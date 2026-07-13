# Studio di Analisi e Progettazione: Connessione Listini, Unità di Misura e Transazioni Commerciali (Quack ERP vs Stato dell'Arte 2026)

Questo documento costituisce l'analisi e la specifica di riferimento per la gestione dei listini prezzi, delle unità di misura (UoM), per la loro integrazione con le transazioni e per le migliori logiche e UX/UI da adottare in Quack ERP in base alle best practice dei sistemi enterprise di classe mondiale.

---

## 1. Connessione Attuale in Quack ERP (Listini & UoM nelle View)

### A. Livello Database (Schema Relazionale)
*   **Listini e Regole di Prezzo (`listini`, `listini_prezzi`)**:
    *   La tabella `listini` gestisce la testata del listino vendita (nome, valuta, date validità, flag attivo).
    *   La tabella delle righe `listini_prezzi` possiede la colonna `unita_misura_id` (chiave esterna verso `unita_misura`). Questo consente a livello DB di legare il prezzo di listino a una specifica unità di misura (es. prezzo per "secchio" o prezzo per "kg").
*   **Preventivi di Vendita (`preventivi_vendita`, `preventivi_righe`)**:
    *   La testata contiene `listino_id` per tracciare il listino di riferimento.
    *   Le righe (`preventivi_righe`) contengono sia la quantità commerciale che quella di stock, mappate tramite `quantita` (quantità inserita a video), `unita_misura` (codice testuale dell'UoM attiva a video) e `unita_misura_id` (chiave esterna). Il prezzo unitario finale e lo sconto sono memorizzati in modo denormalizzato nelle colonne `prezzo_unitario` e `sconto_perc`, congelando il valore economico.
*   **Ordini di Vendita (`ordini_clienti`, `ordini_clienti_righe`)**:
    *   La testata contiene `listino_id`.
    *   Le righe memorizzano analogamente `prezzo_unitario`, `sconto_perc`, `unita_misura` e `unita_misura_id`.
*   **Richieste d'Offerta (`richieste_offerta`, `richieste_offerta_righe` - RFQ)**:
    *   La testata contiene `listino_acquisto_id`.
    *   Le righe memorizzano `prezzo_indicativo`, `quantita_richiesta` e `unita_misura_id`. Non vi è alcuna colonna che tracci lo sconto sulle righe RFQ a livello DB.
*   **Ordini di Acquisto (`ordini_testata`, `ordini_righe` - PO)**:
    *   La testata contiene `listino_acquisto_id`.
    *   Le righe memorizzano in modo statico e congelato `prezzo_unitario`, `sconto_perc` e `unita_misura_id`.

### B. Livello Backend (PHP Controllers & API)
*   **Flusso Vendite (API `/sales/pricelists/api/ottieni-prezzo`)**:
    *   Il controller `SalesPricelistsController.php` gestisce la determinazione del prezzo suggerito.
    *   **Gestione delle UoM nel Pricing**: Il motore di pricing riceve la quantità e l'UoM richiesta. Quando interroga `listini_prezzi`, filtra per il prodotto e verifica se esiste un prezzo specifico per la combinazione esatta di prodotto e `unita_misura_id`.
    *   **Risoluzione delle Conversioni**: Si controlla se la riga di listino trovata definisce un prezzo per l'unità commerciale (es. Secchio) ma l'utente sta inserendo l'ordine in unità di stock (es. kg), il backend non esegue una conversione automatica del prezzo suggerito; restituisce semplicemente il record del listino così com'è, delegando al frontend l'onere di riproporzionare il valore in base al fattore di conversione del prodotto.
    *   **Fallback Pricing**: Se non trova il prezzo nel listino del cliente (Level 1) o nel listino standard (Level 2), il backend calcola il prezzo applicando un ricarico (markup) sul costo minimo registrato per quell'articolo nella tabella delle relazioni prodotto-fornitore (`prodotti_fornitori_prezzi`). **Correzione (verificata contro il codice reale il 10/07/2026)**: il ricarico **non** è un +20% fisso per tutti i prodotti. Il valore viene deciso, in ordine di priorità: (1) una percentuale specifica impostata a mano sulla scheda del singolo prodotto, se presente; (2) altrimenti una percentuale di default valida per tutto l'ERP, impostata una sola volta nelle Impostazioni generali; (3) solo se **nessuna delle due** è mai stata configurata, il sistema usa `+20%` come valore di emergenza puramente cautelativo, per non restituire mai un prezzo pari a zero. Il dettaglio di questa logica a due livelli è descritto più sotto in questo stesso documento, alla sezione "Gestione ed Architettura della Percentuale di Ricarico (Markup Fallback)" — quella sezione descrive esattamente ciò che è già implementato, non una proposta futura.
*   **Flusso Acquisti (RFQ & PO)**:
    > ⚠️ **Correzione (verificata contro il codice reale il 10/07/2026)**: i due punti sotto descrivevano una lacuna che nel frattempo è stata colmata. Il testo originale ("assenza di logica di pricing") è superato: lo si lascia visibile solo per motivi storici, seguito dalla descrizione dello stato attuale.
    *   ~~**Assenza di Logica di Pricing**: Il backend degli acquisti non ha alcun Pricing Engine o endpoint API dedicato per calcolare i prezzi d'acquisto in base a listini o scaglioni.~~ **Stato attuale**: il backend degli acquisti **ha** un motore di pricing dedicato. Il metodo `PurchasesController::getPurchasePrice()` cerca il prezzo più conveniente/pertinente scandagliando `prodotti_fornitori_prezzi` con una logica "a scaglioni": tra tutte le righe di prezzo disponibili per quel prodotto/fornitore, sceglie quella con la `quantita_minima` più alta che sia comunque minore o uguale alla quantità richiesta dall'utente (lo stesso principio degli "sconti quantità": più ne compri, più lo scaglione applicabile è conveniente). Cerca prima dentro un eventuale listino d'acquisto attivo per quel fornitore/data, poi come ripiego nei prezzi base del fornitore.
    *   ~~L'endpoint `/purchases/rfq/api/prodotti` restituisce semplicemente il prezzo unitario statico...~~ **Stato attuale**: l'endpoint reale è `/purchases/rfq/api/ottieni-prezzo` (metodo `PurchasesController::apiOttieniPrezzo`), che invoca il motore a scaglioni descritto sopra — non restituisce più un prezzo statico.
    *   **Unica differenza reale rimasta rispetto al ciclo Vendite**: la conversione di unità di misura sul prezzo di scaglione trovato (es. prezzo definito dal fornitore in KG ma riga inserita in FUSTI) non viene calcolata dentro `getPurchasePrice()` in PHP — resta calcolata lato JavaScript nel form (funzione `qkConvertUom`, richiamata da `assets/js/rfq-form.js`), esattamente come accade lato Vendite (vedi punto successivo "Risoluzione delle Conversioni").

### C. Livello Frontend (Javascript)
*   **Preventivi Vendita (`sales-quotes.js`)**:
    *   Il frontend ascolta le modifiche dell'input quantità (`.inp-qta`) e del prodotto. Quando cambiano, chiama `fetchQuotePrice(tr)` che esegue una chiamata GET a `/sales/pricelists/api/ottieni-prezzo` passando i dati di riga e testata.
    *   **Risoluzione delle Conversioni UoM**: Ricevuto il prezzo dall'API, il Javascript controlla l'unità di misura attiva visualizzata a schermo (selezionabile tramite il toggle globale `#sales-uom-mode` che alterna tra "commercial" e "stock"). Se l'UoM del prezzo espresso a listino differisce da quello visualizzato correntemente in tabella per quella riga, lo script applica le conversioni moltiplicando o dividendo per il fattore `quantita_contenuto` (`qFactor`) per visualizzare la tariffa proporzionata.
    *   Se l'operatore cambia il listino prezzi nel selettore di testata (`#quote-listino-id`), l'evento `change` avvia `refetchAllRowPrices()`, che ricalcola asincronamente tutte le righe del documento.
*   **Ordini di Vendita (`sales-orders-create.js`)**:
    *   All'inserimento del cliente, chiama `/sales/quotes/api/cliente-dettagli`. Legge il `listino_id` di default del cliente, lo imposta nel campo di testata e attiva le chiamate asincrone di pricing per popolare le righe.
*   **RFQ d'Acquisto (`rfq-form.js`)**:
    *   Non c'è integrazione asincrona. Se l'utente cambia il listino in testata (`#rfq-listino-acquisto-id`), non si attiva alcun ricalcolo automatico delle righe. La variazione delle quantità o l'inserimento dei prodotti non interroga alcun Pricing Engine, limitandosi ad usare il valore fisso dell'anagrafica fornitore iniziale.

### D. Livello UX e UI
*   **Preventivi Vendita**:
    *   A fianco del prezzo di riga è presente un badge visivo (`price-origin-badge`). In base alla risposta dell'API del backend (`data.origine`), assume forme e colori specifici:
        *   `dedicato`: icona tag (`fa-tag`) $\rightarrow$ Prezzo da listino cliente.
        *   `standard`: icona mondo (`fa-globe`) $\rightarrow$ Prezzo da listino generale.
        *   `costo`: icona allerta gialla (`fa-circle-exclamation`) $\rightarrow$ Ricarico +20% su costo fornitore.
        *   `manuale`: icona allerta rossa (`fa-triangle-exclamation`).
    *   **Override**: Se l'utente modifica manualmente il prezzo digitando nell'input, il badge cambia in una penna gialla (`fa-pen-to-square`) con tooltip *"Modificato manualmente"*.
*   **RFQ d'Acquisto**:
    *   La tabella non ha badge visivi. Non c'è alcun indicatore di provenienza del costo né alcuna segnalazione in caso di override manuale del prezzo inserito.

---

## 2. Analisi delle Logiche di Connessione e UX/UI: Pregi, Difetti e Stato dell'Arte

### Approccio A: Calcolo Continuo in Tempo Reale (Odoo / SAP)
Il sistema ricalcola il prezzo ad ogni variazione di quantità, UoM o dati di testata, a meno che la riga non sia stata esplicitamente bloccata in modalità manuale.

*   **Pregi**:
    *   **Accuratezza Massima**: Previene errori umani garantendo l'applicazione automatica degli scaglioni di prezzo corretti e delle conversioni UoM corrette.
    *   **Allineamento Commerciale**: Assicura la corretta applicazione di sconti o campagne a validità temporale in base alla data del documento.
*   **Difetti**:
    *   **Frustrazione degli Operatori**: Rischia di sovrascrivere prezzi personalizzati concordati al telefono se il sistema non rileva automaticamente l'override manuale.
    *   **Carico di Rete**: Aumenta il numero di richieste AJAX inviate al backend ad ogni interazione sulle righe.
*   **Stato dell'Arte UX/UI**:
    *   Il campo prezzo unitario deve essere modificabile. Se l'utente digita nel campo, la riga passa automaticamente allo stato "Override/Forzato" (es. tramite una flag `prezzo_manuale` a livello di riga), disattivando i ricalcoli automatici.
    *   Deve essere visibile un'icona di "Reset" a fianco del prezzo per consentire all'operatore di ripristinare il prezzo suggerito dal listino originale.

---

### Approccio B: Suggerimento Solo all'Inserimento
Il prezzo di listino viene recuperato e inserito esclusivamente quando il prodotto viene aggiunto alla riga. Successivamente, la riga viene scollegata dal listino prezzi.

*   **Pregi**:
    *   **Controllo Totale**: Previene sovrascritture accidentali da parte del sistema, offrendo una UX rassicurante all'operatore.
    *   **Semplicità Tecnica**: Non richiede la gestione di flag di stato complessi a livello di database o sessione.
*   **Difetti**:
    *   **Perdita di Marginalità**: Se le quantità aumentano sensibilmente, il sistema non applicherà mai gli sconti quantità o le tariffe agevolate in modo automatico.
    *   **Inconsistenza ai Cambi di Testata**: Se si cambia il listino in testata (es. da listino base a listino promozionale), i prezzi delle righe già inserite non si aggiornano, costringendo a ricompilare il documento da zero.
*   **Stato dell'Arte UX/UI**:
    *   Richiede la presenza di un pulsante esplicito in testata o nelle azioni massicce: *"Ricalcola prezzi in base al listino corrente"*, lasciando la decisione di aggiornare le righe all'operatore.

---

### Approccio C: Spiegazione dell'Origine del Prezzo (UX Tracciabile)
L'interfaccia non si limita a suggerire il prezzo, ma evidenzia graficamente l'origine e la regola di calcolo che lo ha determinato.

*   **Pregi**:
    *   **Trasparenza Totale**: Evita incertezze da parte degli operatori in merito alla correttezza delle tariffe proposte.
    *   **Debugging Semplificato**: Consente a chi gestisce i listini di verificare immediatamente se le date di validità o le regole di scaglione sono configurate in modo corretto.
*   **Difetti**:
    *   **UI Appesantita**: Rischia di occupare spazio prezioso nella griglia delle righe del documento.
    *   **Sviluppo Complesso**: Richiede che l'API di pricing restituisca al frontend solo il prezzo unitario, ma anche la stringa descrittiva della sorgente.
*   **Stato dell'Arte UX/UI**:
    *   Rappresenta lo standard di riferimento. Si implementa posizionando un'icona informativa discreta accanto al prezzo di riga. Al passaggio del mouse o al click, si apre un tooltip/popover che mostra la provenienza (es. *"Prezzo ricavato da: Listino Grossisti 2026 - Scaglione >= 100 pz"*).

---

## 4. Progettazione delle Logiche di Pricing e UX/UI per lo Stato dell'Arte 2026

Per definire la logica di pricing e la UX/UI ideale, analizziamo i punti chiave emersi e definiamo le linee guida architetturali.

### A. Fallback Pricing Hierarchy
Il Pricing Engine di backend deve operare secondo una cascata logica rigida per determinare il prezzo suggerito all'operatore in fase di compilazione del documento:

#### 1. Ciclo Vendite (Order-to-Cash)
*   **Level 1: Copia Transazione**: Se l'ordine di vendita viene generato a partire da un preventivo, i prezzi e gli sconti vengono ereditati e congelati, ignorando ricalcoli automatici.
*   **Level 2: Accordo Quadro / Contratto Cliente**: Condizioni contrattuali specifiche stipulate per il singolo cliente e valide per la data del documento.
*   **Level 3: Listino Dedicato Cliente**: Cerca nel listino prezzi associato all'anagrafica cliente, validando scaglioni e date.
*   **Level 4: Listino Categoria Cliente**: Cerca nei listini associati alla classe o categoria commerciale del cliente (es. *Listino Grossisti*).
*   **Level 5: Listino Standard / Pubblico**: Cerca nel listino generale predefinito (marcato `is_default = 1` nella tabella `listini`).
*   **Level 6: Ricarico su Costo (Cost-Plus)**: In assenza di listini attivi, legge il costo d'acquisto del fornitore primario e applica la percentuale di ricarico definita a livello globale o per categoria di prodotto.
*   **Level 7: Manuale**: Precompila `0.00` con badge rosso di errore, forzando l'inserimento manuale.

#### 2. Ciclo Acquisti (Procure-to-Pay)
*   **Level 1: Offerta RFQ Approvata**: Se l'ordine d'acquisto deriva da una RFQ con preventivo registrato, eredita i prezzi e gli sconti approvati. **Nota (verificata contro il codice reale il 10/07/2026)**: quando una RFQ riceve offerte da più fornitori, "scegliere il vincitore" e "generare l'ordine" non sono due fasi indipendenti che potrebbero divergere — sono la stessa azione. Accettare l'offerta di un fornitore (`qk_offerta_accetta()`) rifiuta automaticamente e nello stesso istante tutte le altre offerte sulla stessa RFQ; l'ordine d'acquisto si genera solo a partire dall'offerta accettata. Non può quindi mai accadere che l'ordine finisca a un fornitore diverso da quello selezionato come vincitore in RFQ.
*   **Level 2: Contratto d'Acquisto / Info Record Fornitore**: Prezzi e scaglioni negoziati specificamente con quel fornitore per il prodotto.
*   **Level 3: Listino d'Acquisto Standard Fornitore**: Listino prezzi generale del fornitore abilitato per l'acquisto.
*   **Level 4: Ultimo Costo Storico**: Prezzo d'acquisto dell'ultima transazione chiusa con lo stesso fornitore per lo stesso articolo.
*   **Level 5: Costo Base Prodotto**: Costo standard definito nella scheda anagrafica generale dell'articolo.
*   **Level 6: Manuale**: Precompila `0.00`.

### B. Gestione ed Architettura della Percentuale di Ricarico (Markup Fallback)

> ✅ **Aggiornamento di stato (verificato contro `SalesPricelistsController.php` il 10/07/2026)**: l'architettura descritta in questo paragrafo **è già implementata**, con due correzioni rispetto al testo originale (spiegate nei box "Correzione" sotto): (1) il valore **non** viene mai copiato/congelato nell'anagrafica del prodotto al momento della sua creazione — viene sempre ricalcolato "al volo" leggendo le impostazioni correnti ogni volta che serve un prezzo suggerito; (2) **non esiste** un livello intermedio di markup per "categoria prodotto" — solo i due livelli descritti sotto (prodotto, poi sistema).

*   **Il Doppio Inserimento della % di Fallback**:
    1.  **Tabella Configurazioni di Sistema (Valore di Default Globale)**:
        *   **Dove nella UI**: Configurazione Modulo Vendite $\rightarrow$ Sezione *Pricing*.
        *   **Scopo**: Definisce la percentuale di markup di fallback standard per l'intero ERP (es. $+20\%$).
        *   **Correzione**: questo valore **non** viene "ereditato automaticamente all'atto della creazione di ogni nuovo prodotto" (cioè non viene copiato una tantum dentro l'anagrafica del prodotto nuovo). Viene invece **letto in tempo reale dalle Impostazioni ogni volta** che il sistema deve calcolare un prezzo suggerito e il prodotto non ha un proprio markup specifico impostato. La differenza pratica: se domani il valore globale passa da +20% a +25%, **tutti** i prodotti che non hanno un proprio markup specifico applicano subito il nuovo +25%, senza bisogno di aggiornare nulla prodotto per prodotto. Se invece il valore fosse stato "copiato alla creazione", cambiare il default globale non avrebbe avuto alcun effetto sui prodotti già esistenti, ed è per questo che il sistema è stato costruito per leggerlo dinamicamente: è la scelta più sicura, perché evita che il valore visualizzato per un prodotto vecchio resti silenziosamente disallineato dal valore che oggi l'azienda considera corretto.
        *   **UI/UX**: Un campo input numerico con tooltip esplicativo: *"Markup di Fallback Globale: Questa percentuale viene applicata di default al costo d'acquisto del prodotto per calcolarne il prezzo suggerito di vendita qualora l'articolo non sia configurato in alcun listino prezzi attivo."*
    2.  **Tabella Anagrafica Prodotto (Valore di Override sulla Scheda Articolo)**:
        *   **Dove nella UI**: Scheda Prodotto $\rightarrow$ Tab *Dati Prodotto* (sotto la sezione *Prezzo di Vendita*).
        *   **Scopo**: Consente di modificare a mano la percentuale di ricarico specifica per quell'articolo (es. impostando $+35\%$ invece del $+20\%$ globale) per riflettere le particolarità commerciali dell'articolo. Se questo campo è vuoto (nessun valore inserito per quel prodotto), il sistema passa a cercare il valore globale del punto 1.
        *   **UI/UX**: Un campo input numerico, lasciato vuoto di default (non pre-popolato con il valore globale, per poter distinguere "prodotto senza override, usa il globale" da "prodotto con override esplicito uguale al globale"), ma liberamente compilabile a mano. A fianco del campo viene inserito un testo parlante: *"Markup Fallback Prodotto: Percentuale di ricarico applicata al costo d'acquisto per determinare il prezzo di vendita consigliato in assenza di listini attivi per questo specifico articolo. Se lasciato vuoto, si applica il markup di default definito nelle impostazioni generali."*
*   **Logica del Pricing Engine (i 3 passi effettivi, in ordine)**:
    1.  Cerca la percentuale di markup inserita direttamente sulla scheda del prodotto specifico. Se presente (anche se il valore è `0`, cioè "vendi a prezzo di costo"), la usa e si ferma qui.
    2.  Se sul prodotto non è impostato nulla, cerca il valore di default definito nelle impostazioni generali di sistema. Se presente, la usa e si ferma qui.
    3.  Se anche il valore di sistema è assente (caso raro: nessuno ha mai configurato nulla, né a livello di prodotto né a livello globale), usa `+20%` come valore di emergenza puramente cautelativo, per evitare di proporre comunque un prezzo pari al solo costo (margine zero) o pari a zero.
    
    **Non esiste un passo intermedio "cerca il markup della categoria prodotto"**: quel livello, presente in una versione precedente di questo paragrafo, non è mai stato implementato e va considerato eliminato dalla progettazione.
    
    Formula di calcolo, identica in tutti e 3 i casi: `Prezzo di vendita suggerito = Costo d'acquisto × (1 + Markup / 100)`.

    **Nota esplicita, da non rimuovere in futuri refactoring (verificata 10/07/2026)**: `0` sul markup di prodotto è un valore **valido e distinto** da "non impostato" — significa "vendi questo prodotto esattamente al costo, senza ricarico", una scelta commerciale legittima (es. articolo civetta o di fine serie). Il codice (`SalesPricelistsController.php:760`) lo riconosce correttamente confrontando `!== null && !== ''`, non con un controllo di verità generico (`if ($markup)`) che tratterebbe erroneamente `0` come "assente" e farebbe scivolare il calcolo al livello successivo (markup globale), sovrascrivendo una scelta esplicita dell'operatore. Chi tocca questa logica in futuro deve preservare questa distinzione.

### C. Soluzioni Dettagliate di UX/UI

#### 1. Il Selettore del Listino di Testata
*   **Filtrazione per Fornitore (Acquisti)**: Quando si crea o modifica un'RFQ o un PO, non appena viene selezionato il fornitore, il selettore del listino in testata interroga il backend e mostra **esclusivamente i listini associati a quel fornitore** o i listini generali d'acquisto.
*   **Logica di Controllo (corretta il 10/07/2026 dopo verifica contro il codice reale)**: la soglia numerica descritta in precedenza (dropdown sotto i 15 listini, autocomplete sopra) **non è mai stata implementata**, ed è stata sostituita in fase di sviluppo da una scelta più semplice: il selettore del listino in testata è **sempre** un campo di ricerca autocomplete (`app/views/sales/quotes/_form.php:234`, commento nel codice: *"Sempre autocomplete come richiesto"*), indipendentemente da quanti listini esistono. Questa scelta evita anche il problema di "buco a esattamente 15" che la soglia numerica avrebbe creato (nessuno dei due confini, "meno di" o "più di", copre il caso esatto) — ed è quindi preferibile, non solo più semplice.
*   **Preselezione / Default**:
    *   *Vendite*: Preseleziona automaticamente il listino collegato alla scheda cliente (`dettagli_clienti.listino_id`). Se vuoto, preseleziona il *Listino Pubblico* (quello marcato `is_default = 1` nella tabella `listini`).
    *   *Acquisti*: Preseleziona il listino preferenziale del fornitore o lascia il campo vuoto se non esistono accordi, avviando il calcolo basato su costi base.
*   **Avviso di Ricalcolo**: Se l'utente modifica manualmente il listino in testata del documento commerciale, il sistema mostra un modal di conferma: *"Desideri aggiornare i prezzi di tutte le righe in base al nuovo listino selezionato? (Nota: i prezzi modificati manualmente verranno ricalcolati)"*.

#### 2. Tracciabilità ed Override sulle Righe
*   **Badge Informativo (Traceability)**: Ogni riga del documento presenta un'icona informativa cliccabile accanto al prezzo unitario. Il backend popola un campo `prezzo_sorgente` che descrive l'algoritmo di calcolo in linguaggio naturale. Al passaggio del mouse, un tooltip mostra: *"Prezzo applicato: € 14,00 (Listino Grossisti 2026 - Scaglione >= 50 pz - Validità fino al 31/12/2026)"*.
*   **Rilevamento Override**: Ciascuna riga traccia lo stato del prezzo (`data-price-mode` = "automatic" | "manual"). Se l'operatore digita una cifra nell'input del prezzo, lo stato passa a "manual" e l'icona del badge si trasforma in una penna gialla.
*   **Pulsante di Reset**: Nello stato di override, compare un pulsante con freccia circolare a fianco del prezzo. Cliccandoci, lo stato torna ad "automatic" e viene inviata una chiamata API per ricaricare il prezzo suggerito dal listino in tempo reale.
*   **Override manuale e cambio di visualizzazione UoM (chiarito il 10/07/2026)**: sono due operazioni distinte, e vanno tenute separate. Se l'operatore cambia il toggle globale stock/commerciale di una riga il cui prezzo è in stato "manual", il prezzo digitato **viene comunque convertito proporzionalmente** insieme alla quantità (funzione `setRowUomMode()` in `assets/js/sales-quotes.js`, che non fa distinzione tra righe automatiche e manuali) — perché cambiare l'unità di visualizzazione non equivale a proporre un nuovo prezzo: è lo stesso numero espresso in un'altra unità, un'operazione puramente matematica. Il blocco dello stato "manual" impedisce solo che il **server** sovrascriva il prezzo con una nuova proposta (es. per un cambio di quantità o di prodotto), non la conversione di visualizzazione tra stock e commerciale.

---

> Le sezioni "Specifiche di Integrazione Multi-UoM" e "Piano di Allineamento" sono state rimosse da questo file perché incorporate nella Versione Definitiva (PARTE 3 e Sezione 8). Fare riferimento a quella come unica fonte.
