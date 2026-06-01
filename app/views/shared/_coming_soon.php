<?php
/**
 * shared/_coming_soon.php
 * Variabili attese: $feature_name (string)
 */
?>

<div class="qk-card" style="max-width:720px">
  <div class="qk-empty">
    <i class="fa-regular fa-hourglass-half"></i>
    <p><strong><?= e($feature_name ?? 'Funzionalità') ?></strong> è in sviluppo.</p>
    <a href="<?= url('dashboard') ?>" class="btn-qk btn-qk-secondary">
      <i class="fa-solid fa-arrow-left"></i> Torna alla dashboard
    </a>
  </div>
</div>
