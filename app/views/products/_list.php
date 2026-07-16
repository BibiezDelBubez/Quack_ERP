<?php
/**
 * products/_list.php
 */
?>
<div class="qk-card">
  <div class="qk-table-toolbar">
    <div class="qk-table-toolbar-left">
      <form method="get" class="qk-flex-row qk-gap-sm flex-wrap align-items-center">
        <div class="qk-filter-wrap">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input type="text" name="q" class="qk-filter-input" placeholder="Cerca nome, SKU..." value="<?= e($search) ?>">
        </div>
        <?php if (!empty($categories)): ?>
        <select name="cat" class="qk-filter-input" style="width:auto">
          <option value="">Tutte le categorie</option>
          <?php foreach ($categories as $cat): ?><option value="<?= e($cat['id']) ?>" <?= (string)$category === (string)$cat['id'] ? 'selected' : '' ?>><?= e($cat['label']) ?></option><?php endforeach; ?>
        </select>
        <?php endif; ?>
        <?php if (!empty($suppliers)): ?>
        <select name="fornitore" class="qk-filter-input" style="width:auto">
          <option value="">Tutti i fornitori</option>
          <?php foreach ($suppliers as $sup): ?>
            <option value="<?= e($sup['id']) ?>" <?= (string)$supplier === (string)$sup['id'] ? 'selected' : '' ?>><?= e($sup['ragione_sociale']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <label class="qk-flex-row qk-gap-xs fw-medium" style="font-size:.85rem;cursor:pointer">
          <input type="checkbox" name="adr" value="1" <?= $adr_only ? 'checked' : '' ?>> Solo ADR
        </label>
        <button type="submit" class="btn-qk btn-qk-secondary btn-qk-sm"><i class="fa-solid fa-filter"></i> Filtra</button>
        <?php if ($search || $category || $adr_only): ?><a href="<?= url('products') ?>" class="btn-qk btn-qk-secondary btn-qk-sm"><i class="fa-solid fa-xmark"></i> Pulisci</a><?php endif; ?>
      </form>
    </div>
    <div class="qk-table-toolbar-right">
      <small class="qk-text-muted me-3"><?= $total ?> prodotti</small>
      <div class="qk-dropdown-container me-2" style="position:relative; display:inline-block; line-height:1;">
        <button type="button" class="btn-qk btn-qk-secondary btn-qk-sm" id="btn-col-settings" title="Mostra/Nascondi colonne">
          <i class="fa-solid fa-sliders"></i> Colonne
        </button>
        <div class="qk-dropdown-menu shadow-sm" id="col-settings-dropdown" style="display:none; position:absolute; right:0; top:120%; z-index:1000; background:var(--qk-card-bg); border:1px solid var(--qk-border); border-radius:var(--qk-radius); padding:.6rem .8rem; min-width:200px; text-align:left; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
          <div class="qk-flex-col qk-gap-sm">
            <label class="qk-flex-row qk-gap-sm" style="font-size:.85rem;font-weight:500;cursor:pointer;color:var(--qk-text-primary);margin:0">
              <input type="checkbox" id="chk-show-costo" style="transform:scale(1.15);">
              <span>Costo d'acquisto</span>
            </label>
            <label class="qk-flex-row qk-gap-sm" style="font-size:.85rem;font-weight:500;cursor:pointer;color:var(--qk-text-primary);margin:0">
              <input type="checkbox" id="chk-show-confezione" style="transform:scale(1.15);">
              <span>Descrizione confezione</span>
            </label>
          </div>
        </div>
      </div>
      <button type="button" class="btn-qk btn-qk-secondary btn-qk-sm" data-bs-toggle="modal" data-bs-target="#modalImportaProdotti">
        <i class="fa-solid fa-file-import me-1"></i>Importa
      </button>
    </div>
  </div>

  <div class="qk-table-wrap">
    <?php if (empty($products)): ?>
    <div class="qk-empty">
      <i class="fa-regular fa-flask"></i>
      <p><?= ($search || $category || $adr_only) ? 'Nessun prodotto trovato con i filtri selezionati.' : 'Nessun prodotto ancora.' ?></p>
      <?php if (!$search && !$category && !$adr_only): ?><a href="<?= url('products/create') ?>" class="btn-qk btn-qk-primary"><i class="fa-solid fa-plus"></i> Aggiungi prodotto</a><?php endif; ?>
    </div>
    <?php else: ?>
    <table class="qk-table">
      <?php $base = url('products'); $sort = $pag['sort']; $dir = $pag['dir']; ?>
      <thead><tr>
        <th class="text-center" style="width: 45px;"><input type="checkbox" id="select-all-products" style="transform: scale(1.15); cursor: pointer;"></th>
        <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'sku', $sort, $dir)) ?>">SKU<?= qk_sort_icon('sku', $sort, $dir) ?></a></th>
        <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'nome', $sort, $dir)) ?>">Nome prodotto<?= qk_sort_icon('nome', $sort, $dir) ?></a></th>
        <th class="qk-sortable"><a href="<?= e(qk_sort_url($base, 'categoria', $sort, $dir)) ?>">Categoria<?= qk_sort_icon('categoria', $sort, $dir) ?></a></th>
        <th class="qk-sortable text-center"><a href="<?= e(qk_sort_url($base, 'giacenza', $sort, $dir)) ?>">Giacenza<?= qk_sort_icon('giacenza', $sort, $dir) ?></a></th>
        <th class="qk-sortable text-end"><a href="<?= e(qk_sort_url($base, 'prezzo', $sort, $dir)) ?>">Prezzo di vendita<?= qk_sort_icon('prezzo', $sort, $dir) ?></a></th>
        <th class="col-costo-acquisto text-end" style="display: none;">Costo d'acquisto</th>
        <th class="col-confezione" style="display: none;">Descrizione confezione</th>
        <th>ADR</th>
        <th class="col-actions"></th>
      </tr></thead>
      <tbody>
      <?php foreach ($products as $p): ?>
        <tr data-id="<?= (int)$p['id'] ?>">
          <td class="text-center" style="width: 45px;"><input type="checkbox" class="product-select-chk" data-id="<?= (int)$p['id'] ?>" style="transform: scale(1.15); cursor: pointer;"></td>
          <td><code class="qk-text-secondary" style="font-size:.8rem"><?= e($p['sku_interno'] ?? '') ?></code></td>
          <td class="fw-semibold">
            <a href="<?= url('products/edit?id=' . $p['id']) ?>" class="qk-text-accent text-decoration-none">
              <?= e($p['nome'] ?? '') ?>
            </a>
          </td>
          <td><?php if ($p['categoria']): ?><span class="qk-badge draft"><?= e($p['categoria']) ?></span><?php else: ?><span class="qk-text-muted">--</span><?php endif; ?></td>
          <td class="text-center">
            <?php if ($p['quantita_disponibile'] !== null): ?>
            <a href="<?= url('warehouse/min-stock?prodotto_id=' . (int) $p['id']) ?>"
               class="qk-text-accent text-decoration-none" title="Vedi giacenza per magazzino">
              <?= (int) $p['quantita_disponibile'] ?>
            </a>
            <?php else: ?>
            <span class="qk-text-muted">--</span>
            <?php endif; ?>
          </td>
          <td class="text-end"><?php if (!empty($p['prezzo_vendita'])) { echo e(number_format($p['prezzo_vendita'], 2, ',', '.')); } else { echo '--'; } ?></td>
          <td class="col-costo-acquisto text-end" style="display: none; white-space: nowrap;"><?php if (!empty($p['costo_acquisto'])) { echo e(number_format($p['costo_acquisto'], 2, ',', '.')) . ' €'; } else { echo '--'; } ?></td>
          <td class="col-confezione" style="display: none;"><?= e($p['descrizione_confezione'] ?? '--') ?></td>
          <td><?= !empty($p['is_adr']) ? '<span class="qk-badge warning">ADR</span>' : '<span class="qk-text-muted">--</span>' ?></td>
          <td class="col-actions">
            <div class="d-flex gap-1 justify-content-end">
              <a href="<?= url('products/edit?id=' . $p['id']) ?>" class="btn-qk btn-qk-secondary btn-qk-sm" title="Modifica"><i class="fa-solid fa-pencil"></i></a>
              <form action="<?= url('products/duplicate') ?>" method="post" class="d-inline" onsubmit="return confirm('Duplicare questo prodotto con tutti i suoi listini e fornitori?');">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <button type="submit" class="btn-qk btn-qk-secondary btn-qk-sm" title="Duplica"><i class="fa-solid fa-copy"></i></button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <?php if ($pages > 1): ?>
  <div class="qk-table-footer mt-2">
    <span class="qkwh-count-label"><?= $total ?> prodotti</span>
    <div class="qk-pagination">
      <?php for ($pg = 1; $pg <= $pages; $pg++): ?>
      <?php
        $paginationParams = array_filter([
          'q'        => $search,
          'cat'      => $category,
          'adr'      => $adr_only ? '1' : '',
          'fornitore' => $supplier ?: '',
          'page'     => $pg > 1 ? $pg : '',
        ]);
        $paginationQs = http_build_query($paginationParams);
      ?>
      <a href="<?= url('products') . ($paginationQs ? '?' . $paginationQs : '') ?>"
         class="qk-page-btn <?= $pg === $page ? 'active' : '' ?>"><?= $pg ?></a>
      <?php endfor; ?>
    </div>
  </div>
  <?php endif; ?>
