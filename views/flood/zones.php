<?php
$f = $this->zoneFilters;
$rows = $this->zoneRows;
$counts = $this->zoneCounts;
$levels = flood_zone_levels();
$sources = flood_zone_sources();
$tabLink = function ($status) use ($f) {
    return URL . 'flood/zones?' . http_build_query(array('status' => $status, 'level' => $f['level'], 'region' => $f['region'], 'province' => $f['province'], 'amphoe' => $f['amphoe'], 'q' => $f['q']));
};
?>
<div class="flood-page-header">
    <h2 class="flood-page-title"><i class="fa fa-map"></i> พื้นที่ประกาศ</h2>
    <?php if ($this->canEdit) { ?>
    <div class="page-actions">
        <?php if ($this->canImport) { ?>
        <a href="<?= URL ?>flood/zoneImport" class="btn btn-default"><i class="fa fa-download"></i> นำเข้าจาก arankub.com</a>
        <?php } ?>
        <a href="<?= URL ?>flood/zoneForm" class="btn btn-primary"><i class="fa fa-plus"></i> ประกาศพื้นที่ใหม่</a>
    </div>
    <?php } ?>
</div>

<div class="flood-kpi-grid">
    <?php foreach ($levels as $code => $l) { ?>
    <a class="flood-kpi-card lv-<?= h($code) ?>" href="<?= URL ?>flood/zones?level=<?= h($code) ?>">
        <i class="fa <?= h($l['icon']) ?> kpi-icon"></i>
        <div class="kpi-value"><?= (int) $counts[$code] ?></div>
        <div class="kpi-label"><?= h($l['name']) ?></div>
        <div class="kpi-sub"><?= h($l['desc']) ?></div>
    </a>
    <?php } ?>
</div>

<ul class="nav-tabs">
    <li class="<?= $f['status'] === 'active' ? 'active' : '' ?>"><a href="<?= h($tabLink('active')) ?>">ประกาศอยู่ <span class="count"><?= (int) $counts['total'] ?></span></a></li>
    <li class="<?= $f['status'] === 'ended' ? 'active' : '' ?>"><a href="<?= h($tabLink('ended')) ?>">ปิดประกาศแล้ว</a></li>
    <li class="<?= $f['status'] === 'all' ? 'active' : '' ?>"><a href="<?= h($tabLink('all')) ?>">ทั้งหมด</a></li>
</ul>

<form class="filter-bar" method="get" action="<?= URL ?>flood/zones" style="margin-bottom:12px">
    <input type="hidden" name="status" value="<?= h($f['status']) ?>" />
    <select name="level" class="form-control"><?= flood_options($levels, $f['level'], 'ทุกระดับ') ?></select>
    <?= flood_region_select($this->regions, array('name' => 'region', 'class' => 'form-control', 'data-rg-for' => 'fbProvince', 'aria-label' => 'ภาค'), $f['region'] ?? '', 'ทุกภาค') ?>
    <?= flood_province_select($this->provinces, array('name' => 'province', 'id' => 'fbProvince', 'class' => 'form-control', 'data-pv-for' => 'fbAmphoe', 'aria-label' => 'จังหวัด'), $f['province'], 'ทุกจังหวัด') ?>
    <select name="amphoe" class="form-control" id="fbAmphoe">
        <option value="">ทุกอำเภอ</option>
        <?= flood_amphoe_options($this->amphoes, $f['amphoe'], 'อ.') ?>
    </select>
    <input type="search" name="q" value="<?= h($f['q']) ?>" class="form-control grow" placeholder="ค้นหาชื่อพื้นที่ / ข้อความ / ตำบล" />
    <button type="submit" class="btn btn-default"><i class="fa fa-search"></i> ค้นหา</button>
</form>

<div class="flood-card">
    <div class="flood-card-body" style="padding:10px">
        <div id="zonesMap" class="flood-map"></div>
        <div class="map-legend" id="zonesLegend" style="margin-top:6px"></div>
    </div>
</div>

