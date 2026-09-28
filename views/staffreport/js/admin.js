/* ตั้งรหัส / ปิดรับ ฟอร์มบุคลากร + คัดลอกข้อความ */
$(function () {
    'use strict';

    $(document).on('click', '.js-sf-code', function () {
        var $b = $(this);
        var mode = $b.data('mode');
        var code = $.trim($('#sfCustom').val());
        var go = function () {
            Flood.busy($b, true);
            Flood.post('staffreport/setCode', { mode: mode, code: code }).then(function () {
                setTimeout(function () { window.location.reload(); }, 900);
            }, function () {
                Flood.busy($b, false);
            });
        };
        if (mode === 'custom' && code.replace(/[^A-Za-z0-9]/g, '').length < 4) {
            Flood.toast('กรุณาพิมพ์รหัส 4–12 ตัว (ตัวเลขหรือตัวอักษรอังกฤษ)', 'danger');
            return;
        }
        if (mode === 'random' && !$('#sfCode').length) {
            go();   // ยังปิดรับอยู่ — เปิดรับได้เลย
            return;
        }
        Flood.confirm({
            title: mode === 'off' ? 'ปิดรับฟอร์ม?' : 'เปลี่ยนรหัส?',
            html: mode === 'off' ? 'ลิงก์และรหัสเดิมจะใช้ไม่ได้ทันที จนกว่าจะตั้งรหัสใหม่'
                : 'ลิงก์/รหัสเดิมที่ส่งในกลุ่มไลน์จะใช้ไม่ได้ทันที — ต้องส่งข้อความใหม่ให้บุคลากร',
            okText: mode === 'off' ? 'ปิดรับ' : 'เปลี่ยนรหัส'
        }, function () { go(); return true; });
    });

    $(document).on('click', '.js-sf-copy', function () {
        var el = $($(this).data('target'))[0];
        if (!el) {
            return;
        }
        var text = el.value;
        var ok = function () { Flood.toast('คัดลอกแล้ว', 'success'); };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(ok, function () { el.select(); document.execCommand('copy'); ok(); });
        } else {
            el.select();
            document.execCommand('copy');
            ok();
        }
    });
});
