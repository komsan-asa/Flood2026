<?php
$User = flood_session_user();
$pageMenu = isset($this->pageMenu) ? (string) $this->pageMenu : '';
$activeTab = isset($this->activeTab) ? $this->activeTab : '';

if ($pageMenu === 'flood') {
    require __DIR__ . '/flood/_sidebar.php';
    echo '<div class="flood-main">';
    require __DIR__ . '/flood/_topbar.php';
    echo '<div class="flood-content">';
} elseif (empty($this->noNavbar)) {
    $hot = defined('HOTLINE_PHONE') ? HOTLINE_PHONE : '';
?>
<nav class="sticky top-0 z-[1000] border-b border-ink-200 bg-white/95 backdrop-blur">
    <div class="mx-auto flex max-w-3xl items-center justify-between gap-2 px-4 py-2.5">
        <a href="<?= URL ?>" class="flex items-center gap-2 font-bold text-brand-700 hover:no-underline">
            <img src="<?= URL ?>public/img/favicon.svg" alt="" width="28" height="28" />
            <span><?= h(SHORT_NAME_SYSTEM) ?></span>
        </a>
        <div class="flex items-center gap-2">
            <a href="<?= URL ?>" class="btn btn-default btn-sm"><i class="fa fa-map-o"></i> <span class="hidden sm:inline">แผนที่สถานการณ์</span></a>
            <a href="tel:<?= h(flood_ddpm_phone()) ?>" class="btn btn-ddpm btn-sm" title="สายด่วน ปภ. แจ้งเหตุสาธารณภัย/น้ำท่วม"><i class="fa fa-phone"></i> <?= h(flood_ddpm_phone()) ?> <span class="hidden sm:inline">ปภ.</span></a>
            <a href="tel:<?= h(EMERGENCY_PHONE) ?>" class="btn btn-1669 btn-sm" title="เจ็บป่วยฉุกเฉิน"><i class="fa fa-phone"></i> <?= h(EMERGENCY_PHONE) ?></a>
        </div>
    </div>
</nav>
<?php } ?>
