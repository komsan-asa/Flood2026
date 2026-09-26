<?php
/**
 * หน้าแรกสาธารณะ — แผนที่สถานการณ์น้ำ (แผนที่เต็มจอ + แผงข้อมูลลอย)
 * แสดงเฉพาะพื้นที่ที่เจ้าหน้าที่ประกาศ (ไม่มีชื่อ/เบอร์/ที่อยู่ของผู้ใด)
 */
$levels = flood_zone_levels();
$counts = is_array($this->counts) ? $this->counts : array();
$hot = defined('HOTLINE_PHONE') ? HOTLINE_PHONE : '';
$icons = array(
    'evacuated' => '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
    'blocked' => '<path d="M5 17h14l-1.5-6h-11z"/><circle cx="8" cy="17" r="2"/><circle cx="16" cy="17" r="2"/><path d="M4 4l16 16"/>',
    'watch' => '<path d="M12 3l9 16H3z"/><path d="M12 10v4M12 17h.01"/>',
);
?>
<div id="pubMap" class="sk-map sk-fullmap" role="region" aria-label="แผนที่พื้นที่ประกาศน้ำท่วม <?= h(flood_region_name()) ?>"></div>

<header class="sk-top sk-glass">
    <a href="<?= URL ?>" class="sk-brand">
        <span class="sk-mark" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 16c2 0 2-1.5 4-1.5S8 16 10 16s2-1.5 4-1.5 2 1.5 4 1.5 2-1.5 4-1.5"/><path d="M2 20.5c2 0 2-1.5 4-1.5s2 1.5 4 1.5 2-1.5 4-1.5 2 1.5 4 1.5 2-1.5 4-1.5"/><path d="M12 2.5s-4 4.5-4 7a4 4 0 0 0 8 0c0-2.5-4-7-4-7z"/></svg>
        </span>
        <span class="sk-brand-text">
            <b><span class="sk-nw">สถานการณ์น้ำ</span> <span id="pubRegion"><?= h(flood_region_name()) ?></span></b>
            <small><span class="sk-live"></span><span>อัปเดต <span id="pubUpdated"><?= h(flood_thai_date(time())) ?></span><span class="sk-next" id="pubNext"></span><span class="sk-online-mini" id="pubOnlineMini"<?= empty($this->visitStats) ? ' hidden' : '' ?>> · <i></i><b data-visit="online"><?= (int) ($this->visitStats['online'] ?? 0) ?></b> คนกำลังดู</span><span class="sk-org"> · <?= h(DEPARTMENT_NAME) ?></span></span></small>
        </span>
    </a>
    <nav class="sk-top-links" aria-label="เมนู">
        <a href="<?= URL ?>sos/status" class="sk-btn sk-btn-ghost">ติดตามคำขอ</a>
        <a href="<?= URL ?>login" class="sk-btn sk-btn-ghost">เจ้าหน้าที่</a>
    </nav>
    <a href="tel:<?= h(flood_ddpm_phone()) ?>" class="sk-btn sk-btn-ddpm sk-call" aria-label="ต้องการความช่วยเหลือ โทร <?= h(flood_ddpm_phone()) ?> สายด่วน ปภ." title="สายด่วน ปภ. แจ้งเหตุสาธารณภัย/น้ำท่วม">
        <i class="fa fa-phone" aria-hidden="true"></i><span class="sk-call-txt"><small>ต้องการความช่วยเหลือโทร</small><b><?= h(flood_ddpm_phone()) ?></b></span>
    </a>
    <a href="tel:<?= h(EMERGENCY_PHONE) ?>" class="sk-btn sk-btn-1669 sk-call" aria-label="โทร <?= h(EMERGENCY_PHONE) ?> เจ็บป่วยฉุกเฉิน">
        <i class="fa fa-phone" aria-hidden="true"></i><span class="sk-call-txt"><small>เจ็บป่วยฉุกเฉิน</small><b><?= h(EMERGENCY_PHONE) ?></b></span>
    </a>
</header>

