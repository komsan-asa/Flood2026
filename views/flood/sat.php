<?php
/** ห้องสถานการณ์ SAT — ดู controllers/sat.php */
$col = Sat_Model::colors();
$acts = Sat_Model::overallActions();
$lvNames = flood_zone_levels_all();
$chip = function ($status, $text = null, $cls = '') use ($col) {
    if (!isset($col[$status])) {
        return '<span class="sat-chip sat-c-none ' . h($cls) . '">⚪ ' . h($text !== null ? $text : 'ยังไม่มีข้อมูล') . '</span>';
    }
    return '<span class="sat-chip sat-c-' . h($status) . ' ' . h($cls) . '">' . $col[$status]['emoji'] . ' '
        . h($text !== null ? $text : $col[$status]['label']) . '</span>';
};
$when = function ($dt) {
    return $dt ? flood_thai_date($dt) : '';
};
?>
<div class="flood-page-header">
    <h2 class="flood-page-title"><i class="fa fa-crosshairs"></i> ห้องสถานการณ์ SAT
        <?php if ($this->satReady) { ?><small class="sat-hosp-name"><?= h($this->sat['hosp']['name']) ?></small><?php } ?></h2>
    <?php if ($this->satReady) { ?>
    <div class="flood-page-actions">
        <?php if (!empty($this->satPull)) { $pc = $this->satPull; ?>
        <div class="btn-group sat-pull-group">
            <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false"
                    title="ดึงข้อมูลย่อยของตัวชี้วัดแต่ละข้อ ประมวลเป็นสรุป แล้วบันทึกลงประวัติ"><i class="fa fa-cloud-download"></i> ดึงประมวล <span class="caret"></span></button>
            <ul class="dropdown-menu dropdown-menu-right sat-pull-menu">
                <?php foreach ($pc['presets'] as $pk => $p) { ?>
                <li><a href="#" class="js-sat-pull" data-codes="<?= h(implode(',', $p['codes'])) ?>" data-title="<?= h($p['name']) ?>"><?php if ($pk === 'all') { ?><b><i class="fa fa-list-ul"></i> <?= h($p['name']) ?></b> <small>(<?= count($p['codes']) ?> หัวข้อย่อย)</small><?php } else { ?><i class="fa fa-star-o"></i> <?= h($p['name']) ?><?php } ?></a></li>
                <?php } ?>
                <?php foreach ($pc['groups'] as $gname => $gcodes) { ?>
                <li role="separator" class="divider"></li>
                <li class="dropdown-header"><?= h($gname) ?></li>
                <?php foreach ($gcodes as $gc) { if (!isset($pc['items'][$gc])) { continue; } ?>
                <li><a href="#" class="js-sat-pull" data-codes="<?= h($gc) ?>" data-title="<?= h($pc['items'][$gc]['name']) ?>"><?= h($pc['items'][$gc]['emoji']) ?> <?= h($pc['items'][$gc]['name']) ?></a></li>
                <?php } ?>
                <?php } ?>
            </ul>
        </div>
        <?php } ?>
        <a href="#satSitrep" class="btn btn-primary"><i class="fa fa-file-text-o"></i> ออก SitRep ฉบับที่ <?= (int) $this->satNextNo ?></a>
    </div>
    <?php } ?>
</div>

<?php if (!$this->satReady) { ?>
<div class="alert alert-danger">ยังไม่มีตาราง SAT และระบบสร้างเองไม่ได้ ให้ผู้ดูแลรัน <code>php sql/apply_schema.php 19</code></div>
<?php return; } ?>
<?php
$c = $this->sat;
$items = $c['items'];
$decl = $this->satDeclared;
$sug = $this->satSuggest;
$edit = $this->satCanEdit;
$declStatus = $decl ? $decl['status'] : '';
// ข้อมูลเปลี่ยนหลังประกาศสถานะ (controllers/sat.php → satDeclChanges)
$declChg = isset($this->satDeclChanges) ? $this->satDeclChanges : array();
// + เหตุผลที่ประกาศอ้างสีตัวชี้วัดที่เปลี่ยนไปแล้ว เช่น "น้ำประปา: กระทบระบบบริการ" แต่ตอนนี้เขียว (เหตุผลที่คัดลอกต่อมาจากประกาศ/SitRep ก่อนหน้า)
if ($decl && (string) $decl['reason'] !== '') {
    $seen = array();
    foreach ($declChg as $x) {
        $seen[$x['name']] = 1;
    }
    foreach ($items as $it) {
        foreach ($col as $ck => $cv) {
            if (!isset($seen[$it['name']]) && $it['status'] !== $ck && mb_strpos((string) $decl['reason'], $it['name'] . ': ' . $cv['name']) !== false) {
                $declChg[] = array('name' => $it['name'], 'old' => $ck, 'new' => (string) $it['status']);
                $seen[$it['name']] = 1;
            }
        }
    }
}
// เหตุผลปัจจุบันจากระบบ — เติมฟอร์มประกาศ/SitRep แทนเหตุผลเก่าเมื่อข้อมูลเปลี่ยน
$sysReason = implode(' · ', array_map(function ($x) { return $x[1]; }, array_slice((array) $this->satReasons, 0, 4)));
$declOld = $decl && ($declChg || $sug !== $declStatus);
$overallReason = $declOld || !$decl ? $sysReason : (string) $decl['reason'];
// ③ ผลที่เลือกไว้ให้ในหน้าต่าง "ยืนยันหน้างาน" = ช่อง "ใช้ได้" (หน้างาน ≤3 ชม. → กรมทางหลวง → หน้างานครั้งก่อน)
$routeSug = function ($r) {
    $byColor = array('green' => 'pass', 'yellow' => 'slow', 'orange' => 'high', 'red' => 'blocked');
    $checks = Sat_Model::routeChecks();
    $has = !empty($r['check_result']) && isset($checks[$r['check_result']]);
    if ($has && !empty($r['check_fresh'])) {
        return array($r['check_result'], 'ผลยืนยันหน้างานล่าสุด (ภายใน 3 ชม.)');
    }
    if (!empty($r['auto']) && isset($byColor[$r['auto']])) {
        $n = empty($r['hits']) ? 0 : count($r['hits']);
        return array($byColor[$r['auto']], $n ? 'กรมทางหลวง — พบจุดน้ำท่วมบนเส้นทาง ' . $n . ' จุด' : 'กรมทางหลวง — ไม่มีรายงานน้ำท่วมบนเส้นทาง');
    }
    return $has ? array($r['check_result'], 'ผลยืนยันหน้างานครั้งก่อน (เกิน 3 ชม.)') : array('', '');
};
// SitRep ที่ใช้ประกาศสถานะ — เปิดดูได้จากกล่องสถานะ
$declRepId = 0;
if ($decl && !empty($decl['sitrep_no'])) {
    foreach ($this->satSitreps as $x) {
        if ((int) $x['report_no'] === (int) $decl['sitrep_no']) {
            $declRepId = (int) $x['sitrep_id'];
            break;
        }
    }
}
?>

