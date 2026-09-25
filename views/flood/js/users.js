/* จัดการผู้ใช้งาน */
$(function () {
    'use strict';

    var users = {};
    (window.USERS || []).forEach(function (u) { users[u.user_id] = u; });

    function syncRole() {
        var r = $('#uRole').val();
        $('#uTeamGroup').toggleClass('hidden', r !== 'team');
        $('#uRoleDesc').text((window.ROLE_DESC || {})[r] || '');
    }
    $('#uRole').on('change', syncRole);

    $(document).on('click', '.js-user-edit', function () {
        var id = $(this).data('user');
        var u = users[id] || { user_id: 0, loginname: '', name: '', role: 'officer', team_id: '', org_name: '', phone: '', is_active: 1 };
        var isNew = !u.user_id;
        $('#userTitle').text(isNew ? 'เพิ่มผู้ใช้' : 'แก้ไขผู้ใช้: ' + u.name);
        $('#uId').val(u.user_id);
        $('#uLogin').val(u.loginname).prop('readonly', !isNew);
        $('#uPass').val('');
        $('#uPassLabel').text(isNew ? 'รหัสผ่านเริ่มต้น' : 'ตั้งรหัสผ่านใหม่ (ถ้าต้องการ)');
        $('#uPassHelp').text(isNew
            ? 'อย่างน้อย 8 ตัว — ผู้ใช้ต้องเปลี่ยนเองตอนเข้าใช้ครั้งแรก'
            : 'เว้นว่าง = ใช้รหัสเดิม · ถ้าตั้งใหม่ ผู้ใช้ต้องเปลี่ยนเองอีกครั้งตอนเข้าใช้');
        $('#uName').val(u.name);
        $('#uRole').val(u.role);
        $('#uTeam').val(u.team_id);
        $('#uOrg').val(u.org_name);
        $('#uPhone').val(u.phone);
        $('#uActive').prop('checked', !!u.is_active);
        var self = u.user_id === window.ME_ID;
        $('#uRole, #uActive').prop('disabled', self);
        syncRole();
        $('#userModal').modal('show');
    });

    $('#userForm').on('submit', function (e) {
        e.preventDefault();
        var $btn = $('#uSave');
        Flood.busy($btn, true);
        // select/checkbox ที่ disabled (แก้บัญชีตัวเอง) ไม่ถูกส่งไป — เติมค่าเดิมให้
        var data = $(this).serializeArray();
        if ($('#uRole').prop('disabled')) {
            data.push({ name: 'role', value: $('#uRole').val() });
        }
        data.push({ name: 'is_active', value: $('#uActive').is(':checked') ? 1 : 0 });
        Flood.post('flood/saveUser', $.param(data)).then(function (o) {
            Flood.toast(o.msg, 'success');
            setTimeout(function () { window.location.reload(); }, 500);
        }, function () {
            Flood.busy($btn, false);
        });
    });
});
