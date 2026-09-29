-- Refer เข้า รพ. ช่วงอุทกภัย (หน้า sat/refer) — นำเข้าจาก Google Sheet "Refer ภาวะอุทกภัย 2569"
-- ระบบสร้างเองครั้งแรกที่เปิดหน้า · ใช้ไฟล์นี้เมื่อบัญชีฐานข้อมูลไม่มีสิทธิ์ CREATE:  php sql/apply_schema.php 22

CREATE TABLE IF NOT EXISTS `flood_refer_in` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `row_no` INT DEFAULT NULL COMMENT 'ลำดับในชีต',
  `refer_date` DATE DEFAULT NULL COMMENT 'วันที่รับแจ้ง',
  `refer_time` CHAR(5) DEFAULT NULL COMMENT 'เวลา HH:MM',
  `patient_name` VARCHAR(150) DEFAULT NULL,
  `from_hosp` VARCHAR(150) DEFAULT NULL COMMENT 'รพ.ต้นทาง (รพช)',
  `dx` VARCHAR(255) DEFAULT NULL,
  `staff` VARCHAR(100) DEFAULT NULL COMMENT 'Staff ผู้รับ',
  `nationality` VARCHAR(50) DEFAULT NULL,
  `equipment` VARCHAR(150) DEFAULT NULL COMMENT 'อุปกรณ์ เช่น on ET tube / on O2',
  `dept` VARCHAR(100) DEFAULT NULL COMMENT 'แผนก',
  `refer_to` VARCHAR(100) DEFAULT NULL COMMENT 'Refer/Ward — จุดรับ',
  `pass_type` VARCHAR(30) DEFAULT NULL COMMENT 'Refer pass/Refer ER',
  `admit_ward` VARCHAR(100) DEFAULT NULL COMMENT 'Admit',
  `officer` VARCHAR(100) DEFAULT NULL COMMENT 'เจ้าหน้าที่ผู้บันทึก',
  `note` VARCHAR(255) DEFAULT NULL,
  `imported_by` INT UNSIGNED DEFAULT NULL,
  `imported_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_flood_refer_in_date` (`refer_date`, `refer_time`),
  KEY `idx_flood_refer_in_hosp` (`from_hosp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
