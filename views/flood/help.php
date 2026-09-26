<?php
$f = $this->helpFilters;
$res = $this->helpResult;
$rows = $res['rows'];
$c = $this->helpCounts;
$st = flood_help_statuses();
$pr = flood_priorities();
$needs = flood_help_needs();
$flags = flood_help_flags();
$isTeam = $this->isTeam;
$statusTabs = $isTeam
    ? array('open' => 'งานที่ยังไม่เสร็จ', 'assigned' => 'ได้รับมอบหมาย', 'in_progress' => 'กำลังช่วยเหลือ', 'done' => 'เสร็จแล้ว', 'all' => 'ทั้งหมด')
    : array('open' => 'ยังเปิดอยู่', 'waiting' => 'รอมอบหมาย', 'assigned' => 'มอบหมายแล้ว', 'in_progress' => 'กำลังช่วยเหลือ', 'done' => 'เสร็จแล้ว', 'cancelled' => 'ยกเลิก', 'all' => 'ทั้งหมด');
$tabCount = array('open' => $c['open'], 'waiting' => $c['waiting'], 'assigned' => $c['assigned'], 'in_progress' => $c['in_progress'],
    'done' => $c['done'], 'cancelled' => $c['cancelled']);
$tab = function ($s) use ($f) {
    $q = $f;
    $q['status'] = $s;
    unset($q['team_id']);
    if (!empty($f['team_id'])) {
        $q['team_id'] = $f['team_id'];
    }
    return URL . 'flood/help?' . http_build_query(array_filter($q, function ($v) { return $v !== '' && $v !== 0; }));
};
?>
<div class="flood-page-header">
    <h2 class="flood-page-title"><i class="fa fa-life-ring"></i> <?= $isTeam ? 'ใบงานของทีม' : 'คำขอความช่วยเหลือ' ?></h2>
    <?php if ($this->canCreate) { ?>
    <a href="<?= URL ?>flood/helpForm" class="btn btn-primary"><i class="fa fa-phone"></i> รับเรื่องทางโทรศัพท์</a>
    <?php } ?>
</div>

<div class="flood-kpi-grid">
    <?php if (!$isTeam) { ?>
    <a class="flood-kpi-card<?= $c['new'] ? ' kpi-danger' : '' ?>" href="<?= h($tab('waiting')) ?>"><i class="fa fa-bell kpi-icon"></i>
        <div class="kpi-value"><?= (int) $c['new'] ?></div><div class="kpi-label">รับเรื่องใหม่ (ยังไม่โทรยืนยัน)</div></a>
    <a class="flood-kpi-card<?= $c['verified'] ? ' kpi-warn' : '' ?>" href="<?= h($tab('waiting')) ?>"><i class="fa fa-phone kpi-icon"></i>
        <div class="kpi-value"><?= (int) $c['verified'] ?></div><div class="kpi-label">ยืนยันแล้ว รอมอบหมายทีม</div></a>
    <?php } ?>
    <a class="flood-kpi-card kpi-info" href="<?= h($tab('assigned')) ?>"><i class="fa fa-users kpi-icon"></i>
        <div class="kpi-value"><?= (int) $c['assigned'] ?></div><div class="kpi-label">มอบหมายแล้ว</div></a>
    <a class="flood-kpi-card kpi-info" href="<?= h($tab('in_progress')) ?>"><i class="fa fa-life-ring kpi-icon"></i>
        <div class="kpi-value"><?= (int) $c['in_progress'] ?></div><div class="kpi-label">กำลังช่วยเหลือ</div></a>
    <div class="flood-kpi-card<?= $c['urgent_open'] ? ' kpi-danger' : '' ?>"><i class="fa fa-exclamation-triangle kpi-icon"></i>
        <div class="kpi-value"><?= (int) $c['urgent_open'] ?></div><div class="kpi-label">ด่วนมาก (ยังเปิดอยู่)</div></div>
    <div class="flood-kpi-card kpi-ok"><i class="fa fa-check kpi-icon"></i>
        <div class="kpi-value"><?= (int) $c['done_today'] ?></div><div class="kpi-label">ช่วยเหลือแล้ววันนี้</div></div>
</div>

<ul class="nav-tabs">
    <?php foreach ($statusTabs as $code => $label) { ?>
    <li class="<?= $f['status'] === $code ? 'active' : '' ?>"><a href="<?= h($tab($code)) ?>"><?= h($label) ?>
        <?php if (isset($tabCount[$code])) { ?><span class="count"><?= (int) $tabCount[$code] ?></span><?php } ?></a></li>
    <?php } ?>
</ul>

