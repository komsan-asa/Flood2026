<?php
$needs = flood_help_needs();
$flags = flood_help_flags();
?>
<div class="pub-page">
    <div class="pub-form-wrap">
        <div class="pub-form-head">
            <h1>🆘 ขอความช่วยเหลือ</h1>
            <p>ช่วงน้ำท่วม — ส่งตรงถึงศูนย์ประสานของโรงพยาบาล เจ้าหน้าที่จะโทรกลับยืนยันทุกเรื่อง</p>
        </div>

        <div class="pub-banner pub-banner-red">
            ☎ <b>เจ็บป่วยฉุกเฉินวิกฤต</b> (หมดสติ / หายใจไม่ออก / เจ็บหน้าอก / เลือดออกมาก)
            โทร <a href="tel:<?= h(EMERGENCY_PHONE) ?>"><?= h(EMERGENCY_PHONE) ?></a> ทันที
            <br>☎ <b>แจ้งเหตุน้ำท่วม / สาธารณภัย</b> สายด่วน ปภ. โทร <a href="tel:<?= h(flood_ddpm_phone()) ?>"><?= h(flood_ddpm_phone()) ?></a> (ตลอด 24 ชม.)
        </div>

        <form id="sosForm" novalidate autocomplete="on">
            <input type="hidden" name="_csrf" value="<?= h(flood_csrf_token()) ?>" />
            <input type="hidden" name="lat" id="fLat" />
            <input type="hidden" name="lng" id="fLng" />
            <input type="hidden" name="accuracy" id="fAcc" />
            <div style="position:absolute;left:-9999px;top:-9999px" aria-hidden="true">
                <label>เว็บไซต์ <input type="text" name="website" tabindex="-1" autocomplete="off" /></label>
            </div>

            <section class="pub-section" id="secNeeds">
                <h2><span class="num">1</span> ต้องการความช่วยเหลือเรื่องอะไร <span class="req">*</span></h2>
                <p class="pub-desc">เลือกได้มากกว่า 1 ข้อ</p>
                <div class="chip-grid cols-2">
                    <?php foreach ($needs as $code => $o) { ?>
                    <label class="chip">
                        <input type="checkbox" name="needs[]" value="<?= h($code) ?>" />
                        <span class="chip-body"><span class="chip-emoji"><?= $o['emoji'] ?></span>
                            <span><span class="chip-title"><?= h($o['name']) ?></span>
                            <?php if ($o['hint'] !== '') { ?><span class="chip-hint"><?= h($o['hint']) ?></span><?php } ?></span>
                        </span>
                    </label>
                    <?php } ?>
                </div>
            </section>

            <section class="pub-section" id="secLocation">
                <h2><span class="num">2</span> ตอนนี้อยู่ที่ไหน <span class="req">*</span></h2>
                <p class="pub-desc">กดใช้ตำแหน่งปัจจุบัน หรือแตะบนแผนที่ — ถ้าระบุไม่ได้ ให้พิมพ์ที่อยู่/จุดสังเกตด้านล่างแทน</p>
                <button type="button" class="pub-btn pub-btn-blue" id="btnLocate" style="width:100%">
                    <i class="fa fa-crosshairs"></i> ใช้ตำแหน่งปัจจุบัน
                </button>
                <div id="pickMap" class="pub-map-pick" style="margin-top:10px"></div>
                <div class="pub-loc-status" id="locStatus">ยังไม่ได้ระบุตำแหน่ง</div>
                <div class="row" style="margin-top:12px">
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label for="fAmphoe">จังหวัด / อำเภอ</label>
                            <div class="pv-am-pair">
                                <?= flood_province_select($this->provinces, array('class' => 'form-control', 'data-pv-for' => 'fAmphoe', 'aria-label' => 'จังหวัด'), '', '— จังหวัด —') ?>
                                <select class="form-control" id="fAmphoe" name="amphoe_code">
                                    <option value="">— เลือกอำเภอ —</option>
                                    <?= flood_amphoe_options($this->amphoes, '') ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label for="fTambon">ตำบล</label>
                            <select class="form-control" id="fTambon" name="tambon_code">
                                <option value="">— เลือกตำบล —</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:0">
                    <label for="fAddress">บ้านเลขที่ / หมู่ / จุดสังเกต</label>
                    <textarea class="form-control" id="fAddress" name="address" rows="2" maxlength="255"
                        placeholder="เช่น 12 หมู่ 3 บ้านหนองบัว ตรงข้ามวัด…"></textarea>
                </div>
            </section>

            <section class="pub-section" id="secPeople">
                <h2><span class="num">3</span> ผู้ที่ต้องการความช่วยเหลือ</h2>
                <div class="form-group">
                    <label for="fPeople">จำนวนคน (โดยประมาณ)</label>
                    <input type="number" class="form-control" id="fPeople" name="people_count" min="1" max="999" inputmode="numeric" style="max-width:160px" />
                </div>
                <label>มีกลุ่มเปราะบางอยู่ด้วยไหม (เลือกได้หลายข้อ)</label>
                <div class="chip-grid cols-2">
                    <?php foreach ($flags as $code => $name) { ?>
                    <label class="chip">
                        <input type="checkbox" name="flags[]" value="<?= h($code) ?>" />
                        <span class="chip-body"><span class="chip-title"><?= h($name) ?></span></span>
                    </label>
                    <?php } ?>
                </div>
                <div class="form-group" style="margin:12px 0 0">
                    <label for="fDetail">รายละเอียดเพิ่มเติม</label>
                    <textarea class="form-control" id="fDetail" name="detail" rows="3" maxlength="2000"
                        placeholder="เช่น ยาที่หมด / อาการป่วย / ระดับน้ำในบ้าน / ต้องใช้เปลหาม"></textarea>
                </div>
            </section>

            <section class="pub-section" id="secContact">
                <h2><span class="num">4</span> ผู้แจ้ง <span class="req">*จำเป็นทั้งชื่อและเบอร์</span></h2>
                <p class="pub-desc">เจ้าหน้าที่จะโทรกลับยืนยันทุกเรื่องก่อนออกช่วยเหลือ · การแจ้งข้อมูลเท็จทำให้ทีมเสียเวลากับผู้เดือดร้อนจริง และมีความผิดตามกฎหมาย</p>
                <div class="form-group">
                    <label for="fName" class="required">ชื่อ-สกุล</label>
                    <input type="text" class="form-control" id="fName" name="requester_name" maxlength="150" autocomplete="name" />
                </div>
                <div class="form-group" style="margin-bottom:0">
                    <label for="fPhone" class="required">เบอร์โทรที่ติดต่อได้</label>
                    <input type="tel" class="form-control" id="fPhone" name="requester_phone" maxlength="20" inputmode="tel" autocomplete="tel" placeholder="08x-xxx-xxxx" />
                </div>
            </section>

            <section class="pub-section" id="secPhoto">
                <h2><span class="num">5</span> แนบรูป <span class="chip-hint" style="display:inline">(ไม่บังคับ สูงสุด 3 รูป)</span></h2>
                <p class="pub-desc">รูประดับน้ำหน้าบ้าน ทางเข้า หรือซองยาที่หมด ช่วยให้เจ้าหน้าที่ประเมินได้เร็วขึ้นมาก</p>
                <div class="up-wrap">
                    <label class="up-pick">
                        <input type="file" id="fPhotos" accept="image/*" multiple />
                        <i class="fa fa-camera"></i> ถ่ายรูป / เลือกรูป <span class="up-count"></span>
                    </label>
                    <div class="up-preview" id="fPreview"></div>
                </div>
            </section>

            <p class="pub-desc" style="text-align:center">
                ข้อมูลที่แจ้งจะใช้เพื่อการช่วยเหลือเท่านั้น และเก็บตามนโยบายคุ้มครองข้อมูลส่วนบุคคลของโรงพยาบาล<br>
                <a href="<?= URL ?>sos/status">ติดตามคำขอที่เคยส่งไว้</a>
            </p>
        </form>
    </div>

    <div class="pub-submit-bar">
        <div class="inner">
            <button type="submit" form="sosForm" class="pub-btn pub-btn-red" id="btnSubmit">
                <i class="fa fa-paper-plane"></i> ส่งเรื่องขอความช่วยเหลือ
            </button>
        </div>
    </div>
</div>
<script>
    window.SOS_TAMBONS = <?= flood_js($this->tambons) ?>;
</script>
