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
  * `magazzino_id` (Chiave Esterna su `magazzini`. L'inventario è sempre riferito a UN magazzino fisico specifico)
  * `categoria_filtro_id` *(aggiunto 2026-07-10)* (Chiave Esterna su `categorie_prodotto`, opzionale. Se valorizzato, l'inventario copre solo i prodotti di quella categoria — un "conteggio a rotazione"/cycle count. Se `NULL`, l'inventario copre tutti i prodotti del magazzino — conteggio completo, comportamento storico)
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

## Blocco Movimenti durante l'Inventario Fisico (ispirato a SAP) — aggiunto il 2026-07-10

Questa sezione spiega, in modo discorsivo e senza dare per scontate nozioni tecniche, **perché** questa funzionalità è stata introdotta, **come funziona** oggi e **quali scelte architetturali** sono state fatte e perché.

### Il problema che c'era prima

Prima di questa modifica, l'inventario fisico funzionava così: si apriva una sessione, il sistema fotografava la giacenza teorica di **tutti** i prodotti del magazzino in quel preciso istante, il magazziniere girava con il tablet contando tutto, e solo alla fine (alla "Convalida") le differenze venivano scritte a sistema.

Il problema è che, nel tempo che intercorre tra l'apertura dell'inventario e la sua convalida (che nella pratica può essere ore, se non un giorno intero per un magazzino grande), **il resto dell'azienda continuava a lavorare normalmente**: un ordine cliente veniva spedito, una bolla di un fornitore veniva scaricata, un operatore registrava un movimento manuale di rettifica per un altro motivo. Ognuna di queste operazioni cambia la giacenza reale di un prodotto — ma la "giacenza teorica" fotografata all'apertura dell'inventario restava congelata al valore vecchio.

Risultato pratico: se durante il conteggio del reparto acidi qualcuno spediva per errore (o per necessità operativa legittima) dell'Acido Cloridrico che era sotto conteggio, alla convalida il sistema avrebbe generato una rettifica basata su un confronto ormai falsato — introducendo un errore di giacenza invece di correggerlo. Peggio ancora, non c'era **nessun controllo** che impedisse alla giacenza di scendere sotto zero, né alcuna visibilità sul fatto che il prodotto contato fosse già promesso (riservato) a un cliente in attesa di spedizione.

### La soluzione: bloccare i movimenti, ma solo dove serve

La soluzione implementata si ispira esplicitamente al modo in cui **SAP** (il principale ERP enterprise al mondo, storicamente un riferimento per i processi di magazzino) gestisce l'inventario fisico. SAP, quando un conteggio è in corso, applica un **blocco dei movimenti (posting block)** sulla combinazione **magazzino + materiale**: cioè blocca solo quel prodotto, in quel magazzino, non l'intero magazzino e non l'intera azienda.

Quack ERP replica questo principio con la stessa identica granularità: **(magazzino, prodotto)**. Concretamente, mentre un inventario è in stato `bozza` (conteggio aperto, non ancora convalidato):

* Non è possibile registrare un **movimento manuale** (carico/scarico/rettifica manuale da `/warehouse/movements`) su un prodotto che è sotto conteggio in quel magazzino.
* Non è possibile **confermare la ricezione di una merce** (bolla fornitore) se contiene un prodotto sotto conteggio in quel magazzino.
* Non è possibile **spedire un ordine cliente** (creare una spedizione/DDT) se contiene un prodotto sotto conteggio in quel magazzino — e questo vale anche per i **Kit** (prodotti composti da più componenti): se uno qualunque dei componenti del kit è sotto conteggio, la spedizione dell'intero kit viene bloccata, perché spedire il kit movimenterebbe comunque quel componente.

Se un operatore prova a fare una di queste tre operazioni su un prodotto bloccato, il sistema rifiuta l'operazione con un messaggio chiaro (es. *"Impossibile spedire 'Acido Cloridrico 33%': è in corso un inventario fisico su questo prodotto in questo magazzino."*), invitando a completare o annullare prima l'inventario.

Tutti gli **altri** prodotti del magazzino, non coinvolti nel conteggio, restano completamente operativi: si possono spedire, ricevere, movimentare normalmente. Questo è il punto centrale della scelta: **non si blocca mai il magazzino intero**, solo i singoli prodotti effettivamente sotto conteggio in quel momento.

*(Nota di trasparenza: SAP, nella sua versione più completa, arriva a granularità ancora più fine — fino al singolo "storage bin"/ubicazione dentro il magazzino, e distingue tra blocco rigido e semplice "congelamento" della giacenza contabile con possibilità di sblocco autorizzato per eccezioni. Quack ERP adotta una versione semplificata pensata per la scala di una PMI: granularità (magazzino, prodotto), blocco sempre rigido, nessun sistema di autorizzazioni per eccezioni — se serve sbloccare, si annulla o si convalida l'inventario.)*

### Perché il blocco da solo non basta: il "cycle count"

C'è però un problema logico: se ogni inventario, per definizione, contava **tutti** i prodotti del magazzino (come funzionava storicamente in Quack ERP), allora un blocco "per singolo prodotto" sarebbe del tutto equivalente, nella pratica, a un blocco dell'intero magazzino — perché tutti i prodotti sarebbero comunque sotto conteggio contemporaneamente. La granularità fine sarebbe inutile senza anche la possibilità di aprire inventari che coprono **solo una parte** del magazzino.

Per questo motivo, insieme al blocco granulare, è stata introdotta la funzionalità di **"conteggio a rotazione" (cycle counting)**: in fase di apertura di un nuovo inventario, l'operatore può scegliere se contare **tutti i prodotti** (comportamento storico, "inventario completo") oppure **solo i prodotti di una categoria specifica** (es. "solo Solventi", "solo Acidi") tramite un menu a tendina "Ambito conteggio" nel form di creazione. Un inventario aperto in questo modo blocca — coerentemente con quanto spiegato sopra — solo i prodotti di quella categoria, lasciando il resto del magazzino pienamente operativo durante tutto il conteggio.

Questo approccio non è un'invenzione arbitraria: è la **best practice riconosciuta** per la gestione dell'inventario fisico nelle piccole e medie imprese (fonti: guide di settore ISM, RFgen, NetSuite, ERP Software Blog). Il modello raccomandato è **ibrido**: conteggi a rotazione frequenti (settimanali/mensili) su sottoinsiemi di prodotti — così da non fermare mai il flusso operativo dell'intero magazzino — combinati con un conteggio fisico completo periodico (tipicamente annuale, es. a fine anno), utile come verifica generale e per gli adempimenti contabili/fiscali. Quack ERP supporta oggi entrambi gli scenari con lo stesso meccanismo.

### Doppio controllo alla Convalida: niente giacenze negative, avviso su riservato

Quando l'operatore clicca "Convalida e Chiudi Inventario", il sistema oggi esegue un controllo in **due passaggi**, invece di applicare direttamente le rettifiche come accadeva prima:

**Passaggio 1 — verifica preventiva.** Per ogni riga con uno scostamento (quantità contata diversa dalla teorica), il sistema calcola quale sarebbe la nuova giacenza disponibile risultante. Se anche una sola riga porterebbe la giacenza disponibile **sotto zero**, l'intera convalida viene **bloccata** e annullata (nessuna rettifica viene applicata, nemmeno per le altre righe corrette) mostrando l'elenco preciso dei prodotti in errore. Questo è un controllo di sicurezza che prima non esisteva: impediva a un errore di battitura durante il conteggio (es. contare "5" invece di "50") di generare una giacenza negativa, un dato privo di senso fisico che poi si propaga a cascata su disponibilità, riordino e documenti di trasporto.

Se invece la nuova giacenza sarebbe sì minore della quantità **già riservata** per ordini clienti confermati (ma non negativa in assoluto), la convalida **non viene bloccata**: procede normalmente, ma il messaggio di successo finale include un avviso esplicito (es. *"ATTENZIONE — sotto-copertura ordini: Acido Cloridrico 33% (disponibile 40, riservato 55)."*), così che il buyer o il responsabile vendite sappia subito che uno o più ordini promessi ai clienti potrebbero non essere completamente coperti dallo stock reale, e possa intervenire (contattare il cliente, sollecitare un riordino, ecc.) prima che il problema si scopra al momento della spedizione.

**Passaggio 2 — applicazione.** Solo se il Passaggio 1 non ha trovato violazioni negative, il sistema procede come prima: genera i movimenti di tipo `rettifica` per ogni riga con scostamento, aggiorna le giacenze e sigilla l'inventario come `convalidato`.

### La sicurezza di poter annullare: "Annulla bozza"

Poiché ora un inventario aperto blocca dei prodotti (anche se solo quelli effettivamente sotto conteggio), è stata aggiunta una via di uscita che prima non esisteva: il bottone **"Annulla bozza"**, disponibile finché l'inventario è in stato `bozza` (non ancora convalidato). Cliccandolo, la sessione di inventario viene eliminata senza aver toccato alcuna giacenza (la convalida è l'unico momento in cui le rettifiche vengono realmente scritte), e tutti i prodotti coinvolti tornano immediatamente movimentabili. Serve, ad esempio, se un inventario è stato aperto per errore, o se il conteggio va rimandato e nel frattempo serve sbloccare i prodotti per l'operatività quotidiana.

### Protezione da conteggi sovrapposti

Infine, per evitare che due inventari in bozza coprano contemporaneamente lo stesso prodotto nello stesso magazzino (situazione che creerebbe ambiguità su quale dei due debba "vincere" alla convalida), il sistema controlla, al momento dell'apertura di un nuovo inventario, se esiste già un altro inventario in bozza che include uno o più degli stessi prodotti in quel magazzino. In tal caso, l'apertura viene rifiutata con un messaggio che indica quale inventario esistente va prima completato o annullato (es. *"Esiste già un inventario in bozza (#12, categoria 'Acidi') che copre uno o più di questi prodotti in questo magazzino."*).

### Riepilogo per chi non è tecnico

In una frase: **da oggi, contare fisicamente un prodotto "congela" solo quel prodotto in quel magazzino** (non l'intero magazzino), si può scegliere di contare solo una categoria alla volta invece di tutto insieme, il sistema impedisce di generare per errore una giacenza negativa, avvisa se lo stock reale rischia di non coprire gli ordini già promessi ai clienti, e si può sempre annullare un conteggio aperto per errore senza conseguenze. Il modello è lo stesso concetto usato da SAP per i grandi magazzini industriali, semplificato per adattarsi alla scala e alle esigenze di Quack.

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
| | magazzino_id | FK (int) | Magazzino di riferimento | Punta a `magazzini.id`. Il blocco movimenti (v. sezione dedicata) si applica per (magazzino, prodotto). |
| | categoria_filtro_id | FK (int, opz.) | Ambito cycle count | Punta a `categorie_prodotto.id`. NULL = inventario completo; valorizzato = conteggio a rotazione su una sola categoria. |
| **inventari_righe** | id | PK (int) | Identificativo riga | Generato automaticamente. |
| | inventario_id | FK (int) | Legame testata | Punta a `inventari.id` con cancellazione a cascata. |
| | prodotto_id | FK (int) | Articolo conteggiato | Punta a `prodotti.id`. |
| | lotto_id | FK (int) | Lotto conteggiato | Punta a `lotti.id` (opzionale). |
| | giacenza_teorica | Decimal | Quantità stimata | Copiata dalla giacenza dell'ERP all'avvio. |
| | quantita_contata | Decimal | Quantità reale | Inserita dall'operatore durante il conteggio. |
| | movimento_id | FK (int) | Movimento generato | Punta a `movimenti_magazzino.id` generato alla convalida. |
