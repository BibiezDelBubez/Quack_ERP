# PIANO — Revisione Ordini Clienti (meccanismo "Nuova Versione")

> Stato: 🔶 **DECISIONI DI DESIGN CHIUSE il 24/07/2026 — IMPLEMENTAZIONE (Tappe 3-6) ANCORA NON INIZIATA, per esplicita richiesta dell'utente** ("non ho tempo per implementarla ora e userò quello che scrivi per implementarla appena possibile"). Riferimento: REVISIONE Ciclo Vendite, Problema 8 (§3bis.O4) — "nessuna revisione per un ordine cliente già confermato, l'unico rimedio oggi è annullare (bug O2, già corretto) e rifare da capo".
>
> **Aggiornamento importante rispetto alla versione precedente di questo documento**: nel frattempo (22/07/2026, sessione precedente) la **Tappa 1-2 (schema) è stata effettivamente implementata** — scoperto verificando il codice reale il 24/07/2026, non ricordato da nessuna conversazione precedente: questo documento era rimasto con lo stato "implementazione non iniziata" per errore di aggiornamento, mentre `db/init_sqlite.sql` e la migrazione `db/migrations/2026_07_22d_ordini_clienti_versione_e_superato.php` mostravano già `ordini_clienti.versione`, `ordini_clienti.ordine_padre_id` e `'superato'` nel CHECK di `ordini_clienti.stato`. Vedi §2 e §4.1 sotto per il dettaglio. **Tappe 3-6 (funzione di revisione, transizioni, controller, UI) restano a zero: nessun codice le usa ancora.**
>
> **Le 5 decisioni di design del §4** sono state chiuse in una conversazione dedicata il 24/07/2026 (spiegazione del problema con esempio pratico concreto — ordine da 500 taniche confermato per errore a 50 — seguita da domanda esplicita dell'utente su cosa sia la scelta professionale e cosa facciano gli ERP best-in-class). Il dettaglio di ciascuna decisione, incluso il ragionamento e i riferimenti ai comportamenti di SAP/Odoo/Dynamics 365/NetSuite dove pertinente, è in §4 sotto — **da leggere per intero prima di implementare**, non solo la tabella riassuntiva, perché contiene le microdecisioni (es. perché il blocco su ordine fatturato non ammette eccezioni "con avviso") che altrimenti andrebbero perse.

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

> **✅ RISOLTO — opzione A scelta ed ESEGUITA il 22/07/2026** (schema già in produzione, verificato il 24/07/2026 sul codice reale). Il ragionamento originale che segue è lasciato intatto per memoria storica del perché fosse la scelta più delicata del piano; il risultato è che oggi `ordini_clienti.stato` ammette già `'superato'` e le colonne `versione`/`ordine_padre_id` esistono già — vedi in fondo a questa sezione il riferimento alla migrazione eseguita.

`ordini_clienti.stato` aveva, prima della migrazione:
```sql
CHECK (stato IN ('in_lavorazione','parzialmente_spedito','spedito','completato','annullato'))
```

SQLite **non supporta** `ALTER TABLE ... MODIFY/DROP CONSTRAINT`: aggiungere un valore ammesso a un CHECK esistente richiede **ricreare la tabella** (rinominare la vecchia, crearne una nuova con il CHECK aggiornato, ricopiare tutti i dati, ricreare indici/trigger, droppare la vecchia) — non c'era nessun precedente di questo tipo nelle migrazioni scritte fino a quel momento in questo progetto (tutte additive: nuove colonne, nuovi indici, mai un CHECK modificato). `ordini_clienti` ha inoltre **due tabelle con FK NOT NULL verso di lei** (`spedizioni.ordine_id`, `documenti_trasporto.ordine_id`, oltre a `ordini_clienti_righe`) da preservare intatte durante la ricreazione — era l'operazione di schema più rischiosa di tutte quelle fatte fino a quel momento in questo progetto.

**Tre opzioni erano state messe a confronto:**

- **A) Aggiungere `'superato'` al CHECK**, ricreando la tabella. Coerente al 100% con il modello Acquisti (stessa parola, stesso significato, distinguibile da `annullato` in ogni filtro/report). Rischio più alto, ma il più corretto concettualmente: "sostituito da una versione più recente" e "annullato perché sbagliato/rifiutato" sono eventi diversi e vale la pena poterli distinguere in un report. **← Questa è l'opzione scelta ed eseguita.**
- **B) Riusare `'annullato'`** per marcare anche il vecchio ordine quando viene revisionato, aggiungendo una colonna separata (es. `ordini_clienti.superato_da_id INTEGER REFERENCES ordini_clienti(id)`) per distinguere "annullato e basta" da "annullato perché sostituito". Zero rischio sullo schema esistente (solo colonne additive), ma un ordine "superato" finirebbe comunque nei filtri/contatori di "ordini annullati" a meno di escluderlo esplicitamente ovunque quel filtro esiste oggi (rischio di dimenticare un punto). Non scelta.
- **C) Nessun nuovo stato**: il vecchio ordine resta `in_lavorazione` ma con un flag/colonna che lo marca "superato da una revisione" senza cambiare `stato` affatto, e tutte le viste/query che oggi considerano `in_lavorazione` come "ordine attivo/aperto" andrebbero riviste per escludere anche questo caso. Più lavoro di verifica trasversale, mai raccomandata. Non scelta.

