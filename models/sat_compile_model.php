<?php

/**
 * ห้อง SAT — "ดึงประมวล" (ปุ่มบนหัวหน้า sat)
 * รวบรวมข้อมูลย่อยของตัวชี้วัดแต่ละข้อเป็นสรุปสั้น + สีที่แนะนำ ให้ SAT ตรวจ/แก้ก่อนบันทึกลงประวัติ
 * (บันทึกผ่าน Sat_Model::saveItem() ด้วย src = 'auto' — การ์ดเปลี่ยนตาม และทุกครั้งอยู่ในประวัติของตัวชี้วัด)
 *
 * แหล่งข้อมูล
 * - ภายนอก: ThaiWater (สสน.) ระดับน้ำ / ฝนสะสม 24 ชม. / คำเตือน — เบราว์เซอร์ของเจ้าหน้าที่ดึงแล้วส่งมาเป็นชุดย่อ
 *   (เซิร์ฟเวอร์ รพ. ออกอินเทอร์เน็ตไม่ได้) → cleanTw() ตรวจชนิด/ความยาวทุกช่องก่อนใช้
 * - ในระบบ: ข้อมูลทั้งหน้า SAT (Sat::collect — หน่วยบริการ เส้นทาง บุคลากร อัตรากำลัง สาธารณูปโภค Refer ศูนย์พักพิง)
 *   · ข้อมูลที่ควรรู้ (flood_notice) · จุดแจ้งประชาชน (flood_report) · ถังน้ำมันเครื่องกำเนิดไฟฟ้า (Utility_Model)
 * - ชีต Google (สาธารณูปโภค / Refer / ศูนย์พักพิง) เบราว์เซอร์ดึงเข้าตารางของแต่ละหน้าก่อน แล้วค่อยประมวลจากฐาน
 * - สีเป็นข้อเสนอ — หัวข้อที่ไม่มีเกณฑ์คืน '' (หน้าจอใช้สีเดิมของตัวชี้วัด) · ไม่มีรายชื่อบุคคลในข้อความ
 */
class Sat_Compile_Model extends Model {

    const LIST_KM = 60;          // รัศมีสถานี ThaiWater ที่แสดงในสรุป
    const WATER_COLOR_KM = 30;   // รัศมีสถานีวัดระดับน้ำที่ใช้ให้สี
    const WATER_NEAR_KM = 10;    // สถานีใกล้โรงพยาบาล (เกณฑ์สีแดง)
    const RAIN_COLOR_KM = 40;    // รัศมีสถานีฝนที่ใช้ให้สี
    const REPORT_KM = 15;        // จุดแจ้งประชาชนรอบโรงพยาบาล
    const SITE_KM = 2;           // จุดแจ้งประชาชนติดโรงพยาบาล
    const FRESH_HOURS = 6;       // ค่าวัด ThaiWater ที่เก่ากว่านี้ไม่ใช้ให้สี

    /** แหล่งที่เบราว์เซอร์ดึงก่อนประมวล → หัวข้อที่ได้ข้อมูลจากแหล่งนั้น */
    public static function pullSources() {
        return array(
            'thaiwater' => array('name' => 'ThaiWater (สสน.) — ระดับน้ำ · ฝน 24 ชม. · คำเตือน', 'codes' => array('water', 'rain')),
            'util' => array('name' => 'ชีตสาธารณูปโภค (งานช่าง)', 'codes' => array('util_water', 'util_o2', 'util_fuel', 'util_power')),
            'refer' => array('name' => 'ชีต Refer ช่วงอุทกภัย', 'codes' => array('ems', 'ed_load')),
            'shelter' => array('name' => 'ชีตรายงานศูนย์พักพิง', 'codes' => array('patient')),
        );
    }

    /** ชุดหัวข้อในเมนู "ดึงประมวล" */
    public static function presets() {
        return array(
            'all' => array('name' => 'สรุปทุกหัวข้อ', 'codes' => array_keys(Sat_Model::items())),
            'key' => array('name' => 'ระดับน้ำ · ฝน · น้ำประปา · ออกซิเจน · เชื้อเพลิง · ผู้ป่วยเสี่ยง',
                'codes' => array('water', 'rain', 'util_water', 'util_o2', 'util_fuel', 'patient')),
        );
    }

    /** กลุ่มหัวข้อในเมนู (ลำดับเดียวกับการ์ด) */
    public static function menuGroups() {
        return array(
            'สภาพน้ำ / ฝน' => array('water', 'rain'),
            'เส้นทาง / เครือข่ายบริการ' => array('road', 'facility', 'ems'),
            'คน' => array('staff', 'patient'),
            'ภายในโรงพยาบาล' => array('hosp_site', 'ed_load', 'util_power', 'util_water', 'util_o2', 'util_fuel', 'util_it'),
        );
    }

    /** ระดับสถานการณ์น้ำของ ThaiWater (ร้อยละความจุลำน้ำ) */
    public static function lvName($lv) {
        $n = array(1 => 'น้ำน้อยวิกฤต', 2 => 'น้ำน้อย', 3 => 'ปกติ', 4 => 'น้ำมาก', 5 => 'ล้นตลิ่ง');
        return isset($n[(int) $lv]) ? $n[(int) $lv] : '';
    }

    /* ==================== ตรวจข้อมูลจากเบราว์เซอร์ ==================== */

