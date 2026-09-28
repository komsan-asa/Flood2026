<?php
$n = function ($v) {
    return $v === null ? '' : (int) $v;
};
$row = function ($u) use ($n) {
    $id = $u ? (int) $u['unit_id'] : 0;
    $v = function ($k) use ($u) { return $u ? (string) $u[$k] : ''; };
    ob_start(); ?>
    <tr class="js-mp-unit"<?= $u && !(int) $u['is_active'] ? ' style="opacity:.55"' : '' ?> data-id="<?= $id ?>">
        <td><input class="form-control" name="sort_order" value="<?= h($v('sort_order')) ?>" style="width:60px" aria-label="ลำดับ" /></td>
        <td><input class="form-control" name="unit_code" value="<?= h($v('unit_code')) ?>" maxlength="30" style="width:100px" aria-label="รหัส" placeholder="เช่น MED_M" /></td>
        <td>
            <input class="form-control" name="unit_name" value="<?= h($v('unit_name')) ?>" maxlength="150" aria-label="หน่วยงาน" placeholder="ชื่อหน่วยงาน" style="min-width:200px" />
            <input class="form-control" name="group_name" value="<?= h($v('group_name')) ?>" maxlength="150" aria-label="กลุ่มงาน" placeholder="กลุ่มงาน" style="margin-top:4px" list="mpGroupList" />
        </td>
        <td><input class="form-control" name="std_ratio" value="<?= h($v('std_ratio')) ?>" maxlength="30" style="width:80px" aria-label="เกณฑ์" /></td>
        <?php foreach (array('m', 'a', 'n') as $k) { ?>
        <td><input class="form-control mp-n" name="req_<?= $k ?>" value="<?= $u ? h($n($u['req_' . $k])) : '' ?>" inputmode="numeric" aria-label="วันทำการ <?= $k ?>" /></td>
        <?php } ?>
        <?php foreach (array('m', 'a', 'n') as $k) { ?>
        <td><input class="form-control mp-n" name="hol_<?= $k ?>" value="<?= $u ? h($n($u['hol_' . $k])) : '' ?>" inputmode="numeric" aria-label="วันหยุด <?= $k ?>" placeholder="=" /></td>
        <?php } ?>
        <td><input class="form-control" name="hr_depts" value="<?= h($v('hr_depts')) ?>" maxlength="500" style="min-width:200px" aria-label="หน่วยงานใน hosoffice" placeholder="ว่าง = ชื่อเดียวกับหน่วยงาน" list="mpDeptList" /></td>
        <td><input class="form-control" name="note" value="<?= h($v('note')) ?>" maxlength="300" style="min-width:200px" aria-label="หมายเหตุ" /></td>
        <td style="white-space:nowrap">
            <button type="button" class="btn btn-primary btn-sm js-mp-unit-save"><i class="fa fa-save"></i> <?= $id ? 'บันทึก' : 'เพิ่ม' ?></button>
            <?php if ($id) { ?>
            <button type="button" class="btn btn-default btn-sm js-mp-unit-toggle"><?= (int) $u['is_active'] ? 'ปิดใช้' : 'เปิดใช้' ?></button>
            <?php } ?>
        </td>
    </tr>
    <?php return ob_get_clean();
};
$groups = array();
foreach ($this->mpUnits as $u) {
    $groups[$u['group_name']] = true;
}
?>
<div class="flood-page-header">
    <h2 class="flood-page-title"><i class="fa fa-sliders"></i> กรอบอัตรากำลังรายเวร</h2>
    <div class="flood-page-actions">
        <a href="<?= URL ?>flood/manpower" class="btn btn-default"><i class="fa fa-arrow-left"></i> กลับหน้าอัตรากำลัง</a>
    </div>
</div>

<?php if (!$this->mpReady) { ?>
<div class="alert alert-danger">ยังไม่มีตารางอัตรากำลังและระบบสร้างเองไม่ได้ ให้ผู้ดูแลรัน <code>php sql/apply_schema.php 18</code></div>
<?php return; } ?>

