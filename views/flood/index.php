<?php
$d = $this->dash;
$role = $d['role'];
$u = flood_session_user();
$canHelp = flood_can_menu($role, 'help');
$canVuln = isset($d['vulnerable']);
?>
<div class="flood-page-header">
    <h2 class="flood-page-title"><i class="fa fa-tachometer"></i> ภาพรวมสถานการณ์</h2>
    <div class="small-muted">อัปเดต <b id="dashUpdated"><?= h($d['updated_th']) ?></b> · รีเฟรชเองทุก 1 นาที</div>
</div>

<div class="flood-kpi-grid" id="dashKpis"></div>

<div class="dash-grid">
    <div class="flood-card">
        <div class="flood-card-header">
            <span><i class="fa fa-map-o"></i> แผนที่สถานการณ์</span>
            <span class="map-legend" id="dashLegend"></span>
        </div>
        <div class="flood-card-body" style="padding:10px">
            <div id="dashMap" class="flood-map map-lg"></div>
            <div class="small-muted" style="margin-top:6px">เปิด/ปิดชั้นข้อมูลได้ที่ปุ่มมุมขวาบนของแผนที่</div>
        </div>
    </div>

    <div>
        <?php if ($role !== 'viewer') { ?>
        <div class="flood-card">
            <div class="flood-card-header">
                <span><i class="fa fa-life-ring"></i> <?= $role === 'team' ? 'ใบงานของทีม' : 'คำขอที่ต้องดำเนินการ' ?></span>
                <?php if ($canHelp) { ?><a href="<?= URL ?>flood/help" class="small-muted">ทั้งหมด <i class="fa fa-angle-right"></i></a><?php } ?>
            </div>
            <div id="dashHelp"></div>
        </div>
        <?php } ?>

        <?php $dn = is_array($this->dashNotices) ? $this->dashNotices : array(); if ($dn) { $ncats = flood_notice_categories(); ?>
        <div class="flood-card">
            <div class="flood-card-header">
                <span><i class="fa fa-newspaper-o"></i> ข้อมูลที่ควรรู้</span>
                <a href="<?= URL ?>flood/notices" class="small-muted">ทั้งหมด <i class="fa fa-angle-right"></i></a>
            </div>
            <ul class="dash-notices">
                <?php foreach ($dn as $n) { $nc = isset($ncats[$n['category']]) ? $ncats[$n['category']] : $ncats['other']; ?>
                <li style="--nc:<?= h($nc['color']) ?>">
                    <span class="ic"><i class="fa <?= h($nc['icon']) ?>"></i></span>
                    <span>
                        <span class="t"><?= (int) $n['is_pinned'] ? '<i class="fa fa-thumb-tack" style="color:#d97706"></i> ' : '' ?><?= h($n['title']) ?></span>
                        <?= $n['verify'] === 'verified' ? '' : ' <span class="label label-warning">ยังไม่ยืนยัน</span>' ?>
                        <div class="m"><?= h(trim((string) $n['place'] . ($n['amphoe_name'] ? ' · อ.' . $n['amphoe_name'] : ''), ' ·')) ?><?= ($n['place'] || $n['amphoe_name']) ? ' · ' : '' ?><?= h(flood_ago($n['info_at'] ?: $n['created_at'])) ?></div>
                    </span>
                </li>
                <?php } ?>
            </ul>
        </div>
        <?php } ?>

        <?php if ($canVuln) { ?>
        <div class="flood-card">
            <div class="flood-card-header">
                <span><i class="fa fa-wheelchair"></i> กลุ่มเปราะบางในพื้นที่ประกาศ</span>
                <a href="<?= URL ?>flood/vulnerable?in_zone=1" class="small-muted">ทั้งหมด <i class="fa fa-angle-right"></i></a>
            </div>
            <div id="dashVuln"></div>
        </div>
        <?php } ?>

        <?php if ($role === 'viewer') { ?>
        <div class="alert alert-info">สิทธิ์ของท่านดูภาพรวมได้อย่างเดียว ข้อมูลรายบุคคลจะไม่แสดง</div>
        <?php } ?>
    </div>
</div>

<script>
    window.DASH = <?= flood_js($d) ?>;
    window.DASH_OPT = <?= flood_js(array(
        'canHelp' => $canHelp,
        'canZones' => flood_can_menu($role, 'zones'),
        'canReports' => flood_can_menu($role, 'reports'),
        'canVuln' => $canVuln,
        'isAdmin' => flood_is_admin_role($role),
        'helpStatuses' => flood_help_statuses(),
        'priorities' => flood_priorities(),
        'evac' => flood_evac_statuses(),
    )) ?>;
</script>
