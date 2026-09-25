<?php
$f = $this->logFilters;
$res = $this->logResult;
$events = array('login' => 'เข้าสู่ระบบ', 'failed' => 'เข้าไม่สำเร็จ', 'logout' => 'ออกจากระบบ', 'kicked' => 'ถูกให้ออก/บัญชีถูกปิด');
$evClass = array('login' => 'label-success', 'failed' => 'label-danger', 'logout' => 'label-default', 'kicked' => 'label-warning');
$days = array(1 => 'วันนี้', 7 => '7 วัน', 30 => '30 วัน', 90 => '90 วัน', 0 => 'ทั้งหมด');
?>
<div class="flood-page-header">
    <h2 class="flood-page-title"><i class="fa fa-history"></i> ประวัติการเข้าใช้งาน</h2>
</div>

<form class="filter-bar" method="get" action="<?= URL ?>flood/loginLog" style="margin-bottom:12px">
    <input type="search" name="q" value="<?= h($f['q']) ?>" class="form-control grow" placeholder="ชื่อผู้ใช้ / ชื่อ / IP" />
    <select name="event" class="form-control"><?= flood_options($events, $f['event'], 'ทุกเหตุการณ์') ?></select>
    <select name="days" class="form-control"><?= flood_options($days, $f['days']) ?></select>
    <button type="submit" class="btn btn-default"><i class="fa fa-search"></i> แสดง</button>
</form>

<div class="flood-card">
    <div class="table-responsive">
        <table class="table table-condensed table-cards" style="margin:0">
            <thead><tr><th>เวลา</th><th>เหตุการณ์</th><th>ชื่อผู้ใช้</th><th>ชื่อ</th><th>สาเหตุ</th><th>IP</th><th>อุปกรณ์</th></tr></thead>
            <tbody>
                <?php if (!$res['rows']) { ?>
                <tr><td colspan="7"><div class="empty-state">ไม่มีรายการ</div></td></tr>
                <?php } ?>
                <?php foreach ($res['rows'] as $r) { ?>
                <tr>
                    <td data-label="เวลา" class="nowrap"><?= h(flood_thai_date($r['created_at'])) ?></td>
                    <td data-label="เหตุการณ์"><span class="label <?= h(isset($evClass[$r['event']]) ? $evClass[$r['event']] : 'label-default') ?>"><?= h(isset($events[$r['event']]) ? $events[$r['event']] : $r['event']) ?></span></td>
                    <td data-label="ชื่อผู้ใช้"><?= h($r['loginname']) ?></td>
                    <td data-label="ชื่อ"><?= h($r['name']) ?><?= $r['role'] ? ' <span class="small-muted">(' . h(flood_role_label($r['role'])) . ')</span>' : '' ?></td>
                    <td data-label="สาเหตุ"><?= h($r['reason']) ?></td>
                    <td data-label="IP" class="small-muted"><?= h($r['ip']) ?></td>
                    <td data-label="อุปกรณ์" class="small-muted" title="<?= h($r['user_agent']) ?>"><?= h(mb_strimwidth($r['user_agent'], 0, 40, '…')) ?></td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>
<?php $pg = $res; $pgPath = 'flood/loginLog'; include __DIR__ . '/_pagination.php'; ?>