<aside class="sk-panel sk-glass" id="pubPanel" aria-label="ข้อมูลสถานการณ์">
    <button type="button" class="sk-handle" id="pubHandle" aria-label="ขยายหรือย่อแผงข้อมูล" aria-expanded="false"><i></i></button>
    <div class="sk-panel-scroll">
        <?php $vs = $this->visitStats; ?>
        <div class="sk-visit" id="pubVisit"<?= $vs ? '' : ' hidden' ?> aria-label="สถิติผู้เข้าชม">
            <span class="sk-visit-item on"><i></i><b data-visit="online"><?= number_format((int) ($vs['online'] ?? 0)) ?></b> กำลังออนไลน์</span>
            <span class="sk-visit-item">👥 วันนี้ <b data-visit="today_visitors"><?= number_format((int) ($vs['today_visitors'] ?? 0)) ?></b> คน</span>
            <span class="sk-visit-item">👁 เข้าชมทั้งหมด <b data-visit="total_views"><?= number_format((int) ($vs['total_views'] ?? 0)) ?></b> ครั้ง</span>
        </div>
        <!-- ตัวกรอง (แบบเดียวกับหน้าเจ้าหน้าที่) — ภาค/จังหวัด/อำเภอ สร้างจาก PUBLIC_DATA (views/index/js/default.js) -->
        <form class="sk-filter" id="pubFilter" role="search" aria-label="กรองพื้นที่ประกาศ" onsubmit="return false">
            <select id="pubFLevel" class="sk-fsel" aria-label="ระดับ">
                <option value="">ทุกระดับ</option>
                <?php foreach ($levels as $code => $l) { ?><option value="<?= h($code) ?>"><?= h($l['name']) ?></option><?php } ?>
            </select>
            <select id="pubFRegion" class="sk-fsel" aria-label="ภาค"<?= count((array) $this->regions) > 1 ? '' : ' hidden' ?>>
                <option value="">ทุกภาค</option>
                <?php foreach ((array) $this->regions as $r) { ?><option value="<?= h($r['region']) ?>"><?= h($r['name']) ?></option><?php } ?>
            </select>
            <select id="pubFProvince" class="sk-fsel" aria-label="จังหวัด"<?= count((array) $this->provinces) > 1 ? '' : ' hidden' ?>><option value="">ทุกจังหวัด</option></select>
            <select id="pubFAmphoe" class="sk-fsel" aria-label="อำเภอ"><option value="">ทุกอำเภอ</option></select>
            <div class="sk-fsearch">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
                <input type="search" id="pubFText" placeholder="ค้นหาชื่อพื้นที่ / ข้อความ / ตำบล" aria-label="ค้นหาพื้นที่" autocomplete="off" />
            </div>
            <div class="sk-fbtns">
                <button type="button" class="sk-fnear" id="pubFNear" title="ตั้งตัวกรองเป็นจังหวัดที่คุณอยู่">📍 ใกล้ฉัน</button>
                <button type="button" class="sk-freset" id="pubFReset" hidden>ล้างตัวกรอง ✕</button>
            </div>
        </form>
        <p class="sk-eyebrow" id="pubOverview">ภาพรวมทั้งจังหวัด · แตะเพื่อกรอง</p>
        <div class="sk-bento" id="pubKpis">
            <?php foreach ($levels as $code => $l) { ?>
            <button type="button" class="sk-tile lv-<?= h($code) ?><?= $code === 'evacuated' ? ' sk-tile-wide' : '' ?><?= (int) ($counts[$code] ?? 0) === 0 ? ' is-zero' : '' ?>" data-level="<?= h($code) ?>" aria-pressed="false">
                <span class="sk-tile-ic" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $icons[$code] ?? '<circle cx="12" cy="12" r="6"/>' ?></svg></span>
                <span class="sk-tile-txt">
                    <span class="sk-tile-n" data-count="<?= h($code) ?>"><?= (int) ($counts[$code] ?? 0) ?></span>
                    <span class="sk-tile-l"><?= h($l['name']) ?></span>
                </span>
            </button>
            <?php } ?>
        </div>

        <?php $pn = is_array($this->publicNotices) ? $this->publicNotices : array(); if ($pn) { $ncats = flood_notice_categories(); ?>
        <section class="sk-news" aria-label="ข้อมูลที่ควรรู้">
            <p class="sk-eyebrow">📢 ข้อมูลที่ควรรู้ <span class="sk-news-n"><?= count($pn) ?></span></p>
            <?php foreach ($pn as $i => $n) { $nc = isset($ncats[$n['category']]) ? $ncats[$n['category']] : $ncats['other']; ?>
            <details class="sk-news-item<?= $i >= 3 ? ' sk-news-more' : '' ?>" style="--nc:<?= h($nc['color']) ?>"<?= $i >= 3 ? ' hidden' : '' ?>>
                <summary>
                    <span class="sk-news-ic" aria-hidden="true"><i class="fa <?= h($nc['icon']) ?>"></i></span>
                    <span class="sk-news-body">
                        <span class="sk-news-cat"><?= h($nc['name']) ?></span>
                        <span class="sk-news-title"><?= h($n['title']) ?><?php if (isset($n['verify']) && $n['verify'] === 'announced') { ?> <span class="sk-tag-pending">⏳ รอตรวจสอบ</span><?php } ?></span>
                        <span class="sk-news-meta"><?= h(trim((string) $n['place'] . ($n['amphoe_name'] ? ' · อ.' . $n['amphoe_name'] : ''), ' ·')) ?><?= ($n['place'] || $n['amphoe_name']) ? ' · ' : '' ?><?= h(flood_ago($n['info_at'])) ?></span>
                    </span>
                </summary>
                <div class="sk-news-detail">
                    <?php if ($n['detail']) { ?><p><?= nl2br(h($n['detail'])) ?></p><?php } ?>
                    <?php if ($n['contact']) { ?><p>📞 <?= h($n['contact']) ?></p><?php } ?>
                    <?php if ($n['source_name'] || $n['source_url']) { ?>
                    <p class="sk-news-src">ที่มา: <?= $n['source_url'] ? '<a href="' . h($n['source_url']) . '" target="_blank" rel="noopener">' . h($n['source_name'] ?: 'แหล่งข่าว') . '</a>' : h($n['source_name']) ?> · <?= h(flood_thai_date($n['info_at'])) ?></p>
                    <?php } ?>
                </div>
            </details>
            <?php } ?>
            <?php if (count($pn) > 3) { ?>
            <button type="button" class="sk-news-toggle" onclick="var m=document.querySelectorAll('.sk-news-more'),o=this.getAttribute('aria-expanded')!=='true';for(var i=0;i<m.length;i++){m[i].hidden=!o;}this.setAttribute('aria-expanded',o);this.textContent=o?'ย่อ':'ดูทั้งหมด (<?= count($pn) ?>)';" aria-expanded="false">ดูทั้งหมด (<?= count($pn) ?>)</button>
            <?php } ?>
        </section>
        <?php } ?>

        <div class="sk-list-head">
            <p class="sk-eyebrow" style="margin:0">พื้นที่ประกาศ <b id="pubTotal"><?= (int) ($counts['total'] ?? 0) ?></b> แห่ง · จุดแจ้ง <b id="pubPoints"><?= count(array_filter((array) $this->points, function ($p) { return empty($p['pending']); })) ?></b> จุด<span class="sk-pend-wrap" hidden> · รอตรวจสอบ <b id="pubPending">0</b></span></p>
            <button type="button" class="sk-clear" id="pubLevelClear" hidden>ล้างตัวกรอง <b id="pubLevelName"></b> ✕</button>
        </div>
        <div id="pubList" class="sk-zones" aria-live="polite"></div>

        <p class="sk-eyebrow" style="margin-top:22px">ข้อควรปฏิบัติ</p>
        <div class="sk-tips">
            <div class="sk-tip"><span aria-hidden="true">📦</span>ยกของขึ้นที่สูง ย้ายรถ เก็บเอกสารสำคัญในถุงกันน้ำ</div>
            <div class="sk-tip"><span aria-hidden="true">⚡</span>ระวังไฟดูด ปิดเบรกเกอร์ทันทีหากน้ำเข้าบ้าน</div>
            <div class="sk-tip"><span aria-hidden="true">🚫</span>ห้ามขับรถหรือเดินลุยกระแสน้ำไหลแรง</div>
            <div class="sk-tip"><span aria-hidden="true">🧓</span>ดูแลผู้สูงอายุ ผู้ป่วยติดเตียง เด็กเล็ก หญิงตั้งครรภ์</div>
            <div class="sk-tip"><span aria-hidden="true">💊</span>เตรียมยาประจำตัวสำรอง ยาใกล้หมดแจ้งโรงพยาบาล</div>
            <div class="sk-tip"><span aria-hidden="true">🐍</span>ระวังสัตว์มีพิษหนีน้ำ เช่น งู ตะขาบ</div>
        </div>
        <?php if ($hot !== '') { ?>
        <a class="sk-hotline" href="tel:<?= h($hot) ?>"><i class="fa fa-phone"></i> ศูนย์ประสานน้ำท่วม <b><?= h(flood_format_phone($hot)) ?></b></a>
        <?php } ?>
        <p class="sk-privacy">
            แผนที่นี้แสดงเฉพาะขอบเขตพื้นที่ที่เจ้าหน้าที่ประกาศ ไม่มีข้อมูลส่วนบุคคลของผู้ใด
            <span class="sk-privacy-links">· <a href="<?= URL ?>sos/status">ติดตามคำขอความช่วยเหลือ</a> · <a href="<?= URL ?>login">เจ้าหน้าที่</a> · <a href="<?= URL ?>index/about">เกี่ยวกับระบบ</a></span>
        </p>
        <p class="sk-dev-credit">Developed by Komsan Asa</p>
    </div>
