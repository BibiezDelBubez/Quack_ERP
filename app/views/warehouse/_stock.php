<?php
/**
 * warehouse/_stock.php
 * Panoramica giacenze: lista prodotti con stock corrente o merce in arrivo.
 * Variabili attese: $stock (array), $ordini_in_arrivo (array), $nInArrivo (int), $search (string), $filter (string)
 */
$filterLabels = [
    'in_stock'    => 'Con giacenza',
    'all'         => 'Tutti',
    'sottoscorta' => 'Sottoscorta',
    'esauriti'    => 'Esauriti',
    'in_arrivo'   => 'Merce in arrivo',
];

$nSottoscorta = !empty($stock) ? count(array_filter($stock, fn($r) => $r['sottoscorta'])) : 0;
$nEsauriti    = !empty($stock) ? count(array_filter($stock, fn($r) => $r['qta_disponibile'] <= 0)) : 0;
?>

<!-- Filtri rapidi + ricerca -->
<div class="qk-toolbar mb-3">
  <div class="qk-filter-pills">
    <?php foreach ($filterLabels as $k => $lbl): ?>
    <?php
      $qs = array_filter(['q' => $search, 'filter' => $k]);
      $pillHref = url('warehouse') . ($qs ? '?' . http_build_query($qs) : '');
    ?>
    <a href="<?= $pillHref ?>"
       class="qk-filter-pill <?= $filter === $k ? 'active' : '' ?>">
      <?= e($lbl) ?>
      <?php if ($k === 'sottoscorta' && $nSottoscorta > 0): ?>
        <span class="qk-pill-count"><?= $nSottoscorta ?></span>
      <?php elseif ($k === 'esauriti' && $nEsauriti > 0): ?>
        <span class="qk-pill-count"><?= $nEsauriti ?></span>
      <?php elseif ($k === 'in_arrivo' && $nInArrivo > 0): ?>
        <span class="qk-pill-count bg-primary text-white" style="border-radius:10px; padding: 2px 6px; font-size:0.75rem"><?= $nInArrivo ?></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
  </div>

  <form method="get" action="<?= url('warehouse') ?>" class="qk-search-form">
    <?php if ($filter !== 'in_stock'): ?>
    <input type="hidden" name="filter" value="<?= e($filter) ?>">
    <?php endif; ?>
    <div class="qk-search-wrap">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input type="search" name="q" value="<?= e($search) ?>"
             placeholder="Cerca..." class="qk-form-control" autocomplete="off">
    </div>
  </form>
</div>

