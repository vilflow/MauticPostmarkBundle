# ⚠️ DEPRECATED - DO NOT USE

> **IMPORTANT**: This documentation is **DEPRECATED** and outdated. It has been superseded by [ENTITY_EMAIL_AUTOMATION.md](ENTITY_EMAIL_AUTOMATION.md) which covers all entity types (Opportunity, Note, and Event) with the correct command names and implementation details.
>
> **This file is kept for historical reference only. Please use ENTITY_EMAIL_AUTOMATION.md for current documentation.**

---

# Legacy Documentation (Outdated - For Historical Reference Only)

**Deprecated:** 2025-11-04
**Reason:** Command names changed, implementation evolved to support all entity types

# Automatic Email Sending for New Opportunities

## Problem
When running a campaign with "Per Opportunity" send mode, emails are sent to existing opportunities that match conditions. However, new opportunities added after the campaign run don't automatically trigger new emails.

## Root Cause
The original OpportunityLifecycleSubscriber was designed to automatically queue campaign executions when opportunities are created, but it relies on Doctrine lifecycle events which aren't triggered when opportunities are created through the Mautic UI.

## Solution (OUTDATED)
~~A new command `mautic:postmark:reschedule-opportunities` has been implemented~~

**CURRENT:** Use `mautic:postmark:reschedule-entities` which supports all entity types (opportunity, note, event).
See [ENTITY_EMAIL_AUTOMATION.md](ENTITY_EMAIL_AUTOMATION.md) for current documentation.

## How It Works

### 1. The Reschedule Command (OUTDATED)
~~The command `/var/www/html/mautic_dev/plugins/MauticPostmarkBundle/Command/RescheduleOpportunityActionsCommand.php`~~

**CURRENT:** Command renamed to `RescheduleEntityActionsCommand.php` and supports all entity types.
- Finds all Postmark campaign actions configured in "opportunity" mode
- Reschedules contacts who have already executed the action so they can be re-evaluated
- Checks for new opportunities that match campaign conditions

### 2. The Campaign Trigger
When `mautic:campaigns:trigger` runs, it:
- Processes all scheduled events
- For opportunity-mode actions, checks ALL opportunities for each contact
- Only sends emails to opportunities that haven't been sent yet (idempotency check)
- Logs all sends to prevent duplicates

## Setup Instructions

### Add to Crontab
Add the reschedule command to your crontab to run every 5-15 minutes:

```bash
# Edit crontab
crontab -e

# Add this line (runs every 10 minutes)
*/10 * * * * php /var/www/html/mautic_dev/bin/console mautic:postmark:reschedule-opportunities

# Or run for specific campaign only
*/10 * * * * php /var/www/html/mautic_dev/bin/console mautic:postmark:reschedule-opportunities -i 36
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
# Step 1: Reschedule opportunity-mode actions
php /var/www/html/mautic_dev/bin/console mautic:postmark:reschedule-opportunities

# Step 2: Trigger campaign execution
php /var/www/html/mautic_dev/bin/console mautic:campaigns:trigger
```

### For Specific Campaign
```bash
# Reschedule only campaign 36
php /var/www/html/mautic_dev/bin/console mautic:postmark:reschedule-opportunities -i 36

# Trigger only campaign 36
php /var/www/html/mautic_dev/bin/console mautic:campaigns:trigger -i 36
```

## Workflow Example

1. **Campaign runs at 10:00 AM**
   - Sends emails for opportunities 1, 2, 3

2. **New opportunity 4 is created at 10:30 AM**
   - Opportunity matches campaign conditions
   - But campaign doesn't run automatically

3. **Reschedule command runs at 10:40 AM** (from cron)
   - Finds all contacts with opportunity-mode actions
   - Reschedules them for execution

4. **Campaign trigger runs at 10:45 AM** (from cron)
   - Checks all opportunities for rescheduled contacts
   - Finds opportunity 4 hasn't been sent yet
   - Sends email for opportunity 4

## Monitoring

### Check Reschedule Activity
```bash
# View logs
tail -f /var/www/html/mautic_dev/var/logs/mautic_prod.log | grep "Rescheduled opportunity-mode"
```

### Check Send Logs
```bash
# Query send logs for opportunities
php bin/console doctrine:query:sql "SELECT * FROM postmark_entity_send_log WHERE entity_type = 'opportunity' ORDER BY created_at DESC LIMIT 10"
```

### Check Campaign Logs
```bash
# View campaign execution logs
php bin/console doctrine:query:sql "SELECT id, lead_id, event_id, date_triggered, is_scheduled FROM campaign_lead_event_log WHERE event_id = 464 ORDER BY id DESC LIMIT 10"
```

## Performance Considerations

- The reschedule command is lightweight and only updates database rows
- It only reschedules contacts who have already executed the action at least once
- Idempotency checks prevent duplicate emails from being sent
- Recommended frequency: Every 5-15 minutes depending on how quickly you need new opportunities processed

## Troubleshooting

### New opportunities not getting emails?

1. **Check if reschedule command is running**:
   ```bash
   php bin/console mautic:postmark:reschedule-opportunities -i 36
   ```
   Should output: "Rescheduled X contact(s)"

2. **Check if campaign trigger is running**:
   ```bash
   php bin/console mautic:campaigns:trigger -i 36
   ```
   Should output: "X total events were executed"

3. **Verify opportunity matches conditions**:
   - Check the campaign's opportunity field value condition
   - Ensure the new opportunity's salesStage (or other field) matches

4. **Check send logs for duplicates**:
   ```bash
   php bin/console doctrine:query:sql "SELECT * FROM postmark_entity_send_log WHERE entity_type = 'opportunity' AND entity_id = YOUR_OPPORTUNITY_ID"
   ```

### Emails being sent multiple times?

This shouldn't happen due to idempotency checks, but if it does:
- Check the `postmark_entity_send_log` table for duplicate entries
- Verify the `alreadySent()` method in CampaignSubscriber.php:924

## Files Modified/Created

1. **Created**: `Command/RescheduleOpportunityActionsCommand.php` - The reschedule command
2. **Modified**: `Config/services.php` - Registered the command as a service
3. **Modified**: `EventListener/OpportunityLifecycleSubscriber.php` - Added debug logging
4. **Modified**: `Service/EventCriteriaBuilder.php` - Fixed EntityManager null issue with lazy loading
5. **Modified**: `Service/OpportunityCriteriaBuilder.php` - Fixed EntityManager null issue with lazy loading
6. **Modified**: `Service/NoteCriteriaBuilder.php` - Fixed EntityManager null issue with lazy loading

## Technical Details

### Why Doctrine Events Don't Work
Mautic uses its own Model layer (FormModel) for entity persistence, which may bypass Doctrine's lifecycle events in certain contexts. The OpportunityLifecycleSubscriber listens for `postPersist` and `postUpdate` events, but these aren't reliably triggered when opportunities are created through the Mautic UI.

### Why This Solution Works
Instead of relying on real-time event triggers, we use a scheduled polling approach:
- The reschedule command periodically reschedules contacts
- The campaign trigger checks for ALL opportunities (not just new ones)
- Idempotency ensures each opportunity only gets one email

This is more reliable and doesn't depend on Doctrine event timing.

## Future Improvements

Potential enhancements:
1. Hook into Mautic's event dispatcher instead of Doctrine events
2. Add a custom form event listener for opportunity creation
3. Implement a queue-based system for better scalability
4. Add configuration options for reschedule frequency per campaign
