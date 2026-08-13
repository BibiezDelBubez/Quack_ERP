<?php
declare(strict_types=1);

/**
 * tests/manual/http/assert_piano11_e2e.php
 *
 * PIANO - Architettura UX Preventivo-Ordine-Spedizione.md, §12 (Layer 2).
 *
 * Legge le pagine HTML VERE, salvate da run_piano11_e2e.sh dopo GET reali
 * (login, sessione, CSRF) sulla pagina /sales/orders/edit di ognuno degli 8
 * ordini fixture, e verifica il contenuto — non il codice sorgente, l'HTML
 * che un browser riceverebbe davvero.
 *
 * Le verifiche sono divise in due gruppi, apposta, per non produrre un muro
 * di FAIL fuorviante:
 *
 *   A) "Invarianti oggi" — cose che devono essere vere SIA prima SIA dopo
 *      aver applicato i diff del §11 alla vista. Se una di queste fallisce,
 *      è una regressione vera, in qualunque momento venga eseguita la suite.
 *   B) "Atteso dopo il Diff 1-4" — cose che oggi (prima di applicare i diff)
 *      sono FALSE per costruzione (il comportamento vecchio/il bug che il
 *      piano corregge), e devono diventare VERE solo dopo l'implementazione.
 *      Eseguita PRIMA di applicare i diff, questa sezione deve fallire quasi
 *      per intero — è la prova che il test sa distinguere "vecchio" da
 *      "nuovo" comportamento, non un errore della suite. Eseguita DOPO
 *      l'implementazione, deve invece passare tutta: è il criterio di
 *      accettazione del §11.
 *
 * Uso: php assert_piano11_e2e.php <cartella_responses> <modo>
 *   <cartella_responses>: contiene un file .html per fixture (scritto da
 *                         run_piano11_e2e.sh via curl).
 *   <modo>: "pre" (atteso: gruppo A tutto verde, gruppo B quasi tutto rosso)
 *           oppure "post" (atteso: gruppo A e B entrambi tutti verdi) — non
 *           cambia le verifiche, cambia solo come viene interpretato/
 *           riportato l'esito finale nel riepilogo.
 */

$responsesDir = $argv[1] ?? null;
$modo = $argv[2] ?? 'pre';

if (!$responsesDir || !is_dir($responsesDir)) {
    fwrite(STDERR, "Uso: php assert_piano11_e2e.php <cartella_responses> [pre|post]\n");
    exit(2);
}

$passA = 0; $failA = 0;
$passB = 0; $failB = 0;

function leggiRisposta(string $dir, string $fixture): string
{
    $path = "$dir/$fixture.html";
    if (!is_file($path)) {
        fwrite(STDERR, "ATTENZIONE: manca $path (la GET per questa fixture non è stata salvata)\n");
        return '';
    }
    return (string) file_get_contents($path);
}

/**
 * Estrae SOLO il frammento HTML della guidance box, non l'intera pagina.
 *
 * Scoperto eseguendo questa suite per la prima volta (12/08/2026, esecuzione
 * reale in modo "pre"): un primo assert cercava "SP-T11-MULTI-2" ovunque
 * nella pagina intera, ed è risultato VERO anche PRIMA di applicare il Diff
 * 2 — non perché la guidance box lo citasse già (non lo fa), ma perché la
 * card "Spedizioni e DDT" più in basso elenca comunque il numero di ogni
 * spedizione in una colonna di tabella (`<td>SP-T11-MULTI-2</td>`),
 * indipendentemente dal piano. Un assert non delimitato dava quindi un falso
 * "verde" pre-diff su un'affermazione sulla guidance box — esattamente il
 * tipo di errore che questa batteria di test dovrebbe scoprire, scoperto
 * grazie all'averla davvero eseguita, non letta a occhio. Corretto restando
 * ai marker HTML già presenti nella vista (`_detail.php`, commenti
 * "Guidance Box" / "Barra Azioni Sticky") per isolare il frammento giusto
 * prima di cercarci dentro qualunque stringa.
 */
function estraiGuidanceBox(string $html): string
{
    $inizio = strpos($html, '<!-- Guidance Box -->');
    $fine   = strpos($html, '<!-- Barra Azioni Sticky -->');
    if ($inizio === false || $fine === false || $fine <= $inizio) {
        return ''; // marker assenti/spostati: meglio un frammento vuoto (assert fallirà onestamente) che uno sbagliato
    }
    return substr($html, $inizio, $fine - $inizio);
}