<!-- ============ สถานะรวมของโรงพยาบาล ============ -->
<div class="sat-overall sat-b-<?= h($declStatus ?: 'none') ?>">
    <div class="sat-overall-main">
        <div class="sat-overall-label">สถานะโรงพยาบาลที่ SAT ประกาศ</div>
        <?php if ($decl) { ?>
        <div class="sat-overall-value"><?= $col[$declStatus]['emoji'] ?> <?= h($col[$declStatus]['label']) ?> <span>— <?= h($col[$declStatus]['name']) ?></span></div>
        <div class="sat-overall-meta">ประกาศ <?= h($when($decl['at'])) ?><?= $decl['by'] ? ' · โดย ' . h($decl['by']) : '' ?><?php if (!empty($decl['sitrep_no'])) { ?> · จาก <?php if ($declRepId) { ?><button type="button" class="btn btn-link sat-link js-sat-rep" data-id="<?= $declRepId ?>" title="เปิดดู SitRep ฉบับนี้">SitRep ฉบับที่ <?= (int) $decl['sitrep_no'] ?></button><?php } else { ?>SitRep ฉบับที่ <?= (int) $decl['sitrep_no'] ?><?php } } ?></div>
        <?php if (!empty($decl['reason'])) { ?><div class="sat-overall-reason"><?= nl2br(h($decl['reason'])) ?></div><?php } ?>
        <?php if ($declOld) { ?>
        <div class="sat-overall-stale">
            <div><i class="fa fa-exclamation-circle"></i> <b>ข้อมูลเปลี่ยนหลังประกาศ</b> — สถานะที่ประกาศไม่เปลี่ยนเอง ตรวจแล้วกดประกาศใหม่</div>
            <?php if ($declChg) { ?><div class="sat-stale-chg"><?php foreach ($declChg as $i => $x) { ?><?= $i ? ' · ' : '' ?><?= h($x['name']) ?> <?= isset($col[$x['old']]) ? $col[$x['old']]['emoji'] : '⚪' ?>→<?= isset($col[$x['new']]) ? $col[$x['new']]['emoji'] : '⚪' ?><?php } ?></div><?php } ?>
            <div class="sat-stale-sug">ระบบประเมินตอนนี้: <?= $chip($sug) ?><?= $sug !== $declStatus ? ' ต่างจากที่ประกาศไว้' : ' เท่ากับที่ประกาศไว้ (เหตุผลเปลี่ยน)' ?></div>
            <?php if ($edit) { ?><button type="button" class="btn btn-primary btn-xs js-sat-overall" data-status="<?= h($sug) ?>" data-reason="<?= h($sysReason) ?>"><i class="fa fa-bullhorn"></i> ประกาศใหม่ตามข้อมูลล่าสุด</button><?php } ?>
        </div>
        <?php } ?>
        <div class="sat-overall-act"><i class="fa fa-hand-o-right"></i> <?= h($acts[$declStatus]) ?></div>
        <?php $nRep = count($this->satSitreps); ?>
        <div class="sat-overall-hist">
            <button type="button" class="btn btn-link js-sat-rep-hist"<?= $nRep ? '' : ' disabled' ?>><i class="fa fa-history"></i> ประวัติ SitRep<?= $nRep ? ' (' . $nRep . ' ฉบับ)' : '' ?></button>
            <button type="button" class="btn btn-link js-sat-history" data-code="overall" data-title="ประวัติการประกาศสถานะ"><i class="fa fa-bullhorn"></i> ประวัติการประกาศสถานะ</button>
        </div>
        <template id="satRepHistTpl">
            <?php if ($this->satSitreps) { ?>
            <table class="table table-condensed sat-table sat-rep-hist">
                <thead><tr><th>ฉบับที่</th><th>สถานะ</th><th>เวลา</th><th>โดย</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($this->satSitreps as $x) { ?>
                <tr><td><b><?= (int) $x['report_no'] ?></b></td><td><?= $x['overall'] ? $chip($x['overall']) : '' ?></td>
                    <td class="sat-nowrap"><?= h($when($x['report_at'])) ?></td><td><?= h((string) $x['by_name']) ?></td>
                    <td><button type="button" class="btn btn-default btn-xs js-sat-rep" data-id="<?= (int) $x['sitrep_id'] ?>"><i class="fa fa-file-text-o"></i> ดู</button></td></tr>
                <?php } ?>
                </tbody>
            </table>
            <?php } else { ?><div class="sat-muted">ยังไม่มี SitRep</div><?php } ?>
        </template>
        <?php } else { ?>
        <div class="sat-overall-value sat-muted">ยังไม่ได้ประกาศ</div>
        <div class="sat-overall-meta">ดูข้อเสนอจากระบบด้านขวา แล้วกด "ประกาศสถานะ"</div>
        <?php } ?>
    </div>
    <div class="sat-overall-side">
        <div class="sat-sug">ระบบประเมินจากข้อมูล: <?= $chip($sug) ?></div>
        <?php if ($this->satReasons) { ?>
        <ul class="sat-reasons">
            <?php foreach (array_slice($this->satReasons, 0, 8) as $r) { ?>
            <li><span class="sat-dot sat-c-<?= h($r[0]) ?>"></span><?= h($r[1]) ?></li>
            <?php } ?>
        </ul>
        <?php } else { ?>
        <div class="sat-muted small">ยังไม่พบสัญญาณผิดปกติจากข้อมูลในระบบ — ตรวจตัวชี้วัดที่ยังไม่มีข้อมูล</div>
        <?php } ?>
        <button type="button" class="btn btn-primary btn-sm js-sat-overall-img" data-hosp="<?= h($c['hosp']['name']) ?>" title="สร้างภาพสถานะ + ผลประเมิน เพื่อส่งผู้บริหาร"><i class="fa fa-picture-o"></i> สร้างเป็นภาพ</button>
        <?php if ($edit) { ?>
        <button type="button" class="btn btn-default btn-sm js-sat-overall" data-status="<?= h($declStatus ?: $sug) ?>"
                data-reason="<?= h($overallReason) ?>"><i class="fa fa-bullhorn"></i> ประกาศสถานะ</button>
        <?php } ?>
    </div>
</div>

<nav class="sat-steps" aria-label="5 เรื่องเร่งด่วน">
    <a href="#satMap"><b>①</b> แผนที่สถานการณ์</a>
    <a href="#satFac"><b>②</b> หน่วยบริการ <?= $c['fac']['overdue'] ? '<span class="sat-badge">' . (int) $c['fac']['overdue'] . '</span>' : '' ?></a>
    <a href="#satRoutes"><b>③</b> 3 เส้นทาง</a>
    <a href="#satCritical"><b>④</b> Critical Patient + Staff</a>
    <a href="#satSitrep"><b>⑤</b> SitRep ถึง EOC</a>
</nav>

<!-- ============ ตัวชี้วัดหลัก ============ -->
<?php
// สรุปอัตโนมัติจากข้อมูลในระบบ (แสดงใต้การ์ด — SAT เลือกสีเอง)
$auto = array();
$auto['road'] = array('กรมทางหลวง รอบ รพ. 80 กม.: ผ่านไม่ได้ ' . $c['road']['red'] . ' · รถเล็กผ่านไม่ได้ ' . $c['road']['orange']
    . ' · เฝ้าระวัง ' . $c['road']['yellow'] . ' จุด');
$f = $c['fac'];
$auto['facility'] = array($f['count'] . ' แห่ง: 🔴' . $f['red'] . ' 🟠' . $f['orange'] . ' 🟡' . $f['yellow'] . ' 🟢' . $f['green']
    . ($f['none'] ? ' ⚪' . $f['none'] : ''), $f['overdue'] ? 'ถึงรอบโทร ' . $f['overdue'] . ' แห่ง' . ($f['news'] ? ' (ข้อมูลจากข่าว ' . $f['news'] . ')' : '') : '');
$rs = array('red' => 0, 'orange' => 0, 'yellow' => 0, 'green' => 0, '' => 0);
foreach ($c['routes'] as $r) {
    $rs[$r['effective']] = (isset($rs[$r['effective']]) ? $rs[$r['effective']] : 0) + 1;
}
$auto['ems'] = array('เส้นทาง ' . count($c['routes']) . ': 🔴' . $rs['red'] . ' 🟠' . $rs['orange'] . ' 🟡' . $rs['yellow'] . ' 🟢' . $rs['green']);
$auto['ems'] = array_merge($auto['ems'], $c['refer']['lines']);   // Refer เข้า รพ. ช่วงอุทกภัย (หน้า sat/refer)
$st = $c['staff'];
$auto['staff'] = $st['ready']
    ? array('แบบสำรวจ ' . $st['all']['total'] . ' คน: 🔴' . $st['all']['red'] . ' 🟠' . $st['all']['orange'] . ' 🟡' . $st['all']['yellow'] . ' 🟢' . $st['all']['green'],
        'หน่วยสำคัญเดินทางไม่ได้ ' . $c['staffCrit']['red'] . ' คน' . ($c['mp']['ready'] && $c['mp']['short'] ? ' · RN เวรนี้ขาด ' . count($c['mp']['short']) . ' หน่วย' : ''),
        Sat_Model::staffFollowText($st, true))   // การติดตามผู้ได้รับผลกระทบ (หน้า flood/staff)
    : array('ยังไม่มีข้อมูลบุคลากร');
