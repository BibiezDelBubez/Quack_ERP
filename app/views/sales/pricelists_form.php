<?php
/**
 * sales/pricelists_form.php — Assembler listino vendita.
 * Flash e breadcrumb gestiti da header.php (via redirect_with / flash_get).
 * Variabili: $isEdit, $id, $values, $errors, $items, $clienti_assegnati
 */
$_partials = __DIR__ . '/pricelists';
?>

<?php $active_tab = 'pricelists'; include __DIR__ . '/_subnav.php'; ?>

<?php if (!empty($errors['_global'])): ?>
<div class="qk-alert qk-alert-danger mb-3">
  <i class="fa-solid fa-circle-exclamation"></i>
  <span><?= e($errors['_global']) ?></span>
</div>
<?php endif; ?>

<form action="" method="post">
  <?= csrf_field() ?>
  <div class="qk-form-grid">
    <?php include $_partials . '/_form_testata.php'; ?>
    <?php include $_partials . '/_sidebar.php'; ?>
  </div>
</form>

<?php if ($isEdit): ?>
  <?php include $_partials . '/_righe.php'; ?>
<?php endif; ?>
