# Automatic Email Sending Conditions (English)

## 🎯 When Does the System Send Emails?

The system sends emails when **ALL** of these conditions are met:

---

## ✅ Condition 1: Contact Must Be in Campaign

Contact must:
- **Have entered the campaign** (e.g., via Lead Source)
- **Campaign has run at least once** for this contact

```bash
# Check if contact is in campaign:
php bin/console doctrine:query:sql "SELECT * FROM campaign_lead_event_log WHERE campaign_id = 36 AND lead_id = 2445"
```

If result is empty, contact hasn't entered campaign yet ❌

---

## ✅ Condition 2: New Entity Must Match Condition Criteria

Depending on entity type:

### 📊 For Opportunity (Sales Opportunity):
```sql
-- In Campaign 36:
salesStage = "Closed Won"
```

**Example:**
```bash
# Opportunities that match criteria:
php bin/console doctrine:query:sql "
SELECT id, name, contact_id, sales_stage, date_entered
FROM opportunities
WHERE deleted = 0 AND sales_stage = 'Closed Won'
"
```

✅ If `salesStage = "Closed Won"` → Email will be sent
❌ If `salesStage = "Prospecting"` → Email will NOT be sent

---

### 📝 For Note:
```sql
-- In Campaign 36:
popupFormC = 0
```

**Example:**
```bash
# Notes that match criteria:
php bin/console doctrine:query:sql "
SELECT id, name, contact_id, popup_form_c, date_entered
FROM notes
WHERE deleted = 0 AND popup_form_c = 0
"
```

✅ If `popupFormC = 0` → Email will be sent
❌ If `popupFormC = 1` → Email will NOT be sent

---

### 📅 For Event:
```sql
-- In Campaign 36:
eventRoundC IN ('1st', '2nd')
```

**Example:**
```bash
# Events that match criteria:
php bin/console doctrine:query:sql "
SELECT e.id, e.name, e.event_round_c, e.date_entered
FROM events e
WHERE e.deleted = 0 AND e.event_round_c IN ('1st', '2nd')
"
```

✅ If `eventRoundC = "1st"` or `"2nd"` → Email will be sent
❌ If `eventRoundC = "3rd"` → Email will NOT be sent

---

## ✅ Condition 3: Email Not Already Sent (Idempotency)

System checks if email was already sent for this Entity:

```bash
# Check sent emails:
php bin/console doctrine:query:sql "
SELECT * FROM postmark_entity_send_log
WHERE entity_type = 'opportunity'
AND entity_id = 1524
AND contact_id = 2445
"
```

✅ If no record exists → Email will be sent
❌ If record with `status = 'sent'` exists → Email will NOT be sent (duplicate)

---

## ✅ Condition 4: Contact Must Have Valid Email

Contact must have a valid email address:

```bash
# Check contact email:
php bin/console doctrine:query:sql "SELECT id, email FROM leads WHERE id = 2445"
```

✅ If valid email → Email will be sent
❌ If empty or invalid email → Email will NOT be sent

---

## ✅ Condition 5: Postmark Account Must Be Verified (Important!)

**Important Note:** If your Postmark account is in "Pending Approval" status:
- Can only send to emails where domain matches "From" domain

**Example Error:**
```
Postmark error (422): {
  "ErrorCode": 412,
  "Message": "While your account is pending approval,
  all recipient addresses must share the same domain as the 'From' address.
  The domain of the 'From' address is 'acaventportal.com',
  but you are attempting to send email to the following domain(s): 'gmail.com'."
}
```

✅ `From: postmark@acaventportal.com` → `To: user@acaventportal.com` ✅ Success
❌ `From: postmark@acaventportal.com` → `To: user@gmail.com` ❌ Error (if Pending)

**Solution:** Verify your Postmark account or use same-domain emails.

---

## 🔄 Flowchart - How It Works

```
1. New Entity added (Opportunity/Note/Event)
   ↓
2. Does it match Condition criteria?
   ├─ Yes → Continue
   └─ No → ❌ Email NOT sent
   ↓
3. Is Contact in Campaign?
   ├─ Yes → Continue
   └─ No → ❌ Email NOT sent
   ↓
4. Has email already been sent for this Entity?
   ├─ No → Continue
   └─ Yes → ❌ Email NOT sent (duplicate)
   ↓
5. Cron (every 10 minutes):
   php bin/console mautic:postmark:reschedule-entities
   ↓
6. Cron (every 5 minutes):
   php bin/console mautic:campaigns:trigger
   ↓
7. ✅ Email is sent!
```

---

## 📊 Practical Example - Step by Step

### Scenario: Adding New Opportunity

