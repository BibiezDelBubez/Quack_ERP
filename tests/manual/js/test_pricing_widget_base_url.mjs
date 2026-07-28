/**
 * tests/manual/js/test_pricing_widget_base_url.mjs
 *
 * Test comportamentale (non solo sintassi) del fix al bug reale scoperto in
 * produzione il 27/07/2026: qkFetchPrezzoSuggerito() (assets/js/pricing-widget.js)
 * costruiva l'URL leggendo una variabile globale `BASE` mai esistita in tutta
 * l'app (l'unico vero globale è `QK_BASE`), producendo sempre un URL senza il
 * prefisso della sottocartella e quindi un 404 -> crash su res.json().
 *
 * Carica il vero sorgente del file (non una riscrittura) via eval indiretto
 * nello scope globale di Node, mocka fetch(), e verifica:
 *   1. con apiUrl esplicito passato dal chiamante (il path normale ora):
 *      l'URL richiesto è esattamente quello passato, con querystring corretta.
 *   2. senza apiUrl (fallback di sicurezza): l'URL usa QK_BASE, non la
 *      variabile inesistente `BASE` — la vecchia riga rotta non deve più
 *      esistere nel percorso eseguito.
 *   3. risposta 404 HTML (esattamente il sintomo osservato in produzione):
 *      la funzione non tenta res.json() su HTML, ritorna null, non lancia.
 *   4. risposta 200 JSON valida: i dati tornano correttamente al chiamante.
 *
 * REFACTOR 27/07/2026 (PIANO qkApiFetch): pricing-widget.js ora delega la
 * validazione a window.qkApiFetch() (assets/js/app.js) invece di farla in
 * proprio. Questo test carica quindi ANCHE il vero sorgente di qkApiFetch
 * (estratto da app.js per conteggio di parentesi, non l'intero file — che
 * tocca `document` in punti irrilevanti qui) per verificare l'integrazione
 * reale tra i due file, non una riscrittura isolata di pricing-widget.js.
 *
 * Esecuzione: node tests/manual/js/test_pricing_widget_base_url.mjs
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

// Estrae solo la funzione qkApiFetch da app.js (per conteggio di parentesi
// graffe), evitando di eval-are l'intero file che referenzia `document` in
// molti altri punti non necessari per questo test.
function extractQkApiFetchSource() {
  const content = fs.readFileSync(path.join(REPO_ROOT, 'assets', 'js', 'app.js'), 'utf-8');
  const marker = 'window.qkApiFetch = async function';
  const start = content.indexOf(marker);
  if (start === -1) throw new Error('qkApiFetch non trovata in assets/js/app.js — il file è cambiato?');

  // La lista parametri contiene già un letterale `{}` (options = {}), quindi
  // il conteggio delle graffe del CORPO della funzione deve iniziare solo
  // dopo la ')' che chiude la lista parametri, non dalla prima '{' in assoluto.
  const parenStart = content.indexOf('(', start);
  if (parenStart === -1) throw new Error('Impossibile trovare la lista parametri di qkApiFetch');
  let parenDepth = 0, bodyOpenBrace = -1;
  for (let i = parenStart; i < content.length; i++) {
    if (content[i] === '(') parenDepth++;
    else if (content[i] === ')') {
      parenDepth--;
      if (parenDepth === 0) {
        // Cerca la prima '{' dopo la chiusura della lista parametri: è l'apertura del corpo.
        bodyOpenBrace = content.indexOf('{', i);
        break;
      }
    }
  }
  if (bodyOpenBrace === -1) throw new Error('Impossibile trovare il corpo di qkApiFetch');

  let depth = 0, end = -1;
  for (let i = bodyOpenBrace; i < content.length; i++) {
    if (content[i] === '{') depth++;
    else if (content[i] === '}') {
      depth--;
      if (depth === 0) { end = i + 1; break; }
    }
  }
  if (end === -1) throw new Error('Impossibile trovare la fine di qkApiFetch in app.js (parentesi non bilanciate?)');
  if (content[end] === ';') end++;
  return content.slice(start, end);
}

async function run() {
  global.window = global.window || {};
  const qkApiFetchSrc = extractQkApiFetchSource();
  (0, eval)(qkApiFetchSrc); // popola window.qkApiFetch col vero sorgente di app.js

  check('qkApiFetch estratta ed eval-ata correttamente da app.js', typeof global.window.qkApiFetch === 'function');

  const src = fs.readFileSync(path.join(REPO_ROOT, 'assets', 'js', 'pricing-widget.js'), 'utf-8');

  // ── Scenario 1: apiUrl esplicito (il path normale dopo il fix) ──────────
  {
    const calls = [];
    global.fetch = async (url) => {
      calls.push(url);
      return {
        ok: true,
        status: 200,
        headers: { get: () => 'application/json; charset=utf-8' },
        json: async () => ({ ok: true, prezzo_unitario: 12.5, prezzo_sorgente: 'Listino Standard' }),
      };
    };
    global.QK_BASE = '/deve-essere-ignorato-quando-apiUrl-e-presente';
    (0, eval)(src); // eval indiretto: esegue nello scope globale, non in quello locale del modulo

    const data = await global.qkFetchPrezzoSuggerito({
      prodottoId: 42, listinoId: 3, clienteId: 7, quantita: 2,
      apiUrl: '/Quack/sales/pricelists/api/ottieni-prezzo',
    });

    check('con apiUrl esplicito: la fetch usa esattamente l\'URL passato dal chiamante (col prefisso sottocartella)',
      calls.length === 1 && calls[0].startsWith('/Quack/sales/pricelists/api/ottieni-prezzo?'));
    check('con apiUrl esplicito: NON usa QK_BASE (che qui punterebbe altrove)',
      !calls[0].includes('deve-essere-ignorato'));
    check('con apiUrl esplicito: la querystring contiene i parametri corretti',
      calls[0].includes('prodotto_id=42') && calls[0].includes('listino_id=3') && calls[0].includes('cliente_id=7') && calls[0].includes('quantita=2'));
    check('con apiUrl esplicito: il risultato JSON arriva correttamente al chiamante',
      data && data.ok === true && data.prezzo_unitario === 12.5);
  }

  // ── Scenario 2: senza apiUrl, fallback deve usare QK_BASE (il vero globale) ──
  {
    const calls = [];
    global.fetch = async (url) => {
      calls.push(url);
      return { ok: true, status: 200, headers: { get: () => 'application/json' }, json: async () => ({ ok: true }) };
    };
    global.QK_BASE = '/Quack';
    (0, eval)(src);

    await global.qkFetchPrezzoSuggerito({ prodottoId: 1 });

    check('senza apiUrl: il fallback usa QK_BASE (il vero globale), non la variabile inesistente BASE',
      calls.length === 1 && calls[0].startsWith('/Quack/sales/pricelists/api/ottieni-prezzo?'));
  }

  // ── Scenario 3: 404 HTML — il sintomo esatto osservato in produzione ────
  {
    global.fetch = async () => ({
      ok: false,
      status: 404,
      headers: { get: (h) => h === 'content-type' ? 'text/html; charset=UTF-8' : null },
      text: async () => '<!DOCTYPE html><html><body>404 Not Found</body></html>',
      json: async () => { throw new Error('SyntaxError: Unexpected token \'<\', "<!DOCTYPE "... is not valid JSON'); },
    });
    global.QK_BASE = '';
    (0, eval)(src);

    let threw = false;
    let result;
    try {
      result = await global.qkFetchPrezzoSuggerito({ prodottoId: 1, apiUrl: '/percorso-sbagliato' });
    } catch (e) {
      threw = true;
    }

    check('risposta 404 HTML: la funzione non propaga un\'eccezione al chiamante (la ingoia e logga)', !threw);
    check('risposta 404 HTML: la funzione ritorna null invece di un JSON parziale/corrotto', result === null);
  }

  // ── Scenario 4: prodottoId assente — comportamento invariato (nessuna fetch) ──
  {
    const calls = [];
    global.fetch = async (url) => { calls.push(url); return { ok: true, status: 200, headers: { get: () => 'application/json' }, json: async () => ({}) }; };
    (0, eval)(src);
    const result = await global.qkFetchPrezzoSuggerito({ prodottoId: '' });
    check('prodottoId assente: ritorna null senza fare alcuna fetch (comportamento pre-esistente preservato)',
      result === null && calls.length === 0);
  }

  console.log('\n' + (failed === 0 ? `TUTTI I TEST OK (${total}/${total})` : `${failed}/${total} TEST FALLITI`));
  process.exit(failed === 0 ? 0 : 1);
}

run().catch(e => { console.error('ERRORE FATALE NEL TEST:', e); process.exit(1); });
