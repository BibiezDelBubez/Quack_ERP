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
            <div class="qk-card-header">
              <span class="qk-card-title">
                <i class="fa-solid fa-file-spreadsheet me-2" style="color:var(--qk-accent)"></i>Tariffe concordate per articolo
              </span>
              <button type="button" id="btn-add-riga-acquisto" class="btn-qk btn-qk-primary btn-qk-sm">
                <i class="fa-solid fa-plus me-1"></i>Aggiungi prodotto
              </button>
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
              /* Riduci padding nelle celle per massimizzare lo spazio */
              #tbl-prezzi-acquisto td, #tbl-prezzi-acquisto th {
                padding: .4rem .4rem !important;
                vertical-align: middle;
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

            <div class="qk-table-wrap">
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
                        <div class="pl-view d-flex gap-1 justify-content-end">
                          <button type="button" class="btn-qk btn-qk-secondary btn-qk-sm px-2 btn-edit-riga" title="Modifica"><i class="fa-solid fa-pencil"></i></button>
                          <button type="button" class="btn-qk btn-qk-danger btn-qk-sm px-2 btn-delete-riga" title="Rimuovi"><i class="fa-solid fa-trash-can"></i></button>
                        </div>
                        <div class="pl-edit d-flex gap-1 justify-content-end">
                          <button type="button" class="btn-qk btn-qk-success btn-qk-sm px-2 btn-confirm-riga" title="Salva"><i class="fa-solid fa-check"></i></button>
                          <button type="button" class="btn-qk btn-qk-secondary btn-qk-sm px-2 btn-cancel-riga" title="Annulla"><i class="fa-solid fa-xmark"></i></button>
                        </div>
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
      <div class="pl-edit d-flex gap-1 justify-content-end">
        <button type="button" class="btn-qk btn-qk-success btn-qk-sm px-2 btn-confirm-riga" title="Salva"><i class="fa-solid fa-check"></i></button>
        <button type="button" class="btn-qk btn-qk-secondary btn-qk-sm px-2 btn-cancel-riga" title="Annulla"><i class="fa-solid fa-xmark"></i></button>
      </div>
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

    document.getElementById('btn-add-riga-acquisto').addEventListener('click', () => {
      tbody.querySelectorAll('.pl-riga.is-editing').forEach(tr => cancelEdit(tr));
      addNewRow();
    });

    function addNewRow() {
      const fragment = tpl.content.cloneNode(true);
      tbody.appendChild(fragment);
      const newTr = tbody.lastElementChild;
      syncEmpty();
      initNewRowM2O(newTr);
      newTr.querySelector('.r-prodotto-label')?.focus();
      newTr.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    tbody.addEventListener('click', function (e) {
      const tr = e.target.closest('tr.pl-riga');
      if (!tr) return;

      if (e.target.closest('.btn-edit-riga')) { enterEdit(tr); }
      else if (e.target.closest('.btn-confirm-riga')) { confirmEdit(tr); }
      else if (e.target.closest('.btn-cancel-riga')) { cancelEdit(tr); }
      else if (e.target.closest('.btn-delete-riga')) { deleteRow(tr); }
    });

    tbody.addEventListener('keydown', function (e) {
      const tr = e.target.closest('tr.pl-riga.is-editing');
      if (!tr) return;
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        confirmEdit(tr);
      }
      if (e.key === 'Escape') {
        e.preventDefault();
        cancelEdit(tr);
      }
    });

    function enterEdit(tr) {
      tbody.querySelectorAll('.pl-riga.is-editing').forEach(other => {
        if (other !== tr) cancelEdit(other);
      });
      // Popola gli input con i dataset
      tr.querySelector('.r-sku-forn').value = tr.dataset.skuFornitore || '';
      tr.querySelector('.r-qmin').value = tr.dataset.qmin || '0';
      tr.querySelector('.r-prezzo').value = tr.dataset.prezzo || '';
      tr.querySelector('.r-sconto').value = tr.dataset.sconto || '0';
      tr.querySelector('.r-valido-dal').value = tr.dataset.validoDal || '';
      tr.querySelector('.r-valido-al').value = tr.dataset.validoAl || '';
      tr.querySelector('.r-note').value = tr.dataset.note || '';
      tr.classList.add('is-editing');
      tr.querySelector('.r-sku-forn')?.focus();
    }

    async function confirmEdit(tr) {
      const isNew = tr.dataset.rigaId === '0';
      const prodottoId = isNew ? tr.querySelector('.r-prodotto-id').value : tr.dataset.prodottoId;
      const prezzo = tr.querySelector('.r-prezzo').value.trim();

      if (!prodottoId) { flashRow(tr, 'Seleziona un prodotto.'); return; }
      if (!prezzo || isNaN(parseFloat(prezzo))) { flashRow(tr, 'Inserisci un prezzo valido.'); return; }

      const btn = tr.querySelector('.btn-confirm-riga');
      btn.classList.add('is-saving');

      try {
        const res = await fetch(BASE + '/purchases/pricelists/righe/save', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({
            _token: CSRF,
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
        if (!d.ok) { flashRow(tr, d.error || 'Errore durante il salvataggio.'); return; }

        applyRigaData(tr, d.riga);
        tr.classList.remove('is-editing');
        tr.classList.add('pl-riga-saved');
        setTimeout(() => tr.classList.remove('pl-riga-saved'), 800);
        syncEmpty();
      } catch (err) {
        flashRow(tr, 'Errore di rete.');
      } finally {
        btn.classList.remove('is-saving');
      }
    }

    function cancelEdit(tr) {
      if (tr.dataset.rigaId === '0') {
        tr.remove();
      } else {
        tr.classList.remove('is-editing');
      }
      syncEmpty();
    }

    async function deleteRow(tr) {
      if (!confirm('Rimuovere questa tariffa dal listino?')) return;
      try {
        const res = await fetch(BASE + '/purchases/pricelists/righe/delete', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ _token: CSRF, riga_id: tr.dataset.rigaId })
        });
        const d = await res.json();
        if (d.ok) {
          tr.remove();
          syncEmpty();
        } else {
          alert(d.error || 'Errore durante l\'eliminazione.');
        }
      } catch (err) {
        alert('Errore di rete.');
      }
    }

    function applyRigaData(tr, r) {
      tr.dataset.rigaId = r.id;
      tr.dataset.prodottoId = r.prodotto_id;
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
          <div class="pl-view d-flex gap-1 justify-content-end">
            <button type="button" class="btn-qk btn-qk-secondary btn-qk-sm px-2 btn-edit-riga" title="Modifica"><i class="fa-solid fa-pencil"></i></button>
            <button type="button" class="btn-qk btn-qk-danger btn-qk-sm px-2 btn-delete-riga" title="Rimuovi"><i class="fa-solid fa-trash-can"></i></button>
          </div>
          <div class="pl-edit d-flex gap-1 justify-content-end">
            <button type="button" class="btn-qk btn-qk-success btn-qk-sm px-2 btn-confirm-riga" title="Salva"><i class="fa-solid fa-check"></i></button>
            <button type="button" class="btn-qk btn-qk-secondary btn-qk-sm px-2 btn-cancel-riga" title="Annulla"><i class="fa-solid fa-xmark"></i></button>
          </div>
        </td>
      `;
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
  }
});
</script>
