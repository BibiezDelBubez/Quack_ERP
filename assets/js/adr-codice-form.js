/* QkM2O è definito in entity_picker_modal.php (footer) — init rimandato */
document.addEventListener('DOMContentLoaded', function () {

  if (typeof QkM2O === 'undefined') return;

  if (!window.QkAdrCodiceConfig || !window.QkAdrCodiceConfig.apiLookupUrl) {
    console.error("QkAdrCodiceConfig.apiLookupUrl non definita.");
    return;
  }

  var BASE = window.QkAdrCodiceConfig.apiLookupUrl;

  /* Helper: costruisce URL lookup */
  function apiUrl(type) { return BASE + '?type=' + type; }

  /* Helper: testo label per display iniziale — già precompilato in PHP,
     il campo di testo mostra già la stringa; QkM2O la userà come valore corrente. */

  /* Helper: crea picker completo stile Odoo */
  function makePicker(type, title, cols, onSel) {
    return new QkPicker({
      title:   title,
      apiUrl:  apiUrl(type),
      columns: cols,
      onSelect: onSel,
    });
  }

  /* Registra un QkM2O per un campo */
  function reg(opts) {
    /* opts: { type, title, pickerCols,
               inputId, hiddenId, clearId, ddId,
               labelKey, cols } */
    var inp    = document.getElementById(opts.inputId);
    var hid    = document.getElementById(opts.hiddenId);
    var clr    = document.getElementById(opts.clearId);
    var dd     = document.getElementById(opts.ddId);
    if (!inp || !hid || !dd) return;

    var m2o;
    var picker = makePicker(opts.type, opts.title, opts.pickerCols, function (id, row) {
      var disp = row.label + (row.sub ? ' — ' + row.sub : '');
      m2o.setValue(id, disp);
    });

    m2o = new QkM2O({
      inputEl:    inp,
      hiddenEl:   hid,
      clearBtn:   clr,
      dropdownEl: dd,
      apiUrl:     apiUrl(opts.type),
      labelKey:   'label',
      columns:    [{ key: 'label', label: 'Codice' }, { key: 'sub', label: 'Descrizione' }],
      picker:     picker,
      onSelect:   function (id, row) {
        /* aggiorna display con label + sub */
        var disp = row.label + (row.sub ? ' — ' + row.sub : '');
        inp.value = disp;
      },
    });
  }

  var COLS2 = [{ key: 'label', label: 'Codice' }, { key: 'sub', label: 'Descrizione' }];

  reg({ type: 'classi',          title: 'Classe di pericolo',          pickerCols: COLS2, inputId: 'fld-classe-label',  hiddenId: 'fld-classe-id',  clearId: 'fld-classe-clear',  ddId: 'fld-classe-dd'  });
  reg({ type: 'classificazioni', title: 'Codice classificazione',       pickerCols: COLS2, inputId: 'fld-classif-label', hiddenId: 'fld-classif-id', clearId: 'fld-classif-clear', ddId: 'fld-classif-dd' });
  reg({ type: 'imballaggi',      title: 'Gruppo imballaggio',           pickerCols: COLS2, inputId: 'fld-imb-label',    hiddenId: 'fld-imb-id',    clearId: 'fld-imb-clear',    ddId: 'fld-imb-dd'    });
  reg({ type: 'categorie',       title: 'Categoria di trasporto',       pickerCols: COLS2, inputId: 'fld-cat-label',    hiddenId: 'fld-cat-id',    clearId: 'fld-cat-clear',    ddId: 'fld-cat-dd'    });
  reg({ type: 'gallerie',        title: 'Codice restrizione gallerie',  pickerCols: COLS2, inputId: 'fld-gall-label',   hiddenId: 'fld-gall-id',   clearId: 'fld-gall-clear',   ddId: 'fld-gall-dd'   });
  reg({ type: 'quantita_esenti', title: 'Codice quantità esente',       pickerCols: COLS2, inputId: 'fld-qe-label',    hiddenId: 'fld-qe-id',    clearId: 'fld-qe-clear',    ddId: 'fld-qe-dd'    });

});
