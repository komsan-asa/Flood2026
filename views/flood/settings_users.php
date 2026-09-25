<?php
$f = $this->userFilters;
$rows = $this->userRows;
$roles = flood_role_labels();
$roleDesc = flood_role_descriptions();
$isSuper = $this->currentRole === 'super_admin';
$roleChoices = $roles;
if (!$isSuper) {
    unset($roleChoices['super_admin']);
}
?>
<div class="flood-page-header">
    <h2 class="flood-page-title"><i class="fa fa-users"></i> ผู้ใช้งาน</h2>
    <button type="button" class="btn btn-primary js-user-edit" data-user="0"><i class="fa fa-user-plus"></i> เพิ่มผู้ใช้</button>
</div>

<form class="filter-bar" method="get" action="<?= URL ?>flood/settingsUsers" style="margin-bottom:12px">
    <input type="search" name="q" value="<?= h($f['q']) ?>" class="form-control grow" placeholder="ค้นหาชื่อ / ชื่อผู้ใช้ / หน่วยงาน / เบอร์โทร" />
    <select name="role" class="form-control"><?= flood_options($roles, $f['role'], 'ทุกสิทธิ์') ?></select>
    <select name="status" class="form-control"><?= flood_options(array('active' => 'เปิดใช้งาน', 'inactive' => 'ปิดใช้งาน'), $f['status'], 'ทุกสถานะ') ?></select>
    <button type="submit" class="btn btn-default"><i class="fa fa-search"></i> ค้นหา</button>
</form>

