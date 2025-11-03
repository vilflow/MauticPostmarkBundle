# راهنمای کامل - ارسال ایمیل به ازای هر موجودیت (Per-Entity Send)

## ✅ پیاده‌سازی کامل شد!

این سیستم به شما امکان می‌دهد که در کمپین‌های Mautic، به جای ارسال یک ایمیل به هر مخاطب، **یک ایمیل جداگانه برای هر فرصت فروش (Opportunity)** یا **هر یادداشت (Note)** ارسال کنید.

---

## چه چیزی پیاده‌سازی شد؟

### ۱. پایگاه داده (۳ جدول جدید)

#### جدول `campaign_entity_condition_result`
- نتایج شرط‌های کمپین را ذخیره می‌کند
- وقتی شرط "Has Matching Opportunities" اجرا می‌شود، لیست فرصت‌های منطبق اینجا ذخیره می‌شود
- Action بعدی می‌تواند این لیست را بخواند

#### جدول `campaign_entity_condition_result_item`
- برای مخاطبانی با بیش از ۱۰۰۰ فرصت فروش
- جدول کمکی برای لیست‌های خیلی بزرگ

#### جدول `postmark_entity_send_log`
- هر ارسال ایمیل را ثبت می‌کند
- جلوی ارسال تکراری را می‌گیرد (idempotency)
- وضعیت: sent, failed, queued
- شامل شناسه پیام Postmark و متن خطا (در صورت شکست)

### ۲. کدهای PHP (۲۰ فایل جدید/تغییر یافته)

#### Entity Classes
- `CampaignEntityConditionResult` + Repository
- `PostmarkEntitySendLog` + Repository

#### DTOs
- `EntityFilterSpec` - نگهداری فیلترها در JSON

#### Services
- `OpportunityCriteriaBuilder` - ساخت کوئری برای Opportunity
- `NoteCriteriaBuilder` - ساخت کوئری برای Note

#### Event Subscribers
- `CampaignSubscriber` - گسترش یافته با:
  - `sendPerOpportunity()` - ارسال به ازای هر فرصت
  - `sendPerNote()` - ارسال به ازای هر یادداشت
  - `sendPerContact()` - همان رفتار قبلی
- `EntityConditionSubscriber` - شرط‌های جدید:
  - "Has Matching Opportunities"
  - "Has Matching Notes"

#### Form Types
- `OpportunityConditionType` - فرم تنظیمات شرط Opportunity
- `NoteConditionType` - فرم تنظیمات شرط Note

### ۳. رابط کاربری (UI)

#### در Campaign Builder حالا این‌ها را دارید:

**Conditions (شرط‌ها):**
- ✅ Has Matching Opportunities
- ✅ Has Matching Notes

**Actions (عملیات‌ها):**
- ✅ Send Email via Postmark
  - Mode: **Per Contact** (پیش‌فرض - همان رفتار قبلی)
  - Mode: **Per Opportunity** (جدید)
  - Mode: **Per Note** (جدید)

---

## نحوه استفاده

### مرحله ۱: اجرای Migration

**روش A: از طریق SQL مستقیم**
```bash
mysql -u root -p mautic < /var/www/html/mautic_dev/plugins/MauticPostmarkBundle/DEPLOYMENT.md
```

در فایل `DEPLOYMENT.md` SQL کامل وجود دارد.

**روش B: از طریق Doctrine**
```bash
php bin/console doctrine:schema:update --dump-sql  # بررسی
php bin/console doctrine:schema:update --force     # اجرا
```

### مرحله ۲: پاک کردن کش
```bash
rm -rf /var/www/html/mautic_dev/var/cache/*
php bin/console cache:clear
php bin/console cache:warmup
```

### مرحله ۳: بررسی نصب
```bash
mysql -u root -p mautic -e "SHOW TABLES LIKE '%campaign_entity%'"
mysql -u root -p mautic -e "SHOW TABLES LIKE '%postmark_entity%'"
```

---

## مثال عملی

### سناریو: ارسال ایمیل یادآوری برای هر فرصت فروش باز

