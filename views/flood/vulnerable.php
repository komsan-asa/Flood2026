<?php
$f = $this->vFilters;
$res = $this->vResult;
$rows = $res['rows'];
$s = $this->vSummary;
$groups = flood_vulnerable_groups();
$mob = flood_mobility_options();
$evac = flood_evac_statuses();
$vSources = isset($this->vSources) ? $this->vSources : array();
$vActive = array_values(array_filter($vSources, function ($x) { return (int) $x['is_active'] === 1; }));
$vSrcName = array();
foreach ($vSources as $x) {
    $vSrcName[(int) $x['source_id']] = $x['name'];
}
$fieldLabels = !empty($this->vImportReady) ? Vulnerable_Import_Model::fieldLabels() : array();
$shLabels = array('shelter_name' => 'ศูนย์พักพิง', 'amphoe_name' => 'อำเภอ', 'row_no' => 'ลำดับ', 'people' => 'ผู้พักพิง', 'coordinator' => 'ผู้ประสานประจำศูนย์',
    'health_contact' => 'ผู้ประสานด้านสาธารณสุข', 'elderly' => 'ผู้สูงอายุ', 'disabled' => 'ผู้พิการ', 'child' => 'เด็ก 0–5 ปี', 'bedridden' => 'ติดเตียง',
    'pregnant' => 'หญิงตั้งครรภ์', 'pregnant_ga' => 'อายุครรภ์', 'pregnant_sym' => 'อาการหญิงตั้งครรภ์', 'dialysis_hd' => 'ล้างไตด้วยเครื่อง',
    'dialysis_capd' => 'ล้างไตช่องท้อง', 'mental' => 'สุขภาพจิต', 'chronic' => 'โรคเรื้อรัง', 'treated' => 'รักษา/ทำแผล/จ่ายยา', 'referred' => 'ส่งต่อ', 'needs' => 'สิ่งที่ต้องการสนับสนุน');
$shDates = isset($this->shDates) ? $this->shDates : array();
$shRep = isset($this->shReport) ? $this->shReport : null;
$shNum = function ($v) {
    return $v === null ? '<span class="text-muted">–</span>' : ((int) $v ? '<b>' . (int) $v . '</b>' : '0');
};
$mapRows = array();
foreach ($rows as $r) {
    if ($r['lat'] !== null) {
        $mapRows[] = array('person_id' => (int) $r['person_id'], 'name' => $r['name'], 'lat' => (float) $r['lat'], 'lng' => (float) $r['lng'],
            'evac_status' => $r['evac_status'], 'groups' => flood_codes_names($r['vuln_groups'], $groups),
            'zone' => $r['zone']);
    }
}
?>
<div class="flood-page-header">
    <h2 class="flood-page-title"><i class="fa fa-wheelchair"></i> ทะเบียนกลุ่มเปราะบาง</h2>
    <div class="flood-page-actions">
        <?php if ($vActive) { ?><button type="button" class="btn btn-default js-vsrc-sync" data-id="0"><i class="fa fa-refresh"></i> ดึงข้อมูลจาก Google Sheet</button><?php } ?>
        <a href="<?= URL ?>flood/vulnerableForm" class="btn btn-primary"><i class="fa fa-user-plus"></i> เพิ่มบุคคล</a>
    </div>
</div>

<div class="flood-kpi-grid">
    <a class="flood-kpi-card" href="<?= URL ?>flood/vulnerable"><i class="fa fa-users kpi-icon"></i>
        <div class="kpi-value"><?= (int) $s['total'] ?></div><div class="kpi-label">อยู่ในทะเบียน</div></a>
    <a class="flood-kpi-card<?= $s['in_zone'] ? ' kpi-warn' : '' ?>" href="<?= URL ?>flood/vulnerable?in_zone=1"><i class="fa fa-map kpi-icon"></i>
        <div class="kpi-value"><?= (int) $s['in_zone'] ?></div><div class="kpi-label">อยู่ในพื้นที่ประกาศ</div></a>
    <a class="flood-kpi-card<?= $s['in_zone_waiting'] ? ' kpi-danger' : ' kpi-ok' ?>" href="<?= URL ?>flood/vulnerable?in_zone=1"><i class="fa fa-exclamation-triangle kpi-icon"></i>
        <div class="kpi-value"><?= (int) $s['in_zone_waiting'] ?></div><div class="kpi-label">ในพื้นที่ประกาศ ยังไม่อพยพ</div>
        <div class="kpi-sub">สถานะ "ยังไม่ได้ติดต่อ" หรือ "แจ้งเตือนแล้ว"</div></a>
    <a class="flood-kpi-card<?= $s['no_location'] ? ' kpi-warn' : '' ?>" href="<?= URL ?>flood/vulnerable?no_location=1"><i class="fa fa-map-marker kpi-icon"></i>
        <div class="kpi-value"><?= (int) $s['no_location'] ?></div><div class="kpi-label">ยังไม่มีพิกัดบ้าน</div>
        <div class="kpi-sub">ระบบเทียบกับพื้นที่ประกาศไม่ได้</div></a>
