# Architettura Quack ERP – Acquisti V0.2

## Studio del flusso logico della gestione degli Acquisti da parte di un ERP. Dal momento in cui un utente apre una nuova richiesta di preventivo al momento in cui l'ordine è confermato. Copre la logica applicativa, le decisioni architetturali e il comportamento del frontend.

# Il ciclo acquisti

Negli ERP "best in class" (Odoo, SAP, NetSuite, Dynamics) il ciclo acquisti è quasi sempre questo:

![][image1]

# Flusso logico documentale

Tutto il ciclo acquisti ruota attorno a un singolo record nella tabella ordini\_acquisto . Questo record **nasce come richiesta preventivo (RFQ)** e **muore come ordine ricevuto**, cambiando stato nel tempo. Non esistono due tabelle separate "rfq" e "purchase\_order" — è lo stesso record.

Questo è la scelta architetturale più importante del ciclo acquisti. I motivi:

* Non c’è bisogno di duplicare dati con due tabelle RFQ e OrdiniAcquisto

* Cambiando solo il valore dello stato, non devo "copiare" un record per promuoverlo

* Lo storico è tutto su un record solo (facile da leggere, facile da debuggare)

* La macchina a stati è semplice da implementare

## Stati dell’ordine di acquisto

| Stato | Cosa significa | Cosa è modificabile |
| :---- | :---- | :---- |
| **`draft`** | RFQ in compilazione, non ancora inviata | Tutto |
| **`sent`** | RFQ inviata al fornitore, in attesa di quotazione | Quasi tutto |
| **`confirmed`** | Diventato PO: il fornitore ha confermato, è un ordine vero | Solo note, date di consegna |
| **`cancelled`** | Annullato | Niente |
| **`Partially_received`** | Annullato | Niente |
| **`received`** | Merce arrivata (totale o parziale) | Niente, solo lettura |

![][image2]

**Come vengono modellati nel DB?**

In modo brutalmente semplice. È un campo TEXT nella tabella ordini\_acquisti:

* state \= "draft"  
* state \= "confirmed"  
* state \= "cancelled"

Il database non sa niente di stati o transizioni. È solo una stringa. L'ordine e il significato degli stati esistono **solo nel codice PHP**, non nel DB.

### Come fa il sistema a sapere quale stato viene prima e quale dopo?

Questa logica non va in una tabella ma va nel codice.

Questo perché i**l flusso di un ciclo acquisti non cambia mai.** Non è qualcosa che l'utente finale deve riconfigurare. Se cambia, è un intervento di sviluppo, e quindi è lo sviluppatore a dover intervenire

Per scrivere quindi quali sono gli stati ordine, e poter dire a quali stati può accedere un utente, si fa quella che si chiama una mappa delle transizioni consentite.

Nella pratica è un array associativo. In PHP si scrive così (non è codice da copiare, è pseudocodice per capire il concetto):

![][image3]

Quando arriva una richiesta di transizione, il backend fa due cose in sequenza:

**Passo 1 — Controllo strutturale** (la mappa): "questa transizione è teoricamente ammessa?"

* stato\_attuale \= leggi dal DB (es. "sent")  
* stato\_richiesto \= parametro della richiesta (es. "confirmed")  
* stati\_raggiungibili \= TRANSIZIONI\_VALIDE\["sent"\]   \["confirmed", "cancelled"\]  
* se "confirmed" non è in stati\_raggiungibili  errore 422, blocca tutto

**Passo 2 — Controllo di business**: "in questo caso specifico, le condizioni sono soddisfatte?"

se stato\_richiesto \= "confirmed":

    \- il fornitore è valorizzato?  no  errore "Seleziona un fornitore"

    \- ci sono righe?  no  errore "Aggiungi almeno un prodotto"

    \- tutte le righe hanno prezzo?  no  errore "Prezzo mancante sulla riga 3"

    \- tutte le condizioni passate  esegui la transizione

Il passo 1 è identico per tutti gli ordini. Il passo 2 è specifico per ogni transizione e contiene logica di business. Separare i due passi rende il codice leggibile: sai esattamente dove guardare se vuoi capire "cosa serve per confermare un ordine".

**Dove vive questo codice:** in una classe PurchaseOrderStateMachine (o simile) nella cartella /src. Il controller PHP chiama $stateMachine-\>transition($order, 'confirmed') e riceve o un successo o un'eccezione con il motivo del fallimento.

TRANSIZIONI VALIDE:

* da draft       posso andare in: sent, cancelled  
* da sent        posso andare in: confirmed, cancelled  
* da confirmed   posso andare in: received, partially\_received, cancelled  
* da part.rec.   posso andare in: received, cancelled  
* da received    posso andare in: (nessuna, stato finale)  
* da cancelled   posso andare in: (nessuna, stato finale)

Quando l'utente preme "Conferma ordine", il backend:

- Legge lo stato attuale dal DB (sent)  
- Controlla: è consentito passare da sent a confirmed?  sì  
- Esegue la transizione

Se invece arriva una richiesta di passare da received a draft, la rifiuta con errore

Il sistema non è "intelligente" — segue una lista. Questo si chiama macchina a stati finiti ed è uno dei pattern più usati in tutto lo sviluppo software.

### Cosa il front-end mostra in base allo stato

| Stato | Testata ordine | Righe ordine | Azioni disponibili |
| :---- | :---- | :---- | :---- |
| **draft** | Editabile | Editabili | Salva, Invia al fornitore, Elimina |
| **sent** | Solo prezzi editabili | Solo prezzi editabili | Conferma ordine, Annulla |
| **confirmed** | Solo lettura | Solo lettura | Registra ricevimento, Annulla |
| **partially\\\_received** | Solo lettura | Solo lettura | Registra ricevimento (restante) |
| **received** | Solo lettura | Solo lettura | Nessuna |
| **cancelled** | Solo lettura | Solo lettura | Nessuna |

### Cosa fa il back-end

Il flusso corretto è:

* Utente preme "Conferma ordine"  
  *  Frontend invia POST /api/purchase-orders/42/confirm  
  *  Backend controlla stato attuale (legge dal DB)  
  *  Backend valida le condizioni di business  
  *  Backend esegue (o rifiuta con errore)  
  *  Frontend aggiorna la UI in base alla risposta

Il frontend non conosce le regole — conosce solo lo stato attuale (che il backend gli ha comunicato) e mostra o nasconde pulsanti di conseguenza. Le regole vere stanno sempre nel backend.

### Cosa mostra il front-end

Ogni volta che il frontend carica un ordine, il backend risponde con l'oggetto completo incluso state. Il frontend usa state per decidere cosa mostrare. In pratica:

* se state \= "draft":  
  * mostra: form editabile, pulsante "Invia al fornitore", pulsante "Elimina"  
  * nascondi: tutto il resto  
* se state \= "sent":  
  * mostra: prezzi editabili, pulsante "Conferma ordine", pulsante "Annulla"  
  * nascondi: resto del form  
* se state \= "confirmed":  
  * mostra: vista sola lettura, pulsante "Registra ricevimento", pulsante "Annulla"  
  * nascondi: tutto ciò che è editabile  
* se state \= "received":  
  * mostra: vista sola lettura, storico ricevimenti  
  * nascondi: qualsiasi pulsante di azione

l risultato: l'utente non vede mai pulsanti che non può usare. Non è una questione di sicurezza (quella è nel backend) ma di UX — non mostrare opzioni impossibili evita confusione.

### Gli stati finali non hanno uscita

received e cancelled sono stati finali: la mappa non prevede transizioni in uscita da loro. Il sistema non offre nessun pulsante di azione, non accetta nessuna richiesta di cambio stato.

Per cancelled la domanda frequente è "ma se voglio riordinare?". La risposta è: crei un nuovo ordine. Alcuni ERP hanno un pulsante "Duplica" che copia tutte le righe di un ordine annullato in una nuova bozza — utile, semplice da implementare, non è un "ripristino" ma una copia. Vedi sezione 7 per i dettagli sulla cancellazione.

# Il flusso acquisti passo passo

## Passo 1 — L'utente crea una nuova RFQ

Tutto parte quando ci si accorge che serve della merce. Questo può accadere in due modi:

* **Via Manuale:** L'operatore nota che un prodotto sta finendo in magazzino o vuole fare scorta. Va sulla schermata "Acquisti" dell'ERP e clicca su "Nuova Richiesta di Offerta (RFQ)".

* **Via Automatica (Dropshipping/Smart Splitter):** Un cliente ha accettato un preventivo per prodotti che noi non abbiamo fisicamente in magazzino. L'ERP analizza le righe di quel preventivo e, in base al "Fornitore Default" di ogni prodotto, genera automaticamente le relative bozze di RFQ separate.

### Cosa succede a schermo in questa fase?

#### *L'utente crea un preventivo*

**Frontend**: l'utente clicca "Nuova richiesta di preventivo". Si apre un form vuoto.  
**Cosa succede nel software**: il frontend NON crea ancora nulla nel database. Il record viene creato solo quando l'utente salva per la prima volta (o aggiunge la prima riga). Questo evita record vuoti abbandonati.

In alternativa (più semplice da implementare): crei subito il record in draft con una chiamata POST, ottieni l'ID, e poi tutte le modifiche successive sono PATCH su quell'ID. Scelta pragmatica: fai così, è più facile.

**Cosa scrive il backend**:

- Nuovo record in ordini\_acquisti con state \= draft  
- Numero documento assegnato (es. RFQ/2025/0001) — vedi sezione numerazione  
- created\_by \= utente corrente  
- created\_at \= timestamp  
- supplier\_id \= null (ancora da scegliere)

#### *L'utente sceglie il fornitore*

**Frontend**: campo autocomplete sul nome fornitore. L'utente digita, il sistema suggerisce dai partner con \`is\_supplier \= true\`. Scelta il fornitore.

