# Piano di Implementazione: Immutabilità e Denormalizzazione Ciclo Acquisti
**Stato Attuale:** Non Iniziato
**Obiettivo:** Rimuovere le query `LEFT JOIN` dinamiche sulle anagrafiche dei documenti d'acquisto (Metodi di Pagamento e Incoterms) e sostituirle con il pattern "Snapshot" per garantire che i documenti finanziari storici non si alterino mai, seguendo l'architettura Strict MVC e DRY (massima performance, zero hack di retrocompatibilità).

---

## 1. Modifiche al Database Schema

Le tabelle del ciclo acquisti dovranno contenere nativamente la copia del testo delle anagrafiche. Essendo il DB di prova sacrificabile per lo storico, adottiamo il design più puro.

### File: `db/init_sqlite.sql` e `db/init_mysql.sql`
Modificare i blocchi `CREATE TABLE` corrispondenti aggiungendo queste nuove colonne (esattamente con questi nomi, in modo che frontend e PDF continuino a funzionare a scatola chiusa leggendo queste chiavi):

1. **`rfq_testata`**:
   - Aggiungere `metodo_pagamento_descrizione VARCHAR(255)`
   - Aggiungere `incoterm_descrizione VARCHAR(255)`
2. **`offerte_testata`**:
   - Aggiungere `metodo_pagamento_descrizione VARCHAR(255)`
3. **`ordini_testata`**:
   - Aggiungere `metodo_pagamento_descrizione VARCHAR(255)`
   - Aggiungere `incoterm_descrizione VARCHAR(255)`

### File: `db/migrations/2026_08_XX_denormalize_acquisti.php`
Creare un file di migrazione idempotente che:
1. Esegua gli `ALTER TABLE` per aggiungere le colonne se mancanti.
2. (Opzionale/Fallback) Faccia un `UPDATE` massivo pescando le descrizioni dalle tabelle anagrafiche per valorizzare i record fittizi già presenti, ad es:
   `UPDATE rfq_testata SET metodo_pagamento_descrizione = (SELECT descrizione FROM metodi_pagamento WHERE id = rfq_testata.metodo_pagamento_id) WHERE metodo_pagamento_descrizione IS NULL`

---

## 2. Aggiornamento Logica Data Access (Gli Helpers)

Questa è l'unica parte architetturale che subisce un refactoring logico (Strict MVC). 
Il Controller non farà query e la View (i template PHP e PDF) non si accorgerà di niente.

### File: `includes/purchases_rfq.php`
- **Operazioni in Lettura (`qk_rfq_detail`):**
  - **Rimuovere** la riga: `LEFT JOIN metodi_pagamento mp ON mp.id = r.metodo_pagamento_id`
  - **Rimuovere** la riga: `LEFT JOIN incoterms i ON i.codice = r.incoterm_codice`
  - Nella clausola `SELECT`, rimuovere `mp.descrizione as metodo_pagamento_descrizione` e affidarsi direttamente a `r.*` che estrarrà in automatico la colonna nativa `metodo_pagamento_descrizione`.
  
- **Operazioni in Scrittura (`qk_rfq_crea`, `qk_rfq_modifica`, `qk_rfq_crea_revisione`):**
  - Prima di preparare i dati per la `INSERT` / `UPDATE`, inserire un blocco di codice (una query) che, se `$rfq_data['metodo_pagamento_id']` è presente, vada a pescare la `descrizione` dalla tabella `metodi_pagamento`. Stesso discorso per `incoterm_codice`.
  - Aggiungere fisicamente all'elenco dei campi in `INSERT INTO / UPDATE` le colonne `metodo_pagamento_descrizione` e `incoterm_descrizione` passandogli il testo appena "fotografato".

### File: `includes/purchases_quotes.php` (Offerte Ricevute)
- Applicare lo stesso principio di cui sopra (solo per `metodo_pagamento_descrizione`).
- Pulire le query di `SELECT` dalla `LEFT JOIN metodi_pagamento`.
- Aggiornare `qk_offerta_crea` e `qk_offerta_modifica` per fare il pre-fetch testuale e salvarsi il dato nativo.

### File: `includes/purchases_orders.php`
- Applicare l'approccio su entrambe le colonne (Metodo e Incoterm).
- Pulire `qk_ordine_detail` dalle due `LEFT JOIN`.
- Aggiornare `qk_ordine_crea`, `qk_ordine_modifica`, `qk_ordine_crea_revisione`.

---

## 3. Nessuna modifica al Frontend (Viste e PDF)
Avendo chiamato le nuove colonne del database *esattamente* con il nome dell'alias (`metodo_pagamento_descrizione`) che le viste si aspettavano, i file `.php` dentro `app/views/purchases/...` e i template di stampa PDF continueranno a chiamare le loro variabili senza generare rotture. **Dichiaro che questa parte è esente da modifiche.**

---

## 4. Simulazione Logica e Collaudo (TDD E2E)

Una volta terminata la stesura del codice, è TASSATIVO (secondo la Regola Aurea del progetto Quack) eseguire il seguente test PHP isolato (da posizionare in `scratch/check_immutabilita.php`) e garantirne l'`Exit Code 0`:

**Step 1:** Il test inserisce un nuovo finto Incoterm ("AAA", "Test Incoterm") e un finto Pagamento ("BBB", "Test Pagamento").
**Step 2:** Il test invoca in PHP `qk_rfq_crea()` passando i nuovi codici, simulando una vera operazione.
**Step 3:** Il test altera subdolamente con puro SQL le anagrafiche in DB, cambiando il testo di AAA in "Falso Incoterm" e BBB in "Falso Pagamento".
**Step 4:** Il test chiama `qk_rfq_detail()` sull'ID creato, analizza il JSON/Array restituito e **ASSERISCE** che le descrizioni siano ancora "Test Incoterm" e "Test Pagamento".
**Step 5:** Ripete la logica per `qk_ordine_crea()`.
**Condizione di successo:** Se trova "Falso Incoterm" significa che la JOIN non è stata estirpata. Il test fa `exit(1)`. Se il testo storico è preservato, fa `exit(0)`.
