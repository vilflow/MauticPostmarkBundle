# Automatic Email Sending for New Entities (Opportunity, Note, Event)

## Problem
When running a campaign with "Per Opportunity", "Per Note", or "Per Event" send modes, emails are sent to existing entities that match conditions. However, new entities added after the campaign run don't automatically trigger new emails.

## Root Cause
The OpportunityLifecycleSubscriber was designed to automatically queue campaign executions when entities are created, but it relies on Doctrine lifecycle events which aren't triggered when entities are created through the Mautic UI.

## Solution
A new command `mautic:postmark:reschedule-entities` has been implemented that reschedules entity-mode Postmark actions so they check for new entities on the next campaign run.

## How It Works

### 1. The Reschedule Command
The command `/var/www/html/mautic_dev/plugins/MauticPostmarkBundle/Command/RescheduleEntityActionsCommand.php`:
- Finds all Postmark campaign actions configured in "opportunity", "note", or "event" mode
- Reschedules contacts who have already executed the action so they can be re-evaluated
- Checks for new entities that match campaign conditions

### 2. The Campaign Trigger
When `mautic:campaigns:trigger` runs, it:
- Processes all scheduled events
- For entity-mode actions, checks ALL entities (opportunities/notes/events) for each contact
- Only sends emails to entities that haven't been sent yet (idempotency check)
- Logs all sends to prevent duplicates

## Setup Instructions

### Add to Crontab
Add the reschedule command to your crontab to run every 5-15 minutes:

```bash
# Edit crontab
crontab -e

# Add this line (runs every 10 minutes for ALL entity types)
*/10 * * * * php /var/www/html/mautic_dev/bin/console mautic:postmark:reschedule-entities

# Or run for specific campaign only
*/10 * * * * php /var/www/html/mautic_dev/bin/console mautic:postmark:reschedule-entities -i 36

# Or run for specific entity mode only (opportunity, note, or event)
*/10 * * * * php /var/www/html/mautic_dev/bin/console mautic:postmark:reschedule-entities -m opportunity
```

### Ensure Campaign Trigger Runs
Make sure the campaign trigger cron is also running:

```bash
# Add to crontab if not already present
*/5 * * * * php /var/www/html/mautic_dev/bin/console mautic:campaigns:trigger
```

## Usage

### Manual Testing
You can manually trigger the workflow:

```bash
# Step 1: Reschedule entity-mode actions
php /var/www/html/mautic_dev/bin/console mautic:postmark:reschedule-entities

# Step 2: Trigger campaign execution
php /var/www/html/mautic_dev/bin/console mautic:campaigns:trigger
```

### For Specific Campaign
```bash
# Reschedule only campaign 36
php /var/www/html/mautic_dev/bin/console mautic:postmark:reschedule-entities -i 36

# Trigger only campaign 36
php /var/www/html/mautic_dev/bin/console mautic:campaigns:trigger -i 36
```

### For Specific Entity Mode
```bash
# Reschedule only opportunity-mode actions
php /var/www/html/mautic_dev/bin/console mautic:postmark:reschedule-entities -m opportunity

# Reschedule only note-mode actions
php /var/www/html/mautic_dev/bin/console mautic:postmark:reschedule-entities -m note

# Reschedule only event-mode actions
php /var/www/html/mautic_dev/bin/console mautic:postmark:reschedule-entities -m event
```

## Workflow Example

### For Opportunities
1. **10:00 AM** - Campaign runs, sends emails for opportunities 1, 2, 3
2. **10:30 AM** - You add new opportunity 4
3. **10:40 AM** - Reschedule command runs (from cron), marks contacts for re-evaluation
4. **10:45 AM** - Campaign trigger runs (from cron), finds and sends email for opportunity 4

### For Notes
1. **10:00 AM** - Campaign runs, sends emails for notes 1, 2
2. **10:30 AM** - You add new note 3
3. **10:40 AM** - Reschedule command runs, marks contacts for re-evaluation
4. **10:45 AM** - Campaign trigger runs, finds and sends email for note 3

### For Events
1. **10:00 AM** - Campaign runs, sends emails for events 1, 2
2. **10:30 AM** - You add new event 3
3. **10:40 AM** - Reschedule command runs, marks contacts for re-evaluation
4. **10:45 AM** - Campaign trigger runs, finds and sends email for event 3

## Monitoring

### Check Reschedule Activity
```bash
# View logs for all entity types
tail -f /var/www/html/mautic_dev/var/logs/mautic_prod.log | grep "Rescheduled entity-mode"
```

### Check Send Logs by Entity Type
```bash
# Query send logs grouped by entity type
php bin/console doctrine:query:sql "SELECT entity_type, COUNT(*) as count, MAX(created_at) as latest_send FROM postmark_entity_send_log WHERE campaign_id = 36 GROUP BY entity_type"
```

### Check Recent Sends for Specific Entity Type
```bash
# For opportunities
php bin/console doctrine:query:sql "SELECT * FROM postmark_entity_send_log WHERE entity_type = 'opportunity' ORDER BY created_at DESC LIMIT 10"

# For notes
php bin/console doctrine:query:sql "SELECT * FROM postmark_entity_send_log WHERE entity_type = 'note' ORDER BY created_at DESC LIMIT 10"

# For events
php bin/console doctrine:query:sql "SELECT * FROM postmark_entity_send_log WHERE entity_type = 'event' ORDER BY created_at DESC LIMIT 10"
```

### Check Campaign Logs
```bash
# View campaign execution logs for specific action
php bin/console doctrine:query:sql "SELECT id, lead_id, event_id, date_triggered, is_scheduled FROM campaign_lead_event_log WHERE event_id = 464 ORDER BY id DESC LIMIT 10"
```

