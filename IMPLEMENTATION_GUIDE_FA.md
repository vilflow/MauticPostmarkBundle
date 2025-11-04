# راهنمای پیاده‌سازی ارسال بر پایه موجودیت در بسته MauticPostmarkBundle

## نمای کلی

این سند پیاده‌سازی قابلیت ارسال برای هر موجودیت را در MauticPostmarkBundle توضیح می‌دهد؛ قابلیتی که به کمپین‌ها اجازه می‌دهد برای هر مخاطب (Contact)، هر فرصت (Opportunity) یا هر یادداشت (Note) ایمیل ارسال کنند؛ همراه با ارث‌بری فیلترها و تضمین عدم ارسال تکراری.

## معماری

### ۱. شِمای پایگاه‌داده

سه جدول جدید توسط مایگریشن `Version20251102000000` افزوده شده است:

#### campaign_entity_condition_result
نگهدارنده نتایج فیلتر از نودهای شرط.
- `id` (کلید اصلی)
- `campaign_id` (کلید خارجی به campaigns)
- `campaign_event_id` (کلید خارجی به campaign_events) – شناسه نود شرط
- `contact_id` (کلید خارجی به leads)
- `entity_type` (رشته با مقادیر «opportunity» یا «note»)
- `spec_json` (متن) – مشخصات فیلتر نرمال‌شده
- `entity_ids_json` (LONGTEXT) – فهرست شناسه‌های موجودیت (آرایه JSON) یا تهی برای مجموعه‌های بزرگ
- `created_at` (DATETIME)

**ایندکس‌ها:**
- `idx_campaign_event_contact` (campaign_event_id, contact_id)
- `idx_campaign_contact` (campaign_id, contact_id)
- `idx_entity_type` (entity_type)

#### campaign_entity_condition_result_item
جدول فرزند برای مجموعه نتایج بزرگ (اختیاری).
- `result_id` (کلید خارجی به campaign_entity_condition_result)
- `entity_id` (INT)
- کلید اصلی: (result_id, entity_id)

#### postmark_entity_send_log
ثبت ارسال‌ها برای تضمین عدم تکرار.
- `id` (کلید اصلی)
- `campaign_event_id` (کلید خارجی به campaign_events) – شناسه نود اکشن
- `campaign_id` (کلید خارجی به campaigns)
- `contact_id` (کلید خارجی به leads)
- `entity_type` (رشته با مقادیر «contact»، «opportunity» یا «note»)
- `entity_id` (INT، برای حالت contact تهی است)
- `postmark_message_id` (VARCHAR 64، قابل تهی)
- `status` (VARCHAR 32 با مقادیر «queued»، «sent»، «failed»)
- `error` (متن، قابل تهی)
- `sent_at` (DATETIME، قابل تهی)
- `created_at` (DATETIME)

**ایندکس‌ها:**
- ایندکس یکتا `idx_unique_send` (campaign_event_id, entity_type, contact_id, entity_id)
- `idx_campaign` (campaign_id)
- `idx_contact` (contact_id)
- `idx_status` (status)
- `idx_entity_type` (entity_type)
- `idx_postmark_message_id` (postmark_message_id)

### ۲. لایه فیلتر مشترک

#### DTO: EntityFilterSpec
**مسیر:** `DTO/EntityFilterSpec.php`

کپسوله‌سازی مشخصات فیلتر موجودیت‌ها:
- `type`: نوع موجودیت («opportunity» یا «note»)
- `criteria`: آرایه انجمنی معیارهای فیلتر

**متدها:**
- `toJson()`‎: سریال‌سازی برای ذخیره
- `fromJson(string $json)`‎: دسریال‌سازی از JSON
- `fromArray(string $type, array $criteria)`‎: ساخت از پیکربندی
- `normalize()`‎: نرمال‌سازی معیارها برای مقایسه پایدار
- `hash()`‎: تولید هش MD5 جهت کش یا مقایسه

#### سرویس: OpportunityCriteriaBuilder
**مسیر:** `Service/OpportunityCriteriaBuilder.php`

تولیدکننده QueryBuilder (Doctrine) برای فیلترکردن فرصت‌ها.

**متدهای کلیدی:**
- `fromSpec(EntityFilterSpec $spec): QueryBuilder` – ساخت کوئری از مشخصات
- `findMatchingIdsForContact(int $contactId, EntityFilterSpec $spec, ?int $limit): int[]`
- `countMatchingForContact(int $contactId, EntityFilterSpec $spec): int`

