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
    *   **Fallback Pricing**: Se non trova il prezzo nel listino del cliente (Level 1) o nel listino standard (Level 2), il backend calcola il prezzo applicando un ricarico fisso del **+20%** sul costo minimo registrato per quell'articolo nella tabella delle relazioni prodotto-fornitore (`prodotti_fornitori_prezzi`).
*   **Flusso Acquisti (RFQ & PO)**:
    *   **Assenza di Logica di Pricing**: Il backend degli acquisti non ha alcun Pricing Engine o endpoint API dedicato per calcolare i prezzi d'acquisto in base a listini o scaglioni.
    *   L'endpoint `/purchases/rfq/api/prodotti` restituisce semplicemente il prezzo unitario statico definito nella relazione anagrafica fornitore dell'articolo, senza applicare calcoli su scaglioni di quantità o conversioni di unità di misura.

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

## 2. Connessione e Gestione UoM nei Grandi ERP Best-in-Class

### SAP ERP (S/4HANA)
*   **Listini e Prezzi**: Utilizza la **Condition Technique** (procedura di calcolo con sequenze di accesso). I record di condizione sono memorizzati combinando chiavi specifiche (es. Cliente/Materiale/UoM).
*   **Gestione Unità di Misure (UoM)**: SAP gestisce tre tipi di UoM: *Base UoM* (stoccaggio), *Sales UoM* (vendita) e *Purchase UoM* (acquisto). Se un listino è definito in "Scatole da 12" ma l'ordine viene inserito in "Pezzi", il motore di pricing di SAP esegue automaticamente la conversione del prezzo unitario applicando il fattore di conversione definito nell'anagrafica materiale prima di esporlo a video.
*   **RFQ & PO**: I prezzi di acquisto sono gestiti negli **Info Records**. L'Info Record definisce l'unità di misura d'acquisto specifica del fornitore e le relative scale di sconti quantità per quella specifica UoM.

### Oracle NetSuite
*   **Listini e Prezzi**: I prezzi sono strutturati in **Price Levels** legati ai clienti.
*   **Gestione Unità di Misure (UoM)**: NetSuite utilizza gli **UoM Schedules** (tabelle di conversione unità). Ogni articolo è associato a un set di unità (es. *Pezzo, Scatola, Pallet*). Nei listini prezzi, l'utente può inserire tariffe specifiche per ciascuna unità di misura. In fase di compilazione del preventivo o dell'ordine, se l'utente cambia l'unità di misura della riga, NetSuite aggiorna istantaneamente il prezzo unitario suggerito pescando il valore associato a quella specifica UoM nel listino prezzi del cliente.

### Odoo
*   **Listini e Prezzi**: Gestisce i listini prezzi (**Pricelists**) configurabili con formule e regole a cascata.
*   **Gestione Unità di Misure (UoM)**: Odoo organizza le UoM in **UoM Categories** (es. Peso, Volume, Unità). La conversione tra UoM della stessa categoria è gestita da fattori moltiplicatori/divisori fissi. Nei listini di vendita o nelle Vendor Pricelists d'acquisto, il prezzo può essere espresso in un'UoM specifica. All'inserimento della riga, il sistema verifica l'UoM selezionata e, se differisce da quella del listino, calcola il prezzo proporzionato applicando il fattore di conversione in tempo reale.

### Microsoft Dynamics 365 Business Central
*   **Listini e Prezzi**: Utilizza i **Sales Price Lists** e **Purchase Price Lists** associabili a clienti, fornitori o campagne.
*   **Gestione Unità di Misure (UoM)**: Ogni articolo ha una *Base Unit of Measure*. È possibile definire unità alternative (es. *Confezione da 6 pz*) con i relativi codici e fattori di conversione. Nei listini prezzi (vendita e acquisto), le righe di prezzo sono esplicitamente legate al codice unità di misura. Se il motore di pricing non trova un prezzo specifico per l'UoM inserita sulla riga del documento, applica il prezzo dell'unità di misura base convertendolo in proporzione al fattore di conversione memorizzato sulla scheda dell'articolo.

---

## 3. Analisi delle Logiche di Connessione e UX/UI: Pregi, Difetti e Stato dell'Arte

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
*   **Level 1: Offerta RFQ Approvata**: Se l'ordine d'acquisto deriva da una RFQ con preventivo registrato, eredita i prezzi e gli sconti approvati.
*   **Level 2: Contratto d'Acquisto / Info Record Fornitore**: Prezzi e scaglioni negoziati specificamente con quel fornitore per il prodotto.
*   **Level 3: Listino d'Acquisto Standard Fornitore**: Listino prezzi generale del fornitore abilitato per l'acquisto.
*   **Level 4: Ultimo Costo Storico**: Prezzo d'acquisto dell'ultima transazione chiusa con lo stesso fornitore per lo stesso articolo.
*   **Level 5: Costo Base Prodotto**: Costo standard definito nella scheda anagrafica generale dell'articolo.
*   **Level 6: Manuale**: Precompila `0.00`.

