# Gestione dell'Indirizzo di Consegna nei Preventivi Spontanei Fornitore
**Analisi dei grandi ERP (SAP S/4HANA, Microsoft Dynamics 365, Oracle NetSuite, Odoo) e raccomandazioni per Quack.**

---

## 1. Cosa fanno i grandi ERP (Best-in-Class)?

Nei sistemi ERP professionali, quando si registra un **preventivo spontaneo** (unsolicited quotation, ovvero un preventivo inserito direttamente a sistema senza una precedente Richiesta di Offerta / RFQ o Richiesta d'Acquisto):

### **SÌ, è sempre previsto l'inserimento o la selezione di un indirizzo di consegna (Destinazione).**

Tuttavia, non viene inserito come "indirizzo a testo libero", ma è gestito tramite la selezione di entità anagrafiche interne dell'azienda (chiamate stabilimenti, magazzini o sedi logistiche):

*   **SAP S/4HANA (Purchasing/Sourcing):**
    Ogni riga di offerta (o la testata del documento) richiede l'associazione a un **Plant (Stabilimento)** o **Storage Location (Magazzino)**. Questo stabilimento ha un indirizzo di consegna predefinito associato nell'anagrafica di sistema.
*   **Microsoft Dynamics 365 Supply Chain:**
    Il preventivo richiede la selezione di un **Site** (Sito produttivo) e di un **Warehouse** (Magazzino) di destinazione. L'indirizzo fisico di spedizione viene ereditato direttamente da questa selezione.
*   **Oracle NetSuite:**
    Nei preventivi/ordini di acquisto spontanei, viene specificata la **Location** (Magazzino/Sede) di destinazione, che determina l'indirizzo di spedizione (shipping address) stampato sul documento.

---

## 2. Perché gli ERP richiedono l'indirizzo anche sul Preventivo?

1.  **Impatto Commerciale e Tariffe (Incoterms):**
    Il prezzo offerto da un fornitore è spesso legato alla destinazione finale della merce. Ad esempio, consegnare a Milano ha costi e condizioni logistiche (es. trasporto franco destino o franco partenza) diversi rispetto a consegnare a Palermo o all'estero.
2.  **Calcolo della Territorialità IVA e Dazi:**
    La destinazione geografica della merce determina le regole di tassazione, l'applicabilità dell'IVA o la necessità di gestire dazi doganali.
3.  **Tracciabilità e Flusso PO (Purchase Order):**
    Essendo il preventivo convertibile direttamente in Ordine d'Acquisto, avere la destinazione definita fin dall'inizio permette di generare l'Ordine con dati completi senza costringere l'operatore a reinserirli in un second tempo.

---

## 3. Stato attuale in Quack e Raccomandazioni

Attualmente, la tabella `offerte_testata` di Quack **non dispone di colonne per salvare l'indirizzo di consegna**. 

*   **Nel caso di Preventivo da RFQ:** L'ordine d'acquisto finale eredita l'indirizzo della RFQ a monte.
*   **Nel caso di Preventivo Spontaneo:** Non essendoci una RFQ, l'ordine d'acquisto viene generato senza un indirizzo di spedizione predefinito e l'utente dovrà impostarlo direttamente nella maschera dell'Ordine (dove i campi sono editabili).

### **Raccomandazione di Implementazione per Quack:**

Per allineare Quack agli ERP best-in-class, si consiglia la seguente evoluzione in due fasi:

*   **Fase 1 (Stato Attuale - Semplificato):**
    Poiché i preventivi spontanei non memorizzano l'indirizzo, l'utente completerà la parte commerciale (articoli e prezzi) nell'Offerta. Quando l'offerta viene accettata e convertita in Ordine d'Acquisto (`ordini_testata`), l'utente modificherà/inserirà l'indirizzo di consegna desiderato direttamente nell'Ordine, dove i campi di spedizione sono completamente editabili.
*   **Fase 2 (Evoluzione Future-Proof):**
    Qualora si desiderasse una precisione assoluta fin dalla fase di preventivo, sarà necessario:
    1. Aggiungere le colonne `consegna_via`, `consegna_civico`, ecc. alla tabella `offerte_testata` tramite una migrazione DB.
    2. Abilitare il pannello di selezione dell'indirizzo (analogo a quello della RFQ) anche sulla creazione/modifica dell'Offerta per i soli preventivi spontanei.
