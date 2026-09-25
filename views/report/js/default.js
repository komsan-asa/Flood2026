/*
 * ฟอร์มประชาชนแจ้งจุดน้ำท่วม — ทีละขั้น
 * ขั้น 1 ปักหมุดกลางจอ (เลื่อนแผนที่ใต้หมุด) · ขั้น 2 มาตรวัดความสูงน้ำ · … · ขั้น 6 ผู้แจ้ง + สรุป
 * ปุ่มย้อนกลับของมือถือ = ย้อนขั้น (ใช้ history.pushState)
 */
$(function () {
    'use strict';

    var $form = $('#reportForm');
    var TOTAL = $('.sk-step').length;
    var LABELS = window.REPORT_LABELS || {};
    var MIN_ZOOM = 15;          // ต้องซูมใกล้พอ ตำแหน่งจึงจะแม่นระดับซอย
    var step = 1;
    var sent = false;
    var esc = Flood.esc;

    // ---------- ขั้น 1: แผนที่ปักหมุดกลางจอ ----------
    var map = FloodMap.create('pickMap', { scrollWheelZoom: true });
    var gps = null;             // {lat,lng,acc} ล่าสุดจากเครื่อง
    var accCircle = null;
    var $status = $('#locStatus');
    var $pick = $('.sk-pick');
    map.zoomControl.setPosition('bottomright');

    function status(html, cls) {
        $status.removeClass('ok err').addClass(cls || '').html(html);
    }

    function syncCenter() {
        var c = map.getCenter();
        if (map.getZoom() < MIN_ZOOM) {
            $('#fLat,#fLng,#fAcc').val('');
            status('<i class="fa fa-search-plus"></i> ซูมเข้าไปอีกนิดให้เห็นถนน/บ้าน แล้ววางหมุดตรงจุดน้ำท่วม', '');
            return;
        }
        var lat = Math.round(c.lat * 1e7) / 1e7;
        var lng = Math.round(c.lng * 1e7) / 1e7;
        var fromGps = gps && map.distance(c, [gps.lat, gps.lng]) < 5;
        var fromLink = !fromGps && linkPt && map.distance(c, [linkPt.lat, linkPt.lng]) < 3;
        $('#fLat').val(lat);
        $('#fLng').val(lng);
        $('#fMethod').val(fromGps ? 'gps' : 'pin');
        $('#fAcc').val(fromGps && gps.acc ? Math.round(gps.acc) : '');
        if (fromLink) {
            status('<i class="fa fa-check-circle"></i> ตำแหน่งจากลิงก์ ' + lat.toFixed(5) + ', ' + lng.toFixed(5) + ' · เลื่อนแผนที่เพื่อปรับได้', 'ok');
            $('#secLocation').removeClass('has-error');
            return;
        }
        status(fromGps
            ? '<i class="fa fa-check-circle"></i> ตำแหน่งจาก GPS' + (gps.acc ? ' (คลาดเคลื่อนราว ' + Math.round(gps.acc) + ' ม.)' : '') + ' · เลื่อนแผนที่เพื่อปรับได้'
            : '<i class="fa fa-check-circle"></i> หมุดอยู่ที่ ' + lat.toFixed(5) + ', ' + lng.toFixed(5) + ' · เลื่อนแผนที่เพื่อปรับ', 'ok');
        $('#secLocation').removeClass('has-error');
    }

    map.on('movestart', function () { $pick.addClass('moving'); });
    map.on('moveend', function () { $pick.removeClass('moving'); syncCenter(); });

    $('#btnLocate').on('click', function () {
        var $b = $(this);
        var orig = $b.html();
        $b.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> กำลังหาตำแหน่ง…');
        FloodMap.locate(function (lat, lng, acc) {
            $b.prop('disabled', false).html(orig);
            gps = { lat: lat, lng: lng, acc: acc };
            if (accCircle) { map.removeLayer(accCircle); }
            if (acc) {
                accCircle = L.circle([lat, lng], { radius: acc, color: '#5aa6e6', weight: 1, fillColor: '#5aa6e6', fillOpacity: 0.12, interactive: false }).addTo(map);
            }
            map.setView([lat, lng], Math.max(map.getZoom(), 17));
        }, function (msg) {
            $b.prop('disabled', false).html(orig);
            status('<i class="fa fa-exclamation-triangle"></i> ' + esc(msg), 'err');
        });
    });

    // ---------- วางลิงก์ Google Maps / พิกัด ----------
    var linkPt = null;          // จุดที่ได้จากลิงก์ล่าสุด

    /** ดึงพิกัดจากลิงก์แผนที่หรือข้อความพิกัด — คืน {lat,lng} หรือ null */
    function parseMapText(raw) {
        var s = String(raw || '').trim();
        try { s = decodeURIComponent(s.replace(/\+/g, ' ')); } catch (e) { /* ใช้ข้อความเดิม */ }
        var num = '(-?\\d{1,3}(?:\\.\\d+)?)';
        var tries = [
            new RegExp('!3d' + num + '!4d' + num),                                   // หมุดจริงของสถานที่ (Google)
            new RegExp('[?&](?:q|ll|query|destination|daddr|sll|center)=(?:loc:)?' + num + '\\s*,\\s*' + num), // ?q=lat,lng
            new RegExp('/place/' + num + '\\s*,\\s*' + num),
            new RegExp('/search/' + num + '\\s*,\\s*' + num),
            new RegExp('@' + num + ',' + num)                                        // กึ่งกลางจอที่เปิดดูอยู่
        ];
        var m, i;
        for (i = 0; i < tries.length; i++) {
            m = s.match(tries[i]);
            if (m) { return fix(+m[1], +m[2]); }
        }
        // องศา ลิปดา ฟิลิปดา เช่น 13°55'10.0"N 102°02'00.1"E
        m = s.match(/(\d{1,3})\s*[°º]\s*(\d{1,2})\s*['′’]\s*(\d{1,2}(?:\.\d+)?)\s*(?:["″”]|'')?\s*([NS])[\s,;]*(\d{1,3})\s*[°º]\s*(\d{1,2})\s*['′’]\s*(\d{1,2}(?:\.\d+)?)\s*(?:["″”]|'')?\s*([EW])/i);
        if (m) {
            var la = (+m[1]) + (+m[2]) / 60 + (+m[3]) / 3600;
            var lo = (+m[5]) + (+m[6]) / 60 + (+m[7]) / 3600;
            return fix(/s/i.test(m[4]) ? -la : la, /w/i.test(m[8]) ? -lo : lo);
        }
        // เลขพิกัดคู่ธรรมดา เช่น 13.9194486, 102.0333529
        m = s.match(/(-?\d{1,3}\.\d{3,})\s*[, ]\s*(-?\d{1,3}\.\d{3,})/);
        if (m) { return fix(+m[1], +m[2]); }
        return null;

        function fix(a, b) {
            if (a >= 97 && a <= 106.5 && b >= 5 && b <= 21) { var t = a; a = b; b = t; } // พิมพ์สลับ lng,lat
            if (!(a >= 5 && a <= 21 && b >= 97 && b <= 106.5)) { return { out: true }; }
            return { lat: a, lng: b };
        }
    }

    function isShortLink(s) {
        return /^(https?:\/\/)?(maps\.app\.goo\.gl|goo\.gl\/maps|g\.co\/kgs)\//i.test(String(s).trim());
    }

    function pasteState(cls) {
        $('#pasteBox').removeClass('ok err').addClass(cls || '');
    }

    function goToLink(pt) {
        linkPt = { lat: Math.round(pt.lat * 1e7) / 1e7, lng: Math.round(pt.lng * 1e7) / 1e7 };
        gps = null;
        pasteState('ok');
        map.setView([linkPt.lat, linkPt.lng], 18);
        syncCenter();
    }

    function applyLink() {
        var raw = $('#fMapLink').val();
        if (!$.trim(raw)) {
            $('#fMapLink').trigger('focus');
            return;
        }
        var pt = parseMapText(raw);
        if (pt && !pt.out) {
            goToLink(pt);
            return;
        }
        if (pt && pt.out) {
            pasteState('err');
            status('<i class="fa fa-exclamation-triangle"></i> พิกัดนี้อยู่นอกประเทศไทย — ตรวจลิงก์อีกครั้ง', 'err');
            return;
        }
        if (isShortLink(raw)) {
            // ลิงก์ย่อจากปุ่ม "แชร์" — ให้เซิร์ฟเวอร์เปิดดูว่าพาไปที่ไหน
            var $b = $('#btnMapLink');
            Flood.busy($b, true, 'กำลังอ่าน…');
            Flood.post('report/maplink', { url: $.trim(raw) }, { silent: true }).then(function (o) {
                Flood.busy($b, false);
                var p2 = parseMapText(o.url || '');
                if (p2 && !p2.out) {
                    goToLink(p2);
                } else {
                    linkFail('ลิงก์นี้ไม่มีพิกัด — เปิดลิงก์ในแผนที่ กดค้างที่จุดนั้น แล้วคัดลอกพิกัดมาวางแทน');
                }
            }, function (o) {
                Flood.busy($b, false);
                linkFail((o && o.msg) || 'อ่านลิงก์ย่อไม่ได้ — เปิดลิงก์ใน Google Maps แล้วคัดลอกลิงก์เต็มหรือพิกัดมาวางแทน');
            });
            return;
        }
        linkFail('ไม่พบพิกัดในข้อความนี้ — วางลิงก์ Google Maps หรือพิกัด เช่น 13.9194, 102.0333');
    }

    function linkFail(msg) {
        pasteState('err');
        status('<i class="fa fa-exclamation-triangle"></i> ' + esc(msg), 'err');
    }

    $('#btnMapLink').on('click', applyLink);
    $('#fMapLink').on('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); applyLink(); }
    }).on('paste', function () {
        setTimeout(applyLink, 0);   // วางแล้วไปทันที ไม่ต้องกดปุ่ม
    }).on('input', function () { pasteState(''); });

    // ---------- ขั้น 2: มาตรวัดความสูงน้ำ ----------
    function paintGauge() {
        var $c = $form.find('[name=depth]:checked');
        var h = $c.length ? $c.closest('.sk-opt').data('h') : 0;
        $('#gaugeWater').css('height', h ? (h + 4) + '%' : '0');
        $('.sk-gauge-mark').removeClass('on');
        if ($c.length) {
            $('.sk-gauge-mark[data-mark="' + $c.val() + '"]').addClass('on');
        }
    }

    // ---------- เลือกตัวเลือก ----------
    $form.on('change', 'input[type=checkbox]', updateNav);
    $form.on('change', 'input[type=radio]', function () {
        $(this).closest('.sk-step').removeClass('has-error');
        if (this.name === 'depth') {
            paintGauge();
        }
        updateNav();
        // คำถามเดียวในขั้น → ไปขั้นต่อไปให้เอง
        if ($(this).data('auto')) {
            setTimeout(function () { if (step === 2) { next(); } }, 550);
        }
    });
    // แตะตัวเลือกเดิมซ้ำในขั้นที่ไม่บังคับ = ยกเลิกการเลือก
    $form.on('click', '#secVehicle input[type=radio], #secExtent input[name=houses]', function () {
        var $i = $(this);
        if ($i.data('was')) {
            $i.prop('checked', false).data('was', false);
            updateNav();
            return;
        }
        $form.find('[name="' + this.name + '"]').data('was', false);
        $i.data('was', true);
    });
    $form.on('input', 'input,textarea', function () {
        $(this).removeClass('invalid').closest('.sk-step').removeClass('has-error');
    });

    var photos = FloodUpload.bind($('#fPhotos'), $('#fPreview'), 3);
    $('#fPhotos').on('change', function () { setTimeout(updateNav, 50); });
    $('#fPreview').on('click', '.up-del', function () { setTimeout(updateNav, 50); });

    // ---------- ตรวจแต่ละขั้น ----------
    function fail(n, msg, $field) {
        var $s = $('.sk-step[data-step="' + n + '"]');
        if (n !== step) {
            go(n, true);
        }
        $s.addClass('has-error');
        if ($field) {
            $field.addClass('invalid').trigger('focus');
        }
        Flood.toast(msg, 'danger');
        return false;
    }

    function check(n) {
        if (n === 1) {
            if (map.getZoom() < MIN_ZOOM || !$('#fLat').val()) {
                return fail(1, 'ซูมแผนที่เข้าไปให้ใกล้จุดน้ำท่วม หรือกด “ใช้ตำแหน่งของฉัน”');
            }
        }
        if (n === 2 && !$form.find('[name=depth]:checked').length) {
            return fail(2, 'กรุณาเลือกว่าน้ำสูงแค่ไหน');
        }
        if (n === 3 && !$form.find('[name=extent]:checked').length) {
            return fail(3, 'กรุณาเลือกว่าน้ำท่วมกว้างแค่ไหน');
        }
        if (n === 6) {
            if ($.trim($('#fName').val()).length < 2) {
                return fail(6, 'กรุณากรอกชื่อผู้แจ้ง', $('#fName'));
            }
            if (!/^0\d{8,9}$/.test($('#fPhone').val().replace(/[^0-9]/g, ''))) {
                return fail(6, 'กรุณากรอกเบอร์โทร 9–10 หลัก', $('#fPhone'));
            }
        }
        return true;
    }

    // ---------- สรุปก่อนส่ง ----------
    function pickLabel(name) {
        var v = $form.find('[name="' + name + '"]:checked').val();
        return v && LABELS[name] && LABELS[name][v] ? LABELS[name][v] : '';
    }

    function pickMany(name) {
        return $form.find('[name="' + name + '[]"]:checked').map(function () {
            return (LABELS[name] || {})[this.value] || '';
        }).get().filter(Boolean).join(', ');
    }

    function renderSummary() {
        var rows = [
            [1, 'ตำแหน่ง', $('#fLat').val() ? ($('#fMethod').val() === 'gps' ? 'GPS ' : (linkPt ? 'ลิงก์/ปักหมุด ' : 'ปักหมุด ')) + Number($('#fLat').val()).toFixed(5) + ', ' + Number($('#fLng').val()).toFixed(5) : ''],
            [2, 'ความสูงน้ำ', pickLabel('depth')],
            [3, 'ความกว้าง', [pickLabel('extent'), pickLabel('houses') ? 'บ้าน: ' + pickLabel('houses') : ''].filter(Boolean).join(' · ')],
            [4, 'สภาพในพื้นที่', [pickMany('impacts'), pickLabel('vehicle'), pickLabel('trend')].filter(Boolean).join(' · ')],
            [5, 'รูป/จุดสังเกต', [photos.count() ? photos.count() + ' รูป' : '', $.trim($('#fNote').val())].filter(Boolean).join(' · ')]
        ];
        $('#wzSummary').html(rows.map(function (r) {
            return '<div><dt>' + esc(r[1]) + '</dt><dd class="' + (r[2] ? '' : 'none') + '">' + esc(r[2] || 'ไม่ได้ระบุ') + '</dd>'
                + '<button type="button" data-go="' + r[0] + '">แก้ไข</button></div>';
        }).join(''));
    }
    $('#wzSummary').on('click', '[data-go]', function () { go(+$(this).data('go'), true); });

    // ---------- เดินหน้า / ย้อนกลับ ----------
    function optionalEmpty() {
        if (step === 4) {
            return !$form.find('[name=vehicle]:checked, [name=trend]:checked, [name="impacts[]"]:checked').length;
        }
        if (step === 5) {
            return !photos.count() && !$.trim($('#fNote').val());
        }
        return false;
    }

    function updateNav() {
        var label = step === 1 ? 'ยืนยันจุดนี้' : (optionalEmpty() ? 'ข้าม' : 'ถัดไป');
        $('#btnNext').toggleClass('skip', optionalEmpty()).find('span').text(label);
        $('#btnNext').prop('hidden', step === TOTAL);
        $('#btnSubmit').prop('hidden', step !== TOTAL);
        $('#btnBack').prop('hidden', step === 1);
    }

    function go(n, push) {
        n = Math.max(1, Math.min(TOTAL, n));
        var dir = n < step ? 'back' : '';
        step = n;
        $('.sk-step').each(function () {
            var on = +$(this).data('step') === n;
            $(this).prop('hidden', !on).removeClass('back');
            if (on && dir) { $(this).addClass('back'); }
        });
        $('.sk-wz-progress span').each(function () {
            var s = +$(this).data('seg');
            $(this).toggleClass('done', s < n).toggleClass('now', s === n);
        });
        $('#wzCount').text('ขั้นที่ ' + n + ' จาก ' + TOTAL + ' · ' + (window.REPORT_STEPS || [])[n - 1]);
        if (n === 1) {
            setTimeout(function () { map.invalidateSize(); }, 30);
        }
        if (n === TOTAL) {
            renderSummary();
        }
        updateNav();
        window.scrollTo(0, 0);
        $('.sk-step[data-step="' + n + '"] h1').trigger('focus');
        if (push !== false && window.history.pushState) {
            window.history.pushState({ step: n }, '', '#' + n);
        }
    }

    function next() {
        if (check(step)) {
            go(step + 1);
        }
    }

    $('#btnNext').on('click', next);
    $('#btnBack').on('click', function () {
        if (window.history.state && window.history.state.step > 1) {
            window.history.back();
        } else {
            go(step - 1, false);
        }
    });
    $(window).on('popstate', function (e) {
        var st = e.originalEvent.state;
        go(st && st.step ? st.step : 1, false);
    });
    $form.on('keydown', 'input:not([type=radio]):not(#fMapLink)', function (e) {
        if (e.key === 'Enter' && step < TOTAL) {
            e.preventDefault();
            next();
        }
    });

    // กรอกไปแล้วเผลอปิด/รีเฟรช — เตือนก่อน
    $(window).on('beforeunload', function () {
        if (!sent && step > 1) {
            return 'ข้อมูลที่กรอกไว้จะหายไป';
        }
    });

    // ---------- ส่ง ----------
    var fieldToStep = { location: 1, depth: 2, extent: 3, reporter_name: 6, reporter_phone: 6 };

    $form.on('submit', function (e) {
        e.preventDefault();
        for (var i = 1; i <= TOTAL; i++) {
            if (!check(i)) {
                return;
            }
        }
        var $btn = $('#btnSubmit');
        Flood.busy($btn, true, photos.count() ? 'กำลังย่อรูปและส่ง…' : 'กำลังส่ง…');
        $form.find('[name=_csrf]').val(window.CSRF_TOKEN);
        var fd = new FormData($form[0]);
        photos.appendTo(fd, 'photos[]').then(function () {
            return Flood.post('report/save', fd, { silent: true, timeout: 180000 });
        }).then(function (o) {
            sent = true;
            window.location.href = o.url;
        }, function (o) {
            Flood.busy($btn, false);
            if (o && o.field && fieldToStep[o.field]) {
                fail(fieldToStep[o.field], o.msg, o.field.indexOf('reporter') === 0 ? $('[name="' + o.field + '"]') : null);
            } else {
                Flood.toast((o && o.msg) || 'ส่งไม่สำเร็จ กรุณาลองใหม่', 'danger');
            }
        });
    });

    if (window.history.replaceState) {
        window.history.replaceState({ step: 1 }, '', window.location.pathname + window.location.search);
    }
    go(1, false);
    status('ซูมเข้าไปที่จุดน้ำท่วม หรือกด “ใช้ตำแหน่งของฉัน”', '');
});
