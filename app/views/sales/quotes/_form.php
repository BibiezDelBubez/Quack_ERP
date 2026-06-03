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
#tbl-righe       { width: 100%; border-collapse: collapse; font-size: .85rem; }
#tbl-righe thead th { padding: .5rem .5rem; font-weight: 600; font-size: .78rem;
                      text-transform: uppercase; letter-spacing: .05em;
                      color: var(--qk-text-muted); border-bottom: 2px solid var(--qk-border);
                      white-space: nowrap; }
#tbl-righe tbody td { padding: .35rem .4rem; border-bottom: 1px solid var(--qk-border); vertical-align: top; }
#tbl-righe tbody tr:last-child td { border-bottom: none; }
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
              border-radius: var(--qk-radius-sm); padding: .3rem .4rem;
              color: var(--qk-text-primary); font-size: .85rem;
              transition: border .15s, background .15s; }
.riga-input:focus { outline: none; border-color: var(--qk-accent); background: var(--qk-input-bg, var(--qk-card-bg)); }
.riga-input.text-end { text-align: right; }
.riga-total { font-weight: 600; text-align: right; padding: .4rem .4rem;
              color: var(--qk-text-primary); }
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

<form method="post" id="form-offerta" action="<?= url('sales/quotes/create') ?>">
  <?= csrf_field() ?>

  <!-- Cliente nascosto -->
  <input type="hidden" name="cliente_id"    id="cliente_id"    value="<?= e($v['cliente_id']) ?>">
  <input type="hidden" name="cliente_label" id="cliente_label" value="<?= e($v['cliente_label']) ?>">

  <!-- ══════════════════ CLIENTE ══════════════════════════════════════════ -->
  <div class="qk-card mb-3">
    <div class="qk-card-header">
      <span class="qk-card-title"><i class="fa-solid fa-building me-2"></i>Cliente</span>
    </div>
    <div class="qk-card-body">
      <div class="d-flex gap-2 align-items-start">
        <div class="qk-ac-wrap flex-grow-1">
          <input type="text" id="cliente_search" class="qk-form-control <?= isset($errors['cliente_id']) ? 'is-invalid' : '' ?>"
                 placeholder="Cerca per nome, ragione sociale, email…"
                 value="<?= e($v['cliente_label']) ?>" autocomplete="off">
          <div class="qk-ac-dropdown" id="ac-clienti-drop"></div>
        </div>
        <button type="button" class="btn-qk btn-qk-secondary" id="btn-nuovo-cliente"
                title="Crea nuovo cliente" style="white-space:nowrap">
          <i class="fa-solid fa-plus"></i> Nuovo cliente
        </button>
      </div>
      <?php if (isset($errors['cliente_id'])): ?>
      <span class="qk-form-error mt-1"><i class="fa-solid fa-circle-xmark"></i> <?= e($errors['cliente_id']) ?></span>
      <?php endif; ?>

      <!-- Preview cliente selezionato -->
      <div id="cliente-preview" class="qk-cliente-preview" style="<?= $v['cliente_id'] ? '' : 'display:none' ?>">
        <i class="fa-solid fa-building fa-lg" style="color:var(--qk-accent);margin-top:.1rem"></i>
        <div>
          <div class="name" id="cp-nome"><?= e($v['cliente_label']) ?></div>
          <div class="info" id="cp-info"></div>
        </div>
        <button type="button" class="btn-clear" id="btn-clear-cliente" title="Rimuovi cliente">
          <i class="fa-solid fa-xmark"></i>
        </button>
      </div>
    </div>
  </div>

  <!-- ══════════════════ INTESTAZIONE OFFERTA ═════════════════════════════ -->
  <div class="qk-form-grid mb-3">

    <!-- Colonna sinistra: dati offerta -->
    <div>
      <div class="qk-card h-100">
        <div class="qk-card-header"><span class="qk-card-title">Dati offerta</span></div>
        <div class="qk-card-body">

          <div class="qk-form-row">
            <div class="qk-form-group">
              <label class="qk-form-label">N° Offerta (Prot.) <span class="required">*</span></label>
              <input type="text" name="numero_offerta" class="qk-form-control"
                     value="<?= e($v['numero_offerta'] ?: $numero_offerta) ?>" placeholder="es. 56.26">
            </div>
            <div class="qk-form-group">
              <label class="qk-form-label">Stato</label>
              <select name="stato" class="qk-form-control">
                <?php foreach (['bozza'=>'Bozza','inviata'=>'Inviata','accettata'=>'Accettata','rifiutata'=>'Rifiutata','scaduta'=>'Scaduta','annullata'=>'Annullata'] as $k => $lbl): ?>
                <option value="<?= e($k) ?>" <?= ($v['stato'] ?? 'bozza') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
                <?php endforeach; ?>
              </select>
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

          <div class="qk-form-group">
            <label class="qk-form-label">Canale vendita</label>
            <select name="canale_vendita" class="qk-form-control <?= isset($errors['canale_vendita']) ? 'is-invalid' : '' ?>">
              <?php foreach (['email_diretta'=>'Email diretta','dropshipping'=>'Dropshipping','agente'=>'Agente','sito'=>'Sito web'] as $k => $lbl): ?>
              <option value="<?= e($k) ?>" <?= ($v['canale_vendita'] ?? 'email_diretta') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

        </div>
      </div>
    </div>

    <!-- Colonna destra: destinatario e condizioni -->
    <div>
      <div class="qk-card h-100">
        <div class="qk-card-header"><span class="qk-card-title">Destinatario e consegna</span></div>
        <div class="qk-card-body">

          <div class="qk-form-group">
            <label class="qk-form-label">All'attenzione di</label>
            <input type="text" name="alla_ca" class="qk-form-control"
                   value="<?= e($v['alla_ca']) ?>" placeholder="Es. Sig. Mario Rossi">
          </div>

          <div class="qk-form-group">
            <label class="qk-form-label">Data consegna richiesta</label>
            <input type="date" name="data_consegna_richiesta" class="qk-form-control"
                   value="<?= e($v['data_consegna_richiesta']) ?>">
          </div>

          <div class="qk-form-group">
            <label class="qk-form-label">Trasporto</label>
            <input type="text" name="trasporto" class="qk-form-control"
                   value="<?= e($v['trasporto']) ?>" placeholder="Es. Franco destino">
          </div>

          <div class="qk-form-group">
            <label class="qk-form-label">Condizioni di pagamento</label>
            <input type="text" name="condizioni_pagamento" class="qk-form-control"
                   value="<?= e($v['condizioni_pagamento']) ?>" placeholder="Es. 30 gg d.f.f.m., bonifico anticipato…">
          </div>

        </div>
      </div>
    </div>

  </div><!-- /qk-form-grid -->

  <!-- ══════════════════ RIGHE PRODOTTO ═══════════════════════════════════ -->
  <div class="qk-card mb-3">
    <div class="qk-card-header d-flex align-items-center justify-content-between">
      <span class="qk-card-title"><i class="fa-solid fa-list me-2"></i>Prodotti / Righe offerta</span>
      <div class="d-flex gap-2">
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
            <th class="col-conf">Confezione</th>
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
          <!-- Le righe vengono aggiunte da JS -->
        </tbody>
      </table>
      <?php if (isset($errors['righe'])): ?>
      <div style="padding:.5rem 1rem">
        <span class="qk-form-error"><i class="fa-solid fa-circle-xmark"></i> <?= e($errors['righe']) ?></span>
      </div>
      <?php endif; ?>
    </div>

    <!-- Totali -->
    <div style="padding:1rem 1.25rem; border-top: 1px solid var(--qk-border);">
      <div class="d-flex justify-content-between align-items-start gap-3">

        <!-- Sconto globale -->
        <div style="max-width:260px">
          <div class="qk-form-group mb-0">
            <label class="qk-form-label">Sconto globale %</label>
            <input type="number" name="sconto_globale_perc" id="sconto_globale"
                   class="qk-form-control" style="width:120px"
                   value="<?= e($v['sconto_globale_perc']) ?>" min="0" max="100" step="0.01" placeholder="0">
          </div>
        </div>

        <!-- Riepilogo importi -->
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

  <!-- ══════════════════ NOTE ═════════════════════════════════════════════ -->
  <div class="qk-form-grid mb-3">
    <div class="qk-form-group">
      <label class="qk-form-label"><i class="fa-solid fa-note-sticky me-1"></i>Oggetto / Note per il cliente</label>
      <textarea name="note_cliente" class="qk-form-control" rows="3"
                placeholder="Descrizione oggetto dell'offerta, condizioni particolari…"><?= e($v['note_cliente']) ?></textarea>
    </div>
    <div class="qk-form-group">
      <label class="qk-form-label"><i class="fa-solid fa-lock me-1"></i>Note interne (non stampate)</label>
      <textarea name="note_interne" class="qk-form-control" rows="3"
                placeholder="Appunti interni, memo…"><?= e($v['note_interne']) ?></textarea>
    </div>
  </div>

  <!-- ══════════════════ AZIONI STICKY ════════════════════════════════════ -->
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
(function () {
'use strict';

const CSRF   = <?= json_encode(csrf_token()) ?>;
const BASE   = (typeof QK_BASE !== 'undefined') ? QK_BASE : '';

/* ─────────────────────────────────────────────────────────────────────────
   UTILITÀ
 ───────────────────────────────────────────────────────────────────────── */

function fmtEuro(n) {
  return '€ ' + Number(n).toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function debounce(fn, ms) {
  let t;
  return function(...args) { clearTimeout(t); t = setTimeout(() => fn.apply(this, args), ms); };
}

/* ─────────────────────────────────────────────────────────────────────────
   AUTOCOMPLETE GENERICO
   Parametri:
     input     — <input> visibile di testo
     dropdown  — <div> contenitore del dropdown
     apiUrl    — stringa URL
     onSelect  — callback(item) chiamata quando l'utente sceglie
     onCreate  — callback() quando l'utente clicca "Crea nuovo" (opzionale)
     createLabel — testo del link "Crea nuovo"
 ───────────────────────────────────────────────────────────────────────── */
function initAutocomplete({ input, dropdown, apiUrl, onSelect, onCreate, createLabel }) {
  let focused = -1;
  let items   = [];

  function close() {
    dropdown.classList.remove('open');
    dropdown.innerHTML = '';
    focused = -1;
    items   = [];
  }

  function open(results) {
    dropdown.innerHTML = '';
    items = results;
    focused = -1;

    if (results.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'qk-ac-empty';
      empty.textContent = 'Nessun risultato';
      dropdown.appendChild(empty);
    } else {
      results.forEach((item, i) => {
        const el = document.createElement('div');
        el.className = 'qk-ac-item';
        el.innerHTML = `<span class="qk-ac-item-label">${escHtml(item.label || item.nome || '')}</span>`
                     + (item.email    ? `<span class="qk-ac-item-sub">${escHtml(item.email)}</span>` : '')
                     + (item.telefono ? `<span class="qk-ac-item-sub">${escHtml(item.telefono)}</span>` : '')
                     + (item.sku      ? `<span class="qk-ac-item-sub">SKU: ${escHtml(item.sku)}</span>` : '');
        el.addEventListener('mousedown', (e) => { e.preventDefault(); onSelect(item); close(); });
        dropdown.appendChild(el);
      });
    }

    if (onCreate) {
      const cr = document.createElement('div');
      cr.className = 'qk-ac-create';
      cr.innerHTML = `<i class="fa-solid fa-plus"></i> ${escHtml(createLabel || 'Crea nuovo')}`;
      cr.addEventListener('mousedown', (e) => { e.preventDefault(); close(); onCreate(); });
      dropdown.appendChild(cr);
    }

    dropdown.classList.add('open');
  }

  function escHtml(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }

  const doSearch = debounce(async function(q) {
    if (!q) { close(); return; }
    try {
      const res  = await fetch(apiUrl + '?q=' + encodeURIComponent(q), { credentials: 'same-origin' });
      const data = await res.json();
      open(data);
    } catch (e) { close(); }
  }, 280);

  input.addEventListener('input', () => doSearch(input.value.trim()));
  input.addEventListener('focus', () => { if (input.value.trim()) doSearch(input.value.trim()); });

  // Keyboard navigation
  input.addEventListener('keydown', (e) => {
    const els = dropdown.querySelectorAll('.qk-ac-item');
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      focused = Math.min(focused + 1, els.length - 1);
      els.forEach((el, i) => el.classList.toggle('focused', i === focused));
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      focused = Math.max(focused - 1, 0);
      els.forEach((el, i) => el.classList.toggle('focused', i === focused));
    } else if (e.key === 'Enter' && focused >= 0 && items[focused]) {
      e.preventDefault();
      onSelect(items[focused]);
      close();
    } else if (e.key === 'Escape') {
      close();
    }
  });

  // Click outside
  document.addEventListener('click', (e) => {
    if (!input.contains(e.target) && !dropdown.contains(e.target)) close();
  });
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
}

function clearCliente() {
  elClienteId.value    = '';
  elClienteLabel.value = '';
  elClienteSearch.value = '';
  elClientePreview.style.display = 'none';
  elCpNome.textContent = '';
  elCpInfo.textContent = '';
}

document.getElementById('btn-clear-cliente').addEventListener('click', clearCliente);

elClienteSearch.addEventListener('input', () => {
  // Se svuota il campo, rimuovi la selezione
  if (!elClienteSearch.value.trim()) clearCliente();
});

initAutocomplete({
  input:       elClienteSearch,
  dropdown:    elClienteDrop,
  apiUrl:      BASE + '/sales/quotes/api/clienti',
  onSelect:    selectCliente,
  onCreate:    () => openModal('modal-nuovo-cliente'),
  createLabel: 'Crea nuovo cliente',
});

/* ─────────────────────────────────────────────────────────────────────────
   RIGHE PRODOTTO
 ───────────────────────────────────────────────────────────────────────── */

let righeCount = 0;

function buildRiga(prefill = {}) {
  const idx = righeCount++;
  const tr  = document.createElement('tr');
  tr.dataset.idx = idx;

  tr.innerHTML = `
    <td class="col-search">
      <div class="qk-ac-wrap">
        <input type="text" class="riga-input riga-search" placeholder="Cerca…" autocomplete="off">
        <div class="qk-ac-dropdown riga-ac-drop"></div>
      </div>
      <div class="riga-sku" data-sku=""></div>
      <input type="hidden" name="righe[${idx}][prodotto_id]" class="inp-prodotto-id" value="${prefill.prodotto_id || ''}">
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
      <input type="number" name="righe[${idx}][prezzo_unitario]" class="riga-input text-end inp-pu"
             placeholder="0,00" min="0" step="0.0001" value="${prefill.prezzo_unitario || ''}">
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

  // Ricalcola quando cambiano i valori numerici
  ['inp-qta','inp-pu','inp-sc','inp-iva'].forEach(cls => {
    tr.querySelector('.' + cls).addEventListener('input', calcTotali);
  });

  // Autocomplete prodotto
  const searchInput = tr.querySelector('.riga-search');
  const searchDrop  = tr.querySelector('.riga-ac-drop');

  initAutocomplete({
    input:       searchInput,
    dropdown:    searchDrop,
    apiUrl:      BASE + '/sales/quotes/api/prodotti',
    onSelect:    (item) => {
      tr.querySelector('.inp-prodotto-id').value  = item.id;
      tr.querySelector('.inp-desc').value         = item.label;
      tr.querySelector('.inp-um').value           = item.unita_misura || '';
      tr.querySelector('.riga-conf').value        = item.quantita_confezione || '';
      tr.querySelector('[data-sku]').textContent  = item.sku ? 'SKU: ' + item.sku : '';
      searchInput.value = item.label;
      calcTotali();
    },
    onCreate:    () => openModal('modal-nuovo-prodotto', tr),
    createLabel: 'Crea nuovo prodotto',
  });

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
      tr.querySelector('.riga-total').textContent = fmtEuro(tot);
    }
  });

  const scGlob = parseFloat(document.getElementById('sconto_globale')?.value) || 0;
  const impSc  = Math.round(imponibile * (1 - scGlob / 100) * 100) / 100;
  const ivaSc  = Math.round(totIva    * (1 - scGlob / 100) * 100) / 100;
  const totale = Math.round((impSc + ivaSc) * 100) / 100;

  document.getElementById('tot-imponibile').textContent = fmtEuro(impSc);
  document.getElementById('tot-iva').textContent        = fmtEuro(ivaSc);
  document.getElementById('tot-totale').textContent     = fmtEuro(totale);
}

document.getElementById('btn-aggiungi-riga').addEventListener('click', () => buildRiga());
document.getElementById('sconto_globale').addEventListener('input', calcTotali);

// Prima riga vuota all'avvio
buildRiga();

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

})(); // IIFE
</script>
