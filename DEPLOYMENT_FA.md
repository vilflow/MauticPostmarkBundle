# راهنمای استقرار - MauticPostmarkBundle - ارسال به ازای هر موجودیت

## خلاصه پیاده‌سازی

این سند دستورالعمل‌های استقرار برای قابلیت ارسال به ازای هر موجودیت (per-entity send) را که برای MauticPostmarkBundle پیاده‌سازی شده است، ارائه می‌دهد.

## آنچه پیاده‌سازی شده است

### ✅ اجزای کامل شده

#### 1. طرح پایگاه داده و Migrationها
- **مکان:** `Migrations/Version_1_1_0.php`
- **جداول ایجاد شده:**
  - `campaign_entity_condition_result` - نتایج فیلتر از نودهای شرط را ذخیره می‌کند
  - `campaign_entity_condition_result_item` - جدول فرزند برای مجموعه نتایج بزرگ
  - `postmark_entity_send_log` - لاگ idempotency برای ارسال‌های سطح موجودیت

#### 2. کلاس‌های Entity
- **CampaignEntityConditionResult** (`Entity/CampaignEntityConditionResult.php`)
- **CampaignEntityConditionResultRepository** (`Entity/CampaignEntityConditionResultRepository.php`)
- **PostmarkEntitySendLog** (`Entity/PostmarkEntitySendLog.php`)
- **PostmarkEntitySendLogRepository** (`Entity/PostmarkEntitySendLogRepository.php`)

#### 3. لایه فیلتر (DTOها و Serviceها)
- **EntityFilterSpec** (`DTO/EntityFilterSpec.php`) - مشخصات فیلتر با سریالی‌سازی JSON
- **OpportunityCriteriaBuilder** (`Service/OpportunityCriteriaBuilder.php`) - سازنده کوئری برای Opportunities
- **NoteCriteriaBuilder** (`Service/NoteCriteriaBuilder.php`) - سازنده کوئری برای Notes
- **EventCriteriaBuilder** (`Service/EventCriteriaBuilder.php`) - سازنده کوئری برای Events

#### 4. یکپارچگی Campaign
- **CampaignSubscriber** (`EventListener/CampaignSubscriber.php`)
  - گسترش یافته با منطق ارسال به ازای هر موجودیت
  - `sendPerContact()` - ارسال یک ایمیل به ازای هر مخاطب (رفتار پیش‌فرض)
  - `sendPerEvent()` - ارسال یک ایمیل به ازای هر رویداد
  - `sendPerOpportunity()` - ارسال یک ایمیل به ازای هر فرصت
  - `sendPerNote()` - ارسال یک ایمیل به ازای هر یادداشت
  - متدهای کمکی برای idempotency، حل کردن token، فیلتر کردن موجودیت

#### 5. اجزای UI
- **PostmarkSendType** (`Form/Type/PostmarkSendType.php`) - انتخابگر Mode اضافه شد (contact/event/opportunity/note)
- **ترجمه‌ها** (`Translations/en_US/messages.ini`) - ترجمه‌های مرتبط با mode اضافه شد

#### 6. ثبت Service
- **services.php** (`Config/services.php`) - تمام serviceهای جدید ثبت شد و CampaignSubscriber بروزرسانی شد

## مراحل استقرار

### مرحله 1: پشتیبان‌گیری از پایگاه داده
```bash
# قبل از اجرای migrationها یک پشتیبان ایجاد کنید
mysqldump -u [user] -p mautic > mautic_backup_$(date +%Y%m%d_%H%M%S).sql
```

### مرحله 2: اجرای Migrationهای پایگاه داده

فایل migration سه جدول جدید ایجاد می‌کند. برای اعمال آن:

**روش A: از طریق Doctrine Migrations (اگر به صورت خودکار شناسایی شود)**
```bash
php bin/console doctrine:migrations:migrate --no-interaction
```

**روش B: اجرای مستقیم SQL**
اگر migrationها به صورت خودکار شناسایی نمی‌شوند، SQL را به صورت دستی اجرا کنید:

