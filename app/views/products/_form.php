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
$apiSdsList         = url('products/sds/list');
$apiSdsSave         = $isEdit ? url('products/sds/save')           : '#';
$apiSdsDelete       = $isEdit ? url('products/sds/delete')         : '#';
$apiSdsRimuoviFile  = $isEdit ? url('products/sds/rimuovi-file')   : '#';

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

$um_base_id = null;
foreach ($unita_misura_list as $umRow) {
    if ($umRow['codice'] === $um) {
        $um_base_id = $umRow['id'];
        break;
    }
}
$um_comm_codice = '';
if (!empty($v['unita_contenuto_id'])) {
    foreach ($unita_misura_list as $umRow) {
        if ((string)$umRow['id'] === (string)$v['unita_contenuto_id']) {
            $um_comm_codice = $umRow['codice'];
            break;
        }
    }
}
?>
<script>
/* Config: PHP → JS, letta da products-form.js e products-suppliers.js */
window.QkPF = {
  csrfToken:            <?= json_encode(csrf_token()) ?>,
  /* ── Generali ─────────────────────────────────────────────────────── */
  isEdit:               <?= $isEdit ? 'true' : 'false' ?>,
  prodottoId:           <?= (int)$entity_id ?>,
  baseUrl:              <?= json_encode(url()) ?>,

  /* ── API URL ──────────────────────────────────────────────────────── */
  apiTag:               <?= json_encode($apiTagCreate) ?>,
  apiCat:               <?= json_encode($apiCatUrl) ?>,
  apiSearchCat:         <?= json_encode($apiSearchCat) ?>,
  apiSearchForn:        <?= json_encode($apiSearchForn) ?>,
  apiSearchProd:        <?= json_encode($apiSearchProd) ?>,
  apiSearchProdExclude: <?= json_encode($apiSearchProd . '?exclude=' . (int)$entity_id) ?>,
  apiFornSave:          <?= json_encode($apiFornSave) ?>,
  apiFornDelete:        <?= json_encode($apiFornDelete) ?>,
  unitaMisuraList:      <?= json_encode($unita_misura_list ?? []) ?>,
  /* Prezzi fornitore — gestiti da products-suppliers.js */
  apiPrezzoSave:        <?= json_encode($apiPrezzoSave) ?>,
  apiPrezzoDelete:      <?= json_encode($apiPrezzoDelete) ?>,
  apiFornListini:       <?= json_encode($apiFornListini) ?>,
  /* Kit */
  apiKitSave:           <?= json_encode($apiKitSave) ?>,
  apiKitDelete:         <?= json_encode($apiKitDelete) ?>,
  suppliersCreateUrl:   <?= json_encode(url('suppliers/create')) ?>,
  /* Schede di sicurezza (SDS) — gestite da products-sds.js */
  apiSdsList:           <?= json_encode($apiSdsList) ?>,
  apiSdsSave:           <?= json_encode($apiSdsSave) ?>,
  apiSdsDelete:         <?= json_encode($apiSdsDelete) ?>,
  apiSdsRimuoviFile:    <?= json_encode($apiSdsRimuoviFile) ?>,
  apiUploadsBase:       <?= json_encode(url('uploads?path=')) ?>,

  /* ── Dati pre-caricati (PHP → JS) ────────────────────────────────── */
  /** Array di fornitori del prodotto (da qk_load_prodotto_fornitori). */
  fornitori:            <?= json_encode(array_values($prodotto_fornitori)) ?>,
  /** Tutti gli scaglioni di prezzo di tutti i fornitori (da qk_load_prodotto_tutti_prezzi). */
  tuttePrezzi:          <?= json_encode(array_values($tutti_prezzi)) ?>,

  /* ── UoM settings ── */
  uomStock:             <?= json_encode($um) ?>,
  uomStockId:           <?= json_encode($um_base_id) ?>,
  uomCommercialId:      <?= json_encode($v['unita_contenuto_id'] ?? null) ?>,
  uomCommercial:        <?= json_encode($um_comm_codice) ?>,
  quantitaContenuto:    <?= json_encode($v['quantita_contenuto'] ?? null) ?>,
};
</script>
<?php if (!empty($errors['_global'])): ?>
<div class="qk-alert qk-alert-danger" data-auto-dismiss="6000">
  <i class="fa-solid fa-circle-xmark"></i>
  <span><?= e($errors['_global']) ?></span>
  <button class="qk-alert-close"><i class="fa-solid fa-xmark"></i></button>
