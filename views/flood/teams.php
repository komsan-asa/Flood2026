<?php
$types = flood_team_types();
$rows = $this->teamRows;
?>
<div class="flood-page-header">
    <h2 class="flood-page-title"><i class="fa fa-ambulance"></i> ทีมช่วยเหลือ</h2>
    <button type="button" class="btn btn-primary js-team-edit" data-team="0"><i class="fa fa-plus"></i> เพิ่มทีม</button>
</div>
<p class="text-muted">ทีมที่รับใบงานได้ เช่น ทีมแพทย์ของโรงพยาบาล มูลนิธิกู้ภัย อปท. อาสาสมัคร — ผู้ใช้สิทธิ์ "ทีมช่วยเหลือ" จะเห็นเฉพาะใบงานของทีมตัวเอง</p>

<div class="flood-card">
    <div class="table-responsive">
        <table class="table table-hover table-cards" style="margin:0">
            <thead>
                <tr><th>ชื่อทีม</th><th>ประเภท</th><th>โทร</th><th>พื้นที่หลัก</th><th>ยานพาหนะ/อุปกรณ์</th><th>งานค้าง</th><th>สมาชิก</th><th>สถานะ</th><th style="width:1%"></th></tr>
            </thead>
            <tbody>
                <?php if (!$rows) { ?>
                <tr><td colspan="9"><div class="empty-state"><i class="fa fa-ambulance"></i>ยังไม่มีทีม — กด "เพิ่มทีม"</div></td></tr>
                <?php } ?>
                <?php foreach ($rows as $t) { ?>
                <tr<?= (int) $t['is_active'] === 1 ? '' : ' style="opacity:.6"' ?>>
                    <td data-label="ชื่อทีม"><b><?= h($t['name']) ?></b><?php if ($t['note']) { ?><div class="small-muted"><?= h($t['note']) ?></div><?php } ?></td>
                    <td data-label="ประเภท"><?= h(flood_opt_name($types, $t['team_type'])) ?></td>
                    <td data-label="โทร"><?= $t['phone'] ? '<a href="tel:' . h($t['phone']) . '">' . h(flood_format_phone($t['phone'])) . '</a>' : '—' ?></td>
                    <td data-label="พื้นที่"><?= $t['amphoe_name'] ? 'อ.' . h($t['amphoe_name']) : '—' ?></td>
                    <td data-label="ยานพาหนะ"><?= h((string) $t['vehicles']) ?></td>
                    <td data-label="งานค้าง"><?= (int) $t['open_jobs'] ? '<a href="' . URL . 'flood/help?team_id=' . (int) $t['team_id'] . '">' . (int) $t['open_jobs'] . '</a>' : '0' ?></td>
                    <td data-label="สมาชิก"><?= (int) $t['member_count'] ?></td>
                    <td data-label="สถานะ"><?= (int) $t['is_active'] === 1 ? '<span class="label label-success">ใช้งาน</span>' : '<span class="label label-default">ปิด</span>' ?></td>
                    <td data-label=""><button type="button" class="btn btn-default btn-sm js-team-edit" data-team="<?= (int) $t['team_id'] ?>"><i class="fa fa-pencil"></i> แก้ไข</button></td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="teamModal" tabindex="-1" role="dialog">
    <div class="modal-dialog">
        <form class="modal-content" id="teamForm">
            <div class="modal-header">
                <h4 class="modal-title" id="teamTitle">เพิ่มทีม</h4>
                <button type="button" class="close" data-dismiss="modal" aria-label="ปิด">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="team_id" id="tId" />
                <div class="form-group">
                    <label for="tName" class="required">ชื่อทีม</label>
                    <input type="text" class="form-control" id="tName" name="name" maxlength="150" />
                </div>
                <div class="row">
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label for="tType">ประเภท</label>
                            <select class="form-control" id="tType" name="team_type"><?= flood_options($types, 'rescue') ?></select>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label for="tPhone">เบอร์โทรทีม</label>
                            <input type="tel" class="form-control" id="tPhone" name="phone" maxlength="20" />
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label for="tAmphoe">พื้นที่รับผิดชอบหลัก</label>
                    <div class="pv-am-pair">
                        <?= flood_province_select($this->provinces, array('class' => 'form-control', 'data-pv-for' => 'tAmphoe', 'aria-label' => 'จังหวัด'), '', '— จังหวัด —') ?>
                        <select class="form-control" id="tAmphoe" name="amphoe_code">
                            <option value="">ทุกพื้นที่</option>
                            <?= flood_amphoe_options($this->amphoes, '', 'อ.') ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="tVehicles">ยานพาหนะ / อุปกรณ์</label>
                    <input type="text" class="form-control" id="tVehicles" name="vehicles" maxlength="255" placeholder="เช่น เรือท้องแบน 2 ลำ, รถยกสูง 1 คัน" />
                </div>
                <div class="form-group">
                    <label for="tNote">หมายเหตุ</label>
                    <input type="text" class="form-control" id="tNote" name="note" maxlength="255" />
                </div>
                <label class="checkbox-inline"><input type="checkbox" id="tActive" checked /> เปิดใช้งาน (รับใบงานได้)</label>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">ยกเลิก</button>
                <button type="submit" class="btn btn-primary" id="tSave"><i class="fa fa-save"></i> บันทึก</button>
            </div>
        </form>
    </div>
</div>

<script>
    window.TEAMS = <?= flood_js(array_map(function ($t) {
        return array('team_id' => (int) $t['team_id'], 'name' => $t['name'], 'team_type' => $t['team_type'], 'phone' => (string) $t['phone'],
            'amphoe_code' => (string) $t['amphoe_code'], 'vehicles' => (string) $t['vehicles'], 'note' => (string) $t['note'], 'is_active' => (int) $t['is_active']);
    }, $rows)) ?>;
</script>
