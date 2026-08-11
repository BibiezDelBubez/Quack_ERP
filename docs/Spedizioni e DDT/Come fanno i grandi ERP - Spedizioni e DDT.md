# Come gestiscono Spedizioni e DDT i grandi ERP — SAP, NetSuite, Dynamics 365, Odoo

Documento di ricerca comparativa, scritto per rispondere a una domanda concreta: **come organizzano, a livello di schermate e di bottoni, il passaggio da "ordine di vendita registrato" a "merce spedita e documento di trasporto emesso" quattro tra i sistemi gestionali più usati al mondo?** Non è un documento sulla teoria del ciclo attivo — è un confronto pratico, schermata per schermata, per capire cosa vede davvero un operatore quando lavora in questi sistemi, e per avere un metro di paragone quando si valutano le scelte di UX di Quack_ERP.



---

## Perché confrontarsi proprio con questi 4 sistemi

- **SAP** (S/4HANA, ed ECC prima di lui) — lo standard storico del mondo enterprise, quello con cui il concetto stesso di "modulo Vendite e Distribuzione (SD)" è stato codificato per decenni. Se un consulente ERP parla di "outbound delivery" o "post goods issue", sta usando terminologia nata qui.
- **NetSuite** (Oracle) — il gestionale cloud nativo più diffuso per aziende medie in forte crescita, spesso scelto proprio da chi esce da un sistema più artigianale (fogli Excel, software home-made) — lo scenario più vicino a quello di un'azienda che oggi valuta Quack_ERP.
- **Microsoft Dynamics 365 Business Central** — il gestionale Microsoft pensato esplicitamente per piccole e medie imprese, l'erede diretto di Navision. Tra i quattro, probabilmente il più vicino per scala e complessità a un'azienda delle dimensioni di quella che usa Quack_ERP.
- **Odoo** — open source, modulare, con un'interfaccia moderna orientata alla semplicità d'uso più che alla ricchezza di opzioni. Il più "leggero" dei quattro, e per questo il termine di paragone più onesto quando si valuta se una complessità in più (in Quack_ERP o altrove) è davvero necessaria o è complessità aggiunta senza motivo.

---

## Il modello concettuale comune a tutti e quattro: 3 documenti, non 1

Prima di entrare nel dettaglio di ognuno, un concetto che li accomuna tutti e che vale la pena capire bene, perché è la base di ogni scelta di UX che vedremo dopo.

Nessuno dei quattro sistemi tratta "l'ordine di vendita" come un singolo record che cambia stato fino a diventare "spedito" e poi "fatturato". Tutti e quattro, senza eccezioni, creano **tre documenti distinti e collegati**:

