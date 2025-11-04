# ⚠️ منسوخ شده – استفاده نکنید

> **مهم:** این مستند **منسوخ** و قدیمی است. نسخه به‌روزتر در فایل [ENTITY_EMAIL_AUTOMATION.md](ENTITY_EMAIL_AUTOMATION.md) موجود است که همه انواع موجودیت (Opportunity، Note و Event) را با نام فرمان‌ها و جزئیات پیاده‌سازی صحیح پوشش می‌دهد.
>
> **این فایل صرفاً برای مراجعه تاریخی نگهداری می‌شود. لطفاً برای راهنمای فعلی از ENTITY_EMAIL_AUTOMATION.md استفاده کنید.**

---

# مستندات قدیمی (فقط برای مرجع تاریخی)

**تاریخ منسوخ‌شدن:** ۲۰۲۵-۱۱-۰۴  
**دلیل:** تغییر نام فرمان‌ها و گسترش پیاده‌سازی برای پشتیبانی از تمامی انواع موجودیت

# ارسال خودکار ایمیل برای فرصت‌های جدید

## مسئله
در حالت ارسال «به ازای هر فرصت» در یک کمپین، ایمیل‌ها برای فرصت‌های موجود که شرایط را دارند ارسال می‌شود. اما فرصت‌های جدیدی که پس از اجرای کمپین ایجاد می‌شوند، به صورت خودکار ایمیل دریافت نمی‌کنند.

## ریشه مشکل
در پیاده‌سازی اولیه، `OpportunityLifecycleSubscriber` طوری طراحی شده بود که هنگام ایجاد فرصت جدید، اجراهای کمپین را به صف اضافه کند. اما این سازوکار به رویدادهای چرخه عمر Doctrine متکی است؛ رویدادهایی که هنگام ایجاد فرصت از طریق رابط کاربری Mautic فعال نمی‌شوند.

## راهکار (قدیمی)
~~فرمان جدید `mautic:postmark:reschedule-opportunities` پیاده‌سازی شده است~~

**وضعیت فعلی:** از `mautic:postmark:reschedule-entities` استفاده کنید که از همه انواع موجودیت (opportunity، note، event) پشتیبانی می‌کند. برای مستندات به‌روز به [ENTITY_EMAIL_AUTOMATION.md](ENTITY_EMAIL_AUTOMATION.md) مراجعه کنید.

## سازوکار

### ۱. فرمان باززمان‌بندی (قدیمی)
~~مسیر فرمان: `/var/www/html/mautic_dev/plugins/MauticPostmarkBundle/Command/RescheduleOpportunityActionsCommand.php`~~

**وضعیت فعلی:** فرمان به `RescheduleEntityActionsCommand.php` تغییر نام داده و از همه موجودیت‌ها پشتیبانی می‌کند.
- همه اکشن‌های Postmark در حالت «opportunity» را پیدا می‌کند
- مخاطبانی را که قبلاً اکشن را اجرا کرده‌اند برای ارزیابی دوباره به صف بازمی‌گرداند
- فرصت‌های جدیدی را که با شرایط کمپین مطابقت دارند بررسی می‌کند

### ۲. تریگر کمپین
وقتی `mautic:campaigns:trigger` اجرا می‌شود:
- همه رویدادهای زمان‌بندی‌شده را پردازش می‌کند
- برای اکشن‌های حالت opportunity، همه فرصت‌های هر مخاطب را بررسی می‌کند
- فقط برای فرصت‌هایی ایمیل می‌فرستد که قبلاً ایمیلی دریافت نکرده‌اند (برابر با کنترل idempotency)
- همه ارسال‌ها را برای جلوگیری از تکرار لاگ می‌کند

## دستورالعمل راه‌اندازی

### افزودن به کران‌تب
فرمان باززمان‌بندی را هر ۵ تا ۱۵ دقیقه در کران‌تب اجرا کنید:

```bash
# ویرایش کران‌تب
crontab -e

# اجرا هر ۱۰ دقیقه
*/10 * * * * php /var/www/html/mautic_dev/bin/console mautic:postmark:reschedule-opportunities

# یا اجرا برای آی‌دی کمپین مشخص
*/10 * * * * php /var/www/html/mautic_dev/bin/console mautic:postmark:reschedule-opportunities -i 36
```

### اطمینان از اجرای تریگر کمپین
مطمئن شوید فرمان تریگر کمپین نیز فعال است:

```bash
# اگر هنوز اضافه نشده
*/5 * * * * php /var/www/html/mautic_dev/bin/console mautic:campaigns:trigger
```

## نحوه استفاده

### تست دستی
می‌توانید جریان را به صورت دستی اجرا کنید:

```bash
# گام ۱: باززمان‌بندی اکشن‌های حالت opportunity
php /var/www/html/mautic_dev/bin/console mautic:postmark:reschedule-opportunities

# گام ۲: اجرای کمپین
php /var/www/html/mautic_dev/bin/console mautic:campaigns:trigger
```

### برای کمپین مشخص
```bash
# فقط کمپین ۳۶ را باززمان‌بندی کن
php /var/www/html/mautic_dev/bin/console mautic:postmark:reschedule-opportunities -i 36

# اجرای کمپین ۳۶
php /var/www/html/mautic_dev/bin/console mautic:campaigns:trigger -i 36
```

## نمونه جریان کاری

1. **اجرای کمپین در ساعت ۱۰:۰۰**  
   - ایمیل‌ها برای فرصت‌های ۱، ۲، ۳ ارسال می‌شود

2. **فرصت جدید شماره ۴ در ساعت ۱۰:۳۰ ایجاد می‌شود**  
   - فرصت با شرایط کمپین مطابقت دارد  
   - اما کمپین به صورت خودکار اجرا نمی‌شود