**Cosa succede nel software**: il frontend fa una chiamata PATCH a \`/api/purchase-orders/{id}\` con il \`supplier\_id\`. Il backend aggiorna il record e risponde con i dati aggiornati, inclusi i default del fornitore (termine di pagamento, valuta, indirizzo).

Il frontend aggiorna i campi header con questi default — l'utente può cambiarli.

**La regola del multi-fornitore in azione:** Se l'operatore aggiunge un prodotto (es. *Flocculante*) il cui fornitore abituale a sistema sarebbe un altro (es. *PoolDistributor*), l'ERP mostra una riga evidenziata in giallo con un avviso: ⚠️ Fornitore Abituale: PoolDistributor. 

#### *L'utente aggiunge le righe*

Questa è la parte più ricca di logica.

**Frontend**: tabella editabile. Ogni riga ha i campi: prodotto, descrizione, confezione, quantità, UoM, prezzo unitario, sconto, IVA, subtotale.

**Quando l'utente sceglie un prodotto in una riga**:

Il frontend fa una chiamata a /api/products/{id}/supplier-info?supplier={supplier\_id}. Il backend risponde con:

* Il codice articolo del fornitore per quel prodotto

* La confezione di acquisto preferita per quel fornitore

* La UoM di acquisto preferita

* Il prezzo suggerito (vedi algoritmo sotto). In questa fase i prezzi non sono vincolanti: il sistema propone il "costo base" salvato in anagrafica, ma l'operatore può lasciarlo così com'è o metterlo a zero in attesa del preventivo del fornitore.

Il frontend precompila tutti questi campi. L'utente può modificarli.

* In questa fase i prezzi non sono vincolanti: il sistema propone il "costo base" salvato in anagrafica, ma l'operatore può lasciarlo così com'è o metterlo a zero in attesa del preventivo del fornitore. 

**Quando l'utente inserisce una quantità o un prezzo**:

Il frontend calcola il subtotale in tempo reale (JS, nessuna chiamata al backend). Il totale dell'ordine si aggiorna in tempo reale. Il salvataggio della riga avviene on-blur (quando l'utente esce dal campo) o con un bottone esplicito "Salva righe" — la seconda opzione è più sicura per evitare chiamate continue.

**Prodotto non nel catalogo del fornitore**:

Se l'utente sceglie un prodotto che non ha il fornitore selezionato in product\_supplier, il frontend mostra un warning: "Questo prodotto non risulta nel catalogo di \[fornitore\]. Vuoi aggiungerlo?". Se l'utente conferma, il prodotto viene aggiunto con i dati minimi. Il catalogo si auto-aggiorna quando l'ordine viene confermato.

#### *L'utente salva la bozza*

La RFQ è in stato draft. L'utente può uscire e tornare, modificare righe, cambiare fornitore. Nessun vincolo ancora.

Il numero documento è già assegnato (es. RFQ/2025/0001). Questo numero rimane per tutta la vita del documento, anche quando diventa PO.

#### *Algoritmo di suggerimento prezzo (lato backend):*

1. Cerca in prodotti\_frornitore per quella coppia (prodotto, fornitore), con valid\_from \<= oggi \<= valid\_to (o null), filtrato per la confezione scelta se disponibile, prendendo lo scaglione con min\_qty più alto che sia minore o uguale alla quantità inserita.

2. Se non trovi nulla, prendi il prezzo dell'ultima purchase\_order\_line confermata per quella coppia (prodotto, fornitore), ordinata per order\_date DESC.

3. Se non trovi nulla neanche lì, il campo prezzo resta vuoto e il sistema mostra un avviso "Nessun prezzo disponibile per questo fornitore".

Questo algoritmo si ricalcola ogni volta che l'utente cambia la quantità (potrebbe scattare uno scaglione diverso) o la confezione.

## Passo 2 – l’utente stampa il pdf della RFQ e la invia al fornitore

**Frontend**: pulsante "Invia al fornitore". Il sistema chiede conferma.

**Cosa succede nel software**:

1. Il backend valida che il documento sia completo (fornitore presente, almeno una riga, prezzi presenti o accettato l'invio senza prezzi)

2. state passa da draft a sent

3. sent\_at \= timestamp (aggiungi questo campo alla tabella)

4. In futuro: generazione PDF e invio email. Per ora: solo cambio stato.

Da questo momento il documento non è più modificabile liberamente. Il frontend nasconde i campi editabili e mostra i dati in sola lettura.

**Perché bloccare le modifiche in questa fase?** È una regola fondamentale per evitare casini amministrativi. Se hai inviato un PDF via mail con scritte certe quantità, non puoi permettere che un operatore le modifichi a schermo senza che il fornitore lo sappia.

* Le **quantità** e i **prodotti** vengono temporaneamente congelati.

* I **prezzi** rimangono invece modificabili, perché stiamo aspettando che il fornitore ci risponda dicendoci quanto vuole.

**Impatto sulle giacenze:** **Zero**.

### Stampa e invio di richieste di preventivo (RFQ) nella nostra realtà (QUACK ERP)

Visto che l'ERP gira in locale su XAMPP e potrebbe non avere un server SMTP configurato per inviare mail direttamente, la procedura deve essere robusta e semi-automatica.

**Cosa succede fisicamente a schermo (UI/UX)?**

1. **La Schermata di visualizzazione della RFQ (Stato: Bozza):** L'operatore vede la richiesta con un grande pulsante verde: **"Genera PDF e Invia"**.

2. **Il Click sul Pulsante:** Quando l'utente clicca, l'ERP non invia la mail di nascosto, ma apre un **Popup (Modal)** che offre due strade:

   * **Strada A (Semi-automatica via Client Locale):** Un link speciale con protocollo mailto: precompilato (es. mailto:fornitore@chemical.com?subject=Richiesta Offerta RFQ-2026-0004\&body=Salve, in allegato...). Al click, si apre automaticamente l'Outlook o il Thunderbird installato sul PC dell'utente con la mail pronta. L'utente deve solo trascinare il PDF appena scaricato.

   * **Strada B (Copia-Incolla Manuale):** Nel popup ci sono due pulsanti:

     1. \[ Scarica PDF della Richiesta \] (Salva il file sul desktop).

     2. \[ Copia Testo Mail \] (Copia negli appunti un testo standard per il fornitore).

3. **Il Cambio di Stato Effettivo:** In fondo allo stesso Popup c'è un pulsante arancione: **\[ Registra come INVIATA \]**.

* Solo quando l'utente clicca questo tasto, il front-end invia una richiesta Javascript (Fetch API) al back-end che esegue la query: UPDATE acquisti SET stato \= 'inviata\_rfq' WHERE id \= ?.

* La schermata si ricarica, i campi quantità si congelano (diventano readonly a schermo) e compare il badge di stato: **Stato: Inviata**.

**Impatto sulle giacenze:** **Zero**. La merce è solo un'idea sulla carta.

## Passo 3 \- La Trattativa e l'Aggiornamento Prezzi

Questo passo non avviene dentro il software. Il fornitore risponde via email, telefono, portale suo. L'utente riceve la risposta fuori dal sistema.

L'utente torna sull'RFQ e può aggiornare i prezzi con quelli ricevuti (lo stato sent permette ancora la modifica dei prezzi delle righe, ma non aggiunta/rimozione righe — decidilo tu, è una scelta di policy).

Il fornitore risponde alla mail: *"Sì, ho tutto disponibile. Il Cloro te lo faccio a 1.20 €/kg invece di 1.10, e l'Acido a 2.10 €/kg"*.

* L'operatore riapre la RFQ (che è nello stato "Inviata").

* Inserisce nei relativi campi i prezzi reali confermati dal fornitore.

* Il sistema ricalcola il totale imponibile dell'ordine.

## Passo 4 \- La Conferma dell'Ordine (il record passa da RFQ a P.O.)

**Frontend**: pulsante "Conferma ordine". Il sistema chiede conferma.

Questo è il momento di svolta del ciclo. Il documento cambia formalmente natura: smette di essere una richiesta di preventivo (RFQ) e diventa un **Ordine d'Acquisto (PO)** definitivo.

**Cosa succede nel software**:

1. Validazione finale (fornitore, righe, prezzi — nessun prezzo vuoto)

2. state passa da sent a confirmed

3. confirmed\_at \= timestamp

4. Il numero documento rimane lo stesso (RFQ/2025/0001 \= PO/2025/0001) oppure il sistema assegna un numero PO separato — dipende da quanto formale vuoi essere. Consiglio: stesso numero, cambia solo l'etichetta sul documento stampato.

5. **Aggiornamento automatico del catalogo fornitori**: per ogni riga, il backend controlla se esiste già un record in product\_supplier per quella coppia. Se non esiste, lo crea. Poi aggiorna (o crea) il prezzo in product\_supplier\_price con il prezzo effettivo dell'ordine, valid\_from \= oggi.

6. Il sistema crea automaticamente un stock\_picking in stato draft per il ricevimento futuro. Le righe del picking corrispondono alle righe del PO, con le quantità attese.

Il documento diventa **completamente blindato**: non si possono più toccare né i prodotti, né le quantità, né i prezzi negoziati.

**Come si muovono le giacenze adesso? (La logica del "In Arrivo")** La merce non è ancora fisicamente da noi (è su un camion o ancora nei magazzini del fornitore), quindi non possiamo aumentare la *Giacenza Reale*. Però, dobbiamo dire a chi si occupa delle vendite che quella merce sta arrivando, altrimenti rischiamo di ordinarla due volte o di dire ai clienti che siamo senza stock.

L'ERP aumenta la **Giacenza in Arrivo** dei prodotti ordinati di una quantità pari a quella scritta nell'ordine.

La formula magica che calcola la **Giacenza Virtuale** (quella che mostrerai su WooCommerce o ai venditori) è:

Giacenza Virtuale=Giacenza Reale+Giacenza in Arrivo-Impegnato per i Clienti

## Passo 8 \- L'Arrivo del Camion e il Carico (La Ricezione Reale)

Questo è tecnicamente fuori dal ciclo RFQPO, ma fa parte dello stesso flusso fisico.

Il camion del fornitore arriva nel piazzale di Gemina Srl. Il magazziniere scarica la merce e prende in mano la bolla (DDT) del fornitore.

Qui ci possono essere due posti diversi con logiche diverse dove l’utente inserisce la merce in arrivo

1. Una schermata “Merce in arrivo” che per ogni ordine confermato che non ha ancora ricevuto TUTTE le merci mi dice quante me ne aspetto  
2. Una schermata di magazzino “Registra Movimento”

### Opzione “Merce in arrivo”

Quando l'utente preme "Conferma ordine", il software fa due cose quasi simultaneamente.

* La prima è ovvia: l'ordine cambia stato da sent a confirmed.  
* La seconda è meno ovvia ma altrettanto importante: **il sistema crea automaticamente una schermata di attesa per il ricevimento della merce**.

Chiamiamola "bolla di entrata attesa" per ora. È un record nella tabella stock\_picking ricevimento\_merce). Questo record dice:

"Dall'ordine 42 a Rossi Srl aspetto: 30 pz di Cloro 10 kg e 10 pz di Flocculante 25 kg, entro il 20/06/2025."

Lo stato di questo record è draft — significa "attesa", non ancora successo niente fisicamente.

**Il problema reale della chimica (Tolleranze e Rotture):** Il magazziniere apre l'ERP, cerca l'ordine ORDA-2026-0001 e clicca su "Registra Ricezione". L'ERP gli mostra le quantità che *dovrebbero* arrivare. Il magazziniere però deve fare un controllo fisico, perché:

1. **Prodotti liquidi/sfusi:** Avevamo ordinato una cisterna da 1000 kg di acido, ma per questioni di pressione e temperatura il fornitore ne ha scaricati effettivamente 980 kg.

2. **Rotture/Resi:** Un sacco di cloro si è rotto durante il viaggio ed è inutilizzabile; quindi, ne accettiamo solo 9 su 10\.

3. **Consegne parziali:** Il fornitore non aveva tutto subito. Ci consegna oggi 500 kg di cloro e i restanti 500 kg arriveranno la settimana prossima.

**Cosa fa l'operatore a schermo?** Inserisce il numero del DDT del fornitore (fondamentale per tracciare la merce in caso di controlli) e digita le **quantità effettivamente contate e integre**.

	**Il numero DDT è assolutamente testo libero (VARCHAR/TEXT)[^1].**

**Cosa fa l'ERP sotto il cofano quando si salva il carico?**

1. Prende la quantità inserita dal magazziniere (es. 980 kg ricevuti) e la aggiunge alla **Giacenza Reale**.

2. Sottrae la quantità che era stata promessa (i 1000 kg originali) dalla **Giacenza in Arrivo** (che torna a zero per quel prodotto).

3. **Gestione del residuo (il bivio decisionale):** Se la quantità ricevuta è inferiore a quella ordinata, l'ERP mostra una domanda all'utente: *"La merce mancante arriverà con una seconda spedizione o l'ordine è da considerarsi chiuso così?"*

   * *Se arriverà:* Lo stato dell'ordine diventa **"Ricevuto Parziale"**. L'ERP tiene in *Giacenza in Arrivo* la differenza rimanente.

   * *Se il fornitore non la manderà più:* Lo stato dell'ordine diventa **"Ricevuto"** (chiuso). La *Giacenza in Arrivo* residua viene azzerata.

### Come funziona in pratica

Alla conferma dell'ordine il sistema crea **un solo** ricevimento merce con le quantità totali dell'ordine. Questo rimane aperto finché non è tutto ricevuto.  
Quando arriva la prima spedizione parziale (lunedì, 20 pz di Cloro):

1. L'utente apre il ricevimento merce  
2. Registra "ho ricevuto 20 pz di Cloro (lotto LOT-A123) e 10 pz di Flocculante (lotto LOT-B456)"  
3. Conferma questa ricezione parziale

Il sistema aggiorna:

* Lo stock: entra in magazzino quello che è arrivato  
* Il ricevimento merce: segna quante unità sono state ricevute finora  
* L'ordine: passa a partially\_received

Il ricevimento merce rimane aperto, con ancora 10 pz di Cloro da registrare.  
Quando arriva venerdì la seconda spedizione:

1. L'utente riapre lo stesso ricevimento merce (non ne crea uno nuovo)  
2. Registra "ho ricevuto 10 pz di Cloro (lotto LOT-A124)"  
3. Conferma

Il sistema aggiorna lo stock, chiude il ricevimento merce (done), e porta l'ordine a received.  
![][image4]

### Opzione “Registra ricevimento”

**Frontend**: dal dettaglio PO, pulsante "Registra ricevimento". Si apre la schermata di ricevimento, pre-popolata con le righe attese.

**Per ogni riga**, l'utente inserisce:

* Quantità ricevuta (può essere inferiore a quella attesa)

* Per i prodotti con tracking \= lot: numero lotto, data produzione, data scadenza (letti dalla bolla del fornitore)

* Se arrivano più lotti per lo stesso prodotto: pulsante "Aggiungi lotto" che aggiunge una sotto-riga con un secondo lotto

**Quando l'utente conferma il ricevimento**:

1. Il backend crea i stock\_move per ogni riga/lotto

2. Crea o aggiorna i stock\_lot con i dati inseriti

3. Aggiorna stock\_quant (incrementa per prodotto \+ lotto \+ location)

4. Aggiorna qty\_received sulle righe del PO

5. Se tutte le righe sono completamente ricevute: state  received

6. Se almeno una riga è parzialmente ricevuta: state  partially\_received (e rimane disponibile per un secondo ricevimento)

## Tabelle e campi necessari

### Tabella ClientiFornitori

| CAMPO | TIPO | DESCRIZIONE | SCOPO | ALTRE NOTE |
| :---- | :---- | :---- | :---- | :---- |
| **id** | Intero | Chiave primaria autoincrementale | Identificativo unico del soggetto |  |
| **ragione\_sociale** | Testo | Nome dell'azienda o del privato | Visualizzazione in fattura e ordini | Obbligatorio |
| **p\_iva** | Testo | Partita IVA | Fatturazione e controlli fiscali | Deve essere unico |
| **is\_fornitore** | Booleano | Flag (0 \= No, 1 \= Sì) | Filtra i soggetti quando si fa un acquisto | Evita di mostrare clienti privati tra i fornitori |
| **is\_cliente** | Booleano | Flag (0 \= No, 1 \= Sì) | Filtra i soggetti quando si fa una vendita | Un soggetto può avere entrambi i flag a 1 |

### Tabella Prodotti

| CAMPO | TIPO | DESCRIZIONE | SCOPO | ALTRE NOTE |
| :---- | :---- | :---- | :---- | :---- |
| **id** | Intero | Chiave primaria autoincrementale | Identificativo unico del prodotto |  |
| **codice** | Testo | Codice interno dell'articolo | Ricerca rapida da tastiera | Deve essere unico (es: CLO-10KG) |
| **nome** | Testo | Nome commerciale del prodotto | Descrizione nei documenti | Es: "Cloro Pastiglie 10kg" |
| **costo\_base** | Decimale | Prezzo d'acquisto standard | Precompilazione automatica nelle RFQ | Modificabile nella singola riga d'acquisto |
| **fornitore\_default\_id** | Intero | ID del fornitore preferenziale | Suggerisce a chi ordinare la merce | Collegato alla tabella soggetti |
| **giacenza\_reale** | Decimale | Quantità fisica presente in magazzino | Controllo stock reale | Aumenta solo al carico fisico della merce |
| **giacenza\_in\_arrivo** | Decimale | Quantità ordinata ma non ancora arrivata | Calcolo della disponibilità futura | Aumenta alla conferma dell'ordine d'acquisto |

### Tabella Acquisti (Testata unica per RFQ e PO)

| CAMPO | TIPO | DESCRIZIONE | SCOPO | ALTRE NOTE |
| :---- | :---- | :---- | :---- | :---- |
| **id** | Intero | Chiave primaria autoincrementale | Identificativo unico del documento d'acquisto |  |
| **codice\_documento** | Testo | Codice visualizzato del documento | Tracciabilità per l'operatore e il fornitore | Es: RFQ-2026-0001 (cambia in ORDA-2026-0001 alla conferma) |
| **fornitore\_id** | Intero | ID del fornitore dell'ordine | Sapere a chi stiamo inviando l'ordine | Collegato alla tabella soggetti |
| **stato** | Testo | Stato del documento | Gestisce i permessi di modifica e i calcoli | Valori ammessi: bozza\_rfq, inviata\_rfq, confermato\_ordine, ricevuto, ricevuto\_parziale, annullato |
| **data\_creazione** | Timestamp | Data e ora di creazione del record | Tracciamento interno | Inserito in automatico |
| **data\_ordine** | Timestamp | Data di conferma dell'ordine | Validità giuridica dell'ordine d'acquisto | Popolato solo quando si passa a confermato\_ordine |
| **totale\_imponibile** | Decimale | Somma dei costi delle righe | Controllo finanziario dell'ordine | Aggiornato automaticamente ad ogni modifica delle righe |

### TABELLA: acquisti\_righe (Le righe di dettaglio dell'ordine)

| CAMPO | TIPO | DESCRIZIONE | SCOPO | ALTRE NOTE |
| :---- | :---- | :---- | :---- | :---- |
| **id** | Intero | Chiave primaria autoincrementale | Identificativo unico della riga |  |
| **acquisto\_id** | Intero | ID della testata d'acquisto | Collega la riga al suo documento padre | Collegato a acquisti con cancellazione a cascata |
| **prodotto\_id** | Intero | ID del prodotto ordinato | Sapere cosa stiamo comprando | Collegato alla tabella prodotti |
| **quantita\_ordinata** | Decimale | Quantità richiesta al fornitore | Definire il volume dell'acquisto |  |
| **costo\_unitario** | Decimale | Prezzo pattuito per l'acquisto | Calcolo del totale della riga | Sovrascrive il costo\_base del prodotto |

### TABELLA: ricezioni\_merci (Le testate delle bolle di carico)

| CAMPO | TIPO | DESCRIZIONE | SCOPO | ALTRE NOTE |
| :---- | :---- | :---- | :---- | :---- |
| **id** | Intero | Chiave primaria autoincrementale | Identificativo unico del carico |  |
| **acquisto\_id** | Intero | ID dell'ordine d'acquisto di origine | Collegare il carico all'ordine che lo ha generato | Collegato alla tabella acquisti |
| **data\_ricezione** | Timestamp | Data e ora del carico fisico | Storico delle entrate merce | Inserito in automatico al salvataggio |
| **numero\_ddt\_fornitore** | Testo | Numero del documento di trasporto cartaceo | Tracciabilità obbligatoria per legge | Inserito manualmente dal magazziniere |

### TABELLA: ricezioni\_merci\_righe (Il conteggio fisico della merce arrivata)

| CAMPO | TIPO | DESCRIZIONE | SCOPO | ALTRE NOTE |
| :---- | :---- | :---- | :---- | :---- |
| **id** | Intero | Chiave primaria autoincrementale | Identificativo unico della riga di carico |  |
| **ricezione\_id** | Intero | ID della testata di ricezione | Collega la riga alla bolla di carico | Collegato a ricezioni\_merci |
| **prodotto\_id** | Intero | ID del prodotto scaricato | Identificare l'articolo che entra in magazzino | Collegato alla tabella prodotti |
| **quantita\_ricevuta** | Decimale | Quantità effettiva contata e accettata | Aggiornare la giacenza reale | Può differire dalla quantità originariamente ordinata |

## Domande e risposte

* Ma fisicamente, le tabelle ricezioni\_merci (testata e righe) a che servono? Cioè la web app cosa se ne fa? Perché gli servono?  
  - **Ho scritto un paragrafo a parte**  
* L’ERP non può inviare da solo email con le rfq. Come è la procedura per cambiare stato della rfq a “Inviata”? Cosa succede fisicamente nel front-end? L’utente clicca un flag? Un bottone?  
  - **Soluzione link per mandare email e scaricare pdf, bottone per cambiare stato**  
* “Il sistema gli assegna un codice d'ordine sequenziale e non modificabile (es. da RFQ-2026-0004 diventa ORDA-2026-0001)”. È previsto/best practice che l’utente possa scrivere a mano i nomi/codici di preventivi e ordini?  
  - **Si usa 1 riferimento generato dall’erp, 1 riferimento interno aziendale, 1 fornitore**  
* Lotti: come gestisco l’arrivo dei prodotti (cioè le giacenze magazzino) se devo anche tracciare i lotti?  
  - **Ho la risposta, la scrivo sotto.**  
* Qual è la best practice (o meglio, una soluzione che bilancia best practice e praticità) per i prezzi di acquisto? Dove si storano di solito? Si fanno dei listini? Come funziona di solito? Come fanno gli altri erp? Cosa mi consigli?  
  - **Prezzi di acquisto: mai sul prodotto. Sempre nel catalogo fornitore**  
* Come si gestisce il fatto che  
  - I prodotti a magazzino sono storati con una unità di misura che varia a seconda del prodotto (di solito pezzi)  
  - Il prezzo di un prodotto (sia di acquisto che di vendita) dipende dalla sua confezione. Un fusto cloro da 25 kg ha un prezzo che NON è 5 volte il prezzo di un secchio da 5\.  
  - Sia per vendita che per acquisto vorrei dare all’utente la possibiità di mostrare/inserire prezzi secondo una unità di misura a scelta (ad es a pezzo o a kg). Come viene gestita di solito questa cosa? Quale è la best practice negli erp?  
  - **Soluzione: salvo unità di stoccaggio, unità di “preventivo” e quantità per pezzo**

# Aggiunta futura del ListinoAcquisti

Al momento, per salvare i prezzi di acquisto sfruttiamo il trittico di tabelle

* podotti

* prodotti\_fornitori

* prodotti\_fornitori prezzi

**Se un giorno vorrò mettere dei veri e propri listini acquisto, così da avere anche uno storico di tali listini, è fattibile?**

La struttura attuale è già pronta per questo. Non devi stravolgere niente.  
\`prodotti\_fornitori\_prezzi\` ha già \`valido\_dal\` e \`valido\_al\`. Quando un fornitore ti manda un nuovo listino, crei nuove righe con le nuove date di validità. Questo è già, di fatto, uno storico dei prezzi per periodo.

Quello che manca è solo un'entità formale "listino" a cui agganciare quelle righe — un nome, una descrizione, una provenienza.

### Cosa bisogna fare?

Due operazioni, entrambe retrocompatibili:

#### *1\. Aggiungi la tabella \`*listini\_acquisto

| TABELLA | CAMPO  | TIPO  | DESCRIZIONE  |
| :---: | :---: | :---: | :---: |
| listini\_acquisto | **id ** | INTEGER PK  | ** ** |
| listini\_acquisto | fornitore\_id  | INTEGER FK → clienti\_fornitori  |   |
| listini\_acquisto | nome  | VARCHAR(100)  | es. "Listino Rossi Srl H1 2026"  |
| listini\_acquisto | valido\_dal  | DATE  |   |
| listini\_acquisto | valido\_al  | DATE  |   |
| listini\_acquisto | note  | TEXT  |   |
| listini\_acquisto | filepath | TEXT | Se ho dei pdf li carico qui |
|  |  |  |  |

#### *2\. Aggiungi un campo nullable a \`prodotti\_fornitori\_prezzi\`*

| TABELLA | CAMPO  | TIPO  | DESCRIZIONE  |
| :---- | :---- | :---- | :---- |
| prodotti\_fornitori\_prezzi | **listino\_acquisto\_id ** | INTEGER  | FK → listini\_acquisto, nullable  |

Deve essere nullable perché i prezzi inseriti prima dell'introduzione dei listini restano validi così com'erano — non devi migrare niente.

### Come cambia l'algoritmo di ricerca del prezzo

un listino attivo, cade sul comportamento attuale. Zero breaking changes.

21:39

##  **Come funziona oggi**

Quando l'utente aggiunge una riga a un ordine di acquisto, il sistema deve trovare il prezzo giusto. Lo cerca così:

1. Prendi il prodotto scelto (es. Cloro pastiglie 25kg) 

2. Prendi il fornitore scelto (es. Rossi Srl) 

3. Trova le righe in prodotti\_fornitori\_prezzi per quella coppia 

4. Tieni solo quelle ancora valide oggi (valido\_dal \<= oggi \<= valido\_al) 

5. Tra quelle valide, prendi lo scaglione giusto per la quantità ordinata 

Risultato: un prezzo.

### **Come funzionerebbe domani con i listini**

Il sistema determina quale prezzo applicare seguendo una gerarchia di regole logiche ed automatiche basate sul **Fornitore**, la **Data dell'Ordine** (es. oggi) e la **Quantità Ordinata**.

Ecco il flusso esatto che esegue il motore di calcolo quando l'utente seleziona un prodotto o cambia la quantità in una RdP (RFQ):

1\. Ricerca dei Listini Attivi

Il sistema verifica se per il fornitore selezionato esiste un listino in listini\_acquisto valido alla data dell'ordine: 

* **Caso A: Esiste un listino attivo per il fornitore (es. "Listino Rossi Srl 2026")**

  1. Il sistema cerca in prodotti\_fornitori\_prezzi le righe associate a quel prodotto/fornitore che appartengono **specificamente a quel listino** (listino\_acquisto\_id \= ID\_DEL\_LISTINO).

  2. Tra queste, filtra per validità temporale ed esclude gli scaglioni superiori alla quantità ordinata ().

  3. Prende la riga con la  maggiore (lo scaglione più alto applicabile).

  4. **Fallback di sicurezza**: Se per quel prodotto specifico non c'è una riga di prezzo dentro quel listino, il sistema cerca automaticamente tra i **prezzi base** (dove listino\_acquisto\_id IS NULL) per non lasciare il prezzo a 0\.

* **Caso B: NON esiste alcun listino attivo per il fornitore**

  1. Il sistema cerca direttamente in prodotti\_fornitori\_prezzi le righe dove listino\_acquisto\_id IS NULL (i prezzi base/scaglioni standard).

  2. Applica i filtri di data e quantità come sopra, restituendo il prezzo base corrispondente.

### **Zero breaking changes**

Tutto ciò che esiste oggi continua a funzionare esattamente uguale. I prezzi inseriti prima dell'introduzione dei listini hanno listino\_acquisto\_id \= null — il sistema li trova comunque perché quando non c'è un listino attivo usa il comportamento attuale, che non guarda quel campo.

Non bisogna toccare nessun prezzo già inserito. Non bisogna riscrivere nessuna logica esistente. Si aggiunge solo il passo 1 all'inizio.

# 

# Approfondimenti Tecnici e Operativi: Ciclo Acquisti e Magazzino (Gemina ERP)

## Tutti i possibili stati di un ordine di acquisto

Per capire come e quando usare questa correzione, ecco la mappa esatta di tutti gli stati che un documento d'acquisto può assumere all'interno del database:

| STATO (acquisti.stato) | COESISTE CON ALTRI? | SEQUENZIALE? | SIGNIFICATO REALE | AZIONE SULLO STOCK | NOTA OPERATIVA |
| :---- | :---- | :---- | :---- | :---- | :---- |
| **bozza\_rfq** | No | Sì (Inizio) | L'operatore sta compilando la richiesta di offerta. | Nessun impatto. | I campi sono tutti modificabili. |
| **inviata\_rfq** | No | Sì | Il PDF è stato generato e inviato al fornitore. | Nessun impatto. | Le quantità si bloccano. I prezzi restano modificabili per la trattativa. |
| **confermato\_ordine** | No | Sì | L'accordo è chiuso. Diventa un Ordine d'Acquisto (PO). | Aumenta la **Giacenza in Arrivo**. | Il documento è completamente blindato (non modificabile). |
| **ricevuto\_parziale** | No | Sì | È arrivato solo un pezzo della merce ordinata. | Aumenta la **Giacenza Reale** della quota arrivata. Riduce la **Giacenza in Arrivo** della stessa quota. | L'ordine resta aperto in attesa del saldo. |
| **ricevuto** | No | Sì (Fine) | Tutta la merce è arrivata. L'ordine è chiuso con successo. | La **Giacenza in Arrivo** residua va a $0$. | L'ordine è archiviato e pronto per il controllo fattura. |
| **annullato** | No | No | L'ordine è stato cancellato (es. il fornitore non ha la merce). | Sottrae la quantità ordinata dalla **Giacenza in Arrivo** (la azzera). | Usabile in qualsiasi momento prima della ricezione merc |

## A cosa servono fisicamente le tabelle ricezioni\_merci e ricezioni\_merci\_righe?

Se non avessimo queste tabelle e aumentassimo direttamente la giacenza nella tabella prodotti, l'ERP sarebbe cieco. Ecco a cosa servono concretamente a livello di codice e operatività:

**A. Gestione delle Consegne Parziali**

Se ordini 1000 kg di Cloro e il fornitore ti manda oggi 600 kg (con DDT n. 45\) e la settimana prossima 400 kg (con DDT n. 89):

* Senza tabelle di ricezione, potresti solo sovrascrivere la giacenza. Non sapresti mai quanti pezzi di quell'ordine sono arrivati con il primo camion e quanti con il secondo.

* Con queste tabelle, l'ERP sa che l'ordine è collegato a **due eventi di carico distinti**, ognuno con la sua data e il suo numero di documento cartaceo.

**B. Tracciabilità Legale e Controllo Qualità (La "Bolla")**

Nel settore chimico la tracciabilità è tutto. Se un lotto di acido risulta difettoso o pericoloso, devi poter rispondere alla domanda: *"In quale giorno e con quale documento di trasporto (DDT) del fornitore è entrato questo specifico fusto?"*.

* La tabella ricezioni\_merci storicizza l'evento fisico. È la prova digitale che il magazziniere ha firmato la bolla cartacea.

**C. Risoluzione delle Discrepanze Fatture-Bozze**

Quando a fine mese arriverà la fattura differita del fornitore, l'amministrazione non controllerà l'ordine d'acquisto originale (dove magari c'erano scritti 1000 kg), ma controllerà le **ricezioni reali** (i 980 kg effettivamente scaricati) registrate in queste tabelle, per verificare se il fornitore sta fatturando solo ciò che ha realmente consegnato.

## Gestione delle Consegne Parziali: Il flusso dettagliato

Nella rivendita di prodotti chimici, le consegne parziali sono la norma (mancanza di stock del produttore, camion ADR saturi, ecc.). Ecco come l'utente e l'ERP gestiscono questo flusso passo dopo passo.

- \[Ordine Confermato: 100 kg\]  
  * \[Camion Arriva: Scarica solo 60 kg\]  
    * ERP carica 60 kg in Giacenza Reale  
      * ERP lascia 40 kg in Giacenza in Arrivo  
      * Stato Ordine: "Ricevuto Parziale"

**Passo 1: L'Attesa**

L'ordine d'acquisto ORDA-2026-0004 è stato confermato. L'utente ha ordinato **100 Pezzi** di "Cloro Pastiglie Secchio 10kg".

* **A schermo:** Lo stato dell'ordine è "Confermato". Nei dettagli dell'ordine c'è un grande pulsante blu: **"Registra Ricezione Merci"**.

* **Lo stock:** La giacenza\_reale del cloro è 0\. La giacenza\_in\_arrivo è 100\.

**Passo 2: Arriva il primo camion (Consegna Parziale di 60 Pezzi)**

Il corriere scarica solo 60 secchi perché il resto era finito. Il magazziniere clicca su **"Registra Ricezione Merci"**.

* **Cosa mostra lo schermo (UI):** Si apre una schermata di carico che elenca i prodotti dell'ordine. Accanto al cloro, il sistema propone una casella di testo precompilata con il valore di default dell'ordine: \[ 100 \]. C'è anche un campo vuoto obbligatorio: \[ Numero DDT Fornitore \].

* **Cosa fa l'utente:**

  1. Scrive il numero del DDT cartaceo (es. "DDT-9954").

  2. Cancella il 100 nella casella e scrive 60 (la quantità effettivamente contata sul bancale).

  3. Clicca su **"Conferma Ricezione"**.

* **Cosa fa l'ERP (Software):**

  1. Crea una riga nella testata ricezioni\_merci (collegata a ORDA-2026-0004 e con DDT "DDT-9954").

  2. Crea una riga in ricezioni\_merci\_righe con quantità 60\.

  3. Aggiorna la giacenza del prodotto:

     * giacenza\_reale diventa $0 \+ 60 \= 60$

     * giacenza\_in\_arrivo diventa $100 \- 60 \= 40$

  4. L'ERP rileva che $60 \< 100$. Cambia automaticamente lo stato di ORDA-2026-0004 in **"ricevuto\_parziale"**.

**Passo 3: Lo stato intermedio**

L'azienda ha parzialmente la merce in casa e può già iniziare a venderla.

* **A schermo:** Nella lista degli ordini d'acquisto, ORDA-2026-0004 mostra l'etichetta gialla "Ricevuto Parziale". Se l'utente lo apre, vede chiaramente: *Ordinati: 100 | Ricevuti: 60 | Rimanenti in Arrivo: 40*. Il pulsante "Registra Ricezione Merci" è ancora attivo.

**Passo 4: Arriva il secondo camion (I restanti 40 Pezzi)**

La settimana dopo arriva il saldo della merce con un nuovo DDT (es. "DDT-10112"). Il magazziniere clicca di nuovo su **"Registra Ricezione Merci"**.

* **Cosa mostra lo schermo (UI):** La schermata di carico si apre mostrando solo il residuo non ancora consegnato. L'input della quantità è precompilato con il valore residuo: \[ 40 \].

* **Cosa fa l'utente:** Scrive il nuovo numero di DDT ("DDT-10112"), verifica che i pezzi siano effettivamente 40 e clicca su **"Conferma Ricezione"**.

* **Cosa fa l'ERP (Software):**

  1. Crea una seconda riga in ricezioni\_merci (associata allo stesso ordine ma con il nuovo DDT "DDT-10112").

  2. Crea una riga in ricezioni\_merci\_righe con quantità 40\.

  3. Aggiorna la giacenza del prodotto:

     * giacenza\_reale diventa $60 \+ 40 \= 100$

     * giacenza\_in\_arrivo diventa $40 \- 40 \= 0$

  4. L'ERP rileva che la somma di tutte le ricezioni collegate all'ordine ($60 \+ 40$) è uguale alla quantità totale ordinata (100). Cambia lo stato dell'ordine in **"ricevuto"** (chiuso) e nasconde il pulsante per effettuare altri carichi.

## Evitare l’obbligo di usare la schermata “Merce in arrivo”

Se ho capito bene gli ERP, dopo l’invio di un ordine di acquisto, popolano una schermata “Merce in Arrivo”. Quando la merce arriva, l’utente deve usare questa schermata per aggiornare il magazzino.

Ma se non lo vuole fare o non si ricorda di usare questa schermata? Se vuole aggiornare il magazzino a mano?

Nel mondo reale succede spesso: l'utente distratto bypassa il modulo acquisti, va direttamente nella scheda del prodotto "Cloro" e scrive a mano nel campo giacenza: **"Giacenza precedente: 10, nuova giacenza: 20"**.

La Giacenza Reale ora è corretta (20), ma la Giacenza in Arrivo dell'ordine d'acquisto non è mai stata scalata, perché l'utente non ha registrato la ricezione merci. Risultato: la **Giacenza Virtuale** dell'azienda è completamente falsata (crede di avere altra merce in arrivo che in realtà è già in magazzino).

**Esiste un modo per dire “ah sì, le ho già inserite a mano io nel modulo magazzino”? La soluzione (Pratica ed elegante): "La Riconciliazione Manuale"**

Non bloccare l'utente (lo odierebbe), ma dagli una via d'uscita rapida direttamente nella schermata dell'Ordine d'Acquisto (ORDA).

Aggiungi un pulsante rosso chiamato **"Chiudi Ordine senza Carico"** o **"Forza Chiusura Ordine (Riconciliazione)"**.

**Cosa fa l'ERP quando l'utente clicca questo pulsante?**

Mostra un avviso: *"Attenzione: usando questa funzione azzererai la merce in arrivo per questo ordine senza aumentare la giacenza reale. Usalo solo se hai già caricato la merce manualmente a magazzino."*

Se l'utente conferma:

1. Lo stato dell'ordine d'acquisto (acquisti.stato) passa a **"ricevuto"** (chiuso).

2. Per ogni riga di quell'ordine, l'ERP prende la quantità ordinata che era ancora in sospeso e la **sottrae** dalla giacenza\_in\_arrivo del prodotto, azzerandola.

3. **Non tocca** la giacenza\_reale (perché l'utente l'ha già modificata a mano in precedenza).

4. Crea una riga fittizia nella tabella ricezioni\_merci con la nota: *"Riconciliazione manuale per bypass operatore"*, così rimane traccia dell'accaduto.

## Prezzi d'Acquisto: Dove salvarli e come gestirli

Aggiungiamo una tabella d'appoggio snella chiamata fornitori\_prodotti che mappa la relazione tra fornitore, prodotto e l'ultimo prezzo reale negoziato.

**Come si comporta l'interfaccia a schermo?**

1. L'utente crea una nuova RFQ per il fornitore **Chemical Srl** e aggiunge il prodotto **Cloro Pastiglie Secchio 10kg**.

2. Il Javascript del front-end esegue un controllo rapido:

   * Cerca nella tabella fornitori\_prodotti se esiste una riga con prodotto\_id \= Cloro e soggetto\_id \= Chemical Srl.

   * **Se esiste:** Precompila il campo prezzo della riga con il valore ultimo\_prezzo\_negoziato trovato (es. 11.50 €).

   * **Se non esiste:** Precompila il campo usando il costo\_base generico preso dalla scheda prodotto (es. 12.00 €).

3. **La Libertà di Modifica (Cruciale):** Il campo del prezzo d'acquisto sulla riga dell'RFQ è un input di testo **totalmente libero e modificabile**. Se l'utente in quel momento sta negoziando un prezzo speciale di 11.00 €, cancella il valore proposto e scrive a mano 11.00 €. Non c'è alcun blocco.

4. **Auto-Apprendimento al Click:** Quando l'utente clicca su **"Conferma Ordine"** (passaggio da RFQ a Ordine d'Acquisto), il backend dell'ERP fa una query di aggiornamento (UPSERT) sulla tabella fornitori\_prodotti:

   * Se c'era già una relazione per quel fornitore e quel prodotto, aggiorna ultimo\_prezzo\_negoziato \= 11.00.

   * Se non c'era, inserisce una nuova riga impostando il prezzo a 11.00.

Così, la prossima volta che ordinerai lo stesso cloro da quel fornitore, l'ERP si ricorderà in automatico dell'ultimo prezzo di 11.00 €, senza che tu abbia dovuto configurare nulla a mano.

## Prezzi di acquisto a scaglioni

Premessa: al momento il db lavora nell'ipotesi semplificativa che ogni riga in product rappresenti una confezione specifica. "Cloro 5 kg" e "Cloro 10 kg" sono due prodotti distinti. Questa scelta semplifica molto la gestione dei prezzi.

### Cosa sono gli scaglioni

Uno scaglione è un prezzo che si applica solo se ordini almeno una certa quantità. Esempio reale:

| min\_qty | prezzo unitario | significato |
| :---- | :---- | :---- |
| **0** | 8,50 €/pz | da 1 pezzo in su |
| **10** | 7,80 €/pz | da 10 pezzi in su |
| **50** | 7,20 €/pz | da 50 pezzi in su |

Se ordini 8 pezzi di "Cloro 10 kg" dal fornitore X  si applica il prezzo da 0 (8,50 €/pz). Se ordini 12 pezzi  si applica il prezzo da 10 (7,80 €/pz). Si applica sempre lo scaglione con min\_qty più alto che non supera la quantità ordinata.

### Come si popola la tabella product\_supplier\_price

Con la semplificazione "un prodotto \= una confezione", la tabella ha questa forma per "Cloro 10 kg" dal fornitore Brenntag (supplier id 15):

| supplier\_prod\_id  |  min\_qty  |  price  |  valid\_from  |  valid\_to  |
| :---- | :---- | :---- | :---- | :---- |
| **15** | 0 | 8,5 |  2025-01-01  |  null      |
| **15** | 10 | 7,8 |  2025-01-01  |  null      |
| **15** | 50 | 7,2 |  2025-01-01  |  null      |

supplier\_prod\_id \= 15 è il record in product\_supplier per la coppia (Cloro 10 kg, Brenntag)

### L'algoritmo di ricerca del prezzo — passo per passo

Quando l'utente aggiunge una riga all'RFQ (prodotto \= "Cloro 10 kg", fornitore \= Brenntag, qty \= 30):

#### ***Step 1:** Trova il record in product\_supplier per questa coppia.*

product\_id \= 42 (Cloro 10 kg)  
supplier\_id \= 7 (Brenntag)  
 trova supplier\_prod\_id \= 15

#### ***Step 2**: Cerca i prezzi validi oggi*

Cerca i prezzi validi oggi, con min\_qty \<= 30, ordina per min\_qty decrescente, prendi il primo.  
oggi \= 2025-06-10  
candidate valide:

- min\_qty=0,  price=8,50 (0 \<= 30 ✓, valid\_from=2025-01-01 \<= oggi ✓, valid\_to=null ✓)  
  - min\_qty=10, price=7,80 (10 \<= 30 ✓)  
  - min\_qty=50, price=7,20 (50 \<= 30 ✗  scartato)

ordinate per min\_qty DESC: prima viene min\_qty=10

prezzo suggerito: 7,80 €/pz

#### ***Step 3:** Se non trovi niente (nessun prezzo nel catalogo per questa coppia), cerca l'ultimo prezzo pagato:*

cerca in purchase\_order\_line

    join purchase\_order on order\_id \= purchase\_order.id

    dove product\_id \= 42

    e supplier\_id \= 7

    e state \= 'confirmed' (o 'received')

    ordina per order\_date DESC

    prendi il primo

 se trova qualcosa, suggerisci quel prezzo con un'indicazione "ultimo prezzo pagato il GG/MM/AAAA"

#### ***Step 4: Non ci sono prezzi storici***

Se non trovi neanche quello, campo prezzo vuoto. Il sistema mostra: "Nessun prezzo disponibile — inseriscilo manualmente".

### Cosa succede quando la quantità cambia

L'utente ha inserito 30 pezzi (prezzo 7,80). Poi corregge a 55 pezzi. Il frontend, on-blur sulla quantità, rilancia la chiamata al backend per il prezzo suggerito. Il backend trova min\_qty=50  7,20. Il frontend aggiorna il campo prezzo (ma non lo sovrascrive silenziosamente se l'utente l'ha già modificato a mano — mostra invece un avviso: "Il prezzo suggerito per questa quantità è 7,20 €. Aggiorna?").

Questo dettaglio UX è importante: non sovrascrivere mai silenziosamente un valore che l'utente ha inserito a mano.

### Validità temporale (valid\_from / valid\_to)

Il fornitore ti manda il listino 2026 a dicembre 2025\. Lo inserisci con valid\_from \= 2026-01-01. Fino al 31 dicembre si applica ancora il vecchio listino; dal 1 gennaio si applica il nuovo. Non devi toccare niente.

Se il listino precedente aveva valid\_to \= 2025-12-31, il sistema esclude automaticamente quei prezzi da gennaio. Se invece non hai messo valid\_to sul vecchio (come nella tabella sopra con valid\_to \= null), il sistema applicherà il vecchio E il nuovo — e prenderà lo scaglione con min\_qty più favorevole. Questo è un bug potenziale: quando inserisci un nuovo listino, imposta sempre valid\_to sul vecchio.

Soluzione pratica: il form di inserimento prezzi ha un pulsante "Nuova versione listino" che automaticamente imposta valid\_to \= ieri su tutti i prezzi correnti di quella coppia e apre il form per i nuovi. Così l'utente non si ricorda di farlo a mano.

### Come fanno gli ERP best in class

**Odoo**: ha esattamente questo schema (scaglioni \+ validità) nella tabella product.supplierinfo. La ricerca del prezzo è identica all'algoritmo descritto. Non ha però il "suggerimento da ultimo prezzo pagato" out-of-the-box — lo trovi come modulo community o lo implementi tu.

**SAP**: scaglioni multipli con validità, più condizioni complesse (sconti per valore totale, non solo per quantità). Overkill per il tuo caso.

**ERPNext**: identico a Odoo come concetto, ma i listini fornitori si chiamano "Supplier Quotation". Il meccanismo è lo stesso.

**Cosa ti consiglio**: implementa solo min\_qty e valid\_from/valid\_to. Niente sconti percentuali separati, niente sconti per valore totale ordine. Se serve, lo aggiungi dopo — la struttura della tabella lo permette già (c'è già il campo discount nel design).

## Prezzo al Pezzo vs Prezzo al Kg

Per dare all'utente la possibilità di mostrare e negoziare i prezzi sia "Al Pezzo" (es. per fusto) che "Al Kg/Litro" senza rischiare di incasinare i conti del magazzino reale (che deve sempre ragionare in unità di stoccaggio fisiche come il "Pezzo" o il "Fusto"), dobbiamo strutturare l'anagrafica del prodotto in modo impeccabile.

Invece di un generico campo "Peso", definiamo tre parametri matematici chiari nella scheda del prodotto.

| TABELLA | CAMPO | TIPO | DESCRIZIONE | SCOPO | ALTRE NOTE |
| :---- | :---- | :---- | :---- | :---- | :---- |
| **prodotti** | **unita\_stoccaggio** | Testo | Come contiamo il prodotto fisicamente a magazzino | Definire l'unità di misura base | Es: "Secchi", "Fusti", "Taniche" |
| **prodotti** | **unita\_frazionata** | Testo | Unità di misura commerciale del contenuto chimico | Permettere la visualizzazione alternativa | Di solito "Kg" o "Litri" |
| **prodotti** | **quantita\_contenuto** | Decimale | Quanti Kg/Litri ci sono dentro una singola unità di stoccaggio | Effettuare la conversione matematica esatta | Per un fusto da 25 kg, il valore è 25.000 |

**I prezzi nel DB sono SEMPRE riferiti alla UoM di Stock:** Se l'utente vuole vedere il prezzo al kg nel preventivo, l'interfaccia (o l'API) prende il prezzo del Pezzo (€17.50) e lo divide per il peso\_netto del prodotto (5kg), mostrando €3.50/kg.

**Esempio Pratico di Inserimento Dati (Scheda Prodotto)**

Creiamo il prodotto: **"Cloro Pastiglie Fusto 25 Kg"**

* unita\_stoccaggio \= "Fusti"

* unita\_frazionata \= "Kg"

* quantita\_contenuto \= 25.000

L'utente che inserisce la scheda prodotto ha subito chiaro cosa sta inserendo: sta dicendo al sistema che 1 fusto contiene esattamente 25 kg di materiale.

**Come funziona la schermata di Vendita o Acquisto? (UI/UX)**

L'operatore sta preparando un preventivo d'acquisto per **10 Fusti** di Cloro.

**Scenario A: Inserimento Standard (Al Pezzo/Fusto)**

* L'operatore digita la quantità: \[ 10 \] Fusti.

* Il sistema mostra il prezzo proposto: \[ € 50.00 \] Al Fusto.

* Il totale riga calcolato è di **€ 500.00**.

* Sotto il campo prezzo, l'ERP mostra una scritta informativa in grigio chiaro:

*(pari a € 2.00 / Kg)*

**Scenario B: Trattativa al Kg (L'utente vuole inserire il prezzo al Kg)**

Il fornitore dice al telefono: *"Ti faccio un prezzo speciale di 1.80 € al Kg"*.

1. Accanto al campo prezzo compare un piccolo switch o pulsante di visualizzazione: **\[ €/Kg \]**.

2. Quando l'operatore clicca il pulsante, il Javascript del front-end modifica visivamente la riga senza ricaricare la pagina:

   * Nasconde l'input del prezzo al fusto e mostra l'input: Prezzo al Kg: \[ € 1.80 \].

   * Il Javascript esegue istantaneamente il calcolo inverso e aggiorna il prezzo reale:

Prezzo al Fusto=Prezzo al Kg 1.80quantita\_contenuto 25.00\=45.00 €

3. A schermo, l'operatore vede entrambi i dati chiari: sta inserendo "1.80 €/Kg" che equivale a "45.00 €/Fusto".

4. **Salvataggio nel Database:** Quando l'utente preme invia, nel database viene salvato solo il dato standard pulito: quantita \= 10 e costo\_unitario \= 45.00.

**Perché questo metodo è il migliore in assoluto?**

* **Semplicità di calcolo:** Il database non deve gestire due unità di misura diverse durante gli inventari o i calcoli di valore del magazzino. C'è solo una colonna di giacenza reale che conta i pezzi (fusti).

* **Trasparenza per l'operatore:** Chi inserisce i dati ha la certezza matematica di cosa sta inserendo, riducendo a zero il rischio di confondere il prezzo del singolo fusto con il prezzo al chilo.

## Prezzo al pezzo v2

Il punto chiave, nei chimici, è separare “UoM di movimento” da “UoM di prezzo” e da “UoM di contenuto”.

Per i chimici, però, la vera best practice non è solo “una UoM per prodotto”, ma **separare 3 concetti diversi**:

1. **UoM di stock / movimentazione**  
   Quella con cui carichi e scarichi a magazzino.  
   Esempio: pz, kg, l. 

2. **UoM commerciale / di vendita o acquisto**  
   Quella con cui l’utente ragiona sul documento.  
   Esempio: vendi “tanica”, compri “kg”, mostri il prezzo “€/l”. 

3. **UoM di contenuto**  
   Quella che descrive quanto principio attivo o prodotto contiene l’imballo.  
   Esempio: una tanica da 5 kg, un fusto da 200 l.

**Per i chimici, kg ↔ l non è una conversione UoM “pura”**

Questa è la parte più importante.

Tra **kg** e **l** non c’è una conversione universale, perché dipende da:

* densità 

* temperatura 

* concentrazione 

* eventualmente purezza 

Quindi non metterei mai una conversione globale kg ↔ l nella tabella UoM come se fosse g ↔ kg.

La best practice è:

* **conversioni globali solo dentro la stessa categoria**  
  esempio g ↔ kg, ml ↔ l 

* **conversioni massa-volume solo a livello prodotto**, solo se quel prodotto le supporta  
  esempio: densità \= 1.18 kg/l

### Come strutturerei il dato

**Tabella prodotto**

Oltre ai tuoi campi, aggiungerei qualcosa del genere:

* stock\_uom\_id → UoM di inventario / movimentazione 

* content\_uom\_id → kg, l, g, ml, ecc. 

* content\_qty → quantità contenuta in 1 unità stock 

* sales\_uom\_id opzionale → UoM preferita di vendita 

* purchase\_uom\_id opzionale → UoM preferita di acquisto 

* density opzionale → utile se vuoi convertire massa/volume 

* density\_uom / condizioni di riferimento, se servono 

Esempio:

* prodotto: “Acido cloridrico 33% \- tanica 25 l” 

* stock\_uom \= pz 

* content\_uom \= l 

* content\_qty \= 25 

* sales\_uom \= l o pz, a seconda del modo commerciale 

* purchase\_uom \= pz

## Codici Doumento (RFQ, Documento acquisti)- Dettagli e criticità

In verità ogni documento deve avere almeno tre campi

1. Codice progressivo, unico, generato da ERP. Quello usato dall’ERP  
2. Codice riferimento interno – per comodità e ricerca dell’ufficio acquisti  
3. Codice riferimento fornitore – per comodità e ricerca dell’ufficio acquisti

#### *Come generare il codice progressivo dei Documenti*

Un dettaglio tecnico che causa problemi se fatto male.

Il numero documento (RFQ/2025/0001) deve essere:

* Unico  
* Progressivo  
* Non generato con MAX(id) \+ 1 (race condition se due utenti salvano simultaneamente)

Soluzione per SQLite: una tabella sequence con una riga per tipo di sequenza, aggiornata dentro una transazione BEGIN IMMEDIATE. Il backend legge il numero corrente, incrementa, salva, tutto dentro la transazione.

| Sequence |
| :---- |
| **name (es. "purchase\_order")** |
| **prefix (es. "RFQ/")** |
| **year (es. 2025\)** |
| **padding (es. 4  "0001")** |
| **next\_number (es. 42\)** |

Ogni anno il next\_number riparte da 1\. Il backend gestisce questo automaticamente confrontando l'anno corrente con quello salvato.

Forzare l'ufficio acquisti a usare *unicamente* il codice progressivo generato dall'ERP (es. ORDA-2026-0001) è frustrante e limita l'operatività reale. Spesso gli utenti vogliono registrare documenti usando codici storici o sigle concordate direttamente a voce con i fornitori.

**Assolutamente NO.** L'utente non deve MAI poter scrivere o modificare a mano i codici sequenziali come RFQ-2026-0004 o ORDA-2026-0001.

**Perché è una pessima idea lasciarli modificare?**

1. **Integrità del database:** Se l'utente scrive un codice già esistente per errore, SQLite restituirà un errore di chiave duplicata (UNIQUE constraint failed) bloccando l'applicazione.

2. **Rottura dei collegamenti cartacei:** Se stampi un ordine con scritto ORD-01 e poi l'utente lo rinomina in ORD-01-BIS a sistema, perdi la corrispondenza univoca.

**Come si fa di solito (La soluzione bilanciata): si adotta un approccio a doppio codice:**

1. **Codice ERP (Blindato): Generato in automatico (es. ORDA-2026-0001). Serve al database per non avere doppioni e mantenere le relazioni pulite.**

2. **Codice Personalizzato / Riferimento Interno (Libero): Un campo di testo indicizzato che l'utente può compilare a mano come preferisce.**

**Come si comporta l'interfaccia a schermo?**

* Quando l'utente crea una RFQ, il campo Codice ERP mostra \[ Generato in Automatico \] ed è grigio (sola lettura).

* Accanto, c'è un campo editabile chiamato **Riferimento Interno / Codice Preventivo** (es. \[ Preventivo-Cloro-2026-Bis \]).

* Nelle tabelle di ricerca dell'ERP, l'utente può cercare gli ordini digitando *sia* il codice dell'ERP (ORDA-2026-0001) *sia* il proprio codice personalizzato (Preventivo-Cloro-2026-Bis). L'ERP esegue la ricerca su entrambi i campi.

## Gestione dei Lotti (Tracciabilità dei Prodotti Chimici)

Nel settore chimico, la giacenza totale di un prodotto non basta: devi sapere come quella giacenza è divisa per **Lotto (Batch)** e quando scade.

**Come si modifica il database per gestire i lotti?**

Dobbiamo introdurre una tabella lotti e modificare la riga di ricezione merci per accogliere questo dato quando il magazziniere scarica il camion.

![][image5]

### Flusso acquisti tenendo conto della gestione lotti

**Passo 1: L'Attesa**

L'ordine è confermato. Il prodotto "Cloro" ha una spunta attiva nell'anagrafica prodotti chiamata richiede\_lotto \= 1\.

**Passo 2: Arriva la merce nel piazzale**

Il magazziniere scarica i 10 secchi di cloro e clicca su **"Registra Ricezione Merci"**.

* **Cosa mostra lo schermo (UI):** L'interfaccia mostra la riga del cloro. Poiché il sistema sa che questo prodotto richiede la tracciabilità, sotto la riga del prodotto si apre un sotto-modulo specifico per i lotti:

**Prodotto:** Cloro Pastiglie 10kg — **Ordinati:** 10 pezzi — **Ricevuti:** \[ 10 \]

── *Dettaglio Lotti Ricevuti* ──

* **Codice Lotto:** \[ \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_ \] *(campo di testo vuoto)*

  * **Data Scadenza:** \[ DD/MM/YYYY \] *(selettore data)*

  * *Pulsante: \[ \+ Dividi in più Lotti \] (nel caso in cui lo stesso carico contenga lotti diversi)*

**Passo 3: L'azione dell'utente**

Il magazziniere legge l'etichetta sul fusto:

1. Digita il codice letto sul fusto: LOT-2026-01 nel campo codice lotto.

2. Inserisce la data di scadenza stampata: 31/12/2028.

3. Clicca su **"Conferma Carico"**.

**Passo 4: Cosa fa il software sotto il cofano**

1. Crea la testata di carico in ricezioni\_merci e la riga in ricezioni\_merci\_righe.

2. Registra una nuova riga nella tabella lotti impostando il codice LOT-2026-01, la data di scadenza, e impostando sia quantita\_iniziale che quantita\_attuale a 10\.

3. Nella tabella ricezioni\_merci\_righe, oltre a salvare che sono stati scaricati 10 pezzi, memorizza l'ID del lotto appena creato (collegando fisicamente il DDT del fornitore a quel lotto).

4. Aggiorna la giacenza\_reale a 10\.

| TABELLA | CAMPO | TIPO | DESCRIZIONE | SCOPO | ALTRE NOTE |
| :---- | :---- | :---- | :---- | :---- | :---- |
| **lotti** | **id** | Intero | Chiave primaria | Identificativo univoco del lotto |  |
| **lotti** | **prodotto\_id** | Intero | ID del prodotto | Collegare il lotto al prodotto chimico | Chiave esterna su prodotti |
| **lotti** | **codice\_lotto** | Testo | Codice alfanumerico del lotto | Identificare la partita di produzione | Es: "LOT-2026-A12" (spesso scritto sul fusto) |
| **lotti** | **data\_scadenza** | Data | Data di scadenza del prodotto | Sapere quando il prodotto chimico perde efficacia | Obbligatorio per cloro e reagenti liquidi |
| **lotti** | **quantita\_iniziale** | Decimale | Quantità caricata all'arrivo | Storico del lotto | Espressa nell'unità di misura base |
| **lotti** | **quantita\_attuale** | Decimale | Quantità ancora disponibile di quel lotto | Scalata ad ogni vendita | Quando arriva a zero, il lotto è esaurito |

### Aggiunte a tabella: ricezioni\_merci\_righe (Aggiornata per registrare i lotti)

Per fare entrare la merce tracciata, il magazziniere deve inserire il lotto durante il carico.

| TABELLA | CAMPO | TIPO | DESCRIZIONE | SCOPO | ALTRE NOTE |
| :---- | :---- | :---- | :---- | :---- | :---- |
| **ricezioni\_merci\_righe** | *\[Campi precedenti...\]* | ... | ... | ... | Vedi tabella precedente |
| **ricezioni\_merci\_righe** | **lotto\_inserito** | Testo | Lotto digitato dal magazziniere | Creare o associare il lotto a sistema | Se il lotto non esiste, l'ERP lo crea nella tabella lotti |
| **ricezioni\_merci\_righe** | **scadenza\_inserita** | Data | Data di scadenza scritta sul fusto | Registrare la shelf-life | Inserita a mano durante il controllo fisico |

**Come si muovono le giacenze con i lotti?**

Quando il magazziniere salva il carico di 10 fusti inserendo il lotto L123:

1. L'ERP aumenta la giacenza\_reale nella tabella prodotti di 10\.

2. L'ERP inserisce una riga nella tabella lotti con codice\_lotto \= 'L123', quantita\_attuale \= 10 e prodotto\_id \= X.

### Come gestire i lotti se non abbiamo una data di scadenza?

Nel settore chimico, molti lotti industriali non hanno una vera data di scadenza stampata, ma solo il codice del lotto di produzione. La soluzione tecnica è semplicissima ed è la best practice del settore:

* Nella tabella lotti, il campo data\_scadenza deve essere **impostato come NULLABLE** (può cioè essere lasciato vuoto / NULL).

* **A schermo (UI):** Se il prodotto richiede il lotto, il magazziniere scriverà solo il codice lotto (es. LOT-2026-01). Il campo "Data Scadenza" sarà presente ma opzionale. Se è vuoto, l'ERP salverà NULL nel database. L'architettura rimane comunque pronta per il futuro nel caso in cui decidiate di inserire le scadenze.

**2\. Lo "Sheet" Completo: Schema Tabelle per il Ciclo Acquisti**

## Problema multi-fornitore

La domanda centrale è: **Ha senso inserire prodotti associati a fornitori diversi in una singola Richiesta di Offerta (RFQ)?**

Vediamo come si comportano i diversi sistemi e qual è la scelta migliore per un ERP snello.

### Opzione A: Lo Standard Enterprise (SAP / NetSuite) – Flusso Rigido

* **Come funziona:** Esiste una fase preliminare chiamata *Richiesta di Acquisto (Purchase Requisition \- PR)*, che è un documento puramente interno. La PR raccoglie i fabbisogni (es. "servono 100 kg di cloro e 50 kg di acido"). Questa PR viene poi "esplosa" e il sistema genera automaticamente RFQ distinte, inviate solo ai fornitori abilitati per quei prodotti secondo tabelle di corrispondenza rigide.  
* **Pro:** Controllo totale, automazione dei processi su larga scala, zero errori di acquisto da fornitori non autorizzati.  
* **Contro:** Rigidità estrema. Se vuoi derogare per motivi di trasporto o urgenza, devi modificare le anagrafiche di acquisto del prodotto prima di poter emettere l'ordine. Richiede una mole di tabelle di configurazione (Fornitore x Prodotto x Prezzo x Lotto Minimo) insostenibile per un team snello.

### Opzione B: L'Approccio Flessibile (Odoo) – Relazione Aperta

* **Come funziona:** L'anagrafica prodotto supporta un elenco di fornitori alternativi (product.supplierinfo). In fase di creazione manuale di una RFQ, il sistema non blocca l'inserimento di alcun prodotto: se il fornitore in testata non è tra quelli censiti sul prodotto, il sistema lo permette, limitandosi ad applicare il prezzo di default o chiedendo l'inserimento manuale del prezzo.  
* **Pro:** Flessibilità operativa immediata. Ottimo per mercati volatili o con forti vincoli logistici.  
* **Contro:** Rischio di "sporcare" i dati storici se l'operatore seleziona un fornitore errato per distrazione.

### Scelta per QUACK ERP

Per una ditta che vende prodotti chimici (spesso in ADR), la flessibilità dell'Opzione B è vitale per un motivo preciso: **il consolidamento logistico**.

**La Regola del Sistema Gemina:**

1. L'anagrafica prodotto definisce **un solo** fornitore\_default\_id e un costo\_base.  
2. L'interfaccia di inserimento RFQ permette di cercare e aggiungere **qualsiasi** prodotto a catalogo.  
3. Se il prodotto inserito ha un fornitore\_default\_id diverso dal fornitore della RFQ, il sistema mostra un **alert visivo non bloccante** (es. sfondo giallo sulla riga e badge ⚠️ Fornitore abituale: Fornitore A).  
4. L'operatore può forzare l'acquisto inserendo il prezzo pattuito con il nuovo fornitore.

## Gestione dell’IVA

### La Regola d'Oro del B2B: Prezzi sempre Imponibili (Netti)

Nei gestionali professionali e nelle relazioni commerciali B2B, **tutti i prezzi salvati nel database, negoziati con i fornitori o offerti ai clienti devono essere tassativamente al netto dell'IVA (Prezzi Imponibili).**

Non si memorizzano mai i prezzi ivati (lordi) nel database. Ecco perché:

1. **Le aliquote cambiano nel tempo:** Se lo Stato decide di spostare l'IVA di un acido dal $22\\% al $10\\%, e tu hai memorizzato a database il prezzo ivato, dovrai ricalcolare a mano l'intero listino per non rimetterci margini. Se invece salvi il netto, l'ERP si adeguerà all'istante cambiando solo l'aliquota di calcolo.

2. **Esenzioni e Reverse Charge:** Nelle vendite o acquisti industriali, potresti comprare o vendere in esenzione d'IVA (es. esportazioni extra-UE, Reverse Charge per pulizie/servizi, o clienti con dichiarazione d'intento). In questi casi l'aliquota reale applicata sulla riga del documento diventa 0%. Se il prezzo memorizzato fosse ivato, saresti bloccato.

### Dove salvare l’IVA nel DB (I tre livelli di dati)

Per gestire l'IVA senza complicare lo schema, l'imposta deve essere tracciata su tre livelli distinti del database.

#### *Livello A: L'Anagrafica Prodotto (L’iva di “default” suggerita per vendere al cliente)*

Ogni prodotto chimico ha un'aliquota IVA standard applicata dallo Stato (solitamente il 22%, ma potrebbero esserci prodotti al 10% o al 4%).

* **Dove si salva:** Nella tabella prodotti come campo aliquota\_iva\_default.

* **A cosa serve:** Funziona come un "suggeritore". Quando l'utente inserisce quel prodotto in un preventivo o in un ordine d'acquisto, l'ERP legge questo valore e lo propone in automatico sulla riga, evitando all'utente di scriverlo ogni volta.

#### *Livello B: Le Righe dei Documenti (La Storicizzazione)*

**L'aliquota IVA reale applicata a una riga di acquisto o di vendita deve essere salvata fisicamente sulla riga stessa** (acquisti\_righe e vendite\_righe).

* **Perché lo facciamo?** Se nel 2026 vendi il cloro al 22% di IVA e nel 2028 lo Stato cambia l'aliquota al 25%, l'anagrafica del cloro verrà aggiornata al 25%. Se l'amministrazione riapre o stampa un vecchio preventivo del 2026, l'ERP deve mostrare ancora il 22%, non il 25%. Salvando l'aliquota direttamente sulla riga della transazione (es. aliquota\_iva \= 22.00), blindiamo la correttezza storica del documento.

#### *Livello C: Le Testate dei Documenti (Il Riepilogo Calcolato per velocizzare le query)*

Nelle testate dei documenti (acquisti e vendite) salviamo i totali calcolati per velocizzare le query di ricerca e la generazione dei PDF.

* **Cosa salviamo:** totale\_imponibile (somma pura dei netti), totale\_iva (somma dell'imposta calcolata su ogni riga) e, se utile, totale\_documento (imponibile \+ IVA).

#### *Approfondimenti dettagli*

1) **Campo ultimo\_prezzo\_negoziato in fornitori\_prodotti**

* **Come gestirlo:** Deve essere **rigorosamente imponibile (senza IVA)**.

* **Perché?** L'accordo commerciale con il fornitore riguarda sempre il valore della merce escluso tasse. L'IVA associata a quel prodotto verrà calcolata dinamicamente sulla riga dell'RFQ prendendo l'aliquota\_iva\_default dal prodotto. Non serve una colonna IVA in questa tabella di transizione.

2) **Listini Prezzi Futuri**

* **Come gestirlo:** Tutti i prezzi di listino futuri che salverai a database (es. listini personalizzati per clienti VIP o stagionali) dovranno memorizzare solo valori netti/imponibili.

3) **Integrazione Futura WooCommerce (Punto 2 del progetto)**

WooCommerce permette di inserire i prezzi a backend sia Ivati che Non Ivati.

* **La Best Practice di allineamento:** Configura WooCommerce per lavorare con i prezzi **esclusi IVA a backend** (esattamente come l'ERP). In questo modo, l'API di WooCommerce riceverà dall'ERP il prezzo imponibile puro e si occuperà autonomamente di mostrare il prezzo ivato al consumatore finale sul sito web. Questo elimina qualsiasi problema di arrotondamento dei decimali durante la sincronizzazione delle giicenze e dei prezzi.

#### *Come lavora l'Interfaccia Utente (UI/UX) con l'IVA*

Quando l'utente compila un documento (ad esempio un preventivo cliente o un'RFQ d'acquisto):

1. **Inserimento riga:** L'operatore seleziona il prodotto "Cloro Fusto 25 Kg".

2. **Auto-popolamento:** L'interfaccia inserisce automaticamente il prezzo imponibile (es. 45.00 €) e l'aliquota IVA presa dall'anagrafica del prodotto (es. \[ 22 \] % in una casellina di testo modificabile).

3. **Calcolo istantaneo in Javascript (Front-end):** Mentre l'utente digita la quantità (es. 2 fusti), il Javascript calcola all'istante sia il totale imponibile che l'imposta per quella riga:

Totale Imponibile Riga=2×45.00\=90.00 €Imposta IVA Riga=90.00×22100\=19.80 €

4. **Il riepilogo a fondo pagina:** In fondo alla schermata, una spalla laterale mostra chiaramente la scomposizione della transazione per evitare malintesi amministrativi:

**Imponibile totale:** 90.00 €

**IVA (22%):** 19.80 €

**Totale Documento (Ivato):** 109.80 €

## Cancellazione e modifiche a Ordini di Acquisto

**1\. Cancellazione**: un PO confermato non si cancella mai fisicamente. Si mette state \= cancelled e si lascia lì. Se devi riordinare, crei un nuovo ordine. Questo vale anche per le righe: no DELETE su righe di ordini confermati.

**2\. Modifiche ai prezzi post-conferma**: non si fanno. Se il fornitore cambia prezzo dopo la conferma, annulli e rifai. Il prezzo del PO è il prezzo a cui hai comprato — è un dato storico.

**3\. Multi-valuta**: per ora ignora. Tieni tutto in EUR, aggiungi un campo currency alla tabella ma non implementare la conversione. Quando servirà, il campo è già lì.

### Per gli ordini non ancora confermati (draft / sent)

In questi stati l'ordine non ha effetti su nessun'altra parte del sistema. Nessuno stock è cambiato, nessun impegno formale è stato preso (o quasi).

**Best practice: cancellazione fisica per i draft, soft delete per i sent.**

* draft: l'utente può eliminare fisicamente l'ordine. È una bozza — non è stata inviata a nessuno, non esiste formalmente. DELETE dalla tabella, DELETE a cascata sulle righe.

* sent: tecnicamente potresti eliminare fisicamente anche questo, ma è già stata inviata (anche solo via email) al fornitore. Meglio tenerla come record storico in stato cancelled. Così se il fornitore ti richiama a distanza di settimane, hai traccia di cosa era stato richiesto.

Alcuni ERP (Odoo incluso) consentono l'eliminazione fisica solo degli RFQ mai inviati, e gestiscono gli altri con cancelled. È la scelta giusta.

### Per gli ordini confermati (confirmed e oltre)

Un PO confermato non si elimina mai fisicamente. Punto. I motivi sono pratici, non teorici:

1. **Audit trail**: il fornitore ha ricevuto il tuo ordine. Se l'ordine scompare dal tuo sistema, non puoi rispondere a contestazioni.

2. **Stock\_picking**: alla conferma è stato creato un picking. Se elimini il PO senza eliminare il picking, hai un'inconsistenza nel DB. Se elimini entrambi, perdi traccia.

3. **Storico prezzi**: il PO confermato contribuisce allo storico prezzi in product\_supplier\_price. Se lo elimini, perdi quel dato.

La transizione confirmed  cancelled imposta state \= "cancelled" e basta. Il record rimane, le righe rimangono, il picking (se esiste) passa a cancelled.

### Cosa può fare l'utente con un ordine cancellato

Un ordine cancelled è in sola lettura. L'utente può:

**1\. Consultarlo** Può aprirlo, leggerlo, stamparlo (o generare il PDF). Utile per capire perché era stato creato, a che prezzo, con quale fornitore.

**2\. Duplicarlo ("Crea copia")** Il pulsante più utile di tutti. Crea un nuovo ordine in draft con le stesse righe, lo stesso fornitore, gli stessi prezzi (non necessariamente validi ancora — il sistema segnala se qualcosa è cambiato). L'utente parte da dove era arrivato, senza riscrivere tutto. Questo è lo standard Odoo e funziona bene.

**3\. Niente altro.** Non può rimetterlo in draft. Non può modificarlo. Non può "de-cancellarlo".

| Stato                 |  Eliminazione fisica  |  Cancellazione soft      |  Note                               |
| :---- | :---- | :---- | :---- |
|  \`draft\`               |  ✅ permessa           |  non necessaria                   |  nessun effetto su altri sistemi    |
|  \`sent\`                |  ❌ sconsigliata       |  ✅ preferita                      |  era stata comunicata al fornitore  |
|  \`confirmed\`           |  ❌ mai                |  ✅ solo se no picking confermati  |  audit trail                        |
|  \`partially\_received\`  |  ❌ mai                |  ❌ bloccata                       |  ci sono già movimenti di stock     |
|  \`received\`            |  ❌ mai                |  ❌ bloccata                       |  ordine completato                  |
|  \`cancelled\`           |  ❌ mai                |  — stato finale                   |  solo lettura, duplicabile          |

###  Cancellazione di ordine acquisto DOPO conferma

Ci sono tre scenari molto diversi tra loro.

#### *Scenario 1: annulli prima che arrivi qualsiasi merce*

Il ricevimento merce è ancora in stato draft — nessuna merce è entrata in magazzino.

Cosa fa il sistema:

* Mette l'ordine a cancelled

* Mette anche il ricevimento merce a cancelled

* Fine. Nessun problema, nessuna complicazione.

Lo stock non è stato toccato, quindi non c'è niente da correggere.

#### *Scenario 2: annulli dopo che è arrivato tutto*

L'ordine è già a received, il ricevimento merce è già done. Tutta la merce è in magazzino.

Il sistema non ti permette di annullare. Non ha senso annullare un ordine già completamente ricevuto — la merce è già fisicamente in magazzino, già nelle giacenze, potenzialmente già usata o rivenduta. L'ordine è storia.

Se c'è un errore (hai ricevuto merce che non dovevi), il meccanismo non è "annullare l'ordine" ma creare una rettifica di magazzino separata. Funzionalità diversa, per ora fuori scope.

#### *Scenario 3: annulli dopo che è arrivata solo una parte della merce*

Questo è il caso difficile. L'ordine è partially\_received. Hai ricevuto 20 pz di Cloro, i restanti 10 non arriveranno più.

Il ricevimento merce è ancora aperto, ma non è vuoto — registra già che 20 pz sono entrati in magazzino. Quei 20 pz sono reali, le giacenze sono già state aggiornate.

**Il sistema blocca l'annullamento** con un messaggio:

"Non puoi annullare questo ordine. Risultano già ricevute 20 pz di Cloro 10 kg (lotto LOT-A123, arrivate il 15/06/2025)."

In pratica questo scenario quasi non esiste: se hai già ricevuto parte della merce, la tieni. Quello che fai è aggiornare la quantità attesa sulla riga dell'ordine (da 30 a 20), il sistema chiude il ricevimento merce e porta l'ordine a received.

| Situazione | Annullamento possibile? | Cosa fa il sistema |
| :---- | :---- | :---- |
| Nessuna merce ricevuta | ✅ Sì | Mette ordine e ricevimento merce a cancelled |
| Merce parzialmente ricevuta | ❌ No | Blocca con messaggio di errore |
| Tutta la merce ricevuta | ❌ No | Blocca: ordine già completato |

## Tabella Sequenze (per la generazione dei codici documenti)

La tabella sequenze serve per generare numeri documento (di acquisto e vendita) univoci e progressivi (es. RFQ/2025/0001) senza buchi e senza duplicati, anche se due utenti salvano nello stesso momento.

### IL PROBLEMA CHE RISOLVE

Il modo ingenuo di generare un numero progressivo è:

1. leggi il numero più alto in ordini\_acquisto

2. aggiungi 1

3. salva il nuovo ordine con quel numero

Questo ha un bug: se due utenti fanno questa operazione nello stesso istante, leggono entrambi lo stesso numero massimo, aggiungono 1 entrambi, e creano due ordini con lo stesso numero. La tabella sequenze risolve questo con un meccanismo di lock.

### COM’È FATTA LA TABELLA

Un record per ogni tipo di documento:

| id | tipo | prefisso | anno | numero\_corrente | padding |
| :---- | :---- | :---- | :---- | :---- | :---- |
| **1** | ordine\_acquisto | RFQ/ | 2025 | 88 | 4 |
| **2** | ricevimento\_merce | RIC/ | 2025 | 23 | 4 |
| **3** | preventivo | PREV/ | 2025 | 41 | 4 |
| **4** | ordine\_cliente | ORD/ | 2025 | 107 | 4 |

Il campo numero\_corrente contiene l’ultimo numero già usato. Il prossimo sarà numero\_corrente \+ 1\.

### COME SI GENERA UN NUMERO — PASSO PER PASSO

Esempio: l’utente salva un nuovo ordine di acquisto.

#### *PASSO 1 — Apri una transazione con lock*

BEGIN IMMEDIATE

IMMEDIATE in SQLite significa: prendo il controllo esclusivo del database adesso. Nessun altro può scrivere finché non ho finito. Questo è il meccanismo che impedisce i duplicati.

#### *PASSO 2 — Leggi il record della sequenza*

SELECT \* FROM sequenze WHERE tipo \= 'ordine\_acquisto'

Risultato:

prefisso \= 'RFQ/'  
anno \= 2025  
numero\_corrente \= 88  
padding \= 4

#### *PASSO 3 — Controlla se l’anno è cambiato*

anno\_oggi \= 2025

se anno\_oggi \!= anno salvato (2025):  
→ numero\_corrente \= 0 (riparte da zero)  
→ aggiorna anno \= anno\_oggi

In questo caso l’anno coincide, quindi numero\_corrente rimane 88\.

#### *PASSO 4 — Incrementa e salva*

nuovo\_numero \= 88 \+ 1 \= 89

UPDATE sequenze  
SET numero\_corrente \= 89  
WHERE tipo \= 'ordine\_acquisto'

#### *PASSO 5 — Assembla il numero documento*

prefisso \= "RFQ/"  
anno \= "2025"  
numero \= "89" → con padding 4 → "0089"

risultato \= "RFQ/2025/0089"

#### *PASSO 6 — Salva l’ordine con quel numero*

INSERT INTO ordini\_acquisto (numero, ...)  
VALUES ('RFQ/2025/0089', ...)

#### *PASSO 7 — Chiudi la transazione*

COMMIT

Da questo momento altri utenti possono scrivere. Il prossimo che arriverà troverà numero\_corrente \= 89 e genererà 0090\.

#### *COSA SUCCEDE SE DUE UTENTI SALVANO NELLO STESSO ISTANTE*

Utente A e Utente B premono “Salva” contemporaneamente.

* Utente A arriva per primo: esegue BEGIN IMMEDIATE, prende il lock.

* Utente B arriva mezzo secondo dopo: prova BEGIN IMMEDIATE, SQLite lo mette in attesa.

* Utente A finisce: COMMIT. Il lock si libera.

* Utente B riparte: legge numero\_corrente \= 89, genera 0090\. Nessun duplicato.

#### *COSA SUCCEDE A CAPODANNO*

È il 1° gennaio 2026\. L’utente salva il primo ordine dell’anno.

anno\_oggi \= 2026  
anno salvato \= 2025

Sono diversi:

→ numero\_corrente \= 0 (si azzera)  
→ anno \= 2026 (si aggiorna)

Il numero generato sarà RFQ/2026/0001. Il contatore riparte da 1 ogni anno.

#### *COSA SUCCEDE SE IL SALVATAGGIO FALLISCE DOPO AVER INCREMENTATO*

Scenario: il numero è stato incrementato a 89, ma il salvataggio dell’ordine fallisce.

Poiché tutto è dentro la stessa transazione, il COMMIT non viene eseguito. SQLite esegue automaticamente un ROLLBACK, che riporta numero\_corrente a 88\.

Il numero 89 non è mai esistito. Il prossimo ordine riprenderà da 89\.

#### *IN BREVE*

BEGIN IMMEDIATE  
leggi sequenza  
se anno cambiato → azzera contatore  
incrementa contatore  
aggiorna sequenza  
assembla numero (prefisso \+ anno \+ numero con padding)  
salva documento con quel numero  
COMMIT

La tabella sequenze ha un record per tipo di documento. Ogni record tiene il conto separato. Aggiungere un nuovo tipo di documento è aggiungere una riga alla tabella, senza toccare il codice.

# Appunti tecnici per il back-end

## Come è fatta l'API backend (struttura minima)

Niente di sofisticato. Un file PHP per risorsa o un router minimale.

* *GET    /api/purchase-orders           lista con filtri (stato, fornitore, data)*  
* *POST   /api/purchase-orders           crea bozza*  
* *GET    /api/purchase-orders/{id}      dettaglio con righe*  
* *PATCH  /api/purchase-orders/{id}      aggiorna header*  
* *DELETE /api/purchase-orders/{id}      elimina (solo draft)*

* *POST   /api/purchase-orders/{id}/send      transizione draft  sent*  
* *POST   /api/purchase-orders/{id}/confirm   transizione sent  confirmed*  
* *POST   /api/purchase-orders/{id}/cancel    cancella*

* *POST   /api/purchase-orders/{id}/lines         aggiungi riga*  
* *PATCH  /api/purchase-orders/{id}/lines/{lid}   modifica riga*  
* *DELETE /api/purchase-orders/{id}/lines/{lid}   rimuovi riga*

* *GET    /api/products/{id}/supplier-info?supplier={sid}   dati catalogo \+ prezzo suggerito*

* *POST   /api/stock-pickings/{id}/validate   conferma ricevimento (con lotti)*

Ogni endpoint di transizione (send, confirm, cancel) contiene la logica di validazione. Il frontend non valida nulla di critico — la validazione è sempre lato backend.

## Dove mettere la logica

Una scelta frequente e sbagliata: mettere la logica nei file che gestiscono le rotte (i "controller"). Dopo un po' diventano illeggibili.

Struttura consigliata per PHP senza framework:

* /api  
* /routes.php           solo routing (quale file gestisce quale URL)  
* /src  
* /PurchaseOrder.php    logica di business (validazioni, transizioni, calcoli)  
* /StockPicking.php     logica ricevimento e lotti  
* /ProductSupplier.php  logica catalogo e prezzi  
* /db  
* /Database.php         connessione e query base

La regola: i file in /src non sanno nulla di HTTP. Ricevono dati, restituiscono dati, lanciano eccezioni in caso di errore. Il routing catcha le eccezioni e le trasforma in risposte JSON con il codice HTTP giusto.

[^1]:  Il Documento di Trasporto (DDT) viene generato dal tuo fornitore, quindi è impossibile prevederne il formato o avere una tabella precompilata. L'ERP deve semplicemente salvare questo codice così come l'utente lo digita (es. "DDT-9954", "A/241" o "12099-B"). Perché lo salviamo? Serve solo per motivi fiscali e di contestazione (es. se un fusto perde, puoi risalire a quale bolla fornitore era associato).

[image1]: <data:image/png;base64,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>

[image2]: <data:image/png;base64,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>

[image3]: <data:image/png;base64,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>

[image4]: <data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAoMAAAIjCAYAAAB4chFgAACAAElEQVR4Xuydi99WU97/f3/D8zPz4JGJIiNUhHLIWeOUwyOHRiNn4/gQKTHk2BAJYWoah9FglKghotEwqEghOcQdIaMUlaYeGes37+X33da17r2va1/3dd3d993+vF+v9brva++91vquw17rc33X2vv6P04IIYQQQhSW/xMfEEIIIYQQxUFiUAghhBCiwEgMCiGEEEIUGIlBIYQQQogCIzEohBBCCFFgJAaFEEIIIQqMxKAQQgghRIGRGBRCCCGEKDASg0IIIYQQBUZiUAghhBCiwEgMCiGEEEIUGIlBIYQQQogCIzEohBBCCFFg6iYGv/32W7d06dL4cN0g7W+++SY+7L7++uv4UKtm3bp1PuTl+++/d8uXL/eB/4UQQggh6kmqGHziiScyw9///vf4ci/SjjnmGPcf//Ef7o033ohP1wzpk/ZWW22VpL9w4ULXv39/f7yt8Nlnn7ndd9/dB/7Pw5133unLSOB/IYQQQoh6kioG165d64499thEhBhr1qxx119/vT+24447ukWLFvnjLSEGseP//t//KzEohBBCCFEDqWIQfv3rXzcSg4BQtOOjR49Ojm/oZeJ58+a5//qv/2pkX2tHy8RCCCGEaE1ULQbBjl922WX+M0Jw6tSp7oADDvAiLWTSpElul1128dfzd/LkyYmoeffdd30+m2yyifvtb3/rPX9cx+cVK1YkaZA+aW+55ZZJ+uXE4KmnnurTsLRiLxwezltuuSXJb7fddnOPPPKID0OHDk2uu/3225NrCC+99FKqIJsxY4b3lIaBPE477bTk89tvv+2P/elPf3I77bST+8c//pHEv/rqq93mm2/u89hiiy3chRdemNgc1tFtt91Wkibh0EMPdcuWLSuxwcpAGlYXhPPPP79RXQghhBCi2FQtBkPP4L333uuFSPfu3ZNjoRhExHFsyJAh/joTVrbcaXEILPkiZP7zP//Tf95jjz3c4sWLS9JH/FUSg3PmzHE777yzmz9/vhdfCCDy5TisXLnSHXXUUT7eyJEj3cyZM5N0CJTbrkNA/fGPf/TlmDBhgj8/atSoRoJw1apVJeW4++67/TUIud69e/t0yH/rrbf214RikHyI8/vf/97985//dIcddliJHWEdIQZfeOEFX0983n///b3I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>

[image5]: <data:image/png;base64,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>