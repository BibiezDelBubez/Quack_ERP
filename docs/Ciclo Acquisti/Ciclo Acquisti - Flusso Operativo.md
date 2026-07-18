# Ciclo Acquisti — Flusso Operativo

Percorso utente end-to-end **RFQ → Offerte → Ordine → Ricezione**, stile "cosa vede l'utente / cosa fa il backend / cosa cambia nel DB" per ogni passo. Questo è il documento principale del ciclo acquisti: descrive il comportamento reale del codice a oggi (18/07/2026), verificato riga per riga contro `db/init_sqlite.sql` e gli `includes/purchases_*.php`/controller — non le versioni superate o mai implementate che comparivano nei documenti sorgente.

Per lo schema dati completo (colonne, tabelle, API) vedi il documento B (*Schema Dati e API*). Per il confronto con SAP/Odoo/Dynamics/NetSuite dietro ogni scelta architetturale vedi il documento C (*Ricerche di Best Practice*). Per le lacune note e il backlog vedi il documento D.

**Architettura di base**: il ciclo ruota attorno a **4 moduli/tabelle separate** — `rfq_testata/righe`, `offerte_testata/righe`, `ordini_testata/righe` (+ `ordini_righe_pianificazione`), `ricezioni_testata/righe` — non un singolo record con campo stato. È il modello usato da tutti i grandi ERP (RFQ, Quotation, PO, Goods Receipt sono sempre entità distinte) ed è quello realmente implementato.

---

## 1. Fase A — RFQ (Richiesta di Preventivo)

### 1.1 Creazione (stato `bozza`)

**Cosa vede l'utente**: form di nuova RFQ — fornitore, date, condizioni di pagamento, valuta, indirizzo/contatto di consegna, righe (prodotto, quantità, UoM, prezzo suggerito).

**Cosa fa il backend**: `qk_rfq_salva()` (`includes/purchases_rfq.php`) inserisce/aggiorna `rfq_testata` + `rfq_righe` dentro un'unica transazione, ricalcola i totali (`_rfq_ricalcola_totali()`) **lato server, non lato client** — per lo stesso motivo per cui si evitano i prezzi lordi a DB (§2.5 documento B): un ricalcolo affidato al solo Javascript sarebbe soggetto ad arrotondamenti diversi da browser a browser, con discrepanze tra quanto visto in form e quanto salvato — e fotografa indirizzo/contatto scelti in `indirizzi_documenti`/`contatti_documenti` (vedi §5 sotto). Lo stesso principio (mai fidarsi del calcolo client per il valore salvato) vale per ogni testata che aggrega righe in questo ciclo.

**Cosa cambia nel DB**: `rfq_testata.stato = 'bozza'`. Nessun impatto su giacenze.

Finché la RFQ è in bozza, l'utente ha controllo totale: articoli, quantità, destinatario, prezzi si possono cambiare liberamente, nessuno storico di modifica viene registrato. È un blocco note.

**Algoritmo di suggerimento prezzo** (si ricalcola a ogni cambio di quantità o confezione, vedi §4 per il dettaglio completo):

1. Cerca in `prodotti_fornitori` la coppia (prodotto, fornitore) e lo scaglione di prezzo con `min_qty` più alto ≤ alla quantità inserita, tra quelli validi oggi (`valid_from`/`valid_to`).
2. Se non trovato, cerca un prezzo base sulla scheda prodotto.
3. Se anche questo manca, cerca l'ultimo prezzo realmente pagato in un ordine inviato per quella coppia prodotto/fornitore (fallback storico, vedi §4.3).
4. Se anche il terzo tentativo fallisce, il campo prezzo resta vuoto — l'operatore lo inserisce a mano.

Il prezzo proposto è sempre un **suggerimento precompilato**, mai un valore imposto: l'operatore può modificarlo prima di salvare la riga.

### 1.2 Invio al fornitore (`bozza` → `inviata`)

**Cosa vede l'utente**: pulsante "Genera PDF e Invia" sulla RFQ in bozza. Al click si apre un popup con due possibilità, perché l'ERP non invia email in autonomia (gira in locale, nessun SMTP garantito):

- **Scarica PDF** della richiesta, da allegare manualmente alla mail.
- **Copia testo mail** pre-compilato (dettagli RFQ + riferimento documento) da incollare nel client di posta aziendale.

In fondo al popup, un pulsante distinto **"Registra come Inviata"**: solo quando l'utente lo clicca, il frontend chiama il backend che esegue davvero la transizione di stato. Fino a quel momento la RFQ resta in bozza, anche se il PDF è già stato scaricato e la mail già spedita fuori dal sistema.

