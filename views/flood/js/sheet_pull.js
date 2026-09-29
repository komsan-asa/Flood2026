/*
 * อ่าน Google Sheet จากเบราว์เซอร์ของเจ้าหน้าที่ แล้วส่งเข้าระบบเป็น CSV
 * เซิร์ฟเวอร์โรงพยาบาลออกอินเทอร์เน็ตไม่ได้ → ใช้ gviz (JSONP) ซึ่งอ่านชีตที่ตั้ง "จำกัด" ได้
 * ถ้าเบราว์เซอร์ล็อกอิน Google บัญชีที่มีสิทธิ์ดูชีต (วิธีเดียวกับหน้าบุคลากร — views/flood/js/staff.js)
 *
 *   FloodSheet.pull(endpoint, sourceId, sheetUrl) → promise(ผลจากเซิร์ฟเวอร์ {msg, ...})
 *   FloodSheet.load(sheetUrl, {tab: 'ชื่อแท็บ', headers: 3}) → promise(table ของ gviz)
 *     tab = อ่านแท็บตามชื่อ (ถ้าไม่มีแท็บนั้น Google ส่งแท็บแรกกลับมาแทน — ผู้เรียกต้องตรวจเอง)
 *     headers = จำนวนแถวหัวคอลัมน์ (หัวที่ผสานช่องหลายแถว ใส่ 3 ให้ Google รวมเป็นชื่อคอลัมน์เดียว)
 */