**Migrazione già eseguita**: `db/migrations/2026_07_22d_ordini_clienti_versione_e_superato.php` — procedura completa e verificata (introspezione della DDL reale da `sqlite_master`, mai trascritta a mano; tabella nuova creata sotto nome temporaneo per non rompere le FK di `spedizioni`/`documenti_trasporto`/`ordini_clienti_righe` durante la ricreazione; verifica conteggio righe; `PRAGMA foreign_key_check` post-ricreazione; idempotente). `db/init_sqlite.sql` riflette già lo schema aggiornato per le installazioni nuove. **Nessuna azione richiesta su questo punto per riprendere il lavoro**: si parte direttamente dalla Tappa 3 (funzione di revisione), la Tappa 2 del piano sotto è da considerarsi completata.

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

## 4. Decisioni di design — stato: 4 chiuse, 1 ancora aperta

### 4.1 — Rappresentazione di "superato" — ✅ RISOLTO (implementato, vedi §2)

Opzione A, già in produzione dal 22/07/2026. Nessuna azione ulteriore richiesta.

### 4.2 — Quando è permesso revisionare — ✅ DECISO 24/07/2026

**Decisione**: si può creare una nuova versione **solo** se l'ordine è nello stato esatto `'in_lavorazione'` — mai da `'parzialmente_spedito'`, `'spedito'`, `'completato'`, `'annullato'`, `'superato'`.

**Motivazione, spiegata all'utente con un esempio concreto durante la conversazione del 24/07/2026** (ordine di 500 taniche di detersivo confermato per errore a 50, scoperto il giorno dopo): finché nulla è stato spedito, l'ordine è ancora "solo carta" — correggerlo con una revisione è sicuro, non lascia nulla di incoerente indietro. Nel momento in cui anche una sola unità è stata spedita, quella merce è fisicamente uscita dal magazzino e un documento di trasporto è stato emesso: non è più "un errore da correggere", è un fatto già accaduto. Una revisione a quel punto dovrebbe decidere cosa fare della parte già spedita (portarla nella nuova versione? lasciarla nella vecchia, ormai "superata" ma con roba fisicamente uscita a suo nome? nessuna delle due è pulita) — la scelta più sicura è non aprire questa casistica affatto, e lasciare che un ordine parzialmente evaso si gestisca con gli strumenti già esistenti (nota di credito quando esisterà, o un secondo ordine per il residuo).

È il mirror esatto della restrizione già usata lato Acquisti (`qk_ordine_crea_revisione()`: solo da stato `'inviato'`, cioè "nulla ancora ricevuto"), tradotta in "nulla ancora spedito" per le vendite. Verificabile con un'unica condizione sullo stato stesso, dato che `parzialmente_spedito`/`spedito` sono raggiunti SOLO se `quantita_spedita > 0` su almeno una riga (logica S7, già verificata e documentata in `REVISIONE - Analisi Documentazione Ciclo Vendite.md`).

**Implica anche** (già presente nella Tappa 3 originale, confermato qui): un guard aggiuntivo che blocchi la revisione se esiste già una `spedizioni` collegata all'ordine, anche nel caso (teorico, ma da coprire per difesa in profondità — stesso principio già applicato più volte in questo progetto) in cui lo stato fosse stato manomesso o disallineato.

### 4.3 — Ordine già fatturato (`stato_fatturazione = 'fatturato'`) — ✅ DECISO 24/07/2026, esplicitamente, con motivazione basata su best practice ERP

**Decisione**: la revisione è **sempre bloccata**, senza eccezioni, se `stato_fatturazione = 'fatturato'` — nessuna via "permetti con avviso/reset". Il bottone "Crea nuova versione" non deve nemmeno comparire (o deve comparire disabilitato) su un ordine in questo stato; se richiamato comunque via azione diretta (stesso principio di difesa in profondità di §4.2), la funzione deve rifiutare con un messaggio esplicito.

