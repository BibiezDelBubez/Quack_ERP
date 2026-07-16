<?php
/**
 * purchases/quotes/_form.php
 * Form di creazione / modifica Offerta/Preventivo Fornitore (ciclo acquisti v2).
 *
 * Variabili attese:
 *   $id               int|null    id offerta (null = nuova)
 *   $values           array       valori form
 *   $righe            array       righe offerta
 *   $errors           array       errori di validazione
 *   $detail           array|null  dettaglio completo
 *   $anteprima_numero string      numero ERP proposto
 */

$isEdit = isset($id) && $id > 0;
$offerta = $detail['offerta'] ?? null;
$stato = $values['stato'] ?? 'in_valutazione';

$isInValutazione = $stato === 'in_valutazione';
$isAccettata     = $stato === 'accettata';
$isRifiutata     = $stato === 'rifiutata';

// Solo in valutazione si può modificare
$canEdit = $isInValutazione || !$isEdit;
$readonly = $canEdit ? '' : 'readonly';
$disabled = $canEdit ? '' : 'disabled';

$statoLabel = [
    'in_valutazione' => ['pending',   'In valutazione'],
    'accettata'      => ['confirmed', 'Accettata'],
    'rifiutata'      => ['draft',     'Rifiutata'],
][$stato] ?? ['draft', $stato];
?>

<style>
</style>

  <?php if ($isEdit && $stato === 'rifiutata' && !empty($offerta['motivo_rifiuto'])): ?>
  <div class="qk-alert qk-alert-danger mb-3">
    <i class="fa-solid fa-ban"></i>
    <span><strong>Preventivo Rifiutato. Motivo:</strong> <?= e($offerta['motivo_rifiuto']) ?></span>
  </div>
  <?php endif; ?>

<!-- Guidance Box -->
<div class="qk-guidance-box mb-4">
  <div class="fs-4"><i class="fa-solid fa-circle-info"></i></div>
  <div>
    <h6 class="mb-1 fw-bold">Cosa fare adesso?</h6>
    <p class="mb-0 text-muted" style="font-size:0.875rem">
      <?php if (!$isEdit): ?>
        Inserisci i dettagli del preventivo spontaneo o della quotazione del fornitore, compresi i prezzi unitari concordati, quindi clicca su "Salva".
      <?php elseif ($isInValutazione): ?>
        Il preventivo è in fase di valutazione. Controlla i prezzi unitari e le date di consegna inserite, quindi clicca su <strong>"Accetta e Genera Ordine"</strong> per generare automaticamente l'Ordine di Acquisto collegato.
      <?php elseif ($isAccettata): ?>
        Questo preventivo è stato accettato ed è stato generato l'ordine di acquisto collegato.
      <?php elseif ($isRifiutata): ?>
        Questo preventivo è stato rifiutato.
      <?php endif; ?>
    </p>
  </div>
</div>

  <!-- Barra Azioni e Stato Sticky -->
  <div class="qk-sticky-action-bar mb-4">
    <div class="qk-sticky-action-bar-left">
      <?php if ($canEdit): ?>
        <button type="submit" form="form-offerta" class="btn-qk btn-qk-primary">
          <i class="fa-solid fa-floppy-disk"></i> Salva Preventivo
        </button>
        <a href="<?= url('purchases/quotes') ?>" class="btn-qk btn-qk-secondary">Annulla</a>
      <?php endif; ?>

      <?php if ($isEdit && $isInValutazione): ?>
        <!-- Accetta ed eventualmente genera ordine -->
        <form method="post" action="<?= url('purchases/quotes/action') ?>" class="d-inline">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $id ?>">
          <input type="hidden" name="azione" value="accetta">
          <input type="hidden" name="genera_ordine" value="1">
          <button type="submit" class="btn-qk btn-qk-success">
            <i class="fa-solid fa-circle-check"></i> Accetta e Genera Ordine
          </button>
        </form>
      <?php endif; ?>
    </div>

    <div class="qk-sticky-action-bar-right">
      <?php if ($isEdit && $isInValutazione): ?>
        <!-- Rifiuta (triggers modal) -->
        <button type="button" class="btn-qk btn-qk-secondary text-danger" onclick="document.getElementById('modal-rifiuta-offerta').style.display='flex'">
          <i class="fa-solid fa-ban"></i> Rifiuta
        </button>
      <?php endif; ?>

      <a href="<?= url('purchases/quotes') ?>" class="btn-qk btn-qk-secondary">
        <i class="fa-solid fa-arrow-left"></i> Elenco Preventivi
      </a>

      <!-- Pipeline visivo stati -->
      <ul class="qk-status-path d-none d-sm-flex" style="margin: 0; padding: 0;">
        <?php
        $steps = [
          ['in_valutazione', 'In valutazione'],
          ['accettata',      'Accettata'],
          ['rifiutata',      'Rifiutata'],
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
          } elseif ($s === 'rifiutata' && $stato === 'accettata') {
            $statusClass = 'pending';
          } elseif ($s === 'accettata' && $stato === 'rifiutata') {
            $statusClass = 'pending';
          } elseif ($idx < $currentIdx) {
            $statusClass = 'completed';
          } else {
            $statusClass = 'pending';
          }
          
          if ($s === 'rifiutata' && $stato !== 'rifiutata') continue;
          if ($s === 'accettata' && $stato === 'rifiutata') continue;
        ?>
          <li class="qk-status-path-item <?= $statusClass ?>">
            <?= $lbl ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>

