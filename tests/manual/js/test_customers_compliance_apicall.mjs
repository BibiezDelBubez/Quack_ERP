/**
 * tests/manual/js/test_customers_compliance_apicall.mjs
 *
 * Test comportamentale della migrazione a apiCall()/qkApiFetch() di
 * assets/js/customers-compliance.js (PIANO Punti 1-2-3 Basso Rischio, Punto
 * 3). Verifica, con codice reale (eval del sorgente, non riscritto) ed una
 * fetch mockata, che i 5 punti di chiamata continuino a produrre ESATTAMENTE
 * gli stessi messaggi d'errore di prima della migrazione:
 *
 *   1. fetchComplianceData()       — GET iniziale + al refresh dopo ogni azione
 *   2. .btn-init-auth  click       — "Errore: "/"Errore di rete: "
 *   3. .file-upload-input change   — "Errore caricamento: "/"Errore: "
 *   4. .chk-verify-doc change      — "Errore: "/"Errore: "
 *   5. .btn-approve click          — "Impossibile aggiornare: "/"Errore: "
 *
 * Struttura in 4 passi come da piano:
 *   A. apiCall() isolato
 *   B. fetchComplianceData() isolato
 *   C. i 4 handler DOM via jsdom reale (eventi reali, non chiamate dirette)
 *   D. test di sequenza (azione riuscita → richiama fetchComplianceData → re-render)
 *
 * Esecuzione: node tests/manual/js/test_customers_compliance_apicall.mjs
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

function extractFunctionSource(content, marker) {
  const start = content.indexOf(marker);
  if (start === -1) throw new Error(`Marker "${marker}" non trovato — il file è cambiato?`);
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
  if (end === -1) throw new Error(`Fine funzione non trovata per "${marker}"`);
  if (content[end] === ';') end++;
  return content.slice(start, end);
}

function qkApiFetchSource() {
  const content = fs.readFileSync(path.join(REPO_ROOT, 'assets', 'js', 'app.js'), 'utf-8');
  return extractFunctionSource(content, 'window.qkApiFetch = async function');
}

const COMPLIANCE_SRC = fs.readFileSync(path.join(REPO_ROOT, 'assets', 'js', 'customers-compliance.js'), 'utf-8');

async function run() {
  // ═══════════ A. apiCall() isolato ═══════════
  {
    global.window = global.window || {};
    (0, eval)(qkApiFetchSource());
    const src = extractFunctionSource(COMPLIANCE_SRC, 'async function apiCall(url, formData) {');
    (0, eval)(src);

    // GET, successo
    global.fetch = async (url) => ({ ok: true, status: 200, headers: { get: () => 'application/json' }, json: async () => ({ ok: true, foo: 'bar' }) });
    let r = await global.apiCall('/test/get');
    check('apiCall(): GET successo → {ok:true, data}', r.ok === true && r.data.foo === 'bar');

    // GET, 404 HTML → networkError
    global.fetch = async () => ({ ok: false, status: 404, headers: { get: () => 'text/html' }, text: async () => '<!DOCTYPE html>404' });
    r = await global.apiCall('/test/get');
    check('apiCall(): 404 HTML → {ok:false, networkError:true, error}', r.ok === false && r.networkError === true && typeof r.error === 'string' && r.error.length > 0);

    // POST con FormData, successo
    let receivedOpts = null;
    global.fetch = async (url, opts) => { receivedOpts = opts; return { ok: true, status: 200, headers: { get: () => 'application/json' }, json: async () => ({ ok: true }) }; };
    const fakeFd = { __isFormData: true };
    r = await global.apiCall('/test/post', fakeFd);
    check('apiCall(): POST con FormData passa method+body corretti', receivedOpts.method === 'POST' && receivedOpts.body === fakeFd);
    check('apiCall(): POST successo → ok:true', r.ok === true);

    // apiCall NON distingue il fallimento business (data.ok:false) da un successo di rete:
    // resta responsabilità del chiamante, per design (vedi commento nel sorgente).
    global.fetch = async () => ({ ok: true, status: 200, headers: { get: () => 'application/json' }, json: async () => ({ ok: false, error: 'motivo di dominio' }) });
    r = await global.apiCall('/test/get');
    check('apiCall(): risposta HTTP 200 con data.ok:false è comunque {ok:true} a livello di rete (la logica business resta al chiamante)', r.ok === true && r.data.ok === false && r.data.error === 'motivo di dominio');
  }

  // ═══════════ B. fetchComplianceData() isolato ═══════════
  {
    global.window = global.window || {};
    (0, eval)(qkApiFetchSource());
    const apiCallSrc = extractFunctionSource(COMPLIANCE_SRC, 'async function apiCall(url, formData) {');
    (0, eval)(apiCallSrc);

    const fetchSrc = extractFunctionSource(COMPLIANCE_SRC, 'async function fetchComplianceData() {');
    let renderCalls = [];
    global.renderCompliance = (list) => { renderCalls.push(list); };
    global.esc = (s) => String(s ?? '');
    global.cfg = { apiGet: '/test/compliance', customerId: 42 };
    global.container = { innerHTML: '' };

    (0, eval)(fetchSrc);

    // Successo
    let requestedUrl = '';
    global.fetch = async (url) => { requestedUrl = url; return { ok: true, status: 200, headers: { get: () => 'application/json' }, json: async () => ({ ok: true, compliance: [{ id: 1 }] }) }; };
    await global.fetchComplianceData();
    check('fetchComplianceData(): URL include customer_id', requestedUrl.includes('customer_id=42'));
    check('fetchComplianceData(): successo chiama renderCompliance(data.compliance)', renderCalls.length === 1 && renderCalls[0][0].id === 1);

    // Fallimento business (data.ok:false) → SENZA prefisso "Errore:" (comportamento originale esatto)
    global.container.innerHTML = '';
    global.fetch = async () => ({ ok: true, status: 200, headers: { get: () => 'application/json' }, json: async () => ({ ok: false, error: 'Cliente non trovato' }) });
    await global.fetchComplianceData();
    check('fetchComplianceData(): fallimento business → messaggio SENZA prefisso "Errore:" (esattamente come il codice originale)', global.container.innerHTML.includes('Cliente non trovato') && !global.container.innerHTML.includes('Errore: Cliente non trovato'));

    // Fallimento di rete/eccezione → CON prefisso "Errore:" (comportamento originale esatto)
    global.container.innerHTML = '';
    global.fetch = async () => ({ ok: false, status: 500, headers: { get: () => 'text/html' }, text: async () => 'boom' });
    await global.fetchComplianceData();
    check('fetchComplianceData(): fallimento di rete → messaggio CON prefisso "Errore:" (esattamente come il codice originale)', global.container.innerHTML.includes('Errore:'));
  }

  // ═══════════ C. I 4 handler DOM via jsdom reale ═══════════
  //
  // Importante: gli elementi .btn-init-auth/.file-upload-input/.chk-verify-doc/
  // .btn-approve ricevono i propri event listener SOLO tramite attachEvents(),
  // chiamata internamente da renderCompliance() — funzione privata di
  // closure, non esposta su window. Iniettare l'HTML a mano (senza passare
  // dal rendering reale) produrrebbe quindi bottoni "morti", senza alcun
  // listener: il test dimostrerebbe solo la struttura DOM, non il comportamento.
  // Per questo il setup esegue prima un caricamento GET reale con dati di
  // compliance realistici, lascia che sia buildCard()/attachEvents() (codice
  // vero, non riscritto) a costruire i bottoni, e solo DOPO individua
  // l'elemento reale via querySelector per simulare il click/change.

  const ITEM_NON_PRESENTE = {
    restriction_type: { id: 7, name: 'ADR', regulatory_ref: null, requires_vat: 0, validity_days: 365 },
    authorization: null,
    documents: [],
  };
  const ITEM_IN_ATTESA = {
    restriction_type: { id: 8, name: 'Sostanze Controllate', regulatory_ref: null, requires_vat: 0, validity_days: 365 },
    authorization: { id: 5, status: 'in_attesa', expiry_date: null, approver_name: null, issued_date: null, internal_notes: '' },
    documents: [
      { id: 2, document_name: 'Certificato', mandatory: 1, instructions: null, file: null },
      { id: 3, document_name: 'Scheda tecnica', mandatory: 0, instructions: null, file: { id: 9, file_path: 'x/y.pdf', original_filename: 'scheda.pdf', verified: 0, verifier_name: null } },
    ],
  };

  // Nota tecnica: window.eval() di jsdom, SENZA l'opzione runScripts:
  // 'dangerously', non esegue il codice nel vero realm della finestra (un
  // controllo diretto ha mostrato "typeof window" === "undefined" al suo
  // interno). Per restare coerenti con la tecnica già usata e verificata nel
  // resto della suite (eval indiretto nello scope globale di Node, con
  // global.window/global.document/global.fetch/global.alert/global.FormData
  // che puntano ai veri oggetti jsdom) si usa qui lo stesso approccio: il
  // codice del file, che referenzia `document`, `alert`, `fetch`, `FormData`
  // come identificatori bare (non `window.alert` ecc.), li risolve tramite
  // lo scope globale di Node — dove queste variabili sono state impostate.
  async function setupDomWithCard(complianceItem) {
    const dom = new JSDOM(`<!DOCTYPE html><html><body>
      <div id="compliance-status-list"></div>
    </body></html>`, { url: 'http://localhost/' });
    const { window } = dom;
    window.QkCustomerCompliance = { apiGet: '/api/get', apiSave: '/api/save', apiUpload: '/api/upload', apiVerify: '/api/verify', apiApprove: '/api/approve', customerId: 1, csrfToken: 'tok', userRole: 'admin' };
    global.window = window;
    global.document = window.document;
    global.FormData = window.FormData;
    (0, eval)(qkApiFetchSource());

    // Fetch iniziale (durante DOMContentLoaded): restituisce la card di test.
    global.fetch = async (url) => {
      if (String(url).includes('/api/get')) {
        return { ok: true, status: 200, headers: { get: () => 'application/json' }, json: async () => ({ ok: true, compliance: [complianceItem] }) };
      }
      throw new Error('fetch inatteso durante il setup: ' + url);
    };

    // Eval dell'intero file nello scope globale (IIFE con DOMContentLoaded reale)
    (0, eval)(COMPLIANCE_SRC);
    global.document.dispatchEvent(new window.Event('DOMContentLoaded', { bubbles: true, cancelable: true }));
    await new Promise((r) => setTimeout(r, 20));

    return { window, document: global.document, container: global.document.getElementById('compliance-status-list') };
  }

  /** Dopo il setup imposta il mock fetch per il test vero e proprio: la
   *  chiamata all'endpoint sotto test usa `actionResponse`, un eventuale
   *  refresh GET successivo (chiamato da fetchComplianceData()) risponde
   *  con una lista vuota per non complicare l'asserzione. */
  function mockActionFetch(window, endpointFragment, actionResponse) {
    let getCallCount = 0;
    global.fetch = async (url) => {
      const u = String(url);
      if (u.includes(endpointFragment)) {
        return { ok: true, status: 200, headers: { get: () => 'application/json' }, json: async () => actionResponse };
      }
      if (u.includes('/api/get')) {
        getCallCount++;
        return { ok: true, status: 200, headers: { get: () => 'application/json' }, json: async () => ({ ok: true, compliance: [] }) };
      }
      throw new Error('fetch inatteso: ' + u);
    };
    return { getCallCount: () => getCallCount };
  }

  function mockNetworkFailureFetch(window) {
    global.fetch = async () => ({ ok: false, status: 404, headers: { get: () => 'text/html' }, text: async () => '<!DOCTYPE html>404' });
  }

  // C.1 — .btn-init-auth: "Errore: " (business) / "Errore di rete: " (rete)
  {
    const { window, container } = await setupDomWithCard(ITEM_NON_PRESENTE);
    const btn = container.querySelector('.btn-init-auth');
    check('.btn-init-auth: il pulsante reale esiste dopo il render (auth assente → needsRenew)', !!btn);
    let alertMsg = null;
    global.alert = (m) => { alertMsg = m; };
    mockActionFetch(window, '/api/save', { ok: false, error: 'tipo già attivo' });
    btn.dispatchEvent(new window.Event('click', { bubbles: true }));
    await new Promise((r) => setTimeout(r, 20));
    check('.btn-init-auth: fallimento business → alert "Errore: tipo già attivo" (prefisso esatto originale)', alertMsg === 'Errore: tipo già attivo');
    check('.btn-init-auth: fallimento business → btn.disabled riportato a false', btn.disabled === false);
  }
  {
    const { window, container } = await setupDomWithCard(ITEM_NON_PRESENTE);
    const btn = container.querySelector('.btn-init-auth');
    let alertMsg = null;
    global.alert = (m) => { alertMsg = m; };
    mockNetworkFailureFetch(window);
    btn.dispatchEvent(new window.Event('click', { bubbles: true }));
    await new Promise((r) => setTimeout(r, 20));
    check('.btn-init-auth: fallimento di rete → alert con prefisso "Errore di rete: " (esatto originale)', typeof alertMsg === 'string' && alertMsg.startsWith('Errore di rete: '));
  }

  // C.2 — .file-upload-input: "Errore caricamento: " (business) / "Errore: " (rete)
  {
    const { window, container } = await setupDomWithCard(ITEM_IN_ATTESA);
    const input = container.querySelector('.file-upload-input'); // doc senza file → input "Carica"
    check('.file-upload-input: l\'input reale esiste dopo il render (documento senza file)', !!input);
    Object.defineProperty(input, 'files', { value: [{ name: 'test.pdf' }], configurable: true });
    let alertMsg = null;
    global.alert = (m) => { alertMsg = m; };
    mockActionFetch(window, '/api/upload', { ok: false, error: 'formato non valido' });
    input.dispatchEvent(new window.Event('change', { bubbles: true }));
    await new Promise((r) => setTimeout(r, 20));
    check('.file-upload-input: fallimento business → alert "Errore caricamento: formato non valido" (prefisso esatto originale)', alertMsg === 'Errore caricamento: formato non valido');
  }
  {
    const { window, container } = await setupDomWithCard(ITEM_IN_ATTESA);
    const input = container.querySelector('.file-upload-input');
    Object.defineProperty(input, 'files', { value: [{ name: 'test.pdf' }], configurable: true });
    let alertMsg = null;
    global.alert = (m) => { alertMsg = m; };
    mockNetworkFailureFetch(window);
    input.dispatchEvent(new window.Event('change', { bubbles: true }));
    await new Promise((r) => setTimeout(r, 20));
    check('.file-upload-input: fallimento di rete → alert con prefisso "Errore: " (esatto originale, NON "Errore caricamento: ")', typeof alertMsg === 'string' && alertMsg.startsWith('Errore: ') && !alertMsg.startsWith('Errore caricamento: '));
  }

  // C.3 — .chk-verify-doc: "Errore: " sia business che rete, ma checked ripristinato
  {
    const { window, container } = await setupDomWithCard(ITEM_IN_ATTESA);
    const chk = container.querySelector('.chk-verify-doc'); // doc con file → checkbox verifica
    check('.chk-verify-doc: la checkbox reale esiste dopo il render (documento con file)', !!chk);
    chk.checked = true;
    let alertMsg = null;
    global.alert = (m) => { alertMsg = m; };
    mockActionFetch(window, '/api/verify', { ok: false, error: 'permesso negato' });
    chk.dispatchEvent(new window.Event('change', { bubbles: true }));
    await new Promise((r) => setTimeout(r, 20));
    check('.chk-verify-doc: fallimento business → alert "Errore: permesso negato"', alertMsg === 'Errore: permesso negato');
    check('.chk-verify-doc: fallimento business → checkbox ripristinata (checked invertito di nuovo)', chk.checked === false);
  }

  // C.4 — .btn-approve: "Impossibile aggiornare: " (business) / "Errore: " (rete)
  {
    const { window, container } = await setupDomWithCard(ITEM_IN_ATTESA);
    // status in_attesa + userRole admin → due bottoni .btn-approve (Rifiuta/Approva); prendiamo "Approva"
    const btn = container.querySelector('.btn-approve[data-status="approvata"]');
    check('.btn-approve: il pulsante "Approva" reale esiste dopo il render (auth in_attesa, utente admin)', !!btn);
    let alertMsg = null;
    global.alert = (m) => { alertMsg = m; };
    mockActionFetch(window, '/api/approve', { ok: false, error: 'stato non valido' });
    btn.dispatchEvent(new window.Event('click', { bubbles: true }));
    await new Promise((r) => setTimeout(r, 20));
    check('.btn-approve: fallimento business → alert "Impossibile aggiornare: stato non valido" (prefisso esatto originale)', alertMsg === 'Impossibile aggiornare: stato non valido');
  }
  {
    const { window, container } = await setupDomWithCard(ITEM_IN_ATTESA);
    const btn = container.querySelector('.btn-approve[data-status="approvata"]');
    let alertMsg = null;
    global.alert = (m) => { alertMsg = m; };
    mockNetworkFailureFetch(window);
    btn.dispatchEvent(new window.Event('click', { bubbles: true }));
    await new Promise((r) => setTimeout(r, 20));
    check('.btn-approve: fallimento di rete → alert con prefisso "Errore: " (NON "Impossibile aggiornare: ")', typeof alertMsg === 'string' && alertMsg.startsWith('Errore: ') && !alertMsg.startsWith('Impossibile aggiornare: '));
  }

  // ═══════════ D. Test di sequenza: azione riuscita → refetch → re-render ═══════════
  {
    const { window, container } = await setupDomWithCard(ITEM_IN_ATTESA);
    const btn = container.querySelector('.btn-approve[data-status="approvata"]');
    const tracker = mockActionFetch(window, '/api/approve', { ok: true });
    btn.dispatchEvent(new window.Event('click', { bubbles: true }));
    await new Promise((r) => setTimeout(r, 30));
    check('Sequenza: azione .btn-approve riuscita richiama fetchComplianceData() esattamente una volta (refresh automatico preservato)', tracker.getCallCount() === 1);
    check('Sequenza: dopo il refresh il container mostra lo stato "nessun regime configurato" (re-render reale avvenuto, non solo la richiesta)', container.innerHTML.includes('Nessun regime'));
  }

  console.log('\n' + (failed === 0 ? `TUTTI I TEST OK (${total}/${total})` : `${failed}/${total} TEST FALLITI`));
  process.exit(failed === 0 ? 0 : 1);
}

run().catch(e => { console.error('ERRORE FATALE NEL TEST:', e); process.exit(1); });
