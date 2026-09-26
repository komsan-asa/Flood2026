<?php

/**
 * ติดตามสถานการณ์อุทกภัยทั้งประเทศ (รายวัน) — สรุปจากรายงาน ปภ. / ข่าว / โซเชียล
 *
 * ตาราง flood_sitrep: 1 แถว = 1 วัน × 1 ขอบเขต
 *   province_code = '00' → ภาพรวมทั้งประเทศของวันนั้น
 *   province_code = 'NN' → รายจังหวัดของวันนั้น
 * นำเข้าซ้ำวันเดิม/จังหวัดเดิม = แก้ไขทับ (ค่าที่ส่งว่างไม่ทับค่าเดิม)
 *
 * นำเข้าพื้นที่น้ำท่วมรายอำเภอเป็น "พื้นที่ประกาศ" (flood_zone, source = web) — แสดงป้าย "รอตรวจสอบ" บนแผนที่
 * จนกว่าเจ้าหน้าที่จะแก้ที่มาเป็น "เจ้าหน้าที่ประกาศ" หรือ "ประกาศ ปภ."
 */
class Sitrep_Model extends Model {

    const NATIONAL = '00';

    /** สร้างตารางถ้ายังไม่มี (เหมือน sql/16_flood_sitrep.sql) */
    public function ensureTable() {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        try {
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS `flood_sitrep` (
                  `sitrep_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `report_date` DATE NOT NULL,
                  `province_code` CHAR(2) NOT NULL DEFAULT '00' COMMENT '00 = ทั้งประเทศ',
                  `as_of` DATETIME DEFAULT NULL COMMENT 'เวลาของข้อมูล',
                  `status` VARCHAR(10) DEFAULT NULL COMMENT 'ongoing|resolved|warning',
                  `trend` VARCHAR(10) DEFAULT NULL COMMENT 'rising|stable|falling',
                  `provinces_total` SMALLINT UNSIGNED DEFAULT NULL COMMENT 'สะสม',
                  `provinces_ongoing` SMALLINT UNSIGNED DEFAULT NULL,
                  `amphoes` SMALLINT UNSIGNED DEFAULT NULL,
                  `tambons` SMALLINT UNSIGNED DEFAULT NULL,
                  `villages` INT UNSIGNED DEFAULT NULL,
                  `households` INT UNSIGNED DEFAULT NULL,
                  `people` INT UNSIGNED DEFAULT NULL,
                  `deaths` SMALLINT UNSIGNED DEFAULT NULL,
                  `injured` SMALLINT UNSIGNED DEFAULT NULL,
                  `amphoe_names` VARCHAR(1000) DEFAULT NULL COMMENT 'คั่น comma',
                  `note` TEXT DEFAULT NULL,
                  `source_name` VARCHAR(200) DEFAULT NULL,
                  `source_url` VARCHAR(500) DEFAULT NULL,
                  `created_by` INT UNSIGNED DEFAULT NULL,
                  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  `updated_at` DATETIME DEFAULT NULL,
                  PRIMARY KEY (`sitrep_id`),
                  UNIQUE KEY `uk_flood_sitrep_day` (`report_date`, `province_code`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $ready = true;
        } catch (Exception $e) {
            error_log('[flood] สร้างตาราง flood_sitrep ไม่ได้ (รัน php sql/apply_schema.php 16): ' . $e->getMessage());
            $ready = false;
        }
        return $ready;
    }

    /* ==================== อ่าน ==================== */

    /** ภาพรวมทั้งประเทศทุกวัน (เก่า → ใหม่) */
    public function nationalDays($from = null) {
        if (!$this->ensureTable()) {
            return array();
        }
        $params = array(':p' => self::NATIONAL);
        $cond = '';
        if ($from) {
            $cond = ' AND report_date >= :f';
            $params[':f'] = $from;
        }
        return $this->db->select("SELECT * FROM flood_sitrep WHERE province_code = :p" . $cond . " ORDER BY report_date", $params);
    }

    /** รายจังหวัดของวันหนึ่ง พร้อมชื่อจังหวัด/ภาค — น้ำเพิ่มก่อน แล้วเรียงตามครัวเรือน */
    public function provinceRows($date) {
        if (!$this->ensureTable()) {
            return array();
        }
        return $this->db->select(
            "SELECT s.*, p.name AS province_name, p.region
             FROM flood_sitrep s LEFT JOIN flood_province p ON p.province_code = s.province_code
             WHERE s.report_date = :d AND s.province_code <> :n
             ORDER BY (s.status = 'resolved'), FIELD(IFNULL(s.trend, 'x'), 'rising', 'stable', 'falling', 'x'),
                      IFNULL(s.households, 0) DESC, p.sort_order",
            array(':d' => $date, ':n' => self::NATIONAL));
    }

    /** วันที่มีข้อมูลรายจังหวัด → [date => จำนวนจังหวัด] */
    public function provinceDates() {
        if (!$this->ensureTable()) {
            return array();
        }
        $out = array();
        foreach ($this->db->select("SELECT report_date, COUNT(*) AS c FROM flood_sitrep WHERE province_code <> :n GROUP BY report_date ORDER BY report_date",
            array(':n' => self::NATIONAL)) as $r) {
            $out[$r['report_date']] = (int) $r['c'];
        }
        return $out;
    }

    /** พื้นที่ประกาศที่นำเข้าจากข่าว/โซเชียล (ยังไม่ตรวจสอบ) ทั้งประเทศ — นับรายจังหวัด */
    public function webZoneCounts() {
        $out = array();
        foreach ($this->db->select("SELECT LEFT(amphoe_code, 2) AS pv, level, COUNT(*) AS c FROM flood_zone
                                    WHERE status = 'active' AND amphoe_code IS NOT NULL GROUP BY pv, level") as $r) {
            if (!isset($out[$r['pv']])) {
                $out[$r['pv']] = array();
            }
            $out[$r['pv']][$r['level']] = (int) $r['c'];
        }
        return $out;
    }

    /* ==================== นำเข้า ==================== */

    private $provinces = null;
    private $tambons = null;

    private function provinceCode($name) {
        if ($this->provinces === null) {
            $this->provinces = array();
            foreach ($this->db->select("SELECT province_code, name FROM flood_province") as $p) {
                $this->provinces[$p['name']] = $p['province_code'];
            }
        }
        $name = trim(preg_replace('/^(จ\.|จังหวัด)\s*/u', '', (string) $name));
        $alias = array('กทม.' => 'กรุงเทพมหานคร', 'กทม' => 'กรุงเทพมหานคร', 'กรุงเทพฯ' => 'กรุงเทพมหานคร', 'อยุธยา' => 'พระนครศรีอยุธยา');
        if (isset($alias[$name])) {
            $name = $alias[$name];
        }
        return isset($this->provinces[$name]) ? $this->provinces[$name] : null;
    }

    /** หาจุดกลางอำเภอ (หรือตำบลที่ระบุ) ในจังหวัดที่ระบุ — กันชื่ออำเภอ/ตำบลซ้ำข้ามจังหวัด */
    public function locate($pv, $amphoe, $tambons) {
        if ($this->tambons === null) {
            $this->tambons = $this->db->select(
                "SELECT t.tambon_code, t.amphoe_code, t.name, t.lat, t.lng, a.name AS amphoe_name
                 FROM flood_tambon t JOIN flood_amphoe a ON a.amphoe_code = t.amphoe_code
                 WHERE t.lat IS NOT NULL");
        }
        $clean = function ($s) {
            return trim(preg_replace('/^(อ\.|อำเภอ|เขต|ต\.|ตำบล|แขวง)\s*/u', '', (string) $s));
        };
        $amphoe = $clean($amphoe);
        $alt = array($amphoe);
        if (strpos($amphoe, 'เมือง') !== 0) {
            $alt[] = 'เมือง' . $amphoe;   // "พระนครศรีอยุธยา" = อ.พระนครศรีอยุธยา
        }
        $alt[] = 'เขต' . $amphoe;         // กรุงเทพฯ เก็บชื่อเป็น "เขตหนองจอก"
        $in = array();
        foreach ($this->tambons as $t) {
            if (substr($t['amphoe_code'], 0, 2) === $pv && in_array($t['amphoe_name'], $alt, true)) {
                $in[] = $t;
            }
        }
        if (!$in) {
            return null;
        }
        foreach ((array) $tambons as $tn) {
            $tn = $clean($tn);
            foreach ($in as $t) {
                if ($tn !== '' && $t['name'] === $tn) {
                    return array('lat' => (float) $t['lat'], 'lng' => (float) $t['lng'], 'amphoe_code' => $t['amphoe_code'],
                        'tambon_code' => $t['tambon_code'], 'amphoe_name' => $t['amphoe_name'], 'tambon_name' => $t['name']);
                }
            }
        }
        $lat = 0;
        $lng = 0;
        foreach ($in as $t) {
            $lat += (float) $t['lat'];
            $lng += (float) $t['lng'];
        }
        return array('lat' => $lat / count($in), 'lng' => $lng / count($in), 'amphoe_code' => $in[0]['amphoe_code'],
            'tambon_code' => null, 'amphoe_name' => $in[0]['amphoe_name'], 'tambon_name' => null);
    }

    private function num($v) {
        return is_numeric($v) && $v >= 0 ? (int) $v : null;
    }

    private function dt($s) {
        $t = is_string($s) ? strtotime($s) : false;
        return $t ? date('Y-m-d H:i:s', $t) : null;
    }

    private function url($u) {
        $u = trim((string) $u);
        return preg_match('#^https?://#i', $u) ? mb_substr($u, 0, 500) : null;
    }

    private function upsert($date, $pv, $row, $uid) {
        $exist = $this->db->selectOne("SELECT sitrep_id FROM flood_sitrep WHERE report_date = :d AND province_code = :p",
            array(':d' => $date, ':p' => $pv));
        $row = array_filter($row, function ($v) { return $v !== null && $v !== ''; });
        if ($exist) {
            if (!$row) {
                return 'skipped';
            }
            $row['updated_at'] = date('Y-m-d H:i:s');
            $this->db->update('flood_sitrep', $row, 'sitrep_id = :w_id', array(':w_id' => (int) $exist['sitrep_id']));
            return 'updated';
        }
        $row['report_date'] = $date;
        $row['province_code'] = $pv;
        $row['created_by'] = $uid;
        $this->db->insert('flood_sitrep', $row);
        return 'created';
    }

    /**
     * items: {kind: "day", date, as_of, status, provinces_total, provinces_ongoing, amphoes, tambons, villages, households, people,
     *          deaths, injured, note, source_name, source_url,
     *          provinces: [{name, status, trend, amphoes, tambons, villages, households, amphoe_names[], note}]}
     *        {kind: "zone", province, amphoe, tambons[], status: ongoing|resolved, trend, level, severity,
     *          info_at, source_name, source_url}
     */
    public function import($items, $user, $flood) {
        $out = array('created' => array(), 'updated' => array(), 'skipped' => array(), 'errors' => array());
        if (!$this->ensureTable()) {
            $out['errors'][] = 'ยังไม่มีตาราง flood_sitrep (รัน php sql/apply_schema.php 16)';
            return $out;
        }
        $uid = isset($user['user_id']) ? (int) $user['user_id'] : null;
        $levels = flood_zone_levels();
        foreach (array_slice((array) $items, 0, 500) as $i => $it) {
            $kind = isset($it['kind']) ? $it['kind'] : '';
            try {
                if ($kind === 'day') {
                    $out = $this->importDay($it, $uid, $out, $i);
                } elseif ($kind === 'zone') {
                    $out = $this->importZone($it, $uid, $flood, $levels, $out, $i);
                } else {
                    $out['errors'][] = '#' . ($i + 1) . ': ไม่รู้จักชนิดข้อมูล';
                }
            } catch (Exception $e) {
                error_log('[flood] sitrepImport #' . ($i + 1) . ': ' . $e->getMessage());
                $out['errors'][] = '#' . ($i + 1) . ': บันทึกไม่สำเร็จ';
            }
        }
        return $out;
    }

    private function importDay($it, $uid, $out, $i) {
        $date = isset($it['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $it['date']) ? $it['date'] : null;
        if (!$date) {
            $out['errors'][] = '#' . ($i + 1) . ': วันที่ไม่ถูกต้อง';
            return $out;
        }
        $g = function ($k) use ($it) {
            return isset($it[$k]) ? $it[$k] : null;
        };
        $srcName = $g('source_name') !== null ? mb_substr((string) $g('source_name'), 0, 200) : null;
        $srcUrl = $this->url($g('source_url'));
        $asOf = $this->dt($g('as_of'));
        $r = $this->upsert($date, self::NATIONAL, array(
            'as_of' => $asOf,
            'status' => $g('status') !== null ? mb_substr((string) $g('status'), 0, 10) : null,
            'provinces_total' => $this->num($g('provinces_total')),
            'provinces_ongoing' => $this->num($g('provinces_ongoing')),
            'amphoes' => $this->num($g('amphoes')),
            'tambons' => $this->num($g('tambons')),
            'villages' => $this->num($g('villages')),
            'households' => $this->num($g('households')),
            'people' => $this->num($g('people')),
            'deaths' => $this->num($g('deaths')),
            'injured' => $this->num($g('injured')),
            'note' => $g('note') !== null ? (string) $g('note') : null,
            'source_name' => $srcName,
            'source_url' => $srcUrl,
        ), $uid);
        $out[$r][] = 'ภาพรวม ' . $date;
        foreach ((array) $g('provinces') as $p) {
            $pv = $this->provinceCode(isset($p['name']) ? $p['name'] : '');
            if (!$pv) {
                $out['errors'][] = $date . ': ไม่พบจังหวัด ' . (isset($p['name']) ? $p['name'] : '');
                continue;
            }
            $names = array_values(array_filter(array_map('trim', (array) (isset($p['amphoe_names']) ? $p['amphoe_names'] : array()))));
            // แหล่งข้อมูลรายจังหวัด (ถ้ามี) แทนแหล่งของทั้งวัน — เช่น จังหวัดที่มีเฉพาะรายงานข่าว
            $pUrl = $this->url(isset($p['source_url']) ? $p['source_url'] : '');
            $r = $this->upsert($date, $pv, array(
                'as_of' => !empty($p['as_of']) ? $this->dt($p['as_of']) : $asOf,
                'status' => isset($p['status']) ? mb_substr((string) $p['status'], 0, 10) : null,
                'trend' => isset($p['trend']) && in_array($p['trend'], array('rising', 'stable', 'falling'), true) ? $p['trend'] : null,
                'amphoes' => $this->num(isset($p['amphoes']) ? $p['amphoes'] : null),
                'tambons' => $this->num(isset($p['tambons']) ? $p['tambons'] : null),
                'villages' => $this->num(isset($p['villages']) ? $p['villages'] : null),
                'households' => $this->num(isset($p['households']) ? $p['households'] : null),
                'amphoe_names' => $names ? mb_substr(implode(',', $names), 0, 1000) : null,
                'note' => isset($p['note']) ? (string) $p['note'] : null,
                'source_name' => $pUrl ? mb_substr((string) (isset($p['source_name']) ? $p['source_name'] : ''), 0, 200) : $srcName,
                'source_url' => $pUrl ?: $srcUrl,
            ), $uid);
            $out[$r][] = $date . ' ' . $p['name'];
        }
        return $out;
    }

    private function importZone($it, $uid, $flood, $levels, $out, $i) {
        $pvName = trim(preg_replace('/^(จ\.|จังหวัด)\s*/u', '', isset($it['province']) ? (string) $it['province'] : ''));
        $pv = $this->provinceCode($pvName);
        $label = '#' . ($i + 1) . ' ' . (isset($it['amphoe']) ? $it['amphoe'] : '') . ' ' . $pvName;
        if (!$pv || empty($it['amphoe'])) {
            $out['skipped'][] = $label . ' (ไม่ระบุอำเภอ/ไม่พบจังหวัด)';
            return $out;
        }
        $tambons = array_values(array_filter(array_map('strval', (array) (isset($it['tambons']) ? $it['tambons'] : array()))));
        $loc = $this->locate($pv, $it['amphoe'], $tambons);
        if (!$loc) {
            $out['errors'][] = $label . ': ไม่พบอำเภอในจังหวัด';
            return $out;
        }
        // มีพื้นที่ประกาศอยู่แล้วในอำเภอนี้ → ไม่สร้างซ้ำ (ให้เจ้าหน้าที่ปรับพื้นที่เดิมเอง)
        $dup = $this->db->selectValue("SELECT zone_id FROM flood_zone WHERE status = 'active' AND amphoe_code = :a LIMIT 1",
            array(':a' => $loc['amphoe_code']));
        if ($dup) {
            $out['skipped'][] = $label . ' (มีพื้นที่ประกาศในอำเภอนี้แล้ว #' . $dup . ')';
            return $out;
        }
        $resolved = isset($it['status']) && $it['status'] === 'resolved';
        $level = isset($it['level']) && isset($levels[$it['level']]) ? $it['level']
            : ($resolved && isset($levels['receded']) ? 'receded' : 'watch');
        $bkk = $pv === '10';
        $pvLabel = $bkk ? 'กรุงเทพมหานคร' : 'จ.' . $pvName;
        $amLabel = ($bkk ? 'เขต' : 'อ.') . preg_replace('/^เขต/u', '', $loc['amphoe_name']);
        $name = 'บริเวณ ' . ($loc['tambon_name'] ? ($bkk ? 'แขวง' : 'ต.') . $loc['tambon_name'] . ' ' : '') . $amLabel . ' ' . $pvLabel;
        $trendTxt = array('rising' => 'แนวโน้มน้ำเพิ่มขึ้น', 'stable' => 'ระดับน้ำทรงตัว', 'falling' => 'แนวโน้มน้ำลดลง');
        $parts = array();
        if (!empty($it['severity'])) {
            $parts[] = trim((string) $it['severity']);
        }
        $others = array();
        foreach ($tambons as $t) {
            $t = trim($t);
            if ($t !== '' && $t !== $loc['tambon_name']) {
                $others[] = $t;
            }
        }
        if ($others) {
            $parts[] = 'พื้นที่ที่มีรายงานด้วย: ' . implode(', ', $others);
        }
        if (!empty($it['trend']) && isset($trendTxt[$it['trend']])) {
            $parts[] = $trendTxt[$it['trend']];
        }
        if ($resolved) {
            $parts[] = 'สถานการณ์คลี่คลายแล้ว ยังต้องระวังโคลน/ถนนชำรุด';
        }
        $src = trim((string) (isset($it['source_name']) ? $it['source_name'] : ''));
        $at = $this->dt(isset($it['info_at']) ? $it['info_at'] : null) ?: date('Y-m-d H:i:s');
        $url = $this->url(isset($it['source_url']) ? $it['source_url'] : '');
        $parts[] = 'ที่มา: ' . ($src !== '' ? $src : 'ข่าว/โซเชียล') . ' ข้อมูล ' . flood_thai_date($at) . ($url ? ' — ' . $url : '');
        $id = $flood->saveZone(0, array(
            'name' => mb_substr($name, 0, 200),
            'level' => $level,
            'shape' => 'circle',
            'center_lat' => round($loc['lat'], 7),
            'center_lng' => round($loc['lng'], 7),
            'radius_m' => $loc['tambon_code'] ? 2500 : 5000,
            'amphoe_code' => $loc['amphoe_code'],
            'tambon_code' => $loc['tambon_code'],
            'note' => implode(' · ', $parts),
            'source' => 'web',
            'status' => 'active',
            'started_at' => $at,
        ), $uid);
        $out['created'][] = 'พื้นที่ #' . $id . ' ' . $name;
        return $out;
    }
}
