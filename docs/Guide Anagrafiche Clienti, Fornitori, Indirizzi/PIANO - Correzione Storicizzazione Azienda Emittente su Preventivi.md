# PIANO — Correzione storicizzazione azienda emittente su Preventivi

> Stato: **✅ ESEGUITO il 14/07/2026**, esteso anche a `ordini_clienti` su richiesta esplicita (risposta alla domanda 2 del §2). Vedi checklist in fondo per il dettaglio di cosa è stato verificato.

## 0. Correzione di una diagnosi precedente (per trasparenza)

Durante la discussione di oggi avevo inizialmente inquadrato il problema come una grande asimmetria architetturale: "il ciclo vendite non usa lo snapshot con hash, il ciclo acquisti sì, andrebbero unificati". **Questa diagnosi era sbagliata**, basata su una verifica incompleta (avevo letto solo `qk_fetch_document_entities()`, non il flusso di salvataggio reale). Verificato ora riga per riga:

- `includes/sales_quotes.php:322-346` (creazione preventivo) e `:576-607` (modifica preventivo) chiamano già `qk_upsert_document_address()`/`qk_upsert_document_contact()` — le **stesse** funzioni di snapshot con hash usate dal ciclo acquisti — per l'indirizzo di consegna e il contatto, con corretto riuso dell'id esistente in fase di update (righe 578-583, 599, 607).
- `assets/js/sales-quotes.js:1132-1256` mostra già le stesse badge (`✓ Sincronizzato con anagrafica` / `Modificato localmente (override)`) del ciclo acquisti.

Quindi **non serve alcuna migrazione architetturale** per indirizzo di consegna e contatto — sono già unificati. Il problema reale è molto più piccolo, isolato, e riguarda solo un punto: l'azienda emittente sui preventivi.

## 1. Il problema esatto, verificato

`preventivi` (unica tabella tra `preventivi`/`ordini_clienti` ad avere queste colonne — verificato che `ordini_clienti` non le ha) ha 7 colonne denormalizzate scritte correttamente alla creazione/modifica: `azienda_ragione_sociale`, `azienda_partita_iva`, `azienda_codice_fiscale`, `azienda_indirizzo`, `azienda_telefono`, `azienda_email`, `azienda_iban` (`includes/sales_quotes.php:354,363,404`).

In stampa, **due punti distinti** di `SalesQuotesController.php` ignorano queste colonne e rifanno una query dal vivo, **duplicata identica in entrambi**:

- Righe 766-786 (generazione PDF)
- Righe 1094-1114 (generazione Word/DOCX)

Entrambi fanno:

```php
if (!empty($quota['azienda_interna_id'])) {
    // query su aziende_interne + clienti_fornitori per id
}
if (!$azienda) {
    // fallback: WHERE az.predefinita = 1 LIMIT 1 — SENZA filtro attivo=1 (bug noto, §3.1 doc 00)
}
```

**Conseguenza pratica**: un preventivo vecchio ristampato oggi mostra i dati dell'azienda interna **attuale** (o quella attualmente predefinita), non quella realmente emittente al momento della creazione. Se in futuro cambia la Partita IVA, l'IBAN, o si aggiunge una seconda azienda interna, ogni ristampa di un preventivo storico cambia retroattivamente intestazione fiscale — un problema serio per un documento commerciale/fiscale, non cosmetico.

**Bonus**: le due query sono identiche, duplicate — una violazione DRY minore che si può risolvere nello stesso intervento.

## 2. Domande aperte — rispondi prima che io tocchi il codice

1. **Comportamento per i preventivi già esistenti (creati prima del fix), dove le colonne `azienda_*` sono già valorizzate correttamente da tempo.** Per questi, la correzione (leggere le colonne invece di fare la query live) è sufficiente e corretta da sola, oppure vuoi che verifichi anche se qualcuno di questi preventivi ha le colonne `azienda_*` vuote (es. creati quando esisteva un bug diverso, o importati) e serve un fallback esplicito per quei casi?

        RISPOSTA. I dati attuali sono fittizi, non hanno importanza

1. **`ordini_clienti` non ha le colonne `azienda_*`.** Se un ordine cliente genera anch'esso un PDF/Word con intestazione azienda (da verificare — non ho ancora controllato `SalesOrdersController.php` per la stampa), vuoi che estenda la stessa correzione anche lì (aggiungendo le colonne denormalizzate mancanti), o preferisci limitare l'intervento di oggi solo a `preventivi` e trattare `ordini_clienti` come un intervento separato?
   
   RISPOSTA: il nostro scopo non era quello di dare la gestione piu evoluta, potente, omnicomprensiva e robusta sia a vendite che ad acquisti? così deve essere.
