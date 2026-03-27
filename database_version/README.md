# hails.Monitor

Monitor avatar activity in Second Life, process it into structured sessions, and visualize it through a secure web dashboard.

This project has evolved significantly from earlier versions and now includes:
- Session-based tracking (not just raw visits)
- Automated compression jobs
- A full authentication system
- A live dashboard UI

---

## 🖼️ Preview

<img width="1920" height="964" alt="web-ui-preview" src="https://github.com/user-attachments/assets/c5088f59-13de-459a-bc9e-cf7273419558" />

---

## 📦 What You'll Need:

- **Webserver (Required)**
  - Apache or Nginx recommended
- **Database**
  - MySQL / MariaDB
- **PHP**
  - PHP 8+ recommended
- **SQL Client**
  - HeidiSQL, DBeaver, or similar
- **Cron Access (IMPORTANT)**
  - Required for session compression and maintenance jobs

---

## ✅ Optional but Recommended:

- Grafana (for advanced analytics)
- HTTPS (strongly recommended for security)

---

## 🚨 CRITICAL SECURITY NOTES:

- **ALL config files MUST be stored in a secure, non-public directory.**
  - Example: `/usr/www/yourdomain/secure/config.php`
- **NEVER place `config.php` inside your public web root.**
- **DO NOT expose your API key.**
- **DO NOT commit credentials to GitHub.**

> If your config file is accessible via a browser, your system is compromised.

---

## 🧠 Project Overview

This system works in multiple stages:

### 1. Data Collection (LSL Scripts)
- `hails.Monitor.lsl`
- `hails.CronServer.lsl`
- `hails.HUDmon-BETA.lsl`

These send avatar data to your server via API.

---

### 2. API Layer
- `av.php` → handles batch inserts & queries  
- `avCron.php` → handles name cleanup + maintenance

---

### 3. Database Layer
- Created via: `run_me.sql`  

Includes:
- `avatar_visits` (raw data)
- `change_log` (audit trail)
- `avatar_sessions` (compressed sessions)
- `monitor_users` (auth system)
- `monitor_user_regions` (permissions)

---

### 4. Processing Layer (CRON REQUIRED)
- `hailsDBCompressCron.php` 

This:
- Converts raw logs into sessions
- Reduces database size
- Tracks progress via `compression_state`

---

### 5. Dashboard + UI
- `monitor_dashboard.php`
- `monitor_data.php`  
- `monitor_login.php`  
- `monitor_logout.php` 
- `index.html` (frontend container) 

Features:
- Secure login system
- Role-based access (user / moderator / superadmin)
- Region filtering
- Live activity tracking
- Session-based analytics

---

## ⚡️ Setup Instructions

### 1. Download Files

- Upload all PHP files to your webserver
- Place LSL scripts into Second Life inventory

---

### 2. Create Secure Config

Create `config.php` OUTSIDE your web root:

```php
<?php

if (php_sapi_name() !== 'cli' && !defined('ALLOW_CONFIG_INCLUDE')) {
    http_response_code(403);
    exit('Forbidden');
}

define('DB_SERVER', 'localhost'); //usually localhost, unless your database is hosted on a seperate server
define('DB_USERNAME', 'YOUR-DB-USER-HERE');
define('DB_PASSWORD', 'YOUR-PASSWORD-HERE');
define('DB_NAME', 'DATABASE-NAME-HERE');
define('API_KEY', 'YOUR-API-KEY-HERE'); // Add the API key here

/**
 * Monitor dashboard login
 */
define('MONITOR_SUPERADMIN', 'YOUR-DASHBOARD-USERNAME-HERE');
?>
```

Example path:
```
/usr/www/yourdomain/secure/config.php
```

Then update ALL PHP files to point to it.

---

### 3. Configure API Key

Set the same key in:
- `config.php`
- ALL LSL scripts

```lsl
string API_KEY = "YOUR-SECRET-KEY";
```

---

### 4. Update Server URL

Update in all LSL scripts:

```lsl
string server_url = "https://yourdomain.com/av.php";
```

---

### 5. Upload Files

- Upload PHP files to your web root
- Ensure `.htaccess` is included if used

---

### 6. Run SQL Script

Execute:

```
run_me.sql
```

This creates all required tables and indexes.

---

### 7. Configure Cron Jobs (VERY IMPORTANT)

You MUST configure cron jobs for:

#### Main Processing Job:
```
*/30 * * * * /usr/bin/php /path/to/your/hosted/hailsDBCompressCron.php >/dev/null 2>&1
```

### 8. Deploy LSL Scripts

- Place in-world objects with:
  - `hails.Monitor.lsl`
  - `hails.Lookup.lsl`
- Deploy:
  - `hails.CronServer.lsl` (handles periodic tasks)
  - `hails.Watchdog.lsl` (monitoring/health)

---

### 9. Access Dashboard

Visit:
```
https://yourdomain.com/index.html
```

Log in using credentials from your database.

---

## 🔐 Authentication System

Supports:
- User roles:
  - user
  - moderator
  - superadmin
- Region-based access control
- Secure password hashing
- Session-based login

---

## 📊 Data Flow Summary

1. LSL → sends avatar data
2. `av.php` → stores raw visits
3. `change_log` → tracks changes
4. `hailsDBCompressCron.php` → builds sessions
5. Dashboard → displays processed data

---

## 🧹 Maintenance Notes

- Monitor log file sizes (`phpcron.txt`)
- Ensure cron jobs are running
- Periodically verify:
  - `compression_state`
  - session growth
- Clean old logs if needed

---

## ⚠️ Common Mistakes

- ❌ Putting `config.php` in public directory
- ❌ Forgetting to set API key everywhere
- ❌ Not running cron jobs
- ❌ Incorrect file paths to config
- ❌ Missing database indexes

---

## 🛠️ Notes

- This project assumes moderate familiarity with:
  - PHP
  - MySQL
  - Cron jobs
- Debug logging is built into several components
- System is designed for scalability via session compression

---

## 🧾 Final Notes

This is no longer a simple visit tracker, it is a **full monitoring platform**.

Take the time to:
- Secure it properly
- Verify cron jobs
- Keep config files protected

If something breaks, check:
- Config path
- API key
- Cron execution
- Database connectivity

---

Enjoy ❤️
