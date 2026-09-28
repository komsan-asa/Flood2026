-- อัตรากำลังพยาบาลรายเวร (หน้า flood/manpower)
-- ระบบสร้างตาราง + ใส่กรอบตั้งต้นเองครั้งแรกที่เปิดหน้า (Manpower_Model::ensureTables) · ใช้ไฟล์นี้เมื่อบัญชีฐานข้อมูลไม่มีสิทธิ์ CREATE
--   php sql/apply_schema.php 18
-- ข้อมูลลงเวลา: Flood2026-site-api (hosoffice) → flood_mp_checkin — ดู docs/manpower-api.md
-- ฐานเดิมที่สร้างตารางไปก่อนแล้ว: ALTER TABLE flood_mp_unit ADD COLUMN `hr_depts` VARCHAR(500) DEFAULT NULL AFTER `note`;

CREATE TABLE IF NOT EXISTS `flood_mp_unit` (
  `unit_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `unit_code` VARCHAR(30) NOT NULL COMMENT 'รหัสหน่วยงาน — ระบบลงเวลาใช้อ้างอิง',
  `group_name` VARCHAR(150) NOT NULL COMMENT 'กลุ่มงาน',
  `unit_name` VARCHAR(150) NOT NULL,
  `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `std_ratio` VARCHAR(30) DEFAULT NULL COMMENT 'เกณฑ์มาตรฐาน เช่น 1 ต่อ 4',
  `req_m` TINYINT UNSIGNED DEFAULT NULL COMMENT 'กรอบเวรเช้า วันทำการ (NULL = ไม่มีเวรนี้)',
  `req_a` TINYINT UNSIGNED DEFAULT NULL,
  `req_n` TINYINT UNSIGNED DEFAULT NULL,
  `hol_m` TINYINT UNSIGNED DEFAULT NULL COMMENT 'กรอบเวรเช้า วันหยุด (NULL = เท่าวันทำการ, 0 = ไม่มีเวร)',
  `hol_a` TINYINT UNSIGNED DEFAULT NULL,
  `hol_n` TINYINT UNSIGNED DEFAULT NULL,
  `note` VARCHAR(300) DEFAULT NULL,
  `hr_depts` VARCHAR(500) DEFAULT NULL COMMENT 'หน่วยงานใน hosoffice ที่นับเข้าหน่วยนี้ (ชื่อหรือรหัส คั่นด้วย ,) — ว่าง = ชื่อตรงกับ unit_name',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `updated_by` INT UNSIGNED DEFAULT NULL,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`unit_id`),
  UNIQUE KEY `uq_flood_mp_unit_code` (`unit_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `flood_mp_checkin` (
  `checkin_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `unit_code` VARCHAR(30) NOT NULL COMMENT 'ตรงกับ flood_mp_unit.unit_code',
  `work_date` DATE NOT NULL,
  `shift` CHAR(1) NOT NULL COMMENT 'M|A|N',
  `emp_code` VARCHAR(30) NOT NULL COMMENT 'รหัสพนักงาน — นับไม่ซ้ำคนต่อเวร',
  `emp_name` VARCHAR(150) DEFAULT NULL,
  `staff_type` VARCHAR(10) NOT NULL DEFAULT 'RN' COMMENT 'RN = พยาบาลวิชาชีพ (นับเทียบกรอบ) / อื่น ๆ เช่น PN, NA',
  `checkin_at` DATETIME DEFAULT NULL,
  `source` VARCHAR(30) NOT NULL DEFAULT 'site-api',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`checkin_id`),
  UNIQUE KEY `uq_flood_mp_checkin` (`unit_code`, `work_date`, `shift`, `emp_code`),
  KEY `idx_flood_mp_checkin_date` (`work_date`, `shift`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `flood_mp_count` (
  `unit_id` INT UNSIGNED NOT NULL,
  `work_date` DATE NOT NULL,
  `shift` CHAR(1) NOT NULL,
  `actual` TINYINT UNSIGNED NOT NULL,
  `note` VARCHAR(300) DEFAULT NULL,
  `updated_by` INT UNSIGNED DEFAULT NULL,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`unit_id`, `work_date`, `shift`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `flood_mp_sync` (
  `sync_date` DATE NOT NULL,
  `synced_at` DATETIME NOT NULL,
  `fetched` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'การสแกนเข้าเวรที่ได้จาก API',
  `mapped` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'จับคู่หน่วยงานได้',
  `unmapped` MEDIUMTEXT DEFAULT NULL COMMENT 'JSON {หน่วยงาน hosoffice: จำนวนคน} ที่ยังไม่จับคู่',
  `error` VARCHAR(500) DEFAULT NULL,
  PRIMARY KEY (`sync_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `flood_mp_holiday` (
  `hdate` DATE NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  PRIMARY KEY (`hdate`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- กรอบตั้งต้นจากเอกสารเกณฑ์ลดเพิ่มคน 1 ก.ค. 2568 (ตัวเลขในวงเล็บของเอกสาร = กรอบวันหยุด)
INSERT IGNORE INTO `flood_mp_unit` (`unit_code`,`group_name`,`unit_name`,`sort_order`,`std_ratio`,`req_m`,`req_a`,`req_n`,`hol_m`,`hol_a`,`hol_n`,`note`) VALUES ('ER', 'กลุ่มงานการพยาบาลผู้ป่วยอุบัติเหตุและฉุกเฉิน', 'งานอุบัติเหตุและฉุกเฉิน', 10, '1 ต่อ 10', 8, 7, 5, 9, 8, 5, 'เอกสาร: ช 8(9)/ บ 7(8)/ ด 5 · ไม่ลดคน');
INSERT IGNORE INTO `flood_mp_unit` (`unit_code`,`group_name`,`unit_name`,`sort_order`,`std_ratio`,`req_m`,`req_a`,`req_n`,`hol_m`,`hol_a`,`hol_n`,`note`) VALUES ('REFER', 'กลุ่มงานการพยาบาลผู้ป่วยอุบัติเหตุและฉุกเฉิน', 'งานศูนย์รับส่งต่อ', 20, NULL, 2, 1, 1, NULL, NULL, NULL, 'ช 2 คนไม่รวมหัวหน้า (ปรับจากประชุม กกบ.กลุ่มการ 24.1.68) · ไม่ลดคน');
INSERT IGNORE INTO `flood_mp_unit` (`unit_code`,`group_name`,`unit_name`,`sort_order`,`std_ratio`,`req_m`,`req_a`,`req_n`,`hol_m`,`hol_a`,`hol_n`,`note`) VALUES ('TEA_EMS', 'กลุ่มงานการพยาบาลผู้ป่วยอุบัติเหตุและฉุกเฉิน', 'TEA+EMS', 30, NULL, 1, NULL, NULL, 0, NULL, NULL, '1 คน วันทำการ · ไม่ลดคน');
INSERT IGNORE INTO `flood_mp_unit` (`unit_code`,`group_name`,`unit_name`,`sort_order`,`std_ratio`,`req_m`,`req_a`,`req_n`,`hol_m`,`hol_a`,`hol_n`,`note`) VALUES ('ADMIT', 'กลุ่มงานการพยาบาลผู้ป่วยอุบัติเหตุและฉุกเฉิน', 'ศูนย์ admit', 40, NULL, 1, NULL, NULL, 0, NULL, NULL, '1 คน วันทำการ · ไม่ลดคน');
INSERT IGNORE INTO `flood_mp_unit` (`unit_code`,`group_name`,`unit_name`,`sort_order`,`std_ratio`,`req_m`,`req_a`,`req_n`,`hol_m`,`hol_a`,`hol_n`,`note`) VALUES ('ICU1', 'กลุ่มงานการพยาบาลผู้ป่วยหนัก', 'งานห้องผู้ป่วยหนัก 1', 50, '1 ต่อ 2', 5, 5, 5, NULL, NULL, NULL, 'ไม่ลดคน');
INSERT IGNORE INTO `flood_mp_unit` (`unit_code`,`group_name`,`unit_name`,`sort_order`,`std_ratio`,`req_m`,`req_a`,`req_n`,`hol_m`,`hol_a`,`hol_n`,`note`) VALUES ('ICU2', 'กลุ่มงานการพยาบาลผู้ป่วยหนัก', 'งานห้องผู้ป่วยหนัก 2', 60, '1 ต่อ 2', 5, 5, 5, NULL, NULL, NULL, 'ไม่ลดคน');
INSERT IGNORE INTO `flood_mp_unit` (`unit_code`,`group_name`,`unit_name`,`sort_order`,`std_ratio`,`req_m`,`req_a`,`req_n`,`hol_m`,`hol_a`,`hol_n`,`note`) VALUES ('ICU3', 'กลุ่มงานการพยาบาลผู้ป่วยหนัก', 'งานห้องผู้ป่วยหนัก 3', 70, '1 ต่อ 2', 4, 4, 4, NULL, NULL, NULL, 'ไม่ลดคน');
INSERT IGNORE INTO `flood_mp_unit` (`unit_code`,`group_name`,`unit_name`,`sort_order`,`std_ratio`,`req_m`,`req_a`,`req_n`,`hol_m`,`hol_a`,`hol_n`,`note`) VALUES ('NICU', 'กลุ่มงานการพยาบาลผู้ป่วยหนัก', 'งานทารกแรกเกิดวิกฤตป่วย', 80, '1 ต่อ 2', 6, 6, 6, NULL, NULL, NULL, 'ไม่ลดคน');
INSERT IGNORE INTO `flood_mp_unit` (`unit_code`,`group_name`,`unit_name`,`sort_order`,`std_ratio`,`req_m`,`req_a`,`req_n`,`hol_m`,`hol_a`,`hol_n`,`note`) VALUES ('ICU4', 'กลุ่มงานการพยาบาลผู้ป่วยหนัก', 'งานห้องผู้ป่วยหนัก 4', 90, '1 ต่อ 2', 4, 4, 4, NULL, NULL, NULL, 'ไม่ลดคน');
INSERT IGNORE INTO `flood_mp_unit` (`unit_code`,`group_name`,`unit_name`,`sort_order`,`std_ratio`,`req_m`,`req_a`,`req_n`,`hol_m`,`hol_a`,`hol_n`,`note`) VALUES ('LR', 'กลุ่มงานการพยาบาลผู้คลอด', 'งานห้องคลอด', 100, '2 ต่อ 1', 4, 4, 4, NULL, NULL, NULL, 'ไม่ลดคน');
INSERT IGNORE INTO `flood_mp_unit` (`unit_code`,`group_name`,`unit_name`,`sort_order`,`std_ratio`,`req_m`,`req_a`,`req_n`,`hol_m`,`hol_a`,`hol_n`,`note`) VALUES ('MED_M', 'กลุ่มงานการพยาบาลผู้ป่วยอายุรกรรม', 'หอผู้ป่วยอายุรกรรมชาย', 110, '1 ต่อ 4', 7, 7, 7, NULL, NULL, NULL, 'ผู้ป่วยตามเกณฑ์ 28 · ลดเมื่อน้อยกว่า 26 คน · เสริมเมื่อ 44');
INSERT IGNORE INTO `flood_mp_unit` (`unit_code`,`group_name`,`unit_name`,`sort_order`,`std_ratio`,`req_m`,`req_a`,`req_n`,`hol_m`,`hol_a`,`hol_n`,`note`) VALUES ('MED_F', 'กลุ่มงานการพยาบาลผู้ป่วยอายุรกรรม', 'หอผู้ป่วยอายุรกรรมหญิง', 120, '1 ต่อ 4', 7, 7, 7, NULL, NULL, NULL, 'ผู้ป่วยตามเกณฑ์ 28 · ลดเมื่อน้อยกว่า 26 คน · เสริมเมื่อ 44');
INSERT IGNORE INTO `flood_mp_unit` (`unit_code`,`group_name`,`unit_name`,`sort_order`,`std_ratio`,`req_m`,`req_a`,`req_n`,`hol_m`,`hol_a`,`hol_n`,`note`) VALUES ('MED_MIX', 'กลุ่มงานการพยาบาลผู้ป่วยอายุรกรรม', 'หอผู้ป่วยอายุรกรรมรวม', 130, '1 ต่อ 4', 7, 7, 7, NULL, NULL, NULL, 'ผู้ป่วยตามเกณฑ์ 28 · ลดเมื่อน้อยกว่า 26 คน · เสริมเมื่อ 44');
INSERT IGNORE INTO `flood_mp_unit` (`unit_code`,`group_name`,`unit_name`,`sort_order`,`std_ratio`,`req_m`,`req_a`,`req_n`,`hol_m`,`hol_a`,`hol_n`,`note`) VALUES ('MED_ER4', 'กลุ่มงานการพยาบาลผู้ป่วยอายุรกรรม', 'หอผู้ป่วยอายุรกรรม ER ชั้น 4', 140, '1 ต่อ 4', 4, 4, 4, NULL, NULL, NULL, 'ผู้ป่วยตามเกณฑ์ 16 · ลดเมื่อน้อยกว่า 14 คน · เสริมเมื่อ 26');
INSERT IGNORE INTO `flood_mp_unit` (`unit_code`,`group_name`,`unit_name`,`sort_order`,`std_ratio`,`req_m`,`req_a`,`req_n`,`hol_m`,`hol_a`,`hol_n`,`note`) VALUES ('VIP4', 'กลุ่มงานการพยาบาลผู้ป่วยอายุรกรรม', 'หอผู้ป่วยพิเศษ 4', 150, '1 ต่อ 4', 2, 2, 2, NULL, NULL, NULL, 'ผู้ป่วยตามเกณฑ์ 8 · ไม่ลดคน · ไม่มีเสริม');
INSERT IGNORE INTO `flood_mp_unit` (`unit_code`,`group_name`,`unit_name`,`sort_order`,`std_ratio`,`req_m`,`req_a`,`req_n`,`hol_m`,`hol_a`,`hol_n`,`note`) VALUES ('VIP5', 'กลุ่มงานการพยาบาลผู้ป่วยอายุรกรรม', 'หอผู้ป่วยพิเศษ 5', 160, '1 ต่อ 4', 2, 2, 2, NULL, NULL, NULL, 'ผู้ป่วยตามเกณฑ์ 8 · ไม่ลดคน · เสริมเมื่อ 14');
INSERT IGNORE INTO `flood_mp_unit` (`unit_code`,`group_name`,`unit_name`,`sort_order`,`std_ratio`,`req_m`,`req_a`,`req_n`,`hol_m`,`hol_a`,`hol_n`,`note`) VALUES ('MONK4', 'กลุ่มงานการพยาบาลผู้ป่วยอายุรกรรม', 'หอผู้ป่วยพิเศษสงฆ์อาพาธชั้น 4', 170, '1 ต่อ 4', 2, 2, 2, NULL, NULL, NULL, 'ผู้ป่วยตามเกณฑ์ 8 · ไม่ลดคน · ไม่มีเสริม');
INSERT IGNORE INTO `flood_mp_unit` (`unit_code`,`group_name`,`unit_name`,`sort_order`,`std_ratio`,`req_m`,`req_a`,`req_n`,`hol_m`,`hol_a`,`hol_n`,`note`) VALUES ('SUR_TR', 'กลุ่มงานการพยาบาลผู้ป่วยศัลยกรรม', 'หอผู้ป่วยศัลยกรรมอุบัติเหตุ', 180, '1 ต่อ 4', 3, 3, 3, NULL, NULL, NULL, 'ผู้ป่วยตามเกณฑ์ 12 · ลดเมื่อน้อยกว่า 10 คน · เสริมเมื่อ 20');
INSERT IGNORE INTO `flood_mp_unit` (`unit_code`,`group_name`,`unit_name`,`sort_order`,`std_ratio`,`req_m`,`req_a`,`req_n`,`hol_m`,`hol_a`,`hol_n`,`note`) VALUES ('SUR_M', 'กลุ่มงานการพยาบาลผู้ป่วยศัลยกรรม', 'หอผู้ป่วยศัลยกรรมชาย', 190, '1 ต่อ 5', 5, 5, 5, NULL, NULL, NULL, 'ผู้ป่วยตามเกณฑ์ 25 · ลดเมื่อน้อยกว่า 22 คน · เสริมเมื่อ 37');
INSERT IGNORE INTO `flood_mp_unit` (`unit_code`,`group_name`,`unit_name`,`sort_order`,`std_ratio`,`req_m`,`req_a`,`req_n`,`hol_m`,`hol_a`,`hol_n`,`note`) VALUES ('SUR_F', 'กลุ่มงานการพยาบาลผู้ป่วยศัลยกรรม', 'หอผู้ป่วยศัลยกรรมหญิง', 200, '1 ต่อ 5', 4, 4, 4, NULL, NULL, NULL, 'ผู้ป่วยตามเกณฑ์ 20 · ลดเมื่อน้อยกว่า 17 คน · เสริมเมื่อ 30');
INSERT IGNORE INTO `flood_mp_unit` (`unit_code`,`group_name`,`unit_name`,`sort_order`,`std_ratio`,`req_m`,`req_a`,`req_n`,`hol_m`,`hol_a`,`hol_n`,`note`) VALUES ('MONK1', 'กลุ่มงานการพยาบาลผู้ป่วยศัลยกรรม', 'หอผู้ป่วยพิเศษสงฆ์อาพาธชั้น 1', 210, '1 ต่อ 5', 2, 2, 2, NULL, NULL, NULL, 'ผู้ป่วยตามเกณฑ์ 10 · ไม่ลดคน · ไม่มีเสริม');
INSERT IGNORE INTO `flood_mp_unit` (`unit_code`,`group_name`,`unit_name`,`sort_order`,`std_ratio`,`req_m`,`req_a`,`req_n`,`hol_m`,`hol_a`,`hol_n`,`note`) VALUES ('MONK2', 'กลุ่มงานการพยาบาลผู้ป่วยศัลยกรรม', 'หอผู้ป่วยพิเศษสงฆ์อาพาธชั้น 2', 220, '1 ต่อ 5', 2, 2, 2, NULL, NULL, NULL, 'ผู้ป่วยตามเกณฑ์ 10 · ไม่ลดคน · ไม่มีเสริม');
INSERT IGNORE INTO `flood_mp_unit` (`unit_code`,`group_name`,`unit_name`,`sort_order`,`std_ratio`,`req_m`,`req_a`,`req_n`,`hol_m`,`hol_a`,`hol_n`,`note`) VALUES ('OBGYN', 'กลุ่มงานการพยาบาลผู้ป่วยสูติ-นรีเวช', 'หอผู้ป่วยสูตินรีเวชกรรม', 230, '1 ต่อ 6', 4, 3, 3, 3, NULL, NULL, 'เอกสาร: ช 4(3)/ บ 3/ ด 3 · ผู้ป่วยตามเกณฑ์ 18 · ลดเมื่อน้อยกว่า 14 คน · เสริมเมื่อ 26 (ช วันทำการ 34)');
INSERT IGNORE INTO `flood_mp_unit` (`unit_code`,`group_name`,`unit_name`,`sort_order`,`std_ratio`,`req_m`,`req_a`,`req_n`,`hol_m`,`hol_a`,`hol_n`,`note`) VALUES ('MONK3', 'กลุ่มงานการพยาบาลผู้ป่วยสูติ-นรีเวช', 'หอผู้ป่วยพิเศษสงฆ์อาพาธชั้น 3', 240, '1 ต่อ 6', 2, 2, 2, NULL, NULL, NULL, 'ผู้ป่วยตามเกณฑ์ 12 · ไม่ลดคน · ไม่มีเสริม');
INSERT IGNORE INTO `flood_mp_unit` (`unit_code`,`group_name`,`unit_name`,`sort_order`,`std_ratio`,`req_m`,`req_a`,`req_n`,`hol_m`,`hol_a`,`hol_n`,`note`) VALUES ('PSY', 'กลุ่มงานการพยาบาลจิตเวช', 'หอผู้ป่วยจิตเวช', 250, '1 ต่อ 4', 2, 2, 2, NULL, NULL, NULL, 'ผู้ป่วยตามเกณฑ์ 8 · ไม่ลดคน · เสริมเมื่อ 14');
INSERT IGNORE INTO `flood_mp_unit` (`unit_code`,`group_name`,`unit_name`,`sort_order`,`std_ratio`,`req_m`,`req_a`,`req_n`,`hol_m`,`hol_a`,`hol_n`,`note`) VALUES ('PED', 'กลุ่มงานการพยาบาลผู้ป่วยกุมารเวชกรรม', 'หอผู้ป่วยกุมารเวชกรรม', 260, '1 ต่อ 4', 4, 4, 4, NULL, NULL, NULL, 'ผู้ป่วยตามเกณฑ์ 16 · ลดเมื่อน้อยกว่า 14 คน · เสริมเมื่อ 26');
INSERT IGNORE INTO `flood_mp_unit` (`unit_code`,`group_name`,`unit_name`,`sort_order`,`std_ratio`,`req_m`,`req_a`,`req_n`,`hol_m`,`hol_a`,`hol_n`,`note`) VALUES ('SNB', 'กลุ่มงานการพยาบาลผู้ป่วยกุมารเวชกรรม', 'หอผู้ป่วยทารกแรกเกิดป่วย', 270, '1 ต่อ 4', 3, 3, 3, NULL, NULL, NULL, 'ผู้ป่วยตามเกณฑ์ 12 · ลดเมื่อน้อยกว่า 10 คน · เสริมเมื่อ 20');
INSERT IGNORE INTO `flood_mp_unit` (`unit_code`,`group_name`,`unit_name`,`sort_order`,`std_ratio`,`req_m`,`req_a`,`req_n`,`hol_m`,`hol_a`,`hol_n`,`note`) VALUES ('ORTHO', 'กลุ่มงานการพยาบาลผู้ป่วยออร์โธปิดิกส์', 'หอผู้ป่วยศัลยกรรมกระดูก', 280, '1 ต่อ 5', 4, 4, 4, NULL, NULL, NULL, 'ผู้ป่วยตามเกณฑ์ 20 · ลดเมื่อน้อยกว่า 17 คน · เสริมเมื่อ 30');
INSERT IGNORE INTO `flood_mp_unit` (`unit_code`,`group_name`,`unit_name`,`sort_order`,`std_ratio`,`req_m`,`req_a`,`req_n`,`hol_m`,`hol_a`,`hol_n`,`note`) VALUES ('ORTHO_VIP', 'กลุ่มงานการพยาบาลผู้ป่วยออร์โธปิดิกส์', 'หอผู้ป่วยพิเศษศัลยกรรมกระดูก', 290, '1 ต่อ 5', 2, 2, 2, NULL, NULL, NULL, 'ผู้ป่วยตามเกณฑ์ 10 · ไม่ลดคน · ไม่มีเสริม');
INSERT IGNORE INTO `flood_mp_unit` (`unit_code`,`group_name`,`unit_name`,`sort_order`,`std_ratio`,`req_m`,`req_a`,`req_n`,`hol_m`,`hol_a`,`hol_n`,`note`) VALUES ('ENT', 'กลุ่มงานการพยาบาลผู้ป่วยโสต ศอ นาสิก จักษุ', 'หอผู้ป่วยโสตศอนาสิกและจักษุ', 300, '1 ต่อ 5', 3, 2, 2, 2, NULL, NULL, 'เอกสาร: ช 3(2)/ บ 2/ ด 2 · ผู้ป่วยตามเกณฑ์ 10 · ไม่ลดคน · เสริมเมื่อ 16 (ช วันทำการ 23)');
