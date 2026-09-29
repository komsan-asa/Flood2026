<?php

/**
 * Refer เข้าโรงพยาบาลช่วงอุทกภัย (หน้า sat/refer) — นำเข้าจาก Google Sheet "Refer ภาวะอุทกภัย 2569"
 * ตารางเดียว flood_refer_in: 1 แถว = ผู้ป่วย 1 ราย (ชีตเป็นทะเบียนสะสม)
 *
 * - จับคอลัมน์จากหัวคอลัมน์ (หาแถวหัวเองใน 10 แถวแรก) — เพิ่ม/สลับคอลัมน์ในชีตได้
 * - นำเข้าซ้ำ = แทนที่ทั้งตาราง (ชีตคือทะเบียนจริง แก้/ลบแถวในชีตแล้วระบบตามให้)
 * - มีชื่อผู้ป่วยและการวินิจฉัย → หน้าจอแสดงรายชื่อเฉพาะเจ้าหน้าที่ศูนย์/ผู้ดูแลระบบ ผู้บริหารเห็นตัวเลขรวม
 */
class Refer_Model extends Model {

    const DEFAULT_SHEET = 'https://docs.google.com/spreadsheets/d/1t4cmVff4c0zWvw5lHaGRKRTSY7QlCuj5aZwf3ZaY3BY/edit?gid=592288863';
    const DEFAULT_TAB = 'Refer ภาวะอุทกภัย ก.ย.69';