</div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" data-qk-form action="<?= $formAction ?>">
  <?= csrf_field() ?>

  <!-- ═══════════════════════ BARRA AZIONI SUPERIORE ═══════════════════════ -->
  <div class="qk-card mb-3" id="top-action-bar">
    <div class="qk-card-body d-flex align-items-center justify-content-between flex-wrap gap-2 py-2">
      <div class="d-flex align-items-center gap-2">
        <button type="submit" class="btn-qk btn-qk-primary">
          <i class="fa-solid fa-floppy-disk me-1"></i>
          <?= $isEdit ? 'Salva modifiche' : 'Crea prodotto' ?>
        </button>
        <a href="<?= url('products') ?>" class="btn-qk btn-qk-secondary">Annulla</a>
      </div>

      <?php if ($isEdit): ?>
      <div class="d-flex align-items-center gap-3">
        <?php if (!empty($record_meta)): ?>
        <span class="text-muted small d-none d-md-inline" style="font-size:.8rem;">
          <?php if ($record_meta['created_at'] ?? ''): ?>
          Creato: <?= fmt_datetime($record_meta['created_at']) ?>
          <?php endif; ?>
          <?php if ($record_meta['updated_at'] ?? ''): ?>
          &middot; Aggiornato: <?= fmt_datetime($record_meta['updated_at']) ?>
          <?php endif; ?>
        </span>
        <?php endif; ?>

        <button type="button" id="btn-delete-prodotto" class="btn-qk btn-qk-danger btn-qk-sm">
          <i class="fa-solid fa-trash me-1"></i> Elimina prodotto
        </button>
      </div>
      <?php endif; ?>
    </div>
  </div>
  
  <div class="qk-alert qk-alert-info mb-3 d-none align-items-center" id="top-live-bar" style="padding: 10px 15px;">
    <i class="fa-solid fa-cloud-arrow-up me-2" style="font-size: 1.2rem;"></i>
    <div>
      <strong>Salvataggio Automatico Attivo</strong><br>
      <span style="font-size: 0.85rem;">Tutte le modifiche effettuate in questa scheda (incluso il Costo d'Acquisto Base) vengono salvate istantaneamente in tempo reale. Non è necessario premere alcun tasto "Salva".</span>
    </div>
  </div>

  <!-- Tab nav (100% full width) -->
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
            <i class="fa-solid fa-handshake fa-fw me-1"></i>Costi d'Acquisto & Fornitori
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
            <?php if (qk_is_true($v['is_adr'] ?? '0')): ?>
            <span class="badge ms-1" style="background:var(--qk-warning);color:var(--qk-body-bg)">ADR</span>
            <?php endif; ?>
            <span class="badge bg-danger ms-1 d-none" id="badge-compliance">!</span>
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="ptab-btn-sds"
                  data-bs-toggle="tab" data-bs-target="#ptab-sds"
                  type="button" role="tab" aria-selected="false">
            <i class="fa-solid fa-file-shield fa-fw me-1"></i>Scheda di sicurezza
            <span class="badge bg-warning text-dark ms-1 d-none" id="badge-sds-scadute" title="Ci sono schede scadute">!</span>
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
              <div class="row g-4">
                <!-- Colonna Campi Dati -->
                <div class="col-lg-8">
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
                          <input type="checkbox" name="attivo" value="1" <?= qk_is_true($v['attivo'] ?? '1') ? 'checked' : '' ?>>
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

                <!-- Colonna Foto Principale -->
                <div class="col-lg-4 border-start-lg ps-lg-4">
                  <label class="qk-form-label mb-2">Foto principale</label>
                  <input type="hidden" name="rimuovi_foto" id="rimuovi-foto-input" value="0">

                  <?php if (!empty($immagini)): ?>
                  <div class="mb-2">
                    <img src="<?= e($immagini[0]['url']) ?>" alt="Foto prodotto" id="foto-current"
                         style="width:100%;max-height:200px;object-fit:contain;border-radius:6px;border:1px solid var(--qk-border);background:var(--qk-bg-subtle)">
                  </div>
                  <?php endif; ?>

                  <div id="foto-drop-zone" class="<?= !empty($immagini) ? 'd-none' : '' ?>"
                       style="border:2px dashed var(--qk-border);border-radius:8px;padding:1.25rem;text-align:center;cursor:pointer;transition:border-color .2s,background .2s">
                    <i class="fa-solid fa-image fa-2x mb-2" style="color:var(--qk-text-muted)"></i>
                    <p class="small mb-1" style="color:var(--qk-text-muted)">Trascina un&rsquo;immagine oppure clicca</p>
                    <p class="small mb-0" style="color:var(--qk-text-muted);font-size:.75rem">JPG, PNG, WebP &middot; max 5 MB</p>
                  </div>
                  <input type="file" name="foto_principale" id="foto-input"
                         accept="image/jpeg,image/png,image/webp" class="d-none">

                  <div id="foto-preview" class="d-none mt-2">
                    <img id="foto-preview-img" src="" alt="Anteprima"
                         style="width:100%;max-height:180px;object-fit:contain;border-radius:6px;border:1px solid var(--qk-border)">
                    <p id="foto-preview-name" class="small mt-1 mb-0 text-truncate" style="color:var(--qk-text-muted)"></p>
                  </div>

                  <div class="qk-flex-row qk-gap-sm mt-2 flex-wrap">
                    <button type="button" id="btn-choose-foto" class="btn-qk btn-qk-secondary btn-qk-sm">
                      <i class="fa-solid fa-upload me-1"></i>
                      <span id="btn-choose-foto-label"><?= !empty($immagini) ? 'Cambia foto' : 'Scegli foto' ?></span>
                    </button>
                    <button type="button" id="btn-remove-foto"
                            class="btn-qk btn-qk-danger btn-qk-sm <?= empty($immagini) ? 'd-none' : '' ?>" title="Rimuovi foto">
                      <i class="fa-solid fa-trash me-1"></i> Rimuovi foto
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Prezzo di Vendita -->
          <div class="qk-card mb-3">
            <div class="qk-card-header"><span class="qk-card-title"><i class="fa-solid fa-tags me-2" style="color:var(--qk-accent)"></i>Prezzo di Vendita & Ricarichi</span></div>
            <div class="qk-card-body">
              <div class="qk-form-row">
                <!-- Prezzo di vendita base -->
                <div class="qk-form-group">
                  <label class="qk-form-label">
                    Prezzo Vendita (Listino Pubblico)
                    <span class="qk-form-hint">Inserimento rapido (sovrascrive eventuali prezzi nel Listino Globale)</span>
                  </label>
                  <input type="number" name="prezzo_vendita_base"
                         class="qk-form-control"
                         value="<?= e($v['prezzo_vendita_base'] ?? '') ?>"
                         placeholder="0.00" min="0" step="0.01">
                </div>
                <!-- Markup di fallback (%) -->
                <div class="qk-form-group">
                  <label class="qk-form-label">
                    Markup di Fallback (%)
                    <span class="ms-1 text-primary"
                          data-bs-toggle="tooltip" data-bs-placement="top"
                          title="Ricarico percentuale applicato sul costo minimo del fornitore per determinare il prezzo di vendita se l'articolo non è configurato in alcun listino. Se lasciato vuoto, verrà usato il valore di fallback globale configurato nel sistema."
                          style="cursor:help">
                      <i class="fa-solid fa-circle-question" style="color:var(--qk-text-muted);font-size:.9em"></i>
                    </span>
                    <span class="qk-form-hint">Sovrascrive il ricarico di fallback generale di sistema</span>
                  </label>
                  <div class="input-group">
                    <input type="number" name="markup_fallback_perc"
                           class="qk-form-control"
                           value="<?= e($v['markup_fallback_perc'] ?? '') ?>"
                           placeholder="Usa default di sistema" min="0" step="0.01">
                    <span class="input-group-text">%</span>
                  </div>
                </div>
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
                             <?= qk_is_true($v['traccia_lotti'] ?? '1') ? 'checked' : '' ?>>
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
              <div class="qk-form-row">
                <!-- Scorta minima: non più un campo del prodotto (era un unico valore
                     azienda-wide, superato dal multi-magazzino). Qui mostriamo solo,
                     a titolo informativo e non modificabile, la soglia impostata per
                     il magazzino attivo — la modifica vera si fa nel modulo Magazzino,
                     dove ha senso scegliere per quale sede. Vedi Piano Multi-Magazzino §3.5. -->
                <div class="qk-form-group">
                  <label class="qk-form-label">Scorta minima</label>
                  <div class="d-flex align-items-center gap-2">
                    <input type="number" class="qk-form-control" value="<?= e($v['scorta_minima'] ?? '0') ?>"
                           placeholder="0.000" step="0.001" disabled readonly style="max-width:140px">
                    <?php if ($isEdit): ?>
                    <a href="<?= url('warehouse/min-stock?prodotto_id=' . (int) $entity_id) ?>" class="qk-form-hint">
                      <i class="fa-solid fa-arrow-up-right-from-square"></i> Modifica soglie di riordino per sede →
                    </a>
                    <?php endif; ?>
                  </div>
                  <span class="qk-form-hint">Valore per il magazzino attivo, mostrato qui a titolo informativo. Si imposta per ciascuna sede nel modulo Magazzino.</span>
                </div>
                <!-- Giacenza totale: somma su tutte le sedi, contatore cliccabile verso
                     il dettaglio per magazzino — pattern Odoo ("In Stock" a un click dalla
                     scheda prodotto), Best Practice UX-UI Multi-Magazzino nei Grandi ERP.md §4/§5.
                     Riusa la stessa schermata già costruita per le soglie di riordino, invece
                     di un nuovo drawer/vista separata. -->
                <div class="qk-form-group">
                  <label class="qk-form-label">Giacenza (tutte le sedi)</label>
                  <?php if ($isEdit): ?>
                  <div>
                    <a href="<?= url('warehouse/min-stock?prodotto_id=' . (int) $entity_id) ?>"
                       class="btn-qk btn-qk-secondary btn-qk-sm" style="display:inline-flex;align-items:center;gap:.4rem">
                      <i class="fa-solid fa-warehouse"></i>
                      <strong><?= e(fmt_qty($giacenza_totale_display ?? 0.0)) ?></strong> <?= e($um) ?>
                      <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:.7rem"></i>
                    </a>
                  </div>
                  <span class="qk-form-hint">Somma su tutte le sedi. Click per vedere il dettaglio per magazzino.</span>
                  <?php else: ?>
                  <div class="qk-form-control-plaintext qk-text-muted" style="padding-top:.4rem">Disponibile dopo il salvataggio</div>
                  <?php endif; ?>
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
                         placeholder="Es. 25 oppure 12.5" min="0.001" step="0.001"
                         title="Massimo 3 cifre decimali (es. 12.5 o 0.75)">
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

          <!-- Banner Informativo Listini Vendita -->
          <div class="qk-alert qk-alert-info d-flex align-items-center mt-3" role="alert" style="padding: 1rem;">
            <i class="fa-solid fa-circle-info me-3 fa-lg"></i>
            <div style="font-size: 0.9rem;">
              <strong>Gestione Listini & Prezzo Base</strong><br>
              In questa matrice configuri i prezzi di questo specifico prodotto per i listini attivi. <br>
              <strong>Best Practice ERP:</strong> Non puoi "creare" un nuovo listino da questa schermata. I listini commerciali (es. <em>Listino B2B</em>, <em>Listino Rivenditori</em>) sono entità globali del sistema e vanno creati nella sezione <strong>Configurazione Listini</strong>. Una volta creati, appariranno automaticamente qui come nuove righe, pronti per essere personalizzati con sconti o scaglioni per questo prodotto. Il <strong>Prezzo Base</strong> (prima riga) funge da fallback quando a un cliente non è assegnato alcun listino.
            </div>
          </div>

          <link rel="stylesheet" href="<?= asset('css/products-form.css') ?>?v=<?= time() ?>">

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

          // Mostra tutti i listini attivi a sistema. Il listino Base (ID 1) è già incluso grazie alla modifica a DB.
          $listini_da_mostrare = [];
          foreach ($listini as $listino) {
              if ($listino['id'] == 1) {
                  $listino['is_base'] = true;
                  array_unshift($listini_da_mostrare, $listino); // Mettilo sempre per primo
              } else {
                  $listini_da_mostrare[] = $listino;
              }
          }
          ?>

          <!-- Banner Prezzi Consigliati -->
          <div id="rrp-banner-container" class="qk-alert qk-alert-info mt-3 d-flex align-items-center justify-content-between" style="padding: 0.5rem 1rem; border-radius: var(--qk-radius); font-size: 0.85rem; min-height: 40px; margin-bottom: 0;">
            <div class="d-flex align-items-center gap-2" style="flex-wrap: wrap;">
              <i class="fa-solid fa-circle-info fa-fw text-info" style="font-size: 1.1rem; margin-top: 0;"></i>
              <strong class="text-dark">Prezzi consigliati dal fornitore:</strong>
              <span id="rrp-banner-value" style="color: var(--qk-text-primary);">Nessun prezzo consigliato</span>
            </div>
            <button type="button" class="btn btn-sm btn-outline-primary" id="btn-open-rrp-drawer" style="padding: 2px 10px; font-size: 0.8rem; height: auto;">
              <i class="fa-solid fa-sliders me-1"></i> Gestisci prezzi consigliati
            </button>
          </div>

          <div class="qk-card mt-3">
            <div class="qk-card-header qk-flex-row qk-justify-between qk-align-center" style="border-bottom:1px solid var(--qk-border); padding:.6rem 1.25rem;">
              <div>
                <span class="qk-card-title">Matrice Prezzi di Vendita & Listini</span>
                <span class="qk-card-subtitle" style="display:block; font-size:.78rem; color:var(--qk-text-muted);">
                  Griglia dei prezzi per scaglione. I valori si salvano in automatico all'uscita dal campo (blur) o premendo Invio. Il prezzo viene calcolato automaticamente (Cascading) in base allo Sconto del Listino sul Prezzo Base (sostituendo il campo diventerà un prezzo in override).
                </span>
              </div>
              <div class="text-end d-flex align-items-center gap-3">
                <div class="qk-flex-row qk-gap-sm d-flex align-items-center" style="gap: .5rem;">
                  <label class="qk-form-label mb-0 text-nowrap" style="font-size:.85rem; font-weight:600; color:var(--qk-text-muted)">Visualizza in:</label>
                  <select id="sales-uom-mode" class="form-select form-select-sm" style="width:auto; padding:2px 28px 2px 8px; font-size:.85rem; height:auto;">
                    <option value="commercial">Unità commerciale (<?= e($um_comm_codice ?: $um) ?>)</option>
                    <option value="stock" selected>Unità di stock (<?= e($um) ?>)</option>
                  </select>
                </div>
              </div>
            </div>
            
            <div class="qk-card-body p-3">
              <div class="qk-table-wrap mt-3 pricing-matrix-container">
                <table class="qk-table pricing-matrix-table" id="pricing-matrix-table">
                  <thead>
                    <tr>
                      <th style="width: 250px; background: var(--qk-hover-bg, rgba(241, 245, 249, 0.6)); color: var(--qk-text-primary); border-bottom: 2px solid var(--qk-border);">Listino (Valuta)</th>
                      <th style="width: 100px; text-align: center; border-bottom: 2px solid var(--qk-border);">Sconto %</th>
                      <th style="width: 200px; background: #f1f5f9; color: #0f172a; border-bottom: 2px solid var(--qk-border); font-weight: 700; text-align: center;">PREZZO BASE [Qtà 0+]</th>
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
                      <th style="width: 60px; text-align: center; border-bottom: 2px solid var(--qk-border); background: var(--qk-table-head-bg);">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-add-qty-column" title="Aggiungi scaglione (colonna)" style="padding: 2px 6px; font-size: 0.75rem;">
                          <i class="fa-solid fa-plus"></i>
                        </button>
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($listini_da_mostrare as $listino): 
                        $lid = (int)$listino['id'];
                        $isBase = !empty($listino['is_base']);
                        
                        // Cerca regola base (qty = 0)
                        $baseRule = $rules_map[$lid]['0'] ?? null;
                        $basePrice = $baseRule ? (float)$baseRule['prezzo_unitario'] : ($isBase ? (float)($v['prezzo_vendita_base'] ?? 0) : null);
                        $baseDiscount = $baseRule ? (float)$baseRule['sconto_perc'] : 0.0;
                        $baseNote = $baseRule ? $baseRule['note'] : '';
                        $baseNote = $baseRule ? $baseRule['note'] : '';
                        $baseRowId = $baseRule ? $baseRule['id'] : 0;
                        $scontoDefault = (float)($listino['sconto_default_perc'] ?? 0.0);
                    ?>
                    <tr class="listino-row" data-listino-id="<?= $isBase ? '' : $lid ?>" data-valuta="<?= e($listino['valuta']) ?>" data-sconto-default="<?= $scontoDefault ?>">
                      <td class="listino-info-cell" style="<?= $isBase ? 'background: #f1f5f9; border-left: 4px solid var(--qk-primary);' : '' ?>">
                        <div class="d-flex flex-column gap-1">
                          <span style="font-size: 0.85rem; font-weight: 700; color: var(--qk-text-primary);"><?= e($listino['nome']) ?></span>
                          <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-light text-dark border" style="font-size: 0.68rem;"><?= e($listino['valuta']) ?></span>
                            <?php if ($isBase): ?>
                              <span class="badge bg-primary text-white" style="font-size: 0.65rem; padding: 2px 5px; font-weight: 600;">PREZZO BASE</span>
                            <?php else: ?>
                              <span class="badge bg-secondary text-white" style="font-size: 0.65rem; padding: 2px 5px; font-weight: 500;">Listino Specifico</span>
                            <?php endif; ?>
                          </div>
                        </div>
                      </td>
                      
                      <!-- Colonna Sconto % -->
                      <td>
                        <div class="matrix-cell-wrapper p-1">
                          <?php if ($isBase): ?>
                            <div class="text-center text-muted" style="font-size:0.75rem">—</div>
                          <?php else: ?>
                            <div class="input-group input-group-sm">
                              <input type="number" class="form-control listino-sconto-input text-end" 
                                     step="0.01" min="0" max="100" placeholder="0.00" readonly
                                     title="Sconto percentuale predefinito impostato nel Listino (sola lettura)"
                                     value="<?= $scontoDefault > 0 ? number_format($scontoDefault, 2, '.', '') : '' ?>">
                              <span class="input-group-text">%</span>
                            </div>
                          <?php endif; ?>
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
                      <td style="background: var(--qk-table-head-bg); border-left: none; border-right: none;"></td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>


          <?php endif; ?>

          <!-- ── Drawer RRP (Prezzi Consigliati) ── -->
          <div class="qk-drawer-overlay" id="rrp-drawer-overlay"></div>
          <div class="qk-drawer" id="rrp-drawer">
            <div class="qk-drawer-header">
              <h5>Prezzi di Rivendita Consigliati</h5>
              <button type="button" class="btn-close" id="btn-close-rrp-drawer" aria-label="Chiudi"></button>
            </div>
             <div class="qk-drawer-body">
               <div class="d-flex justify-content-between align-items-center mb-3">
                 <p class="text-muted small mb-0">Imposta i prezzi di rivendita consigliati (Minimo e Massimo) forniti dai produttori/fornitori per questo prodotto.</p>
                 <div class="qk-flex-row qk-gap-sm d-flex align-items-center" style="gap: .75rem;">
                   
                   <div class="qk-dropdown-container" style="position:relative; display:inline-block; line-height:1;">
                     <button type="button" class="btn-qk btn-qk-secondary btn-qk-xs" id="btn-rrp-col-settings" title="Mostra/Nascondi colonne" style="display: inline-flex; align-items: center; gap: 0.25rem; font-size: 0.78rem;">
                       <i class="fa-solid fa-sliders"></i> Colonne
                     </button>
                     <div class="qk-dropdown-menu shadow-sm" id="rrp-col-settings-dropdown" style="display:none; position:absolute; right:0; top:120%; z-index:1000; background:var(--qk-card-bg); border:1px solid var(--qk-border); border-radius:var(--qk-radius); padding:.5rem .7rem; min-width:160px; text-align:left; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                       <div class="qk-flex-col qk-gap-sm d-flex flex-column" style="gap: .4rem;">
                         <label class="qk-flex-row qk-gap-sm" style="font-size:.78rem;font-weight:500;cursor:pointer;color:var(--qk-text-primary);margin:0; gap:.4rem; display:flex; align-items:center;">
                           <input type="checkbox" class="col-toggle form-check-input" value="min" checked>
                           <span>Prezzo Minimo</span>
                         </label>
                         <label class="qk-flex-row qk-gap-sm" style="font-size:.78rem;font-weight:500;cursor:pointer;color:var(--qk-text-primary);margin:0; gap:.4rem; display:flex; align-items:center;">
                           <input type="checkbox" class="col-toggle form-check-input" value="max" checked>
                           <span>Prezzo Massimo</span>
                         </label>
                         <label class="qk-flex-row qk-gap-sm" style="font-size:.78rem;font-weight:500;cursor:pointer;color:var(--qk-text-primary);margin:0; gap:.4rem; display:flex; align-items:center;">
                           <input type="checkbox" class="col-toggle form-check-input" value="date">
                           <span>Date Validità</span>
                         </label>
                       </div>
                     </div>
                   </div>

                   <div class="d-flex align-items-center gap-1">
                     <label class="qk-form-label mb-0 text-nowrap" style="font-size:.78rem; font-weight:600; color:var(--qk-text-muted)">Visualizza in:</label>
                     <select id="drawer-uom-mode" class="form-select form-select-sm" style="width:auto; padding:2px 28px 2px 8px; font-size:.78rem; height:auto;">
                        <option value="commercial">Unità commerciale (<?= e($um_comm_codice ?: $um) ?>)</option>
                        <option value="stock" selected>Unità di stock (<?= e($um) ?>)</option>
                     </select>
                   </div>
                 </div>
               </div>
               <div id="rrp-drawer-alert" class="qk-alert qk-alert-danger d-none mb-3"></div>
               <table class="table table-sm table-hover mb-0" style="font-size: 0.85rem; table-layout: fixed;">
                    <thead style="background: var(--qk-table-head-bg);">
                        <tr>
                            <th>Fonte (Fornitore)</th>
                            <th data-col="rrp-min" style="width: 90px; text-align: right;">Prezzo Min</th>
                            <th data-col="rrp-max" style="width: 90px; text-align: right;">Prezzo Max</th>
                            <th style="width: 60px; text-align: center;">Valuta</th>
                            <th data-col="rrp-date" style="width: 130px;">Valido dal</th>
                            <th data-col="rrp-date" style="width: 130px;">Valido al</th>
                            <th style="width: 45px; text-align: center;"></th>
                        </tr>
                    </thead>
                    <tbody id="rrp-tbody">
                        <!-- JS will populate this -->
                    </tbody>
               </table>
               <div class="mt-3">
                   <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-add-rrp">
                       <i class="fa-solid fa-plus"></i> Aggiungi Prezzo Consigliato
                   </button>
               </div>
            </div>
            <div class="qk-drawer-footer">
              <button type="button" class="btn-qk btn-qk-secondary" id="btn-cancel-rrp-drawer">Chiudi</button>
            </div>
          </div>

        </div>

        <!-- ──────────────────────────────────────────────────────────────
             TAB 2: COSTI D'ACQUISTO E FORNITORI (FLAT GRID)
        ─────────────────────────────────────────────────────────────────── -->
        <div class="tab-pane fade" id="ptab-fornitori" role="tabpanel">

          <?php if (!$isEdit): ?>
          <div class="qk-card mb-3">
            <div class="qk-card-body text-center py-5">
              <i class="fa-solid fa-floppy-disk fa-2x mb-3" style="color:var(--qk-text-muted)"></i>
              <p style="color:var(--qk-text-muted)">Salva prima il prodotto per poter gestire i costi di acquisto.</p>
            </div>
          </div>
          <?php else: ?>

          <!-- Banner Informativo Costi & Fornitori -->
          <div class="alert alert-info d-flex align-items-center mb-3" role="alert" style="background-color: #e0f2fe; color: #0284c7; border: 1px solid #bae6fd; border-radius: var(--qk-radius); padding: 1rem;">
            <i class="fa-solid fa-circle-info me-3 fa-lg"></i>
            <div style="font-size: 0.9rem;">
              <strong>Costi d'Acquisto e Fornitori (Item Master Costs)</strong><br>
              In questa sezione definisci il costo di riferimento interno per il calcolo dei margini e configuri le anagrafiche dei fornitori. I listini o le condizioni d'acquisto specifiche si trovano nella griglia fornitori sottostante.
            </div>
          </div>
          
          <!-- ── Valutazione Costi Interni ───────────────────────────────── -->
          <div class="qk-card mb-4" style="border:1px solid var(--qk-primary);">
            <div class="qk-card-header" style="background-color:rgba(14, 165, 233, 0.05); border-bottom:1px solid var(--qk-border); padding:.8rem 1.25rem;">
              <h5 class="mb-0" style="font-size:1rem; color:var(--qk-primary); font-weight:600;">
                <i class="fa-solid fa-calculator me-2"></i> Valutazione Costi d'Acquisto (Riferimento Interno)
              </h5>
            </div>
            <div class="qk-card-body p-4">
              <div class="row gx-4 gy-3">
                <div class="col-md-4">
                  <label class="qk-form-label" for="costo_acquisto_base">Costo d'Acquisto Base</label>
                  <div class="input-group input-group-sm">
                    <span class="input-group-text">&euro;</span>
                    <input type="number" step="0.0001" min="0" class="form-control" 
                           id="costo_acquisto_base" name="costo_acquisto_base"
                           value="<?= e($v['costo_acquisto_base']) ?>" placeholder="Es. 12.50">
                  </div>
                  <div class="form-text mt-1" style="font-size:0.75rem;">
                    Costo standard inserito manualmente. Usato come fallback per i calcoli dei margini.
                  </div>
                </div>
                
                <div class="col-md-4">
                  <label class="qk-form-label text-muted" for="costo_acquisto_medio">Costo Medio Ponderato (CMP)</label>
                  <div class="input-group input-group-sm">
                    <span class="input-group-text text-muted bg-light">&euro;</span>
                    <input type="number" class="form-control bg-light" 
                           id="costo_acquisto_medio" name="costo_acquisto_medio"
                           value="<?= e($v['costo_acquisto_medio']) ?>" readonly placeholder="—">
                  </div>
                  <div class="form-text mt-1" style="font-size:0.75rem;">
                    Calcolato automaticamente (sola lettura).
                  </div>
                </div>
                
                <div class="col-md-4">
                  <label class="qk-form-label" for="unita_misura_acquisto_id">UoM di Riferimento</label>
                  <select class="form-select form-select-sm" id="unita_misura_acquisto_id" name="unita_misura_acquisto_id">
                    <?php if (!empty($v['unita_contenuto_id']) && (string)$v['unita_contenuto_id'] !== (string)$um_base_id): ?>
                      <option value="<?= e($v['unita_contenuto_id']) ?>" <?= ((string)$v['unita_misura_acquisto_id'] === (string)$v['unita_contenuto_id']) ? 'selected' : '' ?>>
                        Unità commerciale (<?= e($um_comm_codice) ?>)
                      </option>
                    <?php endif; ?>
                    <?php if ($um_base_id): ?>
                      <option value="<?= e($um_base_id) ?>" <?= (empty($v['unita_misura_acquisto_id']) || (string)$v['unita_misura_acquisto_id'] === (string)$um_base_id) ? 'selected' : '' ?>>
                        Unità di stock (<?= e($um) ?>)
                      </option>
                    <?php endif; ?>
                  </select>
                  <div class="form-text mt-1" style="font-size:0.75rem;">
                    Unità di misura su cui si basano i costi (tipicamente la Stock UoM).
                  </div>
                </div>
              </div>
            </div>
          </div>
          
          <h6 class="mb-3" style="font-weight:700; color:var(--qk-text-primary); font-size:1.05rem; border-bottom: 2px solid var(--qk-border); padding-bottom: 0.5rem;">
            Matrice Fornitori (Approvvigionamento)
          </h6>
          <!-- ── Toolbar ─────────────────────────────────────────────────── -->
          <div class="qk-table-toolbar qk-card mb-3"
               style="border-radius:var(--qk-radius);border:1px solid var(--qk-card-border)">
            <div class="qk-table-toolbar-left">
              <div class="qk-flex-row qk-gap-sm d-flex align-items-center" style="gap: .5rem; padding-left: .5rem;">
                <label class="qk-form-label mb-0 text-nowrap" style="font-size:.85rem; font-weight:600; color:var(--qk-text-muted)">Visualizza in:</label>
                <select id="purchase-uom-mode" class="form-select form-select-sm" style="width:auto; padding:2px 28px 2px 8px; font-size:.85rem; height:auto;">
                  <option value="commercial">Unità commerciale (<?= e($um_comm_codice ?: $um) ?>)</option>
                  <option value="stock" selected>Unità di stock (<?= e($um) ?>)</option>
                </select>
              </div>
            </div>
            <div class="qk-table-toolbar-right d-flex gap-2 align-items-center">
              <div class="qk-dropdown-container" style="position:relative; display:inline-block; line-height:1;">
                <button type="button" class="btn-qk btn-qk-secondary btn-qk-sm" id="btn-col-settings" title="Mostra/Nascondi colonne" style="display: inline-flex; align-items: center; gap: 0.25rem;">
                  <i class="fa-solid fa-sliders"></i> Colonne
                </button>
                <div class="qk-dropdown-menu shadow-sm" id="col-settings-dropdown" style="display:none; position:absolute; right:0; top:120%; z-index:1000; background:var(--qk-card-bg); border:1px solid var(--qk-border); border-radius:var(--qk-radius); padding:.6rem .8rem; min-width:180px; text-align:left; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                  <div class="qk-flex-col qk-gap-sm d-flex flex-column" style="gap: .5rem;">
                    <label class="qk-flex-row qk-gap-sm" style="font-size:.85rem;font-weight:500;cursor:pointer;color:var(--qk-text-primary);margin:0; gap:.5rem; display:flex; align-items:center;">
                      <input type="checkbox" class="col-toggle form-check-input" value="pref" checked>
                      <span>Preferito</span>
                    </label>
                    <label class="qk-flex-row qk-gap-sm" style="font-size:.85rem;font-weight:500;cursor:pointer;color:var(--qk-text-primary);margin:0; gap:.5rem; display:flex; align-items:center;">
                      <input type="checkbox" class="col-toggle form-check-input" value="nome_prod">
                      <span>Nome Prodotto Fornitore</span>
                    </label>
                    <label class="qk-flex-row qk-gap-sm" style="font-size:.85rem;font-weight:500;cursor:pointer;color:var(--qk-text-primary);margin:0; gap:.5rem; display:flex; align-items:center;">
                      <input type="checkbox" class="col-toggle form-check-input" value="sku" checked>
                      <span>SKU Fornitore</span>
                    </label>
                    <label class="qk-flex-row qk-gap-sm" style="font-size:.85rem;font-weight:500;cursor:pointer;color:var(--qk-text-primary);margin:0; gap:.5rem; display:flex; align-items:center;">
                      <input type="checkbox" class="col-toggle form-check-input" value="qta_min" checked>
                      <span>Quantità Minima</span>
                    </label>
                    <label class="qk-flex-row qk-gap-sm" style="font-size:.85rem;font-weight:500;cursor:pointer;color:var(--qk-text-primary);margin:0; gap:.5rem; display:flex; align-items:center;">
                      <input type="checkbox" class="col-toggle form-check-input" value="sconto" checked>
                      <span>Sconto %</span>
                    </label>
                    <label class="qk-flex-row qk-gap-sm" style="font-size:.85rem;font-weight:500;cursor:pointer;color:var(--qk-text-primary);margin:0; gap:.5rem; display:flex; align-items:center;">
                      <input type="checkbox" class="col-toggle form-check-input" value="lead_time" checked>
                      <span>Consegna (gg)</span>
                    </label>
                    <label class="qk-flex-row qk-gap-sm" style="font-size:.85rem;font-weight:500;cursor:pointer;color:var(--qk-text-primary);margin:0; gap:.5rem; display:flex; align-items:center;">
                      <input type="checkbox" class="col-toggle form-check-input" value="valido_dal">
                      <span>Valido Dal</span>
                    </label>
                    <label class="qk-flex-row qk-gap-sm" style="font-size:.85rem;font-weight:500;cursor:pointer;color:var(--qk-text-primary);margin:0; gap:.5rem; display:flex; align-items:center;">
                      <input type="checkbox" class="col-toggle form-check-input" value="valido_al">
                      <span>Valido Al</span>
                    </label>
                  </div>
                </div>
              </div>
              <button type="button"
                      class="btn-qk btn-qk-primary btn-qk-sm"
                      id="btn-add-fornitore-flat">
                <i class="fa-solid fa-plus"></i> Aggiungi riga
              </button>
            </div>
          </div>

          <!-- ── Master Table Flat ────────────────────────────────────────────── -->
          <div class="qk-card" style="overflow-x:auto; min-height: 250px;">
            <table class="qks-master-table" id="master-fornitori-table">
              <thead>
                <tr>
                  <th data-col="pref" title="Fornitore Preferenziale: determina quale costo d'acquisto viene proposto di default nei calcoli e documenti dell'ERP.">Prf</th>
                  <th data-col="fornitore" style="min-width: 200px;">Fornitore</th>
                  <th data-col="nome_prod" style="min-width: 180px; display: none;">Nome Prod. Forn.</th>
                  <th data-col="sku" style="min-width: 120px;">SKU Fornitore</th>
                  <th data-col="prezzo" style="width:110px; text-align:right;">Costo Acq.</th>
                  <th data-col="qta_min" style="width:90px; text-align:right;">Qtà Min.</th>
                  <th data-col="uom" style="width:90px; text-align:center;">UoM</th>
                  <th data-col="sconto" style="width:80px; text-align:right;">Sc. %</th>
                  <th data-col="lead_time" style="width:90px; text-align:center;">Cons. (gg)</th>
                  <th data-col="valido_dal" style="width:130px; text-align:center;">Valido Dal</th>
                  <th data-col="valido_al" style="width:130px; text-align:center;">Valido Al</th>
                  <th data-col="azioni" style="width:80px; text-align:center;"></th>
                </tr>
              </thead>
              <tbody id="master-fornitori-tbody">
                <!-- Popolato dinamicamente da JS flat -->
              </tbody>
            </table>
          </div>

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
                           <?= qk_is_true($v['is_adr'] ?? '0') ? 'checked' : '' ?>
                           onchange="document.getElementById('adr-sostanza-wrap').style.opacity=this.checked?'1':'.4';
                                     document.getElementById('adr-sostanza-wrap').style.pointerEvents=this.checked?'':'none';">
                    <label class="form-check-label fw-semibold" for="is_adr">Prodotto pericoloso ADR</label>
                  </div>
                </div>
              </div>

              <div id="adr-sostanza-wrap"
                   style="<?= !qk_is_true($v['is_adr'] ?? '0') ? 'opacity:.4;pointer-events:none' : '' ?>">
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
                         target="_blank" class="small qk-text-accent text-decoration-none"
                         title="Classificazione ADR/CLP condivisa da questa sostanza — le Schede di Sicurezza (SDS) si gestiscono invece nella tab &quot;Scheda di sicurezza&quot; qui sopra, per fornitore">
                        <i class="fa-solid fa-arrow-up-right-from-square me-1"></i>Apri classificazione ADR/CLP sostanza
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



          <?php endif; ?>

        </div><!-- /ptab-adr (ADR e Restrizioni) -->

        <!-- ──────────────────────────────────────────────────────────────
             TAB: SCHEDA DI SICUREZZA (SDS) — per prodotto-fornitore
             PIANO - Refactoring Schede di Sicurezza (SDS) su Prodotto-Fornitore.md, M5
        ─────────────────────────────────────────────────────────────────── -->
        <div class="tab-pane fade" id="ptab-sds" role="tabpanel">

          <?php if (!$isEdit): ?>
          <div class="qk-card mb-3 mt-3">
            <div class="qk-card-body text-center py-5">
              <i class="fa-solid fa-floppy-disk fa-2x mb-3" style="color:var(--qk-text-muted)"></i>
              <p style="color:var(--qk-text-muted)">Salva prima il prodotto per poter gestire le Schede di Sicurezza (SDS).</p>
            </div>
          </div>
          <?php elseif (empty($prodotto_fornitori)): ?>
          <div class="qk-card mb-3 mt-3">
            <div class="qk-card-body text-center py-5">
              <i class="fa-solid fa-handshake fa-2x mb-3" style="color:var(--qk-text-muted)"></i>
              <p style="color:var(--qk-text-muted)">
                Le Schede di Sicurezza si caricano per fornitore: aggiungi prima almeno un fornitore nella tab
                <a href="#" onclick="document.getElementById('ptab-btn-fornitori').click();return false;">Costi d'Acquisto &amp; Fornitori</a>.
              </p>
            </div>
          </div>
          <?php else: ?>

          <div class="qk-guidance-box mt-3">
            <div class="fs-4"><i class="fa-solid fa-circle-info"></i></div>
            <div>
              <h6 class="mb-1 fw-bold">Una SDS per fornitore e lingua</h6>
              <p class="mb-0 text-muted" style="font-size:0.875rem">
                Ogni fornitore consegna la propria Scheda di Sicurezza, nella lingua del destinatario (REACH).
                Puoi avere più schede correnti in vigore per lo stesso fornitore, una per lingua.
                Le schede storiche restano sempre visibili, anche se il fornitore collegato viene disattivato.
              </p>
            </div>
          </div>

          <!-- ── Toolbar ─────────────────────────────────────────────────── -->
          <div class="qk-table-toolbar qk-card mt-3 mb-3"
               style="border-radius:var(--qk-radius);border:1px solid var(--qk-card-border)">
            <div class="qk-table-toolbar-left">
              <label class="qk-flex-row qk-gap-sm d-flex align-items-center" style="gap:.4rem;font-size:.85rem;cursor:pointer;">
                <input type="checkbox" id="sds-filter-hide-inattivi" class="form-check-input">
                Nascondi fornitori disattivati
              </label>
            </div>
            <div class="qk-table-toolbar-right">
              <button type="button" class="btn-qk btn-qk-primary btn-qk-sm" id="btn-add-sds">
                <i class="fa-solid fa-plus"></i> Carica scheda di sicurezza
              </button>
            </div>
          </div>

          <!-- ── Tabella righe SDS ─────────────────────────────────────── -->
          <div class="qk-card" style="overflow-x:auto; min-height:150px;">
            <div class="qk-empty d-none" id="sds-empty">
              <i class="fa-solid fa-file-shield"></i>
              <p>Nessuna scheda di sicurezza ancora caricata per questo prodotto.</p>
            </div>
            <table class="qk-table" id="sds-table">
              <thead>
                <tr>
                  <th>Fornitore</th>
                  <th style="width:70px;text-align:center;">Lingua</th>
                  <th>Versione</th>
                  <th style="width:120px;">Inserita il</th>
                  <th style="width:110px;">Valido dal</th>
                  <th style="width:110px;">Valido al</th>
                  <th style="width:110px;">Stato</th>
                  <th style="width:120px;text-align:center;">File</th>
                  <th style="width:100px;"></th>
                </tr>
              </thead>
              <tbody id="sds-tbody">
                <!-- Popolato dinamicamente da products-sds.js -->
              </tbody>
            </table>
          </div>

          <?php endif; ?>

          <!-- ── Modale dettaglio SDS (upload + metadati) ──────────────────
               Non un drawer: qui si compila SEMPRE un solo record alla volta
               (a differenza del drawer RRP, che contiene una mini-tabella di
               più righe) — stesso pattern del modale "Aggiungi restrizione"
               poco sopra in questa stessa tab ADR. Scelta rivista il
               22/07/2026: un modale Bootstrap è già responsive di default
               (niente overflow su schermi stretti, a differenza di
               `.qk-drawer` che ha una larghezza fissa di 400px senza
               breakpoint mobile — gap pre-esistente e condiviso col drawer
               RRP, non specifico di qui). -->
          <div class="modal fade" id="modal-sds" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered modal-lg">
              <div class="modal-content">
                <div class="modal-header">
                  <h5 class="modal-title" id="sds-modal-title">Nuova scheda di sicurezza</h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                </div>
                <div class="modal-body">
                  <div id="sds-modal-alert" class="qk-alert qk-alert-danger d-none mb-3"></div>

                  <input type="hidden" id="sds-form-id" value="0">

                  <div class="qk-form-group">
                    <label class="qk-form-label">Fornitore <span class="required">*</span></label>
                    <select id="sds-sel-fornitore" class="qk-form-control"></select>
                    <span class="qk-form-hint">Solo i fornitori attivi di questo prodotto compaiono qui.</span>
                  </div>

                  <div class="qk-form-row">
                    <div class="qk-form-group">
                      <label class="qk-form-label">Lingua <span class="required">*</span></label>
                      <select id="sds-sel-lingua" class="searchable-select qk-form-control">
                        <option value="">— Seleziona lingua —</option>
                        <?php foreach ($iso_lingue as $lng): ?>
                        <option value="<?= e($lng['codice']) ?>"><?= e($lng['nome']) ?> (<?= e($lng['codice']) ?>)</option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="qk-form-group">
                      <label class="qk-form-label">Versione</label>
                      <input type="text" id="sds-versione" class="qk-form-control" placeholder="es. 2.1">
                    </div>
                  </div>

                  <div class="qk-form-row">
                    <div class="qk-form-group">
                      <label class="qk-form-label">Data emissione <span class="qk-form-hint">(dal fornitore)</span></label>
                      <input type="date" id="sds-data-emissione" class="qk-form-control">
                    </div>
                  </div>

                  <div class="qk-form-row">
                    <div class="qk-form-group">
                      <label class="qk-form-label">Valido dal <span class="qk-form-hint">vuoto = nessun limite</span></label>
                      <input type="date" id="sds-valido-dal" class="qk-form-control">
                    </div>
                    <div class="qk-form-group">
                      <label class="qk-form-label">Valido al <span class="qk-form-hint">vuoto = senza scadenza</span></label>
                      <input type="date" id="sds-valido-al" class="qk-form-control">
                    </div>
                  </div>

                  <div class="qk-form-group">
                    <label class="qk-form-label">File PDF/DOC/DOCX <span id="sds-file-required-mark" class="required">*</span></label>
                    <input type="file" id="sds-file-input" accept=".pdf,.doc,.docx" class="qk-form-control">
                    <div id="sds-file-current" class="qk-form-hint mt-1"></div>
                  </div>

                  <div class="qk-form-group mb-0">
                    <label class="qk-form-label">Note</label>
                    <textarea id="sds-note" class="qk-form-control" rows="3"></textarea>
                  </div>
                </div>
                <div class="modal-footer d-flex justify-content-between">
                  <div>
                    <button type="button" class="btn-qk btn-qk-danger btn-qk-sm d-none" id="btn-sds-delete">
                      <i class="fa-solid fa-trash"></i> Elimina
                    </button>
                    <button type="button" class="btn-qk btn-qk-secondary btn-qk-sm d-none" id="btn-sds-rimuovi-file">
                      <i class="fa-solid fa-file-slash"></i> Rimuovi solo il file
                    </button>
                  </div>
                  <div>
                    <button type="button" class="btn-qk btn-qk-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="button" class="btn-qk btn-qk-primary" id="btn-save-sds">
                      <i class="fa-solid fa-floppy-disk"></i> Salva
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>

        </div><!-- /ptab-sds -->

      </div><!-- /tab-content -->
</form>

<?php if ($isEdit): ?>
<form id="form-delete-prodotto" action="<?= url('products/delete') ?>" method="post" style="display:none;" onsubmit="return confirm('Eliminare definitivamente questo prodotto? Questa operazione non può essere annullata.');">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int)$entity_id ?>">
</form>

<?php endif; ?>

<?php include __DIR__ . '/_form_modals.php'; ?>

<!-- ═══════════════════════ JS ════════════════════════════════════════ -->
<script src="<?= asset('js/products-form.js') ?>?v=<?= time() ?>"></script>
<?php if ($isEdit): ?>
<script src="<?= asset('js/products-suppliers.js') ?>?v=<?= time() ?>"></script>
<script src="<?= asset('js/searchable-select.js') ?>?v=<?= time() ?>"></script>
<script src="<?= asset('js/products-sds.js') ?>?v=<?= time() ?>"></script>
<?php endif; ?>