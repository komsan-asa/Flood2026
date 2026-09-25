<?php

class ErrorController extends Controller {

    function __construct() {
        parent::__construct();
    }

    function index() {
        if (!headers_sent()) {
            http_response_code(404);
        }
        if (flood_is_ajax()) {
            flood_json(array('chk' => false, 'msg' => 'ไม่พบหน้านี้'), 404);
        }
        $this->view->pageMenu = 'error';
        $this->view->msg = 'ไม่พบหน้าที่ต้องการ';
        $this->view->rander('error/index');
    }

    function notLogin() {
        $this->view->pageMenu = 'error';
        $this->view->msg = 'กรุณาเข้าสู่ระบบ';
        $this->view->rander('error/index');
    }
}
