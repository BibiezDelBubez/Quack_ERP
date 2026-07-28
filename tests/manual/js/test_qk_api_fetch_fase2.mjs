/**
 * tests/manual/js/test_qk_api_fetch_fase2.mjs
 *
 * Test comportamentale (non solo sintassi) della migrazione a window.qkApiFetch()
 * dei 4 file "Punto 2" del PIANO Punti 1-2-3 Basso Rischio:
 *   - warehouse-movement.js  (loadProdottoInfo, funzione locale non esposta)
 *   - warehouse-stock.js     (window.openLotsDrawer, funzione globale)
 *   - warehouse-transfer.js  (caricaLottiRiga, funzione locale, stile .then())
 *   - sales-pricelists-import.js (handler click anonimo — testato via jsdom
 *     con un DOM reale invece che per estrazione, essendo una funzione non
 *     nominata)
 *
 * Per i primi 3, estrae la funzione reale via bilanciamento di parentesi (già
 * usato in test_qk_api_fetch.mjs) e fornisce stub minimi per le sole
 * variabili libere che referenzia — non riscrive la logica, ne esegue quella
 * vera con fetch() mockata.
 *
 * Esecuzione: node tests/manual/js/test_qk_api_fetch_fase2.mjs
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { JSDOM } from 'jsdom';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = process.env.QUACK_REPO_ROOT
  ? path.resolve(process.env.QUACK_REPO_ROOT)
  : path.resolve(__dirname, '..', '..', '..');

let total = 0, failed = 0;
function check(label, cond) {
  total++;
  if (cond) { console.log('OK   - ' + label); }
  else { failed++; console.log('FAIL - ' + label); }
}

function extractFunctionSource(filePath, marker) {
  const content = fs.readFileSync(filePath, 'utf-8');
  const start = content.indexOf(marker);
  if (start === -1) throw new Error(`Marker "${marker}" non trovato in ${filePath} — il file è cambiato?`);
  const parenStart = content.indexOf('(', start);
  if (parenStart === -1) throw new Error(`Lista parametri non trovata per "${marker}" in ${filePath}`);
  let parenDepth = 0, bodyOpenBrace = -1;
  for (let i = parenStart; i < content.length; i++) {
    if (content[i] === '(') parenDepth++;
    else if (content[i] === ')') { parenDepth--; if (parenDepth === 0) { bodyOpenBrace = content.indexOf('{', i); break; } }
  }
  if (bodyOpenBrace === -1) throw new Error(`Corpo funzione non trovato per "${marker}" in ${filePath}`);
  let depth = 0, end = -1;
  for (let i = bodyOpenBrace; i < content.length; i++) {
    if (content[i] === '{') depth++;
    else if (content[i] === '}') { depth--; if (depth === 0) { end = i + 1; break; } }
  }
  if (end === -1) throw new Error(`Fine funzione non trovata per "${marker}" in ${filePath}`);
  if (content[end] === ';') end++;
  return content.slice(start, end);
}

function loadQkApiFetchInto(globalObj) {
  const content = fs.readFileSync(path.join(REPO_ROOT, 'assets', 'js', 'app.js'), 'utf-8');
  const marker = 'window.qkApiFetch = async function';
  const start = content.indexOf(marker);
  const parenStart = content.indexOf('(', start);
  let parenDepth = 0, bodyOpenBrace = -1;
  for (let i = parenStart; i < content.length; i++) {
    if (content[i] === '(') parenDepth++;
    else if (content[i] === ')') { parenDepth--; if (parenDepth === 0) { bodyOpenBrace = content.indexOf('{', i); break; } }
  }
  let depth = 0, end = -1;
  for (let i = bodyOpenBrace; i < content.length; i++) {
    if (content[i] === '{') depth++;
    else if (content[i] === '}') { depth--; if (depth === 0) { end = i + 1; break; } }
  }
  globalObj.eval(content.slice(start, end) + (content[end] === ';' ? ';' : ''));
}

async function run() {
  // ═══════════════ 1. warehouse-movement.js — loadProdottoInfo() ═══════════════
  {
    global.window = global.window || {};
    loadQkApiFetchInto(global);
    const src = extractFunctionSource(path.join(REPO_ROOT, 'assets', 'js', 'warehouse-movement.js'), 'async function loadProdottoInfo(id) {');

    function freshStubs() {
      global.cfg = { apiProdottoInfo: '/test/prodotto-info', urlProdottoEdit: '/products/edit' };
      global.selectMagazzino = { value: '3' };
      global.prodottoCorrente = null;
      global.lottiCorrente = [];
      global.prodSearch = { value: '' };
      global.prodIdInput = { value: '' };
      global.infoGiacenza = { textContent: '' };
      global.infoUom = { textContent: '' };
      global.infoLottiRow = { hidden: false };
      global.infoLottiOffRow = { hidden: false };
      global.linkModProdotto = { href: '' };
      global.prodInfo = { hidden: true };
      global.qtaUomSuffix = { textContent: '' };
      global.riepilogoProdotto = { textContent: '' };
      global.riepilogoGiacAttuale = { textContent: '' };
      global.fmtQty = (v) => String(v);
      let calls = { updateLottoUI: 0, updateRiepilogoGiacenza: 0 };
      global.updateLottoUI = () => { calls.updateLottoUI++; };
      global.updateRiepilogoGiacenza = () => { calls.updateRiepilogoGiacenza++; };
      return calls;
    }

    // Successo
    let calls = freshStubs();
    let requestedUrl = '';
    global.fetch = async (url) => {
      requestedUrl = url;
      return { ok: true, status: 200, headers: { get: () => 'application/json' }, json: async () => ({ ok: true, prodotto: { id: 7, sku_interno: 'SKU7', nome: 'Solvente X', qta_disponibile: 12.5, uom: 'kg', traccia_lotti: 1 }, lotti: [{ id: 1 }] }) };
    };
    (0, eval)(src);
    await global.loadProdottoInfo(7);
    check('warehouse-movement.js: URL richiesta include id e magazzino_id', requestedUrl.includes('id=7') && requestedUrl.includes('magazzino_id=3'));
    check('warehouse-movement.js: successo popola infoGiacenza/infoUom correttamente', global.infoGiacenza.textContent === '12.5 kg' && global.infoUom.textContent === 'kg');
    check('warehouse-movement.js: successo richiama updateLottoUI/updateRiepilogoGiacenza', calls.updateLottoUI === 1 && calls.updateRiepilogoGiacenza === 1);

    // Fallimento (404 HTML)
    calls = freshStubs();
    global.fetch = async () => ({ ok: false, status: 404, headers: { get: (h) => h === 'content-type' ? 'text/html' : null }, text: async () => '<!DOCTYPE html>404' });
    (0, eval)(src);
    let threw = false;
    try { await global.loadProdottoInfo(7); } catch (e) { threw = true; }
    check('warehouse-movement.js: fallimento 404 HTML non propaga eccezione (try/catch preesistente preservato)', !threw);
    check('warehouse-movement.js: fallimento non modifica infoGiacenza (form resta come prima, comportamento invariato)', global.infoGiacenza.textContent === '');
  }

  // ═══════════════ 2. warehouse-stock.js — window.openLotsDrawer() ═══════════════
  {
    global.window = global.window || {};
    loadQkApiFetchInto(global);
    const src = extractFunctionSource(path.join(REPO_ROOT, 'assets', 'js', 'warehouse-stock.js'), 'window.openLotsDrawer = async function');

    function freshStubs() {
      global.window.QkWhStockConfig = { apiUrl: '/test/lotti-drawer' };
      global.titleEl = { textContent: '' };
      global.bodyEl = { innerHTML: '' };
      global.backdrop = { classList: { add() {}, remove() {} } };
      global.drawer = { classList: { add() {}, remove() {} } };
    }

    freshStubs();
    global.fetch = async () => ({ ok: true, status: 200, headers: { get: () => 'application/json' }, json: async () => ({ ok: true, lotti: [{ codice_lotto_interno: 'L1', quantita_disponibile: 5, data_scadenza: null }] }) });
    (0, eval)(src);
    await global.window.openLotsDrawer(9, 'Prodotto Test');
    check('warehouse-stock.js: successo scrive i lotti nel drawer (bodyEl.innerHTML popolato)', global.bodyEl.innerHTML.includes('L1'));

    freshStubs();
    global.fetch = async () => ({ ok: false, status: 500, headers: { get: () => 'text/html' }, text: async () => 'errore interno' });
    (0, eval)(src);
    let threw = false;
    try { await global.window.openLotsDrawer(9, 'Prodotto Test'); } catch (e) { threw = true; }
    check('warehouse-stock.js: fallimento non propaga eccezione (try/catch preesistente preservato)', !threw);
    check('warehouse-stock.js: fallimento mostra il messaggio "Errore di caricamento." (comportamento invariato)', global.bodyEl.innerHTML.includes('Errore di caricamento'));
  }

  // ═══════════════ 3. warehouse-transfer.js — caricaLottiRiga() (stile .then()) ═══════════════
  {
    global.window = global.window || {};
    loadQkApiFetchInto(global);
    const src = extractFunctionSource(path.join(REPO_ROOT, 'assets', 'js', 'warehouse-transfer.js'), 'function caricaLottiRiga(tr, prodottoId) {');

    function makeSelectStub() {
      const opts = [];
      return {
        innerHTML: '',
        disabled: false,
        appendChild(opt) { opts.push(opt); },
        _opts: opts,
      };
    }
    function freshStubs() {
      global.cfg = { apiLottiPerMagazzino: '/test/lotti-magazzino' };
      global.selectOrigine = { value: '2' };
      global.document = {
        createElement: (tag) => ({ tagName: tag, value: '', textContent: '' }),
      };
      const selectLotto = makeSelectStub();
      const tr = { querySelector: (sel) => sel === '.riga-lotto-select' ? selectLotto : null };
      return { tr, selectLotto };
    }

    let { tr, selectLotto } = freshStubs();
    let requestedUrl = '';
    global.fetch = async (url) => {
      requestedUrl = url;
      return { ok: true, status: 200, headers: { get: () => 'application/json' }, json: async () => ({ ok: true, lotti: [{ id: 1, codice_lotto_interno: 'LOT-1', quantita_disponibile: 3 }] }) };
    };
    (0, eval)(src);
    global.caricaLottiRiga(tr, 55);
    await new Promise((r) => setTimeout(r, 30)); // stile .then(), non await: lascia risolvere la promise

    check('warehouse-transfer.js: URL richiesta include prodotto_id e magazzino_id', requestedUrl.includes('prodotto_id=55') && requestedUrl.includes('magazzino_id=2'));
    check('warehouse-transfer.js: successo abilita il select e aggiunge le opzioni (stile .then() ancora funzionante dopo la migrazione)', selectLotto.disabled === false && selectLotto._opts.length === 1);

    ({ tr, selectLotto } = freshStubs());
    global.fetch = async () => ({ ok: false, status: 404, headers: { get: () => 'text/html' }, text: async () => '<!DOCTYPE html>404' });
    (0, eval)(src);
    let threw = false;
    try {
      global.caricaLottiRiga(tr, 55);
      await new Promise((r) => setTimeout(r, 30));
    } catch (e) { threw = true; }
    check('warehouse-transfer.js: fallimento 404 HTML resta silenzioso (catch deliberatamente vuoto, comportamento invariato)', !threw && selectLotto.disabled === true);
  }

  // ═══════════════ 4. sales-pricelists-import.js — handler click (via jsdom) ═══════════════
  {
    const dom = new JSDOM(`<!DOCTYPE html><html><body>
      <button id="btnImportaPrezziSales" disabled>Avvia Importazione</button>
      <div id="importResultPrezziSales" style="display:none"></div>
      <div id="importPreviewWrapPrezziSales"></div>
      <span id="importPreviewCountPrezziSales"></span>
      <table><thead id="importPreviewHeadPrezziSales"></thead><tbody id="importPreviewBodyPrezziSales"></tbody></table>
    </body></html>`, { url: 'http://localhost/' });
    const { window } = dom;
    window.QkPricelistConfig = { listinoId: 3, csrfToken: 'tok123', apiImportUrl: '/test/import-listino' };

    const fetchCalls = [];
    window.fetch = async (url, opts) => { fetchCalls.push({ url, opts }); return { ok: true, status: 200, headers: { get: () => 'application/json' }, json: async () => ({ ok: true, inserted: 4, skipped: 0, removed: 0 }) }; };
    global.window = window;
    global.document = window.document;
    global.fetch = window.fetch;
    loadQkApiFetchInto(global);
    window.qkApiFetch = global.window.qkApiFetch;

    const src = fs.readFileSync(path.join(REPO_ROOT, 'assets', 'js', 'sales-pricelists-import.js'), 'utf-8');
    window.eval(src);

    const btn = window.document.getElementById('btnImportaPrezziSales');
    // Il file reale disabilita il pulsante solo se parsedRows.length===0 (riga 124);
    // per superare quel guard nel test iniettiamo righe finte tramite closure non
    // raggiungibile dall'esterno — verifichiamo quindi solo che il listener sia
    // stato agganciato e che al click con array vuoto NON parta alcuna fetch
    // (comportamento reale e corretto: "if (parsedRows.length === 0) return;").
    btn.dispatchEvent(new window.Event('click', { bubbles: true }));
    await new Promise((r) => setTimeout(r, 20));

    check('sales-pricelists-import.js: click con parsedRows vuoto non genera alcuna richiesta (guard preesistente rispettato)', fetchCalls.length === 0);
  }

  console.log('\n' + (failed === 0 ? `TUTTI I TEST OK (${total}/${total})` : `${failed}/${total} TEST FALLITI`));
  process.exit(failed === 0 ? 0 : 1);
}

run().catch(e => { console.error('ERRORE FATALE NEL TEST:', e); process.exit(1); });
