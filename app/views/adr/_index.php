<?php
/**
 * adr/_index.php — Vista principale ADR con 6 tab
 *
 * Variabili attese:
 *   $tab                     string  tab attiva: codici|classi|imballaggio|gallerie|categorie|classificazioni
 *   $ok                      ?string messaggio successo inline
 *   $errors                  array   errori inline
 *   $search                  string  testo ricerca codici ONU
 *   $codici                  array   righe adr_codici_onu
 *   $classi                  array   righe adr_classi_pericolo
 *   $imballaggi              array   righe adr_gruppi_imballaggio
 *   $gallerie                array   righe adr_codici_restrizione_gallerie
 *   $categorie_trasporto     array   righe adr_categorie_trasporto
 *   $codici_classificazione  array   righe adr_codici_classificazione
 */

$page_actions = '<a href="' . url('adr/codici/create') . '" class="btn-qk btn-qk-primary">
    <i class="fa-solid fa-plus"></i> Nuovo codice ONU
</a>';

/* JSON per il JS (export / import) */
$codici_json = json_encode($codici, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
$import_url  = url('adr/codici/import');
$classificazioni_json = json_encode($codici_classificazione, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
$import_classificazioni_url  = url('adr/classificazioni/import');
?>

<?php if (!empty($errors['_global'])): ?>
<div class="qk-alert qk-alert-danger mb-3">
  <i class="fa-solid fa-circle-xmark"></i>
  <span><?= e($errors['_global']) ?></span>
</div>
<?php endif; ?>

<!-- ═══════════════════════ TAB NAV ═══════════════════════════════════════ -->
<ul class="nav nav-tabs qk-tabs mb-0" id="adrTabs" role="tablist">
  <li class="nav-item" role="presentation">
    <button class="nav-link <?= $tab === 'codici'      ? 'active' : '' ?>"
            id="tab-btn-codici" data-bs-toggle="tab" data-bs-target="#tab-codici"
            data-tab-key="codici" type="button" role="tab">
      <i class="fa-solid fa-list-ol fa-fw me-1"></i>Codici ONU
      <span class="badge bg-secondary ms-1"><?= count($codici) ?></span>
    </button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link <?= $tab === 'classi'      ? 'active' : '' ?>"
            id="tab-btn-classi" data-bs-toggle="tab" data-bs-target="#tab-classi"
            data-tab-key="classi" type="button" role="tab">
      <i class="fa-solid fa-fire-flame-curved fa-fw me-1"></i>Classi pericolo
      <span class="badge bg-secondary ms-1"><?= count($classi) ?></span>
    </button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link <?= $tab === 'imballaggio' ? 'active' : '' ?>"
            id="tab-btn-imballaggio" data-bs-toggle="tab" data-bs-target="#tab-imballaggio"
            data-tab-key="imballaggio" type="button" role="tab">
      <i class="fa-solid fa-box-taped fa-fw me-1"></i>Gruppi imballaggio
      <span class="badge bg-secondary ms-1"><?= count($imballaggi) ?></span>
    </button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link <?= $tab === 'gallerie'   ? 'active' : '' ?>"
            id="tab-btn-gallerie" data-bs-toggle="tab" data-bs-target="#tab-gallerie"
            data-tab-key="gallerie" type="button" role="tab">
      <i class="fa-solid fa-car-tunnel fa-fw me-1"></i>Codici galleria
      <span class="badge bg-secondary ms-1"><?= count($gallerie) ?></span>
    </button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link <?= $tab === 'categorie' ? 'active' : '' ?>"
            id="tab-btn-categorie" data-bs-toggle="tab" data-bs-target="#tab-categorie"
            data-tab-key="categorie" type="button" role="tab">
      <i class="fa-solid fa-truck fa-fw me-1"></i>Categorie trasporto
      <span class="badge bg-secondary ms-1"><?= count($categorie_trasporto) ?></span>
    </button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link <?= $tab === 'classificazioni' ? 'active' : '' ?>"
            id="tab-btn-classificazioni" data-bs-toggle="tab" data-bs-target="#tab-classificazioni"
            data-tab-key="classificazioni" type="button" role="tab">
      <i class="fa-solid fa-barcode fa-fw me-1"></i>Codici classificazione
      <span class="badge bg-secondary ms-1"><?= count($codici_classificazione) ?></span>
    </button>
  </li>
</ul>

<!-- ═══════════════════════ TAB CONTENT ══════════════════════════════════ -->
<div class="tab-content mt-0">

  <?php include APP_ROOT_DIR . '/app/views/adr/tabs/_codici.php'; ?>
  <?php include APP_ROOT_DIR . '/app/views/adr/tabs/_classi.php'; ?>
  <?php include APP_ROOT_DIR . '/app/views/adr/tabs/_imballaggio.php'; ?>
  <?php include APP_ROOT_DIR . '/app/views/adr/tabs/_gallerie.php'; ?>
  <?php include APP_ROOT_DIR . '/app/views/adr/tabs/_categorie.php'; ?>
  <?php include APP_ROOT_DIR . '/app/views/adr/tabs/_classificazioni.php'; ?>
</div>

<?php include APP_ROOT_DIR . '/app/views/adr/modals/_lookup.php'; ?>
<script>
/* Config: PHP -> JS, letta da assets/js/adr_index.js.
   NOTA (23/07/2026): il file JS referenziato qui sotto (assets/js/adr_index.js)
   non esisteva affatto -- lo script tag puntava a un file mai creato (404
   silenzioso, nessun JS caricato in questa pagina). Il contenuto reale era
   rimasto in public/assets/js/adr_index.js, orfano e mai referenziato da
   nessuna view, con tag di output PHP letterali mai elaborati. Ricreato qui
   in assets/js/ (percorso che asset() si aspetta davvero) con gli stessi
   valori letti da un oggetto di configurazione invece che da tag inline. */
window.QkAdrIndex = {
  codiciData: <?= $codici_json ?>,
  importUrl: <?= json_encode($import_url) ?>,
  classificazioniData: <?= $classificazioni_json ?>,
  importClassificazioniUrl: <?= json_encode($import_classificazioni_url) ?>
};
</script>
<script src="<?= asset('js/exceljs.min.js') ?>"></script>
<script src="<?= asset('js/adr_index.js') ?>"></script>
