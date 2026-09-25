<?php $hot = defined('HOTLINE_PHONE') ? HOTLINE_PHONE : ''; ?>
<div class="pub-page">
    <div class="pub-done">
        <div class="ok-icon"><i class="fa fa-check"></i></div>
        <h1>ได้รับคำขอความช่วยเหลือแล้ว</h1>
        <?php if ($this->ref !== '') { ?>
        <div>เลขอ้างอิง (จดไว้ใช้ติดตามเรื่อง)</div>
        <div class="pub-ref"><?= h($this->ref) ?></div>
        <?php } ?>
        <div class="next">
            <b>ขั้นตอนต่อไป</b>
            <ul style="margin:6px 0 0;padding-left:20px">
                <li>เจ้าหน้าที่จะ<b>โทรกลับ</b>ตามเบอร์ที่แจ้งเพื่อยืนยันก่อนออกช่วยเหลือ — กรุณาเปิดเครื่องไว้</li>
                <li>ถ้าอาการแย่ลงหรือฉุกเฉินวิกฤต โทร <a href="tel:<?= h(EMERGENCY_PHONE) ?>"><b><?= h(EMERGENCY_PHONE) ?></b></a> ทันที ไม่ต้องรอ</li>
                <li>แจ้งเหตุน้ำท่วม / สาธารณภัย สายด่วน ปภ. โทร <a href="tel:<?= h(flood_ddpm_phone()) ?>"><b><?= h(flood_ddpm_phone()) ?></b></a></li>
                <?php if ($hot !== '') { ?>
                <li>สอบถามศูนย์ประสาน: <a href="tel:<?= h($hot) ?>"><b><?= h(flood_format_phone($hot)) ?></b></a></li>
                <?php } ?>
                <li>ไม่ต้องส่งคำขอซ้ำ — ติดตามสถานะได้จากปุ่มด้านล่าง</li>
            </ul>
        </div>
        <div class="links">
            <a href="<?= URL ?>sos/status" class="pub-btn pub-btn-blue"><i class="fa fa-search"></i> ติดตามสถานะคำขอ</a>
            <a href="<?= URL ?>" class="pub-btn pub-btn-outline"><i class="fa fa-map-o"></i> ดูแผนที่สถานการณ์น้ำ</a>
        </div>
    </div>
</div>
