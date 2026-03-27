# SQL Directory Overview

## 📌 What This Database Does

This database is designed to track and analyze avatar activity over time.

At its core, the system:
- Captures real-time changes to avatar data
- Logs all modifications in a centralized `change_log` table
- Aggregates raw activity into session-based data for performance and analysis
- Provides a foundation for monitoring, reporting, and data optimization

Most of the logic in this directory supports that workflow, with `run_me.sql` establishing the full structure and the remaining scripts helping analyze and maintain the data.

---

> ⚠️ **Important:**  
> You must run `run_me.sql` before executing any other script in this directory. Nothing else will function correctly without it.

---

## 🧱 `run_me.sql` (REQUIRED FIRST)

**Purpose:**  
This is the **core setup script**. It creates the entire database structure, including all tables, indexes, and triggers required for the system to function.

**What it does:**
- Creates the `hailsmonitor` database if it does not already exist
- Defines all primary tables:
  - `avatar_visits` – Tracks the most recent state of each avatar
  - `change_log` – Stores a full history of changes (INSERT, UPDATE, DELETE)
  - `avatar_sessions` – Aggregates visit activity into sessions
  - `compression_state` – Tracks progress of compression jobs
  - `monitor_users` – Stores application users
  - `monitor_user_regions` – Maps users to regions they can access
- Adds indexes for performance optimization
- Creates **triggers** on `avatar_visits`:
  - Automatically logs all INSERT, UPDATE, and DELETE operations into `change_log`
- Establishes the `change_log` system, which is the backbone for tracking all data changes and powering analytics

**When to run:**
- ✅ First-time setup (required)
- ✅ Rebuilding the database from scratch

**Do not skip this step.** All other scripts depend on these tables and triggers existing.

---

## 📊 `change_log_stats.sql`

**Purpose:**  
Provides a **high-level summary** of activity in the `change_log` table.

**What it shows:**
- Total number of logged changes
- Number of rows affecting `avatar_visits`
- Breakdown of:
  - INSERT/UPDATE operations
  - DELETE operations

**When to use:**
- Monitoring overall system activity
- Verifying that triggers are working
- Getting a quick health check of change volume

---

## 📊 `changelog_op_stats.sql`

**Purpose:**  
Breaks down `change_log` activity by **table and operation type**.

**What it shows:**
- Counts grouped by:
  - `table_name`
  - `operation` (INSERT, UPDATE, DELETE)
- Sorted by highest activity

**When to use:**
- Identifying which operations are most frequent
- Debugging unexpected spikes in activity
- Understanding workload distribution

---

## 🗜️ `compression_ratio_query.sql`

**Purpose:**  
Measures the effectiveness of session compression.

**What it shows:**
- Total number of sessions
- Total raw rows represented
- Calculated **compression ratio**

**When to use:**
- Evaluating performance improvements from session aggregation
- Validating compression jobs
- Monitoring data efficiency over time

---

## ⚙️ `optimize_table.sql`

**Purpose:**  
Performs maintenance on the `change_log` table.

**What it does:**
- Runs the following command:
  `OPTIMIZE TABLE change_log;`

**When to use:**
- After large numbers of deletes or updates
- Periodically for performance maintenance
- When query performance begins to degrade noticeably

---

# Recommended Workflow

1. **Initialize the database**
   - Run `run_me.sql`

2. **Complete Setup steps**
   - Complete the remaining setup steps in the main [README](https://github.com/Hailey-Ross/hails.Monitor/blob/main/database_version/README.md)

3. **Verify logging is working**
   - Run `change_log_stats.sql`

4. **Analyze activity (optional)**
   - Run `changelog_op_stats.sql`

5. **Evaluate compression**
   - Run `compression_ratio_query.sql`

6. **Perform maintenance (Monthly / Biweekly)**
   - Run `optimize_table.sql`
