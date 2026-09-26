<?php
/**
 * สถานการณ์อุทกภัยทั่วประเทศรายวัน (สาธารณะ) — index/situation
 * ข้อมูลจาก Index::situation() → Sitrep_Model (ตาราง flood_sitrep) · ไม่มีข้อมูลส่วนบุคคล
 * ?d=YYYY-MM-DD เลือกวันของตารางรายจังหวัด
 */
$days = is_array($this->sitDays) ? $this->sitDays : array();
$rows = is_array($this->sitRows) ? $this->sitRows : array();
$allDates = is_array($this->sitDates) ? $this->sitDates : array();
$sel = $this->sitDate;
$zc = is_array($this->sitZoneCounts) ? $this->sitZoneCounts : array();
// ตัวเลขรายจังหวัดของรายงานก่อนหน้า — แสดงแทนเมื่อวันที่เลือกไม่มีตัวเลขรายจังหวัด
$prevRows = array();
foreach ((array) $this->sitPrevRows as $r) {
    $prevRows[$r['province_code']] = $r;
}
$prevDate = $this->sitPrevDate;
$usePrev = function ($r) use ($prevRows) {
    $pv = $r['province_code'];
    return ($r['amphoes'] === null && $r['households'] === null && isset($prevRows[$pv]) && $prevRows[$pv]['households'] !== null)
        ? $prevRows[$pv] : null;
};
$anyPrev = false;
foreach ($rows as $r) {
    $anyPrev = $anyPrev || (bool) $usePrev($r);
}
$levels = flood_zone_levels();
$from = $this->sitFrom;

$fmt = function ($v) { return $v === null || $v === '' ? '–' : number_format((int) $v); };
$dLabel = function ($d, $year = false) { return flood_thai_date($d, false, $year); };
$byDate = array();
foreach ($days as $d) {
    $byDate[$d['report_date']] = $d;
}
// วันล่าสุดที่มีตัวเลขรวม (ครัวเรือน) และวันก่อนหน้า — ใช้เทียบเพิ่ม/ลด
$withNum = array_values(array_filter($days, function ($d) { return $d['households'] !== null; }));
$last = $withNum ? $withNum[count($withNum) - 1] : null;
$prev = count($withNum) > 1 ? $withNum[count($withNum) - 2] : null;
$maxHh = 0;
foreach ($days as $d) {
    $maxHh = max($maxHh, (int) $d['households']);
}
$delta = function ($k) use ($last, $prev) {
    if (!$last || !$prev || $last[$k] === null || $prev[$k] === null) {
        return '';
    }
    $x = (int) $last[$k] - (int) $prev[$k];
    if ($x === 0) {
        return '<span class="st-delta">เท่าเดิม</span>';
    }
    return '<span class="st-delta ' . ($x > 0 ? 'up' : 'down') . '">' . ($x > 0 ? '▲ +' : '▼ ') . number_format($x) . '</span>';
};
$trendTxt = array('rising' => array('▲', 'น้ำเพิ่ม'), 'stable' => array('▬', 'ทรงตัว'), 'falling' => array('▼', 'ลดลง'));
$statusTxt = array('resolved' => 'คลี่คลาย', 'news' => 'จากข่าว', 'warning' => 'เฝ้าระวัง');
$sources = array();
$addSrc = function ($name, $url) use (&$sources) {
    if ($url && !isset($sources[$url])) {
        $sources[$url] = $name ?: parse_url($url, PHP_URL_HOST);
    }
};
foreach ($days as $d) {
    $addSrc($d['source_name'], $d['source_url']);
}
foreach ($rows as $r) {
    $addSrc($r['source_name'], $r['source_url']);
}
?>
<div class="st-page">
<nav class="st-nav" aria-label="เมนู">
    <a href="<?= URL ?>" class="st-back"><i class="fa fa-map" aria-hidden="true"></i> แผนที่สถานการณ์</a>
    <span class="st-nav-r">
        <a href="<?= URL ?>report" class="st-link">แจ้งจุดน้ำท่วม</a>
        <a href="<?= URL ?>index/about" class="st-link">เกี่ยวกับระบบ</a>
    </span>
</nav>

