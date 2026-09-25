/* ข้อมูลผู้ใช้ / เปลี่ยนรหัสผ่าน */
$(function () {
    'use strict';

    $('#profileForm').on('submit', function (e) {
        e.preventDefault();
        var $btn = $('#pSave');
        Flood.busy($btn, true);
        Flood.post('flood/saveProfile', $(this).serialize()).then(function (o) {
            Flood.toast(o.msg, 'success');
            Flood.busy($btn, false);
        }, function () {
            Flood.busy($btn, false);
        });
    });

    $('#passwordForm').on('submit', function (e) {
        e.preventDefault();
        if ($('#pNew').val() !== $('#pConfirm').val()) {
            Flood.toast('ยืนยันรหัสผ่านใหม่ไม่ตรงกัน', 'danger');
            return;
        }
        var $btn = $('#pPassSave');
        Flood.busy($btn, true);
        Flood.post('flood/changePassword', $(this).serialize()).then(function (o) {
            Flood.toast(o.msg, 'success');
            setTimeout(function () { window.location.href = o.url; }, 700);
        }, function () {
            Flood.busy($btn, false);
        });
    });
});
