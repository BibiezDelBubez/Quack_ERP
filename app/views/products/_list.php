<?php
/**
 * products/_list.php
 */
?>
<div class="qk-card">
  <div class="qk-table-toolbar">
    <div class="qk-table-toolbar-left">
      <form method="get" class="qk-flex-row qk-gap-sm flex-wrap align-items-center">
        <div class="qk-filter-wrap">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input type="text" name="q" class="qk-filter-input" placeholder="Cerca nome, SKU..." value="<?= e($search) ?>">
        </div>
        <?php if (!empty($categories)): ?>
        <select name="cat" class="qk-filter-input" style="width:auto">
          <option value="">Tutte le categorie</option>
          <?php foreach ($categories as $cat): ?><option value="<?= e($cat['id']) ?>" <?= (string)$category === (string)$cat['id'] ? 'selected' : '' ?>><?= e($cat['label']) ?></option><?php endforeach; ?>
        </select>
        <?php endif; ?>
        <?php if (!empty($suppliers)): ?>
        <select name="fornitore" class="qk-filter-input" style="width:auto">
          <option value="">Tutti i fornitori</option>
          <?php foreach ($suppliers as $sup): ?>
            <option value="<?= e($sup['id']) ?>" <?= (string)$supplier === (string)$sup['id'] ? 'selected' : '' ?>><?= e($sup['ragione_sociale']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <label class="qk-flex-row qk-gap-xs fw-medium" style="font-size:.85rem;cursor:pointer">
          <input type="checkbox" name="adr" value="1" <?= $adr_only ? 'checked' : '' ?>> Solo ADR
        </label>
        <button type="submit" class="btn-qk btn-qk-secondary btn-qk-sm"><i class="fa-solid fa-filter"></i> Filtra</button>
        <?php if ($search || $category || $adr_only): ?><a href="<?= url('products') ?>" class="btn-qk btn-qk-secondary btn-qk-sm"><i class="fa-solid fa-xmark"></i> Pulisci</a><?php endif; ?>
      </form>
    </div>
    <div class="qk-table-toolbar-right">
      <small class="qk-text-muted me-3"><?= $total ?> prodotti</small>
      <button type="button" class="btn-qk btn-qk-secondary btn-qk-sm" data-bs-toggle="modal" data-bs-target="#modalImportaProdotti">
        <i class="fa-solid fa-file-import me-1"></i>Importa
      </button>
    </div>
  </div>

  <div class="qk-table-wrap">
    <?php if (empty($products)): ?>
    <div class="qk-empty">
      <i class="fa-regular fa-flask"></i>
      <p><?= ($search || $category || $adr_only) ? 'Nessun prodotto trovato con i filtri selezionati.' : 'Nessun prodotto ancora.' ?></p>
      <?php if (!$search && !$category && !$adr_only): ?><a href="<?= url('products/create') ?>" class="btn-qk btn-qk-primary"><i class="fa-solid fa-plus"></i> Aggiungi prodotto</a><?php endif; ?>
    </div>
    <?php else: ?>
    <table class="qk-table">
      <?php $base = url('products'); $sort = $pag['sort']; $dir = $pag['dir']; ?>
      <thead><tr>
        <th class="text-center" style="width: 45px;"><input type="checkbox" id="select-all-products" style="transform: scale(1.15); cursor: pointer;"></th>
        <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'sku', $sort, $dir)) ?>">SKU<?= qk_sort_icon('sku', $sort, $dir) ?></a></th>
        <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'nome', $sort, $dir)) ?>">Nome prodotto<?= qk_sort_icon('nome', $sort, $dir) ?></a></th>
        <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'categoria', $sort, $dir)) ?>">Categoria<?= qk_sort_icon('categoria', $sort, $dir) ?></a></th>
        <th class="qk-sortable text-center"><a href="<?= e(qk_sort_url($base, 'giacenza', $sort, $dir)) ?>">Giacenza<?= qk_sort_icon('giacenza', $sort, $dir) ?></a></th>
        <th class="qk-sortable text-end"><a href="<?= e(qk_sort_url($base, 'prezzo', $sort, $dir)) ?>">Prezzo<?= qk_sort_icon('prezzo', $sort, $dir) ?></a></th>
        <th>ADR</th>
        <th class="col-actions"></th>
      </tr></thead>
      <tbody>
      <?php foreach ($products as $p): ?>
        <tr data-id="<?= (int)$p['id'] ?>">
          <td class="text-center" style="width: 45px;"><input type="checkbox" class="product-select-chk" data-id="<?= (int)$p['id'] ?>" style="transform: scale(1.15); cursor: pointer;"></td>
          <td><code class="qk-text-secondary" style="font-size:.8rem"><?= e($p['sku_interno'] ?? '') ?></code></td>
          <td class="fw-semibold">
            <a href="<?= url('products/edit?id=' . $p['id']) ?>" class="qk-text-accent text-decoration-none">
              <?= e($p['nome'] ?? '') ?>
            </a>
          </td>
          <td><?php if ($p['categoria']): ?><span class="qk-badge draft"><?= e($p['categoria']) ?></span><?php else: ?><span class="qk-text-muted">--</span><?php endif; ?></td>
          <td class="text-center"><?= $p['quantita_disponibile'] !== null ? (int)$p['quantita_disponibile'] : '<span class="qk-text-muted">--</span>' ?></td>
          <td class="text-end"><?php if (!empty($p['prezzo_min'])) { echo e(number_format($p['prezzo_min'], 2, ',', '.')); } else { echo '--'; } ?></td>
          <td><?= !empty($p['is_adr']) ? '<span class="qk-badge warning">ADR</span>' : '<span class="qk-text-muted">--</span>' ?></td>
          <td class="col-actions">
            <div class="d-flex gap-1 justify-content-end">
              <a href="<?= url('products/edit?id=' . $p['id']) ?>" class="btn-qk btn-qk-secondary btn-qk-sm" title="Modifica"><i class="fa-solid fa-pencil"></i></a>
              <form action="<?= url('products/duplicate') ?>" method="post" class="d-inline" onsubmit="return confirm('Duplicare questo prodotto con tutti i suoi listini e fornitori?');">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <button type="submit" class="btn-qk btn-qk-secondary btn-qk-sm" title="Duplica"><i class="fa-solid fa-copy"></i></button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <?php if ($pages > 1): ?>
  <div class="qk-table-footer mt-2">
    <span class="qkwh-count-label"><?= $total ?> prodotti</span>
    <div class="qk-pagination">
      <?php for ($pg = 1; $pg <= $pages; $pg++): ?>
      <?php
        $paginationParams = array_filter([
          'q'        => $search,
          'cat'      => $category,
          'adr'      => $adr_only ? '1' : '',
          'fornitore' => $supplier ?: '',
          'page'     => $pg > 1 ? $pg : '',
        ]);
        $paginationQs = http_build_query($paginationParams);
      ?>
      <a href="<?= url('products') . ($paginationQs ? '?' . $paginationQs : '') ?>"
         class="qk-page-btn <?= $pg === $page ? 'active' : '' ?>"><?= $pg ?></a>
      <?php endfor; ?>
    </div>
  </div>
  <?php endif; ?>
