<?php

/**
 * ซิงก์จุดน้ำท่วมบนทางหลวงจาก "ศูนย์บริหารงานอุบัติภัย กรมทางหลวง" (HDMS) → พื้นที่ประกาศ (flood_zone.source = hdms)
 *
 * ต้นทาง (ข้อมูลสาธารณะของหน้า https://hdms.doh.go.th/dashboard)
 *   GET https://hdms.doh.go.th/internal-api/public/dashboard?start=…&end=…&status=open&passage=all
 *   → เหตุที่ยังเปิดอยู่ทั้งหมด {case_id, case_name, latitude, longitude, road_code, section_name, km_start, km_end,
 *      flood_level, lane_closure (true = ผ่านทางได้), road_closure_text, direction_text, bypass_desc, initial_relief, …}
 *
 * - ผ่านทางไม่ได้ → รถทุกชนิดผ่านไม่ได้ (สะพานขาด → ถนนขาด/สะพานชำรุด) · ผ่านทางได้ → เฝ้าระวัง
 * - เหตุที่ปิดแล้วที่ต้นทาง → ปิดประกาศพื้นที่ของเราให้เอง · ข้อมูลเปลี่ยน → อัปเดตให้เอง
 *   (ยกเว้นพื้นที่ที่เจ้าหน้าที่แก้ไขเองแล้ว — updated_by ไม่ว่าง — ระบบจะไม่เขียนทับ/ปิดแทน)
 * - ไม่เก็บชื่อผู้รายงานและเบอร์โทรของต้นทาง
 * - เซิร์ฟเวอร์ของเราออกอินเทอร์เน็ตไม่ได้ (DNS) → เบราว์เซอร์ของเจ้าหน้าที่ที่เปิดหน้าระบบอยู่เป็นผู้ดึงข้อมูลจาก HDMS
 *   (HDMS อนุญาต CORS) แล้วส่งรายการมาที่ flood/hdmsSync ทุก SYNC_EVERY วินาที — public/js/flood-hdms.js
 *   ถ้าวันหนึ่งเซิร์ฟเวอร์ออกเน็ตได้ เรียก sync() โดยไม่ส่ง items ก็ดึงเองได้
 * - จำรหัสต้นทางในตาราง flood_zone_import (source = hdms) ตารางเดียวกับการนำเข้า arankub
 */
class Hdms_Model extends Model {

    const SOURCE = 'hdms';
    const API_URL = 'https://hdms.doh.go.th/internal-api/public/dashboard';
    const PAGE_URL = 'https://hdms.doh.go.th/dashboard';
    const CREDIT = 'ศูนย์บริหารงานอุบัติภัย กรมทางหลวง';
    const SYNC_EVERY = 600;     // 10 นาที
    const MAX_ITEMS = 1000;

    /** ปิดการซิงก์ได้ด้วย define('HDMS_SYNC', false) ใน config/app.php */
    public static function enabled() {
        return !defined('HDMS_SYNC') || HDMS_SYNC;
    }

    private static function stampFile() {
        return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'skflood_hdms_' . md5(__DIR__) . '.json';
    }

    /** ข้อมูลการซิงก์ครั้งล่าสุด {at, ok, msg, counts} */
    public static function lastSync() {
        $f = self::stampFile();
        $d = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
        return is_array($d) ? $d : array('at' => 0);
    }

