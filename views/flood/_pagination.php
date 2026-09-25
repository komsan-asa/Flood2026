<?php
/**
 * แถบเลขหน้า — ก่อน include ให้ตั้ง:
 *   $pg      ผลลัพธ์จาก model (page, pages, total)
 *   $pgPath  path ของหน้า เช่น 'flood/reports'
 * ตัวกรองอื่นใน query string ถูกส่งต่อให้เอง
 */
if (!isset($pg) || !is_array($pg) || (int) $pg['pages'] <= 1) {
    return;
}
$pgParams = $_GET;
unset($pgParams['url'], $pgParams['page']);
$pgLink = function ($n) use ($pgParams, $pgPath) {
    return URL . $pgPath . '?' . http_build_query(array_merge($pgParams, array('page' => $n)));
};
$cur = (int) $pg['page'];
$last = (int) $pg['pages'];
$from = max(1, $cur - 2);
$to = min($last, $cur + 2);
?>
<div class="flex flex-wrap items-center justify-between gap-2" style="margin-top:12px">
    <span class="small-muted">ทั้งหมด <?= number_format((int) $pg['total']) ?> รายการ · หน้า <?= $cur ?>/<?= $last ?></span>
    <ul class="pagination">
        <?php if ($cur > 1) { ?><li><a href="<?= h($pgLink($cur - 1)) ?>">&laquo;</a></li><?php } ?>
        <?php if ($from > 1) { ?><li><a href="<?= h($pgLink(1)) ?>">1</a></li><?php if ($from > 2) { ?><li class="disabled"><span>…</span></li><?php } } ?>
        <?php for ($i = $from; $i <= $to; $i++) { ?>
        <li class="<?= $i === $cur ? 'active' : '' ?>"><a href="<?= h($pgLink($i)) ?>"><?= $i ?></a></li>
        <?php } ?>
        <?php if ($to < $last) { if ($to < $last - 1) { ?><li class="disabled"><span>…</span></li><?php } ?><li><a href="<?= h($pgLink($last)) ?>"><?= $last ?></a></li><?php } ?>
        <?php if ($cur < $last) { ?><li><a href="<?= h($pgLink($cur + 1)) ?>">&raquo;</a></li><?php } ?>
    </ul>
</div>
