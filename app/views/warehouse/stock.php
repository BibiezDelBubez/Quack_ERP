<?php
/**
 * warehouse/_stock.php
 * Panoramica giacenze: lista prodotti con stock corrente o merce in arrivo.
 * Variabili attese: $stock (array), $ordini_in_arrivo (array), $nInArrivo (int),
 * $search (string), $filter (string), $magazzini (array), $magazzinoId (int)
 * — Fix 2, Magazzino Attivo - Analisi e Alternative.md §8.3.
 */
$filterLabels = [
    'in_stock'    => 'Con giacenza',
    'all'         => 'Tutti',
    'sottoscorta' => 'Sottoscorta',
    'esauriti'    => 'Esauriti',
    'in_arrivo'   => 'Merce in arrivo',
];

// Contatori per le pill sottoscorta/esauriti: rispettano lo stesso magazzino
// scelto sopra (o l'aggregato se $magazzinoId === 0) — coerenti con qk_stock_*()
// invece delle query indipendenti scritte a mano che c'erano prima (bug: quella
// per "sottoscorta" leggeva ancora p.scorta_minima, colonna rimossa da
// prodotti col multi-magazzino, §3.5 — non era mai stata aggiornata).
if ($magazzinoId === 0) {
    $nSottoscorta = (int) $pdo->query(
        "SELECT COUNT(*) FROM (
            SELECT p.id FROM prodotti p LEFT JOIN giacenze g ON g.prodotto_id = p.id
             WHERE p.attivo = 1 GROUP BY p.id HAVING " . qk_sottoscorta_having('g') . "
         ) t"
    )->fetchColumn();
    $nEsauriti = (int) $pdo->query(
        "SELECT COUNT(*) FROM (
            SELECT p.id FROM prodotti p LEFT JOIN giacenze g ON g.prodotto_id = p.id
             WHERE p.attivo = 1 GROUP BY p.id HAVING SUM(COALESCE(g.quantita_disponibile,0)) <= 0
         ) t"
    )->fetchColumn();
} else {
    $stSc = $pdo->prepare(
        "SELECT COUNT(*) FROM prodotti p LEFT JOIN giacenze g ON g.prodotto_id = p.id AND g.magazzino_id = ?
          WHERE p.attivo = 1 AND " . qk_sottoscorta_where('g')
    );
    $stSc->execute([$magazzinoId]);
    $nSottoscorta = (int) $stSc->fetchColumn();

    $stEs = $pdo->prepare(
        "SELECT COUNT(*) FROM prodotti p LEFT JOIN giacenze g ON g.prodotto_id = p.id AND g.magazzino_id = ?
          WHERE p.attivo = 1 AND COALESCE(g.quantita_disponibile, 0) <= 0"
    );
    $stEs->execute([$magazzinoId]);
    $nEsauriti = (int) $stEs->fetchColumn();
}
$base = url('warehouse/stock');
?>

<!-- Sotto-navigazione condivisa -->
<?php $active_tab = 'stock'; include __DIR__ . '/_subnav.php'; ?>

