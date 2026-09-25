<?php

/**
 * Audit — ประวัติการเข้าใช้งาน และประวัติการเพิ่ม/แก้ไข/ลบข้อมูล
 * (แนวเดียวกับ libs/Audit.php ของระบบ COC)
 *
 * ดักที่ชั้น Database (insert/update/delete) จุดเดียว จึงครอบทุกตารางอัตโนมัติ
 * โดยไม่ต้องไล่แก้ทุก model — ตารางที่เพิ่มใหม่ในอนาคตก็ถูกบันทึกเองตั้งแต่แรก
 *
 * หลักที่ยึดไว้:
 *   1. ห้ามทำให้ระบบหลักพัง — ทุกอย่างอยู่ใน try/catch ถ้าเขียน log ไม่ได้ก็เงียบไป
 *      และหยุดพยายามทั้ง request (self::$failed) จะได้ไม่ช้าเพราะ error ซ้ำ ๆ
 *   2. ห้ามเรียกตัวเองซ้ำ — เขียน log ด้วย PDO ตรง ๆ ไม่ผ่าน Database::insert()
 *   3. ห้ามเก็บความลับ — คอลัมน์รหัสผ่าน/กุญแจ/token ถูกแทนด้วย *** ก่อนเขียน
 */
class Audit {

    /** ตารางที่ไม่บันทึก — ตาราง log เอง และตารางอ้างอิงที่ไม่ใช่ข้อมูลที่คนแก้ */
    private static $skipTables = array(
        'flood_audit_log',
        'flood_login_log',
        'flood_amphoe',
        'flood_tambon',
        'flood_help_log',        // เป็นไทม์ไลน์ของใบงานอยู่แล้ว
        'flood_vulnerable_log',  // เป็นประวัติการติดตามอยู่แล้ว
    );

    /**
     * คอลัมน์ที่เปลี่ยนเองตามการใช้งาน ไม่ใช่การแก้ข้อมูล
     * ถ้า UPDATE แตะแค่คอลัมน์พวกนี้ จะไม่บันทึก — กัน flood_user ถูกเขียน log ทุกนาที
     */
    private static $noiseCols = array(
        'last_seen_at', 'last_ip', 'last_login_at', 'last_page', 'updated_at',
    );

    /** คอลัมน์ที่ค่าห้ามโผล่ใน log */
    private static $secretCols = array(
        'password_hash', 'password', 'secret_key', 'access_token', 'refresh_token', 'api_key',
    );

    const MAX_ROWS = 20;
    const MAX_VALUE_LEN = 2000;
    const MAX_JSON_LEN = 200000;
    const MAX_CASCADE_ROWS = 200;

    private static $enabled = null;
    private static $suspend = 0;
    private static $depth = 0;
    private static $failed = false;
    private static $schemaOk = null;
    private static $actor = null;
    private static $route = null;
    private static $pkCache = array();
    private static $cascadeCache = array();

    /* ==================== สวิตช์ ==================== */

    public static function enabled() {
        if (self::$enabled === null) {
            $default = (php_sapi_name() !== 'cli');
            self::$enabled = defined('AUDIT_LOG_ENABLED') ? (bool) AUDIT_LOG_ENABLED : $default;
        }
        return self::$enabled && !self::$failed && self::$suspend === 0 && self::$depth === 0;
    }

    public static function enable() { self::$enabled = true; }

    public static function disable() { self::$enabled = false; }

    public static function suspend() { self::$suspend++; }

    public static function resume() { if (self::$suspend > 0) { self::$suspend--; } }

    /* ==================== บริบทของคนที่สั่ง ==================== */

    public static function actor() {
        if (self::$actor === null) {
            $u = isset($_SESSION['User_FLOOD']) && is_array($_SESSION['User_FLOOD']) ? $_SESSION['User_FLOOD'] : array();
            self::setActor($u);
        }
        return self::$actor;
    }

