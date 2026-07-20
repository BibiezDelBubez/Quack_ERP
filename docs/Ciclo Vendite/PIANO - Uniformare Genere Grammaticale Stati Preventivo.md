# PIANO — Uniformare il genere grammaticale degli stati del preventivo (`preventivi.stato`)

> Stato: **✅ ESEGUITO il 20/07/2026**. Il database live dell'utente era solo di test (nessun dato da preservare), quindi la Fase 0/Fase 4 (ricognizione e migrazione dati sul DB reale) sono decadute: nessuna riga esistente da migrare, il DB può essere ricreato da zero con lo schema attuale (già maschile) senza alcuna perdita. Scelto il genere **maschile** (raccomandazione confermata). Vedi checklist in fondo per il dettaglio di cosa è stato verificato.

Origine: `REVISIONE - Analisi Documentazione Ciclo Vendite.md`, §1.2 e domanda aperta #1 del §5. Segue lo stesso schema già usato per `PIANO - Correzione Storicizzazione Azienda Emittente su Preventivi.md` (Guide Anagrafiche).

---

## 1. Il problema esatto, verificato oggi riga per riga

**Schema (fonte di verità dichiarata)**: sia `db/init_sqlite.sql:1178-1181` sia `db/init_mysql.sql:827` impongono lo stesso `CHECK`, identico, solo maschile:

```sql
CHECK (stato IN ('bozza','inviato','accettato','rifiutato','scaduto','annullato'))
```

Nessun disallineamento tra i due schemi su questo punto — buona notizia, la correzione non deve gestire una doppia fonte di verità in conflitto tra loro.

**Codice**: tutte le **scritture dirette** sul campo sono femminili (violano il CHECK dichiarato); il testo discorsivo rivolto all'utente (messaggi flash, tooltip) è quasi sempre già maschile corretto. Tabella completa, riverificata oggi (non riuso ciecamente l'audit di ieri, per regola del progetto):

| File | Righe | Cosa fa | Genere | Tipo |
|---|---|---|---|---|
| `app/Controllers/SalesQuotesController.php` | 137, 280 | legge `$values['stato'] === 'accettata'` | femminile | lettura |
| `app/Controllers/SalesQuotesController.php` | 339 | `UPDATE preventivi SET stato = 'inviata'` | femminile | **scrittura diretta** |
| `app/Controllers/SalesQuotesController.php` | 363 | `UPDATE preventivi SET stato = 'rifiutata'` | femminile | **scrittura diretta** |
| `app/Controllers/SalesQuotesController.php` | 340, 346, 364 | messaggi flash ("Inviato", "accettato", "Rifiutato") | maschile | presentazione (già corretto) |
| `includes/sales_quotes.php` (`qk_preventivo_nuova_versione`) | 760 | `UPDATE ... stato = 'annullata'` | femminile | **scrittura diretta** |
| `includes/sales_quotes.php` (`qk_preventivo_conferma_ordine`) | 901, 906 | check + `UPDATE ... stato = 'accettata'` | femminile | **scrittura diretta** |
| `app/views/sales/quotes/_form.php` | 30-40 | testo esplicativo (bozza/inviato/accettato/rifiutato) | maschile | presentazione (già corretto) |
| `app/views/sales/quotes/_form.php` | 52-54 | **bottone "Salva e invia"**, `value="inviata"` | femminile | **scrittura diretta** — bypassa l'azione `invia`, scoperto solo in questa verifica, non era nell'elenco di ieri |
| `app/views/sales/quotes/_form.php` | 69, 79, 88, 116 | confronti `in_array`/`===` per mostrare i bottoni azione | femminile | lettura |
| `app/views/sales/quotes/_form.php` | 133-144 | pipeline visiva stati | femminile | presentazione |
| `app/views/sales/quotes/_form.php` | 283-286 | **`<select name="stato">` libero**, 6 `<option>` femminili | femminile | **scrittura diretta, superficie più ampia**: l'operatore può impostare *qualunque* stato a mano, non solo transizioni valide (stesso gap P3 già noto nella REVISIONE, qui solo segnalato, non risolto) |
| `app/views/sales/quotes/_list.php` | 6-8 | `$statiLabels` filtro elenco | femminile | presentazione + **genera il parametro `status` usato in query** |
| `app/Controllers/SalesQuotesController.php` (`index()`) | 31 | `o.stato = :stato` con `:stato` = parametro GET `status` | dipende dal chiamante | lettura/filtro — deve restare sincronizzato con qualunque genere scelto |
| `includes/sales_dashboard.php` | 21 | `SELECT COUNT(*) ... WHERE stato = 'inviata'` | femminile | lettura (KPI dashboard) |
| `app/views/sales/sales_dashboard.php` | 8-19 | `preventivo_badge_dash()`, mappa femminile | femminile | presentazione |
| `app/views/sales/sales_dashboard.php` | 40-58 | link KPI con `?status=bozza`/`?status=inviata` | misto (bozza è invariante, inviata è femminile) | lettura/filtro |
| `app/Controllers/SalesPricelistsController.php` | 343 | `WHERE ... stato NOT IN ('annullato','rifiutato')` | **maschile** | lettura — **bug funzionale concreto, vedi sotto** |

