# PIANO — Revisione Ordini Clienti (meccanismo "Nuova Versione")

> Stato: 🔲 **STUDIO COMPLETATO, IMPLEMENTAZIONE NON INIZIATA.** Nessun file di codice toccato. Riferimento: REVISIONE Ciclo Vendite, Problema 8 (§3bis.O4) — "nessuna revisione per un ordine cliente già confermato, l'unico rimedio oggi è annullare (bug O2, già corretto) e rifare da capo".

---

## 0. Cosa esiste già lato Acquisti — verificato riga per riga, non per sentito dire

`qk_ordine_crea_revisione()` (`includes/purchases_orders.php:625-802`) fa, in una transazione:

1. **Guard di stato**: solo un ordine `inviato` può diventare `superato` (`TransitionGuard`, `qk_transizioni_ordini_fornitore()`).
2. **Guard aggiuntivo, più sottile**: blocca se esiste una `ricezioni_testata` in stato `bozza` collegata — altrimenti quella ricezione resterebbe agganciata alla versione vecchia (ormai `superato`), invisibile dalla versione nuova.
3. Duplica lo snapshot indirizzo (`indirizzi_documenti`) e contatto (`contatti_documenti`) come **nuove righe fisiche**, non riferimenti condivisi.
4. Nuovo numero con suffisso `" v" + versione` (es. `ORD/2026/0001 v2`).
5. Inserisce la nuova testata in stato **`bozza`**, con `versione` incrementata e `ordine_padre_id` che punta al vecchio.
6. Duplica tutte le righe, azzerando `quantita_ricevuta` (sempre 0 sull'origine, per via del guard al punto 1: solo `inviato` = nulla ancora ricevuto).
7. Rilascia `quantita_in_arrivo` del vecchio ordine (`_ordine_aggiorna_in_arrivo(..., '-')`) e marca il vecchio `superato`.

**Scoperta rilevante per questo piano**: ho cercato in tutto `app/Controllers/PurchaseOrdersController.php` e nelle viste `app/views/purchases/orders/` qualunque chiamata a questa funzione — **zero risultati**. La funzione esiste, è testata (`tests/manual/suite_transition_guard_tappa4_ordini_fornitore.php`), ma **non è collegata a nessun bottone o azione controller**. È codice morto lato UI, stessa categoria già documentata per `qk_get_purchase_price()` (REVISIONE Ciclo Acquisti §1.5). Non è nel perimetro di questo piano correggerlo lato Acquisti — lo segnalo solo perché significa che **non esiste un'interfaccia utente di riferimento da copiare**: quella lato Vendite va disegnata da zero, non solo portata.

---

## 1. Differenze strutturali Acquisti vs Vendite — perché non è un copia-incolla

| Aspetto | Acquisti (`ordini_testata`) | Vendite (`ordini_clienti`) | Impatto |
|---|---|---|---|
| Stato "bozza" iniziale | Sì (`bozza`→`in_approvazione`→`approvato`→`inviato`) | **No** — CHECK ammette solo `in_lavorazione, parzialmente_spedito, spedito, completato, annullato` | La nuova versione non può nascere "non ancora impegnativa" come in Acquisti: o nasce già `in_lavorazione` (impegnativa da subito) o serve inventare uno stato che oggi non esiste |
| Valore di stato "superato" | Esiste nel CHECK | **Non esiste** nel CHECK di `ordini_clienti.stato` | Vedi §2 sotto — è il punto più delicato di tutto il piano |
| Colonne `versione`/`ordine_padre_id` | Esistono già | **Non esistono** | Migrazione additiva, semplice (stesso pattern usato 3 volte in questa sessione) |
| Analogo di "quantità impegnata" | `quantita_in_arrivo` (`giacenze`), gestita da `_ordine_aggiorna_in_arrivo()` | `quantita_riservata` (`giacenze`), gestita da `qk_riserva_stock_ordine()`/`qk_rilascia_stock_ordine()` — **quest'ultima esiste già**, l'ho scritta oggi stesso per il fix del bug O2 | Nessun lavoro nuovo da fare qui, solo riusare le due funzioni già pronte e già verificate |
| Guard "documento a valle aperto" | Ricezione in `bozza` | Non esiste un equivalente diretto — ma serve un guard analogo: nessuna spedizione deve esistere già | Va scritto ex novo (semplice: query su `spedizioni`/`quantita_spedita`) |
| Magazzino di riferimento | `magazzino_destinazione_id`, già su `ordini_testata` da tempo | `magazzino_id`, aggiunto oggi stesso (fix O2) | Nessun lavoro aggiuntivo, la colonna c'è già |

---

## 2. Il punto più delicato: come rappresentare "questo ordine è stato sostituito"

`ordini_clienti.stato` ha oggi:
```sql
CHECK (stato IN ('in_lavorazione','parzialmente_spedito','spedito','completato','annullato'))
```

SQLite **non supporta** `ALTER TABLE ... MODIFY/DROP CONSTRAINT`: aggiungere un valore ammesso a un CHECK esistente richiede **ricreare la tabella** (rinominare la vecchia, crearne una nuova con il CHECK aggiornato, ricopiare tutti i dati, ricreare indici/trigger, droppare la vecchia) — non ho trovato nessun precedente di questo tipo nelle 19 migrazioni già scritte in questo progetto (tutte additive: nuove colonne, nuovi indici, mai un CHECK modificato). `ordini_clienti` ha inoltre **due tabelle con FK NOT NULL verso di lei** (`spedizioni.ordine_id`, `documenti_trasporto.ordine_id`, oltre a `ordini_clienti_righe`) da preservare intatte durante la ricreazione — è l'operazione di schema più rischiosa di tutte quelle fatte finora in questo progetto.

**Tre opzioni, da decidere prima di scrivere qualunque codice:**

- **A) Aggiungere `'superato'` al CHECK**, ricreando la tabella. Coerente al 100% con il modello Acquisti (stessa parola, stesso significato, distinguibile da `annullato` in ogni filtro/report). Rischio più alto, ma il più corretto concettualmente: "sostituito da una versione più recente" e "annullato perché sbagliato/rifiutato" sono eventi diversi e vale la pena poterli distinguere in un report.
- **B) Riusare `'annullato'`** per marcare anche il vecchio ordine quando viene revisionato, aggiungendo una colonna separata (es. `ordini_clienti.superato_da_id INTEGER REFERENCES ordini_clienti(id)`) per distinguere "annullato e basta" da "annullato perché sostituito". Zero rischio sullo schema esistente (solo colonne additive), ma un ordine "superato" finirebbe comunque nei filtri/contatori di "ordini annullati" a meno di escluderlo esplicitamente ovunque quel filtro esiste oggi (rischio di dimenticare un punto).
- **C) Nessun nuovo stato**: il vecchio ordine resta `in_lavorazione` ma con un flag/colonna che lo marca "superato da una revisione" senza cambiare `stato` affatto, e tutte le viste/query che oggi considerano `in_lavorazione` come "ordine attivo/aperto" vanno riviste per escludere anche questo caso. Più lavoro di verifica trasversale (ogni punto del codice che oggi fa `WHERE stato = 'in_lavorazione'` andrebbe riletto), non lo consiglio.

