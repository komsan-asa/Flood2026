<?php
$f = $this->reportFilters;
$res = $this->reportResult;
$rows = $res['rows'];
$c = $this->reportCounts;
$st = flood_report_view_statuses();
$depth = flood_depth_options();
$extent = flood_extent_options();
$vehicle = flood_vehicle_options();
$trend = flood_trend_options();
$impacts = flood_area_impacts();
$tab = function ($s) use ($f) {
    return URL . 'flood/reports?' . http_build_query(array_filter(array('status' => $s, 'q' => $f['q'], 'impact' => $f['impact'] ?? '', 'province' => $f['province'] ?? '', 'region' => $f['region'] ?? '')));
};
$mapRows = array();
foreach ($rows as $r) {
    $mapRows[] = array(
        'report_id' => (int) $r['report_id'], 'ref_code' => $r['ref_code'], 'lat' => (float) $r['lat'], 'lng' => (float) $r['lng'],
        'status' => $r['view_status'], 'depth' => flood_opt_name($depth, $r['depth']), 'ago' => flood_ago($r['created_at']),
    );
}
?>
<div class="flood-page-header">
    <h2 class="flood-page-title"><i class="fa fa-tint"></i> รายงานจากประชาชน</h2>
    <span class="small-muted">วันนี้ <?= (int) $c['today'] ?> รายงาน</span>
</div>

<?php if ($c['waiting'] && $f['status'] !== 'waiting') { ?>
<div class="alert alert-warning" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <i class="fa fa-bullhorn"></i>
    <span>มีรายงานที่ <b>ยืนยันแล้วแต่ยังไม่ได้ประกาศ</b> <?= (int) $c['waiting'] ?> รายการ — ประชาชนยังไม่เห็นบนแผนที่</span>
    <a class="btn btn-warning btn-sm" href="<?= h($tab('waiting')) ?>" style="margin-left:auto">ดูรายการรอประกาศ</a>
</div>
<?php } ?>

<?php if (flood_public_pending_enabled() && $f['status'] === 'pending' && $c['pending']) { ?>
<div class="alert alert-info rep-onmap-note">
    <i class="fa fa-map-marker"></i>
    รายงานที่ <b>รอตรวจสอบ</b> ทั้ง <?= (int) $c['pending'] ?> รายการ <b>ขึ้นแผนที่ประชาชนแล้ว</b> เป็นจุดสีเหลือง พร้อมป้าย <span class="sk-tag-pending">⏳ รอตรวจสอบ</span>
    — กด <b>ตรวจ</b> เพื่อประกาศเป็นพื้นที่ / ยืนยัน / ไม่ใช้ข้อมูล (ไม่ใช้ข้อมูล = เอาออกจากแผนที่)
</div>
<?php } ?>

<ul class="nav-tabs">
    <li class="<?= $f['status'] === 'pending' ? 'active' : '' ?>"><a href="<?= h($tab('pending')) ?>">รอตรวจสอบ <span class="count"><?= (int) $c['pending'] ?></span></a></li>
    <li class="<?= $f['status'] === 'waiting' ? 'active' : '' ?>"><a href="<?= h($tab('waiting')) ?>" title="ยืนยันแล้ว แต่ยังไม่ขึ้นแผนที่ประชาชน">รอประกาศ <span class="count<?= $c['waiting'] ? ' count-alert' : '' ?>"><?= (int) $c['waiting'] ?></span></a></li>
    <li class="<?= $f['status'] === 'announced' ? 'active' : '' ?>"><a href="<?= h($tab('announced')) ?>">ประกาศแล้ว <span class="count"><?= (int) $c['announced'] ?></span></a></li>
    <li class="<?= $f['status'] === 'rejected' ? 'active' : '' ?>"><a href="<?= h($tab('rejected')) ?>">ไม่ใช้ข้อมูล <span class="count"><?= (int) $c['rejected'] ?></span></a></li>
    <li class="<?= $f['status'] === 'all' ? 'active' : '' ?>"><a href="<?= h($tab('all')) ?>">ทั้งหมด</a></li>
</ul>