<form class="filter-bar" method="get" action="<?= URL ?>flood/help" style="margin-bottom:12px">
    <input type="hidden" name="status" value="<?= h($f['status']) ?>" />
    <select name="priority" class="form-control"><?= flood_options($pr, $f['priority'], 'ทุกความเร่งด่วน') ?></select>
    <select name="need" class="form-control"><?= flood_options($needs, $f['need'], 'ทุกเรื่อง') ?></select>
    <?= flood_province_select($this->provinces, array('name' => 'province', 'class' => 'form-control', 'data-pv-for' => 'fbAmphoe', 'aria-label' => 'จังหวัด'), $f['province'], 'ทุกจังหวัด') ?>
    <select name="amphoe" class="form-control" id="fbAmphoe">
        <option value="">ทุกอำเภอ</option>
        <?= flood_amphoe_options($this->amphoes, $f['amphoe'], 'อ.') ?>
    </select>
    <?php if (!$isTeam) { ?>
    <select name="team_id" class="form-control">
        <option value="">ทุกทีม</option>
        <?php foreach ($this->teams as $t) { ?>
        <option value="<?= (int) $t['team_id'] ?>"<?= (int) $f['team_id'] === (int) $t['team_id'] ? ' selected' : '' ?>><?= h($t['name']) ?></option>
        <?php } ?>
    </select>
    <?php } ?>
    <input type="search" name="q" value="<?= h($f['q']) ?>" class="form-control grow" placeholder="ค้นหาเลขที่ / ชื่อ / เบอร์โทร / ที่อยู่" />
    <button type="submit" class="btn btn-default"><i class="fa fa-search"></i> ค้นหา</button>
    <button type="button" class="btn btn-default" id="toggleHelpMap"><i class="fa fa-map-o"></i> แผนที่</button>
</form>

<div class="flood-card hidden" id="helpMapCard">
    <div class="flood-card-body" style="padding:10px">
        <div id="helpMap" class="flood-map"></div>
        <div class="map-legend" style="margin-top:6px">
            <span><span class="sw" style="background:#dc2626"></span>ด่วนมาก</span>
            <span><span class="sw" style="background:#f59e0b"></span>ด่วน</span>
            <span><span class="sw" style="background:#64748b"></span>ปกติ</span>
            <span class="small-muted">· แสดงเฉพาะคำขอที่ยังเปิดอยู่และมีพิกัด</span>
        </div>
    </div>
</div>

<div class="flood-card">
    <div class="table-responsive">
        <table class="table table-hover table-cards" style="margin:0">
            <thead>
                <tr>
                    <th>ความเร่งด่วน</th>
                    <th>เลขที่</th>
                    <th>ต้องการ</th>
                    <th>ผู้ขอ</th>
                    <th>พื้นที่</th>
                    <th>สถานะ / ทีม</th>
                    <th>รับเรื่อง</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows) { ?>
                <tr><td colspan="7"><div class="empty-state"><i class="fa fa-check-circle"></i>ไม่มีคำขอในหมวดนี้</div></td></tr>
                <?php } ?>
                <?php foreach ($rows as $h) {
                    $fl = flood_codes_names($h['vulnerable_flags'], $flags);
                ?>
                <tr class="row-link" data-href="<?= URL ?>flood/helpView/<?= (int) $h['help_id'] ?>">
                    <td data-label="ความเร่งด่วน"><?= flood_status_label($pr, $h['priority']) ?></td>
                    <td data-label="เลขที่" class="nowrap"><a href="<?= URL ?>flood/helpView/<?= (int) $h['help_id'] ?>"><b><?= h($h['ref_code']) ?></b></a>
                        <div class="small-muted"><?= $h['source'] === 'phone' ? '<i class="fa fa-phone"></i> รับทางโทรศัพท์' : ($h['source'] === 'facebook' ? '<i class="fa fa-facebook-square"></i> จาก Facebook' : '<i class="fa fa-globe"></i> ส่งทางเว็บ') ?>
                        <?= (int) $h['photo_count'] ? ' · <i class="fa fa-camera"></i> ' . (int) $h['photo_count'] : '' ?></div>
                    </td>
                    <td data-label="ต้องการ">
                        <?= flood_tags(flood_codes_names($h['needs'], $needs)) ?>
                        <?php if ($fl) { ?><div><?= flood_tags($fl, 'tag-warn') ?></div><?php } ?>
                        <?php if ($h['people_count']) { ?><div class="small-muted"><?= (int) $h['people_count'] ?> คน</div><?php } ?>
                    </td>
                    <td data-label="ผู้ขอ"><?= h($h['requester_name']) ?>
                        <div><a href="tel:<?= h($h['requester_phone']) ?>" class="small-muted"><i class="fa fa-phone"></i> <?= h(flood_format_phone($h['requester_phone'])) ?></a></div>
                    </td>
                    <td data-label="พื้นที่">
                        <?= $h['tambon_name'] ? 'ต.' . h($h['tambon_name']) . ' ' : '' ?><?= $h['amphoe_name'] ? 'อ.' . h($h['amphoe_name']) : '' ?>
                        <?php if ($h['address']) { ?><div class="small-muted"><?= h(mb_strimwidth($h['address'], 0, 60, '…')) ?></div><?php } ?>
                        <?php if ($h['lat'] === null) { ?><div class="small-muted text-warning"><i class="fa fa-map-marker"></i> ไม่มีพิกัด</div><?php } ?>
                    </td>
                    <td data-label="สถานะ"><?= flood_status_label($st, $h['status']) ?>
                        <?php if ($h['team_name']) { ?><div class="small-muted"><i class="fa fa-users"></i> <?= h($h['team_name']) ?></div><?php } ?>
                    </td>
                    <td data-label="รับเรื่อง" class="nowrap"><?= h(flood_ago($h['created_at'])) ?><div class="small-muted"><?= h(flood_thai_date($h['created_at'])) ?></div></td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>
<?php $pg = $res; $pgPath = 'flood/help'; include __DIR__ . '/_pagination.php'; ?>

<script>
    window.HELP_MAP = <?= flood_js($this->helpMap) ?>;
    window.HELP_PRI = <?= flood_js($pr) ?>;
</script>
