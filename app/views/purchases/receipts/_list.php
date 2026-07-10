<?php
/**
 * purchases/receipts/_list.php
 * Variabili: $ricezioni_list, $search, $stato, $dal, $al, $pag, $total
 */
function ricezione_badge(string $stato): string {
    $map = ['bozza' => ['draft','Bozza'], 'completata' => ['confirmed','Completata'], 'annullata' => ['cancelled','Annullata']];
    [$cls, $lbl] = $map[$stato] ?? ['draft', $stato];
    return '<span class="qk-badge ' . $cls . '">' . e($lbl) . '</span>';
}
$statiLabels = ['' => 'Tutti', 'bozza' => 'Bozze', 'completata' => 'Completate', 'annullata' => 'Annullate'];
$base = url('purchases/receipts');
$sort = $pag['sort']; $dir = $pag['dir'];
$hasFilters = $search !== '' || $dal !== '' || $al !== '';
?>

<?php $active_tab = 'receipts'; include __DIR__ . '/../_subnav.php'; ?>


<div class="qk-card">

  <div class="qk-table-toolbar">
    <form method="get" action="<?= $base ?>" class="qk-table-toolbar-left">
      <div class="qk-filter-wrap">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="text" name="q" class="qk-filter-input" placeholder="Numero, ordine, fornitore…" value="<?= e($search) ?>" autocomplete="off">
      </div>
      <div class="qk-filter-date-group">
        <label>Da ricezione</label>
        <input type="date" name="dal" class="qk-filter-input qk-filter-date" value="<?= e($dal) ?>">
      </div>
      <div class="qk-filter-date-group">
        <label>A ricezione</label>
        <input type="date" name="al" class="qk-filter-input qk-filter-date" value="<?= e($al) ?>">
      </div>
      <?php if ($sort !== 'data'): ?><input type="hidden" name="sort" value="<?= e($sort) ?>"><?php endif; ?>
      <?php if ($dir  !== 'desc'): ?><input type="hidden" name="dir"  value="<?= e($dir) ?>"><?php endif; ?>
      <button type="submit" class="btn-qk btn-qk-secondary btn-qk-sm" title="Filtra"><i class="fa-solid fa-magnifying-glass"></i></button>
      <?php if ($hasFilters): ?><a href="<?= $base ?>" class="btn-qk btn-qk-secondary btn-qk-sm" title="Rimuovi filtri"><i class="fa-solid fa-xmark"></i></a><?php endif; ?>
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
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'data', $sort, $dir)) ?>">Data ricezione<?= qk_sort_icon('data', $sort, $dir) ?></a></th>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'stato', $sort, $dir)) ?>">Stato<?= qk_sort_icon('stato', $sort, $dir) ?></a></th>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'numero', $sort, $dir)) ?>">Numero<?= qk_sort_icon('numero', $sort, $dir) ?></a></th>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'ordine', $sort, $dir)) ?>">Ordine<?= qk_sort_icon('ordine', $sort, $dir) ?></a></th>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'fornitore', $sort, $dir)) ?>">Fornitore<?= qk_sort_icon('fornitore', $sort, $dir) ?></a></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($ricezioni_list)): ?>
        <tr><td colspan="6" class="qk-table-empty"><i class="fa-solid fa-inbox"></i> Nessuna ricezione trovata.</td></tr>
        <?php else: foreach ($ricezioni_list as $r): ?>
        <tr>
          <td><?= $r['data_effettiva'] ? e(fmt_date($r['data_effettiva'])) : '<span class="qk-text-muted">In attesa</span>' ?></td>
          <td><?= ricezione_badge($r['stato']) ?></td>
          <td><a href="<?= url('purchases/receipts/edit') . '?id=' . $r['id'] ?>" class="qk-link-strong"><?= e($r['numero']) ?></a></td>
          <td><?php if (!empty($r['ordine_numero'])): ?>
            <a href="<?= url('purchases/orders/edit') . '?id=' . $r['ordine_id'] ?>" class="qk-text-muted" style="font-size:.82rem"><?= e($r['ordine_numero']) ?></a>
          <?php else: ?><span class="qk-text-muted">—</span><?php endif; ?></td>
          <td><?= e($r['fornitore_nome'] ?? '—') ?></td>
          <td class="qk-table-actions">
            <a href="<?= url('purchases/receipts/edit') . '?id=' . $r['id'] ?>" class="btn-qk btn-qk-xs btn-qk-secondary" title="Apri"><i class="fa-solid fa-pencil"></i></a>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php qk_paginate($total, $pag['page'], $pag['limit'], $base); ?>

</div>
