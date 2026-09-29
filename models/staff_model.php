<?php

/**
 * บุคลากรโรงพยาบาลที่ได้รับผลกระทบจากน้ำท่วม (ข้อมูลภายใน — เฉพาะเจ้าหน้าที่ศูนย์/ผู้ดูแลระบบ)
 * รวมคำตอบจาก Google Form หลายชุด (แต่ละชุด = 1 แหล่ง ใน flood_staff_source) เป็นรายชื่อบุคคลเดียว
 *
 *   flood_staff_source  แหล่งข้อมูล (ลิงก์ Google Sheet ที่เก็บคำตอบฟอร์ม)
 *   flood_staff_resp    คำตอบแต่ละแถวตามต้นฉบับ (ไม่เก็บเลขบัตรประชาชน) — นำเข้าซ้ำได้ แถวเดิมไม่ซ้ำ
 *   flood_staff         บุคคล 1 คน = 1 แถว รวมคำตอบทุกฟอร์ม (จับคู่ด้วยเบอร์โทร แล้วจึงชื่อ-สกุล)
 *
 * หัวคอลัมน์ของฟอร์มจับคู่ด้วยคำสำคัญ (columnRules) — ฟอร์มใหม่ที่ถามคล้ายกันนำเข้าได้เลย
 * คอลัมน์ที่ไม่รู้จักยังเก็บไว้ในคำตอบต้นฉบับ (ดูได้ในหน้ารายละเอียด)
 */

if (!function_exists('flood_staff_follow_options')) {
    function flood_staff_follow_options() {
        return array(
            'new' => array('name' => 'ยังไม่ติดตาม', 'class' => 'label-default', 'icon' => 'fa-circle-o'),
            'contacted' => array('name' => 'ติดต่อแล้ว', 'class' => 'label-info', 'icon' => 'fa-phone'),
            'helping' => array('name' => 'กำลังช่วยเหลือ', 'class' => 'label-warning', 'icon' => 'fa-life-ring'),
            'done' => array('name' => 'ช่วยเหลือแล้ว', 'class' => 'label-success', 'icon' => 'fa-check-circle'),
            'no_need' => array('name' => 'ไม่ต้องการความช่วยเหลือ', 'class' => 'label-default', 'icon' => 'fa-minus-circle'),
        );
    }
}

if (!function_exists('flood_staff_levels')) {
    /** ระดับผลกระทบ — คำนวณจากคำตอบ (รุนแรงสุดของทุกฟอร์ม) */
    function flood_staff_levels() {
        return array(
            'severe' => array('name' => 'รุนแรง', 'class' => 'label-danger', 'icon' => 'fa-exclamation-triangle', 'order' => 1,
                'desc' => 'บ้านถูกน้ำท่วมโดยตรง / ถูกตัดขาด / มาทำงานไม่ได้ / ยังไม่มีที่พัก'),
            'moderate' => array('name' => 'ปานกลาง', 'class' => 'label-warning', 'icon' => 'fa-road', 'order' => 2,
                'desc' => 'ถนนถูกปิดกั้น เดินทางลำบาก / ลงเวรแล้วกลับบ้านไม่ได้'),
            'mild' => array('name' => 'เล็กน้อย / เฝ้าระวัง', 'class' => 'label-info', 'icon' => 'fa-eye', 'order' => 3,
                'desc' => 'ยังสัญจรได้ / อาจประสบภัยเร็ว ๆ นี้'),
            'none' => array('name' => 'ไม่ได้รับผลกระทบ', 'class' => 'label-success', 'icon' => 'fa-check', 'order' => 4, 'desc' => ''),
            'unknown' => array('name' => 'ไม่ระบุ', 'class' => 'label-default', 'icon' => 'fa-question', 'order' => 5, 'desc' => ''),
        );
    }
}

if (!function_exists('flood_staff_flags')) {
    function flood_staff_flags() {
        return array(
            'need_shelter' => array('name' => 'ต้องการที่พักด่วน', 'icon' => 'fa-bed'),
            'cant_work' => array('name' => 'มาทำงานไม่ได้', 'icon' => 'fa-ban'),
            'stranded' => array('name' => 'ถูกตัดขาด / กลับบ้านไม่ได้', 'icon' => 'fa-chain-broken'),
            'home_hit' => array('name' => 'บ้านถูกน้ำท่วม', 'icon' => 'fa-home'),
            'need_help' => array('name' => 'ขอความช่วยเหลือ', 'icon' => 'fa-life-ring'),
        );
    }
}

class Staff_Model extends Model {

    const MAX_BYTES = 8388608;   // 8 MB ต่อชีต
    const PER_PAGE = 50;

    /** ฟิลด์ที่รวมเป็นข้อมูลบุคคล (ชื่อคอลัมน์ใน flood_staff => ชื่อไทย) */
    public static function fieldLabels() {
        return array(
            'prefix' => 'คำนำหน้า', 'full_name' => 'ชื่อ-สกุล', 'phone' => 'เบอร์โทร', 'phone2' => 'เบอร์สำรอง', 'email' => 'อีเมล',
            'employ_type' => 'สถานะการจ้างงาน', 'position' => 'ตำแหน่ง', 'department' => 'กลุ่มงาน/หน่วยงาน', 'work_type' => 'ประเภทการทำงาน',
            'addr_card' => 'ที่อยู่ตามบัตรประชาชน', 'addr_now' => 'ที่พักอาศัยปัจจุบัน',
            'victim' => 'เป็นผู้ประสบภัยหรือไม่', 'impact' => 'ผลกระทบจากน้ำท่วม', 'travel' => 'การเดินทางมาโรงพยาบาล',
            'work_status' => 'การมาทำงาน', 'shift_status' => 'สถานะการเข้าเวร', 'work_problem' => 'ปัญหาในการปฏิบัติงาน',
            'has_shelter' => 'ที่พักนอนหลังลงเวร', 'shelter_need' => 'ที่พักชั่วคราวที่ต้องการ', 'current_aid' => 'ได้รับความช่วยเหลือ/พักที่',
            'help_need' => 'ต้องการความช่วยเหลือ', 'clothing' => 'ความช่วยเหลือด้านเสื้อผ้า', 'toiletries' => 'ของใช้ส่วนตัวที่ต้องการ',
            'damage' => 'ความเสียหาย', 'photos' => 'รูปถ่าย', 'suggest' => 'ข้อแนะนำ', 'note' => 'หมายเหตุ',
        );
    }

