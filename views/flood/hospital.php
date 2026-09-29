<?php
/** ภาพรวม (โรงพยาบาล) — ดู Sat::overview() ใน controllers/sat.php · ตัวเลขรวมเท่านั้น ไม่มีรายชื่อ */
$hUser = flood_session_user();
$role = flood_normalize_role(isset($hUser['role']) ? $hUser['role'] : '');
$col = Sat_Model::colors();
$chip = function ($status, $text = null) use ($col) {
    if (!isset($col[$status])) {
        return '<span class="sat-chip sat-c-none">⚪ ' . h($text !== null ? $text : 'ยังไม่มีข้อมูล') . '</span>';
    }
    return '<span class="sat-chip sat-c-' . h($status) . '">' . $col[$status]['emoji'] . ' ' . h($text !== null ? $text : $col[$status]['name']) . '</span>';
};
$S = $this->hStaff;
$canStaff = flood_can_menu($role, 'staff');
$canMp = flood_can_menu($role, 'manpower');
?>
<div class="flood-page-header">
    <h2 class="flood-page-title"><i class="fa fa-hospital-o"></i> ภาพรวม (โรงพยาบาล)
        <?php if ($this->satReady) { ?><small class="sat-hosp-name"><?= h($this->sat['hosp']['name']) ?></small><?php } ?></h2>
    <div class="flood-page-actions">
        <a href="<?= URL ?>sat" class="btn btn-default"><i class="fa fa-crosshairs"></i> ห้องสถานการณ์ SAT</a>
    </div>
</div>

<?php if ($this->satReady) {
    $d = $this->satDeclared;
    $sug = $this->satSuggest;
?>
<div class="flood-card">
    <div class="flood-card-header">สถานะโรงพยาบาล</div>
    <div class="flood-card-body">
        <div class="hosp-status-row">
            <div>
                <div class="small-muted">SAT ประกาศ</div>
                <?= $d ? $chip($d['status']) : $chip('', 'ยังไม่ประกาศ') ?>
                <?php if ($d) { ?><div class="small-muted"><?= h(flood_thai_date($d['at'])) ?><?= $d['by'] !== '' ? ' · ' . h($d['by']) : '' ?></div><?php } ?>
            </div>
            <div>
                <div class="small-muted">ระบบประเมินจากข้อมูล (ข้อเสนอ)</div>
                <?= $chip($sug) ?>
            </div>
        </div>
        <?php if ($this->satReasons) { ?>
        <ul class="hosp-reasons">
            <?php foreach (array_slice($this->satReasons, 0, 6) as $r) { ?>
            <li><?= isset($col[$r[0]]) ? $col[$r[0]]['emoji'] : '' ?> <?= h($r[1]) ?></li>
            <?php } ?>
        </ul>
        <?php } ?>
    </div>
</div>
<?php } else { ?>
<div class="alert alert-warning">ยังไม่มีตาราง SAT — สถานะโรงพยาบาล หน่วยบริการ และเส้นทางยังแสดงไม่ได้</div>
<?php } ?>

