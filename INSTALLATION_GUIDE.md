# Uptime Hotspot System - Installation Guide

## 📋 Requirements

### Server Requirements
- PHP 7.4 or higher
- Web server (Apache/Nginx)
- HTTPS/SSL certificate (for M-Pesa API and production use)
- Writeable directory for data storage

### MikroTik Router Requirements
- RouterOS with Hotspot configured
- API service enabled (port 8728)
- User with appropriate permissions

### M-Pesa Requirements
- Safaricom Daraja API credentials (Consumer Key & Secret)
- Paybill/Till number
- Passkey for STK Push
- Public callback URL (HTTPS required)

---

## 🚀 Installation Steps

### Step 1: Upload Files to Server

Upload all files to your web server with the following structure:

```
/public_html/
├── index.html              (Frontend hotspot portal)
├── config.php              (Configuration file)
├── MikrotikAPI.php         (MikroTik communication class)
├── api/
│   ├── payment.php         (M-Pesa payment handler)
│   ├── callback.php        (M-Pesa callback receiver)
│   └── voucher.php         (Voucher verification)
├── admin/
│   ├── index.php           (Admin dashboard)
│   └── login.php           (Admin login page)
└── data/                   (Auto-created for storage)
    ├── vouchers.json
    ├── transactions.json
    └── system.log
```

### Step 2: Configure MikroTik Router

1. **Enable API Service:**
   ```
   /ip service
   set api address=0.0.0.0/0 disabled=no port=8728
   ```

2. **Create Hotspot Profiles:**
   ```
   /ip hotspot user profile
   add name="30min_3mbps" rate-limit="3M/3M" session-timeout="30m"
   add name="2hour_3mbps" rate-limit="3M/3M" session-timeout="2h"
   add name="12hour_3mbps" rate-limit="3M/3M" session-timeout="12h"
   add name="24hour_3mbps" rate-limit="3M/3M" session-timeout="1d"
   add name="1week_3mbps" rate-limit="3M/3M" session-timeout="1w"
   ```

3. **Set Hotspot Walled Garden (Allow Payment APIs):**
   ```
   /ip hotspot walled-garden
   add dst-host=*.safaricom.co.ke
   add dst-host=yourdomain.com
   ```

### Step 3: Configure config.php

Edit `config.php` with your settings:

```php
// Admin Credentials
define('ADMIN_USERNAME', 'admin');
define('ADMIN_PASSWORD', 'your_secure_password');

// MikroTik Settings
define('MIKROTIK_HOST', '192.168.88.1');
define('MIKROTIK_USER', 'admin');
define('MIKROTIK_PASS', 'your_mikrotik_password');
define('MIKROTIK_PORT', 8728);

// M-Pesa Settings
define('MPESA_CONSUMER_KEY', 'your_consumer_key');
define('MPESA_CONSUMER_SECRET', 'your_consumer_secret');
define('MPESA_SHORTCODE', '174379');
define('MPESA_PASSKEY', 'your_passkey');
define('MPESA_CALLBACK_URL', 'https://yourdomain.com/api/callback.php');
define('MPESA_ENVIRONMENT', 'sandbox'); // Change to 'production' for live
```

### Step 4: Update Frontend Configuration

Edit `index.html` and update the API base URL:

```javascript
const API_BASE_URL = "https://yourdomain.com/";
```

### Step 5: Set File Permissions

Ensure the data directory is writable:

```bash
chmod 755 /path/to/data
chmod 644 /path/to/data/*.json
chmod 644 /path/to/data/*.log
```

### Step 6: Configure Hotspot Walled Garden in index.html

Update the MikroTik hotspot to serve your portal:

1. Go to MikroTik WebFig/Winbox
2. Navigate to **IP > Hotspot > Server Profiles**
3. Set HTML Directory to point to your hosted `index.html`
4. Or set Login page URL to: `https://yourdomain.com/index.html`

---

## 🔐 M-Pesa Daraja API Setup

### Sandbox Environment (Testing)

1. Visit: https://developer.safaricom.co.ke/
2. Create an account and log in
3. Create a new app to get:
   - Consumer Key
   - Consumer Secret
