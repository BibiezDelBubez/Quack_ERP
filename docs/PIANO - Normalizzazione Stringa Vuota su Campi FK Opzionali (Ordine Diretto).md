# PIANO — Normalizzazione stringa vuota su campi FK opzionali (ordine cliente diretto)

> Stato: ✅ **ESEGUITO il 26/07/2026**, su autorizzazione esplicita ("implementa il piano"). Vedi §9 in fondo per l'evidenza di esecuzione del fix principale (§5). **Aggiornamento stesso giorno**: anche la domanda aperta §7 punto 1 (messaggio d'errore grezzo) è stata poi autorizzata ed eseguita — vedi §10. Il punto 2 del §7 (estendere la normalizzazione a `qk_ordine_cliente_crea_revisione()`) resta **deliberatamente non eseguito**, su raccomandazione esplicita motivata (rischio pratico nullo, verificato).

---

## 0. Origine

Durante il piano di unificazione della creazione ordine cliente (eseguito il 26/07/2026), il test end-to-end via HTTP reale ha fatto emergere un errore: un ordine diretto creato lasciando in bianco un campo opzionale (azienda interna, indirizzo di spedizione o contatto) falliva con un errore SQL grezzo invece di creare l'ordine con quel campo semplicemente vuoto. Verificato con `git show` che il bug è preesistente (stesso identico codice nel `SalesOrderRepository` originale), non introdotto da quel piano. Questo documento risponde alla richiesta esplicita di cercare in tutto il resto del codebase varianti dello stesso problema.

## 1. Il meccanismo del bug, in breve

Un campo di un form HTML lasciato vuoto arriva a PHP come stringa vuota `''`, **mai** come `null` — questo è un fatto di HTTP/HTML, non un errore di qualcuno. Il codice PHP di questo progetto usa `$valore ?? null` in molti punti per decidere cosa scrivere sul database quando un campo è assente. Il problema: l'operatore `??` scatta **solo** se la chiave manca del tutto o vale `null` — una stringa vuota `''` non è né l'una né l'altra cosa, quindi `??` la lascia passare intatta. Se quel campo punta a un'altra tabella (una FOREIGN KEY, es. "quale azienda interna" o "quale indirizzo"), il database rifiuta di scrivere una stringa vuota su una colonna numerica che deve corrispondere a un id reale o essere `NULL` — e l'inserimento fallisce.

## 2. Mappa completa — ogni controller controllato, con esito

Ricerca fatta con almeno due metodi indipendenti (grep sui nomi delle funzioni di lettura form `readPostValues`/`readFormValues`/pattern locali, e un secondo grep più ampio su `_id'] ?? null` in tutto il repository, non solo nei controller) per rispettare `CLAUDE.md` punto 12 — un solo pattern di ricerca non è mai sufficiente per concludere "non esiste".

**🔴 Unico caso realmente a rischio:**

| Controller | Campo | Lettura form | Scrittura (a rischio) | Colonna FK | Errore mostrato |
|---|---|---|---|---|---|
| `SalesOrdersController::create()` (ordine diretto) | `azienda_interna_id` | `app/Controllers/SalesOrdersController.php:245` | `includes/sales_orders.php:258` (`qk_ordine_cliente_salva()`) — `?? null` | `db/init_sqlite.sql:1288` — `REFERENCES aziende_interne(id)`, nullable | **Grezzo** — `SalesOrdersController.php:153-155`, nessun messaggio amichevole |
| stesso | `indirizzo_spedizione_id` | `SalesOrdersController.php:256` | `includes/sales_orders.php:270` — `?? null` | `db/init_sqlite.sql:1287` — `REFERENCES indirizzi(id)`, nullable | Stesso, grezzo |
| stesso | `contatto_id` | `SalesOrdersController.php:267` | `includes/sales_orders.php:281` — `?? null` | `db/init_sqlite.sql:1355` — `REFERENCES clienti_fornitori(id)`, nullable | Stesso, grezzo |

Nessuna validazione a monte copre questi 3 campi (`qk_ordine_diretto_validate()`, `includes/sales_orders.php:31-59`, valida solo `cliente_id`/`data_ordine`/righe) — sono genuinamente opzionali sul form, quindi il caso "lasciati in bianco" è un uso normale, non un edge case raro.

**🟢 Stesso pattern di lettura form (produce `''`), ma scrittura già sicura — verificato, non solo assunto:**