<form method="post" action="<?= $isEdit ? url('purchases/quotes/edit') . '?id=' . $id : url('purchases/quotes/create') ?>" id="form-offerta" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <input type="hidden" name="stato" value="<?= e($stato) ?>">
  <input type="hidden" name="rfq_id" value="<?= e($values['rfq_id']) ?>">

  <?php if (!empty($errors['_global'])): ?>
  <div class="qk-alert qk-alert-danger mb-3">
    <i class="fa-solid fa-triangle-exclamation"></i>
    <span><?= e($errors['_global']) ?></span>
  </div>
  <?php endif; ?>

  <div class="qk-card mb-4">
    <div class="qk-card-header">
      <span class="qk-card-title">
        <i class="fa-solid fa-file-invoice me-2"></i>Dati Generali
      </span>
    </div>
    <div class="qk-card-body">
      <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:1.5rem;">
        <!-- RFQ di origine -->
        <div class="qk-form-group">
          <label class="qk-form-label">RFQ Collegata</label>
          <?php if (!empty($values['rfq_id'])): ?>
            <div class="qk-form-control-plaintext">
               <a href="<?= url('purchases/rfq/edit') . '?id=' . $values['rfq_id'] ?>" class="qk-link-strong">
                <i class="fa-solid fa-arrow-up-right-from-square"></i> RFQ #<?= e($values['rfq_numero'] ?: $values['rfq_id']) ?><?= !empty($values['rfq_riferimento_interno']) ? ' — ' . e($values['rfq_riferimento_interno']) : '' ?>
              </a>
            </div>
          <?php else: ?>
            <span class="qk-text-muted">Nessuna (Preventivo Spontaneo)</span>
          <?php endif; ?>
        </div>

        <!-- Fornitore -->
        <div class="qk-form-group">
          <label class="qk-form-label">Fornitore <span style="color:var(--qk-danger)">*</span></label>
          <?php if ($isEdit || !empty($values['rfq_id'])): ?>
            <input type="hidden" name="fornitore_id" id="off-fornitore-id" value="<?= e($values['fornitore_id']) ?>">
            <div class="qk-form-control-plaintext">
              <strong><?= e($offerta['fornitore_nome'] ?? $values['fornitore_nome'] ?? 'Fornitore pre-caricato') ?></strong>
            </div>
          <?php else: ?>
            <input type="hidden" name="fornitore_id" id="off-fornitore-id" value="<?= e($values['fornitore_id']) ?>">
            <div class="qk-m2o-wrap" style="position:relative">
              <div class="qk-m2o-input-row">
                <input type="text" class="qk-m2o-input" id="off-fornitore-label" placeholder="Cerca fornitore…" value="<?= e($values['fornitore_nome'] ?? '') ?>" autocomplete="off" required>
                <button type="button" class="qk-m2o-clear d-none" id="off-fornitore-clear">&times;</button>
              </div>
              <div class="qk-m2o-dropdown shadow-sm" id="off-fornitore-dropdown"></div>
            </div>
          <?php endif; ?>
          <?php if (!empty($errors['fornitore_id'])): ?>
            <span class="qk-form-error"><i class="fa-solid fa-circle-xmark"></i> <?= e($errors['fornitore_id']) ?></span>
          <?php endif; ?>
        </div>

        <!-- Numero Preventivo Fornitore -->
        <div class="qk-form-group">
          <label class="qk-form-label">Riferimento Preventivo Fornitore <span style="color:var(--qk-danger)">*</span></label>
          <input type="text" name="riferimento_fornitore" class="qk-form-control"
                 placeholder="Es. Preventivo n° 456"
                 value="<?= e($values['riferimento_fornitore'] ?? '') ?>" required <?= $readonly ?>>
        </div>

        <!-- Allegato Preventivo -->
        <div class="qk-form-group">
          <label class="qk-form-label">Allegato Preventivo</label>
          <?php if (!empty($values['allegato_path'])): ?>
            <div class="mb-2" class="qk-flex-row qk-gap-sm">
              <a href="<?= url(ltrim($values['allegato_path'], '/')) ?>" target="_blank" class="btn-qk btn-qk-ghost text-primary py-1 px-2" style="font-size:.82rem; height:auto; display:inline-flex; align-items:center; gap:.25rem">
                <i class="fa-solid fa-file-pdf"></i> Apri allegato
              </a>
              <?php if ($canEdit): ?>
                <label class="qk-flex-row  text-danger cursor-pointer" style="font-size:.82rem; margin:0">
                  <input type="checkbox" name="rimuovi_allegato" value="1"> Rimuovi
                </label>
              <?php endif; ?>
            </div>
          <?php endif; ?>
          <input type="hidden" name="allegato_path" value="<?= e($values['allegato_path'] ?? '') ?>">
          <?php if ($canEdit): ?>
            <input type="file" name="allegato_preventivo" class="qk-form-control" accept=".pdf,.doc,.docx,.png,.jpg,.jpeg">
          <?php endif; ?>
          <?php if (!empty($errors['allegato_preventivo'])): ?>
            <div class="qk-text-danger" style="font-size:.82rem; margin-top:.25rem">
              <?= e($errors['allegato_preventivo']) ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- Data Consegna Predefinita -->
        <div class="qk-form-group">
          <label class="qk-form-label">Data Consegna Predefinita</label>
          <input type="date" name="data_consegna_default" id="off-data-consegna-default" class="qk-form-control"
                 value="<?= e($values['data_consegna_default'] ?? '') ?>" <?= $readonly ?>>
        </div>
      </div>
    </div>
  </div>

  <!-- Odoo-style Navigation Tabs -->
  <ul class="nav qk-nav-tabs" role="tablist">
    <li class="nav-item">
      <button class="nav-link active" id="tab-articoli-btn" type="button" onclick="switchOffertaTab('articoli')">
        <i class="fa-solid fa-list-check me-1"></i> Articoli Offerti
      </button>
    </li>
    <li class="nav-item">
      <button class="nav-link" id="tab-spedizione-contatti-btn" type="button" onclick="switchOffertaTab('spedizione-contatti')">
        <i class="fa-solid fa-truck-fast me-1"></i> Spedizione & Contatti
      </button>
    </li>
    <li class="nav-item">
      <button class="nav-link" id="tab-condizioni-commerciali-btn" type="button" onclick="switchOffertaTab('condizioni-commerciali')">
        <i class="fa-solid fa-file-invoice-dollar me-1"></i> Condizioni Commerciali
      </button>
    </li>
    <li class="nav-item">
      <button class="nav-link" id="tab-note-btn" type="button" onclick="switchOffertaTab('note')">
        <i class="fa-solid fa-comment-dots me-1"></i> Note
      </button>
    </li>
  </ul>

  <!-- Tab Content Panes -->
  <div class="tab-content mt-3">

    <!-- Pane: Articoli Offerti -->
    <div class="tab-pane active" id="pane-articoli">
      <div class="qk-card mb-4">
        <div class="qk-card-header" class="qk-flex-row qk-justify-between" style=" width:100%">
          <span class="qk-card-title">
            <i class="fa-solid fa-list me-2"></i>Articoli Offerti
          </span>
          <div class="qk-dropdown-container" style="position:relative; display:inline-block; line-height:1;">
            <button type="button" class="btn-qk btn-qk-secondary btn-qk-xs" id="btn-col-settings" title="Mostra/Nascondi colonne">
              <i class="fa-solid fa-sliders"></i> Colonne
            </button>
            <div class="qk-dropdown-menu shadow-sm" id="col-settings-dropdown" style="display:none; position:absolute; right:0; top:120%; z-index:1000; background:var(--qk-card-bg); border:1px solid var(--qk-border); border-radius:var(--qk-radius); padding:.6rem .8rem; min-width:180px; text-align:left; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
              <div class="qk-flex-col qk-gap-sm">
                <label class="qk-flex-row qk-gap-sm" style="font-size:.85rem;font-weight:500;cursor:pointer;color:var(--qk-text-primary);margin:0">
                  <input type="checkbox" id="chk-show-sku" style="transform:scale(1.15);" checked>
                  <span>SKU Fornitore</span>
                </label>
                <label class="qk-flex-row qk-gap-sm" style="font-size:.85rem;font-weight:500;cursor:pointer;color:var(--qk-text-primary);margin:0">
                  <input type="checkbox" id="chk-show-desc" style="transform:scale(1.15);" checked>
                  <span>Descrizione</span>
                </label>
                <label class="qk-flex-row qk-gap-sm" style="font-size:.85rem;font-weight:500;cursor:pointer;color:var(--qk-text-primary);margin:0">
                  <input type="checkbox" id="chk-show-sconto" style="transform:scale(1.15);">
                  <span>Sconto %</span>
                </label>
                <label class="qk-flex-row qk-gap-sm" style="font-size:.85rem;font-weight:500;cursor:pointer;color:var(--qk-text-primary);margin:0">
                  <input type="checkbox" id="chk-show-iva" style="transform:scale(1.15);" checked>
                  <span>IVA %</span>
                </label>
                <label class="qk-flex-row qk-gap-sm" style="font-size:.85rem;font-weight:500;cursor:pointer;color:var(--qk-text-primary);margin:0">
                  <input type="checkbox" id="chk-show-consegna" style="transform:scale(1.15);">
                  <span>Data Consegna</span>
                </label>
                <label class="qk-flex-row qk-gap-sm" style="font-size:.85rem;font-weight:500;cursor:pointer;color:var(--qk-text-primary);margin:0">
                  <input type="checkbox" id="chk-show-note" style="transform:scale(1.15);" checked>
                  <span>Note</span>
                </label>
              </div>
            </div>
          </div>
        </div>
        <div class="qk-table-wrap">
          <table class="qk-table">
            <thead>
              <tr>
                <th style="min-width:280px; width:30%">Articolo</th>
                <th class="col-sku">SKU Fornitore</th>
                <th class="col-desc">Descrizione</th>
                <th>Quantità Offerta</th>
                <th>UdM</th>
                <th style="width:140px">Prezzo Unitario (€) <span style="color:var(--qk-danger)">*</span></th>
                <th class="col-sconto" style="width:100px">Sconto %</th>
                <th class="col-iva" style="width:100px">IVA %</th>
                <th class="col-consegna">Data Consegna Promessa</th>
                <th class="col-note">Note</th>
                <?php if ($canEdit): ?>
                <th style="width:60px"></th>
                <?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($righe as $i => $r): ?>
              <?php if ($canEdit && empty($values['rfq_id'])): ?>
              <tr class="offerta-riga" data-idx="<?= $i ?>">
                <td>
                  <input type="hidden" name="riga_prodotto_id[]" class="r-prodotto-id" value="<?= e($r['prodotto_id']) ?>">
                  <input type="hidden" name="riga_rfq_riga_id[]" class="r-rfq-riga-id" value="<?= e($r['rfq_riga_id'] ?? '') ?>">
                  <div class="qk-m2o-wrap" style="position:relative">
                    <input type="text" class="qk-form-control qk-m2o-input r-prodotto-label"
                           placeholder="Cerca articolo…" autocomplete="off"
                           value="<?= e(($r['prodotto_nome'] ?? '') . (!empty($r['sku_interno']) ? ' (' . $r['sku_interno'] . ')' : '')) ?>" required>
                    <button type="button" class="qk-m2o-clear <?= $r['prodotto_id'] ? '' : 'd-none' ?> r-prodotto-clear">&times;</button>
                    <div class="qk-m2o-dropdown shadow-sm r-prodotto-dropdown"></div>
                  </div>
                </td>
                <td class="col-sku">
                  <input type="text" name="riga_sku_fornitore[]" class="qk-form-control r-sku" value="<?= e($r['sku_fornitore'] ?? '') ?>">
                </td>
                <td class="col-desc">
                  <input type="text" name="riga_descrizione[]" class="qk-form-control r-desc" value="<?= e($r['descrizione'] ?? '') ?>">
                </td>
                <td>
                  <input type="number" name="riga_quantita_offerta[]" class="qk-form-control r-qty" step="0.001" value="<?= isset($r['quantita_offerta']) ? (float)$r['quantita_offerta'] : '1' ?>" required>
                </td>
                <td>
                  <input type="hidden" name="riga_unita_misura_id[]" class="r-uom-id" value="<?= e($r['unita_misura_id'] ?? '') ?>">
                  <span class="r-uom-label qk-text-muted"><?= e($r['uom'] ?? '—') ?></span>
                </td>
                <td>
                  <input type="number" name="riga_prezzo_unitario[]" class="qk-form-control r-prezzo" step="0.0001" placeholder="0.00" value="<?= ($r['prezzo_unitario'] !== null && $r['prezzo_unitario'] !== '') ? (float)$r['prezzo_unitario'] : '' ?>" required>
                </td>
                <td class="col-sconto">
                  <input type="number" name="riga_sconto_perc[]" class="qk-form-control r-sconto" step="0.01" value="<?= isset($r['sconto_perc']) ? (float)$r['sconto_perc'] : '0' ?>">
                </td>
                <td class="col-iva">
                  <input type="number" name="riga_iva_perc[]" class="qk-form-control r-iva" step="1" value="<?= isset($r['iva_perc']) ? (float)$r['iva_perc'] : '22' ?>">
                </td>
                <td class="col-consegna">
                  <input type="date" name="riga_data_consegna_prevista[]" class="qk-form-control r-consegna" value="<?= e($r['data_consegna_prevista'] ?? '') ?>">
                </td>
                <td class="col-note">
                  <input type="text" name="riga_note[]" class="qk-form-control r-note" value="<?= e($r['note'] ?? '') ?>">
                </td>
                <td class="col-rimuovi">
                  <button type="button" class="btn-qk btn-qk-xs btn-qk-ghost r-rimuovi" title="Rimuovi riga">
                    <i class="fa-solid fa-trash-can text-danger"></i>
                  </button>
                </td>
              </tr>
              <?php else: ?>
              <tr class="offerta-riga" data-idx="<?= $i ?>" data-temp-id="<?= e($r['temp_id'] ?? $r['id'] ?? 'row_' . $i) ?>" style="<?= !empty($r['offerta_riga_padre_id']) ? 'background: var(--qk-row-alt);' : '' ?>">
                <td>
                  <input type="hidden" name="riga_rfq_riga_id[]" value="<?= e($r['rfq_riga_id'] ?? '') ?>">
                  <input type="hidden" name="riga_offerta_riga_padre_id[]" class="r-padre-id" value="<?= e($r['offerta_riga_padre_id'] ?? '') ?>">
                  
                  <!-- Container per prodotto originale (statico) -->
                  <div class="prod-originale-container" id="prod-originale-<?= $i ?>" style="display: <?= ($r['flag_sostituto'] ?? 0) ? 'none' : 'block' ?>;">
                    <strong><?= e(($r['prodotto_nome'] ?? '') . (!empty($r['sku_interno']) ? ' - ' . $r['sku_interno'] : '')) ?></strong>
                    <?php if (!empty($r['rfq_riga_id']) && isset($r['qta_richiesta_rfq'])): ?>
                      <div class="qk-text-muted mt-1" style="font-size:.78rem; font-weight:normal">
                        Richiesto: <strong><?= e($r['req_sku_interno'] ?? $r['sku_interno'] ?? '') ?></strong> - Qta: <strong><?= e((float)$r['qta_richiesta_rfq']) ?></strong>
                      </div>
                    <?php endif; ?>
                  </div>
    
                  <!-- Container per sostituzione -->
                  <div class="prod-sostituto-container" id="prod-sostituto-<?= $i ?>" style="display: <?= ($r['flag_sostituto'] ?? 0) ? 'block' : 'none' ?>; margin-top: .5rem;">
                    <input type="hidden" name="riga_prodotto_id[]" class="r-prodotto-id" value="<?= e($r['prodotto_id']) ?>">
                    <div class="qk-m2o-wrap" style="position:relative">
                      <div class="qk-m2o-input-row">
                        <input type="text" class="qk-m2o-input r-prodotto-label"
                               placeholder="Cerca sostituto…" autocomplete="off"
                               value="<?= e(($r['prodotto_nome'] ?? '') . (!empty($r['sku_interno']) ? ' (' . $r['sku_interno'] . ')' : '')) ?>" <?= ($r['flag_sostituto'] ?? 0) ? 'required' : '' ?>>
                        <button type="button" class="qk-m2o-clear <?= $canEdit && $r['prodotto_id'] ? '' : 'd-none' ?> r-prodotto-clear" <?= $disabled ?>>&times;</button>
                      </div>
                      <div class="qk-m2o-dropdown shadow-sm r-prodotto-dropdown"></div>
                    </div>
                    
                    <?php if ($canEdit): ?>
                    <button type="button" class="btn-qk btn-qk-ghost text-primary py-0 px-1 mt-1 btn-quick-crea-prod" style="font-size:.75rem; height:auto">
                      <i class="fa-solid fa-plus"></i> Crea Nuovo Articolo
                    </button>
                    <?php endif; ?>
                  </div>
    
                  <?php if ($canEdit && !empty($r['rfq_riga_id'])): ?>
                    <div class="mt-1">
                      <label style="font-size:.8rem; display:inline-flex; align-items:center; gap:.25rem; font-weight:normal; margin:0">
                        <input type="checkbox" name="riga_flag_sostituto_check[]" value="1" class="flag-sostituto-check" <?= ($r['flag_sostituto'] ?? 0) ? 'checked' : '' ?> onchange="toggleSostitutoRow(this, <?= $i ?>)"
                               data-orig-id="<?= e($r['req_prodotto_id'] ?? $r['prodotto_id']) ?>"
                               data-orig-label="<?= e(($r['req_prodotto_nome'] ?? $r['prodotto_nome'] ?? '') . (!empty($r['req_sku_interno'] ?? $r['sku_interno']) ? ' (' . ($r['req_sku_interno'] ?? $r['sku_interno']) . ')' : '')) ?>">
                         Il Fornitore offre prodotto diverso
                      </label>
                      <input type="hidden" name="riga_flag_sostituto[]" class="flag-sostituto-value" value="<?= ($r['flag_sostituto'] ?? 0) ? '1' : '0' ?>">
                    </div>
                  <?php else: ?>
                    <?php if (empty($r['rfq_riga_id'])): ?>
                      <input type="hidden" name="riga_prodotto_id[]" class="r-prodotto-id" value="<?= e($r['prodotto_id']) ?>">
                    <?php endif; ?>
                    <input type="hidden" name="riga_flag_sostituto[]" class="flag-sostituto-value" value="<?= e($r['flag_sostituto'] ?? '0') ?>">
                  <?php endif; ?>
                </td>
                <td class="col-sku">
                  <input type="text" name="riga_sku_fornitore[]" class="qk-form-control" value="<?= e($r['sku_fornitore'] ?? '') ?>" <?= $readonly ?>>
                </td>
                <td class="col-desc">
                  <input type="text" name="riga_descrizione[]" class="qk-form-control" value="<?= e($r['descrizione'] ?? '') ?>" <?= $readonly ?>>
                </td>
                <td>
                  <input type="number" name="riga_quantita_offerta[]" class="qk-form-control r-qty" step="0.001" value="<?= isset($r['quantita_offerta']) ? (float)$r['quantita_offerta'] : '1' ?>" required <?= $readonly ?> data-requested="<?= isset($r['qta_richiesta_rfq']) ? (float)$r['qta_richiesta_rfq'] : '' ?>">
                  <div class="qty-delta-badge" style="font-size:.78rem;margin-top:.25rem;display:none"></div>
                </td>
                <td>
                  <input type="hidden" name="riga_unita_misura_id[]" value="<?= e($r['unita_misura_id'] ?? '') ?>">
                  <span class="qk-text-muted"><?= e($r['uom'] ?? '—') ?></span>
                </td>
                <td>
                  <input type="number" name="riga_prezzo_unitario[]" class="qk-form-control" step="0.0001" placeholder="0.00" value="<?= ($r['prezzo_unitario'] !== null && $r['prezzo_unitario'] !== '') ? (float)$r['prezzo_unitario'] : '' ?>" required <?= $readonly ?>>
                </td>
                <td class="col-sconto">
                  <input type="number" name="riga_sconto_perc[]" class="qk-form-control" step="0.01" value="<?= isset($r['sconto_perc']) ? (float)$r['sconto_perc'] : '0' ?>" <?= $readonly ?>>
                </td>
                <td class="col-iva">
                  <input type="number" name="riga_iva_perc[]" class="qk-form-control" step="1" value="<?= isset($r['iva_perc']) ? (float)$r['iva_perc'] : '22' ?>" <?= $readonly ?>>
                </td>
                <td class="col-consegna">
                  <input type="date" name="riga_data_consegna_prevista[]" class="qk-form-control" value="<?= e($r['data_consegna_prevista'] ?? '') ?>" <?= $readonly ?>>
                </td>
                <td class="col-note">
                  <input type="text" name="riga_note[]" class="qk-form-control" value="<?= e($r['note'] ?? '') ?>" <?= $readonly ?>>
                  <input type="hidden" name="riga_temp_id[]" class="r-temp-id" value="<?= e($r['temp_id'] ?? $r['id'] ?? 'row_' . $i) ?>">
                </td>
                <?php if ($canEdit): ?>
                <td>
                  <?php if (empty($r['offerta_riga_padre_id'])): ?>
                    <button type="button" class="btn-qk btn-qk-xs btn-qk-secondary btn-split-riga" title="Sdoppia consegna (Split)" onclick="splitRiga(this, <?= $i ?>)">
                      <i class="fa-solid fa-code-branch"></i>
                    </button>
                  <?php else: ?>
                    <button type="button" class="btn-qk btn-qk-xs btn-qk-ghost text-danger" title="Rimuovi split" onclick="rimuoviSplit(this)">
                      <i class="fa-solid fa-xmark"></i>
                    </button>
                  <?php endif; ?>
                </td>
                <?php endif; ?>
              </tr>
              <?php endif; ?>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php if ($canEdit && empty($values['rfq_id'])): ?>
      <div class="mt-2 mb-4">
        <button type="button" class="btn-qk btn-qk-secondary btn-qk-sm" id="btn-add-riga">
          <i class="fa-solid fa-plus me-1"></i> Aggiungi Articolo
        </button>
      </div>
      <?php endif; ?>
    </div> <!-- Fine pane-articoli -->

    <!-- Pane: Spedizione & Contatti -->
    <div class="tab-pane d-none" id="pane-spedizione-contatti">
      <div class="qk-card mb-4">
        <div class="qk-card-body">
          <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:1.5rem;">
            
            <!-- Colonna 1: Spedizione (Read-Only da RFQ) -->
            <div>
              <h4 style="font-size:.9rem; text-transform:uppercase; letter-spacing:.05em; color:var(--qk-text-muted); margin-bottom:1rem; border-bottom:1px solid var(--qk-border); padding-bottom:.5rem;">Destinazione Merce (Dati RFQ di Origine)</h4>
              <?php if (empty($values['rfq_id'])): ?>
                <div class="qk-alert qk-alert-info mb-3">
                  <i class="fa-solid fa-circle-info"></i>
                  <span>Preventivo spontaneo. Nessun indirizzo di consegna specificato da RFQ.</span>
                </div>
              <?php else: ?>
                <div class="border rounded p-3 mb-3" style="background: var(--qk-bg-subtle); border-color: var(--qk-border) !important;">
                  <div class="qk-flex-row qk-justify-between mb-3">
                    <h6 class="mb-0 fw-bold" style="font-size: 0.85rem;">
                      <i class="fa-solid fa-truck-ramp-box me-1"></i>Dettagli consegna
                      <span id="addr-state-badge" style="font-size: 0.72rem; vertical-align: middle;"></span>
                    </h6>
                    <button type="button" class="btn-qk btn-qk-xs btn-qk-ghost text-danger" id="btn-cancel-edit-addr" title="Annulla modifiche / Ripristina valori anagrafica"><i class="fa-solid fa-rotate-left me-1"></i>Ripristina</button>
                  </div>

                  <input type="hidden" name="indirizzo_consegna_id" id="off-indirizzo-consegna-id" value="<?= e($values['indirizzo_consegna_id'] ?? '') ?>">
                  <input type="hidden" name="indirizzo_consegna_master_id" id="off-indirizzo-consegna-master-id" value="<?= e($values['indirizzo_consegna_master_id'] ?? '') ?>">

                  <div class="qk-form-group mb-2">
                    <label class="qk-form-label">Presso (c/o)</label>
                    <input type="text" name="consegna_presso" id="off-consegna-presso" class="qk-form-control" value="<?= e($values['consegna_presso'] ?? '') ?>" placeholder="Es. Magazzino logistica, Cantiere B">
                  </div>

                  <div class="qk-form-row mb-2">
                    <div class="qk-form-group" style="flex:3">
                      <label class="qk-form-label">Via/Piazza</label>
                      <input type="text" name="consegna_via" id="off-consegna-via" class="qk-form-control" value="<?= e($values['consegna_via'] ?? '') ?>" required>
                    </div>
                    <div class="qk-form-group" style="flex:1">
                      <label class="qk-form-label">Civico</label>
                      <input type="text" name="consegna_civico" id="off-consegna-civico" class="qk-form-control" value="<?= e($values['consegna_civico'] ?? '') ?>">
                    </div>
                  </div>

                  <div class="qk-form-row mb-2">
                    <div class="qk-form-group" style="flex:2">
                      <label class="qk-form-label">CAP</label>
                      <input type="text" name="consegna_cap" id="off-consegna-cap" class="qk-form-control" value="<?= e($values['consegna_cap'] ?? '') ?>" required>
                    </div>
                    <div class="qk-form-group" style="flex:4">
                      <label class="qk-form-label">Città</label>
                      <input type="text" name="consegna_citta" id="off-consegna-citta" class="qk-form-control" value="<?= e($values['consegna_citta'] ?? '') ?>" required>
                    </div>
                  </div>

                  <div class="qk-form-row mb-2">
                    <div class="qk-form-group">
                      <label class="qk-form-label">Provincia</label>
                      <input type="text" name="consegna_provincia" id="off-consegna-provincia" class="qk-form-control" value="<?= e($values['consegna_provincia'] ?? '') ?>" maxlength="2">
                    </div>
                    <div class="qk-form-group">
                      <label class="qk-form-label">Nazione</label>
                      <input type="text" name="consegna_nazione" id="off-consegna-nazione" class="qk-form-control" value="<?= e($values['consegna_nazione'] ?? 'IT') ?>" maxlength="2">
                    </div>
                  </div>

                  <div class="qk-form-row mb-2">
                    <div class="qk-form-group">
                      <label class="qk-form-label">Referente Consegna</label>
                      <input type="text" name="consegna_referente" id="off-consegna-referente" class="qk-form-control" value="<?= e($values['consegna_referente'] ?? '') ?>">
                    </div>
                    <div class="qk-form-group">
                      <label class="qk-form-label">Tel. Consegna</label>
                      <input type="text" name="consegna_telefono" id="off-consegna-telefono" class="qk-form-control" value="<?= e($values['consegna_telefono'] ?? '') ?>">
                    </div>
                  </div>

                  <div class="qk-form-group">
                    <label class="qk-form-label">Note Consegna</label>
                    <input type="text" name="consegna_note" id="off-consegna-note" class="qk-form-control" value="<?= e($values['consegna_note'] ?? '') ?>">
                  </div>
                </div>
              <?php endif; ?>
            </div>

            <!-- Colonna 2: Referente Fornitore -->
            <div>
              <h4 style="font-size:.9rem; text-transform:uppercase; letter-spacing:.05em; color:var(--qk-text-muted); margin-bottom:1rem; border-bottom:1px solid var(--qk-border); padding-bottom:.5rem;">Referente Fornitore (Commerciale)</h4>
              <div class="qk-form-group">
                <label class="qk-form-label">Alla cortese attenzione (Referente Fornitore)</label>
                <select name="contatto_id" id="off-contatto-id" class="qk-form-control" data-selected-id="<?= e($values['contatto_id'] ?? '') ?>" <?= $disabled ?>>
                  <option value="">Seleziona fornitore prima...</option>
                </select>
                
                <input type="hidden" name="contatto_master_id" id="off-contatto-master-id" value="<?= e($values['contatto_master_id'] ?? '') ?>">
                <input type="hidden" id="off-fornitore-telefono" value="<?= e($values['fornitore_telefono'] ?? '') ?>">
                <input type="hidden" id="off-fornitore-email" value="<?= e($values['fornitore_email'] ?? '') ?>">
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
                  <input type="text" name="contatto_nome" id="off-contatto-nome" class="qk-form-control" value="<?= e($values['contatto_nome'] ?? '') ?>" placeholder="Es. Ing. Mario Rossi">
                </div>

                <div class="qk-form-group mb-2">
                  <label class="qk-form-label">Telefono di contatto</label>
                  <input type="text" name="contatto_telefono" id="off-contatto-telefono" class="qk-form-control" placeholder="Es. +39 02 123456" value="<?= e($values['contatto_telefono'] ?? '') ?>">
                </div>

                <div class="qk-form-group mb-2">
                  <label class="qk-form-label">Email di contatto</label>
                  <input type="email" name="contatto_email" id="off-contatto-email" class="qk-form-control" placeholder="Es. mario.rossi@fornitore.com" value="<?= e($values['contatto_email'] ?? '') ?>">
                </div>
              </div>
              <div class="qk-form-group">
                <label class="qk-form-label">Listino di Acquisto di riferimento</label>
                <select name="listino_acquisto_id" id="off-listino-acquisto-id" class="qk-form-control" data-selected-id="<?= e($values['listino_acquisto_id'] ?? '') ?>" <?= $disabled ?>>
                  <option value="">Nessun listino specifico...</option>
                </select>
              </div>
            </div>

          </div>
        </div>
      </div>
    </div> <!-- Fine pane-spedizione-contatti -->

    <!-- Pane: Condizioni Commerciali -->
    <div class="tab-pane d-none" id="pane-condizioni-commerciali">
      <div class="qk-card mb-4">
        <div class="qk-card-body">
          <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:1.5rem;">
            <!-- Colonna: Date & Condizioni -->
            <div>
              <h4 style="font-size:.9rem; text-transform:uppercase; letter-spacing:.05em; color:var(--qk-text-muted); margin-bottom:1rem; border-bottom:1px solid var(--qk-border); padding-bottom:.5rem;">Date & Riferimenti</h4>
              <div class="qk-form-group">
                <label class="qk-form-label">Data Ricezione Preventivo</label>
                <input type="date" name="data_offerta" class="qk-form-control"
                       value="<?= e($values['data_offerta'] ?? date('Y-m-d')) ?>" <?= $readonly ?>>
              </div>

              <div class="qk-form-group">
                <label class="qk-form-label">Scadenza Validità Preventivo</label>
                <input type="date" name="data_scadenza_offerta" class="qk-form-control"
                       value="<?= e($values['data_scadenza_offerta'] ?? '') ?>" <?= $readonly ?>>
              </div>

              <div class="qk-form-group">
                <label class="qk-form-label">Valuta</label>
                <select name="valuta" class="qk-form-control" <?= $disabled ?>>
                  <option value="EUR" <?= ($values['valuta'] ?? 'EUR') === 'EUR' ? 'selected' : '' ?>>EUR (€)</option>
                  <option value="USD" <?= ($values['valuta'] ?? '') === 'USD' ? 'selected' : '' ?>>USD ($)</option>
                </select>
              </div>
            </div>

            <!-- Colonna: Pagamento & Trasporto -->
            <div>
              <h4 style="font-size:.9rem; text-transform:uppercase; letter-spacing:.05em; color:var(--qk-text-muted); margin-bottom:1rem; border-bottom:1px solid var(--qk-border); padding-bottom:.5rem;">Pagamento & Trasporto</h4>
              <div class="qk-form-group">
                <label class="qk-form-label">Metodo di Pagamento</label>
                <select name="metodo_pagamento_id" id="off-metodo-pagamento-id" class="qk-form-control" <?= $disabled ?>>
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
                <input type="text" name="condizioni_pagamento" id="off-condizioni-pagamento" class="qk-form-control"
                       placeholder="Es. 30gg fine mese"
                       value="<?= e($values['condizioni_pagamento'] ?? '') ?>" <?= $readonly ?>>
              </div>

              <div class="qk-form-group">
                <label class="qk-form-label">Resa Porto (Trasporto)</label>
                <select name="resa_porto" id="off-resa-porto" class="qk-form-control" <?= $disabled ?>>
                  <option value="">Seleziona resa porto...</option>
                  <option value="porto_franco" <?= ($values['resa_porto'] ?? '') === 'porto_franco' ? 'selected' : '' ?>>Porto Franco (A carico venditore)</option>
                  <option value="porto_assegnato" <?= ($values['resa_porto'] ?? '') === 'porto_assegnato' ? 'selected' : '' ?>>Porto Assegnato (A carico acquirente)</option>
                </select>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div> <!-- Fine pane-condizioni-commerciali -->

    <!-- Pane: Note -->
    <div class="tab-pane d-none" id="pane-note">
      <div class="qk-form-grid mb-4">
        <div class="qk-card" style="margin:0">
          <div class="qk-card-header"><span class="qk-card-title">Note per il Fornitore</span></div>
          <div class="qk-card-body">
            <textarea name="note_fornitore" class="qk-form-control" rows="3" <?= $readonly ?>><?= e($values['note_fornitore'] ?? '') ?></textarea>
          </div>
        </div>
        <div class="qk-card" style="margin:0">
          <div class="qk-card-header"><span class="qk-card-title">Note Interne</span></div>
          <div class="qk-card-body">
            <textarea name="note_interne" class="qk-form-control" rows="3" <?= $readonly ?>><?= e($values['note_interne'] ?? '') ?></textarea>
          </div>
        </div>
      </div>
    </div> <!-- Fine pane-note -->
  </div> <!-- Fine tab-content -->


