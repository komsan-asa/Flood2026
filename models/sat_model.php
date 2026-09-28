<?php

/**
 * ห้องสถานการณ์ SAT (Situation Awareness Team) ของโรงพยาบาล — หน้า sat
 * ตามเอกสาร "SAT Flood Dashboard 8 เรื่อง": ตัวชี้วัดหลัก · สถานะโรงพยาบาล 4 สี · 5 เรื่องที่ต้องทำภายใน 1 ชั่วโมง
 *
 *   ① แผนที่รอบโรงพยาบาล (พื้นที่ประกาศ + จุดน้ำท่วมทางหลวงจากกรมทางหลวง + หน่วยบริการ)
 *   ② หน่วยบริการในเครือข่าย + ผลโทรสอบถามแต่ละครั้ง (เขียว / เหลือง / ส้ม / แดง)
 *   ③ เส้นทาง เข้า รพ. / ออกจาก รพ. / Refer — เทียบกับจุดทางหลวง (source = hdms) ให้เอง + ยืนยันหน้างาน
 *   ④ Critical Staff (จาก flood_staff + อัตรากำลังรายเวร) และ Critical Patient (ทะเบียนกลุ่มเปราะบาง)
 *   ⑤ SitRep ส่ง EOC — เก็บข้อความทุกฉบับ
 *
 * ตาราง (ระบบสร้างเองครั้งแรกที่เปิดหน้า · ใช้ sql/19_flood_sat.sql เมื่อบัญชีฐานข้อมูลไม่มีสิทธิ์ CREATE)
 *   flood_sat_setting        ค่าตั้ง: ชื่อ/พิกัดโรงพยาบาล, สถานะที่ SAT ประกาศ
 *   flood_sat_item (+_log)   ตัวชี้วัด: สี + สรุป ล่าสุด และประวัติ
 *   flood_sat_facility (+_log)  หน่วยบริการ และผลโทรสอบถามแต่ละครั้ง
 *   flood_sat_route (+_check)   เส้นทาง (ทางหลวง + ช่วง กม.) และผลยืนยันหน้างาน
 *   flood_sat_sitrep         SitRep ที่ออกแล้ว (ข้อความ + ตัวเลข ณ เวลานั้น)
 */
class Sat_Model extends Model {

    /* ==================== ค่าคงที่ / ตัวเลือก ==================== */

    public static function colors() {
        return array(
            'green' => array('name' => 'ปกติ', 'label' => 'GREEN', 'emoji' => '🟢', 'hex' => '#16a34a', 'order' => 1),
            'yellow' => array('name' => 'เริ่มมีผลกระทบ', 'label' => 'YELLOW', 'emoji' => '🟡', 'hex' => '#ca8a04', 'order' => 2),
            'orange' => array('name' => 'กระทบระบบบริการ', 'label' => 'ORANGE', 'emoji' => '🟠', 'hex' => '#ea580c', 'order' => 3),
            'red' => array('name' => 'วิกฤต', 'label' => 'RED', 'emoji' => '🔴', 'hex' => '#dc2626', 'order' => 4),
        );
    }

    /** สิ่งที่ SAT ต้องทำในแต่ละระดับ (ตามเอกสาร) */
    public static function overallActions() {
        return array(
            'green' => 'โรงพยาบาลยังปกติ — เฝ้าระวังและรายงานเป็นระยะ',
            'yellow' => 'เริ่มมีผลกระทบ — เสนอ EOC ทันทีว่า "ถ้าเป็นแบบนี้อีก 6–12 ชั่วโมง โรงพยาบาลจะกระทบอะไร"',
            'orange' => 'กระทบระบบบริการ — เสนอ "ทางเลือก" ให้ Incident Commander (เส้นทางสำรอง / รับผู้ป่วยที่หน่วยอื่น / เปิด staff pool / ย้ายผู้ป่วยฟอกไตล่วงหน้า)',
            'red' => 'วิกฤต — EOC เข้าสู่ full ICS activation',
        );
    }

    /** ลำดับความรุนแรงของสี (0 = ยังไม่มีข้อมูล) */
    public static function rank($color) {
        $c = self::colors();
        return isset($c[$color]) ? $c[$color]['order'] : 0;
    }

    public static function worst($a, $b) {
        return self::rank($b) > self::rank($a) ? $b : $a;
    }

