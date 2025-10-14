<?php

namespace MauticPlugin\MauticPostmarkBundle\EventListener;

use Doctrine\DBAL\Connection;
use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use Mautic\CampaignBundle\Event\CampaignExecutionEvent;
use MauticPlugin\MauticPostmarkBundle\Event\PostmarkEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class PostmarkConditionSubscriber implements EventSubscriberInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CampaignEvents::CAMPAIGN_ON_BUILD => ['onCampaignBuild', 0],
            PostmarkEvents::ON_CAMPAIGN_CONDITION => ['onCampaignCondition', 0],
        ];
    }

    public function onCampaignBuild(CampaignBuilderEvent $event): void
    {
        // Add Postmark conditions
        $event->addCondition(
            'postmark.delivered',
            [
                'label'         => 'mautic.postmark.campaign.condition.delivered',
                'description'   => 'mautic.postmark.campaign.condition.delivered_descr',
                'eventName'     => PostmarkEvents::ON_CAMPAIGN_CONDITION,
            ]
        );

        $event->addCondition(
            'postmark.opened',
            [
                'label'         => 'mautic.postmark.campaign.condition.opened',
                'description'   => 'mautic.postmark.campaign.condition.opened_descr',
                'eventName'     => PostmarkEvents::ON_CAMPAIGN_CONDITION,
            ]
        );

        $event->addCondition(
            'postmark.clicked',
            [
                'label'         => 'mautic.postmark.campaign.condition.clicked',
                'description'   => 'mautic.postmark.campaign.condition.clicked_descr',
                'eventName'     => PostmarkEvents::ON_CAMPAIGN_CONDITION,
            ]
        );

        $event->addCondition(
            'postmark.bounced',
            [
                'label'         => 'mautic.postmark.campaign.condition.bounced',
                'description'   => 'mautic.postmark.campaign.condition.bounced_descr',
                'eventName'     => PostmarkEvents::ON_CAMPAIGN_CONDITION,
            ]
        );

        $event->addCondition(
            'postmark.spam_complaint',
            [
                'label'         => 'mautic.postmark.campaign.condition.spam_complaint',
                'description'   => 'mautic.postmark.campaign.condition.spam_complaint_descr',
                'eventName'     => PostmarkEvents::ON_CAMPAIGN_CONDITION,
            ]
        );

        $event->addCondition(
            'postmark.delivery_status',
            [
                'label'           => 'mautic.postmark.campaign.condition.delivery_status',
                'description'     => 'mautic.postmark.campaign.condition.delivery_status_descr',
                'eventName'       => PostmarkEvents::ON_CAMPAIGN_CONDITION,
                'formType'        => \Mautic\CoreBundle\Form\Type\TextType::class,
                'formTypeOptions' => [
                    'label' => 'mautic.postmark.campaign.condition.delivery_status.value',
                    'attr'  => [
                        'class'   => 'form-control',
                        'tooltip' => 'mautic.postmark.campaign.condition.delivery_status.tooltip',
                    ],
                ],
            ]
        );
    }

    public function onCampaignCondition(CampaignExecutionEvent $event)
    {
        $eventDetails = $event->getEventDetails();
        if (!$eventDetails) {
            return $event->setResult(false);
        }
        
        $eventType = $eventDetails->getType();
        $config = $event->getConfig();
        $lead = $event->getLead();
        
        if (!$lead) {
            return $event->setResult(false);
        }
        
        $leadId = $lead->getId();
        $campaign = $eventDetails->getCampaign();
        if (!$campaign) {
            return $event->setResult(false);
        }
        
        $currentCampaignId = $campaign->getId();
        $currentEventId = $eventDetails->getId();

        // Find the previous Postmark action in the same campaign flow
        $previousPostmarkEvent = $this->findPreviousPostmarkEventInFlow($currentCampaignId, $currentEventId, $leadId);

        if (!$previousPostmarkEvent) {
            return $event->setResult(false);
        }

        $conditionMet = false;

        switch ($eventType) {
            case 'postmark.delivered':
                $conditionMet = (bool) ($previousPostmarkEvent['postmark_delivered'] ?? 0);
                break;

            case 'postmark.opened':
                $conditionMet = (bool) ($previousPostmarkEvent['postmark_opened'] ?? 0);
                break;

            case 'postmark.clicked':
                $conditionMet = (bool) ($previousPostmarkEvent['postmark_clicked'] ?? 0);
                break;

            case 'postmark.bounced':
                $conditionMet = (bool) ($previousPostmarkEvent['postmark_bounced'] ?? 0);
                break;

            case 'postmark.spam_complaint':
                $conditionMet = (bool) ($previousPostmarkEvent['postmark_spam_complaint'] ?? 0);
                break;

            case 'postmark.delivery_status':
                $expectedStatus = $config['delivery_status'] ?? '';
                $actualStatus = $previousPostmarkEvent['postmark_delivery_status'] ?? '';
                $conditionMet = ($actualStatus === $expectedStatus);
                break;
        }
        
        return $event->setResult($conditionMet);
    }

    /**
     * Find the previous Postmark action event in the same campaign flow for this contact
     */
    private function findPreviousPostmarkEventInFlow(int $campaignId, int $currentEventId, int $leadId): ?array
    {
        // Try to find ANY postmark event logs for this campaign and lead first
        $allPostmarkLogs = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(MAUTIC_TABLE_PREFIX.'campaign_lead_event_log', 'log')
            ->where('log.lead_id = :leadId')
            ->andWhere('log.campaign_id = :campaignId')
            ->andWhere('log.channel = :channel')
            ->setParameter('leadId', $leadId)
            ->setParameter('campaignId', $campaignId)
            ->setParameter('channel', 'postmark')
            ->orderBy('log.date_triggered', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();
            
        if (!empty($allPostmarkLogs)) {
            // Return the most recent postmark event log
            return $allPostmarkLogs[0];
        }

        return null;
    }
}