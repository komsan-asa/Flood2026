/* ห้องสถานการณ์ SAT — แผนที่รอบโรงพยาบาล / บันทึกตัวชี้วัด / ผลโทรหน่วยบริการ / เส้นทาง / SitRep */
$(function () {
    'use strict';

    var SAT = window.SAT || {};
    var esc = Flood.esc;
    var COLORS = SAT.colors || {};
    var reload = function () { setTimeout(function () { window.location.reload(); }, 600); };

    /* ==================== แผนที่ ==================== */
    var map = null;
    var picking = false;
    function levelColor(code) {
        var all = (window.FLOOD_MAP && window.FLOOD_MAP.levelsAll) || {};
        return all[code] ? all[code].color : '#64748b';
    }
    function levelName(code) {
        var all = (window.FLOOD_MAP && window.FLOOD_MAP.levelsAll) || {};
        return all[code] ? all[code].name : code;
    }
    function statusHex(s) {
        return COLORS[s] ? COLORS[s].hex : '#94a3b8';
    }
    if (document.getElementById('satMapEl') && window.L) {
        var cfg = window.FLOOD_MAP || {};
        var h = SAT.hosp || { lat: 13.803, lng: 102.077, name: 'โรงพยาบาล' };
        map = L.map('satMapEl', { scrollWheelZoom: false }).setView([h.lat, h.lng], 11);
        L.tileLayer(cfg.tileUrl || 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
            { maxZoom: 19, attribution: cfg.attribution || '' }).addTo(map);
        map.on('focus', function () { map.scrollWheelZoom.enable(); });
        map.on('blur', function () { map.scrollWheelZoom.disable(); });

        // พื้นที่ประกาศ / จุดทางหลวง
        (SAT.zones || []).forEach(function (z) {
            var c = levelColor(z.level);
            var hdms = z.source === 'hdms';
            var opt = { color: c, weight: hdms ? 2 : 1.5, fillColor: c, fillOpacity: hdms ? 0.28 : 0.16, dashArray: z.source === 'web' ? '5 4' : null };
            var layer = (z.poly && z.poly.length > 2) ? L.polygon(z.poly, opt) : L.circle([z.lat, z.lng], $.extend({ radius: Math.max(z.r || 0, 150) }, opt));
            layer.bindPopup('<b>' + esc(z.name) + '</b><br>' + esc(levelName(z.level))
                + (hdms ? ' · กรมทางหลวง' : (z.source === 'web' ? ' · ข่าว/โซเชียล (รอตรวจสอบ)' : (z.source === 'report' ? ' · รายงานประชาชน' : '')))
                + (z.at ? '<br><small>' + esc(z.at) + '</small>' : ''));
            layer.addTo(map);
        });

        // หน่วยบริการ
        (SAT.facilities || []).forEach(function (f) {
            var main = f.type === 'main';
            if (main) {
                return;   // โรงพยาบาลแม่ข่ายใช้หมุดของโรงพยาบาลด้านล่าง
            }
            var m = L.circleMarker([f.lat, f.lng], {
                radius: f.type === 'hospital' ? 9 : 7, color: '#0f172a', weight: 1.5, dashArray: f.approx ? '3 3' : null,
                fillColor: statusHex(f.status), fillOpacity: 0.95
            });
            m.bindPopup('<b>' + esc(f.name) + '</b><br>' + (COLORS[f.status] ? COLORS[f.status].emoji + ' ' + esc(COLORS[f.status].label) : '⚪ ยังไม่มีข้อมูล')
                + (f.at ? '<br><small>ข้อมูล ' + esc(f.at) + (f.overdue ? ' · ถึงรอบโทร' : '') + '</small>' : '')
                + (f.approx ? '<br><small>พิกัดโดยประมาณ (จุดกลางตำบล/อำเภอ)</small>' : ''));
            m.addTo(map);
        });

        // จุดแจ้งจากประชาชน (ยืนยันแล้ว / รอตรวจสอบ) — อ่านจาก api/zones ชุดเดียวกับแผนที่ประชาชน
        (function () {
            var RADIUS_KM = 60;
            var layerOk = L.layerGroup().addTo(map);
            var layerPending = L.layerGroup().addTo(map);
            function distKm(aLat, aLng, bLat, bLng) {
                var r = Math.PI / 180;
                var dLat = (bLat - aLat) * r, dLng = (bLng - aLng) * r;
                var s = Math.sin(dLat / 2) * Math.sin(dLat / 2)
                    + Math.cos(aLat * r) * Math.cos(bLat * r) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
                return 12742 * Math.asin(Math.min(1, Math.sqrt(s)));
            }
            // จุดที่ใช้พิกัดกลางตำบลซ้อนกันเป็นกอง → ขยับออกเล็กน้อย (คงที่ตามเลขจุด) ให้กดแยกได้
            function spread(p) {
                if (!p.approx) {
                    return [p.lat, p.lng];
                }
                var a = (+p.id || 0) * 2.399, d = 120 + ((+p.id || 0) % 5) * 45;
                return [p.lat + (d * Math.cos(a)) / 111320, p.lng + (d * Math.sin(a)) / (111320 * Math.cos(p.lat * Math.PI / 180))];
            }
            function row(label, v) {
                return v ? '<br>' + label + ': ' + esc(v) : '';
            }
            Flood.get('api/zones', {}, { silent: true }).then(function (o) {
                var nOk = 0, nPending = 0;
                (o.points || []).forEach(function (p) {
                    if (!p.lat || !p.lng || distKm(h.lat, h.lng, +p.lat, +p.lng) > RADIUS_KM) {
                        return;
                    }
                    var pending = !!p.pending;
                    var c = pending ? '#f97316' : levelColor(p.level);
                    var mk = L.circleMarker(spread(p), {
                        radius: 6, weight: 2, color: pending ? '#c2410c' : '#ffffff',
                        dashArray: pending ? '3 2' : null,
                        fillColor: pending ? '#fff7ed' : c, fillOpacity: pending ? 0.9 : 0.95
                    });
                    var web = p.web && p.web.url ? '<br><a href="' + esc(p.web.url) + '" target="_blank" rel="noopener noreferrer">' + esc(p.web.name || 'เว็บต้นทาง') + ' ↗</a>' : '';
                    mk.bindPopup('<b>' + (pending ? '⏳ จุดแจ้งจากประชาชน · รอตรวจสอบ' : '📍 จุดแจ้งจากประชาชน · ' + esc(levelName(p.level))) + '</b>'
                        + (p.zone_name ? '<br>' + esc(p.zone_name) : '')
                        + row('ระดับน้ำ', p.depth) + row('ท่วมกว้าง', p.extent) + row('รถ', p.vehicle) + row('แนวโน้ม', p.trend)
                        + (p.time_th ? '<br><small>แจ้ง ' + esc(p.time_th) + (p.ago ? ' (' + esc(p.ago) + ')' : '') + '</small>' : '')
                        + (p.approx ? '<br><small>⚠️ พิกัดโดยประมาณ (จุดกลางตำบล) — ไม่ใช่ตำแหน่งจริง</small>' : '')
                        + (p.fb_url ? '<br><a href="' + esc(p.fb_url) + '" target="_blank" rel="noopener noreferrer">โพสต์ต้นทาง ↗</a>' : '')
                        + web
                        + '<br><a href="' + 'flood/reports' + '" target="_blank">ตรวจ/ประกาศในหน้ารายงานประชาชน ↗</a>');
                    mk.addTo(pending ? layerPending : layerOk);
                    if (pending) {
                        nPending++;
                    } else {
                        nOk++;
                    }
                });
                var overlays = {};
                overlays['📍 จุดแจ้งประชาชน · ยืนยันแล้ว (' + nOk + ')'] = layerOk;
                overlays['⏳ จุดแจ้งประชาชน · รอตรวจสอบ (' + nPending + ')'] = layerPending;
                L.control.layers(null, overlays, { collapsed: false, position: 'topright' }).addTo(map);
            });
        })();

        // โรงพยาบาลแม่ข่าย
        var hIcon = L.divIcon({ className: 'sat-hosp-icon', html: '<span><i class="fa fa-h-square"></i></span>', iconSize: [30, 30], iconAnchor: [15, 15] });
        L.marker([h.lat, h.lng], { icon: hIcon, zIndexOffset: 1000 }).addTo(map)
            .bindPopup('<b>' + esc(h.name) + '</b>' + (h.approx ? '<br><small>ตำแหน่งโดยประมาณ — กด "ตั้งตำแหน่งโรงพยาบาล"</small>' : ''));
        L.circle([h.lat, h.lng], { radius: 10000, color: '#0f172a', weight: 1, dashArray: '2 6', fill: false, interactive: false }).addTo(map);

        map.on('click', function (e) {
            if (!picking) {
                return;
            }
            picking = false;
            $('#satMapEl').removeClass('is-picking');
            var ll = e.latlng;
            Flood.confirm({ title: 'ตั้งตำแหน่งโรงพยาบาล', message: 'ใช้จุด ' + ll.lat.toFixed(6) + ', ' + ll.lng.toFixed(6) + ' เป็นที่ตั้งโรงพยาบาล?' }, function () {
                return Flood.post('sat/hospitalSave', { lat: ll.lat.toFixed(7), lng: ll.lng.toFixed(7) }).then(function (o) {
                    Flood.toast(o.msg, 'success');
                    reload();
                });
            });
        });
    }
    $('#satSetHosp').on('click', function () {
        if (!map) {
            return;
        }
        picking = true;
        $('#satMapEl').addClass('is-picking');
        Flood.toast('แตะจุดที่ตั้งโรงพยาบาลบนแผนที่', 'info');
        document.getElementById('satMap').scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    /* ==================== ตัวช่วยฟอร์มในหน้าต่าง ==================== */
    function pick($m, key, val) {
        var $p = $m.find('[data-pick="' + key + '"]');
        $p.find('.sat-pick-b').removeClass('is-on');
        if (val) {
            $p.find('.sat-pick-b[data-v="' + val + '"]').addClass('is-on');
        }
        $p.data('val', val || '');
    }
    $(document).on('click', '.sat-pick-b', function () {
        var $p = $(this).closest('[data-pick]');
        pick($(this).closest('.modal'), $p.data('pick'), $(this).data('v'));
        $p.data('touched', 1);
        $p.trigger('sat:pick', [$(this).data('v')]);
    });
    function picked($m, key) {
        return String($m.find('[data-pick="' + key + '"]').data('val') || '');
    }
    function fields($m) {
        var o = {};
        $m.find('input[name],select[name],textarea[name]').each(function () {
            if (this.type === 'checkbox') {
                o[this.name] = this.checked ? '1' : '0';
            } else {
                o[this.name] = $(this).val();
            }
        });
        return o;
    }
    function save($btn, path, data, done) {
        Flood.busy($btn, true);
        Flood.post(path, data).then(function (o) {
            Flood.toast(o.msg || 'บันทึกแล้ว', 'success');
            if (done) {
                done(o);
            } else {
                reload();
            }
        }, function () { Flood.busy($btn, false); });
    }

    /* ==================== ตัวชี้วัด ==================== */
    var $im = $('#satItemModal');
    function openItem(el) {
        var d = $(el).data();
        $im.find('[data-f="name"]').text(d.name);
        $im.find('[data-f="hint"]').text(d.hint || '');
        $im.find('[name=code]').val(d.code);
        $im.find('[name=note]').val(d.note || '');
        $im.find('.js-sat-history-in').data('code', d.code).data('title', 'ประวัติ ' + d.name);
        pick($im, 'status', d.status || '');
        // ข้อมูลจากระบบ (สาธารณูปโภค / Refer / ศูนย์พักพิง ฯลฯ) — กดใช้แทนการพิมพ์เอง
        var auto = d.auto ? String(d.auto) : '';
        $im.data('auto', auto).data('autoStatus', d.autoStatus || '');
        $im.find('[data-f="autobox"]').toggleClass('hidden', !auto);
        $im.find('[data-f="auto"]').text(auto);
        $im.find('[data-f="autost"]').html(d.autoStatus ? 'ระบบแนะนำ ' + chipHtml(d.autoStatus) : '');
        $im.modal('show');
    }
    $im.on('click', '.js-sat-use-auto', function () {
        var auto = $im.data('auto') || '';
        var st = $im.data('autoStatus') || '';
        var $n = $im.find('[name=note]');
        var cur = $.trim($n.val());
        if (st) {   // ระบบสำคัญ: ใช้ค่าจากระบบแทนทั้งหมด + สีที่แนะนำ
            $n.val(auto);
            pick($im, 'status', st);
        } else {    // การ์ดอื่น: ต่อท้ายสรุปเดิม
            $n.val(cur && cur.indexOf(auto) < 0 ? cur + '\n' + auto : (cur || auto));
        }
        $n.focus();
    });

    /* ระบบสำคัญ: ตั้ง น้ำประปา / ออกซิเจน / เชื้อเพลิง ตามหน้าสาธารณูปโภคครั้งเดียว */
    $('#satUtilSync').on('click', function () {
        var items = $(this).data('items') || [];
        var html = '<p>ตั้งสีและสรุปของตัวชี้วัดต่อไปนี้ตามข้อมูลหน้าสาธารณูปโภค (บันทึกในชื่อท่าน · ค่าเดิมดูได้ที่ประวัติ)</p>'
            + '<table class="table table-condensed sat-table"><thead><tr><th></th><th>ปัจจุบัน</th><th>ใหม่</th></tr></thead><tbody>'
            + items.map(function (x) {
                return '<tr><td><b>' + esc(x.name) + '</b></td><td>' + chipHtml(x.cur_status) + ' <span class="sat-sub">' + esc(x.cur_note) + '</span></td>'
                    + '<td>' + chipHtml(x.status) + ' ' + esc(x.text) + '</td></tr>';
            }).join('') + '</tbody></table>';
        Flood.confirm({ title: 'อัปเดตจากข้อมูลสาธารณูปโภค', html: html, okText: 'อัปเดต', large: true }, function () {
            return Flood.post('sat/utilitySync', {}).then(function (o) {
                Flood.toast(o.msg || 'อัปเดตแล้ว', 'success');
                reload();
            });
        });
    });
    $(document).on('click', '.js-sat-item', function (e) {
        e.stopPropagation();
        openItem(this);
    });
    $(document).on('keydown', '.js-sat-item', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            openItem(this);
        }
    });
    $im.on('click', '[data-save="item"]', function () {
        var st = picked($im, 'status');
        if (!st) {
            Flood.toast('กรุณาเลือกสี', 'warning');
            return;
        }
        save($(this), 'sat/itemSave', { code: $im.find('[name=code]').val(), status: st, note: $im.find('[name=note]').val() });
    });

    /* ==================== ประกาศสถานะโรงพยาบาล ==================== */
    var $om = $('#satOverallModal');
    $om.find('[data-pick="status"]').on('sat:pick', function (e, v) {
        $om.find('[data-f="act"]').text((SAT.acts && SAT.acts[v]) || '');
    });
    $('.js-sat-overall').on('click', function () {
        var d = $(this).data();
        pick($om, 'status', d.status || '');
        $om.find('[data-f="act"]').text((SAT.acts && SAT.acts[d.status]) || '');
        $om.find('[name=reason]').val(d.reason || '');
        $om.modal('show');
    });
    $om.on('click', '[data-save="overall"]', function () {
        var st = picked($om, 'status');
        if (!st) {
            Flood.toast('กรุณาเลือกสถานะ', 'warning');
            return;
        }
        save($(this), 'sat/overallSave', { status: st, reason: $om.find('[name=reason]').val() });
    });

    /* ==================== ประวัติ / ดูข้อความ ==================== */
    var $vm = $('#satViewModal');
    function showView(title, html, copyText) {
        $vm.find('[data-f="title"]').text(title);
        $vm.find('[data-f="body"]').html(html);
        $vm.data('copy', copyText || '');
        $vm.find('.js-sat-copy-view').toggle(!!copyText);
        $vm.find('.js-sat-img-view').toggle(!!copyText && /SitRep/.test(copyText));
        $vm.modal('show');
    }
    function chipHtml(s, text) {
        var c = COLORS[s];
        return '<span class="sat-chip sat-c-' + (c ? esc(s) : 'none') + '">' + (c ? c.emoji + ' ' + esc(text || c.label) : '⚪ ' + esc(text || 'ไม่มี')) + '</span>';
    }
    function history(code, title) {
        Flood.get('sat/itemHistory/' + encodeURIComponent(code)).then(function (o) {
            var html = o.rows.length ? '<table class="table table-condensed sat-table"><tbody>' + o.rows.map(function (r) {
                return '<tr><td class="sat-nowrap">' + esc(r.at) + (r.src === 'auto' ? '<br><span class="sat-src-auto">ดึงประมวล</span>' : '')
                    + '</td><td>' + chipHtml(r.status) + '</td><td>' + esc(r.note).replace(/\n/g, '<br>')
                    + '</td><td class="sat-sub">' + esc(r.by) + '</td></tr>';
            }).join('') + '</tbody></table>' : '<div class="sat-muted">ยังไม่มีประวัติ</div>';
            showView(title, html);
        });
    }
    $('.js-sat-history').on('click', function () {
        history($(this).data('code'), $(this).data('title'));
    });
    $im.on('click', '.js-sat-history-in', function () {
        var d = $(this).data();
        $im.modal('hide');
        history(d.code, d.title);
    });
    $vm.on('click', '.js-sat-copy-view', function () {
        copyText($vm.data('copy'));
    });

    /* ==================== ② หน่วยบริการ ==================== */
    var $cm = $('#satCallModal');
    $('.js-sat-call').on('click', function () {
        var d = $(this).data();
        $cm.find('[data-f="name"]').text(d.name);
        $cm.find('input[type=text],textarea').val('');
        $cm.find('[name=facility_id]').val(d.id);
        $cm.find('[name=service]').val(d.service || 'open');
        $cm.find('[name=source]').val('call');
        $cm.find('[name=relocated_to]').val(d.moved || '');
        pick($cm, 'status', d.status || '');
        $cm.find('[data-pick="status"]').data('touched', 0);
        $cm.modal('show');
    });
    $cm.find('[name=service]').on('change', function () {
        // เลือกการให้บริการแล้วแนะนำสีให้ (ถ้ายังไม่ได้กดสีเอง)
        if (!$cm.find('[data-pick="status"]').data('touched')) {
            pick($cm, 'status', $(this).find(':selected').data('color'));
        }
    });
    $cm.on('click', '[data-save="call"]', function () {
        var data = fields($cm);
        data.status = picked($cm, 'status');
        if (!data.status) {
            Flood.toast('กรุณาเลือกสีสถานะ', 'warning');
            return;
        }
        save($(this), 'sat/facilityLog', data);
    });

    var $fm = $('#satFacModal');
    function loadTambons(amphoe, selected) {
        var $t = $fm.find('[name=tambon_code]').html('<option value="">–</option>');
        if (!amphoe) {
            return;
        }
        Flood.get('api/tambons', { amphoe: amphoe }, { silent: true }).then(function (o) {
            (o.tambons || []).forEach(function (t) {
                $t.append($('<option>').val(t.tambon_code).text(t.name));
            });
            if (selected) {
                $t.val(selected);
            }
        });
    }
    $fm.find('[name=amphoe_code]').on('change', function () { loadTambons($(this).val(), ''); });
    function historyTable(rows) {
        return rows.length
            ? '<table class="table table-condensed sat-table"><tbody>' + rows.map(function (r) {
                return '<tr><td class="sat-nowrap">' + esc(r.at) + '<div class="sat-sub">' + esc(r.source) + (r.by ? ' · ' + esc(r.by) : '') + '</div></td><td>'
                    + chipHtml(r.status) + '</td><td>' + esc(r.service) + (r.detail ? '<div class="sat-sub">' + esc(r.detail) + '</div>' : '') + '</td></tr>';
            }).join('') + '</tbody></table>'
            : '<div class="sat-muted">ยังไม่มีประวัติ</div>';
    }
    $(document).on('click', '.js-sat-fac-edit', function () {
        var id = +$(this).data('id') || 0;
        if (!$fm.length) {
            // ผู้ดูอย่างเดียว: แสดงประวัติผลโทร
            Flood.get('sat/facilityHistory/' + id).then(function (o) {
                showView(o.facility.name, historyTable(o.rows));
            });
            return;
        }
        $fm.find('input[type=text],input[type=hidden]').val('');
        $fm.find('[name=ftype]').val('hs');
        $fm.find('[name=amphoe_code]').val('');
        $fm.find('[name=is_active]').prop('checked', true);
        $fm.find('[data-f="history"]').html('');
        $fm.find('[data-f="title"]').text(id ? 'หน่วยบริการ' : 'เพิ่มหน่วยบริการ');
        $fm.find('[data-save="fac"]').toggle(!!SAT.canEdit);
        loadTambons('', '');
        if (!id) {
            $fm.find('[name=facility_id]').val(0);
            $fm.modal('show');
            return;
        }
        Flood.get('sat/facilityHistory/' + id).then(function (o) {
            var f = o.facility;
            $.each(f, function (k, v) {
                var $el = $fm.find('[name="' + k + '"]');
                if ($el.is(':checkbox')) {
                    $el.prop('checked', +v === 1);
                } else if (k !== 'tambon_code') {
                    $el.val(v === null ? '' : v);
                }
            });
            if (+f.loc_approx === 1) {
                $fm.find('[name=lat],[name=lng]').val('');
            }
            loadTambons(f.amphoe_code, f.tambon_code);
            $fm.find('[data-f="title"]').text(f.name);
            $fm.find('[data-f="history"]').html('<h4 class="sat-h4">ประวัติผลโทร</h4>' + historyTable(o.rows));
            $fm.modal('show');
        });
    });
    $fm.on('click', '[data-save="fac"]', function () {
        save($(this), 'sat/facilitySave', fields($fm));
    });

    var $xm = $('#satImportModal');
    $('#satFacImport').on('click', function () { $xm.modal('show'); });
    $xm.on('click', '[data-save="import"]', function () {
        var rows = $xm.find('[name=rows]').val();
        if (!$.trim(rows)) {
            Flood.toast('วางรายชื่อก่อน', 'warning');
            return;
        }
        save($(this), 'sat/facilityImport', { rows: rows }, function (o) {
            if (o.errors && o.errors.length) {
                Flood.toast(o.errors.join(' · '), 'warning', 8000);
            }
            reload();
        });
    });

    /* ==================== ③ เส้นทาง ==================== */
    var $rm = $('#satRouteModal');
    $('.js-sat-route-edit').on('click', function () {
        var d = $(this).data();
        $rm.find('[name=route_id]').val(d.id || 0);
        $rm.find('[name=rtype]').val(d.rtype || 'refer');
        $rm.find('[name=name]').val(d.name || '');
        $rm.find('[name=destination]').val(d.destination || '');
        $rm.find('[name=segments]').val(d.segments || '');
        $rm.find('[name=note]').val(d.note || '');
        $rm.find('[name=is_backup]').prop('checked', +d.backup === 1);
        $rm.find('[name=is_active]').prop('checked', true);
        $rm.modal('show');
    });
    $rm.on('click', '[data-save="route"]', function () {
        save($(this), 'sat/routeSave', fields($rm));
    });

    var $km = $('#satCheckModal');
    $('.js-sat-check').on('click', function () {
        var d = $(this).data();
        $km.find('[data-f="name"]').text(d.name);
        $km.find('[name=route_id]').val(d.id);
        $km.find('[name=note]').val('');
        // เลือกไว้ให้ตามข้อมูลแนะนำของแถว (ช่อง "ใช้ได้") — ผู้ตรวจกดเปลี่ยนได้
        var sug = String(d.suggest || '');
        pick($km, 'result', sug);
        $km.find('[data-f="suggest"]').text(sug
            ? 'เลือกไว้ให้ตามข้อมูลแนะนำ: ' + (d.suggestFrom || '') + ' — ตรวจหน้างานแล้วเปลี่ยนได้'
            : 'ยังไม่มีข้อมูลแนะนำ — เลือกผลตามที่ตรวจพบ');
        $km.modal('show');
    });
    $km.on('click', '[data-save="check"]', function () {
        var r = picked($km, 'result');
        if (!r) {
            Flood.toast('กรุณาเลือกผลการตรวจ', 'warning');
            return;
        }
        save($(this), 'sat/routeCheck', { route_id: $km.find('[name=route_id]').val(), result: r, note: $km.find('[name=note]').val() });
    });

    /* ==================== ⑤ SitRep ==================== */
    function copyText(text) {
        if (!text) {
            return;
        }
        var done = function () { Flood.toast('คัดลอกแล้ว', 'success'); };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done, function () { fallbackCopy(text, done); });
        } else {
            fallbackCopy(text, done);
        }
    }
    function fallbackCopy(text, done) {
        var $t = $('<textarea readonly>').val(text).css({ position: 'fixed', left: '-9999px' }).appendTo('body');
        $t[0].select();
        try {
            document.execCommand('copy');
            done();
        } catch (e) {
            Flood.toast('คัดลอกไม่ได้ — เลือกข้อความแล้วกด Ctrl+C', 'warning');
        }
        $t.remove();
    }
    var $sf = $('#satSitrepForm');
    function formData() {
        var o = {};
        $sf.find('[name]').each(function () { o[this.name] = $(this).val(); });
        return o;
    }
    $('#satDraft').on('click', function () {
        var $b = $(this);
        Flood.busy($b, true, 'กำลังรวบรวมข้อมูล…');
        Flood.post('sat/sitrepDraft', formData()).then(function (o) {
            Flood.busy($b, false);
            $('#satBody').val(o.text);
            $('#satNo').text(o.no);
        }, function () { Flood.busy($b, false); });
    });
    $('#satCopy').on('click', function () { copyText($('#satBody').val()); });
    $('#satSave').on('click', function () {
        var body = $.trim($('#satBody').val());
        if (body.length < 20) {
            Flood.toast('กดสร้างข้อความก่อนบันทึก', 'warning');
            return;
        }
        var d = formData();
        var m = String(d.next || '').match(/(\d{1,2})[:.](\d{2})/);
        var next = '';
        if (m) {
            var t = new Date();
            t.setHours(+m[1], +m[2], 0, 0);
            if (t.getTime() < Date.now() - 3600000) {
                t.setDate(t.getDate() + 1);
            }
            next = t.getFullYear() + '-' + ('0' + (t.getMonth() + 1)).slice(-2) + '-' + ('0' + t.getDate()).slice(-2)
                + ' ' + ('0' + t.getHours()).slice(-2) + ':' + ('0' + t.getMinutes()).slice(-2);
        }
        save($(this), 'sat/sitrepSave', { body: body, overall: d.overall, next_at: next }, function () {
            copyText(body);
            reload();
        });
    });
    $(document).on('click', '.js-sat-rep', function () {
        Flood.get('sat/sitrep/' + $(this).data('id')).then(function (o) {
            showView('SitRep ฉบับที่ ' + o.no + ' · ' + o.at + (o.by ? ' · ' + o.by : ''),
                '<pre class="sat-pre">' + esc(o.body) + '</pre>', o.body);
        });
    });

    /* ==================== SitRep เป็นภาพ (ส่งต่อผู้บริหาร / LINE) ==================== */
    // วาดด้วย canvas จากข้อความ SitRep โดยตรง — ไม่ต้องโหลดไลบรารีภายนอก
    var IMG_FONT = '"Sarabun","Noto Sans Thai","Leelawadee UI","Tahoma","Segoe UI Emoji","Apple Color Emoji","Noto Color Emoji",sans-serif';
    var IMG_STATUS = {
        red: { re: /🔴|\bRED\b|แดง/, bg: '#c62828', fg: '#fff', name: 'RED' },
        orange: { re: /🟠|\bORANGE\b|ส้ม/, bg: '#ef6c00', fg: '#fff', name: 'ORANGE' },
        yellow: { re: /🟡|\bYELLOW\b|เหลือง/, bg: '#f9c80e', fg: '#3a2e00', name: 'YELLOW' },
        green: { re: /🟢|\bGREEN\b|เขียว/, bg: '#2e7d32', fg: '#fff', name: 'GREEN' }
    };
    function imgSegments(text) {
        if (window.Intl && Intl.Segmenter) {
            try {
                var seg = new Intl.Segmenter('th', { granularity: 'word' });
                return Array.from(seg.segment(text), function (s) { return s.segment; });
            } catch (e) { /* ใช้แบบรายตัวอักษร */ }
        }
        return Array.from(text);
    }
    function imgWrap(ctx, text, maxW) {
        var out = [], cur = '';
        imgSegments(text).forEach(function (w) {
            var t = cur + w;
            if (cur !== '' && ctx.measureText(t).width > maxW) {
                out.push(cur.replace(/\s+$/, ''));
                cur = w.replace(/^\s+/, '');
                // คำเดียวยาวเกินบรรทัด — ตัดรายตัวอักษร
                while (ctx.measureText(cur).width > maxW && cur.length > 1) {
                    var i = cur.length - 1;
                    while (i > 1 && ctx.measureText(cur.slice(0, i)).width > maxW) { i--; }
                    out.push(cur.slice(0, i));
                    cur = cur.slice(i);
                }
            } else {
                cur = t;
            }
        });
        if (cur !== '' || !out.length) { out.push(cur); }
        return out;
    }
    function sitrepCanvas(text) {
        // SitRep ที่แยกเป็นตารางได้ → วาดแบบตาราง (views/flood/js/sat_sitrep_view.js) · อื่น ๆ ใช้ตัววาดเดิมด้านล่าง
        if (window.SatSitrepView && window.SatSitrepView.canvas) {
            var tcv = window.SatSitrepView.canvas(text);
            if (tcv) {
                return tcv;
            }
        }
        var W = 1080, P = 56, lines = String(text || '').replace(/\r/g, '').split('\n');
        var title = lines.shift() || 'SitRep';
        var sub = (lines[0] && /^ข้อมูล\s*ณ/.test(lines[0])) ? lines.shift() : '';
        var st = null;
        lines.forEach(function (l) {
            if (!st && /^สถานะโรงพยาบาล/.test(l)) {
                Object.keys(IMG_STATUS).some(function (k) { if (IMG_STATUS[k].re.test(l)) { st = IMG_STATUS[k]; return true; } return false; });
            }
        });
        var cv = document.createElement('canvas');
        var ctx = cv.getContext('2d');
        var F = function (size, bold) { ctx.font = (bold ? '700 ' : '400 ') + size + 'px ' + IMG_FONT; };
        // ---- จัดหน้า (รอบแรก = วัดความสูง, รอบสอง = วาด) ----
        function layout(draw) {
            var y = 0, ops = [];
            var headH = P + 50 + (sub ? 40 : 0) + 28;
            ops.push({ t: 'head', h: headH });
            y = headH + 32;
            var inBox = false;
            lines.forEach(function (raw) {
                var l = raw.replace(/\s+$/, '');
                if (l === '') { y += 18; return; }
                var indent = /^\s{2,}/.test(raw) ? 44 : 0;
                l = l.replace(/^\s+/, '');
                var size = indent ? 25 : 27, bold = false, color = indent ? '#455a64' : '#1c2733', lh = Math.round(size * 1.5);
                var isStatus = /^สถานะโรงพยาบาล/.test(l);
                var isRec = /^SAT\s*แนะนำ/.test(l);
                if (isStatus) { size = 30; bold = true; lh = 46; }
                if (/^รายงานครั้งถัดไป/.test(l)) { bold = true; color = '#1f3a5f'; }
                if (isRec) { inBox = true; y += 8; }
                F(size, bold);
                var maxW = W - P * 2 - indent - (inBox ? 40 : 0);
                // ป้ายหัวข้อ "xxx:" ตัวหนา เฉพาะบรรทัดแรกของย่อหน้า
                var m = !isStatus && !indent ? l.match(/^(.{1,40}?:)\s/) : null;
                var wrapped = imgWrap(ctx, l, maxW);
                var startY = y;
                wrapped.forEach(function (w, i) {
                    ops.push({ t: 'text', x: P + indent + (inBox ? 20 : 0), y: y, s: w, size: size, bold: bold, color: color,
                        label: (i === 0 && m && w.indexOf(m[1]) === 0) ? m[1] : '' });
                    y += lh;
                });
                if (isRec) { ops.push({ t: 'box', y: startY - 14, h: y - startY + 18 }); inBox = false; y += 14; }
            });
            y += 24;
            ops.push({ t: 'foot', y: y });
            y += 70;
            return { h: y, ops: ops };
        }
        var L = layout();
        cv.width = W;
        cv.height = L.h;
        ctx = cv.getContext('2d');
        ctx.textBaseline = 'top';
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, W, L.h);
        // กล่อง "SAT แนะนำ" วาดก่อนตัวหนังสือ
        L.ops.forEach(function (o) {
            if (o.t === 'box') {
                ctx.fillStyle = '#fff4e0';
                ctx.fillRect(P, o.y, W - P * 2, o.h);
                ctx.fillStyle = '#ef6c00';
                ctx.fillRect(P, o.y, 8, o.h);
            }
        });
        L.ops.forEach(function (o) {
            if (o.t === 'head') {
                ctx.fillStyle = '#1f3a5f';
                ctx.fillRect(0, 0, W, o.h);
                ctx.fillStyle = st ? st.bg : '#90a4ae';
                ctx.fillRect(0, o.h - 12, W, 12);
                F(40, true); ctx.fillStyle = '#ffffff';
                var tl = imgWrap(ctx, title, W - P * 2 - (st ? 230 : 0))[0];
                ctx.fillText(tl, P, P - 8);
                if (sub) { F(27, false); ctx.fillStyle = '#cfdcec'; ctx.fillText(sub, P, P + 48); }
                if (st) {
                    F(30, true);
                    var bw = ctx.measureText(st.name).width + 48;
                    var bx = W - P - bw, by = P - 10;
                    ctx.fillStyle = st.bg;
                    ctx.beginPath();
                    if (ctx.roundRect) { ctx.roundRect(bx, by, bw, 54, 27); } else { ctx.rect(bx, by, bw, 54); }
                    ctx.fill();
                    ctx.fillStyle = st.fg;
                    ctx.fillText(st.name, bx + 24, by + 9);
                }
            } else if (o.t === 'text') {
                var x = o.x, s = o.s;
                if (o.label) {
                    F(o.size, true); ctx.fillStyle = '#1f3a5f';
                    ctx.fillText(o.label, x, o.y);
                    x += ctx.measureText(o.label).width;
                    s = s.slice(o.label.length);
                }
                F(o.size, o.bold); ctx.fillStyle = o.color;
                ctx.fillText(s, x, o.y);
            } else if (o.t === 'foot') {
                ctx.fillStyle = '#e3e8ee';
                ctx.fillRect(P, o.y, W - P * 2, 2);
                F(22, false); ctx.fillStyle = '#78909c';
                var d = new Date(), pad = function (n) { return ('0' + n).slice(-2); };
                ctx.fillText('สร้างภาพจากระบบ ' + (document.title.split('·').pop() || '').trim() + ' · '
                    + pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + '/' + (d.getFullYear() + 543) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ' น.', P, o.y + 20);
            }
        });
        return cv;
    }
    function sitrepFileName(text, prefix) {
        var m = prefix ? null : String(text || '').match(/ฉบับที่\s*(\d+)/);
        var d = new Date(), pad = function (n) { return ('0' + n).slice(-2); };
        return (prefix || 'SitRep') + (m ? '-' + m[1] : '') + '-' + d.getFullYear() + pad(d.getMonth() + 1) + pad(d.getDate()) + '-' + pad(d.getHours()) + pad(d.getMinutes()) + '.png';
    }
    /* ---- จัดภาพลงกระดาษ A4 (แนวตั้ง 150 dpi = 1240×1754) — ยาวไม่มากย่อให้พอดี 1 หน้า ยาวมากแบ่งหลายหน้า ---- */
    var A4 = { W: 1240, H: 1754, side: 40, top: 40, bottom: 64, pdfW: 595.28, pdfH: 841.89 };
    function a4Break(src, from, limit, minY) {
        // จุดตัดหน้า: 1) เส้นคั่นแถวตาราง (#e5e7eb เกือบเต็มความกว้าง) หรือแถวพิกเซลสีเดียวทั้งแถว (ช่องว่างระหว่างกล่อง)
        //             2) ไม่มี → แถวที่มีจุดสีเข้มน้อยสุด (ไม่ตัดกลางตัวหนังสือ)
        var h = limit - minY;
        if (h <= 2) { return limit; }
        var data;
        try { data = src.getContext('2d').getImageData(0, minY, src.width, h).data; } catch (e) { return limit; }
        var W = src.width, best = limit, bestN = Infinity, near = function (a, b) { return Math.abs(a - b) <= 6; };
        for (var r = h - 1; r >= 0; r--) {
            var o = r * W * 4, n = 0, sep = 0, same = true, cnt = 0;
            var r0 = data[o], g0 = data[o + 1], b0 = data[o + 2];
            for (var x = 0; x < W; x += 2) {
                var i = o + x * 4, R = data[i], G = data[i + 1], B = data[i + 2];
                cnt++;
                if (R * 0.299 + G * 0.587 + B * 0.114 < 170) { n++; }
                if (near(R, 229) && near(G, 231) && near(B, 235)) { sep++; }
                if (same && !(near(R, r0) && near(G, g0) && near(B, b0))) { same = false; }
            }
            if (sep / cnt >= 0.8) { return minY + r + 1; }
            if (same) { return minY + r; }
            if (n < bestN) { bestN = n; best = minY + r; }
        }
        return best > from ? best : limit;
    }
    function a4Pages(src) {
        var cw = A4.W - A4.side * 2, ch = A4.H - A4.top - A4.bottom;
        var s = cw / src.width;
        var slices = [];
        if (src.height * s <= ch * 1.35) {
            slices.push({ y: 0, h: src.height, s: Math.min(s, ch / src.height) });
        } else {
            var per = Math.floor(ch / s), y = 0;
            while (y < src.height) {
                var end = y + per;
                if (end >= src.height) {
                    end = src.height;
                } else {
                    end = a4Break(src, y, end, end - Math.floor(per * 0.3));
                }
                slices.push({ y: y, h: end - y, s: s });
                y = end;
            }
        }
        return slices.map(function (sl, i) {
            var pg = document.createElement('canvas');
            pg.width = A4.W;
            pg.height = A4.H;
            var c = pg.getContext('2d');
            c.fillStyle = '#ffffff';
            c.fillRect(0, 0, A4.W, A4.H);
            var dw = src.width * sl.s, dh = sl.h * sl.s;
            c.imageSmoothingQuality = 'high';
            c.drawImage(src, 0, sl.y, src.width, sl.h, (A4.W - dw) / 2, A4.top, dw, dh);
            if (slices.length > 1) {
                c.font = '400 20px ' + IMG_FONT;
                c.fillStyle = '#90a4ae';
                c.textAlign = 'right';
                c.textBaseline = 'alphabetic';
                c.fillText('หน้า ' + (i + 1) + '/' + slices.length, A4.W - A4.side, A4.H - 28);
            }
            return pg;
        });
    }
    function a4Pdf(pages) {
        // PDF ขนาด A4 จากภาพ JPEG หน้าละ 1 ภาพ (เขียนเอง ไม่ใช้ไลบรารี)
        var enc = function (s) { var a = new Uint8Array(s.length); for (var i = 0; i < s.length; i++) { a[i] = s.charCodeAt(i) & 255; } return a; };
        var parts = [], len = 0, offs = [];
        var push = function (u) { parts.push(u); len += u.length; };
        var obj = function (n, body) { offs[n] = len; push(enc(n + ' 0 obj\n')); body.forEach(push); push(enc('\nendobj\n')); };
        push(enc('%PDF-1.4\n%\xE2\xE3\xCF\xD3\n'));
        var n = pages.length, kids = [];
        for (var i = 0; i < n; i++) { kids.push((3 + i * 3) + ' 0 R'); }
        obj(1, [enc('<< /Type /Catalog /Pages 2 0 R >>')]);
        obj(2, [enc('<< /Type /Pages /Kids [' + kids.join(' ') + '] /Count ' + n + ' >>')]);
        pages.forEach(function (pg, i) {
            var p = 3 + i * 3;
            var b64 = pg.toDataURL('image/jpeg', 0.92).split(',')[1];
            var bin = atob(b64), jpg = new Uint8Array(bin.length);
            for (var k = 0; k < bin.length; k++) { jpg[k] = bin.charCodeAt(k); }
            var cs = 'q ' + A4.pdfW + ' 0 0 ' + A4.pdfH + ' 0 0 cm /Im0 Do Q';
            obj(p, [enc('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' + A4.pdfW + ' ' + A4.pdfH + '] /Resources << /XObject << /Im0 ' + (p + 2) + ' 0 R >> >> /Contents ' + (p + 1) + ' 0 R >>')]);
            obj(p + 1, [enc('<< /Length ' + cs.length + ' >>\nstream\n' + cs + '\nendstream')]);
            obj(p + 2, [enc('<< /Type /XObject /Subtype /Image /Width ' + pg.width + ' /Height ' + pg.height + ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' + jpg.length + ' >>\nstream\n'), jpg, enc('\nendstream')]);
        });
        var total = 3 + n * 3, xref = len;
        var x = 'xref\n0 ' + total + '\n0000000000 65535 f \n';
        for (var j = 1; j < total; j++) { x += ('0000000000' + offs[j]).slice(-10) + ' 00000 n \n'; }
        push(enc(x + 'trailer\n<< /Size ' + total + ' /Root 1 0 R >>\nstartxref\n' + xref + '\n%%EOF'));
        return new Blob(parts, { type: 'application/pdf' });
    }
    function dlUrl(url, name) {
        var a = document.createElement('a');
        a.href = url;
        a.download = name;
        document.body.appendChild(a);
        a.click();
        setTimeout(function () { a.remove(); }, 100);
    }
    var $imgM = null;
    function showSitrepImage(text, prefix) {
        if (!text || $.trim(text).length < 10) {
            Flood.toast('ยังไม่มีข้อความ SitRep', 'warning');
            return;
        }
        var go = function () {
            var cv;
            try {
                cv = sitrepCanvas(text);
            } catch (e) {
                Flood.toast('สร้างภาพไม่ได้ในเบราว์เซอร์นี้', 'danger');
                return;
            }
            var name = sitrepFileName(text, prefix);
            if (!$imgM) {
                $imgM = $('<div class="modal fade" id="satImgModal" tabindex="-1" role="dialog"><div class="modal-dialog modal-lg" role="document"><div class="modal-content">'
                    + '<div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="ปิด"><span>&times;</span></button>'
                    + '<h4 class="modal-title"><i class="fa fa-picture-o"></i> ภาพสำหรับส่งต่อผู้บริหาร <small data-f="info"></small></h4></div>'
                    + '<div class="modal-body" data-f="pages" style="background:#eef2f6;text-align:center;max-height:70vh;overflow:auto"></div>'
                    + '<div class="modal-footer">'
                    + '<button type="button" class="btn btn-default js-img-share" style="display:none"><i class="fa fa-share-alt"></i> แชร์ (LINE ฯลฯ)</button>'
                    + '<button type="button" class="btn btn-default js-img-copy" style="display:none"><i class="fa fa-clipboard"></i> คัดลอกภาพ</button>'
                    + '<button type="button" class="btn btn-default js-img-dl"><i class="fa fa-file-image-o"></i> ดาวน์โหลด PNG</button>'
                    + '<button type="button" class="btn btn-primary js-img-pdf"><i class="fa fa-file-pdf-o"></i> ดาวน์โหลด PDF (A4)</button>'
                    + '<button type="button" class="btn btn-default" data-dismiss="modal">ปิด</button></div>'
                    + '</div></div></div>').appendTo('body');
            }
            var pages = a4Pages(cv);
            var multi = pages.length > 1;
            var base = name.replace(/\.png$/, '');
            var urls = pages.map(function (pg) { return pg.toDataURL('image/png'); });
            var pngName = function (i) { return base + (multi ? '-p' + (i + 1) : '') + '-A4.png'; };
            $imgM.find('[data-f="info"]').text('· A4' + (multi ? ' ' + pages.length + ' หน้า' : ''));
            $imgM.find('[data-f="pages"]').html(urls.map(function (u, i) {
                return '<img src="' + u + '" alt="หน้า ' + (i + 1) + '" style="display:block;margin:0 auto 14px;max-width:100%;background:#fff;box-shadow:0 2px 12px rgba(0,0,0,.18)">';
            }).join(''));
            $imgM.find('.js-img-dl').html('<i class="fa fa-file-image-o"></i> ดาวน์โหลด PNG' + (multi ? ' (' + pages.length + ' ไฟล์)' : ''))
                .off('click').on('click', function () {
                    urls.forEach(function (u, i) { setTimeout(function () { dlUrl(u, pngName(i)); }, i * 400); });
                });
            $imgM.find('.js-img-pdf').off('click').on('click', function () {
                var url;
                try {
                    url = URL.createObjectURL(a4Pdf(pages));
                } catch (e) {
                    Flood.toast('สร้าง PDF ไม่ได้ — ใช้ปุ่ม PNG แทน', 'warning');
                    return;
                }
                dlUrl(url, base + '-A4.pdf');
                setTimeout(function () { URL.revokeObjectURL(url); }, 5000);
            });
            var canCopy = !multi && !!(window.ClipboardItem && navigator.clipboard && navigator.clipboard.write && window.isSecureContext);
            $imgM.find('.js-img-copy').toggle(canCopy);
            $imgM.find('.js-img-share').hide();
            Promise.all(pages.map(function (pg) {
                return new Promise(function (res) { pg.toBlob(res, 'image/png'); });
            })).then(function (blobs) {
                var files = null;
                try { files = blobs.map(function (b, i) { return new File([b], pngName(i), { type: 'image/png' }); }); } catch (e) { files = null; }
                var canShare = !!(files && navigator.canShare && navigator.canShare({ files: files }));
                $imgM.find('.js-img-share').toggle(canShare).off('click').on('click', function () {
                    navigator.share({ files: files, title: base }).catch(function () { /* ผู้ใช้ยกเลิก */ });
                });
                $imgM.find('.js-img-copy').off('click').on('click', function () {
                    navigator.clipboard.write([new ClipboardItem({ 'image/png': blobs[0] })]).then(function () {
                        Flood.toast('คัดลอกภาพแล้ว — วางในแชต LINE ได้เลย', 'success');
                    }, function () { Flood.toast('คัดลอกภาพไม่ได้ — ใช้ปุ่มดาวน์โหลดแทน', 'warning'); });
                });
            });
            $imgM.modal('show');
        };
        // รอฟอนต์ไทยของหน้าโหลดเสร็จ ภาพจะได้ไม่ใช้ฟอนต์สำรอง
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(go, go);
        } else {
            go();
        }
    }
    $('#satImg').on('click', function () { showSitrepImage($('#satBody').val()); });
    $vm.on('click', '.js-sat-img-view', function () {
        var t = $vm.data('copy');
        $vm.one('hidden.bs.modal', function () { showSitrepImage(t); });
        $vm.modal('hide');
    });

    /* ภาพ "สถานะโรงพยาบาลที่ SAT ประกาศ" + "ระบบประเมินจากข้อมูล" — ประกอบข้อความจากกล่องบนหน้า แล้ววาดด้วยตัววาด SitRep */
    function overallText($btn) {
        var $o = $btn.closest('.sat-overall');
        var clean = function ($e) { return $.trim(($e.text() || '').replace(/\s+/g, ' ')); };
        var emo = function (cls) {
            var m = String(cls || '').match(/sat-c-(\w+)/);
            return m && COLORS[m[1]] ? COLORS[m[1]].emoji : '⚪';
        };
        var d = new Date(), pad = function (n) { return ('0' + n).slice(-2); };
        var L = [];
        L.push('🏥 สถานะโรงพยาบาล · SAT ' + ($btn.data('hosp') || ''));
        L.push('ข้อมูล ณ ' + pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + '/' + (d.getFullYear() + 543) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ' น.');
        L.push('');
        var $v = $o.find('.sat-overall-main .sat-overall-value');
        L.push('สถานะโรงพยาบาล (SAT ประกาศ): ' + clean($v));
        var meta = clean($o.find('.sat-overall-main .sat-overall-meta'));
        if (meta) { L.push('  ' + meta); }
        var reason = clean($o.find('.sat-overall-main .sat-overall-reason'));
        if (reason) { L.push('เหตุผล: ' + reason); }
        L.push('');
        var sug = $o.find('.sat-sug .sat-chip');
        L.push('ระบบประเมินจากข้อมูล: ' + (sug.length ? clean(sug) : '-'));
        var $li = $o.find('.sat-reasons li');
        if ($li.length) {
            $li.each(function () {
                L.push(emo($(this).find('.sat-dot').attr('class')) + ' ' + clean($(this)));
            });
        } else {
            var none = clean($o.find('.sat-overall-side > .sat-muted'));
            if (none) { L.push(none); }
        }
        var act = clean($o.find('.sat-overall-main .sat-overall-act'));
        if (act) {
            L.push('');
            L.push('SAT แนะนำ: ' + act);
        }
        return L.join('\n');
    }
    $('.js-sat-overall-img').on('click', function () {
        showSitrepImage(overallText($(this)), 'SAT-status');
    });
});
