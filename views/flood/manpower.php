<?php
$shifts = Manpower_Model::shifts();
$date = $this->mpDate;
$day = $this->mpDay;
$m = $this->mpMatrix;
$sum = $this->mpSummary;
$isToday = $date === date('Y-m-d');
$curShift = $isToday ? Manpower_Model::currentShift() : '';
$dow = array('อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์');
$prev = date('Y-m-d', strtotime($date . ' -1 day'));
$next = date('Y-m-d', strtotime($date . ' +1 day'));
$qs = function ($d) {
    return URL . 'flood/manpower?' . http_build_query(array_filter(array('date' => $d, 'group' => $this->mpGroup)));
};
$canEdit = !isset($this->mpCanEdit) || $this->mpCanEdit;   // false = ผู้บริหาร/ดูอย่างเดียว
$statusLabel = array(
    'short' => 'ขาด', 'ok' => 'ครบ', 'over' => 'เกิน', 'nodata' => 'รอข้อมูล', 'wait' => 'ยังไม่ถึงเวร', 'none' => 'ไม่มีเวร',
);
?>
<div class="flood-page-header">
    <h2 class="flood-page-title"><i class="fa fa-id-badge"></i> อัตรากำลังพยาบาลรายเวร</h2>
    <div class="flood-page-actions">
        <?php if ($this->mpIsAdmin) { ?>
        <a href="<?= URL ?>flood/manpowerUnits" class="btn btn-default"><i class="fa fa-sliders"></i> ตั้งค่ากรอบ / วันหยุด</a>
        <?php } ?>
    </div>
</div>

<?php if (!$this->mpReady) { ?>
<div class="alert alert-danger">ยังไม่มีตารางอัตรากำลังและระบบสร้างเองไม่ได้ ให้ผู้ดูแลรัน <code>php sql/apply_schema.php 18</code></div>
<?php return; } ?>

<form class="filter-bar mp-bar" method="get" action="<?= URL ?>flood/manpower">
    <div class="mp-datenav">
    <a href="<?= h($qs($prev)) ?>" class="btn btn-default" title="วันก่อนหน้า" aria-label="วันก่อนหน้า"><i class="fa fa-chevron-left"></i></a>
    <input type="date" name="date" value="<?= h($date) ?>" class="form-control mp-date" aria-label="วันที่" onchange="this.form.submit()" />
    <a href="<?= h($qs($next)) ?>" class="btn btn-default" title="วันถัดไป" aria-label="วันถัดไป"><i class="fa fa-chevron-right"></i></a>
    <?php if (!$isToday) { ?><a href="<?= h($qs(date('Y-m-d'))) ?>" class="btn btn-default">วันนี้</a><?php } ?>
    </div>
    <select name="group" class="form-control grow" aria-label="กลุ่มงาน" onchange="this.form.submit()">
        <option value="">ทุกกลุ่มงาน</option>
        <?php foreach ($this->mpGroups as $g) { ?>
        <option value="<?= h($g) ?>"<?= $this->mpGroup === $g ? ' selected' : '' ?>><?= h($g) ?></option>
        <?php } ?>
    </select>
    <label class="mp-only-short"><input type="checkbox" id="mpOnlyShort" /> แสดงเฉพาะหน่วยที่ขาด / รอข้อมูล</label>
</form>

<?php $api = isset($this->mpApi) ? $this->mpApi : array('on' => false, 'hint' => '', 'last' => null, 'every' => 5); ?>
<div class="mp-api<?= $api['on'] && $api['last'] && $api['last']['error'] ? ' is-err' : '' ?>">
    <?php if (!$api['on']) { ?>
    <span><i class="fa fa-plug"></i> ยังไม่ได้เชื่อมระบบลงเวลา (Flood2026-site-api) — <?= h($api['hint']) ?> · กรอกจำนวนเองได้โดยกดที่ช่อง</span>
    <?php } else { $l = $api['last']; ?>
    <span><i class="fa fa-clock-o"></i> ข้อมูลลงเวลาจาก hosoffice
        <?php if ($l) { ?>· ดึงล่าสุด <?= h(date('H:i', strtotime($l['synced_at']))) ?> น.
            <?php if ($l['error']) { ?><b class="text-danger">ไม่สำเร็จ: <?= h($l['error']) ?></b>
            <?php } else { ?>· สแกนเข้าเวร <?= (int) $l['fetched'] ?> รายการ เข้าหน่วยงาน <?= (int) $l['mapped'] ?>
                <?php $um = $l['unmapped'] ? (array) json_decode($l['unmapped'], true) : array(); if ($um) { ?>
                · <span title="<?= h(implode(', ', array_keys($um))) ?>">หน่วยงานยังไม่จับคู่ <?= (int) array_sum($um) ?> คน</span><?php } ?>
            <?php } ?>
        <?php } else { ?>· ยังไม่เคยดึงข้อมูลวันนี้<?php } ?>
        <small class="text-muted">(ดึงใหม่อัตโนมัติทุก <?= (int) $api['every'] ?> นาทีเมื่อเปิดหน้าวันนี้/เมื่อวาน)</small>
    </span>
    <?php if ($canEdit) { ?><button type="button" class="btn btn-default btn-sm js-mp-sync" data-date="<?= h($date) ?>"><i class="fa fa-refresh"></i> ดึงข้อมูลลงเวลา</button><?php } ?>
    <?php } ?>