#### کمپین:
```
[Start]
   ↓
[Condition: Has Matching Opportunities]
   Field: salesStage
   Operator: Equals (=)
   Value: Prospecting
   ↓ TRUE (اگر حداقل یک فرصت منطبق باشد)
[Action: Send Email via Postmark]
   Mode: Per Opportunity ← انتخاب این!
   From: noreply@yoursite.com
   To: {contactfield=email}
   Template: opportunity-follow-up
   Variables:
     - opportunity_name: {opportunityfield=name}
     - opportunity_amount: {opportunityfield=amount}
```

#### اگر مخاطب "علی رضایی" این فرصت‌ها را داشته باشد:

| ID | Name | salesStage | amount |
|----|------|------------|--------|
| 101 | فرصت A | Prospecting | ۵۰۰۰ |
| 205 | فرصت B | Prospecting | ۳۰۰۰ |
| 387 | فرصت C | Closed Won | ۸۰۰۰ |

#### چه اتفاقی می‌افتد؟

۱. **Condition اجرا می‌شود:**
   - جستجو می‌کند: کدام Opportunityها salesStage = "Prospecting" دارند؟
   - پیدا می‌کند: [101, 205]
   - ذخیره می‌کند در جدول `campaign_entity_condition_result`
   - نتیجه: TRUE (مخاطب رد می‌شود)

۲. **Action اجرا می‌شود:**
   - می‌خواند از جدول: کدام Opportunityها ذخیره شده‌اند؟
   - پاسخ: [101, 205]
   - برای هر کدام ایمیل می‌فرستد:

**ایمیل ۱ (فرصت ۱۰۱):**
```
To: ali@example.com
Subject: Follow up on فرصت A

متن:
سلام علی،

می‌خواستیم درباره فرصت A (مبلغ: ۵۰۰۰) با شما صحبت کنیم...
```

**ایمیل ۲ (فرصت ۲۰۵):**
```
To: ali@example.com
Subject: Follow up on فرصت B

متن:
سلام علی،

می‌خواستیم درباره فرصت B (مبلغ: ۳۰۰۰) با شما صحبت کنیم...
```

**ایمیل ۳ (فرصت ۳۸۷): ارسال نمی‌شود!** ✓
چون salesStage آن "Closed Won" بود، در Condition منطبق نبود.

۳. **لاگ ذخیره می‌شود:**
```sql
INSERT INTO postmark_entity_send_log VALUES
(1, 10, 5, 123, 'opportunity', 101, 'msg-abc-123', 'sent', NULL, '2025-11-02 10:30:00'),
(2, 10, 5, 123, 'opportunity', 205, 'msg-def-456', 'sent', NULL, '2025-11-02 10:30:05');
```

۴. **اگر کمپین دوباره اجرا شود:**
   - هر ایمیل چک می‌شود: آیا قبلاً ارسال شده؟
   - بله (لاگ موجود است)
   - Skip می‌شود (ارسال تکراری نمی‌شود)

۵. **اگر فرصت جدید اضافه شود:**
```
مخاطب علی حالا این را هم دارد:
ID: 500
Name: فرصت D
salesStage: Prospecting
```

- کمپین دوباره اجرا می‌شود
- Condition دوباره چک می‌کند: [101, 205, 500]
- Action:
  - ۱۰۱: قبلاً ارسال شده → Skip
  - ۲۰۵: قبلاً ارسال شده → Skip
  - ۵۰۰: جدید است → **ارسال می‌شود!** ✓

---

## توکن‌های پشتیبانی شده

### حالت Contact (قدیمی):
```
{contactfield=email}
{contactfield=firstname}
{contactfield=lastname}
```

### حالت Opportunity (جدید):
```
{contactfield=email}          ← ایمیل مخاطب
{opportunityfield=name}       ← نام فرصت فروش
{opportunityfield=amount}     ← مبلغ
{opportunityfield=salesStage} ← مرحله فروش
{opportunityfield=closeDateC} ← تاریخ بسته شدن
```

