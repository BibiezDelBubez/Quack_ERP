# Revisione Documentazione: Ciclo Vendite

Come richiesto, ecco l'analisi puntuale e riga per riga di tutto il contenuto della cartella `C:\Users\michel.papetti\Documents\xampp\htdocs\Quack\docs\Ciclo Vendite`. (Nota: il prompt menzionava "Revisione ciclo acquisti" ma la cartella fornita è "Ciclo Vendite", pertanto l'analisi è concentrata sulle vendite).

Di seguito l'elenco dei documenti analizzati, i relativi stralci a garanzia della lettura integrale, i suggerimenti di collocazione, una proposta di riorganizzazione, e tutte le incongruenze e ripetizioni rilevate.

---

## 1. Analisi dei Documenti (Stralci e Collocazione)

### File: `Ciclo vendite da Preventivo a Spedizione e Fatturazione.md`
- **Capitolo 1: Moduli Logici e Nomenclatura Database**
  - *Stralcio:* "Nel database di Quack c'è una distinzione netta tra preventivi attivi (inviati ai clienti) e offerte passive (ricevute dai fornitori)"
  - *Dove dovrebbe andare:* In un documento introduttivo generale "Architettura del Ciclo Vendite".
- **Capitolo 2: Gestione Versioni e Revisioni Preventivi Cliente**
  - *Stralcio:* "Il preventivo originario passa allo stato Annullato (o Storico) con storico = 1. Il nuovo preventivo viene creato in stato Bozza"
  - *Dove dovrebbe andare:* Sezione "Gestione Preventivi e Offerte".
- **Capitolo 3: Da Preventivo a Ordine Cliente (Sales Order)**
  - *Stralcio:* "Copia le righe in ordini_clienti_righe mantenendo il riferimento preventivo_riga_id per fini statistici."
  - *Dove dovrebbe andare:* Sezione "Gestione Ordini Clienti".
- **Capitolo 4: Esecuzione dell'Ordine: Spedizioni e Assegnazione Lotti (Pick & Pack)**
  - *Stralcio:* "Il sistema supporta lo split multi-lotto: se servono 100 kg, l'operatore può prelevare 60 kg dal Lotto A e 40 kg dal Lotto B."
  - *Dove dovrebbe andare:* Va fuso con il documento dedicato `Spedizioni e Assegnazione Lotti.md` per evitare ridondanze.
- **Capitolo 5: Stato di Fatturazione Semplificato**
  - *Stralcio:* "Quack non obbliga l'utente ad emettere fatture elettroniche o compilare dettagli amministrativi complessi."
  - *Dove dovrebbe andare:* Sezione finale "Fatturazione e Chiusura Ciclo".

### File: `Guida alla Gestione dei Listini Vendita ERP.md`
- **Capitolo 1 & 2: Architettura e Logica**
  - *Stralcio:* "Listini generali (per tutti) e listini dedicati (per un solo cliente) NON sono oggetti diversi a livello di database."
  - *Dove dovrebbe andare:* Nuovo documento dedicato "Motore di Pricing e Listini".
- **Capitolo 3 & 4: Schema Database e Algoritmo**
  - *Stralcio:* "prezzo_base = regola_base.valore if regola_base else (prodotto.costo_medio_ponderato * 1.20)"
  - *Dove dovrebbe andare:* Sottosezione tecnica di "Motore di Pricing e Listini".
- **Capitolo 5 & 8: Frontend, UX e "Illusione Ottica"**
  - *Stralcio:* "L'utente ha l'illusione di salvare il prezzo sul prodotto, ma a DB i dati rimangono normalizzati, puliti e scalabili."
  - *Dove dovrebbe andare:* Sezione "UI/UX Linee Guida per il Pricing".
- **Capitolo 6 & 7: Caso Speciale Chimico e Fallback**
  - *Stralcio:* "Se un prodotto non è nel listino dedicato... Applica invece una Cascata di Fallback (Ereditarietà) strutturata in 4 livelli."
  - *Dove dovrebbe andare:* Accorpato in "Motore di Pricing e Listini".

