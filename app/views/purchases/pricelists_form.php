<?php
/**
 * purchases/pricelists_form.php — Form di creazione/modifica Listino Acquisto.
 * Variabili: $isEdit, $id, $values, $errors, $items
 */
?>

<?php $active_tab = 'pricelists'; include __DIR__ . '/_subnav.php'; ?>

<?php if (!empty($errors['_global'])): ?>
  <div class="qk-alert qk-alert-danger mb-3">
    <i class="fa-solid fa-circle-exclamation"></i>
    <span><?= e($errors['_global']) ?></span>
  </div>
<?php endif; ?>

<form action="" method="post" class="mb-4">
  <?= csrf_field() ?>

  <!-- Card Testata Principale -->
  <div class="qk-card shadow-sm mb-4">
    <div class="qk-card-header">
      <span class="qk-card-title">
        <i class="fa-solid fa-file-invoice-dollar me-2" style="color:var(--qk-accent)"></i>
        <?= $isEdit ? e($values['nome_listino']) : 'Nuovo listino acquisto' ?>
      </span>
      <?php if ($isEdit): ?>
        <span style="font-size:.8rem;color:var(--qk-text-muted)">ID #<?= (int)($id ?? 0) ?></span>
      <?php endif; ?>
    </div>

    <div class="qk-card-body">
      <div class="row g-3">
        <!-- Nome Listino -->
        <div class="col-md-6">
          <div class="qk-form-group mb-0">
            <label class="qk-form-label">Nome listino <span class="required">*</span></label>
            <input type="text" name="nome_listino"
                   class="qk-form-control <?= isset($errors['nome_listino']) ? 'is-invalid' : '' ?>"
                   placeholder="Es. Listino Base 2026, Promo Estate"
                   value="<?= e($values['nome_listino']) ?>">
            <?php if (isset($errors['nome_listino'])): ?>
              <div class="qk-form-error"><i class="fa-solid fa-circle-exclamation"></i> <?= e($errors['nome_listino']) ?></div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Fornitore -->
        <div class="col-md-6">
          <div class="qk-form-group mb-0">
            <label class="qk-form-label">Fornitore <span class="required">*</span></label>
            <div class="qk-m2o-wrap position-relative">
              <input type="hidden" name="fornitore_id" id="pl-fornitore-id" value="<?= e($values['fornitore_id']) ?>">
              <input type="text" class="qk-form-control qk-m2o-input <?= isset($errors['fornitore_id']) ? 'is-invalid' : '' ?>"
                     id="pl-fornitore-label" placeholder="Cerca fornitore…"
                     value="<?= e($values['fornitore_label'] ?? '') ?>"
                     autocomplete="off" <?= $isEdit ? 'disabled' : '' ?>>
              <button type="button" class="qk-m2o-clear d-none" id="pl-fornitore-clear" <?= $isEdit ? 'style="display:none"' : '' ?>>&times;</button>
              <div class="qk-m2o-dropdown shadow-sm" id="pl-fornitore-dropdown"></div>
            </div>
            <?php if (isset($errors['fornitore_id'])): ?>
              <div class="qk-form-error"><i class="fa-solid fa-circle-exclamation"></i> <?= e($errors['fornitore_id']) ?></div>
            <?php endif; ?>
            <?php if ($isEdit): ?>
              <div class="qk-form-hint">Il fornitore non può essere modificato dopo la creazione.</div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Date validità -->
        <div class="col-md-6">
          <div class="qk-form-group mb-0">
            <label class="qk-form-label">Valido dal</label>
            <input type="date" name="valido_dal" class="qk-form-control" value="<?= e($values['valido_dal']) ?>">
          </div>
        </div>
        <div class="col-md-6">
          <div class="qk-form-group mb-0">
            <label class="qk-form-label">Valido al</label>
            <input type="date" name="valido_al" class="qk-form-control" value="<?= e($values['valido_al']) ?>">
          </div>
        </div>
      </div>
    </div>

    <div class="qk-card-footer qk-flex-row qk-justify-between">
      <a href="<?= url('purchases/pricelists') ?>" class="btn-qk btn-qk-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i>Annulla
      </a>
      <button type="submit" class="btn-qk btn-qk-primary">
        <i class="fa-solid fa-floppy-disk me-1"></i>Salva listino
      </button>
    </div>
  </div>

  <?php if ($isEdit): ?>
    <!-- Sub-tabs (Odoo-style) -->
    <div class="qk-tabs-container mb-4">
      <ul class="nav nav-tabs qk-tabs border-bottom-0" id="pricelistPurchaseTabs" role="tablist" style="gap:4px">
        <li class="nav-item" role="presentation">
          <button class="nav-link active fw-semibold" id="p-items-tab" data-bs-toggle="tab" data-bs-target="#p-items-pane" type="button" role="tab" aria-controls="p-items-pane" aria-selected="true" style="padding:.75rem 1.25rem;border-radius:var(--qk-radius) var(--qk-radius) 0 0">
            <i class="fa-solid fa-file-spreadsheet me-2 text-primary"></i>Articoli e Tariffe
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link fw-semibold" id="p-notes-tab" data-bs-toggle="tab" data-bs-target="#p-notes-pane" type="button" role="tab" aria-controls="p-notes-pane" aria-selected="false" style="padding:.75rem 1.25rem;border-radius:var(--qk-radius) var(--qk-radius) 0 0">
            <i class="fa-solid fa-circle-info me-2 text-primary"></i>Note e Informazioni
          </button>
        </li>
      </ul>

      <div class="tab-content" id="pricelistPurchaseTabsContent">
        
        <!-- TAB 1: Articoli e Tariffe -->
        <div class="tab-pane fade show active" id="p-items-pane" role="tabpanel" aria-labelledby="p-items-tab" tabindex="0">
          <div class="qk-card shadow-sm">
            <div class="qk-card-header d-flex justify-content-between align-items-center">
              <span class="qk-card-title m-0">
                <i class="fa-solid fa-file-spreadsheet me-2" style="color:var(--qk-accent)"></i>Tariffe concordate per articolo
              </span>
              <div class="d-flex align-items-center gap-2">
                <input type="text" id="pl-grid-search-acquisto" class="qk-form-control py-1 px-2" placeholder="Filtra per nome o SKU..." style="font-size: .82rem !important; height: auto !important; min-height: 0 !important; width: 220px; margin: 0;">
                <button type="button" class="btn-qk btn-qk-secondary btn-qk-sm" data-bs-toggle="modal" data-bs-target="#modalImportaPrezziAcquisto">
                  <i class="fa-solid fa-file-import me-1"></i>Importa (XLSX)
                </button>
                <button type="button" id="btn-add-riga-acquisto" class="btn-qk btn-qk-primary btn-qk-sm">
                  <i class="fa-solid fa-plus me-1"></i>Aggiungi prodotto
                </button>
              </div>
            </div>

            <style>
              .pl-riga:not(.is-editing) .pl-edit { display: none !important; }
              .pl-riga.is-editing .pl-view       { display: none !important; }
              .pl-riga.is-editing > td { background-color: var(--qk-accent-light) !important; }
              .pl-riga.is-editing > td:first-child { box-shadow: inset 3px 0 0 var(--qk-accent); }
              .pl-btn-confirm { border-color: var(--qk-success); color: var(--qk-success); }
              .pl-btn-confirm:hover { background: var(--qk-success-bg); }
              .pl-btn-confirm.is-saving::before {
                content: ''; display: inline-block; width: 10px; height: 10px;
                border: 2px solid currentColor; border-top-color: transparent;
                border-radius: 50%; animation: pl-spin .6s linear infinite; margin-right: 0;
              }
              .pl-btn-confirm.is-saving i { display: none; }
              @keyframes pl-spin { to { transform: rotate(360deg); } }
              .pl-riga-saved > td { animation: pl-saved-flash .8s ease-out forwards; }
              @keyframes pl-saved-flash {
                0%   { background-color: var(--qk-success-bg); }
                100% { background-color: transparent; }
              }
              #tbl-prezzi-acquisto {
                table-layout: fixed;
                width: 100%;
              }
              /* Riduci padding nelle celle per massimizzare lo spazio */
              #tbl-prezzi-acquisto td, #tbl-prezzi-acquisto th {
                padding: .5rem .4rem !important;
                vertical-align: top !important;
              }
              /* Rendi gli input in tabella molto compatti */
              #tbl-prezzi-acquisto .qk-form-control,
              #tbl-prezzi-acquisto .qk-m2o-input {
                padding: .2rem .35rem !important;
                font-size: .82rem !important;
                height: auto !important;
                min-height: 0 !important;
              }
              #tbl-prezzi-acquisto select {
                padding: .2rem .35rem !important;
                font-size: .82rem !important;
                height: auto !important;
              }
            </style>

            <div class="qk-table-wrap" style="min-height: 320px;">
              <!-- Empty State -->
              <div id="pl-empty-acquisto" class="text-center py-5 qk-text-muted" <?= !empty($items) ? 'style="display:none"' : '' ?>>
                <i class="fa-solid fa-file-spreadsheet fs-2 mb-2"></i>
                <p class="mb-0 fw-semibold">Nessun articolo associato a questo listino d'acquisto.</p>
                <p class="small text-muted">Clicca su "Aggiungi prodotto" per definire una tariffa concordata.</p>
              </div>

              <table class="qk-table" id="tbl-prezzi-acquisto" <?= empty($items) ? 'style="display:none"' : '' ?>>
                <thead>
                  <tr>
                    <th>Prodotto</th>
                    <th style="width:140px">SKU Interno</th>
                    <th style="width:140px">SKU Fornitore</th>
                    <th class="text-end" style="width:110px">Q.tà min</th>
                    <th class="text-end" style="width:140px">Prezzo Unitario</th>
                    <th class="text-end" style="width:100px">Sconto %</th>
                    <th style="width:220px">Validità Riga</th>
                    <th>Note Prezzo</th>
                    <th style="width:72px"></th>
                  </tr>
                </thead>
                <tbody id="pl-tbody-acquisto">
                  <?php foreach ($items as $item): ?>
                    <tr class="pl-riga" data-riga-id="<?= (int)$item['id'] ?>"
                         data-prodotto-id="<?= (int)$item['prodotto_id'] ?>"
                         data-prodotto-nome="<?= e($item['prodotto_nome']) ?>"
                         data-sku="<?= e($item['sku_interno']) ?>"
                         data-sku-fornitore="<?= e($item['sku_fornitore'] ?? '') ?>"
                         data-qmin="<?= (float)$item['quantita_minima'] ?>"
                         data-prezzo="<?= (float)$item['prezzo'] ?>"
                         data-sconto="<?= (float)$item['sconto_perc'] ?>"
                         data-valido-dal="<?= e($item['valido_dal'] ?? '') ?>"
                         data-valido-al="<?= e($item['valido_al'] ?? '') ?>"
                         data-note="<?= e($item['note'] ?? '') ?>">
                      <td>
                        <div class="pl-view">
                          <a href="<?= url('products/edit?id=' . $item['prodotto_id']) ?>" class="fw-semibold qk-text-accent" tabindex="-1">
                            <?= e($item['prodotto_nome']) ?>
                          </a>
                          <div class="pl-duplicate-warning text-danger fw-semibold mt-1" style="font-size: 0.75rem; display: none;">
                            <i class="fa-solid fa-triangle-exclamation me-1"></i>Articolo duplicato per questa quantità
                          </div>
                        </div>
                      </td>
                      <td><code><?= e($item['sku_interno']) ?></code></td>
                      <td>
                        <span class="pl-view text-monospace"><?= e($item['sku_fornitore'] ?? '—') ?></span>
                        <input class="pl-edit qk-form-control py-1 px-2 text-monospace r-sku-forn" type="text" value="<?= e($item['sku_fornitore'] ?? '') ?>">
                      </td>
                      <td class="text-end">
                        <span class="pl-view text-monospace"><?= number_format((float)$item['quantita_minima'], 3, ',', '.') ?></span>
                        <input class="pl-edit qk-form-control py-1 px-2 text-end text-monospace r-qmin" type="number" min="0" step="0.001" value="<?= (float)$item['quantita_minima'] ?>">
                      </td>
                      <td class="text-end">
                        <span class="pl-view text-monospace fw-bold"><?= number_format((float)$item['prezzo'], 4, ',', '.') ?> €</span>
                        <input class="pl-edit qk-form-control py-1 px-2 text-end text-monospace r-prezzo" type="number" min="0" step="0.0001" value="<?= (float)$item['prezzo'] ?>">
                      </td>
                      <td class="text-end">
                        <span class="pl-view text-monospace"><?= number_format((float)$item['sconto_perc'], 2, ',', '.') ?> %</span>
                        <input class="pl-edit qk-form-control py-1 px-2 text-end text-monospace r-sconto" type="number" min="0" max="100" step="0.01" value="<?= (float)$item['sconto_perc'] ?>">
                      </td>
                      <td>
                        <div class="pl-view text-monospace small">
                          <?php if (!empty($item['valido_dal']) || !empty($item['valido_al'])): ?>
                            <?= !empty($item['valido_dal']) ? date('d/m/Y', strtotime($item['valido_dal'])) : '...' ?>
                            &rarr;
                            <?= !empty($item['valido_al']) ? date('d/m/Y', strtotime($item['valido_al'])) : '...' ?>
                          <?php else: ?>
                            Sempre valido
                          <?php endif; ?>
                        </div>
                        <div class="pl-edit d-flex gap-1">
                          <input class="qk-form-control py-1 px-1 r-valido-dal" type="date" value="<?= e($item['valido_dal'] ?? '') ?>" style="font-size:.75rem">
                          <input class="qk-form-control py-1 px-1 r-valido-al" type="date" value="<?= e($item['valido_al'] ?? '') ?>" style="font-size:.75rem">
                        </div>
                      </td>
                      <td>
                        <span class="pl-view qk-text-muted small"><?= e($item['note'] ?? '') ?></span>
                        <input class="pl-edit qk-form-control py-1 px-2 r-note" type="text" value="<?= e($item['note'] ?? '') ?>">
                      </td>
                      <td>
                        <button type="button" class="btn-qk btn-qk-danger btn-qk-sm px-2 btn-delete-riga" title="Rimuovi" style="vertical-align: middle;">
                          <i class="fa-solid fa-trash-can"></i>
                        </button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- TAB 2: Note e Informazioni -->
        <div class="tab-pane fade" id="p-notes-pane" role="tabpanel" aria-labelledby="p-notes-tab" tabindex="0">
          <div class="row g-3">
            <div class="col-md-7">
              <div class="qk-card shadow-sm h-100">
                <div class="qk-card-header">
                  <span class="qk-card-title"><i class="fa-solid fa-message me-2" style="color:var(--qk-accent)"></i>Note interne o descrittive</span>
                </div>
                <div class="qk-card-body">
                  <div class="qk-form-group mb-0">
                    <textarea name="note" class="qk-form-control" rows="6" placeholder="Aggiungi note sull'accordo o dettagli aggiuntivi del listino..."><?= e($values['note']) ?></textarea>
                  </div>
                </div>
              </div>
            </div>
            <div class="col-md-5">
              <div class="qk-card shadow-sm h-100" style="border-left:3px solid var(--qk-info)">
                <div class="qk-card-header">
                  <span class="qk-card-title"><i class="fa-solid fa-circle-info me-2" style="color:var(--qk-info)"></i>Informazioni</span>
                </div>
                <div class="qk-card-body" style="font-size:.85rem;color:var(--qk-text-muted);line-height:1.6">
                  <p class="mb-2">I listini consentono di impostare regole di acquisto temporanee o tariffe concordate con il fornitore prescelto.</p>
                  <p class="mb-0">Una volta inserita la tariffa, il sistema la proporrà automaticamente quando crei una Richiesta di Offerta (RFQ) o un Ordine di Acquisto per questo fornitore in base alle date e scaglioni inseriti.</p>
                </div>
              </div>
            </div>
          </div>
        </div>

      </div>
    </div>
  <?php endif; ?>

