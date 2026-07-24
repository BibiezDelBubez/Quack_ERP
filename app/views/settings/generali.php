<?php
/**
 * settings/generali.php
 * Gestione delle configurazioni generali del sistema (Ricarico Fallback Globale).
 * Variabili: $values (array), $errors (array), $success (bool)
 */
?>

<?php if ($success): ?>
<div class="qk-alert qk-alert-success mb-3" data-auto-dismiss="4000">
  <i class="fa-solid fa-circle-check"></i>
  <span>Impostazioni di sistema salvate correttamente.</span>
  <button class="qk-alert-close"><i class="fa-solid fa-xmark"></i></button>
 </div>
<?php endif; ?>

<?php if (!empty($errors['_global'])): ?>
<div class="qk-alert qk-alert-danger mb-3">
  <i class="fa-solid fa-circle-exclamation"></i>
  <span><?= e($errors['_global']) ?></span>
  <button class="qk-alert-close"><i class="fa-solid fa-xmark"></i></button>
</div>
<?php endif; ?>

<form method="post" id="formGenerali" action="<?= url('settings/generali') ?>">
  <?= csrf_field() ?>

  <div class="qk-form-grid">

    <!-- Colonna principale -->
    <div>

      <!-- Card Configurazione Ricarico Fallback -->
      <div class="qk-card mb-3">
        <div class="qk-card-header">
          <span class="qk-card-title">
            <i class="fa-solid fa-calculator me-2" style="color:var(--qk-accent)"></i>Margini e Pricing di Fallback
          </span>
        </div>
        <div class="qk-card-body">

          <!-- Ricarico di fallback globale -->
          <div class="qk-form-group">
            <label class="qk-form-label">
              Ricarico Fallback Globale (%) <span class="required">*</span>
              <span class="ms-1 text-primary"
                    data-bs-toggle="tooltip" data-bs-placement="top"
                    title="Questo valore percentuale stabilisce il ricarico di default da applicare sul costo del fornitore quando non ci sono listini dedicati o prezzi standard. Può essere sovrascritto a livello di singolo prodotto."
                    style="cursor:help">
                <i class="fa-solid fa-circle-question" style="color:var(--qk-text-muted);font-size:.9em"></i>
              </span>
            </label>
            <div class="input-group" style="max-width: 250px;">
              <input type="number" name="markup_fallback_default"
                     class="qk-form-control <?= isset($errors['markup_fallback_default']) ? 'is-invalid' : '' ?>"
                     value="<?= e($values['markup_fallback_default']) ?>"
                     placeholder="20.00" min="0" max="1000" step="0.01">
              <span class="input-group-text">%</span>
            </div>
            <?php if (isset($errors['markup_fallback_default'])): ?>
              <div class="qk-form-error mt-1"><i class="fa-solid fa-circle-exclamation"></i> <?= e($errors['markup_fallback_default']) ?></div>
            <?php endif; ?>
            <span class="qk-form-hint mt-2 d-block">
              Viene applicato automaticamente sul costo d'acquisto del fornitore preferenziale se non è presente un prezzo di listino specifico per l'articolo e per il cliente.
            </span>
          </div>

          <hr class="my-4" style="border-color:var(--qk-border)">

          <!-- PIANO - Soglie Margine Configurabili (§1.4) -->
          <div class="qk-form-group">
            <label class="qk-form-label">
              Semaforo Margine % (preventivi e ordini)
              <span class="ms-1 text-primary"
                    data-bs-toggle="tooltip" data-bs-placement="top"
                    title="Soglie usate per colorare il margine percentuale calcolato nei form Preventivo e Ordine Cliente diretto: verde se il margine è almeno pari alla soglia verde, giallo se è almeno pari alla soglia gialla, rosso sotto la soglia gialla."
                    style="cursor:help">
                <i class="fa-solid fa-circle-question" style="color:var(--qk-text-muted);font-size:.9em"></i>
              </span>
            </label>
            <div class="d-flex gap-3 flex-wrap">
              <div>
                <label class="qk-form-label small mb-1" for="margine_soglia_verde">Soglia verde (%) <span class="required">*</span></label>
                <div class="input-group" style="max-width: 180px;">
                  <input type="number" id="margine_soglia_verde" name="margine_soglia_verde"
                         class="qk-form-control <?= isset($errors['margine_soglia_verde']) ? 'is-invalid' : '' ?>"
                         value="<?= e($values['margine_soglia_verde']) ?>"
                         placeholder="20.00" min="0" max="100" step="0.01">
                  <span class="input-group-text">%</span>
                </div>
              </div>
              <div>
                <label class="qk-form-label small mb-1" for="margine_soglia_giallo">Soglia gialla (%) <span class="required">*</span></label>
                <div class="input-group" style="max-width: 180px;">
                  <input type="number" id="margine_soglia_giallo" name="margine_soglia_giallo"
                         class="qk-form-control <?= isset($errors['margine_soglia_giallo']) ? 'is-invalid' : '' ?>"
                         value="<?= e($values['margine_soglia_giallo']) ?>"
                         placeholder="10.00" min="0" max="100" step="0.01">
                  <span class="input-group-text">%</span>
                </div>
              </div>
            </div>
            <?php if (isset($errors['margine_soglia_verde'])): ?>
              <div class="qk-form-error mt-1"><i class="fa-solid fa-circle-exclamation"></i> <?= e($errors['margine_soglia_verde']) ?></div>
            <?php endif; ?>
            <?php if (isset($errors['margine_soglia_giallo'])): ?>
              <div class="qk-form-error mt-1"><i class="fa-solid fa-circle-exclamation"></i> <?= e($errors['margine_soglia_giallo']) ?></div>
            <?php endif; ?>
            <span class="qk-form-hint mt-2 d-block">
              Sotto la soglia gialla il margine è mostrato in rosso. La soglia verde deve essere maggiore della soglia gialla.
            </span>
          </div>

        </div>
      </div>

    </div><!-- /colonna principale -->

    <!-- Pannello stato (destro) -->
    <div class="qk-status-panel">
      <div class="qk-status-panel-hd">Impostazioni ERP</div>
      <div class="qk-status-panel-body">
        <p class="small text-muted mb-4">
          Questi parametri definiscono il comportamento logico-commerciale di Quack ERP al fine di evitare vendite sottocosto o inserimento manuale obbligatorio di listini per ogni articolo.
        </p>

        <div class="qk-status-panel-actions">
          <button type="submit" class="btn-qk btn-qk-primary">
            <i class="fa-solid fa-floppy-disk me-1"></i>Salva impostazioni
          </button>
          <a href="<?= url('settings') ?>" class="btn-qk btn-qk-secondary">Annulla</a>
        </div>
      </div>
    </div>

  </div><!-- /qk-form-grid -->
</form>