</div><!-- /qk-card -->

<!-- ======================================================================
     MODALE — Importazione massiva prodotti
     ====================================================================== -->
<div class="modal fade" id="modalImportaProdotti" tabindex="-1" aria-labelledby="modalImportaProdottiLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-semibold" id="modalImportaProdottiLabel">
          <i class="fa-solid fa-file-import me-2"></i>Importazione Massiva Prodotti
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">

        <div class="mb-3 p-3" style="background:var(--qk-bg-subtle);border:1px solid var(--qk-border);border-radius:6px">
          <p class="mb-2 fw-semibold"><i class="fa-solid fa-circle-info me-1 qk-text-accent"></i>Come funziona</p>
          <ol class="mb-0" style="font-size:.875rem;padding-left:1.2rem">
            <li>Scarica il tracciato Excel con il pulsante qui sotto</li>
            <li>Compila il file rispettando le colonne (non modificare la riga 1)</li>
            <li>Carica il file compilato con "Carica file Excel"</li>
            <li>Controlla l'anteprima e clicca <strong>Importa</strong></li>
          </ol>
          <div class="mt-2" style="font-size:.8rem;color:var(--qk-text-muted)">
            Campi obbligatori: <strong>sku_interno</strong>, <strong>nome</strong>.
            Le righe con SKU gia presenti vengono saltate (nessun aggiornamento).
          </div>
        </div>

        <div class="qk-flex-row qk-gap-sm mb-3">
          <button type="button" id="btnDownloadTracciato" class="btn-qk btn-qk-secondary btn-qk-sm">
            <i class="fa-solid fa-file-arrow-down me-1"></i>Scarica Tracciato
          </button>
          <label class="btn-qk btn-qk-secondary btn-qk-sm mb-0" style="cursor:pointer">
            <i class="fa-solid fa-folder-open me-1"></i>Carica file Excel
            <input type="file" id="inputFileProdotti" accept=".xlsx,.xls" style="display:none">
          </label>
        </div>

        <div id="importPreviewWrap" style="display:none">
          <div class="qk-flex-row qk-justify-between mb-1">
            <span class="fw-semibold" style="font-size:.875rem">Anteprima (<span id="importPreviewCount">0</span> righe)</span>
            <button type="button" id="btnClearFile" class="btn-qk btn-qk-secondary btn-qk-sm">
              <i class="fa-solid fa-xmark"></i> Rimuovi
            </button>
          </div>
          <div style="max-height:240px;overflow:auto;border:1px solid var(--qk-border);border-radius:6px">
            <table class="qk-table qk-table-sm mb-0">
              <thead id="importPreviewHead"></thead>
              <tbody id="importPreviewBody"></tbody>
            </table>
          </div>
        </div>

        <div id="importResult" style="display:none;margin-top:1rem"></div>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn-qk btn-qk-secondary" data-bs-dismiss="modal">Chiudi</button>
        <button type="button" id="btnImportaProdotti" class="btn-qk btn-qk-primary" disabled>
          <i class="fa-solid fa-upload me-1"></i>Importa
        </button>
      </div>
    </div>
  </div>
