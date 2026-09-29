<?php
/** Refer เข้าโรงพยาบาลช่วงอุทกภัย — ดู controllers/sat.php (refer) และ models/refer_model.php */
$s = $this->refer;
$edit = $this->referCanEdit;
$d = function ($date) {
    return $date ? flood_thai_date($date, false) : '–';
};
$bars = function ($rows) {
    $max = 0;
    foreach ($rows as $r) {
        $max = max($max, (int) $r['c']);
    }
    $h = '';
    foreach ($rows as $r) {
        $w = $max ? round((int) $r['c'] * 100 / $max) : 0;
        $h .= '<div class="rf-bar-row"><div class="rf-bar-name">' . h($r['name']) . '</div><div class="rf-bar-track"><div class="rf-bar-fill" style="width:'
            . $w . '%"></div></div><div class="rf-bar-num">' . (int) $r['c'] . '</div></div>';
    }
    return $h ?: '<div class="empty-state">ยังไม่มีข้อมูล</div>';
};
?>
<div class="flood-page-header">
    <h2 class="flood-page-title"><i class="fa fa-ambulance"></i> Refer เข้า รพ. ช่วงอุทกภัย</h2>
    <div class="flood-page-actions">
        <?php if ($edit) { ?>
        <button type="button" class="btn btn-primary" id="utPull"><i class="fa fa-refresh"></i> ดึงจาก Google Sheet</button>
        <label class="btn btn-default" title="ดาวน์โหลดชีตเป็น Microsoft Excel (.xlsx) แล้วอัปโหลด">
            <i class="fa fa-upload"></i> อัปโหลด .xlsx
            <input type="file" id="utFile" accept=".xlsx" hidden>
        </label>
        <?php } ?>
        <a href="<?= URL ?>sat" class="btn btn-default"><i class="fa fa-crosshairs"></i> ห้อง SAT</a>
    </div>
</div>

<div class="ut-src small-muted">
    <?php if ($edit) { ?>ที่มา: <a href="<?= h($this->referSheet) ?>" target="_blank" rel="noopener">Google Sheet "Refer ภาวะอุทกภัย 2569"</a>
    (แท็บ <?= h(implode(' · ', $this->referTabs)) ?>)<?php } else { ?>ที่มา: ทะเบียน Refer ภาวะอุทกภัย 2569 · แสดงเฉพาะตัวเลขสรุป<?php } ?>
    · นำเข้าล่าสุด <?= $s['imported_at'] ? h(flood_thai_date($s['imported_at'])) . ' น.' : 'ยังไม่เคยนำเข้า' ?>
    <?php if ($edit) { ?> · <a href="#" id="utSheetEdit">เปลี่ยนลิงก์ชีต</a><?php } ?>
</div>
<div id="utResult" class="alert alert-info hidden" role="status"></div>

<div class="flood-kpi-grid ut-kpis">
    <div class="flood-kpi-card kpi-info"><i class="fa fa-ambulance kpi-icon"></i>
        <div class="kpi-value"><?= (int) $s['total'] ?></div><div class="kpi-label">Refer ทั้งหมด</div>
        <div class="kpi-sub"><?= $s['byDate'] ? 'ตั้งแต่ ' . h($d(end($s['byDate'])['d'])) : 'ยังไม่มีข้อมูล' ?></div></div>
    <div class="flood-kpi-card kpi-warn"><i class="fa fa-calendar kpi-icon"></i>
        <div class="kpi-value"><?= (int) $s['today'] ?></div><div class="kpi-label">วันนี้</div>
        <div class="kpi-sub"><?= h(flood_thai_date(time(), false)) ?></div></div>
    <div class="flood-kpi-card kpi-danger"><i class="fa fa-heartbeat kpi-icon"></i>
        <div class="kpi-value"><?= (int) $s['ett'] ?></div><div class="kpi-label">on ET tube</div>
        <div class="kpi-sub">ต้องใช้เครื่องช่วยหายใจ / เตียงวิกฤต</div></div>
    <div class="flood-kpi-card kpi-info"><i class="fa fa-medkit kpi-icon"></i>
        <div class="kpi-value"><?= (int) $s['o2'] ?></div><div class="kpi-label">on O2</div>
        <div class="kpi-sub">canular / mask c bag</div></div>
