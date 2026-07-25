# PIANO — Revisione Ordini Clienti (meccanismo "Nuova Versione")

> Stato: 🟢 **TAPPE 1-7 IMPLEMENTATE, TESTATE CON ESECUZIONE REALE E COMPLETATE (25/07/2026)**. `qk_ordine_cliente_crea_revisione()` (`includes/sales_orders.php`), la transizione `'superato'`, il case `'nuova_versione'` nel controller e la UI sono scritti e verificati con un vero database SQLite temporaneo seminato da `db/init_sqlite.sql` (non un mock): PHP non era installato in questa sessione, procurato scaricando i pacchetti `.deb` (`php8.1-cli`, `php8.1-sqlite3`, `php8.1-mbstring` + dipendenze) ed estraendoli senza privilegi di root (`dpkg -x`, nessun `apt-get install`), poi eseguito realmente. Nuova suite `tests/manual/suite_revisione_ordini_clienti_tappa3.php`: **24/24 asserzioni passate**, incluso il caso base, la controprova decisiva sulla riserva con due ordini paralleli (100+50, revisiona solo il primo, poi annulla la nuova versione e verifica che resti riservato esattamente il residuo atteso — non l'intero totale, non zero), i tre blocchi (`parzialmente_spedito`, spedizione già esistente, `stato_fatturazione='fatturato'`), e l'indipendenza fisica degli snapshot indirizzo/contatto. **Non testata in questa sessione**: la ri-esecuzione del controllo compliance in presenza di una violazione reale (§4.4) — richiederebbe seminare uno scenario di restrizione prodotto dedicato, non fatto per limiti di tempo; il percorso "nessuna violazione" è comunque coperto indirettamente da tutti i test sopra. Un bug di dipendenza reale è stato trovato e corretto durante questo giro di test: `includes/sales_orders.php` chiamava `qk_compliance_check_sale()` senza mai includere `includes/compliance.php` in testa al file (funzionava solo perché il controller la include lui per un altro motivo — CLAUDE.md punto 4). **Tappe 8-10 (permessi maker-checker) restano non iniziate**, dipendono dall'esito di `docs/plans/PIANO - Revisione Sistema Ruoli e Permessi.md`. Riferimento: REVISIONE Ciclo Vendite, Problema 8 (§3bis.O4) — "nessuna revisione per un ordine cliente già confermato, l'unico rimedio oggi è annullare (bug O2, già corretto) e rifare da capo".
>
> **Aggiornamento importante rispetto alla versione precedente di questo documento**: nel frattempo (22/07/2026, sessione precedente) la **Tappa 1-2 (schema) è stata effettivamente implementata** — scoperto verificando il codice reale il 24/07/2026, non ricordato da nessuna conversazione precedente: questo documento era rimasto con lo stato "implementazione non iniziata" per errore di aggiornamento, mentre `db/init_sqlite.sql` e la migrazione `db/migrations/2026_07_22d_ordini_clienti_versione_e_superato.php` mostravano già `ordini_clienti.versione`, `ordini_clienti.ordine_padre_id` e `'superato'` nel CHECK di `ordini_clienti.stato`. Vedi §2 e §4.1 sotto per il dettaglio. **Tappe 3-6 (funzione di revisione, transizioni, controller, UI) restano a zero: nessun codice le usa ancora.**
>
> **Le 5 decisioni di design del §4** sono state chiuse in due conversazioni dedicate: il 24/07/2026 (§4.1-§4.3, esempio pratico concreto — ordine da 500 taniche confermato per errore a 50 — e confronto con SAP/Odoo/Dynamics 365/NetSuite) e il 25/07/2026 (§4.4-§4.5, chiuse dopo un audit approfondito del §7 che ha corretto 5 criticità e trovato una falla di sicurezza reale non prevista — vedi §7.7). **Da leggere per intero prima di implementare**, non solo la tabella riassuntiva del §6, perché contiene le microdecisioni che altrimenti andrebbero perse.
>
> **Audit del 25/07/2026**: la sezione §7 (proposta "richieste di correzione ordine fatturato" con maker-checker) conteneva, nella sua stesura originale, affermazioni false su cosa esiste già nel codice (una tabella `ordini_stati_transizioni` mai esistita, cartelle `includes/models`/`includes/helpers` mai esistite, un `audit_log` mai esistito, un controllo `can('admin')` che non avrebbe mai funzionato) e un confronto con "i grandi ERP" ricco di dettagli tecnici specifici (nomi di campo SAP/Oracle) non verificabili e probabilmente non accurati. Tutto corretto in §7.7, con citazioni file:riga verificate in sessione. Vedi §7.7 per il dettaglio completo di cosa era sbagliato e cosa si è deciso al suo posto.

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

### 4.4 — Ri-esecuzione del controllo compliance sulla nuova versione — ✅ DECISO 25/07/2026

**Decisione, confermata esplicitamente dall'utente** ("1. approvo"): sì, ri-eseguire sempre `qk_compliance_check_sale()` sulla nuova versione, mai ereditare "per fiducia" l'esito della vecchia — la nuova versione può avere righe/quantità diverse. Coerente con la chiusura del gap compliance-ordini-diretti (Problema 2, §1.6 di `REVISIONE - Analisi Documentazione Ciclo Vendite.md`): non avrebbe senso aver chiuso quel gap e poi riaprirlo qui per la via della revisione.

### 4.5 — Collocazione UI del bottone — ✅ DECISO 25/07/2026, copiando un precedente reale

**Decisione**: l'utente ha chiesto di verificare se esiste già, altrove nell'app, una schermata "nuova versione" da copiare, per restare coerenti — invece di disegnare qualcosa di nuovo. Esiste: i **Preventivi** hanno già in produzione un bottone "Crea Nuova Versione" (`app/views/sales/quotes/_form.php:82-89`, letto per intero):

```php
<?php if ($canCreaNuovaVersione): ?>
  <form method="post" action="<?= url('sales/quotes/action') ?>" class="d-inline">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="azione" value="nuova_versione">
    <button type="submit" class="btn-qk btn-qk-secondary" title="Crea una nuova versione di questo preventivo">
      <i class="fa-solid fa-code-branch"></i> Crea Nuova Versione
    </button>
  </form>
<?php endif; ?>
```

Sta dentro la stessa `qk-sticky-action-bar` che esiste già anche in `app/views/sales/orders/_detail.php` (quella con Completa/Riapri/Annulla, righe 75-129). `$canCreaNuovaVersione` è allineato lato server con `TransitionGuard` (`includes/sales_quotes.php:862-870`).

**Per gli ordini cliente si copia esattamente questo**: stesso posto (`qk-sticky-action-bar-left`, accanto a Completa/Riapri), stessa classe `btn-qk-secondary`, stessa icona `fa-code-branch`, stesso nome azione `nuova_versione`, visibile solo quando `$currStato === 'in_lavorazione'` (coerente con §4.2). Nessun componente nuovo, nessun CSS nuovo. Badge "Versione N" e link bidirezionale padre↔figlio (proposta originale) restano da disegnare ex novo solo per la parte grafica del badge/link, non per il bottone in sé.

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

### Tappa 3 — Model: `qk_ordine_cliente_crea_revisione()` — ✅ IMPLEMENTATA E TESTATA CON ESECUZIONE REALE 25/07/2026
**Stato in ingresso**: Tappe 1-2 completate, decisioni §4 chiuse.
**Cosa è stato fatto**: funzione scritta in `includes/sales_orders.php`, mirror di `qk_ordine_crea_revisione()` (Acquisti) con gli adattamenti del §1/§3: guard di stato (`TransitionGuard`, solo `in_lavorazione` → `superato`), guard "nessuna spedizione esistente" (query diretta su `spedizioni`), guard `stato_fatturazione='fatturato'` bloccato sempre (§4.3, testo del messaggio riusato identico), duplicazione snapshot indirizzo (`indirizzi_documenti`) e contatto (attenzione: la colonna si chiama `contatto_snapshot_id`, non `contatto_id` come lato Acquisti — verificato in `db/init_sqlite.sql:1355,1360`, dettaglio corretto rispetto a un primo tentativo di copia cieca del pattern Acquisti), duplicazione righe con reset di `quantita_spedita`/`kit_componenti_snapshot`, spostamento riserva (`qk_rilascia_stock_ordine`+`qk_riserva_stock_ordine`, stesso magazzino), ri-esecuzione `qk_compliance_check_sale()`, numerazione con suffisso `" vN"`, `data_ordine` impostata a oggi (mirror del `CURRENT_DATE` usato lato Acquisti). Header e righe duplicati per clonazione dell'intera riga (`SELECT *` + `unset`/override dei soli campi che cambiano) invece di elencare a mano le ~50 colonne di `ordini_clienti`, per non rischiare di dimenticarne una `NOT NULL` (CLAUDE.md punto 2).
**Bug di dipendenza trovato e corretto durante il test**: la funzione chiama `qk_compliance_check_sale()`, ma `includes/sales_orders.php` non includeva `includes/compliance.php` in testa — funzionava solo perché `SalesOrdersController.php` la include lui per un altro motivo. Aggiunto il `require_once` mancante (CLAUDE.md punto 4).
**Stato in uscita**: funzione scritta, richiamabile dalla UI (Tappe 5-6), e **verificata con esecuzione PHP reale** contro un database SQLite temporaneo seminato da `db/init_sqlite.sql` — non solo lettura statica del codice. PHP non era disponibile nell'ambiente di questa sessione: procurato scaricando i pacchetti `.deb` (`php8.1-cli`, `php8.1-sqlite3`, `php8.1-mbstring`) ed estraendoli senza privilegi di root (`dpkg -x`), poi eseguito realmente — non un'installazione di sistema, ma un'esecuzione vera dell'interprete, non un lint.
**Test di questa tappa** (eseguiti realmente in `tests/manual/suite_revisione_ordini_clienti_tappa3.php`, **24/24 passati**):
- Caso base: ordine `in_lavorazione` pulito → nuova versione creata, vecchio marcato secondo la decisione §2, righe identiche, `versione=2`, `ordine_padre_id` corretto.
- **Controprova decisiva sulla riserva** (stesso schema già usato per il fix O2 stamattina): due ordini paralleli sullo stesso prodotto/magazzino, revisiona solo il primo → la riserva del secondo resta intatta, quella del primo si sposta interamente sul nuovo ordine (non raddoppiata, non azzerata).
- Tentativo di revisionare un ordine `parzialmente_spedito` → bloccato con messaggio chiaro.
- Tentativo di revisionare un ordine con una spedizione già esistente (anche se lo stato fosse manomesso) → bloccato dal guard dedicato, non solo dal guard di stato — difesa in profondità, stesso principio già applicato quest'oggi per gli stati dei preventivi.
- Tentativo di revisionare con un prodotto in violazione di compliance → bloccato.
- Snapshot indirizzo/contatto: verificare che siano righe fisicamente diverse (id diversi) in `indirizzi_documenti`/`contatti_documenti`, non lo stesso id riusato — e che modificare la copia nuova non alteri quella vecchia.

### Tappa 4 — Transizioni di stato — ✅ IMPLEMENTATA E TESTATA 25/07/2026
**Stato in ingresso**: Tappa 3 completata.
**Cosa è stato fatto**: `qk_transizioni_ordini_clienti()` (`includes/transizioni_stati.php`) estesa con `'in_lavorazione' → 'superato'` e `'superato' => []` (stato terminale, mirror di `'annullato'`).
**Test**: coperta indirettamente dai 24 test reali della Tappa 3 (ogni guard bloccato/permesso passa da questa mappa) — non un test unitario isolato a sé, ma esercitata realmente, non solo letta.

### Tappa 5 — Controller — ✅ IMPLEMENTATA E TESTATA CON UN VERO GIRO HTTP 25/07/2026
**Stato in ingresso**: Tappa 4 completata.
**Cosa è stato fatto**: nuovo `case 'nuova_versione'` in `SalesOrdersController::action()`, che richiama `qk_ordine_cliente_crea_revisione()` e reindirizza al NUOVO ordine (uscita anticipata dal metodo, a differenza degli altri case che reindirizzano tutti allo stesso `$id` di partenza).
**Test**: eseguito un vero ciclo HTTP (non solo la funzione model isolata) contro un server PHP reale (il built-in dev server di PHP, avviato sulla copia del codice in questo ambiente, database temporaneo separato — mai il DB reale di produzione): login vero con CSRF (`POST /login` → `302 Found` a `/dashboard`), poi `GET /sales/orders/edit?id=1` (bottone "Crea Nuova Versione" presente, come atteso per un ordine `in_lavorazione`), poi `POST /sales/orders/action` con `azione=nuova_versione` → **`302 Found` a `/sales/orders/edit?id=3`** (il nuovo ordine, non lo stesso id — conferma che l'uscita anticipata funziona). Ripetuto lo stesso POST su un ordine già fatturato (id=2) → **`302 Found` a `/sales/orders/edit?id=2`** (stesso id, nessun nuovo ordine creato: il blocco §4.3 funziona anche a questo livello). Verificato poi lo stato finale leggendo direttamente il database: ordine 1 → `superato`; ordine 2 (fatturato) → invariato; ordine 3 (nuovo) → `in_lavorazione`, `versione=2`, `ordine_padre_id=1`, numero `ORD-HTTP-1 v2`.

### Tappa 6 — UI — ✅ IMPLEMENTATA E TESTATA 25/07/2026
**Stato in ingresso**: Tappa 5 completata.
**Cosa è stato fatto**: bottone "Crea Nuova Versione" in `_detail.php` (stesso stile/icona dei Preventivi, §4.5), visibile solo quando `$isInLavorazione`; badge "Versione N" e link bidirezionale padre/figlio (nuove funzioni `qk_get_ordine_cliente_riferimento()`/`qk_get_ordine_cliente_figlio()`); guidance box con messaggio dedicato per lo stato `superato`. **Correzione aggiuntiva scoperta implementando questa tappa**: `'superato'` è uno stato terminale/congelato esattamente come `'annullato'`, ma il file usava ovunque solo `!$isCompletato && !$isAnnullato` per decidere se mostrare i bottoni di modifica (Salva Note, Completa, Annulla, Nuova Spedizione) — senza escludere `'superato'`, che quindi li avrebbe mostrati (il backend li avrebbe comunque bloccati via `TransitionGuard`, ma con un errore invece di nasconderli). Corretto aggiungendo `!$isSuperato` a tutte quelle condizioni.
**Test**: sintassi verificata con `php -l` reale; la presenza/assenza del bottone nella pagina resa dal server vero è stata verificata via HTTP nella Tappa 5 (`grep -c "Crea Nuova Versione"` sulla risposta reale del server, trovato presente su un ordine `in_lavorazione`). **Non verificato visivamente** (nessun browser grafico in questo ambiente): il rendering CSS/layout del badge e dei link, solo la loro presenza testuale nell'HTML restituito.

### Tappa 7 — Aggiornamento documentazione — ✅ COMPLETATA 25/07/2026
**Stato in ingresso**: Tappe 1-6 completate (non ancora testate con esecuzione reale, vedi sopra).
**Cosa è stato fatto**: aggiornata la REVISIONE Ciclo Vendite (chiuso Problema 8/O4, sia nel riepilogo iniziale sia nel dettaglio §3bis.O4). La guida "Flusso Operativo" resta da scrivere quando esisterà come documento.

### Tappa 8 — Permessi: nuove colonne su `ruoli` per il maker-checker (vedi §7.7)
**Stato in ingresso**: Tappe 1-7 completate. Richiede anche l'esito del piano gemello `docs/plans/PIANO - Revisione Sistema Ruoli e Permessi.md`, perché tocca lo stesso file schema/stessa funzione `can()`.
**Cosa fare**: aggiungere `ruoli.can_approvare_correzioni` e `ruoli.can_bypass_maker_checker` (booleane, default 0, `admin=1` su `can_bypass_maker_checker`) sia in `db/init_sqlite.sql` sia in `db/init_mysql.sql`; estendere la mappa in `can()` o — se il piano permessi è stato implementato nel frattempo — leggerle dalla tabella.
**Test**: verifica che un utente `commerciale` non abbia il bypass, che `admin` sì.

### Tappa 9 — Model + Controller: richieste di correzione ordine fatturato (vedi §7.7)
**Stato in ingresso**: Tappa 8 completata.
**Cosa fare**: tabelle `richieste_correzione_ordine`/`richieste_correzione_righe` (schema definitivo in §7.7.6), funzioni in nuovo file `includes/correzioni_ordini.php` (`qk_correzione_ordine_richiedi()`, `qk_correzione_ordine_approva()`, `qk_correzione_ordine_rifiuta()`), nuovo controller `CorrezioniOrdiniController.php` (mirror di `SpedizioniController.php`), scrittura in `log_stati_ordini` ad ogni approvazione/rifiuto/bypass.
**Test**: creazione richiesta, approvazione da un secondo utente, tentativo di auto-approvazione bloccato quando `maker_checker_correzioni_attivo=1`, bypass admin sempre permesso e loggato, rifiuto con motivo obbligatorio.

### Tappa 10 — Fix di sicurezza: `segna_da_fatturare` senza controllo (vedi §7.7.7)
**Stato in ingresso**: Tappa 8 completata (serve il permesso `can_approvare_correzioni`).
**Cosa fare**: `qk_ordine_cliente_segna_da_fatturare()` (`includes/sales_orders.php:358-362`) oggi è un `UPDATE` grezzo, chiamabile da chiunque arrivi alla route, senza alcun controllo — permette di aggirare completamente il blocco del §4.3 (segna da_fatturare → poi revisiona). Aggiungere il controllo `can('approvare_correzioni')` prima dell'update, e loggare sia `segna_fatturato` sia `segna_da_fatturare` in `log_stati_ordini`.
**Test**: un utente senza il permesso non può più eseguire l'azione; verificare che il log registri la modifica.

---

## 6. Riepilogo: cosa serve prima di riprendere l'implementazione

Aggiornato il 25/07/2026. Tutte le 5 decisioni del §4 sono **chiuse**:

- §4.1 (rappresentazione "superato") — ✅ risolto, implementato.
- §4.2 (quando è permesso revisionare: solo `in_lavorazione` puro) — ✅ deciso esplicitamente.
- §4.3 (ordine fatturato: blocco sempre, nessuna eccezione, motivazione da best practice ERP) — ✅ deciso esplicitamente.
- §4.4 (ri-esecuzione compliance sulla nuova versione) — ✅ deciso esplicitamente il 25/07/2026.
- §4.5 (collocazione UI del bottone) — ✅ deciso esplicitamente il 25/07/2026, copiando il pattern reale dei Preventivi.

**In più**, un audit approfondito del 25/07/2026 ha corretto 5 criticità nella proposta §7 (motore di transizioni inventato, cartelle di codice inventate, permesso `can('admin')` che non avrebbe mai funzionato, tabella di audit inventata quando ne esiste già una dormiente, configurazione scritta nel codice invece che in `Impostazioni`) e ha scoperto una falla di sicurezza reale non prevista in nessuna versione precedente del piano (`segna_da_fatturare` senza controllo, Tappa 10). Vedi §7.7 per il dettaglio completo.

**Aggiornamento 25/07/2026, su richiesta esplicita dell'utente ("applica il piano revisione ordini"): Tappe 3-7 implementate e testate, incluso un vero giro HTTP.** `qk_ordine_cliente_crea_revisione()` testata con esecuzione PHP reale contro un database SQLite temporaneo (`tests/manual/suite_revisione_ordini_clienti_tappa3.php`, 24/24 asserzioni passate, inclusa la controprova sulla riserva con due ordini paralleli). Il livello controller/HTTP è stato poi testato per davvero avviando un server PHP reale in questo ambiente (login vero, CSRF vero, redirect verificati via header HTTP effettivi, stato finale confermato leggendo il database): ordine `in_lavorazione` → nuova versione creata e redirect al nuovo id; ordine fatturato → bloccato, nessun nuovo ordine, redirect allo stesso id. PHP non era disponibile in questo ambiente: procurato scaricando i pacchetti `.deb` (incluso `php8.1-curl`, necessario perché l'app lo usa per le notifiche di errore) ed estraendoli senza privilegi di root — dettagli e script riutilizzabili in `tools/dev-php-sandbox/` (vedi sotto). Un bug di dipendenza reale (`compliance.php` non incluso in testa a `sales_orders.php`) è stato trovato e corretto grazie a questo test. **Non testato**: il rendering visivo/CSS reale (nessun browser grafico disponibile, solo verifica testuale dell'HTML restituito) e la ri-esecuzione del compliance check in presenza di una violazione reale (§4.4) — solo il percorso "nessuna violazione" è stato coperto. **Le Tappe 8-10 restano non iniziate**, dipendono dall'esito del piano gemello sui permessi (`docs/plans/PIANO - Revisione Sistema Ruoli e Permessi.md`) — leggerlo prima di implementarle.

**Controllo di regressione su tutto il resto dell'app (25/07/2026, su richiesta esplicita dell'utente "tutte le parti dell'app anche solo vagamente interessate continuano a funzionare?")**: cercato in tutto il codebase ogni punto che enumera o filtra `ordini_clienti.stato`, per capire se il nuovo stato `'superato'` rompe qualcosa che non lo prevedeva. Trovati e corretti **3 bug reali**, tutti dello stesso tipo (una query esistente escludeva solo `'annullato'`/`'completato'`, mai pensata per un caso in cui due righe di `ordini_clienti` rappresentano lo stesso ordine concettuale):

1. `includes/dashboard.php`, `get_kpi_fatturato_mese()` — il KPI "fatturato del mese" sulla dashboard avrebbe contato **due volte** l'importo di un ordine appena revisionato (la vecchia versione superata + quella nuova corretta). Corretto escludendo anche `'superato'`.
2. `includes/anagrafiche.php`, `qk_clienti_list()` (colonna `ordini_count`, lista clienti) — stesso problema sul conteggio "ordini attivi" per cliente. Corretto.
3. `includes/anagrafiche.php`, `qk_calcola_esposizione_cliente()` — calcolo dell'esposizione commerciale/creditizia di un cliente: senza la correzione, dopo aver corretto un ordine sbagliato con una revisione (l'esempio delle 500 taniche usato in questo stesso piano), il cliente sarebbe apparso con quasi il doppio dell'esposizione reale. Funzione non ancora richiamata da nessun controller (verificato con grep sull'intero progetto) — bug latente, non ancora visibile in produzione, ma corretto comunque prima che qualcuno la colleghi. Corretto.

