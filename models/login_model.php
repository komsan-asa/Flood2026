<?php

class Login_Model extends Model {

    /** ล็อกอินผิดติดกันกี่ครั้งภายในช่วงเวลา แล้วพักบัญชีชั่วคราว */
    const MAX_FAILED = 10;
    const LOCK_MINUTES = 15;

    public function dataRun() {
        header('Content-Type: application/json; charset=utf-8');

        if (!flood_csrf_valid()) {
            // หน้า login เปิดค้างไว้นานจน session หมด — ส่ง token ใหม่ให้ JS ลองส่งซ้ำเอง
            echo json_encode(array('chk' => false, 'csrf' => true, 'token' => flood_csrf_token(),
                'error_log' => 'กรุณากดเข้าสู่ระบบอีกครั้ง'), JSON_UNESCAPED_UNICODE);
            return;
        }

        $loginname = isset($_POST['username']) ? trim((string) $_POST['username']) : '';
        $password = isset($_POST['password']) ? (string) $_POST['password'] : '';

        if ($loginname === '' || $password === '') {
            echo json_encode(array('chk' => false, 'error_log' => 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน'), JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($this->tooManyFailures($loginname)) {
            $this->logFailedLogin($loginname, 'ถูกพักชั่วคราว — ล็อกอินผิดเกิน ' . self::MAX_FAILED . ' ครั้ง');
            echo json_encode(array('chk' => false,
                'error_log' => 'เข้าสู่ระบบผิดหลายครั้ง กรุณารอ ' . self::LOCK_MINUTES . ' นาทีแล้วลองใหม่'), JSON_UNESCAPED_UNICODE);
            return;
        }

        $row = $this->db->selectOne(
            $this->loginSelectSql() . " WHERE u.loginname = :loginname LIMIT 1",
            array(':loginname' => $loginname)
        );

        if (!$row || (int) $row['is_active'] !== 1 || !password_verify($password, $row['password_hash'])) {
            $reason = !$row ? 'ไม่พบชื่อผู้ใช้นี้ในระบบ'
                : ((int) $row['is_active'] !== 1 ? 'บัญชีถูกปิดใช้งาน' : 'รหัสผ่านไม่ถูกต้อง');
            $this->logFailedLogin($loginname, $reason, $row ? $row : null);
            // ข้อความที่ผู้ใช้เห็นตั้งใจให้กำกวม ไม่บอกว่าผิดที่ชื่อหรือรหัส — สาเหตุจริงเก็บใน flood_login_log
            echo json_encode(array('chk' => false, 'error_log' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง'), JSON_UNESCAPED_UNICODE);
            return;
        }

        if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
            try {
                $this->db->update('flood_user', array('password_hash' => password_hash($password, PASSWORD_DEFAULT)),
                    'user_id = :w_id', array(':w_id' => (int) $row['user_id']));
            } catch (Exception $e) {
                // เก็บ hash แบบใหม่ไม่ได้ก็ยังล็อกอินได้
            }
        }

        echo json_encode($this->establishSession($row), JSON_UNESCAPED_UNICODE);
    }

    /** นับครั้งที่ล็อกอินผิด "หลังจากล็อกอินสำเร็จครั้งล่าสุด" ภายใน LOCK_MINUTES นาที */
    private function tooManyFailures($loginname) {
        try {
            $cutoff = date('Y-m-d H:i:s', time() - self::LOCK_MINUTES * 60);
            $lastOk = $this->db->selectValue(
                "SELECT MAX(created_at) FROM flood_login_log WHERE loginname = :l AND event = 'login'",
                array(':l' => $loginname)
            );
            if ($lastOk && $lastOk > $cutoff) {
                $cutoff = $lastOk;
            }
            $n = (int) $this->db->selectValue(
                "SELECT COUNT(*) FROM flood_login_log
                 WHERE loginname = :l AND event = 'failed' AND created_at >= :c",
                array(':l' => $loginname, ':c' => $cutoff)
            );
            return $n >= self::MAX_FAILED;
        } catch (Exception $e) {
            return false;
        }
    }

    public function logFailedLogin($loginname, $reason, $user = null) {
        $info = array('loginname' => $loginname, 'method' => 'password', 'reason' => $reason);
        if (is_array($user)) {
            $info['user_id'] = $user['user_id'];
            $info['name'] = $user['name'];
            $info['role'] = $user['role'];
        }
        Audit::loginEvent($this->db, 'failed', $info);
    }

    /** บันทึกการออกจากระบบ — เรียกก่อนล้าง session */
    public function logLogout($user, $event = 'logout') {
        if (empty($user['user_id'])) {
            return;
        }
        Audit::loginEvent($this->db, $event, array(
            'user_id' => $user['user_id'],
            'loginname' => isset($user['loginname']) ? $user['loginname'] : '',
            'name' => isset($user['name']) ? $user['name'] : '',
            'role' => isset($user['role']) ? $user['role'] : '',
        ));
        // ออกจากระบบแล้วให้หายจากรายชื่อผู้ใช้ออนไลน์ทันที
        try {
            $this->db->update('flood_user', array('last_seen_at' => null, 'last_page' => null),
                'user_id = :w_id', array(':w_id' => (int) $user['user_id']));
        } catch (Exception $e) {
        }
    }

    private function loginSelectSql() {
        return "SELECT u.user_id, u.loginname, u.password_hash, u.name, u.role, u.team_id, u.org_name,
                       u.phone, u.is_active, u.must_change_password, t.name AS team_name
                FROM flood_user u
                LEFT JOIN flood_team t ON t.team_id = u.team_id";
    }

    private function establishSession($row) {
        Session::init();
        Session::regenerate();
        $now = date('Y-m-d H:i:s');
        $data = array(
            'user_id' => (int) $row['user_id'],
            'loginname' => $row['loginname'],
            'name' => $row['name'],
            'role' => flood_normalize_role($row['role']),
            'team_id' => $row['team_id'] !== null ? (int) $row['team_id'] : null,
            'team_name' => $row['team_name'],
            'org_name' => $row['org_name'],
            'must_change_password' => (int) $row['must_change_password'],
            'login_at' => $now,
        );
        Session::set('User_FLOOD', $data);
        Session::set('flood_last_touch', null);

        try {
            $this->db->update('flood_user', array(
                'last_login_at' => $now,
                'last_seen_at' => $now,
                'last_ip' => flood_client_ip(),
                'last_page' => 'เข้าสู่ระบบ',
            ), 'user_id = :w_id', array(':w_id' => (int) $row['user_id']));
        } catch (Exception $e) {
            // ไม่ให้เรื่องบันทึกสถิติขวางการเข้าสู่ระบบ
        }

        Audit::setActor($data);
        Audit::loginEvent($this->db, 'login', array(
            'user_id' => $data['user_id'],
            'loginname' => $data['loginname'],
            'name' => $data['name'],
            'role' => $data['role'],
            'method' => 'password',
        ));

        $next = 'flood';
        $want = Session::get('flood_after_login');
        if (is_string($want) && strpos($want, 'flood') === 0) {
            $next = $want;
        }
        Session::set('flood_after_login', null);
        if ($data['must_change_password'] === 1) {
            $next = 'flood/profile';
        }
        return array('chk' => true, 'url' => URL, 'redirect' => URL . $next);
    }

}
