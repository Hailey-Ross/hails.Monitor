CREATE DATABASE IF NOT EXISTS hails_monitor_db;

USE hails_monitor_db;

CREATE TABLE avatar_visits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    avatar_name VARCHAR(255),
    avatar_key VARCHAR(36) UNIQUE,
    region_name VARCHAR(255),
    first_seen DATETIME,
    last_seen DATETIME,
    INDEX (avatar_key),
    INDEX (region_name)
) ENGINE=MyISAM;
