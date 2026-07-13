# 02 — Dettaglio Tecnico: Schema DB e Codice

Riferimento tecnico puntuale per chi implementa o tocca questa parte del codice. Per il flusso operativo e le decisioni di prodotto, vedi [01 - Ciclo Multi-UoM e Prezzi - Guida Completa.md](01%20-%20Ciclo%20Multi-UoM%20e%20Prezzi%20-%20Guida%20Completa.md). File sorgente principali: `db/init_sqlite.sql`, `db/init_mysql.sql` (schemi gemelli, tenuti allineati), `includes/prodotti.php`, `includes/pricing.php`, `includes/sales_quotes.php`, `includes/purchases_rfq.php`, `includes/purchases_orders.php`, `app/Controllers/SalesPricelistsController.php`, `app/Controllers/PurchasesController.php`, `app/Controllers/ProductsController.php`, `assets/js/app.js`, `assets/js/products-form.js`.

---

## 1. Schema — tabella `prodotti` (colonne multi-UoM)

| Colonna | Tipo | Note |
|---|---|---|
| `unita_misura_stock_id` | INTEGER, NOT NULL | FK `unita_misura(id)`. Stock UoM, obbligatoria, immutabile dopo il primo movimento. |
| `unita_contenuto_id` | INTEGER, nullable | FK `unita_misura(id)`. UoM commerciale, opzionale. |
| `quantita_contenuto` | DECIMAL(10,3), nullable | Quanta `unita_contenuto_id` è contenuta in 1 `unita_misura_stock_id`. Max 3 decimali (validato). |
| `num_factor` | INTEGER, NOT NULL DEFAULT 1 | Numeratore della frazione esatta — vedi convenzione sotto. |
| `den_factor` | INTEGER, NOT NULL DEFAULT 1 | Denominatore della frazione esatta. |

### Convenzione num_factor/den_factor — CRITICA, storia del bug

`num_factor`/`den_factor` devono rappresentare **`1 / quantita_contenuto`**, non `quantita_contenuto` stesso. Per `quantita_contenuto=25` (1 fusto = 25 kg): `num_factor=1, den_factor=25`.

**Perché è facile sbagliarla**: il 14/07/2026 un fix per un bug di troncamento (12.5 → 12 per i decimali) ha unificato il calcolo dei due valori, ma ha invertito la convenzione per **tutti** i valori (non solo i decimali), rompendo il prezzo convertito per la maggioranza dei prodotti reali. È stato scoperto solo eseguendo realmente la formula con un interprete PHP (php-wasm + bcmath) su 9 casi numerici concreti — non rileggendo il codice.

Calcolo corretto, in `includes/prodotti.php` (`qk_insert_prodotto()` e `qk_update_prodotto()`, duplicato in entrambe le funzioni):
```php
// Rappresenta quantita_contenuto come frazione esatta aVal/bVal (stringa + GCD),
// poi INVERTE: num_factor = bVal, den_factor = aVal, per ottenere 1/quantita_contenuto.
$rawQty = qk_prodotti_decimal($v['quantita_contenuto']);
$numFactor = 1; $denFactor = 1;
if ($rawQty !== null && (float)$rawQty > 0.0) {
    $fVal = (float)$rawQty;
    $strVal = (string)$fVal;
    $decimals = strpos($strVal, '.') !== false ? strlen(substr($strVal, strpos($strVal, '.') + 1)) : 0;
    $multiplier = 10 ** $decimals;
    $aVal = (int)round($fVal * $multiplier);
    $bVal = $multiplier;
    $gcd = function($a, $b) use (&$gcd) { return $b ? $gcd($b, $a % $b) : $a; };
    $g = (int)$gcd($aVal, $bVal);
    if ($g > 0) { $aVal = (int)($aVal / $g); $bVal = (int)($bVal / $g); }
    $numFactor = $bVal;  // inversione
    $denFactor = $aVal;
}
```
Verificato con 9 casi reali (qc = 25, 12.5, 10, 2, 0.04, 0.5, 1, 100, 3.333) via esecuzione bcmath reale, non a memoria.

