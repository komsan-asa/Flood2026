<?php

/**
 * ประชาชนขอความช่วยเหลือ (ไม่ต้องล็อกอิน) → กลายเป็นใบงานให้ศูนย์ประสานโทรยืนยันและมอบหมายทีม
 * ข้อมูลส่วนบุคคลเห็นเฉพาะเจ้าหน้าที่ ไม่ขึ้นบนแผนที่สาธารณะ
 */
class Sos extends Controller {

    function __construct() {
        parent::__construct();
        $this->view->pageMenu = 'sos';
        $this->view->useMap = true;
        $this->view->css = array('../public/css/public.css', 'report/css/default.css');
        $this->view->js = array('../public/js/flood-map.js', '../public/js/flood-upload.js', 'sos/js/default.js');
    }

    public function loadModel($name) {
        parent::loadModel('flood');
    }

    function index() {
        $this->view->pageTitle = 'ขอความช่วยเหลือ';
        $this->view->amphoes = $this->model->getAmphoes();
        $this->view->tambons = $this->model->getTambons();
        $this->view->rander('sos/index');
    }

    function save() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            flood_json(array('chk' => false, 'msg' => 'ต้องส่งด้วยฟอร์ม'), 405);
        }
        if (!flood_csrf_valid()) {
            flood_json(array('chk' => false, 'csrf' => true, 'token' => flood_csrf_token(), 'msg' => 'กรุณากดส่งอีกครั้ง'));
        }
        if (flood_in('website', '', $_POST) !== '') {
            flood_json(array('chk' => true, 'ref' => '', 'url' => URL . 'sos/done'));
        }

        $data = $this->model->parseHelpInput($_POST);
        if (is_string($data)) {
            flood_json(array('chk' => false, 'msg' => $data));
        }

        $ip = flood_client_ip();
        list($ipCount, $phoneCount) = $this->model->recentSubmitCounts('flood_help', 'requester_phone', $ip, $data['requester_phone']);
        if ($ipCount >= PUBLIC_LIMIT_PER_IP_10MIN || $phoneCount >= PUBLIC_LIMIT_PER_PHONE_HOUR) {
            flood_json(array('chk' => false, 'msg' => 'ส่งคำขอถี่เกินไป หากเร่งด่วนโปรดโทร ' . EMERGENCY_PHONE));
        }

        $photos = array();
        try {
            $photos = flood_store_images('photos', 3);
            $data['source'] = 'public';
            $data['status'] = 'new';
            $data['ip'] = $ip;
            $data['user_agent'] = flood_user_agent();
            $ref = $this->model->createHelp($data, $photos, null);
        } catch (Exception $e) {
            foreach ($photos as $p) {
                @unlink(flood_upload_dir() . '/' . $p['file_path']);
            }
            error_log('[flood] sos/save: ' . $e->getMessage());
            $msg = ($e instanceof PDOException) ? 'บันทึกไม่สำเร็จ กรุณาลองใหม่ หรือโทร ' . EMERGENCY_PHONE : $e->getMessage();
            flood_json(array('chk' => false, 'msg' => $msg));
        }

        Session::set('flood_last_sos', array('ref' => $ref['ref'], 'phone' => $data['requester_phone']));
        flood_json(array('chk' => true, 'ref' => $ref['ref'], 'url' => URL . 'sos/done'));
    }

    function done() {
        // หน้านี้ไม่มีแผนที่/ฟอร์ม — ไม่ต้องโหลดสคริปต์ของฟอร์ม
        $this->view->useMap = false;
        $this->view->js = array();
        $last = Session::get('flood_last_sos');
        $this->view->ref = is_array($last) ? $last['ref'] : '';
        $this->view->pageTitle = 'ส่งคำขอแล้ว';
        $this->view->rander('sos/done');
    }

    /** ประชาชนตรวจสถานะคำขอ — ต้องใช้ทั้งเลขอ้างอิงและเบอร์โทรที่ใช้แจ้ง */
    function status() {
        $last = Session::get('flood_last_sos');
        $this->view->prefillRef = is_array($last) ? $last['ref'] : '';
        $this->view->prefillPhone = is_array($last) ? $last['phone'] : '';
        $this->view->pageTitle = 'ติดตามคำขอความช่วยเหลือ';
        $this->view->result = null;
        $this->view->error = '';

        $ref = strtoupper(flood_in('ref', '', $_GET));
        $phone = flood_normalize_phone(flood_in('phone', '', $_GET));
        if ($ref !== '' || $phone !== '') {
            // กันไล่เดาเลข — จำกัดจำนวนครั้งต่อ session
            $tries = Session::get('flood_status_tries');
            $tries = is_array($tries) ? array_filter($tries, function ($t) {
                return $t > time() - 3600;
            }) : array();
            if (count($tries) >= 30) {
                $this->view->error = 'ค้นหาบ่อยเกินไป กรุณารอสักครู่';
            } else {
                $tries[] = time();
                Session::set('flood_status_tries', array_values($tries));
                if (!preg_match('/^SOS-\d{6}-\d+$/', $ref) || !flood_valid_phone($phone)) {
                    $this->view->error = 'กรุณากรอกเลขอ้างอิง (เช่น SOS-690924-001) และเบอร์โทรที่ใช้แจ้ง';
                } else {
                    $this->view->result = $this->model->publicHelpStatus($ref, $phone);
                    if (!$this->view->result) {
                        $this->view->error = 'ไม่พบคำขอที่ตรงกับเลขอ้างอิงและเบอร์โทรนี้';
                    }
                }
            }
            $this->view->prefillRef = $ref;
            $this->view->prefillPhone = $phone;
        }
        $this->view->useMap = false;
        $this->view->js = array();
        $this->view->rander('sos/status');
    }

}
