<?php
$mode = $mode ?? 'create';
$entity_id = $entity_id ?? null;
$action_url = $mode === 'edit' ? url('users/edit?id=' . (int)$entity_id) : url('users/create');
?>

<?php if (!empty($errors['_global'])): ?>
<div class="qk-alert qk-alert-danger mb-4"><i class="fa-solid fa-circle-xmark"></i><span><?= e($errors['_global']) ?></span></div>
<?php endif; ?>

<div class="qk-card" style="max-width:640px">
  <form method="post" action="<?= e($action_url) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="row g-3 mb-3">
      <div class="col-sm-6">
        <label class="form-label">Nome <span class="text-danger">*</span></label>
        <input type="text" name="nome" class="form-control <?= isset($errors['nome']) ? 'is-invalid' : '' ?>" value="<?= e($values['nome'] ?? '') ?>">
      </div>
      <div class="col-sm-6">
        <label class="form-label">Cognome <span class="text-danger">*</span></label>
        <input type="text" name="cognome" class="form-control <?= isset($errors['cognome']) ? 'is-invalid' : '' ?>" value="<?= e($values['cognome'] ?? '') ?>">
      </div>
    </div>
    <div class="row g-3 mb-3">
      <div class="col-sm-6">
        <label class="form-label">Username <span class="text-danger">*</span></label>
        <input type="text" name="username" autocomplete="username"
               class="form-control <?= isset($errors['username']) ? 'is-invalid' : '' ?>"
               value="<?= e($values['username'] ?? '') ?>"
               placeholder="es. mario.rossi">
        <?php if (!empty($errors['username'])): ?>
          <div class="invalid-feedback"><?= e($errors['username']) ?></div>
        <?php endif; ?>
      </div>
      <div class="col-sm-6">
        <label class="form-label">Email <span class="text-danger">*</span></label>
        <input type="email" name="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>" value="<?= e($values['email'] ?? '') ?>">
      </div>
    </div>
    <div class="row g-3 mb-3">
      <div class="col-sm-6">
        <label class="form-label">Ruolo <span class="text-danger">*</span></label>
        <select name="ruolo" class="form-select <?= isset($errors['ruolo']) ? 'is-invalid' : '' ?>">
          <?php foreach (['admin','commerciale','magazziniere','readonly'] as $r): ?><option value="<?= $r ?>" <?= ($values['ruolo'] ?? '') === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-6">
        <label class="form-label">Stato</label>
        <select name="attivo" class="form-select">
          <option value="1" <?= ($values['attivo'] ?? '1') === '1' ? 'selected' : '' ?>>Attivo</option>
          <option value="0" <?= ($values['attivo'] ?? '1') === '0' ? 'selected' : '' ?>>Disabilitato</option>
        </select>
      </div>
    </div>
    <hr class="my-4">
    <div class="row g-3 mb-4">
      <div class="col-sm-6">
        <label class="form-label"><?= $mode === 'edit' ? 'Nuova password' : 'Password' ?><?= $mode === 'create' ? ' *' : '' ?></label>
        <input type="password" name="password" autocomplete="new-password" class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>">
      </div>
      <div class="col-sm-6">
        <label class="form-label">Conferma password<?= $mode === 'create' ? ' *' : '' ?></label>
        <input type="password" name="password_confirm" autocomplete="new-password" class="form-control <?= isset($errors['password_confirm']) ? 'is-invalid' : '' ?>">
      </div>
    </div>
    <div class="d-flex justify-content-between pt-3 border-top">
      <a href="<?= url('users') ?>" class="btn-qk btn-qk-secondary"><i class="fa-solid fa-arrow-left me-1"></i>Annulla</a>
      <button type="submit" class="btn-qk btn-qk-primary"><i class="fa-solid fa-floppy-disk me-1"></i><?= $mode === 'edit' ? 'Salva modifiche' : 'Crea utente' ?></button>
    </div>
  </form>
</div>
