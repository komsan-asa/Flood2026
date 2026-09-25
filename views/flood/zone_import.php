<?php
$st = $this->importStats;
$api = $this->importApiUrl;
?>
<div class="flood-page-header">
    <h2 class="flood-page-title"><i class="fa fa-download"></i> นำเข้าพื้นที่จาก arankub.com</h2>
    <a href="<?= URL ?>flood/zones" class="btn btn-default btn-sm"><i class="fa fa-arrow-left"></i> พื้นที่ประกาศ</a>
</div>
<p class="text-muted">
    คัดลอกพื้นที่ประกาศน้ำท่วมจาก <a href="https://arankub.com/flood" target="_blank" rel="noopener">arankub.com/flood</a>
    (ARANKUB AI อ.อรัญประเทศ) มาเป็นพื้นที่ของระบบนี้ครั้งเดียว — หลังนำเข้าเจ้าหน้าที่แก้ไข/ปิดประกาศได้ตามปกติ
    แต่ข้อมูลจะไม่อัปเดตตามต้นทางเอง · นำเข้าซ้ำได้ ระบบจะข้ามพื้นที่ที่เคยนำเข้าแล้ว
</p>

<?php if (!$this->importReady) { ?>
<div class="alert alert-warning">
    <i class="fa fa-exclamation-triangle"></i> สร้างตาราง <code>flood_zone_import</code> ไม่ได้ (บัญชีฐานข้อมูลของเว็บอาจไม่มีสิทธิ์ CREATE)
    — ให้รันไฟล์ <code>sql/10_flood_zone_import.sql</code> บนฐานข้อมูล แล้วเปิดหน้านี้ใหม่
</div>
<?php } else { ?>

<?php if ($st['count'] > 0) { ?>
<div class="alert alert-info">
    <i class="fa fa-info-circle"></i> เคยนำเข้าแล้ว <b><?= (int) $st['count'] ?></b> พื้นที่ (ยังประกาศอยู่ <?= (int) $st['active'] ?>)
    · ครั้งล่าสุด <?= h(flood_thai_date($st['last_at'])) ?>
</div>
<?php } ?>

<div class="flood-card">
    <div class="flood-card-header"><span>1. ดึงข้อมูล</span></div>
    <div class="flood-card-body">
        <div class="imp-fetch">
            <button type="button" class="btn btn-primary" id="impFetch"><i class="fa fa-cloud-download"></i> ดึงข้อมูลจาก arankub.com</button>
            <span class="small-muted">เซิร์ฟเวอร์จะอ่านจาก <code><?= h($api) ?></code> · ยังไม่บันทึกอะไรจนกว่าจะกด "นำเข้า"</span>
        </div>
        <details class="imp-paste" id="impPasteBox">
            <summary>เซิร์ฟเวอร์ออกอินเทอร์เน็ตไม่ได้? คัดลอกข้อมูลมาวางเอง</summary>
            <ol class="imp-steps">
                <li>เปิด <a href="<?= h($api) ?>" target="_blank" rel="noopener"><?= h($api) ?></a> ในแท็บใหม่</li>
                <li>กด <kbd>Ctrl</kbd> + <kbd>A</kbd> แล้ว <kbd>Ctrl</kbd> + <kbd>C</kbd> (มือถือ: แตะค้าง → เลือกทั้งหมด → คัดลอก)</li>
                <li>กลับมาวางในช่องด้านล่าง แล้วกด "ตรวจข้อมูลที่วาง" — หรือบันทึกหน้านั้นเป็นไฟล์ .json แล้วกด "เลือกไฟล์"</li>
            </ol>
            <form id="impPasteForm" autocomplete="off">
                <label for="impJson" class="sr-only">ข้อมูลจาก arankub.com</label>
                <textarea class="form-control imp-json" id="impJson" rows="5" spellcheck="false" placeholder='{"zones":[ ... ]}'></textarea>
                <div class="imp-paste-actions">
                    <label class="btn btn-default btn-sm"><input type="file" id="impFile" accept=".json,application/json,text/plain" style="display:none" />
                        <i class="fa fa-file-text-o"></i> เลือกไฟล์ .json</label>
                    <button type="submit" class="btn btn-default btn-sm" id="impPasteBtn"><i class="fa fa-check"></i> ตรวจข้อมูลที่วาง</button>
                </div>
            </form>
        </details>
    </div>
</div>

<div class="flood-card" id="impResult" hidden>
    <div class="flood-card-header"><span>2. ตรวจก่อนนำเข้า</span><span class="small-muted" id="impSourceTime"></span></div>
    <div class="flood-card-body">
        <div class="imp-summary" id="impSummary" role="status"></div>
        <div class="imp-levels" id="impLevels"></div>
        <div id="impMap" class="flood-map"></div>
        <div class="map-legend" id="impLegend" style="margin-top:6px"></div>
    </div>
    <div class="imp-toolbar">
        <button type="button" class="btn btn-default btn-xs" id="impAll"><i class="fa fa-check-square-o"></i> เลือกทั้งหมด</button>
        <button type="button" class="btn btn-default btn-xs" id="impNone"><i class="fa fa-square-o"></i> ไม่เลือกเลย</button>
        <span class="small-muted">แตะชื่อพื้นที่เพื่อดูบนแผนที่</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-cards imp-table" style="margin:0">
            <thead>
                <tr>
                    <th style="width:1%"><span class="sr-only">เลือก</span></th>
                    <th>พื้นที่</th>
                    <th>ระดับ</th>
                    <th>อำเภอ / ตำบล</th>
                    <th>ข้อความถึงประชาชน</th>
                    <th>ประกาศ</th>
                    <th>ควรรู้</th>
                </tr>
            </thead>
            <tbody id="impRows"></tbody>
        </table>
    </div>
    <div class="flood-card-body imp-footer">
        <label class="imp-attr"><input type="checkbox" id="impAttr" checked />
            เติม "<?= h(Zone_Import_Model::ATTRIBUTION) ?>" ท้ายข้อความถึงประชาชน (ให้เครดิตแหล่งข้อมูล)</label>
        <div class="imp-run">
            <span class="small-muted" id="impRunHint">พื้นที่ที่นำเข้าจะขึ้นแผนที่ประชาชนทันที</span>
            <button type="button" class="btn btn-primary" id="impRun" disabled><i class="fa fa-download"></i> นำเข้า <span id="impRunCount">0</span> พื้นที่</button>
        </div>
    </div>
</div>

<script>
    window.IMPORT_CFG = <?= flood_js(array(
        'otherZones' => $this->otherZones,
        'levelLabels' => $this->importLevelLabels,
        'attribution' => Zone_Import_Model::ATTRIBUTION,
    )) ?>;
</script>
<?php } ?>