</div><!-- /qk-card -->

<!-- ======================================================================
     MODALE — Importazione massiva prodotti
     ====================================================================== -->
<div class="modal fade" id="modalImportaProdotti" tabindex="-1" aria-labelledby="modalImportaProdottiLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-semibold" id="modalImportaProdottiLabel">
          <i class="fa-solid fa-file-import me-2"></i>Importazione Massiva Prodotti
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">

        <div class="mb-3 p-3" style="background:var(--qk-bg-subtle);border:1px solid var(--qk-border);border-radius:6px">
          <p class="mb-2 fw-semibold"><i class="fa-solid fa-circle-info me-1 qk-text-accent"></i>Come funziona</p>
          <ol class="mb-0" style="font-size:.875rem;padding-left:1.2rem">
            <li>Scarica il tracciato Excel con il pulsante qui sotto</li>
            <li>Compila il file rispettando le colonne (non modificare la riga 1)</li>
            <li>Carica il file compilato con "Carica file Excel"</li>
            <li>Controlla l'anteprima e clicca <strong>Importa</strong></li>
          </ol>
          <div class="mt-2" style="font-size:.8rem;color:var(--qk-text-muted)">
            Campi obbligatori: <strong>sku_interno</strong>, <strong>nome</strong>.
            Le righe con SKU gia presenti vengono saltate (nessun aggiornamento).
          </div>
        </div>

        <div class="qk-flex-row qk-gap-sm mb-3">
          <button type="button" id="btnDownloadTracciato" class="btn-qk btn-qk-secondary btn-qk-sm">
            <i class="fa-solid fa-file-arrow-down me-1"></i>Scarica Tracciato
          </button>
          <label class="btn-qk btn-qk-secondary btn-qk-sm mb-0" style="cursor:pointer">
            <i class="fa-solid fa-folder-open me-1"></i>Carica file Excel
            <input type="file" id="inputFileProdotti" accept=".xlsx,.xls" style="display:none">
          </label>
        </div>

        <div id="importPreviewWrap" style="display:none">
          <div class="qk-flex-row qk-justify-between mb-1">
            <span class="fw-semibold" style="font-size:.875rem">Anteprima (<span id="importPreviewCount">0</span> righe)</span>
            <button type="button" id="btnClearFile" class="btn-qk btn-qk-secondary btn-qk-sm">
              <i class="fa-solid fa-xmark"></i> Rimuovi
            </button>
          </div>
          <div style="max-height:240px;overflow:auto;border:1px solid var(--qk-border);border-radius:6px">
            <table class="qk-table qk-table-sm mb-0">
              <thead id="importPreviewHead"></thead>
              <tbody id="importPreviewBody"></tbody>
            </table>
          </div>
        </div>

        <div id="importResult" style="display:none;margin-top:1rem"></div>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn-qk btn-qk-secondary" data-bs-dismiss="modal">Chiudi</button>
        <button type="button" id="btnImportaProdotti" class="btn-qk btn-qk-primary" disabled>
          <i class="fa-solid fa-upload me-1"></i>Importa
        </button>
      </div>
    </div>
  </div>