<!-- Selettore magazzino: sempre esplicito, mai un default silenzioso (§8.3) -->
<div class="qk-card mb-3">
  <div class="qk-card-body py-2">
    <form method="get" action="<?= url('warehouse/stock') ?>" class="d-flex align-items-center gap-2 flex-wrap">
      <?php if ($filter !== 'in_stock'): ?><input type="hidden" name="filter" value="<?= e($filter) ?>"><?php endif; ?>
      <?php if ($search !== ''): ?><input type="hidden" name="q" value="<?= e($search) ?>"><?php endif; ?>
      <label class="qk-form-label mb-0">Magazzino</label>
      <select name="magazzino_id" class="qk-form-control qk-form-control-sm" style="max-width:280px" onchange="this.form.submit()">
        <option value="0" <?= $magazzinoId === 0 ? 'selected' : '' ?>>Tutti i magazzini</option>
        <?php foreach ($magazzini as $mg): ?>
        <option value="<?= (int) $mg['id'] ?>" <?= $magazzinoId === (int) $mg['id'] ? 'selected' : '' ?>><?= e($mg['nome']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($filter === 'in_arrivo'): ?>
      <span class="qk-form-hint"><i class="fa-solid fa-circle-info"></i> "Merce in arrivo" mostra sempre tutti gli ordini d'acquisto pendenti dell'azienda, non filtrati per sede.</span>
      <?php endif; ?>
    </form>
  </div>
</div>

<!-- Filtri rapidi + ricerca -->
<div class="qk-toolbar mb-3">
  <div class="qk-filter-pills">
    <?php foreach ($filterLabels as $k => $lbl): ?>
    <?php
      $qs = array_filter(['q' => $search, 'filter' => $k, 'magazzino_id' => $magazzinoId ?: null], static fn($v) => $v !== null && $v !== '');
      $pillHref = url('warehouse/stock') . ($qs ? '?' . http_build_query($qs) : '');
    ?>
    <a href="<?= $pillHref ?>"
       class="qk-filter-pill <?= $filter === $k ? 'active' : '' ?>">
      <?= e($lbl) ?>
      <?php if ($k === 'sottoscorta' && $nSottoscorta > 0): ?>
        <span class="qk-pill-count"><?= $nSottoscorta ?></span>
      <?php elseif ($k === 'esauriti' && $nEsauriti > 0): ?>
        <span class="qk-pill-count"><?= $nEsauriti ?></span>
      <?php elseif ($k === 'in_arrivo' && $nInArrivo > 0): ?>
        <span class="qk-pill-count"><?= $nInArrivo ?></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
  </div>

  <form method="get" action="<?= url('warehouse/stock') ?>" class="qk-search-form">
    <?php if ($filter !== 'in_stock'): ?>
    <input type="hidden" name="filter" value="<?= e($filter) ?>">
    <?php endif; ?>
    <?php if ($magazzinoId !== 0): ?>
    <input type="hidden" name="magazzino_id" value="<?= (int) $magazzinoId ?>">
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
      <div class="qk-empty">
        <i class="fa-solid fa-dolly"></i>
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
              <a href="<?= url('purchases/orders/edit') . '?id=' . $o['id'] ?>" class="fw-bold">
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
                <a href="<?= url('purchases/receipts/edit') . '?id=' . $o['draft_ricezione_id'] ?>" class="btn-qk btn-qk-success btn-qk-xs fw-bold">
                  <i class="fa-solid fa-boxes-packing"></i> Apri Bozza Ricezione
                </a>
              <?php else: ?>
                <a href="<?= url('purchases/receipts/create') . '?ordine_id=' . $o['id'] ?>" class="btn-qk btn-qk-primary btn-qk-xs fw-bold">
                  <i class="fa-solid fa-truck-field"></i> Registra Ricevimento
                </a>
              <?php endif; ?>
            </td>
            <td class="text-end">
              <a href="<?= url('purchases/orders/edit') . '?id=' . $o['id'] ?>" class="btn-qk btn-qk-xs btn-qk-secondary" title="Vedi Dettaglio Ordine">
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
      <div class="qk-empty">
        <i class="fa-solid fa-boxes-stacked"></i>
        <p>Nessun prodotto trovato.</p>
      </div>
      <?php else: ?>
      <?php $sort = $pag['sort']; $dir = $pag['dir']; ?>
      <table class="qk-table">
        <thead>
          <tr>
            <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'sku', $sort, $dir)) ?>">SKU<?= qk_sort_icon('sku', $sort, $dir) ?></a></th>
            <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'nome', $sort, $dir)) ?>">Prodotto<?= qk_sort_icon('nome', $sort, $dir) ?></a></th>
            <th>UoM</th>
            <th class="qk-sortable text-end"><a href="<?= e(qk_sort_url($base, 'disponibile', $sort, $dir)) ?>">Disponibile<?= qk_sort_icon('disponibile', $sort, $dir) ?></a></th>
            <th class="qk-sortable text-end"><a href="<?= e(qk_sort_url($base, 'riservata', $sort, $dir)) ?>">Riservata<?= qk_sort_icon('riservata', $sort, $dir) ?></a></th>
            <th class="qk-sortable text-end"><a href="<?= e(qk_sort_url($base, 'in_arrivo', $sort, $dir)) ?>">In arrivo<?= qk_sort_icon('in_arrivo', $sort, $dir) ?></a></th>
            <th class="qk-sortable text-end"><a href="<?= e(qk_sort_url($base, 'libera', $sort, $dir)) ?>">Libera<?= qk_sort_icon('libera', $sort, $dir) ?></a></th>
            <th class="qk-sortable text-end"><a href="<?= e(qk_sort_url($base, 'scorta', $sort, $dir)) ?>">Scorta min.<?= qk_sort_icon('scorta', $sort, $dir) ?></a></th>
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
              <a href="<?= url('products/edit?id=' . $r['id']) ?>" class="qk-text-accent text-decoration-none fw-semibold">
                <?= e($r['nome']) ?>
              </a>
              <?php if ($r['traccia_lotti']): ?>
              <span class="qkwh-badge-lotti" style="cursor:pointer" title="Vedi lotti disponibili" onclick="openLotsDrawer(<?= $r['id'] ?>, '<?= e(addslashes($r['nome'])) ?>')">
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

