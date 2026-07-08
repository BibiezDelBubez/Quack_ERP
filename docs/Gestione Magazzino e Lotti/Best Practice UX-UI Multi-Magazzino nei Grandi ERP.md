# Best Practice UX/UI Multi-Magazzino nei Grandi ERP

Nota di studio — spiega come i sistemi ERP di riferimento del mercato risolvono quattro problemi concreti: mostrare le giacenze con filtro sui magazzini, cambiare "vista" da un magazzino all'altro, fare un trasferimento tra due magazzini, e impostare le scorte minime per singolo magazzino. Scritta per essere comprensibile anche a chi non ha mai usato un ERP.

Sistemi analizzati: **SAP S/4HANA** ed **Oracle NetSuite** (i due riferimenti enterprise più diffusi al mondo), **Microsoft Dynamics 365** (enterprise/mid-market, ecosistema Microsoft), **Odoo** (non è "enterprise" in senso stretto, ma è probabilmente il sistema con la UX più moderna e curata su questo specifico problema, ed è un ottimo termine di paragone per capire "come si fa bene" senza la complessità di SAP).

Non sono stati inclusi tool come Zoho Inventory o Katana MRP: sono buoni prodotti, ma sono strumenti per piccole imprese (Zoho) o per la sola produzione/inventory di piccoli manifatturieri (Katana), non ERP paragonabili a quelli sopra per profondità e scala.

---

## 1. SAP S/4HANA (con Extended Warehouse Management)

SAP è il sistema più diffuso tra le grandi aziende manifatturiere e distributive, incluso il settore chimico — è probabilmente il termine di paragone più rilevante per Quack, anche se la sua interfaccia nasce per organizzazioni molto più grandi e complesse.

**Visualizzare le giacenze.** SAP separa concettualmente due livelli di sede: il "plant" (stabilimento/sede legale-operativa) e la "storage location" (una sotto-zona dentro il plant, es. un magazzino specifico o un'area di quarantena). L'app "Stock Overview" mostra, per un dato materiale, tutte le quantità disponibili incrociando plant e storage location in un'unica tabella: non è né solo un totale aggregato né solo una sede singola, ma una matrice che permette di vedere entrambe le cose insieme, colonna per colonna. È potente ma richiede di capire prima la differenza tra plant e storage location, cosa che a un utente nuovo non è affatto ovvia.

**Cambiare vista da un magazzino all'altro.** Non esiste un "magazzino attivo di sessione" universale come lo intendiamo noi: ogni transazione/app chiede esplicitamente plant e storage location come parametri di ricerca, ogni volta. Questo elimina il rischio di operare per errore sul posto sbagliato (non c'è mai un default nascosto), ma al prezzo di dover reinserire il contesto quasi ad ogni schermata — mitigato in parte da valori di default per utente/ruolo configurabili dall'amministratore.

**Fare un trasferimento tra magazzini.** SAP distingue esplicitamente il trasferimento "in-plant" (tra due storage location dello stesso stabilimento, operazione più semplice e diretta, pensata per essere fatta anche da mobile/palmare in magazzino) dal trasferimento "cross-plant" (tra stabilimenti diversi, che coinvolge tipicamente anche aspetti contabili/fiscali, quindi un processo più strutturato). Concettualmente è lo stesso principio già adottato in Quack per i trasferimenti come documento: SAP infatti tratta anche questi come documenti con uno stato (non semplici aggiornamenti istantanei), esattamente per la stessa ragione — la merce viaggia, e nel mentre deve poter risultare "in transito".

**Impostare le scorte minime.** Le soglie di riordino vivono a livello di combinazione materiale + plant (o più in profondità, materiale + plant + storage location per configurazioni avanzate), dentro un'anagrafica dedicata ("Material Master", vista MRP). Questo è coerente con l'approccio già adottato in Quack (soglia legata a prodotto+magazzino, non solo a prodotto).

