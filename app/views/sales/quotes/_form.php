<?php
/**
 * sales/quotes/_form.php — Form creazione/modifica offerta.
 * Variabili attese: $values (array), $errors (array), $numero_offerta (string), $linkedOrder (array|null)
 */
$v = $values;
$stato = $v['stato'] ?? 'bozza';
$can_edit = ($stato === 'bozza');
?>

<!-- ── Stili caricati via controller (sales-common.css) ────────────────── -->

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
<div class="qk-flex-row qk-justify-between mb-4 p-3 border rounded shadow-xs" style="background: var(--qk-bg-subtle); border-color: var(--qk-border) !important; flex-wrap: wrap; gap: .75rem;">
  <!-- Bottoni Azione / Workflow e Stampe (a sinistra) -->
  <div class="qk-flex-row qk-gap-sm flex-wrap">
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
        <?php elseif ($stato === 'accettata' && !empty($linkedOrder)): ?>
          <span class="qk-badge py-2 px-3 fw-bold qk-flex-row" style="font-size: 0.85rem; height: 31px; background: var(--qk-success-bg); color: var(--qk-success); border: 1px solid rgba(16, 185, 129, 0.2);">
            <i class="fa-solid fa-check-double me-1"></i> Ordine Generato: 
            <a href="<?= url('sales/orders/edit?id=' . $linkedOrder['id']) ?>" class="fw-bold text-decoration-underline ms-1" style="color: var(--qk-success);"><?= e($linkedOrder['numero_ordine']) ?></a>
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
      <span class="qk-dirty-warning" id="dirty-warn-badge"><i class="fa-solid fa-triangle-exclamation"></i> Modifiche non salvate (salva per aggiornare i PDF)</span>
    <?php endif; ?>
  </div>

  <!-- Bottoni Esci / Pericolosi (a destra) -->
  <div class="qk-flex-row qk-gap-sm flex-wrap ms-auto">
    <?php if (isset($id) && $id > 0 && $stato === 'inviata'): ?>
      <form method="post" action="<?= url('sales/quotes/action') ?>" class="d-inline" onsubmit="return confirm('Segnare il preventivo come rifiutato?')">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="azione" value="rifiuta">
        <button type="submit" class="btn-qk btn-qk-secondary btn-qk-sm text-danger" title="Segna come rifiutato">
          <i class="fa-solid fa-ban"></i> Rifiuta
        </button>
      </form>
    <?php endif; ?>
    <a href="<?= url('sales/quotes') ?>" class="btn-qk btn-qk-secondary btn-qk-sm">
      <i class="fa-solid fa-arrow-left"></i> Torna alla lista
    </a>
  </div>
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
  <?= csrf_field() ?>

  <!-- Cliente nascosto -->
  <input type="hidden" name="cliente_id"    id="cliente_id"    value="<?= e($v['cliente_id']) ?>">
  <input type="hidden" name="cliente_label" id="cliente_label" value="<?= e($v['cliente_label']) ?>">

  <fieldset style="border:none; padding:0; margin:0;"<?= !$can_edit ? ' disabled' : '' ?>>

  <!-- Top Primary Fields Grid -->
  <div class="qk-card mb-3">
    <div class="qk-card-header qk-flex-row qk-justify-between">
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
              <div class="qk-flex-row qk-gap-sm">
                <div class="qk-m2o-wrap flex-grow-1" style="position:relative;">
                  <input type="text" id="cliente_search" class="qk-form-control qk-m2o-input <?= isset($errors['cliente_id']) ? 'is-invalid' : '' ?>"
                         placeholder="Cerca cliente per nome, ragione sociale, email…"
                         value="<?= e($v['cliente_label']) ?>" autocomplete="off">
                  <button type="button" class="qk-m2o-clear <?= $v['cliente_id'] ? '' : 'd-none' ?>" id="btn-clear-cliente-inline" title="Rimuovi cliente">&times;</button>
                  <div class="qk-m2o-dropdown shadow-sm" id="ac-clienti-drop"></div>
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
              <input type="hidden" name="listino_id" id="quote-listino-id" value="<?= e($v['listino_id'] ?? '') ?>">
              <div class="qk-m2o-wrap" style="position:relative;">
                <?php
                $listinoLabel = '';
                foreach ($listini as $lst) {
                    if ((string)($v['listino_id'] ?? '') === (string)$lst['id']) {
                        $listinoLabel = $lst['nome'] . ' (' . $lst['valuta'] . ')';
                        break;
                    }
                }
                ?>
                <input type="text"
                       class="qk-form-control qk-m2o-input"
                       id="quote-listino-label"
                       placeholder="Cerca listino…"
                       value="<?= e($listinoLabel) ?>"
                       autocomplete="off">
                <button type="button" class="qk-m2o-clear <?= empty($v['listino_id']) ? 'd-none' : '' ?>" id="quote-listino-clear">&times;</button>
                <div class="qk-m2o-dropdown shadow-sm" id="quote-listino-dropdown" style="display:none; position:absolute; z-index:1050; background:var(--qk-card-bg); border:1px solid var(--qk-border); width:100%; max-height:200px; overflow-y:auto;"></div>
              </div>
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
  </fieldset>

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
          
          <?php if ($can_edit): ?>
          <!-- Catalogo Prodotti Widget -->
          <div class="qk-card mb-3 p-3 border-0 shadow-xs" style="border-radius: var(--qk-border-radius-lg, 8px);">
            <div class="qk-flex-row qk-justify-between mb-2">
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
          <?php endif; ?>

          <div class="qk-flex-row qk-justify-between mb-2">
            <h4 style="font-size:1rem; margin:0;"><i class="fa-solid fa-list me-2"></i>Righe preventivo</h4>
            <div class="qk-flex-row qk-gap-sm ">
              <div class="qk-flex-row qk-gap-xs me-2" style="font-size: .85rem;">
                <label class="qk-form-label mb-0 text-muted" style="white-space:nowrap; font-weight:600;">Visualizza in:</label>
                <select id="sales-uom-mode" name="uom_mode" class="qk-form-control" style="width:auto; padding:2px 8px; font-size:.85rem; height:auto;">
                  <option value="commercial" <?= ($v['uom_mode'] ?? 'commercial') === 'commercial' ? 'selected' : '' ?>>Unità commerciale</option>
                  <option value="stock" <?= ($v['uom_mode'] ?? 'commercial') === 'stock' ? 'selected' : '' ?>>Unità di stock</option>
                </select>
              </div>
              <?php if ($can_edit): ?>
              <button type="button" id="btn-nuovo-prodotto" class="btn-qk btn-qk-secondary btn-qk-sm">
                <i class="fa-solid fa-box-open"></i> Nuovo prodotto
              </button>
              <?php endif; ?>
              
              <div class="qk-dropdown-container" style="position:relative; display:inline-block; line-height:1;">
                <button type="button" class="btn-qk btn-qk-secondary btn-qk-sm" id="btn-col-settings" title="Mostra/Nascondi colonne" style="display: inline-flex; align-items: center; gap: 0.25rem;">
                  <i class="fa-solid fa-sliders"></i> Colonne
                </button>
                <div class="qk-dropdown-menu shadow-sm" id="col-settings-dropdown" style="display:none; position:absolute; right:0; top:120%; z-index:1000; background:var(--qk-card-bg); border:1px solid var(--qk-border); border-radius:var(--qk-radius); padding:.6rem .8rem; min-width:180px; text-align:left; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                  <div class="qk-flex-col qk-gap-sm">
                    <label class="qk-flex-row qk-gap-sm" style="font-size:.85rem;font-weight:500;cursor:pointer;color:var(--qk-text-primary);margin:0">
                      <input type="checkbox" id="chk-show-conf" style="transform:scale(1.15);" checked>
                      <span>Confezione</span>
                    </label>
                    <?php if ($can_edit): ?>
                    <label class="qk-flex-row qk-gap-sm" style="font-size:.85rem;font-weight:500;cursor:pointer;color:var(--qk-text-primary);margin:0">
                      <input type="checkbox" id="chk-show-range" style="transform:scale(1.15);" checked>
                      <span>Range RRP</span>
                    </label>
                    <?php endif; ?>
                    <label class="qk-flex-row qk-gap-sm" style="font-size:.85rem;font-weight:500;cursor:pointer;color:var(--qk-text-primary);margin:0">
                      <input type="checkbox" id="chk-show-sconto" style="transform:scale(1.15);">
                      <span>Sconto</span>
                    </label>
                    <label class="qk-flex-row qk-gap-sm" style="font-size:.85rem;font-weight:500;cursor:pointer;color:var(--qk-text-primary);margin:0">
                      <input type="checkbox" id="chk-show-iva" style="transform:scale(1.15);">
                      <span>IVA</span>
                    </label>
                  </div>
                </div>
              </div>

              <?php if ($can_edit): ?>
              <button type="button" id="btn-aggiungi-riga" class="btn-qk btn-qk-primary btn-qk-sm">
                <i class="fa-solid fa-plus"></i> Aggiungi riga
              </button>
              <?php endif; ?>
            </div>
          </div>
          
          <fieldset style="border:none; padding:0; margin:0;"<?= !$can_edit ? ' disabled' : '' ?>>
          <div class="qk-table-wrap">
            <table class="qk-table <?= !$can_edit ? 'is-readonly' : '' ?>" id="tbl-righe">
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
            <div class="qk-flex-row qk-justify-between ">
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

            <?php if ($can_edit): ?>
            <!-- Margins Summary Row (Mockup Style) -->
            <div class="row g-3 mt-3 pt-3 border-top">
              <div class="col-6 col-md-3">
                <div class="qk-card p-3 text-center shadow-xs">
                  <div class="text-muted small fw-bold text-uppercase" style="font-size:0.75rem;">Totale Cliente</div>
                  <div class="fs-4 fw-bold text-dark mt-1" id="box-tot-cliente">€ 0,00</div>
                </div>
              </div>
              <div class="col-6 col-md-3">
                <div class="qk-card p-3 text-center shadow-xs">
                  <div class="text-muted small fw-bold text-uppercase" style="font-size:0.75rem;">Totale Costo</div>
                  <div class="fs-4 fw-bold text-dark mt-1" id="box-tot-costo">€ 0,00</div>
                </div>
              </div>
              <div class="col-6 col-md-3">
                <div class="p-3 rounded border shadow-xs text-center" id="box-margine-euro-container" style="background-color: var(--qk-success-bg); border-color: var(--qk-success);">
                  <div class="text-muted small fw-bold text-uppercase" style="font-size:0.75rem;">Margine €</div>
                  <div class="fs-4 fw-bold text-success mt-1" id="box-margine-euro">€ 0,00</div>
                </div>
              </div>
              <div class="col-6 col-md-3">
                <div class="p-3 rounded border shadow-xs text-center" id="box-margine-perc-container" style="background-color: var(--qk-success-bg); border-color: var(--qk-success);">
                  <div class="text-muted small fw-bold text-uppercase" style="font-size:0.75rem;">Margine %</div>
                  <div class="fs-4 fw-bold text-success mt-1" id="box-margine-perc">0,00%</div>
                </div>
              </div>
            </div>
            <?php endif; ?>
          </div>
          </fieldset>
        </div>

        <!-- Tab Spedizione & Contatti -->
        <div class="tab-pane d-none" id="pane-spedizione-contatti">
          <fieldset style="border:none; padding:0; margin:0; width:100%;"<?= !$can_edit ? ' disabled' : '' ?>>
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
                <div class="qk-flex-row qk-justify-between mb-3">
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
                <div class="qk-flex-row qk-justify-between mb-3">
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
          </fieldset>
        </div>

        <!-- Tab Condizioni Commerciali -->
        <div class="tab-pane d-none" id="pane-condizioni-commerciali">
          <fieldset style="border:none; padding:0; margin:0; width:100%;"<?= !$can_edit ? ' disabled' : '' ?>>
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
          </fieldset>
        </div>

        <!-- Tab Note -->
        <div class="tab-pane d-none" id="pane-note">
          <fieldset style="border:none; padding:0; margin:0; width:100%;"<?= !$can_edit ? ' disabled' : '' ?>>
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
          </fieldset>
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
            <div class="qk-flex-row qk-gap-sm">
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
        <button type="button" class="btn-qk btn-qk-primary" id="btn-salva-prodotto"><i class="fa-solid fa-floppy-disk"></i> Crea prodotto</button>
      </div>
    </div>
  </div>
</div>
<script>
window.QK_QUOTES_CONFIG = {
  csrf: <?= json_encode(csrf_token()) ?>,
  preloadedRighe: <?= json_encode($preloadedRighe ?? []) ?>,
  quoteStato: <?= json_encode($values['stato'] ?? 'bozza') ?>,
  listini: <?= json_encode(array_map(fn($l) => ['id' => $l['id'], 'label' => $l['nome'] . ' (' . $l['valuta'] . ')'], $listini)) ?>
};
</script>
<script src="<?= asset('js/sales-quotes.js') ?>"></script>
