<?php
/**
 * customers/_list.php
 * Variabili: $customers, $total, $search, $type, $pag, $base
 */
$sort = $pag['sort']; $dir = $pag['dir'];
$hasFilters = $search !== '' || $type !== '';
?>
<div class="qk-card">
  <div class="qk-table-toolbar">
    <form method="get" action="<?= $base ?>" class="qk-table-toolbar-left">
      <div class="qk-filter-wrap">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="text" name="q" class="qk-filter-input"
               placeholder="Nome, email, p.iva, città…" value="<?= e($search) ?>" autocomplete="off">
      </div>
      <select name="type" class="qk-filter-input" style="width:auto">
        <option value="">Tutti i tipi</option>
        <option value="privato" <?= $type === 'privato' ? 'selected' : '' ?>>Privati</option>
        <option value="azienda" <?= $type === 'azienda' ? 'selected' : '' ?>>Aziende / Lib. Prof.</option>
      </select>
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
      <small class="qk-text-muted"><?= $total ?> client<?= $total === 1 ? 'e' : 'i' ?></small>
    </div>
  </div>

  <div class="qk-table-wrap">
    <?php if (empty($customers)): ?>
    <div class="qk-empty">
      <i class="fa-regular fa-address-book"></i>
      <p><?= $hasFilters ? 'Nessun cliente trovato con i filtri selezionati.' : 'Nessun cliente ancora.' ?></p>
      <?php if (!$hasFilters): ?>
      <a href="<?= url('customers/create') ?>" class="btn-qk btn-qk-primary">
        <i class="fa-solid fa-plus"></i> Aggiungi il primo cliente
      </a>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <table class="qk-table">
      <thead>
        <tr>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'nome', $sort, $dir)) ?>">Nome / Ragione sociale<?= qk_sort_icon('nome', $sort, $dir) ?></a></th>
          <th>Tipo</th>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'email', $sort, $dir)) ?>">Email<?= qk_sort_icon('email', $sort, $dir) ?></a></th>
          <th>Telefono</th>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'citta', $sort, $dir)) ?>">Città<?= qk_sort_icon('citta', $sort, $dir) ?></a></th>
          <th class="text-center">Ordini</th>
          <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'created', $sort, $dir)) ?>">Creato<?= qk_sort_icon('created', $sort, $dir) ?></a></th>
          <th class="col-actions"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($customers as $c): ?>
        <tr>
          <td class="fw-semibold">
            <a href="<?= url('customers/edit?id=' . $c['id']) ?>" class="qk-link-strong"><?= e($c['name']) ?></a>
          </td>
          <td>
            <?php if ($c['type'] === 'privato'): ?>
              <span class="qk-badge draft"><i class="fa-solid fa-user fa-xs me-1"></i>Privato</span>
            <?php else: ?>
              <span class="qk-badge confirmed"><i class="fa-solid fa-building fa-xs me-1"></i>Azienda</span>
            <?php endif; ?>
          </td>
          <td class="qk-text-secondary"><?= $c['email'] ? e($c['email']) : '<span class="qk-text-muted">—</span>' ?></td>
          <td class="qk-text-secondary"><?= $c['phone'] ? e($c['phone']) : '<span class="qk-text-muted">—</span>' ?></td>
          <td class="qk-text-muted"><?= $c['city'] ? e($c['city']) : '—' ?></td>
          <td class="text-center">
            <?php if ($c['orders'] > 0): ?>
              <a href="<?= url('orders?customer_id=' . $c['id']) ?>" class="qk-link-strong"><?= $c['orders'] ?></a>
            <?php else: ?>
              <span class="qk-text-muted">—</span>
            <?php endif; ?>
          </td>
          <td class="qk-text-muted"><?= fmt_date($c['created_at']) ?></td>
          <td class="col-actions">
            <a href="<?= url('customers/edit?id=' . $c['id']) ?>" class="btn-qk btn-qk-secondary btn-qk-sm" title="Modifica">
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
