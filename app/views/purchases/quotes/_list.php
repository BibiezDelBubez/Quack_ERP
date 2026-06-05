<?php
/**
 * purchases/quotes/_list.php
 * Variabili: $offerte_list, $search, $stato, $dal, $al, $pag, $total
 */
function offerta_badge(string $stato): string {
    $map = ['in_valutazione' => ['pending','In valutazione'], 'accettata' => ['confirmed','Accettata'], 'rifiutata' => ['draft','Rifiutata']];
    [$cls, $lbl] = $map[$stato] ?? ['draft', $stato];
    return '<span class="qk-badge ' . $cls . '">' . e($lbl) . '</span>';
}
$statiLabels = ['' => 'Tutti', 'in_valutazione' => 'In Valutazione', 'accettata' => 'Accettati', 'rifiutata' => 'Rifiutati'];
$base = url('purchases/quotes');
$sort = $pag['sort']; $dir = $pag['dir'];
$hasFilters = $search !== '' || $dal !== '' || $al !== '';
?>

<?php $active_tab = 'quotes'; include __DIR__ . '/../_subnav.php'; ?>

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
          <th>RFQ origine</th>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'fornitore', $sort, $dir)) ?>">Fornitore<?= qk_sort_icon('fornitore', $sort, $dir) ?></a></th>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'scadenza', $sort, $dir)) ?>">Scadenza<?= qk_sort_icon('scadenza', $sort, $dir) ?></a></th>
          <th class="qk-sortable qk-text-right"><a href="<?= e(qk_sort_url($base, 'totale', $sort, $dir)) ?>">Totale<?= qk_sort_icon('totale', $sort, $dir) ?></a></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($offerte_list)): ?>
        <tr><td colspan="8" class="qk-table-empty"><i class="fa-solid fa-inbox"></i> Nessun preventivo trovato.</td></tr>
        <?php else: foreach ($offerte_list as $o): ?>
        <tr>
          <td><?= e(fmt_date($o['data_offerta'] ?? '')) ?: '—' ?></td>
          <td><?= offerta_badge($o['stato']) ?></td>
          <td><a href="<?= url('purchases/quotes/edit') . '?id=' . $o['id'] ?>" class="qk-link-strong"><?= e($o['numero']) ?></a></td>
          <td><?php if (!empty($o['rfq_id'])): ?>
            <a href="<?= url('purchases/rfq/edit') . '?id=' . $o['rfq_id'] ?>" class="qk-text-muted" style="font-size:.82rem"><?= e($o['rfq_numero'] ?: '#'.$o['rfq_id']) ?></a>
          <?php else: ?><span class="qk-text-muted">Spontaneo</span><?php endif; ?></td>
          <td><?= e($o['fornitore_nome'] ?? '—') ?></td>
          <td><?php
            if ($o['data_scadenza_offerta']) {
                $scaduta = new DateTime($o['data_scadenza_offerta']) < new DateTime();
                echo '<span' . ($scaduta ? ' class="qk-text-danger"' : '') . '>' . e(fmt_date($o['data_scadenza_offerta'])) . '</span>';
            } else { echo '—'; }
          ?></td>
          <td class="qk-text-right" style="font-weight:600"><?= $o['totale'] !== null ? '€ ' . number_format((float)$o['totale'], 2, ',', '.') : '—' ?></td>
          <td class="qk-table-actions">
            <a href="<?= url('purchases/quotes/edit') . '?id=' . $o['id'] ?>" class="btn-qk btn-qk-xs btn-qk-secondary" title="Apri"><i class="fa-solid fa-pencil"></i></a>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php qk_paginate($total, $pag['page'], $pag['limit'], $base); ?>

</div>
