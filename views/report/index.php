<?php
/**
 * ประชาชนแจ้งจุดน้ำท่วม — ฟอร์มแบบทีละขั้น (ถามทีละเรื่อง ตอบด้วยการแตะ)
 * แนวคิดจากระบบรายงานน้ำท่วมต่างประเทศ: ปักหมุดกลางจอแบบแอปเรียกรถ, มาตรวัดความสูงน้ำเทียบร่างกาย (PetaBencana.id),
 * หนึ่งคำถามต่อหน้า (GOV.UK Design System) — ช่องที่ส่งไป report/save เหมือนเดิมทุกช่อง
 */
$depth = flood_depth_options();
$extent = flood_extent_options();
$houses = flood_houses_options();
$vehicle = flood_vehicle_options();
$trend = flood_trend_options();
$impacts = flood_area_impacts();
// ระดับบนมาตรวัด (% ของความสูงคน) — เรียงจากสูงลงต่ำให้ตรงกับภาพ
$gauge = array('above_waist' => 74, 'waist' => 52, 'knee' => 30, 'ankle' => 9);
$steps = array(1 => 'จุดที่น้ำท่วม', 'น้ำสูงแค่ไหน', 'น้ำท่วมกว้างแค่ไหน', 'สภาพในพื้นที่', 'รูปและจุดสังเกต', 'ผู้แจ้งและส่ง');
?>
<div class="sk-wz" id="wz">
    <header class="sk-wz-top">
        <div class="sk-wz-bar">
            <a href="<?= URL ?>" class="sk-wz-close" aria-label="ปิด กลับหน้าแผนที่"><i class="fa fa-times" aria-hidden="true"></i></a>
            <div class="sk-wz-progress" aria-hidden="true">
                <?php foreach ($steps as $n => $t) { ?><span data-seg="<?= $n ?>"></span><?php } ?>
            </div>
            <a href="<?= URL ?>sos" class="sk-wz-sos">🆘 <span>ขอความช่วยเหลือ</span></a>
        </div>
        <p class="sk-wz-count" id="wzCount" aria-live="polite">ขั้นที่ 1 จาก <?= count($steps) ?></p>
    </header>

    <form id="reportForm" class="sk-wz-form" novalidate autocomplete="on">
        <input type="hidden" name="_csrf" value="<?= h(flood_csrf_token()) ?>" />
        <input type="hidden" name="lat" id="fLat" />
        <input type="hidden" name="lng" id="fLng" />
        <input type="hidden" name="accuracy" id="fAcc" />
        <input type="hidden" name="loc_method" id="fMethod" value="pin" />
        <div style="position:absolute;left:-9999px;top:-9999px" aria-hidden="true">
            <label>เว็บไซต์ <input type="text" name="website" tabindex="-1" autocomplete="off" /></label>
        </div>

        <!-- 1 ตำแหน่ง -->
        <section class="sk-step sk-step-map" data-step="1" id="secLocation">
            <div class="sk-step-head">
                <h1 tabindex="-1">น้ำท่วมอยู่ตรงไหน</h1>
                <p>เลื่อนแผนที่ให้หมุดตรงจุดที่เห็นน้ำด้วยตาตัวเอง</p>
            </div>
            <div class="sk-paste" id="pasteBox">
                <i class="fa fa-link" aria-hidden="true"></i>
                <label for="fMapLink" class="sr-only">ลิงก์ Google Maps หรือพิกัด</label>
                <input type="text" id="fMapLink" class="sk-paste-input" autocomplete="off" spellcheck="false" enterkeyhint="go"
                    placeholder="มีลิงก์ Google Maps? วางที่นี่ หรือพิมพ์พิกัด 13.9194, 102.0333" />
                <button type="button" class="sk-btn sk-btn-soft" id="btnMapLink">ไปที่จุดนี้</button>
            </div>
            <div class="sk-pick">
                <div id="pickMap" class="sk-map sk-pick-map"></div>
                <div class="sk-pick-pin" aria-hidden="true">
                    <svg viewBox="0 0 40 52"><path d="M20 51s17-17.3 17-30A17 17 0 0 0 3 21c0 12.7 17 30 17 30z" fill="#e5485a"/><circle cx="20" cy="20" r="7" fill="#fff"/></svg>
                    <i></i>
                </div>
                <button type="button" class="sk-btn sk-btn-ghost sk-pick-gps" id="btnLocate">
                    <i class="fa fa-crosshairs" aria-hidden="true"></i> ใช้ตำแหน่งของฉัน
                </button>
                <div class="sk-pick-status" id="locStatus" role="status">ซูมเข้าไปที่จุดน้ำท่วม หรือกด “ใช้ตำแหน่งของฉัน”</div>
            </div>
        </section>

        <!-- 2 ความสูงน้ำ -->
        <section class="sk-step" data-step="2" id="secDepth" hidden>
            <div class="sk-step-head">
                <h1 tabindex="-1">ตอนนี้น้ำสูงแค่ไหน</h1>
                <p>เทียบกับร่างกายคนที่ยืนอยู่ในน้ำ</p>
            </div>
            <div class="sk-depth">
                <div class="sk-gauge" id="gauge" aria-hidden="true">
                    <svg class="sk-gauge-person" viewBox="0 0 120 240">
                        <circle cx="60" cy="26" r="17"/>
                        <rect x="37" y="48" width="46" height="80" rx="20"/>
                        <rect x="21" y="54" width="13" height="70" rx="6.5"/>
                        <rect x="86" y="54" width="13" height="70" rx="6.5"/>
                        <rect x="41" y="112" width="17" height="120" rx="8.5"/>
                        <rect x="62" y="112" width="17" height="120" rx="8.5"/>
                    </svg>
                    <div class="sk-water" id="gaugeWater"><i></i></div>
                    <?php foreach ($gauge as $code => $pct) { ?>
                    <span class="sk-gauge-mark" data-mark="<?= h($code) ?>" style="bottom:<?= $pct ?>%"><?= h($depth[$code]['hint']) ?></span>
                    <?php } ?>
                </div>
                <div class="sk-opts" role="radiogroup" aria-label="ความสูงของน้ำ">
                    <?php foreach ($gauge as $code => $pct) { $o = $depth[$code]; ?>
                    <label class="sk-opt" data-h="<?= $pct ?>">
                        <input type="radio" name="depth" value="<?= h($code) ?>" data-auto="1" />
                        <span class="sk-opt-body">
                            <span class="sk-opt-emoji" aria-hidden="true"><?= $o['emoji'] ?></span>
                            <span class="sk-opt-txt"><b><?= h($o['name']) ?></b><small><?= h($o['hint']) ?></small></span>
                            <span class="sk-opt-check" aria-hidden="true"><i class="fa fa-check"></i></span>
                        </span>
                    </label>
                    <?php } ?>
                </div>
            </div>
        </section>

        <!-- 3 ความกว้าง -->
        <section class="sk-step" data-step="3" id="secExtent" hidden>
            <div class="sk-step-head">
                <h1 tabindex="-1">น้ำท่วมกว้างแค่ไหน</h1>
                <p>ไม่ต้องเดาเป็นเมตร เลือกตามที่ตาเห็น</p>
            </div>
            <div class="sk-cards" role="radiogroup" aria-label="ความกว้างของน้ำ">
                <?php foreach ($extent as $code => $o) { ?>
                <label class="sk-card">
                    <input type="radio" name="extent" value="<?= h($code) ?>" />
                    <span class="sk-card-body">
                        <span class="sk-card-emoji" aria-hidden="true"><?= $o['emoji'] ?></span>
                        <b><?= h($o['name']) ?></b>
                        <small><?= h($o['hint']) ?></small>
                    </span>
                </label>
                <?php } ?>
            </div>
            <p class="sk-q">มีบ้านที่น้ำเข้าแล้วกี่หลัง <span>(ถ้าทราบ)</span></p>
            <div class="sk-seg" role="radiogroup" aria-label="จำนวนบ้านที่น้ำเข้า">
                <?php foreach ($houses as $code => $name) { ?>
                <label><input type="radio" name="houses" value="<?= h($code) ?>" /><span><?= h($name) ?></span></label>
                <?php } ?>
            </div>
        </section>

        <!-- 4 การเดินทาง -->
        <section class="sk-step" data-step="4" id="secVehicle" hidden>
            <div class="sk-step-head">
                <h1 tabindex="-1">สภาพในพื้นที่ตอนนี้</h1>
                <p>ช่วยทีมกู้ภัยเลือกพาหนะและเตรียมของช่วยเหลือ · ไม่ทราบกด “ข้าม” ได้</p>
            </div>
            <p class="sk-q" style="margin-top:0">ในพื้นที่นี้มีปัญหาอะไรบ้าง <span>(เลือกได้หลายข้อ)</span></p>
            <div class="sk-seg sk-seg-multi" role="group" aria-label="ผลกระทบในพื้นที่">
                <?php foreach ($impacts as $code => $o) { ?>
                <label><input type="checkbox" name="impacts[]" value="<?= h($code) ?>" /><span><em aria-hidden="true"><?= $o['emoji'] ?></em><?= h($o['name']) ?></span></label>
                <?php } ?>
            </div>
            <p class="sk-hint-sos">บ้านของท่านเองต้องการความช่วยเหลือ? <a href="<?= URL ?>sos">ส่งคำขอความช่วยเหลือแยก →</a></p>
            <p class="sk-q">รถผ่านได้ไหม</p>
            <div class="sk-cards sk-cards-sm" role="radiogroup" aria-label="รถผ่านได้แค่ไหน">
                <?php foreach ($vehicle as $code => $o) { ?>
                <label class="sk-card">
                    <input type="radio" name="vehicle" value="<?= h($code) ?>" />
                    <span class="sk-card-body"><span class="sk-card-emoji" aria-hidden="true"><?= $o['emoji'] ?></span><b><?= h($o['name']) ?></b></span>
                </label>
                <?php } ?>
            </div>
            <p class="sk-q">น้ำกำลังขึ้นหรือลด</p>
            <div class="sk-seg sk-seg-3" role="radiogroup" aria-label="แนวโน้มระดับน้ำ">
                <?php foreach ($trend as $code => $o) { ?>
                <label><input type="radio" name="trend" value="<?= h($code) ?>" /><span><em aria-hidden="true"><?= $o['emoji'] ?></em><?= h($o['name']) ?></span></label>
                <?php } ?>
            </div>
        </section>

        <!-- 5 รูป -->
        <section class="sk-step" data-step="5" id="secPhoto" hidden>
            <div class="sk-step-head">
                <h1 tabindex="-1">ถ่ายรูปให้ดูหน่อย</h1>
                <p>รูปช่วยให้เจ้าหน้าที่ยืนยันได้เร็วขึ้นมาก · ไม่บังคับ</p>
            </div>
            <div class="up-wrap">
                <label class="sk-drop up-pick">
                    <input type="file" id="fPhotos" accept="image/*" multiple />
                    <span class="sk-drop-ic" aria-hidden="true"><i class="fa fa-camera"></i></span>
                    <b>ถ่ายรูป / เลือกรูป</b>
                    <small>สูงสุด 3 รูป · ระบบย่อรูปให้เอง <span class="up-count"></span></small>
                </label>
                <div class="up-preview" id="fPreview"></div>
            </div>
            <label class="sk-q" for="fNote">จุดสังเกต / รายละเอียดเพิ่มเติม</label>
            <textarea class="sk-input" id="fNote" name="place_note" maxlength="255" rows="3" placeholder="เช่น หน้าวัด… ซอย… ถนนขาดตรงสะพาน…"></textarea>
        </section>

        <!-- 6 ผู้แจ้ง + สรุป -->
        <section class="sk-step" data-step="6" id="secReporter" hidden>
            <div class="sk-step-head">
                <h1 tabindex="-1">ใกล้เสร็จแล้ว</h1>
                <p>เจ้าหน้าที่อาจโทรกลับเพื่อยืนยันก่อนประกาศพื้นที่ · ชื่อและเบอร์ไม่ถูกเผยแพร่</p>
            </div>
            <div class="sk-field">
                <label for="fName">ชื่อ-สกุล</label>
                <input type="text" class="sk-input" id="fName" name="reporter_name" maxlength="150" autocomplete="name" />
            </div>
            <div class="sk-field">
                <label for="fPhone">เบอร์โทรที่ติดต่อได้</label>
                <input type="tel" class="sk-input" id="fPhone" name="reporter_phone" maxlength="20" inputmode="tel" autocomplete="tel" placeholder="08x-xxx-xxxx" />
            </div>
            <p class="sk-q">ตรวจสอบก่อนส่ง</p>
            <dl class="sk-summary" id="wzSummary"></dl>
            <p class="sk-warn"><i class="fa fa-info-circle" aria-hidden="true"></i> การแจ้งข้อมูลเท็จทำให้ทีมช่วยเหลือเสียเวลากับผู้เดือดร้อนจริง</p>
        </section>
    </form>

    <footer class="sk-wz-foot">
        <div class="sk-wz-foot-in">
            <button type="button" class="sk-btn sk-btn-ghost" id="btnBack"><i class="fa fa-arrow-left" aria-hidden="true"></i> <span>ย้อนกลับ</span></button>
            <button type="button" class="sk-btn sk-btn-primary" id="btnNext"><span>ยืนยันจุดนี้</span> <i class="fa fa-arrow-right" aria-hidden="true"></i></button>
            <button type="submit" form="reportForm" class="sk-btn sk-btn-primary" id="btnSubmit" hidden><i class="fa fa-paper-plane" aria-hidden="true"></i> ส่งรายงาน</button>
        </div>
    </footer>
</div>
<script>
    window.REPORT_STEPS = <?= flood_js(array_values($steps)) ?>;
    window.REPORT_LABELS = <?= flood_js(array(
        'depth' => array_map(function ($o) { return $o['name'] . ' (' . $o['hint'] . ')'; }, $depth),
        'extent' => array_map(function ($o) { return $o['name']; }, $extent),
        'houses' => $houses,
        'vehicle' => array_map(function ($o) { return $o['name']; }, $vehicle),
        'trend' => array_map(function ($o) { return $o['name']; }, $trend),
        'impacts' => array_map(function ($o) { return $o['name']; }, $impacts),
    )) ?>;
</script>
