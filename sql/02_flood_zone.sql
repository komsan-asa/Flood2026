-- =============================================================================
-- พื้นที่ประกาศน้ำท่วม (แสดงบนแผนที่สาธารณะ) — ไม่มีข้อมูลส่วนบุคคล
-- =============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `flood_zone` (
  `zone_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(200) NOT NULL,
  `level` VARCHAR(20) NOT NULL DEFAULT 'watch' COMMENT 'evacuated|blocked|watch',
  `shape` VARCHAR(10) NOT NULL DEFAULT 'circle' COMMENT 'circle|polygon',
  `center_lat` DECIMAL(10,7) NOT NULL COMMENT 'circle = จุดศูนย์กลาง / polygon = จุดกึ่งกลาง',
  `center_lng` DECIMAL(10,7) NOT NULL,
  `radius_m` INT UNSIGNED DEFAULT NULL COMMENT 'เฉพาะ circle (เมตร)',
  `polygon_json` MEDIUMTEXT DEFAULT NULL COMMENT 'เฉพาะ polygon: [[lat,lng],...]',
  `amphoe_code` CHAR(4) DEFAULT NULL,
  `tambon_code` CHAR(6) DEFAULT NULL,
  `note` TEXT DEFAULT NULL COMMENT 'ข้อความถึงประชาชน เช่น รถเล็กหลีกเลี่ยง',
  `source` VARCHAR(20) NOT NULL DEFAULT 'officer' COMMENT 'officer|ddpm|report|arankub (นำเข้าจาก arankub.com)',
  `status` VARCHAR(10) NOT NULL DEFAULT 'active' COMMENT 'active|ended',
  `started_at` DATETIME NOT NULL,
  `ended_at` DATETIME DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `updated_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`zone_id`),
  KEY `idx_flood_zone_status` (`status`, `level`),
  KEY `idx_flood_zone_amphoe` (`amphoe_code`),
  KEY `idx_flood_zone_tambon` (`tambon_code`),
  CONSTRAINT `fk_flood_zone_amphoe` FOREIGN KEY (`amphoe_code`) REFERENCES `flood_amphoe` (`amphoe_code`) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `fk_flood_zone_tambon` FOREIGN KEY (`tambon_code`) REFERENCES `flood_tambon` (`tambon_code`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
