<?php

declare(strict_types=1);

namespace MauticPlugin\MauticPostmarkBundle\Migration;

use Doctrine\DBAL\Schema\Schema;
use Mautic\CoreBundle\Doctrine\PreUpAssertionMigration;

/**
 * Add Postmark tracking columns to campaign_lead_event_log.
 */
class Version20250831122553 extends PreUpAssertionMigration
{
    protected function preUpAssertions(): void
    {
        // Skip if columns already exist (assume presence of postmark_message_id is enough)
        $this->skipAssertion(
            function (Schema $schema) {
                return $schema->getTable("{$this->prefix}campaign_lead_event_log")->hasColumn('postmark_message_id');
            },
            'Postmark columns already present.'
        );
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
ALTER TABLE {$this->prefix}campaign_lead_event_log
ADD postmark_message_id CHAR(36) NULL,
ADD postmark_delivery_status VARCHAR(32) NULL,
ADD postmark_delivered TINYINT(1) NOT NULL DEFAULT 0,
ADD postmark_delivered_at DATETIME NULL,
ADD postmark_opened TINYINT(1) NOT NULL DEFAULT 0,
ADD postmark_opened_count INT NOT NULL DEFAULT 0,
ADD postmark_last_opened_at DATETIME NULL,
ADD postmark_clicked TINYINT(1) NOT NULL DEFAULT 0,
ADD postmark_clicked_count INT NOT NULL DEFAULT 0,
ADD postmark_last_clicked_at DATETIME NULL,
ADD postmark_bounced TINYINT(1) NOT NULL DEFAULT 0,
ADD postmark_bounced_at DATETIME NULL,
ADD postmark_bounce_type VARCHAR(64) NULL,
ADD postmark_bounce_detail VARCHAR(255) NULL,
ADD postmark_spam_complaint TINYINT(1) NOT NULL DEFAULT 0,
ADD postmark_spam_complaint_at DATETIME NULL,
ADD postmark_deferred_count INT NOT NULL DEFAULT 0,
ADD postmark_last_deferred_at DATETIME NULL,
ADD postmark_subscription_change TINYINT(1) NOT NULL DEFAULT 0,
ADD postmark_subscription_change_at DATETIME NULL,
ADD INDEX idx_pm_subscription_change (postmark_subscription_change);
ADD INDEX idx_pm_msg_id (postmark_message_id),
ADD INDEX idx_pm_status (postmark_delivery_status),
ADD INDEX idx_pm_delivered (postmark_delivered),
ADD INDEX idx_pm_opened (postmark_opened),
ADD INDEX idx_pm_clicked (postmark_clicked),
ADD INDEX idx_pm_bounced (postmark_bounced);
SQL
        );
    }
}
