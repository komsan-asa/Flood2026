<?php

require_once 'models/flood_model.php';

/**
 * ห้องสถานการณ์ SAT ของโรงพยาบาล (หน้า sat) — รายละเอียดข้อมูลดู models/sat_model.php
 *
 * - ใช้แถบเมนูและหน้าตาเดียวกับระบบเจ้าหน้าที่ (pageMenu = flood)
 * - เปิดดูได้: เจ้าหน้าที่ศูนย์ ผู้บริหาร (viewer) และผู้ดูแลระบบ · บันทึก/แก้ไขได้: เจ้าหน้าที่ศูนย์และผู้ดูแลระบบ
 * - ข้อมูลบุคลากร/ผู้ป่วยแสดงเป็นจำนวนรวมเท่านั้น (รายชื่ออยู่ในเมนูของแต่ละเรื่อง)
 * - ajax คืน JSON {chk, msg, ...} แบบเดียวกับ controllers/flood.php
 */
class Sat extends Controller {

    /** @var Flood_Model */
    private $flood;

    /** false = ไม่เรียก hosxp-site-api ใน collect() (อ่านแคชอย่างเดียว — หน้าสาธารณะ) */
    public $hisFetch = true;

    function __construct() {
        parent::__construct();
        $this->view->css = array('flood/css/default.css', 'flood/css/sat.css');
        $this->view->js = array('flood/js/default.js');
    }

    /** Bootstrap เรียกหลังสร้าง controller */
    public function loadModel($name) {
        $this->initModels();
        $this->guard();
    }

    private function initModels() {
        parent::loadModel('sat');
        $this->flood = new Flood_Model();
    }

    /**
     * ภาพรวมโรงพยาบาลสำหรับหน้าสาธารณะ (หน้าเข้าสู่ระบบ) — ไม่ตรวจสิทธิ์ จึงคืนเฉพาะตัวเลขรวม/สถานะ
     * ไม่มีรายชื่อบุคคล และไม่มีชื่อผู้ประกาศ · คืน null ถ้ายังไม่มีตาราง SAT
     */
    public static function publicOverview() {
        $s = new self();
        $s->initModels();
        if (!$s->model->ensureTables()) {
            return null;
        }
        $s->hisFetch = false;
        $c = $s->collect();
        list($sug, $reasons) = $s->suggest($c);
        // เหตุผลภายใน (สาธารณูปโภค/ศูนย์พักพิง — ตัวเลขปฏิบัติการของโรงพยาบาล) ไม่แสดงบนหน้าสาธารณะ และไม่นับในสีที่ระบบประเมินของหน้านั้น
        $reasons = array_values(array_filter($reasons, function ($x) {
            return empty($x[2]);
        }));
        $sug = 'green';
        foreach ($reasons as $x) {
            $sug = Sat_Model::worst($sug, $x[0]);
        }
        $d = $s->declared($c['settings']);
        $staffSum = null;
        try {
            require_once 'models/staff_model.php';
            $sm = new Staff_Model();
            if ($sm->ensureTables()) {
                $staffSum = $sm->summary();
            }
        } catch (Exception $e) {
            error_log('[flood] public hospital overview staff: ' . $e->getMessage());
        }
        $routes = array();
        foreach ($c['routes'] as $r) {
            $routes[] = array('rtype' => $r['rtype'], 'name' => $r['name'], 'is_backup' => $r['is_backup'], 'effective' => $r['effective']);
        }
        return array(
            'hosp_name' => $c['hosp']['name'],
            'declared' => $d ? array('status' => $d['status'], 'at' => $d['at']) : null,
            'suggest' => $sug,
            'reasons' => array_slice($reasons, 0, 6),
            'staffSum' => $staffSum,
            'mp' => array('ready' => $c['mp']['ready'], 'shift' => $c['mp']['shift'], 'short' => count($c['mp']['short']),
                'ok' => $c['mp']['ok'], 'nodata' => $c['mp']['nodata'], 'units' => $c['mp']['units']),
            'fac' => array_intersect_key($c['fac'], array_flip(array('count', 'green', 'yellow', 'orange', 'red', 'none', 'overdue'))),
            'routes' => $routes,
            'staff' => array('ready' => !empty($c['staff']['ready']), 'units' => isset($c['staff']['units']) ? $c['staff']['units'] : array()),
        );
    }

    /* ==================== สิทธิ์ ==================== */

    private function user() {
        return flood_session_user();
    }

    private function role() {
        $u = $this->user();
        return flood_normalize_role(isset($u['role']) ? $u['role'] : '');
    }

    private function uid() {
        $u = $this->user();
        return !empty($u['user_id']) ? (int) $u['user_id'] : null;
    }

    private function canEdit() {
        return flood_is_admin_role($this->role()) || $this->role() === 'officer';
    }

    private function isPost() {
        return isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';
    }

    private function guard() {
        if ($this->isPost() && !flood_csrf_valid()) {
            flood_json(array('chk' => false, 'csrf' => true, 'msg' => 'หน้าจอหมดอายุ กรุณารีเฟรชหน้าแล้วลองใหม่'), 403);
        }
        $u = $this->user();
        if (!empty($u['must_change_password'])) {
            if (flood_is_ajax()) {
                flood_json(array('chk' => false, 'msg' => 'กรุณาเปลี่ยนรหัสผ่านก่อนใช้งาน'));
            }
            header('Location: ' . URL . 'flood/profile');
            exit;
        }
        if (!flood_can_menu($this->role(), 'sat')) {
            $this->deny('ท่านไม่มีสิทธิ์ใช้งานส่วนนี้');
        }
        if ($this->isPost() && !$this->canEdit()) {
            $this->deny('บันทึกได้เฉพาะเจ้าหน้าที่ศูนย์หรือผู้ดูแลระบบ');
        }
    }

    private function deny($msg) {
        if ($this->isPost() || flood_is_ajax()) {
            flood_json(array('chk' => false, 'msg' => $msg), 403);
        }
        $this->view->pageMenu = 'flood';
        $this->view->blockedMessage = $msg;
        $this->view->pageTitle = 'ไม่มีสิทธิ์เข้าใช้';
        $this->view->activeTab = '';
        $this->view->rander('flood/no_permission');
        exit;
    }

    private function ready() {
        if (!$this->model->ensureTables()) {
            flood_json(array('chk' => false, 'msg' => 'ยังไม่มีตาราง SAT และระบบสร้างเองไม่ได้ — ให้ผู้ดูแลรัน php sql/apply_schema.php 19'));
        }
    }

    private static function color($v, $allowEmpty = false) {
        $v = (string) $v;
        if ($allowEmpty && $v === '') {
            return '';
        }
        return array_key_exists($v, Sat_Model::colors()) ? $v : null;
    }

    private static function txt($key, $max = 300) {
        $v = mb_substr(trim((string) flood_in($key, '', $_POST)), 0, $max);
        return $v !== '' ? $v : null;
    }

    /* ==================== รวบรวมข้อมูลทั้งหน้า ==================== */

    /** ข้อมูลทั้งหมดของหน้า — ใช้ทั้งหน้าจอและการร่าง SitRep */
    private function collect() {
        $m = $this->model;
        $settings = $m->settings();
        $hosp = $m->hospital($settings);
        $itemsDef = Sat_Model::items();
        $states = $m->itemStates();
        $now = time();

        // ตัวชี้วัด + เกินรอบอัปเดตหรือยัง
        $items = array();
        foreach ($itemsDef as $code => $def) {
            $s = isset($states[$code]) ? $states[$code] : null;
            $at = $s && $s['updated_at'] ? strtotime($s['updated_at']) : 0;
            $items[$code] = $def + array(
                'code' => $code,
                'status' => $s ? (string) $s['status'] : '',
                'note' => $s ? (string) $s['note'] : '',
                'at' => $s ? (string) $s['updated_at'] : '',
                'by' => $s ? (string) $s['by_name'] : '',
                'src' => $s && isset($s['src']) ? (string) $s['src'] : '',   // auto = มาจากปุ่ม "ดึงประมวล"
                'stale' => !$at || ($now - $at) > $def['hours'] * 3600,
            );
        }

        // ② หน่วยบริการ
        $facilities = $m->facilities();
        $fac = array('count' => count($facilities), 'green' => 0, 'yellow' => 0, 'orange' => 0, 'red' => 0, 'none' => 0,
            'overdue' => 0, 'news' => 0, 'closed' => array());
        $fHours = $itemsDef['facility']['hours'];
        foreach ($facilities as &$f) {
            $f['overdue'] = !$f['log_at'] || ($now - strtotime($f['log_at'])) > $fHours * 3600;
            $st = $f['status'] && isset($fac[$f['status']]) ? $f['status'] : 'none';
            $fac[$st]++;
            if ($f['overdue']) {
                $fac['overdue']++;
            }
            if ($f['log_source'] === 'news') {
                $fac['news']++;
            }
            if (in_array($f['status'], array('orange', 'red'), true)) {
                $fac['closed'][] = $f;
            }
        }
        unset($f);

        // ③ เส้นทาง + จุดทางหลวงจากกรมทางหลวง
        $zones = $m->hdmsZones();
        $routes = $m->routes();
        $checks = Sat_Model::routeChecks();
        foreach ($routes as &$r) {
            $segs = Sat_Model::parseSegments($r['segments']);
            $r['hits'] = $segs ? Sat_Model::routeMatches($segs, $zones) : array();
            $auto = '';
            if ($segs) {
                $auto = 'green';
                foreach ($r['hits'] as $h) {
                    $auto = Sat_Model::worst($auto, Sat_Model::levelColor($h['level']));
                }
            }
            $r['auto'] = $auto;
            $r['check_color'] = $r['check_result'] && isset($checks[$r['check_result']]) ? $checks[$r['check_result']]['color'] : '';
            $fresh = $r['check_at'] && ($now - strtotime($r['check_at'])) <= 3 * 3600;
            // ยืนยันหน้างานภายใน 3 ชม. ใช้แทนข้อมูลกรมทางหลวง (หน้างานใหม่กว่า) · เก่ากว่านั้นใช้ข้อมูลกรมทางหลวง
            $r['check_fresh'] = $fresh;
            $r['effective'] = $fresh ? $r['check_color'] : ($auto !== '' ? $auto : $r['check_color']);
        }
        unset($r);

        // จุดทางหลวงรอบโรงพยาบาล (80 กม.) เรียงตามความรุนแรงแล้วระยะทาง
        $near = array();
        foreach ($zones as $z) {
            $d = flood_haversine_m($hosp['lat'], $hosp['lng'], $z['center_lat'], $z['center_lng']);
            if ($d <= 80000) {
                $z['dist_km'] = round($d / 1000, 1);
                $z['color'] = Sat_Model::levelColor($z['level']);
                $near[] = $z;
            }
        }
        usort($near, function ($a, $b) {
            $o = Sat_Model::rank($b['color']) - Sat_Model::rank($a['color']);
            if ($o !== 0) {
                return $o;
            }
            return $a['dist_km'] == $b['dist_km'] ? 0 : ($a['dist_km'] < $b['dist_km'] ? -1 : 1);
        });
        $road = array('red' => 0, 'orange' => 0, 'yellow' => 0, 'green' => 0);
        foreach ($near as $z) {
            $road[$z['color']]++;
        }

        // โรงพยาบาลอยู่ในพื้นที่ประกาศหรือไม่
        $mapZones = $m->nearbyZones($hosp['lat'], $hosp['lng'], 60);
        $hospIn = array();
        foreach ($mapZones as $z) {
            if (flood_point_in_zone($hosp['lat'], $hosp['lng'], flood_zone_prepare($z))) {
                $hospIn[] = $z;
            }
        }

        // ④ บุคลากร + อัตรากำลังเวรนี้ + ผู้ป่วยเสี่ยง
        $staff = $m->staffSummary();
        $staffCrit = array('red' => 0, 'orange' => 0);
        foreach ($staff['units'] as $u) {
            $staffCrit['red'] += $u['red'];
            $staffCrit['orange'] += $u['orange'];
        }
        $mp = $this->manpowerNow();
        $vuln = $this->vulnerableNow();
        // ข้อมูลจากหน้าอื่นของโรงพยาบาล: สาธารณูปโภค · Refer เข้า · กลุ่มเปราะบางในศูนย์พักพิง
        $util = $this->utilityNow();
        $refer = $this->referNow();
        $shelter = $this->shelterNow();
        // ER / IPD / OPD / Refer จาก HOSxP (hosxp-site-api · แคช 10 นาที)
        $his = $this->hisNow($settings);

        return compact('settings', 'hosp', 'items', 'facilities', 'fac', 'routes', 'near', 'road', 'mapZones', 'hospIn',
            'staff', 'staffCrit', 'mp', 'vuln', 'util', 'refer', 'shelter', 'his');
    }

