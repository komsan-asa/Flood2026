<?php
$f = $this->staffFilters;
$res = $this->staffResult;
$rows = $res['rows'];
$s = $this->staffSummary;
$levels = flood_staff_levels();
$flags = flood_staff_flags();
$follow = flood_staff_follow_options();
$sources = $this->staffSources;
$srcName = array();
foreach ($sources as $i => $src) {
    $srcName[(int) $src['source_id']] = 'ฟอร์ม ' . ($i + 1);
}
$qs = function ($extra) use ($f) {
    return URL . 'flood/staff?' . http_build_query(array_filter(array_merge($f, $extra)));
};
$exportUrl = URL . 'flood/staffExport?' . http_build_query(array_filter($f));
?>
<div class="flood-page-header">
    <h2 class="flood-page-title"><i class="fa fa-user-md"></i> บุคลากรที่ได้รับผลกระทบ</h2>
    <div class="flood-page-actions">
        <a href="<?= URL ?>staffreport/admin" class="btn btn-default"><i class="fa fa-wpforms"></i> ฟอร์มให้บุคลากรแจ้ง</a>
        <a href="<?= h($exportUrl) ?>" class="btn btn-default"><i class="fa fa-file-excel-o"></i> ส่งออก CSV</a>
        <button type="button" class="btn btn-primary js-staff-sync" data-id="0"><i class="fa fa-refresh"></i> ดึงคำตอบใหม่ทุกฟอร์ม</button>
    </div>
</div>
<p class="text-muted staff-lead"><i class="fa fa-lock"></i> ข้อมูลภายในโรงพยาบาล — เห็นเฉพาะเจ้าหน้าที่ศูนย์และผู้ดูแลระบบ · รวมคำตอบจากแบบสำรวจ <?= count($sources) ?> ฟอร์ม เป็นรายชื่อเดียว (จับคู่คนเดียวกันด้วยเบอร์โทร แล้วจึงชื่อ-สกุล) · ไม่เก็บเลขบัตรประชาชน</p>

<?php if (!$this->staffReady) { ?>
<div class="alert alert-danger">ยังไม่มีตาราง flood_staff และระบบสร้างเองไม่ได้ — ให้ผู้ดูแลรัน <code>php sql/apply_schema.php 17</code></div>
<?php return; } ?>

<?php if ((int) $s['total'] === 0) { ?>
<div class="alert alert-info"><i class="fa fa-info-circle"></i> ยังไม่มีข้อมูล — กด <b>ดึงคำตอบใหม่ทุกฟอร์ม</b> เพื่อนำเข้าจาก Google Sheet ครั้งแรก</div>
<?php } ?>

<div class="flood-kpi-grid">
    <a class="flood-kpi-card" href="<?= URL ?>flood/staff"><i class="fa fa-users kpi-icon"></i>
        <div class="kpi-value"><?= (int) $s['total'] ?></div><div class="kpi-label">บุคลากรที่ตอบแบบสำรวจ</div>
        <div class="kpi-sub"><?= (int) $s['responses'] ?> คำตอบจากทุกฟอร์ม</div></a>
    <a class="flood-kpi-card<?= $s['severe'] ? ' kpi-danger' : '' ?>" href="<?= h($qs(array('level' => 'severe', 'page' => null))) ?>"><i class="fa fa-exclamation-triangle kpi-icon"></i>
        <div class="kpi-value"><?= (int) $s['severe'] ?></div><div class="kpi-label">ผลกระทบรุนแรง</div>
        <div class="kpi-sub">บ้านท่วม · ถูกตัดขาด · มาทำงานไม่ได้ · ไม่มีที่พัก</div></a>
    <a class="flood-kpi-card<?= $s['moderate'] ? ' kpi-warn' : '' ?>" href="<?= h($qs(array('level' => 'moderate'))) ?>"><i class="fa fa-road kpi-icon"></i>
        <div class="kpi-value"><?= (int) $s['moderate'] ?></div><div class="kpi-label">ผลกระทบปานกลาง</div>
        <div class="kpi-sub">ถนนถูกปิด เดินทางลำบาก / กลับบ้านไม่ได้</div></a>
    <a class="flood-kpi-card<?= $s['waiting'] ? ' kpi-danger' : ' kpi-ok' ?>" href="<?= h($qs(array('level' => 'affected', 'follow' => 'new'))) ?>"><i class="fa fa-phone kpi-icon"></i>
        <div class="kpi-value"><?= (int) $s['waiting'] ?></div><div class="kpi-label">ได้รับผลกระทบ ยังไม่ติดตาม</div>
        <div class="kpi-sub">รุนแรง + ปานกลาง ที่สถานะ "ยังไม่ติดตาม"</div></a>
