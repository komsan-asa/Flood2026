<?php
$cats = flood_notice_categories();
$vopt = flood_notice_verify_options();
$rows = $this->noticeRows;
$c = $this->noticeCounts;
$f = $this->noticeFilters;
$canEdit = $this->noticeCanEdit;
$tab = function ($status, $cat) {
    return URL . 'flood/notices?' . http_build_query(array_filter(array('status' => $status, 'cat' => $cat)));
};
$jsRows = array();
?>
<div class="flood-page-header">
    <h2 class="flood-page-title"><i class="fa fa-newspaper-o"></i> ข้อมูลที่ควรรู้</h2>
    <?php if ($canEdit) { ?>
    <button type="button" class="btn btn-primary js-notice-edit" data-id="0"><i class="fa fa-plus"></i> เพิ่มข้อมูล</button>
    <?php } ?>
</div>
<p class="text-muted" style="margin-top:-6px">ข้อมูลประกอบการตัดสินใจที่ไม่ใช่จุดน้ำท่วม — ศูนย์พักพิง สาธารณสุข ไฟฟ้า/ประปา การระบายน้ำ โรงเรียนปิด เส้นทาง · ข่าวจากโซเชียลขึ้นป้าย <span class="label label-warning">ยังไม่ยืนยัน</span> จนกว่าจะเช็กกับหน่วยงาน · ประชาชนเห็นเฉพาะที่ <span class="label label-success">ยืนยันแล้ว</span> และไม่ได้ซ่อน (<a href="<?= URL ?>" target="_blank">ดูหน้าประชาชน</a>)</p>

<?php if (!$this->noticeReady) { ?>
<div class="alert alert-danger">ยังไม่มีตาราง flood_notice และระบบสร้างเองไม่ได้ — ให้ผู้ดูแลรัน <code>php sql/apply_schema.php 12</code></div>
<?php } ?>

<ul class="nav-tabs">
    <li class="<?= $f['status'] === 'active' ? 'active' : '' ?>"><a href="<?= h($tab('active', $f['cat'])) ?>">ใช้อยู่ <span class="count"><?= (int) $c['active'] ?></span></a></li>
    <li class="<?= $f['status'] === 'archived' ? 'active' : '' ?>"><a href="<?= h($tab('archived', $f['cat'])) ?>">เก็บแล้ว <span class="count"><?= (int) $c['archived'] ?></span></a></li>
</ul>

<div class="notice-chips">
    <a href="<?= h($tab($f['status'], '')) ?>" class="notice-chip<?= $f['cat'] === '' ? ' on' : '' ?>">ทุกหมวด</a>
    <?php foreach ($cats as $code => $cat) { $n = isset($c['by_cat'][$code]) ? (int) $c['by_cat'][$code] : 0; ?>
    <a href="<?= h($tab($f['status'], $code)) ?>" class="notice-chip<?= $f['cat'] === $code ? ' on' : '' ?>" style="--nc:<?= h($cat['color']) ?>">
        <i class="fa <?= h($cat['icon']) ?>"></i> <?= h($cat['name']) ?><?= $n ? ' <b>' . $n . '</b>' : '' ?>
    </a>
    <?php } ?>
</div>

<?php if (!$rows) { ?>
<div class="flood-card"><div class="empty-state"><i class="fa fa-newspaper-o"></i>ยังไม่มีข้อมูลในหมวดนี้</div></div>
<?php } ?>

