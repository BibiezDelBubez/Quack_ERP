<?php
/**
 * sales/shipments/_nuova.php
 * Pagina dedicata Pick & Pack — Nuova Spedizione
 *
 * Variabili attese (dal controller):
 *   $order           array    — dati ordine cliente
 *   $righe           array    — righe con qta_residua > 0
 *   $lottiPerProdotto array   — [prodotto_id => [...lotti]]
 *   $orderId         int
 */

// Quante righe hanno lotti sufficienti server-side (per il progress badge iniziale)
$righeConLotti = 0;
foreach ($righe as $r) {
    if (!(int)($r['traccia_lotti'] ?? 0)) {
        $righeConLotti++; // senza traccia lotti: sempre pronta
        continue;
    }
    $lotti = $lottiPerProdotto[$r['prodotto_id']] ?? [];
    $stock = array_sum(array_column($lotti, 'quantita_disponibile'));
    if ($stock >= (float)$r['qta_residua']) $righeConLotti++;
}
$totRighe = count($righe);
?>

<?php if (!empty($_SESSION['flash_error'])): ?>
<div class="qk-alert qk-alert-danger mb-3">
  <i class="fa-solid fa-circle-xmark"></i>
  <span><?= e($_SESSION['flash_error']) ?></span>
  <button class="qk-alert-close"><i class="fa-solid fa-xmark"></i></button>
</div>
<?php unset($_SESSION['flash_error']); endif; ?>

