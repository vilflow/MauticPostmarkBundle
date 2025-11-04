# شرایط ارسال خودکار ایمیل (فارسی)

## 🎯 در چه حالتی سیستم ایمیل می‌فرسته؟

سیستم ایمیل می‌فرسته وقتی که **همه** این شرایط برقرار باشند:

---

## ✅ شرط 1: Contact در Campaign باشد

Contact باید:
- **وارد campaign شده باشد** (مثلاً از طریق Lead Source)
- **Campaign حداقل یک بار اجرا شده باشد** برای این contact

```bash
# بررسی کنید که contact در campaign است:
php bin/console doctrine:query:sql "SELECT * FROM campaign_lead_event_log WHERE campaign_id = 36 AND lead_id = 2445"
```

اگر نتیجه خالی بود، یعنی contact هنوز وارد campaign نشده ❌

---

## ✅ شرط 2: Entity جدید شرایط Condition را داشته باشد

بسته به نوع Entity:

### 📊 برای Opportunity (فرصت فروش):
```sql
-- در Campaign 36:
salesStage = "Closed Won"
```

**مثال:**
```bash
# Opportunity‌هایی که شرایط را دارند:
php bin/console doctrine:query:sql "
SELECT id, name, contact_id, sales_stage, date_entered
FROM opportunities
WHERE deleted = 0 AND sales_stage = 'Closed Won'
"
```

✅ اگر `salesStage = "Closed Won"` → ایمیل ارسال می‌شود
❌ اگر `salesStage = "Prospecting"` → ایمیل ارسال نمی‌شود

---

### 📝 برای Note (یادداشت):
```sql
-- در Campaign 36:
popupFormC = 0
```

**مثال:**
```bash
# Note‌هایی که شرایط را دارند:
php bin/console doctrine:query:sql "
SELECT id, name, contact_id, popup_form_c, date_entered
FROM notes
WHERE deleted = 0 AND popup_form_c = 0
"
```

✅ اگر `popupFormC = 0` → ایمیل ارسال می‌شود
❌ اگر `popupFormC = 1` → ایمیل ارسال نمی‌شود

---

### 📅 برای Event (رویداد):
```sql
-- در Campaign 36:
eventRoundC IN ('1st', '2nd')
```

**مثال:**
```bash
# Event‌هایی که شرایط را دارند:
php bin/console doctrine:query:sql "
SELECT e.id, e.name, e.event_round_c, e.date_entered
FROM events e
WHERE e.deleted = 0 AND e.event_round_c IN ('1st', '2nd')
"
```

✅ اگر `eventRoundC = "1st"` یا `"2nd"` → ایمیل ارسال می‌شود
❌ اگر `eventRoundC = "3rd"` → ایمیل ارسال نمی‌شود

---

## ✅ شرط 3: ایمیل قبلاً ارسال نشده باشد (Idempotency)

سیستم چک می‌کنه که برای این Entity قبلاً ایمیل فرستاده یا نه:

```bash
# بررسی ایمیل‌های ارسال شده:
php bin/console doctrine:query:sql "
SELECT * FROM postmark_entity_send_log
WHERE entity_type = 'opportunity'
AND entity_id = 1524
AND contact_id = 2445
"
```

✅ اگر رکوردی وجود نداشته باشد → ایمیل ارسال می‌شود
❌ اگر رکورد با `status = 'sent'` وجود داشته باشد → ایمیل ارسال نمی‌شود (تکراری)

---

## ✅ شرط 4: Contact باید ایمیل معتبر داشته باشد

Contact باید آدرس ایمیل معتبر داشته باشد:

```bash
# بررسی ایمیل contact:
php bin/console doctrine:query:sql "SELECT id, email FROM leads WHERE id = 2445"
```

✅ اگر ایمیل معتبر باشد → ایمیل ارسال می‌شود
❌ اگر ایمیل خالی یا نامعتبر باشد → ایمیل ارسال نمی‌شود