</form>

<!-- Template per nuova riga listino acquisto -->
<template id="pl-tpl-new-acquisto">
  <tr class="pl-riga is-editing" data-riga-id="0" data-prodotto-id="">
    <td>
      <div class="pl-edit">
        <div class="qk-m2o-wrap" style="min-width:180px">
          <input type="hidden" class="r-prodotto-id" value="">
          <input type="text" class="qk-form-control qk-m2o-input r-prodotto-label" placeholder="Cerca prodotto…" autocomplete="off">
          <button type="button" class="qk-m2o-clear d-none r-prodotto-clear">&times;</button>
          <div class="qk-m2o-dropdown" style="position:absolute;z-index:100;background:var(--qk-card-bg);border:1px solid var(--qk-card-border);max-height:200px;overflow-y:auto;width:100%"></div>
        </div>
        <div class="pl-duplicate-warning text-danger fw-semibold mt-1" style="font-size: 0.75rem; display: none;">
          <i class="fa-solid fa-triangle-exclamation me-1"></i>Articolo duplicato per questa quantità
        </div>
      </div>
    </td>
    <td><code class="r-sku-label">—</code></td>
    <td><input class="pl-edit qk-form-control py-1 px-2 text-monospace r-sku-forn" type="text" placeholder="SKU Forn."></td>
    <td><input class="pl-edit qk-form-control py-1 px-2 text-end text-monospace r-qmin" type="number" min="0" step="0.001" value="0"></td>
    <td><input class="pl-edit qk-form-control py-1 px-2 text-end text-monospace r-prezzo" type="number" min="0" step="0.0001" placeholder="0,0000 €"></td>
    <td><input class="pl-edit qk-form-control py-1 px-2 text-end text-monospace r-sconto" type="number" min="0" max="100" step="0.01" value="0"></td>
    <td>
      <div class="pl-edit d-flex gap-1">
        <input class="qk-form-control py-1 px-1 r-valido-dal" type="date" style="font-size:.75rem">
        <input class="qk-form-control py-1 px-1 r-valido-al" type="date" style="font-size:.75rem">
      </div>
    </td>
    <td><input class="pl-edit qk-form-control py-1 px-2 r-note" type="text" placeholder="Note prezzo"></td>
    <td>
      <button type="button" class="btn-qk btn-qk-secondary btn-qk-sm px-2 btn-cancel-riga" title="Annulla (Esc)" style="vertical-align: middle;">
        <i class="fa-solid fa-trash-can"></i>
      </button>
    </td>
  </tr>
