/* ระดับพื้นที่ (ผู้ดูแล) — เพิ่ม/แก้ชื่อ สี ไอคอน ลำดับ และเปิด/ปิดใช้งาน */
$(function () {
    'use strict';

    var levels = window.LEVELS || {};

    /* สีป้ายแบบเดียวกับฝั่ง PHP (flood_color_on_white): เข้มขึ้นจนตัวอักษรขาวอ่านได้ ≥ 4.5:1 */
    function lum(hex) {
        var out = 0;
        [[1, 0.2126], [3, 0.7152], [5, 0.0722]].forEach(function (p) {
            var c = parseInt(hex.substr(p[0], 2), 16) / 255;
            c = c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
            out += p[1] * c;
        });
        return out;
    }
    function badgeColor(hex) {
        var rgb = [1, 3, 5].map(function (i) { return parseInt(hex.substr(i, 2), 16); });
        var out = hex;
        for (var i = 0; i < 40 && 1.05 / (lum(out) + 0.05) < 4.5; i++) {
            rgb = rgb.map(function (c) { return c * 0.94; });
            out = '#' + rgb.map(function (c) { return ('0' + Math.round(c).toString(16)).slice(-2); }).join('');
        }
        return out;
    }
    function rgba(hex, a) {
        return 'rgba(' + [1, 3, 5].map(function (i) { return parseInt(hex.substr(i, 2), 16); }).join(',') + ',' + a + ')';
    }

    function preview() {
        var c = String($('#lvColor').val() || '#64748b').toLowerCase();
        var $b = $('#lvPreviewBadge');
        $b.css('background', badgeColor(c));
        $b.find('i').attr('class', 'fa ' + $('#lvIcon').val());
        $b.find('span').text($.trim($('#lvName').val()) || 'ชื่อระดับ');
        $('#lvPreviewMap').css({ borderColor: c, background: rgba(c, 0.28) });
        $('.js-level-color').each(function () {
            var on = String($(this).data('color')).toLowerCase() === c;
            $(this).toggleClass('on', on).attr('aria-checked', on ? 'true' : 'false');
        });
    }

    $(document).on('click', '.js-level-edit', function () {
        var code = String($(this).data('code') || '');
        var l = levels[code] || { level_code: '', name: '', description: '', color: '#0284c7', icon: 'fa-circle', is_active: 1 };
        $('#levelTitle').text(code ? 'แก้ไขระดับ' : 'เพิ่มระดับ');
        $('#lvCode').val(code);
        $('#lvName').val(l.name);
        $('#lvDesc').val(l.description || '');
        $('#lvColor').val(l.color);
        $('#lvIcon').val(l.icon);
        $('#lvActive').prop('checked', Number(l.is_active) === 1);
        preview();
        $('#levelModal').modal('show');
    });

    $('#levelForm').on('input change', 'input, select', preview);
    $(document).on('click', '.js-level-color', function () {
        $('#lvColor').val($(this).data('color'));
        preview();
    });

    $('#levelForm').on('submit', function (e) {
        e.preventDefault();
        if ($.trim($('#lvName').val()).length < 2) {
            Flood.toast('กรุณาตั้งชื่อระดับ', 'danger');
            $('#lvName').focus();
            return;
        }
        var $btn = $('#lvSave');
        Flood.busy($btn, true);
        var data = $(this).serialize() + '&is_active=' + ($('#lvActive').is(':checked') ? 1 : 0);
        Flood.post('flood/saveLevel', data).then(function (o) {
            Flood.toast(o.msg, 'success');
            setTimeout(function () { window.location.reload(); }, 500);
        }, function () {
            Flood.busy($btn, false);
        });
    });

    $(document).on('click', '.js-level-move', function () {
        var $b = $(this);
        Flood.busy($b, true);
        Flood.post('flood/moveLevel', { level_code: $b.data('code'), dir: $b.data('dir') }).then(function () {
            window.location.reload();
        }, function () {
            Flood.busy($b, false);
        });
    });

    $(document).on('click', '.js-level-delete', function () {
        var $b = $(this);
        Flood.confirm({
            title: 'ลบระดับ',
            message: 'ลบระดับ "' + $b.data('name') + '" ออกจากระบบ?',
            okText: 'ลบ',
            okClass: 'btn-danger'
        }, function () {
            return Flood.post('flood/deleteLevel', { level_code: $b.data('code') }).then(function (o) {
                Flood.toast(o.msg, 'success');
                setTimeout(function () { window.location.reload(); }, 500);
            });
        });
    });
});
