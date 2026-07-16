<?php
/**
 * products/_categories.php — Gestione Categorie Prodotti.
 * Variabili attese: $categories, $dropdown_categories, $errors
 */
?>
<script src="<?= asset('js/exceljs.min.js') ?>"></script>
<?php
$_qk_csrf_cat    = csrf_token();
$_qk_import_cat  = url('products/categories/import');
$_qk_export_cat  = url('products/categories/export');
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
      <button class="btn-qk btn-qk-secondary btn-qk-sm" id="btnEsportaCategorie">
        <i class="fa-solid fa-file-arrow-down"></i> Esporta
      </button>
      <button class="btn-qk btn-qk-secondary btn-qk-sm"
              data-bs-toggle="modal" data-bs-target="#modalImportaCategorie">
        <i class="fa-solid fa-file-arrow-up"></i> Importa
      </button>
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
          <th>Immagine</th>
          <th class="col-actions"></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($categories as $cat): ?>
        <tr>
          <td><span class="text-monospace small">#<?= e($cat['id']) ?></span></td>
          <td class="fw-semibold">
            <a href="#" class="qk-link-strong text-decoration-none"
               data-bs-toggle="modal" data-bs-target="#modal-categoria"
               data-mode="edit"
               data-id="<?= e($cat['id']) ?>"
               data-nome="<?= e($cat['nome']) ?>"
               data-parent-id="<?= e($cat['parent_id'] ?? '') ?>"
               data-parent-nome="<?= e($cat['parent_nome'] ?? '') ?>"
               data-posizione="<?= e($cat['posizione_categoria']) ?>"
               data-immagine="<?= e($cat['immagine_path'] ?? '') ?>">
              <?= e($cat['nome']) ?>
            </a>
          </td>
          <td>
            <?php if (!empty($cat['parent_nome'])): ?>
              <span class="qk-badge draft"><?= e($cat['parent_nome']) ?></span>
            <?php else: ?>
              <span class="qk-text-muted">—</span>
            <?php endif; ?>
          </td>
          <td class="text-center"><?= e($cat['posizione_categoria']) ?></td>
          <td>
            <?php if (!empty($cat['immagine_path'])): ?>
              <span class="text-monospace small" style="font-size:.75rem"><?= e($cat['immagine_path']) ?></span>
            <?php else: ?>
              <span class="qk-text-muted">—</span>
            <?php endif; ?>
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
                          data-bs-toggle="modal" data-bs-target="#modal-categoria"
                          data-mode="edit"
                          data-id="<?= e($cat['id']) ?>"
                          data-nome="<?= e($cat['nome']) ?>"
                          data-parent-id="<?= e($cat['parent_id'] ?? '') ?>"
                          data-parent-nome="<?= e($cat['parent_nome'] ?? '') ?>"
                          data-posizione="<?= e($cat['posizione_categoria']) ?>"
                          data-immagine="<?= e($cat['immagine_path'] ?? '') ?>">
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
    <form method="post" enctype="multipart/form-data" class="modal-content">
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
          <label class="qk-form-label">Categoria Genitore</label>
          <input type="hidden" name="parent_id" id="cat-parent-id" value="">
          <div class="qk-m2o-wrap" style="position:relative;">
            <input type="text"
                   class="qk-form-control qk-m2o-input"
                   id="cat-parent-label"
                   placeholder="Cerca categoria genitore…"
                   autocomplete="off">
            <button type="button" class="qk-m2o-clear d-none" id="cat-parent-clear">&times;</button>
            <div class="qk-m2o-dropdown shadow-sm" id="cat-parent-dropdown" style="display:none; position:absolute; z-index:1050; background:var(--qk-card-bg); border:1px solid var(--qk-border); width:100%; max-height:200px; overflow-y:auto;"></div>
          </div>
          <?php if (isset($errors['parent_id'])): ?>
            <div class="qk-form-error"><?= e($errors['parent_id']) ?></div>
          <?php endif; ?>
        </div>

        <div class="qk-form-group">
          <label for="cat-posizione" class="qk-form-label">Ordine di visualizzazione</label>
          <input type="number" name="posizione_categoria" id="cat-posizione" class="qk-form-control" value="0" min="0">
        </div>

        <div class="qk-form-group">
          <label class="qk-form-label">Immagine Categoria</label>
          <div id="cat-preview-container" class="mb-2 qk-flex-row qk-gap-sm" style="display:none !important; align-items: center;">
            <img id="cat-preview" src="#" alt="Anteprima categoria" style="max-height:60px;border:1px solid var(--qk-card-border);padding:2px;border-radius:4px">
            <span class="small text-muted font-monospace" id="cat-status-text"></span>
            <button type="button" class="btn-qk btn-qk-secondary btn-qk-xs ms-auto" id="btn-remove-cat-img" style="padding: 2px 6px; font-size: 0.75rem;">
              <i class="fa-solid fa-trash-can"></i> Rimuovi
            </button>
          </div>
          <input type="file" name="immagine_file" id="cat-immagine-file" class="qk-form-control" accept="image/*">
          <input type="hidden" name="immagine_path" id="cat-immagine" value="">
          <?php if (isset($errors['immagine_file'])): ?>
            <div class="qk-form-error"><?= e($errors['immagine_file']) ?></div>
          <?php endif; ?>
          <span class="qk-form-hint">Seleziona un'immagine dal computer. Formati supportati: JPEG, PNG, GIF, SVG, WEBP.</span>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn-qk btn-qk-secondary" data-bs-dismiss="modal">Annulla</button>
        <button type="submit" class="btn-qk btn-qk-primary">Salva Categoria</button>
      </div>
    </form>
  </div>
