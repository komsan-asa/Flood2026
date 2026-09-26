<?php
$p = $this->person;
$groups = flood_vulnerable_groups();
$mob = flood_mobility_options();
$evac = flood_evac_statuses();
$v = function ($k) use ($p) {
    return $p && isset($p[$k]) && $p[$k] !== null ? $p[$k] : '';
};
$selGroups = $p ? flood_codes_filter($p['vuln_groups'], $groups) : array();
?>
<div class="flood-page-header">
    <h2 class="flood-page-title"><i class="fa fa-wheelchair"></i> <?= $p ? 'แก้ไขข้อมูล ' . h($p['name']) : 'เพิ่มในทะเบียนกลุ่มเปราะบาง' ?></h2>
    <a href="<?= URL ?>flood/vulnerable" class="btn btn-default btn-sm js-back"><i class="fa fa-arrow-left"></i> กลับ</a>
</div>

<?php if ($p && $p['zone']) { ?>
<div class="alert alert-warning"><i class="fa fa-exclamation-triangle"></i> บ้านของบุคคลนี้อยู่ในพื้นที่ประกาศ <b><?= h($p['zone']['name']) ?></b> <?= flood_level_badge($p['zone']['level']) ?>
    · สถานะตอนนี้: <?= flood_status_label($evac, $p['evac_status']) ?></div>
<?php } ?>

