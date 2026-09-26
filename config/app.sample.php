<?php

// ---- ที่อยู่ของระบบบนเว็บ ----
// ตรวจจากตำแหน่ง index.php เอง: Z:\Flood2026 บนเว็บเซิร์ฟเวอร์ → http://<server>/Flood2026/
// ถ้าเซิร์ฟเวอร์ตั้งค่าแบบพิเศษ (alias/proxy) ให้กำหนดตายตัว เช่น $basePath = '/Flood2026/';
$basePath = '/Flood2026/';
if (php_sapi_name() !== 'cli' && isset($_SERVER['SCRIPT_NAME'])) {
    $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/';
    // nginx/proxy บางแบบส่ง SCRIPT_NAME เป็น /index.php เมื่อเปิดหน้าย่อย (เช่น /Flood2026/report)
    // → ตัดส่วน ?url= ออกจาก REQUEST_URI แทน จะได้ /Flood2026/ เสมอ
    $reqPath = isset($_SERVER['REQUEST_URI']) ? (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) : '';
    $route = isset($_GET['url']) ? trim((string) $_GET['url'], '/') : '';
    if ($route !== '' && $reqPath !== '') {
        $reqPath = rtrim(rawurldecode($reqPath), '/');
        if (substr($reqPath, -strlen($route)) === $route) {
            $basePath = rtrim(substr($reqPath, 0, -strlen($route)), '/') . '/';
        }
    }
}
define('BASE_PATH', $basePath);
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
define('IS_HTTPS', $isHttps);
define('URL', ($isHttps ? 'https' : 'http') . '://' . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost') . $basePath);

// ---- ฐานข้อมูล ----
define('DB_TYPE', 'mysql');
define('DB_HOST', '192.168.0.18');
define('DB_PORT', 3306);
define('DB_NAME', 'db_flood');
define('DB_USER', '<ชื่อผู้ใช้ฐานข้อมูล>');
define('DB_PASS', '<รหัสผ่านฐานข้อมูล>');

// ---- ชื่อระบบ ----
define('SYSTEM_NAME', 'ระบบช่วยเหลือผู้ประสบภัยน้ำท่วม จังหวัดสระแก้ว');
define('SYSTEM_NAME_THAI', 'ระบบช่วยเหลือผู้ประสบภัยน้ำท่วม จังหวัดสระแก้ว');
define('DEPARTMENT_NAME', 'โรงพยาบาลสมเด็จพระยุพราชสระแก้ว');
define('TITLE_SYSTEM_NAME', 'SK Flood — สถานการณ์น้ำ จังหวัดสระแก้ว');
define('SHORT_NAME_SYSTEM', 'SK Flood');
define('PROVINCE_NAME', 'สระแก้ว');

// ผู้ดูแลระบบที่ให้ผู้ใช้ติดต่อเมื่อสิทธิ์ไม่พอ (แสดงบนหน้าจอ)
define('SYSTEM_ADMIN_CONTACT', '');

// ---- เบอร์โทรบนหน้าสาธารณะ ----
define('EMERGENCY_PHONE', '1669');          // เจ็บป่วยฉุกเฉิน
define('DDPM_PHONE', '1784');              // สายด่วน ปภ. (แจ้งเหตุสาธารณภัย/น้ำท่วม)
define('HOTLINE_PHONE', '');                // เบอร์ศูนย์ประสานน้ำท่วมของโรงพยาบาล (ว่าง = ไม่แสดง)

// ---- แผนที่ ----
define('MAP_CENTER_LAT', 13.80);            // กึ่งกลางจังหวัดสระแก้วโดยประมาณ
define('MAP_CENTER_LNG', 102.30);
define('MAP_ZOOM', 9);
// ระบบครอบคลุมทั้งประเทศ (77 จังหวัด กรองตามภาค/จังหวัด/อำเภอได้) — ชื่อและมุมมองแผนที่เริ่มต้น
// ใช้เฉพาะภูมิภาคเดียว: เช่น 'ภาคตะวันออก' 13.15 / 101.75 / 8 แล้วปิดจังหวัดอื่นที่ flood_province.is_active = 0
define('REGION_NAME', 'ประเทศไทย');
define('REGION_CENTER_LAT', 13.2);
define('REGION_CENTER_LNG', 101.0);
define('REGION_ZOOM', 6);
define('MAP_TILE_URL', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png');
define('MAP_TILE_ATTRIBUTION', '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors');

// ---- ผู้ใช้งานออนไลน์ ----
// ถือว่า "ออนไลน์" ถ้ามีการใช้งานภายในกี่นาที (หน้าจอที่เปิดค้างไว้ส่งสัญญาณทุก 60 วินาที)
define('ONLINE_MINUTES', 3);

// ---- กันสแปมฟอร์มสาธารณะ (แจ้งจุดน้ำ / ขอความช่วยเหลือ) ----
// เครือข่ายมือถือใช้ IP ร่วมกันหลายคน อย่าตั้งต่ำเกินไป
define('PUBLIC_LIMIT_PER_IP_10MIN', 30);    // ต่อ IP ต่อ 10 นาที
define('PUBLIC_LIMIT_PER_PHONE_HOUR', 6);   // ต่อเบอร์โทร ต่อ 1 ชั่วโมง

// ---- ประวัติการแก้ไขข้อมูล (flood_audit_log) ----
// ค่าเริ่มต้นเมื่อไม่ประกาศ: เปิดบนเว็บ / ปิดบน CLI
// define('AUDIT_LOG_ENABLED', true);
// define('AUDIT_SKIP_TABLES', '');