</div>

<!-- ── MODAL IMPORTA CATEGORIE ── -->
<div class="modal fade" id="modalImportaCategorie" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-solid fa-file-arrow-up me-2"></i>Importa Categorie</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
      </div>
      <div class="modal-body">

        <!-- Istruzioni -->
        <div class="qk-alert qk-alert-info mb-3 p-3">
          <i class="fa-solid fa-circle-info"></i>
          <div style="font-size:0.85rem;line-height:1.5">
            <strong>Come funziona l'importazione:</strong><br>
            Scarica il tracciato Excel, compila le righe e carica il file.<br>
            Le categorie vengono <strong>inserite o aggiornate</strong> in base al nome (case-insensitive).<br>
            <ul class="mb-0 mt-1" style="padding-left:1.2rem">
              <li><strong>nome *</strong> — obbligatorio, identificatore univoco</li>
              <li><strong>parent_nome</strong> — nome della categoria genitore (deve esistere già o essere nella stessa importazione prima)</li>
              <li><strong>posizione_categoria</strong> — numero intero, ordine di visualizzazione (default 0)</li>
              <li><strong>immagine_path</strong> — percorso immagine/SVG</li>
            </ul>
          </div>
        </div>

        <!-- Bottone tracciato -->
        <div class="mb-3">
          <button type="button" class="btn-qk btn-qk-secondary btn-qk-sm" id="btnTracCat">
            <i class="fa-solid fa-download me-1"></i> Scarica Tracciato Excel
          </button>
        </div>

        <!-- Upload -->
        <div class="mb-3">
          <label class="qk-form-label" for="fileCat">Carica file Excel (.xlsx)</label>
          <input type="file" id="fileCat" accept=".xlsx" class="qk-form-control">
        </div>

        <!-- Preview -->
        <div id="previewCat" class="d-none">
          <div class="qk-form-label mb-1">Anteprima prime 10 righe</div>
          <div class="qk-table-wrap" style="max-height:260px;overflow-y:auto">
            <table class="qk-table" id="previewCatTable">
              <thead><tr id="previewCatHead"></tr></thead>
              <tbody id="previewCatBody"></tbody>
            </table>
          </div>
          <div class="mt-2 text-muted small" id="previewCatCount"></div>
        </div>

        <!-- Risultato -->
        <div id="importCatResult" class="d-none mt-3"></div>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn-qk btn-qk-secondary" data-bs-dismiss="modal">Chiudi</button>
        <button type="button" class="btn-qk btn-qk-primary d-none" id="btnImportCat">
          <i class="fa-solid fa-upload me-1"></i> Importa
        </button>
      </div>
    </div>
  </div>
</div>

<script>
window.QuackCategoriesConfig = {
    csrfToken: '<?= e($_qk_csrf_cat) ?>',
    importUrl: '<?= e($_qk_import_cat) ?>',
    exportUrl: '<?= e($_qk_export_cat) ?>',
    apiUrl: '<?= url("products/api/categorie") ?>',
    baseUrl: '<?= BASE_URL ?>',
    uploadsUrl: '<?= url("uploads?path=") ?>'
};
</script>
<script defer src="<?= asset('js/products-categories.js') ?>"></script>
