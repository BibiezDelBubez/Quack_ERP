<?php
function get_badge_class(string $stato): string {
    $map = [
        'bozza'                 => 'draft',
        'in_approvazione'       => 'pending',
        'approvato'             => 'confirmed',
        'inviato'               => 'active',
        'in_lavorazione'        => 'pending',
        'parzialmente_spedito'  => 'warning',
        'parzialmente_ricevuto' => 'warning',
        'spedito'               => 'active',
        'completato'            => 'confirmed',
        'annullato'             => 'draft',
    ];
    return $map[$stato] ?? 'draft';
}

function get_stato_label(string $stato): string {
    $map = [
        'bozza'                 => 'Bozza',
        'in_approvazione'       => 'In Appr.',
        'approvato'             => 'Approvato',
        'inviato'               => 'Inviato',
        'in_lavorazione'        => 'In Lavorazione',
        'parzialmente_spedito'  => 'Parz. Spedito',
        'parzialmente_ricevuto' => 'Parz. Ricevuto',
        'spedito'               => 'Spedito',
        'completato'            => 'Completato',
        'annullato'             => 'Annullato',
    ];
    return $map[$stato] ?? ucfirst($stato);
}
?>

<!-- KPI grid -->
<div class="qk-kpi-grid">
<?php foreach ($kpi as $k): ?>
  <div class="qk-kpi-card">
    <div class="qk-kpi-icon <?= e($k['color']) ?>">
      <i class="fa-solid fa-<?= e($k['icon']) ?>"></i>
    </div>
    <div class="qk-kpi-body">
      <div class="qk-kpi-value"><?= e($k['value']) ?></div>
      <div class="qk-kpi-label"><?= e($k['label']) ?></div>
    </div>
  </div>
<?php endforeach; ?>
</div>

