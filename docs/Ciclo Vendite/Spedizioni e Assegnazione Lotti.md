# Gestione Spedizioni e Assegnazione Lotti nel Ciclo Vendite

Questa guida descrive l'architettura tecnica, i casi d'uso e le linee guida di design per l'implementazione del flusso di spedizione merce e generazione dei Documenti di Trasporto (DDT) nel ciclo vendite attivo di Quack ERP.

---

## 1. Casi d'Uso Chiave (Use Cases)

Nel commercio chimico e distributivo, l'evasione di un ordine di vendita può assumere diverse forme operative. Il sistema deve supportare i seguenti scenari senza irrigidire il flusso di lavoro:

### A. Spedizione Completa Lineare
L'ordine viene evaso interamente in un'unica spedizione. 
* Ciascuna riga dell'ordine viene coperta per l'intera quantità ordinata.
* I lotti vengono assegnati automaticamente dal sistema basandosi sulla disponibilità di magazzino.

### B. Spedizione Parziale (Tranche Multiple)
Per motivi di disponibilità scorte o accordi con il cliente, l'ordine viene evaso in tempi diversi.
* L'operatore può indicare, per ogni riga, la quantità effettivamente pronta per la partenza.
* Lo stato dell'ordine transita automaticamente a `parzialmente_spedito`.
* Le spedizioni successive scaleranno le quantità residue attese, fino al completamento totale (stato `spedito`).

### C. Ripartizione Multi-Lotto (Split Lotto)
Se la quantità ordinata per una singola riga supera la giacenza di un singolo lotto fisico, l'operatore deve poter prelevare la merce da lotti differenti.
* Esempio: Ordinati 100 kg. Giacenza Lotto A = 60 kg, Lotto B = 40 kg.
* L'interfaccia deve consentire di inserire due o più righe di assegnazione lotto per la stessa riga d'ordine (es. `Lotto A: 60 kg` e `Lotto B: 40 kg`).

---

## 2. Modello Dati e Flusso Transazionale (Database)

Al momento del salvataggio di una spedizione, il database deve essere aggiornato garantendo l'integrità referenziale sotto un'unica transazione database (`BEGIN TRANSACTION`).

### A. Creazione della Spedizione (`spedizioni` e `spedizioni_righe`)
Viene generato un record nella tabella `spedizioni` (associato all'ID dell'ordine) e le relative righe dettagliate in `spedizioni_righe`. 
Ogni riga di spedizione memorizza obbligatoriamente:
* Il riferimento alla riga dell'ordine (`ordine_riga_id`).
* Il lotto prelevato (`lotto_id`).
* La quantità effettiva spedita.

### B. Generazione del DDT (`documenti_trasporto` e `documenti_trasporto_righe`)
In contemporanea alla spedizione, viene creato il Documento di Trasporto (DDT) per accompagnare fisicamente la merce:
* La testata del DDT denormalizza i dati di spedizione, causale, aspetto dei beni, porto e vettore.
* Le righe del DDT copiano le informazioni ADR denormalizzate relative ai lotti di prodotti chimici pericolosi (ONU, classe, gruppo di imballaggio) per consentire la stampa cartacea conforme alla normativa sui trasporti.

### C. Aggiornamento delle Giacenze a Magazzino
La movimentazione fisica comporta lo scarico immediato delle scorte:
1. **Lotti**: La quantità disponibile del lotto (`quantita_disponibile` in `lotti`) viene decrementata del valore spedito.
2. **Giacenze Generali**: 
   * `quantita_disponibile` del prodotto in `giacenze` viene decrementata.
   * `quantita_riservata` (precedentemente accantonata al momento dell'accettazione dell'ordine) viene decrementata in proporzione alla quantità spedita.
3. **Righe Ordine**: La colonna `quantita_spedita` di `ordini_clienti_righe` viene incrementata della quantità evasa.
4. **Stato dell'Ordine**: Se la quantità totale spedita per tutte le righe eguaglia o supera la quantità ordinata, lo stato dell'ordine diventa `spedito`.

---

## 3. Linee Guida UX (Stile Apple)

Per rendere l'operazione naturale e priva di attrito per l'utente, l'interfaccia deve seguire regole di design precise:

1. **Suggerimento Automatico Smart (Auto-allocation)**:
   * All'apertura della schermata di spedizione, il sistema pre-calcola l'assegnazione ottimale dei lotti applicando la logica **FEFO** (First Expired, First Out) per i prodotti che tracciano i lotti.
   * Se i lotti non hanno scadenza, si applica la logica **FIFO** (First In, First Out) basandosi sulla data di creazione del lotto.
   * L'utente deve solo premere "Conferma" se l'assegnazione automatica è corretta.

2. **Interfaccia Visiva Denza e Chiara**:
   * Utilizzo di un pannello laterale a scorrimento (Drawer) o di un modal a schermo intero con sfumature morbide e transazioni fluide (senza ricaricare la pagina).
   * Indicatori grafici di progresso per mostrare immediatamente quanta parte di ciascuna riga è già stata spedita in passato e quanta rimane da spedire.

3. **Gestione Errori Preventiva**:
   * Il sistema blocca il salvataggio se l'utente tenta di spedire una quantità superiore a quella disponibile nel lotto selezionato.
   * Evidenziazione visiva immediata (soft-red border e testo informativo) in caso di inserimento di quantità non congruenti.
