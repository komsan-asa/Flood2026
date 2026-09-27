<?php
$isApp = isset($this->pageMenu) && $this->pageMenu === 'flood';
if ($isApp) {
    echo '</div>'; // .flood-content
    echo '<div class="px-6 pb-6 text-xs text-ink-400">' . h(SHORT_NAME_SYSTEM) . ' · ' . h(DEPARTMENT_NAME) . ' · <span class="sk-dev-credit-app">Developed by Komsan Asa</span></div>';
    echo '</div>'; // .flood-main
}
?>
<div class="flood-toast-wrap" id="floodToast"></div>
<?php if ($isApp) { ?>
<script src="<?= URL ?>public/js/flood-refresh.js?v=<?= flood_asset_ver('public/js/flood-refresh.js') ?>"></script>
<?php
    // ซิงก์จุดน้ำท่วมบนทางหลวง (กรมทางหลวง HDMS) ผ่านเบราว์เซอร์ของเจ้าหน้าที่ — officer ขึ้นไปเท่านั้น
    $fu = flood_session_user();
    if ($fu && in_array(isset($fu['role']) ? $fu['role'] : '', array('super_admin', 'admin', 'officer'), true)) {
        require_once 'models/hdms_model.php';
        if (Hdms_Model::enabled()) {
            $hl = Hdms_Model::lastSync(); ?>
<script>window.FLOOD_HDMS = <?= flood_js(array('last' => (int) ($hl['at'] ?? 0), 'every' => Hdms_Model::SYNC_EVERY)) ?>;</script>
<script src="<?= URL ?>public/js/flood-hdms.js?v=<?= flood_asset_ver('public/js/flood-hdms.js') ?>"></script>
<?php   }
    }
} ?>
</body>
</html>
