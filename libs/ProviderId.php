<?php
/**
 * เข้าสู่ระบบด้วย Provider ID (ผ่าน Health ID / หมอพร้อม) — ขั้นตอนเดียวกับระบบ sysinspec / COC
 *   Health ID code -> Health ID token -> Provider ID token -> profile (cid, name_th, organization[].hcode)
 * ค่าตั้งอยู่ใน config/provider_id.php (ไม่ขึ้น git)
 */
class ProviderId {

    private static $cfg = null;

    public static function cfg($key, $default = null) {
        if (self::$cfg === null) {
            $d = array(
                'enabled' => false,
                'authorize_url' => 'https://moph.id.th/oauth/redirect',
                'health_token_url' => 'https://moph.id.th/api/v1/token',
                'token_url' => 'https://provider.id.th/api/v1/services/token',
                'profile_url' => 'https://provider.id.th/api/v1/services/profile',
                'ssl_verify' => true,
                'curl_resolve' => '',
                'home_hcodes' => array('10699'),
                'cid_salt' => '',
            );
            $file = dirname(__DIR__) . '/config/provider_id.php';
            $c = is_file($file) ? include $file : array();
            self::$cfg = array_merge($d, is_array($c) ? $c : array());
        }
        return array_key_exists($key, self::$cfg) ? self::$cfg[$key] : $default;
    }

    /** เปิดใช้งานได้เมื่อ enabled และกรอก client id/secret/salt ครบ */
    public static function enabled() {
        return (bool) self::cfg('enabled') && function_exists('curl_init')
            && self::cfg('health_client_id', '') !== '' && self::cfg('health_client_secret', '') !== ''
            && self::cfg('provider_client_id', '') !== '' && self::cfg('provider_secret_key', '') !== ''
            && strlen((string) self::cfg('cid_salt', '')) >= 16;
    }

    /** ต้องลงทะเบียน URL นี้เป็น redirect_uri กับ Health ID */
    public static function redirectUri() {
        $u = (string) self::cfg('redirect_uri', '');
        return $u !== '' ? $u : URL . 'login/providerCallback';
    }

    public static function authorizeUrl($state) {
        return self::cfg('authorize_url') . '?' . http_build_query(array(
            'client_id' => self::cfg('health_client_id'),
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'state' => $state,
        ));
    }