<div class="notice-grid">
    <?php foreach ($rows as $n) {
        $cat = isset($cats[$n['category']]) ? $cats[$n['category']] : $cats['other'];
        $v = isset($vopt[$n['verify']]) ? $vopt[$n['verify']] : $vopt['unverified'];
        $jsRows[] = array(
            'notice_id' => (int) $n['notice_id'], 'category' => $n['category'], 'title' => $n['title'], 'detail' => (string) $n['detail'],
            'amphoe_code' => (string) $n['amphoe_code'], 'place' => (string) $n['place'], 'contact' => (string) $n['contact'],
            'verify' => $n['verify'], 'source_name' => (string) $n['source_name'], 'source_url' => (string) $n['source_url'],
            'is_pinned' => (int) $n['is_pinned'], 'is_public' => isset($n['is_public']) ? (int) $n['is_public'] : 1, 'info_at' => $n['info_at'] ? date('Y-m-d\TH:i', strtotime($n['info_at'])) : '',
        );
    ?>
    <article class="notice-card<?= (int) $n['is_pinned'] ? ' pinned' : '' ?><?= $n['status'] === 'archived' ? ' archived' : '' ?>" style="--nc:<?= h($cat['color']) ?>">
        <div class="notice-head">
            <span class="notice-cat"><i class="fa <?= h($cat['icon']) ?>"></i> <?= h($cat['name']) ?></span>
            <span class="label <?= h($v['class']) ?>"><i class="fa <?= h($v['icon']) ?>"></i> <?= h($v['name']) ?></span>
            <?php $pub = !isset($n['is_public']) || (int) $n['is_public'] === 1; ?>
            <?php if ($pub && $n['verify'] === 'verified' && $n['status'] === 'active') { ?>
            <span class="notice-vis on" title="แสดงบนแผนที่ประชาชน"><i class="fa fa-globe"></i> ประชาชนเห็น</span>
            <?php } else { ?>
            <span class="notice-vis" title="<?= $pub ? 'จะแสดงให้ประชาชนเมื่อยืนยันแล้ว' : 'ซ่อนจากประชาชน' ?>"><i class="fa fa-lock"></i> เฉพาะเจ้าหน้าที่</span>
            <?php } ?>
            <?php if ((int) $n['is_pinned']) { ?><span class="notice-pin" title="ปักหมุด"><i class="fa fa-thumb-tack"></i></span><?php } ?>
        </div>
        <h3 class="notice-title"><?= h($n['title']) ?></h3>
        <?php if ($n['detail']) { ?><div class="notice-detail"><?= nl2br(h($n['detail'])) ?></div><?php } ?>
        <dl class="notice-meta">
            <?php if ($n['place'] || $n['amphoe_name']) { ?><dt><i class="fa fa-map-marker"></i></dt><dd><?= h(trim((string) $n['place'] . ($n['amphoe_name'] ? ' · อ.' . $n['amphoe_name'] : ''), ' ·')) ?></dd><?php } ?>
            <?php if ($n['contact']) { ?><dt><i class="fa fa-phone"></i></dt><dd><?= h($n['contact']) ?></dd><?php } ?>
            <?php if ($n['source_name'] || $n['source_url']) { ?><dt><i class="fa fa-link"></i></dt><dd><?= $n['source_url'] ? '<a href="' . h($n['source_url']) . '" target="_blank" rel="noopener">' . h($n['source_name'] ?: 'แหล่งข่าว') . ' <i class="fa fa-external-link"></i></a>' : h($n['source_name']) ?></dd><?php } ?>
        </dl>
        <div class="notice-foot">
            <span class="small-muted">ข้อมูล ณ <?= h(flood_thai_date($n['info_at'] ?: $n['created_at'])) ?><?= $n['updated_by_name'] ? ' · แก้โดย ' . h($n['updated_by_name']) : ($n['created_by_name'] ? ' · โดย ' . h($n['created_by_name']) : '') ?></span>
            <?php if ($canEdit) { ?>
            <span class="notice-actions">
                <?php if ($n['status'] === 'active') { ?>
                <?php if ($n['verify'] !== 'verified') { ?><button type="button" class="btn btn-success btn-xs js-notice-set" data-id="<?= (int) $n['notice_id'] ?>" data-field="verify" data-value="verified" title="เช็กกับหน่วยงานแล้ว"><i class="fa fa-check"></i> ยืนยัน</button><?php } ?>
                <button type="button" class="btn btn-default btn-xs js-notice-set" data-id="<?= (int) $n['notice_id'] ?>" data-field="is_pinned" data-value="<?= (int) $n['is_pinned'] ? 0 : 1 ?>"><i class="fa fa-thumb-tack"></i> <?= (int) $n['is_pinned'] ? 'เลิกปัก' : 'ปักหมุด' ?></button>
                <button type="button" class="btn btn-default btn-xs js-notice-set" data-id="<?= (int) $n['notice_id'] ?>" data-field="is_public" data-value="<?= $pub ? 0 : 1 ?>"><i class="fa <?= $pub ? 'fa-eye-slash' : 'fa-globe' ?>"></i> <?= $pub ? 'ซ่อนจากประชาชน' : 'ให้ประชาชนเห็น' ?></button>
                <button type="button" class="btn btn-default btn-xs js-notice-edit" data-id="<?= (int) $n['notice_id'] ?>"><i class="fa fa-pencil"></i> แก้ไข</button>
                <button type="button" class="btn btn-default btn-xs js-notice-set" data-id="<?= (int) $n['notice_id'] ?>" data-field="status" data-value="archived" title="ข้อมูลหมดอายุ/ไม่เกี่ยวแล้ว"><i class="fa fa-archive"></i> เก็บ</button>
                <?php } else { ?>
                <button type="button" class="btn btn-default btn-xs js-notice-set" data-id="<?= (int) $n['notice_id'] ?>" data-field="status" data-value="active"><i class="fa fa-undo"></i> นำกลับมาใช้</button>
                <?php } ?>
            </span>
            <?php } ?>
        </div>
    </article>
    <?php } ?>