| Area | Controller | Come viene neutralizzata la stringa vuota |
|---|---|---|
| Preventivi (Vendite) | `SalesQuotesController` | `!empty(...) ? (int)... : null` per `azienda_interna_id`/`listino_id`; `qk_null_if_empty()` per `contatto_id`/`indirizzo_spedizione_id` — verificato `includes/sales_quotes.php:368-382` |
| Offerte fornitore (Acquisti) | `PurchasesQuotesController` | `!== '' ? (int)... : null` su tutti i campi FK, `qk_offerta_save()`, `includes/purchases_quotes.php` |
| RFQ (Acquisti) | `RfqController` | Stesso pattern, `qk_rfq_save()`, `includes/purchases_rfq.php` |
| Ordini fornitore | `PurchaseOrdersController` | Unico campo FK opzionale già letto con `!== '' ? (int)... : null` alla lettura |
| Clienti | `CustomersController` | `!empty(...) ? (int)... : null`, `includes/anagrafiche.php:307,318`; errore già amichevole (`qk_db_user_message()`) |
| Prodotti | `ProductsController` | `qk_prodotti_null()` (`includes/prodotti.php:12-16`, stesso identico pattern di `qk_null_if_empty()`) su tutti i campi FK; errore già amichevole |
| Aziende interne, Fornitori | `AziendeController`, `SuppliersController` | Nessun campo `_id` scrivibile dal form principale — non applicabile |
| Magazzini | `MagazziniController` | Campi FK gestiti fuori dall'helper condiviso con cast esplicito già sicuro |
| Sostanze chimiche | `SostanzeChimicheController` | Lambda locale dedicata che neutralizza `''`/`null` prima del cast |
| Ricezioni, Prodotti-sottocontroller | vari | Pattern diverso (cast `(int)` diretto, mai `??`), fuori da questo specifico bug |

**Conclusione della mappa**: il progetto ha già, in *quasi ogni altro punto*, il pattern giusto per questo problema (`qk_null_if_empty()`/`qk_prodotti_null()`/`!empty(...) ? (int)... : null`) — l'ordine cliente diretto è rimasto l'unica eccezione, probabilmente perché `SalesOrderRepository::insertOrderHeader()` (il codice originale, ora sostituito) è stato scritto prima che questo pattern si consolidasse come convenzione nel resto del progetto.

## 3. Componenti già esistenti da riusare (DRY) — nessuna nuova funzione

| Funzione | Firma | File | Perché va bene per questo caso |
|---|---|---|---|
| `qk_null_if_empty()` | `qk_null_if_empty(?string $s): ?string` | `includes/anagrafiche.php:72` | Già usata per lo stesso identico scopo in `sales_quotes.php` sullo stesso campo `contatto_id`/`indirizzo_spedizione_id` — comportamento già collaudato, non richiede cast aggiuntivo se si mantiene coerenza con `!empty(...) ? (int)... : null` per i campi numerici |

Non serve introdurre nulla: la correzione userà lo stesso identico idioma `!empty($data['campo']) ? (int)$data['campo'] : null` già presente **due righe sopra**, nella stessa funzione `qk_ordine_cliente_salva()`, per `listino_id` (`includes/sales_orders.php`, verificato in questa sessione) — è letteralmente lo stesso pattern, applicato oggi a un campo e non ancora agli altri tre.

## 4. Conformità a `.agents/AGENTS.md`

- **Regola Aurea (DRY)**: la correzione riusa un idioma già presente nello stesso file, nella stessa funzione — zero nuove funzioni, zero duplicazione.
- **Regola sui Controlli**: ogni riga di questo documento cita `file:riga` verificato con lo strumento di lettura in questa sessione (non dedotto dal nome delle funzioni) — inclusi 4 controlli a campione rifatti personalmente sui risultati della ricerca ampia.
- **Regola sull'Autorizzazione**: nessuna esecuzione senza autorizzazione esplicita — questo è un piano.

## 5. Soluzione proposta

Dentro `qk_ordine_cliente_salva()` (`includes/sales_orders.php`), sostituire:

```php
$data['azienda_interna_id'] ?? null,
...
$data['indirizzo_spedizione_id'] ?? null,
...
$data['contatto_id'] ?? null,
```

con:

```php
!empty($data['azienda_interna_id']) ? (int) $data['azienda_interna_id'] : null,
...
!empty($data['indirizzo_spedizione_id']) ? (int) $data['indirizzo_spedizione_id'] : null,
...
!empty($data['contatto_id']) ? (int) $data['contatto_id'] : null,
```

**Un solo punto di modifica** (la funzione unificata scritta il 26/07/2026), perché è l'unico scrittore reale di `ordini_clienti` in fase di creazione — protegge automaticamente sia il percorso diretto (dove il bug è oggi raggiungibile) sia il percorso da preventivo (dove oggi non lo è, perché i dati arrivano già puliti da una riga di database, ma lo sarebbe comunque se in futuro cambiasse la provenienza dei dati — difesa in profondità, stesso principio già usato altrove in questo progetto, es. `qk_ordine_cliente_crea_revisione()`).

