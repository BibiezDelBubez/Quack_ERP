<?php
/**
 * sales/quotes/_form.php — Form creazione/modifica offerta.
 * Variabili attese: $values (array), $errors (array), $numero_offerta (string)
 */
$v = $values;
?>

<!-- ── Stili specifici offerta ──────────────────────────────────────────── -->
<style>
/* Autocomplete dropdown */
.qk-ac-wrap        { position: relative; }
.qk-ac-dropdown    { position: absolute; top: calc(100% + 4px); left: 0; right: 0; z-index: 500;
                     background: var(--qk-card-bg); border: 1px solid var(--qk-border);
                     border-radius: var(--qk-radius); box-shadow: 0 8px 24px rgba(0,0,0,.18);
                     max-height: 260px; overflow-y: auto; display: none; }
.qk-ac-dropdown.open { display: block; }
.qk-ac-item        { padding: .5rem .85rem; cursor: pointer; display: flex; flex-direction: column;
                     border-bottom: 1px solid var(--qk-border); transition: background .12s; }
.qk-ac-item:last-child { border-bottom: none; }
.qk-ac-item:hover,
.qk-ac-item.focused { background: var(--qk-accent-muted, rgba(var(--qk-accent-rgb,.1))); }
.qk-ac-item-label  { font-weight: 600; font-size: .88rem; }
.qk-ac-item-sub    { font-size: .78rem; color: var(--qk-text-muted); }
.qk-ac-empty       { padding: .75rem 1rem; font-size: .85rem; color: var(--qk-text-muted); text-align: center; }
.qk-ac-create      { padding: .55rem .85rem; cursor: pointer; display: flex; align-items: center; gap: .4rem;
                     font-size: .85rem; font-weight: 600; color: var(--qk-accent-text);
                     border-top: 1px solid var(--qk-border); }
.qk-ac-create:hover { background: var(--qk-accent-muted); }

/* Cliente selezionato */
.qk-cliente-preview { background: var(--qk-accent-muted, rgba(0,100,60,.06));
                      border: 1px solid var(--qk-border); border-radius: var(--qk-radius-sm);
                      padding: .65rem 1rem; margin-top: .5rem; display: flex;
                      align-items: flex-start; gap: .75rem; }
.qk-cliente-preview .name { font-weight: 700; }
.qk-cliente-preview .info { font-size: .82rem; color: var(--qk-text-muted); }
.qk-cliente-preview .btn-clear { margin-left: auto; background: none; border: none;
                                  cursor: pointer; color: var(--qk-text-muted); padding: .1rem .3rem; }

/* Tabella righe offerta */
#tbl-righe       { width: 100%; border-collapse: collapse; font-size: .875rem; }
#tbl-righe thead th { padding: .75rem .5rem; font-weight: 600; font-size: .78rem;
                      text-transform: uppercase; letter-spacing: .05em;
                      color: var(--qk-text-muted); border-bottom: 2px solid var(--qk-border);
                      white-space: nowrap; }
#tbl-righe tbody td { padding: .65rem .5rem; vertical-align: middle; }
#tbl-righe tbody tr { border-bottom: 1px solid var(--qk-border); transition: background-color 0.12s ease; }
#tbl-righe tbody tr:nth-child(even) { background-color: #f8fafc; }
#tbl-righe tbody tr:hover { background-color: #f1f5f9 !important; }
#tbl-righe tbody tr:last-child { border-bottom: none; }
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

.riga-input { width: 100%; background: transparent; border: 1px solid transparent;
              border-radius: var(--qk-radius-sm); padding: .25rem .4rem;
              color: var(--qk-text-primary); font-size: .875rem;
              outline: none; transition: all 0.12s ease; }
