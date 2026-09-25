<?php

/**
 * หน้าแรกสาธารณะ — แผนที่สถานการณ์น้ำ (ไม่ต้องล็อกอิน อยู่ใน allow-list ของ Bootstrap)
 * แสดงเฉพาะขอบเขตพื้นที่ที่เจ้าหน้าที่ประกาศ ไม่มีข้อมูลส่วนบุคคลของผู้ใด
 */
class Index extends Controller {

    function __construct() {
        parent::__construct();
        $this->view->pageMenu = 'index';
        $this->view->useMap = true;
        $this->view->css = array('../public/css/public.css', 'index/css/default.css');
        $this->view->js = array('../public/js/flood-map.js', 'index/js/default.js');
    }

    /** หน้าสาธารณะใช้ model หลักตัวเดียวกับระบบเจ้าหน้าที่ */
    public function loadModel($name) {
        parent::loadModel('flood');
    }

    function index() {
        if (!$this->model) {
            $this->loadModel('flood');
        }
        $this->view->amphoes = $this->model->getAmphoes();
        $this->view->zones = $this->model->publicZones();
        $this->view->points = $this->model->publicReportPoints();
        $this->view->counts = $this->model->zoneCounts();
        // สถิติผู้เข้าชม (อ่านอย่างเดียว — การนับทำผ่าน api/visit จาก JavaScript)
        $this->view->visitStats = null;
        try {
            require_once 'models/visit_model.php';
            $vm = new Visit_Model();
            if ($vm->ensureTables()) {
                $this->view->visitStats = $vm->stats();
            }
        } catch (Exception $e) {
            error_log('[flood] visit stats: ' . $e->getMessage());
        }
        // ข้อมูลที่ควรรู้ (เฉพาะที่เจ้าหน้าที่ยืนยันแล้วและเปิดให้ประชาชนเห็น) — ผิดพลาด = ไม่แสดงส่วนนี้
        $this->view->publicNotices = array();
        try {
            require_once 'models/notice_model.php';
            $nm = new Notice_Model();
            if ($nm->ensureTable()) {
                $this->view->publicNotices = $nm->listPublic();
            }
        } catch (Exception $e) {
            error_log('[flood] public notices: ' . $e->getMessage());
        }
        $this->view->noNavbar = true;
        $this->view->bodyClass = 'sk-map-body';
        $this->view->rander('index/index');
    }

    /** หน้าแนะนำระบบ (สาธารณะ) */
    function about() {
        if (!$this->model) {
            $this->loadModel('flood');
        }
        $st = array('amphoes' => 0, 'tambons' => 0, 'zones' => 0, 'points' => 0, 'pending' => 0);
        try {
            $st['amphoes'] = count($this->model->getAmphoes());
            $st['tambons'] = count($this->model->getTambons());
            $c = $this->model->zoneCounts();
            $st['zones'] = (int) $c['total'];
            foreach ($this->model->publicReportPoints() as $p) {
                if (!empty($p['pending'])) {
                    $st['pending']++;
                } else {
                    $st['points']++;
                }
            }
        } catch (Exception $e) {
            error_log('[flood] about stats: ' . $e->getMessage());
        }
        try {
            require_once 'models/visit_model.php';
            $vm = new Visit_Model();
            if ($vm->ensureTables()) {
                $v = $vm->stats();
                $st['visitors'] = (int) $v['total_visitors'];
            }
        } catch (Exception $e) {
            error_log('[flood] about visit: ' . $e->getMessage());
        }
        $this->view->aboutStats = $st;
        $this->view->useMap = false;
        $this->view->css = array('index/css/about.css');
        $this->view->js = array();
        $this->view->noNavbar = true;
        $this->view->bodyClass = 'sk-about-body';
        $this->view->pageTitle = 'แนะนำระบบ';
        $this->view->rander('index/about');
    }

}
