<?php
/**
 * app/views/purchases/_subnav.php
 * Sotto-navigazione orizzontale per il modulo Acquisti.
 * Variabili attese: $active_tab (string)
 */
$active_tab = $active_tab ?? '';
$subnav_tabs = [
    'dashboard'  => ['label' => 'Panoramica',             'url' => url('purchases')],
    'rfq'        => ['label' => 'Richieste di preventivo', 'url' => url('purchases/rfq')],
    'quotes'     => ['label' => 'Preventivi fornitori',   'url' => url('purchases/quotes')],
    'orders'     => ['label' => 'Ordini d\'acquisto',      'url' => url('purchases/orders')],
    'receipts'   => ['label' => 'Ricezioni merce',        'url' => url('purchases/receipts')],
    'pricelists' => ['label' => 'Listini acquisto',       'url' => url('purchases/pricelists')],
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