**Cosa fa il backend**: `qk_rfq_invia()` — verifica che lo stato sia `bozza`, poi `UPDATE rfq_testata SET stato = 'inviata', inviata_il = CURRENT_TIMESTAMP`.

**Cosa cambia nel DB**: `stato = 'inviata'`. Nessun impatto su giacenze — è ancora solo carta.

**Cosa vede l'utente dopo**: i campi si bloccano (readonly), badge "Stato: Inviata".

### 1.3 RFQ inviata: cosa succede davvero (vedi §5 "Immutabilità e Revisioni")

Una volta inviata, la RFQ è **completamente non modificabile — prezzi inclusi**. Non esiste un margine di "solo i prezzi restano editabili": qualunque tentativo di `UPDATE` su `rfq_testata`/`rfq_righe` con stato diverso da `bozza` viene rifiutato a livello di funzione (`qk_rfq_salva()`, `includes/purchases_rfq.php`), indipendentemente da cosa fa o non fa il controller a monte. Se il fornitore risponde con condizioni diverse (sconto volume, lotto minimo), quello scostamento **non tocca mai la RFQ**: si registra come dato distinto nella riga dell'Offerta (fase B).

Se serve davvero cambiare qualcosa nella richiesta stessa (nuove necessità, errore di battitura grave) prima ancora di ricevere risposta, l'unica via è **"Crea Nuova Versione"** — vedi §5.

### 1.4 Chiusura manuale senza selezione (`inviata`/`bozza` → `chiusa`)

**Cosa vede l'utente**: pulsante "Chiudi RFQ" — usato quando l'indagine di mercato non porta a nulla (progetto annullato, nessun fornitore interessante).

**Cosa fa il backend**: `qk_rfq_chiudi()` — imposta `rfq_testata.stato = 'chiusa'`, `esito_chiusura = 'annullata'`, **e** rifiuta automaticamente, nella stessa transazione, tutte le offerte collegate ancora `in_valutazione` (`motivo_rifiuto = 'RFQ chiusa senza selezione'`). Questo è simmetrico a cosa succede quando un'offerta viene accettata (§2.4): sia la chiusura con successo sia quella senza selezione portano sempre le offerte concorrenti a uno stato terminale, mai le lasciano pendenti in `in_valutazione` all'infinito.

---

## 2. Fase B — Offerte Fornitore

### 2.1 Registrazione offerta

**Cosa vede l'utente**: da una RFQ inviata, "Registra Offerta" — il sistema pre-compila articoli e quantità dalla RFQ di origine. L'utente inserisce prezzi unitari, sconti, aliquote IVA, data di consegna proposta dal fornitore.

Una RFQ può ricevere **più offerte contemporaneamente** (stessa RFQ mandata a più fornitori in parallelo, o lo stesso fornitore che risponde più volte) — più righe `offerte_testata` possono restare in `in_valutazione` sulla stessa `rfq_id`.

**Cosa cambia nel DB**: `offerte_testata.stato = 'in_valutazione'`. Ogni riga offerta referenzia la riga RFQ di origine (`rfq_riga_id`), per tracciare lo scostamento tra richiesto e offerto.

**Offerta spontanea** (non legata a nessuna RFQ): stesso flusso, con `rfq_id` lasciato nullo. Utile quando un fornitore propone qualcosa di sua iniziativa.

### 2.2 Indirizzo di consegna

Ogni offerta ottiene una propria copia indipendente dello snapshot indirizzo/contatto (non un riferimento condiviso con la RFQ). Dettaglio completo — badge UI, casi limite su anagrafica che cambia dopo l'emissione, snapshot orfani — in [`Guide Anagrafiche Clienti, Fornitori, Indirizzi/01 - Ciclo Acquisti - Indirizzi e Contatti da RFQ a Ordine.md`](../Guide%20Anagrafiche%20Clienti%2C%20Fornitori%2C%20Indirizzi/01%20-%20Ciclo%20Acquisti%20-%20Indirizzi%20e%20Contatti%20da%20RFQ%20a%20Ordine.md). In breve: RFQ, Offerta e Ordine non leggono mai in tempo reale l'indirizzo da anagrafica — lo fotografano al momento della creazione/modifica in `indirizzi_documenti`/`contatti_documenti`, con hash per rilevare (non correggere automaticamente) una divergenza futura dal master.

### 2.3 Eccezioni sull'offerta

Il sistema gestisce nativamente cinque scostamenti tra ciò che è stato richiesto (RFQ) e ciò che il fornitore propone:

**A — Quantità diversa (es. lotto minimo).** L'utente inserisce nella riga offerta la quantità reale proposta dal fornitore (es. richiesti 100, lotto minimo di vendita 120). Il sistema accetta il valore, mostra un avviso visivo di scostamento (es. "+20%") nella schermata di confronto offerte, e ricalcola il totale sulla quantità reale.

