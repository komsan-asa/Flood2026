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
        $c = $s->collect();
        list($sug, $reasons) = $s->suggest($c);
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

        return compact('settings', 'hosp', 'items', 'facilities', 'fac', 'routes', 'near', 'road', 'mapZones', 'hospIn',
            'staff', 'staffCrit', 'mp', 'vuln');
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

    /** ระบบประเมินสถานะโรงพยาบาลจากข้อมูล (ข้อเสนอ — SAT เป็นผู้ประกาศ) → [สี, [[สี, เหตุผล], ...]] */
    private function suggest($c) {
        $col = Sat_Model::colors();
        $reasons = array();
        foreach ($c['items'] as $it) {
            if (in_array($it['status'], array('yellow', 'orange', 'red'), true)) {
                $reasons[] = array($it['status'], $it['name'] . ': ' . $col[$it['status']]['name']
                    . ($it['note'] !== '' ? ' — ' . mb_substr(preg_replace('/\s+/u', ' ', $it['note']), 0, 90) : '')
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

    /** สถานะที่ SAT ประกาศล่าสุด */
    private function declared($settings) {
        $o = isset($settings['overall']) ? json_decode((string) $settings['overall']['sval'], true) : null;
        if (!is_array($o) || !self::color(isset($o['status']) ? $o['status'] : '')) {
            return null;
        }
        $o['at'] = (string) $settings['overall']['updated_at'];
        $o['by'] = (string) $settings['overall']['by_name'];
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
        $this->view->sat = $c;
        $this->view->satSuggest = $sug;
        $this->view->satReasons = $reasons;
        $this->view->satDeclared = $this->declared($c['settings']);
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
                'at' => flood_thai_date($r['created_at']));
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
        }
        if ($c['mp']['ready'] && $c['mp']['short']) {
            $staffAuto .= ($staffAuto !== '' ? ' · ' : '') . 'RN เวรนี้ขาดกรอบ ' . count($c['mp']['short']) . ' หน่วย';
        }
        // ผู้ป่วยเสี่ยง
        $v = $c['vuln'];
        $patAuto = $v['ready'] ? 'ทะเบียนกลุ่มเปราะบาง ' . $v['total'] . ' ราย · อยู่ในพื้นที่น้ำท่วม ' . $v['in_zone']
            . ' (ยังไม่อพยพ ' . $v['in_zone_waiting'] . ')' : '';

        $L[] = $line('rain');
        $L[] = $line('water');
        $L[] = $line('road', $roadAuto);
        $L[] = $line('facility', $facAuto);
        if ($closed) {
            $L[] = '   ปิด/ย้ายจุดบริการ: ' . implode(' · ', $closed);
        }
        $L[] = $line('ems');
        if ($rt) {
            $L[] = '   เส้นทาง: ' . implode(' · ', $rt);
        }
        $L[] = $line('staff', $staffAuto);
        $L[] = $line('patient', $patAuto);
        $L[] = $line('hosp_site');
        $L[] = $line('ed_load');
        $u = array();
        foreach (array('util_power', 'util_water', 'util_o2', 'util_fuel', 'util_it') as $code) {
            $it = $items[$code];
            $u[] = $it['name'] . ' ' . ($it['status'] !== '' ? $col[$it['status']]['emoji'] : '⚪')
                . ($it['note'] !== '' ? ' (' . mb_substr(preg_replace('/\s+/u', ' ', $it['note']), 0, 60) . ')' : '');
        }
        $L[] = '⚡ ระบบสำคัญ: ' . implode(' · ', $u);
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
}
