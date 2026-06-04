<?php
/**
 * app/views/purchases/purchases_dashboard.php
 * Dashboard della macro-sezione Acquisti.
 */

// Mappa stato RFQ
function rfq_badge_dash(string $stato): string {
    $map = [
        'bozza'       => ['draft',     'Bozza'],
        'inviata'     => ['confirmed', 'Inviata'],
        'revisionata' => ['pending',   'Revisionata'],
        'chiusa'      => ['delivered', 'Chiusa'],
    ];
    [$cls, $lbl] = $map[$stato] ?? ['draft', $stato];
    return '<span class="qk-badge ' . $cls . '">' . e($lbl) . '</span>';
}

// Mappa stato Ordine
function ordine_badge_dash(string $stato): string {
    $map = [
        'bozza'                 => ['draft',     'Bozza'],
        'inviato'               => ['confirmed', 'Inviato'],
        'parzialmente_ricevuto' => ['pending',   'Parz. Ricevuto'],
        'completato'            => ['delivered', 'Completato'],
        'annullato'             => ['draft',     'Annullato'],
    ];
    [$cls, $lbl] = $map[$stato] ?? ['draft', $stato];
    return '<span class="qk-badge ' . $cls . '">' . e($lbl) . '</span>';
}
?>

<!-- Sotto-navigazione condivisa -->
<?php include __DIR__ . '/_subnav.php'; ?>

<!-- KPI Grid -->
<div class="qk-kpi-grid mb-4">
  <a href="<?= url('purchases/rfq?stato=bozza') ?>" class="qk-kpi-card text-decoration-none" style="color: inherit;">
    <div class="qk-kpi-icon accent">
      <i class="fa-solid fa-arrow-down-to-bracket"></i>
    </div>
    <div class="qk-kpi-body">
      <div class="qk-kpi-value"><?= $rfq_bozza ?></div>
      <div class="qk-kpi-label">RFQ in Bozza</div>
    </div>
  </a>

  <a href="<?= url('purchases/rfq?stato=inviata') ?>" class="qk-kpi-card text-decoration-none" style="color: inherit;">
    <div class="qk-kpi-icon warning">
      <i class="fa-solid fa-paper-plane"></i>
    </div>
    <div class="qk-kpi-body">
      <div class="qk-kpi-value"><?= $rfq_inviata ?></div>
      <div class="qk-kpi-label">RFQ Inviate (In attesa)</div>
    </div>
  </a>

  <a href="<?= url('purchases/orders') ?>" class="qk-kpi-card text-decoration-none" style="color: inherit;">
    <div class="qk-kpi-icon success">
      <i class="fa-solid fa-file-circle-check"></i>
    </div>
    <div class="qk-kpi-body">
      <div class="qk-kpi-value"><?= $ordini_attivi ?></div>
      <div class="qk-kpi-label">Ordini attivi</div>
    </div>
  </a>

  <a href="<?= url('purchases/pricelists') ?>" class="qk-kpi-card text-decoration-none" style="color: inherit;">
    <div class="qk-kpi-icon info">
      <i class="fa-solid fa-file-spreadsheet"></i>
    </div>
    <div class="qk-kpi-body">
      <div class="qk-kpi-value"><?= $listini_attivi ?></div>
      <div class="qk-kpi-label">Listini attivi</div>
    </div>
  </a>
</div>

<!-- Layout a due colonne -->
<div class="qk-two-col">

  <!-- Colonna principale: Ultimi documenti -->
  <div style="display: flex; flex-direction: column; gap: 1.5rem;">
    
    <!-- Ultime RFQ -->
    <div class="qk-card shadow-sm">
      <div class="qk-card-header d-flex justify-content-between align-items-center">
        <span class="qk-card-title">
          <i class="fa-solid fa-arrow-down-to-bracket me-2 text-primary"></i>Ultime richieste di preventivo (RFQ)
        </span>
        <a href="<?= url('purchases/rfq') ?>" class="btn-qk btn-qk-secondary btn-qk-sm">Tutte le RFQ</a>
      </div>
      <div class="qk-table-wrap">
        <table class="qk-table">
          <thead>
            <tr>
              <th>Numero</th>
              <th>Fornitore</th>
              <th>Stato</th>
              <th class="text-end">Totale</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($recent_rfqs)): ?>
            <tr>
              <td colspan="4" class="qk-table-empty">Nessuna richiesta di preventivo recente.</td>
            </tr>
            <?php else: ?>
            <?php foreach ($recent_rfqs as $r): ?>
            <tr>
              <td>
                <a href="<?= url('purchases/rfq/edit?id=' . $r['id']) ?>" class="fw-bold qk-text-accent">
                  <?= e($r['numero']) ?>
                </a>
              </td>
              <td><?= e($r['fornitore_nome'] ?? '—') ?></td>
              <td><?= rfq_badge_dash($r['stato']) ?></td>
              <td class="text-end fw-semibold">
                <?= $r['totale'] !== null ? '€ ' . number_format((float)$r['totale'], 2, ',', '.') : '—' ?>
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
      <div class="qk-card-header d-flex justify-content-between align-items-center">
        <span class="qk-card-title">
          <i class="fa-solid fa-file-circle-check me-2 text-success"></i>Ultimi ordini d'acquisto
        </span>
        <a href="<?= url('purchases/orders') ?>" class="btn-qk btn-qk-secondary btn-qk-sm">Tutti gli ordini</a>
      </div>
      <div class="qk-table-wrap">
        <table class="qk-table">
          <thead>
            <tr>
              <th>Numero</th>
              <th>Fornitore</th>
              <th>Stato</th>
              <th class="text-end">Totale</th>
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
                <a href="<?= url('purchases/orders/edit?id=' . $o['id']) ?>" class="fw-bold qk-text-accent">
                  <?= e($o['numero']) ?>
                </a>
              </td>
              <td><?= e($o['fornitore_nome'] ?? '—') ?></td>
              <td><?= ordine_badge_dash($o['stato']) ?></td>
              <td class="text-end fw-semibold">
                <?= $o['totale'] !== null ? '€ ' . number_format((float)$o['totale'], 2, ',', '.') : '—' ?>
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
      <div class="qk-card-body d-flex flex-column gap-2">
        <a href="<?= url('purchases/rfq/create') ?>" class="btn-qk btn-qk-primary text-start w-100">
          <i class="fa-solid fa-plus fa-fw me-2"></i>Nuova RFQ
        </a>
        <a href="<?= url('suppliers/create') ?>" class="btn-qk btn-qk-secondary text-start w-100">
          <i class="fa-solid fa-handshake fa-fw me-2"></i>Nuovo Fornitore
        </a>
        <a href="<?= url('purchases/pricelists/create') ?>" class="btn-qk btn-qk-secondary text-start w-100">
          <i class="fa-solid fa-file-spreadsheet fa-fw me-2"></i>Nuovo Listino
        </a>
        <a href="<?= url('warehouse?filter=in_arrivo') ?>" class="btn-qk btn-qk-secondary text-start w-100">
          <i class="fa-solid fa-dolly fa-fw me-2"></i>Ricevi Merci
        </a>
      </div>
    </div>
  </div>

</div>
