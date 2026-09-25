/*
 * แนบรูปจากมือถือ — ย่อรูปในเครื่องก่อนส่ง (ด้านยาวไม่เกิน 1600px, JPEG)
 * รูปจากกล้องมือถือ 4–15 MB เหลือราว 200–400 KB ส่งได้แม้สัญญาณอ่อน และไม่ติดขนาดอัปโหลดของเซิร์ฟเวอร์
 *
 * มือถือบางรุ่นเปิดรูปความละเอียดสูงมาก (50–200 ล้านพิกเซล) ด้วย <img> ไม่ได้ → ใช้ createImageBitmap
 * แบบย่อขนาดตอนถอดรหัส (ใช้หน่วยความจำน้อย) ก่อน ถ้ายังใหญ่เกินที่เซิร์ฟเวอร์รับ ย่อซ้ำให้เล็กลงอีก
 * ย่อไม่ได้และไฟล์เดิมใหญ่เกิน → ไม่ส่งรูปนั้น (แจ้งผู้ใช้) แทนที่จะทำให้ทั้งรายงานส่งไม่ผ่าน
 */
(function (window, $) {
    'use strict';

    var FloodUpload = window.FloodUpload = {};
    var URLx = window.URL || window.webkitURL;
    var LIMIT = window.FLOOD_UPLOAD_MAX > 0 ? window.FLOOD_UPLOAD_MAX : 2 * 1024 * 1024;
    // ลำดับการย่อ: ถ้ารอบแรกยังใหญ่เกิน ลดขนาด/คุณภาพลงเรื่อย ๆ
    var PLAN = [[1600, 0.8], [1280, 0.72], [1024, 0.65], [800, 0.6]];

    function toJpeg(canvas, q) {
        var d = $.Deferred();
        if (canvas.toBlob) {
            canvas.toBlob(function (b) { d.resolve(b || null); }, 'image/jpeg', q);
        } else {
            try {
                var bin = atob(canvas.toDataURL('image/jpeg', q).split(',')[1]);
                var arr = new Uint8Array(bin.length);
                for (var i = 0; i < bin.length; i++) { arr[i] = bin.charCodeAt(i); }
                d.resolve(new Blob([arr], { type: 'image/jpeg' }));
            } catch (e) {
                d.resolve(null);
            }
        }
        return d.promise();
    }

    /** ถอดรหัสรูป → คืน {src, w, h, close()} (src วาดลง canvas ได้) */
    function decode(file, maxDim) {
        var d = $.Deferred();
        function viaImg() {
            if (!URLx) { d.resolve(null); return; }
            var url = URLx.createObjectURL(file);
            var img = new Image();
            img.onload = function () {
                d.resolve({ src: img, w: img.naturalWidth, h: img.naturalHeight, close: function () { URLx.revokeObjectURL(url); } });
            };
            img.onerror = function () { URLx.revokeObjectURL(url); d.resolve(null); };
            img.src = url;
        }
        if (window.createImageBitmap) {
            // ถอดรหัสแบบย่อทันที — ต้องรู้สัดส่วนก่อน จึงลองเปิดเต็มก่อนแล้วย่อ ถ้าเปิดเต็มไม่ได้ค่อยบังคับความกว้าง
            window.createImageBitmap(file, { imageOrientation: 'from-image' }).then(function (bm) {
                d.resolve({ src: bm, w: bm.width, h: bm.height, close: function () { if (bm.close) { bm.close(); } } });
            }, function () {
                window.createImageBitmap(file, { resizeWidth: maxDim, resizeQuality: 'medium', imageOrientation: 'from-image' }).then(function (bm) {
                    d.resolve({ src: bm, w: bm.width, h: bm.height, close: function () { if (bm.close) { bm.close(); } } });
                }, viaImg);
            });
        } else {
            viaImg();
        }
        return d.promise();
    }

    FloodUpload.limit = LIMIT;

    /** คืน promise → Blob ที่ส่งได้ หรือ null ถ้าย่อไม่ได้และไฟล์เดิมใหญ่เกิน */
    FloodUpload.compress = function (file) {
        var d = $.Deferred();
        var fallback = file && file.size <= LIMIT && /^image\/(jpeg|png|webp)$/.test(file.type) ? file : null;
        if (!file || !/^image\//.test(file.type) || !document.createElement('canvas').getContext) {
            return d.resolve(fallback).promise();
        }
        decode(file, PLAN[0][0]).then(function (im) {
            if (!im || !im.w || !im.h) {
                d.resolve(fallback);
                return;
            }
            var step = 0;
            (function attempt() {
                var s = Math.min(1, PLAN[step][0] / Math.max(im.w, im.h));
                var c = document.createElement('canvas');
                c.width = Math.max(1, Math.round(im.w * s));
                c.height = Math.max(1, Math.round(im.h * s));
                var ctx = c.getContext('2d');
                ctx.fillStyle = '#fff';
                ctx.fillRect(0, 0, c.width, c.height);
                try {
                    ctx.drawImage(im.src, 0, 0, c.width, c.height);
                } catch (e) {
                    im.close();
                    d.resolve(fallback);
                    return;
                }
                toJpeg(c, PLAN[step][1]).then(function (b) {
                    c.width = c.height = 0; // คืนหน่วยความจำ (สำคัญบนมือถือ)
                    if (b && b.size <= LIMIT) {
                        im.close();
                        d.resolve(fallback && fallback.size <= b.size ? fallback : b);
                    } else if (step < PLAN.length - 1) {
                        step++;
                        attempt();
                    } else {
                        im.close();
                        d.resolve(fallback);
                    }
                });
            })();
        });
        return d.promise();
    };

    /**
     * ผูกกับ input[type=file] — จำรูปที่เลือก แสดงตัวอย่าง กดลบได้
     * คืน { count(), appendTo(formData, field) → promise }
     */
    FloodUpload.bind = function ($input, $preview, max) {
        var files = [];
        max = max || 3;

        function render() {
            $preview.empty();
            files.forEach(function (f, i) {
                var src = URLx ? URLx.createObjectURL(f) : '';
                $preview.append(
                    '<div class="up-thumb"><img src="' + src + '" alt="">'
                    + '<button type="button" class="up-del" data-i="' + i + '" aria-label="ลบรูป">&times;</button></div>'
                );
            });
            $input.closest('.up-wrap').find('.up-count').text(files.length ? files.length + '/' + max + ' รูป' : '');
        }

        $input.on('change', function () {
            var picked = Array.prototype.slice.call(this.files || []);
            var skipped = 0;
            picked.forEach(function (f) {
                // บางเครื่องไม่บอกชนิดไฟล์ (type ว่าง) — ดูจากนามสกุลแทน
                var isImg = /^image\//.test(f.type) || (!f.type && /\.(jpe?g|png|webp|heic|heif)$/i.test(f.name || ''));
                if (!isImg) {
                    skipped++;
                    return;
                }
                if (files.length < max) {
                    files.push(f);
                } else {
                    skipped++;
                }
            });
            if (skipped && window.Flood) {
                window.Flood.toast('แนบได้เฉพาะรูปภาพ สูงสุด ' + max + ' รูป', 'info');
            }
            this.value = '';
            render();
        });

        $preview.on('click', '.up-del', function () {
            files.splice(parseInt($(this).data('i'), 10), 1);
            render();
        });

        return {
            count: function () { return files.length; },
            /** ย่อทีละรูป (ไม่ย่อพร้อมกัน กันหน่วยความจำมือถือเต็ม) แล้วแนบลง FormData */
            appendTo: function (fd, field) {
                var d = $.Deferred();
                var out = [];
                var dropped = 0;
                var i = 0;
                (function nextFile() {
                    if (i >= files.length) {
                        out.forEach(function (b, k) {
                            fd.append(field, b, 'photo' + (k + 1) + '.jpg');
                        });
                        if (dropped && window.Flood) {
                            window.Flood.toast('มีรูป ' + dropped + ' รูปที่เครื่องย่อไม่ได้ ระบบส่งรายงานโดยไม่มีรูปนั้น', 'info', 6000);
                        }
                        d.resolve(fd);
                        return;
                    }
                    var f = files[i++];
                    var t = f.type ? f : new Blob([f], { type: 'image/jpeg' });
                    FloodUpload.compress(t).then(function (b) {
                        if (b) { out.push(b); } else { dropped++; }
                        nextFile();
                    });
                })();
                return d.promise();
            },
            clear: function () { files = []; render(); }
        };
    };

})(window, jQuery);
