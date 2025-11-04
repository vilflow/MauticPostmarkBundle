# ارسال خودکار ایمیل برای موجودیت‌های جدید (Opportunity، Note، Event)

## مسئله
در حالت‌های ارسال «به ازای هر فرصت»، «به ازای هر یادداشت» یا «به ازای هر رویداد»، کمپین فقط برای موجودیت‌های موجود که با شرایط مطابقت دارند ایمیل می‌فرستد. اما موجودیت‌های جدیدی که پس از اجرای کمپین ایجاد می‌شوند، به صورت خودکار ایمیل دریافت نمی‌کنند.

## ریشه مشکل
کلاس `OpportunityLifecycleSubscriber` برای صف‌بندی خودکار اجرای کمپین هنگام ایجاد موجودیت‌ها طراحی شده است، اما به رویدادهای چرخه عمر Doctrine متکی است؛ رویدادهایی که هنگام ایجاد موجودیت از طریق رابط کاربری Mautic فعال نمی‌شوند.

## راهکار
فرمان جدید `mautic:postmark:reschedule-entities` پیاده‌سازی شده است که اکشن‌های Postmark در حالت موجودیت را باززمان‌بندی می‌کند تا در اجرای بعدی کمپین وجود موجودیت‌های جدید را بررسی کنند.

## سازوکار

### ۱. فرمان باززمان‌بندی
فرمان `/var/www/html/mautic_dev/plugins/MauticPostmarkBundle/Command/RescheduleEntityActionsCommand.php`:
- همه اکشن‌های Postmark که در حالت «opportunity»، «note» یا «event» پیکربندی شده‌اند را پیدا می‌کند
- مخاطبانی را که پیش‌تر اکشن را اجرا کرده‌اند باززمان‌بندی می‌کند تا دوباره ارزیابی شوند
- موجودیت‌های جدیدی را که با شرایط کمپین مطابقت دارند بررسی می‌کند

### ۲. تریگر کمپین
هنگامی که `mautic:campaigns:trigger` اجرا می‌شود:
- تمام رویدادهای زمان‌بندی‌شده را پردازش می‌کند
- برای اکشن‌های حالت موجودیت، تمامی موجودیت‌ها (فرصت‌ها/یادداشت‌ها/رویدادها) را برای هر مخاطب بررسی می‌کند
- فقط برای موجودیت‌هایی ایمیل ارسال می‌کند که تاکنون ایمیلی دریافت نکرده‌اند (کنترل idempotency)
- همه ارسال‌ها را لاگ می‌کند تا از تکرار جلوگیری شود

## دستورالعمل راه‌اندازی

### افزودن به کران‌تب
فرمان باززمان‌بندی را هر ۵ تا ۱۵ دقیقه در کران‌تب اجرا کنید:

```bash
# ویرایش کران‌تب
crontab -e

# اجرای هر ۱۰ دقیقه برای همه انواع موجودیت
*/10 * * * * php /var/www/html/mautic_dev/bin/console mautic:postmark:reschedule-entities

# یا اجرا فقط برای یک کمپین مشخص
*/10 * * * * php /var/www/html/mautic_dev/bin/console mautic:postmark:reschedule-entities -i 36

# یا اجرا فقط برای یک حالت موجودیت (opportunity، note یا event)
*/10 * * * * php /var/www/html/mautic_dev/bin/console mautic:postmark:reschedule-entities -m opportunity
```

### اطمینان از اجرای تریگر کمپین
مطمئن شوید کران مربوط به تریگر کمپین هم در حال اجراست:

```bash
# اگر هنوز اضافه نشده است
*/5 * * * * php /var/www/html/mautic_dev/bin/console mautic:campaigns:trigger
```

## نحوه استفاده

### تست دستی
می‌توانید این جریان را به صورت دستی اجرا کنید:

```bash
# گام ۱: باززمان‌بندی اکشن‌های حالت موجودیت
php /var/www/html/mautic_dev/bin/console mautic:postmark:reschedule-entities

# گام ۲: اجرای کمپین
php /var/www/html/mautic_dev/bin/console mautic:campaigns:trigger
```