**Testo del messaggio da mostrare** (deciso durante la conversazione, da riusare identico in implementazione): *"Ordine già fatturato: per correggerlo serve una nota di credito, non una revisione."*

**Motivazione — chiesta esplicitamente dall'utente ("quale sarebbe una scelta professionale? cosa fanno i best in class ERP per casi come questo?") e qui riportata per esteso, non solo la conclusione**:

Il principio comune a SAP, Odoo, Dynamics 365 e NetSuite (gli stessi quattro sistemi già usati come riferimento di confronto altrove in questa cartella di documentazione, es. §1.5 di `REVISIONE - Analisi Documentazione Ciclo Vendite.md`) è: **una volta che esiste un documento a valle con rilevanza fiscale che si basa sui dati di un documento a monte, il documento a monte diventa congelato per quella parte**. Non lo si modifica più — se emerge un errore, non si riscrive il passato, si crea un **documento nuovo** che corregge quello vecchio (tipicamente una nota di credito), lasciando entrambi visibili e tracciati. Concretamente in SAP SD: una volta creato un documento di fatturazione a partire da un ordine di vendita, i campi rilevanti ai fini prezzo/quantità dell'ordine diventano non più liberamente modificabili proprio per questo motivo — la correzione passa da un documento di storno. Stesso principio in Odoo (fattura validata → le eventuali differenze si gestiscono con una nota di credito, non riaprendo l'ordine) e in Dynamics/NetSuite.

È coerente anche con la normativa italiana sulla fattura elettronica: una fattura già inviata allo SdI non si può "editare" — se c'è un errore, si emette una nota di credito; la fattura sbagliata resta comunque valida ed esistente, mai cancellata o alterata retroattivamente.

