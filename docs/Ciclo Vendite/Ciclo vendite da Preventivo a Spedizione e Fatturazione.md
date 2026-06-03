# Ciclo Vendite: da Preventivo a Spedizione e Fatturazione

Il ciclo vendite gestisce il flusso commerciale attivo dell'azienda: dalla quotazione al cliente (preventivo/offerta) all'acquisizione dell'ordine, alla preparazione della merce (con relativo scarico lotti), alla redazione del Documento di Trasporto (DDT) ed infine alla marcatura dello stato di fatturazione.

Si ispira alle best practice dei principali ERP internazionali (SAP, Odoo, Dynamics) adattate al commercio chimico e alla semplicità operativa.

---

## 1. Moduli Logici e Nomenclatura Database

Nel database di Quack c'è una distinzione netta tra preventivi attivi (inviati ai clienti) e offerte passive (ricevute dai fornitori):

* **Ciclo Vendite (Clienti)**:
  * **preventivi** (Testata del preventivo inviato al cliente, gestito tramite l'indirizzo `/offers` nel browser)
  * **preventivi_righe** (Righe del preventivo cliente)
  * **ordini_clienti** / **ordini_clienti_righe** (Gli ordini confermati dai clienti)
* **Ciclo Acquisti (Fornitori)**:
  * **offerte_testata** / **offerte_righe** (Le quotazioni ricevute dai fornitori in risposta alle RFQ)

---

## 2. Gestione Versioni e Revisioni Preventivi Cliente

Per tenere traccia dello storico delle trattative con il cliente senza violare il vincolo `UNIQUE` sul campo `numero_preventivo`, si adotta la strategia del **codice con suffisso di revisione**:

1. **Prima emissione (v1)**: Il preventivo viene registrato con il numero progressivo standard:
   * Esempio: `PREV/2026/0004`
2. **Creazione nuova versione**: Quando il preventivo è in stato "Inviato" e il cliente richiede modifiche:
   * L'utente clicca su **"Crea Nuova Versione"**.
   * Il preventivo originario passa allo stato **Annullato** (o **Storico**) con `storico = 1`.
   * Il nuovo preventivo viene creato in stato **Bozza**, con `storico = 0`.
   * Il numero del nuovo preventivo viene calcolato aggiungendo un suffisso incrementale:
     * Versione 2: `PREV/2026/0004-02`
     * Versione 3: `PREV/2026/0004-03`
3. **Vantaggio**: Questo metodo permette di confrontare le diverse versioni inviate allo stesso cliente mantendole tutte nel database ed è pienamente compatibile con il vincolo `UNIQUE` di SQLite.

---

## 3. Da Preventivo a Ordine Cliente (Sales Order)

Quando il cliente accetta un'offerta:
* L'utente clicca su **"Accetta e Crea Ordine"** o **"Conferma Ordine"** (termini commerciali adatti alle vendite, a differenza di "Aggiudica" usato per gli acquisti).
* Il sistema:
  1. Cambia lo stato del preventivo in **Accettato**.
  2. Crea un record in `ordini_clienti` (es. `ODC/2026/0001`).
  3. Copia le righe in `ordini_clienti_righe` mantenendo il riferimento `preventivo_riga_id` per fini statistici.
  4. Riserva le quantità a magazzino (incrementa `quantita_riservata` in `giacenze` per gli articoli coinvolti).

---

## 4. Esecuzione dell'Ordine: Spedizioni e Assegnazione Lotti (Pick & Pack)

Nel settore chimico, la spedizione della merce richiede la tracciabilità assoluta del lotto.

### **Assegnazione dei Lotti (`ordini_clienti_righe_lotti`)**
Al momento della preparazione della spedizione:
* L'operatore seleziona quali lotti fisici prelevare per coprire la quantità ordinata.
* Il sistema supporta lo **split multi-lotto**: se servono 100 kg, l'operatore può prelevare 60 kg dal *Lotto A* e 40 kg dal *Lotto B*.
* Il database memorizza questa associazione nella tabella `ordini_clienti_righe_lotti`.

### **Spedizione e DDT**
1. **Bolla di Spedizione (`spedizioni`)**: Registra l'avvenuto prelievo e la data di spedizione effettiva.
2. **Generazione DDT (`documenti_trasporto`)**:
   * Viene generato il DDT agganciato alla spedizione.
   * Le informazioni ADR (numero ONU, classe di pericolo, gruppo di imballaggio) vengono denormalizzate sulle righe del DDT per consentire stampe conformi alle normative sui trasporti di merci pericolose.
3. **Aggiornamento Giacenze**:
   * La merce esce dal magazzino:
     * `quantita_disponibile` dell'articolo si riduce.
     * `quantita_riservata` si riduce.
     * La giacenza specifica del `lotto` prelevato viene scaricata.

---

## 5. Stato di Fatturazione Semplificato

Poiché la fatturazione reale può essere gestita esternamente tramite software dedicati (SGA, Doc Finance), Quack non obbliga l'utente ad emettere fatture elettroniche o compilare dettagli amministrativi complessi. 

Al contrario, implementa una marcatura di stato semplice e visiva:
* **Stato dell'Ordine / DDT**: Un campo booleano o uno stato semplice (es. `Fatturato: Sì / No`, o `stato_fatturazione` tra `da_fatturare` e `fatturato`).
* Questo permette all'utente di contrassegnare l'ordine come "Fatturato" con un solo clic, garantendo la riconciliazione visiva senza costringere a fare data entry ridondante.
