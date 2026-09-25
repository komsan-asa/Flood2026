<?php

/**
 * ข้อมูลที่เจ้าหน้าที่ควรรู้ (ไม่ใช่จุดน้ำท่วม) — ศูนย์พักพิง โรงเรียนปิด สาธารณสุข ไฟฟ้า/ประปา การระบายน้ำ เส้นทาง ฯลฯ
 * หน้า flood/notices · ตาราง flood_notice (ระบบสร้างให้เองครั้งแรก เหมือน sql/12_flood_notice.sql)
 * verify: unverified = ข่าวจากโซเชียลที่ยังไม่มีหน่วยงานยืนยัน / verified = ยืนยันแล้ว (มีประกาศทางการหรือโทรเช็กแล้ว)
 */

if (!function_exists('flood_notice_categories')) {
    function flood_notice_categories() {
        return array(
            'shelter' => array('name' => 'ศูนย์พักพิง / ศูนย์ช่วยเหลือ', 'icon' => 'fa-home', 'color' => '#2563eb'),
            'health' => array('name' => 'สาธารณสุข / โรงพยาบาล', 'icon' => 'fa-medkit', 'color' => '#db2777'),
            'utility' => array('name' => 'ไฟฟ้า / น้ำประปา', 'icon' => 'fa-bolt', 'color' => '#d97706'),
            'dam' => array('name' => 'อ่างเก็บน้ำ / การระบายน้ำ', 'icon' => 'fa-tint', 'color' => '#0891b2'),
            'school' => array('name' => 'โรงเรียน / สถานที่ปิด', 'icon' => 'fa-graduation-cap', 'color' => '#7c3aed'),
            'road' => array('name' => 'เส้นทาง / การจราจร', 'icon' => 'fa-road', 'color' => '#475569'),
            'other' => array('name' => 'อื่น ๆ', 'icon' => 'fa-info-circle', 'color' => '#64748b'),
        );
    }
}

if (!function_exists('flood_notice_verify_options')) {
    function flood_notice_verify_options() {
        return array(
            'unverified' => array('name' => 'ยังไม่ยืนยัน', 'class' => 'label-warning', 'icon' => 'fa-question-circle'),
            'verified' => array('name' => 'ยืนยันแล้ว', 'class' => 'label-success', 'icon' => 'fa-check-circle'),
        );
    }
}

class Notice_Model extends Model {

