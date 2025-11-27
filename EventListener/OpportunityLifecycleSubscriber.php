<?php

namespace MauticPlugin\MauticPostmarkBundle\EventListener;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Mautic\CampaignBundle\Entity\Event as CampaignEvent;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\CampaignBundle\Entity\LeadEventLogRepository;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\MauticOpportunitiesBundle\Entity\Opportunity;
use MauticPlugin\MauticPostmarkBundle\Entity\PostmarkEntitySendLog;
use MauticPlugin\MauticPostmarkBundle\Entity\PostmarkEntitySendLogRepository;
use Psr\Log\LoggerInterface;

/**
 * When an Opportunity is created or updated so that it now matches the campaign
 * conditions tied to a Postmark "per opportunity" action, this subscriber
 * queues a new campaign event rotation so the Postmark action will be executed again.
 */
class OpportunityLifecycleSubscriber implements EventSubscriber
{
    /**
     * @var array<string,array{event:CampaignEvent,contact:Lead,rotation:int,opportunityIds:int[]}>
     */
    private array $scheduleQueue = [];

    /**
     * @var array<int,array<int,LeadEventLog>>
     */
    private array $latestLogCache = [];

    public function __construct(
        private CampaignSubscriber $campaignSubscriber,
        private LoggerInterface $logger
    ) {
    }

    public function getSubscribedEvents(): array
    {
        return [
            Events::postPersist,
            Events::postUpdate,
            Events::postFlush,
        ];
    }

    public function postPersist(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Opportunity) {
            return;
        }

        $objectManager = $args->getObjectManager();
        if (!$objectManager instanceof EntityManagerInterface) {
            return;
        }

        $this->logger->info('[OpportunityLifecycle] postPersist triggered', [
            'opportunity_id' => $entity->getId(),
            'opportunity_name' => $entity->getName(),
            'contact_id' => $entity->getContact() ? $entity->getContact()->getId() : null,
        ]);