</div>

<div class="staff-flag-row">
    <?php foreach ($flags as $code => $fl) { $n = (int) $s[$code]; ?>
    <a href="<?= h($qs(array('flag' => $f['flag'] === $code ? '' : $code))) ?>" class="notice-chip<?= $f['flag'] === $code ? ' on' : '' ?>">
        <i class="fa <?= h($fl['icon']) ?>"></i> <?= h($fl['name']) ?> <b><?= $n ?></b>
    </a>
    <?php } ?>
</div>

<?php $ssS = $s; $ssD = $this->staffDepts; $ssLink = true; include __DIR__ . '/_staff_summary.php'; ?>

<form class="filter-bar" method="get" action="<?= URL ?>flood/staff" style="margin-bottom:12px">
    <input type="search" name="q" value="<?= h($f['q']) ?>" class="form-control grow" placeholder="ค้นหาชื่อ / เบอร์โทร / กลุ่มงาน / ตำแหน่ง / ที่พัก" />
    <select name="level" class="form-control" aria-label="ระดับผลกระทบ">
        <option value="">ทุกระดับ</option>
        <option value="affected"<?= $f['level'] === 'affected' ? ' selected' : '' ?>>ได้รับผลกระทบ (รุนแรง + ปานกลาง)</option>
        <?= flood_options($levels, $f['level']) ?>
    </select>
    <select name="dept" class="form-control" aria-label="กลุ่มงาน">
        <option value="">ทุกกลุ่มงาน</option>
        <?php foreach ($this->staffDepts as $d) { ?>
        <option value="<?= h($d['department']) ?>"<?= $f['dept'] === $d['department'] ? ' selected' : '' ?>><?= h($d['department']) ?> (<?= (int) $d['hit'] ?>/<?= (int) $d['n'] ?>)</option>
        <?php } ?>
    </select>
    <select name="follow" class="form-control" aria-label="การติดตาม"><?= flood_options($follow, $f['follow'], 'ทุกสถานะการติดตาม') ?></select>
    <select name="source" class="form-control" aria-label="ฟอร์ม">
        <option value="">ทุกฟอร์ม</option>
        <?php foreach ($sources as $i => $src) { ?>
        <option value="<?= (int) $src['source_id'] ?>"<?= $f['source'] === (int) $src['source_id'] ? ' selected' : '' ?>>ฟอร์ม <?= $i + 1 ?>: <?= h(mb_strimwidth($src['name'], 0, 40, '…')) ?></option>
        <?php } ?>
    </select>
    <?php if ($f['flag'] !== '') { ?><input type="hidden" name="flag" value="<?= h($f['flag']) ?>" /><?php } ?>
    <button type="submit" class="btn btn-default"><i class="fa fa-search"></i> ค้นหา</button>
    <?php if (array_filter($f)) { ?><a href="<?= URL ?>flood/staff" class="btn btn-link">ล้างตัวกรอง</a><?php } ?>
</form>

