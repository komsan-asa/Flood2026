<?php
$h = $this->help;
$st = flood_help_statuses();
$pr = flood_priorities();
$needs = flood_help_needs();
$flags = flood_help_flags();
$groups = flood_vulnerable_groups();
$evac = flood_evac_statuses();
$isOfficer = $this->isOfficer;
$isTeam = $this->isTeam;
$status = $h['status'];
$open = in_array($status, flood_help_open_statuses(), true);
$actionNames = array(
    'create' => 'รับเรื่อง', 'verify' => 'โทรยืนยันแล้ว', 'assign' => 'มอบหมายทีม', 'start' => 'เริ่มออกช่วยเหลือ',
    'done' => 'ช่วยเหลือแล้ว', 'cancel' => 'ยกเลิก', 'reopen' => 'เปิดงานอีกครั้ง', 'note' => 'บันทึกเพิ่มเติม', 'priority' => 'เปลี่ยนความเร่งด่วน',
);
?>
<div class="flood-page-header">
    <h2 class="flood-page-title"><i class="fa fa-life-ring"></i> <?= h($h['ref_code']) ?>
        <?= flood_status_label($pr, $h['priority']) ?> <?= flood_status_label($st, $status) ?></h2>
    <a href="<?= URL ?>flood/help" class="btn btn-default btn-sm js-back"><i class="fa fa-arrow-left"></i> กลับ</a>
</div>

<?php foreach ($this->zonesHere as $z) { ?>
<div class="alert alert-warning"><i class="fa fa-exclamation-triangle"></i> ตำแหน่งนี้อยู่ในพื้นที่ประกาศ <b><?= h($z['name']) ?></b> <?= flood_level_badge($z['level']) ?></div>
<?php } ?>