    /**
     * ซิงก์ถ้าถึงรอบ — ใช้ flock กันหลายคำขอซิงก์พร้อมกัน (คำขออื่นไม่รอ)
     * ผิดพลาดใด ๆ ไม่กระทบหน้าเว็บ (บันทึก error_log แล้วข้าม)
     */
    public static function maybeSync($flood) {
        if (!self::enabled()) {
            return null;
        }
        $last = self::lastSync();
        if (!empty($last['at']) && time() - (int) $last['at'] < self::SYNC_EVERY) {
            return null;
        }
        $lock = @fopen(self::stampFile() . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return null;
        }
        try {
            $m = new Hdms_Model();
            return $m->sync($flood, null);
        } catch (Exception $e) {
            error_log('[flood] hdms sync: ' . $e->getMessage());
            return null;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function saveStamp($ok, $msg, $counts) {
        @file_put_contents(self::stampFile(), json_encode(array('at' => time(), 'ok' => $ok, 'msg' => $msg, 'counts' => $counts)));
    }

    /** ดึงเหตุที่ยังเปิดอยู่จาก HDMS */
    public function fetch() {
        $today = date('Y-m-d');
        $url = self::API_URL . '?' . http_build_query(array(
            'start' => $today, 'end' => $today, 'searchText' => '', 'division' => '', 'district' => '', 'depot' => '',
            'province' => '', 'amphoe' => '', 'tambon' => '', 'incidentType' => '', 'status' => 'open',
            'floodLevel' => '', 'passage' => 'all'));
        $body = null;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
                CURLOPT_HTTPHEADER => array('Accept: application/json'), CURLOPT_USERAGENT => 'SKFlood/1.0 (+' . URL . ')'));
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($body === false || $code !== 200) {
                throw new RuntimeException('ดึงข้อมูล HDMS ไม่ได้ (' . ($err ?: 'HTTP ' . $code) . ')');
            }
        } else {
            $ctx = stream_context_create(array('http' => array('timeout' => 12, 'header' => "Accept: application/json\r\n")));
            $body = @file_get_contents($url, false, $ctx);
            if ($body === false) {
                throw new RuntimeException('ดึงข้อมูล HDMS ไม่ได้');
            }
        }
        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            throw new RuntimeException('ข้อมูล HDMS ไม่ใช่ JSON');
        }
        return array_slice(array_values($data), 0, self::MAX_ITEMS);
    }

    private static function km($s) {
        if (!preg_match('/^\s*(\d+)\s*\+\s*(\d+)/', (string) $s, $m)) {
            return null;
        }
        return (int) $m[1] + ((int) $m[2]) / 1000;
    }

    private static function pickLevel($candidates) {
        $active = flood_zone_levels();
        foreach ($candidates as $c) {
            if (isset($active[$c])) {
                return $c;
            }
        }
        $keys = array_keys($active);
        return $keys ? $keys[0] : 'watch';
    }

    /** ฟิลด์ที่ใช้จากต้นทาง (ส่วนอื่น เช่น ชื่อ/เบอร์ผู้รายงาน ไม่รับ) */
    public static function fields() {
        return array('case_id', 'case_name', 'latitude', 'longitude', 'road_code', 'section_name', 'km_start', 'km_end',
            'flood_level', 'lane_closure', 'road_closure_text', 'direction_text', 'cause_of_accident', 'bypass_desc',
            'initial_relief', 'tambon', 'amphoe', 'province', 'start_date', 'start_date_text', 'report_date_text');
    }

    /** รายการที่เบราว์เซอร์ส่งมา → เก็บเฉพาะฟิลด์ที่ใช้ ตัดความยาว */
    public static function cleanItems($list) {
        $out = array();
        foreach (array_slice((array) $list, 0, self::MAX_ITEMS) as $it) {
            if (!is_array($it)) {
                continue;
            }
            $c = array();
            foreach (self::fields() as $f) {
                $v = isset($it[$f]) ? $it[$f] : null;
                if ($f === 'lane_closure') {
                    $c[$f] = $v === true || $v === 1 || $v === '1' || $v === 'true';
                } else {
                    $c[$f] = is_scalar($v) ? mb_substr(trim((string) $v), 0, 600) : '';
                }
            }
            if (preg_match('/^[0-9A-Za-z_-]{1,40}$/', $c['case_id'])) {
                $out[] = $c;
            }
        }
        return $out;
    }

    /** แปลงรายการ HDMS เป็นข้อมูลพื้นที่ของเรา (null = ข้าม) */
    public function toZone($it, $flood) {
        $lat = isset($it['latitude']) ? (float) $it['latitude'] : 0;
        $lng = isset($it['longitude']) ? (float) $it['longitude'] : 0;
        if (empty($it['case_id']) || !flood_valid_latlng($lat, $lng)) {
            return null;
        }
        $passable = !empty($it['lane_closure']);
        $closure = trim((string) (isset($it['road_closure_text']) ? $it['road_closure_text'] : ''));
        if ($passable) {
            $level = self::pickLevel(array('watch'));
        } elseif (mb_strpos($closure, 'สะพาน') !== false) {
            $level = self::pickLevel(array('road_damaged', 'impassable', 'blocked'));
        } else {
            $level = self::pickLevel(array('impassable', 'blocked', 'watch'));
        }
        $road = ltrim((string) (isset($it['road_code']) ? $it['road_code'] : ''), '0');
        $kmS = trim((string) $it['km_start']);
        $kmE = trim((string) $it['km_end']);
        $kmTxt = $kmS !== '' ? 'กม.' . $kmS . ($kmE !== '' && $kmE !== $kmS ? '–' . $kmE : '') : '';
        $name = trim(($road !== '' ? 'ทล.' . $road . ' ' : '') . trim((string) $it['case_name']));
        $sect = trim(trim((string) (isset($it['section_name']) ? $it['section_name'] : '')) . ' ' . $kmTxt);
        if ($sect !== '') {
            $name .= ' (' . $sect . ')';
        }
        // รัศมี = ครึ่งหนึ่งของช่วง กม. ที่รายงาน (150 ม. – 5 กม.)
        $a = self::km($kmS);
        $b = self::km($kmE);
        $radius = ($a !== null && $b !== null) ? (int) round(abs($b - $a) * 500) : 0;
        $radius = max(150, min(5000, $radius));

        $fl = trim((string) (isset($it['flood_level']) ? $it['flood_level'] : ''));
        if ($fl !== '' && preg_match('/^[\d.\s\-–]+$/u', $fl)) {
            $fl = $fl === '0' ? '' : $fl . ' ซม.';
        }
        $lines = array();
        $lines[] = 'กรมทางหลวงรายงาน: ' . ($passable ? 'ผ่านทางได้' : 'ผ่านทางไม่ได้' . ($closure !== '' ? ' (' . $closure . ')' : ''))
            . ($fl !== '' ? ' · ระดับน้ำ ' . $fl : '')
            . (!empty($it['direction_text']) ? ' · ' . $it['direction_text'] : '');
        foreach (array('cause_of_accident' => 'สาเหตุ', 'bypass_desc' => 'ทางเลี่ยง', 'initial_relief' => 'การดำเนินการ') as $k => $label) {
            $v = trim(preg_replace('/\s+/u', ' ', (string) (isset($it[$k]) ? $it[$k] : '')));
            if ($v !== '') {
                $lines[] = $label . ': ' . mb_substr($v, 0, 400);
            }
        }
        $where = trim(implode(' ', array_filter(array(
            !empty($it['tambon']) ? 'ต.' . $it['tambon'] : '', !empty($it['amphoe']) ? 'อ.' . $it['amphoe'] : '',
            !empty($it['province']) ? 'จ.' . $it['province'] : ''))));
        $times = trim(implode(' · ', array_filter(array(
            !empty($it['start_date_text']) ? 'เริ่ม ' . $it['start_date_text'] : '',
            !empty($it['report_date_text']) ? 'รายงาน ' . $it['report_date_text'] : ''))));
        if ($where !== '' || $times !== '') {
            $lines[] = trim($where . ($where !== '' && $times !== '' ? ' · ' : '') . $times);
        }
        $lines[] = 'ที่มา: ' . self::CREDIT . ' ' . self::PAGE_URL . ' (เลขเหตุ ' . $it['case_id'] . ')';

        $area = $flood->guessArea($lat, $lng, 30);
        $started = !empty($it['start_date']) ? strtotime((string) $it['start_date']) : false;
        return array(
            'ext_id' => (string) $it['case_id'],
            'name' => mb_substr($name, 0, 200),
            'level' => $level,
            'shape' => 'circle',
            'center_lat' => round($lat, 7),
            'center_lng' => round($lng, 7),
            'radius_m' => $radius,
            'polygon_json' => null,
            'amphoe_code' => $area ? $area['amphoe_code'] : null,
            'tambon_code' => $area ? $area['tambon_code'] : null,
            'note' => mb_substr(implode("\n", $lines), 0, 2000),
            'started_at' => $started ? date('Y-m-d H:i:s', $started) : date('Y-m-d H:i:s'),
        );
    }

    /**
     * ซิงก์ทั้งชุด — คืน {fetched, created, updated, closed, reopened, skipped}
     * @param int|null $userId ผู้สั่ง (null = ระบบอัตโนมัติ)
     */
    public function sync($flood, $userId, $items = null) {
        require_once __DIR__ . '/zone_import_model.php';
        $zi = new Zone_Import_Model();
        if (!$zi->ensureTable()) {
            throw new RuntimeException('ยังไม่มีตาราง flood_zone_import');
        }
        $counts = array('fetched' => 0, 'created' => 0, 'updated' => 0, 'closed' => 0, 'reopened' => 0, 'skipped' => 0);
        if ($items === null) {
            try {
                $items = $this->fetch();
            } catch (Exception $e) {
                $this->saveStamp(false, $e->getMessage(), $counts);
                throw $e;
            }
        }
        $counts['fetched'] = count($items);
        // แผนที่ ext_id → พื้นที่ของเรา
        $map = array();
        foreach ($this->db->select(
            "SELECT i.import_id, i.ext_id, i.zone_id, z.status, z.updated_by, z.name, z.level, z.note, z.radius_m,
                    z.center_lat, z.center_lng
             FROM flood_zone_import i LEFT JOIN flood_zone z ON z.zone_id = i.zone_id
             WHERE i.source = :s", array(':s' => self::SOURCE)) as $r) {
            $map[$r['ext_id']] = $r;
        }
        $now = date('Y-m-d H:i:s');
        $open = array();
        foreach ($items as $it) {
            $z = $this->toZone($it, $flood);
            if (!$z) {
                $counts['skipped']++;
                continue;
            }
            $ext = $z['ext_id'];
            $open[$ext] = true;
            $row = isset($map[$ext]) ? $map[$ext] : null;
            if ($row && $row['zone_id'] && $row['status'] !== null) {
                if ($row['updated_by'] !== null) {
                    continue;   // เจ้าหน้าที่แก้ไขเองแล้ว — ไม่เขียนทับ
                }
                $upd = array();
                foreach (array('name', 'level', 'note', 'radius_m') as $k) {
                    if ((string) $row[$k] !== (string) $z[$k]) {
                        $upd[$k] = $z[$k];
                    }
                }
                if (abs((float) $row['center_lat'] - $z['center_lat']) > 0.00005 || abs((float) $row['center_lng'] - $z['center_lng']) > 0.00005) {
                    $upd['center_lat'] = $z['center_lat'];
                    $upd['center_lng'] = $z['center_lng'];
                }
                if ($row['status'] !== 'active') {
                    $upd['status'] = 'active';
                    $upd['ended_at'] = null;
                    $counts['reopened']++;
                } elseif ($upd) {
                    $counts['updated']++;
                }
                if ($upd) {
                    $upd['updated_at'] = $now;
                    $this->db->update('flood_zone', $upd, 'zone_id = :w_id', array(':w_id' => (int) $row['zone_id']));
                }
                continue;
            }
            // ใหม่ (หรือพื้นที่เดิมถูกลบไปแล้ว)
            $zoneId = (int) $this->db->insert('flood_zone', array(
                'name' => $z['name'], 'level' => $z['level'], 'shape' => 'circle',
                'center_lat' => $z['center_lat'], 'center_lng' => $z['center_lng'], 'radius_m' => $z['radius_m'],
                'polygon_json' => null, 'amphoe_code' => $z['amphoe_code'], 'tambon_code' => $z['tambon_code'],
                'note' => $z['note'], 'source' => self::SOURCE, 'status' => 'active', 'started_at' => $z['started_at'],
                'created_by' => $userId,
            ));
            if ($row) {
                $this->db->update('flood_zone_import', array('zone_id' => $zoneId, 'ext_name' => mb_substr($z['name'], 0, 200)),
                    'import_id = :w_id', array(':w_id' => (int) $row['import_id']));
            } else {
                $this->db->insert('flood_zone_import', array(
                    'source' => self::SOURCE, 'ext_id' => $ext, 'zone_id' => $zoneId,
                    'ext_name' => mb_substr($z['name'], 0, 200), 'ext_level' => substr($z['level'], 0, 20), 'ext_shape' => 'circle',
                    'raw_json' => null, 'imported_by' => $userId,
                ));
            }
            $counts['created']++;
        }
        // เหตุที่ต้นทางปิดแล้ว → ปิดประกาศ (เฉพาะพื้นที่ที่ระบบสร้างและยังไม่มีใครแก้)
        if ($counts['fetched'] > 0) {
            foreach ($map as $ext => $row) {
                if (!isset($open[$ext]) && $row['zone_id'] && $row['status'] === 'active' && $row['updated_by'] === null) {
                    $this->db->update('flood_zone', array('status' => 'ended', 'ended_at' => $now, 'updated_at' => $now),
                        'zone_id = :w_id', array(':w_id' => (int) $row['zone_id']));
                    $counts['closed']++;
                }
            }
        }
        $this->saveStamp(true, '', $counts);
        return $counts;
    }
}
