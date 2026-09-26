<?php

/**
 * API สาธารณะสำหรับหน้าแผนที่ — คืนเฉพาะพื้นที่ประกาศ (ไม่มีข้อมูลส่วนบุคคล)
 *   GET api/zones             พื้นที่ที่ประกาศอยู่ทั้งหมด (photos = รหัสภาพประกอบ)
 *   GET api/zones?amphoe=2706 เฉพาะอำเภอ · ?province=27 เฉพาะจังหวัด
 *   GET api/zoneThumb/<id>    ภาพย่อสำหรับบอลลูน (640px)
 *   GET api/zonePhoto/<id>    ภาพขนาดเต็ม (1600px)
 *   GET api/votes?zone=<id>   ยอด Like / Not Like ของพื้นที่ + เสียงของเบราว์เซอร์นี้
 *   POST api/vote             กด Like / Not Like (zone=<id>&vote=1|-1|0)
 */
class Api extends Controller {

    public function loadModel($name) {
        parent::loadModel('flood');
    }

    function index() {
        $this->zones();
    }

    function zones() {
        $amphoe = flood_in('amphoe', '', $_GET);
        if ($amphoe !== '' && !preg_match('/^\d{4}$/', $amphoe)) {
            $amphoe = '';
        }
        $province = flood_province_param('province');
        flood_json(array(
            'chk' => true,
            'zones' => $this->model->publicZones($amphoe, $province),
            'points' => $this->model->publicReportPoints($amphoe, $province),
            'counts' => $this->model->zoneCounts(),
            'updated' => date('Y-m-d H:i:s'),
            'updated_th' => flood_thai_date(time()),
        ));
    }

    /**
     * นับผู้เข้าชม / คนที่กำลังดู — หน้าแผนที่เรียกตอนเปิด (hit=1) และทุก 1 นาทีขณะเปิดแท็บอยู่
     * ไม่เก็บ IP · คุกกี้ skvid เป็นค่าสุ่ม ฐานข้อมูลเก็บเฉพาะ hash
     */
    function visit() {
        header('Cache-Control: no-store');
        require_once 'models/visit_model.php';
        $vm = new Visit_Model();
        if (!$vm->ensureTables()) {
            flood_json(array('chk' => false));
        }
        $vid = isset($_COOKIE['skvid']) && preg_match('/^[a-f0-9]{32}$/', $_COOKIE['skvid']) ? $_COOKIE['skvid'] : '';
        if ($vid === '') {
            $vid = bin2hex(random_bytes(16));
            setcookie('skvid', $vid, array(
                'expires' => time() + 365 * 86400,
                'path' => defined('BASE_PATH') ? BASE_PATH : '/',
                'secure' => defined('IS_HTTPS') && IS_HTTPS,
                'httponly' => true,
                'samesite' => 'Lax',
            ));
        }
        try {
            $vm->touch(sha1('skvid:' . $vid), flood_in('hit', '', $_REQUEST) === '1');
            $s = $vm->stats();
        } catch (Exception $e) {
            error_log('[flood] api/visit: ' . $e->getMessage());
            flood_json(array('chk' => false));
        }
        flood_json(array('chk' => true) + $s);
    }

    /**
     * ยอด Like / Not Like ของพื้นที่ประกาศ (หน้ารายละเอียดพื้นที่)
     * off = true → ระบบกด Like ยังไม่พร้อม (สร้างตารางไม่ได้) ให้หน้าเว็บซ่อนปุ่ม
     */
    function votes() {
        $zone = (int) flood_in('zone', 0, $_GET);
        $vm = $this->voteModel();
        if (!$vm) {
            flood_json(array('chk' => false, 'off' => true));
        }
        try {
            flood_json(array('chk' => true, 'zone' => $zone) + $vm->zoneVotes($zone, $this->voter(false)));
        } catch (Exception $e) {
            error_log('[flood] api/votes: ' . $e->getMessage());
            flood_json(array('chk' => false, 'msg' => 'โหลดยอด Like ไม่สำเร็จ'));
        }
    }