    /** RN เวรปัจจุบัน: หน่วยที่ขาด / ยังไม่มีข้อมูล (หน้า flood/manpower) */
    private function manpowerNow() {
        $out = array('ready' => false, 'shift' => '', 'short' => array(), 'nodata' => 0, 'ok' => 0, 'units' => 0);
        try {
            require_once 'models/manpower_model.php';
            $mp = new Manpower_Model();
            if (!$mp->ensureTables()) {
                return $out;
            }
            $date = date('Y-m-d');
            $units = $mp->units();
            $day = $mp->dayInfo($date);
            $matrix = $mp->matrix($date, $units, $day['holiday']);
            $shift = Manpower_Model::currentShift();
            $out['ready'] = true;
            $out['shift'] = $shift;
            foreach ($units as $u) {
                $c = isset($matrix[(int) $u['unit_id']][$shift]) ? $matrix[(int) $u['unit_id']][$shift] : null;
                if (!$c || $c['status'] === 'none') {
                    continue;
                }
                $out['units']++;
                if ($c['status'] === 'short') {
                    $out['short'][] = array('name' => $u['unit_name'], 'req' => (int) $c['req'], 'actual' => (int) $c['actual']);
                } elseif ($c['status'] === 'nodata') {
                    $out['nodata']++;
                } else {
                    $out['ok']++;
                }
            }
        } catch (Exception $e) {
            error_log('[flood] SAT manpower: ' . $e->getMessage());
        }
        return $out;
    }

    /** ทะเบียนกลุ่มเปราะบาง: จำนวนรวม/ในพื้นที่ประกาศ/รายกลุ่ม (ไม่ส่งรายชื่อ) */
    private function vulnerableNow() {
        $out = array('ready' => false, 'total' => 0, 'in_zone' => 0, 'in_zone_waiting' => 0, 'no_location' => 0, 'groups' => array());
        try {
            $sum = $this->flood->vulnerableSummary();
            $out = array_merge($out, $sum);
            $out['ready'] = true;
            $names = flood_vulnerable_groups();
            foreach ($this->flood->db->select('SELECT vuln_groups FROM flood_vulnerable WHERE is_active = 1') as $r) {
                foreach (array_filter(explode(',', (string) $r['vuln_groups'])) as $g) {
                    $k = isset($names[$g]) ? $names[$g] : $g;
                    $out['groups'][$k] = (isset($out['groups'][$k]) ? $out['groups'][$k] : 0) + 1;
                }
            }
            arsort($out['groups']);
        } catch (Exception $e) {
            error_log('[flood] SAT vulnerable: ' . $e->getMessage());
        }
        return $out;
    }

    /**
     * สาธารณูปโภค (หน้า sat/utility · ชีตงานช่าง) → ข้อความ + สีที่ระบบแนะนำ ของตัวชี้วัด util_o2 / util_fuel / util_water
     * เกณฑ์ (ข้อเสนอ — SAT เป็นผู้ตัดสิน):
     *   ออกซิเจนเหลวพอใช้ < 1 วัน แดง · < 2 วัน ส้ม · < 3 วัน เหลือง
     *   น้ำมันสำรองรวม < 25% แดง · < 50% ส้ม · < 70% เหลือง
     *   ถังพักน้ำ หมดถังตั้งแต่ครึ่งหนึ่งของอาคาร แดง · มีอาคารหมดถัง ส้ม · ต่ำสุด < 25% เหลือง
     *   ค่าวัดเก่ากว่า 48 ชม. ไม่ให้สี (แสดงข้อความพร้อมวันที่เท่านั้น)
     */
    private function utilityNow() {
        $out = array('ready' => false, 'imported_at' => null, 'items' => array());
        try {
            require_once 'models/utility_model.php';
            $um = new Utility_Model();
            if (!$um->ensureTables()) {
                return $out;
            }
            $s = $um->summary();
        } catch (Exception $e) {
            error_log('[flood] SAT utility: ' . $e->getMessage());
            return $out;
        }
        $out['ready'] = true;
        $out['imported_at'] = $s['imported_at'];
        $num = function ($v, $dec = 0) {
            return number_format((float) $v, $dec);
        };
        $when = function ($date, $slot = '') {
            return flood_thai_date($date, false) . ($slot !== '' && $slot !== '00:00' ? ' ' . $slot . ' น.' : '');
        };
        $fresh = function ($date, $slot = '') {
            return (time() - strtotime($date . ' ' . ($slot !== '' ? $slot : '23:59'))) <= 48 * 3600;
        };

        // ออกซิเจนเหลว: ค่าล่าสุด + อัตราใช้ → พอใช้อีกกี่วัน
        if ($s['oxygen']) {
            $o = $s['oxygen'];
            $last = $o['last'];
            $days = $o['days_left'];
            $color = '';
            if ($days !== null && $fresh($last['log_date'], (string) $last['slot'])) {
                $color = $days < 1 ? 'red' : ($days < 2 ? 'orange' : ($days < 3 ? 'yellow' : 'green'));
            }
            $out['items']['util_o2'] = array('status' => $color, 'fresh' => $fresh($last['log_date'], (string) $last['slot']),
                'text' => 'ออกซิเจนเหลว ' . $num($last['volume_m3']) . ' ลบ.ม. (' . $when($last['log_date'], (string) $last['slot']) . ')'
                    . ($o['rate_day'] ? ' · ใช้ ~' . $num($o['rate_day']) . ' ลบ.ม./วัน · พอใช้อีก ~' . $num($days, 1) . ' วัน' : ''),
                'value' => $num($last['volume_m3']) . ' ลบ.ม.',
                'sub' => ($days !== null ? 'พอใช้อีก ~' . $num($days, 1) . ' วัน · ' : '') . $when($last['log_date'], (string) $last['slot']));
        }

        // น้ำมันสำรอง (เครื่องกำเนิดไฟฟ้า + ถังสำรอง) ของวันล่าสุด
        if ($s['fuel'] && $s['fuel']['pct'] !== null) {
            $f = $s['fuel'];
            $pct = (float) $f['pct'];
            $lowItem = null;
            foreach ($f['items'] as $it) {
                if ((float) $it['capacity_l'] > 0 && $it['remain_l'] !== null) {
                    $p = (float) $it['remain_l'] * 100 / (float) $it['capacity_l'];
                    if ($lowItem === null || $p < $lowItem[1]) {
                        $lowItem = array((string) $it['item'], $p);
                    }
                }
            }
            $color = $fresh($f['date']) ? ($pct < 25 ? 'red' : ($pct < 50 ? 'orange' : ($pct < 70 ? 'yellow' : 'green'))) : '';
            $out['items']['util_fuel'] = array('status' => $color, 'fresh' => $fresh($f['date']),
                'text' => 'น้ำมันสำรอง ' . $num($f['remain']) . '/' . $num($f['capacity']) . ' ลิตร (' . $num($pct) . '%) · ' . $when($f['date'])
                    . ($lowItem && $lowItem[1] < 50 ? ' · ต่ำสุด ' . $lowItem[0] . ' ' . $num($lowItem[1]) . '%' : ''),
                'value' => $num($pct) . '%',
                'sub' => $num($f['remain']) . '/' . $num($f['capacity']) . ' ลิตร · ' . $when($f['date']));
        }

        // ถังพักน้ำ: ค่าล่าสุดของแต่ละอาคาร (หมดถัง = 0%) + รถเติมน้ำวันล่าสุด
        if ($s['water'] && $s['water']['buildings']) {
            $empty = array();
            $low = array();
            $min = null;
            $latest = '';
            $n = 0;
            foreach ($s['water']['buildings'] as $b) {
                $vals = array();
                foreach (array('lower_pct', 'upper_pct') as $k) {
                    if ($b[$k] !== null) {
                        $vals[] = (float) $b[$k];
                    }
                }
                $p = ((int) $b['lower_empty'] || (int) $b['upper_empty']) ? 0.0 : ($vals ? min($vals) : null);
                if ($p === null) {
                    continue;
                }
                $n++;
                if ($p <= 0) {
                    $empty[] = (string) $b['building'];
                } elseif ($p < 25) {
                    $low[] = $b['building'] . ' ' . $num($p) . '%';
                }
                $min = $min === null ? $p : min($min, $p);
                $at = $b['log_date'] . ' ' . $b['slot'];
                if ($at > $latest) {
                    $latest = $at;
                }
            }
            if ($n) {
                list($ld, $ls) = explode(' ', $latest);
                $color = '';
                if ($fresh($ld, $ls)) {
                    $color = count($empty) >= max(2, ceil($n / 2)) ? 'red' : ($empty ? 'orange' : ($min < 25 ? 'yellow' : 'green'));
                }
                $de = $s['delivery'];
                $out['items']['util_water'] = array('status' => $color, 'fresh' => $fresh($ld, $ls),
                    'text' => 'ถังพักน้ำ ' . $n . ' อาคาร (ล่าสุด ' . $when($ld, $ls) . ')'
                        . ($empty ? ' · หมดถัง: ' . implode(', ', $empty) : '')
                        . ($low ? ' · ต่ำกว่า 25%: ' . implode(', ', $low) : '')
                        . (!$empty && !$low ? ' · ต่ำสุด ' . $num($min) . '%' : '')
                        . ($de ? ' · รถเติมน้ำ ' . $when($de['date']) . ' ' . $num($de['days'][0]['liters']) . ' ล./' . (int) $de['days'][0]['trips'] . ' เที่ยว' : ''),
                    'value' => $empty ? 'หมดถัง ' . count($empty) . ' อาคาร' : 'ต่ำสุด ' . $num($min) . '%',
                    'sub' => $n . ' อาคาร · ' . $when($ld, $ls));
            }
        }
        return $out;
    }