---

## 2. Schema — tabelle di prezzo (colonne canoniche multi-UoM)

Aggiunte a `listini_prezzi` (vendita) e `prodotti_fornitori_prezzi` (acquisto), entrambi gli schemi:

| Colonna | Tipo | Significato |
|---|---|---|
| `quantita_minima_intento` | DECIMAL(10,3), nullable | Il valore con cui l'utente ha *davvero* scritto la soglia. |
| `unita_misura_soglia_id` | INTEGER, nullable, FK `unita_misura(id)` | L'unità di quell'intento. `NULL` = intento già in Stock UoM. |
| `quantita_minima_stock` | DECIMAL(10,3) | **Valore canonico**, sempre in Stock UoM. Confrontato dal motore di ricerca prezzo. Calcolato con `qk_uom_converti_riga()`. |

`quantita_minima` (colonna preesistente) resta per retrocompatibilità ma non è più quella autorevole per la ricerca.

### Vincoli UNIQUE canonici

```sql
-- listini_prezzi
UNIQUE(listino_id, prodotto_id, quantita_minima_stock)
-- prodotti_fornitori_prezzi
UNIQUE(prodotto_fornitore_id, listino_acquisto_id, quantita_minima_stock)
```
Sostituiscono i vecchi vincoli sul valore grezzo (`quantita_minima`/`unita_misura_id`) — intercettano anche i "doppioni mascherati" (stessa soglia fisica, unità diverse). Su MySQL, `prodotti_fornitori_prezzi` non aveva mai avuto un vincolo `UNIQUE`: colmato qui direttamente nella forma finale corretta.

**Bug storico superato (non più presente nel codice attuale)**: prima di questa architettura, `SalesPricelistsController::getSalePrice()` conteneva tre query che filtravano `listini_prezzi` per `valido_dal`/`valido_al` — colonne che su questa tabella **non sono mai esistite** (la validità per il ciclo vendite vive solo sulla testata `listini`, per scelta di progetto). Con la gestione errori PDO in modalità eccezione, questo generava un errore SQL a ogni chiamata, che risaliva fino al `try/catch` più esterno saltando l'intera cascata di fallback (la ricerca prezzo falliva sempre, invece di scendere al livello successivo). Corretto il 10/07/2026 rimuovendo le condizioni non valide. **Stato: risolto e superato**, non solo corretto — le query di quel periodo non esistono più nemmeno nella forma corretta di allora: sono state interamente riscritte per confrontare il valore canonico `quantita_minima_stock` (§4-5), quindi il bug non ha più alcuna superficie su cui potersi ripresentare. Dettaglio storico completo in `REVISIONE - Ridondanze, Contraddizioni e Piano di Riorganizzazione.md`, punto K / §6.10.

### Tabelle righe-documento (snapshot, invariate da questo piano)

`preventivi_righe`, `ordini_clienti_righe`, `rfq_righe`, `ordini_righe` — tutte simmetriche: `unita_misura_id`, `quantita_stock`, `system_unit_price_stock`, `unita_misura_stock_id`, `quantita_contenuto_snapshot`, `num_factor_snapshot`, `den_factor_snapshot`. Congelano il fattore al momento della creazione del documento — mai toccate dal ricalcolo delle tabelle prezzo "vive" (§4).

---

## 3. `qk_uom_converti_riga()` — la funzione autorevole

`includes/prodotti.php`. Firma:

```php
function qk_uom_converti_riga(PDO $pdo, ?int $prodotto_id, ?int $um_id, float $qta, float $pu): array
```

Riceve prodotto, unità e quantità/prezzo in quell'unità; restituisce `quantita_stock`, `system_unit_price_stock`, `unita_misura_stock_id`, `quantita_contenuto_snapshot`, `num_factor_snapshot`, `den_factor_snapshot`. Logica:

