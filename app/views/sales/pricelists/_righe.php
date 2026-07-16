<?php
/**
 * sales/pricelists/_righe.php
 * Tabella prezzi prodotto con inline row editing.
 * Variabili: $id (int), $items (array), $values (array per valuta)
 */
?>

<link rel="stylesheet" href="<?= asset('css/sales-pricelists-righe.css') ?>?v=<?= time() ?>">

<!-- ── Card tabella ── -->
<div class="qk-card shadow-sm">
  <div class="qk-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span class="qk-card-title m-0">
      <i class="fa-solid fa-badge-percent me-2" style="color:var(--qk-accent)"></i>Prezzi prodotto
    </span>
    <div class="d-flex align-items-center gap-2">
      <div class="d-flex align-items-center gap-2 me-2">
        <label class="mb-0 fw-semibold qk-text-muted text-nowrap" style="font-size: .85rem;">Visualizza in:</label>
        <select id="uom-toggle" class="qk-form-control py-1" style="width: auto; font-size: .85rem;">
          <option value="stock" selected>Unità di Stock</option>
          <option value="comm">Unità Commerciale</option>
        </select>
      </div>
      <input type="text" id="pl-grid-search" class="qk-form-control qk-form-control-sm" placeholder="Filtra per nome o SKU..." style="width: 220px; padding: .25rem .5rem; font-size: .8rem; margin: 0; text-align: left;">
      <button type="button" class="btn-qk btn-qk-secondary btn-qk-sm" data-bs-toggle="modal" data-bs-target="#modalImportaPrezziSales">
        <i class="fa-solid fa-file-import me-1"></i>Importa (XLSX)
      </button>
      <button type="button" id="btn-add-riga" class="btn-qk btn-qk-primary btn-qk-sm">
        <i class="fa-solid fa-plus me-1"></i>Aggiungi prodotto
      </button>
    </div>
  </div>

  <div class="qk-table-wrap" style="min-height: 320px;">
    <!-- Empty state -->
    <?php if (empty($items)): ?>
    <div id="pl-empty">
      <i class="fa-solid fa-badge-percent"></i>
      <p>Nessun prezzo configurato.</p>
      <p style="font-size:.8rem;opacity:.7">Clicca "Aggiungi prodotto" per iniziare.</p>
    </div>
    <?php endif; ?>

    <table class="qk-table" id="tbl-prezzi" <?= empty($items) ? 'style="display:none"' : '' ?>>
      <colgroup>
        <col class="pl-td-product">
        <col class="pl-td-um">
        <col class="pl-td-qmin">
        <col class="pl-td-prezzo">
        <col class="pl-td-sconto">
        <col class="pl-td-note">
        <col class="pl-td-actions">
      </colgroup>
      <thead>
        <tr>
          <th>
            <a href="?id=<?= $id ?>&sort=nome&dir=<?= ($sort === 'nome' && $dir === 'asc') ? 'desc' : 'asc' ?>" class="qk-sort-link text-decoration-none qk-text-dark">
              Prodotto <?= ($sort === 'nome') ? '<i class="fa-solid fa-sort-'.($dir==='asc'?'up':'down').' ms-1"></i>' : '' ?>
            </a>
          </th>
          <th>UM</th>
          <th class="text-end">
            <a href="?id=<?= $id ?>&sort=quantita_minima&dir=<?= ($sort === 'quantita_minima' && $dir === 'asc') ? 'desc' : 'asc' ?>" class="qk-sort-link text-decoration-none qk-text-dark">
              Q.tà min <?= ($sort === 'quantita_minima') ? '<i class="fa-solid fa-sort-'.($dir==='asc'?'up':'down').' ms-1"></i>' : '' ?>
            </a>
          </th>
          <th class="text-end">
            <a href="?id=<?= $id ?>&sort=prezzo&dir=<?= ($sort === 'prezzo' && $dir === 'asc') ? 'desc' : 'asc' ?>" class="qk-sort-link text-decoration-none qk-text-dark">
              Prezzo unitario <?= ($sort === 'prezzo') ? '<i class="fa-solid fa-sort-'.($dir==='asc'?'up':'down').' ms-1"></i>' : '' ?>
            </a>
          </th>
          <th class="text-end">Sconto %</th>
          <th>Note</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="pl-tbody">
        <?php foreach ($items as $item): ?>
          <?php include __DIR__ . '/_riga.php'; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  
  <?php if (!empty($items) && isset($pages) && $pages > 1): ?>
    <div class="mt-3 mx-3 mb-3">
      <?= qk_pagination($page, $pages, '?id=' . $id . '&sort=' . $sort . '&dir=' . $dir . '&page=') ?>
    </div>
  <?php endif; ?>
</div>