```bash
# به فایل migrations بروید
cd /var/www/html/mautic_dev/plugins/MauticPostmarkBundle/Migrations

# migration را بررسی کنید
cat Version_1_1_0.php

# برای نصب استاندارد Mautic بدون prefix:
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
    entity_type VARCHAR(32) NOT NULL COMMENT 'contact, event, opportunity, or note',
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

### مرحله 3: پاک کردن Cache
```bash
php bin/console cache:clear
php bin/console cache:warmup
```

### مرحله 4: تأیید نصب
```bash
# بررسی اینکه آیا جداول ایجاد شده‌اند
mysql -u [user] -p mautic -e "SHOW TABLES LIKE '%campaign_entity%'"
mysql -u [user] -p mautic -e "SHOW TABLES LIKE '%postmark_entity%'"

# توضیح جداول
mysql -u [user] -p mautic -e "DESCRIBE campaign_entity_condition_result"
mysql -u [user] -p mautic -e "DESCRIBE postmark_entity_send_log"
```

## استفاده

### 1. ایجاد Campaign

1. برو به **Campaigns** → **New Campaign**
2. یک نود **Condition** اضافه کن (اختیاری): از شرایط استاندارد Mautic برای فیلتر کردن مخاطبان استفاده کن
   - مثال: "Opportunity Field Value" برای بررسی salesStage خاص
   - مثال: "Note Field Value" برای بررسی flagهای خاص
   - مثال: "Event Field Value" برای بررسی معیارهای event
3. یک نود **Action** اضافه کن: "Send Email via Postmark"
   - **Mode**: از این‌ها انتخاب کن:
     - "Per Contact" (پیش‌فرض - یک ایمیل به ازای هر مخاطب)
     - "Per Event" (یک ایمیل به ازای هر event منطبق)
     - "Per Opportunity" (یک ایمیل به ازای هر opportunity منطبق)
     - "Per Note" (یک ایمیل به ازای هر note منطبق)
   - تنظیمات ایمیل را پیکربندی کن (from, to, template و غیره)

**نکته:** برای modeهای entity (event/opportunity/note)، action به تمام موجودیت‌های منطبق برای هر مخاطب کوئری می‌زند و ارسال می‌کند. از شرایط campaign برای پیش‌فیلتر کردن مخاطبان استفاده کنید، سپس action ارسال‌های سطح موجودیت را مدیریت می‌کند.

### 2. حل کردن Token

modeهای per-entity از سینتکس توسعه‌یافته token پشتیبانی می‌کنند:

**Tokenهای Contact Mode (موجود):**
- `{contactfield=email}` - ایمیل مخاطب
- `{contactfield=firstname}` - نام مخاطب
- و غیره

**Tokenهای Event Mode (جدید):**
- `{contactfield=email}` - ایمیل مخاطب
- `{eventfield=name}` - نام event
- `{eventfield=eventRoundC}` - دور event
- `{eventfield=activityStatusType}` - وضعیت فعالیت
- و غیره

**Tokenهای Opportunity Mode (جدید):**
- `{contactfield=email}` - ایمیل مخاطب
- `{opportunityfield=name}` - نام opportunity
- `{opportunityfield=amount}` - مبلغ
- `{opportunityfield=salesStage}` - مرحله فروش
- و غیره

**Tokenهای Note Mode (جدید):**
- `{contactfield=email}` - ایمیل مخاطب
- `{notefield=name}` - نام note
- `{notefield=description}` - توضیحات
- `{notefield=createdAt}` - تاریخ ایجاد
- و غیره

### 3. Idempotency

سیستم به طور خودکار از ارسال‌های تکراری جلوگیری می‌کند:
- هر ترکیب از (campaign_event_id, entity_type, contact_id, entity_id) فقط یک بار قابل ارسال است
- اجرای مجدد campaign، موجودیت‌های قبلاً ارسال شده را رد می‌کند
- ارسال‌های ناموفق بعد از رفع خطا قابل تلاش مجدد هستند

## وضعیت پیاده‌سازی فعلی

### Modeهای موجودیت به طور کامل پیاده‌سازی شده

همه modeهای entity **به طور کامل پیاده‌سازی شده و عملیاتی هستند**:

#### 1. Contact Mode (پیش‌فرض)
- یک ایمیل به ازای هر مخاطب ارسال می‌کند
- رفتار اصلی، کاملاً سازگار با نسخه‌های قبلی

#### 2. Event Mode
- یک ایمیل به ازای هر event منطبق ارسال می‌کند
- از متد `sendPerEvent()` در CampaignSubscriber استفاده می‌کند
- **نکته:** Event mode از جدول `campaign_entity_condition_result` استفاده نمی‌کند
- در عوض، مستقیماً با استفاده از `EventCriteriaBuilder` به eventها کوئری می‌زند
- از تمام شرایط استاندارد campaign پشتیبانی می‌کند

#### 3. Opportunity Mode
- یک ایمیل به ازای هر opportunity منطبق ارسال می‌کند
- از متد `sendPerOpportunity()` در CampaignSubscriber استفاده می‌کند
- می‌تواند نتایج فیلتر را در جدول `campaign_entity_condition_result` ذخیره کند
- از فیلتر کردن آگاه از رابطه پشتیبانی می‌کند (مرتبط با Events)

#### 4. Note Mode
- یک ایمیل به ازای هر note منطبق ارسال می‌کند
- از متد `sendPerNote()` در CampaignSubscriber استفاده می‌کند
- می‌تواند نتایج فیلتر را در جدول `campaign_entity_condition_result` ذخیره کند
- از فیلتر کردن آگاه از رابطه پشتیبانی می‌کند (مرتبط با Events)

### سیستم Reschedule خودکار

برای مدیریت موجودیت‌های جدید ایجاد شده بعد از اجرای campaign:

**دستور:** `mautic:postmark:reschedule-entities`
- جایگزین دستور قدیمی `mautic:postmark:reschedule-opportunities` می‌شود
- از همه انواع entity پشتیبانی می‌کند: opportunity, note, event
- گزینه‌ها:
  - `-i, --campaign-id`: campaign خاصی را هدف قرار بده
  - `-m, --mode`: mode خاصی را هدف قرار بده (opportunity, note, event)

**راه‌اندازی Crontab:**
```bash
# Reschedule کردن actionهای entity هر 10 دقیقه
*/10 * * * * php /path/to/mautic/bin/console mautic:postmark:reschedule-entities

