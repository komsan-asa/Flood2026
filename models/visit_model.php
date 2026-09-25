<?php

/**
 * นับผู้เข้าชมหน้าแผนที่สาธารณะ + คนที่กำลังดูอยู่ (ไม่เก็บ IP / ข้อมูลส่วนบุคคล)
 *
 * - เบราว์เซอร์ได้คุกกี้สุ่ม skvid (1 ปี) · ฐานข้อมูลเก็บเฉพาะค่า hash ของคุกกี้
 * - นับจาก JavaScript บนหน้าเว็บ (api/visit?hit=1) — บอท/โปรแกรมดึงหน้าเว็บส่วนใหญ่ไม่ถูกนับ
 * - "เข้าชม" นับซ้ำคนเดิมได้เมื่อห่างกันเกิน 30 นาที · "ผู้เข้าชมวันนี้" = คนไม่ซ้ำต่อวัน
 * - "กำลังออนไลน์" = เปิดหน้าอยู่ภายใน 3 นาทีล่าสุด (หน้าเว็บส่งสัญญาณทุก 1 นาที ขณะเปิดแท็บอยู่)
 * ตาราง flood_visit_daily / flood_visit_online ระบบสร้างเอง (เหมือน sql/13_flood_visit.sql)
 */
class Visit_Model extends Model {

    const ONLINE_SEC = 180;
    const REVISIT_SEC = 1800;

    public function ensureTables() {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS `flood_visit_daily` (
                `day` DATE NOT NULL,
                `views` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'จำนวนครั้งที่เข้าชม',
                `visitors` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'ผู้เข้าชมไม่ซ้ำในวันนั้น',
                PRIMARY KEY (`day`)
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $this->db->exec("CREATE TABLE IF NOT EXISTS `flood_visit_online` (
                `vid` CHAR(40) NOT NULL COMMENT 'sha1 ของคุกกี้ skvid',
                `last_seen` DATETIME NOT NULL,
                `last_view` DATETIME NOT NULL,
                `seen_day` DATE NOT NULL,
                PRIMARY KEY (`vid`),
                KEY `idx_flood_visit_online_seen` (`last_seen`)
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $ready = true;
        } catch (Exception $e) {
            error_log('[flood] สร้างตารางนับผู้เข้าชมไม่ได้ (รัน php sql/apply_schema.php 13): ' . $e->getMessage());
            $ready = false;
        }
        return $ready;
    }

    private function run($sql, $params) {
        $st = $this->db->prepare($sql);   // PDO ตรง — ไม่ผ่าน Audit (ไม่ต้องลงประวัติแก้ไขข้อมูล)
        $st->execute($params);
    }

    /** $vidHash = sha1 ของคุกกี้ · $countView = true เมื่อเปิดหน้า (false = สัญญาณว่ายังเปิดอยู่) */
    public function touch($vidHash, $countView) {
        $now = date('Y-m-d H:i:s');
        $today = date('Y-m-d');
        $row = $this->db->selectOne("SELECT last_view, seen_day FROM flood_visit_online WHERE vid = :v", array(':v' => $vidHash));
        $addView = 0;
        $addVisitor = 0;
        if (!$row) {
            $this->run("INSERT INTO flood_visit_online (vid, last_seen, last_view, seen_day) VALUES (:v, :n, :n2, :d)
                        ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen)",
                array(':v' => $vidHash, ':n' => $now, ':n2' => $now, ':d' => $today));
            $addView = 1;
            $addVisitor = 1;
        } else {
            $upd = array('last_seen = :n');
            $p = array(':n' => $now, ':v' => $vidHash);
            if ($row['seen_day'] !== $today) {
                $addVisitor = 1;
                $upd[] = 'seen_day = :d';
                $p[':d'] = $today;
            }
            if ($countView && ($addVisitor || strtotime($row['last_view']) < time() - self::REVISIT_SEC)) {
                $addView = 1;
                $upd[] = 'last_view = :lv';
                $p[':lv'] = $now;
            }
            $this->run("UPDATE flood_visit_online SET " . implode(', ', $upd) . " WHERE vid = :v", $p);
        }
        if ($addView || $addVisitor) {
            $this->run("INSERT INTO flood_visit_daily (day, views, visitors) VALUES (:d, :a, :b)
                        ON DUPLICATE KEY UPDATE views = views + VALUES(views), visitors = visitors + VALUES(visitors)",
                array(':d' => $today, ':a' => $addView, ':b' => $addVisitor));
        }
        // เก็บกวาดคุกกี้ที่ไม่กลับมาเกิน 3 วัน (ประมาณ 1 ใน 50 ครั้ง)
        if (mt_rand(1, 50) === 1) {
            $this->run("DELETE FROM flood_visit_online WHERE last_seen < :t", array(':t' => date('Y-m-d H:i:s', time() - 3 * 86400)));
        }
    }

    public function stats() {
        $today = $this->db->selectOne("SELECT views, visitors FROM flood_visit_daily WHERE day = :d", array(':d' => date('Y-m-d')));
        $total = $this->db->selectOne("SELECT COALESCE(SUM(views), 0) AS views, COALESCE(SUM(visitors), 0) AS visitors FROM flood_visit_daily");
        $online = (int) $this->db->selectValue("SELECT COUNT(*) FROM flood_visit_online WHERE last_seen >= :t",
            array(':t' => date('Y-m-d H:i:s', time() - self::ONLINE_SEC)));
        return array(
            'online' => max(0, $online),
            'today_visitors' => $today ? (int) $today['visitors'] : 0,
            'today_views' => $today ? (int) $today['views'] : 0,
            'total_views' => (int) $total['views'],
            'total_visitors' => (int) $total['visitors'],
        );
    }
}
