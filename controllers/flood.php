<?php

/**
 * ระบบเจ้าหน้าที่ (ต้องล็อกอิน) — แนวเดียวกับ controllers/coc.php ของระบบ COC
 * action หนึ่งตัว = หนึ่งหน้าจอ / หนึ่ง endpoint (ajax คืน JSON รูปแบบ {chk, msg, ...})
 */
class Flood extends Controller {

    function __construct() {
        parent::__construct();
        $this->view->css = array('flood/css/default.css');
        $this->view->js = array('flood/js/default.js');
        $this->view->pageMenu = 'flood';
    }

    /** Bootstrap เรียกหลังสร้าง controller — จุดแรกที่ $this->model พร้อมใช้ */
    public function loadModel($name) {
        parent::loadModel('flood');
        $this->guardRequest();
        $this->touchActivity();
    }

    /* ==================== ผู้ใช้ / สิทธิ์ ==================== */

    private function user() {
        return flood_session_user();
    }

    private function role() {
        $u = $this->user();
        return flood_normalize_role(isset($u['role']) ? $u['role'] : '');
    }

    private function isAdmin() {
        return flood_is_admin_role($this->role());
    }

    /** เจ้าหน้าที่ที่แก้ไขข้อมูลงานได้ (ไม่ใช่ viewer/team) */
    private function isOfficer() {
        return $this->isAdmin() || $this->role() === 'officer';
    }

    private function teamId() {
        $u = $this->user();
        return !empty($u['team_id']) ? (int) $u['team_id'] : 0;
    }

    /** ทีมของผู้ใช้สำหรับกรองใบงาน — บัญชีทีมที่ไม่มีทีม (ถูกลบทีม) ได้ -1 = ไม่เห็นงานใดเลย */
    private function teamScope() {
        return $this->teamId() > 0 ? $this->teamId() : -1;
    }

    private function can($menu) {
        return flood_can_menu($this->role(), $menu);
    }

    private function currentAction() {
        $url = isset($_GET['url']) ? trim((string) $_GET['url'], '/') : '';
        $seg = explode('/', $url);
        return isset($seg[1]) ? $seg[1] : '';
    }

    private function isPost() {
        return isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';
    }

    /** ด่านเดียวคุมทุก action: POST ต้องมี CSRF token / บัญชีที่ต้องเปลี่ยนรหัสผ่านเข้าได้แค่หน้าโปรไฟล์ */
    private function guardRequest() {
        if ($this->isPost() && !flood_csrf_valid()) {
            flood_json(array('chk' => false, 'csrf' => true, 'msg' => 'หน้าจอหมดอายุ กรุณารีเฟรชหน้าแล้วลองใหม่'), 403);
        }
        $u = $this->user();
        if (!empty($u['must_change_password'])
            && !in_array($this->currentAction(), array('profile', 'changePassword', 'ping'), true)) {
            if (flood_is_ajax()) {
                flood_json(array('chk' => false, 'msg' => 'กรุณาเปลี่ยนรหัสผ่านก่อนใช้งาน'));
            }
            header('Location: ' . URL . 'flood/profile');
            exit;
        }
    }

    /**
     * บันทึกเวลาใช้งานล่าสุด + ตรวจว่าบัญชียังใช้ได้ — อย่างมาก 1 ครั้ง/นาที
     * (หน้าจอที่เปิดค้างส่ง ping ทุก 60 วินาที จึงถูกนับว่าออนไลน์ต่อเนื่อง)
     */
    private function touchActivity($force = false, $page = null) {
        $u = $this->user();
        if (empty($u['user_id'])) {
            return;
        }
        $now = time();
        $last = Session::get('flood_last_touch');
        if (!$force && $last !== null && is_numeric($last) && ($now - (int) $last) < 60) {
            return;
        }
        Session::set('flood_last_touch', $now);
        try {
            $st = $this->model->getSessionState($u['user_id']);
            if (!$st || (int) $st['is_active'] !== 1) {
                $this->forceLogout('บัญชีถูกปิดใช้งาน');
            }
            if (!empty($st['session_revoked_at']) && !empty($u['login_at']) && $st['session_revoked_at'] >= $u['login_at']) {
                $this->forceLogout('ผู้ดูแลระบบให้ออกจากระบบ');
            }
            // ผู้ดูแลเปลี่ยนสิทธิ์/ทีม — มีผลทันทีโดยไม่ต้องล็อกอินใหม่
            $newTeam = $st['team_id'] !== null ? (int) $st['team_id'] : null;
            if ($st['role'] !== $u['role'] || $newTeam !== (isset($u['team_id']) ? $u['team_id'] : null) || $st['name'] !== $u['name']) {
                $u['role'] = flood_normalize_role($st['role']);
                $u['team_id'] = $newTeam;
                $u['team_name'] = $st['team_name'];
                $u['name'] = $st['name'];
                Session::set('User_FLOOD', $u);
            }
            if ($page === null && !flood_is_ajax()) {
                $page = $this->pageLabel();
            }
            $this->model->touchUserActivity($u['user_id'], flood_client_ip(), $page);
        } catch (Exception $e) {
            // ไม่ให้เรื่องบันทึกสถิติทำให้หน้าเว็บพัง
        }
    }

    private function forceLogout($reason) {
        $u = $this->user();
        Audit::loginEvent($this->model->db, 'kicked', array(
            'user_id' => isset($u['user_id']) ? $u['user_id'] : null,
            'loginname' => isset($u['loginname']) ? $u['loginname'] : '',
            'name' => isset($u['name']) ? $u['name'] : '',
            'role' => isset($u['role']) ? $u['role'] : '',
            'reason' => $reason,
        ));
        Session::destroy();
        if (flood_is_ajax()) {
            flood_json(array('chk' => false, 'relogin' => true, 'msg' => $reason . ' กรุณาเข้าสู่ระบบใหม่'));
        }
        header('Location: ' . URL . 'login?m=' . rawurlencode($reason));
        exit;
    }

    /** ชื่อหน้าจอที่แสดงในรายชื่อผู้ใช้ออนไลน์ */
    private function pageLabel() {
        $map = array(
            '' => 'ภาพรวม', 'index' => 'ภาพรวม', 'zones' => 'พื้นที่ประกาศ', 'zoneForm' => 'ประกาศ/แก้ไขพื้นที่',
            'reports' => 'รายงานจากประชาชน', 'help' => 'คำขอความช่วยเหลือ', 'helpForm' => 'รับเรื่องทางโทรศัพท์',
            'helpView' => 'ใบงาน', 'vulnerable' => 'ทะเบียนกลุ่มเปราะบาง', 'vulnerableForm' => 'แก้ไขทะเบียนกลุ่มเปราะบาง',
            'teams' => 'ทีมช่วยเหลือ', 'settingsUsers' => 'ผู้ใช้งาน', 'onlineUsers' => 'ผู้ใช้งานออนไลน์',
            'loginLog' => 'ประวัติการเข้าใช้งาน', 'auditLog' => 'ประวัติการแก้ไขข้อมูล', 'profile' => 'ข้อมูลผู้ใช้',
            'zoneLevels' => 'ระดับพื้นที่', 'notices' => 'ข้อมูลที่ควรรู้', 'zoneImport' => 'นำเข้าพื้นที่จาก arankub',
        );
        $a = $this->currentAction();
        return isset($map[$a]) ? $map[$a] : null;
    }

    /** ไม่มีสิทธิ์: ajax → JSON / หน้าเว็บ → หน้าแจ้งเตือน */
    private function deny($asJson, $msg = 'ท่านไม่มีสิทธิ์ใช้งานส่วนนี้') {
        if ($asJson || flood_is_ajax()) {
            flood_json(array('chk' => false, 'msg' => $msg), 403);
        }
        $this->view->blockedMessage = $msg;
        $this->view->pageTitle = 'ไม่มีสิทธิ์เข้าใช้';
        $this->view->activeTab = '';
        $this->view->rander('flood/no_permission');
        exit;
    }

    private function requireMenu($menu, $asJson = false) {
        if (!$this->can($menu)) {
            $this->deny($asJson);
        }
    }

    private function requireOfficer($asJson = false) {
        if (!$this->isOfficer()) {
            $this->deny($asJson, 'เฉพาะเจ้าหน้าที่ศูนย์หรือผู้ดูแลระบบ');
        }
    }

    private function requireAdmin($asJson = false) {
        if (!$this->isAdmin()) {
            $this->deny($asJson, 'เฉพาะผู้ดูแลระบบ');
        }
    }

    private function useMap() {
        $this->view->useMap = true;
        $this->view->js[] = '../public/js/flood-map.js';
    }

    private function pageParam() {
        return max(1, (int) flood_in('page', 1, $_GET));
    }

    /* ==================== ภาพรวม ==================== */

    function index() {
        $this->requireMenu('stats');
        $this->useMap();
        $this->view->js[] = 'flood/js/dashboard.js';
        $this->view->dash = $this->dashboardPayload();
        // ข้อมูลที่ควรรู้ (ปักหมุดก่อน) — ไม่มีตาราง/ผิดพลาด = ไม่แสดงการ์ด
        $this->view->dashNotices = array();
        try {
            $nm = $this->noticeModel();
            if ($nm->ensureTable()) {
                $this->view->dashNotices = $nm->listNotices('active', '', 6);
            }
        } catch (Exception $e) {
            error_log('[flood] dashboard notices: ' . $e->getMessage());
        }
        $this->view->activeTab = 'stats';
        $this->view->pageTitle = 'ภาพรวมสถานการณ์';
        $this->view->rander('flood/index');
    }

    function dashboardData() {
        $this->requireMenu('stats', true);
        flood_json(array('chk' => true) + $this->dashboardPayload());
    }

    private function dashboardPayload() {
        $role = $this->role();
        $teamId = $role === 'team' ? $this->teamScope() : null;
        $zones = $this->model->listZones(array('status' => 'active'));
        $out = array(
            'role' => $role,
            'zone_counts' => $this->model->zoneCounts(),
            'zones' => $this->model->withZonePhotos(array_map(array($this->model, 'zoneForMap'), $zones)),
            'help_counts' => $this->model->helpCounts($teamId),
            'online' => $this->model->getOnlineSummary(),
            'updated_th' => flood_thai_date(time()),
            'help_map' => array(),
            'help_recent' => array(),
        );
        if ($role !== 'viewer') {
            $out['help_map'] = $this->model->openHelpForMap($teamId);
            $needs = flood_help_needs();
            foreach ($this->model->recentHelp($teamId, 8) as $h) {
                $out['help_recent'][] = array(
                    'help_id' => (int) $h['help_id'],
                    'ref_code' => $h['ref_code'],
                    'priority' => $h['priority'],
                    'status' => $h['status'],
                    'needs' => flood_codes_names($h['needs'], $needs),
                    'requester_name' => $h['requester_name'],
                    'area' => trim(($h['tambon_name'] ? 'ต.' . $h['tambon_name'] . ' ' : '') . ($h['amphoe_name'] ? 'อ.' . $h['amphoe_name'] : '')),
                    'team_name' => $h['team_name'],
                    'ago' => flood_ago($h['created_at']),
                );
            }
        }
        if ($this->can('reports')) {
            $out['report_counts'] = $this->model->reportCounts();
            $out['reports_map'] = $this->model->pendingReportsForMap();
        }
        if ($this->can('vulnerable')) {
            $vz = $this->model->vulnerableInZones();
            $out['vulnerable'] = $this->model->vulnerableSummary($vz);
            $out['vulnerable_map'] = array();
            $evac = flood_evac_statuses();
            foreach ($vz as $v) {
                $out['vulnerable_map'][] = array(
                    'person_id' => (int) $v['person_id'],
                    'name' => $v['name'],
                    'lat' => (float) $v['lat'],
                    'lng' => (float) $v['lng'],
                    'groups' => $v['group_names'],
                    'evac_status' => $v['evac_status'],
                    'evac_name' => $evac[$v['evac_status']]['name'],
                    'zone_name' => $v['zone_name'],
                    'zone_level' => $v['zone_level'],
                );
            }
        }
        return $out;
    }

