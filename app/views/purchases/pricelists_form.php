<?php
/**
 * purchases/pricelists_form.php — Form di creazione/modifica Listino Acquisto.
 */
?>

<?php $active_tab = 'pricelists'; include __DIR__ . '/_subnav.php'; ?>

<?php if (!empty($errors['_global'])): ?>
  <div class="qk-alert qk-alert-danger mb-3">
    <i class="fa-solid fa-circle-exclamation"></i>
    <span><?= e($errors['_global']) ?></span>
  </div>
<?php endif; ?>

<form action="" method="post">
  <?= csrf_field() ?>

  <div class="qk-form-grid">

    <!-- Card principale -->
    <div>
      <div class="qk-card shadow-sm">

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

          <!-- Nome Listino -->
          <div class="qk-form-group">
            <label class="qk-form-label">Nome listino <span class="required">*</span></label>
            <input type="text" name="nome_listino"
                   class="qk-form-control <?= isset($errors['nome_listino']) ? 'is-invalid' : '' ?>"
                   placeholder="Es. Listino Base 2026, Promo Estate"
                   value="<?= e($values['nome_listino']) ?>">
            <?php if (isset($errors['nome_listino'])): ?>
              <div class="qk-form-error"><i class="fa-solid fa-circle-exclamation"></i> <?= e($errors['nome_listino']) ?></div>
            <?php endif; ?>
          </div>

          <!-- Fornitore -->
          <div class="qk-form-group">
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

          <!-- Date validità -->
          <div class="qk-form-row" style="grid-template-columns:1fr 1fr">
            <div class="qk-form-group">
              <label class="qk-form-label">Valido dal</label>
              <input type="date" name="valido_dal" class="qk-form-control" value="<?= e($values['valido_dal']) ?>">
            </div>
            <div class="qk-form-group">
              <label class="qk-form-label">Valido al</label>
              <input type="date" name="valido_al" class="qk-form-control" value="<?= e($values['valido_al']) ?>">
            </div>
          </div>

          <!-- Note -->
          <div class="qk-form-group mb-0">
            <label class="qk-form-label">Note</label>
            <textarea name="note" class="qk-form-control" rows="3"
                      placeholder="Aggiungi dettagli aggiuntivi su questo accordo o listino…"><?= e($values['note']) ?></textarea>
          </div>

        </div>

        <div class="qk-card-footer" style="display:flex;justify-content:space-between;align-items:center">
          <a href="<?= url('purchases/pricelists') ?>" class="btn-qk btn-qk-secondary">
            <i class="fa-solid fa-arrow-left me-1"></i>Annulla
          </a>
          <button type="submit" class="btn-qk btn-qk-primary">
            <i class="fa-solid fa-floppy-disk me-1"></i>Salva listino
          </button>
        </div>

      </div>
    </div>

    <!-- Sidebar informazioni -->
    <div>
      <div class="qk-card shadow-sm" style="border-left:3px solid var(--qk-info)">
        <div class="qk-card-header" style="padding:.75rem 1rem">
          <span class="qk-card-title" style="font-size:.8rem">
            <i class="fa-solid fa-circle-info me-2" style="color:var(--qk-info)"></i>Informazioni
          </span>
        </div>
        <div class="qk-card-body" style="padding:.85rem 1rem;font-size:.82rem;color:var(--qk-text-muted);line-height:1.55">
          <p style="margin:0 0 .5rem">I listini consentono di impostare regole di acquisto temporanee o accordate con il fornitore.</p>
          <p style="margin:0">Una volta creato il listino, puoi associare ad esso i prodotti e le tariffe dalla <strong>scheda del prodotto (tab Fornitori)</strong> selezionando questo listino nel menu a tendina.</p>
        </div>
      </div>
    </div>

  </div>

</form>

<?php if ($isEdit): ?>
  <!-- Griglia Articoli Collegati -->
  <div class="qk-card mt-4">
    <h4 class="mb-4 text-uppercase small text-muted fw-bold"><i class="fa-solid fa-file-spreadsheet me-2"></i>Articoli e Tariffe in questo Listino</h4>
    
    <div class="qk-table-wrap">
      <?php if (empty($items)): ?>
        <div class="text-center py-4 qk-text-muted">
          <i class="fa-solid fa-file-spreadsheet fs-2 mb-2"></i>
          <p class="mb-0">Nessun articolo associato a questo listino.</p>
          <p class="small">Puoi associare i prezzi a questo listino modificando la scheda di un prodotto nella sezione <strong>Fornitori</strong>.</p>
        </div>
      <?php else: ?>
        <table class="qk-table">
          <thead>
            <tr>
              <th>Prodotto</th>
              <th>SKU Interno</th>
              <th>SKU Fornitore</th>
              <th class="text-end" style="width:120px">Q.tà Minima</th>
              <th class="text-end" style="width:140px">Prezzo Unitario</th>
              <th class="text-end" style="width:100px">Sconto %</th>
              <th style="width:180px">Validità Riga</th>
              <th>Note Prezzo</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $item): ?>
              <tr>
                <td>
                  <a href="<?= url('products/edit?id=' . $item['prodotto_id']) ?>" class="fw-semibold qk-text-accent">
                    <?= e($item['prodotto_nome']) ?>
                  </a>
                </td>
                <td><code class="text-monospace"><?= e($item['sku_interno']) ?></code></td>
                <td><code class="text-monospace"><?= e($item['sku_fornitore'] ?? '—') ?></code></td>
                <td class="text-end text-monospace"><?= number_format((float)$item['quantita_minima'], 3, ',', '.') ?></td>
                <td class="text-end fw-bold text-monospace"><?= number_format((float)$item['prezzo'], 4, ',', '.') ?> €</td>
                <td class="text-end text-monospace"><?= number_format((float)$item['sconto_perc'], 2, ',', '.') ?> %</td>
                <td>
                  <span class="small text-monospace">
                    <?php if (!empty($item['valido_dal']) || !empty($item['valido_al'])): ?>
                      <?= !empty($item['valido_dal']) ? date('d/m/Y', strtotime($item['valido_dal'])) : '...' ?>
                      &rarr;
                      <?= !empty($item['valido_al']) ? date('d/m/Y', strtotime($item['valido_al'])) : '...' ?>
                    <?php else: ?>
                      Sempre valido
                    <?php endif; ?>
                  </span>
                </td>
                <td class="qk-text-muted small"><?= e($item['note'] ?? '') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<!-- Script Autocomplete (Many2one) -->
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
      labelKey: 'label',
      columns: [
        { key: 'label', label: 'Fornitore' },
        { key: 'email', label: 'E-mail' }
      ]
    });

    if (hiddenEl.value) {
      clearBtn.classList.remove('d-none');
    }
