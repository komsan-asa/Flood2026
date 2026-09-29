<?php

/**
 * นำเข้าฝนรายวันจากสถานีกรมชลประทาน (ต้องล็อกอิน · เจ้าหน้าที่ศูนย์/ผู้ดูแลระบบ) — ดู models/rain_model.php
 *
 *   POST rain/import   data = JSON {station_code, station_name, rows: [{date: "Y-m-d", mm: 12.4}, …]}, card = 1 (อัปเดตการ์ด SAT)
 *   GET  rain/summary  สรุปล่าสุด (JSON)
 */
class Rain extends Controller {

    public function loadModel($name) {
        require_once 'models/rain_model.php';
        $this->model = new Rain_Model();
    }

    private function allowed() {
        $u = flood_session_user();
        $role = flood_normalize_role(isset($u['role']) ? $u['role'] : '');
        return $u && flood_can_menu($role, 'sat') && (flood_is_admin_role($role) || $role === 'officer') && empty($u['must_change_password']);
    }

    function index() {
        header('Location: ' . URL . 'sat');
        exit;
    }

    function import() {
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            flood_json(array('chk' => false, 'msg' => 'ต้องส่งด้วย POST'), 405);
        }
        if (!flood_csrf_valid()) {
            flood_json(array('chk' => false, 'csrf' => true, 'msg' => 'หน้าจอหมดอายุ กรุณารีเฟรชแล้วลองใหม่'), 403);
        }
        if (!$this->allowed()) {
            flood_json(array('chk' => false, 'msg' => 'เฉพาะเจ้าหน้าที่ศูนย์หรือผู้ดูแลระบบ'), 403);
        }
        if (!$this->model->ensureTable()) {
            flood_json(array('chk' => false, 'msg' => 'สร้างตาราง flood_rain ไม่ได้ — ให้ผู้ดูแลตรวจสิทธิ์ฐานข้อมูล'));
        }
        $raw = isset($_POST['data']) && is_string($_POST['data']) ? $_POST['data'] : '';
        $p = Rain_Model::parse(json_decode($raw, true));
        if (is_string($p)) {
            flood_json(array('chk' => false, 'msg' => $p));
        }
        try {
            $n = $this->model->import($p);
            $s = $this->model->summary($p['code']);
            $card = '';
            if ((string) flood_in('card', '1', $_POST) !== '0') {
                $u = flood_session_user();
                $card = $this->model->updateSatCard($s, !empty($u['user_id']) ? (int) $u['user_id'] : null);
            }
        } catch (Exception $e) {
            error_log('[flood] rain/import: ' . $e->getMessage());
            flood_json(array('chk' => false, 'msg' => 'บันทึกไม่สำเร็จ'));
        }
        $cardMsg = $card === 'updated' ? 'อัปเดตการ์ดฝนแล้ว' : ($card === 'kept' ? 'ไม่แตะการ์ดฝน (SAT เพิ่งแก้เองภายใน ' . Rain_Model::MANUAL_HOLD_HOURS . ' ชม.)' : '');
        flood_json(array('chk' => true, 'rows' => $n, 'card' => $card, 'status' => $s['status'],
            'summary' => Rain_Model::sentence($s),
            'msg' => 'นำเข้าฝน ' . $n . ' วัน · ' . $s['station_name'] . ' · ' . Rain_Model::sentence($s) . ($cardMsg !== '' ? ' · ' . $cardMsg : '')));
    }

    function summary() {
        if (!$this->allowed()) {
            flood_json(array('chk' => false, 'msg' => 'เฉพาะเจ้าหน้าที่ศูนย์หรือผู้ดูแลระบบ'), 403);
        }
        $s = $this->model->summary();
        flood_json(array('chk' => (bool) $s, 'summary' => $s, 'text' => $s ? Rain_Model::sentence($s) : ''));
    }
}
