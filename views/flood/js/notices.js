/* ข้อมูลที่ควรรู้ */
$(function () {
    'use strict';

    var rows = {};
    (window.NOTICES || []).forEach(function (n) { rows[n.notice_id] = n; });

    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function nowLocal() {
        var d = new Date();
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    $(document).on('click', '.js-notice-edit', function () {
        var n = rows[$(this).data('id')] || { notice_id: 0, category: 'shelter', title: '', detail: '', amphoe_code: '', place: '', contact: '',
            verify: 'verified', source_name: '', source_url: '', is_pinned: 0, is_public: 1, info_at: nowLocal() };
        $('#noticeTitle').text(n.notice_id ? 'แก้ไขข้อมูล' : 'เพิ่มข้อมูล');
        $('#nId').val(n.notice_id);
        $('#nCat').val(n.category);
        $('#nVerify').val(n.verify);
        $('#nTitleIn').val(n.title);
        $('#nDetail').val(n.detail);
        $('#nPlace').val(n.place);
        $('#nAmphoe').val(n.amphoe_code);
        $('#nContact').val(n.contact);
        $('#nInfoAt').val(n.info_at);
        $('#nSrcName').val(n.source_name);
        $('#nSrcUrl').val(n.source_url);
        $('#nPinned').prop('checked', !!n.is_pinned);
        $('#nPublic').prop('checked', n.is_public === undefined ? true : !!n.is_public);
        $('#noticeModal').modal('show');
    });

    $('#noticeForm').on('submit', function (e) {
        e.preventDefault();
        var $btn = $('#nSave');
        Flood.busy($btn, true);
        var data = $(this).serialize() + '&is_pinned=' + ($('#nPinned').is(':checked') ? 1 : 0) + '&is_public=' + ($('#nPublic').is(':checked') ? 1 : 0);
        Flood.post('flood/saveNotice', data).then(function (o) {
            Flood.toast(o.msg, 'success');
            setTimeout(function () { window.location.reload(); }, 500);
        }, function () {
            Flood.busy($btn, false);
        });
    });

    $(document).on('click', '.js-notice-set', function () {
        var $b = $(this);
        Flood.busy($b, true);
        Flood.post('flood/noticeSet', { notice_id: $b.data('id'), field: $b.data('field'), value: $b.data('value') }).then(function (o) {
            Flood.toast(o.msg, 'success');
            setTimeout(function () { window.location.reload(); }, 400);
        }, function () {
            Flood.busy($b, false);
        });
    });
});
