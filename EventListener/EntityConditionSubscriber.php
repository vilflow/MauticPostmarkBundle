<?php

namespace MauticPlugin\MauticPostmarkBundle\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use Mautic\CampaignBundle\Event\ConditionEvent;
use MauticPlugin\MauticPostmarkBundle\DTO\EntityFilterSpec;
use MauticPlugin\MauticPostmarkBundle\Entity\CampaignEntityConditionResult;
use MauticPlugin\MauticPostmarkBundle\Form\Type\OpportunityConditionType;
use MauticPlugin\MauticPostmarkBundle\Form\Type\NoteConditionType;
use MauticPlugin\MauticPostmarkBundle\Service\NoteCriteriaBuilder;
use MauticPlugin\MauticPostmarkBundle\Service\OpportunityCriteriaBuilder;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Campaign condition subscriber for storing entity filter results.
 * Enables "Has Matching Opportunities" and "Has Matching Notes" conditions.
 */
class EntityConditionSubscriber implements EventSubscriberInterface
{
    private OpportunityCriteriaBuilder $opportunityBuilder;
    private NoteCriteriaBuilder $noteBuilder;
    private EntityManagerInterface $em;
    private LoggerInterface $logger;

    public function __construct(
        OpportunityCriteriaBuilder $opportunityBuilder,
        NoteCriteriaBuilder $noteBuilder,
        EntityManagerInterface $em,
        ?LoggerInterface $logger = null
    ) {
        $this->opportunityBuilder = $opportunityBuilder;
        $this->noteBuilder = $noteBuilder;
        $this->em = $em;
        $this->logger = $logger ?? new NullLogger();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CampaignEvents::CAMPAIGN_ON_BUILD => ['onCampaignBuild', 0],
            'postmark.entity_condition.has_opportunities' => ['onHasOpportunities', 0],
            'postmark.entity_condition.has_notes' => ['onHasNotes', 0],
        ];
    }

    /**
     * Register condition nodes in campaign builder
     */
    public function onCampaignBuild(CampaignBuilderEvent $event): void
    {
        $event->addCondition(
            'postmark.entity_condition.has_opportunities',
            [
                'label' => 'mautic.postmark.campaign.condition.has_opportunities',
                'description' => 'mautic.postmark.campaign.condition.has_opportunities_descr',
                'formType' => OpportunityConditionType::class,
                'eventName' => 'postmark.entity_condition.has_opportunities',
                'channel' => 'postmark',
            ]
        );

        $event->addCondition(
            'postmark.entity_condition.has_notes',
            [
                'label' => 'mautic.postmark.campaign.condition.has_notes',
                'description' => 'mautic.postmark.campaign.condition.has_notes_descr',
                'formType' => NoteConditionType::class,
                'eventName' => 'postmark.entity_condition.has_notes',
                'channel' => 'postmark',
            ]
        );
    }

    /**
     * Evaluate "Has Matching Opportunities" condition
     */
    public function onHasOpportunities(ConditionEvent $event): void
    {
        $config = $event->getEvent()->getProperties();
        $contact = $event->getContact();

        if (!$contact) {
            $event->setResult(false);
            return;
        }

        $contactId = $contact->getId();
        $campaignId = $event->getEvent()->getCampaign()->getId();
        $campaignEventId = $event->getEvent()->getId();

        try {
            // Build filter spec from config
            $criteria = $this->parseCriteria($config);
            $spec = EntityFilterSpec::fromArray('opportunity', $criteria);

            // Find matching opportunities
            $opportunityIds = $this->opportunityBuilder->findMatchingIdsForContact($contactId, $spec);

            $this->logger->debug('Has Opportunities condition evaluated', [
                'contact_id' => $contactId,
                'campaign_event_id' => $campaignEventId,
                'matched_count' => count($opportunityIds),
                'opportunity_ids' => $opportunityIds,
            ]);

            if (count($opportunityIds) > 0) {
                // Store results for later use by action nodes
                $this->storeConditionResult(
                    $campaignId,
                    $campaignEventId,
                    $contactId,
                    'opportunity',
                    $spec,
                    $opportunityIds
                );

                $event->setResult(true);

                $this->logger->info('Contact has matching opportunities', [
                    'contact_id' => $contactId,
                    'count' => count($opportunityIds),
                ]);
            } else {
                $event->setResult(false);

                $this->logger->debug('Contact has no matching opportunities', [
                    'contact_id' => $contactId,
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Error evaluating Has Opportunities condition', [
                'contact_id' => $contactId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $event->setResult(false);
        }
    }

    /**
     * Evaluate "Has Matching Notes" condition
     */
    public function onHasNotes(ConditionEvent $event): void
    {
        $config = $event->getEvent()->getProperties();
        $contact = $event->getContact();

        if (!$contact) {
            $event->setResult(false);
            return;
        }

        $contactId = $contact->getId();
        $campaignId = $event->getEvent()->getCampaign()->getId();
        $campaignEventId = $event->getEvent()->getId();

        try {
            // Build filter spec from config
            $criteria = $this->parseCriteria($config);
            $spec = EntityFilterSpec::fromArray('note', $criteria);

            // Find matching notes
            $noteIds = $this->noteBuilder->findMatchingIdsForContact($contactId, $spec);

            $this->logger->debug('Has Notes condition evaluated', [
                'contact_id' => $contactId,
                'campaign_event_id' => $campaignEventId,
                'matched_count' => count($noteIds),
                'note_ids' => $noteIds,
            ]);

            if (count($noteIds) > 0) {
                // Store results for later use by action nodes
                $this->storeConditionResult(
                    $campaignId,
                    $campaignEventId,
                    $contactId,
                    'note',
                    $spec,
                    $noteIds
                );

                $event->setResult(true);

                $this->logger->info('Contact has matching notes', [
                    'contact_id' => $contactId,
                    'count' => count($noteIds),
                ]);
            } else {
                $event->setResult(false);

                $this->logger->debug('Contact has no matching notes', [
                    'contact_id' => $contactId,
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Error evaluating Has Notes condition', [
                'contact_id' => $contactId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $event->setResult(false);
        }
    }

    /**
     * Parse criteria from config
     */
    private function parseCriteria(array $config): array
    {
        $criteria = [];

        // Support multiple filter fields
        if (isset($config['filters']) && is_array($config['filters'])) {
            foreach ($config['filters'] as $filter) {
                if (isset($filter['field'], $filter['operator'], $filter['value'])) {
                    $criteria[$filter['field']] = [
                        'operator' => $filter['operator'],
                        'value' => $filter['value'],
                    ];
                }
            }
        }

        // Support single field (backward compatibility)
        if (isset($config['field'], $config['operator'], $config['value'])) {
            $criteria[$config['field']] = [
                'operator' => $config['operator'],
                'value' => $config['value'],
            ];
        }

        return $criteria;
    }

    /**
     * Store condition result in database
     */
    private function storeConditionResult(
        int $campaignId,
        int $campaignEventId,
        int $contactId,
        string $entityType,
        EntityFilterSpec $spec,
        array $entityIds
    ): void {
        try {
            // Check if result already exists for this contact/event
            $existingResult = $this->em->getRepository(CampaignEntityConditionResult::class)
                ->findOneBy([
                    'campaignEventId' => $campaignEventId,
                    'contactId' => $contactId,
                    'entityType' => $entityType,
                ]);

            if ($existingResult) {
                // Update existing result
                $existingResult->setSpecJson($spec->toJson());
                $existingResult->setCreatedAt(new \DateTime());

                if (count($entityIds) <= 1000) {
                    $existingResult->setEntityIds($entityIds);
                } else {
                    // For very large sets, don't store in JSON (use child table instead)
                    $existingResult->setEntityIdsJson(null);
                    $this->logger->warning('Large entity set detected, consider implementing child table storage', [
                        'count' => count($entityIds),
                    ]);
                }
            } else {
                // Create new result
                $result = new CampaignEntityConditionResult();
                $result->setCampaignId($campaignId);
                $result->setCampaignEventId($campaignEventId);
                $result->setContactId($contactId);
                $result->setEntityType($entityType);
                $result->setSpecJson($spec->toJson());

                if (count($entityIds) <= 1000) {
                    $result->setEntityIds($entityIds);
                } else {
                    // For very large sets, don't store in JSON
                    $result->setEntityIdsJson(null);
                    $this->logger->warning('Large entity set detected, consider implementing child table storage', [
                        'count' => count($entityIds),
                    ]);
                }

                $this->em->persist($result);
            }

            $this->em->flush();

            $this->logger->debug('Condition result stored', [
                'campaign_id' => $campaignId,
                'campaign_event_id' => $campaignEventId,
                'contact_id' => $contactId,
                'entity_type' => $entityType,
                'entity_count' => count($entityIds),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to store condition result', [
                'error' => $e->getMessage(),
                'campaign_event_id' => $campaignEventId,
                'contact_id' => $contactId,
                'entity_type' => $entityType,
            ]);

            // Don't throw - condition can still pass even if storage fails
        }
    }
}
