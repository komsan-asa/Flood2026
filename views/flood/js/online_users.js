/* ผู้ใช้งานออนไลน์ (ผู้ดูแลระบบ) — ตารางรีเฟรชเองทุก 15 วินาที */
$(function () {
    'use strict';

    var esc = Flood.esc;
    var me = (window.OU || {}).me;

    function render(rows) {
        var $b = $('#ouBody').empty();
        if (!rows.length) {
            $b.append('<tr><td colspan="9"><div class="empty-state"><i class="fa fa-user-times"></i>ไม่มีผู้ใช้งานในช่วงเวลานี้</div></td></tr>');
        }
        rows.forEach(function (u) {
            var online = u.is_online === 1 || u.is_online === '1' || u.is_online === true;
            $b.append('<tr>'
                + '<td data-label="สถานะ">' + (online
                    ? '<span class="online-dot"></span> <b class="text-success">ออนไลน์</b>'
                    : '<span class="offline-dot"></span> <span class="text-muted">ออฟไลน์</span>') + '</td>'
                + '<td data-label="ชื่อ" class="nowrap"><b>' + esc(u.name) + '</b><div class="small-muted">' + esc(u.loginname) + '</div></td>'
                + '<td data-label="สิทธิ์" class="nowrap">' + esc(u.role_label) + '</td>'
                + '<td data-label="หน่วยงาน">' + esc(u.team_name || u.org_name || '—') + '</td>'
                + '<td data-label="หน้าที่เปิด">' + (online && u.last_page ? '<i class="fa fa-desktop"></i> ' + esc(u.last_page) : '<span class="text-muted">—</span>') + '</td>'
                + '<td data-label="ใช้งานล่าสุด" class="nowrap">' + esc(u.ago_label) + '<div class="small-muted">' + esc(u.last_seen_th) + '</div></td>'
                + '<td data-label="เข้าสู่ระบบ" class="nowrap">' + esc(u.last_login_th || '—') + '</td>'
                + '<td data-label="IP" class="small-muted">' + esc(u.last_ip || '—') + '</td>'
                + '<td data-label="" class="nowrap">' + (online && +u.user_id !== me
                    ? '<button type="button" class="btn btn-default btn-sm js-kick nowrap" data-id="' + u.user_id + '" data-name="' + esc(u.name) + '" title="ให้ออกจากระบบ"><i class="fa fa-sign-out"></i> ให้ออก</button>'
                    : '') + '</td>'
                + '</tr>');
        });
        $('#ouCount').text(rows.length + ' คน');
    }

    function reload() {
        Flood.get('flood/onlineUsersData', $('#ouFilter').serialize(), { silent: true }).then(function (o) {
            render(o.rows || []);
            $('#ouOnline').text(o.summary.online_now);
            $('#ou24').text(o.summary.active_24h);
            $('#ouTotal').text(o.summary.total_active);
            $('#ouTs').text('(ล่าสุด ' + o.ts + ')');
        });
    }

    render((window.OU || {}).rows || []);
    setInterval(function () {
        if ($('#ouAuto').is(':checked') && !document.hidden) {
            reload();
        }
    }, 15000);

    $(document).on('click', '.js-kick', function () {
        var $b = $(this);
        Flood.confirm({
            title: 'ให้ออกจากระบบ',
            message: 'ให้ ' + $b.data('name') + ' ออกจากระบบทุกเครื่อง? (ผู้ใช้ล็อกอินใหม่ได้ตามปกติ)',
            okText: 'ให้ออกจากระบบ', okClass: 'btn-danger'
        }, function () {
            return Flood.post('flood/kickUser', { user_id: $b.data('id') }).then(function (o) {
                Flood.toast(o.msg, 'success');
                reload();
            });
        });
    });
});