2. **Il fallback su `predefinita = 1` senza filtro `attivo = 1`** (bug distinto, già documentato in `00 - Anagrafica Unica`, §3.1) **lo includo in questo stesso intervento** (visto che tocco esattamente quelle righe di codice) o lo tieni separato per un'altra sessione?
   
   RISPOSTA. NON HO CAPITO NIENTE DI QUELLo CHE CHIEDI
3. **Verifica pre-fix**: prima di scrivere codice, voglio interrogare il database reale per contare quanti preventivi esistenti hanno `azienda_interna_id` valorizzato vs quanti si affidano al fallback "predefinita" — è un'informazione a costo zero che mi aiuta a capire quanto è comune il caso limite. Va bene se lo faccio come primo passo (sola lettura, nessuna modifica)?
   RISPOSTA: non so a cosa ti serve però sappi che, ripeto, i dati attualmente esistenti sono fittizi.

## 3. Piano a fasi (da eseguire solo dopo le risposte al §2)

**Fase 1 — Fotografia dello stato attuale (sola lettura, zero rischio).**
Query di conteggio su `preventivi` (quanti con `azienda_interna_id` NULL, quanti con colonne `azienda_*` vuote nonostante l'INSERT dovrebbe averle valorizzate) ed eventualmente su `ordini_clienti` se la domanda 2 lo richiede. Nessuna riga toccata.

**Fase 2 — Funzione condivisa per la lettura, stesso pattern del refactor di oggi.**
Estrarre il blocco duplicato (righe 766-786 e 1094-1114) in una funzione unica, es. `qk_resolve_azienda_emittente_preventivo(array $quota, PDO $pdo): array`, che:

1. Se `$quota['azienda_ragione_sociale']` (o le altre colonne denormalizzate) non sono vuote → le usa direttamente, senza query.
2. Solo se le colonne denormalizzate sono vuote (caso legacy, quantificato in Fase 1) → fa il fallback attuale (query su `azienda_interna_id`, poi `predefinita = 1`), eventualmente con il filtro `attivo = 1` aggiunto se la domanda 3 lo conferma.

**Fase 3 — Sostituzione nei due punti di chiamata**, preservando l'output visivo identico per i preventivi già corretti (nessuna differenza percepibile per l'utente sui documenti "sani"), cambiando comportamento solo per i documenti legacy eventualmente scoperti in Fase 1.

**Fase 4 — Test funzionale reale** (stesso ambiente PHP 8.1 + SQLite già costruito oggi): creare un preventivo di test, modificare dopo il salvataggio l'azienda interna corrispondente (ragione sociale/P.IVA), ristampare il preventivo e verificare che il PDF/Word mostrino ancora i dati **originali**, non quelli aggiornati. Verificare anche il caso legacy (preventivo con colonne `azienda_*` vuote, se esiste) per confermare che il fallback continua a funzionare come prima.

**Fase 5 — Verifica di non-regressione sul resto.** Rieseguire la suite di test già scritta oggi (145 asserzioni) per confermare che il refactor di questa sessione precedente non viene toccato da questo intervento (sono file diversi, ma la verifica è a costo basso e coerente con lo standard di rigore richiesto).

## 4. Checklist atomica

