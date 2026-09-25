<?php

class Model {

    /** @var Database */
    public $db;

    /** ใช้การเชื่อมต่อเดียวกันทั้ง request — หลาย model ไม่ต้องเปิด connection ใหม่ */
    private static $shared = null;

    function __construct() {
        if (self::$shared === null) {
            $dsn = DB_TYPE . ':host=' . DB_HOST
                . (defined('DB_PORT') ? ';port=' . DB_PORT : '')
                . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            try {
                self::$shared = new Database($dsn, DB_USER, DB_PASS, array(
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 5,
                ));
            } catch (PDOException $e) {
                flood_db_unavailable($e);
            }
        }
        $this->db = self::$shared;
    }

    /** การเชื่อมต่อที่เปิดอยู่แล้ว (null = ยังไม่มี model ไหนเชื่อมฐาน) — ให้ helper อ่านค่าตั้งค่าได้โดยไม่เปิด connection เอง */
    public static function sharedDb() {
        return self::$shared;
    }

}
