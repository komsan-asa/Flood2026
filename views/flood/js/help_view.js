/* ใบงานขอความช่วยเหลือ — ปุ่มเปลี่ยนสถานะ + แผนที่ตำแหน่ง */
$(function () {
    'use strict';

    var hv = window.HELP_VIEW || {};
    var PRI_COLOR = { urgent: '#dc2626', high: '#f59e0b', normal: '#64748b' };

    if (hv.lat !== null && $('#hvMap').length) {
        var map = FloodMap.create('hvMap', { center: [hv.lat, hv.lng], zoom: 16 });
        (hv.zones || []).forEach(function (z) { FloodMap.zoneLayer(z).addTo(map); });
        L.marker([hv.lat, hv.lng], { icon: FloodMap.pinIcon(PRI_COLOR[hv.priority] || '#64748b', 'fa-life-ring') }).addTo(map);
        if (hv.accuracy_m) {
            L.circle([hv.lat, hv.lng], { radius: hv.accuracy_m, color: '#1f78c1', weight: 1, fillOpacity: 0.06 }).addTo(map);
        }
    }

    var confirmText = {
        cancel: 'ยืนยันยกเลิกใบงานนี้?',
        done: 'ยืนยันว่าช่วยเหลือเรียบร้อยแล้ว?',
        reopen: 'เปิดใบงานนี้อีกครั้ง?'
    };

    $('#hvActions').on('click', '.js-act', function () {
        var $btn = $(this);
        var action = $btn.data('action');
        var data = { help_id: hv.help_id, action: action };
        var $note = $('#hvActions .js-note[data-for="' + action + '"]');
        data.note = $note.length ? $.trim($note.val()) : '';
        if (action === 'assign') {
            data.team_id = $('#hvTeam').val();
            if (!data.team_id) {
                Flood.toast('กรุณาเลือกทีม', 'danger');
                return;
            }
        }
        if (action === 'verify') {
            data.priority = $('#hvVerifyPriority').val();
        }
        if (action === 'priority') {
            data.priority = $('#hvPriority').val();
        }
        if (action === 'cancel' && !data.note) {
            Flood.toast('กรุณาระบุเหตุผลที่ยกเลิก', 'danger');
            $note.focus();
            return;
        }
        if (action === 'note' && !data.note) {
            Flood.toast('กรุณาพิมพ์บันทึก', 'danger');
            $note.focus();
            return;
        }
        var run = function () {
            Flood.busy($btn, true, 'กำลังบันทึก…');
            return Flood.post('flood/helpAction', data).then(function (o) {
                Flood.toast(o.msg, 'success');
                setTimeout(function () { window.location.reload(); }, 500);
            }, function () {
                Flood.busy($btn, false);
            });
        };
        if (confirmText[action]) {
            Flood.confirm({ title: 'ยืนยัน', message: confirmText[action], okClass: action === 'cancel' ? 'btn-danger' : 'btn-primary' }, function () {
                run();
            });
        } else {
            run();
        }
    });
});
