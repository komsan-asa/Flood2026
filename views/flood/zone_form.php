<?php
$z = $this->zone;
$p = $this->prefill;
$levels = flood_zone_levels();
if ($z && !isset($levels[$z['level']])) {
    $levels[$z['level']] = flood_level($z['level']);   // ระดับเดิมที่ผู้ดูแลปิดใช้ไปแล้ว — พื้นที่นี้ยังคงไว้ได้
}
$levelCodes = array_keys($levels);
$defaultLevel = isset($levels['watch']) ? 'watch' : (string) array_pop($levelCodes);   // เฝ้าระวัง หรือระดับที่เบาที่สุด
$sources = flood_zone_sources();
if (!$z || $z['source'] !== 'arankub') {
    unset($sources['arankub']);   // ใช้กับพื้นที่ที่นำเข้ามาเท่านั้น
}
$val = function ($key, $default = '') use ($z, $p) {
    if ($z && isset($z[$key]) && $z[$key] !== null) {
        return $z[$key];
    }
    if ($p && isset($p[$key]) && $p[$key] !== null) {
        return $p[$key];
    }
    return $default;
};
$level = $val('level', $defaultLevel);
$amphoe = (string) $val('amphoe_code');
$tambon = (string) $val('tambon_code');
$started = $z ? date('Y-m-d\TH:i', strtotime($z['started_at'])) : date('Y-m-d\TH:i');
$shape = $z ? $z['shape'] : 'circle';
?>
<div class="flood-page-header">
    <h2 class="flood-page-title"><i class="fa fa-map-marker"></i> <?= $z ? 'แก้ไขพื้นที่ประกาศ' : 'ประกาศพื้นที่ใหม่' ?></h2>
    <a href="<?= URL ?>flood/zones" class="btn btn-default btn-sm js-back"><i class="fa fa-arrow-left"></i> กลับ</a>
</div>

<?php if ($p) { ?>
<div class="alert alert-info">
    <i class="fa fa-tint"></i> สร้างจากรายงานประชาชน <b><?= h($p['ref_code']) ?></b> — ระบบตั้งจุดศูนย์กลาง รัศมี และระดับให้ตามรายงาน ปรับได้ก่อนบันทึก
    เมื่อบันทึกแล้ว รายงานนี้จะถูกบันทึกว่า "ยืนยันแล้ว"
</div>
<?php } ?>

