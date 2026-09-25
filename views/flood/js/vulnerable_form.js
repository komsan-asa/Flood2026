/* เพิ่ม/แก้ไขบุคคลในทะเบียนกลุ่มเปราะบาง */
$(function () {
    'use strict';

    var cfg = window.VFORM || {};
    var tambons = cfg.tambons || [];
    var picker = FloodMap.picker('vMap', {
        lat: $('#vLat'), lng: $('#vLng'), status: $('#vLocStatus'), initial: cfg.initial,
        initialText: '<i class="fa fa-map-marker"></i> ตำแหน่งบ้านที่บันทึกไว้ — ลากหมุดเพื่อปรับได้'
    });
    (cfg.zones || []).forEach(function (z) {
        FloodMap.zoneLayer(z, { style: { interactive: false }, popup: false }).addTo(picker.map);
    });

    $('#vLocate').on('click', function () { picker.locate($(this)); });
    $('#vClear').on('click', function () { picker.clear(); });

    function fillTambon(selected) {
        var a = $('#vAmphoe').val();
        var $t = $('#vTambon').empty().append('<option value="">—</option>');
        tambons.forEach(function (t) {
            if (t.amphoe_code === a) {
                $t.append($('<option>').val(t.tambon_code).text(t.name));
            }
        });
        if (selected) {
            $t.val(selected);
        }
    }
    $('#vAmphoe').on('change', function () { fillTambon(''); });
    $('#vTambon').on('change', function () {
        var code = $(this).val();
        tambons.forEach(function (t) {
            if (t.tambon_code === code && t.lat && !picker.has()) {
                picker.map.setView([parseFloat(t.lat), parseFloat(t.lng)], 14);
            }
        });
    });
    fillTambon($('#vTambon').data('selected'));

    $('#vForm').on('submit', function (e) {
        e.preventDefault();
        if ($.trim($('#vName').val()).length < 2) {
            Flood.toast('กรุณากรอกชื่อ-สกุล', 'danger');
            return;
        }
        if (!$('[name="vuln_groups[]"]:checked').length) {
            Flood.toast('กรุณาเลือกกลุ่มเปราะบางอย่างน้อย 1 กลุ่ม', 'danger');
            return;
        }
        var $btn = $('#vSave');
        Flood.busy($btn, true);
        Flood.post('flood/saveVulnerable', $(this).serialize()).then(function (o) {
            Flood.toast(o.msg, 'success');
            window.location.href = o.url;
        }, function () {
            Flood.busy($btn, false);
        });
    });

    $('#vRemove').on('click', function () {
        Flood.confirm({
            title: 'นำออกจากทะเบียน',
            message: 'นำ "' + cfg.name + '" ออกจากทะเบียนกลุ่มเปราะบาง? (ประวัติการติดตามยังเก็บไว้)',
            okText: 'นำออก', okClass: 'btn-danger'
        }, function () {
            return Flood.post('flood/vulnerableRemove', { person_id: cfg.person_id }).then(function (o) {
                Flood.toast(o.msg, 'success');
                window.location.href = Flood.url('flood/vulnerable');
            });
        });
    });
});
