<?php
/**
 * app/views/warehouse/_subnav.php
 * Sotto-navigazione orizzontale per il modulo Magazzino.
 * Variabili attese: $active_tab (string)
 */
$subnav_tabs = [
    'dashboard' => ['label' => 'Panoramica',      'url' => url('warehouse')],
    'stock'     => ['label' => 'Giacenze',        'url' => url('warehouse/stock')],
    'movements' => ['label' => 'Movimenti',       'url' => url('warehouse/movements')],
    'inventories' => ['label' => 'Inventario fis.', 'url' => url('warehouse/inventories')],
    'reorder'   => ['label' => 'Riordino',        'url' => url('warehouse/reorder')],
    'min-stock' => ['label' => 'Soglie di riordino', 'url' => url('warehouse/min-stock')],
    'transfers' => ['label' => 'Trasferimenti',   'url' => url('warehouse/transfers')],
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
