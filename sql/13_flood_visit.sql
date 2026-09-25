-- =============================================================================
-- นับผู้เข้าชมหน้าแผนที่สาธารณะ / ผู้ที่กำลังดูอยู่ (ไม่เก็บ IP — เก็บเฉพาะ hash ของคุกกี้สุ่ม)
-- ปกติไม่ต้องรันเอง: ระบบสร้างตารางให้ตอนมีคนเปิดหน้าแผนที่ครั้งแรก (models/visit_model.php)
--   php sql/apply_schema.php 13
-- =============================================================================

CREATE TABLE IF NOT EXISTS `flood_visit_daily` (
  `day` DATE NOT NULL,
  `views` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'จำนวนครั้งที่เข้าชม',
  `visitors` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'ผู้เข้าชมไม่ซ้ำในวันนั้น',
  PRIMARY KEY (`day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `flood_visit_online` (
  `vid` CHAR(40) NOT NULL COMMENT 'sha1 ของคุกกี้ skvid',
  `last_seen` DATETIME NOT NULL,
  `last_view` DATETIME NOT NULL,
  `seen_day` DATE NOT NULL,
  PRIMARY KEY (`vid`),
  KEY `idx_flood_visit_online_seen` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