    /**
     * tw = {water:[สถานี], rain:[สถานี], warn:[{dt,msg}], err:{water,rain,warn}} จาก sat_compile.js
     * water/rain = null หมายถึงไม่ได้ดึง (หรือดึงไม่สำเร็จ) — ต่างจาก [] ที่ดึงได้แต่ไม่มีสถานีในรัศมี
     */
    public static function cleanTw($raw) {
        $tw = is_array($raw) ? $raw : array();
        $out = array('water' => null, 'rain' => null, 'warn' => array(), 'err' => array());
        $g = function ($a, $k) {
            return is_array($a) && isset($a[$k]) ? $a[$k] : null;
        };
        $s = function ($v, $n) {
            return is_scalar($v) ? mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $v)), 0, $n) : '';
        };
        $num = function ($v) {
            return is_numeric($v) ? (float) $v : null;
        };
        $dt = function ($v) {
            $v = is_scalar($v) ? (string) $v : '';
            return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $v) ? substr($v, 0, 16) : '';
        };
        $pt = function ($x) use ($g, $num) {
            $lat = $num($g($x, 'lat'));
            $lng = $num($g($x, 'lng'));
            return ($lat !== null && $lng !== null && flood_valid_latlng($lat, $lng)) ? array($lat, $lng) : null;
        };
        if (is_array($g($tw, 'water'))) {
            $out['water'] = array();
            foreach (array_slice($tw['water'], 0, 80) as $x) {
                $p = $pt($x);
                if (!$p) {
                    continue;
                }
                $out['water'][] = array(
                    'name' => $s($g($x, 'name'), 80), 'river' => $s($g($x, 'river'), 80), 'amphoe' => $s($g($x, 'amphoe'), 60),
                    'prov' => $s($g($x, 'prov'), 60), 'agency' => $s($g($x, 'agency'), 30), 'dt' => $dt($g($x, 'dt')),
                    'lv' => max(0, min(5, (int) $g($x, 'lv'))), 'pct' => $num($g($x, 'pct')), 'diff' => $num($g($x, 'diff')),
                    'above' => (bool) $g($x, 'above'), 'msl' => $num($g($x, 'msl')), 'prev' => $num($g($x, 'prev')),
                    'lat' => $p[0], 'lng' => $p[1],
                );
            }
        }
        if (is_array($g($tw, 'rain'))) {
            $out['rain'] = array();
            foreach (array_slice($tw['rain'], 0, 120) as $x) {
                $p = $pt($x);
                $r24 = $num($g($x, 'r24'));
                if (!$p || $r24 === null || $r24 < 0 || $r24 > 2000) {
                    continue;
                }
                $r1 = $num($g($x, 'r1'));
                $out['rain'][] = array(
                    'name' => $s($g($x, 'name'), 80), 'amphoe' => $s($g($x, 'amphoe'), 60), 'prov' => $s($g($x, 'prov'), 60),
                    'agency' => $s($g($x, 'agency'), 30), 'dt' => $dt($g($x, 'dt')), 'r24' => $r24,
                    'r1' => ($r1 !== null && $r1 >= 0 && $r1 < 500) ? $r1 : null, 'lat' => $p[0], 'lng' => $p[1],
                );
            }
        }
        if (is_array($g($tw, 'warn'))) {
            foreach (array_slice($tw['warn'], 0, 10) as $x) {
                $msg = $s($g($x, 'msg'), 300);
                if ($msg !== '') {
                    $out['warn'][] = array('dt' => $dt($g($x, 'dt')), 'msg' => $msg);
                }
            }
        }
        if (is_array($g($tw, 'err'))) {
            foreach (array('water', 'rain', 'warn') as $k) {
                $e = $s($g($tw['err'], $k), 150);
                if ($e !== '') {
                    $out['err'][$k] = $e;
                }
            }
        }
        return $out;
    }

    /* ==================== ประมวลรายหัวข้อ ==================== */

    /** $c = Sat::collect() · $codes = รหัสตัวชี้วัด · $tw = cleanTw() → รายการผลประมวล (ยังไม่บันทึก) */
    public function compile($c, $codes, $tw) {
        $items = Sat_Model::items();
        $out = array();
        foreach ($codes as $code) {
            if (!isset($items[$code])) {
                continue;
            }
            $fn = 'c_' . $code;
            $r = array();
            try {
                $r = method_exists($this, $fn) ? $this->$fn($c, $tw) : array();
            } catch (Exception $e) {
                error_log('[flood] SAT compile ' . $code . ': ' . $e->getMessage());
                $r = array('lines' => array(), 'note' => 'ประมวลหัวข้อนี้ไม่สำเร็จ: ' . $e->getMessage());
            }
            $r += array('status' => '', 'lines' => array(), 'src' => array(), 'rule' => '', 'data' => null, 'note' => '');
            $lines = array_values(array_filter(array_map('trim', $r['lines']), 'strlen'));
            $text = mb_substr(implode("\n", $lines), 0, 2000);
            $cur = isset($c['items'][$code]) ? $c['items'][$code] : array('status' => '', 'note' => '', 'at' => '', 'stale' => true);
            $out[] = array(
                'code' => $code, 'name' => $items[$code]['name'], 'emoji' => $items[$code]['emoji'],
                'status' => $r['status'], 'cur_status' => (string) $cur['status'], 'cur_note' => (string) $cur['note'],
                'cur_at' => $cur['at'] ? flood_thai_date($cur['at']) : '', 'cur_stale' => !empty($cur['stale']),
                'text' => $text, 'has' => $text !== '', 'src' => array_values(array_unique(array_filter($r['src']))),
                'rule' => $r['rule'], 'note' => $r['note'], 'data' => $r['data'],
            );
        }
        return $out;
    }

    /* ---------- ระดับน้ำ ---------- */

    private function c_water($c, $tw) {
        $h = $c['hosp'];
        $L = array();
        $src = array();
        $status = '';
        $data = null;
        $note = '';
        if ($tw['water'] === null) {
            $note = 'ไม่ได้ดึง ThaiWater' . (isset($tw['err']['water']) ? ' (' . $tw['err']['water'] . ')' : '') . ' — ใช้ข้อมูลที่มีในระบบ';
        } else {
            $st = self::near($tw['water'], $h, self::LIST_KM);
            foreach ($st as &$x) {
                $x['trend'] = ($x['msl'] !== null && $x['prev'] !== null)
                    ? ($x['msl'] - $x['prev'] > 0.005 ? 1 : ($x['msl'] - $x['prev'] < -0.005 ? -1 : 0)) : null;
            }
            unset($x);
            if (!$st) {
                $L[] = 'ThaiWater: ไม่มีสถานีวัดระดับน้ำในรัศมี ' . self::LIST_KM . ' กม. จากโรงพยาบาล';
            } else {
                $over = 0;
                $latest = '';
                foreach ($st as $x) {
                    $over += $x['lv'] >= 5 ? 1 : 0;
                    $latest = max($latest, $x['dt']);
                }
                $L[] = 'สถานีวัดระดับน้ำ ThaiWater รัศมี ' . self::LIST_KM . ' กม. (ข้อมูล ' . self::when($latest) . '): ล้นตลิ่ง '
                    . $over . ' จาก ' . count($st) . ' สถานี';
                $data = array('stations' => array());
                foreach (array_slice($st, 0, 6) as $x) {
                    $L[] = '• ' . $x['name'] . ($x['river'] !== '' ? ' (' . $x['river'] . ')' : '') . ' ' . self::km($x['km']) . ': '
                        . self::wlText($x) . ($x['fresh'] ? '' : ' [ค่าวัด ' . self::when($x['dt']) . ']');
                }
                foreach (array_slice($st, 0, 12) as $x) {
                    $data['stations'][] = array('name' => $x['name'], 'river' => $x['river'], 'km' => $x['km'], 'lv' => $x['lv'],
                        'diff' => $x['diff'], 'above' => $x['above'], 'msl' => $x['msl'], 'trend' => $x['trend'], 'dt' => $x['dt']);
                }
                // สี: สถานีรัศมี 30 กม. ที่ค่าวัดไม่เก่ากว่า 6 ชม.
                $worst = 0;
                $red = false;
                foreach ($st as $x) {
                    if (!$x['fresh'] || $x['km'] > self::WATER_COLOR_KM) {
                        continue;
                    }
                    $worst = max($worst, $x['lv']);
                    if ($x['km'] <= self::WATER_NEAR_KM && $x['lv'] >= 5 && $x['above'] && $x['diff'] !== null && $x['diff'] >= 1 && $x['trend'] === 1) {
                        $red = true;
                    }
                }
                $status = $red ? 'red' : ($worst >= 5 ? 'orange' : ($worst === 4 ? 'yellow' : ($worst > 0 ? 'green' : '')));
                $src[] = 'ThaiWater ' . self::when($latest);
            }
            foreach (array_slice(self::warnFor($tw['warn'], '/ล้นตลิ่ง|ระดับน้ำ|น้ำท่วม|น้ำป่า/u'), 0, 2) as $w) {
                $L[] = 'คำเตือน สสน.' . ($w['dt'] ? ' ' . self::when($w['dt']) : '') . ': ' . $w['msg'];
            }
        }
        foreach ($this->notices(array('dam'), array(), 36, 2) as $n) {
            $L[] = 'อ่าง/การระบายน้ำ: ' . self::noticeText($n);
            $src[] = 'N-' . $n['notice_id'];
        }
        $rp = $this->reportsNear($h['lat'], $h['lng'], self::REPORT_KM, 24);
        if ($rp['n']) {
            $L[] = 'จุดแจ้งประชาชนรอบ รพ. ' . self::REPORT_KM . ' กม. (24 ชม.): ' . $rp['n'] . ' จุด · น้ำเพิ่ม ' . $rp['rising']
                . ' · ทรงตัว ' . $rp['steady'] . ' · ลด ' . $rp['falling'] . ($rp['deep'] ? ' · สูงระดับเอวขึ้นไป ' . $rp['deep'] : '');
            $src[] = 'จุดแจ้งประชาชน';
        }
        return array('status' => $status, 'lines' => $L, 'src' => $src, 'data' => $data, 'note' => $note,
            'rule' => 'สถานีรัศมี ' . self::WATER_COLOR_KM . ' กม.: ล้นตลิ่ง = ส้ม · น้ำมาก (70–100%) = เหลือง · ต่ำกว่านั้น = เขียว · สถานีใกล้ รพ. ≤'
                . self::WATER_NEAR_KM . ' กม. ล้นตลิ่ง ≥1 ม. และยังเพิ่ม = แดง');
    }

    /* ---------- ฝน ---------- */

    private function c_rain($c, $tw) {
        $h = $c['hosp'];
        $L = array();
        $src = array();
        $status = '';
        $data = null;
        $note = '';
        if ($tw['rain'] === null) {
            $note = 'ไม่ได้ดึง ThaiWater' . (isset($tw['err']['rain']) ? ' (' . $tw['err']['rain'] . ')' : '') . ' — ใช้ข้อมูลที่มีในระบบ';
        } else {
            $st = self::near($tw['rain'], $h, self::LIST_KM);
            if (!$st) {
                $L[] = 'ThaiWater: ไม่มีสถานีวัดฝนในรัศมี ' . self::LIST_KM . ' กม. จากโรงพยาบาล';
            } else {
                $latest = '';
                $nearest = null;
                foreach ($st as $x) {
                    $latest = max($latest, $x['dt']);
                    if ($x['fresh'] && ($nearest === null || $x['km'] < $nearest['km'])) {
                        $nearest = $x;
                    }
                }
                $fresh = array_values(array_filter($st, function ($x) {
                    return $x['fresh'];
                }));
                usort($fresh, function ($a, $b) {
                    return $a['r24'] == $b['r24'] ? 0 : ($a['r24'] > $b['r24'] ? -1 : 1);
                });
                $top = array();
                foreach (array_slice($fresh, 0, 3) as $x) {
                    if ($x['r24'] > 0) {
                        $top[] = $x['name'] . ($x['amphoe'] !== '' ? ' (' . $x['amphoe'] . ')' : '') . ' ' . self::mm($x['r24']);
                    }
                }
                $L[] = 'ฝนสะสม 24 ชม. ThaiWater (ถึง ' . self::when($latest) . ') รัศมี ' . self::LIST_KM . ' กม.: '
                    . ($top ? 'สูงสุด ' . implode(' · ', $top) : 'ไม่มีฝน/ฝนน้อยมาก') . ' (' . count($fresh) . ' สถานี)';
                if ($nearest) {
                    $L[] = 'สถานีใกล้ รพ.: ' . $nearest['name'] . ' ' . self::mm($nearest['r24']) . ' (' . self::km($nearest['km']) . ')';
                }
                $max24 = 0;
                $max1 = 0;
                $max1At = null;
                foreach ($fresh as $x) {
                    if ($x['km'] <= self::RAIN_COLOR_KM) {
                        $max24 = max($max24, $x['r24']);
                    }
                    if ($x['r1'] !== null && $x['r1'] > $max1) {
                        $max1 = $x['r1'];
                        $max1At = $x;
                    }
                }
                if ($max1At && $max1 >= 1) {
                    $L[] = 'ฝน 1 ชม. ล่าสุดสูงสุด ' . self::mm($max1) . ' (' . $max1At['name'] . ')';
                }
                $near1 = 0;
                foreach ($fresh as $x) {
                    if ($x['km'] <= self::RAIN_COLOR_KM && $x['r1'] !== null) {
                        $near1 = max($near1, $x['r1']);
                    }
                }
                if ($fresh) {
                    $status = $max24 >= 150 ? 'red' : (($max24 >= 90 || $near1 >= 35) ? 'orange' : (($max24 >= 35 || $near1 >= 20) ? 'yellow' : 'green'));
                }
                $data = array('max24' => $max24, 'max1' => $near1, 'stations' => array());
                foreach (array_slice($fresh, 0, 10) as $x) {
                    $data['stations'][] = array('name' => $x['name'], 'km' => $x['km'], 'r24' => $x['r24'], 'r1' => $x['r1'], 'dt' => $x['dt']);
                }
                $src[] = 'ThaiWater ' . self::when($latest);
            }
            foreach (array_slice(self::warnFor($tw['warn'], '/ฝน/u'), 0, 2) as $w) {
                $L[] = 'คำเตือน สสน.' . ($w['dt'] ? ' ' . self::when($w['dt']) : '') . ': ' . $w['msg'];
            }
        }
        // ฝนรายวันจากสถานีกรมชลประทาน (นำเข้าผ่าน rain/import — models/rain_model.php) ถ้ามี
        $rid = $this->ridRain();
        if ($rid) {
            // วางต่อจากบรรทัดหัว ThaiWater (SitRep ใช้ 2 บรรทัดแรกของสรุป)
            array_splice($L, $L ? 1 : 0, 0, array($rid['station_name'] . ' (กรมชลประทาน): ' . Rain_Model::sentence($rid) . ($rid['fresh'] ? '' : ' (ข้อมูลเก่า)')));
            $src[] = 'กรมชลประทาน ' . self::when($rid['fetched_at']);
            if ($rid['fresh'] && isset(Sat_Model::colors()[$rid['status']])) {
                $status = Sat_Model::worst($status, $rid['status']);
            }
        }
        foreach ($this->notices(array(), array('ฝน', 'อุตุ', 'พายุ', 'มรสุม'), 36, 2, true) as $n) {
            $L[] = 'ประกาศ/ข่าว: ' . self::noticeText($n);
            $src[] = 'N-' . $n['notice_id'];
        }
        return array('status' => $status, 'lines' => $L, 'src' => $src, 'data' => $data, 'note' => $note,
            'rule' => 'ฝนสะสม 24 ชม. สถานีรัศมี ' . self::RAIN_COLOR_KM . ' กม.: ≥35 มม. = เหลือง · ≥90 มม. = ส้ม · ≥150 มม. = แดง · ฝน 1 ชม. ≥20 มม. = เหลือง · ≥35 มม. = ส้ม'
                . ' · สถานีกรมชลประทาน: ฝนวันเดียว >35 / >90 มม. หรือสะสม 3 วัน ≥90 / ≥150 มม. = เหลือง / ส้ม');
    }

    /** สรุปฝนรายวันจากสถานีกรมชลประทาน (Rain_Model) — null ถ้ายังไม่มีระบบ/ข้อมูล */
    private function ridRain() {
        if (!is_file('models/rain_model.php')) {
            return null;
        }
        try {
            require_once 'models/rain_model.php';
            $rm = new Rain_Model();
            $s = $rm->ensureTable() ? $rm->summary() : null;
            return $s ? $s : null;
        } catch (Exception $e) {
            error_log('[flood] SAT compile rain (RID): ' . $e->getMessage());
            return null;
        }
    }

    /* ---------- ถนน ---------- */

    private function c_road($c, $tw) {
        $col = Sat_Model::colors();
        $L = array();
        $io = array();
        $worst = '';
        $inN = 0;     // เส้นทางเข้า รพ. ที่มีสี (ตรวจแล้ว)
        $inRed = 0;   // เส้นทางเข้า รพ. ที่ผ่านไม่ได้
        foreach ($c['routes'] as $r) {
            if (!in_array($r['rtype'], array('in', 'out'), true)) {
                continue;
            }
            $e = (string) $r['effective'];
            $io[] = ($e !== '' ? $col[$e]['emoji'] : '⚪') . ' ' . $r['name'] . self::routeHow($r);
            if ($e !== '') {
                $worst = Sat_Model::worst($worst, $e);
                if ($r['rtype'] === 'in') {
                    $inN++;
                    $inRed += $e === 'red' ? 1 : 0;
                }
            }
        }
        if ($io) {
            $L[] = 'เส้นทางเข้า–ออก รพ.: ' . implode(' · ', $io);
        }
        $rd = $c['road'];
        $line = 'กรมทางหลวงรอบ รพ. 80 กม.: ผ่านไม่ได้ ' . $rd['red'] . ' · รถเล็กผ่านไม่ได้ ' . $rd['orange'] . ' · เฝ้าระวัง ' . $rd['yellow'] . ' จุด';
        if ($c['near'] && in_array($c['near'][0]['color'], array('red', 'orange'), true)) {
            $z = $c['near'][0];
            $line .= ' · หนักสุดใกล้ รพ.: ทล.' . $z['road'] . ($z['section'] !== '' ? ' ' . $z['section'] : '') . ' (' . self::km($z['dist_km']) . ')';
        }
        $L[] = $line;
        $src = array('กรมทางหลวง (HDMS)');
        foreach ($this->notices(array('road'), array(), 24, 2) as $n) {
            $L[] = 'ข่าวเส้นทาง: ' . self::noticeText($n);
            $src[] = 'N-' . $n['notice_id'];
        }
        // เข้า รพ. ไม่ได้ทุกเส้น = แดง · บางเส้นผ่านไม่ได้ = ส้ม — เกณฑ์เดียวกับสถานะโรงพยาบาล (Sat::suggest)
        // (เดิมใช้สีแย่สุด: ถนนเข้า รพ. ขาด 1 เส้นทั้งที่อีกเส้นยังผ่านได้ → การ์ดถนนแดง → ระบบประเมินทั้ง รพ. เป็นแดง)
        $status = $worst === 'red' ? (($inN > 0 && $inRed === $inN) ? 'red' : 'orange') : $worst;
        if ($inN > 0 && $inRed === $inN) {
            $L[] = 'เข้าโรงพยาบาลไม่ได้ทุกเส้นทางที่กำหนด';
        }
        return array('status' => $status, 'lines' => $L, 'src' => $src,
            'rule' => 'เส้นทางเข้า รพ. ผ่านไม่ได้ทุกเส้น = แดง · บางเส้นผ่านไม่ได้ = ส้ม · อื่น ๆ ใช้สีแย่สุดของเส้นทางเข้า/ออก (ยืนยันหน้างานภายใน 3 ชม. ใช้แทนข้อมูลกรมทางหลวง)');
    }

    /* ---------- หน่วยบริการ ---------- */

    private function c_facility($c, $tw) {
        $f = $c['fac'];
        if (!$f['count']) {
            return array('note' => 'ยังไม่มีทะเบียนหน่วยบริการ');
        }
        $L = array();
        $L[] = 'หน่วยบริการ ' . $f['count'] . ' แห่ง: 🔴' . $f['red'] . ' 🟠' . $f['orange'] . ' 🟡' . $f['yellow'] . ' 🟢' . $f['green']
            . ($f['none'] ? ' ⚪' . $f['none'] : '')
            . ($f['overdue'] ? ' · ถึงรอบโทร ' . $f['overdue'] . ' แห่ง' : '')
            . ($f['news'] ? ' (ข้อมูลจากข่าว ยังไม่ได้โทรยืนยัน ' . $f['news'] . ')' : '');
        $closed = array();
        foreach ($f['closed'] as $x) {
            $closed[] = $x['name'] . ($x['relocated_to'] ? ' → ' . $x['relocated_to'] : '');
        }
        if ($closed) {
            $L[] = 'ปิด/ย้ายจุดบริการ: ' . implode(' · ', $closed);
        }
        $hospRed = array();
        $last = '';
        foreach ($c['facilities'] as $x) {
            if (in_array($x['ftype'], array('main', 'hospital'), true) && $x['status'] === 'red') {
                $hospRed[] = $x['name'];
            }
            if ($x['log_at'] && $x['log_source'] !== 'news') {
                $last = max($last, (string) $x['log_at']);
            }
        }
        if ($hospRed) {
            $L[] = 'โรงพยาบาลในเครือข่ายที่ปิด/ติดต่อไม่ได้: ' . implode(', ', $hospRed);
        }
        $bad = $f['red'] + $f['orange'];
        $status = $hospRed ? 'red' : ($bad >= 3 ? 'orange' : ($bad > 0 ? 'yellow' : ($f['green'] ? 'green' : '')));
        return array('status' => $status, 'lines' => $L, 'src' => array($last ? 'ผลโทรล่าสุด ' . self::when($last) : 'ทะเบียนหน่วยบริการ'),
            'rule' => 'โรงพยาบาลในเครือข่ายปิด/ติดต่อไม่ได้ = แดง · ปิด/ย้ายจุดบริการ ≥3 แห่ง = ส้ม · 1–2 แห่ง = เหลือง');
    }

    /* ---------- EMS / Refer ---------- */

    private function c_ems($c, $tw) {
        $col = Sat_Model::colors();
        $L = array();
        $list = array();
        $n = 0;
        $red = 0;
        $warn = 0;
        foreach ($c['routes'] as $r) {
            if ($r['rtype'] !== 'refer') {
                continue;
            }
            $e = (string) $r['effective'];
            $list[] = ($e !== '' ? $col[$e]['emoji'] : '⚪') . ' ' . $r['name'] . self::routeHow($r);
            if ($e === '') {
                continue;
            }
            $n++;
            $red += $e === 'red' ? 1 : 0;
            $warn += in_array($e, array('orange', 'yellow'), true) ? 1 : 0;
        }
        if ($list) {
            $L[] = 'เส้นทาง Refer: ' . implode(' · ', $list);
        }
        $src = array('เส้นทาง ③');
        if (!empty($c['refer']['ready'])) {
            foreach ($c['refer']['lines'] as $x) {
                $L[] = $x;
            }
            $src[] = 'ชีต Refer';
        }
        $status = !$n ? '' : ($red === $n ? 'red' : ($red > 0 ? 'orange' : ($warn > 0 ? 'yellow' : 'green')));
        return array('status' => $status, 'lines' => $L, 'src' => $src,
            'rule' => 'เส้นทาง Refer ผ่านไม่ได้ทุกเส้น = แดง · บางเส้น = ส้ม · ผ่านได้แต่ติดขัด/เฉพาะรถสูง = เหลือง');
    }

    /* ---------- บุคลากร ---------- */

    private function c_staff($c, $tw) {
        $L = array();
        $src = array();
        $st = $c['staff'];
        $critRed = 0;
        if (!empty($st['ready'])) {
            $a = $st['all'];
            $L[] = 'แบบสำรวจบุคลากร ' . $a['total'] . ' คน: 🔴 เดินทางไม่ได้ ' . $a['red'] . ' · 🟠 เสี่ยง ' . $a['orange']
                . ' · 🟡 ลำบาก ' . $a['yellow'] . ' · 🟢 ได้ ' . $a['green'];
            $crit = array();
            $blind = array();
            $units = Sat_Model::criticalUnits();
            foreach ($st['units'] as $code => $u) {
                $nm = isset($units[$code]) ? $units[$code]['name'] : $code;
                if ($u['red'] > 0) {
                    $crit[] = $nm . ' ' . $u['red'];
                    $critRed += $u['red'];
                }
                if (!$u['total']) {
                    $blind[] = $nm;
                }
            }
            if ($crit) {
                $L[] = 'หน่วยสำคัญที่มีคนเดินทางไม่ได้: ' . implode(', ', $crit);
            }
            if ($blind) {
                $L[] = 'หน่วยสำคัญที่ยังไม่มีผู้ตอบ (ต้องโทรถาม): ' . implode(', ', $blind);
            }
            if (!empty($st['flags'])) {
                $fl = $st['flags'];
                $L[] = 'ป้ายในระบบ: มาทำงานไม่ได้ ' . (int) $fl['cant_work'] . ' · ถูกตัดขาด/กลับบ้านไม่ได้ ' . (int) $fl['stranded']
                    . ' · ต้องการที่พักด่วน ' . (int) $fl['need_shelter'];
            }
            $fu = Sat_Model::staffFollowText($st);   // การติดตามผู้ได้รับผลกระทบ (หน้า flood/staff)
            if ($fu !== '') {
                $L[] = $fu;
            }
            $src[] = 'แบบสำรวจบุคลากร' . (!empty($st['last_at']) ? ' ' . self::when($st['last_at']) : '');
        }
        $mp = $c['mp'];
        if (!empty($mp['ready']) && $mp['units']) {
            $sh = array('M' => 'เช้า', 'A' => 'บ่าย', 'N' => 'ดึก');
            $short = array();
            foreach ($mp['short'] as $x) {
                $short[] = $x['name'] . ' ' . $x['actual'] . '/' . $x['req'];
            }
            $L[] = 'RN เวร' . (isset($sh[$mp['shift']]) ? $sh[$mp['shift']] : $mp['shift']) . ': ครบกรอบ ' . $mp['ok'] . ' · ขาด ' . count($mp['short'])
                . ' · รอข้อมูล ' . $mp['nodata'] . ' หน่วย' . ($short ? ' (' . implode(', ', array_slice($short, 0, 6)) . ')' : '');
            $src[] = 'อัตรากำลังรายเวร';
        }
        $nShort = !empty($mp['ready']) ? count($mp['short']) : 0;
        $status = '';
        if (!empty($st['ready']) || !empty($mp['ready'])) {
            $status = ($critRed >= 5 || $nShort >= 3) ? 'orange' : (($critRed > 0 || $nShort > 0) ? 'yellow' : 'green');
        }
        return array('status' => $status, 'lines' => $L, 'src' => $src,
            'rule' => 'หน่วยสำคัญเดินทางไม่ได้ ≥5 คน หรือ RN ขาดกรอบ ≥3 หน่วย = ส้ม · มีบ้าง = เหลือง');
    }

    /* ---------- ผู้ป่วยเสี่ยง ---------- */

    private function c_patient($c, $tw) {
        $L = array();
        $src = array();
        $status = '';
        $sh = $c['shelter'];
        $shFresh = false;
        if (!empty($sh['ready'])) {
            foreach ($sh['lines'] as $x) {
                $L[] = $x;
            }
            $shFresh = $sh['date'] >= date('Y-m-d', strtotime('-2 day'));
            $rows = $this->db->select(
                'SELECT shelter_name, bedridden, pregnant, COALESCE(dialysis_hd, 0) + COALESCE(dialysis_capd, 0) AS dialysis, needs
                 FROM flood_shelter_report WHERE report_date = :d ORDER BY (COALESCE(bedridden,0) + COALESCE(pregnant,0)
                 + COALESCE(dialysis_hd,0) + COALESCE(dialysis_capd,0)) DESC, people DESC LIMIT 40', array(':d' => $sh['date']));
            $care = array();
            $needs = array();
            foreach ($rows as $r) {
                $x = array_filter(array((int) $r['bedridden'] ? 'ติดเตียง ' . (int) $r['bedridden'] : '',
                    (int) $r['dialysis'] ? 'ล้างไต ' . (int) $r['dialysis'] : '', (int) $r['pregnant'] ? 'ตั้งครรภ์ ' . (int) $r['pregnant'] : ''));
                if ($x && count($care) < 4) {
                    $care[] = $r['shelter_name'] . ' (' . implode(' · ', $x) . ')';
                }
                $nd = trim(preg_replace('/\s+/u', ' ', (string) $r['needs']));
                if ($nd !== '' && $nd !== '-' && count($needs) < 2) {
                    $needs[] = mb_substr($r['shelter_name'], 0, 40) . ': ' . mb_substr($nd, 0, 80);
                }
            }
            if ($care) {
                $L[] = 'ศูนย์ที่มีผู้ต้องดูแลพิเศษ: ' . implode(' · ', $care);
            }
            if ($needs) {
                $L[] = 'สิ่งที่ศูนย์ต้องการ: ' . implode(' · ', $needs);
            }
            $src[] = 'รายงานศูนย์พักพิง ' . flood_thai_date($sh['date'], false);
            $s = $sh['sum'];
            if ($shFresh) {
                $status = ($s['bedridden'] + $s['dialysis'] + $s['pregnant']) > 0 ? 'yellow' : 'green';
            }
        }
        $v = $c['vuln'];
        if (!empty($v['ready'])) {
            if ($v['total']) {
                $L[] = 'ทะเบียนผู้ป่วยเสี่ยงรายคน ' . $v['total'] . ' ราย · อยู่ในพื้นที่น้ำท่วม ' . $v['in_zone'] . ' (ยังไม่อพยพ '
                    . $v['in_zone_waiting'] . ')' . ($v['no_location'] ? ' · ยังไม่มีพิกัด ' . $v['no_location'] : '');
                $src[] = 'ทะเบียนกลุ่มเปราะบาง';
                if ($v['in_zone_waiting'] > 0) {
                    $status = 'orange';
                } elseif ($status === '') {
                    $status = 'green';
                }
            } else {
                $L[] = 'ทะเบียนผู้ป่วยเสี่ยงรายคนยังว่าง (ฟอกไต · ออกซิเจน · ติดเตียง · ตั้งครรภ์ใกล้คลอด)';
            }
        }
        return array('status' => $status, 'lines' => $L, 'src' => $src,
            'rule' => 'ผู้ป่วยในทะเบียนอยู่ในพื้นที่น้ำท่วมแต่ยังไม่อพยพ = ส้ม · ศูนย์พักพิง (รายงานไม่เกิน 2 วัน) มีติดเตียง/ล้างไต/ตั้งครรภ์ = เหลือง');
    }

    /* ---------- ตัวโรงพยาบาล ---------- */

    private function c_hosp_site($c, $tw) {
        $h = $c['hosp'];
        $L = array();
        $src = array();
        $status = '';
        $levels = flood_zone_levels_all();
        if ($c['hospIn']) {
            foreach ($c['hospIn'] as $z) {
                $lv = Sat_Model::levelColor($z['level']);
                $L[] = 'จุดโรงพยาบาลอยู่ในพื้นที่ประกาศ: ' . $z['name'] . ' (' . (isset($levels[$z['level']]) ? $levels[$z['level']]['name'] : $z['level']) . ')';
                $status = Sat_Model::worst($status, $lv === 'red' ? 'red' : 'orange');
            }
            $src[] = 'พื้นที่ประกาศ';
        } else {
            $L[] = 'ไม่มีพื้นที่ประกาศครอบจุดโรงพยาบาล' . (!empty($h['approx']) ? ' (พิกัดโรงพยาบาลยังเป็นค่าประมาณ)' : '');
        }
        $rp = $this->reportsNear($h['lat'], $h['lng'], self::SITE_KM, 24);
        if ($rp['n']) {
            $L[] = 'จุดแจ้งประชาชนภายใน ' . self::SITE_KM . ' กม. จาก รพ. (24 ชม.): ' . $rp['n'] . ' จุด'
                . ($rp['deep'] ? ' · สูงระดับเอวขึ้นไป ' . $rp['deep'] : '') . ' · น้ำเพิ่ม ' . $rp['rising'] . ' · ลด ' . $rp['falling'];
            $src[] = 'จุดแจ้งประชาชน';
        }
        foreach ($this->notices(array(), array('ยุพราชสระแก้ว', 'รพร.สระแก้ว'), 48, 2, false) as $n) {
            $L[] = 'ข่าวเกี่ยวกับโรงพยาบาล: ' . self::noticeText($n);
            $src[] = 'N-' . $n['notice_id'];
        }
        return array('status' => $status, 'lines' => $L, 'src' => $src,
            'rule' => 'จุดโรงพยาบาลอยู่ในพื้นที่ประกาศ = ส้ม (ระดับแดง = แดง) · ระบบไม่มีข้อมูลน้ำเข้าอาคาร — ยืนยันหน้างาน');
    }

    /* ---------- ข้อมูลป่วยใน รพ. (ed_load) ---------- */

    private function c_ed_load($c, $tw) {
        $L = array();
        $src = array();
        $status = '';
        $rule = 'ไม่มีเกณฑ์สีจากระบบ — ใช้สีเดิม เลือกเองได้';
        $h = isset($c['his']) ? $c['his'] : array();
        if (!empty($h['lines'])) {
            $L = array_merge($L, $h['lines']);
        }
        if (!empty($h['ready'])) {
            $src[] = 'HOSxP ' . ($h['at'] ? self::when($h['at']) : '');
            // ER วันนี้เทียบเมื่อวานตามสัดส่วนเวลาของวัน (บอกแนวโน้มเท่านั้น ไม่ให้สี)
            $t = $h['today'];
            $y = $h['yest'];
            if ($t && $y && $y['er'] > 0 && $t['er'] !== null) {
                $frac = max(0.25, (time() - strtotime(date('Y-m-d'))) / 86400);
                $proj = (int) round($t['er'] / $frac);
                if ($frac < 0.98 && $proj > $y['er'] * 1.2) {
                    $L[] = 'ER ' . flood_thai_date($t['date'], false) . ' มีแนวโน้มสูงกว่า ' . flood_thai_date($y['date'], false)
                        . ' (คาดทั้งวัน ~' . $proj . ' / ' . $y['er'] . ')';
                }
            }
        }
        $r = $c['refer'];
        if (!empty($r['ready'])) {
            $L = array_merge($L, $r['lines']);
            if ($r['yesterday'] > 0 && $r['today'] > $r['yesterday']) {
                $L[] = 'Refer เข้า ' . flood_thai_date(date('Y-m-d'), false) . ' มากกว่า ' . flood_thai_date(date('Y-m-d', strtotime('-1 day')), false)
                    . ' (' . $r['today'] . ' / ' . $r['yesterday'] . ')';
            }
            $src[] = 'ชีต Refer';
        }
        if (!$L) {
            return array('note' => 'ยังไม่มีข้อมูล — HOSxP (hosxp-site-api) และชีต Refer ยังไม่มีข้อมูล');
        }
        return array('status' => $status, 'lines' => $L, 'src' => $src, 'rule' => $rule);
    }

    /* ---------- สาธารณูปโภค ---------- */

    private function c_util_power($c, $tw) {
        $h = $c['hosp'];
        $L = array();
        $src = array();
        $fuel = $this->utilSummary();
        if ($fuel && !empty($fuel['fuel']['items'])) {
            $cap = 0;
            $rem = 0;
            $n = 0;
            foreach ($fuel['fuel']['items'] as $it) {
                if (!preg_match('/กำเนิด|ปั่นไฟ|generator|gen/iu', (string) $it['item'])) {
                    continue;
                }
                $n++;
                $cap += (float) $it['capacity_l'];
                $rem += (float) $it['remain_l'];
            }
            if ($n && $cap > 0) {
                $L[] = 'ไฟสำรอง: น้ำมันเครื่องกำเนิดไฟฟ้า ' . $n . ' เครื่อง รวม ' . number_format($rem) . '/' . number_format($cap) . ' ลิตร ('
                    . number_format($rem * 100 / $cap) . '%) · ข้อมูล ' . flood_thai_date($fuel['fuel']['date'], false);
                $src[] = 'ชีตสาธารณูปโภค';
            }
        }
        foreach ($this->notices(array('utility'), array('ไฟฟ้า', 'กฟภ', 'ไฟดับ'), 36, 2) as $n) {
            $L[] = 'ข่าวไฟฟ้า: ' . self::noticeText($n);
            $src[] = 'N-' . $n['notice_id'];
        }
        $rp = $this->reportsNear($h['lat'], $h['lng'], self::REPORT_KM, 24, 'no_power');
        if ($rp['n']) {
            $L[] = 'ประชาชนรอบ รพ. ' . self::REPORT_KM . ' กม. แจ้งไฟดับ ' . $rp['n'] . ' จุด (24 ชม.)';
            $src[] = 'จุดแจ้งประชาชน';
        }
        return array('status' => '', 'lines' => $L, 'src' => $src,
            'rule' => 'ระบบไม่มีข้อมูลไฟฟ้าของโรงพยาบาลโดยตรง — ใช้สีเดิม เลือกเองได้');
    }

    private function c_util_water($c, $tw) {
        $h = $c['hosp'];
        $L = array();
        $src = array();
        $status = '';
        $u = isset($c['util']['items']['util_water']) ? $c['util']['items']['util_water'] : null;
        if ($u) {
            $L[] = $u['text'];   // ขึ้นต้นด้วยข้อความชุดเดียวกับหน้าสาธารณูปโภค (SitRep ตัดข้อความซ้ำได้)
            $status = $u['fresh'] ? $u['status'] : '';
            $src[] = 'ชีตสาธารณูปโภค';
        }
        foreach ($this->notices(array('utility'), array('ประปา', 'กปภ'), 48, 2) as $n) {
            $L[] = 'ข่าวประปา: ' . self::noticeText($n);
            $src[] = 'N-' . $n['notice_id'];
        }
        $rp = $this->reportsNear($h['lat'], $h['lng'], self::REPORT_KM, 24, 'no_tap');
        if ($rp['n']) {
            $L[] = 'ประชาชนรอบ รพ. ' . self::REPORT_KM . ' กม. แจ้งไม่มีน้ำประปา ' . $rp['n'] . ' จุด (24 ชม.)';
            $src[] = 'จุดแจ้งประชาชน';
        }
        return array('status' => $status, 'lines' => $L, 'src' => $src,
            'rule' => 'ถังพักน้ำ: หมดถังตั้งแต่ครึ่งหนึ่งของอาคาร = แดง · มีอาคารหมดถัง = ส้ม · ต่ำสุด <25% = เหลือง (ค่าวัดเก่ากว่า 48 ชม. ไม่ให้สี)');
    }

    private function c_util_o2($c, $tw) {
        $u = isset($c['util']['items']['util_o2']) ? $c['util']['items']['util_o2'] : null;
        if (!$u) {
            return array('note' => 'ยังไม่มีข้อมูลออกซิเจนเหลวจากชีตสาธารณูปโภค');
        }
        return array('status' => $u['fresh'] ? $u['status'] : '', 'lines' => array($u['text']), 'src' => array('ชีตสาธารณูปโภค'),
            'rule' => 'ออกซิเจนเหลวพอใช้ <1 วัน = แดง · <2 วัน = ส้ม · <3 วัน = เหลือง (ค่าวัดเก่ากว่า 48 ชม. ไม่ให้สี)');
    }

    private function c_util_fuel($c, $tw) {
        $u = isset($c['util']['items']['util_fuel']) ? $c['util']['items']['util_fuel'] : null;
        if (!$u) {
            return array('note' => 'ยังไม่มีข้อมูลน้ำมันสำรองจากชีตสาธารณูปโภค');
        }
        return array('status' => $u['fresh'] ? $u['status'] : '', 'lines' => array($u['text']), 'src' => array('ชีตสาธารณูปโภค'),
            'rule' => 'น้ำมันสำรองรวม <25% = แดง · <50% = ส้ม · <70% = เหลือง (ค่าวัดเก่ากว่า 48 ชม. ไม่ให้สี)');
    }

    private function c_util_it($c, $tw) {
        return array('note' => 'ไม่มีแหล่งข้อมูลอัตโนมัติ — พิมพ์สรุปเองถ้าต้องการบันทึก');
    }

    /* ==================== ตัวช่วย ==================== */

    /** สถานีในรัศมี $km จากโรงพยาบาล เรียงตามระยะ (+ km, fresh) */
    private static function near($list, $h, $km) {
        $out = array();
        $now = time();
        foreach ($list as $x) {
            $d = flood_haversine_m($h['lat'], $h['lng'], $x['lat'], $x['lng']) / 1000;
            if ($d > $km) {
                continue;
            }
            $x['km'] = round($d, 1);
            $x['fresh'] = $x['dt'] !== '' && ($now - strtotime($x['dt'])) <= self::FRESH_HOURS * 3600;
            $out[] = $x;
        }
        usort($out, function ($a, $b) {
            return $a['km'] == $b['km'] ? 0 : ($a['km'] < $b['km'] ? -1 : 1);
        });
        return $out;
    }

    private static function wlText($x) {
        if ($x['diff'] !== null) {
            $t = ($x['above'] ? 'ล้นตลิ่ง ' : 'ต่ำกว่าตลิ่ง ') . number_format($x['diff'], 2) . ' ม.';
            if (!$x['above'] && self::lvName($x['lv']) !== '') {
                $t .= ' (' . self::lvName($x['lv']) . ')';
            }
        } else {
            $t = self::lvName($x['lv']) !== '' ? self::lvName($x['lv']) : 'ไม่มีค่าวัด';
        }
        if ($x['trend'] === 1) {
            $t .= ' ↑เพิ่ม';
        } elseif ($x['trend'] === -1) {
            $t .= ' ↓ลด';
        } elseif ($x['trend'] === 0) {
            $t .= ' ทรงตัว';
        }
        return $t;
    }

    /** คำเตือน ThaiWater ที่ตรงเรื่อง (เบราว์เซอร์คัดเฉพาะของ จ.สระแก้ว มาแล้ว) */
    private static function warnFor($warn, $re) {
        $out = array();
        foreach ($warn as $w) {
            if (preg_match($re, $w['msg'])) {
                $out[] = array('dt' => $w['dt'], 'msg' => mb_substr($w['msg'], 0, 180));
            }
        }
        return $out;
    }

    /** ที่มาของสีเส้นทาง: ยืนยันหน้างาน / จุดกรมทางหลวงบนเส้นทาง */
    private static function routeHow($r) {
        if (!empty($r['check_fresh'])) {
            return ' (ยืนยันหน้างาน ' . date('H:i', strtotime($r['check_at'])) . ')';
        }
        if (!empty($r['hits'])) {
            return ' (กรมทางหลวง ' . count($r['hits']) . ' จุด)';
        }
        return (string) $r['effective'] === '' ? ' (ยังไม่ตรวจ)' : '';
    }

    private static function when($dt) {
        return $dt ? flood_thai_date($dt) : '-';
    }

    private static function km($v) {
        return rtrim(rtrim(number_format((float) $v, 1), '0'), '.') . ' กม.';
    }

    private static function mm($v) {
        return rtrim(rtrim(number_format((float) $v, 1), '0'), '.') . ' มม.';
    }

    private static function noticeText($n) {
        $at = $n['info_at'] ? $n['info_at'] : $n['created_at'];
        return mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $n['title'])), 0, 160)
            . ' (N-' . (int) $n['notice_id'] . ' · ' . flood_thai_date($at) . ($n['verify'] === 'unverified' ? ' · ยังไม่ยืนยัน' : '') . ')';
    }

    /**
     * ข้อมูลที่ควรรู้ล่าสุด (ยังไม่เก็บ) ภายใน $hours ชม.
     * $cats = หมวด (ว่าง = ทุกหมวด) · $words = คำในหัวเรื่อง/รายละเอียด (ว่าง = ไม่กรอง) · $sakaeo = เฉพาะที่เกี่ยวกับ จ.สระแก้ว
     */
    private function notices($cats, $words, $hours, $limit = 2, $sakaeo = true) {
        static $ok = null;
        if ($ok === null) {
            $ok = (bool) $this->db->selectValue("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'flood_notice'");
        }
        if (!$ok) {
            return array();
        }
        $w = array("n.status = 'active'", 'COALESCE(n.info_at, n.created_at) >= :since');
        $p = array(':since' => date('Y-m-d H:i:s', time() - $hours * 3600));
        if ($cats) {
            $in = array();
            foreach (array_values($cats) as $i => $cat) {
                $in[] = ':c' . $i;
                $p[':c' . $i] = $cat;
            }
            $w[] = 'n.category IN (' . implode(', ', $in) . ')';
        }
        if ($words) {
            $or = array();
            foreach (array_values($words) as $i => $wd) {
                $or[] = '(n.title LIKE :w' . $i . ' OR n.detail LIKE :w' . $i . ')';
                $p[':w' . $i] = '%' . $wd . '%';
            }
            $w[] = '(' . implode(' OR ', $or) . ')';
        }
        if ($sakaeo) {
            $w[] = "(n.amphoe_code LIKE '27%' OR n.title LIKE '%สระแก้ว%' OR n.detail LIKE '%สระแก้ว%')";
        }
        return $this->db->select('SELECT n.notice_id, n.title, n.verify, n.info_at, n.created_at FROM flood_notice n WHERE ' . implode(' AND ', $w)
            . ' ORDER BY COALESCE(n.info_at, n.created_at) DESC, n.notice_id DESC LIMIT ' . (int) $limit, $p);
    }

    /** จุดแจ้งประชาชน (ไม่นับที่ถูกปฏิเสธ) ภายใน $km กม. ในช่วง $hours ชม. — นับตามแนวโน้ม/ความลึก ($impact = กรองผลกระทบ) */
    private function reportsNear($lat, $lng, $km, $hours, $impact = '') {
        $out = array('n' => 0, 'rising' => 0, 'steady' => 0, 'falling' => 0, 'deep' => 0);
        if ($lat === null || $lng === null) {
            return $out;
        }
        $dLat = $km / 111.32;
        $dLng = $km / (111.32 * max(0.2, cos(deg2rad($lat))));
        $sql = "SELECT lat, lng, depth, trend FROM flood_report WHERE status <> 'rejected' AND created_at >= :since
                AND lat BETWEEN :a AND :b AND lng BETWEEN :c AND :d";
        $p = array(':since' => date('Y-m-d H:i:s', time() - $hours * 3600), ':a' => $lat - $dLat, ':b' => $lat + $dLat,
            ':c' => $lng - $dLng, ':d' => $lng + $dLng);
        if ($impact !== '') {
            $sql .= ' AND FIND_IN_SET(:imp, impacts) > 0';
            $p[':imp'] = $impact;
        }
        try {
            $rows = $this->db->select($sql . ' LIMIT 500', $p);
        } catch (Exception $e) {
            error_log('[flood] SAT compile reports: ' . $e->getMessage());
            return $out;
        }
        foreach ($rows as $r) {
            if (flood_haversine_m($lat, $lng, (float) $r['lat'], (float) $r['lng']) > $km * 1000) {
                continue;
            }
            $out['n']++;
            if (isset($out[$r['trend']])) {
                $out[$r['trend']]++;
            }
            if (in_array($r['depth'], array('waist', 'above_waist'), true)) {
                $out['deep']++;
            }
        }
        return $out;
    }

    /** สรุปหน้าสาธารณูปโภค (ถังน้ำมันรายเครื่อง) — null ถ้ายังไม่มีตาราง */
    private function utilSummary() {
        try {
            require_once 'models/utility_model.php';
            $um = new Utility_Model();
            return $um->ensureTables() ? $um->summary() : null;
        } catch (Exception $e) {
            error_log('[flood] SAT compile utility: ' . $e->getMessage());
            return null;
        }
    }

}
