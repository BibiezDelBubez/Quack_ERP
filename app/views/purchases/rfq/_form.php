<?php
/**
 * purchases/rfq/_form.php
 * Form di creazione / modifica Richiesta di Offerta (RFQ).
 *
 * Variabili attese:
 *   $id               int|null    id RFQ (null = nuova)
 *   $values           array       valori form
 *   $righe            array       righe prodotto
 *   $errors           array       errori di validazione
 *   $detail           array|null  dettaglio completo (include offerte, versioni)
 *   $anteprima_numero string      numero ERP proposto
 */

$isEdit   = isset($id) && $id > 0;
$rfq      = $detail['rfq']      ?? null;
$offerte  = $detail['offerte']  ?? [];
$versioni = $detail['versioni'] ?? [];
$stato    = $values['stato']    ?? 'bozza';

$isBozza       = $stato === 'bozza';
$isInviata     = $stato === 'inviata';
$isRevisionata = $stato === 'revisionata';
$isChiusa      = $stato === 'chiusa';

// Solo le bozze possono essere modificate
$canEdit = $isBozza;
$readonly = $canEdit ? '' : 'readonly';
$disabled = $canEdit ? '' : 'disabled';

// Etichetta stato
$statoLabel = [
    'bozza'       => ['draft',     'Bozza'],
    'inviata'     => ['confirmed', 'Inviata'],
    'revisionata' => ['pending',   'Revisionata'],
    'chiusa'      => ['delivered', 'Chiusa'],
][$stato] ?? ['draft', $stato];

// Flash messaggi inline
$savedOk = isset($saved) && $saved;
?>


<style>
/* Miglioramento UX per righe RFQ */
#tbl-righe tbody tr {
  border-bottom: 1px solid var(--qk-border);
  transition: background-color 0.12s ease;
}
#tbl-righe tbody tr:nth-child(even) {
  background-color: var(--qk-bg-subtle);
}
#tbl-righe tbody tr:hover {
  background-color: var(--qk-table-head-bg) !important;
}
#tbl-righe tbody td {
  padding: .65rem .5rem !important;
  vertical-align: middle;
}
/* Affordance visiva input */
#tbl-righe .qk-form-control {
  background: transparent !important;
  border: 1px solid transparent !important;
  padding: .25rem .4rem !important;
  height: auto !important;
  font-size: .875rem !important;
  outline: none;
  transition: all 0.12s ease;
}
#tbl-righe .qk-form-control:focus {
  border-color: var(--qk-accent) !important;
  background: var(--qk-input-bg, var(--qk-card-bg)) !important;
  box-shadow: 0 1px 3px rgba(0,0,0,0.08) !important;
}
#tbl-righe .qk-form-control[readonly], #tbl-righe .qk-form-control:disabled {
  cursor: not-allowed;
  opacity: 0.85;
}
</style>

<!-- ════════════════════════════════════════════════════════════════════  <!-- Guidance Box -->
<div class="qk-guidance-box mb-4">
  <div class="fs-4"><i class="fa-solid fa-circle-info"></i></div>
  <div>
    <h6 class="mb-1 fw-bold">Cosa fare adesso?</h6>
    <p class="mb-0 text-muted" style="font-size:0.875rem">
      <?php if (!$isEdit): ?>
        Crea una nuova richiesta di offerta (RFQ) per richiedere prezzi e disponibilità ai fornitori. Aggiungi i prodotti e clicca su "Salva".
      <?php elseif ($isBozza): ?>
        La RFQ è in Bozza. Aggiungi i prodotti che desideri quotare, quindi clicca su <strong>"Invia al Fornitore"</strong> per inviargli la richiesta.
      <?php elseif ($isInviata): ?>
        La RFQ è stata inviata al fornitore ed è in attesa dei prezzi. Quando ricevi il preventivo dal fornitore, clicca su <strong>"Registra Preventivo Ricevuto"</strong> per inserire i prezzi concordati.
      <?php elseif ($isRevisionata): ?>
        La RFQ è stata revisionata. Puoi creare una nuova offerta o chiuderla.
      <?php elseif ($isChiusa): ?>
        Questa RFQ è chiusa. Il ciclo di preventivazione è completato.
      <?php endif; ?>
    </p>
  </div>
</div>

