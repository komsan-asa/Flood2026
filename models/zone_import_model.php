<?php

/**
 * นำเข้าพื้นที่ประกาศจาก arankub.com (คัดลอกครั้งเดียว) — หน้าผู้ดูแล flood/zoneImport
 *
 * ต้นทาง GET https://arankub.com/api/flood (ข้อมูลสาธารณะ ไม่มีข้อมูลส่วนบุคคล)
 *   {zones:[{id, name, kind:"FLOOD", level:"EVACUATED|BLOCKED|WATCH",
 *            shape:"CIRCLE|POLYGON|CORRIDOR|TAMBON", centerLat, centerLng, radiusM,
 *            polygon:[[lat,lng],...], tambon, note, startedAt, updatedAt}], updatedAt}
 *
 * แปลงรูปร่างเป็นแบบที่ฟอร์มประกาศพื้นที่ของเราแก้ต่อได้
 *   CIRCLE   → วงกลม
 *   POLYGON  → ขอบเขต (polygon)
 *   CORRIDOR → แนวเส้น + ระยะ radiusM สองข้าง → ขอบเขต (polygon) ที่ครอบแนวนั้น
 *   TAMBON   → ทั้งตำบล (ต้นทางไม่มีพิกัด) → วงกลมรอบจุดกลางตำบล รัศมีตามระยะถึงตำบลข้างเคียง
 *
 * พื้นที่ที่นำเข้าเป็นของระบบเรา (flood_zone.source = arankub) เจ้าหน้าที่แก้/ปิดประกาศได้ตามปกติ
 * ข้อมูลไม่อัปเดตตามต้นทางเอง · ตาราง flood_zone_import จำรหัสต้นทางไว้ → นำเข้าซ้ำจะข้ามพื้นที่ที่เคยนำเข้าแล้ว
 */
class Zone_Import_Model extends Model {

    const SOURCE = 'arankub';
    const API_URL = 'https://arankub.com/api/flood';
    const MAX_BYTES = 3145728;      // 3 MB
    const MAX_ZONES = 300;
    const MAX_VERTICES = 300;       // จุดขอบเขตที่แปลงจากแนวเส้น (ฟอร์มรับได้ถึง 500)
    const ATTRIBUTION = 'ที่มา: arankub.com';

    private $tambonCache = null;

    /** ชื่อระดับตามหน้าเว็บ arankub — ให้ผู้ดูแลเทียบก่อนเลือกระดับของเรา */
    public static function levelLabels() {
        return array('EVACUATED' => 'อพยพแล้ว', 'BLOCKED' => 'รถเข้าไม่ได้', 'WATCH' => 'เฝ้าระวัง');
    }

    /** ระดับของเราที่ตรงกับระดับ arankub — ใช้ตัวแรกที่ยังเปิดใช้งาน */
    private static function levelCandidates() {
        return array(
            'EVACUATED' => array('evacuated', 'impassable', 'blocked', 'watch'),
            'BLOCKED' => array('blocked', 'impassable', 'watch'),
            'WATCH' => array('watch'),
        );
    }

    public function apiUrl() {
        return defined('ARANKUB_API_URL') && ARANKUB_API_URL !== '' ? ARANKUB_API_URL : self::API_URL;
    }

    /* ==================== ตารางจำรหัสต้นทาง ==================== */