    /** Refer เข้า รพ. ช่วงอุทกภัย (หน้า sat/refer) — ตัวเลขรวมเท่านั้น ไม่ส่งชื่อผู้ป่วย/Dx ออกไป */
    private function referNow() {
        $out = array('ready' => false, 'total' => 0, 'today' => 0, 'yesterday' => 0, 'ett' => 0, 'o2' => 0, 'since' => '', 'lines' => array());
        try {
            require_once 'models/refer_model.php';
            $rm = new Refer_Model();
            if (!$rm->ensureTables()) {
                return $out;
            }
            $s = $rm->summary();
            unset($s['rows']);
        } catch (Exception $e) {
            error_log('[flood] SAT refer: ' . $e->getMessage());
            return $out;
        }
        if (!$s['total'] && !$s['imported_at']) {
            return $out;
        }
        $y = date('Y-m-d', strtotime('-1 day'));
        foreach ($s['byDate'] as $r) {
            if ($r['d'] === $y) {
                $out['yesterday'] = (int) $r['c'];
            }
        }
        $first = $s['byDate'] ? $s['byDate'][count($s['byDate']) - 1]['d'] : '';
        $out = array_merge($out, array('ready' => true, 'total' => (int) $s['total'], 'today' => (int) $s['today'],
            'ett' => (int) $s['ett'], 'o2' => (int) $s['o2'], 'since' => $first));
        $out['lines'][] = 'Refer เข้า รพ. ช่วงอุทกภัย ' . $out['total'] . ' ราย' . ($first ? ' ตั้งแต่ ' . flood_thai_date($first, false) : '')
            . ' (' . flood_thai_date(date('Y-m-d'), false) . ' ' . $out['today'] . ' · ' . flood_thai_date($y, false) . ' ' . $out['yesterday'] . ')'
            . (!empty($s['imported_at']) ? ' · ข้อมูลชีต ณ ' . flood_thai_date($s['imported_at']) : '');
        $hosp = array();
        foreach (array_slice($s['byHosp'], 0, 3) as $r) {
            $hosp[] = $r['name'] . ' ' . (int) $r['c'];
        }
        $out['lines'][] = 'on ET tube ' . $out['ett'] . ' · on O2 ' . $out['o2'] . ($hosp ? ' · ต้นทาง ' . implode(', ', $hosp) : '');
        return $out;
    }

    /** ตัวเลขผู้รับบริการวันนี้/เมื่อวานจาก HOSxP ผ่าน hosxp-site-api (models/hosxp_api_model.php) — ไม่มีข้อมูลรายบุคคล */
    private function hisNow($settings) {
        $out = array('ready' => false, 'configured' => false, 'today' => null, 'yest' => null, 'at' => '', 'error' => '', 'lines' => array());
        if (!is_file('models/hosxp_api_model.php')) {
            return $out;
        }
        try {
            require_once 'models/hosxp_api_model.php';
            $out = array_merge($out, HosxpApi::daily($this->model, $settings, $this->hisFetch));
            $out['lines'] = HosxpApi::lines($out);
        } catch (Exception $e) {
            error_log('[flood] SAT HOSxP: ' . $e->getMessage());
        }
        return $out;
    }

