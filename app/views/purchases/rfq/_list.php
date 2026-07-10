<?php
/**
 * purchases/rfq/_list.php
 * Variabili: $rfq_list, $search, $stato, $dal, $al, $pag, $total
 */
function rfq_badge(string $stato, ?string $esitoChiusura = null): string {
    $map = ['bozza' => ['draft','Bozza'], 'inviata' => ['confirmed','Inviata'],
            'revisionata' => ['pending','Revisionata'], 'chiusa' => ['delivered','Chiusa']];
    [$cls, $lbl] = $map[$stato] ?? ['draft', $stato];
    // Lo stato 'chiusa' da solo non dice se la RFQ ha prodotto un ordine o è
    // stata abbandonata — lo specifichiamo con un'etichetta secondaria.
    if ($stato === 'chiusa' && $esitoChiusura) {
        $lbl .= $esitoChiusura === 'offerta_accettata' ? ' (offerta accettata)' : ' (annullata)';
    }
    return '<span class="qk-badge ' . $cls . '">' . e($lbl) . '</span>';
}
$statiLabels = ['' => 'Tutti', 'bozza' => 'Bozza', 'inviata' => 'Inviate',
                'revisionata' => 'Revisionate', 'chiusa' => 'Chiuse'];
$base = url('purchases/rfq');
$sort = $pag['sort']; $dir = $pag['dir'];
$hasFilters = $search !== '' || $dal !== '' || $al !== '';
?>

<?php $active_tab = 'rfq'; include __DIR__ . '/../_subnav.php'; ?>


<div class="qk-card">

  <div class="qk-table-toolbar">
    <form method="get" action="<?= $base ?>" class="qk-table-toolbar-left">
      <div class="qk-filter-wrap">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="text" name="q" class="qk-filter-input" placeholder="Numero, riferimento, fornitore…" value="<?= e($search) ?>" autocomplete="off">
      </div>
      <div class="qk-filter-date-group">
        <label>Da emissione</label>
        <input type="date" name="dal" class="qk-filter-input qk-filter-date" value="<?= e($dal) ?>">
      </div>
      <div class="qk-filter-date-group">
        <label>A emissione</label>
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
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'rif', $sort, $dir)) ?>">Rif. interno<?= qk_sort_icon('rif', $sort, $dir) ?></a></th>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'data', $sort, $dir)) ?>">Data<?= qk_sort_icon('data', $sort, $dir) ?></a></th>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'stato', $sort, $dir)) ?>">Stato<?= qk_sort_icon('stato', $sort, $dir) ?></a></th>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'numero', $sort, $dir)) ?>">Numero<?= qk_sort_icon('numero', $sort, $dir) ?></a></th>
          <th>Ver.</th>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'fornitore', $sort, $dir)) ?>">Fornitore<?= qk_sort_icon('fornitore', $sort, $dir) ?></a></th>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'scadenza', $sort, $dir)) ?>">Scadenza<?= qk_sort_icon('scadenza', $sort, $dir) ?></a></th>
          <th class="qk-sortable qk-text-right"><a href="<?= e(qk_sort_url($base, 'totale', $sort, $dir)) ?>">Totale<?= qk_sort_icon('totale', $sort, $dir) ?></a></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rfq_list)): ?>
        <tr><td colspan="9" class="qk-table-empty"><i class="fa-solid fa-inbox"></i> Nessuna RFQ trovata.</td></tr>
        <?php else: foreach ($rfq_list as $r): ?>
        <tr>
          <td><a href="<?= url('purchases/rfq/edit') . '?id=' . $r['id'] ?>" class="qk-link-strong"><?= e($r['riferimento_rfq_interno'] ?: '—') ?></a></td>
          <td><?= e(fmt_date($r['data_emissione'] ?? '')) ?: '—' ?></td>
          <td><?= rfq_badge($r['stato'], $r['esito_chiusura'] ?? null) ?></td>
          <td class="qk-text-muted" style="font-size:.85rem"><?= e($r['numero']) ?></td>
          <td class="qk-text-muted">v<?= (int)$r['versione'] ?></td>
          <td><?= e($r['fornitore_nome'] ?? '—') ?></td>
          <td><?php
            if ($r['data_scadenza']) {
                $scaduta = new DateTime($r['data_scadenza']) < new DateTime();
                echo '<span' . ($scaduta ? ' class="qk-text-danger"' : '') . '>' . e(fmt_date($r['data_scadenza'])) . '</span>';
            } else { echo '—'; }
          ?></td>
          <td class="qk-text-right"><?= $r['totale'] !== null ? '€ ' . number_format((float)$r['totale'], 2, ',', '.') : '—' ?></td>
          <td class="qk-table-actions">
            <a href="<?= url('purchases/rfq/edit') . '?id=' . $r['id'] ?>" class="btn-qk btn-qk-xs btn-qk-secondary" title="Apri"><i class="fa-solid fa-pencil"></i></a>
            <?php if ($r['stato'] === 'bozza'): ?>
            <form method="post" action="<?= url('purchases/rfq/delete') ?>" class="d-inline" onsubmit="return confirm('Eliminare la RFQ <?= e($r['numero']) ?>?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>"><button type="submit" class="btn-qk btn-qk-xs btn-qk-ghost" title="Elimina"><i class="fa-solid fa-trash"></i></button></form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php qk_paginate($total, $pag['page'], $pag['limit'], $base); ?>

</div>
