/* ทีมช่วยเหลือ */
$(function () {
    'use strict';

    var teams = {};
    (window.TEAMS || []).forEach(function (t) { teams[t.team_id] = t; });

    $(document).on('click', '.js-team-edit', function () {
        var t = teams[$(this).data('team')] || { team_id: 0, name: '', team_type: 'rescue', phone: '', amphoe_code: '', vehicles: '', note: '', is_active: 1 };
        $('#teamTitle').text(t.team_id ? 'แก้ไขทีม' : 'เพิ่มทีม');
        $('#tId').val(t.team_id);
        $('#tName').val(t.name);
        $('#tType').val(t.team_type);
        $('#tPhone').val(t.phone);
        Flood.setAmphoe('#tAmphoe', t.amphoe_code);
        $('#tVehicles').val(t.vehicles);
        $('#tNote').val(t.note);
        $('#tActive').prop('checked', !!t.is_active);
        $('#teamModal').modal('show');
    });

    $('#teamForm').on('submit', function (e) {
        e.preventDefault();
        var $btn = $('#tSave');
        Flood.busy($btn, true);
        var data = $(this).serialize() + '&is_active=' + ($('#tActive').is(':checked') ? 1 : 0);
        Flood.post('flood/saveTeam', data).then(function (o) {
            Flood.toast(o.msg, 'success');
            setTimeout(function () { window.location.reload(); }, 500);
        }, function () {
            Flood.busy($btn, false);
        });
    });
});
