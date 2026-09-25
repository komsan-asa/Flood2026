<?php
$rows = $this->levelRows;
$icons = flood_level_icons();
$presets = flood_level_color_presets();
$builtin = flood_zone_levels_builtin();
$levelData = array();
foreach ($rows as $r) {
    $levelData[$r['level_code']] = array(
        'level_code' => $r['level_code'], 'name' => $r['name'], 'description' => (string) $r['description'],
        'color' => $r['color'], 'icon' => $r['icon'], 'is_active' => (int) $r['is_active'],
    );
}
?>
<div class="flood-page-header">
    <h2 class="flood-page-title"><i class="fa fa-sliders"></i> ระดับพื้นที่</h2>
    <?php if ($this->levelReady) { ?>
    <button type="button" class="btn btn-primary js-level-edit" data-code=""><i class="fa fa-plus"></i> เพิ่มระดับ</button>
    <?php } ?>
</div>
<p class="text-muted">
    ระดับที่เจ้าหน้าที่เลือกตอนประกาศพื้นที่ — ใช้เป็นสีบนแผนที่ คำอธิบายสี และตัวเลขสรุป ทั้งหน้าประชาชนและหน้าเจ้าหน้าที่
    เรียงจากรุนแรงมากไปน้อย (ลำดับนี้ใช้เรียงรายการ และพื้นที่ที่รุนแรงกว่าจะวาดทับด้านบนของแผนที่)
</p>