**Pro:** massima precisione, nessuna ambiguità su quale sede sto guardando o modificando, perché il sistema non presume mai nulla. Ottima tracciabilità storica.
**Contro:** verbosità enorme — ogni operazione richiede di specificare esplicitamente dove si sta lavorando, il che è sicuro ma stanca l'utente occasionale; curva di apprendimento ripida; concetti sovrapposti (plant/storage location/magazzino WMS) che confondono chi non viene da un contesto SAP.

---

## 2. Oracle NetSuite

NetSuite è il riferimento cloud enterprise più diffuso dopo SAP, molto usato da aziende di distribuzione e chimica di dimensioni medio-grandi.

**Visualizzare le giacenze.** NetSuite introduce il concetto di "location" (sede/magazzino) e, dentro ciascuna location, opzionalmente il concetto più fine di "bin" (scaffale/ubicazione fisica specifica). Le viste di giacenza permettono di vedere lo stock per singola location oppure aggregato su tutte, a seconda della schermata: le liste articoli mostrano tipicamente il totale, mentre i report di inventario dedicati permettono di scendere al dettaglio per location e, se attivo, per bin.

**Cambiare vista da un magazzino all'altro.** NetSuite lavora molto con filtri espliciti sulle liste (colonna "Location" filtrabile) più che con un selettore di sessione persistente stile "sto lavorando nel magazzino X finché non cambio". La sede diventa "attiva" davvero solo nel contesto di un documento specifico (un ordine, una ricezione), non come stato ambientale generale del sistema.

**Fare un trasferimento tra magazzini.** NetSuite distingue due strumenti con scopi diversi, ed è la distinzione più istruttiva per Quack: un "Inventory Transfer" semplice, pensato per uno spostamento immediato e diretto (poca burocrazia, va bene per correzioni rapide), e un "Transfer Order", pensato invece per pianificare e tracciare un trasferimento più corposo o programmato, che può passare attraverso un'approvazione e più fasi. È esattamente la logica "bozza → in transito → completato" già scelta in Quack, solo che NetSuite la offre come *scelta* tra due strumenti diversi in base alla complessità del caso, invece di un unico flusso sempre a stati.

**Impostare le scorte minime.** Le soglie di riordino sono configurabili per combinazione articolo+location tramite l'apposita funzione di "Multi-Location Inventory", quindi lo stesso principio di Quack: non un'unica soglia globale, ma una per sede.

**Pro:** ottima flessibilità tra "voglio fare una cosa veloce" (Inventory Transfer) e "voglio pianificare e controllare un processo" (Transfer Order) — offre due strumenti proporzionati alla complessità reale dell'operazione, invece di forzare sempre lo stesso flusso pesante anche per un aggiustamento banale.
**Contro:** avere due strumenti diversi per lo stesso concetto (spostare merce) può disorientare chi non sa quale scegliere; la configurazione di bin/location è potente ma con multe opzioni da capire prima di iniziare a usarla bene.

---

## 3. Microsoft Dynamics 365 (ecosistema Business Central / Supply Chain Management)

Riferimento enterprise dell'ecosistema Microsoft, molto diffuso a livello mid-market e in aziende di distribuzione europee.

**Visualizzare le giacenze.** L'elenco "on-hand" (giacenza attuale) è costruito come vista aggregata configurabile: la stessa tabella di dettaglio (che contiene ogni combinazione articolo/sede/lotto) può essere mostrata sia come somma totale che come dettaglio per sede, in base a quali colonne/raggruppamenti l'utente sceglie di applicare. È lo stesso dato sorgente presentato con due lenti diverse, non due funzionalità separate — un pattern pulito.

**Cambiare vista da un magazzino all'altro.** Ogni documento (ordine, trasferimento, rettifica) ha un campo "Location" esplicito che va sempre indicato, non un contesto di sessione globale. Le sedi vengono gestite tramite una "Location Card" (scheda anagrafica del magazzino) che ne definisce anche le rotte di trasferimento predefinite verso altre sedi.