### B. Gestione ed Architettura della Percentuale di Ricarico (Markup Fallback)
*   **Il Doppio Inserimento della % di Fallback**:
    1.  **Tabella Configurazioni di Sistema (Valore di Default Globale)**:
        *   **Dove nella UI**: Configurazione Modulo Vendite $\rightarrow$ Sezione *Pricing*.
        *   **Scopo**: Definisce la percentuale di markup di fallback standard per l'intero ERP (es. $+20\%$). Questo valore viene ereditato automaticamente all'atto della creazione di ogni nuovo prodotto.
        *   **UI/UX**: Un campo input numerico con tooltip esplicativo: *"Markup di Fallback Globale: Questa percentuale viene applicata di default al costo d'acquisto del prodotto per calcolarne il prezzo suggerito di vendita qualora l'articolo non sia configurato in alcun listino prezzi attivo."*
    2.  **Tabella Anagrafica Prodotto (Valore di Override sulla Scheda Articolo)**:
        *   **Dove nella UI**: Scheda Prodotto $\rightarrow$ Tab *Dati Prodotto* (sotto la sezione *Prezzo di Vendita*).
        *   **Scopo**: Consente di modificare a mano la percentuale di ricarico specifica per quell'articolo (es. impostando $+35\%$ invece del $+20\%$ globale) per riflettere le particolarità commerciali dell'articolo.
        *   **UI/UX**: Un campo input numerico pre-popolato con il valore globale ereditato dalle impostazioni, ma liberamente modificabile a mano. A fianco del campo viene inserito un testo parlante: *"Markup Fallback Prodotto: Percentuale di ricarico applicata al costo d'acquisto per determinare il prezzo di vendita consigliato in assenza di listini attivi per questo specifico articolo."*
*   **Logica del Pricing Engine**: Cerca la percentuale di markup inserita nella scheda prodotto. Se assente, cerca quella della categoria prodotto o in estrema ratio quella definita nelle impostazioni globali delle configurazioni di sistema. Esegue quindi il calcolo: `Prezzo = Costo * (1 + Markup / 100)`.

### C. Soluzioni Dettagliate di UX/UI

#### 1. Il Selettore del Listino di Testata
*   **Filtrazione per Fornitore (Acquisti)**: Quando si crea o modifica un'RFQ o un PO, non appena viene selezionato il fornitore, il selettore del listino in testata interroga il backend e mostra **esclusivamente i listini associati a quel fornitore** o i listini generali d'acquisto.
*   **Logica di Controllo**:
    *   Se l'anagrafica del fornitore/cliente ha **meno di 15 listini**, il selettore è una dropdown classica.
    *   Se ci sono **più di 15 listini**, si trasforma in un input di tipo **autocomplete** con ricerca dinamica asincrona basata su testo parziale.
*   **Preselezione / Default**:
    *   *Vendite*: Preseleziona automaticamente il listino collegato alla scheda cliente (`dettagli_clienti.listino_id`). Se vuoto, preseleziona il *Listino Pubblico* (quello marcato `is_default = 1` nella tabella `listini`).
    *   *Acquisti*: Preseleziona il listino preferenziale del fornitore o lascia il campo vuoto se non esistono accordi, avviando il calcolo basato su costi base.
*   **Avviso di Ricalcolo**: Se l'utente modifica manualmente il listino in testata del documento commerciale, il sistema mostra un modal di conferma: *"Desideri aggiornare i prezzi di tutte le righe in base al nuovo listino selezionato? (Nota: i prezzi modificati manualmente verranno ricalcolati)"*.

#### 2. Tracciabilità ed Override sulle Righe
*   **Badge Informativo (Traceability)**: Ogni riga del documento presenta un'icona informativa cliccabile accanto al prezzo unitario. Il backend popola un campo `prezzo_sorgente` che descrive l'algoritmo di calcolo in linguaggio naturale. Al passaggio del mouse, un tooltip mostra: *"Prezzo applicato: € 14,00 (Listino Grossisti 2026 - Scaglione >= 50 pz - Validità fino al 31/12/2026)"*.
*   **Rilevamento Override**: Ciascuna riga traccia lo stato del prezzo (`data-price-mode` = "automatic" | "manual"). Se l'operatore digita una cifra nell'input del prezzo, lo stato passa a "manual" e l'icona del badge si trasforma in una penna gialla.
*   **Pulsante di Reset**: Nello stato di override, compare un pulsante con freccia circolare a fianco del prezzo. Cliccandoci, lo stato torna ad "automatic" e viene inviata una chiamata API per ricaricare il prezzo suggerito dal listino in tempo reale.