</div>

<?php if ($canEdit) { ?>
<div class="modal fade" id="noticeModal" tabindex="-1" role="dialog">
    <div class="modal-dialog">
        <form class="modal-content" id="noticeForm">
            <div class="modal-header">
                <h4 class="modal-title" id="noticeTitle">เพิ่มข้อมูล</h4>
                <button type="button" class="close" data-dismiss="modal" aria-label="ปิด">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="notice_id" id="nId" />
                <div class="row">
                    <div class="col-sm-7"><div class="form-group">
                        <label for="nCat">หมวด</label>
                        <select class="form-control" id="nCat" name="category">
                            <?php foreach ($cats as $code => $cat) { ?><option value="<?= h($code) ?>"><?= h($cat['name']) ?></option><?php } ?>
                        </select>
                    </div></div>
                    <div class="col-sm-5"><div class="form-group">
                        <label for="nVerify">สถานะข้อมูล</label>
                        <select class="form-control" id="nVerify" name="verify">
                            <option value="unverified">ยังไม่ยืนยัน</option>
                            <option value="verified">ยืนยันแล้ว</option>
                        </select>
                    </div></div>
                </div>
                <div class="form-group">
                    <label for="nTitleIn" class="required">หัวข้อ</label>
                    <input type="text" class="form-control" id="nTitleIn" name="title" maxlength="200" placeholder="เช่น เปิดศูนย์พักพิงชั่วคราว อาคารสโมสรเทศบาลเมืองสระแก้ว" />
                </div>
                <div class="form-group">
                    <label for="nDetail">รายละเอียด</label>
                    <textarea class="form-control" id="nDetail" name="detail" rows="4"></textarea>
                </div>
                <div class="row">
                    <div class="col-sm-7"><div class="form-group">
                        <label for="nPlace">สถานที่</label>
                        <input type="text" class="form-control" id="nPlace" name="place" maxlength="200" />
                    </div></div>
                    <div class="col-sm-5"><div class="form-group">
                        <label for="nAmphoe">จังหวัด / อำเภอ</label>
                        <div class="pv-am-pair">
                            <?= flood_province_select($this->provinces, array('class' => 'form-control', 'data-pv-for' => 'nAmphoe', 'aria-label' => 'จังหวัด'), '', '— จังหวัด —') ?>
                            <select class="form-control" id="nAmphoe" name="amphoe_code">
                                <option value="">ทั้งจังหวัด / ไม่ระบุ</option>
                                <?= flood_amphoe_options($this->amphoes, '', 'อ.') ?>
                            </select>
                        </div>
                    </div></div>
                </div>
                <div class="row">
                    <div class="col-sm-6"><div class="form-group">
                        <label for="nContact">ติดต่อ / ผู้ประสาน</label>
                        <input type="text" class="form-control" id="nContact" name="contact" maxlength="100" />
                    </div></div>
                    <div class="col-sm-6"><div class="form-group">
                        <label for="nInfoAt">เวลาของข้อมูล</label>
                        <input type="datetime-local" class="form-control" id="nInfoAt" name="info_at" />
                    </div></div>
                </div>
                <div class="row">
                    <div class="col-sm-5"><div class="form-group">
                        <label for="nSrcName">แหล่งข่าว</label>
                        <input type="text" class="form-control" id="nSrcName" name="source_name" maxlength="150" placeholder="เช่น สวท.สระแก้ว" />
                    </div></div>
                    <div class="col-sm-7"><div class="form-group">
                        <label for="nSrcUrl">ลิงก์</label>
                        <input type="url" class="form-control" id="nSrcUrl" name="source_url" maxlength="500" placeholder="https://" />
                    </div></div>
                </div>
                <div class="checkbox"><label><input type="checkbox" id="nPinned" /> ปักหมุดไว้บนสุด และแสดงในหน้าภาพรวม</label></div>
                <div class="checkbox" style="margin-top:0"><label><input type="checkbox" id="nPublic" checked /> แสดงให้ประชาชนเห็นบนแผนที่สาธารณะ</label>
                    <div class="small-muted">ขึ้นหน้าประชาชนเฉพาะข้อมูลที่ "ยืนยันแล้ว" เท่านั้น — ข่าวที่ยังไม่ยืนยันเจ้าหน้าที่เห็นอย่างเดียว</div></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">ยกเลิก</button>
                <button type="submit" class="btn btn-primary" id="nSave"><i class="fa fa-save"></i> บันทึก</button>
            </div>
        </form>
    </div>
</div>
<?php } ?>

<script>window.NOTICES = <?= flood_js($jsRows) ?>;</script>
