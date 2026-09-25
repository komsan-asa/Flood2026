-- =============================================================================
-- รายงานจุดน้ำท่วมจากประชาชน (รอเจ้าหน้าที่ตรวจก่อนประกาศ) + ไฟล์แนบ
-- ชื่อ/เบอร์ผู้แจ้งเห็นเฉพาะเจ้าหน้าที่ ไม่แสดงบนหน้าสาธารณะ
-- =============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `flood_report` (
  `report_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ref_code` VARCHAR(20) NOT NULL COMMENT 'FR-ปปดดวว-ลำดับ เช่น FR-690924-002',
  `lat` DECIMAL(10,7) NOT NULL,
  `lng` DECIMAL(10,7) NOT NULL,
  `accuracy_m` INT UNSIGNED DEFAULT NULL COMMENT 'ความแม่น GPS (เมตร)',
  `loc_method` VARCHAR(10) NOT NULL DEFAULT 'gps' COMMENT 'gps|pin (ปักหมุดเองบนแผนที่)',
  `depth` VARCHAR(20) NOT NULL COMMENT 'ankle|knee|waist|above_waist',
  `extent` VARCHAR(20) NOT NULL COMMENT 'spot|road|soi|wide',
  `houses` VARCHAR(10) DEFAULT NULL COMMENT 'none|1_3|4_10|10_plus',
  `vehicle` VARCHAR(20) DEFAULT NULL COMMENT 'car|high|none|boat',
  `trend` VARCHAR(10) DEFAULT NULL COMMENT 'rising|steady|falling',
  `place_note` VARCHAR(255) DEFAULT NULL COMMENT 'จุดสังเกต/รายละเอียด',
  `reporter_name` VARCHAR(150) NOT NULL,
  `reporter_phone` VARCHAR(30) NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|verified|rejected',
  `zone_id` INT UNSIGNED DEFAULT NULL COMMENT 'พื้นที่ประกาศที่ใช้รายงานนี้',
  `review_note` VARCHAR(255) DEFAULT NULL,
  `reviewed_by` INT UNSIGNED DEFAULT NULL,
  `reviewed_at` DATETIME DEFAULT NULL,
  `ip` VARCHAR(45) NOT NULL DEFAULT '',
  `user_agent` VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`report_id`),
  UNIQUE KEY `uk_flood_report_ref` (`ref_code`),
  KEY `idx_flood_report_status` (`status`, `created_at`),
  KEY `idx_flood_report_zone` (`zone_id`),
  KEY `idx_flood_report_ip` (`ip`, `created_at`),
  KEY `idx_flood_report_phone` (`reporter_phone`, `created_at`),
  CONSTRAINT `fk_flood_report_zone` FOREIGN KEY (`zone_id`) REFERENCES `flood_zone` (`zone_id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- รูปถ่ายที่แนบมากับรายงาน/คำขอความช่วยเหลือ
-- ไฟล์อยู่ใต้ public/uploads/flood/ ซึ่งปิดทางเข้าตรง — ดูได้ผ่าน flood/attachment/<id> ที่ตรวจสิทธิ์ก่อน
CREATE TABLE IF NOT EXISTS `flood_attachment` (
  `attachment_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ref_type` VARCHAR(20) NOT NULL COMMENT 'report|help|zone (zone = ภาพประกอบพื้นที่ แสดงบนแผนที่สาธารณะ)',
  `ref_id` INT UNSIGNED NOT NULL,
  `file_path` VARCHAR(200) NOT NULL COMMENT 'path ใต้ public/uploads/flood/',
  `orig_name` VARCHAR(200) DEFAULT NULL,
  `mime` VARCHAR(50) NOT NULL DEFAULT 'image/jpeg',
  `size_bytes` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`attachment_id`),
  KEY `idx_flood_attachment_ref` (`ref_type`, `ref_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
