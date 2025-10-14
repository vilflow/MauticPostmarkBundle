# SuiteCRM Integration for Mautic Postmark Bundle

This integration automatically syncs email sending and tracking data from Mautic to SuiteCRM.

## Features

- **Auto-create Email records** in SuiteCRM when Postmark sends an email via Mautic campaigns
- **Auto-update Email status** in SuiteCRM when Postmark webhooks fire (delivery, open, click, bounce, etc.)
- **Seamless sync** - Reports visible in both Mautic and SuiteCRM

## Setup Instructions

### 1. Configure SuiteCRM API Credentials

Add the following to your `.env` file:

```env
SUITECRM_BASE_URL=https://acaventportal.com/legacy
SUITECRM_CLIENT_ID=4d18f246-85e5-7417-2312-68bd1303752f
SUITECRM_CLIENT_SECRET=12121212
SUITECRM_USERNAME=admin
SUITECRM_PASSWORD=admin
```

**To get SuiteCRM API credentials:**

1. Log in to SuiteCRM as Admin
2. Go to **Admin** → **OAuth2 Clients and Tokens**
3. Click **"Create New Client"**
4. Fill in:
   - Name: `Mautic Integration`
   - Grant Type: `Password` (Resource Owner Password Credentials)
   - Scope: Leave default or add `Emails` module access
5. Save and copy the **Client ID** and **Client Secret**
6. Update your `.env` file with these credentials
7. Add your SuiteCRM **username** and **password** to `.env`

### 2. Clear Cache

After updating `.env`, clear Mautic cache:

```bash
rm -rf var/cache/*
```

Or via command line:

```bash
php bin/console cache:clear
```

### 3. Test the Integration

1. Create a campaign in Mautic with a **Postmark Send Email** action
2. Trigger the campaign for a test contact
3. Check SuiteCRM → **Emails** module
4. You should see a new email record created with status "sent"

### 4. Configure Postmark Webhook (if not already done)

Make sure your Postmark webhook is configured to send events to Mautic:

**Webhook URL:**
```
https://your-mautic-domain.com/postmark/webhook
```

**Events to enable:**
- Delivery
- Open
- Click
- Bounce
- Spam Complaint

When these events fire, SuiteCRM email records will be automatically updated.

## How It Works

### When Email is Sent

1. Mautic campaign triggers Postmark email send
2. Plugin creates a new **Email record** in SuiteCRM with:
   - Name: "Postmark Email to [Contact Name]"
   - Status: "sent"
   - From/To addresses
   - Date sent
   - Parent relationship (if Contact ID is mapped)

3. SuiteCRM Email ID is stored in Mautic log metadata for future updates

### When Webhook Event is Received

1. Postmark sends webhook to Mautic (delivery, open, click, etc.)
2. Plugin updates Mautic campaign log with event data
3. Plugin retrieves SuiteCRM Email ID from log metadata
4. Plugin updates SuiteCRM email record with:
   - **Delivery**: Status → "delivered"
   - **Open**: Status → "opened" + location/platform info
   - **Click**: Status → "clicked" + clicked link
   - **Bounce**: Status → "bounced" + bounce reason
   - **Spam**: Status → "spam_complaint"

## Mapping Mautic Contacts to SuiteCRM Contacts

By default, the integration uses `suitecrm_id` field from Mautic contact profile to link emails to the correct SuiteCRM contact.

**Setup:**

1. Add a custom field to Mautic contacts: `suitecrm_id`
2. Store the SuiteCRM Contact ID in this field for each contact
3. The integration will automatically link emails to the correct contact in SuiteCRM

**Note:** The field name is configured in:
```php
plugins/MauticPostmarkBundle/EventListener/CampaignSubscriber.php:230
'parent_id' => $profileFields['suitecrm_id'] ?? null,
```

If you need to use a different field name, modify this line accordingly.

## Troubleshooting

### Integration not working

1. **Check credentials**: Make sure `SUITECRM_CLIENT_ID` and `SUITECRM_CLIENT_SECRET` are correct
2. **Check URL**: Verify `SUITECRM_BASE_URL` ends with `/legacy` (no trailing slash)
3. **Test authentication**: Try creating a record via Postman using your credentials
4. **Check logs**: Look at `var/logs/mautic_dev.php` for errors

### Emails not appearing in SuiteCRM

- Make sure the integration is enabled (credentials are set in `.env`)
- Clear cache after changing `.env`
- Check if Postmark email was actually sent (check Mautic campaign log)
- Verify SuiteCRM API is accessible from your Mautic server

### Webhook updates not reflecting in SuiteCRM

- Verify Postmark webhook is configured correctly
- Check Mautic webhook endpoint is accessible: `/postmark/webhook`
- Ensure webhook events are enabled in Postmark
- Check if SuiteCRM Email ID exists in Mautic log metadata

## Disabling Integration

To disable SuiteCRM integration without removing code:

1. Remove or comment out the credentials in `.env`:
```env
# SUITECRM_BASE_URL=
# SUITECRM_CLIENT_ID=
# SUITECRM_CLIENT_SECRET=
# SUITECRM_USERNAME=
# SUITECRM_PASSWORD=
```

2. Clear cache

The plugin will automatically detect missing credentials and skip SuiteCRM sync.

## API Reference

### SuiteCRM API V8 Endpoints Used

- **POST** `/Api/access_token` - Get OAuth2 token
- **POST** `/Api/V8/module` - Create Email record
- **PATCH** `/Api/V8/module` - Update Email record

### Example API Calls

**Create Email Record:**
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

**Update Email Record:**
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

## Support

Age moshkeli dashti ya soali, contact kon!