<!-- Layout: Liste principale (Ordini Vendite + Ordini Acquisto) + Azioni Rapide a destra -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)) 260px; gap: 1.5rem; margin-top: 1.5rem; align-items: start;">

  <!-- Colonna Documenti Recenti -->
  <div style="display: flex; flex-direction: column; gap: 1.5rem; grid-column: span 1;">
    
    <!-- Ordini Clienti Recenti -->
    <div class="qk-card shadow-sm" style="margin: 0;">
      <div class="qk-card-header d-flex justify-content-between align-items-center">
        <span class="qk-card-title">
          <i class="fa-solid fa-box-open fa-sm me-2 qk-text-accent"></i>Ordini Clienti Recenti
        </span>
        <a href="<?= url('sales/orders') ?>" class="btn-qk btn-qk-secondary btn-qk-sm">
          Tutti gli ordini <i class="fa-solid fa-arrow-right fa-xs"></i>
        </a>
      </div>
      <div class="qk-table-wrap">
        <?php if (empty($recent_sales_orders)): ?>
        <div class="qk-table-empty p-4 text-center text-muted">
          <i class="fa-regular fa-folder-open d-block fs-3 mb-2"></i> Nessun ordine cliente recente.
        </div>
        <?php else: ?>
        <table class="qk-table" style="font-size: 0.85rem;">
          <thead>
            <tr>
              <th>N° Ordine</th>
              <th>Cliente</th>
              <th>Stato</th>
              <th class="text-end">Totale</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recent_sales_orders as $o): ?>
            <tr>
              <td>
                <a href="<?= url('sales/orders/edit?id=' . $o['id']) ?>" class="fw-bold qk-text-accent">
                  <?= e($o['numero_ordine']) ?>
                </a>
              </td>
              <td><?= e($o['cliente_nome']) ?></td>
              <td>
                <span class="qk-badge <?= get_badge_class($o['stato']) ?>">
                  <?= get_stato_label($o['stato']) ?>
                </span>
              </td>
              <td class="text-end fw-semibold">€ <?= number_format((float)$o['totale'], 2, ',', '.') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>

    <!-- Ordini Acquisto Recenti -->
    <div class="qk-card shadow-sm" style="margin: 0;">
      <div class="qk-card-header d-flex justify-content-between align-items-center">
        <span class="qk-card-title">
          <i class="fa-solid fa-file-circle-check fa-sm me-2 text-success"></i>Ordini Acquisto Recenti
        </span>
        <a href="<?= url('purchases/orders') ?>" class="btn-qk btn-qk-secondary btn-qk-sm">
          Tutti gli ordini <i class="fa-solid fa-arrow-right fa-xs"></i>
        </a>
      </div>
      <div class="qk-table-wrap">
        <?php if (empty($recent_purchase_orders)): ?>
        <div class="qk-table-empty p-4 text-center text-muted">
          <i class="fa-regular fa-folder-open d-block fs-3 mb-2"></i> Nessun ordine acquisto recente.
        </div>
        <?php else: ?>
        <table class="qk-table" style="font-size: 0.85rem;">
          <thead>
            <tr>
              <th>N° Ordine</th>
              <th>Fornitore</th>
              <th>Stato</th>
              <th class="text-end">Totale</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recent_purchase_orders as $o): ?>
            <tr>
              <td>
                <a href="<?= url('purchases/orders/edit?id=' . $o['id']) ?>" class="fw-bold qk-text-accent">
                  <?= e($o['numero']) ?>
                </a>
              </td>
              <td><?= e($o['fornitore_nome']) ?></td>
              <td>
                <span class="qk-badge <?= get_badge_class($o['stato']) ?>">
                  <?= get_stato_label($o['stato']) ?>
                </span>
              </td>
              <td class="text-end fw-semibold">
                <?= $o['totale'] !== null ? '€ ' . number_format((float)$o['totale'], 2, ',', '.') : '—' ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <!-- Pannello azioni rapide (Destra) -->
  <div style="grid-column: span 1;">
    <div class="qk-card shadow-sm" style="margin: 0;">
      <div class="qk-card-header">
        <span class="qk-card-title">
          <i class="fa-solid fa-bolt fa-sm me-2 text-warning"></i>Azioni rapide
        </span>
      </div>
      <div class="qk-card-body d-flex flex-column gap-2">
        <a href="<?= url('sales/quotes/create') ?>" class="btn-qk btn-qk-primary text-start w-100">
          <i class="fa-solid fa-file-invoice fa-fw me-2"></i>Nuovo Preventivo Cliente
        </a>
        <a href="<?= url('purchases/rfq/create') ?>" class="btn-qk btn-qk-secondary text-start w-100">
          <i class="fa-solid fa-arrow-down-to-bracket fa-fw me-2"></i>Nuova RFQ Acquisti
        </a>
        <a href="<?= url('purchases/quotes/create') ?>" class="btn-qk btn-qk-secondary text-start w-100">
          <i class="fa-solid fa-envelope-open-text fa-fw me-2"></i>Registra Prev. Fornitore
        </a>
        <a href="<?= url('warehouse/stock') ?>" class="btn-qk btn-qk-secondary text-start w-100">
          <i class="fa-solid fa-boxes-stacked fa-fw me-2"></i>Controllo Giacenze
        </a>
        <a href="<?= url('warehouse/movements/create') ?>" class="btn-qk btn-qk-secondary text-start w-100">
          <i class="fa-solid fa-arrows-rotate fa-fw me-2"></i>Movimento Magazzino
        </a>
        <a href="<?= url('warehouse/inventories/create') ?>" class="btn-qk btn-qk-secondary text-start w-100">
          <i class="fa-solid fa-clipboard-list fa-fw me-2"></i>Rettifica Giacenze (Inventario)
        </a>
        <a href="<?= url('products/create') ?>" class="btn-qk btn-qk-secondary text-start w-100">
          <i class="fa-solid fa-box fa-fw me-2"></i>Nuovo Articolo
        </a>
      </div>
    </div>
  </div>

</div>