</template>

<!-- Script Autocomplete Fornitore (Many2one) & Griglia Prezzi Acquisto -->
<script>
document.addEventListener('DOMContentLoaded', function () {
  const isEdit = <?= json_encode($isEdit) ?>;
  
  if (!isEdit) {
    const inputEl = document.getElementById('pl-fornitore-label');
    const hiddenEl = document.getElementById('pl-fornitore-id');
    const dropdownEl = document.getElementById('pl-fornitore-dropdown');
    const clearBtn = document.getElementById('pl-fornitore-clear');

    const m2o = new QkM2O({
      inputEl: inputEl,
      hiddenEl: hiddenEl,
      dropdownEl: dropdownEl,
      clearBtn: clearBtn,
      apiUrl: QK_BASE + '/purchases/rfq/api/fornitori',
      labelKey: 'nome',
      columns: [
        { key: 'nome', label: 'Fornitore' },
        { key: 'codice', label: 'Codice' }
      ]
    });

    if (hiddenEl.value) {
      clearBtn.classList.remove('d-none');
    }
  } else {
    // Gestione della griglia prezzi acquisto inline
    const BASE = QK_BASE;
    const CSRF = <?= json_encode(csrf_token()) ?>;
    const LISTINO = <?= (int)$id ?>;
    const tbody = document.getElementById('pl-tbody-acquisto');
    const table = document.getElementById('tbl-prezzi-acquisto');
    const empty = document.getElementById('pl-empty-acquisto');
    const tpl = document.getElementById('pl-tpl-new-acquisto');

    // Run duplicate check on load
    setTimeout(runBusinessValidations, 200);

    document.getElementById('btn-add-riga-acquisto').addEventListener('click', () => {
      tbody.querySelectorAll('.pl-riga.is-editing').forEach(tr => cancelEdit(tr));
      addNewRow();
    });

    function addNewRow() {
      const fragment = tpl.content.cloneNode(true);
      tbody.prepend(fragment);
      const newTr = tbody.firstElementChild;
      syncEmpty();
      initNewRowM2O(newTr);
      newTr.querySelector('.r-prodotto-label')?.focus();
      newTr.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    /* ════════════════════════════════════════════════════
       CLICK-TO-EDIT & CELL FOCUS TRIGGER
    ════════════════════════════════════════════════════ */
    tbody.addEventListener('click', function (e) {
      const td = e.target.closest('td');
      if (!td) return;
      const tr = td.closest('tr.pl-riga');
      if (!tr) return;

      if (e.target.closest('.btn-qk')) {
        if (e.target.closest('.btn-confirm-riga')) { confirmEdit(tr); }
        else if (e.target.closest('.btn-cancel-riga')) { cancelEdit(tr); }
        else if (e.target.closest('.btn-delete-riga')) { deleteRow(tr); }
        return;
      }
      if (e.target.closest('a')) return;

      if (!tr.classList.contains('is-editing')) {
        enterEdit(tr);
      }

      const input = td.querySelector('input, select');
      if (input && document.activeElement !== input) {
        input.focus();
        if (input.select && input.tagName !== 'SELECT') input.select();
      }
    });

    tbody.addEventListener('focusin', function (e) {
      const tr = e.target.closest('tr.pl-riga');
      if (tr && !tr.classList.contains('is-editing')) {
        enterEdit(tr);
      }
    });

    /* ════════════════════════════════════════════════════
       IMMEDIATE AUTOSAVE ON BLUR (FOCUSOUT FROM ROW)
    ════════════════════════════════════════════════════ */
    tbody.addEventListener('focusout', function (e) {
      const tr = e.target.closest('tr.pl-riga');
      if (!tr) return;

      const nextFocused = e.relatedTarget;
      if (!nextFocused || nextFocused.closest('tr.pl-riga') !== tr) {
        if (nextFocused && nextFocused.closest('.btn-cancel-riga, .btn-delete-riga')) {
          return;
        }

        const hasDirty = tr.querySelectorAll('.is-dirty').length > 0;
        const isNew = tr.dataset.rigaId === '0';

        if (hasDirty || (isNew && tr.querySelector('.r-prodotto-id')?.value)) {
          confirmEdit(tr);
        } else if (isNew && !tr.querySelector('.r-prodotto-id')?.value) {
          const searchVal = tr.querySelector('.r-prodotto-label')?.value?.trim() || '';
          const prezzoVal = tr.querySelector('.r-prezzo')?.value?.trim() || '';
          const qminVal   = tr.querySelector('.r-qmin')?.value?.trim() || '0';
          const scontoVal = tr.querySelector('.r-sconto')?.value?.trim() || '0';
          const noteVal   = tr.querySelector('.r-note')?.value?.trim() || '';

          const isFullyEmpty = (searchVal === '') && 
                               (prezzoVal === '' || parseFloat(prezzoVal) === 0) &&
                               (qminVal === '' || parseFloat(qminVal) === 0) &&
                               (scontoVal === '' || parseFloat(scontoVal) === 0) &&
                               (noteVal === '');

          if (isFullyEmpty) {
            cancelEdit(tr);
          }
        } else {
          tr.classList.remove('is-editing');
        }
      }
    });

    /* ════════════════════════════════════════════════════
       DIRTY STATE TRACKING
    ════════════════════════════════════════════════════ */
    function checkInputDirty(input) {
      const tr = input.closest('tr.pl-riga');
      if (!tr) return;

      let isDirty = false;
      const val = input.value.trim();

      if (input.classList.contains('r-sku-forn')) {
        isDirty = val !== (tr.dataset.skuFornitore || '');
      } else if (input.classList.contains('r-qmin')) {
        isDirty = parseFloat(val || 0) !== parseFloat(tr.dataset.qmin || 0);
      } else if (input.classList.contains('r-prezzo')) {
        isDirty = parseFloat(val || 0) !== parseFloat(tr.dataset.prezzo || 0);
      } else if (input.classList.contains('r-sconto')) {
        isDirty = parseFloat(val || 0) !== parseFloat(tr.dataset.sconto || 0);
      } else if (input.classList.contains('r-valido-dal')) {
        isDirty = val !== (tr.dataset.validoDal || '');
      } else if (input.classList.contains('r-valido-al')) {
        isDirty = val !== (tr.dataset.validoAl || '');
      } else if (input.classList.contains('r-note')) {
        isDirty = val !== (tr.dataset.note || '');
      }

      if (isDirty) {
        input.classList.add('is-dirty');
        input.style.backgroundColor = '#fef08a'; // Tailwind yellow-200
        input.style.fontStyle = 'italic';
      } else {
        input.classList.remove('is-dirty');
        input.style.backgroundColor = '';
        input.style.fontStyle = '';
      }
    }

    tbody.addEventListener('input', function (e) {
      if (e.target.matches('input, select')) {
        checkInputDirty(e.target);
        runBusinessValidations();
      }
    });

    tbody.addEventListener('change', function (e) {
      if (e.target.matches('input, select')) {
        checkInputDirty(e.target);
        runBusinessValidations();
      }
    });

    /* ════════════════════════════════════════════════════
       KEYBOARD Navigation and events
    ════════════════════════════════════════════════════ */
    tbody.addEventListener('keydown', function (e) {
      const input = e.target;
      const tr = input.closest('tr.pl-riga');
      if (!tr) return;

      // Up/Down Arrows navigation
      if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
        if (!input.matches('input, select') || input.tagName === 'SELECT') return;
        if (input.classList.contains('r-prodotto-label')) {
          const m2oDropdown = input.closest('.qk-m2o-wrap')?.querySelector('.qk-m2o-dropdown');
          if (m2oDropdown && m2oDropdown.classList.contains('open')) return;
        }

        e.preventDefault();
        const targetTr = (e.key === 'ArrowUp') ? tr.previousElementSibling : tr.nextElementSibling;
        if (targetTr && targetTr.classList.contains('pl-riga')) {
          const classes = Array.from(input.classList).filter(c => c.startsWith('r-') || c.startsWith('pl-'));
          for (const cls of classes) {
            const targetInput = targetTr.querySelector('.' + cls);
            if (targetInput) {
              enterEdit(targetTr);
              targetInput.focus();
              if (targetInput.select) targetInput.select();
              break;
            }
          }
        }
        return;
      }

      // Tab on note -> save and go to next or new row
      if (e.key === 'Tab' && input.classList.contains('r-note') && !e.shiftKey) {
        e.preventDefault();
        confirmEdit(tr).then((success) => {
          if (success) {
            const nextTr = tr.nextElementSibling;
            if (nextTr && nextTr.classList.contains('pl-riga')) {
              enterEdit(nextTr);
              nextTr.querySelector('.qk-form-control')?.focus();
            } else {
              addNewRow();
            }
          }
        });
        return;
      }

      // Enter key -> Save and go to next or new row
      if (e.key === 'Enter' && !e.shiftKey) {
        if (input.classList.contains('r-prodotto-label')) {
          const m2oDropdown = input.closest('.qk-m2o-wrap')?.querySelector('.qk-m2o-dropdown');
          if (m2oDropdown && m2oDropdown.classList.contains('open')) return;
        }
        e.preventDefault();
        confirmEdit(tr).then((success) => {
          if (success) {
            const nextTr = tr.nextElementSibling;
            if (nextTr && nextTr.classList.contains('pl-riga')) {
              enterEdit(nextTr);
              nextTr.querySelector('.qk-form-control')?.focus();
            } else {
              addNewRow();
            }
          }
        });
        return;
      }

      // Escape -> Discard
      if (e.key === 'Escape') {
        e.preventDefault();
        cancelEdit(tr);
        return;
      }
    });

    function enterEdit(tr) {
      tbody.querySelectorAll('.pl-riga.is-editing').forEach(other => {
        if (other !== tr) {
          const otherDirty = other.querySelectorAll('.is-dirty').length > 0;
          if (otherDirty) {
            confirmEdit(other);
          } else {
            cancelEdit(other);
          }
        }
      });
      // Popola gli input con i dataset
      tr.querySelector('.r-sku-forn').value = tr.dataset.skuFornitore || '';
      tr.querySelector('.r-qmin').value = tr.dataset.qmin || '0';
      tr.querySelector('.r-prezzo').value = tr.dataset.prezzo || '';
      tr.querySelector('.r-sconto').value = tr.dataset.sconto || '0';
      tr.querySelector('.r-valido-dal').value = tr.dataset.validoDal || '';
      tr.querySelector('.r-valido-al').value = tr.dataset.validoAl || '';
      tr.querySelector('.r-note').value = tr.dataset.note || '';

      tr.querySelectorAll('.qk-form-control').forEach(input => {
        input.classList.remove('is-dirty');
        input.style.backgroundColor = '';
        input.style.fontStyle = '';
      });

      tr.classList.add('is-editing');
    }

    async function confirmEdit(tr) {
      const isNew = tr.dataset.rigaId === '0';
      const prodottoId = isNew ? tr.querySelector('.r-prodotto-id').value : tr.dataset.prodottoId;
      const prezzo = tr.querySelector('.r-prezzo').value.trim();

      if (!prodottoId) { flashRow(tr, 'Seleziona un prodotto.'); return false; }
      if (!prezzo || isNaN(parseFloat(prezzo))) { flashRow(tr, 'Inserisci un prezzo valido.'); return false; }

      // Check duplicates before saving
      runBusinessValidations();
      if (tr.dataset.hasDuplicate === 'true') {
        flashRow(tr, 'Articolo duplicato con lo stesso scaglione di quantità. Salvo bloccato.');
        return false;
      }

      const btn = tr.querySelector('.btn-confirm-riga');
      if (btn) btn.classList.add('is-saving');

      try {
        const res = await fetch(BASE + '/purchases/pricelists/righe/save', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({
            csrf_token: CSRF,
            listino_acquisto_id: LISTINO,
            riga_id: tr.dataset.rigaId,
            prodotto_id: prodottoId,
            sku_fornitore: tr.querySelector('.r-sku-forn').value.trim(),
            quantita_minima: tr.querySelector('.r-qmin').value.trim() || '0',
            prezzo: prezzo,
            sconto_perc: tr.querySelector('.r-sconto').value.trim() || '0',
            valido_dal: tr.querySelector('.r-valido-dal').value,
            valido_al: tr.querySelector('.r-valido-al').value,
            note: tr.querySelector('.r-note').value.trim()
          })
        });

        const d = await res.json();
        if (!d.ok) { flashRow(tr, d.error || 'Errore durante il salvataggio.'); return false; }

        applyRigaData(tr, d.riga);
        tr.classList.remove('is-editing');
        tr.classList.add('pl-riga-saved');
        setTimeout(() => tr.classList.remove('pl-riga-saved'), 800);
        syncEmpty();
        runBusinessValidations();
        return true;
      } catch (err) {
        flashRow(tr, 'Errore di rete.');
        return false;
      } finally {
        if (btn) btn.classList.remove('is-saving');
      }
    }

    function cancelEdit(tr) {
      if (tr.dataset.rigaId === '0') {
        tr.remove();
      } else {
        tr.classList.remove('is-editing');
        tr.querySelector('.r-sku-forn').value = tr.dataset.skuFornitore || '';
        tr.querySelector('.r-qmin').value = tr.dataset.qmin || '0';
        tr.querySelector('.r-prezzo').value = tr.dataset.prezzo || '';
        tr.querySelector('.r-sconto').value = tr.dataset.sconto || '0';
        tr.querySelector('.r-valido-dal').value = tr.dataset.validoDal || '';
        tr.querySelector('.r-valido-al').value = tr.dataset.validoAl || '';
        tr.querySelector('.r-note').value = tr.dataset.note || '';

        tr.querySelectorAll('.qk-form-control').forEach(input => {
          input.classList.remove('is-dirty');
          input.style.backgroundColor = '';
          input.style.fontStyle = '';
        });
      }
      syncEmpty();
      runBusinessValidations();
    }

    async function deleteRow(tr) {
      try {
        const rigaId = tr.dataset.rigaId;
        const originalHtml = tr.innerHTML;
        const originalData = {
          prodottoId: tr.dataset.prodottoId,
          prodottoNome: tr.dataset.prodottoNome,
          sku: tr.dataset.sku,
          skuFornitore: tr.dataset.skuFornitore,
          qmin: tr.dataset.qmin,
          prezzo: tr.dataset.prezzo,
          sconto: tr.dataset.sconto,
          validoDal: tr.dataset.validoDal,
          validoAl: tr.dataset.validoAl,
          note: tr.dataset.note
        };

        const res = await fetch(BASE + '/purchases/pricelists/righe/delete', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ csrf_token: CSRF, riga_id: rigaId })
        });
        const d = await res.json();
        if (!d.ok) {
          window.qkToast(d.error || "Errore durante l'eliminazione.", "danger");
          return;
        }

        window.qkSoftDeleteRow(tr, {
          colSpan: 9,
          messageHtml: `<i class="fa-solid fa-trash-can text-danger me-2"></i>Prezzo per <strong>${escapeHtml(originalData.prodottoNome)}</strong> rimosso. <a href="#" class="undo-delete-inline ms-3 fw-bold text-decoration-underline text-primary">Annulla</a>`,
          onUndo: async () => {
            try {
              const res = await fetch(BASE + '/purchases/pricelists/righe/save', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                  csrf_token: CSRF,
                  listino_acquisto_id: LISTINO,
                  riga_id: '0',
                  prodotto_id: originalData.prodottoId,
                  sku_fornitore: originalData.skuFornitore,
                  quantita_minima: originalData.qmin || '0',
                  prezzo: originalData.prezzo,
                  sconto_perc: originalData.sconto || '0',
                  valido_dal: originalData.validoDal,
                  valido_al: originalData.validoAl,
                  note: originalData.note
                })
              });
              const rdata = await res.json();
              if (rdata.ok) {
                applyRigaData(tr, rdata.riga);
                tr.classList.add('pl-riga-saved');
                setTimeout(() => tr.classList.remove('pl-riga-saved'), 800);
                syncEmpty();
                runBusinessValidations();
                return true;
              } else {
                window.qkToast('Impossibile ripristinare: ' + (rdata.error || 'Errore.'), 'danger');
                return false;
              }
            } catch (err) {
              window.qkToast('Errore durante il ripristino.', 'danger');
              return false;
            }
          },
          onDismiss: () => {
            syncEmpty();
            runBusinessValidations();
          }
        });
      } catch (err) {
        window.qkToast('Errore durante la cancellazione.', 'danger');
      }
    }

    function applyRigaData(tr, r) {
      tr.dataset.rigaId = r.id;
      tr.dataset.prodottoId = r.prodotto_id;
      tr.dataset.prodottoNome = r.prodotto_nome || '';
      tr.dataset.sku = r.sku_interno || '';
      tr.dataset.skuFornitore = r.sku_fornitore || '';
      tr.dataset.qmin = r.quantita_minima;
      tr.dataset.prezzo = r.prezzo;
      tr.dataset.sconto = r.sconto_perc;
      tr.dataset.validoDal = r.valido_dal || '';
      tr.dataset.validoAl = r.valido_al || '';
      tr.dataset.note = r.note || '';

      const fmt = (v, d) => parseFloat(v || 0).toLocaleString('it-IT', { minimumFractionDigits: d, maximumFractionDigits: d });

      // Se riga nuova, costruiamo le celle view
      tr.innerHTML = `
        <td>
          <div class="pl-view">
            <a href="${BASE}/products/edit?id=${r.prodotto_id}" class="fw-semibold qk-text-accent" tabindex="-1">${escapeHtml(r.prodotto_nome)}</a>
            <div class="pl-duplicate-warning text-danger fw-semibold mt-1" style="font-size: 0.75rem; display: none;">
              <i class="fa-solid fa-triangle-exclamation me-1"></i>Articolo duplicato per questa quantità
            </div>
          </div>
        </td>
        <td><code>${escapeHtml(r.sku_interno)}</code></td>
        <td>
          <span class="pl-view text-monospace">${escapeHtml(r.sku_fornitore || '—')}</span>
          <input class="pl-edit qk-form-control py-1 px-2 text-monospace r-sku-forn" type="text" value="${escapeHtml(r.sku_fornitore || '')}">
        </td>
        <td class="text-end">
          <span class="pl-view text-monospace">${fmt(r.quantita_minima, 3)}</span>
          <input class="pl-edit qk-form-control py-1 px-2 text-end text-monospace r-qmin" type="number" min="0" step="0.001" value="${r.quantita_minima}">
        </td>
        <td class="text-end">
          <span class="pl-view text-monospace fw-bold">${fmt(r.prezzo, 4)} €</span>
          <input class="pl-edit qk-form-control py-1 px-2 text-end text-monospace r-prezzo" type="number" min="0" step="0.0001" value="${r.prezzo}">
        </td>
        <td class="text-end">
          <span class="pl-view text-monospace">${fmt(r.sconto_perc, 2)} %</span>
          <input class="pl-edit qk-form-control py-1 px-2 text-end text-monospace r-sconto" type="number" min="0" max="100" step="0.01" value="${r.sconto_perc}">
        </td>
        <td>
          <div class="pl-view text-monospace small">
            ${r.valido_dal || r.valido_al ? 
              (r.valido_dal ? formatDate(r.valido_dal) : '...') + ' &rarr; ' + (r.valido_al ? formatDate(r.valido_al) : '...') : 
              'Sempre valido'}
          </div>
          <div class="pl-edit d-flex gap-1">
            <input class="qk-form-control py-1 px-1 r-valido-dal" type="date" value="${r.valido_dal || ''}" style="font-size:.75rem">
            <input class="qk-form-control py-1 px-1 r-valido-al" type="date" value="${r.valido_al || ''}" style="font-size:.75rem">
          </div>
        </td>
        <td>
          <span class="pl-view qk-text-muted small">${escapeHtml(r.note || '')}</span>
          <input class="pl-edit qk-form-control py-1 px-2 r-note" type="text" value="${escapeHtml(r.note || '')}">
        </td>
        <td>
          <button type="button" class="btn-qk btn-qk-danger btn-qk-sm px-2 btn-delete-riga" title="Rimuovi" style="vertical-align: middle;">
            <i class="fa-solid fa-trash-can"></i>
          </button>
        </td>
      `;
    }

    /* ════════════════════════════════════════════════════
       BUSINESS VALIDATIONS (DUPLICATES)
    ════════════════════════════════════════════════════ */
    function runBusinessValidations() {
      const rows = Array.from(tbody.querySelectorAll('tr.pl-riga'));
      
      const countMap = {};
      rows.forEach(tr => {
        const pid = tr.querySelector('.r-prodotto-id')?.value || tr.dataset.prodottoId;
        const qminInput = tr.querySelector('.r-qmin');
        const qminVal = qminInput ? qminInput.value : tr.dataset.qmin;
        const qmin = parseFloat(qminVal || 0).toFixed(3);
        
        if (pid && pid !== '0') {
          const key = `${pid}_${qmin}`;
          if (!countMap[key]) countMap[key] = [];
          countMap[key].push(tr);
        }
      });
      
      rows.forEach(tr => {
        tr.querySelectorAll('.pl-duplicate-warning').forEach(el => el.style.display = 'none');
        tr.dataset.hasDuplicate = 'false';
      });
      
      Object.keys(countMap).forEach(key => {
        const collisionRows = countMap[key];
        if (collisionRows.length > 1) {
          collisionRows.forEach(tr => {
            tr.querySelectorAll('.pl-duplicate-warning').forEach(el => el.style.display = 'block');
            tr.dataset.hasDuplicate = 'true';
          });
        }
      });
    }

    function initNewRowM2O(tr) {
      const inputEl = tr.querySelector('.r-prodotto-label');
      const hiddenEl = tr.querySelector('.r-prodotto-id');
      const dropdownEl = tr.querySelector('.qk-m2o-dropdown');
      const clearBtn = tr.querySelector('.r-prodotto-clear');

      new QkM2O({
        inputEl, hiddenEl, dropdownEl, clearBtn,
        apiUrl: BASE + '/purchases/pricelists/api/prodotti',
        labelKey: 'label',
        columns: [{ key: 'label', label: 'Prodotto' }, { key: 'sku', label: 'SKU' }],
        onSelect: (id, item) => {
          if (item) {
            tr.querySelector('.r-sku-label').textContent = item.sku || '—';
            runBusinessValidations();
            setTimeout(() => tr.querySelector('.r-sku-forn')?.focus(), 50);
          } else {
            tr.querySelector('.r-sku-label').textContent = '—';
          }
        }
      });
    }

    function syncEmpty() {
      const hasRows = tbody.children.length > 0;
      empty.style.display = hasRows ? 'none' : 'block';
      table.style.display = hasRows ? 'table' : 'none';
    }

    function flashRow(tr, msg) {
      tr.style.outline = '2px solid var(--qk-danger)';
      tr.style.outlineOffset = '-2px';
      tr.title = msg;
      setTimeout(() => { tr.style.outline = ''; tr.title = ''; }, 2000);
    }

    function formatDate(ds) {
      if (!ds) return '';
      const p = ds.split('-');
      return p.length === 3 ? `${p[2]}/${p[1]}/${p[0]}` : ds;
    }

    function escapeHtml(str) {
      if (!str) return '';
      return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }

    /* ── Filtro di ricerca client-side ── */
    document.getElementById('pl-grid-search-acquisto')?.addEventListener('input', function (e) {
      const q = e.target.value.toLowerCase().trim();
      tbody.querySelectorAll('tr.pl-riga').forEach(tr => {
        if (tr.classList.contains('is-editing')) {
          tr.style.display = '';
          return;
        }
        const nome = (tr.dataset.prodottoNome || '').toLowerCase();
        const sku = (tr.dataset.sku || '').toLowerCase();
        if (nome.includes(q) || sku.includes(q)) {
          tr.style.display = '';
        } else {
          tr.style.display = 'none';
        }
      });
    });
  }
});
</script>