    /**
     * กด Like (1) / Not Like (-1) / ยกเลิก (0) — 1 เบราว์เซอร์ 1 เสียงต่อพื้นที่ (คุกกี้ skvid)
     * คืนยอดล่าสุดทุกครั้ง (รวมกรณีไม่สำเร็จ) ให้หน้าเว็บแสดงตัวเลขตรงกับฐานข้อมูล
     */
    function vote() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            flood_json(array('chk' => false, 'msg' => 'ต้องกดจากปุ่มบนหน้าเว็บ'), 405);
        }
        if (!flood_csrf_valid()) {
            flood_json(array('chk' => false, 'csrf' => true, 'token' => flood_csrf_token(), 'msg' => 'กรุณากดอีกครั้ง'));
        }
        $zone = (int) flood_in('zone', 0, $_POST);
        $raw = flood_in('vote', '', $_POST);
        if ($zone <= 0 || !in_array($raw, array('1', '-1', '0'), true)) {
            flood_json(array('chk' => false, 'msg' => 'ข้อมูลไม่ถูกต้อง'));
        }
        $vm = $this->voteModel();
        if (!$vm) {
            flood_json(array('chk' => false, 'off' => true, 'msg' => 'ขณะนี้ยังกด Like ไม่ได้'));
        }
        try {
            if (!$vm->zoneActive($zone)) {
                flood_json(array('chk' => false, 'msg' => 'พื้นที่นี้ปิดประกาศแล้ว'));
            }
            $voter = $this->voter(true);
            if (!$vm->castZone($zone, $voter, (int) $raw, Vote_Model::ipHash(Vote_Model::clientIp()))) {
                flood_json(array('chk' => false, 'msg' => 'มีการกดจากเครือข่ายนี้ถี่เกินไป กรุณารอสักครู่แล้วลองใหม่')
                    + $vm->zoneVotes($zone, $voter));
            }
            flood_json(array('chk' => true, 'zone' => $zone) + $vm->zoneVotes($zone, $voter));
        } catch (Exception $e) {
            error_log('[flood] api/vote: ' . $e->getMessage());
            flood_json(array('chk' => false, 'msg' => 'บันทึกไม่สำเร็จ กรุณาลองใหม่'));
        }
    }

    private function voteModel() {
        require_once 'models/vote_model.php';
        $vm = new Vote_Model();
        return $vm->ensureTable() ? $vm : null;
    }

    /**
     * รหัสผู้กด = hash ของคุกกี้ skvid (ตัวเดียวกับที่ api/visit ออกให้ — รูปแบบเดียวกัน)
     * $create = true: ยังไม่มีคุกกี้ → ออกให้ใหม่ · คืน '' ถ้าไม่มีและไม่ต้องสร้าง
     */
    private function voter($create) {
        $vid = isset($_COOKIE['skvid']) && is_string($_COOKIE['skvid']) && preg_match('/^[a-f0-9]{32}$/', $_COOKIE['skvid'])
            ? $_COOKIE['skvid'] : '';
        if ($vid === '' && $create) {
            $vid = bin2hex(random_bytes(16));
            setcookie('skvid', $vid, array(
                'expires' => time() + 365 * 86400,
                'path' => defined('BASE_PATH') ? BASE_PATH : '/',
                'secure' => defined('IS_HTTPS') && IS_HTTPS,
                'httponly' => true,
                'samesite' => 'Lax',
            ));
            $_COOKIE['skvid'] = $vid;
        }
        return $vid === '' ? '' : Vote_Model::voterHash($vid);
    }

    function zonePhoto($id = null) {
        $this->sendZonePhoto($id, false);
    }

    function zoneThumb($id = null) {
        $this->sendZonePhoto($id, true);
    }

    /**
     * ส่งภาพประกอบพื้นที่ — ประชาชนเปิดได้เฉพาะภาพของพื้นที่ที่ประกาศอยู่
     * เจ้าหน้าที่ที่ล็อกอินอยู่เปิดภาพของพื้นที่ที่ปิดประกาศแล้วได้ด้วย (หน้ารายการพื้นที่)
     * รูปรายงาน/คำขอความช่วยเหลือไม่ผ่านทางนี้ — ต้องไปทาง flood/attachment ที่ตรวจสิทธิ์
     */
    private function sendZonePhoto($id, $thumb) {
        $id = (int) $id;
        $a = $id > 0 ? $this->model->getZonePhoto($id) : null;
        $staff = Session::get('User_FLOOD');
        if (!$a || ($a['zone_status'] !== 'active' && !(is_array($staff) && !empty($staff['user_id'])))) {
            $this->notFound();
        }
        $base = realpath(flood_upload_dir());
        $file = $thumb ? realpath(flood_upload_dir() . '/' . flood_thumb_path($a['file_path'])) : false;
        if (!$file) {
            $file = realpath(flood_upload_dir() . '/' . $a['file_path']);   // ไม่มีภาพย่อ → ส่งภาพเต็มแทน
        }
        if (!$base || !$file || strpos($file, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($file)) {
            $this->notFound();
        }
        $etag = '"z' . $id . ($thumb ? 't' : '') . '-' . filesize($file) . '-' . filemtime($file) . '"';
        session_write_close();
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        // session_start() ใส่ no-cache ไว้ — ภาพเปลี่ยนไม่ได้ (ภาพใหม่ = รหัสใหม่) ให้เบราว์เซอร์เก็บไว้ 5 นาที
        header_remove('Pragma');
        header_remove('Expires');
        header('Cache-Control: private, max-age=300');
        header('ETag: ' . $etag);
        header('X-Content-Type-Options: nosniff');
        if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string) $_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
            http_response_code(304);
            exit;
        }
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($file));
        header('Content-Disposition: inline; filename="zone-' . (int) $a['zone_id'] . '-' . $id . ($thumb ? '-s' : '') . '.jpg"');
        readfile($file);
        exit;
    }

    private function notFound() {
        http_response_code(404);
        header('Cache-Control: no-store');
        exit;
    }

}
