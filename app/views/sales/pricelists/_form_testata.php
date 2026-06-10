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

      <div class="qk-form-group">
        <label class="qk-form-label">Nome listino <span class="required">*</span></label>
        <input type="text" name="nome"
               class="qk-form-control <?= isset($errors['nome']) ? 'is-invalid' : '' ?>"
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

      <div class="qk-form-row" style="grid-template-columns:110px 1fr 1fr">
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
