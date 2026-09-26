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
        <p class="ab-lead">ระบบแจ้งจุดน้ำท่วมและประสานการช่วยเหลือผู้ประสบภัย <?= h(flood_region_name()) ?><?= $n('provinces') > 1 ? ' ' . $n('provinces') . ' จังหวัด' : '' ?>
            ประชาชนแจ้งได้จากมือถือทันที เจ้าหน้าที่ตรวจสอบแล้วประกาศขึ้นแผนที่ พร้อมมอบหมายทีมลงพื้นที่
            และติดตามจนปิดงาน ทุกฝ่ายเห็นภาพเดียวกันแบบเรียลไทม์</p>
        <p class="ab-org"><i class="fa fa-hospital-o" aria-hidden="true"></i> <?= h(DEPARTMENT_NAME) ?></p>
        <div class="ab-hero-btns" data-html2canvas-ignore>
            <a href="<?= URL ?>" class="ab-btn ab-btn-primary"><i class="fa fa-map-o" aria-hidden="true"></i> เปิดแผนที่สถานการณ์</a>
            <button type="button" class="ab-btn ab-btn-ghost" id="abSaveImg" title="รูปขนาดกระดาษ A4 แนวตั้ง (300 dpi) พร้อมพิมพ์"><i class="fa fa-download" aria-hidden="true"></i> ดาวน์โหลดเป็นรูปภาพ A4</button>
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
        <div><b><?= $n('provinces') ?></b><span>จังหวัด</span></div>
        <div><b><?= $n('amphoes') ?></b><span>อำเภอ</span></div>
        <div><b><?= $n('tambons') ?></b><span>ตำบลในระบบ</span></div>
        <div><b><?= count($levels) ?></b><span>ระดับพื้นที่ประกาศ</span></div>
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
                    <li><b>แผนที่สถานการณ์น้ำ</b> เห็นพื้นที่ประกาศทั้งภูมิภาค กรองตามจังหวัด/อำเภอ/ระดับ</li>
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
            <li>ศูนย์ฯ เห็นภาพรวมทุกจังหวัดบนแผนที่เดียว ตัดสินใจส่งทีมได้เร็วขึ้น</li>
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

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js" integrity="sha384-ZZ1pncU3bQe8y31yfZdMFdSpttDoPmOZg2wguVK9almUodir1PghgT0eY7Mrty8H" crossorigin="anonymous" referrerpolicy="no-referrer" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js" integrity="sha384-mZT2gIty7ZDdOGkxfP6joZcYdMW1Jvj9dRlfpTmaJAKKXTqzygtB22k7FLe+KZC1" crossorigin="anonymous" referrerpolicy="no-referrer" defer></script>
<script>
/*
 * ดาวน์โหลดเป็นรูปภาพ A4 (แนวตั้ง 2480 × 3508 px = 300 dpi พร้อมพิมพ์)
 * คัดลอกเนื้อหาหน้านี้ไปจัดใหม่นอกจอ: หัว + ขั้นตอน + ตัวเลข เต็มแถว · หัวข้ออื่นแบ่ง 3 คอลัมน์ให้สูงใกล้กัน
 * แล้วหาความกว้างที่แคบที่สุด (ตัวหนังสือใหญ่ที่สุด) ที่ทั้งหมดยังพอดีสัดส่วน A4 · ท้ายกระดาษมีลิงก์ + QR เปิดแผนที่
 */