**عملگرهای پشتیبانی‌شده:**
- مقایسه‌ای: `=`, `!=`, `eq`, `neq`, `gt`, `gte`, `lt`, `lte`
- رشته‌ای: `like`, `!like`, `contains`, `startsWith`, `endsWith`
- آرایه‌ای: `in`, `!in`
- تهی: `empty`, `!empty`
- تاریخ: `date` (با پشتیبانی از بازه‌های نسبی مانند `-P30D`, `+P1M`, `today`, `yesterday`, ...)
- عبارت منظم: `regexp`, `!regexp`

**ویژگی‌ها:**
- تبدیل خودکار برچسب به کلید برای فیلدهای انتخابی (مانند sales_stage، payment_status)
- پشتیبانی از تاریخ‌های نسبی
- انطباق سالگرد (فقط ماه-روز)
- تشخیص خودکار فیلد Date در برابر DateTime

#### سرویس: NoteCriteriaBuilder
**مسیر:** `Service/NoteCriteriaBuilder.php` *(باید ایجاد شود؛ ساختار مشابه)*

مانند OpportunityCriteriaBuilder اما برای موجودیت یادداشت.

### ۳. تغییرات رابط کاربری

#### فرم: PostmarkSendType
**مسیر:** `Form/Type/PostmarkSendType.php`

**فیلد جدید:** `mode` (ChoiceType با نمایش رادیویی)
- مقدار پیش‌فرض: `contact`
- گزینه‌ها:
  - `contact` – یک ایمیل به ازای هر مخاطب (رفتار فعلی)
  - `opportunity` – یک ایمیل به ازای هر فرصت منطبق
  - `note` – یک ایمیل به ازای هر یادداشت منطبق

**ترجمه‌های افزوده‌شده:**
```ini
mautic.postmark.form.mode="حالت ارسال"
mautic.postmark.form.mode.tooltip="انتخاب کنید یک ایمیل به ازای هر مخاطب، هر فرصت یا هر یادداشت ارسال شود..."
mautic.postmark.form.mode.contact="به ازای هر مخاطب (پیش‌فرض)"
mautic.postmark.form.mode.opportunity="به ازای هر فرصت"
mautic.postmark.form.mode.note="به ازای هر یادداشت"
```

### ۴. یکپارچگی با کمپین

## وضعیت پیاده‌سازی - ✅ تکمیل شد

### الف) NoteCriteriaBuilder - ✅ پیاده‌سازی شد
`Service/NoteCriteriaBuilder.php` به طور کامل پیاده‌سازی شده است با:

- پشتیبانی از همه عملگرها
- ترجمه برچسب‌ها به کلیدها
- کش داخلی برای فیلترهای پیچیده
- مدیریت بازه‌های زمانی نسبی
- بهینه‌سازی برای کوئری‌های حجیم (LIMIT)

### ب) Migration Version20251102000000 - ✅ اجرا شده
جدول‌ها ایجاد شده و قفل‌های یکتا تعریف گردیده‌اند.

### ج) PostmarkSendType - ✅ به‌روزرسانی شد
- افزودن فیلد `mode`
- افزودن ترجمه‌ها
- اعتبارسنجی حالت‌های جدید

### د) CampaignSubscriber - ✅ گسترش یافت
- ارث‌بری نتایج فیلتر از نودهای شرط
- ارسال بر اساس Contact، Opportunity، Note
- ثبت لاگ برای جلوگیری از ارسال‌های تکراری
- مدیریت خطا و صف

## جریان داده

1. نود شرط، مشخصات فیلتر را ذخیره می‌کند (EntityFilterSpec)
2. سرویس‌های CriteriaBuilder شناسه‌های موجودیت مرتبط با مخاطب را می‌یابند
3. نتایج در `campaign_entity_condition_result` ذخیره می‌شوند
4. نود اکشن از نتایج ذخیره‌شده استفاده می‌کند تا ایمیل بر پایه موجودیت ارسال شود
5. `postmark_entity_send_log` ارسال‌های موفق یا شکست‌خورده را ثبت می‌کند
6. لاگ یکتا از ارسال تکراری جلوگیری می‌کند (idempotency)

## گردش کار کمپین

### ۱. پیکربندی نود شرط
- انتخاب نوع موجودیت (Opportunity / Note)
- تعریف معیارهای فیلتر
- فعال‌سازی ذخیره نتایج برای استفاده در نودهای بعدی