    /** กลุ่มเปราะบางในศูนย์พักพิง (ชีตรายงานศูนย์พักพิง ที่ดึงในหน้า flood/vulnerable) — ตัวเลขรวมของวันที่รายงานล่าสุด */
    private function shelterNow() {
        $out = array('ready' => false, 'date' => '', 'sum' => array(), 'lines' => array());
        try {
            $db = $this->flood->db;
            if (!$db->selectValue("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'flood_shelter_report'")) {
                return $out;
            }
            $d = $db->selectValue('SELECT MAX(report_date) FROM flood_shelter_report');
            if (!$d) {
                return $out;
            }
            $sum = $db->selectOne('SELECT COUNT(*) shelters, SUM(people) people, SUM(elderly) elderly, SUM(disabled) disabled, SUM(child) child,
                SUM(bedridden) bedridden, SUM(pregnant) pregnant, SUM(dialysis_hd) dialysis_hd, SUM(dialysis_capd) dialysis_capd,
                SUM(mental) mental, SUM(chronic) chronic FROM flood_shelter_report WHERE report_date = :d', array(':d' => $d));
        } catch (Exception $e) {
            error_log('[flood] SAT shelter: ' . $e->getMessage());
            return $out;
        }
        $sum = array_map('intval', $sum);
        $dz = $sum['dialysis_hd'] + $sum['dialysis_capd'];
        $out = array_merge($out, array('ready' => true, 'date' => $d, 'sum' => $sum + array('dialysis' => $dz)));
        $out['lines'][] = 'ศูนย์พักพิง ' . flood_thai_date($d, false) . ($d !== date('Y-m-d') ? ' (ยังไม่มีรายงานวันนี้)' : '') . ': '
            . $sum['shelters'] . ' ศูนย์ · ผู้พักพิง ' . number_format($sum['people']) . ' คน';
        $out['lines'][] = 'ติดเตียง ' . $sum['bedridden'] . ' · ผู้สูงอายุ ' . $sum['elderly'] . ' · พิการ ' . $sum['disabled'] . ' · เด็ก 0–5 ปี ' . $sum['child']
            . ' · ตั้งครรภ์ ' . $sum['pregnant'] . ' · ล้างไต ' . $dz . ' · โรคเรื้อรัง ' . $sum['chronic'] . ($sum['mental'] ? ' · สุขภาพจิต ' . $sum['mental'] : '');
        return $out;
    }

    /** ระบบประเมินสถานะโรงพยาบาลจากข้อมูล (ข้อเสนอ — SAT เป็นผู้ประกาศ) → [สี, [[สี, เหตุผล], ...]] */
    private function suggest($c) {
        $col = Sat_Model::colors();
        $reasons = array();
        foreach ($c['items'] as $it) {
            if (in_array($it['status'], array('yellow', 'orange', 'red'), true)) {
                $reasons[] = array($it['status'], $it['name'] . ': ' . $col[$it['status']]['name']
                    . ($it['note'] !== '' ? ' — ' . mb_strimwidth(preg_replace('/\s+/u', ' ', $it['note']), 0, 180, '…', 'UTF-8') : '')
                    . ($it['stale'] ? ' (เกินรอบอัปเดต)' : ''));
            }
        }
        $refer = 0;
        $referBad = 0;
        foreach ($c['routes'] as $r) {
            if ($r['rtype'] === 'refer') {
                $refer++;
                if ($r['effective'] === 'red') {
                    $referBad++;
                }
            }
            if ($r['effective'] === 'red' && $r['rtype'] !== 'refer') {
                $reasons[] = array('orange', 'ถนนเข้า–ออกโรงพยาบาลบางเส้นทางใช้ไม่ได้: ' . $r['name']);
            }
        }
        if ($refer > 0 && $referBad === $refer) {
            $reasons[] = array('red', 'Refer ออกจากโรงพยาบาลไม่ได้ทุกเส้นทางที่กำหนด');
        } elseif ($referBad > 0) {
            $reasons[] = array('orange', 'รถ Refer บางเส้นทางผ่านไม่ได้ (' . $referBad . ' จาก ' . $refer . ' เส้นทาง)');
        }
        $bad = $c['fac']['red'] + $c['fac']['orange'];
        if ($bad >= 3) {
            $reasons[] = array('orange', 'หน่วยบริการในเครือข่ายปิด/ย้ายจุดบริการหลายแห่ง (' . $bad . ' แห่ง)');
        } elseif ($bad > 0) {
            $reasons[] = array('yellow', 'หน่วยบริการเริ่มปิด/ย้ายจุดบริการ (' . $bad . ' แห่ง)');
        }
        if ($c['staffCrit']['red'] >= 5) {
            $reasons[] = array('orange', 'บุคลากรหน่วยสำคัญเดินทางมาไม่ได้ ' . $c['staffCrit']['red'] . ' คน');
        } elseif ($c['staffCrit']['red'] > 0) {
            $reasons[] = array('yellow', 'บุคลากรหน่วยสำคัญบางส่วนเดินทางมาไม่ได้ (' . $c['staffCrit']['red'] . ' คน)');
        }
        if ($c['mp']['ready'] && count($c['mp']['short']) >= 3) {
            $reasons[] = array('orange', 'พยาบาลเวรนี้ขาดกรอบ ' . count($c['mp']['short']) . ' หน่วย');
        } elseif ($c['mp']['ready'] && count($c['mp']['short']) > 0) {
            $reasons[] = array('yellow', 'พยาบาลเวรนี้ขาดกรอบ ' . count($c['mp']['short']) . ' หน่วย');
        }
        if ($c['vuln']['in_zone_waiting'] > 0) {
            $reasons[] = array('yellow', 'ผู้ป่วยเสี่ยงอยู่ในพื้นที่น้ำท่วม ยังไม่อพยพ ' . $c['vuln']['in_zone_waiting'] . ' ราย');
        }
        // สาธารณูปโภคจากชีตงานช่าง (สีที่ระบบคำนวณ — แยกจากสีที่ SAT กดเอง)
        foreach ($c['util']['items'] as $code => $u) {
            // SAT กดใช้ข้อมูลนี้แล้ว (สรุปของตัวชี้วัดมีข้อความเดียวกัน) → เหตุผลจากตัวชี้วัดด้านบนครอบคลุมแล้ว ไม่ซ้ำ
            if (in_array($u['status'], array('yellow', 'orange', 'red'), true) && strpos($c['items'][$code]['note'], $u['text']) === false) {
                $reasons[] = array($u['status'], $c['items'][$code]['name'] . ' (ข้อมูลสาธารณูปโภค): ' . $u['text'], 'internal');
            }
        }
        // กลุ่มเปราะบางในศูนย์พักพิงที่อาจต้องรับเข้าโรงพยาบาล (รายงานไม่เกิน 2 วัน)
        $sh = $c['shelter'];
        if ($sh['ready'] && $sh['date'] >= date('Y-m-d', strtotime('-2 day'))) {
            $x = array_filter(array($sh['sum']['bedridden'] ? 'ติดเตียง ' . $sh['sum']['bedridden'] : '',
                $sh['sum']['dialysis'] ? 'ล้างไต ' . $sh['sum']['dialysis'] : '', $sh['sum']['pregnant'] ? 'ตั้งครรภ์ ' . $sh['sum']['pregnant'] : ''));
            if ($x) {
                $reasons[] = array('yellow', 'กลุ่มเปราะบางในศูนย์พักพิง ' . $sh['sum']['shelters'] . ' ศูนย์ (' . flood_thai_date($sh['date'], false) . '): ' . implode(' · ', $x), 'internal');
            }
        }
        foreach ($c['hospIn'] as $z) {
            $lv = Sat_Model::levelColor($z['level']);
            $reasons[] = array($lv === 'red' ? 'red' : 'orange', 'จุดที่ตั้งโรงพยาบาลอยู่ในพื้นที่ประกาศ: ' . $z['name']);
        }
        $best = '';
        foreach ($reasons as $x) {
            $best = Sat_Model::worst($best, $x[0]);
        }
        usort($reasons, function ($a, $b) {
            return Sat_Model::rank($b[0]) - Sat_Model::rank($a[0]);
        });
        return array($best !== '' ? $best : 'green', $reasons);
    }

    /**
     * สถานะที่ SAT ประกาศล่าสุด — การกด "ประกาศสถานะ" หรือการออก SitRep (สถานะในฉบับนั้น) แล้วแต่อันไหนใหม่กว่า
     */
    private function declared($settings) {
        $o = isset($settings['overall']) ? json_decode((string) $settings['overall']['sval'], true) : null;
        if (!is_array($o) || !self::color(isset($o['status']) ? $o['status'] : '')) {
            $o = null;
        } else {
            $o['at'] = (string) $settings['overall']['updated_at'];
            $o['by'] = (string) $settings['overall']['by_name'];
        }
        try {
            $last = null;
            foreach ($this->model->sitreps(5) as $r) {
                if (self::color((string) $r['overall'])) {
                    $last = $r;
                    break;
                }
            }
            if ($last && (!$o || (string) $last['created_at'] > $o['at'])) {
                $reason = '';
                $full = $this->model->getSitrep((int) $last['sitrep_id']);
                if ($full && preg_match('/^เหตุผล:\s*(.+)$/mu', (string) $full['body'], $m)) {
                    $reason = trim($m[1]);
                }
                return array('status' => $last['overall'], 'at' => (string) $last['created_at'], 'by' => (string) $last['by_name'],
                    'reason' => $reason,
                    'sitrep_no' => (int) $last['report_no']);
            }
        } catch (Exception $e) {
            // ยังไม่มีตาราง SitRep — ใช้การประกาศอย่างเดียว
        }
        return $o;
    }

    /* ==================== หน้าจอ ==================== */

    function index() {
        $ready = $this->model->ensureTables();
        $this->view->pageMenu = 'flood';
        $this->view->activeTab = 'sat';
        $this->view->pageTitle = 'ห้องสถานการณ์ SAT';
        $this->view->autoRefresh = false;   // มีฟอร์ม/ร่าง SitRep — ไม่รีเฟรชเองกันข้อความหาย (กดรีเฟรชเองได้)
        $this->view->satReady = $ready;
        if (!$ready) {
            $this->view->rander('flood/sat');
            return;
        }
        $c = $this->collect();
        list($sug, $reasons) = $this->suggest($c);
        $this->view->useMap = true;
        $this->view->js[] = 'flood/js/sat.js';
        $this->view->js[] = 'flood/js/sat_sitrep_view.js';   // SitRep แบบตาราง + ประวัติ SitRep ในกล่องสถานะ
        $this->view->css[] = 'flood/css/sat_sitrep.css';
        if ($this->canEdit()) {
            // ปุ่ม "ดึงประมวล": ตัวอ่านชีตชุดเดียวกับหน้ากลุ่มเปราะบาง (sheet_pull.js) / สาธารณูปโภค (utility.js) + ThaiWater
            $this->view->js[] = 'flood/js/sheet_pull.js';
            $this->view->js[] = 'flood/js/utility.js';
            $this->view->js[] = 'flood/js/sat_compile.js';
            $this->view->satPull = $this->pullConfig($c);
        }
        $this->view->sat = $c;
        $this->view->satSuggest = $sug;
        $this->view->satReasons = $reasons;
        $this->view->satDeclared = $this->declared($c['settings']);
        // ตัวชี้วัดที่สีเปลี่ยนหลังประกาศสถานะ (เทียบบันทึกล่าสุดก่อนเวลาประกาศ) — กล่องสถานะขึ้นเตือนให้ประกาศใหม่
        $declChg = array();
        $dcl = $this->view->satDeclared;
        if ($dcl && !empty($dcl['at'])) {
            try {
                $before = $this->model->itemStatusAt($dcl['at']);
                foreach ($c['items'] as $code => $it) {
                    $old = isset($before[$code]) ? (string) $before[$code] : '';
                    if (!empty($it['at']) && (string) $it['at'] > $dcl['at'] && (string) $it['status'] !== $old) {
                        $declChg[] = array('code' => $code, 'name' => $it['name'], 'old' => $old, 'new' => (string) $it['status']);
                    }
                }
            } catch (Exception $e) {
                error_log('[flood] SAT declChanges: ' . $e->getMessage());
            }
        }
        $this->view->satDeclChanges = $declChg;
        $this->view->satSitreps = $this->model->sitreps(20);
        $this->view->satNextNo = $this->model->nextSitrepNo();
        $this->view->satCanEdit = $this->canEdit();
        $this->view->satAmphoes = $this->model->amphoes();
        $this->view->satJs = $this->mapData($c);
        $this->view->rander('flood/sat');
    }

    /** ภาพรวม (โรงพยาบาล) — ตัวเลขรวมจาก SAT / บุคลากร / อัตรากำลัง ไม่มีรายชื่อ (เมนู "ภาพรวม (โรงพยาบาล)") */
    function overview() {
        $ready = $this->model->ensureTables();
        $this->view->pageMenu = 'flood';
        $this->view->activeTab = 'hospital';
        $this->view->pageTitle = 'ภาพรวม (โรงพยาบาล)';
        $this->view->satReady = $ready;
        $staffSum = null;
        try {
            require_once 'models/staff_model.php';
            $sm = new Staff_Model();
            if ($sm->ensureTables()) {
                $staffSum = $sm->summary();
            }
        } catch (Exception $e) {
            error_log('[flood] hospital overview staff: ' . $e->getMessage());
        }
        $this->view->hStaff = $staffSum;
        if ($ready) {
            $c = $this->collect();
            list($sug, $reasons) = $this->suggest($c);
            $this->view->sat = $c;
            $this->view->satSuggest = $sug;
            $this->view->satReasons = $reasons;
            $this->view->satDeclared = $this->declared($c['settings']);
        }
        $this->view->rander('flood/hospital');
    }

    /** ข้อมูลแผนที่สำหรับ sat.js */
    private function mapData($c) {
        $zones = array();
        foreach ($c['mapZones'] as $z) {
            $poly = $z['shape'] === 'polygon' ? flood_parse_polygon($z['polygon_json']) : null;
            $zones[] = array(
                'id' => (int) $z['zone_id'], 'name' => $z['name'], 'level' => $z['level'], 'source' => $z['source'],
                'lat' => (float) $z['center_lat'], 'lng' => (float) $z['center_lng'], 'r' => (int) $z['radius_m'],
                'poly' => $poly ?: null,
                'at' => flood_thai_date($z['updated_at'] ?: $z['started_at']),
            );
        }
        $fac = array();
        foreach ($c['facilities'] as $f) {
            if ($f['lat'] === null || $f['lng'] === null) {
                continue;
            }
            $fac[] = array(
                'id' => (int) $f['facility_id'], 'name' => $f['name'], 'type' => $f['ftype'],
                'lat' => (float) $f['lat'], 'lng' => (float) $f['lng'], 'approx' => (int) $f['loc_approx'] === 1,
                'status' => (string) $f['status'], 'service' => (string) $f['service'],
                'at' => $f['log_at'] ? flood_thai_date($f['log_at']) : '', 'overdue' => $f['overdue'],
            );
        }
        return array('hosp' => $c['hosp'], 'zones' => $zones, 'facilities' => $fac, 'colors' => Sat_Model::colors());
    }

    /* ==================== บันทึก: ตัวชี้วัด / สถานะรวม / โรงพยาบาล ==================== */

    function itemSave() {
        $this->ready();
        $code = (string) flood_in('code', '', $_POST);
        $items = Sat_Model::items();
        if (!isset($items[$code])) {
            flood_json(array('chk' => false, 'msg' => 'ไม่พบตัวชี้วัดนี้'));
        }
        $status = self::color(flood_in('status', '', $_POST), true);
        if ($status === null) {
            flood_json(array('chk' => false, 'msg' => 'กรุณาเลือกสี'));
        }
        $note = mb_substr(trim((string) flood_in('note', '', $_POST)), 0, 2000);
        $this->model->saveItem($code, $status, $note, $this->uid());
        flood_json(array('chk' => true, 'msg' => 'บันทึก ' . $items[$code]['name'] . ' แล้ว'));
    }

    function itemHistory($code = '') {
        $this->ready();
        $items = Sat_Model::items();
        $code = (string) $code;
        if ($code !== 'overall' && !isset($items[$code])) {
            flood_json(array('chk' => false, 'msg' => 'ไม่พบตัวชี้วัดนี้'));
        }
        $rows = array();
        foreach ($this->model->itemHistory($code, 30) as $r) {
            $rows[] = array('status' => $r['status'], 'note' => (string) $r['note'], 'by' => (string) $r['by_name'],
                'at' => flood_thai_date($r['created_at']), 'src' => isset($r['src']) ? (string) $r['src'] : '');
        }
        flood_json(array('chk' => true, 'rows' => $rows));
    }

    function overallSave() {
        $this->ready();
        $status = self::color(flood_in('status', '', $_POST));
        if (!$status) {
            flood_json(array('chk' => false, 'msg' => 'กรุณาเลือกสถานะ'));
        }
        $reason = mb_substr(trim((string) flood_in('reason', '', $_POST)), 0, 1000);
        $this->model->setSetting('overall', json_encode(array('status' => $status, 'reason' => $reason), JSON_UNESCAPED_UNICODE), $this->uid());
        $this->model->saveItem('overall', $status, $reason, $this->uid());   // เก็บประวัติการประกาศ
        $col = Sat_Model::colors();
        flood_json(array('chk' => true, 'msg' => 'ประกาศสถานะโรงพยาบาล ' . $col[$status]['label'] . ' แล้ว'));
    }

    function hospitalSave() {
        $this->ready();
        $name = mb_substr(trim((string) flood_in('name', '', $_POST)), 0, 150);
        $lat = flood_in('lat', '', $_POST);
        $lng = flood_in('lng', '', $_POST);
        $hasLL = $lat !== '' || $lng !== '';
        if ($hasLL && !flood_valid_latlng($lat, $lng)) {
            flood_json(array('chk' => false, 'msg' => 'พิกัดไม่ถูกต้อง'));
        }
        if ($name !== '') {
            $this->model->setSetting('hosp_name', $name, $this->uid());
        }
        if ($hasLL) {
            $this->model->setSetting('hosp_lat', (string) round((float) $lat, 7), $this->uid());
            $this->model->setSetting('hosp_lng', (string) round((float) $lng, 7), $this->uid());
        }
        flood_json(array('chk' => true, 'msg' => 'บันทึกข้อมูลโรงพยาบาลแล้ว'));
    }

    /* ==================== ② หน่วยบริการ ==================== */

    function facilitySave() {
        $this->ready();
        $id = (int) flood_in('facility_id', 0, $_POST);
        if ($id > 0 && !$this->model->getFacility($id)) {
            flood_json(array('chk' => false, 'msg' => 'ไม่พบหน่วยบริการนี้'));
        }
        $name = mb_substr(trim((string) flood_in('name', '', $_POST)), 0, 200);
        if (mb_strlen($name) < 2) {
            flood_json(array('chk' => false, 'msg' => 'กรุณาใส่ชื่อหน่วยบริการ'));
        }
        $type = (string) flood_in('ftype', 'hs', $_POST);
        if (!array_key_exists($type, Sat_Model::facilityTypes())) {
            $type = 'other';
        }
        $amphoe = preg_replace('/\D/', '', (string) flood_in('amphoe_code', '', $_POST));
        $amphoe = strlen($amphoe) === 4 ? $amphoe : '';
        $tambon = $this->flood->validTambon(flood_in('tambon_code', '', $_POST), $amphoe);
        $lat = flood_in('lat', '', $_POST);
        $lng = flood_in('lng', '', $_POST);
        $data = array(
            'name' => $name, 'ftype' => $type,
            'hcode' => self::txt('hcode', 10), 'phone' => self::txt('phone', 100), 'contact' => self::txt('contact', 150),
            'note' => self::txt('note', 500), 'amphoe_code' => $amphoe !== '' ? $amphoe : null, 'tambon_code' => $tambon ?: null,
            'is_active' => flood_in('is_active', '1', $_POST) === '0' ? 0 : 1,
        );
        if ($lat !== '' && $lng !== '' && flood_valid_latlng($lat, $lng)) {
            $data['lat'] = round((float) $lat, 7);
            $data['lng'] = round((float) $lng, 7);
            $data['loc_approx'] = 0;
        } elseif ($tambon || $amphoe !== '') {
            // ไม่ได้ระบุพิกัด → จุดกลางตำบล (หรืออำเภอ) โดยประมาณ
            $pt = $this->flood->db->selectOne($tambon
                ? 'SELECT lat, lng FROM flood_tambon WHERE tambon_code = :c AND lat IS NOT NULL'
                : 'SELECT AVG(lat) AS lat, AVG(lng) AS lng FROM flood_tambon WHERE amphoe_code = :c AND lat IS NOT NULL',
                array(':c' => $tambon ? $tambon : $amphoe));
            if ($pt && $pt['lat'] !== null) {
                $data['lat'] = round((float) $pt['lat'], 7);
                $data['lng'] = round((float) $pt['lng'], 7);
                $data['loc_approx'] = 1;
            }
        }
        $id = $this->model->saveFacility($id, $data, $this->uid());
        flood_json(array('chk' => true, 'msg' => 'บันทึกหน่วยบริการแล้ว', 'facility_id' => $id));
    }

    /** บันทึกผลโทรสอบถาม 1 ครั้ง */
    function facilityLog() {
        $this->ready();
        $f = $this->model->getFacility((int) flood_in('facility_id', 0, $_POST));
        if (!$f) {
            flood_json(array('chk' => false, 'msg' => 'ไม่พบหน่วยบริการนี้'));
        }
        $status = self::color(flood_in('status', '', $_POST));
        if (!$status) {
            flood_json(array('chk' => false, 'msg' => 'กรุณาเลือกสีสถานะ'));
        }
        $service = (string) flood_in('service', 'open', $_POST);
        if (!array_key_exists($service, Sat_Model::serviceStates())) {
            $service = 'open';
        }
        $source = (string) flood_in('source', 'call', $_POST);
        if (!in_array($source, array('call', 'visit', 'news'), true)) {
            $source = 'call';
        }
        $this->model->addFacilityLog($f['facility_id'], array(
            'status' => $status, 'service' => $service, 'source' => $source,
            'relocated_to' => self::txt('relocated_to', 200), 'road_access' => self::txt('road_access'),
            'staff_issue' => self::txt('staff_issue'), 'utility_issue' => self::txt('utility_issue'),
            'patient_note' => self::txt('patient_note'), 'needs' => self::txt('needs'),
            'contact_person' => self::txt('contact_person', 150), 'note' => self::txt('note', 2000),
        ), $this->uid());
        flood_json(array('chk' => true, 'msg' => 'บันทึกผลของ ' . $f['name'] . ' แล้ว'));
    }

    function facilityHistory($id = 0) {
        $this->ready();
        $f = $this->model->getFacility((int) $id);
        if (!$f) {
            flood_json(array('chk' => false, 'msg' => 'ไม่พบหน่วยบริการนี้'));
        }
        $states = Sat_Model::serviceStates();
        $rows = array();
        foreach ($this->model->facilityLogs($f['facility_id'], 20) as $r) {
            $parts = array();
            foreach (array('relocated_to' => 'ย้ายไป', 'road_access' => 'ถนน', 'staff_issue' => 'บุคลากร',
                'utility_issue' => 'ไฟ/น้ำ/ยา', 'patient_note' => 'ผู้ป่วย', 'needs' => 'ต้องการ',
                'contact_person' => 'ผู้ให้ข้อมูล', 'note' => 'หมายเหตุ') as $k => $label) {
                if ((string) $r[$k] !== '') {
                    $parts[] = $label . ': ' . $r[$k];
                }
            }
            $rows[] = array(
                'status' => $r['status'], 'service' => isset($states[$r['service']]) ? $states[$r['service']]['name'] : $r['service'],
                'source' => $r['source'] === 'news' ? 'ข่าว' : ($r['source'] === 'visit' ? 'ลงพื้นที่' : 'โทร'),
                'detail' => implode(' · ', $parts), 'by' => (string) $r['by_name'], 'at' => flood_thai_date($r['created_at']),
            );
        }
        flood_json(array('chk' => true, 'facility' => array(
            'facility_id' => (int) $f['facility_id'], 'name' => $f['name'], 'ftype' => $f['ftype'], 'hcode' => (string) $f['hcode'],
            'amphoe_code' => (string) $f['amphoe_code'], 'tambon_code' => (string) $f['tambon_code'],
            'lat' => $f['lat'] !== null ? (float) $f['lat'] : '', 'lng' => $f['lng'] !== null ? (float) $f['lng'] : '',
            'loc_approx' => (int) $f['loc_approx'], 'phone' => (string) $f['phone'], 'contact' => (string) $f['contact'],
            'note' => (string) $f['note'], 'is_active' => (int) $f['is_active'],
        ), 'rows' => $rows));
    }

    /**
     * นำเข้ารายชื่อหน่วยบริการจากข้อความ (คัดลอกจาก Excel/CSV) — 1 บรรทัด = 1 หน่วย
     * คอลัมน์: ชื่อ, ประเภท, อำเภอ, ตำบล, เบอร์โทร, รหัสหน่วยบริการ, lat, lng (คั่นด้วย tab หรือ ,) — ชื่อซ้ำ/รหัสซ้ำ = แก้ไขของเดิม
     */
    function facilityImport() {
        $this->ready();
        $text = (string) flood_in('rows', '', $_POST);
        $lines = preg_split('/\r\n|\r|\n/', $text);
        if (count($lines) > 500) {
            flood_json(array('chk' => false, 'msg' => 'นำเข้าได้ครั้งละไม่เกิน 500 บรรทัด'));
        }
        $types = array('แม่ข่าย' => 'main', 'รพร' => 'main', 'รพท' => 'hospital', 'รพช' => 'hospital', 'โรงพยาบาล' => 'hospital',
            'pcc' => 'pcc', 'ศูนย์สุขภาพชุมชน' => 'pcc', 'รพ.สต' => 'hs', 'รพสต' => 'hs', 'สถานีสุขภาพ' => 'hs', 'สอน' => 'hs');
        $amphoes = array();
        foreach ($this->model->amphoes() as $a) {
            $amphoes[preg_replace('/^(อ\.|อำเภอ)\s*/u', '', $a['name'])] = $a['amphoe_code'];
        }
        $created = 0;
        $updated = 0;
        $errors = array();
        foreach ($lines as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            $col = array_map('trim', strpos($line, "\t") !== false ? explode("\t", $line) : str_getcsv($line, ',', '"', '\\'));
            $name = mb_substr(isset($col[0]) ? $col[0] : '', 0, 200);
            if (mb_strlen($name) < 2 || preg_match('/^(ชื่อ|name)/iu', $name)) {
                continue;   // หัวตาราง/บรรทัดว่าง
            }
            $typeTxt = isset($col[1]) ? $col[1] : '';
            $type = array_key_exists($typeTxt, Sat_Model::facilityTypes()) ? $typeTxt : '';
            if ($type === '') {
                foreach ($types as $k => $v) {
                    if ($k !== '' && (mb_stripos($typeTxt, $k) !== false || mb_stripos($name, $k) === 0)) {
                        $type = $v;
                        break;
                    }
                }
            }
            $amName = preg_replace('/^(อ\.|อำเภอ)\s*/u', '', isset($col[2]) ? $col[2] : '');
            $amphoe = isset($amphoes[$amName]) ? $amphoes[$amName] : '';
            $tbName = preg_replace('/^(ต\.|ตำบล)\s*/u', '', isset($col[3]) ? $col[3] : '');
            $tambon = '';
            $lat = isset($col[6]) ? $col[6] : '';
            $lng = isset($col[7]) ? $col[7] : '';
            $data = array('name' => $name, 'ftype' => $type !== '' ? $type : 'hs',
                'phone' => isset($col[4]) && $col[4] !== '' ? mb_substr($col[4], 0, 100) : null,
                'hcode' => isset($col[5]) && $col[5] !== '' ? mb_substr(preg_replace('/\D/', '', $col[5]), 0, 10) : null,
                'amphoe_code' => $amphoe !== '' ? $amphoe : null, 'is_active' => 1);
            if ($amphoe !== '' && $tbName !== '') {
                $t = $this->flood->db->selectOne('SELECT tambon_code, lat, lng FROM flood_tambon WHERE amphoe_code = :a AND name = :n LIMIT 1',
                    array(':a' => $amphoe, ':n' => $tbName));
                if ($t) {
                    $tambon = $t['tambon_code'];
                    $data['tambon_code'] = $tambon;
                    if ($t['lat'] !== null) {
                        $data['lat'] = (float) $t['lat'];
                        $data['lng'] = (float) $t['lng'];
                        $data['loc_approx'] = 1;
                    }
                }
            }
            if ($lat !== '' && $lng !== '' && flood_valid_latlng($lat, $lng)) {
                $data['lat'] = round((float) $lat, 7);
                $data['lng'] = round((float) $lng, 7);
                $data['loc_approx'] = 0;
            } elseif (!isset($data['lat']) && $amphoe !== '') {
                $pt = $this->flood->db->selectOne('SELECT AVG(lat) AS lat, AVG(lng) AS lng FROM flood_tambon WHERE amphoe_code = :a AND lat IS NOT NULL',
                    array(':a' => $amphoe));
                if ($pt && $pt['lat'] !== null) {
                    $data['lat'] = round((float) $pt['lat'], 7);
                    $data['lng'] = round((float) $pt['lng'], 7);
                    $data['loc_approx'] = 1;
                }
            }
            try {
                $old = $data['hcode'] ? $this->model->facilityByHcode($data['hcode']) : null;
                $old = $old ?: $this->model->facilityByName($name);
                if ($old) {
                    $this->model->saveFacility((int) $old['facility_id'], $data, $this->uid());
                    $updated++;
                } else {
                    $this->model->saveFacility(0, $data, $this->uid());
                    $created++;
                }
            } catch (Exception $e) {
                $errors[] = 'บรรทัด ' . ($i + 1) . ': บันทึกไม่สำเร็จ';
            }
        }
        flood_json(array('chk' => true, 'msg' => 'เพิ่ม ' . $created . ' · แก้ไข ' . $updated . ($errors ? ' · ผิดพลาด ' . count($errors) : ''),
            'errors' => array_slice($errors, 0, 20)));
    }

    /* ==================== ③ เส้นทาง ==================== */

    function routeSave() {
        $this->ready();
        $id = (int) flood_in('route_id', 0, $_POST);
        if ($id > 0 && !$this->model->getRoute($id)) {
            flood_json(array('chk' => false, 'msg' => 'ไม่พบเส้นทางนี้'));
        }
        $name = mb_substr(trim((string) flood_in('name', '', $_POST)), 0, 200);
        if (mb_strlen($name) < 2) {
            flood_json(array('chk' => false, 'msg' => 'กรุณาตั้งชื่อเส้นทาง'));
        }
        $type = (string) flood_in('rtype', 'refer', $_POST);
        if (!array_key_exists($type, Sat_Model::routeTypes())) {
            $type = 'refer';
        }
        $segText = trim((string) flood_in('segments', '', $_POST));
        $segs = Sat_Model::parseSegments($segText);
        if ($segText !== '' && !$segs) {
            flood_json(array('chk' => false, 'msg' => 'รูปแบบทางหลวงไม่ถูกต้อง — ตัวอย่าง 33:215-245; 359:0-100'));
        }
        $norm = array();
        foreach ($segs as $s) {
            $norm[] = $s[0] . ($s[1] !== null ? ':' . rtrim(rtrim(number_format($s[1], 3, '.', ''), '0'), '.') . '-'
                . rtrim(rtrim(number_format($s[2], 3, '.', ''), '0'), '.') : '');
        }
        $data = array(
            'rtype' => $type, 'name' => $name, 'destination' => self::txt('destination', 200),
            'segments' => $norm ? implode('; ', $norm) : null, 'is_backup' => flood_in('is_backup', '0', $_POST) === '1' ? 1 : 0,
            'note' => self::txt('note', 500), 'is_active' => flood_in('is_active', '1', $_POST) === '0' ? 0 : 1,
        );
        $id = $this->model->saveRoute($id, $data, $this->uid());
        flood_json(array('chk' => true, 'msg' => 'บันทึกเส้นทางแล้ว', 'route_id' => $id));
    }

    function routeCheck() {
        $this->ready();
        $r = $this->model->getRoute((int) flood_in('route_id', 0, $_POST));
        if (!$r) {
            flood_json(array('chk' => false, 'msg' => 'ไม่พบเส้นทางนี้'));
        }
        $result = (string) flood_in('result', '', $_POST);
        if (!array_key_exists($result, Sat_Model::routeChecks())) {
            flood_json(array('chk' => false, 'msg' => 'กรุณาเลือกผลการตรวจ'));
        }
        $this->model->addRouteCheck($r['route_id'], $result, mb_substr(trim((string) flood_in('note', '', $_POST)), 0, 500), $this->uid());
        flood_json(array('chk' => true, 'msg' => 'บันทึกผลตรวจเส้นทางแล้ว'));
    }

    /* ==================== ⑤ SitRep ==================== */

    /** ร่างข้อความ SitRep จากข้อมูลล่าสุด + ช่องที่ SAT กรอก */
    function sitrepDraft() {
        $this->ready();
        $c = $this->collect();
        list($sug, $reasons) = $this->suggest($c);
        $decl = $this->declared($c['settings']);
        $overall = self::color(flood_in('overall', '', $_POST)) ?: ($decl ? $decl['status'] : $sug);
        $text = $this->sitrepText($c, $overall, $reasons, array(
            'reason' => trim((string) flood_in('reason', $decl ? $decl['reason'] : '', $_POST)),
            'impact' => trim((string) flood_in('impact', '', $_POST)),
            'options' => trim((string) flood_in('options', '', $_POST)),
            'requests' => trim((string) flood_in('requests', '', $_POST)),
            'actions' => trim((string) flood_in('actions', '', $_POST)),
            'next' => trim((string) flood_in('next', '', $_POST)),
            'reporter' => trim((string) flood_in('reporter', '', $_POST)),
        ));
        flood_json(array('chk' => true, 'text' => $text, 'overall' => $overall, 'no' => $this->model->nextSitrepNo()));
    }

    function sitrepSave() {
        $this->ready();
        $body = trim((string) flood_in('body', '', $_POST));
        if (mb_strlen($body) < 20) {
            flood_json(array('chk' => false, 'msg' => 'กรุณากดสร้างข้อความ SitRep ก่อนบันทึก'));
        }
        $overall = self::color(flood_in('overall', '', $_POST)) ?: '';
        $next = trim((string) flood_in('next_at', '', $_POST));
        $nextTs = $next !== '' ? strtotime(str_replace('T', ' ', $next)) : false;
        $c = $this->collect();
        $snap = array(
            'fac' => array_intersect_key($c['fac'], array_flip(array('count', 'green', 'yellow', 'orange', 'red', 'none', 'overdue'))),
            'road' => $c['road'], 'staff' => $c['staff']['all'], 'staff_crit' => $c['staffCrit'],
            'vuln' => array_intersect_key($c['vuln'], array_flip(array('total', 'in_zone', 'in_zone_waiting'))),
            'routes' => array_map(function ($r) {
                return array('id' => (int) $r['route_id'], 'name' => $r['name'], 'effective' => $r['effective'], 'hits' => count($r['hits']));
            }, $c['routes']),
            'items' => array_map(function ($it) {
                return array('status' => $it['status'], 'at' => $it['at']);
            }, $c['items']),
        );
        list($id, $no) = $this->model->saveSitrep(array(
            'report_at' => date('Y-m-d H:i:s'), 'overall' => $overall, 'body' => mb_substr($body, 0, 60000),
            'data_json' => json_encode($snap, JSON_UNESCAPED_UNICODE), 'next_at' => $nextTs ? date('Y-m-d H:i:s', $nextTs) : null,
        ), $this->uid());
        flood_json(array('chk' => true, 'msg' => 'บันทึก SitRep ฉบับที่ ' . $no . ' แล้ว', 'sitrep_id' => $id, 'no' => $no));
    }

    function sitrep($id = 0) {
        $this->ready();
        $s = $this->model->getSitrep((int) $id);
        if (!$s) {
            flood_json(array('chk' => false, 'msg' => 'ไม่พบ SitRep ฉบับนี้'));
        }
        flood_json(array('chk' => true, 'no' => (int) $s['report_no'], 'at' => flood_thai_date($s['report_at']),
            'by' => (string) $s['by_name'], 'overall' => $s['overall'], 'body' => $s['body']));
    }

    /** ข้อความ SitRep (ส่ง LINE กลุ่ม EOC ได้ทันที) */
    private function sitrepText($c, $overall, $reasons, $in) {
        $col = Sat_Model::colors();
        $acts = Sat_Model::overallActions();
        $items = $c['items'];
        $no = $this->model->nextSitrepNo();
        $L = array();
        $L[] = '📋 SitRep ฉบับที่ ' . $no . ' · SAT ' . $c['hosp']['name'];
        $L[] = 'ข้อมูล ณ ' . flood_thai_date(time()) . ' น.' . ($in['reporter'] !== '' ? ' · ผู้รายงาน ' . $in['reporter'] : '');
        $L[] = '';
        $L[] = 'สถานะโรงพยาบาล: ' . $col[$overall]['emoji'] . ' ' . $col[$overall]['label'] . ' — ' . $col[$overall]['name'];
        if ($in['reason'] !== '') {
            $L[] = 'เหตุผล: ' . $in['reason'];
        } elseif ($reasons) {
            $L[] = 'เหตุผล: ' . implode(' · ', array_map(function ($x) { return $x[1]; }, array_slice($reasons, 0, 4)));
        }
        $L[] = '';

        $line = function ($code, $auto = '') use ($items, $col) {
            $it = $items[$code];
            $s = $it['status'] !== '' ? $col[$it['status']]['emoji'] . ' ' : '⚪ ';
            $txt = $it['note'] !== '' ? preg_replace('/\s+/u', ' ', $it['note']) : '';
            if (isset($it['src']) && $it['src'] === 'auto') {
                // สรุปจากปุ่ม "ดึงประมวล" หลายบรรทัด: SitRep ใช้ 2 บรรทัดแรก (ฉบับเต็มดูที่ประวัติตัวชี้วัด) และไม่ต่อข้อมูลระบบซ้ำ
                $ls = array();
                foreach (preg_split('/\R/u', (string) $it['note']) as $ln) {
                    $ln = trim(preg_replace('/^•\s*/u', '', trim($ln)));
                    if ($ln !== '') {
                        $ls[] = $ln;
                    }
                }
                $txt = implode(' · ', array_slice($ls, 0, 2)) . (count($ls) > 2 ? ' …' : '');
                $auto = '';
            }
            $parts = array_filter(array($txt, $auto));
            $when = $it['at'] ? ' (อัปเดต ' . date('H:i', strtotime($it['at'])) . ($it['stale'] ? ' เกินรอบ' : '') . ')' : ' (ยังไม่อัปเดต)';
            return $it['emoji'] . ' ' . $it['name'] . ': ' . $s . ($parts ? implode(' · ', $parts) : '-') . $when;
        };

        // ถนน: ทางหลวงรอบ รพ. จากกรมทางหลวง
        $roadAuto = 'กรมทางหลวงรายงานในรัศมี 80 กม.: ผ่านไม่ได้ ' . $c['road']['red'] . ' · รถเล็กผ่านไม่ได้ ' . $c['road']['orange']
            . ' · เฝ้าระวัง ' . $c['road']['yellow'] . ' จุด';
        $worst = array();
        foreach (array_slice($c['near'], 0, 3) as $z) {
            if ($z['color'] === 'red') {
                $worst[] = 'ทล.' . $z['road'] . ($z['section'] !== '' ? ' ' . $z['section'] : '');
            }
        }
        if ($worst) {
            $roadAuto .= ' (' . implode(' / ', $worst) . ')';
        }
        // เส้นทาง EMS/Refer
        $rt = array();
        foreach ($c['routes'] as $r) {
            if ($r['effective'] === '') {
                continue;
            }
            $rt[] = $col[$r['effective']]['emoji'] . ' ' . $r['name'];
        }
        // หน่วยบริการ
        $f = $c['fac'];
        $facAuto = 'จาก ' . $f['count'] . ' แห่ง: 🔴' . $f['red'] . ' 🟠' . $f['orange'] . ' 🟡' . $f['yellow'] . ' 🟢' . $f['green']
            . ($f['none'] ? ' ⚪' . $f['none'] : '') . ($f['overdue'] ? ' · ถึงรอบโทร ' . $f['overdue'] . ' แห่ง' : '');
        $closed = array();
        foreach ($f['closed'] as $x) {
            $closed[] = $x['name'] . ($x['relocated_to'] ? ' → ' . $x['relocated_to'] : '');
        }
        // บุคลากร
        $st = $c['staff'];
        $staffAuto = '';
        if ($st['ready']) {
            $staffAuto = 'แบบสำรวจ ' . $st['all']['total'] . ' คน: เดินทางไม่ได้ ' . $st['all']['red'] . ' · เสี่ยง ' . $st['all']['orange']
                . ' · ลำบาก ' . $st['all']['yellow'];
            $crit = array();
            foreach ($st['units'] as $code => $u) {
                if ($u['red'] > 0) {
                    $crit[] = Sat_Model::criticalUnits()[$code]['name'] . ' ' . $u['red'];
                }
            }
            if ($crit) {
                $staffAuto .= ' · หน่วยสำคัญที่มีคนเดินทางไม่ได้: ' . implode(', ', $crit);
            }
            $fu = Sat_Model::staffFollowText($st, true);
            if ($fu !== '') {
                $staffAuto .= ' · ผู้ได้รับผลกระทบ ' . $fu;
            }
        }
        if ($c['mp']['ready'] && $c['mp']['short']) {
            $staffAuto .= ($staffAuto !== '' ? ' · ' : '') . 'RN เวรนี้ขาดกรอบ ' . count($c['mp']['short']) . ' หน่วย';
        }
        // ผู้ป่วยเสี่ยง
        $v = $c['vuln'];
        $patAuto = $v['ready'] ? 'ทะเบียนกลุ่มเปราะบาง ' . $v['total'] . ' ราย · อยู่ในพื้นที่น้ำท่วม ' . $v['in_zone']
            . ' (ยังไม่อพยพ ' . $v['in_zone_waiting'] . ')' : '';
        if ($c['shelter']['ready']) {
            $patAuto = implode(' · ', $c['shelter']['lines']) . ($patAuto !== '' ? ' · ' . $patAuto : '');
        }

        // ฝนรายวันจากสถานีกรมชลประทาน (ไม่ซ้ำถ้าสรุปของการ์ดเป็นข้อความชุดเดียวกันแล้ว)
        $rainAuto = '';
        if (is_file('models/rain_model.php')) {
            require_once 'models/rain_model.php';
            $rainAuto = Rain_Model::sitrepText($items['rain']['note']);
        }
        $L[] = $line('rain', $rainAuto);
        $L[] = $line('water');
        $L[] = $line('road', $roadAuto);
        $L[] = $line('facility', $facAuto);
        if ($closed && $items['facility']['src'] !== 'auto') {
            $L[] = '   ปิด/ย้ายจุดบริการ: ' . implode(' · ', $closed);
        }
        $L[] = $line('ems', implode(' · ', $c['refer']['lines']));
        if ($rt && $items['ems']['src'] !== 'auto') {
            $L[] = '   เส้นทาง: ' . implode(' · ', $rt);
        }
        $L[] = $line('staff', $staffAuto);
        $L[] = $line('patient', $patAuto);
        $L[] = $line('hosp_site');
        $L[] = $line('ed_load', implode(' · ', array_slice($c['his']['lines'], 0, 4)));
        $u = array();
        $ux = array();
        foreach (array('util_power', 'util_water', 'util_o2', 'util_fuel', 'util_it') as $code) {
            $it = $items[$code];
            $sys = isset($c['util']['items'][$code]) ? $c['util']['items'][$code]['text'] : '';
            if ($sys !== '') {
                $ux[] = $sys;
            }
            // สรุปที่เป็นข้อความจากหน้าสาธารณูปโภค แสดงเต็มในบรรทัด "ข้อมูลงานช่าง" แทน (ไม่ตัดกลางคำ)
            $note = $sys !== '' && strpos($it['note'], $sys) !== false ? trim(str_replace($sys, '', $it['note'])) : $it['note'];
            $u[] = $it['name'] . ' ' . ($it['status'] !== '' ? $col[$it['status']]['emoji'] : '⚪')
                . ($note !== '' ? ' (' . mb_substr(preg_replace('/\s+/u', ' ', $note), 0, 60) . ')' : '');
        }
        $L[] = '⚡ ระบบสำคัญ: ' . implode(' · ', $u);
        if ($ux) {
            $L[] = '   ข้อมูลงานช่าง: ' . implode(' · ', $ux);
        }
        $L[] = '';
        if ($in['actions'] !== '') {
            $L[] = '✅ การดำเนินการ: ' . $in['actions'];
        }
        if ($in['impact'] !== '') {
            $L[] = '⏱️ ผลกระทบที่คาดใน 6–12 ชม.: ' . $in['impact'];
        }
        if ($in['options'] !== '') {
            $L[] = '🧭 ทางเลือกเสนอ IC: ' . $in['options'];
        }
        if ($in['requests'] !== '') {
            $L[] = '🙏 ต้องการสนับสนุน: ' . $in['requests'];
        }
        $L[] = 'SAT แนะนำ: ' . $acts[$overall];
        if ($in['next'] !== '') {
            $L[] = 'รายงานครั้งถัดไป: ' . $in['next'];
        }
        return implode("\n", $L);
    }

    /* ==================== ดึงประมวล — รวบรวมข้อมูลย่อยของตัวชี้วัดลงประวัติ ==================== */

    private function compileModel() {
        require_once 'models/sat_compile_model.php';
        return new Sat_Compile_Model();
    }

    /** ค่าที่ sat_compile.js ใช้ดึงข้อมูลก่อนประมวล: ลิงก์ชีต/แท็บ + เวลานำเข้าล่าสุด (เฉพาะผู้บันทึกได้ · ไม่มีข้อมูลบุคคล) */
    private function pullConfig($c) {
        require_once 'models/sat_compile_model.php';
        $names = array();
        foreach (Sat_Model::items() as $k => $it) {
            $names[$k] = array('name' => $it['name'], 'emoji' => $it['emoji']);
        }
        $cfg = array('items' => $names, 'groups' => Sat_Compile_Model::menuGroups(), 'presets' => Sat_Compile_Model::presets(),
            'sources' => Sat_Compile_Model::pullSources(), 'colors' => Sat_Model::colors(),
            'util' => null, 'refer' => null, 'shelter' => array(), 'shelterLast' => '');
        $db = $this->model->db;
        $hasTable = function ($t) use ($db) {
            return (bool) $db->selectValue('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t',
                array(':t' => $t));
        };
        try {
            require_once 'models/utility_model.php';
            list($url, $tabs) = $this->utilSource();
            $cfg['util'] = array('sheet' => $url, 'tabs' => $tabs,
                'last' => !empty($c['util']['imported_at']) ? flood_thai_date($c['util']['imported_at']) : '');
        } catch (Exception $e) {
            error_log('[flood] SAT pull config utility: ' . $e->getMessage());
        }
        try {
            require_once 'models/refer_model.php';
            list($url, $tabs) = $this->referSource();
            $last = $hasTable('flood_refer_in') ? $db->selectValue('SELECT MAX(imported_at) FROM flood_refer_in') : null;
            $cfg['refer'] = array('sheet' => $url, 'tabs' => $tabs, 'last' => $last ? flood_thai_date($last) : '');
        } catch (Exception $e) {
            error_log('[flood] SAT pull config refer: ' . $e->getMessage());
        }
        try {
            if ($hasTable('flood_vuln_source') && $db->select("SHOW COLUMNS FROM flood_vuln_source LIKE 'kind'")) {
                foreach ($db->select("SELECT source_id, name, sheet_url FROM flood_vuln_source WHERE is_active = 1 AND kind = 'shelter'
                        ORDER BY source_id") as $r) {
                    $cfg['shelter'][] = array('id' => (int) $r['source_id'], 'name' => (string) $r['name'], 'url' => (string) $r['sheet_url']);
                }
            }
            $cfg['shelterLast'] = !empty($c['shelter']['ready']) ? flood_thai_date($c['shelter']['date'], false) : '';
        } catch (Exception $e) {
            error_log('[flood] SAT pull config shelter: ' . $e->getMessage());
        }
        return $cfg;
    }

    /** POST codes = รหัสตัวชี้วัดคั่นด้วย , (หรือ all) · tw = JSON ThaiWater ชุดย่อจากเบราว์เซอร์ → ผลประมวลรายหัวข้อ (ยังไม่บันทึก) */
    function compile() {
        if (!$this->isPost()) {
            flood_json(array('chk' => false, 'msg' => 'ต้องส่งด้วย POST'), 405);
        }
        $this->ready();
        $items = Sat_Model::items();
        $raw = (string) flood_in('codes', '', $_POST);
        $codes = $raw === 'all' ? array_keys($items) : array_values(array_intersect(array_keys($items), explode(',', $raw)));
        if (!$codes) {
            flood_json(array('chk' => false, 'msg' => 'กรุณาเลือกหัวข้อ'));
        }
        $tw = isset($_POST['tw']) ? (string) $_POST['tw'] : '';
        if (strlen($tw) > 500000) {
            flood_json(array('chk' => false, 'msg' => 'ข้อมูล ThaiWater ใหญ่เกินไป'));
        }
        $cm = $this->compileModel();
        $rows = $cm->compile($this->collect(), $codes, Sat_Compile_Model::cleanTw($tw !== '' ? json_decode($tw, true) : null));
        flood_json(array('chk' => true, 'rows' => $rows, 'at' => flood_thai_date(date('Y-m-d H:i:s'))));
    }

    /** POST items = JSON [{code, status, note, data}] → บันทึกลงประวัติตัวชี้วัด (src = auto · การ์ดเปลี่ยนตาม) */
    function compileSave() {
        if (!$this->isPost()) {
            flood_json(array('chk' => false, 'msg' => 'ต้องส่งด้วย POST'), 405);
        }
        $this->ready();
        $raw = isset($_POST['items']) ? (string) $_POST['items'] : '';
        if (strlen($raw) > 600000) {
            flood_json(array('chk' => false, 'msg' => 'ข้อมูลใหญ่เกินไป'));
        }
        $list = json_decode($raw, true);
        if (!is_array($list) || !$list) {
            flood_json(array('chk' => false, 'msg' => 'กรุณาเลือกหัวข้อที่จะบันทึก'));
        }
        $items = Sat_Model::items();
        $done = array();
        foreach ($list as $x) {
            $code = is_array($x) && isset($x['code']) && is_scalar($x['code']) ? (string) $x['code'] : '';
            if (!isset($items[$code]) || isset($done[$code])) {
                continue;
            }
            $status = self::color(isset($x['status']) && is_scalar($x['status']) ? $x['status'] : '', true);
            $note = mb_substr(trim(isset($x['note']) && is_scalar($x['note']) ? (string) $x['note'] : ''), 0, 2000);
            if ($status === null || ($status === '' && $note === '')) {
                continue;
            }
            $data = isset($x['data']) && is_array($x['data']) ? json_encode($x['data'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null;
            if ($data !== null && strlen($data) > 20000) {
                $data = null;
            }
            $this->model->saveItem($code, $status, $note, $this->uid(), 'auto', $data);
            $done[$code] = $items[$code]['name'];
        }
        if (!$done) {
            flood_json(array('chk' => false, 'msg' => 'ไม่มีหัวข้อที่บันทึกได้ (ต้องมีสีหรือสรุปอย่างน้อยหนึ่งอย่าง)'));
        }
        flood_json(array('chk' => true, 'msg' => 'บันทึกลงประวัติ ' . count($done) . ' หัวข้อ: ' . implode(' · ', $done)));
    }

    /* ==================== สาธารณูปโภค (น้ำ · น้ำมันสำรอง · ออกซิเจน · รถเติมน้ำ) ==================== */

    private function utilModel() {
        require_once 'models/utility_model.php';
        $um = new Utility_Model();
        if (!$um->ensureTables()) {
            if ($this->isPost() || flood_is_ajax()) {
                flood_json(array('chk' => false, 'msg' => 'ยังไม่มีตารางสาธารณูปโภค และระบบสร้างเองไม่ได้ — ให้ผู้ดูแลรัน php sql/apply_schema.php 21'));
            }
            return null;
        }
        return $um;
    }

    /** ลิงก์ชีต + ชื่อแท็บ (ค่าตั้งใน flood_sat_setting) */
    private function utilSource() {
        $s = $this->model->ensureTables() ? $this->model->settings() : array();
        $url = isset($s['util_sheet_url']) && trim((string) $s['util_sheet_url']['sval']) !== '' ? trim($s['util_sheet_url']['sval']) : Utility_Model::DEFAULT_SHEET;
        $tabs = isset($s['util_sheet_tabs']) ? json_decode((string) $s['util_sheet_tabs']['sval'], true) : null;
        if (!is_array($tabs) || !$tabs) {
            $tabs = array_values(Utility_Model::kindNames());
        }
        return array($url, $tabs);
    }

    /** หน้า สาธารณูปโภค (เมนู "สาธารณูปโภค") */
    function utility() {
        $this->view->pageMenu = 'flood';
        $this->view->activeTab = 'utility';
        $this->view->pageTitle = 'สาธารณูปโภค (น้ำ · ไฟสำรอง · ออกซิเจน)';
        $um = $this->utilModel();
        if (!$um) {
            $this->view->blockedMessage = 'ยังไม่มีตารางสาธารณูปโภค — ให้ผู้ดูแลรัน php sql/apply_schema.php 21';
            $this->view->rander('flood/no_permission');
            return;
        }
        list($url, $tabs) = $this->utilSource();
        $edit = $this->canEdit();
        $sum = $um->summary();
        if (!$edit && !empty($sum['delivery'])) {
            $sum['delivery']['trips'] = array();   // ผู้บริหาร/ดูอย่างเดียว เห็นเฉพาะยอดสรุป (ไม่เห็นรายเที่ยว)
        }
        $this->view->css[] = 'flood/css/utility.css';
        if ($edit) {
            $this->view->js[] = 'flood/js/utility.js';
        }
        $this->view->util = $sum;
        $this->view->utilSheet = $url;
        $this->view->utilTabs = $tabs;
        $this->view->utilCanEdit = $edit;
        $this->view->rander('flood/utility');
    }

    /** POST sheets = JSON {ชื่อแท็บ: [[ข้อความ,…],…]} จากเบราว์เซอร์ (อ่าน Google Sheet / .xlsx) */
    function utilityImport() {
        if (!$this->isPost()) {
            flood_json(array('chk' => false, 'msg' => 'ต้องส่งด้วย POST'), 405);
        }
        $um = $this->utilModel();
        $raw = isset($_POST['sheets']) ? (string) $_POST['sheets'] : '';
        if (strlen($raw) > 3000000) {
            flood_json(array('chk' => false, 'msg' => 'ข้อมูลใหญ่เกินไป'));
        }
        $sheets = json_decode($raw, true);
        if (!is_array($sheets) || !$sheets) {
            flood_json(array('chk' => false, 'msg' => 'ไม่พบข้อมูลชีต'));
        }
        $clean = array();
        foreach ($sheets as $tab => $grid) {
            if (!is_array($grid) || count($grid) > 5000) {
                continue;
            }
            $rows = array();
            foreach ($grid as $row) {
                $cells = array();
                foreach (array_slice(is_array($row) ? array_values($row) : array(), 0, 40) as $v) {
                    $cells[] = is_scalar($v) ? mb_substr((string) $v, 0, 500) : '';
                }
                $rows[] = $cells;
            }
            $clean[mb_substr((string) $tab, 0, 100)] = $rows;
        }
        $r = $um->import($clean, $this->uid());
        flood_json(array('chk' => !empty($r['ok']), 'msg' => $r['msg']));
    }

    /** POST url, tabs (บรรทัดละแท็บ) */
    function utilitySheet() {
        if (!$this->isPost()) {
            flood_json(array('chk' => false, 'msg' => 'ต้องส่งด้วย POST'), 405);
        }
        $this->ready();
        require_once 'models/utility_model.php';
        $url = trim((string) flood_in('url', '', $_POST));
        if (!preg_match('#^https://docs\.google\.com/spreadsheets/d/[a-zA-Z0-9_-]{20,}#', $url)) {
            flood_json(array('chk' => false, 'msg' => 'ลิงก์ต้องเป็น Google Sheet (https://docs.google.com/spreadsheets/d/…)'));
        }
        $tabs = array();
        foreach (preg_split('/\r?\n/', (string) flood_in('tabs', '', $_POST)) as $t) {
            $t = trim($t);
            if ($t !== '' && Utility_Model::kindOfTab($t)) {
                $tabs[] = mb_substr($t, 0, 100);
            }
        }
        if (!$tabs) {
            flood_json(array('chk' => false, 'msg' => 'ใส่ชื่อแท็บอย่างน้อย 1 แท็บ (ชื่อต้องมีคำว่า ถังพักน้ำ / น้ำมัน / ออกซิเจน / เติมน้ำ)'));
        }
        $this->model->setSetting('util_sheet_url', mb_substr($url, 0, 500), $this->uid());
        $this->model->setSetting('util_sheet_tabs', json_encode(array_values(array_unique($tabs)), JSON_UNESCAPED_UNICODE), $this->uid());
        flood_json(array('chk' => true, 'msg' => 'บันทึกลิงก์ชีตแล้ว'));
    }

    /** POST — ตั้งตัวชี้วัด น้ำประปา / ออกซิเจน / เชื้อเพลิง ของ SAT ตามข้อมูลหน้าสาธารณูปโภค (สี + สรุปที่ระบบคำนวณ) */
    function utilitySync() {
        if (!$this->isPost()) {
            flood_json(array('chk' => false, 'msg' => 'ต้องส่งด้วย POST'), 405);
        }
        $this->ready();
        $u = $this->utilityNow();
        $items = Sat_Model::items();
        $done = array();
        foreach (array('util_water', 'util_o2', 'util_fuel') as $code) {
            if (empty($u['items'][$code]) || $u['items'][$code]['status'] === '') {
                continue;
            }
            $this->model->saveItem($code, $u['items'][$code]['status'], $u['items'][$code]['text'], $this->uid());
            $done[] = $items[$code]['name'];
        }
        if (!$done) {
            flood_json(array('chk' => false, 'msg' => 'ยังไม่มีข้อมูลสาธารณูปโภคที่วัดภายใน 48 ชม. — กด "ดึงจาก Google Sheet" ที่หน้า สาธารณูปโภค ก่อน'));
        }
        flood_json(array('chk' => true, 'msg' => 'อัปเดต ' . implode(' · ', $done) . ' จากข้อมูลสาธารณูปโภคแล้ว'));
    }

    /* ==================== Refer เข้า รพ. ช่วงอุทกภัย ==================== */

    private function referModel() {
        require_once 'models/refer_model.php';
        $rm = new Refer_Model();
        if (!$rm->ensureTables()) {
            if ($this->isPost() || flood_is_ajax()) {
                flood_json(array('chk' => false, 'msg' => 'ยังไม่มีตาราง Refer และระบบสร้างเองไม่ได้ — ให้ผู้ดูแลรัน php sql/apply_schema.php 22'));
            }
            return null;
        }
        return $rm;
    }

    private function referSource() {
        $s = $this->model->ensureTables() ? $this->model->settings() : array();
        $url = isset($s['refer_sheet_url']) && trim((string) $s['refer_sheet_url']['sval']) !== '' ? trim($s['refer_sheet_url']['sval']) : Refer_Model::DEFAULT_SHEET;
        $tabs = isset($s['refer_sheet_tabs']) ? json_decode((string) $s['refer_sheet_tabs']['sval'], true) : null;
        if (!is_array($tabs) || !$tabs) {
            $tabs = array(Refer_Model::DEFAULT_TAB);
        }
        return array($url, $tabs);
    }

    /** หน้า Refer (เมนู "Refer ช่วงอุทกภัย") — รายชื่อผู้ป่วยเห็นเฉพาะเจ้าหน้าที่ศูนย์/ผู้ดูแลระบบ */
    function refer() {
        $this->view->pageMenu = 'flood';
        $this->view->activeTab = 'refer';
        $this->view->pageTitle = 'Refer เข้า รพ. ช่วงอุทกภัย';
        $rm = $this->referModel();
        if (!$rm) {
            $this->view->blockedMessage = 'ยังไม่มีตาราง Refer — ให้ผู้ดูแลรัน php sql/apply_schema.php 22';
            $this->view->rander('flood/no_permission');
            return;
        }
        list($url, $tabs) = $this->referSource();
        $edit = $this->canEdit();
        $sum = $rm->summary();
        if (!$edit) {
            $sum['rows'] = array();
        }
        $this->view->css[] = 'flood/css/utility.css';
        if ($edit) {
            $this->view->js[] = 'flood/js/utility.js';
        }
        $this->view->refer = $sum;
        $this->view->referSheet = $url;
        $this->view->referTabs = $tabs;
        $this->view->referCanEdit = $edit;
        $this->view->rander('flood/refer');
    }

    /** POST sheets = JSON {ชื่อแท็บ: grid} */
    function referImport() {
        if (!$this->isPost()) {
            flood_json(array('chk' => false, 'msg' => 'ต้องส่งด้วย POST'), 405);
        }
        $rm = $this->referModel();
        $raw = isset($_POST['sheets']) ? (string) $_POST['sheets'] : '';
        $sheets = strlen($raw) <= 5000000 ? json_decode($raw, true) : null;
        if (!is_array($sheets) || !$sheets) {
            flood_json(array('chk' => false, 'msg' => 'ไม่พบข้อมูลชีต'));
        }
        $clean = array();
        foreach ($sheets as $tab => $grid) {
            if (!is_array($grid) || count($grid) > 10000) {
                continue;
            }
            $rows = array();
            foreach ($grid as $row) {
                $cells = array();
                foreach (array_slice(is_array($row) ? array_values($row) : array(), 0, 40) as $v) {
                    $cells[] = is_scalar($v) ? mb_substr((string) $v, 0, 500) : '';
                }
                $rows[] = $cells;
            }
            $clean[mb_substr((string) $tab, 0, 100)] = $rows;
        }
        $r = $rm->import($clean, $this->uid());
        flood_json(array('chk' => !empty($r['ok']), 'msg' => $r['msg']));
    }

    /** POST url, tabs */
    function referSheet() {
        if (!$this->isPost()) {
            flood_json(array('chk' => false, 'msg' => 'ต้องส่งด้วย POST'), 405);
        }
        $this->ready();
        $url = trim((string) flood_in('url', '', $_POST));
        if (!preg_match('#^https://docs\.google\.com/spreadsheets/d/[a-zA-Z0-9_-]{20,}#', $url)) {
            flood_json(array('chk' => false, 'msg' => 'ลิงก์ต้องเป็น Google Sheet (https://docs.google.com/spreadsheets/d/…)'));
        }
        $tabs = array();
        foreach (preg_split('/\r?\n/', (string) flood_in('tabs', '', $_POST)) as $t) {
            $t = trim($t);
            if ($t !== '') {
                $tabs[] = mb_substr($t, 0, 100);
            }
        }
        if (!$tabs) {
            flood_json(array('chk' => false, 'msg' => 'ใส่ชื่อแท็บที่มีทะเบียน Refer อย่างน้อย 1 แท็บ'));
        }
        $this->model->setSetting('refer_sheet_url', mb_substr($url, 0, 500), $this->uid());
        $this->model->setSetting('refer_sheet_tabs', json_encode(array_values(array_unique($tabs)), JSON_UNESCAPED_UNICODE), $this->uid());
        flood_json(array('chk' => true, 'msg' => 'บันทึกลิงก์ชีตแล้ว'));
    }
}
