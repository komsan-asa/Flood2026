$(function () {
    'use strict';

    // จำเฉพาะชื่อผู้ใช้ — ไม่เก็บรหัสผ่านไว้ในเบราว์เซอร์ (เครื่องในศูนย์มักใช้ร่วมกันหลายคน)
    var KEY = 'flood_login_user';
    try {
        var saved = localStorage.getItem(KEY);
        if (saved) {
            $('#username').val(saved);
            $('#rememberUser').prop('checked', true);
            $('#password').focus();
        } else {
            $('#username').focus();
        }
    } catch (e) {
        $('#username').focus();
    }

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