    /* ==================== ผู้ใช้ออนไลน์ ==================== */

    /** หน้าจอที่เปิดค้างส่งสัญญาณทุก 60 วินาที — คืนจำนวน/รายชื่อคนออนไลน์ไปแสดงบนแถบด้านบน */
    function ping() {
        $page = mb_substr(flood_in('page', '', $_POST), 0, 120);
        $this->touchActivity(true, $page !== '' ? $page : null);
        $summary = $this->model->getOnlineSummary();
        $users = array();
        foreach ($this->model->listOnlineNow(60) as $r) {
            $users[] = array(
                'name' => $r['name'],
                'role' => $r['role_label'],
                'team' => $r['team_name'] ? $r['team_name'] : (string) $r['org_name'],
                'page' => (string) $r['last_page'],
                'ago' => $r['ago_label'],
                'me' => (int) $r['user_id'] === (int) $this->user()['user_id'],
            );
        }
        // ตัวเลขบนแถบเมนู: รายงานรอตรวจ / คำขอที่รอดำเนินการ
        $counts = array();
        if ($this->can('reports')) {
            $rc = $this->model->reportCounts();
            $counts['reports'] = $rc['pending'] + $rc['waiting'];   // รอตรวจ + ยืนยันแล้วรอประกาศ
        }
        if ($this->role() === 'team') {
            $hc = $this->model->helpCounts($this->teamScope());
            $counts['help'] = $hc['assigned'] + $hc['in_progress'];
        } elseif ($this->can('help')) {
            $hc = $this->model->helpCounts();
            $counts['help'] = $hc['waiting'];
        }
        flood_json(array('chk' => true, 'online' => $summary['online_now'], 'users' => $users,
            'counts' => $counts, 'ts' => date('H:i')));
    }

    function onlineUsers() {
        $this->requireAdmin();
        $hours = (int) flood_in('hours', 24, $_GET);
        if (!in_array($hours, array(1, 8, 24, 72, 168), true)) {
            $hours = 24;
        }
        $filters = array('q' => flood_in('q', '', $_GET), 'role' => flood_in('role', '', $_GET));
        $this->view->onlineHours = $hours;
        $this->view->onlineFilters = $filters;
        $this->view->onlineSummary = $this->model->getOnlineSummary();
        $this->view->onlineRows = $this->model->listRecentActiveUsers($hours, $filters);
        $this->view->js[] = 'flood/js/online_users.js';
        $this->view->activeTab = 'onlineUsers';
        $this->view->pageTitle = 'ผู้ใช้งานออนไลน์';
        $this->view->rander('flood/online_users');
    }

    function onlineUsersData() {
        $this->requireAdmin(true);
        $hours = (int) flood_in('hours', 24, $_GET);
        if (!in_array($hours, array(1, 8, 24, 72, 168), true)) {
            $hours = 24;
        }
        $filters = array('q' => flood_in('q', '', $_GET), 'role' => flood_in('role', '', $_GET));
        flood_json(array(
            'chk' => true,
            'summary' => $this->model->getOnlineSummary(),
            'rows' => $this->model->listRecentActiveUsers($hours, $filters),
            'me' => (int) $this->user()['user_id'],
            'ts' => date('H:i:s'),
        ));
    }

    /** ผู้ดูแลสั่งให้ผู้ใช้ออกจากระบบ (เช่น ลืมออกจากเครื่องส่วนกลาง) */
    function kickUser() {
        $this->requireAdmin(true);
        $id = (int) flood_in('user_id', 0, $_POST);
        if ($id <= 0 || $id === (int) $this->user()['user_id']) {
            flood_json(array('chk' => false, 'msg' => 'เลือกผู้ใช้ไม่ถูกต้อง'));
        }
        $target = $this->model->getUserById($id);
        if (!$target) {
            flood_json(array('chk' => false, 'msg' => 'ไม่พบผู้ใช้'));
        }
        if ($target['role'] === 'super_admin' && $this->role() !== 'super_admin') {
            flood_json(array('chk' => false, 'msg' => 'ไม่มีสิทธิ์สั่งผู้ดูแลสูงสุดออกจากระบบ'));
        }
        $this->model->kickUser($id);
        flood_json(array('chk' => true, 'msg' => 'สั่งให้ ' . $target['name'] . ' ออกจากระบบแล้ว (มีผลภายใน 1 นาที)'));
    }

    /* ==================== พื้นที่ประกาศ ==================== */

    function zones() {
        $this->requireMenu('zones');
        $status = flood_in('status', 'active', $_GET);
        if (!in_array($status, array('active', 'ended', 'all'), true)) {
            $status = 'active';
        }
        $filters = array(
            'status' => $status,
            'level' => flood_in('level', '', $_GET),
            'region' => flood_region_param(),
            'province' => flood_province_param(),
            'amphoe' => flood_in('amphoe', '', $_GET),
            'q' => flood_in('q', '', $_GET),
        );
        $this->useMap();
        $this->view->js[] = 'flood/js/zones.js';
        $this->view->zoneFilters = $filters;
        $this->view->zoneRows = $this->model->listZones($filters);
        $this->view->zonePhotoIds = $this->model->zonePhotoIds(array_map(function ($z) { return $z['zone_id']; }, $this->view->zoneRows));
        $this->view->zoneCounts = $this->model->zoneCounts();
        $this->view->amphoes = $this->model->getAmphoes();
        $this->view->provinces = $this->model->getProvinces();
        $this->view->regions = $this->model->getRegions();
        $this->view->canEdit = $this->isOfficer();
        $this->view->canImport = $this->isAdmin();
        $this->view->activeTab = 'zones';
        $this->view->pageTitle = 'พื้นที่ประกาศ';
        $this->view->rander('flood/zones');
    }

    function zoneForm($id = null) {
        $this->requireOfficer();
        $zone = null;
        if ($id !== null) {
            $zone = $this->model->getZone((int) $id);
            if (!$zone) {
                header('Location: ' . URL . 'flood/zones');
                exit;
            }
        }
        // สร้างพื้นที่จากรายงานประชาชน — ตั้งจุดศูนย์กลาง/รัศมี/ระดับให้ก่อน เจ้าหน้าที่ปรับต่อได้
        $prefill = null;
        $reportId = (int) flood_in('report_id', 0, $_GET);
        if (!$zone && $reportId > 0) {
            $r = $this->model->getReport($reportId);
            if ($r) {
                $ext = flood_extent_options();
                $area = $this->model->guessArea($r['lat'], $r['lng']);
                $prefill = array(
                    'report_id' => (int) $r['report_id'],
                    'ref_code' => $r['ref_code'],
                    'lat' => (float) $r['lat'],
                    'lng' => (float) $r['lng'],
                    'radius_m' => isset($ext[$r['extent']]) ? $ext[$r['extent']]['radius'] : 200,
                    'level' => $this->levelFromReport($r),
                    'name' => $area ? 'บริเวณ ต.' . $area['name'] : '',
                    'amphoe_code' => $area ? $area['amphoe_code'] : '',
                    'tambon_code' => $area ? $area['tambon_code'] : '',
                    'note' => (string) $r['place_note'],
                );
            }
        }
        $this->useMap();
        $this->view->js[] = '../public/js/flood-upload.js';
        $this->view->js[] = 'flood/js/zone_form.js';
        $this->view->zone = $zone;
        $this->view->prefill = $prefill;
        // ภาพประกอบในบอลลูน: ภาพเดิมของพื้นที่ + รูปจากรายงานประชาชนที่เลือกมาแสดงได้
        $this->view->zonePhotos = $zone ? $this->model->zonePhotos((int) $zone['zone_id']) : array();
        $this->view->reportPhotos = $this->model->reportPhotosForZone($zone ? (int) $zone['zone_id'] : 0,
            $prefill ? (int) $prefill['report_id'] : 0);
        $this->view->photoMax = flood_zone_photo_max();
        $this->view->hasGd = flood_has_gd();
        $this->view->otherZones = array_values(array_filter(array_map(array($this->model, 'zoneForMap'),
            $this->model->listZones(array('status' => 'active'))), function ($z) use ($zone) {
                return !$zone || $z['zone_id'] !== (int) $zone['zone_id'];
            }));
        $this->view->pendingReports = $this->model->pendingReportsForMap(200);
        $this->view->amphoes = $this->model->getAmphoes();
        $this->view->provinces = $this->model->getProvinces();
        $this->view->regions = $this->model->getRegions();
        $this->view->tambons = array();   // ตำบลโหลดทีละอำเภอผ่าน api/tambons
        $this->view->activeTab = 'zones';
        $this->view->pageTitle = $zone ? 'แก้ไขพื้นที่ประกาศ' : 'ประกาศพื้นที่ใหม่';
        $this->view->autoRefresh = false;   // หน้าฟอร์ม: ไม่รีเฟรชเอง กันข้อมูลที่กรอกหาย
        $this->view->rander('flood/zone_form');
    }