<?php if (!$this->levelReady) { ?>
<div class="alert alert-warning">
    <i class="fa fa-exclamation-triangle"></i> สร้างตาราง <code>flood_zone_level</code> ไม่ได้ (บัญชีฐานข้อมูลของเว็บอาจไม่มีสิทธิ์ CREATE)
    — ให้รันไฟล์ <code>sql/08_flood_zone_level.sql</code> บนฐานข้อมูล แล้วเปิดหน้านี้ใหม่
    ระหว่างนี้ระบบใช้ระดับตั้งต้น <?= count($builtin) ?> ระดับไปก่อน
</div>
<?php } else { ?>
<div class="flood-card">
    <div class="table-responsive">
        <table class="table table-hover table-cards" style="margin:0">
            <thead>
                <tr><th style="width:1%">ลำดับ</th><th>ระดับ</th><th>คำอธิบาย</th><th>พื้นที่ที่ใช้ระดับนี้</th><th>สถานะ</th><th style="width:1%"></th></tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $i => $r) {
                    $code = $r['level_code'];
                    $l = flood_level($code);
                    $canDelete = !isset($builtin[$code]) && (int) $r['all_zones'] === 0;
                ?>
                <tr<?= (int) $r['is_active'] === 1 ? '' : ' class="level-off"' ?>>
                    <td data-label="ลำดับ" class="nowrap">
                        <div class="level-move">
                            <span class="level-rank"><?= $i + 1 ?></span>
                            <button type="button" class="btn btn-default btn-xs js-level-move" data-code="<?= h($code) ?>" data-dir="up"
                                title="เลื่อนขึ้น (รุนแรงขึ้น)" aria-label="เลื่อน <?= h($r['name']) ?> ขึ้น"<?= $i === 0 ? ' disabled' : '' ?>><i class="fa fa-arrow-up"></i></button>
                            <button type="button" class="btn btn-default btn-xs js-level-move" data-code="<?= h($code) ?>" data-dir="down"
                                title="เลื่อนลง (เบาลง)" aria-label="เลื่อน <?= h($r['name']) ?> ลง"<?= $i === count($rows) - 1 ? ' disabled' : '' ?>><i class="fa fa-arrow-down"></i></button>
                        </div>
                    </td>
                    <td data-label="ระดับ" class="nowrap"><?= flood_level_badge($code) ?><span class="level-swatch" style="background:<?= h($l['color']) ?>" title="สีบนแผนที่"></span></td>
                    <td data-label="คำอธิบาย"><?= $r['description'] !== null && $r['description'] !== '' ? h($r['description']) : '<span class="text-muted">—</span>' ?></td>
                    <td data-label="พื้นที่ที่ใช้">
                        ประกาศอยู่ <b><?= (int) $r['active_zones'] ?></b> <span class="small-muted">· รวมที่ปิดแล้ว <?= (int) $r['all_zones'] ?></span>
                    </td>
                    <td data-label="สถานะ"><?= (int) $r['is_active'] === 1 ? '<span class="label label-success">ใช้งาน</span>' : '<span class="label label-default">ปิดใช้งาน</span>' ?></td>
                    <td data-label="" class="nowrap">
                        <button type="button" class="btn btn-default btn-sm js-level-edit" data-code="<?= h($code) ?>"><i class="fa fa-pencil"></i> แก้ไข</button>
                        <?php if ($canDelete) { ?>
                        <button type="button" class="btn btn-default btn-sm js-level-delete" data-code="<?= h($code) ?>" data-name="<?= h($r['name']) ?>"
                            title="ลบ" aria-label="ลบ <?= h($r['name']) ?>"><i class="fa fa-trash-o"></i></button>
                        <?php } ?>
                    </td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>
<p class="small-muted">
    ระดับตั้งต้นลบไม่ได้ แต่ปิดใช้งานได้ · ระดับที่ยังมีพื้นที่ประกาศอยู่ปิดใช้งานไม่ได้ ·
    ระดับที่ปิดแล้วจะไม่อยู่ในคำอธิบายสีและตัวเลือกตอนประกาศพื้นที่ แต่พื้นที่เก่าที่ใช้ระดับนั้นยังแสดงชื่อและสีเดิม
</p>

<div class="modal fade" id="levelModal" tabindex="-1" role="dialog">
    <div class="modal-dialog">
        <form class="modal-content" id="levelForm" autocomplete="off">
            <div class="modal-header">
                <h4 class="modal-title" id="levelTitle">เพิ่มระดับ</h4>
                <button type="button" class="close" data-dismiss="modal" aria-label="ปิด">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="level_code" id="lvCode" />
                <div class="form-group">
                    <label for="lvName" class="required">ชื่อระดับ</label>
                    <input type="text" class="form-control" id="lvName" name="name" maxlength="60" placeholder="เช่น รถทุกชนิดผ่านไม่ได้" />
                </div>
                <div class="form-group">
                    <label for="lvDesc">คำอธิบาย</label>
                    <input type="text" class="form-control" id="lvDesc" name="description" maxlength="200"
                        placeholder="ช่วยให้เจ้าหน้าที่เลือกระดับได้ถูก เช่น น้ำสูง รถใหญ่ก็ผ่านไม่ได้" />
                </div>
                <div class="form-group">
                    <label id="lvColorLabel">สี</label>
                    <div class="level-colors" role="radiogroup" aria-labelledby="lvColorLabel">
                        <?php foreach ($presets as $c) { ?>
                        <button type="button" class="level-color js-level-color" data-color="<?= h($c) ?>" style="background:<?= h($c) ?>"
                            role="radio" aria-checked="false" aria-label="สี <?= h($c) ?>"></button>
                        <?php } ?>
                        <label class="level-color-custom" title="เลือกสีเอง"><input type="color" id="lvColor" name="color" value="#0284c7" /> เลือกเอง</label>
                    </div>
                </div>
                <div class="form-group">
                    <label for="lvIcon">ไอคอน</label>
                    <select class="form-control" id="lvIcon" name="icon">
                        <?php foreach ($icons as $k => $v) { ?>
                        <option value="<?= h($k) ?>"><?= h($v) ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="checkbox">
                    <label><input type="checkbox" id="lvActive" checked /> ใช้งาน (เลือกได้ตอนประกาศพื้นที่ และแสดงในคำอธิบายสี)</label>
                </div>
                <div class="level-preview" aria-hidden="true">
                    <div class="small-muted">ตัวอย่าง</div>
                    <span class="lv-badge" id="lvPreviewBadge"><i class="fa fa-circle"></i> <span>ชื่อระดับ</span></span>
                    <span class="level-preview-map" id="lvPreviewMap"></span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">ยกเลิก</button>
                <button type="submit" class="btn btn-primary" id="lvSave"><i class="fa fa-save"></i> บันทึก</button>
            </div>
        </form>
    </div>
</div>

<script>
    window.LEVELS = <?= flood_js($levelData) ?>;
</script>
<?php } ?>
