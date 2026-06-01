<?php
/**
 * suppliers/_list.php
 * Variabili attese: $suppliers (array), $total (int), $search (string), $adr_only (bool)
 */
?>
<div class="qk-card">
  <div class="qk-table-toolbar">
    <div class="qk-table-toolbar-left">
      <form method="get" class="d-flex gap-2 flex-wrap align-items-center">
        <div class="qk-filter-wrap">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input type="text" name="q" class="qk-filter-input"
                 placeholder="Cerca ragione sociale, p.iva, referente…" value="<?= e($search) ?>">
        </div>
        <button type="submit" class="btn-qk btn-qk-secondary btn-qk-sm">
          <i class="fa-solid fa-filter"></i> Filtra
        </button>
        <?php if ($search): ?>
        <a href="<?= url('suppliers') ?>" class="btn-qk btn-qk-secondary btn-qk-sm">
          <i class="fa-solid fa-xmark"></i> Pulisci
        </a>
        <?php endif; ?>
      </form>
    </div>
    <div class="qk-table-toolbar-right">
      <small class="qk-text-muted"><?= $total ?> fornitor<?= $total === 1 ? 'e' : 'i' ?></small>
    </div>
  </div>

  <div class="qk-table-wrap">
    <?php if (empty($suppliers)): ?>
    <div class="qk-empty">
      <i class="fa-regular fa-truck"></i>
      <p><?= ($search || $adr_only) ? 'Nessun fornitore trovato con i filtri selezionati.' : 'Nessun fornitore ancora.' ?></p>
      <?php if (!$search && !$adr_only): ?>
      <a href="<?= url('suppliers/create') ?>" class="btn-qk btn-qk-primary">
        <i class="fa-solid fa-plus"></i> Aggiungi il primo fornitore
      </a>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <table class="qk-table">
      <thead>
        <tr>
          <th>Ragione sociale</th>
          <th>P. IVA</th>
          <th>Referente comm.</th>
          <th>Email</th>
          <th class="text-center">Lead time</th>
          <th>Pagamento</th>
          <th>Stato</th>
          <th class="col-actions"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($suppliers as $s): ?>
        <tr>
          <td class="fw-semibold">
            <a href="<?= url('suppliers/edit?id=' . $s['id']) ?>" class="qk-text-accent text-decoration-none">
              <?= e($s['company']) ?>
            </a>
          </td>
          <td class="qk-text-muted" style="font-family:monospace;font-size:.82rem"><?= e($s['vat']) ?: '—' ?></td>
          <td class="qk-text-secondary"><?= $s['referent'] ? e($s['referent']) : '<span class="qk-text-muted">—</span>' ?></td>
          <td class="qk-text-secondary"><?= $s['email'] ? e($s['email']) : '<span class="qk-text-muted">—</span>' ?></td>
          <td class="text-center qk-text-muted">
            <?= $s['lead_time'] !== null ? $s['lead_time'] . ' gg' : '—' ?>
          </td>
          <td class="qk-text-muted" style="font-size:.82rem"><?= $s['payment_terms'] ? e(truncate($s['payment_terms'], 25)) : '—' ?></td>
          <td><?= status_badge($s['status']) ?></td>
          <td class="col-actions">
            <a href="<?= url('suppliers/edit?id=' . $s['id']) ?>"
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
    <span class="qkwh-count-label"><?= $total ?> fornitor<?= $total === 1 ? 'e' : 'i' ?></span>
    <div class="qk-pagination">
      <?php for ($p = 1; $p <= $pages; $p++): ?>
      <?php
        $params = array_filter(['q' => $search, 'page' => $p > 1 ? $p : '']);
        $qs = http_build_query(array_filter($params));
      ?>
      <a href="<?= url('suppliers') . ($qs ? '?' . $qs : '') ?>"
         class="qk-page-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
      <?php endfor; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