1. **L'Ordine di Vendita** (Sales Order) — cosa il cliente ha chiesto: prodotti, quantità, prezzi, condizioni. Non tocca il magazzino.
2. **Il Documento di Spedizione/Consegna** (Delivery / Shipment / Item Fulfillment, a seconda del sistema — l'equivalente del DDT italiano) — cosa è uscito fisicamente dal magazzino, quando, da dove, con che vettore. Tocca il magazzino: scarica la giacenza.
3. **La Fattura** (Invoice) — cosa viene addebitato al cliente. Tocca la contabilità.

Questi tre documenti sono **collegati** (ogni riga di spedizione sa da quale riga d'ordine viene, ogni riga di fattura sa da quale spedizione viene) ma restano **entità separate**, ognuna con il proprio numero, la propria data, il proprio stato. Un ordine da 100 pezzi può generare 3 spedizioni diverse in giorni diversi (magari anche 2 fatture diverse), e il sistema tiene traccia di "quanto è stato spedito finora" e "quanto resta da spedire" confrontando l'ordine con la somma delle spedizioni collegate — mai un singolo numero scritto a mano.

**Perché è così in tutti e quattro, e non è un caso**: separare i tre documenti permette al sistema di rispondere sempre, in automatico, a tre domande diverse che nella pratica commerciale sono quasi sempre diverse tra loro:

- Cosa ha comprato il cliente? (l'ordine, che non cambia mai una volta confermato)
- Cosa è successo davvero in magazzino? (le spedizioni, che possono essere più di una, in momenti diversi, anche con differenze rispetto all'ordine)
- Cosa gli sto addebitando, e quando? (la fattura, che può seguire la spedizione con giorni o settimane di ritardo, o essere anticipata, secondo la policy commerciale)

Se questi tre concetti fossero un solo campo "stato" su un solo record, un caso come "ho spedito il 60% dell'ordine, fatturato tutto anticipato, e il cliente ha già pagato solo la prima metà" diventerebbe impossibile da rappresentare con un solo valore. Con tre documenti separati, invece, ognuno racconta una parte diversa e vera della storia.

Tenete a mente questo schema: **Ordine → (una o più) Spedizioni → (una o più) Fatture**. Torna identico, con nomi diversi, in tutti e quattro i sistemi che seguono.

---

## 1. SAP — S/4HANA (il modulo "SD", Sales & Distribution)

### 1.1 Le schermate e i bottoni

SAP è il sistema più "vecchia scuola" dei quattro nella sua interfaccia classica (quella con i codici a 4 lettere, i famosi "transaction code" o T-code): ogni funzione ha un proprio codice che l'operatore digita in una casella in alto a sinistra per saltare direttamente a quella schermata, invece di navigare menu. Le versioni moderne (S/4HANA con l'interfaccia "Fiori") affiancano a questo un sistema di app/riquadri cliccabili più simile a quello di un tablet, ma sotto il cofano gira ancora la stessa logica.

Le schermate/funzioni principali di questo flusso, con il loro T-code classico tra parentesi:

- **VA01 — Crea Ordine di Vendita**: form con cliente, condizioni, righe prodotto/quantità/prezzo. Concettualmente identico al form ordine di Quack_ERP.
- **VL01N — Crea Spedizione in Uscita ("Outbound Delivery")**: si apre inserendo il numero dell'ordine di riferimento (o lasciando che il sistema proponga automaticamente le spedizioni dovute in una lista). Qui l'operatore/magazziniere sceglie quali righe e quante unità spedire ora.
- **Prelievo ("Picking")**: un passaggio separato, spesso su un ordine di trasferimento dedicato (T-code LT03) — l'operatore di magazzino conferma fisicamente di aver prelevato la merce dallo scaffale.
- **VL02N — Imballaggio ("Packing") e Uscita Merce ("Post Goods Issue", spesso abbreviato PGI)**: sulla stessa spedizione creata prima, un bottone dedicato "Imballa" per registrare in quali colli/pallet è stata sistemata la merce, e un secondo bottone separato "Registra Uscita Merce" che è il momento esatto in cui la giacenza viene davvero scaricata e la proprietà della merce passa (concettualmente) al cliente.
- **VF01 — Crea Fattura**: ultimo passaggio, con riferimento alla spedizione (non più all'ordine originale).

**Mockup testuale della schermata di creazione spedizione (VL01N)**, per dare un'idea concreta:

```
┌─────────────────────────────────────────────────────────────────┐
│  Crea Spedizione in Uscita                          [VL01N]      │
├─────────────────────────────────────────────────────────────────┤
│  Punto di Spedizione: [1000 - Magazzino Centrale ▾]              │
│  Ordine di riferimento: [4500001234        ] [Cerca]             │
│                                                                    │
│  Righe da evadere:                                                │
│  ┌───────────┬─────────────┬──────────┬───────────┬────────────┐ │
│  │ Materiale │ Descrizione │ Ordinato │ Da Spedire │ Magazzino  │ │
│  ├───────────┼─────────────┼──────────┼───────────┼────────────┤ │
│  │ 100-2201  │ Prodotto A  │   100    │   [100]    │ [1000 ▾]   │ │
│  │ 100-2202  │ Prodotto B  │    50    │   [ 30]    │ [1000 ▾]   │ │
│  └───────────┴─────────────┴──────────┴───────────┴────────────┘ │
│                                                                    │
│  [ Salva Spedizione ]   [ Prelievo ]   [ Imballa ]                │
└─────────────────────────────────────────────────────────────────┘
```

Da notare la colonna "Da Spedire": è un campo editabile riga per riga — l'operatore può ridurla rispetto all'ordinato (spedizione parziale) senza dover fare nulla di speciale, il sistema calcola da solo il residuo.

### 1.2 Il flusso, passo per passo

1. Il commerciale crea l'ordine (VA01). Nessun impatto sul magazzino.
2. Quando la merce è pronta a partire, si crea la spedizione (VL01N) con riferimento all'ordine — il sistema propone in automatico le righe ancora da evadere.
3. Il magazziniere conferma il prelievo fisico (picking).
4. Si conferma l'imballaggio (packing) — a questo punto si può stampare la lista di imballaggio.
5. Si preme "Registra Uscita Merce" (PGI) — **questo è il momento esatto in cui la giacenza si scarica davvero**, non prima. Da qui il documento di trasporto/bolla è stampabile (output type "LD00" per la nota di consegna, "PL00" per la lista di imballaggio — nomi tecnici interni, l'operatore vede solo un bottone "Stampa").
6. Solo dopo il PGI si può fatturare (VF01), con riferimento alla spedizione appena chiusa, non più all'ordine.

### 1.3 Esempio completo, con eccezioni

**Scenario**: il cliente Rossi Srl ordina 100 taniche di detergente (Prodotto A) e 50 flaconi di igienizzante (Prodotto B).

1. Il commerciale crea l'ordine VA01 per 100 + 50 unità. L'ordine è confermato, ma in magazzino non è cambiato nulla.
2. Il giorno della spedizione, in magazzino risultano disponibili solo 30 flaconi di Prodotto B (gli altri 20 sono in produzione). Il magazziniere apre VL01N con riferimento all'ordine: il sistema propone 100 + 50, ma lui riduce manualmente la riga B a 30. Salva la spedizione: ora esiste una spedizione con 100 A + 30 B, e l'ordine "sa" che restano 20 B da evadere in futuro (nessuna azione manuale ulteriore per tracciare questo residuo — è automatico, calcolato dal confronto tra ordine e spedizioni collegate).
3. Il magazziniere preleva fisicamente, imballa, e preme "Registra Uscita Merce". La giacenza si scarica per 100 A e 30 B. Il documento di trasporto è generato e stampabile.
4. Un mese dopo, i 20 flaconi di B mancanti sono pronti: si apre di nuovo VL01N con riferimento allo **stesso ordine originale** — il sistema propone automaticamente solo i 20 B residui (non richiede all'operatore di calcolarli a mano). Si ripete prelievo → imballo → PGI → nuovo DDT, questa volta solo per 20 unità.
5. A questo punto l'ordine è completamente evaso: due spedizioni, due DDT, collegati allo stesso ordine, ognuno con la propria data e il proprio numero.
6. Il commerciale fattura: può scegliere se fatturare le due spedizioni insieme in un'unica fattura, o separatamente — è una scelta esplicita al momento della fatturazione, non decisa in automatico dal sistema.

**Eccezione — "spedizione completa obbligatoria"**: se sulla scheda di un cliente specifico è impostato l'indicatore "consegna solo completa" (frequente per grandi catene retail che non vogliono ricevere scatole a metà), il sistema **blocca** la registrazione di una spedizione parziale per quel cliente — il campo "Da Spedire" non è nemmeno modificabile sotto la quantità ordinata. È una configurazione per cliente, non un comportamento fisso del sistema.

Fonti consultate per questa sezione:

- [Sales Order Outbound Delivery Scenario | SAP Help Portal](https://help.sap.com/docs/SAP_S4HANA_ON-PREMISE/25a41481f62e469ba0e61015a0d39d20/610fb16c6f8341e0a0725eeb3abb00ed.html)
- [PGI in SAP – How to Create Picking & Packing of Goods](https://www.guru99.com/picking-packing-and-pgi.html)
- [How to Create Outbound Delivery With order reference in SAP VL01N](https://community.sap.com/t5/enterprise-resource-planning-blog-posts-by-members/how-to-create-outbound-delivery-with-order-reference-in-sap-vl01n/ba-p/13659080)
- [Solved: delivery note/ packing list - SAP Community](https://community.sap.com/t5/enterprise-resource-planning-q-a/delivery-note-packing-list/qaq-p/3312507)
- [Navigating the Split: Managing Partial Shipments and Backorders in SAP Business One](https://b1bwise.com/navigating-the-split-managing-partial-shipments-and-backorders-in-sap-business-one/)
- [Solved: partial delivery of sales order with some line items - SAP Community](https://community.sap.com/t5/enterprise-resource-planning-q-a/partial-delivery-of-sales-order-with-some-line-items/qaq-p/7062783)

---

## 2. NetSuite

### 2.1 Le schermate e i bottoni

NetSuite è nativo cloud (nessuna installazione, tutto via browser) e la sua interfaccia è più vicina, come impostazione visiva, a un'app web moderna che a un gestionale anni '90. Il flusso ruota attorno a un record chiamato **Item Fulfillment** (letteralmente "evasione articoli"), l'equivalente concettuale della spedizione SAP e del DDT italiano insieme.

- **Sales Order**: il form dell'ordine, con un bottone in alto **"Fulfill"** ("Evadi") che appare non appena l'ordine è approvato.
- Cliccando "Fulfill" si apre il record **Item Fulfillment**, precompilato con tutte le righe e le quantità dell'ordine.
- Su questo record, ogni riga ha una casella "Quantity" modificabile — se si riduce sotto l'ordinato, è una evasione parziale.
- Un flusso più granulare (funzione **"Pick, Pack, and Ship"**, attivabile per operazioni con magazzino strutturato) separa i tre passaggi in transazioni distinte: "Fulfill Orders" (prelievo), "Mark Orders Packed" (imballo, con stampa della distinta), "Mark Orders Shipped" (spedizione effettiva, con vettore e tracking).
- Nella lista Item Fulfillment, un bottone di stampa genera la **Packing Slip** (bolla di consegna).

**Mockup testuale del record Item Fulfillment**:

```
┌───────────────────────────────────────────────────────────────────┐
│  Item Fulfillment — riferito a Ordine SO-1042           [Salva]    │
├───────────────────────────────────────────────────────────────────┤
│  Cliente: Rossi Srl              Data Spedizione: [11/08/2026]     │
│  Magazzino: [Sede Principale ▾]   Vettore: [Corriere XY ▾]         │
│                                                                      │
│  Righe:                                                             │
│  ┌────────────┬──────────┬──────────┬────────────┬───────────────┐│
│  │ Prodotto   │ Ordinato │ Già Sped.│  Da Spedire │ Lotto/Serie   ││
│  ├────────────┼──────────┼──────────┼────────────┼───────────────┤│
│  │ Prodotto A │   100    │    0     │   [100]     │ [scegli ▾]    ││
│  │ Prodotto B │    50    │    0     │   [ 30]     │ [scegli ▾]    ││
│  └────────────┴──────────┴──────────┴────────────┴───────────────┘│
│                                                                      │
│  [ Salva ]    [ Stampa Packing Slip ]                               │
└───────────────────────────────────────────────────────────────────┘
```

### 2.2 Il flusso e gli stati dell'ordine

L'ordine di vendita attraversa una sequenza di stati, mostrata chiaramente in un campo "Status" sempre visibile in testa al record:

`In attesa di approvazione` → `In attesa di evasione` → `Parzialmente evaso` (se solo una parte è stata spedita) → `In attesa di fatturazione` (tutto spedito, non ancora fatturato) → `Fatturato` (fine del ciclo).

Un dettaglio pratico interessante: appena viene creato il *primo* Item Fulfillment (anche solo salvato, non ancora "shipped"), il bottone "Fulfill" **sparisce** dall'ordine — non si può cliccarlo di nuovo per errore mentre un'evasione è già in corso. Se restano righe non coperte, il bottone "Fulfill" ricompare automaticamente una volta che quella prima evasione è marcata "Shipped".

In ogni momento in cui l'ordine è "In attesa di evasione", "Parzialmente evaso" o "In attesa di fatturazione", è disponibile un bottone **"Close Order"** — chiude definitivamente le righe residue senza spedirle (l'equivalente di "questo cliente non vuole più il resto, non generarmi più un promemoria per queste unità mancanti"). È un'azione distinta ed esplicita, con un proprio nome che comunica chiaramente cosa fa — non condivide il bottone con "l'ordine è stato spedito tutto".

### 2.3 Esempio completo, con eccezioni

**Scenario**: stesso ordine di prima, Rossi Srl, 100 Prodotto A + 50 Prodotto B.

1. L'ordine viene creato e approvato. Stato: "In attesa di evasione". Compare il bottone "Fulfill".
2. L'operatore clicca "Fulfill": si apre l'Item Fulfillment precompilato con 100 + 50. Riduce la riga B a 30 (solo 30 disponibili) e salva.
3. Il sistema segna quell'evasione come creata; l'ordine passa a "Parzialmente evaso" (perché mancano ancora 20 di B). Il bottone "Fulfill" torna visibile sull'ordine, ma ora proporrà solo il residuo (20 B) alla prossima apertura.
4. Un mese dopo, l'operatore clicca di nuovo "Fulfill": il nuovo Item Fulfillment propone automaticamente solo i 20 B residui. Salva.
5. Tutte le righe sono coperte: l'ordine passa a "In attesa di fatturazione".
6. Si fattura (con o senza il modulo "Advanced Shipping" attivo, la fattura può essere generata automaticamente insieme all'ultima evasione, oppure come passo separato successivo — è una configurazione di sistema, non una scelta per singolo ordine).

**Eccezione — il cliente rinuncia al resto**: se dopo il primo invio di 100 A + 30 B il cliente comunica che non vuole più i 20 B mancanti (magazzino fornitore chiuso, prodotto discontinuato, ecc.), l'operatore non deve "forzare" nessuno stato: apre l'ordine, ancora "Parzialmente evaso", e clicca "Close Order" sulla riga specifica di Prodotto B. Quella riga esce dal conteggio delle righe pendenti, l'ordine può chiudersi come completo pur non avendo mai spedito quei 20 pezzi — e resta comunque tracciato da qualche parte che quella riga non è stata evasa per scelta esplicita, non per dimenticanza.

Fonti consultate per questa sezione:

- [NetSuite Applications Suite - Order Fulfillment Overview](https://docs.oracle.com/en/cloud/saas/netsuite/ns-online-help/section_4737787881.html)
- [What is the Order Fulfillment Process of NetSuite](https://netsuite.folio3.com/blog/what-is-the-order-fulfillment-process-of-netsuite/)
- [what does the Pending Fulfillment status mean - NetSuite Professionals](https://archive.netsuiteprofessionals.com/t/30334339/what-does-the-pending-fulfillment-status-mean)
- [NetSuite Applications Suite - Bulk Fulfilling Orders](https://docs.oracle.com/en/cloud/saas/netsuite/ns-online-help/section_N1223692.html)
- [Manually Shipping Orders in Pack Cartons or Pallets](https://docs.oracle.com/en/cloud/saas/netsuite/ns-online-help/section_163034719923.html)

---

## 3. Microsoft Dynamics 365 Business Central

### 3.1 Le schermate e i bottoni

Business Central (l'erede di Navision) usa un'interfaccia a pagine con schede ("FastTabs") e un bottone unico e molto caratteristico: **"Post"** ("Registra"), presente su ogni documento di vendita. È qui che sta la differenza più interessante rispetto agli altri sistemi.

- **Sales Order**: il form ordine, con righe prodotto/quantità/prezzo. Ogni riga ha, oltre alla colonna "Quantity" (quanto ordinato), una seconda colonna **"Qty. to Ship"** ("Quantità da Spedire") — precompilata di default uguale alla quantità ordinata, ma modificabile riga per riga.
- Il bottone **"Post"** in alto, quando premuto, apre una **finestra di scelta esplicita con tre opzioni**:
  - **Ship** — registra solo la spedizione (scarica il magazzino), nessuna fattura.
  - **Invoice** — registra solo la fattura, nessun movimento di magazzino (usato quando si vuole fatturare separatamente da quando si spedisce).
  - **Ship and Invoice** — entrambe le cose insieme, in un solo click.
- Dopo aver scelto "Ship" (con o senza fattura), il sistema genera automaticamente un documento **"Posted Sales Shipment"** — l'equivalente diretto del DDT italiano, stampabile subito o inviabile via email in PDF con l'opzione "Post and Send".

**Mockup testuale della finestra di scelta al momento di "Post"**, il punto più rilevante per il confronto con Quack_ERP:

```
┌─────────────────────────────────────────────┐
│  Registra Ordine di Vendita — ODV-2026-0042  │
├─────────────────────────────────────────────┤
│  Cosa vuoi registrare?                       │
│                                               │
│   ( ) Spedisci                               │
│   ( ) Fattura                                │
│   (X) Spedisci e Fattura                     │
│                                               │
│              [ OK ]      [ Annulla ]         │
└─────────────────────────────────────────────┘
```

Da notare: non esiste un bottone genericamente chiamato "Completa" — ogni opzione della finestra dice esplicitamente cosa farà (spedire fisicamente, o addebitare il cliente, o entrambe), e l'utente deve scegliere consapevolmente prima che l'azione parta. Non è un'unica etichetta ambigua dietro cui può nascondersi un significato diverso a seconda del contesto.

### 3.2 Il flusso, passo per passo

1. Si crea l'ordine di vendita: cliente, righe, quantità.
2. Quando la merce è pronta, sulla riga si può ridurre "Qty. to Ship" sotto la quantità ordinata (spedizione parziale) — nessuna finestra di conferma necessaria per farlo, è semplicemente un campo numerico come un altro.
3. Si preme "Post" e si sceglie "Ship" (o "Ship and Invoice" se si vuole fatturare nello stesso momento).
4. Il sistema genera il "Posted Sales Shipment" — stampabile, inviabile via email, e usato come riferimento per la fattura futura.
5. In configurazioni di magazzino più strutturate (con più operatori, aree di picking dedicate), lo stesso flusso passa da un documento intermedio, il "Warehouse Shipment", dove un magazziniere conferma prelievo e imballo prima che qualcuno prema "Post" sull'ordine — ma nelle configurazioni semplici (un solo magazzino, poco personale) questo passaggio intermedio si salta e si registra la spedizione direttamente dall'ordine.

### 3.3 Esempio completo, con eccezioni

**Scenario**: sempre Rossi Srl, 100 Prodotto A + 50 Prodotto B.

1. L'operatore crea l'ordine. Le righe hanno "Qty. to Ship" = 100 e 50 di default (uguale all'ordinato).
2. Solo 30 B disponibili: l'operatore modifica quella singola cella da 50 a 30. Nessun'altra azione richiesta per questo passaggio.
3. Preme "Post" → sceglie "Ship" (non fattura ancora, magari perché la policy aziendale fattura a fine mese). Il sistema registra la spedizione per 100 A + 30 B, scarica il magazzino, genera il "Posted Sales Shipment" n. 1.
4. L'ordine resta aperto — la riga B mostra ancora un residuo di 20 unità visibile direttamente nella riga stessa (colonna "Quantity Shipped" a 30 su un ordinato di 50).
5. Un mese dopo, l'operatore riapre lo stesso ordine (ancora nella lista degli ordini aperti — non è "chiuso" finché non è tutto spedito e fatturato secondo la policy), imposta "Qty. to Ship" = 20 sulla riga B, preme di nuovo "Post" → "Ship and Invoice" questa volta. Genera il secondo "Posted Sales Shipment" e la fattura finale nello stesso click.
6. L'ordine ora è completamente evaso e fatturato — esce dalla lista degli ordini aperti automaticamente (nessuna azione manuale tipo "segna come completato": lo stato deriva dal confronto tra quantità ordinate/spedite/fatturate, non da un interruttore separato).

**Eccezione — cliente che vuole solo consegne complete**: come in SAP, esiste un campo sulla scheda cliente, "Shipping Advice", impostabile su "Complete". Se attivo, il campo "Qty. to Ship" si blocca e non può essere ridotto sotto la quantità ordinata — il sistema impedisce fisicamente la spedizione parziale per quel cliente, senza bisogno che l'operatore se lo ricordi ogni volta.

Fonti consultate per questa sezione:

- [Dynamics 365 Business Central: How to customize post options (Dialog: Ship, Invoice, Ship and Invoice)](https://yzhums.com/7349/)
- [Process partial shipments - Business Central | Microsoft Learn](https://learn.microsoft.com/en-us/dynamics365/business-central/sales-how-send-partial-shipments)
- [Dynamics 365 Business Central: How to prohibit partial (split) shipment of orders](https://yzhums.com/54986/)
- [Printing the Posted Warehouse Shipment - Dynamics 365 Business Central](https://usedynamics.com/business-central/warehouse/print-shipment/)
- [The purpose of using Warehouse Shipments - Dynamics 365 Business Central](https://usedynamics.com/business-central/warehouse/warehouse-shipments/)
- [Posted Shipments - Dynamics 365 Business Central](https://usedynamics.com/business-central/sales/posted-shipments/)

---

## 4. Odoo

### 4.1 Le schermate e i bottoni

Odoo è il più "minimalista" visivamente dei quattro — pochi bottoni, colori decisi, e un concetto di navigazione molto caratteristico: gli **smart button** (bottoncini ovali in alto a destra su ogni documento, che fanno sia da indicatore di stato sia da collegamento diretto al documento collegato).

- **Sales Order (Preventivo/Ordine di Vendita)**: form con righe prodotto/quantità/prezzo. Finché non è confermato si chiama "Preventivo" — un bottone **"Confirm"** ("Conferma") lo trasforma in ordine vero.
- Nell'istante stesso della conferma, Odoo genera **in automatico** un documento collegato, il **"Delivery Order"** (Buono di Prelievo/Consegna) — non è una scelta dell'operatore, succede sempre, subito. Sull'ordine compare uno smart button **"Delivery"** con sopra il numero "1" (o più, se ci sono già più spedizioni) che porta dritti a quel documento.
- Sul Delivery Order, ogni riga ha un campo **"Demand"** (quanto richiesto, di sola lettura) e un campo **"Quantity Done"** (quanto effettivamente prelevato, modificabile).
- Un bottone **"Validate"** ("Convalida") in alto: quando premuto, se "Quantity Done" è uguale su tutte le righe alla richiesta, la spedizione si chiude e basta. **Se invece è inferiore** (spedizione parziale), Odoo apre in automatico una finestra di dialogo che chiede esplicitamente: *"Vuoi creare un ordine residuo (backorder) per le quantità mancanti?"* — con due bottoni, "Crea Backorder" oppure "Nessun Backorder".
- Tornati sull'ordine di vendita originale, un secondo smart button, **"Create Invoice"**, cambia colore (diventa evidenziato) non appena c'è qualcosa di spedito da poter fatturare.

**Mockup testuale della finestra di conferma backorder**, altro punto centrale per il confronto:

```
┌───────────────────────────────────────────────────────────┐
│  Bolla WH/OUT/00042 — Prodotto B: richiesti 50, fatti 30   │
├───────────────────────────────────────────────────────────┤
│                                                              │
│   Vuoi creare un ordine residuo per le quantità             │
│   rimanenti?                                                │
│                                                              │
│      [ Nessun Backorder ]      [ Crea Backorder ]           │
│                                                              │
└───────────────────────────────────────────────────────────┘
```

Da notare: qui il sistema **non lascia decidere in modo implicito**. Se l'operatore preme "Validate" con una quantità inferiore, Odoo lo interrompe e obbliga a una scelta consapevole — non esiste un modo di "spedire parzialmente e dimenticarsi del resto" per errore. O si dice esplicitamente "crea un residuo da evadere poi", o si dice esplicitamente "no, questa è la spedizione finale, il resto non arriverà mai" (per esempio perché il cliente ha rinunciato al resto).

### 4.2 Il flusso, passo per passo

1. Si crea il Preventivo con le righe.
2. Si preme "Confirm" — diventa Ordine di Vendita, e **nello stesso istante** viene creato il Delivery Order collegato (mai un passo separato che qualcuno deve ricordarsi di fare).
3. Il magazziniere apre il Delivery Order (dallo smart button, o dalla sua lista dedicata), aggiorna "Quantity Done" per ogni riga secondo cosa ha davvero prelevato.
4. Preme "Validate". Se tutto combacia, la spedizione si chiude, il magazzino si scarica, e la bolla è stampabile. Se non combacia, compare la finestra backorder descritta sopra.
5. Tornati sull'ordine, lo smart button "Create Invoice" è ora attivo: un click genera la fattura per quanto spedito finora (secondo la policy di fatturazione impostata: "fattura quanto ordinato" o "fattura solo quanto consegnato" — due impostazioni diverse, spiegate sotto).

### 4.3 Esempio completo, con eccezioni

**Scenario**: sempre Rossi Srl, 100 Prodotto A + 50 Prodotto B.

1. Il commerciale crea il Preventivo, poi preme "Confirm". Nasce l'Ordine di Vendita **e**, nello stesso secondo, un Delivery Order con richiesta 100 A + 50 B.
2. Il magazziniere apre il Delivery Order: per A imposta "Quantity Done" = 100, per B solo 30 (unico dato reale disponibile). Preme "Validate".
3. Compare la finestra: "Prodotto B: richiesti 50, fatti 30 — vuoi un backorder?". Il magazziniere clicca **"Crea Backorder"**.
4. Odoo chiude la spedizione corrente (100 A + 30 B, giacenza scaricata, bolla stampabile) e crea **in automatico** un secondo Delivery Order, già pronto, con solo i 20 B mancanti come richiesta — l'operatore lo ritroverà nella lista "Da Fare" del magazzino senza doverlo ricreare lui.
5. Un mese dopo, il magazziniere apre quel secondo Delivery Order (già esistente, generato mesi prima), imposta "Quantity Done" = 20, preme "Validate" — questa volta combacia esattamente, nessuna finestra di dialogo, la spedizione si chiude e basta.
6. Sull'ordine originale, ora lo smart button "Delivery" mostra "2" (due spedizioni collegate). Il commerciale preme "Create Invoice": a seconda della policy di fatturazione impostata sui prodotti (**"Invoice what is ordered"**, fattura tutto anticipatamente alla conferma ordine — il default di Odoo — oppure **"Invoice what is delivered"**, fattura solo ciò che è stato davvero consegnato), il sistema propone l'importo corretto in automatico.

**Eccezione — il cliente rinuncia al resto**: identica eccezione vista per gli altri sistemi, ma qui la scelta è ancora più esplicita: al momento del "Validate" parziale, invece di premere "Crea Backorder" il magazziniere preme **"Nessun Backorder"**. La spedizione si chiude comunque per 30 B, ma **non viene creato nessun secondo documento** — i 20 B mancanti restano ordinati sulla carta (per la reportistica) ma nessuno riceverà mai un promemoria per evaderli, perché la scelta di non farlo è stata dichiarata nello stesso momento, non dedotta implicitamente più avanti.

Fonti consultate per questa sezione:

- [Order handling — Odoo 19.0 documentation](https://www.odoo.com/documentation/19.0/applications/websites/ecommerce/order_handling.html)
- [Three-step delivery — Odoo 19.0 documentation](https://www.odoo.com/documentation/19.0/applications/inventory_and_mrp/inventory/shipping_receiving/daily_operations/delivery_three_steps.html)
- [[Odoo 17] Validate as Backorder | Odoo](https://www.odoo.com/forum/help-1/odoo-17-validate-as-backorder-250160)
- [Odoo Backorder Not Creating — Partial Delivery Fix (2026) | DeployMonkey](https://deploymonkey.com/blog/odoo-backorder-not-creating-fix)
- [Invoicing policies — Odoo 19.0 documentation](https://www.odoo.com/documentation/19.0/applications/sales/sales/invoicing/invoicing_policy.html)

---

## 5. Tabella di confronto rapido

|                                                               | SAP                                                | NetSuite                                                                    | Business Central                                                        | Odoo                                                                                                     |
| ------------------------------------------------------------- | -------------------------------------------------- | --------------------------------------------------------------------------- | ----------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| Nome del documento "spedizione/DDT"                           | Outbound Delivery                                  | Item Fulfillment                                                            | Posted Sales Shipment                                                   | Delivery Order                                                                                           |
| Chi lo crea                                                   | Operatore, con bottone dedicato                    | Operatore, bottone "Fulfill"                                                | Operatore, scelta dentro "Post"                                         | **Automatico**, alla conferma ordine                                                                     |
| Spedizione parziale                                           | Riduci quantità in riga, libero di default         | Riduci quantità in riga, libero di default                                  | Riduci "Qty. to Ship", libero di default                                | Riduci "Quantity Done", **richiede scelta esplicita backorder sì/no**                                    |
| Blocco consegna solo-completa                                 | Sì, per cliente                                    | Non trovato nella ricerca                                                   | Sì, per cliente ("Shipping Advice")                                     | Non è un blocco nativo standard                                                                          |
| Il bottone "completa/spedisci" dice esplicitamente cosa fa    | Sì (PGI = "Registra Uscita Merce", inequivocabile) | Sì ("Fulfill" = evadi, "Close Order" = chiudi residuo, due bottoni diversi) | Sì (finestra con 3 opzioni nominate: Ship / Invoice / Ship and Invoice) | Sì ("Validate" sulla spedizione, "Create Invoice" sulla fattura — mai un bottone unico "completa tutto") |
| Serve un'azione manuale per "generare il DDT" oltre a spedire | No, generato insieme al PGI                        | No, generato insieme al Fulfillment                                         | No, generato insieme al Post-Ship                                       | No, generato insieme alla conferma ordine (come documento vuoto, poi compilato)                          |

Il pattern che emerge, identico in tutti e quattro nonostante interfacce molto diverse tra loro: **non esiste, in nessuno dei quattro sistemi, un singolo bottone genericamente chiamato "completa" che possa marcare un ordine come finito senza che sia successo qualcosa di fisico e verificabile (una spedizione, un'evasione, un'uscita merce).** Ogni azione che cambia lo stato ha un nome che descrive esattamente cosa fa, e le azioni "ho spedito" e "ho chiuso senza spedire tutto" sono sempre due bottoni/scelte diverse, mai la stessa etichetta.

---

## 6. Principi di design ricorrenti (cosa hanno davvero in comune)

Al di là dei nomi diversi, quattro principi tornano identici in tutti e quattro i sistemi:

**Primo: la spedizione/DDT nasce sempre "agganciata" all'ordine, mai da un form vuoto.** In nessuno dei quattro sistemi l'operatore digita di nuovo cliente/prodotti/quantità per fare una spedizione — parte sempre da un ordine esistente, con le righe già pre-compilate, e l'unico lavoro reale è confermare o correggere le quantità.

**Secondo: la spedizione parziale è un caso normale, previsto, mai un'eccezione da aggirare con un trucco.** Tutti e quattro permettono, per default, di spedire meno di quanto ordinato senza errori o avvisi bloccanti — e tutti e quattro tracciano da soli il residuo, senza che l'operatore debba fare un calcolo a mano.

**Terzo: "ho finito di spedire tutto" e "chiudo senza spedire il resto" sono sempre due azioni diverse, con due nomi diversi.** Che sia una finestra di dialogo esplicita (Odoo), un bottone a parte ("Close Order" in NetSuite), o una configurazione per cliente che a monte impedisce la parzialità (SAP, Business Central) — nessuno dei quattro lascia che sia la stessa identica azione, con lo stesso bottone, a significare "ho spedito tutto" oppure "smetto di occuparmene senza aver spedito tutto". Sono deliberatamente tenute separate, perché sono due fatti commerciali diversi (uno positivo — cliente servito — uno che richiede spesso una comunicazione col cliente).

**Quarto: il documento di trasporto/bolla è sempre un sottoprodotto automatico della spedizione, mai un passo a parte con un proprio bottone "crea DDT".** Nessuno dei quattro obbliga l'operatore a "prima spedire, poi ricordarsi di generare il documento" come due azioni separate — il documento nasce insieme all'atto di spedire, sempre.

---

## 7. Confronto con Quack_ERP: le tre criticità sollevate

Con questo quadro davanti, torniamo alle tre osservazioni fatte sulla schermata di dettaglio ordine di Quack_ERP (screenshot dell'ordine ODC/2026/0018, "in lavorazione", con il bottone "Completa Ordine" già cliccabile).

### 7.1 "Completa l'ordine" può voler dire troppe cose

Verificato nel codice (`includes/sales_orders.php:529-540`, `includes/transizioni_stati.php:84`): il bottone marca `stato = 'completato'` **senza controllare se qualcosa è mai stato spedito**. In nessuno dei quattro sistemi confrontati esiste un'azione equivalente — il concetto più vicino, in NetSuite, è "Close Order", ma lì il nome dice esplicitamente "sto chiudendo senza evadere", non lascia margine di ambiguità come "Completa" fa in Quack_ERP. In Business Central l'idea stessa di "completare manualmente" non esiste: l'ordine si chiude da solo quando le quantità spedite/fatturate coprono quelle ordinate.

### 7.2 Il bottone è disponibile subito, senza nessun legame con le spedizioni

Confermato dallo stesso codice: `qk_transizioni_ordini_clienti()` ammette la transizione diretta `in_lavorazione → completato`, zero condizioni su quanto è stato spedito. In tutti e quattro i sistemi confrontati, l'azione equivalente più "pericolosa" (chiudere senza spedire tutto) è sempre un'azione **distinta**, spesso con un nome che dichiara la sua natura eccezionale (backorder "no", "Close Order") — mai la stessa azione "principale" del ciclo, sempre visibile e sempre a portata di click fin dal primo secondo.

### 7.3 La sezione "Spedizioni e DDT" è in fondo alla pagina

Nessuno dei quattro sistemi confrontati nasconde le informazioni di spedizione in fondo a una pagina lunga da scorrere: in SAP e Business Central la spedizione è letteralmente un documento/transazione a parte, raggiungibile da un menu di navigazione sempre visibile sull'ordine; in NetSuite e Odoo è uno smart button/collegamento diretto, visibile subito nella parte alta del record, non dopo aver scrollato oltre note e margini.

---

## 8. Alcune idee emerse dal confronto (da discutere, non ancora applicate)

Questo documento è di sola ricerca — nessuna di queste idee è stata implementata. Sono spunti concreti, ispirati a quanto visto sopra, per un'eventuale discussione futura su come rivedere questa parte di Quack_ERP:

- **Rinominare "Completa Ordine" con un'etichetta che dica cosa succede davvero**, oppure trasformarlo in una scelta esplicita quando viene premuto su un ordine con residuo non spedito (mirror della finestra "Ship / Invoice / Ship and Invoice" di Business Central, o della finestra backorder di Odoo) — invece di un'azione muta che si limita a cambiare un valore in un campo `stato`.
- **Condizionare la disponibilità del bottone**: mostrarlo sempre attivo solo se non ci sono righe con residuo da spedire, altrimenti trasformarlo in un'azione che richiede una conferma esplicita del tipo "Questo ordine ha ancora N unità non spedite. Vuoi chiuderlo comunque?" — pattern preso da NetSuite/Odoo.
- **Spostare la sezione "Spedizioni e DDT" più in alto nella pagina**, o renderla raggiungibile con un collegamento visibile subito (uno smart-button-like, sul modello di NetSuite/Odoo), invece di richiedere lo scroll fino in fondo.

Nessuna di queste tre proposte è stata valutata per impatto tecnico o per compatibilità con il resto dell'architettura di Quack_ERP (transizioni di stato, permessi, ecc.) — sono solo la fotografia di "cosa fanno gli altri", punto di partenza per una eventuale progettazione vera e propria, se si deciderà di procedere.
