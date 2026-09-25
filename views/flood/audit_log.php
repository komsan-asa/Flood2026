<?php
$f = $this->logFilters;
$res = $this->logResult;
$actions = array('insert' => 'เพิ่ม', 'update' => 'แก้ไข', 'delete' => 'ลบ');
$acClass = array('insert' => 'label-success', 'update' => 'label-info', 'delete' => 'label-danger');
$days = array(1 => 'วันนี้', 7 => '7 วัน', 30 => '30 วัน', 90 => '90 วัน', 0 => 'ทั้งหมด');
$tables = array(
    'flood_zone' => 'พื้นที่ประกาศ', 'flood_report' => 'รายงานประชาชน', 'flood_help' => 'ใบงานช่วยเหลือ',
    'flood_vulnerable' => 'ทะเบียนกลุ่มเปราะบาง', 'flood_team' => 'ทีมช่วยเหลือ', 'flood_user' => 'ผู้ใช้งาน', 'flood_attachment' => 'ไฟล์แนบ',
);
?>
<div class="flood-page-header">
    <h2 class="flood-page-title"><i class="fa fa-file-text-o"></i> ประวัติการแก้ไขข้อมูล</h2>
</div>

<form class="filter-bar" method="get" action="<?= URL ?>flood/auditLog" style="margin-bottom:12px">
    <input type="search" name="q" value="<?= h($f['q']) ?>" class="form-control grow" placeholder="ผู้ทำ / เลขแถว / เมนู" />
    <select name="table" class="form-control"><?= flood_options($tables, $f['table'], 'ทุกข้อมูล') ?></select>
    <select name="action" class="form-control"><?= flood_options($actions, $f['action'], 'ทุกการกระทำ') ?></select>
    <select name="days" class="form-control"><?= flood_options($days, $f['days']) ?></select>
    <button type="submit" class="btn btn-default"><i class="fa fa-search"></i> แสดง</button>
</form>

<div class="flood-card">
    <div class="table-responsive">
        <table class="table table-condensed table-hover table-cards" style="margin:0">
            <thead><tr><th>เวลา</th><th>การกระทำ</th><th>ข้อมูล</th><th>แถว</th><th>คอลัมน์ที่เปลี่ยน</th><th>ผู้ทำ</th><th>เมนู</th><th style="width:1%"></th></tr></thead>
            <tbody>
                <?php if (!$res['rows']) { ?>
                <tr><td colspan="8"><div class="empty-state">ไม่มีรายการ</div></td></tr>
                <?php } ?>
                <?php foreach ($res['rows'] as $r) { ?>
                <tr>
                    <td data-label="เวลา" class="nowrap"><?= h(flood_thai_date($r['created_at'])) ?></td>
                    <td data-label="การกระทำ"><span class="label <?= h(isset($acClass[$r['action']]) ? $acClass[$r['action']] : 'label-default') ?>"><?= h(isset($actions[$r['action']]) ? $actions[$r['action']] : $r['action']) ?></span></td>
                    <td data-label="ข้อมูล"><?= h(isset($tables[$r['table_name']]) ? $tables[$r['table_name']] : $r['table_name']) ?></td>
                    <td data-label="แถว">#<?= h($r['row_pk']) ?></td>
                    <td data-label="คอลัมน์" class="small-muted"><?= h(mb_strimwidth($r['changed_cols'], 0, 60, '…')) ?></td>
                    <td data-label="ผู้ทำ"><?= $r['actor_name'] !== '' ? h($r['actor_name']) : '<span class="text-muted">ประชาชน/ระบบ</span>' ?></td>
                    <td data-label="เมนู" class="small-muted"><?= h($r['route']) ?></td>
                    <td data-label=""><button type="button" class="btn btn-default btn-sm js-audit" data-id="<?= (int) $r['audit_id'] ?>">ดู</button></td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>
<?php $pg = $res; $pgPath = 'flood/auditLog'; include __DIR__ . '/_pagination.php'; ?>

<div class="modal fade" id="auditModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title" id="auTitle">รายละเอียด</h4>
                <button type="button" class="close" data-dismiss="modal" aria-label="ปิด">&times;</button>
            </div>
            <div class="modal-body" id="auBody"></div>
        </div>
    </div>
</div>
