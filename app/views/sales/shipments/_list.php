<?php
/**
 * sales/shipments/_list.php
 * Variabili: $shipments, $total, $search, $stato, $dal, $al, $pag, $base
 */
$statiLabels = [
    ''            => 'Tutti',
    'preparazione'=> 'Preparazione',
    'spedita'     => 'Spedita',
    'consegnata'  => 'Consegnata',
    'reso'        => 'Reso',
];
$statoClassMap = ['spedita' => 'confirmed', 'consegnata' => 'delivered', 'reso' => 'cancelled', 'preparazione' => 'pending'];
$sort = $pag['sort']; $dir = $pag['dir'];
$hasFilters = $search !== '' || $dal !== '' || $al !== '';
?>
<?php include APP_ROOT_DIR . '/app/views/sales/_subnav.php'; ?>

<div class="qk-card">
  <div class="qk-table-toolbar">
    <form method="get" action="<?= $base ?>" class="qk-table-toolbar-left">
      <div class="qk-filter-wrap">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="text" name="q" class="qk-filter-input" placeholder="Numero, ordine, cliente…" value="<?= e($search) ?>" autocomplete="off">
      </div>
      <div class="qk-filter-date-group">
        <label>Da spedizione</label>
        <input type="date" name="dal" class="qk-filter-input qk-filter-date" value="<?= e($dal) ?>">
      </div>
      <div class="qk-filter-date-group">
        <label>A spedizione</label>
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
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'numero', $sort, $dir)) ?>">N° Spedizione<?= qk_sort_icon('numero', $sort, $dir) ?></a></th>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'data', $sort, $dir)) ?>">Data<?= qk_sort_icon('data', $sort, $dir) ?></a></th>
          <th>Ordine</th>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'cliente', $sort, $dir)) ?>">Cliente<?= qk_sort_icon('cliente', $sort, $dir) ?></a></th>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'vettore', $sort, $dir)) ?>">Vettore<?= qk_sort_icon('vettore', $sort, $dir) ?></a></th>
          <th>Tracking</th>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'stato', $sort, $dir)) ?>">Stato<?= qk_sort_icon('stato', $sort, $dir) ?></a></th>
          <th>DDT</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($shipments)): ?>
        <tr><td colspan="8" class="qk-table-empty"><i class="fa-solid fa-inbox"></i> Nessuna spedizione trovata.</td></tr>
        <?php else: foreach ($shipments as $s):
          $cls = $statoClassMap[$s['stato']] ?? 'draft';
        ?>
        <tr>
          <td class="fw-semibold"><?= e($s['numero_spedizione']) ?></td>
          <td><?= e(fmt_date($s['data_spedizione'])) ?: '—' ?></td>
          <td><a href="<?= url('sales/orders/edit?id=' . $s['ordine_id']) ?>" class="qk-link-strong"><?= e($s['numero_ordine']) ?></a></td>
          <td><?= e($s['cliente_nome']) ?></td>
          <td class="qk-text-muted"><?= e($s['vettore'] ?: '—') ?></td>
          <td><?php if ($s['tracking']): ?><code style="font-size:.78rem"><?= e($s['tracking']) ?></code><?php else: ?>—<?php endif; ?></td>
          <td><span class="qk-badge <?= $cls ?>"><?= e(ucfirst($s['stato'])) ?></span></td>
          <td>
            <?php if (!empty($s['numero_documento_trasporto'])): ?>
            <a href="<?= url('sales/shipments/ddt/view?id=' . $s['ddt_id']) ?>"
               class="btn-qk btn-qk-secondary btn-qk-sm" target="_blank">
              <i class="fa-solid fa-file-lines me-1"></i><?= e($s['numero_documento_trasporto']) ?>
            </a>
            <?php else: ?>—<?php endif; ?>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php qk_paginate($total, $pag['page'], $pag['limit'], $base); ?>
</div>
