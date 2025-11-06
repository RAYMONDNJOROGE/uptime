# 🌐 Uptime Hotspot Management System

A comprehensive MikroTik hotspot management system with M-Pesa integration for automated payments and voucher-based authentication.

## ✨ Features

### For Customers
- 💳 M-Pesa STK Push payment integration
- 🎫 Voucher code authentication
- 📱 Responsive mobile-friendly interface
- ⚡ Real-time payment verification
- 🔒 Secure automatic connection

### For Administrators
- 🎫 Generate unlimited voucher codes (UTS-XXXXXX format)
- 👥 Complete user management system
- 💰 Transaction tracking and reporting
- 📊 System monitoring dashboard
- 🔧 Full MikroTik functionality
- 📈 Revenue analytics

## 📋 Requirements

- PHP 7.4 or higher
- Apache/Nginx web server
- MikroTik RouterOS with Hotspot
- M-Pesa Daraja API credentials
- SSL certificate (HTTPS)

## 🚀 Quick Start

1. **Upload files to your server**
2. **Configure `config.php`** with your credentials
3. **Create MikroTik hotspot profiles**
4. **Update `index.html`** with your domain
5. **Test the system**

## 📁 File Structure
uptime-hotspot/
├── index.html              # Customer portal
├── config.php              # Configuration
├── MikrotikAPI.php         # Router API
├── .htaccess               # Security
├── api/
│   ├── payment.php         # M-Pesa handler
│   ├── callback.php        # Payment callback
│   └── voucher.php         # Voucher verification
└── admin/
├── index.php           # Dashboard
└── login.php           # Admin login