<!-- Tabella principale -->
<div class="qk-card">
  <div class="qk-card-body p-0">
    <?php if ($filter === 'in_arrivo'): ?>
      <!-- Visualizzazione Merce in Arrivo (Ordini Acquisto pendenti) -->
      <?php if (empty($ordini_in_arrivo)): ?>
      <div class="qk-empty-state">
        <i class="fa-solid fa-truck-ramp-box"></i>
        <p>Nessun ordine in arrivo o parzialmente ricevuto trovato.</p>
      </div>
      <?php else: ?>
      <table class="qk-table">
        <thead>
          <tr>
            <th>Ordine N°</th>
            <th>Fornitore</th>
            <th>Data Ordine</th>
            <th>Consegna Prevista</th>
            <th>Stato</th>
            <th>Azione di Ricezione</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ordini_in_arrivo as $o): ?>
          <tr>
            <td>
              <a href="<?= url('purchases/ordini/edit') . '?id=' . $o['id'] ?>" class="fw-bold">
                <?= e($o['numero']) ?>
              </a>
            </td>
            <td><?= e($o['fornitore_nome']) ?></td>
            <td><?= e($o['data_ordine']) ?></td>
            <td><?= e($o['data_consegna_prevista'] ?? 'Non definita') ?></td>
            <td>
              <span class="qk-badge <?= $o['stato'] === 'parzialmente_ricevuto' ? 'pending' : 'confirmed' ?>">
                <?= e($o['stato'] === 'parzialmente_ricevuto' ? 'Parzialmente Ricevuto' : 'Inviato / Confermato') ?>
              </span>
            </td>
            <td>
              <?php if (!empty($o['draft_ricezione_id'])): ?>
                <a href="<?= url('purchases/ricezioni/edit') . '?id=' . $o['draft_ricezione_id'] ?>" class="btn-qk btn-qk-success btn-qk-xs fw-bold">
                  <i class="fa-solid fa-boxes-packing"></i> Apri Bozza Ricezione
                </a>
              <?php else: ?>
                <a href="<?= url('purchases/ricezioni/create') . '?ordine_id=' . $o['id'] ?>" class="btn-qk btn-qk-primary btn-qk-xs fw-bold">
                  <i class="fa-solid fa-truck-field"></i> Registra Ricevimento
                </a>
              <?php endif; ?>
            </td>
            <td class="text-end">
              <a href="<?= url('purchases/ordini/edit') . '?id=' . $o['id'] ?>" class="btn-qk btn-qk-xs btn-qk-secondary" title="Vedi Dettaglio Ordine">
                <i class="fa-solid fa-arrow-right"></i>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>

    <?php else: ?>
      <!-- Tabella standard giacenze -->
      <?php if (empty($stock)): ?>
      <div class="qk-empty-state">
        <i class="fa-solid fa-boxes-stacked"></i>
        <p>Nessun prodotto trovato.</p>
      </div>
      <?php else: ?>
      <table class="qk-table">
        <thead>
          <tr>
            <th>SKU</th>
            <th>Prodotto</th>
            <th>UoM</th>
            <th class="text-end">Disponibile</th>
            <th class="text-end">Riservata</th>
            <th class="text-end">In arrivo</th>
            <th class="text-end">Libera</th>
            <th class="text-end">Scorta min.</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($stock as $r): ?>
          <?php
            $rowClass = '';
            if ($r['qta_disponibile'] <= 0) {
                $rowClass = 'qkwh-row-esaurito';
            } elseif ($r['sottoscorta']) {
                $rowClass = 'qkwh-row-sottoscorta';
            }
          ?>
          <tr class="<?= $rowClass ?>">
            <td class="qkwh-sku"><code><?= e($r['sku_interno']) ?></code></td>
            <td>
              <?= e($r['nome']) ?>
              <?php if ($r['traccia_lotti']): ?>
              <span class="qkwh-badge-lotti" title="Tracciatura lotti attiva">
                <i class="fa-solid fa-tag"></i> lotti
              </span>
              <?php endif; ?>
            </td>
            <td class="qkwh-uom"><?= e($r['uom']) ?></td>
            <td class="text-end qkwh-qty"><?= fmt_qty($r['qta_disponibile']) ?></td>
            <td class="text-end qkwh-qty qkwh-qty-muted"><?= fmt_qty($r['qta_riservata']) ?></td>
            <td class="text-end qkwh-qty qkwh-qty-positive"><?= fmt_qty($r['qta_in_arrivo']) ?></td>
            <td class="text-end qkwh-qty <?= $r['sottoscorta'] ? 'qkwh-qty-danger' : '' ?>">
              <?= fmt_qty($r['qta_libera']) ?>
              <?php if ($r['sottoscorta']): ?>
              <i class="fa-solid fa-triangle-exclamation ms-1" title="Sottoscorta"></i>
              <?php endif; ?>
            </td>
            <td class="text-end qkwh-qty qkwh-qty-muted">
              <?= $r['scorta_minima'] > 0 ? fmt_qty($r['scorta_minima']) : '—' ?>
            </td>
            <td class="qkwh-actions">
              <a href="<?= url('warehouse/movements/create') . '?prodotto_id=' . $r['id'] ?>"
                 class="btn-qk btn-qk-xs btn-qk-secondary" title="Registra movimento">
                <i class="fa-solid fa-arrows-rotate"></i>
              </a>
              <a href="<?= url('warehouse/movements') . '?prodotto_id=' . $r['id'] ?>"
                 class="btn-qk btn-qk-xs btn-qk-ghost" title="Storico movimenti">
                <i class="fa-solid fa-clock-rotate-left"></i>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<div class="qk-table-footer mt-2">
  <span class="qkwh-count-label">
    <?php if ($filter === 'in_arrivo'): ?>
      <?= count($ordini_in_arrivo) ?> ordin<?= count($ordini_in_arrivo) === 1 ? 'e' : 'i' ?> in attesa
    <?php else: ?>
      <?= count($stock) ?> prodott<?= count($stock) === 1 ? 'o' : 'i' ?>
    <?php endif; ?>
  </span>
  <a href="<?= url('warehouse/movements') ?>" class="qkwh-link-movimenti">
    <i class="fa-solid fa-clock-rotate-left"></i> Vedi storico movimenti
  </a>
</div>
