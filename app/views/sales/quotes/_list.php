<?php
/**
 * sales/quotes/_list.php — Vista HTML della lista offerte.
 * Variabili attese: $offers, $total, $search, $status
 */
?>

<!-- Sotto-navigazione condivisa -->
<?php include APP_ROOT_DIR . '/app/views/sales/_subnav.php'; ?>

<div class="qk-card">
  <div class="qk-table-toolbar">
    <div class="qk-table-toolbar-left">
      <form method="get" class="d-flex gap-2 flex-wrap align-items-center">
        <div class="qk-filter-wrap">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input type="text" name="q" class="qk-filter-input" placeholder="Cerca offerta, cliente…" value="<?= e($search) ?>">
        </div>
        <select name="status" class="qk-filter-input" style="width:auto">
          <option value="">Tutti gli stati</option>
          <?php foreach (['bozza'=>'Bozza','inviata'=>'Inviata','accettata'=>'Accettata','rifiutata'=>'Rifiutata','scaduta'=>'Scaduta','annullata'=>'Annullata'] as $k => $lbl): ?>
          <option value="<?= e($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn-qk btn-qk-secondary btn-qk-sm"><i class="fa-solid fa-filter"></i> Filtra</button>
        <?php if ($search || $status): ?><a href="<?= url('sales/quotes') ?>" class="btn-qk btn-qk-secondary btn-qk-sm"><i class="fa-solid fa-xmark"></i> Pulisci</a><?php endif; ?>
      </form>
    </div>
    <div class="qk-table-toolbar-right"><small class="qk-text-muted"><?= $total ?> offerte</small></div>
  </div>
  <div class="qk-table-wrap">
    <?php if (empty($offers)): ?>
    <div class="qk-empty">
      <i class="fa-regular fa-file-invoice"></i>
      <p><?= ($search || $status) ? 'Nessuna offerta trovata con i filtri selezionati.' : 'Nessuna offerta ancora.' ?></p>
      <?php if (!$search && !$status): ?><a href="<?= url('sales/quotes/create') ?>" class="btn-qk btn-qk-primary"><i class="fa-solid fa-plus"></i> Crea la prima offerta</a><?php endif; ?>
    </div>
    <?php else: ?>
    <table class="qk-table">
      <thead><tr><th>N° Preventivo</th><th>Cliente</th><th>Canale</th><th>Stato</th><th class="text-end">Importo</th><th>Valida fino al</th><th class="col-actions"></th></tr></thead>
      <tbody>
        <?php foreach ($offers as $o): ?>
        <tr>
          <td class="fw-semibold">
            <a href="<?= url('sales/quotes/edit?id=' . $o['id']) ?>" class="qk-text-accent fw-bold text-decoration-none">
              <?= e($o['numero_preventivo'] ?? '') ?>
            </a>
          </td>
          <td><?= e($o['cliente_nome'] ?? '') ?></td>
          <td class="qk-text-secondary"><?= e($o['canale_vendita'] ?? '—') ?></td>
          <td><?= !empty($o['stato']) ? '<span class="qk-badge">' . e(ucfirst($o['stato'])) . '</span>' : '<span class="qk-text-muted">—</span>' ?></td>
          <td class="text-end fw-semibold"><?= fmt_money((float)($o['totale'] ?? 0)) ?></td>
          <td class="qk-text-muted"><?= fmt_date($o['data_scadenza'] ?? '') ?></td>
          <td class="col-actions"></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>