- Se `um_id` è già la Stock UoM del prodotto: nessuna conversione.
- Se `um_id` è la UoM commerciale e `quantita_contenuto > 0`:
  - `quantita_stock = bcdiv(qta, quantita_contenuto, 3)` — divisione BCMath, mai float diretto.
  - `system_unit_price_stock = bcmul(pu, bcdiv(den_factor, num_factor, 9), 4)` — cioè `pu × quantita_contenuto` (perché `den/num = quantita_contenuto` con la convenzione invertita di §1).
- Se `um_id` non è né l'una né l'altra (caso anomalo, non dovrebbe mai presentarsi per la regola sulla "terza unità" — vedi §6): restituisce i valori non convertiti, silenziosamente.

È l'unica funzione autorevole di conversione — usata da tutti e 4 i cicli documentali (preventivi, ordini vendita, RFQ, ordini acquisto) e dal ricalcolo soglie canoniche (§4). Controparte JS di sola anteprima: `qkConvertUom()` in `assets/js/app.js:368-397` (float singolo, mai fonte di verità — commento esplicito nel codice).

---

## 4. `qk_ricalcola_bozze_per_cambio_stock_uom()` — ricalcolo su cambio fattore/Stock UoM

`includes/prodotti.php`. Firma:

```php
function qk_ricalcola_bozze_per_cambio_stock_uom(PDO $pdo, int $prodotto_id): array
// @return array{preventivi: int, rfq: int, ordini_acquisto: int, prezzi_vendita: int, prezzi_acquisto: int}
```

