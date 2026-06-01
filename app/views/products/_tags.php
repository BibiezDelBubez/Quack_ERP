<?php
/**
 * products/_tags.php — Gestione Tag Prodotti.
 * Variabili attese: $tags, $errors
 */
?>

<?php if (!empty($errors['_global'])): ?>
<div class="qk-alert qk-alert-danger mb-3">
  <i class="fa-solid fa-circle-xmark"></i>
  <span><?= e($errors['_global']) ?></span>
</div>
<?php endif; ?>

<div class="qk-card">
  <div class="qk-table-toolbar">
    <div class="qk-table-toolbar-left">
      <h2 class="mb-0">Elenco Tag</h2>
    </div>
    <div class="qk-table-toolbar-right">
      <button class="btn-qk btn-qk-primary btn-qk-sm"
              data-bs-toggle="modal" data-bs-target="#modal-tag"
              data-mode="create">
        <i class="fa-solid fa-plus"></i> Nuovo Tag
      </button>
    </div>
  </div>

  <div class="qk-table-wrap">
    <?php if (empty($tags)): ?>
    <div class="qk-empty">
      <i class="fa-regular fa-hashtag"></i>
      <p>Nessun tag creato.</p>
    </div>
    <?php else: ?>
    <table class="qk-table">
      <thead>
        <tr>
          <th style="width:80px">ID</th>
          <th>Nome Tag</th>
          <th style="width:180px" class="text-center">Prodotti Associati</th>
          <th class="col-actions"></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($tags as $t): ?>
        <tr>
          <td><span class="text-monospace small">#<?= e($t['id']) ?></span></td>
          <td>
            <span class="fw-semibold">
              <i class="fa-solid fa-hashtag small text-muted me-1"></i><?= e($t['nome']) ?>
            </span>
          </td>
          <td class="text-center">
            <span class="qk-badge <?= $t['prodotti_count'] > 0 ? 'confirmed' : 'draft' ?>">
              <?= e($t['prodotti_count']) ?> prodotti
            </span>
          </td>
          <td class="col-actions">
            <div class="dropdown">
              <button class="btn-qk btn-qk-secondary btn-qk-sm px-2"
                      data-bs-toggle="dropdown">
                <i class="fa-solid fa-ellipsis-vertical"></i>
              </button>
              <ul class="dropdown-menu dropdown-menu-end">
                <li>
                  <button type="button" class="dropdown-item"
                          data-bs-toggle="modal" data-bs-target="#modal-tag"
                          data-mode="edit"
                          data-id="<?= e($t['id']) ?>"
                          data-nome="<?= e($t['nome']) ?>">
                    <i class="fa-solid fa-pencil me-2"></i> Modifica
                  </button>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                  <form action="<?= url('products/tags/delete') ?>" method="post"
                        class="d-inline" onsubmit="return confirm('Sei sicuro di voler eliminare questo tag? Verrà rimosso da tutti i prodotti.');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= e($t['id']) ?>">
                    <button type="submit" class="dropdown-item text-danger">
                      <i class="fa-solid fa-trash me-2"></i> Elimina
                    </button>
                  </form>
                </li>
              </ul>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<!-- ── MODAL COMPILAZIONE TAG ── -->
<div class="modal fade" id="modal-tag" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-sm">
    <form method="post" class="modal-content">
      <?= csrf_field() ?>
      <input type="hidden" name="id" id="tag-id" value="">

      <div class="modal-header">
        <h5 class="modal-title" id="tag-modal-title">Gestione Tag</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
      </div>

      <div class="modal-body">
        <div class="qk-form-group">
          <label for="tag-nome" class="qk-form-label">Nome Tag <span class="required">*</span></label>
          <input type="text" name="nome" id="tag-nome" class="qk-form-control <?= isset($errors['nome']) ? 'is-invalid' : '' ?>" required maxlength="50" placeholder="Es. bio, novità...">
          <?php if (isset($errors['nome'])): ?>
            <div class="qk-form-error"><?= e($errors['nome']) ?></div>
          <?php endif; ?>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn-qk btn-qk-secondary" data-bs-dismiss="modal">Annulla</button>
        <button type="submit" class="btn-qk btn-qk-primary">Salva Tag</button>
      </div>
    </form>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const modalEl = document.getElementById('modal-tag');
  if (!modalEl) return;

  modalEl.addEventListener('show.bs.modal', (e) => {
    const trigger = e.relatedTarget;
    if (!trigger) return;

    const mode = trigger.getAttribute('data-mode');
    const titleEl = document.getElementById('tag-modal-title');
    const idInput = document.getElementById('tag-id');
    const nomeInput = document.getElementById('tag-nome');

    if (mode === 'create') {
      titleEl.innerText = 'Nuovo Tag';
      idInput.value = '';
      nomeInput.value = '';
    } else {
      titleEl.innerText = 'Modifica Tag';
      const id = trigger.getAttribute('data-id');
      const nome = trigger.getAttribute('data-nome');

      idInput.value = id;
      nomeInput.value = nome;
    }
  });
});
</script>
