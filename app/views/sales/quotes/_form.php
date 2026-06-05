<?php
/**
 * sales/quotes/_form.php — Form creazione/modifica offerta.
 * Variabili attese: $values (array), $errors (array), $numero_offerta (string), $linkedOrder (array|null)
 */
$v = $values;
$stato = $v['stato'] ?? 'bozza';
$can_edit = ($stato === 'bozza');
?>

<!-- ── Stili specifici offerta ──────────────────────────────────────────── -->
<style>
/* Cliente selezionato */
.qk-cliente-preview { background: var(--qk-accent-muted, rgba(0,100,60,.06));
                      border: 1px solid var(--qk-border); border-radius: var(--qk-radius-sm);
                      padding: .65rem 1rem; margin-top: .5rem; display: flex;
                      align-items: flex-start; gap: .75rem; }
.qk-cliente-preview .name { font-weight: 700; }
.qk-cliente-preview .info { font-size: .82rem; color: var(--qk-text-muted); }
.qk-cliente-preview .btn-clear { margin-left: auto; background: none; border: none;
                                  cursor: pointer; color: var(--qk-text-muted); padding: .1rem .3rem; }

/* Totali */
.qk-totali-grid { display: grid; grid-template-columns: 1fr auto; gap: .25rem .75rem;
                  align-items: center; }
.qk-totali-grid .lbl { font-size: .88rem; color: var(--qk-text-muted); text-align: right; }
.qk-totali-grid .val { font-size: .95rem; font-weight: 600; text-align: right; white-space: nowrap; min-width: 110px; }
.qk-totali-grid .lbl-total { font-size: 1rem; font-weight: 700; color: var(--qk-text-primary); }
.qk-totali-grid .val-total { font-size: 1.1rem; font-weight: 800; color: var(--qk-accent-text); }
.qk-totali-sep  { grid-column: 1 / -1; border: none; border-top: 1px solid var(--qk-border); margin: .3rem 0; }

/* Sticky footer actions */
.qk-form-sticky-actions { position: sticky; bottom: 0; z-index: 100;
                           background: var(--qk-topbar-bg); border-top: 1px solid var(--qk-border);
                           padding: .75rem 1.5rem; display: flex; align-items: center;
                           gap: .75rem; margin: 1.5rem -1.5rem -1.5rem; }
@media (max-width: 768px) {
  .qk-form-sticky-actions { flex-wrap: wrap; }
}

#tbl-righe .col-search { min-width: 180px; width: 22%; }
#tbl-righe .col-desc   { min-width: 200px; width: 28%; }
#tbl-righe .col-conf   { width: 90px; }
#tbl-righe .col-qta    { width: 80px; }
#tbl-righe .col-um     { width: 60px; }
#tbl-righe .col-pu     { width: 100px; }
#tbl-righe .col-sc     { width: 65px; }
#tbl-righe .col-iva    { width: 60px; }
#tbl-righe .col-tot    { width: 100px; }
#tbl-righe .col-del    { width: 36px; }
#tbl-righe .qk-form-control {
  padding-left: 0.25rem;
  padding-right: 0.25rem;
  font-size: 0.82rem;
}
#tbl-righe .col-costo   { width: 90px; }
#tbl-righe .col-range   { width: 120px; }
#tbl-righe .col-delta   { width: 90px; }
.riga-sku { font-size: .72rem; color: var(--qk-text-muted); font-family: monospace; }
/* Override visual indicators */
.is-overridden {
  border-color: #f59e0b !important;
  background-color: #fef3c7 !important;
}

/* Stili Catalogo Prodotti */
.qk-card-prod-catalogo {
  background: var(--qk-card-bg, #fff);
  border: 1px solid var(--qk-border) !important;
  transition: transform 0.15s, box-shadow 0.15s;
}
.qk-card-prod-catalogo:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
  border-color: var(--qk-accent) !important;
}
.badge-rrp-min {
  background-color: rgba(40, 167, 69, 0.1) !important;
  color: #28a745 !important;
}
.badge-rrp-max {
  background-color: rgba(0, 123, 255, 0.1) !important;
  color: #007bff !important;
}
.badge-costo {
  background-color: var(--qk-bg-subtle) !important;
  color: var(--qk-text-secondary) !important;
}
</style>

<?php if (!empty($errors['_global'])): ?>
<div class="qk-alert qk-alert-danger" data-auto-dismiss="7000">
  <i class="fa-solid fa-circle-xmark"></i>
  <span><?= e($errors['_global']) ?></span>
  <button class="qk-alert-close"><i class="fa-solid fa-xmark"></i></button>
</div>
<?php endif; ?>

<?php if (!empty($_SESSION['flash_success'])): ?>
<div class="qk-alert qk-alert-success" data-auto-dismiss="7000">
  <i class="fa-solid fa-circle-check"></i>
  <span><?= e($_SESSION['flash_success']) ?></span>
  <button class="qk-alert-close"><i class="fa-solid fa-xmark"></i></button>
</div>
<?php unset($_SESSION['flash_success']); endif; ?>

<?php if (!empty($_SESSION['flash_error'])): ?>
<div class="qk-alert qk-alert-danger" data-auto-dismiss="7000">
  <i class="fa-solid fa-circle-xmark"></i>
  <span><?= e($_SESSION['flash_error']) ?></span>
  <button class="qk-alert-close"><i class="fa-solid fa-xmark"></i></button>
</div>
<?php unset($_SESSION['flash_error']); endif; ?>

<!-- Barra Azioni e Stato (Odoo style) -->
<div class="d-flex justify-content-between align-items-center mb-4 p-3 border rounded shadow-xs" style="background: var(--qk-bg-subtle); border-color: var(--qk-border) !important; flex-wrap: wrap; gap: .75rem;">
  <!-- Bottoni Azione / Navigazione -->
  <div style="display:flex; gap:.5rem; align-items:center; flex-wrap:wrap;">
    <a href="<?= url('sales/quotes') ?>" class="btn-qk btn-qk-secondary btn-qk-sm">
      <i class="fa-solid fa-arrow-left"></i> Torna alla lista
    </a>
    <?php if (isset($id) && $id > 0): ?>
      <?php if ($stato === 'bozza'): ?>
        <form method="post" action="<?= url('sales/quotes/action') ?>" class="d-inline">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $id ?>">
          <input type="hidden" name="azione" value="invia">
          <button type="submit" class="btn-qk btn-qk-primary btn-qk-sm" title="Segna come inviato al cliente">
            <i class="fa-solid fa-paper-plane"></i> Invia al Cliente
          </button>
        </form>
      <?php else: ?>
        <?php if (in_array($stato, ['inviata', 'rifiutata', 'scaduta', 'annullata'])): ?>
          <form method="post" action="<?= url('sales/quotes/action') ?>" class="d-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="azione" value="nuova_versione">
            <button type="submit" class="btn-qk btn-qk-secondary btn-qk-sm" title="Crea una nuova versione di questo preventivo">
              <i class="fa-solid fa-code-branch"></i> Crea Nuova Versione
            </button>
          </form>
        <?php endif; ?>
        <?php if ($stato === 'inviata'): ?>
          <form method="post" action="<?= url('sales/quotes/action') ?>" class="d-inline" onsubmit="return confirm('Confermare l\'accettazione del preventivo e creare l\'ordine cliente?')">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="azione" value="conferma_ordine">
            <button type="submit" class="btn-qk btn-qk-primary btn-qk-sm" title="Accetta il preventivo e genera l'ordine di vendita">
              <i class="fa-solid fa-circle-check"></i> Accetta e Crea Ordine
            </button>
          </form>
          <form method="post" action="<?= url('sales/quotes/action') ?>" class="d-inline" onsubmit="return confirm('Segnare il preventivo come rifiutato?')">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="azione" value="rifiuta">
            <button type="submit" class="btn-qk btn-qk-secondary btn-qk-sm text-danger" title="Segna come rifiutato">
              <i class="fa-solid fa-ban"></i> Rifiuta
            </button>
          </form>
        <?php elseif ($stato === 'accettata' && !empty($linkedOrder)): ?>
          <span class="qk-badge qk-badge-success py-2 px-3 fw-bold d-inline-flex align-items-center" style="font-size: 0.85rem; height: 31px;">
            <i class="fa-solid fa-check-double me-1"></i> Ordine Generato: 
            <a href="<?= url('sales/orders/edit?id=' . $linkedOrder['id']) ?>" class="fw-bold text-white text-decoration-underline ms-1"><?= e($linkedOrder['numero_ordine']) ?></a>
          </span>
        <?php endif; ?>
      <?php endif; ?>
      <form method="post" action="<?= url('sales/quotes/action') ?>" class="d-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="azione" value="duplica">
        <button type="submit" class="btn-qk btn-qk-secondary btn-qk-sm" title="Duplica questo preventivo in una nuova bozza indipendente">
          <i class="fa-regular fa-copy text-success"></i> Duplica
        </button>
      </form>

      
      <a href="<?= url('sales/quotes/pdf') . '?id=' . $id ?>" class="btn-qk btn-qk-secondary btn-qk-sm" target="_blank" title="Stampa o scarica il preventivo in formato PDF">
        <i class="fa-solid fa-file-pdf text-danger"></i> PDF
      </a>
      <a href="<?= url('sales/quotes/docx') . '?id=' . $id ?>" class="btn-qk btn-qk-secondary btn-qk-sm" target="_blank" title="Scarica il documento in formato Microsoft Word (DOCX)">
        <i class="fa-solid fa-file-word text-primary"></i> Word (DOCX)
      </a>
    <?php endif; ?>
  </div>

  <!-- Pipeline visivo stati -->
  <ul class="qk-status-path d-none d-sm-flex">
    <?php
    $steps = [
      ['bozza',     'Bozza'],
      ['inviata',   'Inviata'],
      ['accettata', 'Accettata'],
    ];
    if ($stato === 'rifiutata') {
        $steps[] = ['rifiutata', 'Rifiutata'];
    } elseif ($stato === 'scaduta') {
        $steps[] = ['scaduta', 'Scaduta'];
    } elseif ($stato === 'annullata') {
        $steps[] = ['annullata', 'Annullata'];
    }
    
    $currentIdx = 0;
    foreach ($steps as $idx => [$s, $lbl]) {
        if ($stato === $s) {
            $currentIdx = $idx;
            break;
        }
    }
    foreach ($steps as $idx => [$s, $lbl]):
        if ($stato === $s) {
            $statusClass = 'active';
        } elseif ($idx < $currentIdx) {
            $statusClass = 'completed';
        } else {
            $statusClass = 'pending';
        }
    ?>
      <li class="qk-status-path-item <?= $statusClass ?>">
        <?= e($lbl) ?>
      </li>
    <?php endforeach; ?>
  </ul>
</div>

