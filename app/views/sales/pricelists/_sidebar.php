<?php
/**
 * sales/pricelists/_sidebar.php
 * Colonna destra del qk-form-grid: info box + clienti assegnati.
 * Variabili attese: $isEdit (bool), $clienti_assegnati (array)
 */
?>
<div>

  <div class="qk-card shadow-sm mb-3" style="border-left:3px solid var(--qk-info)">
    <div class="qk-card-header" style="padding:.75rem 1rem">
      <span class="qk-card-title" style="font-size:.8rem">
        <i class="fa-solid fa-circle-info me-2" style="color:var(--qk-info)"></i>Come funzionano i listini
      </span>
    </div>
    <div class="qk-card-body" style="padding:.85rem 1rem;font-size:.82rem;color:var(--qk-text-muted);line-height:1.55">
      <p style="margin:0 0 .5rem">Prezzi unitari per prodotto con supporto scaglioni di quantità e sconto %.</p>
      <p style="margin:0 0 .5rem">Assegnabile come predefinito a un cliente o selezionabile manualmente su ogni preventivo.</p>
      <p style="margin:0">Se un prodotto non ha un prezzo in listino è sempre possibile inserirlo manualmente nel preventivo.</p>
    </div>
  </div>

  <?php if ($isEdit && !empty($clienti_assegnati)): ?>
  <div class="qk-card shadow-sm">
    <div class="qk-card-header" style="padding:.75rem 1rem">
      <span class="qk-card-title" style="font-size:.8rem">
        <i class="fa-solid fa-address-book me-2" style="color:var(--qk-accent)"></i>Clienti assegnati
      </span>
      <span style="font-size:.75rem;color:var(--qk-text-muted)"><?= count($clienti_assegnati) ?></span>
    </div>
    <div class="qk-card-body" style="padding:.75rem 1rem">
      <?php foreach ($clienti_assegnati as $cl): ?>
      <a href="<?= url('customers/edit?id=' . $cl['id']) ?>"
         style="display:flex;align-items:center;gap:.5rem;padding:.3rem 0;font-size:.83rem;
                color:var(--qk-accent-text);text-decoration:none;border-bottom:1px solid var(--qk-card-border)">
        <i class="fa-solid fa-user" style="font-size:.72rem;color:var(--qk-text-muted);flex-shrink:0"></i>
        <?= e($cl['nome_cliente']) ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

</div>
