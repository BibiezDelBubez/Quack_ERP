# Guida al Ciclo Acquisti v2 (SAP-Inspired)

Questa guida illustra il funzionamento del ciclo acquisti ristrutturato, che gestisce il flusso dall'indagine iniziale (Richiesta di Preventivo - RFQ) fino all'effettiva ricezione della merce a magazzino. Il flusso si ispira al modello SAP ed è strutturato per consentire il tracciamento dettagliato di ogni fase, incluse le revisioni dei preventivi e le consegne parziali/frazionate.

---

## 1. Flusso di Lavoro (Lato Utente)

Il ciclo si sviluppa attraverso quattro fasi principali:

### Fase A: Richiesta di Preventivo (RFQ - Request For Quotation)

1. **Creazione**: L'utente inserisce una nuova RFQ specificando il fornitore, le date desiderate e gli articoli richiesti con le relative quantità.
2. **Stampa & Invio (Workflow Offline)**: Poiché l'applicazione non invia e-mail direttamente per motivi di sicurezza ed infrastruttura locale:
   - L'utente genera il documento PDF ufficiale dell'RFQ.
   - L'utente copia negli appunti del proprio computer un testo pre-compilato (con i dettagli dell'indagine ed il riferimento del documento).
   - L'utente può quindi incollare questo testo nel proprio client di posta aziendale esterna.