---

## ✅ شرط 5: اکانت Postmark باید تأیید شده باشد (مهم!)

**نکته مهم:** اگر اکانت Postmark شما در حالت "Pending Approval" باشد:
- فقط می‌تواند به ایمیل‌هایی بفرستد که دامنه آن با دامنه "From" یکسان باشد

**مثال خطا:**
```
Postmark error (422): {
  "ErrorCode": 412,
  "Message": "While your account is pending approval,
  all recipient addresses must share the same domain as the 'From' address.
  The domain of the 'From' address is 'acaventportal.com',
  but you are attempting to send email to the following domain(s): 'gmail.com'."
}
```

✅ `From: postmark@acaventportal.com` → `To: user@acaventportal.com` ✅ موفق
❌ `From: postmark@acaventportal.com` → `To: user@gmail.com` ❌ خطا (اگر Pending باشد)

**راه حل:** اکانت Postmark خود را تأیید کنید یا از ایمیل‌های همان دامنه استفاده کنید.

---

## 🔄 فلوچارت - چطور کار می‌کنه؟

```
1. Entity جدید اضافه می‌شود (Opportunity/Note/Event)
   ↓
2. آیا شرایط Condition را دارد؟
   ├─ بله → ادامه
   └─ خیر → ❌ ایمیل ارسال نمی‌شود
   ↓
3. آیا Contact در Campaign است؟
   ├─ بله → ادامه
   └─ خیر → ❌ ایمیل ارسال نمی‌شود
   ↓
4. آیا قبلاً برای این Entity ایمیل فرستاده شده؟
   ├─ خیر → ادامه
   └─ بله → ❌ ایمیل ارسال نمی‌شود (تکراری)
   ↓
5. Cron (هر 10 دقیقه):
   php bin/console mautic:postmark:reschedule-entities
   ↓
6. Cron (هر 5 دقیقه):
   php bin/console mautic:campaigns:trigger
   ↓
7. ✅ ایمیل ارسال می‌شود!
```

---

## 📊 مثال عملی - گام به گام

### سناریو: اضافه کردن Opportunity جدید

```bash
# مرحله 1: یک Opportunity جدید اضافه می‌کنیم
# - Contact: 2445
# - Name: "فروش محصول جدید"
# - salesStage: "Closed Won" ✅

# مرحله 2: بررسی می‌کنیم که شرایط را دارد
php bin/console doctrine:query:sql "
SELECT id, name, sales_stage
FROM opportunities
WHERE id = 1525
"
# خروجی: sales_stage = "Closed Won" ✅

# مرحله 3: بررسی می‌کنیم که Contact در Campaign است
php bin/console doctrine:query:sql "
SELECT COUNT(*) as count
FROM campaign_lead_event_log
WHERE campaign_id = 36 AND lead_id = 2445
"
# خروجی: count > 0 ✅

# مرحله 4: بررسی می‌کنیم که قبلاً ایمیل نفرستادیم
php bin/console doctrine:query:sql "
SELECT COUNT(*) as count
FROM postmark_entity_send_log
WHERE entity_type = 'opportunity'
AND entity_id = 1525
"
# خروجی: count = 0 ✅ (هنوز ارسال نشده)

# مرحله 5: صبر می‌کنیم تا Cron اجرا شود (یا دستی اجرا می‌کنیم)
php bin/console mautic:postmark:reschedule-entities

# خروجی:
# Processing: Send Email via Postmark [Opportunity mode]
# Rescheduled 1 contact(s)

# مرحله 6: Campaign را Trigger می‌کنیم
php bin/console mautic:campaigns:trigger

# خروجی:
# 1 total event was executed

# مرحله 7: بررسی می‌کنیم که ایمیل ارسال شده
php bin/console doctrine:query:sql "
SELECT * FROM postmark_entity_send_log
WHERE entity_type = 'opportunity'
AND entity_id = 1525
"
# خروجی: status = 'sent', sent_at = '2025-11-03 12:30:00' ✅
```

