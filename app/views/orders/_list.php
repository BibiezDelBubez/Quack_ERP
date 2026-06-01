<?php
/**
 * orders/_list.php — Vista HTML della lista ordini.
 * Variabili attese: $orders, $total, $search, $status, $status_options
 */
?>

<div class="qk-card">
  <div class="qk-table-toolbar">
    <div class="qk-table-toolbar-left">
      <form method="get" class="d-flex gap-2 flex-wrap align-items-center">
        <div class="qk-filter-wrap">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input type="text" name="q" class="qk-filter-input" placeholder="Cerca ordine, cliente…" value="<?= e($search) ?>">
        </div>
        <select name="status" class="qk-filter-input" style="width:auto">
          <option value="">Tutti gli stati</option>
          <?php foreach ($status_options as $s): ?>
          <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(status_label($s)) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn-qk btn-qk-secondary btn-qk-sm"><i class="fa-solid fa-filter"></i> Filtra</button>
        <?php if ($search || $status): ?>
        <a href="<?= url('orders') ?>" class="btn-qk btn-qk-secondary btn-qk-sm"><i class="fa-solid fa-xmark"></i> Pulisci</a>
        <?php endif; ?>
      </form>
    </div>
    <div class="qk-table-toolbar-right"><small class="qk-text-muted"><?= $total ?> ordini</small></div>
  </div>

  <div class="qk-table-wrap">
    <?php if (empty($orders)): ?>
    <div class="qk-empty">
      <i class="fa-regular fa-box-open"></i>
      <p><?= ($search || $status) ? 'Nessun ordine trovato con i filtri selezionati.' : 'Nessun ordine ancora.' ?></p>
      <?php if (!$search && !$status): ?>
      <a href="<?= url('orders/create') ?>" class="btn-qk btn-qk-primary"><i class="fa-solid fa-plus"></i> Crea il primo ordine</a>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <table class="qk-table">
      <thead><tr><th>N° Ordine</th><th>Cliente</th><th>Prodotti</th><th>Stato</th><th>Corriere</th><th class="text-end">Importo</th><th>Data</th><th class="col-actions"></th></tr></thead>
      <tbody>
        <?php foreach ($orders as $o): ?>
        <tr>
          <td class="fw-semibold"><?= e($o['id'] ?? '') ?></td>
          <td class="qk-text-secondary"><?= e($o['customer_name'] ?? '') ?></td>
          <td class="qk-text-secondary"><?= e(truncate($o['products_summary'] ?? '', 40)) ?></td>
          <td><?= !empty($o['status']) ? status_badge($o['status']) : '<span class="qk-text-muted">—</span>' ?></td>
          <td class="qk-text-muted"><?= e($o['shipping_carrier'] ?? '—') ?></td>
          <td class="text-end fw-semibold"><?= fmt_money((float)($o['total'] ?? 0)) ?></td>
          <td class="qk-text-muted"><?= fmt_date($o['created_at'] ?? '') ?></td>
          <td class="col-actions"></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>