### برای کمپین مشخص
```bash
# فقط کمپین ۳۶ را باززمان‌بندی کن
php /var/www/html/mautic_dev/bin/console mautic:postmark:reschedule-entities -i 36

# اجرای فقط کمپین ۳۶
php /var/www/html/mautic_dev/bin/console mautic:campaigns:trigger -i 36
```

### برای حالت موجودیت مشخص
```bash
# باززمان‌بندی فقط اکشن‌های حالت opportunity
php /var/www/html/mautic_dev/bin/console mautic:postmark:reschedule-entities -m opportunity

# باززمان‌بندی فقط اکشن‌های حالت note
php /var/www/html/mautic_dev/bin/console mautic:postmark:reschedule-entities -m note

# باززمان‌بندی فقط اکشن‌های حالت event
php /var/www/html/mautic_dev/bin/console mautic:postmark:reschedule-entities -m event
```

## نمونه جریان کاری

### برای فرصت‌ها
1. **ساعت ۱۰:۰۰** – کمپین اجرا می‌شود و برای فرصت‌های ۱، ۲، ۳ ایمیل می‌فرستد  
2. **ساعت ۱۰:۳۰** – فرصت جدید شماره ۴ اضافه می‌شود  
3. **ساعت ۱۰:۴۰** – فرمان باززمان‌بندی (از کران) اجرا شده و مخاطبان را برای ارزیابی مجدد علامت می‌زند  
4. **ساعت ۱۰:۴۵** – تریگر کمپین (از کران) اجرا شده و ایمیل فرصت ۴ را ارسال می‌کند

### برای یادداشت‌ها
1. **ساعت ۱۰:۰۰** – کمپین اجرا می‌شود و برای یادداشت‌های ۱ و ۲ ایمیل می‌فرستد  
2. **ساعت ۱۰:۳۰** – یادداشت جدید شماره ۳ اضافه می‌شود  
3. **ساعت ۱۰:۴۰** – فرمان باززمان‌بندی اجرا می‌شود و مخاطبان علامت می‌خورند  
4. **ساعت ۱۰:۴۵** – تریگر کمپین اجرا می‌شود و ایمیل یادداشت ۳ را ارسال می‌کند

### برای رویدادها
1. **ساعت ۱۰:۰۰** – کمپین اجرا می‌شود و برای رویدادهای ۱ و ۲ ایمیل می‌فرستد  
2. **ساعت ۱۰:۳۰** – رویداد جدید شماره ۳ اضافه می‌شود  
3. **ساعت ۱۰:۴۰** – فرمان باززمان‌بندی اجرا می‌شود و مخاطبان را علامت می‌زند  
4. **ساعت ۱۰:۴۵** – تریگر کمپین اجرا می‌شود و ایمیل رویداد ۳ را ارسال می‌کند

## مانیتورینگ

### بررسی فعالیت باززمان‌بندی
```bash
# مشاهده لاگ برای همه انواع موجودیت
tail -f /var/www/html/mautic_dev/var/logs/mautic_prod.log | grep "Rescheduled entity-mode"
```

### بررسی لاگ ارسال بر اساس نوع موجودیت
```bash
# گزارش‌گیری بر اساس نوع موجودیت
php bin/console doctrine:query:sql "SELECT entity_type, COUNT(*) as count, MAX(created_at) as latest_send FROM postmark_entity_send_log WHERE campaign_id = 36 GROUP BY entity_type"
```

### بررسی ارسال‌های اخیر برای نوع موجودیت مشخص
```bash
# برای فرصت‌ها
php bin/console doctrine:query:sql "SELECT * FROM postmark_entity_send_log WHERE entity_type = 'opportunity' ORDER BY created_at DESC LIMIT 10"

# برای یادداشت‌ها
php bin/console doctrine:query:sql "SELECT * FROM postmark_entity_send_log WHERE entity_type = 'note' ORDER BY created_at DESC LIMIT 10"

# برای رویدادها
php bin/console doctrine:query:sql "SELECT * FROM postmark_entity_send_log WHERE entity_type = 'event' ORDER BY created_at DESC LIMIT 10"
```

### بررسی لاگ کمپین
```bash
# مشاهده اجرای کمپین برای اکشن مشخص
php bin/console doctrine:query:sql "SELECT id, lead_id, event_id, date_triggered, is_scheduled FROM campaign_lead_event_log WHERE event_id = 464 ORDER BY id DESC LIMIT 10"
```

