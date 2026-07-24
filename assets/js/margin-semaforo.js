/**
 * margin-semaforo.js — calcolo condiviso del "semaforo" margine %
 * (PIANO - Soglie Margine Configurabili, §1.4).
 *
 * Prima di questo file, sales-quotes.js e sales-orders-create.js
 * duplicavano (in modo divergente) la logica di colorazione del box
 * "margine %": sales-quotes.js aveva già tre fasce (verde/giallo/rosso) ma
 * con soglie hardcoded (20/10); sales-orders-create.js condivideva lo stesso
 * ciclo del box "margine €" ed era quindi solo binario (verde/rosso), senza
 * fascia gialla. Estratta qui un'unica funzione pura, senza dipendenze dal
 * DOM, così le soglie diventano un parametro invece di una costante — lette
 * a runtime da Impostazioni (vedi SettingsController::generali() e
 * window.QK_QUOTES_CONFIG / window.QK_ORDERS_CREATE_CONFIG) invece di essere
 * scritte nel codice sorgente.
 *
 * Il box "margine €" (segno positivo/negativo) resta volutamente binario in
 * entrambi i form e NON usa questa funzione — è una metrica di segno, non di
 * fascia di qualità (vedi piano, §2).
 *
 * Guardia module.exports: permette di testare questa funzione con Node
 * (nessuna dipendenza da window/document/fetch), senza alcun effetto quando
 * il file è caricato nel browser (dove `module` è undefined) — stesso
 * concetto già chiesto dall'utente per evitare di dover ricostruire un
 * interprete PHP per ogni test: qui il test JS gira direttamente con `node`,
 * senza bisogno di un browser.
 *
 * @param {number} marginPerc  Margine percentuale calcolato (puo' essere negativo)
 * @param {number} sogliaVerde  Soglia minima (inclusa) per il colore verde
 * @param {number} sogliaGiallo Soglia minima (inclusa) per il colore giallo
 * @returns {'verde'|'giallo'|'rosso'}
 */
function qkMarginSemaforo(marginPerc, sogliaVerde, sogliaGiallo) {
  if (marginPerc >= sogliaVerde) return 'verde';
  if (marginPerc >= sogliaGiallo) return 'giallo';
  return 'rosso';
}

if (typeof module !== 'undefined' && module.exports) {
  module.exports = { qkMarginSemaforo };
}
