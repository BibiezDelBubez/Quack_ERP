<?php
/**
 * products/_form.php  —  schermata crea/modifica prodotto
 *
 * Inclusi automaticamente:
 *   _form_modals.php        (modali Bootstrap: categoria, fornitore, kit)
 *   assets/js/products-form.js  (tutta la logica JS del form)
 *
 * Variabili attese dal controller:
 *   $mode, $entity_id, $values, $errors, $record_meta,
 *   $categorie, $adr_sostanze, $immagini,
 *   $fornitori_disponibili, $prodotto_fornitori, $tutti_prezzi,
 *   $all_tags, $prodotto_tag_ids,
 *   $kit_componenti
 */
$isEdit     = $mode === 'edit';
$v          = $values;
$formAction = $isEdit
    ? url('products/edit?id=' . $entity_id)
    : url('products/create');

$um        = $v['unita_misura'] ?? 'pz';

$apiCatUrl       = url('products/api/crea-categoria');
$apiSearchCat    = url('products/api/categorie');
$apiSearchForn   = url('products/api/fornitori');
$apiSearchProd   = url('products/api/prodotti');
$apiTagCreate    = url('products/api/crea-tag');
$apiFornSave     = $isEdit ? url('products/fornitori/save')          : '#';
$apiFornDelete   = $isEdit ? url('products/fornitori/delete')       : '#';
$apiPrezzoSave   = $isEdit ? url('products/fornitori/prezzi/save')  : '#';
$apiPrezzoDelete = $isEdit ? url('products/fornitori/prezzi/delete'): '#';
$apiFornListini  = url('products/api/fornitore-listini');
$apiKitSave      = $isEdit ? url('products/kit/save')               : '#';
$apiKitDelete    = $isEdit ? url('products/kit/delete')             : '#';

/* Categoria selezionata (label per il picker) */
$cat_label = '';
foreach ($categorie as $cat) {
    if ((string)$cat['id'] === (string)($v['categoria_id'] ?? '')) {
        $cat_label = $cat['label'];
        break;
    }
}

/* Tipo prodotto corrente */
$isKit = ($v['tipo'] ?? 'semplice') === 'kit';
$kit_componenti = $kit_componenti ?? [];
?>
<?php if (!empty($errors['_global'])): ?>
<div class="qk-alert qk-alert-danger" data-auto-dismiss="6000">
  <i class="fa-solid fa-circle-xmark"></i>
  <span><?= e($errors['_global']) ?></span>
  <button class="qk-alert-close"><i class="fa-solid fa-xmark"></i></button>