</aside>

<div class="sk-legend sk-glass" id="pubLegend" aria-label="คำอธิบายสี"></div>

<div class="sk-fab" id="pubFab">
    <?php if (!empty($this->publicNotices)) { ?>
    <button type="button" class="sk-btn sk-news-fab" id="pubNewsBtn">📢 ข้อมูลที่ควรรู้ <b><?= count($this->publicNotices) ?></b></button>
    <?php } ?>
    <a href="<?= URL ?>report" class="sk-btn sk-btn-primary sk-fab-report">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/><path d="M12 7v5M9.5 9.5h5"/></svg>
        แจ้งจุดน้ำท่วม
    </a>
    <div class="sk-sos-row">
        <span class="sk-sos-label sk-glass">ขอความช่วยเหลือ</span>
        <a href="<?= URL ?>sos" class="sk-sos" aria-label="ขอความช่วยเหลือ SOS">SOS</a>
    </div>
</div>

<script>
    window.PUBLIC_DATA = <?= flood_js(array(
        'zones' => $this->zones,
        'points' => (array) $this->points,
        'counts' => $counts,
        'area' => flood_region_name(),
        'regions' => array_map(function ($r) {
            return array('code' => $r['region'], 'name' => $r['name']);
        }, (array) $this->regions),
        'provinces' => array_map(function ($p) {
            return array('code' => $p['province_code'], 'name' => $p['name'], 'region' => $p['region'], 'lat' => $p['lat'], 'lng' => $p['lng']);
        }, (array) $this->provinces),
        // อำเภอแบบย่อ [รหัส, ชื่อ] — สร้างปุ่มเมื่อเลือกจังหวัด
        'amphoes' => array_map(function ($a) {
            return array($a['amphoe_code'], $a['name']);
        }, (array) $this->amphoes),
        'region' => flood_region_param('region'),
        'province' => flood_province_param('province'),
        'amphoe' => preg_match('/^\d{4}$/', flood_in('amphoe', '', $_GET)) ? flood_in('amphoe', '', $_GET) : '',
    )) ?>;
</script>
