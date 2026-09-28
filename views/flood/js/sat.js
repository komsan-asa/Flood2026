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
        $im.modal('show');
    }
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
        $vm.modal('show');
    }
    function chipHtml(s, text) {
        var c = COLORS[s];
        return '<span class="sat-chip sat-c-' + (c ? esc(s) : 'none') + '">' + (c ? c.emoji + ' ' + esc(text || c.label) : '⚪ ' + esc(text || 'ไม่มี')) + '</span>';
    }
    function history(code, title) {
        Flood.get('sat/itemHistory/' + encodeURIComponent(code)).then(function (o) {
            var html = o.rows.length ? '<table class="table table-condensed sat-table"><tbody>' + o.rows.map(function (r) {
                return '<tr><td class="sat-nowrap">' + esc(r.at) + '</td><td>' + chipHtml(r.status) + '</td><td>' + esc(r.note).replace(/\n/g, '<br>')
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
        pick($km, 'result', '');
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
});
