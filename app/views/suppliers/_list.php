<?php
/**
 * suppliers/_list.php
 * Variabili: $suppliers, $total, $search, $adr_only, $pag, $base
 */
$sort = $pag['sort']; $dir = $pag['dir'];
$hasFilters = $search !== '';
?>
<div class="qk-card">
  <div class="qk-table-toolbar">
    <form method="get" action="<?= $base ?>" class="qk-table-toolbar-left">
      <div class="qk-filter-wrap">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="text" name="q" class="qk-filter-input"
               placeholder="Ragione sociale, p.iva, referente…" value="<?= e($search) ?>" autocomplete="off">
      </div>
      <?php if ($sort !== 'nome'): ?><input type="hidden" name="sort" value="<?= e($sort) ?>"><?php endif; ?>
      <?php if ($dir  !== 'asc'):  ?><input type="hidden" name="dir"  value="<?= e($dir) ?>"><?php endif; ?>
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
      <small class="qk-text-muted"><?= $total ?> fornitor<?= $total === 1 ? 'e' : 'i' ?></small>
    </div>
  </div>

  <div class="qk-table-wrap">
    <?php if (empty($suppliers)): ?>
    <div class="qk-empty">
      <i class="fa-regular fa-truck"></i>
      <p><?= $hasFilters ? 'Nessun fornitore trovato con i filtri selezionati.' : 'Nessun fornitore ancora.' ?></p>
      <?php if (!$hasFilters): ?>
      <a href="<?= url('suppliers/create') ?>" class="btn-qk btn-qk-primary">
        <i class="fa-solid fa-plus"></i> Aggiungi il primo fornitore
      </a>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <table class="qk-table">
      <thead>
        <tr>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'nome', $sort, $dir)) ?>">Ragione sociale<?= qk_sort_icon('nome', $sort, $dir) ?></a></th>
          <th>P. IVA</th>
          <th>Referente comm.</th>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'email', $sort, $dir)) ?>">Email<?= qk_sort_icon('email', $sort, $dir) ?></a></th>
          <th class="text-center">Lead time</th>
          <th>Pagamento</th>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'status', $sort, $dir)) ?>">Stato<?= qk_sort_icon('status', $sort, $dir) ?></a></th>
          <th class="col-actions"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($suppliers as $s): ?>
        <tr>
          <td class="fw-semibold">
            <a href="<?= url('suppliers/edit?id=' . $s['id']) ?>" class="qk-link-strong"><?= e($s['company']) ?></a>
          </td>
          <td class="qk-text-muted" style="font-family:monospace;font-size:.82rem"><?= e($s['vat']) ?: '—' ?></td>
          <td class="qk-text-secondary"><?= $s['referent'] ? e($s['referent']) : '<span class="qk-text-muted">—</span>' ?></td>
          <td class="qk-text-secondary"><?= $s['email'] ? e($s['email']) : '<span class="qk-text-muted">—</span>' ?></td>
          <td class="text-center qk-text-muted"><?= $s['lead_time'] !== null ? $s['lead_time'] . ' gg' : '—' ?></td>
          <td class="qk-text-muted" style="font-size:.82rem"><?= $s['payment_terms'] ? e(truncate($s['payment_terms'], 25)) : '—' ?></td>
          <td><?= status_badge($s['status']) ?></td>
          <td class="col-actions">
            <a href="<?= url('suppliers/edit?id=' . $s['id']) ?>" class="btn-qk btn-qk-secondary btn-qk-sm" title="Modifica">
              <i class="fa-solid fa-pen-to-square"></i>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <?php qk_paginate($total, $pag['page'], $pag['limit'], $base); ?>
</div>
