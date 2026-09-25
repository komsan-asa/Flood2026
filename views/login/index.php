<div class="login-wrap">
    <form class="login-card" id="loginForm" autocomplete="on">
        <div class="login-brand">
            <div class="login-logo">🌊</div>
            <div class="login-org"><?= h(DEPARTMENT_NAME) ?></div>
            <h1>เข้าสู่ระบบ <?= h(SHORT_NAME_SYSTEM) ?></h1>
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

        <p class="text-center small-muted" style="margin:16px 0 0">
            <a href="<?= URL ?>"><i class="fa fa-map-o"></i> กลับหน้าแผนที่สถานการณ์น้ำ</a>
            <?php if (defined('SYSTEM_ADMIN_CONTACT') && SYSTEM_ADMIN_CONTACT !== '') { ?>
            <br>ลืมรหัสผ่าน ติดต่อ <?= h(SYSTEM_ADMIN_CONTACT) ?>
            <?php } ?>
        </p>
    </form>
</div>
