# PIANO — Eliminazione SQL Grezzo nei Controller (Regola Aurea) — 4.A.4 + 4.C.1

> ✅ **STATO REALE VERIFICATO IL 24/07/2026 (sera) — IL PIANO RISULTA ESEGUITO PER INTERO, non "pronto per l'esecuzione" come dichiarava questo documento fino a poco fa.** Questo documento era rimasto fermo alla fotografia del 23/07/2026 (sotto, §0), che già correggeva un conteggio ancora precedente — ma nel frattempo (fuori da questa conversazione, nessuna sessione lo ricorda) **tutto il lavoro descritto nelle Tappe 6, 7 e 8 è stato scritto ed è oggi in produzione**, verificato leggendo il codice reale, non la documentazione:
>
> - **Conteggio query dirette nei 4 controller, rifatto da zero il 24/07**: `SalesOrdersController.php` → 0, `SalesQuotesController.php` → 0, `SpedizioniController.php` → 0, `SalesPricelistsController.php` → **0** (non più le 20 residue dichiarate qui sotto al §0). Verificato con una ricerca esaustiva di `->query(`/`->prepare(`/`->exec(` in tutti e quattro i file: zero occorrenze.
> - **Tappa 6 (`qk_elabora_crea_spedizione()`), fatta**: la funzione (`includes/sales_orders.php:580-589`) è oggi un guscio di 10 righe che istanzia `\App\Repositories\SpedizioneRepository` e `\App\Services\SpedizioneCalculatorService` e delega tutto a `creaSpedizione()` — esattamente la struttura proposta al §4.3. La duplicazione 4x dell'`INSERT INTO movimenti_magazzino` è stata eliminata: esiste un solo `SpedizioneRepository::insertMovimentoScarico()` (righe 212-219), con un commento nel codice che conferma la provenienza ("Tappa 6 (§4.2/4.4.4 del piano): elimina la duplicazione 4x..."). La nota su `movimenti_magazzino.documento_id` mai valorizzato (§4.2) resta com'era: non popolato, dichiarato esplicitamente fuori scope nel commento del metodo — quel punto specifico è l'unico rimasto davvero aperto di tutta la Tappa 6.
> - **Tappa 7/8 (`SalesPricelistsController`), fatta**: il file richiede in testa `app/Repositories/ListinoPrezziRepository.php` e `app/Services/ListinoImportService.php` (righe 10-13, con commento "Tappa 8 (PIANO - Eliminazione SQL Grezzo nei Controller, problema 4.C.2)"), entrambi i file esistono e sono popolati.
> - **Effetto collaterale scoperto per caso, non previsto da questo piano**: il ramo "senza lotto" di `SpedizioneCalculatorService` oggi chiama `getClassificazioneAdr()` esattamente come il ramo a lotto (righe 308-309 vs 265-266) — il gap ADR sui prodotti non tracciati a lotto (Problema D3 della REVISIONE) risulta chiuso come conseguenza di questa stessa estrazione, non per un intervento dedicato. Vedi `REVISIONE - Analisi Documentazione Ciclo Vendite.md` §3bis, D3, aggiornato di conseguenza.
> - **Secondo effetto collaterale**: la numerazione del DDT (Problema D2) passa oggi da `qk_next_sequence_number($this->pdo, 'documento_trasporto')`, non più da `COUNT(*)` — quindi azzera il progressivo a ogni cambio anno e usa un lock `BEGIN IMMEDIATE` reale, chiudendo anche quel problema come effetto collaterale del refactoring.
>
> **Come è stato verificato**: lettura diretta con lo strumento di lettura (mai la shell) di tutti i file citati sopra, non deduzione dai commit o dai commenti da soli — coerente con la regola del progetto sulla staleness della copia shell.
>
> **Quello che resta davvero aperto di questo piano**: solo `movimenti_magazzino.documento_id` mai popolato (§4.2), esplicitamente fuori scope per scelta, non per dimenticanza — da affrontare come intervento a sé se interessa ancora.
>
> Il contenuto originale del piano (sotto) resta come riferimento storico di cosa è stato deciso e perché, ma **non descrive più lo stato del codice**: chi riprende questo documento non deve rileggerlo come "da fare", va letto come "già fatto, ecco come".

