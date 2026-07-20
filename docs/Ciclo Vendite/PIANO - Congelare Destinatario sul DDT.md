# PIANO — Congelare i dati del destinatario sul DDT (`documenti_trasporto`)

> Stato: ✅ **ESEGUITO il 20/07/2026** — tutte le 10 fasi completate e verificate. Vedi checklist §4.
>
> **Risposte ricevute**: (1) metodo a colonne piatte confermato; (2) `spedizioni` fuori perimetro, solo il DDT; (3) nessun backfill sui DDT esistenti nel DB di test (verrà sostituito); (4) `destinatario_codice_fiscale` **incluso** tra le colonne congelate (era già nel design del §1.5/§1.7, confermato).
>
> **Esito**: 21 asserzioni automatiche su 2 nuove suite (`suite_destinatario_ddt_characterization.php` 6/6, `suite_destinatario_ddt_confirm.php` 9/9, più le 6 della caratterizzazione descrittiva) tutte verdi; rendering HTML del DDT confrontato byte-per-byte prima/dopo un cambio di anagrafica cliente — identico; le 11 suite esistenti in `tests/manual/` rieseguite senza regressioni. Documentazione aggiornata in `REVISIONE - Analisi Documentazione Ciclo Vendite.md` (§1.5, §3bis D1, §5 domanda 2).

Origine: `REVISIONE - Analisi Documentazione Ciclo Vendite.md`, §1.5, domanda aperta #2 del §5.

---

## 0. Correzione della raccomandazione data in chat, prima di questo piano

Nella discussione precedente avevo consigliato, come "soluzione professionale coerente", di agganciare il destinatario del DDT al meccanismo a hash `indirizzi_documenti`/`contatti_documenti` (lo stesso che preventivi/ordini_clienti usano per il cliente). **Approfondendo il codice per scrivere questo piano ho scoperto che quel consiglio era impreciso**, e lo correggo qui per trasparenza invece di procedere come se nulla fosse:

- `preventivi`/`ordini_clienti` *scrivono* effettivamente uno snapshot in `indirizzi_documenti`/`contatti_documenti` (via `qk_upsert_document_address()`/`qk_upsert_document_contact()`), ma **la stampa (PDF/Word) del preventivo non legge mai quello snapshot** — legge le colonne piatte denormalizzate sulla riga stessa (`preventivi.cliente_ragione_sociale`, `.consegna_via`, ecc. — verificato in `SalesQuotesController.php:891,996-998` per il PDF e `:1126,1227-1229` per il Word). L'unico consumo verificato di `indirizzo_consegna_id` è in `SalesOrdersController.php:624-628`, come fallback per **recuperare l'indirizzo master da cui ripartire alla creazione della spedizione**, non per la stampa.
- Ho controllato anche il lato acquisti (RFQ), che la guida "00 - Anagrafica Unica" cita come il vero utilizzatore del pattern a hash: la stampa RFQ (`RfqController.php:269`, `qk_rfq_get_fornitore_indirizzo()`) **legge anch'essa l'indirizzo del fornitore dal vivo** (query diretta su `indirizzi`), non dallo snapshot a hash.
- Conclusione verificata: il meccanismo che **davvero** garantisce a un documento stampato di restare storicamente accurato in questo progetto è il pattern a **colonne piatte congelate una volta sola** — esattamente quello già usato con successo per il mittente sullo stesso DDT (`documenti_trasporto.mittente_*`, fix del 19/07/2026) e per l'azienda emittente su preventivi/ordini (`azienda_*`). Il sistema a hash (`indirizzi_documenti`/`contatti_documenti`) esiste ed è scritto correttamente, ma nell'uso reale verificato serve alla continuità operativa (sapere quale indirizzo "master" riproporre), non all'integrità storica della stampa.

