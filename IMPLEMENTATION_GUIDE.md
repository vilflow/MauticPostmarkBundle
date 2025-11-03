# MauticPostmarkBundle Per-Entity Send Implementation

## Overview

This document describes the implementation of per-entity send capabilities for the MauticPostmarkBundle, enabling campaigns to send emails per Contact, per Opportunity, or per Note with filter inheritance and idempotency.

## Architecture

### 1. Database Schema

Three new tables have been added via migration `Version20251102000000`:

#### campaign_entity_condition_result
Stores filter results from condition nodes.
- `id` (PK)
- `campaign_id` (FK to campaigns)
- `campaign_event_id` (FK to campaign_events) - The condition node ID
- `contact_id` (FK to leads)
- `entity_type` (VARCHAR: 'opportunity' or 'note')
- `spec_json` (TEXT) - Normalized filter spec
- `entity_ids_json` (LONGTEXT) - Matched entity IDs (JSON array) or NULL for large sets
- `created_at` (DATETIME)

**Indexes:**
- `idx_campaign_event_contact` (campaign_event_id, contact_id)
- `idx_campaign_contact` (campaign_id, contact_id)
- `idx_entity_type` (entity_type)

#### campaign_entity_condition_result_item
Child table for large result sets (optional expansion).
- `result_id` (FK to campaign_entity_condition_result)
- `entity_id` (INT)
- Primary key: (result_id, entity_id)

#### postmark_entity_send_log
Idempotency log for entity-level sends.
- `id` (PK)
- `campaign_event_id` (FK to campaign_events) - Action node ID
- `campaign_id` (FK to campaigns)
- `contact_id` (FK to leads)
- `entity_type` (VARCHAR: 'contact', 'opportunity', or 'note')
- `entity_id` (INT, NULL for contact mode)
- `postmark_message_id` (VARCHAR 64, NULL)
- `status` (VARCHAR 32: 'queued', 'sent', 'failed')
- `error` (TEXT, NULL)
- `sent_at` (DATETIME, NULL)
- `created_at` (DATETIME)

**Indexes:**
- UNIQUE `idx_unique_send` (campaign_event_id, entity_type, contact_id, entity_id)
- `idx_campaign` (campaign_id)
- `idx_contact` (contact_id)
- `idx_status` (status)
- `idx_entity_type` (entity_type)
- `idx_postmark_message_id` (postmark_message_id)

### 2. Shared Filter Layer

#### DTO: EntityFilterSpec
**Location:** `DTO/EntityFilterSpec.php`

Encapsulates entity filter specifications:
- `type`: Entity type ('opportunity' or 'note')
- `criteria`: Associative array of filter criteria

**Methods:**
- `toJson()`: Serialize for storage
- `fromJson(string $json)`: Deserialize from JSON
- `fromArray(string $type, array $criteria)`: Create from config
- `normalize()`: Normalize criteria for consistent comparison
- `hash()`: Generate MD5 hash for caching/comparison

#### Service: OpportunityCriteriaBuilder
**Location:** `Service/OpportunityCriteriaBuilder.php`

Builds Doctrine QueryBuilder instances for Opportunity filtering.

**Key Methods:**
- `fromSpec(EntityFilterSpec $spec): QueryBuilder` - Build query from spec
- `findMatchingIdsForContact(int $contactId, EntityFilterSpec $spec, ?int $limit): int[]`
- `countMatchingForContact(int $contactId, EntityFilterSpec $spec): int`

**Supported Operators:**
- Comparison: `=`, `!=`, `eq`, `neq`, `gt`, `gte`, `lt`, `lte`
- String: `like`, `!like`, `contains`, `startsWith`, `endsWith`
- Array: `in`, `!in`
- Null: `empty`, `!empty`
- Date: `date` (with relative intervals support: `-P30D`, `+P1M`, `today`, `yesterday`, etc.)
- Regex: `regexp`, `!regexp`

**Features:**
- Automatic label-to-key conversion for select fields (e.g., sales_stage, payment_status)
- Relative date handling with intervals
- Anniversary matching (month-day only)
- DateTime vs Date field detection

#### Service: NoteCriteriaBuilder
**Location:** `Service/NoteCriteriaBuilder.php` *(To be created - similar structure)*

Same as OpportunityCriteriaBuilder but for Notes entity.

### 3. UI Changes

#### Form: PostmarkSendType
**Location:** `Form/Type/PostmarkSendType.php`

