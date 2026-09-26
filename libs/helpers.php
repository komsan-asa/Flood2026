<?php
/**
 * ตัวช่วยที่ใช้ได้ทั้ง controller และ view — โหลดจาก index.php ก่อน Session::init()
 * ห้ามเรียก Session ที่ระดับไฟล์ (session ยังไม่ถูก init ตอน require)
 */

/* ============================================================
 * พื้นฐาน
 * ============================================================ */

if (!function_exists('h')) {
    /** escape ข้อความก่อนพิมพ์ลง HTML — ใช้กับทุกค่าที่มาจากผู้ใช้/ฐานข้อมูล */
    function h($s) {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

function flood_json($data, $httpCode = 200) {
    if (!headers_sent()) {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** JSON สำหรับฝังใน <script> ให้ปลอดภัยจาก </script> และเครื่องหมายคำพูด */
function flood_js($data) {
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
}

function flood_is_ajax() {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function flood_client_ip() {
    return isset($_SERVER['REMOTE_ADDR']) ? substr((string) $_SERVER['REMOTE_ADDR'], 0, 45) : '';
}

function flood_user_agent() {
    return isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : '';
}

/** ค่าจาก $_POST/$_GET แบบตัดช่องว่าง — คืน '' ถ้าไม่มี */
function flood_in($key, $default = '', $src = null) {
    $src = $src === null ? $_REQUEST : $src;
    if (!isset($src[$key]) || is_array($src[$key])) {
        return $default;
    }
    return trim((string) $src[$key]);
}

/** version ของไฟล์ asset จากเวลาแก้ไขไฟล์ — เบราว์เซอร์จะโหลดใหม่เองเมื่อไฟล์เปลี่ยน */
function flood_asset_ver($relPath) {
    $f = dirname(__DIR__) . '/' . ltrim($relPath, '/');
    return @filemtime($f) ?: time();
}

/** ฐานข้อมูลเชื่อมต่อไม่ได้ — แจ้งอย่างสุภาพ ไม่เผยรายละเอียดการเชื่อมต่อ */
function flood_db_unavailable($e) {
    error_log('[flood] เชื่อมต่อฐานข้อมูลไม่ได้: ' . $e->getMessage());
    $msg = 'ระบบติดต่อฐานข้อมูลไม่ได้ชั่วคราว กรุณาลองใหม่อีกครั้ง';
    if (flood_is_ajax()) {
        flood_json(array('chk' => false, 'msg' => $msg), 503);
    }
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
    }
    $phone = defined('EMERGENCY_PHONE') ? EMERGENCY_PHONE : '1669';
    echo '<!DOCTYPE html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>ระบบขัดข้องชั่วคราว</title></head>'
        . '<body style="font-family:Tahoma,sans-serif;padding:32px;max-width:560px;margin:auto;color:#1f2937">'
        . '<h2 style="color:#155e9c">ระบบขัดข้องชั่วคราว</h2><p>' . h($msg) . '</p>'
        . '<p style="font-size:18px">เจ็บป่วยฉุกเฉิน โทร <a href="tel:' . h($phone) . '" style="color:#dc2626;font-weight:bold">' . h($phone) . '</a></p>'
        . '<p style="color:#6b7280;font-size:13px">ผู้ดูแลระบบ: ตรวจค่า DB_HOST / DB_USER / DB_PASS ใน config/app.php และดู error log ของ PHP</p>'
        . '</body></html>';
    exit;
}

/* ============================================================
 * CSRF — ทุก POST ต้องแนบ token (header X-CSRF-Token หรือฟิลด์ _csrf)
 * ============================================================ */

function flood_csrf_token() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    if (empty($_SESSION['flood_csrf'])) {
        $_SESSION['flood_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['flood_csrf'];
}

function flood_csrf_valid() {
    $sent = '';
    if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $sent = (string) $_SERVER['HTTP_X_CSRF_TOKEN'];
    } elseif (isset($_POST['_csrf']) && is_string($_POST['_csrf'])) {
        $sent = $_POST['_csrf'];
    }
    $tok = isset($_SESSION['flood_csrf']) ? (string) $_SESSION['flood_csrf'] : '';
    return $tok !== '' && $sent !== '' && hash_equals($tok, $sent);
}

/* ============================================================
 * ผู้ใช้ / สิทธิ์ — ตารางเมนูอยู่ที่นี่ที่เดียว แถบเมนูกับ controller ใช้ชุดเดียวกัน
 * ============================================================ */

function flood_session_user() {
    $u = class_exists('Session') ? Session::get('User_FLOOD') : null;
    return is_array($u) ? $u : array();
}

function flood_role_labels() {
    return array(
        'super_admin' => 'ผู้ดูแลสูงสุด',
        'admin' => 'ผู้ดูแลระบบ',
        'officer' => 'เจ้าหน้าที่ศูนย์',
        'team' => 'ทีมช่วยเหลือ',
        'viewer' => 'ผู้บริหาร/ดูอย่างเดียว',
    );
}

function flood_role_descriptions() {
    return array(
        'super_admin' => 'ทำได้ทุกอย่าง รวมถึงจัดการบัญชีผู้ดูแลระบบ',
        'admin' => 'ทุกเมนู จัดการผู้ใช้ ทีม และดูประวัติการใช้งาน',
        'officer' => 'ประกาศพื้นที่ ตรวจรายงานประชาชน รับเรื่อง/มอบหมายงาน ดูแลทะเบียนกลุ่มเปราะบาง',
        'team' => 'เห็นเฉพาะใบงานที่มอบหมายให้ทีมตัวเอง อัปเดตสถานะการช่วยเหลือ',
        'viewer' => 'ดูภาพรวมและพื้นที่ประกาศ ไม่แก้ไขข้อมูล',
    );
}

function flood_normalize_role($role) {
    $role = (string) $role;
    return array_key_exists($role, flood_role_labels()) ? $role : 'viewer';
}

function flood_role_label($role) {
    $labels = flood_role_labels();
    $code = flood_normalize_role($role);
    return $labels[$code];
}

function flood_is_admin_role($role) {
    return in_array((string) $role, array('super_admin', 'admin'), true);
}

/** เมนู => role ที่เข้าได้ (admin/super_admin เข้าได้ทุกเมนู) */
function flood_menu_roles() {
    return array(
        'stats' => array('officer', 'team', 'viewer'),
        'zones' => array('officer', 'viewer'),
        'reports' => array('officer'),
        'help' => array('officer', 'team'),
        'vulnerable' => array('officer'),
        'teams' => array('officer'),
        'settings' => array(),
    );
}

function flood_can_menu($role, $key) {
    if (flood_is_admin_role($role)) {
        return true;
    }
    $map = flood_menu_roles();
    return isset($map[$key]) && in_array((string) $role, $map[$key], true);
}

/* ============================================================
 * ตัวเลือกของข้อมูล (code => ชื่อไทย) — เก็บ code ภาษาอังกฤษลงฐาน แสดงชื่อไทยบนจอ
 * ============================================================ */

/* ============================================================
 * ระดับพื้นที่ประกาศ — ผู้ดูแลเพิ่ม/แก้ได้ที่ ผู้ดูแลระบบ → ระดับพื้นที่ (ตาราง flood_zone_level)
 * ยังไม่มีตาราง (ยังไม่รัน sql/08 และผู้ดูแลยังไม่เคยเปิดหน้านั้น) → ใช้ค่าตั้งต้นชุดนี้
 * order = ความรุนแรง (1 = รุนแรงสุด) ใช้เรียงรายการ และระดับที่รุนแรงกว่าวาดทับบนแผนที่
 * ============================================================ */

/** ค่าตั้งต้น — badge ระบุไว้ = สีที่ออกแบบไว้แล้วสำหรับตัวอักษรขาว (ไม่ระบุ = คำนวณให้เอง) */
function flood_zone_levels_builtin() {
    return array(
        'evacuated' => array('name' => 'อพยพแล้ว', 'color' => '#7c3aed', 'badge' => '#6d28d9', 'icon' => 'fa-bus',
            'order' => 1, 'desc' => 'อพยพประชาชนออกจากพื้นที่แล้ว / ห้ามเข้าพื้นที่'),
        'impassable' => array('name' => 'รถทุกชนิดผ่านไม่ได้', 'color' => '#be185d', 'icon' => 'fa-ship',
            'order' => 2, 'desc' => 'น้ำสูงมาก รถใหญ่ก็ผ่านไม่ได้ ต้องใช้เรือ'),
        'road_damaged' => array('name' => 'ถนนขาด / สะพานชำรุด', 'color' => '#44403c', 'icon' => 'fa-chain-broken',
            'order' => 3, 'desc' => 'ถนนขาด ทรุด หรือสะพานชำรุด ห้ามผ่าน ให้ใช้เส้นทางเลี่ยง'),
        'blocked' => array('name' => 'รถเล็กผ่านไม่ได้', 'color' => '#dc2626', 'badge' => '#b91c1c', 'icon' => 'fa-ban',
            'order' => 4, 'desc' => 'น้ำสูง รถเก๋ง/รถเล็กผ่านไม่ได้ (รถกระบะ/รถสูงยังผ่านได้)'),
        'watch' => array('name' => 'เฝ้าระวัง', 'color' => '#f59e0b', 'badge' => '#b45309', 'icon' => 'fa-eye',
            'order' => 5, 'desc' => 'น้ำท่วมขัง / ระดับน้ำสูงขึ้น ติดตามใกล้ชิด'),
        'receded' => array('name' => 'น้ำลดแล้ว (ยังต้องระวัง)', 'color' => '#0d9488', 'icon' => 'fa-level-down',
            'order' => 6, 'desc' => 'น้ำลดแล้ว แต่ยังมีโคลน ถนนเสียหาย หรือไฟฟ้ายังไม่ปลอดภัย'),
    );
}

/** ไอคอน (Font Awesome 4.7) ที่ผู้ดูแลเลือกให้ระดับได้ */
function flood_level_icons() {
    return array(
        'fa-bus' => 'รถบัส / อพยพ', 'fa-ship' => 'เรือ', 'fa-ban' => 'ห้ามผ่าน', 'fa-chain-broken' => 'ขาด / ชำรุด',
        'fa-road' => 'ถนน', 'fa-car' => 'รถยนต์', 'fa-truck' => 'รถใหญ่', 'fa-eye' => 'เฝ้าระวัง',
        'fa-tint' => 'น้ำ', 'fa-level-up' => 'น้ำขึ้น', 'fa-level-down' => 'น้ำลด', 'fa-exclamation-triangle' => 'อันตราย',
        'fa-bolt' => 'ไฟฟ้า', 'fa-home' => 'บ้าน', 'fa-life-ring' => 'ช่วยเหลือ', 'fa-medkit' => 'การแพทย์',
        'fa-times-circle' => 'ปิด', 'fa-check-circle' => 'ปลอดภัย', 'fa-info-circle' => 'ข้อมูล', 'fa-flag' => 'ธง',
        'fa-map-marker' => 'จุด', 'fa-circle' => 'วงกลม',
    );
}

/** สีแนะนำในหน้าตั้งค่า (แยกกันชัดบนแผนที่) */
function flood_level_color_presets() {
    return array('#7c3aed', '#be185d', '#dc2626', '#ea580c', '#f59e0b', '#ca8a04',
        '#16a34a', '#0d9488', '#0284c7', '#2563eb', '#44403c', '#64748b');
}

function flood_valid_hex($s) {
    return is_string($s) && preg_match('/^#[0-9a-fA-F]{6}$/', $s) === 1;
}

/** อัตราส่วนความต่างของสีกับพื้นขาว (WCAG) — ใช้ได้ทั้งตัวอักษรสีบนพื้นขาว และตัวอักษรขาวบนพื้นสี */
function flood_contrast_on_white($hex) {
    $lum = 0;
    $w = array(0.2126, 0.7152, 0.0722);
    foreach (array(1, 3, 5) as $i => $pos) {
        $c = hexdec(substr($hex, $pos, 2)) / 255;
        $c = $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
        $lum += $w[$i] * $c;
    }
    return 1.05 / ($lum + 0.05);
}

/** ทำสีเข้มขึ้นทีละนิดจนอ่านบนพื้นขาว (หรืออ่านตัวอักษรขาวบนสีนี้) ได้ตามอัตราส่วนที่ต้องการ */
function flood_color_on_white($hex, $ratio) {
    $rgb = array(hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2)));
    $out = strtolower($hex);
    for ($i = 0; $i < 40 && flood_contrast_on_white($out) < $ratio; $i++) {
        $rgb = array_map(function ($c) { return $c * 0.94; }, $rgb);
        $out = sprintf('#%02x%02x%02x', (int) round($rgb[0]), (int) round($rgb[1]), (int) round($rgb[2]));
    }
    return $out;
}

/** สีจาง (ผสมขาว) สำหรับพื้นหลังตัวเลือกที่ถูกเลือก */
function flood_color_tint($hex, $white) {
    $out = '#';
    foreach (array(1, 3, 5) as $pos) {
        $c = hexdec(substr($hex, $pos, 2));
        $out .= sprintf('%02x', (int) round($c + (255 - $c) * $white));
    }
    return $out;
}

/** สถานะแคชของ request นี้ (อ่านจากฐานครั้งเดียว) */
function &flood_levels_state() {
    static $state = array('all' => null, 'source' => 'builtin');
    return $state;
}

/** ล้างแคช — หลังผู้ดูแลแก้ระดับ ให้หน้าที่ตอบกลับใช้ค่าใหม่ */
function flood_zone_levels_reset() {
    $st = &flood_levels_state();
    $st['all'] = null;
}

/** 'db' = อ่านจากตาราง flood_zone_level / 'builtin' = ยังไม่มีตาราง ใช้ค่าตั้งต้นในโค้ด */
function flood_zone_levels_source() {
    flood_zone_levels_all();
    $st = &flood_levels_state();
    return $st['source'];
}

/**
 * ระดับทั้งหมดรวมที่ปิดใช้งานแล้ว เรียงตามความรุนแรง
 * code => array(name, desc, color, badge, text, icon, order, active, builtin_color)
 *   badge = พื้นป้ายตัวอักษรขาว (อ่านได้ ≥ 4.5:1) / text = สีตัวเลข-ไอคอนบนพื้นขาว (≥ 3:1)
 */
function flood_zone_levels_all() {
    $st = &flood_levels_state();
    if ($st['all'] !== null) {
        return $st['all'];
    }
    $builtin = flood_zone_levels_builtin();
    $db = class_exists('Model', false) ? Model::sharedDb() : null;
    $rows = null;
    if ($db) {
        try {
            $rows = $db->select("SELECT level_code, name, description, color, icon, sort_order, is_active
                FROM flood_zone_level ORDER BY sort_order, level_code");
        } catch (Exception $e) {
            $rows = null;   // ยังไม่มีตาราง → ใช้ค่าตั้งต้น
        }
    }
    $out = array();
    if ($rows) {
        foreach ($rows as $r) {
            $out[$r['level_code']] = array(
                'name' => $r['name'],
                'desc' => (string) $r['description'],
                'color' => flood_valid_hex($r['color']) ? strtolower($r['color']) : '#64748b',
                'icon' => array_key_exists($r['icon'], flood_level_icons()) ? $r['icon'] : 'fa-circle',
                'order' => (int) $r['sort_order'],
                'active' => (int) $r['is_active'] === 1,
            );
        }
        $source = 'db';
    } else {
        foreach ($builtin as $code => $l) {
            $out[$code] = array('name' => $l['name'], 'desc' => $l['desc'], 'color' => $l['color'],
                'icon' => $l['icon'], 'order' => $l['order'], 'active' => true);
        }
        uasort($out, function ($a, $b) { return $a['order'] - $b['order']; });
        $source = 'builtin';
    }
    foreach ($out as $code => $l) {
        // สีเดิมของระดับตั้งต้น = ใช้สีป้ายที่ออกแบบไว้ / สีที่ผู้ดูแลเลือกเอง = คำนวณให้อ่านออกเสมอ
        $same = isset($builtin[$code]) && strcasecmp($builtin[$code]['color'], $l['color']) === 0;
        $out[$code]['badge'] = $same && isset($builtin[$code]['badge']) ? $builtin[$code]['badge'] : flood_color_on_white($l['color'], 4.5);
        $out[$code]['text'] = flood_color_on_white($l['color'], 3.0);
        $out[$code]['builtin_color'] = $same;
    }
    if ($db) {   // ยังไม่ได้เชื่อมฐาน (เช่น หน้า error) → ไม่จำ เรียกครั้งหลังจะได้ค่าจากฐาน
        $st['all'] = $out;
        $st['source'] = $source;
    }
    return $out;
}

/** ระดับที่เลือกใช้ได้ (เปิดใช้งาน) — $includeInactive = true รวมที่ปิดแล้ว (ใช้แสดงพื้นที่เก่า) */
function flood_zone_levels($includeInactive = false) {
    $out = array();
    foreach (flood_zone_levels_all() as $code => $l) {
        if ($includeInactive || $l['active']) {
            $out[$code] = $l;
        }
    }
    return $out;
}

/** ระดับเดียว (รวมที่ปิดแล้ว) — รหัสที่ไม่รู้จักแสดงเป็นสีเทา */
function flood_level($code) {
    $all = flood_zone_levels_all();
    if (isset($all[$code])) {
        return $all[$code];
    }
    return array('name' => (string) $code, 'desc' => '', 'color' => '#64748b', 'badge' => '#475569', 'text' => '#475569',
        'icon' => 'fa-circle', 'order' => 999, 'active' => false, 'builtin_color' => false);
}

/**
 * CSS สีของทุกระดับ (header.php ใส่ในทุกหน้า) — ป้าย การ์ดตัวเลข ตัวเลือกในฟอร์ม และหน้าประชาชน
 * หน้าประชาชน (ธีมพาสเทล) ระบุสีของระดับตั้งต้นไว้ในไฟล์ของหน้าเอง: ระดับใหม่ใช้ --c จากที่นี่
 * ส่วนระดับตั้งต้นที่ผู้ดูแลเปลี่ยนสี ใส่ selector ที่เจาะจงกว่าเพื่อให้สีใหม่ชนะ
 */
function flood_level_css() {
    $css = '';
    foreach (flood_zone_levels_all() as $code => $l) {
        if (!preg_match('/^[a-z][a-z0-9_]{0,19}$/', $code)) {
            continue;
        }
        $c = $l['color'];
        $b = $l['badge'];
        $t = $l['text'];
        $css .= ".lv-$code{--lv:$c;--lv-badge:$b;--c:$t}"
            . ".lv-badge.lv-$code{background:$b}"
            . ".flood-kpi-card.lv-$code{box-shadow:inset 4px 0 0 $c}.flood-kpi-card.lv-$code .kpi-value{color:$b}"
            . ".chip.lv-$code input:checked+.chip-body{border-color:$c;background:" . flood_color_tint($c, 0.93) . "}";
        if (!$l['builtin_color']) {
            $css .= "body .sk-tile.lv-$code,body .sk-zone.lv-$code{--c:$t}";
        }
    }
    return $css;
}

/** ที่มาของพื้นที่ — arankub = นำเข้าจาก arankub.com (หน้า flood/zoneImport) ไม่แสดงเป็นตัวเลือกตอนประกาศใหม่ */
function flood_zone_sources() {
    return array(
        'officer' => 'เจ้าหน้าที่ประกาศ',
        'ddpm' => 'ประกาศ ปภ.',
        'report' => 'จากรายงานประชาชน',
        'arankub' => 'นำเข้าจาก arankub.com',
    );
}

function flood_depth_options() {
    return array(
        'ankle' => array('name' => 'ถึงข้อเท้า', 'hint' => '~15 ซม.', 'emoji' => '🦶'),
        'knee' => array('name' => 'ถึงเข่า', 'hint' => '~50 ซม.', 'emoji' => '🦵'),
        'waist' => array('name' => 'ถึงเอว', 'hint' => '~100 ซม.', 'emoji' => '🧍'),
        'above_waist' => array('name' => 'สูงกว่าเอว', 'hint' => 'เกิน 100 ซม.', 'emoji' => '⚠️'),
    );
}

/** ความกว้างที่ตาเห็น → รัศมีเริ่มต้นเมื่อแปลงเป็นพื้นที่ประกาศ */
function flood_extent_options() {
    return array(
        'spot' => array('name' => 'เฉพาะจุดนี้ / แอ่งเล็ก', 'hint' => 'เห็นขอบน้ำรอบตัว เดินพ้นได้', 'emoji' => '🕳️', 'radius' => 80),
        'road' => array('name' => 'เต็มถนนช่วงหนึ่ง', 'hint' => 'ประมาณ 1 ช่วงซอย', 'emoji' => '🛣️', 'radius' => 200),
        'soi' => array('name' => 'ทั้งซอย / หลายบ้าน', 'hint' => 'ท่วมต่อเนื่องหลายหลัง', 'emoji' => '🏘️', 'radius' => 400),
        'wide' => array('name' => 'กว้างจนมองไม่เห็นขอบน้ำ', 'hint' => 'มองไปทางไหนก็เป็นน้ำ', 'emoji' => '🌊', 'radius' => 1000),
    );
}

function flood_houses_options() {
    return array(
        'none' => 'ยังไม่มีบ้านไหนน้ำเข้า',
        '1_3' => '1–3 หลัง',
        '4_10' => '4–10 หลัง',
        '10_plus' => 'มากกว่า 10 หลัง',
    );
}

function flood_vehicle_options() {
    return array(
        'car' => array('name' => 'รถเก๋งผ่านได้', 'emoji' => '🚗'),
        'high' => array('name' => 'เฉพาะรถกระบะ/รถสูง', 'emoji' => '🛻'),
        'none' => array('name' => 'รถผ่านไม่ได้', 'emoji' => '🚫'),
        'boat' => array('name' => 'ต้องใช้เรือ', 'emoji' => '🚤'),
    );
}

function flood_trend_options() {
    return array(
        'rising' => array('name' => 'กำลังขึ้น', 'emoji' => '📈'),
        'steady' => array('name' => 'ทรงตัว', 'emoji' => '➡️'),
        'falling' => array('name' => 'กำลังลด', 'emoji' => '📉'),
    );
}

function flood_report_statuses() {
    return array(
        'pending' => array('name' => 'รอตรวจสอบ', 'class' => 'label-warning'),
        'verified' => array('name' => 'ยืนยันแล้ว', 'class' => 'label-success'),
        'rejected' => array('name' => 'ไม่ใช้ข้อมูล', 'class' => 'label-default'),
    );
}

/**
 * สถานะที่แสดงในหน้ารายงาน — "ยืนยันแล้ว" (verified) แยกตามพื้นที่ที่ผูกไว้:
 *   waiting   = ยืนยันแล้วแต่ยังไม่มีพื้นที่ประกาศ หรือพื้นที่ที่ผูกไว้ปิดประกาศไปแล้ว → ยังไม่ขึ้นแผนที่สาธารณะ
 *   announced = ผูกกับพื้นที่ที่ประกาศอยู่ → ประชาชนเห็นบนแผนที่
 */
function flood_report_view_statuses() {
    return array(
        'pending' => array('name' => 'รอตรวจสอบ', 'class' => 'label-warning', 'color' => '#1f78c1'),
        'waiting' => array('name' => 'ยืนยันแล้ว · รอประกาศ', 'class' => 'label-wait-announce', 'color' => '#ea580c'),
        'announced' => array('name' => 'ประกาศแล้ว', 'class' => 'label-success', 'icon' => 'fa-bullhorn', 'color' => '#16a34a'),
        'rejected' => array('name' => 'ไม่ใช้ข้อมูล', 'class' => 'label-default', 'color' => '#9ca3af'),
    );
}

/** $r ต้องมี status, zone_id, zone_status (จาก LEFT JOIN flood_zone) */
function flood_report_view_status($r) {
    if ($r['status'] !== 'verified') {
        return $r['status'];
    }
    return (!empty($r['zone_id']) && isset($r['zone_status']) && $r['zone_status'] === 'active') ? 'announced' : 'waiting';
}

/** สายด่วน ปภ. (กรมป้องกันและบรรเทาสาธารณภัย) */
function flood_ddpm_phone() {
    return defined('DDPM_PHONE') && DDPM_PHONE !== '' ? DDPM_PHONE : '1784';
}

function flood_help_needs() {
    return array(
        'evacuate' => array('name' => 'ต้องการอพยพออก', 'hint' => 'น้ำสูง ออกเองไม่ได้', 'emoji' => '🚤'),
        'medicine' => array('name' => 'ยาประจำตัวหมด', 'hint' => 'ยาเบาหวาน ความดัน ยาจิตเวช ฯลฯ', 'emoji' => '💊'),
        'sick' => array('name' => 'เจ็บป่วย ต้องพบหมอ', 'hint' => 'ไม่ถึงขั้นฉุกเฉินวิกฤต', 'emoji' => '🩺'),
        'shelter' => array('name' => 'ต้องการที่พักชั่วคราว', 'hint' => 'บ้านอยู่ไม่ได้ ไม่มีที่ไป', 'emoji' => '⛺'),
        'food' => array('name' => 'ขาดอาหาร', 'hint' => '', 'emoji' => '🍚'),
        'water' => array('name' => 'ขาดน้ำดื่ม', 'hint' => '', 'emoji' => '💧'),
        'no_power' => array('name' => 'ไฟฟ้าถูกตัด/ไฟดับ', 'hint' => 'เช่น ต้องใช้เครื่องผลิตออกซิเจน', 'emoji' => '🔌'),
        'no_tap' => array('name' => 'น้ำประปาถูกตัด', 'hint' => 'ไม่มีน้ำใช้', 'emoji' => '🚰'),
        'other' => array('name' => 'เรื่องอื่น ๆ', 'hint' => '', 'emoji' => '❓'),
    );
}

/**
 * ผลกระทบในพื้นที่ที่ประชาชนแจ้งมากับรายงานจุดน้ำท่วม (เลือกได้หลายข้อ ไม่บังคับ)
 * เป็นข้อมูลระดับชุมชน — ถ้าบ้านใดต้องการความช่วยเหลือเอง ให้ส่งคำขอ SOS แยก
 */
function flood_area_impacts() {
    return array(
        'no_power' => array('name' => 'ถูกตัดไฟฟ้า/ไฟดับ', 'emoji' => '🔌'),
        'no_tap' => array('name' => 'ถูกตัดน้ำประปา', 'emoji' => '🚰'),
        'food' => array('name' => 'ขาดอาหาร', 'emoji' => '🍚'),
        'water' => array('name' => 'ขาดน้ำดื่ม', 'emoji' => '💧'),
        'shelter' => array('name' => 'ต้องการที่พักชั่วคราว', 'emoji' => '⛺'),
    );
}

/** กลุ่มเปราะบางที่อยู่ในบ้านผู้ขอความช่วยเหลือ */
function flood_help_flags() {
    return array(
        'bedridden' => 'ผู้ป่วยติดเตียง',
        'elderly' => 'ผู้สูงอายุ',
        'child' => 'เด็กเล็ก',
        'pregnant' => 'หญิงตั้งครรภ์',
        'disabled' => 'ผู้พิการ',
    );
}

function flood_help_statuses() {
    return array(
        'new' => array('name' => 'รับเรื่องใหม่', 'class' => 'label-danger', 'icon' => 'fa-bell'),
        'verified' => array('name' => 'โทรยืนยันแล้ว', 'class' => 'label-warning', 'icon' => 'fa-phone'),
        'assigned' => array('name' => 'มอบหมายทีมแล้ว', 'class' => 'label-info', 'icon' => 'fa-users'),
        'in_progress' => array('name' => 'กำลังช่วยเหลือ', 'class' => 'label-primary', 'icon' => 'fa-life-ring'),
        'done' => array('name' => 'ช่วยเหลือแล้ว', 'class' => 'label-success', 'icon' => 'fa-check'),
        'cancelled' => array('name' => 'ยกเลิก', 'class' => 'label-default', 'icon' => 'fa-times'),
    );
}

function flood_help_open_statuses() {
    return array('new', 'verified', 'assigned', 'in_progress');
}

function flood_priorities() {
    return array(
        'urgent' => array('name' => 'ด่วนมาก', 'class' => 'label-danger', 'order' => 1),
        'high' => array('name' => 'ด่วน', 'class' => 'label-warning', 'order' => 2),
        'normal' => array('name' => 'ปกติ', 'class' => 'label-default', 'order' => 3),
    );
}

/**
 * ความเร่งด่วนเริ่มต้นจากสิ่งที่ขอ + กลุ่มเปราะบางในบ้าน (เจ้าหน้าที่ปรับได้ภายหลัง)
 * - ด่วนมาก: ต้องอพยพและมีกลุ่มเปราะบาง / เจ็บป่วยและมีผู้ป่วยติดเตียงหรือหญิงตั้งครรภ์
 *   / ไฟดับและมีผู้ป่วยติดเตียง
 * - ด่วน: ต้องอพยพ / เจ็บป่วย / ยาประจำตัวหมด / ต้องการที่พักชั่วคราว / ขาดน้ำดื่ม
 */
function flood_help_priority($needs, $flags) {
    $needs = (array) $needs;
    $flags = (array) $flags;
    if (in_array('evacuate', $needs, true) && $flags) {
        return 'urgent';
    }
    if (in_array('sick', $needs, true) && array_intersect($flags, array('bedridden', 'pregnant'))) {
        return 'urgent';
    }
    // ไฟดับในบ้านที่มีผู้ป่วยติดเตียง (อาจใช้เครื่องผลิตออกซิเจน/ที่นอนลม)
    if (in_array('no_power', $needs, true) && in_array('bedridden', $flags, true)) {
        return 'urgent';
    }
    if (array_intersect($needs, array('evacuate', 'sick', 'medicine', 'shelter', 'water'))) {
        return 'high';
    }
    return 'normal';
}

function flood_vulnerable_groups() {
    return array(
        'bedridden' => 'ผู้ป่วยติดเตียง',
        'elderly' => 'ผู้สูงอายุ',
        'disabled' => 'ผู้พิการ',
        'dialysis' => 'ผู้ป่วยฟอกไต',
        'oxygen' => 'ใช้ออกซิเจน/เครื่องช่วยหายใจ',
        'pregnant' => 'หญิงตั้งครรภ์',
        'infant' => 'เด็กเล็ก',
        'psychiatric' => 'ผู้ป่วยจิตเวช',
        'other' => 'อื่น ๆ',
    );
}

function flood_mobility_options() {
    return array(
        'walk' => 'เดินได้เอง',
        'assisted' => 'ต้องมีคนพยุง',
        'wheelchair' => 'ใช้รถเข็น',
        'bedridden' => 'ติดเตียง ต้องใช้เปล/เตียงสนาม',
    );
}

function flood_evac_statuses() {
    return array(
        'normal' => array('name' => 'ยังไม่ได้ติดต่อ', 'class' => 'label-default'),
        'alerted' => array('name' => 'แจ้งเตือนแล้ว', 'class' => 'label-warning'),
        'evacuated' => array('name' => 'อพยพแล้ว', 'class' => 'label-success'),
        'shelter_in_place' => array('name' => 'อยู่ที่เดิม ปลอดภัย', 'class' => 'label-info'),
        'admitted' => array('name' => 'รับไว้ใน รพ.', 'class' => 'label-primary'),
    );
}

function flood_team_types() {
    return array(
        'hospital' => 'โรงพยาบาล/หน่วยแพทย์',
        'rescue' => 'กู้ภัย/มูลนิธิ',
        'local_gov' => 'อปท./อำเภอ',
        'military' => 'ทหาร/ตำรวจ',
        'volunteer' => 'อาสาสมัคร/อสม.',
        'other' => 'อื่น ๆ',
    );
}

/* ---------- ป้ายสถานะสำหรับ view ---------- */

function flood_level_badge($code) {
    $l = flood_level($code);
    return '<span class="lv-badge lv-' . h($code) . '" style="background:' . h($l['badge']) . '"><i class="fa ' . h($l['icon']) . '"></i> '
        . h($l['name']) . '</span>';
}

/** ป้ายจากตัวเลือกที่มี 'class' (สถานะรายงาน / สถานะใบงาน / ความเร่งด่วน / สถานะอพยพ) */
/** รายงานรอตรวจสอบแสดงบนแผนที่ประชาชน (ป้าย "รอตรวจสอบ") — ปิดได้ด้วย define('PUBLIC_SHOW_PENDING', false) */
function flood_public_pending_enabled() {
    return !defined('PUBLIC_SHOW_PENDING') || PUBLIC_SHOW_PENDING;
}

function flood_status_label($options, $code) {
    if (!isset($options[$code])) {
        return '<span class="label label-default">' . h($code) . '</span>';
    }
    $o = $options[$code];
    $icon = isset($o['icon']) ? '<i class="fa ' . h($o['icon']) . '"></i> ' : '';
    return '<span class="label ' . h($o['class']) . '">' . $icon . h($o['name']) . '</span>';
}

function flood_tags($names, $class = '') {
    $out = '';
    foreach ((array) $names as $n) {
        $out .= '<span class="tag ' . h($class) . '">' . h($n) . '</span>';
    }
    return $out;
}

/** แถว select option — $selected เทียบแบบ string */
function flood_options($options, $selected = '', $emptyLabel = null) {
    $out = $emptyLabel !== null ? '<option value="">' . h($emptyLabel) . '</option>' : '';
    foreach ($options as $code => $v) {
        $name = is_array($v) ? $v['name'] : $v;
        $out .= '<option value="' . h($code) . '"' . ((string) $selected === (string) $code ? ' selected' : '') . '>' . h($name) . '</option>';
    }
    return $out;
}

/** ชื่อไทยจาก code (รองรับทั้ง array แบบ code => 'ชื่อ' และ code => array('name' => …)) */
function flood_opt_name($options, $code, $fallback = '') {
    if (!isset($options[$code])) {
        return $fallback !== '' ? $fallback : (string) $code;
    }
    $v = $options[$code];
    return is_array($v) ? $v['name'] : $v;
}

/** รับค่าหลายตัวเลือก (array หรือ "a,b") แล้วกรองเหลือเฉพาะ code ที่รู้จัก เรียงตามลำดับตัวเลือก */
function flood_codes_filter($input, $options) {
    if (!is_array($input)) {
        $input = $input === null || $input === '' ? array() : explode(',', (string) $input);
    }
    $input = array_map('trim', array_map('strval', $input));
    $out = array();
    foreach (array_keys($options) as $code) {
        if (in_array((string) $code, $input, true)) {
            $out[] = (string) $code;
        }
    }
    return $out;
}

function flood_codes_names($str, $options) {
    $out = array();
    foreach (flood_codes_filter($str, $options) as $code) {
        $out[] = flood_opt_name($options, $code);
    }
    return $out;
}

/* ============================================================
 * เบอร์โทร
 * ============================================================ */

function flood_normalize_phone($s) {
    $d = preg_replace('/[^0-9+]/', '', (string) $s);
    if (strpos($d, '+66') === 0) {
        $d = '0' . substr($d, 3);
    } elseif (strpos($d, '66') === 0 && strlen($d) === 11) {
        $d = '0' . substr($d, 2);
    }
    return str_replace('+', '', $d);
}

/** เบอร์ไทย 9–10 หลัก ขึ้นต้นด้วย 0 */
function flood_valid_phone($d) {
    return (bool) preg_match('/^0[0-9]{8,9}$/', (string) $d);
}

function flood_format_phone($d) {
    $d = (string) $d;
    if (preg_match('/^(0\d{2})(\d{3})(\d{4})$/', $d, $m)) {
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
    if (preg_match('/^(0\d)(\d{3})(\d{4})$/', $d, $m)) {
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
    return $d;
}

/* ============================================================
 * วันเวลา (แสดงแบบไทย พ.ศ.)
 * ============================================================ */

function flood_thai_months_short() {
    return array(1 => 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.');
}

/** '2026-09-24 15:53:00' → '24 ก.ย. 69 15:53' */
function flood_thai_date($dt, $withTime = true, $withYear = true) {
    if (empty($dt) || strpos((string) $dt, '0000') === 0) {
        return '';
    }
    $ts = is_numeric($dt) ? (int) $dt : strtotime((string) $dt);
    if (!$ts) {
        return '';
    }
    $m = flood_thai_months_short();
    $out = (int) date('j', $ts) . ' ' . $m[(int) date('n', $ts)];
    if ($withYear) {
        $out .= ' ' . sprintf('%02d', ((int) date('Y', $ts) + 543) % 100);
    }
    if ($withTime) {
        $out .= ' ' . date('H:i', $ts);
    }
    return $out;
}

function flood_ago_label($seconds) {
    if ($seconds === null) {
        return '—';
    }
    $s = max(0, (int) $seconds);
    if ($s < 60) {
        return 'เมื่อสักครู่';
    }
    if ($s < 3600) {
        return floor($s / 60) . ' นาทีที่แล้ว';
    }
    if ($s < 86400) {
        return floor($s / 3600) . ' ชม.ที่แล้ว';
    }
    return floor($s / 86400) . ' วันที่แล้ว';
}

function flood_ago($dt) {
    if (empty($dt)) {
        return '—';
    }
    $ts = strtotime((string) $dt);
    return $ts ? flood_ago_label(time() - $ts) : '—';
}

function flood_age_years($birthDate) {
    $b = trim((string) $birthDate);
    if ($b === '' || strpos($b, '0000') === 0) {
        return '';
    }
    try {
        $d = new DateTime(substr($b, 0, 10));
    } catch (Exception $e) {
        return '';
    }
    $now = new DateTime('today');
    if ($d > $now) {
        return '';
    }
    return (int) $d->diff($now)->y;
}

/** ส่วนวันที่ของเลขอ้างอิง: ปี พ.ศ. 2 หลัก + เดือน + วัน เช่น 690924 */
function flood_ref_date_part($ts = null) {
    $ts = $ts === null ? time() : $ts;
    return sprintf('%02d%s', ((int) date('Y', $ts) + 543) % 100, date('md', $ts));
}

/* ============================================================
 * พิกัด / พื้นที่
 * ============================================================ */

/** พิกัดอยู่ในประเทศไทยโดยประมาณ — กันค่าเพี้ยน (0,0) หรือสลับ lat/lng */
function flood_valid_latlng($lat, $lng) {
    if (!is_numeric($lat) || !is_numeric($lng)) {
        return false;
    }
    $lat = (float) $lat;
    $lng = (float) $lng;
    return $lat >= 5.0 && $lat <= 21.0 && $lng >= 97.0 && $lng <= 106.5;
}

function flood_haversine_m($lat1, $lng1, $lat2, $lng2) {
    $r = 6371000.0;
    $p1 = deg2rad((float) $lat1);
    $p2 = deg2rad((float) $lat2);
    $dp = $p2 - $p1;
    $dl = deg2rad((float) $lng2 - (float) $lng1);
    $a = sin($dp / 2) * sin($dp / 2) + cos($p1) * cos($p2) * sin($dl / 2) * sin($dl / 2);
    return 2 * $r * atan2(sqrt($a), sqrt(1 - $a));
}

/** ray casting — $poly = array(array(lat, lng), ...) */
function flood_point_in_polygon($lat, $lng, $poly) {
    $inside = false;
    $n = count($poly);
    if ($n < 3) {
        return false;
    }
    $x = (float) $lng;
    $y = (float) $lat;
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        $xi = (float) $poly[$i][1];
        $yi = (float) $poly[$i][0];
        $xj = (float) $poly[$j][1];
        $yj = (float) $poly[$j][0];
        $intersect = (($yi > $y) !== ($yj > $y))
            && ($x < ($xj - $xi) * ($y - $yi) / (($yj - $yi) ?: 1e-12) + $xi);
        if ($intersect) {
            $inside = !$inside;
        }
    }
    return $inside;
}

/** แปลง/ตรวจ polygon จาก JSON — คืน null ถ้าใช้ไม่ได้ */
function flood_parse_polygon($json) {
    $arr = is_array($json) ? $json : json_decode((string) $json, true);
    if (!is_array($arr)) {
        return null;
    }
    $out = array();
    foreach ($arr as $p) {
        if (!is_array($p) || count($p) < 2) {
            return null;
        }
        $lat = isset($p[0]) ? $p[0] : (isset($p['lat']) ? $p['lat'] : null);
        $lng = isset($p[1]) ? $p[1] : (isset($p['lng']) ? $p['lng'] : null);
        if (!flood_valid_latlng($lat, $lng)) {
            return null;
        }
        $out[] = array(round((float) $lat, 7), round((float) $lng, 7));
    }
    if (count($out) < 3 || count($out) > 500) {
        return null;
    }
    return $out;
}

/** จุดกึ่งกลาง polygon (ถ่วงพื้นที่) — ใช้ปักป้ายชื่อและเลื่อนแผนที่ */
function flood_polygon_centroid($poly) {
    $n = count($poly);
    $a = 0.0;
    $cx = 0.0;
    $cy = 0.0;
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        $f = $poly[$j][1] * $poly[$i][0] - $poly[$i][1] * $poly[$j][0];
        $a += $f;
        $cx += ($poly[$j][1] + $poly[$i][1]) * $f;
        $cy += ($poly[$j][0] + $poly[$i][0]) * $f;
    }
    if (abs($a) < 1e-14) {
        $sLat = 0.0;
        $sLng = 0.0;
        foreach ($poly as $p) {
            $sLat += $p[0];
            $sLng += $p[1];
        }
        return array(round($sLat / $n, 7), round($sLng / $n, 7));
    }
    $a *= 0.5;
    return array(round($cy / (6 * $a), 7), round($cx / (6 * $a), 7));
}

/** พื้นที่โดยประมาณ (ตร.กม.) */
function flood_zone_area_km2($zone) {
    if ($zone['shape'] === 'circle') {
        $r = (float) $zone['radius_m'];
        return M_PI * $r * $r / 1e6;
    }
    $poly = isset($zone['poly']) ? $zone['poly'] : flood_parse_polygon($zone['polygon_json']);
    if (!$poly) {
        return 0;
    }
    $lat0 = deg2rad($poly[0][0]);
    $k = 111320.0;
    $sum = 0.0;
    $n = count($poly);
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        $xi = $poly[$i][1] * $k * cos($lat0);
        $yi = $poly[$i][0] * $k;
        $xj = $poly[$j][1] * $k * cos($lat0);
        $yj = $poly[$j][0] * $k;
        $sum += ($xj * $yi - $xi * $yj);
    }
    return abs($sum) / 2 / 1e6;
}

/** เตรียมแถวพื้นที่สำหรับตรวจจุด: แปลง polygon + กรอบสี่เหลี่ยมครอบ (ตัดทิ้งเร็ว) */
function flood_zone_prepare($zone) {
    if ($zone['shape'] === 'polygon') {
        $poly = flood_parse_polygon($zone['polygon_json']);
        $zone['poly'] = $poly ? $poly : array();
        $lats = array();
        $lngs = array();
        foreach ($zone['poly'] as $p) {
            $lats[] = $p[0];
            $lngs[] = $p[1];
        }
        $zone['bbox'] = $lats ? array(min($lats), max($lats), min($lngs), max($lngs)) : array(0, 0, 0, 0);
    } else {
        $r = (float) $zone['radius_m'];
        $dLat = $r / 111320.0;
        $dLng = $r / (111320.0 * max(0.2, cos(deg2rad((float) $zone['center_lat']))));
        $zone['bbox'] = array(
            (float) $zone['center_lat'] - $dLat, (float) $zone['center_lat'] + $dLat,
            (float) $zone['center_lng'] - $dLng, (float) $zone['center_lng'] + $dLng,
        );
    }
    return $zone;
}

/** จุดนี้อยู่ในพื้นที่ประกาศไหม ($zone ผ่าน flood_zone_prepare แล้ว) */
function flood_point_in_zone($lat, $lng, $zone) {
    $lat = (float) $lat;
    $lng = (float) $lng;
    $b = $zone['bbox'];
    if ($lat < $b[0] || $lat > $b[1] || $lng < $b[2] || $lng > $b[3]) {
        return false;
    }
    if ($zone['shape'] === 'polygon') {
        return flood_point_in_polygon($lat, $lng, $zone['poly']);
    }
    return flood_haversine_m($lat, $lng, $zone['center_lat'], $zone['center_lng']) <= (float) $zone['radius_m'];
}

/** ค่าตั้งต้นของแผนที่ที่ส่งให้ JavaScript */
function flood_map_config() {
    // levels = ระดับที่เปิดใช้ (คำอธิบายสี/ตัวกรอง/ตัวเลขสรุป) · levelsAll = รวมที่ปิดแล้ว (ใช้แสดงพื้นที่เก่า)
    $levels = array();
    $all = array();
    foreach (flood_zone_levels_all() as $code => $l) {
        $x = array('name' => $l['name'], 'color' => $l['color'], 'badge' => $l['badge'], 'text' => $l['text'],
            'icon' => $l['icon'], 'order' => $l['order']);
        $all[$code] = $x;
        if ($l['active']) {
            $levels[$code] = $x;
        }
    }
    return array(
        // ระบบครอบคลุมหลายจังหวัด → เปิดแผนที่ทั้งภูมิภาค (ตั้งเองได้ด้วย REGION_CENTER_LAT/LNG, REGION_ZOOM)
        'center' => array(defined('REGION_CENTER_LAT') ? (float) REGION_CENTER_LAT : 13.2,
            defined('REGION_CENTER_LNG') ? (float) REGION_CENTER_LNG : 101.0),
        'zoom' => defined('REGION_ZOOM') ? (int) REGION_ZOOM : 6,
        'tileUrl' => MAP_TILE_URL,
        'attribution' => MAP_TILE_ATTRIBUTION,
        'levels' => $levels,
        'levelsAll' => $all,
    );
}

/* ============================================================
 * อัปโหลดรูป — ย่อขนาด/ลบข้อมูล EXIF ด้วย GD (ถ้ามี) แล้วเก็บนอกทางเข้าตรงของเว็บ
 * ============================================================ */

function flood_upload_dir() {
    return dirname(__DIR__) . '/public/uploads/flood';
}

/**
 * รับรูปจาก $_FILES[$field] (input multiple) → คืน array ของไฟล์ที่บันทึกแล้ว
 * โยน Exception พร้อมข้อความภาษาไทยเมื่อไฟล์ใช้ไม่ได้
 */
/** ค่า php.ini แบบ 2M / 512K / 1G → bytes */
function flood_ini_bytes($v) {
    $v = trim((string) $v);
    if ($v === '' || $v === '-1' || $v === '0') {
        return 0;
    }
    $n = (float) $v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024;
        case 'm': $n *= 1024;
        case 'k': $n *= 1024;
    }
    return (int) $n;
}

/** ขนาดรูปต่อไฟล์ที่เซิร์ฟเวอร์รับได้จริง (ดูทั้ง upload_max_filesize และ post_max_size ÷ 3 รูป) */
function flood_upload_limit() {
    $lim = array_filter(array(
        flood_ini_bytes(ini_get('upload_max_filesize')),
        (int) floor(flood_ini_bytes(ini_get('post_max_size')) / 3.3),
    ));
    return $lim ? min($lim) : 12582912;
}

function flood_store_images($field, $maxFiles = 3, $maxBytes = 12582912, $publish = false) {
    if (empty($_FILES[$field]) || !isset($_FILES[$field]['name'])) {
        return array();
    }
    $f = $_FILES[$field];
    $files = array();
    if (is_array($f['name'])) {
        foreach ($f['name'] as $i => $name) {
            $files[] = array(
                'name' => $name, 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i],
            );
        }
    } else {
        $files[] = $f;
    }
    $files = array_values(array_filter($files, function ($x) {
        return (int) $x['error'] !== UPLOAD_ERR_NO_FILE;
    }));
    if (!$files) {
        return array();
    }
    if (count($files) > $maxFiles) {
        throw new Exception('แนบรูปได้ไม่เกิน ' . $maxFiles . ' รูป');
    }
    if ($publish && !flood_has_gd()) {
        throw new Exception(flood_no_gd_message());
    }
    $allowed = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp');
    $subdir = date('Ym');
    $dir = flood_upload_dir() . '/' . $subdir;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        throw new Exception('เซิร์ฟเวอร์บันทึกรูปไม่ได้ (สร้างโฟลเดอร์ไม่ได้)');
    }
    $out = array();
    try {
        foreach ($files as $x) {
            if (in_array((int) $x['error'], array(UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE), true)) {
                error_log('[flood] รูปใหญ่เกิน upload_max_filesize=' . ini_get('upload_max_filesize') . ' (' . (int) $x['size'] . ' bytes)');
                throw new Exception('รูปใหญ่เกินที่เซิร์ฟเวอร์รับได้ — ลองเลือกรูปใหม่ หรือส่งโดยไม่แนบรูป');
            }
            if ((int) $x['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('อัปโหลดรูปไม่สำเร็จ (รหัส ' . (int) $x['error'] . ') ลองใหม่หรือส่งโดยไม่แนบรูป');
            }
            if ((int) $x['size'] <= 0 || (int) $x['size'] > $maxBytes) {
                throw new Exception('รูปใหญ่เกินไป (ไม่เกิน ' . round($maxBytes / 1048576) . ' MB ต่อรูป)');
            }
            if (!is_uploaded_file($x['tmp_name'])) {
                throw new Exception('ไฟล์อัปโหลดไม่ถูกต้อง');
            }
            $mime = '';
            if (function_exists('finfo_open')) {
                $fi = finfo_open(FILEINFO_MIME_TYPE);
                $mime = (string) finfo_file($fi, $x['tmp_name']);
                finfo_close($fi);
            } else {
                $info = @getimagesize($x['tmp_name']);
                $mime = $info ? (string) $info['mime'] : '';
            }
            if (!isset($allowed[$mime]) || !@getimagesize($x['tmp_name'])) {
                throw new Exception('รองรับเฉพาะรูป JPG / PNG / WEBP');
            }
            // ภาพที่จะแสดงต่อสาธารณะ — เข้ารหัสใหม่ทุกภาพ + ภาพย่อ
            if ($publish) {
                $out[] = flood_publish_image($x['tmp_name'], $mime, basename((string) $x['name']));
                continue;
            }
            $base = bin2hex(random_bytes(16));
            $saved = flood_reencode_image($x['tmp_name'], $mime, $dir . '/' . $base . '.jpg');
            if ($saved) {
                $rel = $subdir . '/' . $base . '.jpg';
                $outMime = 'image/jpeg';
            } else {
                $rel = $subdir . '/' . $base . '.' . $allowed[$mime];
                if (!move_uploaded_file($x['tmp_name'], $dir . '/' . $base . '.' . $allowed[$mime])) {
                    throw new Exception('บันทึกรูปไม่สำเร็จ');
                }
                $outMime = $mime;
            }
            $out[] = array(
                'file_path' => $rel,
                'orig_name' => mb_substr(basename((string) $x['name']), 0, 200),
                'mime' => $outMime,
                'size_bytes' => (int) @filesize(flood_upload_dir() . '/' . $rel),
            );
        }
    } catch (Exception $e) {
        // รูปที่บันทึกไปแล้วในรอบนี้ไม่มีใครอ้างถึง — ลบทิ้ง
        foreach ($out as $o) {
            flood_remove_stored_image($o['file_path']);
        }
        throw $e;
    }
    return $out;
}

/**
 * ย่อรูปให้ด้านยาวไม่เกิน 1600px และบันทึกเป็น JPEG ใหม่ (ข้อมูล EXIF/พิกัดในรูปหลุดทิ้งไปด้วย)
 * คืน false ถ้าเครื่องไม่มี GD — ผู้เรียกจะเก็บไฟล์เดิมแทน
 */
function flood_reencode_image($src, $mime, $dest, $maxDim = 1600, $quality = 82) {
    if (!function_exists('imagecreatetruecolor')) {
        return false;
    }
    $img = false;
    if ($mime === 'image/jpeg' && function_exists('imagecreatefromjpeg')) {
        $img = @imagecreatefromjpeg($src);
    } elseif ($mime === 'image/png' && function_exists('imagecreatefrompng')) {
        $img = @imagecreatefrompng($src);
    } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
        $img = @imagecreatefromwebp($src);
    }
    if (!$img) {
        return false;
    }
    // หมุนตาม EXIF ของกล้องมือถือ
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($src);
        $o = isset($exif['Orientation']) ? (int) $exif['Orientation'] : 1;
        if ($o === 3) {
            $img = imagerotate($img, 180, 0);
        } elseif ($o === 6) {
            $img = imagerotate($img, -90, 0);
        } elseif ($o === 8) {
            $img = imagerotate($img, 90, 0);
        }
    }
    $w = imagesx($img);
    $h = imagesy($img);
    $scale = min(1.0, $maxDim / max($w, $h));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefill($dst, 0, 0, $white);
    imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $ok = imagejpeg($dst, $dest, (int) $quality);
    imagedestroy($img);
    imagedestroy($dst);
    return $ok;
}

