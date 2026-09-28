-- ผู้ใช้จาก hosoffice (หน้า ผู้ใช้งาน → นำเข้าข้อมูลผู้ใช้จาก API)
-- ระบบเพิ่มคอลัมน์เองครั้งแรกที่กดนำเข้า (Site_Api_Model::ensureColumns) · ใช้ไฟล์นี้เมื่อบัญชีฐานข้อมูลไม่มีสิทธิ์ ALTER
--   php sql/apply_schema.php 19

ALTER TABLE `flood_user`
  ADD COLUMN `auth_source` VARCHAR(20) NOT NULL DEFAULT 'local' COMMENT 'local = รหัสผ่านในระบบ / hosoffice = ตรวจรหัสผ่านกับ hosoffice ผ่าน Flood2026-site-api',
  ADD COLUMN `hr_emp_id` VARCHAR(30) DEFAULT NULL COMMENT 'รหัสบุคลากรใน hosoffice';