### File: `Spedizioni e Assegnazione Lotti.md`
- **Capitolo 1: Casi d'Uso Chiave**
  - *Stralcio:* "L'interfaccia deve consentire di inserire due o più righe di assegnazione lotto per la stessa riga d'ordine (es. Lotto A: 60 kg e Lotto B: 40 kg)."
  - *Dove dovrebbe andare:* Documento "Logistica e Spedizioni: Lotti e DDT".
- **Capitolo 2: Modello Dati e Flusso Transazionale**
  - *Stralcio:* "Le righe del DDT copiano le informazioni ADR denormalizzate relative ai lotti di prodotti chimici pericolosi"
  - *Dove dovrebbe andare:* Documento "Logistica e Spedizioni: Lotti e DDT".
- **Capitolo 3: Linee Guida UX (Stile Apple)**
  - *Stralcio:* "Il sistema pre-calcola l'assegnazione ottimale dei lotti applicando la logica FEFO (First Expired, First Out)"
  - *Dove dovrebbe andare:* UI/UX Guidelines per le Spedizioni.

### File: `compliance-restrizioni-vendita.md`
- **Capitolo 1 & 2: Concetto e differenza con ADR**
  - *Stralcio:* "ADR classifica i prodotti per il trasporto... Non è pensato per regolare a chi si può vendere."
  - *Dove dovrebbe andare:* Documento "Compliance e Normative di Vendita".
- **Capitolo 3 & 4: Struttura DB e Flusso operativo**
  - *Stralcio:* "Se il cliente non ha un'autorizzazione valida → blocco/warning... Il check è ancora da implementare nel controller"
  - *Dove dovrebbe andare:* "Compliance e Normative di Vendita".

