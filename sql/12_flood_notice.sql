-- =============================================================================
-- ข้อมูลที่เจ้าหน้าที่ควรรู้ (ไม่ใช่จุดน้ำท่วม) — หน้า "ข้อมูลที่ควรรู้" (flood/notices)
-- ศูนย์พักพิง / สาธารณสุข / ไฟฟ้า-ประปา / การระบายน้ำ / โรงเรียนปิด / เส้นทาง
--
-- ปกติไม่ต้องรันเอง: ระบบสร้างตารางให้ตอนเปิดหน้าครั้งแรก (models/notice_model.php)
-- ถ้าบัญชีฐานข้อมูลของเว็บไม่มีสิทธิ์ CREATE ให้รันไฟล์นี้เอง (รันซ้ำได้)
--   php sql/apply_schema.php 12
-- =============================================================================

CREATE TABLE IF NOT EXISTS `flood_notice` (
  `notice_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category` VARCHAR(20) NOT NULL DEFAULT 'other' COMMENT 'shelter|health|utility|dam|school|road|other',
  `title` VARCHAR(200) NOT NULL,
  `detail` TEXT DEFAULT NULL,
  `amphoe_code` CHAR(4) DEFAULT NULL,
  `place` VARCHAR(200) DEFAULT NULL COMMENT 'สถานที่ เช่น อาคารสโมสรเทศบาล',
  `contact` VARCHAR(100) DEFAULT NULL COMMENT 'เบอร์/ผู้ประสาน',
  `verify` VARCHAR(12) NOT NULL DEFAULT 'unverified' COMMENT 'unverified|verified',
  `source_name` VARCHAR(150) DEFAULT NULL,
  `source_url` VARCHAR(500) DEFAULT NULL,
  `is_pinned` TINYINT(1) NOT NULL DEFAULT 0,
  `status` VARCHAR(10) NOT NULL DEFAULT 'active' COMMENT 'active|archived',
  `info_at` DATETIME DEFAULT NULL COMMENT 'เวลาของข้อมูล (เวลาโพสต์/ประกาศ)',
  `created_by` INT UNSIGNED DEFAULT NULL,
  `updated_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`notice_id`),
  KEY `idx_flood_notice_status` (`status`, `is_pinned`, `created_at`),
  KEY `idx_flood_notice_cat` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