```bash
# Step 1: Add new Opportunity
# - Contact: 2445
# - Name: "New Product Sale"
# - salesStage: "Closed Won" ✅

# Step 2: Verify it matches criteria
php bin/console doctrine:query:sql "
SELECT id, name, sales_stage
FROM opportunities
WHERE id = 1525
"
# Output: sales_stage = "Closed Won" ✅

# Step 3: Verify Contact is in Campaign
php bin/console doctrine:query:sql "
SELECT COUNT(*) as count
FROM campaign_lead_event_log
WHERE campaign_id = 36 AND lead_id = 2445
"
# Output: count > 0 ✅

# Step 4: Verify email hasn't been sent
php bin/console doctrine:query:sql "
SELECT COUNT(*) as count
FROM postmark_entity_send_log
WHERE entity_type = 'opportunity'
AND entity_id = 1525
"
# Output: count = 0 ✅ (not sent yet)

# Step 5: Wait for Cron to run (or run manually)
php bin/console mautic:postmark:reschedule-entities

# Output:
# Processing: Send Email via Postmark [Opportunity mode]
# Rescheduled 1 contact(s)

# Step 6: Trigger campaign
php bin/console mautic:campaigns:trigger

# Output:
# 1 total event was executed

# Step 7: Verify email was sent
php bin/console doctrine:query:sql "
SELECT * FROM postmark_entity_send_log
WHERE entity_type = 'opportunity'
AND entity_id = 1525
"
# Output: status = 'sent', sent_at = '2025-11-03 12:30:00' ✅
```

---

## 🛠️ Testing - Checklist

To test if system is working correctly:

### 1️⃣ Check Existing Entities Matching Criteria:
```bash
# Opportunities
php bin/console doctrine:query:sql "
SELECT id, name, contact_id, sales_stage
FROM opportunities
WHERE deleted = 0 AND sales_stage = 'Closed Won'
ORDER BY date_entered DESC LIMIT 10
"

# Notes
php bin/console doctrine:query:sql "
SELECT id, name, contact_id, popup_form_c
FROM notes
WHERE deleted = 0 AND popup_form_c = 0
ORDER BY date_entered DESC LIMIT 10
"

# Events
php bin/console doctrine:query:sql "
SELECT id, name, event_round_c
FROM events
WHERE deleted = 0 AND event_round_c IN ('1st', '2nd')
ORDER BY date_entered DESC LIMIT 10
"
```

### 2️⃣ Check Sent Emails:
```bash
php bin/console doctrine:query:sql "
SELECT entity_type, entity_id, contact_id, status, sent_at
FROM postmark_entity_send_log
WHERE campaign_id = 36
ORDER BY id DESC LIMIT 20
"
```

### 3️⃣ Test Sending for New Entities:
```bash
# Step 1: Reschedule
php bin/console mautic:postmark:reschedule-entities -i 36

# Step 2: Trigger Campaign
php bin/console mautic:campaigns:trigger -i 36

# Step 3: Check Result
php bin/console doctrine:query:sql "
SELECT entity_type, entity_id, status, sent_at
FROM postmark_entity_send_log
ORDER BY id DESC LIMIT 10
"
```

---

## ❌ Reasons Email NOT Sent

If email is not sent, one of these is the problem:

### 1. Entity Doesn't Match Condition Criteria
```
❌ Example: salesStage = "Prospecting" but Campaign wants "Closed Won"
```

### 2. Contact Not in Campaign
```
❌ Contact hasn't entered Campaign yet
```

### 3. Email Already Sent (Duplicate)
```
❌ Record exists in postmark_entity_send_log with status='sent'
```

### 4. Contact Doesn't Have Valid Email
```
❌ Email is empty or invalid
```

### 5. Postmark Account in Pending Status
```
❌ Can only send to same domain as From address
✅ Solution: Verify account
```

### 6. Cron Not Running
```
❌ Reschedule and trigger commands not running
✅ Solution: Check Crontab
```

---

## 📋 Summary - Sending Conditions

For email to be sent:

1. ✅ Entity (Opportunity/Note/Event) added
2. ✅ Entity matches Condition criteria:
   - Opportunity: `salesStage = "Closed Won"`
   - Note: `popupFormC = 0`
   - Event: `eventRoundC IN ('1st', '2nd')`
3. ✅ Contact is in Campaign
4. ✅ Haven't sent email for this Entity before
5. ✅ Contact has valid email
6. ✅ Crons are running:
   - `mautic:postmark:reschedule-entities` (every 10 minutes)
   - `mautic:campaigns:trigger` (every 5 minutes)
7. ✅ Postmark account is verified (or same-domain email)

---

## 🚀 Manual Execution for Testing

```bash
# Complete test:
php bin/console mautic:postmark:reschedule-entities -i 36 && \
php bin/console mautic:campaigns:trigger -i 36

# Check result:
php bin/console doctrine:query:sql "
SELECT entity_type, entity_id, contact_id, status, sent_at
FROM postmark_entity_send_log
WHERE campaign_id = 36
ORDER BY id DESC LIMIT 10
"
```

---

## 📞 Support

If you have problems:
1. Read `ENTITY_EMAIL_AUTOMATION.md` (complete English documentation)
2. Check logs: `var/logs/mautic_prod.log`
3. Use commands above for debugging

---

**Last Updated:** 2025-11-04
**Status:** ✅ Production ready
