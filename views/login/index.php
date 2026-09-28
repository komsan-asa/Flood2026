<?php
$ov = isset($this->ov) ? $this->ov : array();
$hc = !empty($ov['help']) ? $ov['help'] : null;
$zc = !empty($ov['zones']) ? $ov['zones'] : null;
$rc = !empty($ov['reports']) ? $ov['reports'] : null;
$showLogin = !empty($this->notice);

/** แถบแนวนอน: รายการ [name, c] เทียบกับค่ามากสุด */
$bars = function ($rows, $colorFn = null) {
    $max = 0;
    foreach ($rows as $r) {
        $max = max($max, (int) $r['c']);
    }
    $html = '';
    foreach ($rows as $r) {
        $c = (int) $r['c'];
        $w = $max > 0 ? max($c > 0 ? 3 : 0, round($c * 100 / $max)) : 0;
        $col = $colorFn ? $colorFn($r) : '';
        $html .= '<div class="ov-bar-row"><div class="ov-bar-name">' . h($r['name']) . '</div>'
            . '<div class="ov-bar-track"><div class="ov-bar-fill" style="width:' . $w . '%' . ($col ? ';background:' . h($col) : '') . '"></div></div>'
            . '<div class="ov-bar-num">' . number_format($c) . '</div></div>';
    }
    return $html;
};
?>
<div class="ov-wrap">
    <div class="ov-head">
        <h1 class="ov-title"><i class="fa fa-tachometer"></i> ภาพรวมสถานการณ์</h1>
        <p class="ov-sub"><?= h(TITLE_SYSTEM_NAME) ?></p>
        <p class="ov-time"><i class="fa fa-clock-o"></i> ข้อมูล ณ <?= h($ov['updated_th'] ?? '') ?> น. · อัปเดตเองทุก 10 นาที</p>
        <button type="button" class="btn btn-lg btn-primary ov-login-btn" id="ovLoginBtn" aria-expanded="<?= $showLogin ? 'true' : 'false' ?>" aria-controls="ovLogin">
            <i class="fa fa-sign-in"></i> เข้าสู่ระบบเจ้าหน้าที่
        </button>
    </div>

    <div class="ov-login<?= $showLogin ? ' is-open' : '' ?>" id="ovLogin">
        <form class="login-card" id="loginForm" autocomplete="on">
            <div class="login-brand">
                <div class="login-logo">🌊</div>
                <div class="login-org"><?= h(DEPARTMENT_NAME) ?></div>
                <h2>เข้าสู่ระบบ <?= h(SHORT_NAME_SYSTEM) ?></h2>
                <p>สำหรับเจ้าหน้าที่ศูนย์ประสานและทีมช่วยเหลือ</p>
            </div>

            <?php if (!empty($this->notice)) { ?>
            <div class="alert alert-warning"><?= h($this->notice) ?> กรุณาเข้าสู่ระบบใหม่</div>
            <?php } ?>

            <div class="form-group login-field">
                <label for="username">ชื่อผู้ใช้งาน</label>
                <i class="fa fa-user field-icon" aria-hidden="true"></i>
                <input type="text" id="username" name="username" class="form-control" required autocomplete="username" autocapitalize="off" />
            </div>

            <div class="form-group login-field">
                <label for="password">รหัสผ่าน</label>
                <i class="fa fa-lock field-icon" aria-hidden="true"></i>
                <input type="password" id="password" name="password" class="form-control" required autocomplete="current-password" />
                <button type="button" class="password-toggle" id="togglePassword" aria-label="แสดงหรือซ่อนรหัสผ่าน">
                    <i class="fa fa-eye" aria-hidden="true"></i>
                </button>
            </div>

            <label class="checkbox-inline" style="margin-bottom:14px">
                <input type="checkbox" id="rememberUser" /> จำชื่อผู้ใช้ไว้ในเครื่องนี้
            </label>

            <div id="loginAlert" class="alert alert-danger hidden" role="alert"></div>

            <button class="btn btn-lg btn-primary btn-block" type="submit" id="btnLogin">
                <i class="fa fa-sign-in"></i> เข้าสู่ระบบ
            </button>

            <?php if (defined('SYSTEM_ADMIN_CONTACT') && SYSTEM_ADMIN_CONTACT !== '') { ?>
            <p class="text-center small-muted" style="margin:14px 0 0">ลืมรหัสผ่าน ติดต่อ <?= h(SYSTEM_ADMIN_CONTACT) ?></p>
            <?php } ?>
        </form>
    </div>

    <?php if (!empty($this->hosp)) {
        $H = $this->hosp;
        $col = Sat_Model::colors();
        $chip = function ($status, $text = null) use ($col) {
            if (!isset($col[$status])) {
                return '<span class="sat-chip sat-c-none">⚪ ' . h($text !== null ? $text : 'ยังไม่มีข้อมูล') . '</span>';
            }
            return '<span class="sat-chip sat-c-' . h($status) . '">' . $col[$status]['emoji'] . ' ' . h($text !== null ? $text : $col[$status]['name']) . '</span>';
        };
        $S = $H['staffSum'];
        $mp = $H['mp'];
        $fac = $H['fac'];
        $shiftNames = array('M' => 'เช้า', 'A' => 'บ่าย', 'N' => 'ดึก');
        $rtypes = Sat_Model::routeTypes();
        $units = Sat_Model::criticalUnits();
    ?>
    <h2 class="ov-section"><span class="ov-section-num">1</span> <i class="fa fa-hospital-o"></i> โรงพยาบาล
        <small><?= h($H['hosp_name']) ?></small></h2>

    <h3 class="ov-subhead">สถานะโรงพยาบาล</h3>
    <div class="flood-card ov-card">
        <div class="flood-card-body">
            <div class="hosp-status-row">
                <div>
                    <div class="small-muted">SAT ประกาศ</div>
                    <?= $H['declared'] ? $chip($H['declared']['status']) : $chip('', 'ยังไม่ประกาศ') ?>
                    <?php if ($H['declared']) { ?><div class="small-muted"><?= h(flood_thai_date($H['declared']['at'])) ?></div><?php } ?>
                </div>
                <div>
                    <div class="small-muted">ระบบประเมินจากข้อมูล (ข้อเสนอ)</div>
                    <?= $chip($H['suggest']) ?>
                </div>
            </div>
            <?php if ($H['reasons']) { ?>
            <ul class="hosp-reasons">
                <?php foreach ($H['reasons'] as $r) { ?>
                <li><?= isset($col[$r[0]]) ? $col[$r[0]]['emoji'] : '' ?> <?= h($r[1]) ?></li>
                <?php } ?>
            </ul>
            <?php } ?>
        </div>
    </div>

    <div class="flood-kpi-grid ov-kpis">
        <?php if ($S) { ?>
        <div class="flood-kpi-card kpi-danger">
            <div class="kpi-value"><?= (int) ($S['severe'] + $S['moderate']) ?></div>
            <div class="kpi-label">บุคลากรได้รับผลกระทบ</div>
            <div class="kpi-sub">รุนแรง <?= (int) $S['severe'] ?> · ปานกลาง <?= (int) $S['moderate'] ?> · ผู้ตอบ <?= (int) $S['total'] ?></div>
        </div>
        <div class="flood-kpi-card kpi-danger">
            <div class="kpi-value"><?= (int) $S['cant_work'] ?></div>
            <div class="kpi-label">มาทำงานไม่ได้</div>
            <div class="kpi-sub">ถูกตัดขาด / กลับบ้านไม่ได้ <?= (int) $S['stranded'] ?></div>
        </div>
        <div class="flood-kpi-card kpi-warn">
            <div class="kpi-value"><?= (int) $S['need_shelter'] ?></div>
            <div class="kpi-label">ต้องการที่พักด่วน</div>
            <div class="kpi-sub">บ้านถูกน้ำท่วม <?= (int) $S['home_hit'] ?></div>
        </div>
        <div class="flood-kpi-card kpi-warn">
            <div class="kpi-value"><?= (int) $S['waiting'] ?></div>
            <div class="kpi-label">ได้รับผลกระทบ ยังไม่ติดตาม</div>
            <div class="kpi-sub">ขอความช่วยเหลือ <?= (int) $S['need_help'] ?></div>
        </div>
        <?php } ?>
        <div class="flood-kpi-card <?= $mp['ready'] && $mp['short'] ? 'kpi-danger' : 'kpi-info' ?>">
            <div class="kpi-value"><?= $mp['ready'] ? (int) $mp['short'] : '–' ?></div>
            <div class="kpi-label">หน่วยที่ RN ขาดกรอบ (เวร<?= h(isset($shiftNames[$mp['shift']]) ? $shiftNames[$mp['shift']] : '') ?>)</div>
            <div class="kpi-sub">ครบ <?= (int) $mp['ok'] ?> · รอข้อมูล <?= (int) $mp['nodata'] ?> จาก <?= (int) $mp['units'] ?> หน่วย</div>
        </div>
        <div class="flood-kpi-card <?= ($fac['red'] + $fac['orange']) ? 'kpi-warn' : 'kpi-ok' ?>">
            <div class="kpi-value"><?= (int) ($fac['red'] + $fac['orange']) ?> / <?= (int) $fac['count'] ?></div>
            <div class="kpi-label">หน่วยบริการ ปิด / ย้ายจุด</div>
            <div class="kpi-sub">🔴 <?= (int) $fac['red'] ?> · 🟠 <?= (int) $fac['orange'] ?> · 🟡 <?= (int) $fac['yellow'] ?></div>
        </div>
    </div>

    <div class="ov-2col">
        <div>
            <h3 class="ov-subhead">เส้นทาง เข้า / ออก / Refer</h3>
            <div class="flood-card ov-card">
                <div class="flood-card-body">
                    <?php if (!$H['routes']) { ?><div class="small-muted">ยังไม่ได้ตั้งเส้นทาง</div><?php } else { ?>
                    <table class="table table-condensed ov-table">
                        <thead><tr><th>ประเภท</th><th>เส้นทาง</th><th>สถานะ</th></tr></thead>
                        <tbody>
                        <?php foreach ($H['routes'] as $r) { ?>
                        <tr>
                            <td class="nowrap"><?= h(isset($rtypes[$r['rtype']]) ? $rtypes[$r['rtype']]['name'] : $r['rtype']) ?></td>
                            <td><?= h($r['name']) ?><?= $r['is_backup'] ? ' <span class="small-muted">(สำรอง)</span>' : '' ?></td>
                            <td class="nowrap"><?= $chip($r['effective']) ?></td>
                        </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                    <?php } ?>
                </div>
            </div>
        </div>
        <div>
            <h3 class="ov-subhead">บุคลากรหน่วยสำคัญ (4 สี)</h3>
            <div class="flood-card ov-card">
                <div class="flood-card-body">
                    <?php if (!$H['staff']['ready']) { ?><div class="small-muted">ยังไม่มีข้อมูลบุคลากร</div><?php } else { ?>
                    <table class="table table-condensed ov-table">
                        <thead><tr><th>หน่วย</th><th title="ถูกตัดขาด / มาไม่ได้">🔴</th><th title="ต้องใช้รถสูง/เรือ">🟠</th><th title="เดินทางลำบาก">🟡</th><th title="ปกติ">🟢</th></tr></thead>
                        <tbody>
                        <?php foreach ($units as $code => $u) { $x = isset($H['staff']['units'][$code]) ? $H['staff']['units'][$code] : null; ?>
                        <tr>
                            <td><?= h($u['name']) ?></td>
                            <?php if (!$x || !$x['total']) { ?><td colspan="4" class="small-muted">ไม่มีผู้ตอบ</td><?php } else { ?>
                            <td><?= $x['red'] ? '<b class="text-danger">' . (int) $x['red'] . '</b>' : '0' ?></td>
                            <td><?= (int) $x['orange'] ?></td><td><?= (int) $x['yellow'] ?></td><td><?= (int) $x['green'] ?></td>
                            <?php } ?>
                        </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                    <?php } ?>
                </div>
            </div>
        </div>
    </div>
    <?php } ?>

    <h2 class="ov-section"><span class="ov-section-num"><?= !empty($this->hosp) ? '2' : '1' ?></span> <i class="fa fa-map-o"></i> สถานการณ์ภายนอก</h2>

    <?php if ($hc || $zc || $rc) { ?>
    <div class="flood-kpi-grid ov-kpis">
        <?php if ($hc) {
            $helpTotal = $hc['open'] + $hc['done'] + $hc['cancelled'];
        ?>
        <div class="flood-kpi-card kpi-info">
            <i class="fa fa-list-alt kpi-icon"></i>
            <div class="kpi-value"><?= number_format($helpTotal) ?></div>
            <div class="kpi-label">คำขอความช่วยเหลือทั้งหมด</div>
            <div class="kpi-sub">ยังเปิดอยู่ <?= number_format($hc['open']) ?> · ช่วยเหลือแล้ว <?= number_format($hc['done']) ?> · ยกเลิก <?= number_format($hc['cancelled']) ?></div>
        </div>
        <div class="flood-kpi-card kpi-danger">
            <i class="fa fa-bell kpi-icon"></i>
            <div class="kpi-value"><?= number_format($hc['waiting']) ?></div>
            <div class="kpi-label">รอรับเรื่อง</div>
            <div class="kpi-sub">ยังไม่ได้มอบหมายทีม</div>
        </div>
        <div class="flood-kpi-card kpi-danger">
            <i class="fa fa-exclamation-triangle kpi-icon"></i>
            <div class="kpi-value"><?= number_format($hc['urgent_open']) ?></div>
            <div class="kpi-label">ด่วนมาก (ยังเปิดอยู่)</div>
            <div class="kpi-sub">ต้องอพยพ / มีกลุ่มเปราะบาง</div>
        </div>
        <div class="flood-kpi-card kpi-warn">
            <i class="fa fa-spinner kpi-icon"></i>
            <div class="kpi-value"><?= number_format($hc['assigned'] + $hc['in_progress']) ?></div>
            <div class="kpi-label">กำลังช่วยเหลือ</div>
            <div class="kpi-sub">มอบหมายทีมแล้ว <?= number_format($hc['assigned']) ?> · ลงพื้นที่ <?= number_format($hc['in_progress']) ?></div>
        </div>
        <div class="flood-kpi-card kpi-ok">
            <i class="fa fa-check kpi-icon"></i>
            <div class="kpi-value"><?= number_format($hc['done_today']) ?></div>
            <div class="kpi-label">ช่วยเหลือแล้ววันนี้</div>
            <div class="kpi-sub">รับเรื่องวันนี้ <?= number_format($hc['today']) ?></div>
        </div>
        <?php } ?>
        <?php if ($zc) { ?>
        <a class="flood-kpi-card kpi-info" href="<?= URL ?>">
            <i class="fa fa-map kpi-icon"></i>
            <div class="kpi-value"><?= number_format($zc['total']) ?></div>
            <div class="kpi-label">พื้นที่ประกาศน้ำท่วม</div>
            <div class="kpi-sub">ดูบนแผนที่สถานการณ์ ›</div>
        </a>
        <?php } ?>
        <?php if ($rc) { ?>
        <div class="flood-kpi-card kpi-warn">
            <i class="fa fa-tint kpi-icon"></i>
            <div class="kpi-value"><?= number_format($rc['pending']) ?></div>
            <div class="kpi-label">รายงานจากประชาชนรอตรวจ</div>
            <div class="kpi-sub">แจ้งวันนี้ <?= number_format($rc['today']) ?> · ประกาศแล้ว <?= number_format($rc['announced']) ?></div>
        </div>
        <?php } ?>
    </div>
    <?php } ?>

    <?php if ($zc && !empty($ov['levels'])) {
        $rows = array();
        foreach ($ov['levels'] as $code => $l) {
            $rows[] = array('name' => $l['name'], 'c' => isset($zc[$code]) ? $zc[$code] : 0, 'color' => $l['color']);
        }
    ?>
    <div class="flood-card ov-card">
        <div class="flood-card-header">พื้นที่ประกาศตามระดับ</div>
        <div class="ov-bars"><?= $bars($rows, function ($r) { return $r['color']; }) ?></div>
    </div>
    <?php } ?>

    <?php if (!empty($ov['needs'])) { ?>
    <div class="flood-card ov-card">
        <div class="flood-card-header">ความต้องการ (คำขอที่ยังเปิดอยู่)</div>
        <div class="ov-bars"><?= $bars($ov['needs']) ?></div>
    </div>
    <?php } ?>

    <?php if (!empty($ov['amphoe'])) { ?>
    <div class="flood-card ov-card">
        <div class="flood-card-header">คำขอที่ยังเปิดอยู่ ตามอำเภอ</div>
        <div class="ov-bars"><?= $bars($ov['amphoe']) ?></div>
    </div>
    <?php } ?>

    <?php if (!empty($ov['province'])) { ?>
    <div class="flood-card ov-card">
        <div class="flood-card-header">พื้นที่ประกาศ ตามจังหวัด (10 อันดับแรก)</div>
        <div class="ov-bars"><?= $bars($ov['province']) ?></div>
    </div>
    <?php } ?>

    <?php if (!empty($ov['daily'])) {
        $hRows = array();
        $rRows = array();
        foreach ($ov['daily'] as $d) {
            $hRows[] = array('name' => $d['d'], 'c' => $d['help']);
            $rRows[] = array('name' => $d['d'], 'c' => $d['report']);
        }
    ?>
    <div class="ov-2col">
        <div class="flood-card ov-card">
            <div class="flood-card-header">คำขอความช่วยเหลือรายวัน (14 วันล่าสุด)</div>
            <div class="ov-bars"><?= $bars($hRows) ?></div>
        </div>
        <div class="flood-card ov-card">
            <div class="flood-card-header">รายงานจุดน้ำจากประชาชนรายวัน (14 วันล่าสุด)</div>
            <div class="ov-bars"><?= $bars($rRows, function () { return '#0891b2'; }) ?></div>
        </div>
    </div>
    <?php } ?>

    <p class="ov-note"><i class="fa fa-lock"></i> รายละเอียดรายคน (ชื่อ เบอร์โทร ที่อยู่ พิกัดบ้าน) ดูได้เฉพาะเจ้าหน้าที่ที่เข้าสู่ระบบ ·
        <a href="#ovLogin" class="js-ov-login">เข้าสู่ระบบ</a></p>
    <p class="ov-note"><a href="<?= URL ?>"><i class="fa fa-map-o"></i> แผนที่สถานการณ์น้ำ</a> · <a href="<?= URL ?>report">แจ้งจุดน้ำท่วม</a> · <a href="<?= URL ?>sos">ขอความช่วยเหลือ</a></p>
</div>
