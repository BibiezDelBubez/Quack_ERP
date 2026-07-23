<?php
/**
 * purchases/pricelists_form.php — Form di creazione/modifica Listino Acquisto.
 * Variabili: $isEdit, $id, $values, $errors, $items
 */
?>

<?php $active_tab = 'pricelists'; include __DIR__ . '/_subnav.php'; ?>

<?php if (!empty($errors['_global'])): ?>
  <div class="qk-alert qk-alert-danger mb-3">
    <i class="fa-solid fa-circle-exclamation"></i>
    <span><?= e($errors['_global']) ?></span>
  </div>
<?php endif; ?>

<form action="" method="post" class="mb-4">
  <?= csrf_field() ?>

  <!-- Card Testata Principale -->
  <div class="qk-card shadow-sm mb-4">
    <div class="qk-card-header">
      <span class="qk-card-title">
        <i class="fa-solid fa-file-invoice-dollar me-2" style="color:var(--qk-accent)"></i>
        <?= $isEdit ? e($values['nome_listino']) : 'Nuovo listino acquisto' ?>
      </span>
      <?php if ($isEdit): ?>
        <span style="font-size:.8rem;color:var(--qk-text-muted)">ID #<?= (int)($id ?? 0) ?></span>
      <?php endif; ?>
    </div>

    <div class="qk-card-body">
      <div class="row g-3">
        <!-- Nome Listino -->
        <div class="col-md-6">
          <div class="qk-form-group mb-0">
            <label class="qk-form-label">Nome listino <span class="required">*</span></label>
            <input type="text" name="nome_listino"
                   class="qk-form-control <?= isset($errors['nome_listino']) ? 'is-invalid' : '' ?>"
                   placeholder="Es. Listino Base 2026, Promo Estate"
                   value="<?= e($values['nome_listino']) ?>">
            <?php if (isset($errors['nome_listino'])): ?>
              <div class="qk-form-error"><i class="fa-solid fa-circle-exclamation"></i> <?= e($errors['nome_listino']) ?></div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Fornitore -->
        <div class="col-md-6">
          <div class="qk-form-group mb-0">
            <label class="qk-form-label">Fornitore <span class="required">*</span></label>
            <div class="qk-m2o-wrap position-relative">
              <input type="hidden" name="fornitore_id" id="pl-fornitore-id" value="<?= e($values['fornitore_id']) ?>">
              <input type="text" class="qk-form-control qk-m2o-input <?= isset($errors['fornitore_id']) ? 'is-invalid' : '' ?>"
                     id="pl-fornitore-label" placeholder="Cerca fornitore…"
                     value="<?= e($values['fornitore_label'] ?? '') ?>"
                     autocomplete="off" <?= $isEdit ? 'disabled' : '' ?>>
              <button type="button" class="qk-m2o-clear d-none" id="pl-fornitore-clear" <?= $isEdit ? 'style="display:none"' : '' ?>>&times;</button>
              <div class="qk-m2o-dropdown shadow-sm" id="pl-fornitore-dropdown"></div>
            </div>
            <?php if (isset($errors['fornitore_id'])): ?>
              <div class="qk-form-error"><i class="fa-solid fa-circle-exclamation"></i> <?= e($errors['fornitore_id']) ?></div>
            <?php endif; ?>
            <?php if ($isEdit): ?>
              <div class="qk-form-hint">Il fornitore non può essere modificato dopo la creazione.</div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Date validità -->
        <div class="col-md-6">
          <div class="qk-form-group mb-0">
            <label class="qk-form-label">Valido dal</label>
            <input type="date" name="valido_dal" class="qk-form-control" value="<?= e($values['valido_dal']) ?>">
          </div>
        </div>
        <div class="col-md-6">
          <div class="qk-form-group mb-0">
            <label class="qk-form-label">Valido al</label>
            <input type="date" name="valido_al" class="qk-form-control" value="<?= e($values['valido_al']) ?>">
          </div>
        </div>
      </div>
    </div>

    <div class="qk-card-footer qk-flex-row qk-justify-between">
      <a href="<?= url('purchases/pricelists') ?>" class="btn-qk btn-qk-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i>Annulla
      </a>
      <button type="submit" class="btn-qk btn-qk-primary">
        <i class="fa-solid fa-floppy-disk me-1"></i>Salva listino
      </button>
    </div>
  </div>

  <?php if ($isEdit): ?>
    <!-- Sub-tabs (Odoo-style) -->
    <div class="qk-tabs-container mb-4">
      <ul class="nav nav-tabs qk-tabs border-bottom-0" id="pricelistPurchaseTabs" role="tablist" style="gap:4px">
        <li class="nav-item" role="presentation">
          <button class="nav-link active fw-semibold" id="p-items-tab" data-bs-toggle="tab" data-bs-target="#p-items-pane" type="button" role="tab" aria-controls="p-items-pane" aria-selected="true" style="padding:.75rem 1.25rem;border-radius:var(--qk-radius) var(--qk-radius) 0 0">
            <i class="fa-solid fa-file-spreadsheet me-2 text-primary"></i>Articoli e Tariffe
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link fw-semibold" id="p-notes-tab" data-bs-toggle="tab" data-bs-target="#p-notes-pane" type="button" role="tab" aria-controls="p-notes-pane" aria-selected="false" style="padding:.75rem 1.25rem;border-radius:var(--qk-radius) var(--qk-radius) 0 0">
            <i class="fa-solid fa-circle-info me-2 text-primary"></i>Note e Informazioni
          </button>
        </li>
      </ul>

      <div class="tab-content" id="pricelistPurchaseTabsContent">
        
        <!-- TAB 1: Articoli e Tariffe -->
        <div class="tab-pane fade show active" id="p-items-pane" role="tabpanel" aria-labelledby="p-items-tab" tabindex="0">
          <div class="qk-card shadow-sm">
            <div class="qk-card-header d-flex justify-content-between align-items-center">
              <span class="qk-card-title m-0">
                <i class="fa-solid fa-file-spreadsheet me-2" style="color:var(--qk-accent)"></i>Tariffe concordate per articolo
              </span>
              <div class="d-flex align-items-center gap-2">
                <input type="text" id="pl-grid-search-acquisto" class="qk-form-control py-1 px-2" placeholder="Filtra per nome o SKU..." style="font-size: .82rem !important; height: auto !important; min-height: 0 !important; width: 220px; margin: 0;">
                
                <div class="qk-dropdown-container" style="position:relative; display:inline-block; line-height:1;">
                  <button type="button" class="btn-qk btn-qk-secondary btn-qk-sm" id="btn-col-settings" title="Mostra/Nascondi colonne">
                    <i class="fa-solid fa-sliders"></i> Colonne
                  </button>
                  <div class="qk-dropdown-menu shadow-sm" id="col-settings-dropdown" style="display:none; position:absolute; right:0; top:120%; z-index:1000; background:var(--qk-card-bg); border:1px solid var(--qk-border); border-radius:var(--qk-radius); padding:.6rem .8rem; min-width:180px; text-align:left; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                    <div class="qk-flex-col qk-gap-sm">
                      <label class="qk-flex-row qk-gap-sm" style="font-size:.85rem;font-weight:500;cursor:pointer;color:var(--qk-text-primary);margin:0">
                        <input type="checkbox" id="chk-show-sku-interno" style="transform:scale(1.15);" checked>
                        <span>SKU Interno</span>
                      </label>
                      <label class="qk-flex-row qk-gap-sm" style="font-size:.85rem;font-weight:500;cursor:pointer;color:var(--qk-text-primary);margin:0">
                        <input type="checkbox" id="chk-show-sku-fornitore" style="transform:scale(1.15);" checked>
                        <span>SKU Fornitore</span>
                      </label>
                      <label class="qk-flex-row qk-gap-sm" style="font-size:.85rem;font-weight:500;cursor:pointer;color:var(--qk-text-primary);margin:0">
                        <input type="checkbox" id="chk-show-sconto-perc" style="transform:scale(1.15);" checked>
                        <span>Sconto %</span>
                      </label>
                      <label class="qk-flex-row qk-gap-sm" style="font-size:.85rem;font-weight:500;cursor:pointer;color:var(--qk-text-primary);margin:0">
                        <input type="checkbox" id="chk-show-validita" style="transform:scale(1.15);">
                        <span>Validità Riga</span>
                      </label>
                      <label class="qk-flex-row qk-gap-sm" style="font-size:.85rem;font-weight:500;cursor:pointer;color:var(--qk-text-primary);margin:0">
                        <input type="checkbox" id="chk-show-note" style="transform:scale(1.15);" checked>
                        <span>Note Prezzo</span>
                      </label>
                    </div>
                  </div>
                </div>

                <button type="button" class="btn-qk btn-qk-secondary btn-qk-sm" data-bs-toggle="modal" data-bs-target="#modalImportaPrezziAcquisto">
                  <i class="fa-solid fa-file-import me-1"></i>Importa (XLSX)
                </button>
                <button type="button" id="btn-add-riga-acquisto" class="btn-qk btn-qk-primary btn-qk-sm">
                  <i class="fa-solid fa-plus me-1"></i>Aggiungi prodotto
                </button>
              </div>
            </div>

            <style>
              .pl-riga:not(.is-editing) .pl-edit { display: none !important; }
              .pl-riga.is-editing .pl-view       { display: none !important; }
              .pl-riga.is-editing > td { background-color: var(--qk-accent-light) !important; }
              .pl-riga.is-editing > td:first-child { box-shadow: inset 3px 0 0 var(--qk-accent); }
              .pl-btn-confirm { border-color: var(--qk-success); color: var(--qk-success); }
              .pl-btn-confirm:hover { background: var(--qk-success-bg); }
              .pl-btn-confirm.is-saving::before {
                content: ''; display: inline-block; width: 10px; height: 10px;
                border: 2px solid currentColor; border-top-color: transparent;
                border-radius: 50%; animation: pl-spin .6s linear infinite; margin-right: 0;
              }
              .pl-btn-confirm.is-saving i { display: none; }
              @keyframes pl-spin { to { transform: rotate(360deg); } }
              .pl-riga-saved > td { animation: pl-saved-flash .8s ease-out forwards; }
              @keyframes pl-saved-flash {
                0%   { background-color: var(--qk-success-bg); }
                100% { background-color: transparent; }
              }
              #tbl-prezzi-acquisto {
                table-layout: fixed;
                width: 100%;
              }
              /* Riduci padding nelle celle per massimizzare lo spazio */
              #tbl-prezzi-acquisto td, #tbl-prezzi-acquisto th {
                padding: .5rem .4rem !important;
                vertical-align: top !important;
              }
              /* Rendi gli input in tabella molto compatti */
              #tbl-prezzi-acquisto .qk-form-control,
              #tbl-prezzi-acquisto .qk-m2o-input {
                padding: .2rem .35rem !important;
                font-size: .82rem !important;
                height: auto !important;
                min-height: 0 !important;
              }
              #tbl-prezzi-acquisto select {
                padding: .2rem .35rem !important;
                font-size: .82rem !important;
                height: auto !important;
              }
            </style>

            <div class="qk-table-wrap" style="min-height: 320px;">
              <!-- Empty State -->
              <div id="pl-empty-acquisto" class="text-center py-5 qk-text-muted" <?= !empty($items) ? 'style="display:none"' : '' ?>>
                <i class="fa-solid fa-file-spreadsheet fs-2 mb-2"></i>
                <p class="mb-0 fw-semibold">Nessun articolo associato a questo listino d'acquisto.</p>
                <p class="small text-muted">Clicca su "Aggiungi prodotto" per definire una tariffa concordata.</p>
              </div>

              <div class="d-flex justify-content-between align-items-center mb-3" <?= empty($items) ? 'style="display:none"' : '' ?>>
                <div class="qk-m2o-wrap" style="width: 300px;">
                   <!-- Placeholder for search if needed -->
                </div>
                <div class="d-flex align-items-center gap-2">
                  <label class="mb-0 fw-semibold qk-text-muted text-nowrap" style="font-size: .85rem;">Visualizza in:</label>
                  <select id="uom-toggle" class="qk-form-control py-1" style="width: auto; font-size: .85rem;">
                    <option value="stock" selected>Unità di Stock</option>
                    <option value="comm">Unità Commerciale</option>
                  </select>
                </div>
              </div>

              <table class="qk-table" id="tbl-prezzi-acquisto" <?= empty($items) ? 'style="display:none"' : '' ?>>
                <thead>
                  <tr>
                    <th>
                      <a href="?id=<?= $id ?>&sort=nome&dir=<?= ($sort === 'nome' && $dir === 'asc') ? 'desc' : 'asc' ?>" class="qk-sort-link text-decoration-none qk-text-dark">
                        Prodotto <?= ($sort === 'nome') ? '<i class="fa-solid fa-sort-'.($dir==='asc'?'up':'down').' ms-1"></i>' : '' ?>
                      </a>
                    </th>
                    <th class="col-sku-interno" style="width:140px">
                      <a href="?id=<?= $id ?>&sort=sku_interno&dir=<?= ($sort === 'sku_interno' && $dir === 'asc') ? 'desc' : 'asc' ?>" class="qk-sort-link text-decoration-none qk-text-dark">
                        SKU Interno <?= ($sort === 'sku_interno') ? '<i class="fa-solid fa-sort-'.($dir==='asc'?'up':'down').' ms-1"></i>' : '' ?>
                      </a>
                    </th>
                    <th class="col-sku-fornitore" style="width:140px">SKU Fornitore</th>
                    <th class="text-end" style="width:110px">
                      <a href="?id=<?= $id ?>&sort=quantita_minima&dir=<?= ($sort === 'quantita_minima' && $dir === 'asc') ? 'desc' : 'asc' ?>" class="qk-sort-link text-decoration-none qk-text-dark">
                        Q.tà min <?= ($sort === 'quantita_minima') ? '<i class="fa-solid fa-sort-'.($dir==='asc'?'up':'down').' ms-1"></i>' : '' ?>
                      </a>
                    </th>
                    <th class="text-end" style="width:160px">
                      <a href="?id=<?= $id ?>&sort=prezzo&dir=<?= ($sort === 'prezzo' && $dir === 'asc') ? 'desc' : 'asc' ?>" class="qk-sort-link text-decoration-none qk-text-dark">
                        Prezzo Unitario <?= ($sort === 'prezzo') ? '<i class="fa-solid fa-sort-'.($dir==='asc'?'up':'down').' ms-1"></i>' : '' ?>
                      </a>
                    </th>
                    <th class="text-end col-sconto-perc" style="width:100px">Sconto %</th>
                    <th class="col-validita" style="width:220px">Validità Riga</th>
                    <th class="col-note-prezzo">Note Prezzo</th>
                    <th style="width:72px"></th>
                  </tr>
                </thead>
                <tbody id="pl-tbody-acquisto">
                  <?php foreach ($items as $item): 
                    $uomNative = $item['um_native_code'] ?? $item['um_comm_code'] ?? $item['um_stock_code'] ?? 'PZ';
                    $uomStock  = $item['um_stock_code'] ?? 'PZ';
                    $uomComm   = $item['um_comm_code'] ?? $uomStock;
                    $factor    = (float)($item['p_factor'] ?? 1) ?: 1;
                  ?>
                    <tr class="pl-riga" data-riga-id="<?= (int)$item['id'] ?>"
                         data-prodotto-id="<?= (int)$item['prodotto_id'] ?>"
                         data-prodotto-nome="<?= e($item['prodotto_nome']) ?>"
                         data-sku="<?= e($item['sku_interno']) ?>"
                         data-sku-fornitore="<?= e($item['sku_fornitore'] ?? '') ?>"
                         data-qmin="<?= (float)$item['quantita_minima'] ?>"
                         data-prezzo="<?= (float)$item['prezzo'] ?>"
                         data-sconto="<?= (float)$item['sconto_perc'] ?>"
                         data-valido-dal="<?= e($item['valido_dal'] ?? '') ?>"
                         data-valido-al="<?= e($item['valido_al'] ?? '') ?>"
                         data-note="<?= e($item['note'] ?? '') ?>"
                         data-native-price="<?= (float)$item['prezzo'] ?>"
                         data-native-qmin="<?= (float)$item['quantita_minima'] ?>"
                         data-factor="<?= $factor ?>"
                         data-um-native="<?= e($uomNative) ?>"
                         data-um-stock="<?= e($uomStock) ?>"
                         data-um-comm="<?= e($uomComm) ?>">
                      <td>
                        <div class="pl-view">
                          <a href="<?= url('products/edit?id=' . $item['prodotto_id']) ?>" class="fw-semibold qk-text-accent" tabindex="-1">
                            <?= e($item['prodotto_nome']) ?>
                          </a>
                          <div class="pl-duplicate-warning text-danger fw-semibold mt-1" style="font-size: 0.75rem; display: none;">
                            <i class="fa-solid fa-triangle-exclamation me-1"></i>Articolo duplicato per questa quantità
                          </div>
                        </div>
                      </td>
                      <td class="col-sku-interno"><code><?= e($item['sku_interno']) ?></code></td>
                      <td class="col-sku-fornitore">
                        <span class="pl-view text-monospace"><?= e($item['sku_fornitore'] ?? '—') ?></span>
                        <input class="pl-edit qk-form-control py-1 px-2 text-monospace r-sku-forn" type="text" value="<?= e($item['sku_fornitore'] ?? '') ?>">
                      </td>
                      <td class="text-end">
                        <span class="pl-view text-monospace r-qmin-view"><?= number_format((float)$item['quantita_minima'], 3, ',', '.') ?></span>
                        <input class="pl-edit qk-form-control py-1 px-2 text-end text-monospace r-qmin" type="number" min="0" step="0.001" value="<?= (float)$item['quantita_minima'] ?>">
                      </td>
                      <td class="text-end text-nowrap">
                        <span class="pl-view text-monospace fw-bold r-prezzo-view"><?= number_format((float)$item['prezzo'], 2, ',', '.') ?> €</span>
                        <span class="pl-view text-muted ms-1 r-uom-view" style="font-size:.75rem;">/ <?= e($uomNative) ?></span>
                        <div class="pl-edit d-flex align-items-center">
                          <input class="qk-form-control py-1 px-2 text-end text-monospace r-prezzo" type="number" min="0" step="0.0001" value="<?= (float)$item['prezzo'] ?>">
                          <span class="ms-1 text-muted r-uom-edit-label" style="font-size:.75rem;">/ <?= e($uomNative) ?></span>
                        </div>
                      </td>
                      <td class="text-end col-sconto-perc">
                        <span class="pl-view text-monospace"><?= number_format((float)$item['sconto_perc'], 2, ',', '.') ?> %</span>
                        <input class="pl-edit qk-form-control py-1 px-2 text-end text-monospace r-sconto" type="number" min="0" max="100" step="0.01" value="<?= (float)$item['sconto_perc'] ?>">
                      </td>
                      <td class="col-validita">
                        <div class="pl-view text-monospace small">
                          <?php if (!empty($item['valido_dal']) || !empty($item['valido_al'])): ?>
                            <?= !empty($item['valido_dal']) ? date('d/m/Y', strtotime($item['valido_dal'])) : '...' ?>
                            &rarr;
                            <?= !empty($item['valido_al']) ? date('d/m/Y', strtotime($item['valido_al'])) : '...' ?>
                          <?php else: ?>
                            Sempre valido
                          <?php endif; ?>
                        </div>
                        <div class="pl-edit d-flex gap-1">
                          <input class="qk-form-control py-1 px-1 r-valido-dal" type="date" value="<?= e($item['valido_dal'] ?? '') ?>" style="font-size:.75rem; min-width: 0; flex: 1;">
                          <input class="qk-form-control py-1 px-1 r-valido-al" type="date" value="<?= e($item['valido_al'] ?? '') ?>" style="font-size:.75rem; min-width: 0; flex: 1;">
                        </div>
                      </td>
                      <td class="col-note-prezzo">
                        <span class="pl-view qk-text-muted small"><?= e($item['note'] ?? '') ?></span>
                        <input class="pl-edit qk-form-control py-1 px-2 r-note" type="text" value="<?= e($item['note'] ?? '') ?>">
                      </td>
                      <td>
                        <button type="button" class="btn-qk btn-qk-danger btn-qk-sm px-2 btn-delete-riga" title="Rimuovi" style="vertical-align: middle;">
                          <i class="fa-solid fa-trash-can"></i>
                        </button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            
            <?php if (!empty($items) && isset($pages) && $pages > 1): ?>
              <div class="mt-3">
                <?= qk_pagination($page, $pages, '?id=' . $id . '&sort=' . $sort . '&dir=' . $dir . '&page=') ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- TAB 2: Note e Informazioni -->
        <div class="tab-pane fade" id="p-notes-pane" role="tabpanel" aria-labelledby="p-notes-tab" tabindex="0">
          <div class="row g-3">
            <div class="col-md-7">
              <div class="qk-card shadow-sm h-100">
                <div class="qk-card-header">
                  <span class="qk-card-title"><i class="fa-solid fa-message me-2" style="color:var(--qk-accent)"></i>Note interne o descrittive</span>
                </div>
                <div class="qk-card-body">
                  <div class="qk-form-group mb-0">
                    <textarea name="note" class="qk-form-control" rows="6" placeholder="Aggiungi note sull'accordo o dettagli aggiuntivi del listino..."><?= e($values['note']) ?></textarea>
                  </div>
                </div>
              </div>
            </div>
            <div class="col-md-5">
              <div class="qk-card shadow-sm h-100" style="border-left:3px solid var(--qk-info)">
                <div class="qk-card-header">
                  <span class="qk-card-title"><i class="fa-solid fa-circle-info me-2" style="color:var(--qk-info)"></i>Informazioni</span>
                </div>
                <div class="qk-card-body" style="font-size:.85rem;color:var(--qk-text-muted);line-height:1.6">
                  <p class="mb-2">I listini consentono di impostare regole di acquisto temporanee o tariffe concordate con il fornitore prescelto.</p>
                  <p class="mb-0">Una volta inserita la tariffa, il sistema la proporrà automaticamente quando crei una Richiesta di Offerta (RFQ) o un Ordine di Acquisto per questo fornitore in base alle date e scaglioni inseriti.</p>
                </div>
              </div>
            </div>
          </div>
        </div>

      </div>
    </div>
  <?php endif; ?>