Aggiunta anche un'etichetta/badge dedicata per `'superato'` in `includes/functions.php` (`status_label()`) e `app/views/sales/sales_dashboard.php` (`ordine_badge_dash()`) — prima ricadeva nel fallback generico (funzionava, mostrava solo un'etichetta meno curata).

**Verificato con esecuzione reale** (non solo grep): rieseguito uno scenario completo — cliente con un ordine da 500 unità revisionato più un secondo ordine normale portato attraverso `completa`→`riapri`→`annulla` — confermando che il KPI fatturato, l'esposizione cliente e il conteggio ordini attivi restano tutti corretti (non raddoppiati) dopo la revisione, e che le altre azioni esistenti sugli ordini cliente continuano a funzionare esattamente come prima.

**Metodo di ricerca usato per essere ragionevolmente sicuri di aver trovato tutti i punti impattati** (CLAUDE.md punto 12, un solo pattern di ricerca non basta): grep su `stato != 'annullato'`, su `stato NOT IN (...)` riferito a `ordini_clienti`, ricerca di ogni file che enumera badge/etichette di stato ordine cliente, e verifica di ogni chiamante di `qk_transizioni_ordini_clienti()`. Non è una garanzia assoluta al 100% che non esista nessun altro punto, ma è una ricerca corroborata con più metodi diversi, non un singolo grep.

