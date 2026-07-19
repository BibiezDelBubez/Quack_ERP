<?php
/**
 * purchases/orders/_detail.php
 * Dettaglio ordine di acquisto.
 * Variabili attese: $detail (array), $errors (array), $saved (bool), $page_title (string)
 */

$o     = $detail['ordine'];
$righe = $detail['righe'];
$ricezioni = $detail['ricezioni'] ?? [];
$id    = (int)$o['id'];

$currStato = $o['stato'];
$isBozza        = $currStato === 'bozza';
$isInAppr       = $currStato === 'in_approvazione';
$isApprovato    = $currStato === 'approvato';
$isInviato      = $currStato === 'inviato';
$isPartRicevuto = $currStato === 'parzialmente_ricevuto';
$isCompletato   = $currStato === 'completato';
$isAnnullato    = $currStato === 'annullato';

$canSave = !$isCompletato && !$isAnnullato;
$canEditLogistics = !$isCompletato && !$isAnnullato;

// Tracciabilità manuale "fatturato/da fatturare" (REVISIONE Ciclo Acquisti,
// 17/07/2026) — mirror esatto di app/views/sales/orders/_detail.php.
$statoFatturazione = $o['stato_fatturazione'] ?? 'da_fatturare';
$isFatturato = $statoFatturazione === 'fatturato';

// Find draft reception if any
$draftRicezione = null;
foreach ($ricezioni as $rc) {
    if ($rc['stato'] === 'bozza') {
        $draftRicezione = $rc;
        break;
    }
}
?>


<!-- Guidance Box -->
<div class="qk-guidance-box mb-4 p-3 ">
  <div class="fs-4 text-primary"><i class="fa-solid fa-circle-info"></i></div>
  <div>
    <h6 class="mb-1 fw-bold text-primary">Cosa fare adesso?</h6>
    <p class="mb-0 text-muted" style="font-size:0.875rem">
      <?php if ($isBozza): ?>
        L'ordine è in stato di Bozza. Verifica i dati di testata e delle righe, quindi approvalo per procedere.
      <?php elseif ($isInAppr): ?>
        L'ordine è in attesa di approvazione. Verifica i dati e clicca su "Approva Ordine".
      <?php elseif ($isApprovato): ?>
        L'ordine è stato approvato! Clicca su <strong>"Conferma e Invia Ordine"</strong> per inviarlo formalmente al fornitore e sbloccare la ricezione merci.
      <?php elseif ($isInviato): ?>
        L'ordine è stato confermato ed è attivo. È stata generata una bozza di ricezione: clicca su <strong>"Vedi Bozza Ricevimento"</strong> o vai in magazzino per registrare l'arrivo della merce.
      <?php elseif ($isPartRicevuto): ?>
        Alcuni articoli sono stati ricevuti. Se la merce rimanente non arriverà più, clicca su <strong>"Forza Chiusura Ordine"</strong> per completare l'ordine senza attendere altre consegne.
      <?php elseif ($isCompletato): ?>
        Tutta la merce è stata ricevuta o l'ordine è stato chiuso forzatamente. Il ciclo per questo documento è concluso.
      <?php elseif ($isAnnullato): ?>
        Questo ordine è stato annullato e non è più attivo.
      <?php endif; ?>
    </p>
  </div>
</div>

