-- =============================================================================
-- Flood Master: อำเภอ/ตำบล (จ.สระแก้ว), ทีมช่วยเหลือ, ผู้ใช้งาน
-- Database: db_flood
-- รองรับ MySQL 5.7+ / MariaDB 10.x
-- =============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `flood_amphoe` (
  `amphoe_code` CHAR(4) NOT NULL COMMENT 'รหัสอำเภอ (กรมการปกครอง) เช่น 2706',
  `name` VARCHAR(100) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`amphoe_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `flood_tambon` (
  `tambon_code` CHAR(6) NOT NULL COMMENT 'รหัสตำบล เช่น 270601',
  `amphoe_code` CHAR(4) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `zipcode` VARCHAR(5) DEFAULT NULL,
  `lat` DECIMAL(10,7) DEFAULT NULL COMMENT 'จุดกึ่งกลางโดยประมาณ — ใช้เลื่อนแผนที่',
  `lng` DECIMAL(10,7) DEFAULT NULL,
  PRIMARY KEY (`tambon_code`),
  KEY `idx_flood_tambon_amphoe` (`amphoe_code`),
  CONSTRAINT `fk_flood_tambon_amphoe` FOREIGN KEY (`amphoe_code`) REFERENCES `flood_amphoe` (`amphoe_code`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `flood_team` (
  `team_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `team_type` VARCHAR(20) NOT NULL DEFAULT 'rescue' COMMENT 'hospital|rescue|local_gov|military|volunteer|other',
  `phone` VARCHAR(30) DEFAULT NULL,
  `amphoe_code` CHAR(4) DEFAULT NULL COMMENT 'พื้นที่รับผิดชอบหลัก',
  `vehicles` VARCHAR(255) DEFAULT NULL COMMENT 'เช่น เรือท้องแบน 2 ลำ, รถยกสูง 1 คัน',
  `note` VARCHAR(255) DEFAULT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`team_id`),
  KEY `idx_flood_team_amphoe` (`amphoe_code`),
  CONSTRAINT `fk_flood_team_amphoe` FOREIGN KEY (`amphoe_code`) REFERENCES `flood_amphoe` (`amphoe_code`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `flood_user` (
  `user_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `loginname` VARCHAR(50) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL COMMENT 'password_hash() (bcrypt)',
  `name` VARCHAR(150) NOT NULL,
  `role` VARCHAR(30) NOT NULL DEFAULT 'viewer' COMMENT 'super_admin|admin|officer|team|viewer',
  `team_id` INT UNSIGNED DEFAULT NULL COMMENT 'สังกัดทีมช่วยเหลือ (role=team ต้องมี)',
  `org_name` VARCHAR(200) DEFAULT NULL COMMENT 'หน่วยงาน',
  `phone` VARCHAR(30) DEFAULT NULL,
  `must_change_password` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = ต้องเปลี่ยนรหัสผ่านก่อนใช้งาน',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `last_login_at` DATETIME DEFAULT NULL,
  `last_seen_at` DATETIME DEFAULT NULL COMMENT 'ใช้งานล่าสุด — หน้า ผู้ใช้งานออนไลน์',
  `last_ip` VARCHAR(45) DEFAULT NULL,
  `last_page` VARCHAR(120) DEFAULT NULL COMMENT 'หน้าจอที่เปิดอยู่ล่าสุด',
  `session_revoked_at` DATETIME DEFAULT NULL COMMENT 'ผู้ดูแลสั่งออกจากระบบ — session ที่ล็อกอินก่อนเวลานี้ถูกตัด',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uk_flood_user_loginname` (`loginname`),
  KEY `idx_flood_user_team` (`team_id`),
  KEY `idx_flood_user_seen` (`last_seen_at`),
  CONSTRAINT `fk_flood_user_team` FOREIGN KEY (`team_id`) REFERENCES `flood_team` (`team_id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
