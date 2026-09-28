<?php
$org = defined('HOSPITAL_NAME') && HOSPITAL_NAME ? HOSPITAL_NAME : 'โรงพยาบาล';   // ตั้ง define('HOSPITAL_NAME', '…') ใน config ได้
$state = $this->state;
?>
<div class="pub-page sf-page">
    <div class="pub-form-wrap">
        <div class="pub-form-head">
            <h1>🏥 บุคลากรโรงพยาบาล แจ้งผลกระทบน้ำท่วม / ขอความช่วยเหลือ</h1>
            <p>สำหรับบุคลากร<?= h($org) ?> · ข้อมูลภายใน เห็นเฉพาะทีมที่ดูแลบุคลากร</p>
        </div>

<?php if ($state === 'noperm') { ?>
        <div class="pub-section sf-gate">
            <h2>ไม่มีสิทธิ์เปิดหน้านี้</h2>
            <p class="pub-desc">หน้าตั้งค่าฟอร์มใช้ได้เฉพาะเจ้าหน้าที่ที่ดูแลข้อมูลบุคลากร</p>
            <a class="pub-btn pub-btn-outline" href="<?= URL ?>flood">กลับหน้าระบบเจ้าหน้าที่</a>
        </div>
    </div>
</div>
<?php return; } ?>

<?php if ($state === 'closed') { ?>
        <div class="pub-section sf-gate">
            <h2>⏸ ฟอร์มยังไม่เปิดรับ</h2>
            <p class="pub-desc">ติดต่อหัวหน้างาน หรือศูนย์ประสานของโรงพยาบาล เพื่อขอลิงก์และรหัสล่าสุด</p>
            <div class="pub-banner pub-banner-red" style="margin:0">
                ☎ เจ็บป่วยฉุกเฉิน โทร <a href="tel:<?= h(EMERGENCY_PHONE) ?>"><?= h(EMERGENCY_PHONE) ?></a>
                · แจ้งเหตุน้ำท่วม สายด่วน ปภ. <a href="tel:<?= h(flood_ddpm_phone()) ?>"><?= h(flood_ddpm_phone()) ?></a>
            </div>
        </div>
    </div>
</div>
<?php return; } ?>

<?php if ($state === 'gate') { ?>
        <form class="pub-section sf-gate" method="get" action="<?= URL ?>staffreport" autocomplete="off">
            <h2>🔑 ใส่รหัสของฟอร์ม</h2>
            <p class="pub-desc">รหัสอยู่ในข้อความที่ส่งในกลุ่มไลน์ของหน่วยงาน (ถ้าเปิดจากลิงก์ที่มีรหัสอยู่แล้ว จะเข้าได้ทันที)</p>
            <?php if ($this->error !== '') { ?><div class="pub-banner pub-banner-red"><?= h($this->error) ?></div><?php } ?>
            <input type="text" class="form-control sf-code" name="k" maxlength="12" inputmode="numeric" autocomplete="one-time-code"
                placeholder="เช่น 482913" required autofocus aria-label="รหัสของฟอร์ม" />
            <button type="submit" class="pub-btn pub-btn-blue" style="width:100%;margin-top:10px"><i class="fa fa-unlock"></i> เข้าสู่ฟอร์ม</button>
        </form>
    </div>
</div>
<?php return; } ?>

