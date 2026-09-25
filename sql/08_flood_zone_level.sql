-- =============================================================================
-- ระดับพื้นที่ประกาศ — ผู้ดูแลเพิ่ม/แก้ชื่อ สี ไอคอน ลำดับ ได้ที่ ผู้ดูแลระบบ → ระดับพื้นที่
-- flood_zone.level เก็บ level_code ของตารางนี้
--
-- ปกติไม่ต้องรันเอง: ระบบสร้างตารางนี้ให้ตอนผู้ดูแลเปิดหน้า "ระดับพื้นที่" ครั้งแรก
-- ถ้าบัญชีฐานข้อมูลของเว็บไม่มีสิทธิ์ CREATE ให้รันไฟล์นี้เอง (รันซ้ำได้ ไม่ทับค่าที่ผู้ดูแลแก้ไว้)
--   php sql/apply_schema.php 08
-- =============================================================================

CREATE TABLE IF NOT EXISTS `flood_zone_level` (
  `level_code` VARCHAR(20) NOT NULL COMMENT 'ค่าที่เก็บใน flood_zone.level',
  `name` VARCHAR(60) NOT NULL,
  `description` VARCHAR(200) DEFAULT NULL,
  `color` CHAR(7) NOT NULL DEFAULT '#64748b' COMMENT 'สีพื้นที่บนแผนที่ #rrggbb',
  `icon` VARCHAR(40) NOT NULL DEFAULT 'fa-circle' COMMENT 'ไอคอน Font Awesome 4.7',
  `sort_order` INT NOT NULL DEFAULT 0 COMMENT 'ความรุนแรง 1 = รุนแรงสุด',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL,
  `updated_by` INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`level_code`),
  KEY `idx_flood_zone_level_order` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ระดับตั้งต้น (INSERT IGNORE = มีอยู่แล้วไม่ทับ)
INSERT IGNORE INTO `flood_zone_level` (`level_code`, `name`, `description`, `color`, `icon`, `sort_order`, `is_active`) VALUES
('evacuated',    'อพยพแล้ว',                'อพยพประชาชนออกจากพื้นที่แล้ว / ห้ามเข้าพื้นที่',                       '#7c3aed', 'fa-bus',          1, 1),
('impassable',   'รถทุกชนิดผ่านไม่ได้',      'น้ำสูงมาก รถใหญ่ก็ผ่านไม่ได้ ต้องใช้เรือ',                           '#be185d', 'fa-ship',         2, 1),
('road_damaged', 'ถนนขาด / สะพานชำรุด',     'ถนนขาด ทรุด หรือสะพานชำรุด ห้ามผ่าน ให้ใช้เส้นทางเลี่ยง',              '#44403c', 'fa-chain-broken', 3, 1),
('blocked',      'รถเล็กผ่านไม่ได้',         'น้ำสูง รถเก๋ง/รถเล็กผ่านไม่ได้ (รถกระบะ/รถสูงยังผ่านได้)',             '#dc2626', 'fa-ban',          4, 1),
('watch',        'เฝ้าระวัง',                'น้ำท่วมขัง / ระดับน้ำสูงขึ้น ติดตามใกล้ชิด',                          '#f59e0b', 'fa-eye',          5, 1),
('receded',      'น้ำลดแล้ว (ยังต้องระวัง)', 'น้ำลดแล้ว แต่ยังมีโคลน ถนนเสียหาย หรือไฟฟ้ายังไม่ปลอดภัย',            '#0d9488', 'fa-level-down',   6, 1);
