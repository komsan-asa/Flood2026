<?php

/**
 * ฝนรายวันจากสถานีของกรมชลประทาน (https://swocrf.rid.go.th — ระบบคาดการณ์น้ำหลากด้วยปริมาณน้ำฝน)
 * ใช้กับการ์ด "ฝน" ในห้องสถานการณ์ SAT
 *
 * - เซิร์ฟเวอร์ของเราออกอินเทอร์เน็ตไม่ได้ และเว็บกรมชลประทานไม่เปิด CORS (เบราว์เซอร์ของเจ้าหน้าที่อ่านข้ามเว็บไม่ได้)
 *   → งานตามเวลาของ Claude (ทุก 6 ชม.) เปิดเว็บกรมชลประทานในแผงเบราว์เซอร์บนเครื่องผู้ดูแล อ่านตาราง แล้วส่งมาที่ rain/import
 *   ต้นทาง: POST https://swocrf.rid.go.th/req/data_tables.php  module=measure&mode=get&id=<รหัสสถานี>&fdate=Y-m-d&tdate=Y-m-d&type=custom
 * - ข้อมูลเป็นยอดฝนรายวัน (มม.) ไม่มีรายชั่วโมง/พยากรณ์ · แถวของวันนี้ = ยอดถึงเวลาที่ดึง (ยังไม่ครบวัน)
 * - หลังนำเข้า ระบบอัปเดตการ์ด "ฝน" ให้เอง (สีที่แนะนำ + สรุป) ยกเว้น SAT เพิ่งแก้การ์ดเองภายใน 3 ชม.
 */
class Rain_Model extends Model {

    const SOURCE_NAME = 'กรมชลประทาน';
    const STATION_URL = 'https://swocrf.rid.go.th/?m=stations&i=';
    const AUTO_MARK = '🤖 ดึงอัตโนมัติ';
    const MANUAL_HOLD_HOURS = 3;
    const STALE_HOURS = 8;