/* ============================================================
 * ภาพประกอบพื้นที่ประกาศ — แสดงในบอลลูนบนแผนที่สาธารณะ
 * เก็บใน flood_attachment ref_type = 'zone' (ไฟล์ของตัวเอง ไม่ใช้ร่วมกับรูปรายงาน)
 * ============================================================ */

/** จำนวนภาพสูงสุดต่อพื้นที่ (ตั้ง ZONE_PHOTO_MAX ใน config/app.php ได้) */
function flood_zone_photo_max() {
    return defined('ZONE_PHOTO_MAX') ? max(1, min(20, (int) ZONE_PHOTO_MAX)) : 6;
}

/** เครื่องมี PHP GD พอสำหรับย่อรูปและลบข้อมูล EXIF หรือไม่ */
function flood_has_gd() {
    return function_exists('imagecreatetruecolor') && function_exists('imagejpeg') && function_exists('imagecreatefromjpeg');
}

function flood_no_gd_message() {
    return 'เซิร์ฟเวอร์ยังไม่มี PHP GD จึงเผยแพร่ภาพไม่ได้ (ให้ผู้ดูแลติดตั้ง php-gd แล้วลองใหม่)';
}

/** ชื่อไฟล์ภาพย่อ: 202609/abc.jpg → 202609/abc_t.jpg */
function flood_thumb_path($rel) {
    return preg_replace('/\.[A-Za-z0-9]+$/', '', (string) $rel) . '_t.jpg';
}