<form class="filter-bar" method="get" action="<?= URL ?>flood/reports" style="margin-bottom:12px">
    <input type="hidden" name="status" value="<?= h($f['status']) ?>" />
    <input type="search" name="q" value="<?= h($f['q']) ?>" class="form-control grow" placeholder="ค้นหาเลขอ้างอิง / ชื่อผู้แจ้ง / เบอร์โทร / จุดสังเกต" />
    <select name="impact" class="form-control" style="width:auto" aria-label="ผลกระทบในพื้นที่" onchange="this.form.submit()"><?= flood_options($impacts, $f['impact'] ?? '', 'ผลกระทบ: ทั้งหมด') ?></select>
    <?php if (count((array) $this->provinces) > 1) { ?>
    <?= flood_region_select($this->regions, array('name' => 'region', 'class' => 'form-control', 'style' => 'width:auto', 'data-rg-for' => 'fbProvince', 'aria-label' => 'ภาค'), $f['region'] ?? '', 'ทุกภาค') ?>
    <?= flood_province_select($this->provinces, array('name' => 'province', 'id' => 'fbProvince', 'class' => 'form-control', 'style' => 'width:auto', 'aria-label' => 'จังหวัด', 'onchange' => 'this.form.submit()'), $f['province'] ?? '', 'ทุกจังหวัด') ?>
    <?php } ?>
    <button type="submit" class="btn btn-default"><i class="fa fa-search"></i> ค้นหา</button>
</form>

<div class="flood-card">
    <div class="flood-card-body" style="padding:10px">
        <div id="reportsMap" class="flood-map map-sm"></div>
        <div class="map-legend" style="margin-top:6px">
            <span><span class="sw" style="background:#1f78c1;border-radius:50%"></span>รอตรวจ<?= flood_public_pending_enabled() ? ' (ขึ้นแผนที่ประชาชนแล้ว)' : '' ?></span>
            <span><span class="sw" style="background:#ea580c;border-radius:50%"></span>ยืนยันแล้ว · รอประกาศ</span>
            <span><span class="sw" style="background:#16a34a;border-radius:50%"></span>ประกาศแล้ว</span>
            <span><span class="sw" style="background:#9ca3af;border-radius:50%"></span>ไม่ใช้ข้อมูล</span>
            <span class="small-muted">· พื้นที่สี = พื้นที่ที่ประกาศอยู่</span>
        </div>
    </div>
</div>