**Il piano quindi usa il pattern a colonne piatte** (l'opzione che in chat avevo presentato come "la scorciatoia più semplice"), non perché sia una scorciatoia, ma perché **è quello verificato come corretto per questo esatto problema**, mentre l'altro risolve un problema diverso. Vantaggio ulteriore, scoperto solo scrivendo il piano (vedi §1.3 sotto): il dato da congelare **esiste già, pronto e corretto**, un livello più in alto nella catena documentale — non va nemmeno ricalcolato.

---

## 1. Analisi tecnica completa, verificata riga per riga

### 1.1 Schema attuale

`documenti_trasporto` (`db/init_sqlite.sql:1428-1465`) ha, per il mittente, 6 colonne piatte (`mittente_ragione_sociale`, `mittente_partita_iva`, `mittente_codice_fiscale`, `mittente_indirizzo`, `mittente_telefono`, `mittente_email`) popolate una sola volta alla creazione. Per il destinatario ha **solo** due FK verso tabelle live: `destinatario_id → clienti_fornitori(id)`, `indirizzo_destinazione_id → indirizzi(id)`. Nessuna colonna di snapshot.

### 1.2 Dove si vede il problema oggi

`SpedizioniController::ddtView()` (righe 203-218) fa, ad ogni visualizzazione/stampa del DDT:
```sql
JOIN clienti_fornitori cf ON cf.id = d.destinatario_id
LEFT JOIN indirizzi a ON a.id = d.indirizzo_destinazione_id
```
e il template `app/views/sales/shipments/_ddt_view.php` (righe 71-83) mostra `$d['destinatario_nome']`, `$d['destinatario_piva']`, `$d['nome_sede']`, `$d['via']`, `$d['civico']`, `$d['cap']`, `$d['citta']`, `$d['provincia']`, `$d['nazione']` — tutti valori letti dal vivo, non congelati.

### 1.3 La fonte del dato corretto esiste già, un livello più in alto — scoperta chiave di questo piano

`documenti_trasporto` viene creato in un unico punto: `SalesOrdersController.php`, nel metodo che gestisce l'azione di spedizione (righe 610-724). A riga 610-612 il codice fa già:
```php
$st = $this->pdo->prepare("SELECT * FROM ordini_clienti WHERE id = ?");
$st->execute([$orderId]);
$order = $st->fetch(PDO::FETCH_ASSOC);
```
`$order` contiene **già** `cliente_ragione_sociale`, `cliente_partita_iva`, `cliente_codice_fiscale` e i 10 campi `consegna_*` (`consegna_via`, `consegna_civico`, `consegna_cap`, `consegna_citta`, `consegna_provincia`, `consegna_nazione`, `consegna_presso`, `consegna_referente`, `consegna_telefono`, `consegna_note`) — colonne aggiunte a `ordini_clienti` il 14/07/2026 (vedi `00 - Anagrafica Unica` §3.3) e **verificate come sempre popolate**, in entrambi i percorsi di creazione ordine:
- da preventivo confermato: `qk_preventivo_conferma_ordine()` (`includes/sales_quotes.php:945-969`) le copia direttamente da `$quota` (il preventivo, già congelato alla propria creazione via `qk_denormalize_document_details()`);
- da ordine diretto: `OrderCalculatorService::insertDirectOrder()` (`app/Services/OrderCalculatorService.php:29`) chiama esso stesso `qk_denormalize_document_details()`.

**Conseguenza pratica per il piano**: non serve ricalcolare nulla al momento della creazione del DDT — basta **copiare** questi campi, già corretti e già congelati sull'ordine, sulle nuove colonne del DDT. Stessa catena di custodia già in uso altrove nel progetto (preventivo congela → ordine copia dal preventivo), estesa di un anello (DDT copia dall'ordine).

### 1.4 Cosa consuma davvero il template di stampa (per dimensionare esattamente le colonne nuove)

