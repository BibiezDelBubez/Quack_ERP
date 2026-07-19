(function () {
// ── Aggiorna URL quando si cambia tab (bookmark-friendly) ─────────────
  document.querySelectorAll('#adrTabs [data-tab-key]').forEach(function (btn) {
    btn.addEventListener('shown.bs.tab', function () {
      var key = btn.dataset.tabKey;
      var url = new URL(window.location.href);
      url.searchParams.set('tab', key);
      history.replaceState(null, '', url.toString());
    });
  });

  // ── Popola modal classi ────────────────────────────────────────────────
  var modalClasse = document.getElementById('modal-classe');
  if (modalClasse) {
    modalClasse.addEventListener('show.bs.modal', function (e) {
      var btn    = e.relatedTarget;
      var mode   = btn ? btn.dataset.mode : 'create';
      var label  = modalClasse.querySelector('.modal-title');
      var fCodice = document.getElementById('mc-codice');
      var fDenom  = document.getElementById('mc-denom');
      var fDiv    = document.getElementById('mc-div');

      if (mode === 'edit') {
        label.textContent    = 'Modifica classe ' + btn.dataset.codice;
        fCodice.value        = btn.dataset.codice;
        fCodice.readOnly     = true;
        fDenom.value         = btn.dataset.denominazione;
        fDiv.checked         = btn.dataset.divisioni === '1';
      } else {
        label.textContent    = 'Nuova classe di pericolo';
        fCodice.value        = '';
        fCodice.readOnly     = false;
        fDenom.value         = '';
        fDiv.checked         = false;
      }
    });
  }

  // ── Popola modal imballaggio ───────────────────────────────────────────
  var modalImb = document.getElementById('modal-imb');
  if (modalImb) {
    modalImb.addEventListener('show.bs.modal', function (e) {
      var btn    = e.relatedTarget;
      var mode   = btn ? btn.dataset.mode : 'create';
      var label  = modalImb.querySelector('.modal-title');
      var fCodice = document.getElementById('mi-codice');
      var fDesc   = document.getElementById('mi-desc');

      if (mode === 'edit') {
        label.textContent = 'Modifica gruppo ' + btn.dataset.codice;
        fCodice.value     = btn.dataset.codice;
        fCodice.readOnly  = true;
        fDesc.value       = btn.dataset.descrizione;
      } else {
        label.textContent = 'Nuovo gruppo imballaggio';
        fCodice.value     = '';
        fCodice.readOnly  = false;
        fDesc.value       = '';
      }
    });
  }

  // ── Popola modal galleria ──────────────────────────────────────────────
  var modalGalleria = document.getElementById('modal-galleria');
  if (modalGalleria) {
    modalGalleria.addEventListener('show.bs.modal', function (e) {
      var btn    = e.relatedTarget;
      var mode   = btn ? btn.dataset.mode : 'create';
      var label  = modalGalleria.querySelector('.modal-title');
      var fCodice   = document.getElementById('mg-codice');
      var fCisterna = document.getElementById('mg-cisterna');
      var fDesc     = document.getElementById('mg-desc');

      if (mode === 'edit') {
        label.textContent    = 'Modifica codice ' + btn.dataset.codice;
        fCodice.value        = btn.dataset.codice;
        fCodice.readOnly     = true;
        fCisterna.value      = btn.dataset.cisterna;
        fDesc.value          = btn.dataset.descrizione;
      } else {
        label.textContent    = 'Nuovo codice galleria';
        fCodice.value        = '';
        fCodice.readOnly     = false;
        fCisterna.value      = '';
        fDesc.value          = '';
      }
    });
  }

  // ── Popola modal categoria trasporto ──────────────────────────────────
  var modalCat = document.getElementById('modal-categoria');
  if (modalCat) {
    modalCat.addEventListener('show.bs.modal', function (e) {
      var btn   = e.relatedTarget;
      var mode  = btn ? btn.dataset.mode : 'create';
      var label = modalCat.querySelector('.modal-title');
      var fCat  = document.getElementById('mcat-cat');
      var fMolt = document.getElementById('mcat-molt');
      var fSogl = document.getElementById('mcat-soglia');
      var fDesc = document.getElementById('mcat-desc');
      if (mode === 'edit') {
        label.textContent = 'Modifica categoria ' + btn.dataset.categoria;
        fCat.value        = btn.dataset.categoria;
        fCat.readOnly     = true;
        fMolt.value       = btn.dataset.moltiplicatore;
        fSogl.value       = btn.dataset.soglia;
        fDesc.value       = btn.dataset.descrizione;
      } else {
        label.textContent = 'Nuova categoria di trasporto';
        fCat.value        = '';
        fCat.readOnly     = false;
        fMolt.value       = '';
        fSogl.value       = '';
        fDesc.value       = '';
      }
    });
  }

  // ── Popola modal codice classificazione ───────────────────────────────
  var modalClassif = document.getElementById('modal-classificazione');
  if (modalClassif) {
    modalClassif.addEventListener('show.bs.modal', function (e) {
      var btn    = e.relatedTarget;
      var mode   = btn ? btn.dataset.mode : 'create';
      var label  = modalClassif.querySelector('.modal-title');
      var fCodice = document.getElementById('mcl-codice');
      var fClasse = document.getElementById('mcl-classe');
      var fPrim   = document.getElementById('mcl-prim');
      var fSec    = document.getElementById('mcl-sec');
      var fDesc   = document.getElementById('mcl-desc');
      if (mode === 'edit') {
        label.textContent  = 'Modifica classificazione ' + btn.dataset.codice;
        fCodice.value      = btn.dataset.codice;
        fCodice.readOnly   = true;
        fClasse.value      = btn.dataset.classe;
        fPrim.value        = btn.dataset.primario;
        fSec.value         = btn.dataset.secondario;
        fDesc.value        = btn.dataset.descrizione;
      } else {
        label.textContent  = 'Nuovo codice classificazione';
        fCodice.value      = '';
        fCodice.readOnly   = false;
        fClasse.value      = '';
        fPrim.value        = '';
        fSec.value         = '';
        fDesc.value        = '';
      }
    });
  }

  // ══════════════════════════════════════════════════════════════════════
  //  EXPORT / IMPORT — ExcelJS
  // ══════════════════════════════════════════════════════════════════════

  /* Dati correnti (PHP → JS) */
  var CODICI_DATA  = <?= $codici_json ?>;
  var IMPORT_URL   = <?= json_encode($import_url) ?>;

  /* Definizione colonne — uguale per export, tracciato e import */
  var COL_DEFS = [
    { key: 'codice_onu',                      width: 13, note: 'Numero ONU (intero positivo, es. 1203) — OBBLIGATORIO' },
    { key: 'nome_descrizione',                width: 60, note: 'Denominazione ufficiale di trasporto (colonna 2 Tabella A ADR)' },
    { key: 'classe_pericolo',                 width: 16, note: 'Classe di pericolo ADR (es. 2, 3, 4.1, 6.1)' },
    { key: 'codice_classificazione',          width: 22, note: 'Codice classificazione (es. F1, TF1, 2A, 2F)' },
    { key: 'gruppo_imballaggio',              width: 18, note: 'Gruppo imballaggio: I, II o III' },
    { key: 'categoria_trasporto',             width: 18, note: 'Categoria di trasporto: intero 0–4' },
    { key: 'codice_restrizione_gallerie',     width: 24, note: 'Codice restrizione gallerie (es. A, B, C/D, D/E, E)' },
    { key: 'quantita_limitata',               width: 20, note: 'Quantità limitata in ml o g (numero intero)' },
    { key: 'codice_quantita_esente',          width: 22, note: 'Codice quantità esente (es. E0, E1, E2, E3, E4, E5)' },
    { key: 'e_merce_alto_rischio',            width: 20, note: '1 = merce ad alto rischio, 0 = no' },
    { key: 'e_inquinante_marino',             width: 20, note: '1 = inquinante marino, 0 = no' },
    { key: 'inizio_validita',                 width: 15, note: 'Data inizio validità (YYYY-MM-DD), es. 2024-01-01' },
    { key: 'fine_validita',                   width: 15, note: 'Data fine validità (YYYY-MM-DD) — vuoto = indeterminata' },
  ];

  var HEADER_COLOR = 'FF1E3A5F'; /* blu scuro Quack */
  var EXAMPLE_COLOR = 'FFDDE3E9';

  /* Utility: scarica un buffer come file */
  function downloadBuffer(buffer, filename) {
    var blob = new Blob([buffer], {
      type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    });
    var url = URL.createObjectURL(blob);
    var a   = document.createElement('a');
    a.href     = url;
    a.download = filename;
    a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  }

  /* Utility: stile cella intestazione */
  function styleHeader(cell) {
    cell.font      = { bold: true, color: { argb: 'FFFFFFFF' } };
    cell.fill      = { type: 'pattern', pattern: 'solid', fgColor: { argb: HEADER_COLOR } };
    cell.alignment = { vertical: 'middle', horizontal: 'center', wrapText: true };
  }

  /* ── ESPORTA LISTA ─────────────────────────────────────────────────── */
  document.getElementById('btn-esporta').addEventListener('click', async function () {
    var btn = this;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Esportazione…';

    try {
      var wb = new ExcelJS.Workbook();
      wb.creator = 'Quack ERP';
      wb.created = new Date();

      var ws = wb.addWorksheet('Codici ONU');
      ws.columns = COL_DEFS.map(function (c) { return { key: c.key, width: c.width }; });

      /* Intestazione */
      var hRow = ws.addRow(COL_DEFS.map(function (c) { return c.key; }));
      hRow.height = 28;
      hRow.eachCell(styleHeader);

      /* Dati */
      CODICI_DATA.forEach(function (r) {
        var vals = COL_DEFS.map(function (c) {
          var v = r[c.key];
          if (v === null || v === undefined || v === '') return '';
          if (c.key === 'e_merce_alto_rischio' ||
              c.key === 'e_inquinante_marino') {
            return v ? 1 : 0;
          }
          return v;
        });
        ws.addRow(vals).eachCell(function (cell) {
          cell.alignment = { vertical: 'middle' };
        });
      });

      /* Filtri automatici */
      ws.autoFilter = {
        from: { row: 1, column: 1 },
        to:   { row: 1, column: COL_DEFS.length }
      };
      ws.views = [{ state: 'frozen', xSplit: 0, ySplit: 1 }];

      downloadBuffer(
        await wb.xlsx.writeBuffer(),
        'ADR_Codici_ONU_' + new Date().toISOString().slice(0, 10) + '.xlsx'
      );
    } catch (err) {
      alert('Errore durante l\'esportazione: ' + err.message);
    } finally {
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-file-excel me-1"></i>Esporta Lista';
    }
  });

  /* ── SCARICA TRACCIATO ─────────────────────────────────────────────── */
  document.getElementById('btn-scarica-tracciato').addEventListener('click', async function () {
    var btn = this;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Generazione…';

    try {
      var wb = new ExcelJS.Workbook();
      wb.creator = 'Quack ERP';
      wb.created = new Date();

      /* ── Foglio 1: ISTRUZIONI ──────────────────────────────────────── */
      var wsI = wb.addWorksheet('1. ISTRUZIONI');
      wsI.getColumn(1).width = 32;
      wsI.getColumn(2).width = 68;

      var rTitle = wsI.addRow(['TRACCIATO IMPORTAZIONE — ADR Codici ONU', '']);
      rTitle.getCell(1).font = { bold: true, size: 14, color: { argb: 'FF' + HEADER_COLOR.slice(2) } };
      wsI.mergeCells('A1:B1');
      wsI.getRow(1).height = 28;

      wsI.addRow(['Quack ERP — generato il ' + new Date().toLocaleDateString('it-IT'), '']);
      wsI.addRow([]);

      var rIstr = wsI.addRow(['ISTRUZIONI', '']);
      rIstr.getCell(1).font = { bold: true, size: 11 };

      [
        ['1.', 'Passa al foglio "2. IMPORTA_DATI"'],
        ['2.', 'Elimina le 3 righe di esempio (sfondo grigio chiaro) prima di caricare il file'],
        ['3.', 'Inserisci i tuoi dati dalla riga 2 (la riga 1 è l\'intestazione — non modificarla)'],
        ['4.', 'Salva il file e caricalo col pulsante "Carica file Excel" nel modale di importazione'],
        ['5.', 'I record con numero ONU mancante o non valido vengono ignorati automaticamente'],
        ['6.', 'I nuovi record vengono aggiunti alla lista esistente (lo stesso UN# può comparire più volte)'],
      ].forEach(function (row) { wsI.addRow(row); });

      wsI.addRow([]);

      var rCol = wsI.addRow(['COLONNA / CAMPO DB', 'DESCRIZIONE E VALORI AMMESSI']);
      rCol.eachCell(styleHeader);

      COL_DEFS.forEach(function (c) {
        wsI.addRow([c.key, c.note]);
      });

      /* ── Foglio 2: IMPORTA_DATI ────────────────────────────────────── */
      var wsD = wb.addWorksheet('2. IMPORTA_DATI');
      wsD.columns = COL_DEFS.map(function (c) { return { key: c.key, width: c.width }; });

      var hRow = wsD.addRow(COL_DEFS.map(function (c) { return c.key; }));
      hRow.height = 28;
      hRow.eachCell(styleHeader);

      /* Righe di esempio (dati reali ADR, sfondo grigio) */
      var examples = [
        [1009, '2', '2A', '',    '', '',    0, 'E1', '', 0, 0, 0, '', ''],
        [1010, '2', '2F', '',    '', '',    0, 'E0', '', 0, 0, 0, '', ''],
        [1011, '2', '2F', '',    '', '',    0, 'E0', '', 0, 0, 0, '', ''],
      ];
      examples.forEach(function (ex) {
        var exRow = wsD.addRow(ex);
        exRow.eachCell(function (cell) {
          cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: EXAMPLE_COLOR } };
          cell.font = { italic: true, color: { argb: 'FF777777' } };
        });
      });

      wsD.autoFilter = {
        from: { row: 1, column: 1 },
        to:   { row: 1, column: COL_DEFS.length }
      };
      wsD.views = [{ state: 'frozen', xSplit: 0, ySplit: 1 }];

      downloadBuffer(await wb.xlsx.writeBuffer(), 'ADR_Codici_ONU_Tracciato.xlsx');
    } catch (err) {
      alert('Errore durante la generazione del tracciato: ' + err.message);
    } finally {
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-file-arrow-down me-1"></i>Scarica Tracciato';
    }
  });

  /* ── IMPORTAZIONE FILE ─────────────────────────────────────────────── */

  /* Reset modale ogni volta che si apre */
  var modalImport = document.getElementById('modal-import');
  if (modalImport) {
    modalImport.addEventListener('show.bs.modal', function () {
      setImportStep('instructions');
      document.getElementById('import-file-input').value = '';
    });
  }

  /* Pulsante Riprova: riporta alle istruzioni */
  document.getElementById('import-btn-retry').addEventListener('click', function () {
    setImportStep('instructions');
    document.getElementById('import-file-input').value = '';
  });

  document.getElementById('import-file-input').addEventListener('change', async function () {
    var file = this.files[0];
    if (!file) return;

    setImportStep('processing');

    try {
      var buf = await file.arrayBuffer();
      var wb  = new ExcelJS.Workbook();
      await wb.xlsx.load(buf);

      /* Cerca il foglio "2. IMPORTA_DATI", fallback al primo */
      var ws = wb.getWorksheet('2. IMPORTA_DATI') || wb.worksheets[0];
      if (!ws) {
        throw new Error('Impossibile trovare il foglio dati nel file Excel.');
      }

      /* Leggi l'intestazione dalla riga 1 */
      var headers = [];
      ws.getRow(1).eachCell({ includeEmpty: true }, function (cell) {
        headers.push(((cell.value !== null && cell.value !== undefined) ? String(cell.value) : '').trim());
      });

      /* Mappa chiave → indice colonna (0-based) */
      var keyMap = {};
      COL_DEFS.forEach(function (c) {
        var idx = headers.indexOf(c.key);
        if (idx >= 0) keyMap[c.key] = idx;
      });

      if (keyMap['codice_onu'] === undefined) {
        throw new Error(
          'Intestazione "codice_onu" non trovata (riga 1). ' +
          'Assicurati di usare il tracciato originale senza modificare la riga 1.'
        );
      }

      /* Leggi le righe dati (salta riga 1 = intestazione) */
      var rows = [];
      ws.eachRow(function (row, rowNum) {
        if (rowNum === 1) return;
        var obj = {};
        var hasData = false;

        COL_DEFS.forEach(function (c) {
          var cellVal = '';
          if (keyMap[c.key] !== undefined) {
            var cell = row.getCell(keyMap[c.key] + 1);
            var v = cell.value;
            if (v === null || v === undefined) {
              cellVal = '';
            } else if (typeof v === 'object' && v !== null) {
              /* Rich text o formula */
              cellVal = (v.text || v.result || '').toString().trim();
            } else {
              cellVal = v.toString().trim();
            }
          }
          obj[c.key] = cellVal;
          if (c.key === 'codice_onu' && cellVal !== '') hasData = true;
        });

        if (hasData) rows.push(obj);
      });

      if (rows.length === 0) {
        throw new Error(
          'Il file non contiene righe di dati valide. ' +
          'Ricorda di eliminare le righe di esempio prima del caricamento.'
        );
      }

      /* Invia al PHP via FormData (CSRF incluso) */
      var tokenEl = document.querySelector('input[name="csrf_token"]');
      var fd = new FormData();
      fd.append('csrf_token', tokenEl ? tokenEl.value : '');
      fd.append('rows', JSON.stringify(rows));

      var resp   = await fetch(IMPORT_URL, { method: 'POST', body: fd });
      var result = await resp.json();
      showImportResult(result);

    } catch (err) {
      showImportResult({ ok: false, error: err.message });
    }
  });

  function setImportStep(step) {
    /* 'success' e 'error' mostrano entrambi il div risultato */
    var isResult = (step === 'result' || step === 'success' || step === 'error');
    document.getElementById('import-step-instructions').classList.toggle('d-none', step !== 'instructions');
    document.getElementById('import-step-processing').classList.toggle('d-none',   step !== 'processing');
    document.getElementById('import-step-result').classList.toggle('d-none',       !isResult);

    /* Footer: nascondi tutto durante elaborazione, poi mostra i pulsanti giusti */
    var footer = document.getElementById('import-modal-footer');
    footer.style.display = (step === 'processing') ? 'none' : '';

    /* Testo bottone close */
    var closeBtn = document.getElementById('import-btn-close');
    if (closeBtn) closeBtn.textContent = (step === 'instructions') ? 'Annulla' : 'Chiudi';

    /* Visibilità pulsanti footer */
    var elNormal = document.getElementById('import-footer-normal');
    var elReload = document.getElementById('import-btn-reload');
    var elRetry  = document.getElementById('import-btn-retry');
    if (elNormal) elNormal.classList.toggle('d-none', step !== 'instructions');
    if (elReload) elReload.classList.toggle('d-none',  step !== 'success');
    if (elRetry)  elRetry.classList.toggle('d-none',   step !== 'error');
  }

  function showImportResult(result) {
    var el = document.getElementById('import-step-result');

    if (result.ok) {
      var html = '<div class="qk-alert qk-alert-success mb-3">'
        + '<i class="fa-solid fa-circle-check"></i>'
        + '<div><strong>' + result.inserted + '</strong> record importati correttamente'
        + (result.skipped > 0
            ? '; <strong>' + result.skipped + '</strong> saltati per dati non validi'
            : '')
        + '.</div></div>';

      if (result.errors && result.errors.length > 0) {
        html += '<div class="qk-alert qk-alert-warning mb-3">'
          + '<i class="fa-solid fa-triangle-exclamation"></i>'
          + '<div>' + result.errors.map(function (e) {
              return '<div class="small">' + e + '</div>';
            }).join('') + '</div></div>';
      }
      html += '<p class="text-muted small">Ricarica la pagina per visualizzare i nuovi record nella lista.</p>';
      el.innerHTML = html;
      setImportStep('success');

    } else {
      el.innerHTML =
        '<div class="qk-alert qk-alert-danger mb-3">'
        + '<i class="fa-solid fa-circle-xmark"></i>'
        + '<span>' + (result.error || 'Errore sconosciuto.') + '</span></div>'
        + '<p class="text-muted small">Controlla il file e riprova.</p>';
      setImportStep('error');
    }
  }

  // ══════════════════════════════════════════════════════════════════════
  //  CLASSIFICAZIONI EXPORT / IMPORT — ExcelJS
  // ══════════════════════════════════════════════════════════════════════

  var CLASSIFICAZIONI_DATA = <?= $classificazioni_json ?>;
  var IMPORT_CLASSIFICAZIONI_URL = <?= json_encode($import_classificazioni_url) ?>;

  var CLASSIF_COL_DEFS = [
    { key: 'codice',              width: 15, note: 'Codice di classificazione (es. F1, TF1, 2A) — OBBLIGATORIO' },
    { key: 'codice_classe',       width: 15, note: 'Classe ADR di appartenenza (es. 1, 2, 3, 4.1) — OBBLIGATORIO' },
    { key: 'pericolo_primario',   width: 25, note: 'Descrizione del pericolo primario (es. Infiammabile)' },
    { key: 'pericolo_secondario', width: 25, note: 'Descrizione del pericolo secondario (es. Tossico)' },
    { key: 'descrizione',         width: 50, note: 'Descrizione estesa o note del codice' },
  ];

  /* ── ESPORTA CLASSIFICAZIONI ───────────────────────────────────────── */
  var btnEsportaClassif = document.getElementById('btn-esporta-classificazioni');
  if (btnEsportaClassif) {
    btnEsportaClassif.addEventListener('click', async function () {
      var btn = this;
      btn.disabled = true;
      btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Esportazione…';

      try {
        var wb = new ExcelJS.Workbook();
        wb.creator = 'Quack ERP';
        wb.created = new Date();

        var ws = wb.addWorksheet('Codici Classificazione');
        ws.columns = CLASSIF_COL_DEFS.map(function (c) { return { key: c.key, width: c.width }; });

        /* Intestazione */
        var hRow = ws.addRow(CLASSIF_COL_DEFS.map(function (c) { return c.key; }));
        hRow.height = 28;
        hRow.eachCell(styleHeader);

        /* Dati */
        CLASSIFICAZIONI_DATA.forEach(function (r) {
          var vals = CLASSIF_COL_DEFS.map(function (c) {
            var v = r[c.key];
            return (v === null || v === undefined || v === '') ? '' : v;
          });
          ws.addRow(vals).eachCell(function (cell) {
            cell.alignment = { vertical: 'middle' };
          });
        });

        /* Filtri automatici */
        ws.autoFilter = {
          from: { row: 1, column: 1 },
          to:   { row: 1, column: CLASSIF_COL_DEFS.length }
        };
        ws.views = [{ state: 'frozen', xSplit: 0, ySplit: 1 }];

        downloadBuffer(
          await wb.xlsx.writeBuffer(),
          'ADR_Classificazioni_' + new Date().toISOString().slice(0, 10) + '.xlsx'
        );
      } catch (err) {
        alert('Errore durante l\'esportazione: ' + err.message);
      } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-file-excel me-1"></i>Esporta Lista';
      }
    });
  }

  /* ── SCARICA TRACCIATO CLASSIFICAZIONI ──────────────────────────────── */
  var btnScaricaTracciatoClassif = document.getElementById('btn-scarica-tracciato-classificazioni');
  if (btnScaricaTracciatoClassif) {
    btnScaricaTracciatoClassif.addEventListener('click', async function () {
      var btn = this;
      btn.disabled = true;
      btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Generazione…';

      try {
        var wb = new ExcelJS.Workbook();
        wb.creator = 'Quack ERP';
        wb.created = new Date();

        /* Foglio 1: ISTRUZIONI */
        var wsI = wb.addWorksheet('1. ISTRUZIONI');
        wsI.getColumn(1).width = 32;
        wsI.getColumn(2).width = 68;

        var rTitle = wsI.addRow(['TRACCIATO IMPORTAZIONE — ADR Classificazioni', '']);
        rTitle.getCell(1).font = { bold: true, size: 14, color: { argb: 'FF' + HEADER_COLOR.slice(2) } };
        wsI.mergeCells('A1:B1');
        wsI.getRow(1).height = 28;

        wsI.addRow(['Quack ERP — generato il ' + new Date().toLocaleDateString('it-IT'), '']);
        wsI.addRow([]);

        var rIstr = wsI.addRow(['ISTRUZIONI', '']);
        rIstr.getCell(1).font = { bold: true, size: 11 };

        [
          ['1.', 'Passa al foglio "2. IMPORTA_DATI"'],
          ['2.', 'Elimina le 3 righe di esempio (sfondo grigio chiaro) prima di caricare il file'],
          ['3.', 'Inserisci i tuoi dati dalla riga 2 (la riga 1 è l\'intestazione — non modificarla)'],
          ['4.', 'Salva il file e caricalo col pulsante "Carica file Excel" nel modale di importazione'],
          ['5.', 'I record con codice o classe mancante o non valida vengono ignorati automaticamente'],
          ['6.', 'I nuovi record vengono aggiunti alla lista esistente (il codice deve essere univoco)'],
        ].forEach(function (row) { wsI.addRow(row); });

        wsI.addRow([]);

        var rCol = wsI.addRow(['COLONNA / CAMPO DB', 'DESCRIZIONE E VALORI AMMESSI']);
        rCol.eachCell(styleHeader);

        CLASSIF_COL_DEFS.forEach(function (c) {
          wsI.addRow([c.key, c.note]);
        });

        /* Foglio 2: IMPORTA_DATI */
        var wsD = wb.addWorksheet('2. IMPORTA_DATI');
        wsD.columns = CLASSIF_COL_DEFS.map(function (c) { return { key: c.key, width: c.width }; });

        var hRow = wsD.addRow(CLASSIF_COL_DEFS.map(function (c) { return c.key; }));
        hRow.height = 28;
        hRow.eachCell(styleHeader);

        /* Righe di esempio (dati reali ADR, sfondo grigio) */
        var examples = [
          ['F1', '3', 'Infiammabile', '', 'Materie liquide infiammabili con punto d\'infiammabilità <= 60°C'],
          ['TF1', '3', 'Tossico', 'Infiammabile', 'Materie liquide infiammabili tossiche'],
          ['2A', '2', 'Asfissiante', '', 'Gas asfissiante'],
        ];
        examples.forEach(function (ex) {
          var exRow = wsD.addRow(ex);
          exRow.eachCell(function (cell) {
            cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: EXAMPLE_COLOR } };
            cell.font = { italic: true, color: { argb: 'FF777777' } };
          });
        });

        wsD.autoFilter = {
          from: { row: 1, column: 1 },
          to:   { row: 1, column: CLASSIF_COL_DEFS.length }
        };
        wsD.views = [{ state: 'frozen', xSplit: 0, ySplit: 1 }];

        downloadBuffer(await wb.xlsx.writeBuffer(), 'ADR_Classificazioni_Tracciato.xlsx');
      } catch (err) {
        alert('Errore durante la generazione del tracciato: ' + err.message);
      } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-file-arrow-down me-1"></i>Scarica Tracciato';
      }
    });
  }

  /* ── IMPORTAZIONE FILE CLASSIFICAZIONI ──────────────────────────────── */
  var modalImportClassif = document.getElementById('modal-import-classificazioni');
  if (modalImportClassif) {
    modalImportClassif.addEventListener('show.bs.modal', function () {
      setImportClassifStep('instructions');
      document.getElementById('import-classif-file-input').value = '';
    });
  }

  var btnRetryImportClassif = document.getElementById('import-classif-btn-retry');
  if (btnRetryImportClassif) {
    btnRetryImportClassif.addEventListener('click', function () {
      setImportClassifStep('instructions');
      document.getElementById('import-classif-file-input').value = '';
    });
  }

  var fileInputClassif = document.getElementById('import-classif-file-input');
  if (fileInputClassif) {
    fileInputClassif.addEventListener('change', async function () {
      var file = this.files[0];
      if (!file) return;

      setImportClassifStep('processing');

      try {
        var buf = await file.arrayBuffer();
        var wb  = new ExcelJS.Workbook();
        await wb.xlsx.load(buf);

        var ws = wb.getWorksheet('2. IMPORTA_DATI') || wb.worksheets[0];
        if (!ws) {
          throw new Error('Impossibile trovare il foglio dati nel file Excel.');
        }

        /* Leggi l'intestazione dalla riga 1 */
        var headers = [];
        ws.getRow(1).eachCell({ includeEmpty: true }, function (cell) {
          headers.push(((cell.value !== null && cell.value !== undefined) ? String(cell.value) : '').trim());
        });

        /* Mappa chiave → indice colonna (0-based) */
        var keyMap = {};
        CLASSIF_COL_DEFS.forEach(function (c) {
          var idx = headers.indexOf(c.key);
          if (idx >= 0) keyMap[c.key] = idx;
        });

        if (keyMap['codice'] === undefined) {
          throw new Error(
            'Intestazione "codice" non trovata (riga 1). ' +
            'Assicurati di usare il tracciato originale senza modificare la riga 1.'
          );
        }

        /* Leggi le righe dati (salta riga 1 = intestazione) */
        var rows = [];
        ws.eachRow(function (row, rowNum) {
          if (rowNum === 1) return;
          var obj = {};
          var hasData = false;

          CLASSIF_COL_DEFS.forEach(function (c) {
            var cellVal = '';
            if (keyMap[c.key] !== undefined) {
              var cell = row.getCell(keyMap[c.key] + 1);
              var v = cell.value;
              if (v === null || v === undefined) {
                cellVal = '';
              } else if (typeof v === 'object' && v !== null) {
                cellVal = (v.text || v.result || '').toString().trim();
              } else {
                cellVal = v.toString().trim();
              }
            }
            obj[c.key] = cellVal;
            if (c.key === 'codice' && cellVal !== '') hasData = true;
          });

          if (hasData) rows.push(obj);
        });

        if (rows.length === 0) {
          throw new Error(
            'Il file non contiene righe di dati valide. ' +
            'Ricorda di eliminare le righe di esempio prima del caricamento.'
          );
        }

        /* Invia al PHP via FormData (CSRF incluso) */
        var tokenEl = document.querySelector('input[name="csrf_token"]');
        var fd = new FormData();
        fd.append('csrf_token', tokenEl ? tokenEl.value : '');
        fd.append('rows', JSON.stringify(rows));

        var resp   = await fetch(IMPORT_CLASSIFICAZIONI_URL, { method: 'POST', body: fd });
        var result = await resp.json();
        showImportClassifResult(result);

      } catch (err) {
        showImportClassifResult({ ok: false, error: err.message });
      }
    });
  }

  function setImportClassifStep(step) {
    var isResult = (step === 'result' || step === 'success' || step === 'error');
    document.getElementById('import-classif-step-instructions').classList.toggle('d-none', step !== 'instructions');
    document.getElementById('import-classif-step-processing').classList.toggle('d-none',   step !== 'processing');
    document.getElementById('import-classif-step-result').classList.toggle('d-none',       !isResult);

    var footer = document.getElementById('import-classif-modal-footer');
    footer.style.display = (step === 'processing') ? 'none' : '';

    var closeBtn = document.getElementById('import-classif-btn-close');
    if (closeBtn) closeBtn.textContent = (step === 'instructions') ? 'Annulla' : 'Chiudi';

    var elNormal = document.getElementById('import-classif-footer-normal');
    var elReload = document.getElementById('import-classif-btn-reload');
    var elRetry  = document.getElementById('import-classif-btn-retry');
    if (elNormal) elNormal.classList.toggle('d-none', step !== 'instructions');
    if (elReload) elReload.classList.toggle('d-none',  step !== 'success');
    if (elRetry)  elRetry.classList.toggle('d-none',   step !== 'error');
  }

  function showImportClassifResult(result) {
    var el = document.getElementById('import-classif-step-result');

    if (result.ok) {
      var html = '<div class="qk-alert qk-alert-success mb-3">'
        + '<i class="fa-solid fa-circle-check"></i>'
        + '<div><strong>' + result.inserted + '</strong> record importati correttamente'
        + (result.skipped > 0
            ? '; <strong>' + result.skipped + '</strong> saltati per dati non validi'
            : '')
        + '.</div></div>';

      if (result.errors && result.errors.length > 0) {
        html += '<div class="qk-alert qk-alert-warning mb-3">'
          + '<i class="fa-solid fa-triangle-exclamation"></i>'
          + '<div>' + result.errors.map(function (e) {
              return '<div class="small">' + e + '</div>';
            }).join('') + '</div></div>';
      }
      html += '<p class="text-muted small">Ricarica la pagina per visualizzare i nuovi record nella lista.</p>';
      el.innerHTML = html;
      setImportClassifStep('success');

    } else {
      el.innerHTML =
        '<div class="qk-alert qk-alert-danger mb-3">'
        + '<i class="fa-solid fa-circle-xmark"></i>'
        + '<span>' + (result.error || 'Errore sconosciuto.') + '</span></div>'
        + '<p class="text-muted small">Controlla il file e riprova.</p>';
      setImportClassifStep('error');
    }
  }
})();