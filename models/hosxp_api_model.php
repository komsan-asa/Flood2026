<?php

/**
 * ตัวเลขผู้รับบริการรายวันจาก HOSxP ผ่าน hosxp-site-api (Z:\hosxp-site-api = /var/www/html/hosxp-site-api)
 *   ER · IPD คงค้าง / รับใหม่ / จำหน่าย · OPD · Refer In / Out — วันนี้ (ถึงเวลาที่ดึง) + เมื่อวาน (ทั้งวัน)
 *
 * - เรียก GET api/v1/flood/range?start=เมื่อวาน&end=วันนี้ ครั้งเดียว (header X-API-Key)
 * - API key อ่านจาก hosxp-site-api/config/app.php ในเครื่องเดียวกัน (ไม่คัดลอกมาเก็บใน Flood2026)
 *   ตั้งเองได้ใน config/app.php: HOSXP_API_URL (เช่น 'https://host/hosxp-site-api/index.php') · HOSXP_API_KEY · HOSXP_API_PATH
 *   ค่าเริ่มต้นเรียกโดเมนเดียวกับ Flood2026 ที่ 127.0.0.1 (API อยู่เครื่องเดียวกัน)
 * - เก็บผลไว้ใน flood_sat_setting (skey = hosxp_daily) 10 นาที — ดึงไม่สำเร็จจะลองใหม่ทุก 3 นาที และใช้ค่าล่าสุดที่มี
 */
class HosxpApi {

    const NAME = 'hosxp-site-api';
    const CACHE_KEY = 'hosxp_daily';
    const CACHE_MIN = 10;
    const RETRY_MIN = 3;

    /** โฟลเดอร์ของ API (null = ไม่พบ) */
    public static function path() {
        $p = defined('HOSXP_API_PATH') && HOSXP_API_PATH ? HOSXP_API_PATH : dirname(__DIR__) . '/../hosxp-site-api';
        $real = realpath($p);
        return $real && is_file($real . '/config/app.php') ? $real : null;
    }

    /** API key — HOSXP_API_KEY หรืออ่าน define('SITE_API_KEY', ...) จาก config ของ API โดยไม่ execute ไฟล์ (ชื่อค่าคงที่ชนกับ Flood2026) */
    public static function apiKey() {
        if (defined('HOSXP_API_KEY') && HOSXP_API_KEY) {
            return (string) HOSXP_API_KEY;
        }
        $p = self::path();
        if (!$p) {
            return '';
        }
        $src = @file_get_contents($p . '/config/app.php');
        if ($src !== false && preg_match("/define\\(\\s*['\"]SITE_API_KEY['\"]\\s*,\\s*(?:'((?:[^'\\\\]|\\\\.)*)'|\"((?:[^\"\\\\]|\\\\.)*)\")\\s*\\)/", $src, $m)) {
            return isset($m[2]) && $m[2] !== '' ? stripcslashes($m[2]) : str_replace(array("\\'", '\\\\'), array("'", '\\'), $m[1]);
        }
        return '';
    }

    /**
     * ที่อยู่ที่ลองเรียกตามลำดับ → array(url, resolve)
     *   HOSXP_API_URL (ถ้าตั้ง) · โดเมนเดียวกับ Flood2026 แต่ชี้ไป 127.0.0.1 (https แล้ว http — ไม่ต้องพึ่ง DNS, ตรวจใบรับรองตามปกติ)
     *   · สุดท้ายเรียกโดเมนตามปกติ
     */
    private static function bases() {
        if (defined('HOSXP_API_URL') && HOSXP_API_URL) {
            return array(array(rtrim(HOSXP_API_URL, '?&'), ''));
        }
        $host = isset($_SERVER['HTTP_HOST']) ? preg_replace('/:\d+$/', '', (string) $_SERVER['HTTP_HOST']) : '';
        if ($host === '' || !preg_match('/^[A-Za-z0-9.-]+$/', $host)) {
            return array(array('http://127.0.0.1/' . self::NAME . '/index.php', ''));
        }
        $path = '/' . self::NAME . '/index.php';
        return array(
            array('https://' . $host . $path, $host . ':443:127.0.0.1'),
            array('http://' . $host . $path, $host . ':80:127.0.0.1'),
            array('https://' . $host . $path, ''),
        );
    }

