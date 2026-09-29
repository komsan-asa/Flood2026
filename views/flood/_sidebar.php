<?php
$User = flood_session_user();
$role = flood_normalize_role(isset($User['role']) ? $User['role'] : '');
$settingsTabs = array('settingsUsers', 'zoneLevels', 'onlineUsers', 'loginLog', 'auditLog');
?>
<aside class="flood-sidebar" id="floodSidebar" aria-label="เมนูหลัก">
    <div class="flood-sidebar-logo">
        <span class="logo-mark">🌊</span>
        <div>
            <?= h(SHORT_NAME_SYSTEM) ?>
            <small><?= h(flood_region_name()) ?></small>
        </div>
        <button type="button" class="flood-menu-close" id="floodMenuClose" aria-label="ปิดเมนู"><i class="fa fa-times"></i></button>
    </div>
    <nav class="flood-sidebar-nav">
        <?php if (flood_can_menu($role, 'hospital') || flood_can_menu($role, 'sat') || flood_can_menu($role, 'staff_summary') || flood_can_menu($role, 'manpower_summary') || flood_can_menu($role, 'vulnerable')) { ?>
        <div class="flood-nav-group g-hosp">
        <div class="flood-nav-section flood-nav-divider"><span>โรงพยาบาล</span></div>
        <?php if (flood_can_menu($role, 'hospital')) { ?>
        <a href="<?= URL ?>sat/overview" class="flood-nav-item<?= $activeTab === 'hospital' ? ' active' : '' ?>">
            <i class="fa fa-hospital-o"></i><span>ภาพรวม (โรงพยาบาล)</span>
        </a>
        <?php } ?>
        <?php if (flood_can_menu($role, 'sat')) { ?>
        <a href="<?= URL ?>sat" class="flood-nav-item<?= $activeTab === 'sat' ? ' active' : '' ?>">
            <i class="fa fa-crosshairs"></i><span>ห้องสถานการณ์ SAT</span>
        </a>
        <?php } ?>
        <?php if (flood_can_menu($role, 'sat')) { ?>
        <a href="<?= URL ?>sat/utility" class="flood-nav-item<?= $activeTab === 'utility' ? ' active' : '' ?>">
            <i class="fa fa-tint"></i><span>สาธารณูปโภค</span>
        </a>
        <?php } ?>
        <?php if (flood_can_menu($role, 'sat')) { ?>
        <a href="<?= URL ?>sat/refer" class="flood-nav-item<?= $activeTab === 'refer' ? ' active' : '' ?>">
            <i class="fa fa-ambulance"></i><span>Refer ช่วงอุทกภัย</span>
        </a>
        <?php } ?>
        <?php if (flood_can_menu($role, 'staff_summary')) { ?>
        <a href="<?= URL ?>flood/staff" class="flood-nav-item<?= $activeTab === 'staff' ? ' active' : '' ?>">
            <i class="fa fa-user-md"></i><span>บุคลากรที่ได้รับผลกระทบ</span>
        </a>
        <?php } ?>
        <?php if (flood_can_menu($role, 'staff')) { ?>
        <a href="<?= URL ?>staffreport/admin" class="flood-nav-item<?= $activeTab === 'staffForm' ? ' active' : '' ?>">
            <i class="fa fa-wpforms"></i><span>ฟอร์มบุคลากรแจ้ง</span>
        </a>
        <?php } ?>
        <?php if (flood_can_menu($role, 'manpower_summary')) { ?>
        <a href="<?= URL ?>flood/manpower" class="flood-nav-item<?= $activeTab === 'manpower' ? ' active' : '' ?>">
            <i class="fa fa-id-badge"></i><span>อัตรากำลังรายเวร</span>
        </a>
        <?php } ?>
        <?php if (flood_can_menu($role, 'vulnerable')) { ?>
        <a href="<?= URL ?>flood/vulnerable" class="flood-nav-item<?= $activeTab === 'vulnerable' ? ' active' : '' ?>">
            <i class="fa fa-wheelchair"></i><span>กลุ่มเปราะบาง</span>
        </a>
        <?php } ?>
        </div>
        <?php } ?>
        <?php if (flood_can_menu($role, 'stats') || flood_can_menu($role, 'zones') || flood_can_menu($role, 'reports') || flood_can_menu($role, 'help') || flood_can_menu($role, 'teams')) { ?>
        <?php $navExtOpen = true; ?>
        <div class="flood-nav-group g-ext">
        <div class="flood-nav-section flood-nav-divider"><span>สถานการณ์ภายนอก</span></div>
        <?php } ?>
        <?php if (flood_can_menu($role, 'stats')) { ?>
        <a href="<?= URL ?>flood" class="flood-nav-item<?= $activeTab === 'stats' ? ' active' : '' ?>">
            <i class="fa fa-tachometer"></i><span>ภาพรวม</span>
        </a>
        <a href="<?= URL ?>flood/notices" class="flood-nav-item<?= $activeTab === 'notices' ? ' active' : '' ?>">
            <i class="fa fa-newspaper-o"></i><span>ข้อมูลที่ควรรู้</span>
        </a>
        <?php } ?>
        <?php if (flood_can_menu($role, 'zones')) { ?>
        <a href="<?= URL ?>flood/zones" class="flood-nav-item<?= $activeTab === 'zones' ? ' active' : '' ?>">
            <i class="fa fa-map"></i><span>พื้นที่ประกาศ</span>
        </a>
        <?php } ?>
        <?php if (flood_can_menu($role, 'reports')) { ?>
        <a href="<?= URL ?>flood/reports" class="flood-nav-item<?= $activeTab === 'reports' ? ' active' : '' ?>">
            <i class="fa fa-tint"></i><span>รายงานจากประชาชน</span>
            <span class="nav-count hidden" data-count="reports"></span>
        </a>
        <?php } ?>
        <?php if (flood_can_menu($role, 'help')) { ?>
        <a href="<?= URL ?>flood/help" class="flood-nav-item<?= $activeTab === 'help' ? ' active' : '' ?>">
            <i class="fa fa-life-ring"></i><span><?= $role === 'team' ? 'ใบงานของทีม' : 'ขอความช่วยเหลือ' ?></span>
            <span class="nav-count hidden" data-count="help"></span>
        </a>
        <?php } ?>
        <?php if (flood_can_menu($role, 'teams')) { ?>
        <a href="<?= URL ?>flood/teams" class="flood-nav-item<?= $activeTab === 'teams' ? ' active' : '' ?>">
            <i class="fa fa-ambulance"></i><span>ทีมช่วยเหลือ</span>
        </a>
        <?php } ?>
        <?php if (!empty($navExtOpen)) { ?>
        </div>
        <?php } ?>
        <?php if (flood_can_menu($role, 'settings')) { ?>
        <div class="flood-nav-group g-admin">
        <div class="flood-nav-section flood-nav-divider"><span>ผู้ดูแลระบบ</span></div>
        <a href="<?= URL ?>flood/settingsUsers" class="flood-nav-item<?= $activeTab === 'settingsUsers' ? ' active' : '' ?>">
            <i class="fa fa-users"></i><span>ผู้ใช้งาน</span>
        </a>
        <a href="<?= URL ?>flood/zoneLevels" class="flood-nav-item<?= $activeTab === 'zoneLevels' ? ' active' : '' ?>">
            <i class="fa fa-sliders"></i><span>ระดับพื้นที่</span>
        </a>
        <a href="<?= URL ?>flood/onlineUsers" class="flood-nav-item<?= $activeTab === 'onlineUsers' ? ' active' : '' ?>">
            <i class="fa fa-signal"></i><span>ผู้ใช้งานออนไลน์</span>
        </a>
        <a href="<?= URL ?>flood/loginLog" class="flood-nav-item<?= $activeTab === 'loginLog' ? ' active' : '' ?>">
            <i class="fa fa-history"></i><span>ประวัติการเข้าใช้งาน</span>
        </a>
        <a href="<?= URL ?>flood/auditLog" class="flood-nav-item<?= $activeTab === 'auditLog' ? ' active' : '' ?>">
            <i class="fa fa-file-text-o"></i><span>ประวัติการแก้ไขข้อมูล</span>
        </a>
        </div>
        <?php } ?>
        <div class="flood-nav-group g-public">
        <div class="flood-nav-section flood-nav-divider"><span>หน้าประชาชน</span></div>
        <a href="<?= URL ?>" class="flood-nav-item" target="_blank" rel="noopener"><i class="fa fa-globe"></i><span>แผนที่สาธารณะ</span></a>
        <a href="<?= URL ?>report" class="flood-nav-item" target="_blank" rel="noopener"><i class="fa fa-map-marker"></i><span>ฟอร์มแจ้งจุดน้ำ</span></a>
        <a href="<?= URL ?>sos" class="flood-nav-item" target="_blank" rel="noopener"><i class="fa fa-bullhorn"></i><span>ฟอร์มขอความช่วยเหลือ</span></a>
        </div>
    </nav>
    <div class="flood-sidebar-footer">
        <a href="<?= URL ?>flood/profile" title="ข้อมูลผู้ใช้"><i class="fa fa-user-circle"></i></a>
        <a href="<?= URL ?>login/logout" title="ออกจากระบบ"><i class="fa fa-sign-out"></i></a>
    </div>
</aside>
