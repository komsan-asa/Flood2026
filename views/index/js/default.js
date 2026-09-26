/* หน้าแผนที่สถานการณ์น้ำ (สาธารณะ) — รีเฟรชข้อมูลเองทุก 2 นาที
 * แตะพื้นที่ (บนแผนที่หรือในรายการ) → แผงข้อมูลเปลี่ยนเป็น "รายละเอียดพื้นที่" (มือถือ = แผ่นเลื่อนด้านล่าง)
 * สรุปรายงานประชาชนเป็นหัวข้อ (ระดับน้ำ / รถผ่านได้ไหม / แนวโน้ม ...) + รายงานทีละจุดพร้อมลิงก์โพสต์ต้นทาง */
$(function () {
    'use strict';

    var data = window.PUBLIC_DATA || { zones: [], counts: {}, points: [] };
    data.points = data.points || [];
    var esc = Flood.esc;
    var isMobile = function () { return window.innerWidth < 768; };
    var map = FloodMap.create('pubMap', { scrollWheelZoom: true });
    var layerGroup = L.featureGroup().addTo(map);
    // จุดที่ประชาชนแจ้ง (ยืนยันแล้ว) — วาดทับพื้นที่ แยก pane ให้อยู่ด้านบนเสมอ
    map.createPane('skPoints');
    map.getPane('skPoints').style.zIndex = 450;
    var pointGroup = L.featureGroup().addTo(map);
    var layersById = {};
    var markersById = {};
    var levelFilter = '';
    var amphoeFilter = '';
    var amphoeName = '';
    var provinceFilter = '';
    var provinceName = '';
    var regionFilter = '';
    var regionLabel = '';
    var areaName = data.area || 'ประเทศไทย';   // พื้นที่ทั้งหมดของระบบ
    /** ขอบเขตที่กำลังดู — ใช้ในข้อความ "ภาพรวม..." / "ยังไม่มีพื้นที่ประกาศ..." */
    function scopeName(prefix) {
        var s = amphoeName ? 'อ.' + amphoeName : (provinceName ? 'จ.' + provinceName : (regionLabel || 'ทั้ง' + areaName));
        return prefix ? prefix + (amphoeName || provinceName || regionLabel ? ' ' : '') + s : s;
    }
    function provinceInfo(code) {
        return (data.provinces || []).filter(function (p) { return p.code === code; })[0] || null;
    }
    function regionInfo(code) {
        return (data.regions || []).filter(function (r) { return r.code === code; })[0] || null;
    }
    var firstFit = true;
    var $panel = $('#pubPanel');
    var $main = $panel.children('.sk-panel-scroll').first();
    // รายละเอียดพื้นที่ใช้แผงเดียวกับรายการ — สลับกันแสดง
    var $detail = $('<div class="sk-panel-scroll sk-detail" id="pubDetail" role="region" aria-label="รายละเอียดพื้นที่" hidden></div>')
        .appendTo($panel);
    var detailId = null;       // zone_id ที่เปิดดูอยู่
    var detailAll = false;     // กด "ดูอีก N จุด" แล้ว
    var detailSig = '';        // ข้อมูลที่แสดงอยู่ — รีเฟรชแล้วไม่เปลี่ยนก็ไม่ต้องวาดใหม่
    var listTop = 0;           // ตำแหน่งเลื่อนของรายการก่อนเปิดรายละเอียด
    var detailPushed = false;  // เพิ่มประวัติเบราว์เซอร์ไว้ — ปุ่มย้อนกลับของมือถือ = กลับไปรายการ
    var skipPopAt = 0;         // เวลาที่ย้อนประวัติเอง (ปุ่ม "รายการพื้นที่") — popstate ที่ตามมาไม่ต้องทำซ้ำ
    var SHEET_MS = 320;        // แผ่นเลื่อนบนมือถือย่อ/ขยายเสร็จ (transition .3s)
    var MAX_REPORTS = 5;       // รายงานทีละจุดที่แสดงก่อนกด "ดูอีก"

    map.zoomControl.setPosition('topright'); // ใต้กล่องคำอธิบายสี (แผงข้อมูลบังด้านซ้าย)

    function icon(path) {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"'
            + ' stroke-linejoin="round" aria-hidden="true">' + path + '</svg>';
    }
    var IC = {
        back: icon('<path d="M15 18l-6-6 6-6"/>'),
        go: icon('<path d="M9 6l6 6-6 6"/>'),
        target: icon('<circle cx="12" cy="12" r="7"/><circle cx="12" cy="12" r="2.5"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/>'),
        pin: icon('<path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>')
    };

    (function legend() {
        var lv = FloodMap.config.levels || {};
        var html = '<b style="color:var(--sk-ink)">ระดับพื้นที่</b>';
        Object.keys(lv).sort(function (a, b) { return lv[a].order - lv[b].order; }).forEach(function (c) {
            html += '<span><span class="sw" style="background:' + esc(lv[c].color) + '"></span>' + esc(lv[c].name) + '</span>';
        });
        html += '<span style="margin-top:4px"><span class="sk-pt-sw"></span>จุดที่ประชาชนแจ้ง (ยืนยันแล้ว)</span>';
        html += '<span><span class="sk-pt-sw sk-pt-pending"></span>จุดแจ้งใหม่ · รอตรวจสอบ</span>';
        $('#pubLegend').html(html);
    })();

    // ปุ่มซูมอยู่ใต้กล่องคำอธิบายสี — จำนวนระดับเปลี่ยนได้ (ผู้ดูแลเพิ่มเองที่หน้า "ระดับพื้นที่")
    // จึงวางตามความสูงจริงของกล่อง แทนการตั้งระยะตายตัว
    function placeZoom() {
        var lg = document.getElementById('pubLegend');
        var $corner = $('#pubMap .leaflet-top.leaflet-right');
        if (lg && lg.getClientRects().length) {
            $corner.css('top', Math.round(lg.getBoundingClientRect().bottom + 12) + 'px');
        } else {
            // มือถือซ่อนกล่องนี้ (และปุ่มซูม) — ปุ่ม "ดูทั้งหมด" อยู่ใต้แถบบน
            // แถบบนสูงไม่เท่ากัน (จอแคบชื่อจังหวัดขึ้นบรรทัดใหม่) จึงวัดจากขอบล่างจริงของแถบ
            var top = document.querySelector('.sk-top');
            $corner.css('top', top ? Math.round(top.getBoundingClientRect().bottom - 4) + 'px' : '');
        }
    }

    /** พื้นที่ที่มองเห็นไม่โดนแผงข้อมูลบัง — ใช้ตอนซูมไปหาพื้นที่ */
    function fitPadding() {
        if (isMobile()) {
            return { paddingTopLeft: [20, 90], paddingBottomRight: [20, $panel.outerHeight() + 20] };
        }
        return { paddingTopLeft: [$panel.outerWidth() + 40, 110], paddingBottomRight: [40, 40] };
    }

    function fitAll() {
        var layers = layerGroup.getLayers().concat(pointGroup.getLayers());
        var pad = fitPadding();
        if (layers.length) {
            map.fitBounds(L.featureGroup(layers).getBounds(), $.extend({ maxZoom: 14 }, pad));
        } else {
            // ยังไม่มีพื้นที่ — จัดจังหวัดที่เลือก (หรือทั้งภูมิภาค) ให้อยู่ในส่วนที่มองเห็น
            var p = provinceFilter ? provinceInfo(provinceFilter) : null;
            var inRg = regionFilter ? (data.provinces || []).filter(function (x) { return x.region === regionFilter && x.lat; }) : [];
            if (p && p.lat) {
                map.fitBounds(L.latLng(p.lat, p.lng).toBounds(90000), pad);
            } else if (inRg.length) {
                // ทั้งภาค: กรอบครอบจุดกลางทุกจังหวัดในภาค
                map.fitBounds(L.latLngBounds(inRg.map(function (x) { return [x.lat, x.lng]; })).pad(0.15), pad);
            } else {
                map.setView(FloodMap.config.center, FloodMap.config.zoom);
            }
        }
    }

    /** มือถือ: ขยาย/ย่อแผ่นเลื่อนด้านล่าง */
    function setSheet(open) {
        $panel.toggleClass('open', open);
        $('#pubHandle').attr('aria-expanded', String(open));
    }

    /** มือถือที่ดึงแผ่นเลื่อนขึ้นเต็มจออยู่ — ย่อลงก่อนให้เห็นแผนที่ คืนเวลาที่ต้องรอ (ms) ก่อนเลื่อนแผนที่ */
    function revealMap() {
        if (isMobile() && $panel.hasClass('open')) {
            setSheet(false);
            return SHEET_MS;
        }
        return 0;
    }

    // ปุ่ม "ดูทั้งหมด" — กลับมาเห็นพื้นที่ประกาศทุกแห่ง หลังแตะดูพื้นที่ใดพื้นที่หนึ่งหรือซูมเอง
    // (มือถือไม่มีปุ่มซูม จึงสำคัญ) อยู่มุมเดียวกับปุ่มซูม ต่อท้ายใต้ปุ่มซูม
    var FitAllControl = L.Control.extend({
        options: { position: 'topright' },
        onAdd: function () {
            var b = L.DomUtil.create('button', 'sk-fitall sk-glass');
            b.type = 'button';
            b.title = 'ดูพื้นที่ประกาศทั้งหมด';
            b.setAttribute('aria-label', 'ดูพื้นที่ประกาศทั้งหมดบนแผนที่');
            b.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"'
                + ' stroke-linejoin="round" aria-hidden="true"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/></svg><span>ดูทั้งหมด</span>';
            L.DomEvent.disableClickPropagation(b);
            L.DomEvent.on(b, 'click', function (e) {
                L.DomEvent.preventDefault(e);
                map.closePopup();
                setTimeout(fitAll, revealMap());
            });
            return b;
        }
    });
    new FitAllControl().addTo(map);

    placeZoom();
    // สลับหน้าจอมือถือ ↔ คอมพิวเตอร์ (หมุนจอ/ย่อหน้าต่าง) — แผงข้อมูลย้ายที่ จัดแผนที่ใหม่ให้เห็นทุกพื้นที่
    var wasMobile = isMobile();
    $(window).on('resize', function () {
        placeZoom();
        if (isMobile() !== wasMobile) {
            wasMobile = isMobile();
            setTimeout(fitAll, 350);   // รอแผงเปลี่ยนขนาดเสร็จ (transition .3s)
        }
    });

    function inFilter(level) {
        return !levelFilter || level === levelFilter;
    }

    /* ค้นหาข้อความ (ชื่อพื้นที่ / ข้อความประกาศ / ตำบล / อำเภอ) — กรองในเครื่อง ไม่ต้องโหลดใหม่ */
    var textFilter = '';
    function norm(t) {
        return String(t || '').toLowerCase().replace(/\s+/g, '');
    }
    function matchText(z) {
        if (!textFilter) {
            return true;
        }
        return norm([z.name, z.note, z.tambon_name, z.amphoe_name].join(' ')).indexOf(textFilter) >= 0;
    }
    function zoneShown(z) {
        return inFilter(z.level) && matchText(z);
    }

    /** พื้นที่ในรายการ — ตามตัวกรองระดับ */
    function listZones() {
        return data.zones.filter(zoneShown);
    }

    /** พื้นที่บนแผนที่ — ตามตัวกรอง + พื้นที่ที่เปิดรายละเอียดอยู่เสมอ (เช่น รีเฟรชแล้วเปลี่ยนระดับจนไม่ตรงตัวกรอง) */
    function visibleZones() {
        return data.zones.filter(function (z) { return zoneShown(z) || +z.zone_id === detailId; });
    }

    function visiblePoints() {
        // จุดรอตรวจสอบยังไม่มีระดับ — ไม่แสดงตอนกรองระดับ · จุดของพื้นที่ที่เปิดดูอยู่แสดงเสมอ
        return data.points.filter(function (p) {
            if (+p.zone_id && +p.zone_id === detailId) {
                return true;
            }
            if (textFilter) {
                // ค้นหาอยู่ — แสดงเฉพาะจุดของพื้นที่ที่ตรงคำค้น
                var z = p.pending ? null : zoneById(p.zone_id);
                return !!z && zoneShown(z);
            }
            return !levelFilter || (!p.pending && p.level === levelFilter);
        });
    }

    function zoneById(id) {
        id = +id;
        for (var i = 0; i < data.zones.length; i++) {
            if (+data.zones[i].zone_id === id) {
                return data.zones[i];
            }
        }
        return null;
    }

    /** จุดที่แจ้งในพื้นที่นี้ — ใหม่สุดก่อน (api เรียงมาให้แล้ว) */
    function zonePoints(id) {
        return data.points.filter(function (p) { return !p.pending && +p.zone_id === +id; });
    }

    function fbLink(url, text) {
        return '<a class="sk-fb-link" href="' + esc(url) + '" target="_blank" rel="noopener noreferrer nofollow">'
            + '<i class="fa fa-facebook-square" aria-hidden="true"></i> ' + esc(text) + '</a>';
    }

    function pointPopup(p) {
        if (p.pending) {
            return '<div class="sk-pt-pop"><b>จุดน้ำท่วมที่ประชาชนแจ้ง</b>'
                + '<div class="row"><span class="sk-tag-pending">⏳ รอตรวจสอบ</span></div>'
                + (p.depth ? '<div class="row">💧 น้ำ' + esc(p.depth) + (p.extent ? ' · ' + esc(p.extent) : '') + '</div>' : '')
                + (p.vehicle ? '<div class="row">🚗 ' + esc(p.vehicle) + '</div>' : '')
                + (p.trend ? '<div class="row">📈 ' + esc(p.trend) + '</div>' : '')
                + (p.impacts && p.impacts.length ? '<div>' + p.impacts.map(function (x) { return '<span class="imp">' + esc(x) + '</span>'; }).join('') + '</div>' : '')
                + '<div class="muted">ข้อมูลจากผู้แจ้ง เจ้าหน้าที่ยังไม่ได้ตรวจสอบ</div>'
                + '<div class="muted">แจ้งเมื่อ ' + esc(p.time_th) + ' (' + esc(p.ago) + ')'
                + (p.approx ? ' · ตำแหน่งโดยประมาณ' : '') + '</div>'
                + (p.fb_url ? '<a class="sk-fb-link" href="' + esc(p.fb_url) + '" target="_blank" rel="noopener noreferrer nofollow">'
                    + '<i class="fa fa-facebook-square" aria-hidden="true"></i> ดูโพสต์ต้นทางบน Facebook</a>' : '')
                + '</div>';
        }
        var lv = FloodMap.level(p.level);
        // เปิดรายละเอียดพื้นที่นี้อยู่แล้ว ไม่ต้องมีปุ่มซ้ำ
        var act = (detailId === +p.zone_id ? '' : '<button type="button" class="sk-pp-zone" data-zone="' + esc(p.zone_id) + '">'
                + 'ดูรายละเอียดพื้นที่' + IC.go + '</button>')
            + (p.fb_url ? fbLink(p.fb_url, 'ดูโพสต์ต้นทางบน Facebook') : '');
        return '<div class="sk-pt-pop"><b>จุดน้ำท่วมที่ประชาชนแจ้ง</b>'
            + '<div class="row"><span class="lv-badge lv-' + esc(p.level) + '" style="background:' + esc(lv.color) + '">' + esc(lv.name) + '</span></div>'
            + (p.depth ? '<div class="row">💧 น้ำ' + esc(p.depth) + (p.extent ? ' · ' + esc(p.extent) : '') + '</div>' : '')
            + (p.vehicle ? '<div class="row">🚗 ' + esc(p.vehicle) + '</div>' : '')
            + (p.trend ? '<div class="row">📈 ' + esc(p.trend) + '</div>' : '')
            + (p.impacts && p.impacts.length ? '<div>' + p.impacts.map(function (x) { return '<span class="imp">' + esc(x) + '</span>'; }).join('') + '</div>' : '')
            + '<div class="muted">อยู่ในพื้นที่ "' + esc(p.zone_name) + '"</div>'
            + '<div class="muted">แจ้งเมื่อ ' + esc(p.time_th) + ' (' + esc(p.ago) + ')'
            + (p.approx ? ' · ตำแหน่งโดยประมาณ' : '') + '</div>'
            + (act ? '<div class="sk-pp-act">' + act + '</div>' : '')
            + '</div>';
    }

    /** เปิด/ปิดรายละเอียดพื้นที่ตอนบอลลูนจุดเปิดค้างอยู่ — วาดเนื้อหาใหม่ (ปุ่ม "ดูรายละเอียดพื้นที่" ขึ้น/หาย) */
    function refreshPointPopup() {
        Object.keys(markersById).forEach(function (id) {
            if (markersById[id].isPopupOpen()) {
                markersById[id].getPopup().update();
            }
        });
    }

    function renderPoints() {
        pointGroup.clearLayers();
        markersById = {};
        visiblePoints().forEach(function (p) {
            if (p.pending) {
                L.circleMarker([p.lat, p.lng], {
                    pane: 'skPoints', radius: 6, weight: 2, color: '#b45309', fillColor: '#fcd34d', fillOpacity: .9,
                    dashArray: p.approx ? '3 3' : null
                }).bindPopup(pointPopup(p), { maxWidth: 260 }).addTo(pointGroup);
                return;
            }
            var c = FloodMap.level(p.level).color;
            markersById[p.id] = L.circleMarker([p.lat, p.lng], {
                pane: 'skPoints', radius: p.approx ? 6 : 7, weight: 3, color: c, fillColor: '#fff', fillOpacity: 1,
                dashArray: p.approx ? '3 3' : null
            }).bindPopup(function () { return pointPopup(p); }, { maxWidth: 260 }).addTo(pointGroup);
        });
        $('#pubPoints').text(data.points.filter(function (p) { return !p.pending; }).length);
        var nPend = data.points.filter(function (p) { return p.pending; }).length;
        $('#pubPending').text(nPend).closest('.sk-pend-wrap').prop('hidden', !nPend);
    }

    // นับผู้เข้าชม + คนที่กำลังดู: เปิดหน้า = hit=1 แล้วส่งสัญญาณทุก 1 นาทีขณะแท็บเปิดอยู่
    function visitPing(hit) {
        if (!window.fetch) {
            return;
        }
        fetch(Flood.url('api/visit') + (hit ? '?hit=1' : ''), { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (o) {
                if (!o || !o.chk) {
                    return;
                }
                $('[data-visit]').each(function () {
                    var k = $(this).data('visit');
                    if (o[k] !== undefined) {
                        $(this).text(Number(o[k]).toLocaleString('th-TH'));
                    }
                });
                $('#pubVisit, #pubOnlineMini').prop('hidden', false);
            })
            .catch(function () { /* เงียบไว้ — ไม่กระทบแผนที่ */ });
    }
    visitPing(true);
    setInterval(function () {
        if (!document.hidden) {
            visitPing(false);
        }
    }, 60000);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            visitPing(false);
        }
    });

    // ปุ่ม "ข้อมูลที่ควรรู้" บนแผนที่ → เปิดแผงข้อมูลแล้วเลื่อนไปที่หัวข้อนั้น
    $('#pubNewsBtn').on('click', function () {
        var sec = document.querySelector('.sk-news');
        if (!sec) {
            return;
        }
        closeDetail({ focus: false });   // เปิดรายละเอียดพื้นที่อยู่ — กลับไปรายการก่อน (หัวข้อนี้อยู่ในรายการ)
        if (isMobile()) {
            $panel.addClass('open');
            $('#pubHandle').attr('aria-expanded', 'true');
        }
        setTimeout(function () {
            var sc = document.querySelector('#pubPanel .sk-panel-scroll');
            if (sc) {
                sc.scrollTo({ top: sec.offsetTop - 12, behavior: 'smooth' });
            }
            var first = sec.querySelector('details');
            if (first) {
                first.open = true;
            }
        }, isMobile() ? 320 : 0);
    });

    /** บอลลูนไม่ให้หลบไปอยู่ใต้แถบบนหรือแผงข้อมูล */
    map.on('popupopen', function (e) {
        var o = e.popup.options;
        if (o.autoPan === false) {
            return;   // บอลลูนที่มีภาพ flood-map.js จัดตำแหน่งเอง
        }
        var top = document.querySelector('.sk-top');
        var topPad = top ? Math.round(top.getBoundingClientRect().bottom) + 12 : 20;
        var panelRect = $panel[0] ? $panel[0].getBoundingClientRect() : null;
        o.autoPanPaddingTopLeft = L.point(isMobile() ? 10 : (panelRect ? Math.round(panelRect.right) + 12 : 20), topPad);
        o.autoPanPaddingBottomRight = L.point(10, isMobile() && panelRect ? Math.round(window.innerHeight - panelRect.top) + 12 : 20);
        e.popup._adjustPan && e.popup._adjustPan();
    });

    // เมาส์ชี้พื้นที่ (คอมพิวเตอร์) → บอกชื่อ ให้รู้ว่าแตะดูรายละเอียดได้ · จอสัมผัสไม่ใช้ (ป้ายจะค้าง)
    var canHover = !!(window.matchMedia && window.matchMedia('(hover: hover)').matches);

    function renderMap() {
        renderPoints();
        layerGroup.clearLayers();
        layersById = {};
        visibleZones().slice().sort(function (a, b) {
            return FloodMap.level(b.level).order - FloodMap.level(a.level).order;
        }).forEach(function (z) {
            // พื้นที่ไม่ใช้บอลลูน — แตะแล้วเปิดรายละเอียดในแผงข้อมูล (ที่พอแสดงทุกจุดที่แจ้ง อ่านง่ายกว่า)
            var layer = FloodMap.zoneLayer(z, { popup: false });
            layer.on('click', function () { openDetail(z.zone_id); });
            if (canHover) {
                layer.bindTooltip(esc(z.name) + ' <small>· คลิกดูรายละเอียด</small>', { sticky: true, direction: 'top', className: 'sk-zone-tip' });
            }
            layer.addTo(layerGroup);
            layersById[z.zone_id] = layer;
        });
        styleZones();
        if (firstFit) {
            fitAll();
            firstFit = false;
        }
    }

    /** พื้นที่ที่เปิดดูรายละเอียดอยู่ — เส้นหนาขึ้น · พื้นที่อื่นจางลงให้เห็นว่ากำลังดูที่ไหน */
    function styleZones() {
        Object.keys(layersById).forEach(function (id) {
            var z = zoneById(id);
            if (!z) {
                return;
            }
            var extra = null;
            if (detailId !== null) {
                extra = +id === detailId ? { weight: 4, fillOpacity: 0.4 } : { opacity: 0.45, fillOpacity: 0.1 };
            }
            layersById[id].setStyle(FloodMap.zoneStyle(z.level, extra));
        });
    }

    function renderList() {
        var zs = listZones();
        var $list = $('#pubList').empty();
        if (!zs.length) {
            var where = esc(scopeName(''));
            $list.append('<div class="sk-empty"><div class="sk-empty-ok"><i class="fa fa-check"></i></div>'
                + (data.zones.length
                    ? '<b>ไม่มีพื้นที่ในระดับที่เลือก</b><small>ลองล้างตัวกรองเพื่อดูทั้งหมด</small>'
                    : '<b>ยังไม่มีพื้นที่ประกาศ</b><small>' + where + ' ขณะนี้ยังไม่มีประกาศจากเจ้าหน้าที่</small>')
                + '</div>');
            return;
        }
        var perZone = {};
        data.points.forEach(function (p) { perZone[p.zone_id] = (perZone[p.zone_id] || 0) + 1; });
        zs.forEach(function (z) {
            var nPt = perZone[z.zone_id] || 0;
            var area = esc(FloodMap.areaLabel(z));
            var lv = FloodMap.level(z.level);
            $list.append(
                '<button type="button" class="sk-zone lv-' + esc(z.level) + '" data-id="' + esc(z.zone_id) + '">'
                + '<span class="sk-zone-dot" aria-hidden="true"></span>'
                + '<span class="sk-zone-body"><span class="sk-zone-name">' + esc(z.name) + FloodMap.pendingTag(z) + '</span>'
                + '<span class="sk-zone-meta">' + (area ? area + ' · ' : '') + 'ประกาศ ' + esc(z.started_th || '')
                + (nPt ? ' · <span class="sk-zone-pts">📍 ' + nPt + ' จุดแจ้ง</span>' : '') + '</span>'
                + (z.note ? '<span class="sk-zone-note">' + esc(String(z.note).replace(/\s*—?\s*https?:\/\/\S+/g, '')) + '</span>' : '')
                + '</span><span class="sk-zone-lv">' + esc(lv.name) + '</span>'
                + '<span class="sk-zone-go">' + IC.go + '</span></button>'
            );
        });
    }

    /* ==================== รายละเอียดพื้นที่ ==================== */

    // ค่าที่ประชาชนเลือกตอนแจ้ง (ชื่อตาม libs/helpers.php) เรียงจากเบา → หนัก — ใช้เรียงสรุปและเลือกสีชิป
    var SCALE = {
        depth: ['ถึงข้อเท้า', 'ถึงเข่า', 'ถึงเอว', 'สูงกว่าเอว'],
        vehicle: ['รถเก๋งผ่านได้', 'เฉพาะรถกระบะ/รถสูง', 'รถผ่านไม่ได้', 'ต้องใช้เรือ'],
        trend: ['กำลังลด', 'ทรงตัว', 'กำลังขึ้น'],
        extent: ['เฉพาะจุดนี้ / แอ่งเล็ก', 'เต็มถนนช่วงหนึ่ง', 'ทั้งซอย / หลายบ้าน', 'กว้างจนมองไม่เห็นขอบน้ำ']
    };
    // สีชิป: s0 เขียว (ดี) · s1 ฟ้า · s2 ส้ม · s3 แดง · s4 แดงทึบ (หนักสุด) · ว่าง = เทา (ไม่บอกความรุนแรง)
    var TONE = {
        depth: ['s1', 's2', 's3', 's4'],
        vehicle: ['s0', 's2', 's3', 's4'],
        trend: ['s0', 's1', 's3'],
        extent: ['', '', '', '']
    };
    var ROWS = [
        { kind: 'depth', label: 'ระดับน้ำ', emoji: '💧' },
        { kind: 'vehicle', label: 'รถผ่านได้ไหม', emoji: '🚗' },
        { kind: 'trend', label: 'แนวโน้มน้ำ', emoji: '📈' },
        { kind: 'extent', label: 'ท่วมกว้าง', emoji: '🌊' },
        { kind: 'impacts', label: 'ผลกระทบ', emoji: '⚠️' }
    ];

    function tone(kind, v) {
        var i = SCALE[kind] ? SCALE[kind].indexOf(v) : -1;
        return i < 0 ? '' : TONE[kind][i];
    }

    /** นับค่าที่แจ้งเข้ามา → [{v, n}] เรียงจากหนักไปเบา (ค่าที่ไม่รู้จักต่อท้าย) · ผลกระทบเรียงตามจำนวน */
    function tally(pts, kind) {
        var count = {};
        var keys = [];
        pts.forEach(function (p) {
            var vals = kind === 'impacts' ? (p.impacts || []) : (p[kind] ? [p[kind]] : []);
            vals.forEach(function (v) {
                if (!count[v]) {
                    count[v] = 0;
                    keys.push(v);
                }
                count[v] += 1;
            });
        });
        var sc = SCALE[kind] || [];
        return keys.sort(function (a, b) {
            var d = sc.indexOf(b) - sc.indexOf(a);
            return d || count[b] - count[a];
        }).map(function (v) { return { v: v, n: count[v] }; });
    }

    function chip(text, cls, n) {
        return '<span class="sk-c' + (cls ? ' ' + cls : '') + '">' + esc(text)
            + (n ? '<span class="n">' + n + ' จุด</span>' : '') + '</span>';
    }

    function section(title, sub, body) {
        return '<section class="sk-dt-sec"><h3 class="sk-dt-h"><span>' + esc(title) + '</span>'
            + (sub ? '<small>' + esc(sub) + '</small>' : '') + '</h3>' + body + '</section>';
    }

    function summaryHtml(pts) {
        var rows = ROWS.map(function (r) {
            var t = tally(pts, r.kind);
            if (!t.length) {
                return '';
            }
            return '<div class="sk-dt-k"><span aria-hidden="true">' + r.emoji + '</span> ' + esc(r.label) + '</div>'
                + '<div class="sk-dt-v">' + t.map(function (x) {
                    return chip(x.v, r.kind === 'impacts' ? 'imp' : tone(r.kind, x.v), x.n);
                }).join('') + '</div>';
        }).join('');
        return rows ? '<div class="sk-dt-sum">' + rows + '</div>'
            : '<div class="sk-dt-empty">ผู้แจ้งไม่ได้ระบุระดับน้ำหรือการสัญจร</div>';
    }

    function reportCard(p) {
        var facts = [];
        if (p.depth) {
            facts.push(chip('น้ำ' + p.depth, tone('depth', p.depth)));
        }
        if (p.vehicle) {
            facts.push(chip(p.vehicle, tone('vehicle', p.vehicle)));
        }
        if (p.trend) {
            facts.push(chip('น้ำ' + p.trend, tone('trend', p.trend)));
        }
        if (p.extent) {
            facts.push(chip(p.extent, ''));
        }
        var imps = (p.impacts || []).map(function (x) { return chip(x, 'imp'); });
        return '<li class="sk-rp" data-rp="' + esc(p.id) + '">'
            + '<div class="sk-rp-top"><b>' + esc(p.ago) + '</b><span>' + esc(p.time_th) + '</span></div>'
            + (facts.length ? '<div class="sk-rp-chips">' + facts.join('') + '</div>' : '')
            + (imps.length ? '<div class="sk-rp-chips">' + imps.join('') + '</div>' : '')
            + (!facts.length && !imps.length ? '<div class="sk-rp-none">ไม่ได้ระบุรายละเอียด</div>' : '')
            + '<div class="sk-rp-act">'
            + (markersById[p.id] ? '<button type="button" class="sk-rp-pin" data-pt="' + esc(p.id) + '">' + IC.pin + 'ดูจุดบนแผนที่</button>' : '')
            + (p.approx ? '<span class="sk-rp-approx">ตำแหน่งโดยประมาณ</span>' : '')
            + (p.fb_url ? fbLink(p.fb_url, 'โพสต์ต้นทาง') : '')
            + '</div></li>';
    }

    /* ---------- Like / Not Like ของพื้นที่ (นับ 1 เสียงต่อเบราว์เซอร์ — ฝั่งเซิร์ฟเวอร์ใช้คุกกี้ skvid) ---------- */
    var votes = {};          // zone_id → {likes, dislikes, mine: 1 / -1 / 0}
    var voteOff = false;     // เซิร์ฟเวอร์ยังไม่พร้อม (สร้างตารางไม่ได้) — ซ่อนปุ่ม
    var voteBusy = false;

    function voteNum(n) {
        return Number(n || 0).toLocaleString('th-TH');
    }

    function voteBtn(val, v) {
        var up = val === 1;
        var on = !!v && v.mine === val;
        var n = v ? (up ? v.likes : v.dislikes) : null;
        return '<button type="button" class="sk-vote-btn ' + (up ? 'up' : 'down') + (on ? ' on' : '') + '" data-v="' + val + '"'
            + ' aria-pressed="' + on + '">'
            + '<i class="fa fa-thumbs-' + (on ? '' : 'o-') + (up ? 'up' : 'down') + '" aria-hidden="true"></i>'
            + '<span>' + (up ? 'Like' : 'Not Like') + '</span>'
            + '<b class="n">' + (n === null ? '–' : voteNum(n)) + '</b></button>';
    }

    function voteHtml(id) {
        if (voteOff) {
            return '';
        }
        var v = votes[id];
        return '<div class="sk-vote" data-zone="' + esc(id) + '">'
            + '<span class="sk-vote-q">ข้อมูลนี้มีประโยชน์ไหม</span>'
            + '<span class="sk-vote-btns" role="group" aria-label="Like หรือ Not Like ข้อมูลพื้นที่นี้">'
            + voteBtn(1, v) + voteBtn(-1, v) + '</span></div>';
    }

    /** อัปเดตปุ่มในที่เดิม (ไม่วาดใหม่ — โฟกัสของคีย์บอร์ดยังอยู่ที่ปุ่มที่กด) */
    function paintVote(id) {
        var v = votes[id];
        $detail.find('.sk-vote[data-zone="' + id + '"] .sk-vote-btn').each(function () {
            var val = +$(this).data('v');
            var up = val === 1;
            var on = !!v && v.mine === val;
            $(this).toggleClass('on', on).attr('aria-pressed', String(on));
            $(this).find('.fa').attr('class', 'fa fa-thumbs-' + (on ? '' : 'o-') + (up ? 'up' : 'down'));
            $(this).find('.n').text(v ? voteNum(up ? v.likes : v.dislikes) : '–');
        });
    }

    function setVotes(id, o) {
        votes[id] = { likes: +o.likes || 0, dislikes: +o.dislikes || 0, mine: +o.mine || 0 };
    }

    function loadVotes(id) {
        if (voteOff || voteBusy) {
            return;
        }
        Flood.get('api/votes', { zone: id }, { silent: true }).done(function (o) {
            if (voteBusy) {
                return;   // กดอยู่ระหว่างโหลด — ใช้ผลจากการกดแทน
            }
            setVotes(id, o);
            paintVote(id);
        }).fail(function (o) {
            if (o && o.off) {
                voteOff = true;
                $detail.find('.sk-vote').remove();
            }
        });
    }

    /** กดปุ่มเดิมซ้ำ = ยกเลิก · กดอีกปุ่ม = เปลี่ยน — แสดงผลทันที แล้วใช้ตัวเลขจริงจากเซิร์ฟเวอร์ */
    function castVote(id, val) {
        if (voteBusy || voteOff) {
            return;
        }
        var prev = votes[id] ? $.extend({}, votes[id]) : { likes: 0, dislikes: 0, mine: 0 };
        var next = prev.mine === val ? 0 : val;
        var cur = $.extend({}, prev);
        if (cur.mine === 1) {
            cur.likes = Math.max(0, cur.likes - 1);
        } else if (cur.mine === -1) {
            cur.dislikes = Math.max(0, cur.dislikes - 1);
        }
        if (next === 1) {
            cur.likes += 1;
        } else if (next === -1) {
            cur.dislikes += 1;
        }
        cur.mine = next;
        votes[id] = cur;
        paintVote(id);
        voteBusy = true;
        $detail.find('.sk-vote').addClass('is-busy');
        Flood.post('api/vote', { zone: id, vote: next }, { silent: true }).done(function (o) {
            setVotes(id, o);
        }).fail(function (o) {
            if (o && o.likes !== undefined) {
                setVotes(id, o);
            } else {
                votes[id] = prev;
            }
            if (o && o.off) {
                voteOff = true;
            }
            Flood.toast((o && o.msg) || 'บันทึกไม่สำเร็จ กรุณาลองใหม่', 'danger');
        }).always(function () {
            voteBusy = false;
            $detail.find('.sk-vote').removeClass('is-busy');
            if (voteOff) {
                $detail.find('.sk-vote').remove();
            } else {
                paintVote(id);
            }
        });
    }

    function detailHtml(z) {
        var lv = FloodMap.level(z.level);
        var pts = zonePoints(z.zone_id);
        var photos = $.isArray(z.photos) ? z.photos : [];
        var area = FloodMap.areaLabel(z);
        var h = '<div class="sk-dt-bar">'
            + '<button type="button" class="sk-dt-back" id="pubDetailBack">' + IC.back + 'รายการพื้นที่</button>'
            + '<button type="button" class="sk-dt-map" title="เลื่อนแผนที่ไปที่พื้นที่นี้">' + IC.target + 'ดูบนแผนที่</button>'
            + '</div>';

        h += '<div class="sk-dt-head lv-' + esc(z.level) + '">'
            + '<span class="lv-badge lv-' + esc(z.level) + '" style="background:' + esc(lv.badge || lv.color) + '">' + esc(lv.name) + '</span>' + FloodMap.pendingTag(z)
            + '<h2 class="sk-dt-name" tabindex="-1">' + esc(z.name) + '</h2>'
            + '<div class="sk-dt-meta">' + esc([area, z.started_th ? 'ประกาศ ' + z.started_th : ''].filter(Boolean).join(' · ')) + '</div>'
            + voteHtml(+z.zone_id)
            + '</div>';

        if (z.note) {
            h += section(z.source === 'web' ? 'ข้อมูลจากข่าว/โซเชียล (ยังไม่ตรวจสอบ)' : 'ประกาศจากเจ้าหน้าที่', '',
                '<div class="sk-dt-note">' + FloodMap.linkNote(z.note) + '</div>');
        }

        if (pts.length) {
            h += section('สรุปจากรายงานประชาชน', pts.length + ' จุด · ล่าสุด ' + pts[0].ago, summaryHtml(pts));
        }

        if (photos.length) {
            h += section('ภาพประกอบ', photos.length + ' ภาพ · แตะเพื่อขยาย', '<div class="sk-dt-photos">' + photos.map(function (id, i) {
                return '<button type="button" class="sk-dt-photo" data-i="' + i + '" aria-label="ดูภาพที่ ' + (i + 1) + ' ขนาดใหญ่">'
                    + '<img src="' + esc(FloodMap.photoUrl(id, true)) + '" alt="" loading="lazy" decoding="async"></button>';
            }).join('') + '</div>');
        }

        if (pts.length) {
            var shown = detailAll ? pts : pts.slice(0, MAX_REPORTS);
            h += section('รายงานแต่ละจุด', 'ใหม่สุดก่อน', '<ul class="sk-rps">' + shown.map(reportCard).join('') + '</ul>'
                + (pts.length > shown.length
                    ? '<button type="button" class="sk-dt-more">ดูอีก ' + (pts.length - shown.length) + ' จุด</button>' : ''));
        } else {
            h += section('รายงานจากประชาชน', '', '<div class="sk-dt-empty">ยังไม่มีรายงานจากประชาชนที่เจ้าหน้าที่ยืนยันในพื้นที่นี้</div>');
        }

        h += '<a class="sk-dt-cta" href="' + esc(Flood.url('report')) + '">พบน้ำท่วมในพื้นที่นี้? <b>แจ้งจุดน้ำท่วม' + IC.go + '</b></a>'
            + '<p class="sk-dt-foot">แสดงเฉพาะรายงานที่เจ้าหน้าที่ตรวจสอบแล้ว · ตำแหน่งจุดอาจคลาดเคลื่อนเล็กน้อย</p>';
        return h;
    }

    /** ตัวเลือก (selector) ของปุ่มที่โฟกัสอยู่ในรายละเอียด — วาดใหม่แล้วโฟกัสกลับที่เดิม */
    function focusKey() {
        var el = document.activeElement;
        if (!el || !$.contains($detail[0], el)) {
            return '';
        }
        if (el.id) {
            return '#' + el.id;
        }
        var i = el.getAttribute('data-i');
        if (i) {
            return '.sk-dt-photo[data-i="' + i + '"]';
        }
        var v = el.getAttribute('data-v');
        if (v) {
            return '.sk-vote-btn[data-v="' + v + '"]';
        }
        var cls = el.className && typeof el.className === 'string' ? '.' + el.className.split(/\s+/)[0] : '';
        if (!cls) {
            return '';
        }
        // ปุ่ม/ลิงก์ในรายงานทีละจุด — ระบุรายงานด้วย (ไม่ให้โฟกัสไปลิงก์ของรายงานอื่น)
        var rp = $(el).closest('.sk-rp[data-rp]').attr('data-rp');
        return rp ? '.sk-rp[data-rp="' + rp + '"] ' + cls : cls;
    }

    function focusQuiet(el) {
        if (!el) {
            return;
        }
        try {
            el.focus({ preventScroll: true });
        } catch (e) {
            el.focus();
        }
    }

    /** วาดรายละเอียดของพื้นที่ที่เปิดอยู่ · keepScroll = รีเฟรชข้อมูล (ไม่เลื่อนกลับขึ้นบน ไม่ย้ายโฟกัส) */
    function renderDetail(keepScroll) {
        var z = zoneById(detailId);
        if (!z) {
            return false;
        }
        var sig = JSON.stringify([z, zonePoints(detailId), detailAll, Object.keys(markersById).length]);
        if (keepScroll && sig === detailSig) {
            return true;
        }
        var top = $detail.scrollTop();
        var fk = keepScroll ? focusKey() : '';
        detailSig = sig;
        $detail.html(detailHtml(z));
        // ภาพโหลดไม่ได้ (เช่น เพิ่งปิดประกาศ) — เอากรอบภาพออก ไม่ให้เห็นรูปแตก
        $detail.find('.sk-dt-photo img').one('error', function () {
            $(this).closest('.sk-dt-photo').remove();
        });
        $detail.scrollTop(keepScroll ? top : 0);
        if (fk) {
            focusQuiet($detail.find(fk)[0]);
        }
        return true;
    }

    function setHistory(push) {
        if (!window.history || !history.replaceState) {
            return;
        }
        try {
            if (push) {
                history.pushState({ skZone: detailId, pushed: true }, '', '#zone-' + detailId);
                detailPushed = true;
            } else {
                history.replaceState({ skZone: detailId, pushed: detailPushed }, '', '#zone-' + detailId);
            }
        } catch (e) { /* เบราว์เซอร์ในแอปบางตัวไม่ให้แก้ประวัติ — ใช้งานต่อได้ */ }
    }

    /**
     * เปิดรายละเอียดพื้นที่ในแผงข้อมูล
     * opts.history: true (ค่าเริ่ม) = เพิ่มประวัติ · 'replace' = แทนที่ · false = ไม่แตะ (มาจากปุ่มย้อนกลับ)
     */
    function openDetail(id, opts) {
        opts = opts || {};
        var z = zoneById(id);
        if (!z) {
            return false;
        }
        var wasOpen = detailId !== null;
        if (wasOpen && +z.zone_id === detailId) {
            return true;   // เปิดพื้นที่นี้อยู่แล้ว — ไม่วาดใหม่ ไม่เลื่อนกลับขึ้นบน
        }
        if (!wasOpen) {
            listTop = $main.scrollTop();
        }
        var prev = wasOpen ? zoneById(detailId) : null;
        detailId = +z.zone_id;
        detailAll = false;
        if (!layersById[detailId] || (prev && !inFilter(prev.level))) {
            // ตัวกรองระดับซ่อนพื้นที่นี้อยู่ (หรือพื้นที่ก่อนหน้าแสดงไว้เพราะเปิดดูอยู่) — วาดแผนที่ใหม่
            // ต้องวาดก่อนรายละเอียด: ปุ่ม "ดูจุดบนแผนที่" มีเฉพาะจุดที่มีหมุดบนแผนที่
            renderMap();
        } else {
            styleZones();
            refreshPointPopup();
        }
        renderDetail(false);
        loadVotes(detailId);
        $main.prop('hidden', true);
        $detail.prop('hidden', false);
        if (opts.history !== false) {
            setHistory(!wasOpen && opts.history !== 'replace');
        }
        if (opts.focus !== false) {
            focusQuiet($detail.find('.sk-dt-name')[0]);
        }
        return true;
    }

    /** กลับไปหน้ารายการพื้นที่ (ตำแหน่งเลื่อนเดิม) */
    function closeDetail(opts) {
        opts = opts || {};
        if (detailId === null) {
            return;
        }
        var id = detailId;
        if (opts.history !== false) {
            if (detailPushed) {
                // ย้อนประวัติที่เพิ่มไว้ตอนเปิด (ไม่ให้ค้างรายการ #zone ในประวัติ) — ปิดแผงทันทีด้านล่าง
                // popstate ที่ตามมาจากการย้อนนี้ไม่ต้องทำอะไรอีก
                detailPushed = false;
                skipPopAt = Date.now();
                history.back();
            } else {
                clearHash();
            }
        }
        detailId = null;
        detailAll = false;
        detailSig = '';
        $detail.prop('hidden', true).empty();
        $main.prop('hidden', false).scrollTop(listTop);
        var z = zoneById(id);
        if (z && !inFilter(z.level)) {
            renderMap();   // พื้นที่นี้แสดงไว้เพราะเปิดดูอยู่ — ปิดแล้วให้แผนที่กลับไปตามตัวกรอง
        } else {
            styleZones();
            refreshPointPopup();
        }
        if (opts.focus !== false) {
            focusQuiet($('#pubList .sk-zone[data-id="' + id + '"]')[0]);
        }
    }

    function flyToZone(id, delay) {
        var layer = layersById[id];
        if (!layer || !layer.getBounds) {
            return;
        }
        setTimeout(function () {
            map.flyToBounds(layer.getBounds(), $.extend({ maxZoom: 16, duration: 0.7 }, fitPadding()));
        }, delay || 0);
    }

    /** "ดูจุดบนแผนที่" ในรายงานทีละจุด — บินไปที่จุดแล้วเปิดบอลลูนของจุดนั้น */
    function showPoint(id) {
        var m = markersById[id];
        if (!m) {
            return;
        }
        setTimeout(function () {
            var ll = m.getLatLng();
            map.closePopup();
            // เปิดบอลลูนหลังบินถึง — เปิดระหว่างบิน การเลื่อนให้เห็นบอลลูนจะชนกับการบิน
            map.once('moveend', function () { m.openPopup(); });
            map.flyToBounds(L.latLngBounds(ll, ll), $.extend({ maxZoom: Math.max(map.getZoom(), 17), duration: 0.6 }, fitPadding()));
        }, revealMap());
    }

    /** เอา #zone-… ออกจาก URL (ไม่เพิ่มประวัติ) */
    function clearHash() {
        try {
            history.replaceState(null, '', location.pathname + location.search);
        } catch (e) { /* เบราว์เซอร์ในแอปบางตัวไม่ให้แก้ประวัติ — ใช้งานต่อได้ */ }
    }

    function zoneFromHash() {
        var m = /^#zone-(\d+)$/.exec(location.hash || '');
        return m ? +m[1] : null;
    }

    function renderCounts() {
        var c = { total: data.zones.length };
        data.zones.forEach(function (z) {
            c[z.level] = (c[z.level] || 0) + 1;
        });
        $('[data-count]').each(function () {
            var n = c[$(this).data('count')] || 0;
            $(this).text(n).closest('.sk-tile').toggleClass('is-zero', n === 0);
        });
        $('#pubTotal').text(c.total);
        // ตัวเลขสรุปตามอำเภอที่เลือก — บอกให้ชัดว่าเป็นภาพรวมของที่ไหน
        $('#pubOverview').text(scopeName('ภาพรวม') + ' · แตะเพื่อกรอง');
    }

    function renderAll() {
        renderMap();
        renderList();
        $('.sk-tile').each(function () {
            $(this).attr('aria-pressed', String($(this).data('level') === levelFilter));
        });
        if (levelFilter) {
            $('#pubLevelName').text(FloodMap.level(levelFilter).name);
            $('#pubLevelClear').prop('hidden', false);
        } else {
            $('#pubLevelClear').prop('hidden', true);
        }
        $('#pubFLevel').val(levelFilter);
        $('#pubFReset').prop('hidden', !(levelFilter || textFilter || regionFilter || provinceFilter || amphoeFilter));
    }

    // นับถอยหลังถึงการดึงข้อมูลรอบถัดไป (ทุก 2 นาที) แสดงต่อจากเวลาอัปเดต
    var REFRESH_SEC = 120;
    var nextIn = REFRESH_SEC;
    function tickNext() {
        if (!document.hidden) {
            nextIn -= 1;
        }
        if (nextIn <= 0) {
            reload();
        }
        var m = Math.floor(Math.max(0, nextIn) / 60);
        var s = Math.max(0, nextIn) % 60;
        $('#pubNext').text(' · รีเฟรชใน ' + m + ':' + (s < 10 ? '0' : '') + s);
    }

    function reload() {
        nextIn = REFRESH_SEC;
        Flood.get('api/zones', { amphoe: amphoeFilter, province: provinceFilter, region: regionFilter }, { silent: true }).then(function (o) {
            data.zones = o.zones || [];
            data.points = o.points || [];
            $('#pubUpdated').text(o.updated_th || '');
            renderCounts();
            renderAll();
            if (detailId !== null) {
                if (zoneById(detailId)) {
                    renderDetail(true);
                    loadVotes(detailId);
                } else {
                    closeDetail({ focus: false });
                    Flood.toast('พื้นที่ที่เปิดดูอยู่ ปิดประกาศแล้ว', 'info');
                }
            }
        });
    }

    $('#pubList').on('click', '.sk-zone', function () {
        var id = +$(this).data('id');
        var wait = revealMap();
        if (openDetail(id)) {
            flyToZone(id, wait);
        }
    });

    $detail.on('click', '#pubDetailBack', function () {
        closeDetail();
    });
    $detail.on('click', '.sk-dt-map', function () {
        flyToZone(detailId, revealMap());
    });
    $detail.on('click', '.sk-dt-photo', function () {
        var z = zoneById(detailId);
        if (z && $.isArray(z.photos)) {
            FloodMap.lightbox(z.photos, +$(this).data('i') || 0, z.name);
        }
    });
    $detail.on('click', '.sk-rp-pin', function () {
        showPoint(+$(this).data('pt'));
    });
    $detail.on('click', '.sk-vote-btn', function () {
        castVote(+$(this).closest('.sk-vote').data('zone'), +$(this).data('v'));
    });
    $detail.on('click', '.sk-dt-more', function () {
        detailAll = true;
        renderDetail(true);
        // โฟกัสรายงานแรกที่เพิ่งแสดง (ปุ่ม "ดูอีก" หายไปแล้ว)
        focusQuiet($detail.find('.sk-rp').eq(MAX_REPORTS).find('button, a')[0]);
    });
    $panel.on('keydown', function (e) {
        if ((e.key === 'Escape' || e.keyCode === 27) && detailId !== null && !$('body').hasClass('fm-lb-open')) {
            e.preventDefault();
            closeDetail();
        }
    });

    // ปุ่ม "ดูรายละเอียดพื้นที่" ในบอลลูนจุดที่แจ้ง
    $('#pubMap').on('click', '.sk-pp-zone', function (e) {
        e.preventDefault();
        var id = +$(this).data('zone');
        if (isMobile()) {
            map.closePopup();   // บอลลูนจะโดนแผ่นเลื่อนด้านล่างบังอยู่ดี
        }
        openDetail(id);
    });

    // ปุ่มย้อนกลับของเบราว์เซอร์/มือถือ
    $(window).on('popstate', function (e) {
        var st = e.originalEvent.state;
        if (skipPopAt && Date.now() - skipPopAt < 1500 && !(st && st.skZone)) {
            skipPopAt = 0;   // มาจาก history.back() ของปุ่ม "รายการพื้นที่" — ปิดแผงไปแล้ว
            if (detailId !== null) {
                // เปิดพื้นที่ใหม่เร็วมากก่อนการย้อนเสร็จ (เบราว์เซอร์ทิ้ง pushState นั้นไป) — เพิ่มประวัติให้ใหม่
                detailPushed = false;
                setHistory(true);
            }
            return;
        }
        skipPopAt = 0;
        if (detailId !== null && $('.fm-lb').length && !(st && st.skZone)) {
            // ปุ่มย้อนกลับของมือถือขณะดูภาพเต็มจอ = ปิดภาพ (รายละเอียดพื้นที่ยังเปิดอยู่ ใส่ประวัติกลับคืน)
            $('.fm-lb .fm-lb-close').trigger('click');
            detailPushed = false;
            setHistory(true);
            return;
        }
        var id = st && st.skZone ? st.skZone : zoneFromHash();
        if (id !== null && zoneById(id)) {
            detailPushed = !!(st && st.pushed);
            openDetail(id, { history: false });
        } else {
            detailPushed = false;
            closeDetail({ history: false });
            if (id !== null) {
                clearHash();   // แก้ #zone-… เองเป็นพื้นที่ที่ไม่มี/ปิดไปแล้ว
                Flood.toast('ไม่พบพื้นที่นี้ หรือปิดประกาศแล้ว', 'info');
            }
        }
    });

    $('#pubKpis').on('click', '.sk-tile', function () {
        var lv = $(this).data('level');
        levelFilter = levelFilter === lv ? '' : lv;
        firstFit = true;
        renderAll();
    });

    $('#pubLevelClear').on('click', function () {
        levelFilter = '';
        firstFit = true;
        renderAll();
    });

    /* ---------- ตัวกรอง: ระดับ · ภาค → จังหวัด → อำเภอ · ค้นหา ---------- */
    var $fRegion = $('#pubFRegion');
    var $fProvince = $('#pubFProvince');
    var $fAmphoe = $('#pubFAmphoe');
    function opt(v, label, sel) {
        return '<option value="' + esc(v) + '"' + (sel ? ' selected' : '') + '>' + esc(label) + '</option>';
    }
    function fillProvinces() {
        var html = opt('', 'ทุกจังหวัด', !provinceFilter);
        var groups = {};
        var order = [];
        (data.provinces || []).forEach(function (p) {
            if (regionFilter && p.region !== regionFilter) {
                return;
            }
            var g = p.region || '';
            if (!groups[g]) {
                groups[g] = [];
                order.push(g);
            }
            groups[g].push(p);
        });
        order.forEach(function (g) {
            var items = groups[g].map(function (p) { return opt(p.code, 'จ.' + p.name, p.code === provinceFilter); }).join('');
            var r = regionInfo(g);
            html += order.length > 1 && r ? '<optgroup label="' + esc(r.name) + '">' + items + '</optgroup>' : items;
        });
        $fProvince.html(html);
    }
    function fillAmphoes() {
        var single = (data.provinces || []).length <= 1;
        var html = opt('', 'ทุกอำเภอ', !amphoeFilter);
        if (provinceFilter || single) {
            (data.amphoes || []).forEach(function (a) {
                if (single || String(a[0]).substring(0, 2) === provinceFilter) {
                    html += opt(a[0], 'อ.' + a[1], a[0] === amphoeFilter);
                }
            });
        }
        $fAmphoe.html(html).prop('disabled', !(provinceFilter || single));
    }
    function syncUrl() {
        // จำภาค/จังหวัด/อำเภอไว้ใน URL — แชร์ลิงก์แล้วเปิดตรงที่เดิม
        try {
            var u = new URL(window.location.href);
            ['region', 'province', 'amphoe'].forEach(function (k) { u.searchParams.delete(k); });
            if (amphoeFilter) {
                u.searchParams.set('amphoe', amphoeFilter);
            } else if (provinceFilter) {
                u.searchParams.set('province', provinceFilter);
            } else if (regionFilter) {
                u.searchParams.set('region', regionFilter);
            }
            window.history.replaceState(window.history.state, '', u.toString());
        } catch (e) { /* เบราว์เซอร์เก่า */ }
    }
    function applyArea(silent) {
        $fRegion.val(regionFilter);
        fillProvinces();
        fillAmphoes();
        $('#pubRegion').text(amphoeName ? 'อ.' + amphoeName + (provinceName ? ' จ.' + provinceName : '')
            : (provinceName ? 'จ.' + provinceName : (regionLabel || areaName)));
        syncUrl();
        if (!silent) {
            firstFit = true;
            reload();
        }
        renderAll();
    }
    function setRegion(code, silent) {
        var r = code ? regionInfo(code) : null;
        regionFilter = r ? r.code : '';
        regionLabel = r ? r.name : '';
        provinceFilter = '';
        provinceName = '';
        amphoeFilter = '';
        amphoeName = '';
        applyArea(silent);
    }
    function setProvince(code, silent) {
        var p = code ? provinceInfo(code) : null;
        provinceFilter = p ? p.code : '';
        provinceName = p ? p.name : '';
        amphoeFilter = '';
        amphoeName = '';
        if (p && p.region) {
            var r = regionInfo(p.region);
            regionFilter = r ? r.code : regionFilter;
            regionLabel = r ? r.name : regionLabel;
        }
        applyArea(silent);
    }
    function setAmphoe(code, silent) {
        var a = null;
        (data.amphoes || []).forEach(function (x) {
            if (x[0] === code) {
                a = x;
            }
        });
        if (a && String(a[0]).substring(0, 2) !== provinceFilter && (data.provinces || []).length > 1) {
            setProvince(String(a[0]).substring(0, 2), true);
        }
        amphoeFilter = a ? a[0] : '';
        amphoeName = a ? a[1] : '';
        applyArea(silent);
    }
    /* จำพื้นที่ที่เลือกไว้ในเครื่อง — เปิดครั้งหน้าเริ่มที่เดิม (ไม่มี localStorage = ข้ามไป) */
    var SAVE_KEY = 'skfArea';
    var userPicked = false;
    function saveArea() {
        userPicked = true;
        try {
            localStorage.setItem(SAVE_KEY, JSON.stringify({ r: regionFilter, p: provinceFilter, a: amphoeFilter, t: Date.now() }));
        } catch (e) { /* โหมดส่วนตัว */ }
    }
    function loadArea() {
        try {
            return JSON.parse(localStorage.getItem(SAVE_KEY) || 'null');
        } catch (e) {
            return null;
        }
    }
    /**
     * จังหวัดตามตำแหน่งผู้ใช้ (เบราว์เซอร์ถามสิทธิ์ก่อน) → ตั้งตัวกรองจังหวัดให้เอง
     * manual = กดปุ่ม "ใกล้ฉัน" (แจ้งผลทุกกรณี) · อัตโนมัติ = ครั้งแรกที่เปิด ไม่แจ้งถ้าไม่ได้
     */
    function locateArea(manual) {
        if (!navigator.geolocation) {
            if (manual) {
                Flood.toast('เบราว์เซอร์นี้หาตำแหน่งไม่ได้', 'info');
            }
            return;
        }
        var $b = $('#pubFNear').prop('disabled', true);
        navigator.geolocation.getCurrentPosition(function (pos) {
            Flood.get('api/area', { lat: pos.coords.latitude.toFixed(3), lng: pos.coords.longitude.toFixed(3) }, { silent: true })
                .then(function (o) {
                    $b.prop('disabled', false);
                    if (!o || !o.chk) {
                        if (manual) {
                            Flood.toast('ตำแหน่งของคุณอยู่นอกพื้นที่ในระบบ', 'info');
                        }
                        return;
                    }
                    if (!manual && userPicked) {
                        return;   // ผู้ใช้เลือกพื้นที่เองไปแล้วระหว่างรอ — ไม่เปลี่ยนให้
                    }
                    setProvince(o.province);
                    saveArea();
                    Flood.toast('แสดงพื้นที่ จ.' + o.province_name + ' ตามตำแหน่งของคุณ · เปลี่ยนได้ที่ตัวกรอง', 'info');
                }, function () {
                    $b.prop('disabled', false);
                    if (manual) {
                        Flood.toast('ตำแหน่งของคุณอยู่นอกพื้นที่ในระบบ หรือหาไม่ได้ — เลือกจังหวัดจากตัวกรองแทนได้', 'info');
                    }
                });
        }, function () {
            $b.prop('disabled', false);
            if (manual) {
                Flood.toast('ไม่ได้รับอนุญาตให้ใช้ตำแหน่ง — เลือกจังหวัดจากตัวกรองแทนได้', 'info');
            }
        }, { enableHighAccuracy: false, timeout: 10000, maximumAge: 600000 });
    }

    $fRegion.on('change', function () { setRegion(String($(this).val() || '')); saveArea(); });
    $fProvince.on('change', function () { setProvince(String($(this).val() || '')); saveArea(); });
    $fAmphoe.on('change', function () { setAmphoe(String($(this).val() || '')); saveArea(); });
    $('#pubFNear').on('click', function () { locateArea(true); });
    $('#pubFLevel').on('change', function () {
        levelFilter = String($(this).val() || '');
        firstFit = true;
        renderAll();
    });
    var textTimer = null;
    $('#pubFText').on('input search', function () {
        var v = norm($(this).val());
        clearTimeout(textTimer);
        textTimer = setTimeout(function () {
            if (v !== textFilter) {
                textFilter = v;
                firstFit = true;
                renderAll();
            }
        }, 200);
    });
    $('#pubFReset').on('click', function () {
        levelFilter = '';
        textFilter = '';
        $('#pubFText').val('');
        setRegion('');
        saveArea();
    });

    $('#pubHandle').on('click', function () {
        setSheet(!$panel.hasClass('open'));
    });

    renderAll();
    if (data.amphoe) {
        setAmphoe(data.amphoe);       // เปิดจากลิงก์ ?amphoe=2706
    } else if (data.province) {
        setProvince(data.province);   // ?province=27
    } else if (data.region) {
        setRegion(data.region);       // ?region=east
    } else {
        // ไม่ได้ระบุในลิงก์: ใช้พื้นที่ที่เลือกไว้ครั้งก่อน → ไม่เคยเลือก = หาจังหวัดจากตำแหน่งผู้ใช้
        var saved = loadArea();
        if (saved && saved.a) {
            setAmphoe(saved.a);
        } else if (saved && saved.p) {
            setProvince(saved.p);
        } else if (saved && saved.r) {
            setRegion(saved.r);
        } else {
            applyArea(true);
        }
        if (!saved && (data.provinces || []).length > 1) {
            try {
                localStorage.setItem(SAVE_KEY, JSON.stringify({ r: '', p: '', a: '', t: Date.now() }));   // ถามตำแหน่งครั้งเดียว
            } catch (e) { /* ไม่มี localStorage — ถามทุกครั้งที่เปิด */ }
            locateArea(false);
        }
    }

    // ลิงก์ตรงถึงพื้นที่ (#zone-12) — ส่งต่อในไลน์แล้วเปิดมาที่รายละเอียดเลย
    var hz = zoneFromHash();
    if (hz !== null) {
        if (openDetail(hz, { history: 'replace', focus: false })) {
            flyToZone(hz, 400);
        } else {
            clearHash();
            Flood.toast('ไม่พบพื้นที่นี้ หรือปิดประกาศแล้ว', 'info');
        }
    }

    tickNext();
    setInterval(tickNext, 1000);
});
