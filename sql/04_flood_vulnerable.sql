-- =============================================================================
-- ทะเบียนกลุ่มเปราะบาง (ผู้ป่วยติดเตียง ผู้สูงอายุ ผู้พิการ ฟอกไต ฯลฯ)
-- ข้อมูลสุขภาพ — เห็นเฉพาะ admin/officer เท่านั้น ไม่แสดงบนหน้าสาธารณะ
-- ระบบเทียบพิกัดกับพื้นที่ประกาศเพื่อเตือนว่าใครอยู่ในพื้นที่น้ำท่วม
-- =============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `flood_vulnerable` (
  `person_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `hn` VARCHAR(20) DEFAULT NULL COMMENT 'HN โรงพยาบาล (ถ้ามี)',
  `name` VARCHAR(150) NOT NULL,
  `sex` CHAR(1) DEFAULT NULL COMMENT 'M|F',
  `birth_date` DATE DEFAULT NULL,
  `vuln_groups` VARCHAR(150) NOT NULL COMMENT 'คั่น comma: bedridden,elderly,disabled,dialysis,oxygen,pregnant,infant,psychiatric,other',
  `mobility` VARCHAR(20) DEFAULT NULL COMMENT 'walk|assisted|wheelchair|bedridden',
  `medical_needs` VARCHAR(255) DEFAULT NULL COMMENT 'เช่น ใช้ออกซิเจน 24 ชม. / ฟอกไตทุกอังคาร-ศุกร์',
  `address` VARCHAR(255) DEFAULT NULL,
  `moo` VARCHAR(10) DEFAULT NULL,
  `amphoe_code` CHAR(4) DEFAULT NULL,
  `tambon_code` CHAR(6) DEFAULT NULL,
  `lat` DECIMAL(10,7) DEFAULT NULL,
  `lng` DECIMAL(10,7) DEFAULT NULL,
  `phone` VARCHAR(30) DEFAULT NULL,
  `caregiver_name` VARCHAR(150) DEFAULT NULL,
  `caregiver_phone` VARCHAR(30) DEFAULT NULL,
  `evac_status` VARCHAR(20) NOT NULL DEFAULT 'normal' COMMENT 'normal|alerted|evacuated|shelter_in_place|admitted',
  `evac_place` VARCHAR(200) DEFAULT NULL COMMENT 'ที่พักพิง/สถานที่อพยพไป',
  `last_checked_at` DATETIME DEFAULT NULL,
  `note` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`person_id`),
  KEY `idx_flood_vulnerable_hn` (`hn`),
  KEY `idx_flood_vulnerable_area` (`amphoe_code`, `tambon_code`),
  KEY `idx_flood_vulnerable_evac` (`evac_status`),
  CONSTRAINT `fk_flood_vulnerable_amphoe` FOREIGN KEY (`amphoe_code`) REFERENCES `flood_amphoe` (`amphoe_code`) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `fk_flood_vulnerable_tambon` FOREIGN KEY (`tambon_code`) REFERENCES `flood_tambon` (`tambon_code`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ประวัติการติดตาม/อพยพรายคน
CREATE TABLE IF NOT EXISTS `flood_vulnerable_log` (
  `log_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `person_id` INT UNSIGNED NOT NULL,
  `evac_status` VARCHAR(20) NOT NULL,
  `evac_place` VARCHAR(200) DEFAULT NULL,
  `note` TEXT DEFAULT NULL,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `user_name` VARCHAR(150) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`),
  KEY `idx_flood_vulnerable_log_person` (`person_id`, `created_at`),
  CONSTRAINT `fk_flood_vulnerable_log_person` FOREIGN KEY (`person_id`) REFERENCES `flood_vulnerable` (`person_id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
