<?php
/** สาธารณูปโภคโรงพยาบาล — ดู controllers/sat.php (utility) และ models/utility_model.php */
$s = $this->util;
$edit = $this->utilCanEdit;
$d = function ($date) {
    return $date ? flood_thai_date($date, false) : '';
};
$n = function ($v, $dec = 0) {
    return $v === null || $v === '' ? '–' : number_format((float) $v, $dec);
};
$pctCls = function ($p) {
    if ($p === null || $p === '') {
        return 'ut-na';
    }
    $p = (float) $p;
    return $p < 25 ? 'ut-red' : ($p < 50 ? 'ut-amber' : 'ut-green');
};
$bar = function ($p, $empty = false) use ($pctCls) {
    if ($empty) {
        return '<div class="ut-bar"><div class="ut-bar-fill ut-red" style="width:0"></div></div><span class="ut-pct ut-red">หมดถัง</span>';
    }
    if ($p === null || $p === '') {
        return '<span class="ut-pct ut-na">–</span>';
    }
    $w = max(0, min(100, (float) $p));
    return '<div class="ut-bar"><div class="ut-bar-fill ' . $pctCls($p) . '" style="width:' . $w . '%"></div></div>'
        . '<span class="ut-pct ' . $pctCls($p) . '">' . number_format((float) $p, 0) . '%</span>';
};
$o2 = $s['oxygen'];
$fu = $s['fuel'];
$wa = $s['water'];
$de = $s['delivery'];
?>
<div class="flood-page-header">
    <h2 class="flood-page-title"><i class="fa fa-tint"></i> สาธารณูปโภค (น้ำ · ไฟสำรอง · ออกซิเจน)</h2>
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
    <?php if ($edit) { ?>ที่มา: <a href="<?= h($this->utilSheet) ?>" target="_blank" rel="noopener">Google Sheet งานช่าง</a>
    (แท็บ <?= h(implode(' · ', $this->utilTabs)) ?>)<?php } else { ?>ที่มา: Google Sheet งานช่าง<?php } ?>
    · นำเข้าล่าสุด <?= $s['imported_at'] ? h(flood_thai_date($s['imported_at'])) . ' น.' : 'ยังไม่เคยนำเข้า' ?>
    <?php if ($edit) { ?> · <a href="#" id="utSheetEdit">เปลี่ยนลิงก์ชีต</a><?php } ?>
</div>
<div id="utResult" class="alert alert-info hidden" role="status"></div>

<div class="flood-kpi-grid ut-kpis">
    <div class="flood-kpi-card <?= $o2 && $o2['days_left'] !== null && $o2['days_left'] < 2 ? 'kpi-danger' : 'kpi-info' ?>">
        <i class="fa fa-medkit kpi-icon"></i>
        <div class="kpi-value"><?= $o2 ? $n($o2['last']['volume_m3']) : '–' ?> <small>ลบ.ม.</small></div>
        <div class="kpi-label">ออกซิเจนเหลวคงเหลือ</div>
        <div class="kpi-sub"><?php if ($o2) { ?>
            <?= h($d($o2['last']['log_date'])) ?> <?= h($o2['last']['slot']) ?> น.
            <?php if ($o2['rate_day']) { ?> · ใช้ ~<?= $n($o2['rate_day']) ?> ลบ.ม./วัน · <b>พอใช้อีก ~<?= $n($o2['days_left'], 1) ?> วัน</b><?php } ?>
        <?php } else { ?>ยังไม่มีข้อมูล<?php } ?></div>
    </div>
    <div class="flood-kpi-card <?= $fu && $fu['pct'] !== null && $fu['pct'] < 40 ? 'kpi-warn' : 'kpi-info' ?>">
        <i class="fa fa-bolt kpi-icon"></i>
        <div class="kpi-value"><?= $fu ? $n($fu['remain']) : '–' ?> <small>ลิตร</small></div>
        <div class="kpi-label">น้ำมันสำรอง (เครื่องกำเนิดไฟฟ้า + ถังสำรอง)</div>
        <div class="kpi-sub"><?= $fu ? 'จากความจุ ' . $n($fu['capacity']) . ' ลิตร (' . $n($fu['pct']) . '%) · ' . h($d($fu['date'])) : 'ยังไม่มีข้อมูล' ?></div>
    </div>
    <?php
    $low = null;
    if ($wa) {
        foreach ($wa['buildings'] as $b) {
            $p = $b['lower_empty'] || $b['upper_empty'] ? 0 : min($b['lower_pct'] !== null ? (float) $b['lower_pct'] : 999, $b['upper_pct'] !== null ? (float) $b['upper_pct'] : 999);
            if ($low === null || $p < $low[1]) {
                $low = array($b, $p);
            }
        }
    }
    ?>
    <div class="flood-kpi-card <?= $low && $low[1] < 25 ? 'kpi-danger' : ($low && $low[1] < 50 ? 'kpi-warn' : 'kpi-ok') ?>">
        <i class="fa fa-tint kpi-icon"></i>
        <div class="kpi-value"><?= $low ? ($low[1] >= 999 ? '–' : $n($low[1]) . '%') : '–' ?></div>
        <div class="kpi-label">ถังพักน้ำต่ำสุด</div>
        <div class="kpi-sub"><?= $low ? h($low[0]['building']) . ' · ' . h($d($low[0]['log_date'])) . ' ' . h($low[0]['slot']) . ' น.' : 'ยังไม่มีข้อมูล' ?></div>
    </div>
    <div class="flood-kpi-card kpi-info">
        <i class="fa fa-truck kpi-icon"></i>
        <div class="kpi-value"><?= $de ? $n($de['days'][0]['liters']) : '–' ?> <small>ลิตร</small></div>
        <div class="kpi-label">รถเติมน้ำเข้าอาคาร</div>
        <div class="kpi-sub"><?= $de ? h($d($de['date'])) . ' · ' . (int) $de['days'][0]['trips'] . ' เที่ยว' : 'ยังไม่มีข้อมูล' ?></div>
    </div>