<div class="flood-card">
    <div class="table-responsive">
        <table class="table table-hover table-cards" style="margin:0">
            <thead>
                <tr><th>ชื่อ</th><th>ชื่อผู้ใช้</th><th>สิทธิ์</th><th>หน่วยงาน / ทีม</th><th>โทร</th><th>เข้าใช้ล่าสุด</th><th>สถานะ</th><th style="width:1%"></th></tr>
            </thead>
            <tbody>
                <?php if (!$rows) { ?>
                <tr><td colspan="8"><div class="empty-state"><i class="fa fa-users"></i>ไม่พบผู้ใช้</div></td></tr>
                <?php } ?>
                <?php foreach ($rows as $u) {
                    $locked = !$isSuper && $u['role'] === 'super_admin';
                ?>
                <tr<?= (int) $u['is_active'] === 1 ? '' : ' style="opacity:.6"' ?>>
                    <td data-label="ชื่อ">
                        <span class="<?= $u['is_online'] ? 'online-dot' : 'offline-dot' ?>" title="<?= $u['is_online'] ? 'ออนไลน์' : 'ออฟไลน์' ?>"></span>
                        <b><?= h($u['name']) ?></b>
                        <?php if ((int) $u['must_change_password'] === 1) { ?><div class="small-muted"><i class="fa fa-key"></i> ยังไม่ได้เปลี่ยนรหัสผ่าน</div><?php } ?>
                    </td>
                    <td data-label="ชื่อผู้ใช้"><?= h($u['loginname']) ?></td>
                    <td data-label="สิทธิ์"><span class="label <?= flood_is_admin_role($u['role']) ? 'label-danger' : ($u['role'] === 'team' ? 'label-info' : 'label-primary') ?>"><?= h(flood_role_label($u['role'])) ?></span></td>
                    <td data-label="หน่วยงาน"><?= h((string) $u['org_name']) ?><?php if ($u['team_name']) { ?><div class="small-muted"><i class="fa fa-ambulance"></i> <?= h($u['team_name']) ?></div><?php } ?></td>
                    <td data-label="โทร"><?= $u['phone'] ? h(flood_format_phone($u['phone'])) : '—' ?></td>
                    <td data-label="เข้าใช้ล่าสุด" class="nowrap"><?= $u['last_login_at'] ? h(flood_thai_date($u['last_login_at'])) : '<span class="text-muted">ยังไม่เคย</span>' ?></td>
                    <td data-label="สถานะ"><?= (int) $u['is_active'] === 1 ? '<span class="label label-success">ใช้งาน</span>' : '<span class="label label-default">ปิด</span>' ?></td>
                    <td data-label="">
                        <?php if (!$locked) { ?>
                        <button type="button" class="btn btn-default btn-sm js-user-edit" data-user="<?= (int) $u['user_id'] ?>"><i class="fa fa-pencil"></i> แก้ไข</button>
                        <?php } ?>
                    </td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>

<div class="flood-card">
    <div class="flood-card-header">สิทธิ์การใช้งาน</div>
    <div class="flood-card-body">
        <dl class="kv">
            <?php foreach ($roles as $code => $label) { ?>
            <dt><?= h($label) ?></dt><dd><?= h($roleDesc[$code]) ?></dd>
            <?php } ?>
        </dl>
    </div>
</div>

<div class="modal fade" id="userModal" tabindex="-1" role="dialog">
    <div class="modal-dialog">
        <form class="modal-content" id="userForm" autocomplete="off">
            <div class="modal-header">
                <h4 class="modal-title" id="userTitle">เพิ่มผู้ใช้</h4>
                <button type="button" class="close" data-dismiss="modal" aria-label="ปิด">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="user_id" id="uId" />
                <div class="row">
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label for="uLogin" class="required">ชื่อผู้ใช้ (ภาษาอังกฤษ)</label>
                            <input type="text" class="form-control" id="uLogin" name="loginname" maxlength="50" autocapitalize="off" />
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label for="uPass" id="uPassLabel">รหัสผ่านเริ่มต้น</label>
                            <input type="text" class="form-control" id="uPass" name="password" maxlength="100" autocomplete="new-password" />
                            <div class="help-block" id="uPassHelp">อย่างน้อย 8 ตัว — ผู้ใช้ต้องเปลี่ยนเองตอนเข้าใช้ครั้งแรก</div>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label for="uName" class="required">ชื่อ-สกุล</label>
                    <input type="text" class="form-control" id="uName" name="name" maxlength="150" />
                </div>
                <div class="row">
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label for="uRole" class="required">สิทธิ์</label>
                            <select class="form-control" id="uRole" name="role"><?= flood_options($roleChoices, 'officer') ?></select>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="form-group" id="uTeamGroup">
                            <label for="uTeam">ทีมที่สังกัด</label>
                            <select class="form-control" id="uTeam" name="team_id">
                                <option value="">—</option>
                                <?php foreach ($this->teams as $t) { ?>
                                <option value="<?= (int) $t['team_id'] ?>"><?= h($t['name']) ?></option>
                                <?php } ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label for="uOrg">หน่วยงาน</label>
                            <input type="text" class="form-control" id="uOrg" name="org_name" maxlength="200" />
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label for="uPhone">เบอร์โทร</label>
                            <input type="tel" class="form-control" id="uPhone" name="phone" maxlength="20" />
                        </div>
                    </div>
                </div>
                <label class="checkbox-inline"><input type="checkbox" id="uActive" checked /> เปิดใช้งาน</label>
                <div class="help-block" id="uRoleDesc"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">ยกเลิก</button>
                <button type="submit" class="btn btn-primary" id="uSave"><i class="fa fa-save"></i> บันทึก</button>
            </div>
        </form>
    </div>
</div>

<script>
    window.USERS = <?= flood_js(array_map(function ($u) {
        return array('user_id' => (int) $u['user_id'], 'loginname' => $u['loginname'], 'name' => $u['name'], 'role' => $u['role'],
            'team_id' => $u['team_id'] !== null ? (int) $u['team_id'] : '', 'org_name' => (string) $u['org_name'], 'phone' => (string) $u['phone'],
            'is_active' => (int) $u['is_active']);
    }, $rows)) ?>;
    window.ROLE_DESC = <?= flood_js($roleDesc) ?>;
    window.ME_ID = <?= (int) $this->currentUserId ?>;
</script>