    /** สร้างตารางถ้ายังไม่มี — คืน false ถ้าไม่มีสิทธิ์ CREATE (ให้รัน php sql/apply_schema.php 12) */
    public function ensureTable() {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        try {
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS `flood_notice` (
                  `notice_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `category` VARCHAR(20) NOT NULL DEFAULT 'other' COMMENT 'shelter|health|utility|dam|school|road|other',
                  `title` VARCHAR(200) NOT NULL,
                  `detail` TEXT DEFAULT NULL,
                  `amphoe_code` CHAR(4) DEFAULT NULL,
                  `place` VARCHAR(200) DEFAULT NULL COMMENT 'สถานที่ เช่น อาคารสโมสรเทศบาล',
                  `contact` VARCHAR(100) DEFAULT NULL COMMENT 'เบอร์/ผู้ประสาน',
                  `verify` VARCHAR(12) NOT NULL DEFAULT 'unverified' COMMENT 'unverified|verified',
                  `source_name` VARCHAR(150) DEFAULT NULL,
                  `source_url` VARCHAR(500) DEFAULT NULL,
                  `is_pinned` TINYINT(1) NOT NULL DEFAULT 0,
                  `is_public` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = แสดงบนแผนที่ประชาชน (เฉพาะที่ยืนยันแล้ว)',
                  `status` VARCHAR(10) NOT NULL DEFAULT 'active' COMMENT 'active|archived',
                  `info_at` DATETIME DEFAULT NULL COMMENT 'เวลาของข้อมูล (เวลาโพสต์/ประกาศ)',
                  `created_by` INT UNSIGNED DEFAULT NULL,
                  `updated_by` INT UNSIGNED DEFAULT NULL,
                  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  `updated_at` DATETIME DEFAULT NULL,
                  PRIMARY KEY (`notice_id`),
                  KEY `idx_flood_notice_status` (`status`, `is_pinned`, `created_at`),
                  KEY `idx_flood_notice_cat` (`category`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            if (!$this->db->select("SHOW COLUMNS FROM flood_notice LIKE 'is_public'")) {
                $this->db->exec("ALTER TABLE flood_notice ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 1
                    COMMENT '1 = แสดงบนแผนที่ประชาชน (เฉพาะที่ยืนยันแล้ว)' AFTER is_pinned");
            }
            $ready = true;
        } catch (Exception $e) {
            error_log('[flood] สร้างตาราง flood_notice ไม่ได้ (รัน php sql/apply_schema.php 12): ' . $e->getMessage());
            $ready = (bool) $this->safeExists();
        }
        return $ready;
    }

    private function safeExists() {
        try {
            return $this->db->select("SHOW TABLES LIKE 'flood_notice'");
        } catch (Exception $e) {
            return false;
        }
    }

    /** $status = active|archived|all · $category = '' ทุกหมวด */
    public function listNotices($status = 'active', $category = '', $limit = 200) {
        $where = array('1=1');
        $p = array();
        if ($status === 'active' || $status === 'archived') {
            $where[] = 'n.status = :st';
            $p[':st'] = $status;
        }
        if ($category !== '' && array_key_exists($category, flood_notice_categories())) {
            $where[] = 'n.category = :c';
            $p[':c'] = $category;
        }
        return $this->db->select(
            "SELECT n.*, a.name AS amphoe_name, cu.name AS created_by_name, uu.name AS updated_by_name
             FROM flood_notice n
             LEFT JOIN flood_amphoe a ON a.amphoe_code = n.amphoe_code
             LEFT JOIN flood_user cu ON cu.user_id = n.created_by
             LEFT JOIN flood_user uu ON uu.user_id = n.updated_by
             WHERE " . implode(' AND ', $where) . "
             ORDER BY n.is_pinned DESC, COALESCE(n.info_at, n.created_at) DESC
             LIMIT " . (int) $limit, $p);
    }

    public function counts() {
        $out = array('active' => 0, 'archived' => 0, 'unverified' => 0, 'by_cat' => array());
        foreach ($this->db->select("SELECT status, category, verify, COUNT(*) AS c FROM flood_notice GROUP BY status, category, verify") as $r) {
            $out[$r['status']] = (isset($out[$r['status']]) ? $out[$r['status']] : 0) + (int) $r['c'];
            if ($r['status'] === 'active') {
                $out['by_cat'][$r['category']] = (isset($out['by_cat'][$r['category']]) ? $out['by_cat'][$r['category']] : 0) + (int) $r['c'];
                if ($r['verify'] === 'unverified') {
                    $out['unverified'] += (int) $r['c'];
                }
            }
        }
        return $out;
    }

    public function getNotice($id) {
        return $this->db->selectOne("SELECT * FROM flood_notice WHERE notice_id = :id", array(':id' => (int) $id));
    }

    /** ตรวจ/แปลงข้อมูลฟอร์ม — คืน array หรือข้อความผิดพลาด */
    public function parseInput($src) {
        $title = mb_substr(trim(isset($src['title']) ? (string) $src['title'] : ''), 0, 200);
        if ($title === '') {
            return 'กรุณาใส่หัวข้อ';
        }
        $cat = isset($src['category']) && array_key_exists($src['category'], flood_notice_categories()) ? $src['category'] : 'other';
        $verify = isset($src['verify']) && $src['verify'] === 'verified' ? 'verified' : 'unverified';
        $amphoe = isset($src['amphoe_code']) ? preg_replace('/[^0-9]/', '', (string) $src['amphoe_code']) : '';
        $url = trim(isset($src['source_url']) ? (string) $src['source_url'] : '');
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            return 'ลิงก์แหล่งข่าวต้องขึ้นต้นด้วย http:// หรือ https://';
        }
        $infoAt = isset($src['info_at']) ? strtotime((string) $src['info_at']) : false;
        return array(
            'category' => $cat,
            'title' => $title,
            'detail' => trim(isset($src['detail']) ? (string) $src['detail'] : '') ?: null,
            'amphoe_code' => strlen($amphoe) === 4 ? $amphoe : null,
            'place' => mb_substr(trim(isset($src['place']) ? (string) $src['place'] : ''), 0, 200) ?: null,
            'contact' => mb_substr(trim(isset($src['contact']) ? (string) $src['contact'] : ''), 0, 100) ?: null,
            'verify' => $verify,
            'source_name' => mb_substr(trim(isset($src['source_name']) ? (string) $src['source_name'] : ''), 0, 150) ?: null,
            'source_url' => $url !== '' ? mb_substr($url, 0, 500) : null,
            'is_pinned' => !empty($src['is_pinned']) ? 1 : 0,
            'is_public' => isset($src['is_public']) ? (!empty($src['is_public']) ? 1 : 0) : 1,
            'info_at' => $infoAt ? date('Y-m-d H:i:s', $infoAt) : null,
        );
    }

    public function save($id, $data, $userId) {
        if ($id > 0) {
            $data['updated_by'] = $userId;
            $data['updated_at'] = date('Y-m-d H:i:s');
            $this->db->update('flood_notice', $data, 'notice_id = :w_id', array(':w_id' => (int) $id));
            return (int) $id;
        }
        $data['created_by'] = $userId;
        $data['status'] = 'active';
        if (empty($data['info_at'])) {
            $data['info_at'] = date('Y-m-d H:i:s');
        }
        return $this->db->insert('flood_notice', $data);
    }

    public function setField($id, $field, $value, $userId) {
        $allowed = array('status' => array('active', 'archived'), 'verify' => array('verified', 'unverified'), 'is_pinned' => array(0, 1),
            'is_public' => array(0, 1));
        if (!isset($allowed[$field]) || !in_array($value, $allowed[$field], true)) {
            return false;
        }
        $this->db->update('flood_notice', array($field => $value, 'updated_by' => $userId, 'updated_at' => date('Y-m-d H:i:s')),
            'notice_id = :w_id', array(':w_id' => (int) $id));
        return true;
    }

    /**
     * สำหรับหน้าประชาชน — เฉพาะที่ใช้อยู่ + ยืนยันแล้ว + เปิดให้ประชาชนเห็น (ข่าวที่ยังไม่ยืนยันไม่ออกหน้าประชาชนเด็ดขาด)
     * ไม่ส่งข้อมูลผู้บันทึก/ผู้แก้ไข
     */
    public function listPublic($limit = 30) {
        $rows = $this->db->select(
            "SELECT n.notice_id, n.category, n.title, n.detail, n.place, n.contact, n.source_name, n.source_url,
                    n.is_pinned, COALESCE(n.info_at, n.created_at) AS info_at, a.name AS amphoe_name
             FROM flood_notice n
             LEFT JOIN flood_amphoe a ON a.amphoe_code = n.amphoe_code
             WHERE n.status = 'active' AND n.verify = 'verified' AND n.is_public = 1
             ORDER BY n.is_pinned DESC, COALESCE(n.info_at, n.created_at) DESC
             LIMIT " . (int) $limit);
        return $rows;
    }

    /** ใช้กับการนำเข้าจากโซเชียล: ข้ามถ้าหัวข้อเดียวกัน (หรือลิงก์เดียวกัน) มีอยู่แล้วใน 3 วัน */
    public function findDuplicate($title, $url) {
        return $this->db->selectValue(
            "SELECT notice_id FROM flood_notice WHERE (title = :t" . ($url ? " OR source_url = :u" : '') . ") AND created_at >= :d LIMIT 1",
            array_merge(array(':t' => $title, ':d' => date('Y-m-d H:i:s', time() - 3 * 86400)), $url ? array(':u' => $url) : array()));
    }
}