Verificato campo per campo in `_ddt_view.php`: `destinatario_nome` (ragione sociale/nome+cognome), `destinatario_piva`, `nome_sede` (mappa a `consegna_presso` sull'ordine), `via`, `civico`, `cap`, `citta`, `provincia`, `nazione`. La query oggi recupera anche `destinatario_cf` (codice fiscale) ma il template non lo mostra — lo includo comunque per simmetria con `mittente_codice_fiscale` (già presente, a costo zero, utile se in futuro va aggiunto in stampa).

### 1.5 Design delle nuove colonne — nome scelto per coerenza, non per comodità

Dieci colonne nuove su `documenti_trasporto`, prefisso `destinatario_*` (mirror esatto di `mittente_*`, non alias generici tipo `via`/`civico` che sarebbero ambigui in una tabella con già due "lati" — mittente e destinatario):

```
destinatario_ragione_sociale VARCHAR(255)
destinatario_partita_iva     VARCHAR(30)
destinatario_codice_fiscale  VARCHAR(30)
destinatario_via             VARCHAR(150)
destinatario_civico          VARCHAR(10)
destinatario_cap             VARCHAR(10)
destinatario_citta           VARCHAR(100)
destinatario_provincia       CHAR(2)
destinatario_nazione         CHAR(2)
destinatario_nome_sede       VARCHAR(100)
```

Su SQLite `ALTER TABLE ... ADD COLUMN` è supportato nativamente (a differenza del caso del CHECK sugli stati preventivo di ieri) — **non serve ricreare la tabella**, rischio molto più basso.

### 1.6 Le due funzioni da scrivere, mirror esatto di quelle già esistenti per il mittente

- `qk_calcola_destinatario_ddt(PDO $pdo, int $destinatarioId, ?int $indirizzoDestinazioneId): array` — risoluzione dal vivo (estratta dalla query oggi in `ddtView()`), usata **solo** come fallback per DDT storici privi delle colonne congelate. Mirror di `qk_calcola_mittente_ddt()`.
- `qk_resolve_destinatario_ddt_stampa(PDO $pdo, array $ddt): array` — legge prima `destinatario_*` se valorizzate, altrimenti ricade su `qk_calcola_destinatario_ddt()`. Mirror di `qk_resolve_mittente_ddt_stampa()`. A differenza del mittente (un solo campo `indirizzo` pre-formattato), restituisce i campi indirizzo **strutturati** (`via`, `civico`, `cap`, `citta`, `provincia`, `nazione`, `nome_sede`) perché è così che il template li consuma oggi — non introduco una stringa formattata che poi andrebbe smontata di nuovo.

Entrambe vanno in `includes/document_common.php`, accanto alle omologhe per il mittente, non in un file nuovo (stesso file, stessa responsabilità).

### 1.7 Punti di codice da toccare — elenco completo, nessuna sorpresa nascosta

1. `db/init_sqlite.sql` — 10 nuove colonne su `documenti_trasporto`.
2. `db/init_mysql.sql` — stesse 10 colonne (schema mirror già identico oggi per `mittente_*`, verificato riga 1087-1117).
3. `includes/document_common.php` — le due nuove funzioni.
4. `SalesOrdersController.php` (righe 717-724, l'`INSERT INTO documenti_trasporto`) — aggiungere le 10 colonne, valorizzate copiando da `$order` (già disponibile in quella riga di codice, nessuna nuova query).
5. `SpedizioniController::ddtView()` (righe 203-218) — rimuovere il `JOIN clienti_fornitori`/`LEFT JOIN indirizzi` per il destinatario dalla query; chiamare `qk_resolve_destinatario_ddt_stampa()` dopo il fetch, esattamente come già avviene per `$mittente` a riga 229.
6. `app/views/sales/shipments/_ddt_view.php` (righe 71-83) — sostituire `$d['destinatario_nome']` ecc. con `$destinatario['ragione_sociale']` ecc., stesso trattamento già riservato a `$mittente` nello stesso file (righe 31-37).
7. Migrazione dedicata in `db/migrations/` per aggiungere le colonne a un database SQLite già esistente (idempotente, stesso pattern di `2026_07_17_stato_fatturazione_ordini_acquisto.php`).

Nessun altro file risulta leggere `documenti_trasporto.destinatario_id`/`indirizzo_destinazione_id` per la stampa (verificato con una ricerca mirata su tutto il progetto) — la lista sopra è quindi completa, non parziale.

### 1.8 Cosa NON cambia, per essere espliciti

- `destinatario_id` e `indirizzo_destinazione_id` **restano** sullo schema: sono l'identità del destinatario (chi è, a fini di ricerca/filtro/report), non vanno confuse con lo snapshot dei suoi dati stampati. Stessa distinzione già presente altrove (es. `preventivi.cliente_id` convive con `preventivi.cliente_ragione_sociale`).
- La lista spedizioni (se mostra il nome cliente per la ricerca/filtro) resta invariata: è UI operativa, non un documento fiscale stampato — non ha lo stesso problema di integrità storica.
- `spedizioni` (la tabella, distinta da `documenti_trasporto`) non viene toccata in questo piano: non ha un proprio "documento stampato" a sé, il documento stampato è il DDT. Se in futuro vorrai un trattamento analogo anche lì, è un intervento a parte (vedi domanda 2 del §2).

---

## 2. Domande aperte — rispondi prima che io tocchi qualunque file

1. **Conferma dell'approccio corretto (§0)**: procedo con le colonne piatte copiate dall'ordine (non il sistema a hash che avevo suggerito prima)?
2. **`spedizioni`**: la confermo fuori perimetro per questo intervento (nessun documento stampato a sé, solo un record interno), o vuoi che valuti comunque un trattamento analogo?
3. **DDT già esistenti nel database**: dato che l'ultima volta mi hai detto che il DB reale è solo di test, presumo valga anche qui e non serva preoccuparsi di backfillare i DDT già emessi con le nuove colonne (resterebbero vuote, e la funzione di lettura ricadrebbe sul fallback dal vivo per quei soli record storici, esattamente come già avviene per `mittente_*` sui DDT pre-19/07). Confermi?
4. **Codice fiscale destinatario**: lo includo (`destinatario_codice_fiscale`) anche se il template oggi non lo stampa, per simmetria con `mittente_codice_fiscale` e per non dover toccare di nuovo lo schema se un domani serve. Va bene, o preferisci ometterlo finché non serve davvero?

---

## 3. Piano a fasi

**Fase 1 — Rilettura immediatamente pre-modifica.** Rileggo con lo strumento di lettura (non da shell) tutti i file elencati al §1.7 subito prima di ciascuna modifica, per la regola del progetto sui file che possono cambiare durante la sessione.

**Fase 2 — Suite di test "characterization" PRIMA del fix.** Scrivo `tests/manual/suite_destinatario_ddt_characterization.php`, stesso pattern di `suite_mittente_ddt.php`: database SQLite temporaneo da `db/init_sqlite.sql`, seed realistico (cliente con indirizzo, ordine con `consegna_*` valorizzati, DDT creato). Verifica **il comportamento attuale**: il DDT mostra il destinatario risolto dal vivo; se cambio l'indirizzo del cliente in anagrafica *dopo* aver creato il DDT, la vista del DDT cambia di conseguenza (bug riprodotto e dimostrato, non solo affermato). Eseguo e confermo che il risultato corrisponde a quanto descritto al §1.2 prima di continuare.

**Fase 3 — Schema.** Aggiungo le 10 colonne a `db/init_sqlite.sql` e `db/init_mysql.sql` (stesso commit, per non lasciare i due schemi disallineati anche solo temporaneamente). Scrivo la migrazione idempotente per DB SQLite già esistenti. Verifico con `PRAGMA table_info` su un DB fresco che le colonne esistano con i tipi corretti.

**Fase 4 — Le due funzioni in `document_common.php`.** Scrivo `qk_calcola_destinatario_ddt()` (estraendo la logica di query oggi duplicata solo in `ddtView()`) e `qk_resolve_destinatario_ddt_stampa()`. Test mirato subito dopo, isolato dal resto: chiamo le due funzioni direttamente su dati di seed, verifico che restituiscano i campi attesi sia nel ramo "congelato" sia nel ramo "legacy dal vivo".

**Fase 5 — Popolamento alla creazione (`SalesOrdersController.php`).** Aggiungo le 10 colonne all'INSERT, valorizzate da `$order['cliente_ragione_sociale']` ecc. Nessuna nuova query: i dati sono già in memoria in quel punto del codice.

**Fase 6 — Lettura in stampa (`ddtView()` + `_ddt_view.php`).** Rimuovo il JOIN live per il destinatario dalla query, aggiungo la chiamata a `qk_resolve_destinatario_ddt_stampa()`, aggiorno il template per leggere da `$destinatario` invece che da `$d`. Verifico visivamente il rendering (vedi Fase 8).

**Fase 7 — Suite di conferma POST-fix.** Scrivo `tests/manual/suite_destinatario_ddt_confirm.php`: stesso scenario della Fase 2, ma ora verifico che un DDT creato *dopo* il fix, con successivo cambio di indirizzo/ragione sociale del cliente in anagrafica, **continui a mostrare i dati originali**. Verifico anche il ramo legacy: un DDT con le colonne `destinatario_*` vuote (simulando un record pre-migrazione) deve ancora funzionare tramite il fallback dal vivo, senza errori.

**Fase 8 — Verifica visiva del documento reale, non solo dei dati.** Genero l'HTML del DDT (via `ddtView()` su dati di test) e lo confronto visivamente prima/dopo — il layout stampato deve restare identico, cambia solo la fonte del dato, non l'aspetto. Se ho un modo di renderizzare la pagina (screenshot), lo uso; altrimenti confronto l'HTML prodotto campo per campo.

**Fase 9 — Non regressione.** Rieseguo tutte le suite esistenti in `tests/manual/` (incluse le 2 aggiunte ieri per gli stati preventivo) per assicurarmi che nessuna condivida codice toccato oggi (`document_common.php` è condiviso con `qk_resolve_azienda_emittente_stampa()`, quindi la verifica non è solo formale).

**Fase 10 — Documentazione, solo a fix validato.** Aggiorno `REVISIONE - Analisi Documentazione Ciclo Vendite.md` §1.5, chiudendo la domanda 2 del §5 con riferimento a questo piano. Non tocco `Manuale_Definitivo_Ciclo_Vendite.md` finché non decidiamo la riscrittura complessiva di quella sezione (§4 della REVISIONE, non ancora confermata).

---

## 4. Checklist atomica

- [x] Risposte al §2 ricevute
- [x] Fase 1 — file riconfermati (rilettura immediatamente pre-modifica per ogni file toccato)
- [x] Fase 2 — suite characterization scritta ed eseguita, bug riprodotto (`suite_destinatario_ddt_characterization.php`, 6/6 OK, JOIN live confermato mutare col cambio anagrafica)
- [x] Fase 3 — schema aggiornato (SQLite + MySQL), migrazione scritta e testata (`db/migrations/2026_07_20_destinatario_ddt_congelato.php`, idempotente: prima esecuzione 10 colonne aggiunte, seconda esecuzione 0 aggiunte)
- [x] Fase 4 — funzioni `qk_calcola_destinatario_ddt()`/`qk_resolve_destinatario_ddt_stampa()` scritte e testate isolatamente (12/12 asserzioni)
- [x] Fase 5 — popolamento alla creazione DDT (`SalesOrdersController.php`, INSERT esteso con i 10 campi da `$order`, nessuna nuova query)
- [x] Fase 6 — lettura in stampa aggiornata (controller + vista): `SpedizioniController::ddtView()` chiama `qk_resolve_destinatario_ddt_stampa()`, `_ddt_view.php` legge da `$destinatario` invece che da `$d`
- [x] Fase 7 — suite di conferma: tutte le asserzioni verdi (`suite_destinatario_ddt_confirm.php`, 9/9 OK: ramo congelato confermato immutabile al cambio anagrafica, ramo legacy confermato funzionante col fallback dal vivo)
- [x] Fase 8 — verifica visiva del documento completata: HTML del DDT renderizzato con la vista reale (`_ddt_view.php`, dati e helper veri, non stub), confrontato byte-per-byte prima/dopo un cambio di ragione sociale e indirizzo del cliente — identico. Layout e dati (ragione sociale, sede, indirizzo, P.IVA) corretti nel blocco DESTINATARIO
- [x] Fase 9 — nessuna regressione sulle suite esistenti: tutte le 11 suite in `tests/manual/` rieseguite, 0 fallimenti (incluse quelle che condividono `document_common.php`, es. `suite_mittente_ddt.php`)
- [x] Fase 10 — documentazione aggiornata (`REVISIONE - Analisi Documentazione Ciclo Vendite.md`, §1.5, §3bis D1, §5 domanda 2)

---

## 5. Piano di rollback

Nessuna modifica distruttiva: le colonne nuove sono aggiuntive (`ADD COLUMN`, mai `DROP`/`ALTER` su colonne esistenti), `destinatario_id`/`indirizzo_destinazione_id` restano invariate, e la funzione di lettura ha comunque un fallback dal vivo funzionante per righe senza i nuovi campi. Il rollback, se necessario, è: revert dei file di codice (controller, vista, `document_common.php`) — le colonne nuove possono restare vuote e inutilizzate senza alcun effetto collaterale, il comportamento tornerebbe quello di oggi (lettura dal vivo) senza bisogno di una migrazione di rollback separata.