## گزینه‌های فرمان

### مرجع کامل فرمان
```bash
php bin/console mautic:postmark:reschedule-entities [options]

Options:
  -i, --campaign-id=CAMPAIGN-ID   باززمان‌بندی فقط برای آی‌دی کمپین مشخص
  -m, --mode=MODE                 باززمان‌بندی فقط برای حالت مشخص
                                  (opportunity، note یا event)
  -h, --help                      نمایش راهنما
```

### نمونه‌ها
```bash
# باززمان‌بندی همه اکشن‌های حالت موجودیت در همه کمپین‌ها
php bin/console mautic:postmark:reschedule-entities

# باززمان‌بندی فقط کمپین ۳۶
php bin/console mautic:postmark:reschedule-entities -i 36

# باززمان‌بندی فقط اکشن‌های حالت opportunity
php bin/console mautic:postmark:reschedule-entities -m opportunity

# باززمان‌بندی اکشن‌های حالت opportunity فقط در کمپین ۳۶
php bin/console mautic:postmark:reschedule-entities -i 36 -m opportunity
```

## ملاحظات کارایی

- فرمان باززمان‌بندی سبک است و فقط رکوردهای دیتابیس را به‌روزرسانی می‌کند
- فقط مخاطبانی را باززمان‌بندی می‌کند که حداقل یک بار اکشن را اجرا کرده‌اند
- کنترل idempotency مانع ارسال ایمیل تکراری می‌شود
- فرکانس پیشنهادی: هر ۵ تا ۱۵ دقیقه بسته به سرعت نیاز به پردازش موجودیت‌های جدید
- اگر برای هر نوع موجودیت فرکانس متفاوت می‌خواهید، از گزینه `-m` استفاده کنید

## رفع اشکال

### موجودیت‌های جدید ایمیل دریافت نمی‌کنند؟

1. **بررسی اجرای فرمان باززمان‌بندی:**
   ```bash
   php bin/console mautic:postmark:reschedule-entities -i 36
   ```
   خروجی باید شبیه «Rescheduled X contact(s)» باشد.

2. **بررسی اجرای تریگر کمپین:**
   ```bash
   php bin/console mautic:campaigns:trigger -i 36
   ```
   خروجی باید شبیه «X total events were executed» باشد.

3. **تطبیق موجودیت با شرایط:**  
   - فیلدهای شرطی کمپین را بررسی کنید  
   - مطمئن شوید مقدار فیلد موجودیت جدید مطابق شرط است

4. **بررسی لاگ ارسال برای تکرار:**  
   ```bash
   # برای opportunity
   php bin/console doctrine:query:sql "SELECT * FROM postmark_entity_send_log WHERE entity_type = 'opportunity' AND entity_id = YOUR_ENTITY_ID"

   # برای note
   php bin/console doctrine:query:sql "SELECT * FROM postmark_entity_send_log WHERE entity_type = 'note' AND entity_id = YOUR_ENTITY_ID"

   # برای event
   php bin/console doctrine:query:sql "SELECT * FROM postmark_entity_send_log WHERE entity_type = 'event' AND entity_id = YOUR_ENTITY_ID"
   ```

### ایمیل‌ها چندبار ارسال می‌شوند؟

این مسئله نباید رخ دهد؛ در صورت وقوع:
- جدول `postmark_entity_send_log` را برای رکوردهای تکراری بررسی کنید
- منطق `alreadySent()` در `CampaignSubscriber.php:924` را بازبینی کنید

### فرمان پیام «No Postmark actions found» نمایش می‌دهد؟

یعنی اکشنی در حالت موجودیت پیکربندی نشده است. بررسی کنید:
```bash
# بررسی حالت اکشن در دیتابیس
php bin/console doctrine:query:sql "SELECT id, name, type, properties FROM campaign_events WHERE campaign_id = YOUR_CAMPAIGN_ID AND type = 'postmark.send'"
```

به دنبال عبارتی مانند `s:4:"mode";s:11:"opportunity"` در ستون properties بگردید.

## فایل‌های ایجاد/ویرایش‌شده