**New Field:** `mode` (ChoiceType, expanded as radio buttons)
- Default: `contact`
- Options:
  - `contact` - Send one email per contact (existing behavior)
  - `opportunity` - Send one email per matched opportunity
  - `note` - Send one email per matched note

**Translations Added:**
```ini
mautic.postmark.form.mode="Send Mode"
mautic.postmark.form.mode.tooltip="Choose whether to send one email per contact, per opportunity, or per note..."
mautic.postmark.form.mode.contact="Per Contact (Default)"
mautic.postmark.form.mode.opportunity="Per Opportunity"
mautic.postmark.form.mode.note="Per Note"
```

### 4. Campaign Integration

## Next Steps for Implementation

### A. Create NoteCriteriaBuilder
Create `Service/NoteCriteriaBuilder.php` mirroring the OpportunityCriteriaBuilder pattern but for Note entities.

### B. Campaign Condition Nodes

Create two condition node types that store filter results:

#### 1. Has Matching Opportunities Condition
**Event Type:** `postmark.condition.has_opportunities`

**Configuration:**
- Filter fields for Opportunity entity (sales_stage, payment_status, amount, dates, etc.)
- "Store results" flag (default: true)

**On Evaluation:**
```php
1. Build EntityFilterSpec from node config
2. Use OpportunityCriteriaBuilder to find matching Opportunity IDs for contact
3. If matches > 0:
   - Insert into campaign_entity_condition_result:
     * campaign_id, campaign_event_id (condition node ID), contact_id
     * entity_type='opportunity'
     * spec_json (serialized EntityFilterSpec)
     * entity_ids_json (JSON array of IDs or NULL if > 1000)
   - If IDs > 1000, insert into campaign_entity_condition_result_item
   - Return TRUE (pass contact to next node)
4. Else:
   - Return FALSE (fail branch)
```

#### 2. Has Matching Notes Condition
**Event Type:** `postmark.condition.has_notes`

Same as above but for Notes entity.

### C. Extend Postmark Action (CampaignSubscriber)

**File:** `EventListener/CampaignSubscriber.php`

#### Current Flow (Contact Mode)
```php
onCampaignTriggerPostmark(PendingEvent $event) {
    foreach ($contacts as $contact) {
        // Send one email to contact
    }
}
```

#### Extended Flow

```php
onCampaignTriggerPostmark(PendingEvent $event) {
    $config = $event->getEvent()->getProperties();
    $mode = $config['mode'] ?? 'contact';

    switch ($mode) {
        case 'contact':
            $this->sendPerContact($event, $config);
            break;
        case 'opportunity':
            $this->sendPerOpportunity($event, $config);
            break;
        case 'note':
            $this->sendPerNote($event, $config);
            break;
    }
}
```

#### sendPerOpportunity() Logic

```php
private function sendPerOpportunity(PendingEvent $event, array $config): void
{
    $contacts = $event->getContacts();
    $actionEventId = $event->getEvent()->getId();
    $campaignId = $event->getEvent()->getCampaign()->getId();

    foreach ($contacts as $logId => $contact) {
        $log = $event->getPending()->get($logId);
        $contactId = $contact->getId();

        // 1. Find upstream condition node results for this contact
        $conditionResults = $this->findConditionResultsForContact(
            $campaignId,
            $contactId,
            'opportunity'
        );

        if (empty($conditionResults)) {
            $event->fail($log, 'No matching opportunities found in upstream conditions');
            continue;
        }

        // 2. Collect all unique opportunity IDs from condition results
        $opportunityIds = $this->extractEntityIds($conditionResults);

        // 3. For each opportunity, check idempotency and send
        foreach ($opportunityIds as $opportunityId) {
            // Check if already sent
            if ($this->alreadySent($actionEventId, 'opportunity', $contactId, $opportunityId)) {
                continue; // Skip duplicate sends
            }

            // Load opportunity entity
            $opportunity = $this->em->getRepository(Opportunity::class)->find($opportunityId);
            if (!$opportunity) {
                continue;
            }

            // Resolve tokens (can now include opportunity fields)
            [$from, $to, $model] = $this->resolveTokens(
                $config['from_email'],
                $config['to_email'],
                $config['template_model'],
                $contact->getProfileFields(),
                $opportunity // Pass opportunity for additional token resolution
            );

            // Send email
            [$ok, $statusCode, $respBody, $err] = $this->sendPostmark(
                $config['server_token'],
                [
                    'From' => $from,
                    'To' => $to,
                    'TemplateAlias' => $config['template_alias'],
                    'TemplateModel' => $model,
                ]
            );

            // Log to postmark_entity_send_log
            $this->logEntitySend(
                $actionEventId,
                $campaignId,
                $contactId,
                'opportunity',
                $opportunityId,
                $ok ? 'sent' : 'failed',
                $ok ? $this->extractMessageId($respBody) : null,
                $ok ? null : $err
            );

            if (!$ok) {
                // Log failure but continue with other opportunities
                continue;
            }
        }

        // Mark log as passed (even if some opportunities failed)
        $event->pass($log);
    }
}
```

