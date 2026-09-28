<?php

class Login extends Controller {

    function __construct() {
        parent::__construct();
        $this->view->js = array('login/js/default.js');
        $this->view->css = array('flood/css/sat.css', 'login/css/default.css');
    }

    function index() {
        $u = flood_session_user();
        if (!empty($u['user_id'])) {
            header('Location: ' . URL . 'flood');
            exit;
        }
        $this->view->notice = mb_substr(flood_in('m', '', $_GET), 0, 120);
        $this->view->ov = $this->overview();
        $this->view->hosp = $this->hospital();
        $this->view->pageTitle = 'ภาพรวม · เข้าสู่ระบบเจ้าหน้าที่';
        $this->view->rander('login/index');
    }

    /** ภาพรวมโรงพยาบาล (ชุดเดียวกับหน้า sat/overview) — ตัวเลขรวมเท่านั้น · ผิดพลาดแล้วข้ามส่วนนี้ */
    private function hospital() {
        try {
            require_once 'controllers/sat.php';
            return Sat::publicOverview();
        } catch (Exception $e) {
            error_log('[flood] login hospital overview: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * ภาพรวมสาธารณะบนหน้าเข้าสู่ระบบ — ตัวเลขรวมเท่านั้น (ไม่มีชื่อ เบอร์ ที่อยู่ หรือพิกัดรายบุคคล)
     * ส่วนไหนผิดพลาด (เช่น ยังไม่มีตาราง) ข้ามส่วนนั้น หน้าเข้าสู่ระบบยังใช้ได้
     */
    private function overview() {
        $ov = array('updated_th' => flood_thai_date(time()), 'zones' => null, 'help' => null, 'reports' => null,
            'needs' => array(), 'amphoe' => array(), 'province' => array(), 'daily' => array(), 'levels' => array());
        try {
            require_once 'models/flood_model.php';
            $fm = new Flood_Model();
        } catch (Exception $e) {
            error_log('[flood] login overview: ' . $e->getMessage());
            return $ov;
        }
        $open = "('new','verified','assigned','in_progress')";
        try {
            $ov['zones'] = $fm->zoneCounts();
            $ov['levels'] = flood_zone_levels();
        } catch (Exception $e) {
            error_log('[flood] login overview zones: ' . $e->getMessage());
        }
        try {
            $ov['help'] = $fm->helpCounts();
        } catch (Exception $e) {
            error_log('[flood] login overview help: ' . $e->getMessage());
        }
        try {
            $ov['reports'] = $fm->reportCounts();
        } catch (Exception $e) {
            error_log('[flood] login overview reports: ' . $e->getMessage());
        }
        try {
            $needs = flood_help_needs();
            $cnt = array_fill_keys(array_keys($needs), 0);
            foreach ($fm->db->select("SELECT needs FROM flood_help WHERE status IN $open") as $r) {
                foreach (explode(',', (string) $r['needs']) as $c) {
                    $c = trim($c);
                    if (isset($cnt[$c])) {
                        $cnt[$c]++;
                    }
                }
            }
            foreach ($needs as $code => $n) {
                $ov['needs'][] = array('name' => $n['emoji'] . ' ' . $n['name'], 'c' => $cnt[$code]);
            }
        } catch (Exception $e) {
            error_log('[flood] login overview needs: ' . $e->getMessage());
        }
        try {
            $ov['amphoe'] = $fm->db->select(
                "SELECT COALESCE(a.name, 'ไม่ระบุอำเภอ') AS name, COUNT(*) AS c
                 FROM flood_help h LEFT JOIN flood_amphoe a ON a.amphoe_code = h.amphoe_code
                 WHERE h.status IN $open GROUP BY h.amphoe_code, a.name ORDER BY c DESC LIMIT 10");
        } catch (Exception $e) {
            error_log('[flood] login overview amphoe: ' . $e->getMessage());
        }
        try {
            $ov['province'] = $fm->db->select(
                "SELECT COALESCE(p.name, 'ไม่ระบุจังหวัด') AS name, COUNT(*) AS c
                 FROM flood_zone z LEFT JOIN flood_province p ON p.province_code = LEFT(z.amphoe_code, 2)
                 WHERE z.status = 'active' GROUP BY p.province_code, p.name ORDER BY c DESC LIMIT 10");
        } catch (Exception $e) {
            error_log('[flood] login overview province: ' . $e->getMessage());
        }
        try {
            $since = date('Y-m-d 00:00:00', strtotime('-13 days'));
            $days = array();
            for ($i = 13; $i >= 0; $i--) {
                $d = date('Y-m-d', strtotime("-$i days"));
                $days[$d] = array('d' => flood_thai_date($d, false), 'help' => 0, 'report' => 0);
            }
            foreach ($fm->db->select("SELECT DATE(created_at) AS d, COUNT(*) AS c FROM flood_help WHERE created_at >= :s GROUP BY DATE(created_at)",
                array(':s' => $since)) as $r) {
                if (isset($days[$r['d']])) {
                    $days[$r['d']]['help'] = (int) $r['c'];
                }
            }
            foreach ($fm->db->select("SELECT DATE(created_at) AS d, COUNT(*) AS c FROM flood_report WHERE created_at >= :s GROUP BY DATE(created_at)",
                array(':s' => $since)) as $r) {
                if (isset($days[$r['d']])) {
                    $days[$r['d']]['report'] = (int) $r['c'];
                }
            }
            // ตัดวันต้นช่วงที่ยังไม่มีข้อมูลเลย
            $rows = array_values($days);
            while ($rows && $rows[0]['help'] === 0 && $rows[0]['report'] === 0) {
                array_shift($rows);
            }
            $ov['daily'] = $rows;
        } catch (Exception $e) {
            error_log('[flood] login overview daily: ' . $e->getMessage());
        }
        return $ov;
    }

    function run() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            flood_json(array('chk' => false, 'error_log' => 'ต้องส่งด้วยฟอร์ม'), 405);
        }
        $this->model->dataRun();
    }

    function logout() {
        $u = Session::get('User_FLOOD');
        if (is_array($u) && $this->model) {
            $this->model->logLogout($u);
        }
        Session::destroy();
        header('Location: ' . URL . 'login');
        exit();
    }

}