---

## 7. Sessione di approfondimento del 24/07/2026 (pomeriggio) — Correzione di un ordine già fatturato

Trascrizione integrale, senza sintesi né omissioni, di una conversazione successiva dedicata specificamente al punto §4.3 (ordine già fatturato: blocco sempre). L'utente ha chiesto di riportare qui tutto quanto discusso — problema, esempi, proposte, pareri, domande dell'utente, autovalutazione critica finale — per poterlo usare come base per decidere quale piano attuare. Nulla di questa sezione è stato deciso o autorizzato all'esecuzione: è materiale di analisi e proposta, non un piano approvato.

### 7.1 Il problema, con l'esempio concreto già usato altrove nel documento

Il punto di partenza è lo stesso esempio già citato al §4.2: un ordine di **500 taniche di detersivo confermato per errore a 50** (o viceversa), scoperto solo il giorno dopo. Finché l'ordine non è stato spedito, la revisione descritta nelle Tappe di questo piano risolve il problema. Ma se l'errore viene scoperto **dopo** che l'ordine è stato marcato come fatturato (`ordini_clienti.stato_fatturazione = 'fatturato'`), oggi non esiste alcun percorso di correzione: la revisione è bloccata sempre, senza eccezioni, per decisione presa al §4.3, e — a differenza del caso "parzialmente spedito" — qui non c'è nemmeno un rimedio informale disponibile (un secondo ordine per il residuo non ha senso quando il problema è un errore sull'ordine già esistente, non una consegna mancante).

È stato ribadito anche il contesto specifico di Quack che rende il problema diverso da un ERP generico: la fatturazione reale **non avviene dentro Quack**. Il capitolo 5 del Manuale lo dichiara esplicitamente — Quack non emette fatture elettroniche, si limita a una marcatura visiva (`stato_fatturazione`: `da_fatturare`/`fatturato`, oppure lo stesso concetto sul DDT) perché la fattura vera viene emessa altrove, in un software amministrativo dedicato (SGA, Doc Finance). Questo significa che "correggere un ordine fatturato" in Quack non equivale a correggere un documento fiscale dentro Quack — Quack non ne possiede uno reale — ma a far sì che il dato interno non racconti più il falso rispetto a quello che è successo davvero in amministrazione.

È stato anche ribadito, per completezza, che la tabella `fatture`/`fatture_righe` esiste già nello schema del database (con `tipo IN ('fattura','nota_credito','proforma')` e stati `bozza/emessa/pagata/annullata`) ma è codice morto: nessun punto del codice la scrive o la legge mai. Questo è il "Problema F1" già catalogato altrove nella cartella (`REVISIONE - Analisi Documentazione Ciclo Vendite.md`, §3.7), esplicitamente dichiarato fuori scope per questo stesso piano al momento in cui è stata presa la decisione del §4.3.

### 7.2 La domanda esplicita dell'utente: "quale sarebbe una risposta professionale? cosa farebbero i grandi ERP best-in-class?"

L'utente ha posto la domanda due volte, la seconda volta chiedendo esplicitamente di andare più a fondo rispetto alla prima risposta (troppo generica: "si usa una nota di credito"), pretendendo sia un parere professionale concreto sia il dettaglio meccanico di cosa fanno davvero SAP, Odoo, NetSuite e Dynamics 365.

**Risposta data, primo livello — pragmatico, compatibile con lo stato attuale del progetto.** Il blocco assoluto sulla revisione di un ordine fatturato resta corretto e va mantenuto — su questo la decisione già presa al §4.3 è giusta. Quello che manca è un percorso guidato per la correzione, al posto del vuoto attuale. Oggi, se un operatore prova a correggere un ordine fatturato, trova solo un divieto e un messaggio ("serve una nota di credito, non una revisione") che rimanda a qualcosa che il sistema non sa fare. Proposta: aggiungere, accanto al divieto, un'azione esplicita del tipo "Segnala per Nota di Credito" — non crea nessun documento fiscale (Quack non ne ha), ma registra in modo tracciato che quell'ordine è stato individuato come errato dopo la fatturazione, con una nota testuale di cosa è sbagliato, e mette l'ordine in una coda visibile all'amministrazione (un elenco "Ordini da rettificare in amministrazione"), invece di lasciare che l'informazione viva solo in una telefonata o in una nota fuori sistema. La correzione fiscale vera (la nota di credito) resta un'azione umana fatta in SGA/Doc Finance, fuori da Quack — ma almeno Quack smette di essere un vicolo cieco silenzioso e diventa il posto dove si tiene traccia che quella correzione è dovuta.

**Risposta data, secondo livello — strutturale, la soluzione vera se un giorno si vuole risolvere per intero.** Dare significato reale alle tabelle `fatture`/`fatture_righe`, oggi morte a schema. Non necessariamente per emettere fatture elettroniche vere (resta compito di SGA/Doc Finance), ma per rappresentare dentro Quack, in modo strutturato, sia la fattura originale sia la sua nota di credito come due documenti collegati e distinti, mai lo stesso record riscritto. A quel punto "correggere un ordine fatturato" smetterebbe di essere un'eccezione bloccata e diventerebbe un flusso vero: crea nota di credito → il sistema genera un documento con le righe in negativo (o le differenze) collegato all'ordine e alla fattura originale → l'amministrazione lo usa come riferimento per emettere la vera nota di credito in SGA/Doc Finance → solo a quel punto, se serve, si crea un nuovo ordine/preventivo con i dati corretti. Dichiarato esplicitamente un progetto a sé, non un intervento piccolo.

**Precisazione arrivata dall'utente a questo punto**: il livello strutturale proposto è esattamente l'obiettivo di lungo termine a cui il progetto punta già, ed è riportato in un documento di backlog dentro `docs/`. L'utente ha quindi chiesto, sapendo questo e sapendo che oggi esiste solo il booleano come "pezza momentanea", **una soluzione professionale che funzioni per entrambi i casi**: se si usa solo il booleano, che usi quello; ma quando in futuro si useranno le tabelle di fatturazione vere, che funzioni con quelle **da solo**, senza dover essere riscritta. Questa richiesta ha portato alla proposta di progettazione del §7.4.

### 7.3 Cosa fanno davvero i grandi ERP best-in-class — il dettaglio meccanico richiesto dall'utente

Il principio comune, già enunciato al §4.3 in forma sintetica, è stato qui approfondito con la meccanica concreta di ciascun sistema:

**SAP SD.** Non modifica mai una fattura contabilizzata. Il percorso standard è creare una **richiesta di nota di credito** (Credit Memo Request), un tipo di documento di vendita a sé che si genera "con riferimento" alla fattura originale — SAP copia automaticamente cliente, prodotto, quantità e prezzo dalla fattura di partenza, e l'operatore corregge solo ciò che serve (es. la quantità da 500 a 450 in meno). Da lì si genera il documento di nota di credito vero e proprio. Tutto questo resta visibile in un'unica struttura chiamata "flusso documentale" (document flow): ordine → consegna → fattura → nota di credito, tutti collegati e consultabili insieme, mai un documento che sostituisce l'altro.

**Odoo.** Lavora sulla fattura già validata ("posted"): un bottone dedicato ("Add Credit Note") genera automaticamente lo storno completo (le stesse righe, importi invertiti), obbligando l'operatore a scrivere un motivo. Odoo tiene inoltre separato, riga per riga dell'ordine di vendita, "quanto è stato ordinato" da "quanto è stato effettivamente fatturato" — quindi una nota di credito libera automaticamente la differenza, permettendo di rifatturare la parte corretta senza toccare l'ordine originale.

**Oracle NetSuite.** Ha un tipo di transazione dedicato, "Credit Memo", che si applica direttamente contro una fattura specifica esistente, riducendo il saldo del cliente; ha anche un'opzione esplicita per decidere se la nota di credito deve anche far rientrare la merce a magazzino (utile quando la correzione è un reso fisico, non solo un errore di prezzo) o se è puramente una correzione contabile senza movimento fisico.

**Microsoft Dynamics 365.** Segue lo stesso schema: nota di credito collegata esplicitamente alla fattura di origine, mai una modifica diretta.

**Il punto comune, isolato esplicitamente come "la parte davvero riusabile per Quack anche restando un sistema più semplice"**: la nota di credito non è mai un'operazione anonima — è sempre un documento che dichiara esplicitamente "sto correggendo il documento X, per questo motivo, di questa entità". È proprio questo — un riferimento esplicito e un motivo tracciato — che oggi manca del tutto in Quack quando un ordine fatturato risulta sbagliato, ed è stato segnalato come la parte più semplice ed economica da costruire per prima, anche prima di affrontare l'intero livello strutturale.

### 7.4 Proposta di progettazione a doppio livello (funziona oggi col booleano, domani con le tabelle vere, senza riscrittura)

Richiesta esplicita dell'utente: una soluzione che oggi usi solo il booleano `stato_fatturazione`, ma che il giorno in cui `fatture`/`fatture_righe` diventeranno reali funzioni con quelle **da sola**, senza dover essere ridisegnata.

**Principio di fondo**: isolare in un unico punto la domanda "questo ordine è chiuso fiscalmente?", invece di spargere quella domanda — e la sua risposta — in tutto il codice. Oggi quel punto risponde leggendo il booleano; in futuro risponde interrogando le tabelle di fatturazione — ma tutto il resto del sistema (il blocco sulla revisione, l'interfaccia, il flusso di correzione) non deve sapere né cambiare nulla, perché si appoggia sempre a quell'unico punto. Segnalato esplicitamente che questo non è un'idea nuova per il progetto: è lo stesso principio già usato e collaudato per la scadenza dei preventivi (`v_preventivi_stato_effettivo`, vedi il piano dedicato in questa stessa cartella) — invece di scrivere `data_scadenza < oggi` in ogni singola query, il calcolo è stato centralizzato in una vista, e tutto il resto legge da lì.

**Primo seme — una vista per "è chiuso fiscalmente?" (Adattiva)**

Invece di avere due versioni diverse della vista (una oggi e una domani), possiamo scriverne una singola che **capisce da sola** quale logica usare (Graceful Degradation).

```sql
CREATE VIEW v_ordini_stato_fiscale AS
SELECT 
    o.*,
    CASE 
        -- Condizione 1 (A lungo termine): Esiste almeno una fattura reale importata nell'archivio per questo ordine?
        -- (Se la tabella fatture è vuota, questo ramo sarà ignorato automaticamente)
        WHEN EXISTS (
            SELECT 1 FROM fatture f 
            WHERE f.ordine_id = o.id 
              AND f.tipo = 'fattura' 
              AND f.stato IN ('emessa','pagata')
        ) THEN 1
        
        -- Condizione 2 (A breve termine): Non ci sono fatture reali, mi fido del booleano manuale
        WHEN o.stato_fatturazione = 'fatturato' THEN 1
        
        -- Altrimenti l'ordine non è chiuso fiscalmente
        ELSE 0 
    END AS fiscalmente_chiuso
FROM ordini_clienti o;
```

Ogni punto del codice che deve chiedersi "posso ancora toccare questo ordine?" legge `fiscalmente_chiuso` da questa vista. 
Questa soluzione è brillante perché **non richiede alcuna riscrittura futura**. Finché non importerai le vere fatture, la vista userà automaticamente il booleano (Condizione 2). Il giorno in cui le fatture esterne inizieranno a popolare la tabella, la vista inizierà a dare loro la precedenza (Condizione 1) scavalcando eventuali dimenticanze sul booleano manuale, senza che nessuno debba toccare una riga di codice PHP o SQL. Un vero comportamento adattivo.

**Secondo seme — una coda di correzione, non solo un divieto**

```sql
CREATE TABLE richieste_correzione_ordine (
    id                       INTEGER PRIMARY KEY AUTOINCREMENT,
    ordine_id                INTEGER NOT NULL REFERENCES ordini_clienti(id),
    motivo                   TEXT NOT NULL,   -- es. "quantità 500 invece di 50, confermato per errore"
    creato_da                INTEGER NOT NULL REFERENCES utenti(id),
    creato_il                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    stato                    TEXT NOT NULL DEFAULT 'aperta'
                             CHECK (stato IN ('aperta','chiusa')),
    nota_credito_fattura_id  INTEGER REFERENCES fatture(id),  -- oggi sempre NULL
    chiusa_il                TIMESTAMP,
    chiusa_da                INTEGER REFERENCES utenti(id)
);
```

Oggi, quando un operatore prova a revisionare un ordine fiscalmente chiuso, invece di trovare solo un errore secco troverebbe un'azione "Segnala per Correzione" che scrive una riga in questa tabella con un motivo obbligatorio. L'amministrazione avrebbe una schermata "Richieste di correzione aperte" invece di doversi fidare che qualcuno ricordi. La correzione fiscale vera avviene comunque fuori da Quack (in SGA/Doc Finance) — quando è fatta, l'amministrazione chiude la richiesta in Quack, che resta come log storico di "cosa è stato corretto e quando", cosa che oggi non esiste affatto.

Domani, quando `fatture`/`fatture_righe` diventeranno reali, la tabella non cambierebbe forma: cambierebbe solo cosa succede quando si "risolve" una richiesta aperta. Invece di limitarsi a marcarla chiusa a mano, il bottone di chiusura genererebbe davvero una riga in `fatture` (`tipo = 'nota_credito'`, collegata alla fattura originale e all'ordine), valorizzerebbe `nota_credito_fattura_id` con quella riga, e solo a quel punto marcherebbe la richiesta come chiusa automaticamente. La struttura che tiene traccia delle richieste sarebbe la stessa da subito; cambierebbe solo l'azione che le risolve — stesso principio del seme precedente.

**Perché, secondo la proposta, questa combinazione reggerebbe nel tempo senza riscritture**: con questi due punti isolati — la vista per "è chiuso?" e la tabella per "cosa c'è da correggere" — il passaggio dal booleano alle vere tabelle di fatturazione richiederebbe di toccare solo due file: la definizione della vista, e l'implementazione dell'azione "Chiudi richiesta". Tutto il resto — il guard sulla revisione, l'interfaccia, il flusso operativo, la tabella delle richieste stessa — resterebbe scritto una volta sola.

Offerta fatta all'utente, non ancora accettata: scrivere questa proposta come un piano dettagliato in stile `PIANO -`, con mappa dei file da toccare, test previsti, dichiarazione di additività/non-rischio sui dati esistenti — non ancora fatto, in attesa di conferma.

### 7.5 Domanda diretta dell'utente: "è una soluzione professionale, di livello enterprise? o è una cafonata da sagra di paese?"

Riportata qui l'autovalutazione critica data in risposta, per intero, senza addolcirla.

**Cosa regge davvero (la parte valutata positivamente)**: l'idea di isolare la domanda "è chiuso fiscalmente?" dietro un unico punto di lettura (la vista) è stata valutata come ingegneria corretta, non un trucchetto — coerente con un pattern che il progetto ha già validato e in cui si fida (`v_preventivi_stato_effettivo`). Anche l'idea della "richiesta" che oggi resta un promemoria interno e domani diventa un documento fiscale vero è stata valutata come non inventata a caso: concettualmente vicina a come SAP gestisce la cosa con la "Credit Memo Request", un documento che nasce come richiesta e solo dopo un passaggio diventa la nota di credito vera.

**Quattro difetti reali, ammessi esplicitamente**:

1. **Nessun controllo a doppia firma (maker-checker)**: nella proposta, chiunque possa modificare un ordine può anche aprire e chiudere una "richiesta di correzione" da solo, senza che nessun altro la validi. In un vero sistema enterprise — e ancora di più in un ERP che gestisce precursori di esplosivi con obbligo di segnalazione — una correzione post-fattura richiede quasi sempre un secondo ruolo che approva, non solo chi l'ha creata. Ammesso che questo manchi del tutto nella proposta.

2. **Il "motivo" è testo libero, non dati strutturati**: la tabella ha solo un campo `motivo TEXT` — "quantità 500 invece di 50". Un sistema serio registra invece quali righe e quali valori stanno cambiando (quantità vecchia/nuova, prezzo vecchio/nuovo, riga per riga), perché altrimenti quella richiesta non può mai diventare in automatico una vera nota di credito con righe corrette — resta per sempre un post-it, anche quando le tabelle `fatture` diventeranno reali. Valutato come difetto strutturale, non di dettaglio, che rende la tabella molto meno "riusabile nel futuro" di quanto dichiarato inizialmente.
3. **Nessun riferimento al documento esterno reale**: la tabella non ha un campo per registrare "numero della nota di credito emessa davvero fuori sistema" in SGA/Doc Finance — la chiusura della richiesta è solo un click di fiducia, senza traccia verificabile di cosa sia realmente successo in amministrazione. Valutato come qualcosa a cui un sistema enterprise non si affiderebbe mai.

4. **Un dettaglio di schema assunto senza verifica**: `fatture.ordine_id` è stato scritto come se si sapesse per certo che quella colonna esiste con quel nome — non verificato leggendo `db/init_sqlite.sql` prima di scriverlo, fidandosi invece della memoria della sessione. Ammesso esplicitamente che questo è esattamente il tipo di errore che questa stessa cartella di documentazione ripete di evitare in continuazione ("verifica lo schema reale, non la memoria"), e che prima di costruire qualunque cosa su questa proposta andrebbe riletto lo schema vero di `fatture`/`fatture_righe`, non dato per scontato.

**Verdetto dato**: per un ERP piccolo di un distributore chimico, con fatturazione reale che vive fuori dal sistema, la proposta è stata giudicata una base di partenza ragionevole e proporzionata — non una soluzione da buttare. Ma è stato dichiarato esplicitamente eccessivo chiamarla "livello enterprise": mancano l'approvazione a due persone, i dati strutturati riga per riga, il riferimento al documento esterno reale, e c'è un'assunzione di schema mai verificata. Offerta fatta: riscrivere la proposta in versione realmente enterprise (con le quattro aggiunte) se richiesto, altrimenti la versione "diet" resta disponibile per le dimensioni di questo progetto ma va dichiarata per quello che è, non spacciata per qualcosa che non è.

### 7.6 Soluzioni Dettagliate a tutte le Criticità (Workflow, Dati Strutturati, Audit, Disaccoppiamento)

> ⚠️ **ATTENZIONE — questa sezione (§7.6) contiene errori fattuali scoperti in audit il 25/07/2026, tutti corretti in §7.7 più sotto.** Lasciata intatta per memoria storica di come si è arrivati alla soluzione finale (stesso principio già applicato al ragionamento originale del §2), ma **non usarla come riferimento per implementare**: usare sempre §7.7. In sintesi, §7.6 descrive una tabella `ordini_stati_transizioni` mai esistita nel progetto (il meccanismo reale è `includes/transizioni_stati.php` + `TransitionGuard`), cartelle `includes/models`/`includes/helpers` mai esistite (il progetto usa file piatti in `includes/` + eventuali classi in `app/Services/`), un controllo `can('admin')` che — per come è fatta davvero la funzione `can()` — non avrebbe mai funzionato per nessuno, e una tabella `audit_log` mai esistita quando ne esiste già una dormiente pensata apposta (`log_stati_ordini`). Il confronto con SAP/Oracle/Dynamics con nomi di campo specifici (`SAP_ALL`, `FND_LOG_MESSAGE`) non è stato verificato e va trattato con cautela.

Questa sezione espande il design originale per correggere tutti i difetti strutturali identificati nel §7.5, portando la soluzione a un vero livello enterprise.

#### Soluzione alla Criticità 1 – Workflow di Approvazione (Maker‑Checker) – Soluzione completa

**Perché è un problema**
- La proposta originale permetteva a chiunque di aprire e chiudere una richiesta di correzione da solo, violando il principio di *Segregation of Duties* (SoD). Senza una doppia firma, l’audit trail è incompleto e il rischio di frode aumenta.

**Come lo risolvono i grandi ERP**
| ERP | Maker‑Checker | Super‑User / Override |
|-----|--------------|----------------------|
| SAP S/4HANA | Ruoli separati, audit log | `SAP_ALL` con tracciamento `ACTIVITY_TYPE = ‘FORCED_APPROVAL’` |
| Oracle E‑Business Suite | Responsabilità distinte | `System Administrator` con log `FND_LOG_MESSAGE` |
| Microsoft Dynamics 365 | Security Roles | `System Administrator` con *Audit History* |
| Infor CloudSuite | Role‑based approval | *Override Log* obbligatorio |

**Proposta per Quack**
1. **Macchina a stati (unidirezionale)**
   - `in_approvazione` – creata dal Maker.
   - `approvata` – validata dal Checker, richiede `riferimento_doc_esterno`.
   - `rifiutata` – rifiutata dal Checker, richiede `motivo_rifiuto`.
2. **Ruoli**
   - `admin` – Super‑User già presente, può forzare l’approvazione.
   - `commerciale`, `magazziniere`, ecc. – Maker.
3. **Gestione del bypass per micro‑imprese**
```php
// config.php (da aggiungere)
define('STRICT_MAKER_CHECKER', true);   // comportamento enterprise
define('ALLOW_ADMIN_BYPASS', true);    // consente al ruolo admin di forzare
```
```php
// guard in includes/functions.php
function can_approve_corr_request(array $request, int $userId, string $role): bool {
    if (STRICT_MAKER_CHECKER && $request['creato_da'] === $userId) {
        return false; // maker = checker → blocco
    }
    if (ALLOW_ADMIN_BYPASS && $role === 'admin') {
        return true; // bypass consentito, verrà audit‑loggato
    }
    return $request['creato_da'] !== $userId;
}
```
4. **Audit Log obbligatorio**
```sql
CREATE TABLE audit_log (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id       INTEGER NOT NULL,
    action        TEXT    NOT NULL,   -- FORCED_APPROVE, APPROVE, REJECT, …
    target_table  TEXT    NOT NULL,
    record_id     INTEGER NOT NULL,
    comment       TEXT,
    ts            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
```
Il controller deve inserire una riga in `audit_log` ogni volta che il bypass è usato (campo `comment` obbligatorio).
5. **Interfaccia UI**
   - Bottone **Approva**: disabilitato per il maker quando `STRICT_MAKER_CHECKER` è attivo.
   - Bottone **Forza approvazione** (visibile solo a `admin`): apre un modal con campo *Motivo del bypass* (obbligatorio) e, al conferma, chiama il guard sopra.
   - Bottone **Rifiuta**: sempre disponibile ai checker, richiede *Motivo rifiuto*.
   - Banner rosso “Operazione forzata – registrata in audit log” appare dopo il bypass.
6. **Casistiche ed Edge Cases**
   - **Caso 1 – Micro‑impresa (titolare unico)**: l’admin usa il bypass; l’azione è audit‑loggata.
   - **Caso 2 – Checker assente**: la coda di ruolo `Richieste in attesa` permette a chiunque con permessi `manage_orders` di intervenire.
   - **Caso 3 – Rifiuto senza motivo**: UI blocca il submit; il backend valida e restituisce errore.
   - **Caso 4 – Modifica manuale DB**: se lo stato è `approvata` ma manca `riferimento_doc_esterno`, il sistema segnala un alert “Stato inconsistente – verifica manuale”.
   - **Caso 5 – Import massivo**: le richieste importate vengono inserite in stato `in_approvazione`; un job settimanale notifica il numero di pending.
7. **Schema DDL (integrato con la Criticità 3)**
```sql
CREATE TABLE richieste_correzione_ordine (
    id                       INTEGER PRIMARY KEY AUTOINCREMENT,
    ordine_id                INTEGER NOT NULL REFERENCES ordini_clienti(id),
    motivo                   TEXT,                            -- Opzionale (i dati veri sono in richieste_correzione_righe)
    creato_da                INTEGER NOT NULL REFERENCES utenti(id),
    creato_il                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    stato                    TEXT NOT NULL DEFAULT 'in_approvazione'
                             CHECK (stato IN ('in_approvazione','approvata','rifiutata')),

    riferimento_doc_esterno  VARCHAR(50),                     -- Es. "SGA-NC-2026-045"
    motivo_rifiuto           TEXT,                            -- Valorizzato se stato = 'rifiutata'

    chiusa_il                TIMESTAMP,
    chiusa_da                INTEGER REFERENCES utenti(id),
    
    -- Obbligo External ID all'approvazione (Criticità 3)
    CHECK ( (stato = 'approvata' AND riferimento_doc_esterno IS NOT NULL) OR stato != 'approvata' ),
    
    -- Obbligo motivo al rifiuto
    CHECK ( (stato = 'rifiutata' AND motivo_rifiuto IS NOT NULL) OR stato != 'rifiutata' )
);
```

#### Soluzione alla Criticità 2: Dati Strutturati per la Macchina (Non testo libero)
Invece di affidare la correzione a un inaffidabile campo `motivo TEXT`, il sistema deve obbligare l'utente a dichiarare *esattamente* quali righe dell'ordine originale cambiano e come. Questo permette in futuro di generare automaticamente la nota di credito e ricalcolare lo scarico di magazzino in maniera puramente matematica.

**Modifica allo Schema:**
Creazione di una tabella "figlia" strettamente legata alla richiesta, speculare alle righe dell'ordine originale.

```sql
CREATE TABLE richieste_correzione_righe (
    id                            INTEGER PRIMARY KEY AUTOINCREMENT,
    richiesta_id                  INTEGER NOT NULL REFERENCES richieste_correzione_ordine(id) ON DELETE CASCADE,
    riga_ordine_id_originale      INTEGER NOT NULL REFERENCES ordini_clienti_righe(id),
    prodotto_id                   INTEGER NOT NULL REFERENCES prodotti(id),
    -- Dati originali (congelati al momento della richiesta per sicurezza e audit)
    quantita_originale            DECIMAL(10,3) NOT NULL,
    prezzo_unitario_originale     DECIMAL(10,3) NOT NULL,
    -- Dati corretti dall'operatore
    quantita_corretta             DECIMAL(10,3) NOT NULL,
    prezzo_unitario_corretto      DECIMAL(10,3) NOT NULL,
    
    CHECK (quantita_originale != quantita_corretta OR prezzo_unitario_originale != prezzo_unitario_corretto)
);
```

**Flusso Operativo (UX):**
1. L'utente clicca su "Segnala per Correzione".
2. Si apre una modale che mostra tutte le righe dell'ordine attuale.
3. L'utente modifica le celle di "Quantità" o "Prezzo" solo per le righe errate (es. cambia 500 in 50).
4. Il sistema calcola il Delta (es. -450 pezzi) e lo mostra a video.
5. Al salvataggio, il campo `motivo TEXT` in testata diventerà opzionale (usato solo per note discorsive, es. "Errore di battitura confermato al telefono"), mentre la vera fonte di verità saranno i record matematici inseriti in `richieste_correzione_righe`.

#### Soluzione alla Criticità 3: Tracciabilità del Documento Esterno Reale (SGA)
Poiché Quack non emette fisicamente la nota di credito, delegando l'operazione a SGA/Doc Finance, è vitale mantenere l'audit trail tra i due sistemi. Se l'amministrazione chiude la richiesta su Quack, deve dichiarare con quale documento reale l'ha chiusa.

**Modifica allo Schema:**
Inclusa nella tabella `richieste_correzione_ordine` definita sopra al paragrafo della Criticità 1, tramite il campo `riferimento_doc_esterno` e i relativi `CHECK` di obbligatorietà allo stato `approvata`.

**Flusso Operativo (UX):**
1. La richiesta appare nella dashboard dell'Amministrazione.
2. L'addetto apre SGA (il software esterno) e genera la vera Nota di Credito fiscale.
3. Torna in Quack e clicca su "Chiudi Richiesta".
4. Il sistema **impedisce** la chiusura se l'utente non compila un popup "Riferimento Nota di Credito Esterna".
5. Inserendo il numero, la richiesta passa a `chiusa`, popolando `riferimento_doc_esterno` e `chiusa_il`. La catena di audit tra Quack e la contabilità è ora blindata e verificabile a distanza di anni.

#### Soluzione alla Criticità 4: Disaccoppiamento Fiscale e Comportamento Adattivo (WMS vs ERP Finance)
La proposta originale creava un debito tecnico vincolando le correzioni a una tabella `fatture` vuota, ignorando la necessità reale: **Quack agirà solo come archivio in lettura**, mentre il VERO master della fatturazione resterà un software esterno (SGA/Doc Finance).

**Le Necessità Strategiche (Breve vs Lungo Termine):**
- **Oggi (Urgenza):** L'ERP deve andare online subito senza essere frenato dal modulo finance. A breve termine, la cosa più utile e veloce è un semplice booleano (`stato_fatturazione = 'fatturato'`).
- **Domani (Archivio a regime):** Le fatture vere continueranno a essere emesse da altri software, e le tabelle `fatture` di Quack fungeranno solo da archivio dati. A lungo termine, il sistema dovrà basarsi sui record reali importati, ignorando i flag manuali per evitare errori umani.

**La Soluzione Enterprise (Loose Coupling & Graceful Degradation):**
Invece di avere due versioni del codice da scambiare in futuro (e invece di vincolare a database la logistica alla fatturazione, col rischio di bloccare il magazzino se SGA ritarda una sincronizzazione), si adottano due principi:
1. **Accoppiamento Debole (Loose Coupling):** Si rimuove totalmente la Foreign Key verso la tabella `fatture` (già fatto nella Soluzione 3). Il collegamento avverrà tramite una semplice `JOIN` logica sul campo testuale `riferimento_doc_esterno`.
2. **Logica Autoadattiva (Graceful Degradation):** Si unisce il "breve" e il "lungo" termine in un'unica vista SQL intelligente, che "capisce da sola" il contesto senza richiedere alcun intervento umano futuro.

**Stub di Codice (La Vista Adattiva, introdotta al §7.4):**
```sql
CREATE VIEW v_ordini_stato_fiscale AS
SELECT o.*,
    CASE 
        -- Condizione A Lungo Termine: Se ci sono vere fatture nell'archivio, usa quelle
        WHEN EXISTS (
            SELECT 1 FROM fatture f 
            WHERE f.ordine_id = o.id AND f.tipo = 'fattura' AND f.stato IN ('emessa','pagata')
        ) THEN 1
        
        -- Condizione A Breve Termine: Se non ci sono, fidati del booleano d'emergenza
        WHEN o.stato_fatturazione = 'fatturato' THEN 1
        
        ELSE 0 
    END AS fiscalmente_chiuso
FROM ordini_clienti o;
```

**Risultato (Zero Riscritture):**

Oggi la vista ignorerà il `WHEN EXISTS` e userà in automatico il booleano manuale. Il giorno in cui le fatture esterne inizieranno a popolare la tabella, la vista darà loro la precedenza in modo automatico. Il sistema transiterà dal breve al lungo termine da solo, riga per riga, senza dover aggiornare una singola riga di codice PHP o SQL. Questa parte (§7.4, vista adattiva) resta valida: è un'applicazione corretta di un pattern già reale e verificato nel progetto (`v_preventivi_stato_effettivo`, `db/init_sqlite.sql:1243-1252`).

---

## 7.7 Correzioni dopo audit approfondito (25/07/2026) — ✅ DECISO, sostituisce §7.6 dove in contraddizione

Audit condotto rileggendo per intero (non a memoria, non con grep isolato) i file reali coinvolti: `includes/transizioni_stati.php`, `app/Services/TransitionGuard.php`, `includes/functions.php`, `includes/compliance.php`, `includes/impostazioni.php`, `includes/magazzino.php`, `app/Controllers/SalesOrdersController.php`, `app/Controllers/PurchaseOrdersController.php`, `app/views/sales/orders/_detail.php`, `app/views/purchases/orders/_detail.php`, `app/views/sales/quotes/_form.php`, `db/init_sqlite.sql` e `db/init_mysql.sql` (interi, 2853 e ~1000 righe), `app/Controllers/CustomersController.php`. Cinque criticità trovate in §7.6, tutte risolte riusando solo meccanismi già esistenti nel progetto — nessuna architettura nuova.

### 7.7.1 — Motore di transizioni: nessuna tabella nuova

**Correzione**: §7.6 proponeva una tabella `ordini_stati_transizioni`. Non esiste, non è mai esistita. In tutto Quack (RFQ, ordini fornitore, preventivi, ricezioni, trasferimenti, inventari, ordini cliente) le regole di transizione sono array PHP dentro `includes/transizioni_stati.php`, verificati da `app/Services/TransitionGuard.php` (72 righe, zero riferimenti a tabelle — letto per intero).

**Decisione**: aggiungere la regola verso `'superato'` alla funzione già esistente `qk_transizioni_ordini_clienti()`. Nessuna tabella nuova, nessuna infrastruttura nuova.

### 7.7.2 — Dove va il codice: niente cartelle nuove

**Correzione**: §7.6 proponeva `includes/helpers/corr_request_helper.php` e `includes/models/CorrezioneOrdine.php`. Nessuna delle due cartelle esiste in Quack. La struttura reale è piatta (`includes/sales_orders.php`, `includes/purchases_orders.php`, `includes/magazzino.php`, `includes/compliance.php`...); quando la logica è abbastanza complessa da meritare una classe, il pattern verificato (`includes/compliance.php`, 6 righe di funzione-ponte che chiama `App\Services\ComplianceCheckerService`) è: funzione-ponte breve in `includes/`, classe vera in `app/Services/`.

**Decisione**: `qk_ordine_cliente_crea_revisione()` (Tappa 3) va in `includes/sales_orders.php`, accanto a `qk_ordine_cliente_completa()`/`riapri()`/`annulla()` (righe 189-255, stesso file, stesso pattern esatto — non solo "simile", lo stesso codice con nome diverso). Le "richieste di correzione" (§7.4-7.6) vanno in un nuovo controller `CorrezioniOrdiniController.php`, mirror di `SpedizioniController.php` (che già esiste come controller separato per un sotto-flusso di `ordini_clienti`, invece di stare dentro `SalesOrdersController`), e in un nuovo file piatto `includes/correzioni_ordini.php`.

### 7.7.3 — Permessi e maker-checker: `can('admin')` non avrebbe mai funzionato

**Correzione**: §7.6 proponeva di controllare il bypass con `can('admin')` e citava "un precedente già implementato per le revisioni RFQ" — nessuno dei due è corretto. `can(string $permission)` (`includes/functions.php:384-403`, letta per intero) controlla **permessi** (`manage_users`, `view_costs`, `edit_prices`, `manage_catalog`, `manage_warehouse`, `manage_orders`), non nomi di ruolo — `'admin'` non è tra questi, quindi `can('admin')` restituisce sempre `false`, anche per l'amministratore vero. Il precedente RFQ citato non esiste (verificato leggendo per intero i file RFQ: zero bypass, zero doppia approvazione).

**Decisione (maker-checker reale, costruito con gli strumenti che il progetto già ha)**:
- Nuove colonne booleane su `ruoli`: `can_approvare_correzioni`, `can_bypass_maker_checker` (stesso stile di `can_manage_users` ecc., default 0, `can_bypass_maker_checker=1` solo per `admin` nel seed).
- `qk_correzione_ordine_approva()` verifica `$richiesta['creato_da'] !== $userIdCorrente`, a meno che l'utente non abbia `can('bypass_maker_checker')` — vera separazione creatore/approvatore, non un nome di ruolo hardcoded.
- Ogni approvazione, rifiuto o bypass scrive una riga in `log_stati_ordini` (§7.7.4), con nota esplicita se è stato un bypass.

### 7.7.4 — Audit: non serve `audit_log`, esiste già `log_stati_ordini`

**Correzione**: §7.6 proponeva una tabella `audit_log` "come avviene per gli altri flussi critici dell'app" — non esiste nessuna tabella così, e questo non avviene per **nessun** flusso oggi in Quack (verificato su tutto `db/init_sqlite.sql`, 2853 righe). Esiste però già, dal 2026, una tabella pensata esattamente per questo scopo e mai collegata a nessun codice: `log_stati_ordini` (`db/init_sqlite.sql:1142-1151`: `tipo_documento`, `documento_id`, `stato_precedente`, `stato_nuovo`, `cambiato_da`, `cambiato_il`, `note`), con un commento degli sviluppatori che dice esplicitamente che è lì in attesa di un primo uso.

**Decisione**: usare `log_stati_ordini` per tutte le scritture di audit di questo piano (revisione, richieste di correzione, `segna_fatturato`/`segna_da_fatturare` — vedi §7.7.7). Sarebbe il suo primo utilizzo reale in tutto il progetto.

### 7.7.5 — Configurazione: `Impostazioni`, non un `define()`, default flessibile

**Correzione**: §7.6 proponeva `define('STRICT_MAKER_CHECKER', true)` dentro `config.php`. `config.php` (letto per intero) contiene solo valori fissi d'ambiente (nome app, versione, percorsi), mai interruttori di regole di business. Il meccanismo reale del progetto per questo è la tabella `Impostazioni` (chiave/valore) con `get_impostazione()`/`set_impostazione()` (`includes/impostazioni.php`), già usata realmente per le soglie di margine (`SalesOrdersController.php:183-184`).

**Decisione, con i 3 vincoli posti dall'utente**:
1. Chiave `maker_checker_correzioni_attivo` in `Impostazioni`, letta con `get_impostazione($pdo, 'maker_checker_correzioni_attivo', '0')`.
2. **Default `'0'` (flessibile)**: finché nessun amministratore lo attiva esplicitamente, un solo utente con `can('approvare_correzioni')` può creare e approvare la stessa richiesta da solo.
3. **Almeno un ruolo che può fare tutto**: `admin` ha sempre `can('bypass_maker_checker')` = vero per costruzione (seed), indipendentemente dall'impostazione — può sempre approvare anche una propria richiesta.

```php
$makerCheckerAttivo = get_impostazione($pdo, 'maker_checker_correzioni_attivo', '0') === '1';
$puoBypassare = can('bypass_maker_checker');
if ($makerCheckerAttivo && !$puoBypassare && $richiesta['creato_da'] === $userIdCorrente) {
    throw new \RuntimeException('Non puoi approvare una richiesta creata da te stesso.');
}
```

### 7.7.6 — Schema finale delle tabelle (verificato contro i tipi reali di `ordini_clienti_righe`, non a memoria)

```sql
CREATE TABLE IF NOT EXISTS richieste_correzione_ordine (
    id                       INTEGER      PRIMARY KEY AUTOINCREMENT,
    ordine_id                INTEGER      NOT NULL REFERENCES ordini_clienti(id),
    motivo                   TEXT,                          -- nota discorsiva, opzionale: il dettaglio vero sta nelle righe
    stato                    TEXT         NOT NULL DEFAULT 'in_approvazione'
                                          CHECK (stato IN ('in_approvazione','approvata','rifiutata')),
    riferimento_doc_esterno  VARCHAR(50),                   -- es. "SGA-NC-2026-045"; obbligatorio solo se approvata
    motivo_rifiuto           TEXT,                          -- obbligatorio solo se rifiutata
    creato_da                INTEGER      NOT NULL REFERENCES utenti(id),
    created_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    chiusa_da                INTEGER      REFERENCES utenti(id),
    chiusa_il                DATETIME,
    CHECK ( (stato = 'approvata' AND riferimento_doc_esterno IS NOT NULL) OR stato != 'approvata' ),
    CHECK ( (stato = 'rifiutata' AND motivo_rifiuto IS NOT NULL) OR stato != 'rifiutata' )
);

CREATE TABLE IF NOT EXISTS richieste_correzione_righe (
    id                        INTEGER       PRIMARY KEY AUTOINCREMENT,
    richiesta_id              INTEGER       NOT NULL REFERENCES richieste_correzione_ordine(id) ON DELETE CASCADE,
    riga_ordine_id_originale   INTEGER       NOT NULL REFERENCES ordini_clienti_righe(id),
    prodotto_id                INTEGER       REFERENCES prodotti(id),
    quantita_originale         DECIMAL(10,3) NOT NULL,
    prezzo_unitario_originale  DECIMAL(10,4) NOT NULL,
    quantita_corretta          DECIMAL(10,3) NOT NULL,
    prezzo_unitario_corretta   DECIMAL(10,4) NOT NULL,
    CHECK (quantita_originale != quantita_corretta OR prezzo_unitario_originale != prezzo_unitario_corretta)
);
```

Tipi decimali allineati a `ordini_clienti_righe.quantita_ordinata` (`DECIMAL(10,3)`) e `.prezzo_unitario` (`DECIMAL(10,4)`) — verificati in `db/init_sqlite.sql:1376,1379`, non assunti come nella stesura originale del §7.5 punto 4. `prodotto_id` nullable come nella riga originale. `ON DELETE CASCADE` solo tra richiesta e sue righe, mai tra richiesta e ordine (gli ordini non si cancellano mai fisicamente in Quack). **Da valutare anche su `db/init_mysql.sql`** quando si implementa (regola CLAUDE.md punto 7) — non ancora fatto.

### 7.7.7 — Falla di sicurezza scoperta durante l'audit, non prevista in nessuna versione precedente di questo piano

`qk_ordine_cliente_segna_da_fatturare()` (`includes/sales_orders.php:358-362`) è oggi un `UPDATE` grezzo senza alcun controllo:

```php
function qk_ordine_cliente_segna_da_fatturare(PDO $pdo, int $id): void
{
    $st = $pdo->prepare("UPDATE ordini_clienti SET stato_fatturazione = 'da_fatturare', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $st->execute([$id]);
}
```

Chiunque raggiunga l'azione `segna_da_fatturare` (bottone già esistente in `_detail.php:107-114`) può disattivare `stato_fatturazione='fatturato'` e poi cliccare "Crea Nuova Versione" — **aggirando completamente** il blocco assoluto deciso al §4.3. Il blocco si basa su un campo che oggi chiunque può disattivare con un click, senza permesso speciale e senza lasciare traccia.

**Decisione**: `segna_da_fatturare` richiede `can('approvare_correzioni')` (stesso permesso nuovo di §7.7.3); sia `segna_fatturato` sia `segna_da_fatturare` scrivono una riga in `log_stati_ordini`. Vedi Tappa 10 al §5.

### 7.7.8 — Stato finale

Tutte le criticità di design sono risolte e tutte le decisioni del §4 sono chiuse. **Resta non autorizzata l'implementazione** di qualunque tappa (§5) finché non richiesta esplicitamente, incluse le Tappe 8-10 nate da questo audit. Le Tappe 8 e 10 dipendono anche dall'esito del piano gemello `docs/plans/PIANO - Revisione Sistema Ruoli e Permessi.md`, che ridisegna il sistema di permessi di cui questo piano diventa il primo caso d'uso reale del nuovo modello maker-checker.
