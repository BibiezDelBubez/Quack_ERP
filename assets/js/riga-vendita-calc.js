/**
 * riga-vendita-calc.js — calcolo condiviso di imponibile/IVA/totale per una
 * riga di vendita (preventivo o ordine cliente diretto).
 *
 * Estratta il 13/08/2026 dopo aver scoperto, durante il test manuale in
 * browser della Fase 5 del PIANO - Aliquota IVA su Prodotti e Cliente
 * Esente (Livello Intermedio), che sales-quotes.js e sales-orders-create.js
 * duplicavano — in modo identico, quindi bacate allo stesso modo — la
 * stessa formula dentro le rispettive calcTotali():
 *
 *   const iva = parseFloat(tr.querySelector('.inp-iva')?.value) || 22;
 *
 * Bug: parseFloat('0') restituisce il numero 0, che l'operatore `||` tratta
 * come "valore assente" — quindi un'aliquota IVA legittimamente a zero
 * (cliente esente, vedi §7 del piano sopra) veniva silenziosamente
 * sostituita con 22 nel calcolo di ANTEPRIMA mostrato a video. Non nel dato
 * salvato: il salvataggio reale ricalcola sempre lato server
 * (includes/sales_quotes.php:152-155, qk_calcola_riga_offerta(), e
 * app/Services/OrderCalculatorService.php:133-141, insertDirectOrder()),
 * che applicavano già il controllo corretto — verificato leggendo entrambi
 * prima di scrivere questo file, non dato per scontato.
 *
 * Stessa causa strutturale già affrontata in questo progetto per un
 * problema diverso ma identico nella forma: vedi margin-semaforo.js e il
 * suo stesso commento in testa — due pagine, stessa logica, copiata a mano
 * invece che condivisa. Questo file applica lo stesso rimedio: un'unica
 * funzione pura, senza dipendenze dal DOM, caricata con <script> da
 * entrambe le pagine (app/views/sales/quotes/_form.php e
 * app/views/sales/orders/_create.php) e richiamata da entrambi i
 * calcTotali() invece di essere riscritta.
 *
 * Nota sull'arrotondamento: sales-quotes.js usava la libreria Big.js (già
 * caricata solo su quella pagina) per i calcoli di riga, mentre
 * sales-orders-create.js usava aritmetica nativa (Math.round). Questa
 * funzione condivisa usa aritmetica nativa con arrotondamento a 2 decimali
 * ad ogni passaggio — stessa tecnica già usata lato server in
 * qk_calcola_riga_offerta() (round() di PHP, non una libreria decimale) —
 * per restare senza dipendenze extra (nessun obbligo di caricare Big.js
 * anche nella pagina ordini) e per essere il più possibile coerente con
 * l'arrotondamento del calcolo server-side autorevole. Sulle cifre tipiche
 * di prezzo/quantità/sconto di questo dominio la differenza rispetto a
 * Big.js è inosservabile; se in futuro emergesse un caso reale di deriva,
 * va risolto qui, in un solo punto, non tornando a due copie separate.
 *
 * Guardia module.exports: stesso meccanismo di margin-semaforo.js, per
 * poter testare questa funzione con Node
 * (tests/manual/js/test_riga_vendita_calc.js) senza bisogno di un browser.
 *
 * @param {number} prezzoUnitario
 * @param {number} quantita
 * @param {number} scontoPerc  Sconto di riga in percentuale (0-100)
 * @param {string|number|null|undefined} ivaGrezza  Valore grezzo così come
 *   letto dal campo IVA della riga (stringa dall'input: può essere '',
 *   null, undefined, o un numero/stringa numerica valida — "0" compreso).
 * @param {number} ivaFallback  Aliquota da usare SOLO se ivaGrezza è
 *   mancante o non numerica — mai se vale legittimamente zero.
 * @returns {{imponibileRiga:number, ivaRiga:number, totaleRiga:number, ivaPercUsata:number}}
 */
function qkCalcolaRigaVendita(prezzoUnitario, quantita, scontoPerc, ivaGrezza, ivaFallback) {
  const pu = Number(prezzoUnitario) || 0;
  const qta = Number(quantita) || 0;
  const sc = Number(scontoPerc) || 0;

  // Stesso identico principio già in uso lato server
  // (qk_calcola_riga_offerta(), includes/sales_quotes.php:152-155): un
  // valore mancante o non numerico usa il fallback; un valore numerico
  // valido, zero compreso, si usa così com'è. Mai un `|| fallback` sul
  // risultato di parseFloat, che tratterebbe 0 come "mancante".
  let ivaPercUsata = Number(ivaFallback) || 0;
  if (ivaGrezza !== null && ivaGrezza !== undefined && String(ivaGrezza).trim() !== '') {
    const ivaParsata = parseFloat(ivaGrezza);
    if (!Number.isNaN(ivaParsata)) {
      ivaPercUsata = ivaParsata;
    }
  }

  const prezzoNetto = pu * (1 - sc / 100);
  const imponibileRiga = Math.round(prezzoNetto * qta * 100) / 100;
  const ivaRiga = Math.round(imponibileRiga * (ivaPercUsata / 100) * 100) / 100;
  const totaleRiga = Math.round((imponibileRiga + ivaRiga) * 100) / 100;

  return { imponibileRiga, ivaRiga, totaleRiga, ivaPercUsata };
}

if (typeof module !== 'undefined' && module.exports) {
  module.exports = { qkCalcolaRigaVendita };
}