<!-- StatusBar -->
<div class="qk-statusbar qk-flex-row qk-justify-between mb-4" style="padding:.5rem .75rem;background:var(--qk-table-head-bg);border:1px solid var(--qk-card-border);border-radius:var(--qk-radius)">
  
  <div class="qk-statusbar-buttons qk-flex-row qk-gap-sm">
    <?php if ($isBozza || $isInAppr): ?>
      <form action="<?= url('purchases/orders/action') ?>" method="post" class="d-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="azione" value="approva">
        <button type="submit" class="btn-qk btn-qk-success btn-qk-sm fw-bold">
          <i class="fa-solid fa-check me-1"></i> Approva Ordine
        </button>
      </form>
    <?php endif; ?>

    <?php if ($isApprovato): ?>
      <form action="<?= url('purchases/orders/action') ?>" method="post" class="d-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="azione" value="invia">
        <button type="submit" class="btn-qk btn-qk-primary btn-qk-sm fw-bold">
          <i class="fa-solid fa-circle-check me-1"></i> Conferma e Invia Ordine
        </button>
      </form>
    <?php endif; ?>

    <?php if ($isInviato || $isPartRicevuto): ?>
      <?php if ($draftRicezione): ?>
        <a href="<?= url('purchases/receipts/edit') . '?id=' . $draftRicezione['id'] ?>" class="btn-qk btn-qk-success btn-qk-sm fw-bold">
          <i class="fa-solid fa-boxes-packing me-1"></i> Vedi Bozza Ricevimento
        </a>
      <?php else: ?>
        <a href="<?= url('purchases/receipts/create') . '?ordine_id=' . $id ?>" class="btn-qk btn-qk-success btn-qk-sm fw-bold">
          <i class="fa-solid fa-boxes-packing me-1"></i> Registra Ricevimento
        </a>
      <?php endif; ?>

      <form action="<?= url('purchases/orders/action') ?>" method="post" class="d-inline"
            onsubmit="return confirm('La chiusura forzata completerà l\'ordine annullando i residui attesi e azzerando la quantità in arrivo a magazzino. Procedere?');">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="azione" value="forza_chiusura">
        <button type="submit" class="btn-qk btn-qk-warning btn-qk-sm fw-bold">
          <i class="fa-solid fa-circle-xmark me-1"></i> Forza Chiusura Ordine
        </button>
      </form>
    <?php endif; ?>

    <?php if (!$isCompletato && !$isAnnullato):
      // REVISIONE Ciclo Acquisti §1.3 (deciso 14/07/2026, implementato 17/07/2026):
      // popup di conferma esplicativo quando l'ordine ha già ricezioni parziali —
      // non un semplice "sei sicuro?". Nessun conteggio numerico aggregato: le
      // righe possono avere UoM diverse tra loro, un "X su Y" sommato sarebbe
      // fuorviante.
      $confermaAnnulla = $isPartRicevuto
          ? "Questo ordine ha già ricevuto merce parzialmente. Annullandolo, il residuo non ancora ricevuto non arriverà più; quanto già ricevuto resta regolarmente a magazzino. Continuare?"
          : "Sei sicuro di voler annullare questo ordine?";
    ?>
      <form action="<?= url('purchases/orders/action') ?>" method="post" class="d-inline"
            onsubmit="return confirm('<?= e($confermaAnnulla) ?>');">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="azione" value="annulla">
        <button type="submit" class="btn-qk btn-qk-danger btn-qk-sm">
          <i class="fa-solid fa-ban me-1"></i> Annulla
        </button>
      </form>
    <?php endif; ?>

    <?php if (!$isFatturato): ?>
      <form action="<?= url('purchases/orders/action') ?>" method="post" class="d-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="azione" value="segna_fatturato">
        <button type="submit" class="btn-qk btn-qk-secondary btn-qk-sm fw-bold">
          <i class="fa-solid fa-file-invoice-dollar me-1"></i> Segna come Fatturato
        </button>
      </form>
    <?php else: ?>
      <form action="<?= url('purchases/orders/action') ?>" method="post" class="d-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="azione" value="segna_da_fatturare">
        <button type="submit" class="btn-qk btn-qk-secondary btn-qk-sm text-danger">
          <i class="fa-solid fa-undo me-1"></i> Ripristina "Da Fatturare"
        </button>
      </form>
    <?php endif; ?>

    <a href="<?= url('purchases/orders/docx') . '?id=' . $id ?>" class="btn-qk btn-qk-secondary btn-qk-sm" target="_blank">
      <i class="fa-solid fa-file-word text-primary me-1"></i> Word (DOCX)
    </a>
    
    <a href="<?= url('purchases/orders') ?>" class="btn-qk btn-qk-secondary btn-qk-sm">
      <i class="fa-solid fa-arrow-left me-1"></i> Lista Ordini
    </a>
  </div>

  <?php
  $poSteps = [
    ['bozza',                 'Bozza'],
    ['approvato',             'Approvato'],
    ['inviato',               'Inviato'],
    ['parzialmente_ricevuto', 'Parz. Ricevuto'],
    ['completato',            'Completato'],
  ];
  if ($currStato === 'annullato') {
    $poSteps[] = ['annullato', 'Annullato'];
  }
  $poCurrentIdx = 0;
  foreach ($poSteps as $idx => [$s, $lbl]) {
    if ($currStato === $s) {
      $poCurrentIdx = $idx;
      break;
    }
  }
  ?>
  <ul class="qk-status-path">
    <?php foreach ($poSteps as $idx => [$s, $lbl]):
      if ($currStato === $s) {
        $statusClass = 'active';
      } elseif ($currStato === 'in_approvazione' && $idx === 0) {
        $statusClass = 'completed';
      } elseif ($currStato === 'in_approvazione' && $idx > 0) {
        $statusClass = 'pending';
      } elseif ($idx < $poCurrentIdx) {
        $statusClass = 'completed';
      } else {
        $statusClass = 'pending';
      }
    ?>
      <li class="qk-status-path-item <?= $statusClass ?>">
        <?= $lbl ?>
      </li>
    <?php endforeach; ?>
  </ul>