window.FloodSheet = (function () {
    'use strict';

    function parts(url) {
        var m = /\/spreadsheets\/d\/([a-zA-Z0-9_-]{20,})/.exec(url || '');
        var g = /[#&?]gid=(\d+)/.exec(url || '');
        return m ? { id: m[1], gid: g ? g[1] : '0' } : null;
    }

    function load(url, opt) {
        opt = opt || {};
        var d = $.Deferred();
        var p = parts(url);
        if (!p) {
            return d.reject('ลิงก์ชีตไม่ถูกต้อง').promise();
        }
        var cb = '__floodSheet' + Date.now() + Math.floor(Math.random() * 1000);
        var s = document.createElement('script');
        var timer;
        var done = function (fn, v) {
            clearTimeout(timer);
            try { delete window[cb]; } catch (e) { window[cb] = undefined; }
            if (s.parentNode) { s.parentNode.removeChild(s); }
            fn(v);
        };
        timer = setTimeout(function () { done(d.reject, 'Google ไม่ตอบกลับ (หมดเวลา)'); }, 30000);
        window[cb] = function (o) {
            if (!o || o.status !== 'ok' || !o.table) {
                done(d.reject, 'อ่านชีตไม่ได้ — ล็อกอิน Google บัญชีที่มีสิทธิ์ดูชีตนี้ในเบราว์เซอร์ หรือดาวน์โหลด CSV แล้วอัปโหลด');
                return;
            }
            done(d.resolve, o.table);
        };
        s.onerror = function () { done(d.reject, 'โหลดชีตไม่ได้ — ล็อกอิน Google บัญชีที่มีสิทธิ์ดูชีต หรือดาวน์โหลด CSV แล้วอัปโหลด'); };
        s.src = 'https://docs.google.com/spreadsheets/d/' + p.id + '/gviz/tq?'
            + (opt.tab ? 'sheet=' + encodeURIComponent(opt.tab) : 'gid=' + p.gid)
            + '&headers=' + (opt.headers || 1) + '&tqx=responseHandler:' + cb + ';out:json';
        document.head.appendChild(s);
        return d.promise();
    }

    function toCsv(t) {
        var q = function (v) {
            v = v === null || v === undefined ? '' : String(v);
            return /[",\r\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v;
        };
        var lines = [t.cols.map(function (c) { return q(c.label); }).join(',')];
        t.rows.forEach(function (r) {
            lines.push(t.cols.map(function (c, i) {
                var cell = r.c[i];
                if (!cell) { return ''; }
                return q(cell.f !== undefined && cell.f !== null ? cell.f : cell.v);
            }).join(','));
        });
        return '﻿' + lines.join('\r\n');
    }

    function send(endpoint, fields, table) {
        var fd = new FormData();
        Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
        fd.append('csv', new Blob([toCsv(table)], { type: 'text/csv' }), 'sheet.csv');
        return Flood.post(endpoint, fd, { timeout: 200000, silent: true }).then(function (o) { return o; },
            function (o) { return $.Deferred().reject((o && o.msg) || 'นำเข้าไม่สำเร็จ').promise(); });
    }

    function pull(endpoint, sourceId, url) {
        return load(url).then(function (t) { return send(endpoint, { source_id: sourceId }, t); });
    }

    /* ---------- รายงานรายวันแยกแท็บ (เช่น แท็บ "28 ก.ย.69") ---------- */
    var MON = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    var MON_FULL = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
    var pad = function (n) { return ('0' + n).slice(-2); };
    var ymd = function (d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); };

    /** ชื่อแท็บที่เจ้าหน้าที่มักตั้ง: "28 ก.ย.69" (แบบหลัก) · "28 ก.ย. 69" · "28ก.ย.69" · "01 ต.ค.69" */
    function tabNames(d) {
        var m = MON[d.getMonth()];
        var yy = pad((d.getFullYear() + 543) % 100);
        return [d.getDate() + ' ' + m + yy, d.getDate() + ' ' + m + ' ' + yy, d.getDate() + m + yy, pad(d.getDate()) + ' ' + m + yy];
    }

    /** หัวรายงานของแท็บมีวันที่ตรงกับวันที่ขอ (ถ้าไม่มีแท็บนั้น Google ส่งแท็บแรกกลับมา — ต้องไม่รับ) */
    function tableIsDate(t, d) {
        var txt = t.cols.map(function (c) { return c.label || ''; }).join(' ').replace(/\s+/g, ' ');
        return txt.indexOf(d.getDate() + ' ' + MON_FULL[d.getMonth()]) >= 0 || txt.indexOf(d.getDate() + ' ' + MON[d.getMonth()]) >= 0;
    }

    function findTab(url, d, names) {
        var i = 0;
        var next = function () {
            if (i >= names.length) {
                return $.Deferred().resolve(null).promise();
            }
            var name = names[i++];
            return load(url, { tab: name, headers: 3 }).then(function (t) {
                return tableIsDate(t, d) ? { name: name, date: d, table: t } : next();
            }, function () { return next(); });
        };
        return next();
    }

    /** รันงานทีละ n งานพร้อมกัน */
    function pool(items, n, fn) {
        var out = new Array(items.length);
        var idx = 0;
        var d = $.Deferred();
        var running = 0;
        var kick = function () {
            if (idx >= items.length && running === 0) {
                d.resolve(out);
                return;
            }
            while (running < n && idx < items.length) {
                (function (k) {
                    running++;
                    fn(items[k]).always(function (v) { out[k] = v; running--; kick(); });
                })(idx++);
            }
        };
        kick();
        return d.promise();
    }

    /**
     * ดึงแท็บรายวันย้อนหลัง days วัน (รวมวันนี้) แล้วส่งทีละแท็บ → promise({ok:[ข้อความ], bad:[ข้อความ], missingToday: 'ชื่อแท็บ'|null})
     * รอบแรกลองชื่อแบบหลักทุกวัน · วันที่ยังไม่พบระหว่างวันแรกที่พบถึงวันนี้ ลองชื่อแบบอื่นอีกรอบ
     */
    function pullDaily(endpoint, sourceId, url, days) {
        var dates = [];
        var today = new Date();
        for (var i = (days || 21) - 1; i >= 0; i--) {
            var d = new Date(today.getFullYear(), today.getMonth(), today.getDate() - i);
            dates.push(d);
        }
        return pool(dates, 4, function (d) { return findTab(url, d, tabNames(d).slice(0, 1)); }).then(function (found) {
            var first = -1;
            found.forEach(function (f, k) { if (f && first < 0) { first = k; } });
            var retry = [];
            dates.forEach(function (d, k) {
                if (!found[k] && (first < 0 ? k >= dates.length - 3 : k > first)) {
                    retry.push(k);
                }
            });
            return pool(retry, 4, function (k) { return findTab(url, dates[k], tabNames(dates[k]).slice(1)); }).then(function (more) {
                retry.forEach(function (k, j) { found[k] = more[j]; });
                return found;
            });
        }).then(function (found) {
            var res = { ok: [], bad: [], missingToday: found[found.length - 1] ? null : tabNames(today)[0] };
            var chain = $.Deferred().resolve().promise();
            found.forEach(function (f) {
                if (!f) {
                    return;
                }
                chain = chain.then(function () {
                    return send(endpoint, { source_id: sourceId, mode: 'shelter_day', date: ymd(f.date), tab: f.name }, f.table)
                        .then(function (o) { if (!o.skipped) { res.ok.push(o.msg); } },
                            function (m) { res.bad.push(f.name + ': ' + m); return $.Deferred().resolve().promise(); });
                });
            });
            return chain.then(function () { return res; });
        });
    }

    return { load: load, toCsv: toCsv, pull: pull, pullDaily: pullDaily };
})();