$v = $c['vuln'];
$auto['patient'] = $v['ready'] ? array('ทะเบียนรายคน ' . $v['total'] . ' ราย · ในพื้นที่น้ำท่วม ' . $v['in_zone'] . ' (ยังไม่อพยพ ' . $v['in_zone_waiting'] . ')') : array();
$auto['patient'] = array_merge($c['shelter']['lines'], $auto['patient']);   // กลุ่มเปราะบางในศูนย์พักพิง (หน้า flood/vulnerable)
$auto['ed_load'] = $c['his']['lines'];   // ER / IPD / OPD / Refer จาก HOSxP (hosxp-site-api)
$auto['hosp_site'] = array($c['hospIn'] ? 'จุดโรงพยาบาลอยู่ในพื้นที่ประกาศ: ' . $c['hospIn'][0]['name'] : 'จุดโรงพยาบาลไม่อยู่ในพื้นที่ประกาศบนแผนที่',
    $c['hosp']['approx'] ? 'พิกัดโรงพยาบาลยังเป็นค่าประมาณ — ตั้งตำแหน่งในแผนที่ ①' : '');
// ฝนรายวันจากสถานีกรมชลประทาน (นำเข้าผ่าน rain/import ทุก 6 ชม. — models/rain_model.php)
if (is_file('models/rain_model.php')) {
    require_once 'models/rain_model.php';
    $auto['rain'] = Rain_Model::autoLines();
}
$card = function ($it) use ($chip, $when, $auto, $edit) {
    $a = isset($auto[$it['code']]) ? array_filter($auto[$it['code']]) : array();
    $cls = 'sat-card sat-b-' . ($it['status'] !== '' ? $it['status'] : 'none') . ($it['stale'] ? ' is-stale' : '') . ($edit ? ' js-sat-item' : '');
    $h = '<div class="' . h($cls) . '"' . ($edit ? ' role="button" tabindex="0"' : '') . ' data-code="' . h($it['code']) . '" data-name="' . h($it['name'])
        . '" data-status="' . h($it['status']) . '" data-note="' . h($it['note']) . '" data-hint="' . h($it['hint']) . '" data-auto="' . h(implode("\n", $a)) . '">';
    $h .= '<div class="sat-card-h"><span class="sat-card-t"><i class="fa ' . h($it['icon']) . '"></i> ' . h($it['name']) . '</span>' . $chip($it['status']) . '</div>';
    if ($it['hint'] !== '') {
        $h .= '<div class="sat-card-hint">' . h($it['hint']) . ' · ทุก ' . (int) $it['hours'] . ' ชม.</div>';
    }
    if ($it['note'] !== '') {
        $h .= '<div class="sat-card-note' . (!empty($it['src']) && $it['src'] === 'auto' ? ' is-auto' : '') . '">' . nl2br(h($it['note'])) . '</div>';
    }
    foreach ($a as $line) {
        $h .= '<div class="sat-card-auto"><i class="fa fa-database"></i> ' . h($line) . '</div>';
    }
    $h .= '<div class="sat-card-f">' . ($it['at'] ? 'อัปเดต ' . h($when($it['at'])) . ($it['by'] ? ' · ' . h($it['by']) : '') : 'ยังไม่อัปเดต')
        . ($it['stale'] ? ' <b class="sat-stale">เกินรอบ</b>' : '')
        . (!empty($it['src']) && $it['src'] === 'auto' ? ' <span class="sat-src-auto" title="สรุปจากปุ่มดึงประมวล">ดึงประมวล</span>' : '') . '</div>';
    return $h . '</div>';
};
?>
<h3 class="sat-h">ตัวชี้วัดหลัก <small><?= $edit ? 'กดการ์ดเพื่ออัปเดตสีและสรุป · ' : '' ?>กรอบสีเทาเข้ม = เกินรอบอัปเดต · <i class="fa fa-database"></i> = ข้อมูลจากระบบ</small></h3>
<div class="sat-grid">
    <?php foreach ($items as $it) { if ($it['group'] === 'main') { echo $card($it); } } ?>
    <?php foreach ($items as $it) { if ($it['group'] === 'hosp') { echo $card($it); } } ?>
    <?php
    // ค่าจากหน้าสาธารณูปโภค (ชีตงานช่าง) ของ น้ำประปา / ออกซิเจน / เชื้อเพลิง — ดู Sat::utilityNow()
    $ux = $c['util']['items'];
    $uxSync = array();
    foreach ($items as $code => $it) {
        // เสนอเฉพาะตัวที่ยังไม่ตรงกับข้อมูลล่าสุด (สีต่าง หรือสรุปยังไม่ใช่ข้อความชุดนี้)
        if (isset($ux[$code]) && $ux[$code]['status'] !== '' && ($it['status'] !== $ux[$code]['status'] || strpos($it['note'], $ux[$code]['text']) === false)) {
            $uxSync[] = array('name' => $it['name'], 'cur_status' => $it['status'], 'cur_note' => mb_substr($it['note'], 0, 80),
                'status' => $ux[$code]['status'], 'text' => $ux[$code]['text']);
        }
    }
    ?>
    <div class="sat-card sat-card-util">
        <div class="sat-card-h"><span class="sat-card-t"><i class="fa fa-plug"></i> ระบบสำคัญ (Critical utility)</span>
            <a href="<?= URL ?>sat/utility" class="sat-util-link">สาธารณูปโภค ›</a></div>
        <div class="sat-card-hint">ไฟฟ้า น้ำ ออกซิเจน เชื้อเพลิง IT · ทุก 6 ชม.<?php if ($ux) { ?> · <i class="fa fa-database"></i> = ค่าจากหน้าสาธารณูปโภค (นำเข้า <?= h($when($c['util']['imported_at'])) ?> น.)<?php } ?></div>
        <?php foreach ($items as $it) { if ($it['group'] !== 'util') { continue; } $ua = isset($ux[$it['code']]) ? $ux[$it['code']] : null; ?>
        <div class="sat-util<?= $edit ? ' js-sat-item' : '' ?><?= $it['stale'] ? ' is-stale' : '' ?>"<?= $edit ? ' role="button" tabindex="0"' : '' ?>
             data-code="<?= h($it['code']) ?>" data-name="<?= h($it['name']) ?>" data-status="<?= h($it['status']) ?>"
             data-note="<?= h($it['note']) ?>" data-hint="<?= h($it['hint']) ?>"
             data-auto="<?= h($ua ? $ua['text'] : '') ?>" data-auto-status="<?= h($ua ? $ua['status'] : '') ?>">
            <span><i class="fa <?= h($it['icon']) ?>"></i> <?= h($it['name']) ?></span>
            <span class="sat-util-note"><?= h(mb_substr($ua && strpos($it['note'], $ua['text']) !== false ? trim(str_replace($ua['text'], '', $it['note'])) : $it['note'], 0, 60)) ?></span>
            <?= $chip($it['status']) ?>
            <?php if ($ua) { ?><div class="sat-util-auto"><i class="fa fa-database"></i> <?= h($ua['text']) ?><?php if ($ua['status'] !== '' && $ua['status'] !== $it['status']) { ?> · ระบบแนะนำ <?= $chip($ua['status']) ?><?php } elseif (!$ua['fresh']) { ?> · <span class="sat-stale">ค่าวัดเก่ากว่า 48 ชม.</span><?php } ?></div><?php } ?>
        </div>
        <?php } ?>
        <?php if ($edit && $uxSync) { ?>
        <button type="button" class="btn btn-default btn-sm sat-util-sync" id="satUtilSync" data-items="<?= h(json_encode($uxSync, JSON_UNESCAPED_UNICODE)) ?>">
            <i class="fa fa-refresh"></i> อัปเดต <?= h(implode(' / ', array_map(function ($x) { return $x['name']; }, $uxSync))) ?> ตามข้อมูลสาธารณูปโภค</button>
        <?php } ?>
    </div>
</div>