</div>

<div class="ut-grid">
    <div class="flood-card">
        <div class="flood-card-header"><span><i class="fa fa-tint"></i> ถังพักน้ำรายอาคาร (ค่าล่าสุด)</span>
            <?php if ($wa) { ?><small class="small-muted">วันที่ <?= h($d($wa['date'])) ?></small><?php } ?></div>
        <?php if (!$wa) { ?><div class="empty-state">ยังไม่มีข้อมูล</div><?php } else { ?>
        <div class="table-responsive"><table class="table table-condensed ut-table">
            <thead><tr><th>อาคาร</th><th>เวลา</th><th>ถังล่าง</th><th>ถังบน</th><th>หมายเหตุ</th></tr></thead>
            <tbody>
            <?php foreach ($wa['buildings'] as $b) { ?>
                <tr>
                    <td><?= h($b['building']) ?><?php if ($b['tank_note']) { ?><div class="small-muted"><?= h($b['tank_note']) ?></div><?php } ?></td>
                    <td class="nowrap"><?= h($d($b['log_date'])) ?> <?= h($b['slot']) ?></td>
                    <td class="ut-lv"><?= $bar($b['lower_pct'], (int) $b['lower_empty'] === 1) ?>
                        <div class="small-muted"><?= $b['lower_cm'] !== null ? $n($b['lower_cm']) . ($b['lower_full_cm'] ? '/' . $n($b['lower_full_cm']) : '') . ' ซม.' : '' ?></div></td>
                    <td class="ut-lv"><?= $bar($b['upper_pct'], (int) $b['upper_empty'] === 1) ?>
                        <div class="small-muted"><?= $b['upper_cm'] !== null ? $n($b['upper_cm']) . ($b['upper_full_cm'] ? '/' . $n($b['upper_full_cm']) : '') . ' ซม.' : '' ?></div></td>
                    <td class="small"><?= h($b['note']) ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table></div>
        <?php } ?>
    </div>

    <div class="flood-card">
        <div class="flood-card-header"><span><i class="fa fa-medkit"></i> ออกซิเจนเหลว</span></div>
        <?php if (!$o2) { ?><div class="empty-state">ยังไม่มีข้อมูล</div><?php } else { ?>
        <div class="table-responsive"><table class="table table-condensed ut-table">
            <thead><tr><th>วันที่</th><th>เวลา</th><th class="text-right">คงเหลือ (ลบ.ม.)</th><th class="text-right">ใช้ไป</th><th class="text-right">%</th></tr></thead>
            <tbody>
            <?php foreach (array_reverse(array_slice($o2['series'], -12)) as $r) { ?>
                <tr><td><?= h($d($r['log_date'])) ?></td><td><?= h($r['slot']) ?></td>
                    <td class="text-right"><b><?= $n($r['volume_m3']) ?></b></td><td class="text-right"><?= $n($r['used_m3']) ?></td>
                    <td class="text-right"><?= $n($r['used_pct'], 1) ?></td></tr>
            <?php } ?>
            </tbody>
        </table></div>
        <?php } ?>
    </div>

    <div class="flood-card">
        <div class="flood-card-header"><span><i class="fa fa-bolt"></i> น้ำมันสำรอง</span>
            <?php if ($fu) { ?><small class="small-muted">วันที่ <?= h($d($fu['date'])) ?></small><?php } ?></div>
        <?php if (!$fu) { ?><div class="empty-state">ยังไม่มีข้อมูล</div><?php } else { ?>
        <div class="table-responsive"><table class="table table-condensed ut-table">
            <thead><tr><th>รายการ</th><th class="text-right">ความจุ (ล.)</th><th>คงเหลือ</th><th>รอบ</th></tr></thead>
            <tbody>
            <?php foreach ($fu['items'] as $it) {
                $p = (float) $it['capacity_l'] > 0 ? (float) $it['remain_l'] * 100 / (float) $it['capacity_l'] : null; ?>
                <tr><td><?= h($it['item']) ?></td><td class="text-right"><?= $n($it['capacity_l']) ?></td>
                    <td class="ut-lv"><?= $bar($p) ?><div class="small-muted"><?= $n($it['remain_l']) ?> ลิตร</div></td>
                    <td><?= $it['slot'] === '00:00' ? 'ต้นวัน' : h($it['slot']) ?></td></tr>
            <?php } ?>
            </tbody>
        </table></div>
        <?php } ?>
    </div>

    <div class="flood-card">
        <div class="flood-card-header"><span><i class="fa fa-truck"></i> รถเติมน้ำเข้าอาคาร</span>
            <?php if ($de) { ?><small class="small-muted">วันที่ <?= h($d($de['date'])) ?></small><?php } ?></div>
        <?php if (!$de) { ?><div class="empty-state">ยังไม่มีข้อมูล</div><?php } else { ?>
        <div class="ut-days">
            <?php foreach ($de['days'] as $dy) { ?>
            <span class="ut-day"><?= h($d($dy['log_date'])) ?> · <b><?= $n($dy['liters']) ?></b> ล. · <?= (int) $dy['trips'] ?> เที่ยว</span>
            <?php } ?>
        </div>
        <?php if ($edit) { ?>
        <div class="table-responsive"><table class="table table-condensed ut-table">
            <thead><tr><th>คัน</th><th>เข้า–ออก</th><th>อาคาร</th><th class="text-right">ลิตร</th><th>หน่วยรถ / หมายเหตุ</th></tr></thead>
            <tbody>
            <?php foreach ($de['trips'] as $t) { ?>
                <tr><td class="nowrap"><?= h($t['trip_label']) ?></td>
                    <td class="nowrap"><?= h($t['time_in']) ?><?= $t['time_out'] ? '–' . h($t['time_out']) : '' ?></td>
                    <td><?= h($t['building']) ?></td><td class="text-right"><?= $n($t['liters']) ?></td>
                    <td class="small"><?= h($t['vehicle']) ?><?= $t['note'] ? '<div class="small-muted">' . h($t['note']) . '</div>' : '' ?></td></tr>
            <?php } ?>
            </tbody>
            <tfoot>
            <?php foreach ($de['buildings'] as $b) { ?>
                <tr class="ut-sum"><td colspan="2"></td><td><?= h($b['building']) ?></td><td class="text-right"><?= $n($b['liters']) ?></td><td class="small-muted"><?= (int) $b['n'] ?> เที่ยว</td></tr>
            <?php } ?>
            </tfoot>
        </table></div>
        <?php } else { ?>
        <div class="table-responsive"><table class="table table-condensed ut-table">
            <thead><tr><th>อาคาร</th><th class="text-right">ลิตร</th><th class="text-right">เที่ยว</th></tr></thead>
            <tbody>
            <?php foreach ($de['buildings'] as $b) { ?>
                <tr><td><?= h($b['building']) ?></td><td class="text-right"><?= $n($b['liters']) ?></td><td class="text-right"><?= (int) $b['n'] ?></td></tr>
            <?php } ?>
            </tbody>
        </table></div>
        <?php } ?>
        <?php } ?>
    </div>
</div>

<?php if ($edit) { ?><script>window.UTIL = <?= flood_js(array('sheet' => $this->utilSheet, 'tabs' => $this->utilTabs, 'canEdit' => $edit, 'tabHint' => 'ระบบแยกชนิดจากชื่อแท็บ: ถังพักน้ำ · น้ำมัน · ออกซิเจน · เติมน้ำ')) ?>;</script><?php } ?>