/** ลบไฟล์รูป (และภาพย่อถ้ามี) — ยอมเฉพาะไฟล์ในโฟลเดอร์อัปโหลด */
function flood_remove_stored_image($rel) {
    $base = realpath(flood_upload_dir());
    if (!$base || $rel === '' || $rel === null) {
        return;
    }
    foreach (array($rel, flood_thumb_path($rel)) as $p) {
        $file = realpath(flood_upload_dir() . '/' . $p);
        if ($file && strpos($file, $base . DIRECTORY_SEPARATOR) === 0 && is_file($file)) {
            @unlink($file);
        }
    }
}

/**
 * ทำภาพสำหรับเผยแพร่: เข้ารหัส JPEG ใหม่ (ด้านยาว 1600px ไม่มี EXIF/พิกัดกล้อง) + ภาพย่อ 640px สำหรับบอลลูน
 * $src = ไฟล์ต้นทาง (ไฟล์อัปโหลด หรือรูปรายงานประชาชนที่เก็บไว้แล้ว) — ไฟล์ต้นทางไม่ถูกแตะต้อง
 * โยน Exception ข้อความภาษาไทยเมื่อทำไม่ได้
 */
function flood_publish_image($src, $mime, $origName = '') {
    if (!flood_has_gd()) {
        throw new Exception(flood_no_gd_message());
    }
    $subdir = date('Ym');
    $dir = flood_upload_dir() . '/' . $subdir;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        throw new Exception('เซิร์ฟเวอร์บันทึกรูปไม่ได้ (สร้างโฟลเดอร์ไม่ได้)');
    }
    $rel = $subdir . '/' . bin2hex(random_bytes(16)) . '.jpg';
    $full = flood_upload_dir() . '/' . $rel;
    // ภาพย่อทำจากภาพที่เข้ารหัสใหม่แล้ว (หมุนตามกล้องแล้ว เล็กกว่า อ่านเร็วกว่า)
    if (!flood_reencode_image($src, $mime, $full, 1600, 82)
        || !flood_reencode_image($full, 'image/jpeg', flood_upload_dir() . '/' . flood_thumb_path($rel), 640, 76)) {
        flood_remove_stored_image($rel);
        throw new Exception('อ่านรูปนี้ไม่ได้ ลองใช้รูปอื่น');
    }
    return array(
        'file_path' => $rel,
        'orig_name' => mb_substr((string) $origName, 0, 200),
        'mime' => 'image/jpeg',
        'size_bytes' => (int) @filesize($full),
    );
}