.riga-input:focus { border-color: var(--qk-accent); background: var(--qk-input-bg, var(--qk-card-bg));
                    box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
.riga-input[readonly] { cursor: not-allowed; color: var(--qk-text-muted); opacity: 0.85; }
.riga-input.text-end { text-align: right; font-family: monospace; }
.riga-total { font-weight: 600; text-align: right; padding: .25rem .4rem;
              color: var(--qk-text-primary); font-family: monospace; }
.riga-del   { background: none; border: none; cursor: pointer; color: var(--qk-text-muted);
              font-size: .9rem; padding: .3rem; border-radius: var(--qk-radius-sm);
              transition: color .12s, background .12s; }
.riga-del:hover { color: #ef4444; background: rgba(239,68,68,.1); }

/* SKU badge in riga */
.riga-sku { font-size: .72rem; color: var(--qk-text-muted); font-family: monospace; }

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
</style>

<?php if (!empty($errors['_global'])): ?>
<div class="qk-alert qk-alert-danger" data-auto-dismiss="7000">
  <i class="fa-solid fa-circle-xmark"></i>
  <span><?= e($errors['_global']) ?></span>
  <button class="qk-alert-close"><i class="fa-solid fa-xmark"></i></button>
</div>
<?php endif; ?>

<!-- Barra Azioni e Stato (Odoo style) -->
<div class="d-flex justify-content-between align-items-center mb-4 p-3 border rounded shadow-xs" style="background: var(--qk-bg-subtle); border-color: var(--qk-border) !important; flex-wrap: wrap; gap: .75rem;">
  <!-- Bottoni Azione / Navigazione -->
  <div style="display:flex; gap:.5rem; align-items:center; flex-wrap:wrap;">
    <a href="<?= url('sales/quotes') ?>" class="btn-qk btn-qk-secondary btn-qk-sm">
      <i class="fa-solid fa-arrow-left"></i> Torna alla lista
    </a>
  </div>

  <!-- Pipeline visivo stati -->
  <ul class="qk-status-path d-none d-sm-flex">
    <?php
    $stato = $v['stato'] ?? 'bozza';
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
                <div class="qk-ac-wrap flex-grow-1">
                  <input type="text" id="cliente_search" class="qk-form-control <?= isset($errors['cliente_id']) ? 'is-invalid' : '' ?>"
                         placeholder="Cerca cliente per nome, ragione sociale, email…"
                         value="<?= e($v['cliente_label']) ?>" autocomplete="off">
                  <div class="qk-ac-dropdown" id="ac-clienti-drop"></div>
                </div>
                <button type="button" class="btn-qk btn-qk-secondary" id="btn-nuovo-cliente"
                        title="Crea nuovo cliente" style="white-space:nowrap">
                  <i class="fa-solid fa-plus"></i> Nuovo
                </button>
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
          
          <!-- Cliente Preview -->
          <div id="cliente-preview" class="qk-cliente-preview mb-3" style="<?= $v['cliente_id'] ? '' : 'display:none' ?>">
            <i class="fa-solid fa-building fa-lg" style="color:var(--qk-accent);margin-top:.1rem"></i>
            <div>
              <div class="name" id="cp-nome"><?= e($v['cliente_label']) ?></div>
              <div class="info" id="cp-info"></div>
            </div>
            <button type="button" class="btn-clear" id="btn-clear-cliente" title="Rimuovi cliente">
              <i class="fa-solid fa-xmark"></i>
            </button>
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
      <ul class="nav qk-nav-tabs" role="tablist" style="border-bottom: 1px solid var(--qk-border); padding-bottom: 0.5rem; display: flex; gap: 1rem; list-style: none; margin: 0 0 1rem 0;">
        <button class="nav-link active" id="tab-prodotti-btn" type="button" onclick="switchQuoteTab('prodotti')" style="background: none; border: none; border-bottom: 2px solid var(--qk-accent); color: var(--qk-accent-text); padding: 0.5rem 1rem; font-weight: 600; cursor: pointer;">
          <i class="fa-solid fa-list me-1"></i> Prodotti
        </button>
        <button class="nav-link" id="tab-altre-info-btn" type="button" onclick="switchQuoteTab('altre-info')" style="background: none; border: none; border-bottom: 2px solid transparent; color: var(--qk-text-muted); padding: 0.5rem 1rem; font-weight: 600; cursor: pointer;">
          <i class="fa-solid fa-circle-info me-1"></i> Altre Info
        </button>
        <button class="nav-link" id="tab-note-btn" type="button" onclick="switchQuoteTab('note')" style="background: none; border: none; border-bottom: 2px solid transparent; color: var(--qk-text-muted); padding: 0.5rem 1rem; font-weight: 600; cursor: pointer;">
          <i class="fa-solid fa-pen me-1"></i> Note
        </button>
      </ul>

      <!-- Tab Content Panes -->
      <div class="tab-content">
        
        <!-- Tab Prodotti -->
        <div class="tab-pane active" id="pane-prodotti">
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
              <button type="button" id="btn-aggiungi-riga" class="btn-qk btn-qk-primary btn-qk-sm">
                <i class="fa-solid fa-plus"></i> Aggiungi riga
              </button>
            </div>
          </div>
          
          <div class="qk-table-wrap" style="overflow-x:auto">
            <table id="tbl-righe">
              <thead>
                <tr>
                  <th class="col-search">Cerca prodotto</th>
                  <th class="col-desc">Descrizione</th>
                  <th class="col-conf">Confezione <i class="fa-solid fa-circle-info" data-bs-toggle="tooltip" title="Es. secchio 5 kg" style="cursor:help; font-size:0.8rem; opacity:0.8;"></i></th>
                  <th class="col-qta">Qtà <span class="required">*</span></th>
                  <th class="col-um">U.M.</th>
                  <th class="col-pu">Prezzo unit.</th>
                  <th class="col-sc">Sc. %</th>
                  <th class="col-iva">IVA %</th>
                  <th class="col-tot">Totale</th>
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
            <div class="d-flex justify-content-between align-items-start gap-3">
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
          </div>
        </div>

        <!-- Tab Altre Info -->
        <div class="tab-pane d-none" id="pane-altre-info">
          <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:1.5rem;">
            
            <!-- Spedizione & Consegna -->
            <div>
              <h4 style="font-size:.9rem; text-transform:uppercase; letter-spacing:.05em; color:var(--qk-text-muted); margin-bottom:1rem; border-bottom:1px solid var(--qk-border); padding-bottom:.5rem;">Spedizione & Consegna</h4>
              <div class="qk-form-group">
                <label class="qk-form-label">Indirizzo di consegna</label>
                <select name="indirizzo_spedizione_id" id="quote-indirizzo-spedizione-id" class="qk-form-control" data-selected-id="<?= e($v['indirizzo_spedizione_id'] ?? '') ?>">
                  <option value="">Seleziona cliente prima...</option>
                </select>
              </div>
              <div class="qk-form-group">
                <label class="qk-form-label">Data consegna richiesta</label>
                <input type="date" name="data_consegna_richiesta" class="qk-form-control"
                       value="<?= e($v['data_consegna_richiesta']) ?>">
              </div>
              <div class="qk-form-group">
                <label class="qk-form-label">Trasporto (Resa)</label>
                <input type="text" name="trasporto" class="qk-form-control"
                       value="<?= e($v['trasporto']) ?>" placeholder="Es. Franco destino">
              </div>
            </div>

            <!-- Referente & Recapiti -->
            <div>
              <h4 style="font-size:.9rem; text-transform:uppercase; letter-spacing:.05em; color:var(--qk-text-muted); margin-bottom:1rem; border-bottom:1px solid var(--qk-border); padding-bottom:.5rem;">Referente & Recapiti</h4>
              <div class="qk-form-group">
                <label class="qk-form-label">Alla cortese attenzione (Referente)</label>
                <select name="contatto_id" id="quote-contatto-id" class="qk-form-control" data-selected-id="<?= e($v['contatto_id'] ?? '') ?>">
                  <option value="">Seleziona cliente prima...</option>
                </select>
                <input type="hidden" name="contatto_nome" id="quote-contatto-nome" value="<?= e($v['contatto_nome'] ?? '') ?>">
                <!-- Fallback info -->
                <input type="hidden" id="customer-generale-telefono" value="">
                <input type="hidden" id="customer-generale-email" value="">
              </div>
              <div class="qk-form-group">
                <label class="qk-form-label">Telefono di contatto</label>
                <input type="text" name="contatto_telefono" id="quote-contatto-telefono" class="qk-form-control"
                       placeholder="Es. +39 02 123456"
                       value="<?= e($v['contatto_telefono']) ?>">
              </div>
              <div class="qk-form-group">
                <label class="qk-form-label">Email di contatto</label>
                <input type="email" name="contatto_email" id="quote-contatto-email" class="qk-form-control"
                       placeholder="Es. mario.rossi@cliente.com"
                       value="<?= e($v['contatto_email']) ?>">
              </div>
            </div>

            <!-- Condizioni & Fatturazione -->
            <div>
              <h4 style="font-size:.9rem; text-transform:uppercase; letter-spacing:.05em; color:var(--qk-text-muted); margin-bottom:1rem; border-bottom:1px solid var(--qk-border); padding-bottom:.5rem;">Fatturazione & Canali</h4>
              <div class="qk-form-group">
                <label class="qk-form-label">Condizioni di pagamento</label>
                <input type="text" name="condizioni_pagamento" id="quote-condizioni-pagamento" class="qk-form-control"
                       value="<?= e($v['condizioni_pagamento']) ?>" placeholder="Es. 30 gg d.f.f.m.">
              </div>
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
    <button type="submit" name="stato" value="bozza" class="btn-qk btn-qk-secondary">
      <i class="fa-solid fa-floppy-disk"></i> Salva bozza
    </button>
    <button type="submit" name="stato" value="inviata" class="btn-qk btn-qk-primary">
      <i class="fa-solid fa-paper-plane"></i> Salva e invia
    </button>
    <a href="<?= url('sales/quotes') ?>" class="btn-qk btn-qk-secondary" style="margin-left:auto">
      <i class="fa-solid fa-xmark"></i> Annulla
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

const elClienteSearch  = document.getElementById('cliente_search');
const elClienteId      = document.getElementById('cliente_id');
const elClienteLabel   = document.getElementById('cliente_label');
const elClienteDrop    = document.getElementById('ac-clienti-drop');
const elClientePreview = document.getElementById('cliente-preview');
const elCpNome         = document.getElementById('cp-nome');
const elCpInfo         = document.getElementById('cp-info');

function selectCliente(item) {
  elClienteId.value    = item.id;
  elClienteLabel.value = item.label;
  elClienteSearch.value = item.label;
  elCpNome.textContent = item.label;
  const parts = [item.email, item.telefono].filter(Boolean);
  elCpInfo.textContent = parts.join(' · ');
  elClientePreview.style.display = '';
  checkAllRowsCompliance();
  loadClienteDettagli();
}

function clearCliente() {
  elClienteId.value    = '';
  elClienteLabel.value = '';
  elClienteSearch.value = '';
  elClientePreview.style.display = 'none';
  elCpNome.textContent = '';
  elCpInfo.textContent = '';
  checkAllRowsCompliance();
  
  const spedSelect = document.getElementById('quote-indirizzo-spedizione-id');
  const contSelect = document.getElementById('quote-contatto-id');
  if (spedSelect) spedSelect.innerHTML = '<option value="">Seleziona cliente prima...</option>';
  if (contSelect) contSelect.innerHTML = '<option value="">Seleziona cliente prima...</option>';
}

document.getElementById('btn-clear-cliente').addEventListener('click', clearCliente);

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
}

function setRowUomMode(tr, targetMode, currentMode) {
  const qFactor = parseFloat(tr.dataset.quantitaContenuto) || 1;
  const qtyInput = tr.querySelector('.inp-qta');
  const priceInput = tr.querySelector('.inp-pu');
  const umInput = tr.querySelector('.inp-um');

  if (!qtyInput || !priceInput || !umInput) return;

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

  tr.innerHTML = `
    <td class="col-search">
      <div class="qk-ac-wrap">
        <input type="text" class="riga-input riga-search" placeholder="Cerca…" autocomplete="off" value="${esc(prefill.descrizione || '')}">
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
      <input type="hidden" name="righe[${idx}][quantita_confezione]" class="inp-quantita-confezione" value="${esc(prefill.quantita_confezione || '')}">
    </td>
    <td class="col-desc">
      <input type="text" name="righe[${idx}][descrizione]" class="riga-input inp-desc"
             placeholder="Descrizione prodotto" value="${esc(prefill.descrizione || '')}">
    </td>
    <td class="col-conf">
      <input type="text" class="riga-input riga-conf" readonly
             value="${esc(prefill.quantita_confezione || '')}" placeholder="—" title="Confezione (da catalogo)" style="color:var(--qk-text-muted)">
    </td>
    <td class="col-qta">
      <input type="number" name="righe[${idx}][quantita]" class="riga-input text-end inp-qta"
             placeholder="0" min="0" step="0.001" value="${prefill.quantita || ''}">
    </td>
    <td class="col-um">
      <input type="text" name="righe[${idx}][unita_misura]" class="riga-input inp-um"
             placeholder="pz" maxlength="5" value="${esc(prefill.unita_misura || '')}">
    </td>
    <td class="col-pu">
      <div style="position:relative; display:flex; align-items:center; width:100%;">
        <input type="number" name="righe[${idx}][prezzo_unitario]" class="riga-input text-end inp-pu"
               placeholder="0,00" min="0" step="0.0001" value="${prefill.prezzo_unitario || ''}" style="padding-right:20px; width:100%;">
        <span class="price-origin-badge" style="position:absolute; right:4px; font-size:0.75rem; cursor:pointer;" title="Nessun prezzo configurato">
          <i class="fa-solid fa-triangle-exclamation" style="color:var(--qk-danger)"></i>
        </span>
      </div>
    </td>
    <td class="col-sc">
      <input type="number" name="righe[${idx}][sconto_perc]" class="riga-input text-end inp-sc"
             placeholder="0" min="0" max="100" step="0.01" value="${prefill.sconto_perc || '0'}">
    </td>
    <td class="col-iva">
      <input type="number" name="righe[${idx}][iva_perc]" class="riga-input text-end inp-iva"
             placeholder="22" min="0" max="100" step="1" value="${prefill.iva_perc || '22'}">
    </td>
    <td class="col-tot">
      <div class="riga-total">€ 0,00</div>
    </td>
    <td class="col-del">
      <button type="button" class="riga-del" title="Rimuovi riga"><i class="fa-solid fa-trash-can"></i></button>
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
        quantitaConfezione: item.quantita_confezione || ''
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
  return tr;
}

function calcTotali() {
  let imponibile = 0;
  let totIva     = 0;

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
  });

  const scGlob = parseFloat(document.getElementById('sconto_globale')?.value) || 0;
  const impSc  = Math.round(imponibile * (1 - scGlob / 100) * 100) / 100;
  const ivaSc  = Math.round(totIva    * (1 - scGlob / 100) * 100) / 100;
  const totale = Math.round((impSc + ivaSc) * 100) / 100;

  document.getElementById('tot-imponibile').textContent = window.qkFormatEuro(impSc);
  document.getElementById('tot-iva').textContent        = window.qkFormatEuro(ivaSc);
  document.getElementById('tot-totale').textContent     = window.qkFormatEuro(totale);
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
const contNomeHidden = document.getElementById('quote-contatto-nome');
const contTelInput = document.getElementById('quote-contatto-telefono');
const contEmailInput = document.getElementById('quote-contatto-email');
const custTelHidden = document.getElementById('customer-generale-telefono');
const custEmailHidden = document.getElementById('customer-generale-email');
const condPagInput = document.getElementById('quote-condizioni-pagamento');

let loadedContacts = [];

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
    
    // Save general info for fallback
    if (custTelHidden) custTelHidden.value = data.cliente?.telefono || '';
    if (custEmailHidden) custEmailHidden.value = data.cliente?.email || data.cliente?.cellulare || '';

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
    data.indirizzi.forEach(ind => {
      const option = document.createElement('option');
      option.value = ind.id;
      
      const labelParts = [];
      if (ind.nome_sede) labelParts.push(`[${ind.nome_sede}]`);
      let addrStr = `${ind.via || ''} ${ind.civico || ''}`.trim();
      if (ind.cap || ind.citta) {
        addrStr += `, ${ind.cap || ''} ${ind.citta || ''}`.trim();
      }
      if (addrStr) labelParts.push(addrStr);
      if (ind.referente_consegna) labelParts.push(`(Ref: ${ind.referente_consegna})`);
      option.textContent = labelParts.join(' ');

      if (selectedSpedId && String(ind.id) === String(selectedSpedId)) {
        option.selected = true;
      }
      spedSelect.appendChild(option);
    });

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

  } catch (err) {
    spedSelect.innerHTML = `<option value="">Errore: ${err.message}</option>`;
    contSelect.innerHTML = `<option value="">Errore: ${err.message}</option>`;
  }
}

if (contSelect) {
  contSelect.addEventListener('change', () => {
    const selectedId = contSelect.value;
    if (!selectedId) {
      if (contTelInput) contTelInput.value = custTelHidden?.value || '';
      if (contEmailInput) contEmailInput.value = custEmailHidden?.value || '';
      if (contNomeHidden) contNomeHidden.value = '';
    } else {
      const contact = loadedContacts.find(c => String(c.id) === String(selectedId));
      if (contact) {
        if (contTelInput) contTelInput.value = contact.telefono || contact.cellulare || custTelHidden?.value || '';
        if (contEmailInput) contEmailInput.value = contact.email || custEmailHidden?.value || '';
        if (contNomeHidden) contNomeHidden.value = contact.nome_completo || '';
      }
    }
  });
}

async function fetchQuotePrice(tr) {
  const prodottoId = tr.querySelector('.inp-prodotto-id')?.value;
  const listinoId = document.getElementById('quote-listino-id')?.value;
  const qtyInput = tr.querySelector('.inp-qta');
  const priceInput = tr.querySelector('.inp-pu');

  if (!prodottoId || !listinoId || !qtyInput || !priceInput) return;

  const qty = parseFloat(qtyInput.value) || 1.0;
  
  try {
    const res = await fetch(`${BASE}/sales/pricelists/api/ottieni-prezzo?prodotto_id=${prodottoId}&listino_id=${listinoId}&quantita=${qty}`);
    const data = await res.json();
    
    if (data.ok) {
      let computedPrice = parseFloat(data.prezzo_unitario) || 0;
      
      // Factoring in active UoM mode conversions (engine returns price in commercial/standard unit)
      if (currentUomMode === 'stock') {
        const qFactor = parseFloat(tr.dataset.quantitaContenuto) || 1;
        if (qFactor > 0 && qFactor !== 1) {
          computedPrice = computedPrice * qFactor;
        }
      }
      
      priceInput.value = parseFloat(computedPrice.toFixed(4));
      
      const discountInput = tr.querySelector('.inp-sc');
      if (discountInput && data.sconto_perc !== undefined) {
        discountInput.value = parseFloat(data.sconto_perc) || 0;
      }
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

});
</script>