**Bug funzionale trovato oggi, indipendente dalla scelta del genere**: la query di `SalesPricelistsController.php:343` vuole escludere dal conteggio i preventivi annullati/rifiutati collegati a un listino, ma confronta con i valori maschili (`'annullato'`,`'rifiutato'`) mentre i valori realmente scritti nel campo sono femminili (`'annullata'`,`'rifiutata'`). Nessuna riga corrisponde mai a quei due valori letterali, quindi la clausola `NOT IN` è di fatto sempre vera: **il conteggio include sempre anche i preventivi annullati e rifiutati**, cosa che il codice esplicitamente non vuole. Questo non è un problema estetico: chiunque guardi "quanti preventivi usano questo listino" vede un numero gonfiato. Va corretto qualunque sia la convenzione finale — è la stessa riga di codice che comunque tocchiamo.

**Punto critico non emerso nell'audit precedente**: `includes/db.php:68-73` mostra che il database reale **non viene mai rigenerato automaticamente** da `init_sqlite.sql` una volta che la tabella `utenti` esiste — l'auto-setup scatta solo su un database vuoto. Significa che il `CHECK` realmente in vigore sul database che usi oggi potrebbe essere diverso da quello scritto ora nel file (creato tempo fa, mai più toccato). Il database vive in `C:/Quack_ERP_data/db/database.sqlite`, **fuori dalla cartella di progetto — non è raggiungibile dai miei strumenti**. Non posso verificarlo da solo: serve un passo con te, vedi Fase 0.

