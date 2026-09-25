/*
 * ตัววาดพื้นที่ประกาศ
 *   วงกลม: แตะแผนที่เพื่อวางจุดศูนย์กลาง ลากหมุดเพื่อย้าย ปรับรัศมีด้วยแถบเลื่อน
 *   วาดขอบเขต: แตะทีละจุดรอบพื้นที่ (อย่างน้อย 3 จุด) ลากจุดเพื่อปรับ แตะจุดค้างเพื่อลบ
 */
$(function () {
    'use strict';

    var cfg = window.ZONE_FORM || {};
    var esc = Flood.esc;
    var map = FloodMap.create('zoneMap', { zoom: 12 });
    var shape = $('#zShape').val() || 'circle';
    var center = null;
    var radius = 300;
    var points = [];
    var circle = null;
    var centerMarker = null;
    var poly = null;
    var vertexMarkers = [];
    var drawLayer = L.featureGroup().addTo(map);
    var refLayer = L.featureGroup().addTo(map);

    /* ---------- ชั้นอ้างอิง: พื้นที่อื่น + รายงานรอตรวจ ---------- */
    // พื้นที่อื่นแสดงเป็นพื้นหลังเฉย ๆ (กดทะลุได้) จะได้วาดทับหรือวาดติดกันได้
    (cfg.others || []).forEach(function (z) {
        FloodMap.zoneLayer(z, {
            popup: false,
            style: { color: '#64748b', fillColor: '#94a3b8', fillOpacity: 0.12, weight: 1, dashArray: '4 4', interactive: false }
        }).addTo(refLayer);
    });
    (cfg.reports || []).forEach(function (r) {
        L.marker([r.lat, r.lng], { icon: FloodMap.dotIcon('#1f78c1') })
            .bindPopup('<div class="pop-title">' + esc(r.ref_code) + '</div>น้ำ' + esc(r.depth_name) + '<div class="small-muted">' + esc(r.ago) + '</div>')
            .addTo(refLayer);
    });

    function levelColor() {
        return FloodMap.level($('input[name=level]:checked').val() || 'watch').color;
    }

    function style() {
        var c = levelColor();
        return { color: c, fillColor: c, weight: 2, fillOpacity: 0.28 };
    }

    var vertexIcon = L.divIcon({ className: 'vertex-icon', iconSize: [14, 14], iconAnchor: [7, 7] });

    /* ---------- วาด ---------- */
    function clearDraw() {
        drawLayer.clearLayers();
        circle = null;
        centerMarker = null;
        poly = null;
        vertexMarkers = [];
    }

    function render() {
        clearDraw();
        if (shape === 'circle') {
            if (center) {
                circle = L.circle(center, $.extend({ radius: radius }, style())).addTo(drawLayer);
                centerMarker = L.marker(center, { draggable: true, icon: FloodMap.pinIcon(levelColor(), 'fa-tint') }).addTo(drawLayer);
                centerMarker.on('drag', function (e) {
                    center = e.target.getLatLng();
                    circle.setLatLng(center);
                    sync();
                });
            }
        } else if (points.length) {
            poly = (points.length >= 3 ? L.polygon(points, style()) : L.polyline(points, { color: levelColor(), weight: 2, dashArray: '5 5' }))
                .addTo(drawLayer);
            points.forEach(function (p, i) {
                var m = L.marker(p, { draggable: true, icon: vertexIcon, title: 'ลากเพื่อย้าย / แตะค้าง (หรือคลิกขวา) เพื่อลบ' }).addTo(drawLayer);
                m.on('drag', function (e) {
                    points[i] = e.target.getLatLng();
                    poly.setLatLngs(points);
                    sync();
                });
                m.on('contextmenu', function () {
                    points.splice(i, 1);
                    render();
                });
                vertexMarkers.push(m);
            });
        }
        sync();
    }

    function areaKm2() {
        if (shape === 'circle') {
            return center ? Math.PI * radius * radius / 1e6 : 0;
        }
        if (points.length < 3) {
            return 0;
        }
        var lat0 = points[0].lat * Math.PI / 180;
        var k = 111320;
        var s = 0;
        for (var i = 0, j = points.length - 1; i < points.length; j = i++) {
            var xi = points[i].lng * k * Math.cos(lat0);
            var yi = points[i].lat * k;
            var xj = points[j].lng * k * Math.cos(lat0);
            var yj = points[j].lat * k;
            s += (xj * yi - xi * yj);
        }
        return Math.abs(s) / 2 / 1e6;
    }

    function sync() {
        $('#zShape').val(shape);
        if (shape === 'circle') {
            $('#zLat').val(center ? center.lat.toFixed(7) : '');
            $('#zLng').val(center ? center.lng.toFixed(7) : '');
            $('#zRadiusHidden').val(radius);
            $('#zPolygon').val('');
            $('#zHelp').html(center
                ? '<i class="fa fa-hand-pointer-o"></i> ลากหมุดเพื่อย้ายจุดศูนย์กลาง ปรับรัศมีด้วยแถบเลื่อน หรือแตะแผนที่เพื่อย้ายไปจุดใหม่'
                : '<i class="fa fa-hand-pointer-o"></i> แตะบนแผนที่ตรงกลางพื้นที่น้ำท่วม เพื่อวางวงกลม');
        } else {
            $('#zLat, #zLng, #zRadiusHidden').val('');
            $('#zPolygon').val(points.length >= 3 ? JSON.stringify(points.map(function (p) {
                return [+p.lat.toFixed(7), +p.lng.toFixed(7)];
            })) : '');
            $('#zHelp').html(points.length >= 3
                ? '<i class="fa fa-check"></i> ขอบเขต ' + points.length + ' จุด — ลากจุดเพื่อปรับ แตะแผนที่เพื่อเพิ่มจุด คลิกขวา/แตะค้างที่จุดเพื่อลบ'
                : '<i class="fa fa-hand-pointer-o"></i> แตะบนแผนที่ทีละจุดรอบพื้นที่น้ำท่วม (อย่างน้อย 3 จุด) — ตอนนี้ ' + points.length + ' จุด');
        }
        var a = areaKm2();
        $('#zArea').text(a > 0 ? 'พื้นที่ประมาณ ' + (a < 0.1 ? Math.round(a * 1e6).toLocaleString() + ' ตร.ม.' : a.toFixed(2) + ' ตร.กม.') : '');
    }

    function setRadius(r, fromInput) {
        radius = Math.max(20, Math.min(30000, parseInt(r, 10) || 20));
        $('#zRadius').val(radius);
        $('#zRadiusRange').val(Math.min(5000, radius));
        if (circle) {
            circle.setRadius(radius);
        }
        sync();
    }

    function setShape(s) {
        shape = s;
        $('.js-shape').removeClass('active').filter('[data-shape="' + s + '"]').addClass('active');
        $('#zRadiusBox').toggleClass('hidden', s !== 'circle');
        $('#zPolyTools').toggleClass('hidden', s !== 'polygon');
        // เปลี่ยนจากวงกลมเป็นวาดขอบเขต: ตั้งจุดเริ่มเป็นสี่เหลี่ยมครอบวงกลมเดิม ให้ปรับต่อได้เลย
        if (s === 'polygon' && !points.length && center) {
            var dLat = radius / 111320;
            var dLng = radius / (111320 * Math.cos(center.lat * Math.PI / 180));
            points = [
                L.latLng(center.lat + dLat, center.lng - dLng), L.latLng(center.lat + dLat, center.lng + dLng),
                L.latLng(center.lat - dLat, center.lng + dLng), L.latLng(center.lat - dLat, center.lng - dLng)
            ];
        }
        if (s === 'circle' && !center && points.length >= 3) {
            center = L.polygon(points).getBounds().getCenter();
        }
        render();
    }

    map.on('click', function (e) {
        if (shape === 'circle') {
            center = e.latlng;
        } else {
            points.push(e.latlng);
        }
        render();
    });

    $('.js-shape').on('click', function () { setShape($(this).data('shape')); });
    $('#zRadiusRange').on('input', function () { setRadius($(this).val()); });
    $('#zRadius').on('change keyup', function () { setRadius($(this).val(), true); });
    $('#zUndo').on('click', function () { points.pop(); render(); });
    $('#zClear').on('click', function () { points = []; render(); });
    $('input[name=level]').on('change', render);
    $('#zLocate').on('click', function () {
        var $b = $(this);
        FloodMap.locate(function (lat, lng) {
            map.setView([lat, lng], 16);
        }, function (msg) {
            Flood.toast(msg, 'info');
        });
        $b.blur();
    });

    /* ---------- อำเภอ/ตำบล ---------- */
    var tambons = cfg.tambons || [];
    function fillTambons(selected) {
        var a = $('#zAmphoe').val();
        var $t = $('#zTambon').empty().append('<option value="">—</option>');
        tambons.forEach(function (t) {
            if (!a || t.amphoe_code === a) {
                $t.append($('<option>').val(t.tambon_code).text(t.name + (a ? '' : ' (' + t.amphoe_code + ')')));
            }
        });
        if (selected) {
            $t.val(selected);
        }
    }
    $('#zAmphoe').on('change', function () { fillTambons(''); });
    $('#zTambon').on('change', function () {
        var code = $(this).val();
        var hasShape = (shape === 'circle' && center) || (shape === 'polygon' && points.length);
        tambons.forEach(function (t) {
            if (t.tambon_code === code && t.lat && !hasShape) {
                map.setView([parseFloat(t.lat), parseFloat(t.lng)], 14);
            }
        });
    });
    fillTambons($('#zTambon').data('selected'));

    /* ---------- ค่าเริ่มต้น ---------- */
    if (cfg.zone) {
        radius = cfg.zone.radius_m || 300;
        if (cfg.zone.shape === 'polygon' && cfg.zone.polygon) {
            points = cfg.zone.polygon.map(function (p) { return L.latLng(p[0], p[1]); });
        } else {
            center = L.latLng(cfg.zone.center[0], cfg.zone.center[1]);
        }
    } else if (cfg.prefill) {
        center = L.latLng(cfg.prefill.center[0], cfg.prefill.center[1]);
        radius = cfg.prefill.radius_m || 200;
    }
    setRadius(radius);
    setShape(shape);
    var fitTo = drawLayer.getLayers();
    if (fitTo.length) {
        map.fitBounds(drawLayer.getBounds().pad(0.6), { maxZoom: 16 });
    } else if (refLayer.getLayers().length) {
        FloodMap.fitLayers(map, refLayer.getLayers(), 13);
    }

    /* ---------- ภาพประกอบ (บอลลูนบนแผนที่สาธารณะ) ---------- */
    var $photoGroup = $('#zPhotoGroup');
    var photoMax = parseInt($photoGroup.data('max'), 10) || 6;
    var photos = $('#zPhotos').length ? FloodUpload.bind($('#zPhotos'), $('#zPreview'), photoMax) : null;

    $photoGroup.on('change', '.js-photo-remove', function () {
        $(this).closest('.zone-photo').toggleClass('is-removing', this.checked);
    });
    $photoGroup.on('change', '.js-photo-pick', function () {
        $(this).closest('.zone-photo').toggleClass('is-picked', this.checked);
    });

    /** จำนวนภาพหลังบันทึก = ภาพเดิมที่ไม่ลบ + รูปรายงานที่ติ๊ก + ภาพที่เพิ่มใหม่ */
    function photoTotal() {
        return (parseInt($photoGroup.data('current'), 10) || 0)
            - $photoGroup.find('.js-photo-remove:checked').length
            + $photoGroup.find('.js-photo-pick:checked').length
            + (photos ? photos.count() : 0);
    }

    /* ---------- บันทึก ---------- */
    $('#zoneForm').on('submit', function (e) {
        e.preventDefault();
        if ($.trim($('#zName').val()).length < 2) {
            Flood.toast('กรุณาตั้งชื่อพื้นที่', 'danger');
            $('#zName').focus();
            return;
        }
        if (shape === 'circle' && !center) {
            Flood.toast('กรุณาแตะบนแผนที่เพื่อวางจุดศูนย์กลาง', 'danger');
            return;
        }
        if (shape === 'polygon' && points.length < 3) {
            Flood.toast('กรุณาแตะบนแผนที่อย่างน้อย 3 จุด', 'danger');
            return;
        }
        if (photoTotal() > photoMax) {
            Flood.toast('ภาพประกอบรวมได้ไม่เกิน ' + photoMax + ' ภาพต่อพื้นที่ — ติ๊ก "ลบ" ภาพเดิมหรือเลือกภาพน้อยลง', 'danger');
            return;
        }
        var $btn = $('#zSave');
        Flood.busy($btn, true);
        var fd = new FormData(this);
        $.when(photos ? photos.appendTo(fd, 'photos[]') : fd).then(function () {
            return Flood.post('flood/saveZone', fd);
        }).then(function (o) {
            Flood.toast(o.msg, 'success');
            window.location.href = o.url;
        }, function () {
            Flood.busy($btn, false);
        });
    });
});
