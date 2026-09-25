/* ประวัติการแก้ไขข้อมูล — แสดงค่าเดิม/ค่าใหม่รายคอลัมน์ */
$(function () {
    'use strict';

    var esc = Flood.esc;

    function show(v) {
        if (v === null || v === undefined) {
            return '<span class="text-muted">NULL</span>';
        }
        if (typeof v === 'object') {
            return '<code>' + esc(JSON.stringify(v)) + '</code>';
        }
        return esc(v);
    }

    $(document).on('click', '.js-audit', function () {
        Flood.get('flood/auditLogEntry/' + $(this).data('id')).then(function (o) {
            var e = o.entry;
            var before = e.before || {};
            var after = e.after || {};
            var html = '<p class="small-muted">' + esc(e.created_at) + ' · ' + esc(e.actor_name || 'ประชาชน/ระบบ')
                + ' · ' + esc(e.route) + ' · IP ' + esc(e.ip) + '</p>';
            if (e.action === 'delete') {
                var row = before.row || before;
                html += '<table class="table table-condensed diff-table"><thead><tr><th>คอลัมน์</th><th>ค่าที่ถูกลบ</th></tr></thead><tbody>';
                Object.keys(row).forEach(function (k) {
                    html += '<tr><td>' + esc(k) + '</td><td class="old">' + show(row[k]) + '</td></tr>';
                });
                html += '</tbody></table>';
                if (before.cascade) {
                    html += '<p class="small-muted">ข้อมูลลูกที่ถูกลบตามไปด้วย: ' + esc(Object.keys(before.cascade).join(', ')) + '</p>';
                }
            } else {
                var keys = Object.keys($.extend({}, before, after));
                html += '<table class="table table-condensed diff-table"><thead><tr><th>คอลัมน์</th>'
                    + (e.action === 'update' ? '<th>ค่าเดิม</th>' : '') + '<th>ค่าใหม่</th></tr></thead><tbody>';
                keys.forEach(function (k) {
                    html += '<tr><td>' + esc(k) + '</td>'
                        + (e.action === 'update' ? '<td class="old">' + show(before[k]) + '</td>' : '')
                        + '<td class="new">' + show(after[k]) + '</td></tr>';
                });
                html += '</tbody></table>';
            }
            $('#auTitle').text(e.table_name + ' #' + e.row_pk);
            $('#auBody').html(html);
            $('#auditModal').modal('show');
        });
    });
});