</div>

<form action="<?= url('purchases/orders/edit') . '?id=' . $id ?>" method="post">
  <?= csrf_field() ?>

  <?php if (!empty($errors['_global'])): ?>
    <div class="qk-alert qk-alert-danger mb-3">
      <i class="fa-solid fa-triangle-exclamation"></i>
      <span><?= e($errors['_global']) ?></span>
    </div>
  <?php endif; ?>

  <div class="qk-card mb-4">
    <div class="qk-card-header qk-flex-row qk-justify-between">
      <span class="qk-card-title">
        <i class="fa-solid fa-file-circle-check me-2"></i> Ordine d'Acquisto <?= e($o['numero']) ?>
      </span>
      <div class="qk-flex-row qk-gap-sm">
        <span class="qk-badge <?= $isFatturato ? 'confirmed' : 'draft' ?> fw-bold" style="font-size:0.85rem">
          <i class="fa-solid <?= $isFatturato ? 'fa-circle-check' : 'fa-hourglass' ?> me-1"></i>
          Fatturazione: <?= $isFatturato ? 'Fatturato' : 'Da Fatturare' ?>
        </span>
        <span class="badge bg-secondary text-monospace"><?= e($o['valuta']) ?></span>
      </div>
    </div>

    <div class="qk-card-body">
      <!-- Grid a 3 colonne per Dati Testata -->
      <div class="row g-3">
        <!-- Colonna 1: Fornitore & Contatto -->
        <div class="col-md-4">
          <div class="qk-form-group mb-2">
            <label class="qk-form-label">Fornitore</label>
            <input type="text" class="qk-form-control" value="<?= e($o['fornitore_nome']) ?>" readonly>
          </div>

          <div class="qk-form-group mb-2">
            <label class="qk-form-label">Alla Cortese Attenzione (Referente)</label>
            <input type="text" class="qk-form-control" value="<?= e($o['contatto_nome'] ?: '—') ?>" readonly>
          </div>

          <div class="qk-form-group mb-2">
            <label class="qk-form-label">Contatti Referente</label>
            <div class="qk-flex-row qk-gap-sm">
              <input type="text" class="qk-form-control" style="font-size:0.8rem;" value="<?= e($o['contatto_telefono'] ?: $o['fornitore_telefono'] ?: '—') ?>" placeholder="Telefono" readonly>
              <input type="text" class="qk-form-control" style="font-size:0.8rem;" value="<?= e($o['contatto_email'] ?: $o['fornitore_email'] ?: '—') ?>" placeholder="Email" readonly>
            </div>
          </div>
        </div>

        <!-- Colonna 2: Dati Commerciali & Pagamento -->
        <div class="col-md-4">
          <div class="qk-form-group mb-2">
            <label class="qk-form-label">Metodo di Pagamento</label>
            <input type="text" class="qk-form-control" value="<?= e($o['metodo_pagamento_descrizione'] ?: $o['condizioni_pagamento'] ?: '—') ?>" readonly>
          </div>

          <div class="qk-form-group mb-2">
            <label class="qk-form-label">Listino di Acquisto</label>
            <input type="text" class="qk-form-control" value="<?= e($o['listino_acquisto_nome'] ?: 'Nessun listino collegato') ?>" readonly>
          </div>

          <div class="qk-form-group mb-2">
            <label class="qk-form-label">Resa Porto</label>
            <input type="text" class="qk-form-control" value="<?= e($o['resa_porto'] ?: '—') ?>" readonly>
          </div>
        </div>

        <!-- Colonna 3: Riferimenti & Date -->
        <div class="col-md-4">
          <div class="qk-form-group mb-2">
            <label class="qk-form-label">Data Ordine</label>
            <input type="date" class="qk-form-control" value="<?= e($o['data_ordine']) ?>" readonly>
          </div>

          <div class="qk-form-group mb-2">
            <label class="qk-form-label">Data Consegna Prevista (Iniziale)</label>
            <input type="date" name="data_consegna_prevista" class="qk-form-control" 
                   value="<?= e($o['data_consegna_prevista'] ?? '') ?>"
                   <?= $canEditLogistics ? '' : 'readonly' ?>>
          </div>

          <div class="qk-form-group mb-2">
            <label class="qk-form-label">Riferimento Preventivo Fornitore</label>
            <input type="text" name="riferimento_fornitore" class="qk-form-control" 
                   value="<?= e($o['riferimento_fornitore'] ?? '') ?>" 
                   placeholder="Rif. preventivo del fornitore"
                   <?= $canEditLogistics ? '' : 'readonly' ?>>
          </div>

          <div class="qk-form-group mb-2">
            <label class="qk-form-label">Altri Riferimenti / Note Logistiche</label>
            <input type="text" name="altri_riferimenti" class="qk-form-control"
                   value="<?= e($o['altri_riferimenti'] ?? '') ?>"
                   placeholder="Es. DDT di riferimento logistico, note corriere"
                   <?= $canEditLogistics ? '' : 'readonly' ?>>
          </div>

          <div class="qk-form-group mb-2">
            <label class="qk-form-label">Magazzino Destinazione</label>
            <?php if ($canEditLogistics): ?>
            <select name="magazzino_destinazione_id" class="qk-form-control">
              <option value="">— Non specificato —</option>
              <?php foreach ($magazzini_options as $mg): ?>
              <option value="<?= $mg['id'] ?>" <?= (int)($o['magazzino_destinazione_id'] ?? 0) === (int)$mg['id'] ? 'selected' : '' ?>>
                <?= e($mg['nome']) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <span class="qk-form-hint mt-1 d-block">Dove verrà accreditata la merce ricevuta per questo ordine.</span>
            <?php else: ?>
            <input type="text" class="qk-form-control" readonly
                   value="<?php
                     $mgNome = '—';
                     foreach ($magazzini_options as $mg) {
                         if ((int)$mg['id'] === (int)($o['magazzino_destinazione_id'] ?? 0)) { $mgNome = $mg['nome']; break; }
                     }
                     echo e($mgNome);
                   ?>">
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Odoo-style Navigation Tabs per Spedizione & Contatti nell'Ordine -->
  <ul class="nav qk-nav-tabs mb-3" role="tablist">
    <li class="nav-item">
      <button class="nav-link active" id="tab-ordine-articoli-btn" type="button" onclick="switchOrdineTab('articoli')">
        <i class="fa-solid fa-list-check me-1"></i> Articoli Ordinati
      </button>
    </li>
    <li class="nav-item">
      <button class="nav-link" id="tab-ordine-spedizione-contatti-btn" type="button" onclick="switchOrdineTab('spedizione-contatti')">
        <i class="fa-solid fa-truck-fast me-1"></i> Spedizione & Referente
      </button>
    </li>
  </ul>

  <!-- Contenitore Tab Articoli -->
  <div class="tab-content">
    <div class="tab-pane active" id="pane-ordine-articoli">
      <!-- Prodotti dell'ordine -->
      <div class="qk-card mb-4">
        <div class="qk-card-header">
          <span class="qk-card-title"><i class="fa-solid fa-list-check me-2"></i> Articoli Ordinati</span>
        </div>
        <div class="qk-table-wrap">
          <table class="qk-table" style="font-size:0.875rem">
            <thead>
              <tr>
                <th>Prodotto</th>
                <th>Descrizione</th>
                <th>SKU Fornitore</th>
                <th class="qk-text-right">Ordinata</th>
                <th class="qk-text-right">Ricevuta</th>
                <th class="qk-text-center">U.M.</th>
                <th class="qk-text-right">Prezzo Unit.</th>
                <th class="qk-text-center">Sconto</th>
                <th class="qk-text-center">IVA</th>
                <th class="qk-text-right">Imponibile</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($righe as $r): ?>
                <tr>
                  <td>
                    <strong><?= e($r['sku_interno']) ?></strong><br>
                    <span class="text-muted"><?= e($r['prodotto_nome']) ?></span>
                  </td>
                  <td><?= e($r['descrizione'] ?? '') ?></td>
                  <td><?= e($r['sku_fornitore'] ?? '—') ?></td>
                  <td class="qk-text-right" style="font-weight:600"><?= e((float)$r['quantita_ordinata']) ?></td>
                  <td class="qk-text-right text-success" style="font-weight:600"><?= e((float)$r['quantita_ricevuta']) ?></td>
                  <td class="qk-text-center"><span class="qk-badge draft"><?= e($r['uom'] ?? 'N/D') ?></span></td>
                  <td class="qk-text-right">€ <?= number_format((float)$r['prezzo_unitario'], 2, ',', '.') ?></td>
                  <td class="qk-text-center"><?= $r['sconto_perc'] > 0 ? (float)$r['sconto_perc'] . '%' : '—' ?></td>
                  <td class="qk-text-center"><?= (float)$r['iva_perc'] ?>%</td>
                  <td class="qk-text-right" style="font-weight:600">€ <?= number_format((float)$r['imponibile_riga'], 2, ',', '.') ?></td>
                </tr>
                <!-- Sezione pianificazione tranche della riga -->
                <?php if (!empty($r['pianificazione'])): ?>
                  <tr style="background-color:var(--qk-row-alt)">
                    <td colspan="10" style="padding-left: 2rem; border-top:none">
                      <div class="small">
                        <strong>Pianificazione consegne (Tranche):</strong>
                        <div class="qk-flex-row qk-gap-md mt-1">
                          <?php foreach ($r['pianificazione'] as $p): ?>
                            <span class="qk-badge <?= $p['stato'] === 'completata' ? 'confirmed' : 'pending' ?>" style="font-size:0.75rem">
                              Data: <strong><?= e($p['data_consegna_prevista'] ?? 'Non definita') ?></strong> |
                              Qta: <strong><?= e((float)$p['quantita_pianificata']) ?></strong> |
                              Ricevuta: <strong><?= e((float)$p['quantita_ricevuta']) ?></strong>
                              (<?= e($p['stato']) ?>)
                            </span>
                          <?php endforeach; ?>
                        </div>
                      </div>
                    </td>
                  </tr>
                <?php endif; ?>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <!-- Totali -->
        <div class="qk-flex-row qk-justify-end p-3">
          <div class="qk-card" style="width:300px; margin:0">
            <div class="qk-card-body" style="padding: 0.75rem 1rem">
              <div class="qk-flex-row qk-justify-between mb-2">
                <span class="text-muted">Imponibile:</span>
                <span class="fw-semibold">€ <?= number_format((float)$o['imponibile'], 2, ',', '.') ?></span>
              </div>
              <div class="qk-flex-row qk-justify-between mb-2">
                <span class="text-muted">IVA:</span>
                <span class="fw-semibold">€ <?= number_format((float)$o['totale_iva'], 2, ',', '.') ?></span>
              </div>
              <hr class="my-2">
              <div class="qk-flex-row qk-justify-between">
                <span class="fw-bold">Totale:</span>
                <span class="fw-bold h5 mb-0" style="color:var(--qk-accent-text)">€ <?= number_format((float)$o['totale'], 2, ',', '.') ?></span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Pane Spedizione & Contatti -->
    <div class="tab-pane d-none" id="pane-ordine-spedizione-contatti">
      <div class="qk-card mb-4">
        <div class="qk-card-body">
          <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:1.5rem;">
            
            <!-- Colonna Spedizione -->
            <div>
              <h4 style="font-size:.9rem; text-transform:uppercase; letter-spacing:.05em; color:var(--qk-text-muted); margin-bottom:1rem; border-bottom:1px solid var(--qk-border); padding-bottom:.5rem;">Destinazione Consegna</h4>
              <div class="border rounded p-3 mb-3 position-relative" style="background: var(--qk-bg-subtle); border-color: var(--qk-border) !important;">
                <div class="qk-flex-row qk-justify-between mb-3">
                  <h6 class="mb-0 fw-bold" style="font-size: 0.85rem;">
                    <i class="fa-solid fa-truck-ramp-box me-1"></i>Indirizzo di Consegna
                  </h6>
                  <?php if ($isInviato || $isPartRicevuto || $isCompletato): ?>
                    <span class="badge bg-danger text-white ms-2"><i class="fa-solid fa-lock me-1"></i> Contratto Confermato (Immodificabile)</span>
                  <?php elseif ($o['indirizzo_consegna_personalizzato']): ?>
                    <span class="badge bg-warning text-dark ms-2">Indirizzo Modificato</span>
                  <?php else: ?>
                    <span class="badge bg-success text-white ms-2">Anagrafica Allineata</span>
                  <?php endif; ?>
                </div>

                <div class="qk-form-group mb-2">
                  <label class="qk-form-label">Presso (c/o)</label>
                  <input type="text" class="qk-form-control" value="<?= e($o['consegna_presso'] ?? '') ?>" disabled readonly>
                </div>
                <div class="qk-form-row mb-2">
                  <div class="qk-form-group" style="flex:3">
                    <label class="qk-form-label">Via/Piazza</label>
                    <input type="text" class="qk-form-control" value="<?= e($o['consegna_via'] ?? '') ?>" disabled readonly>
                  </div>
                  <div class="qk-form-group" style="flex:1">
                    <label class="qk-form-label">Civico</label>
                    <input type="text" class="qk-form-control" value="<?= e($o['consegna_civico'] ?? '') ?>" disabled readonly>
                  </div>
                </div>
                <div class="qk-form-row mb-2">
                  <div class="qk-form-group" style="flex:2">
                    <label class="qk-form-label">CAP</label>
                    <input type="text" class="qk-form-control" value="<?= e($o['consegna_cap'] ?? '') ?>" disabled readonly>
                  </div>
                  <div class="qk-form-group" style="flex:4">
                    <label class="qk-form-label">Città</label>
                    <input type="text" class="qk-form-control" value="<?= e($o['consegna_citta'] ?? '') ?>" disabled readonly>
                  </div>
                </div>
                <div class="qk-form-row mb-2">
                  <div class="qk-form-group">
                    <label class="qk-form-label">Provincia</label>
                    <input type="text" class="qk-form-control" value="<?= e($o['consegna_provincia'] ?? '') ?>" disabled readonly>
                  </div>
                  <div class="qk-form-group">
                    <label class="qk-form-label">Nazione</label>
                    <input type="text" class="qk-form-control" value="<?= e($o['consegna_nazione'] ?? 'IT') ?>" disabled readonly>
                  </div>
                </div>
              </div>
            </div>

            <!-- Colonna Referente Fornitore -->
            <div>
              <h4 style="font-size:.9rem; text-transform:uppercase; letter-spacing:.05em; color:var(--qk-text-muted); margin-bottom:1rem; border-bottom:1px solid var(--qk-border); padding-bottom:.5rem;">Referente Fornitore</h4>
              <div class="border rounded p-3 mb-3 position-relative" style="background: var(--qk-bg-subtle); border-color: var(--qk-border) !important;">
                <div class="qk-flex-row qk-justify-between mb-3">
                  <h6 class="mb-0 fw-bold" style="font-size: 0.85rem;">
                    <i class="fa-solid fa-id-card-clip me-1"></i>Dettagli referente fornitore
                  </h6>
                  <?php if ($isInviato || $isPartRicevuto || $isCompletato): ?>
                    <span class="badge bg-danger text-white ms-2"><i class="fa-solid fa-lock me-1"></i> Contratto Confermato</span>
                  <?php elseif ($o['contatto_personalizzato']): ?>
                    <span class="badge bg-warning text-dark ms-2">Contatto Personalizzato</span>
                  <?php else: ?>
                    <span class="badge bg-success text-white ms-2">Anagrafica Allineata</span>
                  <?php endif; ?>
                </div>

                <div class="qk-form-group mb-2">
                  <label class="qk-form-label">Alla c.a. di (Nome Referente)</label>
                  <input type="text" class="qk-form-control" value="<?= e($o['contatto_nome'] ?? '') ?>" disabled readonly>
                </div>
                <div class="qk-form-group mb-2">
                  <label class="qk-form-label">Telefono di contatto</label>
                  <input type="text" class="qk-form-control" value="<?= e($o['contatto_telefono'] ?? '') ?>" disabled readonly>
                </div>
                <div class="qk-form-group mb-2">
                  <label class="qk-form-label">Email di contatto</label>
                  <input type="text" class="qk-form-control" value="<?= e($o['contatto_email'] ?? '') ?>" disabled readonly>
                </div>
              </div>
            </div>
            
          </div>
        </div>
      </div>
    </div>
  </div>

  

  <!-- Note ed allegati -->
  <div class="qk-form-grid mb-4">
    <div class="qk-card" style="margin:0">
      <div class="qk-card-header">
        <span class="qk-card-title"><i class="fa-solid fa-comment-dots me-2 text-muted"></i>Note Fornitore</span>
      </div>
      <div class="qk-card-body">
        <textarea name="note_fornitore" class="qk-form-control" rows="3" 
                  <?= $canEditLogistics ? '' : 'readonly' ?>><?= e($o['note_fornitore'] ?? '') ?></textarea>
      </div>
    </div>

    <div class="qk-card" style="margin:0">
      <div class="qk-card-header">
        <span class="qk-card-title"><i class="fa-solid fa-lock me-2 text-muted"></i>Note Interne</span>
      </div>
      <div class="qk-card-body">
        <textarea name="note_interne" class="qk-form-control" rows="3" 
                  <?= $canEditLogistics ? '' : 'readonly' ?>><?= e($o['note_interne'] ?? '') ?></textarea>
      </div>
    </div>
  </div>

  <!-- Ricezioni collegate -->
  <?php if (!empty($ricezioni)): ?>
    <div class="qk-card mb-4">
      <div class="qk-card-header">
        <span class="qk-card-title"><i class="fa-solid fa-boxes-stacked me-2 text-muted"></i> Ricezioni collegate</span>
      </div>
      <div class="qk-table-wrap">
        <table class="qk-table" style="font-size:0.875rem">
          <thead>
            <tr>
              <th>Numero Ricezione</th>
              <th>Stato</th>
              <th>DDT Numero</th>
              <th>Data Creazione</th>
              <th>Data Ricezione</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($ricezioni as $rc): ?>
              <tr>
                <td><strong><?= e($rc['numero']) ?></strong></td>
                <td>
                  <span class="qk-badge <?= $rc['stato'] === 'completata' ? 'confirmed' : 'draft' ?>">
                    <?= e($rc['stato']) ?>
                  </span>
                </td>
                <td><?= e($rc['ddt_numero'] ?? '—') ?></td>
                <td><?= e($rc['created_at']) ?></td>
                <td><?= e($rc['data_effettiva'] ?? '—') ?></td>
                <td class="qk-text-right">
                  <a href="<?= url('purchases/receipts/edit') . '?id=' . $rc['id'] ?>" class="btn-qk btn-qk-xs btn-qk-secondary">
                    <i class="fa-solid fa-pencil"></i> Apri
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($canSave): ?>
    <div class="qk-flex-row qk-justify-end mb-5">
      <button type="submit" class="btn-qk btn-qk-primary px-4 fw-bold">
        <i class="fa-solid fa-floppy-disk me-1"></i> Salva Note e Riferimenti
      </button>
    </div>
  <?php endif; ?>

</form>

<script src="<?= url('public/assets/js/purchases/orders_detail.js') ?>"></script>
