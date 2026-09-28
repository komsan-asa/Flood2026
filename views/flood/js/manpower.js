/* อัตรากำลังพยาบาลรายเวร — กรอกจำนวนเอง / ดูรายชื่อผู้ลงเวลา / ตั้งค่ากรอบและวันหยุด */
$(function () {
    'use strict';

    var esc = Flood.esc;
    var reload = function () { setTimeout(function () { window.location.reload(); }, 700); };
    var SHIFT = { M: 'เวรเช้า', A: 'เวรบ่าย', N: 'เวรดึก' };

    /* ---------- เฉพาะหน่วยที่ขาด / รอข้อมูล (จำค่าไว้ในเบราว์เซอร์) ---------- */
    var $only = $('#mpOnlyShort');
    function applyOnly() {
        var on = $only.is(':checked');
        $('#mpTable .mp-row').each(function () {
            $(this).toggleClass('mp-hide', on && !$(this).hasClass('mp-flag'));
        });
        $('#mpTable .mp-group').each(function () {
            var $g = $(this);
            var any = $g.nextUntil('.mp-group').filter('.mp-row:not(.mp-hide)').length > 0;
            $g.toggleClass('mp-hide', !any);
        });
        try { localStorage.setItem('mpOnlyShort', on ? '1' : ''); } catch (e) { /* ไม่มี storage */ }
    }
    if ($only.length) {
        try { $only.prop('checked', localStorage.getItem('mpOnlyShort') === '1'); } catch (e) { /* ไม่มี storage */ }
        $only.on('change', applyOnly);
        applyOnly();
    }

    /* ---------- กดช่องในตาราง → กรอกจำนวน / ดูผู้ลงเวลา ---------- */
    var $modal = $('#mpModal');
    var date = $modal.find('[name=date]').val();

    $(document).on('click', '.js-mp-cell', function () {
        var d = $(this).data();
        $('#mpmName').text(d.name);
        $('#mpmShift').text(SHIFT[d.shift] || '');
        $('#mpmUnit').val(d.unit);
        $('#mpmShiftKey').val(d.shift);
        $('#mpmReq').text(d.req);
        $('#mpmCheckin').text(d.checkin === '' ? '–' : d.checkin);
        $('#mpmOther').text(d.other || 0);
        $('#mpmActual').val(d.actual === '' ? '' : d.actual);
        $('#mpmNote').val(d.note || '');
        $('#mpmBy').text(d.actual !== '' && d.by ? 'กรอกโดย ' + d.by + (d.at ? ' · ' + d.at : '') : (d.at ? 'ลงเวลาล่าสุด ' + d.at : ''));
        $('#mpmList').html('');
        $modal.modal('show');
        if (d.checkin !== '' || d.other) {
            $('#mpmList').html('<div class="small-muted"><i class="fa fa-spinner fa-spin"></i> กำลังโหลดรายชื่อ…</div>');
            Flood.get('flood/manpowerCheckins', { unit_id: d.unit, date: date, shift: d.shift }, { silent: true }).then(function (o) {
                if (!o.rows.length) {
                    $('#mpmList').html('');
                    return;
                }
                $('#mpmList').html('<table>' + o.rows.map(function (r) {
                    return '<tr><td>' + esc(r.at) + '</td><td>' + esc(r.name || r.code) + '</td><td class="small-muted">'
                        + esc(r.code) + '</td><td>' + (r.type === 'RN' ? '<b>RN</b>' : esc(r.type)) + '</td></tr>';
                }).join('') + '</table>');
            }, function () { $('#mpmList').html(''); });
        }
    });
    $modal.on('shown.bs.modal', function () { $('#mpmActual').focus(); });

    function save(clear) {
        var $btn = $('#mpForm [type=submit]');
        var data = $('#mpForm').serializeArray().reduce(function (o, f) { o[f.name] = f.value; return o; }, {});
        if (clear) {
            data.actual = '';
            data.note = '';
        }
        Flood.busy($btn, true);
        Flood.post('flood/manpowerSave', data).then(function (o) {
            Flood.toast(o.msg, 'success');
            $modal.modal('hide');
            reload();
        }, function () { Flood.busy($btn, false); });
    }
    $('#mpForm').on('submit', function (e) {
        e.preventDefault();
        save(false);
    });
    $(document).on('click', '.js-mp-clear', function () { save(true); });

    /* ---------- ดึงข้อมูลลงเวลาจาก hosoffice ---------- */
    $(document).on('click', '.js-mp-sync', function () {
        var $btn = $(this);
        Flood.busy($btn, true, 'กำลังดึง…');
        Flood.post('flood/manpowerSync', { date: $btn.data('date') }, { timeout: 120000 }).then(function (o) {
            Flood.toast(o.msg, 'success');
            reload();
        }, function () { Flood.busy($btn, false); });
    });

    /* ---------- ตั้งค่ากรอบ (ผู้ดูแลระบบ) ---------- */
    $(document).on('click', '.js-mp-unit-save', function () {
        var $tr = $(this).closest('tr');
        var data = { unit_id: $tr.data('id') };
        $tr.find('input[name]').each(function () { data[this.name] = $(this).val(); });
        var $btn = $(this);
        Flood.busy($btn, true);
        Flood.post('flood/manpowerUnitSave', data).then(function (o) {
            Flood.toast(o.msg, 'success');
            Flood.busy($btn, false);
            if (!data.unit_id) {
                reload();
            }
        }, function () { Flood.busy($btn, false); });
    });
    $(document).on('click', '.js-mp-unit-toggle', function () {
        Flood.post('flood/manpowerUnitSave', { unit_id: $(this).closest('tr').data('id'), act: 'toggle' }).then(function (o) {
            Flood.toast(o.msg, 'success');
            reload();
        });
    });

    $('#mpHolForm').on('submit', function (e) {
        e.preventDefault();
        var $btn = $(this).find('[type=submit]');
        Flood.busy($btn, true);
        Flood.post('flood/manpowerHoliday', $(this).serialize()).then(function (o) {
            Flood.toast(o.msg, 'success');
            reload();
        }, function () { Flood.busy($btn, false); });
    });
    $(document).on('click', '.js-mp-hol-del', function () {
        var dt = $(this).data('date');
        Flood.confirm({ title: 'ลบวันหยุด', message: 'ลบวันหยุดวันที่ ' + dt + ' ?', okText: 'ลบ', okClass: 'btn-danger' }, function () {
            return Flood.post('flood/manpowerHoliday', { hdate: dt, act: 'delete' }).then(function (o) {
                Flood.toast(o.msg, 'success');
                reload();
            });
        });
    });
});