---

## 5. Specifiche di Integrazione e Conversione Multi-UoM (dalla Specifica di Riferimento)

### A. I Pilastri della Gestione UoM e Prezzi
Come definito nel manuale architetturale di riferimento di Quack ERP:
1.  **Immutabilità Transazionale**: Quando un documento viene finalizzato, tutti i fattori di conversione, i prezzi unitari e le UoM attive in quel momento sono copiati (snapshottati) nelle righe del documento per isolare lo storico da variazioni anagrafiche successive.
2.  **Base UoM Unica per Prodotto**: Il magazzino gestisce saldi e lotti solo ed esclusivamente nell'unità base di stoccaggio. Qualsiasi movimento logistico in unità alternativa viene preventivamente convertito e registrato in Base UoM.
3.  **Listini in UoM Nativa**: I listini vendite, acquisti e RRP conservano il prezzo unitario legato all'UoM con cui sono stati negoziati commerciali (es. prezzo espresso a KG o a FUSTO), adattando il valore a video in base alle relazioni di conversione.

### B. Relazione Matematica ad Alta Precisione
La relazione tra l'unità commerciale e quella di stoccaggio è gestita tramite la formula:

$$\text{1 Unità Commerciale (Alt)} = \left(\frac{\text{Numeratore}}{\text{Denominatore}}\right) \times \text{Unità di Stock (Base)}$$

Il database transazionale (`sales_order_lines`, `preventivi_righe`, `ordini_righe`) deve memorizzare lo snapshot dei coefficienti di conversione attivi al salvataggio:
*   `num_factor_snapshot DECIMAL(18,9)`
*   `den_factor_snapshot DECIMAL(18,9)`
*   `quantita_stock DECIMAL(18,4)`
*   `system_unit_price_stock DECIMAL(18,4)`

### C. Matrice delle Eccezioni Multi-UoM

| Caso Limite / Eccezione | Comportamento del Sistema | Rationale Tecnico |
| :--- | :--- | :--- |
| **1. Cambio della Base UoM post-movimenti** | **Vietata.** Il campo viene disabilitato in UI e bloccato in Backend. | Se modificata, corromperebbe lo storico dei movimenti di magazzino e il costo medio ponderato. |
| **2. Cambio della Base UoM senza movimenti** | **Consentita.** Il sistema aggiorna i preventivi/ordini in stato "bozza" associati. | Poiché non ci sono transazioni fisiche a magazzino, è possibile allineare le bozze commerciali. |
| **3. UoM Commerciale non definita** | La UI consente solo la selezione della UoM di Stock. | Evita divisioni per zero o configurazioni invalide. |
| **4. Modifica del fattore di conversione ($F_{conv}$)** | I listini storici continuano a funzionare con il nuovo fattore per le nuove transazioni. I vecchi ordini salvati rimangono intatti. | Lo snapshot `quantita_contenuto_snapshot` sulle righe passate isola lo storico dalle modifiche anagrafiche future. |
| **5. Prezzo consigliato (RRP) multi-UoM** | Il venditore inserisce l'RRP specificando la UoM (es. `200 €/fusto` o `8 €/kg`). | Consente il confronto coerente tra prezzi consigliati dai fornitori e listini interni sia a peso che a pezzo. |

---

## 6. Piano di Allineamento e Implementazione del Codice

### A. Modifiche al Database
*   `ALTER TABLE listini_prezzi ADD COLUMN unita_misura_id INTEGER REFERENCES unita_misura(id)`
*   `ALTER TABLE prodotti_fornitori_prezzi ADD COLUMN unita_misura_id INTEGER REFERENCES unita_misura(id)`
*   `ALTER TABLE prodotti_fornitori ADD COLUMN rrp_unita_misura_id INTEGER REFERENCES unita_misura(id)`
*   Righe dei documenti aggiornate con `quantita_contenuto_snapshot DECIMAL(10,3)`, `quantita_stock DECIMAL(10,3)`, e `unita_misura_stock_id`.

### B. Logiche Backend PHP
1.  In `ProductsController.php` implementare il controllo dei movimenti di magazzino prima di consentire la modifica del campo `unita_misura_stock_id`.
2.  In `includes/sales_quotes.php` e negli altri helper transazionali, inserire il calcolo di `quantita_stock` e la scrittura dei campi snapshot all'atto dell'inserimento/modifica delle righe.

### C. Logiche Frontend JavaScript
1.  Migliorare `window.qkConvertUom` in `assets/js/app.js` per gestire le conversioni bidirezionali dei prezzi e delle quantità esposte in tabella in tempo reale durante il toggling della modalità UoM attiva.
2.  Integrare il selettore dell'UoM della riga per restringere la scelta solo alle unità abilitate per il prodotto.