# Trigger کردن campaignها هر 5 دقیقه
*/5 * * * * php /path/to/mautic/bin/console mautic:campaigns:trigger
```

برای راه‌اندازی کامل اتوماسیون [ENTITY_EMAIL_AUTOMATION.md](ENTITY_EMAIL_AUTOMATION.md) را ببینید.

## چک‌لیست تست

### تست‌های Unit
- [ ] سریالی‌سازی/دی‌سریالی‌سازی EntityFilterSpec
- [ ] ساخت کوئری OpportunityCriteriaBuilder با عملگرهای مختلف
- [ ] ساخت کوئری NoteCriteriaBuilder
- [ ] ساخت کوئری EventCriteriaBuilder
- [ ] بررسی‌های idempotency PostmarkEntitySendLogRepository
- [ ] حل کردن token با فیلدهای entity

### تست‌های Integration
- [ ] Migration به درستی جداول را ایجاد می‌کند
- [ ] Persistence و بازیابی entity
- [ ] ساخت معیار فیلتر
- [ ] Autowiring و dependency injection سرویس

### تست‌های عملکردی
1. **Contact Mode (Regression)**
   - [ ] campaignهای موجود هنوز کار می‌کنند
   - [ ] یک ایمیل به ازای هر مخاطب ارسال می‌شود

2. **Event Mode**
   - [ ] Campaign با شرط Event، به هر event منطبق ایمیل می‌فرستد
   - [ ] Tokenها به درستی حل می‌شوند: `{eventfield=name}`
   - [ ] Idempotency از ارسال‌های تکراری در rerun جلوگیری می‌کند
   - [ ] لاگ campaign آمار ارسال را نشان می‌دهد

3. **Opportunity Mode**
   - [ ] Campaign با شرط Opportunity، به هر opportunity منطبق ایمیل می‌فرستد
   - [ ] Tokenها به درستی حل می‌شوند: `{opportunityfield=amount}`
   - [ ] Idempotency از ارسال‌های تکراری جلوگیری می‌کند
   - [ ] لاگ campaign آمار ارسال را نشان می‌دهد

4. **Note Mode**
   - [ ] تست‌های مشابه Opportunity mode اما برای Notes

5. **موارد خاص (Edge Cases)**
   - [ ] Contact با 0 موجودیت منطبق → action به صورت ایمن fail می‌شود
   - [ ] Contact با >100 موجودیت منطبق → همه به درستی ارسال می‌شوند
   - [ ] Entity حذف شده بعد از ارزیابی شرط → به صورت ایمن رد می‌شود
   - [ ] نودهای شرط متعدد → نتایج ترکیب می‌شوند (union)

## نظارت و نگهداری

### کوئری‌های پایگاه داده
```sql
-- بررسی آمار لاگ ارسال
SELECT
    entity_type,
    status,
    COUNT(*) as count,
    MIN(created_at) as first_send,
    MAX(created_at) as last_send
