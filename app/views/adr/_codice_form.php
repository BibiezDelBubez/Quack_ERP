<?php
/**
 * adr/_codice_form.php — Form creazione/modifica adr_codici_onu
 *
 * Variabili attese:
 *   $mode      string   'create'|'edit'
 *   $entity_id ?int     ID record (solo in edit)
 *   $values    array    Valori correnti del form
 *   $errors    array    Errori di validazione
 *   $lookup    array    Lookup tables: classi, classificazioni, imballaggi, categorie,
 *                       gallerie, quantita_esenti, unita_misura
 */

$action_url = $mode === 'edit'
    ? url('adr/codici/edit?id=' . (int)$entity_id)
    : url('adr/codici/create');

$v   = fn(string $k): string => (string) ($values[$k] ?? '');

/* URL API lookup */
$apiLookup = url('adr/api/lookup');

/* Label corrente per i campi M2O — calcolate dai lookup già caricati */
$lbl = function (string $field, array $list, string $idCol, string $labelCol, string $subCol = '') use ($values): array {
    $val = (string)($values[$field] ?? '');
    if ($val === '') return ['', ''];
    foreach ($list as $r) {
        if ((string)$r[$idCol] === $val) {
            return [$r[$labelCol] ?? $val, $subCol ? ($r[$subCol] ?? '') : ''];
        }
    }
    return [$val, ''];
};

[$lbl_classe,   $sub_classe]   = $lbl('classe_pericolo',            $lookup['classi'],          'codice',    'codice',    'denominazione');
[$lbl_classif,  $sub_classif]  = $lbl('codice_classificazione',     $lookup['classificazioni'], 'codice',    'codice',    'descrizione');
[$lbl_imb,      $sub_imb]      = $lbl('gruppo_imballaggio',         $lookup['imballaggi'],      'codice',    'codice',    'descrizione');
[$lbl_cat,      $sub_cat]      = $lbl('categoria_trasporto',        $lookup['categorie'],       'categoria', 'categoria', 'descrizione');
[$lbl_gall,     $sub_gall]     = $lbl('codice_restrizione_gallerie',$lookup['gallerie'],        'codice',    'codice',    'descrizione');
[$lbl_qe,       $sub_qe]       = $lbl('codice_quantita_esente',     $lookup['quantita_esenti'], 'codice',    'codice',    'descrizione');
?>

<?php if (!empty($errors['_global'])): ?>
<div class="qk-alert qk-alert-danger mb-4">
  <i class="fa-solid fa-circle-xmark"></i>
  <span><?= e($errors['_global']) ?></span>
</div>
<?php endif; ?>

