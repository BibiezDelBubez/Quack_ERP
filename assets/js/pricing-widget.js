/**
 * pricing-widget.js — chiamata condivisa al motore di prezzo (AUDIT 3 §1.2).
 *
 * Prima di questa estrazione, sales-orders-create.js e sales-quotes.js
 * duplicavano la stessa chiamata a /sales/pricelists/api/ottieni-prezzo — una
 * corretta (sales-quotes.js), una rotta (sales-orders-create.js: mandava i
 * parametri via POST body invece che in query string GET, con anche un nome
 * parametro sbagliato e una chiave di risposta sbagliata). Estratta qui in
 * un'unica funzione perché la parte rotta era esattamente quella dove vive
 * la duplicazione — non nella gestione della risposta, che resta diversa e
 * specifica di ogni form (conversione prezzo tra UoM, sconto, RRP solo nel
 * form preventivi).
 *
 * FIX 27/07/2026 (bug reale scoperto in produzione): questa funzione
 * costruiva l'URL leggendo una variabile globale `BASE` che non è mai
 * esistita da nessuna parte nell'app — l'unico vero globale impostato dal
 * server è `QK_BASE` (includes/header.php). Ogni pagina che chiama questa
 * funzione (sales-quotes.js, sales-orders-create.js) si crea invece una
 * propria `BASE` locale dentro il proprio scope di DOMContentLoaded,
 * invisibile da qui: `typeof BASE` era quindi SEMPRE 'undefined', l'URL
 * partiva sempre senza il prefisso della sottocartella, e la richiesta
 * finiva sempre in un 404 HTML che rompeva il successivo `res.json()`.
 *
 * Soluzione (coerente con il pattern già dominante nel resto dell'app —
 * vedi window.QkAdrSostanze.lookupUrl, QK_QUOTES_CONFIG.verificaDisponibilitaUrl,
 * ecc.): l'URL completo va generato lato server con url() e passato dal
 * chiamante tramite il parametro esplicito `apiUrl`. Il fallback su QK_BASE
 * resta solo come rete di sicurezza per un eventuale nuovo chiamante che se
 * ne dimenticasse — non è più il percorso normale.
 *
 * REFACTOR 27/07/2026 (DRY, PIANO qkApiFetch): la validazione HTTP/content-type
 * introdotta col fix sopra è stata estratta in window.qkApiFetch() (assets/js/app.js),
 * condivisa anche da altri 5 file che avevano lo stesso problema. Questa funzione
 * ora la richiama invece di duplicarla. window.qkApiFetch è definita in app.js,
 * caricato da includes/footer.php su ogni pagina — disponibile per il tempo in
 * cui questa funzione viene davvero chiamata (interazione utente, non al parse
 * dello script), stesso schema già usato in questo file per QK_BASE.
 *
 * @param {Object} params
 * @param {string|number} params.prodottoId
 * @param {string|number} [params.listinoId]
 * @param {string|number} [params.clienteId]
 * @param {number} [params.quantita=1]
 * @param {string|number} [params.unitaMisuraId]
 * @param {string} [params.apiUrl] URL completo dell'endpoint, generato lato
 *   server con url('sales/pricelists/api/ottieni-prezzo') e passato dal
 *   chiamante (es. window.QK_QUOTES_CONFIG.ottieniPrezzoUrl). Se omesso,
 *   ricade su QK_BASE + percorso fisso.
 * @returns {Promise<Object|null>} l'oggetto JSON di risposta, o null in caso di errore.
 */
async function qkFetchPrezzoSuggerito({ prodottoId, listinoId = '', clienteId = '', quantita = 1, unitaMisuraId = '', apiUrl = '' } = {}) {
  if (!prodottoId) return null;
  const base = (typeof QK_BASE !== 'undefined') ? QK_BASE : '';
  const url  = apiUrl || (base + '/sales/pricelists/api/ottieni-prezzo');
  const qs = new URLSearchParams({
    prodotto_id: String(prodottoId),
    listino_id: String(listinoId ?? ''),
    cliente_id: String(clienteId ?? ''),
    quantita: String(quantita ?? 1),
    unita_misura_id: String(unitaMisuraId ?? ''),
  });
  const fullUrl = `${url}?${qs.toString()}`;
  try {
    return await window.qkApiFetch(fullUrl);
  } catch (e) {
    console.error('qkFetchPrezzoSuggerito:', e);
    return null;
  }
}
