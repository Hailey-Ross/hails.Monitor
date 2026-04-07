# hails.Monitor

Monitor avatar activity in Second Life, process it into structured sessions, and visualize it through a secure web dashboard.

This project has evolved significantly from earlier versions and now includes:
- Session-based tracking (not just raw visits)
- Automated compression jobs
- A full authentication system
- A live dashboard UI
- Multiuser support

---

## 🖼️ Preview

<img width="1920" height="964" alt="web-ui-preview" src="https://github.com/user-attachments/assets/c5088f59-13de-459a-bc9e-cf7273419558" />

### 📊 Compression Stats  
*Prior to this update, my database had ballooned to around 1.2~1.3 GiB. After running the compression cronjob, the entire database is now down to 7.8 MiB*

<img width="1570" height="498" alt="compression stats" src="https://github.com/user-attachments/assets/7bec3883-7154-4353-a721-3f551f677893" />

### 💽 Table Size Breakdown
*Here is the breakdown of each tables size after the compression was run.*

<img width="270" height="270" alt="db-size-breakdown" src="https://github.com/user-attachments/assets/7af6a56e-0110-4950-85c6-64dba45cf6c2" />

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
- HTTPS/SSL (strongly recommended for security)

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
- `region_scanners` (active scanner connected)
- `compression_state` (stores last compressed rows/date/time)

---

### 4. Processing Layer (CRON REQUIRED)
- `hailsDBCompressCron.php` (compresses change_log entries)
- `hails.CronServer.lsl` (lookup empty names / verify names haven't changed)

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
- Avatar name verification (lookup users not seen recently to check for name changes)

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

### 9. Setup / Access Dashboard

#### 9.1 Generate a Password Hash
Run the following command in your CLI:

```bash
php -r 'echo password_hash("YourStrongPasswordHere", PASSWORD_DEFAULT), PHP_EOL;'
```

---

#### 9.2 Insert Super User

Ensure the username matches the super admin defined in \`config.php\`, then run the following SQL:

```sql
INSERT INTO monitor_users (
    username,
    password_hash,
    display_name,
    timezone,
    can_view_all,
    is_active
) VALUES (
    'YOUR_USERNAME_HERE',
    'PASTE_HASH_HERE',
    'DISPLAY_NAME_HERE',
    'America/Boston',
    1,
    1
);
```

**What makes this a super admin?**

- `can_view_all = 1` → Can view all regions  
- `is_active = 1` → Account is enabled  
- Must be defined in `config.php`  

---

#### 9.3 Access the Dashboard

Navigate to:

```text
https://yourdomain.com/index.html
```

Log in using the credentials for the user you just created.

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
5. `hails.CronServer.lsl` → runs Maintenance tasks on the DB
6. Dashboard → displays processed data

---

## 🧹 Maintenance Notes

- Monitor log file sizes (`phpcron.txt`)
- Ensure cron jobs are running
  - `hails.CronServer.lsl` (every 2 hours)
    - Checks for blank or NULL value avatar_names
    - If no empty names are found, checks avatar_names against their UUID in world to compare for name changes
      - After 90 days of not beeing seen
      - Previously verified over 150 days ago
- Periodically verify:
  - `compression_state`
  - session growth
- Clean old logs if needed
- Purge regions using SQL Procedure
  - `CALL purge_region_data('Region Name', 1);` To preview any changes that would be made
  - `CALL purge_region_data('Region Name', 0);` To actually delete the region
    - This feature is **NOT** rollback friendly at all.
- You will need to Optimize the change_logs table
  - This does NOT need to be run daily, perhaps weekly
    - `OPTIMIZE TABLE change_log;`

---

## ⚠️ Common Mistakes

- ❌ Putting `config.php` in public directory
- ❌ Forgetting to set API key everywhere
- ❌ Not running cron jobs
- ❌ Incorrect file paths to config
- ❌ Missing database indexes
- ❌ Not rotating API-Keys or Passwords when exposed

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

Enjoy,  
Hails❤️
