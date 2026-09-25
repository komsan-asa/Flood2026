<?php

/**
 * ประชาชนกด Like / Not Like ให้พื้นที่ประกาศ (หน้าแผนที่สาธารณะ → รายละเอียดพื้นที่)
 *
 * - 1 เบราว์เซอร์ = 1 เสียงต่อพื้นที่ (คุกกี้สุ่ม skvid ตัวเดียวกับที่นับผู้เข้าชม)
 *   กดปุ่มเดิมซ้ำ = ยกเลิก · กดอีกปุ่ม = เปลี่ยนใจ
 * - ไม่เก็บชื่อ/IP: ผู้กดเก็บเป็น hash ของคุกกี้ · IP เก็บเป็น hash ผสมค่าลับของเซิร์ฟเวอร์ ใช้กันปั่นยอดเท่านั้น
 * - กันปั่นยอด (ล้างคุกกี้แล้วกดใหม่): เสียงใหม่จาก IP เดียวกันไม่เกิน LIMIT_IP_ZONE_HOUR ต่อพื้นที่
 *   และ LIMIT_IP_HOUR ทุกพื้นที่รวมกัน ต่อ 1 ชั่วโมง (มือถือหลายเครื่องอาจใช้ IP เดียวกัน จึงตั้งไว้หลวม ๆ)
 * - กดได้เฉพาะพื้นที่ที่ประกาศอยู่
 * ตาราง flood_vote ระบบสร้างเองตอนใช้ครั้งแรก (เหมือน sql/14_flood_vote.sql)
 */
class Vote_Model extends Model {

    const LIMIT_IP_ZONE_HOUR = 20;
    const LIMIT_IP_HOUR = 120;

