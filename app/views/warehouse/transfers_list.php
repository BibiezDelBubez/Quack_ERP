<?php
/**
 * warehouse/transfers_list.php
 * Variabili: $trasferimenti, $total, $search, $stato, $pag
 */
$statoLabels = [
    ''            => 'Tutti gli stati',
    'bozza'       => 'Bozza',
    'in_transito' => 'In transito',
    'completato'  => 'Completato',
    'annullato'   => 'Annullato',
];
$statoBadgeMap = [
    'bozza'       => ['label' => 'Bozza',       'cls' => 'draft'],
    'in_transito' => ['label' => 'In transito', 'cls' => 'pending'],
    'completato'  => ['label' => 'Completato',  'cls' => 'confirmed'],
    'annullato'   => ['label' => 'Annullato',   'cls' => 'cancelled'],
];
$base = url('warehouse/transfers');
$sort = $pag['sort']; $dir = $pag['dir'];
$hasFilters = $search !== '';
?>
<?php $active_tab = 'transfers'; include __DIR__ . '/_subnav.php'; ?>

<div class="qk-card">
  <div class="qk-table-toolbar">
    <form method="get" action="<?= $base ?>" class="qk-table-toolbar-left">
      <div class="qk-filter-wrap">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="text" name="q" class="qk-filter-input"
               placeholder="Numero, magazzino…" value="<?= e($search) ?>" autocomplete="off">
      </div>
      <?php if ($stato !== ''): ?><input type="hidden" name="stato" value="<?= e($stato) ?>"><?php endif; ?>
      <?php if ($sort !== 'data'): ?><input type="hidden" name="sort" value="<?= e($sort) ?>"><?php endif; ?>
      <?php if ($dir  !== 'desc'): ?><input type="hidden" name="dir"  value="<?= e($dir) ?>"><?php endif; ?>
      <button type="submit" class="btn-qk btn-qk-secondary btn-qk-sm" title="Filtra">
        <i class="fa-solid fa-magnifying-glass"></i>
      </button>
      <?php if ($hasFilters): ?>
      <a href="<?= $base ?><?= $stato ? '?stato='.e($stato) : '' ?>"
         class="btn-qk btn-qk-secondary btn-qk-sm" title="Rimuovi filtri">
        <i class="fa-solid fa-xmark"></i>
      </a>
      <?php endif; ?>
    </form>
    <div class="qk-table-toolbar-right">
      <div class="qk-filter-pills">
        <?php foreach ($statoLabels as $k => $lbl): ?>
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
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'numero', $sort, $dir)) ?>">Numero<?= qk_sort_icon('numero', $sort, $dir) ?></a></th>
          <th>Origine</th>
          <th>Destinazione</th>
          <th class="text-center">Righe</th>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'stato', $sort, $dir)) ?>">Stato<?= qk_sort_icon('stato', $sort, $dir) ?></a></th>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'data', $sort, $dir)) ?>">Data richiesta<?= qk_sort_icon('data', $sort, $dir) ?></a></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($trasferimenti)): ?>
        <tr><td colspan="6" class="qk-table-empty"><i class="fa-solid fa-truck-arrow-right"></i> Nessun trasferimento trovato.</td></tr>
        <?php else: foreach ($trasferimenti as $t):
          $bd = $statoBadgeMap[$t['stato']] ?? ['label' => $t['stato'], 'cls' => 'draft'];
        ?>
        <tr>
          <td>
            <a href="<?= url('warehouse/transfers/view?id=' . $t['id']) ?>" class="qk-link-strong">
              <?= e($t['numero']) ?>
            </a>
          </td>
          <td><?= e($t['magazzino_origine_nome']) ?></td>
          <td><?= e($t['magazzino_destinazione_nome']) ?></td>
          <td class="text-center"><?= (int) $t['n_righe'] ?></td>
          <td><span class="qk-badge <?= $bd['cls'] ?>"><?= e($bd['label']) ?></span></td>
          <td class="qk-text-muted" style="font-size:.82rem"><?= e(fmt_date($t['data_richiesta'])) ?></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php qk_paginate($total, $pag['page'], $pag['limit'], $base); ?>
</div>
