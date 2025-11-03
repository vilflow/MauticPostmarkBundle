# MauticPostmarkBundle - Per-Entity Send Feature Deployment Guide

## Summary of Implementation

This document provides deployment instructions for the per-entity send feature that has been implemented for the MauticPostmarkBundle.

## What's Been Implemented

### ✅ Completed Components

#### 1. Database Schema & Migrations
- **Location:** `Migrations/Version20251102000000.php`
- **Tables Created:**
  - `campaign_entity_condition_result` - Stores filter results from condition nodes
  - `campaign_entity_condition_result_item` - Child table for large result sets
  - `postmark_entity_send_log` - Idempotency log for entity-level sends

#### 2. Entity Classes
- **CampaignEntityConditionResult** (`Entity/CampaignEntityConditionResult.php`)
- **CampaignEntityConditionResultRepository** (`Entity/CampaignEntityConditionResultRepository.php`)
- **PostmarkEntitySendLog** (`Entity/PostmarkEntitySendLog.php`)
- **PostmarkEntitySendLogRepository** (`Entity/PostmarkEntitySendLogRepository.php`)

#### 3. Filter Layer (DTOs & Services)
- **EntityFilterSpec** (`DTO/EntityFilterSpec.php`) - Filter specification with JSON serialization
- **OpportunityCriteriaBuilder** (`Service/OpportunityCriteriaBuilder.php`) - Query builder for Opportunities
- **NoteCriteriaBuilder** (`Service/NoteCriteriaBuilder.php`) - Query builder for Notes

#### 4. Campaign Integration
- **CampaignSubscriber** (`EventListener/CampaignSubscriber.php`)
  - Extended with per-entity send logic
  - `sendPerOpportunity()` - Send one email per opportunity
  - `sendPerNote()` - Send one email per note
  - `sendPerContact()` - Original behavior (default)
  - Helper methods for idempotency, token resolution, entity filtering

#### 5. UI Components
- **PostmarkSendType** (`Form/Type/PostmarkSendType.php`) - Added Mode selector (contact/opportunity/note)
- **Translations** (`Translations/en_US/messages.ini`) - Added mode-related translations

#### 6. Service Registration
- **services.php** (`Config/services.php`) - Registered all new services and updated CampaignSubscriber

## Deployment Steps

### Step 1: Backup Database
```bash
# Create a backup before running migrations
mysqldump -u [user] -p mautic > mautic_backup_$(date +%Y%m%d_%H%M%S).sql
```

### Step 2: Run Database Migrations

The migration file creates three new tables. To apply it:

**Option A: Via Doctrine Migrations (if picked up automatically)**
```bash
php bin/console doctrine:migrations:migrate --no-interaction
```

**Option B: Run SQL Directly**
If migrations aren't auto-detected, run the SQL manually:

```bash
# Navigate to the migrations file
cd /var/www/html/mautic_dev/plugins/MauticPostmarkBundle/Migrations

# Review the migration
cat Version20251102000000.php

# Extract and run the SQL (replace {prefix} with your table prefix, usually empty)
# For standard Mautic installation without prefix:
mysql -u [user] -p mautic << 'EOF'
CREATE TABLE IF NOT EXISTS campaign_entity_condition_result (
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
        REFERENCES campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_cecr_campaign_event FOREIGN KEY (campaign_event_id)
        REFERENCES campaign_events(id) ON DELETE CASCADE,
    CONSTRAINT fk_cecr_contact FOREIGN KEY (contact_id)
        REFERENCES leads(id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS campaign_entity_condition_result_item (
    result_id INT NOT NULL,
    entity_id INT NOT NULL,
    PRIMARY KEY(result_id, entity_id),
    CONSTRAINT fk_cecri_result FOREIGN KEY (result_id)
        REFERENCES campaign_entity_condition_result(id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS postmark_entity_send_log (
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
        REFERENCES campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_pesl_campaign_event FOREIGN KEY (campaign_event_id)
        REFERENCES campaign_events(id) ON DELETE CASCADE,
    CONSTRAINT fk_pesl_contact FOREIGN KEY (contact_id)
        REFERENCES leads(id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
EOF
```

