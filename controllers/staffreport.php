<?php

/**
 * บุคลากรโรงพยาบาลแจ้งผลกระทบ / ปัญหา / ขอความช่วยเหลือ (ลิงก์ + รหัสสั้น ไม่ต้องล็อกอิน)
 * คำตอบเข้า "บุคลากรที่ได้รับผลกระทบ" (flood/staff) — ดู models/staff_form_model.php
 *
 *   staffreport          ฟอร์ม (ยังไม่ใส่รหัส = หน้ากรอกรหัส · ลิงก์ ?k=รหัส เข้าได้ทันที)
 *   staffreport/save     บันทึก (POST)
 *   staffreport/done     ส่งแล้ว
 *   staffreport/admin    ตั้ง/เปลี่ยน/ปิดรหัส + ลิงก์และข้อความสำหรับส่งในกลุ่มไลน์ (เจ้าหน้าที่ที่เห็นเมนูบุคลากร)
 *   staffreport/setCode  (POST JSON) สำหรับหน้า admin
 */
class Staffreport extends Controller {

    const SESSION_KEY = 'flood_staff_form_ok';

    function __construct() {
        parent::__construct();
        $this->view->css = array('../public/css/public.css', 'report/css/default.css', 'staffreport/css/default.css');
        $this->view->js = array();
    }

    public function loadModel($name) {
        require_once 'models/staff_form_model.php';
        $this->model = new Staff_Form_Model();
    }

    /** ผ่านรหัสแล้วในเซสชันนี้ (รหัสถูกเปลี่ยน/ปิด = ต้องใส่ใหม่) */
    private function unlocked() {
        $code = $this->model->code();
        $ok = Session::get(self::SESSION_KEY);
        return $code !== '' && is_string($ok) && hash_equals(sha1('sf|' . $code), $ok);
    }

    function index() {
        $this->view->pageTitle = 'บุคลากรโรงพยาบาล — แจ้งผลกระทบ / ขอความช่วยเหลือ';
        $this->view->state = 'form';
        $this->view->error = '';
        if (!$this->model->ensureTables()) {
            $this->view->state = 'closed';
            $this->view->rander('staffreport/index');
            return;
        }
        if ($this->model->code() === '') {
            $this->view->state = 'closed';
            $this->view->rander('staffreport/index');
            return;
        }
        $given = flood_in('k', '', $_GET);
        if (!$this->unlocked() && $given !== '') {
            $ip = flood_client_ip();
            if ($this->model->failCount($ip) >= Staff_Form_Model::FAIL_PER_IP_HOUR) {
                $this->view->error = 'ใส่รหัสผิดหลายครั้งเกินไป กรุณารอ 1 ชั่วโมง หรือขอลิงก์ใหม่จากหัวหน้างาน';
            } elseif ($this->model->codeMatches($given)) {
                Session::set(self::SESSION_KEY, sha1('sf|' . $this->model->code()));
                header('Location: ' . URL . 'staffreport');   // เอารหัสออกจากแถบที่อยู่
                exit;
            } else {
                $this->model->addFail($ip);
                $this->view->error = 'รหัสไม่ถูกต้อง — ตรวจสอบรหัสจากกลุ่มไลน์ของหน่วยงาน';
            }
        }
        if (!$this->unlocked()) {
            $this->view->state = 'gate';
            $this->view->rander('staffreport/index');
            return;
        }
        $this->view->useMap = true;
        $this->view->js = array('../public/js/flood-map.js', '../public/js/flood-upload.js', 'staffreport/js/default.js');
        $this->view->rander('staffreport/index');
    }

