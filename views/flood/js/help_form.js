/* เจ้าหน้าที่บันทึกคำขอที่รับทางโทรศัพท์ */
$(function () {
    'use strict';

    var tambons = Flood.tambonList(window.HF_TAMBONS);   // โหลดเพิ่มทีละอำเภอ
    var picker = FloodMap.picker('pickMap', { lat: $('#fLat'), lng: $('#fLng'), acc: $('#fAcc'), status: $('#locStatus') });
    var photos = FloodUpload.bind($('#fPhotos'), $('#fPreview'), 3);

    $('#btnClearPin').on('click', function () { picker.clear(); });

    $('#fAmphoe').on('change', function () {
        var a = $(this).val();
        var $t = $('#fTambon').empty().append('<option value="">—</option>');
        Flood.loadTambons(a).always(function () {
            if ($('#fAmphoe').val() !== a) {
                return;   // เปลี่ยนอำเภออีกแล้ว
            }
            tambons.forEach(function (t) {
                if (t.amphoe_code === a) {
                    $t.append($('<option>').val(t.tambon_code).text(t.name));
                }
            });
        });
    });
    $('#fTambon').on('change', function () {
        var code = $(this).val();
        tambons.forEach(function (t) {
            if (t.tambon_code === code && t.lat && !picker.has()) {
                picker.map.setView([parseFloat(t.lat), parseFloat(t.lng)], 14);
            }
        });
    });

    $('#helpForm').on('submit', function (e) {
        e.preventDefault();
        if (!$('[name="needs[]"]:checked').length) {
            Flood.toast('กรุณาเลือกเรื่องที่ต้องการความช่วยเหลือ', 'danger');
            return;
        }
        if (!$('#fLat').val() && $.trim($('#fAddress').val()).length < 5) {
            Flood.toast('กรุณาปักหมุดบนแผนที่ หรือกรอกที่อยู่/จุดสังเกต', 'danger');
            return;
        }
        var $btn = $('#btnSave');
        Flood.busy($btn, true);
        var fd = new FormData(this);
        photos.appendTo(fd, 'photos[]').then(function () {
            return Flood.post('flood/saveHelp', fd);
        }).then(function (o) {
            Flood.toast(o.msg, 'success');
            window.location.href = o.url;
        }, function () {
            Flood.busy($btn, false);
        });
    });
});
