<?php
/**
 * purchases/offerte/_form.php
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

<div class="qk-page-header" style="flex-wrap:wrap;gap:.5rem">
  <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
    <h1 class="qk-page-title" style="margin:0">
      <?= $isEdit ? 'Preventivo Fornitore ' . e($offerta['numero']) : 'Registra Preventivo Fornitore' ?>
    </h1>
    <?php if ($isEdit): ?>
      <span class="qk-badge <?= $statoLabel[0] ?>"><?= e($statoLabel[1]) ?></span>
      <?php if ($stato === 'rifiutata' && !empty($offerta['motivo_rifiuto'])): ?>
        <span class="qk-text-danger ms-2" style="font-size:.9rem"><strong>Motivo:</strong> <?= e($offerta['motivo_rifiuto']) ?></span>
      <?php endif; ?>
    <?php else: ?>
      <span class="qk-text-muted" style="font-size:.82rem"><?= e($anteprima_numero) ?></span>
    <?php endif; ?>
  </div>

  <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
    <?php if ($isEdit && $isInValutazione): ?>
      <!-- Accetta ed eventualmente genera ordine -->
      <form method="post" action="<?= url('purchases/offerte/action') ?>" class="d-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="azione" value="accetta">
        <input type="hidden" name="genera_ordine" value="1">
        <button type="submit" class="btn-qk btn-qk-success">
          <i class="fa-solid fa-circle-check"></i> Accetta e Genera Ordine
        </button>
      </form>
      <!-- Rifiuta (triggers modal) -->
      <button type="button" class="btn-qk btn-qk-ghost text-danger" onclick="document.getElementById('modal-rifiuta-offerta').style.display='flex'">
        <i class="fa-solid fa-ban"></i> Rifiuta
      </button>
    <?php endif; ?>

    <a href="<?= url('purchases/offerte') ?>" class="btn-qk btn-qk-ghost">
      <i class="fa-solid fa-arrow-left"></i> Elenco Preventivi
    </a>
  </div>
</div>

<form method="post" action="<?= $isEdit ? url('purchases/offerte/edit') . '?id=' . $id : url('purchases/offerte/create') ?>" id="form-offerta" enctype="multipart/form-data">
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
      <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:1.5rem;">
        <!-- Colonna 1: Fornitore & Riferimenti -->
        <div>
          <h4 style="font-size:.9rem; text-transform:uppercase; letter-spacing:.05em; color:var(--qk-text-muted); margin-bottom:1rem; border-bottom:1px solid var(--qk-border); padding-bottom:.5rem;">Fornitore & Riferimenti</h4>
          <!-- RFQ di origine -->
          <div class="qk-form-group">
            <label class="qk-form-label">RFQ Collegata</label>
            <?php if (!empty($values['rfq_id'])): ?>
              <div class="qk-form-control-plaintext">
                <a href="<?= url('purchases/rfq/edit') . '?id=' . $values['rfq_id'] ?>" class="qk-link-strong">
                  <i class="fa-solid fa-arrow-up-right-from-square"></i> RFQ #<?= e($offerta['rfq_numero'] ?? $values['rfq_id']) ?>
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
              <div class="mb-2" style="display:flex;align-items:center;gap:.5rem">
                <a href="<?= url(ltrim($values['allegato_path'], '/')) ?>" target="_blank" class="btn-qk btn-qk-ghost text-primary py-1 px-2" style="font-size:.82rem; height:auto; display:inline-flex; align-items:center; gap:.25rem">
                  <i class="fa-solid fa-file-pdf"></i> Apri allegato
                </a>
                <?php if ($canEdit): ?>
                  <label class="d-inline-flex align-items-center gap-1 text-danger cursor-pointer" style="font-size:.82rem; margin:0">
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
        </div>

        <!-- Colonna 2: Referente & Recapiti -->
        <div>
          <h4 style="font-size:.9rem; text-transform:uppercase; letter-spacing:.05em; color:var(--qk-text-muted); margin-bottom:1rem; border-bottom:1px solid var(--qk-border); padding-bottom:.5rem;">Referente & Recapiti</h4>
          <div class="qk-form-group">
            <label class="qk-form-label">Alla cortese attenzione (Referente)</label>
            <select name="contatto_id" id="off-contatto-id" class="qk-form-control" data-selected-id="<?= e($values['contatto_id'] ?? '') ?>" <?= $disabled ?>>
              <option value="">Seleziona fornitore prima...</option>
            </select>
            <input type="hidden" name="contatto_nome" id="off-contatto-nome" value="<?= e($values['contatto_nome'] ?? '') ?>">
          </div>
          <div class="qk-form-group">
            <label class="qk-form-label">Telefono di contatto</label>
            <input type="text" name="contatto_telefono" id="off-contatto-telefono" class="qk-form-control"
                   placeholder="Es. +39 02 123456"
                   value="<?= e($values['contatto_telefono'] ?? $values['fornitore_telefono'] ?? '') ?>"
                   <?= $readonly ?>>
            <input type="hidden" name="fornitore_telefono" id="off-fornitore-telefono" value="<?= e($values['fornitore_telefono'] ?? '') ?>">
          </div>
          <div class="qk-form-group">
            <label class="qk-form-label">Email di contatto</label>
            <input type="email" name="contatto_email" id="off-contatto-email" class="qk-form-control"
                   placeholder="Es. info@fornitore.com"
                   value="<?= e($values['contatto_email'] ?? $values['fornitore_email'] ?? '') ?>"
                   <?= $readonly ?>>
            <input type="hidden" name="fornitore_email" id="off-fornitore-email" value="<?= e($values['fornitore_email'] ?? '') ?>">
          </div>
          <div class="qk-form-group">
            <label class="qk-form-label">Listino di Acquisto di riferimento</label>
            <select name="listino_acquisto_id" id="off-listino-acquisto-id" class="qk-form-control" data-selected-id="<?= e($values['listino_acquisto_id'] ?? '') ?>" <?= $disabled ?>>
              <option value="">Nessun listino specifico...</option>
            </select>
          </div>
        </div>

        <!-- Colonna 3: Date & Condizioni -->
        <div>
          <h4 style="font-size:.9rem; text-transform:uppercase; letter-spacing:.05em; color:var(--qk-text-muted); margin-bottom:1rem; border-bottom:1px solid var(--qk-border); padding-bottom:.5rem;">Date & Condizioni</h4>
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
            <label class="qk-form-label">Valuta</label>
            <select name="valuta" class="qk-form-control" <?= $disabled ?>>
              <option value="EUR" <?= ($values['valuta'] ?? 'EUR') === 'EUR' ? 'selected' : '' ?>>EUR (€)</option>
              <option value="USD" <?= ($values['valuta'] ?? '') === 'USD' ? 'selected' : '' ?>>USD ($)</option>
            </select>
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

  <!-- Prodotti / Righe -->
  <div class="qk-card mb-4">
    <div class="qk-card-header">
      <span class="qk-card-title">
        <i class="fa-solid fa-list me-2"></i>Articoli Offerti
      </span>
    </div>
    <div class="qk-table-wrap">
      <table class="qk-table">
        <thead>
          <tr>
            <th style="min-width:280px; width:30%">Articolo</th>
            <th>SKU Fornitore</th>
            <th>Descrizione</th>
            <th>Quantità Offerta</th>
            <th>UdM</th>
            <th style="width:140px">Prezzo Unitario (€) <span style="color:var(--qk-danger)">*</span></th>
            <th style="width:100px">Sconto %</th>
            <th style="width:100px">IVA %</th>
            <th>Data Consegna Promessa</th>
            <th>Note</th>
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
            <td>
              <input type="text" name="riga_sku_fornitore[]" class="qk-form-control r-sku" value="<?= e($r['sku_fornitore'] ?? '') ?>">
            </td>
            <td>
              <input type="text" name="riga_descrizione[]" class="qk-form-control r-desc" value="<?= e($r['descrizione'] ?? '') ?>">
            </td>
            <td>
              <input type="number" name="riga_quantita_offerta[]" class="qk-form-control r-qty" step="0.001" value="<?= e($r['quantita_offerta'] ?? '1') ?>" required>
            </td>
            <td>
              <input type="hidden" name="riga_unita_misura_id[]" class="r-uom-id" value="<?= e($r['unita_misura_id'] ?? '') ?>">
              <span class="r-uom-label qk-text-muted"><?= e($r['uom'] ?? '—') ?></span>
            </td>
            <td>
              <input type="number" name="riga_prezzo_unitario[]" class="qk-form-control r-prezzo" step="0.0001" placeholder="0.00" value="<?= e($r['prezzo_unitario'] ?? '') ?>" required>
            </td>
            <td>
              <input type="number" name="riga_sconto_perc[]" class="qk-form-control r-sconto" step="0.01" value="<?= e($r['sconto_perc'] ?? '0') ?>">
            </td>
            <td>
              <input type="number" name="riga_iva_perc[]" class="qk-form-control r-iva" step="1" value="<?= e($r['iva_perc'] ?? '22') ?>">
            </td>
            <td>
              <input type="date" name="riga_data_consegna_prevista[]" class="qk-form-control r-consegna" value="<?= e($r['data_consegna_prevista'] ?? '') ?>">
            </td>
            <td>
              <input type="text" name="riga_note[]" class="qk-form-control r-note" value="<?= e($r['note'] ?? '') ?>">
            </td>
            <td>
              <button type="button" class="btn-qk btn-qk-xs btn-qk-ghost r-rimuovi" title="Rimuovi riga">
                <i class="fa-solid fa-xmark"></i>
              </button>
            </td>
          </tr>
          <?php else: ?>
          <tr class="offerta-riga" data-idx="<?= $i ?>" data-temp-id="<?= e($r['temp_id'] ?? $r['id'] ?? 'row_' . $i) ?>" style="<?= !empty($r['offerta_riga_padre_id']) ? 'background: rgba(0,0,0,0.02);' : '' ?>">
            <td>
              <input type="hidden" name="riga_rfq_riga_id[]" value="<?= e($r['rfq_riga_id'] ?? '') ?>">
              <input type="hidden" name="riga_offerta_riga_padre_id[]" class="r-padre-id" value="<?= e($r['offerta_riga_padre_id'] ?? '') ?>">
              
              <!-- Container per prodotto originale (statico) -->
              <div class="prod-originale-container" id="prod-originale-<?= $i ?>" style="display: <?= ($r['flag_sostituto'] ?? 0) ? 'none' : 'block' ?>;">
                <strong><?= e($r['sku_interno'] ?? '') ?></strong>
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
                    <button type="button" class="qk-m2o-clear <?= $r['prodotto_id'] ? '' : 'd-none' ?> r-prodotto-clear">&times;</button>
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
                    Sostituto offerto dal fornitore
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
            <td>
              <input type="text" name="riga_sku_fornitore[]" class="qk-form-control" value="<?= e($r['sku_fornitore'] ?? '') ?>" <?= $readonly ?>>
            </td>
            <td>
              <input type="text" name="riga_descrizione[]" class="qk-form-control" value="<?= e($r['descrizione'] ?? '') ?>" <?= $readonly ?>>
            </td>
            <td>
              <input type="number" name="riga_quantita_offerta[]" class="qk-form-control r-qty" step="0.001" value="<?= e($r['quantita_offerta'] ?? '1') ?>" required <?= $readonly ?> data-requested="<?= e($r['qta_richiesta_rfq'] ?? '') ?>">
              <div class="qty-delta-badge" style="font-size:.78rem;margin-top:.25rem;display:none"></div>
            </td>
            <td>
              <input type="hidden" name="riga_unita_misura_id[]" value="<?= e($r['unita_misura_id'] ?? '') ?>">
              <span class="qk-text-muted"><?= e($r['uom'] ?? '—') ?></span>
            </td>
            <td>
              <input type="number" name="riga_prezzo_unitario[]" class="qk-form-control" step="0.0001" placeholder="0.00" value="<?= e($r['prezzo_unitario'] ?? '') ?>" required <?= $readonly ?>>
            </td>
            <td>
              <input type="number" name="riga_sconto_perc[]" class="qk-form-control" step="0.01" value="<?= e($r['sconto_perc'] ?? '0') ?>" <?= $readonly ?>>
            </td>
            <td>
              <input type="number" name="riga_iva_perc[]" class="qk-form-control" step="1" value="<?= e($r['iva_perc'] ?? '22') ?>" <?= $readonly ?>>
            </td>
            <td>
              <input type="date" name="riga_data_consegna_prevista[]" class="qk-form-control" value="<?= e($r['data_consegna_prevista'] ?? '') ?>" <?= $readonly ?>>
            </td>
            <td>
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

  <?php if ($canEdit): ?>
  <div style="display:flex;justify-content:flex-end;gap:.75rem;margin-bottom:2rem">
    <a href="<?= url('purchases/offerte') ?>" class="btn-qk btn-qk-ghost">Annulla</a>
    <button type="submit" class="btn-qk btn-qk-primary">
      <i class="fa-solid fa-floppy-disk"></i> Salva Preventivo
    </button>
  </div>
  <?php endif; ?>
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
    <td>
      <input type="text" name="riga_sku_fornitore[]" class="qk-form-control r-sku" placeholder="SKU">
    </td>
    <td>
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
    <td>
      <input type="number" name="riga_sconto_perc[]" class="qk-form-control r-sconto" step="0.01" value="0">
    </td>
    <td>
      <input type="number" name="riga_iva_perc[]" class="qk-form-control r-iva" step="1" value="22">
    </td>
    <td>
      <input type="date" name="riga_data_consegna_prevista[]" class="qk-form-control r-consegna">
    </td>
    <td>
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
      input.style.borderColor = '#d97706'; // warning orange
      badge.style.display = 'block';
      const sign = diff > 0 ? '+' : '';
      const pct = ((diff / requested) * 100).toFixed(0);
      badge.innerHTML = `<span style="color:#d97706;font-weight:600"><i class="fa-solid fa-triangle-exclamation"></i> Scostamento: ${sign}${diff} (${sign}${pct}%)</span>`;
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
      if (clearBtn) clearBtn.classList.remove('d-none');
    }
  };

  // Inizializza gli autocomplete per i sostituti se modificabile
  if (CAN_EDIT && !IS_SPONTANEOUS) {
    const CSRF_TOKEN = document.querySelector('input[name=_csrf]')?.value ?? '';
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
            formData.append('_csrf', CSRF_TOKEN);

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
  const contNomeHidden = document.getElementById('off-contatto-nome');
  const contTelInput = document.getElementById('off-contatto-telefono');
  const contEmailInput = document.getElementById('off-contatto-email');
  const fornTelHidden = document.getElementById('off-fornitore-telefono');
  const fornEmailHidden = document.getElementById('off-fornitore-email');
  const listiniSelect = document.getElementById('off-listino-acquisto-id');
  const API_FORNITORE_DETTAGLI = <?= json_encode(url('purchases/rfq/api/fornitore-dettagli')) ?>;

  let loadedContacts = [];

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
        if (contNomeHidden) contNomeHidden.value = '';
      } else {
        const contact = loadedContacts.find(c => String(c.id) === String(selectedId));
        if (contact) {
          if (contTelInput) contTelInput.value = contact.telefono || contact.cellulare || fornTelHidden?.value || '';
          if (contEmailInput) contEmailInput.value = contact.email || fornEmailHidden?.value || '';
          if (contNomeHidden) contNomeHidden.value = contact.nome_completo || '';
        }
      }
    });
  }

  loadFornitoreDettagli();
})();
</script>

<?php if ($isEdit && $isInValutazione): ?>
<div id="modal-rifiuta-offerta"
     style="display:none;position:fixed;inset:0;z-index:1050;
            background:rgba(0,0,0,.45);align-items:center;justify-content:center">
  <div style="background:var(--qk-card-bg);border:1px solid var(--qk-border);
              border-radius:var(--qk-radius-lg);padding:1.5rem;width:100%;max-width:480px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
      <strong style="display:inline-flex;align-items:center;gap:.5rem"><i class="fa-solid fa-ban text-danger"></i> Seleziona Motivo Rifiuto</strong>
      <button type="button" onclick="document.getElementById('modal-rifiuta-offerta').style.display='none'"
              style="background:none;border:none;cursor:pointer;font-size:1.2rem;color:var(--qk-text-muted)">&times;</button>
    </div>
    
    <form method="post" action="<?= url('purchases/offerte/action') ?>" style="margin:0">
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
      
      <div style="display:flex;justify-content:flex-end;gap:.5rem;margin-top:1.5rem">
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