function chkA(string $label, bool $cond): void
{
    global $passA, $failA;
    if ($cond) { echo "OK   [A-invariante] - $label\n"; $passA++; return; }
    echo "FAIL [A-invariante] - $label   <-- REGRESSIONE VERA, a qualunque punto dell'implementazione ci si trovi\n";
    $failA++;
}

function chkB(string $label, bool $cond): void
{
    global $passB, $failB;
    if ($cond) { echo "OK   [B-nuovo-comportamento] - $label\n"; $passB++; return; }
    echo "FAIL [B-nuovo-comportamento] - $label\n";
    $failB++;
}

echo "=== INIZIO SUITE: PIANO §12 Layer 2 — HTTP end-to-end reale (modo: $modo) ===\n\n";

// ============================================================================
// Gruppo A — invarianti che devono valere sempre, oggi e dopo il Diff 1-4
// ============================================================================
echo "--- Gruppo A: invarianti (baseline, non toccate dal piano) ---\n";

$numeriOrdine = [
    'ORDER_IN_LAVORAZIONE' => 'T11-INLAV',
    'ORDER_PARZIALE'       => 'T11-PARZ',
    'ORDER_SPEDITO'        => 'T11-SPED',
    'ORDER_COMPLETATO'     => 'T11-COMPL',
    'ORDER_ANNULLATO'      => 'T11-ANN',
    'ORDER_SUPERATO'       => 'T11-SUP',
    'ORDER_MULTISPED'      => 'T11-MULTI',
    'ORDER_FRAZIONARIO'    => 'T11-FRAZ',
];

foreach ($numeriOrdine as $fixture => $numero) {
    $html = leggiRisposta($responsesDir, $fixture);
    chkA("$fixture: pagina non vuota (GET riuscita)", strlen($html) > 500);
    chkA("$fixture: mostra il numero ordine $numero", str_contains($html, $numero));
    chkA("$fixture: mostra la card 'Articoli in Ordine'", str_contains($html, 'Articoli in Ordine'));
    chkA("$fixture: almeno un campo CSRF presente", str_contains($html, 'name="csrf_token"'));
}

// "Annulla Ordine" — bottone NON toccato dal piano (§11.3, nota finale):
// deve restare presente/assente esattamente come oggi, prima e dopo i diff.
$htmlAttivo = leggiRisposta($responsesDir, 'ORDER_IN_LAVORAZIONE');
chkA("Ordine attivo: 'Annulla Ordine' presente (bottone invariato dal piano)", str_contains($htmlAttivo, 'Annulla Ordine'));
$htmlCompletato = leggiRisposta($responsesDir, 'ORDER_COMPLETATO');
chkA("Ordine completato: 'Annulla Ordine' assente (invariato)", !str_contains($htmlCompletato, 'Annulla Ordine'));
$htmlAnnullato = leggiRisposta($responsesDir, 'ORDER_ANNULLATO');
chkA("Ordine annullato: 'Annulla Ordine' assente (invariato)", !str_contains($htmlAnnullato, 'Annulla Ordine'));

// "Richiedi Correzione" — solo su ordini fatturati, invariato dal piano.
chkA("Ordine completato+fatturato: 'Richiedi Correzione' presente (invariato)", str_contains($htmlCompletato, 'Richiedi Correzione'));

// ============================================================================
// Gruppo B — comportamento NUOVO, introdotto dal Diff 1-4 del §11
// ============================================================================
echo "\n--- Gruppo B: comportamento nuovo (Diff 1-4, PIANO §11) ---\n";

// Diff 3 — Situazione A/B: "Completa Ordine" non deve più comparire a zero
// spedito (oggi lo fa: bug descritto in PIANO §2.4/§3, punto 2).
$html = leggiRisposta($responsesDir, 'ORDER_IN_LAVORAZIONE');
chkB("In lavorazione, zero spedito: 'Completa Ordine' ASSENTE (era il bug)", !str_contains($html, 'Completa Ordine'));
chkB("In lavorazione, zero spedito: 'Chiudi Ordine' ASSENTE (Annulla Ordine resta l'azione giusta)", !str_contains($html, 'Chiudi Ordine'));

// Parzialmente spedito: "Chiudi Ordine" deve comparire (non esiste affatto
// nel codice pre-diff), "Completa Ordine" NON deve più comparire qui (oggi
// invece compare, stesso bug di sopra).
$html = leggiRisposta($responsesDir, 'ORDER_PARZIALE');
chkB("Parzialmente spedito: 'Chiudi Ordine' PRESENTE (bottone nuovo)", str_contains($html, 'Chiudi Ordine'));
chkB("Parzialmente spedito: 'Completa Ordine' ASSENTE (era il bug)", !str_contains($html, 'Completa Ordine'));

