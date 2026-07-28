(function () {
  'use strict';

  var LISTINO_ID = window.QkPricelistConfig.listinoId;
  var CSRF_TOKEN = window.QkPricelistConfig.csrfToken;
  var IMPORT_URL = window.QkPricelistConfig.apiImportUrl;

  var COLS_PREZZI = [
    {key:'sku_interno',     note:'Obbligatorio. SKU interno del prodotto in Quack.', esempio:'PROD-001'},
    {key:'quantita_minima', note:'Quantità minima per questa tariffa.',              esempio:'1.000'},
    {key:'prezzo_unitario', note:'Obbligatorio. Prezzo unitario di vendita.',       esempio:'15.5000'},
    {key:'sconto_perc',     note:'Sconto percentuale da applicare.',                 esempio:'5.00'},
    {key:'note',            note:'Note specifiche del prezzo.',                      esempio:'Prezzo promozionale'}
  ];

  function downloadBuffer(buffer, filename) {
    var blob = new Blob([buffer], {type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'});
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url; a.download = filename;
    document.body.appendChild(a); a.click();
    setTimeout(function(){ URL.revokeObjectURL(url); a.remove(); }, 1000);
  }

  document.getElementById('btnDownloadTracciatoPrezziSales')?.addEventListener('click', async function(){
    var btn = this;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Generazione...';

    var wb = new ExcelJS.Workbook();
    wb.creator = 'Quack ERP';
    var ws = wb.addWorksheet('Tariffe Vendita');
    ws.columns = COLS_PREZZI.map(function(c){ return {header:c.key, key:c.key, width:Math.max(c.key.length+4,22)}; });

    var hr = ws.getRow(1);
    COLS_PREZZI.forEach(function(c,i){
      var cell = hr.getCell(i+1);
      cell.value = c.key;
      cell.font  = {bold:true, color:{argb:'FFFFFFFF'}};
      cell.fill  = {type:'pattern', pattern:'solid', fgColor:{argb:'FF2E4057'}};
      cell.alignment = {vertical:'middle', horizontal:'center'};
      cell.note  = {texts:[{text:c.note}]};
    });
    hr.height = 22;

    var ex = ws.addRow(COLS_PREZZI.reduce(function(a,c){ a[c.key]=c.esempio; return a; }, {}));
    ex.eachCell(function(cell){
      cell.fill = {type:'pattern', pattern:'solid', fgColor:{argb:'FFEAF2FB'}};
      cell.font = {italic:true, color:{argb:'FF555555'}};
    });
    ws.views = [{state:'frozen', ySplit:1}];

    downloadBuffer(await wb.xlsx.writeBuffer(), 'Listino_Vendite_Tracciato.xlsx');
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-file-arrow-down me-1"></i>Scarica Modello Tracciato';
  });

  var parsedRows = [];

  document.getElementById('inputFilePrezziSales')?.addEventListener('change', async function(){
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

  document.getElementById('btnClearFilePrezziSales')?.addEventListener('click', function(){
    document.getElementById('inputFilePrezziSales').value = '';
    document.getElementById('importPreviewWrapPrezziSales').style.display = 'none';
    document.getElementById('btnImportaPrezziSales').disabled = true;
    document.getElementById('importResultPrezziSales').style.display = 'none';
    parsedRows = [];
  });

  function renderPreview(headers, rows) {
    var head = document.getElementById('importPreviewHeadPrezziSales');
    var body = document.getElementById('importPreviewBodyPrezziSales');
    head.innerHTML = ''; body.innerHTML = '';

    var trH = document.createElement('tr');
    headers.forEach(function(h){
      var th = document.createElement('th'); th.textContent = h; trH.appendChild(th);
    });
    head.appendChild(trH);

    var preview = rows.slice(0, 10);
    preview.forEach(function(r){
      var tr = document.createElement('tr');
      headers.forEach(function(h){
        var td = document.createElement('td'); td.textContent = r[h] || ''; tr.appendChild(td);
      });
      body.appendChild(tr);
    });

    document.getElementById('importPreviewCountPrezziSales').textContent = rows.length;
    document.getElementById('importPreviewWrapPrezziSales').style.display = 'block';
    document.getElementById('btnImportaPrezziSales').disabled = rows.length === 0;
  }

  document.getElementById('btnImportaPrezziSales')?.addEventListener('click', async function(){
    if (parsedRows.length === 0) return;
    var btn = this;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Importazione in corso...';

    var fd = new FormData();
    fd.append('_token', CSRF_TOKEN);
    fd.append('listino_id', LISTINO_ID);
    fd.append('rows', JSON.stringify(parsedRows));

    try {
      // DRY 27/07/2026 (PIANO qkApiFetch): validazione HTTP/content-type
      // delegata a window.qkApiFetch() (assets/js/app.js).
      var d = await window.qkApiFetch(IMPORT_URL, { method:'POST', body:fd });
      var resWrap = document.getElementById('importResultPrezziSales');
      resWrap.className = d.ok ? 'qk-alert qk-alert-success mt-3' : 'qk-alert qk-alert-danger mt-3';

      var html = '<strong>' + (d.ok ? 'Importazione completata con successo!' : 'Errore nell\'importazione.') + '</strong><br>';
      if (d.ok) {
        html += 'Caricate/Aggiornate ' + d.inserted + ' righe. ';
        if (d.skipped > 0) html += 'Saltate ' + d.skipped + ' righe con errori.<br>';
        if (d.removed > 0) html += 'Rimosse ' + d.removed + ' righe non più presenti nel file (assenti dal nuovo listino).<br>';
      } else {
        html += d.error || '';
      }

      if (d.errors && d.errors.length > 0) {
        html += '<ul class="mb-0 mt-2 text-start small" style="max-height:150px; overflow-y:auto">';
        d.errors.forEach(function(err){ html += '<li>' + err + '</li>'; });
        html += '</ul>';
      }

      resWrap.innerHTML = html;
      resWrap.style.display = 'block';

      if (d.ok) {
        setTimeout(function(){ window.location.reload(); }, 1500);
      }
    } catch(err) {
      alert('Errore di connessione.');
    } finally {
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-upload me-1"></i>Avvia Importazione';
    }
  });

})();