</div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" data-qk-form action="<?= $formAction ?>">
  <?= csrf_field() ?>

  <div class="qk-form-grid">

    <!-- ══════════════════════════════════════════════════════════════════
         COLONNA PRINCIPALE
    ══════════════════════════════════════════════════════════════════════ -->
    <div>

      <!-- Tab nav -->
      <ul class="nav nav-tabs qk-tabs mb-0" id="productTabs" role="tablist">
        <li class="nav-item" role="presentation">
          <button class="nav-link active" id="ptab-btn-dati"
                  data-bs-toggle="tab" data-bs-target="#ptab-dati"
                  type="button" role="tab" aria-selected="true">
            <i class="fa-solid fa-box fa-fw me-1"></i>Dati prodotto
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="ptab-btn-fornitori"
                  data-bs-toggle="tab" data-bs-target="#ptab-fornitori"
                  type="button" role="tab" aria-selected="false">
            <i class="fa-solid fa-truck-ramp-box fa-fw me-1"></i>Fornitori
            <?php if (!empty($prodotto_fornitori)): ?>
            <span class="badge bg-secondary ms-1" id="badge-fornitori"><?= count($prodotto_fornitori) ?></span>
            <?php else: ?>
            <span class="badge bg-secondary ms-1 d-none" id="badge-fornitori">0</span>
            <?php endif; ?>
          </button>
        </li>
        <li class="nav-item" role="presentation" id="ptab-li-kit"
            <?= !$isKit ? 'style="display:none"' : '' ?>>
          <button class="nav-link" id="ptab-btn-kit"
                  data-bs-toggle="tab" data-bs-target="#ptab-kit"
                  type="button" role="tab" aria-selected="false">
            <i class="fa-solid fa-boxes-stacked fa-fw me-1"></i>Componenti kit
            <?php if ($isKit && !empty($kit_componenti)): ?>
            <span class="badge bg-secondary ms-1" id="badge-kit"><?= count($kit_componenti) ?></span>
            <?php else: ?>
            <span class="badge bg-secondary ms-1 d-none" id="badge-kit">0</span>
            <?php endif; ?>
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="ptab-btn-adr"
                  data-bs-toggle="tab" data-bs-target="#ptab-adr"
                  type="button" role="tab" aria-selected="false">
            <i class="fa-solid fa-triangle-exclamation fa-fw me-1" style="color:#f59e0b"></i>ADR
            <?php if (!empty($v['adr_sostanza_id'])): ?>
            <span class="badge" style="background:var(--qk-warning);color:#000">!</span>
            <?php endif; ?>
          </button>
        </li>
      </ul>

      <div class="tab-content mt-0" id="productTabsContent">

        <!-- ──────────────────────────────────────────────────────────────
             TAB 1: DATI PRODOTTO
        ─────────────────────────────────────────────────────────────────── -->
        <div class="tab-pane fade show active" id="ptab-dati" role="tabpanel">

          <!-- 1. Dati Generali -->
          <div class="qk-card mb-3">
            <div class="qk-card-header"><span class="qk-card-title">Dati Generali</span></div>
            <div class="qk-card-body">
              <div class="qk-form-group">
                <label class="qk-form-label">Nome <span class="required">*</span></label>
                <input type="text" name="nome"
                       class="qk-form-control <?= isset($errors['nome']) ? 'is-invalid' : '' ?>"
                       value="<?= e($v['nome']) ?>"
                       placeholder="es. Cloro granulare 70% — 25 kg"
                       autofocus>
                <?php if (isset($errors['nome'])): ?>
                <span class="qk-form-error"><i class="fa-solid fa-circle-xmark"></i> <?= e($errors['nome']) ?></span>
                <?php endif; ?>
              </div>

              <div class="qk-form-row">
                <!-- Categoria — QkM2O stile Odoo -->
                <div class="qk-form-group">
                  <label class="qk-form-label">Categoria</label>
                  <input type="hidden" name="categoria_id" id="cat-id-input" value="<?= e($v['categoria_id'] ?? '') ?>">
                  <div class="qk-m2o-wrap" id="cat-m2o-wrap">
                    <div class="qk-m2o-input-row">
                      <input type="text" id="cat-label-input" class="qk-m2o-input"
                             value="<?= e($cat_label) ?>"
                             placeholder="Cerca categoria…"
                             autocomplete="off">
                      <button type="button" id="cat-clear-btn"
                              class="qk-m2o-clear <?= $v['categoria_id'] ? '' : 'd-none' ?>"
                              title="Rimuovi categoria">
                        <i class="fa-solid fa-xmark fa-xs"></i>
                      </button>
                    </div>
                    <div class="qk-m2o-dropdown" id="cat-m2o-dd"></div>
                  </div>
                </div>

                <!-- Tag -->
                <div class="qk-form-group">
                  <label class="qk-form-label">Tag <span class="qk-form-hint">(clicca per aggiungere etichette)</span></label>
                  <?php
                  /* Costruiamo l'elenco dei tag selezionati e non selezionati */
                  $selectedTagIds = array_map('intval', $prodotto_tag_ids ?? []);
                  ?>
                  <div class="qk-m2m-wrap" id="tag-m2m-wrap">
                    <div class="qk-m2m-field" id="tag-m2m-field">
                      <?php foreach ($all_tags as $tag): ?>
                      <?php if (in_array((int)$tag['id'], $selectedTagIds, true)): ?>
                      <span class="qk-m2m-chip" data-id="<?= (int)$tag['id'] ?>">
                        <span class="qk-m2m-chip-label"><?= e($tag['nome']) ?></span>
                        <button type="button" class="qk-m2m-chip-remove" aria-label="Rimuovi">&#215;</button>
                        <input type="hidden" name="tag_ids[]" value="<?= (int)$tag['id'] ?>">
                      </span>
                      <?php endif; ?>
                      <?php endforeach; ?>
                      <input type="text" class="qk-m2m-input" id="tag-search-input"
                             placeholder="Aggiungi tag…" autocomplete="off" maxlength="50">
                    </div>
                    <div class="qk-m2m-dropdown" id="tag-m2m-dd"></div>
                  </div>
                  <!-- Dataset tags per JS (non renderizzato) -->
                  <script id="all-tags-data" type="application/json">
                    <?= json_encode(array_values($all_tags), JSON_HEX_TAG) ?>
                  </script>
                </div>
              </div>

              <div class="qk-form-row">
                <!-- Tipo prodotto -->
                <div class="qk-form-group">
                  <label class="qk-form-label">Tipo prodotto <span class="required">*</span></label>
                  <div class="d-flex gap-2 flex-wrap mt-1">
                    <label class="qk-radio-card <?= $v['tipo'] === 'semplice' ? 'is-selected' : '' ?>">
                      <input type="radio" name="tipo" value="semplice"
                             <?= $v['tipo'] === 'semplice' ? 'checked' : '' ?>>
                      <i class="fa-solid fa-box"></i> Semplice
                    </label>
                    <label class="qk-radio-card <?= $v['tipo'] === 'kit' ? 'is-selected' : '' ?>">
                      <input type="radio" name="tipo" value="kit"
                             <?= $v['tipo'] === 'kit' ? 'checked' : '' ?>>
                      <i class="fa-solid fa-boxes-stacked"></i> Kit / Bundle
                    </label>
                  </div>
                </div>

                <!-- Stato attivo -->
                <div class="qk-form-group">
                  <label class="qk-form-label">Stato visibilità</label>
                  <div class="mt-2">
                    <label class="d-flex align-items-center gap-2" style="font-size:.875rem; cursor:pointer; font-weight:600;">
                      <input type="checkbox" name="attivo" value="1" <?= ($v['attivo'] ?? '1') === '1' ? 'checked' : '' ?>>
                      Prodotto attivo
                    </label>
                    <span class="qk-form-hint">Il prodotto apparirà nelle ricerche di listini, preventivi e ordini d'acquisto.</span>
                  </div>
                </div>
              </div>

              <!-- Descrizione breve -->
              <div class="qk-form-group mt-3 mb-0">
                <label class="qk-form-label">
                  Descrizione breve
                  <span class="qk-form-hint">Visibile nelle liste e ricerche (max 255 caratteri)</span>
                </label>
                <input type="text" name="descrizione_breve"
                       class="qk-form-control"
                       value="<?= e($v['descrizione_breve']) ?>"
                       placeholder="Sintesi in una riga" maxlength="255">
              </div>
            </div>
          </div>

          <!-- 2. Identificatori e Tracciabilità -->
          <div class="qk-card mb-3">
            <div class="qk-card-header"><span class="qk-card-title">Identificatori e Tracciabilità</span></div>
            <div class="qk-card-body">
              <div class="qk-form-row">
                <!-- SKU interno -->
                <div class="qk-form-group">
                  <label class="qk-form-label">
                    SKU interno <span class="required">*</span>
                    <span class="qk-form-hint">Codice univoco interno — non modificabile dopo la creazione</span>
                  </label>
                  <input type="text" name="sku_interno"
                         class="qk-form-control qk-font-mono <?= isset($errors['sku_interno']) ? 'is-invalid' : '' ?>"
                         value="<?= e($v['sku_interno']) ?>"
                         placeholder="es. CLORO-25KG"
                         <?= $isEdit ? 'readonly' : '' ?>>
                  <?php if (isset($errors['sku_interno'])): ?>
                  <span class="qk-form-error"><i class="fa-solid fa-circle-xmark"></i> <?= e($errors['sku_interno']) ?></span>
                  <?php endif; ?>
                </div>

                <!-- Codice EAN / Barcode -->
                <div class="qk-form-group">
                  <label class="qk-form-label">
                    Codice EAN / barcode
                    <span class="qk-form-hint">8–14 cifre (EAN-8, EAN-13, GTIN-14)</span>
                  </label>
                  <input type="text" name="ean"
                         class="qk-form-control qk-font-mono"
                         value="<?= e($v['ean']) ?>"
                         placeholder="Esempio: 8001234567890" maxlength="14" inputmode="numeric">
                </div>
              </div>

              <div class="qk-form-row">
                <!-- SKU ecommerce -->
                <div class="qk-form-group">
                  <label class="qk-form-label">
                    SKU su
                    <a href="https://www.piscinasuperstore.it" target="_blank" rel="noopener noreferrer"
                       class="qk-form-hint" style="text-decoration:underline dotted;color:inherit">
                      Piscina Superstore <i class="fa-solid fa-arrow-up-right-from-square fa-xs"></i>
                    </a>
                    <span class="ms-1"
                          data-bs-toggle="tooltip" data-bs-placement="top"
                          title="Il codice SKU dello stesso prodotto permette di sincronizzare le giacenze con quelle del sito"
                          style="cursor:help">
                      <i class="fa-solid fa-circle-question" style="color:var(--qk-text-muted);font-size:.8em"></i>
                    </span>
                  </label>
                  <input type="text" name="sku_ecommerce"
                         class="qk-form-control qk-font-mono <?= isset($errors['sku_ecommerce']) ? 'is-invalid' : '' ?>"
                         value="<?= e($v['sku_ecommerce']) ?>">
                  <?php if (isset($errors['sku_ecommerce'])): ?>
                  <span class="qk-form-error"><i class="fa-solid fa-circle-xmark"></i> <?= e($errors['sku_ecommerce']) ?></span>
                  <?php endif; ?>
                </div>

                <!-- Traccia lotti -->
                <div class="qk-form-group">
                  <label class="qk-form-label">Tracciabilità</label>
                  <div class="mt-2">
                    <label class="d-flex align-items-center gap-2" style="font-size:.875rem; cursor:pointer; font-weight:600;">
                      <input type="checkbox" name="traccia_lotti" value="1"
                             <?= ($v['traccia_lotti'] ?? '1') === '1' ? 'checked' : '' ?>>
                      Traccia lotti
                    </label>
                    <span class="qk-form-hint">Indica se si deve tracciare la movimentazione dei lotti di questo prodotto.</span>
                    <?php if (isset($errors['traccia_lotti'])): ?>
                    <span class="qk-form-error d-block mt-1">
                      <i class="fa-solid fa-circle-xmark"></i> <?= e($errors['traccia_lotti']) ?>
                    </span>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- 3. Formato e Misure -->
          <div class="qk-card mb-3" id="peso-card">
            <div class="qk-card-header"><span class="qk-card-title">Formato e Misure</span></div>
             <div class="qk-card-body">
              <div class="qk-form-row">
                <!-- Unità di misura stoccaggio -->
                <div class="qk-form-group">
                  <label class="qk-form-label">
                    Unità di misura stoccaggio <span class="required">*</span>
                    <span class="qk-form-hint">Usata per giacenze e movimenti di magazzino</span>
                  </label>
                  <select name="unita_misura" id="sel-um"
                          class="qk-form-control <?= isset($errors['unita_misura']) ? 'is-invalid' : '' ?>">
                    <?php foreach ($unita_misura_list as $umRow): ?>
                    <option value="<?= e($umRow['codice']) ?>" <?= $um === $umRow['codice'] ? 'selected' : '' ?>>
                      <?= e($umRow['codice']) ?> — <?= e($umRow['descrizione']) ?>
                    </option>
                    <?php endforeach; ?>
                  </select>
                  <?php if (isset($errors['unita_misura'])): ?>
                  <span class="qk-form-error"><i class="fa-solid fa-circle-xmark"></i> <?= e($errors['unita_misura']) ?></span>
                  <?php endif; ?>
                </div>

                <!-- Descrizione confezione -->
                <div class="qk-form-group">
                  <label class="qk-form-label">
                    Descrizione confezione
                    <span class="qk-form-hint">Es. Secchio da 5 kg, Sacco da 25 kg, Flacone da 1 L</span>
                  </label>
                  <input type="text" name="descrizione_confezione"
                         class="qk-form-control"
                         value="<?= e($v['descrizione_confezione'] ?? '') ?>"
                         placeholder="Es. Secchio da 5 kg">
                </div>
              </div>

              <div class="qk-form-row">
                <!-- Scorta minima -->
                <div class="qk-form-group">
                  <label class="qk-form-label">
                    Scorta minima
                    <span class="qk-form-hint">Sotto questa soglia l'articolo risulterà sottoscorta</span>
                  </label>
                  <input type="number" name="scorta_minima"
                         class="qk-form-control"
                         value="<?= e($v['scorta_minima'] ?? '0') ?>"
                         placeholder="0.000" min="0" step="0.001">
                </div>
              </div>

              <div class="qk-form-row">
                <!-- Unità di misura contenuto (commerciale) -->
                <div class="qk-form-group">
                  <label class="qk-form-label">
                    Unità di misura commerciale
                    <span class="qk-form-hint">Unità di vendita/acquisto (es. kg, litri)</span>
                  </label>
                  <select name="unita_contenuto_id" id="sel-unita-contenuto" class="qk-form-control">
                    <option value="">-- Seleziona unità commerciale (Opzionale) --</option>
                    <?php foreach ($unita_misura_list as $umRow): ?>
                    <option value="<?= e($umRow['id']) ?>" <?= (string)($v['unita_contenuto_id'] ?? '') === (string)$umRow['id'] ? 'selected' : '' ?>>
                      <?= e($umRow['codice']) ?> — <?= e($umRow['descrizione']) ?>
                    </option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <!-- Quantità commerciale per unità stock -->
                <div class="qk-form-group">
                  <label class="qk-form-label">
                    Quantità per confezione
                    <span class="qk-form-hint">Es: inserisci 25 se un sacco (unità stoccaggio) contiene 25 kg (unità commerciale)</span>
                  </label>
                  <input type="number" name="quantita_contenuto"
                         class="qk-form-control"
                         value="<?= e($v['quantita_contenuto'] ?? '') ?>"
                         placeholder="Es. 25.000" min="0" step="0.001">
                </div>
              </div>

              <!-- Campi peso: nascosti per kit (calcolati dai componenti) -->
              <div id="peso-fields-wrap" <?= $isKit ? 'style="display:none"' : '' ?>>
                <div class="qk-form-row">
                  <div class="qk-form-group">
                    <label class="qk-form-label">
                      Peso netto (kg)
                      <span class="qk-form-hint">Solo il prodotto, senza imballo.</span>
                    </label>
                    <input type="number" name="peso_netto_kg" id="peso-netto-input"
                           class="qk-form-control"
                           value="<?= e($v['peso_netto_kg']) ?>"
                           placeholder="0.000" min="0" step="0.001">
                  </div>

                  <div class="qk-form-group">
                    <label class="qk-form-label">
                      Peso lordo (kg)
                      <span class="qk-form-hint">Prodotto + imballo completo.</span>
                    </label>
                    <input type="number" name="peso_lordo_kg" id="peso-lordo-input"
                           class="qk-form-control"
                           value="<?= e($v['peso_lordo_kg']) ?>"
                           placeholder="0.000" min="0" step="0.001">
                  </div>
                </div>
              </div>

              <!-- Per i kit: peso calcolato read-only -->
              <div id="peso-kit-summary" <?= !$isKit ? 'style="display:none"' : '' ?>>
                <div class="qk-form-row">
                  <div class="qk-form-group">
                    <label class="qk-form-label">
                      Peso netto stimato
                      <span class="qk-form-hint">Somma automatica dei componenti. Aggiornata al salvataggio.</span>
                    </label>
                    <div class="input-group">
                      <input type="text" id="peso-kit-display" class="qk-form-control"
                             readonly
                             value="<?php
                               $pesoCalc = array_sum(array_map(
                                 fn($kc) => (float)($kc['peso_netto_kg'] ?? 0) * (float)$kc['quantita'],
                                 $kit_componenti
                               ));
                               echo $pesoCalc > 0 ? number_format($pesoCalc, 3, '.', '') : '';
                             ?>"
                             placeholder="—">
                      <span class="input-group-text">kg</span>
                    </div>
                  </div>
                </div>
              </div>

            </div>
          </div>

          <!-- 4. Descrizioni e Note -->
          <div class="qk-card mb-3">
            <div class="qk-card-header"><span class="qk-card-title">Descrizioni e Note</span></div>
            <div class="qk-card-body">
              <div class="qk-form-row">
                <!-- Descrizione completa -->
                <div class="qk-form-group">
                  <label class="qk-form-label">
                    Descrizione completa
                    <span class="qk-form-hint">Testo libero per schede prodotto, sito e documentazione</span>
                  </label>
                  <textarea name="descrizione_completa" class="qk-form-control" rows="5"
                            placeholder="Descrizione dettagliata del prodotto…"><?= e($v['descrizione_completa']) ?></textarea>
                </div>

                <!-- Note interne -->
                <div class="qk-form-group">
                  <label class="qk-form-label">
                    Note interne
                    <span class="qk-form-hint">Non visibili ai clienti (condizioni speciali, note di stoccaggio)</span>
                  </label>
                  <textarea name="note_interne" class="qk-form-control" rows="5"
                            placeholder="Specifiche, condizioni di stoccaggio, note di sicurezza…"><?= e($v['note_interne']) ?></textarea>
                </div>
              </div>
            </div>
          </div>

        </div><!-- /ptab-dati -->


        <!-- ──────────────────────────────────────────────────────────────
             TAB 2: FORNITORI
             Il tab mostra una tabella raggruppata fornitore → prezzi.
             Il rendering è completamente JS (products-suppliers.js),
             che legge i dati da window.QkPF.fornitori e .tuttePrezzi.
        ─────────────────────────────────────────────────────────────────── -->
        <div class="tab-pane fade" id="ptab-fornitori" role="tabpanel">

          <?php if (!$isEdit): ?>
          <!-- Prodotto non ancora salvato: non si possono associare fornitori -->
          <div class="qk-card mb-3">
            <div class="qk-card-body text-center py-5">
              <i class="fa-solid fa-floppy-disk fa-2x mb-3" style="color:var(--qk-text-muted)"></i>
              <p style="color:var(--qk-text-muted)">Salva prima il prodotto per poter associare i fornitori.</p>
            </div>
          </div>
          <?php else: ?>

          <!-- ── Toolbar ─────────────────────────────────────────────────── -->
          <div class="qk-table-toolbar qk-card mb-3"
               style="border-radius:var(--qk-radius);border:1px solid var(--qk-card-border)">
            <div class="qk-table-toolbar-left">
              <span class="qk-text-muted small">Fornitori associati e scaglioni di prezzo</span>
            </div>
            <div class="qk-table-toolbar-right">
              <button type="button"
                      class="btn-qk btn-qk-primary btn-qk-sm"
                      data-bs-toggle="modal"
                      data-bs-target="#modal-fornitore"
                      data-mode="create"
                      id="btn-add-fornitore">
                <i class="fa-solid fa-plus"></i> Aggiungi fornitore
              </button>
            </div>
          </div>

          <!-- ── Stato vuoto (JS lo mostra/nasconde dinamicamente) ────────── -->
          <div class="qk-empty d-none" id="fornitori-empty">
            <i class="fa-regular fa-truck-ramp-box"></i>
            <p>Nessun fornitore associato.<br>
               Clicca "Aggiungi fornitore" per iniziare.</p>
          </div>

          <!-- ── Stack di card fornitore ─────────────────────────────────────
               Ogni fornitore è un .qks-supplier-block autonomo con:
               - header (nome, SKU, stato, azioni)
               - mini-tabella prezzi propria (con thead visibile solo se ci sono prezzi)
               Gestito interamente da assets/js/products-suppliers.js.
               Non più una singola <table> piatta con righe miste.
          ─────────────────────────────────────────────────────────────────── -->
          <div id="fornitori-container">
            <!-- Popolato dinamicamente da products-suppliers.js -->
          </div>

          <!-- ── Come viene scelto il prezzo (collassabile) ─────────────────
               Spiegazione del motore prezzi per utenti non esperti.
               Usa la classe .qks-help-toggle da products-suppliers.css.
          ─────────────────────────────────────────────────────────────────── -->
          <div class="mt-3">
            <button class="qks-help-toggle"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#prezzi-help"
                    aria-expanded="false"
                    aria-controls="prezzi-help">
              <i class="fa-solid fa-circle-info" style="color:var(--qk-info);opacity:.8;font-size:.8rem"></i>
              <span>Come viene scelto il prezzo?</span>
              <i class="fa-solid fa-chevron-down qks-help-chevron"></i>
            </button>
            <div class="collapse" id="prezzi-help">
              <div class="qk-alert qk-alert-info mt-1" style="font-size:.84rem">
                <i class="fa-solid fa-circle-info fa-fw"></i>
                <div>
                  Quando si crea un ordine di acquisto, l'ERP cerca il prezzo
                  nel seguente ordine:
                  <ol class="mb-1 mt-1" style="padding-left:1.2em">
                    <li>Il <strong>listino attivo</strong> abbinato al fornitore
                        (se esiste e ha una riga valida per la quantità ordinata).</li>
                    <li>Lo <strong>scaglione Base</strong> (senza listino) con la
                        quantità minima più alta ≤ quella ordinata.</li>
                    <li>Nessun prezzo trovato — l'utente dovrà inserirlo manualmente.</li>
                  </ol>
                  I <strong>decimali</strong> sono fino a 4 cifre per consentire
                  prezzi molto bassi (es. &euro;&thinsp;0,0025/unità); vengono mostrati
                  solo se significativi.
                </div>
              </div>
            </div>
          </div>

          <?php endif; ?>

        </div><!-- /ptab-fornitori -->


        <!-- ──────────────────────────────────────────────────────────────
             TAB 3: KIT / COMPONENTI
        ─────────────────────────────────────────────────────────────────── -->
        <div class="tab-pane fade" id="ptab-kit" role="tabpanel">

          <?php if (!$isEdit || ($values['tipo'] ?? 'semplice') !== 'kit'): ?>
          <div class="qk-card mb-3" id="kit-save-first-msg">
            <div class="qk-card-body text-center py-5">
              <i class="fa-solid fa-floppy-disk fa-2x mb-3" style="color:var(--qk-text-muted)"></i>
              <p style="color:var(--qk-text-muted)">Salva prima il prodotto come Kit per poter aggiungere i componenti.</p>
            </div>
          </div>
          <?php endif; ?>

          <?php if ($isEdit): ?>
          <div class="qk-card mb-3" id="kit-manage-table-card" style="<?= ($values['tipo'] ?? 'semplice') !== 'kit' ? 'display:none' : '' ?>">
            <div class="qk-table-toolbar">
              <div class="qk-table-toolbar-left">
                <span class="qk-text-muted small">Prodotti che compongono questo kit</span>
              </div>
              <div class="qk-table-toolbar-right">
                <button type="button" class="btn-qk btn-qk-primary btn-qk-sm"
                        id="btn-add-componente">
                  <i class="fa-solid fa-plus"></i> Aggiungi componente
                </button>
              </div>
            </div>
            <div class="qk-table-wrap" id="kit-table-wrap">
              <?php if (empty($kit_componenti)): ?>
              <div class="qk-empty" id="kit-empty">
                <i class="fa-solid fa-boxes-stacked"></i>
                <p>Nessun componente ancora. Clicca "Aggiungi componente" per iniziare.</p>
              </div>
              <?php else: ?>
              <table class="qk-table" id="kit-table">
                <thead>
                  <tr>
                    <th>Prodotto</th>
                    <th>SKU</th>
                    <th style="width:130px" class="text-end">Quantità</th>
                    <th>Note</th>
                    <th class="col-actions"></th>
                  </tr>
                </thead>
                <tbody id="kit-tbody">
                <?php foreach ($kit_componenti as $kc): ?>
                  <tr id="kit-row-<?= (int)$kc['componente_id'] ?>">
                    <td><span class="fw-semibold"><?= e($kc['nome']) ?></span></td>
                    <td class="font-monospace"><?= e($kc['sku_interno']) ?></td>
                    <td class="text-end font-monospace">
                      <?= number_format((float)$kc['quantita'], 3, ',', '.') ?>
                    </td>
                    <td><?= $kc['note'] ? e($kc['note']) : '<span class="qk-text-muted">—</span>' ?></td>
                    <td class="col-actions">
                      <div class="d-flex gap-1 justify-content-end">
                        <button type="button" class="btn-qk btn-qk-secondary btn-qk-sm btn-edit-componente"
                                data-componente-id="<?= (int)$kc['componente_id'] ?>"
                                data-nome="<?= e($kc['nome']) ?>"
                                data-sku="<?= e($kc['sku_interno']) ?>"
                                data-quantita="<?= e($kc['quantita']) ?>"
                                data-note="<?= e($kc['note'] ?? '') ?>">
                          <i class="fa-solid fa-pen"></i>
                        </button>
                        <button type="button" class="btn-qk btn-qk-danger btn-qk-sm btn-delete-componente"
                                data-componente-id="<?= (int)$kc['componente_id'] ?>"
                                data-nome="<?= e($kc['nome']) ?>">
                          <i class="fa-solid fa-trash"></i>
                        </button>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
              <?php endif; ?>
            </div>
          </div>
          <?php endif; ?>

        </div><!-- /ptab-kit -->


        <!-- ──────────────────────────────────────────────────────────────
             TAB 4: ADR
        ─────────────────────────────────────────────────────────────────── -->
        <div class="tab-pane fade" id="ptab-adr" role="tabpanel">

          <div class="qk-card mb-3">
            <div class="qk-card-header">
              <span class="qk-card-title">
                <i class="fa-solid fa-triangle-exclamation" style="color:#f59e0b"></i>
                Classificazione ADR
              </span>
              <span class="qk-card-subtitle">Lascia vuoto se il prodotto non è soggetto a normativa ADR</span>
            </div>
            <div class="qk-card-body">
              <div class="qk-form-row">
                <div class="qk-form-group">
                  <label class="qk-form-label">Sostanza ADR (N° ONU)</label>
                  <select name="adr_sostanza_id" class="qk-form-control" id="adr-sostanza-sel">
                    <option value="">— Non ADR —</option>
                    <?php foreach ($adr_sostanze as $s): ?>
                    <option value="<?= e($s['numero_onu']) ?>"
                      <?= ($v['adr_sostanza_id'] ?? '') === $s['numero_onu'] ? 'selected' : '' ?>>
                      <?= e($s['numero_onu']) ?>
                      <?php if ($s['descrizione'] ?? ''): ?>— <?= e(mb_strimwidth($s['descrizione'], 0, 60, '…')) ?><?php endif; ?>
                      <?php if ($s['classe_adr'] ?? ''): ?>(cl. <?= e($s['classe_adr']) ?>)<?php endif; ?>
                    </option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="qk-form-group" id="adr-pg-wrap"
                     style="<?= ($v['adr_sostanza_id'] ?? '') === '' ? 'opacity:.4;pointer-events:none' : '' ?>">
                  <label class="qk-form-label">
                    Gruppo di imballaggio (Packing Group)
                    <?php if (($v['adr_sostanza_id'] ?? '') !== ''): ?>
                    <span class="required">*</span>
                    <?php endif; ?>
                  </label>
                  <select name="adr_packing_group"
                          class="qk-form-control <?= isset($errors['adr_packing_group']) ? 'is-invalid' : '' ?>">
                    <option value="">— Seleziona —</option>
                    <option value="I"   <?= ($v['adr_packing_group'] ?? '') === 'I'   ? 'selected' : '' ?>>I — Alta pericolosità</option>
                    <option value="II"  <?= ($v['adr_packing_group'] ?? '') === 'II'  ? 'selected' : '' ?>>II — Media pericolosità</option>
                    <option value="III" <?= ($v['adr_packing_group'] ?? '') === 'III' ? 'selected' : '' ?>>III — Bassa pericolosità</option>
                  </select>
                </div>
              </div>
            </div>
          </div>

          <div class="qk-card mb-3" style="border-style:dashed;opacity:.65">
            <div class="qk-card-body text-center py-4">
              <i class="fa-solid fa-clock-rotate-left fa-xl mb-2" style="color:var(--qk-text-muted)"></i>
              <p class="mb-0 small" style="color:var(--qk-text-muted)">
                Ulteriori dati ADR (schede di sicurezza, restrizioni galleria, quantità limitate…) saranno disponibili in una versione successiva.
              </p>
            </div>
          </div>

        </div><!-- /ptab-adr -->

      </div><!-- /tab-content -->

    </div><!-- /colonna principale -->


    <!-- ═══════════════════════ PANNELLO LATERALE ═════════════════════════ -->
    <div class="qk-status-panel">

      <div class="qk-status-panel-body">
        <!-- Foto principale -->
        <div class="mb-4">
        <p class="fw-semibold mb-2"
           style="font-size:.8rem;text-transform:uppercase;letter-spacing:.04em;color:var(--qk-text-muted)">
          Foto principale
        </p>

          <?php if (!empty($immagini)): ?>
          <div class="mb-2">
            <img src="<?= e($immagini[0]['url']) ?>" alt="Foto prodotto" id="foto-current"
                 style="width:100%;max-height:220px;object-fit:contain;
                        border-radius:6px;border:1px solid var(--qk-border);
                        background:var(--qk-bg-subtle)">
          </div>
          <?php endif; ?>

          <div id="foto-drop-zone" class="<?= !empty($immagini) ? 'd-none' : '' ?>"
               style="border:2px dashed var(--qk-border);border-radius:8px;
                      padding:1.25rem;text-align:center;cursor:pointer;
                      transition:border-color .2s,background .2s">
            <i class="fa-solid fa-image fa-2x mb-2" style="color:var(--qk-text-muted)"></i>
            <p class="small mb-1" style="color:var(--qk-text-muted)">Trascina un&rsquo;immagine oppure clicca</p>
            <p class="small mb-0" style="color:var(--qk-text-muted);font-size:.75rem">JPG, PNG, WebP &middot; max 5 MB</p>
          </div>
          <input type="file" name="foto_principale" id="foto-input"
                 accept="image/jpeg,image/png,image/webp" class="d-none">

          <div id="foto-preview" class="d-none mt-2">
            <img id="foto-preview-img" src="" alt="Anteprima"
                 style="width:100%;max-height:180px;object-fit:contain;
                        border-radius:6px;border:1px solid var(--qk-border)">
            <p id="foto-preview-name" class="small mt-1 mb-0 text-truncate"
               style="color:var(--qk-text-muted)"></p>
          </div>

          <div class="d-flex gap-2 mt-2 flex-wrap">
            <button type="button" id="btn-choose-foto" class="btn-qk btn-qk-secondary btn-qk-sm">
              <i class="fa-solid fa-upload me-1"></i>
              <?= !empty($immagini) ? 'Cambia foto' : 'Scegli foto' ?>
            </button>
            <?php if (!empty($immagini)): ?>
            <button type="button" id="btn-remove-foto"
                    class="btn-qk btn-qk-danger btn-qk-sm" title="Nascondi anteprima">
              <i class="fa-solid fa-xmark"></i>
            </button>
            <?php endif; ?>
          </div>
        </div>

        <!-- Azioni -->
        <div class="qk-status-panel-actions">
          <button type="submit" class="btn-qk btn-qk-primary">
            <i class="fa-solid fa-floppy-disk"></i>
            <?= $isEdit ? 'Salva modifiche' : 'Crea prodotto' ?>
          </button>
          <a href="<?= url('products') ?>" class="btn-qk btn-qk-secondary">Annulla</a>
        </div>

        <?php if ($isEdit && !empty($record_meta)): ?>
        <dl class="qk-status-meta mt-3">
          <?php if ($record_meta['created_at'] ?? ''): ?>
          <dt>Creato</dt><dd><?= fmt_datetime($record_meta['created_at']) ?></dd>
          <?php endif; ?>
          <?php if ($record_meta['updated_at'] ?? ''): ?>
          <dt>Aggiornato</dt><dd><?= fmt_datetime($record_meta['updated_at']) ?></dd>
          <?php endif; ?>
        </dl>
        <?php endif; ?>

      </div>
    </div>

  </div><!-- /qk-form-grid -->
