/* ผู้ใช้จาก hosoffice (Flood2026-site-api) — นำเข้า / ทดสอบการเชื่อมต่อ */
$(function () {
    'use strict';

    var esc = Flood.esc;
    var $out = $('#siteApiResult');

    $(document).on('click', '.js-site-import', function () {
        var $btn = $(this);
        Flood.confirm({
            title: 'นำเข้าผู้ใช้จาก hosoffice',
            message: 'ระบบจะเพิ่มบุคลากรที่มีชื่อผู้ใช้ใน hosoffice เป็นผู้ใช้สิทธิ์ "ผู้บริหาร/ดูอย่างเดียว" และปรับชื่อ/หน่วยงานของบัญชีเดิมให้ตรง hosoffice — ดำเนินการต่อ?',
            okText: 'นำเข้า'
        }, function () {
            Flood.busy($btn, true, 'กำลังนำเข้า…');
            return Flood.post('flood/siteApiImportUsers', {}, { timeout: 180000 }).then(function (o) {
                Flood.busy($btn, false);
                Flood.toast(o.msg, 'success');
                $out.html('<div class="alert alert-success" style="margin:0">' + esc(o.msg) + ' — <a href="">รีเฟรชรายชื่อ</a></div>');
            }, function () { Flood.busy($btn, false); });
        });
    });

    function kv(obj) {
        return Object.keys(obj || {}).map(function (k) {
            return '<span class="notice-chip">' + esc(k) + ' <b>' + esc(obj[k]) + '</b></span>';
        }).join(' ');
    }

    $(document).on('click', '.js-site-test', function () {
        var $btn = $(this);
        Flood.busy($btn, true, 'กำลังทดสอบ…');
        Flood.post('flood/siteApiTest', {}, { timeout: 60000, silent: true }).always(function () { Flood.busy($btn, false); })
            .then(render, function (o) {
                $out.html('<div class="alert alert-danger" style="margin:0">' + esc((o && o.msg) || 'เชื่อมต่อไม่ได้') + '</div>');
            });
    });

    function render(o) {
        var h = o.health || {};
        var s = o.schema || {};
        var ok = function (b, e) {
            return b ? '<span class="text-success"><i class="fa fa-check-circle"></i> เชื่อมต่อได้</span>'
                : '<span class="text-danger"><i class="fa fa-times-circle"></i> ' + esc(e || 'เชื่อมต่อไม่ได้') + '</span>';
        };
        var html = '<div class="alert alert-' + (h.hosoffice_ok ? 'success' : 'warning') + '" style="margin:0 0 8px">'
            + 'API ' + esc(h.service || '') + ' v' + esc(h.version || '') + (h.mock ? ' <b>(โหมดข้อมูลตัวอย่าง)</b>' : '')
            + '<br>ฐาน hosoffice: ' + ok(h.hosoffice_ok, h.hosoffice_error)
            + '<br>ระบบลงเวลา: ' + ok(h.hik_ok, h.hik_error) + '</div>';
        ['sql_users', 'sql_user_auth'].forEach(function (k) {
            var x = s[k];
            if (!x) {
                return;
            }
            html += '<div style="margin-bottom:6px"><b>' + k + '</b>: ' + (x.ok
                ? '<span class="text-success">รันได้</span> · ' + esc(x.rows) + ' แถว'
                    + (x.with_username !== undefined ? ' · มีชื่อผู้ใช้ ' + esc(x.with_username) + ' คน' : '')
                    + (x.columns && x.columns.length ? ' · คอลัมน์: ' + esc(x.columns.join(', ')) : '')
                : '<span class="text-danger">' + esc(x.error) + '</span>') + '</div>';
            if (x.departments) {
                html += '<details style="margin-bottom:6px"><summary>หน่วยงานใน hosoffice (' + Object.keys(x.departments).length + ')</summary>' + kv(x.departments) + '</details>';
            }
            if (x.positions) {
                html += '<details style="margin-bottom:6px"><summary>ตำแหน่ง (ไว้ตรวจการแยกพยาบาลวิชาชีพ)</summary>' + kv(x.positions) + '</details>';
            }
        });
        if (s.hik_recent) {
            html += '<div style="margin-bottom:6px"><b>ลงเวลาตั้งแต่เมื่อวาน</b>: ' + esc(s.hik_recent.n) + ' ครั้ง · ล่าสุด ' + esc(s.hik_recent.last_at || '-')
                + (s.hik_sample_emp ? ' · ตัวอย่าง employeeID: ' + esc(s.hik_sample_emp.join(', ')) : '')
                + (s.sql_users && s.sql_users.sample_emp_id ? ' · ตัวอย่าง emp_id จาก hosoffice: ' + esc(s.sql_users.sample_emp_id.join(', ')) : '') + '</div>';
        }
        var tables = $.extend({}, s.hosoffice || {}, s.hik || {});
        if (Object.keys(tables).length) {
            html += '<details><summary>โครงสร้างตารางที่เกี่ยวข้อง</summary><div style="max-height:320px;overflow:auto;font-size:12px">'
                + Object.keys(tables).map(function (t) {
                    var c = tables[t];
                    return '<div style="margin:6px 0"><b>' + esc(t) + '</b>: ' + esc(Array.isArray(c) ? c.join(', ') : c) + '</div>';
                }).join('') + '</div></details>';
        }
        $out.html(html);
    }
});