**Non implemento nessuna delle tre finché non mi dici quale.** La mia raccomandazione è **A**, per coerenza col modello già scelto e già validato lato Acquisti — ma è la decisione con più conseguenze di tutto il piano, quindi la sottopongo esplicitamente invece di sceglierla da solo.

---

## 3. Mappa completa dei touchpoint — tutto quello che tocca `ordini_clienti`, verificato con grep

Per capire davvero "cosa si può rompere", questo è l'elenco completo di tutto ciò che legge o scrive `ordini_clienti`/`ordini_clienti_righe` o dipende dal loro stato, con l'impatto specifico della revisione:

1. **`ordini_clienti_righe`** (FK `ON DELETE CASCADE`) — duplicate integralmente, come fa Acquisti.
2. **`spedizioni.ordine_id`** (FK `NOT NULL`) e **`documenti_trasporto.ordine_id`** (FK `NOT NULL`) — per costruzione, se il guard "nessuna spedizione esistente" (§4 sotto) è rispettato, questi non esistono ancora sull'ordine da revisionare: nessun rischio di "documento a valle orfano", **a patto che il guard sia scritto e testato correttamente** — è il punto singolo di fallimento di tutto il piano.
3. **`giacenze.quantita_riservata`** — rilascio del vecchio ordine (residuo = tutto, dato che nulla è ancora spedito) via `qk_rilascia_stock_ordine()` (già scritta), nuova riserva sul nuovo ordine via `qk_riserva_stock_ordine()` (già esistente). Stesso magazzino (`ordini_clienti.magazzino_id`, non ricalcolato da sessione — coerente col fix O2 di oggi).
4. **`qk_compliance_check_sale()`** (fix Problema 2 di oggi) — la nuova versione ha potenzialmente righe/quantità diverse da quella vecchia: va ri-eseguito il controllo compliance sulla nuova versione, non ereditato "per fiducia" dalla vecchia.
5. **`stato_fatturazione`** — se il vecchio ordine è già `fatturato`, revisionarlo è concettualmente sospetto (si starebbe correggendo un ordine che l'amministrazione ha già considerato chiuso contabilmente). Da decidere: bloccare la revisione in quel caso, o permetterla e resettare `stato_fatturazione='da_fatturare'` sulla nuova versione con un avviso esplicito.
6. **Snapshot `azienda_*`/`cliente_*`/`consegna_*`** (colonne dirette su `ordini_clienti`) + **`indirizzo_consegna_id`/`contatto_snapshot_id`** (righe in `indirizzi_documenti`/`contatti_documenti`) — duplicati come nuove righe fisiche, stesso pattern Acquisti.
7. **`listino_id`** — copiato as-is (non è oggetto della revisione, salvo che l'operatore lo cambi esplicitamente nella nuova versione).
8. **`preventivo_id`** — copiato as-is: la nuova versione resta collegata allo stesso preventivo originario (se esisteva), la genealogia della revisione vive in `ordine_padre_id`, non in `preventivo_id`.
9. **`includes/transizioni_stati.php`, `qk_transizioni_ordini_clienti()`** — da estendere con la transizione verso lo stato scelto al §2.
10. **`app/Controllers/SalesOrdersController.php`**: nuovo metodo model (`qk_ordine_cliente_crea_revisione()` in `includes/sales_orders.php`, mirror di quella Acquisti) + nuovo case nell'`action()` esistente.
11. **`app/views/sales/orders/_detail.php`**: nuovo bottone "Crea Nuova Versione", visibile solo quando lo stato lo permette (§4), con badge versione e link bidirezionale padre↔figlio (l'Acquisti non ha nemmeno questo lato UI da cui copiare, va disegnato).
12. **Numerazione** (`numero_ordine VARCHAR(20) NOT NULL UNIQUE`) — formato attuale osservato `ODC/2026/NNNN` (13 caratteri); un suffisso `" v2"` porta a 16, margine sufficiente nel `VARCHAR(20)` per diverse revisioni.

---

## 4. Decisioni di design aperte — da chiudere prima di implementare

1. **§2 sopra** — quale delle tre opzioni per rappresentare "superato" (raccomando A).
2. **Quando è permesso revisionare.** Raccomando: **solo** `stato = 'in_lavorazione'` esatto — mai `parzialmente_spedito`/`spedito`/`completato`/`annullato`. È il mirror esatto della restrizione Acquisti ("solo `inviato`", cioè "nulla ancora ricevuto") tradotta in "nulla ancora spedito". Verificabile con un'unica condizione (lo stato stesso, dato che `parzialmente_spedito`/`spedito` sono raggiunti SOLO se `quantita_spedita > 0` su almeno una riga — la stessa logica S7 già documentata).
3. **`stato_fatturazione = 'fatturato'`** — bloccare la revisione in quel caso, o permetterla con reset esplicito e avviso? Non ho una raccomandazione netta qui, dipende da come l'amministrazione userà quel flag in pratica.
4. **Compliance** — ri-eseguire sempre il controllo sulla nuova versione (raccomando sì, per coerenza con Problema 2 di oggi — non avrebbe senso aver chiuso quel gap e poi riaprirlo qui).
5. **UI**: confermi che il bottone va su `_detail.php` (stesso posto di annulla/completa/riapri), o preferisci una collocazione diversa?

---

## 5. Microtappe

Ogni tappa è pensata per essere ripresa in una sessione diversa senza perdere contesto: stato in ingresso e stato in uscita sono espliciti, così chi riprende (anche un Claude "che riparte da zero", per usare la formula di CLAUDE.md) sa esattamente a che punto siamo.

### Tappa 1 — Schema: colonne additive (`versione`, `ordine_padre_id`)
**Stato in ingresso**: nessuna colonna nuova.
**Cosa fare**: `ALTER TABLE ordini_clienti ADD COLUMN versione INTEGER NOT NULL DEFAULT 1`, `ADD COLUMN ordine_padre_id INTEGER REFERENCES ordini_clienti(id)` — su `db/init_sqlite.sql` (CREATE TABLE) **e** su una migrazione dedicata (imparata la lezione di oggi: senza migrazione, il DB live non la riceve mai). Stesso su `db/init_mysql.sql`, segnalato esplicitamente se non allineato subito (regola CLAUDE.md).
**Stato in uscita**: colonne presenti, nessun codice le usa ancora, nessun comportamento cambiato.
**Test di questa tappa**: `PRAGMA table_info` sul DB live conferma le due colonne; una query `SELECT` su un ordine esistente qualunque mostra `versione=1, ordine_padre_id=NULL` senza errori.

### Tappa 2 — Schema: il valore "superato" (SOLO se opzione A al §2 viene scelta)
**Stato in ingresso**: Tappa 1 completata.
**Cosa fare**: ricreazione controllata della tabella `ordini_clienti` con il CHECK aggiornato. Procedura, da eseguire **prima su una copia del DB, mai direttamente sul live**:
1. `CREATE TABLE ordini_clienti_new` con lo stesso DDL esatto ma CHECK esteso.
2. `INSERT INTO ordini_clienti_new SELECT * FROM ordini_clienti`.
3. Verifica: `SELECT COUNT(*) FROM ordini_clienti` deve essere uguale su vecchia e nuova, riga per riga su un campione, non solo il conteggio.
4. `DROP TABLE ordini_clienti`, `ALTER TABLE ordini_clienti_new RENAME TO ordini_clienti`.
5. Ricreare tutti gli indici che insistevano sulla vecchia tabella (da elencare con `PRAGMA index_list` PRIMA del drop, non a memoria).
6. Verificare che le FK da `ordini_clienti_righe`/`spedizioni`/`documenti_trasporto` risolvano ancora correttamente (un semplice JOIN di conteggio prima/dopo).
**Stato in uscita**: `ordini_clienti.stato` accetta anche `'superato'`, tutti i dati esistenti intatti, tutte le FK/indici ricreati.
**Test di questa tappa**: script PHP dedicato che, su una **copia** del DB live, esegue la procedura ed esegue poi un centinaio di query di lettura random già usate nell'app (join con righe/spedizioni/documenti_trasporto) confrontando i risultati prima/dopo byte per byte. Solo dopo un esito pulito su copia si esegue sul DB reale.

### Tappa 3 — Model: `qk_ordine_cliente_crea_revisione()`
**Stato in ingresso**: Tappe 1-2 completate, decisioni §4 chiuse.
**Cosa fare**: nuova funzione in `includes/sales_orders.php`, mirror di `qk_ordine_crea_revisione()` (Acquisti) con gli adattamenti del §1/§3: guard di stato (solo `in_lavorazione`), guard "nessuna spedizione esistente" (nuovo, non ha equivalente diretto da copiare), guard `stato_fatturazione` (se deciso al §4.3), duplicazione snapshot indirizzo/contatto, duplicazione righe, rilascio riserva vecchio ordine + nuova riserva, ri-esecuzione compliance check, numerazione con suffisso.
**Stato in uscita**: funzione scritta, non ancora richiamabile da UI.
**Test di questa tappa** (esecuzione PHP reale contro il DB live, stesso metodo usato per tutti i fix di oggi — non solo lint):
- Caso base: ordine `in_lavorazione` pulito → nuova versione creata, vecchio marcato secondo la decisione §2, righe identiche, `versione=2`, `ordine_padre_id` corretto.
- **Controprova decisiva sulla riserva** (stesso schema già usato per il fix O2 stamattina): due ordini paralleli sullo stesso prodotto/magazzino, revisiona solo il primo → la riserva del secondo resta intatta, quella del primo si sposta interamente sul nuovo ordine (non raddoppiata, non azzerata).
- Tentativo di revisionare un ordine `parzialmente_spedito` → bloccato con messaggio chiaro.
- Tentativo di revisionare un ordine con una spedizione già esistente (anche se lo stato fosse manomesso) → bloccato dal guard dedicato, non solo dal guard di stato — difesa in profondità, stesso principio già applicato quest'oggi per gli stati dei preventivi.
- Tentativo di revisionare con un prodotto in violazione di compliance → bloccato.
- Snapshot indirizzo/contatto: verificare che siano righe fisicamente diverse (id diversi) in `indirizzi_documenti`/`contatti_documenti`, non lo stesso id riusato — e che modificare la copia nuova non alteri quella vecchia.

### Tappa 4 — Transizioni di stato
**Stato in ingresso**: Tappa 3 completata.
**Cosa fare**: estendere `qk_transizioni_ordini_clienti()` con la transizione verso lo stato scelto al §2.
**Test**: uno unitario minimo (verifica che `TransitionGuard` accetti/rifiuti le transizioni attese) — già in gran parte coperto dai test comportamentali della Tappa 3.

### Tappa 5 — Controller
**Stato in ingresso**: Tappa 4 completata.
**Cosa fare**: nuovo `case 'nuova_versione'` in `SalesOrdersController::action()`, che richiama la funzione della Tappa 3 e reindirizza al nuovo ordine (stesso schema già presente per `crea_spedizione`).
**Test**: chiamata reale al controller (stesso metodo via Reflection/POST simulato già usato oggi per gli altri fix), non solo alla funzione model isolata — per essere sicuri che CSRF, sessione, redirect si comportino come atteso.

### Tappa 6 — UI
**Stato in ingresso**: Tappa 5 completata.
**Cosa fare**: bottone "Crea Nuova Versione" in `_detail.php`, visibile solo quando lo stato lo permette; badge "Versione N" e link all'ordine padre (se esiste) / al figlio (se questo ordine è stato superato).
**Test**: verifica visiva reale (Browser pane) — apertura pagina ordine, click bottone, verifica redirect e dati precompilati sulla nuova versione; verifica che il bottone NON compaia su un ordine `parzialmente_spedito`/`completato`/`annullato`.

### Tappa 7 — Aggiornamento documentazione
**Stato in ingresso**: Tappe 1-6 completate e verificate.
**Cosa fare**: aggiornare la REVISIONE Ciclo Vendite (chiudere Problema 8/O4), e la futura guida "Flusso Operativo" quando verrà scritta.

---

## 6. Riepilogo: cosa serve da te prima che io inizi

Le 5 decisioni del §4 (in particolare la §4.1 — è quella con più conseguenze). Il resto del piano non cambia sostanzialmente qualunque cosa deciderai lì, tranne la Tappa 2 che esiste solo se scegli l'opzione A.