    /**
     * สร้างตาราง flood_zone_import ถ้ายังไม่มี (เหมือน sql/10_flood_zone_import.sql)
     * คืน false ถ้าไม่มีตารางและสร้างไม่ได้ (บัญชีฐานข้อมูลไม่มีสิทธิ์ CREATE)
     */
    public function ensureTable() {
        try {
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS `flood_zone_import` (
                  `import_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `source` VARCHAR(20) NOT NULL COMMENT 'ระบบต้นทาง เช่น arankub',
                  `ext_id` VARCHAR(64) NOT NULL COMMENT 'รหัสพื้นที่ของต้นทาง',
                  `zone_id` INT UNSIGNED DEFAULT NULL COMMENT 'พื้นที่ของเราที่สร้างจากรายการนี้',
                  `ext_name` VARCHAR(200) DEFAULT NULL,
                  `ext_level` VARCHAR(20) DEFAULT NULL,
                  `ext_shape` VARCHAR(20) DEFAULT NULL,
                  `ext_updated_at` DATETIME DEFAULT NULL COMMENT 'เวลาแก้ไขล่าสุดที่ต้นทาง (เวลาไทย)',
                  `raw_json` MEDIUMTEXT DEFAULT NULL COMMENT 'ข้อมูลต้นฉบับตอนนำเข้า',
                  `imported_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  `imported_by` INT UNSIGNED DEFAULT NULL,
                  PRIMARY KEY (`import_id`),
                  UNIQUE KEY `uq_flood_zone_import_ext` (`source`, `ext_id`),
                  KEY `idx_flood_zone_import_zone` (`zone_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            return true;
        } catch (Exception $e) {
            // ไม่มีสิทธิ์ CREATE แต่อาจมีตารางอยู่แล้ว (รัน sql/10 เอง)
            try {
                $this->db->selectValue("SELECT COUNT(*) FROM flood_zone_import");
                return true;
            } catch (Exception $e2) {
                error_log('[flood] สร้างตาราง flood_zone_import ไม่ได้: ' . $e->getMessage());
                return false;
            }
        }
    }

    /** ext_id → พื้นที่ของเราที่เคยนำเข้า (เฉพาะที่พื้นที่ยังอยู่) */
    public function importedMap() {
        $out = array();
        foreach ($this->db->select(
            "SELECT i.ext_id, z.zone_id, z.name, z.level, z.status
             FROM flood_zone_import i INNER JOIN flood_zone z ON z.zone_id = i.zone_id
             WHERE i.source = :s",
            array(':s' => self::SOURCE)
        ) as $r) {
            $out[$r['ext_id']] = array('zone_id' => (int) $r['zone_id'], 'name' => $r['name'],
                'level' => $r['level'], 'status' => $r['status']);
        }
        return $out;
    }

    public function importedStats() {
        $r = $this->db->selectOne(
            "SELECT COUNT(*) AS c, MAX(i.imported_at) AS last_at,
                    SUM(z.status = 'active') AS active_count
             FROM flood_zone_import i INNER JOIN flood_zone z ON z.zone_id = i.zone_id
             WHERE i.source = :s",
            array(':s' => self::SOURCE)
        );
        return array('count' => (int) $r['c'], 'active' => (int) $r['active_count'], 'last_at' => $r['last_at']);
    }

    /* ==================== ดึง / อ่านข้อมูล ==================== */

    /** ดึงจาก arankub.com ทางเซิร์ฟเวอร์ — URL คงที่ (ผู้ใช้ส่ง URL เองไม่ได้) */
    public function fetchRemote() {
        if (!function_exists('curl_init')) {
            throw new Exception('เซิร์ฟเวอร์นี้ไม่มี PHP curl — ใช้วิธีคัดลอกข้อมูลมาวางแทน');
        }
        $url = $this->apiUrl();
        for ($hop = 0; $hop < 3; $hop++) {
            $body = '';
            $tooBig = false;
            $max = self::MAX_BYTES;
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body, &$tooBig, $max) {
                    $body .= $chunk;
                    if (strlen($body) > $max) {
                        $tooBig = true;
                        return 0;   // ยกเลิกการดาวน์โหลด
                    }
                    return strlen($chunk);
                },
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; SK-Flood-Import/1.0)',
                CURLOPT_HTTPHEADER => array('Accept: application/json'),
                CURLOPT_ENCODING => '',
            ));
            $ok = curl_exec($ch);
            $err = curl_error($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $next = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            curl_close($ch);
            if ($tooBig) {
                throw new Exception('ข้อมูลจาก arankub.com ใหญ่ผิดปกติ (เกิน 3 MB) จึงหยุดดึงไว้ก่อน');
            }
            if ($ok === false) {
                throw new Exception('เชื่อมต่อ arankub.com ไม่ได้ (' . $err . ') — เซิร์ฟเวอร์อาจออกอินเทอร์เน็ตไม่ได้ ใช้วิธีคัดลอกข้อมูลมาวางแทน');
            }
            // ส่งต่อได้เฉพาะภายใน arankub.com
            if ($code >= 300 && $code < 400 && preg_match('#^https://([a-z0-9-]+\.)*arankub\.com(/|$)#i', $next)) {
                $url = $next;
                continue;
            }
            if ($code !== 200) {
                throw new Exception('arankub.com ตอบกลับรหัส ' . $code . ' — ลองใหม่ภายหลัง หรือใช้วิธีคัดลอกข้อมูลมาวางแทน');
            }
            return $body;
        }
        throw new Exception('arankub.com ส่งต่อหลายทอดเกินไป — ใช้วิธีคัดลอกข้อมูลมาวางแทน');
    }

    /** JSON → array('zones' => [...], 'updated_at' => ...) หรือโยน Exception พร้อมข้อความภาษาไทย */
    public function decode($raw) {
        $raw = (string) $raw;
        if (strlen($raw) > self::MAX_BYTES) {
            throw new Exception('ข้อมูลใหญ่เกินไป (เกิน 3 MB)');
        }
        $raw = trim(preg_replace('/^\xEF\xBB\xBF/', '', $raw));
        if ($raw === '') {
            throw new Exception('ยังไม่มีข้อมูล — วางข้อความจากหน้า arankub.com/api/flood ลงในช่องก่อน');
        }
        $data = json_decode($raw, true, 64);
        if (!is_array($data)) {
            // คัดลอกมาทั้งหน้า (มีข้อความอื่นติดมา) — ลองตัดเฉพาะช่วง { ... }
            $a = strpos($raw, '{');
            $b = strrpos($raw, '}');
            if ($a !== false && $b !== false && $b > $a) {
                $data = json_decode(substr($raw, $a, $b - $a + 1), true, 64);
            }
        }
        if (!is_array($data)) {
            throw new Exception('อ่านข้อมูลไม่ได้ (ไม่ใช่ JSON) — ตรวจว่าคัดลอกมาครบตั้งแต่ { ถึง } ตัวสุดท้าย');
        }
        if (isset($data['zones']) && is_array($data['zones'])) {
            $zones = $data['zones'];
        } elseif ($data && array_keys($data) === range(0, count($data) - 1)) {
            $zones = $data;   // วางมาเฉพาะรายการ [...]
        } else {
            throw new Exception('ไม่พบรายการพื้นที่ (zones) ในข้อมูลนี้');
        }
        if (count($zones) > self::MAX_ZONES) {
            throw new Exception('พื้นที่มากเกินไป (' . count($zones) . ' รายการ) นำเข้าได้ครั้งละไม่เกิน ' . self::MAX_ZONES);
        }
        return array(
            'zones' => array_values($zones),
            'updated_at' => isset($data['updatedAt']) ? self::parseTime($data['updatedAt']) : null,
        );
    }

    /* ==================== ตัวอย่างก่อนนำเข้า ==================== */

    /**
     * แปลงทุกรายการ + ตรวจซ้ำ/ทับซ้อน
     * state: new = นำเข้าได้ · imported = เคยนำเข้าแล้ว · skip = ใช้ไม่ได้ (ดู reason)
     */
    public function preview($payload) {
        $imported = $this->importedMap();
        $ours = $this->ourActiveZones();
        $items = array();
        $seen = array();
        $levels = array();
        $counts = array('total' => 0, 'new' => 0, 'imported' => 0, 'skip' => 0, 'checked' => 0);
        foreach ($payload['zones'] as $z) {
            $it = $this->mapZone($z);
            if ($it['state'] !== 'skip' && isset($seen[$it['ext_id']])) {
                $it = self::skip($it, 'ซ้ำกับรายการก่อนหน้าในข้อมูลชุดนี้');
            }
            $seen[$it['ext_id']] = true;
            if ($it['state'] === 'new' && isset($imported[$it['ext_id']])) {
                $it['state'] = 'imported';
                $it['checked'] = false;
                $it['zone'] = $imported[$it['ext_id']];
            }
            if ($it['state'] === 'new') {
                $this->overlapFlags($it, $ours);
                $lv = $it['level_key'];
                if (!isset($levels[$lv])) {
                    $labels = self::levelLabels();
                    $levels[$lv] = array('code' => $lv, 'label' => isset($labels[$lv]) ? $labels[$lv] : ($lv === 'NONE' ? 'ไม่ระบุระดับ' : ''),
                        'count' => 0, 'default' => $it['level_default']);
                }
                $levels[$lv]['count']++;
            }
            $counts['total']++;
            $counts[$it['state']]++;
            $counts['checked'] += $it['checked'] ? 1 : 0;
            $items[] = $it;
        }
        // ระดับที่รุนแรงกว่าขึ้นก่อน (ตามลำดับของ arankub)
        $order = array_flip(array_keys(self::levelLabels()));
        uasort($levels, function ($a, $b) use ($order) {
            $x = isset($order[$a['code']]) ? $order[$a['code']] : 99;
            $y = isset($order[$b['code']]) ? $order[$b['code']] : 99;
            return $x === $y ? strcmp($a['code'], $b['code']) : $x - $y;
        });
        return array(
            'items' => $items,
            'levels' => array_values($levels),
            'counts' => $counts,
            'source_updated_th' => !empty($payload['updated_at']) ? flood_thai_date($payload['updated_at']) : '',
        );
    }

    /** ข้อมูลที่ส่งให้หน้าเว็บ (ไม่รวมข้อมูลต้นฉบับ) */
    public static function publicItem($it) {
        unset($it['raw'], $it['ext_updated'], $it['reach']);
        return $it;
    }

    /**
     * รายการที่เก็บไว้ใน session รอกดนำเข้า — เฉพาะที่นำเข้าได้และเฉพาะช่องที่ใช้บันทึก
     * (session ถูกอ่านทุก request จึงต้องเล็ก: ข้อมูลต้นฉบับเกิน 8 KB ไม่เก็บ)
     */
    public static function sessionItems($items) {
        $keys = array('ext_id', 'ext_name', 'ext_level', 'level_key', 'ext_shape', 'ext_updated', 'name', 'level_default',
            'shape', 'center', 'radius_m', 'polygon', 'amphoe_code', 'tambon_code', 'note', 'started_at', 'raw', 'state');
        $out = array();
        foreach ($items as $it) {
            if ($it['state'] !== 'new') {
                continue;
            }
            $x = array_intersect_key($it, array_flip($keys));
            if ($x['raw'] !== null && strlen($x['raw']) > 8192) {
                $x['raw'] = null;
            }
            $out[] = $x;
        }
        if (strlen(serialize($out)) > 2097152) {
            foreach ($out as $k => $x) {
                $out[$k]['raw'] = null;
            }
        }
        return $out;
    }

    private static function skip($it, $reason) {
        $it['state'] = 'skip';
        $it['reason'] = $reason;
        $it['checked'] = false;
        return $it;
    }

    private static function flag(&$it, $type, $msg) {
        $it['flags'][] = array('t' => $type, 'm' => $msg);
    }

    /** พื้นที่ของ arankub หนึ่งรายการ → รายการสำหรับตัวอย่าง/บันทึก */
    private function mapZone($z) {
        $it = array(
            'ext_id' => '', 'ext_name' => '', 'ext_level' => '', 'level_key' => 'NONE', 'ext_shape' => '', 'ext_updated' => null, 'ext_updated_th' => '',
            'name' => '', 'level_default' => '', 'shape' => null, 'center' => null, 'radius_m' => null, 'polygon' => null,
            'shape_text' => '', 'reach' => 0, 'amphoe_code' => null, 'tambon_code' => null, 'amphoe_name' => '', 'tambon_name' => '',
            'note' => '', 'started_at' => null, 'started_th' => '', 'state' => 'new', 'reason' => '', 'flags' => array(),
            'checked' => true, 'zone' => null, 'raw' => null,
        );
        if (!is_array($z)) {
            $it['ext_id'] = 'x' . substr(sha1(json_encode($z)), 0, 20);
            return self::skip($it, 'รูปแบบข้อมูลไม่ถูกต้อง');
        }
        $get = function ($keys) use ($z) {
            foreach ((array) $keys as $k) {
                if (isset($z[$k]) && $z[$k] !== '') {
                    return $z[$k];
                }
            }
            return null;
        };

        // รหัสต้นทาง — ไม่มี/ใช้ไม่ได้ ใช้ลายนิ้วมือจากชื่อ+พิกัดแทน (นำเข้าซ้ำก็ยังจับคู่ได้)
        $id = $get('id');
        $it['ext_id'] = is_scalar($id) && preg_match('/^[A-Za-z0-9_.:\-]{1,60}$/', (string) $id)
            ? (string) $id
            : 'h' . substr(sha1(json_encode(array($get('name'), $get('shape'), $get('centerLat'), $get('centerLng'),
                $get('polygon'), $get('tambon')))), 0, 20);
        $raw = json_encode($z, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $it['raw'] = strlen($raw) <= 60000 ? $raw : null;
        $it['ext_name'] = self::cleanText($get('name'), 200);
        $it['ext_level'] = strtoupper(trim((string) (is_scalar($get('level')) ? $get('level') : '')));
        // กลุ่มสำหรับตาราง "เทียบระดับ" — ไม่ระบุระดับใช้ NONE (คีย์ว่างส่งผ่านฟอร์มไม่ได้)
        $it['level_key'] = $it['ext_level'] !== '' ? $it['ext_level'] : 'NONE';
        $it['ext_shape'] = strtoupper(trim((string) (is_scalar($get('shape')) ? $get('shape') : '')));
        $it['ext_updated'] = self::parseTime($get('updatedAt'));
        $it['ext_updated_th'] = $it['ext_updated'] ? flood_thai_date($it['ext_updated']) : '';

        $kind = strtoupper(trim((string) (is_scalar($get('kind')) ? $get('kind') : '')));
        if ($kind !== '' && $kind !== 'FLOOD') {
            return self::skip($it, 'ไม่ใช่พื้นที่น้ำท่วม (ประเภท ' . $kind . ')');
        }
        $status = strtoupper(trim((string) (is_scalar($get('status')) ? $get('status') : '')));
        if (in_array($status, array('ENDED', 'CLOSED', 'INACTIVE', 'RESOLVED', 'ARCHIVED'), true) || $get('endedAt') !== null) {
            return self::skip($it, 'ต้นทางปิดประกาศแล้ว');
        }

        list($name, $nameCut) = self::stripRefCodes($it['ext_name']);
        $it['level_default'] = $this->defaultLevel($it['ext_level']);
        if ($it['ext_level'] === '') {
            self::flag($it, 'warn', 'ต้นทางไม่ระบุระดับ — ใช้ "' . flood_level($it['level_default'])['name'] . '"');
        } elseif (!array_key_exists($it['ext_level'], self::levelCandidates())) {
            self::flag($it, 'warn', 'ระดับ ' . $it['ext_level'] . ' ไม่รู้จัก — ตรวจระดับที่ใช้แทน');
        }

        // ---------- รูปร่าง ----------
        $lat = $get(array('centerLat', 'lat'));
        $lng = $get(array('centerLng', 'lng'));
        $hasCenter = flood_valid_latlng($lat, $lng);
        $radius = $get(array('radiusM', 'radius', 'radius_m'));
        $radius = is_numeric($radius) && (float) $radius > 0 ? (float) $radius : null;
        $rawPts = $get(array('polygon', 'path', 'line', 'coordinates'));
        $pts = $rawPts !== null ? self::parsePoints($rawPts) : null;
        $tambonText = self::cleanText($get('tambon'), 120);
        $shape = $it['ext_shape'];
        if (!in_array($shape, array('CIRCLE', 'POLYGON', 'CORRIDOR', 'TAMBON'), true)) {
            $shape = $pts && count($pts) >= 3 ? 'POLYGON' : ($hasCenter ? 'CIRCLE' : ($tambonText !== '' ? 'TAMBON' : ''));
            if ($shape === '') {
                return self::skip($it, 'ไม่มีพิกัดหรือขอบเขตของพื้นที่');
            }
            self::flag($it, 'info', 'ต้นทางไม่ระบุรูปร่างที่รู้จัก — อ่านเป็น ' . $shape);
        }
        $tambonHit = null;

        if ($shape === 'POLYGON') {
            if ($pts && count($pts) > 3 && $pts[0] === $pts[count($pts) - 1]) {
                array_pop($pts);   // วงปิด (จุดแรกซ้ำจุดท้าย)
            }
            if ($pts && count($pts) >= 3) {
                if (count($pts) > 500) {
                    $n0 = count($pts);
                    $errM = 0.0;
                    $pts = self::simplifyLatLngRing($pts, self::MAX_VERTICES, $errM);
                    if ($errM > 50) {
                        self::flag($it, 'warn', 'ขอบเขตละเอียดมาก (' . $n0 . ' จุด) ลดเหลือ ' . count($pts) . ' จุด รูปร่างคลาดได้ถึง ~'
                            . self::distText($errM) . ' — ไม่ได้เลือกไว้ให้ ตรวจก่อนนำเข้า');
                        $it['checked'] = false;
                    } else {
                        self::flag($it, 'info', 'ขอบเขตมีจุดมาก (' . $n0 . ' จุด) — ลดเหลือ ' . count($pts) . ' จุด');
                    }
                }
                $it['shape'] = 'polygon';
                $it['polygon'] = $pts;
                $it['shape_text'] = 'ขอบเขต ' . count($pts) . ' จุด';
            } elseif ($hasCenter) {
                self::flag($it, 'warn', 'ขอบเขตไม่ครบ 3 จุด — ใช้วงกลมรอบจุดกลางแทน');
                $shape = 'CIRCLE';
            } else {
                return self::skip($it, 'ขอบเขตไม่ครบ 3 จุด และไม่มีจุดกลาง');
            }
        } elseif ($shape === 'CORRIDOR') {
            $r = $radius !== null ? $radius : 100.0;
            if ($radius === null) {
                self::flag($it, 'warn', 'ต้นทางไม่ระบุความกว้างแนว — ใช้ข้างละ 100 ม.');
            }
            $r = min(5000.0, max(20.0, $r));
            if ($pts && count($pts) >= 2) {
                $filled = false;
                $errorM = 0.0;
                $poly = self::corridorPolygon($pts, $r, $filled, $errorM);
                if ($poly) {
                    $it['shape'] = 'polygon';
                    $it['polygon'] = $poly;
                    $it['shape_text'] = 'แนวถนน ' . count($pts) . ' จุด กว้างข้างละ ' . self::distText($r)
                        . ' → ขอบเขต ' . count($poly) . ' จุด';
                    if ($filled) {
                        self::flag($it, 'warn', 'แนวเส้นวนเป็นวง — ขอบเขตรวมพื้นที่กลางวงด้วย ตรวจ/แก้ขอบเขตหลังนำเข้า');
                    }
                    if ($errorM > 0.5 * $r) {
                        self::flag($it, 'warn', 'แนวถนนยาว/คดเคี้ยวมาก ขอบเขตคลาดได้ถึง ~' . self::distText($errorM)
                            . ' — ไม่ได้เลือกไว้ให้ ตรวจก่อนนำเข้า');
                        $it['checked'] = false;
                    }
                } elseif (count(array_unique(array_map('json_encode', $pts))) > 1) {
                    return self::skip($it, 'แนวเส้นยาวผิดปกติ (เกิน 200 กม.) หรือพิกัดผิด');
                }
            }
            if (!$it['shape']) {
                $c = $pts ? $pts[0] : ($hasCenter ? array((float) $lat, (float) $lng) : null);
                if (!$c) {
                    return self::skip($it, 'แนวเส้นไม่มีพิกัด');
                }
                $lat = $c[0];
                $lng = $c[1];
                $hasCenter = true;
                $radius = max(50.0, $r);
                $shape = 'CIRCLE';
                self::flag($it, 'info', 'แนวเส้นมีจุดเดียว — ใช้วงกลมแทน');
            }
        } elseif ($shape === 'TAMBON') {
            $tambonHit = $this->matchTambon($tambonText !== '' ? $tambonText : $it['ext_name'],
                $hasCenter ? (float) $lat : null, $hasCenter ? (float) $lng : null);
            if (!$tambonHit && !$hasCenter) {
                return self::skip($it, 'ไม่พบตำบล "' . ($tambonText !== '' ? $tambonText : $it['ext_name']) . '" ในจังหวัดสระแก้ว');
            }
            $c = $hasCenter ? array(round((float) $lat, 7), round((float) $lng, 7))
                : array((float) $tambonHit['lat'], (float) $tambonHit['lng']);
            $r = $radius !== null ? $radius : ($tambonHit ? $this->tambonRadius($tambonHit) : 3000);
            $it['shape'] = 'circle';
            $it['center'] = $c;
            $it['radius_m'] = (int) round(min(30000, max(20, $r)));
            $it['shape_text'] = 'ทั้งตำบล → วงกลมรอบกลางตำบล รัศมี ' . self::distText($it['radius_m']) . ' (โดยประมาณ)';
            self::flag($it, 'info', 'ต้นทางประกาศทั้งตำบล (ไม่มีขอบเขต) — ใช้วงกลมโดยประมาณ แก้ขอบเขตได้หลังนำเข้า');
        }
        if ($shape === 'CIRCLE' && !$it['shape']) {
            if (!$hasCenter) {
                return self::skip($it, 'ไม่มีจุดกลางของวงกลม');
            }
            $r = $radius;
            if ($r === null) {
                $r = 300;
                self::flag($it, 'warn', 'ต้นทางไม่ระบุรัศมี — ใช้ 300 ม.');
            } elseif ($r < 20 || $r > 30000) {
                self::flag($it, 'warn', 'รัศมี ' . self::distText($r) . ' เกินช่วงที่ระบบรับได้ — ปรับเป็น ' . self::distText(min(30000, max(20, $r))));
            }
            $it['shape'] = 'circle';
            $it['center'] = array(round((float) $lat, 7), round((float) $lng, 7));
            $it['radius_m'] = (int) round(min(30000, max(20, $r)));
            $it['shape_text'] = 'วงกลม รัศมี ' . self::distText($it['radius_m']);
        }
        if ($it['shape'] === 'polygon') {
            // ใช้กติกาเดียวกับทั้งระบบ (3–500 จุด พิกัดในไทย) — ไม่ผ่าน = ฟอร์ม/แผนที่จะอ่านขอบเขตนี้ไม่ได้
            $checked = flood_parse_polygon(json_encode($it['polygon']));
            if (!$checked) {
                return self::skip($it, 'ขอบเขตใช้ไม่ได้ (เกิน 500 จุด หรือพิกัดผิด)');
            }
            $it['polygon'] = $checked;
            $it['center'] = flood_polygon_centroid($it['polygon']);
            $reach = 0;
            foreach ($it['polygon'] as $p) {
                $reach = max($reach, flood_haversine_m($it['center'][0], $it['center'][1], $p[0], $p[1]));
            }
            $it['reach'] = $reach;
        } else {
            $it['reach'] = $it['radius_m'];
        }

        // ---------- อำเภอ / ตำบล ----------
        if (!$tambonHit && $tambonText !== '') {
            $tambonHit = $this->matchTambon($tambonText, $it['center'][0], $it['center'][1]);
            // ชื่อตำบลของต้นทางอยู่ไกลจากพื้นที่จริง → เชื่อพิกัดมากกว่า
            if ($tambonHit && flood_haversine_m($it['center'][0], $it['center'][1], $tambonHit['lat'], $tambonHit['lng']) > 15000) {
                $tambonHit = null;
            }
        }
        if (!$tambonHit) {
            $tambonHit = $this->nearestTambon($it['center'][0], $it['center'][1], 20000);
        }
        if ($tambonHit) {
            $it['amphoe_code'] = $tambonHit['amphoe_code'];
            $it['tambon_code'] = $tambonHit['tambon_code'];
            $it['amphoe_name'] = $tambonHit['amphoe_name'];
            $it['tambon_name'] = $tambonHit['name'];
        } else {
            self::flag($it, 'warn', 'ไม่พบตำบลของสระแก้วใกล้พื้นที่นี้ (อาจอยู่นอกจังหวัด) — ไม่ได้เลือกไว้ให้');
            $it['checked'] = false;
        }

        // ---------- ชื่อ / ข้อความ ----------
        if (mb_strlen($name) < 2) {
            $name = $it['tambon_name'] !== '' ? 'พื้นที่ ต.' . $it['tambon_name'] : 'พื้นที่จาก arankub.com';
            self::flag($it, 'warn', 'ต้นทางไม่มีชื่อพื้นที่ — ตั้งชื่อให้เป็น "' . $name . '"');
        }
        $it['name'] = mb_substr($name, 0, 200);
        list($note, $noteCut) = self::stripRefCodes(self::cleanText($get(array('note', 'description')), 4000, true));
        if ($nameCut || $noteCut) {
            self::flag($it, 'info', 'ตัดรหัสรายงานของ arankub (FR-…) ออก กันสับสนกับเลขรายงานของระบบนี้');
        }
        if ($shape === 'TAMBON') {
            $note = ($note !== '' ? $note . ' · ' : '') . 'ประกาศทั้งตำบล ขอบเขตบนแผนที่เป็นค่าโดยประมาณ';
        }
        if (self::hasPhone($note)) {
            self::flag($it, 'warn', 'ข้อความมีเบอร์โทรศัพท์ — ตรวจว่าเผยแพร่บนแผนที่ประชาชนได้');
        }
        $it['note'] = mb_substr($note, 0, 1950);

        $it['started_at'] = self::parseTime($get(array('startedAt', 'createdAt')));
        if (!$it['started_at'] || strtotime($it['started_at']) > time() + 3600) {
            $it['started_at'] = date('Y-m-d H:i:s');
            self::flag($it, 'info', 'ไม่มีเวลาประกาศที่ใช้ได้ — ใช้เวลาที่นำเข้า');
        }
        $it['started_th'] = flood_thai_date($it['started_at']);
        return $it;
    }

    /** ระดับของเราที่แนะนำสำหรับระดับ arankub (เฉพาะที่เปิดใช้งาน) */
    private function defaultLevel($ext) {
        $active = flood_zone_levels();
        $cands = self::levelCandidates();
        $try = isset($cands[$ext]) ? $cands[$ext] : array(strtolower($ext), 'watch');
        foreach ($try as $code) {
            if (isset($active[$code])) {
                return $code;
            }
        }
        $codes = array_keys($active);
        return $codes ? end($codes) : 'watch';
    }

    /* ==================== ซ้ำ / ทับซ้อนกับพื้นที่ที่ประกาศอยู่ ==================== */

    private function ourActiveZones() {
        $out = array();
        foreach ($this->db->select(
            "SELECT zone_id, name, shape, center_lat, center_lng, radius_m, polygon_json FROM flood_zone WHERE status = 'active'"
        ) as $z) {
            $reach = (float) $z['radius_m'];
            if ($z['shape'] === 'polygon') {
                $reach = 0;
                foreach ((array) flood_parse_polygon($z['polygon_json']) as $p) {
                    $reach = max($reach, flood_haversine_m($z['center_lat'], $z['center_lng'], $p[0], $p[1]));
                }
            }
            $out[] = array('zone_id' => (int) $z['zone_id'], 'name' => $z['name'], 'lat' => (float) $z['center_lat'],
                'lng' => (float) $z['center_lng'], 'reach' => max(1.0, $reach), 'norm' => self::normName($z['name']));
        }
        return $out;
    }

    /**
     * อาจซ้ำ = จุดกลางใกล้กันมากและขนาดใกล้เคียงกัน หรือชื่อเดียวกันในระยะ 3 กม. → ไม่เลือกไว้ให้
     * ทับซ้อน = ขอบเขตโดยประมาณซ้อนกัน → แจ้งให้ทราบเฉย ๆ
     */
    private function overlapFlags(&$it, $ours) {
        $norm = self::normName($it['name']);
        $r = max(1.0, (float) $it['reach']);
        $near = array();
        foreach ($ours as $o) {
            $d = flood_haversine_m($it['center'][0], $it['center'][1], $o['lat'], $o['lng']);
            $minR = min($r, $o['reach']);
            $maxR = max($r, $o['reach']);
            $sameName = $norm !== '' && $norm === $o['norm'] && $d <= 3000;
            if ($sameName || ($d <= max(150.0, 0.5 * $minR) && $maxR <= 4 * $minR)) {
                self::flag($it, 'warn', 'อาจซ้ำกับพื้นที่ที่ประกาศอยู่แล้ว "' . $o['name'] . '" — ไม่ได้เลือกไว้ให้');
                $it['checked'] = false;
                $it['dup_zone'] = array('zone_id' => $o['zone_id'], 'name' => $o['name']);
                return;
            }
            if ($d < $r + $o['reach']) {
                $near[] = '"' . $o['name'] . '"';
            }
        }
        if ($near) {
            self::flag($it, 'info', 'ซ้อนกับพื้นที่ที่ประกาศอยู่ ' . implode(', ', array_slice($near, 0, 3))
                . (count($near) > 3 ? ' และอีก ' . (count($near) - 3) . ' แห่ง' : ''));
        }
    }

    private static function normName($s) {
        $s = mb_strtolower((string) $s);
        $s = preg_replace('/(บริเวณ|ตำบล|ต\.|อำเภอ|อ\.|หมู่บ้าน|บ้าน)/u', '', $s);
        return preg_replace('/[\s\p{P}]+/u', '', $s);
    }

    /* ==================== นำเข้า ==================== */

    /**
     * บันทึกรายการที่เลือกเป็นพื้นที่ประกาศของเรา (ทั้งชุดใน transaction เดียว)
     * $levelMap = array('BLOCKED' => 'blocked', 'NONE' => ..., ...) ตามคีย์ level_key · คืน array(ext_id => zone_id, จำนวนที่ข้ามเพราะมีผู้นำเข้าไปก่อน)
     */
    public function import($items, $levelMap, $attribution, $userId) {
        Audit::ready($this->db);   // DDL ของ audit ต้องทำก่อนเปิด transaction
        if (!$this->ensureTable()) {
            throw new RuntimeException('ยังไม่มีตาราง flood_zone_import — ให้รันไฟล์ sql/10_flood_zone_import.sql ก่อน');
        }
        $active = flood_zone_levels();
        $done = array();
        $skipped = 0;
        $this->db->beginTransaction();
        try {
            // แถวจำรหัสที่พื้นที่หายไปแล้ว (ลบในฐานข้อมูลตรง ๆ) — ล้างทิ้งให้นำเข้าใหม่ได้
            $this->db->delete('flood_zone_import',
                'source = :w_s AND zone_id IS NOT NULL AND zone_id NOT IN (SELECT zone_id FROM flood_zone)', 1000,
                array(':w_s' => self::SOURCE));
            foreach ($items as $it) {
                // จองรหัสต้นทางก่อน (UNIQUE source+ext_id) — มีผู้นำเข้ารายการนี้ไปแล้ว/กำลังนำเข้าพร้อมกัน = ข้าม
                try {
                    $importId = $this->db->insert('flood_zone_import', array(
                        'source' => self::SOURCE,
                        'ext_id' => $it['ext_id'],
                        'ext_name' => $it['ext_name'] !== '' ? mb_substr($it['ext_name'], 0, 200) : null,
                        'ext_level' => $it['ext_level'] !== '' ? substr($it['ext_level'], 0, 20) : null,
                        'ext_shape' => $it['ext_shape'] !== '' ? substr($it['ext_shape'], 0, 20) : null,
                        'ext_updated_at' => $it['ext_updated'],
                        'raw_json' => $it['raw'],
                        'imported_by' => $userId,
                    ));
                } catch (PDOException $e) {
                    if (isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1062) {
                        $skipped++;
                        continue;
                    }
                    throw $e;
                }
                $key = isset($it['level_key']) ? $it['level_key'] : $it['ext_level'];
                $level = isset($levelMap[$key]) && is_string($levelMap[$key]) && isset($active[$levelMap[$key]])
                    ? $levelMap[$key] : $it['level_default'];
                if (!isset($active[$level])) {
                    $level = $this->defaultLevel($it['ext_level']);
                }
                $note = $it['note'];
                if ($attribution && stripos($note, 'arankub') === false) {
                    $note = ($note !== '' ? $note . ' · ' : '') . self::ATTRIBUTION;
                }
                $zoneId = (int) $this->db->insert('flood_zone', array(
                    'name' => $it['name'],
                    'level' => $level,
                    'shape' => $it['shape'],
                    'center_lat' => $it['center'][0],
                    'center_lng' => $it['center'][1],
                    'radius_m' => $it['shape'] === 'circle' ? (int) $it['radius_m'] : null,
                    'polygon_json' => $it['shape'] === 'polygon' ? json_encode($it['polygon']) : null,
                    'amphoe_code' => $it['amphoe_code'],
                    'tambon_code' => $it['tambon_code'],
                    'note' => $note !== '' ? mb_substr($note, 0, 2000) : null,
                    'source' => self::SOURCE,
                    'status' => 'active',
                    'started_at' => $it['started_at'],
                    'created_by' => $userId,
                ));
                $this->db->update('flood_zone_import', array('zone_id' => $zoneId), 'import_id = :w_id', array(':w_id' => $importId));
                $done[$it['ext_id']] = $zoneId;
            }
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
        return array($done, $skipped);
    }

    /* ==================== ตำบล ==================== */

    private function tambons() {
        if ($this->tambonCache === null) {
            $this->tambonCache = $this->db->select(
                "SELECT t.tambon_code, t.amphoe_code, t.name, t.lat, t.lng, a.name AS amphoe_name
                 FROM flood_tambon t INNER JOIN flood_amphoe a ON a.amphoe_code = t.amphoe_code
                 WHERE t.lat IS NOT NULL AND t.lng IS NOT NULL"
            );
        }
        return $this->tambonCache;
    }

    private function nearestTambon($lat, $lng, $maxM) {
        $best = null;
        $bestD = $maxM;
        foreach ($this->tambons() as $t) {
            $d = flood_haversine_m($lat, $lng, $t['lat'], $t['lng']);
            if ($d < $bestD) {
                $bestD = $d;
                $best = $t;
            }
        }
        return $best;
    }

    /**
     * ตำบลจากข้อความ เช่น "ป่าไร่" / "ต.ป่าไร่ อ.อรัญประเทศ" / "ทั้งตำบลป่าไร่"
     * ชื่อซ้ำหลายอำเภอ (เช่น หนองแวง) → ใช้ชื่ออำเภอในข้อความ หรือพิกัดที่ใกล้กว่า
     */
    private function matchTambon($text, $lat = null, $lng = null) {
        $text = trim((string) $text);
        if ($text === '') {
            return null;
        }
        $amphoe = '';
        if (preg_match('/(?:อำเภอ|อ\.)\s*([^\s,()]+)/u', $text, $m)) {
            $amphoe = $m[1];
        }
        $t = preg_replace('/(?:อำเภอ|อ\.|จังหวัด|จ\.)\s*[^\s,()]+/u', ' ', $text);
        $t = preg_replace('/(?:ตำบล|ต\.)\s*/u', ' ', $t);
        $parts = preg_split('/[\s,()]+/u', trim($t), -1, PREG_SPLIT_NO_EMPTY);
        $first = $parts ? $parts[0] : '';
        $hits = array();
        foreach ($this->tambons() as $row) {
            if ($row['name'] === $first) {
                $hits[] = $row;
            }
        }
        if (!$hits) {
            // ชื่อตำบลอยู่กลางข้อความ — เทียบชื่อที่ยาวกว่าก่อน (กัน "หนองบอน" ไปเจอ "บอน")
            $plain = preg_replace('/\s+/u', '', $text);
            $best = 0;
            foreach ($this->tambons() as $row) {
                $len = mb_strlen($row['name']);
                if ($len >= 3 && $len >= $best && mb_strpos($plain, $row['name']) !== false) {
                    if ($len > $best) {
                        $hits = array();
                    }
                    $best = $len;
                    $hits[] = $row;
                }
            }
        }
        if (count($hits) > 1 && $amphoe !== '') {
            $f = array_values(array_filter($hits, function ($r) use ($amphoe) { return $r['amphoe_name'] === $amphoe; }));
            $hits = $f ? $f : $hits;
        }
        if (count($hits) > 1 && $lat !== null) {
            usort($hits, function ($a, $b) use ($lat, $lng) {
                $x = flood_haversine_m($lat, $lng, $a['lat'], $a['lng']);
                $y = flood_haversine_m($lat, $lng, $b['lat'], $b['lng']);
                return $x < $y ? -1 : ($x > $y ? 1 : 0);
            });
        }
        return $hits ? $hits[0] : null;
    }

    /** รัศมีโดยประมาณของตำบล ≈ ครึ่งทางถึงจุดกลางตำบลที่ใกล้สุด (1.5–6 กม.) */
    private function tambonRadius($t) {
        $best = null;
        foreach ($this->tambons() as $o) {
            if ($o['tambon_code'] === $t['tambon_code']) {
                continue;
            }
            $d = flood_haversine_m($t['lat'], $t['lng'], $o['lat'], $o['lng']);
            $best = $best === null ? $d : min($best, $d);
        }
        $r = $best === null ? 3000 : 0.55 * $best;
        return (int) (round(min(6000, max(1500, $r)) / 50) * 50);
    }

    /* ==================== ข้อความ / เวลา ==================== */

    /** ตัดแท็ก/อักขระควบคุม ย่อช่องว่าง — $multiline เก็บการขึ้นบรรทัดไว้ */
    private static function cleanText($s, $max, $multiline = false) {
        if (!is_scalar($s)) {
            return '';
        }
        // ตัดเฉพาะแท็ก HTML ที่ครบรูป (strip_tags จะกินข้อความหลังเครื่องหมาย < เช่น "น้ำ <30 ซม.")
        $s = html_entity_decode((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace('/<\/?[a-z][a-z0-9]*(?:\s[^<>]*)?\/?>/iu', ' ', $s);
        $s = str_replace(array("\r\n", "\r"), "\n", $s);
        $s = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', ' ', $s);
        if ($multiline) {
            $s = preg_replace('/\h+/u', ' ', $s);   // ช่องว่างทุกแบบรวมถึงช่องว่างยูนิโค้ด
            $s = preg_replace('/ *\n */u', "\n", $s);
            $s = preg_replace("/\n{3,}/u", "\n\n", $s);
        } else {
            $s = preg_replace('/[\s\h]+/u', ' ', $s);
        }
        return mb_substr(trim((string) $s), 0, $max);
    }

    /**
     * ตัดรหัสอ้างอิงรายงานของ arankub เช่น " · รหัสอ้างอิง FR-690924-002"
     * (เลขรูปแบบเดียวกับรายงานของระบบนี้ ถ้าเก็บไว้จะเข้าใจผิดว่าเป็นรายงานของเรา)
     * คืน array(ข้อความที่ตัดแล้ว, ตัดออกไหม)
     */
    public static function stripRefCodes($s) {
        $orig = (string) $s;
        if (strpos($orig, 'FR-') === false) {
            return array($orig, false);
        }
        $code = 'FR-\d{6}-\d{2,4}';
        $s = preg_replace('/\s*[·•|,;–—-]?\s*(?:[(\[]\s*)?(?:รหัส\s*)?(?:อ้างอิง|ref(?:erence)?\.?)\s*[:：]?\s*'
            . $code . '(?:\s*(?:,|และ|\/)\s*' . $code . ')*(?:\s*[)\]])?/iu', '', $s);
        $s = $s === null ? null : preg_replace('/(?:[(\[]\s*)?\b' . $code . '\b(?:\s*[)\]])?/u', '', $s);
        if ($s === null || $s === $orig) {
            return array($orig, false);   // null = regex ล้มเหลว (ข้อความผิดรูป) → คงข้อความเดิม
        }
        // เก็บกวาดเครื่องหมายคั่นที่เหลือ
        foreach (array(
            array('/[ \t]*([·•|])(?:[ \t]*[·•|])+/u', ' $1'),
            array('/[ \t]{2,}/u', ' '),
            array('/^[\s·•|,;:–—-]+|[\s·•|,;:–—-]+$/u', ''),
            array('/[ \t]*([·•|,;:–—-])[ \t]*\n/u', "\n"),
        ) as $rx) {
            $s = preg_replace($rx[0], $rx[1], $s);
            if ($s === null) {
                return array($orig, false);
            }
        }
        return array(trim($s), true);
    }

    private static function hasPhone($s) {
        return (bool) preg_match('/(?<!\d)0\d{1,2}[- .]?\d{3}[- .]?\d{3,4}(?!\d)/u', (string) $s);
    }

    /** ISO 8601 (ส่วนใหญ่เป็นเวลา UTC ลงท้าย Z) หรือ epoch → 'Y-m-d H:i:s' เวลาไทย */
    public static function parseTime($v) {
        if ($v === null || $v === '' || !is_scalar($v)) {
            return null;
        }
        if (is_numeric($v)) {
            $ts = (float) $v;
            $ts = $ts > 1e14 ? $ts / 1e6 : ($ts > 1e11 ? $ts / 1000 : $ts);   // ไมโคร/มิลลิวินาที
            if (!is_finite($ts) || $ts > 4e9) {
                return null;
            }
            $ts = (int) $ts;
        } else {
            try {
                $dt = new DateTime((string) $v);
            } catch (Exception $e) {
                return null;
            }
            $ts = $dt->getTimestamp();
        }
        // ก่อนปี 2000 หรือเกิน 5 ปีข้างหน้า = ค่าเพี้ยน (ปี 5 หลักทำให้ฐานข้อมูลปฏิเสธทั้งชุด)
        if ($ts < 946684800 || $ts > time() + 5 * 366 * 86400) {
            return null;
        }
        $d = new DateTime('@' . $ts);
        $d->setTimezone(new DateTimeZone('Asia/Bangkok'));
        return $d->format('Y-m-d H:i:s');
    }

    private static function distText($m) {
        $m = (float) $m;
        return $m < 1000 ? number_format($m) . ' ม.' : rtrim(rtrim(number_format($m / 1000, 1), '0'), '.') . ' กม.';
    }

    /* ==================== รูปร่าง ==================== */

    /**
     * รายการพิกัดจากต้นทาง — รับ [[lat,lng],...] / [{lat,lng},...] / GeoJSON coordinates
     * สลับให้เองถ้าส่งมาเป็น [lng,lat] (ในไทย lat 5–21 กับ lng 97–106.5 ไม่ทับกัน จึงแยกได้แน่นอน)
     */
    public static function parsePoints($raw) {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (is_array($raw) && isset($raw['coordinates'])) {
            $raw = $raw['coordinates'];
        }
        // GeoJSON Polygon [[[lng,lat],...]] → วงนอก
        while (is_array($raw) && isset($raw[0][0]) && is_array($raw[0][0])) {
            $raw = $raw[0];
        }
        if (!is_array($raw)) {
            return null;
        }
        $out = array();
        foreach ($raw as $p) {
            if (!is_array($p)) {
                return null;
            }
            if (isset($p['lat'], $p['lng'])) {
                $a = $p['lat'];
                $b = $p['lng'];
            } elseif (isset($p['latitude'], $p['longitude'])) {
                $a = $p['latitude'];
                $b = $p['longitude'];
            } elseif (isset($p[0], $p[1])) {
                $a = $p[0];
                $b = $p[1];
            } else {
                return null;
            }
            if (!is_numeric($a) || !is_numeric($b)) {
                return null;
            }
            $a = (float) $a;
            $b = (float) $b;
            if (!flood_valid_latlng($a, $b) && flood_valid_latlng($b, $a)) {
                $t = $a;
                $a = $b;
                $b = $t;
            }
            if (!flood_valid_latlng($a, $b)) {
                return null;
            }
            $q = array(round($a, 7), round($b, 7));
            if ($out && $out[count($out) - 1] === $q) {
                continue;   // จุดซ้ำติดกัน
            }
            $out[] = $q;
        }
        return $out;
    }

    /**
     * ขอบเขตครอบแนวเส้น (buffer) กว้างข้างละ $r เมตร → polygon [[lat,lng],...] หรือ null
     *   1) แปลงพิกัดเป็นเมตรรอบจุดกลาง แล้วคำนวณระยะถึงเส้นเฉพาะจุดตารางที่อยู่ใกล้แนว (ตารางแบบเบาบาง
     *      ช่องละ ~r/5 จึงละเอียดเท่ากันทั้งแนวสั้นและแนวยาวหลายกิโลเมตร)
     *   2) ลากเส้นขอบตรงระยะ = r (marching squares) เลือกวงนอกสุด (รูกลางวงถูกถมเต็ม)
     *   3) ลดจำนวนจุด (Douglas–Peucker) ให้แก้ในฟอร์มได้สะดวก โดยไม่ให้เส้นขอบตัดกันเอง
     */
    public static function corridorPolygon($line, $r, &$filled = null, &$errorM = null) {
        $filled = false;
        $errorM = 0.0;
        $n = count($line);
        $r = (float) $r;
        if ($n < 2 || $r <= 0) {
            return null;
        }
        $lat0 = 0.0;
        $lng0 = 0.0;
        foreach ($line as $p) {
            $lat0 += $p[0];
            $lng0 += $p[1];
        }
        $lat0 /= $n;
        $lng0 /= $n;
        $ky = 110574.0;
        $kx = 111320.0 * cos(deg2rad($lat0));
        $pts = array();
        foreach ($line as $p) {
            $pts[] = array(($p[1] - $lng0) * $kx, ($p[0] - $lat0) * $ky);
        }
        $minX = $maxX = $pts[0][0];
        $minY = $maxY = $pts[0][1];
        $total = 0.0;
        foreach ($pts as $k => $p) {
            $minX = min($minX, $p[0]);
            $maxX = max($maxX, $p[0]);
            $minY = min($minY, $p[1]);
            $maxY = max($maxY, $p[1]);
            if ($k > 0) {
                $total += sqrt(pow($p[0] - $pts[$k - 1][0], 2) + pow($p[1] - $pts[$k - 1][1], 2));
            }
        }
        if ($total < 0.5 || $total > 200000) {
            return null;   // ทุกจุดทับกัน (= จุดเดียว) หรือยาวเกิน 200 กม. (พิกัดผิด)
        }
        // ช่องตาราง r/5 — แนวยาวจนจุดเกิน ~150,000 จุด ค่อยขยายช่อง แต่ไม่เกิน r/2 (ไม่ให้แนวแคบหลุดระหว่างจุด)
        $cell = max($r / 5, 0.5);
        if (($total + 2 * $r) * 2.6 * $r / ($cell * $cell) > 150000) {
            $cell = min($r / 2, sqrt(($total + 2 * $r) * 2.6 * $r / 150000));
        }
        $reach = $r + 1.5 * $cell;
        $pad = $reach + 2 * $cell;
        $x0 = $minX - $pad;
        $y0 = $minY - $pad;
        $nx = (int) ceil(($maxX - $minX + 2 * $pad) / $cell);
        $ny = (int) ceil(($maxY - $minY + 2 * $pad) / $cell);
        $w = $nx + 1;

        // ระยะ² ถึงเส้น เฉพาะจุดตารางในแถบกว้าง ±reach รอบแต่ละท่อน (จุดที่ไม่มีค่า = อยู่นอกแนวแน่นอน)
        $f = array();
        for ($s = 0; $s < $n - 1; $s++) {
            $ax = $pts[$s][0];
            $ay = $pts[$s][1];
            $dx = $pts[$s + 1][0] - $ax;
            $dy = $pts[$s + 1][1] - $ay;
            $len2 = $dx * $dx + $dy * $dy;
            if ($len2 <= 0) {
                continue;
            }
            $len = sqrt($len2);
            $xa = min($ax, $ax + $dx) - $reach;
            $xb = max($ax, $ax + $dx) + $reach;
            $j0 = max(0, (int) floor((min($ay, $ay + $dy) - $reach - $y0) / $cell));
            $j1 = min($ny, (int) ceil((max($ay, $ay + $dy) + $reach - $y0) / $cell));
            for ($j = $j0; $j <= $j1; $j++) {
                $py = $y0 + $j * $cell;
                $lo = $xa;
                $hi = $xb;
                if (abs($dy) > 1e-9) {
                    // แถวนี้ตัดแถบรอบเส้นตรงช่วงไหน
                    $xl = $ax + ($py - $ay) * $dx / $dy;
                    $half = $reach * $len / abs($dy);
                    $lo = max($lo, $xl - $half);
                    $hi = min($hi, $xl + $half);
                }
                if ($lo > $hi) {
                    continue;
                }
                $i0 = max(0, (int) floor(($lo - $x0) / $cell));
                $i1 = min($nx, (int) ceil(($hi - $x0) / $cell));
                $row = $j * $w;
                for ($i = $i0; $i <= $i1; $i++) {
                    $px = $x0 + $i * $cell;
                    $t = (($px - $ax) * $dx + ($py - $ay) * $dy) / $len2;
                    $t = $t < 0 ? 0.0 : ($t > 1 ? 1.0 : $t);
                    $ex = $ax + $t * $dx - $px;
                    $ey = $ay + $t * $dy - $py;
                    $d = $ex * $ex + $ey * $ey;
                    $k = $row + $i;
                    if (!isset($f[$k]) || $d < $f[$k]) {
                        $f[$k] = $d;
                    }
                }
            }
        }

        // ช่องที่มีมุมอยู่ในแนวอย่างน้อย 1 มุม (รหัสช่อง = มุมล่างซ้าย)
        $r2 = $r * $r;
        $cells = array();
        foreach ($f as $k => $d) {
            if ($d < $r2) {
                $cells[$k] = true;
                $cells[$k - 1] = true;
                $cells[$k - $w] = true;
                $cells[$k - $w - 1] = true;
            }
        }
        $val = function ($k) use ($f, $r) {
            return isset($f[$k]) ? sqrt($f[$k]) - $r : INF;   // < 0 = อยู่ในแนว
        };

        // marching squares — จุดตัดอยู่บนขอบช่อง (id: ขอบนอน = 2k, ขอบตั้ง = 2k+1 ของมุม k)
        $pos = array();
        $adj = array();
        foreach (array_keys($cells) as $ka) {
            $i = $ka % $w;
            $j = (int) (($ka - $i) / $w);
            if ($i < 0 || $j < 0 || $i >= $nx || $j >= $ny) {
                continue;
            }
            $va = $val($ka);
            $vb = $val($ka + 1);
            $vc = $val($ka + $w + 1);
            $vd = $val($ka + $w);
            $c = ($va < 0 ? 1 : 0) | ($vb < 0 ? 2 : 0) | ($vc < 0 ? 4 : 0) | ($vd < 0 ? 8 : 0);
            if ($c === 0 || $c === 15) {
                continue;
            }
            $x = $x0 + $i * $cell;
            $y = $y0 + $j * $cell;
            $e = array(
                'B' => array(2 * $ka, $x, $y, $va, $vb, true),                    // ล่าง a→b
                'R' => array(2 * ($ka + 1) + 1, $x + $cell, $y, $vb, $vc, false), // ขวา b→c
                'T' => array(2 * ($ka + $w), $x, $y + $cell, $vd, $vc, true),     // บน d→c
                'L' => array(2 * $ka + 1, $x, $y, $va, $vd, false),               // ซ้าย a→d
            );
            switch ($c) {
                case 1: case 14: $pairs = array('LB'); break;
                case 2: case 13: $pairs = array('BR'); break;
                case 3: case 12: $pairs = array('LR'); break;
                case 4: case 11: $pairs = array('RT'); break;
                case 6: case 9: $pairs = array('BT'); break;
                case 7: case 8: $pairs = array('LT'); break;
                case 5:   // a,c อยู่ในแนว — จุดกลางช่องอยู่ในด้วย = เชื่อมกัน
                    $pairs = ($va + $vb + $vc + $vd) / 4 < 0 ? array('BR', 'TL') : array('LB', 'RT');
                    break;
                default:  // 10: b,d อยู่ในแนว
                    $pairs = ($va + $vb + $vc + $vd) / 4 < 0 ? array('LB', 'RT') : array('BR', 'TL');
            }
            foreach ($pairs as $pr) {
                $ids = array();
                foreach (array($pr[0], $pr[1]) as $side) {
                    list($id, $ex, $ey, $v1, $v2, $horiz) = $e[$side];
                    if (!isset($pos[$id])) {
                        $t = $v1 / ($v1 - $v2);
                        $pos[$id] = $horiz ? array($ex + $t * $cell, $ey) : array($ex, $ey + $t * $cell);
                    }
                    $ids[] = $id;
                }
                $adj[$ids[0]][] = $ids[1];
                $adj[$ids[1]][] = $ids[0];
            }
        }

        // ต่อจุดตัดเป็นวง แล้วเลือกวงที่ใหญ่สุด (วงนอก) — วงอื่นที่ใหญ่พอ = รูกลางแนวที่วนเป็นวง ถูกถมเต็ม
        $seen = array();
        $best = null;
        $bestArea = 0.0;
        $areas = array();
        foreach ($adj as $start => $nb) {
            if (isset($seen[$start])) {
                continue;
            }
            $loop = array();
            $prev = null;
            $cur = $start;
            $guard = count($adj) + 2;
            do {
                $seen[$cur] = true;
                $loop[] = $pos[$cur];
                $nbs = $adj[$cur];
                $next = $nbs[0] !== $prev ? $nbs[0] : (isset($nbs[1]) ? $nbs[1] : $nbs[0]);
                $prev = $cur;
                $cur = $next;
            } while ($cur !== $start && --$guard > 0);
            $area = abs(self::ringArea($loop));
            $areas[] = $area;
            if ($area > $bestArea) {
                $bestArea = $area;
                $best = $loop;
            }
        }
        if (!$best || count($best) < 3) {
            return null;
        }
        foreach ($areas as $a) {
            if ($a < $bestArea && $a > 4 * $r * $r) {
                $filled = true;
            }
        }
        $ring = self::cleanRing($best, $cell * 0.01);
        list($fit, $eps) = self::fitRing($ring, max(1.0, $r * 0.04), self::MAX_VERTICES);
        if ($eps > 0.25 * $r) {
            // แนวยาว/คดเคี้ยวมาก 300 จุดหยาบเกินไป — ใช้ได้ถึง 500 จุด
            list($fit, $eps) = self::fitRing($ring, max(1.0, $r * 0.04), 500);
        }
        $errorM = $eps;
        $ring = $fit;
        if (count($ring) < 3) {
            return null;
        }
        $out = array();
        foreach ($ring as $p) {
            $out[] = array(round($p[1] / $ky + $lat0, 7), round($p[0] / $kx + $lng0, 7));
        }
        return $out;
    }

    /** ลดจุดของขอบเขต lat/lng ที่มีจุดมากเกินไป (คำนวณเป็นเมตร) */
    private static function simplifyLatLngRing($poly, $maxPts, &$errorM = null) {
        $lat0 = $poly[0][0];
        $lng0 = $poly[0][1];
        $ky = 110574.0;
        $kx = 111320.0 * cos(deg2rad($lat0));
        $xy = array();
        foreach ($poly as $p) {
            $xy[] = array(($p[1] - $lng0) * $kx, ($p[0] - $lat0) * $ky);
        }
        list($ring, $errorM) = self::fitRing(self::cleanRing($xy, 0.05), 1.0, $maxPts);
        $out = array();
        foreach ($ring as $p) {
            $out[] = array(round($p[1] / $ky + $lat0, 7), round($p[0] / $kx + $lng0, 7));
        }
        return $out;   // เหลือไม่ถึง 3 จุด → ขั้นตรวจสุดท้ายของ mapZone จะข้ามรายการนี้พร้อมเหตุผล
    }

    /**
     * ลดจุดจนไม่เกิน $maxPts (รับประกัน) — ถ้าลดแล้วเส้นขอบตัดกันเอง ลองค่าที่ละเอียดขึ้นแต่ไม่เกิน 500 จุดที่ระบบรับได้
     * คืน array(วง, ระยะคลาดเคลื่อนสูงสุดที่ยอมให้ (หน่วยเดียวกับพิกัด))
     */
    private static function fitRing($ring, $eps, $maxPts) {
        $out = self::simplifyRing($ring, $eps);
        for ($k = 0; $k < 80 && count($out) > $maxPts; $k++) {
            $eps *= 1.5;
            $out = self::simplifyRing($ring, $eps);
        }
        for ($k = 0; $k < 4 && self::ringSelfIntersects($out); $k++) {
            $try = self::simplifyRing($ring, $eps / 2);
            if (count($try) > 500) {
                break;
            }
            $eps /= 2;
            $out = $try;
        }
        return array($out, $eps);
    }

    private static function ringArea($pts) {
        $a = 0.0;
        $n = count($pts);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $a += $pts[$j][0] * $pts[$i][1] - $pts[$i][0] * $pts[$j][1];
        }
        return $a / 2;
    }

    /** ตัดจุดที่ซ้อนกันติดกัน (รวมจุดท้ายที่ซ้ำจุดแรก) */
    private static function cleanRing($pts, $tol) {
        $out = array();
        foreach ($pts as $p) {
            if ($out) {
                $q = $out[count($out) - 1];
                if (abs($q[0] - $p[0]) <= $tol && abs($q[1] - $p[1]) <= $tol) {
                    continue;
                }
            }
            $out[] = $p;
        }
        while (count($out) > 3 && abs($out[0][0] - $out[count($out) - 1][0]) <= $tol && abs($out[0][1] - $out[count($out) - 1][1]) <= $tol) {
            array_pop($out);
        }
        return $out;
    }

    /** Douglas–Peucker ของวงปิด: ตัดวงเป็น 2 ท่อนที่จุดไกลสุดจากจุดแรก แล้วลดจุดทีละท่อน */
    private static function simplifyRing($pts, $eps) {
        $n = count($pts);
        if ($n <= 8) {
            return $pts;
        }
        $far = 1;
        $bestD = -1.0;
        for ($k = 1; $k < $n; $k++) {
            $dx = $pts[$k][0] - $pts[0][0];
            $dy = $pts[$k][1] - $pts[0][1];
            if ($dx * $dx + $dy * $dy > $bestD) {
                $bestD = $dx * $dx + $dy * $dy;
                $far = $k;
            }
        }
        $a = self::simplifyOpen(array_slice($pts, 0, $far + 1), $eps);
        $tail = array_slice($pts, $far);
        $tail[] = $pts[0];
        $b = self::simplifyOpen($tail, $eps);
        array_pop($a);
        array_pop($b);
        return array_merge($a, $b);
    }

    private static function simplifyOpen($pts, $eps) {
        $n = count($pts);
        if ($n < 3) {
            return $pts;
        }
        $keep = array_fill(0, $n, false);
        $keep[0] = true;
        $keep[$n - 1] = true;
        $stack = array(array(0, $n - 1));
        $eps2 = $eps * $eps;
        while ($stack) {
            list($a, $b) = array_pop($stack);
            $ax = $pts[$a][0];
            $ay = $pts[$a][1];
            $dx = $pts[$b][0] - $ax;
            $dy = $pts[$b][1] - $ay;
            $len2 = $dx * $dx + $dy * $dy;
            $maxD = -1.0;
            $idx = -1;
            for ($k = $a + 1; $k < $b; $k++) {
                $t = $len2 > 0 ? (($pts[$k][0] - $ax) * $dx + ($pts[$k][1] - $ay) * $dy) / $len2 : 0.0;
                $t = $t < 0 ? 0.0 : ($t > 1 ? 1.0 : $t);
                $ex = $ax + $t * $dx - $pts[$k][0];
                $ey = $ay + $t * $dy - $pts[$k][1];
                $d = $ex * $ex + $ey * $ey;
                if ($d > $maxD) {
                    $maxD = $d;
                    $idx = $k;
                }
            }
            if ($idx > 0 && $maxD > $eps2) {
                $keep[$idx] = true;
                $stack[] = array($a, $idx);
                $stack[] = array($idx, $b);
            }
        }
        $out = array();
        foreach ($pts as $k => $p) {
            if ($keep[$k]) {
                $out[] = $p;
            }
        }
        return $out;
    }

    private static function ringSelfIntersects($pts) {
        $n = count($pts);
        for ($i = 0; $i < $n; $i++) {
            $a = $pts[$i];
            $b = $pts[($i + 1) % $n];
            for ($j = $i + 2; $j < $n; $j++) {
                if ($i === 0 && $j === $n - 1) {
                    continue;   // ขอบติดกัน (ต่อท้ายวง)
                }
                $c = $pts[$j];
                $d = $pts[($j + 1) % $n];
                $o1 = ($b[0] - $a[0]) * ($c[1] - $a[1]) - ($b[1] - $a[1]) * ($c[0] - $a[0]);
                $o2 = ($b[0] - $a[0]) * ($d[1] - $a[1]) - ($b[1] - $a[1]) * ($d[0] - $a[0]);
                $o3 = ($d[0] - $c[0]) * ($a[1] - $c[1]) - ($d[1] - $c[1]) * ($a[0] - $c[0]);
                $o4 = ($d[0] - $c[0]) * ($b[1] - $c[1]) - ($d[1] - $c[1]) * ($b[0] - $c[0]);
                if ($o1 * $o2 < 0 && $o3 * $o4 < 0) {
                    return true;
                }
            }
        }
        return false;
    }

}
