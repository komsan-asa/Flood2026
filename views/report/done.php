<div class="sk-done">
    <div class="sk-done-ic" aria-hidden="true"><i class="fa fa-check"></i></div>
    <h1>ขอบคุณที่ช่วยแจ้งข้อมูล</h1>
    <p>รายงานของท่านเข้าคิวให้เจ้าหน้าที่ตรวจสอบแล้ว</p>
    <?php if ($this->ref !== '') { ?>
    <div class="sk-ref"><small>เลขอ้างอิงรายงาน</small><b><?= h($this->ref) ?></b></div>
    <?php } ?>
    <div class="sk-next">
        <ol>
            <li><span>1</span>เจ้าหน้าที่ตรวจสอบข้อมูล และอาจโทรกลับเพื่อสอบถามเพิ่มเติม</li>
            <li><span>2</span>เมื่อยืนยันแล้ว พื้นที่จะแสดงบนแผนที่สถานการณ์น้ำ</li>
            <li><span>3</span>ถ้าท่านหรือคนในบ้านต้องการความช่วยเหลือ ให้ส่งคำขอความช่วยเหลือแยกต่างหาก</li>
        </ol>
    </div>
    <div class="sk-done-links">
        <a href="<?= URL ?>sos" class="sk-btn sk-btn-red">🆘 ขอความช่วยเหลือ</a>
        <a href="<?= URL ?>report" class="sk-btn sk-btn-soft"><i class="fa fa-plus"></i> แจ้งจุดอื่นเพิ่ม</a>
        <a href="<?= URL ?>" class="sk-btn sk-btn-ghost"><i class="fa fa-map-o"></i> ดูแผนที่สถานการณ์น้ำ</a>
    </div>
</div>