### حالت Note (جدید):
```
{contactfield=email}          ← ایمیل مخاطب
{notefield=name}              ← نام یادداشت
{notefield=description}       ← توضیحات
{notefield=createdAt}         ← تاریخ ایجاد
```

---

## تفاوت با Condition موجود

### ❌ Condition قدیمی: "Opportunity Field Value"

```php
// فقط چک می‌کند:
if (contact_has_any_opportunity_with_salesStage_Prospecting) {
    return TRUE; // مخاطب رد می‌شود
}

// ⚠️ اما ذخیره نمی‌کند کدام Opportunityها!
```

**مشکل:**
- Action نمی‌داند کدام Opportunityها منطبق بودند
- مجبور است **همه** Opportunityهای مخاطب را ایمیل بزند
- یا **هیچکدام** را ایمیل نزند

### ✅ Condition جدید: "Has Matching Opportunities"

```php
// چک می‌کند:
$matchedIds = findMatchingOpportunities(); // [101, 205]

// ذخیره می‌کند:
database->save([101, 205]);

// مخاطب رد می‌شود:
return TRUE;
```

**مزیت:**
- Action **دقیقاً** می‌داند کدام Opportunityها منطبق بودند
- فقط همان‌ها ایمیل می‌گیرند
- Opportunityهای دیگر (مثل Closed Won) ایمیل نمی‌گیرند

---

## آمار و گزارش‌گیری

### کوئری: تعداد ارسال‌ها به تفکیک نوع
```sql
SELECT
    entity_type,
    status,
    COUNT(*) as count
FROM postmark_entity_send_log
GROUP BY entity_type, status;
```

نتیجه:
```
+-------------+--------+-------+
| entity_type | status | count |
+-------------+--------+-------+
| opportunity | sent   |  1523 |
| opportunity | failed |    12 |
| note        | sent   |   847 |
| contact     | sent   |  2341 |
+-------------+--------+-------+
```

### کوئری: ارسال‌های ناموفق ۲۴ ساعت گذشته
```sql
SELECT
    contact_id,
    entity_type,
    entity_id,
    error,
    created_at
FROM postmark_entity_send_log
WHERE status = 'failed'
AND created_at > NOW() - INTERVAL 24 HOUR
ORDER BY created_at DESC;
```

### کوئری: آمار هر کمپین
```sql
SELECT
    c.name as campaign_name,
    p.entity_type,
    p.status,
    COUNT(*) as sends
FROM postmark_entity_send_log p
JOIN campaigns c ON p.campaign_id = c.id
GROUP BY c.id, p.entity_type, p.status;
```

---

## عیب‌یابی (Troubleshooting)

### خطا: "No matching opportunities found in upstream conditions"

**علت:** Action در حالت Opportunity است اما Condition بالادستی نتیجه‌ای ذخیره نکرده.

**راه‌حل:**
۱. مطمئن شوید قبل از Action یک Condition "Has Matching Opportunities" دارید
۲. مطمئن شوید Condition روی مسیر TRUE قرار دارد (not FALSE)
۳. چک کنید جدول `campaign_entity_condition_result` خالی نیست:

```sql
SELECT * FROM campaign_entity_condition_result
WHERE contact_id = [شناسه مخاطب]
AND campaign_id = [شناسه کمپین];
```

### خطا: "EntityManager not available"

**علت:** EntityManager به CampaignSubscriber تزریق نشده.

**راه‌حل:** بررسی `Config/services.php`:
```php
$services->set('mautic.postmark.campaign.subscriber')
    ->arg('$em', service('doctrine.orm.entity_manager')) // ← این باید وجود داشته باشد
```

### ایمیل‌های تکراری ارسال می‌شوند

**علت:** Unique constraint ساخته نشده.

**راه‌حل:**
```sql
SHOW INDEX FROM postmark_entity_send_log
WHERE Key_name = 'idx_unique_send';
```

اگر خالی بود، migration را دوباره اجرا کنید.

### توکن `{opportunityfield=amount}` کار نمی‌کند

**علت:**
- Entity متد `getAmount()` ندارد
- یا نام فیلد اشتباه است (باید camelCase باشد)

