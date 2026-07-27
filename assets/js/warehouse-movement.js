/**
 * warehouse-movement.js
 * ─────────────────────────────────────────────────────────────────────────────
 * Logica interattiva del form "Nuovo movimento magazzino":
 *   - Ricerca prodotto con autocomplete (debounced)
 *   - Caricamento info prodotto: giacenza, UoM, traccia_lotti, lotti disponibili
 *   - Gestione lotti: switch esistente/nuovo (solo per carico), filtro FEFO (scarico)
 *   - Aggiorna hint quantità e riepilogo laterale in tempo reale
 *   - Adatta label "Quantità" in base al tipo operazione
 *
 * Dipendenze: window.QkWarehouse (iniettato da _movement_form.php)
 */

/* global QkWarehouse */

(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {

    const cfg = window.QkWarehouse;
    if (!cfg) return;

    /* ── DOM refs ────────────────────────────────────────────────────────── */
    const selectMagazzino = document.getElementById('selectMagazzino'); // Fix 1, §8.2
    const prodSearch    = document.getElementById('prodottoSearch');
    const prodIdInput   = document.getElementById('prodottoId');
    const prodDropdown  = document.getElementById('prodottoDropdown');
    const prodInfo      = document.getElementById('prodottoInfo');
    const infoGiacenza  = document.getElementById('infoGiacenza');
    const infoUom          = document.getElementById('infoUom');
    const infoLottiRow     = document.getElementById('infoLottiRow');
    const infoLottiOffRow  = document.getElementById('infoLottiOffRow');
    const linkModProdotto  = document.getElementById('linkModificaProdotto');

    // Lotto section
    const lottoSection      = document.getElementById('lottoSection');
    const lottoModeWrap     = document.getElementById('lottoModeWrap');
    const lottoEsistenteDiv = document.getElementById('lottoEsistenteDiv');
    const lottoNuovoDiv     = document.getElementById('lottoNuovoDiv');
    const selectLotto       = document.getElementById('selectLotto');
    const nuovoLottoInterno = document.getElementById('nuovoLottoInterno');
    const radioLottoModes   = document.querySelectorAll('input[name="lotto_mode"]');

    const inputQta      = document.getElementById('inputQuantita');
    const qtaLabel      = document.getElementById('qtaLabel');
    const qtaHint       = document.getElementById('qtaHint');
    const qtaUomSuffix  = document.getElementById('qtaUomSuffix');

    const riepilogoTipo        = document.getElementById('riepilogoTipo');
    const riepilogoProdotto    = document.getElementById('riepilogoProdotto');
    const riepilogoGiacAttuale = document.getElementById('riepilogoGiacAttuale');
    const riepilogoNuovaRow    = document.getElementById('riepilogoNuovaRow');
    const riepilogoGiacNuova   = document.getElementById('riepilogoGiacNuova');

    const radioTipi = document.querySelectorAll('input[name="tipo_movimento"]');

    /* ── Stato ───────────────────────────────────────────────────────────── */
    let prodottoCorrente = null; // oggetto prodotto selezionato
    let lottiCorrente    = [];   // tutti i lotti del prodotto (inclusi esauriti)

    const TIPO_CONFIG = {
      carico: {
        label:          'Quantità da caricare',
        hint:           'Inserisci la quantità da <strong>aggiungere</strong> alla giacenza.',
        riepilogoLabel: 'Carico',
        segno:          '+',
      },
      scarico: {
        label:          'Quantità da scaricare',
        hint:           'Inserisci la quantità da <strong>rimuovere</strong> dalla giacenza.',
        riepilogoLabel: 'Scarico',
        segno:          '−',
      },
      rettifica: {
        label:          'Nuova giacenza',
        hint:           'Inserisci la quantità <strong>effettiva contata</strong>. La giacenza verrà impostata a questo valore.',
        riepilogoLabel: 'Rettifica',
        segno:          '=',
      },
    };

    /* ── Tipo movimento ──────────────────────────────────────────────────── */

    function getTipo() {
      for (const r of radioTipi) if (r.checked) return r.value;
      return 'carico';
    }

    function getLottoMode() {
      for (const r of radioLottoModes) if (r.checked) return r.value;
      return 'esistente';
    }

    function updateTipoUI() {
      const tipo = getTipo();
      const conf = TIPO_CONFIG[tipo] || TIPO_CONFIG.carico;

      // Label e hint quantità
      qtaLabel.innerHTML = conf.label + ' <span class="required">*</span>';
      qtaHint.innerHTML  = conf.hint;
      qtaHint.hidden     = false;

      // Riepilogo
      riepilogoTipo.textContent = conf.riepilogoLabel;

      // Visual selection sulle card tipo
      document.querySelectorAll('.qkwh-tipo-option').forEach(el => {
        const radio = el.querySelector('input[type=radio][name=tipo_movimento]');
        el.classList.toggle('selected', radio && radio.checked);
      });

      updateLottoUI();
      updateRiepilogoGiacenza();
    }

    radioTipi.forEach(r => r.addEventListener('change', updateTipoUI));
    document.querySelectorAll('.qkwh-tipo-option').forEach(el => {
      el.addEventListener('click', function () {
        const radio = this.querySelector('input[type=radio][name=tipo_movimento]');
        if (radio) { radio.checked = true; updateTipoUI(); }
      });
    });

    /* ── Gestione lotti ──────────────────────────────────────────────────── */

    function updateLottoUI() {
      if (!prodottoCorrente || !prodottoCorrente.traccia_lotti) {
        lottoSection.hidden = true;
        return;
      }

      const tipo = getTipo();
      lottoSection.hidden = false;

      if (tipo === 'carico') {
        // Mostra lo switch modalità
        lottoModeWrap.hidden = false;

        // Visual selection sulle card lotto_mode
        document.querySelectorAll('.qkwh-tipo-option').forEach(el => {
          const radio = el.querySelector('input[type=radio][name=lotto_mode]');
          if (radio) el.classList.toggle('selected', radio.checked);
        });

        const mode = getLottoMode();
        lottoEsistenteDiv.hidden = (mode === 'nuovo');
        lottoNuovoDiv.hidden     = (mode !== 'nuovo');

        if (mode === 'esistente') {
          // Per il carico su lotto esistente mostra tutti i lotti (anche esauriti)
          renderLotti(lottiCorrente, false);
        }

      } else {
        // Scarico e rettifica: solo lotti esistenti, niente creazione
        lottoModeWrap.hidden     = true;
        lottoEsistenteDiv.hidden = false;
        lottoNuovoDiv.hidden     = true;

        // Reset mode to "esistente" nei radio button
        for (const r of radioLottoModes) r.checked = (r.value === 'esistente');

        // Scarico → solo lotti con disponibilità > 0 (FEFO)
        // Rettifica → tutti i lotti
        renderLotti(lottiCorrente, tipo === 'scarico');
      }
    }

    radioLottoModes.forEach(r => r.addEventListener('change', () => {
      updateLottoModeVisuals();
      updateLottoUI();
    }));

    // Supporto click sulle card lotto_mode
    document.querySelectorAll('#lottoModeWrap .qkwh-tipo-option').forEach(el => {
      el.addEventListener('click', function () {
        const radio = this.querySelector('input[type=radio][name=lotto_mode]');
        if (radio) { radio.checked = true; updateLottoModeVisuals(); updateLottoUI(); }
      });
    });

    function updateLottoModeVisuals() {
      document.querySelectorAll('#lottoModeWrap .qkwh-tipo-option').forEach(el => {
        const radio = el.querySelector('input[type=radio][name=lotto_mode]');
        if (radio) el.classList.toggle('selected', radio.checked);
      });
    }

    /**
     * Popola la select lotto.
     * @param {Array}   lotti            tutti i lotti del prodotto
     * @param {boolean} soloDisponibili  true → filtra qty > 0 (per scarico FEFO)
     */
    function renderLotti(lotti, soloDisponibili) {
      selectLotto.innerHTML = '<option value="">— seleziona lotto —</option>';
      const lista = soloDisponibili
        ? lotti.filter(l => l.quantita_disponibile > 0)
        : lotti;

      lista.forEach(l => {
        const opt  = document.createElement('option');
        opt.value  = l.id;
        const scad = l.data_scadenza ? ' · scad. ' + l.data_scadenza : '';
        const disp = ' (disp. ' + fmtQty(l.quantita_disponibile) + ')';
        opt.textContent = l.codice_lotto_interno + scad + disp;
        if (l.quantita_disponibile <= 0) opt.textContent += ' — ESAURITO';
        selectLotto.appendChild(opt);
      });

      if (lista.length === 0) {
        const opt = document.createElement('option');
        opt.value = '';
        opt.disabled = true;
        opt.textContent = soloDisponibili
          ? '— nessun lotto con giacenza disponibile —'
          : '— nessun lotto presente —';
        selectLotto.appendChild(opt);
      }
    }

    /* ── Calcola e mostra giacenza prevista ──────────────────────────────── */

    function updateRiepilogoGiacenza() {
      if (!prodottoCorrente) {
        riepilogoNuovaRow.hidden = true;
        return;
      }

      const tipo    = getTipo();
      const qtaStr  = String(inputQta.value).replace(',', '.');
      const qta     = parseFloat(qtaStr);
      const attuale = prodottoCorrente.qta_disponibile;
      const uom     = prodottoCorrente.uom;

      riepilogoGiacAttuale.textContent = fmtQty(attuale) + ' ' + uom;

      if (isNaN(qta) || qta < 0) {
        riepilogoNuovaRow.hidden = true;
        return;
      }

      let nuova;
      if (tipo === 'carico')        nuova = attuale + qta;
      else if (tipo === 'scarico')  nuova = attuale - qta;
      else                          nuova = qta; // rettifica

      riepilogoGiacNuova.textContent = fmtQty(nuova) + ' ' + uom;
      riepilogoGiacNuova.className   = 'qkwh-riepilogo-val qkwh-giacenza-nuova' +
        (nuova < 0 ? ' qkwh-giacenza-negativa' : nuova === 0 ? ' qkwh-giacenza-zero' : '');
      riepilogoNuovaRow.hidden = false;
    }

    inputQta.addEventListener('input', updateRiepilogoGiacenza);

    /** Formatta quantità senza decimali inutili (mirror di fmt_qty PHP) */
    function fmtQty(n) {
      if (n === 0) return '0';
      // toLocaleString con massimo 3 decimali, rimuovi trailing zeros
      const s = n.toLocaleString('it-IT', { minimumFractionDigits: 0, maximumFractionDigits: 3 });
      return s;
    }

    /* ── Prodotto: ricerca via QkM2O (componente condiviso, vedi audit DRY/UI
       27/07/2026 — prima qui c'era una ricerca scritta a mano con classi
       .qkwh-dd-* proprie, mai riusate altrove). L'indicatore "traccia lotti"
       che compariva come icona nel menu di ricerca non ha un equivalente
       diretto nel rendering standard di QkM2O (due colonne testo, niente
       badge): resta comunque visibile subito dopo la selezione, nel box
       informativo del prodotto (infoLottiRow/infoLottiOffRow più sotto). */

    new QkM2O({
      inputEl:    prodSearch,
      hiddenEl:   prodIdInput,
      dropdownEl: prodDropdown,
      apiUrl:     cfg.apiProdottiSearch,
      labelKey:   'nome',
      columns:    [{ key: 'nome', label: 'Prodotto' }, { key: 'sku_interno', label: 'SKU' }],
      onSelect:   (id, row) => { selectProdotto(row); },
    });

    async function selectProdotto(p) {
      prodSearch.value  = p.sku_interno + ' — ' + p.nome;
      prodIdInput.value = p.id;
      await loadProdottoInfo(p.id);
    }

    async function loadProdottoInfo(id) {
      try {
        const magazzinoId = selectMagazzino ? selectMagazzino.value : '';
        const url = cfg.apiProdottoInfo + '?id=' + id + (magazzinoId ? '&magazzino_id=' + magazzinoId : '');
        const res  = await fetch(url, { credentials: 'same-origin' });
        const data = await res.json();
        if (!data.ok || !data.prodotto) return;

        const p = data.prodotto;
        prodottoCorrente = p;
        lottiCorrente    = data.lotti || [];

        // Se il campo ricerca è vuoto (pre-fill da URL), valorizzarlo
        if (!prodSearch.value) {
          prodSearch.value  = p.sku_interno + ' — ' + p.nome;
          prodIdInput.value = String(p.id);
        }

        // Info box
        infoGiacenza.textContent = fmtQty(p.qta_disponibile) + ' ' + p.uom;
        infoUom.textContent      = p.uom;

        // Tracciatura lotti: mostra stato ON o OFF con link modifica se OFF
        infoLottiRow.hidden    = !p.traccia_lotti;
        infoLottiOffRow.hidden = !!p.traccia_lotti;
        if (!p.traccia_lotti && cfg.urlProdottoEdit && linkModProdotto) {
          linkModProdotto.href = cfg.urlProdottoEdit + '?id=' + p.id;
        }

        prodInfo.hidden = false;

        // UoM nel campo quantità
        qtaUomSuffix.textContent = p.uom;

        // Riepilogo
        riepilogoProdotto.textContent    = p.nome;
        riepilogoGiacAttuale.textContent = fmtQty(p.qta_disponibile) + ' ' + p.uom;

        updateLottoUI();
        updateRiepilogoGiacenza();

      } catch (e) {
        console.error('[warehouse-movement] Errore caricamento info prodotto:', e);
      }
    }

    /* ── Cambio magazzino a prodotto già scelto: ricarica giacenza e lotti
     * (Fix 1, §8.2) — stesso pattern già usato in warehouse-transfer.js per
     * selectOrigine che ricarica i lotti al cambio origine. ────────────── */
    if (selectMagazzino) {
      selectMagazzino.addEventListener('change', function () {
        if (prodIdInput.value) loadProdottoInfo(prodIdInput.value);
      });
    }

    /* ── Pre-carica prodotto se URL contiene prodotto_id ─────────────────── */

    if (cfg.prefillProdottoId > 0) {
      loadProdottoInfo(cfg.prefillProdottoId);
    }

    /* ── Init UI tipo ────────────────────────────────────────────────────── */
    updateTipoUI();

  }); // DOMContentLoaded
})();
