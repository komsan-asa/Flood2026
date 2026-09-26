/*
 * ฟังก์ชันกลางที่ทุกหน้าใช้ (แนวเดียวกับ public/js/pagejscript.js ของระบบ COC)
 *   Flood.post / Flood.get   — ajax คืน JSON {chk, msg, ...} แนบ CSRF token ให้อัตโนมัติ
 *   Flood.toast              — แจ้งเตือนมุมจอ
 *   Flood.confirm            — กล่องยืนยันแบบ Bootstrap modal
 *   Flood.esc                — escape ข้อความก่อนใส่ลง HTML
 */
(function (window, $) {
    'use strict';

    var Flood = window.Flood = window.Flood || {};

    Flood.url = function (path) {
        return (window.BASE_URL || '/') + (path || '');
    };

    Flood.esc = function (s) {
        return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    };

    // ทุก request ที่ไม่ใช่ GET แนบ CSRF token (อ่านค่าล่าสุดทุกครั้ง เผื่อ token ถูกต่ออายุ)
    $.ajaxSetup({
        beforeSend: function (xhr, s) {
            if (!/^(GET|HEAD|OPTIONS)$/i.test(s.type)) {
                xhr.setRequestHeader('X-CSRF-Token', window.CSRF_TOKEN || '');
            }
        }
    });

    Flood.toast = function (msg, type, ms) {
        var $wrap = $('#floodToast');
        if (!$wrap.length) {
            $wrap = $('<div class="flood-toast-wrap" id="floodToast"></div>').appendTo('body');
        }
        var $t = $('<div class="flood-toast t-' + (type || 'info') + '" role="status"></div>').text(msg);
        $wrap.append($t);
        setTimeout(function () {
            $t.fadeOut(250, function () { $t.remove(); });
        }, ms || (type === 'danger' ? 6000 : 3500));
    };

    function parseJson(xhr) {
        if (xhr.responseJSON) {
            return xhr.responseJSON;
        }
        try {
            return JSON.parse(xhr.responseText);
        } catch (e) {
            return null;
        }
    }

    /**
     * ajax กลาง — resolve เมื่อ chk=true, reject พร้อมแจ้งเตือนเมื่อไม่สำเร็จ
     * opts.silent = true ไม่ต้องแสดงแจ้งเตือนเอง
     */
    Flood.ajax = function (method, path, data, opts) {
        opts = opts || {};
        var d = $.Deferred();
        var isForm = (typeof FormData !== 'undefined') && (data instanceof FormData);

        function fail(o, fallback) {
            if (o && o.relogin) {
                Flood.toast(o.msg || 'เซสชันหมดอายุ กรุณาเข้าสู่ระบบใหม่', 'danger');
                setTimeout(function () { window.location.href = Flood.url('login'); }, 1400);
            } else if (!opts.silent) {
                Flood.toast((o && (o.msg || o.error_log)) || fallback, 'danger');
            }
            d.reject(o || {});
        }

        function send(retried) {
            $.ajax({
                url: Flood.url(path),
                type: method,
                data: data,
                dataType: 'json',
                processData: !isForm,
                contentType: isForm ? false : 'application/x-www-form-urlencoded; charset=UTF-8',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                timeout: opts.timeout || 90000
            }).done(function (o) {
                if (o && o.chk) {
                    d.resolve(o);
                    return;
                }
                // ฟอร์มสาธารณะเปิดค้างนานจน session หมด — รับ token ใหม่แล้วส่งซ้ำ 1 ครั้ง
                if (o && o.csrf && o.token && !retried) {
                    window.CSRF_TOKEN = o.token;
                    if (isForm && data.set) {
                        data.set('_csrf', o.token);
                    }
                    send(true);
                    return;
                }
                fail(o, 'ทำรายการไม่สำเร็จ');
            }).fail(function (xhr) {
                var o = parseJson(xhr);
                if (o && o.csrf && o.token && !retried) {
                    window.CSRF_TOKEN = o.token;
                    send(true);
                    return;
                }
                var fallback = xhr.status === 0
                    ? 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ — ตรวจสอบสัญญาณอินเทอร์เน็ตแล้วลองใหม่'
                    : 'เกิดข้อผิดพลาด (' + xhr.status + ')';
                fail(o, fallback);
            });
        }

        send(false);
        return d.promise();
    };

    Flood.post = function (path, data, opts) {
        return Flood.ajax('POST', path, data, opts);
    };

    Flood.get = function (path, params, opts) {
        return Flood.ajax('GET', path, params, opts);
    };

    /** ปุ่มระหว่างรอ — กันกดซ้ำ */
    Flood.busy = function ($btn, on, text) {
        $btn = $($btn);
        if (on) {
            $btn.data('orig-html', $btn.html()).prop('disabled', true)
                .html('<i class="fa fa-spinner fa-spin"></i> ' + Flood.esc(text || 'กำลังบันทึก…'));
        } else {
            $btn.prop('disabled', false);
            if ($btn.data('orig-html')) {
                $btn.html($btn.data('orig-html'));
            }
        }
    };

    /**
     * กล่องยืนยัน
     *   Flood.confirm({title, message | html, okText, okClass}, function ($modal) { ... })
     *   ถ้า callback คืน promise กล่องจะปิดเมื่อสำเร็จ / คืน false = ไม่ปิด
     */
    Flood.confirm = function (o, onOk) {
        o = o || {};
        $('#floodConfirm').remove();
        var html = '<div class="modal fade" id="floodConfirm" tabindex="-1" role="dialog">'
            + '<div class="modal-dialog' + (o.large ? ' modal-lg' : '') + '"><div class="modal-content">'
            + '<div class="modal-header"><h4 class="modal-title">' + Flood.esc(o.title || 'ยืนยัน') + '</h4>'
            + '<button type="button" class="close" data-dismiss="modal" aria-label="ปิด">&times;</button></div>'
            + '<div class="modal-body">' + (o.html || ('<p style="margin:0">' + Flood.esc(o.message || '') + '</p>')) + '</div>'
            + '<div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">ยกเลิก</button>'
            + '<button type="button" class="btn ' + (o.okClass || 'btn-primary') + '" id="floodConfirmOk">'
            + Flood.esc(o.okText || 'ตกลง') + '</button></div>'
            + '</div></div></div>';
        var $m = $(html).appendTo('body');
        $m.on('click', '#floodConfirmOk', function () {
            var $btn = $(this);
            var r = onOk ? onOk($m) : true;
            if (r === false) {
                return;
            }
            if (r && typeof r.then === 'function') {
                Flood.busy($btn, true, 'กำลังดำเนินการ…');
                r.then(function () { $m.modal('hide'); }, function () { Flood.busy($btn, false); });
                return;
            }
            $m.modal('hide');
        });
        $m.on('hidden.bs.modal', function () { $m.remove(); });
        $m.on('shown.bs.modal', function () { $m.find('input,textarea,select').filter(':visible').first().focus(); });
        $m.modal('show');
        return $m;
    };

    Flood.levels = (window.FLOOD_MAP && window.FLOOD_MAP.levels) || {};

    Flood.levelBadge = function (code) {
        var all = (window.FLOOD_MAP && window.FLOOD_MAP.levelsAll) || {};
        var l = Flood.levels[code] || all[code] || { name: code, badge: '#475569' };
        return '<span class="lv-badge lv-' + Flood.esc(code) + '" style="background:' + Flood.esc(l.badge || '#475569') + '">'
            + Flood.esc(l.name) + '</span>';
    };

    /*
     * ตัวเลือกแบบลำดับชั้น ภาค → จังหวัด → อำเภอ
     *   <select data-rg-for="idของselectจังหวัด">  (flood_region_select)   — ตัวเลือกลูกมี data-rg
     *   <select data-pv-for="idของselectอำเภอ">    (flood_province_select) — ตัวเลือกลูกมี data-pv
     * เลือกตัวบน → ตัวล่างเหลือเฉพาะที่อยู่ในนั้น (ค่าเดิมไม่อยู่แล้ว = ล้าง แล้วส่ง change ต่อ)
     * เลือกตัวล่างก่อน → ตั้งตัวบนให้เอง
     */
    Flood.bindCascade = function (parentSel, attr) {
        var $p = $(parentSel);
        var $c = $('#' + $p.attr('data-' + attr + '-for'));
        if (!$c.length || $p.data('cascadeBound')) {
            return;
        }
        $p.data('cascadeBound', true);
        var $all = $c.children().clone();   // ตัวเลือกทั้งหมด (รวม optgroup) เก็บไว้สร้างใหม่
        function rebuild() {
            var v = String($p.val() || '');
            var cur = String($c.val() || '');
            $c.empty();
            $all.each(function () {
                var code = String($(this).attr('data-' + attr) || '');
                if (!v || !code || code === v) {
                    $c.append($(this).clone());
                }
            });
            var ok = $c.find('option').filter(function () { return this.value === cur; }).length > 0;
            $c.val(ok ? cur : '');
            return !ok && cur !== '';
        }
        $c.data('cascadeRebuild', rebuild);
        $p.on('change', function () {
            if (rebuild()) {
                $c.trigger('change');
            }
        });
        // ตัวล่างเปลี่ยน → ตั้งตัวบน (และตัวบนของตัวบน) ให้ตรง โดยไม่ล้างค่าของตัวล่าง
        $c.on('change cascade:sync', function () {
            var v = String($c.find('option:selected').attr('data-' + attr) || '');
            if (v && String($p.val() || '') !== v) {
                $p.val(v);
                rebuild();
                $p.trigger('cascade:sync');   // ตัวบนของตัวบน (ภาค) ปรับตามต่อ
            }
        });
        var v0 = String($c.find('option:selected').attr('data-' + attr) || '');
        if (v0 && !$p.val()) {
            $p.val(v0);
            $p.trigger('cascade:sync');
        }
        rebuild();
    };
    Flood.bindProvince = function (pvSel) { Flood.bindCascade(pvSel, 'pv'); };

    /** ตั้งค่า select อำเภอจากโค้ด (เช่นตอนเปิดฟอร์มแก้ไข) — ปรับ select จังหวัด/ภาคที่ผูกไว้ให้ตรงก่อน */
    Flood.setAmphoe = function (amSel, code) {
        var $am = $(amSel);
        var $pv = $('select[data-pv-for="' + $am.attr('id') + '"]');
        code = String(code || '');
        if ($pv.length) {
            var $rg = $('select[data-rg-for="' + $pv.attr('id') + '"]');
            if ($rg.length) {
                $rg.val('').trigger('change');
            }
            $pv.val(code ? code.substring(0, 2) : '').trigger('change');
            if (code) {
                $pv.trigger('cascade:sync');
            }
        }
        $am.val(code);
    };

    /*
     * ตำบล — ทั้งประเทศมีหลายพันตำบล จึงโหลดทีละอำเภอจาก api/tambons แล้วเก็บไว้ในรายการกลาง
     *   var tambons = Flood.tambonList(ข้อมูลที่หน้าเว็บส่งมา);
     *   Flood.loadTambons(amphoe).always(function () { ...ใช้ tambons... });
     */
    var tbList = [];
    var tbLoaded = {};
    var tbPending = {};
    var tbSeen = {};
    function tbAdd(t) {
        if (t && t.tambon_code && !tbSeen[t.tambon_code]) {
            tbSeen[t.tambon_code] = true;
            tbList.push(t);
        }
    }
    Flood.tambonList = function (seed) {
        (seed || []).forEach(tbAdd);
        return tbList;
    };
    Flood.loadTambons = function (amphoe) {
        amphoe = String(amphoe || '');
        if (!amphoe || tbLoaded[amphoe]) {
            return $.Deferred().resolve(tbList).promise();
        }
        if (!tbPending[amphoe]) {
            tbPending[amphoe] = $.ajax({ url: Flood.url('api/tambons'), data: { amphoe: amphoe }, dataType: 'json' })
                .done(function (o) {
                    (o && o.tambons || []).forEach(tbAdd);
                    tbLoaded[amphoe] = true;
                })
                .always(function () { delete tbPending[amphoe]; });
        }
        return tbPending[amphoe];
    };

    $(function () {
        $('select[data-rg-for]').each(function () { Flood.bindCascade(this, 'rg'); });
        $('select[data-pv-for]').each(function () { Flood.bindCascade(this, 'pv'); });
    });

})(window, jQuery);
