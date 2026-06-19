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
            <i class="fa-solid fa-handshake fa-fw me-1"></i>Prezzi d'Acquisto & Fornitori
            <?php if (!empty($prodotto_fornitori)): ?>
            <span class="badge bg-secondary ms-1" id="badge-fornitori"><?= count($prodotto_fornitori) ?></span>
            <?php else: ?>
            <span class="badge bg-secondary ms-1 d-none" id="badge-fornitori">0</span>
            <?php endif; ?>
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="ptab-btn-listini"
                  data-bs-toggle="tab" data-bs-target="#ptab-listini"
                  type="button" role="tab" aria-selected="false">
            <i class="fa-solid fa-tag fa-fw me-1"></i>Prezzi di Vendita & Listini
            <?php if (!empty($prezzi_vendita_regole)): ?>
            <span class="badge bg-secondary ms-1" id="badge-listini"><?= count($prezzi_vendita_regole) ?></span>
            <?php else: ?>
            <span class="badge bg-secondary ms-1 d-none" id="badge-listini">0</span>
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
            <i class="fa-solid fa-triangle-exclamation fa-fw me-1" style="color:var(--qk-warning)"></i>ADR & Restrizioni
            <?php if (($v['is_adr'] ?? '0') === '1'): ?>
            <span class="badge ms-1" style="background:var(--qk-warning);color:var(--qk-body-bg)">ADR</span>
            <?php endif; ?>
            <span class="badge bg-danger ms-1 d-none" id="badge-compliance">!</span>
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
                  <div class="qk-flex-row qk-gap-sm flex-wrap mt-1">
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
                    <label class="qk-flex-row qk-gap-sm" style="font-size:.875rem; cursor:pointer; font-weight:600;">
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

          <!-- Prezzo di Vendita -->
          <div class="qk-card mb-3">
            <div class="qk-card-header"><span class="qk-card-title">Prezzo di Vendita</span></div>
            <div class="qk-card-body">
              <div class="qk-form-row">
                <!-- Prezzo di vendita base -->
                <div class="qk-form-group">
                  <label class="qk-form-label">
                    Prezzo di vendita base (€)
                    <span class="qk-form-hint">Imposta il prezzo nel listino standard (ID 1)</span>
                  </label>
                  <input type="number" name="prezzo_vendita_base"
                         class="qk-form-control"
                         value="<?= e($v['prezzo_vendita_base'] ?? '') ?>"
                         placeholder="0.00" min="0" step="0.01">
                </div>
                <!-- Spazio vuoto per allineamento layout -->
                <div class="qk-form-group"></div>
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
                    <label class="qk-flex-row qk-gap-sm" style="font-size:.875rem; cursor:pointer; font-weight:600;">
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
                         value="<?= e($v['descrizione_confezione'] ?? '') ?>">
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
                <!-- Spazio vuoto per allineamento layout -->
                <div class="qk-form-group"></div>
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
             TAB: LISTINI & SCONTI
        ─────────────────────────────────────────────────────────────────── -->
        <div class="tab-pane fade" id="ptab-listini" role="tabpanel">
          <?php if (!$isEdit): ?>
          <div class="qk-card mt-3">
            <div class="qk-card-body text-center py-5">
              <i class="fa-solid fa-floppy-disk fa-2x mb-3" style="color:var(--qk-text-muted)"></i>
              <p style="color:var(--qk-text-muted)">Salva prima il prodotto per poter associare listini speciali e sconti per quantità.</p>
            </div>
          </div>
          <?php else: ?>

          <style>
            .pricing-matrix-container {
              overflow-x: auto;
              margin-top: 1rem;
              border-radius: var(--qk-radius);
              border: 1px solid #cbd5e1;
              background: #fff;
              box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            }
            .pricing-matrix-table {
              width: auto;
              border-collapse: collapse;
              font-size: 0.82rem;
              background: #fff;
              table-layout: fixed;
            }
            .pricing-matrix-table th, .pricing-matrix-table td {
              border: 1px solid #cbd5e1;
              padding: 0.6rem 0.8rem;
              vertical-align: middle;
              overflow: visible; /* Per consentire la visualizzazione dei popover */
            }
            .pricing-matrix-table th {
              background: #f8f9fa;
              font-weight: 600;
              color: var(--qk-text-primary);
              text-align: left;
              border-bottom: 2px solid #cbd5e1;
            }
            .pricing-matrix-table td.listino-info-cell {
              background: #f8fafc;
              font-weight: 600;
              color: var(--qk-text-primary);
            }
            .matrix-cell-wrapper {
              position: relative;
              background: #f8fafc;
              border: 1px solid #cbd5e1;
              border-radius: 6px;
              padding: 4px;
              transition: all 0.2s ease;
            }
            .matrix-cell-wrapper:hover {
              border-color: #94a3b8;
              box-shadow: 0 1px 2px rgba(0,0,0,0.05);
            }
            .matrix-cell-wrapper:focus-within {
              border-color: var(--qk-primary);
              background: #fff;
              box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.15);
            }
            .matrix-price-input-group {
              display: flex;
              align-items: center;
              background: transparent;
              width: 100%;
            }
            .matrix-price-addon {
              font-size: 0.75rem;
              font-weight: 600;
              color: #64748b;
              padding-left: 4px;
              user-select: none;
            }
             .matrix-input-price {
              border: none;
              background: transparent;
              padding: 4px 6px;
              font-family: monospace;
              font-size: 0.88rem;
              font-weight: 600;
              width: 100%;
              text-align: right;
              color: #1e293b;
            }
            .matrix-input-price::placeholder {
              color: #a0aec0;
              opacity: 0.8;
              font-weight: 400;
            }
            .matrix-input-price:focus {
              outline: none;
            }
            .btn-cell-details {
              background: none;
              border: none;
              color: #94a3b8;
              cursor: pointer;
              padding: 2px 6px;
              display: flex;
              align-items: center;
              justify-content: center;
              transition: color 0.2s, background-color 0.2s;
              border-radius: 4px;
            }
            .btn-cell-details:hover {
              color: var(--qk-primary);
              background-color: rgba(13, 110, 253, 0.08);
            }
            .btn-cell-details.has-details {
              color: var(--qk-primary);
              background-color: rgba(13, 110, 253, 0.12);
            }

            /* Stile Popover per Dettagli Cella */
            .matrix-cell-popover {
              position: absolute;
              top: 100%;
              left: 50%;
              transform: translateX(-50%);
              margin-top: 8px;
              width: 210px;
              background: #fff;
              border: 1px solid #cbd5e1;
              border-radius: 8px;
              box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
              z-index: 1050;
              padding: 10px;
              text-align: left;
            }
            .matrix-popover-arrow {
              position: absolute;
              top: -6px;
              left: 50%;
              transform: translateX(-50%) rotate(45deg);
              width: 10px;
              height: 10px;
              background: #fff;
              border-top: 1px solid #cbd5e1;
              border-left: 1px solid #cbd5e1;
            }
            .matrix-popover-content {
              display: flex;
              flex-direction: column;
              gap: 8px;
            }

            .matrix-cell-wrapper.saving {
              border-color: #ffc107;
              background: #fffef0;
            }
            .matrix-cell-wrapper.saved {
              border-color: #28a745;
              background: #f0fff4;
              animation: matrix-flash-green 1s ease;
            }
            .matrix-cell-wrapper.error {
              border-color: #dc3545;
              background: #fff5f5;
            }
            @keyframes matrix-flash-green {
              0% { box-shadow: 0 0 0 4px rgba(40, 167, 69, 0.4); }
              100% { box-shadow: 0 0 0 0 transparent; }
            }
            .btn-delete-qty-column {
              background: none;
              border: none;
              color: var(--qk-danger);
              cursor: pointer;
              padding: 0 4px;
              border-radius: 3px;
              font-size: 0.75rem;
            }
            .btn-delete-qty-column:hover {
              background: rgba(220, 53, 69, 0.1);
            }
          </style>

          <?php
          // Estrai tutti gli scaglioni di quantità (escluso 0)
          $qty_tiers = [];
          foreach ($prezzi_vendita_regole as $regola) {
              $qty = (float)$regola['quantita_minima'];
              if ($qty > 0) {
                  $qty_tiers[] = $qty;
              }
          }
          $qty_tiers = array_unique($qty_tiers);
          sort($qty_tiers);

          // Costruisci mappa listino_id -> quantita_minima -> regola
          $rules_map = [];
          foreach ($prezzi_vendita_regole as $regola) {
              $lid = (int)$regola['listino_id'];
              $qty = (string)(float)$regola['quantita_minima'];
              $rules_map[$lid][$qty] = $regola;
          }

          // Mostra tutti i listini attivi a sistema
          $listini_da_mostrare = $listini;
          ?>

          <div class="qk-card mt-3">
            <div class="qk-card-header qk-flex-row qk-justify-between qk-align-center" style="border-bottom:1px solid var(--qk-border); padding:.6rem 1.25rem;">
              <div>
                <span class="qk-card-title">Matrice Prezzi di Vendita & Listini</span>
                <span class="qk-card-subtitle" style="display:block; font-size:.78rem; color:var(--qk-text-muted);">
                  Griglia dei prezzi per scaglione. I valori si salvano in automatico all'uscita dal campo (blur) o premendo Invio. Clicca sull'icona ingranaggio per sconti e note.
                </span>
              </div>
            </div>
            
            <div class="qk-card-body p-3">
              <!-- Banner RRP multi-fornitore collassabile -->
              <div class="rrp-banner" id="test-rrp-banner">
                <div>
                  <span class="rrp-banner-title"><i class="fa-solid fa-circle-info text-info me-1"></i>Vincoli RRP (Fornitore Preferito):</span>
                  <span id="rrp-banner-content">Caricamento vincoli...</span>
                </div>
                <button type="button" class="btn btn-xs btn-outline-secondary" id="btn-toggle-all-rrp" style="font-size:0.72rem; padding: 2px 6px;">
                  Mostra tutti i fornitori
                </button>
              </div>
              <div id="all-rrp-details" class="collapse mb-3 p-3 border rounded bg-white" style="font-size: 0.8rem;">
                <!-- Tabella comparativa compilata via JS -->
                <table class="table table-sm table-bordered align-middle mb-0">
                  <thead>
                    <tr class="table-light">
                      <th>Fornitore</th>
                      <th>Min RRP</th>
                      <th>Max RRP</th>
                      <th>Pref.</th>
                    </tr>
                  </thead>
                  <tbody id="all-rrp-tbody">
                    <!-- Popolato da JS -->
                  </tbody>
                </table>
              </div>

              <div class="pricing-matrix-container">
                <table class="pricing-matrix-table" id="pricing-matrix-table">
                  <thead>
                    <tr>
                      <th style="width: 250px; background: #e2e8f0; color: #1e293b; border-bottom: 2px solid #cbd5e1;">Listino (Valuta)</th>
                      <th style="width: 200px; background: #f1f5f9; color: #0f172a; border-bottom: 2px solid #cbd5e1; font-weight: 700; text-align: center;">PREZZO BASE [Qtà 0+]</th>
                      <?php foreach ($qty_tiers as $qty): ?>
                      <th class="qty-column-header" data-qty="<?= $qty ?>" style="width: 200px;">
                        <div class="d-flex align-items-center justify-content-between">
                          <span>Qtà <?= number_format($qty, 0, ',', '.') ?>+</span>
                          <button type="button" class="btn-delete-qty-column" data-qty="<?= $qty ?>" title="Elimina questo scaglione per tutti i listini">
                            <i class="fa-solid fa-xmark"></i>
                          </button>
                        </div>
                      </th>
                      <?php endforeach; ?>
                      <!-- Bottone aggiunta scaglione in testata -->
                      <th style="width: 60px; text-align: center; border-bottom: 2px solid #cbd5e1; background: #f8f9fa;">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-add-qty-column" title="Aggiungi scaglione (colonna)" style="padding: 2px 6px; font-size: 0.75rem;">
                          <i class="fa-solid fa-plus"></i>
                        </button>
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($listini_da_mostrare as $listino): 
                        $lid = (int)$listino['id'];
                        $isStandard = ($lid === 1);
                        
                        // Cerca regola base (qty = 0)
                        $baseRule = $rules_map[$lid]['0'] ?? null;
                        $basePrice = $baseRule ? (float)$baseRule['prezzo_unitario'] : ($isStandard ? (float)($v['prezzo_vendita_base'] ?? 0) : null);
                        $baseDiscount = $baseRule ? (float)$baseRule['sconto_perc'] : 0.0;
                        $baseNote = $baseRule ? $baseRule['note'] : '';
                        $baseRowId = $baseRule ? $baseRule['id'] : ($isStandard ? -1 : 0);
                        $hasBaseDetails = ($baseDiscount > 0 || $baseNote !== '');
                    ?>
                    <tr class="listino-row" data-listino-id="<?= $lid ?>" data-valuta="<?= e($listino['valuta']) ?>">
                      <td class="listino-info-cell" style="<?= $isStandard ? 'background: #f1f5f9; border-left: 4px solid var(--qk-primary);' : '' ?>">
                        <div class="d-flex flex-column gap-1">
                          <span style="font-size: 0.85rem; font-weight: 700; color: #1e293b;"><?= e($listino['nome']) ?></span>
                          <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-light text-dark border" style="font-size: 0.68rem;"><?= e($listino['valuta']) ?></span>
                            <?php if ($isStandard): ?>
                              <span class="badge bg-primary text-white" style="font-size: 0.65rem; padding: 2px 5px; font-weight: 600;">LISTINO PRINCIPALE</span>
                            <?php else: ?>
                              <span class="badge bg-secondary text-white" style="font-size: 0.65rem; padding: 2px 5px; font-weight: 500;">Listino Speciale</span>
                            <?php endif; ?>
                          </div>
                        </div>
                      </td>
                      
                      <!-- Colonna prezzo base [0+] -->
                      <td>
                        <div class="matrix-cell-wrapper" data-qty="0" data-riga-id="<?= $baseRowId ?>">
                          <div class="matrix-price-input-group">
                            <span class="matrix-price-addon"><?= e($listino['valuta']) ?></span>
                            <input type="number" class="matrix-input-price cell-price-input" 
                                   step="0.0001" min="0" placeholder="—"
                                   value="<?= $basePrice !== null ? number_format($basePrice, 4, '.', '') : '' ?>">
                          </div>
                        </div>
                      </td>

                      <!-- Colonne quantità scaglionate -->
                      <?php foreach ($qty_tiers as $qty): 
                          $rule = $rules_map[$lid][(string)$qty] ?? null;
                          $price = $rule ? (float)$rule['prezzo_unitario'] : null;
                          $rowId = $rule ? $rule['id'] : 0;
                      ?>
                      <td>
                        <div class="matrix-cell-wrapper" data-qty="<?= $qty ?>" data-riga-id="<?= $rowId ?>">
                          <div class="matrix-price-input-group">
                            <span class="matrix-price-addon"><?= e($listino['valuta']) ?></span>
                            <input type="number" class="matrix-input-price cell-price-input" 
                                   step="0.0001" min="0" placeholder="Ereditato..."
                                   value="<?= $price !== null ? number_format($price, 4, '.', '') : '' ?>">
                          </div>
                        </div>
                      </td>
                      <?php endforeach; ?>
                      <!-- Cella vuota per colonna bottone "+" -->
                      <td style="background: #f8f9fa; border-left: none; border-right: none;"></td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <script>
          (function() {
            const table = document.getElementById('pricing-matrix-table');
            const prodottoId = <?= (int)$entity_id ?>;
            const csrfToken = <?= json_encode(csrf_token()) ?>;
            const baseUrl = <?= json_encode(url()) ?>;
            
            // Riferimento all'input del Prezzo Base sul Tab 1
            const tab1BaseInput = document.querySelector('input[name="prezzo_vendita_base"]');

            // Aggiorna dinamicamente i placeholder grigi per l'ereditarietà dei prezzi
            function updatePlaceholders() {
              // Prendi il prezzo base corrente dal Tab 1 o dal Listino 1 Qty 0
              let standardBaseVal = 0;
              if (tab1BaseInput) {
                standardBaseVal = parseFloat(tab1BaseInput.value) || 0;
              } else {
                const standardRow = table.querySelector('.listino-row[data-listino-id="1"]');
                if (standardRow) {
                  const standardBaseInput = standardRow.querySelector('.matrix-cell-wrapper[data-qty="0"] .cell-price-input');
                  standardBaseVal = parseFloat(standardBaseInput.value) || 0;
                }
              }

              const rows = table.querySelectorAll('.listino-row');
              rows.forEach(row => {
                const lid = row.dataset.listinoId;
                const isStandard = (lid === "1");
                
                const baseCell = row.querySelector('.matrix-cell-wrapper[data-qty="0"]');
                if (!baseCell) return;
                const baseInput = baseCell.querySelector('.cell-price-input');
                const listinoBaseVal = baseInput.value !== '' ? parseFloat(baseInput.value) : (isStandard ? standardBaseVal : null);

                if (!isStandard) {
                  if (baseInput.value === '') {
                    baseInput.placeholder = standardBaseVal.toFixed(4) + ' (Base)';
                  } else {
                    baseInput.placeholder = "—";
                  }
                }

                // Scaglioni successivi (Qty > 0)
                const cells = row.querySelectorAll('.matrix-cell-wrapper[data-qty]:not([data-qty="0"])');
                cells.forEach(cell => {
                  const qty = cell.dataset.qty;
                  const priceInput = cell.querySelector('.cell-price-input');

                  let fallbackVal = listinoBaseVal !== null ? listinoBaseVal : standardBaseVal;
                  
                  // Se il Listino 1 (Standard) ha uno specifico prezzo inserito per questo scaglione, prendilo come ereditarietà
                  const standardRow = table.querySelector('.listino-row[data-listino-id="1"]');
                  if (standardRow) {
                    const standardTierInput = standardRow.querySelector(`.matrix-cell-wrapper[data-qty="${qty}"] .cell-price-input`);
                    if (standardTierInput && standardTierInput.value !== '') {
                      fallbackVal = parseFloat(standardTierInput.value);
                    }
                  }

                  priceInput.placeholder = fallbackVal.toFixed(4) + ' (Ered.)';
                });
              });
            }

            // Inizializza i valori correnti come "last" per evitare salvataggi duplicati al primo focusout
            function cacheCellValues(cell) {
              const price = cell.querySelector('.cell-price-input').value.trim();
              cell.dataset.lastPrice = price;
            }

            table.querySelectorAll('.matrix-cell-wrapper').forEach(cell => {
              cacheCellValues(cell);
            });

            // Sincronizzazione Bidirezionale Tab 1 <-> Tab 3 (Listino 1, Qty 0)
            if (tab1BaseInput) {
              tab1BaseInput.addEventListener('input', function() {
                const standardRow = table.querySelector('.listino-row[data-listino-id="1"]');
                if (standardRow) {
                  const standardBaseInput = standardRow.querySelector('.matrix-cell-wrapper[data-qty="0"] .cell-price-input');
                  standardBaseInput.value = tab1BaseInput.value;
                  updatePlaceholders();
                }
              });

              tab1BaseInput.addEventListener('change', function() {
                const standardRow = table.querySelector('.listino-row[data-listino-id="1"]');
                if (standardRow) {
                  const cell = standardRow.querySelector('.matrix-cell-wrapper[data-qty="0"]');
                  handleCellAutosave(cell);
                }
              });
            }

            // Delegazione eventi per input e blurs
            table.addEventListener('focusout', function(e) {
              const input = e.target.closest('.cell-price-input');
              if (input) {
                const cell = input.closest('.matrix-cell-wrapper');
                // Se modifico il listino base, allineo il Tab 1
                const row = cell.closest('.listino-row');
                if (row.dataset.listinoId === "1" && cell.dataset.qty === "0" && tab1BaseInput) {
                  tab1BaseInput.value = input.value;
                }
                handleCellAutosave(cell);
              }
            });

            table.addEventListener('keydown', function(e) {
              if (e.key === 'Enter') {
                const input = e.target.closest('.cell-price-input');
                if (input) {
                  input.blur();
                  e.preventDefault();
                }
              }
            });

            // Gestore per l'autosave di una cella
            async function handleCellAutosave(cell) {
              const row = cell.closest('.listino-row');
              const listinoId = row.dataset.listinoId;
              const qty = cell.dataset.qty;
              const rigaId = cell.dataset.rigaId;

              const priceInput = cell.querySelector('.cell-price-input');
              const priceVal = priceInput.value.trim();
              const prevPrice = cell.dataset.lastPrice || '';

              if (priceVal === prevPrice) {
                return; // Nessun cambiamento
              }

              // Elimina la riga se il prezzo viene svuotato
              if (priceVal === '') {
                if (rigaId && rigaId !== "0" && rigaId !== "-1") {
                  cell.classList.add('saving');
                  try {
                    const fd = new FormData();
                    fd.append('riga_id', rigaId);
                    fd.append('csrf_token', csrfToken);

                    const res = await fetch(`${baseUrl}/sales/pricelists/righe/delete`, {
                      method: 'POST',
                      body: fd
                    });
                    const data = await res.json();
                    if (data.ok) {
                      cell.classList.remove('saving');
                      cell.classList.add('saved');
                      cell.dataset.rigaId = "0";
                      cacheCellValues(cell);
                      setTimeout(() => cell.classList.remove('saved'), 1500);
                      updatePlaceholders();
                    } else {
                      cell.classList.remove('saving');
                      cell.classList.add('error');
                      alert(data.error || 'Errore durante la cancellazione.');
                    }
                  } catch (err) {
                    cell.classList.remove('saving');
                    cell.classList.add('error');
                  }
                }
                return;
              }

              if (parseFloat(priceVal) < 0) {
                cell.classList.add('error');
                alert('Il prezzo deve essere maggiore o uguale a 0.');
                return;
              }

              cell.classList.remove('error');
              cell.classList.add('saving');

              try {
                const fd = new FormData();
                fd.append('listino_id', listinoId);
                fd.append('riga_id', (rigaId === "-1" || rigaId === "0") ? '0' : rigaId);
                fd.append('prodotto_id', prodottoId);
                fd.append('quantita_minima', qty);
                fd.append('prezzo_unitario', priceVal);
                fd.append('sconto_perc', '0');
                fd.append('note', '');
                fd.append('csrf_token', csrfToken);

                const res = await fetch(`${baseUrl}/sales/pricelists/righe/save`, {
                  method: 'POST',
                  body: fd
                });
                const data = await res.json();

                if (data.ok) {
                  cell.classList.remove('saving');
                  cell.classList.add('saved');
                  
                  if (rigaId !== "-1") {
                    cell.dataset.rigaId = data.riga.id;
                  }
                  cacheCellValues(cell);

                  setTimeout(() => cell.classList.remove('saved'), 1500);
                  updatePlaceholders();
                } else {
                  cell.classList.remove('saving');
                  cell.classList.add('error');
                  alert(data.error || 'Errore durante il salvataggio.');
                }
              } catch (err) {
                cell.classList.remove('saving');
                cell.classList.add('error');
              }
            }

            // Gestore Aggiunta Colonna Scaglione
            document.getElementById('btn-add-qty-column').addEventListener('click', function() {
              const qtyStr = prompt("Inserisci la quantità minima per il nuovo scaglione (es. 10, 50, 100):");
              if (qtyStr === null) return;
              const qty = parseFloat(qtyStr.replace(',', '.'));
              if (isNaN(qty) || qty <= 0) {
                alert("Quantità non valida. Inserire un numero maggiore di 0.");
                return;
              }

              if (table.querySelector(`thead th[data-qty="${qty}"]`)) {
                alert("Questo scaglione di quantità esiste già.");
                return;
              }

              insertQtyColumn(qty);
            });

            function insertQtyColumn(qty) {
              const theadRow = table.querySelector('thead tr');
              const headers = Array.from(theadRow.querySelectorAll('th.qty-column-header'));
              
              // Determina la posizione corretta in base alla quantità per mantenere l'ordine numerico
              let insertIndex = headers.length;
              for (let i = 0; i < headers.length; i++) {
                if (parseFloat(headers[i].dataset.qty) > qty) {
                  insertIndex = i;
                  break;
                }
              }

              // Crea l'header cell
              const th = document.createElement('th');
              th.classList.add('qty-column-header');
              th.dataset.qty = qty;
              th.style.width = "200px";
              th.innerHTML = `
                <div class="d-flex align-items-center justify-content-between">
                  <span>Qtà ${qty.toLocaleString('it-IT')}+</span>
                  <button type="button" class="btn-delete-qty-column" data-qty="${qty}" title="Elimina questo scaglione per tutti i listini">
                    <i class="fa-solid fa-xmark"></i>
                  </button>
                </div>
              `;

              // Inserisci l'header prima dell'ultima colonna del bottone "+"
              const refHeader = headers[insertIndex] || theadRow.querySelector('th:last-child');
              theadRow.insertBefore(th, refHeader);

              // Inserisci le cells corrispondenti per ogni riga prima dell'ultima cella vuota
              const rows = table.querySelectorAll('.listino-row');
              rows.forEach(row => {
                const valuta = row.dataset.valuta;
                const td = document.createElement('td');
                td.innerHTML = `
                  <div class="matrix-cell-wrapper" data-qty="${qty}" data-riga-id="0">
                    <div class="matrix-price-input-group">
                      <span class="matrix-price-addon">${escapeHtml(valuta)}</span>
                      <input type="number" class="matrix-input-price cell-price-input" 
                             step="0.0001" min="0" placeholder="Ereditato..." value="">
                    </div>
                  </div>
                `;

                const refCell = row.cells[insertIndex + 2] || row.querySelector('td:last-child');
                row.insertBefore(td, refCell);

                cacheCellValues(td.querySelector('.matrix-cell-wrapper'));
              });

              updatePlaceholders();
            }

            // Gestore eliminazione colonna scaglione
            table.addEventListener('click', async function(e) {
              const delBtn = e.target.closest('.btn-delete-qty-column');
              if (!delBtn) return;
              
              const qty = delBtn.dataset.qty;
              if (!confirm(`Sei sicuro di voler eliminare lo scaglione quantità ${qty}+ e tutte le sue regole per tutti i listini?`)) {
                return;
              }

              const cellsToDelete = Array.from(table.querySelectorAll(`.matrix-cell-wrapper[data-qty="${qty}"]`))
                .filter(cell => {
                  const rid = cell.dataset.rigaId;
                  return rid && rid !== "0" && rid !== "-1";
                });

              for (const cell of cellsToDelete) {
                const rigaId = cell.dataset.rigaId;
                const fd = new FormData();
                fd.append('riga_id', rigaId);
                fd.append('csrf_token', csrfToken);
                await fetch(`${baseUrl}/sales/pricelists/righe/delete`, { method: 'POST', body: fd });
              }

              const th = table.querySelector(`thead th[data-qty="${qty}"]`);
              if (th) {
                const headers = Array.from(table.querySelectorAll('thead th'));
                const colIndex = headers.indexOf(th);
                th.remove();

                const rows = table.querySelectorAll('tr');
                rows.forEach(row => {
                  if (row.cells[colIndex]) {
                    row.cells[colIndex].remove();
                  }
                });
              }

              updatePlaceholders();
            });

            function escapeHtml(str) {
              if (!str) return '';
              return str.replace(/&/g, "&amp;")
                        .replace(/</g, "&lt;")
                        .replace(/>/g, "&gt;")
                        .replace(/"/g, "&quot;")
                        .replace(/'/g, "&#039;");
            }

            // Esegui il calcolo dei placeholder iniziali
            updatePlaceholders();
          })();
          </script>
          <?php endif; ?>
        </div>



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
              <span class="qk-text-muted small">Fornitori associati (Master List)</span>
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

          <!-- ── Master Table ────────────────────────────────────────────── -->
          <div class="qk-card" style="overflow-x:auto;">
            <table class="qks-master-table" id="master-fornitori-table">
              <thead>
                <tr>
                  <th style="width:60px; text-align:center;">Pref.</th>
                  <th>Fornitore</th>
                  <th>SKU Fornitore</th>
                  <th>Tariffa Base [0+]</th>
                  <th>Lead Time</th>
                  <th style="width:180px; text-align:right;">Azioni</th>
                </tr>
              </thead>
              <tbody id="master-fornitori-tbody">
                <!-- Popolato dinamicamente da JS -->
              </tbody>
            </table>
          </div>

          <!-- ── Drawer Laterale per Dettagli Fornitore ── -->
          <div class="qk-drawer-overlay" id="test-drawer-overlay"></div>
          <div class="qk-drawer" id="test-fornitore-drawer">
            <div class="qk-drawer-header">
              <h5 id="drawer-title">Dettagli Fornitore</h5>
              <button type="button" class="btn-close" id="btn-close-drawer" aria-label="Chiudi"></button>
            </div>
            <div class="qk-drawer-body">
              <div id="drawer-alert" class="qk-alert qk-alert-danger d-none mb-3"></div>
              
              <!-- Form Drawer per salvare metadati fornitore e RRP -->
              <form id="drawer-fornitore-form" onsubmit="event.preventDefault();">
                <input type="hidden" id="dw-id" value="">
                <input type="hidden" id="dw-fornitore-id" value="">
                
                <div class="mb-3">
                  <label class="qk-form-label">Fornitore</label>
                  <input type="text" id="dw-fornitore-label" class="qk-form-control" readonly>
                </div>
                
                <div class="mb-3">
                  <label class="qk-form-label">SKU Fornitore</label>
                  <input type="text" id="dw-sku" class="qk-form-control font-monospace" placeholder="Codice fornitore">
                </div>

                <div class="mb-3">
                  <label class="qk-form-label">Nome prodotto presso il fornitore</label>
                  <input type="text" id="dw-nome-prod-forn" class="qk-form-control" placeholder="Come lo chiama il fornitore" maxlength="150">
                </div>

                <div class="row g-2 mb-3">
                  <div class="col-6">
                    <label class="qk-form-label">Quantità minima ordine (MOQ)</label>
                    <input type="number" id="dw-qta-min" class="qk-form-control" placeholder="0,00" min="0" step="0.001">
                  </div>
                  <div class="col-6">
                    <label class="qk-form-label">Lead Time (Giorni)</label>
                    <input type="number" id="dw-lead-time" class="qk-form-control" min="0" value="7">
                  </div>
                </div>

                <div class="mb-3">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="dw-attivo" value="1" checked>
                    <label class="form-check-label" for="dw-attivo">Attivo</label>
                  </div>
                </div>

                <div class="row g-2 mb-3">
                  <div class="col-6">
                    <label class="qk-form-label">Min RRP</label>
                    <input type="number" id="dw-rrp-min" class="qk-form-control" step="0.0001" min="0" placeholder="0.00">
                  </div>
                  <div class="col-6">
                    <label class="qk-form-label">Max RRP</label>
                    <input type="number" id="dw-rrp-max" class="qk-form-control" step="0.0001" min="0" placeholder="0.00">
                  </div>
                </div>

                <div class="mb-3">
                  <label class="qk-form-label">Note</label>
                  <textarea id="dw-note" class="qk-form-control" rows="2" placeholder="Note specifiche per questo fornitore…"></textarea>
                </div>

                <h6 class="border-bottom pb-2 mt-4"><i class="fa-solid fa-tags me-1"></i>Scaglioni di Acquisto</h6>
                <div style="max-height: 250px; overflow-y:auto; border: 1px solid #cbd5e1; border-radius: 4px; padding: 4px;" class="mb-2">
                  <table class="table table-sm align-middle" id="drawer-scaglioni-table" style="font-size: 0.85rem; margin-bottom:0;">
                    <thead>
                      <tr>
                        <th>Qtà Min.</th>
                        <th>Prezzo d'Acquisto</th>
                        <th style="width:40px;"></th>
                      </tr>
                    </thead>
                    <tbody id="drawer-scaglioni-tbody">
                      <!-- Righe prezzi popolate dinamicamente -->
                    </tbody>
                  </table>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary" id="dw-btn-add-scaglione">
                  <i class="fa-solid fa-plus"></i> Aggiungi Scaglione
                </button>
              </form>
            </div>
            <div class="qk-drawer-footer">
              <button type="button" class="btn-qk btn-qk-secondary" id="btn-cancel-drawer">Annulla</button>
              <button type="button" class="btn-qk btn-qk-primary" id="btn-save-drawer">Salva Modifiche</button>
            </div>
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
                      <div class="qk-flex-row qk-gap-xs justify-content-end">
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
             TAB: ADR E RESTRIZIONI
        ─────────────────────────────────────────────────────────────────── -->
        <div class="tab-pane fade" id="ptab-adr" role="tabpanel">

          <!-- ── Sezione 1: Classificazione ADR ────────────────────────── -->
          <div class="qk-card mb-3 mt-3">
            <div class="qk-card-header">
              <span class="qk-card-title">
                <i class="fa-solid fa-triangle-exclamation me-1" style="color:var(--qk-warning)"></i>
                Classificazione ADR
              </span>
              <span class="qk-card-subtitle">Abilita solo se il prodotto è soggetto a normativa ADR/merci pericolose</span>
            </div>
            <div class="qk-card-body">
              <div class="qk-form-row mb-3">
                <div class="qk-form-group">
                  <div class="form-check form-switch">
                    <input type="checkbox" class="form-check-input" name="is_adr" id="is_adr" value="1"
                           <?= ($v['is_adr'] ?? '0') === '1' ? 'checked' : '' ?>
                           onchange="document.getElementById('adr-sostanza-wrap').style.opacity=this.checked?'1':'.4';
                                     document.getElementById('adr-sostanza-wrap').style.pointerEvents=this.checked?'':'none';">
                    <label class="form-check-label fw-semibold" for="is_adr">Prodotto pericoloso ADR</label>
                  </div>
                </div>
              </div>

              <div id="adr-sostanza-wrap"
                   style="<?= ($v['is_adr'] ?? '0') !== '1' ? 'opacity:.4;pointer-events:none' : '' ?>">
                <div class="qk-form-row">
                  <div class="qk-form-group" style="flex:3">
                    <label class="qk-form-label">Sostanza chimica</label>
                    <select name="sostanza_chimica_id" id="sel-sostanza-chimica" class="qk-form-control">
                      <option value="">— Seleziona sostanza —</option>
                      <?php foreach ($adr_sostanze as $s): ?>
                      <option value="<?= (int)$s['id'] ?>"
                        <?= (string)($v['sostanza_chimica_id'] ?? '') === (string)$s['id'] ? 'selected' : '' ?>>
                        <?= e($s['nome']) ?>
                        <?php if ($s['codice_cas'] ?? ''): ?> · CAS <?= e($s['codice_cas']) ?><?php endif; ?>
                        <?php if ($s['codice_onu'] ?? ''): ?> — UN<?= e($s['codice_onu']) ?><?php endif; ?>
                        <?php if ($s['classe_adr'] ?? ''): ?> (cl. <?= e($s['classe_adr']) ?>)<?php endif; ?>
                        <?php if ($s['gruppo_imballaggio'] ?? ''): ?> GI <?= e($s['gruppo_imballaggio']) ?><?php endif; ?>
                      </option>
                      <?php endforeach; ?>
                    </select>
                    <div class="mt-1 qk-flex-row qk-gap-sm flex-wrap">
                      <a href="<?= url('adr/sostanze/create') ?>" target="_blank" class="small qk-text-accent text-decoration-none">
                        <i class="fa-solid fa-plus me-1"></i>Crea nuova sostanza chimica
                      </a>
                      <?php if (!empty($v['sostanza_chimica_id'])): ?>
                      <span class="qk-text-muted small">·</span>
                      <a href="<?= url('adr/sostanze/edit?id=' . (int)$v['sostanza_chimica_id']) ?>"
                         target="_blank" class="small qk-text-accent text-decoration-none">
                        <i class="fa-solid fa-arrow-up-right-from-square me-1"></i>Apri scheda sostanza
                      </a>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
                <?php if (empty($adr_sostanze)): ?>
                <div class="qk-alert qk-alert-warning mt-2" style="font-size:.85rem">
                  <i class="fa-solid fa-triangle-exclamation me-1"></i>
                  Nessuna sostanza chimica registrata.
                  <a href="<?= url('adr/sostanze/create') ?>" target="_blank" class="fw-semibold">Crea la prima sostanza</a> prima di abilitare l'ADR.
                </div>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <!-- ── Sezione 2: Restrizioni di vendita ─────────────────────── -->
          <?php if (!$isEdit): ?>
          <div class="qk-card mb-3">
            <div class="qk-card-header">
              <span class="qk-card-title">
                <i class="fa-solid fa-shield-halved me-1" style="color:var(--qk-danger)"></i>Restrizioni di vendita
              </span>
            </div>
            <div class="qk-card-body text-center py-4">
              <i class="fa-solid fa-floppy-disk fa-2x mb-2" style="color:var(--qk-text-muted)"></i>
              <p class="mb-0 small" style="color:var(--qk-text-muted)">Salva prima il prodotto per gestire le restrizioni.</p>
            </div>
          </div>
          <?php else: ?>

          <!-- Suggerimento da ADR (mostrato da JS se ci sono suggerimenti) -->
          <div class="qk-alert qk-alert-info mt-3 mb-0 d-none" id="compliance-suggest-banner">
            <i class="fa-solid fa-circle-info fa-fw"></i>
            <span id="compliance-suggest-text"></span>
            <button type="button" class="btn-qk btn-qk-primary btn-qk-sm ms-auto" id="btn-apply-suggestions">
              Applica suggerimenti
            </button>
          </div>

          <!-- Pannello informativo -->
          <div class="qk-guidance-box mt-3 mb-0">
            <div class="fs-4"><i class="fa-solid fa-circle-info"></i></div>
            <div>
              <h6 class="mb-1 fw-bold">Le restrizioni vanno gestite sulla sostanza chimica, non qui</h6>
              <p class="mb-0 text-muted" style="font-size:0.875rem">
                Se questo prodotto contiene una sostanza soggetta a limiti di vendita (precursori di esplosivi, stupefacenti, biocidi ecc.),
                vai sulla <a href="<?= !empty($v['sostanza_chimica_id']) ? url('adr/sostanze/edit?id=' . (int)$v['sostanza_chimica_id']) : url('adr/sostanze') ?>"
                target="_blank" class="qk-text-accent fw-semibold text-decoration-none">scheda sostanza chimica</a> e aggiungile lì —
                si applicano automaticamente a tutti i prodotti che usano quella sostanza.<br>
                <span style="font-size:.85rem; display:block; margin-top:0.4rem">Usa la sezione qui sotto solo per restrizioni specifiche di <em>questo</em> prodotto
                che non dipendono dalla sostanza (es. prodotto biocida non ADR, fitofarmaco, ecc.).</span>
              </p>
            </div>
          </div>

          <!-- Card principale restrizioni dirette sul prodotto -->
          <div class="qk-card mt-3">
            <div class="qk-card-header">
              <span class="qk-card-title">
                <i class="fa-solid fa-shield-halved me-1" style="color:var(--qk-danger)"></i>Restrizioni specifiche di questo prodotto
              </span>
              <button type="button" class="btn-qk btn-qk-primary btn-qk-sm" id="btn-add-restrizione">
                <i class="fa-solid fa-plus me-1"></i>Aggiungi
              </button>
            </div>

            <!-- Stato vuoto -->
            <div class="qk-card-body text-center py-5 d-none" id="compliance-empty">
              <i class="fa-solid fa-shield-check fa-2x mb-2" style="color:var(--qk-success)"></i>
              <p class="mb-0" style="color:var(--qk-text-muted)">Nessuna restrizione — prodotto vendibile liberamente.</p>
            </div>

            <!-- Lista restrizioni -->
            <div class="qk-table-wrap d-none" id="compliance-list-wrap">
              <table class="qk-table mb-0" id="compliance-table">
                <thead>
                  <tr>
                    <th>Tipo restrizione</th>
                    <th>Riferimento normativo</th>
                    <th>Note</th>
                    <th style="width:60px"></th>
                  </tr>
                </thead>
                <tbody id="compliance-tbody"></tbody>
              </table>
            </div>
          </div>

          <!-- Modale aggiungi restrizione -->
          <div class="modal fade" id="modal-compliance-add" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
              <div class="modal-content">
                <div class="modal-header">
                  <h5 class="modal-title"><i class="fa-solid fa-shield-halved me-2"></i>Aggiungi restrizione</h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                  <div class="qk-form-group">
                    <label class="qk-form-label">Tipo restrizione <span class="required">*</span></label>
                    <select class="qk-form-control" id="compliance-add-type">
                      <option value="">— Seleziona —</option>
                    </select>
                  </div>
                  <div class="qk-form-group mb-0">
                    <label class="qk-form-label">Note interne</label>
                    <input type="text" class="qk-form-control" id="compliance-add-note"
                           placeholder="Opzionale — motivo, riferimento interno…">
                  </div>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn-qk btn-qk-secondary" data-bs-dismiss="modal">Annulla</button>
                  <button type="button" class="btn-qk btn-qk-primary" id="btn-compliance-add-confirm">
                    <i class="fa-solid fa-plus me-1"></i>Aggiungi
                  </button>
                </div>
              </div>
            </div>
          </div>

          <script>
          (function () {
            const PRODOTTO_ID  = <?= (int)$entity_id ?>;
            const CSRF         = <?= json_encode(csrf_token()) ?>;
            const BASE         = <?= json_encode(url()) ?>;
            const API_LIST     = BASE + '/products/compliance/list';
            const API_ADD      = BASE + '/products/compliance/add';
            const API_REMOVE   = BASE + '/products/compliance/remove';

            const tbody        = document.getElementById('compliance-tbody');
            const emptyEl      = document.getElementById('compliance-empty');
            const listWrap     = document.getElementById('compliance-list-wrap');
            const badge        = document.getElementById('badge-compliance');
            const suggestBanner = document.getElementById('compliance-suggest-banner');
            const suggestText  = document.getElementById('compliance-suggest-text');
            const typeSelect   = document.getElementById('compliance-add-type');
            const modalEl      = document.getElementById('modal-compliance-add');
            const modal        = new bootstrap.Modal(modalEl);

            let allTypes       = [];
            let assegnateIds   = new Set();
            let suggerimenti   = [];

            // ── Carica dati ─────────────────────────────────────────────────
            async function load() {
              try {
                const r = await fetch(API_LIST + '?prodotto_id=' + PRODOTTO_ID);
                const d = await r.json();
                if (!d.ok) return;

                allTypes      = d.tutti;
                assegnateIds  = new Set(d.assegnate.map(a => a.restriction_type_id));
                suggerimenti  = d.suggerimenti.filter(s => !assegnateIds.has(s.restriction_type_id));

                renderTable(d.assegnate);
                renderSuggestions();
                populateSelect();
              } catch (e) { console.error('compliance load', e); }
            }

            // ── Tabella ──────────────────────────────────────────────────────
            function renderTable(rows) {
              tbody.innerHTML = '';
              if (rows.length === 0) {
                emptyEl.classList.remove('d-none');
                listWrap.classList.add('d-none');
                badge.classList.add('d-none');
              } else {
                emptyEl.classList.add('d-none');
                listWrap.classList.remove('d-none');
                badge.classList.remove('d-none');
                badge.textContent = rows.length;
                rows.forEach(r => tbody.appendChild(buildRow(r)));
              }
            }

            function buildRow(r) {
              const tr = document.createElement('tr');
              tr.dataset.rtid = r.restriction_type_id;
              tr.innerHTML = `
                <td><strong>${esc(r.name)}</strong></td>
                <td><span class="qk-form-hint">${esc(r.regulatory_ref || '—')}</span></td>
                <td><span class="small" style="color:var(--qk-text-muted)">${esc(r.note || '—')}</span></td>
                <td>
                  <button type="button" class="btn-qk btn-qk-danger btn-qk-sm btn-remove-restrizione" title="Rimuovi">
                    <i class="fa-solid fa-trash-can"></i>
                  </button>
                </td>`;
              return tr;
            }

            // ── Suggerimenti da ADR ─────────────────────────────────────────
            function renderSuggestions() {
              if (suggerimenti.length === 0) {
                suggestBanner.classList.add('d-none');
                return;
              }
              const nomi = suggerimenti.map(s => `<strong>${esc(s.name)}</strong>`).join(', ');
              suggestText.innerHTML = `Dal codice ONU di questo prodotto risulta: ${nomi}. Vuoi aggiungerle?`;
              suggestBanner.classList.remove('d-none');
            }

            // ── Select modale ────────────────────────────────────────────────
            function populateSelect() {
              typeSelect.innerHTML = '<option value="">— Seleziona —</option>';
              allTypes.filter(t => !assegnateIds.has(t.id)).forEach(t => {
                const o = document.createElement('option');
                o.value = t.id;
                o.textContent = t.name + (t.regulatory_ref ? ' (' + t.regulatory_ref + ')' : '');
                typeSelect.appendChild(o);
              });
            }

            // ── Aggiungi ─────────────────────────────────────────────────────
            document.getElementById('btn-add-restrizione').addEventListener('click', () => {
              document.getElementById('compliance-add-note').value = '';
              modal.show();
            });

            document.getElementById('btn-compliance-add-confirm').addEventListener('click', async () => {
              const rtid = typeSelect.value;
              const note = document.getElementById('compliance-add-note').value.trim();
              if (!rtid) { typeSelect.focus(); return; }

              await addRestrizione(parseInt(rtid), note);
              modal.hide();
            });

            // ── Applica suggerimenti ─────────────────────────────────────────
            document.getElementById('btn-apply-suggestions').addEventListener('click', async () => {
              for (const s of suggerimenti) {
                await addRestrizione(s.restriction_type_id, '');
              }
            });

            async function addRestrizione(rtid, note) {
              const fd = new FormData();
              fd.append('prodotto_id', PRODOTTO_ID);
              fd.append('restriction_type_id', rtid);
              fd.append('note', note);
              fd.append('csrf_token', CSRF);

              const r = await fetch(API_ADD, { method: 'POST', body: fd });
              const d = await r.json();
              if (d.ok) {
                assegnateIds.add(rtid);
                suggerimenti = suggerimenti.filter(s => s.restriction_type_id !== rtid);
                tbody.appendChild(buildRow(d.restrizione));
                emptyEl.classList.add('d-none');
                listWrap.classList.remove('d-none');
                badge.classList.remove('d-none');
                badge.textContent = assegnateIds.size;
                populateSelect();
                renderSuggestions();
              } else {
                alert(d.error || 'Errore.');
              }
            }

            // ── Rimuovi ──────────────────────────────────────────────────────
            tbody.addEventListener('click', async function (e) {
              const btn = e.target.closest('.btn-remove-restrizione');
              if (!btn) return;
              const tr   = btn.closest('tr');
              const rtid = parseInt(tr.dataset.rtid);

              if (!confirm('Rimuovere questa restrizione dal prodotto?')) return;

              const fd = new FormData();
              fd.append('prodotto_id', PRODOTTO_ID);
              fd.append('restriction_type_id', rtid);
              fd.append('csrf_token', CSRF);

              const r = await fetch(API_REMOVE, { method: 'POST', body: fd });
              const d = await r.json();
              if (d.ok) {
                tr.remove();
                assegnateIds.delete(rtid);
                badge.textContent = assegnateIds.size;
                if (assegnateIds.size === 0) {
                  emptyEl.classList.remove('d-none');
                  listWrap.classList.add('d-none');
                  badge.classList.add('d-none');
                }
                populateSelect();
                // Ricalcola suggerimenti (la rimossa potrebbe tornare suggerita)
                suggerimenti = allTypes.filter(t =>
                  !assegnateIds.has(t.id) &&
                  suggerimenti.some(s => s.restriction_type_id === t.id)
                );
                renderSuggestions();
              } else {
                alert(d.error || 'Errore nella rimozione.');
              }
            });

            function esc(str) {
              if (!str) return '';
              return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            }

            // Carica quando si attiva la tab
            document.getElementById('ptab-btn-adr').addEventListener('shown.bs.tab', function () {
              if (!tbody.dataset.loaded) {
                load();
                tbody.dataset.loaded = '1';
              }
            });

          })();
          </script>

          <?php endif; ?>

        </div><!-- /ptab-adr (ADR e Restrizioni) -->

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

          <div class="qk-flex-row qk-gap-sm mt-2 flex-wrap">
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
<script src="<?= asset('js/products-form.js') ?>"></script>
<?php if ($isEdit): ?>
<script src="<?= asset('js/products-suppliers.js') ?>"></script>
<?php endif; ?>