<?php

require_once 'models/staff_model.php';   // ใช้ตัวอ่าน CSV / ทำความสะอาดค่า / ลิงก์ชีต ชุดเดียวกับหน้าบุคลากร

/**
 * นำเข้าทะเบียนกลุ่มเปราะบางจาก Google Sheet (รายชื่อรายคน) — แบบเดียวกับหน้าบุคลากรที่ได้รับผลกระทบ
 *
 * - แหล่งข้อมูลอยู่ในตาราง flood_vuln_source (ผู้ดูแลระบบเพิ่ม/แก้/ปิด) · ปุ่ม "ดึง" ให้เบราว์เซอร์อ่านชีต (gviz) แล้วส่ง CSV มา
 * - จับคอลัมน์จากชื่อหัวคอลัมน์ (columnRules) ไม่ต้องเรียงคอลัมน์ตามแบบ · หาแถวหัวคอลัมน์เองใน 12 แถวแรก
 * - ไม่เก็บเลขบัตรประชาชน (คอลัมน์และค่าที่เป็นเลข 13 หลักถูกข้าม)
 * - นำเข้าซ้ำได้: จับคู่คนเดิมด้วย HN → เบอร์โทร+ชื่อ → ชื่อ+ตำบล/เบอร์ (ต้องมีข้อมูลยืนยันอย่างน้อย 1 อย่าง)
 * - ไม่แตะสถานะอพยพ/ประวัติการติดตาม · บันทึกของเจ้าหน้าที่ไม่ถูกทับ · พิกัด/พื้นที่ที่เจ้าหน้าที่ปักไว้แล้วไม่ถูกทับ
 * - คนที่เจ้าหน้าที่นำออกจากทะเบียนแล้ว จะไม่ถูกเพิ่มกลับจากชีต
 *
 * ชีตประเภท "รายงานศูนย์พักพิง" (kind = shelter) เช่น รายงาน สสอ. ที่มีแท็บรายวัน "28 ก.ย.69" (1 แถว = 1 ศูนย์พักพิง)
 * - เก็บเป็นตัวเลขรายศูนย์รายวันในตาราง flood_shelter_report — ไม่แปลงเป็นรายชื่อคน (ชีตไม่มีชื่อ)
 * - ดึงแท็บรายวันทีละวัน · ดึงวันเดิมซ้ำ = แทนที่ข้อมูลวันนั้นทั้งชุด · ตรวจวันที่ในหัวรายงานของแท็บก่อนรับ
 */
class Vulnerable_Import_Model extends Model {

    const MAX_BYTES = 8388608;   // 8 MB ต่อชีต
    const RAW_MAX = 20000;       // ขนาด JSON ข้อมูลจากชีตต่อคน

    /* ==================== ตาราง ==================== */

