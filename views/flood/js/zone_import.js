/* นำเข้าพื้นที่ประกาศจาก arankub.com — ดึงข้อมูล → ตรวจบนแผนที่/ตาราง → เลือกแล้วนำเข้า (ครั้งเดียว) */
$(function () {
    'use strict';

    var esc = Flood.esc;
    var cfg = window.IMPORT_CFG;
    if (!cfg) {
        return;   // ยังไม่มีตาราง flood_zone_import — หน้าแสดงคำแนะนำอย่างเดียว
    }
    var levels = FloodMap.config.levels || {};
    var state = { token: '', items: [], levels: [], map: {} };
    var map = null;
    var candGroup = null;
    var oursGroup = null;
    var layers = {};
    var firstFit = false;

    function levelCodes() {
        return Object.keys(levels).sort(function (a, b) { return levels[a].order - levels[b].order; });
    }

    /** ระดับของเราที่จะใช้กับรายการนี้ (ตามที่เลือกในตาราง "เทียบระดับ") */
    function mappedLevel(it) {
        var m = state.map[it.level_key];
        return m && levels[m] ? m : it.level_default;
    }

    function distText(m) {
        return m < 1000 ? Math.round(m) + ' ม.' : (Math.round(m / 100) / 10) + ' กม.';
    }

    /* ---------- แผนที่ ---------- */

    function ensureMap() {
        if (map) {
            map.invalidateSize();
            return;
        }
        map = FloodMap.create('impMap');
        // พื้นที่ที่ประกาศอยู่ในระบบนี้ = สีเทาเส้นประ ไว้ดูว่าซ้ำ/ทับกันไหม
        oursGroup = L.featureGroup().addTo(map);
        (cfg.otherZones || []).forEach(function (z) {
            FloodMap.zoneLayer(z, {
                style: { color: '#64748b', fillColor: '#94a3b8', fillOpacity: 0.12, weight: 1.5, dashArray: '5 5' },
                extraHtml: function () {
                    return '<div class="small-muted" style="margin-top:4px">พื้นที่ที่ประกาศอยู่ในระบบนี้แล้ว</div>';
                }
            }).addTo(oursGroup);
        });
        candGroup = L.featureGroup().addTo(map);
        $('#impLegend').html(
            '<span><span class="sw" style="background:#cbd5e1;border:1px dashed #64748b"></span>ประกาศอยู่ในระบบนี้แล้ว</span>'
            + '<span class="small-muted">สีเต็ม = จะนำเข้า (สีตามระดับ) · เส้นประจาง = ไม่ได้เลือก / นำเข้าแล้ว</span>'
        );
    }

    function candidateZone(it) {
        // นำเข้าแล้ว = ใช้ระดับปัจจุบันของพื้นที่ในระบบนี้ (เจ้าหน้าที่อาจเปลี่ยนไปแล้ว) ไม่ใช่ตามตารางเทียบระดับ
        var level = it.state === 'imported' && it.zone ? it.zone.level : mappedLevel(it);
        return {
            zone_id: 'imp' + it.ext_id, name: it.name, level: level, shape: it.shape, center: it.center,
            radius_m: it.radius_m, polygon: it.polygon, amphoe_name: it.amphoe_name, tambon_name: it.tambon_name,
            note: it.note, started_th: it.started_th
        };
    }

    function renderMap() {
        ensureMap();
        candGroup.clearLayers();
        layers = {};
        var order = state.items.map(function (it, idx) { return idx; }).filter(function (idx) {
            return state.items[idx].state !== 'skip' && state.items[idx].center;
        });
        // ระดับรุนแรงวาดทีหลัง (อยู่บนสุด) · ที่เลือกไว้อยู่บนที่ไม่ได้เลือก
        order.sort(function (a, b) {
            var x = state.items[a];
            var y = state.items[b];
            var on = (x.state === 'new' && x.checked ? 1 : 0) - (y.state === 'new' && y.checked ? 1 : 0);
            return on || (FloodMap.level(candidateZone(y).level).order - FloodMap.level(candidateZone(x).level).order);
        });
        order.forEach(function (idx) {
            var it = state.items[idx];
            var off = it.state !== 'new' || !it.checked;
            layers[idx] = FloodMap.zoneLayer(candidateZone(it), {
                style: off ? { dashArray: '4 6', fillOpacity: 0.05, opacity: 0.55 } : null,
                extraHtml: function () {
                    return '<div class="small-muted" style="margin-top:4px">' + esc(it.shape_text) + '</div>'
                        + (it.state === 'imported' ? '<div class="small-muted">นำเข้าแล้ว</div>'
                            : (off ? '<div class="small-muted">ไม่ได้เลือกนำเข้า</div>' : ''));
                }
            }).addTo(candGroup);
        });
        if (firstFit) {
            firstFit = false;
            var all = candGroup.getLayers().length ? candGroup.getLayers() : oursGroup.getLayers();
            if (!FloodMap.fitLayers(map, all, 15)) {
                map.setView(FloodMap.config.center, FloodMap.config.zoom);
            }
        }
    }

    /* ---------- ตาราง ---------- */

    function levelOptions(selected) {
        return levelCodes().map(function (c) {
            return '<option value="' + esc(c) + '"' + (c === selected ? ' selected' : '') + '>' + esc(levels[c].name) + '</option>';
        }).join('');
    }

    function renderLevels() {
        if (!state.levels.length) {
            $('#impLevels').empty();
            return;
        }
        var html = '<div class="imp-levels-title">เทียบระดับ <span class="small-muted">— ระดับของ arankub ให้ใช้ระดับไหนของระบบนี้</span></div>'
            + '<div class="imp-level-rows">';
        state.levels.forEach(function (l) {
            var label = l.label || l.code;
            html += '<div class="imp-level-row">'
                + '<span class="imp-ext-level"><b>' + esc(label) + '</b> <span class="small-muted">'
                + (l.code !== 'NONE' && l.label ? esc(l.code) + ' · ' : '') + l.count + ' พื้นที่</span></span>'
                + '<i class="fa fa-long-arrow-right imp-arrow" aria-hidden="true"></i>'
                + '<select class="form-control input-sm js-imp-level" data-ext="' + esc(l.code) + '" aria-label="ระดับของระบบนี้สำหรับ '
                + esc(label) + '">' + levelOptions(state.map[l.code]) + '</select>'
                + '</div>';
        });
        $('#impLevels').html(html + '</div>');
    }

    function withAttribution(it) {
        return $('#impAttr').is(':checked') && it.state === 'new' && !/arankub/i.test(it.note || '');
    }

    function flagsHtml(it) {
        var out = '';
        if (it.state === 'imported' && it.zone) {
            out += '<div><span class="label label-success">นำเข้าแล้ว</span> <a href="' + Flood.url('flood/zoneForm/' + it.zone.zone_id) + '">'
                + esc(it.zone.name) + '</a>' + (it.zone.status === 'ended' ? ' <span class="small-muted">(ปิดประกาศแล้ว)</span>' : '') + '</div>';
        }
        if (it.state === 'skip') {
            out += '<div><span class="label label-default">ข้าม</span> ' + esc(it.reason) + '</div>';
        }
        (it.flags || []).forEach(function (f) {
            out += '<div class="imp-flag imp-flag-' + esc(f.t) + '"><i class="fa ' + (f.t === 'warn' ? 'fa-exclamation-triangle' : 'fa-info-circle')
                + '" aria-hidden="true"></i> ' + esc(f.m) + '</div>';
        });
        return out || '<span class="small-muted">—</span>';
    }

    function renderRows() {
        var html = '';
        if (!state.items.length) {
            html = '<tr><td colspan="7"><div class="empty-state"><i class="fa fa-map-o"></i>ต้นทางยังไม่มีพื้นที่ประกาศ</div></td></tr>';
        }
        state.items.forEach(function (it, idx) {
            var on = it.state === 'new' && it.checked;
            var pick = it.state === 'new'
                ? '<input type="checkbox" class="imp-pick js-imp-pick" data-idx="' + idx + '"' + (on ? ' checked' : '')
                    + ' aria-label="นำเข้า ' + esc(it.name) + '">'
                : '';
            var title = layers[idx]
                ? '<button type="button" class="imp-name js-imp-show" data-idx="' + idx + '">' + esc(it.name) + '</button>'
                : '<b>' + esc(it.name || it.ext_name || '(ไม่มีชื่อ)') + '</b>';
            var lv;
            if (it.state === 'imported' && it.zone) {
                lv = Flood.levelBadge(it.zone.level);
            } else if (it.state === 'new') {
                lv = Flood.levelBadge(mappedLevel(it));
            } else {
                lv = '<span class="small-muted">' + esc(it.ext_level || '—') + '</span>';
            }
            if (it.ext_level && it.state === 'new') {
                lv += '<div class="small-muted">arankub: ' + esc((cfg.levelLabels || {})[it.ext_level] || it.ext_level) + '</div>';
            }
            var area = (it.tambon_name ? 'ต.' + esc(it.tambon_name) + ' ' : '') + (it.amphoe_name ? 'อ.' + esc(it.amphoe_name) : '');
            var note = it.note ? esc(it.note) : '';
            if (withAttribution(it)) {
                note += '<span class="imp-attr-suffix">' + (note ? ' · ' : '') + esc(cfg.attribution) + '</span>';
            }
            html += '<tr class="imp-row imp-' + esc(it.state) + (on ? ' is-on' : '') + '">'
                + (pick ? '<td data-label="นำเข้า" class="nowrap">' + pick + '</td>' : '<td class="imp-nopick"></td>')
                + '<td data-label="พื้นที่">' + title + '<div class="small-muted">' + esc(it.shape_text || it.ext_shape || '') + '</div></td>'
                + '<td data-label="ระดับ" class="nowrap">' + lv + '</td>'
                + '<td data-label="อำเภอ / ตำบล">' + (area || '<span class="small-muted">—</span>') + '</td>'
                + '<td data-label="ข้อความ" class="imp-note">' + (note || '<span class="small-muted">—</span>') + '</td>'
                + '<td data-label="ประกาศ" class="nowrap">' + esc(it.started_th || '')
                + (it.ext_updated_th ? '<div class="small-muted">แก้ล่าสุด ' + esc(it.ext_updated_th) + '</div>' : '') + '</td>'
                + '<td data-label="ควรรู้" class="imp-flags">' + flagsHtml(it) + '</td>'
                + '</tr>';
        });
        $('#impRows').html(html);
    }

    function chip(label, n, cls) {
        return '<span class="imp-chip' + (cls ? ' imp-chip-' + cls : '') + '">' + esc(label) + ' <b>' + n + '</b></span>';
    }

    function renderSummary() {
        var c = { 'new': 0, imported: 0, skip: 0, picked: 0 };
        state.items.forEach(function (it) {
            c[it.state] = (c[it.state] || 0) + 1;
            if (it.state === 'new' && it.checked) {
                c.picked++;
            }
        });
        $('#impSummary').html(chip('พบทั้งหมด', state.items.length) + chip('นำเข้าได้', c['new'], 'ok')
            + chip('เคยนำเข้าแล้ว', c.imported) + chip('ข้าม', c.skip, c.skip ? 'warn' : '')
            + chip('เลือกไว้', c.picked, 'pick'));
        $('#impRunCount').text(c.picked);
        $('#impRun').prop('disabled', !state.token || c.picked === 0);
        $('#impAll, #impNone').prop('disabled', !state.token || c['new'] === 0);
        if (!state.token && c['new'] > 0) {
            $('#impRunHint').text('ถ้าต้องการนำเข้าเพิ่ม ให้กด "ดึงข้อมูล" อีกครั้ง');
        }
    }

    function renderAll() {
        renderMap();
        renderLevels();
        renderRows();
        renderSummary();
    }

    /* ---------- ดึงข้อมูล / ตรวจ ---------- */

    function preview(data, $btn) {
        Flood.busy($btn, true, data.mode === 'paste' ? 'กำลังตรวจข้อมูล…' : 'กำลังดึงข้อมูล…');
        Flood.post('flood/zoneImportPreview', data, { timeout: 60000 }).then(function (o) {
            Flood.busy($btn, false);
            state.token = o.token;
            state.items = o.items || [];
            state.levels = o.levels || [];
            state.map = {};
            state.levels.forEach(function (l) { state.map[l.code] = l['default']; });
            $('#impDone').remove();
            $('#impRunHint').text('พื้นที่ที่นำเข้าจะขึ้นแผนที่ประชาชนทันที');
            $('#impSourceTime').text(o.source_updated_th ? ' · ข้อมูล arankub ณ ' + o.source_updated_th : '');
            $('#impResult').prop('hidden', false);
            firstFit = true;
            renderAll();
            $('html,body').animate({ scrollTop: $('#impResult').offset().top - 70 }, 250);
        }, function (o) {
            Flood.busy($btn, false);
            // ดึงทางเซิร์ฟเวอร์ไม่ได้ → เปิดวิธีคัดลอกมาวางให้เลย
            if (o && o.mode === 'fetch') {
                $('#impPasteBox').prop('open', true);
            }
        });
    }

    $('#impFetch').on('click', function () {
        preview({ mode: 'fetch' }, $(this));
    });

    $('#impPasteForm').on('submit', function (e) {
        e.preventDefault();
        var json = $.trim($('#impJson').val());
        if (!json) {
            Flood.toast('วางข้อมูลจาก arankub.com/api/flood ลงในช่องก่อน', 'danger');
            $('#impJson').focus();
            return;
        }
        preview({ mode: 'paste', json: json }, $('#impPasteBtn'));
    });

    $('#impFile').on('change', function () {
        var f = this.files && this.files[0];
        this.value = '';
        if (!f) {
            return;
        }
        if (f.size > 3 * 1024 * 1024) {
            Flood.toast('ไฟล์ใหญ่เกิน 3 MB', 'danger');
            return;
        }
        var rd = new FileReader();
        rd.onload = function () {
            $('#impJson').val(String(rd.result || ''));
            $('#impPasteForm').trigger('submit');
        };
        rd.readAsText(f);
    });

    /* ---------- เลือก / เทียบระดับ ---------- */

    $('#impRows').on('change', '.js-imp-pick', function () {
        state.items[$(this).data('idx')].checked = this.checked;
        $(this).closest('tr').toggleClass('is-on', this.checked);   // ไม่วาดตารางใหม่ — โฟกัสคีย์บอร์ดอยู่ที่เดิม
        renderMap();
        renderSummary();
    });

    $('#impAll, #impNone').on('click', function () {
        var on = this.id === 'impAll';
        state.items.forEach(function (it) {
            if (it.state === 'new') {
                it.checked = on;
            }
        });
        renderMap();
        renderRows();
        renderSummary();
    });

    $('#impLevels').on('change', '.js-imp-level', function () {
        state.map[String($(this).data('ext'))] = $(this).val();
        renderMap();
        renderRows();
    });

    $('#impAttr').on('change', renderRows);

    $('#impRows').on('click', '.js-imp-show', function () {
        var layer = layers[$(this).data('idx')];
        if (!layer) {
            return;
        }
        map.fitBounds(layer.getBounds().pad(0.4), { maxZoom: 16 });
        layer.openPopup();
        $('html,body').animate({ scrollTop: $('#impMap').offset().top - 80 }, 200);
    });

    /* ---------- นำเข้า ---------- */

    $('#impRun').on('click', function () {
        var ids = [];
        var byLevel = {};
        state.items.forEach(function (it) {
            if (it.state === 'new' && it.checked) {
                ids.push(it.ext_id);
                var lv = mappedLevel(it);
                byLevel[lv] = (byLevel[lv] || 0) + 1;
            }
        });
        if (!ids.length || !state.token) {
            return;
        }
        var list = levelCodes().filter(function (c) { return byLevel[c]; }).map(function (c) {
            return '<li>' + Flood.levelBadge(c) + ' ' + byLevel[c] + ' พื้นที่</li>';
        }).join('');
        Flood.confirm({
            title: 'นำเข้าพื้นที่จาก arankub.com',
            html: '<p>นำเข้า <b>' + ids.length + '</b> พื้นที่ และแสดงบนแผนที่ประชาชนทันที</p>'
                + '<ul class="imp-confirm-list">' + list + '</ul>'
                + '<p class="small-muted" style="margin:0">แก้ไขหรือปิดประกาศภายหลังได้ที่หน้า "พื้นที่ประกาศ" ตามปกติ</p>',
            okText: 'นำเข้า ' + ids.length + ' พื้นที่'
        }, function () {
            return Flood.post('flood/zoneImportRun', {
                token: state.token, ids: ids, map: state.map, attribution: $('#impAttr').is(':checked') ? 1 : 0
            }, { timeout: 120000 }).then(function (o) {
                var done = o.done || {};
                state.items.forEach(function (it) {
                    if (done[it.ext_id]) {
                        it.zone = { zone_id: done[it.ext_id], name: it.name, level: mappedLevel(it), status: 'active' };
                        it.state = 'imported';
                        it.checked = false;
                    }
                });
                state.token = '';   // ตัวอย่างชุดนี้ใช้แล้ว
                renderAll();
                Flood.toast(o.msg, 'success', 6000);
                $('#impDone').remove();
                $('<div class="alert alert-success" id="impDone" role="status"><i class="fa fa-check"></i> ' + esc(o.msg)
                    + ' · <a href="' + Flood.url('flood/zones') + '">ไปหน้าพื้นที่ประกาศ</a></div>').insertBefore('#impResult');
                $('html,body').animate({ scrollTop: $('#impDone').offset().top - 80 }, 200);
            });
        });
    });
});