</form>

<?php if ($canEdit && empty($values['rfq_id'])): ?>
<template id="tpl-riga">
  <tr class="offerta-riga" data-idx="__IDX__">
    <td>
      <input type="hidden" name="riga_prodotto_id[]" class="r-prodotto-id" value="">
      <input type="hidden" name="riga_rfq_riga_id[]" class="r-rfq-riga-id" value="">
      <div class="qk-m2o-wrap" style="position:relative">
        <div class="qk-m2o-input-row">
          <input type="text" class="qk-m2o-input r-prodotto-label"
                 placeholder="Cerca articolo…" autocomplete="off" required>
          <button type="button" class="qk-m2o-clear d-none r-prodotto-clear">&times;</button>
        </div>
        <div class="qk-m2o-dropdown shadow-sm r-prodotto-dropdown"></div>
      </div>
    </td>
    <td class="col-sku">
      <input type="text" name="riga_sku_fornitore[]" class="qk-form-control r-sku" placeholder="SKU">
    </td>
    <td class="col-desc">
      <input type="text" name="riga_descrizione[]" class="qk-form-control r-desc" placeholder="Descrizione riga…">
    </td>
    <td>
      <input type="number" name="riga_quantita_offerta[]" class="qk-form-control r-qty" step="0.001" value="1" required>
    </td>
    <td>
      <input type="hidden" name="riga_unita_misura_id[]" class="r-uom-id" value="">
      <span class="r-uom-label qk-text-muted" style="font-size:.85rem">—</span>
    </td>
    <td>
      <input type="number" name="riga_prezzo_unitario[]" class="qk-form-control r-prezzo" step="0.0001" placeholder="0.00" required>
    </td>
    <td class="col-sconto">
      <input type="number" name="riga_sconto_perc[]" class="qk-form-control r-sconto" step="0.01" value="0">
    </td>
    <td class="col-iva">
      <input type="number" name="riga_iva_perc[]" class="qk-form-control r-iva" step="1" value="22">
    </td>
    <td class="col-consegna">
      <input type="date" name="riga_data_consegna_prevista[]" class="qk-form-control r-consegna">
    </td>
    <td class="col-note">
      <input type="text" name="riga_note[]" class="qk-form-control r-note">
    </td>
    <td>
      <button type="button" class="btn-qk btn-qk-xs btn-qk-ghost r-rimuovi" title="Rimuovi riga">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </td>
  </tr>
