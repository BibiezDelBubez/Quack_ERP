(function() {
  var cfg = window.QuackCategoriesConfig || {};
  var CSRF_TOKEN = cfg.csrfToken;
  var IMPORT_URL = cfg.importUrl;
  var EXPORT_URL = cfg.exportUrl;

  var CAT_COLS = ['nome','parent_nome','posizione_categoria','immagine_path'];
  var CAT_HEADERS = ['nome *','parent_nome','posizione_categoria','immagine_path'];

  // ── Categoria Genitore ───────────────────────────────────────────────────
  // Migrato da window.qkAutocomplete a QkM2O (componente condiviso, vedi
  // docs/plans/PIANO - Unificazione Autocomplete, D4, 27/07/2026).
  // L'esclusione della categoria stessa dai risultati (non puo' essere
  // genitore di se stessa) prima era un filtro client-side qui; ora e'
  // lato server via exclude_id (qk_search_categorie), perche' QkM2O
  // interroga l'endpoint direttamente senza hook di trasformazione dati.
  document.addEventListener('DOMContentLoaded', function() {
    var pInput = document.getElementById('cat-parent-label');
    var pHidden = document.getElementById('cat-parent-id');
    var pDropdown = document.getElementById('cat-parent-dropdown');
    var pClear = document.getElementById('cat-parent-clear');
    var selfId = document.getElementById('cat-id') ? document.getElementById('cat-id').value : '';

    if (pInput) {
      var apiUrl = cfg.apiUrl + (selfId ? '?exclude_id=' + encodeURIComponent(selfId) : '');

      new QkM2O({
        inputEl:    pInput,
        hiddenEl:   pHidden,
        dropdownEl: pDropdown,
        clearBtn:   pClear,
        apiUrl:     apiUrl,
        labelKey:   'nome',
        columns:    [{ key: 'nome', label: 'Nome' }],
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
              imgSrc = cfg.baseUrl + '/' + immaginePath;
            } else {
              imgSrc = cfg.uploadsUrl + encodeURIComponent(immaginePath);
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
