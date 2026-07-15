# Revisione della documentazione "Ciclo Acquisti"

Analisi riga-per-riga dei 6 file della cartella, con verifica incrociata sul codice reale dell'ERP (`db/init_sqlite.sql`, `includes/purchases_rfq.php`, `purchases_quotes.php`, `purchases_orders.php`, `purchases_receipts.php`, `pricing.php`). Obiettivo: individuare ripetizioni, contraddizioni, lacune, e proporre una riorganizzazione definitiva. Nessun file esistente è stato modificato: questo documento è solo l'analisi, la riscrittura vera e propria è un passo successivo da confermare.

## 0. Stato dei singoli file

| File | Contenuto | Stato |
|---|---|---|
| `Architettura ERP - Flusso Acquisto.md` (1543 righe) | Documento fondativo, flusso passo-passo + decisioni architetturali | Superato su un punto centrale (§1.1), valido su molte sotto-decisioni |
| `Ciclo acquisti SAP-Inspired da RFQ a Arrivo Merce.md` (683 righe) | Ricerca/proposta a 4 moduli (RFQ/Offerte/Ordini/Ricezioni) | Il più vicino al modello realmente implementato |
| `da RFQ a Conferma Ordine.md` (48 righe, ripristinato dall'utente) | Principio di immutabilità della RFQ inviata | Confermato al 100% dal codice |
| `dubbi e domande su rfq.md` (111 righe) | Domande aperte dell'utente, alcune con risposta trovabile nel codice | Da trasformare in backlog con risposte |
| `guida_ciclo_acquisti_v2.md` (114 righe) | Sintesi condensata, nomenclatura già allineata al DB reale | Buon canovaccio per il documento definitivo |
| `ricerca_indirizzo_consegna_preventivi.md` (50 righe) | Ricerca best practice su indirizzo di consegna | Completamente superato: la funzionalità proposta come "futura" è già implementata |

---

## 1. Contraddizioni e incongruenze (con verifica codice)

### 1.1 Architettura tabelle: 1 record vs 4 moduli separati — CONTRADDIZIONE MAGGIORE

- **`Architettura ERP...md` righe 11-13**: "Tutto il ciclo acquisti ruota attorno a un **singolo record** nella tabella `ordini_acquisto`... Non esistono due tabelle separate 'rfq' e 'purchase_order' — è lo stesso record."
- **`Ciclo acquisti SAP-Inspired...md` righe 3-87**: propone 4 moduli/8 tabelle separate (`T_RFQ_*`, `T_Offerte_*`, `T_Ordini_*`, `T_Ricezioni_*`).
- **Codice reale**: `db/init_sqlite.sql` implementa esattamente il modello a 4 tabelle — `rfq_testata/righe`, `offerte_testata/righe`, `ordini_testata/righe`, `ricezioni_testata/righe`, più `ordini_righe_pianificazione`. Nessuna tabella `ordini_acquisto` esiste. Il commento di intestazione ("ciclo legacy rimosso") conferma che il modello a record unico è stato realizzato e poi abbandonato.
- **Verdetto**: il modello a 4 tabelle è quello corretto e best practice (è quello usato da SAP/Oracle/Dynamics/Odoo: RFQ, Quotation, PO, Goods Receipt sono sempre entità distinte, mai un solo record con campo stato). Confermato ulteriormente dal punto 1.2 sotto: la separazione è strutturalmente necessaria per preservare l'immutabilità della RFQ. **`Architettura ERP - Flusso Acquisto.md` è da correggere su questo punto**, mantenendo però le sotto-decisioni indipendenti (numerazione, IVA, UoM, lotti, prezzi).

> **✅ RISOLTO (deciso in data 2026-07-14):** Architettura definitiva = 4 tabelle separate (`rfq_*`, `offerte_*`, `ordini_*`, `ricezioni_*` + `ordini_righe_pianificazione`). Il modello a record unico va rimosso dalla documentazione finale, non solo corretto: non è un'alternativa valida, è storia superata. Nessuna modifica al codice necessaria (già corretto). Nella sezione "Schema Dati" definitiva si documenteranno solo le tabelle reali.
>
> **Nota per il backlog**: in un piano futuro (non ora) si valuterà di rinominare le tabelle con un prefisso o una convenzione più esplicita, per distinguere a colpo d'occhio le tabelle del ciclo Acquisti da quelle del ciclo Vendite (es. `acq_rfq_testata` vs `vnd_preventivi_testata`, o simile) — oggi i nomi (`rfq_*`, `offerte_*`, `ordini_*`) non rendono subito chiaro il dominio di appartenenza. Da inserire nel documento D (Backlog) come voce di refactoring futuro, non bloccante.

### 1.2 Editabilità della RFQ inviata — tre versioni diverse, il codice è il più restrittivo di tutte

| Fonte | Cosa dice |
|---|---|
| `Architettura ERP...md` riga 30 (tabella stati) | `sent` → **"Quasi tutto"** modificabile |
| `Architettura ERP...md` righe 109-111 (tabella frontend) | `sent` → **"Solo prezzi"** editabili |
| `da RFQ a Conferma Ordine.md` righe 24-47 | RFQ inviata è "congelata": nessuna modifica, nemmeno di prezzo. Ogni scostamento (sconti, lotti minimi) va registrato come riga separata nell'**Offerta**, mai sovrascrivendo la RFQ. |
| **Codice reale** (`purchases_rfq.php` righe 286-297) | Blocco totale: se `stato !== 'bozza'`, qualunque UPDATE su `rfq_testata`/`rfq_righe` viene rifiutato con eccezione. Commento esplicito nel codice: *"Unico punto di verità sul blocco modifica: solo 'bozza' è liberamente modificabile... la protezione non dipende da controlli duplicati nel controller (che in passato potevano essere aggirati/sovrascritti)"*. |

**Verdetto**: il codice conferma integralmente la versione di `da RFQ a Conferma Ordine.md`, la più restrittiva delle tre. Riga 30 di `Architettura ERP...md` è semplicemente errata e va corretta in "Niente". Questo è anche coerente con la best practice enterprise (RFQ come documento legale immutabile una volta inviato).

> **✅ RISOLTO (deciso in data 2026-07-14):** Testo definitivo = quello di `da RFQ a Conferma Ordine.md` (righe 24-47): RFQ inviata è completamente non modificabile, prezzi inclusi. Riga 30 e righe 109-111 di `Architettura ERP...md` sono errate e vanno corrette in "Niente" nella documentazione definitiva. Qualsiasi scostamento di prezzo/quantità rispetto alla RFQ va registrato come riga separata nell'Offerta, mai sovrascrivendo la RFQ. Nessuna modifica al codice necessaria (già corretto). Il testo di `da RFQ a Conferma Ordine.md` va riportato integralmente nella sezione "Immutabilità e Revisioni" del documento A definitivo.

### 1.3 Annullamento ordine con merce parzialmente ricevuta

- **`Architettura ERP...md` righe 1339-1355**: "Il sistema blocca l'annullamento" se risultano merci già parzialmente ricevute.
- **Codice reale** (`purchases_orders.php` riga 438): `qk_ordine_annulla` blocca solo gli stati `completato` e `annullato`. Lo stato `parzialmente_ricevuto` **non è bloccato**: un ordine parzialmente ricevuto può essere annullato senza alcun avviso.
- **Bug collaterale trovato durante la verifica**: `_ordine_aggiorna_in_arrivo` (righe 502-533) sottrae da `giacenze.quantita_in_arrivo` la quantità **totale ordinata originaria**, non il residuo ancora da ricevere. Poiché `quantita_in_arrivo` è aggregata per (prodotto, magazzino) e non per singolo ordine, annullare un ordine parzialmente ricevuto può azzerare erroneamente la giacenza-in-arrivo dovuta ad **altri ordini ancora aperti** sullo stesso prodotto/magazzino.
- **Verdetto**: il comportamento del codice (permettere l'annullamento anche con ricezione parziale) è di per sé ragionevole e usato anche da SAP (chiusura PO con GR parziale), ma il documento descrive un comportamento diverso mai implementato — è il documento a dover essere allineato o la validazione a dover essere aggiunta, a scelta. Il bug sull'aggregazione `quantita_in_arrivo` è un difetto reale indipendente, da correggere a parte.

> **✅ RISOLTO (deciso in data 2026-07-14):** Confronto con i grandi ERP (SAP: indicatore "Final Delivery"/consegna completata; Dynamics 365: "Cancel remaining quantity"; Odoo e NetSuite: chiusura righe residue) — nessuno tratta questa come un'unica operazione bloccante: separano sempre "chiudere il residuo non ricevuto" (sempre permesso) da "stornare merce già ricevuta" (flusso separato, reso/nota di credito, fuori scope qui).
>
> **Decisione presa**: annullare un ordine `parzialmente_ricevuto` resta permesso (opzione A), riformulato correttamente come "chiusura del residuo non ancora ricevuto" — la merce già entrata a magazzino non viene mai toccata dall'annullamento. In aggiunta, il frontend deve mostrare un **popup di conferma esplicativo** prima di procedere: non un semplice "sei sicuro?", ma un testo che spiega cosa sta per succedere (es. "Questo ordine ha già ricevuto parzialmente merce (X pz su Y). Annullandolo, il residuo non arriverà più; quanto già ricevuto resta regolarmente a magazzino. Continuare?").
>
> **Azioni da fare sul codice** (da riportare anche nel documento B — Schema Dati/API — come task tecnico):
> 1. Correggere `_ordine_aggiorna_in_arrivo` in `purchases_orders.php` (righe 502-533): deve sottrarre da `giacenze.quantita_in_arrivo` solo la quantità **residua non ancora ricevuta** di quell'ordine (quantità ordinata − quantità già ricevuta), non la quantità totale originaria.
> 2. Aggiungere il popup di conferma esplicativo nel frontend prima della chiamata di annullamento, quando lo stato è `parzialmente_ricevuto`.
> 3. Nessun nuovo blocco da aggiungere in `qk_ordine_annulla`: il comportamento attuale (blocco solo su `completato`/`annullato`) resta corretto.

### 1.4 Indirizzo di consegna sui preventivi — file di ricerca completamente superato

- **`ricerca_indirizzo_consegna_preventivi.md` riga 36**: "Attualmente, la tabella `offerte_testata` di Quack **non dispone di colonne per salvare l'indirizzo di consegna**", proposto come "Fase 2 (Evoluzione Future-Proof)".
- **Codice reale**: `offerte_testata.indirizzo_consegna_id` esiste già (`db/init_sqlite.sql` riga 2090), con meccanismo di snapshot completo via `indirizzi_documenti` e upsert (`qk_upsert_document_address`), implementato anche su `rfq_testata` (righe 1220/1983) e `ordini_testata` (riga 2140).
- **Verdetto**: il documento descrive come mancante/futura una funzionalità già interamente costruita — probabilmente il codice è stato scritto dopo la ricerca senza aggiornare il testo. Da riscrivere come nota storica ("ecco la scelta presa e perché") oppure archiviare.

> **✅ RISOLTO (deciso in data 2026-07-14):** esiste già una guida dedicata, più approfondita e verificata di quanto servirebbe riscrivere qui: [`Guide Anagrafiche Clienti, Fornitori, Indirizzi/01 - Ciclo Acquisti - Indirizzi e Contatti da RFQ a Ordine.md`](../Guide%20Anagrafiche%20Clienti%2C%20Fornitori%2C%20Indirizzi/01%20-%20Ciclo%20Acquisti%20-%20Indirizzi%20e%20Contatti%20da%20RFQ%20a%20Ordine.md). `ricerca_indirizzo_consegna_preventivi.md` va **ritirato** (non riscritto come nota storica) dalla cartella Ciclo Acquisti.
>
> **Logica reale in breve** (dettagli completi nel documento sopra): RFQ, Offerta e Ordine non leggono mai in tempo reale l'indirizzo/contatto da `clienti_fornitori`/`indirizzi`. Ogni volta che un documento viene creato o modificato, l'indirizzo e il contatto scelti vengono **fotografati** in due tabelle dedicate (`indirizzi_documenti`, `contatti_documenti`), con un flag `personalizzato` (lo snapshot è stato modificato a mano rispetto al master?) e un hash per rilevare se l'anagrafica master è cambiata da allora. Cambiare l'indirizzo di un fornitore in anagrafica oggi non altera mai un documento già emesso. Ogni offerta su una RFQ ottiene una propria copia indipendente dello snapshot (non un riferimento condiviso), e ogni revisione di un ordine (`superato`) clona lo snapshot in una nuova riga, preservando intatto quello della versione precedente per finalità di audit.
>
> **Azione per il documento A definitivo** (Flusso Operativo): includere questo riassunto breve nella sezione dedicata all'indirizzo di consegna, con rimando esplicito al documento sopra per il dettaglio (badge UI, casi limite su anagrafica che cambia, snapshot orfani, asimmetria vendite/acquisti sul "salva in anagrafica"). Il futuro documento C (contenitore Best Practice del Ciclo Acquisti) non deve trattare questo argomento: resta di competenza esclusiva della serie "Guide Anagrafiche".

### 1.5 Listino acquisti "futuro" già implementato, ma con un fallback mancante

- **`Architettura ERP...md` righe 511-605**: descrive come proposta futura l'algoritmo prezzo con listino attivo + fallback su prezzo base.
- **Codice reale** (`pricing.php`, `qk_get_purchase_price`, righe 287-401): implementa esattamente quell'algoritmo, già in produzione.
- **Lacuna reale**: lo stesso documento (righe 226-234, 828-848) descrive un **terzo fallback** — "ultimo prezzo pagato" nell'ultima riga d'ordine confermata — che **non risulta implementato**: se mancano sia listino sia prezzo base, la funzione ritorna `null` (riga 400).
- **Verdetto**: nessuna vera contraddizione di merito, ma il documento va aggiornato per riflettere che il listino è realtà, e la lacuna sul fallback "ultimo prezzo pagato" va segnalata come funzionalità non ancora costruita (non descritta come tale in nessun documento).

> **✅ RISOLTO (deciso in data 2026-07-14):** verificato anche `PurchasesController::getPurchasePrice()` (thin wrapper che delega a `qk_get_purchase_price()`, refactoring pulito del 14/07/2026, nessuna duplicazione) e la guida `Unita di misura e prezzi/` — quest'ultima non tratta affatto questo gap specifico, quindi la decisione era ancora aperta.
>
> **Decisione presa**: implementare il fallback, ma sempre etichettato come storico, mai indistinguibile da un prezzo di listino valido — coerente con la filosofia già adottata nella guida Prezzi/UoM (dati storici non cancellati, ma nemmeno fatti riemergere silenziosamente come se fossero attuali; vedi es. §6.11 fornitore disattivato). Concretamente:
> 1. Se listino e prezzo base falliscono entrambi, `qk_get_purchase_price()` cerca l'ultima riga di un **ordine realmente inviato al fornitore** per quella coppia prodotto/fornitore — stato `inviato`, `parzialmente_ricevuto` o `completato` (mai `bozza`, `in_approvazione`, `annullato` o `superato`), ordinata per data più recente.
> 2. Se trovata, ritorna il prezzo con `prezzo_sorgente = "Ultimo prezzo pagato (storico) — GG/MM/AAAA"` — testo distinto sia da "Listino: X" sia da "Prezzo base", da rendere in UI con uno stile visivamente diverso (es. colore di avviso), mai uguale a un prezzo di listino valido.
> 3. Resta un suggerimento precompilato, non un valore imposto: l'operatore può sempre modificarlo prima di salvare la riga, come già oggi per listino/prezzo base.
> 4. Se anche questo terzo tentativo fallisce, il campo prezzo resta vuoto come oggi.
>
> **Azione per il documento B definitivo** (Schema Dati/API): documentare questo terzo passo dell'algoritmo come parte dell'architettura pricing definitiva, non più come proposta.

### 1.6 Order Amendment (revisione) — funzionalità importante, assente da ogni documento

- **Codice reale**: `purchases_orders.php`, `qk_ordine_crea_revisione` (righe 544-703): quando un ordine è `inviato`, crea una nuova versione che duplica testata/righe/snapshot, incrementa `versione`, e marca il vecchio ordine come `superato` (stato mai citato in nessun documento).
- Lo stesso pattern esiste anche per le RFQ: stato `revisionata` e flusso "Crea nuova versione" (`purchases_rfq.php` righe 292, 544) — confermato durante la verifica del punto 1.2.
- **`Architettura ERP...md` righe 1264-1266** dichiara l'opposto come regola: "Modifiche ai prezzi post-conferma: non si fanno. Se il fornitore cambia prezzo dopo la conferma, annulli e rifai."
- **Verdetto**: il codice implementa deliberatamente un meccanismo di revisione strutturata (con genealogia `ordine_padre_id`) alternativo ad "annulla e rifai", applicato in modo coerente sia a RFQ sia a Ordini. È una funzionalità enterprise-grade (analoga al PO amendment di SAP/Oracle) completamente assente dalla documentazione — priorità alta per la sezione "Immutabilità e Revisioni" del documento definitivo.

> **✅ RISOLTO (deciso in data 2026-07-14):** il meccanismo di revisione (`superato` per gli Ordini, `revisionata` per le RFQ) è l'unico modo corretto per modificare un documento già inviato — preserva lo storico e non lascia ricezioni merci "orfane" collegate a un ordine annullato. La frase "annulla e rifai" va **rimossa** dal documento definitivo: descrive un comportamento mai implementato. Resta valido solo il caso banale di un documento ancora in bozza, dove annullare/cancellare e ricreare non pone alcun problema (la bozza è comunque liberamente modificabile, non serve nemmeno annullarla).
>
> **Azione per il documento A definitivo**: la sezione "Immutabilità e Revisioni" (già prevista al problema 2) deve includere questo meccanismo come parte centrale, con l'esempio pratico del cambio prezzo post-invio e il collegamento con le ricezioni merci parziali.

### 1.7 Righe RFQ senza prodotto a catalogo (free-text) — discusso ma mai implementato

- **`Ciclo acquisti SAP-Inspired...md` righe 573-591** e **`dubbi e domande su rfq.md` righe 6-7**: discutono se ammettere righe RFQ senza prodotto in anagrafica (campionature, R&D), notando che è prassi SAP ("Free-Text Requisitions") ma che nel settore chimico l'anagrafica-first è spesso obbligatoria per motivi normativi (SDS, ADR, lotti).
- **Codice reale**: `rfq_righe.prodotto_id`, `offerte_righe.prodotto_id`, `ordini_righe.prodotto_id`, `ricezioni_righe.prodotto_id` sono tutti `NOT NULL` (es. riga 662 di `init_sqlite.sql`). Nessuna riga senza prodotto è mai stata possibile.
- **Verdetto**: conferma il dubbio già sollevato dall'utente. La scelta anagrafica-first è legittima per un rivenditore di chimici, ma va scritta come **decisione presa**, non lasciata come domanda aperta nella prossima versione della documentazione.

> **✅ RISOLTO (deciso in data 2026-07-14):** confermato anche confrontando SAP/Dynamics/NetSuite nel dettaglio — nessuno di questi sistemi tratta le righe "senza codice articolo" come righe di magazzino generiche: sono sempre righe di **spesa contabile** (account assignment category in SAP, procurement category in D365, riga "Expense" in NetSuite), mai un movimento di magazzino, mai uno SKU, mai un lotto. Confermato quindi che `prodotto_id NOT NULL` ovunque è la scelta corretta per tutto ciò che deve finire tracciato a magazzino — non va rilassato.
>
> **Decisione presa**: le righe free-text-per-il-magazzino restano scartate per scelta, motivato da conformità normativa (SDS/ADR/lotti) oltre che da coerenza con la prassi reale dei grandi ERP. Aggiunta voce di backlog per la funzionalità strutturalmente diversa (righe di spesa non-magazzino, es. trasporto/servizi): vedi [Backlog Sviluppi Futuri.md, voce 14](../Backlog%20Sviluppi%20Futuri.md#14-righe-di-spesa-non-magazzino-sugli-ordini-dacquisto).

### 1.8 Contratto Quadro / Blanket PO — mai implementato, coerente col dubbio dell'utente

- **`Ciclo acquisti SAP-Inspired...md` righe 209-335**: descrive in dettaglio tabelle `T_Contratti_Testata/Righe` e "chiamate d'ordine".
- **Codice reale**: nessuna traccia di tabelle contratti né di logica "blanket"/"chiamata d'ordine" (verificato con ricerca mirata, zero risultati).
- **Verdetto**: non è una contraddizione ma una conferma di quanto l'utente stesso segnala in `dubbi e domande su rfq.md` riga 26 ("manca completamente la fase 3"). Va marcato esplicitamente come backlog di ricerca, non implementazione.

> **✅ RISOLTO (deciso in data 2026-07-14):** nessuna decisione di merito necessaria — non c'è conflitto tra documento e codice, entrambi (e l'utente stesso) concordano che è lavoro non ancora iniziato. **Non si implementa ora, rimandato al futuro.** Nel documento A definitivo (Flusso Operativo) il Contratto Quadro non va descritto come se facesse parte del flusso attuale — solo un rimando esplicito "vedi backlog" per chi si chiede perché non esiste. Dettaglio tecnico completo scritto in [Backlog Sviluppi Futuri.md, voce 15](../Backlog%20Sviluppi%20Futuri.md#15-contratto-quadro-blanket-purchase-order-con-chiamate-dordine).

### 1.9 Fornitore di default per prodotto — campo diverso, funzionalità di warning mai costruita

- **`Architettura ERP...md` righe 449, 1183, 188, 1185**: descrive un campo `prodotti.fornitore_default_id` e un avviso visivo "⚠️ Fornitore Abituale: X" quando si sceglie un fornitore diverso da quello preferito.
- **Codice reale**: `prodotti` non ha `fornitore_default_id` (verificato, nessun risultato). Il meccanismo reale è `prodotti_fornitori.ordine_preferenza_fornitore` (relazione N:N con ordinamento di preferenza), usato in query come `purchases_rfq.php` riga 127. **L'avviso visivo di warning non è implementato in nessun punto** (né PHP né frontend/JS).
- **Verdetto**: la scelta architetturale reale (preferenza su relazione N:N) è più vicina a Odoo ed è migliore di un FK singolo per un caso multi-fornitore. Ma il warning UX descritto in due documenti come già deciso è puro backlog non segnalato come tale.

> **✅ RISOLTO (deciso in data 2026-07-14):** correggere il documento definitivo per riferirsi al campo reale (`prodotti_fornitori.ordine_preferenza_fornitore`, non `prodotti.fornitore_default_id`). L'avviso "Fornitore Abituale" **si implementa a breve** (non backlog — modifica piccola, localizzata, da fare presto): quando l'operatore aggiunge a una RFQ una riga il cui fornitore abituale (quello con `ordine_preferenza_fornitore` più basso per quel prodotto) è diverso dal fornitore scelto in testata, la riga va evidenziata con l'avviso "⚠️ Fornitore Abituale: [nome]".
>
> **Lista dettagliata del fix:**
> 1. **Backend** — `qk_acquisti_prodotti_search()` in [RfqController.php:1248](../../app/Controllers/RfqController.php) (la query dietro l'endpoint `/purchases/rfq/api/prodotti`): aggiungere una subquery che recupera, per ogni prodotto, il fornitore con `ordine_preferenza_fornitore` più basso (join `prodotti_fornitori`/`clienti_fornitori`, `ORDER BY ordine_preferenza_fornitore ASC LIMIT 1`), restituendo `fornitore_abituale_id` e `fornitore_abituale_nome` nel JSON di risposta.
> 2. **Confronto**: nella stessa funzione (o lato JS, indifferente), confrontare `fornitore_abituale_id` con il `fornitore_id` passato come parametro (il fornitore scelto in testata sulla RFQ) — se diversi e `fornitore_abituale_id` non è null, includere il flag/nome nella risposta.
> 3. **Frontend** — [rfq-form.js](../../assets/js/rfq-form.js): in `fetchProdotti()` (righe 77-99) aggiungere il mapping dei due nuovi campi (`fornitore_abituale_id`, `fornitore_abituale_nome`) all'oggetto prodotto restituito.
> 4. Nel gestore `onSelect` di `initRiga()` (righe 198-252, in particolare dopo riga 230 dove si popolano gli altri campi della riga): se `sel.fornitore_abituale_id` è presente e diverso dal fornitore scelto in testata, applicare una classe CSS di evidenziazione (sfondo giallo) alla riga `tr` e mostrare un badge/tooltip col testo "⚠️ Fornitore Abituale: {nome}".
> 5. Verificare comportamento quando l'operatore cambia il fornitore in testata **dopo** aver già aggiunto righe (l'avviso andrebbe ricalcolato per tutte le righe esistenti, non solo per le nuove).
>
> **Azione per il documento A definitivo**: descrivere l'avviso come comportamento reale (non più aspirazionale) una volta implementato, con riferimento al campo `ordine_preferenza_fornitore`.

### 1.10 Difetti tecnici collaterali trovati durante la verifica (non contraddizioni documentali, ma da correggere)

- `db/init_sqlite.sql` conteneva **due `CREATE TABLE IF NOT EXISTS ricezioni_testata`** (righe 2235 e 2483) con colonne diverse; grazie a `IF NOT EXISTS` la prima era quella attiva, la seconda era codice morto silenzioso.
- `db/init_mysql.sql` referenzia `ordini_righe_pianificazione` in una FK ma non ne contiene la `CREATE TABLE` (presente solo in `init_sqlite.sql`) — il file MySQL è disallineato e va considerato non affidabile o esplicitamente marcato come tale. *(Problema distinto, non ancora affrontato — vedi prossimo punto da discutere separatamente.)*

> **✅ RISOLTO E CORRETTO NEL CODICE (2026-07-14):** confronto colonna per colonna delle due definizioni: la seconda (riga 2483) non aveva nessun campo assente dalla prima — era identica in tutto tranne per l'assenza di `magazzino_id`. Confermato anche che `purchases_receipts.php:165` scrive attivamente `INSERT INTO ricezioni_testata (..., magazzino_id, ...)`, quindi la prima definizione (con `magazzino_id`) era anche quella funzionalmente corretta, non solo quella "vincente" per `IF NOT EXISTS`. Nessuna crasi necessaria.
>
> **Correzione applicata**: rimossa la seconda definizione duplicata (ex righe 2483-2509) da `db/init_sqlite.sql`, sostituita con un commento che rimanda a questo punto della revisione. Verificato lo schema risultante eseguendolo per intero in un DB SQLite in memoria (`php -r` con PDO): nessun errore di sintassi, nessuna tabella mancante, file non troncato/corrotto.
>
> **Trovato durante la stessa verifica, stesso pattern**: `trg_ricezioni_testata_updated_at` era anch'esso duplicato in `db/init_sqlite.sql` (righe 2306 e 2523, corpo identico). Rimossa la seconda copia (mai eseguita). Ri-verificato lo schema completo dopo entrambe le correzioni: ancora zero errori.

### 1.11 `db/init_mysql.sql` completamente rigenerato e allineato a `db/init_sqlite.sql` (2026-07-14)

Il punto sopra ("MySQL disallineato su `ordini_righe_pianificazione`") si è rivelato, indagando, un problema molto più esteso: **16 tabelle intere mancanti** da MySQL rispetto a SQLite (tutto il dominio multi-magazzino, tutto il dominio ADR/sostanze chimiche, `ordini_righe_pianificazione`, `prodotti_restrizioni`, `prodotti_rrp`), **2 tabelle obsolete presenti solo in MySQL** (`unita_misura_categorie`, `ordini_clienti_righe_lotti` — entrambe confermate rimosse per scelta di design nei commenti di `init_sqlite.sql`), e — più grave — **139 delle 144 foreign key del file MySQL erano scritte in stile `REFERENCES` inline**, sintassi che MySQL/InnoDB accetta ma non traduce mai in un vincolo realmente applicato (a differenza della sintassi esplicita `FOREIGN KEY (...) REFERENCES ...`, usata correttamente solo nella sezione ADR/compliance, 15 vincoli). L'utente ha confermato che il file MySQL è tenuto volutamente indietro perché lo sviluppo procede su SQLite, e ha chiesto una riallineamento completo anziché una correzione mirata.

**Decisione presa**: rigenerare `db/init_mysql.sql` per intero, per introspezione dello schema SQLite realmente eseguito (`PRAGMA table_info`/`foreign_key_list`/`index_list` su un DB SQLite in-memoria), non per traduzione manuale del testo — per eliminare il rischio di errori di trascrizione su ~90 tabelle. Decisioni di conversione prese lungo il percorso:
- Ogni FK diventa un vincolo `FOREIGN KEY` esplicito con nome (`fk_<tabella>_<colonna>`), raggruppando correttamente le FK composite (stesso `id` in `PRAGMA foreign_key_list`) — un bug del generatore le trattava inizialmente come FK separate, causando un errore reale in fase di test (`errno 150`), corretto prima della consegna.
- I 14 trigger SQLite `*_updated_at` diventano `ON UPDATE CURRENT_TIMESTAMP` sulla colonna diretta (equivalente MySQL idiomatico, nessun trigger necessario).
- I 3 indici unique "parziali" (`WHERE col = valore`, non supportato da MySQL) diventano una colonna generata `VIRTUAL` + `UNIQUE KEY` sulla colonna generata (`idx_magazzini_un_predefinito`, `uq_um_riferimento`); `uq_sostanze_chimiche_cas` (`WHERE ... IS NOT NULL`) non necessita del trucco perché un `UNIQUE` MySQL normale ammette già più righe NULL.
- Colonne `TEXT` con `CHECK (... IN (...))` diventano `VARCHAR` dimensionato sul valore più lungo dell'enum (CHECK preservato, non scartato come nella precedente versione a mano del file — scelta più corretta, MariaDB 10.4/MySQL 8.0.16+ supportano ed enforce CHECK). Colonne `TEXT` con `DEFAULT` diventano `VARCHAR(255)` (MySQL non ammette `DEFAULT` letterale su `TEXT`/`BLOB`).
- `DEFAULT (DATE('now'))` (3 occorrenze: `data_emissione` su RFQ/Offerte/Ordini) diventa `DEFAULT (CURRENT_DATE)`.
- Tabelle con PK non numerica (es. `codice` testuale sulle lookup ADR) o PK composita: bug trovato e corretto nello script di generazione — la prima versione ometteva del tutto `PRIMARY KEY` per questi casi, gestendo solo l'`id INTEGER AUTOINCREMENT` singolo.
- Dati seed (schema_migrations, lookup ADR/CLP, unità di misura, ruoli, ecc.) rigenerati dal contenuto realmente presente nel DB SQLite dopo l'esecuzione dello script, non ricopiati a mano dal testo — 21 blocchi INSERT.

**Verifica effettiva** (non solo sintattica): avviata un'istanza MariaDB temporanea e isolata (datadir usa-e-getta, porta non standard, mai toccata la MySQL di XAMPP), importato il file per intero — **zero errori**. Controlli comportamentali eseguiti sull'istanza reale:
- Le 2 tabelle obsolete non esistono più.
- Un INSERT con `fornitore_id` inesistente su `ordini_testata` **viene correttamente rifiutato** (`errno 1452`) — la foreign key ora è realmente applicata, a differenza di prima.
- Un secondo magazzino con `predefinito = 1` viene correttamente rifiutato (`errno 1062`), un secondo magazzino con `predefinito = 0` viene correttamente accettato — l'indice parziale si comporta come in SQLite.
- Tabelle: 89 (+2 viste = 91 in `information_schema.tables`); foreign key: 219; viste: 2 — conteggi coerenti tra generazione e verifica.
- Istanza temporanea spenta e rimossa al termine, nessun impatto sulla MySQL di XAMPP (mai stata in esecuzione durante questo lavoro).

`db/init_mysql.sql` è ora il file consegnato in sostituzione del precedente (verificabile con `git diff`, versione precedente conservata nella cronologia).

---

## 2. Concetti/testi ripetuti

| Concetto | Occorrenze |
|---|---|
| Auto-split pianificazione consegne parziali (100→60+40, DDT, giacenza in arrivo/reale) | `Architettura ERP...md` righe 646-719 **e** 330-400 (quasi verbatim, due stesure); `Ciclo acquisti SAP-Inspired...md` righe 376-533; `dubbi e domande su rfq.md` righe 49-111 (terza riscrittura quasi identica) — **scritto 4 volte in totale** |
| Algoritmo di ricerca prezzo (scaglioni, listino, fallback) | `Architettura ERP...md` righe 226-234 (semplice), 553-605 (con listini), 776-872 (dettagliata step 1-4) — **3 stesure** |
| Tabella sequenze numerazione documenti (BEGIN IMMEDIATE, reset annuale) | `Architettura ERP...md` righe 1016-1069 e 1357-1489 — praticamente duplicata |
| Workflow invio RFQ offline ("Stampa & Segna") | `Architettura ERP...md` righe 260-284; `Ciclo acquisti SAP-Inspired...md` righe 118-159; `guida_ciclo_acquisti_v2.md` righe 14-22 |
| Le eccezioni sull'offerta fornitore (quantità diversa, offerta spontanea, prodotto sostitutivo, consegne scaglionate) | `guida_ciclo_acquisti_v2.md` righe 60-88 (5 eccezioni A-E); `Ciclo acquisti SAP-Inspired...md` righe 165-206 (4 eccezioni A-D) — **conteggio diverso, da unificare** |
| Prodotto sostitutivo non in anagrafica: Opzione A vs B | `guida_ciclo_acquisti_v2.md` righe 70-75; `Ciclo acquisti SAP-Inspired...md` righe 175-194; `dubbi e domande su rfq.md` righe 32-40 (qui come domanda aperta, non risposta) |
| Cancellazione ordine con merce parzialmente ricevuta | `Architettura ERP...md` righe 1339-1355 |
| Tolleranza esubero (gerarchia sistema/articolo/fornitore) | `Ciclo acquisti SAP-Inspired...md` righe 614-630 (unica stesura, ma da confrontare col campo reale `Tolleranza_Esubero_Percentuale` menzionato come domanda aperta in `dubbi e domande su rfq.md` righe 19-20) |

La ripetizione più grave è l'auto-split (4 stesure quasi identiche) e l'algoritmo prezzi (3 stesure): motivo principale per fondere tutto in un'unica stesura definitiva nel documento di flusso.

---

## 3. Eccezioni e casi limite non coperti da nessun documento

Analisi per sottoargomento, come richiesto — verificando se la documentazione copre davvero tutti gli aspetti e le eccezioni, non solo il percorso "felice".

### Magazzino / Giacenze (esempio guida per rigore)
- **Ricezione dirottata su magazzino diverso da quello pianificato sull'ordine**: il campo `ricezioni_testata.magazzino_id` è deliberatamente indipendente da `ordini_testata.magazzino_destinazione_id` — il commento nel codice dice esplicitamente "ricezione dirottata, o ordine ricevuto in più tranche su magazzini diversi" (righe 2247-2251 di `init_sqlite.sql`) e rimanda a un documento esterno **"Piano di Implementazione Multi-Magazzino, §3.4"** che non fa parte di questa cartella. **Nessuno dei documenti Ciclo Acquisti tratta questo caso**, benché sia esplicitamente supportato dal codice. Da colmare, anche solo con un rimando a quel piano.
- **`quantita_riservata`** in `giacenze` è aggregata per prodotto, non per singolo ordine cliente/magazzino specifico (il commento nel codice rimanda anch'esso a un "§4.4 del piano" esterno) — stesso problema strutturale già identificato per `quantita_in_arrivo` al punto 1.3: nessun documento del Ciclo Acquisti tratta le implicazioni di questa aggregazione quando più ordini insistono sullo stesso prodotto.
- **Soglia di riordino (`scorta_minima`)** è per coppia (prodotto, magazzino): nessun documento tratta come/quando viene generata automaticamente una RFQ quando la giacenza di un singolo magazzino scende sotto soglia (la "Via Automatica" descritta in `Architettura ERP...md` riga 161 parla solo del caso dropshipping, non del riordino per sottoscorta).
- **Trasferimenti tra magazzini** (`magazzino_origine_id`/`magazzino_destinazione_id`, con `CHECK` che vieta trasferimento verso se stesso) esistono nello schema ma non sono mai menzionati nel ciclo acquisti — corretto se sono fuori scope, ma andrebbe dichiarato esplicitamente che il trasferimento interno è un processo distinto, non parte del ciclo acquisti.

> **✅ RISOLTO (deciso in data 2026-07-14):** i documenti di Ciclo Acquisti sono anteriori all'introduzione del multi-magazzino — non è una lacuna da colmare voce per voce, semplicemente non potevano saperlo. Trovato in [`Gestione Magazzino e Lotti/Piano di Implementazione Multi-Magazzino.md`](../Gestione%20Magazzino%20e%20Lotti/Piano%20di%20Implementazione%20Multi-Magazzino.md) il trattamento completo di tutti e 4 i punti sopra (ricezione dirottata §3.4, scoping per sede di `scorta_minima` §2.8/§3.5, ecc.).
>
> **Decisione presa**: le 4 voci si chiudono con un unico rimando nel documento A definitivo, invece di essere descritte in dettaglio qui.
>
> **⚠️ Attenzione — rimando provvisorio**: quella cartella (`Gestione Magazzino e Lotti`) non è ancora stata sottoposta alla stessa revisione che stiamo facendo ora su Ciclo Acquisti — è probabile che contenga essa stessa ripetizioni/contraddizioni tra più file (proprio come questa cartella prima di questa revisione), e che un'eventuale riorganizzazione futura rinomini, fonda o spacchetti `Piano di Implementazione Multi-Magazzino.md`. **Il nome file citato qui andrà riconfermato** quando/se si farà la stessa operazione di revisione anche su quella cartella — non trattarlo come un link definitivo.

### Stati ordine / ciclo di vita
- Revisione (`superato`) di un ordine che ha già ricezioni parziali collegate: il codice blocca la revisione solo se stato ≠ `inviato` (riga 551), ma non verifica l'esistenza di ricezioni già registrate — rischio di ricezioni "orfane" collegate alla versione superata. Non trattato in nessun documento.

> **✅ RISOLTO (deciso in data 2026-07-14):** le ricezioni già registrate **non vanno mai ripuntate** alla nuova versione — sarebbe riscrivere a quali condizioni contrattuali è associato un evento fisico già avvenuto, incoerente col principio di immutabilità già adottato altrove (RFQ congelata, snapshot mai alterati). La soluzione corretta ha tre parti:
> 1. **Aggiungere `riga_padre_id` su `ordini_righe`** (stesso pattern di `ordine_padre_id` sulla testata, oggi assente a livello di riga). Quando `qk_ordine_crea_revisione()` clona le righe da v1 a v2, ogni nuova riga registra esplicitamente quale riga della versione precedente sostituisce — necessario per sapere con certezza quale riga di v2 continua quale riga di v1, specialmente se il numero di righe cambia tra versioni.
> 2. **"Quantità ricevuta" per una riga** = somma delle ricezioni collegate a quella riga **e a tutta la catena `riga_padre_id` a ritroso**, non solo quelle della versione corrente. Così v2 mostra "200 ricevuto su 500" senza aver mai spostato dati, e v1 resta prova intatta di cosa è arrivato sotto i suoi termini originari.
> 3. **Le ricezioni successive alla revisione** devono poter essere registrate solo contro la versione corrente (non `superato`) — da verificare che il flusso di ricezione selezioni oggi correttamente solo ordini non superati.
>
> **Azione tecnica**: migrazione schema (`riga_padre_id` su `ordini_righe`), modifica a `qk_ordine_crea_revisione()` per valorizzarla, nuova funzione di calcolo "quantità ricevuta cumulata" che risale la catena. Da implementare quando si passa alla fase di codice (non fatto ora, questa è la sola decisione di design).

- Nessun timeout/escalation per un ordine bloccato in `in_approvazione`.

> **✅ RISOLTO (deciso in data 2026-07-14):** verificato che `in_approvazione` è uno stato **mai raggiunto nella pratica** — esiste nello schema e viene letto (badge UI, guardia della funzione di approvazione), ma nessun punto del codice lo scrive mai. Oggi ogni ordine passa direttamente `bozza` → `approvato` con un solo pulsante "Approva Ordine", cliccabile da chiunque, senza instradamento, soglie per importo o controllo di ruolo. Il "problema del timeout" quindi non si pone: nessun ordine può restare bloccato in uno stato che non raggiunge mai.
>
> **Decisione presa**: nessun timeout da implementare. È corretto che un ordine resti indefinitamente in attesa se nessuno lo approva — non serve un'automazione che lo sblocchi da sola.
>
> **Da backlog** (richiesto esplicitamente dall'utente): l'idea di un vero flusso di approvazione strutturato (instradamento a un ruolo specifico, soglie per importo, doppio livello per importi alti) resta come sviluppo futuro riconosciuto ma non impegnato — vedi [Backlog Sviluppi Futuri.md](../Backlog%20Sviluppi%20Futuri.md).
- Un ordine `superato` risulta annullabile (la funzione di annullamento blocca solo `completato`/`annullato`, non `superato`) — scenario non contemplato da nessun documento.

> **✅ RISOLTO (deciso in data 2026-07-14):** un ordine `superato` (sostituito da una revisione più recente) è ormai solo storico e non dovrebbe più poter cambiare stato per nessun motivo. **Decisione presa**: aggiungere `superato` alla lista degli stati bloccati in `qk_ordine_annulla()` (`purchases_orders.php:438`), insieme a `completato` e `annullato`. Correzione tecnica piccola e senza ambiguità, da applicare quando si passa alla fase di codice.

### Indirizzo di consegna (ambito: Acquisti)
- Sincronizzazione (o assenza di essa) fra indirizzo master in `indirizzi` e lo snapshot in `indirizzi_documenti` quando il master viene modificato dopo l'emissione del documento: non discusso.
- Consegne split su più indirizzi per lo stesso ordine: non menzionato.

> **✅ RISOLTO (deciso in data 2026-07-14):** se il fornitore aggiorna il proprio indirizzo in anagrafica mentre esiste già una RFQ/Offerta/Ordine che lo referenzia, il documento **non si aggiorna da solo e nessun avviso viene mostrato** — resta la fotografia (snapshot) presa nel momento in cui il documento è stato creato, per sempre. Lo `hash_originale` salvato permetterebbe di rilevare la divergenza confrontandolo con un nuovo hash calcolato al volo, ma **nessun punto del codice lo fa oggi automaticamente**. Comportamento noto e già documentato, non una domanda aperta.
>
> **Dettaglio completo**: [`Guide Anagrafiche Clienti, Fornitori, Indirizzi/01 - Ciclo Acquisti - Indirizzi e Contatti da RFQ a Ordine.md`](../Guide%20Anagrafiche%20Clienti%2C%20Fornitori%2C%20Indirizzi/01%20-%20Ciclo%20Acquisti%20-%20Indirizzi%20e%20Contatti%20da%20RFQ%20a%20Ordine.md), §3.1 ("Caso Limite 1"). Nel documento A definitivo basta un rimando a quella sezione, non serve riscriverla.

### Multi-fornitore
- Cosa succede se il fornitore con `ordine_preferenza_fornitore = 1` viene disattivato (`attivo = 0`)? Nessun documento tratta un fallback automatico al secondo fornitore preferito.

### Pricing / UoM
- Prodotto senza UoM di acquisto definita: nel codice `prodotti_fornitori.unita_misura_acquisto_id` è `NOT NULL`, quindi non può esistere una riga fornitore senza UoM — ma cosa mostra l'interfaccia se l'utente prova ad aggiungerne una senza specificarla non è documentato.
- Fallback "ultimo prezzo pagato" mancante (punto 1.5) — lacuna nota ma non aggiornata in nessun documento.
- Cambio valuta a metà trattativa (offerta in USD, ordine da fare in EUR): il campo `valuta` esiste su tutte e tre le tabelle testata ma senza tasso di cambio né conversione; il valore è copiato as-is da uno stadio all'altro (`purchases_orders.php` riga 277). Nessun documento tratta questo flusso.

### Numerazione documenti
- Recovery se la tabella `sequenze` viene corrotta/cancellata manualmente: il codice lancia una `RuntimeException` (`functions.php` riga 501) ma nessuna procedura di recupero è documentata.

### Ricevimento merce
- Resi/note di credito al fornitore **dopo** la conferma di ricezione (difetto scoperto in un secondo momento): il campo `quantita_rifiutata`/`motivo_rifiuto` gestisce solo il rifiuto al momento del carico, non un reso successivo. Non trattato in nessun documento.
- Three-way match fatture: menzionato solo come "nota per lo sviluppatore" nel file SAP-Inspired (riga 634), nessun dettaglio implementativo né riscontro nel codice verificato.

### Cancellazione
- Cancellazione di una RFQ con più offerte concorrenti collegate: cosa succede alle offerte "in valutazione" non accettate se la RFQ viene chiusa senza selezionarne nessuna? Non trattato esplicitamente (oltre al vincolo noto che una RFQ con offerte collegate non è comunque eliminabile fisicamente, solo chiudibile).

### Approvazioni per importo
- Lo stato `in_approvazione` esiste nel codice, ma nessun documento tratta soglie differenziate per importo (es. secondo livello di autorizzazione sopra una certa cifra); `qk_ordine_approva` non fa alcun controllo su importo o ruolo, accetta l'approvazione da qualunque utente passato come parametro. Lacuna sia documentale sia, potenzialmente, di sicurezza applicativa.

---

## 4. Proposta di riorganizzazione dei file

In linea con l'indicazione dell'utente: un documento unico di flusso operativo (UX/UI + backend/DB insieme, passo-passo), documenti di dettaglio tecnico separati, e un contenitore unico per le ricerche di best practice (richiamato, non fuso).

**A. `Ciclo Acquisti — Flusso Operativo`** *(documento principale, unico)*
Percorso utente end-to-end RFQ → Offerte → Ordine → Ricezione, in stile "cosa vede l'utente / cosa fa il backend / cosa cambia nel DB" per ogni passo. Stati realmente implementati e transizioni reali (non quelle superate del primo documento). Contenuti da fondere in un'unica stesura (oggi ripetuti 2-4 volte): auto-split consegne parziali, algoritmo prezzi, workflow invio offline, eccezioni sull'offerta (unificando il conteggio A-D/A-E). Nuova sezione da scrivere ex novo: **"Immutabilità e Revisioni"**, che copre sia RFQ (`bozza`→`inviata`→`revisionata`/`chiusa`) sia Ordini (`inviato`→`superato` via revisione) come un unico principio architetturale coerente. Include il contenuto di `da RFQ a Conferma Ordine.md` così com'è (già corretto).

**B. `Ciclo Acquisti — Schema Dati e API`** *(documento tecnico separato)*
Tabelle realmente esistenti, nomi allineati a `db/init_sqlite.sql` (non ai nomi `T_Maiuscoli` del file SAP-Inspired). Numerazione documenti (un'unica stesura, non due), motore prezzi/listini con la lacuna del fallback "ultimo prezzo pagato" segnalata come todo, UoM a tre livelli, tolleranza esubero, i due difetti tecnici del punto 1.10 come promemoria di correzione.

**C. `Ciclo Acquisti — Ricerche di Best Practice`** *(contenitore unico, richiamato non fuso)*
Tutto il materiale comparativo SAP/Odoo/Dynamics/NetSuite oggi sparso: la sezione "come fanno gli ERP best in class" di `Architettura ERP...md`, l'intero `ricerca_indirizzo_consegna_preventivi.md` riscritto come nota storica (la funzionalità è già implementata, non più "da fare"), il confronto Opzione A/B sul multi-fornitore, e il "Percorso B: Contratto Quadro" del file SAP-Inspired marcato esplicitamente come backlog mai iniziato. Il documento A rimanda qui con "vedi contenitore best practice, sezione X" invece di duplicare.

**D. `Ciclo Acquisti — Domande Aperte / Backlog`**
Trasforma `dubbi e domande su rfq.md` in backlog vivo: le domande ormai risolte da questa verifica (righe free-text scartate per scelta anagrafica-first, campo fornitore preferito reale, ecc.) diventano risposte scritte; restano aperte le domande verificate come davvero irrisolte nel codice (soglie di approvazione per importo, resi post-ricezione, multi-valuta, fallback ultimo prezzo pagato, ricezione dirottata multi-magazzino — quest'ultima da riconciliare col "Piano di Implementazione Multi-Magazzino" esterno).

**File da eliminare**: nessuno — `da RFQ a Conferma Ordine.md` è stato ripristinato dall'utente ed è ora materiale valido per il documento A.

---

*Questo documento è la sola analisi richiesta. La riscrittura vera e propria nei 4 file proposti (A-D) è un passo successivo, da avviare solo dopo conferma.*
