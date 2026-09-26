<?php

/**
 * นำเข้าข้อมูลน้ำท่วมที่รวบรวมจากโพสต์โซเชียล (Facebook ฯลฯ) — เจ้าหน้าที่/ผู้ช่วยส่งมาเป็น JSON ที่ flood/socialImport
 *
 * - รายงานจุดน้ำ (kind = report) → flood_report สถานะ "รอตรวจสอบ" เสมอ ไม่ขึ้นแผนที่ประชาชนจนกว่าเจ้าหน้าที่จะประกาศ
 * - คนต้องการความช่วยเหลือ (kind = help) → flood_help สถานะ "รับเรื่องใหม่" ให้เจ้าหน้าที่โทร/ยืนยันก่อน
 * - พิกัดใช้จุดกลางตำบล (loc_method = approx) เพราะโพสต์ส่วนใหญ่บอกแค่ชื่อหมู่บ้าน/ตำบล
 * - เก็บลิงก์โพสต์ต้นทางไว้ (source_url) และข้ามรายการที่ลิงก์หรือข้อความซ้ำกับที่นำเข้าแล้ว
 * - ไม่เก็บชื่อ/เบอร์ของชาวบ้านที่โพสต์ — ใส่ชื่อเพจ/กลุ่มที่เป็นแหล่งข่าวแทน
 *
 * item: {kind, source_name, url, posted_at "Y-m-d H:i", amphoe, tambon, place, note,
 *        depth, extent, vehicle, trend, impacts[], needs[], flags[], people_count, priority}
 */
class Social_Import_Model extends Model {

    const MAX_ITEMS = 100;

    private $tambons = null;

