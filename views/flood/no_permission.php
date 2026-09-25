<div class="flood-card" style="max-width:560px;margin:40px auto">
    <div class="flood-card-body text-center" style="padding:32px">
        <div style="font-size:40px;color:#d97706"><i class="fa fa-lock"></i></div>
        <h2 style="font-size:18px;margin:8px 0"><?= h($this->blockedMessage ? $this->blockedMessage : 'ท่านไม่มีสิทธิ์ใช้งานส่วนนี้') ?></h2>
        <?php if (defined('SYSTEM_ADMIN_CONTACT') && SYSTEM_ADMIN_CONTACT !== '') { ?>
        <p class="text-muted">หากต้องการสิทธิ์เพิ่ม ติดต่อผู้ดูแลระบบ <?= h(SYSTEM_ADMIN_CONTACT) ?></p>
        <?php } else { ?>
        <p class="text-muted">หากต้องการสิทธิ์เพิ่ม กรุณาติดต่อผู้ดูแลระบบ</p>
        <?php } ?>
        <a href="<?= URL ?>flood" class="btn btn-primary"><i class="fa fa-tachometer"></i> กลับหน้าภาพรวม</a>
    </div>
</div>
