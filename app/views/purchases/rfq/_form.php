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

<?php if (!empty($_SESSION['flash_success'])): ?>
<div class="qk-alert qk-alert-success mb-3" data-auto-dismiss="4000">
  <i class="fa-solid fa-circle-check"></i>
  <span><?= e($_SESSION['flash_success']) ?></span>
  <button class="qk-alert-close"><i class="fa-solid fa-xmark"></i></button>
</div>
<?php unset($_SESSION['flash_success']); endif; ?>

<?php if (!empty($_SESSION['flash_error'])): ?>
<div class="qk-alert qk-alert-danger mb-3" data-auto-dismiss="6000">
  <i class="fa-solid fa-circle-xmark"></i>
  <span><?= e($_SESSION['flash_error']) ?></span>
  <button class="qk-alert-close"><i class="fa-solid fa-xmark"></i></button>
</div>
<?php unset($_SESSION['flash_error']); endif; ?>

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
<div class="qk-guidance-box mb-4 p-3 bg-light border-start border-primary border-4 rounded d-flex align-items-center gap-3">
  <div class="fs-4 text-primary"><i class="fa-solid fa-circle-info"></i></div>
  <div>
    <h6 class="mb-1 fw-bold text-primary">Cosa fare adesso?</h6>
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

<!-- Barra Azioni e Stato (Odoo style) -->
  <div class="d-flex justify-content-between align-items-center mb-4 p-3 border rounded shadow-xs" style="background: var(--qk-bg-subtle); border-color: var(--qk-border) !important; flex-wrap: wrap; gap: .75rem;">
    <!-- Bottoni Azione -->
    <div style="display:flex; gap:.5rem; align-items:center; flex-wrap:wrap;">
      <?php if ($isEdit): ?>
        <?php if ($isBozza): ?>
          <button type="button" class="btn-qk btn-qk-primary" id="btn-invia-rfq" title="Invia la richiesta di preventivo al fornitore (genera il PDF e segna il documento come Inviato)">
            <i class="fa-solid fa-paper-plane"></i> Invia al Fornitore
          </button>
          <form method="post" action="<?= url('purchases/rfq/delete') ?>" class="d-inline"
                onsubmit="return confirm('Eliminare definitivamente la RFQ <?= e($rfq['numero']) ?>?')">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <button type="submit" class="btn-qk btn-qk-ghost text-danger" title="Elimina definitivamente questa richiesta in bozza">
              <i class="fa-solid fa-trash"></i>
            </button>
          </form>
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
      <?php endif; ?>
    </div>

    <!-- Pipeline visivo stati -->
    <ul class="qk-status-path d-none d-sm-flex">
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
      </span>
    </div>
    <?php endif; ?>

    <!-- ── Card unica: Document Sheet ────────────────────────────────────────── -->
    <div class="qk-card mb-4 shadow-sm">
      <div class="qk-card-header d-flex justify-content-between align-items-center">
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
              <div style="display:flex;gap:.5rem;align-items:flex-start">
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
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:.5rem; margin-bottom:1rem;">
              <div style="display:flex; align-items:center; gap:.5rem;">
                <label class="qk-form-label" style="margin-bottom:0; font-size:.85rem; font-weight:600; color:var(--qk-text-muted)">Visualizza in:</label>
                <select id="rfq-uom-mode" name="uom_mode" class="qk-form-control" style="width:auto; padding:2px 8px; font-size:.85rem; height:auto;" <?= $disabled ?? '' ?>>
                  <option value="commercial" <?= ($values['uom_mode'] ?? 'commercial') === 'commercial' ? 'selected' : '' ?>>Unità commerciale</option>
                  <option value="stock" <?= ($values['uom_mode'] ?? 'commercial') === 'stock' ? 'selected' : '' ?>>Unità di stock</option>
                </select>
              </div>
              <div style="display:flex; align-items:center; gap:.5rem;">
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
                    <div style="display:flex; flex-direction:column; gap:.5rem;">
                      <label style="display:flex; align-items:center; gap:.6rem; font-size:.85rem; font-weight:500; cursor:pointer; color:var(--qk-text-primary); margin:0;">
                        <input type="checkbox" id="chk-show-formato" style="transform:scale(1.15);" checked>
                        <span>Formato</span>
                      </label>
                      <label style="display:flex; align-items:center; gap:.6rem; font-size:.85rem; font-weight:500; cursor:pointer; color:var(--qk-text-primary); margin:0;">
                        <input type="checkbox" id="chk-show-prezzo" style="transform:scale(1.15);" checked>
                        <span>Prezzo indicativo</span>
                      </label>
                      <label style="display:flex; align-items:center; gap:.6rem; font-size:.85rem; font-weight:500; cursor:pointer; color:var(--qk-text-primary); margin:0;">
                        <input type="checkbox" id="chk-show-totale" style="transform:scale(1.15);" checked>
                        <span>Totale</span>
                      </label>
                      <label style="display:flex; align-items:center; gap:.6rem; font-size:.85rem; font-weight:500; cursor:pointer; color:var(--qk-text-primary); margin:0;">
                        <input type="checkbox" id="chk-show-data-consegna" style="transform:scale(1.15);">
                        <span>Arrivo previsto</span>
                      </label>
                      <?php if (!$isBozza): ?>
                      <label style="display:flex; align-items:center; gap:.6rem; font-size:.85rem; font-weight:500; cursor:pointer; color:var(--qk-text-primary); margin:0;">
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
                    <th style="width:3%" class="col-drag"></th>
                    <th style="width:20%">Prodotto</th>
                    <th style="width:10%" class="col-formato">Formato</th>
                    <th style="width:8%">SKU Fornitore</th>
                    <th style="width:10%">Descrizione / Note</th>
                    <th style="width:6%">Qtà <span style="color:var(--qk-danger)">*</span></th>
                    <th style="width:5%">UdM</th>
                    <th style="width:9%" class="col-prezzo">Prezzo indicativo (<span class="currency-symbol">€</span>)</th>
                    <th style="width:5%">IVA %</th>
                    <th style="width:12%" class="col-data-consegna col-hidden">Arrivo previsto</th>
                    <th style="width:8%" class="col-rifiutato col-hidden text-center">Rifiutato</th>
                    <th style="width:10%" class="text-end col-totale">
                      <span>Totale (<span class="currency-symbol">€</span>)</span>
                    </th>
                    <?php if ($canEdit): ?><th style="width:4%"></th><?php endif; ?>
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

            <div class="d-flex justify-content-end p-3 border-top mt-3" style="background: var(--qk-bg-subtle); border-radius: var(--qk-radius);">
              <div style="width: 280px; font-size: 0.95rem; line-height: 1.8;">
                <div class="d-flex justify-content-between">
                  <span class="qk-text-muted">Imponibile:</span>
                  <span class="fw-semibold"><span class="currency-symbol">€</span> <span id="totale-imponibile">0,00</span></span>
                </div>
                <div class="d-flex justify-content-between">
                  <span class="qk-text-muted">IVA:</span>
                  <span class="fw-semibold"><span class="currency-symbol">€</span> <span id="totale-iva">0,00</span></span>
                </div>
                <div class="d-flex justify-content-between border-top pt-1 mt-1" style="font-size: 1.1rem;">
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
                <h4 style="font-size:.9rem; text-transform:uppercase; letter-spacing:.05em; color:var(--qk-text-muted); margin-bottom:1rem; border-bottom:1px solid var(--qk-border); padding-bottom:.5rem;">Indirizzo di Consegna</h4>
                <div class="qk-form-group">
                  <label class="qk-form-label">Seleziona sede di consegna</label>
                  <select name="indirizzo_consegna_id" id="rfq-indirizzo-consegna-id" class="qk-form-control" data-selected-id="<?= e($values['indirizzo_consegna_id'] ?? '') ?>" <?= $disabled ?>>
                    <option value="">Caricamento indirizzi...</option>
                  </select>
                </div>

                <!-- Dettagli Indirizzo di Consegna (Snapshot/Override) -->
                <div id="sped-fields-box" class="border rounded p-3 mb-3" style="background: var(--qk-bg-subtle); border-color: var(--qk-border) !important;">
                  <div class="d-flex justify-content-between align-items-center mb-3">
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
                <h4 style="font-size:.9rem; text-transform:uppercase; letter-spacing:.05em; color:var(--qk-text-muted); margin-bottom:1rem; border-bottom:1px solid var(--qk-border); padding-bottom:.5rem;">Referente & Recapiti</h4>
                <div class="qk-form-group">
                  <label class="qk-form-label">Alla cortese attenzione (Referente)</label>
                  <select name="contatto_id" id="rfq-contatto-id" class="qk-form-control" data-selected-id="<?= e($values['contatto_id'] ?? '') ?>" <?= $disabled ?>>
                    <option value="">Seleziona fornitore prima...</option>
                  </select>
                  
                  <input type="hidden" id="rfq-fornitore-telefono" value="<?= e($values['fornitore_telefono'] ?? '') ?>">
                  <input type="hidden" id="rfq-fornitore-email" value="<?= e($values['fornitore_email'] ?? '') ?>">
                </div>

                <!-- Dettagli Referente sul Documento -->
                <div id="cont-fields-box" class="border rounded p-3 mb-3" style="background: var(--qk-bg-subtle); border-color: var(--qk-border) !important;">
                  <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0 fw-bold" style="font-size: 0.85rem;">
                      <i class="fa-solid fa-id-card-clip me-1"></i>Dettagli Stampati
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


  <!-- ── Pulsante salva (solo in bozza) ────────────────────────────────── -->
  <?php if ($canEdit): ?>
  <div style="display:flex;justify-content:flex-end;gap:.75rem;margin-bottom:2rem">
    <a href="<?= url('purchases/rfq') ?>" class="btn-qk btn-qk-ghost">Annulla</a>
    <button type="submit" class="btn-qk btn-qk-primary">
      <i class="fa-solid fa-floppy-disk"></i>
      <?= $isEdit ? 'Salva modifiche' : 'Crea RFQ' ?>
    </button>
  </div>
  <?php endif; ?>

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
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
      <strong><i class="fa-solid fa-paper-plane me-2"></i>Invia al Fornitore (Offline Workflow)</strong>
      <button type="button" onclick="document.getElementById('modal-invia-rfq').style.display='none'"
              style="background:none;border:none;cursor:pointer;font-size:1.2rem;color:var(--qk-text-muted)">&times;</button>
    </div>
    
    <div style="font-size:.9rem;color:var(--qk-text-primary);line-height:1.6;margin-bottom:1rem">
      Il file <strong><?= e($rfq['numero']) ?>.pdf</strong> è pronto per la stampa o l'invio manuale.
    </div>

    <!-- Tasti di supporto invio offline -->
    <div style="display:flex;gap:.5rem;margin-bottom:1.5rem">
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
      
      <div style="display:flex;justify-content:flex-end;gap:.5rem">
        <button type="button" class="btn-qk btn-qk-ghost" onclick="document.getElementById('modal-invia-rfq').style.display='none'">
          No, lascia in Bozza
        </button>
        <form method="post" action="<?= url('purchases/rfq/action') ?>" style="margin:0">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $id ?>">
          <input type="hidden" name="azione" value="invia">
          <button type="submit" class="btn-qk btn-qk-primary">
            <i class="fa-solid fa-circle-check"></i> Sì, segna come Inviata e Blocca
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
    <td>
      <button type="button" class="btn-qk btn-qk-xs btn-qk-ghost r-rimuovi" title="Rimuovi riga">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </td>
  </tr>