/* ============================================================
 * จังหวัด — ระบบครอบคลุมหลายจังหวัด (ภาคตะวันออก)
 * รหัสอำเภอ 4 หลัก: 2 หลักแรก = รหัสจังหวัด (กรมการปกครอง) เช่น 2706 → 27 สระแก้ว
 * ============================================================ */

/** ชื่อพื้นที่ที่ระบบครอบคลุม (ตั้งได้ด้วย REGION_NAME ใน config/app.php) */
function flood_region_name() {
    return defined('REGION_NAME') && REGION_NAME !== '' ? REGION_NAME : 'ประเทศไทย';
}

/** 6 ภาค (ราชบัณฑิตยสถาน) — ลำดับนี้ใช้ในตัวกรองทุกหน้า */
function flood_regions() {
    return array(
        'north' => 'ภาคเหนือ',
        'northeast' => 'ภาคตะวันออกเฉียงเหนือ',
        'central' => 'ภาคกลาง',
        'east' => 'ภาคตะวันออก',
        'west' => 'ภาคตะวันตก',
        'south' => 'ภาคใต้',
    );
}

/** รหัสภาคจาก request (ไม่รู้จัก = '') */
function flood_region_param($key = 'region', $src = null) {
    $v = flood_in($key, '', $src === null ? $_GET : $src);
    return array_key_exists($v, flood_regions()) ? $v : '';
}

