# 02 — Dettaglio Tecnico — Schema DB e Codice (Anagrafiche)

> Capitolo tecnico di riferimento per chi lavora sul codice, non sostituisce ma completa `00` e `01`. Schema, firme di funzione e percorsi verificati direttamente sul codice sorgente al 14/07/2026, inclusi i refactor MVC/DRY effettuati in questa stessa sessione di lavoro. I file storici (F3 in particolare) contenevano le versioni pre-refactor di alcune di queste funzioni: qui sono riportate le firme attuali.

---

## 1. Schema database — tabelle principali

### 1.1 `clienti_fornitori`

Anagrafica unica di clienti, fornitori e aziende interne. Colonne chiave: `id`, `codice` (univoco, generato per prefisso), `tipo` (`cliente`/`fornitore`/`entrambi`), `parent_id` (INTEGER, **nessuna foreign key**, nessun `ON DELETE`), `partita_iva VARCHAR(20) UNIQUE`, `attivo`.

### 1.2 `indirizzi`

Indirizzi collegati a un soggetto. `soggetto_id` → `clienti_fornitori(id)` con `ON DELETE CASCADE`. Colonna `tipo` vincolata da CHECK. Colonna `predefinito` booleana, **senza indice univoco parziale né trigger** — l'unicità del predefinito per soggetto+tipo è garantita solo a livello applicativo, non a livello di schema (vedi `00`, §2.2).

### 1.3 `dettagli_clienti` / `dettagli_fornitori`

Tabelle di dettaglio per ruolo, popolate con pattern di upsert pigro (vedi `00`, §1.3).

### 1.4 `aziende_interne`

Entità legali interne, con `predefinita` (booleano, nessun vincolo DB di unicità) e `attivo`.

### 1.5 `indirizzi_documenti` / `contatti_documenti`

Snapshot immutabili usati dal ciclo acquisti (vedi `01`). Colonne chiave: `master_id`/`contatto_master_id` (→ `clienti_fornitori(id)` / `indirizzi(id)`, `ON DELETE SET NULL`), `personalizzato`, `hash_originale`. **Non hanno una colonna `created_at`** — mancanza rilevante per la pulizia periodica pianificata (vedi `01`, §3.3, Livello 2).

### 1.6 Tabelle documento con FK verso gli snapshot

`rfq_testata`, `offerte_testata`, `ordini_testata` (acquisti), `preventivi`, `ordini_clienti` (vendite) — puntano a `indirizzi_documenti`/`contatti_documenti`, non direttamente a `indirizzi`/`clienti_fornitori`, per i campi di indirizzo/contatto.

### 1.7 Colonne denormalizzate sui documenti di vendita

`preventivi`/`ordini_clienti` hanno colonne dedicate (`azienda_ragione_sociale`, `azienda_partita_iva`, `azienda_codice_fiscale`, `azienda_indirizzo`, `azienda_telefono`, `azienda_email`, `azienda_iban`) popolate alla creazione e, dal 14/07/2026, lette correttamente anche in fase di stampa tramite `qk_resolve_azienda_emittente_stampa()` (vedi §2.1 sotto e `00`, §3.3).

---

## 2. Funzioni condivise — Service Layer (`includes/`)

Il progetto non ha una cartella `app/Models`. Tutta la logica di accesso ai dati e le regole di business vivono in `includes/*.php` come funzioni procedurali con prefisso `qk_`, organizzate per dominio (non per singola entità) — un pattern **Controller + Service Layer procedurale + View**, dichiarato esplicitamente nel commento di testa di `CustomersController.php`. Non è un MVC classico con Model a oggetti, ed è una scelta consapevole, non una svista: chi cerca "il Model" deve sapere che il posto giusto è `includes/`.

### 2.1 `includes/document_common.php`

Refactored il 14/07/2026 in pattern Repository + Formatter, per separare l'accesso ai dati dalla formattazione testuale (in precedenza mescolati in un'unica funzione, causa diretta del bug della stringa `",  ()"` — vedi `00`, §4).

```
qk_generate_address_hash(?array $addr): string
qk_generate_contact_hash(?array $cont): string
qk_snapshot_stato_personalizzazione(?array $masterRecord, array $payload, bool $masterIdGiven, callable $hashFn): array
qk_upsert_document_address(PDO $pdo, array $payload, ?int $existingId = null): int
qk_upsert_document_contact(PDO $pdo, array $payload, ?int $existingId = null): int
qk_fetch_document_entities(PDO $pdo, array $values): array           // Repository: solo query, nessuna formattazione
qk_format_indirizzo_stringa(?array $addr): ?string                   // usa array_filter, niente residui tipo ",  ()"
qk_format_document_snapshot(array $entities): array                  // Formatter: solo testo, nessun accesso DB
qk_denormalize_document_details(PDO $pdo, array $values): array      // orchestratore: fetch + format
qk_resolve_azienda_emittente_stampa(PDO $pdo, array $documento): array // aggiunta 14/07/2026, vedi sotto
```

