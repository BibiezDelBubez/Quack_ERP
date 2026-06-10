<?php
/**
 * users/_form.php
 * Variabili attese: $mode ('create'|'edit'), $entity_id (?int), $values (array), $errors (array)
 */
$isEdit     = ($mode ?? 'create') === 'edit';
$action_url = $isEdit ? url('users/edit?id=' . (int)($entity_id ?? 0)) : url('users/create');
$v          = $values ?? [];

$ruoloLabels = [
    'admin'        => 'Amministratore',
    'commerciale'  => 'Commerciale',
    'magazziniere' => 'Magazziniere',
    'readonly'     => 'Solo lettura',
];
$ruoloDesc = [
    'admin'        => 'Accesso completo a tutte le funzioni e impostazioni.',
    'commerciale'  => 'Gestione vendite, clienti, preventivi e ordini.',
    'magazziniere' => 'Gestione magazzino, movimenti e inventari.',
    'readonly'     => 'Visualizzazione senza possibilità di modificare dati.',
];
?>

<?php if (!empty($errors['_global'])): ?>
<div class="qk-alert qk-alert-danger mb-4">
  <i class="fa-solid fa-circle-xmark"></i>
  <span><?= e($errors['_global']) ?></span>
</div>
<?php endif; ?>

<div class="qk-form-grid no-sidebar" style="max-width:680px">
  <form method="post" action="<?= e($action_url) ?>" novalidate autocomplete="off">
    <?= csrf_field() ?>

    <!-- ── Dati personali ──────────────────────────────────────────────── -->
    <div class="qk-card mb-3">
      <div class="qk-card-header">
        <span class="qk-card-title"><i class="fa-solid fa-user fa-fw me-2 qk-text-accent"></i>Dati personali</span>
      </div>
      <div class="qk-card-body">

        <div class="qk-form-row mb-3">
          <div>
            <label class="qk-form-label">Nome <span class="required">*</span></label>
            <input type="text" name="nome" autocomplete="given-name"
                   class="qk-form-control <?= isset($errors['nome']) ? 'is-invalid' : '' ?>"
                   value="<?= e($v['nome'] ?? '') ?>" placeholder="es. Mario">
            <?php if (!empty($errors['nome'])): ?>
              <div class="qk-field-error"><?= e($errors['nome']) ?></div>
            <?php endif; ?>
          </div>
          <div>
            <label class="qk-form-label">Cognome <span class="required">*</span></label>
            <input type="text" name="cognome" autocomplete="family-name"
                   class="qk-form-control <?= isset($errors['cognome']) ? 'is-invalid' : '' ?>"
                   value="<?= e($v['cognome'] ?? '') ?>" placeholder="es. Rossi">
            <?php if (!empty($errors['cognome'])): ?>
              <div class="qk-field-error"><?= e($errors['cognome']) ?></div>
            <?php endif; ?>
          </div>
        </div>

        <div class="qk-form-row">
          <div>
            <label class="qk-form-label">Username <span class="required">*</span></label>
            <input type="text" name="username" autocomplete="username"
                   class="qk-form-control <?= isset($errors['username']) ? 'is-invalid' : '' ?>"
                   value="<?= e($v['username'] ?? '') ?>" placeholder="es. mario.rossi">
            <?php if (!empty($errors['username'])): ?>
              <div class="qk-field-error"><?= e($errors['username']) ?></div>
            <?php else: ?>
              <div class="qk-form-hint">3–50 caratteri: lettere, numeri, punto, trattino.</div>
            <?php endif; ?>
          </div>
          <div>
            <label class="qk-form-label">Email <span class="required">*</span></label>
            <input type="email" name="email" autocomplete="email"
                   class="qk-form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                   value="<?= e($v['email'] ?? '') ?>" placeholder="es. mario@azienda.it">
            <?php if (!empty($errors['email'])): ?>
              <div class="qk-field-error"><?= e($errors['email']) ?></div>
            <?php endif; ?>
          </div>
        </div>

      </div>
    </div>

    <!-- ── Accesso e permessi ───────────────────────────────────────────── -->
    <div class="qk-card mb-3">
      <div class="qk-card-header">
        <span class="qk-card-title"><i class="fa-solid fa-shield-halved fa-fw me-2 qk-text-accent"></i>Accesso e permessi</span>
      </div>
      <div class="qk-card-body">

        <div class="mb-4">
          <label class="qk-form-label">Ruolo <span class="required">*</span></label>
          <div class="d-flex flex-column gap-2 mt-1">
            <?php foreach ($ruoloLabels as $slug => $label): ?>
            <label class="qk-radio-option <?= ($v['ruolo'] ?? '') === $slug ? 'is-selected' : '' ?>">
              <input type="radio" name="ruolo" value="<?= $slug ?>"
                     <?= ($v['ruolo'] ?? '') === $slug ? 'checked' : '' ?>>
              <span class="qk-radio-option-body">
                <span class="qk-radio-option-label"><?= $label ?></span>
                <span class="qk-radio-option-desc"><?= $ruoloDesc[$slug] ?></span>
              </span>
            </label>
            <?php endforeach; ?>
          </div>
          <?php if (!empty($errors['ruolo'])): ?>
            <div class="qk-field-error mt-1"><?= e($errors['ruolo']) ?></div>
          <?php endif; ?>
        </div>

        <div>
          <label class="qk-form-label">Stato account</label>
          <div class="d-flex gap-2 mt-1">
            <label class="qk-radio-option horizontal <?= ($v['attivo'] ?? '1') === '1' ? 'is-selected' : '' ?>">
              <input type="radio" name="attivo" value="1" <?= ($v['attivo'] ?? '1') === '1' ? 'checked' : '' ?>>
              <span class="qk-radio-option-body">
                <i class="fa-solid fa-circle-check" style="color:var(--qk-success)"></i>
                <span class="qk-radio-option-label">Attivo</span>
              </span>
            </label>
            <label class="qk-radio-option horizontal <?= ($v['attivo'] ?? '1') === '0' ? 'is-selected' : '' ?>">
              <input type="radio" name="attivo" value="0" <?= ($v['attivo'] ?? '1') === '0' ? 'checked' : '' ?>>
              <span class="qk-radio-option-body">
                <i class="fa-solid fa-ban" style="color:var(--qk-danger)"></i>
                <span class="qk-radio-option-label">Disabilitato</span>
              </span>
            </label>
          </div>
        </div>

      </div>
    </div>

    <!-- ── Password ─────────────────────────────────────────────────────── -->
    <div class="qk-card mb-4">
      <div class="qk-card-header">
        <span class="qk-card-title"><i class="fa-solid fa-lock fa-fw me-2 qk-text-accent"></i><?= $isEdit ? 'Cambia password' : 'Password' ?></span>
        <?php if ($isEdit): ?>
          <span class="qk-text-muted" style="font-size:.8rem">Lascia vuoto per non modificare</span>
        <?php endif; ?>
      </div>
      <div class="qk-card-body">
        <div class="qk-form-row">
          <div>
            <label class="qk-form-label"><?= $isEdit ? 'Nuova password' : 'Password' ?><?= !$isEdit ? ' <span class="required">*</span>' : '' ?></label>
            <input type="password" name="password" autocomplete="new-password"
                   class="qk-form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>">
            <?php if (!empty($errors['password'])): ?>
              <div class="qk-field-error"><?= e($errors['password']) ?></div>
            <?php else: ?>
              <div class="qk-form-hint">Minimo 8 caratteri.</div>
            <?php endif; ?>
          </div>
          <div>
            <label class="qk-form-label">Conferma password<?= !$isEdit ? ' <span class="required">*</span>' : '' ?></label>
            <input type="password" name="password_confirm" autocomplete="new-password"
                   class="qk-form-control <?= isset($errors['password_confirm']) ? 'is-invalid' : '' ?>">
            <?php if (!empty($errors['password_confirm'])): ?>
              <div class="qk-field-error"><?= e($errors['password_confirm']) ?></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Azioni ────────────────────────────────────────────────────────── -->
    <div class="d-flex justify-content-between align-items-center">
      <a href="<?= url('users') ?>" class="btn-qk btn-qk-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i>Annulla
      </a>
      <button type="submit" class="btn-qk btn-qk-primary">
        <i class="fa-solid fa-floppy-disk me-1"></i><?= $isEdit ? 'Salva modifiche' : 'Crea utente' ?>
      </button>
    </div>

  </form>
</div>
