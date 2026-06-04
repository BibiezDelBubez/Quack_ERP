<?php
/**
 * customers/_list.php
 * Variabili attese: $customers (array), $total (int), $search (string), $type (string)
 */
?>
<div class="qk-card">
  <div class="qk-table-toolbar">
    <div class="qk-table-toolbar-left">
      <form method="get" class="d-flex gap-2 flex-wrap align-items-center">
        <div class="qk-filter-wrap">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input type="text" name="q" class="qk-filter-input"
                 placeholder="Cerca nome, email, p.iva, città…" value="<?= e($search) ?>">
        </div>
        <select name="type" class="qk-filter-input" style="width:auto">
          <option value="">Tutti i tipi</option>
          <option value="privato"  <?= $type === 'privato'  ? 'selected' : '' ?>>Privati</option>
          <option value="azienda"  <?= $type === 'azienda'  ? 'selected' : '' ?>>Aziende / Lib. Prof.</option>
        </select>
        <button type="submit" class="btn-qk btn-qk-secondary btn-qk-sm">
          <i class="fa-solid fa-filter"></i> Filtra
        </button>
        <?php if ($search || $type): ?>
        <a href="<?= url('customers') ?>" class="btn-qk btn-qk-secondary btn-qk-sm">
          <i class="fa-solid fa-xmark"></i> Pulisci
        </a>
        <?php endif; ?>
      </form>
    </div>
    <div class="qk-table-toolbar-right">
      <small class="qk-text-muted"><?= $total ?> client<?= $total === 1 ? 'e' : 'i' ?></small>
    </div>
  </div>

  <div class="qk-table-wrap">
    <?php if (empty($customers)): ?>
    <div class="qk-empty">
      <i class="fa-regular fa-address-book"></i>
      <p><?= ($search || $type) ? 'Nessun cliente trovato con i filtri selezionati.' : 'Nessun cliente ancora.' ?></p>
      <?php if (!$search && !$type): ?>
      <a href="<?= url('customers/create') ?>" class="btn-qk btn-qk-primary">
        <i class="fa-solid fa-plus"></i> Aggiungi il primo cliente
      </a>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <table class="qk-table">
      <thead>
        <tr>
          <th>Nome / Ragione sociale</th>
          <th>Tipo</th>
          <th>Email</th>
          <th>Telefono</th>
          <th>Città</th>
          <th class="text-center">Ordini</th>
          <th>Creato</th>
          <th class="col-actions"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($customers as $c): ?>
        <tr>
          <td class="fw-semibold">
            <a href="<?= url('customers/edit?id=' . $c['id']) ?>" class="qk-text-accent text-decoration-none">
              <?= e($c['name']) ?>
            </a>
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
              <a href="<?= url('orders?customer_id=' . $c['id']) ?>" class="qk-text-accent text-decoration-none fw-semibold">
                <?= $c['orders'] ?>
              </a>
            <?php else: ?>
              <span class="qk-text-muted">—</span>
            <?php endif; ?>
          </td>
          <td class="qk-text-muted"><?= fmt_date($c['created_at']) ?></td>
          <td class="col-actions">
            <a href="<?= url('customers/edit?id=' . $c['id']) ?>"
               class="btn-qk btn-qk-secondary btn-qk-sm" title="Modifica">
              <i class="fa-solid fa-pen-to-square"></i>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <?php if ($pages > 1): ?>
  <div class="qk-table-footer mt-2">
    <span class="qkwh-count-label"><?= $total ?> client<?= $total === 1 ? 'e' : 'i' ?></span>
    <div class="qk-pagination">
      <?php for ($p = 1; $p <= $pages; $p++): ?>
      <?php
        $params = array_filter(['q' => $search, 'type' => $type, 'page' => $p > 1 ? $p : '']);
        $qs = http_build_query(array_filter($params));
      ?>
      <a href="<?= url('customers') . ($qs ? '?' . $qs : '') ?>"
         class="qk-page-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
      <?php endfor; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