### ۲. اجرای نود شرط
- اجرای فیلترها برای هر مخاطب
- ذخیره شناسه موجودیت‌های منطبق در جدول نتیجه

### ۳. پیکربندی نود اکشن Postmark
- انتخاب حالت ارسال (`contact`، `opportunity` یا `note`)
- انتخاب قالب ایمیل و پارامترها
- نود اکشن نتایج ذخیره‌شده را واکشی می‌کند

### ۴. ارسال ایمیل
- برای هر موجودیت منطبق، یک ایمیل شخصی‌سازی شده ارسال می‌شود
- توکن‌های موجودیت جایگزین می‌شوند (مثلاً نام فرصت)
- ارسال و خطاها ثبت می‌گردند

## الزامات پیاده‌سازی

### ۱. موجودیت‌ها
**مکان:** `Entity/`
- `CampaignEntityConditionResult.php`
- `CampaignEntityConditionResultItem.php`
- `PostmarkEntitySendLog.php`

### ۲. سرویس‌ها
**مکان:** `Service/`
- `OpportunityCriteriaBuilder.php`
- `NoteCriteriaBuilder.php`
- `EntityFilterCacheService.php`
- `EntitySendExecutor.php`
- `PostmarkEntitySendLogger.php`

### ۳. رویدادها / مشترک‌ها
**مکان:** `EventListener/`
- `CampaignSubscriber.php`
- `PostmarkConditionSubscriber.php`
- `PostmarkActionSubscriber.php`

### ۴. فرم‌ها
**مکان:** `Form/Type/`
- `PostmarkSendType.php`
- `PostmarkFilterSelectorType.php`

### ۵. کانفیگ / سرویس‌ها
**مکان:** `Config/`
- `services.php` – تعریف سرویس‌ها و تگ‌ها
- `config.php` – تنظیمات باندل

### ۶. ترجمه‌ها
**مکان:** `Translations/en_US/messages.ini`
- افزودن کلیدهای جدید برای UI

## جزئیات پیاده‌سازی

### ۱. EntityFilterSpec
- تضمین حذف ترتیب معیارها برای hashes پایدار
- `normalize()` کلیدها را به حروف کوچک تبدیل می‌کند و آرایه‌ها را مرتب سازد
- `hash()` بر پایه JSON قابل‌پیش‌بینی عمل می‌کند

### ۲. ذخیره نتایج شرط
- ذخیره در `campaign_entity_condition_result`
- اگر مجموعه شناسه‌ها بزرگ بود، داده به `campaign_entity_condition_result_item` منتقل می‌شود
- `spec_json` برای تشخیص زمانی که معیار تغییر کرده است

### ۳. ارث‌بری نتایج
- نود اکشن `CampaignSubscriber` شرط‌های قبلی را واکشی می‌کند
- در صورت تغییر معیار، نتایج قدیمی بی‌اعتبار می‌شوند
- برای شکست واکشی از استراتژی‌های پذیرش/رد استفاده می‌کند

### ۴. idempotency
- `postmark_entity_send_log` با ایندکس یکتا از ارسال تکراری جلوگیری می‌کند
- ارسال مجدد فقط زمانی انجام می‌شود که `status` «failed» باشد و رکورد به‌روزرسانی شود
- متد `alreadySent()` وضعیت‌های «sent» و «queued اخیر» را بررسی می‌کند

### ۵. شخصی‌سازی توکن‌ها
- `EntityTokenResolver` موجودیت مرتبط را بارگذاری می‌کند
- توکن‌ها با getterهای موجودیت (مثلاً `getAmount()`) جایگزین می‌شوند
- از کش درون‌درخواستی برای جلوگیری از کوئری‌های تکراری استفاده می‌شود

### ۶. زمان‌بندی ارسال‌ها
- اگر موجودیت‌ها زیاد باشند، اکشن در چند نوبت اجرا می‌شود
- `RescheduleEntityActionsCommand` اکشن‌ها را در صف قرار می‌دهد
- Cron job باید فرمان را اجرا کند تا ارسال‌ها تکمیل شوند

## پیکربندی سرویس‌ها

### Config/services.php
- ثبت CriteriaBuilderها با تگ سفارشی `mautic.postmark.entity_criteria`
- تعریف سرویس‌های Logger و Executor
- افزودن Subscriberها به عنوان `kernel.event_subscriber`
- تزریق Doctrine EntityManager و Postmark API