---

## 🛠️ تست کردن - چک لیست

برای تست اینکه سیستم درست کار می‌کنه:

### 1️⃣ بررسی Entity‌های موجود که شرایط را دارند:
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

### 2️⃣ بررسی ایمیل‌های ارسال شده:
```bash
php bin/console doctrine:query:sql "
SELECT entity_type, entity_id, contact_id, status, sent_at
FROM postmark_entity_send_log
WHERE campaign_id = 36
ORDER BY id DESC LIMIT 20
"
```

### 3️⃣ تست ارسال برای Entity‌های جدید:
```bash
# گام 1: Reschedule کردن
php bin/console mautic:postmark:reschedule-entities -i 36

# گام 2: Trigger کردن Campaign
php bin/console mautic:campaigns:trigger -i 36

# گام 3: بررسی نتیجه
php bin/console doctrine:query:sql "
SELECT entity_type, entity_id, status, sent_at
FROM postmark_entity_send_log
ORDER BY id DESC LIMIT 10
"
```

---

## ❌ دلایل عدم ارسال ایمیل

اگر ایمیل ارسال نمی‌شود، یکی از این مشکلات است:

### 1. Entity شرایط Condition را ندارد
```
❌ مثال: salesStage = "Prospecting" اما Campaign می‌خواهد "Closed Won"
```

### 2. Contact در Campaign نیست
```
❌ Contact هنوز وارد Campaign نشده
```

### 3. قبلاً ایمیل فرستاده شده (تکراری)
```
❌ در جدول postmark_entity_send_log رکورد با status='sent' وجود دارد
```

### 4. Contact ایمیل معتبر ندارد
```
❌ ایمیل خالی است یا نامعتبر است
```

### 5. اکانت Postmark در حالت Pending است
```
❌ فقط می‌تواند به همان دامنه From بفرستد
✅ راه حل: اکانت را تأیید کنید
```

### 6. Cron اجرا نمی‌شود
```
❌ Command های reschedule و trigger اجرا نمی‌شوند
✅ راه حل: Crontab را چک کنید
```

---

## 📋 خلاصه - شرایط ارسال

برای اینکه ایمیل ارسال شود:

1. ✅ Entity (Opportunity/Note/Event) اضافه شده باشد
2. ✅ Entity شرایط Condition را داشته باشد:
   - Opportunity: `salesStage = "Closed Won"`
   - Note: `popupFormC = 0`
   - Event: `eventRoundC IN ('1st', '2nd')`
3. ✅ Contact در Campaign باشد
4. ✅ قبلاً برای این Entity ایمیل نفرستاده باشیم
5. ✅ Contact ایمیل معتبر داشته باشد
6. ✅ Cron ها اجرا شوند:
   - `mautic:postmark:reschedule-entities` (هر 10 دقیقه)
   - `mautic:campaigns:trigger` (هر 5 دقیقه)
7. ✅ اکانت Postmark تأیید شده باشد (یا دامنه ایمیل یکسان باشد)

---

## 🚀 اجرای دستی برای تست

```bash
# تست کامل:
php bin/console mautic:postmark:reschedule-entities -i 36 && \
php bin/console mautic:campaigns:trigger -i 36

# بررسی نتیجه:
php bin/console doctrine:query:sql "
SELECT entity_type, entity_id, contact_id, status, sent_at
FROM postmark_entity_send_log
WHERE campaign_id = 36
ORDER BY id DESC LIMIT 10
"
```

---

## 📞 پشتیبانی

اگر مشکلی دارید:
1. فایل `ENTITY_EMAIL_AUTOMATION.md` را بخوانید (مستندات کامل انگلیسی)
2. لاگ‌ها را چک کنید: `var/logs/mautic_prod.log`
3. از دستورات بالا برای Debug استفاده کنید