<div class="flood-card">
    <div class="table-responsive">
        <table class="table table-hover table-cards" style="margin:0">
            <thead>
                <tr>
                    <th>เวลาแจ้ง</th>
                    <th>เลขอ้างอิง</th>
                    <th>สภาพน้ำ</th>
                    <th>รถผ่าน / แนวโน้ม</th>
                    <th>ผู้แจ้ง</th>
                    <th>สถานะ</th>
                    <th style="width:1%"></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows) { ?>
                <tr><td colspan="7"><div class="empty-state"><i class="fa fa-inbox"></i>ไม่มีรายงานในหมวดนี้</div></td></tr>
                <?php } ?>
                <?php foreach ($rows as $r) { ?>
                <tr>
                    <td data-label="เวลาแจ้ง" class="nowrap"><?= h(flood_thai_date($r['created_at'])) ?><div class="small-muted"><?= h(flood_ago($r['created_at'])) ?></div></td>
                    <td data-label="เลขอ้างอิง" class="nowrap"><b><?= h($r['ref_code']) ?></b>
                        <?php if ((int) $r['photo_count'] > 0) { ?><div class="small-muted"><i class="fa fa-camera"></i> <?= (int) $r['photo_count'] ?> รูป</div><?php } ?>
                    </td>
                    <td data-label="สภาพน้ำ">
                        น้ำ<?= h(flood_opt_name($depth, $r['depth'])) ?>
                        <div class="small-muted"><?= h(flood_opt_name($extent, $r['extent'])) ?></div>
                        <?php if (!empty($r['impacts'])) { ?><div style="margin-top:4px"><?= flood_tags(flood_codes_names($r['impacts'], $impacts), 'tag-danger') ?></div><?php } ?>
                    </td>
                    <td data-label="รถ / แนวโน้ม">
                        <?= $r['vehicle'] ? h(flood_opt_name($vehicle, $r['vehicle'])) : '<span class="text-muted">—</span>' ?>
                        <?php if ($r['trend']) { ?><div class="small-muted"><?= h(flood_opt_name($trend, $r['trend'])) ?></div><?php } ?>
                    </td>
                    <td data-label="ผู้แจ้ง"><?= h($r['reporter_name']) ?>
                        <?php if (!empty($r['source_url'])) { ?>
                        <div><a href="<?= h($r['source_url']) ?>" target="_blank" rel="noopener" class="small-muted"><i class="fa fa-facebook-square"></i> เปิดโพสต์ต้นทาง</a></div>
                        <?php } elseif ($r['reporter_phone'] !== '') { ?>
                        <div><a href="tel:<?= h($r['reporter_phone']) ?>" class="small-muted"><i class="fa fa-phone"></i> <?= h(flood_format_phone($r['reporter_phone'])) ?></a></div>
                        <?php } ?>
                    </td>
                    <td data-label="สถานะ">
                        <?= flood_status_label($st, $r['view_status']) ?>
                        <?php if ($r['status'] === 'pending' && flood_public_pending_enabled()) { ?>
                        <div class="rep-onmap" title="ประชาชนเห็นจุดนี้บนแผนที่แล้ว พร้อมป้าย &quot;รอตรวจสอบ&quot;"><i class="fa fa-globe"></i> ขึ้นแผนที่ประชาชนแล้ว</div>
                        <?php } ?>
                        <?php if ($r['zone_name']) { ?>
                        <div class="small-muted"><i class="fa fa-map"></i> <?= h($r['zone_name']) ?><?= $r['zone_status'] !== 'active' ? ' <span class="text-danger">(ปิดประกาศแล้ว)</span>' : '' ?></div>
                        <?php } elseif ($r['view_status'] === 'waiting') { ?>
                        <div class="small-muted"><i class="fa fa-info-circle"></i> ยังไม่ได้ผูกกับพื้นที่ประกาศ</div>
                        <?php } elseif ($r['status'] === 'pending' && $r['inside_zone']) { ?>
                        <div class="small-muted" title="จุดที่แจ้งอยู่ในพื้นที่ที่ประกาศแล้ว"><i class="fa fa-info-circle"></i> อยู่ใน "<?= h($r['inside_zone']['name']) ?>"</div>
                        <?php } ?>
                    </td>
                    <td data-label="" class="nowrap">
                        <?php if ($r['view_status'] === 'waiting') { ?>
                        <button type="button" class="btn btn-warning btn-sm js-report-open" data-id="<?= (int) $r['report_id'] ?>">
                            <i class="fa fa-bullhorn"></i> ประกาศ
                        </button>
                        <?php } else { ?>
                        <button type="button" class="btn btn-primary btn-sm js-report-open" data-id="<?= (int) $r['report_id'] ?>">
                            <i class="fa fa-search"></i> <?= $r['status'] === 'pending' ? 'ตรวจ' : 'ดู' ?>
                        </button>
                        <?php } ?>
                    </td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>
<?php $pg = $res; $pgPath = 'flood/reports'; include __DIR__ . '/_pagination.php'; ?>

<!-- รายละเอียดรายงาน -->
<div class="modal fade modal-lg" id="reportModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title">รายงาน <span id="rmRef"></span> <span id="rmStatus"></span></h4>
                <button type="button" class="close" data-dismiss="modal" aria-label="ปิด">&times;</button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <div id="rmMap" class="flood-map map-sm"></div>
                        <div class="small-muted" id="rmLoc" style="margin-top:4px"></div>
                        <div class="photo-grid" id="rmPhotos" style="margin-top:10px"></div>
                    </div>
                    <div class="col-md-6">
                        <dl class="kv" id="rmKv"></dl>
                        <div id="rmInside" style="margin-top:10px"></div>
                    </div>
                </div>
                <div id="rmActions" style="margin-top:14px"></div>
            </div>
        </div>
    </div>
</div>

<script>
    window.REPORTS = <?= flood_js($mapRows) ?>;
    window.REPORT_PUBLIC_PENDING = <?= flood_public_pending_enabled() ? 'true' : 'false' ?>;
    window.REPORT_ZONES = <?= flood_js($this->activeZones) ?>;
</script>