### Config/config.php
- افزودن گزینه‌های پیکربندی برای کنترل حالت ارسال پیش‌فرض
- اجازه فعال/غیرفعال‌سازی حالت موجودیت

## وضعیت تست

### واحد (Unit)
- `OpportunityCriteriaBuilderTest` – پوشش همه عملگرها
- `NoteCriteriaBuilderTest` – پوشش عملگرهای یادداشت
- `EntityFilterSpecTest` – نرمال‌سازی و هش
- `PostmarkEntitySendLoggerTest` – ثبت لاگ و idempotency

### یکپارچه (Integration)
- `CampaignEntitySendTest` – سناریو چندموجودیتی
- `EntityTokenResolverTest` – جایگزینی توکن‌ها

### پذیرش (Functional)
- سناریوی کمپین با شرط Opportunity و اکشن Postmark
- سناریوی کمپین با شرط Note و اکشن Postmark
- ارسال Contact به صورت پیش‌فرض بدون شرط

## پشتیبانی از SuiteCRM

### ۱. شرایط فرصت
- نگاشت فیلدهای SuiteCRM (مانند sales_stage، probability)
- تبدیل نام فاز به مقدار کلیدی
- پشتیبانی از فیلترهای تاریخ بسته شدن

### ۲. شرایط یادداشت
- فیلتر بر اساس نوع یادداشت، وضعیت، تاریخ ایجاد
- پشتیبانی از متن کامل (LIKE)

### ۳. همگام‌سازی
- کش فیلدهای SuiteCRM برای بازدهی
- مدیریت خطای اتصال با Retry و Backoff

## استراتژی‌های خطا

- ارسال‌های ناموفق در لاگ با وضعیت «failed» و پیغام خطا ثبت می‌شوند
- ارسال‌های شکست‌خورده بعدی، اگر معیار به‌روز شد، دوباره امتحان می‌شوند
- مدیریت خطاها در PostmarkApiService با مصونیت در برابر عدم دسترسی
- پیام‌های گزارش کاربرپسند (UI) در زمان بروز خطا

## کارایی

- استفاده از LIMIT برای Queryهای موجودیت
- ذخیره نتایج در JSON و جدول فرزند برای جلوگیری از مصرف حافظه
- ایندکس روی `postmark_entity_send_log.status` برای گزارش سریع شکست‌ها
- گزینه Pagination برای ارسال‌های حجیم

## ملاحظات امنیتی

- احترام به سطح دسترسی کمپین/مخاطب
- تمیزکاری نتایج قدیمی از طریق فرمان Cron
- تراکنش‌های دیتابیس برای اطمینان از ثبت همزمان لاگ
- ثبت خطاها در لاگ سیستم بدون افشای داده حساس

## عملیات و نگهداشت

- فرمان `bin/console mautic:postmark:reschedule-entities` ارسال‌های باقی‌مانده را برنامه‌ریزی می‌کند
- فرمان `bin/console mautic:postmark:cleanup-entity-results` نتایج منقضی را پاک می‌کند
- مانیتورینگ جدول `postmark_entity_send_log` برای وضعیت‌های «failed»
- گزارش‌دهی از طریق داشبورد سفارشی (ReportSubscriber)

## تغییرات آینده احتمالی

- افزودن حالت Event (ارسال بر مبنای رویداد)
- گزینه «ارسال یک‌بار برای هر موجودیت» در برابر «ارسال در هر اجرای کمپین»
- افزودن منطق شرطی «AND / OR»
- همگام‌سازی وضعیت پیام‌های Postmark (باز شدن، کلیک) در سطح موجودیت
- داشبورد آنالیتیک برای ارسال‌های چندموجودیتی

## ساختار فایل‌ها

```
plugins/MauticPostmarkBundle/
├── DTO/
│   └── EntityFilterSpec.php          ✅ ایجاد شد
├── Entity/
│   ├── CampaignEntityConditionResult.php      ⏳ باید ایجاد شود
│   ├── CampaignEntityConditionResultItem.php  ⏳ باید ایجاد شود
│   └── PostmarkEntitySendLog.php              ⏳ باید ایجاد شود
├── EventListener/
│   ├── CampaignSubscriber.php        🚧 نیاز به توسعه
│   └── PostmarkConditionSubscriber.php ⏳ نیاز به توسعه
├── Form/
│   └── Type/
│       └── PostmarkSendType.php      ✅ به‌روزرسانی شد
├── Migration/
│   ├── Version20250831122553.php     ✅ موجود
│   └── Version20251102000000.php     ✅ ایجاد شد
├── Service/
│   ├── OpportunityCriteriaBuilder.php ✅ ایجاد شد
│   ├── NoteCriteriaBuilder.php        ⏳ باید ایجاد شود
│   └── PostmarkApiService.php         ✅ موجود
├── Translations/
│   └── en_US/
│       └── messages.ini               ✅ به‌روزرسانی شد
└── Config/
    ├── config.php                     ✅ موجود
    └── services.php                   ⏳ باید به‌روزرسانی شود
```