3. **Invio e Blocco**: Quando l'RFQ viene segnata come "Inviata", lo stato passa a *Inviata* e il documento viene bloccato per prevenire modifiche accidentali.
4. **Revisione / Nuova Versione**: Se il fornitore richiede modifiche o emergono nuove necessità prima di ricevere l'offerta finale, l'utente può cliccare su **"Nuova Versione"**. Questo:
   - Duplica l'RFQ corrente in una nuova scheda.
   - Mantiene un collegamento storico (l'RFQ originaria viene segnata come *Revisionata*).
   - Genera un nuovo numero di documento univoco incrementale.

### Fase B: Offerte Fornitore (Offerte)

1. **Registrazione Offerta**: Alla ricezione del preventivo da parte del fornitore, l'utente registra l'offerta collegandola all'RFQ di partenza. Il sistema pre-compila gli articoli e le quantità originariamente richieste.
2. **Inserimento Prezzi e Date**: L'utente inserisce i prezzi unitari, eventuali sconti, aliquote IVA e la data di consegna prevista formulata dal fornitore.
3. **Valutazione**: L'offerta rimane nello stato *In valutazione*. Se lo stabilimento riceve offerte da più fornitori per la stessa RFQ, le registra separatamente.
4. **Accettazione**: Quando un'offerta viene accettata:
   - Lo stato dell'offerta passa ad *Accettata*.
   - Eventuali altre offerte concorrenti collegate alla stessa RFQ vengono automaticamente impostate su *Rifiutata*.
   - L'RFQ di partenza viene automaticamente chiusa (*Chiusa*).
   - Viene abilitata la creazione immediata dell'Ordine di Acquisto.

### Fase C: Ordine di Acquisto (ODA)

1. **Generazione**: L'accettazione dell'offerta genera automaticamente l'Ordine di Acquisto cristallizzando i prezzi, le quantità concordate e la data di consegna pianificata iniziale.
2. **Approvazione**: L'ordine nasce nello stato di *Bozza* o *In approvazione*. Una volta autorizzato, passa a *Approvato*.
3. **Invio al Fornitore**: Quando l'ordine viene notificato ufficialmente al fornitore, viene segnato come *Inviato*. Da questo momento:
   - La merce viene conteggiata come "In Arrivo" nel calcolo delle giacenze di magazzino.
   - Non è più possibile modificare prezzi o quantità dell'ordine, ma solo note testuali o riferimenti logistici.

### Fase D: Ricezione Merce (Goods Receipt)

1. **Apertura Ricezione**: All'arrivo della merce in stabilimento, l'utente apre una nuova "Ricezione Merce" a partire dall'Ordine di Acquisto. Il sistema propone automaticamente le quantità residue da ricevere.
2. **Controllo Qualità & Lotti**:
   - L'utente verifica la merce e indica la quantità effettivamente accettata e quella eventualmente rifiutata (specificandone il motivo, es. "Merce danneggiata").
   - Se il prodotto richiede la tracciabilità per lotti, l'utente seleziona un lotto esistente o inserisce i dati di un nuovo lotto (codice lotto fornitore, codice interno, data di scadenza e numero DDT).
3. **Conferma e Auto-Split**:
   - Al momento della conferma, le quantità accettate entrano a far parte della giacenza fisica disponibile in magazzino e vengono rimosse dalla quantità "In Arrivo".
   - **Gestione Consegne Parziali**: Se il fornitore consegna meno di quanto pianificato per una tranche, il sistema aggiorna la tranche corrente come parziale e mantiene l'ordine in stato *Parzialmente Ricevuto*. Se vengono consegnati articoli in esubero (nei limiti della tolleranza ammessa), il sistema crea uno split automatico di tracciamento contrassegnandolo come completato.
   - Quando tutti gli articoli sono stati interamente ricevuti, l'Ordine di Acquisto passa nello stato di *Completato*.

---

## 2. Gestione delle Eccezioni Operative

Il sistema è strutturato per gestire nativamente le seguenti eccezioni per rispecchiare la realtà aziendale ed industriale:

### Eccezione A: Il fornitore propone quantità diverse (es. lotto minimo)

* **Lato Utente**: Nella riga dell'offerta, l'utente inserisce la quantità reale offerta dal fornitore (es. richiesta 100, ma il lotto minimo di vendita è 120).
* **Lato Sistema**: L'offerta accetta la quantità proposta. Nella schermata di confronto offerte viene mostrato un avviso visivo di scostamento (+20%) e il totale del preventivo viene ricalcolato su 120 pezzi.

### Eccezione B: L'offerta è completamente diversa da quanto chiesto (Offerta Spontanea)

* **Scenario Rifiuto**: Se l'offerta spontanea non è di interesse, l'utente la registra indicando il motivo del rifiuto obbligatorio (es. "Fuori budget"), salvandola in stato *Rifiutata* per alimentare le statistiche di performance del fornitore.
* **Scenario Accettazione**: L'utente accede alla sezione "Offerte" e clicca su **"Nuova Offerta Spontanea"**, inserendo gli articoli ed i prezzi lasciando vuoto il riferimento RFQ. Una volta approvata, può generare direttamente un Ordine d'Acquisto.

### Eccezione C: Il fornitore propone un prodotto sostitutivo non presente in anagrafica

* **Funzionamento**: Nella riga dell'offerta, l'utente spunta il checkbox **"Proponi Sostituto"** e compila i dettagli dell'articolo sostitutivo.
* **Lato Sistema**:
  - **Opzione A (Nuova Variante Fornitore)**: Associa l'anagrafica esistente ad un nuovo codice articolo fornitore nella tabella di corrispondenza `prodotti_fornitori`.
  - **Opzione B (Nuovo Articolo)**: Esegue un inserimento transato che crea il nuovo articolo in `prodotti`, crea il legame in `prodotti_fornitori` ed aggiorna la riga dell'offerta per puntare al nuovo ID prodotto. Questo evita di bloccare il workflow d'acquisto mantenendo la pulizia dei dati.

### Eccezione D: Consegne scaglionate sull'offerta (Split di riga per date diverse)

* **Lato Utente**: L'utente clicca su **"Sdoppia Riga"** nella riga dell'offerta del fornitore.
* **Lato Sistema**: Il sistema crea un record figlio nella tabella `offerte_righe` collegato alla stessa riga RFQ di origine (e puntante alla riga offerta padre). L'utente può così impostare quantità e date di consegna diverse per ciascuna tranche. In fase di generazione dell'ordine, verranno create righe o schedulazioni distinte in `ordini_righe_pianificazione`.

### Eccezione E: Consegna scaglionata non pianificata (Arrivo parziale imprevisto)

* **Lato Utente**: Se un fornitore invia una quantità inferiore rispetto a quella pianificata in un'unica data (es. arrivano 60 kg invece di 100 kg), il magazziniere modifica la quantità direttamente sul form di ricezione ed inserisce il DDT.
* **Lato Sistema (Auto-Split)**:
  - Salva la ricezione effettiva dei 60 kg e aggiorna lo stock.
  - Modifica la riga di pianificazione originale in `ordini_righe_pianificazione` riducendo la quantità prevista a 60 kg e impostando lo stato su *Completata*.
  - Crea automaticamente un nuovo record di pianificazione per il residuo di 40 kg in stato *In Attesa*, consentendo all'ufficio acquisti di riprogrammarla ed evitando disallineamenti contabili.

---

## 3. Flusso Logico del Backend (Database e Servizi)

Il database utilizza 9 tabelle correlate per garantire la massima granularità e coerenza transazionale:

1. **`rfq_testata` & `rfq_righe`**: Contengono l'anagrafica e le righe della richiesta di offerta. Memorizzano il legame gerarchico (`rfq_padre_id`) per gestire lo storico delle versioni revisionate.
2. **`offerte_testata` & `offerte_righe`**: Contengono le risposte dei fornitori. Ogni riga offerta fa riferimento alla riga RFQ corrispondente (`rfq_riga_id`) per tracciare le differenze tra richiesta e offerta.
3. **`ordini_testata` & `ordini_righe`**: Contengono i contratti di acquisto definitivi con i prezzi bloccati. Ciascuna riga fa riferimento alla riga dell'offerta accettata.
4. **`ordini_righe_pianificazione`**: Gestisce le date e le quantità previste per la consegna. Supporta più tranche di consegna per una singola riga d'ordine e traccia l'avanzamento reale.
5. **`ricezioni_testata` & `ricezioni_righe`**: Registrano le bolle di entrata merce (DDT fornitore) collegate all'ordine d'acquisto di riferimento.

### Regole di Integrità e Transazioni:

- Ogni operazione di modifica dello stato (accettazione offerta, invio ordine, conferma ricezione) viene eseguita all'interno di una transazione database (`BEGIN TRANSACTION` / `COMMIT`). In caso di errore in qualsiasi punto del flusso, viene effettuato il rollback completo per evitare dati orfani o disallineati.
- Il calcolo dei totali (imponibile, IVA, totale documento) viene effettuato lato server durante il salvataggio per evitare discrepanze dovute ad arrotondamenti lato client.
- L'aggiornamento dello stock interagisce direttamente con la tabella `giacenze` incrementando la giacenza fisica e decrementando la giacenza in arrivo in base alle conferme di ricezione effettive.

---

## 4. Flusso del Frontend (Interfaccia Utente)

- **Stati Bloccati**: Quando un documento (RFQ, Offerta o Ordine) raggiunge uno stato finale o avanzato (es. RFQ *Inviata*, Ordine *Inviato*), i relativi moduli di inserimento disabilitano automaticamente i campi di input o nascondono i pulsanti di modifica, visualizzando i dati in modalità di sola lettura.
- **Navigazione Fluida**: In ogni scheda di dettaglio sono presenti scorciatoie rapide per navigare verso i documenti correlati (es. dall'Ordine di Acquisto è possibile visualizzare l'offerta di origine e l'elenco delle ricezioni collegate; dalla Ricezione è possibile tornare istantaneamente all'ordine padre).
- **Interfaccia di Ricezione Intuitiva**: Il form di ricezione merce offre un layout chiaro in cui per ogni riga d'ordine l'utente può inserire rapidamente le quantità contate. In caso di prodotti con tracciabilità lotti attiva, l'interfaccia mostra dinamicamente i campi aggiuntivi per la registrazione del lotto, validandoli prima dell'invio del modulo.