</div>

<?php if ($s['byDate']) { ?>
<div class="ut-days rf-days">
    <?php foreach ($s['byDate'] as $r) { ?><span class="ut-day"><?= h($d($r['d'])) ?> · <b><?= (int) $r['c'] ?></b> ราย</span><?php } ?>
</div>
<?php } ?>

<div class="ut-grid rf-grid">
    <div class="flood-card"><div class="flood-card-header">รพ.ต้นทาง</div><div class="rf-bars"><?= $bars($s['byHosp']) ?></div></div>
    <div class="flood-card"><div class="flood-card-header">แผนก</div><div class="rf-bars"><?= $bars($s['byDept']) ?></div></div>
    <div class="flood-card"><div class="flood-card-header">Admit ward</div><div class="rf-bars"><?= $bars($s['byAdmit']) ?></div></div>
    <div class="flood-card"><div class="flood-card-header">อุปกรณ์ขณะส่งต่อ · รับที่ (ER / pass ward)</div>
        <div class="rf-bars"><?= $bars($s['byEquip']) ?></div><div class="rf-bars rf-sep"><?= $bars($s['byPass']) ?></div></div>
</div>

<?php if ($edit) { /* ผู้บริหาร/ดูอย่างเดียว เห็นเฉพาะตัวเลขสรุปด้านบน — ไม่แสดงการ์ดรายการผู้ป่วย */ ?>
<div class="flood-card">
    <div class="flood-card-header"><span><i class="fa fa-list"></i> รายการ Refer</span><small class="small-muted"><?= (int) $s['total'] ?> ราย</small></div>
    <?php if (!$s['rows']) { ?>
    <div class="empty-state">ยังไม่มีข้อมูล — กด "ดึงจาก Google Sheet"</div>
    <?php } else { ?>
    <div class="table-responsive"><table class="table table-condensed table-hover ut-table table-cards">
        <thead><tr><th>#</th><th>วันที่/เวลา</th><th>ผู้ป่วย</th><th>รพ.ต้นทาง</th><th>Dx</th><th>อุปกรณ์</th><th>แผนก</th><th>รับที่</th><th>Admit</th><th>Staff / ผู้บันทึก</th></tr></thead>
        <tbody>
        <?php foreach ($s['rows'] as $r) { ?>
            <tr>
                <td data-label="ลำดับ"><?= $r['row_no'] !== null ? (int) $r['row_no'] : '' ?></td>
                <td data-label="วันที่" class="nowrap"><?= h($d($r['refer_date'])) ?> <?= h($r['refer_time']) ?></td>
                <td data-label="ผู้ป่วย"><?= h($r['patient_name']) ?><?= $r['nationality'] && $r['nationality'] !== 'ไทย' ? ' <span class="tag tag-warn">' . h($r['nationality']) . '</span>' : '' ?></td>
                <td data-label="รพ.ต้นทาง"><?= h($r['from_hosp']) ?></td>
                <td data-label="Dx"><?= h($r['dx']) ?></td>
                <td data-label="อุปกรณ์"><?= h($r['equipment']) ?></td>
                <td data-label="แผนก"><?= h($r['dept']) ?></td>
                <td data-label="รับที่"><?= h($r['refer_to']) ?><?= $r['pass_type'] ? '<div class="small-muted">' . h($r['pass_type']) . '</div>' : '' ?></td>
                <td data-label="Admit"><?= h($r['admit_ward']) ?></td>
                <td data-label="Staff" class="small"><?= h($r['staff']) ?><?= $r['officer'] ? '<div class="small-muted">' . h($r['officer']) . '</div>' : '' ?>
                    <?= $r['note'] ? '<div class="small-muted">' . h($r['note']) . '</div>' : '' ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table></div>
    <?php } ?>
</div>

<?php } ?>
<?php if ($edit) { ?><script>window.UTIL = <?= flood_js(array('sheet' => $this->referSheet, 'tabs' => $this->referTabs, 'canEdit' => $edit,
    'endpoint' => 'sat/referImport', 'sheetEndpoint' => 'sat/referSheet', 'tabHint' => 'ชื่อแท็บที่มีทะเบียน Refer (แถวหัวคอลัมน์ต้องมี "วันที่" และ "ชื่อ")')) ?>;</script><?php } ?>
