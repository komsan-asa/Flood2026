-- ติดตามสถานการณ์อุทกภัยทั้งประเทศรายวัน (สรุปจากรายงาน ปภ. / ข่าว / โซเชียล)
-- 1 แถว = 1 วัน × 1 ขอบเขต · province_code '00' = ภาพรวมทั้งประเทศ
-- ระบบสร้างให้เองอัตโนมัติ (Sitrep_Model::ensureTable) ถ้าบัญชีฐานข้อมูลมีสิทธิ์ CREATE
CREATE TABLE IF NOT EXISTS `flood_sitrep` (
  `sitrep_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `report_date` DATE NOT NULL,
  `province_code` CHAR(2) NOT NULL DEFAULT '00' COMMENT '00 = ทั้งประเทศ',
  `as_of` DATETIME DEFAULT NULL COMMENT 'เวลาของข้อมูล',
  `status` VARCHAR(10) DEFAULT NULL COMMENT 'ongoing|resolved|warning',
  `trend` VARCHAR(10) DEFAULT NULL COMMENT 'rising|stable|falling',
  `provinces_total` SMALLINT UNSIGNED DEFAULT NULL COMMENT 'สะสม',
  `provinces_ongoing` SMALLINT UNSIGNED DEFAULT NULL,
  `amphoes` SMALLINT UNSIGNED DEFAULT NULL,
  `tambons` SMALLINT UNSIGNED DEFAULT NULL,
  `villages` INT UNSIGNED DEFAULT NULL,
  `households` INT UNSIGNED DEFAULT NULL,
  `people` INT UNSIGNED DEFAULT NULL,
  `deaths` SMALLINT UNSIGNED DEFAULT NULL,
  `injured` SMALLINT UNSIGNED DEFAULT NULL,
  `amphoe_names` VARCHAR(1000) DEFAULT NULL COMMENT 'คั่น comma',
  `note` TEXT DEFAULT NULL,
  `source_name` VARCHAR(200) DEFAULT NULL,
  `source_url` VARCHAR(500) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`sitrep_id`),
  UNIQUE KEY `uk_flood_sitrep_day` (`report_date`, `province_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