</form>


<?php include __DIR__ . '/_form_modals.php'; ?>

<!-- ═══════════════════════ JS ════════════════════════════════════════ -->
<script>
/* Config: PHP → JS, letta da products-form.js e products-suppliers.js */
window.QkPF = {
  /* ── Generali ─────────────────────────────────────────────────────── */
  isEdit:               <?= $isEdit ? 'true' : 'false' ?>,
  prodottoId:           <?= (int)$entity_id ?>,

  /* ── API URL ──────────────────────────────────────────────────────── */
  apiTag:               <?= json_encode($apiTagCreate) ?>,
  apiCat:               <?= json_encode($apiCatUrl) ?>,
  apiSearchCat:         <?= json_encode($apiSearchCat) ?>,
  apiSearchForn:        <?= json_encode($apiSearchForn) ?>,
  apiSearchProd:        <?= json_encode($apiSearchProd) ?>,
  apiSearchProdExclude: <?= json_encode($apiSearchProd . '?exclude=' . (int)$entity_id) ?>,
  apiFornSave:          <?= json_encode($apiFornSave) ?>,
  apiFornDelete:        <?= json_encode($apiFornDelete) ?>,
  /* Prezzi fornitore — gestiti da products-suppliers.js */
  apiPrezzoSave:        <?= json_encode($apiPrezzoSave) ?>,
  apiPrezzoDelete:      <?= json_encode($apiPrezzoDelete) ?>,
  apiFornListini:       <?= json_encode($apiFornListini) ?>,
  /* Kit */
  apiKitSave:           <?= json_encode($apiKitSave) ?>,
  apiKitDelete:         <?= json_encode($apiKitDelete) ?>,
  suppliersCreateUrl:   <?= json_encode(url('suppliers/create')) ?>,

  /* ── Dati pre-caricati (PHP → JS) ────────────────────────────────── */
  /** Array di fornitori del prodotto (da qk_load_prodotto_fornitori). */
  fornitori:            <?= json_encode(array_values($prodotto_fornitori)) ?>,
  /** Tutti gli scaglioni di prezzo di tutti i fornitori (da qk_load_prodotto_tutti_prezzi). */
  tuttePrezzi:          <?= json_encode(array_values($tutti_prezzi)) ?>,
};
</script>
<script src="<?= url('assets/js/products-form.js') ?>"></script>
<?php if ($isEdit): ?>
<script src="<?= url('assets/js/products-suppliers.js') ?>"></script>
<?php endif; ?>