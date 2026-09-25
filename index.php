<?php

// PHP 8.x: undefined vars/props เป็น Warning — ปิด Notice/Deprecated ไว้เหมือนระบบ COC
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);

date_default_timezone_set('Asia/Bangkok');

require 'libs/Bootstrap.php';
require 'libs/Controller.php';
require 'libs/Model.php';
require 'libs/View.php';
require_once 'libs/Audit.php';
require 'libs/Database.php';
require 'libs/Session.php';
require 'libs/helpers.php';
require 'config/paths.php';

$configApp = __DIR__ . '/config/app.php';
$configSample = __DIR__ . '/config/app.sample.php';
if (file_exists($configApp)) {
    require $configApp;
} elseif (file_exists($configSample)) {
    require $configSample;
    if (php_sapi_name() !== 'cli') {
        echo '<!DOCTYPE html><html lang="th"><head><meta charset="utf-8"><title>ติดตั้งระบบ</title></head><body>';
        echo '<p>กรุณาคัดลอก <code>config/app.sample.php</code> เป็น <code>config/app.php</code> แล้วใส่รหัสผ่านฐานข้อมูล</p></body></html>';
        exit;
    }
} else {
    die('ไม่พบไฟล์ config');
}

Session::init();
$app = new Bootstrap();
