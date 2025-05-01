/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8 */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

CREATE DATABASE IF NOT EXISTS `hailsmonitor` /*!40100 DEFAULT CHARACTER SET utf8 */;
USE `hailsmonitor`;

CREATE TABLE IF NOT EXISTS `avatar_visits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `avatar_name` varchar(255) DEFAULT NULL,
  `avatar_key` varchar(36) DEFAULT NULL,
  `region_name` varchar(255) DEFAULT NULL,
  `first_seen` datetime DEFAULT NULL,
  `last_seen` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `avatar_key` (`avatar_key`),
  KEY `avatar_key_2` (`avatar_key`)
) ENGINE=MyISAM AUTO_INCREMENT=1122 DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `change_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `table_name` varchar(255) DEFAULT NULL,
  `operation` varchar(50) DEFAULT NULL,
  `old_data` json DEFAULT NULL,
  `new_data` json DEFAULT NULL,
  `change_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=135067 DEFAULT CHARSET=utf8;

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

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