// Tutto spedito: "Nuova Spedizione" deve sparire ovunque (oggi invece resta
// visibile nella card in fondo, anche a niente-piu-da-spedire — PIANO §2.5/
// Passo 5). "Completa Ordine" resta presente qui (era già vero prima: non è
// un flip, è verificato comunque per completezza).
$html = leggiRisposta($responsesDir, 'ORDER_SPEDITO');
chkB("Tutto spedito: 'Nuova Spedizione' ASSENTE (nulla resta da spedire)", !str_contains($html, 'Nuova Spedizione'));
chkA("Tutto spedito: 'Completa Ordine' presente (invariato, non un flip)", str_contains($html, 'Completa Ordine'));

// Badge conteggio spedizioni in alto (Diff 4b) — non esiste affatto oggi.
$html = leggiRisposta($responsesDir, 'ORDER_IN_LAVORAZIONE');
chkB("Badge 'Spedizioni: 0 registrate' su ordine senza spedizioni", str_contains($html, 'Spedizioni: 0 registrate'));

$html = leggiRisposta($responsesDir, 'ORDER_COMPLETATO');
chkB("Badge 'Spedizioni: 1 registrata' su ordine con una spedizione", str_contains($html, 'Spedizioni: 1 registrata'));

$html = leggiRisposta($responsesDir, 'ORDER_MULTISPED');
chkB("Badge 'Spedizioni: 2 registrate' su ordine con due spedizioni", str_contains($html, 'Spedizioni: 2 registrate'));
// Guidance box (Diff 2) deve citare l'ULTIMA spedizione (SP-T11-MULTI-2, la
// più recente), non la prima — verifica diretta dell'ordinamento DESC di
// qk_get_ordine_cliente_spedizioni() applicato correttamente dalla vista.
// Deliberatamente ristretto al SOLO frammento della guidance box (vedi
// estraiGuidanceBox() sopra): la card "Spedizioni e DDT" più in basso elenca
// comunque il numero di ogni spedizione in tabella, quindi un controllo
// sulla pagina intera darebbe un falso positivo anche prima del Diff 2
// (scoperto eseguendo davvero questa suite la prima volta).
$guidanceBox = estraiGuidanceBox($html);
chkB("Guidance box (frammento isolato) cita l'ultima spedizione (SP-T11-MULTI-2), non la prima", str_contains($guidanceBox, 'SP-T11-MULTI-2'));
chkB("Guidance box (frammento isolato) NON cita la prima spedizione (SP-T11-MULTI-1)", !str_contains($guidanceBox, 'SP-T11-MULTI-1'));

// Ancora HTML per il link "in alto" (Diff 4a).
chkB("Ancora id=\"spedizioni-ddt\" presente sulla card in fondo pagina", str_contains($html, 'id="spedizioni-ddt"'));

// Quantità frazionarie: la guidance box (Diff 2) deve mostrare il residuo
// esatto (7.25), non troncato/arrotondato a un intero — il motivo per cui il
// piano vieta number_format a 0 decimali (§11.2, nota sul formato numerico).
$html = leggiRisposta($responsesDir, 'ORDER_FRAZIONARIO');
$guidanceBoxFraz = estraiGuidanceBox($html);
chkB("Quantità frazionarie: guidance box (frammento isolato) mostra '7.25' (residuo esatto)", str_contains($guidanceBoxFraz, '7.25'));

echo "\n=== RIEPILOGO SUITE (modo: $modo) ===\n";
echo "Gruppo A (invarianti):        PASS $passA / FAIL $failA\n";
echo "Gruppo B (nuovo comportamento): PASS $passB / FAIL $failB\n";

if ($failA > 0) {
    echo "\nATTENZIONE: almeno un'invariante del Gruppo A è fallita — questa e' una regressione vera, indipendentemente dal modo ('$modo').\n";
}

if ($modo === 'pre') {
    echo "\nModo 'pre' (prima di applicare i diff del §11 alla vista): atteso Gruppo A tutto verde, Gruppo B in gran parte rosso.\n";
    exit($failA > 0 ? 1 : 0);
}

echo "\nModo 'post' (dopo aver applicato i diff del §11 alla vista): atteso Gruppo A e Gruppo B entrambi tutti verdi.\n";
exit(($failA > 0 || $failB > 0) ? 1 : 0);
