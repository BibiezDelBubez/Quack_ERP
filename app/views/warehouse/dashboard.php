<?php
/**
 * app/views/warehouse/dashboard.php
 * Dashboard riepilogativa del magazzino.
 */

function movimento_badge_dash(string $tipo): string {
    $map = [
        'carico'        => ['delivered', 'Carico'],
        'scarico'       => ['pending',   'Scarico'],
        'rettifica'     => ['draft',     'Rettifica'],
        'reso_cliente'  => ['confirmed', 'Reso cl.'],
        'reso_fornitore'=> ['draft',     'Reso forn.'],
    ];
    [$cls, $lbl] = $map[$tipo] ?? ['draft', $tipo];
    return '<span class="qk-badge ' . $cls . '">' . e($lbl) . '</span>';
}
?>

<!-- Sotto-navigazione condivisa -->
<?php $active_tab = 'dashboard'; include __DIR__ . '/_subnav.php'; ?>

<!-- KPI Grid -->
<div class="qk-kpi-grid mb-4">
  <div class="qk-kpi-card">
    <div class="qk-kpi-icon info">
      <i class="fa-solid fa-coins"></i>
    </div>
    <div class="qk-kpi-body">
      <div class="qk-kpi-value"><?= fmt_money($valore_magazzino) ?></div>
      <div class="qk-kpi-label">Valore Magazzino</div>
    </div>
  </div>

  <a href="<?= url('warehouse/reorder') ?>" class="qk-kpi-card text-decoration-none" style="color: inherit;">
    <div class="qk-kpi-icon warning">
      <i class="fa-solid fa-circle-exclamation"></i>
    </div>
    <div class="qk-kpi-body">
      <div class="qk-kpi-value"><?= $sottoscorta_count ?></div>
      <div class="qk-kpi-label">Prodotti Sottoscorta</div>
    </div>
  </a>

  <a href="<?= url('warehouse/stock?filter=in_arrivo') ?>" class="qk-kpi-card text-decoration-none" style="color: inherit;">
    <div class="qk-kpi-icon success">
      <i class="fa-solid fa-truck-loading"></i>
    </div>
    <div class="qk-kpi-body">
      <div class="qk-kpi-value"><?= $in_arrivo_count ?></div>
      <div class="qk-kpi-label">Merce in Arrivo (Carichi)</div>
    </div>
  </a>

  <div class="qk-kpi-card">
    <div class="qk-kpi-icon accent">
      <i class="fa-solid fa-truck-ramp-box"></i>
    </div>
    <div class="qk-kpi-body">
      <div class="qk-kpi-value"><?= $in_partenza_count ?></div>
      <div class="qk-kpi-label">Merce in Partenza (Sped.)</div>
    </div>
  </div>
</div>

<!-- Layout a due colonne -->
<div class="qk-two-col">

  <!-- Colonna principale: Ultimi movimenti e sottoscorta -->
  <div style="display: flex; flex-direction: column; gap: 1.5rem;">
    
    <!-- Ultimi Movimenti -->
    <div class="qk-card shadow-sm">
      <div class="qk-card-header d-flex justify-content-between align-items-center">
        <span class="qk-card-title">
          <i class="fa-solid fa-arrow-right-arrow-left me-2 text-primary"></i>Ultimi movimenti logistici
        </span>
        <a href="<?= url('warehouse/movements') ?>" class="btn-qk btn-qk-secondary btn-qk-sm">Tutti i movimenti</a>
      </div>
      <div class="qk-table-wrap">
        <table class="qk-table">
          <thead>
            <tr>
              <th>Data</th>
              <th>Prodotto</th>
              <th>Tipo</th>
              <th class="text-end">Quantità</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($recent_movements)): ?>
            <tr>
              <td colspan="4" class="qk-table-empty">Nessun movimento recente registrato.</td>
            </tr>
            <?php else: ?>
            <?php foreach ($recent_movements as $m): ?>
            <tr>
              <td class="qk-text-muted"><?= fmt_datetime($m['created_at']) ?></td>
              <td class="fw-semibold"><?= e($m['prodotto_nome']) ?></td>
              <td><?= movimento_badge_dash($m['tipo_movimento']) ?></td>
              <td class="text-end fw-semibold">
                <?= number_format((float)$m['quantita'], 3, ',', '.') ?> <?= e($m['uom']) ?>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Prodotti vicini al sottoscorta -->
    <div class="qk-card shadow-sm">
      <div class="qk-card-header d-flex justify-content-between align-items-center">
        <span class="qk-card-title">
          <i class="fa-solid fa-circle-exclamation me-2 text-warning"></i>Articoli sottoscorta o in esaurimento
        </span>
        <a href="<?= url('warehouse/reorder') ?>" class="btn-qk btn-qk-secondary btn-qk-sm">Proposte di riordino</a>
      </div>
      <div class="qk-table-wrap">
        <table class="qk-table">
          <thead>
            <tr>
              <th>Prodotto</th>
              <th class="text-end">Disponibile</th>
              <th class="text-end">Scorta Minima</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($understock_items)): ?>
            <tr>
              <td colspan="3" class="qk-table-empty">Nessun articolo sottoscorta. Ottimo!</td>
            </tr>
            <?php else: ?>
            <?php foreach ($understock_items as $u): ?>
            <tr>
              <td class="fw-semibold"><?= e($u['nome']) ?></td>
              <td class="text-end text-danger fw-bold"><?= number_format((float)$u['quantita_disponibile'], 3, ',', '.') ?> <?= e($u['uom']) ?></td>
              <td class="text-end qk-text-muted"><?= number_format((float)$u['scorta_minima'], 3, ',', '.') ?> <?= e($u['uom']) ?></td>
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
        <a href="<?= url('warehouse/movements/create') ?>" class="btn-qk btn-qk-primary text-start w-100">
          <i class="fa-solid fa-plus fa-fw me-2"></i>Nuovo Movimento Manuale
        </a>
        <a href="<?= url('warehouse/inventories/create') ?>" class="btn-qk btn-qk-secondary text-start w-100">
          <i class="fa-solid fa-clipboard-list fa-fw me-2"></i>Rileva Inventario Fisico
        </a>
        <a href="<?= url('warehouse/reorder') ?>" class="btn-qk btn-qk-secondary text-start w-100">
          <i class="fa-solid fa-circle-exclamation fa-fw me-2"></i>Proposte di Riordino
        </a>
      </div>
    </div>
  </div>

</div>