</form>

<!-- Template per nuova riga listino acquisto -->
<template id="pl-tpl-new-acquisto">
  <tr class="pl-riga is-editing" data-riga-id="0" data-prodotto-id="">
    <td>
      <div class="pl-edit">
        <div class="qk-m2o-wrap" style="min-width:180px">
          <input type="hidden" class="r-prodotto-id" value="">
          <input type="text" class="qk-form-control qk-m2o-input r-prodotto-label" placeholder="Cerca prodotto…" autocomplete="off">
          <button type="button" class="qk-m2o-clear d-none r-prodotto-clear">&times;</button>
          <div class="qk-m2o-dropdown" style="position:absolute;z-index:100;background:var(--qk-card-bg);border:1px solid var(--qk-card-border);max-height:200px;overflow-y:auto;width:100%"></div>
        </div>
        <div class="pl-duplicate-warning text-danger fw-semibold mt-1" style="font-size: 0.75rem; display: none;">
          <i class="fa-solid fa-triangle-exclamation me-1"></i>Articolo duplicato per questa quantità
        </div>
      </div>
    </td>
    <td class="col-sku-interno"><code class="r-sku-label">—</code></td>
    <td class="col-sku-fornitore"><input class="pl-edit qk-form-control py-1 px-2 text-monospace r-sku-forn" type="text" placeholder="SKU Forn."></td>
    <td><input class="pl-edit qk-form-control py-1 px-2 text-end text-monospace r-qmin" type="number" min="0" step="0.001" value="0"></td>
    <td><input class="pl-edit qk-form-control py-1 px-2 text-end text-monospace r-prezzo" type="number" min="0" step="0.0001" placeholder="0,0000 €"></td>
    <td class="col-sconto-perc"><input class="pl-edit qk-form-control py-1 px-2 text-end text-monospace r-sconto" type="number" min="0" max="100" step="0.01" value="0"></td>
    <td class="col-validita">
      <div class="pl-edit d-flex gap-1">
        <input class="qk-form-control py-1 px-1 r-valido-dal" type="date" style="font-size:.75rem; min-width: 0; flex: 1;">
        <input class="qk-form-control py-1 px-1 r-valido-al" type="date" style="font-size:.75rem; min-width: 0; flex: 1;">
      </div>
    </td>
    <td class="col-note-prezzo"><input class="pl-edit qk-form-control py-1 px-2 r-note" type="text" placeholder="Note prezzo"></td>
    <td>
      <button type="button" class="btn-qk btn-qk-secondary btn-qk-sm px-2 btn-cancel-riga" title="Annulla (Esc)" style="vertical-align: middle;">
        <i class="fa-solid fa-trash-can"></i>
      </button>
    </td>
  </tr>
