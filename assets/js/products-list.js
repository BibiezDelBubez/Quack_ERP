(function () {
  'use strict';

  var cfg = window.QuackListConfig || {};
  var CSRF_TOKEN  = cfg.csrfToken;
  var IMPORT_URL  = cfg.importUrl;

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
    {key:'is_adr',                   note:'"1" se ADR, altrimenti "0".',                        esempio:'1'},
    {key:'traccia_lotti',            note:'"1" per attivare la gestione lotti.',                esempio:'1'},
    {key:'attivo',                   note:'"1" attivo, "0" disabilitato. Default: 1.',          esempio:'1'},
    {key:'note_interne',             note:'Annotazioni ad uso interno.',                        esempio:''},
  ];

  var btnDownloadTracciato = document.getElementById('btnDownloadTracciato');
  if (btnDownloadTracciato) {
    btnDownloadTracciato.addEventListener('click', async function(){
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
  }

  var parsedRows = [];
  var inputFileProdotti = document.getElementById('inputFileProdotti');

  if (inputFileProdotti) {
    inputFileProdotti.addEventListener('change', async function(){
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
  }

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

  var btnClearFile = document.getElementById('btnClearFile');
  if (btnClearFile) {
    btnClearFile.addEventListener('click', function(){
      parsedRows = [];
      document.getElementById('inputFileProdotti').value = '';
      document.getElementById('importPreviewWrap').style.display = 'none';
      document.getElementById('btnImportaProdotti').disabled = true;
      document.getElementById('importResult').style.display  = 'none';
    });
  }

  var modalImportaProdotti = document.getElementById('modalImportaProdotti');
  if (modalImportaProdotti) {
    modalImportaProdotti.addEventListener('hidden.bs.modal', function(){
      if (document.getElementById('btnClearFile')) document.getElementById('btnClearFile').click();
    });
  }

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

  var btnImportaProdotti = document.getElementById('btnImportaProdotti');
  if (btnImportaProdotti) {
    btnImportaProdotti.addEventListener('click', async function(){
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
  }

  function esc(str){
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
})();

document.addEventListener('DOMContentLoaded', function () {
  var cfg = window.QuackListConfig || {};

  /* ── Mostra/Nascondi Colonne ───────────────────────────────────────── */
  var btnColSettings = document.getElementById('btn-col-settings');
  var colSettingsDropdown = document.getElementById('col-settings-dropdown');
  var chkCosto = document.getElementById('chk-show-costo');
  var chkConfezione = document.getElementById('chk-show-confezione');

  if (btnColSettings && colSettingsDropdown) {
    btnColSettings.addEventListener('click', function (e) {
      e.stopPropagation();
      var isVisible = colSettingsDropdown.style.display === 'block';
      colSettingsDropdown.style.display = isVisible ? 'none' : 'block';
    });

    document.addEventListener('click', function (e) {
      if (!colSettingsDropdown.contains(e.target) && e.target !== btnColSettings) {
        colSettingsDropdown.style.display = 'none';
      }
    });
  }

  function updateColumnVisibility() {
    var showCosto = chkCosto ? chkCosto.checked : false;
    var showConfezione = chkConfezione ? chkConfezione.checked : false;

    document.querySelectorAll('.col-costo-acquisto').forEach(function (el) {
      el.style.display = showCosto ? '' : 'none';
    });
    document.querySelectorAll('.col-confezione').forEach(function (el) {
      el.style.display = showConfezione ? '' : 'none';
    });

    localStorage.setItem('qk_prod_show_costo', showCosto ? '1' : '0');
    localStorage.setItem('qk_prod_show_confezione', showConfezione ? '1' : '0');
  }

  if (chkCosto && chkConfezione) {
    // Hidden by default, unless previously stored as '1'
    chkCosto.checked = localStorage.getItem('qk_prod_show_costo') === '1';
    chkConfezione.checked = localStorage.getItem('qk_prod_show_confezione') === '1';
    updateColumnVisibility();

    [chkCosto, chkConfezione].forEach(function (chk) {
      chk.addEventListener('change', updateColumnVisibility);
    });
  }

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
    if (badge) badge.textContent = count;
    
    // Evidenzia le righe selezionate
    checkboxes.forEach(function(chk) {
      var tr = chk.closest('tr');
      if (tr) {
        tr.classList.toggle('row-selected', chk.checked);
      }
    });

    if (bar) {
      if (count > 0) {
        bar.classList.add('show');
      } else {
        bar.classList.remove('show');
        if (selectAll) selectAll.checked = false;
      }
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

  if (selectSub && btnApply) {
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
      fd.append('csrf_token', cfg.csrfToken);
      fd.append('sostanza_id', subId);
      ids.forEach(function (id) {
        fd.append('prodotto_ids[]', id);
      });

      try {
        var resp = await fetch(cfg.batchAssociaUrl, {
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