    /** คอลัมน์ที่มาของรายงาน (เหมือน sql/11_flood_report_source.sql) — คืน false ถ้าเพิ่มไม่ได้ */
    public function ensureColumns() {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        try {
            if (!$this->db->select("SHOW COLUMNS FROM flood_report LIKE 'source_url'")) {
                $this->db->exec("ALTER TABLE flood_report
                    ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT 'public' COMMENT 'public|facebook|line|other' AFTER status,
                    ADD COLUMN source_name VARCHAR(150) DEFAULT NULL COMMENT 'ชื่อเพจ/กลุ่มต้นทาง' AFTER source,
                    ADD COLUMN source_url VARCHAR(500) DEFAULT NULL COMMENT 'ลิงก์โพสต์ต้นทาง' AFTER source_name");
            }
            $ready = true;
        } catch (Exception $e) {
            error_log('[flood] เพิ่มคอลัมน์ที่มาของรายงานไม่ได้ (รัน php sql/apply_schema.php 11): ' . $e->getMessage());
            $ready = false;
        }
        return $ready;
    }

    /** หาจุดกลางตำบลจากชื่ออำเภอ + ชื่อตำบล (ไม่มีตำบล = จุดเฉลี่ยของอำเภอ) */
    public function locate($amphoe, $tambon) {
        if ($this->tambons === null) {
            $this->tambons = $this->db->select(
                "SELECT t.tambon_code, t.amphoe_code, t.name, t.lat, t.lng, a.name AS amphoe_name
                 FROM flood_tambon t JOIN flood_amphoe a ON a.amphoe_code = t.amphoe_code
                 WHERE t.lat IS NOT NULL");
        }
        $clean = function ($s) {
            return trim(preg_replace('/^(อ\.|อำเภอ|ต\.|ตำบล)\s*/u', '', (string) $s));
        };
        $amphoe = $clean($amphoe);
        $tambon = $clean($tambon);
        $inAmphoe = array();
        foreach ($this->tambons as $t) {
            if ($amphoe === '' || $t['amphoe_name'] === $amphoe) {
                $inAmphoe[] = $t;
                if ($tambon !== '' && $t['name'] === $tambon) {
                    return array('lat' => (float) $t['lat'], 'lng' => (float) $t['lng'], 'amphoe_code' => $t['amphoe_code'],
                        'tambon_code' => $t['tambon_code'], 'label' => 'ต.' . $t['name'] . ' อ.' . $t['amphoe_name']);
                }
            }
        }
        if ($amphoe === '' || !$inAmphoe) {
            return null;
        }
        $lat = 0;
        $lng = 0;
        foreach ($inAmphoe as $t) {
            $lat += (float) $t['lat'];
            $lng += (float) $t['lng'];
        }
        return array('lat' => $lat / count($inAmphoe), 'lng' => $lng / count($inAmphoe), 'amphoe_code' => $inAmphoe[0]['amphoe_code'],
            'tambon_code' => null, 'label' => 'อ.' . $amphoe . ' (ไม่ทราบตำบล)');
    }

    private function pick($value, $options, $default = null) {
        return is_string($value) && array_key_exists($value, $options) ? $value : $default;
    }

    private function postedAt($s) {
        $t = is_string($s) ? strtotime($s) : false;
        return ($t && $t <= time() + 600 && $t > time() - 30 * 86400) ? date('Y-m-d H:i:s', $t) : date('Y-m-d H:i:s');
    }

    private function cleanUrl($u) {
        $u = trim((string) $u);
        if ($u === '' || !preg_match('#^https://(www\.|m\.)?(facebook\.com|fb\.watch|fb\.com)/#i', $u)) {
            return null;
        }
        // ตัด query ที่เป็นตัวติดตาม (__cft__, __tn__ …) ออก แต่ลิงก์แบบ permalink.php / photo / story.php / watch
        // ต้องเก็บพารามิเตอร์ที่บอกว่าเป็นโพสต์ไหนไว้ ไม่งั้นลิงก์เปิดไม่ได้
        $path = (string) parse_url($u, PHP_URL_PATH);
        $base = preg_replace('/[?#].*$/', '', $u);
        if (preg_match('#^/(permalink\.php|story\.php|photo\.php|photo/?|watch/?)$#', $path)) {
            parse_str((string) parse_url($u, PHP_URL_QUERY), $q);
            $keep = array();
            foreach (array('story_fbid', 'id', 'fbid', 'set', 'v') as $k) {
                if (isset($q[$k]) && is_string($q[$k]) && $q[$k] !== '') {
                    $keep[$k] = $q[$k];
                }
            }
            if ($keep) {
                $base .= '?' . http_build_query($keep);
            }
        }
        return mb_substr($base, 0, 500);
    }

    private $noticeModel = null;

    private function importNotice($it, $user, $uid, $label, $out) {
        if ($this->noticeModel === null) {
            require_once 'models/notice_model.php';
            $this->noticeModel = new Notice_Model();
        }
        $nm = $this->noticeModel;
        if (!$nm->ensureTable()) {
            $out['errors'][] = $label . ': ยังไม่มีตาราง flood_notice';
            return $out;
        }
        $src = $it;
        $src['source_url'] = $this->cleanUrl(isset($it['url']) ? $it['url'] : '') ?: '';
        $src['info_at'] = $this->postedAt(isset($it['posted_at']) ? $it['posted_at'] : '');
        if (!isset($src['verify'])) {
            $src['verify'] = 'unverified';
        }
        if (!empty($it['amphoe'])) {
            $loc = $this->locate($it['amphoe'], '');
            $src['amphoe_code'] = $loc ? $loc['amphoe_code'] : '';
        }
        $data = $nm->parseInput($src);
        if (is_string($data)) {
            $out['errors'][] = $label . ': ' . $data;
            return $out;
        }
        if ($nm->findDuplicate($data['title'], $data['source_url'])) {
            $out['skipped'][] = $label . ' (มีข้อมูลนี้แล้ว)';
            return $out;
        }
        $id = $nm->save(0, $data, $uid);
        $out['created'][] = array('kind' => 'notice', 'ref' => 'N-' . $id, 'place' => $data['title'], 'where' => (string) $data['place']);
        return $out;
    }

    /**
     * @param Flood_Model $flood model หลัก (createReport / createHelp)
     * คืน array(created => [...], skipped => [...], errors => [...])
     */
    public function import($items, $user, $flood) {
        $out = array('created' => array(), 'skipped' => array(), 'errors' => array());
        $hasSource = $this->ensureColumns();
        $uid = isset($user['user_id']) ? (int) $user['user_id'] : null;
        $ua = 'social-import by ' . (isset($user['username']) ? $user['username'] : $uid);
        foreach (array_slice((array) $items, 0, self::MAX_ITEMS) as $i => $it) {
            $label = '#' . ($i + 1) . ' ' . mb_substr(isset($it['place']) ? (string) $it['place'] : (isset($it['title']) ? (string) $it['title'] : ''), 0, 60);
            // ข้อมูลที่ควรรู้ (ไม่ใช่จุดน้ำท่วม) → flood_notice สถานะ "ยังไม่ยืนยัน" เว้นแต่ส่ง verify = verified มา
            if (isset($it['kind']) && $it['kind'] === 'notice') {
                try {
                    $out = $this->importNotice($it, $user, $uid, $label, $out);
                } catch (Exception $e) {
                    error_log('[flood] socialImport notice ' . $label . ': ' . $e->getMessage());
                    $out['errors'][] = $label . ': บันทึกไม่สำเร็จ';
                }
                continue;
            }
            try {
                $loc = $this->locate(isset($it['amphoe']) ? $it['amphoe'] : '', isset($it['tambon']) ? $it['tambon'] : '');
                if (!$loc) {
                    $out['errors'][] = $label . ': ไม่พบอำเภอ/ตำบลในจังหวัด';
                    continue;
                }
                $srcName = mb_substr(trim(isset($it['source_name']) ? (string) $it['source_name'] : 'Facebook'), 0, 120);
                $url = $this->cleanUrl(isset($it['url']) ? $it['url'] : '');
                $place = mb_substr(trim(isset($it['place']) ? (string) $it['place'] : ''), 0, 200);
                $note = trim(isset($it['note']) ? (string) $it['note'] : '');
                $at = $this->postedAt(isset($it['posted_at']) ? $it['posted_at'] : '');
                $kind = isset($it['kind']) && $it['kind'] === 'help' ? 'help' : 'report';
                if ($place === '') {
                    $out['errors'][] = $label . ': ไม่มีชื่อจุด/สถานที่';
                    continue;
                }

                if ($kind === 'report') {
                    // ซ้ำ = โพสต์เดียวกันและจุดเดียวกัน (โพสต์เดียวอาจบอกหลายจุด) หรือจุดเดียวกันจากแหล่งเดียวกันใน 3 วัน
                    $dup = $this->db->selectValue(
                        "SELECT ref_code FROM flood_report
                         WHERE reporter_name = :n AND LEFT(place_note, CHAR_LENGTH(:p)) = :p2 AND created_at >= :d LIMIT 1",
                        array(':n' => mb_substr('Facebook: ' . $srcName, 0, 150), ':p' => $place, ':p2' => $place,
                            ':d' => date('Y-m-d H:i:s', time() - 3 * 86400)));
                    if ($dup) {
                        $out['skipped'][] = $label . ' (มีแล้ว ' . $dup . ')';
                        continue;
                    }
                    $impacts = array();
                    foreach ((array) (isset($it['impacts']) ? $it['impacts'] : array()) as $c) {
                        if (is_string($c) && array_key_exists($c, flood_area_impacts())) {
                            $impacts[] = $c;
                        }
                    }
                    $data = array(
                        'lat' => $loc['lat'], 'lng' => $loc['lng'], 'accuracy_m' => null, 'loc_method' => 'approx',
                        'depth' => $this->pick(isset($it['depth']) ? $it['depth'] : null, flood_depth_options(), 'knee'),
                        'extent' => $this->pick(isset($it['extent']) ? $it['extent'] : null, flood_extent_options(), 'road'),
                        'houses' => null,
                        'vehicle' => $this->pick(isset($it['vehicle']) ? $it['vehicle'] : null, flood_vehicle_options()),
                        'trend' => $this->pick(isset($it['trend']) ? $it['trend'] : null, flood_trend_options()),
                        'impacts' => $impacts ? implode(',', $impacts) : null,
                        'place_note' => mb_substr($place . ($note !== '' ? ' — ' . $note : ''), 0, 255),
                        'reporter_name' => mb_substr('Facebook: ' . $srcName, 0, 150),
                        'reporter_phone' => '',
                        'status' => 'pending',
                        'ip' => 'import',
                        'user_agent' => mb_substr($ua, 0, 255),
                        'created_at' => $at,
                    );
                    if ($hasSource) {
                        $data['source'] = 'facebook';
                        $data['source_name'] = $srcName;
                        $data['source_url'] = $url;
                    }
                    $ref = $flood->createReport($data, array());
                    $out['created'][] = array('kind' => 'report', 'ref' => $ref['ref'], 'place' => $place, 'where' => $loc['label']);
                } else {
                    if ($url && $this->db->selectValue("SELECT ref_code FROM flood_help WHERE detail LIKE :u LIMIT 1", array(':u' => '%' . $url . '%'))) {
                        $out['skipped'][] = $label . ' (มีใบงานแล้ว)';
                        continue;
                    }
                    $needs = array();
                    foreach ((array) (isset($it['needs']) ? $it['needs'] : array()) as $c) {
                        if (is_string($c) && array_key_exists($c, flood_help_needs())) {
                            $needs[] = $c;
                        }
                    }
                    $flags = array();
                    foreach ((array) (isset($it['flags']) ? $it['flags'] : array()) as $c) {
                        if (is_string($c) && array_key_exists($c, flood_help_flags())) {
                            $flags[] = $c;
                        }
                    }
                    $pr = isset($it['priority']) && in_array($it['priority'], array('urgent', 'high', 'normal'), true) ? $it['priority'] : 'high';
                    $detail = "นำเข้าจากโพสต์ Facebook ({$srcName}) — ยังไม่มีเบอร์ผู้ขอ ต้องติดตามผ่านผู้นำชุมชน/อปท.\n" . $note
                        . ($url ? "\nโพสต์ต้นทาง: " . $url : '');
                    $h = $flood->createHelp(array(
                        'needs' => implode(',', $needs ? $needs : array('other')),
                        'detail' => $detail,
                        'people_count' => isset($it['people_count']) && (int) $it['people_count'] > 0 ? (int) $it['people_count'] : null,
                        'vulnerable_flags' => $flags ? implode(',', $flags) : null,
                        'lat' => $loc['lat'], 'lng' => $loc['lng'], 'accuracy_m' => null,
                        'address' => mb_substr($place . ' (' . $loc['label'] . ' · พิกัดกลางตำบลโดยประมาณ)', 0, 255),
                        'amphoe_code' => $loc['amphoe_code'], 'tambon_code' => $loc['tambon_code'],
                        'requester_name' => mb_substr('Facebook: ' . $srcName, 0, 150),
                        'requester_phone' => '',
                        'priority' => $pr,
                        'status' => 'new',
                        'source' => 'facebook',
                        'created_by' => $uid,
                        'ip' => 'import',
                        'user_agent' => mb_substr($ua, 0, 255),
                    ), array(), $user);
                    $out['created'][] = array('kind' => 'help', 'ref' => $h['ref'], 'place' => $place, 'where' => $loc['label']);
                }
            } catch (Exception $e) {
                error_log('[flood] socialImport ' . $label . ': ' . $e->getMessage());
                $out['errors'][] = $label . ': บันทึกไม่สำเร็จ';
            }
        }
        return $out;
    }
}
