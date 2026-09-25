<?php
/**
 * หน้าแนะนำระบบ (สาธารณะ) — index/about
 * ตัวเลขสดมาจาก Index::about() · ไม่มีข้อมูลส่วนบุคคล
 */
$levels = flood_zone_levels();
$roles = flood_role_labels();
$roleDesc = flood_role_descriptions();
$st = is_array($this->aboutStats) ? $this->aboutStats : array();
$n = function ($k) use ($st) { return number_format((int) ($st[$k] ?? 0)); };
$ddpm = flood_ddpm_phone();
?>
<div class="ab-page">
<nav class="ab-nav">
    <a href="<?= URL ?>" class="ab-back"><i class="fa fa-map" aria-hidden="true"></i> แผนที่สถานการณ์</a>
    <span class="ab-nav-r">
        <a href="<?= URL ?>report" class="ab-link">แจ้งจุดน้ำท่วม</a>
        <a href="<?= URL ?>login" class="ab-link">เจ้าหน้าที่</a>
    </span>
</nav>

<main class="ab-wrap" id="abCapture">

    <!-- ===== Hero ===== -->
    <section class="ab-hero">
        <div class="ab-hero-mark" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 16c2 0 2-1.5 4-1.5S8 16 10 16s2-1.5 4-1.5 2 1.5 4 1.5 2-1.5 4-1.5"/><path d="M2 20.5c2 0 2-1.5 4-1.5s2 1.5 4 1.5 2-1.5 4-1.5 2 1.5 4 1.5 2-1.5 4-1.5"/><path d="M12 2.5s-4 4.5-4 7a4 4 0 0 0 8 0c0-2.5-4-7-4-7z"/></svg>
        </div>
        <p class="ab-kicker">แนะนำระบบ</p>
        <h1>ระบบ <span>SK Flood</span> by SCPH</h1>
        <p class="ab-tag">แจ้งเร็ว · ประกาศชัด · ช่วยทัน</p>
        <p class="ab-lead">ระบบแจ้งจุดน้ำท่วมและประสานการช่วยเหลือผู้ประสบภัย จังหวัด<?= h(PROVINCE_NAME) ?>
            ประชาชนแจ้งได้จากมือถือทันที เจ้าหน้าที่ตรวจสอบแล้วประกาศขึ้นแผนที่ พร้อมมอบหมายทีมลงพื้นที่
            และติดตามจนปิดงาน ทุกฝ่ายเห็นภาพเดียวกันแบบเรียลไทม์</p>
        <p class="ab-org"><i class="fa fa-hospital-o" aria-hidden="true"></i> <?= h(DEPARTMENT_NAME) ?></p>
        <div class="ab-hero-btns" data-html2canvas-ignore>
            <a href="<?= URL ?>" class="ab-btn ab-btn-primary"><i class="fa fa-map-o" aria-hidden="true"></i> เปิดแผนที่สถานการณ์</a>
            <button type="button" class="ab-btn ab-btn-ghost" id="abSaveImg"><i class="fa fa-download" aria-hidden="true"></i> ดาวน์โหลดเป็นรูปภาพ</button>
        </div>
        <blockquote class="ab-quote">“ข้อมูลที่ถูกต้อง ไปถึงคนที่ต้องใช้ ทันเวลา — คือความปลอดภัยของทุกคน”</blockquote>
    </section>

    <!-- ===== Workflow ===== -->
    <section class="ab-sec">
        <h2><i class="fa fa-random" aria-hidden="true"></i> ขั้นตอนการทำงานของระบบ</h2>
        <ol class="ab-steps">
            <li><b>1</b><div><h3>ประชาชนแจ้ง</h3><p>แจ้งจุดน้ำท่วม (GPS / ปักหมุด / แนบรูป) หรือกด SOS ขอความช่วยเหลือ ไม่ต้องสมัครสมาชิก</p></div></li>
            <li><b>2</b><div><h3>ตรวจสอบ</h3><p>เจ้าหน้าที่ศูนย์ตรวจรายงาน โทรยืนยัน รวมรายงานซ้ำ และคัดข่าวจากโซเชียล</p></div></li>
            <li><b>3</b><div><h3>ประกาศพื้นที่</h3><p>ประกาศเป็นวงกลมหรือขอบเขตบนแผนที่ แยกสีตามระดับความรุนแรง ประชาชนเห็นทันที</p></div></li>
            <li><b>4</b><div><h3>มอบหมายทีม</h3><p>ออกใบงานช่วยเหลือ ส่งให้ทีมในพื้นที่ ทีมอัปเดตสถานะจากหน้างาน</p></div></li>
            <li><b>5</b><div><h3>ติดตามจนปิดงาน</h3><p>ผู้แจ้งติดตามสถานะได้เอง · ดูแลกลุ่มเปราะบาง · ปิดประกาศเมื่อน้ำลด</p></div></li>
        </ol>
    </section>

    <!-- ===== Big numbers ===== -->
    <section class="ab-stats">
        <div><b><?= $n('amphoes') ?></b><span>อำเภอ</span></div>
        <div><b><?= $n('tambons') ?></b><span>ตำบลในระบบ</span></div>
        <div><b><?= count($levels) ?></b><span>ระดับพื้นที่ประกาศ</span></div>
        <div><b><?= count($roles) ?></b><span>สิทธิ์ผู้ใช้งาน</span></div>
    </section>
    <section class="ab-live">
        <span class="ab-live-dot" aria-hidden="true"></span>
        <span>ขณะนี้ · ประกาศอยู่ <b><?= $n('zones') ?></b> พื้นที่</span>
        <span>จุดสังเกตยืนยันแล้ว <b><?= $n('points') ?></b></span>
        <span>รอตรวจสอบ <b><?= $n('pending') ?></b></span>
        <?php if ((int) ($st['visitors'] ?? 0) >= 100) { ?><span>ผู้เข้าชมสะสม <b><?= $n('visitors') ?></b></span><?php } ?>
    </section>

    <!-- ===== Features ===== -->
    <section class="ab-sec">
        <h2><i class="fa fa-star" aria-hidden="true"></i> จุดเด่นของระบบ</h2>
        <div class="ab-grid2">
            <div class="ab-card">
                <h3><i class="fa fa-users" aria-hidden="true"></i> สำหรับประชาชน</h3>
                <ul>
                    <li><b>แผนที่สถานการณ์น้ำ</b> เห็นพื้นที่ประกาศทั้งจังหวัด กรองตามอำเภอ/ระดับ</li>
                    <li><b>แจ้งจุดน้ำท่วม</b> ระบุตำแหน่งด้วย GPS หรือปักหมุด แนบรูป บอกความลึก/รถผ่านได้ไหม</li>
                    <li><b>SOS ขอความช่วยเหลือ</b> ได้เลขคำขอ ติดตามสถานะเองได้</li>
                    <li><b>เห็นรายงานใหม่ทันที</b> ขึ้นแผนที่พร้อมป้าย “รอตรวจสอบ” จนกว่าเจ้าหน้าที่ยืนยัน</li>
                    <li><b>ข้อมูลที่ควรรู้</b> ศูนย์พักพิง โรงพยาบาล ไฟฟ้า/ประปา การระบายน้ำ เส้นทาง</li>
                    <li><b>ปุ่มโทรด่วน</b> ปภ. <?= h($ddpm) ?> · เจ็บป่วยฉุกเฉิน <?= h(EMERGENCY_PHONE) ?></li>
                </ul>
            </div>
            <div class="ab-card">
                <h3><i class="fa fa-id-badge" aria-hidden="true"></i> สำหรับเจ้าหน้าที่</h3>
                <ul>
                    <li><b>ตรวจรายงาน</b> แยกแท็บ รอตรวจ / ยืนยันแล้ว·รอประกาศ / ประกาศแล้ว</li>
                    <li><b>ประกาศพื้นที่</b> วาดวงกลมหรือขอบเขต ผูกรายงานเข้าพื้นที่เดิมได้</li>
                    <li><b>ใบงานและทีม</b> มอบหมาย ติดตาม ทีมเห็นเฉพาะงานของตัวเอง</li>
                    <li><b>ทะเบียนกลุ่มเปราะบาง</b> ผู้ป่วยติดเตียง ผู้สูงอายุ ในพื้นที่เสี่ยง</li>
                    <li><b>นำเข้าข่าวจากโซเชียล</b> เป็นรายงานรอตรวจสอบ พร้อมลิงก์ต้นทาง</li>
                    <li><b>หน้าจอรีเฟรชเอง</b> พร้อมเวลาข้อมูล · ดูผู้ใช้ออนไลน์และประวัติการใช้งาน</li>
                </ul>
            </div>
        </div>
    </section>

    <!-- ===== Levels ===== -->
    <section class="ab-sec">
        <h2><i class="fa fa-tint" aria-hidden="true"></i> ระดับพื้นที่ประกาศ</h2>
        <div class="ab-levels">
            <?php foreach ($levels as $code => $l) { ?>
            <div class="ab-level" style="--lv: <?= h($l['color']) ?>">
                <i class="fa <?= h($l['icon'] ?? 'fa-circle') ?>" aria-hidden="true"></i>
                <div><b><?= h($l['name']) ?></b><?php if (!empty($l['desc'])) { ?><small><?= h($l['desc']) ?></small><?php } ?></div>
            </div>
            <?php } ?>
            <div class="ab-level ab-level-pend" style="--lv: #b45309">
                <i class="fa fa-question-circle" aria-hidden="true"></i>
                <div><b>รอตรวจสอบ</b><small>ประชาชนแจ้งเข้ามา เจ้าหน้าที่ยังไม่ยืนยัน (แสดง <?= (int) (defined('PUBLIC_PENDING_HOURS') ? PUBLIC_PENDING_HOURS : 48) ?> ชม.)</small></div>
            </div>
        </div>
    </section>

    <!-- ===== Roles ===== -->
    <section class="ab-sec">
        <h2><i class="fa fa-key" aria-hidden="true"></i> สิทธิ์ผู้ใช้งาน</h2>
        <div class="ab-roles">
            <?php foreach ($roles as $code => $name) { ?>
            <div><b><?= h($name) ?></b><small><?= h($roleDesc[$code] ?? '') ?></small></div>
            <?php } ?>
        </div>
    </section>

    <!-- ===== Privacy ===== -->
    <section class="ab-sec">
        <h2><i class="fa fa-shield" aria-hidden="true"></i> ความเป็นส่วนตัวและความปลอดภัย</h2>
        <div class="ab-grid3">
            <div class="ab-mini"><i class="fa fa-user-secret" aria-hidden="true"></i><b>ไม่เปิดเผยตัวผู้แจ้ง</b><p>หน้าสาธารณะไม่แสดงชื่อ เบอร์โทร หรือบ้านเลขที่ ติดตามคำขอต้องใช้เลขคำขอคู่กับเบอร์โทร</p></div>
            <div class="ab-mini"><i class="fa fa-lock" aria-hidden="true"></i><b>สิทธิ์ตามหน้าที่</b><p>แต่ละบทบาทเห็นเฉพาะเมนูที่จำเป็น ป้องกัน CSRF และจำกัดจำนวนการแจ้งต่อ IP/เบอร์</p></div>
            <div class="ab-mini"><i class="fa fa-history" aria-hidden="true"></i><b>ตรวจสอบย้อนหลังได้</b><p>บันทึกประวัติการเปลี่ยนแปลงทุกรายการ รู้ว่าใครทำอะไร เมื่อไร</p></div>
        </div>
    </section>

    <!-- ===== Benefits ===== -->
    <section class="ab-sec">
        <h2><i class="fa fa-heart" aria-hidden="true"></i> ประโยชน์ที่ได้</h2>
        <ul class="ab-benefits">
            <li>ประชาชนรู้เส้นทางที่ผ่านไม่ได้ก่อนออกเดินทาง ลดความเสี่ยงรถติดน้ำ</li>
            <li>คำขอความช่วยเหลือไม่ตกหล่น ทุกเรื่องมีเลข มีผู้รับผิดชอบ มีสถานะ</li>
            <li>ศูนย์ฯ เห็นภาพรวมทั้งจังหวัดบนแผนที่เดียว ตัดสินใจส่งทีมได้เร็วขึ้น</li>
            <li>กลุ่มเปราะบางในพื้นที่น้ำท่วมได้รับการดูแลก่อน</li>
            <li>ลดข่าวลือ — ข้อมูลที่เผยแพร่ผ่านการตรวจสอบของเจ้าหน้าที่</li>
            <li>มีข้อมูลย้อนหลังสำหรับสรุปบทเรียนและวางแผนรับมือปีต่อไป</li>
        </ul>
    </section>

    <!-- ===== Closing ===== -->
    <section class="ab-close">
        <p class="ab-close-h">ใช้งานผ่านเบราว์เซอร์ได้ทุกที่ ทั้งมือถือ แท็บเล็ต และคอมพิวเตอร์ — ไม่ต้องติดตั้งแอป</p>
        <div class="ab-chips">
            <span>PHP 8</span><span>MySQL / MariaDB</span><span>MVC</span><span>Leaflet · OpenStreetMap</span>
            <span><?= count($roles) ?> ระดับสิทธิ์</span><span>รองรับทุกอุปกรณ์</span><span>อัปเดตอัตโนมัติ</span>
        </div>
        <p class="ab-foot"><?= h(SHORT_NAME_SYSTEM) ?> by SCPH · <?= h(DEPARTMENT_NAME) ?><br>Developed by Komsan Asa</p>
    </section>
</main>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js" defer></script>
<script>
(function () {
    var b = document.getElementById('abSaveImg');
    if (!b) return;
    b.addEventListener('click', function () {
        if (!window.html2canvas) { alert('กำลังโหลด กรุณาลองอีกครั้ง'); return; }
        var old = b.innerHTML;
        b.disabled = true; b.innerHTML = '<i class="fa fa-spinner fa-spin"></i> กำลังสร้างรูป...';
        window.html2canvas(document.getElementById('abCapture'), { scale: 2, backgroundColor: '#eef6ff', useCORS: true })
            .then(function (c) {
                var a = document.createElement('a');
                a.download = 'SK-Flood-about.png';
                a.href = c.toDataURL('image/png');
                document.body.appendChild(a); a.click(); a.remove();
            })
            .catch(function () { alert('สร้างรูปไม่สำเร็จ'); })
            .then(function () { b.disabled = false; b.innerHTML = old; });
    });
})();
</script>