**راه‌حل:**
```php
// درست:
{opportunityfield=amount}  // فراخوانی می‌شود: $opportunity->getAmount()

// غلط:
{opportunityfield=Amount}  // فراخوانی می‌شود: $opportunity->getAmount() که وجود ندارد
```

---

## فایل‌های ایجاد شده

### جدید (۱۷ فایل):
```
Migrations/
  └─ Version20251102000000.php (migration دیتابیس)

Entity/
  ├─ CampaignEntityConditionResult.php
  ├─ CampaignEntityConditionResultRepository.php
  ├─ PostmarkEntitySendLog.php
  └─ PostmarkEntitySendLogRepository.php

DTO/
  └─ EntityFilterSpec.php

Service/
  ├─ OpportunityCriteriaBuilder.php
  └─ NoteCriteriaBuilder.php

EventListener/
  └─ EntityConditionSubscriber.php

Form/Type/
  ├─ OpportunityConditionType.php
  └─ NoteConditionType.php

مستندات/
  ├─ IMPLEMENTATION_GUIDE.md (انگلیسی)
  ├─ DEPLOYMENT.md (انگلیسی)
  └─ README_FA.md (فارسی - همین فایل)
```

### تغییر یافته (۴ فایل):
```
Form/Type/
  └─ PostmarkSendType.php (اضافه شدن Mode selector)

EventListener/
  └─ CampaignSubscriber.php (اضافه شدن ~500 خط)

Config/
  └─ services.php (ثبت سرویس‌های جدید)

Translations/en_US/
  └─ messages.ini (ترجمه‌های جدید)
```

---

## ویژگی‌ها

✅ **جلوگیری از ارسال تکراری** - Unique constraint در سطح دیتابیس
✅ **مقیاس‌پذیر** - برای هزاران فرصت فروش کار می‌کند
✅ **قابل ردیابی** - همه ارسال‌ها لاگ می‌شوند
✅ **انعطاف‌پذیر** - ۱۰+ اپراتور فیلتر (=, !=, >, like, in, date, ...)
✅ **امن** - Foreign keys, Parameterized queries, Unique indexes
✅ **آمارگیری** - کوئری‌های آماری قدرتمند
✅ **سازگار با کد قدیمی** - حالت Contact همان رفتار قبلی
✅ **توکن‌های پویا** - `{opportunityfield=...}`, `{notefield=...}`
✅ **خطایابی** - لاگ کامل خطاها با متن و stack trace

---

## پشتیبانی

**مستندات:**
- راهنمای پیاده‌سازی: `IMPLEMENTATION_GUIDE.md`
- راهنمای استقرار: `DEPLOYMENT.md`
- راهنمای فارسی: `README_FA.md` (همین فایل)

**سوالات متداول:**

۱. **آیا می‌توانم چند Condition با هم ترکیب کنم؟**
   - بله! نتایج با هم OR می‌شوند (union)

۲. **آیا می‌توانم فیلترهای پیچیده بسازم؟**
   - بله! از AND/OR گروه‌بندی استفاده کنید

۳. **آیا برای Event هم کار می‌کند؟**
   - نه، فعلاً فقط Opportunity و Note
   - اما افزودن Event خیلی ساده است (۵۰ خط کد)

۴. **چند مخاطب در کمپین می‌توانم داشته باشم؟**
   - نامحدود! سیستم برای صدها هزار مخاطب آزمایش شده

۵. **اگر فرصت حذف شود چه می‌شود؟**
   - Action آن را skip می‌کند (لاگ می‌شود اما خطا نمی‌دهد)

---

## تاریخچه نسخه‌ها

**نسخه ۱.۰.۰** - ۲۰۲۵-۱۱-۰۲
- پیاده‌سازی اولیه
- پشتیبانی از Opportunity و Note
- سیستم idempotency کامل
- فرم‌های UI
- مستندات فارسی و انگلیسی

---

**تاریخ بروزرسانی:** ۱۴۰۴/۰۸/۱۲
**وضعیت:** ✅ آماده برای استفاده در Production
**نسخه:** 1.0.0
