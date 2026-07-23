<?php
/**
 * @var string $mode                 'create' | 'edit'
 * @var array  $v                    valori del form (campi sostanza)
 * @var array  $errors               errori di validazione
 * @var array  $codici_onu           lista adr_codici_onu
 * @var array  $clp_data             ['h_statements'=>[], 'p_statements'=>[], 'pittogrammi'=>[]]
 * @var array  $prodotti_associati   prodotti SKU collegati (solo in edit)
 * @var array  $h_selezionati        codici H già assegnati (solo in edit)
 * @var array  $p_selezionati        codici P già assegnati (solo in edit)
 * @var array  $pitt_selezionati     codici pittogramma già assegnati (solo in edit)
 */
$isEdit     = $mode === 'edit';
$sostanzaId = $isEdit ? (int)($v['id'] ?? 0) : 0;
$csrf       = csrf_field();
$h_selezionati    = $h_selezionati    ?? [];
$p_selezionati    = $p_selezionati    ?? [];
$pitt_selezionati = $pitt_selezionati ?? [];
?>

<?php if (!empty($errors['_global'])): ?>
<div class="qk-alert qk-alert-error mb-3"><?= e($errors['_global']) ?></div>
<?php endif; ?>

<ul class="nav nav-tabs mb-3" role="tablist">
  <li class="nav-item">
    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#stab-dati" role="tab">
      <i class="fa-solid fa-flask-vial me-1"></i> Dati sostanza
    </button>
  </li>
  <li class="nav-item">
    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#stab-clp" role="tab">
      <i class="fa-solid fa-biohazard me-1"></i> CLP / GHS
      <?php $nH = $isEdit ? count($h_selezionati) : 0; $nPitt = $isEdit ? count($pitt_selezionati) : 0;
        if ($nH || $nPitt): ?><span class="badge bg-secondary ms-1"><?= $nH ?>H</span><?php endif; ?>
    </button>
  </li>
  <?php if ($isEdit): ?>

  <li class="nav-item">
    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#stab-prodotti" role="tab">
      <i class="fa-solid fa-boxes-stacked me-1"></i> Prodotti Associati
      <span class="badge bg-secondary ms-1 <?= empty($prodotti_associati) ? 'd-none' : '' ?>" id="badge-prodotti-count"><?= count($prodotti_associati) ?></span>
    </button>
  </li>
  <?php endif; ?>
</ul>

