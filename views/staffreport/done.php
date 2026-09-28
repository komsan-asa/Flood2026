<div class="pub-page">
    <div class="pub-done">
        <div class="ok-icon"><i class="fa fa-check"></i></div>
        <h1>ได้รับข้อมูลแล้ว ขอบคุณครับ/ค่ะ</h1>
        <?php if ($this->last) { ?>
        <div>ผู้แจ้ง: <b><?= h($this->last['name']) ?></b> · <?= h(flood_thai_date($this->last['at'], true)) ?></div>
        <?php } ?>
        <div class="next">
            <b>ขั้นตอนต่อไป</b>
            <ul style="margin:6px 0 0;padding-left:20px">
                <li>ทีมที่ดูแลบุคลากรจะ<b>โทรกลับ</b>ตามเบอร์ที่ให้ไว้ กรุณาเปิดเครื่องไว้</li>
                <li>สถานการณ์เปลี่ยน (น้ำขึ้น / ได้ที่พักแล้ว / มาทำงานได้แล้ว) <b>แจ้งซ้ำได้</b> ระบบรวมเป็นรายชื่อเดียวกัน</li>
                <li>เจ็บป่วยฉุกเฉิน โทร <a href="tel:<?= h(EMERGENCY_PHONE) ?>"><b><?= h(EMERGENCY_PHONE) ?></b></a>
                    · ติดอยู่ในน้ำ / ต้องอพยพด่วน สายด่วน ปภ. <a href="tel:<?= h(flood_ddpm_phone()) ?>"><b><?= h(flood_ddpm_phone()) ?></b></a></li>
            </ul>
        </div>
        <div class="links">
            <a href="<?= URL ?>staffreport" class="pub-btn pub-btn-blue"><i class="fa fa-pencil"></i> แจ้งเพิ่ม / แก้ไขข้อมูล</a>
            <a href="<?= URL ?>" class="pub-btn pub-btn-outline"><i class="fa fa-map-o"></i> ดูแผนที่สถานการณ์น้ำ</a>
        </div>
    </div>
</div>
