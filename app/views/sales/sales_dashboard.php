<?php
/**
 * app/views/sales/sales_dashboard.php
 * Dashboard della macro-sezione Vendite.
 */

// Mappa stato Preventivo
function preventivo_badge_dash(string $stato): string {
    $map = [
        'bozza'     => ['draft',     'Bozza'],
        'inviato'   => ['confirmed', 'Inviato'],
        'accettato' => ['delivered', 'Accettato'],
        'rifiutato' => ['pending',   'Rifiutato'],
        'scaduto'   => ['pending',   'Scaduto'],
        'annullato' => ['draft',     'Annullato'],
    ];
    [$cls, $lbl] = $map[$stato] ?? ['draft', $stato];
    return '<span class="qk-badge ' . $cls . '">' . e($lbl) . '</span>';
}

// Mappa stato Ordine Cliente
function ordine_badge_dash(string $stato): string {
    $map = [
        'in_lavorazione'       => ['pending',   'In Lavorazione'],
        'parzialmente_spedito' => ['confirmed', 'Parz. Spedito'],
        'spedito'              => ['delivered', 'Spedito'],
        'completato'           => ['delivered', 'Completato'],
        'annullato'            => ['draft',     'Annullato'],
        // PIANO - Revisione Ordini Clienti: senza questa riga uno stesso
        // 'superato' cadrebbe nel fallback sotto (classe 'draft', etichetta
        // grezza "superato" minuscola) — non un errore, solo meno chiaro.
        'superato'             => ['draft',     'Superato'],
    ];
    [$cls, $lbl] = $map[$stato] ?? ['draft', $stato];
    return '<span class="qk-badge ' . $cls . '">' . e($lbl) . '</span>';
}
?>

<!-- Sotto-navigazione condivisa -->
<?php include __DIR__ . '/_subnav.php'; ?>

<!-- KPI Grid -->
<div class="qk-kpi-grid mb-4">
  <a href="<?= url('sales/quotes?status=bozza') ?>" class="qk-kpi-card text-decoration-none" style="color: inherit;">
    <div class="qk-kpi-icon accent">
      <i class="fa-solid fa-file-invoice"></i>
    </div>
    <div class="qk-kpi-body">
      <div class="qk-kpi-value"><?= $quotes_bozza ?></div>
      <div class="qk-kpi-label">Preventivi in Bozza</div>
    </div>
  </a>

  <a href="<?= url('sales/quotes?status=inviato') ?>" class="qk-kpi-card text-decoration-none" style="color: inherit;">
    <div class="qk-kpi-icon warning">
      <i class="fa-solid fa-paper-plane"></i>
    </div>
    <div class="qk-kpi-body">
      <div class="qk-kpi-value"><?= $quotes_inviati ?></div>
      <div class="qk-kpi-label">Preventivi Inviati (In attesa)</div>
    </div>
  </a>

  <a href="<?= url('sales/orders') ?>" class="qk-kpi-card text-decoration-none" style="color: inherit;">
    <div class="qk-kpi-icon success">
      <i class="fa-solid fa-file-invoice-dollar"></i>
    </div>
    <div class="qk-kpi-body">
      <div class="qk-kpi-value"><?= $orders_attivi ?></div>
      <div class="qk-kpi-label">Ordini attivi</div>
    </div>
  </a>
</div>

<!-- Layout a due colonne -->
<div class="qk-two-col">

  <!-- Colonna principale: Ultimi documenti -->
  <div class="qk-flex-col qk-gap-lg">
    
    <!-- Ultimi Preventivi -->
    <div class="qk-card shadow-sm">
      <div class="qk-card-header qk-flex-row qk-justify-between">
        <span class="qk-card-title">
          <i class="fa-solid fa-file-invoice me-2 text-primary"></i>Ultimi preventivi clienti
        </span>
        <a href="<?= url('sales/quotes') ?>" class="btn-qk btn-qk-secondary btn-qk-sm">Tutti i preventivi</a>
      </div>
      <div class="qk-table-wrap">
        <table class="qk-table">
          <thead>
            <tr>
              <th>Numero</th>
              <th>Cliente</th>
              <th>Stato</th>
              <th class="text-end">Totale</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($recent_quotes)): ?>
            <tr>
              <td colspan="4" class="qk-table-empty">Nessun preventivo recente.</td>
            </tr>
            <?php else: ?>
            <?php foreach ($recent_quotes as $q): ?>
            <tr>
              <td>
                <a href="<?= url('sales/quotes/edit?id=' . $q['id']) ?>" class="fw-bold qk-text-accent text-decoration-none">
                  <?= e($q['numero_preventivo']) ?>
                </a>
              </td>
              <td><?= e($q['cliente_nome'] ?? '—') ?></td>
              <td><?= preventivo_badge_dash($q['stato']) ?></td>
              <td class="text-end fw-semibold">
                <?= $q['totale'] !== null ? '€ ' . number_format((float)$q['totale'], 2, ',', '.') : '—' ?>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Ultimi Ordini -->
    <div class="qk-card shadow-sm">
      <div class="qk-card-header qk-flex-row qk-justify-between">
        <span class="qk-card-title">
          <i class="fa-solid fa-file-invoice-dollar me-2 text-success"></i>Ultimi ordini clienti
        </span>
        <a href="<?= url('sales/orders') ?>" class="btn-qk btn-qk-secondary btn-qk-sm">Tutti gli ordini</a>
      </div>
      <div class="qk-table-wrap">
        <table class="qk-table">
          <thead>
            <tr>
              <th>Numero</th>
              <th>Cliente</th>
              <th>Stato</th>
              <th class="text-end">Data Ordine</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($recent_orders)): ?>
            <tr>
              <td colspan="4" class="qk-table-empty">Nessun ordine recente.</td>
            </tr>
            <?php else: ?>
            <?php foreach ($recent_orders as $o): ?>
            <tr>
              <td>
                <a href="<?= url('sales/orders/edit?id=' . $o['id']) ?>" class="fw-bold qk-text-accent text-decoration-none">
                  <?= e($o['numero_ordine']) ?>
                </a>
              </td>
              <td><?= e($o['cliente_nome'] ?? '—') ?></td>
              <td><?= ordine_badge_dash($o['stato']) ?></td>
              <td class="text-end fw-semibold">
                <?= fmt_date($o['data_ordine']) ?>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>

  <!-- Colonna laterale: Azioni Rapide -->
  <div>
    <div class="qk-card shadow-sm">
      <div class="qk-card-header">
        <span class="qk-card-title">
          <i class="fa-solid fa-bolt me-2 text-warning"></i>Azioni rapide
        </span>
      </div>
      <div class="qk-card-body qk-flex-col qk-gap-sm">
        <a href="<?= url('sales/quotes/create') ?>" class="btn-qk btn-qk-primary text-start w-100">
          <i class="fa-solid fa-plus fa-fw me-2"></i>Nuovo Preventivo
        </a>
        <a href="<?= url('customers/create') ?>" class="btn-qk btn-qk-secondary text-start w-100">
          <i class="fa-solid fa-user-plus fa-fw me-2"></i>Nuovo Cliente
        </a>
        <a href="<?= url('sales/orders/create') ?>" class="btn-qk btn-qk-secondary text-start w-100">
          <i class="fa-solid fa-file-invoice fa-fw me-2"></i>Nuovo Ordine Diretto
        </a>
      </div>
    </div>
  </div>

</div>