### Step 3: Clear Cache
```bash
php bin/console cache:clear
php bin/console cache:warmup
```

### Step 4: Verify Installation
```bash
# Check if tables were created
mysql -u [user] -p mautic -e "SHOW TABLES LIKE '%campaign_entity%'"
mysql -u [user] -p mautic -e "SHOW TABLES LIKE '%postmark_entity%'"

# Describe the tables
mysql -u [user] -p mautic -e "DESCRIBE campaign_entity_condition_result"
mysql -u [user] -p mautic -e "DESCRIBE postmark_entity_send_log"
```

## Usage

### 1. Create a Campaign

1. Go to **Campaigns** → **New Campaign**
2. Add a **Condition** node: (Future: "Has Matching Opportunities" or "Has Matching Notes")
   - Configure filter criteria for Opportunities or Notes
   - Ensure "Store results" is enabled (default)
3. Add an **Action** node: "Send Email via Postmark"
   - **Mode**: Select "Per Opportunity" or "Per Note"
   - Configure email settings (from, to, template, etc.)

### 2. Token Resolution

The per-entity modes support extended token syntax:

**Contact Mode Tokens (existing):**
- `{contactfield=email}` - Contact email
- `{contactfield=firstname}` - Contact first name
- etc.

**Opportunity Mode Tokens (new):**
- `{contactfield=email}` - Contact email
- `{opportunityfield=name}` - Opportunity name
- `{opportunityfield=amount}` - Opportunity amount
- `{opportunityfield=salesStage}` - Sales stage
- etc.

**Note Mode Tokens (new):**
- `{contactfield=email}` - Contact email
- `{notefield=name}` - Note name
- `{notefield=description}` - Note description
- `{notefield=createdAt}` - Note created date
- etc.

### 3. Idempotency

The system automatically prevents duplicate sends:
- Each combination of (campaign_event_id, entity_type, contact_id, entity_id) can only be sent once
- Reruns of the campaign will skip already-sent entities
- Failed sends can be retried after fixing the error

## Pending Implementation

### Campaign Condition Nodes

**Status:** Not yet implemented

Two condition node types need to be created:

#### 1. "Has Matching Opportunities" Condition
- Event type: `postmark.condition.has_opportunities`
- Stores matching Opportunity IDs in `campaign_entity_condition_result` table
- On evaluation:
  - Build `EntityFilterSpec` from node config
  - Find matching Opportunities for contact using `OpportunityCriteriaBuilder`
  - Store results with entity_type='opportunity'
  - Return TRUE if matches > 0

#### 2. "Has Matching Notes" Condition
- Event type: `postmark.condition.has_notes`
- Same as above but for Notes entity
- Uses `NoteCriteriaBuilder`

**Implementation Location:** Create `EventListener/PostmarkConditionSubscriber.php`

