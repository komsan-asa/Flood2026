<?php

/**
 * ตัวเรียก Flood2026-site-api (ฐาน hosoffice + ระบบลงเวลา)
 *   ค่าเริ่มต้น: require โฟลเดอร์ข้างเคียง ../Flood2026-site-api แล้วเรียก F26Dispatch::run ในโปรเซสเดียวกัน
 *   API อยู่คนละเครื่อง: ตั้ง SITE_API_URL + SITE_API_KEY ใน config/app.php
 */
class FloodSiteApi {

    /** โฟลเดอร์ของ API (null = ไม่พบ) */
    public static function path() {
        $p = defined('SITE_API_PATH') && SITE_API_PATH ? SITE_API_PATH : dirname(__DIR__) . '/../Flood2026-site-api';
        $real = realpath($p);
        return $real && is_file($real . '/lib/F26Dispatch.php') ? $real : null;
    }

    public static function mode() {
        if (defined('SITE_API_URL') && SITE_API_URL) {
            return 'http';
        }
        $p = self::path();
        return $p && is_file($p . '/config/config.php') ? 'local' : '';
    }

    public static function enabled() {
        return self::mode() !== '';
    }

    /** คำอธิบายสำหรับแสดงบนหน้าจอเมื่อยังใช้ไม่ได้ */
    public static function setupHint() {
        if (defined('SITE_API_URL') && SITE_API_URL) {
            return '';
        }
        $p = self::path();
        if (!$p) {
            return 'ไม่พบโฟลเดอร์ Flood2026-site-api ข้าง ๆ Flood2026 — ตั้ง SITE_API_PATH หรือ SITE_API_URL ใน config/app.php';
        }
        if (!is_file($p . '/config/config.php')) {
            return 'ยังไม่มี Flood2026-site-api/config/config.php — คัดลอกจาก config.sample.php แล้วใส่ค่าฐาน hosoffice และ api_key';
        }
        return '';
    }

    /**
     * @param bool $post ใช้กับ v1/user-verify (รหัสผ่านไปใน body เท่านั้น)
     * @return array ผลลัพธ์จาก API — ok = false พร้อม error เมื่อไม่สำเร็จ
     */
    public static function call($route, $params = array(), $post = false) {
        $mode = self::mode();
        if ($mode === '') {
            return array('ok' => false, 'error' => self::setupHint() ?: 'ยังไม่ได้ตั้งค่า Flood2026-site-api');
        }
        try {
            if ($mode === 'local') {
                require_once self::path() . '/lib/F26Dispatch.php';
                $key = defined('SITE_API_KEY') && SITE_API_KEY ? SITE_API_KEY : '';
                if ($key === '') {
                    $cfg = F26Dispatch::config();
                    $key = isset($cfg['api_key']) ? (string) $cfg['api_key'] : '';
                }
                $r = F26Dispatch::run($route, $params, $key);
                return is_array($r) ? $r : array('ok' => false, 'error' => 'API ตอบกลับไม่ถูกต้อง');
            }
            return self::http($route, $params, $post);
        } catch (Throwable $e) {
            error_log('[FloodSiteApi] ' . $route . ': ' . $e->getMessage());
            return array('ok' => false, 'error' => 'เรียก Flood2026-site-api ไม่สำเร็จ: ' . mb_substr($e->getMessage(), 0, 200));
        }
    }

    private static function http($route, $params, $post) {
        if (!function_exists('curl_init')) {
            return array('ok' => false, 'error' => 'เซิร์ฟเวอร์ไม่มี PHP curl');
        }
        $url = SITE_API_URL . (strpos(SITE_API_URL, '?') === false ? '?' : '&') . 'route=' . rawurlencode($route);
        if (!$post && $params) {
            $url .= '&' . http_build_query($params);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => array('X-API-Key: ' . (defined('SITE_API_KEY') ? SITE_API_KEY : ''), 'Accept: application/json'),
        ));
        if ($post) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        }
        $body = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            return array('ok' => false, 'error' => 'ติดต่อ Flood2026-site-api ไม่ได้ (' . $err . ')');
        }
        $j = json_decode($body, true);
        return is_array($j) ? $j : array('ok' => false, 'error' => 'API ตอบกลับไม่ใช่ JSON');
    }

}

/**
 * ผู้ใช้จาก hosoffice — นำเข้าเป็นผู้ใช้ Flood2026 (HR_USERNAME = ชื่อผู้ใช้) และตรวจรหัสผ่านตอนเข้าสู่ระบบ
 */
class Site_Api_Model extends Model {

    const SOURCE = 'hosoffice';

