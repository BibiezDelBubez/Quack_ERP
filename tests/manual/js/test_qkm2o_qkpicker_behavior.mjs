/**
 * tests/manual/js/test_qkm2o_qkpicker_behavior.mjs
 *
 * Test comportamentale (non solo sintassi) di QkM2O/QkPicker — il componente
 * condiviso su cui è stato migrato il Gruppo B (vedi docs/plans/PIANO -
 * Unificazione Autocomplete (qkAutocomplete verso QkM2O).md).
 *
 * Carica il vero codice sorgente di QkM2O/QkPicker estratto da
 * includes/entity_picker_modal.php (non una riscrittura/mock del componente)
 * dentro un DOM jsdom, monta un markup minimo identico a quello usato dal
 * campo "categoria genitore" (il caso più semplice migrato, B.2), mocka
 * fetch() con risposte fedeli alla forma reale dell'API, e simula le
 * interazioni utente reali: focus, digitazione, click su un risultato, click
 * su "cancella" — verificando lo stato del DOM e i callback invocati, non
 * solo l'assenza di eccezioni.
 *
 * Esecuzione: node tests/manual/js/test_qkm2o_qkpicker_behavior.mjs
 * (richiede jsdom: npm install jsdom nella stessa cartella, o globalmente)
 */

import { JSDOM } from 'jsdom';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
// QUACK_REPO_ROOT permette di eseguire questo script da fuori la struttura
// tests/manual/js/ (es. da una cartella scratch con node_modules propri),
// mantenendo la lettura del sorgente reale del componente dal repo vero.
const REPO_ROOT = process.env.QUACK_REPO_ROOT
  ? path.resolve(process.env.QUACK_REPO_ROOT)
  : path.resolve(__dirname, '..', '..', '..');

let total = 0, failed = 0;
function check(label, cond) {
  total++;
  if (cond) {
    console.log('OK   - ' + label);
  } else {
    failed++;
    console.log('FAIL - ' + label);
  }
}

// ── Legge il vero sorgente JS di QkM2O/QkPicker ──
// (stesso file usato in produzione, nessuna riscrittura: se il componente
// condiviso cambia, questo test lo scopre automaticamente perché carica il
// codice reale, non una copia).
//
// Aggiornamento 08/08/2026 (Fase 3, audit/piano-correzione-bug-qkpicker-
// persistenza-selezione.md §8): il JS non è più inline dentro
// includes/entity_picker_modal.php (che ora include solo un
// <script src="...">) — è stato spostato in assets/js/components/
// entity-picker.js. Legge direttamente quel file invece di fare regex-
// extraction su un <script> che non esiste più.
function extractQkM2OSource() {
  const jsPath = path.join(REPO_ROOT, 'assets', 'js', 'components', 'entity-picker.js');
  if (!fs.existsSync(jsPath)) {
    throw new Error('assets/js/components/entity-picker.js non trovato — il componente è stato spostato altrove?');
  }
  return fs.readFileSync(jsPath, 'utf-8');
}

