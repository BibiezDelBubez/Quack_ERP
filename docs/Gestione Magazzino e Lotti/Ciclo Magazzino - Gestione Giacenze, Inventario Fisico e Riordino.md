# Ciclo Magazzino: Gestione Giacenze, Inventario Fisico e Riordino

# Tabelle chiave

Per garantire il tracciamento, la separazione dei compiti e lo storico dei flussi, il database divide le entità logiche del magazzino in tre aree: le Giacenze correnti (quantità), lo Storico dei Movimenti (carichi, scarichi, rettifiche) e le Sessioni di Inventario Fisico (rilevamenti periodici).

### **MODULO 1: Giacenze (Current Stock Levels)**

*La situazione delle giacenze in tempo reale per ciascun prodotto.*

* **giacenze**  
  * `id` (Chiave Primaria)  
  * `prodotto_id` (Chiave Esterna su `prodotti`)  
  * `quantita_disponibile` (Quantità fisica a magazzino libera da impegni, pronta per la vendita)  
  * `quantita_riservata` (Quantità impegnata per ordini clienti confermati ma non ancora spediti)  
  * `quantita_in_arrivo` (Quantità in arrivo da ordini d'acquisto inviati ma non ancora ricevuti)  
  * `scorta_minima` (Soglia sotto la quale scatta l'allerta di sottoscorta per il riordino)  
  * `updated_at` (Timestamp dell'ultimo aggiornamento della riga)

### **MODULO 2: Movimenti Magazzino (Inventory Movements)**

*Il giornale di magazzino. Chi, cosa, quando e perché ha spostato della merce.*

* **movimenti_magazzino**  
  * `id` (Chiave Primaria)  
  * `prodotto_id` (Chiave Esterna su `prodotti`)  
  * `lotto_id` (Chiave Esterna su `lotti`, NULL se l'articolo non gestisce la tracciabilità lotti)  
  * `tipo_movimento` (Valori: `carico`, `scarico`, `reso_cliente`, `reso_fornitore`, `rettifica`, `riserva`, `svincolo`)  
  * `quantita` (Quantità movimentata, sempre salvata come valore positivo)  
  * `documento_tipo` (Tipo documento origine: `ordine_acquisto`, `ordine_cliente`, `ricevimento_merce`, `inventario`)  
  * `documento_id` (ID del documento correlato per navigazione incrociata)  
  * `canale_origine` (es: `manuale`, `sito`, `woocommerce`, `importazione`)  
  * `riferimento_esterno` (Identificativo o note aggiuntive dell'operazione)  
  * `note` (Campo di testo libero compilato dall'utente o dal sistema)  
  * `creato_da` (Utente che ha eseguito o autorizzato l'operazione)  
  * `created_at` (Timestamp di registrazione del movimento)

### **MODULO 3: Inventario Fisico (Physical Stocktaking)**

*Il modulo per allineare periodicamente la teoria dell'ERP con la realtà fisica del magazzino.*

* **inventari**  
  * `id` (Chiave Primaria)  
  * `data_inventario` (Data ufficiale in cui viene eseguito il conteggio)  
  * `note` (Note generali: es. "Inventario fine anno 2026", "Rettifica straordinaria reparto chimico")  
  * `creato_da` (Utente che ha aperto la sessione d'inventario)  
  * `created_at` (Data e ora di creazione del record)  
  * `stato` (Valori: `bozza` = conteggio in corso, `convalidato` = rettifiche applicate e sigillate)
* **inventari_righe**  
  * `id` (Chiave Primaria)  
  * `inventario_id` (Chiave Esterna su `inventari` con cancellazione a cascata)  
  * `prodotto_id` (Chiave Esterna su `prodotti`)  
  * `lotto_id` (Chiave Esterna su `lotti`, NULL se il prodotto non gestisce i lotti)  
  * `giacenza_teorica` (La quantità che l'ERP calcolava come presente al momento dell'avvio dell'inventario)  
  * `quantita_contata` (La quantità fisica realmente contata a scaffale dal magazziniere)  
  * `rettifica` (Campo calcolato/generato: `quantita_contata` - `giacenza_teorica`. Può essere positivo o negativo)  
  * `movimento_id` (Chiave Esterna su `movimenti_magazzino`. Collega la riga al movimento di rettifica generato alla convalida)

---

## Flusso degli stati e Regole di Business del Back-End

### **1. Immutabilità dell'Inventario Convalidato**
Quando lo stato di un inventario fisicio passa da `bozza` a `convalidato`, l'intera sessione viene **sigillata**. Qualsiasi richiesta di modifica (PUT) o cancellazione (DELETE) delle righe o della testata viene rifiutata dal server.

### **2. Calcolo Automatico e Generazione Rettifiche alla Convalida**
Al momento del passaggio di stato a `convalidato`, il backend esegue una **transazione SQL (ACID)** per elaborare ogni riga dell'inventario:
* Se `quantita_contata` è uguale a `giacenza_teorica` (rettifica = 0), non viene generato alcun movimento.
* Se `quantita_contata` ≠ `giacenza_teorica`, il sistema:
  1. Genera un record in `movimenti_magazzino`:
     * Imposta `tipo_movimento = 'rettifica'`.
     * Imposta `quantita` = valore assoluto della differenza.
     * Imposta `documento_tipo = 'inventario'` e `documento_id = ID_inventario`.
     * Nel campo `note` inserisce un testo descrittivo (es: *"Rettifica inventario #12. Differenza: -3,5 kg"*).
  2. Associa il movimento inserito alla riga dell'inventario popolando `inventari_righe.movimento_id`.
  3. Aggiorna live la quantità disponibile a magazzino nella tabella `giacenze` (e nella tabella `lotti` se la riga fa riferimento a un lotto specifico):
     * Se la differenza è negativa (reale < teorico): lo stock calcolato si riduce.
     * Se la differenza è positiva (reale > teorico): lo stock calcolato aumenta.

### **3. Esclusione Scadenze Lotti (Eccezione Chimico-Logistica semplificata)**
Per evitare che l'inserimento dati diventi un ostacolo burocratico durante le movimentazioni veloci, **Quack ERP non gestisce le date di scadenza dei lotti** a livello logico/alerting (il campo della scadenza è puramente informativo e opzionale). L'operatore seleziona e traccia i lotti unicamente in base al codice lotto del fornitore o al codice interno assegnato al carico.

---

## Ciclo di utilizzo da parte Utente

### **FASE 1: Monitoraggio Stock e KPI dalla Dashboard**

* **Azione dell'utente:** Il responsabile di magazzino o il buyer accede alla schermata principale **Magazzino** (`/warehouse`).
* **KPI Operativi:**
  * **Valore Magazzino:** Mostra il capitale immobilizzato in stock. Ad esempio, se a magazzino ci sono 1.000 kg di *Ipoclorito di Sodio* con un costo d'acquisto medio di 1,20 €/kg, il sistema valorizza quel prodotto a 1.200,00 €. La somma di tutti i prodotti dà il valore totale.
  * **Sottoscorta:** Indica quanti articoli hanno superato la soglia di sicurezza e richiedono un acquisto per evitare fermi produzione.
  * **Merce in Arrivo (Ingressi previsti):** Indica che ci sono, ad esempio, 3 camion previsti in giornata (ordini d'acquisto inviati che il magazziniere attende per lo scarico).
  * **Merce in Partenza (Uscite previste):** Mostra il numero di ordini pronti per il picking, consentendo di pianificare i colli e i corrieri ADR del giorno.

---

### **FASE 2: Esecuzione dell'Inventario Fisico straordinario**

Prendiamo lo scenario in cui il responsabile decide di verificare lo stock del reparto chimico liquido.

#### **1. Apertura dell'Inventario (Bozza)**
* Il responsabile clicca su **"Nuovo Inventario Fisico"** alla data odierna, inserendo come nota *"Riconciliazione liquidi reparto B"*.
* Il sistema genera la sessione e, in automatico, **cattura la giacenza teorica** di tutti i prodotti a sistema. Ad esempio:
  * *Acido Cloridrico 33% (Lotto AC-901)*: teorico a sistema = **120 kg**
  * *Ipoclorito di Sodio (Lotto IS-302)*: teorico a sistema = **80 kg**

#### **2. Conteggio a Scaffale e Inserimento dei Dati**
* Il magazziniere stampa la scheda o gira con il tablet inserendo le quantità pesate:
  * Trova l'Acido Cloridrico, esegue la pesata e rileva **115 kg** (riporta una mancanza di 5 kg, probabilmente per evaporazione o tolleranza di travaso).
  * Trova l'Ipoclorito di Sodio e rileva **80 kg** (perfettamente allineato).
  * Trova un fusto non registrato di *Acido Solforico* da **25 kg** (eccedenza non a sistema).
* Nel form dell'inventario, l'operatore inserisce:
  * Acido Cloridrico: Contata = `115` (Rettifica calcolata a schermo = `-5`)
  * Ipoclorito di Sodio: Contata = `80` (Rettifica calcolata = `0`)
  * Acido Solforico: Contata = `25` (Teorico era 0, Rettifica = `+25`)

#### **3. Convalida e Aggiornamento Giacenze**
* L'operatore clicca su **"Convalida e Chiudi Inventario"**.
* Il backend esegue la transazione:
  * Per l'Acido Cloridrico, inserisce un movimento di `rettifica` di **5 kg** in scarico. La giacenza disponibile del lotto passa da 120 a 115 kg.
  * Per l'Ipoclorito di Sodio, non fa nulla (allineato).
  * Per l'Acido Solforico, inserisce un movimento di `rettifica` di **25 kg** in carico. La giacenza del prodotto viene registrata a 25 kg.
* La sessione passa a `convalidato` e le giacenze teoriche dell'ERP corrispondono ora esattamente alla realtà del magazzino.

---

### **FASE 3: Flusso di Riordino automatico per prodotti Sottoscorta**

* **Azione dell'utente:** Il buyer apre la scheda **"Riordino"** (`/warehouse/reorder`).
* **Visualizzazione:** Il sistema mostra una tabella con i prodotti che hanno sforato la scorta minima. 
  * *Esempio*: *Soda Caustica a scaglie* -> Disponibile: **50 kg** | Scorta Minima: **200 kg**.
  * Il sistema indica il fornitore preferenziale inserito a catalogo (es. *Gemina Chimica*) e l'ultimo prezzo concordato (0,95 €/kg).
* **Il Flusso Rapido:**
  1. Il buyer clicca su **"Genera RFQ"** accanto alla Soda Caustica.
  2. L'ERP apre in automatico la pagina di creazione di una nuova **Richiesta di Preventivo (RFQ)** (modulo Acquisti), avendo già precompilato:
     * Il fornitore: *Gemina Chimica*.
     * Il prodotto nella riga: *Soda Caustica*.
     * La quantità consigliata: **150 kg** (la differenza matematica necessaria per tornare alla scorta minima di sicurezza, ovvero $200 - 50 = 150$).
  3. Il buyer deve solo verificare e cliccare su "Invia RFQ" completando l'operazione in pochi secondi.

---

## Design Interfaccia Utente - Principi di UX/UI

Le schermate del magazzino seguono criteri di pulizia visiva e immediatezza, evitando l'eccesso di dati a schermo:

### **Schermata A: La Dashboard di Magazzino (`/warehouse`)**
* **KPI Superiori:** Quattro riquadri colorati con icone chiare:
  * *Valore magazzino (Blu)*, *Prodotti Sottoscorta (Arancione)*, *In arrivo (Verde)*, *In partenza (Celeste)*.
* **Layout a due colonne:**
  * **Sinistra (Principale):** Tabella con i 5 movimenti più recenti (Data, Prodotto, Tipo movimento, Quantità, Operatore) e i 5 prodotti più vicini al sottoscorta.
  * **Destra (Laterale):** Pannello "Azioni Rapide" con bottoni grandi e colorati per:
    * *Nuovo Movimento Manuale*
    * *Avvia Inventario Fisico*
    * *Visualizza Proposte di Riordino*

### **Schermata B: Form di Inserimento Inventario Fisico (`/warehouse/inventari/create`)**
* **Testata:** Campo data dell'inventario (precompilato a oggi) e campo note.
* **Griglia di Conteggio:**
  * Una griglia precompilata con tutti i prodotti chimici a catalogo e i relativi lotti attivi.
  * Colonna fissa: *Giacenza teorica a sistema* (es. `120 kg`).
  * Colonna di input: *Quantità contata*. Il campo è precompilato con lo stesso valore teorico per velocizzare l'inserimento nei casi in cui non ci siano differenze (l'operatore deve modificare solo le righe dove ha riscontrato scostamenti).
  * Colonna calcolata in tempo reale via JavaScript: *Scostamento*. Se l'utente cambia la quantità contata, mostra istantaneamente la differenza (es. `-5` in arancione o `+10` in verde).

---

## Dettaglio Tabelle (Field Matrix)

| TABELLA | CAMPO | TIPO | SCOPO | DESCRIZIONE / REGOLE DI VALIDAZIONE |
| :--- | :--- | :--- | :--- | :--- |
| **giacenze** | id | PK (int) | Identificativo riga | Generato automaticamente dal database. |
| | prodotto_id | FK (int) | Collegamento anagrafica | Punta a `prodotti.id`. Deve essere unico per articolo. |
| | quantita_disponibile | Decimal | Giacenza logica libera | Stock fisico reale sottratto di quello già impegnato. |
| | quantita_riservata | Decimal | Impegno ordini | Quantità associata a contratti/ordini clienti non ancora evasi. |
| | quantita_in_arrivo | Decimal | Ordini in transito | Somma delle quantità presenti negli ordini d'acquisto inviati. |
| | scorta_minima | Decimal | Parametro riordino | Quantità minima sotto la quale il prodotto è sottoscorta. |
| **movimenti_magazzino** | id | PK (int) | Identificativo movimento | Generato automaticamente. |
| | prodotto_id | FK (int) | Articolo movimentato | Punta a `prodotti.id`. Obbligatorio. |
| | lotto_id | FK (int) | Lotto tracciato | Punta a `lotti.id`. Obbligatorio se l'articolo traccia i lotti. |
| | tipo_movimento | Varchar | Causale operazione | Valori ammessi: `carico`, `scarico`, `rettifica`, etc. |
| | quantita | Decimal | Entità dello spostamento | Valore numerico sempre positivo. |
| | documento_tipo | Varchar | Tracciabilità origine | Tipo entità che ha generato il movimento (es: `inventario`). |
| | documento_id | Int | ID del documento | ID numerico del documento collegato. |
| **inventari** | id | PK (int) | Identificativo sessione | Generato automaticamente. |
| | data_inventario | Date | Data del conteggio | Impostata dall'utente (default: data corrente). |
| | note | Text | Annotazioni descrittive | Campo di testo libero per commenti sull'inventario. |
| | creato_da | FK (int) | Utente creatore | Punta a `utenti.id` dell'operatore loggato. |
| | stato | Varchar | Stato della sessione | Valori: `bozza` (modificabile), `convalidato` (sigillato). |
| **inventari_righe** | id | PK (int) | Identificativo riga | Generato automaticamente. |
| | inventario_id | FK (int) | Legame testata | Punta a `inventari.id` con cancellazione a cascata. |
| | prodotto_id | FK (int) | Articolo conteggiato | Punta a `prodotti.id`. |
| | lotto_id | FK (int) | Lotto conteggiato | Punta a `lotti.id` (opzionale). |
| | giacenza_teorica | Decimal | Quantità stimata | Copiata dalla giacenza dell'ERP all'avvio. |
| | quantita_contata | Decimal | Quantità reale | Inserita dall'operatore durante il conteggio. |
| | movimento_id | FK (int) | Movimento generato | Punta a `movimenti_magazzino.id` generato alla convalida. |
