<?php
/**
 * products/_categories.php — Gestione Categorie Prodotti.
 * Variabili attese: $categories, $dropdown_categories, $errors
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
      <h2 class="mb-0">Elenco Categorie</h2>
    </div>
    <div class="qk-table-toolbar-right">
      <button class="btn-qk btn-qk-primary btn-qk-sm"
              data-bs-toggle="modal" data-bs-target="#modal-categoria"
              data-mode="create">
        <i class="fa-solid fa-plus"></i> Nuova Categoria
      </button>
    </div>
  </div>

  <div class="qk-table-wrap">
    <?php if (empty($categories)): ?>
    <div class="qk-empty">
      <i class="fa-regular fa-folder-open"></i>
      <p>Nessuna categoria creata.</p>
    </div>
    <?php else: ?>
    <table class="qk-table">
      <thead>
        <tr>
          <th style="width:80px">ID</th>
          <th>Nome Categoria</th>
          <th>Categoria Genitore</th>
          <th style="width:100px" class="text-center">Ordine</th>
          <th class="col-actions"></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($categories as $cat): ?>
        <tr>
          <td><span class="text-monospace small">#<?= e($cat['id']) ?></span></td>
          <td class="fw-semibold"><?= e($cat['nome']) ?></td>
          <td>
            <?php if (!empty($cat['parent_nome'])): ?>
              <span class="qk-badge draft"><?= e($cat['parent_nome']) ?></span>
            <?php else: ?>
              <span class="qk-text-muted">—</span>
            <?php endif; ?>
          </td>
          <td class="text-center"><?= e($cat['posizione_categoria']) ?></td>
          <td class="col-actions">
            <div class="dropdown">
              <button class="btn-qk btn-qk-secondary btn-qk-sm px-2"
                      data-bs-toggle="dropdown">
                <i class="fa-solid fa-ellipsis-vertical"></i>
              </button>
              <ul class="dropdown-menu dropdown-menu-end">
                <li>
                  <button type="button" class="dropdown-item"
                          data-bs-toggle="modal" data-bs-target="#modal-categoria"
                          data-mode="edit"
                          data-id="<?= e($cat['id']) ?>"
                          data-nome="<?= e($cat['nome']) ?>"
                          data-parent-id="<?= e($cat['parent_id'] ?? '') ?>"
                          data-posizione="<?= e($cat['posizione_categoria']) ?>">
                    <i class="fa-solid fa-pencil me-2"></i> Modifica
                  </button>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                  <form action="<?= url('products/categories/delete') ?>" method="post"
                        class="d-inline" onsubmit="return confirm('Sei sicuro di voler eliminare questa categoria?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= e($cat['id']) ?>">
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

<!-- ── MODAL COMPILAZIONE CATEGORIA ── -->
<div class="modal fade" id="modal-categoria" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <?= csrf_field() ?>
      <input type="hidden" name="id" id="cat-id" value="">

      <div class="modal-header">
        <h5 class="modal-title" id="cat-modal-title">Gestione Categoria</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
      </div>

      <div class="modal-body">
        <div class="qk-alert qk-alert-info mb-3 p-3">
          <i class="fa-solid fa-circle-info"></i>
          <div style="font-size:0.85rem; line-height:1.4">
            Le categorie consentono di strutturare il catalogo in modo <strong>gerarchico</strong> (ad albero).
            <hr class="my-2" style="opacity:0.15">
            <em>Esempio: Se vendessi strumenti musicali, la categoria <strong>Chitarre</strong> farebbe da genitore alle sotto-categorie <strong>Chitarre Acustiche</strong> o <strong>Chitarre Elettriche</strong>.</em>
          </div>
        </div>

        <div class="qk-form-group">
          <label for="cat-nome" class="qk-form-label">Nome Categoria <span class="required">*</span></label>
          <input type="text" name="nome" id="cat-nome" class="qk-form-control <?= isset($errors['nome']) ? 'is-invalid' : '' ?>" required maxlength="100">
          <?php if (isset($errors['nome'])): ?>
            <div class="qk-form-error"><?= e($errors['nome']) ?></div>
          <?php endif; ?>
        </div>

        <div class="qk-form-group">
          <label for="cat-parent" class="qk-form-label">Categoria Genitore</label>
          <select name="parent_id" id="cat-parent" class="qk-form-control <?= isset($errors['parent_id']) ? 'is-invalid' : '' ?>">
            <option value="">(Nessuna genitore - Root)</option>
            <?php foreach ($dropdown_categories as $dcat): ?>
              <option value="<?= e($dcat['id']) ?>"><?= e($dcat['label']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (isset($errors['parent_id'])): ?>
            <div class="qk-form-error"><?= e($errors['parent_id']) ?></div>
          <?php endif; ?>
        </div>

        <div class="qk-form-group">
          <label for="cat-posizione" class="qk-form-label">Ordine di visualizzazione</label>
          <input type="number" name="posizione_categoria" id="cat-posizione" class="qk-form-control" value="0" min="0">
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn-qk btn-qk-secondary" data-bs-dismiss="modal">Annulla</button>
        <button type="submit" class="btn-qk btn-qk-primary">Salva Categoria</button>
      </div>
    </form>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const modalEl = document.getElementById('modal-categoria');
  if (!modalEl) return;

  modalEl.addEventListener('show.bs.modal', (e) => {
    const trigger = e.relatedTarget;
    if (!trigger) return;

    const mode = trigger.getAttribute('data-mode');
    const titleEl = document.getElementById('cat-modal-title');
    const idInput = document.getElementById('cat-id');
    const nomeInput = document.getElementById('cat-nome');
    const parentSelect = document.getElementById('cat-parent');
    const posizioneInput = document.getElementById('cat-posizione');

    if (mode === 'create') {
      titleEl.innerText = 'Nuova Categoria';
      idInput.value = '';
      nomeInput.value = '';
      parentSelect.value = '';
      posizioneInput.value = '0';

      // Restore all options to visible
      Array.from(parentSelect.options).forEach(opt => opt.style.display = '');
    } else {
      titleEl.innerText = 'Modifica Categoria';
      const id = trigger.getAttribute('data-id');
      const nome = trigger.getAttribute('data-nome');
      const parentId = trigger.getAttribute('data-parent-id');
      const posizione = trigger.getAttribute('data-posizione');

      idInput.value = id;
      nomeInput.value = nome;
      parentSelect.value = parentId || '';
      posizioneInput.value = posizione || '0';

      // Cannot select self as parent
      Array.from(parentSelect.options).forEach(opt => {
        if (opt.value === id) {
          opt.style.display = 'none';
        } else {
          opt.style.display = '';
        }
      });
    }
  });
});
</script>