    public static function setActor($actor) {
        self::$actor = array(
            'user_id' => !empty($actor['user_id']) ? (int) $actor['user_id'] : null,
            'loginname' => isset($actor['loginname']) ? (string) $actor['loginname'] : '',
            'name' => isset($actor['name']) ? (string) $actor['name'] : '',
            'role' => isset($actor['role']) ? (string) $actor['role'] : '',
            'team_id' => !empty($actor['team_id']) ? (int) $actor['team_id'] : null,
        );
    }

    public static function route() {
        if (self::$route === null) {
            if (php_sapi_name() === 'cli') {
                global $argv;
                self::$route = 'cli:' . basename(isset($argv[0]) ? $argv[0] : 'php');
            } else {
                $u = isset($_GET['url']) ? (string) $_GET['url'] : '';
                self::$route = substr(trim($u, '/'), 0, 120);
            }
        }
        return self::$route;
    }

    public static function ip() {
        return isset($_SERVER['REMOTE_ADDR']) ? substr((string) $_SERVER['REMOTE_ADDR'], 0, 45) : '';
    }

    public static function userAgent() {
        return isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : '';
    }

    /** hash ของ session id — ใช้จับคู่ login กับ logout โดยไม่เก็บ session id จริง */
    public static function sessionKey() {
        $sid = session_id();
        return $sid ? substr(md5($sid), 0, 32) : '';
    }

    /* ==================== ตัวกรอง ==================== */

    public static function normalizeTable($table) {
        $t = str_replace('`', '', (string) $table);
        $pos = strrpos($t, '.');
        if ($pos !== false) {
            $t = substr($t, $pos + 1);
        }
        return trim($t);
    }

    public static function isAudited($table) {
        $t = self::normalizeTable($table);
        if ($t === '') {
            return false;
        }
        $skip = self::$skipTables;
        if (defined('AUDIT_SKIP_TABLES') && AUDIT_SKIP_TABLES !== '') {
            foreach (explode(',', AUDIT_SKIP_TABLES) as $extra) {
                $extra = trim($extra);
                if ($extra !== '') {
                    $skip[] = $extra;
                }
            }
        }
        return !in_array($t, $skip, true);
    }

    /* ==================== จุดเชื่อมกับ Database ==================== */

    /** อ่านแถวก่อนถูกแก้/ลบ — คืน null เมื่อไม่ต้องบันทึก */
    public static function before($db, $table, $where, $limit = 0, $params = array()) {
        if (!self::enabled() || !self::isAudited($table)) {
            return null;
        }
        $t = self::normalizeTable($table);
        $cap = self::MAX_ROWS + 1;
        if ($limit > 0 && $limit < $cap) {
            $cap = (int) $limit;
        }
        self::$depth++;
        try {
            $sth = $db->prepare("SELECT * FROM `{$t}` WHERE {$where} LIMIT {$cap}");
            foreach ((array) $params as $k => $v) {
                $sth->bindValue($k, $v);
            }
            $sth->execute();
            $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $rows = null;
        }
        self::$depth--;
        return $rows;
    }

    /** สำหรับ DELETE — เก็บทั้งแถวแม่และลูกที่ผูก ON DELETE CASCADE "ก่อน" ลบ */
    public static function beforeDelete($db, $table, $where, $limit = 0, $params = array()) {
        $rows = self::before($db, $table, $where, $limit, $params);
        if (!is_array($rows) || !$rows) {
            return $rows;
        }
        if (count($rows) > self::MAX_ROWS) {
            return $rows;
        }
        $t = self::normalizeTable($table);
        $out = array();
        foreach ($rows as $row) {
            $out[] = array('row' => $row, 'cascade' => self::cascadeSnapshot($db, $t, $row));
        }
        return $out;
    }

