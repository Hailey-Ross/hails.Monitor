CREATE DATABASE IF NOT EXISTS `hailsmonitor` /*!40100 DEFAULT CHARACTER SET utf8 */;
USE `hailsmonitor`;

CREATE TABLE IF NOT EXISTS `avatar_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `avatar_key` char(36) NOT NULL,
  `region_name` varchar(191) NOT NULL,
  `visit_start` datetime NOT NULL,
  `visit_end` datetime NOT NULL,
  `heartbeat_count` int(10) unsigned NOT NULL DEFAULT '1',
  `duration_seconds` int(10) unsigned NOT NULL DEFAULT '0',
  `source_first_change_log_id` int(10) unsigned NOT NULL,
  `source_last_change_log_id` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_avatar_region_start` (`avatar_key`,`region_name`,`visit_start`),
  KEY `idx_region_start` (`region_name`,`visit_start`),
  KEY `idx_visit_start` (`visit_start`),
  KEY `idx_source_range` (`source_first_change_log_id`,`source_last_change_log_id`),
  KEY `idx_avatar_region` (`avatar_key`,`region_name`)
) ENGINE=InnoDB AUTO_INCREMENT=16309 DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `avatar_visits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `avatar_name` varchar(255) DEFAULT NULL,
  `avatar_key` varchar(36) DEFAULT NULL,
  `region_name` varchar(255) DEFAULT NULL,
  `first_seen` datetime DEFAULT NULL,
  `last_seen` datetime DEFAULT NULL,
  `last_name_verified_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `avatar_key` (`avatar_key`),
  KEY `avatar_key_2` (`avatar_key`),
  KEY `idx_avatar_visits_last_seen_desc` (`last_seen`),
  KEY `idx_avatar_visits_last_seen` (`last_seen`),
  KEY `idx_avatar_visits_region_lastseen` (`region_name`,`last_seen`)
) ENGINE=MyISAM AUTO_INCREMENT=10294 DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `change_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `table_name` varchar(255) DEFAULT NULL,
  `operation` varchar(50) DEFAULT NULL,
  `old_data` json DEFAULT NULL,
  `new_data` json DEFAULT NULL,
  `change_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `region_name_gc` varchar(255) GENERATED ALWAYS AS (json_unquote(json_extract(`new_data`,'$.region_name'))) STORED,
  `avatar_key_gc` varchar(36) GENERATED ALWAYS AS (json_unquote(json_extract(`new_data`,'$.avatar_key'))) STORED,
  PRIMARY KEY (`id`),
  KEY `idx_change_log_tbl_op_time` (`table_name`,`operation`,`change_time`),
  KEY `idx_change_log_region_avatar_time` (`region_name_gc`,`avatar_key_gc`,`change_time`),
  KEY `idx_change_log_tbl_op_id` (`table_name`,`operation`,`id`)
) ENGINE=MyISAM AUTO_INCREMENT=3486564 DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `compression_state` (
  `job_name` varchar(100) NOT NULL,
  `last_processed_id` int(10) unsigned NOT NULL DEFAULT '0',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`job_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `monitor_user_regions` (
  `user_id` int(10) unsigned NOT NULL,
  `region_name` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`,`region_name`),
  KEY `idx_region_name` (`region_name`),
  CONSTRAINT `fk_monitor_user_regions_user` FOREIGN KEY (`user_id`) REFERENCES `monitor_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `monitor_users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `display_name` varchar(150) DEFAULT NULL,
  `timezone` varchar(100) NOT NULL DEFAULT 'America/Denver',
  `can_view_all` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `role` enum('user','moderator','superadmin') NOT NULL DEFAULT 'user',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_monitor_username` (`username`),
  KEY `idx_monitor_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4;