</template>
<?php endif; ?>

<script>
(function () {
  'use strict';

  const API_FORNITORI = <?= json_encode(url('purchases/rfq/api/fornitori')) ?>;
  const API_PRODOTTI  = <?= json_encode(url('purchases/rfq/api/prodotti')) ?>;
  const CAN_EDIT      = <?= $canEdit ? 'true' : 'false' ?>;
  const IS_SPONTANEOUS = <?= empty($values['rfq_id']) ? 'true' : 'false' ?>;

  let fornitoreSel = { id: '<?= e($values['fornitore_id']) ?>', nome: '' };
  let rigaIdx      = 0;

  function autocomplete({ inputEl, dropdownEl, clearEl, hiddenEl, fetchFn, onSelect }) {
    let timer = null;

    inputEl.addEventListener('input', () => {
      clearTimeout(timer);
      const q = inputEl.value.trim();
      if (q.length < 1) { dropdownEl.innerHTML = ''; dropdownEl.style.display = 'none'; return; }
      timer = setTimeout(async () => {
        const items = await fetchFn(q);
        if (!items.length) {
          dropdownEl.innerHTML = '<div class="qk-m2o-item qk-text-muted" style="font-size:.83rem">Nessun risultato</div>';
        } else {
          dropdownEl.innerHTML = items.map(it =>
            `<div class="qk-m2o-item" data-id="${it.id}" data-label="${it.label?.replace(/"/g,'&quot;') ?? ''}" data-extra='${JSON.stringify(it)}'>${it.label ?? ''}</div>`
          ).join('');
        }
        dropdownEl.style.display = 'block';

        dropdownEl.querySelectorAll('.qk-m2o-item[data-id]').forEach(el => {
          el.addEventListener('mousedown', e => {
            e.preventDefault();
            const data = JSON.parse(el.dataset.extra);
            hiddenEl.value  = el.dataset.id;
            inputEl.value   = el.dataset.label;
            dropdownEl.style.display = 'none';
            if (clearEl) { clearEl.classList.remove('d-none'); }
            onSelect && onSelect(data);
          });
        });
      }, 220);
    });

    inputEl.addEventListener('blur', () => {
      setTimeout(() => { dropdownEl.style.display = 'none'; }, 200);
    });

    if (clearEl) {
      clearEl.addEventListener('click', () => {
        hiddenEl.value = '';
        inputEl.value  = '';
        clearEl.classList.add('d-none');
        dropdownEl.style.display = 'none';
        onSelect && onSelect(null);
      });
    }
  }

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
    }));
  }

  if (CAN_EDIT && IS_SPONTANEOUS) {
    // Autocomplete fornitore
    const fInput    = document.getElementById('off-fornitore-label');
    const fHidden   = document.getElementById('off-fornitore-id');
    const fDropdown = document.getElementById('off-fornitore-dropdown');
    const fClear    = document.getElementById('off-fornitore-clear');

    if (fInput) {
      autocomplete({
        inputEl:    fInput,
        dropdownEl: fDropdown,
        clearEl:    fClear,
        hiddenEl:   fHidden,
        fetchFn:    fetchFornitori,
        onSelect:   (sel) => {
          fornitoreSel = sel ? { id: String(sel.id), nome: sel.label } : { id: '', nome: '' };
          loadFornitoreDettagli();
        },
      });
      if (fHidden.value) fClear?.classList.remove('d-none');
    }

    // Righe dinamiche
    const tbody   = document.querySelector('#form-offerta table.qk-table tbody');
    const tplRiga = document.getElementById('tpl-riga');
    const btnAdd  = document.getElementById('btn-add-riga');

    function addRiga() {
      const clone = tplRiga.content.cloneNode(true);
      const tr    = clone.querySelector('tr');
      const idx   = rigaIdx++;
      tr.dataset.idx = idx;

      tbody.appendChild(clone);
      const newTr = tbody.querySelector(`tr[data-idx="${idx}"]`);
      initRiga(newTr);
      return newTr;
    }

    function initRiga(tr) {
      const pInput    = tr.querySelector('.r-prodotto-label');
      const pHidden   = tr.querySelector('.r-prodotto-id');
      const pDropdown = tr.querySelector('.r-prodotto-dropdown');
      const pClear    = tr.querySelector('.r-prodotto-clear');

      autocomplete({
        inputEl:    pInput,
        dropdownEl: pDropdown,
        clearEl:    pClear,
        hiddenEl:   pHidden,
        fetchFn:    fetchProdotti,
        onSelect:   (p) => {
          if (p) {
            tr.querySelector('.r-sku').value = p.sku_fornitore ?? '';
            tr.querySelector('.r-uom-id').value = p.uom_id ?? '';
            tr.querySelector('.r-uom-label').textContent = p.uom ?? '—';
            tr.querySelector('.r-prezzo').value = p.prezzo ?? '';
          } else {
            tr.querySelector('.r-sku').value = '';
            tr.querySelector('.r-uom-id').value = '';
            tr.querySelector('.r-uom-label').textContent = '—';
            tr.querySelector('.r-prezzo').value = '';
          }
        }
      });

      tr.querySelector('.r-rimuovi').addEventListener('click', () => {
        tr.remove();
      });

      const defaultDateInput = document.getElementById('off-data-consegna-default');
      if (defaultDateInput && defaultDateInput.value) {
        const rowDate = tr.querySelector('.r-consegna');
        if (rowDate && !rowDate.value) {
          rowDate.value = defaultDateInput.value;
        }
      }
    }

    if (btnAdd) {
      btnAdd.addEventListener('click', addRiga);
    }

    // Inizializza le righe esistenti
    tbody.querySelectorAll('tr.offerta-riga').forEach(tr => {
      initRiga(tr);
    });

    // Se non ci sono righe, aggiungine una di default
    if (tbody.querySelectorAll('tr.offerta-riga').length === 0) {
      addRiga();
    }
  }

  // Funzione per aggiornare il badge dello scostamento quantità
  function updateQtyDeviation(input) {
    const requested = parseFloat(input.dataset.requested);
    const badge = input.nextElementSibling;
    if (isNaN(requested) || !badge || !badge.classList.contains('qty-delta-badge')) {
      return;
    }
    const current = parseFloat(input.value) || 0;
    const diff = current - requested;
    if (diff === 0) {
      input.style.borderColor = '';
      badge.style.display = 'none';
      badge.innerHTML = '';
    } else {
      input.style.borderColor = 'var(--qk-warning)'; // warning orange
      badge.style.display = 'block';
      const sign = diff > 0 ? '+' : '';
      const pct = ((diff / requested) * 100).toFixed(0);
      badge.innerHTML = `<span style="color:var(--qk-warning);font-weight:600"><i class="fa-solid fa-triangle-exclamation"></i> Scostamento: ${sign}${diff} (${sign}${pct}%)</span>`;
    }
  }

  // Monitora tutti gli input quantità per lo scostamento
  document.querySelectorAll('.r-qty').forEach(input => {
    input.addEventListener('input', () => updateQtyDeviation(input));
    updateQtyDeviation(input);
  });

  // Sostituto handlers per RFQ-linked
  window.toggleSostitutoRow = function(checkbox, idx) {
    const origContainer = document.getElementById('prod-originale-' + idx);
    const sostContainer = document.getElementById('prod-sostituto-' + idx);
    const labelSost = sostContainer.querySelector('.r-prodotto-label');
    const valueSost = sostContainer.querySelector('.r-prodotto-id');
    const hiddenFlag = checkbox.parentNode.parentNode.querySelector('.flag-sostituto-value');
    
    if (checkbox.checked) {
      origContainer.style.display = 'none';
      sostContainer.style.display = 'block';
      labelSost.setAttribute('required', 'required');
      hiddenFlag.value = '1';
    } else {
      origContainer.style.display = 'block';
      sostContainer.style.display = 'none';
      labelSost.removeAttribute('required');
      hiddenFlag.value = '0';
      // Ripristina l'articolo originale
      valueSost.value = checkbox.dataset.origId;
      labelSost.value = checkbox.dataset.origLabel;
      const clearBtn = sostContainer.querySelector('.r-prodotto-clear');
      if (CAN_EDIT && clearBtn) clearBtn.classList.remove('d-none');
    }
  };

  // Inizializza gli autocomplete per i sostituti se modificabile
  if (CAN_EDIT && !IS_SPONTANEOUS) {
    // Corretto 15/07/2026 (Revisione Ciclo Acquisti §3bis, punto B.2): il campo
    // reale renderizzato da csrf_field() si chiama "csrf_token", non "_csrf" —
    // il selettore precedente non trovava mai nulla, CSRF_TOKEN era sempre
    // una stringa vuota. Allineato alla convenzione già usata nel resto dell'app.
    const CSRF_TOKEN = document.querySelector('input[name=csrf_token]')?.value ?? '';
    document.querySelectorAll('.prod-sostituto-container').forEach(container => {
      const pInput    = container.querySelector('.r-prodotto-label');
      const pHidden   = container.querySelector('.r-prodotto-id');
      const pDropdown = container.querySelector('.r-prodotto-dropdown');
      const pClear    = container.querySelector('.r-prodotto-clear');
      const tr        = container.closest('tr');

      autocomplete({
        inputEl:    pInput,
        dropdownEl: pDropdown,
        clearEl:    pClear,
        hiddenEl:   pHidden,
        fetchFn:    fetchProdotti,
        onSelect:   (p) => {
          if (p) {
            tr.querySelector('.r-sku').value = p.sku_fornitore ?? '';
            tr.querySelector('.r-uom-id').value = p.uom_id ?? '';
            tr.querySelector('.r-uom-label').textContent = p.uom ?? '—';
            tr.querySelector('.r-prezzo').value = p.prezzo ?? '';
          }
        }
      });

      // Tasto crea prodotto al volo per sostituto
      const btnCrea = container.querySelector('.btn-quick-crea-prod');
      if (btnCrea) {
        btnCrea.addEventListener('click', async () => {
          const nome = prompt("Inserisci il nome del nuovo prodotto:");
          if (!nome || nome.trim() === '') return;

          try {
            const formData = new FormData();
            formData.append('nome', nome);
            formData.append('csrf_token', CSRF_TOKEN);

            const res = await fetch(<?= json_encode(url('purchases/rfq/api/crea-prodotto')) ?>, {
              method: 'POST',
              body: formData
            });
            const data = await res.json();
            if (data.ok) {
              pHidden.value = data.id;
              pInput.value = `${data.nome} (${data.sku_interno})`;
              pClear.classList.remove('d-none');
              tr.querySelector('.r-sku').value = data.sku_interno;
            } else {
              alert("Errore: " + data.error);
            }
          } catch (err) {
            alert("Errore di connessione durante la creazione del prodotto.");
          }
        });
      }
    });
  }

  // Sdoppiamento riga
  window.splitRiga = function(button, idx) {
    const tr = button.closest('tr');
    const clone = tr.cloneNode(true);
    
    clone.style.background = 'rgba(0,0,0,0.02)';
    clone.classList.add('riga-split-figlia');
    
    const tempParentId = tr.getAttribute('data-temp-id') || ('row_' + idx);
    clone.querySelector('.r-padre-id').value = tempParentId;
    
    const childTempId = 'split_' + Math.random().toString(36).substr(2, 9);
    clone.setAttribute('data-temp-id', childTempId);
    
    clone.querySelector('.r-temp-id').value = childTempId;
    clone.querySelector('.r-qty').value = '0';
    clone.querySelector('.r-qty').dataset.requested = '';
    const warningBadge = clone.querySelector('.qty-delta-badge');
    if (warningBadge) {
      warningBadge.style.display = 'none';
      warningBadge.innerHTML = '';
    }
    
    const actionsTd = clone.querySelector('td:last-child');
    actionsTd.innerHTML = `
      <button type="button" class="btn-qk btn-qk-xs btn-qk-ghost text-danger" title="Rimuovi split" onclick="rimuoviSplit(this)">
        <i class="fa-solid fa-xmark"></i>
      </button>
    `;
    
    tr.after(clone);
    
    const newQty = clone.querySelector('.r-qty');
    newQty.addEventListener('input', () => updateQtyDeviation(newQty));
  };

  window.rimuoviSplit = function(button) {
    button.closest('tr').remove();
  };

  /* ── Caricamento dinamico dettagli fornitore (contatti, listini) ────── */
  const contSelect = document.getElementById('off-contatto-id');
  const contNomeInput = document.getElementById('off-contatto-nome');
  const contTelInput = document.getElementById('off-contatto-telefono');
  const contEmailInput = document.getElementById('off-contatto-email');
  const fornTelHidden = document.getElementById('off-fornitore-telefono');
  const fornEmailHidden = document.getElementById('off-fornitore-email');
  const listiniSelect = document.getElementById('off-listino-acquisto-id');
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
    const fornitoreId = document.getElementById('off-fornitore-id')?.value;
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

      loadedContacts = data.contatti || [];

      if (fornTelHidden) fornTelHidden.value = data.fornitore?.telefono || '';
      if (fornEmailHidden) fornEmailHidden.value = data.fornitore?.email || data.fornitore?.cellulare || '';

      const condPag = document.getElementById('off-condizioni-pagamento');
      if (condPag && !condPag.value && data.fornitore?.condizioni_pagamento) {
        condPag.value = data.fornitore.condizioni_pagamento;
      }

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

  if (contSelect) {
    contSelect.addEventListener('change', () => {
      const selectedId = contSelect.value;
      if (!selectedId) {
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

  /* ── Gestione interattiva Indirizzo Consegna ───────────────────────── */
  const indSelect = document.getElementById('off-indirizzo-consegna-id');
  let loadedAddress = null; // l'indirizzo master ereditato

  async function loadOriginalAddress() {
    const masterAddrId = document.getElementById('off-indirizzo-consegna-master-id')?.value;
    
    if (!masterAddrId) return;

    try {
      const res = await fetch(<?= json_encode(url('purchases/rfq/api/azienda-indirizzi')) ?> + '?indirizzo_id=' + masterAddrId);
      const data = await res.json();
      if (data.ok && data.indirizzo) {
        loadedAddress = data.indirizzo;
        syncAddressUI();
      }
    } catch (e) {
      console.error(e);
    }
  }

  function syncAddressUI() {
    if (!loadedAddress) return;
    const inpVia = document.getElementById('off-consegna-via');
    if (!inpVia) return;

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

    checkField(document.getElementById('off-consegna-via'), loadedAddress.via);
    checkField(document.getElementById('off-consegna-civico'), loadedAddress.civico);
    checkField(document.getElementById('off-consegna-cap'), loadedAddress.cap);
    checkField(document.getElementById('off-consegna-citta'), loadedAddress.citta);
    checkField(document.getElementById('off-consegna-provincia'), loadedAddress.provincia);
    checkField(document.getElementById('off-consegna-nazione'), loadedAddress.nazione || 'IT');
    checkField(document.getElementById('off-consegna-presso'), loadedAddress.presso);
    checkField(document.getElementById('off-consegna-referente'), loadedAddress.referente_consegna);
    checkField(document.getElementById('off-consegna-telefono'), loadedAddress.telefono_consegna);
    checkField(document.getElementById('off-consegna-note'), loadedAddress.note_consegna);

    const badge = document.getElementById('addr-state-badge');
    const resetBtn = document.getElementById('btn-cancel-edit-addr');

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

  [
    'off-consegna-presso', 'off-consegna-via', 'off-consegna-civico', 'off-consegna-cap',
    'off-consegna-citta', 'off-consegna-provincia', 'off-consegna-nazione',
    'off-consegna-referente', 'off-consegna-telefono', 'off-consegna-note'
  ].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', syncAddressUI);
  });

  const btnCancelEditAddr = document.getElementById('btn-cancel-edit-addr');
  if (btnCancelEditAddr) {
    btnCancelEditAddr.addEventListener('click', () => {
      if (!loadedAddress) return;
      
      const elVia = document.getElementById('off-consegna-via');
      const elCiv = document.getElementById('off-consegna-civico');
      const elCap = document.getElementById('off-consegna-cap');
      const elCit = document.getElementById('off-consegna-citta');
      const elPrv = document.getElementById('off-consegna-provincia');
      const elNaz = document.getElementById('off-consegna-nazione');
      const elPre = document.getElementById('off-consegna-presso');
      const elRef = document.getElementById('off-consegna-referente');
      const elTel = document.getElementById('off-consegna-telefono');
      const elNot = document.getElementById('off-consegna-note');

      if (elVia) elVia.value = loadedAddress.via || '';
      if (elCiv) elCiv.value = loadedAddress.civico || '';
      if (elCap) elCap.value = loadedAddress.cap || '';
      if (elCit) elCit.value = loadedAddress.citta || '';
      if (elPrv) elPrv.value = loadedAddress.provincia || '';
      if (elNaz) elNaz.value = loadedAddress.nazione || 'IT';
      if (elPre) elPre.value = loadedAddress.presso || '';
      if (elRef) elRef.value = loadedAddress.referente_consegna || '';
      if (elTel) elTel.value = loadedAddress.telefono_consegna || '';
      if (elNot) elNot.value = loadedAddress.note_consegna || '';

      syncAddressUI();
    });
  }

  loadOriginalAddress();

  // Implement Read-only Lock for status not 'in_valutazione'
  const quoteStato = <?= json_encode($values['stato'] ?? 'in_valutazione') ?>;
  if (quoteStato !== 'in_valutazione') {
    [
      contSelect, contNomeInput, contTelInput, contEmailInput,
      document.getElementById('off-consegna-presso'),
      document.getElementById('off-consegna-via'),
      document.getElementById('off-consegna-civico'),
      document.getElementById('off-consegna-cap'),
      document.getElementById('off-consegna-citta'),
      document.getElementById('off-consegna-provincia'),
      document.getElementById('off-consegna-nazione'),
      document.getElementById('off-consegna-referente'),
      document.getElementById('off-consegna-telefono'),
      document.getElementById('off-consegna-note')
    ].forEach(el => {
      if (el) {
        el.disabled = true;
        el.readOnly = true;
      }
    });
    if (btnCancelEditCont) btnCancelEditCont.style.display = 'none';
    if (btnCancelEditAddr) btnCancelEditAddr.style.display = 'none';
  }

  loadFornitoreDettagli();

  /* ── Mostra/Nascondi Colonne ───────────────────────────────────────── */
  const btnColSettings = document.getElementById('btn-col-settings');
  const colSettingsDropdown = document.getElementById('col-settings-dropdown');
  const chkSku = document.getElementById('chk-show-sku');
  const chkDesc = document.getElementById('chk-show-desc');
  const chkSconto = document.getElementById('chk-show-sconto');
  const chkIva = document.getElementById('chk-show-iva');
  const chkConsegna = document.getElementById('chk-show-consegna');
  const chkNote = document.getElementById('chk-show-note');

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
    const showSku = chkSku ? chkSku.checked : true;
    const showDesc = chkDesc ? chkDesc.checked : true;
    const showSconto = chkSconto ? chkSconto.checked : true;
    const showIva = chkIva ? chkIva.checked : true;
    const showConsegna = chkConsegna ? chkConsegna.checked : true;
    const showNote = chkNote ? chkNote.checked : true;

    document.querySelectorAll('.col-sku').forEach(el => el.style.display = showSku ? '' : 'none');
    document.querySelectorAll('.col-desc').forEach(el => el.style.display = showDesc ? '' : 'none');
    document.querySelectorAll('.col-sconto').forEach(el => el.style.display = showSconto ? '' : 'none');
    document.querySelectorAll('.col-iva').forEach(el => el.style.display = showIva ? '' : 'none');
    document.querySelectorAll('.col-consegna').forEach(el => el.style.display = showConsegna ? '' : 'none');
    document.querySelectorAll('.col-note').forEach(el => el.style.display = showNote ? '' : 'none');

    localStorage.setItem('off_show_sku', showSku ? '1' : '0');
    localStorage.setItem('off_show_desc', showDesc ? '1' : '0');
    localStorage.setItem('off_show_sconto', showSconto ? '1' : '0');
    localStorage.setItem('off_show_iva', showIva ? '1' : '0');
    localStorage.setItem('off_show_consegna', showConsegna ? '1' : '0');
    localStorage.setItem('off_show_note', showNote ? '1' : '0');
  }

  [chkSku, chkDesc, chkSconto, chkIva, chkConsegna, chkNote].forEach(chk => {
    if (chk) chk.addEventListener('change', updateColumnVisibility);
  });

  if (chkSku) {
    const pref = localStorage.getItem('off_show_sku');
    chkSku.checked = (pref === null || pref === '1');
  }
  if (chkDesc) {
    const pref = localStorage.getItem('off_show_desc');
    chkDesc.checked = (pref === null || pref === '1');
  }
  if (chkSconto) {
    const pref = localStorage.getItem('off_show_sconto');
    chkSconto.checked = (pref === '1');
  }
  if (chkIva) {
    const pref = localStorage.getItem('off_show_iva');
    chkIva.checked = (pref === null || pref === '1');
  }
  if (chkConsegna) {
    const pref = localStorage.getItem('off_show_consegna');
    chkConsegna.checked = (pref === '1');
  }
  if (chkNote) {
    const pref = localStorage.getItem('off_show_note');
    chkNote.checked = (pref === null || pref === '1');
  }
  updateColumnVisibility();

  // Override template and split trigger to run visibility update
  const origAddRiga = window.addRiga;
  if (typeof origAddRiga === 'function') {
    window.addRiga = function() {
      const res = origAddRiga();
      updateColumnVisibility();
      return res;
    };
  }
  const origSplitRiga = window.splitRiga;
  if (typeof origSplitRiga === 'function') {
    window.splitRiga = function(button, idx) {
      origSplitRiga(button, idx);
      updateColumnVisibility();
    };
  }

  // Gestione propagazione Data Consegna di Testata (silente)
  const defaultDateInput = document.getElementById('off-data-consegna-default');
  if (defaultDateInput) {
    let prevDefaultDate = defaultDateInput.value;
    defaultDateInput.addEventListener('change', () => {
      const newDefaultDate = defaultDateInput.value;
      document.querySelectorAll('tr.offerta-riga').forEach(tr => {
        const rowDateInput = tr.querySelector('.r-consegna');
        if (rowDateInput) {
          const currentVal = rowDateInput.value;
          // Propaga se il campo è vuoto, oppure se corrispondeva al valore di default precedente
          if (!currentVal || currentVal === prevDefaultDate) {
            rowDateInput.value = newDefaultDate;
          }
        }
      });
      prevDefaultDate = newDefaultDate;
    });
  }

  /* ── Switcher Tab Odoo-Style ───────────────────────────────────────── */
  window.switchOffertaTab = function(tabId) {
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
})();
</script>

<?php if ($isEdit && $isInValutazione): ?>
<div id="modal-rifiuta-offerta"
     style="display:none;position:fixed;inset:0;z-index:1050;
            background:var(--qk-overlay);align-items:center;justify-content:center">
  <div style="background:var(--qk-card-bg);border:1px solid var(--qk-border);
              border-radius:var(--qk-radius-lg);padding:1.5rem;width:100%;max-width:480px">
    <div class="qk-flex-row qk-justify-between" style="margin-bottom:1rem">
      <strong style="display:inline-flex;align-items:center;gap:.5rem"><i class="fa-solid fa-ban text-danger"></i> Seleziona Motivo Rifiuto</strong>
      <button type="button" onclick="document.getElementById('modal-rifiuta-offerta').style.display='none'"
              style="background:none;border:none;cursor:pointer;font-size:1.2rem;color:var(--qk-text-muted)">&times;</button>
    </div>
    
    <form method="post" action="<?= url('purchases/quotes/action') ?>" style="margin:0">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="azione" value="rifiuta">
      
      <div class="qk-form-group">
        <label class="qk-form-label">Motivo Rifiuto <span style="color:var(--qk-danger)">*</span></label>
        <select name="motivo_rifiuto_select" id="motivo_rifiuto_select" class="qk-form-control" onchange="toggleAltroMotivo(this.value)" required>
          <option value="">-- Seleziona un motivo --</option>
          <option value="Prezzo troppo alto">Prezzo troppo alto</option>
          <option value="Tempi di consegna troppo lunghi">Tempi di consegna troppo lunghi</option>
          <option value="Proposta non di interesse">Proposta non di interesse</option>
          <option value="Fuori budget">Fuori budget</option>
          <option value="Altro">Altro (specificare)</option>
        </select>
      </div>

      <div class="qk-form-group" id="altro-motivo-group" style="display:none">
        <label class="qk-form-label">Specificare motivo <span style="color:var(--qk-danger)">*</span></label>
        <input type="text" name="motivo_rifiuto_altro" id="motivo_rifiuto_altro" class="qk-form-control" placeholder="Es. Caratteristiche chimiche non conformi">
      </div>
      
      <div class="qk-flex-row qk-justify-end qk-gap-sm" style="margin-top:1.5rem">
        <button type="button" class="btn-qk btn-qk-ghost" onclick="document.getElementById('modal-rifiuta-offerta').style.display='none'">
          Annulla
        </button>
        <button type="submit" class="btn-qk btn-qk-danger">
          <i class="fa-solid fa-circle-xmark"></i> Conferma Rifiuto
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function toggleAltroMotivo(val) {
  const group = document.getElementById('altro-motivo-group');
  const input = document.getElementById('motivo_rifiuto_altro');
  if (val === 'Altro') {
    group.style.display = 'block';
    input.setAttribute('required', 'required');
  } else {
    group.style.display = 'none';
    input.removeAttribute('required');
    input.value = '';
  }
}
</script>
<?php endif; ?>