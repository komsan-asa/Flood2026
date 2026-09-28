<?php

require_once 'models/staff_model.php';

/**
 * ฟอร์มในระบบให้บุคลากรโรงพยาบาลแจ้งผลกระทบ / ปัญหา / ขอความช่วยเหลือ (หน้า staffreport)
 *
 * - เปิดได้ด้วยลิงก์ + รหัสสั้นที่เจ้าหน้าที่ตั้ง (หน้า staffreport/admin) — ไม่มีรหัส = ปิดรับ
 * - คำตอบเข้ารายชื่อ "บุคลากรที่ได้รับผลกระทบ" (flood/staff) เป็นแหล่งข้อมูลหนึ่งใน flood_staff_source
 *   (sheet_url = ลิงก์ฟอร์มนี้, is_active = 0 เพื่อไม่ให้ปุ่ม "ดึงจาก Google Sheet" ไปดึงแหล่งนี้)
 * - หัวข้อคำถามตั้งให้ Staff_Model::mapHeader() จับคู่ฟิลด์ได้ → ระดับผลกระทบ/ป้าย คำนวณด้วยกติกาเดียวกับ Google Form
 * - ไม่เก็บเลขบัตรประชาชน · รูปถ่ายเก็บใน flood_attachment (ref_type = staff) ดูได้เฉพาะเจ้าหน้าที่ศูนย์ผ่าน flood/attachment/<id>
 */
class Staff_Form_Model extends Model {

    const SOURCE_NAME = 'ฟอร์มในระบบ — บุคลากรแจ้งปัญหา/ขอความช่วยเหลือ';
    const SETTING_CODE = 'staff_form_code';
    const FAIL_PER_IP_HOUR = 20;

    /** ตัวเลือกในฟอร์ม (code => ข้อความที่เก็บเป็นคำตอบ — ถ้อยคำตั้งให้ Staff_Model::classify() อ่านระดับได้) */
    public static function victimOptions() {
        return array(
            'home' => array('ที่อยู่อาศัยถูกน้ำท่วม', '🏠'),
            'road' => array('ถนน/เส้นทางถูกปิดกั้น เดินทางลำบาก', '🛣️'),
            'risk' => array('ยังไม่ประสบภัย แต่อยู่ในพื้นที่เสี่ยง', '👀'),
            'none' => array('ไม่เป็นผู้ประสบภัย', '✅'),
        );
    }

    public static function workOptions() {
        return array(
            'normal' => 'มาปฏิบัติงานได้ตามปกติ',
            'hard' => 'มาปฏิบัติงานได้ แต่ยากลำบาก',
            'cant' => 'ไม่สามารถมาปฏิบัติงานได้',
        );
    }

    /** หลังลงเวร: [ข้อความในฟอร์ม, สถานะการเข้าเวร, ที่พักนอน] */
    public static function shiftOptions() {
        return array(
            'home' => array('กลับบ้านได้ตามปกติ', 'ลงเวรแล้วกลับบ้านได้', ''),
            'stay' => array('กลับบ้านไม่ได้ แต่มีที่พักแล้ว', 'ลงเวรแล้วกลับบ้านไม่ได้', 'มีที่พักแล้ว'),
            'need' => array('กลับบ้านไม่ได้ ยังไม่มีที่พัก', 'ลงเวรแล้วกลับบ้านไม่ได้', 'ยังไม่มีที่พัก ต้องการที่พักด่วน'),
        );
    }

    public static function helpOptions() {
        return array(
            'shelter' => array('ที่พักชั่วคราว', '🛏️'),
            'food' => array('อาหาร / น้ำดื่ม', '🍚'),
            'clothes' => array('เสื้อผ้า / ของใช้ส่วนตัว', '👕'),
            'ride' => array('รถรับส่งมาทำงาน', '🚐'),
            'move' => array('ขนย้ายของ / อพยพครอบครัว', '📦'),
            'health' => array('ยา / ดูแลสุขภาพ', '💊'),
            'other' => array('อื่น ๆ (พิมพ์ในช่องรายละเอียด)', '❓'),
        );
    }

    /* ==================== ตาราง / ตั้งค่า ==================== */

