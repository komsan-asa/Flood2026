-- =============================================================================
-- ที่มาของรายงานจุดน้ำ — ประชาชนส่งเอง (public) หรือนำเข้าจากโพสต์โซเชียล (facebook ฯลฯ)
-- source_url = ลิงก์โพสต์ต้นทางให้เจ้าหน้าที่เปิดตรวจ · นำเข้าซ้ำจะข้ามลิงก์ที่มีแล้ว
--
-- ปกติไม่ต้องรันเอง: ระบบเพิ่มคอลัมน์ให้ตอนนำเข้าครั้งแรก (models/social_import_model.php)
-- ถ้าบัญชีฐานข้อมูลของเว็บไม่มีสิทธิ์ ALTER ให้รันไฟล์นี้เอง (รันครั้งเดียว)
--   php sql/apply_schema.php 11
-- =============================================================================

ALTER TABLE `flood_report`
  ADD COLUMN `source` VARCHAR(20) NOT NULL DEFAULT 'public' COMMENT 'public|facebook|line|other' AFTER `status`,
  ADD COLUMN `source_name` VARCHAR(150) DEFAULT NULL COMMENT 'ชื่อเพจ/กลุ่มต้นทาง' AFTER `source`,
  ADD COLUMN `source_url` VARCHAR(500) DEFAULT NULL COMMENT 'ลิงก์โพสต์ต้นทาง' AFTER `source_name`;