    /** GET route → array('ok' => bool, 'json' => array | 'error' => string) */
    public static function request($route, $params = array()) {
        if (!function_exists('curl_init')) {
            return array('ok' => false, 'error' => 'เซิร์ฟเวอร์ไม่มี PHP curl');
        }
        $key = self::apiKey();
        if ($key === '') {
            return array('ok' => false, 'error' => 'ไม่พบ API key ของ ' . self::NAME . ' (config/app.php) — ตั้ง HOSXP_API_KEY ใน config/app.php');
        }
        $errs = array();
        foreach (self::bases() as $n => $b) {
            list($base, $resolve) = $b;
            $url = $base . (strpos($base, '?') === false ? '?' : '&') . 'url=' . rawurlencode($route) . ($params ? '&' . http_build_query($params) : '');
            $ch = curl_init($url);
            $opt = array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTPHEADER => array('X-API-Key: ' . $key, 'Accept: application/json'),
            );
            if ($resolve !== '' && defined('CURLOPT_RESOLVE')) {
                $opt[CURLOPT_RESOLVE] = array($resolve);
            }
            curl_setopt_array($ch, $opt);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $cerr = curl_error($ch);
            curl_close($ch);
            $tag = '#' . ($n + 1);
            if ($body === false) {
                $errs[] = $tag . ' ' . mb_substr($cerr, 0, 80);
                continue;
            }
            $j = json_decode($body, true);
            if (!is_array($j)) {
                $errs[] = $tag . ' HTTP ' . $code;
                continue;
            }
            if (empty($j['chk'])) {
                return array('ok' => false, 'error' => self::NAME . ': ' . (isset($j['msg']) ? mb_substr((string) $j['msg'], 0, 200) : 'HTTP ' . $code));
            }
            return array('ok' => true, 'json' => $j);
        }
        return array('ok' => false, 'error' => 'ติดต่อ ' . self::NAME . ' ไม่ได้ (' . implode(' · ', $errs) . ')');
    }

    /**
     * ตัวเลขวันนี้ + เมื่อวาน (ผ่านแคช)
     * @param Sat_Model $m · $settings = $m->settings() · $allowFetch = false อ่านแคชอย่างเดียว (หน้าสาธารณะ)
     * @return array ready, today, yest (แถวจาก API), at (เวลาที่ดึงสำเร็จ), error, tried_at
     */
    public static function daily($m, $settings, $allowFetch = true, $force = false) {
        $c = array();
        if (isset($settings[self::CACHE_KEY]) && $settings[self::CACHE_KEY]['sval'] !== null) {
            $c = json_decode((string) $settings[self::CACHE_KEY]['sval'], true);
            $c = is_array($c) ? $c : array();
        }
        $c += array('today' => null, 'yest' => null, 'at' => '', 'error' => '', 'tried_at' => '');
        $today = date('Y-m-d');
        $age = $c['at'] !== '' ? time() - strtotime($c['at']) : PHP_INT_MAX;
        $tried = $c['tried_at'] !== '' ? time() - strtotime($c['tried_at']) : PHP_INT_MAX;
        $fresh = $c['at'] !== '' && substr($c['at'], 0, 10) === $today && $age < self::CACHE_MIN * 60;
        $need = $force || (!$fresh && ($c['error'] === '' || $tried >= self::RETRY_MIN * 60));
        if ($allowFetch && $need && self::path() !== null) {
            $yest = date('Y-m-d', strtotime('-1 day'));
            $r = self::request('api/v1/flood/range', array('start' => $yest, 'end' => $today));
            $c['tried_at'] = date('Y-m-d H:i:s');
            if ($r['ok'] && !empty($r['json']['data']) && is_array($r['json']['data'])) {
                $c['today'] = null;
                $c['yest'] = null;
                foreach ($r['json']['data'] as $row) {
                    if (isset($row['date']) && $row['date'] === $today) {
                        $c['today'] = self::cleanRow($row);
                    } elseif (isset($row['date']) && $row['date'] === $yest) {
                        $c['yest'] = self::cleanRow($row);
                    }
                }
                $c['at'] = $c['tried_at'];
                $c['error'] = '';
            } else {
                $c['error'] = $r['ok'] ? self::NAME . ' ไม่มีข้อมูลในช่วงวันที่' : $r['error'];
                error_log('[flood] HOSxP API: ' . $c['error']);
            }
            try {
                $m->setSetting(self::CACHE_KEY, json_encode($c, JSON_UNESCAPED_UNICODE), null);
            } catch (Exception $e) {
                error_log('[flood] HOSxP cache: ' . $e->getMessage());
            }
        }
        // ข้อมูลของวันก่อน ๆ ไม่ถือเป็น "วันนี้"
        if ($c['today'] && $c['today']['date'] !== $today) {
            if ($c['today']['date'] === date('Y-m-d', strtotime('-1 day'))) {
                $c['yest'] = $c['today'];
            }
            $c['today'] = null;
        }
        if ($c['yest'] && $c['yest']['date'] !== date('Y-m-d', strtotime('-1 day'))) {
            $c['yest'] = null;
        }
        $c['ready'] = (bool) ($c['today'] || $c['yest']);
        $c['configured'] = self::path() !== null || (defined('HOSXP_API_URL') && HOSXP_API_URL);
        return $c;
    }

    private static function cleanRow($row) {
        $out = array('date' => (string) $row['date']);
        foreach (array('er', 'ipd', 'ipd_admit', 'ipd_discharge', 'opd', 'refer_in', 'refer_out') as $k) {
            $out[$k] = isset($row[$k]) ? (int) $row[$k] : null;
        }
        return $out;
    }

    /** บรรทัดสรุปสำหรับการ์ด "ข้อมูลป่วยใน รพ." (ed_load) / SitRep / ดึงประมวล */
    public static function lines($h) {
        $L = array();
        if (empty($h['ready'])) {
            if (!empty($h['error'])) {
                $L[] = 'HOSxP: ดึงข้อมูลไม่สำเร็จ — ' . mb_substr($h['error'], 0, 120);
            }
            return $L;
        }
        $t = $h['today'];
        $y = $h['yest'];
        $v = function ($row, $k) {
            return $row && $row[$k] !== null ? number_format($row[$k]) : '–';
        };
        // ระบุวันที่/เวลาจริง (ข้อความถูกบันทึกเก็บไว้ — "วันนี้/เมื่อวาน" จะผิดเมื่ออ่านภายหลัง)
        $at = $h['at'] !== '' ? $h['at'] : date('Y-m-d H:i:s');
        $dT = flood_thai_date($t ? $t['date'] : date('Y-m-d', strtotime($at)), false) . ' (00:00–' . date('H:i', strtotime($at)) . ')';
        $dY = flood_thai_date($y ? $y['date'] : date('Y-m-d', strtotime($at) - 86400), false) . ' (ทั้งวัน)';
        $L[] = 'ER ' . $dT . ' ' . $v($t, 'er') . ' · ' . $dY . ' ' . $v($y, 'er') . ' ราย';
        $L[] = 'IPD คงค้าง ณ ' . flood_thai_date($at) . ' ' . $v($t, 'ipd') . ' · รับใหม่/จำหน่าย ' . $dT . ' ' . $v($t, 'ipd_admit') . '/' . $v($t, 'ipd_discharge')
            . ' · ' . $dY . ' ' . $v($y, 'ipd_admit') . '/' . $v($y, 'ipd_discharge');
        $L[] = 'OPD ' . $dT . ' ' . $v($t, 'opd') . ' · ' . $dY . ' ' . $v($y, 'opd') . ' ราย';
        $L[] = 'Refer In/Out ' . $dT . ' ' . $v($t, 'refer_in') . '/' . $v($t, 'refer_out')
            . ' · ' . $dY . ' ' . $v($y, 'refer_in') . '/' . $v($y, 'refer_out');
        if (!empty($h['error'])) {
            $L[] = 'HOSxP: ดึงล่าสุดไม่สำเร็จ ใช้ข้อมูล ' . flood_thai_date($h['at']);
        }
        return $L;
    }
}
