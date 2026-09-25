<?php
$User = flood_session_user();
$userName = isset($User['name']) ? $User['name'] : 'ผู้ใช้งาน';
$roleLabel = flood_role_label(isset($User['role']) ? $User['role'] : '');
$orgName = !empty($User['team_name']) ? $User['team_name'] : (!empty($User['org_name']) ? $User['org_name'] : DEPARTMENT_NAME);
$pageTitle = isset($this->pageTitle) ? $this->pageTitle : '';
$isAdminUser = flood_is_admin_role(isset($User['role']) ? $User['role'] : '');
$autoRefresh = $this->autoRefresh !== false;   // หน้าฟอร์มตั้งเป็น false
?>
<div class="flood-topbar">
    <div class="flood-topbar-left">
        <button type="button" class="flood-menu-toggle" id="floodMenuToggle" aria-controls="floodSidebar" aria-expanded="false">
            <i class="fa fa-bars"></i><span>เมนู</span>
        </button>
        <div class="flood-topbar-title">
            <strong><?= h($orgName) ?></strong>
            <span class="text-muted hidden md:inline"> · <?= h($roleLabel) ?></span>
            <?php if ($pageTitle) { ?>
            <span class="hidden lg:inline"> — <?= h($pageTitle) ?></span>
            <?php } ?>
        </div>
    </div>
    <div class="flood-topbar-right">
        <div class="refresh-pill" id="floodRefresh" data-auto="<?= $autoRefresh ? '1' : '0' ?>" data-default="120"
             title="ข้อมูลบนหน้านี้ ณ <?= h(flood_thai_date(time())) ?>" role="status" aria-live="off">
            <i class="fa fa-clock-o" aria-hidden="true"></i>
            <span class="rf-time">ข้อมูล ณ <b><?= date('H:i:s') ?></b></span>
            <span class="rf-cd" data-rf="cd"></span>
            <?php if ($autoRefresh) { ?>
            <select data-rf="sel" aria-label="รอบรีเฟรชอัตโนมัติ">
                <option value="0">ไม่รีเฟรชเอง</option>
                <option value="60">ทุก 1 นาที</option>
                <option value="120">ทุก 2 นาที</option>
                <option value="300">ทุก 5 นาที</option>
                <option value="600">ทุก 10 นาที</option>
            </select>
            <?php } ?>
            <button type="button" class="rf-now" data-rf="now" title="รีเฟรชตอนนี้" aria-label="รีเฟรชตอนนี้"><i class="fa fa-refresh"></i></button>
        </div>
        <div class="dropdown" id="onlineDropdown">
            <button type="button" class="online-pill" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" title="ผู้ใช้งานที่ออนไลน์อยู่ตอนนี้">
                <span class="online-dot"></span>
                <span>ออนไลน์ <b id="onlineCount">–</b></span>
            </button>
            <div class="dropdown-menu" style="width:300px">
                <div class="online-list-head">
                    ผู้ใช้งานที่ออนไลน์อยู่ <span id="onlineCheckedAt" class="font-normal"></span>
                </div>
                <div class="online-list" id="onlineList">
                    <div class="empty-state" style="padding:16px">กำลังโหลด…</div>
                </div>
                <?php if ($isAdminUser) { ?>
                <div class="border-t border-ink-200 px-3 py-2 text-right">
                    <a href="<?= URL ?>flood/onlineUsers" class="text-xs">ดูทั้งหมด / จัดการ <i class="fa fa-angle-right"></i></a>
                </div>
                <?php } ?>
            </div>
        </div>
        <a href="<?= URL ?>flood/profile" class="flood-user-link" title="ข้อมูลผู้ใช้"><i class="fa fa-user-circle-o"></i> <?= h($userName) ?></a>
        <a href="<?= URL ?>login/logout" class="btn btn-default btn-sm" title="ออกจากระบบ"><i class="fa fa-sign-out"></i></a>
    </div>
</div>
<div class="flood-nav-backdrop hidden" id="floodNavBackdrop"></div>
<?php if (!empty($User['must_change_password']) && (isset($this->activeTab) ? $this->activeTab : '') !== 'profile') { ?>
<div class="alert alert-warning" style="margin:12px 16px 0">กรุณาเปลี่ยนรหัสผ่านก่อนใช้งาน</div>
<?php } ?>
