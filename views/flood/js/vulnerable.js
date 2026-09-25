/* ทะเบียนกลุ่มเปราะบาง */
$(function () {
    'use strict';

    var esc = Flood.esc;
    var EVAC_COLOR = { normal: '#7c3aed', alerted: '#c026d3', evacuated: '#16a34a', shelter_in_place: '#0ea5e9', admitted: '#155e9c' };
    var evac = window.VULN_EVAC || {};

    /* ---------- แผนที่ ---------- */
    if ($('#vulnMap').length) {
        var map = FloodMap.create('vulnMap');
        var zl = L.featureGroup().addTo(map);
        var ml = L.featureGroup().addTo(map);
        (window.VULN_ZONES || []).forEach(function (z) { FloodMap.zoneLayer(z).addTo(zl); });
        (window.VULN_MAP || []).forEach(function (v) {
            var ev = evac[v.evac_status] || { name: v.evac_status };
            L.marker([v.lat, v.lng], { icon: FloodMap.pinIcon(EVAC_COLOR[v.evac_status] || '#7c3aed', 'fa-wheelchair') })
                .bindPopup('<div class="pop-title">' + esc(v.name) + '</div>' + esc((v.groups || []).join(', '))
                    + '<div style="margin-top:4px">' + esc(ev.name) + '</div>'
                    + (v.zone ? '<div class="small-muted">อยู่ใน ' + esc(v.zone.name) + '</div>' : '')
                    + '<div style="margin-top:6px"><a href="#" class="js-vstatus" data-id="' + v.person_id + '">อัปเดตสถานะ</a> · '
                    + '<a href="' + Flood.url('flood/vulnerableForm/' + v.person_id) + '">แก้ไข</a></div>')
                .addTo(ml);
        });
        FloodMap.fitLayers(map, ml.getLayers(), 15);
    }

    /* ---------- ตัวกรองตำบล ---------- */
    var tambons = window.VULN_TAMBONS || [];
    function fillTambon(selected) {
        var a = $('#vfAmphoe').val();
        var $t = $('#vfTambon').empty().append('<option value="">ทุกตำบล</option>');
        tambons.forEach(function (t) {
            if (a && t.amphoe_code === a) {
                $t.append($('<option>').val(t.tambon_code).text('ต.' + t.name));
            }
        });
        if (selected) {
            $t.val(selected);
        }
    }
    $('#vfAmphoe').on('change', function () { fillTambon(''); });
    fillTambon($('#vfTambon').data('selected'));

    /* ---------- อัปเดตสถานะ ---------- */
    $(document).on('click', '.js-vstatus', function (e) {
        e.preventDefault();
        var id = $(this).data('id');
        Flood.get('flood/vulnerableData/' + id).then(function (o) {
            var p = o.person;
            $('#vsId').val(p.person_id);
            $('#vsName').text(p.name);
            $('input[name=vs_status]').prop('checked', false).filter('[value="' + p.evac_status + '"]').prop('checked', true);
            $('#vsPlace').val(p.evac_place);
            $('#vsNote').val('');
            $('#vsLogs').html(p.logs.length
                ? '<label>ประวัติการติดตาม</label><ul class="timeline">' + p.logs.map(function (l) {
                    return '<li class="timeline-item"><div class="t-head">' + esc(l.status) + (l.place ? ' — ' + esc(l.place) : '') + '</div>'
                        + '<div class="t-meta">' + esc(l.time) + ' · ' + esc(l.user) + '</div>'
                        + (l.note ? '<div class="t-note">' + esc(l.note) + '</div>' : '') + '</li>';
                }).join('') + '</ul>'
                : '<div class="small-muted">ยังไม่มีประวัติการติดตาม</div>');
            $('#vStatusModal').modal('show');
        });
    });

    $('#vsSave').on('click', function () {
        var $btn = $(this);
        var status = $('input[name=vs_status]:checked').val();
        if (!status) {
            Flood.toast('กรุณาเลือกสถานะ', 'danger');
            return;
        }
        Flood.busy($btn, true);
        Flood.post('flood/vulnerableStatus', {
            person_id: $('#vsId').val(), evac_status: status, evac_place: $('#vsPlace').val(), note: $('#vsNote').val()
        }).then(function (o) {
            Flood.toast(o.msg, 'success');
            setTimeout(function () { window.location.reload(); }, 500);
        }, function () {
            Flood.busy($btn, false);
        });
    });
});
