-- สาธารณูปโภคโรงพยาบาล (หน้า sat/utility) — นำเข้าจาก Google Sheet งานช่าง 4 แท็บ
-- ระบบสร้างตารางเองครั้งแรกที่เปิดหน้า (Utility_Model::ensureTables) · ใช้ไฟล์นี้เมื่อบัญชีฐานข้อมูลไม่มีสิทธิ์ CREATE
--   php sql/apply_schema.php 21

CREATE TABLE IF NOT EXISTS `flood_util_water` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `log_date` DATE NOT NULL,
  `slot` CHAR(5) NOT NULL COMMENT 'HH:MM รอบที่วัด',
  `building` VARCHAR(100) NOT NULL,
  `lower_cm` DECIMAL(7,1) DEFAULT NULL COMMENT 'ระดับถังล่าง (ซม.)',
  `lower_full_cm` DECIMAL(7,1) DEFAULT NULL COMMENT 'ถังล่างเต็ม (ซม.)',
  `lower_pct` DECIMAL(5,1) DEFAULT NULL,
  `lower_empty` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = ชีตเขียน หมดถัง',
  `upper_cm` DECIMAL(7,1) DEFAULT NULL COMMENT 'ระดับถังบน (ซม.)',
  `upper_full_cm` DECIMAL(7,1) DEFAULT NULL,
  `upper_pct` DECIMAL(5,1) DEFAULT NULL,
  `upper_empty` TINYINT(1) NOT NULL DEFAULT 0,
  `tank_note` VARCHAR(200) DEFAULT NULL COMMENT 'เช่น ถังไฟเบอร์ 6 ถัง ขนาด 3500 ลิตร',
  `note` VARCHAR(255) DEFAULT NULL,
  `imported_by` INT UNSIGNED DEFAULT NULL,
  `imported_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_flood_util_water` (`log_date`, `slot`, `building`),
  KEY `idx_flood_util_water_b` (`building`, `log_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `flood_util_fuel` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `log_date` DATE NOT NULL,
  `slot` CHAR(5) NOT NULL COMMENT 'HH:MM (00:00 = มีแต่คอลัมน์ เหลือ ต้นวัน)',
  `item` VARCHAR(150) NOT NULL COMMENT 'เครื่องกำเนิดไฟฟ้า / ถังสำรอง',
  `capacity_l` DECIMAL(9,1) DEFAULT NULL,
  `remain_start_l` DECIMAL(9,1) DEFAULT NULL COMMENT 'คอลัมน์ เหลือ (ต้นวัน)',
  `remain_l` DECIMAL(9,1) DEFAULT NULL,
  `imported_by` INT UNSIGNED DEFAULT NULL,
  `imported_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_flood_util_fuel` (`log_date`, `slot`, `item`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `flood_util_oxygen` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `log_date` DATE NOT NULL,
  `slot` CHAR(5) NOT NULL,
  `volume_m3` DECIMAL(9,2) DEFAULT NULL COMMENT 'ปริมาณออกซิเจนเหลว ลบ.ม.',
  `used_m3` DECIMAL(9,2) DEFAULT NULL COMMENT 'ใช้ไป ลบ.ม. (ตามชีต)',
  `used_pct` DECIMAL(6,2) DEFAULT NULL,
  `imported_by` INT UNSIGNED DEFAULT NULL,
  `imported_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_flood_util_oxygen` (`log_date`, `slot`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `flood_util_delivery` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `log_date` DATE NOT NULL,
  `seq` INT NOT NULL COMMENT 'ลำดับคันในวันนั้น',
  `trip_label` VARCHAR(30) DEFAULT NULL COMMENT 'ข้อความในชีต เช่น คันที่ 3',
  `time_in` CHAR(5) DEFAULT NULL,
  `time_out` CHAR(5) DEFAULT NULL,
  `building` VARCHAR(100) NOT NULL,
  `liters` INT UNSIGNED NOT NULL,
  `vehicle` VARCHAR(150) DEFAULT NULL COMMENT 'หน่วยรถ เช่น รถทหาร / รถเทศบาล',
  `note` VARCHAR(255) DEFAULT NULL,
  `imported_by` INT UNSIGNED DEFAULT NULL,
  `imported_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_flood_util_delivery` (`log_date`, `seq`, `building`),
  KEY `idx_flood_util_delivery_b` (`building`, `log_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