    /**
     * หัวคอลัมน์ฟอร์ม → ฟิลด์ (ตรวจตามลำดับ ตัวแรกที่ตรงชนะ) · '_skip' = ไม่เก็บ (เลขบัตรประชาชน)
     * ลำดับสำคัญ: ข้อที่เฉพาะกว่าอยู่ก่อน เช่น "เบอร์โทรกรณี…" ก่อน "เบอร์โทร", "ที่อยู่ตามบัตร…" ก่อน "บัตรประชาชน"
     */
    private static function columnRules() {
        return array(
            array('ts', '/ประทับเวลา|timestamp/iu'),
            array('addr_card', '/ที่อยู่ตามบัตร|ที่อยู่ตามทะเบียน/u'),
            array('_skip', '/เลขบัตร|บัตรประชาชน|เลขประจำตัวประชาชน|citizen/iu'),
            array('email', '/อีเมล|e-?mail/iu'),
            array('prefix', '/^\s*คำนำหน้า/u'),
            array('phone2', '/เบอร์.*(กรณี|สำรอง)|เบอร์สำรอง/u'),
            array('phone', '/เบอร์|โทรศัพท์|phone/iu'),
            array('employ_type', '/สถานะการจ้าง|ประเภทการจ้าง/u'),
            array('current_aid', '/การได้รับความช่วยเหลือ/u'),
            array('addr_now', '/อาศัยอยู่|พื้นที่พักอาศัย|ที่พักปัจจุบัน|ที่อยู่ปัจจุบัน/u'),
            array('shift_status', '/เข้าเวร/u'),
            array('shelter_need', '/ลักษณะที่พัก/u'),
            array('has_shelter', '/สถานที่พักนอน|ที่พักนอน/u'),
            array('clothing', '/เสื้อผ้า/u'),
            array('toiletries', '/ของใช้ส่วนตัว|toiletries/iu'),
            array('work_problem', '/สถานการณ์ปฏิบัติงาน|ปัญหาในการปฏิบัติงาน/u'),
            array('work_type', '/ประเภทการทำงาน|ลักษณะการทำงาน/u'),
            array('victim', '/ผู้ประสบภัย/u'),
            array('impact', '/ได้รับผลกระทบ/u'),
            array('travel', '/การเดินทาง/u'),
            array('work_status', '/การมาทำงาน|มาปฏิบัติงาน/u'),
            array('help_need', '/ความช่วยเหลือ/u'),
            array('damage', '/ความเสียหาย/u'),
            array('photos', '/รูปภาพ|รูปถ่าย|ภาพถ่าย/u'),
            array('suggest', '/ข้อแนะนำ|ข้อเสนอแนะ/u'),
            array('note', '/หมายเหตุ/u'),
            array('position', '/ตำแหน่ง/u'),
            array('department', '/กลุ่มงาน|หน่วยงาน|แผนก|หอผู้ป่วย|ฝ่าย/u'),
            array('full_name', '/ชื่อ/u'),
        );
    }

    public static function mapHeader($h) {
        $h = trim((string) $h);
        if ($h === '') {
            return null;
        }
        foreach (self::columnRules() as $r) {
            if (preg_match($r[1], $h)) {
                return $r[0];
            }
        }
        return null;
    }

    /* ==================== ตาราง ==================== */