<!-- ExcelJS Library for reading/writing xlsx -->
<script src="<?= asset('js/exceljs.min.js') ?>"></script>

<!-- Modal per Importazione Massiva Prezzi Acquisto -->
<div class="modal fade" id="modalImportaPrezziAcquisto" tabindex="-1" aria-labelledby="modalImportaPrezziAcquistoTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modalImportaPrezziAcquistoTitle">
          <i class="fa-solid fa-file-import me-2"></i>Importazione Massiva Tariffe Acquisto
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
      </div>
      <div class="modal-body">
        <div class="qk-alert qk-alert-info mb-3">
          <i class="fa-solid fa-circle-info"></i>
          <span>Carica un file Excel (.xlsx) per importare o aggiornare le tariffe degli articoli. Se una tariffa d'acquisto esiste già per lo stesso articolo e scaglione quantità, verrà aggiornata con il nuovo prezzo.</span>
        </div>
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div>
            <input type="file" id="inputFilePrezziAcquisto" accept=".xlsx" class="d-none">
            <label for="inputFilePrezziAcquisto" class="btn-qk btn-qk-primary">
              <i class="fa-solid fa-file-excel me-1"></i>Seleziona File Excel...
            </label>
            <button type="button" class="btn-qk btn-qk-secondary" id="btnClearFilePrezzi">
              <i class="fa-solid fa-trash me-1"></i>Rimuovi File
            </button>
          </div>
          <button type="button" class="btn-qk btn-qk-secondary" id="btnDownloadTracciatoPrezzi">
            <i class="fa-solid fa-file-arrow-down me-1"></i>Scarica Modello Tracciato
          </button>
        </div>

        <div id="importPreviewWrapPrezzi" style="display:none" class="mb-3">
          <h6 class="fw-bold mb-2">Anteprima Righe (<span id="importPreviewCountPrezzi">0</span> righe trovate, mostra max 10)</h6>
          <div class="qk-table-wrap" style="max-height: 250px; overflow-y: auto;">
            <table class="qk-table mb-0" style="font-size: .8rem">
              <thead id="importPreviewHeadPrezzi"></thead>
              <tbody id="importPreviewBodyPrezzi"></tbody>
            </table>
          </div>
        </div>

        <div id="importResultPrezzi" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-qk btn-qk-secondary" data-bs-dismiss="modal">Chiudi</button>
        <button type="button" class="btn-qk btn-qk-primary" id="btnImportaPrezziAcquisto" disabled>
          <i class="fa-solid fa-upload me-1"></i>Avvia Importazione
        </button>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  'use strict';

  var LISTINO_ID = <?= (int)($id ?? 0) ?>;
  var CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
  var IMPORT_URL = QK_BASE + '/purchases/pricelists/api/import';

  var COLS_PREZZI = [
    {key:'sku_interno',     note:'Obbligatorio. SKU interno del prodotto in Quack.', esempio:'PROD-001'},
    {key:'sku_fornitore',   note:'Codice articolo assegnato dal fornitore.',         esempio:'SUPP-PROD-99'},
    {key:'quantita_minima', note:'Quantità minima per questa tariffa.',              esempio:'1.000'},
    {key:'prezzo',          note:'Obbligatorio. Prezzo unitario di acquisto.',       esempio:'12.5000'},
    {key:'sconto_perc',     note:'Sconto percentuale da applicare.',                 esempio:'5.00'},
    {key:'valido_dal',      note:'Data inizio validità riga (AAAA-MM-GG).',          esempio:'2026-01-01'},
    {key:'valido_al',       note:'Data fine validità riga (AAAA-MM-GG).',            esempio:'2026-12-31'},
    {key:'note',            note:'Note specifiche del prezzo.',                      esempio:'Prezzo bloccato da contratto'}
  ];

  function downloadBuffer(buffer, filename) {
    var blob = new Blob([buffer], {type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'});
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url; a.download = filename;
    document.body.appendChild(a); a.click();
    setTimeout(function(){ URL.revokeObjectURL(url); a.remove(); }, 1000);
  }

  document.getElementById('btnDownloadTracciatoPrezzi').addEventListener('click', async function(){
    var btn = this;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Generazione...';

    var wb = new ExcelJS.Workbook();
    wb.creator = 'Quack ERP';
    var ws = wb.addWorksheet('Tariffe');
    ws.columns = COLS_PREZZI.map(function(c){ return {header:c.key, key:c.key, width:Math.max(c.key.length+4,22)}; });

    var hr = ws.getRow(1);
    COLS_PREZZI.forEach(function(c,i){
      var cell = hr.getCell(i+1);
      cell.value = c.key;
      cell.font  = {bold:true, color:{argb:'FFFFFFFF'}};
      cell.fill  = {type:'pattern', pattern:'solid', fgColor:{argb:'FF2E4057'}};
      cell.alignment = {vertical:'middle', horizontal:'center'};
      cell.note  = {texts:[{text:c.note}]};
    });
    hr.height = 22;

    var ex = ws.addRow(COLS_PREZZI.reduce(function(a,c){ a[c.key]=c.esempio; return a; }, {}));
    ex.eachCell(function(cell){
      cell.fill = {type:'pattern', pattern:'solid', fgColor:{argb:'FFEAF2FB'}};
      cell.font = {italic:true, color:{argb:'FF555555'}};
    });
    ws.views = [{state:'frozen', ySplit:1}];

    downloadBuffer(await wb.xlsx.writeBuffer(), 'Listino_Acquisti_Tracciato.xlsx');
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-file-arrow-down me-1"></i>Scarica Modello Tracciato';
  });

  var parsedRows = [];

  document.getElementById('inputFilePrezziAcquisto').addEventListener('change', async function(){
    var file = this.files[0];
    if (!file) return;
    var reader = new FileReader();
    reader.onload = async function(e){
      var wb = new ExcelJS.Workbook();
      await wb.xlsx.load(e.target.result);
      var ws = wb.worksheets[0];
      if (!ws) { alert('File non valido.'); return; }

      var headers = [];
      ws.getRow(1).eachCell({includeEmpty:false}, function(cell){
        headers.push(String(cell.value !== null && cell.value !== undefined ? cell.value : '').trim());
      });

      parsedRows = [];
      ws.eachRow(function(row, rn){
        if (rn === 1) return;
        var obj = {};
        headers.forEach(function(h,i){
          var v = row.getCell(i+1).value;
          obj[h] = (v !== null && v !== undefined) ? String(v).trim() : '';
        });
        if (Object.values(obj).some(function(v){ return v !== ''; })) parsedRows.push(obj);
      });
      renderPreview(headers, parsedRows);
    };
    reader.readAsArrayBuffer(file);
  });

  function renderPreview(headers, rows){
    var th = '<tr>';
    headers.forEach(function(h){ th += '<th style="white-space:nowrap">'+esc(h)+'</th>'; });
    th += '</tr>';
    document.getElementById('importPreviewHeadPrezzi').innerHTML = th;

    var tb = '';
    rows.slice(0,10).forEach(function(row){
      tb += '<tr>';
      headers.forEach(function(h){
        var v = row[h] !== undefined ? row[h] : '';
        var bad = (h==='sku_interno'||h==='prezzo') && v==='';
        tb += '<td style="white-space:nowrap'+(bad?';color:var(--qk-danger);font-weight:600':'')+'">' + esc(v||'--') + '</td>';
      });
      tb += '</tr>';
    });
    if (rows.length > 10)
      tb += '<tr><td colspan="'+headers.length+'" class="text-center qk-text-muted" style="font-size:.8rem">... e altre '+(rows.length-10)+' righe</td></tr>';

    document.getElementById('importPreviewBodyPrezzi').innerHTML = tb;
    document.getElementById('importPreviewCountPrezzi').textContent = rows.length;
    document.getElementById('importPreviewWrapPrezzi').style.display = 'block';
    document.getElementById('btnImportaPrezziAcquisto').disabled = rows.length === 0;
    document.getElementById('importResultPrezzi').style.display  = 'none';
  }

  document.getElementById('btnClearFilePrezzi').addEventListener('click', function(){
    parsedRows = [];
    document.getElementById('inputFilePrezziAcquisto').value = '';
    document.getElementById('importPreviewWrapPrezzi').style.display = 'none';
    document.getElementById('btnImportaPrezziAcquisto').disabled = true;
    document.getElementById('importResultPrezzi').style.display  = 'none';
  });

  document.getElementById('modalImportaPrezziAcquisto').addEventListener('hidden.bs.modal', function(){
    document.getElementById('btnClearFilePrezzi').click();
  });

  function normalizePrezzoHeader(h) {
    var clean = String(h || '').trim().toLowerCase()
      .replace(/[\s\-\_]+/g, '_')
      .replace(/[\(\)]/g, '');
    
    var map = {
      'sku': 'sku_interno',
      'sku_interno': 'sku_interno',
      'codice': 'sku_interno',
      'codice_articolo': 'sku_interno',
      'prodotto_sku': 'sku_interno',
      
      'sku_fornitore': 'sku_fornitore',
      'codice_fornitore': 'sku_fornitore',
      'codice_articolo_fornitore': 'sku_fornitore',
      'part_number': 'sku_fornitore',
      
      'quantita_minima': 'quantita_minima',
      'quantità_minima': 'quantita_minima',
      'quantita': 'quantita_minima',
      'qta': 'quantita_minima',
      
      'prezzo': 'prezzo',
      'prezzo_unitario': 'prezzo',
      'prezzo_acquisto': 'prezzo',
      'costo': 'prezzo',
      'costo_unitario': 'prezzo',
      
      'sconto': 'sconto_perc',
      'sconto_perc': 'sconto_perc',
      'sconto_%': 'sconto_perc',
      
      'valido_dal': 'valido_dal',
      'inizio_validita': 'valido_dal',
      'data_inizio': 'valido_dal',
      
      'valido_al': 'valido_al',
      'fine_validita': 'valido_al',
      'data_fine': 'valido_al',
      
      'note': 'note',
      'note_prezzo': 'note',
      'descrizione': 'note'
    };
    return map[clean] || clean;
  }

  document.getElementById('btnImportaPrezziAcquisto').addEventListener('click', async function(){
    if (!parsedRows.length) return;
    var btn = this;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Importazione...';

    var normalizedRows = parsedRows.map(function(row) {
      var normObj = {};
      Object.keys(row).forEach(function(key) {
        var normKey = normalizePrezzoHeader(key);
        normObj[normKey] = row[key];
      });
      return normObj;
    });

    var fd = new FormData();
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('listino_acquisto_id', LISTINO_ID);
    fd.append('rows', JSON.stringify(normalizedRows));

    try {
      var resp   = await fetch(IMPORT_URL, {method:'POST', body:fd});
      var result = await resp.json();
      var box    = document.getElementById('importResultPrezzi');
      box.style.display = 'block';

      if (!result.ok) {
        box.innerHTML = '<div class="alert alert-danger py-2"><i class="fa-solid fa-triangle-exclamation me-1"></i>' + esc(result.error) + '</div>';
      } else {
        var html = '<div class="alert alert-success py-2 mb-2">'
                 + '<i class="fa-solid fa-circle-check me-1"></i>'
                 + '<strong>'+result.inserted+'</strong> tariffe importate/aggiornate'
                 + (result.skipped>0 ? ', <strong>'+result.skipped+'</strong> saltate' : '') + '.'
                 + '</div>';
        if (result.errors && result.errors.length) {
          html += '<details style="font-size:.8rem"><summary style="cursor:pointer" class="qk-text-muted">Dettaglio righe saltate ('+result.errors.length+')</summary>'
               + '<ul class="mt-1 mb-0">';
          result.errors.forEach(function(e){ html += '<li>'+esc(e)+'</li>'; });
          html += '</ul></details>';
        }
        box.innerHTML = html;
        if (result.inserted > 0) {
          parsedRows = [];
          document.getElementById('importPreviewWrapPrezzi').style.display = 'none';
          setTimeout(function(){ window.location.reload(); }, 1500);
        }
      }
    } catch(err) {
      document.getElementById('importResultPrezzi').innerHTML =
        '<div class="alert alert-danger py-2">Errore di rete: '+esc(err.message)+'</div>';
    }
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-upload me-1"></i>Avvia Importazione';
  });

  function esc(str){
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
})();
</script>

