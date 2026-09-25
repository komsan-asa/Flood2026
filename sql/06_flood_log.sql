-- =============================================================================
-- ประวัติการเข้าใช้งาน (flood_login_log) และประวัติการเปลี่ยนแปลงข้อมูล (flood_audit_log)
--
-- ระบบสร้างตารางให้เองตอนเขียน log ครั้งแรก (Audit::ensureSchema) ไฟล์นี้เก็บไว้เป็น
-- หลักฐานโครงสร้าง และใช้สร้างล่วงหน้าบนฐานข้อมูลใหม่
--
-- ตั้งใจ "ไม่ผูก foreign key" กับ flood_user / ตารางข้อมูล เพราะ log ต้องอยู่รอด
-- แม้ข้อมูลต้นทางจะถูกลบ
-- =============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS flood_login_log (
    login_log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL = ล็อกอินล้มเหลวและไม่รู้ว่าเป็นบัญชีไหน',
    loginname VARCHAR(50) NOT NULL DEFAULT '' COMMENT 'ชื่อผู้ใช้ที่กรอกเข้ามา (เก็บแม้ล็อกอินไม่ผ่าน)',
    name VARCHAR(150) NOT NULL DEFAULT '',
    role VARCHAR(30) NOT NULL DEFAULT '',
    event VARCHAR(20) NOT NULL COMMENT 'login | failed | logout | kicked',
    method VARCHAR(20) NOT NULL DEFAULT 'password',
    reason VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'สาเหตุที่ล็อกอินไม่ผ่าน',
    ip VARCHAR(45) NOT NULL DEFAULT '',
    user_agent VARCHAR(255) NOT NULL DEFAULT '',
    session_key CHAR(32) NOT NULL DEFAULT '' COMMENT 'hash ของ session id — ใช้จับคู่ login กับ logout',
    created_at DATETIME NOT NULL,
    PRIMARY KEY (login_log_id),
    KEY idx_flood_login_log_user (user_id, created_at),
    KEY idx_flood_login_log_time (created_at),
    KEY idx_flood_login_log_event (event, created_at),
    KEY idx_flood_login_log_name (loginname, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS flood_audit_log (
    audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    table_name VARCHAR(64) NOT NULL,
    row_pk VARCHAR(120) NOT NULL DEFAULT '' COMMENT 'ค่า primary key ของแถวที่ถูกกระทำ',
    action VARCHAR(10) NOT NULL COMMENT 'insert | update | delete',
    actor_user_id INT UNSIGNED NULL DEFAULT NULL,
    actor_loginname VARCHAR(50) NOT NULL DEFAULT '',
    actor_name VARCHAR(150) NOT NULL DEFAULT '',
    actor_role VARCHAR(30) NOT NULL DEFAULT '',
    actor_team_id INT UNSIGNED NULL DEFAULT NULL,
    changed_cols VARCHAR(1000) NOT NULL DEFAULT '' COMMENT 'คอลัมน์ที่เปลี่ยน (เฉพาะ update)',
    before_json MEDIUMTEXT NULL COMMENT 'ค่าก่อนแก้ หรือทั้งแถวที่ถูกลบ',
    after_json MEDIUMTEXT NULL COMMENT 'ค่าหลังแก้ หรือข้อมูลที่เพิ่ม',
    row_count INT UNSIGNED NOT NULL DEFAULT 1,
    route VARCHAR(120) NOT NULL DEFAULT '' COMMENT 'เมนู/endpoint ที่สั่ง เช่น flood/saveZone',
    ip VARCHAR(45) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    PRIMARY KEY (audit_id),
    KEY idx_flood_audit_log_row (table_name, row_pk, created_at),
    KEY idx_flood_audit_log_time (created_at),
    KEY idx_flood_audit_log_actor (actor_loginname, created_at),
    KEY idx_flood_audit_log_action (action, created_at),
    KEY idx_flood_audit_log_table (table_name, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