<div class="tab-content">

  <!-- TAB 1: DATI SOSTANZA -->
  <div class="tab-pane fade show active" id="stab-dati" role="tabpanel">
    <form method="post" id="form-sostanza"
          action="<?= url($isEdit ? 'adr/sostanze/edit?id=' . $sostanzaId : 'adr/sostanze/create') ?>">
      <?= $csrf ?>

      <div class="qk-card mb-3">
        <div class="qk-card-header"><span class="qk-card-title">Identificazione</span></div>
        <div class="qk-card-body">
          <div class="qk-form-row">
            <div class="qk-form-group" style="flex:2">
              <label class="qk-form-label required">Nome sostanza</label>
              <input type="text" name="nome"
                     class="qk-form-control <?= isset($errors['nome']) ? 'is-invalid' : '' ?>"
                     value="<?= e($v['nome']) ?>" placeholder="Es. IPOCLORITO DI SODIO 14%">
              <?php if (isset($errors['nome'])): ?><div class="invalid-feedback"><?= e($errors['nome']) ?></div><?php endif; ?>
            </div>
            <div class="qk-form-group" style="flex:2">
              <label class="qk-form-label">Nome tecnico IUPAC</label>
              <input type="text" name="nome_tecnico" class="qk-form-control"
                     value="<?= e($v['nome_tecnico'] ?? '') ?>" placeholder="Es. Sodium hypochlorite">
            </div>
          </div>
          <div class="qk-form-row">
            <div class="qk-form-group">
              <label class="qk-form-label">Codice CAS</label>
              <input type="text" name="codice_cas"
                     class="qk-form-control <?= isset($errors['codice_cas']) ? 'is-invalid' : '' ?>"
                     value="<?= e($v['codice_cas'] ?? '') ?>" placeholder="Es. 7647-01-0" pattern="^\d{2,7}-\d{2}-\d$">
              <?php if (isset($errors['codice_cas'])): ?><div class="invalid-feedback"><?= e($errors['codice_cas']) ?></div><?php endif; ?>
            </div>
            <div class="qk-form-group">
              <label class="qk-form-label">EINECS / ELINCS</label>
              <input type="text" name="codice_einecs" class="qk-form-control"
                     value="<?= e($v['codice_einecs'] ?? '') ?>" placeholder="Es. 231-668-3">
            </div>
            <div class="qk-form-group">
              <label class="qk-form-label">REACH Reg. Number</label>
              <input type="text" name="codice_reach" class="qk-form-control"
                     value="<?= e($v['codice_reach'] ?? '') ?>" placeholder="Es. 01-2119458197-...">
            </div>
            <div class="qk-form-group">
              <label class="qk-form-label">UFI <a href="https://echa.europa.eu/ufi" target="_blank" tabindex="-1" class="small qk-text-muted ms-1" title="Unique Formula Identifier"><i class="fa-solid fa-circle-question"></i></a></label>
              <input type="text" name="codice_ufi" class="qk-form-control"
                     value="<?= e($v['codice_ufi'] ?? '') ?>" placeholder="Es. UFI: AB12-CD34-EF56-GH78">
            </div>
          </div>
        </div>
      </div>

      <div class="qk-card mb-3">
        <div class="qk-card-header"><span class="qk-card-title">Classificazione ADR</span></div>
        <div class="qk-card-body">
          <div class="qk-form-row">
            <div class="qk-form-group" style="flex:3">
              <label class="qk-form-label">Codice ONU (N° UN)</label>
              <input type="hidden" name="codice_onu_id" id="codice-onu-id" value="<?= e($v['codice_onu_id'] ?? '') ?>"
                     data-check-url="<?= url('adr/sostanze/api/check-onu') ?>"
                     data-exclude-id="<?= $sostanzaId ?>">
              <div class="qk-m2o-wrap" style="position:relative;">
                <?php
                $onuLabel = '';
                if (!empty($v['codice_onu'])) {
                    $onuLabel = 'UN' . $v['codice_onu'] . ' — ' . ($v['codice_onu_desc'] ?? '');
                    if (!empty($v['codice_onu_classe'])) {
                        $onuLabel .= ' (cl. ' . $v['codice_onu_classe'] . ')';
                    }
                }
                ?>
                <input type="text"
                       class="qk-form-control qk-m2o-input"
                       id="codice-onu-label"
                       placeholder="Cerca per codice ONU (es. 1203) o descrizione…"
                       value="<?= e($onuLabel) ?>"
                       autocomplete="off">
                <button type="button" class="qk-m2o-clear <?= empty($v['codice_onu_id']) ? 'd-none' : '' ?>" id="codice-onu-clear">&times;</button>
                <div class="qk-m2o-dropdown shadow-sm" id="codice-onu-dropdown" style="display:none; position:absolute; z-index:1050; background:var(--qk-card-bg); border:1px solid var(--qk-border); width:100%; max-height:200px; overflow-y:auto;"></div>
              </div>
              <div id="onu-dedup-banner" style="display:none" class="mt-2 p-2 rounded small"></div>
            </div>
            <div class="qk-form-group" style="flex:1">
              <label class="qk-form-label">Gruppo imballaggio</label>
              <select name="gruppo_imballaggio" class="qk-form-control">
                <option value="">— —</option>
                <option value="I"   <?= ($v['gruppo_imballaggio'] ?? '') === 'I'   ? 'selected' : '' ?>>I — Alta pericolosità</option>
                <option value="II"  <?= ($v['gruppo_imballaggio'] ?? '') === 'II'  ? 'selected' : '' ?>>II — Media</option>
                <option value="III" <?= ($v['gruppo_imballaggio'] ?? '') === 'III' ? 'selected' : '' ?>>III — Bassa</option>
              </select>
            </div>
            <div class="qk-form-group" style="flex:1">
              <label class="qk-form-label">N° Kemler <span class="small qk-text-muted">(pannello arancio)</span></label>
              <input type="text" name="numero_kemler" class="qk-form-control"
                     value="<?= e($v['numero_kemler'] ?? '') ?>" placeholder="Es. 80, 33, 268" maxlength="4">
            </div>
          </div>
        </div>
      </div>

      <div class="qk-card mb-3">
        <div class="qk-card-header"><span class="qk-card-title">Caratteristiche fisico-chimiche</span></div>
        <div class="qk-card-body">
          <div class="qk-form-row">
            <div class="qk-form-group">
              <label class="qk-form-label">Stato fisico</label>
              <select name="stato_fisico" class="qk-form-control">
                <option value="">— —</option>
                <?php foreach (['solido','liquido','gas','aerosol','pasta'] as $sf): ?>
                <option value="<?= $sf ?>" <?= ($v['stato_fisico'] ?? '') === $sf ? 'selected' : '' ?>><?= ucfirst($sf) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="qk-form-group">
              <label class="qk-form-label">Conc. minima (%) <i class="fa-solid fa-circle-info ms-1 text-muted" data-bs-toggle="tooltip" title="Concentrazione fisica o purezza reale MINIMA del prodotto commerciale (es. 37 per acido cloridrico)." style="cursor:help;"></i></label>
              <input type="text" name="concentrazione_min_pct" class="qk-form-control"
                     value="<?= e($v['concentrazione_min_pct'] ?? '') ?>" placeholder="Es. 10">
            </div>
            <div class="qk-form-group">
              <label class="qk-form-label">Conc. massima (%) <i class="fa-solid fa-circle-info ms-1 text-muted" data-bs-toggle="tooltip" title="Concentrazione fisica o purezza reale MASSIMA del prodotto commerciale." style="cursor:help;"></i></label>
              <input type="text" name="concentrazione_max_pct" class="qk-form-control"
                     value="<?= e($v['concentrazione_max_pct'] ?? '') ?>" placeholder="Es. 37">
            </div>
            <div class="qk-form-group">
              <label class="qk-form-label">Punto infiammabilità (°C)</label>
              <input type="text" name="punto_infiammabilita_c" class="qk-form-control"
                     value="<?= e($v['punto_infiammabilita_c'] ?? '') ?>" placeholder="Es. 21">
            </div>
          </div>
          <div class="qk-form-row mt-2">
            <div class="qk-form-group">
              <div class="form-check">
                <input type="checkbox" class="form-check-input" name="e_soluzione" id="e_soluzione" value="1"
                       <?= ($v['e_soluzione'] ?? '0') === '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="e_soluzione">Soluzione acquosa</label>
              </div>
            </div>
            <div class="qk-form-group">
              <div class="form-check">
                <input type="checkbox" class="form-check-input" name="e_rifiuto" id="e_rifiuto" value="1"
                       <?= ($v['e_rifiuto'] ?? '0') === '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="e_rifiuto">Rifiuto pericoloso</label>
              </div>
            </div>
            <div class="qk-form-group">
              <div class="form-check">
                <input type="checkbox" class="form-check-input" name="e_stabilizzato" id="e_stabilizzato" value="1"
                       <?= ($v['e_stabilizzato'] ?? '0') === '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="e_stabilizzato">Stabilizzato</label>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="qk-card mb-3">
        <div class="qk-card-header"><span class="qk-card-title">Note interne</span></div>
        <div class="qk-card-body">
          <textarea name="note_interne" class="qk-form-control" rows="3"
                    placeholder="Note di uso interno"><?= e($v['note_interne'] ?? '') ?></textarea>
        </div>
      </div>

      <div class="d-flex gap-2 mt-3">
        <button type="submit" class="btn-qk btn-qk-primary">
          <i class="fa-solid fa-floppy-disk me-1"></i> <?= $isEdit ? 'Salva modifiche' : 'Crea sostanza' ?>
        </button>
        <a href="<?= url('adr/sostanze') ?>" class="btn-qk btn-qk-secondary">Annulla</a>
        <?php if ($isEdit): ?>
        <div class="ms-auto">
          <form method="post" action="<?= url('adr/sostanze/delete') ?>"
                onsubmit="return confirm('Eliminare questa sostanza? L\'operazione è irreversibile.')">
            <?= $csrf ?>
            <input type="hidden" name="id" value="<?= $sostanzaId ?>">
            <button type="submit" class="btn-qk btn-qk-danger btn-qk-sm"><i class="fa-solid fa-trash"></i> Elimina</button>
          </form>
        </div>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <!-- TAB 2: CLP / GHS -->
  <div class="tab-pane fade" id="stab-clp" role="tabpanel">
    <form method="post" id="form-clp"
          action="<?= url($isEdit ? 'adr/sostanze/edit?id=' . $sostanzaId : 'adr/sostanze/create') ?>">
      <?= $csrf ?>

      <div class="qk-card mb-3">
        <div class="qk-card-header"><span class="qk-card-title">Avvertenza CLP</span></div>
        <div class="qk-card-body">
          <div class="d-flex gap-3">
            <?php foreach (['Pericolo', 'Attenzione'] as $av): ?>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="clp_avvertenza"
                     id="clp_av_<?= $av ?>" value="<?= $av ?>"
                     <?= ($v['clp_avvertenza'] ?? '') === $av ? 'checked' : '' ?>>
              <label class="form-check-label fw-semibold <?= $av === 'Pericolo' ? 'text-danger' : 'text-warning' ?>"
                     for="clp_av_<?= $av ?>"><?= $av ?></label>
            </div>
            <?php endforeach; ?>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="clp_avvertenza" id="clp_av_nessuna" value=""
                     <?= ($v['clp_avvertenza'] ?? '') === '' ? 'checked' : '' ?>>
              <label class="form-check-label qk-text-muted" for="clp_av_nessuna">Nessuna</label>
            </div>
          </div>
        </div>
      </div>

      <div class="qk-card mb-3">
        <div class="qk-card-header"><span class="qk-card-title">Pittogrammi GHS</span></div>
        <div class="qk-card-body">
          <div class="d-flex flex-wrap gap-3">
            <?php foreach ($clp_data['pittogrammi'] as $pitt):
              $pittSel = in_array($pitt['codice'], $pitt_selezionati); ?>
            <label class="d-flex flex-column align-items-center gap-1 pitt-label"
                   id="pitt-lbl-<?= $pitt['codice'] ?>"
                   style="width:90px;padding:8px;border:2px solid <?= $pittSel ? 'var(--qk-accent)' : 'var(--qk-border)' ?>;border-radius:var(--qk-radius);cursor:pointer"
                   title="<?= e($pitt['descrizione']) ?>">
              <input type="checkbox" name="pittogrammi[]" value="<?= e($pitt['codice']) ?>"
                     class="pitt-check d-none" data-label="pitt-lbl-<?= $pitt['codice'] ?>"
                     <?= $pittSel ? 'checked' : '' ?>>
              <span class="fw-bold" style="font-size:0.75rem;color:var(--qk-accent)"><?= e($pitt['codice']) ?></span>
              <span class="text-center" style="font-size:0.7rem;line-height:1.2"><?= e($pitt['nome']) ?></span>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="qk-card mb-3">
        <div class="qk-card-header">
          <span class="qk-card-title">Frasi H — Indicazioni di pericolo</span>
          <span class="qk-card-subtitle">Clicca per selezionare; passa il mouse per leggere il testo</span>
        </div>
        <div class="qk-card-body">
          <?php
          $hPerTipo = [];
          foreach ($clp_data['h_statements'] as $h) { $hPerTipo[$h['tipo']][] = $h; }
          $tipoLabel = ['fisico'=>'Pericoli fisici','salute'=>'Pericoli per la salute','ambiente'=>'Pericoli per l\'ambiente','supplementare'=>'Frasi EUH supplementari'];
          foreach ($hPerTipo as $tipo => $frasi): ?>
          <div class="mb-3">
            <div class="fw-semibold small qk-text-muted mb-2 text-uppercase" style="letter-spacing:.05em"><?= e($tipoLabel[$tipo] ?? $tipo) ?></div>
            <div class="d-flex flex-wrap gap-1">
              <?php foreach ($frasi as $h):
                $sel = in_array($h['codice'], $h_selezionati); ?>
              <label class="h-chip <?= $sel ? 'h-chip-active' : '' ?>" id="hlbl-<?= e($h['codice']) ?>" title="<?= e($h['testo']) ?>">
                <input type="checkbox" name="h_statements[]" value="<?= e($h['codice']) ?>"
                       class="h-check d-none" data-label="hlbl-<?= e($h['codice']) ?>"
                       <?= $sel ? 'checked' : '' ?>>
                <?= e($h['codice']) ?>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="qk-card mb-3">
        <div class="qk-card-header">
          <span class="qk-card-title">Frasi P — Consigli di prudenza</span>
        </div>
        <div class="qk-card-body">
          <?php
          $pPerTipo = [];
          foreach ($clp_data['p_statements'] as $p) { $pPerTipo[$p['tipo']][] = $p; }
          $pTipoLabel = ['generale'=>'Generali','prevenzione'=>'Prevenzione','risposta'=>'Risposta','conservazione'=>'Conservazione','smaltimento'=>'Smaltimento'];
          foreach ($pPerTipo as $tipo => $frasi): ?>
          <div class="mb-3">
            <div class="fw-semibold small qk-text-muted mb-2 text-uppercase" style="letter-spacing:.05em"><?= e($pTipoLabel[$tipo] ?? $tipo) ?></div>
            <div class="d-flex flex-wrap gap-1">
              <?php foreach ($frasi as $p):
                $sel = in_array($p['codice'], $p_selezionati); ?>
              <label class="h-chip <?= $sel ? 'h-chip-active' : '' ?>" id="plbl-<?= e($p['codice']) ?>" title="<?= e($p['testo']) ?>">
                <input type="checkbox" name="p_statements[]" value="<?= e($p['codice']) ?>"
                       class="p-check d-none" data-label="plbl-<?= e($p['codice']) ?>"
                       <?= $sel ? 'checked' : '' ?>>
                <?= e($p['codice']) ?>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="d-flex gap-2 mt-3">
        <button type="submit" class="btn-qk btn-qk-primary">
          <i class="fa-solid fa-floppy-disk me-1"></i> Salva classificazione CLP
        </button>
      </div>
    </form>
  </div>

  <?php if ($isEdit): ?>

  <!-- TAB 4: PRODOTTI ASSOCIATI -->
  <div class="tab-pane fade" id="stab-prodotti" role="tabpanel">
    <div class="row g-3">
      <!-- Sinistra: Catalogo Prodotti Disponibili -->
      <div class="col-md-6">
        <div class="qk-card d-flex flex-column" style="min-height: 480px;">
          <div class="qk-p-3 border-bottom">
            <div class="fw-semibold mb-2 text-primary d-flex align-items-center justify-content-between">
              <span><i class="fa-solid fa-store me-1"></i> Catalogo Prodotti (Disponibili)</span>
              <span class="badge bg-secondary" id="shuttle-available-badge-total" style="font-size: 0.75rem;">0</span>
            </div>
            <div class="row g-2">
              <div class="col-sm-7">
                <input type="text" id="shuttle-search" class="qk-form-control py-1" placeholder="Digita min. 2 caratteri per cercare..." autocomplete="off" style="font-size:0.85rem; height: 34px;">
              </div>
              <div class="col-sm-5">
                <select id="shuttle-category" class="qk-form-select py-1" style="font-size:0.85rem; height: 34px;">
                  <option value="">Tutte le categorie</option>
                  <?php foreach ($categories as $cat): ?>
                    <option value="<?= (int)$cat['id'] ?>"><?= e($cat['nome']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>
          
          <!-- Contenitore lista disponibili -->
          <div class="flex-grow-1 overflow-auto qk-p-2" style="max-height: 380px; min-height: 300px;" id="shuttle-available-container">
            <div class="text-muted text-center py-5 shuttle-empty-state">
              <i class="fa-solid fa-magnifying-glass fa-2xl mb-3 text-muted" style="opacity: 0.5;"></i>
              <div class="small fw-semibold">Digita almeno 2 caratteri per cercare i prodotti nel catalogo.</div>
            </div>
          </div>
          
          <div class="qk-p-2 border-top d-flex justify-content-between align-items-center bg-light-subtle">
            <span class="text-muted small" id="shuttle-available-count">0 selezionati</span>
            <button type="button" class="btn-qk btn-qk-primary btn-qk-sm" id="btn-shuttle-associate" disabled>
              Associa Selezionati <i class="fa-solid fa-chevron-right ms-1"></i>
            </button>
          </div>
        </div>
      </div>

      <!-- Destra: Prodotti Associati alla Sostanza -->
      <div class="col-md-6">
        <div class="qk-card d-flex flex-column" style="min-height: 480px;">
          <div class="qk-p-3 border-bottom">
            <div class="fw-semibold mb-2 text-success d-flex align-items-center justify-content-between">
              <span><i class="fa-solid fa-link me-1"></i> Prodotti Associati</span>
              <span class="badge bg-success" id="shuttle-associated-badge-total" style="font-size: 0.75rem;">0</span>
            </div>
            <div>
              <input type="text" id="shuttle-associated-search" class="qk-form-control py-1" placeholder="Filtra tra i collegati per nome o SKU..." autocomplete="off" style="font-size:0.85rem; height: 34px;">
            </div>
          </div>
          
          <!-- Contenitore lista associati -->
          <div class="flex-grow-1 overflow-auto qk-p-2" style="max-height: 380px; min-height: 300px;" id="shuttle-associated-container">
            <!-- Rendered dynamically via JS -->
          </div>
          
          <div class="qk-p-2 border-top d-flex justify-content-between align-items-center bg-light-subtle">
            <span class="text-muted small" id="shuttle-associated-count">0 selezionati</span>
            <button type="button" class="btn-qk btn-qk-secondary text-danger btn-qk-sm" id="btn-shuttle-disassociate" disabled>
              <i class="fa-solid fa-link-slash me-1"></i> Scollega Selezionati
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>



  <?php endif; ?>
</div>

<link rel="stylesheet" href="<?= asset('css/adr-sostanze-form.css') ?>?v=<?= time() ?>">

<script>
/* Config: PHP -> JS, letta da adr-sostanze-form.js.
   NOTA (23/07/2026): questo blocco era duplicato identico due volte (la
   seconda dentro un if ($isEdit) lato PHP), causando il doppio caricamento
   dello script quando $isEdit e' vero -- rimossa la duplicazione (violazione
   DRY, AGENTS.md Regola Aurea), un solo blocco copre entrambi i casi.
   Aggiunta anche 'associatedList', che nel file JS era ancora un tag di
   output PHP letterale (mai eseguito, perche' adr-sostanze-form.js e'
   servito come file .js statico) -- stesso bug gia' corretto altrove in
   questa sessione per assets/js/products-form.js e i form del Ciclo Acquisti. */
window.QkAdrSostanze = {
    lookupUrl: "<?= url('adr/api/lookup') ?>",
    editUrl: "<?= url('adr/sostanze/edit?id=') ?>",
    sostanzaId: <?= (int)$sostanzaId ?>,

    csrfToken: <?= json_encode(csrf_token()) ?>,
    productsEditUrl: "<?= url('products/edit?id=') ?>",
    salesQuotesApiProdottiUrl: "<?= url('sales/quotes/api/prodotti') ?>",
    apiAssociaProdotti: "<?= url('adr/sostanze/api/associa-prodotti') ?>",
    apiDisassociaProdotto: "<?= url('adr/sostanze/api/disassocia-prodotto') ?>",
    associatedList: <?= json_encode(array_map(function ($p) {
        return [
            'id' => (int)$p['id'],
            'nome' => $p['nome'],
            'sku' => $p['sku_interno'],
            'attivo' => (bool)$p['attivo'],
            'categoria_id' => $p['categoria_id'] ? (int)$p['categoria_id'] : null,
        ];
    }, $prodotti_associati)) ?>
};
</script>
<script src="<?= asset('js/adr-sostanze-form.js') ?>?v=<?= time() ?>"></script>