/** <select> ภาค — data-rg-for = id ของ select จังหวัด (รายการจังหวัดเหลือเฉพาะภาคที่เลือก) */
function flood_region_select($regions, $attrs = array(), $selected = '', $allLabel = 'ทุกภาค') {
    $a = '';
    foreach ($attrs as $k => $v) {
        $a .= ' ' . h($k) . '="' . h($v) . '"';
    }
    $html = '<select' . $a . '><option value="">' . h($allLabel) . '</option>';
    foreach ((array) $regions as $r) {
        $html .= '<option value="' . h($r['region']) . '"' . ((string) $selected === (string) $r['region'] ? ' selected' : '')
            . '>' . h($r['name']) . '</option>';
    }
    return $html . '</select>';
}

/** รหัสจังหวัดของอำเภอ/ตำบล */
function flood_province_of($code) {
    $code = (string) $code;
    return strlen($code) >= 2 ? substr($code, 0, 2) : '';
}

/** รหัสจังหวัด 2 หลักจาก request (ค่าผิดรูปแบบ = '') */
function flood_province_param($key = 'province', $src = null) {
    $v = flood_in($key, '', $src === null ? $_GET : $src);
    return preg_match('/^\d{2}$/', $v) ? $v : '';
}

/**
 * <select> จังหวัด — ใช้คู่กับ select อำเภอ (data-pv-for = id ของ select อำเภอ)
 * public/js/pagejscript.js จะกรองรายการอำเภอให้เหลือเฉพาะจังหวัดที่เลือก
 */
