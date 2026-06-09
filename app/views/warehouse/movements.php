<?php
/**
 * warehouse/movements.php
 * Variabili: $movements, $total, $search, $tipo, $dal, $al, $prodotto_id, $pag, $base
 */
$tipoLabels = [
    ''               => 'Tutti i tipi',
    'carico'         => 'Carico',
    'scarico'        => 'Scarico',
    'rettifica'      => 'Rettifica',
    'reso_cliente'   => 'Reso cliente',
    'reso_fornitore' => 'Reso fornitore',
    'riserva'        => 'Riserva',
    'svincolo'       => 'Svincolo',
];
$tipoBadgeMap = [
    'carico'         => ['label' => 'Carico',         'cls' => 'confirmed'],
    'scarico'        => ['label' => 'Scarico',        'cls' => 'cancelled'],
    'rettifica'      => ['label' => 'Rettifica',      'cls' => 'pending'],
    'reso_cliente'   => ['label' => 'Reso cliente',   'cls' => 'draft'],
    'reso_fornitore' => ['label' => 'Reso fornitore', 'cls' => 'draft'],
    'riserva'        => ['label' => 'Riserva',        'cls' => 'muted'],
    'svincolo'       => ['label' => 'Svincolo',       'cls' => 'muted'],
];
$sort = $pag['sort']; $dir = $pag['dir'];
$hasFilters = $search !== '' || $dal !== '' || $al !== '';
?>
<?php $active_tab = 'movements'; include __DIR__ . '/_subnav.php'; ?>

<?php if ($prodotto_id): ?>
<div class="qk-alert qk-alert-info mb-3">
  <i class="fa-solid fa-filter"></i>
  <span>Movimenti filtrati per prodotto ID #<?= (int)$prodotto_id ?> —
    <a href="<?= $base ?>">rimuovi filtro</a></span>
</div>
<?php endif; ?>

<div class="qk-card">
  <div class="qk-table-toolbar">
    <form method="get" action="<?= $base ?>" class="qk-table-toolbar-left">
      <div class="qk-filter-wrap">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="text" name="q" class="qk-filter-input"
               placeholder="Prodotto, SKU, note, rif…" value="<?= e($search) ?>" autocomplete="off">
      </div>
      <div class="qk-filter-date-group">
        <label>Da data</label>
        <input type="date" name="dal" class="qk-filter-input qk-filter-date" value="<?= e($dal) ?>">
      </div>
      <div class="qk-filter-date-group">
        <label>A data</label>
        <input type="date" name="al" class="qk-filter-input qk-filter-date" value="<?= e($al) ?>">
      </div>
      <?php if ($prodotto_id): ?><input type="hidden" name="prodotto_id" value="<?= (int)$prodotto_id ?>"><?php endif; ?>
      <?php if ($sort !== 'data'): ?><input type="hidden" name="sort" value="<?= e($sort) ?>"><?php endif; ?>
      <?php if ($dir  !== 'desc'): ?><input type="hidden" name="dir"  value="<?= e($dir) ?>"><?php endif; ?>
      <button type="submit" class="btn-qk btn-qk-secondary btn-qk-sm" title="Filtra">
        <i class="fa-solid fa-magnifying-glass"></i>
      </button>
      <?php if ($hasFilters): ?>
      <a href="<?= $base ?><?= $prodotto_id ? '?prodotto_id='.(int)$prodotto_id : '' ?>"
         class="btn-qk btn-qk-secondary btn-qk-sm" title="Rimuovi filtri">
        <i class="fa-solid fa-xmark"></i>
      </a>
      <?php endif; ?>
    </form>
    <div class="qk-table-toolbar-right">
      <div class="qk-filter-pills">
        <?php foreach ($tipoLabels as $k => $lbl): ?>
        <a href="<?= e(qk_list_url($base, ['tipo' => $k ?: null])) ?>"
           class="qk-filter-pill <?= $tipo === $k ? 'active' : '' ?>"><?= e($lbl) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="qk-table-wrap">
    <table class="qk-table">
      <thead>
        <tr>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'data', $sort, $dir)) ?>">Data<?= qk_sort_icon('data', $sort, $dir) ?></a></th>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'tipo', $sort, $dir)) ?>">Tipo<?= qk_sort_icon('tipo', $sort, $dir) ?></a></th>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'prodotto', $sort, $dir)) ?>">Prodotto<?= qk_sort_icon('prodotto', $sort, $dir) ?></a></th>
          <th>Lotto</th>
          <th class="qk-sortable qk-text-right"><a href="<?= e(qk_sort_url($base, 'qta', $sort, $dir)) ?>">Quantità<?= qk_sort_icon('qta', $sort, $dir) ?></a></th>
          <th>Origine</th>
          <th>Note / Rif.</th>
          <th>Utente</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($movements)): ?>
        <tr><td colspan="8" class="qk-table-empty"><i class="fa-solid fa-inbox"></i> Nessun movimento trovato.</td></tr>
        <?php else: foreach ($movements as $m):
          $bd = $tipoBadgeMap[$m['tipo_movimento']] ?? ['label' => $m['tipo_movimento'], 'cls' => 'draft'];
          $positivi = ['carico','reso_fornitore','svincolo'];
          $segno = in_array($m['tipo_movimento'], $positivi) ? '+' : '-';
          $qtaClass = $segno === '+' ? 'qk-text-success' : 'qk-text-danger';
        ?>
        <tr>
          <td class="text-nowrap qk-text-muted" style="font-size:.82rem"><?= e(fmt_datetime($m['created_at'])) ?></td>
          <td><span class="qk-badge <?= $bd['cls'] ?>"><?= e($bd['label']) ?></span></td>
          <td>
            <a href="<?= url('products/edit?id=' . $m['prodotto_id']) ?>" class="qk-link-strong">
              <?= e($m['prodotto_nome']) ?>
            </a>
            <?php if (!empty($m['sku_interno'])): ?>
            <span class="qk-text-muted" style="font-size:.78rem;margin-left:.3rem"><?= e($m['sku_interno']) ?></span>
            <?php endif; ?>
          </td>
          <td class="qk-text-muted" style="font-size:.82rem"><?= e($m['codice_lotto_interno'] ?? '—') ?></td>
          <td class="qk-text-right fw-semibold <?= $qtaClass ?>">
            <?= $segno ?><?= number_format((float)$m['quantita'], 3, ',', '.') ?>
            <span class="qk-text-muted fw-normal"><?= e($m['uom'] ?? '') ?></span>
          </td>
          <td class="qk-text-muted" style="font-size:.82rem"><?= e($m['canale_origine'] ?? '—') ?></td>
          <td class="qk-text-muted" style="font-size:.82rem"><?= e($m['riferimento_esterno'] ?: ($m['note'] ? truncate($m['note'], 40) : '—')) ?></td>
          <td class="qk-text-muted" style="font-size:.82rem"><?= e($m['creato_da_username'] ?? '—') ?></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php qk_paginate($total, $pag['page'], $pag['limit'], $base); ?>
</div>