#### Helper Methods to Add

```php
private function findConditionResultsForContact(int $campaignId, int $contactId, string $entityType): array
{
    // Query campaign_entity_condition_result
    // Return array of results with entity_ids
}

private function extractEntityIds(array $conditionResults): array
{
    // Decode entity_ids_json from each result
    // If entity_ids_json is NULL, query campaign_entity_condition_result_item
    // Return unique array of entity IDs
}

private function alreadySent(int $actionEventId, string $entityType, int $contactId, int $entityId): bool
{
    // Check postmark_entity_send_log for existing record
    // Return true if status='sent' or recent 'failed' (within grace period)
}

private function logEntitySend(
    int $actionEventId,
    int $campaignId,
    int $contactId,
    string $entityType,
    ?int $entityId,
    string $status,
    ?string $messageId,
    ?string $error
): void
{
    // INSERT into postmark_entity_send_log
}

private function resolveTokens(
    string $from,
    string $to,
    array $templateModel,
    array $contactFields,
    $entity = null // Opportunity or Note or null
): array
{
    // Extended token resolution
    // Support {contactfield=email}, {opportunityfield=amount}, {notefield=description}
}
```

#### sendPerNote() Logic
Same pattern as sendPerOpportunity but for Notes.

### D. Service Registration

**File:** `Config/services.php`

```php
<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();

    // Existing services...

    // Criteria builders
    $services->set('mautic.postmark.criteria_builder.opportunity', OpportunityCriteriaBuilder::class)
        ->args([service('doctrine.orm.entity_manager')]);

    $services->set('mautic.postmark.criteria_builder.note', NoteCriteriaBuilder::class)
        ->args([service('doctrine.orm.entity_manager')]);
};
```

### E. Handling Newly Created Entities

#### Problem
Entities created AFTER a campaign condition node evaluation won't be included in subsequent action node executions.

#### Solution 1: Time-based Re-evaluation
Add a "stale duration" config to condition nodes:
- If condition result is older than X hours/days, re-evaluate
- Update campaign_entity_condition_result.created_at
- Refresh entity_ids

#### Solution 2: Event-driven Updates
Subscribe to Opportunity/Note creation events:
- When new entity matches an active condition spec, insert into result table
- Trigger campaign event recalculation for affected contacts

#### Recommended: Hybrid Approach
- Condition nodes store `last_evaluated_at`
- Action nodes check age of condition results
- If stale (> 1 hour by default), re-run condition criteria before sending
- Update results table with new entities

### F. Testing Checklist

#### Unit Tests
- [ ] EntityFilterSpec serialization/deserialization
- [ ] OpportunityCriteriaBuilder query building
- [ ] NoteCriteriaBuilder query building
- [ ] Token resolution with entity fields
- [ ] Idempotency checks

#### Integration Tests
- [ ] Migration runs successfully on fresh database
- [ ] Migration skips on existing tables
- [ ] Condition nodes store results correctly
- [ ] Action nodes inherit filter results
- [ ] Idempotency prevents duplicate sends
- [ ] Newly created entities are handled
- [ ] Multiple condition nodes combine correctly (AND/OR logic)

#### Functional Tests
1. **Contact Mode (Regression Test)**
   - Existing campaigns still work as before
   - One email per contact

2. **Opportunity Mode**
   - Campaign: Condition "Has Opportunities with sales_stage=Submitted" → Action "Send per Opportunity"
   - Contact A has 3 opportunities (2 submitted, 1 closed)
   - Expected: 2 emails sent (one per submitted opportunity)
   - Re-run campaign: 0 new emails (idempotency)

3. **Note Mode**
   - Campaign: Condition "Has Notes with newsletterFormC=true" → Action "Send per Note"
   - Contact B has 5 notes (3 with flag, 2 without)
   - Expected: 3 emails sent

