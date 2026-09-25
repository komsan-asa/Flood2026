<?php

class Login extends Controller {

    function __construct() {
        parent::__construct();
        $this->view->js = array('login/js/default.js');
        $this->view->css = array('login/css/default.css');
    }

    function index() {
        $u = flood_session_user();
        if (!empty($u['user_id'])) {
            header('Location: ' . URL . 'flood');
            exit;
        }
        $this->view->notice = mb_substr(flood_in('m', '', $_GET), 0, 120);
        $this->view->pageTitle = 'เข้าสู่ระบบเจ้าหน้าที่';
        $this->view->rander('login/index');
    }

    function run() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            flood_json(array('chk' => false, 'error_log' => 'ต้องส่งด้วยฟอร์ม'), 405);
        }
        $this->model->dataRun();
    }

    function logout() {
        $u = Session::get('User_FLOOD');
        if (is_array($u) && $this->model) {
            $this->model->logLogout($u);
        }
        Session::destroy();
        header('Location: ' . URL . 'login');
        exit();
    }

}