<div class="flood-kpi-grid">
    <?php if ($S) { ?>
    <a class="flood-kpi-card kpi-danger" href="<?= $canStaff ? URL . 'flood/staff?level=affected' : '#' ?>">
        <div class="kpi-value"><?= (int) ($S['severe'] + $S['moderate']) ?></div>
        <div class="kpi-label">บุคลากรได้รับผลกระทบ</div>
        <div class="kpi-sub">รุนแรง <?= (int) $S['severe'] ?> · ปานกลาง <?= (int) $S['moderate'] ?> · ผู้ตอบ <?= (int) $S['total'] ?></div>
    </a>
    <div class="flood-kpi-card kpi-danger">
        <div class="kpi-value"><?= (int) $S['cant_work'] ?></div>
        <div class="kpi-label">มาทำงานไม่ได้</div>
        <div class="kpi-sub">ถูกตัดขาด / กลับบ้านไม่ได้ <?= (int) $S['stranded'] ?></div>
    </div>
    <div class="flood-kpi-card kpi-warn">
        <div class="kpi-value"><?= (int) $S['need_shelter'] ?></div>
        <div class="kpi-label">ต้องการที่พักด่วน</div>
        <div class="kpi-sub">บ้านถูกน้ำท่วม <?= (int) $S['home_hit'] ?></div>
    </div>
    <div class="flood-kpi-card kpi-warn">
        <div class="kpi-value"><?= (int) $S['waiting'] ?></div>
        <div class="kpi-label">ได้รับผลกระทบ ยังไม่ติดตาม</div>
        <div class="kpi-sub">ขอความช่วยเหลือ <?= (int) $S['need_help'] ?></div>
    </div>
    <?php } ?>
    <?php if ($this->satReady) {
        $mp = $this->sat['mp'];
        $fac = $this->sat['fac'];
        $shiftNames = array('M' => 'เช้า', 'A' => 'บ่าย', 'N' => 'ดึก');
    ?>
    <a class="flood-kpi-card <?= $mp['ready'] && count($mp['short']) ? 'kpi-danger' : 'kpi-info' ?>" href="<?= $canMp ? URL . 'flood/manpower' : '#' ?>">
        <div class="kpi-value"><?= $mp['ready'] ? count($mp['short']) : '–' ?></div>
        <div class="kpi-label">หน่วยที่ RN ขาดกรอบ (เวร<?= h(isset($shiftNames[$mp['shift']]) ? $shiftNames[$mp['shift']] : '') ?>)</div>
        <div class="kpi-sub">ครบ <?= (int) $mp['ok'] ?> · รอข้อมูล <?= (int) $mp['nodata'] ?> จาก <?= (int) $mp['units'] ?> หน่วย</div>
    </a>
    <a class="flood-kpi-card <?= ($fac['red'] + $fac['orange']) ? 'kpi-warn' : 'kpi-ok' ?>" href="<?= URL ?>sat#satFac">
        <div class="kpi-value"><?= (int) ($fac['red'] + $fac['orange']) ?> / <?= (int) $fac['count'] ?></div>
        <div class="kpi-label">หน่วยบริการ ปิด / ย้ายจุด</div>
        <div class="kpi-sub">🔴 <?= (int) $fac['red'] ?> · 🟠 <?= (int) $fac['orange'] ?> · 🟡 <?= (int) $fac['yellow'] ?> · ถึงรอบโทร <?= (int) $fac['overdue'] ?></div>
    </a>
    <?php
    // สาธารณูปโภค · Refer เข้า · กลุ่มเปราะบางในศูนย์พักพิง (ตัวเลขรวม — ดู Sat::utilityNow / referNow / shelterNow)
    $ux = $this->sat['util']['items'];
    $rf = $this->sat['refer'];
    $shl = $this->sat['shelter'];
    $kc = function ($s) {
        return $s === 'red' || $s === 'orange' ? 'kpi-danger' : ($s === 'yellow' ? 'kpi-warn' : ($s === 'green' ? 'kpi-ok' : 'kpi-info'));
    };
    if ($ux) {
        $uw = '';
        foreach ($ux as $x) {
            $uw = Sat_Model::worst($uw, $x['status']);
        }
        $o2 = isset($ux['util_o2']) ? $ux['util_o2'] : null;
        $usub = array_filter(array($o2 ? $o2['sub'] : '', isset($ux['util_fuel']) ? 'น้ำมันสำรอง ' . $ux['util_fuel']['value'] : '',
            isset($ux['util_water']) ? 'ถังพักน้ำ ' . $ux['util_water']['value'] : ''));
    ?>
    <a class="flood-kpi-card <?= $kc($uw) ?>" href="<?= URL ?>sat/utility">
        <div class="kpi-value"><?= $o2 ? h($o2['value']) : '–' ?></div>
        <div class="kpi-label">ออกซิเจนเหลวคงเหลือ · สาธารณูปโภค</div>
        <div class="kpi-sub"><?= h(implode(' · ', $usub)) ?></div>
    </a>
    <?php } ?>
    <?php if ($rf['ready']) { ?>
    <a class="flood-kpi-card <?= $rf['today'] ? 'kpi-warn' : 'kpi-info' ?>" href="<?= URL ?>sat/refer">
        <div class="kpi-value"><?= (int) $rf['total'] ?></div>
        <div class="kpi-label">Refer เข้า รพ. ช่วงอุทกภัย</div>
        <div class="kpi-sub">วันนี้ <?= (int) $rf['today'] ?> · เมื่อวาน <?= (int) $rf['yesterday'] ?> · on ET tube <?= (int) $rf['ett'] ?> · on O2 <?= (int) $rf['o2'] ?></div>
    </a>
    <?php } ?>
    <?php if ($shl['ready']) { $ss = $shl['sum']; ?>
    <a class="flood-kpi-card <?= $ss['bedridden'] + $ss['dialysis'] + $ss['pregnant'] ? 'kpi-warn' : 'kpi-info' ?>" href="<?= flood_can_menu($role, 'vulnerable') ? URL . 'flood/vulnerable' : '#' ?>">
        <div class="kpi-value"><?= (int) $ss['bedridden'] ?></div>
        <div class="kpi-label">ติดเตียงในศูนย์พักพิง</div>
        <div class="kpi-sub"><?= (int) $ss['shelters'] ?> ศูนย์ · ผู้พักพิง <?= number_format($ss['people']) ?> · ตั้งครรภ์ <?= (int) $ss['pregnant'] ?> · ล้างไต <?= (int) $ss['dialysis'] ?> · <?= h(flood_thai_date($shl['date'], false)) ?></div>
    </a>
    <?php } ?>
    <?php } ?>
