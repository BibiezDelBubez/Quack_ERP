
# PIANO — Magazzino Esplicito alla Conferma Ordine (P4b)

**Stato:** approvato ed eseguito il 25/07/2026, su autorizzazione esplicita dell'utente ("va bene, procedi"), dopo una fase di analisi/design (DB, backend, frontend, UX/UI) discussa e confermata in chat. Riferimento: `REVISIONE - Analisi Documentazione Ciclo Vendite.md`, §6.6/§6.7bis, per il problema originale; questo piano copre solo P4b (il punto 4 di `PIANO - Vendita Allo Scoperto Controllata (P4).md`, §1, lì lasciato esplicitamente fuori scope).

---

## 1. Il problema, in breve

`qk_magazzino_attivo_id($pdo)` viene letto silenziosamente dalla sessione sia da `qk_preventivo_conferma_ordine()` (`includes/sales_quotes.php:1144`) sia da `SalesOrdersController::create()` (riga 532), per decidere su quale magazzino riservare la merce alla conferma di un ordine cliente. La sessione non riflette una scelta consapevole per QUEL documento: viene aggiornata come effetto collaterale di filtri usati altrove (Giacenze, Riordino, Lotti in scadenza, Movimenti, Trasferimenti — `WarehouseController.php`, righe 74/440/599/897/1093/1140). Né `app/views/sales/orders/_create.php` né `app/views/sales/quotes/_form.php` espongono un campo magazzino.

## 2. Soluzione

Campo magazzino esplicito, obbligatorio, precompilato con un default comodo ma sempre modificabile, su entrambi i percorsi — stesso principio già applicato con successo su "Nuovo movimento" (`createMovement()`) e "Nuova Spedizione" (`SpedizioniController::nuova()`).

## 3. Decisioni confermate