<div class="flood-card">
    <div class="flood-card-head staff-count">พบ <b><?= (int) $res['total'] ?></b> คน</div>
    <div class="table-responsive">
        <table class="table table-hover table-cards" style="margin:0">
            <thead>
                <tr>
                    <th>ชื่อ-สกุล / ตำแหน่ง</th>
                    <th>ติดต่อ</th>
                    <th>ผลกระทบ</th>
                    <th>สถานการณ์ / ความต้องการ</th>
                    <th>การติดตาม</th>
                    <th style="width:1%"></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows) { ?>
                <tr><td colspan="6"><div class="empty-state"><i class="fa fa-user-md"></i>ไม่พบข้อมูล</div></td></tr>
                <?php } ?>
                <?php foreach ($rows as $r) {
                    $lv = isset($levels[$r['level']]) ? $levels[$r['level']] : $levels['unknown'];
                    $situ = array_filter(array($r['impact'] ?: $r['victim'], $r['shift_status'], $r['work_status']));
                    $need = array_filter(array(
                        $r['has_shelter'] ? 'ที่พัก: ' . $r['has_shelter'] : '',
                        $r['help_need'] ? 'ต้องการ: ' . $r['help_need'] : '',
                        $r['toiletries'] ? 'ของใช้: ' . $r['toiletries'] : '',
                    ));
                ?>
                <tr class="staff-row lv-<?= h($r['level']) ?>">
                    <td data-label="ชื่อ-สกุล"><b><?= h(trim($r['prefix'] . ' ' . $r['full_name'])) ?></b>
                        <div class="small-muted"><?= h(trim($r['position'] . ($r['position'] && $r['department'] ? ' · ' : '') . $r['department'])) ?></div>
                        <div class="small-muted staff-src"><?php foreach (array_filter(explode(',', $r['sources'])) as $sid) { ?><span><?= h(isset($srcName[(int) $sid]) ? $srcName[(int) $sid] : '#' . $sid) ?></span><?php } ?>
                            · ตอบล่าสุด <?= h(flood_ago($r['last_at'])) ?></div>
                    </td>
                    <td data-label="ติดต่อ" class="nowrap">
                        <?php if ($r['phone']) { ?><a href="tel:<?= h($r['phone']) ?>"><i class="fa fa-phone"></i> <?= h(flood_format_phone($r['phone'])) ?></a><?php } ?>
                        <?php if ($r['phone2']) { ?><div class="small-muted">สำรอง <?= h($r['phone2']) ?></div><?php } ?>
                        <?php if ($r['addr_now']) { ?><div class="small-muted staff-clip" title="<?= h($r['addr_now']) ?>"><i class="fa fa-home"></i> <?= h($r['addr_now']) ?></div><?php } ?>
                    </td>
                    <td data-label="ผลกระทบ"><?= flood_status_label($levels, $r['level']) ?>
                        <div class="staff-flags"><?php foreach (array_filter(explode(',', $r['flags'])) as $k) { if (isset($flags[$k])) { ?>
                            <span class="tag"><i class="fa <?= h($flags[$k]['icon']) ?>"></i> <?= h($flags[$k]['name']) ?></span>
                        <?php } } ?></div>
                    </td>
                    <td data-label="สถานการณ์">
                        <?php foreach ($situ as $t) { ?><div class="staff-clip" title="<?= h($t) ?>"><?= h($t) ?></div><?php } ?>
                        <?php foreach ($need as $t) { ?><div class="small-muted staff-clip" title="<?= h($t) ?>"><?= h($t) ?></div><?php } ?>
                    </td>
                    <td data-label="การติดตาม"><?= flood_status_label($follow, $r['follow_status']) ?>
                        <?php if ($r['follow_note']) { ?><div class="small-muted staff-clip" title="<?= h($r['follow_note']) ?>"><?= h($r['follow_note']) ?></div><?php } ?>
                        <?php if ($r['followed_at']) { ?><div class="small-muted"><?= h(flood_ago($r['followed_at'])) ?></div><?php } ?>
                    </td>
                    <td data-label="" class="nowrap">
                        <button type="button" class="btn btn-primary btn-sm js-staff-view" data-id="<?= (int) $r['staff_id'] ?>"><i class="fa fa-folder-open-o"></i> ดู / ติดตาม</button>
                    </td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>
<?php $pg = $res; $pgPath = 'flood/staff'; include __DIR__ . '/_pagination.php'; ?>

<!-- แหล่งข้อมูล (Google Sheet) -->
<div class="flood-card staff-sources">
    <div class="flood-card-head"><b><i class="fa fa-table"></i> แหล่งข้อมูล (Google Form / Sheet)</b>
        <?php if ($this->staffIsAdmin) { ?><button type="button" class="btn btn-default btn-sm js-staff-src" data-id="0"><i class="fa fa-plus"></i> เพิ่มฟอร์ม</button><?php } ?>
    </div>
    <div class="table-responsive">
        <table class="table table-cards" style="margin:0">
            <thead><tr><th>ฟอร์ม</th><th>คำตอบในระบบ</th><th>ดึงล่าสุด</th><th style="width:1%"></th></tr></thead>
            <tbody>
            <?php foreach ($sources as $i => $src) { $isForm = strpos((string) $src['sheet_url'], 'docs.google.com') === false;   // ฟอร์มในระบบ (staffreport) ?>
                <tr class="<?= (int) $src['is_active'] || $isForm ? '' : 'text-muted' ?>">
                    <td data-label="ฟอร์ม"><b>ฟอร์ม <?= $i + 1 ?></b> · <?= h($src['name']) ?><?= $isForm ? ' <span class="label label-info">ฟอร์มในระบบ</span>' : ((int) $src['is_active'] ? '' : ' <span class="label label-default">ปิดอยู่</span>') ?>
                        <div class="small-muted"><?php if ($isForm) { ?><a href="<?= URL ?>staffreport/admin">ลิงก์ / รหัสของฟอร์ม <i class="fa fa-cog"></i></a><?php } else { ?><a href="<?= h($src['sheet_url']) ?>" target="_blank" rel="noopener">เปิด Google Sheet <i class="fa fa-external-link"></i></a><?php } ?></div></td>
                    <td data-label="คำตอบ"><?= (int) $src['resp_total'] ?> แถว</td>
                    <td data-label="ดึงล่าสุด">
                        <?php if ($isForm) { ?><span class="text-muted">รับทันทีเมื่อบุคลากรส่ง</span><?php } elseif ($src['last_sync_at']) { ?><?= h(flood_ago($src['last_sync_at'])) ?><?php if ($src['last_new'] !== null && !$src['last_error']) { ?> · ใหม่ <?= (int) $src['last_new'] ?><?php } ?><?php } else { ?><span class="text-muted">ยังไม่เคย</span><?php } ?>
                        <?php if ($src['last_error']) { ?><div class="text-danger small"><i class="fa fa-exclamation-circle"></i> <?= h($src['last_error']) ?></div><?php } ?>
                    </td>
                    <td data-label="" class="nowrap">
                        <?php if ((int) $src['is_active']) { ?><button type="button" class="btn btn-default btn-sm js-staff-sync" data-id="<?= (int) $src['source_id'] ?>" data-name="<?= h($src['name']) ?>" data-url="<?= h($src['sheet_url']) ?>" title="ดึงจาก Google Sheet"><i class="fa fa-refresh"></i> ดึง</button><?php } ?>
                        <?php if (!$isForm) { ?>
                        <button type="button" class="btn btn-default btn-sm js-staff-upload" data-id="<?= (int) $src['source_id'] ?>" data-name="<?= h($src['name']) ?>" title="อัปโหลดไฟล์ CSV"><i class="fa fa-upload"></i> CSV</button>
                        <?php if ($this->staffIsAdmin) { ?>
                        <button type="button" class="btn btn-default btn-sm js-staff-src" data-id="<?= (int) $src['source_id'] ?>" data-name="<?= h($src['name']) ?>" data-url="<?= h($src['sheet_url']) ?>" title="แก้ไข"><i class="fa fa-pencil"></i></button>
                        <button type="button" class="btn btn-default btn-sm js-staff-src-toggle" data-id="<?= (int) $src['source_id'] ?>" title="<?= (int) $src['is_active'] ? 'ปิดการดึง' : 'เปิดใช้' ?>"><i class="fa <?= (int) $src['is_active'] ? 'fa-pause' : 'fa-play' ?>"></i></button>
                        <?php } ?>
                        <?php } else { ?>
                        <a class="btn btn-default btn-sm" href="<?= URL ?>staffreport/admin"><i class="fa fa-key"></i> ลิงก์ / รหัส</a>
                        <?php } ?>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
    <div class="flood-card-body small-muted">ปุ่ม <b>ดึง</b> ให้เบราว์เซอร์ของท่านอ่านชีตจาก Google แล้วส่งเข้าระบบ (เซิร์ฟเวอร์โรงพยาบาลออกอินเทอร์เน็ตไม่ได้) — ถ้าตั้งชีตเป็น "จำกัด" (แนะนำ เพราะมีข้อมูลส่วนบุคคล) ต้องล็อกอิน Google บัญชีที่มีสิทธิ์ดูชีตในเบราว์เซอร์นี้ หรือดาวน์โหลดชีตเป็น .csv แล้วกดปุ่ม <b>CSV</b> · นำเข้าซ้ำได้ คำตอบที่เคยนำเข้าแล้วไม่ซ้ำ · ถ้าระบบแยกคนเดียวกันเป็น 2 รายการ (ชื่อสะกดต่าง + เบอร์ต่าง) รวมได้ในหน้ารายละเอียด</div>
</div>

<!-- รายละเอียด + ติดตาม -->
<div class="modal fade" id="staffModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title"><span id="stName"></span> <small id="stLevel"></small></h4>
                <button type="button" class="close" data-dismiss="modal" aria-label="ปิด">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="stId" />
                <div id="stHead" class="staff-head"></div>
                <div class="staff-follow">
                    <label>สถานะการติดตาม</label>
                    <div class="chip-grid cols-3" style="margin-bottom:8px">
                        <?php foreach ($follow as $code => $o) { ?>
                        <label class="chip"><input type="radio" name="st_follow" value="<?= h($code) ?>" />
                            <span class="chip-body"><span class="chip-title"><i class="fa <?= h($o['icon']) ?>"></i> <?= h($o['name']) ?></span></span></label>
                        <?php } ?>
                    </div>
                    <textarea class="form-control" id="stNote" rows="2" maxlength="2000" placeholder="บันทึก เช่น โทรแล้ว จัดที่พักหอพยาบาลชั้น 3 / ส่งถุงยังชีพ"></textarea>
                    <div class="small-muted" id="stFollowed" style="margin-top:4px"></div>
                </div>
                <h5 class="staff-sec">คำตอบจากแบบสำรวจ</h5>
                <div id="stResps"></div>
                <details class="staff-merge">
                    <summary>คนเดียวกันแต่ขึ้นเป็น 2 รายการ?</summary>
                    <div class="filter-bar" style="margin-top:8px">
                        <input type="number" class="form-control" id="stMergeFrom" min="1" placeholder="เลขรายการที่ซ้ำ (#)" />
                        <button type="button" class="btn btn-default" id="stMerge"><i class="fa fa-compress"></i> รวมเข้ากับรายการนี้</button>
                    </div>
                    <div class="small-muted">เลขรายการอยู่หลังชื่อในหัวกล่องนี้ (#) — คำตอบทั้งหมดของรายการที่ซ้ำจะย้ายมาที่นี่ แล้วลบรายการนั้น</div>
                </details>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">ปิด</button>
                <button type="button" class="btn btn-primary" id="stSave"><i class="fa fa-save"></i> บันทึกการติดตาม</button>
            </div>
        </div>
    </div>
</div>

<!-- อัปโหลด CSV -->
<div class="modal fade" id="staffUploadModal" tabindex="-1" role="dialog">
    <div class="modal-dialog">
        <form class="modal-content" id="staffUploadForm" enctype="multipart/form-data">
            <div class="modal-header">
                <h4 class="modal-title">อัปโหลด CSV: <span id="suName"></span></h4>
                <button type="button" class="close" data-dismiss="modal" aria-label="ปิด">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="source_id" id="suId" />
                <input type="hidden" name="_csrf" value="<?= h(flood_csrf_token()) ?>" />
                <ol class="small-muted" style="padding-left:18px">
                    <li>เปิด Google Sheet ของฟอร์มนี้ → <b>ไฟล์ → ดาวน์โหลด → ค่าที่คั่นด้วยจุลภาค (.csv)</b></li>
                    <li>เลือกไฟล์ที่ได้ด้านล่าง — นำเข้าไฟล์เดิมซ้ำได้ คำตอบที่มีแล้วจะไม่ซ้ำ</li>
                </ol>
                <input type="file" name="csv" accept=".csv,text/csv" class="form-control" required />
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">ยกเลิก</button>
                <button type="submit" class="btn btn-primary"><i class="fa fa-upload"></i> นำเข้า</button>
            </div>
        </form>
    </div>
</div>
