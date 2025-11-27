# یکپارچگی SuiteCRM برای Mautic Postmark Bundle

این یکپارچگی به صورت خودکار داده‌های ارسال و ردیابی ایمیل را از Mautic به SuiteCRM همگام‌سازی می‌کند.

## ویژگی‌ها

- **ایجاد خودکار رکوردهای Email** در SuiteCRM وقتی Postmark از طریق کمپین‌های Mautic ایمیل ارسال می‌کند
- **بروزرسانی خودکار وضعیت Email** در SuiteCRM وقتی webhookهای Postmark اجرا می‌شوند (delivery, open, click, bounce و غیره)
- **همگام‌سازی یکپارچه** - گزارش‌ها هم در Mautic و هم در SuiteCRM قابل مشاهده هستند

## دستورالعمل‌های راه‌اندازی

### 1. پیکربندی API Credentialsهای SuiteCRM

موارد زیر را به فایل `.env` خود اضافه کنید:

```env
SUITECRM_BASE_URL=https://acaventportal.com/legacy
SUITECRM_CLIENT_ID=4d18f246-85e5-7417-2312-68bd1303752f
SUITECRM_CLIENT_SECRET=12121212
SUITECRM_USERNAME=admin
SUITECRM_PASSWORD=admin
```

**برای دریافت SuiteCRM API credentials:**

1. به عنوان Admin به SuiteCRM وارد شوید
2. به **Admin** → **OAuth2 Clients and Tokens** بروید
3. روی **"Create New Client"** کلیک کنید
4. پر کنید:
   - Name: `Mautic Integration`
   - Grant Type: `Password` (Resource Owner Password Credentials)
   - Scope: پیش‌فرض را بگذارید یا دسترسی ماژول `Emails` را اضافه کنید
5. ذخیره کنید و **Client ID** و **Client Secret** را کپی کنید
6. فایل `.env` خود را با این credentialsها بروزرسانی کنید
7. **username** و **password** SuiteCRM خود را به `.env` اضافه کنید

### 2. پاک کردن Cache

بعد از بروزرسانی `.env`، cache Mautic را پاک کنید:

```bash
rm -rf var/cache/*
```

یا از طریق خط فرمان:

```bash
php bin/console cache:clear
```

### 3. تست یکپارچگی

1. یک campaign در Mautic با یک action **Postmark Send Email** ایجاد کنید
2. campaign را برای یک contact تستی trigger کنید
3. SuiteCRM → ماژول **Emails** را بررسی کنید
4. باید یک رکورد email جدید با وضعیت "sent" ببینید

### 4. پیکربندی Postmark Webhook (اگر قبلاً انجام نشده)

مطمئن شوید Postmark webhook شما پیکربندی شده تا eventها را به Mautic ارسال کند:

**Webhook URL:**
```
https://your-mautic-domain.com/postmark/webhook
```

**Eventهایی که باید فعال شوند:**
- Delivery
- Open
- Click
- Bounce
- Spam Complaint

وقتی این eventها اجرا می‌شوند، رکوردهای email SuiteCRM به صورت خودکار بروزرسانی می‌شوند.

## چطور کار می‌کند

### وقتی Email ارسال می‌شود

1. کمپین Mautic ارسال ایمیل Postmark را trigger می‌کند
2. پلاگین یک **رکورد Email** جدید در SuiteCRM با موارد زیر ایجاد می‌کند:
   - Name: "Postmark Email to [Contact Name]"
   - Status: "sent"
   - آدرس‌های From/To
   - تاریخ ارسال
   - رابطه Parent (اگر Contact ID نگاشت شده باشد)

3. SuiteCRM Email ID در metadata لاگ Mautic برای بروزرسانی‌های آینده ذخیره می‌شود

### وقتی Webhook Event دریافت می‌شود

1. Postmark webhook را به Mautic ارسال می‌کند (delivery, open, click و غیره)
2. پلاگین لاگ campaign Mautic را با داده‌های event بروزرسانی می‌کند
3. پلاگین SuiteCRM Email ID را از metadata لاگ بازیابی می‌کند
4. پلاگین رکورد email SuiteCRM را با موارد زیر بروزرسانی می‌کند:
   - **Delivery**: Status → "delivered"
   - **Open**: Status → "opened" + اطلاعات location/platform
   - **Click**: Status → "clicked" + لینک کلیک شده
   - **Bounce**: Status → "bounced" + دلیل bounce
   - **Spam**: Status → "spam_complaint"

