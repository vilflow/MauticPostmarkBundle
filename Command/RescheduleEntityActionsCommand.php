<?php

namespace MauticPlugin\MauticPostmarkBundle\Command;

use Doctrine\DBAL\Connection;
use Mautic\CampaignBundle\Entity\Event as CampaignEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reschedules entity-mode (opportunity/note/event) Postmark campaign actions to check for new entities.
 *
 * This command should be run periodically (e.g., every 5-15 minutes) to ensure
 * newly created entities trigger campaign emails even though Doctrine lifecycle
 * events don't fire reliably from the Mautic UI.
 */
class RescheduleEntityActionsCommand extends Command
{
    protected static $defaultName = 'mautic:postmark:reschedule-entities';

    private const SUPPORTED_MODES = ['opportunity', 'note', 'event'];

    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Reschedules entity-mode Postmark actions (opportunity/note/event) to process new entities')
            ->setHelp(
                'This command finds all Postmark campaign actions configured in "opportunity", "note", or "event" mode '.
                'and reschedules them so new entities can trigger emails.'
            )
            ->addOption(
                'campaign-id',
                'i',
                InputOption::VALUE_OPTIONAL,
                'Only reschedule actions for a specific campaign ID'
            )
            ->addOption(
                'mode',
                'm',
                InputOption::VALUE_OPTIONAL,
                'Only reschedule actions for a specific mode (opportunity, note, or event)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $campaignId = $input->getOption('campaign-id');
        $modeFilter = $input->getOption('mode');

        // Validate mode filter if provided
        if ($modeFilter && !in_array($modeFilter, self::SUPPORTED_MODES, true)) {
            $output->writeln(sprintf(
                '<error>Invalid mode "%s". Supported modes: %s</error>',
                $modeFilter,
                implode(', ', self::SUPPORTED_MODES)
            ));
            return Command::FAILURE;
        }

        $output->writeln('<info>Finding entity-mode Postmark actions...</info>');

        // Find all Postmark actions
        $qb = $this->connection->createQueryBuilder();
        $qb->select('ce.id', 'ce.campaign_id', 'ce.name', 'ce.properties')
            ->from(MAUTIC_TABLE_PREFIX.'campaign_events', 'ce')
            ->where('ce.type = :type')
            ->andWhere('ce.event_type = :eventType')
            ->setParameter('type', 'postmark.send')
            ->setParameter('eventType', CampaignEvent::TYPE_ACTION);

        if ($campaignId) {
            $qb->andWhere('ce.campaign_id = :campaignId')
                ->setParameter('campaignId', $campaignId);
        }

        $events = $qb->executeQuery()->fetchAllAssociative();

        if (empty($events)) {
            $output->writeln('<comment>No Postmark actions found</comment>');
            return Command::SUCCESS;
        }

        $rescheduledCount = 0;
        $processedActions = [];
        $now = new \DateTime();

        foreach ($events as $event) {
            $properties = $event['properties'];
            if (!$properties) {
                continue;
            }

            $propertiesArray = unserialize($properties);
            if (!is_array($propertiesArray)) {
                continue;
            }

            // Check for mode in both the properties array and nested properties
            $mode = null;
            if (isset($propertiesArray['mode'])) {
                $mode = $propertiesArray['mode'];
            } elseif (isset($propertiesArray['properties']['mode'])) {
                $mode = $propertiesArray['properties']['mode'];
            }

            // Skip if mode is not one of the entity modes we support
            if (!in_array($mode, self::SUPPORTED_MODES, true)) {
                continue;
            }

            // Skip if mode filter is set and doesn't match
            if ($modeFilter && $mode !== $modeFilter) {
                continue;
            }

            $modeLabel = ucfirst($mode);
            $output->writeln(sprintf(
                'Processing: <comment>%s</comment> [%s mode] (Campaign %d, Event %d)',
                $event['name'] ?: 'Postmark Action',
                $modeLabel,
                $event['campaign_id'],
                $event['id']
            ));

            // Find all logs for this event that have already been triggered but aren't scheduled
            $updated = $this->connection->executeStatement(
                'UPDATE '.MAUTIC_TABLE_PREFIX.'campaign_lead_event_log
                SET is_scheduled = 1, trigger_date = :triggerDate
                WHERE event_id = :eventId
                AND date_triggered IS NOT NULL
                AND is_scheduled = 0',
                [
                    'eventId' => $event['id'],
                    'triggerDate' => $now->format('Y-m-d H:i:s'),
                ]
            );

            if ($updated > 0) {
                $output->writeln(sprintf('  Rescheduled <info>%d</info> contact(s)', $updated));
                $rescheduledCount += $updated;

                $this->logger->info('Rescheduled entity-mode Postmark action', [
                    'mode' => $mode,
                    'event_id' => $event['id'],
                    'campaign_id' => $event['campaign_id'],
                    'contacts_rescheduled' => $updated,
                ]);
            }

            $processedActions[$mode] = ($processedActions[$mode] ?? 0) + 1;
        }

        $output->writeln('');

        // Show summary by mode
        if (!empty($processedActions)) {
            $output->writeln('<info>Summary by mode:</info>');
            foreach ($processedActions as $mode => $count) {
                $output->writeln(sprintf('  - %s: %d action(s)', ucfirst($mode), $count));
            }
            $output->writeln('');
        }

        $output->writeln(sprintf(
            '<info>Done! Rescheduled %d contact(s) across %d entity-mode action(s)</info>',
            $rescheduledCount,
            array_sum($processedActions)
        ));

        return Command::SUCCESS;
    }
}