        $this->evaluateOpportunity($entity, $objectManager);
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Opportunity) {
            return;
        }

        $objectManager = $args->getObjectManager();
        if (!$objectManager instanceof EntityManagerInterface) {
            return;
        }

        $this->evaluateOpportunity($entity, $objectManager);
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $this->logger->info('[OpportunityLifecycle] postFlush called', [
            'queue_count' => count($this->scheduleQueue),
        ]);

        if (empty($this->scheduleQueue)) {
            return;
        }

        $entityManager = $args->getEntityManager();
        $queue         = $this->scheduleQueue;
        $this->scheduleQueue = [];
        $this->latestLogCache = [];
        $now = new \DateTimeImmutable();

        $this->logger->info('[OpportunityLifecycle] Processing schedule queue', [
            'queue_count' => count($queue),
        ]);

        foreach ($queue as $data) {
            try {
                $log = new LeadEventLog();
                $log->setLead($data['contact']);
                $log->setEvent($data['event']);
                $log->setIsScheduled(true);
                $log->setSystemTriggered(true);
                $log->setTriggerDate($now);
                $log->setRotation($data['rotation']);

                if (!empty($data['opportunityIds'])) {
                    $log->setMetadata([
                        'postmark' => [
                            'queued'            => true,
                            'opportunity_ids'   => array_values(array_unique($data['opportunityIds'])),
                        ],
                    ]);
                }

                $entityManager->persist($log);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to queue Postmark opportunity send', [
                    'eventId'   => $data['event']->getId(),
                    'contactId' => $data['contact']->getId(),
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        // Flush newly created campaign logs (postFlush will be invoked again but the queue is now empty).
        $entityManager->flush();
    }

    private function evaluateOpportunity(Opportunity $opportunity, EntityManagerInterface $entityManager): void
    {
        $contact = $opportunity->getContact();
        if (!$contact instanceof Lead || null === $contact->getId()) {
            $this->logger->info('[OpportunityLifecycle] No valid contact found for opportunity', [
                'opportunity_id' => $opportunity->getId(),
            ]);
            return;
        }

        $contactId  = $contact->getId();
        $latestLogs = $this->getLatestLogsForContact($contact, $entityManager);

        $this->logger->info('[OpportunityLifecycle] Evaluating opportunity', [
            'opportunity_id' => $opportunity->getId(),
            'contact_id' => $contactId,
            'latest_logs_count' => count($latestLogs),
        ]);

        foreach ($latestLogs as $log) {
            $event = $log->getEvent();
            if (!$event instanceof CampaignEvent) {
                continue;
            }

            $properties = $event->getProperties();
            $mode       = $properties['mode'] ?? 'contact';

            if ('opportunity' !== $mode) {
                continue;
            }

            if ($log->getIsScheduled()) {
                // There is already a pending rotation scheduled, let it run.
                continue;
            }

            // Determine if this is the initial rotation or a follow-up
            $hasExecutedInitialRotation = (null !== $log->getDateTriggered());

            // Calculate next rotation
            $currentRotation = $log->getRotation() ?? 0;
            $nextRotation = $hasExecutedInitialRotation ? ($currentRotation + 1) : $currentRotation;

            try {
                $matchingIds = $this->campaignSubscriber->getFilteredOpportunityIdsForContact($contactId, $event);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to evaluate Postmark opportunity filters', [
                    'eventId'   => $event->getId(),
                    'contactId' => $contactId,
                    'error'     => $e->getMessage(),
                ]);

                continue;
            }

            if (!in_array($opportunity->getId(), $matchingIds, true)) {
                continue;
            }

            /** @var PostmarkEntitySendLogRepository $sendLogRepository */
            $sendLogRepository = $entityManager->getRepository(PostmarkEntitySendLog::class);

            if ($sendLogRepository->alreadySent(
                $event->getId(),
                'opportunity',
                $contactId,
                $opportunity->getId()
            )) {
                continue;
            }

            $key = $event->getId().':'.$contactId;

            if (isset($this->scheduleQueue[$key])) {
                if (!in_array($opportunity->getId(), $this->scheduleQueue[$key]['opportunityIds'], true)) {
                    $this->scheduleQueue[$key]['opportunityIds'][] = $opportunity->getId();
                }

                continue;
            }

            $this->logger->info('Queueing Postmark opportunity send', [
                'eventId'          => $event->getId(),
                'contactId'        => $contactId,
                'opportunityId'    => $opportunity->getId(),
                'rotation'         => $nextRotation,
                'initialExecution' => !$hasExecutedInitialRotation,
            ]);

            $this->scheduleQueue[$key] = [
                'event'          => $event,
                'contact'        => $contact,
                'rotation'       => $nextRotation,
                'opportunityIds' => [$opportunity->getId()],
            ];
        }
    }

    /**
     * Retrieve the most recent LeadEventLog per Postmark action event for the contact.
     *
     * @return LeadEventLog[]
     */
    private function getLatestLogsForContact(Lead $contact, EntityManagerInterface $entityManager): array
    {
        $contactId = $contact->getId();
        if (isset($this->latestLogCache[$contactId])) {
            return $this->latestLogCache[$contactId];
        }

        /** @var LeadEventLogRepository $leadEventLogRepository */
        $leadEventLogRepository = $entityManager->getRepository(LeadEventLog::class);

        $qb = $leadEventLogRepository->createQueryBuilder('log')
            ->join('log.event', 'event')
            ->where('log.lead = :lead')
            ->andWhere('event.type = :type')
            ->andWhere('event.eventType = :eventType')
            ->setParameter('lead', $contact)
            ->setParameter('type', CampaignEvent::TYPE_ACTION)
            ->setParameter('eventType', 'postmark.send');

        $logs = $qb->getQuery()->getResult();

        $latestByEvent = [];

        foreach ($logs as $log) {
            if (!$log instanceof LeadEventLog) {
                continue;
            }

            $eventId = $log->getEvent()->getId();

            if (!isset($latestByEvent[$eventId]) || $log->getRotation() > $latestByEvent[$eventId]->getRotation()) {
                $latestByEvent[$eventId] = $log;
            }
        }

        $this->latestLogCache[$contactId] = array_values($latestByEvent);

        return $this->latestLogCache[$contactId];
    }
}