<form method="post" id="form-offerta" action="">
  <fieldset style="border:none; padding:0; margin:0;"<?= !$can_edit ? ' disabled' : '' ?>>
  <?= csrf_field() ?>

  <!-- Cliente nascosto -->
  <input type="hidden" name="cliente_id"    id="cliente_id"    value="<?= e($v['cliente_id']) ?>">
  <input type="hidden" name="cliente_label" id="cliente_label" value="<?= e($v['cliente_label']) ?>">

  <!-- Top Primary Fields Grid -->
  <div class="qk-card mb-3">
    <div class="qk-card-header d-flex justify-content-between align-items-center">
      <span class="qk-card-title" style="margin:0">
        <i class="fa-solid fa-file-invoice me-2 text-primary"></i>
        <?= ($v['numero_offerta'] ?? '') ? 'Preventivo ' . e($v['numero_offerta']) : 'Nuovo Preventivo' ?>
      </span>
      <?php if (!($v['numero_offerta'] ?? '')): ?>
        <span class="qk-text-muted" style="font-size:.82rem">Numero ERP provvisorio: <strong><?= e($numero_offerta) ?></strong></span>
      <?php endif; ?>
    </div>
    <div class="qk-card-body">
      <input type="hidden" name="numero_offerta" value="<?= e($v['numero_offerta'] ?: $numero_offerta) ?>">
      
      <div class="qk-form-grid mb-3">
        <!-- Colonna Sinistra: Cliente, Azienda Venditrice e Oggetto -->
        <div>
          <!-- Cliente & Azienda Venditrice in una riga -->
          <div class="qk-form-row mb-3">
            <div class="qk-form-group flex-grow-1">
              <label class="qk-form-label">Cliente <span class="required">*</span></label>
              <div class="d-flex gap-2 align-items-start">
                <div class="qk-ac-wrap flex-grow-1" style="position:relative;">
                  <input type="text" id="cliente_search" class="qk-form-control <?= isset($errors['cliente_id']) ? 'is-invalid' : '' ?>"
                         placeholder="Cerca cliente per nome, ragione sociale, email…"
                         value="<?= e($v['cliente_label']) ?>" autocomplete="off" style="padding-right: 28px;">
                  <button type="button" class="qk-ac-clear <?= $v['cliente_id'] ? '' : 'd-none' ?>" id="btn-clear-cliente-inline" title="Rimuovi cliente">&times;</button>
                  <div class="qk-ac-dropdown" id="ac-clienti-drop"></div>
                </div>
                <button type="button" class="btn-qk btn-qk-secondary" id="btn-nuovo-cliente"
                        title="Crea nuovo cliente" style="white-space:nowrap">
                  <i class="fa-solid fa-plus"></i> Nuovo
                </button>
              </div>
              <div id="cliente-helper-text" class="text-muted small mt-1" style="display:none;">
                <i class="fa-solid fa-circle-info me-1"></i><span id="cliente-helper-content"></span>
              </div>
              <?php if (isset($errors['cliente_id'])): ?>
              <span class="qk-form-error mt-1"><i class="fa-solid fa-circle-xmark"></i> <?= e($errors['cliente_id']) ?></span>
              <?php endif; ?>
            </div>
            
            <div class="qk-form-group" style="flex: 0 0 200px; min-width: 170px;">
              <label class="qk-form-label">Azienda venditrice</label>
              <select name="azienda_interna_id" id="quote-azienda-interna-id" class="qk-form-control" style="font-size: 0.85rem;">
                <?php foreach ($aziende as $az): ?>
                  <option value="<?= $az['id'] ?>" <?= (string)($v['azienda_interna_id'] ?? '') === (string)$az['id'] ? 'selected' : '' ?>>
                    <?= e($az['ragione_sociale']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="qk-form-group" style="flex: 0 0 200px; min-width: 170px;">
              <label class="qk-form-label">Listino prezzi</label>
              <select name="listino_id" id="quote-listino-id" class="qk-form-control" style="font-size: 0.85rem;">
                <option value="">— Nessuno (Prezzi liberi) —</option>
                <?php foreach ($listini as $lst): ?>
                  <option value="<?= $lst['id'] ?>" <?= (string)($v['listino_id'] ?? '') === (string)$lst['id'] ? 'selected' : '' ?>>
                    <?= e($lst['nome']) ?> (<?= e($lst['valuta']) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          
          <!-- Oggetto dell'offerta -->
          <div class="qk-form-group">
            <label class="qk-form-label">Oggetto dell'offerta</label>
            <input type="text" name="oggetto" class="qk-form-control"
                   value="<?= e($v['oggetto'] ?? '') ?>" placeholder="Es. Fornitura annuale ipoclorito di sodio">
          </div>
        </div>
        
        <!-- Colonna Destra: Riferimento, Stato, Date -->
        <div>
          <div class="qk-form-row mb-3">
            <div class="qk-form-group">
              <label class="qk-form-label">Riferimento interno</label>
              <input type="text" name="riferimento_interno" class="qk-form-control"
                     placeholder="Es. Prot. 56/2026"
                     value="<?= e($v['riferimento_interno'] ?? '') ?>">
            </div>
            <div class="qk-form-group">
              <label class="qk-form-label">Stato</label>
              <?php if (empty($id)): ?>
                <input type="hidden" name="stato" value="bozza">
                <input type="text" class="qk-form-control" value="Bozza" readonly style="background-color: var(--qk-bg-subtle); cursor: not-allowed;">
              <?php else: ?>
                <select name="stato" class="qk-form-control">
                  <?php foreach (['bozza'=>'Bozza','inviata'=>'Inviata','accettata'=>'Accettata','rifiutata'=>'Rifiutata','scaduta'=>'Scaduta','annullata'=>'Annullata'] as $k => $lbl): ?>
                  <option value="<?= e($k) ?>" <?= ($v['stato'] ?? 'bozza') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
                  <?php endforeach; ?>
                </select>
              <?php endif; ?>
            </div>
          </div>
          
          <div class="qk-form-row">
            <div class="qk-form-group">
              <label class="qk-form-label">Data offerta <span class="required">*</span></label>
              <input type="date" name="data_offerta" class="qk-form-control <?= isset($errors['data_offerta']) ? 'is-invalid' : '' ?>"
                     value="<?= e($v['data_offerta']) ?>">
              <?php if (isset($errors['data_offerta'])): ?><span class="qk-form-error"><i class="fa-solid fa-circle-xmark"></i> <?= e($errors['data_offerta']) ?></span><?php endif; ?>
            </div>
            <div class="qk-form-group">
              <label class="qk-form-label">Scadenza offerta</label>
              <input type="date" name="data_scadenza" class="qk-form-control" value="<?= e($v['data_scadenza']) ?>">
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Odoo-style Navigation Tabs -->
  <div class="qk-card mb-3">
    <div class="qk-card-body">
      <ul class="nav qk-nav-tabs" role="tablist">
        <li class="nav-item">
          <button class="nav-link active" id="tab-prodotti-btn" type="button" onclick="switchQuoteTab('prodotti')">
            <i class="fa-solid fa-list me-1"></i> Prodotti
          </button>
        </li>
        <li class="nav-item">
          <button class="nav-link" id="tab-spedizione-contatti-btn" type="button" onclick="switchQuoteTab('spedizione-contatti')">
            <i class="fa-solid fa-truck-fast me-1"></i> Spedizione & Contatti
          </button>
        </li>
        <li class="nav-item">
          <button class="nav-link" id="tab-condizioni-commerciali-btn" type="button" onclick="switchQuoteTab('condizioni-commerciali')">
            <i class="fa-solid fa-file-invoice-dollar me-1"></i> Condizioni Commerciali
          </button>
        </li>
        <li class="nav-item">
          <button class="nav-link" id="tab-note-btn" type="button" onclick="switchQuoteTab('note')">
            <i class="fa-solid fa-pen me-1"></i> Note
          </button>
        </li>
      </ul>

      <!-- Tab Content Panes -->
      <div class="tab-content">
        
        <!-- Tab Prodotti -->
        <div class="tab-pane active" id="pane-prodotti">
          
          <!-- Catalogo Prodotti Widget -->
          <div class="qk-card mb-3 p-3 bg-light border-0 shadow-xs" style="border-radius: var(--qk-border-radius-lg, 8px);">
            <div class="d-flex align-items-center justify-content-between mb-2">
              <span class="fw-bold text-dark" style="font-size:0.95rem;">
                <i class="fa-solid fa-cubes me-1 text-primary"></i> Catalogo Prodotti — clicca per aggiungere
              </span>
            </div>
            
            <div class="qk-filter-wrap mb-3" style="max-width: 100%;">
              <i class="fa-solid fa-magnifying-glass"></i>
              <input type="text" id="catalogo-search" class="qk-filter-input w-100" placeholder="Cerca prodotto, codice, confezione..." autocomplete="off">
            </div>

            <!-- Grid di Card Prodotti -->
            <div id="catalogo-grid" class="row g-2 mb-2" style="max-height: 250px; overflow-y: auto;">
              <div class="col-12 text-center text-muted py-3 small">
                Digita nella barra di ricerca per visualizzare i prodotti
              </div>
            </div>
          </div>

          <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
            <h4 style="font-size:1rem; margin:0;"><i class="fa-solid fa-list me-2"></i>Righe preventivo</h4>
            <div class="d-flex align-items-center gap-2 flex-wrap">
              <div class="d-flex align-items-center gap-1 me-2" style="font-size: .85rem;">
                <label class="qk-form-label mb-0 text-muted" style="white-space:nowrap; font-weight:600;">Visualizza in:</label>
                <select id="sales-uom-mode" name="uom_mode" class="qk-form-control" style="width:auto; padding:2px 8px; font-size:.85rem; height:auto;">
                  <option value="commercial" <?= ($v['uom_mode'] ?? 'commercial') === 'commercial' ? 'selected' : '' ?>>Unità commerciale</option>
                  <option value="stock" <?= ($v['uom_mode'] ?? 'commercial') === 'stock' ? 'selected' : '' ?>>Unità di stock</option>
                </select>
              </div>
              <button type="button" id="btn-nuovo-prodotto" class="btn-qk btn-qk-secondary btn-qk-sm">
                <i class="fa-solid fa-box-open"></i> Nuovo prodotto
              </button>
              
              <div class="qk-dropdown-container" style="position:relative; display:inline-block; line-height:1;">
                <button type="button" class="btn-qk btn-qk-secondary btn-qk-sm" id="btn-col-settings" title="Mostra/Nascondi colonne">
                  <i class="fa-solid fa-sliders"></i> Colonne
                </button>
                <div class="qk-dropdown-menu shadow-sm" id="col-settings-dropdown" style="display:none; position:absolute; right:0; top:120%; z-index:1000; background:var(--qk-card-bg); border:1px solid var(--qk-border); border-radius:var(--qk-radius); padding:.6rem .8rem; min-width:180px; text-align:left; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                  <div style="display:flex; flex-direction:column; gap:.5rem;">
                    <label style="display:flex; align-items:center; gap:.6rem; font-size:.85rem; font-weight:500; cursor:pointer; color:var(--qk-text-primary); margin:0;">
                      <input type="checkbox" id="chk-show-conf" style="transform:scale(1.15);" checked>
                      <span>Confezione</span>
                    </label>
                    <label style="display:flex; align-items:center; gap:.6rem; font-size:.85rem; font-weight:500; cursor:pointer; color:var(--qk-text-primary); margin:0;">
                      <input type="checkbox" id="chk-show-sconto" style="transform:scale(1.15);">
                      <span>Sconto</span>
                    </label>
                    <label style="display:flex; align-items:center; gap:.6rem; font-size:.85rem; font-weight:500; cursor:pointer; color:var(--qk-text-primary); margin:0;">
                      <input type="checkbox" id="chk-show-iva" style="transform:scale(1.15);">
                      <span>IVA</span>
                    </label>
                  </div>
                </div>
              </div>

              <button type="button" id="btn-aggiungi-riga" class="btn-qk btn-qk-primary btn-qk-sm">
                <i class="fa-solid fa-plus"></i> Aggiungi riga
              </button>
            </div>
          </div>
          
          <div class="qk-table-wrap">
            <table class="qk-table" id="tbl-righe">
              <thead>
                <tr>
                  <th class="col-search">Cerca prodotto</th>
                  <th class="col-desc">Descrizione</th>
                  <th class="col-conf">Confezione <i class="fa-solid fa-circle-info" data-bs-toggle="tooltip" title="Es. secchio 5 kg" style="cursor:help; font-size:0.8rem; opacity:0.8;"></i></th>
                  <th class="col-qta">Qtà <span class="required">*</span></th>
                  <th class="col-um">U.M.</th>
                  <th class="col-costo">Costo</th>
                  <th class="col-range">Range RRP</th>
                  <th class="col-pu">Prezzo unit.</th>
                  <th class="col-sc">Sc. %</th>
                  <th class="col-iva">IVA %</th>
                  <th class="col-tot">Totale</th>
                  <th class="col-delta">Delta</th>
                  <th class="col-del"></th>
                </tr>
              </thead>
              <tbody id="tbody-righe">
                <!-- Righe gestite via JS -->
              </tbody>
            </table>
            <?php if (isset($errors['righe'])): ?>
            <div style="padding:.5rem 1rem">
              <span class="qk-form-error"><i class="fa-solid fa-circle-xmark"></i> <?= e($errors['righe']) ?></span>
            </div>
            <?php endif; ?>
          </div>

          <div style="padding:1rem 1.25rem; border-top: 1px solid var(--qk-border); margin-top:1rem;">
            <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
              <div style="max-width:260px">
                <div class="qk-form-group mb-0">
                  <label class="qk-form-label">Sconto globale %</label>
                  <input type="number" name="sconto_globale_perc" id="sconto_globale"
                         class="qk-form-control" style="width:120px"
                         value="<?= e($v['sconto_globale_perc']) ?>" min="0" max="100" step="0.01" placeholder="0">
                </div>
              </div>
              <div class="qk-totali-grid" style="min-width:280px">
                <span class="lbl">Imponibile</span>
                <span class="val" id="tot-imponibile">€ 0,00</span>
                <span class="lbl">IVA (22%)</span>
                <span class="val" id="tot-iva">€ 0,00</span>
                <hr class="qk-totali-sep">
                <span class="lbl lbl-total">Totale IVA inclusa</span>
                <span class="val val-total" id="tot-totale">€ 0,00</span>
              </div>
            </div>

            <!-- Margins Summary Row (Mockup Style) -->
            <div class="row g-3 mt-3 pt-3 border-top">
              <div class="col-6 col-md-3">
                <div class="p-3 rounded border bg-light shadow-xs text-center">
                  <div class="text-muted small fw-bold text-uppercase" style="font-size:0.75rem;">Totale Cliente</div>
                  <div class="fs-4 fw-bold text-dark mt-1" id="box-tot-cliente">€ 0,00</div>
                </div>
              </div>
              <div class="col-6 col-md-3">
                <div class="p-3 rounded border bg-light shadow-xs text-center">
                  <div class="text-muted small fw-bold text-uppercase" style="font-size:0.75rem;">Totale Costo</div>
                  <div class="fs-4 fw-bold text-dark mt-1" id="box-tot-costo">€ 0,00</div>
                </div>
              </div>
              <div class="col-6 col-md-3">
                <div class="p-3 rounded border shadow-xs text-center" id="box-margine-euro-container" style="background-color: rgba(40,167,69,0.06); border-color: rgba(40,167,69,0.2);">
                  <div class="text-muted small fw-bold text-uppercase" style="font-size:0.75rem;">Margine €</div>
                  <div class="fs-4 fw-bold text-success mt-1" id="box-margine-euro">€ 0,00</div>
                </div>
              </div>
              <div class="col-6 col-md-3">
                <div class="p-3 rounded border shadow-xs text-center" id="box-margine-perc-container" style="background-color: rgba(40,167,69,0.06); border-color: rgba(40,167,69,0.2);">
                  <div class="text-muted small fw-bold text-uppercase" style="font-size:0.75rem;">Margine %</div>
                  <div class="fs-4 fw-bold text-success mt-1" id="box-margine-perc">0,00%</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Tab Spedizione & Contatti -->
        <div class="tab-pane d-none" id="pane-spedizione-contatti">
          <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap:1.5rem;">
            
            <!-- Destinatario & Spedizione -->
            <div>
              <h4 style="font-size:.9rem; text-transform:uppercase; letter-spacing:.05em; color:var(--qk-text-muted); margin-bottom:1rem; border-bottom:1px solid var(--qk-border); padding-bottom:.5rem;"><i class="fa-solid fa-truck-ramp-box me-1"></i>Spedizione & Destinazione</h4>
              
              <div class="qk-form-group">
                <label class="qk-form-label">Seleziona indirizzo di consegna</label>
                <select name="indirizzo_spedizione_id" id="quote-indirizzo-spedizione-id" class="qk-form-control" data-selected-id="<?= e($v['indirizzo_spedizione_id'] ?? '') ?>">
                  <option value="">Seleziona cliente prima...</option>
                </select>
              </div>

              <!-- Campi dettagli indirizzo (Snapshot/Override) -->
              <div id="sped-fields-box" class="border rounded p-3 mb-3" style="display:none; background: var(--qk-bg-subtle); border-color: var(--qk-border) !important;">
                <div class="d-flex justify-content-between align-items-center mb-3">
                  <h6 class="mb-0 fw-bold" style="font-size: 0.85rem;">
                    <i class="fa-solid fa-map-location-dot me-1"></i>Dettagli Spedizione
                    <span id="sped-state-badge" style="font-size: 0.72rem; vertical-align: middle;"></span>
                  </h6>
                  <button type="button" class="btn-qk btn-qk-xs btn-qk-ghost text-danger" id="btn-cancel-edit-sped" title="Annulla modifiche / Ripristina valori anagrafica"><i class="fa-solid fa-rotate-left me-1"></i>Ripristina</button>
                </div>
                
                <input type="hidden" name="consegna_nome_sede" id="consegna_nome_sede" value="">
                
                <div class="qk-form-row mb-2">
                  <div class="qk-form-group flex-grow-1">
                    <label class="qk-form-label">Via / Piazza <span class="required">*</span></label>
                    <input type="text" name="consegna_via" id="consegna_via" class="qk-form-control" value="<?= e($v['consegna_via'] ?? '') ?>">
                  </div>
                  <div class="qk-form-group" style="flex: 0 0 80px;">
                    <label class="qk-form-label">Civico</label>
                    <input type="text" name="consegna_civico" id="consegna_civico" class="qk-form-control" value="<?= e($v['consegna_civico'] ?? '') ?>">
                  </div>
                </div>

                <div class="qk-form-row mb-2">
                  <div class="qk-form-group" style="flex: 0 0 100px;">
                    <label class="qk-form-label">CAP <span class="required">*</span></label>
                    <input type="text" name="consegna_cap" id="consegna_cap" class="qk-form-control" value="<?= e($v['consegna_cap'] ?? '') ?>">
                  </div>
                  <div class="qk-form-group flex-grow-1">
                    <label class="qk-form-label">Città <span class="required">*</span></label>
                    <input type="text" name="consegna_citta" id="consegna_citta" class="qk-form-control" value="<?= e($v['consegna_citta'] ?? '') ?>">
                  </div>
                </div>
                
                <div class="qk-form-row mb-2">
                  <div class="qk-form-group">
                    <label class="qk-form-label">Provincia</label>
                    <input type="text" name="consegna_provincia" id="consegna_provincia" class="qk-form-control" maxlength="2" placeholder="Es. MI" value="<?= e($v['consegna_provincia'] ?? '') ?>">
                  </div>
                  <div class="qk-form-group">
                    <label class="qk-form-label">Nazione</label>
                    <input type="text" name="consegna_nazione" id="consegna_nazione" class="qk-form-control" maxlength="2" placeholder="IT" value="<?= e($v['consegna_nazione'] ?? 'IT') ?>">
                  </div>
                </div>

                <div class="qk-form-group mb-2">
                  <label class="qk-form-label">Presso (c/o)</label>
                  <input type="text" name="consegna_presso" id="consegna_presso" class="qk-form-control" value="<?= e($v['consegna_presso'] ?? '') ?>" placeholder="Es. Magazzino logistica, Cantiere B">
                </div>

                <div class="qk-form-row mb-2">
                  <div class="qk-form-group">
                    <label class="qk-form-label">Referente Consegna</label>
                    <input type="text" name="consegna_referente" id="consegna_referente" class="qk-form-control" value="<?= e($v['consegna_referente'] ?? '') ?>" placeholder="Persona sul posto">
                  </div>
                  <div class="qk-form-group">
                    <label class="qk-form-label">Tel. Consegna</label>
                    <input type="text" name="consegna_telefono" id="consegna_telefono" class="qk-form-control" value="<?= e($v['consegna_telefono'] ?? '') ?>">
                  </div>
                </div>

                <div class="qk-form-group mb-3">
                  <label class="qk-form-label">Note Consegna</label>
                  <input type="text" name="consegna_note" id="consegna_note" class="qk-form-control" value="<?= e($v['consegna_note'] ?? '') ?>" placeholder="Es. Consegnare solo al mattino, citofono 2">
                </div>

                <!-- Checkbox salva in anagrafica -->
                <div id="div-salva-in-anagrafica-addr" style="display:none; align-items: center; gap: 0.5rem; padding-top: 0.25rem;">
                  <input type="checkbox" name="salva_in_anagrafica_indirizzo" id="salva_in_anagrafica_indirizzo" value="1" style="transform: scale(1.1);">
                  <label class="qk-form-label mb-0" for="salva_in_anagrafica_indirizzo" style="cursor:pointer; color: var(--qk-accent);">
                    Salva in anagrafica per usi futuri
                  </label>
                </div>
              </div>

              <div class="qk-form-row">
                <div class="qk-form-group">
                  <label class="qk-form-label">Data consegna richiesta</label>
                  <input type="date" name="data_consegna_richiesta" class="qk-form-control" value="<?= e($v['data_consegna_richiesta']) ?>">
                </div>
                <div class="qk-form-group">
                  <label class="qk-form-label">Trasporto (Resa)</label>
                  <input type="text" name="trasporto" class="qk-form-control" value="<?= e($v['trasporto']) ?>" placeholder="Es. Franco destino">
                </div>
              </div>
            </div>

            <!-- Referente & Recapiti -->
            <div>
              <h4 style="font-size:.9rem; text-transform:uppercase; letter-spacing:.05em; color:var(--qk-text-muted); margin-bottom:1rem; border-bottom:1px solid var(--qk-border); padding-bottom:.5rem;"><i class="fa-solid fa-address-card me-1"></i>Referente Cliente</h4>
              
              <div class="qk-form-group">
                <label class="qk-form-label">Seleziona referente da anagrafica</label>
                <select name="contatto_id" id="quote-contatto-id" class="qk-form-control" data-selected-id="<?= e($v['contatto_id'] ?? '') ?>">
                  <option value="">Seleziona cliente prima...</option>
                </select>
                <!-- Fallback info -->
                <input type="hidden" id="customer-generale-telefono" value="">
                <input type="hidden" id="customer-generale-email" value="">
              </div>

              <!-- Dettagli Referente sul Documento -->
              <div id="cont-fields-box" class="border rounded p-3 mb-3" style="background: var(--qk-bg-subtle); border-color: var(--qk-border) !important;">
                <div class="d-flex justify-content-between align-items-center mb-3">
                  <h6 class="mb-0 fw-bold" style="font-size: 0.85rem;">
                    <i class="fa-solid fa-id-card-clip me-1"></i>Dettagli Stampati
                    <span id="cont-state-badge" style="font-size: 0.72rem; vertical-align: middle;"></span>
                  </h6>
                  <button type="button" class="btn-qk btn-qk-xs btn-qk-ghost text-danger" id="btn-cancel-edit-cont" title="Annulla modifiche / Ripristina valori anagrafica"><i class="fa-solid fa-rotate-left me-1"></i>Ripristina</button>
                </div>
                
                <div class="qk-form-group mb-2">
                  <label class="qk-form-label">Alla c.a. di (Nome Referente)</label>
                  <input type="text" name="contatto_nome" id="quote-contatto-nome" class="qk-form-control" value="<?= e($v['contatto_nome'] ?? $v['alla_ca'] ?? '') ?>" placeholder="Es. Dott. Mario Rossi">
                </div>

                <div class="qk-form-row">
                  <div class="qk-form-group">
                    <label class="qk-form-label">Telefono referente</label>
                    <input type="text" name="contatto_telefono" id="quote-contatto-telefono" class="qk-form-control" placeholder="Es. +39 02 123456" value="<?= e($v['contatto_telefono']) ?>">
                  </div>
                  <div class="qk-form-group">
                    <label class="qk-form-label">Email referente</label>
                    <input type="email" name="contatto_email" id="quote-contatto-email" class="qk-form-control" placeholder="Es. mario.rossi@cliente.com" value="<?= e($v['contatto_email']) ?>">
                  </div>
                </div>
              </div>
            </div>

          </div>
        </div>

        <!-- Tab Condizioni Commerciali -->
        <div class="tab-pane d-none" id="pane-condizioni-commerciali">
          <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:1.5rem;">
            
            <!-- Fatturazione & Canali -->
            <div>
              <h4 style="font-size:.9rem; text-transform:uppercase; letter-spacing:.05em; color:var(--qk-text-muted); margin-bottom:1rem; border-bottom:1px solid var(--qk-border); padding-bottom:.5rem;"><i class="fa-solid fa-file-invoice-dollar me-1"></i>Vendita & Canali</h4>
              <div class="qk-form-row">
                <div class="qk-form-group">
                  <label class="qk-form-label">Canale vendita</label>
                  <select name="canale_vendita" class="qk-form-control">
                    <?php foreach (['email_diretta'=>'Email diretta','dropshipping'=>'Dropshipping','agente'=>'Agente','sito'=>'Sito web'] as $kk => $lbl): ?>
                    <option value="<?= e($kk) ?>" <?= ($v['canale_vendita'] ?? 'email_diretta') === $kk ? 'selected' : '' ?>><?= e($lbl) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="qk-form-group">
                  <label class="qk-form-label">Valuta</label>
                  <select name="valuta" class="qk-form-control">
                    <option value="EUR" <?= ($v['valuta'] ?? 'EUR') === 'EUR' ? 'selected' : '' ?>>EUR (€)</option>
                    <option value="USD" <?= ($v['valuta'] ?? '') === 'USD' ? 'selected' : '' ?>>USD ($)</option>
                  </select>
                </div>
              </div>
            </div>

            <!-- Condizioni Pagamento -->
            <div>
              <h4 style="font-size:.9rem; text-transform:uppercase; letter-spacing:.05em; color:var(--qk-text-muted); margin-bottom:1rem; border-bottom:1px solid var(--qk-border); padding-bottom:.5rem;"><i class="fa-solid fa-credit-card me-1"></i>Pagamento</h4>
              <div class="qk-form-group">
                <label class="qk-form-label">Condizioni di pagamento</label>
                <input type="text" name="condizioni_pagamento" id="quote-condizioni-pagamento" class="qk-form-control" value="<?= e($v['condizioni_pagamento']) ?>" placeholder="Es. 30 gg d.f.f.m.">
              </div>
            </div>

          </div>
        </div>

        <!-- Tab Note -->
        <div class="tab-pane d-none" id="pane-note">
          <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:1.5rem;">
            <div class="qk-form-group">
              <label class="qk-form-label"><i class="fa-solid fa-note-sticky me-1"></i>Note per il cliente (in offerta)</label>
              <textarea name="note_cliente" class="qk-form-control" rows="5"
                        placeholder="Note visibili sul preventivo stampato..."><?= e($v['note_cliente']) ?></textarea>
            </div>
            <div class="qk-form-group">
              <label class="qk-form-label"><i class="fa-solid fa-lock me-1"></i>Note interne (non stampate)</label>
              <textarea name="note_interne" class="qk-form-control" rows="5"
                        placeholder="Memo o note per il team interno..."><?= e($v['note_interne']) ?></textarea>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>

  <!-- Sticky Actions -->
  <div class="qk-form-sticky-actions">
    <?php if ($can_edit): ?>
      <button type="submit" name="stato" value="bozza" class="btn-qk btn-qk-secondary">
        <i class="fa-solid fa-floppy-disk"></i> Salva bozza
      </button>
      <button type="submit" name="stato" value="inviata" class="btn-qk btn-qk-primary">
        <i class="fa-solid fa-paper-plane"></i> Salva e invia
      </button>
    <?php else: ?>
      <span class="qk-text-muted"><i class="fa-solid fa-lock me-1"></i> Questo preventivo non è più in stato bozza ed è bloccato in sola lettura.</span>
    <?php endif; ?>
    <a href="<?= url('sales/quotes') ?>" class="btn-qk btn-qk-secondary" style="margin-left:auto">
      <i class="fa-solid fa-xmark"></i> Torna alla lista
    </a>
  </div>

  </fieldset>
</form><!-- /form-offerta -->


<!-- ══════════════════════ MODALE NUOVO CLIENTE ═════════════════════════════ -->
<div class="modal fade" id="modal-nuovo-cliente" tabindex="-1" aria-labelledby="mnc-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="background:var(--qk-card-bg);color:var(--qk-text-primary);border:1px solid var(--qk-border)">
      <div class="modal-header" style="border-color:var(--qk-border)">
        <h5 class="modal-title" id="mnc-title"><i class="fa-solid fa-user-plus me-2"></i>Nuovo cliente rapido</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
      </div>
      <div class="modal-body">
        <div id="mnc-error" class="qk-alert qk-alert-danger mb-3" style="display:none">
          <i class="fa-solid fa-circle-xmark"></i> <span id="mnc-error-msg"></span>
        </div>
        <form id="form-nuovo-cliente" novalidate>
          <!-- Tipo soggetto -->
          <div class="qk-form-group">
            <label class="qk-form-label">Tipo soggetto</label>
            <div class="d-flex gap-2">
              <label class="qk-radio-card is-selected" id="rc-azienda">
                <input type="radio" name="tipo_soggetto" value="azienda" checked>
                <i class="fa-solid fa-building"></i> Azienda
              </label>
              <label class="qk-radio-card" id="rc-privato">
                <input type="radio" name="tipo_soggetto" value="privato">
                <i class="fa-solid fa-user"></i> Privato
              </label>
            </div>
          </div>
          <!-- Ragione sociale -->
          <div class="qk-form-group" id="mnc-rs-wrap">
            <label class="qk-form-label">Ragione sociale <span class="required">*</span></label>
            <input type="text" name="ragione_sociale" id="mnc-ragione-sociale" class="qk-form-control"
                   placeholder="Acme S.r.l.">
          </div>
          <!-- Nome / cognome per privati -->
          <div class="d-none" id="mnc-nc-wrap">
            <div class="qk-form-row">
              <div class="qk-form-group">
                <label class="qk-form-label">Nome <span class="required">*</span></label>
                <input type="text" name="nome" class="qk-form-control" placeholder="Mario">
              </div>
              <div class="qk-form-group">
                <label class="qk-form-label">Cognome <span class="required">*</span></label>
                <input type="text" name="cognome" class="qk-form-control" placeholder="Rossi">
              </div>
            </div>
          </div>
          <!-- Contatti -->
          <div class="qk-form-row">
            <div class="qk-form-group">
              <label class="qk-form-label">Email</label>
              <input type="email" name="email" class="qk-form-control" placeholder="info@azienda.it">
            </div>
            <div class="qk-form-group">
              <label class="qk-form-label">Telefono</label>
              <input type="text" name="telefono" class="qk-form-control" placeholder="+39 0000 000000">
            </div>
          </div>
        </form>
      </div>
      <div class="modal-footer" style="border-color:var(--qk-border)">
        <button type="button" class="btn-qk btn-qk-secondary" data-bs-dismiss="modal">Annulla</button>
        <button type="button" class="btn-qk btn-qk-primary" id="btn-salva-cliente">
          <i class="fa-solid fa-floppy-disk"></i> Crea cliente
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ══════════════════════ MODALE NUOVO PRODOTTO ════════════════════════════ -->
<div class="modal fade" id="modal-nuovo-prodotto" tabindex="-1" aria-labelledby="mnp-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="background:var(--qk-card-bg);color:var(--qk-text-primary);border:1px solid var(--qk-border)">
      <div class="modal-header" style="border-color:var(--qk-border)">
        <h5 class="modal-title" id="mnp-title"><i class="fa-solid fa-box-open me-2"></i>Nuovo prodotto rapido</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
      </div>
      <div class="modal-body">
        <div id="mnp-error" class="qk-alert qk-alert-danger mb-3" style="display:none">
          <i class="fa-solid fa-circle-xmark"></i> <span id="mnp-error-msg"></span>
        </div>
        <form id="form-nuovo-prodotto" novalidate>
          <div class="qk-form-group">
            <label class="qk-form-label">Nome prodotto <span class="required">*</span></label>
            <input type="text" name="nome" id="mnp-nome" class="qk-form-control"
                   placeholder="Es. Flocculante liquido 5 Kg">
          </div>
          <div class="qk-form-row">
            <div class="qk-form-group">
              <label class="qk-form-label">SKU interno</label>
              <input type="text" name="sku_interno" class="qk-form-control"
                     placeholder="Lascia vuoto per auto-generare">
            </div>
            <div class="qk-form-group">
              <label class="qk-form-label">Unità di misura</label>
              <select name="unita_misura" class="qk-form-control">
                <option value="pz">pz</option>
                <option value="kg">kg</option>
                <option value="l">l</option>
                <option value="g">g</option>
                <option value="ml">ml</option>
              </select>
            </div>
          </div>
          <p class="qk-text-muted" style="font-size:.82rem">
            <i class="fa-solid fa-circle-info me-1"></i>
            Il prezzo verrà impostato direttamente nella riga dell'offerta.
          </p>
        </form>
      </div>
      <div class="modal-footer" style="border-color:var(--qk-border)">
        <button type="button" class="btn-qk btn-qk-secondary" data-bs-dismiss="modal">Annulla</button>
        <button type="button" class="btn-qk btn-qk-primary" id="btn-salva-prodotto">
          <i class="fa-solid fa-floppy-disk"></i> Crea prodotto
        </button>
      </div>
    </div>
  </div>
</div>
<!-- ══════════════════════════════ JAVASCRIPT ═══════════════════════════════ -->
<script>
document.addEventListener('DOMContentLoaded', function () {
'use strict';

const CSRF   = <?= json_encode(csrf_token()) ?>;
const BASE   = (typeof QK_BASE !== 'undefined') ? QK_BASE : '';
const PRELOADED_RIGHE = <?= json_encode($preloadedRighe ?? []) ?>;

/* ─────────────────────────────────────────────────────────────────────────
   UTILITÀ & AC FETCH
 ───────────────────────────────────────────────────────────────────────── */
async function fetchClienti(q) {
  try {
    const res  = await fetch(BASE + '/sales/quotes/api/clienti?q=' + encodeURIComponent(q), { credentials: 'same-origin' });
    return await res.json();
  } catch (e) { return []; }
}

async function fetchProdotti(q) {
  try {
    const res  = await fetch(BASE + '/sales/quotes/api/prodotti?q=' + encodeURIComponent(q), { credentials: 'same-origin' });
    return await res.json();
  } catch (e) { return []; }
}

/* ─────────────────────────────────────────────────────────────────────────
   GESTIONE CLIENTE
 ───────────────────────────────────────────────────────────────────────── */

const elClienteSearch       = document.getElementById('cliente_search');
const elClienteId           = document.getElementById('cliente_id');
const elClienteLabel        = document.getElementById('cliente_label');
const elClienteDrop         = document.getElementById('ac-clienti-drop');
const elBtnClearInline      = document.getElementById('btn-clear-cliente-inline');
const elHelperText          = document.getElementById('cliente-helper-text');
const elHelperContent       = document.getElementById('cliente-helper-content');

function selectCliente(item) {
  elClienteId.value    = item.id;
  elClienteLabel.value = item.label;
  elClienteSearch.value = item.label;
  if (elBtnClearInline) elBtnClearInline.classList.remove('d-none');
  
  const parts = [item.email, item.telefono].filter(Boolean);
  if (elHelperText && elHelperContent && parts.length > 0) {
    elHelperContent.textContent = parts.join(' · ');
    elHelperText.style.display = '';
  } else if (elHelperText) {
    elHelperText.style.display = 'none';
  }

  checkAllRowsCompliance();
  loadClienteDettagli();
}

function clearCliente() {
  elClienteId.value    = '';
  elClienteLabel.value = '';
  elClienteSearch.value = '';
  if (elBtnClearInline) elBtnClearInline.classList.add('d-none');
  
  if (elHelperText && elHelperContent) {
    elHelperContent.textContent = '';
    elHelperText.style.display = 'none';
  }

  checkAllRowsCompliance();
  
  const spedSelect = document.getElementById('quote-indirizzo-spedizione-id');
  const contSelect = document.getElementById('quote-contatto-id');
  if (spedSelect) spedSelect.innerHTML = '<option value="">Seleziona cliente prima...</option>';
  if (contSelect) contSelect.innerHTML = '<option value="">Seleziona cliente prima...</option>';
}

if (elBtnClearInline) {
  elBtnClearInline.addEventListener('click', clearCliente);
}

elClienteSearch.addEventListener('input', () => {
  // Se svuota il campo, rimuovi la selezione
  if (!elClienteSearch.value.trim()) clearCliente();
});

window.qkAutocomplete({
  input:       elClienteSearch,
  dropdown:    elClienteDrop,
  fetchFn:     fetchClienti,
  onSelect:    selectCliente,
  onCreate:    () => openModal('modal-nuovo-cliente'),
  createLabel: 'Crea nuovo cliente',
});

/* ─────────────────────────────────────────────────────────────────────────
   RIGHE PRODOTTO
 ───────────────────────────────────────────────────────────────────────── */

let righeCount = 0;
let currentUomMode = document.getElementById('sales-uom-mode')?.value || 'commercial';

function syncRowMetadata(tr, metadata) {
  if (metadata.uomStock !== undefined) {
    tr.dataset.uomStock = metadata.uomStock;
    tr.querySelector('.inp-uom-stock').value = metadata.uomStock;
  }
  if (metadata.uomStockId !== undefined) {
    tr.dataset.uomStockId = metadata.uomStockId;
    tr.querySelector('.inp-uom-stock-id').value = metadata.uomStockId;
  }
  if (metadata.uomCommercial !== undefined) {
    tr.dataset.uomCommercial = metadata.uomCommercial;
    tr.querySelector('.inp-uom-commercial').value = metadata.uomCommercial;
  }
  if (metadata.uomCommercialId !== undefined) {
    tr.dataset.uomCommercialId = metadata.uomCommercialId;
    tr.querySelector('.inp-uom-commercial-id').value = metadata.uomCommercialId;
  }
  if (metadata.quantitaContenuto !== undefined) {
    tr.dataset.quantitaContenuto = metadata.quantitaContenuto;
    tr.querySelector('.inp-quantita-contenuto').value = metadata.quantitaContenuto;
  }
  if (metadata.sku !== undefined) {
    tr.querySelector('.inp-sku').value = metadata.sku;
    const skuDiv = tr.querySelector('.riga-sku');
    if (skuDiv) skuDiv.textContent = metadata.sku ? 'SKU: ' + metadata.sku : '';
  }
  if (metadata.quantitaConfezione !== undefined) {
    tr.querySelector('.inp-quantita-confezione').value = metadata.quantitaConfezione;
    const confInput = tr.querySelector('.riga-conf');
    if (confInput) confInput.value = metadata.quantitaConfezione;
  }
  if (metadata.costoBase !== undefined) {
    tr.dataset.costoBase = metadata.costoBase;
  }
  if (metadata.rrpMin !== undefined) {
    tr.dataset.rrpMin = metadata.rrpMin;
  }
  if (metadata.rrpMax !== undefined) {
    tr.dataset.rrpMax = metadata.rrpMax;
  }
}

function setRowUomMode(tr, targetMode, currentMode) {
  const qFactor = parseFloat(tr.dataset.quantitaContenuto) || 1;
  const qtyInput = tr.querySelector('.inp-qta');
  const priceInput = tr.querySelector('.inp-pu');
  const umInput = tr.querySelector('.inp-um');

  if (!qtyInput || !priceInput || !umInput) return;

  // Se il prodotto non ha una unità commerciale definita (quantità contenuto non impostata o pari a 1)
  const haUnitaCommerciale = tr.dataset.uomCommercialId && qFactor > 1;
  if (!haUnitaCommerciale) {
    umInput.value = tr.dataset.uomStock || 'pz';
    // Se eravamo in visualizzazione commerciale, forziamo il reset a valori stock
    if (targetMode === 'commercial' && currentMode === 'stock') {
      // Non convertiamo nulla perché non c'è unità commerciale diversa
    }
    return;
  }

  if (targetMode === currentMode) {
    umInput.value = (targetMode === 'stock') 
      ? (tr.dataset.uomStock || 'pz') 
      : (tr.dataset.uomCommercial || 'pz');
    return;
  }

  const { value: newQty, price: newPrice } = window.qkConvertUom({
    value: qtyInput.value,
    price: priceInput.value,
    qFactor,
    targetMode
  });

  if (qtyInput.value && newQty !== null) qtyInput.value = newQty;
  if (priceInput.value && newPrice !== null) priceInput.value = newPrice;

  umInput.value = (targetMode === 'stock') 
    ? (tr.dataset.uomStock || 'pz') 
    : (tr.dataset.uomCommercial || 'pz');
}

function buildRiga(prefill = {}) {
  const idx = righeCount++;
  const tr  = document.createElement('tr');
  tr.dataset.idx = idx;

  // Initialize dataset attributes
  tr.dataset.uomStock = prefill.uom_stock || prefill.unita_misura || '';
  tr.dataset.uomStockId = prefill.uom_stock_id || '';
  tr.dataset.uomCommercial = prefill.uom_commercial || prefill.unita_misura || '';
  tr.dataset.uomCommercialId = prefill.uom_commercial_id || '';
  tr.dataset.quantitaContenuto = prefill.quantita_contenuto || '1';
  tr.dataset.costoBase = prefill.costo_unitario || prefill.costo_base || '0';
  tr.dataset.rrpMin = prefill.rrp_min !== undefined && prefill.rrp_min !== null ? prefill.rrp_min : '';
  tr.dataset.rrpMax = prefill.rrp_max !== undefined && prefill.rrp_max !== null ? prefill.rrp_max : '';

  tr.innerHTML = `
    <td class="col-search">
      <div class="qk-ac-wrap">
        <input type="text" class="qk-form-control riga-search" placeholder="Cerca…" autocomplete="off" value="${esc(prefill.descrizione || '')}">
        <div class="qk-ac-dropdown riga-ac-drop"></div>
      </div>
      <div class="riga-sku" data-sku="">${prefill.sku ? 'SKU: ' + esc(prefill.sku) : ''}</div>
      <div class="riga-compliance-warn mt-1 text-warning small fw-bold" style="display:none; line-height: 1.2;"></div>
      <input type="hidden" name="righe[${idx}][prodotto_id]" class="inp-prodotto-id" value="${prefill.prodotto_id || ''}">
      
      <!-- Hidden UoM metadata -->
      <input type="hidden" name="righe[${idx}][uom_stock]" class="inp-uom-stock" value="${esc(tr.dataset.uomStock)}">
      <input type="hidden" name="righe[${idx}][uom_stock_id]" class="inp-uom-stock-id" value="${esc(tr.dataset.uomStockId)}">
      <input type="hidden" name="righe[${idx}][uom_commercial]" class="inp-uom-commercial" value="${esc(tr.dataset.uomCommercial)}">
      <input type="hidden" name="righe[${idx}][uom_commercial_id]" class="inp-uom-commercial-id" value="${esc(tr.dataset.uomCommercialId)}">
      <input type="hidden" name="righe[${idx}][quantita_contenuto]" class="inp-quantita-contenuto" value="${esc(tr.dataset.quantitaContenuto)}">
      <input type="hidden" name="righe[${idx}][sku]" class="inp-sku" value="${esc(prefill.sku || '')}">
    </td>
    <td class="col-desc">
      <textarea name="righe[${idx}][descrizione]" class="qk-form-control inp-desc"
                placeholder="Descrizione prodotto" rows="1" style="min-height:31px; height:31px; resize:vertical; padding-top:4px; padding-bottom:4px; line-height:1.2; font-size:0.85rem;">${esc(prefill.descrizione || '')}</textarea>
    </td>
    <td class="col-conf">
      <input type="text" name="righe[${idx}][quantita_confezione]" class="qk-form-control riga-conf inp-quantita-confezione"
             value="${esc(prefill.quantita_confezione || '')}" placeholder="—" title="Confezione">
    </td>
    <td class="col-qta">
      <input type="number" name="righe[${idx}][quantita]" class="qk-form-control text-end inp-qta"
             placeholder="0" min="0" step="0.001" value="${prefill.quantita || ''}">
    </td>
    <td class="col-um">
      <input type="text" name="righe[${idx}][unita_misura]" class="qk-form-control inp-um"
             placeholder="pz" maxlength="5" value="${esc(prefill.unita_misura || '')}">
    </td>
    <td class="col-costo text-end" style="font-weight: 500;">
      <span class="riga-costo-val">—</span>
    </td>
    <td class="col-range text-center small text-muted">
      <span class="riga-range-val">—</span>
    </td>
    <td class="col-pu">
      <div style="position:relative; display:flex; align-items:center; width:100%;">
        <input type="number" name="righe[${idx}][prezzo_unitario]" class="qk-form-control text-end inp-pu"
               placeholder="0,00" min="0" step="0.0001" value="${prefill.prezzo_unitario || ''}" style="padding-right:20px; width:100%;">
        <span class="price-origin-badge" style="position:absolute; right:4px; font-size:0.75rem; cursor:pointer;" title="Nessun prezzo configurato">
          <i class="fa-solid fa-triangle-exclamation" style="color:var(--qk-danger)"></i>
        </span>
      </div>
    </td>
    <td class="col-sc">
      <input type="number" name="righe[${idx}][sconto_perc]" class="qk-form-control text-end inp-sc"
             placeholder="0" min="0" max="100" step="0.01" value="${prefill.sconto_perc || '0'}">
    </td>
    <td class="col-iva">
      <input type="number" name="righe[${idx}][iva_perc]" class="qk-form-control text-end inp-iva"
             placeholder="22" min="0" max="100" step="1" value="${prefill.iva_perc || '22'}">
    </td>
    <td class="col-tot">
      <div class="riga-total">€ 0,00</div>
    </td>
    <td class="col-delta text-end" style="font-weight: 600;">
      <span class="riga-delta-val">—</span>
    </td>
    <td class="col-del">
      <button type="button" class="btn-qk btn-qk-xs btn-qk-ghost text-danger riga-del" title="Rimuovi riga"><i class="fa-solid fa-trash-can"></i></button>
    </td>
  `;

  // Pulsante elimina
  tr.querySelector('.riga-del').addEventListener('click', () => {
    tr.remove();
    calcTotali();
  });

  // Listen to manual price overrides
  const puInput = tr.querySelector('.inp-pu');
  const badge = tr.querySelector('.price-origin-badge');
  if (puInput && badge) {
    puInput.addEventListener('input', () => {
      badge.innerHTML = '<i class="fa-solid fa-pen-to-square" style="color:var(--qk-warning)"></i>';
      badge.title = 'Modificato manualmente';
    });
  }

  // Ricalcola quando cambiano i valori numerici
  ['inp-qta','inp-pu','inp-sc','inp-iva'].forEach(cls => {
    tr.querySelector('.' + cls).addEventListener('input', calcTotali);
  });

  tr.querySelector('.inp-qta').addEventListener('input', () => {
    fetchQuotePrice(tr);
  });

  // Autocomplete prodotto
  const searchInput = tr.querySelector('.riga-search');
  const searchDrop  = tr.querySelector('.riga-ac-drop');

  window.qkAutocomplete({
    input:       searchInput,
    dropdown:    searchDrop,
    fetchFn:     fetchProdotti,
    onSelect:    (item) => {
      tr.querySelector('.inp-prodotto-id').value  = item.id;
      tr.querySelector('.inp-desc').value         = item.label;
      tr.querySelector('.inp-qta').value          = '1';
      tr.querySelector('.inp-pu').value           = '';
      
      // Update metadata structures
      syncRowMetadata(tr, {
        uomStock: item.unita_misura || 'pz',
        uomStockId: item.unita_misura_stock_id || '',
        uomCommercial: item.unita_misura_commerciale || item.unita_misura || 'pz',
        uomCommercialId: item.unita_contenuto_id || item.unita_misura_stock_id || '',
        quantitaContenuto: item.quantita_contenuto || '1',
        sku: item.sku || '',
        quantitaConfezione: item.quantita_confezione || '',
        costoBase: item.costo_base || '0',
        rrpMin: item.rrp_min !== undefined && item.rrp_min !== null ? item.rrp_min : '',
        rrpMax: item.rrp_max !== undefined && item.rrp_max !== null ? item.rrp_max : ''
      });

      // Apply currently active UoM mode (starting from default Stock values)
      setRowUomMode(tr, currentUomMode, 'stock');

      searchInput.value = item.label;
      calcTotali();
      checkRowCompliance(tr);
      fetchQuotePrice(tr);
    },
    onCreate:    () => openModal('modal-nuovo-prodotto', tr),
    createLabel: 'Crea nuovo prodotto',
  });

  // Convert row values if loaded in a different UoM mode than current view mode
  let savedMode = currentUomMode;
  if (prefill.unita_misura) {
    if (prefill.unita_misura === tr.dataset.uomStock) {
      savedMode = 'stock';
    } else if (prefill.unita_misura === tr.dataset.uomCommercial) {
      savedMode = 'commercial';
    }
  }
  if (prefill.unita_misura && savedMode !== currentUomMode) {
    setRowUomMode(tr, currentUomMode, savedMode);
  }

  document.getElementById('tbody-righe').appendChild(tr);
  calcTotali();
  if (typeof updateColumnVisibility === 'function') {
    updateColumnVisibility();
  }
  return tr;
}

function calcTotali() {
  let imponibile = 0;
  let totIva     = 0;
  let totaleCosto = 0;

  document.querySelectorAll('#tbody-righe tr').forEach(tr => {
    const qta  = parseFloat(tr.querySelector('.inp-qta')?.value)  || 0;
    const pu   = parseFloat(tr.querySelector('.inp-pu')?.value)   || 0;
    const sc   = parseFloat(tr.querySelector('.inp-sc')?.value)   || 0;
    const iva  = parseFloat(tr.querySelector('.inp-iva')?.value)  || 22;
    const pn   = pu * (1 - sc / 100);
    const tot  = Math.round(pn * qta * 100) / 100;
    const rig  = Math.round(tot * iva / 100 * 100) / 100;
    imponibile += tot;
    totIva     += rig;
    if (tr.querySelector('.riga-total')) {
      tr.querySelector('.riga-total').textContent = window.qkFormatEuro(tot);
    }

    // UoM factor and Cost/RRP math
    const qFactor = parseFloat(tr.dataset.quantitaContenuto) || 1;
    const costoBase = parseFloat(tr.dataset.costoBase) || 0;
    const rrpMinRaw = tr.dataset.rrpMin ? parseFloat(tr.dataset.rrpMin) : null;
    const rrpMaxRaw = tr.dataset.rrpMax ? parseFloat(tr.dataset.rrpMax) : null;

    let costoConvertito = costoBase;
    let rrpMinConvertito = rrpMinRaw;
    let rrpMaxConvertito = rrpMaxRaw;

    if (currentUomMode === 'commercial' && qFactor > 1) {
      costoConvertito = costoBase / qFactor;
      if (rrpMinConvertito !== null) rrpMinConvertito = rrpMinRaw / qFactor;
      if (rrpMaxConvertito !== null) rrpMaxConvertito = rrpMaxRaw / qFactor;
    }

    const rowCostTotal = Math.round(costoConvertito * qta * 100) / 100;
    totaleCosto += rowCostTotal;

    const rowDelta = tot - rowCostTotal;

    // Display values
    const elCosto = tr.querySelector('.riga-costo-val');
    if (elCosto) {
      elCosto.textContent = costoBase > 0 ? window.qkFormatEuro(costoConvertito) : '—';
    }

    const elRange = tr.querySelector('.riga-range-val');
    if (elRange) {
      if (rrpMinConvertito !== null && rrpMaxConvertito !== null) {
        elRange.textContent = `€ ${rrpMinConvertito.toFixed(2)} → € ${rrpMaxConvertito.toFixed(2)}`;
      } else {
        elRange.textContent = '—';
      }
    }

    const elDelta = tr.querySelector('.riga-delta-val');
    if (elDelta) {
      elDelta.textContent = window.qkFormatEuro(rowDelta);
      if (rowDelta >= 0) {
        elDelta.style.color = '#28a745';
      } else {
        elDelta.style.color = 'var(--qk-danger)';
      }
    }
  });

  const scGlob = parseFloat(document.getElementById('sconto_globale')?.value) || 0;
  const impSc  = Math.round(imponibile * (1 - scGlob / 100) * 100) / 100;
  const ivaSc  = Math.round(totIva    * (1 - scGlob / 100) * 100) / 100;
  const totale = Math.round((impSc + ivaSc) * 100) / 100;

  document.getElementById('tot-imponibile').textContent = window.qkFormatEuro(impSc);
  document.getElementById('tot-iva').textContent        = window.qkFormatEuro(ivaSc);
  document.getElementById('tot-totale').textContent     = window.qkFormatEuro(totale);

  // Update summary boxes
  const boxTotCliente = document.getElementById('box-tot-cliente');
  if (boxTotCliente) boxTotCliente.textContent = window.qkFormatEuro(impSc);

  const boxTotCosto = document.getElementById('box-tot-costo');
  if (boxTotCosto) boxTotCosto.textContent = window.qkFormatEuro(totaleCosto);

  const boxMargineEuro = document.getElementById('box-margine-euro');
  const boxMargineEuroCont = document.getElementById('box-margine-euro-container');
  const marginEuro = impSc - totaleCosto;
  if (boxMargineEuro) {
    boxMargineEuro.textContent = window.qkFormatEuro(marginEuro);
    if (marginEuro >= 0) {
      boxMargineEuro.className = 'fs-4 fw-bold text-success mt-1';
      if (boxMargineEuroCont) {
        boxMargineEuroCont.style.backgroundColor = 'rgba(40,167,69,0.06)';
        boxMargineEuroCont.style.borderColor = 'rgba(40,167,69,0.2)';
      }
    } else {
      boxMargineEuro.className = 'fs-4 fw-bold text-danger mt-1';
      if (boxMargineEuroCont) {
        boxMargineEuroCont.style.backgroundColor = 'rgba(220,53,69,0.06)';
        boxMargineEuroCont.style.borderColor = 'rgba(220,53,69,0.2)';
      }
    }
  }

  const boxMarginePerc = document.getElementById('box-margine-perc');
  const boxMarginePercCont = document.getElementById('box-margine-perc-container');
  const marginPerc = impSc > 0 ? (marginEuro / impSc) * 100 : 0;
  if (boxMarginePerc) {
    boxMarginePerc.textContent = marginPerc.toFixed(2) + '%';
    if (marginPerc >= 20) {
      boxMarginePerc.className = 'fs-4 fw-bold text-success mt-1';
      if (boxMarginePercCont) {
        boxMarginePercCont.style.backgroundColor = 'rgba(40,167,69,0.06)';
        boxMarginePercCont.style.borderColor = 'rgba(40,167,69,0.2)';
      }
    } else if (marginPerc >= 10) {
      boxMarginePerc.className = 'fs-4 fw-bold text-warning mt-1';
      if (boxMarginePercCont) {
        boxMarginePercCont.style.backgroundColor = 'rgba(255,193,7,0.06)';
        boxMarginePercCont.style.borderColor = 'rgba(255,193,7,0.2)';
      }
    } else {
      boxMarginePerc.className = 'fs-4 fw-bold text-danger mt-1';
      if (boxMarginePercCont) {
        boxMarginePercCont.style.backgroundColor = 'rgba(220,53,69,0.06)';
        boxMarginePercCont.style.borderColor = 'rgba(220,53,69,0.2)';
      }
    }
  }
}

// UoM Toggling Listener
const elUomMode = document.getElementById('sales-uom-mode');
if (elUomMode) {
  elUomMode.addEventListener('change', () => {
    const newMode = elUomMode.value;
    document.querySelectorAll('#tbody-righe tr').forEach(tr => {
      setRowUomMode(tr, newMode, currentUomMode);
    });
    currentUomMode = newMode;
    calcTotali();
  });
}

document.getElementById('btn-aggiungi-riga').addEventListener('click', () => buildRiga());
document.getElementById('sconto_globale').addEventListener('input', calcTotali);

// Load preloaded rows if any exist
if (PRELOADED_RIGHE && PRELOADED_RIGHE.length > 0) {
  PRELOADED_RIGHE.forEach(r => buildRiga(r));
} else {
  buildRiga();
}

// Catalogo Prodotti Logic
const elCatSearch = document.getElementById('catalogo-search');
const elCatGrid = document.getElementById('catalogo-grid');

let catalogoProdottiData = [];

async function renderCatalogo(q = '') {
  if (q.trim().length === 0) {
    elCatGrid.innerHTML = `
      <div class="col-12 text-center text-muted py-3 small">
        Digita nella barra di ricerca per visualizzare i prodotti
      </div>
    `;
    return;
  }
  
  try {
    const res = await fetchProdotti(q);
    catalogoProdottiData = res;
    if (!res || res.length === 0) {
      elCatGrid.innerHTML = `
        <div class="col-12 text-center text-muted py-3 small">
          Nessun prodotto trovato
        </div>
      `;
      return;
    }
    
    let html = '';
    res.forEach((prod, index) => {
      const label = prod.label || prod.nome || '';
      const sku = prod.sku || prod.sku_interno || '';
      const confezione = prod.quantita_confezione || prod.descrizione_confezione || '—';
      const costo = parseFloat(prod.costo_base) || 0;
      const rrpMin = prod.rrp_min !== null && prod.rrp_min !== undefined ? parseFloat(prod.rrp_min) : null;
      const rrpMax = prod.rrp_max !== null && prod.rrp_max !== undefined ? parseFloat(prod.rrp_max) : null;
      
      const badgeCostoHtml = costo > 0 ? `<span class="px-1 badge-costo rounded">Costo: ${window.qkFormatEuro(costo)}</span>` : '';
      const badgeMinHtml = rrpMin !== null ? `<span class="px-1 badge-rrp-min rounded fw-bold">Min: ${window.qkFormatEuro(rrpMin)}</span>` : '';
      const badgeMaxHtml = rrpMax !== null ? `<span class="px-1 badge-rrp-max rounded fw-bold">Max: ${window.qkFormatEuro(rrpMax)}</span>` : '';

      html += `
        <div class="col-12 col-sm-6 col-md-4 col-lg-3">
          <div class="qk-card-prod-catalogo p-2 border rounded shadow-xs h-100" style="cursor:pointer;" onclick="aggiungiProdottoDaCatalogo(${index})">
            <div class="fw-bold small text-truncate" title="${window.qkEscapeHtml(label)}">${window.qkEscapeHtml(label)}</div>
            <div class="text-muted font-monospace" style="font-size:0.75rem;">${window.qkEscapeHtml(sku)}</div>
            <div class="text-muted" style="font-size:0.75rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">Conf: ${window.qkEscapeHtml(confezione)}</div>
            <div class="d-flex gap-1 flex-wrap mt-1" style="font-size:0.72rem;">
              ${badgeCostoHtml}
              ${badgeMinHtml}
              ${badgeMaxHtml}
            </div>
          </div>
        </div>
      `;
    });
    elCatGrid.innerHTML = html;
  } catch (err) {
    console.error(err);
    elCatGrid.innerHTML = `<div class="col-12 text-center text-danger py-3 small">Errore durante la ricerca</div>`;
  }
}

window.aggiungiProdottoDaCatalogo = function (index) {
  const prod = catalogoProdottiData[index];
  if (!prod) return;
  
  // Trova una riga vuota o creane una nuova
  let targetTr = null;
  const rows = document.querySelectorAll('#tbody-righe tr');
  for (let tr of rows) {
    const prodId = tr.querySelector('.inp-prodotto-id').value;
    const searchVal = tr.querySelector('.riga-search').value;
    if (!prodId && !searchVal) {
      targetTr = tr;
      break;
    }
  }
  
  if (!targetTr) {
    targetTr = buildRiga();
  }
  
  // Popola la riga
  targetTr.querySelector('.inp-prodotto-id').value  = prod.id;
  targetTr.querySelector('.inp-desc').value         = prod.label;
  targetTr.querySelector('.inp-qta').value          = '1';
  targetTr.querySelector('.inp-pu').value           = '';
  
  syncRowMetadata(targetTr, {
    uomStock: prod.unita_misura || 'pz',
    uomStockId: prod.unita_misura_stock_id || '',
    uomCommercial: prod.unita_misura_commerciale || prod.unita_misura || 'pz',
    uomCommercialId: prod.unita_contenuto_id || prod.unita_misura_stock_id || '',
    quantitaContenuto: prod.quantita_contenuto || '1',
    sku: prod.sku || '',
    quantitaConfezione: prod.quantita_confezione || '',
    costoBase: prod.costo_base || '0',
    rrpMin: prod.rrp_min !== null && prod.rrp_min !== undefined ? prod.rrp_min : '',
    rrpMax: prod.rrp_max !== null && prod.rrp_max !== undefined ? prod.rrp_max : ''
  });

  setRowUomMode(targetTr, currentUomMode, 'stock');

  targetTr.querySelector('.riga-search').value = prod.label;
  calcTotali();
  checkRowCompliance(targetTr);
  fetchQuotePrice(targetTr);
  window.qkToast('Prodotto aggiunto al preventivo!', 'success');
};

if (elCatSearch) {
  elCatSearch.addEventListener('input', window.qkDebounce(() => {
    renderCatalogo(elCatSearch.value);
  }, 250));
}

/* ─────────────────────────────────────────────────────────────────────────
   MODALI HELPER
 ───────────────────────────────────────────────────────────────────────── */

let _pendingRigaTr = null; // riga che ha richiesto la creazione prodotto

function openModal(id, rigaTr = null) {
  if (id === 'modal-nuovo-prodotto') _pendingRigaTr = rigaTr;
  const el = document.getElementById(id);
  if (el) new bootstrap.Modal(el).show();
}

function showModalError(prefix, msg) {
  const el    = document.getElementById(prefix + '-error');
  const msgEl = document.getElementById(prefix + '-error-msg');
  if (el && msgEl) { msgEl.textContent = msg; el.style.display = ''; }
}
function hideModalError(prefix) {
  const el = document.getElementById(prefix + '-error');
  if (el) el.style.display = 'none';
}

// Tipo soggetto nel modale cliente
document.querySelectorAll('#form-nuovo-cliente input[name="tipo_soggetto"]').forEach(radio => {
  radio.addEventListener('change', () => {
    const isPriv = radio.value === 'privato';
    document.getElementById('mnc-rs-wrap').classList.toggle('d-none', isPriv);
    document.getElementById('mnc-nc-wrap').classList.toggle('d-none', !isPriv);
  });
});

/* ─────────────────────────────────────────────────────────────────────────
   SALVA NUOVO CLIENTE (AJAX)
 ───────────────────────────────────────────────────────────────────────── */

document.getElementById('btn-nuovo-cliente').addEventListener('click', () => openModal('modal-nuovo-cliente'));

document.getElementById('btn-salva-cliente').addEventListener('click', async () => {
  hideModalError('mnc');
  const form = document.getElementById('form-nuovo-cliente');
  const fd   = new FormData(form);
  fd.append('csrf_token', CSRF);

  const btn  = document.getElementById('btn-salva-cliente');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Salvataggio…';

  try {
    const res  = await fetch(BASE + '/sales/quotes/api/crea-cliente', { method: 'POST', body: fd, credentials: 'same-origin' });
    const data = await res.json();
    if (data.success) {
      selectCliente(data);
      form.reset();
      bootstrap.Modal.getInstance(document.getElementById('modal-nuovo-cliente')).hide();
    } else {
      showModalError('mnc', data.error || 'Errore sconosciuto');
    }
  } catch (e) {
    showModalError('mnc', 'Errore di rete: ' + e.message);
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Crea cliente';
  }
});

/* ─────────────────────────────────────────────────────────────────────────
   SALVA NUOVO PRODOTTO (AJAX)
 ───────────────────────────────────────────────────────────────────────── */

document.getElementById('btn-nuovo-prodotto').addEventListener('click', () => openModal('modal-nuovo-prodotto', null));

document.getElementById('btn-salva-prodotto').addEventListener('click', async () => {
  hideModalError('mnp');
  const form = document.getElementById('form-nuovo-prodotto');
  const fd   = new FormData(form);
  fd.append('csrf_token', CSRF);

  const btn  = document.getElementById('btn-salva-prodotto');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Salvataggio…';

  try {
    const res  = await fetch(BASE + '/sales/quotes/api/crea-prodotto', { method: 'POST', body: fd, credentials: 'same-origin' });
    const data = await res.json();
    if (data.success) {
      // Se c'è una riga in attesa, popola quella; altrimenti aggiungi una nuova riga
      if (_pendingRigaTr) {
        _pendingRigaTr.querySelector('.inp-prodotto-id').value  = data.id;
        _pendingRigaTr.querySelector('.inp-desc').value         = data.label;
        _pendingRigaTr.querySelector('.inp-um').value           = data.unita_misura || '';
        _pendingRigaTr.querySelector('.riga-search').value      = data.label;
        _pendingRigaTr.querySelector('[data-sku]').textContent  = data.sku ? 'SKU: ' + data.sku : '';
        _pendingRigaTr = null;
      } else {
        buildRiga({ descrizione: data.label, unita_misura: data.unita_misura, prodotto_id: data.id });
        // Aggiorna search dell'ultima riga
        const rows = document.querySelectorAll('#tbody-righe tr');
        const last = rows[rows.length - 1];
        if (last) last.querySelector('.riga-search').value = data.label;
      }
      form.reset();
      calcTotali();
      bootstrap.Modal.getInstance(document.getElementById('modal-nuovo-prodotto')).hide();
    } else {
      showModalError('mnp', data.error || 'Errore sconosciuto');
    }
  } catch (e) {
    showModalError('mnp', 'Errore di rete: ' + e.message);
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Crea prodotto';
  }
});

/* ─────────────────────────────────────────────────────────────────────────
   VALIDAZIONE CLIENT-SIDE PRIMA DEL SUBMIT
 ───────────────────────────────────────────────────────────────────────── */

function esc(s) {
  return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function checkRowCompliance(tr) {
  const clienteId = document.getElementById('cliente_id').value;
  const prodottoId = tr.querySelector('.inp-prodotto-id').value;
  const warnDiv = tr.querySelector('.riga-compliance-warn');
  
  if (!clienteId || !prodottoId || !warnDiv) {
    if (warnDiv) {
      warnDiv.style.display = 'none';
      warnDiv.innerHTML = '';
    }
    return;
  }
  
  const fd = new FormData();
  fd.set('cliente_id', clienteId);
  fd.set('prodotto_id', prodottoId);
  
  const csrfEl = document.querySelector('input[name="csrf_token"]');
  if (csrfEl) fd.set('csrf_token', csrfEl.value);

  fetch(BASE + '/sales/quotes/api/check-compliance', {
    method: 'POST',
    body: fd
  })
  .then(res => res.json())
  .then(data => {
    if (!data.ok && data.violations && data.violations.length > 0) {
      warnDiv.style.display = 'block';
      warnDiv.innerHTML = `<i class="fa-solid fa-triangle-exclamation text-warning me-1"></i> Non autorizzato: ${esc(data.violations[0].restriction_name)}`;
    } else {
      warnDiv.style.display = 'none';
      warnDiv.innerHTML = '';
    }
  })
  .catch(err => {
    console.error('Compliance check error:', err);
  });
}

function checkAllRowsCompliance() {
  document.querySelectorAll('#tbody-righe tr').forEach(tr => {
    checkRowCompliance(tr);
  });
}

// Initial check on load
setTimeout(checkAllRowsCompliance, 300);

document.getElementById('form-offerta').addEventListener('submit', function (e) {
  const clienteId = document.getElementById('cliente_id').value;
  if (!clienteId) {
    e.preventDefault();
    document.getElementById('cliente_search').focus();
    // Flash rapido sul campo
    document.getElementById('cliente_search').classList.add('is-invalid');
    setTimeout(() => document.getElementById('cliente_search').classList.remove('is-invalid'), 2500);
    alert('Seleziona un cliente prima di salvare.');
    return;
  }

  // Aggiorna stato dal pulsante cliccato
  const btn = e.submitter;
  if (btn && btn.name === 'stato' && btn.value) {
    document.querySelector('input[name="stato"]') || (() => {
      const h = document.createElement('input');
      h.type = 'hidden'; h.name = 'stato'; h.value = btn.value;
      this.appendChild(h);
    })();
  }
});

/* ─────────────────────────────────────────────────────────────────────────
   TABS & DYNAMIC CUSTOMER DETAILS LOADING (Odoo style)
───────────────────────────────────────────────────────────────────────── */

window.switchQuoteTab = function(tabId) {
  document.querySelectorAll('.qk-nav-tabs .nav-link').forEach(btn => {
    btn.classList.remove('active');
    btn.style.borderBottomColor = 'transparent';
    btn.style.color = 'var(--qk-text-muted)';
  });
  const activeBtn = document.getElementById('tab-' + tabId + '-btn');
  if (activeBtn) {
    activeBtn.classList.add('active');
    activeBtn.style.borderBottomColor = 'var(--qk-accent)';
    activeBtn.style.color = 'var(--qk-accent-text)';
  }

  document.querySelectorAll('.tab-content .tab-pane').forEach(pane => {
    pane.classList.add('d-none');
    pane.classList.remove('active');
  });
  const activePane = document.getElementById('pane-' + tabId);
  if (activePane) {
    activePane.classList.remove('d-none');
    activePane.classList.add('active');
  }
};

const spedSelect = document.getElementById('quote-indirizzo-spedizione-id');
const contSelect = document.getElementById('quote-contatto-id');
const contNomeInput = document.getElementById('quote-contatto-nome');
const contTelInput = document.getElementById('quote-contatto-telefono');
const contEmailInput = document.getElementById('quote-contatto-email');
const custTelHidden = document.getElementById('customer-generale-telefono');
const custEmailHidden = document.getElementById('customer-generale-email');
const condPagInput = document.getElementById('quote-condizioni-pagamento');

// Elementi Address Preview / Override
const elSpedFieldsBox     = document.getElementById('sped-fields-box');
const elBtnCancelEditSped = document.getElementById('btn-cancel-edit-sped');

const inpConsegnaVia       = document.getElementById('consegna_via');
const inpConsegnaCivico    = document.getElementById('consegna_civico');
const inpConsegnaCap       = document.getElementById('consegna_cap');
const inpConsegnaCitta     = document.getElementById('consegna_citta');
const inpConsegnaProvincia = document.getElementById('consegna_provincia');
const inpConsegnaNazione   = document.getElementById('consegna_nazione');
const inpConsegnaPresso    = document.getElementById('consegna_presso');
const inpConsegnaReferente = document.getElementById('consegna_referente');
const inpConsegnaTelefono  = document.getElementById('consegna_telefono');
const inpConsegnaNote      = document.getElementById('consegna_note');
const divSalvaAnagrafica   = document.getElementById('div-salva-in-anagrafica-addr');
const chkSalvaAnagrafica   = document.getElementById('salva_in_anagrafica_indirizzo');

let loadedContacts = [];
let loadedAddresses = [];

async function loadClienteDettagli() {
  if (!spedSelect || !contSelect) return;
  const clienteId = elClienteId.value;
  if (!clienteId) {
    spedSelect.innerHTML = '<option value="">Seleziona cliente prima...</option>';
    contSelect.innerHTML = '<option value="">Seleziona cliente prima...</option>';
    return;
  }

  spedSelect.innerHTML = '<option value="">Caricamento...</option>';
  contSelect.innerHTML = '<option value="">Caricamento...</option>';
  
  try {
    const res = await fetch(`${BASE}/sales/quotes/api/cliente-dettagli?cliente_id=${clienteId}`);
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'Errore recupero dettagli');

    loadedContacts = data.contatti || [];
    loadedAddresses = data.indirizzi || [];
    
    // Save general info for fallback
    if (custTelHidden) custTelHidden.value = data.cliente?.telefono || '';
    if (custEmailHidden) custEmailHidden.value = data.cliente?.email || data.cliente?.cellulare || '';

    // Update compact helper text under input
    if (elHelperText && elHelperContent) {
      const parts = [data.cliente?.email || data.cliente?.cellulare, data.cliente?.telefono].filter(Boolean);
      if (parts.length > 0) {
        elHelperContent.textContent = parts.join(' · ');
        elHelperText.style.display = '';
      } else {
        elHelperText.style.display = 'none';
      }
    }

    // Propose default payment terms
    if (condPagInput && !condPagInput.value && data.cliente?.condizioni_pagamento) {
      condPagInput.value = data.cliente.condizioni_pagamento;
    }

    // Propose default pricelist
    const listinoSelect = document.getElementById('quote-listino-id');
    if (listinoSelect && !listinoSelect.value && data.cliente?.listino_id) {
      listinoSelect.value = data.cliente.listino_id;
      refetchAllRowPrices();
    }

    // Populate Addresses
    spedSelect.innerHTML = '<option value="">— Nessun indirizzo specifico (Usa sede legale) —</option>';
    const selectedSpedId = spedSelect.dataset.selectedId;
    
    loadedAddresses.forEach(ind => {
      const option = document.createElement('option');
      option.value = ind.id;
      
      const labelParts = [];
      if (ind.nome_sede) labelParts.push(`[${ind.nome_sede}]`);
      if (ind.citta) labelParts.push(ind.citta);
      option.textContent = labelParts.join(' · ');

      if (selectedSpedId && String(ind.id) === String(selectedSpedId)) {
        option.selected = true;
      }
      spedSelect.appendChild(option);
    });

    // Option for custom address
    const customOption = document.createElement('option');
    customOption.value = 'custom';
    customOption.textContent = '+ Inserisci indirizzo personalizzato (una tantum)...';
    if (selectedSpedId === 'custom' || (!selectedSpedId && inpConsegnaVia && inpConsegnaVia.value)) {
      customOption.selected = true;
    }
    spedSelect.appendChild(customOption);

    // Initial sync of address UI on edit load
    syncAddressUI();

    // Populate Contacts
    contSelect.innerHTML = '<option value="">— Nessuno (Usa recapiti generali azienda) —</option>';
    const selectedContId = contSelect.dataset.selectedId;
    data.contatti.forEach(c => {
      const option = document.createElement('option');
      option.value = c.id;
      option.textContent = `${c.nome_completo} (${c.email || c.telefono || 'nessun recapito'})`;
      if (selectedContId && String(c.id) === String(selectedContId)) {
        option.selected = true;
      }
      contSelect.appendChild(option);
    });
    
    syncContactUI();

  } catch (err) {
    spedSelect.innerHTML = `<option value="">Errore: ${err.message}</option>`;
    contSelect.innerHTML = `<option value="">Errore: ${err.message}</option>`;
  }
}

function getOriginalAddress() {
  const val = spedSelect.value;
  if (!val) {
    // Sede legale fallback
    return loadedAddresses.find(i => i.tipo === 'sede_legale') || loadedAddresses[0] || {};
  }
  if (val === 'custom') {
    return {};
  }
  return loadedAddresses.find(i => String(i.id) === String(val)) || {};
}

function clearOverrideStylesSped() {
  [inpConsegnaVia, inpConsegnaCivico, inpConsegnaCap, inpConsegnaCitta, inpConsegnaProvincia,
   inpConsegnaNazione, inpConsegnaPresso, inpConsegnaReferente, inpConsegnaTelefono, inpConsegnaNote]
  .forEach(inp => {
    if (inp) inp.classList.remove('is-overridden');
  });
}

function syncAddressUI() {
  const val = spedSelect.value;
  if (elSpedFieldsBox) elSpedFieldsBox.style.display = 'block';

  if (val === 'custom') {
    if (divSalvaAnagrafica) divSalvaAnagrafica.style.display = 'flex';
    document.getElementById('sped-state-badge').textContent = 'Recapiti manuali / una tantum';
    document.getElementById('sped-state-badge').className = 'badge bg-info text-dark ms-2';
    if (elBtnCancelEditSped) elBtnCancelEditSped.style.display = 'none';
    clearOverrideStylesSped();
    return;
  }

  const ind = getOriginalAddress();
  const isNewForm = !inpConsegnaVia.value;
  if (isNewForm && ind.via && quoteStato === 'bozza') {
    copyAddressToFields(ind);
  }
  
  let hasOverride = false;
  const checkField = (inputEl, origValue) => {
    if (!inputEl) return;
    const cur = inputEl.value.trim();
    const ref = (origValue ?? '').trim();
    if (cur !== ref) {
      inputEl.classList.add('is-overridden');
      hasOverride = true;
    } else {
      inputEl.classList.remove('is-overridden');
    }
  };

  checkField(inpConsegnaVia, ind.via);
  checkField(inpConsegnaCivico, ind.civico);
  checkField(inpConsegnaCap, ind.cap);
  checkField(inpConsegnaCitta, ind.citta);
  checkField(inpConsegnaProvincia, ind.provincia);
  checkField(inpConsegnaNazione, ind.nazione || 'IT');
  checkField(inpConsegnaPresso, ind.presso);
  checkField(inpConsegnaReferente, ind.referente_consegna);
  checkField(inpConsegnaTelefono, ind.telefono_consegna);
  checkField(inpConsegnaNote, ind.note_consegna);

  const badge = document.getElementById('sped-state-badge');
  if (hasOverride) {
    badge.textContent = 'Modificato localmente (override)';
    badge.className = 'badge bg-warning text-dark ms-2';
    if (elBtnCancelEditSped) elBtnCancelEditSped.style.display = 'inline-block';
    if (divSalvaAnagrafica) divSalvaAnagrafica.style.display = 'flex';
  } else {
    badge.textContent = val ? '✓ Sincronizzato con anagrafica' : '— Usa sede legale —';
    badge.className = val ? 'badge bg-success text-white ms-2' : 'badge bg-secondary text-white ms-2';
    if (elBtnCancelEditSped) elBtnCancelEditSped.style.display = 'none';
    if (divSalvaAnagrafica) divSalvaAnagrafica.style.display = 'none';
  }
}

function copyAddressToFields(ind) {
  const elNomeSede = document.getElementById('consegna_nome_sede');
  if (elNomeSede) elNomeSede.value = ind.nome_sede || '';
  if (inpConsegnaVia) inpConsegnaVia.value = ind.via || '';
  if (inpConsegnaCivico) inpConsegnaCivico.value = ind.civico || '';
  if (inpConsegnaCap) inpConsegnaCap.value = ind.cap || '';
  if (inpConsegnaCitta) inpConsegnaCitta.value = ind.citta || '';
  if (inpConsegnaProvincia) inpConsegnaProvincia.value = ind.provincia || '';
  if (inpConsegnaNazione) inpConsegnaNazione.value = ind.nazione || 'IT';
  if (inpConsegnaPresso) inpConsegnaPresso.value = ind.presso || '';
  if (inpConsegnaReferente) inpConsegnaReferente.value = ind.referente_consegna || '';
  if (inpConsegnaTelefono) inpConsegnaTelefono.value = ind.telefono_consegna || '';
  if (inpConsegnaNote) inpConsegnaNote.value = ind.note_consegna || '';
}

function clearAddressFields() {
  const elNomeSede = document.getElementById('consegna_nome_sede');
  if (elNomeSede) elNomeSede.value = '';
  [inpConsegnaVia, inpConsegnaCivico, inpConsegnaCap, inpConsegnaCitta, inpConsegnaProvincia, inpConsegnaPresso, inpConsegnaReferente, inpConsegnaTelefono, inpConsegnaNote].forEach(inp => {
    if (inp) inp.value = '';
  });
  if (inpConsegnaNazione) inpConsegnaNazione.value = 'IT';
  if (chkSalvaAnagrafica) chkSalvaAnagrafica.checked = false;
}

// Bind input change listeners to keep preview in sync
[inpConsegnaVia, inpConsegnaCivico, inpConsegnaCap, inpConsegnaCitta, inpConsegnaProvincia, inpConsegnaNazione, inpConsegnaPresso, inpConsegnaReferente, inpConsegnaTelefono, inpConsegnaNote].forEach(inp => {
  if (inp) {
    inp.addEventListener('input', syncAddressUI);
  }
});

if (spedSelect) {
  spedSelect.addEventListener('change', () => {
    clearAddressFields();
    const val = spedSelect.value;
    if (val && val !== 'custom') {
      const ind = loadedAddresses.find(i => String(i.id) === String(val));
      if (ind) {
        copyAddressToFields(ind);
      }
    } else if (!val) {
      const ind = getOriginalAddress();
      if (ind && ind.via) {
        copyAddressToFields(ind);
      }
    }
    syncAddressUI();
  });
}

if (elBtnCancelEditSped) {
  elBtnCancelEditSped.addEventListener('click', () => {
    const val = spedSelect.value;
    if (val && val !== 'custom') {
      const ind = loadedAddresses.find(i => String(i.id) === String(val));
      if (ind) copyAddressToFields(ind);
    } else {
      const ind = getOriginalAddress();
      if (ind && ind.via) {
        copyAddressToFields(ind);
      } else {
        clearAddressFields();
      }
    }
    syncAddressUI();
  });
}

function getOriginalContact() {
  const val = contSelect.value;
  if (!val) {
    return {};
  }
  return loadedContacts.find(c => String(c.id) === String(val)) || {};
}

function syncContactUI() {
  const val = contSelect.value;
  const orig = getOriginalContact();
  
  let hasOverride = false;
  const checkField = (inputEl, origValue) => {
    if (!inputEl) return;
    const cur = inputEl.value.trim();
    const ref = (origValue ?? '').trim();
    if (cur !== ref) {
      inputEl.classList.add('is-overridden');
      hasOverride = true;
    } else {
      inputEl.classList.remove('is-overridden');
    }
  };
  
  checkField(contNomeInput, orig.nome_completo || '');
  checkField(contTelInput, orig.telefono || orig.cellulare || custTelHidden?.value || '');
  checkField(contEmailInput, orig.email || custEmailHidden?.value || '');
  
  const badge = document.getElementById('cont-state-badge');
  const resetBtn = document.getElementById('btn-cancel-edit-cont');
  
  if (!val) {
    badge.textContent = 'Recapiti manuali / una tantum';
    badge.className = 'badge bg-info text-dark ms-2';
    if (resetBtn) resetBtn.style.display = 'none';
    [contNomeInput, contTelInput, contEmailInput].forEach(inp => {
      if (inp) inp.classList.remove('is-overridden');
    });
  } else if (hasOverride) {
    badge.textContent = 'Modificato localmente (override)';
    badge.className = 'badge bg-warning text-dark ms-2';
    if (resetBtn) resetBtn.style.display = 'inline-block';
  } else {
    badge.textContent = '✓ Sincronizzato con anagrafica';
    badge.className = 'badge bg-success text-white ms-2';
    if (resetBtn) resetBtn.style.display = 'none';
  }
}

// Bind input change listeners for contact
[contNomeInput, contTelInput, contEmailInput].forEach(inp => {
  if (inp) {
    inp.addEventListener('input', syncContactUI);
  }
});

if (contSelect) {
  contSelect.addEventListener('change', () => {
    const selectedId = contSelect.value;
    if (!selectedId) {
      if (contTelInput) contTelInput.value = custTelHidden?.value || '';
      if (contEmailInput) contEmailInput.value = custEmailHidden?.value || '';
      if (contNomeInput) contNomeInput.value = '';
    } else {
      const contact = loadedContacts.find(c => String(c.id) === String(selectedId));
      if (contact) {
        if (contTelInput) contTelInput.value = contact.telefono || contact.cellulare || custTelHidden?.value || '';
        if (contEmailInput) contEmailInput.value = contact.email || custEmailHidden?.value || '';
        if (contNomeInput) contNomeInput.value = contact.nome_completo || '';
      }
    }
    syncContactUI();
  });
}

const btnCancelEditCont = document.getElementById('btn-cancel-edit-cont');
if (btnCancelEditCont) {
  btnCancelEditCont.addEventListener('click', () => {
    const contact = getOriginalContact();
    if (contact && contact.nome_completo) {
      if (contNomeInput) contNomeInput.value = contact.nome_completo || '';
      if (contTelInput) contTelInput.value = contact.telefono || contact.cellulare || custTelHidden?.value || '';
      if (contEmailInput) contEmailInput.value = contact.email || custEmailHidden?.value || '';
    } else {
      if (contNomeInput) contNomeInput.value = '';
      if (contTelInput) contTelInput.value = custTelHidden?.value || '';
      if (contEmailInput) contEmailInput.value = custEmailHidden?.value || '';
    }
    syncContactUI();
  });
}

// Implement Read-only Lock for status not 'bozza'
const quoteStato = <?= json_encode($v['stato'] ?? 'bozza') ?>;
if (quoteStato !== 'bozza') {
  [spedSelect, contSelect, inpConsegnaVia, inpConsegnaCivico, inpConsegnaCap, inpConsegnaCitta, 
   inpConsegnaProvincia, inpConsegnaNazione, inpConsegnaPresso, inpConsegnaReferente, 
   inpConsegnaTelefono, inpConsegnaNote, contNomeInput, contTelInput, contEmailInput, chkSalvaAnagrafica]
  .forEach(el => {
    if (el) {
      el.disabled = true;
      el.readOnly = true;
    }
  });
  [elBtnCancelEditSped, btnCancelEditCont].forEach(el => {
    if (el) el.style.display = 'none';
  });
}

async function fetchQuotePrice(tr) {
  const prodottoId = tr.querySelector('.inp-prodotto-id')?.value;
  const listinoId = document.getElementById('quote-listino-id')?.value;
  const qtyInput = tr.querySelector('.inp-qta');
  const priceInput = tr.querySelector('.inp-pu');

  const clienteId = document.getElementById('cliente_id')?.value || '';

  if (!prodottoId || !qtyInput || !priceInput) return;

  const qty = parseFloat(qtyInput.value) || 1.0;
  
  try {
    const res = await fetch(`${BASE}/sales/pricelists/api/ottieni-prezzo?prodotto_id=${prodottoId}&listino_id=${listinoId || ''}&cliente_id=${clienteId}&quantita=${qty}`);
    const data = await res.json();
    
    if (data.ok) {
      let computedPrice = parseFloat(data.prezzo_unitario) || 0;
      
      // Convert list price from its native UoM to current visual UoM mode
      const qFactor = parseFloat(tr.dataset.quantitaContenuto) || 1;
      const nativeUomId = data.unita_misura_id ? parseInt(data.unita_misura_id) : null;
      const isNativeCommercial = (nativeUomId && nativeUomId === parseInt(tr.dataset.uomCommercialId));
      
      if (isNativeCommercial && currentUomMode === 'stock' && qFactor > 0) {
        // Price was configured in Commercial (e.g. KG) but we view in Stock (e.g. pz) -> multiply price by content factor
        computedPrice = computedPrice * qFactor;
      } else if (!isNativeCommercial && currentUomMode === 'commercial' && qFactor > 0) {
        // Price was configured in Stock (e.g. pz) but we view in Commercial (e.g. KG) -> divide price by content factor
        computedPrice = computedPrice / qFactor;
      }
      
      priceInput.value = parseFloat(computedPrice.toFixed(4));
      
      const discountInput = tr.querySelector('.inp-sc');
      if (discountInput && data.sconto_perc !== undefined) {
        discountInput.value = parseFloat(data.sconto_perc) || 0;
      }

      if (data.costo_unitario !== undefined) {
        tr.dataset.costoBase = data.costo_unitario;
      }
      if (data.rrp_min !== undefined) {
        tr.dataset.rrpMin = data.rrp_min !== null ? data.rrp_min : '';
      }
      if (data.rrp_max !== undefined) {
        tr.dataset.rrpMax = data.rrp_max !== null ? data.rrp_max : '';
      }
      calcTotali();
    }

    // Update source badge
    const badge = tr.querySelector('.price-origin-badge');
    if (badge && data.origine) {
      let iconHtml = '';
      if (data.origine === 'dedicato') {
        iconHtml = '<i class="fa-solid fa-tag" style="color:var(--qk-accent)" title="${data.prezzo_sorgente}"></i>';
      } else if (data.origine === 'standard') {
        iconHtml = '<i class="fa-solid fa-globe" style="color:var(--qk-text-muted)" title="${data.prezzo_sorgente}"></i>';
      } else if (data.origine === 'costo') {
        iconHtml = '<i class="fa-solid fa-circle-exclamation" style="color:var(--qk-warning)" title="${data.prezzo_sorgente}"></i>';
      } else {
        iconHtml = '<i class="fa-solid fa-triangle-exclamation" style="color:var(--qk-danger)" title="${data.prezzo_sorgente}"></i>';
      }
      badge.innerHTML = iconHtml;
      badge.title = data.prezzo_sorgente || '';
    }

    calcTotali();
  } catch (e) {
    console.error('Error fetching listino price:', e);
  }
}

function refetchAllRowPrices() {
  document.querySelectorAll('#tbody-righe tr').forEach(tr => {
    fetchQuotePrice(tr);
  });
}

document.getElementById('quote-listino-id')?.addEventListener('change', () => {
  refetchAllRowPrices();
});

// Initial load if customer pre-selected
if (elClienteId && elClienteId.value) {
  loadClienteDettagli();
}

// Unconditionally refetch pricing origins for existing rows on page load
refetchAllRowPrices();

/* ── Mostra/Nascondi Colonne Odoo-Style ────────────────────────────── */
const btnColSettings = document.getElementById('btn-col-settings');
const colSettingsDropdown = document.getElementById('col-settings-dropdown');
const chkShowConf = document.getElementById('chk-show-conf');
const chkShowSconto = document.getElementById('chk-show-sconto');
const chkShowIva = document.getElementById('chk-show-iva');

if (btnColSettings && colSettingsDropdown) {
  btnColSettings.addEventListener('click', (e) => {
    e.stopPropagation();
    const isVisible = colSettingsDropdown.style.display === 'block';
    colSettingsDropdown.style.display = isVisible ? 'none' : 'block';
  });

  document.addEventListener('click', (e) => {
    if (!colSettingsDropdown.contains(e.target) && e.target !== btnColSettings) {
      colSettingsDropdown.style.display = 'none';
    }
  });
}

function updateColumnVisibility() {
  const showConf = chkShowConf ? chkShowConf.checked : true;
  const showSconto = chkShowSconto ? chkShowSconto.checked : false;
  const showIva = chkShowIva ? chkShowIva.checked : false;

  document.querySelectorAll('.col-conf').forEach(el => {
    el.style.display = showConf ? '' : 'none';
  });
  document.querySelectorAll('.col-sc').forEach(el => {
    el.style.display = showSconto ? '' : 'none';
  });
  document.querySelectorAll('.col-iva').forEach(el => {
    el.style.display = showIva ? '' : 'none';
  });
}

[chkShowConf, chkShowSconto, chkShowIva].forEach(chk => {
  if (chk) {
    chk.addEventListener('change', updateColumnVisibility);
  }
});

// Run initial visibility check
updateColumnVisibility();

});
</script>