### File: `RRP_analisi_best_practice.md`
- **Capitolo 1: ERP Enterprise Comparison**
  - *Stralcio:* "Microsoft Dynamics 365 Finance & Operations... Margin alert nativo con semaforo a tre colori sulle righe ordine/preventivo"
  - *Dove dovrebbe andare:* Sottosezione "Analisi Competitor e Best Practice" (può essere un'appendice o un documento di design isolato).
- **Capitolo 2 & 3: Analisi DB Quack e Piano modifiche**
  - *Stralcio:* "La tabella prodotti_fornitori... contiene già: rrp_min DECIMAL(10,4)... rrp_max DECIMAL(10,4)"
  - *Dove dovrebbe andare:* Documento tecnico "Integrazione Costi e Prezzi Fornitore nelle Vendite".
- **Capitolo 4 & 5: Ciclo d'uso e Riepilogo**
  - *Stralcio:* "Il pricing manager apre il modal di inserimento prezzo per un prodotto. Vede il range consigliato dal fornitore come riferimento."
  - *Dove dovrebbe andare:* Accorpato alle specifiche del Preventivo e dei Listini.

### File: `indirizzi-contatti-nel-ciclo-vendite.md`
- **Tutto il documento (Problema, Pattern, DB, Backend, Frontend)**
  - *Stralcio:* "La regola che non cambia mai: la stampa (PDF, DDT, fattura) legge sempre i campi snapshot, mai la FK."
  - *Dove dovrebbe andare:* Questo è un documento architetturale trasversale. Andrebbe in "Core Architettura: Anagrafiche e Snapshot".

---

## 2. Proposta di Riorganizzazione dei File

Attualmente i file mischiano analisi di mercato, design UI, schema database e specifiche funzionali. Propongo la seguente struttura a cartelle/file:

1. **`01_Architettura_e_Flussi_Base.md`**
   - Nomenclatura e flusso da Preventivo a Fatturazione (da `Ciclo vendite da Preventivo a Spedizione e Fatturazione.md`).
   - Gestione degli Snapshot e Override (da `indirizzi-contatti-nel-ciclo-vendite.md`).
2. **`02_Pricing_e_Listini.md`**
   - Logica dei listini e fallback in 4 livelli (da `Guida alla Gestione dei Listini Vendita ERP.md`).
   - Gestione RRP e visibilità margini sul preventivo (da `RRP_analisi_best_practice.md`).
3. **`03_Spedizioni_Lotti_DDT.md`**
   - Fusione dei concetti di prelievo lotti, FEFO/FIFO, e generazione DDT (da `Spedizioni e Assegnazione Lotti.md` e capitolo 4 del doc base).
4. **`04_Compliance_e_Restrizioni.md`**
   - Gestione delle restrizioni di vendita e autorizzazioni clienti (da `compliance-restrizioni-vendita.md`).
5. **`Appendice_Analisi_Best_Practice.md`** (opzionale)
   - L'analisi dei competitor (Odoo, SAP, Oracle, MS Dynamics) attualmente sparsa tra RRP, Indirizzi e Listini.

---

## 3. Segnalazioni: Testo o Concetti Ripetuti (Overlap)

- **Assegnazione Lotti e Spedizioni**: 
  Il file `Ciclo vendite da Preventivo a Spedizione e Fatturazione.md` al Capitolo 4 riassume in poche righe lo split multi-lotto, l'ADR e l'aggiornamento giacenze. Questi identici concetti formano l'interezza del file `Spedizioni e Assegnazione Lotti.md`, creando una ripetizione ridondante.
- **Logica di Fallback Listini**:
  In `Guida alla Gestione dei Listini Vendita ERP.md`, l'algoritmo di calcolo (Capitolo 4) espone in pseudo-codice la ricerca del prezzo. Al Capitolo 7 ("La Cascata di Fallback") lo stesso esatto concetto viene rispiegato in forma testuale e discorsiva per 4 livelli.
- **Copia dei dati sul DDT**:
  Il concetto di copiare i dati al momento della spedizione per la stampa è ripetuto due volte: in `Spedizioni e Assegnazione Lotti.md` ("Le righe del DDT copiano le informazioni ADR denormalizzate") e in `indirizzi-contatti-nel-ciclo-vendite.md` in modo molto più approfondito ("Snapshot denormalizzato per stampa").

---

## 4. Segnalazioni: Contraddizioni e Incongruenze

- **Incongruenza sugli Stati del Preventivo / Ordine**:
  - `Ciclo vendite da Preventivo a Spedizione e Fatturazione.md` elenca gli stati: **Bozza, Inviato, Annullato (o Storico), Accettato**.
  - `indirizzi-contatti-nel-ciclo-vendite.md` elenca gli stati: **bozza, inviata, confermata, scaduta**.
  *(Nota: "Accettato" vs "confermata" e la presenza dello stato "scaduta" differiscono tra i due documenti).*
- **Contraddizione nel Modello Dati del Preventivo**:
  - `indirizzi-contatti-nel-ciclo-vendite.md` mostra l'aggiunta di numerosi campi (es. `cliente_ragione_sociale`, `contatto_nome`, ecc.) alla tabella `preventivi` come snapshot.
  - `Ciclo vendite da Preventivo a Spedizione e Fatturazione.md` non menziona nulla di questa struttura ibrida (FK + Snapshot), ma descrive un database standard.
- **Sovrapposizione e Incoerenza UI nel form Preventivo**:
  - `Guida alla Gestione dei Listini Vendita ERP.md` prevede per la riga del preventivo un'**icona tag (verde/giallo/grigio)** per mostrare l'origine del prezzo, e un pulsante **"Salva questo prezzo nel listino di ACME"**.
  - `RRP_analisi_best_practice.md` richiede di aggiungere le colonne **Costo**, **Range consigliato**, **Delta**, e i **badge RRP (Min/Max)**, e per i totali un box Margine €/%. 
  *(I due documenti propongono modifiche massive e affollate alla stessa riga d'ordine senza considerarsi a vicenda).*
- **Incongruenza tecnica sul Listino Standard**:
  - Nel database proposto (`Guida...Listini`), il flag `is_standard BOOLEAN DEFAULT FALSE` identifica il listino base. 
  - Nello pseudocodice (`calcola_prezzo_vendita`), l'ID è invece hardcoded: `ID_LISTINO_STANDARD = 1`, ignorando il flag del DB.
- **Visione del Prezzo di Listino Originale vs RRP**:
  - `Guida...Listini` consiglia di salvare sulle righe preventivo il `Prezzo di Listino Originale`, lo `Sconto Applicato` e il `Prezzo Finale`.
  - `RRP_analisi_best_practice` impone esplicitamente: *"La tabella preventivi_righe non deve salvare rrp_min/rrp_max... Salvarli creerebbe ridondanza senza utilità"*. Anche se RRP è di acquisto e il Listino è di vendita, l'approccio architetturale raccomandato sui dati di partenza è discorde (salvare il listino di partenza vs NON salvare l'RRP di partenza).