**Non incluso in questa proposta, segnalato ma non deciso** (`CLAUDE.md` punto 8 — non decido da solo cosa fare di una scoperta collaterale): il blocco `catch` di `SalesOrdersController::create()` (righe 153-155) espone comunque il messaggio SQL grezzo per **qualunque** altro errore di database, non solo questo. Correggere solo il bug della stringa vuota lascia comunque la porta aperta a un messaggio brutto per un errore diverso (es. un vincolo UNIQUE, o una FK genuinamente rotta per un altro motivo). Se vuoi, posso includere anche l'adozione di `dbMessage()`/un equivalente in questo controller nello stesso intervento — ma è una decisione a parte, che riguarda l'esperienza utente in generale, non solo questo bug specifico.

## 6. Batteria di test (Protocollo della Simulazione Logica, AGENTS.md)

1. **Test dedicato nuovo** (estensione di `tests/manual/suite_ordine_cliente_salva_unificata.php` o suite a parte): chiamare `qk_ordine_cliente_salva()` con `azienda_interna_id`, `indirizzo_spedizione_id`, `contatto_id` tutti valorizzati a stringa vuota `''` — verificare che NON sollevi eccezione, e che le tre colonne siano scritte come `NULL` reale (query diretta, non assunto).
2. **Test HTTP end-to-end reale** (stesso schema già usato il 26/07/2026, server PHP built-in + DB scratch): login vero, POST reale su `/sales/orders/create` **senza** inviare questi 3 campi (esattamente come farebbe un browser con un `<select>` lasciato sulla voce vuota) — verificare redirect 302 (successo), non un 200 con errore.
3. **Regressione**: rieseguire l'intera batteria `tests/manual/` (43 suite) prima e dopo — nessuna differenza attesa, il cambiamento è più permissivo (accetta un caso che prima falliva), non restrittivo.
4. **Non serve modificare test sugli altri controller** (Preventivi, Acquisti, Anagrafiche, ecc.): la ricognizione ha verificato che sono già sicuri, non vengono toccati da questa correzione — le rispettive suite esistenti restano semplicemente una controprova che nulla lì cambia.

## 7. Domande aperte

1. Confermi la soluzione minimale (solo i 3 campi in `qk_ordine_cliente_salva()`), o preferisci che includa anche la correzione del messaggio d'errore grezzo nel controller (§5, paragrafo "non incluso")?
2. Vuoi che estenda la stessa "difesa in profondità" (normalizzazione anche se in teoria già pulita a monte) anche a `qk_ordine_cliente_crea_revisione()`, l'unica altra funzione che scrive `ordini_clienti`, anche se lì i dati vengono clonati da una riga già esistente sul database (quindi il rischio pratico è nullo, ma il principio sarebbe applicato ovunque per coerenza)?

## 8. Rollback

Nessuna modifica di schema. Una sola funzione toccata (`qk_ordine_cliente_salva()`), tre righe. Revert immediato e privo di rischio.

## 9. Evidenza di esecuzione (26/07/2026)

