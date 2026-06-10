<div class="qk-card" style="max-width:640px">

  <?php if ($ok): ?>
  <div class="qk-alert qk-alert-success mb-4" data-auto-dismiss="4000">
    <i class="fa-solid fa-circle-check"></i>
    <span><?= e($ok) ?></span>
  </div>
  <?php endif; ?>

  <?php if (!empty($errors['_global'])): ?>
  <div class="qk-alert qk-alert-danger mb-4">
    <i class="fa-solid fa-circle-xmark"></i>
    <span><?= e($errors['_global']) ?></span>
  </div>
  <?php endif; ?>

  <div class="qk-flex-row qk-gap-md mb-4 pb-4 border-bottom">
    <?php
      $words = array_filter(explode(' ', $display_name));
      $ini   = mb_strtoupper(mb_substr(array_values($words)[0]??'U',0,1).mb_substr(array_values($words)[1]??'',0,1));
    ?>
    <div class="qk-avatar qk-avatar-lg"><?= e($ini) ?></div>
    <div>
      <div class="fw-semibold fs-5"><?= e($display_name) ?></div>
      <div class="qk-text-muted small"><?= e($utente['email']) ?></div>
      <span class="qk-badge confirmed mt-1"><?= e($utente['ruolo']) ?></span>
    </div>
  </div>

  <ul class="nav nav-tabs qk-tabs mb-4" id="profileTabs" role="tablist">
    <li class="nav-item" role="presentation">
      <button class="nav-link <?= $active_tab==='dati' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tab-dati" type="button">
        <i class="fa-solid fa-user fa-fw me-1"></i>Dati personali
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link <?= $active_tab==='sicurezza' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tab-sicurezza" type="button">
        <i class="fa-solid fa-lock fa-fw me-1"></i>Sicurezza
      </button>
    </li>
  </ul>

  <div class="tab-content">
    <div class="tab-pane fade <?= $active_tab==='dati' ? 'show active' : '' ?>" id="tab-dati">
      <form method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="dati">
        <div class="row g-3 mb-3">
          <div class="col-sm-6">
            <label class="qk-form-label">Nome <span class="text-danger">*</span></label>
            <input type="text" name="nome" class="qk-form-control <?= isset($errors['nome']) ? 'is-invalid' : '' ?>" value="<?= e($values['nome']) ?>">
            <?php if (isset($errors['nome'])): ?><div class="qk-field-error"><?= e($errors['nome']) ?></div><?php endif; ?>
          </div>
          <div class="col-sm-6">
            <label class="qk-form-label">Cognome <span class="text-danger">*</span></label>
            <input type="text" name="cognome" class="qk-form-control <?= isset($errors['cognome']) ? 'is-invalid' : '' ?>" value="<?= e($values['cognome']) ?>">
            <?php if (isset($errors['cognome'])): ?><div class="qk-field-error"><?= e($errors['cognome']) ?></div><?php endif; ?>
          </div>
        </div>
        <div class="mb-4">
          <label class="qk-form-label">Email <span class="text-danger">*</span></label>
          <input type="email" name="email" class="qk-form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>" value="<?= e($values['email']) ?>">
          <?php if (isset($errors['email'])): ?><div class="qk-field-error"><?= e($errors['email']) ?></div><?php endif; ?>
        </div>
        <div class="mb-3">
          <label class="qk-form-label">Ruolo</label>
          <input type="text" class="qk-form-control" value="<?= e($utente['ruolo']) ?>" disabled>
          <div class="qk-form-hint">Il ruolo viene assegnato dall'amministratore.</div>
        </div>
        <div class="qk-flex-row qk-justify-end pt-3 border-top">
          <button type="submit" class="btn-qk btn-qk-primary"><i class="fa-solid fa-floppy-disk me-1"></i>Salva modifiche</button>
        </div>
      </form>
    </div>
    <div class="tab-pane fade <?= $active_tab==='sicurezza' ? 'show active' : '' ?>" id="tab-sicurezza">
      <form method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="password">
        <div class="mb-3">
          <label class="qk-form-label">Password attuale <span class="text-danger">*</span></label>
          <input type="password" name="pwd_current" autocomplete="current-password" class="qk-form-control <?= isset($errors['pwd_current']) ? 'is-invalid' : '' ?>">
          <?php if (isset($errors['pwd_current'])): ?><div class="qk-field-error"><?= e($errors['pwd_current']) ?></div><?php endif; ?>
        </div>
        <div class="mb-3">
          <label class="qk-form-label">Nuova password <span class="text-danger">*</span></label>
          <input type="password" name="pwd_new" autocomplete="new-password" class="qk-form-control <?= isset($errors['pwd_new']) ? 'is-invalid' : '' ?>">
          <?php if (isset($errors['pwd_new'])): ?><div class="qk-field-error"><?= e($errors['pwd_new']) ?></div><?php endif; ?>
          <div class="qk-form-hint">Minimo 8 caratteri.</div>
        </div>
        <div class="mb-4">
          <label class="qk-form-label">Conferma nuova password <span class="text-danger">*</span></label>
          <input type="password" name="pwd_confirm" autocomplete="new-password" class="qk-form-control <?= isset($errors['pwd_confirm']) ? 'is-invalid' : '' ?>">
          <?php if (isset($errors['pwd_confirm'])): ?><div class="qk-field-error"><?= e($errors['pwd_confirm']) ?></div><?php endif; ?>
        </div>
        <div class="qk-flex-row qk-justify-end pt-3 border-top">
          <button type="submit" class="btn-qk btn-qk-primary"><i class="fa-solid fa-key me-1"></i>Cambia password</button>
        </div>
      </form>
    </div>
  </div>