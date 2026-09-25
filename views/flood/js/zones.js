/* รายการพื้นที่ประกาศ */
$(function () {
    'use strict';

    var zones = window.ZONES || [];
    var map = FloodMap.create('zonesMap');
    var group = L.featureGroup().addTo(map);
    var byId = {};

    $('#zonesLegend').html(FloodMap.legendHtml() + '<span class="small-muted">เส้นประ = ปิดประกาศแล้ว</span>');

    zones.slice().sort(function (a, b) {
        return FloodMap.level(b.level).order - FloodMap.level(a.level).order;
    }).forEach(function (z) {
        var ended = z.status === 'ended';
        var layer = FloodMap.zoneLayer(z, {
            style: ended ? { dashArray: '6 6', fillOpacity: 0.08 } : null,
            extraHtml: window.ZONES_CAN_EDIT ? function (zz) {
                return '<div style="margin-top:6px"><a href="' + Flood.url('flood/zoneForm/' + zz.zone_id) + '">แก้ไขพื้นที่</a></div>';
            } : null
        }).addTo(group);
        byId[z.zone_id] = layer;
    });
    FloodMap.fitLayers(map, group.getLayers(), 15);

    $(document).on('click', '.js-zone-show', function () {
        var layer = byId[$(this).data('id')];
        if (!layer) {
            return;
        }
        map.fitBounds(layer.getBounds().pad(0.3), { maxZoom: 16 });
        layer.openPopup();
        $('html,body').animate({ scrollTop: $('#zonesMap').offset().top - 70 }, 200);
    });

    $(document).on('click', '.js-zone-status', function () {
        var $b = $(this);
        var ending = $b.data('status') === 'ended';
        Flood.confirm({
            title: ending ? 'ปิดประกาศพื้นที่' : 'ประกาศพื้นที่อีกครั้ง',
            message: ending
                ? 'ยืนยันว่าน้ำลดแล้วที่ "' + $b.data('name') + '" — พื้นที่นี้จะหายจากแผนที่สาธารณะ'
                : 'นำ "' + $b.data('name') + '" กลับมาแสดงบนแผนที่สาธารณะอีกครั้ง',
            okText: ending ? 'ปิดประกาศ' : 'ประกาศอีกครั้ง',
            okClass: ending ? 'btn-success' : 'btn-primary'
        }, function () {
            return Flood.post('flood/zoneStatus', { zone_id: $b.data('id'), status: $b.data('status') }).then(function (o) {
                Flood.toast(o.msg, 'success');
                setTimeout(function () { window.location.reload(); }, 600);
            });
        });
    });
});
