<?php
$needs = flood_help_needs();
$flags = flood_help_flags();
?>
<div class="flood-page-header">
    <h2 class="flood-page-title"><i class="fa fa-phone"></i> รับเรื่องขอความช่วยเหลือ (ทางโทรศัพท์)</h2>
    <a href="<?= URL ?>flood/help" class="btn btn-default btn-sm js-back"><i class="fa fa-arrow-left"></i> กลับ</a>
</div>
<div class="alert alert-info">เรื่องที่เจ้าหน้าที่บันทึกเองจะถือว่า "โทรยืนยันแล้ว" — มอบหมายทีมต่อได้ทันทีหลังบันทึก</div>

<form id="helpForm" autocomplete="off">
    <input type="hidden" name="lat" id="fLat" />
    <input type="hidden" name="lng" id="fLng" />
    <input type="hidden" name="accuracy" id="fAcc" />
    <div class="row">
        <div class="col-md-6">
            <div class="flood-card">
                <div class="flood-card-header">ผู้แจ้ง / สิ่งที่ต้องการ</div>
                <div class="flood-card-body">
                    <div class="row">
                        <div class="col-sm-6">
                            <div class="form-group">
                                <label for="fName" class="required">ชื่อผู้แจ้ง</label>
                                <input type="text" class="form-control" id="fName" name="requester_name" maxlength="150" />
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="form-group">
                                <label for="fPhone" class="required">เบอร์โทร</label>
                                <input type="tel" class="form-control" id="fPhone" name="requester_phone" maxlength="20" />
                                <div class="help-block" id="phoneHint"></div>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="required">ต้องการความช่วยเหลือเรื่อง</label>
                        <div class="chip-grid cols-2">
                            <?php foreach ($needs as $code => $o) { ?>
                            <label class="chip"><input type="checkbox" name="needs[]" value="<?= h($code) ?>" />
                                <span class="chip-body"><span class="chip-emoji"><?= $o['emoji'] ?></span><span class="chip-title"><?= h($o['name']) ?></span></span>
                            </label>
                            <?php } ?>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-sm-6">
                            <div class="form-group">
                                <label for="fPeople">จำนวนคน</label>
                                <input type="number" class="form-control" id="fPeople" name="people_count" min="1" max="999" />
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="form-group">
                                <label for="fPriority">ความเร่งด่วน</label>
                                <select class="form-control" id="fPriority" name="priority">
                                    <?= flood_options(flood_priorities(), '', 'ให้ระบบประเมินจากข้อมูล') ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>กลุ่มเปราะบางในบ้าน</label>
                        <div class="flex flex-wrap gap-3">
                            <?php foreach ($flags as $code => $name) { ?>
                            <label class="checkbox-inline"><input type="checkbox" name="flags[]" value="<?= h($code) ?>" /> <?= h($name) ?></label>
                            <?php } ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="fDetail">รายละเอียด</label>
                        <textarea class="form-control" id="fDetail" name="detail" rows="4" maxlength="2000"
                            placeholder="อาการ / ยาที่ต้องการ / ระดับน้ำ / เส้นทางเข้าออก"></textarea>
                    </div>
                    <div class="form-group" style="margin-bottom:0">
                        <label>รูปถ่าย (ถ้ามี)</label>
                        <div class="up-wrap">
                            <label class="btn btn-default"><input type="file" id="fPhotos" accept="image/*" multiple style="display:none" />
                                <i class="fa fa-camera"></i> เลือกรูป <span class="up-count"></span></label>
                            <div class="photo-grid up-preview" id="fPreview"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="flood-card">
                <div class="flood-card-header">ตำแหน่ง</div>
                <div class="flood-card-body">
                    <div class="row">
                        <div class="col-sm-6">
                            <div class="form-group">
                                <label for="fAmphoe">จังหวัด / อำเภอ</label>
                                <div class="pv-am-pair">
                                    <?= flood_province_select($this->provinces, array('class' => 'form-control', 'data-pv-for' => 'fAmphoe', 'aria-label' => 'จังหวัด'), '', '— จังหวัด —') ?>
                                    <select class="form-control" id="fAmphoe" name="amphoe_code">
                                        <option value="">—</option>
                                        <?= flood_amphoe_options($this->amphoes, '') ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="form-group">
                                <label for="fTambon">ตำบล</label>
                                <select class="form-control" id="fTambon" name="tambon_code"><option value="">—</option></select>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="fAddress">ที่อยู่ / จุดสังเกต</label>
                        <textarea class="form-control" id="fAddress" name="address" rows="2" maxlength="255"></textarea>
                    </div>
                    <p class="small-muted" style="margin:0 0 6px">แตะแผนที่เพื่อปักหมุด (ถ้าผู้แจ้งบอกตำแหน่งได้) — ไม่บังคับถ้ามีที่อยู่</p>
                    <div id="pickMap" class="flood-map map-sm"></div>
                    <div class="small-muted" id="locStatus" style="margin-top:4px">ยังไม่ได้ปักหมุด</div>
                    <button type="button" class="btn btn-default btn-sm" id="btnClearPin" style="margin-top:6px"><i class="fa fa-times"></i> ล้างหมุด</button>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-lg btn-block" id="btnSave"><i class="fa fa-save"></i> บันทึกใบงาน</button>
        </div>
    </div>
</form>

<script>
    window.HF_TAMBONS = <?= flood_js($this->tambons) ?>;
</script>