DELIMITER //
CREATE PROCEDURE `purge_region_data`(
    IN p_region_name VARCHAR(255),
    IN p_preview_only TINYINT(1)
)
BEGIN
    DECLARE v_sessions_count INT DEFAULT 0;
    DECLARE v_user_regions_count INT DEFAULT 0;
    DECLARE v_scanners_count INT DEFAULT 0;
    DECLARE v_change_log_count INT DEFAULT 0;

    IF p_region_name IS NULL OR TRIM(p_region_name) = '' THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Region name cannot be null or empty';
    END IF;

    SELECT COUNT(*) INTO v_sessions_count
    FROM avatar_sessions
    WHERE region_name = p_region_name;

    SELECT COUNT(*) INTO v_user_regions_count
    FROM monitor_user_regions
    WHERE region_name = p_region_name;

    SELECT COUNT(*) INTO v_scanners_count
    FROM region_scanners
    WHERE region_name = p_region_name;

    SELECT COUNT(*) INTO v_change_log_count
    FROM change_log
    WHERE JSON_UNQUOTE(JSON_EXTRACT(new_data, '$.region_name')) = p_region_name
       OR JSON_UNQUOTE(JSON_EXTRACT(old_data, '$.region_name')) = p_region_name;

    IF p_preview_only = 1 THEN
        SELECT
            'PREVIEW' AS mode,
            p_region_name AS region_name,
            v_sessions_count AS avatar_sessions_rows,
            v_user_regions_count AS monitor_user_regions_rows,
            v_scanners_count AS region_scanners_rows,
            v_change_log_count AS change_log_rows;
    ELSE
        DELETE FROM avatar_sessions
        WHERE region_name = p_region_name;

        DELETE FROM monitor_user_regions
        WHERE region_name = p_region_name;

        DELETE FROM region_scanners
        WHERE region_name = p_region_name;

        DELETE FROM change_log
        WHERE JSON_UNQUOTE(JSON_EXTRACT(new_data, '$.region_name')) = p_region_name
           OR JSON_UNQUOTE(JSON_EXTRACT(old_data, '$.region_name')) = p_region_name;

        SELECT
            'DELETED' AS mode,
            p_region_name AS region_name,
            v_sessions_count AS avatar_sessions_rows,
            v_user_regions_count AS monitor_user_regions_rows,
            v_scanners_count AS region_scanners_rows,
            v_change_log_count AS change_log_rows;
    END IF;
END//
DELIMITER ;

CREATE TABLE IF NOT EXISTS `region_scanners` (
  `region_name` varchar(255) NOT NULL,
  `scanner_key` varchar(36) NOT NULL,
  `owner_key` varchar(36) DEFAULT NULL,
  `object_name` varchar(255) DEFAULT NULL,
  `started_at` datetime NOT NULL,
  `last_checkin` datetime NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`region_name`),
  KEY `idx_scanner_key` (`scanner_key`),
  KEY `idx_last_checkin` (`last_checkin`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @OLDTMP_SQL_MODE=@@SQL_MODE, SQL_MODE='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION';
DELIMITER //
CREATE TRIGGER log_changes_after_delete
AFTER DELETE ON avatar_visits
FOR EACH ROW
BEGIN
   INSERT INTO change_log(table_name, operation, old_data, new_data)
   VALUES (
      'avatar_visits', 
      'DELETE', 
      JSON_OBJECT('id', OLD.id, 'avatar_name', OLD.avatar_name, 'avatar_key', OLD.avatar_key, 
                  'region_name', OLD.region_name, 'first_seen', OLD.first_seen, 'last_seen', OLD.last_seen),
      NULL
   );
END//
DELIMITER ;
SET SQL_MODE=@OLDTMP_SQL_MODE;

SET @OLDTMP_SQL_MODE=@@SQL_MODE, SQL_MODE='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION';
DELIMITER //
CREATE TRIGGER log_changes_after_insert
AFTER INSERT ON avatar_visits
FOR EACH ROW
BEGIN
   INSERT INTO change_log(table_name, operation, old_data, new_data)
   VALUES (
      'avatar_visits', 
      'INSERT', 
      NULL,
      JSON_OBJECT('id', NEW.id, 'avatar_name', NEW.avatar_name, 'avatar_key', NEW.avatar_key, 
                  'region_name', NEW.region_name, 'first_seen', NEW.first_seen, 'last_seen', NEW.last_seen)
   );
END//
DELIMITER ;
SET SQL_MODE=@OLDTMP_SQL_MODE;

SET @OLDTMP_SQL_MODE=@@SQL_MODE, SQL_MODE='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION';
DELIMITER //
CREATE TRIGGER log_changes_after_update
AFTER UPDATE ON avatar_visits
FOR EACH ROW
BEGIN
   INSERT INTO change_log(table_name, operation, old_data, new_data)
   VALUES (
      'avatar_visits', 
      'UPDATE', 
      JSON_OBJECT('id', OLD.id, 'avatar_name', OLD.avatar_name, 'avatar_key', OLD.avatar_key, 
                  'region_name', OLD.region_name, 'first_seen', OLD.first_seen, 'last_seen', OLD.last_seen),
      JSON_OBJECT('id', NEW.id, 'avatar_name', NEW.avatar_name, 'avatar_key', NEW.avatar_key, 
                  'region_name', NEW.region_name, 'first_seen', NEW.first_seen, 'last_seen', NEW.last_seen)
   );
END//
DELIMITER ;
SET SQL_MODE=@OLDTMP_SQL_MODE;