**Fare un trasferimento tra magazzini.** Il "Transfer Order" ha esplicitamente una sede di partenza e una di arrivo, e si conferma in due passaggi distinti e separati nel tempo: prima si registra la spedizione dall'origine, poi — potenzialmente giorni dopo — si registra la ricezione a destinazione. Tra questi due eventi la merce risulta "in transito", esattamente il concetto già implementato in Quack. Interessante: le "Transfer Routes" (rotte di trasferimento) possono essere preconfigurate tra coppie di sedi ricorrenti, per velocizzare la creazione di nuovi trasferimenti abituali.

**Impostare le scorte minime.** Qui Dynamics 365 introduce una sottigliezza utile: la soglia di riordino di un articolo di per sé non ha "sede" (la scheda articolo è generica), ma diventa specifica per magazzino tramite un record separato chiamato "Stockkeeping Unit" (SKU), che rappresenta proprio la combinazione articolo+sede con le sue regole di riordino proprie. Questo separa chiaramente "cos'è il prodotto" da "come si comporta in questa specifica sede" — un prodotto può avere scorta minima 50 a Milano e 10 a Bari, senza mescolare le due informazioni nella stessa scheda.

**Pro:** la separazione articolo/SKU-per-sede è concettualmente molto pulita, evita di sovraccaricare l'anagrafica prodotto con dati che dipendono dalla sede; le rotte di trasferimento predefinite riducono l'attrito per gli spostamenti ricorrenti.
**Contro:** il concetto di "Stockkeeping Unit" distinto dall'articolo è un livello di indirezione in più da spiegare a un utente nuovo; senza SKU configurata per una sede, il comportamento ricade su regole di default meno trasparenti.

---

## 4. Odoo

Odoo non è un ERP "enterprise" nella fascia di SAP/Oracle/Microsoft, ma è probabilmente il sistema che risolve questi quattro problemi con la UX più semplice da capire per un utente senza formazione — motivo per cui è un ottimo modello di riferimento, anche se il pubblico a cui si rivolge è diverso (PMI, non multinazionali).

**Visualizzare le giacenze.** Odoo mostra sempre, sulla scheda di ogni singolo prodotto, un pulsante/contatore "In Stock" cliccabile che apre subito il dettaglio riga per riga per magazzino — nessun report separato da cercare, nessuna configurazione preventiva: è la stessa schermata del prodotto, con l'informazione a un click di distanza. Le viste elenco (liste prodotti, reportistica) permettono di raggruppare per magazzino con un filtro standard, oppure lasciare il totale aggregato di default.

**Cambiare vista da un magazzino all'altro.** Odoo non ha un "magazzino attivo" di sessione universale per la navigazione: ogni operazione di magazzino (Inventory) mostra i propri elenchi già filtrabili per sede tramite i filtri standard in cima alla lista, con la possibilità di salvare un filtro come preferito personale. Il concetto più vicino al nostro "magazzino attivo" è invece legato al contesto del *documento* che si sta creando (un ordine di vendita ha una sede di spedizione di default, configurabile per azienda/utente), non a un interruttore globale sempre visibile.

**Fare un trasferimento tra magazzini.** Anche qui, coerenza col resto: un trasferimento interno è un documento con uno stato (bozza → pronto → fatto), e in più Odoo offre una modalità automatica interessante: si può configurare una "regola di rifornimento" (reordering rule) su un magazzino satellite che, quando la scorta scende sotto soglia, genera *da sola* un trasferimento dal magazzino principale, senza intervento manuale — la stessa logica delle scorte minime viene riusata anche per alimentare automaticamente i trasferimenti, non solo per segnalare "sto per finire".

**Impostare le scorte minime.** Le "Reordering Rules" si configurano per combinazione prodotto+magazzino con una quantità minima e una massima (quando si scende sotto il minimo, si riordina fino al massimo) — stesso principio di Quack/Dynamics/NetSuite, ma con un'interfaccia a singola schermata molto più immediata (una tabella con le colonne Prodotto, Magazzino, Min, Max, invece di dover aprire una scheda separata per ogni combinazione).

