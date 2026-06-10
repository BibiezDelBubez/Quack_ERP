<?php
/**
 * app/views/warehouse/reorder.php
 * Proposte di riordino per articoli sottoscorta.
 * Variabili: $proposals, $total, $pag, $search
 */
$base = url('warehouse/reorder');
$sort = $pag['sort'];
$dir  = $pag['dir'];
?>

<?php $active_tab = 'reorder'; include __DIR__ . '/_subnav.php'; ?>

<!-- Ricerca -->
<div class="qk-toolbar mb-3">
  <form method="get" action="<?= e($base) ?>" class="qk-search-form">
    <?php if ($sort !== 'nome'):  ?><input type="hidden" name="sort" value="<?= e($sort) ?>"><?php endif; ?>
    <?php if ($dir  !== 'asc'):   ?><input type="hidden" name="dir"  value="<?= e($dir) ?>"><?php endif; ?>
    <div class="qk-search-wrap">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input type="search" name="q" value="<?= e($search) ?>"
             placeholder="Cerca prodotto o SKU…" class="qk-form-control" autocomplete="off">
    </div>
  </form>

  <?php if ($total > 0): ?>
    <span class="qk-text-muted" style="font-size:.85rem">
      <?= $total ?> articolo<?= $total !== 1 ? 'i' : '' ?> sottoscorta
    </span>
  <?php endif; ?>
</div>

<!-- Tabella -->
<div class="qk-card">
  <div class="qk-card-body p-0">
    <table class="qk-table">
      <thead>
        <tr>
          <th>SKU</th>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'nome', $sort, $dir)) ?>">Prodotto<?= qk_sort_icon('nome', $sort, $dir) ?></a></th>
          <th class="text-end">Disponibile</th>
          <th class="text-end">Riservata</th>
          <th class="text-end qk-sortable"><a href="<?= e(qk_sort_url($base, 'arrivo', $sort, $dir)) ?>">In arrivo<?= qk_sort_icon('arrivo', $sort, $dir) ?></a></th>
          <th class="text-end qk-sortable"><a href="<?= e(qk_sort_url($base, 'libera', $sort, $dir)) ?>">Libera<?= qk_sort_icon('libera', $sort, $dir) ?></a></th>
          <th class="text-end qk-sortable"><a href="<?= e(qk_sort_url($base, 'minima', $sort, $dir)) ?>">Scorta min.<?= qk_sort_icon('minima', $sort, $dir) ?></a></th>
          <th class="text-end">Q.tà consigliata</th>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'fornitore', $sort, $dir)) ?>">Fornitore<?= qk_sort_icon('fornitore', $sort, $dir) ?></a></th>
          <th class="text-end">Ultimo prezzo</th>
          <th class="text-end">Azioni</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($proposals)): ?>
        <tr>
          <td colspan="11" class="qk-table-empty">
            <?php if ($search !== ''): ?>
              Nessun articolo sottoscorta corrisponde alla ricerca.
            <?php else: ?>
              Nessun articolo sottoscorta — ottimo!
            <?php endif; ?>
          </td>
        </tr>
        <?php else: ?>
        <?php foreach ($proposals as $p): ?>
        <?php $qtaConsigliata = max(0, $p['scorta_minima'] - $p['qta_libera']); ?>
        <tr>
          <td><code><?= e($p['sku_interno']) ?></code></td>
          <td class="fw-semibold">
            <a href="<?= url('products/edit?id=' . $p['id']) ?>" class="qk-text-accent text-decoration-none">
              <?= e($p['nome']) ?>
            </a>
          </td>
          <td class="text-end"><?= number_format((float)$p['qta_disponibile'], 3, ',', '.') ?> <?= e($p['uom']) ?></td>
          <td class="text-end qk-text-muted"><?= number_format((float)$p['qta_riservata'], 3, ',', '.') ?> <?= e($p['uom']) ?></td>
          <td class="text-end" style="color:var(--qk-success)"><?= number_format((float)$p['qta_in_arrivo'], 3, ',', '.') ?> <?= e($p['uom']) ?></td>
          <td class="text-end fw-bold" style="color:var(--qk-danger)"><?= number_format((float)$p['qta_libera'], 3, ',', '.') ?> <?= e($p['uom']) ?></td>
          <td class="text-end qk-text-muted"><?= number_format((float)$p['scorta_minima'], 3, ',', '.') ?> <?= e($p['uom']) ?></td>
          <td class="text-end fw-bold qk-text-accent"><?= number_format((float)$qtaConsigliata, 3, ',', '.') ?> <?= e($p['uom']) ?></td>
          <td>
            <?php if ($p['fornitore_nome']): ?>
              <?php if ($p['fornitore_id']): ?>
                <a href="<?= url('suppliers/edit?id=' . $p['fornitore_id']) ?>" class="qk-text-accent text-decoration-none fw-medium">
                  <?= e($p['fornitore_nome']) ?>
                </a>
              <?php else: ?>
                <?= e($p['fornitore_nome']) ?>
              <?php endif; ?>
            <?php else: ?>
              <span class="qk-text-muted">—</span>
            <?php endif; ?>
          </td>
          <td class="text-end">
            <?php if ($p['ultimo_prezzo'] !== null): ?>
              <?= fmt_money((float)$p['ultimo_prezzo']) ?>
            <?php else: ?>
              <span class="qk-text-muted">—</span>
            <?php endif; ?>
          </td>
          <td class="text-end">
            <?php if ($p['fornitore_id']): ?>
              <a href="<?= url('purchases/rfq/create') . '?fornitore_id=' . $p['fornitore_id'] . '&prodotto_id=' . $p['id'] . '&quantita=' . $qtaConsigliata ?>"
                 class="btn-qk btn-qk-primary btn-qk-xs">
                <i class="fa-solid fa-file-invoice-dollar me-1"></i>Genera RFQ
              </a>
            <?php else: ?>
              <a href="<?= url('purchases/rfq/create') . '?prodotto_id=' . $p['id'] . '&quantita=' . $qtaConsigliata ?>"
                 class="btn-qk btn-qk-secondary btn-qk-xs">
                <i class="fa-solid fa-file-invoice-dollar me-1"></i>Genera RFQ
              </a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Paginazione -->
<?php
$pagUrl = $base . '?' . http_build_query(array_filter([
    'q'    => $search,
    'sort' => $sort !== 'nome' ? $sort : null,
    'dir'  => $dir  !== 'asc'  ? $dir  : null,
]));
qk_paginate($total, $pag['page'], $pag['limit'], $pagUrl);
?>
