<?php
/**
 * sales/orders/_create.php
 * Form creazione ordine cliente diretto (senza preventivo).
 * Variabili attese: $values (array), $errors (array), $numero_ordine (string),
 *                  $aziende (array), $listini (array)
 */
$v = $values;
?>

<!-- ── Stili caricati via controller (sales-common.css) ────────────────── -->

<?php if (!empty($errors['_global'])): ?>
<div class="qk-alert qk-alert-danger" data-auto-dismiss="7000">
  <i class="fa-solid fa-circle-xmark"></i>
  <span><?= e($errors['_global']) ?></span>
  <button class="qk-alert-close"><i class="fa-solid fa-xmark"></i></button>
</div>
<?php endif; ?>

<?php if (!empty($warnings)): ?>
<!-- PIANO - Vendita Allo Scoperto Controllata (P4), §3.3: MAI un blocco —
     la vendita allo scoperto resta sempre permessa — ma richiede una
     conferma esplicita (bottone dedicato in barra azioni, non una
     checkbox) prima di procedere al salvataggio. -->
<div class="qk-alert qk-alert-warning">
  <i class="fa-solid fa-triangle-exclamation"></i>
  <div>
    <strong>Disponibilità insufficiente per <?= count($warnings) ?> prodotto/i.</strong>
    La vendita allo scoperto è comunque permessa: verifica le quantità e, se va bene così,
    clicca su <strong>"Conferma comunque e crea l'ordine"</strong> nella barra in alto.
    <div class="qk-table-wrap mt-2">
      <table class="qk-table" style="font-size:.85rem">
        <thead>
          <tr><th>Prodotto</th><th class="text-end">Richiesto</th><th class="text-end">Disponibile</th><th class="text-end">Scoperto</th></tr>
        </thead>
        <tbody>
        <?php foreach ($warnings as $w): ?>
          <tr>
            <td><?= e($w['prodotto_nome']) ?></td>
            <td class="text-end"><?= e(number_format((float)$w['richiesto'], 3, ',', '.')) ?></td>
            <td class="text-end"><?= e(number_format((float)$w['disponibile'], 3, ',', '.')) ?></td>
            <td class="text-end"><?= e(number_format((float)$w['scoperto'], 3, ',', '.')) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>