**Sample Code Structure:**
```php
<?php

namespace MauticPlugin\MauticPostmarkBundle\EventListener;

use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use Mautic\CampaignBundle\Event\ConditionEvent;
use Mautic\CampaignBundle\CampaignEvents;
use MauticPlugin\MauticPostmarkBundle\Service\OpportunityCriteriaBuilder;
use MauticPlugin\MauticPostmarkBundle\Service\NoteCriteriaBuilder;
use MauticPlugin\MauticPostmarkBundle\DTO\EntityFilterSpec;
use MauticPlugin\MauticPostmarkBundle\Entity\CampaignEntityConditionResult;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class PostmarkConditionSubscriber implements EventSubscriberInterface
{
    private OpportunityCriteriaBuilder $opportunityBuilder;
    private NoteCriteriaBuilder $noteBuilder;
    private EntityManagerInterface $em;

    public function __construct(
        OpportunityCriteriaBuilder $opportunityBuilder,
        NoteCriteriaBuilder $noteBuilder,
        EntityManagerInterface $em
    ) {
        $this->opportunityBuilder = $opportunityBuilder;
        $this->noteBuilder = $noteBuilder;
        $this->em = $em;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CampaignEvents::CAMPAIGN_ON_BUILD => ['onCampaignBuild', 0],
            'postmark.condition.has_opportunities' => ['onHasOpportunities', 0],
            'postmark.condition.has_notes' => ['onHasNotes', 0],
        ];
    }

    public function onCampaignBuild(CampaignBuilderEvent $event): void
    {
        // Register condition nodes
        $event->addCondition(
            'postmark.condition.has_opportunities',
            [
                'label' => 'Has Matching Opportunities',
                'description' => 'Check if contact has opportunities matching criteria',
                'formType' => OpportunityFilterType::class, // TODO: Create this form
                'eventName' => 'postmark.condition.has_opportunities',
            ]
        );

        $event->addCondition(
            'postmark.condition.has_notes',
            [
                'label' => 'Has Matching Notes',
                'description' => 'Check if contact has notes matching criteria',
                'formType' => NoteFilterType::class, // TODO: Create this form
                'eventName' => 'postmark.condition.has_notes',
            ]
        );
    }

    public function onHasOpportunities(ConditionEvent $event): void
    {
        $config = $event->getEvent()->getProperties();
        $contact = $event->getContact();
        $contactId = $contact->getId();

        // Build filter spec from config
        $spec = EntityFilterSpec::fromArray('opportunity', $config['criteria'] ?? []);

        // Find matching opportunities
        $opportunityIds = $this->opportunityBuilder->findMatchingIdsForContact($contactId, $spec);

        if (count($opportunityIds) > 0) {
            // Store results
            $this->storeConditionResult(
                $event->getEvent()->getCampaign()->getId(),
                $event->getEvent()->getId(),
                $contactId,
                'opportunity',
                $spec,
                $opportunityIds
            );

            // Pass condition
            $event->setResult(true);
        } else {
            $event->setResult(false);
        }
    }

    public function onHasNotes(ConditionEvent $event): void
    {
        // Similar to onHasOpportunities but for Notes
        // ...
    }

    private function storeConditionResult(
        int $campaignId,
        int $campaignEventId,
        int $contactId,
        string $entityType,
        EntityFilterSpec $spec,
        array $entityIds
    ): void {
        $result = new CampaignEntityConditionResult();
        $result->setCampaignId($campaignId);
        $result->setCampaignEventId($campaignEventId);
        $result->setContactId($contactId);
        $result->setEntityType($entityType);
        $result->setSpecJson($spec->toJson());

        // Store IDs (up to 1000, otherwise use child table)
        if (count($entityIds) <= 1000) {
            $result->setEntityIds($entityIds);
        } else {
            // TODO: Implement child table storage for large sets
            $result->setEntityIdsJson(null);
        }

        $this->em->persist($result);
        $this->em->flush();
    }
}
```

**Registration in services.php:**
```php
$services->set('mautic.postmark.condition.subscriber')
    ->class(\MauticPlugin\MauticPostmarkBundle\EventListener\PostmarkConditionSubscriber::class)
    ->arg('$opportunityBuilder', service('mautic.postmark.criteria_builder.opportunity'))
    ->arg('$noteBuilder', service('mautic.postmark.criteria_builder.note'))
    ->arg('$em', service('doctrine.orm.entity_manager'))
    ->tag('kernel.event_subscriber');
```

## Testing Checklist

### Unit Tests
- [ ] EntityFilterSpec serialization/deserialization
- [ ] OpportunityCriteriaBuilder query building with various operators
- [ ] NoteCriteriaBuilder query building
- [ ] PostmarkEntitySendLogRepository idempotency checks
- [ ] Token resolution with entity fields

### Integration Tests
- [ ] Migration creates tables correctly
- [ ] Entity persistence and retrieval
- [ ] Filter criteria building
- [ ] Service autowiring and dependency injection

