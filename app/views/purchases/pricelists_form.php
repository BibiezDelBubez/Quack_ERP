<?php
/**
 * purchases/pricelists_form.php — Form di creazione/modifica Listino Acquisto.
 */
?>

<?php if (!empty($errors['_global'])): ?>
  <div class="qk-alert qk-alert-danger mb-3">
    <i class="fa-solid fa-circle-exclamation"></i>
    <span><?= e($errors['_global']) ?></span>
  </div>
<?php endif; ?>

<form action="" method="post" class="needs-validation" novalidate>
  <?= csrf_field() ?>

  <div class="row">
    <!-- Scheda Informazioni Generali -->
    <div class="col-12 col-lg-8">
      <div class="qk-card mb-4">
        <h4 class="mb-4 text-uppercase small text-muted fw-bold">Dettagli Listino</h4>

        <div class="row g-3">
          <!-- Nome Listino -->
          <div class="col-12 col-md-6">
            <label class="form-label fw-bold small text-uppercase">Nome Listino <span class="text-danger">*</span></label>
            <input type="text" name="nome_listino" class="qk-form-control" placeholder="Es. Listino Base 2026, Promo Estate" value="<?= e($values['nome_listino']) ?>" required>
            <?php if (isset($errors['nome_listino'])): ?>
              <div class="text-danger small mt-1"><?= e($errors['nome_listino']) ?></div>
            <?php endif; ?>
          </div>

          <!-- Fornitore Autocomplete (Many2one) -->
          <div class="col-12 col-md-6">
            <label class="form-label fw-bold small text-uppercase">Fornitore <span class="text-danger">*</span></label>
            <div class="qk-m2o-wrap position-relative">
              <input type="hidden" name="fornitore_id" id="pl-fornitore-id" value="<?= e($values['fornitore_id']) ?>">
              <input type="text" class="qk-form-control qk-m2o-input" id="pl-fornitore-label" placeholder="Cerca fornitore..." value="<?= e($values['fornitore_label'] ?? '') ?>" autocomplete="off" required <?= $isEdit ? 'disabled' : '' ?>>
              <button type="button" class="qk-m2o-clear d-none" id="pl-fornitore-clear" <?= $isEdit ? 'style="display:none"' : '' ?>>&times;</button>
              <div class="qk-m2o-dropdown shadow-sm" id="pl-fornitore-dropdown"></div>
            </div>
            <?php if (isset($errors['fornitore_id'])): ?>
              <div class="text-danger small mt-1"><?= e($errors['fornitore_id']) ?></div>
            <?php endif; ?>
            <?php if ($isEdit): ?>
              <div class="form-text text-muted" style="font-size:0.75rem">Il fornitore non può essere modificato dopo la creazione.</div>
            <?php endif; ?>
          </div>
        </div>

        <div class="row g-3 mt-2">
          <!-- Valido Dal -->
          <div class="col-12 col-md-6">
            <label class="form-label fw-bold small text-uppercase">Valido Dal</label>
            <input type="date" name="valido_dal" class="qk-form-control" value="<?= e($values['valido_dal']) ?>">
          </div>

          <!-- Valido Al -->
          <div class="col-12 col-md-6">
            <label class="form-label fw-bold small text-uppercase">Valido Al</label>
            <input type="date" name="valido_al" class="qk-form-control" value="<?= e($values['valido_al']) ?>">
          </div>
        </div>

        <!-- Note -->
        <div class="mb-3 mt-3">
          <label class="form-label fw-bold small text-uppercase">Note</label>
          <textarea name="note" class="qk-form-control" rows="3" placeholder="Aggiungi dettagli aggiuntivi su questo accordo o listino..."><?= e($values['note']) ?></textarea>
        </div>
        
        <!-- Pulsanti Azione -->
        <div class="d-flex justify-content-between align-items-center border-top pt-3 mt-4">
          <a href="<?= url('purchases/pricelists') ?>" class="btn-qk btn-qk-secondary">Annulla</a>
          <button type="submit" class="btn-qk btn-qk-primary">
            <i class="fa-solid fa-floppy-disk me-1"></i>Salva Listino
          </button>
        </div>
      </div>
    </div>

    <!-- Guida Utente Sidebar -->
    <div class="col-12 col-lg-4">
      <div class="qk-card mb-4" style="background-color: var(--qk-bg-card-secondary, #fafafa); border-left: 4px solid var(--qk-info);">
        <h5 class="fw-bold mb-3"><i class="fa-solid fa-circle-info me-2 text-info"></i>Informazioni</h5>
        <p class="small qk-text-muted">I listini consentono di impostare regole di acquisto temporanee o accordate con il fornitore.</p>
        <p class="small qk-text-muted mb-0">Una volta creato il listino, puoi associare ad esso i prodotti e le tariffe relative dalla **scheda del prodotto (tab Fornitori)** selezionando questo listino nel menu a tendina.</p>
      </div>
    </div>
  </div>
</form>

<?php if ($isEdit): ?>
  <!-- Griglia Articoli Collegati -->
  <div class="qk-card mt-4">
    <h4 class="mb-4 text-uppercase small text-muted fw-bold"><i class="fa-solid fa-receipt me-2"></i>Articoli e Tariffe in questo Listino</h4>
    
    <div class="qk-table-wrap">
      <?php if (empty($items)): ?>
        <div class="text-center py-4 qk-text-muted">
          <i class="fa-solid fa-tags fs-2 mb-2"></i>
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
  }
});
</script>