    /** สร้างตาราง/คอลัมน์ถ้ายังไม่มี (เหมือน sql/20_flood_vuln_source.sql) — false = ไม่มีสิทธิ์ ALTER/CREATE */
    public function ensureTables() {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        try {
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS `flood_vuln_source` (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $srcCols = array();
            foreach ($this->db->select('SHOW COLUMNS FROM flood_vuln_source') as $c) {
                $srcCols[$c['Field']] = true;
            }
            if (!isset($srcCols['kind'])) {
                $this->db->exec("ALTER TABLE `flood_vuln_source` ADD COLUMN `kind` VARCHAR(10) NOT NULL DEFAULT 'persons'
                    COMMENT 'persons = รายชื่อรายคน · shelter = รายงานศูนย์พักพิง (ตัวเลขรายศูนย์รายวัน)' AFTER `sheet_url`");
            }
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS `flood_shelter_report` (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $cols = array();
            foreach ($this->db->select('SHOW COLUMNS FROM flood_vulnerable') as $c) {
                $cols[$c['Field']] = true;
            }
            $add = array(
                'src_id' => "ADD COLUMN `src_id` INT UNSIGNED DEFAULT NULL COMMENT 'flood_vuln_source ที่นำเข้ามา (NULL = เจ้าหน้าที่เพิ่มเอง)'",
                'src_key' => "ADD COLUMN `src_key` CHAR(40) DEFAULT NULL COMMENT 'sha1(แหล่ง|HN หรือ เบอร์+ชื่อ) ใช้จับคู่รอบถัดไป'",
                'src_raw' => "ADD COLUMN `src_raw` MEDIUMTEXT DEFAULT NULL COMMENT 'JSON {หัวคอลัมน์: ค่า} จากชีต ไม่มีเลขบัตรประชาชน'",
                'src_synced_at' => "ADD COLUMN `src_synced_at` DATETIME DEFAULT NULL",
            );
            $alter = array();
            foreach ($add as $col => $sql) {
                if (!isset($cols[$col])) {
                    $alter[] = $sql;
                }
            }
            if ($alter) {
                $this->db->exec('ALTER TABLE `flood_vulnerable` ' . implode(', ', $alter));
            }
            $hasKey = false;
            foreach ($this->db->select('SHOW INDEX FROM flood_vulnerable') as $ix) {
                if ($ix['Key_name'] === 'idx_flood_vulnerable_src') {
                    $hasKey = true;
                }
            }
            if (!$hasKey) {
                $this->db->exec('ALTER TABLE `flood_vulnerable` ADD KEY `idx_flood_vulnerable_src` (`src_id`, `src_key`)');
            }
            $ready = true;
        } catch (Exception $e) {
            error_log('[flood] เตรียมตารางนำเข้ากลุ่มเปราะบางไม่ได้ (รัน php sql/apply_schema.php 20): ' . $e->getMessage());
            $ready = false;
        }
        return $ready;
    }

    /* ==================== แหล่งข้อมูล ==================== */

    public function sources($activeOnly = false) {
        return $this->db->select("SELECT s.*,
                (SELECT COUNT(*) FROM flood_vulnerable v WHERE v.src_id = s.source_id AND v.is_active = 1) AS people,
                (SELECT COUNT(DISTINCT r.report_date) FROM flood_shelter_report r WHERE r.source_id = s.source_id) AS days,
                (SELECT MAX(r.report_date) FROM flood_shelter_report r WHERE r.source_id = s.source_id) AS last_day
            FROM flood_vuln_source s" . ($activeOnly ? ' WHERE s.is_active = 1' : '') . ' ORDER BY s.source_id');
    }

    public function getSource($id) {
        return $this->db->selectOne('SELECT * FROM flood_vuln_source WHERE source_id = :id', array(':id' => (int) $id));
    }

    public function saveSource($id, $name, $url, $groups, $kind = 'persons') {
        $name = trim(mb_substr((string) $name, 0, 150));
        if (!Staff_Model::parseSheetUrl($url)) {
            return 'ลิงก์ต้องเป็น Google Sheet (https://docs.google.com/spreadsheets/d/…)';
        }
        if ($name === '') {
            return 'กรุณาตั้งชื่อแหล่งข้อมูล';
        }
        $g = flood_codes_filter($groups, flood_vulnerable_groups());
        $row = array('name' => $name, 'sheet_url' => mb_substr(trim($url), 0, 500), 'default_groups' => $g ? implode(',', $g) : null,
            'kind' => $kind === 'shelter' ? 'shelter' : 'persons');
        if ($id > 0) {
            $this->db->update('flood_vuln_source', $row, 'source_id = :id', array(':id' => (int) $id));
            return (int) $id;
        }
        $row['is_active'] = 1;
        return (int) $this->db->insert('flood_vuln_source', $row);
    }

    public function setSourceActive($id, $on) {
        $this->db->update('flood_vuln_source', array('is_active' => $on ? 1 : 0), 'source_id = :id', array(':id' => (int) $id));
    }

    public function setKind($id, $kind) {
        $this->db->update('flood_vuln_source', array('kind' => $kind === 'shelter' ? 'shelter' : 'persons', 'last_error' => null),
            'source_id = :id', array(':id' => (int) $id));
    }

    public function markSyncError($sourceId, $msg) {
        $this->db->update('flood_vuln_source', array('last_error' => mb_substr($msg, 0, 500), 'last_sync_at' => date('Y-m-d H:i:s')),
            'source_id = :id', array(':id' => (int) $sourceId));
    }

    /* ==================== จับคอลัมน์ ==================== */

    /** ชื่อฟิลด์ → ชื่อไทย (แสดงผลการจับคอลัมน์ให้เจ้าหน้าที่ตรวจ) */
    public static function fieldLabels() {
        $l = array(
            'name' => 'ชื่อ-สกุล', 'prefix' => 'คำนำหน้า', 'first_name' => 'ชื่อ', 'last_name' => 'นามสกุล', 'hn' => 'HN',
            'sex' => 'เพศ', 'birth_date' => 'วันเกิด', 'age' => 'อายุ', 'adl' => 'ADL', 'groups' => 'กลุ่มเปราะบาง',
            'mobility' => 'การเคลื่อนย้าย', 'medical_needs' => 'ความต้องการทางการแพทย์', 'address' => 'ที่อยู่', 'village' => 'หมู่บ้าน',
            'moo' => 'หมู่', 'tambon' => 'ตำบล', 'amphoe' => 'อำเภอ', 'province' => 'จังหวัด', 'latlng' => 'พิกัด', 'lat' => 'ละติจูด',
            'lng' => 'ลองจิจูด', 'phone' => 'เบอร์โทร', 'caregiver_name' => 'ผู้ดูแล', 'caregiver_phone' => 'เบอร์ผู้ดูแล', 'note' => 'หมายเหตุ',
            '_skip' => 'ไม่เก็บ (เลขบัตรประชาชน)', '_ignore' => 'ไม่ใช้ (ลำดับ)', '_raw' => 'เก็บไว้ดูอย่างเดียว',
        );
        foreach (flood_vulnerable_groups() as $code => $n) {
            $l['grp:' . $code] = 'กลุ่ม: ' . $n;
        }
        return $l;
    }

    /** คำในข้อความ → กลุ่มเปราะบาง */
    public static function groupRules() {
        return array(
            'bedridden' => '/ติดเตียง|bed\s*-?\s*ridden/iu',
            'elderly' => '/สูงอายุ|สูงวัย|elderly/iu',
            'disabled' => '/พิการ|disab/iu',
            'dialysis' => '/ฟอกไต|ล้างไต|ล้างช่องท้อง|ฟอกเลือด|\bCAPD\b|\bAPD\b|\bHD\b|dialysis/iu',
            'oxygen' => '/ออกซิเจน|\bO2\b|oxygen|เครื่องช่วยหายใจ|ventilator|เจาะคอ|trache/iu',
            'pregnant' => '/ตั้งครรภ์|มีครรภ์|ใกล้คลอด|อายุครรภ์|\bANC\b|pregnan/iu',
            'infant' => '/เด็กเล็ก|ทารก|แรกเกิด|เด็กอายุ\s*0|infant|newborn/iu',
            'psychiatric' => '/จิตเวช|ป่วยทางจิต|\bSMI\b|psych/iu',
        );
    }

    /**
     * หัวคอลัมน์ → ฟิลด์ (ตรวจตามลำดับ ตัวแรกที่ตรงชนะ) · ข้อที่เฉพาะกว่าอยู่ก่อน
     * _skip = ไม่เก็บเลย · _ignore = ไม่ใช้ · _raw = เก็บไว้ดูในข้อมูลจากชีตอย่างเดียว
     */
    private static function columnRules() {
        return array(
            array('address', '/ที่อยู่ตามบัตร|ที่อยู่ตามทะเบียน/u'),
            array('_skip', '/เลขบัตร|บัตรประชาชน|เลขประจำตัวประชาชน|citizen|^\s*CID\b|^\s*PID\b|13\s*หลัก/iu'),
            array('_ignore', '/^\s*(ลำดับ|ลำดับที่|ที่|no\.?|#)\s*$/iu'),
            array('_raw', '/ประทับเวลา|timestamp|วันที่บันทึก|วันที่สำรวจ|หน่วยบริการ|รพ\.?\s*สต|สถานบริการ|ผู้รับผิดชอบ|ผู้บันทึก|ผู้รายงาน|ผู้สำรวจ|อสม|พักพิง|อพยพ/iu'),
            array('medical_needs', '/ชื่อโรค|ชื่อยา|โรคประจำตัว/u'),
            array('hn', '/^\s*H\.?\s*N\.?(\s|$|\()|เลขประจำตัวผู้ป่วย|เลขที่ผู้ป่วย/iu'),
            array('caregiver_phone', '/(เบอร์|โทร).*(ผู้ดูแล|ญาติ|ผู้ติดต่อ|ผู้ประสาน)|(ผู้ดูแล|ญาติ|ผู้ติดต่อ|ผู้ประสาน).*(เบอร์|โทร)/u'),
            array('caregiver_name', '/ผู้ดูแล|ญาติ|ผู้ติดต่อ|caregiver/iu'),
            array('phone', '/เบอร์|โทร|phone|mobile|^\s*tel/iu'),
            array('village', '/หมู่บ้าน|ชื่อบ้าน/u'),
            array('latlng', '/พิกัด|lat\s*[,\/]\s*l(on|ng)|google\s*map|location|แผนที่/iu'),
            array('lat', '/^\s*lat|ละติจูด/iu'),
            array('lng', '/^\s*l(on|ng)|ลองจิจูด/iu'),
            array('prefix', '/^\s*คำนำหน้า/u'),
            array('last_name', '/^\s*(นาม)?สกุล|last\s*name|surname/iu'),
            array('first_name', '/^\s*ชื่อ\s*$|^\s*ชื่อ\s*\(|^\s*ชื่อตัว|first\s*name/iu'),
            array('name', '/ชื่อ|^\s*name/iu'),
            array('sex', '/^\s*เพศ|^\s*sex|gender/iu'),
            array('birth_date', '/วันเกิด|วัน\s*เดือน\s*ปี\s*เกิด|ว\s*\/\s*ด\s*\/\s*ป\s*เกิด|birth|\bdob\b/iu'),
            array('grp:pregnant', '/อายุครรภ์|\bGA\b/iu'),
            array('age', '/^\s*อายุ|^\s*age\b/iu'),
            array('adl', '/\bADL\b|บาร์เทล|barthel|กิจวัตรประจำวัน/iu'),
            array('groups', '/กลุ่มเปราะบาง|กลุ่มเสี่ยง|^\s*กลุ่ม|ประเภท|สถานะผู้ป่วย|category/iu'),
            array('mobility', '/เคลื่อนไหว|เคลื่อนย้าย|ช่วยเหลือตนเอง|ช่วยเหลือตัวเอง|mobility|การเดิน(?!ทาง)/iu'),
            array('medical_needs', '/โรค|วินิจฉัย|diag|ความต้องการ|อุปกรณ์|การรักษา|ยาที่ใช้|ยาประจำ|ปัญหาสุขภาพ|อาการ|medical/iu'),
            array('_groupcol', ''),   // หัวคอลัมน์ที่เป็นชื่อกลุ่ม เช่น "ติดเตียง" "ฟอกไต" (ตรวจด้วย groupRules)
            array('address', '/ที่อยู่|บ้านเลขที่|address|ที่พักอาศัย/iu'),
            array('moo', '/^\s*(หมู่|หมู่ที่|ม\.)\s*(ที่)?\s*$|^\s*moo/iu'),
            array('tambon', '/ตำบล|^\s*ต\.\s*$|tambon|sub-?district/iu'),
            array('amphoe', '/อำเภอ|^\s*อ\.\s*$|amphoe|district/iu'),
            array('province', '/จังหวัด|^\s*จ\.\s*$|province/iu'),
            array('note', '/หมายเหตุ|note|remark/iu'),
        );
    }

    public static function mapHeader($h) {
        $h = trim((string) $h);
        if ($h === '') {
            return null;
        }
        foreach (self::columnRules() as $r) {
            if ($r[0] === '_groupcol') {
                foreach (self::groupRules() as $code => $re) {
                    if (preg_match($re, $h)) {
                        return 'grp:' . $code;
                    }
                }
                continue;
            }
            if (preg_match($r[1], $h)) {
                return $r[0];
            }
        }
        return null;
    }

    /** แถวหัวคอลัมน์ = แถวใน 12 แถวแรกที่จับฟิลด์ได้มากที่สุด และมีคอลัมน์ชื่อ */
    public static function findHeader($rows) {
        $best = -1;
        $bestScore = 0;
        foreach (array_slice($rows, 0, 12) as $i => $r) {
            $fields = array();
            foreach ($r as $h) {
                $f = self::mapHeader(Staff_Model::clean($h));
                if ($f !== null && $f[0] !== '_') {
                    $fields[$f] = true;
                }
            }
            if (!isset($fields['name']) && !isset($fields['first_name'])) {
                continue;
            }
            if (count($fields) > $bestScore) {
                $bestScore = count($fields);
                $best = $i;
            }
        }
        return $best;
    }

    /* ==================== แปลงค่า ==================== */

    public static function detectGroups($text) {
        $out = array();
        $text = (string) $text;
        if ($text === '') {
            return $out;
        }
        foreach (self::groupRules() as $code => $re) {
            if (preg_match($re, $text)) {
                $out[] = $code;
            }
        }
        return $out;
    }

    public static function mobilityFrom($text) {
        $t = (string) $text;
        if ($t === '') {
            return null;
        }
        if (preg_match('/ติดเตียง|bed/iu', $t)) {
            return 'bedridden';
        }
        if (preg_match('/รถเข็น|wheel/iu', $t)) {
            return 'wheelchair';
        }
        if (preg_match('/พยุง|ไม้เท้า|walker|ช่วยเหลือบางส่วน|ติดบ้าน|ต้องมีคน/iu', $t)) {
            return 'assisted';
        }
        if (preg_match('/เดินได้|ช่วยเหลือตัวเองได้|ช่วยเหลือตนเองได้|ติดสังคม/iu', $t)) {
            return 'walk';
        }
        return null;
    }

    /** ADL (Barthel 0–20): 0–4 ติดเตียง · 5–11 ติดบ้าน · 12+ ติดสังคม */
    public static function adlInfo($v) {
        $v = trim((string) $v);
        if (preg_match('/^\d{1,2}$/', $v)) {
            $n = (int) $v;
            if ($n <= 4) {
                return array(array('bedridden'), 'bedridden');
            }
            return array(array(), $n <= 11 ? 'assisted' : 'walk');
        }
        return array(self::detectGroups($v), self::mobilityFrom($v));
    }

    public static function isNegative($v) {
        return (bool) preg_match('/^\s*(0|ไม่|ไม่มี|ไม่ใช่|ไม่ได้|no|n|false|✗|×)\s*$/iu', (string) $v);
    }

    public static function isTruthy($v) {
        return (bool) preg_match('/^\s*(1|✓|✔|☑|●|\/|x|ใช่|มี|yes|y|true)\s*$/iu', (string) $v);
    }

    public static function sexFrom($v) {
        $v = trim((string) $v);
        if (preg_match('/^(ชาย|ช|m|male|1)$/iu', $v)) {
            return 'M';
        }
        if (preg_match('/^(หญิง|ญ|f|female|2)$/iu', $v)) {
            return 'F';
        }
        return null;
    }

    public static function sexFromName($name) {
        $n = trim((string) $name);
        if (preg_match('/^(นางสาว|น\.\s*ส\.|นาง|ด\.\s*ญ\.|เด็กหญิง|mrs|ms|miss)/iu', $n)) {
            return 'F';
        }
        if (preg_match('/^(นาย|ด\.\s*ช\.|เด็กชาย|พระ|mr)/iu', $n)) {
            return 'M';
        }
        return null;
    }

    /** วันเกิด: 12/05/2490 · 1947-05-12 · 12 พ.ค. 2490 · 12 พฤษภาคม 2490 (ปี พ.ศ./ค.ศ. 4 หลัก — ปี 2 หลักไม่เดา) */
    public static function parseBirth($v) {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        $y = $m = $d = 0;
        if (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})#', $v, $x)) {
            list(, $d, $m, $y) = $x;
        } elseif (preg_match('#^(\d{4})-(\d{1,2})-(\d{1,2})#', $v, $x)) {
            list(, $y, $m, $d) = $x;
        } else {
            $months = array('ม.ค' => 1, 'มกรา' => 1, 'ก.พ' => 2, 'กุมภา' => 2, 'มี.ค' => 3, 'มีนา' => 3, 'เม.ย' => 4, 'เมษา' => 4,
                'พ.ค' => 5, 'พฤษภา' => 5, 'มิ.ย' => 6, 'มิถุนา' => 6, 'ก.ค' => 7, 'กรกฎา' => 7, 'ส.ค' => 8, 'สิงหา' => 8,
                'ก.ย' => 9, 'กันยา' => 9, 'ต.ค' => 10, 'ตุลา' => 10, 'พ.ย' => 11, 'พฤศจิกา' => 11, 'ธ.ค' => 12, 'ธันวา' => 12);
            if (preg_match('/^(\d{1,2})\s*([ก-๙.]+)\s*(\d{4})$/u', $v, $x)) {
                foreach ($months as $k => $n) {
                    if (mb_strpos($x[2], $k) === 0) {
                        list($d, $m, $y) = array($x[1], $n, $x[3]);
                        break;
                    }
                }
            }
        }
        $y = (int) $y;
        $m = (int) $m;
        $d = (int) $d;
        if ($y > 2400) {
            $y -= 543;
        }
        if ($y < 1900 || !checkdate($m, $d, $y)) {
            return null;
        }
        $s = sprintf('%04d-%02d-%02d', $y, $m, $d);
        return $s <= date('Y-m-d') ? $s : null;
    }

    /** "13.81, 102.07" · ลิงก์ Google Maps ที่มี @lat,lng หรือ q=lat,lng */
    public static function parseLatLng($v) {
        if (preg_match('/(-?\d{1,3}\.\d{2,})\s*[,\s]\s*(-?\d{1,3}\.\d{2,})/', (string) $v, $x)) {
            if (flood_valid_latlng($x[1], $x[2])) {
                return array(round((float) $x[1], 7), round((float) $x[2], 7));
            }
            if (flood_valid_latlng($x[2], $x[1])) {
                return array(round((float) $x[2], 7), round((float) $x[1], 7));
            }
        }
        return null;
    }

    /** เบอร์แรกในช่อง (บางแถวใส่หลายเบอร์) → 0xxxxxxxxx หรือ '' */
    public static function firstPhone($v) {
        $v = (string) $v;
        if (preg_match('/(\+?66|0)?[\d][\d\- ]{7,12}\d/', $v, $x)) {
            $d = Staff_Model::phoneDigits($x[0]);
            return flood_valid_phone($d) ? $d : '';
        }
        return '';
    }

    public static function looksLikeCid($v) {
        $d = preg_replace('/[\s\-]/', '', (string) $v);
        return (bool) preg_match('/^\d{13}$/', $d);
    }

    /** ชื่อพื้นที่ → คีย์เทียบ (ตัดคำนำหน้า อ./ต./จ. และช่องว่าง) */
    public static function areaKey($v) {
        $s = preg_replace('/^\s*(อำเภอ|อ\.|เขต|ตำบล|ต\.|แขวง|จังหวัด|จ\.)\s*/u', '', (string) $v);
        return preg_replace('/[\s.]/u', '', $s);
    }

    /* ==================== แปลงแถวในชีตเป็นข้อมูลทะเบียน ==================== */

    /**
     * CSV → รายการบุคคล (ยังไม่แตะฐานข้อมูล ยกเว้น $area ที่ใช้เทียบชื่ออำเภอ/ตำบล)
     * @param callable|null $area function($amphoeText, $tambonText, $provinceText) → array(amphoe_code|null, tambon_code|null)
     * @return array(people[], stats{rows, skipped, dup_in_sheet}, columns{mapped, unmapped})
     */
    public static function parseSheet($raw, $defaultGroups = array(), $area = null) {
        $rows = Staff_Model::parseCsv($raw);
        $hi = self::findHeader($rows);
        if ($hi < 0) {
            throw new Exception('ไม่พบแถวหัวคอลัมน์ที่มีคอลัมน์ "ชื่อ" — ชีตนี้อาจไม่ใช่รายชื่อรายคน (เช่น ตารางสรุปตัวเลข)');
        }
        $head = array_map(array('Staff_Model', 'clean'), $rows[$hi]);
        $rows = array_slice($rows, $hi + 1);
        $map = array();
        $columns = array('mapped' => array(), 'unmapped' => array());
        foreach ($head as $i => $h) {
            $f = self::mapHeader($h);
            $map[$i] = $f;
            if ($h === '') {
                continue;
            }
            if ($f === null) {
                $columns['unmapped'][] = $h;
            } else {
                $columns['mapped'][$h] = $f;
            }
        }
        $headNameKeys = array();
        foreach ($head as $i => $h) {
            if (in_array($map[$i], array('name', 'first_name'), true)) {
                $headNameKeys[Staff_Model::nameKey($h)] = true;
            }
        }
        $groupsAll = flood_vulnerable_groups();
        $defaultGroups = flood_codes_filter($defaultGroups, $groupsAll);
        $stats = array('rows' => 0, 'skipped' => 0, 'dup_in_sheet' => 0);
        $people = array();
        $seen = array();
        foreach ($rows as $r) {
            $vals = array();
            $rawOut = array();
            $grpText = array();
            $medText = array();
            $groups = array();
            $mobility = null;
            foreach ($map as $i => $f) {
                $v = isset($r[$i]) ? Staff_Model::clean($r[$i]) : '';
                if ($v === '' || $f === '_skip' || $f === '_ignore') {
                    continue;
                }
                if (self::looksLikeCid($v)) {
                    continue;   // ไม่เก็บเลขบัตรประชาชน แม้อยู่ในคอลัมน์ชื่ออื่น
                }
                if ($head[$i] !== '' && !isset($rawOut[$head[$i]])) {
                    $rawOut[$head[$i]] = $v;
                }
                if ($f === null || $f === '_raw') {
                    continue;
                }
                if (strpos($f, 'grp:') === 0) {
                    if (self::isNegative($v)) {
                        continue;
                    }
                    $groups[] = substr($f, 4);
                    if (!self::isTruthy($v)) {
                        $medText[] = $head[$i] . ': ' . $v;
                    }
                    continue;
                }
                if ($f === 'medical_needs') {
                    $medText[] = $v;
                    continue;
                }
                if ($f === 'groups') {
                    $grpText[] = $v;
                    continue;
                }
                if ($f === 'adl') {
                    list($g, $mob) = self::adlInfo($v);
                    $groups = array_merge($groups, $g);
                    $mobility = $mobility ?: $mob;
                    $medText[] = 'ADL ' . $v;
                    continue;
                }
                if (!isset($vals[$f])) {
                    $vals[$f] = $v;
                }
            }
            // ชื่อ
            $name = isset($vals['name']) ? $vals['name'] : trim((isset($vals['first_name']) ? $vals['first_name'] : '') . ' '
                . (isset($vals['last_name']) ? $vals['last_name'] : ''));
            if (isset($vals['prefix']) && $name !== '' && mb_strpos($name, $vals['prefix']) !== 0) {
                $name = $vals['prefix'] . $name;
            }
            $name = trim(preg_replace('/\s+/u', ' ', $name));
            $nameKey = Staff_Model::nameKey($name);
            if ($name === '' && !$rawOut) {
                continue;   // แถวว่าง
            }
            $stats['rows']++;
            if (mb_strlen($nameKey) < 2 || preg_match('/^(รวม|ทั้งหมด|total)$/iu', $nameKey) || isset($headNameKeys[$nameKey])) {
                $stats['skipped']++;
                continue;
            }
            // กลุ่ม / การเคลื่อนย้าย
            $groups = array_merge($groups, self::detectGroups(implode(' ', $grpText)), self::detectGroups(implode(' ', $medText)));
            if (isset($vals['mobility'])) {
                $groups = array_merge($groups, self::detectGroups($vals['mobility']));
                $mobility = self::mobilityFrom($vals['mobility']) ?: $mobility;
            }
            $birth = isset($vals['birth_date']) ? self::parseBirth($vals['birth_date']) : null;
            $age = null;
            if ($birth) {
                $age = (int) flood_age_years($birth);
            } elseif (isset($vals['age']) && preg_match('/^\s*(\d{1,3})/', $vals['age'], $x)) {
                $age = (int) $x[1];
            }
            if ($age !== null && $age >= 60 && $age < 130) {
                $groups[] = 'elderly';
            }
            if ($age !== null && $age <= 5) {
                $groups[] = 'infant';
            }
            if ($grpText && !$groups) {
                $groups[] = 'other';   // ชีตระบุกลุ่มแต่ไม่ตรงกลุ่มในระบบ — ข้อความเดิมอยู่ในข้อมูลจากชีต
            }
            if (!$groups) {
                $groups = $defaultGroups ?: array('other');
            }
            $groups = flood_codes_filter(array_unique($groups), $groupsAll);
            if (!$mobility && in_array('bedridden', $groups, true)) {
                $mobility = 'bedridden';
            }
            // ที่อยู่ / พื้นที่
            $address = isset($vals['address']) ? $vals['address'] : '';
            if (isset($vals['village']) && mb_strpos($address, $vals['village']) === false) {
                $address = trim($address . ' บ.' . preg_replace('/^\s*(บ้าน|บ\.)\s*/u', '', $vals['village']));
            }
            $moo = isset($vals['moo']) ? preg_replace('/\D/', '', $vals['moo']) : '';
            if ($moo === '' && preg_match('/(?:ม\.|หมู่(?:ที่)?)\s*(\d{1,3})/u', $address, $x)) {
                $moo = $x[1];
            }
            $tb = isset($vals['tambon']) ? $vals['tambon'] : (preg_match('/(?:ต\.|ตำบล)\s*([^\s,]+)/u', $address, $x) ? $x[1] : '');
            $ap = isset($vals['amphoe']) ? $vals['amphoe'] : (preg_match('/(?:อ\.|อำเภอ)\s*([^\s,]+)/u', $address, $x) ? $x[1] : '');
            $pv = isset($vals['province']) ? $vals['province'] : (preg_match('/(?:จ\.|จังหวัด)\s*([^\s,]+)/u', $address, $x) ? $x[1] : '');
            list($amphoeCode, $tambonCode) = $area ? call_user_func($area, $ap, $tb, $pv) : array(null, null);
            $ll = null;
            if (isset($vals['lat'], $vals['lng']) && flood_valid_latlng($vals['lat'], $vals['lng'])) {
                $ll = array(round((float) $vals['lat'], 7), round((float) $vals['lng'], 7));
            } elseif (isset($vals['latlng'])) {
                $ll = self::parseLatLng($vals['latlng']);
            }
            $phone = isset($vals['phone']) ? self::firstPhone($vals['phone']) : '';
            $cgPhone = isset($vals['caregiver_phone']) ? self::firstPhone($vals['caregiver_phone']) : '';
            $hn = isset($vals['hn']) ? mb_substr(preg_replace('/\.0+$/', '', preg_replace('/\s+/u', '', $vals['hn'])), 0, 20) : '';
            $sex = isset($vals['sex']) ? self::sexFrom($vals['sex']) : null;
            if (!$sex) {
                $sex = self::sexFromName($name);
            }
            // คีย์จับคู่รอบถัดไป
            if ($hn !== '') {
                $basis = 'hn:' . mb_strtolower($hn);
            } elseif ($phone !== '') {
                $basis = 'ph:' . substr($phone, -9) . '|' . $nameKey;
            } else {
                $basis = 'nm:' . $nameKey . '|' . (string) $tambonCode . '|' . $moo . '|' . (string) $birth;
            }
            if (isset($seen[$basis])) {
                $stats['dup_in_sheet']++;
                continue;
            }
            $seen[$basis] = true;
            $raw = json_encode($rawOut, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if (strlen($raw) > self::RAW_MAX) {
                $raw = json_encode(array_slice($rawOut, 0, 40, true), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            }
            $med = implode(' · ', array_unique($medText));
            $people[] = array(
                'basis' => $basis,
                'name_key' => $nameKey,
                'area_text' => trim($tb . ' ' . $ap),
                'data' => array(
                    'name' => mb_substr($name, 0, 150),
                    'hn' => $hn !== '' ? $hn : null,
                    'sex' => $sex,
                    'birth_date' => $birth,
                    'vuln_groups' => implode(',', $groups),
                    'mobility' => $mobility,
                    'medical_needs' => $med !== '' ? mb_substr($med, 0, 255) : null,
                    'address' => $address !== '' ? mb_substr($address, 0, 255) : null,
                    'moo' => $moo !== '' ? mb_substr($moo, 0, 10) : null,
                    'amphoe_code' => $amphoeCode,
                    'tambon_code' => $tambonCode,
                    'lat' => $ll ? $ll[0] : null,
                    'lng' => $ll ? $ll[1] : null,
                    'phone' => $phone !== '' ? $phone : null,
                    'caregiver_name' => isset($vals['caregiver_name']) ? mb_substr($vals['caregiver_name'], 0, 150) : null,
                    'caregiver_phone' => $cgPhone !== '' ? $cgPhone : null,
                    'note' => isset($vals['note']) ? mb_substr($vals['note'], 0, 2000) : null,
                ),
                'raw' => $raw,
            );
        }
        return array($people, $stats, $columns);
    }

    /* ==================== เทียบชื่ออำเภอ/ตำบล ==================== */

    /** ตัวเทียบชื่อพื้นที่ — เลือกจังหวัดที่ระบุในชีตก่อน แล้วจึงจังหวัดหลักของระบบ (สระแก้ว) */
    public function areaResolver() {
        $home = defined('FLOOD_HOME_PROVINCE') ? FLOOD_HOME_PROVINCE : '27';
        $prov = array();
        try {
            foreach ($this->db->select('SELECT province_code, name FROM flood_province') as $p) {
                $prov[self::areaKey($p['name'])] = $p['province_code'];
            }
        } catch (Exception $e) {
            // ยังไม่มีตารางจังหวัด — ใช้จังหวัดหลักอย่างเดียว
        }
        $amph = array();
        foreach ($this->db->select('SELECT amphoe_code, name FROM flood_amphoe') as $a) {
            $amph[self::areaKey($a['name'])][] = $a['amphoe_code'];
        }
        $tamb = array();
        foreach ($this->db->select('SELECT tambon_code, amphoe_code, name FROM flood_tambon') as $t) {
            $tamb[self::areaKey($t['name'])][] = $t['tambon_code'];
        }
        $pick = function ($codes, $pv) use ($home) {
            if (!$codes) {
                return null;
            }
            foreach (array($pv, $home) as $p) {
                if ($p === '') {
                    continue;
                }
                $in = array_values(array_filter($codes, function ($c) use ($p) { return strpos($c, $p) === 0; }));
                if (count($in) === 1) {
                    return $in[0];
                }
                if ($in) {
                    return null;   // ชื่อซ้ำหลายแห่งในจังหวัดเดียวกัน — ไม่เดา
                }
            }
            return count($codes) === 1 ? $codes[0] : null;
        };
        return function ($ap, $tb, $pv) use ($prov, $amph, $tamb, $pick) {
            $pvKey = self::areaKey($pv);
            $pvCode = $pvKey !== '' && isset($prov[$pvKey]) ? $prov[$pvKey] : '';
            $apKey = self::areaKey($ap);
            if ($apKey === 'เมือง' && $pvKey !== '') {
                $apKey = 'เมือง' . $pvKey;
            }
            $amphoe = $apKey !== '' && isset($amph[$apKey]) ? $pick($amph[$apKey], $pvCode) : null;
            $tambon = null;
            $tbKey = self::areaKey($tb);
            if ($tbKey !== '' && isset($tamb[$tbKey])) {
                $codes = $tamb[$tbKey];
                if ($amphoe) {
                    $codes = array_values(array_filter($codes, function ($c) use ($amphoe) { return strpos($c, $amphoe) === 0; }));
                    $tambon = count($codes) === 1 ? $codes[0] : null;
                } else {
                    $tambon = $pick($codes, $pvCode);
                }
            }
            if ($tambon && !$amphoe) {
                $amphoe = substr($tambon, 0, 4);
            }
            return array($amphoe, $tambon);
        };
    }

    /* ==================== รายงานศูนย์พักพิง (ตัวเลขรายศูนย์รายวัน) ==================== */

    /** ชีตนี้เป็นรายงานศูนย์พักพิง (มีคำว่า ศูนย์พักพิง แต่ไม่มีคอลัมน์ชื่อคน) */
    public static function looksLikeShelter($raw) {
        return mb_strpos((string) $raw, 'พักพิง') !== false && self::findHeader(Staff_Model::parseCsv($raw)) < 0;
    }

    /** "วันที่ 28 กันยายน 2569" / "28 ก.ย.69" / "28/09/2569" ในข้อความ → Y-m-d */
    public static function thaiDateIn($text) {
        $months = array('มกราคม' => 1, 'กุมภาพันธ์' => 2, 'มีนาคม' => 3, 'เมษายน' => 4, 'พฤษภาคม' => 5, 'มิถุนายน' => 6,
            'กรกฎาคม' => 7, 'สิงหาคม' => 8, 'กันยายน' => 9, 'ตุลาคม' => 10, 'พฤศจิกายน' => 11, 'ธันวาคม' => 12,
            'ม.ค.' => 1, 'ก.พ.' => 2, 'มี.ค.' => 3, 'เม.ย.' => 4, 'พ.ค.' => 5, 'มิ.ย.' => 6,
            'ก.ค.' => 7, 'ส.ค.' => 8, 'ก.ย.' => 9, 'ต.ค.' => 10, 'พ.ย.' => 11, 'ธ.ค.' => 12);
        $text = preg_replace('/\s+/u', ' ', (string) $text);
        foreach ($months as $name => $m) {
            $q = preg_quote($name, '/');
            if (preg_match('/(\d{1,2})\s*' . $q . '\s*(\d{4}|\d{2})(?!\d)/u', $text, $x)) {
                $y = (int) $x[2];
                $y = $y < 100 ? $y + 2500 : $y;
                $y = $y > 2400 ? $y - 543 : $y;
                if (checkdate($m, (int) $x[1], $y)) {
                    return sprintf('%04d-%02d-%02d', $y, $m, (int) $x[1]);
                }
            }
        }
        if (preg_match('#(\d{1,2})/(\d{1,2})/(\d{4})#', $text, $x)) {
            $y = (int) $x[3] > 2400 ? (int) $x[3] - 543 : (int) $x[3];
            if (checkdate((int) $x[2], (int) $x[1], $y)) {
                return sprintf('%04d-%02d-%02d', $y, (int) $x[2], (int) $x[1]);
            }
        }
        return null;
    }

    /** หัวคอลัมน์รายงานศูนย์พักพิง → ฟิลด์ (ตรวจตามลำดับ) — คอลัมน์อื่นเก็บใน raw */
    private static function shelterRules() {
        return array(
            array('_raw', '/MCATT|เครียด\s*(น้อย|ปานกลาง|มาก)/u'),
            array('people', '/ยอดผู้เข้าพัก|จำนวนผู้พัก|ผู้เข้าพักพิง|จำนวนผู้อพยพ/u'),
            array('coordinator', '/ผู้ประสานประจำ/u'),
            array('health_contact', '/ผู้ประสานหลัก|ผู้ประสาน.*สาธารณสุข/u'),
            array('shelter_name', '/ศูนย์พักพิง|สถานที่พักพิง|จุดอพยพ/u'),
            array('amphoe_name', '/^\s*อำเภอ\s*$/u'),
            array('row_no', '/ลำดับ\s*$/u'),
            array('elderly', '/สูงอายุ/u'),
            array('disabled', '/พิการ/u'),
            array('child', '/0\s*[-–]\s*5\s*ปี|เด็กเล็ก/u'),
            array('bedridden', '/ติดเตียง/u'),
            array('pregnant_ga', '/อายุครรภ์/u'),
            array('pregnant_sym', '/อาการทั่วไป/u'),
            array('pregnant', '/ตั้งครรภ์/u'),
            array('dialysis_hd', '/ล้างไต.*เครื่อง|ฟอกเลือด|\bHD\b/u'),
            array('dialysis_capd', '/ล้างไต.*ช่องท้อง|CAPD/u'),
            array('mental', '/สุขภาพจิต/u'),
            array('chronic', '/โรคเรื้อรัง/u'),
            array('treated', '/ทำแผล/u'),
            array('referred', '/ส่งต่อ\s*รพ/u'),
            array('needs', '/สิ่งที่ต้องการสนับสนุน|ปัญหา\s*อุปสรรค/u'),
        );
    }

    public static function shelterField($label) {
        foreach (self::shelterRules() as $r) {
            if (preg_match($r[1], (string) $label)) {
                return $r[0];
            }
        }
        return null;
    }

    /**
     * CSV ของแท็บรายวัน → array(date, rows[], columns)
     * รองรับทั้งแบบที่ gviz รวมหัวหลายแถวเป็นแถวเดียว และ CSV ที่ดาวน์โหลดเอง (หัวรายงาน + หัวคอลัมน์ 2 แถวที่ผสานช่อง)
     */
    public static function parseShelterDay($raw, $area = null) {
        $rows = Staff_Model::parseCsv($raw);
        // แถวหัวคอลัมน์ = มีคอลัมน์ศูนย์พักพิง และคอลัมน์ตัวเลขรายศูนย์อย่างน้อย 2 อย่าง (กันหัวข้อในแท็บสรุป)
        $h1 = -1;
        foreach (array_slice($rows, 0, 8) as $i => $r) {
            $f = array();
            foreach ($r as $c) {
                $x = self::shelterField(Staff_Model::clean($c));
                if ($x !== null) {
                    $f[$x] = true;
                }
            }
            $others = array_intersect_key($f, array_flip(array('people', 'elderly', 'disabled', 'child', 'bedridden', 'pregnant', 'amphoe_name', 'coordinator')));
            if (isset($f['shelter_name']) && count($others) >= 2) {
                $h1 = $i;
                break;
            }
        }
        if ($h1 < 0) {
            throw new Exception('ไม่พบคอลัมน์ "ศูนย์พักพิง" — แท็บนี้ไม่ใช่รายงานรายศูนย์พักพิง');
        }
        $top = array();
        foreach (array_slice($rows, 0, $h1 + 1) as $r) {
            $top[] = implode(' ', $r);
        }
        $date = self::thaiDateIn(implode(' ', $top));
        $head = array_map(array('Staff_Model', 'clean'), $rows[$h1]);
        $start = $h1 + 1;
        // แถวหัวคอลัมน์แถวที่ 2 (ช่องผสาน เช่น หญิงตั้งครรภ์ → จำนวน / อายุครรภ์)
        if (isset($rows[$start]) && !preg_match('/^\s*\d+\s*$/', (string) $rows[$start][0])
            && count(array_filter($rows[$start], function ($c) { return trim((string) $c) !== ''; })) >= 3) {
            $sub = array_map(array('Staff_Model', 'clean'), $rows[$start]);
            $group = '';
            foreach ($head as $c => $v) {
                $s2 = isset($sub[$c]) ? $sub[$c] : '';
                if ($v !== '') {
                    $group = $s2 !== '' ? $v : '';
                    $head[$c] = trim($v . ' ' . $s2);
                } elseif ($s2 !== '') {
                    $head[$c] = trim($group . ' ' . $s2);
                }
            }
            $start++;
        }
        $map = array();
        $columns = array();
        foreach ($head as $c => $label) {
            $f = self::shelterField($label);
            $map[$c] = $f;
            if ($label !== '') {
                $columns[$label] = $f ?: '_raw';
            }
        }
        $num = function ($v) {
            $v = str_replace(',', '', trim((string) $v));
            return preg_match('/^\d+/', $v, $x) ? (int) $x[0] : null;
        };
        $out = array();
        $amphoe = '';
        foreach (array_slice($rows, $start) as $r) {
            $vals = array();
            $rawOut = array();
            foreach ($map as $c => $f) {
                $v = isset($r[$c]) ? Staff_Model::clean($r[$c]) : '';
                if ($v === '' || self::looksLikeCid($v)) {
                    continue;
                }
                if ($head[$c] !== '') {
                    $rawOut[$head[$c]] = $v;
                }
                if ($f !== null && $f !== '_raw' && !isset($vals[$f])) {
                    $vals[$f] = $v;
                }
            }
            if (isset($vals['amphoe_name']) && !preg_match('/^(รวม|ทั้งหมด)/u', $vals['amphoe_name'])) {
                $amphoe = $vals['amphoe_name'];   // ช่องอำเภอผสานหลายแถว — ใช้ค่าล่าสุดต่อลงมา
            }
            $shelter = isset($vals['shelter_name']) ? $vals['shelter_name'] : '';
            if ($shelter === '' || preg_match('/^(รวม|ทั้งหมด|total)/iu', $shelter)
                || (isset($vals['amphoe_name']) && preg_match('/^(รวม|ทั้งหมด)/u', $vals['amphoe_name']))) {
                continue;   // แถวว่าง / แถวรวม
            }
            $tb = preg_match('/(?:ต\.|ตำบล)\s*([^\s,]+)/u', $shelter, $x) ? $x[1] : '';
            list($amphoeCode, $tambonCode) = $area ? call_user_func($area, $amphoe, $tb, '') : array(null, null);
            $note = array();
            if (isset($vals['pregnant_ga'])) {
                $note[] = 'อายุครรภ์ ' . $vals['pregnant_ga'] . ' สัปดาห์';
            }
            if (isset($vals['pregnant_sym'])) {
                $note[] = $vals['pregnant_sym'];
            }
            $row = array(
                'row_no' => isset($vals['row_no']) ? $num($vals['row_no']) : null,
                'amphoe_name' => $amphoe !== '' ? mb_substr($amphoe, 0, 100) : null,
                'amphoe_code' => $amphoeCode,
                'tambon_code' => $tambonCode,
                'shelter_name' => mb_substr($shelter, 0, 255),
                'pregnant_note' => $note ? mb_substr(implode(' · ', $note), 0, 500) : null,
                'coordinator' => isset($vals['coordinator']) ? mb_substr($vals['coordinator'], 0, 300) : null,
                'health_contact' => isset($vals['health_contact']) ? mb_substr($vals['health_contact'], 0, 500) : null,
                'needs' => isset($vals['needs']) ? mb_substr($vals['needs'], 0, 1000) : null,
                'raw' => json_encode($rawOut, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            );
            foreach (array('people', 'elderly', 'disabled', 'child', 'bedridden', 'pregnant', 'dialysis_hd', 'dialysis_capd',
                'mental', 'chronic', 'treated', 'referred') as $f) {
                $row[$f] = isset($vals[$f]) ? $num($vals[$f]) : null;
            }
            $out[] = $row;
        }
        return array($date, $out, $columns);
    }

    /**
     * นำเข้าแท็บรายวันของรายงานศูนย์พักพิง — แทนที่ข้อมูลวันนั้นทั้งชุด
     * @param string|null $expectDate วันที่ของแท็บที่เบราว์เซอร์ขอ (กันกรณี Google ส่งแท็บแรกกลับมาแทนแท็บที่ไม่มี)
     * @return array{date, shelters, skipped, people}
     */
    public function importShelterDay($sourceId, $raw, $expectDate = null, $tab = null) {
        list($date, $rows, $columns) = self::parseShelterDay($raw, $this->areaResolver());
        if (!$date) {
            throw new Exception('ไม่พบวันที่ในหัวรายงานของแท็บ');
        }
        if ($expectDate !== null && $date !== $expectDate) {
            return array('date' => $date, 'shelters' => 0, 'skipped' => true, 'people' => 0);
        }
        if (!$rows) {
            throw new Exception('แท็บวันที่ ' . $date . ' ยังไม่มีข้อมูลศูนย์พักพิง');
        }
        $now = date('Y-m-d H:i:s');
        $people = 0;
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM flood_shelter_report WHERE source_id = ? AND report_date = ?')->execute(array((int) $sourceId, $date));
            foreach ($rows as $r) {
                $r['source_id'] = (int) $sourceId;
                $r['report_date'] = $date;
                $r['tab_name'] = $tab !== null ? mb_substr($tab, 0, 100) : null;
                $r['imported_at'] = $now;
                $this->db->insert('flood_shelter_report', $r);
                $people += (int) $r['people'];
            }
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->db->update('flood_vuln_source', array(
            'last_sync_at' => $now, 'last_rows' => count($rows), 'last_new' => null, 'last_updated' => null, 'last_error' => null,
            'last_columns' => json_encode(array('mapped' => $columns, 'unmapped' => array()), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        ), 'source_id = :id', array(':id' => (int) $sourceId));
        return array('date' => $date, 'shelters' => count($rows), 'skipped' => false, 'people' => $people);
    }

    /** วันที่มีรายงานศูนย์พักพิง (ใหม่ → เก่า) */
    public function shelterDates() {
        return $this->db->select('SELECT report_date, COUNT(*) AS shelters, SUM(people) AS people
            FROM flood_shelter_report GROUP BY report_date ORDER BY report_date DESC LIMIT 60');
    }

    /** รายศูนย์ของวันหนึ่ง + ผลรวม (รวมทุกชีตประเภทศูนย์พักพิง) */
    public function shelterReport($date) {
        $rows = $this->db->select('SELECT r.*, s.name AS source_name, t.name AS tambon_name
            FROM flood_shelter_report r
            LEFT JOIN flood_vuln_source s ON s.source_id = r.source_id
            LEFT JOIN flood_tambon t ON t.tambon_code = r.tambon_code
            WHERE r.report_date = :d ORDER BY r.amphoe_name, r.source_id, r.row_no, r.report_id', array(':d' => $date));
        $sum = array('shelters' => count($rows));
        foreach (array('people', 'elderly', 'disabled', 'child', 'bedridden', 'pregnant', 'dialysis_hd', 'dialysis_capd',
            'mental', 'chronic', 'treated', 'referred') as $f) {
            $sum[$f] = 0;
            foreach ($rows as $r) {
                $sum[$f] += (int) $r[$f];
            }
        }
        return array('rows' => $rows, 'sum' => $sum);
    }

    /* ==================== นำเข้า ==================== */

    /**
     * นำเข้า CSV ของแหล่งหนึ่ง
     * @return array{rows,new,updated,same,linked,skipped,dup_in_sheet,removed,not_in_sheet,no_area,columns}
     */
    public function importCsv($sourceId, $raw, $userId) {
        $src = $this->getSource($sourceId);
        if (!$src) {
            throw new Exception('ไม่พบแหล่งข้อมูล');
        }
        list($people, $stats, $columns) = self::parseSheet($raw, (string) $src['default_groups'], $this->areaResolver());
        $out = $stats + array('new' => 0, 'updated' => 0, 'same' => 0, 'linked' => 0, 'removed' => 0, 'not_in_sheet' => 0, 'no_area' => 0,
            'columns' => $columns);
        $now = date('Y-m-d H:i:s');

        // ทะเบียนเดิมทั้งหมด (ทะเบียนมีหลักร้อย–พันคน อ่านครั้งเดียวแล้วเทียบในหน่วยความจำ)
        $bySrc = array();
        $byHn = array();
        $byPhone = array();
        $byName = array();
        $all = array();
        foreach ($this->db->select('SELECT person_id, hn, name, sex, birth_date, vuln_groups, mobility, medical_needs, address, moo,
                amphoe_code, tambon_code, lat, lng, phone, caregiver_name, caregiver_phone, is_active, src_id, src_key
                FROM flood_vulnerable') as $p) {
            $id = (int) $p['person_id'];
            $all[$id] = $p;
            if ($p['src_key'] !== null && (int) $p['src_id'] === (int) $sourceId) {
                $bySrc[$p['src_key']] = $id;
            }
            if ((int) $p['is_active'] !== 1) {
                continue;
            }
            if ($p['hn'] !== null && $p['hn'] !== '') {
                $byHn[mb_strtolower($p['hn'])][] = $id;
            }
            if ($p['phone']) {
                $byPhone[substr($p['phone'], -9)][] = $id;
            }
            $byName[Staff_Model::nameKey($p['name'])][] = $id;
        }
        $claimed = array();   // คนในทะเบียนที่ถูกจับคู่กับแถวในชีตรอบนี้แล้ว

        $this->db->beginTransaction();
        try {
            foreach ($people as $pp) {
                $d = $pp['data'];
                $key = sha1($sourceId . '|' . $pp['basis']);
                $id = 0;
                if (isset($bySrc[$key]) && !isset($claimed[$bySrc[$key]])) {
                    $id = $bySrc[$key];
                    if ((int) $all[$id]['is_active'] !== 1) {
                        $out['removed']++;   // เจ้าหน้าที่นำออกจากทะเบียนแล้ว — ไม่เพิ่มกลับ
                        continue;
                    }
                }
                $linked = false;
                if (!$id) {
                    $id = $this->matchPerson($d, $pp['name_key'], $byHn, $byPhone, $byName, $all, $claimed);
                    $linked = $id > 0;
                }
                if (!$d['amphoe_code'] && $pp['area_text'] !== '') {
                    $out['no_area']++;
                }
                if (!$id) {
                    $row = $d;
                    $row['evac_status'] = 'normal';
                    $row['is_active'] = 1;
                    $row['created_by'] = $userId ?: null;
                    $row['src_id'] = (int) $sourceId;
                    $row['src_key'] = $key;
                    $row['src_raw'] = $pp['raw'];
                    $row['src_synced_at'] = $now;
                    $newId = (int) $this->db->insert('flood_vulnerable', $row);
                    $claimed[$newId] = true;
                    $out['new']++;
                    continue;
                }
                $claimed[$id] = true;
                $cur = $all[$id];
                $upd = self::mergeFields($cur, $d);
                $changed = (bool) $upd;
                $upd['src_id'] = (int) $sourceId;
                $upd['src_key'] = $key;
                $upd['src_raw'] = $pp['raw'];
                $upd['src_synced_at'] = $now;
                if ($changed) {
                    $upd['updated_at'] = $now;
                }
                $this->db->update('flood_vulnerable', $upd, 'person_id = :w_id', array(':w_id' => $id));
                if ($linked) {
                    $out['linked']++;   // คนที่มีอยู่แล้ว (เพิ่มเอง/ชีตอื่น) — ผูกกับชีตนี้และเติมข้อมูล
                } elseif ($changed) {
                    $out['updated']++;
                } else {
                    $out['same']++;
                }
            }
            $out['not_in_sheet'] = (int) $this->db->selectValue('SELECT COUNT(*) FROM flood_vulnerable
                WHERE src_id = :s AND is_active = 1 AND (src_synced_at IS NULL OR src_synced_at < :t)', array(':s' => (int) $sourceId, ':t' => $now));
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->db->update('flood_vuln_source', array(
            'last_sync_at' => $now, 'last_rows' => $out['rows'], 'last_new' => $out['new'], 'last_updated' => $out['updated'] + $out['linked'],
            'last_error' => null, 'last_columns' => json_encode($columns, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        ), 'source_id = :id', array(':id' => (int) $sourceId));
        return $out;
    }

    /** หาคนเดิมในทะเบียน: HN → เบอร์+ชื่อ → ชื่อที่มีข้อมูลยืนยัน (เบอร์/ตำบล/เบอร์ผู้ดูแล/วันเกิด) */
    private function matchPerson($d, $nameKey, $byHn, $byPhone, $byName, $all, $claimed) {
        $free = function ($ids) use ($claimed) {
            // คนที่ถูกจับคู่กับแถวอื่นในรอบนี้แล้วไม่นำมาจับซ้ำ
            return array_values(array_filter((array) $ids, function ($id) use ($claimed) {
                return !isset($claimed[$id]);
            }));
        };
        if ($d['hn'] !== null) {
            $ids = $free(isset($byHn[mb_strtolower($d['hn'])]) ? $byHn[mb_strtolower($d['hn'])] : array());
            if ($ids) {
                return $ids[0];
            }
        }
        if ($d['phone'] !== null) {
            foreach ($free(isset($byPhone[substr($d['phone'], -9)]) ? $byPhone[substr($d['phone'], -9)] : array()) as $id) {
                if (Staff_Model::nameKey($all[$id]['name']) === $nameKey) {
                    return $id;
                }
            }
        }
        foreach ($free(isset($byName[$nameKey]) ? $byName[$nameKey] : array()) as $id) {
            $c = $all[$id];
            if ($d['hn'] !== null && $c['hn'] !== null && $c['hn'] !== '' && mb_strtolower($c['hn']) !== mb_strtolower($d['hn'])) {
                continue;   // HN คนละเลข = คนละคน
            }
            $proof = ($d['phone'] !== null && ($c['phone'] === $d['phone'] || $c['caregiver_phone'] === $d['phone']))
                || ($d['caregiver_phone'] !== null && ($c['caregiver_phone'] === $d['caregiver_phone'] || $c['phone'] === $d['caregiver_phone']))
                || ($d['tambon_code'] !== null && $c['tambon_code'] === $d['tambon_code'])
                || ($d['birth_date'] !== null && $c['birth_date'] === $d['birth_date']);
            if ($proof) {
                return $id;
            }
        }
        return 0;
    }

    /**
     * ค่าที่จะอัปเดตจากชีต (เฉพาะช่องที่ชีตมีค่า):
     * - ข้อมูลบุคคล/ติดต่อ: ใช้ค่าจากชีต · กลุ่ม: รวมของเดิม + ชีต
     * - อำเภอ/ตำบล/พิกัด/การเคลื่อนย้าย: เติมเฉพาะที่ยังว่าง (เจ้าหน้าที่อาจปักหมุดเองละเอียดกว่า)
     * - ไม่แตะ สถานะอพยพ / ที่พักพิง / บันทึก / การติดตาม
     */
    public static function mergeFields($cur, $d) {
        $upd = array();
        foreach (array('name', 'hn', 'sex', 'birth_date', 'medical_needs', 'address', 'moo', 'phone', 'caregiver_name', 'caregiver_phone') as $f) {
            if ($d[$f] !== null && (string) $d[$f] !== (string) $cur[$f]) {
                $upd[$f] = $d[$f];
            }
        }
        $g = flood_codes_filter(array_merge(explode(',', (string) $cur['vuln_groups']), explode(',', (string) $d['vuln_groups'])), flood_vulnerable_groups());
        if (count($g) > 1) {
            $g = array_values(array_diff($g, array('other')));   // มีกลุ่มจริงแล้ว ไม่ต้องคง "อื่น ๆ"
        }
        if (implode(',', $g) !== (string) $cur['vuln_groups']) {
            $upd['vuln_groups'] = implode(',', $g);
        }
        if ($cur['mobility'] === null && $d['mobility'] !== null) {
            $upd['mobility'] = $d['mobility'];
        }
        if ($cur['amphoe_code'] === null && $d['amphoe_code'] !== null) {
            $upd['amphoe_code'] = $d['amphoe_code'];
            $upd['tambon_code'] = $d['tambon_code'];
        } elseif ($cur['tambon_code'] === null && $d['tambon_code'] !== null && $cur['amphoe_code'] === substr($d['tambon_code'], 0, 4)) {
            $upd['tambon_code'] = $d['tambon_code'];
        }
        if ($cur['lat'] === null && $d['lat'] !== null) {
            $upd['lat'] = $d['lat'];
            $upd['lng'] = $d['lng'];
        }
        return $upd;
    }
}