## Command Options

### Full Command Reference
```bash
php bin/console mautic:postmark:reschedule-entities [options]

Options:
  -i, --campaign-id=CAMPAIGN-ID   Only reschedule actions for a specific campaign ID
  -m, --mode=MODE                 Only reschedule actions for a specific mode
                                  (opportunity, note, or event)
  -h, --help                      Display help message
```

### Examples
```bash
# Reschedule all entity-mode actions across all campaigns
php bin/console mautic:postmark:reschedule-entities

# Reschedule only campaign 36
php bin/console mautic:postmark:reschedule-entities -i 36

# Reschedule only opportunity-mode actions
php bin/console mautic:postmark:reschedule-entities -m opportunity

# Reschedule only opportunity-mode actions in campaign 36
php bin/console mautic:postmark:reschedule-entities -i 36 -m opportunity
```

## Performance Considerations

- The reschedule command is lightweight and only updates database rows
- It only reschedules contacts who have already executed the action at least once
- Idempotency checks prevent duplicate emails from being sent
- Recommended frequency: Every 5-15 minutes depending on how quickly you need new entities processed
- You can run mode-specific commands if you want different frequencies per entity type

## Troubleshooting

### New entities not getting emails?

1. **Check if reschedule command is running**:
   ```bash
   php bin/console mautic:postmark:reschedule-entities -i 36
   ```
   Should output: "Rescheduled X contact(s)"

2. **Check if campaign trigger is running**:
   ```bash
   php bin/console mautic:campaigns:trigger -i 36
   ```
   Should output: "X total events were executed"

3. **Verify entity matches conditions**:
   - Check the campaign's entity field value condition
   - Ensure the new entity's field value matches the condition

4. **Check send logs for duplicates**:
   ```bash
   # For opportunity
   php bin/console doctrine:query:sql "SELECT * FROM postmark_entity_send_log WHERE entity_type = 'opportunity' AND entity_id = YOUR_ENTITY_ID"

   # For note
   php bin/console doctrine:query:sql "SELECT * FROM postmark_entity_send_log WHERE entity_type = 'note' AND entity_id = YOUR_ENTITY_ID"

   # For event
   php bin/console doctrine:query:sql "SELECT * FROM postmark_entity_send_log WHERE entity_type = 'event' AND entity_id = YOUR_ENTITY_ID"
   ```

### Emails being sent multiple times?

This shouldn't happen due to idempotency checks, but if it does:
- Check the `postmark_entity_send_log` table for duplicate entries
- Verify the `alreadySent()` method in CampaignSubscriber.php:924

### Command shows "No Postmark actions found"?

This means no actions are configured in entity mode. Check:
```bash
# Verify action mode in database
php bin/console doctrine:query:sql "SELECT id, name, type, properties FROM campaign_events WHERE campaign_id = YOUR_CAMPAIGN_ID AND type = 'postmark.send'"
```

Look for `s:4:\"mode\";s:11:\"opportunity\"` or similar in the properties field.

## Files Modified/Created

1. **Created**: `Command/RescheduleEntityActionsCommand.php` - The reschedule command for all entity types
2. **Modified**: `Config/services.php` - Registered the command as a service
3. **Modified**: `EventListener/OpportunityLifecycleSubscriber.php` - Added debug logging (kept for future Doctrine event improvements)
4. **Modified**: `Service/EventCriteriaBuilder.php` - Fixed EntityManager null issue with lazy loading
5. **Modified**: `Service/OpportunityCriteriaBuilder.php` - Fixed EntityManager null issue with lazy loading
6. **Modified**: `Service/NoteCriteriaBuilder.php` - Fixed EntityManager null issue with lazy loading

## Technical Details

### Supported Entity Types
The command supports three entity types:
1. **Opportunity** - Sends one email per opportunity (from MauticOpportunitiesBundle)
2. **Note** - Sends one email per note (from MauticNotesBundle)
3. **Event** - Sends one email per event (from MauticEventsBundle)

### Why Doctrine Events Don't Work
Mautic uses its own Model layer (FormModel) for entity persistence, which may bypass Doctrine's lifecycle events in certain contexts. The OpportunityLifecycleSubscriber listens for `postPersist` and `postUpdate` events, but these aren't reliably triggered when entities are created through the Mautic UI.

### Why This Solution Works
Instead of relying on real-time event triggers, we use a scheduled polling approach:
- The reschedule command periodically reschedules contacts
- The campaign trigger checks for ALL entities (not just new ones)
- Idempotency ensures each entity only gets one email

This is more reliable and doesn't depend on Doctrine event timing.

### Entity-Specific Implementations

#### Opportunity Mode (CampaignSubscriber.php:425-555)
- Filters opportunities by upstream conditions
- Respects Event relationships (if campaign has Event condition)
- Sends one email per opportunity
- Resolves opportunity field tokens (e.g., `{opportunityfield=name}`)

#### Note Mode (CampaignSubscriber.php:560-690)
- Filters notes by upstream conditions
- Respects Event relationships
- Sends one email per note
- Resolves note field tokens (e.g., `{notefield=description}`)

#### Event Mode (CampaignSubscriber.php:695-862)
- Filters events by upstream conditions
- Sends one email per event
- Resolves event field tokens (e.g., `{eventfield=name}`)

## Future Improvements

Potential enhancements:
1. Hook into Mautic's event dispatcher instead of Doctrine events
2. Add custom form event listeners for entity creation
3. Implement a queue-based system for better scalability
4. Add configuration options for reschedule frequency per campaign
5. Create lifecycle subscribers for Note and Event entities (when Doctrine events are fixed)