**Perché "blocca sempre" e non "permetti con avviso"**: un permesso-con-avviso lascerebbe comunque la possibilità di modificare (indirettamente, tramite una nuova versione collegata) qualcosa che una fattura già emessa e magari già registrata in contabilità dà per certo — esattamente il tipo di disallineamento silenzioso che questo stesso progetto ha già dovuto correggere altrove (mittente del DDT letto dal vivo invece che congelato, riserva di stock mai rilasciata all'annullamento). "Blocca sempre" è l'unica opzione che non introduce una nuova versione di quello stesso tipo di bug.

**Limite noto, segnalato esplicitamente e volutamente fuori scope**: Quack oggi non ha alcun meccanismo di nota di credito funzionante — la tabella `fatture`/`fatture_righe` esiste a schema ma è codice morto (nessun INSERT/UPDATE/SELECT in tutto il codebase, vedi Problema F1 in `REVISIONE - Analisi Documentazione Ciclo Vendite.md`, §3.7). Bloccare la revisione su un ordine fatturato è comunque la scelta corretta anche in questa situazione (evita di introdurre un disallineamento), ma lascia aperta la domanda pratica "e allora come si corregge davvero un ordine già fatturato, oggi?" — la risposta onesta è "manualmente, fuori dal sistema", che è lo stato di fatto del progetto, non una lacuna introdotta da questo piano. Non risolto qui, deliberatamente: è il problema F1, un intervento a sé.

### 4.4 — Ri-esecuzione del controllo compliance sulla nuova versione — raccomandazione scritta, non ancora confermata esplicitamente

Raccomandazione (invariata dalla stesura originale di questo piano, il 22/07/2026): sì, ri-eseguire sempre `qk_compliance_check_sale()` sulla nuova versione, mai ereditare "per fiducia" l'esito della vecchia — la nuova versione può avere righe/quantità diverse. Coerente con la chiusura del gap compliance-ordini-diretti (Problema 2, §1.6 di `REVISIONE - Analisi Documentazione Ciclo Vendite.md`): non avrebbe senso aver chiuso quel gap e poi riaprirlo qui per la via della revisione. **Nessuna obiezione sollevata finora, ma non è stata oggetto di conferma esplicita nella conversazione del 24/07/2026** (a differenza di §4.2/§4.3) — da confermare con un sì esplicito prima di implementare, per non decidere al posto dell'utente su un punto mai discusso a voce.

### 4.5 — Collocazione UI del bottone — ancora aperta, non discussa

Non affrontata nella conversazione del 24/07/2026. La proposta originale (§Tappa 6 sotto) resta: bottone "Crea Nuova Versione" su `app/views/sales/orders/_detail.php`, stesso posto di annulla/completa/riapri, visibile solo quando lo stato lo permette (§4.2), con badge "Versione N" e link bidirezionale padre↔figlio. Da confermare o correggere prima di implementare la Tappa 6.

---

## 5. Microtappe

Ogni tappa è pensata per essere ripresa in una sessione diversa senza perdere contesto: stato in ingresso e stato in uscita sono espliciti, così chi riprende (anche un Claude "che riparte da zero", per usare la formula di CLAUDE.md) sa esattamente a che punto siamo.

### Tappa 1 — Schema: colonne additive (`versione`, `ordine_padre_id`) — ✅ COMPLETATA il 22/07/2026
**Stato in ingresso**: nessuna colonna nuova.
**Cosa è stato fatto**: colonne aggiunte sia a `db/init_sqlite.sql` sia tramite `db/migrations/2026_07_22d_ordini_clienti_versione_e_superato.php` (idempotente, copre anche la Tappa 2 nello stesso file). `db/init_mysql.sql` da riverificare esplicitamente per allineamento (non controllato durante questa sessione — segnalato qui invece di darlo per scontato, regola CLAUDE.md punto 7).
**Stato in uscita — verificato il 24/07/2026 rileggendo il codice reale**: colonne presenti, nessun codice applicativo le usa ancora (Tappe 3-6 a zero), nessun comportamento visibile cambiato.
**Test**: non eseguiti espressamente in questa sessione (il lavoro di verifica del 24/07 si è limitato a leggere schema/migrazione); da fare comunque una `PRAGMA table_info` di conferma quando si riprende il lavoro, prima di scrivere la Tappa 3, per non dare per scontato lo stato del DB su cui si opera (regola CLAUDE.md punto 9).

### Tappa 2 — Schema: il valore "superato" — ✅ COMPLETATA il 22/07/2026 (opzione A, vedi §2)
**Stato in ingresso**: Tappa 1 completata.
**Cosa è stato fatto**: la stessa migrazione `2026_07_22d_ordini_clienti_versione_e_superato.php` ha ricreato `ordini_clienti` con procedura verificata — introspezione DDL reale da `sqlite_master` (mai trascritta a mano), tabella nuova sotto nome temporaneo per preservare le FK di `ordini_clienti_righe`/`spedizioni`/`documenti_trasporto` durante l'operazione, verifica conteggio righe prima/dopo, ricreazione indici letti da `sqlite_master` (non hardcoded), `PRAGMA foreign_key_check` finale, tutto in una singola transazione con rollback automatico in caso di qualunque controllo fallito. Procedura più prudente di quella originariamente abbozzata qui sotto (che prevedeva un test preventivo su copia separata del DB) — la migrazione stessa è già scritta per essere sicura sul DB reale, grazie ai controlli integrati (conteggio righe, FK check, transazione atomica).
**Stato in uscita**: `ordini_clienti.stato` accetta `'superato'`, dati esistenti intatti (verificato dalla migrazione stessa via conteggio+FK check al momento dell'esecuzione).
**Nota per chi riprende il lavoro**: nessuna azione richiesta su questa tappa. Si parte dalla Tappa 3.

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

## 6. Riepilogo: cosa serve prima di riprendere l'implementazione

Aggiornato il 24/07/2026. Delle 5 decisioni del §4, **4 sono chiuse**:

- §4.1 (rappresentazione "superato") — ✅ risolto, implementato.
- §4.2 (quando è permesso revisionare: solo `in_lavorazione` puro) — ✅ deciso esplicitamente.
- §4.3 (ordine fatturato: blocco sempre, nessuna eccezione, motivazione da best practice ERP) — ✅ deciso esplicitamente, il più dettagliato dei tre.
- §4.4 (ri-esecuzione compliance sulla nuova versione) — raccomandazione scritta e mai contestata, ma **non confermata a voce**: chiedere un sì/no esplicito prima di implementare la Tappa 3, oppure procedere con la raccomandazione se non si ricorda obiezione e lo si segnala comunque nel riepilogo di fine lavoro.
- §4.5 (collocazione UI del bottone) — **ancora completamente aperta**, non discussa. Da chiedere prima della Tappa 6, oppure procedere con la proposta di default (`_detail.php`, stesso posto di annulla/completa/riapri) segnalandolo esplicitamente come scelta non confermata.

**Non implementare nulla di questo piano finché l'utente non lo chiede esplicitamente** — per sua richiesta diretta del 24/07/2026 ("non ho tempo per implementarla ora e userò quello che scrivi per implementarla appena possibile"). Quando verrà richiesta l'implementazione, si parte dalla Tappa 3 (Tappe 1-2 già fatte) seguendo lo stesso procedimento già rodato per gli altri interventi di questa cartella: rilettura immediata dei file coinvolti prima di ogni modifica, test reali (non solo sintattici) ad ogni tappa, aggiornamento di questo stesso documento a lavoro concluso.