3. **فرمان باززمان‌بندی در ساعت ۱۰:۴۰ اجرا می‌شود** (از کران)  
   - همه مخاطبان دارای اکشن opportunity را پیدا می‌کند  
   - آنها را برای اجرا دوباره در صف قرار می‌دهد

4. **تریگر کمپین در ساعت ۱۰:۴۵ اجرا می‌شود** (از کران)  
   - همه فرصت‌های مخاطبان باززمان‌بندی‌شده را بررسی می‌کند  
   - متوجه می‌شود فرصت ۴ ایمیل دریافت نکرده  
   - ایمیل برای فرصت ۴ ارسال می‌شود

## مانیتورینگ

### بررسی فعالیت باززمان‌بندی
```bash
# مشاهده لاگ‌ها
tail -f /var/www/html/mautic_dev/var/logs/mautic_prod.log | grep "Rescheduled opportunity-mode"
```

### بررسی لاگ ارسال
```bash
# کوئری روی لاگ ارسال
php bin/console doctrine:query:sql "SELECT * FROM postmark_entity_send_log WHERE entity_type = 'opportunity' ORDER BY created_at DESC LIMIT 10"
```

### بررسی لاگ کمپین
```bash
# مشاهده اجرای رویدادهای کمپین
php bin/console doctrine:query:sql "SELECT id, lead_id, event_id, date_triggered, is_scheduled FROM campaign_lead_event_log WHERE event_id = 464 ORDER BY id DESC LIMIT 10"
```

## ملاحظات کارایی

- فرمان باززمان‌بندی سبک است و فقط رکوردهای دیتابیس را به‌روزرسانی می‌کند
- فقط مخاطبانی را باززمان‌بندی می‌کند که حداقل یک‌بار اکشن را اجرا کرده‌اند
- کنترل idempotency مانع ارسال ایمیل تکراری می‌شود
- فرکانس پیشنهادی: هر ۵ تا ۱۵ دقیقه بسته به نیاز به پردازش فرصت‌های تازه

## رفع اشکال

### فرصت‌های جدید ایمیل نمی‌گیرند؟

1. **بررسی اجرای فرمان باززمان‌بندی:**
   ```bash
   php bin/console mautic:postmark:reschedule-opportunities -i 36
   ```
   خروجی باید چیزی مثل «Rescheduled X contact(s)» باشد.

2. **بررسی اجرای تریگر کمپین:**
   ```bash
   php bin/console mautic:campaigns:trigger -i 36
   ```
   خروجی باید «X total events were executed» باشد.

3. **تطبیق فرصت با شرایط:**  
   - مقدار فیلدهای شرطی کمپین (مثلاً salesStage) را بررسی کنید  
   - مطمئن شوید فرصت جدید همان مقدار را دارد

4. **بررسی لاگ ارسال برای تکرار:**  
   ```bash
   php bin/console doctrine:query:sql "SELECT * FROM postmark_entity_send_log WHERE entity_type = 'opportunity' AND entity_id = YOUR_OPPORTUNITY_ID"
   ```

### ایمیل‌ها چندبار ارسال می‌شوند؟

نباید این اتفاق بیفتد؛ اگر افتاد:
- جدول `postmark_entity_send_log` را برای رکوردهای تکراری بررسی کنید
- منطق `alreadySent()` در `CampaignSubscriber.php:924` را بازبینی کنید

## فایل‌های ایجاد/تغییر یافته (قدیمی)

1. **ایجاد شد:** `Command/RescheduleOpportunityActionsCommand.php` – فرمان باززمان‌بندی
2. **ویرایش شد:** `Config/services.php` – ثبت فرمان به‌عنوان سرویس
3. **ویرایش شد:** `EventListener/OpportunityLifecycleSubscriber.php` – افزودن لاگ‌های اشکال‌زدایی
4. **ویرایش شد:** `Service/EventCriteriaBuilder.php` – رفع مشکل بارگذاری تنبل EntityManager
5. **ویرایش شد:** `Service/OpportunityCriteriaBuilder.php` – رفع مشکل بارگذاری تنبل EntityManager
6. **ویرایش شد:** `Service/NoteCriteriaBuilder.php` – رفع مشکل بارگذاری تنبل EntityManager

## جزئیات فنی

### چرا رویدادهای Doctrine پاسخگو نیستند؟
Mautic از لایه Model اختصاصی (FormModel) برای ذخیره موجودیت‌ها استفاده می‌کند که ممکن است رویدادهای چرخه عمر Doctrine را دور بزند. `OpportunityLifecycleSubscriber` به رویدادهای `postPersist` و `postUpdate` متکی است، اما هنگام ایجاد فرصت از طریق UI این رویدادها همیشه فراخوانی نمی‌شوند.

### چرا این راهکار موثر است؟
به جای اتکا به رویدادهای زمان واقعی، از روش زمان‌بندی دوره‌ای استفاده می‌کنیم:
- فرمان باززمان‌بندی به طور دوره‌ای مخاطبان را در صف قرار می‌دهد
- تریگر کمپین تمام فرصت‌ها را چک می‌کند (نه فقط موارد جدید)
- کنترل idempotency تضمین می‌کند هر فرصت فقط یک‌بار ایمیل می‌گیرد

این روش پایدارتر است و به زمان‌بندی رویدادهای Doctrine وابسته نیست.

## بهبودهای آتی

پیشنهادهای احتمالی:
1. استفاده از event dispatcher داخلی Mautic به جای رویدادهای Doctrine
2. افزودن listener سفارشی برای رویداد ایجاد فرصت در فرم‌ها
3. پیاده‌سازی سیستم مبتنی بر صف برای مقیاس‌پذیری بهتر
4. افزودن گزینه پیکربندی فرکانس باززمان‌بندی برای هر کمپین