**B — Offerta completamente diversa da quanto chiesto (offerta spontanea).**
- *Rifiuto*: l'utente registra l'offerta con motivo di rifiuto obbligatorio (es. "Fuori budget"), stato `rifiutata` — alimenta le statistiche fornitore.
- *Accettazione*: "Nuova Offerta Spontanea" con `rfq_id` vuoto, righe e prezzi inseriti a mano. Una volta accettata, genera comunque un Ordine di Acquisto come qualunque altra offerta.

**C — Prodotto sostitutivo non in anagrafica.** Checkbox "Proponi Sostituto" sulla riga offerta:
- *Opzione A (nuova variante fornitore)*: associa un prodotto già esistente in anagrafica a un nuovo codice articolo fornitore in `prodotti_fornitori` — nessun nuovo prodotto creato.
- *Opzione B (nuovo articolo)*: crea il prodotto in `prodotti`, il legame in `prodotti_fornitori`, e aggiorna la riga offerta a puntare al nuovo `prodotto_id` — tutto in una transazione.

Il prodotto creato al volo da questo percorso è **minimale**: nessuna classificazione ADR, nessuna tracciabilità lotti impostata. Accettabile per lo scenario "sostituto proposto dal fornitore, da completare poi in anagrafica" — da non riusare per altri casi d'uso dove servirebbe l'anagrafica prodotto completa (vedi documento D per il richiamo esplicito).

**D — Consegne scaglionate pianificate sull'offerta (split di riga per date diverse).** "Sdoppia Riga" sulla riga offerta: crea un record figlio in `offerte_righe`, collegato alla stessa riga RFQ di origine e puntante alla riga offerta padre. L'utente imposta quantità e data di consegna diverse per ciascuna tranche. In fase di generazione ordine, ogni tranche diventa una riga distinta in `ordini_righe_pianificazione`.

**E — Consegna scaglionata non pianificata (arrivo parziale imprevisto in fase di ricezione).** Questa non è un'eccezione sull'offerta ma sulla ricezione — vedi §4.4 ("auto-split").

> Le vecchie versioni della documentazione contavano queste eccezioni in modo diverso (4 vs 5 voci, con "consegna scaglionata pianificata" ed "eccezione E — arrivo parziale imprevisto" a volte fuse a volte separate). Qui sono tenute distinte perché avvengono in fasi diverse del flusso (offerta vs ricezione) e con meccanismi di sistema diversi (split manuale a monte vs auto-split a valle).

### 2.4 Accettazione offerta

**Cosa vede l'utente**: pulsante "Accetta Offerta" su una riga in `in_valutazione`.

**Cosa fa il backend**: `qk_offerta_accetta()` (`includes/purchases_quotes.php`) — imposta l'offerta scelta a `accettata`, **rifiuta automaticamente tutte le altre offerte `in_valutazione` sulla stessa `rfq_id`**, chiude la RFQ di origine (`chiusa`, esito diverso dalla chiusura manuale), e abilita la generazione dell'Ordine.

**Cosa cambia nel DB**: `offerte_testata.stato = 'accettata'` sulla scelta, `rifiutata` sulle concorrenti, `rfq_testata.stato = 'chiusa'`.

**Limite noto: nessun cruscotto di confronto tra offerte.** L'accettazione è offerta-per-offerta, intera — non esiste una vista comparativa affiancata (matrice prodotti × fornitori, punteggio automatico, "aggiudica per riga") come in alcuni ERP enterprise. Con poche offerte concorrenti (il caso tipico qui) il confronto manuale resta gestibile; vedi documento D per la voce di backlog se in futuro il numero di fornitori contattati in parallelo cresce.

---

## 3. Fase C — Ordine di Acquisto

### 3.1 Generazione (`bozza`)

**Cosa fa il backend**: `qk_ordine_da_offerta()` — cristallizza prezzi, quantità e data di consegna concordati nell'offerta accettata, generando `ordini_testata`/`ordini_righe` con `stato = 'bozza'` (o direttamente pianificazione tranche multiple, se l'offerta aveva split di tipo D). Ogni riga ordine referenzia la riga offerta di origine.

### 3.2 Approvazione (`bozza` → `approvato`, passando per `in_approvazione`)

