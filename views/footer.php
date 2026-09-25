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
<?php } ?>
</body>
</html>