4. Use test credentials:
   - Shortcode: `174379`
   - Passkey: (Provided in Daraja docs)
5. Test phone number: `254708374149`

### Production Environment

1. Apply for Lipa Na M-Pesa Online (STK Push) through Daraja
2. Provide business details and documentation
3. Get production credentials after approval
4. Update `config.php` with production credentials
5. Change `MPESA_ENVIRONMENT` to `'production'`

### Setting Up Callback URL

Your callback URL must be:
- Publicly accessible (HTTPS)
- Reachable from Safaricom servers
- Not behind authentication

Test your callback: `curl https://yourdomain.com/api/callback.php`

---

## 🧪 Testing the System

### Test M-Pesa Payment (Sandbox)

1. Connect to the hotspot
2. Select a plan and click "Connect Now"
3. Enter test phone: `254708374149`
4. Approve the STK push prompt
5. You should be automatically connected

### Test Voucher Login

1. Admin panel: Create a voucher
2. Copy the voucher code (e.g., `UTS-ABC123`)
3. On hotspot portal, enter voucher in "Voucher Login"
4. Click "Connect with Voucher"
5. Should be automatically logged in

---

## 🔒 Security Best Practices

1. **Change Default Credentials:**
   - Update `ADMIN_PASSWORD` in config.php
   - Use strong passwords (16+ characters)

2. **Secure File Permissions:**
   ```bash
   chmod 600 config.php
   chmod 755 admin/
   ```

3. **Enable HTTPS:**
   - Install SSL certificate (Let's Encrypt recommended)
   - Force HTTPS redirects

4. **Restrict Admin Access:**
   - Add `.htaccess` IP restrictions for `/admin/`
   - Use fail2ban for brute force protection

5. **Regular Backups:**
   - Backup `data/` folder regularly
   - Keep transaction logs for reconciliation

6. **Monitor Logs:**
   - Check `data/system.log` regularly
   - Set up log rotation

---

## 🐛 Troubleshooting

### Users Can't Connect After Payment

**Cause:** MikroTik API connection failed

**Solution:**
1. Check MikroTik API is enabled: `/ip service print`
2. Verify credentials in config.php
3. Check firewall rules allow API port (8728)
4. Test connection: `telnet 192.168.88.1 8728`

### M-Pesa STK Push Not Received

**Cause:** Invalid phone number or API credentials

**Solution:**
1. Verify phone format: `254712345678`
2. Check M-Pesa credentials in config.php
3. Ensure callback URL is accessible (HTTPS)
4. Check `data/system.log` for errors

### Vouchers Not Working

**Cause:** Profile mismatch or MikroTik connection issue

**Solution:**
1. Verify profile names in config.php match MikroTik
2. Check voucher hasn't expired
3. Ensure voucher is unused: Admin panel > Vouchers tab
4. Check `data/vouchers.json` for voucher status

### Payment Successful But User Not Created

**Cause:** Callback not received or profile error

**Solution:**
1. Check callback URL is accessible publicly
2. Verify MikroTik profiles exist
3. Check `data/transactions.json` for transaction status
4. Manually create user in MikroTik if needed

---

## 📊 System Monitoring

### Check System Logs

```bash
tail -f /path/to/data/system.log
```

### Monitor Transactions

Admin panel > Transactions tab shows all M-Pesa payments

### Check Vouchers

Admin panel > Vouchers tab shows all generated vouchers and their status

---

## 🔄 Maintenance

### Clear Old Transactions (Periodic)

Edit `data/transactions.json` and remove old entries (keep last 1000)

### Backup Data Files

```bash
cp -r data/ backup/data_$(date +%Y%m%d)/
```

### Update System

1. Backup all files
2. Replace PHP files with new versions
3. Keep config.php and data/ folder
4. Test functionality

---

## 📞 Support

- **Customer Care:** +254 791 024 153
- **Software Provider:** Uptime Tech Masters
- **Email:** support@uptimehotspot.com

---

## 📄 License

© 2025 Uptime Hotspot - All rights reserved
Developed by Uptime Tech Masters