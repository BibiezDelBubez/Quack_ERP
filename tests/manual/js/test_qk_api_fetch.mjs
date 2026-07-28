/**
 * tests/manual/js/test_qk_api_fetch.mjs
 *
 * Test comportamentale (non solo sintassi) dell'helper condiviso
 * window.qkApiFetch() (assets/js/app.js — PIANO "Helper Condiviso qkApiFetch
 * (DRY chiamate JSON)") e dei 6 file migrati per usarlo nell'ambito ridotto
 * di questa fase: pricing-widget.js (testato a parte in
 * test_pricing_widget_base_url.mjs), i 4 file con postJson() duplicato
 * (customers-addresses.js, customers-contacts.js, suppliers-addresses.js,
 * suppliers-contacts.js) e i 2 file con fetchLotti() duplicato
 * (sales-orders-shipment.js, sales-shipment-new.js).
 *
 * Carica il vero sorgente di ogni funzione (non riscritture) via eval
 * indiretto, con fetch() mockata caso per caso. Struttura:
 *   Parte A — comportamento diretto di qkApiFetch() (successo, content-type
 *             non JSON, 404 HTML — il sintomo originale del bug —, opzione A
 *             sul 500-con-JSON deciso il 27/07/2026, credentials, FormData).
 *   Parte B — regressione sui 6 file migrati: verifica che il comportamento
 *             VISIBILE ai chiamanti (cosa ritorna/lancia in caso di successo
 *             e di fallimento) sia rimasto identico a prima della migrazione,
 *             non solo che il codice non lanci eccezioni inattese.
 *
 * Esecuzione: node tests/manual/js/test_qk_api_fetch.mjs
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
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

// Estrae una funzione `<marker>(...) { ... }` da un file, bilanciando
// parentesi tonde (lista parametri, che può contenere default tipo `= {}`)
// e graffe (corpo), per non fermarsi al primo `}` trovato per sbaglio dentro
// la lista parametri stessa.
function extractFunctionSource(filePath, marker) {
  const content = fs.readFileSync(filePath, 'utf-8');
  const start = content.indexOf(marker);
  if (start === -1) throw new Error(`Marker "${marker}" non trovato in ${filePath} — il file è cambiato?`);
  const parenStart = content.indexOf('(', start);
  if (parenStart === -1) throw new Error(`Lista parametri non trovata per "${marker}" in ${filePath}`);
  let parenDepth = 0, bodyOpenBrace = -1;
  for (let i = parenStart; i < content.length; i++) {
    if (content[i] === '(') parenDepth++;
    else if (content[i] === ')') {
      parenDepth--;
      if (parenDepth === 0) { bodyOpenBrace = content.indexOf('{', i); break; }
    }
  }
  if (bodyOpenBrace === -1) throw new Error(`Corpo funzione non trovato per "${marker}" in ${filePath}`);
  let depth = 0, end = -1;
  for (let i = bodyOpenBrace; i < content.length; i++) {
    if (content[i] === '{') depth++;
    else if (content[i] === '}') {
      depth--;
      if (depth === 0) { end = i + 1; break; }
    }
  }
  if (end === -1) throw new Error(`Fine funzione non trovata per "${marker}" in ${filePath}`);
  return content.slice(start, end);
}

async function run() {
  global.window = global.window || {};
  const appJsPath = path.join(REPO_ROOT, 'assets', 'js', 'app.js');
  (0, eval)(extractFunctionSource(appJsPath, 'window.qkApiFetch = async function'));
  check('qkApiFetch caricata correttamente da app.js', typeof global.window.qkApiFetch === 'function');

  // ═══════════════════════ Parte A: qkApiFetch diretta ═══════════════════════

  // A1: 200 + JSON valido → ritorna il body parsato
  {
    global.fetch = async () => ({
      ok: true, status: 200,
      headers: { get: () => 'application/json' },
      json: async () => ({ hello: 'world' }),
    });
    const data = await global.window.qkApiFetch('/test/ok');
    check('A1: 200 + JSON valido ritorna il body parsato', data && data.hello === 'world');
  }

  // A2: 200 ma content-type non JSON → lancia
  {
    global.fetch = async () => ({
      ok: true, status: 200,
      headers: { get: (h) => (h === 'content-type' ? 'text/plain' : null) },
      text: async () => 'testo semplice non JSON',
    });
    let threw = false, msg = '';
    try { await global.window.qkApiFetch('/test/nonjson'); } catch (e) { threw = true; msg = e.message; }
    check('A2: 200 ma content-type non-JSON: lancia', threw);
    check('A2: il messaggio riporta status e content-type', msg.includes('200') && msg.includes('text/plain'));
  }

  // A3: 404 HTML — il sintomo esatto del bug originale in produzione
  {
    global.fetch = async () => ({
      ok: false, status: 404,
      headers: { get: (h) => (h === 'content-type' ? 'text/html; charset=UTF-8' : null) },
      text: async () => '<!DOCTYPE html><html><body>404 Not Found</body></html>',
    });
    let threw = false, msg = '';
    try { await global.window.qkApiFetch('/test/404'); } catch (e) { threw = true; msg = e.message; }
    check('A3: 404 HTML: lancia (non tenta mai res.json() sull\'HTML)', threw);
    check('A3: il messaggio include URL, status 404 e un\'anteprima del corpo',
      msg.includes('/test/404') && msg.includes('404') && msg.includes('<!DOCTYPE'));
  }

  // A4: Opzione A (decisa 27/07/2026, PIANO qkApiFetch §8) — 500 anche con
  // JSON applicativo valido nel corpo viene comunque trattato come errore.
  {
    global.fetch = async () => ({
      ok: false, status: 500,
      headers: { get: (h) => (h === 'content-type' ? 'application/json' : null) },
      text: async () => '{"ok":false,"error":"Errore interno"}',
    });
    let threw = false;
    try { await global.window.qkApiFetch('/test/500json'); } catch (e) { threw = true; }
    check('A4 (opzione A): 500 con JSON valido nel corpo viene comunque trattato come risposta non valida', threw);
  }

  // A5: credentials di default same-origin, sovrascrivibile esplicitamente
  {
    let receivedOpts = null;
    global.fetch = async (url, opts) => {
      receivedOpts = opts;
      return { ok: true, status: 200, headers: { get: () => 'application/json' }, json: async () => ({}) };
    };
    await global.window.qkApiFetch('/test/cred');
    check('A5a: credentials di default è "same-origin"', receivedOpts.credentials === 'same-origin');
    await global.window.qkApiFetch('/test/cred', { credentials: 'omit' });
    check('A5b: credentials esplicito passato dal chiamante sovrascrive il default', receivedOpts.credentials === 'omit');
  }

  // A6: un body FormData arriva intatto, senza Content-Type forzato che ne romperebbe il boundary
  {
    let receivedOpts = null;
    global.fetch = async (url, opts) => {
      receivedOpts = opts;
      return { ok: true, status: 200, headers: { get: () => 'application/json' }, json: async () => ({}) };
    };
    const fakeFormData = { __isFormData: true };
    await global.window.qkApiFetch('/test/formdata', { method: 'POST', body: fakeFormData });
    check('A6a: un body FormData passato in options arriva intatto a fetch()', receivedOpts.body === fakeFormData);
    check('A6b: qkApiFetch non forza alcun header (lascia il Content-Type/boundary al browser)', !receivedOpts.headers);
  }

  // ═══════════ Parte B: regressione sui 6 file migrati nell'ambito ridotto ═══════════

  const postJsonFiles = ['customers-addresses.js', 'customers-contacts.js', 'suppliers-addresses.js', 'suppliers-contacts.js'];
  for (const file of postJsonFiles) {
    const filePath = path.join(REPO_ROOT, 'assets', 'js', file);
    (0, eval)(extractFunctionSource(filePath, 'async function postJson(url, formData) {'));

    global.fetch = async () => ({
      ok: true, status: 200, headers: { get: () => 'application/json' },
      json: async () => ({ ok: true, foo: 'bar' }),
    });
    const okResult = await global.postJson('/test/save', {});
    check(`B (${file}): postJson() successo ritorna il body parsato, comportamento invariato`,
      okResult && okResult.ok === true && okResult.foo === 'bar');

    global.fetch = async () => ({
      ok: false, status: 404,
      headers: { get: (h) => (h === 'content-type' ? 'text/html' : null) },
      text: async () => '<!DOCTYPE html>404',
    });
    let threw = false, msg = '';
    try { await global.postJson('/test/save', {}); } catch (e) { threw = true; msg = e.message; }
    check(`B (${file}): postJson() su risposta non valida (404 HTML) lancia ancora, come prima della migrazione`, threw);
    check(`B (${file}): il messaggio mostrato all'utente resta il testo generico originale (comportamento visibile invariato)`,
      msg === 'Errore del server. Riprova o controlla la console.');
  }

  {
    const filePath = path.join(REPO_ROOT, 'assets', 'js', 'sales-orders-shipment.js');
    global.cfg = { apiLotti: '/test/lotti' };
    (0, eval)(extractFunctionSource(filePath, 'async function fetchLotti(prodId) {'));

    global.fetch = async () => ({
      ok: true, status: 200, headers: { get: () => 'application/json' },
      json: async () => ({ lotti: [{ id: 1 }, { id: 2 }] }),
    });
    const okLotti = await global.fetchLotti(42);
    check('B (sales-orders-shipment.js): fetchLotti() successo ritorna i lotti', Array.isArray(okLotti) && okLotti.length === 2);

    global.fetch = async () => ({ ok: false, status: 500, headers: { get: () => 'text/html' }, text: async () => 'errore' });
    const failLotti = await global.fetchLotti(42);
    check('B (sales-orders-shipment.js): fetchLotti() su fallimento ritorna ancora [] in silenzio (comportamento invariato)',
      Array.isArray(failLotti) && failLotti.length === 0);
  }

  {
    const filePath = path.join(REPO_ROOT, 'assets', 'js', 'sales-shipment-new.js');
    global.cfg = { apiLotti: '/test/lotti', includiScaduti: true, magazzinoId: 7 };
    (0, eval)(extractFunctionSource(filePath, 'async function fetchLotti(prodId) {'));

    let calledUrl = '';
    global.fetch = async (url) => {
      calledUrl = url;
      return { ok: true, status: 200, headers: { get: () => 'application/json' }, json: async () => ({ lotti: [{ id: 9 }] }) };
    };
    const okLotti = await global.fetchLotti(42);
    check('B (sales-shipment-new.js): fetchLotti() successo ritorna i lotti', Array.isArray(okLotti) && okLotti.length === 1);
    check('B (sales-shipment-new.js): l\'URL include ancora includi_scaduti e magazzino_id (comportamento invariato)',
      calledUrl.includes('includi_scaduti=1') && calledUrl.includes('magazzino_id=7'));

    global.fetch = async () => ({ ok: false, status: 500, headers: { get: () => 'text/html' }, text: async () => 'errore' });
    const failLotti = await global.fetchLotti(42);
    check('B (sales-shipment-new.js): fetchLotti() su fallimento ritorna ancora [] in silenzio (comportamento invariato)',
      Array.isArray(failLotti) && failLotti.length === 0);
  }

  console.log('\n' + (failed === 0 ? `TUTTI I TEST OK (${total}/${total})` : `${failed}/${total} TEST FALLITI`));
  process.exit(failed === 0 ? 0 : 1);
}

run().catch(e => { console.error('ERRORE FATALE NEL TEST:', e); process.exit(1); });