4. **Filter Inheritance**
   - Multiple condition nodes with different filters
   - Action should receive union/intersection based on campaign graph

5. **Token Resolution**
   - {contactfield=email} resolves to contact email
   - {opportunityfield=amount} resolves to opportunity amount
   - {notefield=description} resolves to note description

## Current Implementation Status

### ✅ Completed
1. Database migrations created (3 tables)
2. DTO EntityFilterSpec implemented
3. OpportunityCriteriaBuilder implemented with full operator support
4. Form field `mode` added with translations
5. Architecture and data flow designed

### 🚧 In Progress
1. Extending CampaignSubscriber for per-entity logic

### ⏳ Pending
1. Create NoteCriteriaBuilder
2. Implement campaign condition node subscribers
3. Add helper methods to CampaignSubscriber
4. Implement token resolution extension
5. Add service registrations
6. Create tests
7. Handle newly created entities (re-evaluation strategy)

## File Structure

```
plugins/MauticPostmarkBundle/
├── DTO/
│   └── EntityFilterSpec.php          ✅ Created
├── Entity/
│   ├── CampaignEntityConditionResult.php      ⏳ To create
│   ├── CampaignEntityConditionResultItem.php  ⏳ To create
│   └── PostmarkEntitySendLog.php             ⏳ To create
├── EventListener/
│   ├── CampaignSubscriber.php        🚧 To extend
│   └── PostmarkConditionSubscriber.php ⏳ To extend
├── Form/
│   └── Type/
│       └── PostmarkSendType.php      ✅ Updated
├── Migration/
│   ├── Version20250831122553.php     ✅ Existing
│   └── Version20251102000000.php     ✅ Created
├── Service/
│   ├── OpportunityCriteriaBuilder.php ✅ Created
│   ├── NoteCriteriaBuilder.php        ⏳ To create
│   └── PostmarkApiService.php         ✅ Existing
├── Translations/
│   └── en_US/
│       └── messages.ini               ✅ Updated
└── Config/
    ├── config.php                     ✅ Existing
    └── services.php                   ⏳ To update
```

## Notes & Considerations

### Performance
- For contacts with >1000 matched entities, use `campaign_entity_condition_result_item` child table
- Consider pagination for sends (batch processing)
- Index on postmark_entity_send_log.status for failed send retries

### Edge Cases
- What if contact is deleted but has pending entity sends?
  - FK constraints cascade delete (GOOD)
- What if opportunity/note is deleted after condition evaluation?
  - Action node should gracefully skip missing entities
- What if no upstream condition nodes exist for entity mode?
  - Fail with clear error message
- What if multiple condition nodes for same entity type?
  - Combine results (union of entity IDs)

### Future Enhancements
- Add Event mode (send per Event)
- Add "send once per entity" vs "send on every campaign run" option
- Add conditional logic: "AND all conditions" vs "OR any condition"
- Webhook updates to postmark_entity_send_log (delivery, opens, clicks per entity)
- Dashboard/reporting for per-entity send analytics
- Retry failed sends after X hours/days

## References

### Domain Rules (from requirements)
- One Event can have many Opportunities
- One Event can have many Notes
- Notes and Events are not directly related (both can relate to same Contact)
- Campaigns in Mautic are contact-centric (this feature extends that)

### Mautic Campaign Event Flow
1. Contacts enter campaign (via segment, form, etc.)
2. Campaign events execute in order: Decisions → Conditions → Actions
3. Condition nodes filter contacts (true/false branches)
4. Action nodes execute on filtered contacts
5. **Our extension:** Condition nodes now STORE filter results, Action nodes INHERIT those results for per-entity sends

## Support & Troubleshooting

### Common Issues

**Migration fails with "table already exists":**
- PreUpAssertionMigration should prevent this
- Check manually: `SHOW TABLES LIKE '%postmark_entity_send_log%';`

**Condition results not found in action node:**
- Verify condition node has "store results" enabled
- Check campaign_entity_condition_result table for entries
- Ensure condition node executed BEFORE action node in campaign flow

**Duplicate sends despite idempotency:**
- Check unique index on postmark_entity_send_log
- Verify alreadySent() logic includes both 'sent' and recent 'queued' statuses

**Token resolution fails for entity fields:**
- Ensure resolveTokens() receives entity object
- Check entity has getter method for field (e.g., getAmount())
- Verify field name case matches (camelCase)

---

**Generated:** 2025-11-02
**Version:** 1.0
**Status:** Implementation in progress
