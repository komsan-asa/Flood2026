/* ฟอร์มประชาชนขอความช่วยเหลือ */
$(function () {
    'use strict';

    var $form = $('#sosForm');
    var tambons = Flood.tambonList(window.SOS_TAMBONS);   // โหลดเพิ่มทีละอำเภอ
    var picker = FloodMap.picker('pickMap', {
        lat: $('#fLat'), lng: $('#fLng'), acc: $('#fAcc'), status: $('#locStatus'),
        onChange: function () { $('#secLocation').removeClass('has-error'); }
    });
    var photos = FloodUpload.bind($('#fPhotos'), $('#fPreview'), 3);

    $('#btnLocate').on('click', function () {
        picker.locate($(this));
    });

    // อำเภอ → รายชื่อตำบล / เลือกตำบลแล้วเลื่อนแผนที่ไปใกล้ ๆ (ถ้ายังไม่ได้ปักหมุด)
    $('#fAmphoe').on('change', function () {
        var a = $(this).val();
        var $t = $('#fTambon').empty().append('<option value="">— เลือกตำบล —</option>');
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

    $form.on('change input', 'input,textarea,select', function () {
        $(this).closest('.pub-section').removeClass('has-error');
    });

    function fail(sectionId, msg) {
        var $s = $('#' + sectionId).addClass('has-error');
        Flood.toast(msg, 'danger');
        $('html,body').animate({ scrollTop: $s.offset().top - 70 }, 250);
        return false;
    }

    function validate() {
        if (!$form.find('[name="needs[]"]:checked').length) {
            return fail('secNeeds', 'กรุณาเลือกว่าต้องการความช่วยเหลือเรื่องอะไร');
        }
        if (!$('#fLat').val() && $.trim($('#fAddress').val()).length < 5) {
            return fail('secLocation', 'กรุณาระบุตำแหน่งบนแผนที่ หรือพิมพ์ที่อยู่/จุดสังเกต');
        }
        if ($.trim($('#fName').val()).length < 2) {
            return fail('secContact', 'กรุณากรอกชื่อผู้แจ้ง');
        }
        if (!/^0\d{8,9}$/.test($('#fPhone').val().replace(/[^0-9]/g, ''))) {
            return fail('secContact', 'กรุณากรอกเบอร์โทร 9–10 หลัก เจ้าหน้าที่จะโทรกลับยืนยัน');
        }
        return true;
    }

    $form.on('submit', function (e) {
        e.preventDefault();
        if (!validate()) {
            return;
        }
        var $btn = $('#btnSubmit');
        Flood.busy($btn, true, photos.count() ? 'กำลังย่อรูปและส่ง…' : 'กำลังส่ง…');
        $form.find('[name=_csrf]').val(window.CSRF_TOKEN);
        var fd = new FormData($form[0]);
        photos.appendTo(fd, 'photos[]').then(function () {
            return Flood.post('sos/save', fd, { silent: true, timeout: 180000 });
        }).then(function (o) {
            window.location.href = o.url;
        }, function (o) {
            Flood.busy($btn, false);
            Flood.toast((o && o.msg) || 'ส่งไม่สำเร็จ กรุณาลองใหม่ หรือโทรแจ้งโดยตรง', 'danger');
        });
    });
});