Nello schema esiste uno stato intermedio `in_approvazione`, pensato per un flusso di autorizzazione strutturato — **ma non è mai raggiunto nella pratica**. `qk_ordine_approva()` non fa alcun controllo di ruolo o soglia per importo: un solo pulsante "Approva Ordine", cliccabile da chiunque, porta l'ordine direttamente da `bozza` ad `approvato`. Non esiste oggi un secondo livello di autorizzazione né un timeout su ordini bloccati — perché nessun ordine ci si blocca mai, dato che lo stato intermedio non viene mai scritto. Un flusso di approvazione vero (instradamento a ruolo, soglie per importo) è backlog riconosciuto ma non implementato — vedi documento D.

### 3.3 Invio al fornitore (`approvato` → `inviato`)

**Cosa fa il backend**: `qk_ordine_invia()` — valida che l'ordine sia completo, passa `stato = 'inviato'`.

**Cosa cambia nel DB**: aumenta `giacenze.quantita_in_arrivo` per ogni prodotto ordinato, della quantità scritta in ordine. La **Giacenza Virtuale** (quella mostrata a chi vende) è `Giacenza Reale + Giacenza in Arrivo − Impegnato per i Clienti`.

Da questo momento l'ordine è **completamente blindato**: prezzi, quantità e prodotti non sono più modificabili in alcun modo — solo note testuali/riferimenti logistici (`qk_ordine_aggiorna_note()`). Qualunque cambiamento di prezzo/quantità post-invio richiede una **revisione** (§5), mai una modifica diretta.

### 3.4 Ricezione (`inviato` → `parzialmente_ricevuto` → `completato`)

Vedi §4 per il dettaglio.

### 3.5 Annullamento

**Cosa vede l'utente**: pulsante "Annulla Ordine", bloccato se lo stato è già `completato`, `annullato` o `superato`.

**Cosa fa il backend**: `qk_ordine_annulla()` — permesso anche su un ordine `parzialmente_ricevuto`. Un ordine parzialmente ricevuto può essere annullato: questo va inteso come **chiusura del residuo non ancora ricevuto**, non come storno della merce già entrata a magazzino, che resta intatta. Se lo stato è `parzialmente_ricevuto`, il frontend mostra un popup di conferma esplicativo (non un generico "sei sicuro?") che spiega cosa sta per succedere prima di procedere — senza un conteggio numerico aggregato di quanto ricevuto/mancante, perché righe diverse possono avere UoM diverse tra loro e un totale sommato sarebbe fuorviante.

**Cosa cambia nel DB**: `_ordine_aggiorna_in_arrivo()` sottrae da `giacenze.quantita_in_arrivo` solo la quantità **residua non ancora ricevuta** di quell'ordine — non la quantità totale ordinata originaria. Questo evita che l'annullamento di un ordine azzeri erroneamente giacenza-in-arrivo dovuta ad *altri* ordini ancora aperti sullo stesso prodotto/magazzino (bug corretto il 17/07/2026, vedi documento B per il dettaglio tecnico).

Un ordine `superato` (sostituito da una revisione più recente) **non è mai annullabile**: è solo storico.

