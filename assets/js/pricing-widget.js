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
 * @param {Object} params
 * @param {string|number} params.prodottoId
 * @param {string|number} [params.listinoId]
 * @param {string|number} [params.clienteId]
 * @param {number} [params.quantita=1]
 * @param {string|number} [params.unitaMisuraId]
 * @returns {Promise<Object|null>} l'oggetto JSON di risposta, o null in caso di errore di rete.
 */
async function qkFetchPrezzoSuggerito({ prodottoId, listinoId = '', clienteId = '', quantita = 1, unitaMisuraId = '' }) {
  if (!prodottoId) return null;
  const base = (typeof BASE !== 'undefined') ? BASE : '';
  const qs = new URLSearchParams({
    prodotto_id: String(prodottoId),
    listino_id: String(listinoId ?? ''),
    cliente_id: String(clienteId ?? ''),
    quantita: String(quantita ?? 1),
    unita_misura_id: String(unitaMisuraId ?? ''),
  });
  try {
    const res = await fetch(`${base}/sales/pricelists/api/ottieni-prezzo?${qs.toString()}`);
    return await res.json();
  } catch (e) {
    console.error('qkFetchPrezzoSuggerito:', e);
    return null;
  }
}
