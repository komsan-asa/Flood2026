/*
 * ส่วนกลางของระบบเจ้าหน้าที่: เมนูมือถือ + สัญญาณออนไลน์ (ทุก 60 วินาที)
 * สัญญาณ ping ทำให้หน้าจอที่เปิดค้างไว้ยังนับเป็น "ออนไลน์" และได้ตัวเลขบนแถบเมนูกลับมาด้วย
 */
$(function () {
    'use strict';

    var $sb = $('#floodSidebar');
    var $bd = $('#floodNavBackdrop');

    function openNav() {
        $sb.addClass('open');
        $bd.removeClass('hidden');
        $('#floodMenuToggle').attr('aria-expanded', 'true');
    }

    function closeNav() {
        $sb.removeClass('open');
        $bd.addClass('hidden');
        $('#floodMenuToggle').attr('aria-expanded', 'false');
    }

    $('#floodMenuToggle').on('click', openNav);
    $('#floodMenuClose, #floodNavBackdrop').on('click', closeNav);

    /* ---------- ผู้ใช้ออนไลน์ ---------- */

    function pageName() {
        return (document.title || '').split(' · ')[0];
    }

    function renderOnline(o) {
        $('#onlineCount').text(o.online);
        $('#onlineCheckedAt').text('(' + o.ts + ' น.)');
        var $l = $('#onlineList').empty();
        if (!o.users || !o.users.length) {
            $l.append('<div class="empty-state" style="padding:16px">ไม่มีผู้ใช้ออนไลน์</div>');
        }
        (o.users || []).forEach(function (u) {
            $l.append(
                '<div class="online-item"><span class="online-dot" style="margin-top:6px"></span><div>'
                + '<div class="who">' + Flood.esc(u.name) + (u.me ? ' <span class="small-muted">(ฉัน)</span>' : '') + '</div>'
                + '<div class="meta">' + Flood.esc(u.role) + (u.team ? ' · ' + Flood.esc(u.team) : '') + '</div>'
                + (u.page ? '<div class="meta"><i class="fa fa-desktop"></i> ' + Flood.esc(u.page) + ' · ' + Flood.esc(u.ago) + '</div>' : '')
                + '</div></div>'
            );
        });
        var c = o.counts || {};
        $('[data-count]').each(function () {
            var k = $(this).data('count');
            var n = c[k] || 0;
            $(this).text(n).toggleClass('hidden', !n);
        });
    }

    function ping() {
        Flood.post('flood/ping', { page: pageName() }, { silent: true }).then(renderOnline);
    }

    if ($('#onlineCount').length) {
        ping();
        setInterval(ping, 60000);
        $('#onlineDropdown').on('show.bs.dropdown', ping);
    }

    /* ---------- ปุ่มย้อนกลับ ---------- */
    $(document).on('click', '.js-back', function (e) {
        e.preventDefault();
        if (document.referrer && document.referrer.indexOf(window.BASE_URL) === 0 && window.history.length > 1) {
            window.history.back();
        } else {
            window.location.href = $(this).attr('href');
        }
    });

    /* ---------- แถวตารางที่คลิกได้ ---------- */
    $(document).on('click', 'tr.row-link', function (e) {
        if ($(e.target).closest('a,button,input,select,label').length) {
            return;
        }
        window.location.href = $(this).data('href');
    });
});