**Cancellazione fisica vs soft, per stato**: coerente con la logica di annullamento sopra, una RFQ in `bozza` (mai condivisa con l'esterno) può essere cancellata fisicamente dal DB; una RFQ già `inviata` non viene mai eliminata fisicamente, solo transizionata di stato (`chiusa`/`revisionata`) — stesso principio dei documenti "atto ufficiale" di §5. `qk_rfq_delete()` blocca comunque la cancellazione fisica se esistono offerte collegate, indipendentemente dallo stato.

**Limite noto: nessuna funzione "Duplica"/"Crea Copia" da un ordine annullato.** Alcuni ERP (es. Odoo) permettono di generare un nuovo ordine bozza con le stesse righe/fornitore/prezzi a partire da uno annullato, per non dover reinserire tutto da capo in caso di errore di annullamento o di riordino identico. Non esiste in Quack — da non confondere con la revisione (§5), che parte da un ordine `inviato`, non da uno `annullato`. Nessuna decisione presa se aggiungerla.

### 3.6 Fatturazione (tracciabilità amministrativa, non three-way match)

Ogni ordine ha un campo `stato_fatturazione` (`da_fatturare`/`fatturato`, default `da_fatturare`), con due azioni di toggle nel dettaglio ordine — stesso pattern già in uso lato Vendite su `ordini_clienti`. È una **spunta manuale binaria**, non un controllo automatico di corrispondenza importi/quantità: l'amministrazione la usa per sapere se un ordine già inviato/ricevuto è stato o no fatturato, nessuna verifica incrociata. Il three-way match vero (Ordine ↔ Ricezione ↔ Fattura Fornitore, con blocco pagamento su scostamenti) richiede un intero modulo Fatture Fornitori che oggi non esiste — resta backlog, vedi documento D.

### 3.7 Navigazione tra documenti collegati — parziale

Dal dettaglio di una **Ricezione** esiste un link che torna all'Ordine padre; dal dettaglio di un **Ordine** esiste una sezione che elenca le Ricezioni collegate. Manca invece il verso opposto della catena: dal dettaglio dell'**Ordine** il riferimento all'Offerta/RFQ di origine è solo testo statico (`Rif. RFQ: {numero}`), non un link cliccabile — non si può risalire da un ordine alla RFQ o all'offerta che l'hanno generato con un clic. Verificato 18/07/2026, non risulta backlog né decisione presa di lasciarlo così.

---

## 4. Fase D — Ricezione Merce

### 4.1 Apertura ricezione

**Cosa vede l'utente**: da un ordine `inviato` o `parzialmente_ricevuto`, "Registra Ricezione Merci". Il sistema propone automaticamente le quantità ancora da ricevere (residuo per riga, tenendo conto della tolleranza esubero — vedi §4.3).

**Cosa fa il backend**: `qk_ricezione_apri()` — crea `ricezioni_testata` in `stato = 'bozza'`, collegata all'ordine. **Non tocca lo stato dell'ordine né alcuna quantità**: finché la ricezione resta in bozza, l'ordine risulta ancora `inviato`/`parzialmente_ricevuto` a tutti gli effetti.

### 4.2 Controllo qualità e lotti

**Cosa vede l'utente**: per ogni riga, campo quantità accettata precompilato col residuo atteso, campo quantità rifiutata (con motivo obbligatorio, es. "Merce danneggiata") e numero DDT fornitore obbligatorio. Se il prodotto traccia lotti, campi aggiuntivi mostrati **dinamicamente** e validati lato client prima dell'invio del modulo (lotto esistente o nuovo: codice lotto fornitore, codice interno, data scadenza).

Il dettaglio di schema sui lotti (`data_scadenza` nullable per best practice — non tutti i prodotti chimici hanno una scadenza, es. alcuni acidi/basi stabili indefinitamente; `quantita_iniziale`/`quantita_attuale` separate) è materia del dominio Magazzino/Lotti, non ripetuto qui — vedi la cartella `Gestione Magazzino e Lotti`.

### 4.3 Tolleranza esubero

Ogni riga d'ordine porta un campo `tolleranza_esubero_perc` (snapshottato dall'offerta). La quantità massima accettabile in una singola ricezione è `quantita_ordinata × (1 + tolleranza_esubero_perc / 100)` — oltre questo limite la ricezione va corretta a mano o gestita come reso/eccezione, non passa come consegna normale.

### 4.4 Conferma e auto-split

**Cosa fa il backend**: `qk_ricezione_conferma()` (`includes/purchases_receipts.php`), dentro un'unica transazione:

1. Registra la quantità accettata/rifiutata per riga, con eventuale lotto.
2. Aggiorna `giacenze.quantita_disponibile` (+ quantità accettata) e `giacenze.quantita_in_arrivo` (− residuo di quella riga, non il totale ordinato).
3. Avanza la pianificazione consegna (`_ricezione_avanza_pianificazione()`), tranche per tranche:
   - Se la quantità ricevuta cumulata **raggiunge o supera** la tranche pianificata, la tranche passa a `completata`. Se la supera (esubero entro tolleranza), l'eccesso genera un **record figlio** in `ordini_righe_pianificazione` (`riga_padre_id` valorizzato), anch'esso subito `completata` — è lo split automatico che dà nome all'eccezione E.
   - Se la quantità ricevuta cumulata **resta sotto** la tranche pianificata, la tranche resta la stessa riga, aggiornata a `stato = 'ricevuta_parziale'` — **non viene creato un nuovo record separato per il residuo**: è la stessa tranche che resta aperta, in attesa del saldo. (Questo differisce da una descrizione più vecchia, mai verificata contro il codice, che parlava di un nuovo record "in attesa" per il residuo — non è così: il residuo resta sulla riga di pianificazione esistente.)
4. `qk_ordine_aggiorna_stato_ricezione()` ricalcola lo stato testata dell'ordine confrontando la somma di `quantita_ordinata` con la somma di `quantita_ricevuta` su tutte le righe: se il totale ricevuto è ancora inferiore all'ordinato, `parzialmente_ricevuto`; se combacia, `completato`.

**Esempio concreto** (100 kg ordinati, tolleranza 0%):
- Primo carico: arrivano 60 kg → `giacenza_disponibile` 0→60, `giacenza_in_arrivo` 100→40, tranche `ricevuta_parziale` (60/100), ordine → `parzialmente_ricevuto`.
- Secondo carico: arrivano i 40 kg residui → `giacenza_disponibile` 60→100, `giacenza_in_arrivo` 40→0, tranche → `completata`, ordine → `completato`.