<!-- ============ ① แผนที่ ============ -->
<section id="satMap" class="flood-card sat-sec">
    <div class="sat-sec-h">
        <h3><b>①</b> Flood Situation Map <small><?= h($c['hosp']['name']) ?> + ถนน + เครือข่ายบริการ (รัศมี 60 กม.)</small></h3>
        <?php if ($edit) { ?>
        <button type="button" class="btn btn-default btn-sm" id="satSetHosp"><i class="fa fa-map-pin"></i> ตั้งตำแหน่งโรงพยาบาล</button>
        <?php } ?>
    </div>
    <?php if ($c['hosp']['approx']) { ?>
    <div class="sat-warn"><i class="fa fa-exclamation-circle"></i> ตำแหน่งโรงพยาบาลยังเป็นจุดกลาง ต.สระแก้ว โดยประมาณ — กด "ตั้งตำแหน่งโรงพยาบาล" แล้วแตะจุดที่ตั้งจริงบนแผนที่</div>
    <?php } ?>
    <div id="satMapEl" class="sat-map" role="application" aria-label="แผนที่สถานการณ์รอบโรงพยาบาล"></div>
    <div class="sat-legend">
        <span><i class="sat-lg-hosp"></i> โรงพยาบาลแม่ข่าย</span>
        <span><i class="sat-lg-fac sat-c-green"></i><i class="sat-lg-fac sat-c-yellow"></i><i class="sat-lg-fac sat-c-orange"></i><i class="sat-lg-fac sat-c-red"></i> หน่วยบริการ (สีตามผลโทรล่าสุด · ขอบประ = พิกัดโดยประมาณ)</span>
        <span><i class="sat-lg-zone"></i> พื้นที่ประกาศ / จุดทางหลวง (สีตามระดับ)</span>
    </div>
</section>

<!-- ============ ② หน่วยบริการ ============ -->
<section id="satFac" class="flood-card sat-sec">
    <div class="sat-sec-h">
        <h3><b>②</b> โทรสอบถามหน่วยบริการ <small>สถานะ Green / Yellow / Orange / Red · โทรทุก <?= (int) $items['facility']['hours'] ?> ชม.</small></h3>
        <?php if ($edit) { ?>
        <div>
            <button type="button" class="btn btn-default btn-sm js-sat-fac-edit" data-id="0"><i class="fa fa-plus"></i> เพิ่มหน่วยบริการ</button>
            <button type="button" class="btn btn-default btn-sm" id="satFacImport"><i class="fa fa-upload"></i> นำเข้ารายชื่อ</button>
        </div>
        <?php } ?>
    </div>
    <div class="sat-chips">
        <?= $chip('red', 'แดง ' . $f['red']) ?> <?= $chip('orange', 'ส้ม ' . $f['orange']) ?> <?= $chip('yellow', 'เหลือง ' . $f['yellow']) ?>
        <?= $chip('green', 'เขียว ' . $f['green']) ?> <?php if ($f['none']) { echo $chip('', 'ยังไม่มีข้อมูล ' . $f['none']); } ?>
        <?php if ($f['overdue']) { ?><span class="sat-chip sat-c-due"><i class="fa fa-phone"></i> ถึงรอบโทร <?= (int) $f['overdue'] ?> แห่ง</span><?php } ?>
    </div>
    <div class="table-responsive">
        <table class="table sat-table">
            <thead><tr><th>สถานะ</th><th>หน่วยบริการ</th><th>การให้บริการ</th><th>ปัญหา / ต้องการ</th><th>ข้อมูลล่าสุด</th><th></th></tr></thead>
            <tbody>
            <?php $states = Sat_Model::serviceStates(); $types = Sat_Model::facilityTypes();
            foreach ($c['facilities'] as $x) {
                $issues = array_filter(array(
                    $x['road_access'] ? 'ถนน: ' . $x['road_access'] : '', $x['staff_issue'] ? 'บุคลากร: ' . $x['staff_issue'] : '',
                    $x['utility_issue'] ? 'ไฟ/น้ำ/ยา: ' . $x['utility_issue'] : '', $x['patient_note'] ? 'ผู้ป่วย: ' . $x['patient_note'] : '',
                    $x['needs'] ? 'ต้องการ: ' . $x['needs'] : ''));
            ?>
            <tr class="<?= $x['overdue'] ? 'sat-row-due' : '' ?>">
                <td><?= $chip((string) $x['status']) ?></td>
                <td><b><?= h($x['name']) ?></b>
                    <div class="sat-sub"><?= h(isset($types[$x['ftype']]) ? $types[$x['ftype']] : $x['ftype']) ?><?= $x['amphoe_name'] ? ' · อ.' . h($x['amphoe_name']) : '' ?><?= $x['tambon_name'] ? ' ต.' . h($x['tambon_name']) : '' ?></div>
                    <?php if ($x['phone']) { ?><a class="sat-tel" href="tel:<?= h(preg_replace('/[^0-9+]/', '', explode(',', $x['phone'])[0])) ?>"><i class="fa fa-phone"></i> <?= h($x['phone']) ?></a><?php } ?>
                </td>
                <td><?= $x['service'] ? h(isset($states[$x['service']]) ? $states[$x['service']]['name'] : $x['service']) : '<span class="sat-muted">–</span>' ?>
                    <?php if ($x['relocated_to']) { ?><div class="sat-sub">→ <?= h($x['relocated_to']) ?></div><?php } ?></td>
                <td class="sat-issues"><?= $issues ? h(implode(' · ', $issues)) : '<span class="sat-muted">–</span>' ?>
                    <?php if ($x['log_note']) { ?><div class="sat-sub"><?= h(mb_substr($x['log_note'], 0, 160)) ?></div><?php } ?></td>
                <td class="sat-nowrap"><?= $x['log_at'] ? h($when($x['log_at'])) : '<span class="sat-muted">ยังไม่เคยโทร</span>' ?>
                    <div class="sat-sub"><?= $x['log_source'] === 'news' ? 'จากข่าว' : ($x['log_source'] === 'visit' ? 'ลงพื้นที่' : ($x['log_source'] ? 'โทร' : '')) ?><?= $x['log_by'] ? ' · ' . h($x['log_by']) : '' ?></div>
                    <?php if ($x['overdue']) { ?><b class="sat-stale">ถึงรอบโทร</b><?php } ?></td>
                <td class="sat-nowrap">
                    <?php if ($edit) { ?>
                    <button type="button" class="btn btn-primary btn-xs js-sat-call" data-id="<?= (int) $x['facility_id'] ?>" data-name="<?= h($x['name']) ?>"
                            data-status="<?= h((string) $x['status']) ?>" data-service="<?= h((string) $x['service']) ?>" data-moved="<?= h((string) $x['relocated_to']) ?>"><i class="fa fa-phone"></i> บันทึกผลโทร</button>
                    <?php } ?>
                    <button type="button" class="btn btn-default btn-xs js-sat-fac-edit" data-id="<?= (int) $x['facility_id'] ?>" title="ประวัติ / แก้ไข"><i class="fa fa-history"></i></button>
                </td>
            </tr>
            <?php } ?>
            <?php if (!$c['facilities']) { ?><tr><td colspan="6" class="sat-muted">ยังไม่มีหน่วยบริการ — เพิ่มหรือนำเข้ารายชื่อ</td></tr><?php } ?>
            </tbody>
        </table>
    </div>
</section>

