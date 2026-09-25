<?php

class Bootstrap {

    /** controller ที่เปิดได้โดยไม่ต้องล็อกอิน — หน้าประชาชน + API แผนที่ */
    private $public = array('index', 'login', 'error', 'api', 'report', 'sos');

    function __construct() {

        $url = explode('/', rtrim((isset($_GET['url']) ? $_GET['url'] : null), '/'));

        if (empty($url[0])) {
            require 'controllers/index.php';
            $controller = new Index();
            $controller->loadModel('index');
            $controller->index();
            return false;
        }

        // ชื่อ controller/action ต้องเป็นตัวอักษร ตัวเลข _ เท่านั้น — กัน ../ และชื่อแปลก ๆ
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $url[0])) {
            $this->error();
            return false;
        }

        $file = 'controllers/' . $url[0] . '.php';

        if (file_exists($file)) {
            $logged = Session::get('User_FLOOD');
            if (!is_array($logged)) {
                $logged = array();
            }
            if (sizeof($logged) > 0 || in_array($url[0], $this->public)) {
                require $file;
            } else {
                $this->loginFalse();
                return false;
            }
        } else {
            $this->error();
            return false;
        }

        $controllerClass = ($url[0] === 'error') ? 'ErrorController' : $url[0];
        $controller = new $controllerClass;
        $controller->loadModel($url[0]);
        $controller->view->pageMenu = $url[0];

        if (isset($url[2])) {
            if ($this->isAction($controller, $url[1])) {
                $controller->{$url[1]}($url[2]);
            } else {
                $this->error();
            }
        } else if (isset($url[1])) {
            if ($this->isAction($controller, $url[1])) {
                $controller->{$url[1]}();
            } else {
                $this->error();
            }
        } else {
            $controller->index();
        }
    }

    /**
     * เรียกผ่าน URL ได้เฉพาะ public method ที่ประกาศใน controller นั้นเอง
     * (กันการเรียก loadModel / __construct / private method ผ่าน URL)
     */
    private function isAction($controller, $name) {
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', (string) $name)) {
            return false;
        }
        // controller หลายตัว override loadModel เป็น public — ไม่ใช่ action ห้ามเรียกผ่าน URL
        if (in_array(strtolower($name), array('loadmodel', 'unloadmodel'), true)) {
            return false;
        }
        if (!method_exists($controller, $name)) {
            return false;
        }
        try {
            $m = new ReflectionMethod($controller, $name);
        } catch (ReflectionException $e) {
            return false;
        }
        if (!$m->isPublic() || $m->isStatic() || $m->isConstructor()) {
            return false;
        }
        $declaring = $m->getDeclaringClass()->getName();
        return strcasecmp($declaring, 'Controller') !== 0;
    }

    function error() {
        require_once 'controllers/error.php';
        $controller = new ErrorController();
        $controller->index();
        return false;
    }

    function loginFalse() {
        if ($this->isAjax()) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(array(
                'chk' => false,
                'relogin' => true,
                'msg' => 'เซสชันหมดอายุ กรุณาเข้าสู่ระบบใหม่'
            ), JSON_UNESCAPED_UNICODE);
            return false;
        }
        // จำหน้าที่ตั้งใจจะเปิด ล็อกอินเสร็จแล้วพากลับมาที่เดิม
        $want = isset($_GET['url']) ? trim((string) $_GET['url'], '/') : '';
        if ($want !== '' && preg_match('/^[a-zA-Z0-9_\/\-]+$/', $want)) {
            Session::set('flood_after_login', $want);
        }
        header('Location: ' . URL . 'login');
        exit;
    }

    function isAjax() {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

}
