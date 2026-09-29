/*
 * ห้อง SAT — ปุ่ม "ดึงประมวล" (หัวหน้า sat) · ดู models/sat_compile_model.php
 *
 * 1) ดึงข้อมูลล่าสุดจากเบราว์เซอร์ของเจ้าหน้าที่ (เซิร์ฟเวอร์โรงพยาบาลออกอินเทอร์เน็ตไม่ได้)
 *    - ThaiWater (สสน.) ระดับน้ำ / ฝน 24 ชม. / คำเตือน — คัดสถานีรัศมี 80 กม. จากโรงพยาบาลแล้วส่งเป็นชุดย่อให้ sat/compile
 *    - ชีตสาธารณูปโภค / ชีต Refer — FloodUtilSheet (utility.js) → sat/utilityImport, sat/referImport
 *    - ชีตรายงานศูนย์พักพิง — FloodSheet.pullDaily (sheet_pull.js) 3 วันล่าสุด → flood/vulnUpload
 *    แหล่งไหนดึงไม่ได้ก็ประมวลต่อจากข้อมูลที่นำเข้าไว้แล้ว
 * 2) sat/compile ประมวลรายหัวข้อ (สรุป + สีที่แนะนำ) → เจ้าหน้าที่ตรวจ/แก้ในหน้าต่าง
 * 3) sat/compileSave บันทึกหัวข้อที่ติ๊กลงประวัติตัวชี้วัด (การ์ดเปลี่ยนตาม)
 */