<main class="st-wrap">
    <section class="st-hero">
        <p class="st-kicker">ติดตามทั่วประเทศ · ตั้งแต่ <?= h($dLabel($from, true)) ?></p>
        <h1>สถานการณ์อุทกภัย<span>ทั่วประเทศ</span></h1>
        <p class="st-lead">สรุปรายวันจากรายงานกรมป้องกันและบรรเทาสาธารณภัย (ปภ.) ข่าว และโซเชียล
            พื้นที่รายอำเภอที่นำขึ้นแผนที่จากข่าวติดป้าย <span class="sk-tag-pending">⏳ รอตรวจสอบ</span> จนกว่าเจ้าหน้าที่จะยืนยัน</p>
        <?php if ($last) { ?>
        <p class="st-asof"><span class="st-live" aria-hidden="true"></span>ตัวเลขล่าสุด ณ <b><?= h(flood_thai_date($last['as_of'] ?: $last['report_date'], (bool) $last['as_of'])) ?></b>
            <?php if ($last['source_url']) { ?>· <a href="<?= h($last['source_url']) ?>" target="_blank" rel="noopener"><?= h($last['source_name'] ?: 'แหล่งข้อมูล') ?></a><?php } ?></p>
        <?php } ?>
    </section>

    <?php if (!$days) { ?>
    <section class="st-sec st-empty">
        <p>ยังไม่มีข้อมูลสรุปรายวัน — เจ้าหน้าที่กำลังรวบรวม</p>
        <a href="<?= URL ?>" class="st-btn">ดูแผนที่สถานการณ์</a>
    </section>
    <?php } ?>

    <?php if ($last) { ?>
    <section class="st-kpis" aria-label="ตัวเลขล่าสุด">
        <div class="st-kpi st-kpi-main"><span>จังหวัดที่ยังมีน้ำท่วม</span><b><?= $fmt($last['provinces_ongoing']) ?></b><?= $delta('provinces_ongoing') ?></div>
        <div class="st-kpi"><span>อำเภอ</span><b><?= $fmt($last['amphoes']) ?></b><?= $delta('amphoes') ?></div>
        <div class="st-kpi"><span>ตำบล</span><b><?= $fmt($last['tambons']) ?></b><?= $delta('tambons') ?></div>
        <div class="st-kpi"><span>หมู่บ้าน</span><b><?= $fmt($last['villages']) ?></b><?= $delta('villages') ?></div>
        <div class="st-kpi"><span>ครัวเรือน</span><b><?= $fmt($last['households']) ?></b><?= $delta('households') ?></div>
        <div class="st-kpi"><span>ประชาชน (คน)</span><b><?= $fmt($last['people']) ?></b><?= $delta('people') ?></div>
    </section>
    <?php if ($prev) { ?>
    <p class="st-note-sm">เทียบกับรายงาน <?= h(flood_thai_date($prev['as_of'] ?: $prev['report_date'], (bool) $prev['as_of'])) ?><?= $last['deaths'] !== null ? ' · เสียชีวิต ' . $fmt($last['deaths']) . ' ราย' : '' ?></p>
    <?php } ?>
    <?php if ($last['note']) { ?>
    <div class="st-callout"><?= nl2br(h($last['note'])) ?></div>
    <?php } ?>
    <?php } ?>

    <?php if ($days) { ?>
    <section class="st-sec">
        <h2><i class="fa fa-calendar" aria-hidden="true"></i> ไทม์ไลน์รายวัน</h2>
        <p class="st-sub">แท่ง = จำนวนครัวเรือนที่ได้รับผลกระทบตามรายงาน ปภ. · แตะวันเพื่อดูรายจังหวัด</p>
        <div class="st-days" role="list">
            <?php foreach ($days as $d) {
                $has = $d['households'] !== null;
                $pct = $has && $maxHh > 0 ? max(4, round((int) $d['households'] / $maxHh * 100)) : 0;
                $isSel = $d['report_date'] === $sel;
                $nPv = isset($allDates[$d['report_date']]) ? $allDates[$d['report_date']] : 0; ?>
            <a role="listitem" href="<?= URL ?>index/situation?d=<?= h($d['report_date']) ?>#provinces" class="st-day<?= $has ? '' : ' is-warn' ?><?= $isSel ? ' is-sel' : '' ?>"<?= $isSel ? ' aria-current="true"' : '' ?>>
                <span class="st-day-d"><?= h($dLabel($d['report_date'])) ?></span>
                <span class="st-bar" aria-hidden="true"><i style="height:<?= (int) $pct ?>%"></i></span>
                <?php if ($has) { ?>
                <b class="st-day-n"><?= $fmt($d['households']) ?></b><small>ครัวเรือน</small>
                <span class="st-day-pv"><?= $fmt($d['provinces_ongoing']) ?> จังหวัด · <?= $fmt($d['people']) ?> คน</span>
                <?php } else { ?>
                <b class="st-day-n st-day-w">เฝ้าระวัง</b><small>ยังไม่มียอดรวม ปภ.</small>
                <span class="st-day-pv"><?= $nPv ? $nPv . ' จังหวัดมีรายงานข่าว' : 'ประกาศเตือน' ?></span>
                <?php } ?>
            </a>
            <?php } ?>
        </div>
        <script>(function () { var d = document.querySelector('.st-days'), s = d && d.querySelector('.is-sel');
            if (s) { d.scrollLeft += s.getBoundingClientRect().right - d.getBoundingClientRect().right + 8; } })();</script>
        <?php foreach (array_reverse($days) as $d) { if (!$d['note'] || $d === $last) { continue; } ?>
        <details class="st-daynote">
            <summary><b><?= h($dLabel($d['report_date'], true)) ?></b> <?= h(mb_strimwidth(preg_replace('/\s+/u', ' ', $d['note']), 0, 110, '…', 'UTF-8')) ?></summary>
            <p><?= nl2br(h($d['note'])) ?></p>
            <?php if ($d['source_url']) { ?><p class="st-src-line">ที่มา: <a href="<?= h($d['source_url']) ?>" target="_blank" rel="noopener"><?= h($d['source_name'] ?: 'แหล่งข้อมูล') ?></a></p><?php } ?>
        </details>
        <?php } ?>
    </section>
    <?php } ?>

    <?php if ($allDates) { ?>
    <section class="st-sec" id="provinces">
        <h2><i class="fa fa-table" aria-hidden="true"></i> รายจังหวัด · <?= h($dLabel($sel, true)) ?></h2>
        <div class="st-chips" role="group" aria-label="เลือกวัน">
            <?php foreach ($allDates as $dt => $n) { ?>
            <a href="<?= URL ?>index/situation?d=<?= h($dt) ?>#provinces" class="st-chip"<?= $dt === $sel ? ' aria-current="true"' : '' ?>><?= h($dLabel($dt)) ?> <small><?= (int) $n ?></small></a>
            <?php } ?>
        </div>
        <?php if ($anyPrev) { ?>
        <p class="st-sub">ตัวเลขสีจางคือยอดจากรายงาน <?= h($dLabel($prevDate, true)) ?> (รายงานวันนี้ระบุเพียงแนวโน้มรายจังหวัด)</p>
        <?php } ?>
        <?php if (!$rows) { ?>
        <p class="st-sub">ไม่มีข้อมูลรายจังหวัดของวันนี้</p>
        <?php } else { ?>
        <div class="st-table" role="table" aria-label="สถานการณ์รายจังหวัด">
            <div class="st-tr st-th" role="row">
                <span role="columnheader">จังหวัด</span>
                <span role="columnheader">แนวโน้ม</span>
                <span role="columnheader" class="st-num">อ. / ต. / หมู่บ้าน</span>
                <span role="columnheader" class="st-num">ครัวเรือน</span>
                <span role="columnheader">อำเภอที่มีรายงาน</span>
                <span role="columnheader" class="st-num">บนแผนที่</span>
            </div>
            <?php foreach ($rows as $r) {
                $pv = $r['province_code'];
                $st = (string) $r['status'];
                $tr = isset($trendTxt[$r['trend']]) ? $trendTxt[$r['trend']] : null;
                $nz = isset($zc[$pv]) ? array_sum($zc[$pv]) : 0;
                $names = $r['amphoe_names'] ? explode(',', $r['amphoe_names']) : array(); ?>
            <div class="st-tr<?= $st === 'resolved' ? ' is-done' : '' ?>" role="row">
                <span role="cell" class="st-pv"><b><?= h($r['province_name'] ?: $pv) ?></b>
                    <?php if (isset($statusTxt[$st])) { ?><em class="st-st st-st-<?= h($st) ?>"><?= h($statusTxt[$st]) ?></em><?php } ?></span>
                <span role="cell"><?php if ($tr) { ?><span class="st-trend t-<?= h($r['trend']) ?>"><?= $tr[0] ?> <?= h($tr[1]) ?></span><?php } else { ?><span class="st-dim">–</span><?php } ?></span>
                <?php $pr = $usePrev($r); ?>
                <?php if ($pr) { ?>
                <span role="cell" class="st-num st-prev" data-label="อ./ต./หมู่บ้าน" title="ตัวเลขจากรายงาน <?= h($dLabel($prevDate)) ?>"><?= $fmt($pr['amphoes']) ?> / <?= $fmt($pr['tambons']) ?> / <?= $fmt($pr['villages']) ?><small><?= h($dLabel($prevDate)) ?></small></span>
                <span role="cell" class="st-num st-hh st-prev" data-label="ครัวเรือน" title="ตัวเลขจากรายงาน <?= h($dLabel($prevDate)) ?>"><?= $fmt($pr['households']) ?><small><?= h($dLabel($prevDate)) ?></small></span>
                <?php } else { ?>
                <span role="cell" class="st-num" data-label="อ./ต./หมู่บ้าน"><?= $fmt($r['amphoes']) ?> / <?= $fmt($r['tambons']) ?> / <?= $fmt($r['villages']) ?></span>
                <span role="cell" class="st-num st-hh" data-label="ครัวเรือน"><?= $fmt($r['households']) ?></span>
                <?php } ?>
                <span role="cell" class="st-names">
                    <?= $names ? h(implode(' · ', $names)) : '' ?>
                    <?php if ($r['note']) { ?><small><?= h($r['note']) ?></small><?php } ?>
                </span>
                <span role="cell" class="st-num">
                    <?php if ($nz) { ?><a href="<?= URL ?>?province=<?= h($pv) ?>" class="st-maplink" title="ดูพื้นที่ประกาศบนแผนที่"><?= (int) $nz ?> พื้นที่ ›</a><?php } else { ?><span class="st-dim">–</span><?php } ?>
                </span>
            </div>
            <?php } ?>
        </div>
        <?php } ?>
    </section>
    <?php } ?>

    <?php $tot = array(); foreach ($zc as $pv => $lv) { foreach ($lv as $code => $c) { $tot[$code] = ($tot[$code] ?? 0) + $c; } } if ($tot) { ?>
    <section class="st-sec">
        <h2><i class="fa fa-map-marker" aria-hidden="true"></i> พื้นที่ประกาศบนแผนที่ขณะนี้</h2>
        <div class="st-levels">
            <?php foreach ($levels as $code => $l) { if (empty($tot[$code])) { continue; } ?>
            <span class="st-lv" style="--c:<?= h($l['color']) ?>"><i aria-hidden="true"></i><?= h($l['name']) ?> <b><?= (int) $tot[$code] ?></b></span>
            <?php } ?>
        </div>
        <p class="st-sub">รวม <?= number_format(array_sum($tot)) ?> พื้นที่ใน <?= count($zc) ?> จังหวัด · รวมพื้นที่ที่เจ้าหน้าที่ประกาศและพื้นที่จากข่าวที่รอตรวจสอบ</p>
        <a href="<?= URL ?>" class="st-btn"><i class="fa fa-map-o" aria-hidden="true"></i> เปิดแผนที่ทั่วประเทศ</a>
    </section>
    <?php } ?>

    <section class="st-sec st-foot">
        <h2><i class="fa fa-info-circle" aria-hidden="true"></i> แหล่งข้อมูลและข้อจำกัด</h2>
        <ul class="st-caveats">
            <li>ตัวเลขรวมเป็นยอดตามรายงาน ปภ. ณ เวลาที่ระบุ ซึ่งสื่อมวลชนเผยแพร่ต่อ บางวันไม่มีรายงานสรุประดับประเทศ (มีเพียงประกาศเตือน)</li>
            <li>ข้อมูลรายอำเภอและตำบลรวบรวมจากข่าวและโซเชียล ตำแหน่งบนแผนที่เป็นจุดกลางตำบล/อำเภอโดยประมาณ ยังไม่ผ่านการตรวจสอบของเจ้าหน้าที่</li>
            <li>ตัวเลขจากต่างแหล่งอาจไม่ตรงกัน ให้ยึดประกาศของจังหวัดและ ปภ. เป็นหลัก</li>
            <li>ต้องการความช่วยเหลือ โทร <a href="tel:<?= h(flood_ddpm_phone()) ?>"><b><?= h(flood_ddpm_phone()) ?></b></a> สายด่วน ปภ. · เจ็บป่วยฉุกเฉิน <a href="tel:<?= h(EMERGENCY_PHONE) ?>"><b><?= h(EMERGENCY_PHONE) ?></b></a></li>
        </ul>
        <?php if ($sources) { ?>
        <p class="st-src-h">แหล่งข้อมูล</p>
        <ul class="st-srcs">
            <?php foreach ($sources as $u => $n) { ?>
            <li><a href="<?= h($u) ?>" target="_blank" rel="noopener"><?= h($n) ?></a></li>
            <?php } ?>
        </ul>
        <?php } ?>
        <p class="st-credit"><?= h(DEPARTMENT_NAME) ?> · Developed by Komsan Asa</p>
    </section>
</main>
</div>