Autorizzazione ricevuta: "implementa il piano". Eseguita **solo** la soluzione minimale del §5 — le due domande del §7 non sono state decise né eseguite (non toccato il messaggio d'errore del controller, non estesa la normalizzazione a `qk_ordine_cliente_crea_revisione()`).

1. **Rilettura fresca** di `qk_ordine_cliente_salva()` (`includes/sales_orders.php:254-302`) prima di modificare: confermato identico a come descritto nel piano, nessuna modifica intermedia da altre sessioni.
2. **Baseline pre-modifica**: tutte le 43 suite `tests/manual/` eseguite — stessi 2 fallimenti preesistenti e non correlati già noti (`suite_sds_m1_schema.php`, `suite_transition_guard_tappa0.php`), tutto il resto verde.
3. **Correzione applicata**: le tre righe `$data['azienda_interna_id'] ?? null` / `$data['indirizzo_spedizione_id'] ?? null` / `$data['contatto_id'] ?? null` sostituite con `!empty($data['campo']) ? (int) $data['campo'] : null`, stesso idioma già presente nella stessa funzione per `listino_id`. Verificato sintatticamente pulito e UTF-8 valido subito dopo la modifica.
4. **Test dedicato** aggiunto a `tests/manual/suite_ordine_cliente_salva_unificata.php` (nuova sezione 6, 7 asserzioni): chiamata con i 3 campi a stringa vuota → nessuna eccezione, colonne scritte `NULL` vero (non più stringa vuota); più un test di non-regressione con i 3 campi valorizzati con ID reali (aziende_interne/indirizzi/clienti_fornitori seminati appositamente) → continuano a essere scritti correttamente. Suite completa: 31/31 assert passate, exit code 0.
5. **Test end-to-end via HTTP reale**: server PHP built-in, login vero, POST reale su `/sales/orders/create` **senza inviare** i 3 campi (esattamente come un `<select>` lasciato sulla voce vuota in un browser reale) — risposta **302 Found** (successo, prima era un 200 con errore SQL grezzo), verificato poi via query diretta sul DB scratch: ordine creato, le tre colonne sono `null` reale.
6. **Sweep di non regressione completo**: tutte le 43 suite rieseguite dopo la modifica — **zero differenze** rispetto alla baseline, stessi identici 2 fallimenti preesistenti e nessun altro.

Nessuna modifica di schema, nessun altro file toccato oltre a `includes/sales_orders.php` (fix) e `tests/manual/suite_ordine_cliente_salva_unificata.php` (test).

## 10. Evidenza di esecuzione — §7 punto 1, messaggio d'errore grezzo (26/07/2026)

Autorizzazione ricevuta: "sì", in risposta diretta alla domanda "Vuoi che applichi la modifica del punto 1?". Riguarda **solo** il punto 1 del §7 (sostituire il messaggio SQL grezzo con `dbMessage()`); il punto 2 (`qk_ordine_cliente_crea_revisione()`) resta deliberatamente non eseguito — vedi motivazione già data all'utente: quella funzione copia righe già esistenti da un ordine/preventivo salvato in precedenza, quindi non può mai aver ricevuto un valore `''` su questi campi (nessun form scrive lì direttamente); confermato rileggendo `SalesOrdersController::edit()` (righe 426-457), che aggiorna solo `note`/`data_consegna_prevista` tramite `qk_ordine_cliente_aggiorna_note()` e non tocca mai `azienda_interna_id`/`indirizzo_spedizione_id`/`contatto_id`.

1. **Rilettura fresca** di `SalesOrdersController.php:144-159` (blocco try/catch del metodo `create()`) e di `BaseController.php:1-20` e `:80-90` (firma di `dbMessage(PDOException $e): string`, già usata da altri controller) prima di modificare: confermato lo stato descritto nel piano.
2. **Baseline pre-modifica**: tutte le 43 suite `tests/manual/` eseguite — stessi 2 fallimenti preesistenti e non correlati già noti (`suite_sds_m1_schema.php`, `suite_transition_guard_tappa0.php`), tutto il resto verde.
3. **Correzione applicata**: il blocco `catch (\Throwable $e)` che esponeva `$e->getMessage()` grezzo è stato preceduto da un nuovo `catch (\PDOException $e)` dedicato, che usa `$this->dbMessage($e)` al posto del messaggio grezzo. Il catch generico `\Throwable` resta sotto, invariato, per continuare a gestire gli `Exception`/`RuntimeException` "di dominio" (es. blocchi compliance) che hanno già un messaggio scritto apposta per l'utente e non vanno intercettati da questo nuovo ramo. Verificato sintatticamente pulito (`php -l`) e UTF-8 valido (`tools/check-utf8.php`, exit 0) subito dopo la modifica, con mtime fresco confrontato con l'orario reale della modifica.
4. **Test end-to-end via HTTP reale**: server PHP built-in, login vero. Primo tentativo con `cliente_id=999999` (inesistente) per forzare una violazione FK — fallito come test valido perché la richiesta viene intercettata prima, dal controllo compliance (`qk_compliance_check_sale()`), che restituisce "Cliente non trovato" per un percorso di codice diverso, mai arrivando al blocco try/catch modificato. Corretto usando invece `cliente_id=8701` (valido, seminato apposta) e `listino_id=999999` (inesistente, non pre-validato da nessuna parte) — la richiesta arriva correttamente al vero INSERT e genera una violazione FK reale. Risposta HTML verificata (dopo aver notato che gli apici erano HTML-encoded `&#039;` e non `'`, quindi grep diretto su apice falliva — risolto ispezionando le righe grezze con `sed -n`): il testo mostrato è ora "Impossibile completare l'operazione: vincolo di chiave esterna (Foreign Key) violato. Verificare che tutti i codici/relazioni inseriti esistano già a sistema, oppure che il record non sia referenziato da altri dati." — al posto del precedente messaggio SQL grezzo tipo `SQLSTATE[23000]: Integrity constraint violation...`.
5. **Sweep di non regressione completo**: tutte le 43 suite rieseguite dopo la modifica — **zero differenze** rispetto alla baseline, stessi identici 2 fallimenti preesistenti e nessun altro.
6. **File toccati in questa fase**: solo `app/Controllers/SalesOrdersController.php` (fix). Nessun altro file di produzione modificato. Script di test ad hoc per l'E2E creati in `/tmp` e rimossi a fine test, non fanno parte del repo.

Il punto 2 del §7 resta deliberatamente non eseguito, come indicato nell'intestazione di stato in cima al documento.
