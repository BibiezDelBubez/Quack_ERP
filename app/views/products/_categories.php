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
(function() {
  var CSRF_TOKEN = '<?= $_qk_csrf_cat ?>';
  var IMPORT_URL = '<?= $_qk_import_cat ?>';
  var EXPORT_URL = '<?= $_qk_export_cat ?>';

  var CAT_COLS = ['nome','parent_nome','posizione_categoria','immagine_path'];
  var CAT_HEADERS = ['nome *','parent_nome','posizione_categoria','immagine_path'];

  // ── Autocomplete per Categoria Genitore ─────────────────────────────────────
  function fetchParentCategories(term, callback) {
    var selfId = document.getElementById('cat-id').value;
    fetch('<?= url("products/api/categorie") ?>?q=' + encodeURIComponent(term))
      .then(function(res) { return res.json(); })
      .then(function(data) {
        var results = data.map(function(item) {
          return { id: item.id, label: item.nome };
        });
        if (selfId) {
          results = results.filter(function(item) {
            return String(item.id) !== String(selfId);
          });
        }
        callback(results);
      })
      .catch(function(err) {
        console.error('Errore caricamento categorie:', err);
        callback([]);
      });
  }

  document.addEventListener('DOMContentLoaded', function() {
    var pInput = document.getElementById('cat-parent-label');
    var pHidden = document.getElementById('cat-parent-id');
    var pDropdown = document.getElementById('cat-parent-dropdown');
    var pClear = document.getElementById('cat-parent-clear');

    if (pInput) {
      window.qkAutocomplete({
        input: pInput,
        dropdown: pDropdown,
        fetchFn: fetchParentCategories,
        onSelect: function(sel) {
          if (sel) {
            pHidden.value = sel.id;
            pInput.value = sel.label;
            pClear.classList.remove('d-none');
          } else {
            pHidden.value = '';
            pInput.value = '';
            pClear.classList.add('d-none');
          }
        }
      });

      pClear.addEventListener('click', function() {
        pHidden.value = '';
        pInput.value = '';
        pClear.classList.add('d-none');
        pDropdown.style.display = 'none';
      });
    }
  });

  // ── Modale edit: popola immagine_path e preview ───────────────────────────────
  var modalCatEl = document.getElementById('modal-categoria');
  if (modalCatEl) {
    modalCatEl.addEventListener('show.bs.modal', function(e) {
      var trigger = e.relatedTarget;
      if (!trigger) return;
      var mode = trigger.getAttribute('data-mode');
      var titleEl = document.getElementById('cat-modal-title');
      var idInput = document.getElementById('cat-id');
      var nomeInput = document.getElementById('cat-nome');
      var parentIdInput = document.getElementById('cat-parent-id');
      var parentLabelInput = document.getElementById('cat-parent-label');
      var parentClearBtn = document.getElementById('cat-parent-clear');
      var posizioneInput = document.getElementById('cat-posizione');
      var immagineInput = document.getElementById('cat-immagine');
      
      var fileInput = document.getElementById('cat-immagine-file');
      var previewContainer = document.getElementById('cat-preview-container');
      var previewImg = document.getElementById('cat-preview');
      var statusText = document.getElementById('cat-status-text');

      if (fileInput) fileInput.value = '';

      if (mode === 'create') {
        titleEl.innerText = 'Nuova Categoria';
        idInput.value = '';
        nomeInput.value = '';
        parentIdInput.value = '';
        parentLabelInput.value = '';
        if (parentClearBtn) parentClearBtn.classList.add('d-none');
        posizioneInput.value = '0';
        immagineInput.value = '';
        
        previewImg.src = '#';
        statusText.innerText = '';
        previewContainer.style.setProperty('display', 'none', 'important');
      } else {
        titleEl.innerText = 'Modifica Categoria';
        idInput.value = trigger.getAttribute('data-id');
        nomeInput.value = trigger.getAttribute('data-nome');
        
        var pId = trigger.getAttribute('data-parent-id') || '';
        var pNome = trigger.getAttribute('data-parent-nome') || '';
        parentIdInput.value = pId;
        parentLabelInput.value = pNome;
        if (pId) {
          if (parentClearBtn) parentClearBtn.classList.remove('d-none');
        } else {
          if (parentClearBtn) parentClearBtn.classList.add('d-none');
        }
        
        posizioneInput.value = trigger.getAttribute('data-posizione') || '0';
        
        var immaginePath = trigger.getAttribute('data-immagine') || '';
        immagineInput.value = immaginePath;
        
        if (immaginePath) {
          var imgSrc = immaginePath;
          if (immaginePath.indexOf('http://') !== 0 && immaginePath.indexOf('https://') !== 0) {
            if (immaginePath.indexOf('assets/') === 0) {
              imgSrc = '<?= BASE_URL ?>/' + immaginePath;
            } else {
              imgSrc = '<?= url("uploads?path=") ?>' + encodeURIComponent(immaginePath);
            }
          }
          previewImg.src = imgSrc;
          statusText.innerHTML = '<span class="text-success"><i class="fa-solid fa-circle-check"></i> Immagine presente</span>';
          previewContainer.style.setProperty('display', 'flex', 'important');
        } else {
          previewImg.src = '#';
          statusText.innerText = '';
          previewContainer.style.setProperty('display', 'none', 'important');
        }
      }
    });

    // File input preview setup
    var catFileInput = document.getElementById('cat-immagine-file');
    if (catFileInput) {
      catFileInput.addEventListener('change', function() {
        var file = this.files[0];
        if (file) {
          var reader = new FileReader();
          reader.onload = function(e) {
            document.getElementById('cat-preview').src = e.target.result;
            document.getElementById('cat-status-text').innerHTML = '<span class="text-success font-monospace" style="font-weight:600;"><i class="fa-solid fa-circle-check"></i> Anteprima (' + (file.size / 1024).toFixed(1) + ' KB)</span>';
            document.getElementById('cat-preview-container').style.setProperty('display', 'flex', 'important');
          };
          reader.readAsDataURL(file);
        }
      });
    }

    // Remove button setup
    var removeBtn = document.getElementById('btn-remove-cat-img');
    if (removeBtn) {
      removeBtn.addEventListener('click', function() {
        document.getElementById('cat-immagine').value = '';
        document.getElementById('cat-preview').src = '#';
        document.getElementById('cat-preview-container').style.setProperty('display', 'none', 'important');
        var fileInput = document.getElementById('cat-immagine-file');
        if (fileInput) fileInput.value = '';
      });
    }
  }

  // ── Esporta ─────────────────────────────────────────────────────────
  var btnEsporta = document.getElementById('btnEsportaCategorie');
  if (btnEsporta) {
    btnEsporta.addEventListener('click', function() {
      fetch(EXPORT_URL)
        .then(function(r){ return r.json(); })
        .then(function(json) {
          if (!json.ok || !json.rows) { alert('Errore durante l\'export.'); return; }
          if (typeof ExcelJS === 'undefined') { alert('ExcelJS non caricato.'); return; }
          var wb = new ExcelJS.Workbook();
          var ws = wb.addWorksheet('Categorie');
          ws.columns = [
            {header:'id', key:'id', width:8},
            {header:'nome', key:'nome', width:30},
            {header:'parent_nome', key:'parent_nome', width:30},
            {header:'posizione_categoria', key:'posizione_categoria', width:20},
            {header:'immagine_path', key:'immagine_path', width:40}
          ];
          ws.getRow(1).font = {bold:true};
          json.rows.forEach(function(r){
            ws.addRow({
              id: r.id,
              nome: r.nome,
              parent_nome: r.parent_nome || '',
              posizione_categoria: r.posizione_categoria,
              immagine_path: r.immagine_path || ''
            });
          });
          wb.xlsx.writeBuffer().then(function(buf){
            var blob = new Blob([buf], {type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'});
            var a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'categorie_' + new Date().toISOString().slice(0,10) + '.xlsx';
            a.click();
          });
        })
        .catch(function(){ alert('Errore di rete durante l\'export.'); });
    });
  }

  // ── Tracciato template ───────────────────────────────────────────────
  var btnTrac = document.getElementById('btnTracCat');
  if (btnTrac) {
    btnTrac.addEventListener('click', function() {
      if (typeof ExcelJS === 'undefined') { alert('ExcelJS non caricato.'); return; }
      var wb = new ExcelJS.Workbook();
      var ws = wb.addWorksheet('Categorie');
      ws.columns = [
        {header:'nome *', key:'nome', width:30},
        {header:'parent_nome', key:'parent_nome', width:30},
        {header:'posizione_categoria', key:'posizione_categoria', width:20},
        {header:'immagine_path', key:'immagine_path', width:40}
      ];
      ws.getRow(1).font = {bold:true};
      ws.getRow(1).fill = {type:'pattern', pattern:'solid', fgColor:{argb:'FFD6E4F7'}};
      // Riga di esempio
      ws.addRow(['Solventi','Chimici','10','assets/img/solventi.svg']);

      var wsGuida = wb.addWorksheet('Guida');
      wsGuida.getCell('A1').value = 'GUIDA ALL\'IMPORTAZIONE CATEGORIE';
      wsGuida.getCell('A1').font = {bold:true, size:13};
      var guida = [
        ['Campo','Obbligatorio','Descrizione'],
        ['nome','SI','Nome della categoria. Usato come chiave per upsert (insert o update).'],
        ['parent_nome','NO','Nome della categoria genitore. Deve essere presente nel file o già in DB.'],
        ['posizione_categoria','NO','Numero intero per ordinamento. Default: 0.'],
        ['immagine_path','NO','Percorso relativo o URL dell\'immagine associata alla categoria.']
      ];
      guida.forEach(function(row, i){
        var r = wsGuida.addRow(row);
        if (i === 0) r.font = {bold:true};
      });
      wsGuida.columns = [{width:25},{width:15},{width:70}];

      wb.xlsx.writeBuffer().then(function(buf){
        var blob = new Blob([buf], {type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'});
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'tracciato_categorie.xlsx';
        a.click();
      });
    });
  }

  // ── Upload + Preview ─────────────────────────────────────────────────
  var fileCat = document.getElementById('fileCat');
  var parsedCatRows = [];

  if (fileCat) {
    fileCat.addEventListener('change', function() {
      var file = fileCat.files[0];
      if (!file) return;
      document.getElementById('importCatResult').classList.add('d-none');
      document.getElementById('previewCat').classList.add('d-none');
      document.getElementById('btnImportCat').classList.add('d-none');
      parsedCatRows = [];

      if (typeof ExcelJS === 'undefined') { alert('ExcelJS non caricato.'); return; }
      var reader = new FileReader();
      reader.onload = function(ev) {
        var wb = new ExcelJS.Workbook();
        wb.xlsx.load(ev.target.result).then(function() {
          var ws = wb.worksheets[0];
          var headers = [];
          var dataRows = [];
          ws.eachRow(function(row, rowNum) {
            var vals = row.values.slice(1).map(function(v){
              if (v === null || v === undefined) return '';
              if (typeof v === 'object' && v.text) return v.text;
              return String(v);
            });
            if (rowNum === 1) { headers = vals; }
            else if (vals.some(function(v){ return v.trim() !== ''; })) { dataRows.push(vals); }
          });

          // Mappa header → indice colonna
          var idxMap = {};
          CAT_COLS.forEach(function(col) {
            idxMap[col] = headers.findIndex(function(h){
              return h.replace(' *','').trim().toLowerCase() === col.toLowerCase();
            });
          });

          parsedCatRows = dataRows.map(function(row) {
            var obj = {};
            CAT_COLS.forEach(function(col) {
              obj[col] = idxMap[col] >= 0 ? (row[idxMap[col]] || '').trim() : '';
            });
            return obj;
          }).filter(function(r){ return r.nome !== ''; });

          // Preview
          var headTr = document.getElementById('previewCatHead');
          headTr.innerHTML = '';
          CAT_HEADERS.forEach(function(h) {
            var th = document.createElement('th');
            th.textContent = h;
            headTr.appendChild(th);
          });
          var tbody = document.getElementById('previewCatBody');
          tbody.innerHTML = '';
          parsedCatRows.slice(0, 10).forEach(function(r) {
            var tr = document.createElement('tr');
            CAT_COLS.forEach(function(col) {
              var td = document.createElement('td');
              td.textContent = r[col];
              tr.appendChild(td);
            });
            tbody.appendChild(tr);
          });
          document.getElementById('previewCatCount').textContent =
            'Righe trovate: ' + parsedCatRows.length + (parsedCatRows.length > 10 ? ' (mostrate le prime 10)' : '');
          document.getElementById('previewCat').classList.remove('d-none');
          document.getElementById('btnImportCat').classList.remove('d-none');
        }).catch(function(err){
          alert('Errore nella lettura del file: ' + err.message);
        });
      };
      reader.readAsArrayBuffer(file);
    });
  }

  // ── Importa ──────────────────────────────────────────────────────────
  var btnImport = document.getElementById('btnImportCat');
  if (btnImport) {
    btnImport.addEventListener('click', function() {
      if (!parsedCatRows.length) { alert('Nessuna riga da importare.'); return; }
      btnImport.disabled = true;
      btnImport.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Importazione...';

      fetch(IMPORT_URL, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': CSRF_TOKEN
        },
        body: JSON.stringify({rows: parsedCatRows, csrf_token: CSRF_TOKEN})
      })
      .then(function(r){ return r.json(); })
      .then(function(res) {
        btnImport.disabled = false;
        btnImport.innerHTML = '<i class="fa-solid fa-upload me-1"></i> Importa';
        var div = document.getElementById('importCatResult');
        div.classList.remove('d-none');
        if (res.ok) {
          var msg = '<div class="qk-alert qk-alert-success p-3"><i class="fa-solid fa-circle-check"></i><div>' +
            '<strong>Importazione completata.</strong><br>' +
            'Inserite: <strong>' + res.inserted + '</strong> &nbsp; ' +
            'Aggiornate: <strong>' + res.updated + '</strong> &nbsp; ' +
            'Saltate: <strong>' + res.skipped + '</strong>';
          if (res.errors && res.errors.length) {
            msg += '<br><span class="text-danger">Errori: ' + res.errors.join('; ') + '</span>';
          }
          msg += '</div></div>';
          div.innerHTML = msg;
          setTimeout(function(){ location.reload(); }, 2000);
        } else {
          div.innerHTML = '<div class="qk-alert qk-alert-danger p-3"><i class="fa-solid fa-circle-xmark"></i><span>' +
            (res.error || 'Errore durante l\'importazione.') + '</span></div>';
        }
      })
      .catch(function(err) {
        btnImport.disabled = false;
        btnImport.innerHTML = '<i class="fa-solid fa-upload me-1"></i> Importa';
        alert('Errore di rete: ' + err.message);
      });
    });
  }

  // Reset modale all'apertura
  var modalImpEl = document.getElementById('modalImportaCategorie');
  if (modalImpEl) {
    modalImpEl.addEventListener('show.bs.modal', function() {
      if (fileCat) fileCat.value = '';
      parsedCatRows = [];
      document.getElementById('previewCat').classList.add('d-none');
      document.getElementById('importCatResult').classList.add('d-none');
      document.getElementById('btnImportCat').classList.add('d-none');
    });
  }

})();
</script>
