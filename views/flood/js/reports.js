/* ตรวจรายงานจากประชาชน */
$(function () {
    'use strict';

    var esc = Flood.esc;
    var zones = window.REPORT_ZONES || [];
    // pending = รอตรวจ / waiting = ยืนยันแล้วรอประกาศ / announced = ประกาศแล้ว / rejected = ไม่ใช้ข้อมูล
    var STATUS_COLOR = { pending: '#1f78c1', waiting: '#ea580c', announced: '#16a34a', verified: '#16a34a', rejected: '#9ca3af' };

    /* ---------- แผนที่รวมของหน้านี้ ---------- */
    var map = FloodMap.create('reportsMap');
    var zoneLayer = L.featureGroup().addTo(map);
    var markers = L.featureGroup().addTo(map);
    zones.forEach(function (z) { FloodMap.zoneLayer(z).addTo(zoneLayer); });
    (window.REPORTS || []).forEach(function (r) {
        L.marker([r.lat, r.lng], { icon: FloodMap.dotIcon(STATUS_COLOR[r.status] || '#1f78c1') })
            .bindPopup('<div class="pop-title">' + esc(r.ref_code) + '</div>น้ำ' + esc(r.depth) + '<div class="small-muted">' + esc(r.ago) + '</div>'
                + '<div style="margin-top:6px"><a href="#" class="js-report-open" data-id="' + r.report_id + '">ดูรายละเอียด</a></div>')
            .addTo(markers);
    });
    if (!FloodMap.fitLayers(map, markers.getLayers(), 15)) {
        FloodMap.fitLayers(map, zoneLayer.getLayers(), 13);
    }

    /* ---------- กล่องรายละเอียด ---------- */
    var modalMap = null;
    var modalLayers = null;
    var current = null;

    function kv(label, value) {
        return value ? '<dt>' + esc(label) + '</dt><dd>' + value + '</dd>' : '';
    }

    function zoneOptions(r) {
        // พื้นที่ที่รายงานอยู่ข้างในขึ้นก่อน ตามด้วยพื้นที่อื่นเรียงจากใกล้ไปไกล
        var inside = (r.inside_zones || []).map(function (z) { return z.zone_id; });
        var list = zones.map(function (z) {
            var d = L.latLng(r.lat, r.lng).distanceTo(L.latLng(z.center[0], z.center[1]));
            return { z: z, d: d, inside: inside.indexOf(z.zone_id) >= 0 };
        }).sort(function (a, b) { return (b.inside - a.inside) || (a.d - b.d); });
        // ไม่มีพื้นที่ครอบจุดนี้และพื้นที่ใกล้สุดไกลเกินไป → ไม่เลือกให้เอง กันผูกผิดพื้นที่
        var near = r.loc_method === 'approx' ? 5000 : 3000;
        var noNear = list.length && !list[0].inside && list[0].d > near;
        var html = noNear ? '<option value="" selected>— เลือกพื้นที่ (ไม่มีพื้นที่ใกล้จุดนี้) —</option>' : '';
        return html + list.map(function (x) {
            return '<option value="' + x.z.zone_id + '">' + esc(x.z.name) + ' — ' + esc(FloodMap.level(x.z.level).name)
                + (x.inside ? ' (จุดอยู่ในพื้นที่นี้)' : ' (' + (x.d / 1000).toFixed(1) + ' กม.)') + '</option>';
        }).join('');
    }

    function nearestZoneInfo(r) {
        var inside = (r.inside_zones || []).length > 0;
        var best = null;
        zones.forEach(function (z) {
            var d = L.latLng(r.lat, r.lng).distanceTo(L.latLng(z.center[0], z.center[1]));
            if (best === null || d < best) { best = d; }
        });
        var near = r.loc_method === 'approx' ? 5000 : 3000;
        return { none: !inside && (best === null || best > near), km: best === null ? null : (best / 1000).toFixed(1) };
    }

    /* ขั้นตอนประกาศ (ใช้ทั้งตอนตรวจรายงานใหม่ และรายงานที่ยืนยันแล้วแต่ยังไม่ประกาศ) */
    function announceBlocks(r, n) {
        var h = [];
        if (r.zone_id && r.zone_status !== 'active') {
            h.push('<div class="action-block"><h4>' + (n++) + ') ประกาศพื้นที่เดิมอีกครั้ง</h4>'
                + '<div class="small-muted" style="margin-bottom:6px">รายงานนี้ผูกกับ <b>' + esc(r.zone_name) + '</b> ซึ่งปิดประกาศไปแล้ว</div>'
                + '<button type="button" class="btn btn-warning btn-sm" id="rmReannounce"><i class="fa fa-bullhorn"></i> ประกาศ "' + esc(r.zone_name) + '" อีกครั้ง</button></div>');
        }
        h.push('<div class="action-block"><h4>' + (n++) + ') ประกาศเป็นพื้นที่ใหม่</h4>'
            + '<a class="btn btn-primary btn-sm" href="' + Flood.url('flood/zoneForm?report_id=' + r.report_id) + '"><i class="fa fa-bullhorn"></i> สร้างพื้นที่ประกาศจากรายงานนี้</a></div>');
        if (zones.length) {
            var nPhoto = (r.photos || []).length;
            var ni = nearestZoneInfo(r);
            h.push('<div class="action-block"><h4>' + (n++) + ') ผูกกับพื้นที่ที่ประกาศอยู่แล้ว</h4>'
                + (ni.none ? '<div class="alert alert-warning" style="margin:0 0 8px;padding:8px 10px;font-size:13px">'
                    + '<i class="fa fa-info-circle"></i> ยังไม่มีพื้นที่ประกาศครอบหรืออยู่ใกล้จุดนี้ (ใกล้สุด ' + ni.km + ' กม.) '
                    + '— ให้ใช้ข้อ <b>"ประกาศเป็นพื้นที่ใหม่"</b> ด้านบนแทน'
                    + (r.loc_method === 'approx' ? '<br><span class="small-muted">รายงานนี้ใช้พิกัดกลางตำบล ระยะทางจึงเป็นค่าโดยประมาณ</span>' : '')
                    + '</div>' : '')
                + '<div class="flex flex-wrap gap-2"><select class="form-control input-sm" id="rmZone" style="flex:1 1 260px">' + zoneOptions(r) + '</select>'
                + '<button type="button" class="btn btn-success btn-sm" id="rmAttach"><i class="fa fa-link"></i> ยืนยันและประกาศ</button></div>'
                + (nPhoto ? '<div class="checkbox" style="margin:8px 0 0"><label><input type="checkbox" id="rmPublish" /> '
                    + 'นำรูปจากรายงานนี้ (' + nPhoto + ' รูป) ไปแสดงในบอลลูนของพื้นที่บนแผนที่สาธารณะ</label>'
                    + '<div class="small-muted">ดูรูปก่อนว่าไม่เห็นใบหน้าคน ทะเบียนรถ หรือบ้านเลขที่</div></div>' : '')
                + '<div class="help-block">ถ้าข้อมูลบ่งว่าสถานการณ์หนักขึ้น ให้เข้าไปปรับระดับของพื้นที่นั้นด้วย</div></div>');
        }
        return { html: h, n: n };
    }

    function rejectBlock(n) {
        return '<div class="action-block"><h4>' + n + ') ไม่ใช้ข้อมูลนี้</h4>'
            + '<div class="flex flex-wrap gap-2"><input type="text" class="form-control input-sm" id="rmRejectNote" maxlength="255" style="flex:1 1 260px" '
            + 'placeholder="เหตุผล เช่น โทรยืนยันแล้วน้ำลดแล้ว / ข้อมูลซ้ำ / ติดต่อไม่ได้" />'
            + '<button type="button" class="btn btn-default btn-sm" id="rmReject"><i class="fa fa-ban"></i> ไม่ใช้ข้อมูล</button></div></div>';
    }

    function reviewedInfo(r, withReopen) {
        return '<div class="action-block"><div class="small-muted">ตรวจโดย ' + esc(r.reviewed_by_name || '—') + ' · ' + esc(r.reviewed_th || '') + '</div>'
            + (r.review_note ? '<div style="margin-top:4px">' + esc(r.review_note) + '</div>' : '')
            + (withReopen ? '<button type="button" class="btn btn-default btn-sm" id="rmReopen" style="margin-top:8px"><i class="fa fa-undo"></i> ย้ายกลับไปรอตรวจ</button>' : '')
            + '</div>';
    }

    function renderActions(r) {
        var h = [];
        var a;
        if (r.status === 'pending' && r.source_url) {
            h.push('<div class="action-block"><h4><i class="fa fa-facebook-square"></i> นำเข้าจากโพสต์ ' + esc(r.source_name || 'Facebook') + '</h4>'
                + '<div class="small-muted" style="margin-bottom:6px">พิกัดเป็นจุดกลางตำบลโดยประมาณ — เปิดโพสต์ดูรูป/รายละเอียด และยืนยันกับผู้นำชุมชนหรือ อปท. ก่อนประกาศ</div>'
                + '<a class="btn btn-default btn-sm" href="' + esc(r.source_url) + '" target="_blank" rel="noopener"><i class="fa fa-external-link"></i> เปิดโพสต์ต้นทาง</a></div>');
        }
        // รายงานรอตรวจ — ทั้งที่ประชาชนแจ้งเองและที่นำเข้าจากโพสต์ ต้องมีปุ่มประกาศ/ยืนยัน/ไม่ใช้ข้อมูลเหมือนกัน
        if (r.status === 'pending') {
            if (!r.source_url && r.phone_raw) {
                h.push('<div class="action-block"><h4><i class="fa fa-phone"></i> แนะนำ: โทรยืนยันกับผู้แจ้งก่อน</h4>'
                    + '<a class="btn btn-default btn-sm" href="tel:' + esc(r.phone_raw) + '"><i class="fa fa-phone"></i> โทร ' + esc(r.reporter_phone) + '</a></div>');
            }
            a = announceBlocks(r, 1);
            h = h.concat(a.html);
            h.push('<div class="action-block"><h4>' + (a.n++) + ') ยืนยันแล้ว แต่ยังไม่ประกาศตอนนี้</h4>'
                + '<div class="flex flex-wrap gap-2"><input type="text" class="form-control input-sm" id="rmVerifyNote" maxlength="255" style="flex:1 1 260px" '
                + 'placeholder="บันทึก (ถ้ามี) เช่น โทรยืนยันแล้ว รอดูสถานการณ์ / รอหัวหน้าอนุมัติ" />'
                + '<button type="button" class="btn btn-warning btn-sm" id="rmVerify"><i class="fa fa-check"></i> ยืนยัน · รอประกาศ</button></div>'
                + '<div class="help-block">รายงานจะไปอยู่แท็บ <b>รอประกาศ</b> กดประกาศภายหลังได้ (ยังไม่ขึ้นแผนที่ประชาชน)</div></div>');
            h.push(rejectBlock(a.n));
        } else if (r.view_status === 'waiting') {
            h.push('<div class="alert alert-warning" style="margin:0 0 10px"><i class="fa fa-bullhorn"></i> ยืนยันแล้ว แต่ <b>ยังไม่ได้ประกาศ</b> — ประชาชนยังไม่เห็นจุดนี้บนแผนที่</div>');
            h.push(reviewedInfo(r, false));
            a = announceBlocks(r, 1);
            h = h.concat(a.html);
            h.push(rejectBlock(a.n));
            h.push('<div class="action-block"><button type="button" class="btn btn-default btn-sm" id="rmReopen"><i class="fa fa-undo"></i> ย้ายกลับไปรอตรวจ</button></div>');
        } else {
            h.push(reviewedInfo(r, true));
        }
        $('#rmActions').html(h.join(''));
    }

    function openReport(id) {
        Flood.get('flood/reportData/' + id).then(function (o) {
            var r = current = o.report;
            $('#rmRef').text(r.ref_code);
            $('#rmStatus').html('<span class="label ' + esc(r.view_status_class || 'label-default') + '">' + esc(r.view_status_name || r.status_name) + '</span>');
            $('#rmKv').html(
                kv('แจ้งเมื่อ', esc(r.created_th) + ' <span class="small-muted">(' + esc(r.ago) + ')</span>')
                + kv('ระดับน้ำ', esc(r.depth))
                + kv('ความกว้าง', esc(r.extent))
                + kv('บ้านที่น้ำเข้า', esc(r.houses))
                + kv('รถผ่าน', esc(r.vehicle))
                + kv('แนวโน้ม', esc(r.trend))
                + kv('ผลกระทบในพื้นที่', r.impacts ? '<b class="text-danger">' + esc(r.impacts) + '</b>' : '')
                + kv('จุดสังเกต', esc(r.place_note))
                + kv('พื้นที่ (ประมาณ)', esc(r.area))
                + kv('ผู้แจ้ง', esc(r.reporter_name) + (r.source_url
                    ? '<br><a href="' + esc(r.source_url) + '" target="_blank" rel="noopener"><i class="fa fa-facebook-square"></i> เปิดโพสต์ต้นทาง</a>'
                    : (r.phone_raw ? '<br><a href="tel:' + esc(r.phone_raw) + '"><i class="fa fa-phone"></i> ' + esc(r.reporter_phone) + '</a>' : '')))
                + kv('ผูกกับพื้นที่', r.zone_name ? esc(r.zone_name) + (r.zone_status !== 'active' ? ' <span class="text-danger">(ปิดประกาศแล้ว)</span>' : '') : '')
            );
            $('#rmInside').html((r.inside_zones || []).length
                ? '<div class="alert alert-info" style="margin:0"><i class="fa fa-info-circle"></i> จุดนี้อยู่ในพื้นที่ที่ประกาศแล้ว: '
                    + r.inside_zones.map(function (z) { return '<b>' + esc(z.name) + '</b> ' + Flood.levelBadge(z.level); }).join(', ') + '</div>'
                : '');
            $('#rmLoc').html('<i class="fa fa-map-marker"></i> ' + r.lat.toFixed(6) + ', ' + r.lng.toFixed(6)
                + (r.loc_method === 'gps' ? ' · GPS' + (r.accuracy_m ? ' ±' + r.accuracy_m + ' ม.' : '')
                    : r.loc_method === 'approx' ? ' · <b class="text-danger">พิกัดโดยประมาณ (กลางตำบล)</b>' : ' · ผู้แจ้งปักหมุดเอง')
                + ' · <a href="' + FloodMap.navUrl(r.lat, r.lng) + '" target="_blank" rel="noopener">นำทาง</a>');
            $('#rmPhotos').html((r.photos || []).map(function (u) {
                return '<a href="' + esc(u) + '" target="_blank" rel="noopener"><img src="' + esc(u) + '" alt="รูปจากผู้แจ้ง" loading="lazy"></a>';
            }).join('') || '<span class="small-muted">ไม่มีรูปแนบ</span>');
            renderActions(r);
            $('#reportModal').modal('show');
        });
    }

    $('#reportModal').on('shown.bs.modal', function () {
        var r = current;
        if (!modalMap) {
            modalMap = FloodMap.create('rmMap');
            modalLayers = L.featureGroup().addTo(modalMap);
        }
        modalLayers.clearLayers();
        zones.forEach(function (z) { FloodMap.zoneLayer(z).addTo(modalLayers); });
        L.marker([r.lat, r.lng], { icon: FloodMap.pinIcon('#1f78c1', 'fa-tint') }).addTo(modalLayers);
        if (r.accuracy_m) {
            L.circle([r.lat, r.lng], { radius: r.accuracy_m, color: '#1f78c1', weight: 1, fillOpacity: 0.06 }).addTo(modalLayers);
        }
        modalMap.invalidateSize();
        modalMap.setView([r.lat, r.lng], 16);
    });

    function review(data) {
        return Flood.post('flood/reviewReport', $.extend({ report_id: current.report_id }, data)).then(function (o) {
            Flood.toast(o.msg, 'success');
            setTimeout(function () { window.location.reload(); }, 600);
        });
    }

    $(document).on('click', '.js-report-open', function (e) {
        e.preventDefault();
        openReport($(this).data('id'));
    });
    $(document).on('click', '#rmAttach', function () {
        if (!$('#rmZone').val()) {
            Flood.toast('เลือกพื้นที่ที่จะผูกก่อน — ถ้าไม่มีพื้นที่ใกล้จุดนี้ ให้กด "สร้างพื้นที่ประกาศจากรายงานนี้"', 'danger');
            $('#rmZone').focus();
            return;
        }
        Flood.busy($(this), true);
        review({ action: 'attach', zone_id: $('#rmZone').val(), publish_photos: $('#rmPublish').is(':checked') ? 1 : 0 })
            .fail(function () { Flood.busy($('#rmAttach'), false); });
    });
    $(document).on('click', '#rmReject', function () {
        var note = $.trim($('#rmRejectNote').val());
        if (!note) {
            Flood.toast('กรุณาระบุเหตุผล', 'danger');
            $('#rmRejectNote').focus();
            return;
        }
        Flood.busy($(this), true);
        review({ action: 'reject', note: note }).fail(function () { Flood.busy($('#rmReject'), false); });
    });
    $(document).on('click', '#rmVerify', function () {
        Flood.busy($(this), true);
        review({ action: 'verify', note: $.trim($('#rmVerifyNote').val()) }).fail(function () { Flood.busy($('#rmVerify'), false); });
    });
    $(document).on('click', '#rmReannounce', function () {
        Flood.busy($(this), true);
        review({ action: 'reannounce' }).fail(function () { Flood.busy($('#rmReannounce'), false); });
    });
    $(document).on('click', '#rmReopen', function () {
        Flood.busy($(this), true);
        review({ action: 'reopen' }).fail(function () { Flood.busy($('#rmReopen'), false); });
    });

    // เปิดจากลิงก์บนหน้าภาพรวม: flood/reports?open=<id>
    var m = /[?&]open=(\d+)/.exec(window.location.search);
    if (m) {
        openReport(m[1]);
    }
});