## نگاشت Contactهای Mautic به Contactهای SuiteCRM

به صورت پیش‌فرض، یکپارچگی از فیلد `suitecrm_id` از profile contact Mautic برای link کردن emailها به contact صحیح SuiteCRM استفاده می‌کند.

**راه‌اندازی:**

1. یک فیلد سفارشی به contactهای Mautic اضافه کنید: `suitecrm_id`
2. SuiteCRM Contact ID را در این فیلد برای هر contact ذخیره کنید
3. یکپارچگی به صورت خودکار emailها را به contact صحیح در SuiteCRM link می‌کند

**نکته:** نام فیلد در این قسمت پیکربندی شده:
```php
plugins/MauticPostmarkBundle/EventListener/CampaignSubscriber.php:230
'parent_id' => $profileFields['suitecrm_id'] ?? null,
```

اگر نیاز دارید از نام فیلد متفاوتی استفاده کنید، این خط را متناسب تغییر دهید.

## عیب‌یابی

### یکپارچگی کار نمی‌کند

1. **Credentials را بررسی کنید**: مطمئن شوید `SUITECRM_CLIENT_ID` و `SUITECRM_CLIENT_SECRET` صحیح هستند
2. **URL را بررسی کنید**: تأیید کنید `SUITECRM_BASE_URL` با `/legacy` تمام می‌شود (بدون slash انتهایی)
3. **Authentication را تست کنید**: سعی کنید از طریق Postman با credentialsهای خود یک رکورد ایجاد کنید
4. **لاگ‌ها را بررسی کنید**: به `var/logs/mautic_dev.php` برای خطاها نگاه کنید

### Emailها در SuiteCRM ظاهر نمی‌شوند

- مطمئن شوید یکپارچگی فعال است (credentials در `.env` تنظیم شده‌اند)
- بعد از تغییر `.env` cache را پاک کنید
- بررسی کنید که آیا email Postmark واقعاً ارسال شده (لاگ campaign Mautic را بررسی کنید)
- تأیید کنید SuiteCRM API از سرور Mautic شما قابل دسترسی است

### بروزرسانی‌های Webhook در SuiteCRM منعکس نمی‌شوند

- Postmark webhook را تأیید کنید که به درستی پیکربندی شده
- Endpoint webhook Mautic را بررسی کنید که قابل دسترسی است: `/postmark/webhook`
- مطمئن شوید eventهای webhook در Postmark فعال هستند
- بررسی کنید که آیا SuiteCRM Email ID در metadata لاگ Mautic وجود دارد

## غیرفعال کردن یکپارچگی

برای غیرفعال کردن یکپارچگی SuiteCRM بدون حذف کد:

1. Credentialsها را در `.env` حذف یا کامنت کنید:
```env
# SUITECRM_BASE_URL=
# SUITECRM_CLIENT_ID=
# SUITECRM_CLIENT_SECRET=
# SUITECRM_USERNAME=
# SUITECRM_PASSWORD=
```

2. Cache را پاک کنید

پلاگین به صورت خودکار credentialsهای گم‌شده را تشخیص می‌دهد و همگام‌سازی SuiteCRM را رد می‌کند.

## مرجع API

### Endpointهای SuiteCRM API V8 استفاده شده

- **POST** `/Api/access_token` - دریافت OAuth2 token
- **POST** `/Api/V8/module` - ایجاد رکورد Email
- **PATCH** `/Api/V8/module` - بروزرسانی رکورد Email

### نمونه فراخوانی‌های API

**ایجاد رکورد Email:**
```json
POST https://acaventportal.com/legacy/Api/V8/module
{
  "data": {
    "type": "Emails",
    "attributes": {
      "name": "Test Email",
      "status": "sent",
      "from_addr": "sender@example.com",
      "to_addrs": "recipient@example.com",
      "parent_type": "Contacts",
      "parent_id": "contact-uuid-here"
    }
  }
}
```

**بروزرسانی رکورد Email:**
```json
PATCH https://acaventportal.com/legacy/Api/V8/module
{
  "data": {
    "type": "Emails",
    "id": "email-uuid-here",
    "attributes": {
      "status": "opened"
    }
  }
}
```

## پشتیبانی

اگه مشکلی داشتی یا سوالی، بگو تا کمکت کنم!

---

**آخرین بروزرسانی:** 2025-11-04
**وضعیت:** ✅ عملیاتی و آماده برای استفاده