</div>
<?php
$_qk_csrf   = csrf_token();
$_qk_import = url('products/import');
?>
<script src="<?= asset('js/exceljs.min.js') ?>"></script>
<script>
window.QuackListConfig = {
    csrfToken: '<?= e($_qk_csrf) ?>',
    importUrl: '<?= e($_qk_import) ?>',
    batchAssociaUrl: '<?= url("products/api/batch-associa-adr") ?>'
};
</script>
<script defer src="<?= asset('js/products-list.js') ?>"></script>

<!-- Barra Azioni Massive Fluttuante (Premium UI) -->
<div id="bulk-actions-bar" class="qk-bulk-actions-bar">
  <div class="qk-bulk-actions-content qk-flex-row qk-justify-between qk-gap-md">
    <div class="qk-flex-row qk-gap-sm align-items-center">
      <span class="qk-badge confirmed font-monospace" id="bulk-select-count-badge" style="font-size: 0.9rem; padding: 4px 8px;">0</span>
      <span class="fw-semibold text-white">Selezionati</span>
    </div>
    
    <div class="qk-flex-row qk-gap-sm align-items-center flex-wrap">
      <select id="bulk-substance-select" class="qk-form-control py-1" style="width: auto; max-width: 250px; background: rgba(0,0,0,0.4); border-color: rgba(255,255,255,0.15); color: #fff; border-radius: 6px;">
        <option value="" style="background: var(--qk-card-bg); color: var(--qk-text-primary);">— Associa Sostanza ADR —</option>
        <option value="0" style="background: var(--qk-card-bg); color: var(--qk-text-primary);">— Rimuovi Sostanza ADR (Disassocia) —</option>
        <?php if (!empty($adr_sostanze)): foreach ($adr_sostanze as $sub): ?>
          <option value="<?= (int)$sub['id'] ?>" style="background: var(--qk-card-bg); color: var(--qk-text-primary);">
            <?= e($sub['nome']) ?> <?= $sub['codice_onu'] ? '[UN' . e($sub['codice_onu']) . ']' : '' ?>
          </option>
        <?php endforeach; endif; ?>
      </select>
      
      <button type="button" id="btn-bulk-apply" class="btn-qk btn-qk-primary btn-qk-sm" disabled>
        <i class="fa-solid fa-link me-1"></i> Applica
      </button>
      
      <button type="button" id="btn-bulk-cancel" class="btn-qk btn-qk-secondary btn-qk-sm" style="border-color: rgba(255,255,255,0.15); color: #fff; background: transparent;">
        Annulla
      </button>
    </div>
  </div>
</div>

<style>
.qk-bulk-actions-bar {
  position: fixed;
  bottom: 24px;
  left: 50%;
  transform: translateX(-50%) translateY(100px);
  z-index: 1050;
  width: 90%;
  max-width: 650px;
  background: rgba(17, 24, 39, 0.85); /* Sfondo scuro semitrasparente */
  backdrop-filter: blur(14px);
  -webkit-backdrop-filter: blur(14px);
  border: 1px solid rgba(255, 255, 255, 0.15);
  border-radius: 12px;
  padding: 10px 18px;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
  transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.3s ease;
  opacity: 0;
}
.qk-bulk-actions-bar.show {
  transform: translateX(-50%) translateY(0);
  opacity: 1;
}
.qk-bulk-actions-content {
  display: flex;
  align-items: center;
}
/* Stile per i checkbox selezionati nella riga */
.qk-table tr.row-selected {
  background-color: var(--qk-accent-light) !important;
}
</style>