    /** สร้างตารางถ้ายังไม่มี (เหมือน sql/17_flood_staff.sql) — false = ไม่มีสิทธิ์ CREATE */
    public function ensureTables() {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        try {
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS `flood_staff_source` (
                  `source_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `name` VARCHAR(150) NOT NULL,
                  `sheet_url` VARCHAR(500) NOT NULL,
                  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                  `last_sync_at` DATETIME DEFAULT NULL,
                  `last_rows` INT UNSIGNED DEFAULT NULL COMMENT 'จำนวนแถวในชีตรอบล่าสุด',
                  `last_new` INT UNSIGNED DEFAULT NULL COMMENT 'แถวใหม่ที่นำเข้ารอบล่าสุด',
                  `last_error` VARCHAR(500) DEFAULT NULL,
                  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`source_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS `flood_staff` (
                  `staff_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `prefix` VARCHAR(40) DEFAULT NULL,
                  `full_name` VARCHAR(150) NOT NULL,
                  `name_key` VARCHAR(150) NOT NULL DEFAULT '',
                  `phone` VARCHAR(20) DEFAULT NULL,
                  `phone_key` CHAR(9) NOT NULL DEFAULT '',
                  `phone2` VARCHAR(20) DEFAULT NULL,
                  `email` VARCHAR(150) DEFAULT NULL,
                  `employ_type` VARCHAR(100) DEFAULT NULL,
                  `position` VARCHAR(150) DEFAULT NULL,
                  `department` VARCHAR(150) DEFAULT NULL,
                  `work_type` VARCHAR(100) DEFAULT NULL,
                  `addr_card` VARCHAR(300) DEFAULT NULL,
                  `addr_now` VARCHAR(300) DEFAULT NULL,
                  `victim` VARCHAR(200) DEFAULT NULL,
                  `impact` VARCHAR(200) DEFAULT NULL,
                  `travel` VARCHAR(200) DEFAULT NULL,
                  `work_status` VARCHAR(200) DEFAULT NULL,
                  `shift_status` VARCHAR(200) DEFAULT NULL,
                  `work_problem` TEXT DEFAULT NULL,
                  `has_shelter` VARCHAR(300) DEFAULT NULL,
                  `shelter_need` VARCHAR(300) DEFAULT NULL,
                  `current_aid` VARCHAR(300) DEFAULT NULL,
                  `help_need` TEXT DEFAULT NULL,
                  `clothing` VARCHAR(300) DEFAULT NULL,
                  `toiletries` VARCHAR(300) DEFAULT NULL,
                  `damage` TEXT DEFAULT NULL,
                  `photos` TEXT DEFAULT NULL,
                  `suggest` TEXT DEFAULT NULL,
                  `note` TEXT DEFAULT NULL,
                  `level` VARCHAR(10) NOT NULL DEFAULT 'unknown' COMMENT 'severe|moderate|mild|none|unknown',
                  `flags` VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'need_shelter,cant_work,stranded,home_hit,need_help',
                  `follow_status` VARCHAR(10) NOT NULL DEFAULT 'new' COMMENT 'new|contacted|helping|done|no_need',
                  `follow_note` TEXT DEFAULT NULL,
                  `followed_by` INT UNSIGNED DEFAULT NULL,
                  `followed_at` DATETIME DEFAULT NULL,
                  `resp_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                  `sources` VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'source_id ที่มีคำตอบ คั่นด้วย ,',
                  `first_at` DATETIME DEFAULT NULL,
                  `last_at` DATETIME DEFAULT NULL,
                  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  `updated_at` DATETIME DEFAULT NULL,
                  PRIMARY KEY (`staff_id`),
                  KEY `idx_flood_staff_phone` (`phone_key`),
                  KEY `idx_flood_staff_name` (`name_key`),
                  KEY `idx_flood_staff_level` (`level`, `follow_status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS `flood_staff_resp` (
                  `resp_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `source_id` INT UNSIGNED NOT NULL,
                  `staff_id` INT UNSIGNED DEFAULT NULL,
                  `row_key` CHAR(40) NOT NULL COMMENT 'sha1(แหล่ง|เวลา|ชื่อ|เบอร์) กันนำเข้าซ้ำ',
                  `answered_at` DATETIME DEFAULT NULL,
                  `answers` MEDIUMTEXT DEFAULT NULL COMMENT 'JSON {หัวคอลัมน์: คำตอบ} ไม่มีเลขบัตรประชาชน',
                  `imported_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`resp_id`),
                  UNIQUE KEY `uq_flood_staff_resp_key` (`row_key`),
                  KEY `idx_flood_staff_resp_staff` (`staff_id`, `answered_at`),
                  KEY `idx_flood_staff_resp_source` (`source_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $this->seedSources();
            $ready = true;
        } catch (Exception $e) {
            error_log('[flood] สร้างตาราง flood_staff ไม่ได้ (รัน php sql/apply_schema.php 17): ' . $e->getMessage());
            try {
                $ready = (bool) $this->db->select("SHOW TABLES LIKE 'flood_staff_resp'");
            } catch (Exception $e2) {
                $ready = false;
            }
        }
        return $ready;
    }

    /** แบบสำรวจ 3 ชุดแรกของโรงพยาบาล (เพิ่ม/ปิดเพิ่มเติมได้ที่หน้าจอ) */
    private function seedSources() {
        if ((int) $this->db->selectValue("SELECT COUNT(*) FROM flood_staff_source") > 0) {
            return;
        }
        $seed = array(
            array('แบบสำรวจบุคลากรที่ได้รับผลกระทบ (ข้อมูลบุคคล)', 'https://docs.google.com/spreadsheets/d/1j8Lihk-72v0TC38i_Wp68nnaoRHSRQx91S6pjrxnJ6w/edit?gid=1568038299'),
            array('ที่พักชั่วคราวหลังลงเวร / ของใช้จำเป็น', 'https://docs.google.com/spreadsheets/d/1I3rYoPsZ3xq6r7e_OcU1iHEaNKG4CCvKtfC4A_rKxaw/edit?gid=729746722'),
            array('ผลกระทบการเดินทางมาปฏิบัติงาน', 'https://docs.google.com/spreadsheets/d/1PqOaPqnyFgpRjhAaaEWgEwFWu6yn8n68zS3AAdj5Xj8/edit?gid=1015077743'),
        );
        foreach ($seed as $s) {
            $this->db->insert('flood_staff_source', array('name' => $s[0], 'sheet_url' => $s[1], 'is_active' => 1));
        }
    }

    /* ==================== แหล่งข้อมูล ==================== */

    public function sources($activeOnly = false) {
        return $this->db->select("SELECT s.*, (SELECT COUNT(*) FROM flood_staff_resp r WHERE r.source_id = s.source_id) AS resp_total
            FROM flood_staff_source s" . ($activeOnly ? " WHERE s.is_active = 1" : '') . " ORDER BY s.source_id");
    }

    public function getSource($id) {
        return $this->db->selectOne("SELECT * FROM flood_staff_source WHERE source_id = :id", array(':id' => (int) $id));
    }

    /** ลิงก์ Google Sheet → array(sheet_id, gid) หรือ null */
    public static function parseSheetUrl($url) {
        $url = trim((string) $url);
        if (!preg_match('#^https://docs\.google\.com/spreadsheets/d/([a-zA-Z0-9_-]{20,})#', $url, $m)) {
            return null;
        }
        $gid = preg_match('/[#&?]gid=(\d+)/', $url, $g) ? $g[1] : '0';
        return array($m[1], $gid);
    }

    public function saveSource($id, $name, $url) {
        $name = trim(mb_substr((string) $name, 0, 150));
        if (!self::parseSheetUrl($url)) {
            return 'ลิงก์ต้องเป็น Google Sheet (https://docs.google.com/spreadsheets/d/…)';
        }
        if ($name === '') {
            return 'กรุณาตั้งชื่อแหล่งข้อมูล';
        }
        $row = array('name' => $name, 'sheet_url' => mb_substr(trim($url), 0, 500));
        if ($id > 0) {
            $this->db->update('flood_staff_source', $row, 'source_id = :id', array(':id' => (int) $id));
            return (int) $id;
        }
        $row['is_active'] = 1;
        return (int) $this->db->insert('flood_staff_source', $row);
    }

    public function setSourceActive($id, $on) {
        $this->db->update('flood_staff_source', array('is_active' => $on ? 1 : 0), 'source_id = :id', array(':id' => (int) $id));
    }

    /* ==================== ดึงชีต ==================== */

    /** ดาวน์โหลด CSV จาก Google Sheet (ชีตต้องตั้ง "ทุกคนที่มีลิงก์ดูได้") */
    public function fetchCsv($sheetUrl) {
        $p = self::parseSheetUrl($sheetUrl);
        if (!$p) {
            throw new Exception('ลิงก์ Google Sheet ไม่ถูกต้อง');
        }
        if (!function_exists('curl_init')) {
            throw new Exception('เซิร์ฟเวอร์ไม่มี PHP cURL — ดาวน์โหลดชีตเป็น CSV แล้วอัปโหลดแทน');
        }
        $url = 'https://docs.google.com/spreadsheets/d/' . $p[0] . '/export?format=csv&gid=' . $p[1];
        for ($hop = 0; $hop < 4; $hop++) {
            $body = '';
            $tooBig = false;
            $max = self::MAX_BYTES;
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body, &$tooBig, $max) {
                    $body .= $chunk;
                    if (strlen($body) > $max) {
                        $tooBig = true;
                        return 0;
                    }
                    return strlen($chunk);
                },
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Thailand-Flood-Staff/1.0)',
                CURLOPT_ENCODING => '',
            ));
            $ok = curl_exec($ch);
            $err = curl_error($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $next = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);
            if ($tooBig) {
                throw new Exception('ชีตใหญ่เกิน 8 MB');
            }
            if ($ok === false) {
                throw new Exception('เชื่อมต่อ Google ไม่ได้ (' . $err . ') — ดาวน์โหลดชีตเป็น CSV แล้วอัปโหลดแทน');
            }
            if ($code >= 300 && $code < 400) {
                // Google ส่งต่อไปที่ googleusercontent.com — ถ้าส่งไปหน้าเข้าสู่ระบบ = ชีตไม่ได้เปิดให้ดู
                if (preg_match('#^https://accounts\.google\.com/#', $next)) {
                    throw new Exception('ชีตนี้ต้องเข้าสู่ระบบ Google — ดาวน์โหลดเป็น CSV (ไฟล์ → ดาวน์โหลด → .csv) แล้วอัปโหลดแทน');
                }
                if (preg_match('#^https://([a-z0-9-]+\.)*(google\.com|googleusercontent\.com)/#i', $next)) {
                    $url = $next;
                    continue;
                }
                throw new Exception('Google ส่งต่อไปที่อยู่ที่ไม่รู้จัก');
            }
            if ($code === 401 || $code === 403 || $code === 404) {
                throw new Exception('เปิดชีตไม่ได้ (รหัส ' . $code . ') — ชีตอาจตั้งเป็น "จำกัด" ให้ดาวน์โหลดเป็น CSV แล้วอัปโหลดแทน');
            }
            if ($code !== 200) {
                throw new Exception('Google ตอบกลับรหัส ' . $code);
            }
            if (stripos($type, 'text/html') !== false || preg_match('/^\s*<(!doctype|html)/i', $body)) {
                throw new Exception('ได้หน้าเว็บแทนข้อมูล — ชีตอาจต้องเข้าสู่ระบบ ให้ดาวน์โหลดเป็น CSV แล้วอัปโหลดแทน');
            }
            return $body;
        }
        throw new Exception('Google ส่งต่อหลายทอดเกินไป');
    }

