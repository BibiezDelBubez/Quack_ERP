<?php
/**
 * sales/orders/_list.php
 * Variabili: $orders, $total, $search, $stato, $dal, $al, $pag, $base
 */
$statiLabels = [
    ''                     => 'Tutti',
    'in_lavorazione'       => 'In lavorazione',
    'parzialmente_spedito' => 'Parz. spedito',
    'spedito'              => 'Spedito',
    'completato'           => 'Completato',
    'annullato'            => 'Annullato',
];
$sort = $pag['sort']; $dir = $pag['dir'];
$hasFilters = $search !== '' || $dal !== '' || $al !== '';
?>
<?php include APP_ROOT_DIR . '/app/views/sales/_subnav.php'; ?>

<div class="qk-card">
  <div class="qk-table-toolbar">
    <form method="get" action="<?= $base ?>" class="qk-table-toolbar-left">
      <div class="qk-filter-wrap">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="text" name="q" class="qk-filter-input" placeholder="Numero, cliente…" value="<?= e($search) ?>" autocomplete="off">
      </div>
      <div class="qk-filter-date-group">
        <label>Da ordine</label>
        <input type="date" name="dal" class="qk-filter-input qk-filter-date" value="<?= e($dal) ?>">
      </div>
      <div class="qk-filter-date-group">
        <label>A ordine</label>
        <input type="date" name="al" class="qk-filter-input qk-filter-date" value="<?= e($al) ?>">
      </div>
      <?php if ($sort !== 'data'): ?><input type="hidden" name="sort" value="<?= e($sort) ?>"><?php endif; ?>
      <?php if ($dir  !== 'desc'): ?><input type="hidden" name="dir"  value="<?= e($dir) ?>"><?php endif; ?>
      <button type="submit" class="btn-qk btn-qk-secondary btn-qk-sm" title="Filtra">
        <i class="fa-solid fa-magnifying-glass"></i>
      </button>
      <?php if ($hasFilters): ?>
      <a href="<?= $base ?>" class="btn-qk btn-qk-secondary btn-qk-sm" title="Rimuovi filtri">
        <i class="fa-solid fa-xmark"></i>
      </a>
      <?php endif; ?>
    </form>
    <div class="qk-table-toolbar-right">
      <div class="qk-filter-pills">
        <?php foreach ($statiLabels as $k => $lbl): ?>
        <a href="<?= e(qk_list_url($base, ['stato' => $k ?: null])) ?>"
           class="qk-filter-pill <?= $stato === $k ? 'active' : '' ?>"><?= e($lbl) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="qk-table-wrap">
    <table class="qk-table">
      <thead>
        <tr>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'numero', $sort, $dir)) ?>">N° Ordine<?= qk_sort_icon('numero', $sort, $dir) ?></a></th>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'cliente', $sort, $dir)) ?>">Cliente<?= qk_sort_icon('cliente', $sort, $dir) ?></a></th>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'stato', $sort, $dir)) ?>">Stato<?= qk_sort_icon('stato', $sort, $dir) ?></a></th>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'data', $sort, $dir)) ?>">Data ordine<?= qk_sort_icon('data', $sort, $dir) ?></a></th>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'consegna', $sort, $dir)) ?>">Consegna prev.<?= qk_sort_icon('consegna', $sort, $dir) ?></a></th>
          <th class="qk-sortable qk-text-right"><a href="<?= e(qk_sort_url($base, 'totale', $sort, $dir)) ?>">Totale<?= qk_sort_icon('totale', $sort, $dir) ?></a></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($orders)): ?>
        <tr><td colspan="7" class="qk-table-empty"><i class="fa-solid fa-inbox"></i> Nessun ordine trovato.</td></tr>
        <?php else: foreach ($orders as $o): ?>
        <tr>
          <td class="fw-semibold"><a href="<?= url('sales/orders/edit?id=' . $o['id']) ?>" class="qk-link-strong"><?= e($o['numero_ordine']) ?></a></td>
          <td><?= e($o['cliente_nome'] ?? '—') ?></td>
          <td><?= status_badge($o['stato'] ?? '') ?></td>
          <td><?= e(fmt_date($o['data_ordine'] ?? '')) ?: '—' ?></td>
          <td class="qk-text-muted"><?= e(fmt_date($o['data_consegna_prevista'] ?? '')) ?: '—' ?></td>
          <td class="qk-text-right"><?= $o['totale'] !== null ? '€ ' . number_format((float)$o['totale'], 2, ',', '.') : '—' ?></td>
          <td class="qk-table-actions">
            <a href="<?= url('sales/orders/edit?id=' . $o['id']) ?>" class="btn-qk btn-qk-xs btn-qk-secondary" title="Apri"><i class="fa-solid fa-pencil"></i></a>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php qk_paginate($total, $pag['page'], $pag['limit'], $base); ?>
</div>