1. **Campo obbligatorio**, blocca il salvataggio se mancante/non valido — stesso trattamento di `magazzino_id` in `createMovement()`, non il pattern "soft" di P4 (che non blocca mai, perché lì la vendita a scoperto è una scelta di business voluta; qui un magazzino mancante è un dato incompleto, non una scelta di business).
2. **Solo magazzini fisici radice** selezionabili (`tipo = 'fisico' AND parent_id IS NULL`) — non ha senso riservare stock ordine su una sede virtuale/conto_terzi/conto_lavoro.
3. **Nessun audit trail dedicato** per ora (chi ha cambiato il magazzino rispetto al default) — overengineering per questo caso, si riprende se emerge un'esigenza concreta.
4. **Percorso preventivo**: il campo NON va nella testata del form (`_form.php`), perché l'intera testata vive dentro un `<fieldset disabled>` non appena il preventivo esce da "bozza" — cioè proprio nel momento in cui la scelta del magazzino conta (stato "inviato", step di accettazione). Va invece come controllo indipendente, fuori da quel fieldset, accanto al bottone "Accetta e Crea Ordine".
5. **Percorso ordine diretto**: nessun pre-check AJAX nuovo — il form resta a submit pieno (PRG) come oggi; cambiare il magazzino prima di inviare non ricalcola nulla lato client, il ricalcolo (incluso l'avviso di scoperto P4) avviene al submit, lato server, sul magazzino effettivamente scelto. Evita di introdurre un'asimmetria/complessità nuova rispetto al resto del form, che oggi non ha alcuna chiamata AJAX.

## 4. Design tecnico

### 4.1 Backend condiviso

`qk_valida_magazzino_ordine_esplicito(PDO $pdo, string $magazzinoParam): ?int` — nuova funzione in `includes/magazzino.php`, accanto a `qk_risolvi_magazzino_filtro()`: valida che il valore POST sia un intero, esista, sia attivo e fisico-radice; null altrimenti (nessun fallback silenzioso — a differenza di `qk_risolvi_magazzino_filtro()`, che è per un filtro di lettura, non per un campo obbligatorio di un documento).

### 4.2 Ordine diretto

- `SalesOrdersController::create()`: in GET, `$values['magazzino_id'] = (string) qk_magazzino_attivo_id($this->pdo)`. In POST, `$magazzinoOrdineId = qk_valida_magazzino_ordine_esplicito($this->pdo, post('magazzino_id', ''))`; se null, `$errors['magazzino_id'] = 'Seleziona il magazzino.'`. Il pre-check di disponibilità P4 (oggi `$magazzinoPerCheck = qk_magazzino_attivo_id($this->pdo)`, riga 125) usa invece `$magazzinoOrdineId` quando valido. Il valore validato viene passato a `qk_insert_ordine_diretto()` → `OrderCalculatorService::insertDirectOrder()` → `SalesOrderRepository::insertOrderHeader()`, che già scrive `ordini_clienti.magazzino_id` (cambia solo la provenienza, non la scrittura) — e alla chiamata di `qk_riserva_stock_ordine()` dentro `insertDirectOrder()`, che oggi riceve il magazzino come parametro (nessuna modifica di firma necessaria, cambia solo il valore passato a monte).
- `_create.php`: nuovo `<select name="magazzino_id">` in testata, stessa riga di "Azienda venditrice", validazione visuale coerente con gli altri campi obbligatori (`.qk-form-error` se `$errors['magazzino_id']`).

### 4.3 Preventivo → ordine

- `qk_preventivo_verifica_disponibilita(PDO $pdo, int $preventivoId, ?int $magazzinoEsplicito = null): array` — nuovo parametro opzionale; se presente, usato al posto di `qk_magazzino_attivo_id($pdo)`.
- `qk_preventivo_conferma_ordine(PDO $pdo, int $id, int $userId, int $magazzinoId, array $venditaScopertoDettaglio = [])` — nuovo parametro obbligatorio `$magazzinoId` (posizionale, 4°, prima di `$venditaScopertoDettaglio` che resta opzionale con default `[]`): sostituisce la chiamata interna a `qk_magazzino_attivo_id($pdo)`. **Nota (25/07/2026):** l'ordine dei parametri qui sopra è quello davvero implementato — la stesura originale di questo punto (`..., array $venditaScopertoDettaglio, int $magazzinoId`, con `$magazzinoId` per ultimo) è stata corretta in fase di scrittura del codice perché avrebbe reso `$venditaScopertoDettaglio` un parametro obbligatorio senza default, rompendo ogni chiamante che lo omette; verificato leggendo `includes/sales_quotes.php:1076` nella stessa sessione in cui è stata scritta questa nota.
- `SalesQuotesController::apiVerificaDisponibilitaOrdine()`: legge anche `magazzino_id` da query string, lo passa al nuovo parametro.
- `SalesQuotesController::action()`, `case 'conferma_ordine'`: legge `post('magazzino_id', '')`, valida con `qk_valida_magazzino_ordine_esplicito()`; se null, redirect con errore "Seleziona il magazzino." (stesso schema del flusso scoperto: mai un crash, sempre un redirect con messaggio chiaro).
- `_form.php`: nuovo `<select name="magazzino_id" form="form-conferma-ordine-preventivo">` fuori dal fieldset disabilitato, accanto al bottone "Accetta e Crea Ordine", visibile solo quando `$stato === 'inviato'`.
- `sales-quotes.js`: il pre-check AJAX include `magazzino_id` nella query string; cambiarlo rilancia il pre-check.

## 5. Batteria di test (protocollo AGENTS.md — esecuzione PHP reale, mai grep/testo come prova)

1. `suite_magazzino_esplicito_ordine_diretto.php` — magazzino esplicito diverso dalla sessione viene rispettato e scritto su `ordini_clienti.magazzino_id`; validazione bloccante se mancante/inesistente/inattivo/non-fisico-radice; il pre-check di disponibilità P4 usa il magazzino scelto, non quello di sessione (scenario: sessione="Bari" con scorta, form magazzino="Milano" scarso → deve comparire l'avviso di scoperto per Milano, non il "tutto disponibile" di Bari).
2. `suite_magazzino_esplicito_preventivo_conferma.php` — stesso scenario sul percorso preventivo, incluso il pre-check AJAX-equivalente (`qk_preventivo_verifica_disponibilita()` col parametro esplicito).
3. Rilancio di tutte le suite esistenti in `tests/manual/` per non-regressione, in particolare quelle di P4 (che condividono `qk_verifica_disponibilita_ordine()`).

## 6. Tappe di esecuzione

1. ✅ Helper `qk_valida_magazzino_ordine_esplicito()` (`includes/magazzino.php:511-523`).
2. ✅ Ordine diretto: controller + vista (`SalesOrdersController.php`, `app/views/sales/orders/_create.php`).
3. ✅ Preventivo → ordine: controller + vista + JS (`SalesQuotesController.php`, `app/views/sales/quotes/_form.php`, `assets/js/sales-quotes.js`).
4. ✅ Batteria di test + non-regressione — vedi §7.
5. ✅ Documentazione con evidenza — questa sezione.

## 7. Evidenza di esecuzione (25/07/2026)

Eseguito con l'interprete PHP reale (`/tmp/phproot/bin/php`, PHP 8.1.2 CLI), non con grep/lettura statica — protocollo AGENTS.md, "Regola sui Controlli". Prima di fidarsi di ciascun risultato, verificato che la copia montata nel sandbox shell non fosse stale (mtime del file confrontato con l'orario reale dell'ultima modifica, CLAUDE.md punto 1).

**Le due nuove suite dedicate a P4b (§5, punti 1-2):**

- `tests/manual/suite_magazzino_esplicito_ordine_diretto.php` → **OK: 20, FALLITI: 0**. Copre: validazione di `qk_valida_magazzino_ordine_esplicito()` su 10 casi (mancante, non numerico, zero, negativo, inesistente, inattivo, virtuale, fisico non-radice, due fisici-radice validi); scenario Bari(scorta)/Milano(scarso) con sessione puntata su Bari e campo esplicito su Milano — il pre-check di disponibilità e la riserva finale seguono davvero il campo esplicito (Milano), non la sessione (Bari, che resta a riserva 0).
- `tests/manual/suite_magazzino_esplicito_preventivo_conferma.php` → **OK: 12, FALLITI: 0**. Stesso scenario Bari/Milano sul percorso preventivo, incluso il pre-check AJAX-equivalente (`qk_preventivo_verifica_disponibilita()` col 3° parametro esplicito) e la conferma finale (`qk_preventivo_conferma_ordine()`); verificata anche la retrocompatibilità: senza il 3° parametro (o con `null` esplicito) la funzione risolve ancora dalla sessione come prima di P4b.

**Non-regressione — tutte le 40 suite in `tests/manual/` rilanciate:**

39 su 40 in esito positivo, incluse le 5 suite pre-esistenti il cui codice chiamante è stato toccato da questa modifica di firma (`qk_insert_ordine_diretto()`, `qk_preventivo_conferma_ordine()`) e quindi aggiornate con il nuovo parametro `$magazzinoId` prima di rilanciarle:

- `suite_ordine_diretto_scoperto_confirm_flow.php` → OK: 18, FALLITI: 0
- `suite_preventivo_conferma_ordine_scoperto.php` → OK: 19, FALLITI: 0
- `suite_preventivo_stato_genere_confirm.php` → OK: 8, FALLITI: 0 (richiesto anche un seed `magazzini` aggiuntivo, non presente prima: la funzione ora richiede un magazzino reale per il vincolo FK `ordini_clienti.magazzino_id`)
- `suite_preventivi_stato_effettivo.php` → OK: 30, FALLITI: 0 (stesso motivo, seed aggiuntivo)
- `suite_transition_guard_tappa6_preventivi.php` → OK: 27, FALLITI: 0 (stesso motivo, seed aggiuntivo)

L'unica suite non in esito pulito è `suite_sds_m1_schema.php` (2 falliti su 34), su un tema estraneo a P4b/vendite/magazzino: la presenza legacy della tabella `sostanze_chimiche_schede` (dominio schede di sicurezza/SDS). Nessun file toccato da questa sessione riguarda quel dominio (verificato con `git status` sui file effettivamente modificati in questa sessione) — segnalato qui per trasparenza, non risolto in questo piano perché fuori scope.
