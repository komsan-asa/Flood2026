<?php
$code = (string) $this->code;
$st = $this->stats;
$form = URL . 'staffreport';
$org = defined('HOSPITAL_NAME') && HOSPITAL_NAME ? HOSPITAL_NAME : 'โรงพยาบาล';   // ตั้ง define('HOSPITAL_NAME', '…') ใน config ได้
?>
<div class="sf-wrap">
<div class="flood-page-header">
    <h2 class="flood-page-title"><i class="fa fa-wpforms"></i> ฟอร์มให้บุคลากรแจ้งปัญหา / ขอความช่วยเหลือ</h2>
    <div class="flood-page-actions">
        <a href="<?= URL ?>flood/staff" class="btn btn-default"><i class="fa fa-user-md"></i> รายชื่อบุคลากรที่ได้รับผลกระทบ</a>
    </div>
</div>
<p class="text-muted"><i class="fa fa-lock"></i> บุคลากรเปิดลิงก์แล้วใส่รหัสสั้น (ไม่ต้องมีบัญชี) · คำตอบเข้ารายชื่อ "บุคลากรที่ได้รับผลกระทบ" ทันที
    รวมกับคำตอบจาก Google Form ด้วยเบอร์โทร · ระดับผลกระทบใช้กติกาเดียวกัน · เปลี่ยนรหัส = ลิงก์/รหัสเดิมใช้ไม่ได้ทันที</p>

<?php if (!$this->ready) { ?>
<div class="alert alert-danger">ยังไม่มีตาราง flood_staff / flood_setting และระบบสร้างเองไม่ได้ — ให้ผู้ดูแลรัน <code>php sql/apply_schema.php 17</code> แล้วเปิดหน้านี้ใหม่</div>
</div>
<?php return; } ?>

<div class="sf-admin-grid">
    <div class="flood-card">
        <div class="flood-card-head"><b><i class="fa fa-key"></i> สถานะ / รหัส</b></div>
        <div class="flood-card-body">
            <?php if ($code === '') { ?>
            <div class="sf-status off"><i class="fa fa-pause-circle"></i> ปิดรับอยู่ — ยังไม่มีรหัส</div>
            <?php } else { ?>
            <div class="sf-status on"><i class="fa fa-check-circle"></i> เปิดรับ · รหัสปัจจุบัน <span class="sf-code-big" id="sfCode"><?= h($code) ?></span></div>
            <?php } ?>
            <div class="sf-actions">
                <button type="button" class="btn btn-primary js-sf-code" data-mode="random"><i class="fa fa-random"></i> <?= $code === '' ? 'เปิดรับ (สุ่มรหัส 6 หลัก)' : 'สุ่มรหัสใหม่' ?></button>
                <div class="input-group sf-custom">
                    <input type="text" class="form-control" id="sfCustom" maxlength="12" placeholder="ตั้งรหัสเอง 4–12 ตัว" />
                    <span class="input-group-btn"><button type="button" class="btn btn-default js-sf-code" data-mode="custom">ตั้งรหัส</button></span>
                </div>
                <?php if ($code !== '') { ?>
                <button type="button" class="btn btn-default js-sf-code" data-mode="off"><i class="fa fa-pause"></i> ปิดรับฟอร์ม</button>
                <?php } ?>
            </div>
            <p class="small text-muted" style="margin:10px 0 0">คำตอบผ่านฟอร์มนี้: วันนี้ <b><?= (int) $st['today'] ?></b> · ทั้งหมด <b><?= (int) $st['total'] ?></b>
                <?php if ($st['last']) { ?> · ล่าสุด <?= h(flood_ago($st['last'])) ?><?php } ?></p>
        </div>
    </div>

    <?php if ($code !== '') {
        $direct = $form . '?k=' . rawurlencode($code);
        $msg = "📣 บุคลากร" . $org . "ที่ได้รับผลกระทบจากน้ำท่วม\n"
            . "แจ้งผลกระทบ / ปัญหา / ขอความช่วยเหลือ (ที่พัก อาหาร รถรับส่ง ฯลฯ) ได้ที่\n"
            . $form . "\n"
            . "รหัส: " . $code . "\n"
            . "ใช้เวลาราว 1 นาที ทีมที่ดูแลจะโทรกลับ · เจ็บป่วยฉุกเฉินโทร " . EMERGENCY_PHONE . "\n"
            . "(ขอให้ส่งเฉพาะในกลุ่มบุคลากร ไม่เผยแพร่ภายนอก)";
    ?>
    <div class="flood-card">
        <div class="flood-card-head"><b><i class="fa fa-share-alt"></i> ส่งให้บุคลากร</b></div>
        <div class="flood-card-body">
            <label>ข้อความสำหรับกลุ่มไลน์ (ลิงก์ + รหัสแยกกัน)</label>
            <textarea class="form-control sf-msg" id="sfMsg" rows="7" readonly><?= h($msg) ?></textarea>
            <div class="sf-actions">
                <button type="button" class="btn btn-success js-sf-copy" data-target="#sfMsg"><i class="fa fa-copy"></i> คัดลอกข้อความ</button>
                <a class="btn btn-default" href="<?= h($form) ?>" target="_blank" rel="noopener"><i class="fa fa-external-link"></i> เปิดฟอร์ม</a>
            </div>
            <label style="margin-top:12px">ลิงก์ที่ใส่รหัสไว้แล้ว (กดแล้วเข้าได้ทันที — ส่งเฉพาะคนที่ไว้ใจได้)</label>
            <div class="input-group">
                <input type="text" class="form-control" id="sfDirect" value="<?= h($direct) ?>" readonly />
                <span class="input-group-btn"><button type="button" class="btn btn-default js-sf-copy" data-target="#sfDirect"><i class="fa fa-copy"></i></button></span>
            </div>
        </div>
    </div>
    <?php } ?>
