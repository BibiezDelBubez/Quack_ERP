<?php
/**
 * app/views/warehouse/inventories_list.php
 * Storico sessioni di inventario fisico.
 * Variabili: $inventari, $total, $pag, $search, $stato
 */
$base = url('warehouse/inventories');
$sort = $pag['sort'];
$dir  = $pag['dir'];
?>

<?php $active_tab = 'inventories'; include __DIR__ . '/_subnav.php'; ?>

<!-- Filtri rapidi + ricerca -->
<div class="qk-toolbar mb-3">
  <div class="qk-filter-pills">
    <?php
    $statoOpts = ['' => 'Tutti', 'bozza' => 'In Bozza', 'convalidato' => 'Convalidati'];
    foreach ($statoOpts as $val => $lbl):
      $qs = array_filter(['q' => $search, 'stato' => $val, 'sort' => $sort !== 'id' ? $sort : null, 'dir' => $dir !== 'desc' ? $dir : null]);
      $href = $base . ($qs ? '?' . http_build_query($qs) : '');
    ?>
    <a href="<?= e($href) ?>" class="qk-filter-pill <?= ($stato ?? '') === $val ? 'active' : '' ?>">
      <?= e($lbl) ?>
    </a>
    <?php endforeach; ?>
  </div>

  <form method="get" action="<?= e($base) ?>" class="qk-search-form">
    <?php if (!empty($stato)): ?>
      <input type="hidden" name="stato" value="<?= e($stato) ?>">
    <?php endif; ?>
    <?php if ($sort !== 'id'):  ?><input type="hidden" name="sort" value="<?= e($sort) ?>"><?php endif; ?>
    <?php if ($dir  !== 'desc'): ?><input type="hidden" name="dir"  value="<?= e($dir) ?>"><?php endif; ?>
    <div class="qk-search-wrap">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input type="search" name="q" value="<?= e($search) ?>"
             placeholder="Cerca per utente o note…" class="qk-form-control" autocomplete="off">
    </div>
  </form>

  <a href="<?= url('warehouse/inventories/create') ?>" class="btn-qk btn-qk-primary btn-qk-sm">
    <i class="fa-solid fa-plus me-1"></i>Nuovo Inventario
  </a>
</div>

<!-- Tabella -->
<div class="qk-card">
  <div class="qk-card-body p-0">
    <table class="qk-table">
      <thead>
        <tr>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'id', $sort, $dir)) ?>">#<?= qk_sort_icon('id', $sort, $dir) ?></a></th>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'data', $sort, $dir)) ?>">Data inventario<?= qk_sort_icon('data', $sort, $dir) ?></a></th>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'stato', $sort, $dir)) ?>">Stato<?= qk_sort_icon('stato', $sort, $dir) ?></a></th>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'creatore', $sort, $dir)) ?>">Aperto da<?= qk_sort_icon('creatore', $sort, $dir) ?></a></th>
          <th>Note</th>
          <th>Creato il</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($inventari)): ?>
        <tr>
          <td colspan="7" class="qk-table-empty">
            <?php if ($search !== '' || !empty($stato)): ?>
              Nessun risultato per i filtri applicati.
            <?php else: ?>
              Nessuna sessione di inventario registrata.
            <?php endif; ?>
          </td>
        </tr>
        <?php else: ?>
        <?php foreach ($inventari as $inv): ?>
        <tr>
          <td class="fw-semibold">#<?= $inv['id'] ?></td>
          <td><?= fmt_date($inv['data_inventario']) ?></td>
          <td>
            <?php if ($inv['stato'] === 'convalidato'): ?>
              <span class="qk-badge confirmed">Convalidato</span>
            <?php else: ?>
              <span class="qk-badge draft">In Bozza</span>
            <?php endif; ?>
          </td>
          <td><?= e($inv['creatore_username']) ?></td>
          <td class="qk-text-muted"><?= e($inv['note']) ?: '—' ?></td>
          <td class="qk-text-muted"><?= fmt_datetime($inv['created_at']) ?></td>
          <td class="text-end">
            <a href="<?= url('warehouse/inventories/create') . '?id=' . $inv['id'] ?>"
               class="btn-qk btn-qk-xs btn-qk-secondary">
              <i class="fa-solid <?= $inv['stato'] === 'convalidato' ? 'fa-eye' : 'fa-pen' ?>"></i>
              <?= $inv['stato'] === 'convalidato' ? 'Visualizza' : 'Modifica' ?>
            </a>
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
    'q'     => $search,
    'stato' => $stato ?? '',
    'sort'  => $sort !== 'id'   ? $sort : null,
    'dir'   => $dir  !== 'desc' ? $dir  : null,
]));
qk_paginate($total, $pag['page'], $pag['limit'], $pagUrl);
?>