<form id="zoneForm" autocomplete="off">
    <input type="hidden" name="zone_id" value="<?= $z ? (int) $z['zone_id'] : 0 ?>" />
    <input type="hidden" name="report_id" value="<?= $p ? (int) $p['report_id'] : 0 ?>" />
    <input type="hidden" name="shape" id="zShape" value="<?= h($shape) ?>" />
    <input type="hidden" name="center_lat" id="zLat" />
    <input type="hidden" name="center_lng" id="zLng" />
    <input type="hidden" name="radius_m" id="zRadiusHidden" />
    <input type="hidden" name="polygon_json" id="zPolygon" />

    <div class="row">
        <div class="col-md-5">
            <div class="flood-card">
                <div class="flood-card-header">รายละเอียดพื้นที่</div>
                <div class="flood-card-body">
                    <div class="form-group">
                        <label for="zName" class="required">ชื่อพื้นที่</label>
                        <input type="text" class="form-control" id="zName" name="name" maxlength="200"
                            value="<?= h($val('name')) ?>" placeholder="เช่น ชุมชนหลังตลาด / ถนนสาย 33 ช่วงสะพาน…" />
                    </div>
                    <div class="form-group">
                        <label class="required">ระดับ</label>
                        <div class="chip-grid" style="grid-template-columns:minmax(0,1fr)">
                            <?php foreach ($levels as $code => $l) { ?>
                            <label class="chip lv-<?= h($code) ?>">
                                <input type="radio" name="level" value="<?= h($code) ?>"<?= $level === $code ? ' checked' : '' ?> />
                                <span class="chip-body">
                                    <span class="chip-emoji" style="color:<?= h($l['color']) ?>"><i class="fa <?= h($l['icon']) ?>"></i></span>
                                    <span><span class="chip-title"><?= h($l['name']) ?></span><span class="chip-hint"><?= h($l['desc']) ?></span></span>
                                </span>
                            </label>
                            <?php } ?>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-sm-6">
                            <div class="form-group">
                                <label for="zAmphoe">อำเภอ</label>
                                <select class="form-control" id="zAmphoe" name="amphoe_code">
                                    <option value="">— ให้ระบบหาจากแผนที่ —</option>
                                    <?php foreach ($this->amphoes as $a) { ?>
                                    <option value="<?= h($a['amphoe_code']) ?>"<?= $amphoe === $a['amphoe_code'] ? ' selected' : '' ?>><?= h($a['name']) ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="form-group">
                                <label for="zTambon">ตำบล</label>
                                <select class="form-control" id="zTambon" name="tambon_code" data-selected="<?= h($tambon) ?>">
                                    <option value="">—</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="zNote">ข้อความถึงประชาชน</label>
                        <textarea class="form-control" id="zNote" name="note" rows="3" maxlength="2000"
                            placeholder="เช่น รถเก๋ง-มอเตอร์ไซค์ หลีกเลี่ยง / ให้ยกของขึ้นที่สูง"><?= h($val('note')) ?></textarea>
                        <div class="help-block">แสดงบนแผนที่สาธารณะ — อย่าใส่ชื่อหรือเบอร์โทรของบุคคล</div>
                    </div>
                    <div class="form-group zone-photos-group" id="zPhotoGroup" data-max="<?= (int) $this->photoMax ?>" data-current="<?= count($this->zonePhotos) ?>">
                        <label>ภาพประกอบ <span class="small-muted">(แสดงในบอลลูนบนแผนที่สาธารณะ · ไม่เกิน <?= (int) $this->photoMax ?> ภาพ)</span></label>
                        <?php if (!$this->hasGd) { ?>
                        <div class="alert alert-warning" style="margin:0"><i class="fa fa-exclamation-triangle"></i> <?= h(flood_no_gd_message()) ?></div>
                        <?php } else { ?>
                        <?php if ($this->zonePhotos) { ?>
                        <div class="zone-photo-grid">
                            <?php foreach ($this->zonePhotos as $i => $ph) { ?>
                            <label class="zone-photo">
                                <img src="<?= URL ?>api/zoneThumb/<?= (int) $ph['attachment_id'] ?>" alt="ภาพประกอบพื้นที่ ภาพที่ <?= $i + 1 ?>" loading="lazy" />
                                <?php if ($i === 0) { ?><span class="zone-photo-tag">ภาพแรก</span><?php } ?>
                                <span class="zone-photo-act"><input type="checkbox" name="remove_photo_ids[]" value="<?= (int) $ph['attachment_id'] ?>" class="js-photo-remove" /> ลบ</span>
                            </label>
                            <?php } ?>
                        </div>
                        <?php } ?>
                        <?php if ($this->reportPhotos) { ?>
                        <div class="zone-photo-sub">รูปจากรายงานประชาชน — ติ๊กภาพที่จะแสดงบนแผนที่</div>
                        <div class="zone-photo-grid">
                            <?php foreach ($this->reportPhotos as $rp) { $used = (int) $rp['used'] > 0; ?>
                            <label class="zone-photo<?= $used ? ' is-used' : '' ?>">
                                <img src="<?= URL ?>flood/attachment/<?= (int) $rp['attachment_id'] ?>" alt="รูปจากรายงาน <?= h($rp['ref_code']) ?>" loading="lazy" />
                                <span class="zone-photo-tag"><?= h($rp['ref_code']) ?></span>
                                <span class="zone-photo-act">
                                    <?php if ($used) { ?><i class="fa fa-check"></i> แสดงอยู่แล้ว<?php } else { ?>
                                    <input type="checkbox" name="report_photo_ids[]" value="<?= (int) $rp['attachment_id'] ?>" class="js-photo-pick" /> ใช้ภาพนี้<?php } ?>
                                </span>
                            </label>
                            <?php } ?>
                        </div>
                        <?php } ?>
                        <div class="up-wrap" style="margin-top:8px">
                            <label class="btn btn-default btn-sm"><input type="file" id="zPhotos" accept="image/*" multiple style="display:none" />
                                <i class="fa fa-camera"></i> เพิ่มภาพ <span class="up-count"></span></label>
                            <div class="up-preview" id="zPreview"></div>
                        </div>
                        <div class="help-block">ภาพล่าสุดขึ้นเป็นภาพแรกของบอลลูน · เลือกภาพที่เห็นสภาพน้ำหรือถนน หลีกเลี่ยงใบหน้าคน ทะเบียนรถ และบ้านเลขที่</div>
                        <?php } ?>
                    </div>
                    <div class="row">
                        <div class="col-sm-6">
                            <div class="form-group">
                                <label for="zSource">แหล่งที่มา</label>
                                <select class="form-control" id="zSource" name="source">
                                    <?= flood_options($sources, $p ? 'report' : $val('source', 'officer')) ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="form-group">
                                <label for="zStarted">เวลาประกาศ</label>
                                <input type="datetime-local" class="form-control" id="zStarted" name="started_at" value="<?= h($started) ?>" />
                            </div>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-primary" id="zSave"><i class="fa fa-bullhorn"></i> <?= $z ? 'บันทึกการแก้ไข' : 'ประกาศพื้นที่' ?></button>
                        <a href="<?= URL ?>flood/zones" class="btn btn-default">ยกเลิก</a>
                    </div>
                    <?php if ($z) { ?>
                    <p class="small-muted" style="margin:10px 0 0">สถานะ: <?= $z['status'] === 'active' ? 'ประกาศอยู่' : 'ปิดประกาศแล้ว' ?> · ปิด/เปิดประกาศได้จากหน้ารายการ</p>
                    <?php } ?>
                </div>
            </div>
        </div>

        <div class="col-md-7">
            <div class="flood-card">
                <div class="flood-card-header">
                    <span>ขอบเขตบนแผนที่ <span class="req text-danger">*</span></span>
                    <span class="small-muted" id="zArea"></span>
                </div>
                <div class="flood-card-body" style="padding:10px">
                    <div class="zone-toolbar">
                        <div class="btn-group" role="group" aria-label="รูปแบบพื้นที่">
                            <button type="button" class="btn btn-default btn-sm js-shape<?= $shape === 'circle' ? ' active' : '' ?>" data-shape="circle"><i class="fa fa-circle-o"></i> วงกลม</button>
                            <button type="button" class="btn btn-default btn-sm js-shape<?= $shape === 'polygon' ? ' active' : '' ?>" data-shape="polygon"><i class="fa fa-object-ungroup"></i> วาดขอบเขต</button>
                        </div>
                        <div class="radius-box" id="zRadiusBox">
                            <span class="small-muted">รัศมี</span>
                            <input type="range" id="zRadiusRange" min="20" max="5000" step="10" />
                            <input type="number" class="form-control input-sm" id="zRadius" min="20" max="30000" step="10" style="width:90px" />
                            <span class="small-muted">ม.</span>
                        </div>
                        <div id="zPolyTools" class="hidden">
                            <button type="button" class="btn btn-default btn-sm" id="zUndo"><i class="fa fa-undo"></i> ย้อนจุดล่าสุด</button>
                            <button type="button" class="btn btn-default btn-sm" id="zClear"><i class="fa fa-trash-o"></i> ล้าง</button>
                        </div>
                        <button type="button" class="btn btn-default btn-sm" id="zLocate" title="ไปยังตำแหน่งของฉัน"><i class="fa fa-crosshairs"></i></button>
                    </div>
                    <div class="zone-help" id="zHelp"></div>
                    <div id="zoneMap" class="flood-map map-lg"></div>
                    <div class="map-legend" style="margin-top:6px">
                        <span><span class="sw" style="background:#94a3b8"></span>พื้นที่อื่นที่ประกาศอยู่</span>
                        <span><span class="sw" style="background:#1f78c1;border-radius:50%"></span>รายงานประชาชนรอตรวจ</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
    window.ZONE_FORM = <?= flood_js(array(
        'zone' => $z ? array(
            'shape' => $z['shape'],
            'center' => array((float) $z['center_lat'], (float) $z['center_lng']),
            'radius_m' => $z['radius_m'] !== null ? (int) $z['radius_m'] : 300,
            'polygon' => $z['shape'] === 'polygon' ? flood_parse_polygon($z['polygon_json']) : null,
        ) : null,
        'prefill' => $p ? array('center' => array($p['lat'], $p['lng']), 'radius_m' => (int) $p['radius_m']) : null,
        'others' => $this->otherZones,
        'reports' => $this->pendingReports,
        'tambons' => $this->tambons,
    )) ?>;
</script>