<div class="flood-card">
    <div class="flood-card-header"><span>รายการพื้นที่ (<?= count($rows) ?>)</span></div>
    <div class="table-responsive">
        <table class="table table-hover table-cards" style="margin:0">
            <thead>
                <tr>
                    <th>ระดับ</th>
                    <th>ชื่อพื้นที่</th>
                    <th>อำเภอ / ตำบล</th>
                    <th>ขอบเขต</th>
                    <th>ข้อความถึงประชาชน</th>
                    <th>ประกาศ</th>
                    <th>ปรับปรุงล่าสุด</th>
                    <th style="width:1%"></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows) { ?>
                <tr><td colspan="8"><div class="empty-state"><i class="fa fa-map-o"></i>ไม่มีพื้นที่ตามเงื่อนไขนี้</div></td></tr>
                <?php } ?>
                <?php foreach ($rows as $z) {
                    $ended = $z['status'] === 'ended';
                ?>
                <tr<?= $ended ? ' style="opacity:.65"' : '' ?>>
                    <td data-label="ระดับ"><?= flood_level_badge($z['level']) ?><?= $ended ? ' <span class="label label-default">ปิดแล้ว</span>' : '' ?></td>
                    <td data-label="ชื่อพื้นที่">
                        <b><?= h($z['name']) ?></b>
                        <div class="small-muted"><?= h(flood_opt_name($sources, $z['source'])) ?><?= (int) $z['report_count'] > 0 ? ' · รายงานประชาชน ' . (int) $z['report_count'] : '' ?><?= (int) $z['photo_count'] > 0 ? ' · <i class="fa fa-camera" aria-hidden="true"></i> ' . (int) $z['photo_count'] . ' ภาพ' : '' ?></div>
                    </td>
                    <td data-label="อำเภอ / ตำบล"><?= $z['tambon_name'] ? 'ต.' . h($z['tambon_name']) . ' ' : '' ?><?= $z['amphoe_name'] ? 'อ.' . h($z['amphoe_name']) : '—' ?></td>
                    <td data-label="ขอบเขต" class="nowrap">
                        <?php if ($z['shape'] === 'circle') { ?>
                        <i class="fa fa-circle-o"></i> รัศมี <?= number_format((int) $z['radius_m']) ?> ม.
                        <?php } else { ?>
                        <i class="fa fa-object-ungroup"></i> วาดขอบเขต
                        <?php } ?>
                        <div class="small-muted">~<?= number_format(flood_zone_area_km2($z), 2) ?> ตร.กม.</div>
                    </td>
                    <td data-label="ข้อความ"><?= $z['note'] ? h(mb_strimwidth($z['note'], 0, 90, '…')) : '<span class="text-muted">—</span>' ?></td>
                    <td data-label="ประกาศ" class="nowrap"><?= h(flood_thai_date($z['started_at'])) ?>
                        <?php if ($ended) { ?><div class="small-muted">ปิด <?= h(flood_thai_date($z['ended_at'])) ?></div><?php } ?>
                    </td>
                    <td data-label="ปรับปรุง" class="nowrap">
                        <?= h(flood_ago($z['updated_at'] ? $z['updated_at'] : $z['created_at'])) ?>
                        <div class="small-muted"><?= h((string) $z['updated_by_name']) ?></div>
                    </td>
                    <td data-label="" class="nowrap">
                        <button type="button" class="btn btn-default btn-sm js-zone-show" data-id="<?= (int) $z['zone_id'] ?>" title="ดูบนแผนที่"><i class="fa fa-crosshairs"></i></button>
                        <?php if ($this->canEdit) { ?>
                        <a href="<?= URL ?>flood/zoneForm/<?= (int) $z['zone_id'] ?>" class="btn btn-default btn-sm" title="แก้ไข"><i class="fa fa-pencil"></i></a>
                        <?php if ($ended) { ?>
                        <button type="button" class="btn btn-default btn-sm js-zone-status" data-id="<?= (int) $z['zone_id'] ?>" data-status="active" data-name="<?= h($z['name']) ?>"><i class="fa fa-undo"></i> ประกาศอีกครั้ง</button>
                        <?php } else { ?>
                        <button type="button" class="btn btn-success btn-sm js-zone-status" data-id="<?= (int) $z['zone_id'] ?>" data-status="ended" data-name="<?= h($z['name']) ?>"><i class="fa fa-check"></i> น้ำลดแล้ว</button>
                        <?php } ?>
                        <?php } ?>
                    </td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>

<?php
$zForMap = array();
foreach ($rows as $z) {
    $zForMap[] = array(
        'zone_id' => (int) $z['zone_id'], 'name' => $z['name'], 'level' => $z['level'], 'shape' => $z['shape'],
        'center' => array((float) $z['center_lat'], (float) $z['center_lng']),
        'radius_m' => $z['radius_m'] !== null ? (int) $z['radius_m'] : null,
        'polygon' => $z['shape'] === 'polygon' ? flood_parse_polygon($z['polygon_json']) : null,
        'amphoe_name' => $z['amphoe_name'], 'tambon_name' => $z['tambon_name'], 'note' => $z['note'],
        'status' => $z['status'], 'started_th' => flood_thai_date($z['started_at']),
        'photos' => isset($this->zonePhotoIds[(int) $z['zone_id']]) ? $this->zonePhotoIds[(int) $z['zone_id']] : array(),
    );
}
?>
<script>
    window.ZONES = <?= flood_js($zForMap) ?>;
    window.ZONES_CAN_EDIT = <?= $this->canEdit ? 'true' : 'false' ?>;
</script>