<!-- ── Template per nuova riga (inizia in edit mode) ── -->
<template id="pl-tpl-new">
  <tr class="pl-riga is-editing pl-riga-new" data-riga-id="0" data-prodotto-id="">

    <td class="pl-td-product">
      <div class="pl-edit">
        <div class="qk-m2o-wrap position-relative">
          <input type="hidden" class="r-prodotto-id" value="">
          <div class="d-flex align-items-center gap-1">
            <div class="position-relative flex-grow-1">
              <input type="text" class="qk-form-control qk-form-control-sm r-prodotto-label qk-m2o-input"
                     placeholder="Cerca prodotto…" autocomplete="off" style="text-align:left; padding-right: 24px;">
              <button type="button" class="qk-m2o-clear d-none r-prodotto-clear" style="right: 8px;">&times;</button>
            </div>
            <a href="#" class="btn-qk btn-qk-secondary btn-qk-sm pl-link-scheda" 
               target="_blank" title="Apri scheda prodotto" style="padding: 0.2rem 0.4rem; font-size: 0.75rem; display: none;">
              <i class="fa-solid fa-arrow-up-right-from-square"></i>
            </a>
          </div>
          <div class="qk-m2o-dropdown r-prodotto-dropdown"></div>
        </div>
      </div>
    </td>

    <td class="pl-td-um">
      <span class="pl-view pl-um-label">—</span>
      <select class="pl-edit qk-form-control qk-form-control-sm r-unita-misura-id" style="padding: .2rem .35rem; font-size: .8rem; text-align: left; display: none;">
      </select>
    </td>

    <td class="pl-td-qmin">
      <input class="pl-edit qk-form-control qk-form-control-sm text-end r-qmin" type="number" min="0" step="0.001" value="0" placeholder="0">
    </td>

    <td class="pl-td-prezzo">
      <input class="pl-edit qk-form-control qk-form-control-sm text-end r-prezzo" type="number" min="0" step="0.0001" placeholder="0.0000">
    </td>

    <td class="pl-td-sconto">
      <input class="pl-edit qk-form-control qk-form-control-sm text-end r-sconto" type="number" min="0" max="100" step="0.01" value="0" placeholder="0">
    </td>

    <td class="pl-td-note">
      <input class="pl-edit qk-form-control qk-form-control-sm r-note" type="text" placeholder="Note…" style="text-align:left">
    </td>

    <td class="pl-td-actions">
      <button type="button" class="btn-qk btn-qk-secondary btn-qk-sm btn-cancel-riga" title="Annulla (Esc)">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </td>




<!-- Modal per Importazione Massiva Prezzi Vendita -->
<div class="modal fade" id="modalImportaPrezziSales" tabindex="-1" aria-labelledby="modalImportaPrezziSalesTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modalImportaPrezziSalesTitle">
          <i class="fa-solid fa-file-import me-2"></i>Importazione Massiva Prezzi Vendita
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
      </div>
      <div class="modal-body">
        <div class="qk-alert qk-alert-info mb-3">
          <i class="fa-solid fa-circle-info"></i>
          <span>Carica un file Excel (.xlsx) per importare o aggiornare i prezzi di vendita degli articoli. Se un prezzo esiste già per lo stesso articolo e scaglione quantità, verrà aggiornato.</span>
        </div>
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div>
            <input type="file" id="inputFilePrezziSales" accept=".xlsx" class="d-none">
            <label for="inputFilePrezziSales" class="btn-qk btn-qk-primary">
              <i class="fa-solid fa-file-excel me-1"></i>Seleziona File Excel...
            </label>
            <button type="button" class="btn-qk btn-qk-secondary" id="btnClearFilePrezziSales">
              <i class="fa-solid fa-trash me-1"></i>Rimuovi File
            </button>
          </div>
          <button type="button" class="btn-qk btn-qk-secondary" id="btnDownloadTracciatoPrezziSales">
            <i class="fa-solid fa-file-arrow-down me-1"></i>Scarica Modello Tracciato
          </button>
        </div>

        <div id="importPreviewWrapPrezziSales" style="display:none" class="mb-3">
          <h6 class="fw-bold mb-2">Anteprima Righe (<span id="importPreviewCountPrezziSales">0</span> righe trovate, mostra max 10)</h6>
          <div class="qk-table-wrap" style="max-height: 250px; overflow-y: auto;">
            <table class="qk-table mb-0" style="font-size: .8rem">
              <thead id="importPreviewHeadPrezziSales"></thead>
              <tbody id="importPreviewBodyPrezziSales"></tbody>
            </table>
          </div>
        </div>

        <div id="importResultPrezziSales" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-qk btn-qk-secondary" data-bs-dismiss="modal">Chiudi</button>
        <button type="button" class="btn-qk btn-qk-primary" id="btnImportaPrezziSales" disabled>
          <i class="fa-solid fa-upload me-1"></i>Avvia Importazione
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ExcelJS Library for reading/writing xlsx -->
<script src="<?= asset('js/exceljs.min.js') ?>"></script>

<script defer src="<?= asset('js/sales-pricelists-righe.js') ?>?v=<?= time() ?>"></script>
<script defer src="<?= asset('js/sales-pricelists-import.js') ?>?v=<?= time() ?>"></script>

