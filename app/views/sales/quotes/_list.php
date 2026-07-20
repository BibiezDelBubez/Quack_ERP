<?php
/**
 * sales/quotes/_list.php
 * Variabili: $offers, $total, $search, $status, $dal, $al, $pag
 */
$statiLabels = ['' => 'Tutti', 'bozza' => 'Bozza', 'inviato' => 'Inviato',
                'accettato' => 'Accettato', 'rifiutato' => 'Rifiutato',
                'scaduto' => 'Scaduto', 'annullato' => 'Annullato'];
$base = url('sales/quotes');
$sort = $pag['sort']; $dir = $pag['dir'];
$hasFilters = $search !== '' || $dal !== '' || $al !== '';
?>

<?php $active_tab = 'quotes'; include APP_ROOT_DIR . '/app/views/sales/_subnav.php'; ?>

<div class="qk-card">

  <div class="qk-table-toolbar">
    <form method="get" action="<?= $base ?>" class="qk-table-toolbar-left">
      <div class="qk-filter-wrap">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="text" name="q" class="qk-filter-input" placeholder="Numero, riferimento, cliente…" value="<?= e($search) ?>" autocomplete="off">
      </div>
      <div class="qk-filter-date-group">
        <label>Da offerta</label>
        <input type="date" name="dal" class="qk-filter-input qk-filter-date" value="<?= e($dal) ?>">
      </div>
      <div class="qk-filter-date-group">
        <label>A offerta</label>
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
        <a href="<?= e(qk_list_url($base, ['status' => $k ?: null])) ?>"
           class="qk-filter-pill <?= $status === $k ? 'active' : '' ?>"><?= e($lbl) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="qk-table-wrap">
    <?php if (empty($offers)): ?>
    <div class="qk-empty">
      <i class="fa-regular fa-file-invoice"></i>
      <p><?= $hasFilters || $status ? 'Nessuna offerta trovata con i filtri selezionati.' : 'Nessuna offerta ancora.' ?></p>
      <?php if (!$hasFilters && !$status): ?>
      <a href="<?= url('sales/quotes/create') ?>" class="btn-qk btn-qk-primary"><i class="fa-solid fa-plus"></i> Crea la prima offerta</a>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <table class="qk-table">
      <thead>
        <tr>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'data', $sort, $dir)) ?>">Data<?= qk_sort_icon('data', $sort, $dir) ?></a></th>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'stato', $sort, $dir)) ?>">Stato<?= qk_sort_icon('stato', $sort, $dir) ?></a></th>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'numero', $sort, $dir)) ?>">N° Preventivo<?= qk_sort_icon('numero', $sort, $dir) ?></a></th>
          <th>Rif. Interno</th>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'cliente', $sort, $dir)) ?>">Cliente<?= qk_sort_icon('cliente', $sort, $dir) ?></a></th>
          <th>Canale</th>
          <th class="qk-sortable qk-text-right"><a href="<?= e(qk_sort_url($base, 'totale', $sort, $dir)) ?>">Totale<?= qk_sort_icon('totale', $sort, $dir) ?></a></th>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'scadenza', $sort, $dir)) ?>">Valida fino<?= qk_sort_icon('scadenza', $sort, $dir) ?></a></th>
          <th class="col-actions"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($offers as $o): ?>
        <tr>
          <td class="qk-text-muted"><?= fmt_date($o['data_offerta'] ?? '') ?></td>
          <td><?= prev_stato_badge($o['stato'] ?? '') ?></td>
          <td><a href="<?= url('sales/quotes/edit?id=' . $o['id']) ?>" class="qk-link-strong"><?= e($o['numero_preventivo'] ?? '') ?></a></td>
          <td class="qk-text-muted"><?= e($o['riferimento_interno'] ?: '—') ?></td>
          <td><?= e($o['cliente_nome'] ?? '—') ?></td>
          <td class="qk-text-muted" style="font-size:.82rem"><?= e($o['canale_vendita'] ?? '—') ?></td>
          <td class="qk-text-right" style="font-weight:600"><?= fmt_money((float)($o['totale'] ?? 0)) ?></td>
          <td class="qk-text-muted"><?php
            if ($o['data_scadenza']) {
                $scaduta = new DateTime($o['data_scadenza']) < new DateTime() && $o['stato'] === 'bozza';
                echo '<span' . ($scaduta ? ' class="qk-text-danger"' : '') . '>' . fmt_date($o['data_scadenza']) . '</span>';
            } else { echo '—'; }
          ?></td>
          <td class="col-actions">
            <div class="qk-flex-row qk-gap-xs justify-content-end">
              <form method="post" action="<?= url('sales/quotes/edit') . '?id=' . $o['id'] ?>" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="azione" value="duplica">
                <button type="submit" class="btn-qk btn-qk-secondary btn-qk-sm" title="Duplica preventivo">
                  <i class="fa-regular fa-copy text-success"></i>
                </button>
              </form>
              <a href="<?= url('sales/quotes/edit?id=' . $o['id']) ?>" class="btn-qk btn-qk-secondary btn-qk-sm" title="Modifica">
                <i class="fa-solid fa-pencil"></i>
              </a>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <?php qk_paginate($total, $pag['page'], $pag['limit'], $base); ?>

</div>