</template>
<?php endif; ?>


<!-- ═══════════════════════════════════════════════════════════════════════
     SCRIPT
════════════════════════════════════════════════════════════════════════ -->
<script>
document.addEventListener('DOMContentLoaded', function () {
  'use strict';

  /* ── Costanti ────────────────────────────────────────────────────────── */
  const API_FORNITORI = <?= json_encode(url('purchases/rfq/api/fornitori')) ?>;
  const API_PRODOTTI  = <?= json_encode(url('purchases/rfq/api/prodotti')) ?>;
  const API_PREZZO    = <?= json_encode(url('purchases/rfq/api/ottieni-prezzo')) ?>;
  const API_FORNITORE_CREA = <?= json_encode(url('purchases/rfq/api/crea-fornitore')) ?>;
  const CAN_EDIT      = <?= $canEdit ? 'true' : 'false' ?>;
  const CSRF_TOKEN    = document.querySelector('input[name=_csrf]')?.value ?? '';
  /* ── Stato globale ───────────────────────────────────────────────────── */
  let fornitoreSel = { id: '<?= e($values['fornitore_id']) ?>', nome: '' };
  let rigaIdx      = 0;  // contatore righe per data-idx univoci
  let draggedRow   = null;
  let currentUomMode = document.getElementById('rfq-uom-mode')?.value || 'commercial';

  function setRowUomMode(tr, targetMode, currentMode) {
    const qFactor = parseFloat(tr.dataset.quantitaContenuto) || 1;
    const qtyInput = tr.querySelector('.r-qty');
    const priceInput = tr.querySelector('.r-prezzo');
    const uomLabel = tr.querySelector('.r-uom-label');
    const uomIdInput = tr.querySelector('.r-uom-id');

    if (!qtyInput || !uomLabel || !uomIdInput) return;

    if (targetMode === currentMode) {
      if (uomLabel) {
        uomLabel.textContent = (targetMode === 'stock') 
          ? (tr.dataset.uomStock || '—') 
          : (tr.dataset.uomCommercial || '—');
      }
      if (uomIdInput) {
        uomIdInput.value = (targetMode === 'stock')
          ? (tr.dataset.uomStockId || '')
          : (tr.dataset.uomCommercialId || '');
      }
      return;
    }

    const { value: newQty, price: newPrice } = window.qkConvertUom({
      value: qtyInput.value,
      price: priceInput ? priceInput.value : null,
      qFactor,
      targetMode
    });

    if (qtyInput.value && newQty !== null) qtyInput.value = newQty;
    if (priceInput && priceInput.value && newPrice !== null) priceInput.value = newPrice;

    if (uomLabel) {
      uomLabel.textContent = (targetMode === 'stock') 
        ? (tr.dataset.uomStock || '—') 
        : (tr.dataset.uomCommercial || '—');
    }
    if (uomIdInput) {
      uomIdInput.value = (targetMode === 'stock')
        ? (tr.dataset.uomStockId || '')
        : (tr.dataset.uomCommercialId || '');
    }
  }
  /* ── Fetch fornitori ─────────────────────────────────────────────────── */
  async function fetchFornitori(q) {
    const res = await fetch(`${API_FORNITORI}?q=${encodeURIComponent(q)}`);
    const data = await res.json();
    return (data.fornitori ?? []).map(f => ({
      id: f.id,
      label: f.nome,
      codice: f.codice,
      ...f
    }));
  }

  /* ── Fetch prodotti (con prezzo fornitore se selezionato) ─────────────── */
  async function fetchProdotti(q) {
    const url = `${API_PRODOTTI}?q=${encodeURIComponent(q)}&fornitore_id=${fornitoreSel.id || 0}`;
    const res  = await fetch(url);
    const data = await res.json();
    return (data.prodotti ?? []).map(p => ({
      id:            p.id,
      label:         `${p.nome} (${p.sku_interno})`,
      nome:          p.nome,
      sku_interno:   p.sku_interno,
      sku_fornitore: p.sku_fornitore ?? '',
      uom_id:        p.unita_misura_id ?? '',
      uom:           p.uom ?? '',
      prezzo:        p.prezzo_unitario ?? '',
      iva_perc:      p.iva_perc ?? '22',
      uom_comm:      p.uom_comm || p.uom_comm_prod || p.uom || '—',
      uom_comm_id:   p.uom_comm_id || p.uom_comm_prod_id || p.unita_misura_id || '',
      uom_comm_prod: p.uom_comm_prod ?? '',
      uom_comm_prod_id: p.uom_comm_prod_id ?? '',
      formato:       p.formato ?? '',
    }));
  }

  /* ══════════════════════════════════════════════════════════════════════
     CAMPO FORNITORE (solo se editabile)
  ═══════════════════════════════════════════════════════════════════════ */
  if (CAN_EDIT) {
    const fInput    = document.getElementById('rfq-fornitore-label');
    const fHidden   = document.getElementById('rfq-fornitore-id');
    const fDropdown = document.getElementById('rfq-fornitore-dropdown');
    const fClear    = document.getElementById('rfq-fornitore-clear');

    if (fInput) {
      window.qkAutocomplete({
        input:       fInput,
        dropdown:    fDropdown,
        fetchFn:     fetchFornitori,
        onSelect:    (sel) => {
          if (sel) {
            fHidden.value = sel.id;
            fInput.value = sel.label || sel.nome || '';
            fClear?.classList.remove('d-none');
            fornitoreSel = { id: String(sel.id), nome: sel.label || sel.nome || '' };
          } else {
            fHidden.value = '';
            fInput.value = '';
            fClear?.classList.add('d-none');
            fornitoreSel = { id: '', nome: '' };
          }
          loadFornitoreDettagli();
        },
      });

      if (fClear) {
        fClear.addEventListener('click', () => {
          fHidden.value = '';
          fInput.value = '';
          fClear.classList.add('d-none');
          fDropdown.style.display = 'none';
          fornitoreSel = { id: '', nome: '' };
          loadFornitoreDettagli();
        });
      }

      // Se c'è già un valore, mostra il tasto X
      if (fHidden.value) fClear?.classList.remove('d-none');
    }
  }

  /* ══════════════════════════════════════════════════════════════════════
     RIGHE — aggiunta dinamica
  ═══════════════════════════════════════════════════════════════════════ */
  const tbody   = document.getElementById('righe-body');
  const tplRiga = document.getElementById('tpl-riga');

  function addRiga(prefill = {}) {
    // Rimuovi riga "vuoto" se presente
    const empty = document.getElementById('riga-empty');
    if (empty) empty.remove();

    const clone = tplRiga.content.cloneNode(true);
    const tr    = clone.querySelector('tr');
    const idx   = rigaIdx++;
    tr.dataset.idx = idx;

    // Popola dati se forniti (es. da pre-fill PHP → JS)
    if (prefill.prodotto_id) {
      tr.querySelector('.r-prodotto-id').value     = prefill.prodotto_id;
      tr.querySelector('.r-prodotto-label').value  = prefill.label ?? '';
      tr.querySelector('.r-sku').value             = prefill.sku_fornitore ?? '';
      tr.querySelector('.r-qty').value             = prefill.quantita_richiesta ?? '1';
      tr.querySelector('.r-prezzo').value          = prefill.prezzo_indicativo ?? '';
      tr.querySelector('.r-desc').value            = prefill.descrizione ?? '';
      tr.querySelector('.r-uom-id').value          = prefill.uom_id ?? '';
      tr.querySelector('.r-uom-label').textContent = prefill.uom ?? '—';
    }

    tbody.appendChild(clone);
    const newTr = tbody.querySelector(`tr[data-idx="${idx}"]`);
    initRiga(newTr);
    return newTr;
  }

  function initRiga(tr) {
    const prodInput    = tr.querySelector('.r-prodotto-label');
    const prodHidden   = tr.querySelector('.r-prodotto-id');
    const prodDropdown = tr.querySelector('.r-prodotto-dropdown');
    const prodClear    = tr.querySelector('.r-prodotto-clear');
    const uomId        = tr.querySelector('.r-uom-id');
    const uomLabel     = tr.querySelector('.r-uom-label');
    const skuInput     = tr.querySelector('.r-sku');
    const prezzoInput  = tr.querySelector('.r-prezzo');
    const ivaInput     = tr.querySelector('.r-iva');
    const rimuoviBtn   = tr.querySelector('.r-rimuovi');
    const qtyInput     = tr.querySelector('.r-qty');

    window.qkAutocomplete({
      input:       prodInput,
      dropdown:    prodDropdown,
      fetchFn:     fetchProdotti,
      onSelect:    (sel) => {
        if (!sel) {
          prodHidden.value    = '';
          prodInput.value     = '';
          prodClear?.classList.add('d-none');
          uomId.value        = '';
          uomLabel.textContent = '—';
          tr.dataset.uomStock = '—';
          tr.dataset.uomStockId = '';
          tr.dataset.uomCommercial = '—';
          tr.dataset.uomCommercialId = '';
          tr.dataset.quantitaContenuto = '1';
          const formatoSpan = tr.querySelector('.r-formato');
          if (formatoSpan) formatoSpan.textContent = '—';
          if (ivaInput) ivaInput.value = '22';
          recalcRowTotal(tr);
          return;
        }
        prodHidden.value = sel.id;
        prodInput.value = sel.label || sel.nome || '';
        prodClear?.classList.remove('d-none');
        tr.dataset.uomStock = sel.uom ?? '—';
        tr.dataset.uomStockId = sel.uom_id ?? '';
        tr.dataset.uomCommercial = sel.uom_comm || sel.uom_comm_prod || sel.uom || '—';
        tr.dataset.uomCommercialId = sel.uom_comm_id || sel.uom_comm_prod_id || sel.uom_id || '';
        tr.dataset.quantitaContenuto = sel.quantita_contenuto || '1';
        
        const formatoSpan = tr.querySelector('.r-formato');
        if (formatoSpan) formatoSpan.textContent = sel.formato || '—';
        
        if (sel.sku_fornitore) skuInput.value  = sel.sku_fornitore;
        if (sel.prezzo)        prezzoInput.value = sel.prezzo;
        if (sel.iva_perc && ivaInput) ivaInput.value = sel.iva_perc;

        // Converte e allinea le UoM in base al modo corrente
        const mode = document.getElementById('rfq-uom-mode')?.value || 'commercial';
        setRowUomMode(tr, mode, 'commercial');

        recalcRowTotal(tr);

        // Auto-crea una nuova riga vuota se si compila l'ultima
        const allRows = Array.from(tbody.querySelectorAll('tr.rfq-riga'));
        if (allRows[allRows.length - 1] === tr) {
          addRiga();
        }
      },
    });

    if (prodClear) {
      prodClear.addEventListener('click', () => {
        prodHidden.value = '';
        prodInput.value  = '';
        prodClear.classList.add('d-none');
        prodDropdown.style.display = 'none';
        uomId.value        = '';
        uomLabel.textContent = '—';
        tr.dataset.uomStock = '—';
        tr.dataset.uomStockId = '';
        tr.dataset.uomCommercial = '—';
        tr.dataset.uomCommercialId = '';
        tr.dataset.quantitaContenuto = '1';
        const formatoSpan = tr.querySelector('.r-formato');
        if (formatoSpan) formatoSpan.textContent = '—';
        if (ivaInput) ivaInput.value = '22';
        recalcRowTotal(tr);
      });
    }

    // Navigazione tra le righe tramite Invio
    tr.querySelectorAll('input').forEach(input => {
      input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          const nextTr = tr.nextElementSibling;
          if (nextTr && nextTr.classList.contains('rfq-riga')) {
            nextTr.querySelector('.r-prodotto-label')?.focus();
          } else {
            const newTr = addRiga();
            newTr.querySelector('.r-prodotto-label')?.focus();
          }
        }
      });
    });

    // Sync rejection checkbox to hidden field
    const rifiutatoCheck = tr.querySelector('.r-e-rifiutato-check');
    const rifiutatoHidden = tr.querySelector('.r-e-rifiutato');
    if (rifiutatoCheck && rifiutatoHidden) {
      rifiutatoCheck.addEventListener('change', () => {
        rifiutatoHidden.value = rifiutatoCheck.checked ? '1' : '0';
      });
    }

    // Aggiorna il pulsante X se già valorizzato
    if (CAN_EDIT && prodHidden.value) prodClear?.classList.remove('d-none');

    // Listener per i calcoli in tempo reale
    if (qtyInput)    qtyInput.addEventListener('input', () => recalcRowTotal(tr));
    if (prezzoInput) prezzoInput.addEventListener('input', () => recalcRowTotal(tr));
    if (ivaInput)    ivaInput.addEventListener('input', () => recalcRowTotal(tr));

    if (rimuoviBtn) {
      rimuoviBtn.addEventListener('click', () => {
        tr.remove();
        recalcTotals();
        if (!tbody.querySelector('tr.rfq-riga')) {
           emptyRow.id    = 'riga-empty';
           emptyRow.style.cursor = 'pointer';
           emptyRow.onclick = () => document.getElementById('btn-aggiungi-riga').click();
           emptyRow.innerHTML = `<td colspan="12" class="qk-table-empty">
             <i class="fa-solid fa-inbox"></i> Nessuna riga. Clicca qui o su "Aggiungi riga" per iniziare.
           </td>`;
          tbody.appendChild(emptyRow);
        }
      });
    }

    // Drag and Drop listeners
    if (CAN_EDIT) {
      tr.addEventListener('dragstart', (e) => {
        draggedRow = tr;
        tr.classList.add('is-dragging');
        e.dataTransfer.effectAllowed = 'move';
      });

      tr.addEventListener('dragend', () => {
        tr.classList.remove('is-dragging');
        draggedRow = null;
        document.querySelectorAll('.rfq-riga').forEach(row => {
          row.classList.remove('drag-over-top', 'drag-over-bottom');
        });
      });

      tr.addEventListener('dragover', (e) => {
        if (!draggedRow || draggedRow === tr) return;
        e.preventDefault();
        const rect = tr.getBoundingClientRect();
        const next = (e.clientY - rect.top) / (rect.bottom - rect.top) > 0.5;
        tr.classList.toggle('drag-over-bottom', next);
        tr.classList.toggle('drag-over-top', !next);
      });

      tr.addEventListener('dragleave', () => {
        tr.classList.remove('drag-over-bottom', 'drag-over-top');
      });

      tr.addEventListener('drop', (e) => {
        if (!draggedRow || draggedRow === tr) return;
        e.preventDefault();
        tr.classList.remove('drag-over-bottom', 'drag-over-top');
        
        const rect = tr.getBoundingClientRect();
        const next = (e.clientY - rect.top) / (rect.bottom - rect.top) > 0.5;
        if (next) {
          tr.parentNode.insertBefore(draggedRow, tr.nextSibling);
        } else {
          tr.parentNode.insertBefore(draggedRow, tr);
        }
        recalcTotals();
      });
    }

    recalcRowTotal(tr);
  }

  // Ricalcola il totale di una riga
  function recalcRowTotal(tr) {
    const qty = parseFloat(tr.querySelector('.r-qty')?.value) || 0;
    const prezzo = parseFloat(tr.querySelector('.r-prezzo')?.value) || 0;
    const total = qty * prezzo;
    const totalSpan = tr.querySelector('.r-totale-riga');
    if (totalSpan) {
      totalSpan.textContent = total.toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    recalcTotals();
  }

  // Ricalcola i totali generali
  function recalcTotals() {
    let imponibile = 0;
    let iva = 0;

    document.querySelectorAll('tr.rfq-riga').forEach(tr => {
      const qty = parseFloat(tr.querySelector('.r-qty')?.value) || 0;
      const prezzo = parseFloat(tr.querySelector('.r-prezzo')?.value) || 0;
      const ivaPerc = parseFloat(tr.querySelector('.r-iva')?.value) || 0;

      const rowImponibile = qty * prezzo;
      const rowIva = rowImponibile * (ivaPerc / 100);

      imponibile += rowImponibile;
      iva += rowIva;
    });

    const valuta = document.getElementById('rfq-valuta')?.value || 'EUR';
    const symbol = { EUR: '€', USD: '$', GBP: '£' }[valuta] || '€';

    document.querySelectorAll('.currency-symbol').forEach(el => {
      el.textContent = symbol;
    });

    const spanImponibile = document.getElementById('totale-imponibile');
    const spanIva = document.getElementById('totale-iva');
    const spanIvato = document.getElementById('totale-ivato');

    if (spanImponibile) spanImponibile.textContent = imponibile.toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    if (spanIva) spanIva.textContent = iva.toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    if (spanIvato) spanIvato.textContent = (imponibile + iva).toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  // Gestione cambio UoM
  const uomModeSelect = document.getElementById('rfq-uom-mode');
  if (uomModeSelect) {
    uomModeSelect.addEventListener('change', () => {
      const targetMode = uomModeSelect.value;
      document.querySelectorAll('tr.rfq-riga').forEach(tr => {
        setRowUomMode(tr, targetMode, currentUomMode);
        recalcRowTotal(tr);
      });
      currentUomMode = targetMode;
    });
    // Allinea subito i testi e gli ID delle UoM sul caricamento pagina
    uomModeSelect.dispatchEvent(new Event('change'));
  }

  // Gestione valuta
  const valutaSelect = document.getElementById('rfq-valuta');
  if (valutaSelect) {
    valutaSelect.addEventListener('change', recalcTotals);
  }

  // Bottone "Aggiungi riga"
  const btnAdd = document.getElementById('btn-aggiungi-riga');
  if (btnAdd) {
    btnAdd.addEventListener('click', () => {
      const tr = addRiga();
      tr.querySelector('.r-prodotto-label')?.focus();
    });
  }

  // Inizializza righe PHP pre-esistenti (non usano il template ma lo stesso init)
  document.querySelectorAll('tr.rfq-riga').forEach(tr => {
    rigaIdx = Math.max(rigaIdx, (parseInt(tr.dataset.idx) || 0) + 1);
    initRiga(tr);
  });

  // Ricalcola subito al caricamento
  recalcTotals();


  /* ══════════════════════════════════════════════════════════════════════
     MODALE: Stampa & Segna come Inviata
  ═══════════════════════════════════════════════════════════════════════ */
  const btnInvia   = document.getElementById('btn-invia-rfq');
  const modalInvia = document.getElementById('modal-invia-rfq');
  if (btnInvia && modalInvia) {
    btnInvia.addEventListener('click', () => {
      modalInvia.style.display = 'flex';
    });
  }

  const btnCopiaEmail = document.getElementById('btn-copia-email');
  if (btnCopiaEmail) {
    btnCopiaEmail.addEventListener('click', () => {
      const rfqNumero = <?= json_encode($rfq['numero'] ?? '') ?>;
      const fornitoreNome = <?= json_encode($rfq['fornitore_nome'] ?? '') ?>;
      const emailText = `Spett.le ${fornitoreNome || 'Fornitore'},\n\nin allegato vi inviamo la nostra richiesta di offerta ${rfqNumero} per la quotazione degli articoli indicati nel documento.\n\nRestiamo in attesa di un vostro riscontro in merito a prezzi e tempi di consegna.\n\nCordiali saluti,\nUfficio Acquisti`;
      
      navigator.clipboard.writeText(emailText).then(() => {
        const originalText = btnCopiaEmail.innerHTML;
        btnCopiaEmail.innerHTML = '<i class="fa-solid fa-check"></i> Copiato!';
        btnCopiaEmail.style.backgroundColor = 'var(--qk-success-bg, #d1e7dd)';
        btnCopiaEmail.style.color = 'var(--qk-success-color, #0f5132)';
        setTimeout(() => {
          btnCopiaEmail.innerHTML = originalText;
          btnCopiaEmail.style.backgroundColor = '';
          btnCopiaEmail.style.color = '';
        }, 2000);
      }).catch(err => {
        alert("Errore nella copia del testo: " + err);
      });
    });
  }

  /* ══════════════════════════════════════════════════════════════════════
     MODALE: Creazione rapida fornitore
  ═══════════════════════════════════════════════════════════════════════ */
  const btnSalvaFornitore = document.getElementById('btn-qf-salva');
  if (btnSalvaFornitore) {
    btnSalvaFornitore.addEventListener('click', async () => {
      const ragione = document.getElementById('qf-ragione-sociale')?.value.trim();
      const piva    = document.getElementById('qf-partita-iva')?.value.trim();
      const errDiv  = document.getElementById('qf-error');
      const errMsg  = document.getElementById('qf-error-msg');

      if (!ragione) {
        errMsg.textContent = 'La ragione sociale è obbligatoria.';
        errDiv.style.display = 'flex';
        return;
      }
      errDiv.style.display = 'none';

      try {
        const res  = await fetch(API_FORNITORE_CREA, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ _csrf: CSRF_TOKEN, ragione_sociale: ragione, partita_iva: piva }),
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error ?? 'Errore sconosciuto');

        // Popola il campo fornitore
        const fHidden = document.getElementById('rfq-fornitore-id');
        const fInput  = document.getElementById('rfq-fornitore-label');
        const fClear  = document.getElementById('rfq-fornitore-clear');
        if (fHidden) fHidden.value  = String(data.id);
        if (fInput)  fInput.value   = data.nome;
        if (fClear)  fClear.classList.remove('d-none');
        fornitoreSel = { id: String(data.id), nome: data.nome };

        // Chiudi modal
        document.getElementById('modal-quick-fornitore').style.display = 'none';
        document.getElementById('qf-ragione-sociale').value = '';
        document.getElementById('qf-partita-iva').value     = '';
      } catch (err) {
        errMsg.textContent = err.message;
        errDiv.style.display = 'flex';
      }
    });
  }

  /* ── Caricamento dinamico indirizzi di consegna ─────────────────────── */
  const azSelect = document.getElementById('rfq-azienda-interna-id');
  const indSelect = document.getElementById('rfq-indirizzo-consegna-id');
  const API_AZIENDA_INDIRIZZI = <?= json_encode(url('purchases/rfq/api/azienda-indirizzi')) ?>;

  let loadedAddresses = [];

  function getOriginalAddress() {
    const val = indSelect.value;
    if (!val) {
      return loadedAddresses[0] || {};
    }
    return loadedAddresses.find(i => String(i.id) === String(val)) || {};
  }

  function clearOverrideStylesSped() {
    [
      document.getElementById('consegna_via'),
      document.getElementById('consegna_civico'),
      document.getElementById('consegna_cap'),
      document.getElementById('consegna_citta'),
      document.getElementById('consegna_provincia'),
      document.getElementById('consegna_nazione'),
      document.getElementById('consegna_presso'),
      document.getElementById('consegna_referente'),
      document.getElementById('consegna_telefono'),
      document.getElementById('consegna_note')
    ].forEach(inp => {
      if (inp) inp.classList.remove('is-overridden');
    });
  }

  function syncAddressUI() {
    const val = indSelect.value;
    const ind = getOriginalAddress();
    const inpVia = document.getElementById('consegna_via');
    if (!inpVia) return;

    const isNewForm = !inpVia.value;
    if (isNewForm && ind.via && CAN_EDIT) {
      copyAddressToFields(ind);
    }

    let hasOverride = false;
    const checkField = (inputEl, origValue) => {
      if (!inputEl) return;
      const cur = inputEl.value.trim();
      const ref = (origValue ?? '').trim();
      if (cur !== ref) {
        inputEl.classList.add('is-overridden');
        hasOverride = true;
      } else {
        inputEl.classList.remove('is-overridden');
      }
    };

    checkField(document.getElementById('consegna_via'), ind.via);
    checkField(document.getElementById('consegna_civico'), ind.civico);
    checkField(document.getElementById('consegna_cap'), ind.cap);
    checkField(document.getElementById('consegna_citta'), ind.citta);
    checkField(document.getElementById('consegna_provincia'), ind.provincia);
    checkField(document.getElementById('consegna_nazione'), ind.nazione || 'IT');
    checkField(document.getElementById('consegna_presso'), ind.presso);
    checkField(document.getElementById('consegna_referente'), ind.referente_consegna);
    checkField(document.getElementById('consegna_telefono'), ind.telefono_consegna);
    checkField(document.getElementById('consegna_note'), ind.note_consegna);

    const badge = document.getElementById('sped-state-badge');
    const resetBtn = document.getElementById('btn-cancel-edit-sped');
    if (hasOverride) {
      badge.textContent = 'Modificato localmente (override)';
      badge.className = 'badge bg-warning text-dark ms-2';
      if (resetBtn) resetBtn.style.display = 'inline-block';
    } else {
      badge.textContent = '✓ Sincronizzato con anagrafica';
      badge.className = 'badge bg-success text-white ms-2';
      if (resetBtn) resetBtn.style.display = 'none';
    }
  }

  function copyAddressToFields(ind) {
    const elVia = document.getElementById('consegna_via');
    const elCiv = document.getElementById('consegna_civico');
    const elCap = document.getElementById('consegna_cap');
    const elCit = document.getElementById('consegna_citta');
    const elPrv = document.getElementById('consegna_provincia');
    const elNaz = document.getElementById('consegna_nazione');
    const elPre = document.getElementById('consegna_presso');
    const elRef = document.getElementById('consegna_referente');
    const elTel = document.getElementById('consegna_telefono');
    const elNot = document.getElementById('consegna_note');

    if (elVia) elVia.value = ind.via || '';
    if (elCiv) elCiv.value = ind.civico || '';
    if (elCap) elCap.value = ind.cap || '';
    if (elCit) elCit.value = ind.citta || '';
    if (elPrv) elPrv.value = ind.provincia || '';
    if (elNaz) elNaz.value = ind.nazione || 'IT';
    if (elPre) elPre.value = ind.presso || '';
    if (elRef) elRef.value = ind.referente_consegna || '';
    if (elTel) elTel.value = ind.telefono_consegna || '';
    if (elNot) elNot.value = ind.note_consegna || '';
  }

  function clearAddressFields() {
    [
      'consegna_via', 'consegna_civico', 'consegna_cap', 'consegna_citta',
      'consegna_provincia', 'consegna_presso', 'consegna_referente',
      'consegna_telefono', 'consegna_note'
    ].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.value = '';
    });
    const elNaz = document.getElementById('consegna_nazione');
    if (elNaz) elNaz.value = 'IT';
  }

  // Bind input changes to sync override indicator
  [
    'consegna_via', 'consegna_civico', 'consegna_cap', 'consegna_citta',
    'consegna_provincia', 'consegna_nazione', 'consegna_presso',
    'consegna_referente', 'consegna_telefono', 'consegna_note'
  ].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', syncAddressUI);
  });

  const btnCancelEditSped = document.getElementById('btn-cancel-edit-sped');
  if (btnCancelEditSped) {
    btnCancelEditSped.addEventListener('click', () => {
      const ind = getOriginalAddress();
      if (ind) copyAddressToFields(ind);
      syncAddressUI();
    });
  }

  async function loadAziendaAddresses() {
    if (!azSelect || !indSelect) return;
    const aziendaId = azSelect.value;
    if (!aziendaId) {
      indSelect.innerHTML = '<option value="">Seleziona azienda prima...</option>';
      return;
    }

    indSelect.innerHTML = '<option value="">Caricamento...</option>';
    try {
      const res = await fetch(`${API_AZIENDA_INDIRIZZI}?azienda_id=${aziendaId}`);
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Errore recupero indirizzi');

      loadedAddresses = data.indirizzi || [];
      indSelect.innerHTML = '';
      if (loadedAddresses.length === 0) {
        indSelect.innerHTML = '<option value="">Nessun indirizzo spedizione/magazzino trovato</option>';
        return;
      }

      const selectedId = indSelect.dataset.selectedId;
      let matched = false;

      // Aggiungi opzione vuota di default
      const defaultOpt = document.createElement('option');
      defaultOpt.value = '';
      defaultOpt.textContent = '— Nessun indirizzo selezionato —';
      if (!selectedId) {
        defaultOpt.selected = true;
        matched = true;
      }
      indSelect.appendChild(defaultOpt);

      loadedAddresses.forEach(ind => {
        const option = document.createElement('option');
        option.value = ind.id;
        
        // Costruisci una label leggibile compatta: Nome sede · Città
        const labelParts = [];
        if (ind.nome_sede) labelParts.push(`[${ind.nome_sede}]`);
        if (ind.citta) labelParts.push(ind.citta);
        option.textContent = labelParts.join(' · ') || (ind.via + ' ' + (ind.civico || ''));
        
        if (selectedId && String(ind.id) === String(selectedId)) {
          option.selected = true;
          matched = true;
        }
        
        indSelect.appendChild(option);
      });

      if (!matched && loadedAddresses.length > 0 && CAN_EDIT) {
        // Se non trova corrispondenza ma siamo in modifica, seleziona il primo indirizzo reale
        indSelect.options[1].selected = true; 
      }
      syncAddressUI();
    } catch (err) {
      indSelect.innerHTML = `<option value="">Errore: ${err.message}</option>`;
    }
  }

  if (azSelect) {
    azSelect.addEventListener('change', () => {
      if (indSelect) indSelect.removeAttribute('data-selected-id');
      loadAziendaAddresses();
    });
  }

  if (indSelect) {
    indSelect.addEventListener('change', () => {
      clearAddressFields();
      const val = indSelect.value;
      if (val) {
        const ind = loadedAddresses.find(i => String(i.id) === String(val));
        if (ind) copyAddressToFields(ind);
      }
      syncAddressUI();
    });
  }

  // Carica gli indirizzi subito all'avvio
  loadAziendaAddresses();

  /* ── Caricamento dinamico dettagli fornitore (contatti, listini) ────── */
  const contSelect = document.getElementById('rfq-contatto-id');
  const contNomeInput = document.getElementById('rfq-contatto-nome');
  const contTelInput = document.getElementById('rfq-contatto-telefono');
  const contEmailInput = document.getElementById('rfq-contatto-email');
  const fornTelHidden = document.getElementById('rfq-fornitore-telefono');
  const fornEmailHidden = document.getElementById('rfq-fornitore-email');
  const listiniSelect = document.getElementById('rfq-listino-acquisto-id');
  const API_FORNITORE_DETTAGLI = <?= json_encode(url('purchases/rfq/api/fornitore-dettagli')) ?>;

  let loadedContacts = [];

  function getOriginalContact() {
    const val = contSelect.value;
    if (!val) return {};
    return loadedContacts.find(c => String(c.id) === String(val)) || {};
  }

  function syncContactUI() {
    const val = contSelect.value;
    const orig = getOriginalContact();

    let hasOverride = false;
    const checkField = (inputEl, origValue) => {
      if (!inputEl) return;
      const cur = inputEl.value.trim();
      const ref = (origValue ?? '').trim();
      if (cur !== ref) {
        inputEl.classList.add('is-overridden');
        hasOverride = true;
      } else {
        inputEl.classList.remove('is-overridden');
      }
    };

    checkField(contNomeInput, orig.nome_completo || '');
    checkField(contTelInput, orig.telefono || orig.cellulare || fornTelHidden?.value || '');
    checkField(contEmailInput, orig.email || fornEmailHidden?.value || '');

    const badge = document.getElementById('cont-state-badge');
    const resetBtn = document.getElementById('btn-cancel-edit-cont');

    if (!val) {
      badge.textContent = 'Recapiti manuali / una tantum';
      badge.className = 'badge bg-info text-dark ms-2';
      if (resetBtn) resetBtn.style.display = 'none';
      [contNomeInput, contTelInput, contEmailInput].forEach(inp => {
        if (inp) inp.classList.remove('is-overridden');
      });
    } else if (hasOverride) {
      badge.textContent = 'Modificato localmente (override)';
      badge.className = 'badge bg-warning text-dark ms-2';
      if (resetBtn) resetBtn.style.display = 'inline-block';
    } else {
      badge.textContent = '✓ Sincronizzato con anagrafica';
      badge.className = 'badge bg-success text-white ms-2';
      if (resetBtn) resetBtn.style.display = 'none';
    }
  }

  [contNomeInput, contTelInput, contEmailInput].forEach(inp => {
    if (inp) inp.addEventListener('input', syncContactUI);
  });

  const btnCancelEditCont = document.getElementById('btn-cancel-edit-cont');
  if (btnCancelEditCont) {
    btnCancelEditCont.addEventListener('click', () => {
      const contact = getOriginalContact();
      if (contact && contact.nome_completo) {
        if (contNomeInput) contNomeInput.value = contact.nome_completo || '';
        if (contTelInput) contTelInput.value = contact.telefono || contact.cellulare || fornTelHidden?.value || '';
        if (contEmailInput) contEmailInput.value = contact.email || fornEmailHidden?.value || '';
      } else {
        if (contNomeInput) contNomeInput.value = '';
        if (contTelInput) contTelInput.value = fornTelHidden?.value || '';
        if (contEmailInput) contEmailInput.value = fornEmailHidden?.value || '';
      }
      syncContactUI();
    });
  }

  async function loadFornitoreDettagli() {
    if (!contSelect || !listiniSelect) return;
    const fornitoreId = document.getElementById('rfq-fornitore-id')?.value;
    if (!fornitoreId) {
      contSelect.innerHTML = '<option value="">Seleziona fornitore prima...</option>';
      listiniSelect.innerHTML = '<option value="">Nessun listino specifico...</option>';
      return;
    }

    contSelect.innerHTML = '<option value="">Caricamento...</option>';
    listiniSelect.innerHTML = '<option value="">Caricamento...</option>';
    try {
      const res = await fetch(`${API_FORNITORE_DETTAGLI}?fornitore_id=${fornitoreId}`);
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Errore recupero dettagli');

      // Memorizza i contatti scaricati per l'evento change
      loadedContacts = data.contatti || [];

      // Salva recapiti aziendali generali come fallback
      if (fornTelHidden) fornTelHidden.value = data.fornitore?.telefono || '';
      if (fornEmailHidden) fornEmailHidden.value = data.fornitore?.email || data.fornitore?.cellulare || '';

      // Se stiamo creando e il fornitore ha condizioni di pagamento predefinite, proponile
      const condPag = document.getElementById('rfq-condizioni-pagamento');
      if (condPag && !condPag.value && data.fornitore?.condizioni_pagamento) {
        condPag.value = data.fornitore.condizioni_pagamento;
      }

      // Popola dropdown Contatti / Referente
      contSelect.innerHTML = '<option value="">— Nessuno (Usa recapiti generali azienda) —</option>';
      const selectedContId = contSelect.dataset.selectedId;
      data.contatti.forEach(c => {
        const option = document.createElement('option');
        option.value = c.id;
        option.textContent = `${c.nome_completo} (${c.email || c.telefono || 'nessun recapito'})`;
        if (selectedContId && String(c.id) === String(selectedContId)) {
          option.selected = true;
        }
        contSelect.appendChild(option);
      });

      // Popola dropdown Listini
      listiniSelect.innerHTML = '<option value="">— Nessun listino specifico —</option>';
      const selectedListinoId = listiniSelect.dataset.selectedId;
      data.listini.forEach(l => {
        const option = document.createElement('option');
        option.value = l.id;
        option.textContent = l.nome_listino;
        if (selectedListinoId && String(l.id) === String(selectedListinoId)) {
          option.selected = true;
        }
        listiniSelect.appendChild(option);
      });

      syncContactUI();
    } catch (err) {
      contSelect.innerHTML = `<option value="">Errore: ${err.message}</option>`;
      listiniSelect.innerHTML = `<option value="">Errore: ${err.message}</option>`;
    }
  }

  // Listener cambio contatto referente
  if (contSelect) {
    contSelect.addEventListener('change', () => {
      const selectedId = contSelect.value;
      if (!selectedId) {
        // Fallback su recapiti generali dell'azienda
        if (contTelInput) contTelInput.value = fornTelHidden?.value || '';
        if (contEmailInput) contEmailInput.value = fornEmailHidden?.value || '';
        if (contNomeInput) contNomeInput.value = '';
      } else {
        const contact = loadedContacts.find(c => String(c.id) === String(selectedId));
        if (contact) {
          if (contTelInput) contTelInput.value = contact.telefono || contact.cellulare || fornTelHidden?.value || '';
          if (contEmailInput) contEmailInput.value = contact.email || fornEmailHidden?.value || '';
          if (contNomeInput) contNomeInput.value = contact.nome_completo || '';
        }
      }
      syncContactUI();
    });
  }

  // Auto-suggerimento Resa Porto basato sull'Incoterm
  const incotermSelect = document.getElementById('rfq-incoterm-codice');
  const resaPortoSelect = document.getElementById('rfq-resa-porto');
  if (incotermSelect && resaPortoSelect) {
    incotermSelect.addEventListener('change', () => {
      const val = incotermSelect.value;
      if (['EXW', 'FCA', 'FOB', 'FAS'].includes(val)) {
        resaPortoSelect.value = 'porto_assegnato';
      } else if (['CPT', 'CIP', 'DAP', 'DDP', 'DPU', 'CFR', 'CIF'].includes(val)) {
        resaPortoSelect.value = 'porto_franco';
      }
    });
  }

  // Implement Read-only Lock for status not 'bozza'
  const rfqStato = <?= json_encode($values['stato'] ?? 'bozza') ?>;
  if (rfqStato !== 'bozza') {
    [
      azSelect, indSelect, contSelect,
      document.getElementById('consegna_via'),
      document.getElementById('consegna_civico'),
      document.getElementById('consegna_cap'),
      document.getElementById('consegna_citta'),
      document.getElementById('consegna_provincia'),
      document.getElementById('consegna_nazione'),
      document.getElementById('consegna_presso'),
      document.getElementById('consegna_referente'),
      document.getElementById('consegna_telefono'),
      document.getElementById('consegna_note'),
      document.getElementById('rfq-contatto-nome'),
      document.getElementById('rfq-contatto-telefono'),
      document.getElementById('rfq-contatto-email')
    ].forEach(el => {
      if (el) {
        el.disabled = true;
        el.readOnly = true;
      }
    });
    if (btnCancelEditSped) btnCancelEditSped.style.display = 'none';
    if (btnCancelEditCont) btnCancelEditCont.style.display = 'none';
  }

  // Carica i dettagli del fornitore subito all'avvio (se già selezionato in modifica)
  loadFornitoreDettagli();



  /* ── Mostra/Nascondi Colonne Odoo-Style ────────────────────────────── */
  const btnColSettings = document.getElementById('btn-col-settings');
  const colSettingsDropdown = document.getElementById('col-settings-dropdown');
  const chkFormato = document.getElementById('chk-show-formato');
  const chkPrezzo = document.getElementById('chk-show-prezzo');
  const chkTotale = document.getElementById('chk-show-totale');
  const chkDataConsegna = document.getElementById('chk-show-data-consegna');
  const chkRifiutato = document.getElementById('chk-show-rifiutato');

  if (btnColSettings && colSettingsDropdown) {
    btnColSettings.addEventListener('click', (e) => {
      e.stopPropagation();
      const isVisible = colSettingsDropdown.style.display === 'block';
      colSettingsDropdown.style.display = isVisible ? 'none' : 'block';
    });

    document.addEventListener('click', (e) => {
      if (!colSettingsDropdown.contains(e.target) && e.target !== btnColSettings) {
        colSettingsDropdown.style.display = 'none';
      }
    });
  }

  function updateColumnVisibility() {
    const showFormato = chkFormato ? chkFormato.checked : true;
    const showPrezzo = chkPrezzo ? chkPrezzo.checked : true;
    const showTotale = chkTotale ? chkTotale.checked : true;
    const showDataConsegna = chkDataConsegna ? chkDataConsegna.checked : false;
    const showRifiutato = chkRifiutato ? chkRifiutato.checked : false;

    document.querySelectorAll('.col-formato').forEach(el => {
      el.style.display = showFormato ? '' : 'none';
    });

    document.querySelectorAll('.col-prezzo').forEach(el => {
      el.style.display = showPrezzo ? '' : 'none';
    });

    document.querySelectorAll('.col-totale').forEach(el => {
      el.style.display = showTotale ? '' : 'none';
    });

    document.querySelectorAll('.col-data-consegna').forEach(el => {
      if (showDataConsegna) {
        el.classList.remove('col-hidden');
      } else {
        el.classList.add('col-hidden');
      }
    });

    document.querySelectorAll('.col-rifiutato').forEach(el => {
      if (showRifiutato) {
        el.classList.remove('col-hidden');
      } else {
        el.classList.add('col-hidden');
      }
    });
    
    localStorage.setItem('rfq_show_formato', showFormato ? '1' : '0');
    localStorage.setItem('rfq_show_prezzo', showPrezzo ? '1' : '0');
    localStorage.setItem('rfq_show_totale', showTotale ? '1' : '0');
    localStorage.setItem('rfq_show_data_consegna', showDataConsegna ? '1' : '0');
    localStorage.setItem('rfq_show_rifiutato', showRifiutato ? '1' : '0');
  }

  if (chkFormato) chkFormato.addEventListener('change', updateColumnVisibility);
  if (chkPrezzo) chkPrezzo.addEventListener('change', updateColumnVisibility);
  if (chkTotale) chkTotale.addEventListener('change', updateColumnVisibility);
  if (chkDataConsegna) chkDataConsegna.addEventListener('change', updateColumnVisibility);
  if (chkRifiutato) chkRifiutato.addEventListener('change', updateColumnVisibility);

  // Inizializzazione della preferenza da localStorage o dai dati esistenti
  if (chkFormato) {
    const pref = localStorage.getItem('rfq_show_formato');
    chkFormato.checked = (pref === null || pref === '1');
  }
  if (chkPrezzo) {
    const pref = localStorage.getItem('rfq_show_prezzo');
    chkPrezzo.checked = (pref === null || pref === '1');
  }
  if (chkTotale) {
    const pref = localStorage.getItem('rfq_show_totale');
    chkTotale.checked = (pref === null || pref === '1');
  }
  if (chkDataConsegna) {
    const hasDataConsegnaValue = Array.from(document.querySelectorAll('.r-data-consegna')).some(el => el.value !== '');
    const pref = localStorage.getItem('rfq_show_data_consegna');
    chkDataConsegna.checked = (pref === '1') || (pref === null && hasDataConsegnaValue);
  }
  if (chkRifiutato) {
    const hasRifiutatoValue = Array.from(document.querySelectorAll('.r-e-rifiutato')).some(el => el.value === '1');
    const pref = localStorage.getItem('rfq_show_rifiutato');
    chkRifiutato.checked = (pref === '1') || (pref === null && hasRifiutatoValue);
  }
  updateColumnVisibility();

  /* ── Switcher Tab Odoo-Style ───────────────────────────────────────── */
  window.switchRfqTab = function(tabId) {
    document.querySelectorAll('.qk-nav-tabs .nav-link').forEach(btn => {
      btn.classList.remove('active');
    });
    const activeBtn = document.getElementById('tab-' + tabId + '-btn');
    if (activeBtn) activeBtn.classList.add('active');

    document.querySelectorAll('.tab-content .tab-pane').forEach(pane => {
      pane.classList.add('d-none');
      pane.classList.remove('active');
    });
    const activePane = document.getElementById('pane-' + tabId);
    if (activePane) {
      activePane.classList.remove('d-none');
      activePane.classList.add('active');
    }
  };

});
</script>