    function saveZone() {
        $this->requireOfficer(true);
        $id = (int) flood_in('zone_id', 0, $_POST);
        $current = $id > 0 ? $this->model->getZone($id) : null;
        if ($id > 0 && !$current) {
            flood_json(array('chk' => false, 'msg' => 'ไม่พบพื้นที่นี้'));
        }
        $name = mb_substr(flood_in('name', '', $_POST), 0, 200);
        if (mb_strlen($name) < 2) {
            flood_json(array('chk' => false, 'msg' => 'กรุณาตั้งชื่อพื้นที่ (เช่น ชุมชน/ถนน/หมู่บ้าน)'));
        }
        $level = flood_in('level', '', $_POST);
        if (!array_key_exists($level, flood_zone_levels()) && !($current && $current['level'] === $level)) {
            flood_json(array('chk' => false, 'msg' => 'กรุณาเลือกระดับของพื้นที่'));
        }
        $shape = flood_in('shape', 'circle', $_POST) === 'polygon' ? 'polygon' : 'circle';
        $data = array('name' => $name, 'level' => $level, 'shape' => $shape);
        if ($shape === 'circle') {
            $lat = flood_in('center_lat', '', $_POST);
            $lng = flood_in('center_lng', '', $_POST);
            $radius = (int) flood_in('radius_m', 0, $_POST);
            if (!flood_valid_latlng($lat, $lng)) {
                flood_json(array('chk' => false, 'msg' => 'กรุณาแตะบนแผนที่เพื่อวางจุดศูนย์กลางของพื้นที่'));
            }
            if ($radius < 20 || $radius > 30000) {
                flood_json(array('chk' => false, 'msg' => 'รัศมีต้องอยู่ระหว่าง 20 เมตร ถึง 30 กิโลเมตร'));
            }
            $data['center_lat'] = round((float) $lat, 7);
            $data['center_lng'] = round((float) $lng, 7);
            $data['radius_m'] = $radius;
            $data['polygon_json'] = null;
        } else {
            $poly = flood_parse_polygon(flood_in('polygon_json', '', $_POST));
            if (!$poly) {
                flood_json(array('chk' => false, 'msg' => 'กรุณาแตะบนแผนที่อย่างน้อย 3 จุดเพื่อวาดขอบเขตพื้นที่'));
            }
            $c = flood_polygon_centroid($poly);
            $data['center_lat'] = $c[0];
            $data['center_lng'] = $c[1];
            $data['radius_m'] = null;
            $data['polygon_json'] = json_encode($poly);
        }
        $amphoe = flood_in('amphoe_code', '', $_POST);
        if ($amphoe !== '' && !$this->model->amphoeExists($amphoe)) {
            $amphoe = '';
        }
        $tambon = $this->model->validTambon(flood_in('tambon_code', '', $_POST), $amphoe);
        if ($tambon && $amphoe === '') {
            $amphoe = substr($tambon, 0, 4);
        }
        if ($amphoe === '') {
            $g = $this->model->guessArea($data['center_lat'], $data['center_lng']);
            if ($g) {
                $amphoe = $g['amphoe_code'];
                $tambon = $tambon ? $tambon : $g['tambon_code'];
            }
        }
        $data['amphoe_code'] = $amphoe !== '' ? $amphoe : null;
        $data['tambon_code'] = $tambon;
        $data['note'] = mb_substr(flood_in('note', '', $_POST), 0, 2000) ?: null;
        $source = flood_in('source', 'officer', $_POST);
        $data['source'] = array_key_exists($source, flood_zone_sources()) ? $source : 'officer';
        $started = flood_in('started_at', '', $_POST);
        if ($started !== '') {
            $ts = strtotime(str_replace('T', ' ', $started));
            if (!$ts || $ts > time() + 86400) {
                flood_json(array('chk' => false, 'msg' => 'เวลาประกาศไม่ถูกต้อง'));
            }
            $data['started_at'] = date('Y-m-d H:i:s', $ts);
        } elseif ($id === 0) {
            $data['started_at'] = date('Y-m-d H:i:s');
        }
        if ($id === 0) {
            $data['status'] = 'active';
        }

        // มาจากรายงานประชาชน → ปิดรายงานนั้นเป็น "ยืนยันแล้ว" ผูกกับพื้นที่นี้
        $reportId = (int) flood_in('report_id', 0, $_POST);
        $report = $reportId > 0 ? $this->model->getReport($reportId) : null;

        // ---- ภาพประกอบ (แสดงในบอลลูนบนแผนที่สาธารณะ) ----
        $max = flood_zone_photo_max();
        $currentIds = array();
        foreach ($id > 0 ? $this->model->zonePhotos($id) : array() as $ph) {
            $currentIds[] = (int) $ph['attachment_id'];
        }
        $removeIds = array_values(array_intersect($currentIds, $this->postIds('remove_photo_ids')));
        $pickIds = $this->postIds('report_photo_ids');
        $picks = array();
        if ($pickIds) {
            foreach ($this->model->reportPhotosForZone($id, $report ? $reportId : 0) as $rp) {
                if ((int) $rp['used'] === 0 && in_array((int) $rp['attachment_id'], $pickIds, true)) {
                    $picks[] = $rp;
                }
            }
        }
        $uploadCount = 0;
        if (isset($_FILES['photos']['error'])) {
            foreach ((array) $_FILES['photos']['error'] as $err) {
                $uploadCount += (int) $err !== UPLOAD_ERR_NO_FILE ? 1 : 0;
            }
        }
        $total = count($currentIds) - count($removeIds) + count($picks) + $uploadCount;
        if ($total > $max) {
            flood_json(array('chk' => false, 'msg' => 'ภาพประกอบรวมได้ไม่เกิน ' . $max . ' ภาพต่อพื้นที่ (ตอนนี้ ' . $total
                . ' ภาพ) — ติ๊ก "ลบ" ภาพเดิมหรือเลือกภาพน้อยลง'));
        }
        $newPhotos = array();
        try {
            $newPhotos = $this->publishReportPhotos($picks);
            $newPhotos = array_merge($newPhotos, flood_store_images('photos', $max, 12582912, true));
        } catch (Exception $e) {
            foreach ($newPhotos as $p) {
                flood_remove_stored_image($p['file_path']);
            }
            flood_json(array('chk' => false, 'msg' => $e->getMessage()));
        }

        $uid = (int) $this->user()['user_id'];
        try {
            list($newId, $removedPaths) = $this->model->saveZoneFull($id, $data, $uid, $newPhotos, $removeIds,
                $report ? array($reportId, 'นำไปประกาศเป็นพื้นที่: ' . $name) : null);
        } catch (Exception $e) {
            foreach ($newPhotos as $p) {
                flood_remove_stored_image($p['file_path']);
            }
            error_log('[flood] saveZone: ' . $e->getMessage());
            flood_json(array('chk' => false, 'msg' => 'บันทึกไม่สำเร็จ กรุณาลองใหม่'));
        }
        foreach ($removedPaths as $p) {
            flood_remove_stored_image($p);
        }
        $msg = 'บันทึกพื้นที่ประกาศแล้ว';
        if ($newPhotos || $removedPaths) {
            $msg .= ' · ภาพประกอบ ' . ($total) . ' ภาพ';
        }
        flood_json(array('chk' => true, 'msg' => $msg, 'zone_id' => $newId, 'url' => URL . 'flood/zones'));
    }

    /** รหัสตัวเลขจากฟิลด์ POST แบบ name[] */
    private function postIds($field) {
        $raw = isset($_POST[$field]) ? (array) $_POST[$field] : array();
        return array_values(array_unique(array_filter(array_map('intval', $raw), function ($v) { return $v > 0; })));
    }

