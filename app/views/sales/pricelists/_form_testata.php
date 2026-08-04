<?php
/**
 * sales/pricelists/_form_testata.php
 * Card principale: campi nome, descrizione, valuta, date, stato attivo.
 * Variabili attese: $values (array), $errors (array), $isEdit (bool), $id (int|null)
 */
?>
<div>
  <div class="qk-card shadow-sm">

    <div class="qk-card-header">
      <span class="qk-card-title">
        <i class="fa-solid fa-badge-percent me-2" style="color:var(--qk-accent)"></i>
        <?= $isEdit ? e($values['nome']) : 'Nuovo listino vendita' ?>
      </span>
      <?php if ($isEdit): ?>
        <span style="font-size:.8rem;color:var(--qk-text-muted)">ID #<?= (int)$id ?></span>
      <?php endif; ?>
    </div>

    <div class="qk-card-body">
      
      <?php if (!empty($values['is_default'])): ?>
        <div class="qk-alert qk-alert-info mb-3">
          <i class="fa-solid fa-earth-americas me-2"></i> Questo è il <strong>Listino Pubblico Globale</strong> (di default). Si applica automaticamente a tutti i clienti sprovvisti di un listino dedicato.
        </div>
      <?php endif; ?>

      <div class="qk-form-group">
        <label class="qk-form-label">Nome listino <span class="required">*</span></label>
        <input type="text" name="nome"
               class="qk-form-control <?= isset($errors['nome']) ? 'is-invalid' : '' ?>"
               <?= !empty($values['is_default']) ? 'readonly style="background-color: var(--qk-bg-main);"' : '' ?>
               placeholder="Es. Listino Pubblico 2026, Clienti Premium"
               value="<?= e($values['nome']) ?>">
        <?php if (isset($errors['nome'])): ?>
          <div class="qk-form-error"><i class="fa-solid fa-circle-exclamation"></i> <?= e($errors['nome']) ?></div>
        <?php endif; ?>
      </div>

      <div class="qk-form-group">
        <label class="qk-form-label">Descrizione</label>
        <input type="text" name="descrizione" class="qk-form-control"
               placeholder="Canale, segmento clienti, note interne…"
               value="<?= e($values['descrizione'] ?? '') ?>">
      </div>

      <div class="qk-form-row" style="grid-template-columns:100px 110px 1fr 1fr">
        <div class="qk-form-group">
          <label class="qk-form-label">Sconto %</label>
          <div class="input-group input-group-sm">
            <input type="number" name="sconto_default_perc" step="0.01"
                   class="form-control"
                   placeholder="0.00"
                   value="<?= e($values['sconto_default_perc'] ?? '0.00') ?>">
            <span class="input-group-text">%</span>
          </div>
        </div>
        <div class="qk-form-group">
          <label class="qk-form-label">Valuta</label>
          <input type="text" name="valuta"
                 class="qk-form-control <?= isset($errors['valuta']) ? 'is-invalid' : '' ?>"
                 maxlength="3" placeholder="EUR" style="text-transform:uppercase"
                 value="<?= e($values['valuta'] ?? 'EUR') ?>">
          <?php if (isset($errors['valuta'])): ?>
            <div class="qk-form-error"><i class="fa-solid fa-circle-exclamation"></i> <?= e($errors['valuta']) ?></div>
          <?php endif; ?>
        </div>
<?php if (empty($values['is_default'])): ?>
        <div class="qk-form-group">
          <label class="qk-form-label">Valido dal</label>
          <input type="date" name="valido_dal" class="qk-form-control"
                 value="<?= e($values['valido_dal']) ?>">
        </div>
        <div class="qk-form-group">
          <label class="qk-form-label">Valido al</label>
          <input type="date" name="valido_al" class="qk-form-control"
                 value="<?= e($values['valido_al']) ?>">
        </div>
      </div>

      <div class="qk-form-group mb-0">
        <label class="qk-form-label">Stato</label>
        <label class="qk-flex-row qk-gap-sm" style="cursor:pointer;width:fit-content">
          <input type="hidden" name="attivo" value="0">
          <input class="form-check-input" type="checkbox" name="attivo" id="pl-attivo"
                 value="1" <?= ($values['attivo'] ?? 1) ? 'checked' : '' ?>
                 style="width:2.4rem;height:1.2rem;cursor:pointer;margin:0">
          <span style="font-size:.875rem;color:var(--qk-text-primary)" id="pl-attivo-label">
            <?= ($values['attivo'] ?? 1) ? 'Listino attivo' : 'Listino disattivato' ?>
          </span>
        </label>
      </div>
<?php else: ?>
        <div style="grid-column: 3 / -1; display: flex; align-items: flex-end;">
            <div class="qk-alert qk-alert-success m-0 w-100 py-2 d-flex align-items-center">
                <i class="fa-solid fa-check-circle me-2"></i>
                <span style="font-size:.85rem"><strong>Sempre attivo</strong> (nessuna scadenza applicabile al catalogo base)</span>
            </div>
        </div>
      </div>
<?php endif; ?>

    </div>

    <div class="qk-card-footer qk-flex-row qk-justify-between">
      <a href="<?= url('sales/pricelists') ?>" class="btn-qk btn-qk-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i>Annulla
      </a>
      <button type="submit" class="btn-qk btn-qk-primary">
        <i class="fa-solid fa-floppy-disk me-1"></i>Salva listino
      </button>
    </div>

  </div>
</div>