<p class="text-muted">จำนวนพยาบาลวิชาชีพที่ต้องมีในแต่ละเวร · ช่อง <b>วันทำการ</b> ว่าง = หน่วยนี้ไม่มีเวรนั้น · ช่อง <b>วันหยุด</b> ว่าง = เท่าวันทำการ, 0 = ไม่มีเวรในวันหยุด
    · <b>รหัส</b> ต้องตรงกับรหัสหน่วยงานที่ระบบลงเวลาส่งมา (flood_mp_checkin.unit_code)</p>

<?php $unm = isset($this->mpUnmapped) ? $this->mpUnmapped : array(); ?>
<datalist id="mpDeptList"><?php foreach (array_keys($unm) as $d) { ?><option value="<?= h($d) ?>"></option><?php } ?></datalist>
<?php if ($unm) { ?>
<div class="alert alert-warning">
    <b><i class="fa fa-exclamation-triangle"></i> หน่วยงานใน hosoffice ที่มีคนลงเวลาแต่ยังไม่จับคู่</b> (7 วันล่าสุด — ใส่ชื่อในช่อง "หน่วยงานใน hosoffice" ของหอที่ถูกต้อง แล้วกดบันทึก)
    <div style="margin-top:6px;display:flex;flex-wrap:wrap;gap:6px">
        <?php foreach ($unm as $d => $c) { ?><span class="notice-chip"><?= h($d) ?> <b><?= (int) $c ?></b></span><?php } ?>
    </div>
</div>
<?php } ?>
<datalist id="mpGroupList"><?php foreach (array_keys($groups) as $g) { ?><option value="<?= h($g) ?>"></option><?php } ?></datalist>

<div class="flood-card">
    <div class="table-responsive">
        <table class="table mp-units-table" style="margin:0">
            <thead>
                <tr>
                    <th rowspan="2">ลำดับ</th><th rowspan="2">รหัส</th><th rowspan="2">หน่วยงาน / กลุ่มงาน</th><th rowspan="2">เกณฑ์</th>
                    <th colspan="3" class="text-center">วันทำการ <small>ช / บ / ด</small></th>
                    <th colspan="3" class="text-center">วันหยุด <small>ช / บ / ด</small></th>
                    <th rowspan="2">หน่วยงานใน hosoffice <small>(คั่นด้วย ,)</small></th><th rowspan="2">หมายเหตุ</th><th rowspan="2"></th>
                </tr>
                <tr><th>ช</th><th>บ</th><th>ด</th><th>ช</th><th>บ</th><th>ด</th></tr>
            </thead>
            <tbody>
                <?php foreach ($this->mpUnits as $u) { echo $row($u); } ?>
                <tr class="mp-group"><td colspan="14">เพิ่มหน่วยงานใหม่</td></tr>
                <?= $row(null) ?>
            </tbody>
        </table>
    </div>
</div>

<h3 style="margin:22px 0 6px;font-size:18px;font-weight:700"><i class="fa fa-calendar"></i> วันหยุดราชการ / นักขัตฤกษ์</h3>
<p class="text-muted">วันเสาร์-อาทิตย์นับเป็นวันหยุดอัตโนมัติ · เพิ่มวันหยุดอื่นที่ต้องใช้กรอบวันหยุด</p>
<form class="mp-hol-row" id="mpHolForm">
    <input type="date" name="hdate" class="form-control" required aria-label="วันที่" />
    <input type="text" name="name" class="form-control" maxlength="150" required placeholder="ชื่อวันหยุด" aria-label="ชื่อวันหยุด" style="min-width:240px" />
    <button type="submit" class="btn btn-primary"><i class="fa fa-plus"></i> เพิ่มวันหยุด</button>
</form>
<div class="flood-card">
    <table class="table" style="margin:0">
        <tbody>
            <?php if (!$this->mpHolidays) { ?>
            <tr><td class="text-muted">ยังไม่มีวันหยุดตั้งแต่ต้นปีนี้</td></tr>
            <?php } ?>
            <?php foreach ($this->mpHolidays as $hd) { ?>
            <tr>
                <td style="width:180px"><?= h(flood_thai_date($hd['hdate'], false)) ?></td>
                <td><?= h($hd['name']) ?></td>
                <td style="width:1%"><button type="button" class="btn btn-default btn-sm js-mp-hol-del" data-date="<?= h($hd['hdate']) ?>"><i class="fa fa-trash"></i> ลบ</button></td>
            </tr>
            <?php } ?>
        </tbody>
    </table>
</div>
