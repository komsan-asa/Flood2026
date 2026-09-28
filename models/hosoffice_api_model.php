<?php

/**
 * ต่อฐาน hosoffice ผ่าน hos-office-site-api (Z:\hos-office-site-api) — แทน Flood2026-site-api ส่วนผู้ใช้
 *
 * - ใช้ค่าเชื่อมต่อฐานจาก hos-office-site-api/config/site.php (HR_DB_*) ในเครื่องเดียวกัน
 *   ไม่คัดลอกรหัสผ่านมาเก็บใน Flood2026 และไม่ส่ง API key หรือรหัสผ่านออกนอกเครื่อง
 * - อ่านอย่างเดียว (SELECT) ตาราง hr_person แบบเดียวกับ models/HrModel.php ของ hos-office-site-api
 * - ตั้งตำแหน่งโฟลเดอร์เองได้ด้วย define('HOSOFFICE_API_PATH', '...') ใน config/app.php
 * - ระบบลงเวลา (อัตรากำลังรายเวร) ยังใช้ Flood2026-site-api เหมือนเดิม
 */
class HosOfficeApi {

    const NAME = 'hos-office-site-api';

    /** โฟลเดอร์ของ hos-office-site-api (null = ไม่พบ) */
    public static function path() {
        $p = defined('HOSOFFICE_API_PATH') && HOSOFFICE_API_PATH ? HOSOFFICE_API_PATH : dirname(__DIR__) . '/../hos-office-site-api';
        $real = realpath($p);
        return $real && is_file($real . '/config/site.php') ? $real : null;
    }

    public static function enabled() {
        $c = self::config();
        return $c !== null && !empty($c['HR_DB_HOST']) && !empty($c['HR_DB_NAME']);
    }

    public static function setupHint() {
        if (!self::path()) {
            return 'ไม่พบ hos-office-site-api/config/site.php ข้าง ๆ Flood2026 — ตั้ง HOSOFFICE_API_PATH ใน config/app.php';
        }
        return self::enabled() ? '' : 'config/site.php ของ hos-office-site-api ยังไม่มีค่า HR_DB_HOST / HR_DB_NAME';
    }

    /** อ่านค่า define('HR_DB_*', ...) จาก config/site.php โดยไม่ execute ไฟล์ (กันชื่อค่าคงที่ชนกับ Flood2026) */
    public static function config() {
        static $cfg = false;
        if ($cfg !== false) {
            return $cfg;
        }
        $cfg = null;
        $p = self::path();
        if (!$p) {
            return null;
        }
        $src = @file_get_contents($p . '/config/site.php');
        if ($src === false) {
            return null;
        }
        $out = array();
        if (preg_match_all("/define\\(\\s*['\"](HR_DB_[A-Z]+|API_QUERY_TIMEOUT)['\"]\\s*,\\s*(?:'((?:[^'\\\\]|\\\\.)*)'|\"((?:[^\"\\\\]|\\\\.)*)\"|(\\d+))\\s*\\)/", $src, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                if (isset($x[4]) && $x[4] !== '') {
                    $v = $x[4];
                } elseif (isset($x[3]) && $x[3] !== '') {
                    $v = stripcslashes($x[3]);
                } else {
                    $v = str_replace(array("\\'", '\\\\'), array("'", '\\'), $x[2]);
                }
                $out[$x[1]] = $v;
            }
        }
        $cfg = $out;
        return $cfg;
    }

