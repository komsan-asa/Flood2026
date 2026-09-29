<?php

/**
 * สาธารณูปโภคโรงพยาบาล (หน้า sat/utility) — นำเข้าจาก Google Sheet งานช่าง 4 แท็บ
 *   ระดับถังพักน้ำ        → flood_util_water      (ระดับถังล่าง/บน รายอาคาร วันละ 3 รอบ)
 *   ระดับถังน้ำมันสำรอง   → flood_util_fuel       (น้ำมันเครื่องกำเนิดไฟฟ้า/ถังสำรอง: ความจุ + คงเหลือรายรอบ)
 *   ระดับออกซิเจนเหลว     → flood_util_oxygen     (ปริมาณ ลบ.ม. / ใช้ไป / %)
 *   เติมน้ำอาคาร          → flood_util_delivery   (รถน้ำแต่ละเที่ยว: เวลาเข้า-ออก อาคาร ลิตร หน่วยรถ)
 *
 * ชีตเป็นบล็อกรายวัน "วันที่ 27/09/69" + หัวตาราง + แถวข้อมูล (มีเซลล์รวม) — ตัวอ่านหาตำแหน่งเองจากข้อความ ไม่ยึดเลขแถว
 * นำเข้าซ้ำได้: วันที่ที่มีในชีตรอบนี้ ลบของเดิมของวันนั้นแล้วใส่ใหม่ทั้งหมด (ชีตคือข้อมูลจริง) · วันที่ที่ไม่มีในชีต ไม่แตะ
 * ตาราง: ระบบสร้างเองครั้งแรก หรือ sql/21_flood_utility.sql
 */
class Utility_Model extends Model {

    const DEFAULT_SHEET = 'https://docs.google.com/spreadsheets/d/1_clwyvlmIEspptE5HVHvfxe7e7gR3z1ITxBuRI2Ye6I/edit';

    public static function kindNames() {
        return array(
            'water' => 'ระดับถังพักน้ำ',
            'fuel' => 'ระดับถังน้ำมันสำรอง',
            'oxygen' => 'ระดับออกซิเจนเหลว',
            'delivery' => 'เติมน้ำอาคาร',
        );
    }

    /** ชนิดแท็บจากชื่อแท็บ (ชื่อเปลี่ยนเล็กน้อยได้) */
    public static function kindOfTab($name) {
        $n = preg_replace('/\s+/u', '', (string) $name);
        if (mb_strpos($n, 'ออกซิเจน') !== false || stripos($n, 'o2') !== false) {
            return 'oxygen';
        }
        if (mb_strpos($n, 'น้ำมัน') !== false) {
            return 'fuel';
        }
        if (mb_strpos($n, 'เติมน้ำ') !== false || mb_strpos($n, 'รถน้ำ') !== false) {
            return 'delivery';
        }
        if (mb_strpos($n, 'ถังพักน้ำ') !== false || mb_strpos($n, 'ระดับน้ำ') !== false) {
            return 'water';
        }
        return null;
    }

    /* ==================== ตาราง ==================== */

