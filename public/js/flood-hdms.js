/*
 * ซิงก์จุดน้ำท่วมบนทางหลวงจากกรมทางหลวง (HDMS) → พื้นที่ประกาศ
 * เซิร์ฟเวอร์ของระบบออกอินเทอร์เน็ตไม่ได้ จึงให้เบราว์เซอร์ของเจ้าหน้าที่ (officer ขึ้นไป) ที่เปิดหน้าระบบอยู่
 * ดึงข้อมูลจาก https://hdms.doh.go.th (อนุญาต CORS) แล้วส่งต่อให้เซิร์ฟเวอร์ที่ flood/hdmsSync ทุก ~10 นาที
 * ถ้ามีเจ้าหน้าที่หลายคนเปิดอยู่ เซิร์ฟเวอร์รับรอบเดียว (auto=1 → ข้ามถ้าเพิ่งซิงก์ไป)
 */
(function (window, $) {
    'use strict';
    var cfg = window.FLOOD_HDMS;
    if (!cfg || !window.fetch) {
        return;
    }
    var FIELDS = ['case_id', 'case_name', 'latitude', 'longitude', 'road_code', 'section_name', 'km_start', 'km_end',
        'flood_level', 'lane_closure', 'road_closure_text', 'direction_text', 'cause_of_accident', 'bypass_desc',
        'initial_relief', 'tambon', 'amphoe', 'province', 'start_date', 'start_date_text', 'report_date_text'];
    var every = (cfg.every || 600) * 1000;
    var last = (cfg.last || 0) * 1000;
    var busy = false;

    function pad(n) { return (n < 10 ? '0' : '') + n; }

    function run(manual) {
        if (busy) {
            return $.Deferred().reject().promise();
        }
        busy = true;
        var d = new Date();
        var day = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
        var url = 'https://hdms.doh.go.th/internal-api/public/dashboard?start=' + day + '&end=' + day
            + '&searchText=&division=&district=&depot=&province=&amphoe=&tambon=&incidentType=&status=open&floodLevel=&passage=all';
        var out = $.Deferred();
        fetch(url, { credentials: 'omit' }).then(function (r) {
            if (!r.ok) {
                throw new Error('HTTP ' + r.status);
            }
            return r.json();
        }).then(function (j) {
            var items = [];
            (Array.isArray(j) ? j : Object.keys(j || {}).map(function (k) { return j[k]; })).forEach(function (it) {
                if (!it || !it.case_id) {
                    return;
                }
                var o = {};
                FIELDS.forEach(function (f) { o[f] = it[f] === undefined ? null : it[f]; });
                items.push(o);
            });
            if (!items.length) {
                throw new Error('ไม่มีข้อมูล');
            }
            return Flood.post('flood/hdmsSync', { items: JSON.stringify(items), auto: manual ? '' : '1' }, { silent: !manual, timeout: 120000 });
        }).then(function (o) {
            busy = false;
            last = (o.last || Math.floor(Date.now() / 1000)) * 1000;
            if (manual && o.msg) {
                Flood.toast(o.msg, 'success');
            }
            out.resolve(o);
        }, function (e) {
            busy = false;
            last = Date.now() - every + 120000;   // ลองใหม่ใน ~2 นาที
            if (manual) {
                Flood.toast('ดึงข้อมูลกรมทางหลวงไม่ได้' + (e && e.message ? ' (' + e.message + ')' : ''), 'danger');
            }
            out.reject(e);
        });
        return out.promise();
    }

    Flood.hdmsSync = function () { return run(true); };

    function tick() {
        if (!document.hidden && Date.now() - last >= every) {
            run(false);
        }
    }
    setTimeout(tick, 4000);
    setInterval(tick, 60000);
})(window, jQuery);