<?php if ($filter !== 'in_arrivo'): ?>
<?php qk_paginate($total, $pag['page'], $pag['limit'], $base); ?>
<?php endif; ?>

<div class="qk-table-footer mt-2">
  <a href="<?= url('warehouse/movements') ?>" class="qkwh-link-movimenti">
    <i class="fa-solid fa-clock-rotate-left"></i> Vedi storico movimenti
  </a>
</div>

<!-- Drawer per i Lotti -->
<div id="qk-drawer-backdrop" class="qk-drawer-backdrop"></div>
<div id="qk-lots-drawer" class="qk-drawer">
  <div class="qk-drawer-header">
    <h3 id="qk-drawer-title" class="qk-drawer-title">Dettaglio Lotti</h3>
    <button type="button" class="qk-drawer-close" onclick="closeLotsDrawer()">&times;</button>
  </div>
  <div id="qk-drawer-body" class="qk-drawer-body">
    <div class="qk-text-muted">Caricamento in corso...</div>
  </div>
</div>

<script>
(function() {
  'use strict';
  
  const backdrop = document.getElementById('qk-drawer-backdrop');
  const drawer   = document.getElementById('qk-lots-drawer');
  const titleEl  = document.getElementById('qk-drawer-title');
  const bodyEl   = document.getElementById('qk-drawer-body');
  
  window.openLotsDrawer = async function(prodottoId, prodottoNome) {
    titleEl.textContent = `Lotti di: ${prodottoNome}`;
    bodyEl.innerHTML = '<div class="qk-text-muted"><i class="fa-solid fa-spinner fa-spin"></i> Caricamento lotti in corso...</div>';
    
    backdrop.classList.add('show');
    drawer.classList.add('open');
    
    try {
      const res = await fetch(`<?= url('warehouse/api/prodotto-info') ?>?id=${prodottoId}`);
      const data = await res.json();
      
      if (!data.ok) {
        bodyEl.innerHTML = `<div class="text-danger"><i class="fa-solid fa-triangle-exclamation"></i> Errore: ${data.error}</div>`;
        return;
      }
      
      const lotti = data.lotti || [];
      const disponibili = lotti.filter(l => l.quantita_disponibile > 0);
      
      if (disponibili.length === 0) {
        bodyEl.innerHTML = '<div class="qk-empty-state"><i class="fa-solid fa-box-open"></i><p>Nessun lotto con giacenza disponibile.</p></div>';
        return;
      }
      
      bodyEl.innerHTML = disponibili.map(l => {
        const scadenzaStr = l.data_scadenza ? new Date(l.data_scadenza).toLocaleDateString('it-IT') : 'Nessuna scadenza';
        const isScaduto = l.data_scadenza && new Date(l.data_scadenza) < new Date();
        const badgeScaduto = isScaduto ? '<span class="badge bg-danger text-white ms-2">Scaduto</span>' : '';
        
        return `
          <div class="qk-drawer-lot-card">
            <div class="qk-drawer-lot-code">
              <i class="fa-solid fa-tag text-muted"></i> ${l.codice_lotto_interno}
            </div>
            ${l.codice_lotto_fornitore ? `<div style="font-size:0.75rem;color:var(--qk-text-muted)">Cod. Fornitore: ${l.codice_lotto_fornitore}</div>` : ''}
            <div class="qk-drawer-lot-meta">
              <span>Disponibile: <strong>${l.quantita_disponibile}</strong></span>
              <span class="${isScaduto ? 'text-danger fw-bold' : ''}">
                <i class="fa-solid fa-calendar-days"></i> ${scadenzaStr}${badgeScaduto}
              </span>
            </div>
          </div>
        `;
      }).join('');
      
    } catch (err) {
      bodyEl.innerHTML = `<div class="text-danger"><i class="fa-solid fa-triangle-exclamation"></i> Errore di caricamento.</div>`;
    }
  };
  
  window.closeLotsDrawer = function() {
    backdrop.classList.remove('show');
    drawer.classList.remove('open');
  };
  
  backdrop.addEventListener('click', closeLotsDrawer);
})();
</script>
                                                                                                  