function flood_province_select($provinces, $attrs = array(), $selected = '', $allLabel = 'ทุกจังหวัด') {
    $a = '';
    foreach ($attrs as $k => $v) {
        $a .= ' ' . h($k) . '="' . h($v) . '"';
    }
    $html = '<select' . $a . '><option value="">' . h($allLabel) . '</option>';
    // หลายภาค → แบ่งกลุ่มตามภาค (สระแก้ว/จังหวัดที่เรียงไว้ก่อน อยู่กลุ่มแรก)
    $groups = array();
    foreach ((array) $provinces as $p) {
        $rg = isset($p['region']) ? (string) $p['region'] : '';
        if (!isset($groups[$rg])) {
            $groups[$rg] = array('name' => isset($p['region_name']) && $p['region_name'] !== '' ? $p['region_name'] : 'จังหวัด', 'opts' => '');
        }
        $groups[$rg]['opts'] .= '<option value="' . h($p['province_code']) . '" data-rg="' . h($rg) . '"'
            . ((string) $selected === (string) $p['province_code'] ? ' selected' : '') . '>จ.' . h($p['name']) . '</option>';
    }
    foreach ($groups as $rg => $g) {
        $html .= count($groups) > 1 ? '<optgroup label="' . h($g['name']) . '" data-rg="' . h($rg) . '">' . $g['opts'] . '</optgroup>' : $g['opts'];
    }
    return $html . '</select>';
}