    public function ensureTable() {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS `flood_vote` (
                `target_type` VARCHAR(10) NOT NULL COMMENT 'zone = พื้นที่ประกาศ',
                `target_id` INT UNSIGNED NOT NULL,
                `voter` CHAR(40) NOT NULL COMMENT 'sha1 ของคุกกี้ skvid (ไม่เก็บชื่อ)',
                `vote` TINYINT NOT NULL DEFAULT 0 COMMENT '1 = Like, -1 = Not Like, 0 = ยกเลิกแล้ว',
                `ip_hash` CHAR(40) NOT NULL DEFAULT '' COMMENT 'hash ของ IP ผสมค่าลับ ใช้กันปั่นยอดเท่านั้น',
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`target_type`, `target_id`, `voter`),
                KEY `idx_flood_vote_ip` (`ip_hash`, `created_at`)
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $ready = true;
        } catch (Exception $e) {
            error_log('[flood] สร้างตาราง flood_vote ไม่ได้ (รัน php sql/apply_schema.php 14): ' . $e->getMessage());
            $ready = false;
        }
        return $ready;
    }

    /** คุกกี้ skvid → รหัสผู้กด (hash แยกจากที่ใช้นับผู้เข้าชม) */
    public static function voterHash($vid) {
        return sha1('skvote:' . $vid);
    }

    /** IP → hash ผสมค่าลับของเซิร์ฟเวอร์ (ย้อนกลับเป็น IP ไม่ได้) */
    public static function ipHash($ip) {
        return sha1('skvote-ip:' . $ip . ':' . (defined('DB_PASS') ? DB_PASS : '') . ':' . (defined('DB_NAME') ? DB_NAME : ''));
    }

    /**
     * IP ของผู้กดสำหรับกันปั่นยอด — ถ้าเว็บอยู่หลัง proxy ภายใน (REMOTE_ADDR เป็น IP วงภายใน/127.x)
     * ใช้ IP แรกใน X-Forwarded-For แทน ไม่งั้นทุกคนจะนับเป็น IP เดียวกันแล้วโดนจำกัดพร้อมกัน
     * (ผู้ที่ต่อตรงจากอินเทอร์เน็ตปลอม X-Forwarded-For ไม่ได้ผล เพราะ REMOTE_ADDR ของเขาเป็น IP สาธารณะ)
     */
    public static function clientIp() {
        $ip = flood_client_ip();
        $xff = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? (string) $_SERVER['HTTP_X_FORWARDED_FOR'] : '';
        if ($xff !== '' && filter_var($ip, FILTER_VALIDATE_IP)
            && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            $parts = explode(',', $xff);
            $first = trim($parts[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        return $ip;
    }

    private function run($sql, $params) {
        $st = $this->db->prepare($sql);   // PDO ตรง — ไม่ผ่าน Audit (การกดของประชาชนไม่ต้องลงประวัติแก้ไขข้อมูล)
        $st->execute($params);
    }

    public function zoneActive($zoneId) {
        return (bool) $this->db->selectValue(
            "SELECT 1 FROM flood_zone WHERE zone_id = :id AND status = 'active'",
            array(':id' => (int) $zoneId)
        );
    }

    /** ยอด Like / Not Like ของพื้นที่ + เสียงของผู้กดคนนี้ (mine: 1 / -1 / 0) */
    public function zoneVotes($zoneId, $voter) {
        $r = $this->db->selectOne(
            "SELECT COALESCE(SUM(vote = 1), 0) AS likes, COALESCE(SUM(vote = -1), 0) AS dislikes
             FROM flood_vote WHERE target_type = 'zone' AND target_id = :id",
            array(':id' => (int) $zoneId)
        );
        $mine = 0;
        if ($voter !== '') {
            $mine = (int) $this->db->selectValue(
                "SELECT vote FROM flood_vote WHERE target_type = 'zone' AND target_id = :id AND voter = :v",
                array(':id' => (int) $zoneId, ':v' => $voter)
            );
        }
        return array(
            'likes' => $r ? (int) $r['likes'] : 0,
            'dislikes' => $r ? (int) $r['dislikes'] : 0,
            'mine' => $mine,
        );
    }

    /**
     * บันทึกเสียง $vote (1 / -1 / 0 = ยกเลิก) ของผู้กด $voter
     * คืน false เมื่อเป็นเสียงใหม่ที่เกินโควตาของ IP นี้ (เปลี่ยน/ยกเลิกเสียงเดิมทำได้เสมอ)
     */
    public function castZone($zoneId, $voter, $vote, $ipHash) {
        $zoneId = (int) $zoneId;
        $vote = (int) $vote;
        $now = date('Y-m-d H:i:s');
        $key = array(':id' => $zoneId, ':v' => $voter);
        $old = $this->db->selectOne(
            "SELECT vote FROM flood_vote WHERE target_type = 'zone' AND target_id = :id AND voter = :v",
            $key
        );
        if ($old) {
            if ((int) $old['vote'] !== $vote) {
                $this->run("UPDATE flood_vote SET vote = :vote, updated_at = :n
                            WHERE target_type = 'zone' AND target_id = :id AND voter = :v",
                    $key + array(':vote' => $vote, ':n' => $now));
            }
            return true;
        }
        if ($vote === 0) {
            return true;   // ยังไม่เคยกด — ไม่มีอะไรให้ยกเลิก
        }
        $since = date('Y-m-d H:i:s', time() - 3600);
        $nZone = (int) $this->db->selectValue(
            "SELECT COUNT(*) FROM flood_vote
             WHERE ip_hash = :ip AND created_at >= :t AND target_type = 'zone' AND target_id = :id",
            array(':ip' => $ipHash, ':t' => $since, ':id' => $zoneId)
        );
        $nAll = (int) $this->db->selectValue(
            "SELECT COUNT(*) FROM flood_vote WHERE ip_hash = :ip AND created_at >= :t",
            array(':ip' => $ipHash, ':t' => $since)
        );
        if ($nZone >= self::LIMIT_IP_ZONE_HOUR || $nAll >= self::LIMIT_IP_HOUR) {
            return false;
        }
        // กดพร้อมกันสองแท็บ — แถวเดียวกันชนกัน ให้ค่าล่าสุดชนะ
        $this->run("INSERT INTO flood_vote (target_type, target_id, voter, vote, ip_hash, created_at, updated_at)
                    VALUES ('zone', :id, :v, :vote, :ip, :n, :n2)
                    ON DUPLICATE KEY UPDATE vote = VALUES(vote), updated_at = VALUES(updated_at)",
            $key + array(':vote' => $vote, ':ip' => $ipHash, ':n' => $now, ':n2' => $now));
        return true;
    }
}
