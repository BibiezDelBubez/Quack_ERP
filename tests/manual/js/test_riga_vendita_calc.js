/**
 * tests/manual/js/test_riga_vendita_calc.js
 *
 * Test comportamentale di assets/js/riga-vendita-calc.js — in particolare
 * del bug reale scoperto il 13/08/2026 durante il test manuale in browser
 * della Fase 5 del PIANO - Aliquota IVA su Prodotti e Cliente Esente
 * (Livello Intermedio): un cliente esente IVA vedeva il campo IVA di riga
 * correttamente a "0", ma il riepilogo totali calcolava comunque il 22% —
 * causa: `parseFloat('0') || 22` tratta lo zero legittimo come "mancante".
 * Il test TC4 qui sotto riproduce esattamente questo scenario e fallisce
 * se il bug si ripresentasse.
 *
 * Carica il vero sorgente del modulo condiviso (non una riscrittura) via
 * require(), sfruttando la guardia `module.exports` già presente nel file
 * — stesso approccio di margin-semaforo.js, mai stato testato con Node
 * prima d'ora (verificato con una ricerca nel repo prima di scrivere questo
 * file: nessun test esistente lo copriva).
 *
 * Esecuzione: node tests/manual/js/test_riga_vendita_calc.js
 */

const path = require('path');
const { qkCalcolaRigaVendita } = require(path.join('..', '..', '..', 'assets', 'js', 'riga-vendita-calc.js'));

let total = 0, failed = 0;
function check(label, cond, detail) {
  total++;
  if (cond) {
    console.log('OK   - ' + label);
  } else {
    failed++;
    console.log('FAIL - ' + label + (detail ? ' (' + detail + ')' : ''));
  }
}

function approxEqual(a, b, eps) {
  return Math.abs(a - b) < (eps || 0.001);
}

// TC1 — IVA valorizzata normale: usa il valore inviato, non il fallback.
{
  const r = qkCalcolaRigaVendita(100, 2, 0, '10', 22);
  check('TC1: IVA "10" inviata -> usata 10, non il fallback 22', r.ivaPercUsata === 10, 'ivaPercUsata=' + r.ivaPercUsata);
  check('TC1: imponibile = 200.00', approxEqual(r.imponibileRiga, 200), 'imponibile=' + r.imponibileRiga);
  check('TC1: iva riga = 20.00 (10% di 200)', approxEqual(r.ivaRiga, 20), 'ivaRiga=' + r.ivaRiga);
  check('TC1: totale riga = 220.00', approxEqual(r.totaleRiga, 220), 'totaleRiga=' + r.totaleRiga);
}

// TC2 — IVA stringa vuota: campo davvero mancante, usa il fallback.
{
  const r = qkCalcolaRigaVendita(50, 1, 0, '', 22);
  check('TC2: IVA "" -> fallback 22 usato', r.ivaPercUsata === 22, 'ivaPercUsata=' + r.ivaPercUsata);
}

// TC3 — IVA null/undefined (riga costruita senza il campo): usa il fallback.
{
  const rNull = qkCalcolaRigaVendita(50, 1, 0, null, 22);
  const rUndef = qkCalcolaRigaVendita(50, 1, 0, undefined, 22);
  check('TC3: IVA null -> fallback 22 usato', rNull.ivaPercUsata === 22, 'ivaPercUsata=' + rNull.ivaPercUsata);
  check('TC3: IVA undefined -> fallback 22 usato', rUndef.ivaPercUsata === 22, 'ivaPercUsata=' + rUndef.ivaPercUsata);
}

// TC4 — IL CASO DEL BUG: IVA "0" legittimo (cliente esente) NON deve
// diventare il fallback. Riproduce esattamente lo scenario osservato in
// browser: riga con imponibile 45.00, IVA di riga "0" (stringa, come la
// legge davvero tr.querySelector('.inp-iva').value dal DOM).
{
  const r = qkCalcolaRigaVendita(1.8, 25, 0, '0', 22);
  check('TC4 (bug originale): IVA "0" -> resta 0, NON diventa 22', r.ivaPercUsata === 0, 'ivaPercUsata=' + r.ivaPercUsata);
  check('TC4: imponibile = 45.00', approxEqual(r.imponibileRiga, 45), 'imponibile=' + r.imponibileRiga);
  check('TC4: iva riga = 0.00 (non 9.90 come nel bug osservato in browser)', approxEqual(r.ivaRiga, 0), 'ivaRiga=' + r.ivaRiga);
  check('TC4: totale riga = 45.00 (non 54.90)', approxEqual(r.totaleRiga, 45), 'totaleRiga=' + r.totaleRiga);
}

// TC4bis — stesso caso ma con IVA "0" numero puro (non stringa), nel caso
// il chiamante passi già un numero invece del valore grezzo dell'input.
{
  const r = qkCalcolaRigaVendita(1.8, 25, 0, 0, 22);
  check('TC4bis: IVA numero 0 -> resta 0, NON diventa 22', r.ivaPercUsata === 0, 'ivaPercUsata=' + r.ivaPercUsata);
}

// TC5 — IVA non numerica: valore corrotto/imprevisto, usa il fallback
// invece di propagare NaN nei totali.
{
  const r = qkCalcolaRigaVendita(50, 1, 0, 'abc', 22);
  check('TC5: IVA "abc" (non numerica) -> fallback 22 usato', r.ivaPercUsata === 22, 'ivaPercUsata=' + r.ivaPercUsata);
  check('TC5: nessun NaN propagato nei totali', !Number.isNaN(r.totaleRiga), 'totaleRiga=' + r.totaleRiga);
}

// TC6 — sconto di riga applicato correttamente insieme a un'IVA esplicita.
{
  const r = qkCalcolaRigaVendita(100, 3, 10, '22', 22);
  // prezzo netto = 100 * 0.9 = 90; imponibile = 90 * 3 = 270; iva = 59.40; totale = 329.40
  check('TC6: sconto 10% + IVA 22% -> imponibile 270.00', approxEqual(r.imponibileRiga, 270), 'imponibile=' + r.imponibileRiga);
  check('TC6: iva riga = 59.40', approxEqual(r.ivaRiga, 59.4), 'ivaRiga=' + r.ivaRiga);
  check('TC6: totale riga = 329.40', approxEqual(r.totaleRiga, 329.4), 'totaleRiga=' + r.totaleRiga);
}

// TC7 — quantità/prezzo a zero: nessun errore, tutto a zero.
{
  const r = qkCalcolaRigaVendita(0, 0, 0, '22', 22);
  check('TC7: prezzo e quantita a 0 -> tutti i totali a 0', r.imponibileRiga === 0 && r.ivaRiga === 0 && r.totaleRiga === 0);
}

console.log('\n' + total + ' test eseguiti, ' + (total - failed) + ' passati, ' + failed + ' falliti.');
process.exit(failed > 0 ? 1 : 0);