</div>

<div class="mp-day<?= $day['holiday'] ? ' is-holiday' : '' ?>">
    <b>วัน<?= $dow[(int) date('w', strtotime($date))] ?>ที่ <?= h(flood_thai_date($date, false)) ?></b>
    <span class="mp-day-tag"><i class="fa <?= $day['holiday'] ? 'fa-calendar-o' : 'fa-briefcase' ?>"></i> <?= h($day['name']) ?><?= $day['holiday'] ? ' (ใช้กรอบวันหยุด)' : '' ?></span>
    <?php if ($isToday) { ?><span class="mp-day-now"><i class="fa fa-clock-o"></i> ขณะนี้: เวร<?= h($shifts[$curShift]['name']) ?></span><?php } ?>
</div>

<div class="mp-sum-grid">
    <?php foreach ($shifts as $s => $meta) { $x = $sum[$s]; ?>
    <div class="mp-sum<?= $s === $curShift ? ' is-now' : '' ?><?= $x['short'] ? ' has-short' : '' ?>">
        <div class="mp-sum-h">เวร<?= h($meta['name']) ?> <small><?= h($meta['start']) ?>–<?= h($meta['end']) ?> น.</small></div>
        <div class="mp-sum-v"><?= (int) $x['actual'] ?><span> / <?= (int) $x['req'] ?> คน</span></div>
        <div class="mp-sum-chips">
            <span class="mp-chip st-ok">ครบ <?= (int) $x['ok'] ?></span>
            <span class="mp-chip st-short">ขาด <?= (int) $x['short'] ?><?= $x['missing'] ? ' หน่วย (' . (int) $x['missing'] . ' คน)' : '' ?></span>
            <span class="mp-chip st-over">เกิน <?= (int) $x['over'] ?></span>
            <?php if ($x['nodata']) { ?><span class="mp-chip st-nodata">รอข้อมูล <?= (int) $x['nodata'] ?></span><?php } ?>
            <?php if ($x['wait']) { ?><span class="mp-chip st-wait">ยังไม่ถึงเวร <?= (int) $x['wait'] ?></span><?php } ?>
        </div>
    </div>
    <?php } ?>
</div>

<?php $ssS = $this->mpStaff; $ssD = $this->mpStaffDepts; $ssLink = flood_can_menu(flood_normalize_role(($ssU = flood_session_user()) && isset($ssU['role']) ? $ssU['role'] : ''), 'staff'); include __DIR__ . '/_staff_summary.php'; ?>