    /** เรียก API คืน array(status, body, error) ไม่โยน exception */
    public static function http($url, $method, $headers, $body) {
        $ch = curl_init($url);
        $verify = (bool) self::cfg('ssl_verify', true);
        $opts = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            CURLOPT_TIMEOUT => 30,
        );
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $body === null ? '' : $body;
        }
        $resolve = (string) self::cfg('curl_resolve', '');
        if ($resolve !== '') {
            $opts[CURLOPT_RESOLVE] = array_values(array_filter(array_map('trim', explode(';', $resolve))));
        }
        curl_setopt_array($ch, $opts);
        $out = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($out === false) {
            return array('status' => 0, 'body' => '', 'error' => $err !== '' ? $err : 'ไม่ทราบสาเหตุ');
        }
        return array('status' => $status, 'body' => (string) $out, 'error' => '');
    }

    public static function apiMessage($body) {
        $j = json_decode($body, true);
        foreach (array('message', 'error_description', 'error') as $k) {
            if (isset($j[$k]) && is_string($j[$k]) && $j[$k] !== '') {
                return $j[$k];
            }
        }
        return mb_substr((string) $body, 0, 200);
    }

    public static function tokenFrom($body) {
        $j = json_decode($body, true);
        if (isset($j['data']['access_token'])) {
            return (string) $j['data']['access_token'];
        }
        return isset($j['access_token']) ? (string) $j['access_token'] : '';
    }

    /** หน่วยงานทั้งหมดที่ profile สังกัด: array(array('hcode'=>..., 'name'=>...)) */
    public static function orgs($data) {
        $raw = isset($data['organization']) ? $data['organization'] : null;
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return array();
        }
        if (isset($raw['hcode'])) {
            $raw = array($raw);
        }
        $out = array();
        foreach ($raw as $o) {
            if (is_array($o) && !empty($o['hcode'])) {
                $h = trim((string) $o['hcode']);
                $name = '';
                foreach (array('hname_th', 'hname', 'name_th', 'name') as $k) {
                    if (!empty($o[$k]) && is_string($o[$k])) {
                        $name = trim($o[$k]);
                        break;
                    }
                }
                $out[$h] = array('hcode' => $h, 'name' => $name);
            }
        }
        return array_values($out);
    }

    /** รหัสอ้างอิงบุคคลจากเลขบัตร (HMAC) — ไม่เก็บเลขบัตรจริง */
    public static function personKey($cid) {
        $cid = preg_replace('/\D/', '', (string) $cid);
        if (strlen($cid) !== 13) {
            return '';
        }
        return hash_hmac('sha256', $cid, (string) self::cfg('cid_salt', ''));
    }

    /**
     * รหัสอ้างอิงบุคคลจากโปรไฟล์ — โปรไฟล์ Provider ID ปกติไม่ส่งเลขบัตร (cid) ตรง ๆ แต่ส่ง provider_id / hash_cid
     * เลือกตามลำดับคงที่ (ต้องได้ค่าเดิมทุกครั้งที่คนเดิมเข้า): provider_id → hash_cid → cid → account_id
     * ทุกแบบเก็บเป็น HMAC (ใส่คำนำหน้าแยกชนิด) ไม่เก็บค่าจริง
     */
    public static function profileKey($data) {
        $salt = (string) self::cfg('cid_salt', '');
        foreach (array('provider_id', 'hash_cid') as $f) {
            if (isset($data[$f]) && is_scalar($data[$f]) && trim((string) $data[$f]) !== '') {
                return hash_hmac('sha256', $f . ':' . trim((string) $data[$f]), $salt);
            }
        }
        $k = self::personKey(isset($data['cid']) ? $data['cid'] : '');
        if ($k !== '') {
            return $k;
        }
        if (isset($data['account_id']) && is_scalar($data['account_id']) && trim((string) $data['account_id']) !== '') {
            return hash_hmac('sha256', 'account_id:' . trim((string) $data['account_id']), $salt);
        }
        return '';
    }

    /** ชื่อภาษาไทย: name_th หรือ คำนำหน้า+ชื่อ+นามสกุล */
    public static function profileName($data) {
        $n = trim((string) (isset($data['name_th']) && is_scalar($data['name_th']) ? $data['name_th'] : ''));
        if ($n === '') {
            $parts = array();
            foreach (array('title_th', 'firstname_th', 'lastname_th') as $f) {
                if (!empty($data[$f]) && is_scalar($data[$f])) {
                    $parts[] = trim((string) $data[$f]);
                }
            }
            $n = trim(preg_replace('/\s+/u', ' ', implode(' ', $parts)));
        }
        return $n;
    }

    /** ตำแหน่ง: position ของโปรไฟล์ หรือของหน่วยงานแรก */
    public static function profilePosition($data) {
        if (!empty($data['position']) && is_scalar($data['position'])) {
            return trim((string) $data['position']);
        }
        $org = isset($data['organization']) ? $data['organization'] : null;
        if (is_string($org)) {
            $org = json_decode($org, true);
        }
        if (is_array($org)) {
            $first = isset($org['position']) ? $org : reset($org);
            if (is_array($first) && !empty($first['position']) && is_scalar($first['position'])) {
                return trim((string) $first['position']);
            }
        }
        return '';
    }

    /** สังกัด รพ.แม่ข่าย (home_hcodes) หรือไม่ */
    public static function isHome($hcodes) {
        $home = array_map('strval', (array) self::cfg('home_hcodes', array()));
        return (bool) array_intersect(array_map('strval', $hcodes), $home);
    }

    /**
     * code จาก Health ID -> profile
     * คืน array('ok'=>true, 'key'=>..., 'name'=>..., 'position'=>..., 'orgs'=>[...]) หรือ array('ok'=>false,'msg'=>...)
     */
    public static function exchange($code) {
        $url = self::cfg('health_token_url');
        $url .= (strpos($url, '?') !== false ? '&' : '?') . http_build_query(array(
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::redirectUri(),
            'client_id' => self::cfg('health_client_id'),
            'client_secret' => self::cfg('health_client_secret'),
        ));
        $r = self::http($url, 'POST', array('Content-Type: application/x-www-form-urlencoded'), '');
        if ($r['error'] !== '') {
            return array('ok' => false, 'msg' => 'เชื่อมต่อ Health ID ไม่สำเร็จ — ' . $r['error']);
        }
        if ($r['status'] !== 200) {
            return array('ok' => false, 'msg' => 'แลก code เป็น token ไม่สำเร็จ (Health ID HTTP ' . $r['status'] . ') ' . self::apiMessage($r['body']));
        }
        $healthToken = self::tokenFrom($r['body']);
        if ($healthToken === '') {
            return array('ok' => false, 'msg' => 'ไม่พบ access_token ในคำตอบจาก Health ID');
        }

        $r = self::http(self::cfg('token_url'), 'POST', array('Content-Type: application/json'), json_encode(array(
            'client_id' => self::cfg('provider_client_id'),
            'secret_key' => self::cfg('provider_secret_key'),
            'token_by' => 'Health ID',
            'token' => $healthToken,
        )));
        if ($r['error'] !== '') {
            return array('ok' => false, 'msg' => 'เชื่อมต่อ Provider ID ไม่สำเร็จ — ' . $r['error']);
        }
        if ($r['status'] !== 200) {
            return array('ok' => false, 'msg' => 'ขอ token จาก Provider ID ไม่สำเร็จ (HTTP ' . $r['status'] . ') ' . self::apiMessage($r['body']));
        }
        $providerToken = self::tokenFrom($r['body']);
        if ($providerToken === '') {
            return array('ok' => false, 'msg' => 'ไม่พบ access_token ในคำตอบจาก Provider ID');
        }

        // Authorization ส่ง token ตรง ๆ ไม่มีคำว่า Bearer
        $r = self::http(self::cfg('profile_url'), 'GET', array(
            'Content-Type: application/json',
            'Authorization: ' . $providerToken,
            'client-id: ' . self::cfg('provider_client_id'),
            'secret-key: ' . self::cfg('provider_secret_key'),
        ), null);
        if ($r['error'] !== '') {
            return array('ok' => false, 'msg' => 'ดึงข้อมูลผู้ใช้จาก Provider ID ไม่สำเร็จ — ' . $r['error']);
        }
        if ($r['status'] !== 200) {
            return array('ok' => false, 'msg' => 'ดึงข้อมูลผู้ใช้จาก Provider ID ไม่สำเร็จ (HTTP ' . $r['status'] . ') ' . self::apiMessage($r['body']));
        }
        $p = json_decode($r['body'], true);
        if (!is_array($p)) {
            $p = array();
        }
        if (isset($p['status']) && in_array($p['status'], array('fail', 'error'), true)) {
            return array('ok' => false, 'msg' => 'Provider ID แจ้งข้อผิดพลาด: ' . self::apiMessage($r['body']));
        }
        $data = isset($p['data']) && is_array($p['data']) ? $p['data'] : $p;
        $key = self::profileKey($data);
        if ($key === '') {
            // บันทึกเฉพาะชื่อฟิลด์ (ไม่บันทึกค่า) เพื่อดูว่า Provider ID ส่งอะไรมา
            error_log('[Flood provider] ไม่พบรหัสบุคคลในโปรไฟล์ — ฟิลด์ที่ได้: ' . implode(',', array_keys($data)));
            return array('ok' => false, 'msg' => 'ไม่พบรหัสบุคคล (provider_id / hash_cid / cid) ในข้อมูลจาก Provider ID');
        }
        return array(
            'ok' => true,
            'key' => $key,
            'name' => self::profileName($data),
            'position' => self::profilePosition($data),
            'orgs' => self::orgs($data),
        );
    }

}