> **Stato aggiornato il 23/07/2026, dopo verifica riga-per-riga dello stato reale del codice** (non del testo dell'audit). Vedi §0 per cosa è cambiato rispetto alla versione precedente di questo documento.
>
> **Ambito di questa revisione: SOLO 4.A.4 (SQL grezzo nei controller) e 4.C.1 (God Object `elaboraCreaSpedizione`).** Le altre tappe del piano originale (require_once fuori posto, CSRF, CSS duplicato, docblock, "segna fatturato", unificazione filosofie ordine, riduzione duplicazione PDF/DOCX) NON sono state riverificate in questa sessione e restano fuori scope — non toccarle assumendo che lo stato descritto altrove in questo file sia ancora accurato senza prima rileggerle (CLAUDE.md punto 9).
>
> **Decisioni confermate dall'utente in questa sessione (23/07/2026):**
> 1. Il test `suite_transition_guard_tappa7_ordini_clienti.php` (2 asserzioni fallite per disallineamento architetturale, non per un bug — vedi `audit/scoperte_audit.md` Tappa 0.5) va **riscritto per chiamare direttamente `qk_elabora_crea_spedizione()`**, non più il metodo del controller via Reflection.
> 2. Questo documento va **corretto e riusato**, non sostituito da uno nuovo.
>
> Decisioni ereditate dalla versione precedente di questo piano, ancora valide per lo scope 4.C.1:
> - Filosofia dei test: mai fidarsi di `php -l` da solo, ogni fix verificato con script reale (Protocollo della Simulazione Logica, `.agents/AGENTS.md` §5).
> - Le funzioni estratte da codice transazionale non aprono/chiudono transazioni proprie.
> - "`SELECT *` resta `SELECT *`" quando si estrae una query esistente, per non rompere una vista che si aspetta colonne non elencate esplicitamente.

---

## 0. Cosa è cambiato rispetto alla versione precedente di questo documento

La versione precedente dichiarava "🔲 SOLO PIANO, NESSUN FILE TOCCATO" e contava 78 query dirette in 4 controller. Verificando oggi il codice reale con lo strumento di lettura (mai la shell, per la regola CLAUDE.md punto 1 — il mount shell di questa sessione è risultato desincronizzato, `wc -l` su `SalesOrdersController.php` restituiva 515 righe contro le ~1013 attese dal vecchio conteggio), risulta che gran parte del lavoro è **già stata fatta in sessioni precedenti, senza essere documentata come tale**:

| Controller | Query dirette (conteggio vecchio) | Query dirette **oggi**, verificate leggendo il file per intero |
|---|---|---|
| `SalesOrdersController.php` | 33 | **0** |
| `SalesQuotesController.php` | 9 | **0** |
| `SpedizioniController.php` | 7 | **0** |
| `SalesPricelistsController.php` | 29 | **20** |

Le "8 duplicazioni nome cliente" sono oggi **2**, entrambe in `SalesPricelistsController.php` (righe 673 e 759) — esiste già `qk_nome_cliente_sql_fragment()` in `includes/anagrafiche.php`, gli altri 6 punti la usano già.

`elaboraCreaSpedizione()` **non è più un metodo di ~400 righe nel controller**: è un wrapper privato di 10 righe (`SalesOrdersController.php:504-514`) che chiama `qk_elabora_crea_spedizione()`, una funzione di **324 righe** (`includes/sales_orders.php:573-896`, letta per intero). Il God Object si è spostato da controller a helper, non è stato smontato — contiene ancora **21 query dirette embedded** e una violazione DRY interna non presente nell'audit originale: lo stesso `INSERT INTO movimenti_magazzino (...)` scritto **4 volte** quasi identico (righe 791, 835, 848, 862).

Effetto collaterale scoperto e documentato in `audit/scoperte_audit.md` Tappa 0.5: il test `suite_transition_guard_tappa7_ordini_clienti.php` chiama il metodo del controller via Reflection aspettandosi un'eccezione quando l'ordine è in stato terminale — ma il controller oggi cattura sempre l'eccezione (`catch (\Throwable $e) { ...; flash_set('error', ...); }`, mai un `throw` di rilancio), perché è il comportamento corretto per l'uso HTTP reale. Il test è rimasto scritto per l'architettura precedente. **Decisione presa in questa sessione: riscrivere il test (§2).**

**Conclusione pratica:** il lavoro reale rimasto per 4.A.4+4.C.1 è molto più piccolo del previsto — concentrato in `SalesPricelistsController.php` (13 query CRUD/assegnazione + 7 query nel God Object `apiImportPrezzi()`, problema 4.C.2 collegato) e nella riorganizzazione di `qk_elabora_crea_spedizione()` (324 righe, oggi in `includes/`, non più nel controller ma ancora monolitica e con una duplicazione interna).

---

## 1. Tappa 0.5 — Correggere il test disallineato (da fare PRIMA di toccare `qk_elabora_crea_spedizione()`)

**Perché prima:** la Tappa 6 (§4) sposterà codice dentro `qk_elabora_crea_spedizione()`. Se il test di regressione per quella funzione è già rotto per un motivo indipendente, ogni esecuzione della suite durante la Tappa 6 mostrerà 2 fallimenti "di fondo" che è facile confondere con un fallimento causato dalla modifica in corso. Va sistemato per primo, così la baseline è pulita (0 fallimenti) prima di iniziare a toccare la funzione.

**Cosa cambia esattamente**, in `tests/manual/suite_transition_guard_tappa7_ordini_clienti.php`:

- Le righe 214-237 definiscono `chiamaElaboraCreaSpedizione()`, che oggi costruisce `$_POST`/`$_SESSION` e invoca `SalesOrdersController::elaboraCreaSpedizione()` via `ReflectionMethod`. Questa funzione di supporto del test va **sostituita** con una che chiama direttamente `qk_elabora_crea_spedizione($pdo, $orderId, $postData, $filesData, $userId)` — la vera firma pubblica della funzione in `includes/sales_orders.php:573`, dove l'eccezione viene lanciata davvero (il `catch` che la assorbe vive solo nel wrapper del controller, mai in questa funzione).
- I due `chkThrows(...)` alle righe 244-248 e 258-262 restano invariati nella struttura (stessa asserzione, stesso messaggio atteso), cambia solo la funzione richiamata internamente.
- Il caso "ammesso end-to-end" (righe 264-279) e il caso "self-loop" (righe 281-287) vanno aggiornati allo stesso modo — oggi passano anch'essi da `chiamaElaboraCreaSpedizione()`.
- Non serve più costruire `$_SESSION['qk_user']`/`$_POST` globali (quello serviva solo per simulare l'HTTP che il controller si aspettava) — si passano `$postData`/`$filesData`/`$userId` come argomenti diretti alla funzione, più esplicito e meno fragile.
- **Non toccare** il resto del file (righe 1-213): quelle asserzioni (`qk_ordine_cliente_completa`, `_riapri`, `_annulla`, `_aggiorna_note`) chiamano già le funzioni reali direttamente, non passano dal controller, e oggi passano tutte — nessuna modifica necessaria.

**Verifica (obbligatoria, non un "dovrebbe funzionare"):** eseguire `php tests/manual/suite_transition_guard_tappa7_ordini_clienti.php`, leggere l'output riga per riga, confermare `OK: N  FALLITI: 0` con lo stesso numero di asserzioni `OK` di prima (nessuna asserzione persa, solo il meccanismo di chiamata cambiato) ed `exit code 0`. Solo a questo punto procedere con la Tappa 6.

---

## 2. Tappe già completate — verificate oggi, nessuna azione richiesta

Elencate per completezza e per evitare che una sessione futura le rifaccia per errore (CLAUDE.md punto 9 vale anche al contrario: non ripetere lavoro già fatto e verificato).

- **`SalesOrdersController.php`**: zero query dirette (letto per intero, 515 righe). L'unico frammento SQL nel file è una stringa passata come valore a `qk_ordini_clienti_list()` per l'ordinamento colonna "totale" (riga 35), non una query eseguita dal controller.
- **`SalesQuotesController.php`**: zero query dirette (letto per intero, 774 righe). Tutto passa da `qk_*` in `includes/sales_quotes.php`/`includes/tabelle_base.php`/`includes/compliance.php` o da `SalesQuoteDocumentService`.
- **`SpedizioniController.php`**: zero query dirette (letto per intero, 171 righe). Tutto delegato a `qk_spedizioni_count/list()`, `qk_get_ordine_per_spedizione()`, `qk_get_righe_ordine_da_spedire()`, ecc.
- **`qk_nome_cliente_sql_fragment(string $alias = ''): string`** (`includes/anagrafiche.php:18-25`): esiste già, gestisce sia SQLite (`||`) sia MySQL (`CONCAT`) in base a `DB_DRIVER`. Usata correttamente in `includes/sales_orders.php` (righe 327, 350, 458, 479), `includes/pricelists.php` (righe 126, 239). **Non usata** in `SalesPricelistsController.php:673,759` — parte della Tappa 7 (§5.5).
- **Prerequisito bug 3.1/3.2 di `qk_elabora_crea_spedizione()`**: confermato presente nel codice letto oggi — righe 787-789/805-809/859-861 usano `$magazzinoRiservaId` (congelato su `ordini_clienti.magazzino_id`) per il rilascio riserva, distinto da `$magazzinoSpedId` per lo scarico fisico; il ramo Kit (righe 826-838) seleziona lotti via `qk_seleziona_lotti_fefo()` e decrementa `lotti_giacenze` per ogni componente tracciato. Prerequisito della vecchia Tappa 6 soddisfatto — resta solo l'estrazione (§4).

---

## 3. Implicazioni generali per §4-§6 — cosa può rompersi

1. **Nomi di colonna nel risultato.** Se una query è `SELECT *`, la funzione estratta resta `SELECT *`. Verificato: nessuna delle query coinvolte in §4-§6 usa `SELECT *` tranne `SELECT * FROM ordini_clienti WHERE id = ?` (riga 592 di `qk_elabora_crea_spedizione`, già dentro un helper — se ulteriormente estratta, resta `SELECT *`).
2. **Riuso non verificato.** Prima di scrivere una funzione nuova, verificato se ne esiste già una equivalente (regola CLAUDE.md punto 1). In §5 questo controllo ha già trovato 2 casi concreti: `qk_listino_insert()`/`qk_listino_update()` esistono già in `includes/pricelists.php` ma non sono usate da `SalesPricelistsController::create()/edit()`; `qk_update_clienti_listino()` esiste già ma non è chiamata da nessuna parte del progetto (verificato con grep esaustivo — oggi è codice morto).
3. **Transazioni.** Le funzioni estratte da codice già transazionale (dentro `qk_elabora_crea_spedizione()` o dentro il `beginTransaction()` di `apiImportPrezzi()`) non aprono/chiudono transazioni proprie.
4. **Vincoli NOT NULL/DEFAULT.** Prima di scrivere o spostare un INSERT, verificato lo schema reale (non la memoria di una sessione precedente, CLAUDE.md punto 2) — dettagli per tabella nelle singole tappe sotto.
5. **`||` vs `CONCAT`.** Nessuna nuova query in questo piano deve introdurre `||` per concatenare stringhe (CLAUDE.md punto 3) — usare sempre `qk_nome_cliente_sql_fragment()` dove serve un nome visualizzato, o `CONCAT()`/branching `DB_DRIVER` per casi nuovi.
6. **Selettori JS.** Nessuna delle tappe seguenti tocca HTML/CSS/ID — sono estrazioni PHP pure (controller/helper → helper/Service), la regola "Logic Bomb" di AGENTS.md non si applica qui, ma va comunque verificato che le CHIAVI dell'array JSON restituito al JS (es. `riga`, `clienti`, `clienti_assegnati`) restino identiche, perché `assets/js/*.js` le legge per nome.

---

## 4. Tappa 6 — Riorganizzare `qk_elabora_crea_spedizione()` (problema 4.C.1, rischio alto)

### 4.1 Implicazioni mappate per intero

La funzione (`includes/sales_orders.php:573-896`) tocca in sequenza 8 tabelle in un'unica transazione: `ordini_clienti` (lettura + update stato finale), `indirizzi_documenti`/`indirizzi` (risoluzione indirizzo a cascata), `spedizioni`, `spedizioni_foto` (upload con MIME sniffing), `documenti_trasporto`, `documenti_trasporto_righe`, `lotti`/`lotti_giacenze`, `giacenze`, `componenti_kit`/`prodotti` (espansione Kit), `movimenti_magazzino`, `ordini_clienti_righe` (update `quantita_spedita`). Un errore nell'estrazione può lasciare una spedizione "a metà" (DDT creato ma giacenza non scaricata) se la transazione non resta esattamente quella di oggi.

**Il modello da seguire esiste già nello stesso dominio**: `App\Services\OrderCalculatorService` + `App\Repositories\SalesOrderRepository` (letti per intero). Pattern osservato: il Service riceve `PDO` + `Repository` nel costruttore, apre `beginTransaction()`, chiama helper globali `qk_*` per la logica di dominio (validazione, conversioni, riserva stock), delega gli INSERT/UPDATE puri al Repository (metodi come `insertOrderHeader()`, `insertOrderLine()`, nessuna transazione propria, nessun try/catch — le eccezioni PDO si propagano al Service), poi `commit()`; nel `catch (Throwable $e)` il Service fa `rollBack()` e rilancia. Stesso identico schema da replicare qui con `SpedizioneCalculatorService` + `SpedizioneRepository`.

### 4.2 Violazione DRY interna da correggere nella stessa tappa (non menzionata dall'audit originale)

L'INSERT `INSERT INTO movimenti_magazzino (prodotto_id, magazzino_id, tipo_movimento, quantita, lotto_id, riferimento_esterno, documento_tipo, creato_da, created_at) VALUES (?, ?, 'scarico', ?, ?, ?, 'ddt', ?, CURRENT_TIMESTAMP)` (o variante con `lotto_id = null`) è scritto identico 4 volte: righe 791 (ramo standalone a lotti), 835 (ramo Kit, componente a lotti), 848 (ramo Kit, componente senza lotti), 862 (ramo standalone senza lotti). Va estratto in un unico metodo `SpedizioneRepository::insertMovimentoScarico(array $data): int` (o funzione `qk_registra_movimento_scarico_spedizione()` se si preferisce restare procedurali per coerenza con `includes/magazzino.php` — **decisione da confermare in fase di esecuzione**, non presa qui: entrambe le forme sono coerenti con pattern già presenti nel progetto, OOP per il "nuovo" Service, procedurale per restare vicino a `qk_riserva_stock_ordine()`/`qk_rilascia_stock_ordine()` che vivono nello stesso file di dominio).

**Osservazione fattuale da segnalare, non da correggere in questa tappa senza autorizzazione esplicita** (CLAUDE.md punto 8): nessuna delle 4 INSERT valorizza `movimenti_magazzino.documento_id`, pur essendo una colonna nullable dedicata esattamente a questo (solo `documento_tipo='ddt'` e `riferimento_esterno=$numDdt` sono valorizzati). Popolare anche `documento_id = $ddtId` migliorerebbe la tracciabilità (join diretto invece di un match testuale su `riferimento_esterno`), ma è un cambio di comportamento (nuovo dato scritto, oggi assente) — va isolato in un passo esplicitamente autorizzato a parte, non mescolato alla riorganizzazione bit-per-bit-equivalente.

### 4.3 Struttura proposta

```
app/Repositories/SpedizioneRepository.php
    insertSpedizione(array $data): int
    insertSpedizioneFoto(array $data): int
    insertDocumentoTrasporto(array $data): int
    insertSpedizioneRiga(array $data): int
    insertDocumentoTrasportoRiga(array $data): int
    insertMovimentoScarico(array $data): int      // elimina la duplicazione 4x
    aggiornaLottoGiacenza(int $lottoId, int $magazzinoId, float $qta): void   // UPSERT righe 729-735
    decrementaGiacenzaDisponibile(int $prodottoId, int $magazzinoId, float $qta): void  // righe 736-740
    getGiacenzaDisponibile(int $prodottoId, int $magazzinoId): float          // righe 741 stCheckGiac
    aggiornaQuantitaSpedita(int $rigaOrdineId, float $qta): void              // riga 742 stUpOrdRiga
    getClassificazioneAdr(int $prodottoId): ?array                           // riga 743 stAdr

app/Services/SpedizioneCalculatorService.php
    creaSpedizione(int $orderId, array $postData, array $filesData, int $userId): array
        // stessa logica di dominio oggi in qk_elabora_crea_spedizione():
        // risoluzione indirizzo a cascata, controllo overshipment, loop righe
        // standalone/Kit/lotti, ricalcolo stato finale — chiamando il
        // Repository sopra per ogni scrittura invece di $pdo->prepare() inline.
```

`includes/sales_orders.php` manterrebbe `qk_elabora_crea_spedizione()` come **wrapper sottile** (stesso pattern già usato da `qk_insert_ordine_diretto()` per il Service esistente):

```php
function qk_elabora_crea_spedizione(PDO $pdo, int $orderId, array $postData, array $filesData, int $userId): array
{
    require_once __DIR__ . '/../app/Repositories/SpedizioneRepository.php';
    require_once __DIR__ . '/../app/Services/SpedizioneCalculatorService.php';
    $repository = new \App\Repositories\SpedizioneRepository($pdo);
    $service = new \App\Services\SpedizioneCalculatorService($pdo, $repository);
    return $service->creaSpedizione($orderId, $postData, $filesData, $userId);
}
```

Nessun chiamante esterno cambia firma: verificato con grep esaustivo che l'unico chiamante di `qk_elabora_crea_spedizione()` è `SalesOrdersController::elaboraCreaSpedizione()` (riga 508) e l'unico chiamante di test è il file corretto in §1 — entrambi continuano a funzionare invariati perché la firma pubblica `(PDO, int, array, array, int): array` non cambia.

### 4.4 Microtappe atomiche, in ordine di rischio crescente

1. **4.4.1 — Creare `SpedizioneRepository` con i soli metodi di LETTURA** (`getGiacenzaDisponibile`, `getClassificazioneAdr`), senza toccare ancora `qk_elabora_crea_spedizione()`. Test: script che chiama i due metodi su dati reali/seed e confronta col risultato delle query originali eseguite a mano sullo stesso DB.
2. **4.4.2 — Sostituire dentro `qk_elabora_crea_spedizione()` SOLO le 2 query di lettura** (righe 741 `stCheckGiac`, 743 `stAdr`) con chiamate al Repository, lasciando tutto il resto invariato. Rilanciare `suite_kit_riserva_snapshot.php` e la suite corretta al §1 — zero regressioni attese (sono sostituzioni bit-equivalenti).
3. **4.4.3 — Aggiungere al Repository i metodi di SCRITTURA semplice** (`insertSpedizioneFoto`, `aggiornaQuantitaSpedita`, `decrementaGiacenzaDisponibile`, `aggiornaLottoGiacenza`), poi sostituirli uno alla volta dentro `qk_elabora_crea_spedizione()`, ri-testando dopo ogni sostituzione (non tutte insieme — se qualcosa si rompe, isolare quale sostituzione è la causa).
4. **4.4.4 — Aggiungere `insertMovimentoScarico()` ed eliminare le 4 INSERT duplicate** (righe 791, 835, 848, 862), sostituendole con 4 chiamate allo stesso metodo. Questo è anche il fix della violazione DRY di §4.2. Test: verificare che il numero di righe inserite in `movimenti_magazzino` per uno scenario con Kit+standalone+lotti sia identico prima/dopo, campo per campo.
5. **4.4.5 — Aggiungere i metodi rimanenti** (`insertSpedizione`, `insertDocumentoTrasporto`, `insertSpedizioneRiga`, `insertDocumentoTrasportoRiga`) e completare l'estrazione della logica di dominio in `SpedizioneCalculatorService::creaSpedizione()`, lasciando in `includes/sales_orders.php` solo il wrapper sottile di §4.3.
6. **4.4.6 — Rimozione fisica del codice vecchio**: a estrazione completata, verificare che `qk_elabora_crea_spedizione()` non contenga più nessun `$pdo->prepare()`/`exec()`/`query()` diretto (grep di conferma), e che il vecchio corpo sia stato cancellato, non lasciato commentato (regola "Logic Bomb"/CLAUDE.md — cancellazione fisica, non commento).

### 4.5 Test end-to-end finale (obbligatorio, Protocollo Simulazione Logica di AGENTS.md)

Scenario che esercita OGNI ramo della funzione in un solo script (estensione di `tests/manual/suite_kit_riserva_snapshot.php`, che copre già gli scenari Kit standalone — riusare il suo seed dove possibile invece di duplicarlo, per DRY nei test stessi): un ordine con (a) una riga standalone tracciata a lotti, (b) una riga standalone non tracciata, (c) una riga Kit con un componente tracciato a lotti e uno non tracciato, magazzino di riserva ORIGINE diverso dal magazzino di SPEDIZIONE (per non perdere la copertura del fix 3.1). Dopo la chiamata reale a `qk_elabora_crea_spedizione()`:
- `spedizioni`/`documenti_trasporto`/`spedizioni_righe`/`documenti_trasporto_righe` contengono le righe attese, con `lotto_id` valorizzato dove previsto (copertura fix 3.2);
- `giacenze.quantita_disponibile` decrementata sul magazzino di spedizione per ogni prodotto/componente coinvolto;
- `lotti_giacenze.quantita_disponibile` decrementata per ogni lotto allocato;
- `giacenze.quantita_riservata` rilasciata sul magazzino ORIGINE, non su quello di spedizione (fix 3.1);
- `movimenti_magazzino` contiene esattamente il numero di righe atteso (uno scarico per allocazione lotto + uno per ogni componente Kit non tracciato + uno per ogni riga standalone non tracciata), tutte con `tipo_movimento='scarico'`;
- `ordini_clienti_righe.quantita_spedita` e `ordini_clienti.stato` aggiornati correttamente.

Eseguire questo identico scenario PRIMA di iniziare la Tappa 6 (per catturare l'output come riferimento — "caratterizzazione prima, conferma dopo") e ripeterlo identico dopo OGNI microtappa di §4.4, confrontando riga per riga, non solo il conteggio totale.

---

## 5. Tappa 7 — `SalesPricelistsController.php`, CRUD e assegnazione clienti (13 query dirette, rischio medio)

Query coinvolte (righe verificate oggi, non quelle — diverse — dell'audit originale): 112-115, 202-210, 366-372, 391-403, 420-422, 441-459, 514-536, 603-611, 671-680, 731-733, 740, 745-748, 757-768.

`includes/pricelists.php` esiste già e contiene già CRUD parziale per `listini` (`qk_listino_insert`, `qk_listino_update`, `qk_get_listino`, `qk_listino_delete`, `qk_listini_list_with_count`) e per l'associazione clienti (`qk_get_clienti_listino`, `qk_update_clienti_listino`, `qk_get_clienti_assegnati_listino`) — è il file dove va aggiunto tutto il resto, non un file nuovo (coerenza + DRY: usare il file di dominio già esistente).

### 5.1 — `create()`/`edit()`: righe 112-115, 202-210 → riuso di funzioni già esistenti, non nuove

`qk_listino_insert(PDO $pdo, array $data): int` e `qk_listino_update(PDO $pdo, int $id, array $data): void` esistono già (`includes/pricelists.php:47-92`) ma **non sono chiamate** da `create()`/`edit()`, che scrivono l'INSERT/UPDATE a mano.

**Verifica di sicurezza fatta PRIMA di proporre il riuso** (perché le firme non sono identiche — `create()` non gestisce mai `is_default`, mentre `qk_listino_insert()`/`qk_listino_update()` sì, e "resettano" l'`is_default` di ogni altro listino se il nuovo valore è 1): la tabella ha un solo listino con `is_default=1` per costruzione (singleton applicativo, "Listino Base Pubblico"). Se si passa sempre `'is_default' => $values['is_default'] ?? 0` (che in `create()` è sempre 0, in `edit()` è il valore letto da DB, quindi identico a se stesso), la query `UPDATE listini SET is_default=0 WHERE is_default=1 AND id != :id` non troverà mai righe da toccare (non esiste un secondo listino con `is_default=1` da azzerare) — comportamento equivalente a oggi, non un'ipotesi, verificato leggendo `qk_listino_update()` per intero e lo schema di `listini`.

**Microtappa:** sostituire il blocco INSERT (righe 112-124) con `$newId = qk_listino_insert($this->pdo, $values);` (passando `'is_default' => 0` esplicito nell'array `$values` di `create()`, che oggi non lo include mai) e il blocco UPDATE (righe 202-219) con `qk_listino_update($this->pdo, $id, $values);` (passando `'is_default' => $values['is_default']`, già presente nell'array). Cancellare fisicamente le query manuali.

**Test:** creare un listino via `create()` prima e dopo, confrontare la riga inserita in `listini` campo per campo; modificare un listino via `edit()` (incluso il caso limite: modificare IL listino che ha `is_default=1`) prima e dopo, verificare che nessun altro listino abbia subito modifiche a `is_default`.

### 5.2 — `apiSaveRiga()`: righe 366-372, 391-403, 420-422, 441-459 → nuove funzioni in `includes/pricelists.php`

Nessuna funzione equivalente trovata con la ricerca preventiva (verificato: `qk_get_listino_items()` esiste ma serve per la lista paginata, non per una singola riga/duplicato). Da creare, con firme che rispettano `SELECT` esplicite (non `SELECT *`, la query 441-459 ha 12 colonne/alias precisi usati dal JS come `prodotto_nome`, `sku_interno`, `unita_misura`, `uom_stock_id`, `costo`, ecc. — copiare l'elenco esatto, non riscriverlo a memoria):

```
qk_listino_prezzo_riga_esiste_duplicato(PDO $pdo, int $listinoId, int $prodottoId, float $qMinStock, int $escludiRigaId): bool   // righe 366-372
qk_listino_prezzo_riga_update(PDO $pdo, int $rigaId, int $listinoId, array $data): void                                          // righe 391-403
qk_listino_prezzo_riga_insert(PDO $pdo, array $data): int                                                                        // righe 420-422
qk_listino_prezzo_riga_dettaglio(PDO $pdo, int $rigaId): ?array                                                                  // righe 441-459, SELECT esplicita invariata
```

**Test:** per ciascuna, chiamata diretta su dati seed reali con confronto valore-per-valore rispetto all'esecuzione della query originale sullo stesso input; poi test end-to-end su `apiSaveRiga()` (creazione riga, modifica riga, tentativo di duplicato — deve restituire lo stesso messaggio di errore di oggi) con verifica della risposta JSON completa, non solo `ok:true/false`.

### 5.3 — `apiProdotti()`: righe 514-536 → nuova funzione, NON unificare con `qk_search_prodotti_orders()`

Verificato (ricerca preventiva, CLAUDE.md punto 1): esiste già `qk_search_prodotti_orders()` in `includes/sales_orders.php:241-287`, stesso scopo generale (autocomplete prodotti) ma **query sostanzialmente diversa** — usa `v_costo_acquisto_prodotti` (non `v_prezzo_acquisto_base_fornitore`), gestisce esplicitamente il costo aggregato dei Kit con una `SUM` su `componenti_kit`, limite 60 righe (non 20), niente ricerca su `ean`. Unificarle forzatamente cambierebbe il comportamento di uno dei due form. **Decisione: estrarre come funzione propria**, non riusare né modificare quella esistente:

```
qk_pricelist_search_prodotti(PDO $pdo, string $q): array   // righe 514-536, SELECT esplicita invariata
```

**Test:** confronto diretto prima/dopo su una ricerca reale con risultati noti (incluso un prodotto Kit, per verificare che il comportamento — oggi diverso da `qk_search_prodotti_orders` sul costo — resti quello di oggi e non venga "corretto" per errore durante l'estrazione).

### 5.4 — `apiOttieniPrezzo()`: righe 603-611 → nuova funzione

```
qk_pricelist_get_rrp_costo(PDO $pdo, int $prodottoId): ?array   // righe 603-611, SELECT esplicita invariata
```

**Test:** confronto diretto prima/dopo su un prodotto con RRP configurato e uno senza (deve restituire `null`/riga vuota coerente con oggi).

### 5.5 — Deduplicazione "nome cliente": righe 673, 759

Sostituire `COALESCE(cf.ragione_sociale, TRIM(COALESCE(cf.nome,'') || ' ' || COALESCE(cf.cognome,'')))` con `{$nomeClienteSql}` dove `$nomeClienteSql = qk_nome_cliente_sql_fragment('cf');`, in entrambi i punti — stessa identica sostituzione già fatta con successo in 6 punti di `includes/sales_orders.php` e `includes/pricelists.php` (righe 327, 350, 458, 479, 126, 239), stesso schema da replicare, zero rischio di comportamento diverso (la funzione produce byte-per-byte la stessa espressione SQL per SQLite).

**Test:** eseguire la query prima e dopo sullo stesso set di clienti reali (inclusi quelli con `ragione_sociale` NULL e quelli con `nome`/`cognome` NULL), confrontare l'output stringa-per-stringa.

### 5.6 — `apiAssegnaClienti()`: righe 731-733, 740, 745-748 → riuso di `qk_update_clienti_listino()`, già esistente

**Verifica di sicurezza fatta PRIMA di proporre il riuso**: la funzione esiste (`includes/pricelists.php:150-182`) ma non è chiamata da nessuna parte del progetto oggi (verificato con grep esaustivo su tutto il codebase — è codice morto). Il suo INSERT `INSERT INTO clienti_listini (cliente_id, listino_id, created_at) VALUES (?, ?, CURRENT_TIMESTAMP)` **non valorizza `priorita`**, mentre il codice manuale del controller lo fa esplicitamente (`priorita) VALUES (?, ?, 10)`). Controllato lo schema reale (`db/init_sqlite.sql:313-321`, CLAUDE.md punto 2): `priorita INTEGER NOT NULL DEFAULT 10` — il default a livello DB è esattamente lo stesso valore che il controller scrive a mano. **Le due forme sono equivalenti byte-per-byte nel risultato**, non solo "probabilmente equivalenti" — verificato sullo schema, non assunto.

**Microtappa:** sostituire il blocco diff-sync (righe 731-752, SELECT esistenti + DELETE + INSERT loop) con `qk_update_clienti_listino($this->pdo, $listinoId, $clienteIds);`. Notare che la funzione esistente apre una propria `beginTransaction()`/`commit()` (righe 152-181) — il controller oggi apre GIÀ la sua transazione alla riga 721 prima del blocco: verificare a runtime se questo causa un conflitto (PDO SQLite non supporta transazioni annidate native). **Se la funzione viene chiamata dentro una transazione già aperta dal controller, la sua `beginTransaction()` interna fallirà** — questo è un dettaglio che il riuso diretto non può ignorare. Due opzioni, da verificare quale sia più corretta leggendo il chiamante per intero prima di scegliere: (a) rimuovere `beginTransaction()`/`commit()`/`rollBack()` da `qk_update_clienti_listino()` per renderla "componibile" dentro la transazione del chiamante (stesso pattern di `qk_riserva_stock_ordine()`), visto che è comunque usata da un solo punto dopo questa modifica; (b) rimuovere invece la `beginTransaction()` del controller e lasciare che sia `qk_update_clienti_listino()` a gestire la propria transazione, spostando FUORI da essa la query di reload (righe 757-768, che oggi vive dopo il `commit()` del controller). L'opzione (a) è preferibile per coerenza con lo stile del resto del progetto (le funzioni "helper" non aprono transazioni proprie quando pensate per essere composte), ma va confermato che nessun altro futuro chiamante di `qk_update_clienti_listino()` si aspetti che apra la propria transazione.

**Test:** assegnazione clienti con un mix di aggiunte/rimozioni/clienti-che-restano-uguali su dati reali, verificando che `clienti_listini` risulti identica prima/dopo la modifica per lo stesso input (incluso il caso limite già corretto il 13/07/2026 e documentato nel controller: un cliente che resta assegnato non deve perdere `priorita`/`data_inizio`/`data_fine` — la diff-sync non tocca le righe invariate).

### 5.7 — Query di reload dopo assegnazione: righe 757-768

Diversa da `qk_get_clienti_assegnati_listino()` già esistente (quella unisce solo `dettagli_clienti`, questa unisce anche `clienti_listini` con `OR` e `DISTINCT`) — **non unificare senza verificare prima se sono davvero la stessa cosa concettuale o due viste intenzionalmente diverse** (una potrebbe essere "clienti con QUESTO come listino di default", l'altra "clienti assegnati in QUALSIASI modo, default o esplicito"; da confermare leggendo dove ciascuna viene usata nel frontend prima di decidere se unificare). Nel dubbio, estrarre come funzione separata:

```
qk_get_clienti_assegnati_dopo_sync(PDO $pdo, int $listinoId): array   // righe 757-768, SELECT esplicita invariata
```

**Test:** confronto diretto prima/dopo su uno scenario reale con clienti assegnati sia via `dettagli_clienti` sia via `clienti_listini`.

---

## 6. Tappa 8 — `apiImportPrezzi()` (7 query dirette + logica di business, problema 4.C.2, rischio alto)

**Implicazioni:** ~211 righe (780-991), tutte dentro un'unica transazione (`beginTransaction()` riga 811, `commit()` riga 976). Fa, in un solo metodo di controller: parsing riga per riga di un array JSON caricato dal client, risoluzione prodotto per SKU, risoluzione unità di misura opzionale per la soglia, conversione UoM (`qk_uom_converti_riga()`), calcolo valore canonico (`quantita_minima_stock`), dedup contro righe esistenti sullo stesso scaglione canonico, **regola "non sovrascrivere se modificato a mano dopo l'ultimo import"** (confronto `modificato_manualmente_il` vs `importato_il`), rimozione righe orfane (presenti in un import precedente ma non più nel file corrente). Un errore qui rischia di corrompere un intero listino prezzi — rischio commerciale diretto, non solo di stile.

**Nessun bug comportamentale noto da correggere prima** (a differenza della Tappa 6) — qui l'estrazione è pura riorganizzazione, bit-per-bit equivalente.

### 6.1 Struttura proposta

```
app/Services/ListinoImportService.php
    importaRighe(int $listinoId, array $rows): array   // ritorna ['inserted'=>,'skipped'=>,'removed'=>,'errors'=>[]]
        // orchestratore: apre/chiude la transazione, richiama i metodi sotto per ogni riga

app/Repositories/ListinoPrezziRepository.php   (o estendere un repository già esistente se emerge in fase di esecuzione che ce n'è uno adatto — verificare prima di crearne un altro)
    trovaProdottoPerSku(string $sku): ?array
    risolviUnitaMisuraPerCodice(string $codice): ?int
    trovaRigaEsistente(int $listinoId, int $prodottoId, float $qMinStock): ?array
    aggiornaRiga(int $rigaId, array $data): void
    inserisciRiga(array $data): int
    trovaRigheOrfane(int $listinoId, array $touchedIds): array
    rimuoviRighe(array $ids): int
```

La logica di validazione riga (SKU vuoto, prezzo non numerico, unità soglia non valida, regola "modificato a mano") resta nel Service (è business logic, non accesso dati) — solo le 7 query dirette (righe 824, 844, 889-892, 917-926, 939-941, 966, 971) migrano nel Repository.

### 6.2 Microtappe atomiche

1. **6.2.1** — Creare `ListinoPrezziRepository` con i 2 metodi di sola lettura semplice (`trovaProdottoPerSku`, `risolviUnitaMisuraPerCodice`), sostituirli dentro `apiImportPrezzi()`, ritestare con un import reale di righe valide (nessuna riga saltata) prima/dopo.
2. **6.2.2** — Aggiungere `trovaRigaEsistente`/`trovaRigheOrfane`, sostituire, ritestare con un import che include righe duplicate/orfane rispetto a un import precedente — stesso conteggio `skipped`/`removed` prima/dopo.
3. **6.2.3** — Aggiungere `aggiornaRiga`/`inserisciRiga`/`rimuoviRighe`, sostituire le ultime 3 query, ritestare l'intero flusso.
4. **6.2.4** — Solo a questo punto, spostare l'orchestrazione (il ciclo `foreach ($rows as ...)`, la regola "modificato a mano", il calcolo `$qMinCanonico`) nel `ListinoImportService`, lasciando in `SalesPricelistsController::apiImportPrezzi()` solo: lettura `$_POST`, chiamata al Service, `echo json_encode(...)`.

### 6.3 Test (obbligatorio, scenario completo)

Un solo import che copre in un colpo solo tutti i rami: (a) una riga nuova (SKU non presente nel listino) → deve comparire in `listini_prezzi`; (b) una riga che aggiorna uno scaglione esistente non modificato a mano → deve aggiornarsi; (c) una riga che punta a uno scaglione esistente ma modificato a mano dopo l'ultimo import → deve restare INTATTA (stesso `prezzo_unitario` di prima, comparire in `errors` con il messaggio corretto, contare come `skipped`); (d) una riga con SKU inesistente → `skipped` con messaggio corretto, nessuna scrittura; (e) una riga orfana (presente da un import precedente, assente da questo file) → deve essere rimossa da `listini_prezzi`; (f) una riga con unità soglia dichiarata non compatibile col prodotto → `skipped` col messaggio corretto. Verificare lo stato di `listini_prezzi` campo per campo dopo l'esecuzione, non solo i contatori `inserted`/`skipped`/`removed` restituiti dal JSON — un contatore corretto non garantisce che la riga scritta abbia i valori giusti.

---

## 7. Regola di verifica per OGNI microtappa di questo documento

1. **Mai fidarsi di `php -l` da solo** — ogni fix verificato con uno script reale che invoca il codice vero (funzione/metodo/Service), mai un mock, per la regola esplicita di `.agents/AGENTS.md` §"Protocollo della Simulazione Logica": setup DB reale (anche in-memory), chiamata reale, lettura byte/JSON/righe DB risultanti, `exit(0)` come condizione di successo.
2. **Caratterizzazione prima, conferma dopo** — per ogni query estratta, catturare l'esecuzione della query originale sui dati di test PRIMA della modifica, ripeterla dopo, confrontare l'output campo per campo (non solo "non è andato in errore").
3. **Rilettura del file immediatamente prima di ogni modifica** — non riusare numeri di riga letti in un passo precedente di questo stesso piano: il codice può essere cambiato nel frattempo (CLAUDE.md punto 9), specialmente vero qui perché questa sessione stessa ha già dimostrato che lo stato del codice può disallinearsi silenziosamente dalla documentazione.
4. **Pulizia dati di test** — ogni script crea ed elimina i propri dati (DB in-memory o directory temporanea dedicata, mai il DB reale).
5. **Cancellazione fisica del codice vecchio** ad ogni estrazione completata — mai lasciare la query/il blocco vecchio commentato "per sicurezza" (regola AGENTS.md sulla Logic Bomb, applicata qui anche alle query, non solo a JS/CSS).
6. **Nessuna tappa lascia il progetto in uno stato peggiore del punto di partenza** — se una sessione si interrompe a metà di una tappa (specialmente §4, la più rischiosa), fermarsi a un punto già verificato e funzionante (es. dopo 4.4.2, non a metà di 4.4.3), mai a metà di un'estrazione con query duplicate temporaneamente in due posti.

---

## 8. Ordine di esecuzione consigliato

**1 → 4 → 5 → 6**, cioè: Tappa 0.5 (fix test, §1) come prerequisito bloccante → Tappa 6 (§4, rischio più alto, ma con prerequisito bug 3.1/3.2 già soddisfatto) → Tappa 7 (§5, rischio medio, indipendente dalla 6) → Tappa 8 (§6, rischio alto ma isolato, indipendente da 6 e 7). Le Tappe 7 e 8 possono essere invertite o eseguite in sessioni separate senza conflitti, entrambe indipendenti dalla Tappa 6 (file diversi, nessuna funzione condivisa tra `SalesPricelistsController`/`includes/pricelists.php` e `qk_elabora_crea_spedizione()`/`includes/sales_orders.php`).

**Stato: pronto per l'esecuzione.** Nessun file toccato durante questa sessione di pianificazione (solo lettura). Resta da autorizzare esplicitamente l'inizio ("approvo", "procedi", ecc. — regola AGENTS.md, nessuna esecuzione su via libera implicito), tappa per tappa.
