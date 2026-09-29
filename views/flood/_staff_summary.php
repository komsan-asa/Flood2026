<?php
/**
 * ภาพสรุปบุคลากรที่ได้รับผลกระทบ (ตัวเลขรวม ไม่มีรายชื่อ) — ใช้ในหน้า flood/staff และ flood/manpower
 * ตัวแปร: $ssS = Staff_Model::summary() · $ssD = Staff_Model::departments() · $ssLink = true ให้กดแล้วกรองหน้า flood/staff
 *         $ssShowFollow = true แสดงสรุปการติดตาม (หน้า flood/staff) · $ssFollowCur = สถานะการติดตามที่กำลังกรอง (เน้นแถว)
 */
if (empty($ssS) || empty($ssS['total'])) {
    return;
}
$ssFlags = function_exists('flood_staff_flags') ? flood_staff_flags() : array();
$ssRows = array();
foreach (array('need_help', 'stranded', 'home_hit', 'cant_work', 'need_shelter') as $k) {
    $ssRows[] = array('k' => $k, 'name' => isset($ssFlags[$k]) ? $ssFlags[$k]['name'] : $k, 'n' => (int) $ssS[$k],
        'urgent' => in_array($k, array('cant_work', 'need_shelter'), true));
}
usort($ssRows, function ($a, $b) { return $b['n'] - $a['n']; });
$ssMax = max(1, $ssRows[0]['n']);
$ssHit = (int) $ssS['severe'] + (int) $ssS['moderate'];
$ssFollowed = max(0, $ssHit - (int) $ssS['waiting']);
$ssTop = array_slice(array_values(array_filter((array) $ssD, function ($d) { return (int) $d['hit'] > 0; })), 0, 8);
$ssUrl = function ($p) { return URL . 'flood/staff?' . http_build_query($p); };
?>
<div class="flood-card ss-card">
    <div class="flood-card-header">
        <span><i class="fa fa-user-md"></i> บุคลากรโรงพยาบาลที่ได้รับผลกระทบ: <b><?= $ssHit ?> คน</b> · มาทำงานไม่ได้ <b><?= (int) $ssS['cant_work'] ?> คน</b></span>
        <?php if (!empty($ssLink)) { ?><a href="<?= URL ?>flood/staff?level=affected" class="small-muted">ดูรายชื่อ ›</a><?php } ?>
    </div>
    <div class="flood-card-body ss-grid">
        <div>
            <div class="ss-sub">ความต้องการ (จากผู้ตอบ <?= (int) $ssS['total'] ?> คน)</div>
            <?php foreach ($ssRows as $r) { $w = max(2, round($r['n'] / $ssMax * 100)); ?>
            <?php if (!empty($ssLink)) { ?><a class="ss-row" href="<?= h($ssUrl(array('flag' => $r['k']))) ?>"><?php } else { ?><div class="ss-row"><?php } ?>
                <span class="ss-label"><?= h($r['name']) ?></span>
                <span class="ss-track"><span class="ss-bar<?= $r['urgent'] ? ' is-urgent' : '' ?>" style="width:<?= $w ?>%"></span></span>
                <b class="ss-n"><?= $r['n'] ?></b>
            <?= !empty($ssLink) ? '</a>' : '</div>' ?>
            <?php } ?>
            <div class="small-muted ss-foot">รุนแรง <?= (int) $ssS['severe'] ?> · ปานกลาง <?= (int) $ssS['moderate'] ?> · ติดตามแล้ว <?= $ssFollowed ?> · ยังไม่ติดตาม <?= (int) $ssS['waiting'] ?></div>
        </div>
        <div>
            <div class="ss-sub">หน่วยงานที่ได้รับผลกระทบมากสุด</div>
            <?php if (!$ssTop) { ?><div class="small-muted">ยังไม่มีข้อมูล</div><?php } else { ?>
            <table class="table table-condensed ss-table">
                <thead><tr><th>หน่วยงาน</th><th class="text-right">คน</th></tr></thead>
                <tbody>
                <?php foreach ($ssTop as $d) { ?>
                <tr>
                    <td><?php if (!empty($ssLink)) { ?><a href="<?= h($ssUrl(array('dept' => $d['department'], 'level' => 'affected'))) ?>"><?= h($d['department']) ?></a><?php } else { ?><?= h($d['department']) ?><?php } ?></td>
                    <td class="text-right"><b><?= (int) $d['hit'] ?></b><span class="small-muted"> / <?= (int) $d['n'] ?></span></td>
                </tr>
                <?php } ?>
                </tbody>
            </table>
            <div class="small-muted">ได้รับผลกระทบ / ผู้ตอบในหน่วย · ชื่อหน่วยงานตามที่ผู้ตอบพิมพ์</div>
            <?php } ?>
        </div>
    </div>
    <?php if (!empty($ssShowFollow) && !empty($ssS['follow']) && function_exists('flood_staff_follow_options')) {
        $ssFo = flood_staff_follow_options();
        $ssFs = $ssS['follow'];
        $ssCur = isset($ssFollowCur) ? (string) $ssFollowCur : '';
        $ssAff = 0;
        $ssFMax = 1;
        $ssTot = array('severe' => 0, 'moderate' => 0, 'other' => 0, 'total' => 0);
        foreach ($ssFs as $x) {
            $ssAff += $x['affected'];
            $ssFMax = max($ssFMax, $x['affected']);
            foreach ($ssTot as $k => $v) {
                $ssTot[$k] += $x[$k];
            }
        }
        $ssDone = $ssAff - (isset($ssFs['new']) ? $ssFs['new']['affected'] : 0);
        $ssPct = $ssAff ? round($ssDone / $ssAff * 100) : 0;
        $ssA = function ($href, $text, $cls = '') use ($ssLink) {
            return !empty($ssLink) ? '<a href="' . h($href) . '"' . ($cls !== '' ? ' class="' . $cls . '"' : '') . '>' . $text . '</a>' : $text;
        };
    ?>
    <div class="flood-card-body ss-grid ss-follow" id="staffFollowSum">
        <div>
            <div class="ss-sub">การติดตามผู้ได้รับผลกระทบ (รุนแรง + ปานกลาง <?= $ssAff ?> คน)</div>
            <?php foreach ($ssFo as $code => $o) { $n = isset($ssFs[$code]) ? $ssFs[$code]['affected'] : 0; $w = $n ? max(2, round($n / $ssFMax * 100)) : 0; ?>
            <?php if (!empty($ssLink)) { ?><a class="ss-row<?= $ssCur === $code ? ' is-on' : '' ?>" href="<?= h($ssUrl(array('follow' => $code, 'level' => 'affected'))) ?>"><?php } else { ?><div class="ss-row"><?php } ?>
                <span class="ss-label"><i class="fa <?= h($o['icon']) ?> ss-fi-<?= h($code) ?>"></i> <?= h($o['name']) ?></span>
                <span class="ss-track"><span class="ss-bar ss-f-<?= h($code) ?>" style="width:<?= $w ?>%"></span></span>
                <b class="ss-n"><?= $n ?></b>
            <?= !empty($ssLink) ? '</a>' : '</div>' ?>
            <?php } ?>
            <div class="small-muted ss-foot">ติดตามแล้ว <b><?= $ssDone ?></b> จาก <?= $ssAff ?> คน (<?= $ssPct ?>%) · ยังไม่ติดตาม <?= $ssAff - $ssDone ?> คน</div>
        </div>
        <div>
            <div class="ss-sub">สถานะการติดตาม แยกระดับผลกระทบ (ผู้ตอบ <?= $ssTot['total'] ?> คน)</div>
            <table class="table table-condensed ss-table ss-ftable">
                <thead><tr><th>สถานะ</th><th class="text-right">รุนแรง</th><th class="text-right">ปานกลาง</th><th class="text-right" title="เล็กน้อย/เฝ้าระวัง · ไม่ได้รับผลกระทบ · ไม่ระบุ">ระดับอื่น</th><th class="text-right">รวม</th></tr></thead>
                <tbody>
                <?php foreach ($ssFo as $code => $o) { $x = isset($ssFs[$code]) ? $ssFs[$code] : array('severe' => 0, 'moderate' => 0, 'other' => 0, 'total' => 0); ?>
                <tr<?= $ssCur === $code ? ' class="is-on"' : '' ?>>
                    <td><?= $ssA($ssUrl(array('follow' => $code)), '<i class="fa ' . h($o['icon']) . ' ss-fi-' . h($code) . '"></i> ' . h($o['name'])) ?></td>
                    <td class="text-right"><?= $x['severe'] ? $ssA($ssUrl(array('follow' => $code, 'level' => 'severe')), (string) $x['severe']) : '<span class="small-muted">–</span>' ?></td>
                    <td class="text-right"><?= $x['moderate'] ? $ssA($ssUrl(array('follow' => $code, 'level' => 'moderate')), (string) $x['moderate']) : '<span class="small-muted">–</span>' ?></td>
                    <td class="text-right"><?= $x['other'] ?: '<span class="small-muted">–</span>' ?></td>
                    <td class="text-right"><b><?= $x['total'] ? $ssA($ssUrl(array('follow' => $code)), (string) $x['total']) : '0' ?></b></td>
                </tr>
                <?php } ?>
                </tbody>
                <tfoot><tr><th>รวม</th><th class="text-right"><?= $ssTot['severe'] ?></th><th class="text-right"><?= $ssTot['moderate'] ?></th><th class="text-right"><?= $ssTot['other'] ?></th><th class="text-right"><?= $ssTot['total'] ?></th></tr></tfoot>
            </table>
            <div class="small-muted"><?= !empty($ssLink) ? 'กดชื่อสถานะหรือตัวเลขเพื่อดูรายชื่อ · ' : '' ?>ระดับอื่น = เล็กน้อย/เฝ้าระวัง · ไม่ได้รับผลกระทบ · ไม่ระบุ</div>
        </div>
    </div>
    <?php } ?>
</div>