$(function () {
    'use strict';

    var P = window.SAT_PULL;
    var $m = $('#satPullModal');
    if (!P || !$m.length) {
        return;
    }
    var esc = Flood.esc;
    var COLORS = P.colors || {};
    var TW = 'https://api-v3.thaiwater.net/api/v1/thaiwater30/public/';
    var hosp = (window.SAT && window.SAT.hosp) || { lat: 13.803, lng: 102.077 };
    var state = { codes: [], title: '', rows: [], tw: null, running: false };

    /* ---------- ตัวช่วย ---------- */
    function distKm(aLat, aLng, bLat, bLng) {
        var r = Math.PI / 180;
        var dLat = (bLat - aLat) * r, dLng = (bLng - aLng) * r;
        var s = Math.sin(dLat / 2) * Math.sin(dLat / 2)
            + Math.cos(aLat * r) * Math.cos(bLat * r) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
        return 12742 * Math.asin(Math.min(1, Math.sqrt(s)));
    }
    function chip(s, text) {
        var c = COLORS[s];
        return '<span class="sat-chip sat-c-' + (c ? esc(s) : 'none') + '">' + (c ? c.emoji + ' ' + esc(text || c.label) : '⚪ ' + esc(text || 'ไม่มี')) + '</span>';
    }
    /** jQuery Deferred → Promise */
    function toP(d) {
        return new Promise(function (res, rej) { d.then(res, rej); });
    }
    function msgOf(e) {
        if (!e) {
            return 'ไม่สำเร็จ';
        }
        if (typeof e === 'string') {
            return e;
        }
        return e.msg || e.message || 'ไม่สำเร็จ';
    }

    /* ---------- แหล่งข้อมูล ---------- */
    function neededSources(codes) {
        var out = [];
        $.each(P.sources || {}, function (key, s) {
            if ((s.codes || []).some(function (c) { return codes.indexOf(c) >= 0; })) {
                out.push(key);
            }
        });
        return out;
    }
    function unavailable(key) {
        if (key === 'thaiwater') {
            return window.fetch && window.Promise ? '' : 'เบราว์เซอร์นี้ดึงไม่ได้';
        }
        if (key === 'util' || key === 'refer') {
            if (!window.FloodUtilSheet) {
                return 'ไม่พบตัวอ่านชีต';
            }
            return P[key] && P[key].sheet ? '' : 'ยังไม่ได้ตั้งลิงก์ชีต';
        }
        if (key === 'shelter') {
            if (!window.FloodSheet) {
                return 'ไม่พบตัวอ่านชีต';
            }
            return P.shelter && P.shelter.length ? '' : 'ยังไม่มีชีตศูนย์พักพิงในหน้ากลุ่มเปราะบาง';
        }
        return '';
    }
    function lastImport(key) {
        if (key === 'util' || key === 'refer') {
            return P[key] && P[key].last ? P[key].last : '';
        }
        return key === 'shelter' ? (P.shelterLast || '') : '';
    }
    function renderSources(keys) {
        var html = keys.map(function (k) {
            var s = P.sources[k];
            var na = unavailable(k);
            var last = lastImport(k);
            return '<li data-src="' + esc(k) + '"><input type="checkbox" id="satPs_' + esc(k) + '"' + (na ? ' disabled' : ' checked') + '>'
                + '<label for="satPs_' + esc(k) + '">' + esc(s.name) + (last ? ' <span class="sat-sub">· นำเข้าล่าสุด ' + esc(last) + '</span>' : '') + '</label>'
                + '<span class="sat-pull-st" data-st>' + (na ? esc(na) : '') + '</span></li>';
        }).join('');
        $m.find('[data-f="sources"]').html(html || '<li class="sat-muted">หัวข้อที่เลือกประมวลจากข้อมูลในระบบ — ไม่ต้องดึงเพิ่ม</li>');
        $m.find('[data-act="rerun"]').toggle(keys.length > 0);
    }
    function setSt(key, cls, text) {
        $m.find('li[data-src="' + key + '"] [data-st]').attr('class', 'sat-pull-st ' + cls).text(text);
    }
    function checkedSources() {
        var out = [];
        $m.find('[data-f="sources"] li[data-src]').each(function () {
            if ($(this).find('input').prop('checked')) {
                out.push(String($(this).data('src')));
            }
        });
        return out;
    }

    /* ---------- ThaiWater ---------- */
    function getJson(path) {
        return fetch(TW + path, { mode: 'cors', credentials: 'omit', cache: 'no-store' }).then(function (r) {
            if (!r.ok) {
                throw new Error('ThaiWater ตอบ ' + r.status);
            }
            return r.json();
        });
    }
    function th(o) {
        return o && o.th ? String(o.th) : '';
    }
    function kmTo(lat, lng) {
        lat = +lat;
        lng = +lng;
        return lat && lng ? distKm(hosp.lat, hosp.lng, lat, lng) : 9999;
    }
    function pullThaiWater() {
        var err = {};
        var tw = { water: null, rain: null, warn: [], err: err };
        var settle = function (p, key) {
            return p.then(function (v) { return { ok: true, v: v }; }, function (e) {
                err[key] = msgOf(e);
                return { ok: false };
            });
        };
        var water = getJson('waterlevel_load').then(function (o) {
            var list = [];
            ((o && o.waterlevel_data && o.waterlevel_data.data) || []).forEach(function (x) {
                var st = x.station || {};
                var g = x.geocode || {};
                var km = kmTo(st.tele_station_lat, st.tele_station_long);
                if (km > 80) {
                    return;
                }
                list.push({
                    name: th(st.tele_station_name), river: x.river_name || '', amphoe: th(g.amphoe_name), prov: th(g.province_name),
                    agency: th((x.agency || {}).agency_shortname), dt: x.waterlevel_datetime || '', lv: x.situation_level,
                    pct: x.storage_percent, diff: x.diff_wl_bank, above: /ล้น/.test(x.diff_wl_bank_text || ''),
                    msl: x.waterlevel_msl, prev: x.waterlevel_msl_previous, lat: +st.tele_station_lat, lng: +st.tele_station_long, km: km
                });
            });
            list.sort(function (a, b) { return a.km - b.km; });
            tw.water = list.slice(0, 40);
            return 'ระดับน้ำ ' + tw.water.length + ' สถานี';
        });
        var rain = getJson('rain_24h').then(function (o) {
            var list = [];
            ((o && o.data) || []).forEach(function (x) {
                var st = x.station || {};
                var g = x.geocode || {};
                var km = kmTo(st.tele_station_lat, st.tele_station_long);
                if (km > 80 || x.rain_24h === null || x.rain_24h === undefined) {
                    return;
                }
                list.push({
                    name: th(st.tele_station_name), amphoe: th(g.amphoe_name), prov: th(g.province_name), agency: th((x.agency || {}).agency_shortname),
                    dt: x.rainfall_datetime || '', r24: x.rain_24h, r1: x.rain_1h, lat: +st.tele_station_lat, lng: +st.tele_station_long, km: km
                });
            });
            // สถานีฝนมากสุด + สถานีใกล้โรงพยาบาล (ส่งเฉพาะที่ใช้)
            var seen = {};
            tw.rain = [];
            list.slice().sort(function (a, b) { return b.r24 - a.r24; }).slice(0, 40)
                .concat(list.slice().sort(function (a, b) { return a.km - b.km; }).slice(0, 20))
                .forEach(function (x) {
                    var k = x.name + '|' + x.lat + '|' + x.lng;
                    if (!seen[k]) {
                        seen[k] = 1;
                        tw.rain.push(x);
                    }
                });
            return 'ฝน ' + list.length + ' สถานี';
        });
        var warn = getJson('warning').then(function (o) {
            var seen = {};
            ((o && o.data) || []).forEach(function (x) {
                String(x.message || '').split(/\n\s*\n/).forEach(function (seg) {
                    seg = $.trim(seg);
                    if (seg && seg.indexOf('จ.สระแก้ว') >= 0 && !seen[seg]) {
                        seen[seg] = 1;
                        tw.warn.push({ dt: x.datetime || '', msg: seg });
                    }
                });
            });
            return 'คำเตือน จ.สระแก้ว ' + tw.warn.length;
        });
        return Promise.all([settle(water, 'water'), settle(rain, 'rain'), settle(warn, 'warn')]).then(function (res) {
            state.tw = tw;
            var ok = res.filter(function (r) { return r.ok; }).map(function (r) { return r.v; });
            if (!ok.length) {
                throw new Error('ดึงไม่ได้ (' + (err.water || err.rain || err.warn || 'ไม่ทราบสาเหตุ') + ')');
            }
            return ok.join(' · ') + (ok.length < 3 ? ' (บางส่วนไม่สำเร็จ)' : '');
        });
    }

    /* ---------- ชีต Google ---------- */
    function pullGrid(key, endpoint) {
        var cfg = P[key];
        setSt(key, 'is-run', 'กำลังอ่านชีต…');
        return toP(window.FloodUtilSheet.pull(cfg.sheet, cfg.tabs || [], function (n, t) {
            setSt(key, 'is-run', 'อ่านชีต ' + n + (t ? '/' + t : '') + ' แถว');
        })).then(function (sheets) {
            setSt(key, 'is-run', 'กำลังนำเข้า…');
            return toP(Flood.post(endpoint, { sheets: JSON.stringify(sheets) }, { timeout: 180000, silent: true }));
        }).then(function (o) {
            return (o && o.msg) || 'นำเข้าแล้ว';
        }, function (e) {
            throw new Error(msgOf(e));
        });
    }
    function pullShelter() {
        var out = [];
        var days = 0;
        var chain = Promise.resolve();
        setSt('shelter', 'is-run', 'กำลังหาแท็บรายวัน…');
        (P.shelter || []).forEach(function (s) {
            chain = chain.then(function () {
                return toP(window.FloodSheet.pullDaily('flood/vulnUpload', s.id, s.url, 3)).then(function (r) {
                    var ok = (r && r.ok) || [];
                    var bad = (r && r.bad) || [];
                    days += ok.length;
                    out.push(s.name + ': ' + (ok.length ? 'นำเข้า ' + ok.length + ' วัน' : 'ไม่พบแท็บรายวัน 3 วันล่าสุด')
                        + (r && r.missingToday ? ' (ยังไม่มีแท็บ "' + r.missingToday + '")' : '') + (bad.length ? ' · ผิดพลาด ' + bad.length : ''));
                }, function (e) {
                    out.push(s.name + ': ' + msgOf(e));
                });
            });
        });
        return chain.then(function () {
            if (!days) {
                // อ่านแท็บไม่ได้เลย: ไม่มีแท็บช่วงนั้นจริง หรือเบราว์เซอร์ยังไม่ได้ล็อกอิน Google ที่มีสิทธิ์ดูชีต
                throw new Error(out.join(' · ') + ' — ถ้าชีตมีแท็บวันนั้น ให้ล็อกอิน Google บัญชีที่มีสิทธิ์ดูชีต');
            }
            return out.join(' · ');
        });
    }

    /* ---------- ดึง → ประมวล ---------- */
    function run() {
        if (state.running) {
            return;
        }
        state.running = true;
        var keys = checkedSources();
        var $rows = $m.find('[data-f="rows"]');
        $m.find('[data-save="pull"]').prop('disabled', true);
        $m.find('[data-f="sources"] input, [data-act="rerun"]').prop('disabled', true);
        $rows.html('<div class="sat-muted"><i class="fa fa-spinner fa-spin"></i> กำลังดึงข้อมูล…</div>');
        state.tw = null;
        var ok = function (key) {
            return function (msg) { setSt(key, 'is-ok', '✓ ' + msg); };
        };
        var fail = function (key) {
            return function (e) { setSt(key, 'is-err', '✗ ' + msgOf(e) + ' — ใช้ข้อมูลที่นำเข้าไว้แล้ว'); };
        };
        var twP = Promise.resolve();
        if (keys.indexOf('thaiwater') >= 0) {
            setSt('thaiwater', 'is-run', 'กำลังดึง…');
            twP = pullThaiWater().then(ok('thaiwater'), fail('thaiwater'));
        }
        // ชีต Google ทีละชีต (ไม่ยิงคำขอพร้อมกันเกินไป)
        var sheetP = Promise.resolve();
        if (keys.indexOf('util') >= 0) {
            sheetP = sheetP.then(function () { return pullGrid('util', 'sat/utilityImport').then(ok('util'), fail('util')); });
        }
        if (keys.indexOf('refer') >= 0) {
            sheetP = sheetP.then(function () { return pullGrid('refer', 'sat/referImport').then(ok('refer'), fail('refer')); });
        }
        if (keys.indexOf('shelter') >= 0) {
            sheetP = sheetP.then(function () { return pullShelter().then(ok('shelter'), fail('shelter')); });
        }
        Promise.all([twP, sheetP]).then(function () {
            $rows.html('<div class="sat-muted"><i class="fa fa-spinner fa-spin"></i> กำลังประมวล…</div>');
            return toP(Flood.post('sat/compile', { codes: state.codes.join(','), tw: state.tw ? JSON.stringify(state.tw) : '' }, { timeout: 120000 }));
        }).then(function (o) {
            state.rows = o.rows || [];
            renderRows(o);
        }, function (e) {
            $rows.html('<div class="alert alert-danger">ประมวลไม่สำเร็จ: ' + esc(msgOf(e)) + '</div>');
        }).then(function () {
            state.running = false;
            $m.find('[data-f="sources"] li[data-src]').each(function () {
                $(this).find('input').prop('disabled', !!unavailable(String($(this).data('src'))));
            });
            $m.find('[data-act="rerun"]').prop('disabled', false);
        });
    }

    /* ---------- ผลประมวล ---------- */
    function colorButtons(val) {
        return $.map(COLORS, function (c, k) {
            return '<button type="button" class="sat-pull-c sat-c-' + esc(k) + (k === val ? ' is-on' : '') + '" data-v="' + esc(k) + '" title="'
                + esc(c.name) + '">' + c.emoji + ' ' + esc(c.label) + '</button>';
        }).join('');
    }
    function renderRows(o) {
        $m.find('[data-f="at"]').text(o.at ? 'ประมวล ' + o.at : '');
        if (!state.rows.length) {
            $m.find('[data-f="rows"]').html('<div class="sat-muted">ไม่มีหัวข้อ</div>');
            return;
        }
        var html = state.rows.map(function (r, i) {
            var def = r.status || r.cur_status || '';
            // ติ๊กให้เมื่อมีข้อมูล และ (ระบบแนะนำสี หรือตัวชี้วัดยังไม่เคยอัปเดต/เกินรอบ) — หัวข้อที่ SAT เพิ่งสรุปเองไม่ทับให้
            var on = r.has && (r.status !== '' || r.cur_status === '' || r.cur_stale);
            var lines = Math.min(10, Math.max(2, (r.text || '').split('\n').length + 1));
            return '<div class="sat-pull-row' + (on ? '' : ' is-off') + '" data-i="' + i + '">'
                + '<div class="sat-pull-row-h"><label><input type="checkbox" class="js-pr-on"' + (on ? ' checked' : '') + '> '
                + esc(r.emoji + ' ' + r.name) + '</label>'
                + '<span class="sat-pull-cur">ปัจจุบัน ' + chip(r.cur_status) + (r.cur_at ? ' ' + esc(r.cur_at) : '') + '</span>'
                + '<span class="sat-pull-colors" data-val="' + esc(def) + '">' + colorButtons(def) + '</span></div>'
                + '<textarea class="form-control js-pr-note" rows="' + lines + '" maxlength="2000" placeholder="'
                + esc(r.note || 'ไม่มีข้อมูลจากระบบ — พิมพ์สรุปเองได้') + '">' + esc(r.text) + '</textarea>'
                + '<div class="sat-pull-meta">'
                + (r.status ? 'ระบบแนะนำ ' + chip(r.status) : (r.has ? 'ไม่มีเกณฑ์สีจากข้อมูล — ใช้สีเดิม' : ''))
                + (r.src && r.src.length ? ' · ที่มา: ' + esc(r.src.join(' · ')) : '')
                + (r.note && r.has ? ' · ' + esc(r.note) : '')
                + (r.cur_note ? ' · <a href="#" class="js-pr-keep">+ ต่อท้ายสรุปเดิม</a>' : '')
                + (r.rule ? '<div class="sat-muted">เกณฑ์สี: ' + esc(r.rule) + '</div>' : '')
                + '</div></div>';
        }).join('');
        $m.find('[data-f="rows"]').html(html);
        count();
    }
    function count() {
        var n = $m.find('.js-pr-on:checked').length;
        $m.find('[data-f="count"]').text(n ? 'เลือก ' + n + ' หัวข้อ' : '');
        $m.find('[data-save="pull"]').prop('disabled', !n || state.running);
    }
    $m.on('change', '.js-pr-on', function () {
        $(this).closest('.sat-pull-row').toggleClass('is-off', !this.checked);
        count();
    });
    $m.on('click', '.sat-pull-c', function () {
        var $g = $(this).closest('.sat-pull-colors');
        $g.find('.sat-pull-c').removeClass('is-on');
        $(this).addClass('is-on');
        $g.data('val', String($(this).data('v')));
        var $row = $(this).closest('.sat-pull-row');
        if (!$row.find('.js-pr-on').prop('checked')) {
            $row.find('.js-pr-on').prop('checked', true).trigger('change');
        }
    });
    $m.on('input', '.js-pr-note', function () {
        var $row = $(this).closest('.sat-pull-row');
        if ($.trim($(this).val()) !== '' && !$row.find('.js-pr-on').prop('checked')) {
            $row.find('.js-pr-on').prop('checked', true).trigger('change');
        }
    });
    $m.on('click', '.js-pr-keep', function (e) {
        e.preventDefault();
        var $row = $(this).closest('.sat-pull-row');
        var r = state.rows[+$row.data('i')];
        var $n = $row.find('.js-pr-note');
        var cur = $.trim($n.val());
        if (r && r.cur_note && cur.indexOf(r.cur_note) < 0) {
            $n.val(cur ? cur + '\n' + r.cur_note : r.cur_note).trigger('input');
        }
    });

    $m.on('click', '[data-save="pull"]', function () {
        var list = [];
        $m.find('.sat-pull-row').each(function () {
            var $r = $(this);
            if (!$r.find('.js-pr-on').prop('checked')) {
                return;
            }
            var r = state.rows[+$r.data('i')];
            list.push({ code: r.code, status: String($r.find('.sat-pull-colors').data('val') || ''), note: $r.find('.js-pr-note').val(), data: r.data || null });
        });
        if (!list.length) {
            Flood.toast('ติ๊กหัวข้อที่จะบันทึก', 'warning');
            return;
        }
        var empty = list.filter(function (x) { return !x.status && !$.trim(x.note); });
        if (empty.length) {
            Flood.toast('หัวข้อที่ติ๊กต้องมีสีหรือสรุปอย่างน้อยหนึ่งอย่าง', 'warning');
            return;
        }
        var $b = $(this);
        Flood.busy($b, true);
        Flood.post('sat/compileSave', { items: JSON.stringify(list) }).then(function (o) {
            Flood.toast(o.msg || 'บันทึกแล้ว', 'success');
            setTimeout(function () { window.location.reload(); }, 900);
        }, function () {
            Flood.busy($b, false);
        });
    });

    /* ---------- เมนู ---------- */
    function open(codes, title) {
        state.codes = codes;
        state.title = title || '';
        state.rows = [];
        $m.find('[data-f="title"]').text(state.title);
        $m.find('[data-f="at"], [data-f="count"]').text('');
        renderSources(neededSources(codes));
        $m.find('[data-save="pull"]').prop('disabled', true);
        $m.modal('show');
        run();
    }
    $(document).on('click', '.js-sat-pull', function (e) {
        e.preventDefault();
        var codes = String($(this).data('codes') || '').split(',').filter(function (x) { return x !== ''; });
        if (codes.length) {
            open(codes, $(this).data('title'));
        }
    });
    $m.on('click', '[data-act="rerun"]', function () {
        run();
    });
});
