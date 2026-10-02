<?php if (isset($pager) && $pager->getTotal() > 0): ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 px-3 py-2 border-top small text-body-secondary">
        <span>
            <?= number_format($pager->getTotal(), 0, ',', '.') ?> registro(s) ·
            página <?= $pager->getCurrentPage() ?> de <?= max(1, $pager->getPageCount()) ?>
        </span>
        <?= $pager->links('default', 'bootstrap') ?>
    </div>
<?php endif ?>
