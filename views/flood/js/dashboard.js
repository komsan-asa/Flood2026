/* ภาพรวมสถานการณ์ — การ์ดตัวเลข + แผนที่รวม + รายการที่ต้องทำ (รีเฟรชทุก 60 วินาที) */
$(function () {
    'use strict';

    var esc = Flood.esc;
    var opt = window.DASH_OPT || {};
    var map = FloodMap.create('dashMap');
    var groups = {
        zones: L.featureGroup().addTo(map),
        help: L.featureGroup().addTo(map),
        reports: L.featureGroup().addTo(map),
        vuln: L.featureGroup().addTo(map)
    };
    var overlays = { 'พื้นที่ประกาศ': groups.zones };
    var fitted = false;

    $('#dashLegend').html(FloodMap.legendHtml());

    var PRI_COLOR = { urgent: '#dc2626', high: '#f59e0b', normal: '#64748b' };
    var EVAC_COLOR = { normal: '#7c3aed', alerted: '#c026d3', evacuated: '#16a34a', shelter_in_place: '#0ea5e9', admitted: '#155e9c' };

    function kpi(o) {
        var tag = o.href ? 'a' : 'div';
        return '<' + tag + (o.href ? ' href="' + esc(Flood.url(o.href)) + '"' : '') + ' class="flood-kpi-card ' + (o.cls || '') + '">'
            + (o.icon ? '<i class="fa ' + o.icon + ' kpi-icon"></i>' : '')
            + '<div class="kpi-value">' + esc(o.value) + '</div>'
            + '<div class="kpi-label">' + esc(o.label) + '</div>'
            + (o.sub ? '<div class="kpi-sub">' + esc(o.sub) + '</div>' : '')
            + '</' + tag + '>';
    }

    function renderKpis(d) {
        var out = [];
        var zc = d.zone_counts || {};
        Object.keys(Flood.levels).sort(function (a, b) { return Flood.levels[a].order - Flood.levels[b].order; }).forEach(function (lv) {
            out.push(kpi({
                value: zc[lv] || 0, label: 'พื้นที่' + Flood.levels[lv].name, cls: 'lv-' + lv,
                icon: Flood.levels[lv].icon, href: opt.canZones ? 'flood/zones?level=' + lv : null
            }));
        });
        var hc = d.help_counts || {};
        if (d.role === 'team') {
            out.push(kpi({ value: hc.assigned || 0, label: 'งานที่ได้รับมอบหมาย', cls: hc.assigned ? 'kpi-danger' : '', icon: 'fa-bell', href: 'flood/help?status=assigned' }));
            out.push(kpi({ value: hc.in_progress || 0, label: 'กำลังช่วยเหลือ', cls: 'kpi-info', icon: 'fa-life-ring', href: 'flood/help?status=in_progress' }));
            out.push(kpi({ value: hc.done_today || 0, label: 'ช่วยเหลือแล้ววันนี้', cls: 'kpi-ok', icon: 'fa-check' }));
        } else {
            out.push(kpi({
                value: hc.waiting || 0, label: 'คำขอรอดำเนินการ', sub: 'ยังไม่ได้มอบหมายทีม', cls: hc.waiting ? 'kpi-danger' : '',
                icon: 'fa-bell', href: opt.canHelp ? 'flood/help?status=waiting' : null
            }));
            out.push(kpi({
                value: (hc.assigned || 0) + (hc.in_progress || 0), label: 'ทีมกำลังช่วยเหลือ', cls: 'kpi-info',
                icon: 'fa-life-ring', href: opt.canHelp ? 'flood/help?status=open' : null
            }));
            out.push(kpi({
                value: hc.urgent_open || 0, label: 'ด่วนมาก (ยังเปิดอยู่)', cls: hc.urgent_open ? 'kpi-danger' : '',
                icon: 'fa-exclamation-triangle', href: opt.canHelp ? 'flood/help?priority=urgent' : null
            }));
            out.push(kpi({ value: hc.done_today || 0, label: 'ช่วยเหลือแล้ววันนี้', sub: 'รับเรื่องวันนี้ ' + (hc.today || 0), cls: 'kpi-ok', icon: 'fa-check' }));
        }
        if (d.report_counts) {
            out.push(kpi({
                value: d.report_counts.pending || 0, label: 'รายงานประชาชนรอตรวจ', sub: 'วันนี้ ' + (d.report_counts.today || 0) + ' รายงาน',
                cls: d.report_counts.pending ? 'kpi-warn' : '', icon: 'fa-tint', href: 'flood/reports'
            }));
            if (d.report_counts.waiting) {
                out.push(kpi({
                    value: d.report_counts.waiting, label: 'ยืนยันแล้ว รอประกาศ', sub: 'ยังไม่ขึ้นแผนที่ประชาชน',
                    cls: 'kpi-warn', icon: 'fa-bullhorn', href: 'flood/reports?status=waiting'
                }));
            }
        }
        if (d.vulnerable) {
            out.push(kpi({
                value: d.vulnerable.in_zone || 0, label: 'กลุ่มเปราะบางในพื้นที่ประกาศ',
                sub: 'ยังไม่อพยพ ' + (d.vulnerable.in_zone_waiting || 0) + ' ราย',
                cls: d.vulnerable.in_zone_waiting ? 'kpi-danger' : '', icon: 'fa-wheelchair', href: 'flood/vulnerable?in_zone=1'
            }));
        }
        out.push(kpi({
            value: d.online ? d.online.online_now : 0, label: 'ผู้ใช้ออนไลน์ตอนนี้',
            sub: 'ใช้งานใน 24 ชม. ' + (d.online ? d.online.active_24h : 0) + ' คน', cls: 'kpi-ok', icon: 'fa-signal',
            href: opt.isAdmin ? 'flood/onlineUsers' : null
        }));
        $('#dashKpis').html(out.join(''));
    }

    function renderLayers(d) {
        groups.zones.clearLayers();
        (d.zones || []).slice().sort(function (a, b) {
            return FloodMap.level(b.level).order - FloodMap.level(a.level).order;
        }).forEach(function (z) {
            FloodMap.zoneLayer(z, {
                extraHtml: opt.canZones ? function (zz) {
                    return '<div style="margin-top:6px"><a href="' + Flood.url('flood/zoneForm/' + zz.zone_id) + '">แก้ไขพื้นที่</a></div>';
                } : null
            }).addTo(groups.zones);
        });

        groups.help.clearLayers();
        (d.help_map || []).forEach(function (h) {
            var pr = opt.priorities[h.priority] || { name: h.priority };
            L.marker([h.lat, h.lng], { icon: FloodMap.pinIcon(PRI_COLOR[h.priority] || '#64748b', 'fa-life-ring') })
                .bindPopup('<div class="pop-title">' + esc(h.ref_code) + '</div>'
                    + '<span class="label ' + esc(pr['class']) + '">' + esc(pr.name) + '</span> '
                    + '<span class="small-muted">' + esc(h.status_name) + '</span>'
                    + '<div style="margin-top:4px">' + esc((h.needs_names || []).join(', ')) + '</div>'
                    + (h.people_count ? '<div class="small-muted">' + esc(h.people_count) + ' คน</div>' : '')
                    + (h.team_name ? '<div class="small-muted">ทีม: ' + esc(h.team_name) + '</div>' : '')
                    + '<div class="small-muted">' + esc(h.ago) + '</div>'
                    + '<div style="margin-top:6px"><a href="' + Flood.url('flood/helpView/' + h.help_id) + '">เปิดใบงาน</a></div>')
                .addTo(groups.help);
        });

        groups.reports.clearLayers();
        (d.reports_map || []).forEach(function (r) {
            L.marker([r.lat, r.lng], { icon: FloodMap.dotIcon('#1f78c1') })
                .bindPopup('<div class="pop-title">' + esc(r.ref_code) + '</div>รายงานรอตรวจ · น้ำ' + esc(r.depth_name)
                    + '<div class="small-muted">' + esc(r.ago) + '</div>'
                    + '<div style="margin-top:6px"><a href="' + Flood.url('flood/reports?open=' + r.report_id) + '">ตรวจรายงาน</a></div>')
                .addTo(groups.reports);
        });

        groups.vuln.clearLayers();
        (d.vulnerable_map || []).forEach(function (v) {
            var ev = opt.evac[v.evac_status] || { name: v.evac_status };
            L.marker([v.lat, v.lng], { icon: FloodMap.pinIcon(EVAC_COLOR[v.evac_status] || '#7c3aed', 'fa-wheelchair') })
                .bindPopup('<div class="pop-title">' + esc(v.name) + '</div>'
                    + esc((v.groups || []).join(', '))
                    + '<div style="margin-top:4px"><span class="label ' + esc(ev['class']) + '">' + esc(ev.name) + '</span></div>'
                    + '<div class="small-muted" style="margin-top:4px">อยู่ใน: ' + esc(v.zone_name) + '</div>'
                    + '<div style="margin-top:6px"><a href="' + Flood.url('flood/vulnerable?q=' + encodeURIComponent(v.name)) + '">ดูในทะเบียน</a></div>')
                .addTo(groups.vuln);
        });

        if (!fitted) {
            var all = [].concat(groups.zones.getLayers(), groups.help.getLayers(), groups.reports.getLayers(), groups.vuln.getLayers());
            FloodMap.fitLayers(map, all, 14);
            fitted = true;
        }
    }

    function renderHelp(d) {
        var $el = $('#dashHelp');
        if (!$el.length) {
            return;
        }
        var rows = d.help_recent || [];
        if (!rows.length) {
            $el.html('<div class="empty-state"><i class="fa fa-check-circle"></i>ไม่มีงานค้าง</div>');
            return;
        }
        $el.html(rows.map(function (h) {
            var pr = opt.priorities[h.priority] || { name: h.priority, 'class': 'label-default' };
            var st = opt.helpStatuses[h.status] || { name: h.status, 'class': 'label-default' };
            return '<a class="dash-list-item" href="' + Flood.url('flood/helpView/' + h.help_id) + '">'
                + '<div class="t1"><span class="label ' + esc(pr['class']) + '">' + esc(pr.name) + '</span>'
                + esc(h.ref_code) + ' <span class="label ' + esc(st['class']) + '">' + esc(st.name) + '</span></div>'
                + '<div class="t2">' + esc((h.needs || []).join(', ')) + ' · ' + esc(h.requester_name)
                + (h.area ? ' · ' + esc(h.area) : '') + (h.team_name ? ' · ทีม ' + esc(h.team_name) : '') + ' · ' + esc(h.ago) + '</div>'
                + '</a>';
        }).join(''));
    }

    function renderVuln(d) {
        var $el = $('#dashVuln');
        if (!$el.length) {
            return;
        }
        var rows = (d.vulnerable_map || []).slice().sort(function (a, b) {
            var wa = (a.evac_status === 'normal' || a.evac_status === 'alerted') ? 0 : 1;
            var wb = (b.evac_status === 'normal' || b.evac_status === 'alerted') ? 0 : 1;
            return wa - wb || FloodMap.level(a.zone_level).order - FloodMap.level(b.zone_level).order;
        }).slice(0, 10);
        if (!rows.length) {
            $el.html('<div class="empty-state"><i class="fa fa-check-circle"></i>ไม่มีบุคคลในทะเบียนอยู่ในพื้นที่ประกาศ</div>');
            return;
        }
        $el.html(rows.map(function (v) {
            var ev = opt.evac[v.evac_status] || { name: v.evac_status, 'class': 'label-default' };
            return '<a class="dash-list-item" href="' + Flood.url('flood/vulnerable?q=' + encodeURIComponent(v.name)) + '">'
                + '<div class="t1">' + esc(v.name) + ' <span class="label ' + esc(ev['class']) + '">' + esc(ev.name) + '</span></div>'
                + '<div class="t2">' + esc((v.groups || []).join(', ')) + ' · ' + Flood.levelBadge(v.zone_level) + ' ' + esc(v.zone_name) + '</div>'
                + '</a>';
        }).join(''));
    }

    function renderAll(d) {
        renderKpis(d);
        renderLayers(d);
        renderHelp(d);
        renderVuln(d);
        $('#dashUpdated').text(d.updated_th || '');
    }

    var d0 = window.DASH || {};
    if (d0.help_map && d0.help_map.length !== undefined && d0.role !== 'viewer') {
        overlays['คำขอช่วยเหลือ (ยังเปิด)'] = groups.help;
    }
    if (d0.reports_map) {
        overlays['รายงานรอตรวจ'] = groups.reports;
    }
    if (d0.vulnerable_map) {
        overlays['กลุ่มเปราะบางในพื้นที่'] = groups.vuln;
    }
    L.control.layers(null, overlays, { collapsed: window.innerWidth < 768 }).addTo(map);

    renderAll(d0);

    setInterval(function () {
        Flood.get('flood/dashboardData', {}, { silent: true }).then(renderAll);
    }, 60000);
});