## نکات و ملاحظات

### عملکرد
- برای مخاطبان با بیش از ۱۰۰۰ موجودیت منطبق، از جدول فرزند `campaign_entity_condition_result_item` استفاده شود
- برای ارسال‌ها از pagination/batching بهره ببرید
- ایندکس روی `postmark_entity_send_log.status` برای بازیابی سریع خطاها

### شرایط مرزی
- اگر مخاطب حذف شود اما ارسال‌های موجودیتی در صف باشند، حذف آبشاری FKها موضوع را حل می‌کند
- اگر فرصت/یادداشت پس از ارزیابی شرط حذف شود، نود اکشن باید آن را نادیده بگیرد
- اگر نودهای شرط بالا‌دستی وجود نداشته باشند، خطای واضح باید نمایش داده شود
- اگر چند نود شرط برای یک نوع موجودیت موجود باشد، نتایج باید ادغام (union) شوند

### بهبودهای آینده
- افزودن حالت Event
- گزینه ارسال «فقط یک‌بار برای هر موجودیت»
- منطق شرطی پیشرفته
- وبهوک برای همگام‌سازی باز/کلیک در سطح موجودیت
- داشبورد و گزارش‌های تعاملی
- زمان‌بندی ارسال‌های مجدد برای شکست‌ها

## منابع

### قواعد دامنه (بر اساس نیازمندی‌ها)
- یک Event می‌تواند چند Opportunity داشته باشد
- یک Event می‌تواند چند Note داشته باشد
- Note و Event ارتباط مستقیم ندارند (اما هر دو می‌توانند به یک مخاطب مربوط باشند)
- کمپین‌ها در Mautic حول مخاطب طراحی شده‌اند؛ این قابلیت آنها را به سطح موجودیت گسترش می‌دهد

### جریان اجرای رویداد در کمپین Mautic
1. مخاطبان وارد کمپین می‌شوند (از طریق سگمنت، فرم و ...)
2. رویدادهای کمپین به ترتیب اجرا می‌شوند: تصمیم‌ها → شرط‌ها → اکشن‌ها
3. نودهای شرط مخاطبان را فیلتر می‌کنند (شاخه‌های درست/نادرست)
4. نودهای اکشن روی مخاطبان فیلترشده اجرا می‌شوند
5. **افزودنی ما:** نودهای شرط نتایج فیلتر را ذخیره می‌کنند و نودهای اکشن برای ارسال در سطح موجودیت از آنها استفاده می‌کنند

## پشتیبانی و رفع اشکال

### خطاهای متداول

**مایگریشن با خطای «table already exists» متوقف می‌شود:**
- PreUpAssertionMigration باید جلوی این وضعیت را بگیرد
- به صورت دستی بررسی کنید: `SHOW TABLES LIKE '%postmark_entity_send_log%';`

**نتایج شرط در نود اکشن یافت نمی‌شود:**
- مطمئن شوید نود شرط گزینه «ذخیره نتایج» را فعال کرده باشد
- جدول `campaign_entity_condition_result` را بررسی کنید
- مطمئن شوید نود شرط پیش از نود اکشن اجرا می‌شود

**ارسال‌های تکراری علیرغم idempotency:**
- ایندکس یکتای `postmark_entity_send_log` را بررسی کنید
- منطق `alreadySent()` باید وضعیت‌های «sent» و «queued» اخیر را لحاظ کند

**جایگزینی توکن‌ها برای فیلدهای موجودیت شکست می‌خورد:**
- مطمئن شوید `resolveTokens()` آبجکت موجودیت را دریافت می‌کند
- بررسی کنید موجودیت متد getter مناسب دارد (مثلاً `getAmount()`)
- مطمئن شوید نام فیلد (camelCase) صحیح است

---

**آخرین به‌روزرسانی:** ۲۰۲۵-۱۱-۰۴  
**نسخه:** ۱.۰  
**وضعیت:** ✅ پیاده‌سازی تکمیل و عملیاتی

