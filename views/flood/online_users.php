<?php
$hours = (int) $this->onlineHours;
$f = $this->onlineFilters;
$s = $this->onlineSummary;
$rows = $this->onlineRows;
$hourOptions = array(1 => '1 ชั่วโมง', 8 => '8 ชั่วโมง', 24 => '24 ชั่วโมง', 72 => '3 วัน', 168 => '7 วัน');
$me = flood_session_user();
?>
<div class="flood-page-header">
    <h2 class="flood-page-title"><i class="fa fa-signal"></i> ผู้ใช้งานออนไลน์</h2>
    <label class="checkbox-inline small-muted"><input type="checkbox" id="ouAuto" checked /> รีเฟรชอัตโนมัติทุก 15 วินาที <span id="ouTs"></span></label>
</div>

<div class="flood-kpi-grid">
    <div class="flood-kpi-card kpi-ok"><i class="fa fa-signal kpi-icon"></i>
        <div class="kpi-value" id="ouOnline"><?= (int) $s['online_now'] ?></div>
        <div class="kpi-label">ออนไลน์ตอนนี้</div>
        <div class="kpi-sub">ใช้งานภายใน <?= (int) (defined('ONLINE_MINUTES') ? ONLINE_MINUTES : 3) ?> นาที</div></div>
    <div class="flood-kpi-card"><i class="fa fa-clock-o kpi-icon"></i>
        <div class="kpi-value" id="ou24"><?= (int) $s['active_24h'] ?></div><div class="kpi-label">ใช้งานใน 24 ชั่วโมง</div></div>
    <div class="flood-kpi-card"><i class="fa fa-users kpi-icon"></i>
        <div class="kpi-value" id="ouTotal"><?= (int) $s['total_active'] ?></div><div class="kpi-label">บัญชีที่เปิดใช้งาน</div></div>
</div>

<form class="filter-bar" id="ouFilter" method="get" action="<?= URL ?>flood/onlineUsers" style="margin-bottom:12px">
    <input type="search" name="q" value="<?= h($f['q']) ?>" class="form-control grow" placeholder="ค้นหาชื่อ / ชื่อผู้ใช้ / หน่วยงาน / ทีม" />
    <select name="hours" class="form-control"><?= flood_options($hourOptions, $hours) ?></select>
    <select name="role" class="form-control"><?= flood_options(flood_role_labels(), $f['role'], 'ทุกสิทธิ์') ?></select>
    <button type="submit" class="btn btn-default"><i class="fa fa-search"></i> แสดง</button>
</form>

<div class="flood-card">
    <div class="flood-card-header">ใช้งานภายใน <?= h($hourOptions[$hours]) ?> <span class="label label-info" id="ouCount"><?= count($rows) ?> คน</span></div>
    <div class="table-responsive">
        <table class="table table-cards" style="margin:0">
            <thead>
                <tr><th>สถานะ</th><th>ชื่อ</th><th>สิทธิ์</th><th>หน่วยงาน / ทีม</th><th>หน้าที่เปิดอยู่</th><th>ใช้งานล่าสุด</th><th>เข้าสู่ระบบ</th><th>IP</th><th style="width:1%"></th></tr>
            </thead>
            <tbody id="ouBody"></tbody>
        </table>
    </div>
</div>
<p class="small-muted">ระบบนับจากเวลาใช้งานล่าสุด (last_seen_at) ซึ่งหน้าจอที่เปิดค้างไว้ส่งสัญญาณทุก 60 วินาที · "ให้ออกจากระบบ" มีผลภายใน 1 นาที</p>

<script>
    window.OU = <?= flood_js(array('rows' => $rows, 'me' => (int) $me['user_id'])) ?>;
</script>