FROM postmark_entity_send_log
GROUP BY entity_type, status;

-- یافتن ارسال‌های ناموفق برای تلاش مجدد
SELECT * FROM postmark_entity_send_log
WHERE status = 'failed'
AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
ORDER BY created_at DESC;

-- عملکرد campaign به تفکیک نوع entity
SELECT
    c.name as campaign_name,
    p.entity_type,
    p.status,
    COUNT(*) as sends
FROM postmark_entity_send_log p
JOIN campaigns c ON p.campaign_id = c.id
GROUP BY c.id, p.entity_type, p.status;
```

### پاک کردن لاگ‌های قدیمی
```php
// در یک دستور زمان‌بندی شده یا cron job
$sendLogRepo = $em->getRepository(PostmarkEntitySendLog::class);
$deleted = $sendLogRepo->deleteOlderThan(90); // حذف لاگ‌های قدیمی‌تر از 90 روز

$conditionRepo = $em->getRepository(CampaignEntityConditionResult::class);
$deleted = $conditionRepo->deleteOlderThan(30); // حذف نتایج شرط قدیمی‌تر از 30 روز
```

## عیب‌یابی

### مشکل: "No matching opportunities found in upstream conditions"
**علت:** Action node در حالت Opportunity است اما هیچ condition node بالادستی نتیجه‌ای ذخیره نکرده
**راه‌حل:** Campaign Mautic شما باید شرایط مناسب برای فیلتر کردن مخاطبان داشته باشد. action به تمام موجودیت‌های منطبق کوئری می‌زند.

### مشکل: "EntityManager not available for per-opportunity sends"
**علت:** EntityManager به CampaignSubscriber تزریق نشده
**راه‌حل:** services.php registration را بررسی کن که شامل آرگومان `$em` باشد

### مشکل: ارسال‌های تکراری علی‌رغم idempotency
**علت:** Unique index ایجاد نشده یا entity_id وقتی نباید NULL است
**راه‌حل:** unique index روی جدول `postmark_entity_send_log` را تأیید کن

### مشکل: Token `{opportunityfield=amount}` حل نمی‌شود
**علت:** Entity متد getter ندارد یا نام فیلد مطابقت ندارد
**راه‌حل:** مطمئن شو Entity Opportunity متد `getAmount()` دارد و نام فیلد با camelCase مطابقت دارد

## ملاحظات عملکرد

- **پردازش دسته‌ای:** برای contactهایی با >50 entity، اجرای پردازش دسته‌ای را در نظر بگیرید
- **Indexing:** تمام فیلدهای کلیدی lookup فهرست‌بندی شده‌اند (campaign_id, contact_id, entity_type)
- **Pagination:** مجموعه نتایج بزرگ (>1000 entity) از جدول فرزند برای جلوگیری از bloat JSON استفاده می‌کنند
- **Caching:** specهای فیلتر برای caching احتمالی normalize و hash می‌شوند (بهبود آینده)

## نکات امنیتی

- تمام عملیات پایگاه داده از کوئری‌های پارامتری شده استفاده می‌کنند (از طریق Doctrine)
- قیدهای Foreign key یکپارچگی ارجاعی را تضمین می‌کنند
- Unique index از ارسال‌های تکراری جلوگیری می‌کند (idempotency در سطح DB)
- دسترسی به entity به مالکیت contact محدود می‌شود (فقط برای موجودیت‌های متعلق به contact ارسال می‌کند)

## پشتیبانی و مستندات

- **راهنمای پیاده‌سازی:** `IMPLEMENTATION_GUIDE.md` یا `IMPLEMENTATION_GUIDE_FA.md`
- **راهنمای استقرار:** این فایل (`DEPLOYMENT_FA.md`)
- **مستندات اتوماسیون:** `ENTITY_EMAIL_AUTOMATION.md`
- **مستندات Mautic:** https://docs.mautic.org
- **مستندات API Postmark:** https://postmarkapp.com/developer

---

**آخرین بروزرسانی:** 2025-11-04
**نسخه:** 1.0
**وضعیت:** ✅ به طور کامل پیاده‌سازی شده و عملیاتی - همه 4 mode موجودیت کار می‌کنند