</div>

<div class="flood-card">
    <div class="flood-card-head"><b><i class="fa fa-list-ul"></i> คำถามในฟอร์ม → ข้อมูลในรายชื่อบุคลากร</b></div>
    <div class="flood-card-body small">
        <ol style="margin:0;padding-left:18px">
            <li>ชื่อ-สกุล · เบอร์โทร · กลุ่มงาน/หน่วยงาน · ตำแหน่ง (จับคู่คนเดิมด้วยเบอร์ แล้วจึงชื่อ)</li>
            <li>ผลกระทบ: ที่อยู่อาศัยถูกน้ำท่วม (รุนแรง) · ถนนถูกปิดกั้น (ปานกลาง) · อยู่ในพื้นที่เสี่ยง (เล็กน้อย) · ไม่เป็นผู้ประสบภัย</li>
            <li>การมาปฏิบัติงาน: ปกติ · ยากลำบาก (ปานกลาง) · มาไม่ได้ (รุนแรง + ป้าย "มาทำงานไม่ได้")</li>
            <li>หลังลงเวร: กลับบ้านไม่ได้ (ป้าย "ถูกตัดขาด/กลับบ้านไม่ได้") · ยังไม่มีที่พัก (รุนแรง + ป้าย "ต้องการที่พักด่วน")</li>
            <li>ความช่วยเหลือที่ต้องการ (ป้าย "ขอความช่วยเหลือ") · ปัญหาที่พบ · ที่อยู่ + พิกัด · รูป 3 รูป (เปิดดูได้เฉพาะเจ้าหน้าที่)</li>
        </ol>
        <p style="margin:8px 0 0">คนที่เคยติดตามจบแล้ว (ช่วยเหลือแล้ว / ไม่ต้องการ) แล้วแจ้งเข้ามาใหม่ว่ายังต้องการความช่วยเหลือ → กลับไปอยู่ "ยังไม่ติดตาม" พร้อมบันทึกในช่องติดตาม · ระดับผลกระทบคิดจากคำตอบที่รุนแรงที่สุดของคนนั้น (กติกาเดิมของหน้าบุคลากร) — แจ้งซ้ำว่าดีขึ้นแล้ว ระดับไม่ลดเอง ให้ทีมปรับสถานะติดตามแทน</p>
    </div>
</div>
</div>
