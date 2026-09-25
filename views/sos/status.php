<?php
$st = flood_help_statuses();
$r = $this->result;
$actionNames = array(
    'create' => 'ได้รับคำขอ', 'verify' => 'เจ้าหน้าที่โทรยืนยันแล้ว', 'assign' => 'มอบหมายทีมช่วยเหลือแล้ว',
    'start' => 'ทีมกำลังออกช่วยเหลือ', 'done' => 'ช่วยเหลือเรียบร้อย', 'cancel' => 'ปิดเรื่อง', 'reopen' => 'เปิดเรื่องอีกครั้ง',
);
?>
<div class="pub-page">
    <div class="pub-form-wrap" style="padding-bottom:40px">
        <div class="pub-form-head">
            <h1><i class="fa fa-search"></i> ติดตามคำขอความช่วยเหลือ</h1>
            <p>กรอกเลขอ้างอิงที่ได้หลังส่งคำขอ และเบอร์โทรที่ใช้แจ้ง</p>
        </div>
        <form method="get" action="<?= URL ?>sos/status" class="pub-section">
            <div class="form-group">
                <label for="sRef">เลขอ้างอิง</label>
                <input type="text" class="form-control" id="sRef" name="ref" value="<?= h($this->prefillRef) ?>" placeholder="SOS-690924-001" autocapitalize="characters" />
            </div>
            <div class="form-group">
                <label for="sPhone">เบอร์โทรที่ใช้แจ้ง</label>
                <input type="tel" class="form-control" id="sPhone" name="phone" value="<?= h($this->prefillPhone) ?>" inputmode="tel" />
            </div>
            <button type="submit" class="pub-btn pub-btn-blue" style="width:100%"><i class="fa fa-search"></i> ตรวจสถานะ</button>
        </form>

        <?php if ($this->error !== '') { ?>
        <div class="alert alert-warning"><?= h($this->error) ?></div>
        <?php } ?>

        <?php if ($r) { ?>
        <div class="pub-status-card">
            <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;align-items:center">
                <b style="font-size:18px"><?= h($r['ref_code']) ?></b>
                <span class="label <?= h($st[$r['status']]['class']) ?>" style="font-size:14px"><?= h($st[$r['status']]['name']) ?></span>
            </div>
            <?php if (!empty($r['team_name']) && in_array($r['status'], array('assigned', 'in_progress', 'done'), true)) { ?>
            <p style="margin:8px 0 0">ทีมที่รับผิดชอบ: <b><?= h($r['team_name']) ?></b>
                <?php if (!empty($r['team_phone'])) { ?> · โทร <a href="tel:<?= h($r['team_phone']) ?>"><?= h(flood_format_phone($r['team_phone'])) ?></a><?php } ?>
            </p>
            <?php } ?>
            <ul class="timeline" style="margin-top:14px">
                <?php foreach ($r['timeline'] as $t) { ?>
                <li class="timeline-item">
                    <div class="t-head"><?= h(isset($actionNames[$t['action']]) ? $actionNames[$t['action']] : $t['action']) ?></div>
                    <div class="t-meta"><?= h(flood_thai_date($t['created_at'])) ?></div>
                </li>
                <?php } ?>
            </ul>
        </div>
        <?php } ?>

        <div class="links" style="display:flex;flex-direction:column;gap:8px;margin-top:16px">
            <a href="<?= URL ?>sos" class="pub-btn pub-btn-outline">🆘 ส่งคำขอความช่วยเหลือใหม่</a>
            <a href="tel:<?= h(EMERGENCY_PHONE) ?>" class="pub-btn pub-btn-red"><i class="fa fa-phone"></i> ฉุกเฉิน โทร <?= h(EMERGENCY_PHONE) ?></a>
        </div>
    </div>
</div>
