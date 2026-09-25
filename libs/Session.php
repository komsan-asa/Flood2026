<?php

class Session {

    /**
     * ชื่อคุกกี้แยกจากระบบอื่นบนเซิร์ฟเวอร์เดียวกัน (เช่น /coc) และผูก path ไว้ที่โฟลเดอร์ระบบ
     * ออกจากระบบฝั่งหนึ่งจะได้ไม่เตะอีกฝั่งออกไปด้วย
     */
    public static function init() {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if (!headers_sent()) {
            session_name('FLOODSESSID');
            session_set_cookie_params(array(
                'lifetime' => 0,
                'path' => defined('BASE_PATH') ? BASE_PATH : '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => defined('IS_HTTPS') ? (bool) IS_HTTPS : false,
            ));
            // เจ้าหน้าที่เปิดหน้าจอค้างไว้ทั้งกะ — ให้ session อยู่ได้ 12 ชั่วโมงถ้ายังใช้งาน
            ini_set('session.gc_maxlifetime', '43200');
        }
        @session_start();
    }

    public static function set($key, $value) {
        $_SESSION[$key] = ($value) ? $value : null;
    }

    public static function get($key) {
        if (isset($_SESSION)) {
            return isset($_SESSION[$key]) ? $_SESSION[$key] : null;
        }
        return null;
    }

    /** เปลี่ยน session id ใหม่หลังล็อกอิน — กัน session fixation */
    public static function regenerate() {
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_regenerate_id(true);
        }
    }

    public static function destroy() {
        $_SESSION = array();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

}