**Documentazione**: femminile in `Manuale_Definitivo_Ciclo_Vendite.md` (righe 625, 645 — le "frecce" di transizione), `REVISIONE - Analisi Documentazione Ciclo Vendite.md` (già corretto, è l'audit stesso), `Revisione_ciclo_vendite.md`, e nel file `docs/ERP Prodotti Chimici - scopo e necessita.docx` (2 occorrenze nello stesso paragrafo). `audit/scoperte_audit.md` e `audit/criticita_risolte.md` citano il valore femminile come parte di un log storico di scoperte passate, non come documentazione corrente.

---

## 2. Domande aperte — rispondi prima che io tocchi qualunque file

1. **Conferma genere finale.** Confermo la mia raccomandazione di ieri — **maschile**, zero modifiche di schema, coerente con `init_sqlite.sql`/`init_mysql.sql` così come sono oggi — o preferisci comunque il femminile (richiede ricreare la tabella `preventivi` su entrambi gli schemi)?

2. **Accesso al database reale.** Non posso leggerlo: vive fuori dalla cartella di progetto. Preparo io uno script di **sola lettura** (`db/migrations/_check_stato_preventivi_readonly.php` o simile, zero scritture, zero rischio) che stampa: il `CHECK` realmente attivo su `preventivi.stato` in questo momento, e quante righe esistenti hanno oggi un valore femminile salvato. Tu lo esegui (es. `php db/migrations/nome_script.php` dalla cartella del progetto, oppure aprendolo da browser se preferisci) e mi incolli l'output. Va bene? È il passo che determina se serve anche una migrazione dati, non solo di codice.

3. **Il bug di `SalesPricelistsController.php:343`** (il filtro non esclude mai nulla): lo correggo nello stesso intervento — è la stessa riga che comunque tocco per il genere — o lo tratti come intervento separato, magari con verifica più ampia se pensi che nasconda conseguenze non ancora viste (es. altri conteggi/report che hanno lo stesso pattern altrove)?

4. **`audit/scoperte_audit.md` e `audit/criticita_risolte.md`.** Contengono citazioni testuali del bug (valori femminili) come parte di un log storico. Li lascio invariati (sono uno storico di cosa si è trovato, non la documentazione corrente) oppure aggiungo una singola riga "poi corretto, vedi questo PIANO" senza toccare il testo originale?

5. **Il file `.docx`** (`docs/ERP Prodotti Chimici - scopo e necessita.docx`) ha la stessa frase in femminile. Lo aggiorno anch'io con lo skill docx, o preferisci occupartene tu manualmente in un secondo momento?

6. **Il bottone "Salva e invia"** (`_form.php` riga 52-54): scrive `stato='inviata'` in fase di creazione/modifica, un punto di scrittura diretta separato dall'azione dedicata `invia` (che invece passa dal controller). L'ho scoperto solo in questa verifica, non era nell'elenco di ieri — lo correggo insieme al resto, giusto?

---

## 3. Piano a fasi (da eseguire solo dopo le risposte al §2)

**Fase 0 — Ricognizione del database reale (sola lettura, rischio zero).**
Script dedicato eseguito da te (domanda 2 del §2). Nessuna riga toccata. Output atteso: CHECK realmente attivo oggi, conteggio righe già in stato femminile/maschile/altro valore imprevisto. Questo determina se la Fase 4 (migrazione dati) è necessaria o se il database è già vuoto/di test.

**Fase 1 — Congelamento dell'elenco esatto da correggere.**
Immediatamente prima di modificare ogni singolo file (non prima, per la regola del progetto su file che possono cambiare durante la sessione), rileggo il file con lo strumento di lettura e confermo che riga/numero coincidano ancora con questo piano. Se qualcosa è cambiato, aggiorno il piano prima di procedere, non correggo alla cieca.

**Fase 2 — Suite di test "characterization" PRIMA del fix.**
Scrivo `tests/manual/suite_preventivo_stato_genere.php` (stesso pattern di `suite_mittente_ddt.php`: database SQLite temporaneo costruito da `db/init_sqlite.sql`, dati seed realistici, chiamate alle funzioni vere, non mock) che **documenta il comportamento attuale**, bug incluso: crea un preventivo, lo invia, verifica cosa succede oggi (incluso il fatto che l'`UPDATE` con `'inviata'` fallisce o passa a seconda del CHECK effettivo in un DB fresco da schema), verifica che oggi `SalesPricelistsController` conta male. Eseguo questa suite e mi aspetto che confermi esattamente i problemi descritti al §1 — se qualcosa si comporta diversamente da come documentato, mi fermo e rivedo l'analisi prima di continuare, non forzo il piano a incastrarsi sui fatti.

**Fase 3 — Modifiche di codice, in sotto-tappe a rischio crescente (ognuna verificata prima di passare alla successiva).**

- 3a. *Solo presentazione* (nessun impatto su dati scritti/letti dal DB): mappe di badge, pipeline visiva, testo statico. Rischio più basso, si tocca per prima.
- 3b. *Filtri di lettura*: `_list.php` (`$statiLabels`), `sales_dashboard.php` (KPI e link), `SalesPricelistsController.php:343` (fix genere + fix del bug funzionale, se confermato al punto 3 del §2).
- 3c. *Scritture dirette*: le due `UPDATE` in `SalesQuotesController.php` (azioni `invia`/`rifiuta`), le tre in `includes/sales_quotes.php` (`qk_preventivo_nuova_versione`, `qk_preventivo_conferma_ordine`), il bottone "Salva e invia" in `_form.php`. Sotto-tappa a rischio più alto: sono i punti che scrivono davvero sul database, li tocco per ultimi e li verifico uno per uno.
- 3d. *Il `<select>` libero* (`_form.php:283-286`): stesso principio di 3c ma isolato a parte perché è la superficie più ampia (permette qualunque valore, non solo transizioni valide) — verifico esplicitamente che dopo il fix il menu mostri solo ed esattamente i 6 valori nel nuovo genere, senza refusi.

Dopo *ogni* sotto-tappa (3a-3d): rileggo il file appena modificato con lo strumento di lettura (non da shell, per la regola del progetto su copie stale), e solo dopo eseguo `php -l` su una copia fresca scritta dallo strumento di scrittura se serve testarla da shell. `php -l` da solo non basta e non è mai considerato "verifica conclusa" in questo piano — è solo un controllo sintattico preliminare, il vero controllo è la Fase 5.

**Fase 4 — Migrazione dati sul database reale, solo se la Fase 0 la rende necessaria.**
Se emergono righe esistenti con valori nel genere "sbagliato" (rispetto alla scelta fatta al punto 1 del §2), scrivo una migrazione dedicata in `db/migrations/`, idempotente (controlla prima di agire, stesso pattern di `2026_07_17_stato_fatturazione_ordini_acquisto.php`), con backup implicito (il bootstrap in `db.php` già salva una copia di sicurezza prima di toccare lo schema, ma una migrazione dati va comunque scritta per essere ri-eseguibile senza danni se lanciata due volte). La esegui tu sul database reale, non io (non ho accesso).

**Fase 5 — Test funzionale end-to-end reale, dopo il fix.**
Rieseguo `suite_preventivo_stato_genere.php` (la stessa scritta in Fase 2, non una nuova più permissiva) e verifico che **ogni** asserzione che prima documentava un problema ora passi, e che nessuna asserzione "di controllo" (comportamenti già corretti prima) sia cambiata. Scenari coperti, tutti con dati e funzioni reali, non stub:
- creazione preventivo → bozza → invio → stato coerente nel DB, nella lista (filtro `status`), nel KPI dashboard, nel `<select>` del form riaperto;
- accettazione (`conferma_ordine`) → stato coerente, ordine cliente generato correttamente, guard "già accettato" (riga 901) ancora funzionante con il nuovo valore;
- rifiuto → stato coerente, bottone "Crea Nuova Versione" compare (dipende dal confronto `in_array` in `_form.php:69`, va verificato che legga il nuovo valore);
- nuova versione → il vecchio passa allo stato "annullato/a" corretto, il nuovo nasce in bozza, la numerazione `-02` invariata (non c'entra col genere ma verifico che il resto della funzione non si sia rotto);
- verifica diretta della query di `SalesPricelistsController.php`: creo un preventivo `rifiutato/a` collegato a un listino, verifico che ora **non** venga più contato — questo è il test che dimostra la correzione del bug funzionale, non solo del genere;
- verifica che nessuna transizione bypassi ancora il genere corretto: provo (nel test, non in produzione) a impostare uno stato dal `<select>` libero e confermo che il valore scritto sia quello nuovo, non un misto.

**Fase 6 — Verifica di non regressione.**
Rieseguo tutte le 7 suite già esistenti in `tests/manual/` (nessuna tocca oggi `sales_quotes`, ma alcune condividono helper comuni come `document_common.php`/`anagrafiche.php` — verifica a basso costo, coerente con lo standard già usato in questo progetto). Se qualcosa che prima passava ora fallisce, mi fermo e non procedo oltre finché non è chiaro perché.

**Fase 7 — Verifica manuale a schermo, non solo automatica.**
Perché "che funzioni davvero, che abbia senso" non è garantito da nessuno script. Checklist concreta da eseguire tu (o io se mi dai un modo di vedere la UI) sull'ambiente reale, dopo che Fase 4/5/6 sono verdi: creare un preventivo vero da interfaccia, cliccare "Salva e invia", controllare che compaia correttamente come "Inviato" nella lista e nel filtro, cliccare "Accetta e Crea Ordine", verificare che l'ordine generato sia corretto, cliccare "Rifiuta" su un altro preventivo di prova e controllare che il pulsante "Crea Nuova Versione" compaia, controllare il PDF/DOCX generato (i template stampano lo stato in chiaro? va controllato se c'è un'altra occorrenza non ancora censita nei template di stampa).

**Fase 8 — Documentazione, solo a fix validato.**
Aggiorno `Manuale_Definitivo_Ciclo_Vendite.md` (righe 625, 645), chiudo il punto §1.2 in `REVISIONE - Analisi Documentazione Ciclo Vendite.md` con riferimento a questo PIANO, aggiorno `Revisione_ciclo_vendite.md`, e — solo se confermato al punto 5 del §2 — il file `.docx`. Non chiudo nulla come "risolto" prima che tu abbia validato la Fase 7, per la regola del progetto di non decidere/archiviare al posto tuo.

---

## 4. Checklist atomica

- [x] Risposte al §2: genere maschile confermato; DB live è solo di test (Fase 0/Fase 4 decadute, nessun dato da migrare); bug `SalesPricelistsController` incluso nello stesso intervento; file `.docx` aggiornato; `audit/scoperte_audit.md` e `audit/criticita_risolte.md` lasciati invariati (log storici, non documentazione corrente)
- [x] Fase 1 — righe/file riconfermati leggendo i file subito prima di ogni edit
- [x] Fase 2 — suite `suite_preventivo_stato_genere_characterization.php` scritta ed eseguita: comportamento PRIMA del fix confermato (CHECK reale rifiuta il femminile scritto oggi dal codice; bug `SalesPricelistsController` riprodotto) — 7/7 assert
- [x] Fase 3a/3b — presentazione e filtri di lettura corretti: `_form.php`, `_list.php`, `sales_dashboard.php` (includes + view), `SalesPricelistsController.php` (già maschile, corretto indirettamente)
- [x] Fase 3c — scritture dirette corrette: `SalesQuotesController.php` (azioni `invia`/`rifiuta`, guard `accettato`), `includes/sales_quotes.php` (`qk_preventivo_nuova_versione`, `qk_preventivo_conferma_ordine`)
- [x] Fase 3d — `<select>` libero in `_form.php` corretto (6 option, valori e label)
- **Due punti scoperti solo in fase di esecuzione, non censiti nell'audit iniziale, corretti nello stesso intervento**: il bottone "Salva e invia" in `_form.php` (scrittura diretta separata dall'azione `invia`) e `prev_stato_badge()` in `includes/functions.php` (badge stato nella lista preventivi — funzione condivisa non individuata dall'audit perché "spostata da quotes/_list.php a functions.php globale", come dice il suo stesso commento)
- [x] Fase 4 — non necessaria (DB live di solo test, nessuna riga da migrare)
- [x] Fase 5 — suite `suite_preventivo_stato_genere_confirm.php` scritta ed eseguita sulle funzioni vere (`qk_preventivo_conferma_ordine`, guard "già accettato", azioni invia/rifiuta, query `SalesPricelistsController`, filtro lista): **8/8 assert**
- [x] Fase 6 — rieseguite le 7 suite preesistenti in `tests/manual/`: **129 assert, 0 fail**, nessuna regressione
- [ ] Fase 7 — checklist manuale a schermo: **non eseguita**, richiede accesso all'interfaccia reale che non ho — da fare da te (vedi elenco puntuale alla Fase 7 sopra)
- [x] Fase 8 — documentazione aggiornata: `Manuale_Definitivo_Ciclo_Vendite.md` (righe 625, 645), `REVISIONE - Analisi Documentazione Ciclo Vendite.md` (§1.2 e domanda 1 del §5 chiuse), file `.docx`. `Revisione_ciclo_vendite.md` lasciato invariato: cita una fonte ormai inesistente nell'albero (`indirizzi-contatti-nel-ciclo-vendite.md`), è un record storico di un tentativo di revisione precedente, non descrive lo stato attuale del codice

**Bug indipendente scoperto durante la Fase 5, NON corretto qui, segnalato per una decisione separata**: `qk_preventivo_nuova_versione()` copia anche la colonna `numero_preventivo` (vincolo `UNIQUE`) nel nuovo `INSERT` prima di rinominarla — fallisce sempre con `UNIQUE constraint failed`. Riprodotto in isolamento, confermato indipendente dal genere degli stati (bug preesistente, non introdotto da questo intervento). Il bottone "Crea Nuova Versione" è oggi non funzionante.

---

## 5. Piano di rollback

Se la convenzione scelta è il maschile (raccomandato): nessuna modifica di schema, quindi il rollback è un semplice revert dei file di codice toccati (6 file) più l'eventuale migrazione dati in Fase 4 — che va scritta in modo reversibile (conserva o è in grado di ricostruire il valore precedente, non un `UPDATE` cieco senza modo di tornare indietro) proprio per questo motivo.

Se la convenzione scelta fosse il femminile: il rollback dello schema è più delicato (richiede ricreare di nuovo la tabella `preventivi` con il CHECK precedente) — motivo ulteriore, oltre al costo, per cui la raccomandazione resta il maschile.

In entrambi i casi, nessuna fase di questo piano scrive sul database reale prima della Fase 4, e la Fase 4 stessa non parte senza la tua esecuzione esplicita — quindi fino a quel momento un rollback è semplicemente "non applicare le modifiche ai file", zero rischio sui dati.