    public static function schemaSql() {
        $t = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        return array(
            "CREATE TABLE IF NOT EXISTS `flood_util_water` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `log_date` DATE NOT NULL,
  `slot` CHAR(5) NOT NULL COMMENT 'HH:MM รอบที่วัด',
  `building` VARCHAR(100) NOT NULL,
  `lower_cm` DECIMAL(7,1) DEFAULT NULL COMMENT 'ระดับถังล่าง (ซม.)',
  `lower_full_cm` DECIMAL(7,1) DEFAULT NULL COMMENT 'ถังล่างเต็ม (ซม.)',
  `lower_pct` DECIMAL(5,1) DEFAULT NULL,
  `lower_empty` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = ชีตเขียน หมดถัง',
  `upper_cm` DECIMAL(7,1) DEFAULT NULL COMMENT 'ระดับถังบน (ซม.)',
  `upper_full_cm` DECIMAL(7,1) DEFAULT NULL,
  `upper_pct` DECIMAL(5,1) DEFAULT NULL,
  `upper_empty` TINYINT(1) NOT NULL DEFAULT 0,
  `tank_note` VARCHAR(200) DEFAULT NULL COMMENT 'เช่น ถังไฟเบอร์ 6 ถัง ขนาด 3500 ลิตร',
  `note` VARCHAR(255) DEFAULT NULL,
  `imported_by` INT UNSIGNED DEFAULT NULL,
  `imported_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_flood_util_water` (`log_date`, `slot`, `building`),
  KEY `idx_flood_util_water_b` (`building`, `log_date`)
)" . $t,
            "CREATE TABLE IF NOT EXISTS `flood_util_fuel` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `log_date` DATE NOT NULL,
  `slot` CHAR(5) NOT NULL COMMENT 'HH:MM (00:00 = มีแต่คอลัมน์ เหลือ ต้นวัน)',
  `item` VARCHAR(150) NOT NULL COMMENT 'เครื่องกำเนิดไฟฟ้า / ถังสำรอง',
  `capacity_l` DECIMAL(9,1) DEFAULT NULL,
  `remain_start_l` DECIMAL(9,1) DEFAULT NULL COMMENT 'คอลัมน์ เหลือ (ต้นวัน)',
  `remain_l` DECIMAL(9,1) DEFAULT NULL,
  `imported_by` INT UNSIGNED DEFAULT NULL,
  `imported_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_flood_util_fuel` (`log_date`, `slot`, `item`)
)" . $t,
            "CREATE TABLE IF NOT EXISTS `flood_util_oxygen` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `log_date` DATE NOT NULL,
  `slot` CHAR(5) NOT NULL,
  `volume_m3` DECIMAL(9,2) DEFAULT NULL COMMENT 'ปริมาณออกซิเจนเหลว ลบ.ม.',
  `used_m3` DECIMAL(9,2) DEFAULT NULL COMMENT 'ใช้ไป ลบ.ม. (ตามชีต)',
  `used_pct` DECIMAL(6,2) DEFAULT NULL,
  `imported_by` INT UNSIGNED DEFAULT NULL,
  `imported_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_flood_util_oxygen` (`log_date`, `slot`)
)" . $t,
            "CREATE TABLE IF NOT EXISTS `flood_util_delivery` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `log_date` DATE NOT NULL,
  `seq` INT NOT NULL COMMENT 'ลำดับคันในวันนั้น',
  `trip_label` VARCHAR(30) DEFAULT NULL COMMENT 'ข้อความในชีต เช่น คันที่ 3',
  `time_in` CHAR(5) DEFAULT NULL,
  `time_out` CHAR(5) DEFAULT NULL,
  `building` VARCHAR(100) NOT NULL,
  `liters` INT UNSIGNED NOT NULL,
  `vehicle` VARCHAR(150) DEFAULT NULL COMMENT 'หน่วยรถ เช่น รถทหาร / รถเทศบาล',
  `note` VARCHAR(255) DEFAULT NULL,
  `imported_by` INT UNSIGNED DEFAULT NULL,
  `imported_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_flood_util_delivery` (`log_date`, `seq`, `building`),
  KEY `idx_flood_util_delivery_b` (`building`, `log_date`)
)" . $t,
        );
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
            error_log('[flood] utility ensureTables: ' . $e->getMessage());
            $ready = false;
        }
        return $ready;
    }

    /* ==================== ตัวอ่านค่าในเซลล์ ==================== */

    /** "วันที่ 27/09/69" · "27/9/2569" · "27/9/2026" → Y-m-d */
    public static function parseDate($s) {
        if (!preg_match('/(\d{1,2})\s*[\/\-.]\s*(\d{1,2})\s*[\/\-.]\s*(\d{2,4})/u', (string) $s, $m)) {
            return null;
        }
        $d = (int) $m[1];
        $mo = (int) $m[2];
        $y = (int) $m[3];
        if ($y < 100) {
            $y += 2500;
        }
        if ($y > 2400) {
            $y -= 543;
        }
        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    /** "เวลา 09.00น" · "9.30 น." · "20.02น" · "14:00" → HH:MM */
    public static function parseTime($s) {
        if (!preg_match('/(\d{1,2})\s*[.:]\s*(\d{2})/u', (string) $s, $m)) {
            return null;
        }
        $h = (int) $m[1];
        $i = (int) $m[2];
        return ($h < 24 && $i < 60) ? sprintf('%02d:%02d', $h, $i) : null;
    }

    public static function num($s) {
        $s = str_replace(array(',', ' '), '', trim((string) $s));
        return is_numeric($s) ? (float) $s : null;
    }

    private static function cell($row, $i) {
        return isset($row[$i]) ? trim(preg_replace('/\s+/u', ' ', (string) $row[$i])) : '';
    }

    private static function isEmptyWord($s) {
        return mb_strpos((string) $s, 'หมด') !== false;
    }

    /** แถวหัวบล็อก "วันที่ …" (อยู่คอลัมน์แรก) */
    private static function blockDate($row) {
        $a = self::cell($row, 0);
        return mb_strpos($a, 'วันที่') !== false ? self::parseDate($a) : null;
    }

    /* ==================== ตัวอ่านแต่ละแท็บ ==================== */

    /**
     * ระดับถังพักน้ำ — หัวตาราง/แถวคั่นบอกขนาดถังของอาคารถัดไป ("ระดับถังล่าง เต็ม..150..Cm")
     * แถวข้อมูล: A อาคาร (เซลล์รวม 3 แถว) · B เวลา · C ถังล่าง ซม. · D ถังบน ซม. · E ถังล่าง % · F ถังบน % · G หมายเหตุ
     */
    public static function parseWater($grid) {
        $out = array();
        $date = null;
        $building = null;
        $blank = array('lower' => null, 'upper' => null, 'note' => null);
        $cap = $blank;
        $capNext = null;
        foreach ($grid as $row) {
            $d = self::blockDate($row);
            if ($d) {
                $date = $d;
                $building = null;
                $capNext = null;
                continue;
            }
            $a = self::cell($row, 0);
            $b = self::cell($row, 1);
            $c = self::cell($row, 2);
            $dd = self::cell($row, 3);
            if (mb_strpos($c . $dd, 'เต็ม') !== false || mb_strpos($dd, 'ขนาด') !== false) {
                $capNext = $blank;
                if (preg_match('/เต็ม[^\d]*(\d+(?:\.\d+)?)/u', $c, $m)) {
                    $capNext['lower'] = (float) $m[1];
                }
                if (preg_match('/เต็ม[^\d]*(\d+(?:\.\d+)?)/u', $dd, $m)) {
                    $capNext['upper'] = (float) $m[1];
                } elseif ($dd !== '' && mb_strpos($dd, 'เต็ม') === false) {
                    $capNext['note'] = mb_substr($dd, 0, 200);
                }
                continue;
            }
            $slot = self::parseTime($b);
            if (!$date || !$slot) {
                continue;
            }
            if ($a !== '' && $a !== 'รายการ') {
                $building = mb_substr($a, 0, 100);
                $cap = $capNext ? $capNext : $blank;
                $capNext = null;
            }
            if (!$building) {
                continue;
            }
            $e = self::cell($row, 4);
            $f = self::cell($row, 5);
            $notes = array();
            for ($i = 6; $i < count($row); $i++) {
                if (self::cell($row, $i) !== '') {
                    $notes[] = self::cell($row, $i);
                }
            }
            $r = array(
                'log_date' => $date, 'slot' => $slot, 'building' => $building,
                'lower_cm' => self::num($c), 'lower_full_cm' => $cap['lower'], 'lower_pct' => self::num($e),
                'lower_empty' => (self::isEmptyWord($c) || self::isEmptyWord($e)) ? 1 : 0,
                'upper_cm' => self::num($dd), 'upper_full_cm' => $cap['upper'], 'upper_pct' => self::num($f),
                'upper_empty' => (self::isEmptyWord($dd) || self::isEmptyWord($f)) ? 1 : 0,
                'tank_note' => $cap['note'],
                'note' => $notes ? mb_substr(implode(' · ', $notes), 0, 255) : null,
            );
            foreach (array('lower', 'upper') as $k) {
                // % เป็นสูตร ซม./ความจุ ในชีต — ช่อง ซม. ว่างแล้วได้ 0 = ยังไม่ได้วัด ไม่ใช่ถังว่าง
                if ($r[$k . '_pct'] !== null && (float) $r[$k . '_pct'] == 0 && $r[$k . '_cm'] === null && !$r[$k . '_empty']) {
                    $r[$k . '_pct'] = null;
                }
                if ($r[$k . '_pct'] === null && $r[$k . '_cm'] !== null && $r[$k . '_full_cm']) {
                    $r[$k . '_pct'] = $r[$k . '_cm'] * 100 / $r[$k . '_full_cm'];
                }
                if ($r[$k . '_empty']) {
                    $r[$k . '_pct'] = 0.0;
                }
                if ($r[$k . '_pct'] !== null) {
                    $r[$k . '_pct'] = round(max(0, min(999, $r[$k . '_pct'])), 1);
                }
            }
            if ($r['lower_cm'] === null && $r['upper_cm'] === null && $r['lower_pct'] === null && $r['upper_pct'] === null
                && !$r['lower_empty'] && !$r['upper_empty'] && $r['note'] === null) {
                continue;
            }
            $out[$date . '|' . $slot . '|' . $building] = $r;
        }
        return array_values($out);
    }

    /** ระดับถังน้ำมันสำรอง — หัวตาราง "ความจุ | เหลือ | 09.00น | 14.00น | 20.00น." · แถวรายการ: ชื่อ | ความจุ | เหลือ | คงเหลือรายรอบ */
    public static function parseFuel($grid) {
        $out = array();
        $date = null;
        $capCol = 1;
        $startCol = 2;
        $slots = array();
        foreach ($grid as $row) {
            $d = self::blockDate($row);
            if ($d) {
                $date = $d;
                $slots = array();
                continue;
            }
            $cells = array();
            foreach ($row as $i => $v) {
                $cells[$i] = self::cell($row, $i);
            }
            $ci = array_search('ความจุ', $cells, true);
            if ($ci !== false) {
                $capCol = $ci;
                $startCol = $ci + 1;
                $slots = array();
                foreach ($cells as $i => $v) {
                    if ($i > $startCol && ($t = self::parseTime($v))) {
                        $slots[$i] = $t;
                    }
                }
                continue;
            }
            $a = self::cell($row, 0);
            if (!$date || $a === '' || $a === 'รายการ' || mb_strpos($a, 'รวม') === 0) {
                continue;
            }
            $capacity = self::num(self::cell($row, $capCol));
            if ($capacity === null) {
                continue;
            }
            $start = self::num(self::cell($row, $startCol));
            $item = mb_substr($a, 0, 150);
            $any = false;
            foreach ($slots as $i => $slot) {
                $v = self::num(self::cell($row, $i));
                if ($v === null) {
                    continue;
                }
                $any = true;
                $out[$date . '|' . $slot . '|' . $item] = array('log_date' => $date, 'slot' => $slot, 'item' => $item,
                    'capacity_l' => $capacity, 'remain_start_l' => $start, 'remain_l' => $v);
            }
            if (!$any && $start !== null) {
                $out[$date . '|00:00|' . $item] = array('log_date' => $date, 'slot' => '00:00', 'item' => $item,
                    'capacity_l' => $capacity, 'remain_start_l' => $start, 'remain_l' => $start);
            }
        }
        return array_values($out);
    }

    /** ระดับออกซิเจนเหลว — แถว: เวลา | ปริมาณ ลบ.ม. | ใช้ไป ลบ.ม. | คิดเป็น % */
    public static function parseOxygen($grid) {
        $out = array();
        $date = null;
        foreach ($grid as $row) {
            $d = self::blockDate($row);
            if ($d) {
                $date = $d;
                continue;
            }
            $slot = self::parseTime(self::cell($row, 0));
            $vol = self::num(self::cell($row, 1));
            if (!$date || !$slot || $vol === null) {
                continue;
            }
            $pct = self::num(self::cell($row, 3));
            $out[$date . '|' . $slot] = array('log_date' => $date, 'slot' => $slot, 'volume_m3' => $vol,
                'used_m3' => self::num(self::cell($row, 2)), 'used_pct' => $pct !== null ? round($pct, 2) : null);
        }
        return array_values($out);
    }

    /**
     * เติมน้ำอาคาร — หัวตาราง "คันรถ | เวลาเข้า | เวลาออก | <ชื่ออาคาร…> | หมายเหตุ"
     * แถวละ 1 คัน: ลิตรอยู่ใต้ชื่ออาคารที่เติม · หมายเหตุ = หน่วยรถ · ช่องหลังหมายเหตุ = บันทึกเพิ่ม · แถว "รวม" ข้าม
     */
    public static function parseDelivery($grid) {
        $out = array();
        $date = null;
        $bcols = array();
        $noteCol = null;
        $inCol = 1;
        $outCol = 2;
        $autoSeq = 0;
        foreach ($grid as $row) {
            $d = self::blockDate($row);
            if ($d) {
                $date = $d;
                $autoSeq = 0;
                continue;
            }
            $a = self::cell($row, 0);
            if (mb_strpos($a, 'คันรถ') !== false) {
                $bcols = array();
                $noteCol = null;
                foreach ($row as $i => $v) {
                    $v = self::cell($row, $i);
                    if ($i === 0 || $v === '') {
                        continue;
                    }
                    if (mb_strpos($v, 'เข้า') !== false) {
                        $inCol = $i;
                    } elseif (mb_strpos($v, 'ออก') !== false) {
                        $outCol = $i;
                    } elseif (mb_strpos($v, 'หมายเหตุ') !== false) {
                        $noteCol = $i;
                    } elseif ($noteCol === null) {
                        $bcols[$i] = mb_substr($v, 0, 100);
                    }
                }
                continue;
            }
            if (!$date || !$bcols || $a === '' || mb_strpos($a, 'รวม') === 0 || $a === 'ลำดับ') {
                continue;
            }
            $autoSeq++;
            $seq = preg_match('/(\d+)/', $a, $m) ? (int) $m[1] : $autoSeq;
            $notes = array();
            if ($noteCol !== null) {
                for ($i = $noteCol + 1; $i < count($row); $i++) {
                    if (self::cell($row, $i) !== '') {
                        $notes[] = self::cell($row, $i);
                    }
                }
            }
            foreach ($bcols as $i => $bname) {
                $l = self::num(self::cell($row, $i));
                if ($l === null || $l <= 0) {
                    continue;
                }
                $key = $date . '|' . $seq . '|' . $bname;
                while (isset($out[$key])) {   // เลขคันซ้ำในวันเดียวกัน (พิมพ์ซ้ำ) — เลื่อนเลขให้ไม่ทับกัน
                    $seq += 1000;
                    $key = $date . '|' . $seq . '|' . $bname;
                }
                $out[$key] = array(
                    'log_date' => $date, 'seq' => $seq, 'trip_label' => is_numeric($a) ? 'คันที่ ' . (int) $a : mb_substr($a, 0, 30),
                    'time_in' => self::parseTime(self::cell($row, $inCol)), 'time_out' => self::parseTime(self::cell($row, $outCol)),
                    'building' => $bname, 'liters' => (int) round($l),
                    'vehicle' => ($noteCol !== null && self::cell($row, $noteCol) !== '') ? mb_substr(self::cell($row, $noteCol), 0, 150) : null,
                    'note' => $notes ? mb_substr(implode(' · ', $notes), 0, 255) : null,
                );
            }
        }
        return array_values($out);
    }

    public static function parse($kind, $grid) {
        switch ($kind) {
            case 'water':
                return self::parseWater($grid);
            case 'fuel':
                return self::parseFuel($grid);
            case 'oxygen':
                return self::parseOxygen($grid);
            case 'delivery':
                return self::parseDelivery($grid);
        }
        return array();
    }

    private static function table($kind) {
        $t = array('water' => 'flood_util_water', 'fuel' => 'flood_util_fuel', 'oxygen' => 'flood_util_oxygen', 'delivery' => 'flood_util_delivery');
        return $t[$kind];
    }

    /* ==================== นำเข้า ==================== */

    /**
     * $sheets = array(ชื่อแท็บ => grid (array ของแถว แต่ละแถวเป็น array ของข้อความ))
     * คืน array('ok'=>bool, 'msg'=>..., 'kinds'=>array(kind => array('tab','rows','dates')), 'skipped'=>array(ชื่อแท็บ))
     */
    public function import($sheets, $uid) {
        $parsed = array();
        $skipped = array();
        foreach ($sheets as $tab => $grid) {
            $kind = self::kindOfTab($tab);
            if (!$kind || !is_array($grid)) {
                $skipped[] = (string) $tab;
                continue;
            }
            $parsed[$kind] = array('tab' => (string) $tab, 'rows' => self::parse($kind, $grid));
        }
        if (!$parsed) {
            return array('ok' => false, 'msg' => 'ไม่พบแท็บที่รู้จัก (ระดับถังพักน้ำ / ระดับถังน้ำมันสำรอง / ระดับออกซิเจนเหลว / เติมน้ำอาคาร)');
        }
        $now = date('Y-m-d H:i:s');
        $result = array();
        // INSERT ตรง (ไม่ผ่าน Database::insert) — ข้อมูลนำเข้าเป็นร้อยแถว ไม่ต้องลง audit ทีละแถว
        // และ audit สร้างตารางเอง (DDL) ซึ่งทำให้ transaction ถูก commit กลางคัน
        $this->db->beginTransaction();
        try {
            foreach ($parsed as $kind => $p) {
                $table = self::table($kind);
                $dates = array();
                foreach ($p['rows'] as $r) {
                    $dates[$r['log_date']] = true;
                }
                $dates = array_keys($dates);
                sort($dates);
                if ($dates) {
                    $in = implode(',', array_fill(0, count($dates), '?'));
                    $this->db->prepare("DELETE FROM $table WHERE log_date IN ($in)")->execute($dates);
                }
                $sth = null;
                foreach ($p['rows'] as $r) {
                    $r['imported_by'] = $uid;
                    $r['imported_at'] = $now;
                    if (!$sth) {
                        $cols = array_keys($r);
                        $sth = $this->db->prepare("INSERT INTO $table (`" . implode('`, `', $cols) . "`) VALUES (:"
                            . implode(', :', $cols) . ')');
                    }
                    foreach ($r as $k => $v) {
                        $sth->bindValue(':' . $k, $v, $v === null ? PDO::PARAM_NULL : (is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR));
                    }
                    $sth->execute();
                }
                $result[$kind] = array('tab' => $p['tab'], 'rows' => count($p['rows']), 'dates' => $dates);
            }
            if ($this->db->inTransaction()) {
                $this->db->commit();
            }
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('[flood] utility import: ' . $e->getMessage());
            return array('ok' => false, 'msg' => 'บันทึกไม่สำเร็จ กรุณาลองใหม่ (' . mb_substr($e->getMessage(), 0, 150) . ')');
        }
        $names = self::kindNames();
        $parts = array();
        foreach ($result as $kind => $r) {
            $parts[] = $names[$kind] . ' ' . $r['rows'] . ' แถว' . ($r['dates'] ? ' (' . count($r['dates']) . ' วัน)' : '');
        }
        return array('ok' => true, 'msg' => 'นำเข้าแล้ว: ' . implode(' · ', $parts)
            . ($skipped ? ' · ข้ามแท็บ ' . implode(', ', $skipped) : ''), 'kinds' => $result, 'skipped' => $skipped);
    }

    /* ==================== แสดงผล ==================== */

    public function summary() {
        $s = array('oxygen' => null, 'fuel' => null, 'water' => null, 'delivery' => null, 'imported_at' => null);
        $s['imported_at'] = $this->db->selectValue(
            'SELECT MAX(t) FROM (SELECT MAX(imported_at) t FROM flood_util_water UNION ALL SELECT MAX(imported_at) FROM flood_util_fuel
             UNION ALL SELECT MAX(imported_at) FROM flood_util_oxygen UNION ALL SELECT MAX(imported_at) FROM flood_util_delivery) x');

        // ออกซิเจนเหลว: ค่าล่าสุด + อัตราใช้ตั้งแต่เติมครั้งล่าสุด → คาดว่าพอใช้อีกกี่วัน
        $o2 = $this->db->select('SELECT log_date, slot, volume_m3, used_m3, used_pct FROM flood_util_oxygen ORDER BY log_date, slot');
        if ($o2) {
            $last = end($o2);
            $seg = array($last);
            for ($i = count($o2) - 2; $i >= 0; $i--) {
                if ((float) $o2[$i]['volume_m3'] < (float) $seg[0]['volume_m3']) {
                    break;   // ค่าก่อนหน้าต่ำกว่า = มีการเติมถังระหว่างนั้น
                }
                array_unshift($seg, $o2[$i]);
            }
            $first = $seg[0];
            $hours = (strtotime($last['log_date'] . ' ' . $last['slot']) - strtotime($first['log_date'] . ' ' . $first['slot'])) / 3600;
            $rate = null;
            if ($hours >= 6 && (float) $first['volume_m3'] > (float) $last['volume_m3']) {
                $rate = ((float) $first['volume_m3'] - (float) $last['volume_m3']) / $hours * 24;
            }
            $s['oxygen'] = array('last' => $last, 'rate_day' => $rate,
                'days_left' => $rate ? (float) $last['volume_m3'] / $rate : null, 'series' => $o2);
        }

        // น้ำมันสำรอง: รอบล่าสุดของแต่ละรายการในวันล่าสุด
        $fd = $this->db->selectValue('SELECT MAX(log_date) FROM flood_util_fuel');
        if ($fd) {
            $items = $this->db->select(
                'SELECT f.* FROM flood_util_fuel f
                 JOIN (SELECT item, MAX(slot) s FROM flood_util_fuel WHERE log_date = :d GROUP BY item) x ON x.item = f.item AND x.s = f.slot
                 WHERE f.log_date = :d2 ORDER BY f.id', array(':d' => $fd, ':d2' => $fd));
            $cap = 0;
            $rem = 0;
            foreach ($items as $it) {
                $cap += (float) $it['capacity_l'];
                $rem += (float) $it['remain_l'];
            }
            $s['fuel'] = array('date' => $fd, 'items' => $items, 'capacity' => $cap, 'remain' => $rem,
                'pct' => $cap > 0 ? $rem * 100 / $cap : null);
        }

        // ถังพักน้ำ: ค่าล่าสุดที่มีตัวเลขของแต่ละอาคาร (ย้อนดู 1 วัน) + ตารางทั้งวันล่าสุด
        $wd = $this->db->selectValue('SELECT MAX(log_date) FROM flood_util_water');
        if ($wd) {
            $rows = $this->db->select('SELECT * FROM flood_util_water WHERE log_date >= :d ORDER BY log_date, slot, id',
                array(':d' => date('Y-m-d', strtotime($wd . ' -1 day'))));
            $by = array();
            foreach ($rows as $r) {
                if ($r['lower_pct'] === null && $r['upper_pct'] === null) {
                    continue;
                }
                $by[$r['building']] = $r;
            }
            $s['water'] = array('date' => $wd, 'buildings' => array_values($by),
                'day' => $this->db->select('SELECT * FROM flood_util_water WHERE log_date = :d ORDER BY id', array(':d' => $wd)));
        }

        // รถเติมน้ำ: ยอดรายวัน + รายการวันล่าสุด + ยอดรายอาคาร
        $days = $this->db->select(
            'SELECT log_date, COUNT(DISTINCT seq) trips, SUM(liters) liters FROM flood_util_delivery GROUP BY log_date ORDER BY log_date DESC LIMIT 14');
        if ($days) {
            $dd = $days[0]['log_date'];
            $s['delivery'] = array('days' => $days, 'date' => $dd,
                'trips' => $this->db->select('SELECT * FROM flood_util_delivery WHERE log_date = :d ORDER BY seq, id', array(':d' => $dd)),
                'buildings' => $this->db->select(
                    'SELECT building, COUNT(*) n, SUM(liters) liters FROM flood_util_delivery WHERE log_date = :d GROUP BY building ORDER BY liters DESC',
                    array(':d' => $dd)));
        }
        return $s;
    }

}