</div>
<?php
$_qk_csrf   = csrf_token();
$_qk_import = url('products/import');
?>
<script src="<?= asset('js/exceljs.min.js') ?>"></script>
<script>
(function () {
  'use strict';

  var CSRF_TOKEN  = '<?= $_qk_csrf ?>';
  var IMPORT_URL  = '<?= $_qk_import ?>';

  function downloadBuffer(buffer, filename) {
    var blob = new Blob([buffer], {type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'});
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url; a.download = filename;
    document.body.appendChild(a); a.click();
    setTimeout(function(){ URL.revokeObjectURL(url); a.remove(); }, 1000);
  }

  var COLS = [
    {key:'sku_interno',              note:'Obbligatorio. Deve essere univoco.',                  esempio:'PROD-001'},
    {key:'nome',                     note:'Obbligatorio.',                                       esempio:'Acido Cloridrico 37%'},
    {key:'descrizione_breve',        note:'Testo breve per preventivi.',                         esempio:'HCl 37% - Tanica 25L'},
    {key:'descrizione_confezione',   note:'Formato confezione per documenti commerciali.',       esempio:'Tanica HDPE 25 litri'},
    {key:'tipo',                     note:'"semplice" o "kit". Default: semplice.',              esempio:'semplice'},
    {key:'categoria',                note:'Nome esatto della categoria (case-insensitive).',     esempio:'Acidi'},
    {key:'unita_misura_stock',       note:'Codice UoM di magazzino (es. kg, l, pz). Default: pz.', esempio:'kg'},
    {key:'unita_misura_commerciale', note:'Codice UoM commerciale (es. tanica, sacco, pz).',      esempio:'tanica'},
    {key:'quantita_contenuto',       note:'Quantità del contenuto (es. 25.00 se tanica da 25kg).', esempio:'25.00'},
    {key:'peso_netto_kg',            note:'Peso netto del contenuto in kg.',                     esempio:'25.000'},
    {key:'peso_lordo_kg',            note:'Peso lordo imballo incluso in kg.',                  esempio:'26.200'},
    {key:'ean',                      note:'Codice EAN-13 della confezione.',                    esempio:'8012345678901'},
    {key:'sku_ecommerce',            note:'SKU usato su WooCommerce (opzionale).',              esempio:'WOO-HCL-25L'},
    {key:'prezzo_vendita_base',      note:'Prezzo nel listino base (listino 1).',               esempio:'45.00'},
    {key:'scorta_minima',            note:'Quantita minima di riordino. Default: 0.',           esempio:'5'},
    {key:'is_adr',                   note:'"1" se ADR, altrimenti "0".',                        esempio:'1'},
    {key:'traccia_lotti',            note:'"1" per attivare la gestione lotti.',                esempio:'1'},
    {key:'attivo',                   note:'"1" attivo, "0" disabilitato. Default: 1.',          esempio:'1'},
    {key:'note_interne',             note:'Annotazioni ad uso interno.',                        esempio:''},
  ];

  document.getElementById('btnDownloadTracciato').addEventListener('click', async function(){
    var btn = this;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Generazione...';

    var wb = new ExcelJS.Workbook();
    wb.creator = 'Quack ERP';
    var ws = wb.addWorksheet('Prodotti');
    ws.columns = COLS.map(function(c){ return {header:c.key, key:c.key, width:Math.max(c.key.length+4,22)}; });

    var hr = ws.getRow(1);
    COLS.forEach(function(c,i){
      var cell = hr.getCell(i+1);
      cell.value = c.key;
      cell.font  = {bold:true, color:{argb:'FFFFFFFF'}};
      cell.fill  = {type:'pattern', pattern:'solid', fgColor:{argb:'FF2E4057'}};
      cell.alignment = {vertical:'middle', horizontal:'center'};
      cell.note  = {texts:[{text:c.note}]};
    });
    hr.height = 22;

    var ex = ws.addRow(COLS.reduce(function(a,c){ a[c.key]=c.esempio; return a; }, {}));
    ex.eachCell(function(cell){
      cell.fill = {type:'pattern', pattern:'solid', fgColor:{argb:'FFEAF2FB'}};
      cell.font = {italic:true, color:{argb:'FF555555'}};
    });
    ws.views = [{state:'frozen', ySplit:1}];

    var wg = wb.addWorksheet('Guida');
    wg.getColumn(1).width = 28; wg.getColumn(2).width = 55;
    wg.addRow(['Campo','Descrizione']);
    wg.getRow(1).eachCell(function(c){
      c.font = {bold:true, color:{argb:'FFFFFFFF'}};
      c.fill = {type:'pattern', pattern:'solid', fgColor:{argb:'FF2E4057'}};
    });
    COLS.forEach(function(c){
      var row = wg.addRow([c.key, c.note]);
      if (c.key === 'sku_interno' || c.key === 'nome')
        row.getCell(1).font = {bold:true, color:{argb:'FFCC0000'}};
    });

    downloadBuffer(await wb.xlsx.writeBuffer(), 'Prodotti_Tracciato.xlsx');
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-file-arrow-down me-1"></i>Scarica Tracciato';
  });

  var parsedRows = [];

  document.getElementById('inputFileProdotti').addEventListener('change', async function(){
    var file = this.files[0];
    if (!file) return;
    var reader = new FileReader();
    reader.onload = async function(e){
      var wb = new ExcelJS.Workbook();
      await wb.xlsx.load(e.target.result);
      var ws = wb.worksheets[0];
      if (!ws) { alert('File non valido.'); return; }

      var headers = [];
      ws.getRow(1).eachCell({includeEmpty:false}, function(cell){
        headers.push(String(cell.value !== null && cell.value !== undefined ? cell.value : '').trim());
      });

      parsedRows = [];
      ws.eachRow(function(row, rn){
        if (rn === 1) return;
        var obj = {};
        headers.forEach(function(h,i){
          var v = row.getCell(i+1).value;
          obj[h] = (v !== null && v !== undefined) ? String(v).trim() : '';
        });
        if (Object.values(obj).some(function(v){ return v !== ''; })) parsedRows.push(obj);
      });
      renderPreview(headers, parsedRows);
    };
    reader.readAsArrayBuffer(file);
  });

  function renderPreview(headers, rows){
    var th = '<tr>';
    headers.forEach(function(h){ th += '<th style="white-space:nowrap">'+esc(h)+'</th>'; });
    th += '</tr>';
    document.getElementById('importPreviewHead').innerHTML = th;

    var tb = '';
    rows.slice(0,10).forEach(function(row){
      tb += '<tr>';
      headers.forEach(function(h){
        var v = row[h] !== undefined ? row[h] : '';
        var bad = (h==='sku_interno'||h==='nome') && v==='';
        tb += '<td style="white-space:nowrap'+(bad?';color:var(--qk-danger);font-weight:600':'')+'">' + esc(v||'--') + '</td>';
      });
      tb += '</tr>';
    });
    if (rows.length > 10)
      tb += '<tr><td colspan="'+headers.length+'" class="text-center qk-text-muted" style="font-size:.8rem">... e altre '+(rows.length-10)+' righe</td></tr>';

    document.getElementById('importPreviewBody').innerHTML = tb;
    document.getElementById('importPreviewCount').textContent = rows.length;
    document.getElementById('importPreviewWrap').style.display = 'block';
    document.getElementById('btnImportaProdotti').disabled = rows.length === 0;
    document.getElementById('importResult').style.display  = 'none';
  }

  document.getElementById('btnClearFile').addEventListener('click', function(){
    parsedRows = [];
    document.getElementById('inputFileProdotti').value = '';
    document.getElementById('importPreviewWrap').style.display = 'none';
    document.getElementById('btnImportaProdotti').disabled = true;
    document.getElementById('importResult').style.display  = 'none';
  });

  document.getElementById('modalImportaProdotti').addEventListener('hidden.bs.modal', function(){
    document.getElementById('btnClearFile').click();
  });

  function normalizeHeader(h) {
    var clean = String(h || '').trim().toLowerCase()
      .replace(/[\s\-\_]+/g, '_')
      .replace(/[\(\)]/g, '');
    
    var map = {
      'sku': 'sku_interno',
      'sku_interno': 'sku_interno',
      'codice': 'sku_interno',
      'codice_articolo': 'sku_interno',
      'nome': 'nome',
      'nome_prodotto': 'nome',
      'prodotto': 'nome',
      'descrizione': 'descrizione_breve',
      'descrizione_breve': 'descrizione_breve',
      'confezione': 'descrizione_confezione',
      'formato': 'descrizione_confezione',
      'descrizione_confezione': 'descrizione_confezione',
      'categoria': 'categoria',
      'categorie': 'categoria',
      
      'unita_misura_stock': 'unita_misura_stock',
      'unita_misura': 'unita_misura_stock',
      'um': 'unita_misura_stock',
      'uom': 'unita_misura_stock',
      
      'unita_misura_commerciale': 'unita_misura_commerciale',
      'unita_commerciale': 'unita_misura_commerciale',
      'um_commerciale': 'unita_misura_commerciale',
      'uom_commerciale': 'unita_misura_commerciale',
      
      'quantita_contenuto': 'quantita_contenuto',
      'quantità_contenuto': 'quantita_contenuto',
      'quantita': 'quantita_contenuto',
      'quantità': 'quantita_contenuto',
      
      'peso_netto': 'peso_netto_kg',
      'peso_netto_kg': 'peso_netto_kg',
      'peso': 'peso_netto_kg',
      'peso_lordo': 'peso_lordo_kg',
      'peso_lordo_kg': 'peso_lordo_kg',
      'ean_13': 'ean',
      'ean': 'ean',
      'codice_ean': 'ean',
      'scorta_minima': 'scorta_minima',
      'scorta': 'scorta_minima',
      'adr': 'is_adr',
      'is_adr': 'is_adr',
      'lotti': 'traccia_lotti',
      'traccia_lotti': 'traccia_lotti',
      'gestione_lotti': 'traccia_lotti',
      'attivo': 'attivo',
      'stato': 'attivo'
    };
    return map[clean] || clean;
  }

  document.getElementById('btnImportaProdotti').addEventListener('click', async function(){
    if (!parsedRows.length) return;
    var btn = this;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Importazione...';

    // Normalizziamo le chiavi dell'oggetto da inviare in base ai sinonimi
    var normalizedRows = parsedRows.map(function(row) {
      var normObj = {};
      Object.keys(row).forEach(function(key) {
        var normKey = normalizeHeader(key);
        normObj[normKey] = row[key];
      });
      return normObj;
    });

    var fd = new FormData();
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('rows', JSON.stringify(normalizedRows));

    try {
      var resp   = await fetch(IMPORT_URL, {method:'POST', body:fd});
      var result = await resp.json();
      var box    = document.getElementById('importResult');
      box.style.display = 'block';

      if (!result.ok) {
        box.innerHTML = '<div class="alert alert-danger py-2"><i class="fa-solid fa-triangle-exclamation me-1"></i>' + esc(result.error) + '</div>';
      } else {
        var html = '<div class="alert alert-success py-2 mb-2">'
                 + '<i class="fa-solid fa-circle-check me-1"></i>'
                 + '<strong>'+result.inserted+'</strong> prodotti importati'
                 + (result.skipped>0 ? ', <strong>'+result.skipped+'</strong> saltati' : '') + '.'
                 + '</div>';
        if (result.errors && result.errors.length) {
          html += '<details style="font-size:.8rem"><summary style="cursor:pointer" class="qk-text-muted">Dettaglio righe saltate ('+result.errors.length+')</summary>'
               + '<ul class="mt-1 mb-0">';
          result.errors.forEach(function(e){ html += '<li>'+esc(e)+'</li>'; });
          html += '</ul></details>';
        }
        box.innerHTML = html;
        if (result.inserted > 0) {
          parsedRows = [];
          document.getElementById('importPreviewWrap').style.display = 'none';
          setTimeout(function(){ window.location.reload(); }, 1500);
        }
      }
    } catch(err) {
      document.getElementById('importResult').innerHTML =
        '<div class="alert alert-danger py-2">Errore di rete: '+esc(err.message)+'</div>';
    }
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-upload me-1"></i>Importa';
  });

  function esc(str){
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
})();
</script>

<!-- Barra Azioni Massive Fluttuante (Premium UI) -->
<div id="bulk-actions-bar" class="qk-bulk-actions-bar">
  <div class="qk-bulk-actions-content qk-flex-row qk-justify-between qk-gap-md">
    <div class="qk-flex-row qk-gap-sm align-items-center">
      <span class="qk-badge confirmed font-monospace" id="bulk-select-count-badge" style="font-size: 0.9rem; padding: 4px 8px;">0</span>
      <span class="fw-semibold text-white">Selezionati</span>
    </div>
    
    <div class="qk-flex-row qk-gap-sm align-items-center flex-wrap">
      <select id="bulk-substance-select" class="qk-form-control py-1" style="width: auto; max-width: 250px; background: rgba(0,0,0,0.4); border-color: rgba(255,255,255,0.15); color: #fff; border-radius: 6px;">
        <option value="" style="background: var(--qk-card-bg); color: var(--qk-text-primary);">— Associa Sostanza ADR —</option>
        <option value="0" style="background: var(--qk-card-bg); color: var(--qk-text-primary);">— Rimuovi Sostanza ADR (Disassocia) —</option>
        <?php if (!empty($adr_sostanze)): foreach ($adr_sostanze as $sub): ?>
          <option value="<?= (int)$sub['id'] ?>" style="background: var(--qk-card-bg); color: var(--qk-text-primary);">
            <?= e($sub['nome']) ?> <?= $sub['codice_onu'] ? '[UN' . e($sub['codice_onu']) . ']' : '' ?>
          </option>
        <?php endforeach; endif; ?>
      </select>
      
      <button type="button" id="btn-bulk-apply" class="btn-qk btn-qk-primary btn-qk-sm" disabled>
        <i class="fa-solid fa-link me-1"></i> Applica
      </button>
      
      <button type="button" id="btn-bulk-cancel" class="btn-qk btn-qk-secondary btn-qk-sm" style="border-color: rgba(255,255,255,0.15); color: #fff; background: transparent;">
        Annulla
      </button>
    </div>
  </div>
</div>

<style>
.qk-bulk-actions-bar {
  position: fixed;
  bottom: 24px;
  left: 50%;
  transform: translateX(-50%) translateY(100px);
  z-index: 1050;
  width: 90%;
  max-width: 650px;
  background: rgba(17, 24, 39, 0.85); /* Sfondo scuro semitrasparente */
  backdrop-filter: blur(14px);
  -webkit-backdrop-filter: blur(14px);
  border: 1px solid rgba(255, 255, 255, 0.15);
  border-radius: 12px;
  padding: 10px 18px;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
  transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.3s ease;
  opacity: 0;
}
.qk-bulk-actions-bar.show {
  transform: translateX(-50%) translateY(0);
  opacity: 1;
}
.qk-bulk-actions-content {
  display: flex;
  align-items: center;
}
/* Stile per i checkbox selezionati nella riga */
.qk-table tr.row-selected {
  background-color: var(--qk-accent-light) !important;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var selectAll = document.getElementById('select-all-products');
  var checkboxes = document.querySelectorAll('.product-select-chk');
  var bar = document.getElementById('bulk-actions-bar');
  var badge = document.getElementById('bulk-select-count-badge');
  var selectSub = document.getElementById('bulk-substance-select');
  var btnApply = document.getElementById('btn-bulk-apply');
  var btnCancel = document.getElementById('btn-bulk-cancel');

  function updateBarState() {
    var selected = document.querySelectorAll('.product-select-chk:checked');
    var count = selected.length;
    badge.textContent = count;
    
    // Evidenzia le righe selezionate
    checkboxes.forEach(function(chk) {
      var tr = chk.closest('tr');
      if (tr) {
        tr.classList.toggle('row-selected', chk.checked);
      }
    });

    if (count > 0) {
      bar.classList.add('show');
    } else {
      bar.classList.remove('show');
      if (selectAll) selectAll.checked = false;
    }
  }

  if (selectAll) {
    selectAll.addEventListener('change', function () {
      var state = this.checked;
      checkboxes.forEach(function (chk) {
        chk.checked = state;
      });
      updateBarState();
    });
  }

  checkboxes.forEach(function (chk) {
    chk.addEventListener('change', function () {
      if (selectAll && !this.checked) {
        selectAll.checked = false;
      }
      updateBarState();
    });
  });

  if (selectSub) {
    selectSub.addEventListener('change', function () {
      btnApply.disabled = (this.value === '');
    });
  }

  if (btnCancel) {
    btnCancel.addEventListener('click', function () {
      checkboxes.forEach(function (chk) {
        chk.checked = false;
      });
      if (selectAll) selectAll.checked = false;
      updateBarState();
    });
  }

  if (btnApply) {
    btnApply.addEventListener('click', async function () {
      var subId = selectSub.value;
      var selected = document.querySelectorAll('.product-select-chk:checked');
      var ids = Array.from(selected).map(function (chk) {
        return chk.dataset.id;
      });

      if (ids.length === 0 || subId === '') return;

      btnApply.disabled = true;
      btnApply.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Applicazione…';

      var fd = new FormData();
      fd.append('csrf_token', '<?= csrf_token() ?>');
      fd.append('sostanza_id', subId);
      ids.forEach(function (id) {
        fd.append('prodotto_ids[]', id);
      });

      try {
        var resp = await fetch('<?= url("products/api/batch-associa-adr") ?>', {
          method: 'POST',
          body: fd
        });
        var res = await resp.json();
        if (res.ok) {
          window.qkToast(ids.length + ' prodotti aggiornati con successo!', 'success');
          setTimeout(function () {
            window.location.reload();
          }, 1000);
        } else {
          window.qkToast(res.error || 'Errore durante l\'aggiornamento.', 'danger');
          btnApply.disabled = false;
          btnApply.innerHTML = '<i class="fa-solid fa-link me-1"></i> Applica';
        }
      } catch (err) {
        window.qkToast('Errore di rete.', 'danger');
        btnApply.disabled = false;
        btnApply.innerHTML = '<i class="fa-solid fa-link me-1"></i> Applica';
      }
    });
  }
});
</script>