    /**
     * ตัวชี้วัด: code => ชื่อ, emoji (ใช้ในข้อความ SitRep), ไอคอน, รอบอัปเดต (ชม. — เลยแล้วขึ้นเตือน), คำอธิบาย, กลุ่ม
     * 8 เรื่องตามเอกสาร + ตัวโรงพยาบาล/ED (อยู่ในเกณฑ์สีของเอกสาร) · ระบบสำคัญแยกเป็น 5 ข้อย่อย
     */
    public static function items() {
        return array(
            'rain' => array('name' => 'ฝน', 'emoji' => '🌧️', 'icon' => 'fa-cloud', 'hours' => 6, 'group' => 'main',
                'hint' => 'ฝนสะสม / พยากรณ์ 6–24 ชม.'),
            'water' => array('name' => 'ระดับน้ำ', 'emoji' => '🌊', 'icon' => 'fa-tint', 'hours' => 3, 'group' => 'main',
                'hint' => 'จุดวัดสำคัญ + แนวโน้มเพิ่ม/ลด'),
            'road' => array('name' => 'ถนน', 'emoji' => '🛣️', 'icon' => 'fa-road', 'hours' => 3, 'group' => 'main',
                'hint' => 'ถนนเข้า รพ. / ถนนส่งต่อ / ถนนไป รพ.คู่ส่งต่อ'),
            'facility' => array('name' => 'หน่วยบริการ', 'emoji' => '🏥', 'icon' => 'fa-hospital-o', 'hours' => 6, 'group' => 'main',
                'hint' => 'รพช. / PCC / รพ.สต. ใดเปิด–ปิด'),
            'ems' => array('name' => 'EMS / Refer', 'emoji' => '🚑', 'icon' => 'fa-ambulance', 'hours' => 3, 'group' => 'main',
                'hint' => 'รถผ่านได้หรือไม่ / เส้นทางสำรอง'),
            'staff' => array('name' => 'บุคลากร', 'emoji' => '👨‍⚕️', 'icon' => 'fa-user-md', 'hours' => 12, 'group' => 'main',
                'hint' => 'ใครเดินทางมาทำงานไม่ได้'),
            'patient' => array('name' => 'ผู้ป่วยเสี่ยง', 'emoji' => '🧑‍🦽', 'icon' => 'fa-wheelchair', 'hours' => 12, 'group' => 'main',
                'hint' => 'ติดเตียง / ออกซิเจน / ฟอกไต / NCD'),
            'hosp_site' => array('name' => 'ตัวโรงพยาบาล', 'emoji' => '🏨', 'icon' => 'fa-building', 'hours' => 3, 'group' => 'hosp',
                'hint' => 'น้ำท่วมในโรงพยาบาล / ทางเข้า–ออก'),
            'ed_load' => array('name' => 'ผู้ป่วยที่ ER', 'emoji' => '🚨', 'icon' => 'fa-heartbeat', 'hours' => 3, 'group' => 'hosp',
                'hint' => 'ผู้ป่วยเพิ่มผิดปกติหรือไม่'),
            'util_power' => array('name' => 'ไฟฟ้า', 'emoji' => '⚡', 'icon' => 'fa-bolt', 'hours' => 6, 'group' => 'util', 'hint' => ''),
            'util_water' => array('name' => 'น้ำประปา', 'emoji' => '🚰', 'icon' => 'fa-shower', 'hours' => 6, 'group' => 'util', 'hint' => ''),
            'util_o2' => array('name' => 'ออกซิเจน', 'emoji' => '🫁', 'icon' => 'fa-medkit', 'hours' => 6, 'group' => 'util', 'hint' => ''),
            'util_fuel' => array('name' => 'เชื้อเพลิง', 'emoji' => '⛽', 'icon' => 'fa-tachometer', 'hours' => 6, 'group' => 'util',
                'hint' => 'เครื่องปั่นไฟ / รถ'),
            'util_it' => array('name' => 'IT / สื่อสาร', 'emoji' => '💻', 'icon' => 'fa-server', 'hours' => 6, 'group' => 'util', 'hint' => ''),
        );
    }

    public static function facilityTypes() {
        return array(
            'main' => 'โรงพยาบาลแม่ข่าย',
            'hospital' => 'โรงพยาบาล (รพท./รพช.)',
            'pcc' => 'PCC / ศูนย์สุขภาพชุมชน',
            'hs' => 'รพ.สต. / สถานีสุขภาพ',
            'other' => 'อื่น ๆ',
        );
    }

    /** การให้บริการ → สีตั้งต้นที่แนะนำในฟอร์มบันทึกผลโทร */
    public static function serviceStates() {
        return array(
            'open' => array('name' => 'เปิดให้บริการปกติ', 'color' => 'green'),
            'partial' => array('name' => 'เปิดบางส่วน / มีปัญหา', 'color' => 'yellow'),
            'relocated' => array('name' => 'ย้ายจุดให้บริการ', 'color' => 'orange'),
            'closed' => array('name' => 'ปิด', 'color' => 'red'),
            'unreachable' => array('name' => 'ติดต่อไม่ได้ / ถูกตัดขาด', 'color' => 'red'),
        );
    }

    public static function routeTypes() {
        return array(
            'in' => array('name' => 'เข้าโรงพยาบาล', 'icon' => 'fa-sign-in'),
            'out' => array('name' => 'ออกจากโรงพยาบาล', 'icon' => 'fa-sign-out'),
            'refer' => array('name' => 'Refer', 'icon' => 'fa-ambulance'),
        );
    }

    /** ผลยืนยันหน้างาน (รถ EMS/Refer วิ่งจริง หรือโทรถามคนในพื้นที่) */
    public static function routeChecks() {
        return array(
            'pass' => array('name' => 'ผ่านได้ทุกชนิด', 'color' => 'green'),
            'slow' => array('name' => 'ผ่านได้ แต่ช้า/ต้องระวัง', 'color' => 'yellow'),
            'high' => array('name' => 'ผ่านได้เฉพาะรถสูง', 'color' => 'orange'),
            'blocked' => array('name' => 'ผ่านไม่ได้', 'color' => 'red'),
        );
    }

    /** ระดับพื้นที่ประกาศ → สีเส้นทาง */
    public static function levelColor($level) {
        $map = array('impassable' => 'red', 'road_damaged' => 'red', 'evacuated' => 'red', 'blocked' => 'orange',
            'watch' => 'yellow', 'receded' => 'green');
        return isset($map[$level]) ? $map[$level] : 'yellow';
    }

    /** หน่วยสำคัญของ Staff Accessibility (ตามเอกสาร) — จับคู่จากชื่อหน่วยงานที่บุคลากรพิมพ์เอง */
    public static function criticalUnits() {
        return array(
            'ED' => array('name' => 'ED / อุบัติเหตุฉุกเฉิน', 're' => '/(อุบัติเหตุ.*ฉุกเฉิน|^\s*ER\b|^\s*ED\b|ฉุกเฉิน)/iu',
                'not' => '/(ศัลยกรรม|อายุรกรรม|med|หอผู้ป่วย|ส่งต่อ|สงต่อ)/iu'),
            'ICU' => array('name' => 'ICU / NICU', 're' => '/(ผู้ป่วยหนัก|ICU|ทารกแรกเกิดวิกฤต)/iu', 'not' => ''),
            'OR' => array('name' => 'ห้องผ่าตัด (OR)', 're' => '/ห้องผ่าตัด/u', 'not' => ''),
            'ANES' => array('name' => 'วิสัญญี', 're' => '/วิสัญญี/u', 'not' => ''),
            'LAB' => array('name' => 'Lab / ธนาคารเลือด', 're' => '/(เทคนิคการแพทย์|พยาธิ|ชันสูตร|ธนาคารเลือด|\blab\b)/iu', 'not' => ''),
            'XRAY' => array('name' => 'X-ray / รังสี', 're' => '/(รังสี|เอกซเรย์|เอ็กซเรย์|x-?ray)/iu', 'not' => ''),
            'PHARM' => array('name' => 'เภสัชกรรม', 're' => '/(จ่ายยา|ผลิตยา|เภสัชกรรม|ห้องยา)/u', 'not' => ''),
            'ENG' => array('name' => 'วิศวกรรม / ซ่อมบำรุง', 're' => '/(วิศวกรรม|ซ่อมบำรุง|ช่างซ่อม)/u', 'not' => ''),
            'IT' => array('name' => 'IT / คอมพิวเตอร์', 're' => '/(คอมพิวเตอร์|สารสนเทศ|\bIT\b)/iu', 'not' => '/เภสัชสนเทศ/u'),
            'EMS' => array('name' => 'EMS / Refer', 're' => '/(ส่งต่อ|สงต่อ|refer|EMS|ยานยนต์|พนักงานขับรถ)/iu', 'not' => ''),
            'HD' => array('name' => 'ไตเทียม', 're' => '/ไตเทียม/u', 'not' => ''),
            'LR' => array('name' => 'ห้องคลอด', 're' => '/ห้องคลอด/u', 'not' => ''),
        );
    }