<div class="row">
    <div class="col-md-7">
        <div class="flood-card">
            <div class="flood-card-header">ข้อมูลคำขอ</div>
            <div class="flood-card-body">
                <dl class="kv">
                    <dt>ต้องการ</dt><dd><?= flood_tags(flood_codes_names($h['needs'], $needs)) ?></dd>
                    <?php if ($h['vulnerable_flags']) { ?>
                    <dt>กลุ่มเปราะบาง</dt><dd><?= flood_tags(flood_codes_names($h['vulnerable_flags'], $flags), 'tag-warn') ?></dd>
                    <?php } ?>
                    <?php if ($h['people_count']) { ?><dt>จำนวนคน</dt><dd><?= (int) $h['people_count'] ?> คน</dd><?php } ?>
                    <?php if ($h['detail']) { ?><dt>รายละเอียด</dt><dd style="white-space:pre-line"><?= h($h['detail']) ?></dd><?php } ?>
                    <dt>ผู้ขอ</dt>
                    <dd><?= h($h['requester_name']) ?><br>
                        <a href="tel:<?= h($h['requester_phone']) ?>" class="phone-big"><i class="fa fa-phone"></i> <?= h(flood_format_phone($h['requester_phone'])) ?></a></dd>
                    <dt>พื้นที่</dt>
                    <dd><?= $h['tambon_name'] ? 'ต.' . h($h['tambon_name']) . ' ' : '' ?><?= $h['amphoe_name'] ? 'อ.' . h($h['amphoe_name']) : '<span class="text-muted">ไม่ระบุ</span>' ?>
                        <?php if ($h['address']) { ?><div><?= h($h['address']) ?></div><?php } ?></dd>
                    <dt>ช่องทาง</dt>
                    <dd><?= $h['source'] === 'phone' ? 'เจ้าหน้าที่รับทางโทรศัพท์' . ($h['created_by_name'] ? ' (' . h($h['created_by_name']) . ')' : '') : ($h['source'] === 'facebook' ? 'นำเข้าจากโพสต์ Facebook — ลิงก์อยู่ในรายละเอียด' : 'ประชาชนส่งทางเว็บ') ?></dd>
                    <dt>รับเรื่อง</dt><dd><?= h(flood_thai_date($h['created_at'])) ?> <span class="small-muted">(<?= h(flood_ago($h['created_at'])) ?>)</span></dd>
                    <?php if ($h['verified_at']) { ?><dt>ยืนยันโดย</dt><dd><?= h((string) $h['verified_by_name']) ?> · <?= h(flood_thai_date($h['verified_at'])) ?></dd><?php } ?>
                    <?php if ($h['team_name']) { ?>
                    <dt>ทีม</dt><dd><b><?= h($h['team_name']) ?></b><?php if ($h['team_phone']) { ?> · <a href="tel:<?= h($h['team_phone']) ?>"><?= h(flood_format_phone($h['team_phone'])) ?></a><?php } ?>
                        <?php if ($h['assigned_at']) { ?><span class="small-muted"> · มอบหมาย <?= h(flood_thai_date($h['assigned_at'])) ?></span><?php } ?></dd>
                    <?php } ?>
                    <?php if ($h['result_note']) { ?><dt>ผล / เหตุผล</dt><dd><?= h($h['result_note']) ?></dd><?php } ?>
                </dl>
            </div>
        </div>

        <div class="flood-card">
            <div class="flood-card-header">
                <span>ตำแหน่ง</span>
                <?php if ($h['lat'] !== null) { ?>
                <a href="https://www.google.com/maps/dir/?api=1&amp;destination=<?= h($h['lat']) ?>,<?= h($h['lng']) ?>" target="_blank" rel="noopener" class="btn btn-default btn-sm"><i class="fa fa-location-arrow"></i> นำทาง</a>
                <?php } ?>
            </div>
            <div class="flood-card-body" style="padding:10px">
                <?php if ($h['lat'] !== null) { ?>
                <div id="hvMap" class="flood-map map-sm"></div>
                <div class="small-muted" style="margin-top:4px"><?= h($h['lat']) ?>, <?= h($h['lng']) ?><?= $h['accuracy_m'] ? ' · GPS ±' . (int) $h['accuracy_m'] . ' ม.' : '' ?></div>
                <?php } else { ?>
                <div class="empty-state"><i class="fa fa-map-marker"></i>ผู้แจ้งไม่ได้ระบุพิกัด — ใช้ที่อยู่/จุดสังเกตด้านบน</div>
                <?php } ?>
            </div>
        </div>

        <?php if ($h['photos']) { ?>
        <div class="flood-card">
            <div class="flood-card-header">รูปถ่าย</div>
            <div class="flood-card-body photo-grid">
                <?php foreach ($h['photos'] as $p) { ?>
                <a href="<?= URL ?>flood/attachment/<?= (int) $p['attachment_id'] ?>" target="_blank" rel="noopener">
                    <img src="<?= URL ?>flood/attachment/<?= (int) $p['attachment_id'] ?>" alt="รูปจากผู้ขอ" loading="lazy" /></a>
                <?php } ?>
            </div>
        </div>
        <?php } ?>

        <?php if ($this->registryMatches) { ?>
        <div class="alert alert-info">
            <b><i class="fa fa-wheelchair"></i> เบอร์นี้ตรงกับทะเบียนกลุ่มเปราะบาง</b>
            <?php foreach ($this->registryMatches as $m) { ?>
            <div style="margin-top:4px"><?= h($m['name']) ?> — <?= h(implode(', ', flood_codes_names($m['vuln_groups'], $groups))) ?>
                <?= $m['medical_needs'] ? ' · ' . h($m['medical_needs']) : '' ?>
                · <?= flood_status_label($evac, $m['evac_status']) ?>
                <a href="<?= URL ?>flood/vulnerableForm/<?= (int) $m['person_id'] ?>">ดูข้อมูล</a></div>
            <?php } ?>
        </div>
        <?php } ?>

        <?php if ($this->samePhone) { ?>
        <div class="flood-card">
            <div class="flood-card-header">คำขออื่นจากเบอร์เดียวกัน</div>
            <div>
                <?php foreach ($this->samePhone as $o) { ?>
                <a class="dash-list-item" href="<?= URL ?>flood/helpView/<?= (int) $o['help_id'] ?>">
                    <div class="t1"><?= h($o['ref_code']) ?> <?= flood_status_label($st, $o['status']) ?></div>
                    <div class="t2"><?= h(implode(', ', flood_codes_names($o['needs'], $needs))) ?> · <?= h(flood_thai_date($o['created_at'])) ?></div>
                </a>
                <?php } ?>
            </div>
        </div>
        <?php } ?>
    </div>

    <div class="col-md-5">
        <div class="flood-card">
            <div class="flood-card-header">ดำเนินการ</div>
            <div class="flood-card-body" id="hvActions" data-id="<?= (int) $h['help_id'] ?>">
                <?php if ($isOfficer && $status === 'new') { ?>
                <div class="action-block">
                    <h4><i class="fa fa-phone"></i> 1. โทรยืนยันกับผู้ขอ</h4>
                    <div class="flex flex-wrap gap-2">
                        <select class="form-control input-sm" id="hvVerifyPriority" style="width:auto"><?= flood_options($pr, $h['priority']) ?></select>
                        <button type="button" class="btn btn-warning btn-sm js-act" data-action="verify"><i class="fa fa-check"></i> ยืนยันแล้ว</button>
                    </div>
                    <input type="text" class="form-control input-sm js-note" data-for="verify" style="margin-top:6px" placeholder="บันทึกจากการโทร (ถ้ามี)" maxlength="1000" />
                </div>
                <?php } ?>

                <?php if ($isOfficer && $open) { ?>
                <div class="action-block">
                    <h4><i class="fa fa-users"></i> <?= $h['team_id'] ? 'เปลี่ยนทีม' : 'มอบหมายทีมช่วยเหลือ' ?></h4>
                    <div class="flex flex-wrap gap-2">
                        <select class="form-control input-sm" id="hvTeam" style="flex:1 1 200px">
                            <option value="">— เลือกทีม —</option>
                            <?php foreach ($this->teams as $t) { ?>
                            <option value="<?= (int) $t['team_id'] ?>"<?= (int) $h['team_id'] === (int) $t['team_id'] ? ' selected' : '' ?>>
                                <?= h($t['name']) ?> (งานค้าง <?= (int) $t['open_jobs'] ?>)</option>
                            <?php } ?>
                        </select>
                        <button type="button" class="btn btn-info btn-sm js-act" data-action="assign"><i class="fa fa-paper-plane"></i> มอบหมาย</button>
                    </div>
                    <input type="text" class="form-control input-sm js-note" data-for="assign" style="margin-top:6px" placeholder="ข้อความถึงทีม (ถ้ามี)" maxlength="1000" />
                    <?php if (!$this->teams) { ?><div class="help-block">ยังไม่มีทีม — เพิ่มได้ที่เมนูทีมช่วยเหลือ</div><?php } ?>
                </div>
                <?php } ?>

                <?php if ($status === 'assigned') { ?>
                <div class="action-block">
                    <h4><i class="fa fa-truck"></i> ทีมออกช่วยเหลือ</h4>
                    <button type="button" class="btn btn-primary btn-sm js-act" data-action="start"><i class="fa fa-play"></i> เริ่มออกช่วยเหลือ</button>
                </div>
                <?php } ?>

                <?php if (in_array($status, array('assigned', 'in_progress'), true)) { ?>
                <div class="action-block">
                    <h4><i class="fa fa-check-circle"></i> ปิดงาน</h4>
                    <textarea class="form-control input-sm js-note" data-for="done" rows="2" maxlength="255" placeholder="ผลการช่วยเหลือ เช่น อพยพ 3 คนไปศูนย์พักพิง… / ส่งยาแล้ว"></textarea>
                    <button type="button" class="btn btn-success btn-sm js-act" data-action="done" style="margin-top:6px"><i class="fa fa-check"></i> ช่วยเหลือเรียบร้อย</button>
                </div>
                <?php } ?>

                <?php if ($isOfficer && $open) { ?>
                <div class="action-block">
                    <h4><i class="fa fa-sliders"></i> อื่น ๆ</h4>
                    <div class="flex flex-wrap gap-2">
                        <select class="form-control input-sm" id="hvPriority" style="width:auto"><?= flood_options($pr, $h['priority']) ?></select>
                        <button type="button" class="btn btn-default btn-sm js-act" data-action="priority">เปลี่ยนความเร่งด่วน</button>
                    </div>
                    <div class="flex flex-wrap gap-2" style="margin-top:8px">
                        <input type="text" class="form-control input-sm js-note" data-for="cancel" style="flex:1 1 200px" placeholder="เหตุผลที่ยกเลิก (จำเป็น)" maxlength="255" />
                        <button type="button" class="btn btn-danger btn-sm js-act" data-action="cancel"><i class="fa fa-times"></i> ยกเลิกใบงาน</button>
                    </div>
                </div>
                <?php } ?>

                <?php if ($isOfficer && !$open) { ?>
                <div class="action-block">
                    <button type="button" class="btn btn-default btn-sm js-act" data-action="reopen"><i class="fa fa-undo"></i> เปิดใบงานอีกครั้ง</button>
                </div>
                <?php } ?>

                <div class="action-block">
                    <h4><i class="fa fa-comment-o"></i> บันทึกเพิ่มเติม</h4>
                    <textarea class="form-control input-sm js-note" data-for="note" rows="2" maxlength="1000" placeholder="เช่น โทรไม่ติด จะโทรอีกครั้ง 10 นาที"></textarea>
                    <button type="button" class="btn btn-default btn-sm js-act" data-action="note" style="margin-top:6px"><i class="fa fa-plus"></i> เพิ่มบันทึก</button>
                </div>
            </div>
        </div>

        <div class="flood-card">
            <div class="flood-card-header">ไทม์ไลน์</div>
            <div class="flood-card-body">
                <ul class="timeline">
                    <?php foreach ($h['logs'] as $l) { ?>
                    <li class="timeline-item">
                        <div class="t-head"><?= h(isset($actionNames[$l['action']]) ? $actionNames[$l['action']] : $l['action']) ?>
                            <?php if ($l['action'] === 'assign' && $l['team_name']) { ?> → <?= h($l['team_name']) ?><?php } ?></div>
                        <div class="t-meta"><?= h(flood_thai_date($l['created_at'])) ?> · <?= h($l['user_name'] !== '' ? $l['user_name'] : 'ระบบ/ประชาชน') ?></div>
                        <?php if ($l['note']) { ?><div class="t-note"><?= h($l['note']) ?></div><?php } ?>
                    </li>
                    <?php } ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
    window.HELP_VIEW = <?= flood_js(array(
        'help_id' => (int) $h['help_id'],
        'lat' => $h['lat'] !== null ? (float) $h['lat'] : null,
        'lng' => $h['lng'] !== null ? (float) $h['lng'] : null,
        'accuracy_m' => $h['accuracy_m'] !== null ? (int) $h['accuracy_m'] : null,
        'priority' => $h['priority'],
        'zones' => array_map(function ($z) {
            return array('zone_id' => (int) $z['zone_id'], 'name' => $z['name'], 'level' => $z['level'], 'shape' => $z['shape'],
                'center' => array((float) $z['center_lat'], (float) $z['center_lng']), 'radius_m' => $z['radius_m'] !== null ? (int) $z['radius_m'] : null,
                'polygon' => isset($z['poly']) ? $z['poly'] : null, 'note' => $z['note']);
        }, $this->zonesHere),
    )) ?>;
</script>
