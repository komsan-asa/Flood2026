<?php
/**
 * ภาพสรุปบุคลากรที่ได้รับผลกระทบ (ตัวเลขรวม ไม่มีรายชื่อ) — ใช้ในหน้า flood/staff และ flood/manpower
 * ตัวแปร: $ssS = Staff_Model::summary() · $ssD = Staff_Model::departments() · $ssLink = true ให้กดแล้วกรองหน้า flood/staff
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
</div>