<!-- Barra Azioni Sticky -->
<div class="qk-sticky-action-bar mb-4">
  <div class="qk-sticky-action-bar-left">
    <?php if (!empty($warnings)): ?>
      <!-- Bottone dedicato invece di una checkbox (PIANO P4 §8): submit che
           porta con sé conferma_vendita_scoperto=1 solo se è QUESTO bottone
           a essere cliccato (attributi name/value sul <button>, standard HTML). -->
      <button type="submit" form="form-ordine-diretto" name="conferma_vendita_scoperto" value="1" class="btn-qk btn-qk-primary">
        <i class="fa-solid fa-triangle-exclamation"></i> Conferma comunque e crea l'ordine
      </button>
    <?php else: ?>
      <!-- id esplicito: il bottone e' FUORI dal <form> (associato solo via
           l'attributo form="..."), un pattern meno affidabile del bottone
           annidato usato da Preventivi (sales/quotes/_form.php,
           #btn-accetta-crea-ordine, dentro il proprio <form>) per
           l'intercettazione lato JS del click prima del submit nativo - vedi
           assets/js/sales-orders-create.js, che aggancia l'intercettazione
           al CLICK di questo bottone (non al submit del form) proprio per
           questo motivo. audit/piano-correzione-bug-righe-perse-vendita-
           scoperto-ordini.md, §5.6/§5.7, aggiornamento 08/08/2026 post
           riscontro in test browser reale. -->
      <button type="submit" form="form-ordine-diretto" id="ord-btn-submit" class="btn-qk btn-qk-primary">
        <i class="fa-solid fa-circle-check"></i> Conferma Ordine
      </button>
    <?php endif; ?>
    <a href="<?= url('sales/orders') ?>" class="btn-qk btn-qk-secondary">Annulla</a>
  </div>
  <div class="qk-sticky-action-bar-right">
    <a href="<?= url('sales/orders') ?>" class="btn-qk btn-qk-secondary">
      <i class="fa-solid fa-arrow-left"></i> Lista Ordini
    </a>
    <ul class="qk-status-path d-none d-sm-flex" style="margin:0; padding:0;">
      <li class="qk-status-path-item active">Bozza</li>
      <li class="qk-status-path-item pending">In Lavorazione</li>
      <li class="qk-status-path-item pending">Spedito</li>
      <li class="qk-status-path-item pending">Completato</li>
    </ul>
  </div>
</div>
  <ul class="qk-status-path d-none d-sm-flex">
    <li class="qk-status-path-item active">Bozza</li>
    <li class="qk-status-path-item pending">In Lavorazione</li>
    <li class="qk-status-path-item pending">Spedito</li>
    <li class="qk-status-path-item pending">Completato</li>
  </ul>
</div>

<form method="post" id="form-ordine-diretto" action="">
  <?= csrf_field() ?>

  <!-- Cliente nascosto -->
  <input type="hidden" name="cliente_id"    id="ord-cliente-id"    value="<?= e($v['cliente_id']) ?>">
  <input type="hidden" name="cliente_label" id="ord-cliente-label" value="<?= e($v['cliente_label']) ?>">

  <!-- Vendita Allo Scoperto Controllata (P4) — valorizzato via JS solo dopo
       conferma esplicita nella modale (vedi assets/js/sales-orders-create.js).
       audit/piano-correzione-bug-righe-perse-vendita-scoperto-ordini.md, §5.6. -->
  <input type="hidden" name="conferma_vendita_scoperto" id="ord-input-conferma-scoperto" value="">

  <!-- ── TESTATA ─────────────────────────────────────────────────────────── -->
  <div class="qk-card mb-3">
    <div class="qk-card-header qk-flex-row qk-justify-between">
      <span class="qk-card-title" style="margin:0">
        <i class="fa-solid fa-file-contract me-2 text-primary"></i>
        Nuovo Ordine Cliente
      </span>
      <span class="qk-text-muted" style="font-size:.82rem">
        N° provvisorio: <strong><?= e($numero_ordine) ?></strong>
      </span>
    </div>
    <input type="hidden" name="numero_ordine" value="<?= e($numero_ordine) ?>">
    <div class="qk-card-body">
      <div class="qk-form-grid mb-3">

        <!-- Col sinistra: Cliente & Azienda & Riferimento -->
        <div>
          <div class="qk-form-row mb-3">
            <!-- Cliente autocomplete -->
            <div class="qk-form-group flex-grow-1">
              <label class="qk-form-label">Cliente <span class="required">*</span></label>
              <div class="qk-flex-row qk-gap-sm">
                <div class="qk-m2o-wrap flex-grow-1">
                  <input type="text" id="ord-cliente-search" class="qk-form-control qk-m2o-input <?= isset($errors['cliente_id']) ? 'is-invalid' : '' ?>"
                         placeholder="Cerca cliente per nome, ragione sociale, email…"
                         value="<?= e($v['cliente_label']) ?>" autocomplete="off">
                  <button type="button" class="qk-m2o-clear <?= $v['cliente_id'] ? '' : 'd-none' ?>" id="ord-btn-clear-cliente" title="Rimuovi cliente">&times;</button>
                  <div class="qk-m2o-dropdown" id="ord-ac-clienti-drop"></div>
                </div>
                <button type="button" class="btn-qk btn-qk-secondary" id="ord-btn-nuovo-cliente" title="Crea nuovo cliente" style="white-space:nowrap">
                  <i class="fa-solid fa-plus"></i> Nuovo
                </button>
              </div>
              <div id="ord-cliente-helper" class="text-muted small mt-1" style="display:none;">
                <i class="fa-solid fa-circle-info me-1"></i><span id="ord-cliente-helper-content"></span>
              </div>
              <?php if (isset($errors['cliente_id'])): ?>
              <span class="qk-form-error mt-1"><i class="fa-solid fa-circle-xmark"></i> <?= e($errors['cliente_id']) ?></span>
              <?php endif; ?>
            </div>

            <!-- Azienda venditrice -->
            <div class="qk-form-group" style="flex:0 0 200px; min-width:170px;">
              <label class="qk-form-label">Azienda venditrice</label>
              <select name="azienda_interna_id" class="qk-form-control" style="font-size:.85rem;">
                <?php foreach ($aziende as $az): ?>
                  <option value="<?= $az['id'] ?>" <?= (string)($v['azienda_interna_id'] ?? '') === (string)$az['id'] ? 'selected' : '' ?>>
                    <?= e($az['ragione_sociale']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Magazzino di evasione (PIANO - Magazzino Esplicito alla
                 Conferma Ordine, P4b): precompilato col magazzino attivo di
                 sessione come default comodo, ma sempre visibile e
                 modificabile - mai piu risolto in silenzio al salvataggio. -->
            <div class="qk-form-group" style="flex:0 0 190px; min-width:170px;">
              <label class="qk-form-label">Magazzino di evasione <span class="required">*</span></label>
              <select name="magazzino_id" id="ord-select-magazzino" class="qk-form-control <?= isset($errors['magazzino_id']) ? 'is-invalid' : '' ?>" style="font-size:.85rem;">
                <?php foreach ($magazziniOrdine as $mg): ?>
                  <option value="<?= $mg['id'] ?>" <?= (string)($v['magazzino_id'] ?? '') === (string)$mg['id'] ? 'selected' : '' ?>>
                    <?= e($mg['nome']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <?php if (isset($errors['magazzino_id'])): ?>
              <span class="qk-form-error mt-1"><i class="fa-solid fa-circle-xmark"></i> <?= e($errors['magazzino_id']) ?></span>
              <?php else: ?>
              <div class="text-muted small mt-1">Da qui verrà riservata la merce alla conferma.</div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Riferimento interno e Condizioni pagamento -->
          <div class="qk-form-row mb-2">
            <div class="qk-form-group">
              <label class="qk-form-label">Rif. interno / N° ordine cliente</label>
              <input type="text" name="riferimento_interno" class="qk-form-control"
                     placeholder="Es. PO-12345 / Prot. 77/2026"
                     value="<?= e($v['riferimento_interno'] ?? '') ?>">
            </div>
            <div class="qk-form-group">
              <label class="qk-form-label">Condizioni pagamento</label>
              <input type="text" name="condizioni_pagamento" class="qk-form-control"
                     id="ord-condizioni-pagamento"
                     placeholder="Es. 30 gg d.f.f.m." value="<?= e($v['condizioni_pagamento'] ?? '') ?>">
            </div>
          </div>
        </div>

        <!-- Col destra: Date & Valuta & Trasporto -->
        <div>
          <div class="qk-form-row mb-3">
            <div class="qk-form-group">
              <label class="qk-form-label">Data ordine <span class="required">*</span></label>
              <input type="date" name="data_ordine" class="qk-form-control <?= isset($errors['data_ordine']) ? 'is-invalid' : '' ?>"
                     value="<?= e($v['data_ordine']) ?>">
              <?php if (isset($errors['data_ordine'])): ?>
              <span class="qk-form-error"><i class="fa-solid fa-circle-xmark"></i> <?= e($errors['data_ordine']) ?></span>
              <?php endif; ?>
            </div>
            <div class="qk-form-group">
              <label class="qk-form-label">Data consegna prevista</label>
              <input type="date" name="data_consegna_prevista" class="qk-form-control"
                     value="<?= e($v['data_consegna_prevista'] ?? '') ?>">
            </div>
          </div>
          <div class="qk-form-row">
            <div class="qk-form-group">
              <label class="qk-form-label">Valuta</label>
              <select name="valuta" class="qk-form-control">
                <option value="EUR" <?= ($v['valuta'] ?? 'EUR') === 'EUR' ? 'selected' : '' ?>>EUR (€)</option>
                <option value="USD" <?= ($v['valuta'] ?? '') === 'USD' ? 'selected' : '' ?>>USD ($)</option>
              </select>
            </div>
            <div class="qk-form-group">
              <label class="qk-form-label">Listino di Vendita</label>
              <select name="listino_id" id="ord-listino-id" class="qk-form-control">
                <?php foreach ($listini as $lst): ?>
                  <option value="<?= $lst['id'] ?>" <?= (string)($v['listino_id'] ?? '') === (string)$lst['id'] ? 'selected' : '' ?>>
                    <?= e($lst['nome']) ?> (<?= e($lst['valuta']) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="qk-form-group">
              <label class="qk-form-label">Trasporto (Resa)</label>
              <input type="text" name="trasporto" class="qk-form-control"
                     value="<?= e($v['trasporto'] ?? 'Franco destino') ?>" placeholder="Es. Franco destino">
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ── TAB NAVIGATION ──────────────────────────────────────────────────── -->
  <div class="qk-card mb-3">
    <div class="qk-card-body">
      <ul class="nav qk-nav-tabs" role="tablist">
        <li class="nav-item">
          <button class="nav-link active" type="button" onclick="switchOrdTab('prodotti')">
            <i class="fa-solid fa-list me-1"></i> Prodotti
          </button>
        </li>
        <li class="nav-item">
          <button class="nav-link" type="button" onclick="switchOrdTab('spedizione')">
            <i class="fa-solid fa-truck-fast me-1"></i> Spedizione & Contatti
          </button>
        </li>
        <li class="nav-item">
          <button class="nav-link" type="button" onclick="switchOrdTab('note')">
            <i class="fa-solid fa-pen me-1"></i> Note
          </button>
        </li>
      </ul>

      <div class="tab-content">

        <!-- ── TAB PRODOTTI ───────────────────────────────────────────────── -->
        <div class="tab-pane active" id="ord-pane-prodotti">

          <!-- Catalogo rapido -->
          <div class="qk-card mb-3 p-3 border-0 shadow-xs" style="border-radius:var(--qk-border-radius-lg,8px);">
            <div class="qk-flex-row qk-justify-between mb-2">
              <span class="fw-bold text-dark" style="font-size:.95rem;">
                <i class="fa-solid fa-cubes me-1 text-primary"></i> Catalogo Prodotti — clicca per aggiungere
              </span>
            </div>
            <div class="qk-filter-wrap mb-3" style="max-width:100%;">
              <i class="fa-solid fa-magnifying-glass"></i>
              <input type="text" id="ord-catalogo-search" class="qk-filter-input w-100" placeholder="Cerca prodotto, codice…" autocomplete="off">
            </div>
            <div id="ord-catalogo-grid" class="row g-2 mb-2" style="max-height:250px; overflow-y:auto;">
              <div class="col-12 text-center text-muted py-3 small">Digita nella barra di ricerca per visualizzare i prodotti</div>
            </div>
          </div>

          <!-- Toolbar righe -->
          <div class="qk-flex-row qk-justify-between mb-2">
            <h4 style="font-size:1rem; margin:0;"><i class="fa-solid fa-list me-2"></i>Righe ordine</h4>
            <div class="qk-flex-row qk-gap-sm ">
              <div class="qk-flex-row qk-gap-xs me-2" style="font-size:.85rem;">
                <label class="qk-form-label mb-0 text-muted" style="white-space:nowrap; font-weight:600;">Visualizza in:</label>
                <select id="ord-uom-mode" name="uom_mode" class="qk-form-control" style="width:auto; padding:2px 8px; font-size:.85rem; height:auto;">
                  <option value="commercial" <?= ($v['uom_mode'] ?? 'commercial') === 'commercial' ? 'selected' : '' ?>>Unità commerciale</option>
                  <option value="stock"      <?= ($v['uom_mode'] ?? '') === 'stock' ? 'selected' : '' ?>>Unità di stock</option>
                </select>
              </div>
              <button type="button" id="ord-btn-nuovo-prodotto" class="btn-qk btn-qk-secondary btn-qk-sm">
                <i class="fa-solid fa-box-open"></i> Nuovo prodotto
              </button>
              <button type="button" id="ord-btn-aggiungi-riga" class="btn-qk btn-qk-primary btn-qk-sm">
                <i class="fa-solid fa-plus"></i> Aggiungi riga
              </button>
            </div>
          </div>

          <div class="qk-table-wrap">
            <table class="qk-table" id="tbl-righe-ord">
              <thead>
                <tr>
                  <th class="col-search">Prodotto</th>
                  <th class="col-desc">Descrizione</th>
                  <th class="col-conf">Confezione</th>
                  <th class="col-qta">Qtà <span class="required">*</span></th>
                  <th class="col-um">U.M.</th>
                  <th class="col-costo">Costo</th>
                  <th class="col-pu">Prezzo unit.</th>
                  <th class="col-sc">Sc. %</th>
                  <th class="col-iva">IVA %</th>
                  <th class="col-tot">Totale</th>
                  <th class="col-del"></th>
                </tr>
              </thead>
              <tbody id="ord-tbody-righe">
              </tbody>
            </table>
            <?php if (isset($errors['righe'])): ?>
            <div style="padding:.5rem 1rem">
              <span class="qk-form-error"><i class="fa-solid fa-circle-xmark"></i> <?= e($errors['righe']) ?></span>
            </div>
            <?php endif; ?>
          </div>

          <!-- Totali -->
          <div style="padding:1rem 1.25rem; border-top:1px solid var(--qk-border); margin-top:1rem;">
            <div class="qk-flex-row qk-justify-between ">
              <div style="max-width:260px">
                <div class="qk-form-group mb-0">
                  <label class="qk-form-label">Sconto globale %</label>
                  <input type="number" name="sconto_globale_perc" id="ord-sconto-globale"
                         class="qk-form-control" style="width:120px"
                         value="<?= e($v['sconto_globale_perc'] ?? '0') ?>" min="0" max="100" step="0.01" placeholder="0">
                </div>
              </div>
              <div class="qk-totali-grid" style="min-width:280px">
                <span class="lbl">Imponibile</span>
                <span class="val" id="ord-tot-imponibile">€ 0,00</span>
                <span class="lbl">IVA (22%)</span>
                <span class="val" id="ord-tot-iva">€ 0,00</span>
                <hr class="qk-totali-sep">
                <span class="lbl lbl-total">Totale IVA inclusa</span>
                <span class="val val-total" id="ord-tot-totale">€ 0,00</span>
              </div>
            </div>
            <!-- KPI Margine -->
            <div class="row g-3 mt-3 pt-3 border-top">
              <div class="col-6 col-md-3">
                <div class="qk-card p-3 text-center shadow-xs">
                  <div class="text-muted small fw-bold text-uppercase" style="font-size:.75rem;">Totale Cliente</div>
                  <div class="fs-4 fw-bold text-dark mt-1" id="ord-box-tot-cliente">€ 0,00</div>
                </div>
              </div>
              <div class="col-6 col-md-3">
                <div class="qk-card p-3 text-center shadow-xs">
                  <div class="text-muted small fw-bold text-uppercase" style="font-size:.75rem;">Totale Costo</div>
                  <div class="fs-4 fw-bold text-dark mt-1" id="ord-box-tot-costo">€ 0,00</div>
                </div>
              </div>
              <div class="col-6 col-md-3">
                <div class="p-3 rounded border shadow-xs text-center" id="ord-box-margine-eur-wrap" style="background:var(--qk-success-bg); border-color:var(--qk-success);">
                  <div class="text-muted small fw-bold text-uppercase" style="font-size:.75rem;">Margine €</div>
                  <div class="fs-4 fw-bold text-success mt-1" id="ord-box-margine-eur">€ 0,00</div>
                </div>
              </div>
              <div class="col-6 col-md-3">
                <div class="p-3 rounded border shadow-xs text-center" id="ord-box-margine-pct-wrap" style="background:var(--qk-success-bg); border-color:var(--qk-success);">
                  <div class="text-muted small fw-bold text-uppercase" style="font-size:.75rem;">Margine %</div>
                  <div class="fs-4 fw-bold text-success mt-1" id="ord-box-margine-pct">0,00%</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- ── TAB SPEDIZIONE & CONTATTI ────────────────────────────────── -->
        <div class="tab-pane d-none" id="ord-pane-spedizione">
          <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px,1fr)); gap:1.5rem;">

            <!-- Destinazione -->
            <div>
              <h4 style="font-size:.9rem; text-transform:uppercase; letter-spacing:.05em; color:var(--qk-text-muted); margin-bottom:1rem; border-bottom:1px solid var(--qk-border); padding-bottom:.5rem;">
                <i class="fa-solid fa-truck-ramp-box me-1"></i>Spedizione & Destinazione
              </h4>

              <div class="qk-form-group">
                <label class="qk-form-label">Seleziona indirizzo di consegna</label>
                <select name="indirizzo_spedizione_id" id="ord-indirizzo-spedizione-id" class="qk-form-control"
                        data-selected-id="<?= e($v['indirizzo_spedizione_id'] ?? '') ?>">
                  <option value="">Seleziona cliente prima...</option>
                </select>
              </div>

              <!-- Box preview / dettagli indirizzo -->
              <div id="ord-sped-fields-box" class="border rounded p-3 mb-3"
                   style="display:none; background:var(--qk-bg-subtle); border-color:var(--qk-border)!important;">
                <div class="qk-flex-row qk-justify-between mb-3">
                  <h6 class="mb-0 fw-bold" style="font-size:.85rem;">
                    <i class="fa-solid fa-map-location-dot me-1"></i>Dettagli Spedizione
                    <span id="ord-sped-state-badge" style="font-size:.72rem; vertical-align:middle;"></span>
                  </h6>
                  <button type="button" class="btn-qk btn-qk-xs btn-qk-ghost text-danger" id="ord-btn-ripristina-sped"
                          title="Ripristina valori anagrafica"><i class="fa-solid fa-rotate-left me-1"></i>Ripristina</button>
                </div>
                <input type="hidden" name="consegna_nome_sede" id="ord-consegna-nome-sede" value="">
                <div class="qk-form-row mb-2">
                  <div class="qk-form-group flex-grow-1">
                    <label class="qk-form-label">Via / Piazza <span class="required">*</span></label>
                    <input type="text" name="consegna_via" id="ord-consegna-via" class="qk-form-control" value="<?= e($v['consegna_via'] ?? '') ?>">
                  </div>
                  <div class="qk-form-group" style="flex:0 0 80px;">
                    <label class="qk-form-label">Civico</label>
                    <input type="text" name="consegna_civico" id="ord-consegna-civico" class="qk-form-control" value="<?= e($v['consegna_civico'] ?? '') ?>">
                  </div>
                </div>
                <div class="qk-form-row mb-2">
                  <div class="qk-form-group" style="flex:0 0 100px;">
                    <label class="qk-form-label">CAP <span class="required">*</span></label>
                    <input type="text" name="consegna_cap" id="ord-consegna-cap" class="qk-form-control" value="<?= e($v['consegna_cap'] ?? '') ?>">
                  </div>
                  <div class="qk-form-group flex-grow-1">
                    <label class="qk-form-label">Città <span class="required">*</span></label>
                    <input type="text" name="consegna_citta" id="ord-consegna-citta" class="qk-form-control" value="<?= e($v['consegna_citta'] ?? '') ?>">
                  </div>
                </div>
                <div class="qk-form-row mb-2">
                  <div class="qk-form-group">
                    <label class="qk-form-label">Provincia</label>
                    <input type="text" name="consegna_provincia" id="ord-consegna-provincia" class="qk-form-control" maxlength="2" placeholder="MI" value="<?= e($v['consegna_provincia'] ?? '') ?>">
                  </div>
                  <div class="qk-form-group">
                    <label class="qk-form-label">Nazione</label>
                    <input type="text" name="consegna_nazione" id="ord-consegna-nazione" class="qk-form-control" maxlength="2" placeholder="IT" value="<?= e($v['consegna_nazione'] ?? 'IT') ?>">
                  </div>
                </div>
                <div class="qk-form-group mb-2">
                  <label class="qk-form-label">Presso (c/o)</label>
                  <input type="text" name="consegna_presso" id="ord-consegna-presso" class="qk-form-control" value="<?= e($v['consegna_presso'] ?? '') ?>" placeholder="Es. Magazzino Nord">
                </div>
                <div class="qk-form-row mb-2">
                  <div class="qk-form-group">
                    <label class="qk-form-label">Referente Consegna</label>
                    <input type="text" name="consegna_referente" id="ord-consegna-referente" class="qk-form-control" value="<?= e($v['consegna_referente'] ?? '') ?>" placeholder="Persona sul posto">
                  </div>
                  <div class="qk-form-group">
                    <label class="qk-form-label">Tel. Consegna</label>
                    <input type="text" name="consegna_telefono" id="ord-consegna-telefono" class="qk-form-control" value="<?= e($v['consegna_telefono'] ?? '') ?>">
                  </div>
                </div>
                <div class="qk-form-group mb-3">
                  <label class="qk-form-label">Note Consegna</label>
                  <input type="text" name="consegna_note" id="ord-consegna-note" class="qk-form-control" value="<?= e($v['consegna_note'] ?? '') ?>" placeholder="Es. Citofono 3, consegnare al mattino">
                </div>
                <div id="ord-div-salva-anagrafica" style="display:none; align-items:center; gap:.5rem; padding-top:.25rem;">
                  <input type="checkbox" name="salva_in_anagrafica_indirizzo" id="ord-salva-anagrafica" value="1" style="transform:scale(1.1);">
                  <label class="qk-form-label mb-0" for="ord-salva-anagrafica" style="cursor:pointer; color:var(--qk-accent);">
                    Salva in anagrafica per usi futuri
                  </label>
                </div>
              </div>
            </div>

            <!-- Referente cliente -->
            <div>
              <h4 style="font-size:.9rem; text-transform:uppercase; letter-spacing:.05em; color:var(--qk-text-muted); margin-bottom:1rem; border-bottom:1px solid var(--qk-border); padding-bottom:.5rem;">
                <i class="fa-solid fa-address-card me-1"></i>Referente Cliente
              </h4>
              <div class="qk-form-group">
                <label class="qk-form-label">Seleziona referente da anagrafica</label>
                <select name="contatto_id" id="ord-contatto-id" class="qk-form-control"
                        data-selected-id="<?= e($v['contatto_id'] ?? '') ?>">
                  <option value="">Seleziona cliente prima...</option>
                </select>
              </div>
              <div class="border rounded p-3 mb-3" style="background:var(--qk-bg-subtle); border-color:var(--qk-border)!important;">
                <div class="qk-flex-row qk-justify-between mb-3">
                  <h6 class="mb-0 fw-bold" style="font-size:.85rem;">
                    <i class="fa-solid fa-id-card-clip me-1"></i>Dettagli Referente
                  </h6>
                </div>
                <div class="qk-form-group mb-2">
                  <label class="qk-form-label">Alla c.a. di</label>
                  <input type="text" name="contatto_nome" id="ord-contatto-nome" class="qk-form-control"
                         value="<?= e($v['contatto_nome'] ?? '') ?>" placeholder="Es. Dott. Mario Rossi">
                </div>
                <div class="qk-form-row">
                  <div class="qk-form-group">
                    <label class="qk-form-label">Telefono</label>
                    <input type="text" name="contatto_telefono" id="ord-contatto-telefono" class="qk-form-control"
                           value="<?= e($v['contatto_telefono'] ?? '') ?>">
                  </div>
                  <div class="qk-form-group">
                    <label class="qk-form-label">Email</label>
                    <input type="email" name="contatto_email" id="ord-contatto-email" class="qk-form-control"
                           value="<?= e($v['contatto_email'] ?? '') ?>">
                  </div>
                </div>
              </div>
            </div>

          </div>
        </div>

        <!-- ── TAB NOTE ───────────────────────────────────────────────────── -->
        <div class="tab-pane d-none" id="ord-pane-note">
          <div class="qk-form-group">
            <label class="qk-form-label"><i class="fa-solid fa-note-sticky me-1"></i>Note ordine (interne)</label>
            <textarea name="note" class="qk-form-control" rows="5"
                      placeholder="Note visibili internamente su questo ordine..."><?= e($v['note'] ?? '') ?></textarea>
          </div>
        </div>

      </div><!-- /tab-content -->
    </div>
  </div>



</form><!-- /form-ordine-diretto -->


<!-- ══════════════════════ MODALE NUOVO CLIENTE ═════════════════════════════ -->
<div class="modal fade" id="ord-modal-nuovo-cliente" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="background:var(--qk-card-bg);color:var(--qk-text-primary);border:1px solid var(--qk-border)">
      <div class="modal-header" style="border-color:var(--qk-border)">
        <h5 class="modal-title"><i class="fa-solid fa-user-plus me-2"></i>Nuovo cliente rapido</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
      </div>
      <div class="modal-body">
        <div id="ord-mnc-error" class="qk-alert qk-alert-danger mb-3" style="display:none">
          <i class="fa-solid fa-circle-xmark"></i> <span id="ord-mnc-error-msg"></span>
        </div>
        <form id="ord-form-nuovo-cliente" novalidate>
          <div class="qk-form-group">
            <label class="qk-form-label">Tipo soggetto</label>
            <div class="qk-flex-row qk-gap-sm">
              <label class="qk-radio-card is-selected" id="ord-rc-azienda">
                <input type="radio" name="tipo_soggetto" value="azienda" checked>
                <i class="fa-solid fa-building"></i> Azienda
              </label>
              <label class="qk-radio-card" id="ord-rc-privato">
                <input type="radio" name="tipo_soggetto" value="privato">
                <i class="fa-solid fa-user"></i> Privato
              </label>
            </div>
          </div>
          <div class="qk-form-group" id="ord-mnc-rs-wrap">
            <label class="qk-form-label">Ragione sociale <span class="required">*</span></label>
            <input type="text" name="ragione_sociale" id="ord-mnc-ragione-sociale" class="qk-form-control" placeholder="Acme S.r.l.">
          </div>
          <div class="d-none" id="ord-mnc-nc-wrap">
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
        <button type="button" class="btn-qk btn-qk-primary" id="ord-btn-salva-cliente">
          <i class="fa-solid fa-floppy-disk"></i> Crea cliente
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ══════════════════════ MODALE NUOVO PRODOTTO ════════════════════════════ -->
<div class="modal fade" id="ord-modal-nuovo-prodotto" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="background:var(--qk-card-bg);color:var(--qk-text-primary);border:1px solid var(--qk-border)">
      <div class="modal-header" style="border-color:var(--qk-border)">
        <h5 class="modal-title"><i class="fa-solid fa-box-open me-2"></i>Nuovo prodotto rapido</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
      </div>
      <div class="modal-body">
        <div id="ord-mnp-error" class="qk-alert qk-alert-danger mb-3" style="display:none">
          <i class="fa-solid fa-circle-xmark"></i> <span id="ord-mnp-error-msg"></span>
        </div>
        <form id="ord-form-nuovo-prodotto" novalidate>
          <div class="qk-form-group">
            <label class="qk-form-label">Nome prodotto <span class="required">*</span></label>
            <input type="text" name="nome" id="ord-mnp-nome" class="qk-form-control" placeholder="Es. Flocculante liquido 5 Kg">
          </div>
          <div class="qk-form-row">
            <div class="qk-form-group">
              <label class="qk-form-label">SKU interno</label>
              <input type="text" name="sku_interno" class="qk-form-control" placeholder="Lascia vuoto per auto-generare">
            </div>
            <div class="qk-form-group">
              <label class="qk-form-label">Unità di misura</label>
              <select name="unita_misura" class="qk-form-control">
                <option value="pz">pz</option>
                <option value="kg">kg</option>
                <option value="l">l</option>
              </select>
            </div>
          </div>
          <p class="qk-text-muted" style="font-size:.82rem">
            <i class="fa-solid fa-circle-info me-1"></i>Il prezzo verrà impostato direttamente nella riga dell'ordine.
          </p>
        </form>
      </div>
      <div class="modal-footer" style="border-color:var(--qk-border)">
        <button type="button" class="btn-qk btn-qk-secondary" data-bs-dismiss="modal">Annulla</button>
        <button type="button" class="btn-qk btn-qk-primary" id="ord-btn-salva-prodotto">
          <i class="fa-solid fa-floppy-disk"></i> Crea prodotto
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ══════════════ MODALE CONFERMA VENDITA ALLO SCOPERTO ══════════════════
     Mirror di qk-modal-scoperto-preventivo (sales/quotes/_form.php) — stesso
     pattern, adattato alla creazione ordine diretto (nessun id ordine ancora
     esistente: le righe vengono passate al pre-check dal DOM, non da un id
     già salvato). audit/piano-correzione-bug-righe-perse-vendita-scoperto-
     ordini.md, §5.6. -->
<div class="modal fade" id="ord-modal-scoperto" tabindex="-1" aria-labelledby="ordms-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="background:var(--qk-card-bg);color:var(--qk-text-primary);border:1px solid var(--qk-border)">
      <div class="modal-header" style="border-color:var(--qk-border)">
        <h5 class="modal-title" id="ordms-title"><i class="fa-solid fa-triangle-exclamation text-warning me-2"></i>Disponibilità insufficiente</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
      </div>
      <div class="modal-body">
        <p class="mb-2">
          La disponibilità a magazzino non copre tutte le righe di questo ordine. La vendita allo scoperto è comunque permessa: verifica le quantità e, se va bene così, conferma qui sotto.
        </p>
        <div class="qk-table-wrap">
          <table class="qk-table" style="font-size:.85rem">
            <thead>
              <tr><th>Prodotto</th><th class="text-end">Richiesto</th><th class="text-end">Disponibile</th><th class="text-end">Scoperto</th></tr>
            </thead>
            <tbody id="ordms-righe-scoperte"></tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer" style="border-color:var(--qk-border)">
        <button type="button" class="btn-qk btn-qk-secondary" data-bs-dismiss="modal">Annulla</button>
        <button type="button" class="btn-qk btn-qk-success" id="ord-btn-conferma-scoperto">
          <i class="fa-solid fa-triangle-exclamation"></i> Conferma comunque e crea l'ordine
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════ JAVASCRIPT ═══════════════════════════════ -->
<script>
window.QK_ORDERS_CREATE_CONFIG = {
  csrf: <?= json_encode(csrf_token()) ?>,
  margineSogliaVerde: <?= json_encode($margineSogliaVerde ?? 20.0) ?>,
  margineSogliaGiallo: <?= json_encode($margineSogliaGiallo ?? 10.0) ?>,
  ottieniPrezzoUrl: <?= json_encode(url('sales/pricelists/api/ottieni-prezzo')) ?>,
  verificaDisponibilitaUrl: <?= json_encode(url('sales/orders/api/verifica-disponibilita')) ?>
};
</script>
<!-- asset(), non url(): cache-busting automatico via filemtime() (vedi
     includes/functions.php). Prima di questo fix questi tre script erano
     inclusi con url() (nessun cache-busting) mentre lo stesso pattern in
     Preventivi (sales/quotes/_form.php) usa correttamente asset() per gli
     stessi file condivisi (pricing-widget.js, margin-semaforo.js) - la
     causa, scoperta in test browser reali (Livello 2, scenario S2), di
     comportamento incoerente: il browser continuava a servire una copia
     in cache di sales-orders-create.js anche dopo modifiche al file su
     disco, perche' l'URL non cambiava mai tra una modifica e l'altra.
     audit/piano-correzione-bug-righe-perse-vendita-scoperto-ordini.md,
     aggiornamento 08/08/2026. -->
<script src="<?= asset('js/pricing-widget.js') ?>"></script>
<script src="<?= asset('js/margin-semaforo.js') ?>"></script>
<script src="<?= asset('js/sales-orders-create.js') ?>"></script>

