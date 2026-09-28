-- ห้องสถานการณ์ SAT ของโรงพยาบาล (หน้า sat) — ดู models/sat_model.php
-- ระบบสร้างตารางเองครั้งแรกที่เปิดหน้า (Sat_Model::ensureTables) · ใช้ไฟล์นี้เมื่อบัญชีฐานข้อมูลไม่มีสิทธิ์ CREATE
--   php sql/apply_schema.php 19
-- ข้อมูลตั้งต้น (โรงพยาบาล 9 อำเภอ + รพ.สต. ที่ สสจ. แจ้งปิด/ย้าย + เส้นทางตัวอย่าง) ระบบใส่ให้เองตอนเปิดหน้าครั้งแรก

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `flood_sat_setting` (
  `skey` VARCHAR(50) NOT NULL,
  `sval` TEXT DEFAULT NULL,
  `updated_by` INT UNSIGNED DEFAULT NULL,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`skey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `flood_sat_item` (
  `code` VARCHAR(30) NOT NULL,
  `status` VARCHAR(10) NOT NULL DEFAULT '' COMMENT 'green|yellow|orange|red',
  `note` TEXT DEFAULT NULL,
  `updated_by` INT UNSIGNED DEFAULT NULL,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `flood_sat_item_log` (
  `log_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(30) NOT NULL,
  `status` VARCHAR(10) NOT NULL DEFAULT '',
  `note` TEXT DEFAULT NULL,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`),
  KEY `idx_flood_sat_item_log_code` (`code`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `flood_sat_facility` (
  `facility_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `hcode` VARCHAR(10) DEFAULT NULL COMMENT 'รหัสหน่วยบริการ 5 หลัก (ถ้ามี)',
  `name` VARCHAR(200) NOT NULL,
  `ftype` VARCHAR(10) NOT NULL DEFAULT 'hs' COMMENT 'main|hospital|pcc|hs|other',
  `amphoe_code` CHAR(4) DEFAULT NULL,
  `tambon_code` CHAR(6) DEFAULT NULL,
  `lat` DECIMAL(10,7) DEFAULT NULL,
  `lng` DECIMAL(10,7) DEFAULT NULL,
  `loc_approx` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = พิกัดจุดกลางตำบล/อำเภอโดยประมาณ',
  `phone` VARCHAR(100) DEFAULT NULL,
  `contact` VARCHAR(150) DEFAULT NULL COMMENT 'ผู้ประสานงาน',
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `note` VARCHAR(500) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` INT UNSIGNED DEFAULT NULL,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`facility_id`),
  KEY `idx_flood_sat_facility_amphoe` (`amphoe_code`),
  KEY `idx_flood_sat_facility_hcode` (`hcode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `flood_sat_facility_log` (
  `log_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `facility_id` INT UNSIGNED NOT NULL,
  `status` VARCHAR(10) NOT NULL COMMENT 'green|yellow|orange|red',
  `service` VARCHAR(12) NOT NULL DEFAULT 'open' COMMENT 'open|partial|relocated|closed|unreachable',
  `relocated_to` VARCHAR(200) DEFAULT NULL,
  `road_access` VARCHAR(300) DEFAULT NULL,
  `staff_issue` VARCHAR(300) DEFAULT NULL,
  `utility_issue` VARCHAR(300) DEFAULT NULL,
  `patient_note` VARCHAR(300) DEFAULT NULL,
  `needs` VARCHAR(300) DEFAULT NULL,
  `contact_person` VARCHAR(150) DEFAULT NULL,
  `source` VARCHAR(10) NOT NULL DEFAULT 'call' COMMENT 'call|visit|news',
  `note` TEXT DEFAULT NULL,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`),
  KEY `idx_flood_sat_facility_log_f` (`facility_id`, `log_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `flood_sat_route` (
  `route_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rtype` VARCHAR(10) NOT NULL DEFAULT 'refer' COMMENT 'in|out|refer',
  `name` VARCHAR(200) NOT NULL,
  `destination` VARCHAR(200) DEFAULT NULL,
  `segments` VARCHAR(300) DEFAULT NULL COMMENT 'ทางหลวง:กม.เริ่ม-กม.สิ้นสุด คั่นด้วย ; เช่น 33:215-245; 359:0-100',
  `is_backup` TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `note` VARCHAR(500) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` INT UNSIGNED DEFAULT NULL,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`route_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `flood_sat_route_check` (
  `check_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `route_id` INT UNSIGNED NOT NULL,
  `result` VARCHAR(10) NOT NULL COMMENT 'pass|slow|high|blocked',
  `note` VARCHAR(500) DEFAULT NULL,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`check_id`),
  KEY `idx_flood_sat_route_check_r` (`route_id`, `check_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `flood_sat_sitrep` (
  `sitrep_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `report_no` INT UNSIGNED NOT NULL,
  `report_at` DATETIME NOT NULL,
  `overall` VARCHAR(10) NOT NULL DEFAULT '',
  `body` MEDIUMTEXT NOT NULL,
  `data_json` MEDIUMTEXT DEFAULT NULL,
  `next_at` DATETIME DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`sitrep_id`),
  KEY `idx_flood_sat_sitrep_no` (`report_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