Nella rivendita di prodotti chimici le consegne parziali non sono un'eccezione rara ma la norma operativa (indisponibilità di stock dal produttore, camion ADR già saturi che costringono a spedire il resto a parte, ecc.) — l'auto-split esiste proprio perché è il caso comune, non quello raro.

**Limite noto: nessuna interfaccia per riprogrammare la data di consegna di una tranche residua.** Se il fornitore comunica un ritardo su un residuo già pianificato (es. "i 40 kg mancanti arrivano il 15 giugno, non prima"), oggi non esiste un modo per aggiornare `ordini_righe_pianificazione.data_consegna_prevista` da interfaccia — solo una nota libera. Vedi documento D.

### 4.5 Ricezione dirottata su magazzino diverso

`ricezioni_testata.magazzino_id` è **deliberatamente indipendente** da `ordini_testata.magazzino_destinazione_id` — copre sia il caso "ricezione dirottata" sia "ordine ricevuto in più tranche su magazzini diversi". Il dettaglio completo (implicazioni sull'aggregazione di `quantita_riservata`/`quantita_in_arrivo` per prodotto invece che per singolo ordine/magazzino, generazione automatica di RFQ sotto-scorta per singolo magazzino) è materia del **Piano di Implementazione Multi-Magazzino**, fuori da questa cartella — vedi [`Gestione Magazzino e Lotti/Piano di Implementazione Multi-Magazzino.md`](../Gestione%20Magazzino%20e%20Lotti/Piano%20di%20Implementazione%20Multi-Magazzino.md) (nome file da riconfermare se quella cartella subisce a sua volta una riorganizzazione).

I trasferimenti interni tra magazzini (`magazzino_origine_id`/`magazzino_destinazione_id` su tabella dedicata) sono un processo **distinto**, non parte del ciclo acquisti.

### 4.6 Resi al fornitore dopo la conferma

Non esiste un flusso guidato "reso da questa ricezione" (a differenza di SAP Return Delivery, D365 Purchase return order, Odoo Return). Il modo attuale — considerato sufficiente per l'uso corrente, non un bug — è il modulo generico "Nuovo movimento" di magazzino, tipo `reso_fornitore`: collegamento all'ordine/ricezione di origine solo tramite un campo di testo libero, nessun selettore guidato. Un flusso guidato è backlog futuro, vedi documento D.

### 4.7 Bypass — carico manuale senza passare dalla ricezione

Se un operatore aggiorna la giacenza a mano dalla scheda prodotto invece di registrare la ricezione, la **Giacenza in Arrivo** dell'ordine non si scala mai automaticamente, e la Giacenza Virtuale finisce falsata (crede di avere merce in arrivo che è già a magazzino). Per questo esiste in interfaccia un pulsante **"Forza Chiusura Ordine"** (`app/views/purchases/orders/_detail.php`), pensato per riconciliare esplicitamente questi casi.

**⚠️ Bug verificato (18/07/2026): il pulsante non funziona.** `PurchaseOrdersController` gestisce l'azione `forza_chiusura` chiamando `qk_ordine_forza_chiusura()` — **funzione che non esiste nel codebase** (nessuna definizione trovata; confermato anche dal baseline PHPStan, che la elenca come "Function not found"). Cliccare il pulsante oggi produce un fatal error, non una riconciliazione. Bug attivo, non solo lacuna documentale — vedi documento D (Domande Aperte/Backlog) per la decisione su come e quando risolverlo.

---

## 5. Immutabilità e Revisioni

Principio architetturale unico, applicato in modo coerente sia a RFQ sia a Ordini: **un documento inviato all'esterno (fornitore) non si modifica mai una volta uscito — o resta congelato per sempre, o viene sostituito da una nuova versione tracciata**. Mai un "annulla e rifai" silenzioso, mai una sovrascrittura che cancella cosa era stato comunicato originariamente.

### 5.1 RFQ: `bozza` → `inviata` → `revisionata` / `chiusa`

- **`bozza`**: liberamente modificabile, nessuno storico.
- **`inviata`**: **completamente immutabile, prezzi inclusi**. Il blocco è applicato a livello di funzione (`qk_rfq_salva()`), non delegato al controller — qualunque `UPDATE` su `rfq_testata`/`rfq_righe` con stato diverso da `bozza` viene rifiutato con eccezione esplicita, indipendentemente da come viene invocata la funzione. Non esiste un margine "solo i prezzi sono modificabili": è una versione di documentazione precedente, mai vera nel codice.
- **`revisionata`**: stato di una RFQ inviata che è stata sostituita da una nuova versione (vedi sotto). Anch'essa non più modificabile.
- **`chiusa`**: fine flusso, via accettazione di un'offerta o chiusura manuale (§1.4).

**Creare una nuova versione** (`qk_rfq_nuova_versione()`, solo da stato `inviata`): duplica la RFQ corrente in una nuova scheda (nuovo numero, es. `RFQ/2026/0004 v2`), con `rfq_padre_id` a collegarla alla versione precedente e propri snapshot indipendenti di indirizzo/contatto (mai condivisi con l'originale). La versione precedente passa a `revisionata`. La nuova nasce in `bozza`, di nuovo liberamente modificabile.

Qualunque scostamento di prezzo o quantità rispetto alla RFQ originale (sconto volume, lotto minimo del fornitore) **non giustifica mai una modifica diretta**: va registrato come dato separato nella riga dell'Offerta collegata, mai sovrascrivendo la RFQ.

### 5.2 Ordini: `inviato` → `superato` via revisione

Una volta che un ordine è `inviato`, prezzi e quantità sono blindati. Se il fornitore cambia condizioni dopo l'invio, l'unico modo corretto per aggiornarle **non è "annulla e rifai"** (comportamento descritto in vecchia documentazione, mai realmente implementato) ma la **revisione strutturata**, analoga al PO amendment di SAP/Oracle:

`qk_ordine_crea_revisione()` (solo da stato `inviato`) clona testata/righe/snapshot indirizzo-contatto in un nuovo ordine (nuovo numero, es. `ORD/2026/0012 v2`, `ordine_padre_id` verso l'originale), e marca l'ordine originale come `superato`. La nuova versione nasce `bozza`.

Un ordine `superato`:
- non è mai più annullabile (§3.5);
- non riceve mai nuove ricezioni (bloccato in `qk_ricezione_conferma()`, che verifica che l'ordine collegato sia ancora `inviato` o `parzialmente_ricevuto` prima di scrivere qualunque dato).

**Guardia contro le "ricezioni orfane"**: se esiste una ricezione ancora `bozza` collegata all'ordine (aperta ma non ancora confermata), `qk_ordine_crea_revisione()` **blocca la revisione** finché quella ricezione non viene completata o annullata. Senza questa guardia sarebbe possibile aprire una ricezione bozza su un ordine `inviato`, revisionare l'ordine (portandolo a `superato`), e poi confermare la ricezione rimasta agganciata alla versione ormai storica — i dati (giacenze, lotti) finirebbero scritti sulla versione sbagliata, invisibili in quella nuova davvero attiva. Due guardie indipendenti coprono lo scenario (in `qk_ordine_crea_revisione()` e, come seconda linea di difesa, in `qk_ricezione_conferma()`), coerenti con l'approccio SAP/Odoo/Dynamics: bloccare la modifica di un documento con documenti a valle già collegati, invece di scoprirla dopo.

*(Nota tecnica: `qk_ordine_crea_revisione()` non ha oggi nessun chiamante nell'interfaccia — è una funzionalità di amendment scritta e verificata, ma non ancora collegata a un pulsante nella UI. Le guardie sopra sono comunque attive fin da ora, così il giorno in cui verrà collegata il comportamento sarà già corretto.)*

### 5.3 Chiusura RFQ e offerte concorrenti: sempre uno stato terminale

Sia l'accettazione di un'offerta sia la chiusura manuale di una RFQ senza selezione portano **sempre** le offerte concorrenti `in_valutazione` a uno stato terminale (`accettata`/`rifiutata`), mai le lasciano pendenti indefinitamente — vedi §1.4 e §2.4. È lo stesso principio di immutabilità applicato a un caso specifico: un documento padre chiuso non può lasciare figli in stato limbo.

---

## 6. Multi-fornitore: il fornitore preferito è il primo attivo, non un riferimento fisso

`prodotti_fornitori.ordine_preferenza_fornitore` (relazione N:N, non un singolo campo `fornitore_default_id` come descritto in vecchia documentazione) stabilisce un ordine di preferenza fra i fornitori collegati a un prodotto. La regola corretta, applicata in `qk_rfq_detail()` e nelle proposte di riordino sotto-scorta, è: **il fornitore preferito è il primo attivo per ordine di preferenza**, non semplicemente quello con `ordine_preferenza_fornitore = 1` a prescindere dal suo stato. Se il fornitore preferito in assoluto viene disattivato, il sistema scorre automaticamente al successivo attivo — stesso comportamento di SAP (source list), Dynamics 365 (purchase requisition sourcing) e Odoo (vendor pricelist priority). Se non resta nessun fornitore attivo, l'interfaccia lo segnala esplicitamente ("Nessun fornitore attivo") invece di lasciare un campo vuoto indistinguibile da un dato mai censito.

**Avviso "Fornitore Abituale"**: quando si aggiunge a una RFQ una riga il cui fornitore abituale (il preferito per quel prodotto) è diverso dal fornitore scelto in testata, la riga viene evidenziata con un badge "⚠️ Fornitore Abituale: [nome]". L'avviso si ricalcola su tutte le righe già presenti anche se l'operatore cambia il fornitore in testata dopo averle già aggiunte — non solo sulle nuove righe.

---

## 7. Limitazioni note e consapevoli (non lacune da colmare adesso)

- **Multi-valuta**: il campo `valuta` è un `<select>` libero e indipendente su ogni testata (RFQ, Offerta, Ordine), senza tasso di cambio né conversione — il valore numerico del prezzo passa identico da uno stadio all'altro anche se la valuta cambia. Nessun controllo incrociato. Intervento rimandato per intero al backlog (documento D, che consolida anche il tema multi-valuta lato KPI/dashboard).
- **Numero di documento**: sequenza `sequenze` con lock via transazione, reset annuale automatico. Se la riga di sequenza per un tipo di documento viene persa/corrotta, oggi il sistema mostra un errore generico (pagina 500) — la pagina admin **Impostazioni → Progressivi e Canali** permette di ispezionare/correggere le sequenze manualmente. Dettaglio tecnico completo nel documento B.
- **Contratto Quadro / Blanket PO** (chiamate d'ordine ricorrenti contro un accordo quadro): non implementato, non fa parte del flusso attuale. Vedi backlog.
- **Righe RFQ senza prodotto a catalogo** (free-text, es. campionature): scartate per scelta — `prodotto_id` è `NOT NULL` ovunque nella catena RFQ→Offerta→Ordine→Ricezione. Coerente con la prassi degli ERP di riferimento (righe senza SKU sono sempre righe di spesa contabile, mai un movimento di magazzino) ed è la scelta corretta per un rivenditore di chimici dove ADR/lotti impongono anagrafica-first. Una futura riga "di spesa non-magazzino" (trasporto, servizi) è concettualmente diversa e resta backlog.

---

## 8. Riferimento — principio di immutabilità RFQ (testo originale)

Il testo seguente, verificato integralmente contro il codice ed è la formulazione di riferimento del principio descritto in §5.1, è riportato qui integralmente perché già corretto nella sua stesura originale.

> ## RFQ in stato Bozza
>
> Quando il record si trova nello stato Bozza, l'utente ha il controllo totale perché si tratta di un documento di lavoro interno non ancora condiviso con l'esterno.
>
> L'ERP si limita a fare da blocco note. L'utente può cambiare articoli, quantità, destinatari e prezzi senza che il sistema debba registrare uno storico delle modifiche o generare transazioni di controllo.
>
> ## RFQ in stato INVIATA
>
> Nel momento in cui l'utente invia la RFQ al fornitore e il record passa allo stato Inviata, la situazione cambia radicalmente perché l'azienda ha ufficialmente comunicato un'intenzione e una richiesta a una terza parte.
>
> Molti sistemi ERP limitano la modifica alle sole righe di prezzo. Il motivo è dovuto alla natura stessa della trattativa commerciale:
>
> -  L'utente ha chiesto al fornitore la quotazione per un elenco preciso di prodotti con quantità precise.
>
>   o  Quella specifica richiesta è ormai un atto ufficiale, "congelato" nel tempo.
>
>   o  Modificare le quantità in questa fase distruggerebbe la prova legale di ciò che era stato originariamente richiesto al fornitore, impedendo di misurare lo scostamento tra Richiesta e Offerta.
>
> Tuttavia, nella realtà operativa si verificano scostamenti frequenti. Il fornitore potrebbe applicare sconti di volume o imporre lotti minimi di acquisto. Anziché permettere all'utente di aggiornare e sovrascrivere le quantità nella RFQ (che sarebbe un gravissimo errore di progettazione e compliance), il sistema risolve questa deviazione nella fase successiva di Registrazione Offerta. L'utente registra le nuove quantità proposte (il lotto minimo) direttamente nella riga dell'Offerta Fornitore. Il sistema calcola lo scostamento tra la RFQ originale (immutabile) e l'Offerta (flessibile), garantendo così l'allineamento operativo senza mai bloccare l'utente o costringerlo a "salti mortali", e preservando al 100% l'integrità storica del database.

*(Nel codice reale la RFQ inviata è immutabile nella sua interezza, prezzi compresi — non "solo le quantità": la formulazione sopra, scritta prima della verifica sul codice, resta corretta nella sostanza del principio anche se il dettaglio "prezzi ancora editabili" citato altrove in documentazione più vecchia non lo è.)*