1. **ایجاد شد:** `Command/RescheduleEntityActionsCommand.php` – فرمان باززمان‌بندی برای همه انواع موجودیت
2. **ویرایش شد:** `Config/services.php` – ثبت فرمان به‌عنوان سرویس
3. **ویرایش شد:** `EventListener/OpportunityLifecycleSubscriber.php` – افزودن لاگ اشکال‌زدایی (برای بهبودهای آتی Doctrine)
4. **ویرایش شد:** `Service/EventCriteriaBuilder.php` – رفع مشکل بارگذاری تنبل EntityManager
5. **ویرایش شد:** `Service/OpportunityCriteriaBuilder.php` – رفع مشکل بارگذاری تنبل EntityManager
6. **ویرایش شد:** `Service/NoteCriteriaBuilder.php` – رفع مشکل بارگذاری تنبل EntityManager

## جزئیات فنی

### انواع موجودیت پشتیبانی‌شده
این فرمان از سه نوع موجودیت پشتیبانی می‌کند:
1. **Opportunity** – ارسال یک ایمیل به ازای هر فرصت (از بسته MauticOpportunitiesBundle)
2. **Note** – ارسال یک ایمیل به ازای هر یادداشت (از بسته MauticNotesBundle)
3. **Event** – ارسال یک ایمیل به ازای هر رویداد (از بسته MauticEventsBundle)

### چرا رویدادهای Doctrine پاسخگو نیستند؟
Mautic از لایه Model اختصاصی (FormModel) برای ذخیره موجودیت‌ها استفاده می‌کند که در برخی سناریوها ممکن است رویدادهای چرخه عمر Doctrine را دور بزند. `OpportunityLifecycleSubscriber` به رویدادهای `postPersist` و `postUpdate` متکی است، اما این رویدادها هنگام ایجاد موجودیت از طریق UI همیشه فراخوانی نمی‌شوند.

### چرا این راهکار موثر است؟
به جای اتکا بر رویدادهای زمان واقعی، از روش زمان‌بندی دوره‌ای استفاده می‌کنیم:
- فرمان باززمان‌بندی به‌صورت دوره‌ای مخاطبان را دوباره در صف اجرا قرار می‌دهد
- تریگر کمپین همه موجودیت‌ها (نه فقط موارد جدید) را بررسی می‌کند
- کنترل idempotency تضمین می‌کند هر موجودیت فقط یک‌بار ایمیل دریافت کند

این رویکرد قابل اعتمادتر است و به زمان‌بندی رویدادهای Doctrine متکی نیست.

### جزئیات پیاده‌سازی برای هر موجودیت

#### حالت Opportunity (فایل `CampaignSubscriber.php:425-555`)
- فیلترکردن فرصت‌ها براساس شرایط بالادستی
- رعایت ارتباط با Event (اگر کمپین شرط رویداد داشته باشد)
- ارسال یک ایمیل به ازای هر فرصت
- جایگزینی توکن‌های فیلد فرصت (مانند `{opportunityfield=name}`)

#### حالت Note (`CampaignSubscriber.php:560-690`)
- فیلترکردن یادداشت‌ها براساس شرایط بالادستی
- رعایت ارتباط با رویدادها
- ارسال یک ایمیل به ازای هر یادداشت
- جایگزینی توکن‌های فیلد یادداشت (مانند `{notefield=description}`)

#### حالت Event (`CampaignSubscriber.php:695-862`)
- فیلترکردن رویدادها براساس شرایط بالادستی
- ارسال یک ایمیل به ازای هر رویداد
- جایگزینی توکن‌های فیلد رویداد (مانند `{eventfield=name}`)

## بهبودهای آینده

پیشنهادهای احتمالی:
1. استفاده از event dispatcher داخلی Mautic به جای رویدادهای Doctrine
2. افزودن listener سفارشی در فرم‌ها برای ایجاد موجودیت
3. پیاده‌سازی سیستم مبتنی بر صف برای مقیاس‌پذیری بهتر
4. افزودن گزینه پیکربندی فرکانس باززمان‌بندی برای هر کمپین
5. ایجاد lifecycle subscriber برای موجودیت‌های Note و Event (پس از رفع محدودیت رویدادهای Doctrine)