### Functional Tests
1. **Contact Mode (Regression)**
   - [ ] Existing campaigns still work
   - [ ] One email sent per contact

2. **Opportunity Mode**
   - [ ] Campaign with "Has Opportunities" condition stores results
   - [ ] Action node sends one email per matching opportunity
   - [ ] Tokens resolve correctly: `{opportunityfield=amount}`
   - [ ] Idempotency prevents duplicate sends on rerun
   - [ ] Campaign log shows send statistics

3. **Note Mode**
   - [ ] Same tests as Opportunity mode but for Notes

4. **Edge Cases**
   - [ ] Contact with 0 matching entities → action fails gracefully
   - [ ] Contact with >100 matching entities → all sent correctly
   - [ ] Deleted entity after condition evaluation → skipped gracefully
   - [ ] Multiple condition nodes → results combined (union)

## Monitoring & Maintenance

### Database Queries
```sql
-- Check send log statistics
SELECT
    entity_type,
    status,
    COUNT(*) as count,
    MIN(created_at) as first_send,
    MAX(created_at) as last_send
FROM postmark_entity_send_log
GROUP BY entity_type, status;

-- Find failed sends for retry
SELECT * FROM postmark_entity_send_log
WHERE status = 'failed'
AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
ORDER BY created_at DESC;

-- Campaign performance by entity type
SELECT
    c.name as campaign_name,
    p.entity_type,
    p.status,
    COUNT(*) as sends
FROM postmark_entity_send_log p
JOIN campaigns c ON p.campaign_id = c.id
GROUP BY c.id, p.entity_type, p.status;
```

### Cleanup Old Logs
```php
// In a scheduled command or cron job
$sendLogRepo = $em->getRepository(PostmarkEntitySendLog::class);
$deleted = $sendLogRepo->deleteOlderThan(90); // Delete logs older than 90 days

$conditionRepo = $em->getRepository(CampaignEntityConditionResult::class);
$deleted = $conditionRepo->deleteOlderThan(30); // Delete condition results older than 30 days
```

## Troubleshooting

### Issue: "No matching opportunities found in upstream conditions"
**Cause:** Action node is in Opportunity mode but no condition node has stored Opportunity results
**Fix:** Add "Has Matching Opportunities" condition node before the action

### Issue: "EntityManager not available for per-opportunity sends"
**Cause:** EntityManager not injected into CampaignSubscriber
**Fix:** Check services.php registration includes `$em` argument

### Issue: Duplicate sends despite idempotency
**Cause:** Unique index not created or entity_id is NULL when it shouldn't be
**Fix:** Verify unique index exists on `postmark_entity_send_log` table

### Issue: Token `{opportunityfield=amount}` not resolving
**Cause:** Entity doesn't have getter method or field name mismatch
**Fix:** Ensure Opportunity entity has `getAmount()` method and field name matches camelCase

## Performance Considerations

- **Batch Processing:** For contacts with >50 entities, consider implementing batch processing
- **Indexing:** All key lookup fields are indexed (campaign_id, contact_id, entity_type)
- **Pagination:** Large result sets (>1000 entities) use child table to avoid JSON bloat
- **Caching:** Filter specs are normalized and hashed for potential caching (future enhancement)

## Security Notes

- All database operations use parameterized queries (via Doctrine)
- Foreign key constraints ensure referential integrity
- Unique index prevents duplicate sends (idempotency at DB level)
- Entity access is scoped to contact ownership (only sends for entities belonging to the contact)

## Support & Documentation

- **Implementation Guide:** `IMPLEMENTATION_GUIDE.md`
- **Deployment Guide:** This file (`DEPLOYMENT.md`)
- **Mautic Documentation:** https://docs.mautic.org
- **Postmark API Docs:** https://postmarkapp.com/developer

---

**Last Updated:** 2025-11-02
**Version:** 1.0
**Status:** Core implementation complete, condition nodes pending