    /** ระดับการเดินทางมาทำงาน 4 สี (Staff Accessibility Map) */
    public static function accessLevels() {
        return array(
            'red' => 'เดินทางไม่ได้',
            'orange' => 'เสี่ยงเดินทางไม่ได้',
            'yellow' => 'เดินทางลำบาก',
            'green' => 'เดินทางได้',
            'unknown' => 'ไม่ระบุ',
        );
    }

    /* ==================== ตาราง ==================== */

    public function ensureTables() {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        try {
            foreach (self::schemaSql() as $sql) {
                $this->db->exec($sql);
            }
            if ((int) $this->db->selectValue('SELECT COUNT(*) FROM flood_sat_facility') === 0) {
                $this->seedFacilities();
            }
            if ((int) $this->db->selectValue('SELECT COUNT(*) FROM flood_sat_route') === 0) {
                $this->seedRoutes();
            }
            $ready = true;
        } catch (Exception $e) {
            error_log('[flood] SAT ensureTables: ' . $e->getMessage());
            $ready = false;
        }
        return $ready;
    }

    /** คำสั่งสร้างตาราง — ชุดเดียวกับ sql/19_flood_sat.sql */
    public static function schemaSql() {
        $t = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        return array(
            "CREATE TABLE IF NOT EXISTS `flood_sat_setting` (
              `skey` VARCHAR(50) NOT NULL,
              `sval` TEXT DEFAULT NULL,
              `updated_by` INT UNSIGNED DEFAULT NULL,
              `updated_at` DATETIME DEFAULT NULL,
              PRIMARY KEY (`skey`)
            )" . $t,
            "CREATE TABLE IF NOT EXISTS `flood_sat_item` (
              `code` VARCHAR(30) NOT NULL,
              `status` VARCHAR(10) NOT NULL DEFAULT '' COMMENT 'green|yellow|orange|red',
              `note` TEXT DEFAULT NULL,
              `updated_by` INT UNSIGNED DEFAULT NULL,
              `updated_at` DATETIME DEFAULT NULL,
              PRIMARY KEY (`code`)
            )" . $t,
            "CREATE TABLE IF NOT EXISTS `flood_sat_item_log` (
              `log_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `code` VARCHAR(30) NOT NULL,
              `status` VARCHAR(10) NOT NULL DEFAULT '',
              `note` TEXT DEFAULT NULL,
              `user_id` INT UNSIGNED DEFAULT NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`log_id`),
              KEY `idx_flood_sat_item_log_code` (`code`, `created_at`)
            )" . $t,
            "CREATE TABLE IF NOT EXISTS `flood_sat_facility` (
              `facility_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `hcode` VARCHAR(10) DEFAULT NULL COMMENT 'รหัสหน่วยบริการ 5 หลัก (ถ้ามี)',
              `name` VARCHAR(200) NOT NULL,
              `ftype` VARCHAR(10) NOT NULL DEFAULT 'hs' COMMENT 'main|hospital|pcc|hs|other',
              `amphoe_code` CHAR(4) DEFAULT NULL,
              `tambon_code` CHAR(6) DEFAULT NULL,
              `lat` DECIMAL(10,7) DEFAULT NULL,
              `lng` DECIMAL(10,7) DEFAULT NULL,
              `loc_approx` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = พิกัดจุดกลางตำบล/อำเภอโดยประมาณ',
              `phone` VARCHAR(100) DEFAULT NULL,
              `contact` VARCHAR(150) DEFAULT NULL COMMENT 'ผู้ประสานงาน',
              `sort_order` INT NOT NULL DEFAULT 0,
              `is_active` TINYINT(1) NOT NULL DEFAULT 1,
              `note` VARCHAR(500) DEFAULT NULL,
              `created_by` INT UNSIGNED DEFAULT NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_by` INT UNSIGNED DEFAULT NULL,
              `updated_at` DATETIME DEFAULT NULL,
              PRIMARY KEY (`facility_id`),
              KEY `idx_flood_sat_facility_amphoe` (`amphoe_code`),
              KEY `idx_flood_sat_facility_hcode` (`hcode`)
            )" . $t,
            "CREATE TABLE IF NOT EXISTS `flood_sat_facility_log` (
              `log_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `facility_id` INT UNSIGNED NOT NULL,
              `status` VARCHAR(10) NOT NULL COMMENT 'green|yellow|orange|red',
              `service` VARCHAR(12) NOT NULL DEFAULT 'open' COMMENT 'open|partial|relocated|closed|unreachable',
              `relocated_to` VARCHAR(200) DEFAULT NULL,
              `road_access` VARCHAR(300) DEFAULT NULL,
              `staff_issue` VARCHAR(300) DEFAULT NULL,
              `utility_issue` VARCHAR(300) DEFAULT NULL,
              `patient_note` VARCHAR(300) DEFAULT NULL,
              `needs` VARCHAR(300) DEFAULT NULL,
              `contact_person` VARCHAR(150) DEFAULT NULL,
              `source` VARCHAR(10) NOT NULL DEFAULT 'call' COMMENT 'call|visit|news',
              `note` TEXT DEFAULT NULL,
              `user_id` INT UNSIGNED DEFAULT NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`log_id`),
              KEY `idx_flood_sat_facility_log_f` (`facility_id`, `log_id`)
            )" . $t,
            "CREATE TABLE IF NOT EXISTS `flood_sat_route` (
              `route_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `rtype` VARCHAR(10) NOT NULL DEFAULT 'refer' COMMENT 'in|out|refer',
              `name` VARCHAR(200) NOT NULL,
              `destination` VARCHAR(200) DEFAULT NULL,
              `segments` VARCHAR(300) DEFAULT NULL COMMENT 'ทางหลวง:กม.เริ่ม-กม.สิ้นสุด คั่นด้วย ; เช่น 33:215-245; 359:0-100',
              `is_backup` TINYINT(1) NOT NULL DEFAULT 0,
              `sort_order` INT NOT NULL DEFAULT 0,
              `is_active` TINYINT(1) NOT NULL DEFAULT 1,
              `note` VARCHAR(500) DEFAULT NULL,
              `created_by` INT UNSIGNED DEFAULT NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_by` INT UNSIGNED DEFAULT NULL,
              `updated_at` DATETIME DEFAULT NULL,
              PRIMARY KEY (`route_id`)
            )" . $t,
            "CREATE TABLE IF NOT EXISTS `flood_sat_route_check` (
              `check_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `route_id` INT UNSIGNED NOT NULL,
              `result` VARCHAR(10) NOT NULL COMMENT 'pass|slow|high|blocked',
              `note` VARCHAR(500) DEFAULT NULL,
              `user_id` INT UNSIGNED DEFAULT NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`check_id`),
              KEY `idx_flood_sat_route_check_r` (`route_id`, `check_id`)
            )" . $t,
            "CREATE TABLE IF NOT EXISTS `flood_sat_sitrep` (
              `sitrep_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `report_no` INT UNSIGNED NOT NULL,
              `report_at` DATETIME NOT NULL,
              `overall` VARCHAR(10) NOT NULL DEFAULT '',
              `body` MEDIUMTEXT NOT NULL,
              `data_json` MEDIUMTEXT DEFAULT NULL,
              `next_at` DATETIME DEFAULT NULL,
              `created_by` INT UNSIGNED DEFAULT NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`sitrep_id`),
              KEY `idx_flood_sat_sitrep_no` (`report_no`)
            )" . $t,
        );
    }

    /* ==================== ข้อมูลตั้งต้น (จังหวัดสระแก้ว) ==================== */

    /** จุดกลางตำบล (ชื่อตำบลในอำเภอของจังหวัด) — ไม่พบตำบลใช้จุดเฉลี่ยของอำเภอ */
    public function locateTambon($amphoeName, $tambonName, $province = '27') {
        if ($tambonName !== '') {
            $r = $this->db->selectOne(
                "SELECT t.tambon_code, t.amphoe_code, t.lat, t.lng FROM flood_tambon t
                 JOIN flood_amphoe a ON a.amphoe_code = t.amphoe_code
                 WHERE LEFT(a.amphoe_code, 2) = :p AND a.name = :a AND t.name = :t AND t.lat IS NOT NULL LIMIT 1",
                array(':p' => $province, ':a' => $amphoeName, ':t' => $tambonName));
            if ($r) {
                return $r;
            }
        }
        return $this->db->selectOne(
            "SELECT NULL AS tambon_code, a.amphoe_code, AVG(t.lat) AS lat, AVG(t.lng) AS lng FROM flood_amphoe a
             JOIN flood_tambon t ON t.amphoe_code = a.amphoe_code AND t.lat IS NOT NULL
             WHERE LEFT(a.amphoe_code, 2) = :p AND a.name = :a GROUP BY a.amphoe_code LIMIT 1",
            array(':p' => $province, ':a' => $amphoeName));
    }

    private function seedFacilities() {
        // โรงพยาบาลทั้ง 9 อำเภอ + รพ.สต. ที่ สสจ.สระแก้ว แจ้งว่าปิด/ย้ายจุดบริการ (ข้อมูล 27 ก.ย. 69 10:00)
        // สถานะตั้งต้นมาจากข่าว (source = news) — SAT ต้องโทรยืนยันใหม่ทุกแห่ง
        $news = 'ข่าว สสจ.สระแก้ว ข้อมูล 27 ก.ย. 69 10:00';
        $newsAt = '2026-09-27 10:00:00';
        $rows = array(
            array('main', 'โรงพยาบาลสมเด็จพระยุพราชสระแก้ว', 'เมืองสระแก้ว', 'สระแก้ว', '0-3724-3018-20', 'open', '', 'เปิด 24 ชม.'),
            array('hospital', 'โรงพยาบาลอรัญประเทศ', 'อรัญประเทศ', 'อรัญประเทศ', '', 'open', '', 'เปิด 24 ชม.'),
            array('hospital', 'โรงพยาบาลวัฒนานคร', 'วัฒนานคร', 'วัฒนานคร', '', 'open', '', 'เปิด 24 ชม.'),
            array('hospital', 'โรงพยาบาลตาพระยา', 'ตาพระยา', 'ตาพระยา', '', 'open', '', 'เปิด 24 ชม.'),
            array('hospital', 'โรงพยาบาลคลองหาด', 'คลองหาด', 'คลองหาด', '', 'open', '', 'เปิด 24 ชม.'),
            array('hospital', 'โรงพยาบาลวังน้ำเย็น', 'วังน้ำเย็น', 'วังน้ำเย็น', '', 'open', '', 'เปิด 24 ชม.'),
            array('hospital', 'โรงพยาบาลเขาฉกรรจ์', 'เขาฉกรรจ์', 'เขาฉกรรจ์', '', 'open', '', 'เปิด 24 ชม.'),
            array('hospital', 'โรงพยาบาลโคกสูง', 'โคกสูง', 'โคกสูง', '', 'open', '', 'เปิด 24 ชม.'),
            array('hospital', 'โรงพยาบาลวังสมบูรณ์', 'วังสมบูรณ์', 'วังสมบูรณ์', '', 'open', '', 'เปิด 24 ชม.'),
            array('hs', 'รพ.สต.บ้านแก้ง', 'เมืองสระแก้ว', 'บ้านแก้ง', '', 'relocated', 'วัดรีนิมิต', 'ปิดชั่วคราว ย้ายไปให้บริการที่วัดรีนิมิต'),
            array('hs', 'รพ.สต.โนนหมากมุ่น', 'โคกสูง', 'โนนหมากมุ่น', '', 'relocated', 'รพ.สต.โคกสูง', 'ปิดชั่วคราว ให้บริการทดแทนที่ รพ.สต.โคกสูง'),
            array('hs', 'รพ.สต.ท่าข้าม', 'อรัญประเทศ', 'ท่าข้าม', '', 'relocated', 'รพ.สต.คลองน้ำใส และจุดบริการวัด', 'ปิดชั่วคราว ให้บริการที่ รพ.สต.คลองน้ำใส และจุดบริการวัด'),
            array('hs', 'รพ.สต.บ้านห้วยเดื่อ', 'วัฒนานคร', '', '', 'relocated', 'รพ.สต.หนองหอย', 'ปิดชั่วคราว ให้บริการที่ รพ.สต.หนองหอย'),
            array('hs', 'รพ.สต.บ้านใหม่หนองไทร', 'อรัญประเทศ', 'บ้านใหม่หนองไทร', '', 'partial', 'เตรียมย้ายไป รพ.สต.บ้านโรงเรียน',
                'อยู่ระหว่างติดตามระดับน้ำ ถ้าจำเป็นจะย้ายไป รพ.สต.บ้านโรงเรียน'),
        );
        $sort = 0;
        $states = self::serviceStates();
        foreach ($rows as $r) {
            list($type, $name, $amphoe, $tambon, $phone, $service, $moved, $note) = $r;
            $loc = $this->locateTambon($amphoe, $tambon);
            $sort += 10;
            $fid = $this->db->insert('flood_sat_facility', array(
                'name' => $name, 'ftype' => $type,
                'amphoe_code' => $loc ? $loc['amphoe_code'] : null,
                'tambon_code' => $loc && $loc['tambon_code'] ? $loc['tambon_code'] : null,
                'lat' => $loc ? round((float) $loc['lat'], 7) : null,
                'lng' => $loc ? round((float) $loc['lng'], 7) : null,
                'loc_approx' => 1, 'phone' => $phone !== '' ? $phone : null, 'sort_order' => $sort, 'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ));
            $this->db->insert('flood_sat_facility_log', array(
                'facility_id' => $fid, 'status' => $states[$service]['color'], 'service' => $service,
                'relocated_to' => $moved !== '' ? $moved : null, 'source' => 'news',
                'note' => $note . ' (' . $news . ' — ต้องโทรยืนยัน)', 'created_at' => $newsAt,
            ));
        }
    }

    private function seedRoutes() {
        // ช่วง กม. ตั้งต้นโดยประมาณจากจุดที่กรมทางหลวงรายงาน — SAT ต้องตรวจ/แก้ให้ตรงเส้นทางที่ใช้จริง
        $note = 'ช่วง กม. ตั้งต้นโดยประมาณ — ตรวจ/แก้ให้ตรงเส้นทางที่ใช้จริง';
        $rows = array(
            array('in', 'เข้า รพ. ฝั่งตะวันตก (ทล.33 พระปรง–ศาลาลำดวน–บ้านแก้ง)', 'รพร.สระแก้ว', '33:215-245', 0),
            array('in', 'เข้า รพ. ฝั่งตะวันออก (ทล.33 วัฒนานคร–อรัญประเทศ)', 'รพร.สระแก้ว', '33:245-300', 0),
            array('out', 'ออกจาก รพ. / ถนนในเขตเทศบาล (ตรวจหน้างาน)', 'ชุมชนรอบโรงพยาบาล', '', 0),
            array('refer', 'Refer ตะวันตก ทล.33 → ปราจีนบุรี / กรุงเทพฯ', 'ปราจีนบุรี / กรุงเทพฯ', '33:150-245', 0),
            array('refer', 'Refer ทล.359 → ทล.304 ฉะเชิงเทรา / ชลบุรี', 'ฉะเชิงเทรา / ชลบุรี / กรุงเทพฯ', '359:0-100; 304:60-125', 1),
            array('refer', 'Refer ใต้ ทล.317 → จันทบุรี', 'จันทบุรี', '317:0-200', 1),
        );
        $sort = 0;
        foreach ($rows as $r) {
            $sort += 10;
            $this->db->insert('flood_sat_route', array(
                'rtype' => $r[0], 'name' => $r[1], 'destination' => $r[2], 'segments' => $r[3] !== '' ? $r[3] : null,
                'is_backup' => $r[4], 'sort_order' => $sort, 'is_active' => 1, 'note' => $r[3] !== '' ? $note : null,
                'created_at' => date('Y-m-d H:i:s'),
            ));
        }
    }

    /* ==================== ค่าตั้ง ==================== */

    public function settings() {
        $out = array();
        foreach ($this->db->select(
            'SELECT s.*, u.name AS by_name FROM flood_sat_setting s LEFT JOIN flood_user u ON u.user_id = s.updated_by') as $r) {
            $out[$r['skey']] = $r;
        }
        return $out;
    }

    public function setSetting($key, $val, $uid) {
        $this->db->prepare(
            'INSERT INTO flood_sat_setting (skey, sval, updated_by, updated_at) VALUES (:k, :v, :u, :t)
             ON DUPLICATE KEY UPDATE sval = VALUES(sval), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)')
            ->execute(array(':k' => $key, ':v' => $val, ':u' => $uid, ':t' => date('Y-m-d H:i:s')));
    }

    /** โรงพยาบาลแม่ข่าย: ชื่อ + พิกัด (ยังไม่ตั้ง = จุดกลาง ต.สระแก้ว โดยประมาณ) */
    public function hospital($settings = null) {
        $s = $settings === null ? $this->settings() : $settings;
        $name = isset($s['hosp_name']) && trim((string) $s['hosp_name']['sval']) !== '' ? trim($s['hosp_name']['sval']) : 'รพร.สระแก้ว';
        $lat = isset($s['hosp_lat']) ? (float) $s['hosp_lat']['sval'] : 0;
        $lng = isset($s['hosp_lng']) ? (float) $s['hosp_lng']['sval'] : 0;
        $approx = false;
        if (!flood_valid_latlng($lat, $lng)) {
            $loc = $this->locateTambon('เมืองสระแก้ว', 'สระแก้ว');
            $lat = $loc ? (float) $loc['lat'] : 13.803;
            $lng = $loc ? (float) $loc['lng'] : 102.077;
            $approx = true;
        }
        return array('name' => $name, 'lat' => $lat, 'lng' => $lng, 'approx' => $approx);
    }

    /* ==================== ตัวชี้วัด ==================== */

    public function itemStates() {
        $out = array();
        foreach ($this->db->select(
            'SELECT i.*, u.name AS by_name FROM flood_sat_item i LEFT JOIN flood_user u ON u.user_id = i.updated_by') as $r) {
            $out[$r['code']] = $r;
        }
        return $out;
    }

    public function saveItem($code, $status, $note, $uid) {
        $now = date('Y-m-d H:i:s');
        $this->db->prepare(
            'INSERT INTO flood_sat_item (code, status, note, updated_by, updated_at) VALUES (:c, :s, :n, :u, :t)
             ON DUPLICATE KEY UPDATE status = VALUES(status), note = VALUES(note), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)')
            ->execute(array(':c' => $code, ':s' => $status, ':n' => $note, ':u' => $uid, ':t' => $now));
        $this->db->insert('flood_sat_item_log', array('code' => $code, 'status' => $status, 'note' => $note,
            'user_id' => $uid, 'created_at' => $now));
    }

    public function itemHistory($code, $limit = 20) {
        return $this->db->select(
            'SELECT l.*, u.name AS by_name FROM flood_sat_item_log l LEFT JOIN flood_user u ON u.user_id = l.user_id
             WHERE l.code = :c ORDER BY l.log_id DESC LIMIT ' . (int) $limit, array(':c' => $code));
    }

    /* ==================== หน่วยบริการ ==================== */

    /** หน่วยบริการ + ผลโทรครั้งล่าสุด */
    public function facilities($activeOnly = true) {
        return $this->db->select(
            "SELECT f.*, a.name AS amphoe_name, t.name AS tambon_name,
                    l.log_id, l.status, l.service, l.relocated_to, l.road_access, l.staff_issue, l.utility_issue,
                    l.patient_note, l.needs, l.contact_person, l.source AS log_source, l.note AS log_note,
                    l.created_at AS log_at, lu.name AS log_by
             FROM flood_sat_facility f
             LEFT JOIN flood_amphoe a ON a.amphoe_code = f.amphoe_code
             LEFT JOIN flood_tambon t ON t.tambon_code = f.tambon_code
             LEFT JOIN (SELECT facility_id, MAX(log_id) AS mid FROM flood_sat_facility_log GROUP BY facility_id) x
                    ON x.facility_id = f.facility_id
             LEFT JOIN flood_sat_facility_log l ON l.log_id = x.mid
             LEFT JOIN flood_user lu ON lu.user_id = l.user_id"
            . ($activeOnly ? ' WHERE f.is_active = 1' : '') . "
             ORDER BY f.sort_order, f.facility_id");
    }

    public function getFacility($id) {
        return $this->db->selectOne('SELECT * FROM flood_sat_facility WHERE facility_id = :id', array(':id' => (int) $id));
    }

    public function saveFacility($id, $data, $uid) {
        if ($id > 0) {
            $data['updated_by'] = $uid;
            $data['updated_at'] = date('Y-m-d H:i:s');
            $this->db->update('flood_sat_facility', $data, 'facility_id = :w_id', array(':w_id' => (int) $id));
            return (int) $id;
        }
        $data['created_by'] = $uid;
        $data['created_at'] = date('Y-m-d H:i:s');
        if (!isset($data['sort_order'])) {
            $data['sort_order'] = (int) $this->db->selectValue('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM flood_sat_facility');
        }
        return $this->db->insert('flood_sat_facility', $data);
    }

    public function addFacilityLog($fid, $data, $uid) {
        $data['facility_id'] = (int) $fid;
        $data['user_id'] = $uid;
        $data['created_at'] = date('Y-m-d H:i:s');
        return $this->db->insert('flood_sat_facility_log', $data);
    }

    public function facilityLogs($fid, $limit = 10) {
        return $this->db->select(
            'SELECT l.*, u.name AS by_name FROM flood_sat_facility_log l LEFT JOIN flood_user u ON u.user_id = l.user_id
             WHERE l.facility_id = :f ORDER BY l.log_id DESC LIMIT ' . (int) $limit, array(':f' => (int) $fid));
    }

    /** ชื่อซ้ำ (ใช้ตอนนำเข้า) */
    public function facilityByName($name) {
        return $this->db->selectOne('SELECT * FROM flood_sat_facility WHERE name = :n LIMIT 1', array(':n' => $name));
    }

    public function facilityByHcode($hcode) {
        return $hcode === '' ? null
            : $this->db->selectOne('SELECT * FROM flood_sat_facility WHERE hcode = :h LIMIT 1', array(':h' => $hcode));
    }

    /** อำเภอในจังหวัด (สำหรับฟอร์ม/นำเข้า) */
    public function amphoes($province = '27') {
        return $this->db->select("SELECT amphoe_code, name FROM flood_amphoe WHERE LEFT(amphoe_code, 2) = :p ORDER BY amphoe_code",
            array(':p' => $province));
    }

    /* ==================== เส้นทาง ==================== */

    public function routes($activeOnly = true) {
        return $this->db->select(
            "SELECT r.*, c.check_id, c.result AS check_result, c.note AS check_note, c.created_at AS check_at, cu.name AS check_by
             FROM flood_sat_route r
             LEFT JOIN (SELECT route_id, MAX(check_id) AS mid FROM flood_sat_route_check GROUP BY route_id) x ON x.route_id = r.route_id
             LEFT JOIN flood_sat_route_check c ON c.check_id = x.mid
             LEFT JOIN flood_user cu ON cu.user_id = c.user_id"
            . ($activeOnly ? ' WHERE r.is_active = 1' : '') . "
             ORDER BY FIELD(r.rtype, 'in', 'out', 'refer'), r.sort_order, r.route_id");
    }

    public function getRoute($id) {
        return $this->db->selectOne('SELECT * FROM flood_sat_route WHERE route_id = :id', array(':id' => (int) $id));
    }

    public function saveRoute($id, $data, $uid) {
        if ($id > 0) {
            $data['updated_by'] = $uid;
            $data['updated_at'] = date('Y-m-d H:i:s');
            $this->db->update('flood_sat_route', $data, 'route_id = :w_id', array(':w_id' => (int) $id));
            return (int) $id;
        }
        $data['created_by'] = $uid;
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['sort_order'] = (int) $this->db->selectValue('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM flood_sat_route');
        return $this->db->insert('flood_sat_route', $data);
    }

    public function addRouteCheck($rid, $result, $note, $uid) {
        return $this->db->insert('flood_sat_route_check', array('route_id' => (int) $rid, 'result' => $result,
            'note' => $note !== '' ? $note : null, 'user_id' => $uid, 'created_at' => date('Y-m-d H:i:s')));
    }

    /** "33:215-245; 359:0-100; 317" → [[road, from, to], ...] (ไม่ใส่ กม. = ทั้งสาย) */
    public static function parseSegments($spec) {
        $out = array();
        foreach (preg_split('/[;,\n]+/u', (string) $spec) as $part) {
            $part = trim(str_replace(array('ทล.', 'ทล', ' '), '', $part));
            if ($part === '') {
                continue;
            }
            if (preg_match('/^(\d{1,4})(?::(\d+(?:\.\d+)?)\s*[-–]\s*(\d+(?:\.\d+)?))?$/u', $part, $m)) {
                $a = isset($m[2]) && $m[2] !== '' ? (float) $m[2] : null;
                $b = isset($m[3]) && $m[3] !== '' ? (float) $m[3] : null;
                if ($a !== null && $b !== null && $a > $b) {
                    list($a, $b) = array($b, $a);
                }
                $out[] = array(ltrim($m[1], '0'), $a, $b);
            }
        }
        return $out;
    }

    /** ชื่อพื้นที่จาก hdms เช่น "ทล.33 น้ำท่วม (พระปรง - โนนจิก กม.218+359–236+300)" → [ทางหลวง, กม.เริ่ม, กม.สิ้นสุด, ช่วง] */
    public static function parseHdmsName($name) {
        $road = '';
        if (preg_match('/^ทล\.\s?(\d+)/u', (string) $name, $m)) {
            $road = ltrim($m[1], '0');
        }
        $a = null;
        $b = null;
        if (preg_match_all('/กม\.\s?(\d+)\+(\d+)(?:\s*[–-]\s*(\d+)\+(\d+))?/u', (string) $name, $mm, PREG_SET_ORDER)) {
            $last = end($mm);   // ช่วง กม. ในวงเล็บท้ายชื่อ (มาตรฐานของ HDMS)
            $a = (float) $last[1] + (float) $last[2] / 1000;
            $b = isset($last[3]) && $last[3] !== '' ? (float) $last[3] + (float) $last[4] / 1000 : $a;
            if ($a > $b) {
                list($a, $b) = array($b, $a);
            }
        }
        // ช่วงควบคุมในวงเล็บท้ายชื่อ — อาจมีวงเล็บซ้อน เช่น "(โนนจิก - อรัญประเทศ(เขตแดนไทย/กัมพูชา) กม.285+000–285+400)"
        // ไล่จากท้ายทีละไบต์ได้เพราะ ( ) เป็น ASCII ไม่ปนกับไบต์ของอักษรไทยใน UTF-8
        $sect = '';
        $s = rtrim((string) $name);
        $n = strlen($s);
        if ($n > 0 && $s[$n - 1] === ')') {
            $depth = 0;
            for ($i = $n - 1; $i >= 0; $i--) {
                if ($s[$i] === ')') {
                    $depth++;
                } elseif ($s[$i] === '(') {
                    $depth--;
                    if ($depth === 0) {
                        $sect = trim(substr($s, $i + 1, $n - $i - 2));
                        break;
                    }
                }
            }
        }
        return array($road, $a, $b, $sect);
    }

    /** จุดน้ำท่วมทางหลวงจากกรมทางหลวงที่ยังเปิดอยู่ทั้งหมด (+ แยกทางหลวง/ช่วง กม. จากชื่อ) */
    public function hdmsZones() {
        $rows = $this->db->select(
            "SELECT z.zone_id, z.name, z.level, z.center_lat, z.center_lng, z.radius_m, z.note, z.started_at, z.updated_at,
                    a.name AS amphoe_name, LEFT(z.amphoe_code, 2) AS province_code
             FROM flood_zone z LEFT JOIN flood_amphoe a ON a.amphoe_code = z.amphoe_code
             WHERE z.status = 'active' AND z.source = 'hdms'");
        foreach ($rows as &$r) {
            list($r['road'], $r['km_from'], $r['km_to'], $r['section']) = self::parseHdmsName($r['name']);
        }
        unset($r);
        return $rows;
    }

    /** จุดทางหลวงที่อยู่บนเส้นทาง (ทางหลวงเดียวกัน + ช่วง กม. ซ้อนกัน) */
    public static function routeMatches($segments, $zones) {
        $hits = array();
        foreach ($zones as $z) {
            if ($z['road'] === '') {
                continue;
            }
            foreach ($segments as $s) {
                if ($s[0] !== $z['road']) {
                    continue;
                }
                // ไม่ระบุช่วง กม. = ทั้งสาย · จุดที่ไม่มี กม. จับคู่ได้เฉพาะเส้นทางที่ระบุทั้งสาย
                $whole = $s[1] === null;
                $overlap = $z['km_from'] !== null && $s[1] !== null && $z['km_to'] >= $s[1] && $z['km_from'] <= $s[2];
                if ($whole || $overlap) {
                    $hits[(int) $z['zone_id']] = $z;
                    break;
                }
            }
        }
        usort($hits, function ($x, $y) {
            $o = Sat_Model::rank(Sat_Model::levelColor($y['level'])) - Sat_Model::rank(Sat_Model::levelColor($x['level']));
            if ($o !== 0) {
                return $o;
            }
            $o = strcmp((string) $x['road'], (string) $y['road']);
            if ($o !== 0) {
                return $o;
            }
            $ka = (float) $x['km_from'];
            $kb = (float) $y['km_from'];
            return $ka == $kb ? 0 : ($ka < $kb ? -1 : 1);
        });
        return array_values($hits);
    }

    /** พื้นที่ประกาศที่ยังเปิดอยู่ในระยะ $km จากจุด (สำหรับแผนที่ SAT) */
    public function nearbyZones($lat, $lng, $km = 60, $limit = 600) {
        $dLat = $km / 111.32;
        $dLng = $km / (111.32 * max(0.2, cos(deg2rad($lat))));
        return $this->db->select(
            "SELECT zone_id, name, level, shape, center_lat, center_lng, radius_m, polygon_json, source, note, started_at, updated_at
             FROM flood_zone
             WHERE status = 'active' AND center_lat BETWEEN :a AND :b AND center_lng BETWEEN :c AND :d
             ORDER BY zone_id DESC LIMIT " . (int) $limit,
            array(':a' => $lat - $dLat, ':b' => $lat + $dLat, ':c' => $lng - $dLng, ':d' => $lng + $dLng));
    }

    /* ==================== บุคลากร (Critical Staff) ==================== */

    /** ระดับการเดินทางของบุคลากร 1 คน จากคำตอบแบบสำรวจ (flood_staff) */
    public static function staffAccess($s) {
        $t = (string) $s['travel'];
        $w = (string) $s['work_status'];
        $v = (string) $s['victim'];
        if (preg_match('/ตัดขาดอย่างสิ้นเชิง|ไม่สามารถเดินทาง/u', $t) || mb_strpos($w, 'ไม่สามารถมาปฏิบัติงาน') !== false) {
            return 'red';
        }
        if (preg_match('/รถยกสูง|เรือ/u', $t) || mb_strpos($v, 'อาจจะเกิดขึ้น') !== false) {
            return 'orange';
        }
        if (preg_match('/เส้นทางเลี่ยง|ลำบาก/u', $t . ' ' . $w) || preg_match('/ถนน|ปิดกั้น/u', $v)) {
            return 'yellow';
        }
        if (mb_strpos($t . ' ' . $w, 'ได้ตามปกติ') !== false || preg_match('/^ไม่เป็นผู้ประสบภัย/u', $v)) {
            return 'green';
        }
        return 'unknown';
    }

    /** ตรงกับหน่วยสำคัญหน่วยไหน (ชื่อหน่วยงานพิมพ์เอง — จับคู่ด้วยคำ) */
    public static function staffUnit($dept) {
        $dept = trim((string) $dept);
        if ($dept === '') {
            return '';
        }
        foreach (self::criticalUnits() as $code => $u) {
            if (preg_match($u['re'], $dept) && ($u['not'] === '' || !preg_match($u['not'], $dept))) {
                return $code;
            }
        }
        return '';
    }

    /** สรุป 4 สี ทั้งหมด + รายหน่วยสำคัญ + ป้ายเด่น (ไม่มีชื่อบุคคล) */
    public function staffSummary() {
        $blank = array('red' => 0, 'orange' => 0, 'yellow' => 0, 'green' => 0, 'unknown' => 0, 'total' => 0);
        $out = array('ready' => false, 'all' => $blank, 'units' => array(), 'flags' => array(), 'last_at' => null);
        try {
            if (!$this->db->selectValue("SHOW TABLES LIKE 'flood_staff'")) {
                return $out;
            }
            $rows = $this->db->select('SELECT department, travel, work_status, victim, flags, last_at FROM flood_staff');
        } catch (Exception $e) {
            return $out;
        }
        $out['ready'] = true;
        foreach (self::criticalUnits() as $code => $u) {
            $out['units'][$code] = $blank;
        }
        $flags = array('cant_work' => 0, 'stranded' => 0, 'need_shelter' => 0, 'home_hit' => 0);
        foreach ($rows as $r) {
            $a = self::staffAccess($r);
            $out['all'][$a]++;
            $out['all']['total']++;
            $unit = self::staffUnit($r['department']);
            if ($unit !== '') {
                $out['units'][$unit][$a]++;
                $out['units'][$unit]['total']++;
            }
            foreach (array_filter(explode(',', (string) $r['flags'])) as $f) {
                if (isset($flags[$f])) {
                    $flags[$f]++;
                }
            }
            if ($r['last_at'] && ($out['last_at'] === null || $r['last_at'] > $out['last_at'])) {
                $out['last_at'] = $r['last_at'];
            }
        }
        $out['flags'] = $flags;
        return $out;
    }

    /* ==================== SitRep ==================== */

    public function sitreps($limit = 30) {
        return $this->db->select(
            'SELECT s.sitrep_id, s.report_no, s.report_at, s.overall, s.next_at, s.created_at, u.name AS by_name
             FROM flood_sat_sitrep s LEFT JOIN flood_user u ON u.user_id = s.created_by
             ORDER BY s.sitrep_id DESC LIMIT ' . (int) $limit);
    }

    public function getSitrep($id) {
        return $this->db->selectOne(
            'SELECT s.*, u.name AS by_name FROM flood_sat_sitrep s LEFT JOIN flood_user u ON u.user_id = s.created_by
             WHERE s.sitrep_id = :id', array(':id' => (int) $id));
    }

    public function nextSitrepNo() {
        return (int) $this->db->selectValue('SELECT COALESCE(MAX(report_no), 0) + 1 FROM flood_sat_sitrep');
    }

    public function saveSitrep($data, $uid) {
        $data['report_no'] = $this->nextSitrepNo();
        $data['created_by'] = $uid;
        $data['created_at'] = date('Y-m-d H:i:s');
        $id = $this->db->insert('flood_sat_sitrep', $data);
        return array($id, $data['report_no']);
    }
}