async function run() {
  const dom = new JSDOM(`<!DOCTYPE html><html><body>
    <div class="modal fade" id="qk-picker-modal">
      <div class="modal-dialog"><div class="modal-content">
        <h5 class="modal-title" id="qk-picker-title"></h5>
        <input type="search" id="qk-picker-search">
        <div id="qk-picker-results"></div>
        <div id="qk-picker-pager" class="d-none">
          <span id="qk-picker-count"></span>
          <button id="qk-picker-prev"></button>
          <button id="qk-picker-next"></button>
        </div>
        <button id="qk-picker-btn-nuovo" class="d-none"></button>
      </div></div>
    </div>

    <input type="hidden" id="cat-parent-id" value="">
    <div class="qk-m2o-wrap">
      <input type="text" id="cat-parent-label" class="qk-form-control qk-m2o-input" autocomplete="off">
      <button type="button" class="qk-m2o-clear d-none" id="cat-parent-clear">&times;</button>
      <div class="qk-m2o-dropdown" id="cat-parent-dropdown"></div>
    </div>

    <!-- Secondo campo, dedicato ai test del fix strutturale (audit/piano-
         correzione-bug-qkpicker-persistenza-selezione.md, §10.1 J1-J4): stesso
         markup minimo del campo cliente reale (sales-quotes.js), separato dal
         campo categoria sopra per non interferire con gli assert già eseguiti
         su quello. -->
    <input type="hidden" id="cli-test-id" value="">
    <div class="qk-m2o-wrap">
      <input type="text" id="cli-test-label" class="qk-form-control qk-m2o-input" autocomplete="off">
      <button type="button" class="qk-m2o-clear d-none" id="cli-test-clear">&times;</button>
      <div class="qk-m2o-dropdown" id="cli-test-dropdown"></div>
    </div>
  </body></html>`, { url: 'http://localhost/products/categories' });

  const { window } = dom;
  global.window = window;
  global.document = window.document;

  // Stub minimo di bootstrap.Modal (usato solo da QkPicker.open(), non
  // esercitato dai test su QkM2O da solo).
  const bootstrapStub = {
    Modal: { getOrCreateInstance: () => ({ show() {}, hide() {} }) },
  };
  window.bootstrap = bootstrapStub;
  // Stesso motivo del fetch globale sopra: il riferimento non qualificato
  // `bootstrap.Modal...` dentro il codice eval-ato risolve sulla realm di
  // Node, non su window, quindi va esposto anche qui.
  global.bootstrap = bootstrapStub;

  // Mock di fetch: risponde in base all'URL richiesto, replicando la forma
  // reale di /products/api/categorie (bare array [{id,nome}]).
  const fetchCalls = [];
  const mockFetch = async (url) => {
    fetchCalls.push(url);
    const u = new URL(url, 'http://localhost');
    const q = u.searchParams.get('q') || '';
    const excludeId = u.searchParams.get('exclude_id');
    let rows = [
      { id: 1, nome: 'Solventi' },
      { id: 2, nome: 'Acidi' },
      { id: 3, nome: 'Basi' },
    ];
    if (q) rows = rows.filter(r => r.nome.toLowerCase().includes(q.toLowerCase()));
    if (excludeId) rows = rows.filter(r => String(r.id) !== String(excludeId));
    return {
      json: async () => rows,
    };
  };
  window.fetch = mockFetch;
  // jsdom's window.eval() non crea una realm JS separata a tutti gli effetti:
  // un identificatore non qualificato come `fetch()` dentro il codice
  // eval-ato risolve sul `fetch` nativo di Node (undici), non su
  // `window.fetch`, perché Node 22+ espone `fetch` come globale del
  // process. Il sorgente reale di QkM2O/QkPicker chiama `fetch(...)` senza
  // prefisso `window.`, quindi va sovrascritto anche il fetch globale di
  // Node per la durata del test, altrimenti la mock non viene mai invocata
  // e la richiesta reale fallisce con "Invalid URL" (URL relativo "/...").
  global.fetch = mockFetch;

  // Carica il vero sorgente di QkM2O/QkPicker nel contesto jsdom.
  const src = extractQkM2OSource();
  window.eval(src);

  check('QkM2O caricato correttamente nel contesto jsdom', typeof window.QkM2O === 'function');
  check('QkPicker caricato correttamente nel contesto jsdom', typeof window.QkPicker === 'function');

  const inputEl    = window.document.getElementById('cat-parent-label');
  const hiddenEl    = window.document.getElementById('cat-parent-id');
  const dropdownEl = window.document.getElementById('cat-parent-dropdown');
  const clearBtn   = window.document.getElementById('cat-parent-clear');

  let selectedArgs = null;
  let clearCalled  = false;

  // Stessa configurazione usata in assets/js/products-categories.js (B.2),
  // incluso exclude_id statico come query param nell'apiUrl.
  const m2o = new window.QkM2O({
    inputEl, hiddenEl, dropdownEl, clearBtn,
    apiUrl:   '/products/api/categorie?exclude_id=1',
    labelKey: 'nome',
    columns:  [{ key: 'nome', label: 'Nome' }],
    onSelect: (id, row) => { selectedArgs = { id, row }; },
    onClear:  () => { clearCalled = true; },
  });

  check('QkM2O costruito senza eccezioni', m2o instanceof window.QkM2O);

  // ── 1. Focus: deve lanciare una ricerca con q='' e includere exclude_id ──
  inputEl.dispatchEvent(new window.Event('focus'));
  await new Promise(r => setTimeout(r, 50));

  check('focus: fetch chiamata almeno una volta', fetchCalls.length >= 1);
  check('focus: apiUrl statico contiene exclude_id=1 (D4)', fetchCalls[0].includes('exclude_id=1'));
  check('focus: dropdown mostra le opzioni non escluse (Solventi/Acidi/Basi meno la 1 esclusa da apiUrl statico)',
    dropdownEl.querySelectorAll('.qk-m2o-option[data-id]').length === 2);
  check('focus: la categoria con id=1 (esclusa via exclude_id) non compare nel menu',
    !Array.from(dropdownEl.querySelectorAll('.qk-m2o-option[data-id]')).some(el => el.dataset.id === '1'));

  // ── 2. Digitazione: debounce (220ms) poi nuova ricerca filtrata ──
  fetchCalls.length = 0;
  inputEl.value = 'Acid';
  inputEl.dispatchEvent(new window.Event('input'));
  await new Promise(r => setTimeout(r, 350)); // > 220ms di debounce

  check('digitazione "Acid": fetch richiamata dopo il debounce', fetchCalls.length === 1);
  check('digitazione "Acid": la query è passata correttamente nell\'URL', fetchCalls[0].includes('q=Acid'));
  const optionsAfterType = dropdownEl.querySelectorAll('.qk-m2o-option[data-id]');
  check('digitazione "Acid": mostra solo "Acidi"', optionsAfterType.length === 1 && optionsAfterType[0].dataset.label === 'Acidi');

  // ── 3. Click su un risultato: hiddenEl/inputEl aggiornati, onSelect chiamato ──
  optionsAfterType[0].dispatchEvent(new window.Event('click', { bubbles: true }));

  check('click su risultato: hiddenEl.value impostato all\'id selezionato', hiddenEl.value === '2');
  check('click su risultato: inputEl.value impostato alla label selezionata', inputEl.value === 'Acidi');
  check('click su risultato: onSelect chiamato con (id, row) corretti',
    selectedArgs !== null && selectedArgs.id === '2' && selectedArgs.row.nome === 'Acidi');
  check('click su risultato: dropdown chiuso (classe is-open rimossa)', !dropdownEl.classList.contains('is-open'));

  // ── 4. Click su "cancella": onClear chiamato, campi svuotati ──
  clearBtn.dispatchEvent(new window.Event('click', { bubbles: true }));

  check('click su cancella: hiddenEl.value svuotato', hiddenEl.value === '');
  check('click su cancella: inputEl.value svuotato', inputEl.value === '');
  check('click su cancella: onClear chiamato', clearCalled === true);
  check('click su cancella: pulsante clear nascosto di nuovo', clearBtn.classList.contains('d-none'));

  // ══════════════════════════════════════════════════════════════════════
  // QkPicker — verifica indipendente della modalità "Cerca di più…" (D3)
  // ══════════════════════════════════════════════════════════════════════
  let pickerSelected = null;
  const picker = new window.QkPicker({
    title:    'Cerca categoria',
    apiUrl:   '/products/api/categorie',
    columns:  [{ key: 'nome', label: 'Nome' }],
    onSelect: (id, row) => { pickerSelected = { id, row }; },
  });

  check('QkPicker costruito senza eccezioni', picker instanceof window.QkPicker);

  fetchCalls.length = 0;
  picker.open();
  await new Promise(r => setTimeout(r, 50));

  check('QkPicker.open(): carica con q vuoto', fetchCalls.length === 1 && !fetchCalls[0].includes('q='));
  const pickerRows = window.document.querySelectorAll('#qk-picker-results .qk-picker-row');
  check('QkPicker.open(): mostra tutte e 3 le righe (nessun exclude_id qui)', pickerRows.length === 3);

  pickerRows[0].dispatchEvent(new window.Event('click', { bubbles: true }));
  check('QkPicker: click su riga chiama onSelect con (id, row)', pickerSelected !== null && pickerSelected.row.nome !== undefined);

  // J4 (§10.1): QkPicker costruito SENZA essere passato a nessun QkM2O — deve
  // comportarsi esattamente come prima del fix (nessuna persistenza automatica,
  // nessuna eccezione). Prova la retrocompatibilità del fix strutturale.
  check('J4 — QkPicker standalone: this._m2o è undefined (fix strutturale non attivo, come da contratto)',
    picker._m2o === undefined);

  // ══════════════════════════════════════════════════════════════════════
  // FIX STRUTTURALE — audit/piano-correzione-bug-qkpicker-persistenza-selezione.md
  // QkPicker abbinato a QkM2O deve persistere la selezione DA SOLO, anche se
  // l'onSelect esterno (qui deliberatamente vuoto, come l'originale
  // selectCliente() difettoso) non tocca alcun campo. Questo è il test che,
  // se esistesse prima, avrebbe impedito il bug originale di raggiungere
  // produzione (§5 del piano: il vecchio test su QkPicker verificava solo la
  // chiamata a onSelect, mai l'effetto persistente).
  // ══════════════════════════════════════════════════════════════════════
  const cliInputEl    = window.document.getElementById('cli-test-label');
  const cliHiddenEl   = window.document.getElementById('cli-test-id');
  const cliDropdownEl = window.document.getElementById('cli-test-dropdown');
  const cliClearBtn   = window.document.getElementById('cli-test-clear');

  let brokenOnSelectCalled = false;

  const cliPicker = new window.QkPicker({
    title:   'Cerca cliente (test)',
    apiUrl:  '/sales/quotes/api/clienti',
    columns: [{ key: 'nome', label: 'Nome' }],
    // Deliberatamente "rotto" come l'originale selectCliente(): non imposta
    // alcun campo hidden, riproduce esattamente la causa del bug reale.
    onSelect: (id, row) => { brokenOnSelectCalled = true; },
  });

  const cliM2o = new window.QkM2O({
    inputEl:    cliInputEl,
    hiddenEl:   cliHiddenEl,
    dropdownEl: cliDropdownEl,
    clearBtn:   cliClearBtn,
    apiUrl:     '/sales/quotes/api/clienti',
    labelKey:   'nome',
    columns:    [{ key: 'nome', label: 'Nome' }],
    picker:     cliPicker,
    onSelect:   (id, row) => { brokenOnSelectCalled = true; },
  });

  check('J3 — QkM2O con picker abbinato: costruito senza eccezioni', cliM2o instanceof window.QkM2O);
  check('Registrazione automatica: picker._m2o punta alla giusta istanza QkM2O', cliPicker._m2o === cliM2o);

  fetchCalls.length = 0;
  cliPicker.open();
  await new Promise(r => setTimeout(r, 50));

  const cliRows = window.document.querySelectorAll('#qk-picker-results .qk-picker-row');
  check('Setup: il picker cliente mostra le righe mockate (Solventi/Acidi/Basi riusate come dati fittizi)',
    cliRows.length === 3);

  cliRows[1].dispatchEvent(new window.Event('click', { bubbles: true })); // riga "Acidi", id=2

  check('J1 — hiddenEl.value impostato AUTOMATICAMENTE dal componente, pur con onSelect "rotto"',
    cliHiddenEl.value === '2');
  check('J2 — inputEl.value impostato AUTOMATICAMENTE dal componente, pur con onSelect "rotto"',
    cliInputEl.value === 'Acidi');
  check('Il vecchio onSelect "rotto" viene comunque chiamato (nessuna regressione sulla sua invocazione)',
    brokenOnSelectCalled === true);
  check('Dropdown/clear button coerenti: clearBtn reso visibile da setValue() come nel percorso inline',
    !cliClearBtn.classList.contains('d-none'));

  // J5 (non-regressione): il percorso INLINE di QkM2O (digitazione diretta,
  // non "Cerca di più…") deve restare identico a prima del fix — già
  // verificato sopra dai check "click su risultato: ..." (righe ~178-181),
  // che usano lo stesso identico codice (QkM2O._render(), non toccato dal
  // fix). Nessun assert aggiuntivo necessario: se quelli sopra sono OK,
  // J5 è implicitamente confermato.

  // ── Esito ──
  console.log('\n' + (failed === 0 ? `TUTTI I TEST OK (${total}/${total})` : `${failed}/${total} TEST FALLITI`));
  process.exit(failed === 0 ? 0 : 1);
}

run().catch(e => { console.error('ERRORE FATALE NEL TEST:', e); process.exit(1); });