    /** คอลัมน์ auth_source / hr_emp_id ใน flood_user (เพิ่มเองครั้งแรก) */
    public function ensureColumns() {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        try {
            $cols = array();
            foreach ($this->db->select('SHOW COLUMNS FROM flood_user') as $c) {
                $cols[$c['Field']] = true;
            }
            if (!isset($cols['auth_source'])) {
                $this->db->exec("ALTER TABLE flood_user ADD COLUMN `auth_source` VARCHAR(20) NOT NULL DEFAULT 'local'
                    COMMENT 'local = รหัสผ่านในระบบ / hosoffice = ตรวจรหัสผ่านกับ hosoffice ผ่าน Flood2026-site-api'");
            }
            if (!isset($cols['hr_emp_id'])) {
                $this->db->exec("ALTER TABLE flood_user ADD COLUMN `hr_emp_id` VARCHAR(30) DEFAULT NULL COMMENT 'รหัสบุคลากรใน hosoffice'");
            }
            $ok = true;
        } catch (Exception $e) {
            $ok = false;
        }
        return $ok;
    }

    /**
     * นำเข้า/ปรับปรุงผู้ใช้จากรายชื่อ hosoffice
     *   ใหม่: สิทธิ์ viewer, หน่วยงาน = หน่วยงานใน hosoffice, เข้าสู่ระบบด้วยรหัสผ่าน hosoffice (เฉพาะคนที่ยังปฏิบัติงาน)
     *   มีอยู่แล้ว: ไม่เปลี่ยนสิทธิ์ — admin/super_admin ไม่ถูกลดสิทธิ์หรือปิดบัญชี และบัญชีผู้ดูแลที่สร้างในระบบเองไม่ถูกผูกกับ hosoffice
     *   บัญชีอื่นที่ชื่อผู้ใช้ตรงกัน: ผูกกับ hosoffice (เข้าได้ทั้งรหัสเดิมและรหัส hosoffice) แล้วปรับชื่อ/หน่วยงาน/สถานะตาม hosoffice
     */
    public function importUsers($rows) {
        $n = array('total' => 0, 'created' => 0, 'updated' => 0, 'linked' => 0, 'disabled' => 0, 'skipped' => 0, 'admin_kept' => 0);
        $existing = array();
        foreach ($this->db->select('SELECT user_id, loginname, name, role, org_name, is_active, auth_source, hr_emp_id FROM flood_user') as $u) {
            $existing[mb_strtolower($u['loginname'])] = $u;
        }
        $seen = array();
        foreach ($rows as $r) {
            $login = trim(isset($r['username']) ? (string) $r['username'] : '');
            if ($login === '') {
                continue;
            }
            $n['total']++;
            $key = mb_strtolower($login);
            if (isset($seen[$key]) || !preg_match('/^[A-Za-z0-9._@-]{2,50}$/', $login)) {
                $n['skipped']++;
                continue;
            }
            $seen[$key] = true;
            $name = mb_substr(trim((string) (isset($r['name']) ? $r['name'] : '')), 0, 150);
            $dept = mb_substr(trim((string) (isset($r['dept_name']) ? $r['dept_name'] : '')), 0, 200);
            $emp = mb_substr(trim((string) (isset($r['emp_id']) ? $r['emp_id'] : '')), 0, 30);
            $active = !isset($r['active']) || (int) $r['active'] === 1;
            if (!isset($existing[$key])) {
                if (!$active || mb_strlen($name) < 2) {
                    $n['skipped']++;
                    continue;
                }
                $this->db->insert('flood_user', array(
                    'loginname' => $login, 'name' => $name, 'role' => 'viewer', 'org_name' => $dept !== '' ? $dept : null,
                    'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
                    'must_change_password' => 0, 'is_active' => 1,
                    'auth_source' => self::SOURCE, 'hr_emp_id' => $emp !== '' ? $emp : null,
                ));
                $n['created']++;
                continue;
            }
            $u = $existing[$key];
            $isAdmin = in_array($u['role'], array('admin', 'super_admin'), true);
            if ($isAdmin && $u['auth_source'] !== self::SOURCE) {
                // บัญชีผู้ดูแลที่สร้างในระบบเอง — ไม่ผูกกับ hosoffice (กันคนที่ใช้ชื่อผู้ใช้เดียวกันใน hosoffice เข้าได้ด้วยสิทธิ์ผู้ดูแล)
                $n['admin_kept']++;
                continue;
            }
            $data = array();
            if ($u['auth_source'] !== self::SOURCE) {
                $data['auth_source'] = self::SOURCE;   // เข้าได้ทั้งรหัสผ่านเดิมในระบบ และรหัสผ่าน hosoffice
                $n['linked']++;
            }
            if ($emp !== '' && $u['hr_emp_id'] !== $emp) {
                $data['hr_emp_id'] = $emp;
            }
            if ($name !== '' && mb_strlen($name) >= 2 && $name !== $u['name']) {
                $data['name'] = $name;
            }
            if ($dept !== '' && $dept !== (string) $u['org_name'] && $u['role'] !== 'team') {
                $data['org_name'] = $dept;
            }
            if (!$active && (int) $u['is_active'] === 1 && !$isAdmin) {
                $data['is_active'] = 0;
                $n['disabled']++;
            }
            if ($data) {
                $this->db->update('flood_user', $data, 'user_id = :w_id', array(':w_id' => (int) $u['user_id']));
                if (!isset($data['auth_source'])) {
                    $n['updated']++;
                }
            }
        }
        return $n;
    }

    /** บัญชีนี้ตรวจรหัสผ่านกับ hosoffice ได้หรือไม่ */
    public function authSource($userId) {
        try {
            return (string) $this->db->selectValue('SELECT auth_source FROM flood_user WHERE user_id = :id', array(':id' => (int) $userId));
        } catch (Exception $e) {
            return '';
        }
    }

}