<!-- Barra Azioni e Stato Sticky -->
<div class="qk-sticky-action-bar mb-4">
  <div class="qk-sticky-action-bar-left">
    <?php if ($canEdit): ?>
      <button type="submit" form="form-rfq" class="btn-qk btn-qk-primary">
        <i class="fa-solid fa-floppy-disk"></i> <?= $isEdit ? 'Salva modifiche' : 'Crea RFQ' ?>
      </button>
      <a href="<?= url('purchases/rfq') ?>" class="btn-qk btn-qk-secondary">Annulla</a>
    <?php endif; ?>

    <?php if ($isEdit): ?>
      <?php if ($isBozza): ?>
        <button type="button" class="btn-qk btn-qk-secondary" id="btn-invia-rfq" title="Invia la richiesta di preventivo al fornitore (genera il PDF e segna il documento come Inviato)">
          <i class="fa-solid fa-paper-plane"></i> Invia al Fornitore
        </button>
      <?php endif; ?>

      <?php if ($isInviata): ?>
        <form method="post" action="<?= url('purchases/rfq/action') ?>" class="d-inline">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $id ?>">
          <input type="hidden" name="azione" value="nuova_versione">
          <button type="submit" class="btn-qk btn-qk-secondary" title="Crea una nuova versione di questa RFQ (ad esempio se il fornitore chiede modifiche alle righe prima di quotare)">
            <i class="fa-solid fa-code-branch"></i> Crea Nuova Versione
          </button>
        </form>
        <a href="<?= url('purchases/quotes/create') . '?rfq_id=' . $id ?>" class="btn-qk btn-qk-primary" title="Registra l'offerta economica con i prezzi ricevuti dal fornitore per questa richiesta">
          <i class="fa-solid fa-file-invoice-dollar"></i> Registra Preventivo Ricevuto
        </a>
        <form method="post" action="<?= url('purchases/rfq/action') ?>" class="d-inline"
              onsubmit="return confirm('Chiudere la RFQ senza registrare un\'offerta?')">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $id ?>">
          <input type="hidden" name="azione" value="chiudi">
          <button type="submit" class="btn-qk btn-qk-secondary" title="Chiudi la richiesta senza registrare alcuna offerta (ad esempio se il fornitore non risponde o ha rifiutato)">
            <i class="fa-solid fa-lock text-muted"></i> Chiudi
          </button>
        </form>
      <?php endif; ?>

      <a href="<?= url('purchases/rfq/pdf') . '?id=' . $id ?>" class="btn-qk btn-qk-secondary" target="_blank" title="Stampa o scarica la richiesta di preventivo in formato PDF">
        <i class="fa-solid fa-file-pdf text-danger"></i> PDF
      </a>
      <a href="<?= url('purchases/rfq/docx') . '?id=' . $id ?>" class="btn-qk btn-qk-secondary" target="_blank" title="Scarica il documento in formato Microsoft Word (DOCX)">
        <i class="fa-solid fa-file-word text-primary"></i> Word (DOCX)
      </a>
      <span class="qk-dirty-warning" id="dirty-warn-badge"><i class="fa-solid fa-triangle-exclamation"></i> Modifiche non salvate (salva per aggiornare i PDF)</span>
    <?php endif; ?>
  </div>

  <div class="qk-sticky-action-bar-right">
    <?php if ($isEdit && $isBozza): ?>
      <form method="post" action="<?= url('purchases/rfq/delete') ?>" class="d-inline"
            onsubmit="return confirm('Eliminare definitivamente la RFQ <?= e($rfq['numero']) ?>?')">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <button type="submit" class="btn-qk btn-qk-secondary text-danger" title="Elimina definitivamente questa richiesta in bozza">
          <i class="fa-solid fa-trash"></i> Elimina
        </button>
      </form>
    <?php endif; ?>
    <a href="<?= url('purchases/rfq') ?>" class="btn-qk btn-qk-secondary">
      <i class="fa-solid fa-arrow-left"></i> Torna alla lista
    </a>

    <!-- Pipeline visivo stati -->
    <ul class="qk-status-path d-none d-sm-flex" style="margin: 0; padding: 0;">
      <?php
      $steps = [
        ['bozza',       'Bozza'],
        ['inviata',     'Inviata'],
        ['revisionata', 'Revisionata'],
        ['chiusa',      'Chiusa'],
      ];
      $currentIdx = 0;
      foreach ($steps as $idx => [$s, $lbl]) {
        if ($stato === $s) {
          $currentIdx = $idx;
          break;
        }
      }
      foreach ($steps as $idx => [$s, $lbl]):
        if ($stato === $s) {
          $statusClass = 'active';
        } elseif ($idx < $currentIdx) {
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
</div>

  <!-- Form principale (bozza/editabile) -->
  <form method="post"
        action="<?= $isEdit ? url('purchases/rfq/edit') . '?id=' . $id : url('purchases/rfq/create') ?>"
        id="form-rfq">
    <?= csrf_field() ?>
    <input type="hidden" name="stato" value="<?= e($stato) ?>">

    <?php if (!empty($errors['_global'])): ?>
    <div class="qk-alert qk-alert-danger mb-3">
      <i class="fa-solid fa-triangle-exclamation"></i>
      <span><?= e($errors['_global']) ?></span>
    </div>
    <?php endif; ?>

    <?php if (!$canEdit && $isEdit): ?>
    <div class="qk-alert mb-3" style="background:var(--qk-bg-subtle);color:var(--qk-text-muted);border:1px solid var(--qk-border)">
      <i class="fa-solid fa-lock"></i>
      <span>
        Questa RFQ è in stato <strong><?= e($statoLabel[1]) ?></strong> e non può essere modificata.
        <?php if ($isInviata): ?>
          Usa <strong>Crea Nuova Versione</strong> per fare una revisione.
        <?php endif; ?>
        <?php if ($isChiusa && !empty($values['esito_chiusura'])): ?>
          <?= $values['esito_chiusura'] === 'offerta_accettata'
                ? '(Chiusa perché un\'offerta è stata accettata.)'
                : '(Chiusa manualmente, senza offerta accettata.)' ?>
        <?php endif; ?>
      </span>
    </div>
    <?php endif; ?>

    <!-- ── Card unica: Document Sheet ────────────────────────────────────────── -->
    <div class="qk-card mb-4 shadow-sm">
      <div class="qk-card-header qk-flex-row qk-justify-between">
        <span class="qk-card-title" style="margin:0">
          <i class="fa-solid fa-file-invoice me-2 text-primary"></i>
          <?= $isEdit ? 'Richiesta di Offerta ' . e($rfq['numero']) : 'Nuova Richiesta di Offerta' ?>
        </span>
        <?php if ($isEdit): ?>
          <span class="qk-text-muted" style="font-size:.82rem">versione <?= (int)($rfq['versione'] ?? 1) ?></span>
        <?php else: ?>
          <span class="qk-text-muted" style="font-size:.82rem"><?= e($anteprima_numero) ?></span>
        <?php endif; ?>
      </div>
      <div class="qk-card-body">

        <!-- Top Primary Fields Grid -->
        <div class="qk-form-grid mb-4">
          <!-- Colonna Sinistra -->
          <div>
            <div class="qk-form-row">
              <!-- Riferimento interno -->
              <div class="qk-form-group">
                <label class="qk-form-label">Riferimento interno</label>
                <input type="text" name="riferimento_rfq_interno" class="qk-form-control"
                       placeholder="Es. PROT 44.206"
                       value="<?= e($values['riferimento_rfq_interno'] ?? '') ?>"
                       <?= $readonly ?>>
              </div>

              <!-- Azienda Interna -->
              <div class="qk-form-group">
                <label class="qk-form-label">Azienda acquirente</label>
                <select name="azienda_interna_id" id="rfq-azienda-interna-id" class="qk-form-control" <?= $disabled ?>>
                  <option value="">— Seleziona azienda —</option>
                  <?php foreach ($aziende as $az): ?>
                    <option value="<?= $az['id'] ?>" <?= (string)($values['azienda_interna_id'] ?? '') === (string)$az['id'] ? 'selected' : '' ?>>
                      <?= e($az['ragione_sociale']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <!-- Fornitore (M2O autocomplete) -->
            <div class="qk-form-group">
              <label class="qk-form-label">
                Fornitore <span style="color:var(--qk-danger)">*</span>
              </label>
              <input type="hidden" name="fornitore_id" id="rfq-fornitore-id"
                     value="<?= e($values['fornitore_id']) ?>">
              <div class="qk-flex-row qk-gap-sm">
                <div class="qk-m2o-wrap" style="position:relative;flex:1">
                  <input type="text"
                         class="qk-form-control qk-m2o-input"
                         id="rfq-fornitore-label"
                         placeholder="Cerca fornitore…"
                         value="<?= e($rfq['fornitore_nome'] ?? ($values['fornitore_id'] ? '' : '')) ?>"
                         autocomplete="off"
                         <?= $disabled ?>>
                  <button type="button" class="qk-m2o-clear d-none" id="rfq-fornitore-clear" <?= $disabled ?>>&times;</button>
                  <div class="qk-m2o-dropdown shadow-sm" id="rfq-fornitore-dropdown"></div>
                </div>
                <?php if ($canEdit): ?>
                <button type="button" class="btn-qk btn-qk-secondary"
                        id="btn-quick-fornitore"
                        onclick="document.getElementById('modal-quick-fornitore').style.display='flex'"
                        title="Crea fornitore rapido">
                  <i class="fa-solid fa-plus"></i>
                </button>
                <?php endif; ?>
              </div>
              <?php if (!empty($errors['fornitore_id'])): ?>
                <span class="qk-form-error"><i class="fa-solid fa-circle-xmark"></i> <?= e($errors['fornitore_id']) ?></span>
              <?php endif; ?>
            </div>
          </div>

          <!-- Colonna Destra -->
          <div>
            <!-- Data emissione -->
            <div class="qk-form-group">
              <label class="qk-form-label">Data emissione</label>
              <input type="date" name="data_emissione" class="qk-form-control"
                     value="<?= e($values['data_emissione'] ?? date('Y-m-d')) ?>"
                     <?= $readonly ?>>
            </div>

            <!-- Scadenza risposta -->
            <div class="qk-form-group">
              <label class="qk-form-label">Scadenza risposta</label>
              <input type="date" name="data_scadenza" class="qk-form-control"
                     value="<?= e($values['data_scadenza'] ?? '') ?>"
                     <?= $readonly ?>>
          </div>
        </div>
      </div>

        <!-- Odoo-style Navigation Tabs -->
        <ul class="nav qk-nav-tabs" role="tablist">
          <li class="nav-item">
            <button class="nav-link active" id="tab-prodotti-btn" type="button" onclick="switchRfqTab('prodotti')">
              <i class="fa-solid fa-list-check me-1"></i> Prodotti
            </button>
          </li>
          <li class="nav-item">
            <button class="nav-link" id="tab-spedizione-contatti-btn" type="button" onclick="switchRfqTab('spedizione-contatti')">
              <i class="fa-solid fa-truck-fast me-1"></i> Spedizione & Contatti
            </button>
          </li>
          <li class="nav-item">
            <button class="nav-link" id="tab-condizioni-commerciali-btn" type="button" onclick="switchRfqTab('condizioni-commerciali')">
              <i class="fa-solid fa-file-invoice-dollar me-1"></i> Condizioni Commerciali
            </button>
          </li>
          <li class="nav-item">
            <button class="nav-link" id="tab-note-btn" type="button" onclick="switchRfqTab('note')">
              <i class="fa-solid fa-comment-dots me-1"></i> Note
            </button>
          </li>
        </ul>

        <!-- Tab Content Panes -->
        <div class="tab-content mt-3">

          <!-- Pane: Prodotti -->
          <div class="tab-pane active" id="pane-prodotti">
            <div class="qk-flex-row qk-justify-between" style="flex-wrap: wrap; gap:.5rem; margin-bottom:1rem;">
              <div class="qk-flex-row qk-gap-sm">
                <label class="qk-form-label" style="margin-bottom:0; font-size:.85rem; font-weight:600; color:var(--qk-text-muted)">Visualizza in:</label>
                <select id="rfq-uom-mode" name="uom_mode" class="qk-form-control" style="width:auto; padding:2px 8px; font-size:.85rem; height:auto;" <?= $disabled ?? '' ?>>
                  <option value="commercial" <?= ($values['uom_mode'] ?? 'commercial') === 'commercial' ? 'selected' : '' ?>>Unità commerciale</option>
                  <option value="stock" <?= ($values['uom_mode'] ?? 'commercial') === 'stock' ? 'selected' : '' ?>>Unità di stock</option>
                </select>
              </div>
              <div class="qk-flex-row qk-gap-sm">
                <?php if ($canEdit): ?>
                <button type="button" class="btn-qk btn-qk-secondary btn-qk-xs" id="btn-aggiungi-riga">
                  <i class="fa-solid fa-plus"></i> Aggiungi riga
                </button>
                <?php endif; ?>

                <div class="qk-dropdown-container" style="position:relative; display:inline-block; line-height:1;">
                  <button type="button" class="btn-qk btn-qk-secondary btn-qk-xs" id="btn-col-settings" title="Mostra/Nascondi colonne">
                    <i class="fa-solid fa-sliders"></i> Colonne
                  </button>
                  <div class="qk-dropdown-menu shadow-sm" id="col-settings-dropdown" style="display:none; position:absolute; right:0; top:120%; z-index:1000; background:var(--qk-card-bg); border:1px solid var(--qk-border); border-radius:var(--qk-radius); padding:.6rem .8rem; min-width:180px; text-align:left; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                    <div class="qk-flex-col qk-gap-sm">
                      <label class="qk-flex-row qk-gap-sm" style="font-size:.85rem;font-weight:500;cursor:pointer;color:var(--qk-text-primary);margin:0">
                        <input type="checkbox" id="chk-show-formato" style="transform:scale(1.15);" checked>
                        <span>Formato</span>
                      </label>
                      <label class="qk-flex-row qk-gap-sm" style="font-size:.85rem;font-weight:500;cursor:pointer;color:var(--qk-text-primary);margin:0">
                        <input type="checkbox" id="chk-show-prezzo" style="transform:scale(1.15);" checked>
                        <span>Prezzo Unitario</span>
                      </label>
                      <label class="qk-flex-row qk-gap-sm" style="font-size:.85rem;font-weight:500;cursor:pointer;color:var(--qk-text-primary);margin:0">
                        <input type="checkbox" id="chk-show-totale" style="transform:scale(1.15);" checked>
                        <span>Totale</span>
                      </label>
                      <label class="qk-flex-row qk-gap-sm" style="font-size:.85rem;font-weight:500;cursor:pointer;color:var(--qk-text-primary);margin:0">
                        <input type="checkbox" id="chk-show-data-consegna" style="transform:scale(1.15);">
                        <span>Arrivo previsto</span>
                      </label>
                      <?php if (!$isBozza): ?>
                      <label class="qk-flex-row qk-gap-sm" style="font-size:.85rem;font-weight:500;cursor:pointer;color:var(--qk-text-primary);margin:0">
                        <input type="checkbox" id="chk-show-rifiutato" style="transform:scale(1.15);">
                        <span>Stato Rifiuto</span>
                      </label>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <?php if (!empty($errors['righe'])): ?>
            <div class="qk-alert qk-alert-danger mb-3">
              <i class="fa-solid fa-circle-xmark"></i> <?= e($errors['righe']) ?>
            </div>
            <?php endif; ?>

            <div class="qk-table-wrap" style="min-height: 350px;">
              <table class="qk-table" id="tbl-righe">
                <thead>
                  <tr>
                    <th style="width:35px" class="col-drag no-resize"></th>
                    <th>Prodotto</th>
                    <th style="width:10%" class="col-formato">Formato</th>
                    <th style="width:8%">SKU Fornitore</th>
                    <th style="width:10%">Descrizione / Note</th>
                    <th style="width:6%">Qtà <span style="color:var(--qk-danger)">*</span></th>
                    <th style="width:5%">UdM</th>
                    <th style="width:9%" class="col-prezzo">Prezzo Unitario (<span class="currency-symbol">€</span>)</th>
                    <th style="width:5%">IVA %</th>
                    <th style="width:12%" class="col-data-consegna col-hidden">Arrivo previsto</th>
                    <th style="width:8%" class="col-rifiutato col-hidden text-center">Rifiutato</th>
                    <th style="width:10%" class="text-end col-totale">
                      <span>Totale (<span class="currency-symbol">€</span>)</span>
                    </th>
                    <?php if ($canEdit): ?><th style="width:35px" class="col-rimuovi no-resize"></th><?php endif; ?>
                  </tr>
                </thead>
                <tbody id="righe-body">
                  <?php if (empty($righe)): ?>
                  <tr id="riga-empty" style="cursor: pointer;" onclick="document.getElementById('btn-aggiungi-riga').click();">
                    <td colspan="12" class="qk-table-empty">
                      <i class="fa-solid fa-inbox"></i> Nessuna riga. Clicca qui o su "Aggiungi riga" per iniziare.
                    </td>
                  </tr>
                  <?php else: ?>
                  <?php foreach ($righe as $idx => $r): ?>
                  <?php include __DIR__ . '/_form_riga.php'; ?>
                  <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>

            <div class="qk-flex-row qk-justify-end p-3 border-top mt-3" style="background: transparent;">
              <div style="width: 280px; font-size: 0.95rem; line-height: 1.8;">
                <div class="qk-flex-row qk-justify-between">
                  <span class="qk-text-muted">Imponibile:</span>
                  <span class="fw-semibold"><span class="currency-symbol">€</span> <span id="totale-imponibile">0,00</span></span>
                </div>
                <div class="qk-flex-row qk-justify-between">
                  <span class="qk-text-muted">IVA:</span>
                  <span class="fw-semibold"><span class="currency-symbol">€</span> <span id="totale-iva">0,00</span></span>
                </div>
                <div class="qk-flex-row qk-justify-between border-top pt-1 mt-1" style="font-size: 1.1rem;">
                  <span class="fw-bold">Totale ivato:</span>
                  <span class="fw-bold text-primary"><span class="currency-symbol">€</span> <span id="totale-ivato">0,00</span></span>
                </div>
              </div>
            </div>
          </div>

          <!-- Pane: Spedizione & Contatti -->
          <div class="tab-pane d-none" id="pane-spedizione-contatti">
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:1.5rem;">
              <!-- Colonna 1: Spedizione -->
              <div>
                <h4 style="font-size:.9rem; text-transform:uppercase; letter-spacing:.05em; color:var(--qk-text-muted); margin-bottom:1rem; border-bottom:1px solid var(--qk-border); padding-bottom:.5rem;">Indirizzo di Consegna (Nostra Sede / Magazzino)</h4>
                <div class="qk-form-group">
                  <label class="qk-form-label">Seleziona sede di consegna</label>
                  <select name="indirizzo_consegna_id" id="rfq-indirizzo-consegna-id" class="qk-form-control" data-selected-id="<?= e($values['indirizzo_consegna_id'] ?? '') ?>" <?= $disabled ?>>
                    <option value="">Caricamento indirizzi...</option>
                  </select>
                </div>

                <!-- Dettagli Indirizzo di Consegna (Snapshot/Override) -->
                <div id="sped-fields-box" class="border rounded p-3 mb-3" style="background: var(--qk-bg-subtle); border-color: var(--qk-border) !important;">
                  <div class="qk-flex-row qk-justify-between mb-3">
                    <h6 class="mb-0 fw-bold" style="font-size: 0.85rem;">
                      <i class="fa-solid fa-truck-ramp-box me-1"></i>Dettagli Consegna
                      <span id="sped-state-badge" style="font-size: 0.72rem; vertical-align: middle;"></span>
                    </h6>
                    <button type="button" class="btn-qk btn-qk-xs btn-qk-ghost text-danger" id="btn-cancel-edit-sped" title="Annulla modifiche / Ripristina valori anagrafica"><i class="fa-solid fa-rotate-left me-1"></i>Ripristina</button>
                  </div>

                  <div class="qk-form-group mb-2">
                    <label class="qk-form-label">Presso (c/o)</label>
                    <input type="text" name="consegna_presso" id="consegna_presso" class="qk-form-control" value="<?= e($values['consegna_presso'] ?? '') ?>" placeholder="Es. Magazzino logistica, Cantiere B">
                  </div>

                  <div class="qk-form-row mb-2">
                    <div class="qk-form-group" style="flex:3">
                      <label class="qk-form-label">Via/Piazza</label>
                      <input type="text" name="consegna_via" id="consegna_via" class="qk-form-control" value="<?= e($values['consegna_via'] ?? '') ?>">
                    </div>
                    <div class="qk-form-group" style="flex:1">
                      <label class="qk-form-label">Civico</label>
                      <input type="text" name="consegna_civico" id="consegna_civico" class="qk-form-control" value="<?= e($values['consegna_civico'] ?? '') ?>">
                    </div>
                  </div>

                  <div class="qk-form-row mb-2">
                    <div class="qk-form-group" style="flex:2">
                      <label class="qk-form-label">CAP</label>
                      <input type="text" name="consegna_cap" id="consegna_cap" class="qk-form-control" value="<?= e($values['consegna_cap'] ?? '') ?>">
                    </div>
                    <div class="qk-form-group" style="flex:4">
                      <label class="qk-form-label">Città</label>
                      <input type="text" name="consegna_citta" id="consegna_citta" class="qk-form-control" value="<?= e($values['consegna_citta'] ?? '') ?>">
                    </div>
                  </div>

                  <div class="qk-form-row mb-2">
                    <div class="qk-form-group">
                      <label class="qk-form-label">Provincia</label>
                      <input type="text" name="consegna_provincia" id="consegna_provincia" class="qk-form-control" maxlength="2" placeholder="MI" value="<?= e($values['consegna_provincia'] ?? '') ?>">
                    </div>
                    <div class="qk-form-group">
                      <label class="qk-form-label">Nazione</label>
                      <input type="text" name="consegna_nazione" id="consegna_nazione" class="qk-form-control" maxlength="2" placeholder="IT" value="<?= e($values['consegna_nazione'] ?? 'IT') ?>">
                    </div>
                  </div>

                  <div class="qk-form-row mb-2">
                    <div class="qk-form-group">
                      <label class="qk-form-label">Referente Consegna</label>
                      <input type="text" name="consegna_referente" id="consegna_referente" class="qk-form-control" value="<?= e($values['consegna_referente'] ?? '') ?>" placeholder="Persona sul posto">
                    </div>
                    <div class="qk-form-group">
                      <label class="qk-form-label">Tel. Consegna</label>
                      <input type="text" name="consegna_telefono" id="consegna_telefono" class="qk-form-control" value="<?= e($values['consegna_telefono'] ?? '') ?>">
                    </div>
                  </div>

                  <div class="qk-form-group mb-2">
                    <label class="qk-form-label">Note Consegna</label>
                    <input type="text" name="consegna_note" id="consegna_note" class="qk-form-control" value="<?= e($values['consegna_note'] ?? '') ?>" placeholder="Es. Consegnare al mattino">
                  </div>
                </div>
              </div>

              <!-- Colonna 2: Referente & Recapiti -->
              <div>
                <h4 style="font-size:.9rem; text-transform:uppercase; letter-spacing:.05em; color:var(--qk-text-muted); margin-bottom:1rem; border-bottom:1px solid var(--qk-border); padding-bottom:.5rem;">Referente Fornitore (Commerciale)</h4>
                <div class="qk-form-group">
                  <label class="qk-form-label">Alla cortese attenzione (Referente Fornitore)</label>
                  <select name="contatto_id" id="rfq-contatto-id" class="qk-form-control" data-selected-id="<?= e($values['contatto_id'] ?? '') ?>" <?= $disabled ?>>
                    <option value="">Seleziona fornitore prima...</option>
                  </select>
                  
                  <input type="hidden" id="rfq-fornitore-telefono" value="<?= e($values['fornitore_telefono'] ?? '') ?>">
                  <input type="hidden" id="rfq-fornitore-email" value="<?= e($values['fornitore_email'] ?? '') ?>">
                </div>

                <!-- Dettagli Referente sul Documento -->
                <div id="cont-fields-box" class="border rounded p-3 mb-3" style="background: var(--qk-bg-subtle); border-color: var(--qk-border) !important;">
                  <div class="qk-flex-row qk-justify-between mb-3">
                    <h6 class="mb-0 fw-bold" style="font-size: 0.85rem;">
                      <i class="fa-solid fa-id-card-clip me-1"></i>Dettagli referente fornitore
                      <span id="cont-state-badge" style="font-size: 0.72rem; vertical-align: middle;"></span>
                    </h6>
                    <button type="button" class="btn-qk btn-qk-xs btn-qk-ghost text-danger" id="btn-cancel-edit-cont" title="Annulla modifiche / Ripristina valori anagrafica"><i class="fa-solid fa-rotate-left me-1"></i>Ripristina</button>
                  </div>
                  
                  <div class="qk-form-group mb-2">
                    <label class="qk-form-label">Alla c.a. di (Nome Referente)</label>
                    <input type="text" name="contatto_nome" id="rfq-contatto-nome" class="qk-form-control" value="<?= e($values['contatto_nome'] ?? '') ?>" placeholder="Es. Ing. Mario Rossi">
                  </div>

                  <div class="qk-form-group mb-2">
                    <label class="qk-form-label">Telefono di contatto</label>
                    <input type="text" name="contatto_telefono" id="rfq-contatto-telefono" class="qk-form-control" placeholder="Es. +39 02 123456" value="<?= e($values['contatto_telefono'] ?? '') ?>">
                  </div>

                  <div class="qk-form-group mb-2">
                    <label class="qk-form-label">Email di contatto</label>
                    <input type="email" name="contatto_email" id="rfq-contatto-email" class="qk-form-control" placeholder="Es. mario.rossi@fornitore.com" value="<?= e($values['contatto_email'] ?? '') ?>">
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Pane: Condizioni Commerciali -->
          <div class="tab-pane d-none" id="pane-condizioni-commerciali">
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:1.5rem;">
              <!-- Colonna 1: Incoterms & Trasporto -->
              <div>
                <h4 style="font-size:.9rem; text-transform:uppercase; letter-spacing:.05em; color:var(--qk-text-muted); margin-bottom:1rem; border-bottom:1px solid var(--qk-border); padding-bottom:.5rem;">Resa & Trasporto</h4>
                <div class="qk-form-group">
                  <label class="qk-form-label">Termine di resa (Incoterms)</label>
                  <select name="incoterm_codice" id="rfq-incoterm-codice" class="qk-form-control" <?= $disabled ?>>
                    <option value="">Nessuno...</option>
                    <?php foreach ($incoterms as $inc): ?>
                      <option value="<?= e($inc['codice']) ?>" <?= ($values['incoterm_codice'] ?? '') === $inc['codice'] ? 'selected' : '' ?>>
                        [<?= e($inc['codice']) ?>] <?= e($inc['descrizione']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="qk-form-group">
                  <label class="qk-form-label">Ubicazione termine di resa</label>
                  <input type="text" name="incoterm_luogo" class="qk-form-control"
                         placeholder="Es. Porto di Genova, Magazzino Milano"
                         value="<?= e($values['incoterm_luogo'] ?? '') ?>"
                         <?= $readonly ?>>
                </div>
                <div class="qk-form-group">
                  <label class="qk-form-label">Resa Porto (Trasporto)</label>
                  <select name="resa_porto" id="rfq-resa-porto" class="qk-form-control" <?= $disabled ?>>
                    <option value="">Seleziona resa porto...</option>
                    <option value="porto_franco" <?= ($values['resa_porto'] ?? '') === 'porto_franco' ? 'selected' : '' ?>>Porto Franco (A carico venditore)</option>
                    <option value="porto_assegnato" <?= ($values['resa_porto'] ?? '') === 'porto_assegnato' ? 'selected' : '' ?>>Porto Assegnato (A carico acquirente)</option>
                  </select>
                </div>
              </div>

              <!-- Colonna 2: Listino & Riferimenti -->
              <div>
                <h4 style="font-size:.9rem; text-transform:uppercase; letter-spacing:.05em; color:var(--qk-text-muted); margin-bottom:1rem; border-bottom:1px solid var(--qk-border); padding-bottom:.5rem;">Commerciali & Listini</h4>
                <div class="qk-form-group">
                  <label class="qk-form-label">Listino di Acquisto di riferimento</label>
                  <select name="listino_acquisto_id" id="rfq-listino-acquisto-id" class="qk-form-control" data-selected-id="<?= e($values['listino_acquisto_id'] ?? '') ?>" <?= $disabled ?>>
                    <option value="">Nessun listino specifico...</option>
                  </select>
                </div>
                <div class="qk-form-group">
                  <label class="qk-form-label">Valuta</label>
                  <select name="valuta" id="rfq-valuta" class="qk-form-control" <?= $disabled ?>>
                    <option value="EUR" <?= ($values['valuta'] ?? 'EUR') === 'EUR' ? 'selected' : '' ?>>EUR (€)</option>
                    <option value="USD" <?= ($values['valuta'] ?? '') === 'USD' ? 'selected' : '' ?>>USD ($)</option>
                    <option value="GBP" <?= ($values['valuta'] ?? '') === 'GBP' ? 'selected' : '' ?>>GBP (£)</option>
                  </select>
                </div>
              </div>

              <!-- Colonna 3: Pagamento -->
              <div>
                <h4 style="font-size:.9rem; text-transform:uppercase; letter-spacing:.05em; color:var(--qk-text-muted); margin-bottom:1rem; border-bottom:1px solid var(--qk-border); padding-bottom:.5rem;">Pagamento</h4>
                <div class="qk-form-group">
                  <label class="qk-form-label">Metodo di Pagamento</label>
                  <select name="metodo_pagamento_id" id="rfq-metodo-pagamento-id" class="qk-form-control" <?= $disabled ?>>
                    <option value="">Seleziona metodo...</option>
                    <?php foreach ($metodi_pagamento as $mp): ?>
                      <option value="<?= $mp['id'] ?>" <?= (string)($values['metodo_pagamento_id'] ?? '') === (string)$mp['id'] ? 'selected' : '' ?>>
                        <?= e($mp['descrizione']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="qk-form-group">
                  <label class="qk-form-label">Condizioni di pagamento (Dettagli)</label>
                  <input type="text" name="condizioni_pagamento" id="rfq-condizioni-pagamento" class="qk-form-control"
                         placeholder="Es. 30 giorni fine mese"
                         value="<?= e($values['condizioni_pagamento'] ?? '') ?>"
                         <?= $readonly ?>>
                </div>
              </div>
            </div>
          </div>

          <!-- Pane: Note -->
          <div class="tab-pane d-none" id="pane-note">
            <div class="qk-form-grid">
              <div class="qk-form-group">
                <label class="qk-form-label">
                  Note per il fornitore
                  <span class="qk-text-muted" style="font-size:.8rem">— stampate nel PDF</span>
                </label>
                <textarea name="note_fornitore" class="qk-form-control" rows="4"
                          placeholder="Es. Si prega di indicare i tempi di consegna per ogni articolo."
                          <?= $readonly ?>><?= e($values['note_fornitore'] ?? '') ?></textarea>
              </div>
              <div class="qk-form-group">
                <label class="qk-form-label">
                  Note interne
                  <span class="qk-text-muted" style="font-size:.8rem">— non stampate</span>
                </label>
                <textarea name="note_interne" class="qk-form-control" rows="4"
                          placeholder="Note visibili solo internamente…"
                          <?= $readonly ?>><?= e($values['note_interne'] ?? '') ?></textarea>
              </div>
            </div>
          </div>
        </div>

      </div><!-- /qk-card-body -->
    </div><!-- /qk-card (document sheet) -->




</form><!-- /form-rfq -->


<!-- ═══════════════════════════════════════════════════════════════════════
     PANNELLO: Offerte ricevute (solo in edit)
════════════════════════════════════════════════════════════════════════ -->
<?php if ($isEdit && !empty($offerte)): ?>
<div class="qk-card mb-4">
  <div class="qk-card-header">
    <span class="qk-card-title">
      <i class="fa-solid fa-file-invoice-dollar me-2"></i>Offerte ricevute
    </span>
    <?php if ($isInviata): ?>
    <a href="<?= url('purchases/quotes/create') . '?rfq_id=' . $id ?>"
       class="btn-qk btn-qk-success btn-qk-xs">
      <i class="fa-solid fa-plus"></i> Nuova offerta
    </a>
    <?php endif; ?>
  </div>
  <div class="qk-table-wrap">
    <table class="qk-table">
      <thead>
        <tr>
          <th>Numero</th>
          <th>Fornitore</th>
          <th>Stato</th>
          <th>Data</th>
          <th>Totale</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($offerte as $off): ?>
        <?php
          $offStato = $off['stato'] ?? '';
          $offBadge = [
            'in_valutazione' => ['pending',   'In valutazione'],
            'accettata'      => ['confirmed', 'Accettata'],
            'rifiutata'      => ['draft',     'Rifiutata'],
          ][$offStato] ?? ['draft', $offStato];
        ?>
        <tr>
          <td>
            <a href="<?= url('purchases/quotes/edit') . '?id=' . $off['id'] ?>" class="qk-link-strong">
              <?= e($off['numero']) ?>
            </a>
          </td>
          <td><?= e($off['fornitore_nome'] ?? '—') ?></td>
          <td><span class="qk-badge <?= $offBadge[0] ?>"><?= e($offBadge[1]) ?></span></td>
          <td><?= e($off['data_offerta'] ?? '—') ?></td>
          <td class="qk-text-right">
            <?= $off['totale'] !== null
                ? '€ ' . number_format((float)$off['totale'], 2, ',', '.')
                : '—' ?>
          </td>
          <td class="qk-table-actions">
            <a href="<?= url('purchases/quotes/edit') . '?id=' . $off['id'] ?>"
               class="btn-qk btn-qk-xs btn-qk-secondary">
              <i class="fa-solid fa-pencil"></i>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php elseif ($isEdit && $isInviata): ?>
<div class="qk-card mb-4">
  <div class="qk-card-header">
    <span class="qk-card-title">
      <i class="fa-solid fa-file-invoice-dollar me-2"></i>Offerte ricevute
    </span>
    <a href="<?= url('purchases/quotes/create') . '?rfq_id=' . $id ?>"
       class="btn-qk btn-qk-success btn-qk-xs">
      <i class="fa-solid fa-plus"></i> Registra offerta
    </a>
  </div>
  <div style="padding:1.25rem;text-align:center;color:var(--qk-text-muted)">
    <i class="fa-solid fa-inbox" style="font-size:1.4rem;display:block;margin-bottom:.5rem"></i>
    Nessuna offerta ancora registrata per questa RFQ.
  </div>
</div>
<?php endif; ?>


<!-- ═══════════════════════════════════════════════════════════════════════
     PANNELLO: Cronologia versioni (solo se ci sono più versioni)
════════════════════════════════════════════════════════════════════════ -->
<?php if ($isEdit && count($versioni) > 1): ?>
<div class="qk-card mb-4">
  <div class="qk-card-header">
    <span class="qk-card-title">
      <i class="fa-solid fa-code-branch me-2"></i>Versioni
    </span>
  </div>
  <div class="qk-table-wrap">
    <table class="qk-table">
      <thead>
        <tr><th>Numero</th><th>Ver.</th><th>Stato</th><th>Data</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($versioni as $ver):
          $verBadge = [
            'bozza'       => ['draft',     'Bozza'],
            'inviata'     => ['confirmed', 'Inviata'],
            'revisionata' => ['pending',   'Revisionata'],
            'chiusa'      => ['delivered', 'Chiusa'],
          ][$ver['stato']] ?? ['draft', $ver['stato']];
          $isCurrent = ($ver['id'] == $id);
        ?>
        <tr <?= $isCurrent ? 'style="font-weight:600"' : '' ?>>
          <td><?= e($ver['numero']) ?> <?= $isCurrent ? '<span class="qk-text-muted" style="font-size:.78rem">(corrente)</span>' : '' ?></td>
          <td>v<?= (int)$ver['versione'] ?></td>
          <td><span class="qk-badge <?= $verBadge[0] ?>"><?= e($verBadge[1]) ?></span></td>
          <td><?= e(substr($ver['created_at'], 0, 10)) ?></td>
          <td class="qk-table-actions">
            <?php if (!$isCurrent): ?>
            <a href="<?= url('purchases/rfq/edit') . '?id=' . $ver['id'] ?>"
               class="btn-qk btn-qk-xs btn-qk-secondary">Apri</a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>


<!-- ═══════════════════════════════════════════════════════════════════════
     MODALE: Creazione fornitore rapida
════════════════════════════════════════════════════════════════════════ -->
<?php require APP_ROOT_DIR . '/app/views/components/modal_quick_fornitore.php'; ?>



<!-- ═══════════════════════════════════════════════════════════════════════
     MODALE: "Stampa & Segna come Inviata"
     Aperta dal pulsante "Invia al Fornitore".
     Workflow offline: stampa il PDF → lo invii manualmente al fornitore
     → qui confermi l'invio per aggiornare lo stato a "inviata".
════════════════════════════════════════════════════════════════════════ -->
<?php if ($isEdit && $isBozza): ?>
<div id="modal-invia-rfq"
     style="display:none;position:fixed;inset:0;z-index:1050;
            background:var(--qk-overlay);align-items:center;justify-content:center">
  <div style="background:var(--qk-card-bg);border:1px solid var(--qk-border);
              border-radius:var(--qk-radius-lg);padding:1.5rem;width:100%;max-width:480px">
    <div class="qk-flex-row qk-justify-between" style="margin-bottom:1rem">
      <strong><i class="fa-solid fa-paper-plane me-2"></i>Invia al Fornitore (Offline Workflow)</strong>
      <button type="button" onclick="document.getElementById('modal-invia-rfq').style.display='none'"
              style="background:none;border:none;cursor:pointer;font-size:1.2rem;color:var(--qk-text-muted)">&times;</button>
    </div>
    
    <div style="font-size:.9rem;color:var(--qk-text-primary);line-height:1.6;margin-bottom:1rem">
      Il file <strong><?= e($rfq['numero']) ?>.pdf</strong> è pronto per la stampa o l'invio manuale.
    </div>

    <!-- Tasti di supporto invio offline -->
    <div class="qk-flex-row qk-gap-sm mb-3">
      <a href="<?= url('purchases/rfq/pdf') . '?id=' . $id ?>" target="_blank"
         class="btn-qk btn-qk-secondary" style="flex:1;text-align:center"
         onclick="window.open(this.href); return false;">
        <i class="fa-solid fa-file-pdf"></i> Apri PDF
      </a>
      <button type="button" class="btn-qk btn-qk-secondary" style="flex:1" id="btn-copia-email">
        <i class="fa-solid fa-copy"></i> Copia Testo Email
      </button>
    </div>

    <div style="border-top:1px solid var(--qk-border);padding-top:1rem">
      <p style="font-size:.9rem;margin-bottom:1.25rem">
        Vuoi contrassegnare questo preventivo come <strong>INVIATO</strong> al fornitore?<br>
        <span class="qk-text-muted" style="font-size:.82rem">(Nota: lo stato passerà a "Inviata" e le righe verranno bloccate).</span>
      </p>
      
      <div class="qk-flex-row qk-justify-end qk-gap-sm" style="margin-top:1rem">
        <button type="button" class="btn-qk btn-qk-secondary" onclick="document.getElementById('modal-invia-rfq').style.display='none'">Annulla</button>
        <form method="post" action="<?= url('purchases/rfq/action') ?>" style="margin:0">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $id ?>">
          <input type="hidden" name="azione" value="invia">
          <button type="submit" class="btn-qk btn-qk-primary">
            <i class="fa-solid fa-check"></i> Segna come Inviato
          </button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>


<!-- ═══════════════════════════════════════════════════════════════════════
     TEMPLATE RIGA (hidden, clonato da JS)
════════════════════════════════════════════════════════════════════════ -->
<?php if ($canEdit): ?>
<template id="tpl-riga">
  <tr class="rfq-riga" data-idx="__IDX__" draggable="true">
    <td class="col-drag">
      <i class="fa-solid fa-grip-vertical" title="Trascina per riordinare"></i>
    </td>
    <td>
      <input type="hidden" name="riga_prodotto_id[]" class="r-prodotto-id" value="">
      <div class="qk-m2o-wrap">
        <input type="text" class="qk-form-control qk-m2o-input r-prodotto-label"
               placeholder="Cerca prodotto…" autocomplete="off">
        <button type="button" class="qk-m2o-clear d-none r-prodotto-clear">&times;</button>
        <div class="qk-m2o-dropdown shadow-sm r-prodotto-dropdown"></div>
      </div>
    </td>
    <td class="col-formato">
      <span class="r-formato qk-text-muted" style="font-size:.85rem">—</span>
    </td>
    <td>
      <input type="text" name="riga_sku_fornitore[]" class="qk-form-control r-sku" placeholder="SKU">
    </td>
    <td>
      <input type="text" name="riga_descrizione[]" class="qk-form-control r-desc" placeholder="Note riga…">
    </td>
    <td>
      <input type="number" name="riga_quantita[]" class="qk-form-control r-qty"
             step="0.001" min="0.001" value="1" required>
    </td>
    <td>
      <input type="hidden" name="riga_unita_misura_id[]" class="r-uom-id" value="">
      <span class="r-uom-label qk-text-muted" style="font-size:.85rem">—</span>
    </td>
    <td class="col-prezzo">
      <input type="number" name="riga_prezzo_indicativo[]" class="qk-form-control r-prezzo"
             step="0.0001" min="0" placeholder="0,0000">
    </td>
    <td>
      <input type="number" name="riga_iva_perc[]" class="qk-form-control r-iva"
             step="0.01" min="0" max="100" placeholder="22" value="22">
    </td>
    <td class="col-data-consegna col-hidden">
      <input type="date" name="riga_data_consegna_richiesta[]" class="qk-form-control r-data-consegna" value="">
    </td>
    <td class="col-rifiutato col-hidden text-center">
      <input type="hidden" name="riga_e_rifiutato[]" class="r-e-rifiutato" value="0">
      <input type="checkbox" class="r-e-rifiutato-check" style="transform: scale(1.15);">
    </td>
    <td class="text-end py-3 px-3 col-totale">
      <span class="r-totale-riga fw-semibold" style="font-size:.9rem">0,00</span>
    </td>
    <td class="col-rimuovi">
      <button type="button" class="btn-qk btn-qk-xs btn-qk-ghost r-rimuovi" title="Rimuovi riga">
        <i class="fa-solid fa-trash-can text-danger"></i>
      </button>
    </td>
  </tr>
</template>
<?php endif; ?>


<!-- ═══════════════════════════════════════════════════════════════════════
     SCRIPT
════════════════════════════════════════════════════════════════════════ -->
<script>
window.QK_RFQ_CONFIG = {
  apiFornitori: <?= json_encode(url('purchases/rfq/api/fornitori')) ?>,
  apiProdotti: <?= json_encode(url('purchases/rfq/api/prodotti')) ?>,
  apiPrezzo: <?= json_encode(url('purchases/rfq/api/ottieni-prezzo')) ?>,
  apiFornitoreCrea: <?= json_encode(url('purchases/rfq/api/crea-fornitore')) ?>,
  apiAziendaIndirizzi: <?= json_encode(url('purchases/rfq/api/azienda-indirizzi')) ?>,
  apiFornitoreDettagli: <?= json_encode(url('purchases/rfq/api/fornitore-dettagli')) ?>,
  canEdit: <?= $canEdit ? 'true' : 'false' ?>,
  fornitoreId: <?= json_encode($values['fornitore_id']) ?>,
  rfqNumero: <?= json_encode($rfq['numero'] ?? '') ?>,
  fornitoreNome: <?= json_encode($rfq['fornitore_nome'] ?? '') ?>,
  rfqStato: <?= json_encode($values['stato'] ?? 'bozza') ?>
};
</script>
<script src="<?= url('assets/js/rfq-form.js') ?>"></script>

