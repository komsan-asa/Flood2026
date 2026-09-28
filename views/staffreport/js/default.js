/* ฟอร์มบุคลากรโรงพยาบาลแจ้งผลกระทบ / ขอความช่วยเหลือ */
$(function () {
    'use strict';

    var $form = $('#sfForm');
    if (!$form.length) {
        return;
    }
    var picker = FloodMap.picker('pickMap', {
        lat: $('#fLat'), lng: $('#fLng'), acc: $('#fAcc'), status: $('#locStatus')
    });
    var photos = FloodUpload.bind($('#fPhotos'), $('#fPreview'), 3);

    $('#btnLocate').on('click', function () {
        picker.locate($(this));
    });

    // จำชื่อ/เบอร์/หน่วยงานไว้ในเครื่อง — แจ้งครั้งถัดไปไม่ต้องพิมพ์ใหม่ (ไม่มี localStorage = ข้าม)
    var SAVE_KEY = 'skfStaffForm';
    try {
        var saved = JSON.parse(localStorage.getItem(SAVE_KEY) || 'null');
        if (saved) {
            ['fName', 'fPhone', 'fDept', 'fPos'].forEach(function (id) {
                if (saved[id] && !$('#' + id).val()) {
                    $('#' + id).val(saved[id]);
                }
            });
        }
    } catch (e) { /* โหมดส่วนตัว */ }

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
        if ($.trim($('#fName').val()).length < 4) {
            return fail('secWho', 'กรุณากรอกชื่อ-สกุล');
        }
        if (!/^0\d{8,9}$/.test($('#fPhone').val().replace(/[^0-9]/g, ''))) {
            return fail('secWho', 'กรุณากรอกเบอร์โทร 9–10 หลัก ทีมที่ดูแลจะโทรกลับ');
        }
        if (!$.trim($('#fDept').val())) {
            return fail('secWho', 'กรุณากรอกกลุ่มงาน / หน่วยงาน');
        }
        if (!$form.find('[name=victim]:checked').length) {
            return fail('secImpact', 'กรุณาเลือกว่าน้ำท่วมกระทบคุณอย่างไร');
        }
        if (!$form.find('[name=work]:checked').length) {
            return fail('secImpact', 'กรุณาเลือกเรื่องการมาปฏิบัติงาน');
        }
        if ($form.find('[name="help[]"][value=other]:checked').length && !$.trim($('#fDetail').val())) {
            return fail('secHelp', 'เลือก "อื่น ๆ" แล้ว กรุณาพิมพ์รายละเอียดว่าต้องการอะไร');
        }
        return true;
    }

    $form.on('submit', function (e) {
        e.preventDefault();
        if (!validate()) {
            return;
        }
        try {
            var keep = {};
            ['fName', 'fPhone', 'fDept', 'fPos'].forEach(function (id) { keep[id] = $.trim($('#' + id).val()); });
            localStorage.setItem(SAVE_KEY, JSON.stringify(keep));
        } catch (e2) { /* โหมดส่วนตัว */ }
        var $btn = $('#btnSubmit');
        Flood.busy($btn, true, photos.count() ? 'กำลังย่อรูปและส่ง…' : 'กำลังส่ง…');
        $form.find('[name=_csrf]').val(window.CSRF_TOKEN);
        var fd = new FormData($form[0]);
        photos.appendTo(fd, 'photos[]').then(function () {
            return Flood.post('staffreport/save', fd, { silent: true, timeout: 180000 });
        }).then(function (o) {
            window.location.href = o.url;
        }, function (o) {
            Flood.busy($btn, false);
            if (o && o.reload) {
                Flood.toast(o.msg, 'danger', 8000);
                setTimeout(function () { window.location.href = window.BASE_URL + 'staffreport'; }, 2500);
                return;
            }
            if (o && o.field) {
                fail(o.field, o.msg);
                return;
            }
            Flood.toast((o && o.msg) || 'ส่งไม่สำเร็จ กรุณาลองใหม่', 'danger');
        });
    });
});