</div>

<?php if ($this->satReady) {
    $rtypes = Sat_Model::routeTypes();
    $units = Sat_Model::criticalUnits();
    $st = $this->sat['staff'];
?>
<div class="row">
    <div class="col-md-6">
        <div class="flood-card">
            <div class="flood-card-header">เส้นทาง เข้า / ออก / Refer <a href="<?= URL ?>sat#satRoutes" class="small-muted">ดูใน SAT ›</a></div>
            <div class="flood-card-body">
                <?php if (!$this->sat['routes']) { ?><div class="small-muted">ยังไม่ได้ตั้งเส้นทาง</div><?php } else { ?>
                <table class="table table-condensed">
                    <thead><tr><th>ประเภท</th><th>เส้นทาง</th><th>สถานะ</th></tr></thead>
                    <tbody>
                    <?php foreach ($this->sat['routes'] as $r) { ?>
                    <tr>
                        <td class="nowrap"><?= h(isset($rtypes[$r['rtype']]) ? $rtypes[$r['rtype']]['name'] : $r['rtype']) ?></td>
                        <td><?= h($r['name']) ?><?= $r['is_backup'] ? ' <span class="small-muted">(สำรอง)</span>' : '' ?></td>
                        <td class="nowrap"><?= $chip($r['effective']) ?></td>
                    </tr>
                    <?php } ?>
                    </tbody>
                </table>
                <?php } ?>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="flood-card">
            <div class="flood-card-header">บุคลากรหน่วยสำคัญ (4 สี) <a href="<?= URL ?>sat#satCritical" class="small-muted">ดูใน SAT ›</a></div>
            <div class="flood-card-body">
                <?php if (!$st['ready']) { ?><div class="small-muted">ยังไม่มีข้อมูลบุคลากร</div><?php } else { ?>
                <table class="table table-condensed">
                    <thead><tr><th>หน่วย</th><th title="ถูกตัดขาด / มาไม่ได้">🔴</th><th title="ต้องใช้รถสูง/เรือ">🟠</th><th title="เดินทางลำบาก">🟡</th><th title="ปกติ">🟢</th></tr></thead>
                    <tbody>
                    <?php foreach ($units as $code => $u) { $x = isset($st['units'][$code]) ? $st['units'][$code] : null; ?>
                    <tr>
                        <td><?= h($u['name']) ?></td>
                        <?php if (!$x || !$x['total']) { ?><td colspan="4" class="small-muted">ไม่มีผู้ตอบ</td><?php } else { ?>
                        <td><?= $x['red'] ? '<b class="text-danger">' . (int) $x['red'] . '</b>' : '0' ?></td>
                        <td><?= (int) $x['orange'] ?></td><td><?= (int) $x['yellow'] ?></td><td><?= (int) $x['green'] ?></td>
                        <?php } ?>
                    </tr>
                    <?php } ?>
                    </tbody>
                </table>
                <?php } ?>
            </div>
        </div>
        <?php if ($this->sat['mp']['ready'] && $this->sat['mp']['short']) { ?>
        <div class="flood-card">
            <div class="flood-card-header">หน่วยที่ RN ขาดกรอบเวรนี้</div>
            <div class="flood-card-body">
                <?php foreach ($this->sat['mp']['short'] as $s) { ?>
                <div><?= h($s['name']) ?> — ขึ้นจริง <?= (int) $s['actual'] ?> / กรอบ <?= (int) $s['req'] ?></div>
                <?php } ?>
            </div>
        </div>
        <?php } ?>
    </div>
</div>
<?php } ?>
<p class="small-muted">ตัวเลขรวมจากห้องสถานการณ์ SAT · บุคลากรที่ได้รับผลกระทบ · อัตรากำลังรายเวร — ไม่แสดงรายชื่อบุคคล</p>