Chiamata da `qk_update_prodotto()` (stessa transazione, subito dopo l'`UPDATE` che scrive il nuovo `unita_misura_stock_id`/`quantita_contenuto`/`num_factor`/`den_factor`), solo se la Stock UoM è davvero cambiata. Ricalcola:

1. **Preventivi in bozza** (`preventivi_righe` JOIN `preventivi` WHERE `stato='bozza'`): per ogni riga, richiama `qk_calcola_riga_offerta()` (che a sua volta chiama `qk_uom_converti_riga()`) con gli stessi valori originariamente inseriti dall'utente (`quantita`, `prezzo_unitario`), aggiornando `quantita_stock`, `system_unit_price_stock`, `unita_misura_stock_id`, gli snapshot e `totale_costo_riga`/`margine_riga`.
2. **RFQ in bozza** (`rfq_righe` JOIN `rfq_testata` WHERE `stato='bozza'`): richiama direttamente `qk_uom_converti_riga()`.
3. **Ordini d'acquisto in bozza** (`ordini_righe` JOIN `ordini_testata` WHERE `stato='bozza'`): stesso pattern. Mai sugli ordini già inviati — quelli si toccano solo tramite "nuova revisione" (§7 del documento 01), che preserva lo snapshot originale.
4. **`quantita_minima_stock` su `listini_prezzi`**: per ogni riga del prodotto, ricalcola da `quantita_minima_intento` + `unita_misura_soglia_id` col fattore corrente.
5. **`quantita_minima_stock` su `prodotti_fornitori_prezzi`**: stesso pattern, via JOIN `prodotti_fornitori`.

`qk_update_prodotto()` include anche la guardia che rende obbligatorio il nuovo fattore quando il prodotto ha già una UoM commerciale e la Stock UoM sta cambiando, e ora restituisce `array` (conteggio ricalcoli) invece di `void` — il chiamante (`ProductsController::save()`) lo usa per comporre il messaggio di conferma.

**Verifica eseguita (14/07/2026)**: query SQL identiche a quelle del codice eseguite contro lo schema reale (`db/init_sqlite.sql`) via `node:sqlite`; risultati numerici verificati (100 kg / 25 kg-per-fusto = 4 → ricalcolato a 100 kg / 20 kg-per-tanica = 5 dopo cambio fattore; prezzo 250€ → 200€).

---

## 5. Motore di ricerca prezzo — `includes/pricing.php`

Estratto dai controller in funzioni pure, riusabili anche fuori da un contesto HTTP (CLI/cron futuri):

```php
function qk_get_sale_price(PDO $pdo, int $prodottoId, int $listinoId, float $quantita = 1.0, ?string $dataRiferimento = null, ?int $clienteId = null): ?array
function qk_get_purchase_price(PDO $pdo, int $prodottoId, int $fornitoreId, float $quantita = 1.0, ?string $dataRiferimento = null): ?array
```

I controller (`SalesPricelistsController::getSalePrice()`, `PurchasesController::getPurchasePrice()`) sono ora wrapper di una riga che delegano a queste funzioni. Contratto: **ricevono sempre la quantità già convertita in Stock UoM** dal chiamante (tramite `qk_uom_converti_riga()`), non un numero grezzo di provenienza ambigua. Le query di ricerca scaglione usano `quantita_minima_stock <= :qty_stock ORDER BY quantita_minima_stock DESC LIMIT 1`.

`apiOttieniPrezzo()` (in entrambi i controller) accetta un parametro GET opzionale `unita_misura_id` (unità in cui il chiamante esprime la quantità; assente = retrocompatibile, assume già Stock UoM), converte prima di chiamare le funzioni sopra.

`apiSaveRiga()`/`apiImportPrezzi()` (entrambi i cicli) calcolano e salvano le tre colonne canoniche (§2) a ogni INSERT/UPDATE. Il controllo anti-duplicato server-side confronta `quantita_minima_stock`, non il valore grezzo (altrimenti un doppione mascherato supererebbe il controllo applicativo per poi far fallire l'INSERT contro il vincolo `UNIQUE` con un errore SQL grezzo invece di un messaggio comprensibile).

I due controlli anti-duplicato **client-side** (`app/views/sales/pricelists/_righe.php`, `app/views/purchases/pricelists_form.php`, funzione `runBusinessValidations()`) stimano il valore canonico usando i dataset già presenti (`data-factor`, `data-uom-commercial-id` o equivalenti) — sono solo una stima per UX immediata; il controllo autorevole resta sempre il vincolo `UNIQUE` server-side.

---

## 6. Validazione "terza unità" fornitore

`prodotti_fornitori.unita_misura_acquisto_id` deve sempre coincidere con `unita_misura_stock_id` o `unita_contenuto_id` del prodotto — mai una terza unità priva di fattore di conversione noto. Validato server-side in `ProductsController::saveForn()` e in `PurchasesController::apiSaveRiga()` (quest'ultimo tramite il controllo "terza unità" aggiunto il 14/07/2026): se il valore ricevuto non coincide con una delle due unità ammesse, il salvataggio viene rifiutato con errore esplicito ("unità di acquisto non valida per questo prodotto").

---

## 7. Import massivo (Excel) — colonna soglia opzionale

Entrambe le funzioni `apiImportPrezzi()` (vendita e acquisto) leggono una colonna facoltativa `unita_misura_soglia` (codice UoM): vuota/assente → retrocompatibile, assume Stock UoM; valorizzata → validata (deve essere Stock o Commerciale del prodotto, stesso principio di §6) e usata sia come unità di quotazione sia come unità della soglia.

Righe modificate manualmente dopo l'ultimo import non vengono sovrascritte silenziosamente: ogni riga ha `importato_il`/`modificato_manualmente_il`; se la modifica manuale è più recente dell'ultimo import, la riga viene saltata e segnalata nel riepilogo finale ("modificato manualmente — non sovrascritto, verificare a mano"). Righe assenti da un nuovo import: chiuse (`valido_al = oggi`) lato acquisti (che ha validità per riga), rimosse lato vendite (che non ce l'ha, per scelta di progetto).

---

## 8. Refactoring MVC — `includes/pricing.php`

`getSalePrice()`/`getPurchasePrice()` erano originariamente metodi dei controller (`SalesPricelistsController`, `PurchasesController`), con logica di business mescolata a gestione HTTP. Estratti verbatim in `includes/pricing.php` come funzioni pure (nessuna dipendenza da `$_GET`/`$_POST`/`header()`), riusabili da CLI o cron futuri. I controller ora sono wrapper:

```php
public function getSalePrice(int $prodottoId, int $listinoId, float $quantita = 1.0, ?string $dataRiferimento = null, ?int $clienteId = null): ?array
{
    return qk_get_sale_price($this->pdo, $prodottoId, $listinoId, $quantita, $dataRiferimento, $clienteId);
}
```

Comportamento verificato invariato (stessa logica, stessi risultati) prima e dopo il refactoring.

---

## 9. Numerazione documenti in nuova versione

Pattern corretto per la preservazione del numero radice con suffisso incrementale (usato da `qk_ordine_crea_revisione()` e — dal 14/07/2026 — da `qk_rfq_nuova_versione()`):

```php
$nuovaVersione = (int)$documento['versione'] + 1;
$baseNumero    = preg_replace('/ v\d+$/', '', $documento['numero']);
$nuovoNumero   = $baseNumero . ' v' . $nuovaVersione;
```
Mai `qk_next_numero_acquisto()` (o equivalente) per una nuova versione dello stesso documento — quello genera un numero sequenziale nuovo, "bruciando" il collegamento visivo con la pratica originale.

---

## 10. Checklist file impattati da questa architettura

**Schema**: `db/init_sqlite.sql`, `db/init_mysql.sql`, `db/migrations/2026_07_14_1_soglie_uom_canoniche.php`, `db/migrations/2026_07_14_2_popola_quantita_minima_stock.php`.

**Backend core**: `includes/prodotti.php` (`qk_uom_converti_riga`, `qk_insert_prodotto`, `qk_update_prodotto`, `qk_ricalcola_bozze_per_cambio_stock_uom`), `includes/pricing.php` (`qk_get_sale_price`, `qk_get_purchase_price`).

**Backend vendite**: `app/Controllers/SalesPricelistsController.php`, `includes/sales_quotes.php` (`qk_calcola_riga_offerta`).

**Backend acquisti**: `app/Controllers/PurchasesController.php`, `app/Controllers/ProductsController.php` (`savePrezzo`, `saveForn`), `includes/purchases_rfq.php` (`_rfq_insert_righe`, `qk_rfq_nuova_versione`), `includes/purchases_orders.php` (`qk_ordine_crea_revisione`).

**Frontend**: `assets/js/app.js` (`qkConvertUom`), `assets/js/sales-quotes.js` (`fetchQuotePrice`), `assets/js/rfq-form.js`, `assets/js/products-form.js` (comportamento azzeramento campo su cambio Stock UoM), `app/views/sales/pricelists/_righe.php`, `app/views/purchases/pricelists_form.php`.

**Script di audit**: `db/scripts/audit_soglie_uom.php` (sola lettura — trova righe già coerenti, da convertire, e doppioni mascherati).

---

## 11. Verifica e strumenti usati in questa sessione

Data l'assenza di un ambiente PHP CLI completo nel sandbox di sviluppo, la verifica di questa architettura ha usato:
- **`php-wasm`** (interprete PHP reale via WebAssembly, Node): per la matematica di conversione (bcmath) — 9 casi numerici sulla convenzione num/den, formula prezzo/quantità.
- **`node:sqlite`** (database SQLite reale, nativo Node 22+): per eseguire lo schema `init_sqlite.sql` reale e le query SQL identiche a quelle scritte nel codice, verificandone la sintassi e i risultati contro dati di prova.
- **Parser AST PHP reale** (`php-parser`, Node): per verificare la validità sintattica di ogni file toccato, invece di rileggere il codice a occhio.

Questo approccio ha permesso di scoprire e correggere due bug reali che una semplice rilettura del codice non aveva individuato: l'inversione della convenzione `num_factor`/`den_factor` (§1) e diversi punti di scrittura che non popolavano le nuove colonne canoniche.
