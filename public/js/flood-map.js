/*
 * ตัวช่วยแผนที่ (Leaflet) ที่ใช้ร่วมกันทุกหน้า
 * ค่าตั้งต้น (กึ่งกลาง/ซูม/สีระดับพื้นที่) มาจาก window.FLOOD_MAP ที่ header.php ใส่ไว้
 */
(function (window, $) {
    'use strict';

    var FloodMap = window.FloodMap = {};
    var cfg = window.FLOOD_MAP || { center: [13.8, 102.3], zoom: 9, levels: {} };
    var esc = function (s) { return window.Flood ? window.Flood.esc(s) : String(s); };

    FloodMap.config = cfg;

    // flyTo ระหว่างที่แผนที่ยังซูมค้าง (เช่น fitBounds ตอนเปิดหน้ายังไม่จบ แล้วผู้ใช้แตะรายการพื้นที่ทันที)
    // Leaflet จะบินไปผิดที่ พื้นที่หลุดจอ — ให้รอซูมเสร็จก่อนแล้วค่อยบิน (มีผลทุกหน้าที่ใช้ไฟล์นี้)
    if (window.L && L.Map && !L.Map.prototype._floodSafeFly) {
        var leafletFlyTo = L.Map.prototype.flyTo;
        L.Map.prototype.flyTo = function () {
            var self = this;
            var args = arguments;
            if (this._animatingZoom) {
                this.once('zoomend', function () {
                    setTimeout(function () { leafletFlyTo.apply(self, args); }, 0);
                });
                return this;
            }
            return leafletFlyTo.apply(this, args);
        };
        L.Map.prototype._floodSafeFly = true;

        // L.Circle#getBounds ของ Leaflet คำนวณจากพิกัดบนจอที่คำนวณไว้ครั้งก่อน — ถ้าเรียกตอนแผนที่กำลังซูม
        // จะได้ขอบเขตผิดที่ (flyToBounds บินไปผิดจุด) → คำนวณจากพิกัดจริง + รัศมีเป็นเมตรแทน ถูกต้องทุกเวลา
        L.Circle.prototype.getBounds = function () {
            return this._latlng.toBounds(2 * this._mRadius);
        };
    }

    FloodMap.create = function (el, opts) {
        opts = opts || {};
        var map = L.map(el, {
            zoomControl: true,
            scrollWheelZoom: opts.scrollWheelZoom !== false,
            tap: true
        }).setView(opts.center || cfg.center, opts.zoom || cfg.zoom);
        L.tileLayer(cfg.tileUrl, { maxZoom: 19, attribution: cfg.attribution }).addTo(map);
        // แผนที่อยู่ในแท็บ/กล่องที่เพิ่งแสดง — ให้คำนวณขนาดใหม่
        setTimeout(function () { map.invalidateSize(); }, 200);
        return map;
    };

    /** levels = ระดับที่เปิดใช้ · levelsAll = รวมที่ผู้ดูแลปิดไปแล้ว (พื้นที่เก่ายังแสดงชื่อ/สีได้) */
    FloodMap.level = function (code) {
        return (cfg.levels || {})[code] || (cfg.levelsAll || {})[code]
            || { name: code, color: '#64748b', badge: '#475569', text: '#475569', icon: 'fa-circle', order: 999 };
    };

    FloodMap.zoneStyle = function (level, extra) {
        var c = FloodMap.level(level).color;
        return $.extend({ color: c, weight: 2, opacity: 0.95, fillColor: c, fillOpacity: 0.28 }, extra || {});
    };

    /* ---------- ภาพประกอบพื้นที่ ---------- */

    var gallery = {};   // zone_id → { title, photos } ให้ตัวดูภาพเต็มจอ

    // สไตล์ภาพในบอลลูน/ตัวดูภาพเต็มจอ — header.php ใส่ลิงก์ไว้แล้ว เผื่อ header ถูกแก้จนลิงก์หายให้โหลดเอง
    if (!document.querySelector('link[href*="public/css/flood-map.css"]')) {
        var fmCss = document.createElement('link');
        fmCss.rel = 'stylesheet';
        fmCss.href = (window.BASE_URL || '/') + 'public/css/flood-map.css';
        document.head.appendChild(fmCss);
    }

    /** ภาพผ่าน api ที่ตรวจว่าพื้นที่ยังประกาศอยู่ — thumb = ภาพย่อ 640px สำหรับบอลลูน */
    FloodMap.photoUrl = function (id, thumb) {
        return (window.BASE_URL || '/') + 'api/' + (thumb ? 'zoneThumb/' : 'zonePhoto/') + encodeURIComponent(id);
    };

    function zonePhotos(z) {
        return $.isArray(z.photos) ? z.photos : [];
    }

    /** ภาพในบอลลูนโหลดไม่ได้ (เช่น เพิ่งปิดประกาศ) — เอากรอบภาพออก ไม่ให้เห็นรูปแตก */
    function watchPopupPhoto(popup) {
        var el = popup.getElement ? popup.getElement() : null;
        var img = el ? el.querySelector('.fm-pop-photo img') : null;
        if (!img) {
            return;
        }
        var drop = function () {
            $(img).closest('.fm-pop-photo').remove();
            popup.update();
        };
        if (img.complete && !img.naturalWidth) {
            drop();
        } else {
            $(img).off('error.fm').one('error.fm', drop);
        }
    }

    /** รอให้แผนที่หยุดเลื่อน/ซูม (เช่น flyTo ของหน้ารายการ) แล้วค่อยทำ fn — ผู้ใช้ลากต่อเนื่องเกิน 2.5 วิ = เลิกรอ */
    function whenSettled(map, fn) {
        var timer = null;
        var until = Date.now() + 2500;
        var evts = 'movestart move moveend zoomstart zoomend';
        function stop() {
            clearTimeout(timer);
            map.off(evts, arm);
        }
        function arm() {
            clearTimeout(timer);
            if (Date.now() > until) {
                stop();
                return;
            }
            timer = setTimeout(function () { stop(); fn(); }, 300);
        }
        map.on(evts, arm);
        arm();
        return stop;
    }

    /**
     * autoPan ของ Leaflet ไม่รู้ว่ามีแถบหัวเว็บ/แผงข้อมูล/ปุ่มลอยทับแผนที่อยู่
     * ตรวจจากจอจริงว่าขอบบน/ซ้าย/ขวาของบอลลูนโดนอะไรบัง แล้วเลื่อนแผนที่ให้เห็นทั้งใบ (ให้ขอบบนมาก่อน — ภาพ/ชื่ออยู่บน)
     */
    function revealPopup(map, popup, tries) {
        var el = popup.getElement ? popup.getElement() : null;
        var box = el ? el.querySelector('.leaflet-popup-content-wrapper') : null;   // ไม่รวมหางบอลลูน (pointer-events: none)
        if (!box || !popup.isOpen()) {
            return;
        }
        // ยังซูมอยู่ หรือจุดของพื้นที่อยู่นอกแผนที่ (แอนิเมชันยังไม่จบ) — วัดตอนนี้จะผิด รอแล้วลองใหม่
        var size = map.getSize();
        var p = map.latLngToContainerPoint(popup.getLatLng());
        if (map._animatingZoom || p.x < 0 || p.y < 0 || p.x > size.x || p.y > size.y) {
            tries = (tries || 0) + 1;
            if (tries <= 4) {
                setTimeout(function () { revealPopup(map, popup, tries); }, 350);
            }
            return;
        }
        var r = box.getBoundingClientRect();
        var seen = function (x, y) {
            var hit = document.elementFromPoint(x, y);
            return !!hit && el.contains(hit);
        };
        var cx = r.left + r.width / 2;
        var cy;
        var x, y;
        var dx = 0;
        var dy = 0;
        var hidden = null;
        var topHidden = null;
        // ครึ่งบน (ภาพ + ชื่อ): จุดล่างสุดที่ยังโดนบัง → เลื่อนลงให้พ้น (ขอบจอ / แถบหัวเว็บที่ลอยอยู่)
        for (y = r.top + 2; y < r.top + Math.min(r.height * 0.6, 220); y += 4) {
            if (!seen(cx, y)) {
                topHidden = y;
            }
        }
        if (topHidden !== null) {
            dy = topHidden - r.top + 8;
        } else {
            // ปลายบอลลูนจมใต้แผงด้านล่าง → เลื่อนขึ้น
            hidden = null;
            for (y = r.bottom - 4; y > r.bottom - Math.min(r.height * 0.4, 120); y -= 4) {
                if (!seen(cx, y)) {
                    hidden = y;
                }
            }
            dy = hidden !== null ? -(r.bottom - hidden + 8) : 0;
        }
        // ครึ่งซ้าย: แผงข้อมูลบัง → เลื่อนไปทางขวา / ครึ่งขวา: ปุ่มลอย คำอธิบายสี → เลื่อนไปทางซ้าย
        // ตรวจที่แถวใต้ส่วนที่โดนแถบด้านบนบัง (ไม่งั้นแถบหัวเว็บที่กว้างเต็มจอจะนับเป็นบังด้านข้างด้วย)
        cy = Math.max(r.top + 12, Math.min((topHidden !== null ? topHidden : r.top) + 40, r.bottom - 12));
        hidden = null;
        for (x = r.left + 2; x < r.left + r.width / 2; x += 4) {
            if (!seen(x, cy)) {
                hidden = x;
            }
        }
        if (hidden !== null) {
            dx = hidden - r.left + 8;
        } else {
            for (x = r.right - 2; x > r.left + r.width / 2; x -= 4) {
                if (!seen(x, cy)) {
                    hidden = x;
                }
            }
            dx = hidden !== null ? -(r.right - hidden + 8) : 0;
        }
        if (!dx && !dy) {
            return;
        }
        // อย่าเลื่อนจนจุดของพื้นที่หลุดขอบแผนที่
        dx = Math.max(Math.min(dx, size.x - 12 - p.x), 12 - p.x);
        dy = Math.max(Math.min(dy, size.y - 12 - p.y), 12 - p.y);
        map.panBy([-dx, -dy], { animate: true, duration: 0.25 });
    }

    /** z = {shape, center:[lat,lng], radius_m, polygon:[[lat,lng],...], level, name, note, photos:[id,...], ...} */
    FloodMap.zoneLayer = function (z, opts) {
        opts = opts || {};
        var style = FloodMap.zoneStyle(z.level, opts.style);
        var layer;
        if (z.shape === 'polygon' && z.polygon && z.polygon.length >= 3) {
            layer = L.polygon(z.polygon, style);
        } else {
            layer = L.circle(z.center, $.extend({ radius: z.radius_m || 100 }, style));
        }
        if (opts.popup !== false) {
            var withPhoto = zonePhotos(z).length > 0;
            // มีภาพ → บอลลูนกว้างคงที่ ภาพไม่บีบตามความยาวชื่อพื้นที่
            // และปิด autoPan ของ Leaflet: หน้ารายการเปิดบอลลูนระหว่าง flyTo — autoPan ชนกับ flyTo แล้วบอลลูนหลุดจอ
            // (revealPopup ด้านล่างเลื่อนให้เห็นทั้งใบหลังแผนที่หยุดนิ่งแทน)
            layer.bindPopup(FloodMap.zonePopup(z, opts), withPhoto ? { minWidth: 250, maxWidth: 290, autoPan: false } : {});
            if (withPhoto) {
                layer.on('popupopen', function (e) {
                    var map = this._map;
                    watchPopupPhoto(e.popup);
                    if (map) {
                        var stop = whenSettled(map, function () { revealPopup(map, e.popup); });
                        this.once('popupclose', stop);
                    }
                });
            }
        }
        return layer;
    };

    /** ข้อความพื้นที่ ต./อ. (กรุงเทพฯ ใช้ แขวง/เขต) — คืนข้อความดิบ ให้ผู้เรียก esc เอง */
    FloodMap.areaLabel = function (z) {
        var bkk = String(z.amphoe_code || '').indexOf('10') === 0;
        return ((z.tambon_name ? (bkk ? 'แขวง' : 'ต.') + z.tambon_name + ' ' : '')
            + (z.amphoe_name ? (bkk ? (String(z.amphoe_name).indexOf('เขต') === 0 ? '' : 'เขต') : 'อ.') + z.amphoe_name : '')).trim();
    };

    /** หมายเหตุพื้นที่ → HTML ที่ escape แล้ว และเปลี่ยน URL เป็นลิงก์ "เปิดแหล่งข่าว" */
    FloodMap.linkNote = function (s) {
        var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return map[c]; })
            .replace(/https?:\/\/[^\s<>"']+/g, function (u) {
                return '<a href="' + u + '" target="_blank" rel="noopener nofollow">เปิดแหล่งข่าว ↗</a>';
            });
    };

    /** ป้ายพื้นที่ที่นำเข้าจากข่าว/โซเชียล (ยังไม่ตรวจสอบ) */
    FloodMap.pendingTag = function (z) {
        return z && z.source === 'web' ? ' <span class="sk-tag-pending">⏳ รอตรวจสอบ</span>' : '';
    };

    FloodMap.zonePopup = function (z, opts) {
        var lv = FloodMap.level(z.level);
        var area = esc(FloodMap.areaLabel(z));
        var photos = zonePhotos(z);
        var html = '';
        if (photos.length) {
            gallery[z.zone_id] = { title: z.name, photos: photos.slice() };
            html += '<button type="button" class="fm-pop-photo" data-zone="' + esc(z.zone_id) + '" aria-label="ดูภาพขนาดใหญ่'
                + (photos.length > 1 ? ' (' + photos.length + ' ภาพ)' : '') + '">'
                + '<img src="' + FloodMap.photoUrl(photos[0], true) + '" alt="ภาพพื้นที่ ' + esc(z.name) + '" decoding="async">'
                + '<span class="fm-pop-chip" aria-hidden="true">'
                + (photos.length > 1 ? '<i class="fa fa-clone"></i> ' + photos.length + ' ภาพ' : '<i class="fa fa-expand"></i> ขยาย')
                + '</span></button>';
        }
        html += '<div class="pop-title">' + esc(z.name) + '</div>'
            + '<span class="lv-badge lv-' + esc(z.level) + '" style="background:' + esc(lv.badge || '#475569') + '">' + esc(lv.name) + '</span>' + FloodMap.pendingTag(z)
            + (area ? '<div class="small-muted" style="margin-top:4px">' + area + '</div>' : '')
            + (z.note ? '<div class="fm-note" style="margin-top:4px">' + FloodMap.linkNote(z.note) + '</div>' : '')
            + (z.started_th ? '<div class="small-muted" style="margin-top:4px">ประกาศ ' + esc(z.started_th) + '</div>' : '');
        if (opts && typeof opts.extraHtml === 'function') {
            html += opts.extraHtml(z);
        }
        return html;
    };

    /**
     * ดูภาพเต็มจอ: ลูกศร/ปัดซ้าย-ขวาเปลี่ยนภาพ · Esc หรือแตะพื้นที่ว่างเพื่อปิด
     * photos = [attachment_id, ...] ของภาพประกอบพื้นที่
     */
    FloodMap.lightbox = function (photos, start, title) {
        if (!photos || !photos.length) {
            return;
        }
        var i = Math.max(0, Math.min(start || 0, photos.length - 1));
        var multi = photos.length > 1;
        var back = document.activeElement;
        var $lb = $('<div class="fm-lb" role="dialog" aria-modal="true" tabindex="-1"></div>').attr('aria-label', 'ภาพพื้นที่ ' + (title || ''));
        $lb.html(
            '<div class="fm-lb-bar"><div class="fm-lb-title"></div><div class="fm-lb-count" aria-live="polite"></div>'
            + '<button type="button" class="fm-lb-btn fm-lb-close" aria-label="ปิด"><i class="fa fa-times" aria-hidden="true"></i></button></div>'
            + '<div class="fm-lb-stage"><img class="fm-lb-img" alt="" draggable="false"><div class="fm-lb-state"></div></div>'
            + (multi
                ? '<button type="button" class="fm-lb-btn fm-lb-nav fm-lb-prev" aria-label="ภาพก่อนหน้า"><i class="fa fa-chevron-left" aria-hidden="true"></i></button>'
                + '<button type="button" class="fm-lb-btn fm-lb-nav fm-lb-next" aria-label="ภาพถัดไป"><i class="fa fa-chevron-right" aria-hidden="true"></i></button>'
                : '')
        );
        $lb.find('.fm-lb-title').text(title || '');
        var $img = $lb.find('.fm-lb-img');
        var $state = $lb.find('.fm-lb-state');

        function show(n) {
            i = (n + photos.length) % photos.length;
            $lb.find('.fm-lb-count').text(multi ? (i + 1) + ' / ' + photos.length : '');
            $state.html('<i class="fa fa-spinner fa-spin" aria-hidden="true"></i>').show();
            $img.addClass('is-loading')
                .attr('alt', 'ภาพที่ ' + (i + 1) + ' จาก ' + photos.length + ' · ' + (title || ''))
                .attr('src', FloodMap.photoUrl(photos[i], false));
            if (multi) {
                (new Image()).src = FloodMap.photoUrl(photos[(i + 1) % photos.length], false);   // โหลดภาพถัดไปรอไว้
            }
        }
        $img.on('load', function () {
            $state.hide();
            $img.removeClass('is-loading');
        }).on('error', function () {
            $state.text('โหลดภาพไม่ได้ — ภาพอาจถูกนำออกแล้ว').show();
        });

        function close() {
            $(document).off('keydown.fmlb');
            $('body').removeClass('fm-lb-open');
            $lb.remove();
            if (back && back.focus && document.body.contains(back)) {
                back.focus();
            }
        }

        $lb.on('click', '.fm-lb-close', close);
        $lb.on('click', '.fm-lb-prev', function () { show(i - 1); });
        $lb.on('click', '.fm-lb-next', function () { show(i + 1); });
        $lb.on('click', function (e) {
            if (e.target === this || $(e.target).is('.fm-lb-stage, .fm-lb-state')) {
                close();
            }
        });
        $(document).on('keydown.fmlb', function (e) {
            var k = e.key || '';
            if (k === 'Escape' || e.keyCode === 27) {
                e.preventDefault();
                close();
            } else if (multi && (k === 'ArrowLeft' || e.keyCode === 37)) {
                show(i - 1);
            } else if (multi && (k === 'ArrowRight' || e.keyCode === 39)) {
                show(i + 1);
            } else if (k === 'Tab' || e.keyCode === 9) {
                // โฟกัสวนอยู่ในหน้าต่างภาพ
                var btns = $lb.find('button').get();
                var at = btns.indexOf(document.activeElement);
                e.preventDefault();
                btns[(at + (e.shiftKey ? -1 : 1) + btns.length) % btns.length].focus();
            }
        });

        // ปัดซ้าย/ขวาบนมือถือ
        var x0 = null;
        $lb.on('touchstart', '.fm-lb-stage', function (e) {
            var t = e.originalEvent.touches;
            x0 = t && t.length === 1 ? t[0].clientX : null;
        });
        $lb.on('touchend', '.fm-lb-stage', function (e) {
            var t = e.originalEvent.changedTouches;
            if (x0 === null || !multi || !t || !t.length) {
                return;
            }
            var dx = t[0].clientX - x0;
            x0 = null;
            if (Math.abs(dx) > 45) {
                e.preventDefault();
                show(dx < 0 ? i + 1 : i - 1);
            }
        });

        $('body').addClass('fm-lb-open').append($lb);
        show(i);
        $lb.find('.fm-lb-close').trigger('focus');
    };

    // แตะภาพในบอลลูน → ดูภาพเต็มจอ (บอลลูนสร้างใหม่ทุกครั้งที่เปิด จึงผูกกับ document)
    $(document).on('click', '.fm-pop-photo', function (e) {
        e.preventDefault();
        var g = gallery[$(this).data('zone')];
        if (g) {
            FloodMap.lightbox(g.photos, 0, g.title);
        }
    });

    FloodMap.pinIcon = function (color, fa) {
        return L.divIcon({
            className: 'flood-pin',
            html: '<div class="pin" style="background:' + color + '"><i class="fa ' + (fa || 'fa-circle') + '"></i></div>',
            iconSize: [30, 30],
            iconAnchor: [15, 30],
            popupAnchor: [0, -28]
        });
    };

    FloodMap.dotIcon = function (color) {
        return L.divIcon({
            className: 'flood-dot-pin',
            html: '<div class="dot" style="background:' + color + '"></div>',
            iconSize: [16, 16],
            iconAnchor: [8, 8],
            popupAnchor: [0, -8]
        });
    };

    /**
     * ขอพิกัดจากเครื่อง — เบราว์เซอร์ให้ใช้ได้เฉพาะเว็บ https (หรือ localhost)
     * ใช้ไม่ได้ก็ให้ผู้ใช้แตะบนแผนที่ปักหมุดเองแทน
     */
    FloodMap.locate = function (onOk, onErr) {
        if (!navigator.geolocation) {
            if (onErr) { onErr('เครื่องนี้ไม่รองรับการระบุตำแหน่ง — กรุณาแตะบนแผนที่เพื่อปักหมุดแทน'); }
            return;
        }
        if (window.isSecureContext === false) {
            if (onErr) { onErr('เว็บนี้ยังไม่ใช่ https เบราว์เซอร์จึงไม่ให้ใช้ตำแหน่งอัตโนมัติ — กรุณาแตะบนแผนที่เพื่อปักหมุดแทน'); }
            return;
        }
        navigator.geolocation.getCurrentPosition(function (p) {
            onOk(p.coords.latitude, p.coords.longitude, p.coords.accuracy);
        }, function (e) {
            var m = e.code === 1
                ? 'ไม่ได้อนุญาตให้ใช้ตำแหน่ง — เปิดสิทธิ์ตำแหน่งในเบราว์เซอร์ หรือแตะบนแผนที่เพื่อปักหมุดแทน'
                : 'หาตำแหน่งไม่สำเร็จ — ลองใหม่ หรือแตะบนแผนที่เพื่อปักหมุดแทน';
            if (onErr) { onErr(m); }
        }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 30000 });
    };

    FloodMap.fitLayers = function (map, layers, maxZoom) {
        if (!layers || !layers.length) {
            return false;
        }
        var g = L.featureGroup(layers);
        map.fitBounds(g.getBounds().pad(0.15), { maxZoom: maxZoom || 15 });
        return true;
    };

    FloodMap.legendHtml = function () {
        var out = [];
        var codes = Object.keys(cfg.levels).sort(function (a, b) { return cfg.levels[a].order - cfg.levels[b].order; });
        codes.forEach(function (c) {
            out.push('<span><span class="sw" style="background:' + cfg.levels[c].color + '"></span>' + esc(cfg.levels[c].name) + '</span>');
        });
        return out.join('');
    };

    /**
     * แผนที่เลือกตำแหน่ง (ฟอร์มแจ้งจุดน้ำ / ขอความช่วยเหลือ / ทะเบียน)
     * opts: lat, lng, acc, method = jQuery ของ input ซ่อน / status = กล่องข้อความสถานะ
     *       initial = [lat, lng] ค่าเดิม (ถ้ามี) / onChange(lat, lng, method)
     */
    FloodMap.picker = function (el, opts) {
        opts = opts || {};
        var map = FloodMap.create(el, {
            center: opts.initial || cfg.center,
            zoom: opts.initial ? 16 : (opts.zoom || cfg.zoom)
        });
        var marker = null;
        var accCircle = null;

        function status(text, cls) {
            if (opts.status) {
                opts.status.removeClass('ok err').addClass(cls || '').html(text);
            }
        }

        function set(lat, lng, acc, method, pan) {
            lat = Math.round(lat * 1e7) / 1e7;
            lng = Math.round(lng * 1e7) / 1e7;
            if (!marker) {
                marker = L.marker([lat, lng], { draggable: true, icon: FloodMap.pinIcon('#dc2626', 'fa-map-marker') }).addTo(map);
                marker.on('dragend', function () {
                    var p = marker.getLatLng();
                    set(p.lat, p.lng, null, 'pin', false);
                });
            } else {
                marker.setLatLng([lat, lng]);
            }
            if (accCircle) {
                map.removeLayer(accCircle);
                accCircle = null;
            }
            if (acc && acc > 0) {
                accCircle = L.circle([lat, lng], { radius: acc, color: '#1f78c1', weight: 1, fillOpacity: 0.08 }).addTo(map);
            }
            if (opts.lat) { opts.lat.val(lat); }
            if (opts.lng) { opts.lng.val(lng); }
            if (opts.acc) { opts.acc.val(acc ? Math.round(acc) : ''); }
            if (opts.method) { opts.method.val(method || 'pin'); }
            if (pan) {
                map.setView([lat, lng], Math.max(map.getZoom(), 17));
            }
            var txt = method === 'gps'
                ? '<i class="fa fa-check-circle"></i> ได้ตำแหน่งจาก GPS แล้ว' + (acc ? ' (คลาดเคลื่อนราว ' + Math.round(acc) + ' ม.)' : '') + ' — ลากหมุดเพื่อปรับได้'
                : '<i class="fa fa-check-circle"></i> ปักหมุดแล้ว — ลากหมุดหรือแตะจุดใหม่เพื่อปรับ';
            status(txt, 'ok');
            if (opts.onChange) {
                opts.onChange(lat, lng, method);
            }
        }

        map.on('click', function (e) {
            set(e.latlng.lat, e.latlng.lng, null, 'pin', false);
        });

        if (opts.initial) {
            set(opts.initial[0], opts.initial[1], null, 'pin', false);
            status(opts.initialText || '<i class="fa fa-map-marker"></i> ตำแหน่งที่บันทึกไว้ — ลากหมุดเพื่อปรับได้', 'ok');
        }

        return {
            map: map,
            set: set,
            has: function () { return !!marker; },
            clear: function () {
                if (marker) { map.removeLayer(marker); marker = null; }
                if (accCircle) { map.removeLayer(accCircle); accCircle = null; }
                if (opts.lat) { opts.lat.val(''); }
                if (opts.lng) { opts.lng.val(''); }
                if (opts.acc) { opts.acc.val(''); }
                status('ยังไม่ได้ระบุตำแหน่ง', '');
            },
            locate: function ($btn) {
                var orig = $btn ? $btn.html() : '';
                if ($btn) { $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> กำลังหาตำแหน่ง…'); }
                status('<i class="fa fa-spinner fa-spin"></i> กำลังหาตำแหน่ง…', '');
                FloodMap.locate(function (lat, lng, acc) {
                    if ($btn) { $btn.prop('disabled', false).html(orig); }
                    set(lat, lng, acc, 'gps', true);
                }, function (msg) {
                    if ($btn) { $btn.prop('disabled', false).html(orig); }
                    status('<i class="fa fa-exclamation-triangle"></i> ' + esc(msg), 'err');
                });
            }
        };
    };

    /** ลิงก์นำทางไปยังพิกัด (เปิดแอปแผนที่ในมือถือ) */
    FloodMap.navUrl = function (lat, lng) {
        return 'https://www.google.com/maps/dir/?api=1&destination=' + lat + ',' + lng;
    };

})(window, jQuery);