/**
 * <option> อำเภอ แบ่งกลุ่มตามจังหวัด (มีจังหวัดเดียว = ไม่แบ่งกลุ่ม)
 * @param string $prefix ข้อความนำหน้าชื่อ เช่น 'อ.'
 */
function flood_amphoe_options($amphoes, $selected = '', $prefix = '') {
    $groups = array();
    foreach ((array) $amphoes as $a) {
        $pv = isset($a['province_code']) ? $a['province_code'] : flood_province_of($a['amphoe_code']);
        if (!isset($groups[$pv])) {
            $groups[$pv] = array('name' => isset($a['province_name']) ? $a['province_name'] : $pv, 'items' => array());
        }
        $groups[$pv]['items'][] = $a;
    }
    $html = '';
    foreach ($groups as $pv => $g) {
        $opts = '';
        foreach ($g['items'] as $a) {
            $opts .= '<option value="' . h($a['amphoe_code']) . '" data-pv="' . h($pv) . '"'
                . ((string) $selected === (string) $a['amphoe_code'] ? ' selected' : '') . '>' . h($prefix . $a['name']) . '</option>';
        }
        $html .= count($groups) > 1 ? '<optgroup label="จ.' . h($g['name']) . '" data-pv="' . h($pv) . '">' . $opts . '</optgroup>' : $opts;
    }
    return $html;
}
