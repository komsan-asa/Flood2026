/*
 * SitRep → จัดหน้าเป็นตาราง (หน้าต่าง "SitRep ฉบับที่ …" ในห้อง SAT)
 * อ่านจากข้อความ SitRep ที่ออกแล้วโดยตรง — ไม่ต้องเก็บข้อมูลเพิ่ม · ข้อความที่แก้เองก่อนบันทึกก็แสดงได้
 * ปุ่มคัดลอก / สร้างเป็นภาพ ยังใช้ข้อความเดิม · มีลิงก์ "ดูแบบข้อความ" สลับกลับได้
 *
 * โหลดหลัง sat.js: จะจัดหน้าให้เองเมื่อเปิด #satViewModal ที่มีหัวเรื่อง "SitRep ฉบับที่" และเนื้อหาเป็น <pre class="sat-pre">
 * หรือเรียกตรง: SatSitrepView.html(body)
 * ปุ่ม .js-sat-rep-hist (กล่องสถานะโรงพยาบาล) → ตารางประวัติ SitRep จาก <template id="satRepHistTpl"> · กด "ดู" เปิดฉบับนั้น (+ ปุ่มกลับไปประวัติ)
 */
(function (w, $) {
    'use strict';

    var DOT_RE = /(🔴|🟠|🟡|🟢|⚪|🟣)️?/g;
    var DOTS = { '🔴': 'red', '🟠': 'orange', '🟡': 'yellow', '🟢': 'green', '⚪': 'none', '🟣': 'none' };
    var WORDS = { RED: 'red', ORANGE: 'orange', YELLOW: 'yellow', GREEN: 'green' };
    var LABEL = { red: 'RED', orange: 'ORANGE', yellow: 'YELLOW', green: 'GREEN', none: 'ยังไม่ประเมิน' };
    var ORDER = { none: 0, green: 1, yellow: 2, orange: 3, red: 4 };
    // หัวข้อที่เป็นข้อเสนอ/การดำเนินการ (ไม่ใช่ตัวชี้วัด)
    var ASK_RE = /^(การดำเนินการ|ผลกระทบ|ทางเลือก|ต้องการสนับสนุน|ข้อสั่งการ)/;
    // "อีโมจิ หัวข้อ: ค่า" — หัวข้อไม่เกิน 40 ตัว ไม่มี :
    var KV_RE = /^([^\s฀-๿a-zA-Z0-9(]+)\s*([^:\n]{1,40}?)\s*:\s*(.*)$/;
    // บรรทัดย่อยที่มีหัวข้อ "ชื่อ: ค่า" (ไม่นับเวลา 14:00)
    var SUB_KV_RE = /^([^:\n]{1,30}?[^\d\s:]):\s+(.*)$/;
    var WHEN_RE = /\s*\(((?:อัปเดต|ยังไม่อัปเดต)[^()]*)\)\s*$/;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function colorOf(emoji) {
        return DOTS[String(emoji || '').replace(/️/g, '')] || '';
    }
    function worst(a, b) {
        return (ORDER[b] || 0) > (ORDER[a] || 0) ? b : a;
    }
    function dot(c) {
        return '<i class="srv-dot srv-c-' + (c || 'none') + '"></i>';
    }
    /** ข้อความ → HTML: อีโมจิสีเป็นจุดสี */
    function inline(s) {
        return esc(s).replace(DOT_RE, function (m) { return dot(colorOf(m)); });
    }
    function split(s) {
        return String(s || '').split(/\s+·\s+/).map(function (x) { return $.trim(x); }).filter(function (x) { return x !== ''; });
    }
    /** แยกเป็นช่วง ๆ ตาม " · " ให้ตัดบรรทัดตรงรอยต่อ */
    function segs(s) {
        var p = split(s);
        if (p.length < 2) {
            return inline(s);
        }
        return p.map(function (x) { return '<span class="srv-seg">' + inline(x) + '</span>'; }).join('<span class="srv-sep">·</span>');
    }
    /** ทุกช่วงขึ้นต้นด้วยจุดสี → รายการทีละบรรทัด */
    function isDotList(s) {
        var p = split(s);
        return p.length >= 2 && p.every(function (x) { return /^(🔴|🟠|🟡|🟢|⚪|🟣)/.test(x); });
    }
    /** หลายช่วงแบบ "ชื่อ 🟢 (หมายเหตุ)" เช่น ระบบสำคัญ */
    function isChipSet(s) {
        var p = split(s);
        if (p.length < 2) {
            return false;
        }
        var n = p.filter(function (x) { return /^[^()]{1,30}?\s(🔴|🟠|🟡|🟢|⚪|🟣)/.test(x); }).length;
        return n >= Math.ceil(p.length * 0.6);
    }

    function parse(body) {
        var o = { title: '', meta: [], status: '', statusText: '', reason: '', rows: [], asks: [], rec: '', next: '', extra: [] };
        var last = null;
        String(body || '').replace(/\r/g, '').split('\n').forEach(function (raw) {
            var line = $.trim(raw);
            var m;
            if (line === '') {
                last = null;
                return;
            }
            if (!o.title && /SitRep/i.test(line) && /^📋|^SitRep/i.test(line)) {
                o.title = line.replace(/^📋\s*/, '');
                return;
            }
            if (/^ข้อมูล ณ /.test(line)) {
                o.meta.push(line);
                return;
            }
            if ((m = line.match(/^สถานะโรงพยาบาล\s*:\s*(.*)$/))) {
                var v = m[1];
                var e = v.match(DOT_RE);
                var wd = v.match(/\b(RED|ORANGE|YELLOW|GREEN)\b/);
                o.status = (e && colorOf(e[0])) || (wd && WORDS[wd[1]]) || 'none';
                o.statusText = $.trim(v.replace(DOT_RE, '').replace(/\b(RED|ORANGE|YELLOW|GREEN)\b\s*—?\s*/, ''));
                return;
            }
            if ((m = line.match(/^เหตุผล\s*:\s*(.*)$/))) {
                o.reason = m[1];
                return;
            }
            if ((m = line.match(/^SAT แนะนำ\s*:\s*(.*)$/))) {
                o.rec = m[1];
                return;
            }
            if ((m = line.match(/^รายงานครั้งถัดไป\s*:\s*(.*)$/))) {
                o.next = m[1];
                return;
            }
            if (last && /^(\s{2,}|\t)/.test(raw)) {
                m = line.match(SUB_KV_RE);
                last.subs.push(m ? { label: m[1], text: m[2] } : { label: '', text: line });
                return;
            }
            if ((m = line.match(KV_RE)) && !/^\d/.test(m[1])) {
                if (ASK_RE.test(m[2])) {
                    o.asks.push({ icon: m[1], label: m[2], text: m[3] });
                    last = null;
                    return;
                }
                var row = { icon: m[1], label: m[2], status: '', text: m[3], when: '', subs: [], chips: false };
                var wm = row.text.match(WHEN_RE);
                if (wm) {
                    row.when = wm[1];
                    row.text = row.text.replace(WHEN_RE, '');
                }
                var lead = row.text.match(/^(🔴|🟠|🟡|🟢|⚪|🟣)️?\s*/);
                if (lead) {
                    row.status = colorOf(lead[1]);
                    row.text = row.text.slice(lead[0].length);
                } else if (isChipSet(row.text)) {
                    row.chips = true;
                    (row.text.match(DOT_RE) || []).forEach(function (d) { row.status = worst(row.status, colorOf(d)); });
                }
                row.text = $.trim(row.text);
                if (row.text === '-') {
                    row.text = '';
                }
                o.rows.push(row);
                last = row;
                return;
            }
            o.extra.push(line);
            last = null;
        });
        return o;
    }

    function whenHtml(when, status) {
        if (!when) {
            return '<span class="srv-muted">–</span>';
        }
        if (/^ยังไม่อัปเดต/.test(when)) {
            return '<span class="srv-muted">ยังไม่อัปเดต</span>';
        }
        var t = when.replace(/^อัปเดต\s*/, '');
        var stale = /เกินรอบ/.test(t);
        t = $.trim(t.replace(/เกินรอบ/, ''));
        return '<span class="srv-time">' + esc(t) + '</span>' + (stale ? '<span class="srv-stale">เกินรอบ</span>' : '');
    }

    function detailHtml(r) {
        var h = '';
        if (r.chips) {
            h += '<div class="srv-chips">' + split(r.text).map(function (x) {
                var m = x.match(/^(.*?)\s*(🔴|🟠|🟡|🟢|⚪|🟣)️?\s*(?:\((.*)\))?\s*$/);
                if (!m) {
                    return '<span class="srv-chip">' + inline(x) + '</span>';
                }
                var c = colorOf(m[2]);
                return '<span class="srv-chip srv-c-' + c + '"' + (m[3] ? ' title="' + esc(m[3]) + '"' : '') + '>' + dot(c) + esc(m[1])
                    + (m[3] ? '<small>' + esc(m[3]) + '</small>' : '') + '</span>';
            }).join('') + '</div>';
        } else if (r.text !== '') {
            h += '<div class="srv-main">' + segs(r.text) + '</div>';
        } else {
            h += '<div class="srv-muted">ยังไม่มีข้อมูล</div>';
        }
        r.subs.forEach(function (s) {
            h += '<div class="srv-sub">' + (s.label ? '<b>' + esc(s.label) + '</b>' : '');
            if (isDotList(s.text)) {
                h += '<ul class="srv-list">' + split(s.text).map(function (x) { return '<li>' + inline(x) + '</li>'; }).join('') + '</ul>';
            } else {
                h += '<span>' + segs(s.text) + '</span>';
            }
            h += '</div>';
        });
        return h;
    }

    function html(body, back) {
        var o = parse(body);
        if (!o.rows.length && !o.status) {
            // ไม่ใช่รูปแบบ SitRep — แสดงข้อความเดิม
            return '<pre class="sat-pre">' + esc(body) + '</pre>';
        }
        var h = '<div class="srv">';
        h += '<div class="srv-tools"><span class="srv-meta">'
            + (back ? '<button type="button" class="btn btn-link btn-xs js-sat-rep-hist"><i class="fa fa-arrow-left"></i> ประวัติ SitRep</button> ' : '')
            + o.meta.map(esc).join(' · ') + '</span>'
            + '<button type="button" class="btn btn-link btn-xs srv-raw-toggle"><i class="fa fa-align-left"></i> ดูแบบข้อความ</button></div>';

        if (o.status) {
            h += '<div class="srv-status srv-b-' + o.status + '"><div class="srv-status-main">'
                + '<div class="srv-status-l">สถานะโรงพยาบาล</div>'
                + '<div class="srv-status-v">' + dot(o.status) + esc(LABEL[o.status]) + '</div>'
                + (o.statusText ? '<div class="srv-status-t">' + esc(o.statusText.replace(/^—\s*/, '')) + '</div>' : '');
            // นับตัวชี้วัดตามสี
            var cnt = { red: 0, orange: 0, yellow: 0, green: 0, none: 0 };
            o.rows.forEach(function (r) { cnt[r.status || 'none']++; });
            h += '<div class="srv-count"><span class="srv-count-l">ตัวชี้วัด ' + o.rows.length + ' เรื่อง</span>' + ['red', 'orange', 'yellow', 'green', 'none'].filter(function (k) { return cnt[k] > 0; }).map(function (k) {
                return '<span class="srv-count-i srv-c-' + k + '">' + dot(k) + cnt[k] + (k === 'none' ? ' ' + LABEL.none : '') + '</span>';
            }).join('') + '</div></div>';
            if (o.reason) {
                h += '<div class="srv-reason"><div class="srv-status-l">เหตุผล</div><ul>'
                    + reasonList(o.reason).map(function (x) { return '<li>' + inline(x) + '</li>'; }).join('') + '</ul></div>';
            }
            h += '</div>';
        }

        if (o.rows.length) {
            h += '<table class="srv-table"><colgroup><col class="srv-w-topic"><col class="srv-w-st"><col><col class="srv-w-time"></colgroup>'
                + '<thead><tr><th>ตัวชี้วัด</th><th>สถานะ</th><th>รายละเอียด</th><th>อัปเดต</th></tr></thead><tbody>';
            o.rows.forEach(function (r) {
                var st = r.status || 'none';
                h += '<tr class="srv-r-' + st + '">'
                    + '<td class="srv-topic" data-th="ตัวชี้วัด"><span class="srv-ico">' + esc(r.icon) + '</span>' + esc(r.label) + '</td>'
                    + '<td data-th="สถานะ">' + (r.status && r.status !== 'none'
                        ? '<span class="srv-chip srv-c-' + st + '">' + dot(st) + LABEL[st] + '</span>'
                        : '<span class="srv-chip srv-c-none">' + dot('none') + LABEL.none + '</span>') + '</td>'
                    + '<td class="srv-detail" data-th="รายละเอียด">' + detailHtml(r) + '</td>'
                    + '<td class="srv-when' + (/^อัปเดต/.test(r.when) ? '' : ' is-none') + '" data-th="อัปเดต">' + whenHtml(r.when, st) + '</td></tr>';
            });
            h += '</tbody></table>';
        }

        if (o.asks.length) {
            h += '<div class="srv-asks"><div class="srv-h">ข้อเสนอ / การดำเนินการ</div><table class="srv-kv">';
            o.asks.forEach(function (a) {
                h += '<tr><th><span class="srv-ico">' + esc(a.icon) + '</span>' + esc(a.label) + '</th><td>' + segs(a.text) + '</td></tr>';
            });
            h += '</table></div>';
        }
        if (o.extra.length) {
            h += '<div class="srv-extra">' + o.extra.map(function (x) { return '<p>' + inline(x) + '</p>'; }).join('') + '</div>';
        }
        if (o.rec || o.next) {
            h += '<div class="srv-foot">';
            if (o.rec) {
                h += '<div class="srv-rec"><i class="fa fa-hand-o-right"></i> <b>SAT แนะนำ</b> ' + inline(o.rec) + '</div>';
            }
            if (o.next) {
                h += '<div class="srv-next"><i class="fa fa-clock-o"></i> รายงานครั้งถัดไป <b>' + esc(o.next) + '</b></div>';
            }
            h += '</div>';
        }
        h += '<pre class="sat-pre srv-raw" hidden>' + esc(body) + '</pre>';
        return h + '</div>';
    }

    /* ==================== ภาพ SitRep แบบตาราง (canvas — ใช้กับปุ่ม "สร้างเป็นภาพ") ==================== */
    var CV_FONT = '"Anuphan","Sarabun","Noto Sans Thai","Leelawadee UI","Tahoma","Segoe UI Emoji","Apple Color Emoji","Noto Color Emoji",sans-serif';
    var HEX = { red: '#dc2626', orange: '#ea580c', yellow: '#eab308', green: '#16a34a', none: '#cbd5e1' };
    var TINT = { red: '#fef2f2', orange: '#fff7ed', yellow: '#fefce8', green: '#f0fdf4', none: '#f8fafc' };
    var CHIP = { red: ['#fee2e2', '#991b1b', '#f87171'], orange: ['#ffedd5', '#9a3412', '#fb923c'], yellow: ['#fef9c3', '#854d0e', '#facc15'],
        green: ['#dcfce7', '#166534', '#86efac'], none: ['#f1f5f9', '#475569', '#cbd5e1'] };
    var BADGE = { red: ['#c62828', '#ffffff'], orange: ['#ef6c00', '#ffffff'], yellow: ['#f9c80e', '#3a2e00'], green: ['#2e7d32', '#ffffff'], none: ['#90a4ae', '#ffffff'] };

    function cvFont(ctx, size, weight) {
        ctx.font = (weight || 400) + ' ' + size + 'px ' + CV_FONT;
    }
    function cvWords(text) {
        if (w.Intl && Intl.Segmenter) {
            try {
                var sg = new Intl.Segmenter('th', { granularity: 'word' });
                return cvGlue(Array.from(sg.segment(text), function (x) { return x.segment; }));
            } catch (e) { /* ตัดตามช่องว่าง */ }
        }
        return String(text).split(/(\s+)/);
    }
    /** ข้อความ → คำ/จุดสี แล้วตัดบรรทัดตามความกว้าง (ctx.font ต้องตั้งไว้แล้ว) */
    function cvWrap(ctx, text, maxW, dotW) {
        var toks = [];
        String(text || '').split(/(🔴|🟠|🟡|🟢|⚪|🟣)️?/).forEach(function (part, i) {
            if (i % 2 === 1) {
                toks.push({ c: colorOf(part) || 'none', w: dotW });
            } else if (part) {
                cvWords(part).forEach(function (s) {
                    if (s !== '') {
                        toks.push({ s: s, w: ctx.measureText(s).width });
                    }
                });
            }
        });
        var lines = [], cur = [], cw = 0;
        var push = function () { lines.push(cur); cur = []; cw = 0; };
        toks.forEach(function (t) {
            var sp = t.s !== undefined && /^\s+$/.test(t.s);
            if (sp && !cur.length) {
                return;
            }
            if (cw + t.w > maxW && cur.length) {
                push();
                if (sp) {
                    return;
                }
            }
            if (t.s !== undefined && t.w > maxW) {   // คำเดียวยาวเกินบรรทัด — ตัดรายตัวอักษร
                var s = t.s;
                while (s) {
                    var i = s.length;
                    while (i > 1 && ctx.measureText(s.slice(0, i)).width > maxW - cw) {
                        i--;
                    }
                    var part = s.slice(0, i);
                    cur.push({ s: part, w: ctx.measureText(part).width });
                    cw += cur[cur.length - 1].w;
                    s = s.slice(i);
                    if (s) {
                        push();
                    }
                }
                return;
            }
            cur.push(t);
            cw += t.w;
        });
        if (cur.length || !lines.length) {
            push();
        }
        return lines;
    }
    function cvRound(ctx, x, y, wd, ht, r) {
        ctx.beginPath();
        if (ctx.roundRect) {
            ctx.roundRect(x, y, wd, ht, r);
        } else {
            ctx.rect(x, y, wd, ht);
        }
    }

    /** SitRep → canvas แบบตาราง · แยกเป็นตารางไม่ได้ (ไม่ถึง 3 ตัวชี้วัด) → null ให้ใช้ตัววาดเดิม */
    function canvas(body) {
        var o = parse(body);
        if (o.rows.length < 3) {
            return null;
        }
        var W = 1080, P = 40, IW = W - P * 2;
        var ctx = document.createElement('canvas').getContext('2d');
        var ops = [];
        var st = o.status || 'none';
        function rich(txt, x, y, maxW, size, weight, color) {
            cvFont(ctx, size, weight);
            var lines = cvWrap(ctx, txt, maxW, Math.round(size * 0.85));
            var lh = Math.round(size * 1.5);
            ops.push({ t: 'rich', lines: lines, x: x, y: y, lh: lh, size: size, weight: weight, color: color });
            return lines.length * lh;
        }
        function text(s, x, y, size, weight, color) {
            ops.push({ t: 'text', s: s, x: x, y: y, size: size, weight: weight, color: color });
        }

        // ---- หัว ----
        var head = { t: 'rect', x: 0, y: 0, w: W, h: 0, fill: '#1f3a5f' };
        ops.push(head);
        cvFont(ctx, 30, 800);
        var badge = LABEL[st], bw = ctx.measureText(badge).width + 56;
        ops.push({ t: 'pill', x: W - P - bw, y: 34, w: bw, h: 56, fill: BADGE[st][0] });
        text(badge, W - P - bw + 28, 62, 30, 800, BADGE[st][1]);
        var y = 26;
        y += rich(o.title || 'SitRep', P, y, IW - bw - 24, 36, 700, '#ffffff');
        if (o.meta.length) {
            y += rich(o.meta.join(' · '), P, y, IW - bw - 24, 22, 400, '#cfdcec');
        }
        y = Math.max(y, 100) + 22;
        head.h = y;
        ops.push({ t: 'rect', x: 0, y: y - 10, w: W, h: 10, fill: HEX[st] });

        // ---- กล่องสถานะ + เหตุผล ----
        y += 24;
        var box = { t: 'box', x: P, y: y, w: IW, h: 0, fill: TINT[st], bar: HEX[st] };
        ops.push(box);
        var lx = P + 28, leftW = 300, rx = lx + leftW + 28, rW = P + IW - 24 - rx;
        var ly = y + 18;
        text('สถานะโรงพยาบาล', lx, ly + 12, 19, 600, '#5b6573');
        ly += 32;
        ops.push({ t: 'dot', x: lx + 14, y: ly + 26, r: 14, fill: HEX[st] });
        text(LABEL[st], lx + 40, ly + 26, 44, 800, '#0f172a');
        ly += 58;
        if (o.statusText) {
            ly += rich(o.statusText.replace(/^—\s*/, ''), lx + 40, ly, leftW - 40, 24, 600, '#334155');
        }
        ly += 10;
        ops.push({ t: 'dash', x: lx, y: ly, w: leftW });
        ly += 12;
        var cnt = { red: 0, orange: 0, yellow: 0, green: 0, none: 0 };
        o.rows.forEach(function (r) { cnt[r.status || 'none']++; });
        text('ตัวชี้วัด ' + o.rows.length + ' เรื่อง', lx, ly + 12, 18, 600, '#5b6573');
        ly += 30;
        var dots = { red: '🔴', orange: '🟠', yellow: '🟡', green: '🟢', none: '⚪' };
        ly += rich(['red', 'orange', 'yellow', 'green', 'none'].filter(function (k) { return cnt[k] > 0; }).map(function (k) {
            return dots[k] + ' ' + cnt[k] + (k === 'none' ? ' ' + LABEL.none : '');
        }).join('  '), lx, ly, leftW, 20, 700, '#334155');
        var ry = y + 18;
        if (o.reason) {
            text('เหตุผล', rx, ry + 12, 19, 600, '#5b6573');
            ry += 34;
            reasonList(o.reason).forEach(function (x) {
                ops.push({ t: 'dot', x: rx + 5, y: ry + 17, r: 3.5, fill: '#64748b' });
                ry += rich(x, rx + 18, ry, rW - 18, 22, 400, '#1f2937') + 4;
            });
        }
        box.h = Math.max(ly, ry) - y + 16;
        y += box.h + 24;

        // ---- ตารางตัวชี้วัด ----
        var cT = 210, cS = 170, cD = IW - cT - cS, tableTop = y;
        ops.push({ t: 'rect', x: P, y: y, w: IW, h: 46, fill: '#eef2f7' });
        text('ตัวชี้วัด', P + 18, y + 23, 19, 700, '#334155');
        text('สถานะ', P + cT + 12, y + 23, 19, 700, '#334155');
        text('รายละเอียด', P + cT + cS, y + 23, 19, 700, '#334155');
        y += 46;
        o.rows.forEach(function (r, i) {
            var rs = r.status || 'none', pad = 14, top = y;
            var bg = { t: 'rect', x: P, y: top, w: IW, h: 0, fill: i % 2 ? '#fbfcfe' : '#ffffff' };
            var bar = { t: 'rect', x: P, y: top, w: 7, h: 0, fill: HEX[rs] };
            ops.push(bg, bar);
            var th = rich($.trim(r.icon + ' ' + r.label), P + 18, top + pad, cT - 28, 23, 700, '#0f172a');
            // สถานะ + เวลาอัปเดต
            cvFont(ctx, 18, 700);
            var cl = LABEL[rs], cwid = ctx.measureText(cl).width + 46;
            ops.push({ t: 'pill', x: P + cT + 8, y: top + pad + 1, w: Math.min(cwid, cS - 14), h: 38, fill: CHIP[rs][0], stroke: CHIP[rs][2] });
            ops.push({ t: 'dot', x: P + cT + 26, y: top + pad + 20, r: 6.5, fill: HEX[rs] });
            text(cl, P + cT + 39, top + pad + 20, 18, 700, CHIP[rs][1]);
            var sh = 46;
            if (r.when) {
                var nu = /^ยังไม่อัปเดต/.test(r.when), tm = r.when.replace(/^อัปเดต\s*/, ''), stale = /เกินรอบ/.test(tm);
                sh += rich(nu ? 'ยังไม่อัปเดต' : 'อัปเดต ' + $.trim(tm.replace(/เกินรอบ/, '')), P + cT + 10, top + pad + sh, cS - 16, 18, nu ? 400 : 700,
                    nu ? '#94a3b8' : '#0f172a');
                if (stale) {
                    text('เกินรอบ', P + cT + 10, top + pad + sh + 12, 17, 700, '#b91c1c');
                    sh += 26;
                }
            }
            // รายละเอียด
            var dx = P + cT + cS, dw = cD - 18, dy = top + pad;
            if (r.chips) {
                var cx0 = dx, cy0 = dy, ch = 38, notes = [];
                split(r.text).forEach(function (x) {
                    var m = x.match(/^(.*?)\s*(🔴|🟠|🟡|🟢|⚪|🟣)️?\s*(?:\((.*)\))?\s*$/);
                    var name = m ? m[1] : x, c = m ? (colorOf(m[2]) || 'none') : 'none';
                    if (m && m[3]) {
                        notes.push(name + ': ' + m[3]);
                    }
                    cvFont(ctx, 19, 700);
                    var pw = ctx.measureText(name).width + 46;
                    if (cx0 + pw > dx + dw && cx0 > dx) {
                        cx0 = dx;
                        cy0 += ch + 8;
                    }
                    ops.push({ t: 'pill', x: cx0, y: cy0, w: pw, h: ch, fill: CHIP[c][0], stroke: CHIP[c][2] });
                    ops.push({ t: 'dot', x: cx0 + 18, y: cy0 + ch / 2, r: 6.5, fill: HEX[c] });
                    text(name, cx0 + 31, cy0 + ch / 2, 19, 700, CHIP[c][1]);
                    cx0 += pw + 8;
                });
                dy = cy0 + ch + 6;
                notes.forEach(function (n) { dy += rich(n, dx, dy, dw, 18, 400, '#5b6573'); });
            } else {
                dy += rich(r.text !== '' ? r.text : 'ยังไม่มีข้อมูล', dx, dy, dw, 22, 400, r.text !== '' ? '#1f2937' : '#94a3b8');
            }
            r.subs.forEach(function (s) {
                dy += 6;
                var sb = { t: 'sub', x: dx - 8, y: dy, w: dw + 8, h: 0 };
                ops.push(sb);
                var sy = dy + 8;
                if (s.label) {
                    sy += rich(s.label, dx + 4, sy, dw - 16, 18, 700, '#5b6573');
                }
                (isDotList(s.text) ? split(s.text) : [s.text]).forEach(function (x) {
                    sy += rich(x, dx + 4, sy, dw - 16, 20, 400, '#334155');
                });
                sb.h = sy - dy + 8;
                dy = sy + 8;
            });
            var rh = Math.max(th + pad * 2, sh + pad * 2, dy - top + pad);
            bg.h = rh;
            bar.h = rh;
            ops.push({ t: 'rect', x: P, y: top + rh - 1, w: IW, h: 1, fill: '#e5e7eb' });
            y = top + rh;
        });
        ops.push({ t: 'frame', x: P, y: tableTop, w: IW, h: y - tableTop });

        // ---- ข้อเสนอ / การดำเนินการ ----
        if (o.asks.length) {
            y += 26;
            text('ข้อเสนอ / การดำเนินการ', P, y + 14, 22, 700, '#0f172a');
            y += 38;
            var askTop = y, lw = 330;
            o.asks.forEach(function (a) {
                var top = y;
                var lb = { t: 'rect', x: P, y: top, w: lw, h: 0, fill: '#f8fafc' };
                ops.push(lb);
                var h1 = rich($.trim(a.icon + ' ' + a.label), P + 14, top + 10, lw - 24, 20, 700, '#1f3a5f');
                var h2 = rich(a.text, P + lw + 14, top + 10, IW - lw - 28, 22, 400, '#1f2937');
                var rh = Math.max(h1, h2) + 20;
                lb.h = rh;
                ops.push({ t: 'rect', x: P, y: top + rh - 1, w: IW, h: 1, fill: '#e5e7eb' });
                y = top + rh;
            });
            ops.push({ t: 'frame', x: P, y: askTop, w: IW, h: y - askTop });
        }
        if (o.extra.length) {
            y += 14;
            o.extra.forEach(function (x) { y += rich(x, P, y, IW, 22, 400, '#1f2937') + 6; });
        }
        // ---- SAT แนะนำ / รายงานครั้งถัดไป ----
        if (o.rec) {
            y += 22;
            var rb = { t: 'box', x: P, y: y, w: IW, h: 0, fill: '#fff7ed', bar: '#ef6c00', stroke: '#fed7aa' };
            ops.push(rb);
            var ty = y + 14;
            ty += rich('SAT แนะนำ', P + 26, ty, IW - 50, 19, 700, '#9a3412');
            ty += rich(o.rec, P + 26, ty, IW - 50, 23, 600, '#7c2d12');
            rb.h = ty - y + 14;
            y = ty + 14;
        }
        if (o.next) {
            y += 14;
            var nb = { t: 'box', x: P, y: y, w: IW, h: 0, fill: '#eff6ff', bar: '#2563eb', stroke: '#bfdbfe' };
            ops.push(nb);
            var nh = rich('รายงานครั้งถัดไป: ' + o.next, P + 26, y + 12, IW - 50, 22, 700, '#1e3a8a');
            nb.h = nh + 24;
            y += nb.h;
        }
        // ---- ท้ายภาพ ----
        y += 26;
        ops.push({ t: 'rect', x: P, y: y, w: IW, h: 2, fill: '#e3e8ee' });
        var d = new Date(), pad2 = function (n) { return ('0' + n).slice(-2); };
        text('สร้างภาพจากระบบ ' + ((document.title || '').split('·').pop() || '').trim() + ' · ' + pad2(d.getDate()) + '/' + pad2(d.getMonth() + 1) + '/'
            + (d.getFullYear() + 543) + ' ' + pad2(d.getHours()) + ':' + pad2(d.getMinutes()) + ' น.', P, y + 30, 19, 400, '#78909c');
        y += 60;

        // ---- วาดจริง ----
        var cv = document.createElement('canvas');
        cv.width = W;
        cv.height = Math.ceil(y);
        ctx = cv.getContext('2d');
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, W, cv.height);
        ctx.textBaseline = 'middle';
        ops.forEach(function (op) {
            if (op.t === 'rect') {
                ctx.fillStyle = op.fill;
                ctx.fillRect(op.x, op.y, op.w, op.h);
            } else if (op.t === 'pill') {
                cvRound(ctx, op.x, op.y, op.w, op.h, op.h / 2);
                ctx.fillStyle = op.fill;
                ctx.fill();
                if (op.stroke) {
                    ctx.strokeStyle = op.stroke;
                    ctx.lineWidth = 1.5;
                    ctx.stroke();
                }
            } else if (op.t === 'dot') {
                ctx.beginPath();
                ctx.arc(op.x, op.y, op.r, 0, Math.PI * 2);
                ctx.fillStyle = op.fill;
                ctx.fill();
            } else if (op.t === 'box') {
                cvRound(ctx, op.x, op.y, op.w, op.h, 14);
                ctx.fillStyle = op.fill;
                ctx.fill();
                ctx.strokeStyle = op.stroke || '#e5e7eb';
                ctx.lineWidth = 1.5;
                ctx.stroke();
                ctx.save();
                cvRound(ctx, op.x, op.y, op.w, op.h, 14);
                ctx.clip();
                ctx.fillStyle = op.bar;
                ctx.fillRect(op.x, op.y, 8, op.h);
                ctx.restore();
            } else if (op.t === 'sub') {
                cvRound(ctx, op.x, op.y, op.w, op.h, 10);
                ctx.fillStyle = '#f8fafc';
                ctx.fill();
                ctx.setLineDash([5, 4]);
                ctx.strokeStyle = '#cbd5e1';
                ctx.lineWidth = 1;
                ctx.stroke();
                ctx.setLineDash([]);
            } else if (op.t === 'frame') {
                ctx.strokeStyle = '#d6dbe3';
                ctx.lineWidth = 1.5;
                ctx.strokeRect(op.x, op.y, op.w, op.h);
            } else if (op.t === 'dash') {
                ctx.setLineDash([6, 5]);
                ctx.strokeStyle = 'rgba(15, 23, 42, .2)';
                ctx.lineWidth = 1;
                ctx.beginPath();
                ctx.moveTo(op.x, op.y);
                ctx.lineTo(op.x + op.w, op.y);
                ctx.stroke();
                ctx.setLineDash([]);
            } else if (op.t === 'text') {
                cvFont(ctx, op.size, op.weight);
                ctx.fillStyle = op.color;
                ctx.fillText(op.s, op.x, op.y);
            } else if (op.t === 'rich') {
                cvFont(ctx, op.size, op.weight);
                op.lines.forEach(function (ln, k) {
                    var cx = op.x, cy = op.y + k * op.lh + op.lh / 2;
                    ln.forEach(function (tk) {
                        if (tk.c) {
                            ctx.beginPath();
                            ctx.arc(cx + tk.w / 2, cy, tk.w * 0.34, 0, Math.PI * 2);
                            ctx.fillStyle = HEX[tk.c] || HEX.none;
                            ctx.fill();
                        } else {
                            ctx.fillStyle = op.color;
                            ctx.fillText(tk.s, cx, cy);
                        }
                        cx += tk.w;
                    });
                });
            }
        });
        return cv;
    }

    /* ==================== สรุป 1 หน้า A4 (ภาพ/PDF ส่งผู้บริหาร) ==================== */
    // ขนาดพื้นที่เนื้อหาของหน้า A4 ใน sat.js (a4Pages: 1240×1754 · ขอบข้าง 40 บน 40 ล่าง 64) → วาดขนาดนี้ได้ 1 หน้าพอดีไม่ต้องย่อ
    var A4W = 1160, A4H = 1650;

    /**
     * เหตุผลของสถานะ → รายการ (บรรทัด "เหตุผล:" ใน SitRep ต่อกันด้วย " · " และสรุปของตัวชี้วัดข้างในก็มี " · " ด้วย)
     * ข้อใหม่เริ่มที่ "ชื่อตัวชี้วัด: <ชื่อสี> —" หรือเหตุผลของระบบ (Sat::suggest) · ส่วนอื่นต่อท้ายข้อก่อนหน้า
     */
    var REASON_IND_RE = /^[^:]{1,40}:\s*(ปกติ|เริ่มมีผลกระทบ|กระทบระบบบริการ|วิกฤต)(\s*—|$)/;
    var REASON_SYS_RE = /^(ถนนเข้า–ออกโรงพยาบาล|Refer ออกจากโรงพยาบาล|รถ Refer|หน่วยบริการในเครือข่าย|หน่วยบริการเริ่ม|บุคลากรหน่วยสำคัญ|พยาบาลเวรนี้|ผู้ป่วยเสี่ยงอยู่ใน|กลุ่มเปราะบางในศูนย์|จุดที่ตั้งโรงพยาบาล)|\(ข้อมูลสาธารณูปโภค\):/;
    function reasonList(s) {
        var out = [];
        split(s).forEach(function (x) {
            if (!out.length || REASON_IND_RE.test(x) || REASON_SYS_RE.test(x)) {
                out.push(x);
            } else {
                out[out.length - 1] += ' · ' + x;
            }
        });
        return out;
    }

    /** ไม่ตัดบรรทัดกลางเวลา/ตัวเลข ("15:38") และวงเล็บ/เครื่องหมายวรรคตอนไม่อยู่ต้นหรือท้ายบรรทัดเดี่ยว ๆ */
    function cvGlue(arr) {
        var out = [];
        arr.forEach(function (s) {
            var n = out.length, p = n ? out[n - 1] : '';
            if (n && s && !/\s$/.test(p) && !/^\s/.test(s)
                && (/^[)\].,:;…%]/.test(s) || /[(\[]$/.test(p) || (/\d[:.]?$/.test(p) && /^\d/.test(s)))) {
                out[n - 1] = p + s;
            } else {
                out.push(s);
            }
        });
        return out;
    }
    var ROUTE_ROW_RE = /ถนน|EMS|Refer|เส้นทาง/i;
    var LEAD_DOT_RE = /^(🔴|🟠|🟡|🟢|⚪|🟣)/;

    /** ตัดให้เหลือไม่เกิน max บรรทัด — บรรทัดสุดท้ายต่อ … (ctx.font ต้องตั้งไว้แล้ว) */
    function cvClamp(ctx, lines, max, maxW) {
        if (!max || lines.length <= max) {
            return lines;
        }
        var out = lines.slice(0, max);
        var last = out[max - 1].slice();
        var ell = ctx.measureText('…').width;
        var sum = function () { return last.reduce(function (a, t) { return a + t.w; }, 0); };
        while (last.length && (sum() + ell > maxW || (last[last.length - 1].s !== undefined && /^\s+$/.test(last[last.length - 1].s)))) {
            last.pop();
        }
        last.push({ s: '…', w: ell });
        out[max - 1] = last;
        return out;
    }

    /** แยกเส้นทาง (ขึ้นต้นด้วยจุดสี) ออกจากแถวถนน / EMS-Refer — ที่เหลือเป็นสรุปของการ์ด */
    function a4Split(o) {
        var routes = [], seen = {};
        var tiles = o.rows.map(function (r) {
            var rest = [];
            var routeRow = ROUTE_ROW_RE.test(r.label);
            var take = function (seg) {
                var x = seg.replace(/^[^:]{1,40}:\s*(?=🔴|🟠|🟡|🟢|⚪|🟣)/, '').replace(/\(ยืนยันหน้างาน\s*/g, '(หน้างาน ');
                if (routeRow && LEAD_DOT_RE.test(x)) {
                    var key = $.trim(x.replace(/\s*\([^()]*\)\s*$/, '').replace(DOT_RE, ''));
                    if (!seen[key]) {
                        seen[key] = 1;
                        routes.push(x);
                    }
                    return;
                }
                rest.push(seg);
            };
            if (!r.chips) {
                split(r.text).forEach(take);
            }
            r.subs.forEach(function (s) {
                if (routeRow && (/เส้นทาง/.test(s.label) || isDotList(s.text))) {
                    split(s.text).forEach(take);
                } else {
                    rest.push((s.label ? s.label + ': ' : '') + s.text);
                }
            });
            return { r: r, text: rest.join(' · ') };
        });
        return { tiles: tiles, routes: routes };
    }

    function a4(body) {
        var o = parse(body);
        if (o.rows.length < 3) {
            return null;
        }
        var st = o.status || 'none';
        var ctx = document.createElement('canvas').getContext('2d');
        var sp = a4Split(o);
        var cnt = { red: 0, orange: 0, yellow: 0, green: 0, none: 0 };
        o.rows.forEach(function (r) { cnt[r.status || 'none']++; });

        function layout(p) {
            var ops = [];
            var R = function (txt, x, y, maxW, size, weight, color, maxLines) {
                cvFont(ctx, size, weight);
                var lines = cvClamp(ctx, cvWrap(ctx, txt, maxW, Math.round(size * 0.85)), maxLines, maxW);
                var lh = Math.round(size * 1.42);
                ops.push({ t: 'rich', lines: lines, x: x, y: y, lh: lh, size: size, weight: weight, color: color });
                return lines.length * lh;
            };
            var T = function (s, x, y, size, weight, color, align) {
                ops.push({ t: 'text', s: s, x: x, y: y, size: size, weight: weight, color: color, align: align || 'left' });
            };
            var W = function (s, size, weight) {
                cvFont(ctx, size, weight);
                return ctx.measureText(s).width;
            };

            // ---- หัวกระดาษ ----
            var head = { t: 'head', x: 0, y: 0, w: A4W, h: 152, fill: '#1f3a5f', bar: HEX[st] };
            ops.push(head);
            var bw = Math.max(W(LABEL[st], 46, 800) + 80, 230);
            ops.push({ t: 'pill', x: A4W - 32 - bw, y: 24, w: bw, h: 96, fill: BADGE[st][0], r: 22 });
            T(LABEL[st], A4W - 32 - bw / 2, 58, 46, 800, BADGE[st][1], 'center');
            if (o.statusText) {
                T(o.statusText.replace(/^—\s*/, ''), A4W - 32 - bw / 2, 100, 21, 700, BADGE[st][1], 'center');
            }
            var hw = A4W - 32 - bw - 70;
            T('รายงานสถานการณ์โรงพยาบาล (SitRep) · ทีม SAT', 36, 32, 19, 600, '#9fb6d3');
            R(o.title || 'SitRep', 36, 48, hw, 36, 800, '#ffffff', 1);
            if (o.meta.length) {
                R(o.meta.join(' · '), 36, 100, hw, 21, 400, '#cfdcec', 1);
            }
            var y = 152 + 18;

            // ---- สรุปจำนวนสี + เหตุผล ----
            var top = y, cw = 300;
            var cbox = { t: 'round', x: 0, y: top, w: cw, h: 0, fill: '#f8fafc', stroke: '#e2e8f0' };
            ops.push(cbox);
            T('ตัวชี้วัด ' + o.rows.length + ' เรื่อง', 22, top + 28, 19, 700, '#5b6573');
            var cy = top + 50;
            ['red', 'orange', 'yellow', 'green', 'none'].forEach(function (k) {
                var dim = !cnt[k];
                ops.push({ t: 'dot', x: 32, y: cy + 15, r: 9, fill: dim ? '#e2e8f0' : HEX[k] });
                T(k === 'none' ? 'ยังไม่ประเมิน' : LABEL[k], 52, cy + 15, 19, 600, dim ? '#a8b3c1' : '#334155');
                T(String(cnt[k]), cw - 24, cy + 15, 26, 800, dim ? '#cbd5e1' : '#0f172a', 'right');
                cy += 34;
            });
            cbox.h = cy - top + 12;
            var rx = cw + 16, rW = A4W - rx;
            var rbox = { t: 'box', x: rx, y: top, w: rW, h: 0, fill: TINT[st], bar: HEX[st], stroke: '#e5e7eb' };
            ops.push(rbox);
            T('เหตุผลของสถานะ', rx + 26, top + 28, 19, 700, '#5b6573');
            var ry = top + 46;
            var reasons = reasonList(o.reason).slice(0, p.reasonMax);
            if (!reasons.length) {
                ry += R('— ไม่ได้ระบุเหตุผล —', rx + 26, ry, rW - 50, 20, 400, '#94a3b8', 1);
            }
            reasons.forEach(function (x) {
                ops.push({ t: 'dot', x: rx + 31, y: ry + 15, r: 4, fill: '#64748b' });
                ry += R(x, rx + 44, ry, rW - 70, 21, 400, '#1f2937', p.reasonLines) + 4;
            });
            rbox.h = Math.max(ry + 10, cy + 12) - top;
            cbox.h = rbox.h;
            y = top + rbox.h + 16;

            // ---- SAT แนะนำ + รายงานครั้งถัดไป ----
            if (o.rec || o.next) {
                var nw = o.next ? 250 : 0;
                var bt = y;
                var rb = { t: 'box', x: 0, y: bt, w: A4W - (nw ? nw + 14 : 0), h: 0, fill: '#fff7ed', bar: '#ef6c00', stroke: '#fed7aa' };
                ops.push(rb);
                var ty = bt + 14;
                T('SAT แนะนำ', 26, ty + 12, 18, 700, '#9a3412');
                ty += 28;
                ty += R(o.rec || '-', 26, ty, rb.w - 50, 23, 700, '#7c2d12', 2);
                rb.h = Math.max(ty + 12 - bt, 96);
                if (nw) {
                    var nb = { t: 'box', x: A4W - nw, y: bt, w: nw, h: rb.h, fill: '#eff6ff', bar: '#2563eb', stroke: '#bfdbfe' };
                    ops.push(nb);
                    T('รายงานครั้งถัดไป', A4W - nw + 24, bt + 26, 18, 700, '#1e3a8a');
                    R(o.next, A4W - nw + 24, bt + 42, nw - 40, 30, 800, '#1e3a8a', 2);
                }
                y = bt + rb.h + 18;
            }

            // ---- การ์ดตัวชี้วัด 2 คอลัมน์ ----
            T('ตัวชี้วัดรายด้าน', 0, y + 12, 21, 800, '#0f172a');
            y += 32;
            var gw = (A4W - 14) / 2;
            for (var i = 0; i < sp.tiles.length; i += 2) {
                var rowTop = y, rowH = 0, cards = [];
                for (var c = 0; c < 2 && i + c < sp.tiles.length; c++) {
                    var tl = sp.tiles[i + c], r = tl.r, rs = r.status || 'none';
                    var x0 = c * (gw + 14);
                    var card = { t: 'box', x: x0, y: rowTop, w: gw, h: 0, fill: '#ffffff', bar: HEX[rs], stroke: '#dfe4ea' };
                    ops.push(card);
                    cards.push(card);
                    // ป้ายสี
                    var cl = LABEL[rs], chw = W(cl, 16, 700) + 40;
                    ops.push({ t: 'pill', x: x0 + gw - 16 - chw, y: rowTop + 12, w: chw, h: 30, fill: CHIP[rs][0], stroke: CHIP[rs][2] });
                    ops.push({ t: 'dot', x: x0 + gw - 16 - chw + 16, y: rowTop + 27, r: 5.5, fill: HEX[rs] });
                    T(cl, x0 + gw - 16 - chw + 27, rowTop + 27, 16, 700, CHIP[rs][1]);
                    // ชื่อ + เวลา
                    var nameW = gw - 40 - chw - 16;
                    var nm = $.trim(r.icon + ' ' + r.label);
                    R(nm, x0 + 22, rowTop + 10, nameW, 22, 800, '#0f172a', 1);
                    var when = r.when ? (/^ยังไม่อัปเดต/.test(r.when) ? 'ยังไม่อัปเดต' : r.when) : '';
                    var stale = /เกินรอบ/.test(when);
                    var ty2 = rowTop + 44;
                    if (when) {
                        R(when, x0 + 22, ty2, gw - 44, 15, stale ? 700 : 400, stale ? '#b91c1c' : '#8391a2', 1);
                    }
                    ty2 += 24;
                    if (r.chips) {
                        var px = x0 + 22, py = ty2 + 2;
                        split(r.text).forEach(function (x) {
                            var m = x.match(/^(.*?)\s*(🔴|🟠|🟡|🟢|⚪|🟣)️?\s*(?:\((.*)\))?\s*$/);
                            var name = m ? m[1] : x, cc = m ? (colorOf(m[2]) || 'none') : 'none';
                            var pw = W(name, 16, 700) + 36;
                            if (px + pw > x0 + gw - 16 && px > x0 + 22) {
                                px = x0 + 22;
                                py += 34;
                            }
                            ops.push({ t: 'pill', x: px, y: py, w: pw, h: 28, fill: CHIP[cc][0], stroke: CHIP[cc][2] });
                            ops.push({ t: 'dot', x: px + 14, y: py + 14, r: 5, fill: HEX[cc] });
                            T(name, px + 24, py + 14, 16, 700, CHIP[cc][1]);
                            px += pw + 6;
                        });
                        ty2 = py + 34;
                        if (tl.text) {
                            ty2 += R(tl.text, x0 + 22, ty2, gw - 44, 17, 400, '#475569', 1);
                        }
                    } else {
                        var txt = tl.text !== '' ? tl.text : (sp.routes.length && ROUTE_ROW_RE.test(r.label) ? 'ดูเส้นทางด้านล่าง' : 'ยังไม่มีข้อมูล');
                        ty2 += R(txt, x0 + 22, ty2, gw - 44, 18, 400, tl.text !== '' ? '#1f2937' : '#94a3b8', p.tileLines);
                    }
                    rowH = Math.max(rowH, ty2 + 12 - rowTop);
                }
                cards.forEach(function (cd) { cd.h = rowH; });
                y = rowTop + rowH + 12;
            }

            // ---- เส้นทาง ----
            if (sp.routes.length) {
                y += 4;
                var rt = y;
                var rbx = { t: 'round', x: 0, y: rt, w: A4W, h: 0, fill: '#f8fafc', stroke: '#e2e8f0' };
                ops.push(rbx);
                T('เส้นทางเข้า–ออก รพ. / Refer', 22, rt + 26, 20, 800, '#0f172a');
                var iy = rt + 46, colW = (A4W - 44 - 20) / 2, list = sp.routes.slice(0, 10);
                for (var q = 0; q < list.length; q += 2) {
                    var h1 = R(list[q], 22, iy, colW, 18, 400, '#1f2937', p.routeLines);
                    var h2 = list[q + 1] ? R(list[q + 1], 22 + colW + 20, iy, colW, 18, 400, '#1f2937', p.routeLines) : 0;
                    iy += Math.max(h1, h2) + 4;
                }
                rbx.h = iy - rt + 8;
                y = rt + rbx.h + 16;
            }

            // ---- ข้อเสนอ / การดำเนินการ ----
            if (o.asks.length) {
                var at = y;
                var abx = { t: 'round', x: 0, y: at, w: A4W, h: 0, fill: '#ffffff', stroke: '#e2e8f0' };
                ops.push(abx);
                T('ข้อเสนอ / การดำเนินการ', 22, at + 26, 20, 800, '#0f172a');
                var ay = at + 46;
                o.asks.slice(0, 4).forEach(function (a) {
                    var lw = 300;
                    var h1 = R($.trim(a.icon + ' ' + a.label), 22, ay, lw - 16, 18, 700, '#1f3a5f', 2);
                    var h2 = R(a.text, 22 + lw, ay, A4W - lw - 44, 19, 400, '#1f2937', p.askLines);
                    ay += Math.max(h1, h2) + 6;
                });
                abx.h = ay - at + 6;
                y = at + abx.h + 16;
            }
            return { ops: ops, h: y };
        }

        var tries = [
            { tileLines: 3, reasonMax: 4, reasonLines: 2, routeLines: 2, askLines: 2 },
            { tileLines: 2, reasonMax: 4, reasonLines: 2, routeLines: 2, askLines: 2 },
            { tileLines: 2, reasonMax: 3, reasonLines: 2, routeLines: 1, askLines: 1 },
            { tileLines: 1, reasonMax: 3, reasonLines: 1, routeLines: 1, askLines: 1 }
        ];
        var L = null;
        for (var k = 0; k < tries.length; k++) {
            L = layout(tries[k]);
            if (L.h + 44 <= A4H) {
                break;
            }
        }
        var H = Math.max(A4H, Math.ceil(L.h + 44));
        // ท้ายกระดาษ
        var d = new Date(), pad2 = function (n) { return ('0' + n).slice(-2); };
        L.ops.push({ t: 'rect', x: 0, y: H - 40, w: A4W, h: 1.5, fill: '#e3e8ee' });
        L.ops.push({ t: 'text', s: 'สร้างจากระบบ ' + ((document.title || '').split('·').pop() || '').trim() + ' · ' + pad2(d.getDate()) + '/'
            + pad2(d.getMonth() + 1) + '/' + (d.getFullYear() + 543) + ' ' + pad2(d.getHours()) + ':' + pad2(d.getMinutes()) + ' น.',
            x: 0, y: H - 18, size: 17, weight: 400, color: '#8391a2', align: 'left' });
        L.ops.push({ t: 'text', s: 'สรุป 1 หน้า · รายละเอียดเต็มดูในหน้า SAT', x: A4W, y: H - 18, size: 17, weight: 400, color: '#8391a2', align: 'right' });

        var cv = document.createElement('canvas');
        cv.width = A4W;
        cv.height = H;
        var g = cv.getContext('2d');
        g.fillStyle = '#ffffff';
        g.fillRect(0, 0, A4W, H);
        g.textBaseline = 'middle';
        L.ops.forEach(function (op) {
            g.textAlign = 'left';
            if (op.t === 'rect') {
                g.fillStyle = op.fill;
                g.fillRect(op.x, op.y, op.w, op.h);
            } else if (op.t === 'head') {
                g.save();
                cvRound(g, op.x, op.y, op.w, op.h, 18);
                g.fillStyle = op.fill;
                g.fill();
                g.clip();
                g.fillStyle = op.bar;
                g.fillRect(op.x, op.y + op.h - 10, op.w, 10);
                g.restore();
            } else if (op.t === 'pill') {
                cvRound(g, op.x, op.y, op.w, op.h, op.r || op.h / 2);
                g.fillStyle = op.fill;
                g.fill();
                if (op.stroke) {
                    g.strokeStyle = op.stroke;
                    g.lineWidth = 1.5;
                    g.stroke();
                }
            } else if (op.t === 'dot') {
                g.beginPath();
                g.arc(op.x, op.y, op.r, 0, Math.PI * 2);
                g.fillStyle = op.fill;
                g.fill();
            } else if (op.t === 'round' || op.t === 'box') {
                cvRound(g, op.x, op.y, op.w, op.h, 14);
                g.fillStyle = op.fill;
                g.fill();
                g.strokeStyle = op.stroke || '#e5e7eb';
                g.lineWidth = 1.5;
                g.stroke();
                if (op.t === 'box') {
                    g.save();
                    cvRound(g, op.x, op.y, op.w, op.h, 14);
                    g.clip();
                    g.fillStyle = op.bar;
                    g.fillRect(op.x, op.y, 8, op.h);
                    g.restore();
                }
            } else if (op.t === 'text') {
                cvFont(g, op.size, op.weight);
                g.fillStyle = op.color;
                g.textAlign = op.align || 'left';
                g.fillText(op.s, op.x, op.y);
            } else if (op.t === 'rich') {
                cvFont(g, op.size, op.weight);
                op.lines.forEach(function (ln, k2) {
                    var cx = op.x, cy2 = op.y + k2 * op.lh + op.lh / 2;
                    ln.forEach(function (tk) {
                        if (tk.c) {
                            g.beginPath();
                            g.arc(cx + tk.w / 2, cy2, tk.w * 0.32, 0, Math.PI * 2);
                            g.fillStyle = HEX[tk.c] || HEX.none;
                            g.fill();
                        } else {
                            g.fillStyle = op.color;
                            g.fillText(tk.s, cx, cy2);
                        }
                        cx += tk.w;
                    });
                });
            }
        });
        return cv;
    }

    // ปุ่ม "สร้างเป็นภาพ" (sat.js sitrepCanvas) ใช้ canvas() → สรุป 1 หน้า A4 · ภาพตารางยาวแบบเดิมยังเรียกได้ที่ canvasFull()
    w.SatSitrepView = { html: html, parse: parse, canvas: a4, canvasFull: canvas };

    if (!$) {
        return;
    }
    $(document).on('click', '.srv-raw-toggle', function () {
        var $v = $(this).closest('.srv');
        var raw = $v.toggleClass('is-raw').hasClass('is-raw');
        $v.find('.srv-raw').prop('hidden', !raw);
        $(this).html(raw ? '<i class="fa fa-table"></i> ดูแบบตาราง' : '<i class="fa fa-align-left"></i> ดูแบบข้อความ');
    });
    // ประวัติ SitRep (ปุ่มในกล่องสถานะโรงพยาบาล) — แสดงในหน้าต่างเดียวกับ SitRep
    $(document).on('click', '.js-sat-rep-hist', function () {
        var $vm = $('#satViewModal');
        var $tpl = $('#satRepHistTpl');
        if (!$vm.length || !$tpl.length) {
            return;
        }
        $vm.find('[data-f="title"]').text('ประวัติ SitRep ที่ออกแล้ว');
        $vm.find('[data-f="body"]').html($tpl.html());
        $vm.data('copy', '');
        $vm.find('.js-sat-copy-view, .js-sat-img-view').hide();
        $vm.data('srvFromHist', false).modal('show');
    });
    // เปิดฉบับจากตารางประวัติ → มีปุ่มกลับ
    $(document).on('click', '#satViewModal .js-sat-rep', function () {
        $('#satViewModal').data('srvFromHist', true);
    });
    $(document).on('hidden.bs.modal', '#satViewModal', function () {
        $(this).data('srvFromHist', false);
    });
    // จัดหน้าให้เองเมื่อเปิดหน้าต่าง SitRep (sat.js ใส่ข้อความเป็น <pre class="sat-pre"> ก่อนเปิดหน้าต่าง)
    $(document).on('show.bs.modal', '#satViewModal', function () {
        var $m = $(this);
        var $pre = $m.find('[data-f="body"] > pre.sat-pre');
        var ok = $pre.length === 1 && /^SitRep\s*ฉบับที่/.test($.trim($m.find('[data-f="title"]').text()));
        if (ok) {
            $m.find('[data-f="body"]').html(html($pre.text(), !!$m.data('srvFromHist')));
        }
        // หน้าต่างกว้างขึ้นเฉพาะตอนแสดง SitRep แบบตาราง
        $m.toggleClass('srv-wide', ok && $m.find('.srv-table').length > 0);
    });
})(window, window.jQuery);
