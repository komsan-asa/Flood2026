-- บุคลากรโรงพยาบาลที่ได้รับผลกระทบจากน้ำท่วม (ข้อมูลภายใน) — หน้า flood/staff
-- ระบบสร้างตารางเองครั้งแรกที่เปิดหน้า (Staff_Model::ensureTables) · ใช้ไฟล์นี้เมื่อบัญชีฐานข้อมูลไม่มีสิทธิ์ CREATE
--   php sql/apply_schema.php 17
-- ไม่มีคอลัมน์เลขบัตรประชาชน (ตั้งใจไม่เก็บ)

CREATE TABLE IF NOT EXISTS `flood_staff_source` (
                  `source_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `name` VARCHAR(150) NOT NULL,
                  `sheet_url` VARCHAR(500) NOT NULL,
                  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                  `last_sync_at` DATETIME DEFAULT NULL,
                  `last_rows` INT UNSIGNED DEFAULT NULL COMMENT 'จำนวนแถวในชีตรอบล่าสุด',
                  `last_new` INT UNSIGNED DEFAULT NULL COMMENT 'แถวใหม่ที่นำเข้ารอบล่าสุด',
                  `last_error` VARCHAR(500) DEFAULT NULL,
                  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`source_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `flood_staff` (
                  `staff_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `prefix` VARCHAR(40) DEFAULT NULL,
                  `full_name` VARCHAR(150) NOT NULL,
                  `name_key` VARCHAR(150) NOT NULL DEFAULT '',
                  `phone` VARCHAR(20) DEFAULT NULL,
                  `phone_key` CHAR(9) NOT NULL DEFAULT '',
                  `phone2` VARCHAR(20) DEFAULT NULL,
                  `email` VARCHAR(150) DEFAULT NULL,
                  `employ_type` VARCHAR(100) DEFAULT NULL,
                  `position` VARCHAR(150) DEFAULT NULL,
                  `department` VARCHAR(150) DEFAULT NULL,
                  `work_type` VARCHAR(100) DEFAULT NULL,
                  `addr_card` VARCHAR(300) DEFAULT NULL,
                  `addr_now` VARCHAR(300) DEFAULT NULL,
                  `victim` VARCHAR(200) DEFAULT NULL,
                  `impact` VARCHAR(200) DEFAULT NULL,
                  `travel` VARCHAR(200) DEFAULT NULL,
                  `work_status` VARCHAR(200) DEFAULT NULL,
                  `shift_status` VARCHAR(200) DEFAULT NULL,
                  `work_problem` TEXT DEFAULT NULL,
                  `has_shelter` VARCHAR(300) DEFAULT NULL,
                  `shelter_need` VARCHAR(300) DEFAULT NULL,
                  `current_aid` VARCHAR(300) DEFAULT NULL,
                  `help_need` TEXT DEFAULT NULL,
                  `clothing` VARCHAR(300) DEFAULT NULL,
                  `toiletries` VARCHAR(300) DEFAULT NULL,
                  `damage` TEXT DEFAULT NULL,
                  `photos` TEXT DEFAULT NULL,
                  `suggest` TEXT DEFAULT NULL,
                  `note` TEXT DEFAULT NULL,
                  `level` VARCHAR(10) NOT NULL DEFAULT 'unknown' COMMENT 'severe|moderate|mild|none|unknown',
                  `flags` VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'need_shelter,cant_work,stranded,home_hit,need_help',
                  `follow_status` VARCHAR(10) NOT NULL DEFAULT 'new' COMMENT 'new|contacted|helping|done|no_need',
                  `follow_note` TEXT DEFAULT NULL,
                  `followed_by` INT UNSIGNED DEFAULT NULL,
                  `followed_at` DATETIME DEFAULT NULL,
                  `resp_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                  `sources` VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'source_id ที่มีคำตอบ คั่นด้วย ,',
                  `first_at` DATETIME DEFAULT NULL,
                  `last_at` DATETIME DEFAULT NULL,
                  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  `updated_at` DATETIME DEFAULT NULL,
                  PRIMARY KEY (`staff_id`),
                  KEY `idx_flood_staff_phone` (`phone_key`),
                  KEY `idx_flood_staff_name` (`name_key`),
                  KEY `idx_flood_staff_level` (`level`, `follow_status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `flood_staff_resp` (
                  `resp_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `source_id` INT UNSIGNED NOT NULL,
                  `staff_id` INT UNSIGNED DEFAULT NULL,
                  `row_key` CHAR(40) NOT NULL COMMENT 'sha1(แหล่ง|เวลา|ชื่อ|เบอร์) กันนำเข้าซ้ำ',
                  `answered_at` DATETIME DEFAULT NULL,
                  `answers` MEDIUMTEXT DEFAULT NULL COMMENT 'JSON {หัวคอลัมน์: คำตอบ} ไม่มีเลขบัตรประชาชน',
                  `imported_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`resp_id`),
                  UNIQUE KEY `uq_flood_staff_resp_key` (`row_key`),
                  KEY `idx_flood_staff_resp_staff` (`staff_id`, `answered_at`),
                  KEY `idx_flood_staff_resp_source` (`source_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
