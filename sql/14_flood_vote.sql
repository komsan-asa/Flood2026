-- =============================================================================
-- Like / Not Like ของพื้นที่ประกาศ (หน้าแผนที่สาธารณะ → รายละเอียดพื้นที่)
-- 1 เบราว์เซอร์ = 1 เสียงต่อพื้นที่ · ไม่เก็บชื่อ/IP (เก็บเฉพาะ hash)
--
-- ปกติไม่ต้องรันเอง: ระบบสร้างตารางให้ตอนมีคนเปิดรายละเอียดพื้นที่ครั้งแรก (models/vote_model.php)
-- ถ้าบัญชีฐานข้อมูลของเว็บไม่มีสิทธิ์ CREATE ให้รันไฟล์นี้เอง (รันซ้ำได้)
--   php sql/apply_schema.php 14
-- =============================================================================

CREATE TABLE IF NOT EXISTS `flood_vote` (
  `target_type` VARCHAR(10) NOT NULL COMMENT 'zone = พื้นที่ประกาศ',
  `target_id` INT UNSIGNED NOT NULL,
  `voter` CHAR(40) NOT NULL COMMENT 'sha1 ของคุกกี้ skvid (ไม่เก็บชื่อ)',
  `vote` TINYINT NOT NULL DEFAULT 0 COMMENT '1 = Like, -1 = Not Like, 0 = ยกเลิกแล้ว',
  `ip_hash` CHAR(40) NOT NULL DEFAULT '' COMMENT 'hash ของ IP ผสมค่าลับ ใช้กันปั่นยอดเท่านั้น',
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`target_type`, `target_id`, `voter`),
  KEY `idx_flood_vote_ip` (`ip_hash`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