(function () {
    var btn = document.getElementById('abSaveImg');
    if (!btn) {
        return;
    }
    var A4 = 297 / 210;                 // สูง ÷ กว้าง
    var OUT_W = 2480;                   // A4 ที่ 300 dpi = 2480 × 3508 px
    var OUT_H = 3508;
    var W_MIN = 760;
    var W_MAX = 1500;
    var COLS = 3;                       // คอลัมน์ของหัวข้อด้านล่าง (balance() รองรับ 3)
    var GAP = 14;                       // ระยะห่างระหว่างหัวข้อในคอลัมน์ — ตรงกับ .ab-a4-col { gap }
    var BG = '#eef6ff';
    var SITE = <?= flood_js(URL) ?>;
    var ASOF = <?= flood_js(flood_thai_date(time())) ?>;
    var ORG = <?= flood_js(SHORT_NAME_SYSTEM . ' by SCPH · ' . DEPARTMENT_NAME) ?>;

    function say(msg) {
        if (window.Flood && Flood.toast) {
            Flood.toast(msg, 'danger');
        } else {
            window.alert(msg);
        }
    }

    function el(tag, cls, html) {
        var e = document.createElement(tag);
        if (cls) {
            e.className = cls;
        }
        if (html !== undefined) {
            e.innerHTML = html;
        }
        return e;
    }

    function escHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    /** สร้างหน้า A4 นอกจอจากเนื้อหาจริงของหน้านี้ */
    function build() {
        var src = document.getElementById('abCapture');
        var host = el('div', 'ab-a4-host');
        host.setAttribute('aria-hidden', 'true');
        var page = el('div', 'ab-a4');
        var body = el('div', 'ab-a4-body');
        var clone = src.cloneNode(true);
        [].slice.call(clone.querySelectorAll('[data-html2canvas-ignore], script')).forEach(function (n) {
            n.parentNode.removeChild(n);
        });
        while (clone.firstChild) {
            body.appendChild(clone.firstChild);
        }
        // หัวข้อที่เป็นการ์ด (ยกเว้นขั้นตอนการทำงาน) ไปอยู่ในคอลัมน์ · หัวข้อที่มีหลายการ์ดย่อย (จุดเด่นฯ)
        // แยกการ์ดที่ 2 เป็นต้นไปเป็นชิ้นต่อเนื่อง ให้กระจายไปคอลัมน์อื่นได้
        var cols = el('div', 'ab-a4-cols');
        var colList = [];
        for (var c = 0; c < COLS; c++) {
            colList.push(cols.appendChild(el('div', 'ab-a4-col')));
        }
        var secs = [];
        [].slice.call(body.children).forEach(function (n) {
            if (!n.classList || !n.classList.contains('ab-sec') || n.querySelector('.ab-steps')) {
                return;
            }
            secs.push(n);
            var cards = [].slice.call(n.querySelectorAll('.ab-grid2 > .ab-card'));
            cards.slice(1).forEach(function (card) {
                var more = el('section', 'ab-sec ab-a4-cont');
                more.appendChild(card);
                secs.push(more);
            });
        });
        if (secs.length) {
            body.insertBefore(cols, secs[0]);   // ตำแหน่งเดิมของหัวข้อแรก (ต่อจากแถบตัวเลข)
            secs.forEach(function (n) { colList[0].appendChild(n); });
        }
        var close = body.querySelector('.ab-close');
        if (close) {
            body.appendChild(close);   // ปิดท้ายเต็มแถวต่อจากคอลัมน์
        }
        var shortUrl = SITE.replace(/^https?:\/\//, '').replace(/\/$/, '');
        var foot = el('div', 'ab-a4-foot',
            '<div class="ab-a4-foot-l"><b>' + escHtml(ORG) + '</b>'
            + '<span class="ab-a4-url">เปิดแผนที่สถานการณ์น้ำ <b>' + escHtml(shortUrl) + '</b></span>'
            + '<small>ข้อมูล ณ ' + escHtml(ASOF) + ' · Developed by Komsan Asa</small></div>'
            + (window.qrcode ? '<div class="ab-a4-qr"><small><b>สแกน QR</b>เปิดแผนที่</small><span class="ab-a4-qr-box"></span></div>' : ''));
        page.appendChild(body);
        page.appendChild(foot);
        host.appendChild(page);
        document.body.appendChild(host);
        return { host: host, page: page, cols: colList, secs: secs };
    }

    /** แบ่งหัวข้อลงคอลัมน์ตามลำดับเดิม (อ่านบนลงล่าง ซ้ายไปขวา) ให้คอลัมน์ที่สูงที่สุดเตี้ยที่สุด */
    function balance(a) {
        a.secs.forEach(function (n) { a.cols[0].appendChild(n); });
        var hs = a.secs.map(function (n) { return n.offsetHeight + GAP; });
        var n = hs.length;
        var sum = function (from, to) {
            var t = 0;
            for (var i = from; i < to; i++) {
                t += hs[i];
            }
            return t;
        };
        var best = [n, n];
        var bestMax = Infinity;
        for (var i = 0; i <= n; i++) {
            for (var j = i; j <= n; j++) {
                var m = Math.max(sum(0, i), sum(i, j), sum(j, n));
                if (m < bestMax - 0.5) {
                    bestMax = m;
                    best = [i, j];
                }
            }
        }
        a.secs.forEach(function (sec, k) {
            a.cols[k < best[0] ? 0 : (k < best[1] ? 1 : 2)].appendChild(sec);
        });
    }

    function heightAt(a, w) {
        a.page.style.width = w + 'px';
        a.page.style.minHeight = '';
        balance(a);
        return a.page.offsetHeight;
    }

    /** ความกว้างที่แคบที่สุดที่เนื้อหาทั้งหมดยังพอดีสัดส่วน A4 */
    function fit(a) {
        var lo = W_MIN;
        var hi = W_MAX;
        if (heightAt(a, lo) <= lo * A4) {
            return lo;
        }
        if (heightAt(a, hi) > hi * A4) {
            return hi;   // ยาวเกิน — ย่อทั้งภาพลงให้พอดีตอนวาด
        }
        while (hi - lo > 4) {
            var mid = Math.round((lo + hi) / 2);
            if (heightAt(a, mid) <= mid * A4) {
                hi = mid;
            } else {
                lo = mid;
            }
        }
        return hi;
    }

    /** QR ลิงก์แผนที่ — วาดลงรูปโดยตรง (คมชัดทุกขนาด) */
    function drawQr(ctx, x, y, size) {
        var qr = window.qrcode(0, 'M');
        qr.addData(SITE);
        qr.make();
        var n = qr.getModuleCount();
        var cell = Math.floor(size / (n + 2));
        if (cell < 1) {
            return;
        }
        var full = cell * (n + 2);
        var ox = Math.round(x + (size - full) / 2);
        var oy = Math.round(y + (size - full) / 2);
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(ox, oy, full, full);
        ctx.fillStyle = '#16324f';
        for (var r = 0; r < n; r++) {
            for (var c = 0; c < n; c++) {
                if (qr.isDark(r, c)) {
                    ctx.fillRect(ox + (c + 1) * cell, oy + (r + 1) * cell, cell, cell);
                }
            }
        }
    }

    function save(canvas) {
        var name = 'SK-Flood-A4.png';
        var go = function (href, revoke) {
            var a = document.createElement('a');
            a.download = name;
            a.href = href;
            document.body.appendChild(a);
            a.click();
            a.parentNode.removeChild(a);
            if (revoke) {
                setTimeout(function () { URL.revokeObjectURL(href); }, 5000);
            }
        };
        if (canvas.toBlob && window.URL && URL.createObjectURL) {
            canvas.toBlob(function (blob) {
                if (blob) {
                    go(URL.createObjectURL(blob), true);
                } else {
                    go(canvas.toDataURL('image/png'), false);
                }
            }, 'image/png');
        } else {
            go(canvas.toDataURL('image/png'), false);
        }
    }

    btn.addEventListener('click', function () {
        if (!window.html2canvas) {
            say('กำลังโหลดเครื่องมือสร้างรูป กรุณาลองอีกครั้งในอีกสักครู่');
            return;
        }
        var old = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fa fa-spinner fa-spin" aria-hidden="true"></i> กำลังจัดหน้า A4...';
        var a = null;
        var done = function () {
            if (a && a.host.parentNode) {
                a.host.parentNode.removeChild(a.host);
            }
            btn.disabled = false;
            btn.innerHTML = old;
        };
        var ready = document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve();
        ready.then(function () {
            a = build();
            var w = fit(a);
            heightAt(a, w);
            a.page.style.minHeight = Math.ceil(w * A4) + 'px';   // เต็มหน้า A4 — แถบท้ายชิดขอบล่าง
            var scale = OUT_W / w;
            var pr = a.page.getBoundingClientRect();
            var qEl = a.page.querySelector('.ab-a4-qr-box');
            var qr = qEl ? qEl.getBoundingClientRect() : null;
            return window.html2canvas(a.page, {
                scale: scale, backgroundColor: BG, useCORS: true, logging: false,
                windowWidth: Math.max(1280, w + 80)
            }).then(function (c) {
                var out = document.createElement('canvas');
                out.width = OUT_W;
                out.height = OUT_H;
                var ctx = out.getContext('2d');
                ctx.fillStyle = BG;
                ctx.fillRect(0, 0, OUT_W, OUT_H);
                var s = Math.min(OUT_W / c.width, OUT_H / c.height);
                var dx = (OUT_W - c.width * s) / 2;
                ctx.drawImage(c, dx, 0, c.width * s, c.height * s);
                if (qr && window.qrcode) {
                    try {
                        drawQr(ctx, dx + (qr.left - pr.left) * scale * s, (qr.top - pr.top) * scale * s, qr.width * scale * s);
                    } catch (e) { /* ไม่มี QR ก็ยังมีลิงก์เป็นตัวหนังสือ */ }
                }
                save(out);
            });
        }).catch(function () {
            say('สร้างรูปไม่สำเร็จ กรุณาลองใหม่');
        }).then(done, done);
    });
})();
</script>