<form id="formNuovaSpedizione"
      action="<?= url('sales/orders/action') ?>"
      method="post"
      enctype="multipart/form-data"
      novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= $orderId ?>">
  <input type="hidden" name="azione" value="crea_spedizione">

  <!-- ══════════════════════════════════════════════════════════════════════
       LAYOUT: colonna sinistra (righe) + colonna destra sticky (trasporto)
       ══════════════════════════════════════════════════════════════════════ -->
  <div class="qk-pp-layout">

    <!-- ── COLONNA SINISTRA: righe da spedire ───────────────────────────── -->
    <div class="qk-pp-left">

      <!-- Progress header -->
      <div class="qk-pp-progress-bar">
        <div class="qk-pp-progress-info">
          <span class="qk-pp-progress-label">
            <i class="fa-solid fa-list-check me-2"></i>
            Righe pronte
          </span>
          <span class="qk-pp-progress-count" id="pp-righe-pronte">
            <?= $righeConLotti ?> / <?= $totRighe ?>
          </span>
        </div>
        <div class="qk-pp-progress-track">
          <div class="qk-pp-progress-fill" id="pp-progress-fill"
               style="width: <?= $totRighe > 0 ? round(($righeConLotti/$totRighe)*100) : 0 ?>%"></div>
        </div>
      </div>

      <!-- Righe prodotto -->
      <?php foreach ($righe as $r):
        $rigaId      = (int)$r['id'];
        $prodId      = (int)$r['prodotto_id'];
        $tracciaLotti = (int)($r['traccia_lotti'] ?? 0);
        $qtaResidua  = (float)$r['qta_residua'];
        $uom         = e($r['uom_simbolo'] ?? '');
        $percSpedita = $r['quantita_ordinata'] > 0
                         ? round(((float)$r['quantita_spedita'] / (float)$r['quantita_ordinata']) * 100)
                         : 0;

        // Pre-calcola allocazione server-side per lotti
        $lotti       = $lottiPerProdotto[$prodId] ?? [];
        $stockTot    = array_sum(array_column($lotti, 'quantita_disponibile'));
        $statoRiga   = !$tracciaLotti ? 'ok'
                         : ($stockTot >= $qtaResidua ? 'ok'
                           : ($stockTot > 0 ? 'partial' : 'nostock'));
      ?>
      <div class="qk-pp-riga <?= $statoRiga ?>"
           data-riga-id="<?= $rigaId ?>"
           data-prodotto-id="<?= $prodId ?>"
           data-traccia-lotti="<?= $tracciaLotti ?>"
           data-qta-residua="<?= $qtaResidua ?>"
           data-uom="<?= $uom ?>"
           data-stato="<?= $statoRiga ?>">

        <!-- Header riga -->
        <div class="qk-pp-riga-header">
          <div class="qk-pp-riga-prodotto">
            <span class="qk-pp-riga-sku"><?= e($r['prodotto_codice'] ?? '—') ?></span>
            <span class="qk-pp-riga-nome"><?= e($r['prodotto_nome'] ?? '') ?></span>
            <?php if ($tracciaLotti): ?>
              <span class="qk-badge draft ms-1"><i class="fa-solid fa-tag me-1"></i>Con Lotti</span>
            <?php endif; ?>
          </div>
          <div class="qk-pp-riga-residuo">
            <span class="qk-pp-residuo-label">Da spedire</span>
            <span class="qk-pp-residuo-val"><?= $qtaResidua ?> <?= $uom ?></span>
          </div>
        </div>

        <!-- Barra spedito precedente -->
        <?php if ((float)$r['quantita_spedita'] > 0): ?>
        <div class="qk-pp-prev-progress">
          <span class="qk-pp-prev-label">
            Già spedito: <?= (float)$r['quantita_spedita'] ?> / <?= (float)$r['quantita_ordinata'] ?> <?= $uom ?>
          </span>
          <div class="qk-pp-prev-track">
            <div class="qk-pp-prev-fill" style="width:<?= $percSpedita ?>%"></div>
          </div>
        </div>
        <?php endif; ?>

        <!-- Corpo assegnazione -->
        <div class="qk-pp-riga-body">

          <?php if (!$tracciaLotti): ?>
            <!-- Prodotto senza lotti: semplice input -->
            <div class="qk-pp-simple-input">
              <label class="qk-form-label mb-0">Quantità da spedire</label>
              <div class="qk-pp-simple-row">
                <input type="number"
                       name="quantita_no_lotto[<?= $rigaId ?>]"
                       class="qk-form-control"
                       step="0.001" min="0" max="<?= $qtaResidua ?>"
                       value="<?= $qtaResidua ?>">
                <span class="qk-pp-uom"><?= $uom ?></span>
              </div>
            </div>

          <?php else: ?>
            <!-- Prodotto con lotti: blocco lotti -->
            <div class="qk-pp-lotti-wrap">

              <?php if (empty($lotti)): ?>
                <div class="qk-pp-stock-alert nostock">
                  <i class="fa-solid fa-triangle-exclamation"></i>
                  Nessun lotto disponibile in magazzino per questo prodotto.
                </div>
              <?php else: ?>

                <!-- Colonne header -->
                <div class="qk-pp-lotti-cols">
                  <span>Lotto</span>
                  <span>Disponibile</span>
                  <span>Scadenza</span>
                  <span>Qty da spedire</span>
                  <span></span>
                </div>

                <!-- Righe lotti (pre-allocate FEFO server-side) -->
                <div class="qk-pp-lotti-rows" id="lotti-rows-<?= $rigaId ?>">
                  <?php
                    $rimasto = $qtaResidua;
                    foreach ($lotti as $idx => $lot):
                      if ($rimasto <= 0) break;
                      $assegna = min($rimasto, (float)$lot['quantita_disponibile']);
                      $rimasto -= $assegna;
                  ?>
                  <div class="qk-pp-lotto-row" data-lotto-id="<?= (int)$lot['id'] ?>">
                    <span class="qk-pp-lotto-codice">
                      <?= e($lot['codice']) ?>
                      <?php if ($idx === 0 && count($lotti) > 1): ?>
                        <span class="qk-pp-fefo-tag">FEFO</span>
                      <?php endif; ?>
                    </span>
                    <span class="qk-pp-lotto-disp">
                      <?= number_format((float)$lot['quantita_disponibile'], 3, ',', '.') ?> <?= $uom ?>
                    </span>
                    <span class="qk-pp-lotto-scad <?= !$lot['data_scadenza'] ? 'text-muted' : '' ?>">
                      <?= $lot['data_scadenza'] ? date('d/m/Y', strtotime($lot['data_scadenza'])) : '—' ?>
                    </span>
                    <div class="qk-pp-lotto-qty-wrap">
                      <input type="number"
                             name="lotti[<?= $rigaId ?>][<?= (int)$lot['id'] ?>]"
                             class="qk-form-control qk-pp-lotto-qta"
                             step="0.001" min="0"
                             max="<?= (float)$lot['quantita_disponibile'] ?>"
                             value="<?= number_format($assegna, 3, '.', '') ?>"
                             data-riga-id="<?= $rigaId ?>"
                             data-lotto-disp="<?= (float)$lot['quantita_disponibile'] ?>">
                      <span class="qk-pp-uom"><?= $uom ?></span>
                    </div>
                    <button type="button"
                            class="btn-qk btn-qk-ghost btn-qk-xs qk-pp-btn-rimuovi"
                            data-riga-id="<?= $rigaId ?>"
                            title="Rimuovi questo lotto">
                      <i class="fa-solid fa-xmark"></i>
                    </button>
                  </div>
                  <?php endforeach; ?>

                  <?php if ($rimasto > 0.0005): ?>
                  <div class="qk-pp-stock-alert partial" id="stock-alert-<?= $rigaId ?>">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    Stock insufficiente: mancano <strong><?= number_format($rimasto, 3, ',', '.') ?> <?= $uom ?></strong>
                  </div>
                  <?php endif; ?>
                </div>

                <!-- Footer riga: aggiungi lotto + totale -->
                <div class="qk-pp-lotti-footer">
                  <button type="button"
                          class="btn-qk btn-qk-ghost btn-qk-sm qk-pp-btn-aggiungi"
                          data-riga-id="<?= $rigaId ?>"
                          data-prodotto-id="<?= $prodId ?>"
                          data-uom="<?= $uom ?>">
                    <i class="fa-solid fa-plus me-1"></i> Aggiungi lotto
                  </button>
                  <div class="qk-pp-totale" id="pp-tot-<?= $rigaId ?>">
                    Totale:
                    <strong id="pp-tot-val-<?= $rigaId ?>">
                      <?php
                        // Somma delle qty pre-allocate (ricalcolata dal JS all'init)
                        $rimAssiegnato = $qtaResidua;
                        $totAssegnato = 0;
                        foreach ($lotti as $l) {
                            if ($rimAssiegnato <= 0) break;
                            $q = min($rimAssiegnato, (float)$l['quantita_disponibile']);
                            $totAssegnato += $q;
                            $rimAssiegnato -= $q;
                        }
                        echo number_format($totAssegnato, 3, ',', '.');
                      ?>
                    </strong>
                    / <?= $qtaResidua ?> <?= $uom ?>
                  </div>
                </div>

              <?php endif; ?>
            </div><!-- .qk-pp-lotti-wrap -->
          <?php endif; ?>

        </div><!-- .qk-pp-riga-body -->
      </div><!-- .qk-pp-riga -->
      <?php endforeach; ?>

    </div><!-- .qk-pp-left -->

    <!-- ── COLONNA DESTRA: dati trasporto + riepilogo ───────────────────── -->
    <div class="qk-pp-right">
      <div class="qk-pp-panel" id="qk-pp-panel">

        <!-- Info ordine -->
        <div class="qk-pp-panel-section qk-pp-order-info">
          <div class="qk-pp-panel-label">Ordine</div>
          <div class="qk-pp-order-num"><?= e($order['numero_ordine']) ?></div>
          <div class="qk-pp-order-cliente"><?= e($order['cliente_nome']) ?></div>
        </div>

        <div class="qk-pp-divider"></div>

        <!-- Riepilogo stato allocazione -->
        <div class="qk-pp-panel-section">
          <div class="qk-pp-panel-label">Stato allocazione</div>
          <div class="qk-pp-riepilogo" id="pp-riepilogo">
            <div class="qk-pp-riepilogo-row">
              <span><i class="fa-solid fa-circle-check"></i> Pronte</span>
              <strong id="riepilogo-pronte"><?= $righeConLotti ?></strong>
            </div>
            <div class="qk-pp-riepilogo-row warn" id="riepilogo-row-warn" <?= $righeConLotti == $totRighe ? 'style="display:none"' : '' ?>>
              <span><i class="fa-solid fa-triangle-exclamation"></i> Con problemi</span>
              <strong id="riepilogo-problemi"><?= $totRighe - $righeConLotti ?></strong>
            </div>
          </div>
        </div>

        <div class="qk-pp-divider"></div>

        <!-- Dati trasporto -->
        <div class="qk-pp-panel-section">
          <div class="qk-pp-panel-label">Dati Trasporto</div>

          <div class="qk-form-group">
            <label class="qk-form-label">Vettore</label>
            <input type="text" name="vettore" class="qk-form-control"
                   placeholder="es. BRT, DHL, Proprio…">
          </div>

          <div class="qk-form-group">
            <label class="qk-form-label">N° Tracking</label>
            <input type="text" name="tracking" class="qk-form-control"
                   placeholder="Codice spedizioniere">
          </div>

          <div class="qk-form-group">
            <label class="qk-form-label">Causale</label>
            <select name="causale_trasporto" class="qk-form-control">
              <option value="Vendita" selected>Vendita</option>
              <option value="Reso">Reso</option>
              <option value="Lavorazione">Lavorazione</option>
              <option value="Omaggio">Omaggio</option>
            </select>
          </div>

          <div class="qk-form-group">
            <label class="qk-form-label">Porto</label>
            <select name="porto" class="qk-form-control">
              <option value="franco" selected>Franco (a ns. carico)</option>
              <option value="assegnato">Assegnato (a dest.)</option>
            </select>
          </div>

          <div class="qk-pp-panel-row2">
            <div class="qk-form-group">
              <label class="qk-form-label">Aspetto beni</label>
              <input type="text" name="aspetto_beni" class="qk-form-control"
                     placeholder="es. Fusti…">
            </div>
            <div class="qk-form-group">
              <label class="qk-form-label">N° Colli</label>
              <input type="number" name="numero_colli" class="qk-form-control"
                     min="0" value="0">
            </div>
          </div>

          <div class="qk-form-group">
            <label class="qk-form-label">Peso lordo (kg)</label>
            <input type="number" name="peso_lordo_kg" class="qk-form-control"
                   step="0.001" min="0" value="0">
          </div>

          <div class="qk-form-group">
            <label class="qk-form-label"><i class="fa-solid fa-camera me-1 text-muted"></i> Foto Pallet / Carico</label>
            <input type="file" name="pallet_foto[]" class="qk-form-control" accept="image/*" multiple>
            <small class="qk-text-muted" style="font-size:0.75rem">Seleziona una o più foto da associare alla spedizione</small>
          </div>
        </div>

        <!-- CTA sticky -->
        <div class="qk-pp-panel-actions">
          <button type="submit" class="btn-qk btn-qk-primary w-100" id="pp-btn-conferma">
            <i class="fa-solid fa-truck-fast me-2"></i>
            Conferma Spedizione &amp; Genera DDT
          </button>
          <a href="<?= url('sales/orders/edit') ?>?id=<?= $orderId ?>"
             class="btn-qk btn-qk-secondary w-100 mt-2 text-center">
            <i class="fa-solid fa-arrow-left me-1"></i> Torna all'Ordine
          </a>
          <div class="qk-pp-submit-note" id="pp-submit-note" style="display:none">
            <i class="fa-solid fa-circle-exclamation"></i>
            <span id="pp-submit-note-text"></span>
          </div>
        </div>

      </div><!-- .qk-pp-panel -->
    </div><!-- .qk-pp-right -->

  </div><!-- .qk-pp-layout -->
</form>

<!-- Dati lotti per JS (tutti i lotti per prodotto, per aggiunta manuale) -->
<script>
window.QkPickPack = {
  apiLotti:   <?= json_encode(url('sales/orders/api/lotti-disponibili')) ?>,
  totRighe:   <?= $totRighe ?>,
  righeOk:    <?= $righeConLotti ?>,
};
</script>