    function save() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            flood_json(array('chk' => false, 'msg' => 'ต้องส่งด้วยฟอร์ม'), 405);
        }
        if (!flood_csrf_valid()) {
            flood_json(array('chk' => false, 'csrf' => true, 'token' => flood_csrf_token(), 'msg' => 'กรุณากดส่งอีกครั้ง'));
        }
        if (flood_in('website', '', $_POST) !== '') {
            flood_json(array('chk' => true, 'url' => URL . 'staffreport/done'));
        }
        if (!$this->model->ensureTables() || !$this->unlocked()) {
            flood_json(array('chk' => false, 'msg' => 'รหัสของฟอร์มถูกเปลี่ยนหรือปิดรับแล้ว — เปิดลิงก์ใหม่จากกลุ่มไลน์ของหน่วยงาน', 'reload' => true));
        }
        $p = $this->model->parseInput($_POST);
        if (isset($p['msg'])) {
            flood_json(array('chk' => false, 'msg' => $p['msg'], 'field' => $p['field']));
        }
        // กันส่งถี่: ต่อเซสชัน 6 ครั้ง / 10 นาที · ต่อเบอร์ 6 ครั้ง / ชั่วโมง
        $tries = Session::get('flood_staff_form_sent');
        $tries = is_array($tries) ? array_values(array_filter($tries, function ($t) { return $t > time() - 600; })) : array();
        if (count($tries) >= 6 || $this->model->recentByPhone(Staff_Model::phoneKey($p['phone'])) >= 6) {
            flood_json(array('chk' => false, 'msg' => 'ส่งถี่เกินไป กรุณารอสักครู่ — ถ้าเร่งด่วนโทรหาหัวหน้างานหรือศูนย์ประสานโดยตรง'));
        }

        $photos = array();
        try {
            $photos = flood_store_images('photos', 3);
            Audit::suspend();
            try {
                list($sid, $isNew) = $this->model->save($p, $photos);
            } finally {
                Audit::resume();
            }
        } catch (Exception $e) {
            foreach ($photos as $f) {
                @unlink(flood_upload_dir() . '/' . $f['file_path']);
            }
            error_log('[flood] staffreport/save: ' . $e->getMessage());
            $msg = ($e instanceof PDOException) ? 'บันทึกไม่สำเร็จ กรุณาลองใหม่อีกครั้ง' : $e->getMessage();
            flood_json(array('chk' => false, 'msg' => $msg));
        }
        $tries[] = time();
        Session::set('flood_staff_form_sent', $tries);
        Session::set('flood_staff_form_last', array('name' => $p['name'], 'at' => date('Y-m-d H:i:s')));
        flood_json(array('chk' => true, 'url' => URL . 'staffreport/done'));
    }

    function done() {
        $this->view->pageTitle = 'ส่งข้อมูลแล้ว';
        $last = Session::get('flood_staff_form_last');
        $this->view->last = is_array($last) ? $last : null;
        $this->view->rander('staffreport/done');
    }

    /* ==================== สำหรับเจ้าหน้าที่ (ต้องล็อกอิน + เห็นเมนูบุคลากร) ==================== */

    private function staffUser() {
        $u = flood_session_user();
        if (!$u) {
            return null;
        }
        $role = flood_normalize_role(isset($u['role']) ? $u['role'] : '');
        return flood_can_menu($role, 'staff') ? $u : null;
    }

    function admin() {
        $u = $this->staffUser();
        if (!$u) {
            if (!flood_session_user()) {
                header('Location: ' . URL . 'login');
                exit;
            }
            http_response_code(403);
            $this->view->pageTitle = 'ไม่มีสิทธิ์';
            $this->view->state = 'noperm';
            $this->view->rander('staffreport/index');
            return;
        }
        $this->view->pageMenu = 'flood';
        $this->view->activeTab = 'staffForm';
        $this->view->css = array('flood/css/default.css', 'staffreport/css/default.css');
        $this->view->js = array('flood/js/default.js', 'staffreport/js/admin.js');
        $this->view->pageTitle = 'ฟอร์มให้บุคลากรแจ้งปัญหา / ขอความช่วยเหลือ';
        $ready = $this->model->ensureTables();
        $this->view->ready = $ready;
        $this->view->code = $ready ? $this->model->code() : '';
        $this->view->stats = $ready ? $this->model->stats() : null;
        $this->view->rander('staffreport/admin');
    }

    function setCode() {
        $u = $this->staffUser();
        if (!$u) {
            flood_json(array('chk' => false, 'msg' => 'ไม่มีสิทธิ์ — เฉพาะเจ้าหน้าที่ที่ดูแลข้อมูลบุคลากร'), 403);
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !flood_csrf_valid()) {
            flood_json(array('chk' => false, 'msg' => 'กรุณารีเฟรชหน้าแล้วลองใหม่'));
        }
        if (!$this->model->ensureTables()) {
            flood_json(array('chk' => false, 'msg' => 'สร้างตารางไม่ได้ — ให้ผู้ดูแลตรวจสิทธิ์ฐานข้อมูล'));
        }
        $mode = flood_in('mode', '', $_POST);
        $code = $mode === 'random' ? Staff_Form_Model::randomCode() : ($mode === 'off' ? '' : flood_in('code', '', $_POST));
        if ($mode === 'custom' && Staff_Form_Model::normalizeCode($code) === '') {
            flood_json(array('chk' => false, 'msg' => 'กรุณาพิมพ์รหัส 4–12 ตัว'));
        }
        $r = $this->model->setCode($code, isset($u['user_id']) ? (int) $u['user_id'] : null);
        if ($r !== true) {
            flood_json(array('chk' => false, 'msg' => $r));
        }
        $this->model->sourceId();
        $new = $this->model->code();
        flood_json(array('chk' => true, 'code' => $new,
            'msg' => $new === '' ? 'ปิดรับฟอร์มแล้ว — ลิงก์เดิมใช้ไม่ได้จนกว่าจะตั้งรหัสใหม่' : 'ตั้งรหัสใหม่แล้ว: ' . $new . ' (ลิงก์/รหัสเดิมใช้ไม่ได้แล้ว)'));
    }
}
