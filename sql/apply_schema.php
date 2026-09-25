<?php
/**
 * สร้างฐานข้อมูล db_flood และตารางทั้งหมด (รันซ้ำได้ ไม่ลบข้อมูลเดิม)
 *
 *   php sql/apply_schema.php            ← รันไฟล์ sql/NN_*.sql ทุกไฟล์ตามลำดับเลข
 *   php sql/apply_schema.php 08         ← รันเฉพาะไฟล์ที่ขึ้นต้นด้วย 08
 *
 * ใช้ค่าการเชื่อมต่อจาก config/app.php (DB_HOST / DB_USER / DB_PASS / DB_NAME)
 * บัญชีฐานข้อมูลต้องมีสิทธิ์ CREATE DATABASE หรือสร้างฐาน db_flood ไว้ก่อนแล้ว
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
chdir($root);
date_default_timezone_set('Asia/Bangkok');

$config = file_exists($root . '/config/app.php') ? $root . '/config/app.php' : $root . '/config/app.sample.php';
require $config;

$only = isset($argv[1]) ? preg_replace('/[^0-9a-z_]/i', '', $argv[1]) : '';

echo "เชื่อมต่อ " . DB_USER . "@" . DB_HOST . ":" . (defined('DB_PORT') ? DB_PORT : 3306) . " ...\n";
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . (defined('DB_PORT') ? ';port=' . DB_PORT : '') . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10)
    );
} catch (PDOException $e) {
    fwrite(STDERR, "เชื่อมต่อไม่ได้: " . $e->getMessage() . "\n");
    exit(1);
}
$ver = $pdo->query('SELECT VERSION()')->fetchColumn();
echo "เซิร์ฟเวอร์ฐานข้อมูล: $ver\n";

$db = str_replace('`', '', DB_NAME);
try {
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (PDOException $e) {
    echo "(ข้าม CREATE DATABASE: " . $e->getMessage() . ")\n";
}
$pdo->exec("USE `$db`");
$pdo->exec("SET NAMES utf8mb4");
$pdo->exec("SET time_zone = '+07:00'");

$files = glob($root . '/sql/[0-9][0-9]*_*.sql');
sort($files, SORT_STRING);
$total = 0;
foreach ($files as $file) {
    $name = basename($file);
    if ($only !== '' && strpos($name, $only) !== 0) {
        continue;
    }
    $statements = flood_split_sql(file_get_contents($file));
    $n = 0;
    foreach ($statements as $sql) {
        try {
            $pdo->exec($sql);
            $n++;
        } catch (PDOException $e) {
            // ALTER ซ้ำ (คอลัมน์/ดัชนีมีอยู่แล้ว) ถือว่าปกติ — ฐานเคยรันไฟล์นี้แล้ว
            $code = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
            if (in_array($code, array(1060, 1061, 1826, 1022), true)) {
                echo "  - ข้าม (มีอยู่แล้ว): " . $e->errorInfo[2] . "\n";
                continue;
            }
            fwrite(STDERR, "\n[ผิดพลาด] $name\n" . $e->getMessage() . "\nSQL: " . mb_substr($sql, 0, 300) . "\n");
            exit(1);
        }
    }
    $total += $n;
    echo "✓ $name ($n คำสั่ง)\n";
}

$tables = $pdo->query("SHOW TABLES LIKE 'flood\\_%'")->fetchAll(PDO::FETCH_COLUMN);
echo "\nเสร็จ — รันไป $total คำสั่ง / ตารางในฐาน $db: " . count($tables) . " ตาราง\n";
echo implode(', ', $tables) . "\n";

/** แยกไฟล์ .sql เป็นคำสั่ง — รู้จักข้อความในเครื่องหมายคำพูดและคอมเมนต์ -- / # */
function flood_split_sql($sql) {
    $out = array();
    $buf = '';
    $len = strlen($sql);
    $quote = null;
    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        if ($quote !== null) {
            $buf .= $c;
            if ($c === '\\' && $i + 1 < $len) {
                $buf .= $sql[++$i];
                continue;
            }
            if ($c === $quote) {
                $quote = null;
            }
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') {
            $quote = $c;
            $buf .= $c;
            continue;
        }
        if (($c === '-' && substr($sql, $i, 3) === '-- ') || $c === '#') {
            $nl = strpos($sql, "\n", $i);
            $i = $nl === false ? $len : $nl;
            $buf .= "\n";
            continue;
        }
        if ($c === ';') {
            if (trim($buf) !== '') {
                $out[] = trim($buf);
            }
            $buf = '';
            continue;
        }
        $buf .= $c;
    }
    if (trim($buf) !== '') {
        $out[] = trim($buf);
    }
    return $out;
}
