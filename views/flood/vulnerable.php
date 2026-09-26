<?php
$f = $this->vFilters;
$res = $this->vResult;
$rows = $res['rows'];
$s = $this->vSummary;
$groups = flood_vulnerable_groups();
$mob = flood_mobility_options();
$evac = flood_evac_statuses();
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
    <a href="<?= URL ?>flood/vulnerableForm" class="btn btn-primary"><i class="fa fa-user-plus"></i> เพิ่มบุคคล</a>
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

<form class="filter-bar" method="get" action="<?= URL ?>flood/vulnerable" style="margin-bottom:12px">
    <input type="search" name="q" value="<?= h($f['q']) ?>" class="form-control grow" placeholder="ค้นหาชื่อ / HN / เบอร์โทร / ที่อยู่" />
    <?= flood_province_select($this->provinces, array('name' => 'province', 'class' => 'form-control', 'data-pv-for' => 'vfAmphoe', 'aria-label' => 'จังหวัด'), $f['province'], 'ทุกจังหวัด') ?>
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
</script>
