-- =============================================================================
-- คำขอความช่วยเหลือ (SOS) = ใบงาน
-- สถานะ: new → verified (โทรยืนยัน) → assigned (มอบหมายทีม) → in_progress → done / cancelled
-- =============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `flood_help` (
  `help_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ref_code` VARCHAR(20) NOT NULL COMMENT 'SOS-ปปดดวว-ลำดับ',
  `needs` VARCHAR(100) NOT NULL COMMENT 'คั่น comma: evacuate,medicine,sick,food,other',
  `detail` TEXT DEFAULT NULL,
  `people_count` SMALLINT UNSIGNED DEFAULT NULL COMMENT 'จำนวนคนในบ้าน/ที่ต้องช่วย',
  `vulnerable_flags` VARCHAR(100) DEFAULT NULL COMMENT 'คั่น comma: bedridden,elderly,child,pregnant,disabled',
  `lat` DECIMAL(10,7) DEFAULT NULL,
  `lng` DECIMAL(10,7) DEFAULT NULL,
  `accuracy_m` INT UNSIGNED DEFAULT NULL,
  `address` VARCHAR(255) DEFAULT NULL COMMENT 'ที่อยู่/จุดสังเกต',
  `amphoe_code` CHAR(4) DEFAULT NULL,
  `tambon_code` CHAR(6) DEFAULT NULL,
  `requester_name` VARCHAR(150) NOT NULL,
  `requester_phone` VARCHAR(30) NOT NULL,
  `priority` VARCHAR(10) NOT NULL DEFAULT 'normal' COMMENT 'urgent|high|normal',
  `status` VARCHAR(20) NOT NULL DEFAULT 'new' COMMENT 'new|verified|assigned|in_progress|done|cancelled',
  `team_id` INT UNSIGNED DEFAULT NULL,
  `person_id` INT UNSIGNED DEFAULT NULL COMMENT 'ผูกกับทะเบียนกลุ่มเปราะบาง (ถ้ามี)',
  `result_note` VARCHAR(255) DEFAULT NULL COMMENT 'ผลการช่วยเหลือ / เหตุผลที่ยกเลิก',
  `source` VARCHAR(10) NOT NULL DEFAULT 'public' COMMENT 'public (ประชาชนส่งเอง)|phone (เจ้าหน้าที่รับสาย)',
  `verified_by` INT UNSIGNED DEFAULT NULL,
  `verified_at` DATETIME DEFAULT NULL,
  `assigned_at` DATETIME DEFAULT NULL,
  `done_at` DATETIME DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `ip` VARCHAR(45) NOT NULL DEFAULT '',
  `user_agent` VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`help_id`),
  UNIQUE KEY `uk_flood_help_ref` (`ref_code`),
  KEY `idx_flood_help_status` (`status`, `priority`, `created_at`),
  KEY `idx_flood_help_team` (`team_id`, `status`),
  KEY `idx_flood_help_area` (`amphoe_code`, `tambon_code`),
  KEY `idx_flood_help_phone` (`requester_phone`, `created_at`),
  KEY `idx_flood_help_ip` (`ip`, `created_at`),
  CONSTRAINT `fk_flood_help_team` FOREIGN KEY (`team_id`) REFERENCES `flood_team` (`team_id`) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `fk_flood_help_amphoe` FOREIGN KEY (`amphoe_code`) REFERENCES `flood_amphoe` (`amphoe_code`) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `fk_flood_help_tambon` FOREIGN KEY (`tambon_code`) REFERENCES `flood_tambon` (`tambon_code`) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `fk_flood_help_person` FOREIGN KEY (`person_id`) REFERENCES `flood_vulnerable` (`person_id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ไทม์ไลน์ของใบงาน (ใครทำอะไร เมื่อไหร่)
CREATE TABLE IF NOT EXISTS `flood_help_log` (
  `log_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `help_id` INT UNSIGNED NOT NULL,
  `action` VARCHAR(20) NOT NULL COMMENT 'create|verify|assign|start|done|cancel|reopen|note|priority',
  `status_from` VARCHAR(20) DEFAULT NULL,
  `status_to` VARCHAR(20) DEFAULT NULL,
  `team_id` INT UNSIGNED DEFAULT NULL,
  `note` TEXT DEFAULT NULL,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `user_name` VARCHAR(150) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`),
  KEY `idx_flood_help_log_help` (`help_id`, `created_at`),
  CONSTRAINT `fk_flood_help_log_help` FOREIGN KEY (`help_id`) REFERENCES `flood_help` (`help_id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
