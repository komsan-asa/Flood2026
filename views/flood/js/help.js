/* รายการคำขอความช่วยเหลือ — แผนที่เปิด/ปิดได้ (จำค่าไว้ในเครื่อง) */
$(function () {
    'use strict';

    var esc = Flood.esc;
    var PRI_COLOR = { urgent: '#dc2626', high: '#f59e0b', normal: '#64748b' };
    var map = null;
    var KEY = 'flood_help_map_open';

    function buildMap() {
        if (map) {
            map.invalidateSize();
            return;
        }
        map = FloodMap.create('helpMap');
        var g = L.featureGroup().addTo(map);
        (window.HELP_MAP || []).forEach(function (h) {
            var pr = window.HELP_PRI[h.priority] || { name: h.priority };
            L.marker([h.lat, h.lng], { icon: FloodMap.pinIcon(PRI_COLOR[h.priority] || '#64748b', 'fa-life-ring') })
                .bindPopup('<div class="pop-title">' + esc(h.ref_code) + '</div>' + esc(pr.name) + ' · ' + esc(h.status_name)
                    + '<div>' + esc((h.needs_names || []).join(', ')) + '</div>'
                    + (h.team_name ? '<div class="small-muted">ทีม: ' + esc(h.team_name) + '</div>' : '')
                    + '<div class="small-muted">' + esc(h.ago) + '</div>'
                    + '<div style="margin-top:6px"><a href="' + Flood.url('flood/helpView/' + h.help_id) + '">เปิดใบงาน</a></div>')
                .addTo(g);
        });
        FloodMap.fitLayers(map, g.getLayers(), 15);
    }

    function setOpen(open) {
        $('#helpMapCard').toggleClass('hidden', !open);
        $('#toggleHelpMap').toggleClass('active', open);
        if (open) {
            buildMap();
        }
        try { localStorage.setItem(KEY, open ? '1' : '0'); } catch (e) { /* ไม่เป็นไร */ }
    }

    $('#toggleHelpMap').on('click', function () {
        setOpen($('#helpMapCard').hasClass('hidden'));
    });

    var saved = '0';
    try { saved = localStorage.getItem(KEY) || '0'; } catch (e) { /* ไม่เป็นไร */ }
    if (saved === '1') {
        setOpen(true);
    }

    // การรีเฟรชหน้าอัตโนมัติย้ายไปใช้ตัวนับเวลาบนแถบด้านบน (public/js/flood-refresh.js) ทุกหน้า
});