<!-- ============ ③ เส้นทาง ============ -->
<section id="satRoutes" class="flood-card sat-sec">
    <div class="sat-sec-h">
        <h3><b>③</b> ตรวจ 3 เส้นทางสำคัญ <small>เข้าโรงพยาบาล / ออกจากโรงพยาบาล / Refer · เทียบจุดน้ำท่วมทางหลวงจากกรมทางหลวงให้เอง</small></h3>
        <?php if ($edit) { ?>
        <button type="button" class="btn btn-default btn-sm js-sat-route-edit" data-id="0"><i class="fa fa-plus"></i> เพิ่มเส้นทาง</button>
        <?php } ?>
    </div>
    <div class="table-responsive">
        <table class="table sat-table">
            <thead><tr><th>ใช้ได้</th><th>เส้นทาง</th><th>กรมทางหลวง (อัตโนมัติ)</th><th>ยืนยันหน้างาน</th><th></th></tr></thead>
            <tbody>
            <?php $rtypes = Sat_Model::routeTypes(); $checks = Sat_Model::routeChecks();
            foreach ($c['routes'] as $r) { ?>
            <tr>
                <td><?= $r['effective'] !== '' ? $chip($r['effective']) : $chip('', 'ยังไม่ตรวจ') ?></td>
                <td><span class="sat-rtype sat-rt-<?= h($r['rtype']) ?>"><i class="fa <?= h($rtypes[$r['rtype']]['icon']) ?>"></i> <?= h($rtypes[$r['rtype']]['name']) ?></span>
                    <?= $r['is_backup'] ? '<span class="sat-backup">สำรอง</span>' : '' ?>
                    <div><b><?= h($r['name']) ?></b></div>
                    <div class="sat-sub"><?= $r['destination'] ? 'ปลายทาง ' . h($r['destination']) . ' · ' : '' ?><?= $r['segments'] ? 'ทางหลวง ' . h($r['segments']) : 'ไม่มีทางหลวงผูก — ตรวจหน้างาน' ?></div>
                    <?php if ($r['note']) { ?><div class="sat-sub sat-note"><?= h($r['note']) ?></div><?php } ?></td>
                <td>
                    <?php if ($r['segments']) { ?>
                    <?= $chip($r['auto'], $r['hits'] ? null : 'ไม่มีรายงาน') ?>
                    <?php foreach (array_slice($r['hits'], 0, 4) as $hz) { ?>
                    <div class="sat-hit"><span class="sat-dot sat-c-<?= h(Sat_Model::levelColor($hz['level'])) ?>"></span>ทล.<?= h($hz['road']) ?> <?= h($hz['section']) ?></div>
                    <?php } ?>
                    <?php if (count($r['hits']) > 4) { ?><div class="sat-sub">และอีก <?= count($r['hits']) - 4 ?> จุด</div><?php } ?>
                    <?php } else { ?><span class="sat-muted">–</span><?php } ?>
                </td>
                <td><?php if ($r['check_result']) { ?>
                    <?= $chip($r['check_color'], $checks[$r['check_result']]['name']) ?>
                    <div class="sat-sub"><?= h($when($r['check_at'])) ?><?= $r['check_by'] ? ' · ' . h($r['check_by']) : '' ?><?= $r['check_fresh'] ? '' : ' · <b class="sat-stale">เกิน 3 ชม.</b>' ?></div>
                    <?php if ($r['check_note']) { ?><div class="sat-sub"><?= h($r['check_note']) ?></div><?php } ?>
                    <?php } else { ?><span class="sat-muted">ยังไม่ยืนยัน</span><?php } ?></td>
                <td class="sat-nowrap"><?php if ($edit) { ?>
                    <button type="button" class="btn btn-primary btn-xs js-sat-check" data-id="<?= (int) $r['route_id'] ?>" data-name="<?= h($r['name']) ?>"<?php $rsg = $routeSug($r); ?> data-suggest="<?= h($rsg[0]) ?>" data-suggest-from="<?= h($rsg[1]) ?>"><i class="fa fa-check-square-o"></i> ยืนยันหน้างาน</button>
                    <button type="button" class="btn btn-default btn-xs js-sat-route-edit" data-id="<?= (int) $r['route_id'] ?>"
                            data-rtype="<?= h($r['rtype']) ?>" data-name="<?= h($r['name']) ?>" data-destination="<?= h((string) $r['destination']) ?>"
                            data-segments="<?= h((string) $r['segments']) ?>" data-backup="<?= (int) $r['is_backup'] ?>" data-note="<?= h((string) $r['note']) ?>"
                            title="แก้ไข"><i class="fa fa-pencil"></i></button>
                    <?php } ?></td>
            </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
    <details class="sat-details">
        <summary>จุดน้ำท่วมทางหลวงรอบโรงพยาบาล 80 กม. (กรมทางหลวง) — <?= count($c['near']) ?> จุด</summary>
        <div class="table-responsive">
            <table class="table table-condensed sat-table">
                <thead><tr><th>ระดับ</th><th>ทางหลวง / ช่วง</th><th>อำเภอ</th><th>ระยะ</th><th>อัปเดต</th></tr></thead>
                <tbody>
                <?php foreach ($c['near'] as $z) { ?>
                <tr><td><?= $chip($z['color'], isset($lvNames[$z['level']]) ? $lvNames[$z['level']]['name'] : $z['level']) ?></td>
                    <td><b>ทล.<?= h($z['road']) ?></b> <?= h($z['section'] ?: $z['name']) ?></td>
                    <td><?= h((string) $z['amphoe_name']) ?></td><td class="sat-nowrap"><?= h($z['dist_km']) ?> กม.</td>
                    <td class="sat-nowrap"><?= h($when($z['updated_at'] ?: $z['started_at'])) ?></td></tr>
                <?php } ?>
                <?php if (!$c['near']) { ?><tr><td colspan="5" class="sat-muted">ไม่มีรายงานน้ำท่วมทางหลวงในรัศมี 80 กม.</td></tr><?php } ?>
                </tbody>
            </table>
        </div>
    </details>
</section>

<!-- ============ ④ Critical Patient + Critical Staff ============ -->
<section id="satCritical" class="flood-card sat-sec">
    <div class="sat-sec-h"><h3><b>④</b> บัญชี Critical Patient + Critical Staff</h3></div>
    <div class="sat-two">
        <div>
            <h4>Critical Staff — Staff Accessibility <small>จากแบบสำรวจบุคลากร<?= $st['last_at'] ? ' · ตอบล่าสุด ' . h($when($st['last_at'])) : '' ?></small></h4>
            <?php if ($st['ready']) { $lv = Sat_Model::accessLevels(); ?>
            <div class="table-responsive">
                <table class="table table-condensed sat-table sat-staff">
                    <thead><tr><th>หน่วยสำคัญ</th><th title="เดินทางไม่ได้">🔴</th><th title="เสี่ยงเดินทางไม่ได้">🟠</th><th title="เดินทางลำบาก">🟡</th><th title="เดินทางได้">🟢</th><th title="ไม่ระบุ">?</th><th>ผู้ตอบ</th></tr></thead>
                    <tbody>
                    <?php foreach (Sat_Model::criticalUnits() as $code => $u) { $n = $st['units'][$code]; ?>
                    <tr class="<?= $n['red'] ? 'sat-row-red' : ($n['total'] === 0 ? 'sat-row-none' : '') ?>">
                        <td><?= h($u['name']) ?></td>
                        <td><?= $n['red'] ?: '' ?></td><td><?= $n['orange'] ?: '' ?></td><td><?= $n['yellow'] ?: '' ?></td><td><?= $n['green'] ?: '' ?></td><td><?= $n['unknown'] ?: '' ?></td>
                        <td><?= $n['total'] ?: '<span class="sat-muted">ไม่มีผู้ตอบ</span>' ?></td>
                    </tr>
                    <?php } ?>
                    <tr class="sat-row-total"><td>ทุกคนที่ตอบแบบสำรวจ</td><td><?= $st['all']['red'] ?></td><td><?= $st['all']['orange'] ?></td><td><?= $st['all']['yellow'] ?></td><td><?= $st['all']['green'] ?></td><td><?= $st['all']['unknown'] ?></td><td><?= $st['all']['total'] ?></td></tr>
                    </tbody>
                </table>
            </div>
            <div class="sat-sub">🔴 <?= h($lv['red']) ?> · 🟠 <?= h($lv['orange']) ?> (ต้องใช้รถสูง/เรือ หรืออยู่พื้นที่เสี่ยง) · 🟡 <?= h($lv['yellow']) ?> · 🟢 <?= h($lv['green']) ?>
                · ป้ายในระบบ: มาทำงานไม่ได้ <?= (int) $st['flags']['cant_work'] ?> · ถูกตัดขาด/กลับบ้านไม่ได้ <?= (int) $st['flags']['stranded'] ?> · ต้องการที่พักด่วน <?= (int) $st['flags']['need_shelter'] ?></div>
            <?php if (!empty($st['follow_aff'])) { $fAff = (int) $st['follow_aff']; $fDone = $fAff - (int) $st['follow']['new']['affected']; ?>
            <div class="sat-follow">
                <b><i class="fa fa-phone"></i> การติดตามผู้ได้รับผลกระทบ (รุนแรง + ปานกลาง <?= $fAff ?> คน)</b>
                · ติดตามแล้ว <b><?= $fDone ?></b> คน (<?= round($fDone / $fAff * 100) ?>%)
                <div class="sat-follow-bar" aria-hidden="true">
                    <?php foreach (Sat_Model::staffFollowNames() as $code => $name) { $n = (int) $st['follow'][$code]['affected']; if (!$n) { continue; } ?>
                    <span class="sat-fb-<?= h($code) ?>" style="width:<?= round($n / $fAff * 100, 2) ?>%" title="<?= h($name . ' ' . $n) ?>"></span>
                    <?php } ?>
                </div>
                <div class="sat-follow-chips">
                    <?php foreach (Sat_Model::staffFollowNames() as $code => $name) { $n = (int) $st['follow'][$code]['affected']; ?>
                    <a class="sat-fchip<?= $n ? '' : ' is-zero' ?>" href="<?= URL ?>flood/staff?<?= h(http_build_query(array('level' => 'affected', 'follow' => $code))) ?>#staffFollowSum"><i class="sat-fdot sat-fb-<?= h($code) ?>"></i><?= h($name) ?> <b><?= $n ?></b></a>
                    <?php } ?>
                </div>
            </div>
            <?php } ?>
            <div class="sat-sub sat-note">จับคู่หน่วยงานจากชื่อที่บุคลากรพิมพ์เอง อาจคลาดเคลื่อน · นับเฉพาะผู้ตอบแบบสำรวจ (ไม่ใช่ทั้งโรงพยาบาล) — หน่วยที่ "ไม่มีผู้ตอบ" คือจุดบอดที่ต้องโทรถามหัวหน้าหน่วย</div>
            <?php } else { ?><div class="sat-muted">ยังไม่มีข้อมูลบุคลากร</div><?php } ?>
            <?php if ($c['mp']['ready']) { $sh = Manpower_Model::shifts(); ?>
            <div class="sat-mp">
                <b>พยาบาลวิชาชีพเวร<?= h($sh[$c['mp']['shift']]['name']) ?>วันนี้:</b>
                ครบกรอบ <?= (int) $c['mp']['ok'] ?> · ขาด <?= count($c['mp']['short']) ?> · รอข้อมูล <?= (int) $c['mp']['nodata'] ?> หน่วย
                <?php if ($c['mp']['short']) { ?><div class="sat-sub">ขาด: <?= h(implode(' · ', array_map(function ($x) { return $x['name'] . ' ' . $x['actual'] . '/' . $x['req']; }, $c['mp']['short']))) ?></div><?php } ?>
            </div>
            <?php } ?>
            <div class="sat-links"><a href="<?= URL ?>flood/staff"><i class="fa fa-user-md"></i> รายชื่อบุคลากรที่ได้รับผลกระทบ</a> · <a href="<?= URL ?>flood/manpower"><i class="fa fa-id-badge"></i> อัตรากำลังรายเวร</a></div>
        </div>
        <div>
            <h4>Critical Patient <small>ผู้ป่วยที่ "ห้ามรอจนเกิดเหตุ"</small></h4>
            <?php if ($v['ready']) { ?>
            <div class="sat-kpis">
                <div><b><?= (int) $v['total'] ?></b><span>อยู่ในทะเบียน</span></div>
                <div class="<?= $v['in_zone'] ? 'is-warn' : '' ?>"><b><?= (int) $v['in_zone'] ?></b><span>อยู่ในพื้นที่น้ำท่วม</span></div>
                <div class="<?= $v['in_zone_waiting'] ? 'is-bad' : '' ?>"><b><?= (int) $v['in_zone_waiting'] ?></b><span>ในพื้นที่ ยังไม่อพยพ</span></div>
                <div><b><?= (int) $v['no_location'] ?></b><span>ยังไม่มีพิกัด</span></div>
            </div>
            <?php if ($v['groups']) { ?><div class="sat-sub"><?php foreach ($v['groups'] as $g => $n) { echo h($g) . ' ' . (int) $n . ' · '; } ?></div><?php } ?>
            <?php } ?>
            <?php if (!$v['total']) { ?>
            <div class="sat-warn"><i class="fa fa-exclamation-triangle"></i> ทะเบียนยังว่าง — ต้องทำบัญชีล่วงหน้าตามเอกสาร:
                ผู้ป่วยฟอกไต · ใช้ออกซิเจน · ติดบ้าน/ติดเตียง · ยาเฉพาะ · ตั้งครรภ์ใกล้คลอด · NCD ขาดยาไม่ได้ · นัดสำคัญ
                <div class="sat-sub">แหล่งข้อมูลที่เร็วที่สุด: ระบบ Sakaeo COC (มีพิกัดบ้าน ระดับ ADL ผู้ใช้ Home O2 / CAPD) · งานไตเทียม (ตารางฟอกเลือด) · ANC ที่กำหนดคลอดใกล้ (HIS)</div></div>
            <?php } ?>
            <div class="sat-links"><a href="<?= URL ?>flood/vulnerable"><i class="fa fa-wheelchair"></i> ทะเบียนกลุ่มเปราะบาง</a><?php if ($edit) { ?> · <a href="<?= URL ?>flood/vulnerableForm"><i class="fa fa-plus"></i> เพิ่มผู้ป่วย</a><?php } ?></div>
        </div>
    </div>
</section>

<!-- ============ ⑤ SitRep ============ -->
<section id="satSitrep" class="flood-card sat-sec">
    <div class="sat-sec-h"><h3><b>⑤</b> Situation Report ถึง EOC <small>ร่างจากข้อมูลทั้งหน้า แก้ไขได้ก่อนบันทึก · คัดลอกไปส่ง LINE กลุ่ม EOC</small></h3></div>
    <?php if ($edit) { ?>
    <form id="satSitrepForm" class="sat-form" onsubmit="return false;">
        <div class="sat-form-row">
            <label>สถานะโรงพยาบาล
                <select name="overall" class="form-control">
                    <?php foreach ($col as $k => $x) { ?>
                    <option value="<?= h($k) ?>"<?= ($declStatus ?: $sug) === $k ? ' selected' : '' ?>><?= $x['emoji'] ?> <?= h($x['label']) ?> — <?= h($x['name']) ?></option>
                    <?php } ?>
                </select></label>
            <label>ผู้รายงาน <input type="text" name="reporter" class="form-control" maxlength="100" placeholder="ชื่อ / ตำแหน่ง" /></label>
            <label>รายงานครั้งถัดไป <input type="text" name="next" class="form-control" maxlength="60" value="<?= h(date('H:00', time() + 3 * 3600)) ?> น." /></label>
        </div>
        <label>เหตุผลของสถานะ <textarea name="reason" class="form-control" rows="2" placeholder="เว้นว่าง = ใช้เหตุผลจากการประกาศ/ข้อเสนอของระบบ"><?= h($overallReason) ?></textarea></label>
        <label>การดำเนินการที่ทำแล้ว <textarea name="actions" class="form-control" rows="2"></textarea></label>
        <label>ผลกระทบที่คาดใน 6–12 ชม. <small>(ระดับเหลือง: ต้องเสนอ EOC)</small><textarea name="impact" class="form-control" rows="2"></textarea></label>
        <label>ทางเลือกเสนอ Incident Commander <small>(ระดับส้ม)</small><textarea name="options" class="form-control" rows="2" placeholder="เช่น Refer ทล.33 ใช้ไม่ได้ → ใช้ ทล.317 · หน่วย X ปิด → รับผู้ป่วยที่ Y · เปิด staff pool"></textarea></label>
        <label>ต้องการสนับสนุน <textarea name="requests" class="form-control" rows="2"></textarea></label>
        <div class="sat-form-act">
            <button type="button" class="btn btn-default" id="satDraft"><i class="fa fa-magic"></i> สร้างข้อความจากข้อมูลล่าสุด</button>
        </div>
        <label>ข้อความ SitRep <small>(แก้ไขได้)</small><textarea name="body" id="satBody" class="form-control sat-body" rows="16" placeholder="กด &quot;สร้างข้อความจากข้อมูลล่าสุด&quot;"></textarea></label>
        <div class="sat-form-act">
            <button type="button" class="btn btn-default" id="satCopy"><i class="fa fa-clipboard"></i> คัดลอก</button>
            <button type="button" class="btn btn-default" id="satImg"><i class="fa fa-picture-o"></i> สร้างเป็นภาพ</button>
            <button type="button" class="btn btn-primary" id="satSave"><i class="fa fa-save"></i> บันทึกเป็นฉบับที่ <span id="satNo"><?= (int) $this->satNextNo ?></span></button>
        </div>
    </form>
    <?php } ?>
    <h4 class="sat-h4">SitRep ที่ออกแล้ว</h4>
    <?php if ($this->satSitreps) { ?>
    <ul class="sat-reps">
        <?php foreach ($this->satSitreps as $s) { ?>
        <li><button type="button" class="btn btn-link js-sat-rep" data-id="<?= (int) $s['sitrep_id'] ?>">ฉบับที่ <?= (int) $s['report_no'] ?></button>
            <?= $s['overall'] ? $chip($s['overall']) : '' ?> <span class="sat-sub"><?= h($when($s['report_at'])) ?><?= $s['by_name'] ? ' · ' . h($s['by_name']) : '' ?></span></li>
        <?php } ?>
    </ul>
    <?php } else { ?><div class="sat-muted">ยังไม่มี SitRep</div><?php } ?>
</section>

<?php if ($edit) { ?>
<!-- ============ หน้าต่างบันทึก ============ -->
<div class="modal fade" id="satItemModal" tabindex="-1" role="dialog"><div class="modal-dialog" role="document"><div class="modal-content">
    <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="ปิด"><span>&times;</span></button>
        <h4 class="modal-title">อัปเดต <span data-f="name"></span></h4></div>
    <div class="modal-body">
        <input type="hidden" name="code" />
        <div class="sat-hint" data-f="hint"></div>
        <div class="sat-auto-box hidden" data-f="autobox">
            <div><i class="fa fa-database"></i> <b>ข้อมูลจากระบบ</b> <span data-f="autost"></span></div>
            <div class="sat-auto-text" data-f="auto"></div>
            <button type="button" class="btn btn-default btn-xs js-sat-use-auto"><i class="fa fa-arrow-down"></i> ใช้ข้อมูลนี้ในสรุป</button>
        </div>
        <div class="sat-pick" data-pick="status">
            <?php foreach ($col as $k => $x) { ?><button type="button" class="sat-pick-b sat-c-<?= h($k) ?>" data-v="<?= h($k) ?>"><?= $x['emoji'] ?> <?= h($x['label']) ?><small><?= h($x['name']) ?></small></button><?php } ?>
        </div>
        <label>สรุปสั้น ๆ (ใช้ในข้อความ SitRep)<textarea name="note" class="form-control" rows="3" maxlength="2000"></textarea></label>
        <button type="button" class="btn btn-link btn-xs js-sat-history-in" data-title="ประวัติ">ดูประวัติ</button>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">ยกเลิก</button>
        <button type="button" class="btn btn-primary" data-save="item">บันทึก</button></div>
</div></div></div>

<div class="modal fade" id="satOverallModal" tabindex="-1" role="dialog"><div class="modal-dialog" role="document"><div class="modal-content">
    <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="ปิด"><span>&times;</span></button>
        <h4 class="modal-title">ประกาศสถานะโรงพยาบาล</h4></div>
    <div class="modal-body">
        <div class="sat-pick" data-pick="status">
            <?php foreach ($col as $k => $x) { ?><button type="button" class="sat-pick-b sat-c-<?= h($k) ?>" data-v="<?= h($k) ?>"><?= $x['emoji'] ?> <?= h($x['label']) ?><small><?= h($x['name']) ?></small></button><?php } ?>
        </div>
        <div class="sat-hint" data-f="act"></div>
        <label>เหตุผล<textarea name="reason" class="form-control" rows="3" maxlength="1000"></textarea></label>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">ยกเลิก</button>
        <button type="button" class="btn btn-primary" data-save="overall">ประกาศ</button></div>
</div></div></div>

<div class="modal fade" id="satCallModal" tabindex="-1" role="dialog"><div class="modal-dialog" role="document"><div class="modal-content">
    <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="ปิด"><span>&times;</span></button>
        <h4 class="modal-title">บันทึกผลโทร — <span data-f="name"></span></h4></div>
    <div class="modal-body">
        <input type="hidden" name="facility_id" />
        <div class="sat-form-row">
            <label>การให้บริการ<select name="service" class="form-control">
                <?php foreach (Sat_Model::serviceStates() as $k => $x) { ?><option value="<?= h($k) ?>" data-color="<?= h($x['color']) ?>"><?= h($x['name']) ?></option><?php } ?>
            </select></label>
            <label>ที่มา<select name="source" class="form-control"><option value="call">โทรสอบถาม</option><option value="visit">ลงพื้นที่</option><option value="news">ข่าว/หนังสือแจ้ง</option></select></label>
        </div>
        <div class="sat-pick" data-pick="status">
            <?php foreach ($col as $k => $x) { ?><button type="button" class="sat-pick-b sat-c-<?= h($k) ?>" data-v="<?= h($k) ?>"><?= $x['emoji'] ?> <?= h($x['label']) ?></button><?php } ?>
        </div>
        <div class="sat-hint">เขียว = เปิดปกติ · เหลือง = เปิดแต่มีปัญหา · ส้ม = ให้บริการบางส่วน/ย้ายจุด/ไฟ-น้ำ-ยาเสี่ยง · แดง = ปิด/ติดต่อไม่ได้/ถูกตัดขาด</div>
        <label>ย้ายจุดบริการไปที่<input type="text" name="relocated_to" class="form-control" maxlength="200" /></label>
        <div class="sat-form-row">
            <label>ถนนเข้าหน่วย<input type="text" name="road_access" class="form-control" maxlength="300" /></label>
            <label>บุคลากร<input type="text" name="staff_issue" class="form-control" maxlength="300" /></label>
        </div>
        <div class="sat-form-row">
            <label>ไฟ / น้ำ / ยา / ออกซิเจน<input type="text" name="utility_issue" class="form-control" maxlength="300" /></label>
            <label>ผู้ป่วยเสี่ยงในพื้นที่<input type="text" name="patient_note" class="form-control" maxlength="300" /></label>
        </div>
        <div class="sat-form-row">
            <label>ต้องการสนับสนุน<input type="text" name="needs" class="form-control" maxlength="300" /></label>
            <label>ผู้ให้ข้อมูล<input type="text" name="contact_person" class="form-control" maxlength="150" /></label>
        </div>
        <label>หมายเหตุ<textarea name="note" class="form-control" rows="2" maxlength="2000"></textarea></label>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">ยกเลิก</button>
        <button type="button" class="btn btn-primary" data-save="call">บันทึก</button></div>
</div></div></div>

<div class="modal fade" id="satFacModal" tabindex="-1" role="dialog"><div class="modal-dialog modal-lg" role="document"><div class="modal-content">
    <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="ปิด"><span>&times;</span></button>
        <h4 class="modal-title" data-f="title">หน่วยบริการ</h4></div>
    <div class="modal-body">
        <input type="hidden" name="facility_id" />
        <div class="sat-form-row">
            <label>ชื่อหน่วยบริการ<input type="text" name="name" class="form-control" maxlength="200" /></label>
            <label>ประเภท<select name="ftype" class="form-control"><?php foreach (Sat_Model::facilityTypes() as $k => $x) { ?><option value="<?= h($k) ?>"><?= h($x) ?></option><?php } ?></select></label>
            <label>รหัสหน่วยบริการ<input type="text" name="hcode" class="form-control" maxlength="10" /></label>
        </div>
        <div class="sat-form-row">
            <label>อำเภอ<select name="amphoe_code" class="form-control"><option value="">– เลือก –</option><?php foreach ($this->satAmphoes as $a) { ?><option value="<?= h($a['amphoe_code']) ?>"><?= h($a['name']) ?></option><?php } ?></select></label>
            <label>ตำบล<select name="tambon_code" class="form-control"><option value="">–</option></select></label>
            <label>เบอร์โทร<input type="text" name="phone" class="form-control" maxlength="100" /></label>
        </div>
        <div class="sat-form-row">
            <label>ผู้ประสานงาน<input type="text" name="contact" class="form-control" maxlength="150" /></label>
            <label>Lat<input type="text" name="lat" class="form-control" /></label>
            <label>Lng<input type="text" name="lng" class="form-control" /></label>
        </div>
        <div class="sat-hint">เว้นพิกัดว่าง = ใช้จุดกลางตำบล/อำเภอโดยประมาณ</div>
        <label>หมายเหตุ<input type="text" name="note" class="form-control" maxlength="500" /></label>
        <label class="sat-check"><input type="checkbox" name="is_active" value="1" checked /> ใช้งาน (ไม่ติ๊ก = ซ่อนจากรายการ)</label>
        <div data-f="history"></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">ปิด</button>
        <button type="button" class="btn btn-primary" data-save="fac">บันทึก</button></div>
</div></div></div>

<div class="modal fade" id="satImportModal" tabindex="-1" role="dialog"><div class="modal-dialog modal-lg" role="document"><div class="modal-content">
    <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="ปิด"><span>&times;</span></button>
        <h4 class="modal-title">นำเข้ารายชื่อหน่วยบริการ</h4></div>
    <div class="modal-body">
        <div class="sat-hint">คัดลอกจาก Excel วางได้เลย (1 บรรทัด = 1 หน่วย) — คอลัมน์: <b>ชื่อ · ประเภท (รพ./รพ.สต./PCC) · อำเภอ · ตำบล · เบอร์โทร · รหัสหน่วยบริการ · lat · lng</b> · ชื่อหรือรหัสซ้ำ = แก้ไขของเดิม</div>
        <textarea name="rows" class="form-control sat-body" rows="12" placeholder="รพ.สต.บ้านแก้ง	รพ.สต.	เมืองสระแก้ว	บ้านแก้ง	037-xxx-xxx"></textarea>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">ยกเลิก</button>
        <button type="button" class="btn btn-primary" data-save="import">นำเข้า</button></div>
</div></div></div>

<div class="modal fade" id="satRouteModal" tabindex="-1" role="dialog"><div class="modal-dialog" role="document"><div class="modal-content">
    <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="ปิด"><span>&times;</span></button>
        <h4 class="modal-title">เส้นทาง</h4></div>
    <div class="modal-body">
        <input type="hidden" name="route_id" />
        <div class="sat-form-row">
            <label>ประเภท<select name="rtype" class="form-control"><?php foreach (Sat_Model::routeTypes() as $k => $x) { ?><option value="<?= h($k) ?>"><?= h($x['name']) ?></option><?php } ?></select></label>
            <label class="sat-check"><input type="checkbox" name="is_backup" value="1" /> เส้นทางสำรอง</label>
        </div>
        <label>ชื่อเส้นทาง<input type="text" name="name" class="form-control" maxlength="200" /></label>
        <label>ปลายทาง<input type="text" name="destination" class="form-control" maxlength="200" /></label>
        <label>ทางหลวงที่ผ่าน + ช่วง กม.<input type="text" name="segments" class="form-control" maxlength="300" placeholder="33:215-245; 359:0-100; 317" /></label>
        <div class="sat-hint">หมายเลขทางหลวง:กม.เริ่ม-กม.สิ้นสุด คั่นด้วย ; — ไม่ใส่ช่วง = ทั้งสาย · เว้นว่าง = ตรวจหน้างานอย่างเดียว · ดูเลข กม. ได้จากตาราง "จุดน้ำท่วมทางหลวงรอบโรงพยาบาล"</div>
        <label>หมายเหตุ<input type="text" name="note" class="form-control" maxlength="500" /></label>
        <label class="sat-check"><input type="checkbox" name="is_active" value="1" checked /> ใช้งาน (ไม่ติ๊ก = ซ่อน)</label>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">ยกเลิก</button>
        <button type="button" class="btn btn-primary" data-save="route">บันทึก</button></div>
</div></div></div>

<div class="modal fade" id="satCheckModal" tabindex="-1" role="dialog"><div class="modal-dialog" role="document"><div class="modal-content">
    <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="ปิด"><span>&times;</span></button>
        <h4 class="modal-title">ยืนยันหน้างาน — <span data-f="name"></span></h4></div>
    <div class="modal-body">
        <input type="hidden" name="route_id" />
        <div class="sat-pick" data-pick="result">
            <?php foreach (Sat_Model::routeChecks() as $k => $x) { ?><button type="button" class="sat-pick-b sat-c-<?= h($x['color']) ?>" data-v="<?= h($k) ?>"><?= $col[$x['color']]['emoji'] ?> <?= h($x['name']) ?></button><?php } ?>
        </div>
        <div class="sat-hint sat-suggest" data-f="suggest"></div>
        <label>รายละเอียด (ใครตรวจ รถอะไร จุดไหน)<textarea name="note" class="form-control" rows="3" maxlength="500"></textarea></label>
        <div class="sat-hint">ผลยืนยันภายใน 3 ชม. ใช้แทนข้อมูลกรมทางหลวงในช่อง "ใช้ได้"</div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">ยกเลิก</button>
        <button type="button" class="btn btn-primary" data-save="check">บันทึก</button></div>
</div></div></div>
<?php } ?>

<?php if (!empty($this->satPull)) { ?>
<!-- ============ ดึงประมวล (sat_compile.js) ============ -->
<div class="modal fade" id="satPullModal" tabindex="-1" role="dialog"><div class="modal-dialog modal-lg" role="document"><div class="modal-content">
    <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="ปิด"><span>&times;</span></button>
        <h4 class="modal-title"><i class="fa fa-cloud-download"></i> ดึงประมวล — <span data-f="title"></span></h4></div>
    <div class="modal-body">
        <div class="sat-pull-src">
            <div class="sat-pull-h">1. ดึงข้อมูลล่าสุด <small>ติ๊กออก = ใช้ข้อมูลที่นำเข้าไว้แล้ว</small></div>
            <ul class="sat-pull-list" data-f="sources"></ul>
            <div class="sat-sub">ชีต Google อ่านผ่านเบราว์เซอร์นี้ ต้องล็อกอินบัญชี Google ที่มีสิทธิ์ดูชีต (เซิร์ฟเวอร์โรงพยาบาลออกอินเทอร์เน็ตไม่ได้) · ThaiWater = สถานีโทรมาตร สสน./กรมชลประทาน</div>
            <button type="button" class="btn btn-default btn-xs sat-pull-rerun" data-act="rerun"><i class="fa fa-refresh"></i> ดึงและประมวลใหม่</button>
        </div>
        <div class="sat-pull-h">2. ผลประมวล <small data-f="at"></small> <small>ตรวจ/แก้สีและสรุป แล้วติ๊กหัวข้อที่จะบันทึกลงประวัติ</small></div>
        <div data-f="rows"></div>
    </div>
    <div class="modal-footer">
        <span class="sat-pull-count" data-f="count"></span>
        <button type="button" class="btn btn-default" data-dismiss="modal">ปิด</button>
        <button type="button" class="btn btn-primary" data-save="pull" disabled><i class="fa fa-history"></i> บันทึกลงประวัติ</button>
    </div>
</div></div></div>
<?php } ?>

<div class="modal fade" id="satViewModal" tabindex="-1" role="dialog"><div class="modal-dialog modal-lg" role="document"><div class="modal-content">
    <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="ปิด"><span>&times;</span></button>
        <h4 class="modal-title" data-f="title"></h4></div>
    <div class="modal-body" data-f="body"></div>
    <div class="modal-footer"><button type="button" class="btn btn-primary js-sat-img-view" style="display:none"><i class="fa fa-picture-o"></i> สร้างเป็นภาพ</button>
        <button type="button" class="btn btn-default js-sat-copy-view"><i class="fa fa-clipboard"></i> คัดลอก</button>
        <button type="button" class="btn btn-default" data-dismiss="modal">ปิด</button></div>
</div></div></div>

<script>window.SAT = <?= flood_js($this->satJs + array('canEdit' => $edit, 'acts' => $acts)) ?>;</script>
<?php if (!empty($this->satPull)) { ?><script>window.SAT_PULL = <?= flood_js($this->satPull) ?>;</script><?php } ?>