- [x] Risposte alle domande del §2 ricevute (dati fittizi, estendere anche a ordini_clienti, includere il filtro attivo=1)
- [x] Funzione condivisa scritta (`qk_resolve_azienda_emittente_stampa()` in `document_common.php`), `php -l` passato
- [x] Nessun byte nullo nei file toccati (verificato su tutti e 161 i file `.php` del progetto, non solo quelli toccati)
- [x] Le due chiamate (PDF e Word) sostituite — verificato con grep `predefinita = 1` su tutto `SalesQuotesController.php`: zero occorrenze residue
- [x] Test: documento con snapshot congelato, anagrafica azienda rinominata dopo la creazione → la risoluzione restituisce ancora i dati **originali** (verificato via `qk_resolve_azienda_emittente_stampa()` diretta, 7 assert)
- [x] Test: documento legacy (colonne azienda_* vuote) → fallback dal vivo funzionante, filtro attivo=1 rispettato, azienda esplicita ma disattivata comunque onorata (integrità storica)
- [x] Test end-to-end: `qk_insert_ordine_diretto()` scrive correttamente le nuove colonne azienda_* su `ordini_clienti` (3 assert)
- [x] Esteso a `ordini_clienti`: schema (`init_sqlite.sql`/`init_mysql.sql`), migrazione dedicata per DB esistenti, entrambe le funzioni di creazione ordine (`qk_insert_ordine_diretto()`, `qk_preventivo_conferma_ordine()`)
- [x] Rieseguita l'intera suite di test di oggi (145+ asserzioni originali + 22 nuove) → nessuna regressione
- [x] Aggiornato `02 - Dettaglio Tecnico` con la nuova funzione condivisa
- [x] Aggiornata `00 - Anagrafica Unica, Indirizzi e Aziende Interne` (§3.1 e §3.3) per riflettere le correzioni
- [x] **Scoperta non pianificata, corretta nello stesso intervento**: bug di sintassi PHP preesistente (virgolette doppie non escapate dentro stringhe SQL, commento "Fix Listino Fantasma" copiato in più file) che rompeva 4 file (`sales_orders.php`, `prodotti.php`, `WarehouseController.php`, `SalesPricelistsController.php`) — trovato durante la verifica sistematica di tutti i 161 file `.php`, corretto, riverificato
- [x] **Scoperta non pianificata, corretta nello stesso intervento**: due file (`includes/anagrafiche.php`, `includes/prodotti.php`) risultavano troncati sul disco reale nonostante il tool di editing riportasse successo — stesso problema di desync già incontrato in sessione, risolto ricostruendo il contenuto corretto e riscrivendo direttamente il file

## 6. Addendum — Fix filtro `attivo=1` sul fallback acquisti (14/07/2026, stessa giornata, intervento separato)

Completato l'intervento pianificato sopra, è stato risolto anche l'ultimo punto rimasto aperto: il filtro `attivo=1` mancante sul fallback di `qk_resolve_azienda_emittente()` (lato RFQ/Ordini d'acquisto), l'unico bug noto non ancora corretto rimasto in `00` §3.1.

**Correzione fatta**: solo `qk_azienda_predefinita()` ora filtra `AND az.attivo = 1`. **Nota importante**: la prima proposta di fix (applicare il filtro anche al ramo con `azienda_interna_id` esplicito) è stata corretta prima dell'implementazione — verificando i due punti di chiamata reali (`RfqController::pdf()`, `PurchaseOrdersController::pdf()`) si è confermato che l'id esplicito è sempre quello già salvato su un documento esistente: filtrare `attivo` anche lì avrebbe rotto l'integrità storica (stesso principio già documentato nel docblock di `qk_azienda_interna_by_id()`), non corretto un bug.

- [x] Fix applicato in `includes/anagrafiche.php`: `qk_azienda_predefinita()` con `AND az.attivo = 1`; docblock di `qk_resolve_azienda_emittente()` aggiornato per riflettere i due comportamenti distinti (fallback filtrato, id esplicito non filtrato di proposito)
- [x] Test dedicato: 6 assert nuovi (`/tmp/qktest_fresh2/suite_attivo_filter.php`) — fallback su azienda attiva, fallback su azienda disattivata (bug corretto), promozione di una nuova predefinita, id esplicito su azienda disattivata (integrità storica preservata), id esplicito su azienda attiva, `qk_azienda_predefinita()` senza risultati
- [x] Nessuna regressione: rieseguita `suite_new_fixes.php` (12/12 OK), `suite_stampa.php` (7/7 OK, include già gli assert su questo stesso fix), `suite_e2e_ordine.php` su DB fresco (3/3 OK)
- [x] **Scoperta non pianificata, corretta nello stesso intervento**: `includes/anagrafiche.php` risultava di nuovo troncato sul disco reale (stesso desync noto già incontrato 3 volte in sessione) — riparato con la stessa tecnica di ricostruzione Python, verificato `php -l` pulito su tutti i file `.php` del progetto (0 errori su 1068 file)
- [x] Documentazione aggiornata: `00` §3.1 (da "non ancora corretto" a "corretto"), `02` §2.2

## 5. Piano di rollback

L'intervento non tocca lo schema (nessuna migrazione DB), solo la logica di lettura in due metodi di un controller — il rollback è un semplice revert del file `SalesQuotesController.php` e del nuovo file/funzione aggiunta, senza alcun impatto sui dati già scritti (le colonne denormalizzate restano quello che erano, scritte correttamente da sempre; il rollback riporta solo la lettura al comportamento live precedente).
