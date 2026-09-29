/* ทะเบียนกลุ่มเปราะบาง — แผนที่ / ตัวกรอง / อัปเดตสถานะ / ดึงรายชื่อจาก Google Sheet */
$(function () {
    'use strict';

    var esc = Flood.esc;
    var EVAC_COLOR = { normal: '#7c3aed', alerted: '#c026d3', evacuated: '#16a34a', shelter_in_place: '#0ea5e9', admitted: '#155e9c' };
    var evac = window.VULN_EVAC || {};

    /* ---------- แผนที่ ---------- */
    if ($('#vulnMap').length) {
        var map = FloodMap.create('vulnMap');
        var zl = L.featureGroup().addTo(map);
        var ml = L.featureGroup().addTo(map);
        (window.VULN_ZONES || []).forEach(function (z) { FloodMap.zoneLayer(z).addTo(zl); });
        (window.VULN_MAP || []).forEach(function (v) {
            var ev = evac[v.evac_status] || { name: v.evac_status };
            L.marker([v.lat, v.lng], { icon: FloodMap.pinIcon(EVAC_COLOR[v.evac_status] || '#7c3aed', 'fa-wheelchair') })
                .bindPopup('<div class="pop-title">' + esc(v.name) + '</div>' + esc((v.groups || []).join(', '))
                    + '<div style="margin-top:4px">' + esc(ev.name) + '</div>'
                    + (v.zone ? '<div class="small-muted">อยู่ใน ' + esc(v.zone.name) + '</div>' : '')
                    + '<div style="margin-top:6px"><a href="#" class="js-vstatus" data-id="' + v.person_id + '">อัปเดตสถานะ</a> · '
                    + '<a href="' + Flood.url('flood/vulnerableForm/' + v.person_id) + '">แก้ไข</a></div>')
                .addTo(ml);
        });
        FloodMap.fitLayers(map, ml.getLayers(), 15);
    }

    /* ---------- ตัวกรองตำบล ---------- */
    var tambons = Flood.tambonList(window.VULN_TAMBONS);   // โหลดเพิ่มทีละอำเภอ
    function fillTambon(selected) {
        var a = $('#vfAmphoe').val();
        var $t = $('#vfTambon').empty().append('<option value="">ทุกตำบล</option>');
        Flood.loadTambons(a).always(function () {
            if ($('#vfAmphoe').val() !== a) {
                return;
            }
            tambons.forEach(function (t) {
                if (a && t.amphoe_code === a) {
                    $t.append($('<option>').val(t.tambon_code).text('ต.' + t.name));
                }
            });
            if (selected) {
                $t.val(selected);
            }
        });
    }
    $('#vfAmphoe').on('change', function () { fillTambon(''); });
    fillTambon($('#vfTambon').data('selected'));

    /* ---------- อัปเดตสถานะ ---------- */
    $(document).on('click', '.js-vstatus', function (e) {
        e.preventDefault();
        var id = $(this).data('id');
        Flood.get('flood/vulnerableData/' + id).then(function (o) {
            var p = o.person;
            $('#vsId').val(p.person_id);
            $('#vsName').text(p.name);
            $('input[name=vs_status]').prop('checked', false).filter('[value="' + p.evac_status + '"]').prop('checked', true);
            $('#vsPlace').val(p.evac_place);
            $('#vsNote').val('');
            $('#vsLogs').html(p.logs.length
                ? '<label>ประวัติการติดตาม</label><ul class="timeline">' + p.logs.map(function (l) {
                    return '<li class="timeline-item"><div class="t-head">' + esc(l.status) + (l.place ? ' — ' + esc(l.place) : '') + '</div>'
                        + '<div class="t-meta">' + esc(l.time) + ' · ' + esc(l.user) + '</div>'
                        + (l.note ? '<div class="t-note">' + esc(l.note) + '</div>' : '') + '</li>';
                }).join('') + '</ul>'
                : '<div class="small-muted">ยังไม่มีประวัติการติดตาม</div>');
            $('#vStatusModal').modal('show');
        });
    });

    $('#vsSave').on('click', function () {
        var $btn = $(this);
        var status = $('input[name=vs_status]:checked').val();
        if (!status) {
            Flood.toast('กรุณาเลือกสถานะ', 'danger');
            return;
        }
        Flood.busy($btn, true);
        Flood.post('flood/vulnerableStatus', {
            person_id: $('#vsId').val(), evac_status: status, evac_place: $('#vsPlace').val(), note: $('#vsNote').val()
        }).then(function (o) {
            Flood.toast(o.msg, 'success');
            setTimeout(function () { window.location.reload(); }, 500);
        }, function () {
            Flood.busy($btn, false);
        });
    });

    /* ---------- ดึงรายชื่อจาก Google Sheet (FloodSheet ใน sheet_pull.js) ---------- */
    var reload = function () { setTimeout(function () { window.location.reload(); }, 900); };

    $(document).on('click', '.js-vsrc-sync', function () {
        var $btn = $(this);
        var id = $btn.data('id') || 0;
        var list = [];
        $('.js-vsrc-sync[data-url]').each(function () {
            var $b = $(this);
            if (!id || $b.data('id') === id) {
                list.push({ id: $b.data('id'), name: $b.data('name'), url: $b.data('url'), kind: $b.data('kind') || 'persons' });
            }
        });
        if (!list.length) {
            Flood.toast('ยังไม่มีชีตที่เปิดใช้', 'danger');
            return;
        }
        Flood.busy($btn, true, 'กำลังดึง…');
        var ok = [];
        var bad = [];
        var chain = $.Deferred().resolve().promise();
        // รายงานศูนย์พักพิง: ดึงแท็บรายวันย้อนหลัง 21 วัน (ตัวเลขรายศูนย์ ไม่ใช่รายชื่อคน)
        var daily = function (src) {
            $btn.html('<i class="fa fa-spinner fa-spin"></i> กำลังหาแท็บรายวัน…');
            return FloodSheet.pullDaily('flood/vulnUpload', src.id, src.url, 21).then(function (r) {
                ok = ok.concat(r.ok);
                bad = bad.concat(r.bad.map(function (m) { return src.name + ': ' + m; }));
                if (!r.ok.length && !r.bad.length) {
                    bad.push(src.name + ': ไม่พบแท็บรายวันใน 21 วันล่าสุด (ชื่อแท็บแบบ "' + (r.missingToday || '28 ก.ย.69') + '")');
                } else if (r.missingToday) {
                    ok.push(src.name + ': ยังไม่มีแท็บของวันนี้ ("' + r.missingToday + '")');
                }
            });
        };
        list.forEach(function (src) {
            chain = chain.then(function () {
                if (src.kind === 'shelter') {
                    return daily(src);
                }
                return FloodSheet.pull('flood/vulnUpload', src.id, src.url).then(function (o) {
                    ok.push(o.msg);
                    return o.shelter ? daily(src) : null;   // ระบบพบว่าเป็นรายงานศูนย์พักพิง → ดึงแท็บรายวันต่อ
                }, function (m) { bad.push(src.name + ': ' + m); return $.Deferred().resolve().promise(); });
            });
        });
        chain.then(function () {
            Flood.busy($btn, false);
            Flood.confirm({
                title: bad.length ? (ok.length ? 'ดึงข้อมูลได้บางชีต' : 'ดึงข้อมูลไม่สำเร็จ') : 'ดึงข้อมูลจากชีตแล้ว',
                html: '<ul style="padding-left:18px;margin:0">' + ok.concat(bad).map(function (l) { return '<li>' + esc(l) + '</li>'; }).join('') + '</ul>',
                okText: 'รับทราบ'
            }, function () { if (ok.length) { reload(); } return true; });
        });
    });

    $(document).on('click', '.js-vsrc-upload', function () {
        $('#vuId').val($(this).data('id'));
        $('#vuName').text($(this).data('name'));
        $('#vulnUploadForm')[0].reset();
        $('#vulnUploadModal').modal('show');
    });
    $('#vulnUploadForm').on('submit', function (e) {
        e.preventDefault();
        var $btn = $(this).find('[type=submit]');
        Flood.busy($btn, true, 'กำลังนำเข้า…');
        Flood.post('flood/vulnUpload', new FormData(this), { timeout: 200000 }).then(function (o) {
            $('#vulnUploadModal').modal('hide');
            Flood.confirm({ title: 'นำเข้าแล้ว', message: o.msg, okText: 'รับทราบ' }, function () { reload(); return true; });
        }, function () { Flood.busy($btn, false); });
    });

    /* ---------- แหล่งข้อมูล (ผู้ดูแลระบบ) ---------- */
    $(document).on('click', '.js-vsrc', function () {
        var $b = $(this);
        var id = $b.data('id') || 0;
        var groups = window.VULN_GROUPS || {};
        var sel = String($b.data('groups') || '').split(',');
        var kind = $b.data('kind') || 'persons';
        var chips = Object.keys(groups).map(function (k) {
            return '<label class="checkbox-inline" style="margin:0 12px 4px 0"><input type="checkbox" class="js-vsrc-g" value="' + esc(k) + '"'
                + (sel.indexOf(k) >= 0 ? ' checked' : '') + ' /> ' + esc(groups[k]) + '</label>';
        }).join('');
        Flood.confirm({
            title: id ? 'แก้ไขชีตรายชื่อ' : 'เพิ่มชีตรายชื่อกลุ่มเปราะบาง',
            html: '<div class="form-group"><label>ชื่อชีต</label><input type="text" class="form-control" id="vsrcName" maxlength="150" placeholder="เช่น ผู้ป่วยติดเตียง COC / ผู้ป่วยฟอกไต" /></div>'
                + '<div class="form-group"><label>ลิงก์ Google Sheet (แท็บรายชื่อ)</label><input type="url" class="form-control" id="vsrcUrl" maxlength="500"'
                + ' placeholder="https://docs.google.com/spreadsheets/d/…/edit?gid=…" />'
                + '<div class="small-muted">เปิดชีตไปที่แท็บรายชื่อ แล้วคัดลอกลิงก์จากแถบที่อยู่ (มี gid= ต่อท้าย) · ชีตต้องเป็นรายชื่อรายคน 1 แถว = 1 คน</div></div>'
                + '<div class="form-group"><label>ประเภทชีต</label><div>'
                + '<label class="radio-inline"><input type="radio" name="vsrcKind" value="persons"' + (kind !== 'shelter' ? ' checked' : '') + ' /> รายชื่อรายคน (1 แถว = 1 คน)</label> '
                + '<label class="radio-inline"><input type="radio" name="vsrcKind" value="shelter"' + (kind === 'shelter' ? ' checked' : '') + ' /> รายงานศูนย์พักพิง (แท็บรายวัน เช่น "28 ก.ย.69" · 1 แถว = 1 ศูนย์)</label>'
                + '</div></div>'
                + '<div class="form-group" style="margin:0"><label>ถ้าแถวในชีตไม่ระบุกลุ่ม ให้ใส่กลุ่ม (เฉพาะรายชื่อรายคน)</label><div>' + chips + '</div>'
                + '<div class="small-muted">เช่น ชีตผู้ป่วยฟอกไตทั้งชีต เลือก "ผู้ป่วยฟอกไต" · ถ้าชีตมีคอลัมน์กลุ่ม/โรค/ADL/อายุ ระบบอ่านจากชีตก่อน</div></div>',
            okText: 'บันทึก'
        }, function ($m) {
            return Flood.post('flood/vulnSource', {
                source_id: id, name: $m.find('#vsrcName').val(), sheet_url: $m.find('#vsrcUrl').val(),
                kind: $m.find('input[name=vsrcKind]:checked').val() || 'persons',
                default_groups: $m.find('.js-vsrc-g:checked').map(function () { return this.value; }).get()
            }).then(function (o) { Flood.toast(o.msg, 'success'); reload(); });
        });
        $('#vsrcName').val($b.data('name') || '');
        $('#vsrcUrl').val($b.data('url') || '');
    });
    $(document).on('click', '.js-vsrc-toggle', function () {
        Flood.post('flood/vulnSource', { source_id: $(this).data('id'), act: 'toggle' }).then(function (o) {
            Flood.toast(o.msg, 'success');
            reload();
        });
    });
});
