<?php

class Flood_Model extends Model {

    /* ==================== ข้อมูลอ้างอิง ==================== */

    /**
     * สร้างตาราง flood_province + เติมอำเภอ/ตำบลภาคตะวันออก 7 จังหวัดครั้งแรก (จาก sql/province_east.json)
     * รันซ้ำได้ (INSERT IGNORE) · ไม่มีสิทธิ์ CREATE → ใช้ sql/15_flood_province_east.sql แทน
     */
    public function ensureProvinces() {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        try {
            $n = (int) $this->db->selectValue("SELECT COUNT(*) FROM flood_province");
            if ($n > 0) {
                return $ready = true;
            }
        } catch (Exception $e) {
            try {
                $this->db->exec("CREATE TABLE IF NOT EXISTS `flood_province` (
                    `province_code` CHAR(2) NOT NULL COMMENT 'รหัสจังหวัด (กรมการปกครอง) เช่น 27',
                    `name` VARCHAR(100) NOT NULL,
                    `sort_order` INT NOT NULL DEFAULT 0,
                    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                    `lat` DECIMAL(10,7) DEFAULT NULL COMMENT 'จุดกึ่งกลางโดยประมาณ',
                    `lng` DECIMAL(10,7) DEFAULT NULL,
                    PRIMARY KEY (`province_code`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            } catch (Exception $e2) {
                error_log('[flood] create flood_province: ' . $e2->getMessage());
                return $ready = false;
            }
        }
        $file = dirname(__DIR__) . '/sql/province_east.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($data) || empty($data['provinces'])) {
            error_log('[flood] province seed missing: ' . $file);
            return $ready = false;
        }
        try {
            $this->db->beginTransaction();
            $this->seedRows("INSERT IGNORE INTO flood_province (province_code, name, sort_order, is_active, lat, lng) VALUES ",
                '(?, ?, ?, 1, ?, ?)', $data['provinces']);
            $amphoes = array();
            foreach ($data['amphoes'] as $a) {
                $amphoes[] = array($a[0], $a[1], (int) $a[0]);   // อำเภอใหม่เรียงตามรหัส (สระแก้วเดิม 1–9 อยู่บนสุด)
            }
            $this->seedRows("INSERT IGNORE INTO flood_amphoe (amphoe_code, name, sort_order, is_active) VALUES ", '(?, ?, ?, 1)', $amphoes);
            $this->seedRows("INSERT IGNORE INTO flood_tambon (tambon_code, amphoe_code, name, zipcode, lat, lng) VALUES ",
                '(?, ?, ?, ?, ?, ?)', $data['tambons']);
            $this->db->commit();
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('[flood] province seed: ' . $e->getMessage());
            return $ready = false;
        }
        return $ready = true;
    }

    /** INSERT หลายแถวต่อคำสั่ง (ค่าผูกผ่าน placeholder ทั้งหมด) — ไม่ผ่าน Audit เพราะเป็นข้อมูลอ้างอิง */
    private function seedRows($head, $tuple, $rows) {
        foreach (array_chunk($rows, 100) as $chunk) {
            $vals = array();
            foreach ($chunk as $r) {
                foreach ($r as $v) {
                    $vals[] = $v;
                }
            }
            $sth = $this->db->prepare($head . implode(', ', array_fill(0, count($chunk), $tuple)));
            $sth->execute($vals);
        }
    }

    /** จังหวัดที่เปิดใช้ (สระแก้วก่อน) */
    public function getProvinces() {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        if (!$this->ensureProvinces()) {
            // ยังไม่มีตาราง — ใช้จังหวัดจากรหัสอำเภอที่มีอยู่
            $cache = array();
            foreach ($this->db->select("SELECT DISTINCT LEFT(amphoe_code, 2) AS pv FROM flood_amphoe WHERE is_active = 1 ORDER BY pv") as $r) {
                $cache[] = array('province_code' => $r['pv'], 'name' => $r['pv'] === '27' ? 'สระแก้ว' : $r['pv'], 'lat' => null, 'lng' => null);
            }
            return $cache;
        }
        $cache = $this->db->select("SELECT province_code, name, lat, lng FROM flood_province WHERE is_active = 1 ORDER BY sort_order, province_code");
        foreach ($cache as $i => $p) {
            $cache[$i]['lat'] = $p['lat'] !== null ? (float) $p['lat'] : null;
            $cache[$i]['lng'] = $p['lng'] !== null ? (float) $p['lng'] : null;
        }
        return $cache;
    }

    /** อำเภอที่เปิดใช้ (เฉพาะจังหวัดที่เปิดใช้) พร้อม province_code / province_name */
    public function getAmphoes($province = '') {
        $pv = array();
        foreach ($this->getProvinces() as $p) {
            $pv[$p['province_code']] = $p['name'];
        }
        $out = array();
        foreach ($this->db->select("SELECT amphoe_code, name FROM flood_amphoe WHERE is_active = 1 ORDER BY sort_order, name") as $a) {
            $code = flood_province_of($a['amphoe_code']);
            if (!isset($pv[$code]) || ($province !== '' && $code !== $province)) {
                continue;
            }
            $a['province_code'] = $code;
            $a['province_name'] = $pv[$code];
            $out[] = $a;
        }
        return $out;
    }

    /** กรอบพิกัดของแต่ละจังหวัด (จากจุดกึ่งกลางตำบล + ขอบ ~3 กม.) — ใช้กรองรายงานที่มีแต่พิกัด */
    public function provinceBox($province) {
        if ($province === '') {
            return null;
        }
        $r = $this->db->selectOne(
            "SELECT MIN(lat) AS s, MAX(lat) AS n, MIN(lng) AS w, MAX(lng) AS e FROM flood_tambon
             WHERE amphoe_code LIKE :pv AND lat IS NOT NULL", array(':pv' => $province . '%'));
        if (!$r || $r['s'] === null) {
            return null;
        }
        $m = 0.03;
        return array((float) $r['s'] - $m, (float) $r['n'] + $m, (float) $r['w'] - $m, (float) $r['e'] + $m);
    }

    public function getTambons() {
        return $this->db->select("SELECT tambon_code, amphoe_code, name, lat, lng FROM flood_tambon ORDER BY amphoe_code, name");
    }

    public function amphoeExists($code) {
        return (bool) $this->db->selectValue("SELECT COUNT(*) FROM flood_amphoe WHERE amphoe_code = :c", array(':c' => $code));
    }

    /** ตำบลต้องอยู่ในอำเภอที่เลือก — คืน code ที่ใช้ได้ หรือ null */
    public function validTambon($tambon, $amphoe) {
        if ($tambon === '' || $tambon === null) {
            return null;
        }
        $row = $this->db->selectOne("SELECT tambon_code, amphoe_code FROM flood_tambon WHERE tambon_code = :t", array(':t' => $tambon));
        if (!$row) {
            return null;
        }
        if ($amphoe !== null && $amphoe !== '' && $row['amphoe_code'] !== $amphoe) {
            return null;
        }
        return $row['tambon_code'];
    }

    /**
     * ตำบล/อำเภอที่ใกล้พิกัดที่สุด (เทียบกับจุดกึ่งกลางตำบล) — ใช้เดาพื้นที่เมื่อผู้แจ้งไม่ได้เลือก
     * เป็นค่าประมาณ เจ้าหน้าที่แก้ได้ภายหลัง
     */
    public function guessArea($lat, $lng, $maxKm = 20) {
        if (!flood_valid_latlng($lat, $lng)) {
            return null;
        }
        $best = null;
        $bestD = $maxKm * 1000;
        $rows = $this->db->select(
            "SELECT t.tambon_code, t.amphoe_code, t.name, t.lat, t.lng, a.name AS amphoe_name
             FROM flood_tambon t INNER JOIN flood_amphoe a ON a.amphoe_code = t.amphoe_code
             WHERE t.lat IS NOT NULL"
        );
        foreach ($rows as $t) {
            $d = flood_haversine_m($lat, $lng, $t['lat'], $t['lng']);
            if ($d < $bestD) {
                $bestD = $d;
                $best = $t;
            }
        }
        return $best;
    }

    public function getTeams($activeOnly = true) {
        return $this->db->select(
            "SELECT t.*, a.name AS amphoe_name,
                    (SELECT COUNT(*) FROM flood_help h WHERE h.team_id = t.team_id
                       AND h.status IN ('assigned','in_progress')) AS open_jobs,
                    (SELECT COUNT(*) FROM flood_user u WHERE u.team_id = t.team_id AND u.is_active = 1) AS member_count
             FROM flood_team t
             LEFT JOIN flood_amphoe a ON a.amphoe_code = t.amphoe_code
             " . ($activeOnly ? "WHERE t.is_active = 1" : "") . "
             ORDER BY t.is_active DESC, t.sort_order, t.name"
        );
    }

    public function getTeam($id) {
        return $this->db->selectOne("SELECT * FROM flood_team WHERE team_id = :id", array(':id' => (int) $id));
    }

    public function saveTeam($id, $data) {
        if ($id > 0) {
            $data['updated_at'] = date('Y-m-d H:i:s');
            $this->db->update('flood_team', $data, 'team_id = :w_id', array(':w_id' => (int) $id));
            return (int) $id;
        }
        return $this->db->insert('flood_team', $data);
    }

    /* ==================== ผู้ใช้ / ออนไลน์ ==================== */

    public function getUserById($id) {
        return $this->db->selectOne(
            "SELECT u.user_id, u.loginname, u.name, u.role, u.team_id, u.org_name, u.phone, u.is_active,
                    u.must_change_password, u.last_login_at, u.last_seen_at, u.last_ip, u.last_page,
                    u.session_revoked_at, u.created_at, t.name AS team_name
             FROM flood_user u LEFT JOIN flood_team t ON t.team_id = u.team_id
             WHERE u.user_id = :id",
            array(':id' => (int) $id)
        );
    }

    /** บันทึกเวลาใช้งานล่าสุด — controller เรียกอย่างมาก 1 ครั้ง/นาที + ทุกครั้งที่หน้าจอส่งสัญญาณ */
    public function touchUserActivity($userId, $ip = '', $page = null) {
        $data = array('last_seen_at' => date('Y-m-d H:i:s'));
        if ($ip !== '') {
            $data['last_ip'] = substr($ip, 0, 45);
        }
        if ($page !== null && $page !== '') {
            $data['last_page'] = mb_substr($page, 0, 120);
        }
        $this->db->update('flood_user', $data, 'user_id = :w_id', array(':w_id' => (int) $userId));
    }

    /** สถานะบัญชีที่ต้องตรวจระหว่างใช้งาน (ถูกปิด / ถูกสั่งออกจากระบบ / เปลี่ยนสิทธิ์) */
    public function getSessionState($userId) {
        return $this->db->selectOne(
            "SELECT u.is_active, u.session_revoked_at, u.role, u.team_id, u.name, u.must_change_password, t.name AS team_name
             FROM flood_user u LEFT JOIN flood_team t ON t.team_id = u.team_id WHERE u.user_id = :id",
            array(':id' => (int) $userId)
        );
    }

    private function onlineCutoff() {
        $min = defined('ONLINE_MINUTES') ? max(1, (int) ONLINE_MINUTES) : 3;
        return date('Y-m-d H:i:s', time() - $min * 60);
    }

    public function getOnlineSummary() {
        $row = $this->db->selectOne(
            "SELECT
                SUM(CASE WHEN last_seen_at >= :online AND is_active = 1 THEN 1 ELSE 0 END) AS online_now,
                SUM(CASE WHEN last_seen_at >= :h24 THEN 1 ELSE 0 END) AS active_24h,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS total_active
             FROM flood_user",
            array(':online' => $this->onlineCutoff(), ':h24' => date('Y-m-d H:i:s', time() - 86400))
        );
        return array(
            'online_now' => $row ? (int) $row['online_now'] : 0,
            'active_24h' => $row ? (int) $row['active_24h'] : 0,
            'total_active' => $row ? (int) $row['total_active'] : 0,
        );
    }

    /** รายชื่อคนที่ออนไลน์ตอนนี้ — ใช้กับรายการบนแถบด้านบน (ทุกคนที่ล็อกอินเห็นได้) */
    public function listOnlineNow($limit = 60) {
        $rows = $this->db->select(
            "SELECT u.user_id, u.name, u.role, u.org_name, u.last_seen_at, u.last_page, t.name AS team_name
             FROM flood_user u LEFT JOIN flood_team t ON t.team_id = u.team_id
             WHERE u.is_active = 1 AND u.last_seen_at >= :online
             ORDER BY u.last_seen_at DESC LIMIT " . (int) $limit,
            array(':online' => $this->onlineCutoff())
        );
        foreach ($rows as $i => $r) {
            $rows[$i]['role_label'] = flood_role_label($r['role']);
            $rows[$i]['ago_label'] = flood_ago($r['last_seen_at']);
        }
        return $rows;
    }

    /** ผู้ใช้ที่ใช้งานภายใน N ชั่วโมง — หน้า ผู้ใช้งานออนไลน์ (admin) */
    public function listRecentActiveUsers($hours = 24, $filters = array()) {
        $hours = max(1, min(720, (int) $hours));
        $where = array('u.last_seen_at IS NOT NULL', 'u.last_seen_at >= :cutoff');
        $params = array(':cutoff' => date('Y-m-d H:i:s', time() - $hours * 3600));
        $q = isset($filters['q']) ? trim((string) $filters['q']) : '';
        if ($q !== '') {
            $where[] = '(u.loginname LIKE :q OR u.name LIKE :q OR u.org_name LIKE :q OR t.name LIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }
        $role = isset($filters['role']) ? trim((string) $filters['role']) : '';
        if ($role !== '') {
            $where[] = 'u.role = :role';
            $params[':role'] = $role;
        }
        $rows = $this->db->select(
            "SELECT u.user_id, u.loginname, u.name, u.role, u.org_name, u.is_active, u.last_login_at,
                    u.last_seen_at, u.last_ip, u.last_page, t.name AS team_name
             FROM flood_user u LEFT JOIN flood_team t ON t.team_id = u.team_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY u.last_seen_at DESC LIMIT 500",
            $params
        );
        $cut = strtotime($this->onlineCutoff());
        $now = time();
        foreach ($rows as $i => $r) {
            $seen = strtotime($r['last_seen_at']);
            $rows[$i]['is_online'] = ($seen >= $cut && (int) $r['is_active'] === 1) ? 1 : 0;
            $rows[$i]['ago_label'] = flood_ago_label($now - $seen);
            $rows[$i]['role_label'] = flood_role_label($r['role']);
            $rows[$i]['last_seen_th'] = flood_thai_date($r['last_seen_at']);
            $rows[$i]['last_login_th'] = flood_thai_date($r['last_login_at']);
        }
        return $rows;
    }

    /** ผู้ดูแลสั่งออกจากระบบ — session ของคนนั้นถูกตัดในการเรียกครั้งถัดไป (ไม่เกิน 1 นาที) */
    public function kickUser($userId) {
        $this->db->update('flood_user', array(
            'session_revoked_at' => date('Y-m-d H:i:s'),
            'last_seen_at' => null,
            'last_page' => null,
        ), 'user_id = :w_id', array(':w_id' => (int) $userId));
    }

    public function listUsers($filters = array()) {
        $where = array('1=1');
        $params = array();
        $q = isset($filters['q']) ? trim((string) $filters['q']) : '';
        if ($q !== '') {
            $where[] = '(u.loginname LIKE :q OR u.name LIKE :q OR u.org_name LIKE :q OR u.phone LIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }
        if (!empty($filters['role'])) {
            $where[] = 'u.role = :role';
            $params[':role'] = $filters['role'];
        }
        if (isset($filters['status']) && $filters['status'] !== '') {
            $where[] = 'u.is_active = :act';
            $params[':act'] = $filters['status'] === 'inactive' ? 0 : 1;
        }
        $rows = $this->db->select(
            "SELECT u.user_id, u.loginname, u.name, u.role, u.team_id, u.org_name, u.phone, u.is_active,
                    u.must_change_password, u.last_login_at, u.last_seen_at, t.name AS team_name
             FROM flood_user u LEFT JOIN flood_team t ON t.team_id = u.team_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY u.is_active DESC, FIELD(u.role, 'super_admin','admin','officer','team','viewer'), u.name
             LIMIT 1000",
            $params
        );
        $cut = strtotime($this->onlineCutoff());
        foreach ($rows as $i => $r) {
            $rows[$i]['is_online'] = ($r['last_seen_at'] && strtotime($r['last_seen_at']) >= $cut) ? 1 : 0;
        }
        return $rows;
    }

    public function loginnameExists($loginname, $exceptId = 0) {
        return (bool) $this->db->selectValue(
            "SELECT COUNT(*) FROM flood_user WHERE loginname = :l AND user_id <> :id",
            array(':l' => $loginname, ':id' => (int) $exceptId)
        );
    }

    public function countSuperAdmins() {
        return (int) $this->db->selectValue("SELECT COUNT(*) FROM flood_user WHERE role = 'super_admin' AND is_active = 1");
    }

    public function saveUser($id, $data) {
        if ($id > 0) {
            $data['updated_at'] = date('Y-m-d H:i:s');
            $this->db->update('flood_user', $data, 'user_id = :w_id', array(':w_id' => (int) $id));
            return (int) $id;
        }
        return $this->db->insert('flood_user', $data);
    }

    public function passwordMatches($userId, $password) {
        $hash = $this->db->selectValue("SELECT password_hash FROM flood_user WHERE user_id = :id", array(':id' => (int) $userId));
        return $hash && password_verify($password, $hash);
    }

    public function setPassword($userId, $password, $mustChange = 0) {
        $this->db->update('flood_user', array(
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'must_change_password' => (int) $mustChange,
            'updated_at' => date('Y-m-d H:i:s'),
        ), 'user_id = :w_id', array(':w_id' => (int) $userId));
    }

    /* ==================== ประวัติการใช้งาน ==================== */

    private function logRange($filters, $col, &$where, &$params) {
        $days = isset($filters['days']) ? (int) $filters['days'] : 7;
        if ($days > 0) {
            $where[] = "$col >= :from";
            $params[':from'] = date('Y-m-d 00:00:00', time() - ($days - 1) * 86400);
        }
    }

    public function listLoginLog($filters, $limit = 100, $page = 1) {
        $where = array('1=1');
        $params = array();
        $this->logRange($filters, 'created_at', $where, $params);
        if (!empty($filters['event'])) {
            $where[] = 'event = :ev';
            $params[':ev'] = $filters['event'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(loginname LIKE :q OR name LIKE :q OR ip LIKE :q)';
            $params[':q'] = '%' . $filters['q'] . '%';
        }
        return $this->paginate("SELECT * FROM flood_login_log WHERE " . implode(' AND ', $where),
            "ORDER BY login_log_id DESC", $params, $limit, $page);
    }

    public function listAuditLog($filters, $limit = 100, $page = 1) {
        $where = array('1=1');
        $params = array();
        $this->logRange($filters, 'created_at', $where, $params);
        if (!empty($filters['table'])) {
            $where[] = 'table_name = :tb';
            $params[':tb'] = $filters['table'];
        }
        if (!empty($filters['action'])) {
            $where[] = 'action = :ac';
            $params[':ac'] = $filters['action'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(actor_loginname LIKE :q OR actor_name LIKE :q OR row_pk = :qe OR route LIKE :q)';
            $params[':q'] = '%' . $filters['q'] . '%';
            $params[':qe'] = $filters['q'];
        }
        return $this->paginate(
            "SELECT audit_id, table_name, row_pk, action, actor_loginname, actor_name, actor_role,
                    changed_cols, row_count, route, ip, created_at
             FROM flood_audit_log WHERE " . implode(' AND ', $where),
            "ORDER BY audit_id DESC", $params, $limit, $page);
    }

    public function getAuditEntry($id) {
        return $this->db->selectOne("SELECT * FROM flood_audit_log WHERE audit_id = :id", array(':id' => (int) $id));
    }

    /** แบ่งหน้า: คืน rows + total + pages */
    private function paginate($sql, $orderBy, $params, $limit, $page) {
        $limit = max(1, min(500, (int) $limit));
        $page = max(1, (int) $page);
        $total = (int) $this->db->selectValue("SELECT COUNT(*) FROM (" . $sql . ") x", $params);
        $pages = max(1, (int) ceil($total / $limit));
        $page = min($page, $pages);
        $rows = $this->db->select($sql . " " . $orderBy . " LIMIT " . $limit . " OFFSET " . (($page - 1) * $limit), $params);
        return array('rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $limit);
    }

    /* ==================== พื้นที่ประกาศ ==================== */

    public function listZones($filters = array()) {
        $where = array('1=1');
        $params = array();
        $status = isset($filters['status']) ? $filters['status'] : 'active';
        if ($status === 'active' || $status === 'ended') {
            $where[] = 'z.status = :st';
            $params[':st'] = $status;
        }
        if (!empty($filters['level']) && array_key_exists($filters['level'], flood_zone_levels())) {
            $where[] = 'z.level = :lv';
            $params[':lv'] = $filters['level'];
        }
        if (!empty($filters['amphoe'])) {
            $where[] = 'z.amphoe_code = :am';
            $params[':am'] = $filters['amphoe'];
        }
        if (!empty($filters['province'])) {
            $where[] = 'z.amphoe_code LIKE :pv';
            $params[':pv'] = $filters['province'] . '%';
        }
        if (!empty($filters['q'])) {
            $where[] = '(z.name LIKE :q OR z.note LIKE :q OR t.name LIKE :q)';
            $params[':q'] = '%' . $filters['q'] . '%';
        }
        return $this->db->select(
            "SELECT z.*, a.name AS amphoe_name, t.name AS tambon_name, uu.name AS updated_by_name,
                    (SELECT COUNT(*) FROM flood_report r WHERE r.zone_id = z.zone_id) AS report_count,
                    (SELECT COUNT(*) FROM flood_attachment p WHERE p.ref_type = 'zone' AND p.ref_id = z.zone_id) AS photo_count
             FROM flood_zone z
             LEFT JOIN flood_amphoe a ON a.amphoe_code = z.amphoe_code
             LEFT JOIN flood_tambon t ON t.tambon_code = z.tambon_code
             LEFT JOIN flood_user uu ON uu.user_id = COALESCE(z.updated_by, z.created_by)
             WHERE " . implode(' AND ', $where) . "
             ORDER BY z.status = 'active' DESC, " . $this->levelOrderSql($params) . ",
                      COALESCE(z.updated_at, z.started_at) DESC
             LIMIT 1000",
            $params
        );
    }

    /** ORDER BY ตามความรุนแรงของระดับ (ลำดับที่ผู้ดูแลตั้งไว้) — ระดับที่ไม่รู้จักไปท้ายสุด */
    private function levelOrderSql(&$params, $col = 'z.level') {
        $ph = array();
        foreach (array_keys(flood_zone_levels(true)) as $i => $code) {
            $ph[] = ':lvo' . $i;
            $params[':lvo' . $i] = $code;
        }
        return $ph ? 'COALESCE(NULLIF(FIELD(' . $col . ', ' . implode(', ', $ph) . '), 0), 999)' : '0';
    }

    public function getZone($id) {
        return $this->db->selectOne(
            "SELECT z.*, a.name AS amphoe_name, t.name AS tambon_name
             FROM flood_zone z
             LEFT JOIN flood_amphoe a ON a.amphoe_code = z.amphoe_code
             LEFT JOIN flood_tambon t ON t.tambon_code = z.tambon_code
             WHERE z.zone_id = :id",
            array(':id' => (int) $id)
        );
    }

    public function saveZone($id, $data, $userId) {
        $now = date('Y-m-d H:i:s');
        if ($id > 0) {
            $data['updated_by'] = $userId;
            $data['updated_at'] = $now;
            $this->db->update('flood_zone', $data, 'zone_id = :w_id', array(':w_id' => (int) $id));
            return (int) $id;
        }
        $data['created_by'] = $userId;
        if (empty($data['started_at'])) {
            $data['started_at'] = $now;
        }
        return $this->db->insert('flood_zone', $data);
    }

    public function setZoneStatus($id, $status, $userId) {
        $data = array('status' => $status, 'updated_by' => $userId, 'updated_at' => date('Y-m-d H:i:s'));
        $data['ended_at'] = $status === 'ended' ? date('Y-m-d H:i:s') : null;
        $this->db->update('flood_zone', $data, 'zone_id = :w_id', array(':w_id' => (int) $id));
    }

    /** พื้นที่ที่ประกาศอยู่ ผ่าน flood_zone_prepare แล้ว (พร้อมตรวจจุด) */
    public function activeZones() {
        $params = array();
        $rows = $this->db->select(
            "SELECT z.zone_id, z.name, z.level, z.shape, z.center_lat, z.center_lng, z.radius_m, z.polygon_json,
                    z.amphoe_code, z.tambon_code, z.note, z.started_at, z.updated_at
             FROM flood_zone z WHERE z.status = 'active'
             ORDER BY " . $this->levelOrderSql($params) . ", z.started_at DESC",
            $params
        );
        return array_map('flood_zone_prepare', $rows);
    }

    /** พื้นที่ที่จุดนี้อยู่ข้างใน — ระดับรุนแรงสุดก่อน */
    public function zonesContaining($lat, $lng, $zones = null) {
        if (!flood_valid_latlng($lat, $lng)) {
            return array();
        }
        $zones = $zones === null ? $this->activeZones() : $zones;
        $out = array();
        foreach ($zones as $z) {
            if (flood_point_in_zone($lat, $lng, $z)) {
                $out[] = $z;
            }
        }
        return $out;
    }

    public function zoneCounts() {
        $out = array();
        foreach (array_keys(flood_zone_levels(true)) as $code) {
            $out[$code] = 0;
        }
        $out['total'] = 0;
        foreach ($this->db->select("SELECT level, COUNT(*) AS c FROM flood_zone WHERE status = 'active' GROUP BY level") as $r) {
            if (isset($out[$r['level']]) && $r['level'] !== 'total') {
                $out[$r['level']] = (int) $r['c'];
            }
            $out['total'] += (int) $r['c'];
        }
        return $out;
    }

    /** แปลงแถวพื้นที่เป็นข้อมูลสำหรับแผนที่ (ไม่มีข้อมูลส่วนบุคคล) */
    public function zoneForMap($z) {
        return array(
            'zone_id' => (int) $z['zone_id'],
            'name' => $z['name'],
            'level' => $z['level'],
            'shape' => $z['shape'],
            'center' => array((float) $z['center_lat'], (float) $z['center_lng']),
            'radius_m' => $z['radius_m'] !== null ? (int) $z['radius_m'] : null,
            'polygon' => $z['shape'] === 'polygon' ? flood_parse_polygon($z['polygon_json']) : null,
            'amphoe_name' => isset($z['amphoe_name']) ? $z['amphoe_name'] : null,
            'tambon_name' => isset($z['tambon_name']) ? $z['tambon_name'] : null,
            'note' => $z['note'],
            'status' => isset($z['status']) ? $z['status'] : 'active',
            'started_at' => $z['started_at'],
            'updated_at' => isset($z['updated_at']) && $z['updated_at'] ? $z['updated_at'] : $z['started_at'],
            'started_th' => flood_thai_date($z['started_at']),
        );
    }

    /**
     * จุดที่ประชาชนแจ้งและเจ้าหน้าที่ยืนยัน + ผูกกับพื้นที่ที่ยังประกาศอยู่ — แสดงบนแผนที่สาธารณะ
     * ให้คนเห็นจุดน้ำท่วมจริงทีละจุด (ระวังตอนเข้าพื้นที่/ผ่านทาง)
     * ไม่ส่งชื่อ เบอร์ จุดสังเกต (ข้อความอิสระอาจมีข้อมูลส่วนบุคคล) หรือรูปของผู้แจ้ง
     */
    public function publicReportPoints($amphoe = '', $province = '') {
        $params = array();
        $cond = '';
        if ($amphoe !== '') {
            $cond = ' AND z.amphoe_code = :am';
            $params[':am'] = $amphoe;
        } elseif ($province !== '') {
            $cond = ' AND z.amphoe_code LIKE :pv';
            $params[':pv'] = $province . '%';
        }
        $impactCol = $this->reportImpactsReady() ? 'r.impacts' : 'NULL';
        $sourceCol = $this->reportSourceReady() ? 'r.source_url' : 'NULL';
        $rows = $this->db->select(
            "SELECT r.report_id, r.lat, r.lng, r.depth, r.extent, r.vehicle, r.trend, $impactCol AS impacts,
                    $sourceCol AS source_url, r.loc_method, r.created_at, z.zone_id, z.level, z.name AS zone_name
             FROM flood_report r
             JOIN flood_zone z ON z.zone_id = r.zone_id
             WHERE r.status = 'verified' AND z.status = 'active'" . $cond . "
             ORDER BY r.created_at DESC
             LIMIT 1000",
            $params
        );
        $depth = flood_depth_options();
        $extent = flood_extent_options();
        $vehicle = flood_vehicle_options();
        $trend = flood_trend_options();
        $impacts = flood_area_impacts();
        $out = array();
        foreach ($rows as $r) {
            $out[] = array(
                'id' => (int) $r['report_id'],
                'lat' => round((float) $r['lat'], 6),
                'lng' => round((float) $r['lng'], 6),
                'zone_id' => (int) $r['zone_id'],
                'zone_name' => $r['zone_name'],
                'level' => $r['level'],
                'depth' => flood_opt_name($depth, $r['depth'], ''),
                'extent' => flood_opt_name($extent, $r['extent'], ''),
                'vehicle' => $r['vehicle'] ? flood_opt_name($vehicle, $r['vehicle'], '') : '',
                'trend' => $r['trend'] ? flood_opt_name($trend, $r['trend'], '') : '',
                'impacts' => flood_codes_names((string) $r['impacts'], $impacts),
                'approx' => $r['loc_method'] === 'approx',
                'fb_url' => self::publicSocialUrl($r['source_url']),
                'time_th' => flood_thai_date($r['created_at']),
                'ago' => flood_ago($r['created_at']),
            );
        }
        return array_merge($out, $this->publicPendingPoints($amphoe, $province));
    }

    /**
     * จุดที่ประชาชนแจ้งเข้ามาแต่เจ้าหน้าที่ยังไม่ได้ตรวจ — แสดงบนแผนที่ทันทีพร้อมป้าย "รอตรวจสอบ"
     * เฉพาะ 48 ชั่วโมงล่าสุด (ปรับได้ด้วย PUBLIC_PENDING_HOURS · ปิดทั้งหมดด้วย define('PUBLIC_SHOW_PENDING', false))
     * ไม่ส่งชื่อ เบอร์ หรือข้อความจุดสังเกตของผู้แจ้ง · ไม่ผลกับตัวเลขพื้นที่ประกาศ
     */
    private function publicPendingPoints($amphoe = '', $province = '') {
        if (defined('PUBLIC_SHOW_PENDING') && !PUBLIC_SHOW_PENDING) {
            return array();
        }
        $hours = defined('PUBLIC_PENDING_HOURS') ? max(1, (int) PUBLIC_PENDING_HOURS) : 48;
        $impactCol = $this->reportImpactsReady() ? 'r.impacts' : 'NULL';
        $sourceCol = $this->reportSourceReady() ? 'r.source_url' : 'NULL';
        $rows = $this->db->select(
            "SELECT r.report_id, r.lat, r.lng, r.depth, r.extent, r.vehicle, r.trend, $impactCol AS impacts,
                    $sourceCol AS source_url, r.loc_method, r.created_at
             FROM flood_report r
             WHERE r.status = 'pending' AND r.created_at >= :t
             ORDER BY r.created_at DESC
             LIMIT 500",
            array(':t' => date('Y-m-d H:i:s', time() - $hours * 3600))
        );
        if (!$rows) {
            return array();
        }
        // อำเภอของจุด = ตำบลที่ใกล้ที่สุด (ใช้กรองตามอำเภอบนหน้าประชาชน)
        $tambons = $this->db->select("SELECT t.amphoe_code, t.lat, t.lng FROM flood_tambon t WHERE t.lat IS NOT NULL");
        $depth = flood_depth_options();
        $extent = flood_extent_options();
        $vehicle = flood_vehicle_options();
        $trend = flood_trend_options();
        $impacts = flood_area_impacts();
        $out = array();
        foreach ($tambons as $i => $t) {
            $tambons[$i]['lat'] = (float) $t['lat'];
            $tambons[$i]['lng'] = (float) $t['lng'];
        }
        foreach ($rows as $r) {
            // หาตำบลใกล้สุดด้วยระยะแบบประมาณ (เร็ว — ตำบลทั้งภูมิภาคหลายร้อยแห่ง) แล้วค่อยตรวจระยะจริง
            $am = '';
            $bestT = null;
            $best = INF;
            $la = (float) $r['lat'];
            $ln = (float) $r['lng'];
            $k = cos(deg2rad($la));
            foreach ($tambons as $t) {
                $dy = $t['lat'] - $la;
                $dx = ($t['lng'] - $ln) * $k;
                $d = $dx * $dx + $dy * $dy;
                if ($d < $best) {
                    $best = $d;
                    $bestT = $t;
                }
            }
            if ($bestT && flood_haversine_m($la, $ln, $bestT['lat'], $bestT['lng']) < 40000) {
                $am = $bestT['amphoe_code'];
            }
            if ($am === '' || ($amphoe !== '' && $am !== $amphoe)
                || ($amphoe === '' && $province !== '' && flood_province_of($am) !== $province)) {
                continue;   // นอกพื้นที่ระบบ หรือไม่ใช่อำเภอ/จังหวัดที่เลือก
            }
            $out[] = array(
                'id' => (int) $r['report_id'],
                'lat' => round((float) $r['lat'], 5),
                'lng' => round((float) $r['lng'], 5),
                'zone_id' => 0,
                'zone_name' => '',
                'level' => '',
                'pending' => true,
                'depth' => flood_opt_name($depth, $r['depth'], ''),
                'extent' => flood_opt_name($extent, $r['extent'], ''),
                'vehicle' => $r['vehicle'] ? flood_opt_name($vehicle, $r['vehicle'], '') : '',
                'trend' => $r['trend'] ? flood_opt_name($trend, $r['trend'], '') : '',
                'impacts' => flood_codes_names((string) $r['impacts'], $impacts),
                'approx' => $r['loc_method'] === 'approx',
                'fb_url' => self::publicSocialUrl($r['source_url']),
                'time_th' => flood_thai_date($r['created_at']),
                'ago' => flood_ago($r['created_at']),
            );
        }
        return $out;
    }

    /** มีคอลัมน์ flood_report.source_url (sql/11) หรือยัง — อ่านอย่างเดียว ไม่แก้ตาราง */
    private function reportSourceReady() {
        static $ready = null;
        if ($ready === null) {
            try {
                $ready = (bool) $this->db->select("SHOW COLUMNS FROM flood_report LIKE 'source_url'");
            } catch (Exception $e) {
                $ready = false;
            }
        }
        return $ready;
    }

    /**
     * ลิงก์โพสต์ต้นทางที่แสดงต่อประชาชนได้ — รับเฉพาะ https ของ Facebook เท่านั้น
     * ลิงก์อื่น/รูปแบบแปลก คืนค่าว่าง (กันลิงก์หลอกไปเว็บอื่นจากข้อมูลที่นำเข้า)
     */
    private static function publicSocialUrl($url) {
        $url = preg_replace('#^http://#i', 'https://', trim((string) $url));
        if ($url === '' || strlen($url) > 500 || !preg_match('#^https://#i', $url)) {
            return '';
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (!preg_match('/^((www|m|mbasic|web)\.)?(facebook\.com|fb\.com|fb\.watch)$/', $host)) {
            return '';
        }
        return $url;
    }

    /** ข้อมูลหน้าแผนที่สาธารณะ (พร้อมรหัสภาพประกอบ) */
    public function publicZones($amphoe = '', $province = '') {
        $rows = $this->listZones(array('status' => 'active', 'amphoe' => $amphoe, 'province' => $amphoe === '' ? $province : ''));
        $out = array();
        foreach ($rows as $z) {
            $out[] = $this->zoneForMap($z);
        }
        return $this->withZonePhotos($out);
    }

    /* ==================== ระดับพื้นที่ (ผู้ดูแลตั้งค่า) ==================== */

    /**
     * สร้างตาราง flood_zone_level ถ้ายังไม่มี แล้วใส่ระดับตั้งต้น (เหมือน sql/08_flood_zone_level.sql)
     * คืน false ถ้าสร้างไม่ได้ (บัญชีฐานข้อมูลไม่มีสิทธิ์ CREATE) — ระบบยังใช้ระดับตั้งต้นในโค้ดต่อได้
     */
    public function ensureLevelTable() {
        try {
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS `flood_zone_level` (
                  `level_code` VARCHAR(20) NOT NULL COMMENT 'ค่าที่เก็บใน flood_zone.level',
                  `name` VARCHAR(60) NOT NULL,
                  `description` VARCHAR(200) DEFAULT NULL,
                  `color` CHAR(7) NOT NULL DEFAULT '#64748b' COMMENT 'สีพื้นที่บนแผนที่ #rrggbb',
                  `icon` VARCHAR(40) NOT NULL DEFAULT 'fa-circle' COMMENT 'ไอคอน Font Awesome 4.7',
                  `sort_order` INT NOT NULL DEFAULT 0 COMMENT 'ความรุนแรง 1 = รุนแรงสุด',
                  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  `updated_at` DATETIME DEFAULT NULL,
                  `updated_by` INT UNSIGNED DEFAULT NULL,
                  PRIMARY KEY (`level_code`),
                  KEY `idx_flood_zone_level_order` (`sort_order`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            if ((int) $this->db->selectValue("SELECT COUNT(*) FROM flood_zone_level") === 0) {
                foreach (flood_zone_levels_builtin() as $code => $l) {
                    $this->db->insert('flood_zone_level', array(
                        'level_code' => $code, 'name' => $l['name'], 'description' => $l['desc'],
                        'color' => $l['color'], 'icon' => $l['icon'], 'sort_order' => (int) $l['order'], 'is_active' => 1,
                    ));
                }
            }
        } catch (Exception $e) {
            error_log('[flood] สร้างตาราง flood_zone_level ไม่ได้: ' . $e->getMessage());
            return false;
        }
        flood_zone_levels_reset();
        return true;
    }

    /** รายการระดับสำหรับหน้าตั้งค่า พร้อมจำนวนพื้นที่ที่ใช้ระดับนั้น */
    public function listLevelsAdmin() {
        return $this->db->select(
            "SELECT l.*,
                    (SELECT COUNT(*) FROM flood_zone z WHERE z.level = l.level_code AND z.status = 'active') AS active_zones,
                    (SELECT COUNT(*) FROM flood_zone z WHERE z.level = l.level_code) AS all_zones
             FROM flood_zone_level l
             ORDER BY l.sort_order, l.level_code"
        );
    }

    public function getLevel($code) {
        return $this->db->selectOne(
            "SELECT l.*,
                    (SELECT COUNT(*) FROM flood_zone z WHERE z.level = l.level_code AND z.status = 'active') AS active_zones,
                    (SELECT COUNT(*) FROM flood_zone z WHERE z.level = l.level_code) AS all_zones
             FROM flood_zone_level l WHERE l.level_code = :c",
            array(':c' => (string) $code)
        );
    }

    public function levelNameExists($name, $exceptCode = '') {
        return (int) $this->db->selectValue(
            "SELECT COUNT(*) FROM flood_zone_level WHERE name = :n AND level_code <> :c",
            array(':n' => $name, ':c' => (string) $exceptCode)
        ) > 0;
    }

    public function countActiveLevels() {
        return (int) $this->db->selectValue("SELECT COUNT(*) FROM flood_zone_level WHERE is_active = 1");
    }

    /** เพิ่ม/แก้ระดับ — ระดับใหม่ได้รหัส lv_<เลข> และต่อท้ายลำดับ (รุนแรงน้อยสุด) */
    public function saveLevel($code, $data, $userId) {
        $data['updated_by'] = $userId;
        $data['updated_at'] = date('Y-m-d H:i:s');
        if ($code !== '') {
            $this->db->update('flood_zone_level', $data, 'level_code = :w_code', array(':w_code' => $code));
            flood_zone_levels_reset();
            return $code;
        }
        $n = 1;
        foreach ($this->db->select("SELECT level_code FROM flood_zone_level WHERE level_code LIKE 'lv\\_%'") as $r) {
            $n = max($n, (int) substr($r['level_code'], 3) + 1);
        }
        $data['level_code'] = 'lv_' . $n;
        $data['sort_order'] = 1 + (int) $this->db->selectValue("SELECT COALESCE(MAX(sort_order), 0) FROM flood_zone_level");
        $this->db->insert('flood_zone_level', $data);
        flood_zone_levels_reset();
        return $data['level_code'];
    }

    /** เลื่อนลำดับขึ้น (รุนแรงขึ้น) / ลง — เรียงเลขใหม่ 1..n ทั้งชุด */
    public function moveLevel($code, $dir, $userId) {
        $codes = array();
        foreach ($this->db->select("SELECT level_code FROM flood_zone_level ORDER BY sort_order, level_code") as $r) {
            $codes[] = $r['level_code'];
        }
        $i = array_search($code, $codes, true);
        $j = $dir === 'up' ? $i - 1 : $i + 1;
        if ($i === false || $j < 0 || $j >= count($codes)) {
            return false;
        }
        $tmp = $codes[$i];
        $codes[$i] = $codes[$j];
        $codes[$j] = $tmp;
        Audit::ready($this->db);
        $this->db->beginTransaction();
        try {
            foreach ($codes as $k => $c) {
                $this->db->update('flood_zone_level', array('sort_order' => $k + 1),
                    'level_code = :w_code AND sort_order <> :w_order', array(':w_code' => $c, ':w_order' => $k + 1));
            }
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
        flood_zone_levels_reset();
        return true;
    }

    public function deleteLevel($code) {
        $this->db->delete('flood_zone_level', 'level_code = :w_code', 1, array(':w_code' => (string) $code));
        flood_zone_levels_reset();
    }

    /* ==================== ไฟล์แนบ ==================== */

    public function addAttachments($refType, $refId, $files) {
        foreach ($files as $f) {
            $this->db->insert('flood_attachment', array(
                'ref_type' => $refType,
                'ref_id' => (int) $refId,
                'file_path' => $f['file_path'],
                'orig_name' => $f['orig_name'],
                'mime' => $f['mime'],
                'size_bytes' => (int) $f['size_bytes'],
            ));
        }
    }

    public function listAttachments($refType, $refId) {
        return $this->db->select(
            "SELECT attachment_id, mime, size_bytes, created_at FROM flood_attachment
             WHERE ref_type = :t AND ref_id = :id ORDER BY attachment_id",
            array(':t' => $refType, ':id' => (int) $refId)
        );
    }

    public function getAttachment($id) {
        return $this->db->selectOne("SELECT * FROM flood_attachment WHERE attachment_id = :id", array(':id' => (int) $id));
    }

    /* ==================== ภาพประกอบพื้นที่ (บอลลูนบนแผนที่สาธารณะ) ==================== */

    /** ภาพของพื้นที่ — ใหม่สุดก่อน (ภาพแรกขึ้นเป็นภาพหน้าบอลลูน) */
    public function zonePhotos($zoneId) {
        return $this->db->select(
            "SELECT attachment_id, file_path, orig_name, size_bytes, created_at FROM flood_attachment
             WHERE ref_type = 'zone' AND ref_id = :id ORDER BY attachment_id DESC",
            array(':id' => (int) $zoneId)
        );
    }

    /** รหัสภาพของหลายพื้นที่ใน query เดียว → [zone_id => [attachment_id, ...]] */
    public function zonePhotoIds(array $zoneIds) {
        $zoneIds = array_values(array_unique(array_filter(array_map('intval', $zoneIds))));
        if (!$zoneIds) {
            return array();
        }
        $ph = array();
        $params = array();
        foreach ($zoneIds as $i => $zid) {
            $ph[] = ':z' . $i;
            $params[':z' . $i] = $zid;
        }
        $out = array();
        $rows = $this->db->select(
            "SELECT attachment_id, ref_id FROM flood_attachment
             WHERE ref_type = 'zone' AND ref_id IN (" . implode(',', $ph) . ")
             ORDER BY attachment_id DESC",
            $params
        );
        foreach ($rows as $r) {
            $out[(int) $r['ref_id']][] = (int) $r['attachment_id'];
        }
        return $out;
    }

    /** เติม photos (รหัสภาพ) ให้ข้อมูลพื้นที่ที่ผ่าน zoneForMap แล้ว */
    public function withZonePhotos(array $mapZones) {
        $ids = $this->zonePhotoIds(array_map(function ($z) { return $z['zone_id']; }, $mapZones));
        foreach ($mapZones as $i => $z) {
            $mapZones[$i]['photos'] = isset($ids[$z['zone_id']]) ? $ids[$z['zone_id']] : array();
        }
        return $mapZones;
    }

    /** ภาพหนึ่งภาพพร้อมสถานะพื้นที่ — ใช้ตอนส่งภาพทาง api */
    public function getZonePhoto($id) {
        return $this->db->selectOne(
            "SELECT a.attachment_id, a.file_path, a.mime, z.zone_id, z.status AS zone_status
             FROM flood_attachment a
             JOIN flood_zone z ON z.zone_id = a.ref_id
             WHERE a.attachment_id = :id AND a.ref_type = 'zone'",
            array(':id' => (int) $id)
        );
    }

    /**
     * รูปจากรายงานประชาชนที่เจ้าหน้าที่เลือกไปแสดงกับพื้นที่ได้
     * = รายงานที่ผูกกับพื้นที่นี้ + รายงานต้นทาง (ตอนสร้างพื้นที่จากรายงาน)
     * used > 0 = คัดลอกไปแสดงกับพื้นที่นี้แล้ว (ภาพที่คัดลอกเก็บ orig_name = report:<attachment_id>)
     */
    public function reportPhotosForZone($zoneId, $reportId = 0) {
        $cond = array();
        $params = array(':zu' => (int) $zoneId);
        if ($zoneId > 0) {
            $cond[] = 'r.zone_id = :zid';
            $params[':zid'] = (int) $zoneId;
        }
        if ($reportId > 0) {
            $cond[] = 'r.report_id = :rid';
            $params[':rid'] = (int) $reportId;
        }
        if (!$cond) {
            return array();
        }
        return $this->db->select(
            "SELECT a.attachment_id, a.file_path, a.mime, r.report_id, r.ref_code, r.created_at,
                    (SELECT COUNT(*) FROM flood_attachment u
                     WHERE u.ref_type = 'zone' AND u.ref_id = :zu AND u.orig_name = CONCAT('report:', a.attachment_id)) AS used
             FROM flood_attachment a
             JOIN flood_report r ON r.report_id = a.ref_id
             WHERE a.ref_type = 'report' AND (" . implode(' OR ', $cond) . ")
             ORDER BY r.created_at DESC, a.attachment_id
             LIMIT 60",
            $params
        );
    }

    /** ลบภาพของพื้นที่ (เฉพาะภาพที่เป็นของพื้นที่นี้) — คืน file_path ไว้ลบไฟล์หลังบันทึกสำเร็จ */
    public function removeZonePhotos($zoneId, array $ids) {
        $paths = array();
        foreach (array_unique(array_filter(array_map('intval', $ids))) as $aid) {
            $row = $this->db->selectOne(
                "SELECT file_path FROM flood_attachment WHERE attachment_id = :id AND ref_type = 'zone' AND ref_id = :z",
                array(':id' => $aid, ':z' => (int) $zoneId)
            );
            if ($row) {
                $this->db->delete('flood_attachment', 'attachment_id = :w_id', 1, array(':w_id' => $aid));
                $paths[] = $row['file_path'];
            }
        }
        return $paths;
    }

    /** ยืนยันรายงาน + ผูกกับพื้นที่ + เพิ่มภาพประกอบ (ถ้ามี) ในรายการเดียว */
    public function attachReportToZone($reportId, $zoneId, $note, $userId, $newPhotos) {
        Audit::ready($this->db);
        $this->db->beginTransaction();
        try {
            $this->reviewReport($reportId, 'verified', $zoneId, $note, $userId);
            $this->addAttachments('zone', $zoneId, $newPhotos);
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * บันทึกพื้นที่ + ภาพประกอบ + ปิดรายงานต้นทาง ในรายการเดียว (สำเร็จทั้งหมดหรือไม่บันทึกเลย)
     * $newPhotos = ไฟล์ที่เตรียมแล้ว (flood_publish_image) / $removeIds = รหัสภาพเดิมที่จะลบ
     * $fromReport = array(report_id, note) หรือ null
     * คืน array(zone_id, file_path ของภาพที่ลบ — ให้ผู้เรียกลบไฟล์ออกจากดิสก์)
     */
    public function saveZoneFull($id, $data, $userId, $newPhotos, $removeIds, $fromReport = null) {
        Audit::ready($this->db);
        $this->db->beginTransaction();
        try {
            $zoneId = $this->saveZone($id, $data, $userId);
            $removed = $this->removeZonePhotos($zoneId, $removeIds);
            $this->addAttachments('zone', $zoneId, $newPhotos);
            if ($fromReport) {
                $this->reviewReport($fromReport[0], 'verified', $zoneId, $fromReport[1], $userId);
            }
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
        return array($zoneId, $removed);
    }

    /* ==================== ฟอร์มสาธารณะ: กันสแปม + เลขอ้างอิง ==================== */

    /** จำนวนครั้งที่ส่งจาก IP นี้ใน 10 นาที และจากเบอร์นี้ใน 1 ชั่วโมง */
    public function recentSubmitCounts($table, $phoneCol, $ip, $phone) {
        $ipCount = (int) $this->db->selectValue(
            "SELECT COUNT(*) FROM $table WHERE ip = :ip AND created_at >= :t",
            array(':ip' => $ip, ':t' => date('Y-m-d H:i:s', time() - 600))
        );
        $phoneCount = (int) $this->db->selectValue(
            "SELECT COUNT(*) FROM $table WHERE $phoneCol = :p AND created_at >= :t",
            array(':p' => $phone, ':t' => date('Y-m-d H:i:s', time() - 3600))
        );
        return array($ipCount, $phoneCount);
    }

    /** เลขอ้างอิงเรียงรายวัน เช่น FR-690924-003 — นับจาก id จึงไม่ชนกันแม้ส่งพร้อมกัน */
    private function assignRefCode($table, $idCol, $prefix, $id) {
        $seq = (int) $this->db->selectValue(
            "SELECT COUNT(*) FROM $table WHERE created_at >= :d AND $idCol <= :id",
            array(':d' => date('Y-m-d 00:00:00'), ':id' => (int) $id)
        );
        $ref = $prefix . '-' . flood_ref_date_part() . '-' . str_pad((string) max(1, $seq), 3, '0', STR_PAD_LEFT);
        try {
            $this->db->update($table, array('ref_code' => $ref), "$idCol = :w_id", array(':w_id' => (int) $id));
        } catch (PDOException $e) {
            $ref = $prefix . '-' . flood_ref_date_part() . '-' . $id;
            $this->db->update($table, array('ref_code' => $ref), "$idCol = :w_id", array(':w_id' => (int) $id));
        }
        return $ref;
    }

    /* ==================== รายงานจากประชาชน ==================== */

    /**
     * คอลัมน์ flood_report.impacts (sql/09) — ยังไม่มีก็เพิ่มให้เอง
     * บัญชีฐานข้อมูลไม่มีสิทธิ์ ALTER → คืน false แล้วบันทึกรายงานต่อโดยไม่มีช่องนี้
     */
    public function reportImpactsReady() {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        try {
            $ready = (bool) $this->db->select("SHOW COLUMNS FROM flood_report LIKE 'impacts'");
            if (!$ready) {
                $this->db->exec("ALTER TABLE flood_report ADD COLUMN impacts VARCHAR(100) DEFAULT NULL
                    COMMENT 'คั่น comma: no_power,no_tap,food,water,shelter' AFTER trend");
                $ready = true;
            }
        } catch (Exception $e) {
            error_log('[flood] เพิ่มคอลัมน์ flood_report.impacts ไม่ได้ (รัน php sql/apply_schema.php 09): ' . $e->getMessage());
            $ready = false;
        }
        return $ready;
    }

    public function createReport($data, $photos) {
        if (array_key_exists('impacts', $data) && !$this->reportImpactsReady()) {
            unset($data['impacts']);
        }
        Audit::ready($this->db);
        $this->db->beginTransaction();
        try {
            $data['ref_code'] = 'TMP-' . bin2hex(random_bytes(6));
            $id = $this->db->insert('flood_report', $data);
            $ref = $this->assignRefCode('flood_report', 'report_id', 'FR', $id);
            $this->addAttachments('report', $id, $photos);
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
        return array('id' => $id, 'ref' => $ref);
    }

    public function listReports($filters, $limit = 50, $page = 1) {
        $where = array('1=1');
        $params = array();
        $status = isset($filters['status']) ? $filters['status'] : 'pending';
        if ($status === 'waiting') {
            // ยืนยันแล้วแต่ยังไม่ขึ้นแผนที่: ไม่ได้ผูกพื้นที่ หรือพื้นที่ที่ผูกไว้ปิดประกาศแล้ว
            $where[] = "r.status = 'verified' AND (z.zone_id IS NULL OR z.status <> 'active')";
        } elseif ($status === 'announced') {
            $where[] = "r.status = 'verified' AND z.status = 'active'";
        } elseif ($status !== '' && $status !== 'all' && array_key_exists($status, flood_report_statuses())) {
            $where[] = 'r.status = :st';
            $params[':st'] = $status;
        }
        if (!empty($filters['q'])) {
            $where[] = '(r.ref_code LIKE :q OR r.reporter_name LIKE :q OR r.reporter_phone LIKE :q OR r.place_note LIKE :q)';
            $params[':q'] = '%' . $filters['q'] . '%';
        }
        if (!empty($filters['impact']) && array_key_exists($filters['impact'], flood_area_impacts()) && $this->reportImpactsReady()) {
            $where[] = 'FIND_IN_SET(:im, r.impacts) > 0';
            $params[':im'] = $filters['impact'];
        }
        if (!empty($filters['province']) && ($box = $this->provinceBox($filters['province']))) {
            $where[] = 'r.lat BETWEEN :bs AND :bn AND r.lng BETWEEN :bw AND :be';
            $params[':bs'] = $box[0];
            $params[':bn'] = $box[1];
            $params[':bw'] = $box[2];
            $params[':be'] = $box[3];
        }
        if (!empty($filters['days'])) {
            $where[] = 'r.created_at >= :from';
            $params[':from'] = date('Y-m-d H:i:s', time() - (int) $filters['days'] * 86400);
        }
        $result = $this->paginate(
            "SELECT r.*, z.name AS zone_name, z.level AS zone_level, z.status AS zone_status, u.name AS reviewed_by_name,
                    (SELECT COUNT(*) FROM flood_attachment a WHERE a.ref_type = 'report' AND a.ref_id = r.report_id) AS photo_count
             FROM flood_report r
             LEFT JOIN flood_zone z ON z.zone_id = r.zone_id
             LEFT JOIN flood_user u ON u.user_id = r.reviewed_by
             WHERE " . implode(' AND ', $where),
            "ORDER BY r.created_at DESC", $params, $limit, $page);
        // บอกว่ารายงานนี้อยู่ในพื้นที่ที่ประกาศแล้วหรือยัง — เจ้าหน้าที่จะได้ไม่ประกาศซ้ำ
        $zones = $this->activeZones();
        foreach ($result['rows'] as $i => $r) {
            $inside = $this->zonesContaining($r['lat'], $r['lng'], $zones);
            $result['rows'][$i]['inside_zone'] = $inside ? array('zone_id' => (int) $inside[0]['zone_id'],
                'name' => $inside[0]['name'], 'level' => $inside[0]['level']) : null;
            $result['rows'][$i]['view_status'] = flood_report_view_status($r);
        }
        return $result;
    }

    public function getReport($id) {
        $r = $this->db->selectOne(
            "SELECT r.*, z.name AS zone_name, z.level AS zone_level, z.status AS zone_status, u.name AS reviewed_by_name
             FROM flood_report r
             LEFT JOIN flood_zone z ON z.zone_id = r.zone_id
             LEFT JOIN flood_user u ON u.user_id = r.reviewed_by
             WHERE r.report_id = :id",
            array(':id' => (int) $id)
        );
        if ($r) {
            $r['photos'] = $this->listAttachments('report', $r['report_id']);
        }
        return $r;
    }

    public function reportCounts() {
        $out = array('pending' => 0, 'verified' => 0, 'rejected' => 0, 'waiting' => 0, 'announced' => 0, 'today' => 0);
        foreach ($this->db->select("SELECT status, COUNT(*) AS c FROM flood_report GROUP BY status") as $r) {
            if (isset($out[$r['status']])) {
                $out[$r['status']] = (int) $r['c'];
            }
        }
        // verified แยกเป็น ประกาศแล้ว (พื้นที่ยังประกาศอยู่) / รอประกาศ (ที่เหลือ)
        $out['announced'] = (int) $this->db->selectValue(
            "SELECT COUNT(*) FROM flood_report r JOIN flood_zone z ON z.zone_id = r.zone_id
             WHERE r.status = 'verified' AND z.status = 'active'");
        $out['waiting'] = max(0, $out['verified'] - $out['announced']);
        $out['today'] = (int) $this->db->selectValue("SELECT COUNT(*) FROM flood_report WHERE created_at >= :d",
            array(':d' => date('Y-m-d 00:00:00')));
        return $out;
    }

    public function reviewReport($id, $status, $zoneId, $note, $userId) {
        $this->db->update('flood_report', array(
            'status' => $status,
            'zone_id' => $zoneId ? (int) $zoneId : null,
            'review_note' => $note !== '' ? mb_substr($note, 0, 255) : null,
            'reviewed_by' => $userId,
            'reviewed_at' => date('Y-m-d H:i:s'),
        ), 'report_id = :w_id', array(':w_id' => (int) $id));
    }

    public function pendingReportsForMap($limit = 300) {
        $rows = $this->db->select(
            "SELECT report_id, ref_code, lat, lng, depth, extent, vehicle, trend, created_at
             FROM flood_report WHERE status = 'pending' ORDER BY created_at DESC LIMIT " . (int) $limit
        );
        $depth = flood_depth_options();
        foreach ($rows as $i => $r) {
            $rows[$i]['depth_name'] = flood_opt_name($depth, $r['depth']);
            $rows[$i]['ago'] = flood_ago($r['created_at']);
        }
        return $rows;
    }

    /* ==================== คำขอความช่วยเหลือ / ใบงาน ==================== */

    public function createHelp($data, $photos, $user = null) {
        Audit::ready($this->db);
        $this->db->beginTransaction();
        try {
            $data['ref_code'] = 'TMP-' . bin2hex(random_bytes(6));
            $id = $this->db->insert('flood_help', $data);
            $ref = $this->assignRefCode('flood_help', 'help_id', 'SOS', $id);
            $this->addAttachments('help', $id, $photos);
            $this->addHelpLog($id, 'create', null, 'new', null,
                $data['source'] === 'phone' ? 'เจ้าหน้าที่รับเรื่องทางโทรศัพท์'
                    : ($data['source'] === 'facebook' ? 'นำเข้าจากโพสต์ Facebook (ต้องยืนยันก่อน)' : 'ประชาชนส่งคำขอผ่านเว็บ'), $user);
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
        return array('id' => $id, 'ref' => $ref);
    }

    /**
     * ตรวจและแปลงข้อมูลฟอร์ม — ใช้ร่วมกับหน้ารับเรื่องทางโทรศัพท์ของเจ้าหน้าที่ (Flood::saveHelp)
     * คืน array ข้อมูลพร้อมบันทึก หรือข้อความผิดพลาด (string)
     */
    public function parseHelpInput($src) {
        $model = $this;
        $needs = flood_codes_filter(isset($src['needs']) ? $src['needs'] : array(), flood_help_needs());
        if (!$needs) {
            return 'กรุณาเลือกว่าต้องการความช่วยเหลือเรื่องอะไร';
        }
        $flags = flood_codes_filter(isset($src['flags']) ? $src['flags'] : array(), flood_help_flags());
        $lat = flood_in('lat', '', $src);
        $lng = flood_in('lng', '', $src);
        $hasLoc = flood_valid_latlng($lat, $lng);
        $address = mb_substr(flood_in('address', '', $src), 0, 255);
        if (!$hasLoc && mb_strlen($address) < 5) {
            return 'กรุณาระบุตำแหน่งบนแผนที่ หรือพิมพ์ที่อยู่/จุดสังเกต';
        }
        $amphoe = flood_in('amphoe_code', '', $src);
        if ($amphoe !== '' && !$model->amphoeExists($amphoe)) {
            $amphoe = '';
        }
        $tambon = $model->validTambon(flood_in('tambon_code', '', $src), $amphoe);
        if ($tambon && $amphoe === '') {
            $amphoe = substr($tambon, 0, 4);   // รหัสตำบล 6 หลักขึ้นต้นด้วยรหัสอำเภอ
        }
        if ($amphoe === '' && $hasLoc) {
            // ไม่ได้เลือกอำเภอ — เดาจากพิกัด (ตำบลที่จุดกึ่งกลางใกล้ที่สุด)
            $g = $model->guessArea($lat, $lng);
            if ($g) {
                $amphoe = $g['amphoe_code'];
                $tambon = $g['tambon_code'];
            }
        }
        $name = mb_substr(flood_in('requester_name', '', $src), 0, 150);
        if (mb_strlen($name) < 2) {
            return 'กรุณากรอกชื่อผู้แจ้ง';
        }
        $phone = flood_normalize_phone(flood_in('requester_phone', '', $src));
        if (!flood_valid_phone($phone)) {
            return 'กรุณากรอกเบอร์โทรที่ติดต่อได้จริง (9–10 หลัก) เจ้าหน้าที่จะโทรกลับยืนยัน';
        }
        $people = flood_in('people_count', '', $src);
        $accuracy = flood_in('accuracy', '', $src);
        return array(
            'needs' => implode(',', $needs),
            'vulnerable_flags' => $flags ? implode(',', $flags) : null,
            'detail' => mb_substr(flood_in('detail', '', $src), 0, 2000) ?: null,
            'people_count' => ctype_digit($people) ? min(999, (int) $people) : null,
            'lat' => $hasLoc ? round((float) $lat, 7) : null,
            'lng' => $hasLoc ? round((float) $lng, 7) : null,
            'accuracy_m' => $hasLoc && is_numeric($accuracy) ? min(100000, max(0, (int) round($accuracy))) : null,
            'address' => $address !== '' ? $address : null,
            'amphoe_code' => $amphoe !== '' ? $amphoe : null,
            'tambon_code' => $tambon,
            'requester_name' => $name,
            'requester_phone' => $phone,
            'priority' => flood_help_priority($needs, $flags),
        );
    }

    public function addHelpLog($helpId, $action, $from, $to, $teamId, $note, $user) {
        $this->db->insert('flood_help_log', array(
            'help_id' => (int) $helpId,
            'action' => $action,
            'status_from' => $from,
            'status_to' => $to,
            'team_id' => $teamId ? (int) $teamId : null,
            'note' => $note !== null && $note !== '' ? $note : null,
            'user_id' => !empty($user['user_id']) ? (int) $user['user_id'] : null,
            'user_name' => !empty($user['name']) ? mb_substr($user['name'], 0, 150) : '',
        ));
    }

    public function listHelp($filters, $limit = 50, $page = 1) {
        $where = array('1=1');
        $params = array();
        $status = isset($filters['status']) ? $filters['status'] : 'open';
        if ($status === 'open') {
            $where[] = "h.status IN ('new','verified','assigned','in_progress')";
        } elseif ($status === 'waiting') {
            $where[] = "h.status IN ('new','verified')";
        } elseif ($status !== 'all' && array_key_exists($status, flood_help_statuses())) {
            $where[] = 'h.status = :st';
            $params[':st'] = $status;
        }
        if (!empty($filters['priority']) && array_key_exists($filters['priority'], flood_priorities())) {
            $where[] = 'h.priority = :pr';
            $params[':pr'] = $filters['priority'];
        }
        if (!empty($filters['amphoe'])) {
            $where[] = 'h.amphoe_code = :am';
            $params[':am'] = $filters['amphoe'];
        }
        if (!empty($filters['province'])) {
            $where[] = 'h.amphoe_code LIKE :pv';
            $params[':pv'] = $filters['province'] . '%';
        }
        if (!empty($filters['need']) && array_key_exists($filters['need'], flood_help_needs())) {
            $where[] = 'FIND_IN_SET(:nd, h.needs) > 0';
            $params[':nd'] = $filters['need'];
        }
        if (!empty($filters['team_id'])) {
            $where[] = 'h.team_id = :tm';
            $params[':tm'] = (int) $filters['team_id'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(h.ref_code LIKE :q OR h.requester_name LIKE :q OR h.requester_phone LIKE :q OR h.address LIKE :q OR h.detail LIKE :q)';
            $params[':q'] = '%' . $filters['q'] . '%';
        }
        $result = $this->paginate(
            "SELECT h.*, t.name AS team_name, a.name AS amphoe_name, tb.name AS tambon_name,
                    (SELECT COUNT(*) FROM flood_attachment x WHERE x.ref_type = 'help' AND x.ref_id = h.help_id) AS photo_count
             FROM flood_help h
             LEFT JOIN flood_team t ON t.team_id = h.team_id
             LEFT JOIN flood_amphoe a ON a.amphoe_code = h.amphoe_code
             LEFT JOIN flood_tambon tb ON tb.tambon_code = h.tambon_code
             WHERE " . implode(' AND ', $where),
            "ORDER BY FIELD(h.status,'new','verified','assigned','in_progress','done','cancelled'),
                      FIELD(h.priority,'urgent','high','normal'), h.created_at ASC",
            $params, $limit, $page);
        return $result;
    }

    public function getHelp($id) {
        $h = $this->db->selectOne(
            "SELECT h.*, t.name AS team_name, t.phone AS team_phone, a.name AS amphoe_name, tb.name AS tambon_name,
                    vu.name AS verified_by_name, cu.name AS created_by_name
             FROM flood_help h
             LEFT JOIN flood_team t ON t.team_id = h.team_id
             LEFT JOIN flood_amphoe a ON a.amphoe_code = h.amphoe_code
             LEFT JOIN flood_tambon tb ON tb.tambon_code = h.tambon_code
             LEFT JOIN flood_user vu ON vu.user_id = h.verified_by
             LEFT JOIN flood_user cu ON cu.user_id = h.created_by
             WHERE h.help_id = :id",
            array(':id' => (int) $id)
        );
        if ($h) {
            $h['photos'] = $this->listAttachments('help', $h['help_id']);
            $h['logs'] = $this->db->select(
                "SELECT l.*, t.name AS team_name FROM flood_help_log l
                 LEFT JOIN flood_team t ON t.team_id = l.team_id
                 WHERE l.help_id = :id ORDER BY l.log_id DESC",
                array(':id' => (int) $h['help_id'])
            );
        }
        return $h;
    }

    public function updateHelp($id, $data) {
        $data['updated_at'] = date('Y-m-d H:i:s');
        $this->db->update('flood_help', $data, 'help_id = :w_id', array(':w_id' => (int) $id));
    }

    /** คำขอเก่าจากเบอร์เดียวกัน (ช่วยให้รู้ว่าเป็นเรื่องซ้ำ) */
    public function helpByPhone($phone, $exceptId = 0) {
        return $this->db->select(
            "SELECT help_id, ref_code, status, priority, needs, created_at FROM flood_help
             WHERE requester_phone = :p AND help_id <> :id ORDER BY created_at DESC LIMIT 10",
            array(':p' => $phone, ':id' => (int) $exceptId)
        );
    }

    public function helpCounts($teamId = null) {
        $where = $teamId ? 'WHERE team_id = ' . (int) $teamId : '';
        $out = array('new' => 0, 'verified' => 0, 'assigned' => 0, 'in_progress' => 0, 'done' => 0, 'cancelled' => 0,
            'open' => 0, 'waiting' => 0, 'urgent_open' => 0, 'done_today' => 0, 'today' => 0);
        foreach ($this->db->select("SELECT status, COUNT(*) AS c FROM flood_help $where GROUP BY status") as $r) {
            if (isset($out[$r['status']])) {
                $out[$r['status']] = (int) $r['c'];
            }
        }
        $out['open'] = $out['new'] + $out['verified'] + $out['assigned'] + $out['in_progress'];
        $out['waiting'] = $out['new'] + $out['verified'];
        $and = $teamId ? 'AND team_id = ' . (int) $teamId : '';
        $out['urgent_open'] = (int) $this->db->selectValue(
            "SELECT COUNT(*) FROM flood_help WHERE priority = 'urgent'
               AND status IN ('new','verified','assigned','in_progress') $and");
        $out['done_today'] = (int) $this->db->selectValue(
            "SELECT COUNT(*) FROM flood_help WHERE status = 'done' AND done_at >= :d $and",
            array(':d' => date('Y-m-d 00:00:00')));
        $out['today'] = (int) $this->db->selectValue(
            "SELECT COUNT(*) FROM flood_help WHERE created_at >= :d $and",
            array(':d' => date('Y-m-d 00:00:00')));
        return $out;
    }

    public function openHelpForMap($teamId = null) {
        $params = array();
        $and = '';
        if ($teamId) {
            $and = 'AND h.team_id = :tm';
            $params[':tm'] = (int) $teamId;
        }
        $rows = $this->db->select(
            "SELECT h.help_id, h.ref_code, h.lat, h.lng, h.priority, h.status, h.needs, h.people_count,
                    h.vulnerable_flags, h.created_at, t.name AS team_name
             FROM flood_help h LEFT JOIN flood_team t ON t.team_id = h.team_id
             WHERE h.status IN ('new','verified','assigned','in_progress') AND h.lat IS NOT NULL $and
             ORDER BY h.created_at DESC LIMIT 500",
            $params
        );
        $needs = flood_help_needs();
        $st = flood_help_statuses();
        foreach ($rows as $i => $r) {
            $rows[$i]['needs_names'] = flood_codes_names($r['needs'], $needs);
            $rows[$i]['status_name'] = $st[$r['status']]['name'];
            $rows[$i]['ago'] = flood_ago($r['created_at']);
        }
        return $rows;
    }

    /** ประชาชนตรวจสถานะคำขอของตัวเอง — ต้องรู้ทั้งเลขอ้างอิงและเบอร์โทร */
    public function publicHelpStatus($ref, $phone) {
        $h = $this->db->selectOne(
            "SELECT h.help_id, h.ref_code, h.status, h.needs, h.created_at, h.updated_at, h.assigned_at, h.done_at,
                    t.name AS team_name, t.phone AS team_phone
             FROM flood_help h LEFT JOIN flood_team t ON t.team_id = h.team_id
             WHERE h.ref_code = :r AND h.requester_phone = :p",
            array(':r' => $ref, ':p' => $phone)
        );
        if (!$h) {
            return null;
        }
        $h['timeline'] = $this->db->select(
            "SELECT action, status_to, created_at FROM flood_help_log
             WHERE help_id = :id AND action IN ('create','verify','assign','start','done','cancel','reopen')
             ORDER BY log_id",
            array(':id' => (int) $h['help_id'])
        );
        unset($h['help_id']);
        return $h;
    }

    /* ==================== ทะเบียนกลุ่มเปราะบาง ==================== */

    public function listVulnerable($filters, $limit = 50, $page = 1) {
        $where = array('v.is_active = 1');
        $params = array();
        if (!empty($filters['q'])) {
            $where[] = '(v.name LIKE :q OR v.hn = :qe OR v.phone LIKE :q OR v.caregiver_phone LIKE :q OR v.address LIKE :q)';
            $params[':q'] = '%' . $filters['q'] . '%';
            $params[':qe'] = $filters['q'];
        }
        if (!empty($filters['amphoe'])) {
            $where[] = 'v.amphoe_code = :am';
            $params[':am'] = $filters['amphoe'];
        }
        if (!empty($filters['province'])) {
            $where[] = 'v.amphoe_code LIKE :pv';
            $params[':pv'] = $filters['province'] . '%';
        }
        if (!empty($filters['tambon'])) {
            $where[] = 'v.tambon_code = :tb';
            $params[':tb'] = $filters['tambon'];
        }
        if (!empty($filters['group']) && array_key_exists($filters['group'], flood_vulnerable_groups())) {
            $where[] = 'FIND_IN_SET(:gr, v.vuln_groups) > 0';
            $params[':gr'] = $filters['group'];
        }
        if (!empty($filters['evac']) && array_key_exists($filters['evac'], flood_evac_statuses())) {
            $where[] = 'v.evac_status = :ev';
            $params[':ev'] = $filters['evac'];
        }
        if (!empty($filters['no_location'])) {
            $where[] = 'v.lat IS NULL';
        }
        $rows = $this->db->select(
            "SELECT v.*, a.name AS amphoe_name, t.name AS tambon_name
             FROM flood_vulnerable v
             LEFT JOIN flood_amphoe a ON a.amphoe_code = v.amphoe_code
             LEFT JOIN flood_tambon t ON t.tambon_code = v.tambon_code
             WHERE " . implode(' AND ', $where) . "
             ORDER BY v.name
             LIMIT 20000",
            $params
        );
        // เทียบพิกัดกับพื้นที่ประกาศทุกแถว แล้วค่อยกรอง/เรียง/แบ่งหน้าใน PHP
        $zones = $this->activeZones();
        $levelOrder = flood_zone_levels(true);
        foreach ($rows as $i => $r) {
            $rows[$i]['zone'] = null;
            if ($r['lat'] !== null) {
                $in = $this->zonesContaining($r['lat'], $r['lng'], $zones);
                if ($in) {
                    $rows[$i]['zone'] = array('zone_id' => (int) $in[0]['zone_id'], 'name' => $in[0]['name'], 'level' => $in[0]['level']);
                }
            }
            $rows[$i]['age'] = flood_age_years($r['birth_date']);
        }
        if (!empty($filters['in_zone'])) {
            $rows = array_values(array_filter($rows, function ($r) {
                return $r['zone'] !== null;
            }));
        }
        usort($rows, function ($a, $b) use ($levelOrder) {
            // คนในพื้นที่ประกาศที่ยังไม่อพยพขึ้นก่อน
            $ra = $a['zone'] ? (isset($levelOrder[$a['zone']['level']]) ? $levelOrder[$a['zone']['level']]['order'] : 998) : 999;
            $rb = $b['zone'] ? (isset($levelOrder[$b['zone']['level']]) ? $levelOrder[$b['zone']['level']]['order'] : 998) : 999;
            if ($ra !== $rb) {
                return $ra - $rb;
            }
            $ea = in_array($a['evac_status'], array('normal', 'alerted'), true) ? 0 : 1;
            $eb = in_array($b['evac_status'], array('normal', 'alerted'), true) ? 0 : 1;
            if ($ea !== $eb) {
                return $ea - $eb;
            }
            return strcmp($a['name'], $b['name']);
        });
        $total = count($rows);
        $limit = max(1, (int) $limit);
        $pages = max(1, (int) ceil($total / $limit));
        $page = min(max(1, (int) $page), $pages);
        return array(
            'rows' => array_slice($rows, ($page - 1) * $limit, $limit),
            'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $limit,
        );
    }

    public function getVulnerable($id) {
        $v = $this->db->selectOne(
            "SELECT v.*, a.name AS amphoe_name, t.name AS tambon_name
             FROM flood_vulnerable v
             LEFT JOIN flood_amphoe a ON a.amphoe_code = v.amphoe_code
             LEFT JOIN flood_tambon t ON t.tambon_code = v.tambon_code
             WHERE v.person_id = :id",
            array(':id' => (int) $id)
        );
        if ($v) {
            $v['logs'] = $this->db->select(
                "SELECT * FROM flood_vulnerable_log WHERE person_id = :id ORDER BY log_id DESC LIMIT 50",
                array(':id' => (int) $id)
            );
            $v['zone'] = null;
            if ($v['lat'] !== null) {
                $in = $this->zonesContaining($v['lat'], $v['lng']);
                if ($in) {
                    $v['zone'] = array('zone_id' => (int) $in[0]['zone_id'], 'name' => $in[0]['name'], 'level' => $in[0]['level']);
                }
            }
        }
        return $v;
    }

    public function saveVulnerable($id, $data, $userId) {
        if ($id > 0) {
            $data['updated_at'] = date('Y-m-d H:i:s');
            $this->db->update('flood_vulnerable', $data, 'person_id = :w_id', array(':w_id' => (int) $id));
            return (int) $id;
        }
        $data['created_by'] = $userId;
        return $this->db->insert('flood_vulnerable', $data);
    }

    public function setVulnerableStatus($id, $status, $place, $note, $user) {
        $now = date('Y-m-d H:i:s');
        $this->db->update('flood_vulnerable', array(
            'evac_status' => $status,
            'evac_place' => $place !== '' ? mb_substr($place, 0, 200) : null,
            'last_checked_at' => $now,
            'updated_at' => $now,
        ), 'person_id = :w_id', array(':w_id' => (int) $id));
        $this->db->insert('flood_vulnerable_log', array(
            'person_id' => (int) $id,
            'evac_status' => $status,
            'evac_place' => $place !== '' ? mb_substr($place, 0, 200) : null,
            'note' => $note !== '' ? $note : null,
            'user_id' => !empty($user['user_id']) ? (int) $user['user_id'] : null,
            'user_name' => !empty($user['name']) ? mb_substr($user['name'], 0, 150) : '',
        ));
    }

    /** คนในทะเบียนที่อยู่ในพื้นที่ประกาศตอนนี้ — ใช้ทำการ์ดเตือนและหมุดบนแผนที่ */
    public function vulnerableInZones() {
        $zones = $this->activeZones();
        if (!$zones) {
            return array();
        }
        $rows = $this->db->select(
            "SELECT person_id, name, vuln_groups, mobility, lat, lng, evac_status, phone, caregiver_phone
             FROM flood_vulnerable WHERE is_active = 1 AND lat IS NOT NULL"
        );
        $out = array();
        $groups = flood_vulnerable_groups();
        foreach ($rows as $r) {
            $in = $this->zonesContaining($r['lat'], $r['lng'], $zones);
            if ($in) {
                $r['zone_name'] = $in[0]['name'];
                $r['zone_level'] = $in[0]['level'];
                $r['group_names'] = flood_codes_names($r['vuln_groups'], $groups);
                $out[] = $r;
            }
        }
        return $out;
    }

    public function vulnerableSummary($inZones = null) {
        $inZones = $inZones === null ? $this->vulnerableInZones() : $inZones;
        $waiting = 0;
        foreach ($inZones as $r) {
            if (in_array($r['evac_status'], array('normal', 'alerted'), true)) {
                $waiting++;
            }
        }
        return array(
            'total' => (int) $this->db->selectValue("SELECT COUNT(*) FROM flood_vulnerable WHERE is_active = 1"),
            'no_location' => (int) $this->db->selectValue("SELECT COUNT(*) FROM flood_vulnerable WHERE is_active = 1 AND lat IS NULL"),
            'in_zone' => count($inZones),
            'in_zone_waiting' => $waiting,
        );
    }

    /** ใครในทะเบียนใช้เบอร์นี้ (ตัวเองหรือผู้ดูแล) — ช่วยจับคู่คำขอความช่วยเหลือกับทะเบียน */
    public function vulnerableByPhone($phone) {
        if ($phone === '') {
            return array();
        }
        return $this->db->select(
            "SELECT person_id, name, vuln_groups, mobility, medical_needs, evac_status
             FROM flood_vulnerable WHERE is_active = 1 AND (phone = :p OR caregiver_phone = :p) LIMIT 5",
            array(':p' => $phone)
        );
    }

    /* ==================== ภาพรวม ==================== */

    public function recentHelp($teamId = null, $limit = 8) {
        $f = array('status' => 'open');
        if ($teamId) {
            $f['team_id'] = $teamId;
        }
        $r = $this->listHelp($f, $limit, 1);
        return $r['rows'];
    }

}