    public static function schemaSql() {
        return array("CREATE TABLE IF NOT EXISTS `flood_refer_in` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `row_no` INT DEFAULT NULL COMMENT 'ลำดับในชีต',
  `refer_date` DATE DEFAULT NULL COMMENT 'วันที่รับแจ้ง',
  `refer_time` CHAR(5) DEFAULT NULL COMMENT 'เวลา HH:MM',
  `patient_name` VARCHAR(150) DEFAULT NULL,
  `from_hosp` VARCHAR(150) DEFAULT NULL COMMENT 'รพ.ต้นทาง (รพช)',
  `dx` VARCHAR(255) DEFAULT NULL,
  `staff` VARCHAR(100) DEFAULT NULL COMMENT 'Staff ผู้รับ',
  `nationality` VARCHAR(50) DEFAULT NULL,
  `equipment` VARCHAR(150) DEFAULT NULL COMMENT 'อุปกรณ์ เช่น on ET tube / on O2',
  `dept` VARCHAR(100) DEFAULT NULL COMMENT 'แผนก',
  `refer_to` VARCHAR(100) DEFAULT NULL COMMENT 'Refer/Ward — จุดรับ',
  `pass_type` VARCHAR(30) DEFAULT NULL COMMENT 'Refer pass/Refer ER',
  `admit_ward` VARCHAR(100) DEFAULT NULL COMMENT 'Admit',
  `officer` VARCHAR(100) DEFAULT NULL COMMENT 'เจ้าหน้าที่ผู้บันทึก',
  `note` VARCHAR(255) DEFAULT NULL,
  `imported_by` INT UNSIGNED DEFAULT NULL,
  `imported_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_flood_refer_in_date` (`refer_date`, `refer_time`),
  KEY `idx_flood_refer_in_hosp` (`from_hosp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function ensureTables() {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        try {
            foreach (self::schemaSql() as $sql) {
                $this->db->exec($sql);
            }
            $ready = true;
        } catch (Exception $e) {
            error_log('[flood] refer ensureTables: ' . $e->getMessage());
            $ready = false;
        }
        return $ready;
    }

    /** หัวคอลัมน์ → ฟิลด์ (ตรวจตามลำดับ คำเฉพาะก่อน) */
    private static function columnRules() {
        return array(
            'pass_type' => '/refer\s*pass|pass\s*\/|refer\s*er/iu',
            'refer_to' => '/refer\s*\/\s*ward|^refer$|ส่งต่อไป|จุดรับ/iu',
            'row_no' => '/^ลำดับ|^no\.?$|^ที่$/iu',
            'refer_date' => '/วันที่/u',
            'refer_time' => '/เวลา/u',
            'patient_name' => '/ชื่อ/u',
            'from_hosp' => '/รพช|รพ\.?\s*ต้นทาง|^รพ|โรงพยาบาล|hospital/iu',
            'dx' => '/^dx|diag|วินิจฉัย/iu',
            'staff' => '/^staff|แพทย์/iu',
            'nationality' => '/สัญชาติ/u',
            'equipment' => '/อุปกรณ์/u',
            'dept' => '/แผนก/u',
            'admit_ward' => '/admit/iu',
            'officer' => '/เจ้าหน้าที่|ผู้บันทึก/u',
            'note' => '/หมายเหตุ/u',
        );
    }

    private static function cell($row, $i) {
        return isset($row[$i]) ? trim(preg_replace('/\s+/u', ' ', (string) $row[$i])) : '';
    }

    /** เลขวันที่ Excel (46292) · Date(2026,8,27) ของ gviz · 27/9/2569 · 2026-09-27 → Y-m-d */
    public static function parseDate($s) {
        $s = trim((string) $s);
        if ($s === '') {
            return null;
        }
        if (preg_match('/^Date\((\d{4}),\s*(\d{1,2}),\s*(\d{1,2})/', $s, $m)) {
            return checkdate((int) $m[2] + 1, (int) $m[3], (int) $m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2] + 1, $m[3]) : null;
        }
        if (is_numeric($s) && (float) $s > 30000 && (float) $s < 80000) {
            return date('Y-m-d', strtotime('1899-12-30 +' . (int) $s . ' days'));
        }
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m)) {
            $y = (int) $m[1] > 2400 ? (int) $m[1] - 543 : (int) $m[1];
            return checkdate((int) $m[2], (int) $m[3], $y) ? sprintf('%04d-%02d-%02d', $y, $m[2], $m[3]) : null;
        }
        if (preg_match('/(\d{1,2})\s*[\/\-.]\s*(\d{1,2})\s*[\/\-.]\s*(\d{2,4})/u', $s, $m)) {
            $y = (int) $m[3];
            if ($y < 100) {
                $y += 2500;
            }
            if ($y > 2400) {
                $y -= 543;
            }
            return checkdate((int) $m[2], (int) $m[1], $y) ? sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]) : null;
        }
        return null;
    }

    /** 1715 · 32 (= 00:32) · 17.15 · 17:15 น. · เศษวันของ Excel (0.72) → HH:MM */
    public static function parseTime($s) {
        $s = trim((string) $s);
        if ($s === '') {
            return null;
        }
        if (preg_match('/^Date\(\d+,\d+,\d+,(\d+),(\d+)/', $s, $m)) {
            return sprintf('%02d:%02d', $m[1], $m[2]);
        }
        if (preg_match('/(\d{1,2})\s*[.:]\s*(\d{2})/u', $s, $m) && !preg_match('/^\d+\.0+$/', $s)) {
            return ((int) $m[1] < 24 && (int) $m[2] < 60) ? sprintf('%02d:%02d', $m[1], $m[2]) : null;
        }
        if (is_numeric($s)) {
            $f = (float) $s;
            if ($f > 0 && $f < 1) {
                $min = (int) round($f * 1440);
                return sprintf('%02d:%02d', intdiv($min, 60) % 24, $min % 60);
            }
            $v = (int) round($f);
            $h = intdiv($v, 100);
            $i = $v % 100;
            return ($h < 24 && $i < 60) ? sprintf('%02d:%02d', $h, $i) : null;
        }
        return null;
    }

    /** grid → array(rows, map ฟิลด์=>หัวคอลัมน์) */
    public static function parse($grid) {
        $rules = self::columnRules();
        $hdr = -1;
        $map = array();
        foreach (array_slice($grid, 0, 10, true) as $ri => $row) {
            $m = array();
            foreach ($row as $ci => $v) {
                $h = self::cell($row, $ci);
                if ($h === '') {
                    continue;
                }
                foreach ($rules as $field => $re) {
                    if (!isset($m[$field]) && preg_match($re, $h)) {
                        $m[$field] = $ci;
                        break;
                    }
                }
            }
            if (isset($m['patient_name']) && isset($m['refer_date'])) {
                $hdr = $ri;
                $map = $m;
                break;
            }
        }
        if ($hdr < 0) {
            return array(null, array());
        }
        $out = array();
        foreach ($grid as $ri => $row) {
            if ($ri <= $hdr) {
                continue;
            }
            $get = function ($f, $max = 255) use ($row, $map) {
                if (!isset($map[$f])) {
                    return null;
                }
                $v = self::cell($row, $map[$f]);
                return $v === '' ? null : mb_substr($v, 0, $max);
            };
            $name = $get('patient_name', 150);
            $dx = $get('dx');
            if ($name === null && $dx === null) {
                continue;   // แถวที่มีแต่เลขลำดับ (เตรียมไว้กรอก)
            }
            $no = $get('row_no');
            $out[] = array(
                'row_no' => $no !== null && is_numeric($no) ? (int) $no : null,
                'refer_date' => self::parseDate($get('refer_date')),
                'refer_time' => self::parseTime($get('refer_time')),
                'patient_name' => $name,
                'from_hosp' => $get('from_hosp', 150),
                'dx' => $dx,
                'staff' => $get('staff', 100),
                'nationality' => $get('nationality', 50),
                'equipment' => $get('equipment', 150),
                'dept' => $get('dept', 100),
                'refer_to' => $get('refer_to', 100),
                'pass_type' => $get('pass_type', 30),
                'admit_ward' => $get('admit_ward', 100),
                'officer' => $get('officer', 100),
                'note' => $get('note'),
            );
        }
        $cols = array();
        foreach ($map as $f => $ci) {
            $cols[$f] = self::cell($grid[$hdr], $ci);
        }
        return array($out, $cols);
    }

    /** $sheets = array(ชื่อแท็บ => grid) — ใช้แท็บแรกที่หาหัวคอลัมน์เจอ · แทนที่ทั้งตาราง */
    public function import($sheets, $uid) {
        $rows = null;
        $tab = '';
        foreach ($sheets as $t => $grid) {
            if (!is_array($grid)) {
                continue;
            }
            list($r) = self::parse($grid);
            if ($r !== null) {
                $rows = $r;
                $tab = (string) $t;
                break;
            }
        }
        if ($rows === null) {
            return array('ok' => false, 'msg' => 'ไม่พบแถวหัวคอลัมน์ที่มี "วันที่" และ "ชื่อ" ในชีต');
        }
        $now = date('Y-m-d H:i:s');
        $this->db->beginTransaction();
        try {
            $this->db->exec('DELETE FROM flood_refer_in');
            $sth = null;
            foreach ($rows as $r) {
                $r['imported_by'] = $uid;
                $r['imported_at'] = $now;
                if (!$sth) {
                    $cols = array_keys($r);
                    $sth = $this->db->prepare('INSERT INTO flood_refer_in (`' . implode('`, `', $cols) . '`) VALUES (:' . implode(', :', $cols) . ')');
                }
                foreach ($r as $k => $v) {
                    $sth->bindValue(':' . $k, $v, $v === null ? PDO::PARAM_NULL : (is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR));
                }
                $sth->execute();
            }
            if ($this->db->inTransaction()) {
                $this->db->commit();
            }
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('[flood] refer import: ' . $e->getMessage());
            return array('ok' => false, 'msg' => 'บันทึกไม่สำเร็จ กรุณาลองใหม่ (' . mb_substr($e->getMessage(), 0, 150) . ')');
        }
        $noDate = count(array_filter($rows, function ($r) { return $r['refer_date'] === null; }));
        return array('ok' => true, 'msg' => 'นำเข้าแล้ว: Refer ' . count($rows) . ' ราย จากแท็บ ' . $tab
            . ($noDate ? ' (ไม่มีวันที่ ' . $noDate . ' ราย)' : ''));
    }

    public function summary() {
        $s = array();
        $s['imported_at'] = $this->db->selectValue('SELECT MAX(imported_at) FROM flood_refer_in');
        $s['total'] = (int) $this->db->selectValue('SELECT COUNT(*) FROM flood_refer_in');
        $s['today'] = (int) $this->db->selectValue('SELECT COUNT(*) FROM flood_refer_in WHERE refer_date = CURDATE()');
        $s['ett'] = (int) $this->db->selectValue(
            "SELECT COUNT(*) FROM flood_refer_in WHERE equipment LIKE '%ET%tube%' OR equipment LIKE '%ET-tube%' OR equipment LIKE '%ventilator%'");
        $s['o2'] = (int) $this->db->selectValue("SELECT COUNT(*) FROM flood_refer_in WHERE equipment LIKE '%O2%'");
        $group = function ($col, $limit = 10) {
            return $this->db->select("SELECT COALESCE(NULLIF($col, ''), 'ไม่ระบุ') AS name, COUNT(*) AS c FROM flood_refer_in
                GROUP BY name ORDER BY c DESC LIMIT " . (int) $limit);
        };
        $s['byDate'] = $this->db->select('SELECT refer_date AS d, COUNT(*) AS c FROM flood_refer_in GROUP BY refer_date ORDER BY refer_date DESC LIMIT 30');
        $s['byHosp'] = $group('from_hosp');
        $s['byDept'] = $group('dept');
        $s['byEquip'] = $group('equipment');
        $s['byAdmit'] = $group('admit_ward');
        $s['byPass'] = $group('pass_type');
        $s['rows'] = $this->db->select('SELECT * FROM flood_refer_in ORDER BY refer_date DESC, refer_time DESC, row_no DESC LIMIT 500');
        return $s;
    }

}