</template>

<!-- Script Autocomplete Fornitore (Many2one) & Griglia Prezzi Acquisto -->


<!-- ExcelJS Library for reading/writing xlsx -->
<script src="<?= asset('js/exceljs.min.js') ?>"></script>

<!-- Modal per Importazione Massiva Prezzi Acquisto -->
<div class="modal fade" id="modalImportaPrezziAcquisto" tabindex="-1" aria-labelledby="modalImportaPrezziAcquistoTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modalImportaPrezziAcquistoTitle">
          <i class="fa-solid fa-file-import me-2"></i>Importazione Massiva Tariffe Acquisto
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
      </div>
      <div class="modal-body">
        <div class="qk-alert qk-alert-info mb-3">
          <i class="fa-solid fa-circle-info"></i>
          <span>Carica un file Excel (.xlsx) per importare o aggiornare le tariffe degli articoli. Se una tariffa d'acquisto esiste già per lo stesso articolo e scaglione quantità, verrà aggiornata con il nuovo prezzo.</span>
        </div>
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div>
            <input type="file" id="inputFilePrezziAcquisto" accept=".xlsx" class="d-none">
            <label for="inputFilePrezziAcquisto" class="btn-qk btn-qk-primary">
              <i class="fa-solid fa-file-excel me-1"></i>Seleziona File Excel...
            </label>
            <button type="button" class="btn-qk btn-qk-secondary" id="btnClearFilePrezzi">
              <i class="fa-solid fa-trash me-1"></i>Rimuovi File
            </button>
          </div>
          <button type="button" class="btn-qk btn-qk-secondary" id="btnDownloadTracciatoPrezzi">
            <i class="fa-solid fa-file-arrow-down me-1"></i>Scarica Modello Tracciato
          </button>
        </div>

        <div id="importPreviewWrapPrezzi" style="display:none" class="mb-3">
          <h6 class="fw-bold mb-2">Anteprima Righe (<span id="importPreviewCountPrezzi">0</span> righe trovate, mostra max 10)</h6>
          <div class="qk-table-wrap" style="max-height: 250px; overflow-y: auto;">
            <table class="qk-table mb-0" style="font-size: .8rem">
              <thead id="importPreviewHeadPrezzi"></thead>
              <tbody id="importPreviewBodyPrezzi"></tbody>
            </table>
          </div>
        </div>

        <div id="importResultPrezzi" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-qk btn-qk-secondary" data-bs-dismiss="modal">Chiudi</button>
        <button type="button" class="btn-qk btn-qk-primary" id="btnImportaPrezziAcquisto" disabled>
          <i class="fa-solid fa-upload me-1"></i>Avvia Importazione
        </button>
      </div>
    </div>
  </div>
</div>




<script>
/* Config: PHP -> JS, letta da public/assets/js/purchases/pricelists_form.js.
   NOTA (23/07/2026): questi 3 valori erano tag di output PHP scritti
   direttamente dentro il file .js -- non elaborati mai, perche' il file e'
   servito come asset statico. Stesso bug gia' corretto altrove in questa
   sessione (assets/js/products-form.js, adr-sostanze-form.js, rfq_form.js). */
window.QkPurchasePricelist = {
  isEdit: <?= $isEdit ? 'true' : 'false' ?>,
  csrfToken: <?= json_encode(csrf_token()) ?>,
  listinoId: <?= (int)($id ?? 0) ?>
};
</script>
<script src="<?= url('public/assets/js/purchases/pricelists_form.js') ?>"></script>