    public static function afterInsert($db, $table, $data, $insertId) {
        if (!self::enabled() || !self::isAudited($table)) {
            return;
        }
        $t = self::normalizeTable($table);
        $pk = $insertId > 0 ? (string) $insertId : self::pkFromRow($db, $t, $data);
        self::write($db, array(
            'table_name' => $t,
            'row_pk' => $pk,
            'action' => 'insert',
            'changed_cols' => '',
            'before_json' => null,
            'after_json' => self::encode(self::redact($data)),
            'row_count' => 1,
        ));
    }

    public static function afterUpdate($db, $table, $data, $where, $beforeRows) {
        if (!self::enabled() || !self::isAudited($table) || !is_array($beforeRows) || !$beforeRows) {
            return;
        }
        $t = self::normalizeTable($table);

        if (count($beforeRows) > self::MAX_ROWS) {
            self::write($db, array(
                'table_name' => $t,
                'row_pk' => '',
                'action' => 'update',
                'changed_cols' => implode(',', array_keys($data)),
                'before_json' => self::encode(array(
                    '_note' => 'แก้ไขหลายแถวพร้อมกัน — เกิน ' . self::MAX_ROWS . ' แถว จึงเก็บเป็นสรุป',
                    '_where' => $where,
                )),
                'after_json' => self::encode(self::redact($data)),
                'row_count' => self::MAX_ROWS,
            ));
            return;
        }

        foreach ($beforeRows as $row) {
            $before = array();
            $after = array();
            foreach ($data as $col => $newVal) {
                $oldVal = array_key_exists($col, $row) ? $row[$col] : null;
                if (self::sameValue($oldVal, $newVal)) {
                    continue;
                }
                $before[$col] = $oldVal;
                $after[$col] = $newVal;
            }
            if (!$before) {
                continue;
            }
            $cols = array_keys($before);
            if (!array_diff($cols, self::$noiseCols)) {
                continue;
            }
            self::write($db, array(
                'table_name' => $t,
                'row_pk' => self::pkFromRow($db, $t, $row),
                'action' => 'update',
                'changed_cols' => substr(implode(',', $cols), 0, 1000),
                'before_json' => self::encode(self::redact($before)),
                'after_json' => self::encode(self::redact($after)),
                'row_count' => 1,
            ));
        }
    }

    public static function afterDelete($db, $table, $where, $beforeRows) {
        if (!self::enabled() || !self::isAudited($table) || !is_array($beforeRows) || !$beforeRows) {
            return;
        }
        $t = self::normalizeTable($table);

        if (count($beforeRows) > self::MAX_ROWS) {
            self::write($db, array(
                'table_name' => $t,
                'row_pk' => '',
                'action' => 'delete',
                'changed_cols' => '',
                'before_json' => self::encode(array(
                    '_note' => 'ลบหลายแถวพร้อมกัน — เกิน ' . self::MAX_ROWS . ' แถว จึงเก็บเป็นสรุป',
                    '_where' => $where,
                )),
                'after_json' => null,
                'row_count' => self::MAX_ROWS,
            ));
            return;
        }

        foreach ($beforeRows as $entry) {
            $row = isset($entry['row']) && is_array($entry['row']) ? $entry['row'] : $entry;
            $cascade = isset($entry['cascade']) && is_array($entry['cascade']) ? $entry['cascade'] : array();
            $snapshot = array('row' => self::redact($row));
            if ($cascade) {
                $snapshot['cascade'] = $cascade;
            }
            self::write($db, array(
                'table_name' => $t,
                'row_pk' => self::pkFromRow($db, $t, $row),
                'action' => 'delete',
                'changed_cols' => '',
                'before_json' => self::encode($snapshot),
                'after_json' => null,
                'row_count' => 1,
            ));
        }
    }

    /** เตรียมตาราง log ให้พร้อมก่อนเปิดธุรกรรม — CREATE TABLE ทำ implicit commit */
    public static function ready($db) {
        if (!self::enabled()) {
            return;
        }
        self::ensureSchema($db);
    }