    /** @return PDO */
    private static function pdo() {
        static $pdo = null;
        if ($pdo) {
            return $pdo;
        }
        $c = self::config();
        if (!$c || empty($c['HR_DB_HOST'])) {
            throw new Exception(self::setupHint() ?: 'ยังไม่ได้ตั้งค่า hos-office-site-api');
        }
        $port = !empty($c['HR_DB_PORT']) ? $c['HR_DB_PORT'] : '3306';
        $charset = !empty($c['HR_DB_CHARSET']) ? $c['HR_DB_CHARSET'] : 'utf8';
        $pdo = new PDO('mysql:host=' . $c['HR_DB_HOST'] . ';port=' . $port . ';dbname=' . $c['HR_DB_NAME'] . ';charset=' . $charset,
            isset($c['HR_DB_USER']) ? $c['HR_DB_USER'] : '', isset($c['HR_DB_PASS']) ? $c['HR_DB_PASS'] : '', array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => !empty($c['API_QUERY_TIMEOUT']) ? (int) $c['API_QUERY_TIMEOUT'] : 15,
            ));
        return $pdo;
    }

    private static function safeError($e) {
        return mb_substr(preg_replace('/\s+/u', ' ', $e->getMessage()), 0, 200);
    }

    /** สถานะการเชื่อมต่อ + จำนวนบุคลากร */
    public static function health() {
        try {
            $n = (int) self::pdo()->query('SELECT COUNT(*) FROM hr_person')->fetchColumn();
            $active = (int) self::pdo()->query("SELECT COUNT(*) FROM hr_person WHERE HR_STATUS_ID = '01'")->fetchColumn();
            return array('ok' => true, 'persons' => $n, 'active' => $active);
        } catch (Throwable $e) {
            return array('ok' => false, 'error' => self::safeError($e));
        }
    }

    /**
     * รายชื่อบุคลากรที่มี HR_USERNAME — รูปแบบเดียวกับที่ Site_Api_Model::importUsers() ใช้
     * {username, name, dept_name, position, emp_id (= hr_person.id), active}
     */
    public static function users() {
        try {
            $rows = self::pdo()->query(
                "SELECT h.id, h.HR_USERNAME, h.HR_FNAME, h.HR_LNAME, h.HR_STATUS_ID,
                        pf.HR_PREFIX_NAME, d.HR_DEPARTMENT_SUB_SUB_NAME, p.HR_POSITION_NAME
                 FROM hr_person h
                 LEFT JOIN hr_position p ON p.HR_POSITION_ID = h.HR_POSITION_ID
                 LEFT JOIN hr_department_sub_sub d ON d.HR_DEPARTMENT_SUB_SUB_ID = h.HR_DEPARTMENT_SUB_SUB_ID
                 LEFT JOIN hr_prefix pf ON pf.HR_PREFIX_ID = h.HR_PREFIX_ID
                 WHERE h.HR_USERNAME IS NOT NULL AND TRIM(h.HR_USERNAME) <> ''
                 ORDER BY h.HR_STATUS_ID = '01' DESC, h.HR_USERNAME")->fetchAll();
        } catch (Throwable $e) {
            return array('ok' => false, 'error' => 'อ่าน hr_person ไม่ได้: ' . self::safeError($e));
        }
        $out = array();
        foreach ($rows as $r) {
            $out[] = array(
                'username' => trim((string) $r['HR_USERNAME']),
                'name' => trim(trim((string) $r['HR_PREFIX_NAME']) . trim((string) $r['HR_FNAME']) . ' ' . trim((string) $r['HR_LNAME'])),
                'dept_name' => trim((string) $r['HR_DEPARTMENT_SUB_SUB_NAME']),
                'position' => trim((string) $r['HR_POSITION_NAME']),
                'emp_id' => (string) $r['id'],
                'active' => trim((string) $r['HR_STATUS_ID']) === '01' ? 1 : 0,
            );
        }
        return array('ok' => true, 'rows' => $out);
    }

    /** ตรวจรหัสผ่าน hosoffice (HR_PASSWORD = MD5) — ไม่ส่ง hash ออกไปไหน */
    public static function verify($username, $password) {
        $username = trim((string) $username);
        if ($username === '' || (string) $password === '') {
            return array('ok' => true, 'found' => false, 'valid' => false, 'active' => false);
        }
        try {
            $st = self::pdo()->prepare('SELECT HR_PASSWORD, HR_STATUS_ID FROM hr_person WHERE HR_USERNAME = :u ORDER BY HR_STATUS_ID = \'01\' DESC LIMIT 1');
            $st->execute(array(':u' => $username));
            $r = $st->fetch();
        } catch (Throwable $e) {
            return array('ok' => false, 'error' => self::safeError($e));
        }
        if (!$r) {
            return array('ok' => true, 'found' => false, 'valid' => false, 'active' => false);
        }
        $hash = strtolower(trim((string) $r['HR_PASSWORD']));
        return array(
            'ok' => true,
            'found' => true,
            'valid' => $hash !== '' && hash_equals($hash, md5((string) $password)),
            'active' => trim((string) $r['HR_STATUS_ID']) === '01',
        );
    }
}