<form id="vForm" autocomplete="off">
    <input type="hidden" name="person_id" value="<?= $p ? (int) $p['person_id'] : 0 ?>" />
    <input type="hidden" name="lat" id="vLat" value="<?= h($v('lat')) ?>" />
    <input type="hidden" name="lng" id="vLng" value="<?= h($v('lng')) ?>" />
    <div class="row">
        <div class="col-md-6">
            <div class="flood-card">
                <div class="flood-card-header">ข้อมูลบุคคล</div>
                <div class="flood-card-body">
                    <div class="row">
                        <div class="col-sm-8">
                            <div class="form-group">
                                <label for="vName" class="required">ชื่อ-สกุล</label>
                                <input type="text" class="form-control" id="vName" name="name" maxlength="150" value="<?= h($v('name')) ?>" />
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="form-group">
                                <label for="vHn">HN</label>
                                <input type="text" class="form-control" id="vHn" name="hn" maxlength="20" value="<?= h($v('hn')) ?>" />
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-sm-6">
                            <div class="form-group">
                                <label for="vBirth">วันเกิด (ค.ศ.)</label>
                                <input type="date" class="form-control" id="vBirth" name="birth_date" value="<?= h($v('birth_date')) ?>" max="<?= date('Y-m-d') ?>" />
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="form-group">
                                <label>เพศ</label>
                                <div>
                                    <label class="radio-inline"><input type="radio" name="sex" value="M"<?= $v('sex') === 'M' ? ' checked' : '' ?> /> ชาย</label>
                                    <label class="radio-inline"><input type="radio" name="sex" value="F"<?= $v('sex') === 'F' ? ' checked' : '' ?> /> หญิง</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="required">กลุ่มเปราะบาง</label>
                        <div class="flex flex-wrap gap-3">
                            <?php foreach ($groups as $code => $name) { ?>
                            <label class="checkbox-inline"><input type="checkbox" name="vuln_groups[]" value="<?= h($code) ?>"<?= in_array($code, $selGroups, true) ? ' checked' : '' ?> /> <?= h($name) ?></label>
                            <?php } ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="vMob">การเคลื่อนย้าย</label>
                        <select class="form-control" id="vMob" name="mobility"><?= flood_options($mob, $v('mobility'), '—') ?></select>
                    </div>
                    <div class="form-group">
                        <label for="vNeeds">ความต้องการทางการแพทย์</label>
                        <input type="text" class="form-control" id="vNeeds" name="medical_needs" maxlength="255" value="<?= h($v('medical_needs')) ?>"
                            placeholder="เช่น ใช้ออกซิเจน 24 ชม. / ฟอกไตทุกอังคาร-ศุกร์ / ยาอินซูลิน" />
                    </div>
                    <div class="row">
                        <div class="col-sm-6">
                            <div class="form-group">
                                <label for="vPhone">เบอร์โทร</label>
                                <input type="tel" class="form-control" id="vPhone" name="phone" maxlength="20" value="<?= h($v('phone')) ?>" />
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="form-group">
                                <label for="vCgName">ผู้ดูแล</label>
                                <input type="text" class="form-control" id="vCgName" name="caregiver_name" maxlength="150" value="<?= h($v('caregiver_name')) ?>" />
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="vCgPhone">เบอร์ผู้ดูแล</label>
                        <input type="tel" class="form-control" id="vCgPhone" name="caregiver_phone" maxlength="20" value="<?= h($v('caregiver_phone')) ?>" />
                    </div>
                    <div class="form-group" style="margin-bottom:0">
                        <label for="vNote">หมายเหตุ</label>
                        <textarea class="form-control" id="vNote" name="note" rows="2" maxlength="2000"><?= h($v('note')) ?></textarea>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="flood-card">
                <div class="flood-card-header">ที่อยู่ / ตำแหน่งบ้าน</div>
                <div class="flood-card-body">
                    <div class="row">
                        <div class="col-sm-8">
                            <div class="form-group">
                                <label for="vAddr">บ้านเลขที่ / ถนน / หมู่บ้าน</label>
                                <input type="text" class="form-control" id="vAddr" name="address" maxlength="255" value="<?= h($v('address')) ?>" />
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="form-group">
                                <label for="vMoo">หมู่ที่</label>
                                <input type="text" class="form-control" id="vMoo" name="moo" maxlength="10" value="<?= h($v('moo')) ?>" />
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-sm-6">
                            <div class="form-group">
                                <label for="vAmphoe">จังหวัด / อำเภอ</label>
                                <div class="pv-am-pair">
                                    <?= flood_province_select($this->provinces, array('class' => 'form-control', 'data-pv-for' => 'vAmphoe', 'aria-label' => 'จังหวัด'), '', '— จังหวัด —') ?>
                                    <select class="form-control" id="vAmphoe" name="amphoe_code">
                                        <option value="">—</option>
                                        <?= flood_amphoe_options($this->amphoes, $v('amphoe_code')) ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="form-group">
                                <label for="vTambon">ตำบล</label>
                                <select class="form-control" id="vTambon" name="tambon_code" data-selected="<?= h($v('tambon_code')) ?>"><option value="">—</option></select>
                            </div>
                        </div>
                    </div>
                    <p class="small-muted" style="margin:0 0 6px"><b>ปักหมุดบ้าน</b> เพื่อให้ระบบเตือนเมื่อบ้านอยู่ในพื้นที่ประกาศ — แตะบนแผนที่ หรือกดใช้ตำแหน่งปัจจุบันเมื่ออยู่ที่บ้านผู้ป่วย</p>
                    <div class="flex flex-wrap gap-2" style="margin-bottom:6px">
                        <button type="button" class="btn btn-default btn-sm" id="vLocate"><i class="fa fa-crosshairs"></i> ใช้ตำแหน่งปัจจุบัน</button>
                        <button type="button" class="btn btn-default btn-sm" id="vClear"><i class="fa fa-times"></i> ล้างหมุด</button>
                    </div>
                    <div id="vMap" class="flood-map"></div>
                    <div class="small-muted" id="vLocStatus" style="margin-top:4px">ยังไม่ได้ปักหมุด</div>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary btn-lg" id="vSave"><i class="fa fa-save"></i> บันทึก</button>
                <a href="<?= URL ?>flood/vulnerable" class="btn btn-default btn-lg">ยกเลิก</a>
                <?php if ($p) { ?>
                <button type="button" class="btn btn-default btn-lg" id="vRemove" style="margin-left:auto"><i class="fa fa-trash-o"></i> นำออกจากทะเบียน</button>
                <?php } ?>
            </div>
        </div>
    </div>
</form>

<script>
    window.VFORM = <?= flood_js(array(
        'person_id' => $p ? (int) $p['person_id'] : 0,
        'name' => $p ? $p['name'] : '',
        'initial' => $p && $p['lat'] !== null ? array((float) $p['lat'], (float) $p['lng']) : null,
        'zones' => $this->activeZones,
        'tambons' => $this->tambons,
    )) ?>;
</script>
