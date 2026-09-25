-- =============================================================================
-- จำรหัสพื้นที่ของระบบต้นทางที่นำเข้ามาแล้ว (ผู้ดูแลระบบ → พื้นที่ประกาศ → นำเข้าจาก arankub.com)
-- นำเข้าซ้ำจะข้ามพื้นที่ที่มีในตารางนี้ · raw_json เก็บข้อมูลต้นฉบับตอนนำเข้าไว้ตรวจย้อนหลัง
--
-- ปกติไม่ต้องรันเอง: ระบบสร้างตารางนี้ให้ตอนผู้ดูแลเปิดหน้านำเข้าครั้งแรก
-- ถ้าบัญชีฐานข้อมูลของเว็บไม่มีสิทธิ์ CREATE ให้รันไฟล์นี้เอง (รันซ้ำได้)
--   php sql/apply_schema.php 10
-- =============================================================================

CREATE TABLE IF NOT EXISTS `flood_zone_import` (
  `import_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source` VARCHAR(20) NOT NULL COMMENT 'ระบบต้นทาง เช่น arankub',
  `ext_id` VARCHAR(64) NOT NULL COMMENT 'รหัสพื้นที่ของต้นทาง',
  `zone_id` INT UNSIGNED DEFAULT NULL COMMENT 'พื้นที่ของเราที่สร้างจากรายการนี้',
  `ext_name` VARCHAR(200) DEFAULT NULL,
  `ext_level` VARCHAR(20) DEFAULT NULL,
  `ext_shape` VARCHAR(20) DEFAULT NULL,
  `ext_updated_at` DATETIME DEFAULT NULL COMMENT 'เวลาแก้ไขล่าสุดที่ต้นทาง (เวลาไทย)',
  `raw_json` MEDIUMTEXT DEFAULT NULL COMMENT 'ข้อมูลต้นฉบับตอนนำเข้า',
  `imported_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `imported_by` INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`import_id`),
  UNIQUE KEY `uq_flood_zone_import_ext` (`source`, `ext_id`),
  KEY `idx_flood_zone_import_zone` (`zone_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
