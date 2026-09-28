$(function () {
    'use strict';

    // จำเฉพาะชื่อผู้ใช้ — ไม่เก็บรหัสผ่านไว้ในเบราว์เซอร์ (เครื่องในศูนย์มักใช้ร่วมกันหลายคน)
    var KEY = 'flood_login_user';
    var saved = '';
    try {
        saved = localStorage.getItem(KEY) || '';
    } catch (e) {
        saved = '';
    }
    if (saved) {
        $('#username').val(saved);
        $('#rememberUser').prop('checked', true);
    }

    // ฟอร์มเข้าสู่ระบบซ่อนอยู่ใต้ปุ่ม "เข้าสู่ระบบเจ้าหน้าที่" (เปิดเองเมื่อมีข้อความแจ้ง หรือลิงก์ #login)
    var $box = $('#ovLogin');
    function openLogin(scroll) {
        $box.addClass('is-open');
        $('#ovLoginBtn').attr('aria-expanded', 'true');
        if (scroll) {
            $box[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        (saved ? $('#password') : $('#username')).focus();
    }
    $('#ovLoginBtn').on('click', function () {
        if ($box.hasClass('is-open')) {
            $box.removeClass('is-open');
            $(this).attr('aria-expanded', 'false');
        } else {
            openLogin(false);
        }
    });
    $(document).on('click', '.js-ov-login', function (e) {
        e.preventDefault();
        openLogin(true);
    });
    if ($box.hasClass('is-open') || /^#(login|ovLogin)$/.test(window.location.hash)) {
        openLogin(false);
    }

    // ตัวเลขภาพรวมอัปเดตเองทุก 10 นาที — ไม่รีเฟรชระหว่างที่เปิดฟอร์มเข้าสู่ระบบ
    setInterval(function () {
        if (!$box.hasClass('is-open') && document.visibilityState !== 'hidden') {
            window.location.reload();
        }
    }, 600000);

    $('#togglePassword').on('click', function () {
        var $i = $('#password');
        var show = $i.attr('type') === 'password';
        $i.attr('type', show ? 'text' : 'password');
        $(this).find('i').toggleClass('fa-eye', !show).toggleClass('fa-eye-slash', show);
    });

    $('#loginForm').on('submit', function (e) {
        e.preventDefault();
        var $btn = $('#btnLogin');
        $('#loginAlert').addClass('hidden');
        Flood.busy($btn, true, 'กำลังเข้าสู่ระบบ…');
        Flood.post('login/run', {
            username: $('#username').val(),
            password: $('#password').val()
        }, { silent: true }).then(function (o) {
            try {
                if ($('#rememberUser').is(':checked')) {
                    localStorage.setItem(KEY, $('#username').val());
                } else {
                    localStorage.removeItem(KEY);
                }
            } catch (err) {
                // ใช้ localStorage ไม่ได้ก็ไม่เป็นไร
            }
            window.location.href = o.redirect || (o.url + 'flood');
        }, function (o) {
            Flood.busy($btn, false);
            $('#loginAlert').removeClass('hidden').text((o && (o.error_log || o.msg)) || 'เข้าสู่ระบบไม่สำเร็จ');
        });
    });
});