    public function ensureTable() {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        try {
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS `flood_rain` (
                  `station_code` VARCHAR(80) NOT NULL,
                  `rain_date` DATE NOT NULL,
                  `mm` DECIMAL(7,2) NOT NULL DEFAULT 0,
                  `station_name` VARCHAR(150) NOT NULL DEFAULT '',
                  `source` VARCHAR(20) NOT NULL DEFAULT 'rid',
                  `fetched_at` DATETIME NOT NULL,
                  PRIMARY KEY (`station_code`, `rain_date`),
                  KEY `idx_flood_rain_fetched` (`fetched_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $ready = true;
        } catch (Exception $e) {
            error_log('[flood] flood_rain: ' . $e->getMessage());
            try {
                $ready = (bool) $this->db->select("SHOW TABLES LIKE 'flood_rain'");
            } catch (Exception $e2) {
                $ready = false;
            }
        }
        return $ready;
    }

    /** ตรวจข้อมูลที่ส่งมา → array(code, name, rows[date => mm]) หรือข้อความผิดพลาด */
    public static function parse($in) {
        if (!is_array($in)) {
            return 'รูปแบบข้อมูลไม่ถูกต้อง';
        }
        $code = isset($in['station_code']) ? (string) $in['station_code'] : '';
        if (!preg_match('/^[A-Za-z0-9]{8,80}$/', $code)) {
            return 'รหัสสถานีไม่ถูกต้อง';
        }
        $name = mb_substr(trim(isset($in['station_name']) ? (string) $in['station_name'] : ''), 0, 150);
        $rows = array();
        foreach ((array) (isset($in['rows']) ? $in['rows'] : array()) as $r) {
            $d = isset($r['date']) ? (string) $r['date'] : '';
            $mm = isset($r['mm']) ? $r['mm'] : null;
            $t = strtotime($d);
            if (!$t || !is_numeric($mm) || (float) $mm < 0 || (float) $mm > 1000) {
                continue;
            }
            $day = date('Y-m-d', $t);
            if ($day > date('Y-m-d') || $day < date('Y-m-d', strtotime('-60 day'))) {
                continue;
            }
            $rows[$day] = round((float) $mm, 2);
        }
        if (!$rows) {
            return 'ไม่มีแถวข้อมูลฝนที่ใช้ได้';
        }
        ksort($rows);
        return array('code' => $code, 'name' => $name !== '' ? $name : 'สถานี ' . substr($code, -6), 'rows' => $rows);
    }

    public function import($p) {
        $now = date('Y-m-d H:i:s');
        $n = 0;
        foreach ($p['rows'] as $day => $mm) {
            $st = $this->db->prepare(
                'INSERT INTO flood_rain (station_code, rain_date, mm, station_name, source, fetched_at) VALUES (:c, :d, :m, :n, :s, :f)
                 ON DUPLICATE KEY UPDATE mm = VALUES(mm), station_name = VALUES(station_name), fetched_at = VALUES(fetched_at)');
            $st->execute(array(':c' => $p['code'], ':d' => $day, ':m' => $mm, ':n' => $p['name'], ':s' => 'rid', ':f' => $now));
            $n++;
        }
        return $n;
    }

    /** สรุปของสถานีที่ดึงล่าสุด (หรือสถานีที่ระบุ) — null = ยังไม่มีข้อมูล */
    public function summary($code = null) {
        if (!$this->ensureTable()) {
            return null;
        }
        if ($code === null) {
            $code = $this->db->selectValue('SELECT station_code FROM flood_rain ORDER BY fetched_at DESC LIMIT 1');
            if (!$code) {
                return null;
            }
        }
        $rows = $this->db->select('SELECT rain_date, mm, station_name, fetched_at FROM flood_rain WHERE station_code = :c
            ORDER BY rain_date DESC LIMIT 10', array(':c' => $code));
        if (!$rows) {
            return null;
        }
        $days = array();
        $fetched = '';
        foreach ($rows as $r) {
            $days[$r['rain_date']] = (float) $r['mm'];
            $fetched = max($fetched, (string) $r['fetched_at']);
        }
        $dates = array_keys($days);   // ใหม่ → เก่า
        $latest = $dates[0];
        $prev = isset($dates[1]) ? $dates[1] : null;
        $sumN = function ($n) use ($days, $dates, $latest) {
            $s = 0.0;
            foreach ($dates as $d) {
                if ($d >= date('Y-m-d', strtotime($latest . ' -' . ($n - 1) . ' day'))) {
                    $s += $days[$d];
                }
            }
            return round($s, 1);
        };
        $out = array(
            'station_code' => $code, 'station_name' => (string) $rows[0]['station_name'], 'url' => self::STATION_URL . $code,
            'days' => $days, 'latest' => $latest, 'latest_mm' => $days[$latest], 'latest_is_today' => $latest === date('Y-m-d'),
            'prev' => $prev, 'prev_mm' => $prev !== null ? $days[$prev] : null,
            'sum3' => $sumN(3), 'sum7' => $sumN(7), 'fetched_at' => $fetched,
            'fresh' => $fetched !== '' && (time() - strtotime($fetched)) <= self::STALE_HOURS * 3600,
        );
        $peak = max($out['latest_mm'], (float) $out['prev_mm']);
        $out['peak'] = $peak;
        $out['class'] = self::rainClass($peak);
        $out['status'] = self::suggest($peak, $out['sum3']);
        return $out;
    }

    /** ประเภทฝนตามเกณฑ์กรมอุตุนิยมวิทยา (ปริมาณฝนรายวัน) */
    public static function rainClass($mm) {
        if ($mm <= 0) {
            return 'ไม่มีฝน';
        }
        if ($mm <= 10) {
            return 'ฝนเล็กน้อย';
        }
        if ($mm <= 35) {
            return 'ฝนปานกลาง';
        }
        if ($mm <= 90) {
            return 'ฝนหนัก';
        }
        return 'ฝนหนักมาก';
    }

    /** สีที่แนะนำ (ฝนอย่างเดียวไม่ถึงขั้นวิกฤต จึงสูงสุดแค่ส้ม): ฝนวันล่าสุด/เมื่อวาน + ฝนสะสม 3 วัน */
    public static function suggest($peak, $sum3) {
        if ($peak > 90 || $sum3 >= 150) {
            return 'orange';
        }
        if ($peak > 35 || $sum3 >= 90) {
            return 'yellow';
        }
        return 'green';
    }

    private static function dayLabel($d) {
        return $d === date('Y-m-d') ? 'วันนี้' : ($d === date('Y-m-d', strtotime('-1 day')) ? 'เมื่อวาน' : flood_thai_date($d, false, false));
    }

    private static function mm($v) {
        return rtrim(rtrim(number_format((float) $v, 1), '0'), '.');
    }

    /** ข้อความสรุปสั้นสำหรับการ์ด / SitRep */
    public static function sentence($s) {
        $t = self::dayLabel($s['latest']) . ' ' . self::mm($s['latest_mm']) . ' มม.' . ($s['latest_is_today'] ? ' (ถึง ' . date('H:i', strtotime($s['fetched_at'])) . ')' : '');
        if ($s['prev'] !== null) {
            $t .= ' · ' . self::dayLabel($s['prev']) . ' ' . self::mm($s['prev_mm']) . ' มม.';
        }
        return $t . ' · สะสม 3 วัน ' . self::mm($s['sum3']) . ' มม. → ' . $s['class'];
    }

    /** บรรทัดข้อมูลใต้การ์ด "ฝน" (ไอคอนฐานข้อมูล) — ใช้ใน views/flood/sat.php */
    public static function autoLines() {
        try {
            $m = new self();
            $s = $m->summary();
        } catch (Exception $e) {
            error_log('[flood] rain autoLines: ' . $e->getMessage());
            return array();
        }
        if (!$s) {
            return array('ยังไม่มีข้อมูลฝนจาก' . self::SOURCE_NAME);
        }
        $col = class_exists('Sat_Model') ? Sat_Model::colors() : array();
        $parts = array();
        foreach (array_slice($s['days'], 0, 3, true) as $d => $v) {
            $parts[] = self::dayLabel($d) . ' ' . self::mm($v);
        }
        return array(
            $s['station_name'] . ' (' . self::SOURCE_NAME . '): ' . implode(' · ', $parts) . ' มม.',
            'สะสม 3 วัน ' . self::mm($s['sum3']) . ' · 7 วัน ' . self::mm($s['sum7']) . ' มม. · ' . $s['class']
                . (isset($col[$s['status']]) ? ' ' . $col[$s['status']]['emoji'] : '')
                . ' · ดึง ' . date('H:i', strtotime($s['fetched_at'])) . ' น.' . ($s['fresh'] ? '' : ' (ข้อมูลเก่า เกิน ' . self::STALE_HOURS . ' ชม.)'),
        );
    }

    /** ข้อความต่อท้ายบรรทัด "ฝน" ใน SitRep — ว่างถ้าสรุปของการ์ดมีข้อความชุดนี้อยู่แล้ว */
    public static function sitrepText($note = '') {
        try {
            $m = new self();
            $s = $m->summary();
        } catch (Exception $e) {
            return '';
        }
        if (!$s) {
            return '';
        }
        $t = self::sentence($s);
        if ($note !== '' && strpos($note, $t) !== false) {
            return '';
        }
        return $s['station_name'] . ' (' . self::SOURCE_NAME . '): ' . $t;
    }

    /**
     * อัปเดตการ์ด "ฝน" ของ SAT ตามข้อมูลล่าสุด — คืน 'updated' | 'kept' (SAT เพิ่งแก้เองภายใน 3 ชม.) | 'no_sat'
     */
    public function updateSatCard($s, $uid) {
        if (!is_file('models/sat_model.php')) {
            return 'no_sat';
        }
        require_once 'models/sat_model.php';
        $sat = new Sat_Model();
        if (!$sat->ensureTables()) {
            return 'no_sat';
        }
        $cur = $sat->itemStates();
        $c = isset($cur['rain']) ? $cur['rain'] : null;
        $manualFresh = $c && $c['updated_at'] && (time() - strtotime($c['updated_at'])) < self::MANUAL_HOLD_HOURS * 3600
            && strpos((string) $c['note'], self::AUTO_MARK) !== 0;
        if ($manualFresh) {
            return 'kept';
        }
        $note = self::AUTO_MARK . ' ' . date('H:i') . ' น. · ' . $s['station_name'] . ' (' . self::SOURCE_NAME . '): ' . self::sentence($s);
        $sat->saveItem('rain', $s['status'], $note, $uid);
        return 'updated';
    }
}