**`qk_resolve_azienda_emittente_stampa()`** (14/07/2026): risolve i dati dell'azienda emittente per la stampa PDF/Word di `preventivi`/`ordini_clienti` rispettando la storicità — legge prima le colonne denormalizzate `azienda_*` già congelate sul documento, ricorrendo alla query dal vivo (con filtro `attivo=1` sul fallback `predefinita=1`) solo per documenti legacy privi di quelle colonne. `header_path`/`logo_path` restano sempre risolti dal vivo (branding, non dato storico-fiscale). Sostituisce due blocchi identici duplicati in `SalesQuotesController.php`.

`qk_snapshot_stato_personalizzazione()` unifica la logica di dirty-check prima duplicata quasi identicamente tra `qk_upsert_document_address()` e `qk_upsert_document_contact()` (cambiava solo la tabella interrogata e la funzione di hash). `qk_denormalize_document_details()` è oggi un thin wrapper: `return qk_format_document_snapshot(qk_fetch_document_entities($pdo, $values));` — la firma pubblica non è cambiata, quindi tutti i chiamanti esistenti continuano a funzionare senza modifiche.

### 2.2 `includes/anagrafiche.php`

```
qk_azienda_interna_by_id(PDO $pdo, int $id): ?array
qk_azienda_predefinita(PDO $pdo): ?array
qk_resolve_azienda_emittente(PDO $pdo, ?int $aziendaInternaId = null): array
qk_resolve_azienda_attiva_sessione(PDO $pdo, ?array $aziendeGiaCaricate = null): ?array
qk_esito_cancellazione_messaggio(string $esito, string $entitaSingolare, string $entitaArticolo = 'il'): array
qk_delete_soggetto(PDO $pdo, int $id): string          // 'eliminato' | 'disattivato'
qk_delete_azienda(PDO $pdo, int $id): string            // 'disattivato' | 'non_trovato'
qk_build_e_valida_indirizzo(): array                     // legge $_POST, ritorna ['ok'=>bool, 'v'=>array] o ['ok'=>false,'error'=>string]
qk_parse_float_it(?string $raw, ?float $default = null): ?float
qk_persist_soggetto_clienti_fornitori(PDO $pdo, array $soggetto, ?int $existingId, string $codicePrefix): int
qk_list_contatti_generico() / qk_insert_contatto_generico() / qk_update_contatto_generico() / qk_delete_contatto_generico()
```

Note per ciascuna:

- **`qk_resolve_azienda_emittente()`** unifica quattro blocchi SQL duplicati (2 in `RfqController.php`, 2 in `PurchaseOrdersController.php`) che risolvono l'azienda interna emittente di un documento, con fallback su `predefinita = 1`. **Corretto il 14/07/2026** (in un intervento separato dal refactor MVC/DRY originale, vedi `00` §3.1): il fallback (`qk_azienda_predefinita()`) ora filtra `AND az.attivo = 1`, perché rappresenta la scelta di un'azienda per un documento che non ne ha ancora una assegnata — proporre un'azienda disattivata in quel caso era il bug reale. Il ramo con `azienda_interna_id` esplicito (sempre il valore già salvato su un RFQ/ordine esistente, verificato sui due punti di chiamata reali) resta **senza** filtro `attivo`, di proposito, per lo stesso motivo di integrità storica di `qk_azienda_interna_by_id()` sotto.
- **`qk_resolve_azienda_attiva_sessione()`** estrae la logica prima inline in `includes/header.php` per determinare l'azienda attiva in sessione; accetta opzionalmente una lista di aziende già caricate per evitare una query duplicata.
- **`qk_esito_cancellazione_messaggio()`** genera il messaggio flash mostrato all'utente dopo un tentativo di cancellazione, a partire dall'esito restituito da `qk_delete_soggetto()`/`qk_delete_azienda()` (`'eliminato'`/`'disattivato'`/`'non_trovato'`/default).
- **`qk_build_e_valida_indirizzo()`** unifica la validazione degli indirizzi prima duplicata in tre controller (Customers, Suppliers, Aziende).
- **`qk_parse_float_it()`** converte numeri in formato italiano (virgola decimale) usato da `qk_cliente_prepare_row()`/`qk_fornitore_prepare_row()`. **Nota nota e verificata**: non gestisce correttamente il formato con separatore delle migliaia (es. `"1.500,75"` produce `1.5`, non `1500.75`) — replica fedele di un pattern preesistente in circa 18 altri punti dell'applicazione, fuori dal dominio anagrafiche; segnalato, non corretto in questa sessione perché fuori scope.
- **`qk_persist_soggetto_clienti_fornitori()`** è la correzione sistemica di un bug reale scoperto durante il refactor: prima di questa funzione, `qk_insert_fornitore()` scriveva tutte le 13 colonne mutabili della riga base, mentre `qk_update_fornitore()` ne scriveva solo 9 (mancavano `tipo_soggetto`, `nome`, `cognome`, `cellulare`) — un soggetto `tipo = 'entrambi'` aggiornato da un fornitore non avrebbe mai riflesso modifiche a quei campi. Verificato dormiente (nessun percorso applicativo crea oggi record `'entrambi'` per questa via), ma ora strutturalmente impossibile da far divergere di nuovo: un'unica funzione gestisce sia l'INSERT (tutte 15 colonne) sia l'UPDATE (13 colonne mutabili, esclude `codice`/`tipo` immutabili) per tutti e quattro i chiamanti (`qk_insert_cliente`, `qk_update_cliente`, `qk_insert_fornitore`, `qk_update_fornitore`).
- **Contatti cliente/fornitore**: 4 funzioni generiche parametrizzate per tipo, con thin wrapper (`qk_list/insert/update/delete_contatto_cliente()`/`_fornitore()`) che preservano le firme e il comportamento originali esatti — inclusa l'asimmetria preesistente per cui la lista fornitori non ha filtro `tipo` mentre quella clienti sì.

