<?php
$me = $this->me;
?>
<div class="flood-page-header">
    <h2 class="flood-page-title"><i class="fa fa-user-circle"></i> ข้อมูลผู้ใช้</h2>
</div>

<?php if ($this->mustChange) { ?>
<div class="alert alert-warning"><i class="fa fa-key"></i> <b>กรุณาตั้งรหัสผ่านใหม่ก่อนเริ่มใช้งาน</b> — บัญชีนี้ใช้รหัสผ่านเริ่มต้นหรือรหัสที่ผู้ดูแลตั้งให้</div>
<?php } ?>

<div class="row">
    <div class="col-md-6">
        <div class="flood-card">
            <div class="flood-card-header">ข้อมูลของฉัน</div>
            <div class="flood-card-body">
                <dl class="kv" style="margin-bottom:14px">
                    <dt>ชื่อผู้ใช้</dt><dd><?= h($me['loginname']) ?></dd>
                    <dt>สิทธิ์</dt><dd><?= h(flood_role_label($me['role'])) ?></dd>
                    <?php if ($me['team_name']) { ?><dt>ทีม</dt><dd><?= h($me['team_name']) ?></dd><?php } ?>
                    <dt>เข้าสู่ระบบล่าสุด</dt><dd><?= h(flood_thai_date($me['last_login_at'])) ?></dd>
                </dl>
                <form id="profileForm">
                    <div class="form-group">
                        <label for="pName" class="required">ชื่อ-สกุล</label>
                        <input type="text" class="form-control" id="pName" name="name" maxlength="150" value="<?= h($me['name']) ?>" />
                    </div>
                    <div class="form-group">
                        <label for="pOrg">หน่วยงาน</label>
                        <input type="text" class="form-control" id="pOrg" name="org_name" maxlength="200" value="<?= h((string) $me['org_name']) ?>" />
                    </div>
                    <div class="form-group">
                        <label for="pPhone">เบอร์โทร</label>
                        <input type="tel" class="form-control" id="pPhone" name="phone" maxlength="20" value="<?= h((string) $me['phone']) ?>" />
                    </div>
                    <button type="submit" class="btn btn-primary" id="pSave"<?= $this->mustChange ? ' disabled title="เปลี่ยนรหัสผ่านก่อน"' : '' ?>><i class="fa fa-save"></i> บันทึก</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="flood-card">
            <div class="flood-card-header">เปลี่ยนรหัสผ่าน</div>
            <div class="flood-card-body">
                <form id="passwordForm" autocomplete="off">
                    <input type="text" name="username" value="<?= h($me['loginname']) ?>" autocomplete="username" style="display:none" />
                    <div class="form-group">
                        <label for="pOld" class="required">รหัสผ่านเดิม</label>
                        <input type="password" class="form-control" id="pOld" name="old_password" autocomplete="current-password" />
                    </div>
                    <div class="form-group">
                        <label for="pNew" class="required">รหัสผ่านใหม่</label>
                        <input type="password" class="form-control" id="pNew" name="new_password" autocomplete="new-password" />
                        <div class="help-block">อย่างน้อย 8 ตัวอักษร ไม่ซ้ำรหัสเดิม และไม่เหมือนชื่อผู้ใช้</div>
                    </div>
                    <div class="form-group">
                        <label for="pConfirm" class="required">ยืนยันรหัสผ่านใหม่</label>
                        <input type="password" class="form-control" id="pConfirm" name="confirm_password" autocomplete="new-password" />
                    </div>
                    <button type="submit" class="btn btn-primary" id="pPassSave"><i class="fa fa-key"></i> เปลี่ยนรหัสผ่าน</button>
                </form>
            </div>
        </div>
    </div>
</div>