    /**
     * คัดลอกรูปจากรายงานประชาชนเป็นภาพประกอบพื้นที่ — เข้ารหัสใหม่ (ลบ EXIF) + ภาพย่อ ไม่แตะไฟล์ต้นฉบับ
     * $rows = แถวจาก model->reportPhotosForZone ที่ผ่านการเลือกแล้ว
     */
    private function publishReportPhotos($rows) {
        $out = array();
        $base = realpath(flood_upload_dir());
        try {
            foreach ($rows as $r) {
                $src = realpath(flood_upload_dir() . '/' . $r['file_path']);
                if (!$base || !$src || strpos($src, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($src)) {
                    throw new Exception('ไม่พบไฟล์รูปของรายงาน ' . $r['ref_code']);
                }
                $out[] = flood_publish_image($src, $r['mime'], 'report:' . (int) $r['attachment_id']);
            }
        } catch (Exception $e) {
            foreach ($out as $p) {
                flood_remove_stored_image($p['file_path']);
            }
            throw $e;
        }
        return $out;
    }

    /** ปิดประกาศ (น้ำลดแล้ว) / ประกาศใหม่อีกครั้ง */
    function zoneStatus() {
        $this->requireOfficer(true);
        $id = (int) flood_in('zone_id', 0, $_POST);
        $status = flood_in('status', '', $_POST) === 'active' ? 'active' : 'ended';
        $zone = $this->model->getZone($id);
        if (!$zone) {
            flood_json(array('chk' => false, 'msg' => 'ไม่พบพื้นที่นี้'));
        }
        $this->model->setZoneStatus($id, $status, (int) $this->user()['user_id']);
        flood_json(array('chk' => true,
            'msg' => $status === 'ended' ? 'ปิดประกาศ "' . $zone['name'] . '" แล้ว' : 'ประกาศ "' . $zone['name'] . '" อีกครั้งแล้ว'));
    }

    /**
     * ระดับตั้งต้นตอนสร้างพื้นที่จากรายงาน (เจ้าหน้าที่เปลี่ยนได้ในฟอร์ม)
     * ผ่านไม่ได้เลย/ต้องใช้เรือ → รถทุกชนิดผ่านไม่ได้ · เฉพาะรถกระบะ/รถสูง หรือน้ำถึงเอว → รถเล็กผ่านไม่ได้ · นอกนั้น → เฝ้าระวัง
     * ระดับที่ผู้ดูแลปิดใช้งานไว้จะถูกข้ามไปใช้ตัวถัดไป
     */
    private function levelFromReport($r) {
        $want = array();
        if (in_array($r['vehicle'], array('none', 'boat'), true)) {
            $want[] = 'impassable';
        }
        if (in_array($r['vehicle'], array('high', 'none', 'boat'), true) || in_array($r['depth'], array('waist', 'above_waist'), true)) {
            $want[] = 'blocked';
        }
        $want[] = 'watch';
        $active = flood_zone_levels();
        foreach ($want as $code) {
            if (isset($active[$code])) {
                return $code;
            }
        }
        $codes = array_keys($active);
        return $codes ? end($codes) : 'watch';
    }

    /* ==================== ระดับพื้นที่ (ผู้ดูแล) ==================== */

    function zoneLevels() {
        $this->requireAdmin();
        $ready = $this->model->ensureLevelTable();
        $this->view->js[] = 'flood/js/zone_levels.js';
        $this->view->levelReady = $ready;
        $this->view->levelRows = $ready ? $this->model->listLevelsAdmin() : array();
        $this->view->activeTab = 'zoneLevels';
        $this->view->pageTitle = 'ระดับพื้นที่';
        $this->view->autoRefresh = false;   // หน้าฟอร์ม: ไม่รีเฟรชเอง กันข้อมูลที่กรอกหาย
        $this->view->rander('flood/zone_levels');
    }

    function saveLevel() {
        $this->requireAdmin(true);
        if (!$this->model->ensureLevelTable()) {
            flood_json(array('chk' => false, 'msg' => 'ยังไม่มีตาราง flood_zone_level — ให้รันไฟล์ sql/08_flood_zone_level.sql ก่อน'));
        }
        $code = flood_in('level_code', '', $_POST);
        $old = $code !== '' ? $this->model->getLevel($code) : null;
        if ($code !== '' && !$old) {
            flood_json(array('chk' => false, 'msg' => 'ไม่พบระดับนี้'));
        }
        $name = mb_substr(trim(flood_in('name', '', $_POST)), 0, 60);
        if (mb_strlen($name) < 2) {
            flood_json(array('chk' => false, 'msg' => 'กรุณาตั้งชื่อระดับ เช่น รถเล็กผ่านไม่ได้'));
        }
        if ($this->model->levelNameExists($name, $code)) {
            flood_json(array('chk' => false, 'msg' => 'มีระดับชื่อ "' . $name . '" อยู่แล้ว'));
        }
        $color = strtolower(flood_in('color', '', $_POST));
        if (!flood_valid_hex($color)) {
            flood_json(array('chk' => false, 'msg' => 'กรุณาเลือกสี'));
        }
        $icon = flood_in('icon', 'fa-circle', $_POST);
        $active = flood_in('is_active', '1', $_POST) === '1' ? 1 : 0;
        if ($old && (int) $old['is_active'] === 1 && !$active) {
            if ((int) $old['active_zones'] > 0) {
                flood_json(array('chk' => false, 'msg' => 'ยังมีพื้นที่ที่ประกาศอยู่ใช้ระดับนี้ ' . (int) $old['active_zones']
                    . ' แห่ง — เปลี่ยนระดับของพื้นที่เหล่านั้นหรือปิดประกาศก่อน แล้วค่อยปิดใช้ระดับนี้'));
            }
            if ($this->model->countActiveLevels() <= 1) {
                flood_json(array('chk' => false, 'msg' => 'ต้องมีระดับที่ใช้งานอย่างน้อย 1 ระดับ'));
            }
        }
        $newCode = $this->model->saveLevel($code, array(
            'name' => $name,
            'description' => mb_substr(trim(flood_in('description', '', $_POST)), 0, 200) ?: null,
            'color' => $color,
            'icon' => array_key_exists($icon, flood_level_icons()) ? $icon : 'fa-circle',
            'is_active' => $active,
        ), (int) $this->user()['user_id']);
        flood_json(array('chk' => true, 'msg' => ($old ? 'บันทึกระดับ "' : 'เพิ่มระดับ "') . $name . '" แล้ว', 'level_code' => $newCode));
    }

    function moveLevel() {
        $this->requireAdmin(true);
        $code = flood_in('level_code', '', $_POST);
        $dir = flood_in('dir', '', $_POST) === 'up' ? 'up' : 'down';
        if (!$this->model->ensureLevelTable() || !$this->model->getLevel($code)) {
            flood_json(array('chk' => false, 'msg' => 'ไม่พบระดับนี้'));
        }
        $this->model->moveLevel($code, $dir, (int) $this->user()['user_id']);
        flood_json(array('chk' => true, 'msg' => 'เปลี่ยนลำดับแล้ว'));
    }

    /** ลบได้เฉพาะระดับที่ผู้ดูแลเพิ่มเองและไม่เคยมีพื้นที่ใช้ — ระดับตั้งต้นให้ปิดใช้งานแทน */
    function deleteLevel() {
        $this->requireAdmin(true);
        $code = flood_in('level_code', '', $_POST);
        $l = $this->model->ensureLevelTable() ? $this->model->getLevel($code) : null;
        if (!$l) {
            flood_json(array('chk' => false, 'msg' => 'ไม่พบระดับนี้'));
        }
        if (array_key_exists($code, flood_zone_levels_builtin())) {
            flood_json(array('chk' => false, 'msg' => 'ระดับตั้งต้นลบไม่ได้ — ใช้วิธีปิดใช้งานแทน'));
        }
        if ((int) $l['all_zones'] > 0) {
            flood_json(array('chk' => false, 'msg' => 'มีพื้นที่ (รวมที่ปิดประกาศแล้ว) ใช้ระดับนี้ ' . (int) $l['all_zones']
                . ' แห่ง จึงลบไม่ได้ — ใช้วิธีปิดใช้งานแทน'));
        }
        if ((int) $l['is_active'] === 1 && $this->model->countActiveLevels() <= 1) {
            flood_json(array('chk' => false, 'msg' => 'ต้องมีระดับที่ใช้งานอย่างน้อย 1 ระดับ'));
        }
        $this->model->deleteLevel($code);
        flood_json(array('chk' => true, 'msg' => 'ลบระดับ "' . $l['name'] . '" แล้ว'));
    }

    /* ==================== นำเข้าพื้นที่จาก arankub.com (ผู้ดูแล) ==================== */

    /** model แยกไฟล์ — โหลดเฉพาะตอนใช้หน้านำเข้า */
    private function importModel() {
        require_once 'models/zone_import_model.php';
        return new Zone_Import_Model();
    }

    /** ดึงข้อมูล → ตรวจตัวอย่างบนแผนที่/ตาราง → เลือกแล้วนำเข้า (คัดลอกครั้งเดียว ไม่อัปเดตตามต้นทาง) */
    function zoneImport() {
        $this->requireAdmin();
        $imp = $this->importModel();
        $ready = $imp->ensureTable();
        $this->useMap();
        $this->view->js[] = 'flood/js/zone_import.js';
        $this->view->importReady = $ready;
        $this->view->importStats = $ready ? $imp->importedStats() : array('count' => 0, 'active' => 0, 'last_at' => null);
        $this->view->importApiUrl = $imp->apiUrl();
        $this->view->importLevelLabels = Zone_Import_Model::levelLabels();
        Session::set('flood_zone_import', null);   // เปิดหน้าใหม่ = เริ่มใหม่ ไม่ค้างตัวอย่างเก่าไว้ใน session
        $this->view->otherZones = array_map(array($this->model, 'zoneForMap'), $this->model->listZones(array('status' => 'active')));
        $this->view->activeTab = 'zones';
        $this->view->pageTitle = 'นำเข้าพื้นที่จาก arankub.com';
        $this->view->autoRefresh = false;   // มีตัวอย่างที่กำลังตรวจอยู่ — ไม่รีเฟรชเอง
        $this->view->rander('flood/zone_import');
    }

    function zoneImportPreview() {
        $this->requireAdmin(true);
        if (!$this->isPost()) {
            $this->deny(true, 'ต้องส่งแบบ POST');   // GET ข้ามการตรวจ CSRF — ไม่ให้ลิงก์จากเว็บอื่นสั่งดึงข้อมูลแทนผู้ดูแล
        }
        @set_time_limit(120);
        $imp = $this->importModel();
        if (!$imp->ensureTable()) {
            flood_json(array('chk' => false, 'msg' => 'ยังไม่มีตาราง flood_zone_import — ให้รันไฟล์ sql/10_flood_zone_import.sql ก่อน'));
        }
        $mode = flood_in('mode', 'fetch', $_POST) === 'paste' ? 'paste' : 'fetch';
        try {
            $raw = $mode === 'paste'
                ? (isset($_POST['json']) && is_string($_POST['json']) ? $_POST['json'] : '')
                : $imp->fetchRemote();
            $preview = $imp->preview($imp->decode($raw));
        } catch (Exception $e) {
            if ($e instanceof PDOException) {
                error_log('[flood] zoneImportPreview: ' . $e->getMessage());
            }
            flood_json(array('chk' => false, 'mode' => $mode,
                'msg' => $e instanceof PDOException ? 'ตรวจข้อมูลไม่สำเร็จ กรุณาลองใหม่' : $e->getMessage()));
        }
        // เก็บรายการที่แปลงแล้วไว้ใน session — ตอนนำเข้าใช้ชุดเดียวกับที่ผู้ดูแลเห็น ไม่ดึงใหม่
        $keep = Zone_Import_Model::sessionItems($preview['items']);
        if (strlen(serialize($keep)) > 4194304) {
            Session::set('flood_zone_import', null);
            flood_json(array('chk' => false, 'mode' => $mode, 'msg' => 'ข้อมูลใหญ่เกินไป — แบ่งวางทีละส่วน (เช่น ครั้งละ 50 พื้นที่)'));
        }
        $token = bin2hex(random_bytes(12));
        Session::set('flood_zone_import', array('token' => $token, 'at' => time(), 'items' => $keep));
        $preview['items'] = array_map(array('Zone_Import_Model', 'publicItem'), $preview['items']);
        flood_json(array('chk' => true, 'token' => $token, 'mode' => $mode) + $preview);
    }

    function zoneImportRun() {
        $this->requireAdmin(true);
        if (!$this->isPost()) {
            $this->deny(true, 'ต้องส่งแบบ POST');
        }
        $saved = Session::get('flood_zone_import');
        $token = flood_in('token', '', $_POST);
        if (!is_array($saved) || $token === '' || !hash_equals((string) $saved['token'], $token)
            || (int) $saved['at'] < time() - 3600) {
            if (is_array($saved) && (int) $saved['at'] < time() - 3600) {
                Session::set('flood_zone_import', null);
            }
            flood_json(array('chk' => false, 'msg' => 'ข้อมูลตัวอย่างหมดอายุหรือถูกดึงใหม่ในหน้าอื่น — ดึงข้อมูลแล้วตรวจอีกครั้ง'));
        }
        $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('strval', $_POST['ids']) : array();
        $pick = array();
        foreach ($saved['items'] as $it) {
            if ($it['state'] === 'new' && in_array($it['ext_id'], $ids, true)) {
                $pick[] = $it;
            }
        }
        if (!$pick) {
            flood_json(array('chk' => false, 'msg' => 'ยังไม่ได้เลือกพื้นที่ที่จะนำเข้า'));
        }
        $map = array();
        foreach (isset($_POST['map']) && is_array($_POST['map']) ? $_POST['map'] : array() as $k => $v) {
            if (is_string($v)) {
                $map[strtoupper((string) $k)] = $v;
            }
        }
        $attribution = flood_in('attribution', '1', $_POST) === '1';
        try {
            list($done, $skipped) = $this->importModel()->import($pick, $map, $attribution, (int) $this->user()['user_id']);
        } catch (Exception $e) {
            error_log('[flood] zoneImportRun: ' . $e->getMessage());
            // ข้อผิดพลาดของฐานข้อมูลไม่แสดงรายละเอียดบนหน้าจอ (ดูใน error log)
            flood_json(array('chk' => false, 'msg' => !($e instanceof PDOException) ? $e->getMessage()
                : 'นำเข้าไม่สำเร็จ (ยังไม่มีพื้นที่ใดถูกบันทึก) — ดึงข้อมูลแล้วลองใหม่'));
        }
        Session::set('flood_zone_import', null);   // ตัวอย่างชุดนี้ใช้แล้ว
        $msg = 'นำเข้าแล้ว ' . count($done) . ' พื้นที่ — ขึ้นแผนที่ประชาชนแล้ว';
        if ($skipped) {
            $msg .= ' · ข้าม ' . $skipped . ' พื้นที่ที่มีผู้นำเข้าไปก่อนแล้ว';
        }
        flood_json(array('chk' => true, 'msg' => $msg, 'done' => $done, 'skipped' => $skipped));
    }

    /* ==================== รายงานจากประชาชน ==================== */

    function reports() {
        $this->requireMenu('reports');
        $status = flood_in('status', 'pending', $_GET);
        if ($status !== 'all' && $status !== 'verified' && !array_key_exists($status, flood_report_view_statuses())) {
            $status = 'pending';
        }
        $impact = flood_in('impact', '', $_GET);
        $filters = array('status' => $status, 'q' => flood_in('q', '', $_GET),
            'impact' => array_key_exists($impact, flood_area_impacts()) ? $impact : '',
            'province' => flood_province_param(), 'region' => flood_region_param());
        $this->view->provinces = $this->model->getProvinces();
        $this->view->regions = $this->model->getRegions();
        $this->useMap();
        $this->view->js[] = 'flood/js/reports.js';
        $this->view->reportFilters = $filters;
        $this->view->reportResult = $this->model->listReports($filters, 50, $this->pageParam());
        $this->view->reportCounts = $this->model->reportCounts();
        $this->view->activeZones = array_map(array($this->model, 'zoneForMap'), $this->model->listZones(array('status' => 'active')));
        $this->view->activeTab = 'reports';
        $this->view->pageTitle = 'รายงานจากประชาชน';
        $this->view->rander('flood/reports');
    }

    function reportData($id = null) {
        $this->requireMenu('reports', true);
        $r = $this->model->getReport((int) $id);
        if (!$r) {
            flood_json(array('chk' => false, 'msg' => 'ไม่พบรายงาน'));
        }
        $area = $this->model->guessArea($r['lat'], $r['lng']);
        $inside = array();
        foreach ($this->model->zonesContaining($r['lat'], $r['lng']) as $z) {
            $inside[] = array('zone_id' => (int) $z['zone_id'], 'name' => $z['name'], 'level' => $z['level']);
        }
        $photos = array();
        foreach ($r['photos'] as $p) {
            $photos[] = URL . 'flood/attachment/' . (int) $p['attachment_id'];
        }
        $st = flood_report_statuses();
        $vst = flood_report_view_statuses();
        $view = flood_report_view_status($r);
        flood_json(array('chk' => true, 'report' => array(
            'report_id' => (int) $r['report_id'],
            'ref_code' => $r['ref_code'],
            'lat' => (float) $r['lat'],
            'lng' => (float) $r['lng'],
            'accuracy_m' => $r['accuracy_m'] !== null ? (int) $r['accuracy_m'] : null,
            'loc_method' => $r['loc_method'],
            'depth' => flood_opt_name(flood_depth_options(), $r['depth']),
            'extent' => flood_opt_name(flood_extent_options(), $r['extent']),
            'houses' => $r['houses'] ? flood_opt_name(flood_houses_options(), $r['houses']) : '',
            'vehicle' => $r['vehicle'] ? flood_opt_name(flood_vehicle_options(), $r['vehicle']) : '',
            'trend' => $r['trend'] ? flood_opt_name(flood_trend_options(), $r['trend']) : '',
            'impacts' => implode(', ', flood_codes_names(isset($r['impacts']) ? $r['impacts'] : '', flood_area_impacts())),
            'place_note' => (string) $r['place_note'],
            'reporter_name' => $r['reporter_name'],
            'reporter_phone' => flood_format_phone($r['reporter_phone']),
            'phone_raw' => $r['reporter_phone'],
            'status' => $r['status'],
            'status_name' => $st[$r['status']]['name'],
            'zone_id' => $r['zone_id'] !== null ? (int) $r['zone_id'] : null,
            'zone_name' => $r['zone_name'],
            'zone_status' => $r['zone_status'],
            'view_status' => $view,
            'source' => isset($r['source']) ? $r['source'] : 'public',
            'source_name' => isset($r['source_name']) ? (string) $r['source_name'] : '',
            'source_url' => isset($r['source_url']) ? (string) $r['source_url'] : '',
            'view_status_name' => $vst[$view]['name'],
            'view_status_class' => $vst[$view]['class'],
            'review_note' => (string) $r['review_note'],
            'reviewed_by_name' => (string) $r['reviewed_by_name'],
            'reviewed_th' => flood_thai_date($r['reviewed_at']),
            'created_th' => flood_thai_date($r['created_at']),
            'ago' => flood_ago($r['created_at']),
            'area' => $area ? 'ต.' . $area['name'] . ' อ.' . $area['amphoe_name'] : '',
            'photos' => $photos,
            'inside_zones' => $inside,
        )));
    }

    /**
     * ตรวจรายงาน: attach = ยืนยันและผูกกับพื้นที่ที่ประกาศอยู่แล้ว / reject = ไม่ใช้ข้อมูล / reopen = กลับไปรอตรวจ
     * (การสร้างพื้นที่ใหม่จากรายงานไปทาง flood/zoneForm?report_id=)
     */
    function reviewReport() {
        $this->requireMenu('reports', true);
        $id = (int) flood_in('report_id', 0, $_POST);
        $action = flood_in('action', '', $_POST);
        $note = mb_substr(flood_in('note', '', $_POST), 0, 255);
        $r = $this->model->getReport($id);
        if (!$r) {
            flood_json(array('chk' => false, 'msg' => 'ไม่พบรายงาน'));
        }
        $uid = (int) $this->user()['user_id'];
        if ($action === 'attach') {
            $zoneId = (int) flood_in('zone_id', 0, $_POST);
            $zone = $this->model->getZone($zoneId);
            if (!$zone || $zone['status'] !== 'active') {
                flood_json(array('chk' => false, 'msg' => 'กรุณาเลือกพื้นที่ที่ประกาศอยู่'));
            }
            // เลือกนำรูปจากรายงานนี้ไปแสดงในบอลลูนของพื้นที่ (เท่าที่ยังมีที่ว่าง)
            $newPhotos = array();
            $skipped = 0;
            if (flood_in('publish_photos', '', $_POST) === '1') {
                $rows = array();
                foreach ($this->model->reportPhotosForZone($zoneId, $id) as $rp) {
                    if ((int) $rp['report_id'] === $id && (int) $rp['used'] === 0) {
                        $rows[] = $rp;
                    }
                }
                $room = max(0, flood_zone_photo_max() - count($this->model->zonePhotos($zoneId)));
                $skipped = max(0, count($rows) - $room);
                try {
                    $newPhotos = $this->publishReportPhotos(array_slice($rows, 0, $room));
                } catch (Exception $e) {
                    flood_json(array('chk' => false, 'msg' => $e->getMessage()));
                }
            }
            try {
                $this->model->attachReportToZone($id, $zoneId, $note !== '' ? $note : 'ยืนยันแล้ว อยู่ในพื้นที่ ' . $zone['name'], $uid, $newPhotos);
            } catch (Exception $e) {
                foreach ($newPhotos as $p) {
                    flood_remove_stored_image($p['file_path']);
                }
                error_log('[flood] reviewReport attach: ' . $e->getMessage());
                flood_json(array('chk' => false, 'msg' => 'บันทึกไม่สำเร็จ กรุณาลองใหม่'));
            }
            $msg = 'ยืนยันรายงาน ' . $r['ref_code'] . ' และผูกกับพื้นที่ "' . $zone['name'] . '" แล้ว';
            if ($newPhotos) {
                $msg .= ' · เพิ่มภาพในบอลลูน ' . count($newPhotos) . ' ภาพ';
            }
            if ($skipped) {
                $msg .= ' (ข้าม ' . $skipped . ' ภาพ เพราะพื้นที่นี้มีภาพครบ ' . flood_zone_photo_max() . ' ภาพแล้ว)';
            }
            flood_json(array('chk' => true, 'msg' => $msg));
        }
        // ยืนยันแล้ว (เช่น โทรคุยกับผู้แจ้งแล้ว) แต่ยังไม่ประกาศตอนนี้ → ไปอยู่แท็บ "รอประกาศ"
        if ($action === 'verify') {
            if ($r['status'] !== 'pending') {
                flood_json(array('chk' => false, 'msg' => 'รายงานนี้ตรวจไปแล้ว'));
            }
            $this->model->reviewReport($id, 'verified', null, $note !== '' ? $note : 'ยืนยันแล้ว รอประกาศ', $uid);
            flood_json(array('chk' => true, 'msg' => 'ยืนยันรายงาน ' . $r['ref_code'] . ' แล้ว — อยู่ในแท็บ "รอประกาศ"'));
        }
        // พื้นที่ที่ผูกไว้ปิดประกาศไปแล้ว → เปิดประกาศพื้นที่เดิมอีกครั้ง
        if ($action === 'reannounce') {
            $zone = $r['zone_id'] ? $this->model->getZone((int) $r['zone_id']) : null;
            if ($r['status'] !== 'verified' || !$zone) {
                flood_json(array('chk' => false, 'msg' => 'รายงานนี้ยังไม่ได้ผูกกับพื้นที่'));
            }
            if ($zone['status'] !== 'active') {
                $this->model->setZoneStatus((int) $zone['zone_id'], 'active', $uid);
            }
            flood_json(array('chk' => true, 'msg' => 'ประกาศพื้นที่ "' . $zone['name'] . '" อีกครั้งแล้ว'));
        }
        if ($action === 'reject') {
            if ($note === '') {
                flood_json(array('chk' => false, 'msg' => 'กรุณาระบุเหตุผล (เช่น โทรยืนยันแล้วน้ำลดแล้ว / ข้อมูลซ้ำ)'));
            }
            $this->model->reviewReport($id, 'rejected', null, $note, $uid);
            flood_json(array('chk' => true, 'msg' => 'บันทึกว่าไม่ใช้รายงาน ' . $r['ref_code'] . ' แล้ว'));
        }
        if ($action === 'reopen') {
            $this->model->reviewReport($id, 'pending', null, '', $uid);
            flood_json(array('chk' => true, 'msg' => 'ย้ายรายงาน ' . $r['ref_code'] . ' กลับไปรอตรวจแล้ว'));
        }
        flood_json(array('chk' => false, 'msg' => 'คำสั่งไม่ถูกต้อง'));
    }

    /**
     * นำเข้าข้อมูลจากโพสต์โซเชียล (Facebook ฯลฯ) ที่รวบรวมแล้ว — POST items = JSON array
     * รายงานเข้าเป็น "รอตรวจสอบ" / คำขอความช่วยเหลือเข้าเป็น "รับเรื่องใหม่" ให้เจ้าหน้าที่ยืนยันก่อนเสมอ
     * (รายละเอียด field ดู models/social_import_model.php)
     */
    function socialImport() {
        $this->requireMenu('reports', true);
        if (!$this->isPost()) {
            flood_json(array('chk' => false, 'msg' => 'ต้องส่งด้วย POST'), 405);
        }
        $items = json_decode(flood_in('items', '', $_POST), true);
        if (!is_array($items) || !$items) {
            flood_json(array('chk' => false, 'msg' => 'ไม่มีรายการที่จะนำเข้า'));
        }
        require_once 'models/social_import_model.php';
        $imp = new Social_Import_Model();
        $res = $imp->import($items, $this->user(), $this->model);
        $nR = count(array_filter($res['created'], function ($c) { return $c['kind'] === 'report'; }));
        $nN = count(array_filter($res['created'], function ($c) { return $c['kind'] === 'notice'; }));
        $nH = count($res['created']) - $nR - $nN;
        flood_json(array('chk' => true, 'result' => $res,
            'msg' => 'นำเข้ารายงาน ' . $nR . ' รายการ · คำขอความช่วยเหลือ ' . $nH . ' รายการ · ข้อมูลที่ควรรู้ ' . $nN . ' รายการ'
                . ($res['skipped'] ? ' · ข้ามที่ซ้ำ ' . count($res['skipped']) : '')
                . ($res['errors'] ? ' · ไม่สำเร็จ ' . count($res['errors']) : '')));
    }

    /* ==================== ข้อมูลที่ควรรู้ (ไม่ใช่จุดน้ำท่วม) ==================== */

    private function noticeModel() {
        require_once 'models/notice_model.php';
        return new Notice_Model();
    }

    /** ทุกสิทธิ์ที่เห็นหน้าภาพรวมอ่านได้ · เพิ่ม/แก้/ยืนยัน/เก็บ เฉพาะเจ้าหน้าที่ศูนย์ขึ้นไป */
    function notices() {
        $this->requireMenu('stats');
        $nm = $this->noticeModel();
        $ready = $nm->ensureTable();
        $status = flood_in('status', 'active', $_GET) === 'archived' ? 'archived' : 'active';
        $cat = flood_in('cat', '', $_GET);
        if (!array_key_exists($cat, flood_notice_categories())) {
            $cat = '';
        }
        $this->view->js[] = 'flood/js/notices.js';
        $this->view->noticeReady = $ready;
        $this->view->noticeFilters = array('status' => $status, 'cat' => $cat);
        $this->view->noticeRows = $ready ? $nm->listNotices($status, $cat) : array();
        $this->view->noticeCounts = $ready ? $nm->counts() : array('active' => 0, 'archived' => 0, 'unverified' => 0, 'by_cat' => array());
        $this->view->noticeCanEdit = $this->isOfficer();
        $this->view->amphoes = $this->model->getAmphoes();
        $this->view->provinces = $this->model->getProvinces();
        $this->view->regions = $this->model->getRegions();
        $this->view->activeTab = 'notices';
        $this->view->pageTitle = 'ข้อมูลที่ควรรู้';
        $this->view->rander('flood/notices');
    }

    function saveNotice() {
        $this->requireOfficer(true);
        $nm = $this->noticeModel();
        if (!$nm->ensureTable()) {
            flood_json(array('chk' => false, 'msg' => 'ยังไม่มีตาราง flood_notice — ให้ผู้ดูแลรัน php sql/apply_schema.php 12'));
        }
        $id = (int) flood_in('notice_id', 0, $_POST);
        if ($id > 0 && !$nm->getNotice($id)) {
            flood_json(array('chk' => false, 'msg' => 'ไม่พบข้อมูลนี้'));
        }
        $data = $nm->parseInput($_POST);
        if (is_string($data)) {
            flood_json(array('chk' => false, 'msg' => $data));
        }
        $nm->save($id, $data, (int) $this->user()['user_id']);
        flood_json(array('chk' => true, 'msg' => $id > 0 ? 'บันทึกการแก้ไขแล้ว' : 'เพิ่มข้อมูลแล้ว'));
    }

    /** ยืนยัน / ปักหมุด / เก็บ / นำกลับมาใช้ */
    function noticeSet() {
        $this->requireOfficer(true);
        $nm = $this->noticeModel();
        $id = (int) flood_in('notice_id', 0, $_POST);
        $n = $nm->ensureTable() ? $nm->getNotice($id) : null;
        if (!$n) {
            flood_json(array('chk' => false, 'msg' => 'ไม่พบข้อมูลนี้'));
        }
        $field = flood_in('field', '', $_POST);
        $value = flood_in('value', '', $_POST);
        if ($field === 'is_pinned' || $field === 'is_public') {
            $value = (int) $value ? 1 : 0;
        }
        if (!$nm->setField($id, $field, $value, (int) $this->user()['user_id'])) {
            flood_json(array('chk' => false, 'msg' => 'คำสั่งไม่ถูกต้อง'));
        }
        $msgs = array('verified' => 'ยืนยันข้อมูลแล้ว', 'unverified' => 'เปลี่ยนเป็นยังไม่ยืนยัน', 'archived' => 'เก็บข้อมูลแล้ว',
            'announced' => 'ประกาศให้ประชาชนเห็นแล้ว (ป้าย "รอตรวจสอบ")',
            'active' => 'นำกลับมาใช้แล้ว');
        if ($field === 'is_public') {
            flood_json(array('chk' => true, 'msg' => $value
                ? ($n['verify'] === 'verified' ? 'แสดงให้ประชาชนเห็นแล้ว' : 'ตั้งให้ประชาชนเห็นแล้ว — จะขึ้นหน้าประชาชนเมื่อกด "ยืนยัน"')
                : 'ซ่อนจากประชาชนแล้ว (เจ้าหน้าที่ยังเห็น)'));
        }
        flood_json(array('chk' => true, 'msg' => $field === 'is_pinned' ? ($value ? 'ปักหมุดแล้ว' : 'เลิกปักหมุดแล้ว')
            : (isset($msgs[$value]) ? $msgs[$value] : 'บันทึกแล้ว')));
    }

    /* ==================== คำขอความช่วยเหลือ / ใบงาน ==================== */

    function help() {
        $this->requireMenu('help');
        $isTeam = $this->role() === 'team';
        $status = flood_in('status', 'open', $_GET);
        $filters = array(
            'status' => $status,
            'priority' => flood_in('priority', '', $_GET),
            'region' => flood_region_param(),
            'province' => flood_province_param(),
            'amphoe' => flood_in('amphoe', '', $_GET),
            'need' => flood_in('need', '', $_GET),
            'team_id' => (int) flood_in('team_id', 0, $_GET),
            'q' => flood_in('q', '', $_GET),
        );
        if ($isTeam) {
            // ทีมเห็นเฉพาะใบงานของทีมตัวเอง
            $filters['team_id'] = $this->teamScope();
        }
        $this->useMap();
        $this->view->js[] = 'flood/js/help.js';
        $this->view->helpFilters = $filters;
        $this->view->helpResult = $this->model->listHelp($filters, 50, $this->pageParam());
        $this->view->helpCounts = $this->model->helpCounts($isTeam ? $this->teamScope() : null);
        $this->view->helpMap = $this->model->openHelpForMap($isTeam ? $this->teamScope() : null);
        $this->view->teams = $this->model->getTeams(true);
        $this->view->amphoes = $this->model->getAmphoes();
        $this->view->provinces = $this->model->getProvinces();
        $this->view->regions = $this->model->getRegions();
        $this->view->isTeam = $isTeam;
        $this->view->canCreate = $this->isOfficer();
        $this->view->activeTab = 'help';
        $this->view->pageTitle = 'คำขอความช่วยเหลือ';
        $this->view->rander('flood/help');
    }

    /** เจ้าหน้าที่รับเรื่องทางโทรศัพท์ แล้วบันทึกเป็นใบงาน */
    function helpForm() {
        $this->requireOfficer();
        $this->useMap();
        $this->view->js[] = '../public/js/flood-upload.js';
        $this->view->js[] = 'flood/js/help_form.js';
        $this->view->amphoes = $this->model->getAmphoes();
        $this->view->provinces = $this->model->getProvinces();
        $this->view->regions = $this->model->getRegions();
        $this->view->tambons = array();   // ตำบลโหลดทีละอำเภอผ่าน api/tambons
        $this->view->activeTab = 'help';
        $this->view->pageTitle = 'รับเรื่องขอความช่วยเหลือ';
        $this->view->autoRefresh = false;   // หน้าฟอร์ม: ไม่รีเฟรชเอง กันข้อมูลที่กรอกหาย
        $this->view->rander('flood/help_form');
    }

    function saveHelp() {
        $this->requireOfficer(true);
        $data = $this->model->parseHelpInput($_POST);
        if (is_string($data)) {
            flood_json(array('chk' => false, 'msg' => $data));
        }
        $priority = flood_in('priority', '', $_POST);
        if (array_key_exists($priority, flood_priorities())) {
            $data['priority'] = $priority;
        }
        $u = $this->user();
        $data['source'] = 'phone';
        $data['status'] = 'verified';   // เจ้าหน้าที่คุยกับผู้แจ้งเองแล้ว ถือว่ายืนยันแล้ว
        $data['verified_by'] = (int) $u['user_id'];
        $data['verified_at'] = date('Y-m-d H:i:s');
        $data['created_by'] = (int) $u['user_id'];
        $data['ip'] = flood_client_ip();
        $data['user_agent'] = flood_user_agent();
        $photos = array();
        try {
            $photos = flood_store_images('photos', 3);
            $ref = $this->model->createHelp($data, $photos, $u);
        } catch (Exception $e) {
            foreach ($photos as $p) {
                @unlink(flood_upload_dir() . '/' . $p['file_path']);
            }
            error_log('[flood] saveHelp: ' . $e->getMessage());
            flood_json(array('chk' => false, 'msg' => ($e instanceof PDOException) ? 'บันทึกไม่สำเร็จ' : $e->getMessage()));
        }
        flood_json(array('chk' => true, 'msg' => 'บันทึกใบงาน ' . $ref['ref'] . ' แล้ว',
            'url' => URL . 'flood/helpView/' . $ref['id']));
    }

    /** ทีมเปิดได้เฉพาะใบงานของทีมตัวเอง */
    private function helpAccessible($h) {
        if ($this->role() === 'team') {
            return $this->teamId() > 0 && (int) $h['team_id'] === $this->teamId();
        }
        return $this->can('help');
    }

    function helpView($id = null) {
        $this->requireMenu('help');
        $h = $this->model->getHelp((int) $id);
        if (!$h || !$this->helpAccessible($h)) {
            $this->deny(false, 'ไม่พบใบงานนี้ หรือใบงานไม่ได้อยู่ในความรับผิดชอบของทีมท่าน');
        }
        $this->useMap();
        $this->view->js[] = 'flood/js/help_view.js';
        $this->view->help = $h;
        $this->view->zonesHere = $h['lat'] !== null ? $this->model->zonesContaining($h['lat'], $h['lng']) : array();
        $this->view->samePhone = $this->model->helpByPhone($h['requester_phone'], $h['help_id']);
        $this->view->registryMatches = $this->can('vulnerable') ? $this->model->vulnerableByPhone($h['requester_phone']) : array();
        $this->view->teams = $this->model->getTeams(true);
        $this->view->isOfficer = $this->isOfficer();
        $this->view->isTeam = $this->role() === 'team';
        $this->view->activeTab = 'help';
        $this->view->pageTitle = 'ใบงาน ' . $h['ref_code'];
        $this->view->rander('flood/help_view');
    }

    /**
     * เปลี่ยนสถานะใบงาน — เส้นทางที่อนุญาต:
     *   verify   new → verified                     (เจ้าหน้าที่)
     *   assign   new/verified/assigned/in_progress → assigned + ทีม (เจ้าหน้าที่)
     *   start    assigned → in_progress             (ทีมเจ้าของงาน / เจ้าหน้าที่)
     *   done     assigned/in_progress → done        (ทีมเจ้าของงาน / เจ้าหน้าที่)
     *   cancel   งานที่ยังเปิด → cancelled           (เจ้าหน้าที่ ต้องมีเหตุผล)
     *   reopen   done/cancelled → verified/assigned  (เจ้าหน้าที่)
     *   priority เปลี่ยนความเร่งด่วน                 (เจ้าหน้าที่)
     *   note     บันทึกเพิ่มเติม                     (ทุกคนที่เปิดใบงานได้)
     */
    function helpAction() {
        $this->requireMenu('help', true);
        $id = (int) flood_in('help_id', 0, $_POST);
        $action = flood_in('action', '', $_POST);
        $note = mb_substr(flood_in('note', '', $_POST), 0, 1000);
        $h = $this->model->getHelp($id);
        if (!$h || !$this->helpAccessible($h)) {
            flood_json(array('chk' => false, 'msg' => 'ไม่พบใบงาน'));
        }
        $u = $this->user();
        $officer = $this->isOfficer();
        $from = $h['status'];
        $open = flood_help_open_statuses();
        $now = date('Y-m-d H:i:s');
        $upd = array();
        $to = $from;
        $teamForLog = null;
        $msg = '';

        switch ($action) {
            case 'verify':
                if (!$officer || $from !== 'new') {
                    flood_json(array('chk' => false, 'msg' => 'ยืนยันได้เฉพาะเรื่องใหม่ (โดยเจ้าหน้าที่)'));
                }
                $to = 'verified';
                $upd = array('status' => $to, 'verified_by' => (int) $u['user_id'], 'verified_at' => $now);
                $p = flood_in('priority', '', $_POST);
                if (array_key_exists($p, flood_priorities())) {
                    $upd['priority'] = $p;
                }
                $msg = 'บันทึกว่าโทรยืนยันแล้ว';
                break;
            case 'assign':
                if (!$officer || !in_array($from, $open, true)) {
                    flood_json(array('chk' => false, 'msg' => 'มอบหมายได้เฉพาะงานที่ยังเปิดอยู่ (โดยเจ้าหน้าที่)'));
                }
                $team = $this->model->getTeam((int) flood_in('team_id', 0, $_POST));
                if (!$team || (int) $team['is_active'] !== 1) {
                    flood_json(array('chk' => false, 'msg' => 'กรุณาเลือกทีมช่วยเหลือ'));
                }
                $to = 'assigned';
                $teamForLog = (int) $team['team_id'];
                $upd = array('status' => $to, 'team_id' => $teamForLog, 'assigned_at' => $now);
                if ($from === 'new') {
                    $upd['verified_by'] = (int) $u['user_id'];
                    $upd['verified_at'] = $now;
                }
                $msg = 'มอบหมายให้ ' . $team['name'] . ' แล้ว';
                break;
            case 'start':
                if ($from !== 'assigned') {
                    flood_json(array('chk' => false, 'msg' => 'เริ่มงานได้เมื่อใบงานถูกมอบหมายแล้วเท่านั้น'));
                }
                $to = 'in_progress';
                $upd = array('status' => $to);
                $msg = 'บันทึกว่ากำลังออกช่วยเหลือ';
                break;
            case 'done':
                if (!in_array($from, array('assigned', 'in_progress'), true)) {
                    flood_json(array('chk' => false, 'msg' => 'ปิดงานได้เมื่อมอบหมายทีมแล้ว'));
                }
                $to = 'done';
                $upd = array('status' => $to, 'done_at' => $now, 'result_note' => $note !== '' ? mb_substr($note, 0, 255) : null);
                $msg = 'ปิดงาน — ช่วยเหลือแล้ว';
                break;
            case 'cancel':
                if (!$officer || !in_array($from, $open, true)) {
                    flood_json(array('chk' => false, 'msg' => 'ยกเลิกได้เฉพาะงานที่ยังเปิดอยู่ (โดยเจ้าหน้าที่)'));
                }
                if ($note === '') {
                    flood_json(array('chk' => false, 'msg' => 'กรุณาระบุเหตุผลที่ยกเลิก (เช่น ข้อมูลซ้ำ / ติดต่อไม่ได้ / ช่วยเหลือตัวเองได้แล้ว)'));
                }
                $to = 'cancelled';
                $upd = array('status' => $to, 'result_note' => mb_substr($note, 0, 255));
                $msg = 'ยกเลิกใบงานแล้ว';
                break;
            case 'reopen':
                if (!$officer || !in_array($from, array('done', 'cancelled'), true)) {
                    flood_json(array('chk' => false, 'msg' => 'เปิดงานใหม่ได้เฉพาะงานที่ปิดแล้ว (โดยเจ้าหน้าที่)'));
                }
                $to = $h['team_id'] ? 'assigned' : 'verified';
                $upd = array('status' => $to, 'done_at' => null);
                $msg = 'เปิดใบงานอีกครั้งแล้ว';
                break;
            case 'priority':
                $p = flood_in('priority', '', $_POST);
                if (!$officer || !array_key_exists($p, flood_priorities())) {
                    flood_json(array('chk' => false, 'msg' => 'เลือกความเร่งด่วนไม่ถูกต้อง'));
                }
                $upd = array('priority' => $p);
                $pr = flood_priorities();
                $note = trim('ความเร่งด่วน: ' . $pr[$p]['name'] . ($note !== '' ? ' — ' . $note : ''));
                $msg = 'เปลี่ยนความเร่งด่วนแล้ว';
                break;
            case 'note':
                if ($note === '') {
                    flood_json(array('chk' => false, 'msg' => 'กรุณาพิมพ์บันทึก'));
                }
                $msg = 'เพิ่มบันทึกแล้ว';
                break;
            default:
                flood_json(array('chk' => false, 'msg' => 'คำสั่งไม่ถูกต้อง'));
        }

        Audit::ready($this->model->db);
        $this->model->db->beginTransaction();
        try {
            if ($upd) {
                $this->model->updateHelp($id, $upd);
            }
            $this->model->addHelpLog($id, $action, $from, $to, $teamForLog, $note, $u);
            $this->model->db->commit();
        } catch (Exception $e) {
            $this->model->db->rollBack();
            error_log('[flood] helpAction: ' . $e->getMessage());
            flood_json(array('chk' => false, 'msg' => 'บันทึกไม่สำเร็จ'));
        }
        flood_json(array('chk' => true, 'msg' => $msg));
    }

    /* ==================== ทะเบียนกลุ่มเปราะบาง ==================== */

    function vulnerable() {
        $this->requireMenu('vulnerable');
        $filters = array(
            'q' => flood_in('q', '', $_GET),
            'region' => flood_region_param(),
            'province' => flood_province_param(),
            'amphoe' => flood_in('amphoe', '', $_GET),
            'tambon' => flood_in('tambon', '', $_GET),
            'group' => flood_in('group', '', $_GET),
            'evac' => flood_in('evac', '', $_GET),
            'in_zone' => flood_in('in_zone', '', $_GET) === '1',
            'no_location' => flood_in('no_location', '', $_GET) === '1',
        );
        $this->useMap();
        $this->view->js[] = 'flood/js/vulnerable.js';
        $this->view->vFilters = $filters;
        $this->view->vResult = $this->model->listVulnerable($filters, 50, $this->pageParam());
        $this->view->vSummary = $this->model->vulnerableSummary();
        $this->view->activeZones = array_map(array($this->model, 'zoneForMap'), $this->model->listZones(array('status' => 'active')));
        $this->view->amphoes = $this->model->getAmphoes();
        $this->view->provinces = $this->model->getProvinces();
        $this->view->regions = $this->model->getRegions();
        $this->view->tambons = array();   // ตำบลโหลดทีละอำเภอผ่าน api/tambons
        $this->view->activeTab = 'vulnerable';
        $this->view->pageTitle = 'ทะเบียนกลุ่มเปราะบาง';
        $this->view->rander('flood/vulnerable');
    }

    function vulnerableForm($id = null) {
        $this->requireMenu('vulnerable');
        $person = null;
        if ($id !== null) {
            $person = $this->model->getVulnerable((int) $id);
            if (!$person || (int) $person['is_active'] !== 1) {
                header('Location: ' . URL . 'flood/vulnerable');
                exit;
            }
        }
        $this->useMap();
        $this->view->js[] = 'flood/js/vulnerable_form.js';
        $this->view->person = $person;
        $this->view->activeZones = array_map(array($this->model, 'zoneForMap'), $this->model->listZones(array('status' => 'active')));
        $this->view->amphoes = $this->model->getAmphoes();
        $this->view->provinces = $this->model->getProvinces();
        $this->view->regions = $this->model->getRegions();
        $this->view->tambons = array();   // ตำบลโหลดทีละอำเภอผ่าน api/tambons
        $this->view->activeTab = 'vulnerable';
        $this->view->pageTitle = $person ? 'แก้ไขข้อมูล ' . $person['name'] : 'เพิ่มในทะเบียนกลุ่มเปราะบาง';
        $this->view->autoRefresh = false;   // หน้าฟอร์ม: ไม่รีเฟรชเอง กันข้อมูลที่กรอกหาย
        $this->view->rander('flood/vulnerable_form');
    }

    function saveVulnerable() {
        $this->requireMenu('vulnerable', true);
        $id = (int) flood_in('person_id', 0, $_POST);
        if ($id > 0) {
            $p = $this->model->getVulnerable($id);
            if (!$p || (int) $p['is_active'] !== 1) {
                flood_json(array('chk' => false, 'msg' => 'ไม่พบข้อมูล'));
            }
        }
        $name = mb_substr(flood_in('name', '', $_POST), 0, 150);
        if (mb_strlen($name) < 2) {
            flood_json(array('chk' => false, 'msg' => 'กรุณากรอกชื่อ-สกุล'));
        }
        $groups = flood_codes_filter(isset($_POST['vuln_groups']) ? $_POST['vuln_groups'] : array(), flood_vulnerable_groups());
        if (!$groups) {
            flood_json(array('chk' => false, 'msg' => 'กรุณาเลือกกลุ่มเปราะบางอย่างน้อย 1 กลุ่ม'));
        }
        $birth = flood_in('birth_date', '', $_POST);
        if ($birth !== '') {
            $d = DateTime::createFromFormat('Y-m-d', $birth);
            if (!$d || $d->format('Y-m-d') !== $birth || $birth > date('Y-m-d')) {
                flood_json(array('chk' => false, 'msg' => 'วันเกิดไม่ถูกต้อง (ใช้ปี ค.ศ.)'));
            }
        }
        $lat = flood_in('lat', '', $_POST);
        $lng = flood_in('lng', '', $_POST);
        $hasLoc = $lat !== '' && $lng !== '';
        if ($hasLoc && !flood_valid_latlng($lat, $lng)) {
            flood_json(array('chk' => false, 'msg' => 'พิกัดไม่ถูกต้อง'));
        }
        $amphoe = flood_in('amphoe_code', '', $_POST);
        if ($amphoe !== '' && !$this->model->amphoeExists($amphoe)) {
            $amphoe = '';
        }
        $tambon = $this->model->validTambon(flood_in('tambon_code', '', $_POST), $amphoe);
        if ($tambon && $amphoe === '') {
            $amphoe = substr($tambon, 0, 4);
        }
        $phones = array();
        foreach (array('phone', 'caregiver_phone') as $f) {
            $raw = flood_in($f, '', $_POST);
            $n = flood_normalize_phone($raw);
            if ($raw !== '' && !flood_valid_phone($n)) {
                flood_json(array('chk' => false, 'msg' => 'เบอร์โทรไม่ถูกต้อง (9–10 หลัก)'));
            }
            $phones[$f] = $n !== '' ? $n : null;
        }
        $sex = flood_in('sex', '', $_POST);
        $mob = flood_in('mobility', '', $_POST);
        $data = array(
            'hn' => mb_substr(flood_in('hn', '', $_POST), 0, 20) ?: null,
            'name' => $name,
            'sex' => in_array($sex, array('M', 'F'), true) ? $sex : null,
            'birth_date' => $birth !== '' ? $birth : null,
            'vuln_groups' => implode(',', $groups),
            'mobility' => array_key_exists($mob, flood_mobility_options()) ? $mob : null,
            'medical_needs' => mb_substr(flood_in('medical_needs', '', $_POST), 0, 255) ?: null,
            'address' => mb_substr(flood_in('address', '', $_POST), 0, 255) ?: null,
            'moo' => mb_substr(flood_in('moo', '', $_POST), 0, 10) ?: null,
            'amphoe_code' => $amphoe !== '' ? $amphoe : null,
            'tambon_code' => $tambon,
            'lat' => $hasLoc ? round((float) $lat, 7) : null,
            'lng' => $hasLoc ? round((float) $lng, 7) : null,
            'phone' => $phones['phone'],
            'caregiver_name' => mb_substr(flood_in('caregiver_name', '', $_POST), 0, 150) ?: null,
            'caregiver_phone' => $phones['caregiver_phone'],
            'note' => mb_substr(flood_in('note', '', $_POST), 0, 2000) ?: null,
        );
        $newId = $this->model->saveVulnerable($id, $data, (int) $this->user()['user_id']);
        flood_json(array('chk' => true, 'msg' => 'บันทึกข้อมูล ' . $name . ' แล้ว', 'person_id' => $newId,
            'url' => URL . 'flood/vulnerable'));
    }

    /** อัปเดตสถานะการอพยพ/ติดตามรายคน (เก็บประวัติทุกครั้ง) */
    function vulnerableStatus() {
        $this->requireMenu('vulnerable', true);
        $id = (int) flood_in('person_id', 0, $_POST);
        $status = flood_in('evac_status', '', $_POST);
        if (!array_key_exists($status, flood_evac_statuses())) {
            flood_json(array('chk' => false, 'msg' => 'กรุณาเลือกสถานะ'));
        }
        $p = $this->model->getVulnerable($id);
        if (!$p || (int) $p['is_active'] !== 1) {
            flood_json(array('chk' => false, 'msg' => 'ไม่พบข้อมูล'));
        }
        $this->model->setVulnerableStatus($id, $status, flood_in('evac_place', '', $_POST),
            mb_substr(flood_in('note', '', $_POST), 0, 1000), $this->user());
        $st = flood_evac_statuses();
        flood_json(array('chk' => true, 'msg' => $p['name'] . ': ' . $st[$status]['name']));
    }

    function vulnerableRemove() {
        $this->requireMenu('vulnerable', true);
        $id = (int) flood_in('person_id', 0, $_POST);
        $p = $this->model->getVulnerable($id);
        if (!$p) {
            flood_json(array('chk' => false, 'msg' => 'ไม่พบข้อมูล'));
        }
        // ไม่ลบจริง — ปิดไว้ ประวัติการติดตามยังอยู่
        $this->model->saveVulnerable($id, array('is_active' => 0), (int) $this->user()['user_id']);
        flood_json(array('chk' => true, 'msg' => 'นำ ' . $p['name'] . ' ออกจากทะเบียนแล้ว'));
    }

    function vulnerableData($id = null) {
        $this->requireMenu('vulnerable', true);
        $p = $this->model->getVulnerable((int) $id);
        if (!$p || (int) $p['is_active'] !== 1) {
            flood_json(array('chk' => false, 'msg' => 'ไม่พบข้อมูล'));
        }
        $st = flood_evac_statuses();
        $logs = array();
        foreach ($p['logs'] as $l) {
            $logs[] = array(
                'status' => isset($st[$l['evac_status']]) ? $st[$l['evac_status']]['name'] : $l['evac_status'],
                'place' => (string) $l['evac_place'],
                'note' => (string) $l['note'],
                'user' => $l['user_name'],
                'time' => flood_thai_date($l['created_at']),
            );
        }
        flood_json(array('chk' => true, 'person' => array(
            'person_id' => (int) $p['person_id'],
            'name' => $p['name'],
            'evac_status' => $p['evac_status'],
            'evac_place' => (string) $p['evac_place'],
            'logs' => $logs,
        )));
    }

    /* ==================== ทีมช่วยเหลือ ==================== */

    function teams() {
        $this->requireMenu('teams');
        $this->view->js[] = 'flood/js/teams.js';
        $this->view->teamRows = $this->model->getTeams(false);
        $this->view->amphoes = $this->model->getAmphoes();
        $this->view->provinces = $this->model->getProvinces();
        $this->view->regions = $this->model->getRegions();
        $this->view->activeTab = 'teams';
        $this->view->pageTitle = 'ทีมช่วยเหลือ';
        $this->view->rander('flood/teams');
    }

    function saveTeam() {
        $this->requireMenu('teams', true);
        $id = (int) flood_in('team_id', 0, $_POST);
        if ($id > 0 && !$this->model->getTeam($id)) {
            flood_json(array('chk' => false, 'msg' => 'ไม่พบทีม'));
        }
        $name = mb_substr(flood_in('name', '', $_POST), 0, 150);
        if (mb_strlen($name) < 2) {
            flood_json(array('chk' => false, 'msg' => 'กรุณากรอกชื่อทีม'));
        }
        $type = flood_in('team_type', 'rescue', $_POST);
        $phoneRaw = flood_in('phone', '', $_POST);
        $phone = flood_normalize_phone($phoneRaw);
        if ($phoneRaw !== '' && !flood_valid_phone($phone)) {
            flood_json(array('chk' => false, 'msg' => 'เบอร์โทรไม่ถูกต้อง'));
        }
        $amphoe = flood_in('amphoe_code', '', $_POST);
        $data = array(
            'name' => $name,
            'team_type' => array_key_exists($type, flood_team_types()) ? $type : 'other',
            'phone' => $phone !== '' ? $phone : null,
            'amphoe_code' => $amphoe !== '' && $this->model->amphoeExists($amphoe) ? $amphoe : null,
            'vehicles' => mb_substr(flood_in('vehicles', '', $_POST), 0, 255) ?: null,
            'note' => mb_substr(flood_in('note', '', $_POST), 0, 255) ?: null,
            'is_active' => flood_in('is_active', '1', $_POST) === '1' ? 1 : 0,
        );
        $this->model->saveTeam($id, $data);
        flood_json(array('chk' => true, 'msg' => 'บันทึกทีม ' . $name . ' แล้ว'));
    }

    /* ==================== ผู้ใช้งาน (admin) ==================== */

    function settingsUsers() {
        $this->requireAdmin();
        $filters = array(
            'q' => flood_in('q', '', $_GET),
            'role' => flood_in('role', '', $_GET),
            'status' => flood_in('status', '', $_GET),
        );
        $this->view->js[] = 'flood/js/users.js';
        $this->view->userFilters = $filters;
        $this->view->userRows = $this->model->listUsers($filters);
        $this->view->teams = $this->model->getTeams(true);
        $this->view->currentRole = $this->role();
        $this->view->currentUserId = (int) $this->user()['user_id'];
        $this->view->activeTab = 'settingsUsers';
        $this->view->pageTitle = 'ผู้ใช้งาน';
        $this->view->rander('flood/settings_users');
    }

    function saveUser() {
        $this->requireAdmin(true);
        $me = $this->user();
        $myRole = $this->role();
        $id = (int) flood_in('user_id', 0, $_POST);
        $target = $id > 0 ? $this->model->getUserById($id) : null;
        if ($id > 0 && !$target) {
            flood_json(array('chk' => false, 'msg' => 'ไม่พบผู้ใช้'));
        }
        $role = flood_in('role', 'viewer', $_POST);
        if (!array_key_exists($role, flood_role_labels())) {
            flood_json(array('chk' => false, 'msg' => 'สิทธิ์ไม่ถูกต้อง'));
        }
        if ($myRole !== 'super_admin' && ($role === 'super_admin' || ($target && $target['role'] === 'super_admin'))) {
            flood_json(array('chk' => false, 'msg' => 'เฉพาะผู้ดูแลสูงสุดเท่านั้นที่จัดการบัญชีผู้ดูแลสูงสุดได้'));
        }
        $name = mb_substr(flood_in('name', '', $_POST), 0, 150);
        if (mb_strlen($name) < 2) {
            flood_json(array('chk' => false, 'msg' => 'กรุณากรอกชื่อ-สกุล'));
        }
        $teamId = (int) flood_in('team_id', 0, $_POST);
        if ($role === 'team' && (!$teamId || !$this->model->getTeam($teamId))) {
            flood_json(array('chk' => false, 'msg' => 'ผู้ใช้สิทธิ์ "ทีมช่วยเหลือ" ต้องเลือกทีมที่สังกัด'));
        }
        $phoneRaw = flood_in('phone', '', $_POST);
        $phone = flood_normalize_phone($phoneRaw);
        if ($phoneRaw !== '' && !flood_valid_phone($phone)) {
            flood_json(array('chk' => false, 'msg' => 'เบอร์โทรไม่ถูกต้อง'));
        }
        $active = flood_in('is_active', '1', $_POST) === '1' ? 1 : 0;
        $isSelf = $id > 0 && $id === (int) $me['user_id'];
        if ($isSelf && ($active === 0 || $role !== $target['role'])) {
            flood_json(array('chk' => false, 'msg' => 'เปลี่ยนสิทธิ์หรือปิดบัญชีของตัวเองไม่ได้ (กันล็อกตัวเองออกจากระบบ)'));
        }
        $data = array(
            'name' => $name,
            'role' => $role,
            'team_id' => $teamId > 0 ? $teamId : null,
            'org_name' => mb_substr(flood_in('org_name', '', $_POST), 0, 200) ?: null,
            'phone' => $phone !== '' ? $phone : null,
            'is_active' => $active,
        );
        $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
        if ($id === 0) {
            $login = flood_in('loginname', '', $_POST);
            if (!preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $login)) {
                flood_json(array('chk' => false, 'msg' => 'ชื่อผู้ใช้ใช้ได้เฉพาะ a-z 0-9 . _ - ความยาว 3–50 ตัว'));
            }
            if ($this->model->loginnameExists($login)) {
                flood_json(array('chk' => false, 'msg' => 'ชื่อผู้ใช้ "' . $login . '" มีอยู่แล้ว'));
            }
            if (strlen($password) < 8) {
                flood_json(array('chk' => false, 'msg' => 'รหัสผ่านเริ่มต้นต้องยาวอย่างน้อย 8 ตัวอักษร'));
            }
            $data['loginname'] = $login;
            $data['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            $data['must_change_password'] = 1;
        } elseif ($password !== '') {
            if (strlen($password) < 8) {
                flood_json(array('chk' => false, 'msg' => 'รหัสผ่านใหม่ต้องยาวอย่างน้อย 8 ตัวอักษร'));
            }
            $data['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            // ผู้ดูแลตั้งรหัสให้ — เจ้าของบัญชีต้องเปลี่ยนเองอีกครั้งตอนเข้าใช้งาน
            $data['must_change_password'] = $isSelf ? 0 : 1;
        }
        $this->model->saveUser($id, $data);
        flood_json(array('chk' => true, 'msg' => ($id > 0 ? 'บันทึกการแก้ไข ' : 'เพิ่มผู้ใช้ ') . $name . ' แล้ว'));
    }

    /* ==================== ประวัติการใช้งาน (admin) ==================== */

    private function logFilters() {
        $days = (int) flood_in('days', 7, $_GET);
        if (!in_array($days, array(1, 7, 30, 90, 0), true)) {
            $days = 7;
        }
        return array('q' => flood_in('q', '', $_GET), 'days' => $days);
    }

    function loginLog() {
        $this->requireAdmin();
        $f = $this->logFilters();
        $f['event'] = flood_in('event', '', $_GET);
        $this->view->logFilters = $f;
        $this->view->logResult = $this->model->listLoginLog($f, 100, $this->pageParam());
        $this->view->activeTab = 'loginLog';
        $this->view->pageTitle = 'ประวัติการเข้าใช้งาน';
        $this->view->rander('flood/login_log');
    }

    function auditLog() {
        $this->requireAdmin();
        $f = $this->logFilters();
        $f['action'] = flood_in('action', '', $_GET);
        $f['table'] = flood_in('table', '', $_GET);
        $this->view->js[] = 'flood/js/audit_log.js';
        $this->view->logFilters = $f;
        $this->view->logResult = $this->model->listAuditLog($f, 100, $this->pageParam());
        $this->view->activeTab = 'auditLog';
        $this->view->pageTitle = 'ประวัติการแก้ไขข้อมูล';
        $this->view->rander('flood/audit_log');
    }

    function auditLogEntry($id = null) {
        $this->requireAdmin(true);
        $row = $this->model->getAuditEntry((int) $id);
        if (!$row) {
            flood_json(array('chk' => false, 'msg' => 'ไม่พบรายการ'));
        }
        $row['before'] = $row['before_json'] !== null ? json_decode($row['before_json'], true) : null;
        $row['after'] = $row['after_json'] !== null ? json_decode($row['after_json'], true) : null;
        unset($row['before_json'], $row['after_json']);
        flood_json(array('chk' => true, 'entry' => $row));
    }

    /* ==================== ข้อมูลผู้ใช้ของตัวเอง ==================== */

    function profile() {
        $this->view->js[] = 'flood/js/profile.js';
        $this->view->me = $this->model->getUserById((int) $this->user()['user_id']);
        $this->view->mustChange = !empty($this->user()['must_change_password']);
        $this->view->activeTab = 'profile';
        $this->view->pageTitle = 'ข้อมูลผู้ใช้';
        $this->view->autoRefresh = false;   // หน้าฟอร์ม: ไม่รีเฟรชเอง กันข้อมูลที่กรอกหาย
        $this->view->rander('flood/profile');
    }

    function saveProfile() {
        $name = mb_substr(flood_in('name', '', $_POST), 0, 150);
        if (mb_strlen($name) < 2) {
            flood_json(array('chk' => false, 'msg' => 'กรุณากรอกชื่อ-สกุล'));
        }
        $phoneRaw = flood_in('phone', '', $_POST);
        $phone = flood_normalize_phone($phoneRaw);
        if ($phoneRaw !== '' && !flood_valid_phone($phone)) {
            flood_json(array('chk' => false, 'msg' => 'เบอร์โทรไม่ถูกต้อง'));
        }
        $u = $this->user();
        $this->model->saveUser((int) $u['user_id'], array(
            'name' => $name,
            'phone' => $phone !== '' ? $phone : null,
            'org_name' => mb_substr(flood_in('org_name', '', $_POST), 0, 200) ?: null,
        ));
        $u['name'] = $name;
        $u['org_name'] = mb_substr(flood_in('org_name', '', $_POST), 0, 200);
        Session::set('User_FLOOD', $u);
        flood_json(array('chk' => true, 'msg' => 'บันทึกข้อมูลแล้ว'));
    }

    function changePassword() {
        $u = $this->user();
        $old = isset($_POST['old_password']) ? (string) $_POST['old_password'] : '';
        $new = isset($_POST['new_password']) ? (string) $_POST['new_password'] : '';
        $confirm = isset($_POST['confirm_password']) ? (string) $_POST['confirm_password'] : '';
        if (!$this->model->passwordMatches((int) $u['user_id'], $old)) {
            flood_json(array('chk' => false, 'msg' => 'รหัสผ่านเดิมไม่ถูกต้อง'));
        }
        if (strlen($new) < 8) {
            flood_json(array('chk' => false, 'msg' => 'รหัสผ่านใหม่ต้องยาวอย่างน้อย 8 ตัวอักษร'));
        }
        if ($new !== $confirm) {
            flood_json(array('chk' => false, 'msg' => 'ยืนยันรหัสผ่านใหม่ไม่ตรงกัน'));
        }
        if ($new === $old) {
            flood_json(array('chk' => false, 'msg' => 'รหัสผ่านใหม่ต้องไม่ซ้ำรหัสเดิม'));
        }
        if (strtolower($new) === strtolower($u['loginname'])) {
            flood_json(array('chk' => false, 'msg' => 'รหัสผ่านต้องไม่เหมือนชื่อผู้ใช้'));
        }
        $this->model->setPassword((int) $u['user_id'], $new, 0);
        $u['must_change_password'] = 0;
        Session::set('User_FLOOD', $u);
        flood_json(array('chk' => true, 'msg' => 'เปลี่ยนรหัสผ่านแล้ว', 'url' => URL . 'flood'));
    }

    /* ==================== ไฟล์แนบ (ตรวจสิทธิ์ก่อนส่งรูป) ==================== */

    function attachment($id = null) {
        $a = $this->model->getAttachment((int) $id);
        $ok = false;
        if ($a) {
            if ($this->isOfficer()) {
                $ok = true;
            } elseif ($this->role() === 'team' && $a['ref_type'] === 'help') {
                $h = $this->model->getHelp((int) $a['ref_id']);
                $ok = $h && $this->helpAccessible($h);
            }
        }
        $base = realpath(flood_upload_dir());
        $file = $a ? realpath(flood_upload_dir() . '/' . $a['file_path']) : false;
        if (!$ok || !$file || !$base || strpos($file, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($file)) {
            http_response_code(404);
            exit;
        }
        $mime = in_array($a['mime'], array('image/jpeg', 'image/png', 'image/webp'), true) ? $a['mime'] : 'application/octet-stream';
        session_write_close();
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: private, max-age=86400');
        header('Pragma: private');
        header('X-Content-Type-Options: nosniff');
        readfile($file);
        exit;
    }

}
