/*
 * สาธารณูปโภคโรงพยาบาล — ดึง Google Sheet งานช่าง 4 แท็บ หรืออัปโหลด .xlsx แล้วส่งเป็นตาราง (grid) ให้ sat/utilityImport
 *
 * เซิร์ฟเวอร์โรงพยาบาลออกอินเทอร์เน็ตไม่ได้ → เบราว์เซอร์อ่านชีตเองด้วย gviz (JSONP)
 * gviz เดาชนิดข้อมูลทั้งคอลัมน์ ข้อความในคอลัมน์ตัวเลข (เช่น "ระดับถังล่าง เต็ม..150..Cm", "หมดถัง", ชื่ออาคาร) จะหาย
 * จึงอ่านทีละแถว (range=A{n}:Z{n}) — แถวเดียวไม่มีปัญหาชนิดข้อมูลปน
 */
$(function () {
    'use strict';

    var U = window.UTIL || {};
    var COLS = 'A:Z';

    function sheetId(url) {
        var m = /\/spreadsheets\/d\/([a-zA-Z0-9_-]{20,})/.exec(url || '');
        return m ? m[1] : null;
    }

    function gviz(id, tab, range) {
        var d = $.Deferred();
        var cb = '__floodUt' + Date.now() + Math.floor(Math.random() * 100000);
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
                done(d.reject, 'อ่านแท็บ "' + tab + '" ไม่ได้ — ล็อกอิน Google บัญชีที่มีสิทธิ์ดูชีตนี้ หรือตรวจชื่อแท็บ');
                return;
            }
            done(d.resolve, o.table);
        };
        s.onerror = function () { done(d.reject, 'โหลดชีตไม่ได้ — ล็อกอิน Google บัญชีที่มีสิทธิ์ดูชีต หรือใช้ปุ่มอัปโหลด .xlsx'); };
        s.src = 'https://docs.google.com/spreadsheets/d/' + id + '/gviz/tq?headers=0&sheet=' + encodeURIComponent(tab)
            + (range ? '&range=' + range : '') + '&tqx=responseHandler:' + cb + ';out:json';
        document.head.appendChild(s);
        return d.promise();
    }

    function cellText(c) {
        if (!c || c.v === null || c.v === undefined) {
            return '';
        }
        if (typeof c.v === 'number' || typeof c.v === 'string') {
            return String(c.v);
        }
        return c.f !== undefined && c.f !== null ? String(c.f) : String(c.v);
    }

    function rowCells(t) {
        var r = t.rows && t.rows[0];
        if (!r) {
            return [];
        }
        return t.cols.map(function (c, i) { return cellText(r.c[i]); });
    }

    /**
     * อ่านทั้งแท็บทีละแถว (พร้อมกันครั้งละ 6)
     * gviz ตัดแถวว่างทิ้ง จำนวนแถวที่ได้ (n) จึงน้อยกว่าเลขแถวจริง — ไล่เลขแถวไปจนเจอแถวที่มีข้อมูลครบ n แถว
     * แล้วเลยไปอีกอย่างน้อย 10 แถวว่างติดกัน (กันแถวที่เพิ่งพิมพ์ต่อท้าย)
     */
    function pullTab(id, tab, progress) {
        return gviz(id, tab, '').then(function (t) {
            var n = t.rows ? t.rows.length : 0;
            var grid = [];
            var next = 0;
            var d = $.Deferred();
            var running = 0;
            var failed = false;
            var filled = 0;
            var LIMIT = 3000;
            function lastFilled() {
                for (var i = grid.length - 1; i >= 0; i--) {
                    if (grid[i] && grid[i].some(function (v) { return v !== ''; })) { return i; }
                }
                return -1;
            }
            function enough() {
                return filled >= n && next - 1 - lastFilled() >= 10;
            }
            function pump() {
                if (failed) {
                    return;
                }
                if ((enough() || next >= LIMIT) && running === 0) {
                    d.resolve(grid.slice(0, lastFilled() + 1).map(function (r) { return r || []; }));
                    return;
                }
                while (running < 6 && next < LIMIT && !enough()) {
                    (function (i) {
                        running++;
                        next++;
                        gviz(id, tab, 'A' + (i + 1) + ':Z' + (i + 1)).then(function (rt) {
                            var cells = rowCells(rt);
                            grid[i] = cells;
                            if (cells.some(function (v) { return v !== ''; })) {
                                filled++;
                                progress();
                            }
                            running--;
                            pump();
                        }, function (err) {
                            failed = true;
                            d.reject(err);
                        });
                    })(next);
                }
            }
            pump();
            return d.promise().then(function (g) { return { tab: tab, grid: g, rows: n }; });
        });
    }

    function send(sheets) {
        var $r = $('#utResult');
        return Flood.post(U.endpoint || 'sat/utilityImport', { sheets: JSON.stringify(sheets) }, { timeout: 180000 }).then(function (o) {
            $r.removeClass('hidden alert-danger').addClass('alert-success').text(o.msg + ' — กำลังโหลดหน้าใหม่…');
            setTimeout(function () { window.location.reload(); }, 1500);
        }, function (o) {
            $r.removeClass('hidden alert-success').addClass('alert-danger').text((o && o.msg) || 'นำเข้าไม่สำเร็จ');
        });
    }

    $('#utPull').on('click', function () {
        var $b = $(this);
        var id = sheetId(U.sheet);
        var tabs = U.tabs || [];
        if (!id || !tabs.length) {
            Flood.toast('ยังไม่ได้ตั้งลิงก์ชีต/ชื่อแท็บ', 'warning');
            return;
        }
        var total = 0;
        var doneRows = 0;
        Flood.busy($b, true, 'กำลังอ่านชีต…');
        var prog = function () {
            doneRows++;
            $b.html('<i class="fa fa-spinner fa-spin"></i> อ่านชีต ' + doneRows + (total ? '/' + total : '') + ' แถว');
        };
        $.when.apply($, tabs.map(function (tab) {
            return gviz(id, tab, '').then(function (t) { total += t.rows ? t.rows.length : 0; });
        })).then(function () {
            var out = {};
            var chain = $.Deferred().resolve().promise();
            tabs.forEach(function (tab) {
                chain = chain.then(function () {
                    return pullTab(id, tab, prog).then(function (r) { out[r.tab] = r.grid; });
                });
            });
            return chain.then(function () {
                $b.html('<i class="fa fa-spinner fa-spin"></i> กำลังบันทึก…');
                return send(out);
            });
        }).always(function () {
            Flood.busy($b, false);
        }).fail(function (err) {
            if (typeof err === 'string') {
                $('#utResult').removeClass('hidden alert-success').addClass('alert-danger').text(err);
            }
        });
    });

    /* ---------- อัปโหลด .xlsx (อ่านในเบราว์เซอร์ ไม่ต้องใช้ไลบรารี) ---------- */

    function unzip(buf) {
        var bin = new Uint8Array(buf);
        var dv = new DataView(buf);
        var eocd = -1;
        for (var i = bin.length - 22; i >= 0; i--) {
            if (dv.getUint32(i, true) === 0x06054b50) { eocd = i; break; }
        }
        if (eocd < 0) {
            throw new Error('ไฟล์ไม่ใช่ .xlsx');
        }
        var n = dv.getUint16(eocd + 10, true);
        var off = dv.getUint32(eocd + 16, true);
        var files = {};
        var td = new TextDecoder();
        for (var k = 0; k < n; k++) {
            var nlen = dv.getUint16(off + 28, true);
            var name = td.decode(bin.slice(off + 46, off + 46 + nlen));
            files[name] = { method: dv.getUint16(off + 10, true), csize: dv.getUint32(off + 20, true), lho: dv.getUint32(off + 42, true) };
            off += 46 + nlen + dv.getUint16(off + 30, true) + dv.getUint16(off + 32, true);
        }
        return function read(name) {
            var f = files[name];
            if (!f) {
                return Promise.resolve(null);
            }
            var ln = dv.getUint16(f.lho + 26, true);
            var le = dv.getUint16(f.lho + 28, true);
            var data = bin.slice(f.lho + 30 + ln + le, f.lho + 30 + ln + le + f.csize);
            if (f.method === 0) {
                return Promise.resolve(td.decode(data));
            }
            var ds = new DecompressionStream('deflate-raw');
            return new Response(new Blob([data]).stream().pipeThrough(ds)).arrayBuffer().then(function (b) { return td.decode(b); });
        };
    }

    function unxml(s) {
        return s.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&apos;/g, "'").replace(/&amp;/g, '&');
    }

    function colIndex(s) {
        var n = 0;
        for (var i = 0; i < s.length; i++) { n = n * 26 + s.charCodeAt(i) - 64; }
        return n - 1;
    }

    function readXlsx(buf) {
        var read = unzip(buf);
        var ss = [];
        var rel = {};
        return Promise.all([read('xl/workbook.xml'), read('xl/_rels/workbook.xml.rels'), read('xl/sharedStrings.xml')]).then(function (x) {
            var wb = x[0] || '';
            var m;
            var reR = /<Relationship\b[^>]*>/g;
            while ((m = reR.exec(x[1] || ''))) {
                var id = /Id="([^"]+)"/.exec(m[0]);
                var tg = /Target="([^"]+)"/.exec(m[0]);
                if (id && tg) { rel[id[1]] = tg[1]; }
            }
            var reS = /<si>([\s\S]*?)<\/si>/g;
            while ((m = reS.exec(x[2] || ''))) {
                var t = '';
                var reT = /<t[^>]*>([\s\S]*?)<\/t>/g;
                var q;
                while ((q = reT.exec(m[1]))) { t += unxml(q[1]); }
                ss.push(t);
            }
            var sheets = [];
            var reSh = /<sheet\b[^>]*>/g;
            while ((m = reSh.exec(wb))) {
                var nm = /name="([^"]+)"/.exec(m[0]);
                var rid = /r:id="([^"]+)"/.exec(m[0]);
                if (nm && rid && rel[rid[1]]) {
                    var p = rel[rid[1]];
                    sheets.push({ name: unxml(nm[1]), path: p.charAt(0) === '/' ? p.slice(1) : 'xl/' + p });
                }
            }
            return Promise.all(sheets.map(function (sh) {
                return read(sh.path).then(function (xml) {
                    var grid = [];
                    var reC = /<c r="([A-Z]+)(\d+)"([^>]*?)(?:\/>|>([\s\S]*?)<\/c>)/g;
                    var c;
                    while ((c = reC.exec(xml || ''))) {
                        var r = +c[2] - 1;
                        var ci = colIndex(c[1]);
                        var tp = (/t="(\w+)"/.exec(c[3]) || [])[1];
                        var inner = c[4] || '';
                        var v = (/<v>([\s\S]*?)<\/v>/.exec(inner) || [])[1];
                        var val = '';
                        if (tp === 's' && v !== undefined) {
                            val = ss[+v] || '';
                        } else if (tp === 'inlineStr') {
                            var reI = /<t[^>]*>([\s\S]*?)<\/t>/g;
                            var q2;
                            while ((q2 = reI.exec(inner))) { val += unxml(q2[1]); }
                        } else if (v !== undefined) {
                            val = unxml(v);
                        }
                        if (val === '') { continue; }
                        grid[r] = grid[r] || [];
                        grid[r][ci] = val;
                    }
                    for (var i = 0; i < grid.length; i++) {
                        grid[i] = grid[i] || [];
                        for (var j = 0; j < grid[i].length; j++) {
                            if (grid[i][j] === undefined) { grid[i][j] = ''; }
                        }
                    }
                    return { name: sh.name, grid: grid };
                });
            }));
        });
    }

    $('#utFile').on('change', function () {
        var f = this.files && this.files[0];
        this.value = '';
        if (!f) {
            return;
        }
        if (!window.DecompressionStream) {
            Flood.toast('เบราว์เซอร์นี้อ่าน .xlsx ไม่ได้ — ใช้ Chrome/Edge รุ่นใหม่', 'danger');
            return;
        }
        var $r = $('#utResult');
        $r.removeClass('hidden alert-danger alert-success').addClass('alert-info').text('กำลังอ่านไฟล์ ' + f.name + '…');
        f.arrayBuffer().then(readXlsx).then(function (sheets) {
            var out = {};
            sheets.forEach(function (s) { out[s.name] = s.grid; });
            send(out);
        }).catch(function (e) {
            $r.removeClass('hidden alert-info').addClass('alert-danger').text('อ่านไฟล์ไม่ได้: ' + (e && e.message ? e.message : e));
        });
    });

    /* ---------- เปลี่ยนลิงก์ชีต / ชื่อแท็บ ---------- */
    $('#utSheetEdit').on('click', function (e) {
        e.preventDefault();
        Flood.confirm({
            title: 'ลิงก์ Google Sheet งานช่าง',
            html: '<div class="form-group"><label>ลิงก์ชีต</label><input type="url" class="form-control" id="utSheetUrl" value="' + Flood.esc(U.sheet || '') + '"></div>'
                + '<div class="form-group"><label>ชื่อแท็บ (บรรทัดละ 1 แท็บ)</label><textarea class="form-control" id="utSheetTabs" rows="4">'
                + Flood.esc((U.tabs || []).join('\n')) + '</textarea>'
                + (U.tabHint ? '<p class="help-block">' + Flood.esc(U.tabHint) + '</p>' : '') + '</div>',
            okText: 'บันทึก'
        }, function ($m) {
            return Flood.post(U.sheetEndpoint || 'sat/utilitySheet', { url: $m.find('#utSheetUrl').val(), tabs: $m.find('#utSheetTabs').val() }).then(function (o) {
                Flood.toast(o.msg, 'success');
                setTimeout(function () { window.location.reload(); }, 600);
            });
        });
    });

    /* ---------- ให้หน้าอื่นใช้ตัวอ่านชีตชุดนี้ (ห้อง SAT → ปุ่ม "ดึงประมวล") ---------- */
    /**
     * FloodUtilSheet.pull(ลิงก์ชีต, [ชื่อแท็บ], progress(แถวที่อ่านแล้ว, แถวทั้งหมด)) → promise({ชื่อแท็บ: grid})
     * ส่งต่อให้ sat/utilityImport หรือ sat/referImport เป็น sheets = JSON.stringify(ผลลัพธ์) — แบบเดียวกับปุ่ม "ดึงจาก Google Sheet"
     */
    window.FloodUtilSheet = {
        pull: function (url, tabs, progress) {
            var id = sheetId(url);
            if (!id || !tabs || !tabs.length) {
                return $.Deferred().reject('ยังไม่ได้ตั้งลิงก์ชีต/ชื่อแท็บ').promise();
            }
            var total = 0;
            var doneRows = 0;
            var prog = function () {
                doneRows++;
                if (progress) {
                    progress(doneRows, total);
                }
            };
            return $.when.apply($, tabs.map(function (tab) {
                return gviz(id, tab, '').then(function (t) { total += t.rows ? t.rows.length : 0; });
            })).then(function () {
                var out = {};
                var chain = $.Deferred().resolve().promise();
                tabs.forEach(function (tab) {
                    chain = chain.then(function () {
                        return pullTab(id, tab, prog).then(function (r) { out[r.tab] = r.grid; });
                    });
                });
                return chain.then(function () { return out; });
            });
        }
    };
});