**Pro:** la UX più semplice e diretta dei quattro esaminati — poche schermate, poco gergo, il legame automatico tra scorta minima e trasferimento è elegante e utile per chi ha più sedi rifornite da un magazzino centrale.
**Contro:** questa stessa semplicità comporta meno controllo puntuale rispetto a SAP/NetSuite (niente approvazioni multi-step, tracciamento meno granulare per operazioni molto complesse); il "magazzino attivo" implicito nei documenti può risultare comunque fonte di errore se un utente non guarda con attenzione il campo sede sul singolo documento, un rischio strutturalmente simile a quello di cui parlavamo per Quack.

---

## 5. Confronto e riepilogo

| Tema | SAP S/4HANA | Oracle NetSuite | Microsoft Dynamics 365 | Odoo |
|---|---|---|---|---|
| Vista giacenze | Matrice plant × storage location, molto potente ma verbosa | Per location o aggregata, a seconda della vista | Stessa tabella dati, vista aggregata o per sede a scelta | Dettaglio per magazzino a un click dalla scheda prodotto |
| Cambio magazzino | Nessun contesto globale: si specifica sempre nel comando | Filtro esplicito per lista, sede "attiva" solo nel documento | Campo "Location" sempre esplicito sul documento | Filtro standard sulle liste, sede di default per documento configurabile |
| Trasferimento | Documento con stato, distinzione in-plant / cross-plant | Due strumenti: trasferimento rapido oppure Transfer Order pianificato | Transfer Order in due fasi (spedizione poi ricezione), rotte predefinite | Documento con stato, con generazione automatica da regole di riordino |
| Scorta minima | Per combinazione materiale+plant(+storage location) | Per combinazione articolo+location | Per combinazione articolo+sede tramite "Stockkeeping Unit" separata | Per combinazione prodotto+magazzino, interfaccia a tabella singola |

**Il filo conduttore comune a tutti e quattro**, che vale la pena isolare perché è la vera lezione trasferibile a Quack: **nessuno dei quattro sistemi usa un "magazzino attivo" ambientale e nascosto come unico meccanismo.** Tutti, in modi diversi, rendono la sede un dato esplicito legato al *documento* o alla *vista* che si sta usando in quel momento, non uno stato di sessione invisibile che silenziosamente filtra tutto. Dove esiste un default di comodo (Odoo per i documenti, SAP per utente/ruolo), è appunto un default pre-compilato e sempre visibile/modificabile sul documento stesso, mai un filtro invisibile su una schermata di sola lettura.

**Seconda cosa in comune:** in tutti i sistemi la vista di giacenza aggregata ("quanto ne ho in totale, ovunque") e la vista per singola sede sono la *stessa informazione* guardata con lenti diverse, non due funzionalità separate da mantenere — il dato di partenza è sempre quello granulare per sede, e l'aggregato è semplicemente una somma calcolata al volo, mai una colonna scritta a parte (che è esattamente l'errore attualmente presente nella query dell'elenco prodotti di Quack, con il `GROUP BY` senza `SUM`).

**Terza cosa in comune:** in tutti i sistemi la scorta minima vive alla granularità prodotto+sede, mai solo prodotto — Quack è già allineato correttamente su questo punto (dopo la rimozione di `prodotti.scorta_minima` e l'introduzione di `giacenze.scorta_minima`).

**Cosa suggerisce questo confronto per Quack, in sintesi:**
Il selettore in topbar va bene se resta solo un *default* per i nuovi documenti (come in Odoo/SAP-per-ruolo), non se diventa l'unico modo per vedere le giacenze di una sede diversa. Le viste di elenco/report (situazione stock, elenco prodotti) devono avere un filtro esplicito e visibile sulla pagina stessa, con un'opzione reale "tutti i magazzini" che sia una somma corretta (`SUM` con `GROUP BY`), non un valore preso a caso da una riga qualunque. Il modello a documento con stato per i trasferimenti, già scelto in Quack, è coerente con tutti e quattro i sistemi esaminati — non è una scelta stravagante, è lo standard di mercato.

---

*Documento di studio collegato a: Piano di Implementazione Multi-Magazzino, Parte 3 e 4; Trasferimenti tra Magazzini come Documento.md.*
