<?php
/**
 * app/views/sales/_subnav.php
 * Sotto-navigazione orizzontale per il modulo Vendite.
 * Variabili attese: $active_tab (string)
 */
$subnav_tabs = [
    'dashboard'  => ['label' => 'Panoramica',   'url' => url('sales')],
    'quotes'     => ['label' => 'Preventivi',   'url' => url('sales/quotes')],
    'orders'     => ['label' => 'Ordini',       'url' => url('sales/orders')],
];
?>
<ul class="nav qk-nav-tabs mb-4" role="tablist">
  <?php foreach ($subnav_tabs as $key => $tab): ?>
    <li class="nav-item">
      <a class="nav-link <?= $active_tab === $key ? 'active' : '' ?>" 
         href="<?= e($tab['url']) ?>">
        <?= e($tab['label']) ?>
      </a>
    </li>
  <?php endforeach; ?>
</ul>
