<?php
$flashTypes = ['success' => 'success', 'error' => 'danger', 'warning' => 'warning', 'info' => 'info'];
?>
<?php foreach ($flashTypes as $key => $variant): ?>
    <?php if ($message = session()->getFlashdata($key)): ?>
        <div class="alert alert-<?= $variant ?> alert-dismissible fade show" role="alert">
            <?= esc($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
        </div>
    <?php endif ?>
<?php endforeach ?>

<?php $formErrors = session()->getFlashdata('errors'); ?>
<?php if (! empty($formErrors) && is_array($formErrors)): ?>
    <div class="alert alert-danger" role="alert">
        <div class="fw-semibold mb-1">Corrija os campos abaixo:</div>
        <ul class="mb-0 small">
            <?php foreach ($formErrors as $error): ?>
                <li><?= esc($error) ?></li>
            <?php endforeach ?>
        </ul>
    </div>
<?php endif ?>
