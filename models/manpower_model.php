<?php

/**
 * ตรวจสอบอัตรากำลังพยาบาลวิชาชีพที่ขึ้นปฏิบัติงานรายวัน/รายเวร เทียบกรอบอัตรากำลัง (หน้า flood/manpower)
 *
 * ตาราง
 *   flood_mp_unit     หน่วยงาน + กรอบจำนวนพยาบาลต่อเวร (ช/บ/ด) วันทำการ และวันหยุด
 *   flood_mp_checkin  การลงเวลาปฏิบัติงานรายคน — ระบบลงเวลา (Flood2026-site-api) เขียนเข้าตารางนี้
 *   flood_mp_count    จำนวนที่เจ้าหน้าที่กรอกเอง (ใช้แทนค่าจากการลงเวลาเมื่อมี)
 *   flood_mp_holiday  วันหยุดราชการ/นักขัตฤกษ์ (เสาร์-อาทิตย์นับเป็นวันหยุดอยู่แล้ว)
 *
 * เวร: M = เช้า 08:00–16:00 · A = บ่าย 16:00–24:00 · N = ดึก 00:00–08:00 ของวันที่ work_date
 * กรอบตั้งต้นจากเอกสาร "เกณฑ์ลดเพิ่มคน 1 กรกฎาคม 2568" — ผู้ดูแลระบบแก้ได้ที่ flood/manpowerUnits
 */
class Manpower_Model extends Model {

    const SHIFTS = array(
        'M' => array('name' => 'เช้า', 'short' => 'ช', 'start' => '08:00', 'end' => '16:00'),
        'A' => array('name' => 'บ่าย', 'short' => 'บ', 'start' => '16:00', 'end' => '24:00'),
        'N' => array('name' => 'ดึก', 'short' => 'ด', 'start' => '00:00', 'end' => '08:00'),
    );

    public static function shifts() {
        return self::SHIFTS;
    }

