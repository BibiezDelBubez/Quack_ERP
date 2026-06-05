<?php
/**
 * purchases/orders/_list.php
 * Variabili: $ordini_list, $search, $stato, $dal, $al, $pag, $total
 */
function ordine_badge(string $stato): string {
    $map = ['bozza' => ['draft','Bozza'], 'in_approvazione' => ['pending','In approvazione'],
            'approvato' => ['confirmed','Approvato'], 'inviato' => ['active','Inviato'],
            'parzialmente_ricevuto' => ['warning','Parz. Ricevuto'],
            'completato' => ['confirmed','Completato'], 'annullato' => ['cancelled','Annullato']];
    [$cls, $lbl] = $map[$stato] ?? ['draft', $stato];
    return '<span class="qk-badge ' . $cls . '">' . e($lbl) . '</span>';
}
$statiLabels = ['' => 'Tutti', 'bozza' => 'Bozza', 'in_approvazione' => 'In Approvazione',
                'approvato' => 'Approvato', 'inviato' => 'Inviato',
                'parzialmente_ricevuto' => 'Parz. Ricevuto', 'completato' => 'Completato', 'annullato' => 'Annullato'];
$base = url('purchases/orders');
$sort = $pag['sort']; $dir = $pag['dir'];
$hasFilters = $search !== '' || $dal !== '' || $al !== '';
?>

<?php $active_tab = 'orders'; include __DIR__ . '/../_subnav.php'; ?>

<?php if (!empty($_SESSION['flash_success'])): ?>
<div class="qk-alert qk-alert-success mb-3" data-auto-dismiss="4000">
  <i class="fa-solid fa-circle-check"></i> <span><?= e($_SESSION['flash_success']) ?></span>
  <button class="qk-alert-close"><i class="fa-solid fa-xmark"></i></button>
</div><?php unset($_SESSION['flash_success']); endif; ?>

<div class="qk-card">

  <div class="qk-table-toolbar">
    <form method="get" action="<?= $base ?>" class="qk-table-toolbar-left">
      <div class="qk-filter-wrap">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="text" name="q" class="qk-filter-input" placeholder="Numero, fornitore, RFQ…" value="<?= e($search) ?>" autocomplete="off">
      </div>
      <input type="date" name="dal" class="qk-filter-input qk-filter-date" value="<?= e($dal) ?>" title="Da data">
      <input type="date" name="al"  class="qk-filter-input qk-filter-date" value="<?= e($al) ?>"  title="A data">
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
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'data', $sort, $dir)) ?>">Data<?= qk_sort_icon('data', $sort, $dir) ?></a></th>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'stato', $sort, $dir)) ?>">Stato<?= qk_sort_icon('stato', $sort, $dir) ?></a></th>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'numero', $sort, $dir)) ?>">Numero<?= qk_sort_icon('numero', $sort, $dir) ?></a></th>
          <th>Origine</th>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'fornitore', $sort, $dir)) ?>">Fornitore<?= qk_sort_icon('fornitore', $sort, $dir) ?></a></th>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'consegna', $sort, $dir)) ?>">Consegna prev.<?= qk_sort_icon('consegna', $sort, $dir) ?></a></th>
          <th class="qk-sortable qk-text-right"><a href="<?= e(qk_sort_url($base, 'totale', $sort, $dir)) ?>">Totale<?= qk_sort_icon('totale', $sort, $dir) ?></a></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($ordini_list)): ?>
        <tr><td colspan="8" class="qk-table-empty"><i class="fa-solid fa-inbox"></i> Nessun ordine trovato.</td></tr>
        <?php else: foreach ($ordini_list as $o): ?>
        <tr>
          <td><?= e(fmt_date($o['data_ordine'] ?? '')) ?: '—' ?></td>
          <td><?= ordine_badge($o['stato']) ?></td>
          <td><a href="<?= url('purchases/orders/edit') . '?id=' . $o['id'] ?>" class="qk-link-strong"><?= e($o['numero']) ?></a></td>
          <td>
            <?php if (!empty($o['offerta_numero'])): ?>
              <a href="<?= url('purchases/quotes/edit') . '?id=' . $o['offerta_id'] ?>" class="qk-text-muted" style="font-size:.82rem"><?= e($o['offerta_numero']) ?></a>
            <?php elseif (!empty($o['rfq_numero'])): ?>
              <a href="<?= url('purchases/rfq/edit') . '?id=' . $o['rfq_id'] ?>" class="qk-text-muted" style="font-size:.82rem"><?= e($o['rfq_numero']) ?></a>
            <?php else: ?><span class="qk-text-muted">—</span><?php endif; ?>
          </td>
          <td><?= e($o['fornitore_nome'] ?? '—') ?></td>
          <td><?= e(fmt_date($o['data_consegna_prevista'] ?? '')) ?: '—' ?></td>
          <td class="qk-text-right" style="font-weight:600"><?= $o['totale'] !== null ? '€ ' . number_format((float)$o['totale'], 2, ',', '.') : '—' ?></td>
          <td class="qk-table-actions">
            <a href="<?= url('purchases/orders/edit') . '?id=' . $o['id'] ?>" class="btn-qk btn-qk-xs btn-qk-secondary" title="Apri"><i class="fa-solid fa-pencil"></i></a>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php qk_paginate($total, $pag['page'], $pag['limit'], $base); ?>

</div>
<style>.qk-badge.cancelled { background:var(--qk-border-color); color:var(--qk-text-secondary); }</style>
