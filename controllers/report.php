<?php

/**
 * ประชาชนแจ้งจุดน้ำท่วม/น้ำขัง (ไม่ต้องล็อกอิน)
 * ข้อมูลเข้าคิว "รอตรวจสอบ" — เจ้าหน้าที่ยืนยันก่อนนำไปประกาศเป็นพื้นที่บนแผนที่
 */
class Report extends Controller {

    function __construct() {
        parent::__construct();
        $this->view->pageMenu = 'report';
        $this->view->useMap = true;
        $this->view->css = array('../public/css/public.css', 'report/css/wizard.css');
        $this->view->bodyClass = 'sk-wizard-body';
        $this->view->noNavbar = true;
        $this->view->js = array('../public/js/flood-map.js', '../public/js/flood-upload.js', 'report/js/default.js');
    }

    public function loadModel($name) {
        parent::loadModel('flood');
    }

    function index() {
        $this->view->pageTitle = 'แจ้งจุดน้ำท่วม';
        $this->view->rander('report/index');
    }

    function save() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            flood_json(array('chk' => false, 'msg' => 'ต้องส่งด้วยฟอร์ม'), 405);
        }
        if (!flood_csrf_valid()) {
            // เปิดฟอร์มค้างไว้นานจน session หมด — ส่ง token ใหม่ให้ JS ส่งซ้ำเองอัตโนมัติ
            flood_json(array('chk' => false, 'csrf' => true, 'token' => flood_csrf_token(),
                'msg' => 'กรุณากดส่งอีกครั้ง'));
        }
        // ช่องดักบอท (ซ่อนไว้ คนจริงไม่เห็น) — มีค่าแปลว่าเป็นบอท ตอบเหมือนสำเร็จแต่ไม่บันทึก
        if (flood_in('website', '', $_POST) !== '') {
            flood_json(array('chk' => true, 'ref' => '', 'url' => URL . 'report/done'));
        }

        $lat = flood_in('lat', '', $_POST);
        $lng = flood_in('lng', '', $_POST);
        if (!flood_valid_latlng($lat, $lng)) {
            flood_json(array('chk' => false, 'msg' => 'กรุณาระบุตำแหน่ง — กดใช้ตำแหน่งปัจจุบัน หรือแตะบนแผนที่', 'field' => 'location'));
        }
        $depth = flood_in('depth', '', $_POST);
        if (!array_key_exists($depth, flood_depth_options())) {
            flood_json(array('chk' => false, 'msg' => 'กรุณาเลือกว่าน้ำสูงแค่ไหน', 'field' => 'depth'));
        }
        $extent = flood_in('extent', '', $_POST);
        if (!array_key_exists($extent, flood_extent_options())) {
            flood_json(array('chk' => false, 'msg' => 'กรุณาเลือกว่าน้ำท่วมกว้างแค่ไหน', 'field' => 'extent'));
        }
        $houses = flood_in('houses', '', $_POST);
        $vehicle = flood_in('vehicle', '', $_POST);
        $trend = flood_in('trend', '', $_POST);
        $name = mb_substr(flood_in('reporter_name', '', $_POST), 0, 150);
        $phone = flood_normalize_phone(flood_in('reporter_phone', '', $_POST));
        if ($name === '' || mb_strlen($name) < 2) {
            flood_json(array('chk' => false, 'msg' => 'กรุณากรอกชื่อผู้แจ้ง', 'field' => 'reporter_name'));
        }
        if (!flood_valid_phone($phone)) {
            flood_json(array('chk' => false, 'msg' => 'กรุณากรอกเบอร์โทรที่ติดต่อได้ (9–10 หลัก)', 'field' => 'reporter_phone'));
        }

        $ip = flood_client_ip();
        list($ipCount, $phoneCount) = $this->model->recentSubmitCounts('flood_report', 'reporter_phone', $ip, $phone);
        if ($ipCount >= PUBLIC_LIMIT_PER_IP_10MIN || $phoneCount >= PUBLIC_LIMIT_PER_PHONE_HOUR) {
            flood_json(array('chk' => false, 'msg' => 'ส่งรายงานถี่เกินไป กรุณารอสักครู่แล้วลองใหม่'));
        }

        $photos = array();
        try {
            $photos = flood_store_images('photos', 3);
            $accuracy = flood_in('accuracy', '', $_POST);
            $ref = $this->model->createReport(array(
                'lat' => round((float) $lat, 7),
                'lng' => round((float) $lng, 7),
                'accuracy_m' => is_numeric($accuracy) ? min(100000, max(0, (int) round($accuracy))) : null,
                'loc_method' => flood_in('loc_method', '', $_POST) === 'pin' ? 'pin' : 'gps',
                'depth' => $depth,
                'extent' => $extent,
                'houses' => array_key_exists($houses, flood_houses_options()) ? $houses : null,
                'vehicle' => array_key_exists($vehicle, flood_vehicle_options()) ? $vehicle : null,
                'trend' => array_key_exists($trend, flood_trend_options()) ? $trend : null,
                'impacts' => implode(',', flood_codes_filter(isset($_POST['impacts']) ? $_POST['impacts'] : array(), flood_area_impacts())) ?: null,
                'place_note' => mb_substr(flood_in('place_note', '', $_POST), 0, 255) ?: null,
                'reporter_name' => $name,
                'reporter_phone' => $phone,
                'status' => 'pending',
                'ip' => $ip,
                'user_agent' => flood_user_agent(),
            ), $photos);
        } catch (Exception $e) {
            $this->removeFiles($photos);
            error_log('[flood] report/save: ' . $e->getMessage());
            $msg = ($e instanceof PDOException) ? 'บันทึกไม่สำเร็จ กรุณาลองใหม่อีกครั้ง' : $e->getMessage();
            flood_json(array('chk' => false, 'msg' => $msg));
        }

        Session::set('flood_last_report', $ref['ref']);
        flood_json(array('chk' => true, 'ref' => $ref['ref'], 'url' => URL . 'report/done'));
    }

    /**
     * เปิดลิงก์ย่อ Google Maps (maps.app.goo.gl) เพื่อดูว่าพาไปที่ URL ไหน — คืน URL ปลายทางให้ JS อ่านพิกัดเอง
     * ตามต่อเฉพาะโดเมนของ Google เท่านั้น (กันถูกใช้เป็นทางยิงไปเครื่องอื่น) และไม่อ่านเนื้อหาหน้าเว็บ
     */
    function maplink() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            flood_json(array('chk' => false, 'msg' => 'ต้องส่งด้วยฟอร์ม'), 405);
        }
        if (!flood_csrf_valid()) {
            flood_json(array('chk' => false, 'csrf' => true, 'token' => flood_csrf_token(), 'msg' => 'กรุณาลองอีกครั้ง'));
        }
        // จำกัดต่อ session: 20 ครั้ง / 10 นาที
        $hits = array_filter((array) Session::get('flood_maplink_hits'), function ($t) { return $t > time() - 600; });
        if (count($hits) >= 20) {
            flood_json(array('chk' => false, 'msg' => 'ใช้งานถี่เกินไป กรุณารอสักครู่'));
        }
        $hits[] = time();
        Session::set('flood_maplink_hits', array_values($hits));

        $url = trim(flood_in('url', '', $_POST));
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        if (!function_exists('curl_init')) {
            flood_json(array('chk' => false, 'msg' => 'เซิร์ฟเวอร์อ่านลิงก์ย่อไม่ได้ — เปิดลิงก์ใน Google Maps แล้วคัดลอกลิงก์เต็มหรือพิกัดมาวางแทน'));
        }
        $allowed = '/^(maps\.app\.goo\.gl|goo\.gl|g\.co|(www\.|maps\.)?google\.(com|co\.th))$/i';
        for ($i = 0; $i < 6; $i++) {
            $host = (string) parse_url($url, PHP_URL_HOST);
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            if (!in_array($scheme, array('http', 'https'), true) || !preg_match($allowed, $host)) {
                break;
            }
            // เจอพิกัดแล้วไม่ต้องตามต่อ
            if (preg_match('/!3d-?\d|@-?\d{1,3}\.\d+,-?\d{1,3}\.\d+|[?&](q|ll|query)=-?\d/', rawurldecode($url))) {
                flood_json(array('chk' => true, 'url' => $url));
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_NOBODY => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (SK Flood map link reader)',
            ));
            $head = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($head === false || $code < 300 || $code >= 400 || !preg_match('/^location:\s*(\S+)/im', $head, $m)) {
                break;
            }
            $next = trim($m[1]);
            if (strpos($next, '/') === 0) {
                $next = $scheme . '://' . $host . $next;
            }
            // หน้ายินยอมคุกกี้ของ Google เก็บ URL จริงไว้ใน continue=
            $q = array();
            parse_str((string) parse_url($next, PHP_URL_QUERY), $q);
            if (preg_match('/^consent\.google\./i', (string) parse_url($next, PHP_URL_HOST)) && !empty($q['continue'])) {
                $next = $q['continue'];
            }
            $url = $next;
        }
        if (preg_match('/!3d-?\d|@-?\d{1,3}\.\d+,-?\d{1,3}\.\d+|[?&](q|ll|query)=-?\d/', rawurldecode($url))) {
            flood_json(array('chk' => true, 'url' => $url));
        }
        flood_json(array('chk' => false, 'msg' => 'อ่านพิกัดจากลิงก์นี้ไม่ได้ — เปิดลิงก์ใน Google Maps กดค้างที่จุดนั้น แล้วคัดลอกพิกัดมาวางแทน'));
    }

    function done() {
        // หน้านี้ไม่มีแผนที่/ฟอร์ม — ไม่ต้องโหลดสคริปต์ของฟอร์ม
        $this->view->useMap = false;
        $this->view->js = array();
        $this->view->ref = (string) Session::get('flood_last_report');
        $this->view->pageTitle = 'ส่งรายงานแล้ว';
        $this->view->rander('report/done');
    }

    private function removeFiles($photos) {
        foreach ((array) $photos as $p) {
            @unlink(flood_upload_dir() . '/' . $p['file_path']);
        }
    }

}