    /* ==================== ประวัติการเข้าใช้งาน ==================== */

    /**
     * @param string $event login | failed | logout | kicked
     * @param array  $info  user_id, loginname, name, role, method, reason
     */
    public static function loginEvent($db, $event, $info = array()) {
        if (self::$failed || !$db) {
            return;
        }
        try {
            if (!self::ensureSchema($db)) {
                return;
            }
            $sth = $db->prepare(
                "INSERT INTO flood_login_log
                    (user_id, loginname, name, role, event, method, reason,
                     ip, user_agent, session_key, created_at)
                 VALUES (:uid, :login, :name, :role, :event, :method, :reason,
                     :ip, :ua, :skey, :ts)"
            );
            $sth->bindValue(':uid', !empty($info['user_id']) ? (int) $info['user_id'] : null,
                !empty($info['user_id']) ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $sth->bindValue(':login', mb_substr(isset($info['loginname']) ? (string) $info['loginname'] : '', 0, 50));
            $sth->bindValue(':name', mb_substr(isset($info['name']) ? (string) $info['name'] : '', 0, 150));
            $sth->bindValue(':role', substr(isset($info['role']) ? (string) $info['role'] : '', 0, 30));
            $sth->bindValue(':event', substr($event, 0, 20));
            $sth->bindValue(':method', substr(isset($info['method']) ? (string) $info['method'] : 'password', 0, 20));
            $sth->bindValue(':reason', mb_substr(isset($info['reason']) ? (string) $info['reason'] : '', 0, 255));
            $sth->bindValue(':ip', self::ip());
            $sth->bindValue(':ua', self::userAgent());
            $sth->bindValue(':skey', self::sessionKey());
            $sth->bindValue(':ts', date('Y-m-d H:i:s'));
            $sth->execute();
        } catch (Exception $e) {
            self::$failed = true;
        }
    }

    /* ==================== ภายใน ==================== */

    private static function write($db, $entry) {
        if (self::$failed) {
            return;
        }
        self::$depth++;
        try {
            if (self::ensureSchema($db)) {
                $a = self::actor();
                $sth = $db->prepare(
                    "INSERT INTO flood_audit_log
                        (table_name, row_pk, action, actor_user_id, actor_loginname, actor_name,
                         actor_role, actor_team_id, changed_cols, before_json, after_json,
                         row_count, route, ip, created_at)
                     VALUES (:tbl, :pk, :act, :uid, :login, :aname, :arole, :ateam,
                         :cols, :before, :after, :cnt, :route, :ip, :ts)"
                );
                $sth->bindValue(':tbl', substr($entry['table_name'], 0, 64));
                $sth->bindValue(':pk', substr((string) $entry['row_pk'], 0, 120));
                $sth->bindValue(':act', substr($entry['action'], 0, 10));
                $sth->bindValue(':uid', $a['user_id'], $a['user_id'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $sth->bindValue(':login', mb_substr($a['loginname'], 0, 50));
                $sth->bindValue(':aname', mb_substr($a['name'], 0, 150));
                $sth->bindValue(':arole', substr($a['role'], 0, 30));
                $sth->bindValue(':ateam', $a['team_id'], $a['team_id'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $sth->bindValue(':cols', (string) $entry['changed_cols']);
                $sth->bindValue(':before', $entry['before_json']);
                $sth->bindValue(':after', $entry['after_json']);
                $sth->bindValue(':cnt', (int) $entry['row_count'], PDO::PARAM_INT);
                $sth->bindValue(':route', substr(self::route(), 0, 120));
                $sth->bindValue(':ip', self::ip());
                $sth->bindValue(':ts', date('Y-m-d H:i:s'));
                $sth->execute();
            }
        } catch (Exception $e) {
            self::$failed = true;
        }
        self::$depth--;
    }

    private static function ensureSchema($db) {
        if (self::$schemaOk !== null) {
            return self::$schemaOk;
        }
        self::$schemaOk = false;
        try {
            $db->exec(
                "CREATE TABLE IF NOT EXISTS flood_login_log (
                    login_log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id INT UNSIGNED NULL DEFAULT NULL,
                    loginname VARCHAR(50) NOT NULL DEFAULT '',
                    name VARCHAR(150) NOT NULL DEFAULT '',
                    role VARCHAR(30) NOT NULL DEFAULT '',
                    event VARCHAR(20) NOT NULL,
                    method VARCHAR(20) NOT NULL DEFAULT 'password',
                    reason VARCHAR(255) NOT NULL DEFAULT '',
                    ip VARCHAR(45) NOT NULL DEFAULT '',
                    user_agent VARCHAR(255) NOT NULL DEFAULT '',
                    session_key CHAR(32) NOT NULL DEFAULT '',
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY (login_log_id),
                    KEY idx_flood_login_log_user (user_id, created_at),
                    KEY idx_flood_login_log_time (created_at),
                    KEY idx_flood_login_log_event (event, created_at),
                    KEY idx_flood_login_log_name (loginname, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $db->exec(
                "CREATE TABLE IF NOT EXISTS flood_audit_log (
                    audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    table_name VARCHAR(64) NOT NULL,
                    row_pk VARCHAR(120) NOT NULL DEFAULT '',
                    action VARCHAR(10) NOT NULL,
                    actor_user_id INT UNSIGNED NULL DEFAULT NULL,
                    actor_loginname VARCHAR(50) NOT NULL DEFAULT '',
                    actor_name VARCHAR(150) NOT NULL DEFAULT '',
                    actor_role VARCHAR(30) NOT NULL DEFAULT '',
                    actor_team_id INT UNSIGNED NULL DEFAULT NULL,
                    changed_cols VARCHAR(1000) NOT NULL DEFAULT '',
                    before_json MEDIUMTEXT NULL,
                    after_json MEDIUMTEXT NULL,
                    row_count INT UNSIGNED NOT NULL DEFAULT 1,
                    route VARCHAR(120) NOT NULL DEFAULT '',
                    ip VARCHAR(45) NOT NULL DEFAULT '',
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY (audit_id),
                    KEY idx_flood_audit_log_row (table_name, row_pk, created_at),
                    KEY idx_flood_audit_log_time (created_at),
                    KEY idx_flood_audit_log_actor (actor_loginname, created_at),
                    KEY idx_flood_audit_log_action (action, created_at),
                    KEY idx_flood_audit_log_table (table_name, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            self::$schemaOk = true;
        } catch (Exception $e) {
            self::$schemaOk = false;
        }
        return self::$schemaOk;
    }

    private static function pkColumns($db, $table) {
        if (isset(self::$pkCache[$table])) {
            return self::$pkCache[$table];
        }
        $cols = array();
        try {
            $sth = $db->prepare(
                "SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND CONSTRAINT_NAME = 'PRIMARY'
                 ORDER BY ORDINAL_POSITION"
            );
            $sth->execute(array(':t' => $table));
            foreach ($sth->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $cols[] = $r['COLUMN_NAME'];
            }
        } catch (Exception $e) {
            $cols = array();
        }
        self::$pkCache[$table] = $cols;
        return $cols;
    }

    private static function pkFromRow($db, $table, $row) {
        $out = array();
        foreach (self::pkColumns($db, $table) as $col) {
            if (array_key_exists($col, $row)) {
                $out[] = (string) $row[$col];
            }
        }
        return substr(implode('|', $out), 0, 120);
    }

    private static function cascadeChildren($db, $table) {
        if (isset(self::$cascadeCache[$table])) {
            return self::$cascadeCache[$table];
        }
        $out = array();
        try {
            $sth = $db->prepare(
                "SELECT k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_COLUMN_NAME
                 FROM information_schema.REFERENTIAL_CONSTRAINTS r
                 INNER JOIN information_schema.KEY_COLUMN_USAGE k
                         ON k.CONSTRAINT_SCHEMA = r.CONSTRAINT_SCHEMA
                        AND k.CONSTRAINT_NAME = r.CONSTRAINT_NAME
                 WHERE r.CONSTRAINT_SCHEMA = DATABASE()
                   AND r.DELETE_RULE = 'CASCADE'
                   AND r.REFERENCED_TABLE_NAME = :t"
            );
            $sth->execute(array(':t' => $table));
            $out = $sth->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $out = array();
        }
        self::$cascadeCache[$table] = $out;
        return $out;
    }

    private static function cascadeSnapshot($db, $table, $row) {
        $children = self::cascadeChildren($db, $table);
        if (!$children) {
            return array();
        }
        $out = array();
        $budget = self::MAX_CASCADE_ROWS;
        self::$depth++;
        foreach ($children as $c) {
            if ($budget <= 0) {
                break;
            }
            $parentCol = $c['REFERENCED_COLUMN_NAME'];
            if (!array_key_exists($parentCol, $row)) {
                continue;
            }
            $childTable = $c['TABLE_NAME'];
            try {
                $sth = $db->prepare(
                    "SELECT * FROM `{$childTable}` WHERE `{$c['COLUMN_NAME']}` = :v LIMIT {$budget}"
                );
                $sth->execute(array(':v' => $row[$parentCol]));
                $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                continue;
            }
            if (!$rows) {
                continue;
            }
            $budget -= count($rows);
            $out[$childTable] = array_map(array('Audit', 'redact'), $rows);
        }
        self::$depth--;
        return $out;
    }

    public static function redact($row) {
        $out = array();
        foreach ((array) $row as $k => $v) {
            $key = strtolower((string) $k);
            $secret = in_array($key, self::$secretCols, true)
                || strpos($key, 'password') !== false
                || strpos($key, 'secret') !== false
                || strpos($key, 'token') !== false;
            if ($secret) {
                $out[$k] = '***';
                continue;
            }
            if (is_array($v) || is_object($v)) {
                $v = json_encode($v, JSON_UNESCAPED_UNICODE);
            }
            if (is_string($v) && strlen($v) > self::MAX_VALUE_LEN) {
                $v = mb_strcut($v, 0, self::MAX_VALUE_LEN, 'UTF-8') . '…[ตัดที่ ' . self::MAX_VALUE_LEN . ' ไบต์]';
            }
            $out[$k] = $v;
        }
        return $out;
    }

    private static function encode($data) {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            $json = json_encode(array('_error' => 'แปลงเป็น JSON ไม่ได้'), JSON_UNESCAPED_UNICODE);
        }
        if (strlen($json) > self::MAX_JSON_LEN) {
            $json = mb_strcut($json, 0, self::MAX_JSON_LEN, 'UTF-8') . '…[ตัด]';
        }
        return $json;
    }

    private static function sameValue($old, $new) {
        if ($old === null && $new === null) {
            return true;
        }
        if ($old === null || $new === null) {
            return false;
        }
        if (is_bool($new)) {
            $new = $new ? 1 : 0;
        }
        if ((string) $old === (string) $new) {
            return true;
        }
        // DECIMAL จากฐานกลับมาเป็น '13.7000000' ส่วนค่าใหม่เป็น 13.7 — ถือว่าเท่ากัน
        // (เทียบแบบตัวเลขเฉพาะค่าที่มีจุดทศนิยม เบอร์โทร '081…' จะได้ไม่ถูกมองว่าเท่ากับ '81…')
        if (is_numeric($old) && is_numeric($new)
            && (strpos((string) $old, '.') !== false || strpos((string) $new, '.') !== false)) {
            return abs((float) $old - (float) $new) < 1e-9;
        }
        return false;
    }

}