    /** CSV (รองรับข้อความหลายบรรทัดในช่อง) → array ของแถว */
    public static function parseCsv($raw) {
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', (string) $raw);
        if (!mb_check_encoding($raw, 'UTF-8')) {
            // ไฟล์จาก Excel ภาษาไทยมักเป็น TIS-620 / Windows-874
            $conv = @iconv('CP874', 'UTF-8//IGNORE', $raw);
            if ($conv !== false) {
                $raw = $conv;
            }
        }
        $sep = ',';
        $firstLine = strtok($raw, "\n");
        if ($firstLine !== false && substr_count($firstLine, "\t") > substr_count($firstLine, ',')) {
            $sep = "\t";
        }
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, $raw);
        rewind($fh);
        $rows = array();
        while (($r = fgetcsv($fh, 0, $sep, '"', '\\')) !== false) {
            if ($r === array(null)) {
                continue;
            }
            $rows[] = $r;
        }
        fclose($fh);
        return $rows;
    }

    /* ==================== ทำความสะอาดค่า ==================== */

    public static function clean($v) {
        $v = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', (string) $v);
        $v = preg_replace('/[ \t]+/u', ' ', $v);
        $v = trim($v);
        return in_array($v, array('-', '--', '.', 'ไม่มี.'), true) ? '' : $v;
    }

    public static function phoneDigits($v) {
        $d = preg_replace('/\D/', '', (string) $v);
        if (strlen($d) === 11 && strpos($d, '66') === 0) {
            $d = '0' . substr($d, 2);
        }
        if (strlen($d) === 9 && $d[0] !== '0') {
            $d = '0' . $d;   // ชีตตัดเลข 0 หน้าเบอร์ทิ้ง
        }
        return $d;
    }

    public static function phoneKey($v) {
        $d = self::phoneDigits($v);
        return strlen($d) >= 9 ? substr($d, -9) : '';
    }

    public static function nameKey($v) {
        $s = mb_strtolower(self::clean($v));
        $s = preg_replace('/^(นางสาว|น\.ส\.|นส\.?|นาง|นาย|ว่าที่\s*ร\.?ต\.?|ว่าที่ร้อยตรี|ดร\.|mrs?\.?|ms\.?|miss)\s*/u', '', $s);
        return mb_substr(preg_replace('/[^\p{L}\p{M}]/u', '', $s), 0, 150);
    }

    /** "26/9/2026, 19:32:45" / "26/9/2569 19:32" / "2026-09-26 19:32:45" → Y-m-d H:i:s */
    public static function parseTs($v) {
        $v = trim((string) $v);
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4}),?\s+(\d{1,2}):(\d{2})(?::(\d{2}))?#', $v, $m)) {
            $y = (int) $m[3];
            if ($y > 2400) {
                $y -= 543;
            }
            return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $y, (int) $m[2], (int) $m[1], (int) $m[4], (int) $m[5], isset($m[6]) ? (int) $m[6] : 0);
        }
        $t = strtotime($v);
        return $t ? date('Y-m-d H:i:s', $t) : null;
    }

    /* ==================== นำเข้า ==================== */

    /**
     * นำเข้า CSV ของแหล่งหนึ่ง — คืน array(rows, new, dup, skipped, people_new, unmapped[])
     * แถวที่เคยนำเข้าแล้ว (row_key เดิม) ข้าม · แถวใหม่จับคู่บุคคลเดิมด้วยเบอร์โทร แล้วจึงชื่อ
     */
    public function importCsv($sourceId, $raw) {
        $rows = self::parseCsv($raw);
        if (count($rows) < 2) {
            throw new Exception('ไม่พบข้อมูลในชีต (ต้องมีแถวหัวคอลัมน์ + คำตอบ)');
        }
        $head = array_map(array('Staff_Model', 'clean'), array_shift($rows));
        $map = array();
        $unmapped = array();
        foreach ($head as $i => $h) {
            $f = self::mapHeader($h);
            $map[$i] = $f;
            if ($f === null && $h !== '') {
                $unmapped[] = $h;
            }
        }
        if (!in_array('full_name', $map, true)) {
            throw new Exception('ไม่พบคอลัมน์ชื่อ-สกุล — ชีตนี้ไม่ใช่คำตอบแบบสำรวจบุคลากร');
        }
        $out = array('rows' => 0, 'new' => 0, 'dup' => 0, 'skipped' => 0, 'people_new' => 0, 'unmapped' => $unmapped);
        $touched = array();
        $this->db->beginTransaction();
        try {
            foreach ($rows as $r) {
                $vals = array();
                $answers = array();
                foreach ($map as $i => $f) {
                    $v = isset($r[$i]) ? self::clean($r[$i]) : '';
                    if ($f === '_skip') {
                        continue;   // ไม่เก็บเลขบัตรประชาชน
                    }
                    if ($v !== '' && $head[$i] !== '') {
                        $answers[$head[$i]] = $v;
                    }
                    if ($f !== null && $v !== '' && !isset($vals[$f])) {
                        $vals[$f] = $v;
                    }
                }
                $name = isset($vals['full_name']) ? $vals['full_name'] : '';
                if ($name === '' && empty($vals['phone'])) {
                    continue;   // แถวว่าง
                }
                $out['rows']++;
                if ($name === '') {
                    $out['skipped']++;
                    continue;
                }
                $ts = isset($vals['ts']) ? self::parseTs($vals['ts']) : null;
                $phone = isset($vals['phone']) ? self::phoneDigits($vals['phone']) : '';
                $key = sha1($sourceId . '|' . (isset($vals['ts']) ? $vals['ts'] : '') . '|' . self::nameKey($name) . '|' . $phone);
                if ($this->db->selectValue("SELECT resp_id FROM flood_staff_resp WHERE row_key = :k", array(':k' => $key))) {
                    $out['dup']++;
                    continue;
                }
                $sid = $this->matchStaff(self::phoneKey($phone), self::nameKey($name));
                if (!$sid && !empty($vals['phone2'])) {
                    $sid = $this->matchStaff(self::phoneKey($vals['phone2']), '');
                }
                if (!$sid) {
                    $sid = (int) $this->db->insert('flood_staff', array(
                        'full_name' => mb_substr($name, 0, 150), 'name_key' => self::nameKey($name),
                        'phone_key' => self::phoneKey($phone), 'phone' => $phone !== '' ? mb_substr($phone, 0, 20) : null,
                    ));
                    $out['people_new']++;
                }
                $this->db->insert('flood_staff_resp', array(
                    'source_id' => (int) $sourceId, 'staff_id' => $sid, 'row_key' => $key, 'answered_at' => $ts,
                    'answers' => json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                ));
                $out['new']++;
                $touched[$sid] = true;
            }
            foreach (array_keys($touched) as $sid) {
                $this->rebuild($sid);
            }
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->db->update('flood_staff_source', array(
            'last_sync_at' => date('Y-m-d H:i:s'), 'last_rows' => $out['rows'], 'last_new' => $out['new'], 'last_error' => null,
        ), 'source_id = :id', array(':id' => (int) $sourceId));
        return $out;
    }

    public function markSyncError($sourceId, $msg) {
        $this->db->update('flood_staff_source', array('last_error' => mb_substr($msg, 0, 500), 'last_sync_at' => date('Y-m-d H:i:s')),
            'source_id = :id', array(':id' => (int) $sourceId));
    }

    private function matchStaff($phoneKey, $nameKey) {
        if ($phoneKey !== '') {
            $id = $this->db->selectValue("SELECT staff_id FROM flood_staff WHERE phone_key = :p ORDER BY staff_id LIMIT 1", array(':p' => $phoneKey));
            if ($id) {
                return (int) $id;
            }
            // เบอร์ที่ให้ไว้เป็นเบอร์สำรองในฟอร์มอื่น
            $id = $this->db->selectValue("SELECT staff_id FROM flood_staff WHERE phone2 LIKE :p ORDER BY staff_id LIMIT 1", array(':p' => '%' . $phoneKey));
            if ($id) {
                return (int) $id;
            }
        }
        if ($nameKey !== '' && mb_strlen($nameKey) >= 4) {
            $id = $this->db->selectValue("SELECT staff_id FROM flood_staff WHERE name_key = :n ORDER BY staff_id LIMIT 1", array(':n' => $nameKey));
            if ($id) {
                return (int) $id;
            }
        }
        return 0;
    }

    /** รวมคำตอบทุกฟอร์มของบุคคล (เก่า → ใหม่ คำตอบใหม่ที่ไม่ว่างทับของเดิม) แล้วคำนวณระดับ/ป้าย */
    public function rebuild($staffId) {
        $resps = $this->db->select("SELECT source_id, answered_at, answers FROM flood_staff_resp WHERE staff_id = :s
            ORDER BY answered_at IS NULL, answered_at, resp_id", array(':s' => (int) $staffId));
        if (!$resps) {
            return;
        }
        $fields = array_keys(self::fieldLabels());
        $m = array_fill_keys($fields, '');
        $phones = array();
        $sources = array();
        $first = null;
        $last = null;
        $all = array();   // ทุกคำตอบของทุกฟอร์ม — ใช้คำนวณระดับ (รุนแรงสุด)
        foreach ($resps as $r) {
            $sources[(int) $r['source_id']] = true;
            if ($r['answered_at']) {
                $first = $first ?: $r['answered_at'];
                $last = $r['answered_at'];
            }
            $ans = json_decode((string) $r['answers'], true);
            foreach ((array) $ans as $h => $v) {
                $f = self::mapHeader($h);
                if ($f === null || $f === '_skip' || $f === 'ts' || $v === '') {
                    continue;
                }
                if ($f === 'phone' || $f === 'phone2') {
                    $d = self::phoneDigits($v);
                    if ($d !== '') {
                        $phones[$d] = $f;
                    }
                    continue;
                }
                $m[$f] = $v;
                $all[] = array($f, $v);
            }
        }
        // เบอร์หลัก = เบอร์ล่าสุดที่ให้เป็นเบอร์หลัก · เบอร์อื่นเป็นเบอร์สำรอง
        $main = '';
        foreach ($phones as $d => $f) {
            if ($f === 'phone') {
                $main = $d;
            }
        }
        if ($main === '' && $phones) {
            $main = array_keys($phones)[0];
        }
        $others = array_values(array_diff(array_keys($phones), array($main)));
        $calc = self::classify($all);
        $row = array();
        foreach ($fields as $f) {
            if ($f === 'phone' || $f === 'phone2') {
                continue;
            }
            $max = in_array($f, array('work_problem', 'help_need', 'damage', 'photos', 'suggest', 'note'), true) ? 5000 : 300;
            $row[$f] = $m[$f] !== '' ? mb_substr($m[$f], 0, $max) : null;
        }
        // ตัดคำนำหน้าที่พิมพ์ซ้ำในช่องชื่อ (ฟอร์มที่ไม่มีช่องคำนำหน้า) — เก็บไว้ในช่องคำนำหน้าแทน
        // (ต้องมีเว้นวรรคหลังคำนำหน้า กันชื่อจริงอย่าง "นางนวล" ถูกตัด · หรือตรงกับช่องคำนำหน้าที่ตอบไว้)
        if ($row['full_name'] && $row['prefix'] && mb_strpos($row['full_name'], $row['prefix']) === 0
            && mb_strlen($row['full_name']) > mb_strlen($row['prefix']) + 2) {
            $row['full_name'] = trim(mb_substr($row['full_name'], mb_strlen($row['prefix'])));
        } elseif ($row['full_name'] && preg_match('/^(นางสาว|น\.ส\.|นาง|นาย|ว่าที่\s*ร\.?ต\.?(หญิง)?|ดร\.)(\s+|(?<=\.))(.+)$/u', $row['full_name'], $pm)) {
            $row['full_name'] = trim($pm[4]);
            $row['prefix'] = $row['prefix'] ?: $pm[1];
        }
        $row['full_name'] = $row['full_name'] ?: '(ไม่ระบุชื่อ)';
        $row['name_key'] = self::nameKey($row['full_name']);
        $row['phone'] = $main !== '' ? $main : null;
        $row['phone_key'] = self::phoneKey($main);
        $row['phone2'] = $others ? mb_substr(end($others), 0, 20) : null;   // เบอร์อื่นทั้งหมดดูได้ในคำตอบต้นฉบับ
        $row['level'] = $calc['level'];
        $row['flags'] = implode(',', $calc['flags']);
        $row['resp_count'] = count($resps);
        $row['sources'] = implode(',', array_keys($sources));
        $row['first_at'] = $first;
        $row['last_at'] = $last;
        $row['updated_at'] = date('Y-m-d H:i:s');
        $this->db->update('flood_staff', $row, 'staff_id = :s', array(':s' => (int) $staffId));
    }

    /** คำตอบทั้งหมด [(field, value)] → ระดับผลกระทบ (รุนแรงสุด) + ป้าย */
    public static function classify($all) {
        $rank = array('severe' => 1, 'moderate' => 2, 'mild' => 3, 'none' => 4, 'unknown' => 5);
        $level = 'unknown';
        $flags = array();
        $bump = function ($l) use (&$level, $rank) {
            if ($rank[$l] < $rank[$level]) {
                $level = $l;
            }
        };
        foreach ($all as $a) {
            list($f, $v) = $a;
            if (mb_strpos($v, 'ไม่สามารถมาปฏิบัติงาน') !== false) {
                $flags['cant_work'] = 1;
                $bump('severe');
            }
            if (preg_match('/กลับบ้านไม่ได้|ถูกตัดขาด|ออกจากพื้นที่ไม่ได้|ค้างคืนที่โรงพยาบาล/u', $v)) {
                $flags['stranded'] = 1;
            }
            switch ($f) {
                case 'victim':
                    if (preg_match('/^ไม่เป็นผู้ประสบภัย/u', $v)) {
                        $bump('none');
                    } elseif (mb_strpos($v, 'ที่อยู่อาศัย') !== false) {
                        $flags['home_hit'] = 1;
                        $bump('severe');
                    } elseif (preg_match('/ถนน|ปิดกั้น/u', $v)) {
                        $bump('moderate');
                    } else {
                        $bump('mild');   // ยังไม่ประสบภัย แต่อาจเกิด / อื่น ๆ
                    }
                    break;
                case 'impact':
                    if (preg_match('/^ไม่ได้รับผลกระทบ/u', $v)) {
                        $bump('none');
                    } elseif (mb_strpos($v, 'รุนแรง') !== false) {
                        $flags['stranded'] = 1;
                        $bump('severe');
                    } elseif (mb_strpos($v, 'ปานกลาง') !== false) {
                        $bump('moderate');
                    } else {
                        $bump('mild');
                    }
                    break;
                case 'shift_status':
                    if (preg_match('/กลับบ้านไม่ได้|ค้างคืน/u', $v)) {
                        $bump('moderate');
                    }
                    break;
                case 'has_shelter':
                    if (preg_match('/^ยังไม่มี|ต้องการที่พัก|ต้องการให้จัดหา/u', $v)) {
                        $flags['need_shelter'] = 1;
                        $bump('severe');
                    }
                    break;
                case 'help_need':
                case 'toiletries':
                case 'shelter_need':
                    if (!self::isNoNeed($v)) {
                        $flags['need_help'] = 1;
                    }
                    break;
                case 'clothing':
                    if (preg_match('/^ต้องการ/u', $v)) {
                        $flags['need_help'] = 1;
                    }
                    break;
                case 'work_status':
                    if (mb_strpos($v, 'ยากลำบาก') !== false) {
                        $bump('moderate');
                    } elseif (mb_strpos($v, 'ตามปกติ') !== false) {
                        $bump('none');
                    }
                    break;
            }
        }
        if (isset($flags['stranded']) && $rank[$level] > 2) {
            $level = 'moderate';
        }
        $order = array_keys(flood_staff_flags());
        $out = array();
        foreach ($order as $k) {
            if (isset($flags[$k])) {
                $out[] = $k;
            }
        }
        return array('level' => $level, 'flags' => $out);
    }

    /** คำตอบแบบ "ไม่ต้องการ / ไม่มี / มีครบแล้ว" — ไม่นับเป็นการขอความช่วยเหลือ */
    public static function isNoNeed($v) {
        $v = trim(preg_replace('/\s+/u', ' ', (string) $v));
        return (bool) (preg_match('/^(ยัง)?ไม่(มี|ต้องการ|ได้ต้องการ)?\s*(ค่ะ|คะ|ครับ|นะคะ|จ้า)?\s*[.!]*$/u', $v)
            || preg_match('/^(มีครบ|ตั้งรับและเตรียมพร้อม|เพียงพอ|no\b|none\b|n\/a)/iu', $v));
    }

    /** คำนวณระดับ/ป้ายใหม่ทุกคน (หลังปรับกติกา) */
    public function rebuildAll() {
        $ids = $this->db->select("SELECT staff_id FROM flood_staff");
        foreach ($ids as $r) {
            $this->rebuild((int) $r['staff_id']);
        }
        return count($ids);
    }

    /* ==================== รายการ / สรุป ==================== */

    private function where($f, &$params) {
        $w = array('1=1');
        if ($f['q'] !== '') {
            $cols = array('s.full_name', 's.phone', 's.phone2', 's.department', 's.position', 's.addr_now');
            $or = array();
            foreach ($cols as $i => $c) {
                $or[] = "$c LIKE :q$i";
                $params[':q' . $i] = '%' . $f['q'] . '%';
            }
            $w[] = '(' . implode(' OR ', $or) . ')';
        }
        if ($f['level'] !== '') {
            if ($f['level'] === 'affected') {
                $w[] = "s.level IN ('severe','moderate')";
            } else {
                $w[] = "s.level = :lv";
                $params[':lv'] = $f['level'];
            }
        }
        if ($f['flag'] !== '') {
            $w[] = "FIND_IN_SET(:fl, s.flags)";
            $params[':fl'] = $f['flag'];
        }
        if ($f['follow'] !== '') {
            $w[] = "s.follow_status = :fs";
            $params[':fs'] = $f['follow'];
        }
        if ($f['dept'] !== '') {
            $w[] = "s.department = :dp";
            $params[':dp'] = $f['dept'];
        }
        if ($f['source'] > 0) {
            $w[] = "FIND_IN_SET(:src, s.sources)";
            $params[':src'] = (string) $f['source'];
        }
        return implode(' AND ', $w);
    }

    public function listStaff($f, $page = 1, $all = false) {
        $params = array();
        $where = $this->where($f, $params);
        $order = "FIELD(s.level,'severe','moderate','mild','unknown','none'), FIELD(s.follow_status,'new','contacted','helping','done','no_need'), s.last_at DESC";
        if ($all) {
            return $this->db->select("SELECT s.* FROM flood_staff s WHERE $where ORDER BY $order", $params);
        }
        $total = (int) $this->db->selectValue("SELECT COUNT(*) FROM flood_staff s WHERE $where", $params);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, (int) $page), $pages);
        $rows = $this->db->select("SELECT s.* FROM flood_staff s WHERE $where ORDER BY $order
            LIMIT " . self::PER_PAGE . " OFFSET " . (($page - 1) * self::PER_PAGE), $params);
        return array('rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages);
    }

    public function summary() {
        $s = $this->db->selectOne("SELECT COUNT(*) AS total,
                SUM(level = 'severe') AS severe, SUM(level = 'moderate') AS moderate, SUM(level = 'mild') AS mild, SUM(level = 'none') AS none,
                SUM(FIND_IN_SET('need_shelter', flags) > 0) AS need_shelter,
                SUM(FIND_IN_SET('cant_work', flags) > 0) AS cant_work,
                SUM(FIND_IN_SET('stranded', flags) > 0) AS stranded,
                SUM(FIND_IN_SET('home_hit', flags) > 0) AS home_hit,
                SUM(FIND_IN_SET('need_help', flags) > 0) AS need_help,
                SUM(level IN ('severe','moderate') AND follow_status = 'new') AS waiting,
                (SELECT COUNT(*) FROM flood_staff_resp) AS responses
            FROM flood_staff");
        foreach ($s as $k => $v) {
            $s[$k] = (int) $v;
        }
        $s['follow'] = $this->followSummary();
        return $s;
    }

    /**
     * สรุปการติดตาม: จำนวนคนต่อสถานะ แยกระดับ รุนแรง / ปานกลาง / อื่น ๆ (ไม่มีรายชื่อ)
     * คืน array(สถานะ => array(severe, moderate, other, affected, total)) ตามลำดับ flood_staff_follow_options()
     */
    public function followSummary() {
        $out = array();
        foreach (array_keys(flood_staff_follow_options()) as $code) {
            $out[$code] = array('severe' => 0, 'moderate' => 0, 'other' => 0, 'affected' => 0, 'total' => 0);
        }
        $rows = $this->db->select("SELECT follow_status, level, COUNT(*) AS n FROM flood_staff GROUP BY follow_status, level");
        foreach ($rows as $r) {
            $code = isset($out[$r['follow_status']]) ? $r['follow_status'] : 'new';
            $n = (int) $r['n'];
            $lv = in_array($r['level'], array('severe', 'moderate'), true) ? $r['level'] : 'other';
            $out[$code][$lv] += $n;
            $out[$code]['total'] += $n;
            if ($lv !== 'other') {
                $out[$code]['affected'] += $n;
            }
        }
        return $out;
    }

    /** กลุ่มงาน + จำนวน (ผู้ได้รับผลกระทบก่อน) */
    public function departments() {
        return $this->db->select("SELECT department, COUNT(*) AS n, SUM(level IN ('severe','moderate')) AS hit
            FROM flood_staff WHERE department IS NOT NULL AND department <> ''
            GROUP BY department ORDER BY hit DESC, n DESC, department");
    }

    public function getStaff($id) {
        return $this->db->selectOne("SELECT s.*, u.name AS followed_by_name FROM flood_staff s
            LEFT JOIN flood_user u ON u.user_id = s.followed_by WHERE s.staff_id = :id", array(':id' => (int) $id));
    }

    public function responses($staffId) {
        return $this->db->select("SELECT r.resp_id, r.source_id, r.answered_at, r.answers, src.name AS source_name
            FROM flood_staff_resp r LEFT JOIN flood_staff_source src ON src.source_id = r.source_id
            WHERE r.staff_id = :s ORDER BY r.answered_at DESC, r.resp_id DESC", array(':s' => (int) $staffId));
    }

    public function setFollow($id, $status, $note, $uid) {
        if (!array_key_exists($status, flood_staff_follow_options())) {
            return false;
        }
        $this->db->update('flood_staff', array(
            'follow_status' => $status, 'follow_note' => $note !== '' ? mb_substr($note, 0, 2000) : null,
            'followed_by' => (int) $uid, 'followed_at' => date('Y-m-d H:i:s'),
        ), 'staff_id = :id', array(':id' => (int) $id));
        return true;
    }

    /** รวมบุคคลที่ระบบจับคู่ไม่ได้ (ชื่อสะกดต่างกัน + เบอร์ต่างกัน) — ย้ายคำตอบของ $fromId ไปที่ $intoId */
    public function merge($intoId, $fromId) {
        if ($intoId === $fromId || !$this->getStaff($intoId) || !$this->getStaff($fromId)) {
            return false;
        }
        $this->db->update('flood_staff_resp', array('staff_id' => (int) $intoId), 'staff_id = :f', array(':f' => (int) $fromId));
        $this->db->delete('flood_staff', 'staff_id = :f', 1, array(':f' => (int) $fromId));
        $this->rebuild($intoId);
        return true;
    }
}