<form method="post" action="<?= e($action_url) ?>" novalidate data-qk-form>
  <?= csrf_field() ?>

  <div class="qk-form-grid">

    <!-- ══════════════════════════════════════════════════════════════
         COLONNA PRINCIPALE
    ══════════════════════════════════════════════════════════════════ -->
    <div>

      <!-- ── Identificazione ───────────────────────────────────────── -->
      <div class="qk-card mb-3">
        <div class="qk-card-header">
          <span class="qk-card-title"><i class="fa-solid fa-fingerprint fa-fw me-1"></i>Identificazione</span>
        </div>
        <div class="qk-card-body">

          <div class="qk-form-row">

            <!-- Numero ONU -->
            <div class="qk-form-group" style="flex:0 0 160px">
              <label class="qk-form-label">
                Numero ONU <span class="required">*</span>
              </label>
              <div class="input-group">
                <span class="input-group-text fw-semibold" style="font-size:.85rem">UN</span>
                <input type="text" name="codice_onu" inputmode="numeric" pattern="[0-9]{1,4}"
                       class="qk-form-control qk-font-mono <?= isset($errors['codice_onu']) ? 'is-invalid' : '' ?>"
                       value="<?= e($v('codice_onu')) ?>"
                       placeholder="1203" maxlength="4" required
                       style="-moz-appearance:textfield;appearance:textfield">
              </div>
              <?php if (isset($errors['codice_onu'])): ?>
              <span class="qk-form-error"><i class="fa-solid fa-circle-xmark"></i> <?= e($errors['codice_onu']) ?></span>
              <?php endif; ?>
              <span class="qk-form-hint">Stesso UN# può avere più righe.</span>
            </div>

            <!-- Nome e descrizione -->
            <div class="qk-form-group">
              <label class="qk-form-label">Nome e descrizione <span class="qk-form-hint">(denominazione ufficiale — col. 2 Tab. A)</span></label>
              <input type="text" name="nome_descrizione"
                     class="qk-form-control"
                     value="<?= e($v('nome_descrizione')) ?>"
                     placeholder="es. BENZINA, GASOLIO PER AUTOTRAZIONE…"
                     maxlength="500">
            </div>

          </div>

          <div class="qk-form-row">

            <!-- Classe di pericolo — QkM2O -->
            <div class="qk-form-group">
              <label class="qk-form-label">Classe di pericolo</label>
              <input type="hidden" name="classe_pericolo" id="fld-classe-id" value="<?= e($v('classe_pericolo')) ?>">
              <div class="qk-m2o-wrap">
                <div class="qk-m2o-input-row">
                  <input type="text" id="fld-classe-label" class="qk-m2o-input"
                         autocomplete="off" placeholder="Cerca classe…"
                         value="<?= e($lbl_classe . ($sub_classe ? ' — ' . mb_strimwidth($sub_classe, 0, 40, '…') : '')) ?>">
                  <button type="button" class="qk-m2o-clear <?= $v('classe_pericolo') ? '' : 'd-none' ?>"
                          id="fld-classe-clear"><i class="fa-solid fa-xmark fa-xs"></i></button>
                </div>
                <div class="qk-m2o-dropdown" id="fld-classe-dd"></div>
              </div>
            </div>

            <!-- Codice classificazione — QkM2O -->
            <div class="qk-form-group">
              <label class="qk-form-label">Codice classificazione</label>
              <input type="hidden" name="codice_classificazione" id="fld-classif-id" value="<?= e($v('codice_classificazione')) ?>">
              <div class="qk-m2o-wrap">
                <div class="qk-m2o-input-row">
                  <input type="text" id="fld-classif-label" class="qk-m2o-input"
                         autocomplete="off" placeholder="Cerca codice…"
                         value="<?= e($lbl_classif . ($sub_classif ? ' — ' . mb_strimwidth($sub_classif, 0, 40, '…') : '')) ?>">
                  <button type="button" class="qk-m2o-clear <?= $v('codice_classificazione') ? '' : 'd-none' ?>"
                          id="fld-classif-clear"><i class="fa-solid fa-xmark fa-xs"></i></button>
                </div>
                <div class="qk-m2o-dropdown" id="fld-classif-dd"></div>
              </div>
            </div>

            <!-- Etichette di pericolo -->
            <div class="qk-form-group">
              <label class="qk-form-label">Etichette di pericolo <span class="qk-form-hint">(separate da virgola, es. 3, 8, 6.1)</span></label>
              <input type="text" name="etichette_str"
                     class="qk-form-control"
                     value="<?= e($v('etichette_str')) ?>"
                     placeholder="3, 8, 6.1">
            </div>

          </div>
        </div>
      </div>

      <!-- ── Imballaggio e trasporto ────────────────────────────────── -->
      <div class="qk-card mb-3">
        <div class="qk-card-header">
          <span class="qk-card-title"><i class="fa-solid fa-truck fa-fw me-1"></i>Imballaggio e trasporto</span>
        </div>
        <div class="qk-card-body">
          <div class="qk-form-row">

            <!-- Gruppo imballaggio — QkM2O -->
            <div class="qk-form-group">
              <label class="qk-form-label">Gruppo imballaggio</label>
              <input type="hidden" name="gruppo_imballaggio" id="fld-imb-id" value="<?= e($v('gruppo_imballaggio')) ?>">
              <div class="qk-m2o-wrap">
                <div class="qk-m2o-input-row">
                  <input type="text" id="fld-imb-label" class="qk-m2o-input"
                         autocomplete="off" placeholder="Cerca gruppo…"
                         value="<?= e($lbl_imb . ($sub_imb ? ' — ' . $sub_imb : '')) ?>">
                  <button type="button" class="qk-m2o-clear <?= $v('gruppo_imballaggio') ? '' : 'd-none' ?>"
                          id="fld-imb-clear"><i class="fa-solid fa-xmark fa-xs"></i></button>
                </div>
                <div class="qk-m2o-dropdown" id="fld-imb-dd"></div>
              </div>
            </div>

            <!-- Categoria trasporto — QkM2O -->
            <div class="qk-form-group">
              <label class="qk-form-label">Categoria di trasporto</label>
              <input type="hidden" name="categoria_trasporto" id="fld-cat-id" value="<?= e($v('categoria_trasporto')) ?>">
              <div class="qk-m2o-wrap">
                <div class="qk-m2o-input-row">
                  <input type="text" id="fld-cat-label" class="qk-m2o-input"
                         autocomplete="off" placeholder="Cerca categoria…"
                         value="<?= e($lbl_cat . ($sub_cat ? ' — ' . mb_strimwidth($sub_cat, 0, 50, '…') : '')) ?>">
                  <button type="button" class="qk-m2o-clear <?= $v('categoria_trasporto') ? '' : 'd-none' ?>"
                          id="fld-cat-clear"><i class="fa-solid fa-xmark fa-xs"></i></button>
                </div>
                <div class="qk-m2o-dropdown" id="fld-cat-dd"></div>
              </div>
            </div>

            <!-- Codice galleria — QkM2O -->
            <div class="qk-form-group">
              <label class="qk-form-label">Codice restrizione gallerie</label>
              <input type="hidden" name="codice_restrizione_gallerie" id="fld-gall-id" value="<?= e($v('codice_restrizione_gallerie')) ?>">
              <div class="qk-m2o-wrap">
                <div class="qk-m2o-input-row">
                  <input type="text" id="fld-gall-label" class="qk-m2o-input"
                         autocomplete="off" placeholder="Cerca codice…"
                         value="<?= e($lbl_gall . ($sub_gall ? ' — ' . mb_strimwidth($sub_gall, 0, 40, '…') : '')) ?>">
                  <button type="button" class="qk-m2o-clear <?= $v('codice_restrizione_gallerie') ? '' : 'd-none' ?>"
                          id="fld-gall-clear"><i class="fa-solid fa-xmark fa-xs"></i></button>
                </div>
                <div class="qk-m2o-dropdown" id="fld-gall-dd"></div>
              </div>
            </div>

          </div>
        </div>
      </div>

      <!-- ── Quantità limitata ed esente ───────────────────────────── -->
      <div class="qk-card mb-3">
        <div class="qk-card-header">
          <span class="qk-card-title"><i class="fa-solid fa-scale-balanced fa-fw me-1"></i>Quantità limitata ed esente</span>
        </div>
        <div class="qk-card-body">
          <div class="qk-form-row">

            <!-- Quantità limitata + UM -->
            <div class="qk-form-group">
              <label class="qk-form-label">
                Quantità limitata <span class="qk-form-hint">(col. 7a)</span>
              </label>
              <input type="text" name="quantita_limitata" inputmode="numeric" pattern="[0-9]*"
                     class="qk-form-control"
                     value="<?= e($v('quantita_limitata')) ?>"
                     placeholder="es. 1000"
                     style="-moz-appearance:textfield;appearance:textfield">
              <span class="qk-form-hint">Quantità massima per imballaggio interno in regime QL.</span>
            </div>

            <!-- Codice quantità esente — QkM2O -->
            <div class="qk-form-group">
              <label class="qk-form-label">
                Codice quantità esente <span class="qk-form-hint">(col. 7b)</span>
              </label>
              <input type="hidden" name="codice_quantita_esente" id="fld-qe-id" value="<?= e($v('codice_quantita_esente')) ?>">
              <div class="qk-m2o-wrap">
                <div class="qk-m2o-input-row">
                  <input type="text" id="fld-qe-label" class="qk-m2o-input"
                         autocomplete="off" placeholder="Cerca codice E…"
                         value="<?= e($lbl_qe . ($sub_qe ? ' — ' . mb_strimwidth($sub_qe, 0, 40, '…') : '')) ?>">
                  <button type="button" class="qk-m2o-clear <?= $v('codice_quantita_esente') ? '' : 'd-none' ?>"
                          id="fld-qe-clear"><i class="fa-solid fa-xmark fa-xs"></i></button>
                </div>
                <div class="qk-m2o-dropdown" id="fld-qe-dd"></div>
              </div>
            </div>

          </div>
        </div>
      </div>

      <!-- ── Flag pericolosità ──────────────────────────────────────── -->
      <div class="qk-card mb-3">
        <div class="qk-card-header">
          <span class="qk-card-title"><i class="fa-solid fa-flag fa-fw me-1"></i>Flag pericolosità</span>
        </div>
        <div class="qk-card-body">
          <div class="qk-form-row">
            <div class="qk-form-group">
              <label class="d-flex align-items-center gap-2" style="cursor:pointer;font-size:.875rem">
                <input type="checkbox" name="e_merce_alto_rischio" value="1"
                       <?= ($v('e_merce_alto_rischio') === '1') ? 'checked' : '' ?>>
                <i class="fa-solid fa-skull-crossbones text-danger"></i>
                Merce alto rischio
              </label>
            </div>
            <div class="qk-form-group">
              <label class="d-flex align-items-center gap-2" style="cursor:pointer;font-size:.875rem">
                <input type="checkbox" name="e_inquinante_marino" value="1"
                       <?= ($v('e_inquinante_marino') === '1') ? 'checked' : '' ?>>
                <i class="fa-solid fa-fish-fins" style="color:var(--qk-info)"></i>
                Inquinante marino
              </label>
            </div>
          </div>
        </div>
      </div>

      <!-- ── Validità ───────────────────────────────────────────────── -->
      <div class="qk-card mb-3">
        <div class="qk-card-header">
          <span class="qk-card-title"><i class="fa-solid fa-calendar-range fa-fw me-1"></i>Validità</span>
        </div>
        <div class="qk-card-body">
          <div class="qk-form-row">
            <div class="qk-form-group">
              <label class="qk-form-label">Valido dal</label>
              <input type="date" name="inizio_validita" class="qk-form-control"
                     value="<?= e($v('inizio_validita')) ?>">
            </div>
            <div class="qk-form-group">
              <label class="qk-form-label">Valido fino al</label>
              <input type="date" name="fine_validita" class="qk-form-control"
                     value="<?= e($v('fine_validita')) ?>">
              <span class="qk-form-hint">Lascia vuoto se la validità è indeterminata.</span>
            </div>
          </div>
        </div>
      </div>

    </div><!-- /colonna principale -->

    <!-- ══════════════════════ PANNELLO LATERALE ══════════════════════ -->
    <div class="qk-status-panel">
      <div class="qk-status-panel-hd">Azioni</div>
      <div class="qk-status-panel-body">
        <div class="qk-status-panel-actions">
          <button type="submit" class="btn-qk btn-qk-primary">
            <i class="fa-solid fa-floppy-disk"></i>
            <?= $mode === 'edit' ? 'Salva modifiche' : 'Crea codice ONU' ?>
          </button>
          <a href="<?= url('adr?tab=codici') ?>" class="btn-qk btn-qk-secondary">
            <i class="fa-solid fa-arrow-left me-1"></i>Torna
          </a>
        </div>
      </div>
    </div>

  </div><!-- /qk-form-grid -->
</form>


<!-- ══════════════════════ JS — QkM2O per tutti i campi ADR ══════════════ -->
<script>
/* QkM2O è definito in entity_picker_modal.php (footer) — init rimandato */
document.addEventListener('DOMContentLoaded', function () {

  if (typeof QkM2O === 'undefined') return;

  var BASE = <?= json_encode($apiLookup) ?>;

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
</script>