    public function ensureTables() {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        $sm = new Staff_Model();
        if (!$sm->ensureTables()) {
            return $ready = false;
        }
        try {
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS `flood_setting` (
                  `skey` VARCHAR(50) NOT NULL,
                  `sval` VARCHAR(500) DEFAULT NULL,
                  `updated_by` INT UNSIGNED DEFAULT NULL,
                  `updated_at` DATETIME DEFAULT NULL,
                  PRIMARY KEY (`skey`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS `flood_staff_form_fail` (
                  `ip` VARCHAR(45) NOT NULL,
                  `at` DATETIME NOT NULL,
                  KEY `idx_flood_staff_form_fail` (`ip`, `at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $ready = true;
        } catch (Exception $e) {
            error_log('[flood] staff form tables: ' . $e->getMessage());
            $ready = false;
        }
        return $ready;
    }

    public function getSetting($key) {
        $v = $this->db->selectValue("SELECT sval FROM flood_setting WHERE skey = :k", array(':k' => $key));
        return $v === false || $v === null ? '' : (string) $v;
    }

    public function setSetting($key, $val, $uid) {
        $this->db->exec("INSERT INTO flood_setting (skey, sval, updated_by, updated_at) VALUES ("
            . $this->db->quote($key) . ', ' . $this->db->quote((string) $val) . ', ' . ($uid ? (int) $uid : 'NULL') . ', NOW())
            ON DUPLICATE KEY UPDATE sval = VALUES(sval), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)');
    }

    public function code() {
        return $this->getSetting(self::SETTING_CODE);
    }

    /** รหัส 4–12 ตัว (ตัวเลข/อังกฤษ ไม่สนตัวพิมพ์) — ว่าง = ปิดรับฟอร์ม */
    public static function normalizeCode($c) {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $c));
    }

    public function setCode($code, $uid) {
        $code = self::normalizeCode($code);
        if ($code !== '' && (strlen($code) < 4 || strlen($code) > 12)) {
            return 'รหัสต้องยาว 4–12 ตัว (ตัวเลขหรือตัวอักษรอังกฤษ)';
        }
        $this->setSetting(self::SETTING_CODE, $code, $uid);
        return true;
    }

    public static function randomCode() {
        return (string) random_int(100000, 999999);
    }

    public function codeMatches($given) {
        $code = $this->code();
        return $code !== '' && hash_equals($code, self::normalizeCode($given));
    }

    public function failCount($ip) {
        return (int) $this->db->selectValue("SELECT COUNT(*) FROM flood_staff_form_fail WHERE ip = :ip AND at >= :t",
            array(':ip' => (string) $ip, ':t' => date('Y-m-d H:i:s', time() - 3600)));
    }

    public function addFail($ip) {
        $this->db->insert('flood_staff_form_fail', array('ip' => mb_substr((string) $ip, 0, 45), 'at' => date('Y-m-d H:i:s')));
        if (mt_rand(1, 50) === 1) {
            $this->db->exec("DELETE FROM flood_staff_form_fail WHERE at < " . $this->db->quote(date('Y-m-d H:i:s', time() - 86400)));
        }
    }

    /** แหล่งข้อมูลของฟอร์มนี้ใน flood_staff_source (สร้างเองครั้งแรก) */
    public function sourceId() {
        $id = $this->db->selectValue("SELECT source_id FROM flood_staff_source WHERE sheet_url LIKE '%/staffreport' ORDER BY source_id LIMIT 1");
        if ($id) {
            return (int) $id;
        }
        return (int) $this->db->insert('flood_staff_source', array(
            'name' => self::SOURCE_NAME, 'sheet_url' => URL . 'staffreport', 'is_active' => 0,
        ));
    }

    public function stats() {
        $sid = $this->sourceId();
        return array(
            'total' => (int) $this->db->selectValue("SELECT COUNT(*) FROM flood_staff_resp WHERE source_id = :s", array(':s' => $sid)),
            'today' => (int) $this->db->selectValue("SELECT COUNT(*) FROM flood_staff_resp WHERE source_id = :s AND imported_at >= :d",
                array(':s' => $sid, ':d' => date('Y-m-d 00:00:00'))),
            'last' => $this->db->selectValue("SELECT MAX(imported_at) FROM flood_staff_resp WHERE source_id = :s", array(':s' => $sid)),
        );
    }

    /** ส่งจากเบอร์นี้ผ่านฟอร์มนี้ไปกี่ครั้งใน 1 ชั่วโมง */
    public function recentByPhone($phoneKey) {
        if ($phoneKey === '') {
            return 0;
        }
        return (int) $this->db->selectValue(
            "SELECT COUNT(*) FROM flood_staff_resp r JOIN flood_staff s ON s.staff_id = r.staff_id
             WHERE r.source_id = :src AND s.phone_key = :p AND r.imported_at >= :t",
            array(':src' => $this->sourceId(), ':p' => $phoneKey, ':t' => date('Y-m-d H:i:s', time() - 3600)));
    }

    /* ==================== บันทึกคำตอบ ==================== */

    /** ตรวจข้อมูลจากฟอร์ม → array(answers, name, phone) หรือข้อความผิดพลาด (string) */
    public function parseInput($src) {
        $clean = function ($k, $max) use ($src) {
            return mb_substr(Staff_Model::clean(isset($src[$k]) && is_string($src[$k]) ? $src[$k] : ''), 0, $max);
        };
        $name = $clean('full_name', 150);
        $phone = Staff_Model::phoneDigits($clean('phone', 20));
        $dept = $clean('department', 150);
        if (mb_strlen($name) < 4) {
            return array('field' => 'secWho', 'msg' => 'กรุณากรอกชื่อ-สกุล');
        }
        if (!preg_match('/^0\d{8,9}$/', $phone)) {
            return array('field' => 'secWho', 'msg' => 'กรุณากรอกเบอร์โทรที่ติดต่อได้ 9–10 หลัก');
        }
        if ($dept === '') {
            return array('field' => 'secWho', 'msg' => 'กรุณากรอกกลุ่มงาน / หน่วยงาน');
        }
        $victim = isset($src['victim']) ? (string) $src['victim'] : '';
        $vo = self::victimOptions();
        if (!isset($vo[$victim])) {
            return array('field' => 'secImpact', 'msg' => 'กรุณาเลือกผลกระทบจากน้ำท่วม');
        }
        $work = isset($src['work']) ? (string) $src['work'] : '';
        $wo = self::workOptions();
        if (!isset($wo[$work])) {
            return array('field' => 'secImpact', 'msg' => 'กรุณาเลือกเรื่องการมาปฏิบัติงาน');
        }
        $shift = isset($src['shift']) ? (string) $src['shift'] : '';
        $so = self::shiftOptions();
        $helpSel = array();
        $ho = self::helpOptions();
        foreach ((array) (isset($src['help']) ? $src['help'] : array()) as $c) {
            if (is_string($c) && isset($ho[$c])) {
                $helpSel[$c] = $ho[$c][0];
            }
        }
        $detail = trim(mb_substr(Staff_Model::clean(isset($src['detail']) && is_string($src['detail']) ? str_replace(array("\r\n", "\r"), "\n", $src['detail']) : ''), 0, 2000));
        if (isset($helpSel['other']) && $detail === '') {
            return array('field' => 'secHelp', 'msg' => 'เลือก "อื่น ๆ" แล้ว กรุณาพิมพ์รายละเอียดว่าต้องการอะไร');
        }
        $addr = $clean('addr_now', 300);
        $lat = isset($src['lat']) ? (string) $src['lat'] : '';
        $lng = isset($src['lng']) ? (string) $src['lng'] : '';

        // หัวข้อ = ชื่อคอลัมน์ที่ Staff_Model::mapHeader() จับคู่ฟิลด์ได้ (ลำดับมีผล — ข้อหลังทับข้อก่อนในฟิลด์เดียวกัน)
        $a = array();
        $a['ประทับเวลา'] = date('d/m/Y H:i:s');
        $a['ชื่อ-สกุล'] = $name;
        $a['เบอร์โทรศัพท์ที่ติดต่อได้'] = $phone;
        $a['ตำแหน่ง'] = $clean('position', 150);
        $a['กลุ่มงาน/หน่วยงาน'] = $dept;
        $a['เป็นผู้ประสบภัยหรือไม่'] = $vo[$victim][0];
        $a['การมาทำงาน'] = $wo[$work];
        if (isset($so[$shift])) {
            $a['สถานะการเข้าเวร (หลังลงเวร)'] = $so[$shift][1];
            if ($so[$shift][2] !== '') {
                $a['สถานที่พักนอนหลังลงเวร'] = $so[$shift][2];
            }
        }
        if ($helpSel) {
            $a['ต้องการความช่วยเหลือ'] = implode(', ', $helpSel);
        }
        if ($detail !== '') {
            $a['ปัญหาในการปฏิบัติงาน / ปัญหาที่พบ'] = $detail;
        }
        if ($addr !== '') {
            $a['ที่อยู่ปัจจุบัน'] = $addr;
        }
        if ($lat !== '' && $lng !== '' && flood_valid_latlng($lat, $lng)) {
            $a['พิกัด GPS'] = 'https://www.google.com/maps?q=' . round((float) $lat, 6) . ',' . round((float) $lng, 6);
        }
        $a['ช่องทาง'] = 'ฟอร์มในระบบ ' . SHORT_NAME_SYSTEM;
        // ต้องให้ทีมติดตามต่อไหม (ใช้ตัดสินว่าจะเปิดเรื่องที่ปิดไปแล้วขึ้นมาใหม่หรือไม่)
        $needs = $helpSel || $shift === 'need' || $work === 'cant' || $victim === 'home';
        return array('answers' => array_filter($a, function ($v) { return $v !== ''; }), 'name' => $name, 'phone' => $phone, 'needs' => $needs);
    }

    /** เหมือน Staff_Model::matchStaff() (เบอร์ → เบอร์สำรอง → ชื่อ) */
    private function matchStaff($phoneKey, $nameKey) {
        if ($phoneKey !== '') {
            $id = $this->db->selectValue("SELECT staff_id FROM flood_staff WHERE phone_key = :p ORDER BY staff_id LIMIT 1", array(':p' => $phoneKey));
            if (!$id) {
                $id = $this->db->selectValue("SELECT staff_id FROM flood_staff WHERE phone2 LIKE :p ORDER BY staff_id LIMIT 1", array(':p' => '%' . $phoneKey));
            }
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

    /**
     * บันทึก 1 คำตอบ → รวมเข้าบุคคลเดิม (หรือสร้างใหม่) แล้วคำนวณระดับใหม่
     * $photos = ผลจาก flood_store_images() · คืน array(staff_id, is_new)
     */
    public function save($parsed, $photos) {
        $sm = new Staff_Model();
        $sourceId = $this->sourceId();
        $name = $parsed['name'];
        $phone = $parsed['phone'];
        $answers = $parsed['answers'];
        $this->db->beginTransaction();
        try {
            $sid = $this->matchStaff(Staff_Model::phoneKey($phone), Staff_Model::nameKey($name));
            $isNew = false;
            if (!$sid) {
                $sid = (int) $this->db->insert('flood_staff', array(
                    'full_name' => mb_substr($name, 0, 150), 'name_key' => Staff_Model::nameKey($name),
                    'phone_key' => Staff_Model::phoneKey($phone), 'phone' => mb_substr($phone, 0, 20),
                ));
                $isNew = true;
            }
            if ($photos) {
                $links = array();
                foreach ($photos as $f) {
                    $aid = (int) $this->db->insert('flood_attachment', array(
                        'ref_type' => 'staff', 'ref_id' => $sid, 'file_path' => $f['file_path'], 'orig_name' => $f['orig_name'],
                        'mime' => $f['mime'], 'size_bytes' => (int) $f['size_bytes'],
                    ));
                    $links[] = URL . 'flood/attachment/' . $aid;
                }
                $answers['รูปถ่าย'] = implode(', ', $links);
            }
            $this->db->insert('flood_staff_resp', array(
                'source_id' => $sourceId, 'staff_id' => $sid,
                'row_key' => sha1($sourceId . '|' . microtime(true) . '|' . Staff_Model::nameKey($name) . '|' . $phone . '|' . random_int(0, PHP_INT_MAX)),
                'answered_at' => date('Y-m-d H:i:s'),
                'answers' => json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            ));
            $sm->rebuild($sid);
            // เคยปิดเรื่องไปแล้ว (ช่วยเหลือแล้ว / ไม่ต้องการ) แต่แจ้งเข้ามาใหม่ว่ายังต้องการความช่วยเหลือ → กลับไปอยู่ในคิว "ยังไม่ติดตาม"
            $st = $this->db->selectOne("SELECT follow_status, follow_note FROM flood_staff WHERE staff_id = :s", array(':s' => $sid));
            if ($st && !empty($parsed['needs']) && in_array($st['follow_status'], array('done', 'no_need'), true)) {
                $this->db->update('flood_staff', array(
                    'follow_status' => 'new',
                    'follow_note' => mb_substr('[แจ้งใหม่ผ่านฟอร์มในระบบ ' . date('d/m H:i') . '] ' . (string) $st['follow_note'], 0, 5000),
                ), 'staff_id = :s', array(':s' => $sid));
            }
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
        return array($sid, $isNew);
    }
}
