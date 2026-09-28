/* บุคลากรที่ได้รับผลกระทบ — ดึงคำตอบจาก Google Sheet / อัปโหลด CSV / ติดตาม / รวมรายการซ้ำ */
$(function () {
    'use strict';

    var esc = Flood.esc;
    var reload = function () { setTimeout(function () { window.location.reload(); }, 900); };

    /* ---------- ดึงคำตอบใหม่ ----------
     * เซิร์ฟเวอร์โรงพยาบาลออกอินเทอร์เน็ตไม่ได้ → ให้เบราว์เซอร์ของเจ้าหน้าที่อ่านชีตจาก Google (gviz JSONP)
     * แล้วส่งเป็นไฟล์ CSV ให้ flood/staffUpload · ชีตที่ตั้ง "จำกัด" ก็อ่านได้ ถ้าเบราว์เซอร์ล็อกอิน Google บัญชีที่มีสิทธิ์ดูชีต */
    function sheetParts(url) {
        var m = /\/spreadsheets\/d\/([a-zA-Z0-9_-]{20,})/.exec(url || '');
        var g = /[#&?]gid=(\d+)/.exec(url || '');
        return m ? { id: m[1], gid: g ? g[1] : '0' } : null;
    }

    function loadSheet(url) {
        var d = $.Deferred();
        var p = sheetParts(url);
        if (!p) {
            return d.reject('ลิงก์ชีตไม่ถูกต้อง').promise();
        }
        var cb = '__staffSheet' + Date.now() + Math.floor(Math.random() * 1000);
        var s = document.createElement('script');
        var done = function (fn, v) {
            clearTimeout(timer);
            try { delete window[cb]; } catch (e) { window[cb] = undefined; }
            if (s.parentNode) { s.parentNode.removeChild(s); }
            fn(v);
        };
        var timer = setTimeout(function () { done(d.reject, 'Google ไม่ตอบกลับ (หมดเวลา)'); }, 30000);
        window[cb] = function (o) {
            if (!o || o.status !== 'ok' || !o.table) {
                done(d.reject, 'อ่านชีตไม่ได้ — ล็อกอิน Google บัญชีที่มีสิทธิ์ดูชีตนี้ในเบราว์เซอร์ หรือดาวน์โหลด CSV แล้วอัปโหลด');
                return;
            }
            done(d.resolve, o.table);
        };
        s.onerror = function () { done(d.reject, 'โหลดชีตไม่ได้ — ล็อกอิน Google บัญชีที่มีสิทธิ์ดูชีต หรือดาวน์โหลด CSV แล้วอัปโหลด'); };
        s.src = 'https://docs.google.com/spreadsheets/d/' + p.id + '/gviz/tq?gid=' + p.gid + '&headers=1&tqx=responseHandler:' + cb + ';out:json';
        document.head.appendChild(s);
        return d.promise();
    }

    function tableToCsv(t) {
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
        return '\ufeff' + lines.join('\r\n');
    }

    function syncOne(src) {
        return loadSheet(src.url).then(function (t) {
            var fd = new FormData();
            fd.append('source_id', src.id);
            fd.append('csv', new Blob([tableToCsv(t)], { type: 'text/csv' }), 'sheet.csv');
            return Flood.post('flood/staffUpload', fd, { timeout: 200000, silent: true }).then(function (o) { return o.msg; },
                function (o) { return $.Deferred().reject(src.name + ': ' + ((o && o.msg) || 'นำเข้าไม่สำเร็จ')).promise(); });
        }, function (msg) { return $.Deferred().reject(src.name + ': ' + msg).promise(); });
    }

    $(document).on('click', '.js-staff-sync', function () {
        var $btn = $(this);
        var id = $btn.data('id') || 0;
        var list = [];
        $('.js-staff-sync[data-url]').each(function () {
            var $b = $(this);
            if (!id || $b.data('id') === id) {
                list.push({ id: $b.data('id'), name: $b.data('name'), url: $b.data('url') });
            }
        });
        if (!list.length) {
            Flood.toast('ไม่มีแหล่งข้อมูลที่เปิดใช้', 'danger');
            return;
        }
        Flood.busy($btn, true, 'กำลังดึง…');
        var ok = [];
        var bad = [];
        var chain = $.Deferred().resolve().promise();
        list.forEach(function (src) {
            chain = chain.then(function () {
                return syncOne(src).then(function (m) { ok.push(m); }, function (m) { bad.push(m); return $.Deferred().resolve().promise(); });
            });
        });
        chain.then(function () {
            Flood.busy($btn, false);
            if (bad.length) {
                Flood.confirm({
                    title: ok.length ? 'ดึงข้อมูลได้บางฟอร์ม' : 'ดึงข้อมูลไม่สำเร็จ',
                    html: '<ul style="padding-left:18px;margin:0">' + ok.concat(bad).map(function (l) { return '<li>' + esc(l) + '</li>'; }).join('') + '</ul>',
                    okText: 'รับทราบ'
                }, function () { if (ok.length) { reload(); } return true; });
                return;
            }
            Flood.toast(ok.join(' · '), 'success', 6000);
            reload();
        });
    });

    /* ---------- อัปโหลด CSV ---------- */
    $(document).on('click', '.js-staff-upload', function () {
        $('#suId').val($(this).data('id'));
        $('#suName').text($(this).data('name'));
        $('#staffUploadForm')[0].reset();
        $('#staffUploadModal').modal('show');
    });
    $('#staffUploadForm').on('submit', function (e) {
        e.preventDefault();
        var $btn = $(this).find('[type=submit]');
        Flood.busy($btn, true, 'กำลังนำเข้า…');
        Flood.post('flood/staffUpload', new FormData(this), { timeout: 200000 }).then(function (o) {
            Flood.toast(o.msg, 'success');
            $('#staffUploadModal').modal('hide');
            reload();
        }, function () { Flood.busy($btn, false); });
    });

    /* ---------- แหล่งข้อมูล (ผู้ดูแลระบบ) ---------- */
    $(document).on('click', '.js-staff-src', function () {
        var id = $(this).data('id') || 0;
        Flood.confirm({
            title: id ? 'แก้ไขแหล่งข้อมูล' : 'เพิ่มฟอร์ม (Google Sheet คำตอบ)',
            html: '<div class="form-group"><label>ชื่อฟอร์ม</label><input type="text" class="form-control" id="srcName" maxlength="150" /></div>'
                + '<div class="form-group"><label>ลิงก์ Google Sheet (แท็บคำตอบ)</label><input type="url" class="form-control" id="srcUrl" maxlength="500"'
                + ' placeholder="https://docs.google.com/spreadsheets/d/…/edit?gid=…" /></div>'
                + '<div class="small-muted">เปิดชีตไปที่แท็บ "การตอบแบบฟอร์ม" แล้วคัดลอกลิงก์จากแถบที่อยู่ (มี gid= ต่อท้าย)</div>',
            okText: 'บันทึก'
        }, function ($m) {
            return Flood.post('flood/staffSource', { source_id: id, name: $m.find('#srcName').val(), sheet_url: $m.find('#srcUrl').val() })
                .then(function (o) { Flood.toast(o.msg, 'success'); reload(); });
        });
        $('#srcName').val($(this).data('name') || '');
        $('#srcUrl').val($(this).data('url') || '');
    });
    $(document).on('click', '.js-staff-src-toggle', function () {
        Flood.post('flood/staffSource', { source_id: $(this).data('id'), act: 'toggle' }).then(function (o) {
            Flood.toast(o.msg, 'success');
            reload();
        });
    });

    /* ---------- รายละเอียด + ติดตาม ---------- */
    function field(label, v) {
        return v ? '<div class="staff-kv"><span>' + esc(label) + '</span><b>' + esc(v) + '</b></div>' : '';
    }
    function linkify(v) {
        // คอลัมน์รูปภาพของ Google Form เป็นลิงก์ Drive คั่นด้วย ,
        var s = String(v);
        if (/^https:\/\/(drive|docs)\.google\.com\//.test(s) || /^https?:\/\/[^\s,]+\/flood\/attachment\/\d+/.test(s)) {
            return s.split(/\s*,\s*/).map(function (u, i) {
                return /^https:\/\//.test(u) ? '<a href="' + esc(u) + '" target="_blank" rel="noopener">รูปที่ ' + (i + 1) + ' <i class="fa fa-external-link"></i></a>' : esc(u);
            }).join(' · ');
        }
        // ลิงก์อื่น (เช่น พิกัด Google Maps จากฟอร์มในระบบ) กดเปิดได้
        return esc(s).replace(/(https:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener">$1</a>').replace(/\n/g, '<br>');
    }

    $(document).on('click', '.js-staff-view', function () {
        Flood.get('flood/staffData/' + $(this).data('id')).then(function (o) {
            var s = o.staff;
            $('#stId').val(s.staff_id);
            $('#stName').text(s.name + ' #' + s.staff_id);
            $('#stLevel').text('ผลกระทบ: ' + s.level_name);
            $('#stHead').html(
                field('ตำแหน่ง', s.position) + field('กลุ่มงาน', s.department)
                + (s.phone ? '<div class="staff-kv"><span>โทร</span><b><a href="tel:' + esc(s.phone) + '"><i class="fa fa-phone"></i> ' + esc(s.phone) + '</a>'
                    + (s.phone2 ? ' · สำรอง ' + esc(s.phone2) : '') + '</b></div>' : '')
                + (s.flags.length ? '<div class="staff-flags">' + s.flags.map(function (f) { return '<span class="tag">' + esc(f) + '</span>'; }).join('') + '</div>' : ''));
            $('input[name=st_follow]').prop('checked', false).filter('[value="' + s.follow_status + '"]').prop('checked', true);
            $('#stNote').val(s.follow_note);
            $('#stFollowed').text(s.followed ? 'บันทึกล่าสุด ' + s.followed : '');
            $('#stMergeFrom').val('');
            $('#stResps').html(s.responses.length ? s.responses.map(function (r) {
                var rows = Object.keys(r.answers).map(function (k) {
                    return '<tr><th>' + esc(k) + '</th><td>' + linkify(r.answers[k]) + '</td></tr>';
                }).join('');
                return '<details class="staff-resp" open><summary><b>' + esc(r.source || 'ฟอร์ม') + '</b> <span class="small-muted">' + esc(r.at) + '</span></summary>'
                    + '<table class="table table-condensed staff-ans">' + rows + '</table></details>';
            }).join('') : '<div class="small-muted">ไม่มีคำตอบ</div>');
            $('#staffModal').modal('show');
        });
    });

    $('#stSave').on('click', function () {
        var $btn = $(this);
        var st = $('input[name=st_follow]:checked').val();
        if (!st) {
            Flood.toast('กรุณาเลือกสถานะการติดตาม', 'danger');
            return;
        }
        Flood.busy($btn, true);
        Flood.post('flood/staffFollow', { staff_id: $('#stId').val(), follow_status: st, follow_note: $('#stNote').val() }).then(function (o) {
            Flood.toast(o.msg, 'success');
            $('#staffModal').modal('hide');
            reload();
        }, function () { Flood.busy($btn, false); });
    });

    $('#stMerge').on('click', function () {
        var into = $('#stId').val();
        var from = $.trim($('#stMergeFrom').val());
        if (!from || from === into) {
            Flood.toast('กรุณาใส่เลขรายการที่ซ้ำ (ไม่ใช่เลขของรายการนี้)', 'danger');
            return;
        }
        Flood.confirm({ title: 'รวมรายการ', message: 'ย้ายคำตอบทั้งหมดของ #' + from + ' มารวมกับ #' + into + ' แล้วลบ #' + from + ' ?', okText: 'รวม', okClass: 'btn-danger' }, function () {
            return Flood.post('flood/staffMerge', { into_id: into, from_id: from }).then(function (o) {
                Flood.toast(o.msg, 'success');
                $('#staffModal').modal('hide');
                reload();
            });
        });
    });
});