<div class="flood-card">
    <div class="table-responsive">
        <table class="table mp-table" id="mpTable">
            <thead>
                <tr>
                    <th>หน่วยงาน</th>
                    <?php foreach ($shifts as $s => $meta) { ?>
                    <th class="mp-col<?= $s === $curShift ? ' is-now' : '' ?>">เวร<?= h($meta['name']) ?> <small>(<?= h($meta['short']) ?>)</small></th>
                    <?php } ?>
                </tr>
            </thead>
            <tbody>
                <?php if (!$this->mpUnits) { ?>
                <tr><td colspan="4"><div class="empty-state"><i class="fa fa-id-badge"></i>ยังไม่มีหน่วยงาน</div></td></tr>
                <?php } ?>
                <?php $lastGroup = null; foreach ($this->mpUnits as $u) {
                    $id = (int) $u['unit_id'];
                    $cells = $m[$id];
                    $flag = false;
                    foreach ($cells as $c) {
                        if (in_array($c['status'], array('short', 'nodata'), true)) {
                            $flag = true;
                        }
                    }
                    if ($u['group_name'] !== $lastGroup) { $lastGroup = $u['group_name']; ?>
                <tr class="mp-group"><td colspan="4"><?= h($u['group_name']) ?></td></tr>
                <?php } ?>
                <tr class="mp-row<?= $flag ? ' mp-flag' : '' ?>">
                    <td class="mp-unit">
                        <b><?= h($u['unit_name']) ?></b>
                        <div class="small-muted"><?= h($u['unit_code']) ?><?= $u['std_ratio'] ? ' · เกณฑ์ ' . h($u['std_ratio']) : '' ?></div>
                    </td>
                    <?php foreach ($shifts as $s => $meta) { $c = $cells[$s]; ?>
                    <td class="mp-col<?= $s === $curShift ? ' is-now' : '' ?>">
                        <?php if ($c['status'] === 'none') { ?>
                        <span class="mp-none">—</span>
                        <?php } else { ?>
                        <<?= $canEdit ? 'button type="button"' : 'span style="cursor:default"' ?> class="mp-cell st-<?= h($c['status']) ?><?= $canEdit ? ' js-mp-cell' : '' ?>"
                            <?php if ($canEdit) { ?>                            data-unit="<?= $id ?>" data-shift="<?= h($s) ?>" data-name="<?= h($u['unit_name']) ?>"
                            data-req="<?= (int) $c['req'] ?>" data-actual="<?= $c['src'] === 'manual' ? (int) $c['actual'] : '' ?>"
                            data-checkin="<?= $c['checkin'] === null ? '' : (int) $c['checkin'] ?>" data-other="<?= (int) $c['other'] ?>"
                            data-note="<?= h($c['note']) ?>" data-by="<?= h($c['by']) ?>" data-at="<?= h($c['at'] ? flood_thai_date($c['at']) : '') ?>"<?php } ?>
                            aria-label="<?= h($u['unit_name'] . ' เวร' . $meta['name'] . ' ' . $statusLabel[$c['status']]) ?>">
                            <span class="mp-num"><?= $c['actual'] === null ? '–' : (int) $c['actual'] ?><small>/<?= (int) $c['req'] ?></small></span>
                            <span class="mp-st"><?php
                                if ($c['status'] === 'short') { echo 'ขาด ' . (-$c['diff']); }
                                elseif ($c['status'] === 'over') { echo 'เกิน ' . $c['diff']; }
                                else { echo h($statusLabel[$c['status']]); } ?></span>
                            <?php if ($c['src'] === 'manual') { ?><i class="fa fa-pencil mp-src" title="กรอกเอง"></i><?php } elseif ($c['src'] === 'checkin') { ?><i class="fa fa-clock-o mp-src" title="จากการลงเวลา"></i><?php } ?>
                        </<?= $canEdit ? 'button' : 'span' ?>>
                        <?php } ?>
                    </td>
                    <?php } ?>
                </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>
<p class="small-muted mp-foot">
    นับเฉพาะพยาบาลวิชาชีพ (RN) ที่ลงเวลาในเวรนั้น ไม่นับคนซ้ำ · <i class="fa fa-clock-o"></i> = จากระบบลงเวลา · <i class="fa fa-pencil"></i> = เจ้าหน้าที่กรอกเอง (ใช้แทนค่าจากการลงเวลา)<?= $canEdit ? '' : ' · <i class="fa fa-eye"></i> ดูอย่างเดียว — กรอก/ดูรายชื่อผู้ลงเวลาได้เฉพาะเจ้าหน้าที่ศูนย์' ?>
    · เกณฑ์: หอที่มีพยาบาล 2 คนไม่ลดคน · ลดได้ครั้งละ 1 คนต่อเวร เช้าวันทำการไม่ลด · ผู้ป่วยใส่เครื่องช่วยหายใจ/HFNC นับยอดเพิ่ม 2 คนต่อราย
    · กรอบตามเอกสารเกณฑ์ลดเพิ่มคน 1 ก.ค. 2568
</p>

<?php if (!$canEdit) { return; } ?>
<div class="modal fade" id="mpModal" tabindex="-1" role="dialog">
    <div class="modal-dialog">
        <form class="modal-content" id="mpForm">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="ปิด">&times;</button>
                <h4 class="modal-title"><span id="mpmName"></span> <small id="mpmShift"></small></h4>
            </div>
            <div class="modal-body">
                <input type="hidden" name="unit_id" id="mpmUnit" />
                <input type="hidden" name="shift" id="mpmShiftKey" />
                <input type="hidden" name="date" value="<?= h($date) ?>" />
                <div class="mp-m-stats">
                    <div><small>กรอบ</small><b id="mpmReq"></b></div>
                    <div><small>ลงเวลา (RN)</small><b id="mpmCheckin"></b></div>
                    <div><small>ลงเวลา (อื่น ๆ)</small><b id="mpmOther"></b></div>
                </div>
                <div id="mpmList" class="mp-m-list"></div>
                <div class="form-group">
                    <label for="mpmActual">จำนวนพยาบาลวิชาชีพที่ขึ้นเวรจริง (กรอกเอง)</label>
                    <input type="number" min="0" max="99" class="form-control" name="actual" id="mpmActual" placeholder="เว้นว่าง = ใช้ข้อมูลจากการลงเวลา" />
                </div>
                <div class="form-group">
                    <label for="mpmNote">หมายเหตุ</label>
                    <input type="text" maxlength="300" class="form-control" name="note" id="mpmNote" placeholder="เช่น ขอเสริมจากหอผู้ป่วยอื่น 1 คน / ผู้ป่วยใส่ HFNC 2 ราย" />
                </div>
                <div class="small-muted" id="mpmBy"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default pull-left js-mp-clear">ลบค่าที่กรอก</button>
                <button type="button" class="btn btn-default" data-dismiss="modal">ยกเลิก</button>
                <button type="submit" class="btn btn-primary">บันทึก</button>
            </div>
        </form>
    </div>
</div>
