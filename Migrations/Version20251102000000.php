<?php

declare(strict_types=1);

namespace MauticPlugin\MauticPostmarkBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * Add tables for per-entity Postmark sends (Opportunity, Note modes):
 * - campaign_entity_condition_result: stores filter results from condition nodes
 * - campaign_entity_condition_result_item: stores individual entity IDs for large sets
 * - postmark_entity_send_log: idempotency log for entity-level sends
 */
class Version20251102000000 extends AbstractMigration
{
    protected function isApplicable(Schema $schema): bool
    {
        // Run if the main table doesn't exist yet
        return !$schema->hasTable($this->concatPrefix('postmark_entity_send_log'));
    }

    protected function up(): void
    {
        $prefix = $this->tablePrefix;

        // 1. campaign_entity_condition_result - stores filter results from condition nodes
        $this->addSql(<<<SQL
CREATE TABLE IF NOT EXISTS {$prefix}campaign_entity_condition_result (
    id INT AUTO_INCREMENT NOT NULL,
    campaign_id INT NOT NULL,
    campaign_event_id INT NOT NULL COMMENT 'The condition node ID',
    contact_id INT NOT NULL,
    entity_type VARCHAR(32) NOT NULL COMMENT 'opportunity or note',
    spec_json TEXT NOT NULL COMMENT 'Normalized filter spec used by condition',
    entity_ids_json LONGTEXT NULL COMMENT 'Compact JSON array of matched IDs, or null if large',
    created_at DATETIME NOT NULL,
    PRIMARY KEY(id),
    INDEX idx_campaign_event_contact (campaign_event_id, contact_id),
    INDEX idx_campaign_contact (campaign_id, contact_id),
    INDEX idx_entity_type (entity_type),
    CONSTRAINT fk_cecr_campaign FOREIGN KEY (campaign_id)
        REFERENCES {$prefix}campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_cecr_campaign_event FOREIGN KEY (campaign_event_id)
        REFERENCES {$prefix}campaign_events(id) ON DELETE CASCADE,
    CONSTRAINT fk_cecr_contact FOREIGN KEY (contact_id)
        REFERENCES {$prefix}leads(id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
SQL
        );

        // 2. campaign_entity_condition_result_item - child table for large result sets
        $this->addSql(<<<SQL
CREATE TABLE IF NOT EXISTS {$prefix}campaign_entity_condition_result_item (
    result_id INT NOT NULL,
    entity_id INT NOT NULL,
    PRIMARY KEY(result_id, entity_id),
    CONSTRAINT fk_cecri_result FOREIGN KEY (result_id)
        REFERENCES {$prefix}campaign_entity_condition_result(id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
SQL
        );

        // 3. postmark_entity_send_log - idempotency log for entity-level sends
        $this->addSql(<<<SQL
CREATE TABLE IF NOT EXISTS {$prefix}postmark_entity_send_log (
    id INT AUTO_INCREMENT NOT NULL,
    campaign_event_id INT NOT NULL COMMENT 'Action node ID',
    campaign_id INT NOT NULL,
    contact_id INT NOT NULL,
    entity_type VARCHAR(32) NOT NULL COMMENT 'contact, opportunity, or note',
    entity_id INT NULL COMMENT 'NULL for contact mode',
    postmark_message_id VARCHAR(64) NULL,
    status VARCHAR(32) NOT NULL COMMENT 'queued, sent, failed',
    error TEXT NULL,
    sent_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY(id),
    UNIQUE INDEX idx_unique_send (campaign_event_id, entity_type, contact_id, entity_id),
    INDEX idx_campaign (campaign_id),
    INDEX idx_contact (contact_id),
    INDEX idx_status (status),
    INDEX idx_entity_type (entity_type),
    INDEX idx_postmark_message_id (postmark_message_id),
    CONSTRAINT fk_pesl_campaign FOREIGN KEY (campaign_id)
        REFERENCES {$prefix}campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_pesl_campaign_event FOREIGN KEY (campaign_event_id)
        REFERENCES {$prefix}campaign_events(id) ON DELETE CASCADE,
    CONSTRAINT fk_pesl_contact FOREIGN KEY (contact_id)
        REFERENCES {$prefix}leads(id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
SQL
        );
    }
}
