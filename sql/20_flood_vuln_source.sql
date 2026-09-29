-- =============================================================================
-- ทะเบียนกลุ่มเปราะบาง: ดึงรายชื่อจาก Google Sheet (แบบหน้าบุคลากรที่ได้รับผลกระทบ)
-- ระบบสร้างเองตอนเปิดหน้า flood/vulnerable ครั้งแรก (Vulnerable_Import_Model::ensureTables)
-- ใช้ไฟล์นี้เมื่อบัญชีฐานข้อมูลของเว็บไม่มีสิทธิ์ ALTER/CREATE:  php sql/apply_schema.php 20
-- (ADD COLUMN IF NOT EXISTS ต้องใช้ MariaDB 10.0.2+)
-- =============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `flood_vuln_source` (
  `source_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `sheet_url` VARCHAR(500) NOT NULL,
  `default_groups` VARCHAR(150) DEFAULT NULL COMMENT 'กลุ่มที่ใส่ให้เมื่อแถวในชีตไม่ระบุกลุ่ม',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `last_sync_at` DATETIME DEFAULT NULL,
  `last_rows` INT UNSIGNED DEFAULT NULL,
  `last_new` INT UNSIGNED DEFAULT NULL,
  `last_updated` INT UNSIGNED DEFAULT NULL,
  `last_error` VARCHAR(500) DEFAULT NULL,
  `last_columns` TEXT DEFAULT NULL COMMENT 'JSON {mapped:{หัวคอลัมน์:ฟิลด์}, unmapped:[...]} ของรอบล่าสุด',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `flood_vulnerable`
  ADD COLUMN IF NOT EXISTS `src_id` INT UNSIGNED DEFAULT NULL COMMENT 'flood_vuln_source ที่นำเข้ามา (NULL = เจ้าหน้าที่เพิ่มเอง)',
  ADD COLUMN IF NOT EXISTS `src_key` CHAR(40) DEFAULT NULL COMMENT 'sha1(แหล่ง|HN หรือ เบอร์+ชื่อ) ใช้จับคู่รอบถัดไป',
  ADD COLUMN IF NOT EXISTS `src_raw` MEDIUMTEXT DEFAULT NULL COMMENT 'JSON {หัวคอลัมน์: ค่า} จากชีต ไม่มีเลขบัตรประชาชน',
  ADD COLUMN IF NOT EXISTS `src_synced_at` DATETIME DEFAULT NULL,
  ADD KEY IF NOT EXISTS `idx_flood_vulnerable_src` (`src_id`, `src_key`);

-- ชีตประเภท "รายงานศูนย์พักพิง" (29 ก.ย. 69): 1 แถว = 1 ศูนย์ต่อวัน — ตัวเลขรวม ไม่ใช่รายชื่อคน
ALTER TABLE `flood_vuln_source`
  ADD COLUMN IF NOT EXISTS `kind` VARCHAR(10) NOT NULL DEFAULT 'persons' COMMENT 'persons = รายชื่อรายคน · shelter = รายงานศูนย์พักพิง' AFTER `sheet_url`;

CREATE TABLE IF NOT EXISTS `flood_shelter_report` (
  `report_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source_id` INT UNSIGNED NOT NULL,
  `report_date` DATE NOT NULL,
  `tab_name` VARCHAR(100) DEFAULT NULL,
  `row_no` SMALLINT UNSIGNED DEFAULT NULL,
  `amphoe_name` VARCHAR(100) DEFAULT NULL,
  `amphoe_code` CHAR(4) DEFAULT NULL,
  `tambon_code` CHAR(6) DEFAULT NULL,
  `shelter_name` VARCHAR(255) NOT NULL,
  `people` INT DEFAULT NULL,
  `elderly` INT DEFAULT NULL,
  `disabled` INT DEFAULT NULL,
  `child` INT DEFAULT NULL COMMENT 'เด็ก 0–5 ปี',
  `bedridden` INT DEFAULT NULL,
  `pregnant` INT DEFAULT NULL,
  `dialysis_hd` INT DEFAULT NULL COMMENT 'ล้างไตด้วยเครื่อง',
  `dialysis_capd` INT DEFAULT NULL COMMENT 'ล้างไตช่องท้อง',
  `mental` INT DEFAULT NULL,
  `chronic` INT DEFAULT NULL,
  `treated` INT DEFAULT NULL COMMENT 'รักษา/ทำแผล/จ่ายยา (ราย)',
  `referred` INT DEFAULT NULL COMMENT 'ส่งต่อ รพ./รพ.สต. (ราย)',
  `pregnant_note` VARCHAR(500) DEFAULT NULL,
  `coordinator` VARCHAR(300) DEFAULT NULL COMMENT 'ผู้ประสานประจำศูนย์',
  `health_contact` VARCHAR(500) DEFAULT NULL COMMENT 'ผู้ประสานหลักด้านสาธารณสุข',
  `needs` VARCHAR(1000) DEFAULT NULL COMMENT 'สิ่งที่ต้องการสนับสนุน/ปัญหา',
  `raw` MEDIUMTEXT DEFAULT NULL COMMENT 'JSON {หัวคอลัมน์: ค่า} ทุกคอลัมน์ของแถว',
  `imported_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`report_id`),
  KEY `idx_flood_shelter_report_date` (`report_date`, `source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