</div>

<?php if ($shRep) { $sm = $shRep['sum']; ?>
<!-- กลุ่มเปราะบางในศูนย์พักพิง (ตัวเลขรายศูนย์จากชีตประเภท "รายงานศูนย์พักพิง") -->
<div class="flood-card sh-card">
    <div class="flood-card-header">
        <span><i class="fa fa-home"></i> กลุ่มเปราะบางในศูนย์พักพิง · <?= h(flood_thai_date($this->shDate, false)) ?></span>
        <form method="get" action="<?= URL ?>flood/vulnerable" class="sh-date">
            <select name="sdate" class="form-control input-sm" onchange="this.form.submit()" aria-label="วันที่รายงาน">
                <?php foreach ($shDates as $d) { ?><option value="<?= h($d['report_date']) ?>"<?= $d['report_date'] === $this->shDate ? ' selected' : '' ?>><?= h(flood_thai_date($d['report_date'], false)) ?> · <?= (int) $d['shelters'] ?> ศูนย์</option><?php } ?>
            </select>
        </form>
    </div>
    <div class="flood-card-body">
        <div class="sh-sum">
            <div><b><?= (int) $sm['shelters'] ?></b><span>ศูนย์พักพิง</span></div>
            <div><b><?= number_format($sm['people']) ?></b><span>ผู้พักพิง</span></div>
            <div class="<?= $sm['bedridden'] ? 'is-hot' : '' ?>"><b><?= (int) $sm['bedridden'] ?></b><span>ติดเตียง</span></div>
            <div><b><?= (int) $sm['elderly'] ?></b><span>ผู้สูงอายุ</span></div>
            <div><b><?= (int) $sm['disabled'] ?></b><span>ผู้พิการ</span></div>
            <div><b><?= (int) $sm['child'] ?></b><span>เด็ก 0–5 ปี</span></div>
            <div class="<?= $sm['pregnant'] ? 'is-hot' : '' ?>"><b><?= (int) $sm['pregnant'] ?></b><span>หญิงตั้งครรภ์</span></div>
            <div class="<?= ($sm['dialysis_hd'] + $sm['dialysis_capd']) ? 'is-hot' : '' ?>"><b><?= (int) ($sm['dialysis_hd'] + $sm['dialysis_capd']) ?></b><span>ล้างไต (เครื่อง <?= (int) $sm['dialysis_hd'] ?> · ช่องท้อง <?= (int) $sm['dialysis_capd'] ?>)</span></div>
            <div><b><?= (int) $sm['mental'] ?></b><span>สุขภาพจิต</span></div>
            <div><b><?= (int) $sm['chronic'] ?></b><span>โรคเรื้อรัง</span></div>
        </div>
        <div class="table-responsive">
            <table class="table table-condensed table-cards sh-table">
                <thead><tr><th>ศูนย์พักพิง</th><th class="text-right">ผู้พักพิง</th><th class="text-right">ติดเตียง</th><th class="text-right">สูงอายุ</th><th class="text-right">พิการ</th><th class="text-right">เด็ก 0–5</th><th class="text-right">ตั้งครรภ์</th><th class="text-right">ล้างไต</th><th class="text-right">สุขภาพจิต</th><th class="text-right">โรคเรื้อรัง</th><th>ผู้ประสาน</th></tr></thead>
                <tbody>
                <?php foreach ($shRep['rows'] as $r) {
                    $dz = (int) $r['dialysis_hd'] + (int) $r['dialysis_capd'];
                    $hot = (int) $r['bedridden'] || (int) $r['pregnant'] || $dz;
                    $raw = $r['raw'] ? json_decode($r['raw'], true) : array(); ?>
                <tr class="<?= $hot ? 'sh-hot' : '' ?>">
                    <td data-label="ศูนย์พักพิง"><b><?= h($r['shelter_name']) ?></b>
                        <div class="small-muted"><?= $r['tambon_name'] ? 'ต.' . h($r['tambon_name']) . ' ' : '' ?><?= $r['amphoe_name'] ? 'อ.' . h($r['amphoe_name']) : '' ?></div>
                        <?php if ($r['pregnant_note']) { ?><div class="small"><i class="fa fa-female"></i> <?= h($r['pregnant_note']) ?></div><?php } ?>
                        <?php if ($r['needs']) { ?><div class="small text-warning"><i class="fa fa-exclamation-circle"></i> <?= h($r['needs']) ?></div><?php } ?>
                        <?php if ($raw) { ?><details class="sh-raw"><summary>รายละเอียดทุกคอลัมน์</summary><table class="table table-condensed"><?php foreach ($raw as $hd => $val) { ?><tr><th><?= h($hd) ?></th><td><?= nl2br(h($val)) ?></td></tr><?php } ?></table></details><?php } ?>
                    </td>
                    <td data-label="ผู้พักพิง" class="text-right"><?= $r['people'] === null ? '–' : number_format((int) $r['people']) ?></td>
                    <td data-label="ติดเตียง" class="text-right"><?= $shNum($r['bedridden']) ?></td>
                    <td data-label="สูงอายุ" class="text-right"><?= $shNum($r['elderly']) ?></td>
                    <td data-label="พิการ" class="text-right"><?= $shNum($r['disabled']) ?></td>
                    <td data-label="เด็ก 0–5" class="text-right"><?= $shNum($r['child']) ?></td>
                    <td data-label="ตั้งครรภ์" class="text-right"><?= $shNum($r['pregnant']) ?></td>
                    <td data-label="ล้างไต" class="text-right"><?= $r['dialysis_hd'] === null && $r['dialysis_capd'] === null ? '<span class="text-muted">–</span>' : ($dz ? '<b>' . $dz . '</b>' : '0') ?></td>
                    <td data-label="สุขภาพจิต" class="text-right"><?= $shNum($r['mental']) ?></td>
                    <td data-label="โรคเรื้อรัง" class="text-right"><?= $shNum($r['chronic']) ?></td>
                    <td data-label="ผู้ประสาน" class="sh-contact"><?php foreach (array('coordinator' => 'ประจำศูนย์', 'health_contact' => 'สาธารณสุข') as $k => $lb) { if ($r[$k]) { ?><div><span class="small-muted"><?= h($lb) ?>:</span> <?= preg_replace('/(0\d{1,2}-?\d{3}-?\d{3,4})/u', '<a href="tel:$1">$1</a>', h($r[$k])) ?></div><?php } } ?></td>
                </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <div class="small-muted">ตัวเลขรวมรายศูนย์จากรายงาน (ไม่ใช่รายชื่อคน — ไม่นับรวมในทะเบียนด้านล่าง) · แถวสีส้ม = มีผู้ป่วยติดเตียง / หญิงตั้งครรภ์ / ล้างไต · ช่อง – = ไม่ได้รายงาน · ที่มา: <?= h(implode(', ', array_unique(array_map(function ($x) { return (string) $x['source_name']; }, $shRep['rows'])))) ?></div>
    </div>
</div>
<?php } ?>

<form class="filter-bar" method="get" action="<?= URL ?>flood/vulnerable" style="margin-bottom:12px">
    <input type="search" name="q" value="<?= h($f['q']) ?>" class="form-control grow" placeholder="ค้นหาชื่อ / HN / เบอร์โทร / ที่อยู่" />
    <?= flood_region_select($this->regions, array('name' => 'region', 'class' => 'form-control', 'data-rg-for' => 'fbProvince', 'aria-label' => 'ภาค'), $f['region'] ?? '', 'ทุกภาค') ?>
    <?= flood_province_select($this->provinces, array('name' => 'province', 'id' => 'fbProvince', 'class' => 'form-control', 'data-pv-for' => 'vfAmphoe', 'aria-label' => 'จังหวัด'), $f['province'], 'ทุกจังหวัด') ?>
    <select name="amphoe" class="form-control" id="vfAmphoe">
        <option value="">ทุกอำเภอ</option>
        <?= flood_amphoe_options($this->amphoes, $f['amphoe'], 'อ.') ?>
    </select>
    <select name="tambon" class="form-control" id="vfTambon" data-selected="<?= h($f['tambon']) ?>"><option value="">ทุกตำบล</option></select>
    <select name="group" class="form-control"><?= flood_options($groups, $f['group'], 'ทุกกลุ่ม') ?></select>
    <select name="evac" class="form-control"><?= flood_options($evac, $f['evac'], 'ทุกสถานะ') ?></select>
    <label class="checkbox-inline"><input type="checkbox" name="in_zone" value="1"<?= $f['in_zone'] ? ' checked' : '' ?> /> เฉพาะในพื้นที่ประกาศ</label>
    <label class="checkbox-inline"><input type="checkbox" name="no_location" value="1"<?= $f['no_location'] ? ' checked' : '' ?> /> ยังไม่มีพิกัด</label>
    <button type="submit" class="btn btn-default"><i class="fa fa-search"></i> ค้นหา</button>
</form>

<?php if ($mapRows) { ?>
<div class="flood-card">
    <div class="flood-card-body" style="padding:10px">
        <div id="vulnMap" class="flood-map map-sm"></div>
    </div>
</div>
<?php } ?>

<div class="flood-card">
    <div class="table-responsive">
        <table class="table table-hover table-cards" style="margin:0">
            <thead>
                <tr>
                    <th>ชื่อ-สกุล</th>
                    <th>กลุ่ม / การเคลื่อนย้าย</th>
                    <th>ที่อยู่</th>
                    <th>ติดต่อ</th>
                    <th>พื้นที่ประกาศ</th>
                    <th>สถานะ</th>
                    <th style="width:1%"></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows) { ?>
                <tr><td colspan="7"><div class="empty-state"><i class="fa fa-users"></i>ไม่พบข้อมูล</div></td></tr>
                <?php } ?>
                <?php foreach ($rows as $r) { ?>
                <tr>
                    <td data-label="ชื่อ-สกุล"><b><?= h($r['name']) ?></b>
                        <div class="small-muted"><?= $r['age'] !== '' ? 'อายุ ' . (int) $r['age'] . ' ปี' : '' ?><?= $r['sex'] ? ' · ' . ($r['sex'] === 'M' ? 'ชาย' : 'หญิง') : '' ?><?= $r['hn'] ? ' · HN ' . h($r['hn']) : '' ?></div>
                        <?php if (!empty($r['src_id'])) { ?><div class="small-muted" title="นำเข้าจาก Google Sheet · ดึงล่าสุด <?= h($r['src_synced_at'] ? flood_thai_date($r['src_synced_at']) : '-') ?>"><i class="fa fa-table"></i> <?= h(isset($vSrcName[(int) $r['src_id']]) ? $vSrcName[(int) $r['src_id']] : 'จากชีต') ?></div><?php } ?>
                    </td>
                    <td data-label="กลุ่ม"><?= flood_tags(flood_codes_names($r['vuln_groups'], $groups)) ?>
                        <?php if ($r['mobility']) { ?><div class="small-muted"><?= h(flood_opt_name($mob, $r['mobility'])) ?></div><?php } ?>
                        <?php if ($r['medical_needs']) { ?><div class="small-muted"><i class="fa fa-medkit"></i> <?= h($r['medical_needs']) ?></div><?php } ?>
                    </td>
                    <td data-label="ที่อยู่"><?= h((string) $r['address']) ?><?= $r['moo'] ? ' ม.' . h($r['moo']) : '' ?>
                        <div class="small-muted"><?= $r['tambon_name'] ? 'ต.' . h($r['tambon_name']) . ' ' : '' ?><?= $r['amphoe_name'] ? 'อ.' . h($r['amphoe_name']) : '' ?></div>
                        <?php if ($r['lat'] === null) { ?><div class="small-muted text-warning"><i class="fa fa-map-marker"></i> ยังไม่มีพิกัด</div><?php } ?>
                    </td>
                    <td data-label="ติดต่อ">
                        <?php if ($r['phone']) { ?><a href="tel:<?= h($r['phone']) ?>"><i class="fa fa-phone"></i> <?= h(flood_format_phone($r['phone'])) ?></a><?php } ?>
                        <?php if ($r['caregiver_name'] || $r['caregiver_phone']) { ?>
                        <div class="small-muted">ผู้ดูแล: <?= h((string) $r['caregiver_name']) ?>
                            <?php if ($r['caregiver_phone']) { ?><a href="tel:<?= h($r['caregiver_phone']) ?>"><?= h(flood_format_phone($r['caregiver_phone'])) ?></a><?php } ?></div>
                        <?php } ?>
                    </td>
                    <td data-label="พื้นที่ประกาศ">
                        <?php if ($r['zone']) { ?>
                        <?= flood_level_badge($r['zone']['level']) ?><div class="small-muted"><?= h($r['zone']['name']) ?></div>
                        <?php } else { ?><span class="text-muted">—</span><?php } ?>
                    </td>
                    <td data-label="สถานะ"><?= flood_status_label($evac, $r['evac_status']) ?>
                        <?php if ($r['evac_place']) { ?><div class="small-muted"><?= h($r['evac_place']) ?></div><?php } ?>
                        <?php if ($r['last_checked_at']) { ?><div class="small-muted">ติดตาม <?= h(flood_ago($r['last_checked_at'])) ?></div><?php } ?>
                    </td>
                    <td data-label="" class="nowrap">
                        <button type="button" class="btn btn-primary btn-sm js-vstatus" data-id="<?= (int) $r['person_id'] ?>"><i class="fa fa-refresh"></i> อัปเดตสถานะ</button>
                        <a href="<?= URL ?>flood/vulnerableForm/<?= (int) $r['person_id'] ?>" class="btn btn-default btn-sm" title="แก้ไข"><i class="fa fa-pencil"></i></a>
                    </td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>
<?php $pg = $res; $pgPath = 'flood/vulnerable'; include __DIR__ . '/_pagination.php'; ?>

<?php if (empty($this->vImportReady)) { ?>
<?php if (!empty($this->vIsAdmin)) { ?><div class="alert alert-warning"><i class="fa fa-info-circle"></i> ยังเปิดการดึงรายชื่อจาก Google Sheet ไม่ได้ (ระบบเพิ่มตารางเองไม่ได้) — ให้ผู้ดูแลรัน <code>php sql/apply_schema.php 20</code></div><?php } ?>
<?php } elseif ($vSources || !empty($this->vIsAdmin)) { ?>
<!-- แหล่งข้อมูลรายชื่อ (Google Sheet) -->
<div class="flood-card vsrc-card">
    <div class="flood-card-header"><span><i class="fa fa-table"></i> ข้อมูลจาก Google Sheet (รายชื่อรายคน / รายงานศูนย์พักพิง)</span>
        <?php if (!empty($this->vIsAdmin)) { ?><button type="button" class="btn btn-default btn-sm js-vsrc" data-id="0"><i class="fa fa-plus"></i> เพิ่มชีต</button><?php } ?>
    </div>
    <?php if (!$vSources) { ?>
    <div class="flood-card-body small-muted">ยังไม่มีชีต — กด <b>เพิ่มชีต</b> แล้ววางลิงก์ Google Sheet ที่เป็นรายชื่อรายคน (1 แถว = 1 คน)</div>
    <?php } else { ?>
    <div class="table-responsive">
        <table class="table table-cards" style="margin:0">
            <thead><tr><th>ชีต</th><th>ในทะเบียน</th><th>ดึงล่าสุด</th><th style="width:1%"></th></tr></thead>
            <tbody>
            <?php foreach ($vSources as $src) { $cols = $src['last_columns'] ? json_decode($src['last_columns'], true) : null; ?>
                <tr class="<?= (int) $src['is_active'] ? '' : 'text-muted' ?>">
<?php $isShelter = isset($src['kind']) && $src['kind'] === 'shelter'; ?>
                    <td data-label="ชีต"><b><?= h($src['name']) ?></b><?= $isShelter ? ' <span class="label label-info">รายงานศูนย์พักพิง</span>' : '' ?><?= (int) $src['is_active'] ? '' : ' <span class="label label-default">ปิดอยู่</span>' ?>
                        <div class="small-muted"><a href="<?= h($src['sheet_url']) ?>" target="_blank" rel="noopener">เปิด Google Sheet <i class="fa fa-external-link"></i></a>
                            <?php if ($isShelter) { ?> · ดึงแท็บรายวัน (ชื่อแท็บแบบ "28 ก.ย.69") ย้อนหลัง 21 วัน<?php } elseif ($src['default_groups']) { ?> · ไม่ระบุกลุ่ม = <?= h(implode(', ', flood_codes_names($src['default_groups'], $groups))) ?><?php } ?></div>
                        <?php if ($cols && (!empty($cols['mapped']) || !empty($cols['unmapped']))) { ?>
                        <details class="vsrc-cols"><summary>คอลัมน์ที่ระบบอ่าน<?= $isShelter ? ' (แท็บล่าสุด)' : '' ?></summary>
                            <ul>
                            <?php foreach ((array) $cols['mapped'] as $hd => $fd) { ?><li><?= h($hd) ?> → <b><?= h(isset($fieldLabels[$fd]) ? $fieldLabels[$fd] : (isset($shLabels[$fd]) ? $shLabels[$fd] : ($fd === '_raw' ? 'เก็บไว้ดูในรายละเอียด' : $fd))) ?></b></li><?php } ?>
                            <?php foreach ((array) $cols['unmapped'] as $hd) { ?><li class="text-muted"><?= h($hd) ?> → เก็บไว้ดูในข้อมูลจากชีต</li><?php } ?>
                            </ul>
                        </details>
                        <?php } ?>
                    </td>
                    <td data-label="ในทะเบียน"><?php if ($isShelter) { ?><?= (int) $src['days'] ?> วัน<?= $src['last_day'] ? ' · ล่าสุด ' . h(flood_thai_date($src['last_day'], false)) : '' ?><?php } else { ?><?= (int) $src['people'] ?> คน<?php } ?></td>
                    <td data-label="ดึงล่าสุด">
                        <?php if ($src['last_sync_at']) { ?><?= h(flood_ago($src['last_sync_at'])) ?><?php if (!$src['last_error'] && $src['last_rows'] !== null) { ?><?php if ($isShelter) { ?> · <?= (int) $src['last_rows'] ?> ศูนย์<?php } else { ?> · <?= (int) $src['last_rows'] ?> แถว · ใหม่ <?= (int) $src['last_new'] ?> · ปรับปรุง <?= (int) $src['last_updated'] ?><?php } ?><?php } ?><?php } else { ?><span class="text-muted">ยังไม่เคย</span><?php } ?>
                        <?php if ($src['last_error']) { ?><div class="text-danger small"><i class="fa fa-exclamation-circle"></i> <?= h($src['last_error']) ?></div><?php } ?>
                    </td>
                    <td data-label="" class="nowrap">
                        <?php if ((int) $src['is_active']) { ?><button type="button" class="btn btn-default btn-sm js-vsrc-sync" data-id="<?= (int) $src['source_id'] ?>" data-name="<?= h($src['name']) ?>" data-url="<?= h($src['sheet_url']) ?>" data-kind="<?= $isShelter ? 'shelter' : 'persons' ?>" title="ดึงจาก Google Sheet"><i class="fa fa-refresh"></i> ดึง</button><?php } ?>
                        <button type="button" class="btn btn-default btn-sm js-vsrc-upload" data-id="<?= (int) $src['source_id'] ?>" data-name="<?= h($src['name']) ?>" title="อัปโหลดไฟล์ CSV"><i class="fa fa-upload"></i> CSV</button>
                        <?php if (!empty($this->vIsAdmin)) { ?>
                        <button type="button" class="btn btn-default btn-sm js-vsrc" data-id="<?= (int) $src['source_id'] ?>" data-name="<?= h($src['name']) ?>" data-url="<?= h($src['sheet_url']) ?>" data-groups="<?= h((string) $src['default_groups']) ?>" data-kind="<?= $isShelter ? 'shelter' : 'persons' ?>" title="แก้ไข"><i class="fa fa-pencil"></i></button>
                        <button type="button" class="btn btn-default btn-sm js-vsrc-toggle" data-id="<?= (int) $src['source_id'] ?>" title="<?= (int) $src['is_active'] ? 'ปิดการดึง' : 'เปิดใช้' ?>"><i class="fa <?= (int) $src['is_active'] ? 'fa-pause' : 'fa-play' ?>"></i></button>
                        <?php } ?>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
    <?php } ?>
    <div class="flood-card-body small-muted">ปุ่ม <b>ดึง</b> ให้เบราว์เซอร์ของท่านอ่านชีตจาก Google แล้วส่งเข้าระบบ (เซิร์ฟเวอร์โรงพยาบาลออกอินเทอร์เน็ตไม่ได้) — ชีตที่ตั้ง "จำกัด" ต้องล็อกอิน Google บัญชีที่มีสิทธิ์ดูชีตในเบราว์เซอร์นี้ หรือดาวน์โหลดเป็น .csv แล้วกด <b>CSV</b>
        · ดึงซ้ำได้ ไม่เกิดรายชื่อซ้ำ (จับคู่ด้วย HN → เบอร์โทร+ชื่อ → ชื่อ+ตำบล) · สถานะอพยพ บันทึก และพิกัดที่ปักเองไม่ถูกทับ · คนที่นำออกจากทะเบียนแล้วไม่ถูกเพิ่มกลับ · ไม่เก็บเลขบัตรประชาชน</div>
</div>

<!-- อัปโหลด CSV -->
<div class="modal fade" id="vulnUploadModal" tabindex="-1" role="dialog">
    <div class="modal-dialog">
        <form class="modal-content" id="vulnUploadForm" enctype="multipart/form-data">
            <div class="modal-header">
                <h4 class="modal-title">อัปโหลด CSV: <span id="vuName"></span></h4>
                <button type="button" class="close" data-dismiss="modal" aria-label="ปิด">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="source_id" id="vuId" />
                <input type="hidden" name="_csrf" value="<?= h(flood_csrf_token()) ?>" />
                <ol class="small-muted" style="padding-left:18px">
                    <li>เปิด Google Sheet → แท็บรายชื่อ → <b>ไฟล์ → ดาวน์โหลด → ค่าที่คั่นด้วยจุลภาค (.csv)</b></li>
                    <li>เลือกไฟล์ที่ได้ด้านล่าง — นำเข้าไฟล์เดิมซ้ำได้ ไม่เกิดรายชื่อซ้ำ</li>
                </ol>
                <input type="file" name="csv" accept=".csv,text/csv" class="form-control" required />
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">ยกเลิก</button>
                <button type="submit" class="btn btn-primary"><i class="fa fa-upload"></i> นำเข้า</button>
            </div>
        </form>
    </div>
</div>
<?php } ?>

<!-- อัปเดตสถานะการอพยพ -->
<div class="modal fade" id="vStatusModal" tabindex="-1" role="dialog">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title">อัปเดตสถานะ: <span id="vsName"></span></h4>
                <button type="button" class="close" data-dismiss="modal" aria-label="ปิด">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="vsId" />
                <div class="chip-grid cols-2" style="margin-bottom:10px">
                    <?php foreach ($evac as $code => $o) { ?>
                    <label class="chip"><input type="radio" name="vs_status" value="<?= h($code) ?>" />
                        <span class="chip-body"><span class="chip-title"><?= h($o['name']) ?></span></span></label>
                    <?php } ?>
                </div>
                <div class="form-group">
                    <label for="vsPlace">สถานที่อพยพ / ที่พักพิง</label>
                    <input type="text" class="form-control" id="vsPlace" maxlength="200" placeholder="เช่น ศูนย์พักพิงโรงเรียน… / บ้านญาติ" />
                </div>
                <div class="form-group">
                    <label for="vsNote">บันทึก</label>
                    <textarea class="form-control" id="vsNote" rows="2" maxlength="1000"></textarea>
                </div>
                <div id="vsLogs"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">ปิด</button>
                <button type="button" class="btn btn-primary" id="vsSave"><i class="fa fa-save"></i> บันทึก</button>
            </div>
        </div>
    </div>
</div>

<script>
    window.VULN_MAP = <?= flood_js($mapRows) ?>;
    window.VULN_ZONES = <?= flood_js($this->activeZones) ?>;
    window.VULN_TAMBONS = <?= flood_js($this->tambons) ?>;
    window.VULN_EVAC = <?= flood_js($evac) ?>;
    window.VULN_GROUPS = <?= flood_js($groups) ?>;
</script>
