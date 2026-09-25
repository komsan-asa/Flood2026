/*
 * ตัวนับเวลารีเฟรชหน้าอัตโนมัติ (แถบด้านบนของทุกหน้าเจ้าหน้าที่ — #floodRefresh ใน views/flood/_topbar.php)
 * - ผู้ใช้เลือกรอบได้: ปิด / 1 / 2 / 5 / 10 นาที (จำไว้ในเบราว์เซอร์นี้)
 * - พักการนับเมื่อ: กำลังพิมพ์/กรอกฟอร์ม, มีหน้าต่าง (modal) หรือเมนู dropdown เปิดอยู่, สลับไปแท็บอื่น
 * - หน้าฟอร์ม (controller ตั้ง $this->view->autoRefresh = false) แสดงเวลาอย่างเดียว ไม่รีเฟรชเอง
 */
(function ($) {
    'use strict';
    var box = document.getElementById('floodRefresh');
    if (!box) {
        return;
    }
    var KEY = 'flood_refresh_sec';
    var auto = box.getAttribute('data-auto') !== '0';
    var def = parseInt(box.getAttribute('data-default') || '120', 10);
    var $cd = $(box).find('[data-rf="cd"]');
    var $sel = $(box).find('[data-rf="sel"]');
    var interval = 0;
    var remaining = 0;
    var dirty = false;

    function load() {
        try {
            var v = window.localStorage.getItem(KEY);
            return v === null ? def : parseInt(v, 10) || 0;
        } catch (e) {
            return def;
        }
    }
    function save(v) {
        try { window.localStorage.setItem(KEY, String(v)); } catch (e) { /* โหมดส่วนตัว */ }
    }
    function inBox(el) {
        return el && $.contains(box, el);
    }
    function paused() {
        var a = document.activeElement;
        if (dirty) {
            return 'หยุดรีเฟรชขณะกรอกข้อมูล';
        }
        if (document.hidden) {
            return 'พัก';
        }
        if ($('.modal.in, .modal.show, .dropdown.open').length) {
            return 'พัก (เปิดหน้าต่างอยู่)';
        }
        if (a && !inBox(a) && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName)) {
            return 'พัก (กำลังพิมพ์)';
        }
        return '';
    }
    function fmt(s) {
        var m = Math.floor(s / 60);
        var r = s % 60;
        return m + ':' + (r < 10 ? '0' : '') + r;
    }
    function render() {
        if (!auto || !interval) {
            $cd.text(auto ? '' : 'หน้าฟอร์ม ไม่รีเฟรชเอง').toggleClass('rf-paused', !auto);
            return;
        }
        var p = paused();
        $cd.toggleClass('rf-paused', !!p).text(p ? p : 'รีเฟรชใน ' + fmt(remaining));
    }
    function start(v) {
        interval = v;
        remaining = v;
        render();
    }

    // พิมพ์อะไรในฟอร์มของหน้า (ที่ไม่ใช่ตัวเลือกรอบรีเฟรช) = หยุดนับ กันข้อมูลหาย
    document.addEventListener('input', function (e) {
        if (!inBox(e.target) && $(e.target).closest('form, .modal').length) {
            dirty = true;
            render();
        }
    }, true);

    $sel.on('change', function () {
        var v = parseInt($sel.val(), 10) || 0;
        save(v);
        start(v);
    });
    $(box).find('[data-rf="now"]').on('click', function () {
        window.location.reload();
    });

    if (auto) {
        var v0 = load();
        if (!$sel.find('option[value="' + v0 + '"]').length) {
            v0 = def;
        }
        $sel.val(String(v0));
        start(v0);
        setInterval(function () {
            if (interval && !paused()) {
                remaining -= 1;
                if (remaining <= 0) {
                    window.location.reload();
                    return;
                }
            }
            render();
        }, 1000);
    } else {
        render();
    }
})(jQuery);
