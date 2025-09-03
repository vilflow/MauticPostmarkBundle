<?php

namespace MauticPlugin\MauticPostmarkBundle\EventListener;

use Doctrine\DBAL\Connection;
use Mautic\ReportBundle\Event\ReportBuilderEvent;
use Mautic\ReportBundle\Event\ReportGeneratorEvent;
use Mautic\ReportBundle\ReportEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ReportSubscriber implements EventSubscriberInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ReportEvents::REPORT_ON_BUILD    => ['onBuild', 0],
            ReportEvents::REPORT_ON_GENERATE => ['onGenerate', 0],
        ];
    }

    public function onBuild(ReportBuilderEvent $event): void
    {
        $event->addTable('postmark_campaign_logs', [
            'display_name' => 'Postmark Campaign Logs',
            'group'        => 'postmark_campaign_logs',
            'columns'      => [
                't.id'                       => ['label' => 'ID', 'type' => 'int', 'alias' => 'event_log_id'],
                't.lead_id'                  => ['label' => 'Contact ID', 'type' => 'int'],
                't.campaign_id'              => ['label' => 'Campaign ID', 'type' => 'int'],
                // Derived full name from joined leads table
                'l.contact_name'             => [
                    'label'   => 'Contact Name',
                    'type'    => 'string',
                    'alias'   => 'contact_name',
                    'formula' => "CONCAT(CONCAT(l.firstname, ' '), l.lastname)",
                ],
                // Campaign name from joined campaigns table
                'c.name'                     => [
                    'label' => 'Campaign Name',
                    'type'  => 'string',
                    'alias' => 'campaign_name',
                ],
                't.date_triggered'           => ['label' => 'Triggered', 'type' => 'datetime'],

                't.postmark_message_id'      => ['label' => 'PM MessageID', 'type' => 'string'],
                't.postmark_delivery_status' => ['label' => 'PM Status', 'type' => 'string'],
                't.postmark_delivered'       => ['label' => 'PM Delivered', 'type' => 'bool'],
                't.postmark_delivered_at'    => ['label' => 'PM Delivered At', 'type' => 'datetime'],
                't.postmark_opened'          => ['label' => 'PM Opened', 'type' => 'bool'],
                't.postmark_opened_count'    => ['label' => 'PM Opens', 'type' => 'int'],
                't.postmark_last_opened_at'  => ['label' => 'PM Last Open', 'type' => 'datetime'],
                't.postmark_clicked'         => ['label' => 'PM Clicked', 'type' => 'bool'],
                't.postmark_clicked_count'   => ['label' => 'PM Clicks', 'type' => 'int'],
                't.postmark_last_clicked_at' => ['label' => 'PM Last Click', 'type' => 'datetime'],
                't.postmark_bounced'         => ['label' => 'PM Bounced', 'type' => 'bool'],
                't.postmark_bounced_at'      => ['label' => 'PM Bounced At', 'type' => 'datetime'],
                't.postmark_bounce_type'     => ['label' => 'PM Bounce Type', 'type' => 'string'],
                't.postmark_bounce_detail'   => ['label' => 'PM Bounce Detail', 'type' => 'string'],
                't.postmark_spam_complaint'  => ['label' => 'PM Complaint', 'type' => 'bool'],
                't.postmark_spam_complaint_at'=> ['label' => 'PM Complaint At', 'type' => 'datetime'],
                't.postmark_subscription_change' => ['label' => 'PM Subscription Change', 'type' => 'bool'],
                't.postmark_subscription_change_at' => ['label' => 'PM Subscription Change At', 'type' => 'datetime'],
                't.postmark_deferred_count'  => ['label' => 'PM Deferrals', 'type' => 'int'],
                't.postmark_last_deferred_at'=> ['label' => 'PM Last Deferral', 'type' => 'datetime'],
            ],
            'filters'      => [
                't.postmark_delivery_status' => ['label' => 'PM Status', 'type' => 'string'],
                't.date_triggered'           => ['label' => 'Triggered', 'type' => 'datetime'],
                't.campaign_id'              => ['label' => 'Campaign ID', 'type' => 'int'],
                't.lead_id'                  => ['label' => 'Contact ID', 'type' => 'int'],
            ],
        ]);
    }

    public function onGenerate(ReportGeneratorEvent $event): void
    {
        if ('postmark_campaign_logs' !== $event->getContext()) {
            return;
        }

        $qb = $this->connection->createQueryBuilder();
        $selectColumns = array_keys($event->getSelectColumns());
        if (empty($selectColumns)) {
            // Avoid SELECT syntax error if no columns selected yet (e.g., on initial edit)
            $selectColumns = ['t.id'];
        }

        $qb->from(MAUTIC_TABLE_PREFIX.'campaign_lead_event_log', 't')
           ->select($selectColumns)
           ->where("t.channel = 'postmark'");

        $event->addLeadLeftJoin($qb, 't');
        // Join campaigns when campaign name is requested
        if ($event->usesColumn('c.name')) {
            $qb->leftJoin('t', MAUTIC_TABLE_PREFIX.'campaigns', 'c', 'c.id = t.campaign_id');
        }
        $event->setQueryBuilder($qb);
    }
}