---

## 3. Controller e frontend coinvolti

### 3.1 `app/Controllers/RfqController.php`, `PurchaseOrdersController.php`

Usano `qk_resolve_azienda_emittente()` per l'azienda emittente sui PDF/documenti; `PurchaseOrdersController.php` richiede esplicitamente `includes/anagrafiche.php` (non era richiesto prima del refactor).

### 3.2 `app/Controllers/CustomersController.php`, `SuppliersController.php`, `AziendeController.php`

I metodi `delete()` usano l'esito di `qk_delete_soggetto()`/`qk_delete_azienda()` con `qk_esito_cancellazione_messaggio()` per mostrare il messaggio corretto ("eliminato" vs "disattivato"); gli handler AJAX di salvataggio indirizzo usano `qk_build_e_valida_indirizzo()`.

### 3.3 `includes/header.php`

Usa `qk_aziende_list()` + `qk_resolve_azienda_attiva_sessione()` al posto di ~24 righe di logica inline di query+sessione.

### 3.4 `assets/js/customers-form.js`, `suppliers-form.js`, `aziende-form.js`

JS estratto dai form (gestione tabella listini, toggle tab/sidebar, toggle tipo, preview file), prima inline in `app/views/customers/_form.php`, `suppliers/_form.php`, `settings/aziende/_form.php`. I dati dipendenti da PHP restano inline come oggetti di configurazione minimi (`window.QkCustomerListini = {...}`), coerente con il pattern preesistente `window.QkCustomer`/`QkCustomerAddr`.

### 3.5 `assets/js/rfq-form.js`

Pulsante "Ripristina" (righe ~875-889): `getOriginalContact()` fa fallback silenzioso svuotando i campi se il contatto originale non è più tra quelli attivi (eliminato o disattivato) — miglioramento UX pianificato, non ancora implementato (vedi `00` §1.5, `01` §3).

### 3.6 `assets/js/sales-quotes.js`

Dropdown indirizzo di consegna in fase di preventivo (riga ~1008), con opzioni "Usa sede legale" e "+ Inserisci indirizzo personalizzato (una tantum)" — meccanismo "una tantum" citato in `00` §1.2 come alternativa a dare un indirizzo proprio ai sotto-contatti.

### 3.7 FK dirette residue lato vendite

A differenza del ciclo acquisti (tutto tramite snapshot), alcuni punti del ciclo vendite mantengono ancora riferimenti diretti a `clienti_fornitori`/`indirizzi` non passati da snapshot — coerente con l'algoritmo di risoluzione più semplice descritto in `00` §4, distinto dal pattern snapshot-with-hash del ciclo acquisti.

---

## 4. Ambiente di test e verifica usato in questa sessione

Le modifiche descritte in questo documento (e nell'intera riorganizzazione) sono state verificate con un ambiente PHP 8.1 CLI reale + SQLite (non solo lint statico): 145 asserzioni eseguite su un database SQLite costruito direttamente da `db/init_sqlite.sql`, zero regressioni rilevate. Dettaglio dei bug trovati e corretti durante questa verifica, riportato per completezza: nessuno nel dominio anagrafiche stesso — i problemi elencati in questo documento e in `00`/`01` erano tutti preesistenti al refactor MVC/DRY del 14/07/2026, non introdotti da esso.