    public static function validDate($d) {
        $d = (string) $d;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            return false;
        }
        $p = explode('-', $d);
        return checkdate((int) $p[1], (int) $p[2], (int) $p[0]);
    }

    /** เวรที่กำลังดำเนินอยู่ตอนนี้ (ตามเวลาไทยของเซิร์ฟเวอร์) */
    public static function currentShift() {
        $h = (int) date('G');
        return $h < 8 ? 'N' : ($h < 16 ? 'M' : 'A');
    }

    /** เวรเริ่มแล้วหรือยัง — ใช้แยก "รอข้อมูล" กับ "ยังไม่ถึงเวร" */
    public static function shiftStarted($date, $shift) {
        $start = strtotime($date . ' ' . self::SHIFTS[$shift]['start'] . ':00');
        return time() >= $start;
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
            $cols = array();
            foreach ($this->db->select('SHOW COLUMNS FROM flood_mp_unit') as $c) {
                $cols[$c['Field']] = true;
            }
            if (!isset($cols['hr_depts'])) {
                $this->db->exec("ALTER TABLE flood_mp_unit ADD COLUMN `hr_depts` VARCHAR(500) DEFAULT NULL AFTER `note`");
            }
            if ((int) $this->db->selectValue('SELECT COUNT(*) FROM flood_mp_unit') === 0) {
                $this->seedUnits();
            }
            $ready = true;
        } catch (Exception $e) {
            $ready = false;
        }
        return $ready;
    }

    /** คำสั่งสร้างตาราง — ใช้ร่วมกับ sql/18_flood_manpower.sql */
    public static function schemaSql() {
        return array(
            "CREATE TABLE IF NOT EXISTS `flood_mp_unit` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS `flood_mp_checkin` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS `flood_mp_count` (
              `unit_id` INT UNSIGNED NOT NULL,
              `work_date` DATE NOT NULL,
              `shift` CHAR(1) NOT NULL,
              `actual` TINYINT UNSIGNED NOT NULL,
              `note` VARCHAR(300) DEFAULT NULL,
              `updated_by` INT UNSIGNED DEFAULT NULL,
              `updated_at` DATETIME DEFAULT NULL,
              PRIMARY KEY (`unit_id`, `work_date`, `shift`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS `flood_mp_sync` (
              `sync_date` DATE NOT NULL,
              `synced_at` DATETIME NOT NULL,
              `fetched` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'การสแกนเข้าเวรที่ได้จาก API',
              `mapped` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'จับคู่หน่วยงานได้',
              `unmapped` MEDIUMTEXT DEFAULT NULL COMMENT 'JSON {หน่วยงาน hosoffice: จำนวนคน} ที่ยังไม่จับคู่',
              `error` VARCHAR(500) DEFAULT NULL,
              PRIMARY KEY (`sync_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS `flood_mp_holiday` (
              `hdate` DATE NOT NULL,
              `name` VARCHAR(150) NOT NULL,
              PRIMARY KEY (`hdate`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }

    /**
     * กรอบตั้งต้นจากเอกสารเกณฑ์ 1 ก.ค. 2568 — [code, กลุ่มงาน, หน่วยงาน, เกณฑ์, ช, บ, ด, ช วันหยุด, บ วันหยุด, ด วันหยุด, หมายเหตุ]
     * ตัวเลขในวงเล็บของเอกสาร เช่น "ช 4(3)" ตีความเป็นกรอบวันหยุด (เช้าวันทำการมีกิจกรรมพยาบาลมากกว่า)
     */
    public static function seedData() {
        $er = 'กลุ่มงานการพยาบาลผู้ป่วยอุบัติเหตุและฉุกเฉิน';
        $icu = 'กลุ่มงานการพยาบาลผู้ป่วยหนัก';
        $lr = 'กลุ่มงานการพยาบาลผู้คลอด';
        $med = 'กลุ่มงานการพยาบาลผู้ป่วยอายุรกรรม';
        $sur = 'กลุ่มงานการพยาบาลผู้ป่วยศัลยกรรม';
        $ob = 'กลุ่มงานการพยาบาลผู้ป่วยสูติ-นรีเวช';
        $psy = 'กลุ่มงานการพยาบาลจิตเวช';
        $ped = 'กลุ่มงานการพยาบาลผู้ป่วยกุมารเวชกรรม';
        $ort = 'กลุ่มงานการพยาบาลผู้ป่วยออร์โธปิดิกส์';
        $ent = 'กลุ่มงานการพยาบาลผู้ป่วยโสต ศอ นาสิก จักษุ';
        return array(
            array('ER', $er, 'งานอุบัติเหตุและฉุกเฉิน', '1 ต่อ 10', 8, 7, 5, 9, 8, 5, 'เอกสาร: ช 8(9)/ บ 7(8)/ ด 5 · ไม่ลดคน'),
            array('REFER', $er, 'งานศูนย์รับส่งต่อ', null, 2, 1, 1, null, null, null, 'ช 2 คนไม่รวมหัวหน้า (ปรับจากประชุม กกบ.กลุ่มการ 24.1.68) · ไม่ลดคน'),
            array('TEA_EMS', $er, 'TEA+EMS', null, 1, null, null, 0, null, null, '1 คน วันทำการ · ไม่ลดคน'),
            array('ADMIT', $er, 'ศูนย์ admit', null, 1, null, null, 0, null, null, '1 คน วันทำการ · ไม่ลดคน'),
            array('ICU1', $icu, 'งานห้องผู้ป่วยหนัก 1', '1 ต่อ 2', 5, 5, 5, null, null, null, 'ไม่ลดคน'),
            array('ICU2', $icu, 'งานห้องผู้ป่วยหนัก 2', '1 ต่อ 2', 5, 5, 5, null, null, null, 'ไม่ลดคน'),
            array('ICU3', $icu, 'งานห้องผู้ป่วยหนัก 3', '1 ต่อ 2', 4, 4, 4, null, null, null, 'ไม่ลดคน'),
            array('NICU', $icu, 'งานทารกแรกเกิดวิกฤตป่วย', '1 ต่อ 2', 6, 6, 6, null, null, null, 'ไม่ลดคน'),
            array('ICU4', $icu, 'งานห้องผู้ป่วยหนัก 4', '1 ต่อ 2', 4, 4, 4, null, null, null, 'ไม่ลดคน'),
            array('LR', $lr, 'งานห้องคลอด', '2 ต่อ 1', 4, 4, 4, null, null, null, 'ไม่ลดคน'),
            array('MED_M', $med, 'หอผู้ป่วยอายุรกรรมชาย', '1 ต่อ 4', 7, 7, 7, null, null, null, 'ผู้ป่วยตามเกณฑ์ 28 · ลดเมื่อน้อยกว่า 26 คน · เสริมเมื่อ 44'),
            array('MED_F', $med, 'หอผู้ป่วยอายุรกรรมหญิง', '1 ต่อ 4', 7, 7, 7, null, null, null, 'ผู้ป่วยตามเกณฑ์ 28 · ลดเมื่อน้อยกว่า 26 คน · เสริมเมื่อ 44'),
            array('MED_MIX', $med, 'หอผู้ป่วยอายุรกรรมรวม', '1 ต่อ 4', 7, 7, 7, null, null, null, 'ผู้ป่วยตามเกณฑ์ 28 · ลดเมื่อน้อยกว่า 26 คน · เสริมเมื่อ 44'),
            array('MED_ER4', $med, 'หอผู้ป่วยอายุรกรรม ER ชั้น 4', '1 ต่อ 4', 4, 4, 4, null, null, null, 'ผู้ป่วยตามเกณฑ์ 16 · ลดเมื่อน้อยกว่า 14 คน · เสริมเมื่อ 26'),
            array('VIP4', $med, 'หอผู้ป่วยพิเศษ 4', '1 ต่อ 4', 2, 2, 2, null, null, null, 'ผู้ป่วยตามเกณฑ์ 8 · ไม่ลดคน · ไม่มีเสริม'),
            array('VIP5', $med, 'หอผู้ป่วยพิเศษ 5', '1 ต่อ 4', 2, 2, 2, null, null, null, 'ผู้ป่วยตามเกณฑ์ 8 · ไม่ลดคน · เสริมเมื่อ 14'),
            array('MONK4', $med, 'หอผู้ป่วยพิเศษสงฆ์อาพาธชั้น 4', '1 ต่อ 4', 2, 2, 2, null, null, null, 'ผู้ป่วยตามเกณฑ์ 8 · ไม่ลดคน · ไม่มีเสริม'),
            array('SUR_TR', $sur, 'หอผู้ป่วยศัลยกรรมอุบัติเหตุ', '1 ต่อ 4', 3, 3, 3, null, null, null, 'ผู้ป่วยตามเกณฑ์ 12 · ลดเมื่อน้อยกว่า 10 คน · เสริมเมื่อ 20'),
            array('SUR_M', $sur, 'หอผู้ป่วยศัลยกรรมชาย', '1 ต่อ 5', 5, 5, 5, null, null, null, 'ผู้ป่วยตามเกณฑ์ 25 · ลดเมื่อน้อยกว่า 22 คน · เสริมเมื่อ 37'),
            array('SUR_F', $sur, 'หอผู้ป่วยศัลยกรรมหญิง', '1 ต่อ 5', 4, 4, 4, null, null, null, 'ผู้ป่วยตามเกณฑ์ 20 · ลดเมื่อน้อยกว่า 17 คน · เสริมเมื่อ 30'),
            array('MONK1', $sur, 'หอผู้ป่วยพิเศษสงฆ์อาพาธชั้น 1', '1 ต่อ 5', 2, 2, 2, null, null, null, 'ผู้ป่วยตามเกณฑ์ 10 · ไม่ลดคน · ไม่มีเสริม'),
            array('MONK2', $sur, 'หอผู้ป่วยพิเศษสงฆ์อาพาธชั้น 2', '1 ต่อ 5', 2, 2, 2, null, null, null, 'ผู้ป่วยตามเกณฑ์ 10 · ไม่ลดคน · ไม่มีเสริม'),
            array('OBGYN', $ob, 'หอผู้ป่วยสูตินรีเวชกรรม', '1 ต่อ 6', 4, 3, 3, 3, null, null, 'เอกสาร: ช 4(3)/ บ 3/ ด 3 · ผู้ป่วยตามเกณฑ์ 18 · ลดเมื่อน้อยกว่า 14 คน · เสริมเมื่อ 26 (ช วันทำการ 34)'),
            array('MONK3', $ob, 'หอผู้ป่วยพิเศษสงฆ์อาพาธชั้น 3', '1 ต่อ 6', 2, 2, 2, null, null, null, 'ผู้ป่วยตามเกณฑ์ 12 · ไม่ลดคน · ไม่มีเสริม'),
            array('PSY', $psy, 'หอผู้ป่วยจิตเวช', '1 ต่อ 4', 2, 2, 2, null, null, null, 'ผู้ป่วยตามเกณฑ์ 8 · ไม่ลดคน · เสริมเมื่อ 14'),
            array('PED', $ped, 'หอผู้ป่วยกุมารเวชกรรม', '1 ต่อ 4', 4, 4, 4, null, null, null, 'ผู้ป่วยตามเกณฑ์ 16 · ลดเมื่อน้อยกว่า 14 คน · เสริมเมื่อ 26'),
            array('SNB', $ped, 'หอผู้ป่วยทารกแรกเกิดป่วย', '1 ต่อ 4', 3, 3, 3, null, null, null, 'ผู้ป่วยตามเกณฑ์ 12 · ลดเมื่อน้อยกว่า 10 คน · เสริมเมื่อ 20'),
            array('ORTHO', $ort, 'หอผู้ป่วยศัลยกรรมกระดูก', '1 ต่อ 5', 4, 4, 4, null, null, null, 'ผู้ป่วยตามเกณฑ์ 20 · ลดเมื่อน้อยกว่า 17 คน · เสริมเมื่อ 30'),
            array('ORTHO_VIP', $ort, 'หอผู้ป่วยพิเศษศัลยกรรมกระดูก', '1 ต่อ 5', 2, 2, 2, null, null, null, 'ผู้ป่วยตามเกณฑ์ 10 · ไม่ลดคน · ไม่มีเสริม'),
            array('ENT', $ent, 'หอผู้ป่วยโสตศอนาสิกและจักษุ', '1 ต่อ 5', 3, 2, 2, 2, null, null, 'เอกสาร: ช 3(2)/ บ 2/ ด 2 · ผู้ป่วยตามเกณฑ์ 10 · ไม่ลดคน · เสริมเมื่อ 16 (ช วันทำการ 23)'),
        );
    }

    private function seedUnits() {
        $i = 0;
        foreach (self::seedData() as $r) {
            $i += 10;
            $this->db->insert('flood_mp_unit', array(
                'unit_code' => $r[0], 'group_name' => $r[1], 'unit_name' => $r[2], 'sort_order' => $i, 'std_ratio' => $r[3],
                'req_m' => $r[4], 'req_a' => $r[5], 'req_n' => $r[6], 'hol_m' => $r[7], 'hol_a' => $r[8], 'hol_n' => $r[9],
                'note' => $r[10], 'is_active' => 1,
            ));
        }
    }

    /* ==================== อ่านข้อมูล ==================== */

    public function units($activeOnly = true) {
        return $this->db->select('SELECT * FROM flood_mp_unit' . ($activeOnly ? ' WHERE is_active = 1' : '')
            . ' ORDER BY sort_order, unit_id');
    }

    public function getUnit($id) {
        return $this->db->selectOne('SELECT * FROM flood_mp_unit WHERE unit_id = :id', array(':id' => (int) $id));
    }

    public function holidays($fromDate = null) {
        return $this->db->select('SELECT hdate, name FROM flood_mp_holiday'
            . ($fromDate ? ' WHERE hdate >= :d' : '') . ' ORDER BY hdate', $fromDate ? array(':d' => $fromDate) : array());
    }

    /** วันหยุดหรือไม่ + ชื่อวันหยุด */
    public function dayInfo($date) {
        $w = (int) date('w', strtotime($date));
        $name = $this->db->selectValue('SELECT name FROM flood_mp_holiday WHERE hdate = :d', array(':d' => $date));
        if ($name) {
            return array('holiday' => true, 'name' => (string) $name);
        }
        if ($w === 0 || $w === 6) {
            return array('holiday' => true, 'name' => $w === 0 ? 'วันอาทิตย์' : 'วันเสาร์');
        }
        return array('holiday' => false, 'name' => 'วันทำการ');
    }

    /** กรอบของหน่วยงานในเวรนั้น (null = ไม่มีเวรนี้) */
    public static function required($unit, $shift, $holiday) {
        $k = strtolower($shift);
        $wd = $unit['req_' . $k];
        if ($wd === null) {
            return null;
        }
        if ($holiday && $unit['hol_' . $k] !== null) {
            return (int) $unit['hol_' . $k];
        }
        return (int) $wd;
    }

    /**
     * ตารางเทียบกรอบของวันหนึ่ง: [unit_id][shift] => {req, actual, src, note, checkin, other, status, diff}
     * actual = จำนวนที่กรอกเอง (ถ้ามี) ไม่เช่นนั้น = จำนวนพยาบาลวิชาชีพ (RN) ที่ลงเวลา ไม่นับคนซ้ำ
     */
    public function matrix($date, $units, $holiday) {
        $chk = array();
        $rows = $this->db->select(
            "SELECT unit_code, shift,
                    COUNT(DISTINCT CASE WHEN staff_type = 'RN' THEN emp_code END) AS rn,
                    COUNT(DISTINCT CASE WHEN staff_type <> 'RN' THEN emp_code END) AS other,
                    MAX(created_at) AS last_at
             FROM flood_mp_checkin WHERE work_date = :d GROUP BY unit_code, shift", array(':d' => $date));
        foreach ($rows as $r) {
            $chk[strtoupper($r['unit_code'])][$r['shift']] = $r;
        }
        $man = array();
        foreach ($this->db->select(
            'SELECT c.*, u.name AS by_name FROM flood_mp_count c LEFT JOIN flood_user u ON u.user_id = c.updated_by WHERE c.work_date = :d',
            array(':d' => $date)) as $r) {
            $man[(int) $r['unit_id']][$r['shift']] = $r;
        }
        $out = array();
        foreach ($units as $u) {
            $id = (int) $u['unit_id'];
            $code = strtoupper($u['unit_code']);
            foreach (self::SHIFTS as $s => $meta) {
                $req = self::required($u, $s, $holiday);
                $c = isset($chk[$code][$s]) ? $chk[$code][$s] : null;
                $m = isset($man[$id][$s]) ? $man[$id][$s] : null;
                $cell = array(
                    'req' => $req,
                    'checkin' => $c ? (int) $c['rn'] : null,
                    'other' => $c ? (int) $c['other'] : 0,
                    'actual' => null, 'src' => '', 'note' => '', 'by' => '', 'at' => '',
                );
                if ($m) {
                    $cell['actual'] = (int) $m['actual'];
                    $cell['src'] = 'manual';
                    $cell['note'] = (string) $m['note'];
                    $cell['by'] = (string) $m['by_name'];
                    $cell['at'] = (string) $m['updated_at'];
                } elseif ($c) {
                    $cell['actual'] = (int) $c['rn'];
                    $cell['src'] = 'checkin';
                    $cell['at'] = (string) $c['last_at'];
                }
                $cell['status'] = self::status($cell, $date, $s);
                $cell['diff'] = ($req !== null && $cell['actual'] !== null) ? $cell['actual'] - $req : null;
                $out[$id][$s] = $cell;
            }
        }
        return $out;
    }

    /** none = ไม่มีเวร · wait = ยังไม่ถึงเวร · nodata = เวรเริ่มแล้วแต่ยังไม่มีข้อมูล · short / ok / over */
    public static function status($cell, $date, $shift) {
        if ($cell['req'] === null || ($cell['req'] === 0 && $cell['actual'] === null)) {
            return 'none';
        }
        if ($cell['actual'] === null) {
            return self::shiftStarted($date, $shift) ? 'nodata' : 'wait';
        }
        if ($cell['actual'] < $cell['req']) {
            return 'short';
        }
        return $cell['actual'] > $cell['req'] ? 'over' : 'ok';
    }

    /** สรุปต่อเวร: จำนวนหน่วยที่ครบ/ขาด/เกิน/รอข้อมูล + ผลรวมกรอบ/ที่มาจริง */
    public static function summary($matrix) {
        $sum = array();
        foreach (self::SHIFTS as $s => $meta) {
            $sum[$s] = array('ok' => 0, 'short' => 0, 'over' => 0, 'nodata' => 0, 'wait' => 0, 'req' => 0, 'actual' => 0, 'missing' => 0);
        }
        foreach ($matrix as $cells) {
            foreach ($cells as $s => $c) {
                if ($c['status'] === 'none') {
                    continue;
                }
                $sum[$s][$c['status']]++;
                $sum[$s]['req'] += (int) $c['req'];
                if ($c['actual'] !== null) {
                    $sum[$s]['actual'] += $c['actual'];
                    if ($c['actual'] < $c['req']) {
                        $sum[$s]['missing'] += $c['req'] - $c['actual'];
                    }
                }
            }
        }
        return $sum;
    }

    /** รายชื่อผู้ลงเวลาในเวร (ไว้ดูตรวจสอบ) */
    public function checkins($unitCode, $date, $shift) {
        return $this->db->select(
            'SELECT emp_code, emp_name, staff_type, checkin_at, source FROM flood_mp_checkin
             WHERE unit_code = :u AND work_date = :d AND shift = :s ORDER BY staff_type, checkin_at, emp_name',
            array(':u' => $unitCode, ':d' => $date, ':s' => $shift));
    }

    /* ==================== บันทึก ==================== */

    /** กรอกจำนวนเอง — $actual = null ลบค่าที่กรอก (กลับไปใช้ข้อมูลลงเวลา) */
    public function saveCount($unitId, $date, $shift, $actual, $note, $userId) {
        $w = 'unit_id = :w_u AND work_date = :w_d AND shift = :w_s';
        $wp = array(':w_u' => (int) $unitId, ':w_d' => $date, ':w_s' => $shift);
        $has = $this->db->selectValue('SELECT COUNT(*) FROM flood_mp_count WHERE ' . $w, $wp);
        if ($actual === null) {
            if ($has) {
                $this->db->delete('flood_mp_count', $w, 1, $wp);
            }
            return;
        }
        $data = array('actual' => (int) $actual, 'note' => $note !== '' ? $note : null,
            'updated_by' => $userId ? (int) $userId : null, 'updated_at' => date('Y-m-d H:i:s'));
        if ($has) {
            $this->db->update('flood_mp_count', $data, $w, $wp);
        } else {
            $this->db->insert('flood_mp_count', array_merge($data, array('unit_id' => (int) $unitId, 'work_date' => $date, 'shift' => $shift)));
        }
    }

    public function codeTaken($code, $exceptId) {
        return (int) $this->db->selectValue('SELECT COUNT(*) FROM flood_mp_unit WHERE unit_code = :c AND unit_id <> :id',
            array(':c' => $code, ':id' => (int) $exceptId)) > 0;
    }

    public function saveUnit($id, $data, $userId) {
        $data['updated_by'] = $userId ? (int) $userId : null;
        $data['updated_at'] = date('Y-m-d H:i:s');
        if ($id > 0) {
            $this->db->update('flood_mp_unit', $data, 'unit_id = :w_id', array(':w_id' => (int) $id));
            return (int) $id;
        }
        if (!isset($data['sort_order'])) {
            $data['sort_order'] = (int) $this->db->selectValue('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM flood_mp_unit');
        }
        return $this->db->insert('flood_mp_unit', $data);
    }

    public function addHoliday($date, $name) {
        if ($this->db->selectValue('SELECT COUNT(*) FROM flood_mp_holiday WHERE hdate = :d', array(':d' => $date))) {
            $this->db->update('flood_mp_holiday', array('name' => $name), 'hdate = :w_d', array(':w_d' => $date));
        } else {
            $this->db->insert('flood_mp_holiday', array('hdate' => $date, 'name' => $name));
        }
    }

    public function deleteHoliday($date) {
        $this->db->delete('flood_mp_holiday', 'hdate = :w_d', 1, array(':w_d' => $date));
    }

    /* ==================== ดึงข้อมูลลงเวลาจาก Flood2026-site-api ==================== */

    public static function syncMinutes() {
        return defined('MP_SYNC_MINUTES') ? max(1, (int) MP_SYNC_MINUTES) : 5;
    }

    public static function rnRegex() {
        return defined('MP_RN_REGEX') && MP_RN_REGEX !== '' ? MP_RN_REGEX : 'พยาบาลวิชาชีพ';
    }

    public function lastSync($date) {
        try {
            return $this->db->selectOne('SELECT * FROM flood_mp_sync WHERE sync_date = :d', array(':d' => $date)) ?: null;
        } catch (Exception $e) {
            return null;
        }
    }

    /** ซิงก์อัตโนมัติเมื่อเปิดหน้า — เฉพาะวันนี้/เมื่อวาน และห่างจากครั้งก่อนเกิน MP_SYNC_MINUTES */
    public function needSync($date) {
        if ($date > date('Y-m-d') || $date < date('Y-m-d', strtotime('-1 day'))) {
            return false;
        }
        $l = $this->lastSync($date);
        return !$l || strtotime($l['synced_at']) < time() - self::syncMinutes() * 60;
    }

    /** ตัวจับคู่หน่วยงาน hosoffice → unit_code (ชื่อ/รหัสใน hr_depts หรือชื่อตรงกับชื่อหน่วย) */
    private function deptIndex() {
        $idx = array();
        foreach ($this->units() as $u) {
            $keys = array_filter(array_map('trim', explode(',', (string) $u['hr_depts'])), 'strlen');
            if (!$keys) {
                $keys = array($u['unit_name']);
            }
            foreach ($keys as $k) {
                $idx[self::deptKey($k)] = strtoupper($u['unit_code']);
            }
        }
        return $idx;
    }

    public static function deptKey($s) {
        return mb_strtolower(preg_replace('/\s+/u', '', (string) $s));
    }

    /**
     * ดึงการสแกนเข้าเวรของวัน $date จาก API → flood_mp_checkin (source = hosoffice)
     * @return array {ok, msg, fetched, mapped, unmapped}
     */
    public function syncFromApi($date) {
        require_once 'models/site_api_model.php';
        $now = date('Y-m-d H:i:s');
        $r = FloodSiteApi::call('v1/attendance', array('date' => $date));
        if (empty($r['ok'])) {
            $err = mb_substr(isset($r['error']) ? (string) $r['error'] : 'ดึงข้อมูลไม่สำเร็จ', 0, 500);
            $this->saveSync($date, $now, 0, 0, array(), $err);
            return array('ok' => false, 'msg' => $err);
        }
        $idx = $this->deptIndex();
        $re = '/' . str_replace('/', '\/', self::rnRegex()) . '/u';
        $fetched = 0;
        $mapped = 0;
        $unmapped = array();
        $sql = "INSERT INTO flood_mp_checkin (unit_code, work_date, shift, emp_code, emp_name, staff_type, checkin_at, source)
                VALUES (:u, :d, :s, :e, :n, :t, :c, 'hosoffice')
                ON DUPLICATE KEY UPDATE emp_name = VALUES(emp_name), staff_type = VALUES(staff_type),
                    checkin_at = LEAST(COALESCE(checkin_at, VALUES(checkin_at)), VALUES(checkin_at))";
        $st = $this->db->prepare($sql);
        $this->db->beginTransaction();
        try {
            // สร้างใหม่ทั้งวันทุกครั้ง — แก้การจับคู่หน่วยงานแล้วซิงก์ซ้ำ ข้อมูลจะย้ายไปหน่วยที่ถูกต้อง
            $this->db->prepare("DELETE FROM flood_mp_checkin WHERE work_date = ? AND source = 'hosoffice'")->execute(array($date));
            foreach ((array) $r['rows'] as $a) {
                if (!is_array($a) || !isset($a['work_date'], $a['shift'], $a['emp_id']) || $a['work_date'] !== $date
                    || !isset(self::SHIFTS[$a['shift']])) {
                    continue;
                }
                $fetched++;
                $unit = null;
                foreach (array('dept_id', 'dept_name') as $k) {
                    $key = self::deptKey(isset($a[$k]) ? $a[$k] : '');
                    if ($key !== '' && isset($idx[$key])) {
                        $unit = $idx[$key];
                        break;
                    }
                }
                if ($unit === null) {
                    $dn = trim((string) (isset($a['dept_name']) && $a['dept_name'] !== '' ? $a['dept_name']
                        : (!empty($a['matched']) ? '(ไม่ระบุหน่วยงาน)' : '(ไม่พบใน hosoffice)')));
                    $unmapped[$dn] = (isset($unmapped[$dn]) ? $unmapped[$dn] : 0) + 1;
                    continue;
                }
                $mapped++;
                $pos = (string) (isset($a['position_name']) ? $a['position_name'] : '');
                $st->execute(array(
                    ':u' => $unit, ':d' => $date, ':s' => $a['shift'], ':e' => mb_substr((string) $a['emp_id'], 0, 30),
                    ':n' => mb_substr((string) (isset($a['name']) ? $a['name'] : ''), 0, 150),
                    ':t' => @preg_match($re, $pos) ? 'RN' : 'OTHER',
                    ':c' => isset($a['checkin_at']) && $a['checkin_at'] ? substr((string) $a['checkin_at'], 0, 19) : null,
                ));
            }
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            $this->saveSync($date, $now, $fetched, 0, array(), 'บันทึกไม่สำเร็จ: ' . mb_substr($e->getMessage(), 0, 200));
            return array('ok' => false, 'msg' => 'บันทึกข้อมูลลงเวลาไม่สำเร็จ');
        }
        arsort($unmapped);
        $this->saveSync($date, $now, $fetched, $mapped, $unmapped, null);
        return array('ok' => true, 'fetched' => $fetched, 'mapped' => $mapped, 'unmapped' => $unmapped,
            'msg' => 'ดึงข้อมูลลงเวลาแล้ว: ' . $fetched . ' รายการ · เข้าหน่วยงาน ' . $mapped
                . ($unmapped ? ' · หน่วยงานยังไม่จับคู่ ' . array_sum($unmapped) . ' คน' : ''));
    }

    private function saveSync($date, $at, $fetched, $mapped, $unmapped, $error) {
        try {
            $this->db->prepare('REPLACE INTO flood_mp_sync (sync_date, synced_at, fetched, mapped, unmapped, error) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute(array($date, $at, (int) $fetched, (int) $mapped,
                    $unmapped ? json_encode($unmapped, JSON_UNESCAPED_UNICODE) : null, $error));
        } catch (Exception $e) {
            // ไม่ให้เรื่องบันทึกสถานะขวางการแสดงผล
        }
    }

    /** หน่วยงาน hosoffice ที่ยังไม่จับคู่ จากการซิงก์ 7 วันล่าสุด (สำหรับหน้าตั้งค่า) */
    public function recentUnmapped() {
        $out = array();
        try {
            foreach ($this->db->select('SELECT unmapped FROM flood_mp_sync WHERE sync_date >= :d AND unmapped IS NOT NULL',
                array(':d' => date('Y-m-d', strtotime('-7 days')))) as $r) {
                foreach ((array) json_decode((string) $r['unmapped'], true) as $k => $v) {
                    $out[$k] = max(isset($out[$k]) ? $out[$k] : 0, (int) $v);
                }
            }
        } catch (Exception $e) {
        }
        arsort($out);
        return $out;
    }

}