<?php
$victim = Staff_Form_Model::victimOptions();
$work = Staff_Form_Model::workOptions();
$shift = Staff_Form_Model::shiftOptions();
$help = Staff_Form_Model::helpOptions();
?>
        <div class="pub-banner pub-banner-blue">
            ใช้เวลาราว 1 นาที · ข้อมูลเข้ารายชื่อบุคลากรที่ได้รับผลกระทบ ทีมที่ดูแลจะโทรกลับตามเบอร์ที่ให้ไว้
            · แจ้งซ้ำได้เมื่อสถานการณ์เปลี่ยน (ระบบรวมเป็นรายชื่อเดียวกันด้วยเบอร์โทร)
        </div>
        <div class="pub-banner pub-banner-red">
            ☎ <b>เจ็บป่วยฉุกเฉิน</b> โทร <a href="tel:<?= h(EMERGENCY_PHONE) ?>"><?= h(EMERGENCY_PHONE) ?></a>
            · <b>ติดอยู่ในน้ำ / ต้องอพยพด่วน</b> สายด่วน ปภ. <a href="tel:<?= h(flood_ddpm_phone()) ?>"><?= h(flood_ddpm_phone()) ?></a>
            หรือ <a href="<?= URL ?>sos">ขอความช่วยเหลือเร่งด่วน (SOS)</a>
        </div>

        <form id="sfForm" novalidate autocomplete="on">
            <input type="hidden" name="_csrf" value="<?= h(flood_csrf_token()) ?>" />
            <input type="hidden" name="lat" id="fLat" />
            <input type="hidden" name="lng" id="fLng" />
            <input type="hidden" name="accuracy" id="fAcc" />
            <div style="position:absolute;left:-9999px;top:-9999px" aria-hidden="true">
                <label>เว็บไซต์ <input type="text" name="website" tabindex="-1" autocomplete="off" /></label>
            </div>

            <section class="pub-section" id="secWho">
                <h2><span class="num">1</span> ผู้แจ้ง <span class="req">*</span></h2>
                <div class="form-group">
                    <label for="fName" class="required">ชื่อ-สกุล</label>
                    <input type="text" class="form-control" id="fName" name="full_name" maxlength="150" autocomplete="name" />
                </div>
                <div class="form-group">
                    <label for="fPhone" class="required">เบอร์โทรที่ติดต่อได้</label>
                    <input type="tel" class="form-control" id="fPhone" name="phone" maxlength="20" inputmode="tel" autocomplete="tel" placeholder="08x-xxx-xxxx" />
                </div>
                <div class="row">
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label for="fDept" class="required">กลุ่มงาน / หน่วยงาน</label>
                            <input type="text" class="form-control" id="fDept" name="department" maxlength="150" placeholder="เช่น ER, หอผู้ป่วยอายุรกรรมชาย" />
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="form-group" style="margin-bottom:0">
                            <label for="fPos">ตำแหน่ง</label>
                            <input type="text" class="form-control" id="fPos" name="position" maxlength="150" autocomplete="organization-title" placeholder="เช่น พยาบาลวิชาชีพ" />
                        </div>
                    </div>
                </div>
            </section>

            <section class="pub-section" id="secImpact">
                <h2><span class="num">2</span> ผลกระทบตอนนี้ <span class="req">*</span></h2>
                <label>น้ำท่วมกระทบคุณอย่างไร</label>
                <div class="chip-grid cols-2">
                    <?php foreach ($victim as $code => $o) { ?>
                    <label class="chip">
                        <input type="radio" name="victim" value="<?= h($code) ?>" />
                        <span class="chip-body"><span class="chip-emoji"><?= $o[1] ?></span><span class="chip-title"><?= h($o[0]) ?></span></span>
                    </label>
                    <?php } ?>
                </div>
                <label style="margin-top:14px">การมาปฏิบัติงาน</label>
                <div class="chip-grid">
                    <?php foreach ($work as $code => $name) { ?>
                    <label class="chip">
                        <input type="radio" name="work" value="<?= h($code) ?>" />
                        <span class="chip-body"><span class="chip-title"><?= h($name) ?></span></span>
                    </label>
                    <?php } ?>
                </div>
                <label style="margin-top:14px">หลังลงเวร <span class="chip-hint" style="display:inline">(ไม่ได้ขึ้นเวร ข้ามได้)</span></label>
                <div class="chip-grid">
                    <?php foreach ($shift as $code => $o) { ?>
                    <label class="chip">
                        <input type="radio" name="shift" value="<?= h($code) ?>" />
                        <span class="chip-body"><span class="chip-title"><?= h($o[0]) ?></span></span>
                    </label>
                    <?php } ?>
                </div>
            </section>

            <section class="pub-section" id="secHelp">
                <h2><span class="num">3</span> ปัญหา / ความช่วยเหลือที่ต้องการ</h2>
                <p class="pub-desc">เลือกได้หลายข้อ · ไม่ต้องการความช่วยเหลือ ข้ามได้</p>
                <div class="chip-grid cols-2">
                    <?php foreach ($help as $code => $o) { ?>
                    <label class="chip">
                        <input type="checkbox" name="help[]" value="<?= h($code) ?>" />
                        <span class="chip-body"><span class="chip-emoji"><?= $o[1] ?></span><span class="chip-title"><?= h($o[0]) ?></span></span>
                    </label>
                    <?php } ?>
                </div>
                <div class="form-group" style="margin:12px 0 0">
                    <label for="fDetail">ปัญหาที่พบ / รายละเอียด</label>
                    <textarea class="form-control" id="fDetail" name="detail" rows="3" maxlength="2000"
                        placeholder="เช่น น้ำเข้าบ้านสูง 50 ซม. ลูกเล็ก 2 คนอยู่กับยาย · รถจักรยานยนต์จมน้ำ มาเวรเช้าไม่ได้"></textarea>
                </div>
            </section>

            <section class="pub-section" id="secWhere">
                <h2><span class="num">4</span> ตอนนี้พักอยู่ที่ไหน <span class="chip-hint" style="display:inline">(ไม่บังคับ)</span></h2>
                <div class="form-group">
                    <label for="fAddr">ที่อยู่ / หมู่บ้าน ตำบล อำเภอ</label>
                    <input type="text" class="form-control" id="fAddr" name="addr_now" maxlength="300" placeholder="เช่น บ้านคลองหมี ต.สระแก้ว อ.เมืองสระแก้ว" />
                </div>
                <button type="button" class="pub-btn pub-btn-outline" id="btnLocate" style="width:100%">
                    <i class="fa fa-crosshairs"></i> ใช้ตำแหน่งปัจจุบัน (หรือแตะบนแผนที่)
                </button>
                <div id="pickMap" class="pub-map-pick" style="margin-top:10px"></div>
                <div class="pub-loc-status" id="locStatus">ยังไม่ได้ระบุตำแหน่ง</div>
            </section>

            <section class="pub-section" id="secPhoto">
                <h2><span class="num">5</span> แนบรูป <span class="chip-hint" style="display:inline">(ไม่บังคับ สูงสุด 3 รูป)</span></h2>
                <p class="pub-desc">เช่น ระดับน้ำที่บ้าน ถนนที่ถูกตัดขาด ความเสียหาย</p>
                <div class="up-wrap">
                    <label class="up-pick">
                        <input type="file" id="fPhotos" accept="image/*" multiple />
                        <i class="fa fa-camera"></i> ถ่ายรูป / เลือกรูป <span class="up-count"></span>
                    </label>
                    <div class="up-preview" id="fPreview"></div>
                </div>
            </section>

            <p class="pub-desc" style="text-align:center">
                ข้อมูลใช้เพื่อดูแลและช่วยเหลือบุคลากรเท่านั้น · ไม่ถามเลขบัตรประชาชน · เก็บตามนโยบายคุ้มครองข้อมูลส่วนบุคคลของโรงพยาบาล
            </p>
        </form>
    </div>

    <div class="pub-submit-bar">
        <div class="inner">
            <button type="submit" form="sfForm" class="pub-btn pub-btn-blue" id="btnSubmit">
                <i class="fa fa-paper-plane"></i> ส่งข้อมูล
            </button>
        </div>
    </div>
</div>
