# ADR, Schede di Sicurezza, Permesso di Vendita: come lo fanno davvero i grandi ERP (backend, frontend, DB, UX) — e cosa consiglio per Quack

Questo documento è diviso in due parti. La Parte 1 è la ricerca: cosa fanno concretamente i sistemi enterprise (SAP) e i software verticali per l'industria chimica (Sphera, Trace One, UL Ultrus, Chemwatch, e i player italiani Chemeter/SIAM, Produco/Softsystem, TeamChemical, EPY, eQgest) sui tre temi, a livello di backend, frontend, database e UX. La Parte 2 è la raccomandazione per Quack, costruita da zero sulla base di quella ricerca, senza tener conto di come è fatto oggi il vostro gestionale.

---

# PARTE 1 — RICERCA

## 1\. ADR / Trasporto merci pericolose

### 1.1 Come è strutturato in SAP (il riferimento enterprise)

SAP gestisce questo dominio nel modulo **EHS-DGP (Dangerous Goods Management)**, che non è un modulo isolato: è agganciato sia all'anagrafica materiali sia al ciclo vendite/spedizioni (SD). La logica architetturale è questa:

- **Livello dati di base**: esiste una "Specification Workbench" (transazione `CG02`/`CG02BD`) che è un database di oggetti EH\&S — sostanze, agenti, classificazioni merci pericolose, codici rifiuto, imballaggi. Ogni sostanza ha un "property tree" con nodi come "Composition" e "Possible hazards", sotto cui stanno classi e caratteristiche con valori assegnati. È lo stesso concetto di una tabella sostanze con proprietà tipizzate, ma modellato ad albero per essere estensibile senza modificare lo schema fisico ogni volta che serve un nuovo attributo normativo.  
- **Fonte di verità normativa: "Phrases" (frasi)**: SAP non salva il testo delle frasi H/P (o equivalenti) duplicato ovunque. Le "phrases" vivono in una libreria centrale (transazioni `CG12`/`CGCZ`) e vengono referenziate come value assignment dalle sostanze. Questo è esattamente il pattern "tabella statica frasi CLP \+ pivot" — confermato come best practice reale, non solo teoria da consulente.  
- **Check integrati nel flusso commerciale, non in un modulo separato**: il punto architetturale più importante è che i controlli ADR **non avvengono in un'unica schermata "verifica ADR"**, ma sono agganciati come hook a più stadi del processo vendita: offerta, ordine di vendita, documento di consegna, documento di trasporto. Il sistema calcola un flag "Contains DG" a livello di testata del documento appena viene inserita una riga con materiale pericoloso, e da lì attiva le verifiche di ammissibilità (nazione di destinazione, modalità di trasporto, percorso). I controlli possono girare in automatico o essere lanciati manualmente da un operatore.  
- **Output documentale generato da template**: i documenti di trasporto (colonna ONU, classe, gruppo imballaggio, etichette) sono generati con SAPscript/print program collegati ai master data, personalizzabili per formato ma alimentati dagli stessi dati normativi.

Fonti: SAP Help Portal — Dangerous Goods Management (EHS-DGP); Dangerous Goods Checks in Shipping/Shipment Documents; SI-PRO — SAP EHS Dangerous Goods Management; SAP Community — Dangerous Goods Management Intro.

### 1.2 Cosa fanno i verticali specializzati (più vicini al vostro caso d'uso reale)

I software italiani dedicati al settore chimico non replicano la complessità SAP (che è pensata per multinazionali con flussi export multi-paese), ma tengono lo stesso principio di fondo — **classificazione condivisa a livello di sostanza, generazione documentale automatica agganciata ai movimenti di magazzino/vendita** — con un'interfaccia molto più snella:

- **Chemeter (SIAM)**: classifica automaticamente le merci secondo ADR/RID, IMDG e IATA (cioè lo stesso dato di classificazione alimenta trasporto su strada, ferrovia, mare e aereo — un solo modello dati, output multipli) e genera i documenti di trasporto a partire da quella classificazione. Ha un editor etichette integrato conforme a CLP/GHS.  
- **Produco (Softsystem)**: il modulo Safety/ADR è descritto esplicitamente come generatore di documentazione **collegato all'analisi dei movimenti di magazzino** — cioè non è un modulo a sé, pesca dai documenti di carico/scarico esistenti (ingresso/uscita) per capire quali articoli con classe di pericolo sono coinvolti, e da lì produce report e documenti conformi.  
- **eQgest**: genera lettere di vettura (consignment note) sia per inserimento diretto sia importando dati da bolle di consegna del gestionale collegato; il database delle regole ADR viene aggiornato dal fornitore del software (versione ADR biennale) e il sistema segnala automaticamente le esenzioni applicabili (es. quantità limitate/esenti).  
- **TeamChemical**: gestisce etichettatura con simboli di pericolo e frasi di rischio stampabili in qualsiki formato, gestione barcode sui movimenti (riduce errori di data entry sul lotto/quantità che finisce sul documento di trasporto), e stampa automatica dei documenti di trasporto.

**Pattern comune, in una frase**: la classificazione ADR vive come dato "a monte" legato alla sostanza/prodotto, e il documento di trasporto è generato "a valle" come output automatico agganciato al flusso ordine → bolla/DDT → spedizione, non come task manuale separato. L'errore da evitare, che questi sistemi evitano tutti, è avere un operatore che ricompila a mano i dati ADR sul documento di trasporto: il rischio di errore umano su un dato che ha conseguenze legali (multe, blocco del trasportatore) è troppo alto.

Fonti: SIAM — Chemeter; Softsystem — ADR e SDS in Produco; eQgest — Documentazione ADR; Team Duemila — TeamChemical.

---

## 2\. Schede di Sicurezza (SDS)

### 2.1 SAP Product Safety (EHS-SAF)

- **Authoring collegato ai master data, non un editor libero**: le SDS non si scrivono a mano in un editor di testo dentro SAP. Vengono generate a partire dai dati già strutturati nella specification (composizione, classificazione, frasi H/P, proprietà fisico-chimiche) più i dati del fornitore/materiale. Questo garantisce che, se cambia una frase H a livello normativo, tutte le SDS che la referenziano si aggiornano, invece di dover ritoccare N documenti PDF a mano.  
- **Gestione esplicita dei dati fornitore**: SAP prevede la gestione delle "supplier specifications" — dichiarazioni e dati di sicurezza forniti dal fornitore — con integrazione al materiale e agli ordini SAP. I documenti (comprese le SDS ricevute dal fornitore) sono archiviati "nel loro contesto", cioè agganciati sia al materiale sia al fornitore specifico.  
- **Distribuzione automatica con trigger**: quando una SDS viene aggiornata, il sistema può attivare la "spedizione successiva automatica" (auto-resend) dei report se i dati sottostanti (specifiche o frasi) cambiano — senza intervento manuale.

Fonti: SAP Help Portal — Product Safety (EHS-SAF), Hazardous Substance Master; SI-PRO — SAP EHS Product Safety & Product Stewardship.

### 2.2 I player globali SDS-only (Sphera, Trace One, UL Ultrus, Chemwatch)

Questi sistemi sono più maturi di un modulo ERP generico proprio perché fanno *solo* questo, e la loro architettura converge su un pattern comune a 4 livelli:

1. **Database regolatorio centralizzato e mantenuto dal vendor**: Sphera parla esplicitamente di "regulatory intelligence" — un livello che traccia come cambiano le normative chimiche nelle diverse giurisdizioni e propaga l'impatto sulle SDS esistenti. UL Ultrus ha "ChemADVISOR", un database con oltre 8.000 liste regolatorie e dati su 600.000+ sostanze. Chemwatch (GoSDS) ha 200.000+ sostanze pre-classificate consultabili durante l'authoring. **Questo è il pezzo che nessun ERP interno può replicare da solo**: tenere aggiornato un database regolatorio multi-giurisdizione è un lavoro a tempo pieno di un team di chimici regolatori, motivo per cui anche aziende SAP-based comprano un content-provider esterno (es. UL Ultrus vende proprio "ChemAdvisor per SAP Product Compliance", cioè il contenuto regolatorio da innestare dentro SAP).  
2. **Costruzione della formulazione a livello di componente (CAS \+ concentrazione)**: la SDS non si scrive come testo libero, si costruisce dichiarando i singoli componenti della miscela con CAS e percentuale, e il sistema calcola la classificazione GHS risultante applicando le regole di calcolo (metodo additivo/soglie) — lo stesso principio del CLP.  
3. **Workflow di approvazione prima della pubblicazione**: Chemwatch ha un modulo "Approvals" dedicato per costruire flussi di autorizzazione — manager e altri stakeholder possono approvare, respingere o richiedere modifiche prima che una SDS/etichetta venga usata operativamente (acquisto, stoccaggio, uso). Questo è un dettaglio importante lato UX: l'authoring e la pubblicazione sono due stati separati, con uno step di revisione umana in mezzo, non un editor "salva e pubblica".  
4. **Distribuzione con audit trail e versioning esplicito**: Trace One collega l'authoring SDS al gestionale del cliente per ottenere l'elenco contatti aggiornato e tenere un registro dettagliato di chi ha ricevuto quale SDS e quando, notificando automaticamente i destinatari quando c'è un aggiornamento. UL Ultrus ha un "publishing tool" personalizzabile (branding, layout) con controllo granulare su chi può accedere a quale documento, sia internamente sia verso l'esterno (portale clienti).

Fonti: Sphera — SDS Management Software; Trace One — SDS Authoring/Distribution; UL Solutions — ULTRUS SDS Authoring and Labeling, ChemAdvisor for SAP; Chemwatch — GoSDS, AuthorITe, Approvals module.

### 2.3 I verticali italiani (SDS \+ etichette \+ ADR nello stesso motore dati)

Il dato interessante emerso dalla ricerca è che **tutti** i player italiani (Chemeter, EPY, eQgest, TeamChemical) trattano SDS, etichetta CLP e documento di trasporto ADR come **tre output diversi generati dallo stesso motore di classificazione**, non tre moduli scollegati:

- **EPY**: redazione automatica della SDS secondo Reg. 2015/830 (EU) e adattamenti GHS di Cina/USA/Canada; distribuzione automatica al primo acquisto e a ogni revisione (trigger sull'evento commerciale, non un task manuale); gestione degli scenari di esposizione per le SDS estese.  
- **eQgest**: SDS conformi contemporaneamente a più di 15 normative (CLP, REACH, ADR, ABNT NBR 14725 Brasile, HCS OSHA USA), multilingua, con storico versioni consultabile.  
- **Chemeter**: stessa base dati alimenta SDS, e-FDS (SDS elettronica estesa), documentazione ADR e fascicoli PCN (Poison Centres Notification — la notifica UFI ai centri antiveleni, un altro obbligo collegato che magari vi serve in futuro).

**Perché questo pattern conta per voi**: se costruite ADR e SDS come moduli con un modello dati condiviso a monte (stessa sostanza/prodotto → stessi dati di composizione e classificazione), potete generare tutti e tre gli output (SDS, etichetta, documento di trasporto) senza reinserire dati, e soprattutto senza rischiare disallineamenti tra "quello che dice la SDS in sezione 14" e "quello che c'è scritto sul documento di trasporto" — un'incoerenza che in un controllo capita di essere sanzionabile su entrambi i fronti.

Fonti: Epy — SDS redazione automatica; eQgest — Scheda di Sicurezza; SIAM — Chemeter.

---

## 3\. Permesso di vendita (verifica licenza/autorizzazione cliente)

Qui non esiste un "modulo ERP chimico" dedicato standard come per ADR/SDS — il pattern architetturale più maturo e comparabile viene da un dominio adiacente ma concettualmente identico: il **trade compliance / denied party screening** (SAP GTS), che risolve esattamente lo stesso problema ("posso vendere questo a questo cliente in queste condizioni?") in un contesto diverso (embarghi/sanzioni invece di precursori). Vale la pena guardarlo perché è il caso più maturo e testato su scala enterprise.

### 3.1 SAP GTS — Sanctioned Party List Screening (il pattern architetturale di riferimento)

- **Screening automatico agganciato alla creazione del documento commerciale**: quando un ordine o una consegna viene creato nel sistema gestionale (il "feeder system"), i dati del partner (cliente/fornitore) vengono inviati a GTS e confrontati contro le liste caricate (Denied Party List). Se il cliente compare in lista, **il documento viene bloccato automaticamente** per l'elaborazione successiva, finché qualcuno non lo sblocca manualmente dopo verifica.  
- **Due modalità di screening**: sincrono (il controllo avviene in tempo reale nell'istante in cui l'ordine è creato, prima che l'utente possa proseguire) oppure asincrono (il controllo gira in batch, e i documenti "sospesi" emergono in una coda di lavoro dedicata). Questa distinzione è rilevante per voi: uno screening sincrono blocca l'operatore sul momento (miglior compliance, peggiore fluidità se il servizio di verifica è lento); uno asincrono lascia procedere l'ordine ma lo marca "da verificare" con blocco solo sulla spedizione/fatturazione finale.  
- **Gestione dei documenti bloccati come coda di lavoro separata**: non è un semplice popup di errore — i documenti bloccati restano in uno stato "sospeso" visibile e gestibile da un ruolo dedicato (compliance officer), che verifica il falso positivo/vero positivo e decide se sbloccare.

Fonti: Descartes Visual Compliance — SPL Screening for SAP; SAP — Global Trade Services Sanctioned party list screenings; SurE Systems — Effective SAP GTS SPL Screening Process.

### 3.2 Cosa impone realmente il Regolamento (UE) 2019/1148 (precursori di esplosivi) — il caso concreto che avete

Qui la normativa stessa descrive quasi un flusso applicativo, punto per punto:

- **Verifica per ogni transazione, non una tantum**: l'operatore economico che rende disponibile un precursore soggetto a restrizione (Allegato I) a un privato deve, per ogni transazione, verificare la prova di identità e la licenza della persona, e registrare la quantità venduta **sulla licenza stessa** — cioè la licenza ha un "saldo residuo" che si consuma vendita dopo vendita, non è un semaforo verde/rosso statico.  
- **Auto-dichiarazione del cliente per gli usi professionali**: per i clienti professionali (non privati) si usa tipicamente una "Customer Statement" — dichiarazione firmata, con copia del documento d'identità, con validità massima di 12 mesi (dopo la quale va rinnovata).  
- **Obbligo di segnalazione entro tempi stretti**: transazioni sospette, sparizioni significative e furti vanno segnalati all'autorità competente entro 24 ore dalla scoperta (non 24 ore dalla vendita — dalla scoperta dell'anomalia).  
- **Conservazione dati minimo 18 mesi**: le registrazioni delle transazioni vanno tenute per almeno 18 mesi a fini di tracciabilità e indagine.  
- **Due soglie diverse, due obblighi diversi**: Allegato I (restrizione piena — es. acido nitrico, perossido di idrogeno, nitrato d'ammonio oltre soglia) richiede licenza/verifica preventiva obbligatoria; Allegato II (solo segnalazione) non richiede licenza ma richiede comunque due diligence e segnalazione di transazioni sospette.

Questo è di fatto uno "SAP GTS in miniatura" con logica identica: verifica preventiva di un'autorizzazione del cliente prima che l'ordine possa procedere, consumo di un plafond/soglia, log della transazione, segnalazione con SLA temporale stretto.

Fonti: EUR-Lex — Regolamento (UE) 2019/1148; Linee guida Commissione UE su precursori di esplosivi (FECC); The Compliance People — Regulation EU 2019/1148; Yara — Explosives Precursors Regulation.

---

# PARTE 2 — RACCOMANDAZIONE PER QUACK (da zero, senza guardare lo stato attuale)

L'idea di fondo, coerente con tutta la ricerca sopra: **i tre moduli condividono lo stesso principio architetturale** — un livello di dati normativi "a monte" condiviso per sostanza (classificazione, soglie, frasi), un livello di verifica/generazione automatica agganciato ai documenti commerciali (ordine, DDT, fattura), e un livello di audit/log per la parte che ha conseguenze legali dirette (SDS inviata, transazione precursori, documento ADR emesso). Non serve costruire tre sistemi diversi: serve costruire **un motore di regole di compliance condiviso**, con tre configurazioni diverse sopra.

## 4\. Architettura backend generale consigliata

**Un "Compliance Engine" come layer trasversale, non tre moduli isolati.** Concretamente in PHP: un servizio (classe/namespace `App\Compliance`) con un'interfaccia comune tipo:

CompliancePolicy::check(Prodotto $prodotto, Cliente $cliente, Quantita $quantita, Documento $contesto): CheckResult

dove `CheckResult` è `{esito: passa|blocca|richiede_verifica, motivo, dati_da_mostrare}`. Le tre policy concrete (ADR, precursori, eventuali fitosanitari futuri) implementano la stessa interfaccia. Vantaggi pratici per voi, sviluppatori PHP su un progetto singolo: aggiungere una quarta categoria di restrizione in futuro (è quasi certo che succeda: fitosanitari, sostanze CLP con obbligo UFI/PCN, export extra-UE) significa scrivere una nuova policy, non ridisegnare il DB.

**Hook nei punti giusti del flusso commerciale, non uno screen "verifica compliance" separato.** Sulla base del pattern SAP GTS/EHS-DGP: il check gira automaticamente quando si aggiunge una riga prodotto a un ordine/preventivo (per dare feedback immediato all'operatore commerciale, non a spedizione già pronta), e viene ri-verificato in modo bloccante al momento dell'evasione/spedizione (perché tra la creazione dell'ordine e la spedizione possono passare settimane, e nel frattempo una licenza cliente può scadere). Due controlli, stesso motore, contesto diverso — esattamente la distinzione sincrono/asincrono vista in SAP GTS.

**Un registro eventi (audit log) unico e append-only per tutto ciò che ha rilevanza legale.** Una singola tabella `compliance_eventi` (o log applicativo strutturato) che registra ogni check eseguito, esito, chi/quando, indipendentemente dal tipo (ADR, precursori, SDS inviata). Questo vi dà gratis la "conservazione 18 mesi" richiesta dal Reg. 2019/1148 e vi mette al riparo in caso di ispezione: potete esibire uno storico coerente invece di dover ricostruire i dati da tabelle sparse.

## 5\. Modulo ADR — backend, DB, frontend/UX

**Backend/DB**: mantenete la separazione a tre livelli confermata sia dalla ricerca originale sia da tutti i verticali italiani — tabella statica dei codici ONU (aggiornata solo alle revisioni biennali ONU/ADR), tabella sostanza con proprietà fisico-chimiche e classificazione, tabella di collegamento prodotto↔classificazione con eventuali override (concentrazione specifica, gruppo imballaggio diverso da quello standard). Questo livello è condiviso tra SKU con la stessa sostanza — è l'unico dei tre moduli dove la condivisione tra prodotti è corretta e voluta.

**Generazione documento di trasporto**: non un form da compilare a mano. Come Produco ed eQgest, il documento di trasporto ADR si genera come output automatico a partire dalle righe del DDT/ordine di spedizione, pescando la classificazione già presente sul prodotto. L'operatore vede un'anteprima e può solo confermare o segnalare un'anomalia (mai correggere a mano i dati normativi — se sono sbagliati, si corregge la classificazione a monte, altrimenti si creano incoerenze tra spedizioni diverse dello stesso prodotto).

**UX consigliata**: nella scheda prodotto, un tab "ADR" con badge di stato (Non ADR / ADR classificato / ADR — dati incompleti) visibile già nella lista prodotti, così chi inserisce un ordine vede a colpo d'occhio se sta maneggiando merce pericolosa. Nel form ordine/DDT, quando compare un prodotto ADR, un pannello laterale non bloccante mostra numero ONU, classe, eventuale quantità esente calcolata in automatico (le famose "quantità limitate" che esentano da alcuni obblighi ADR sotto soglia) — informazione utile all'operatore subito, senza dover aprire la scheda prodotto.

## 6\. Modulo Schede di Sicurezza — backend, DB, frontend/UX

**Il punto fermo, confermato ora anche dai vendor SDS-only globali oltre che da REACH**: la SDS è un documento-fornitore-prodotto, mai un documento-sostanza. Il modello dati corretto ha la SDS agganciata all'entità "prodotto acquistato da un fornitore specifico" (non alla sostanza chimica condivisa), con versioning esplicito (numero revisione, data emissione, lingua) e un flag di "scheda corrente" — esattamente il pattern che Sphera/Trace One/UL Ultrus chiamano "version control and audit trail".

**Workflow di stato, non solo upload file.** Prendendo il pattern del modulo "Approvals" di Chemwatch: una SDS caricata non deve diventare automaticamente "quella ufficiale in uso" — meglio uno stato esplicito: `caricata` → `in verifica` (qualcuno controlla che sia leggibile, in lingua corretta, coerente col prodotto) → `attiva` (quella che va ai clienti) → `superata` (rimpiazzata da una revisione più recente, ma conservata per storico/tracciabilità). Con un'azienda piccola questo può essere un solo ruolo che fa tutti gli step, ma lo stato va comunque modellato, perché è quello che vi permette domani di rispondere "quale SDS era attiva il 3 marzo per questo prodotto" in caso di contestazione.

**Distribuzione automatica agganciata all'evento commerciale**, non un task manuale da ricordarsi: al primo ordine di un cliente su un prodotto ADR/pericoloso, e a ogni nuova revisione della SDS per prodotti già venduti a quel cliente, il sistema genera in automatico l'invio (o quantomeno la messa a disposizione tracciata, es. link scaricabile con log di chi/quando ha scaricato) — è il pattern EPY/Trace One, ed è anche l'unico modo per rispettare in modo difendibile l'obbligo REACH di fornire la SDS "non oltre la prima fornitura" e di reinviarla ai destinatari degli ultimi 12 mesi quando viene aggiornata.

**UX consigliata**: nella scheda prodotto-fornitore, un tab "Scheda di sicurezza" con la revisione corrente in evidenza (data, lingua, download) e uno storico revisioni sotto, collassato. Un piccolo pannello di "salute compliance" a livello di catalogo (una vista tipo "prodotti ADR senza SDS attiva caricata" o "SDS con più di 3 anni senza revisione") vi dà, con sforzo di sviluppo minimo, quello che i grandi sistemi vendono come "regulatory intelligence dashboard" — non serve un motore regolatorio esterno per avere questo primo livello di controllo, basta una query.

## 7\. Modulo permesso di vendita — backend, DB, frontend/UX

Qui la ricerca converge chiaramente su un pattern a "licenza con plafond che si consuma", non un semplice sì/no:

**Backend/DB**: tenete la distinzione fra tipo di restrizione (tabella dei "tipi" generica, come già capita di fare bene chi tratta più normative insieme — precursori esplosivi oggi, potenzialmente fitosanitari o altre categorie domani) e istanza di autorizzazione del singolo cliente, con: stato (in attesa/approvata/rifiutata/scaduta/sospesa), data emissione, data scadenza, documenti caricati e verificati da un operatore umano (non solo "file presente" ma un flag "verificato\_da/verificato\_il", perché la responsabilità legale è aver controllato il documento, non solo averlo archiviato). Aggiungete un log delle transazioni collegato sia all'ordine sia all'autorizzazione, con colonna esplicita "segnalato all'autorità" e relativa data — è quello che il Reg. 2019/1148 chiede letteralmente di poter dimostrare.

**Backend — logica del plafond**: se un domani gestite precursori dell'Allegato I con soglia quantitativa sulla licenza (come previsto dal regolamento — la licenza riporta quanto è stato consumato), il check non è binario ma sottrae dalla soglia residua ad ogni vendita e blocca quando il residuo non copre la quantità richiesta. Non serve costruirlo subito se oggi non lo usate, ma vale la pena lasciare lo spazio nel modello dati (un campo "quantità autorizzata" e "quantità consumata" sull'autorizzazione) per non dover rifare la tabella quando servirà.

**UX consigliata, modellata sul pattern SAP GTS "coda di documenti bloccati"**: quando un operatore prova a mettere in ordine un prodotto soggetto a restrizione per un cliente senza autorizzazione valida (assente, scaduta, o senza plafond sufficiente), il sistema non deve limitarsi a un messaggio d'errore generico — deve mostrare subito "manca il documento X" o "l'autorizzazione è scaduta il \[data\]" con un link diretto a "richiedi/carica autorizzazione" per quel cliente, così l'ostacolo diventa un'azione immediata invece di un vicolo cieco che costringe a uscire dal form ordine. Un ruolo "compliance" separato (anche se nella pratica quotidiana è la stessa persona che fa altro) vede una coda dedicata di autorizzazioni in scadenza nei prossimi 30 giorni e di documenti caricati-ma-non-ancora-verificati, così il controllo è proattivo e non scoperto solo quando arriva l'ordine.

## 8\. Ruoli e permessi (trasversale ai tre moduli)

Coerente con quanto visto sia in SAP (ruoli distinti per data steward EHS vs operatore vendite) sia in Chemwatch (modulo Approvals con "manager e stakeholder" separati da chi fa la richiesta): distinguete almeno due ruoli anche se oggi coincidono nella stessa persona — chi **inserisce/modifica** i dati normativi e i documenti (classificazioni ADR, SDS caricate, autorizzazioni ricevute) e chi **autorizza/verifica** che siano corretti prima che diventino "attivi" e vincolanti per le vendite. Anche in un'azienda piccola, separare questi due stati nel dato (non necessariamente nella persona) è quello che vi permette di rispondere "chi ha verificato questo documento e quando" — è la parte che, guardando la ricerca, torna sistematicamente in ogni sistema maturo, dai grandi enterprise ai piccoli verticali italiani.

---

## Fonti

- [SAP Help Portal — Dangerous Goods Management (EHS-DGP)](https://help.sap.com/docs/SAP_ERP/47b7039ad8c44808aafbedb12029db94/b424ba53422bb54ce10000000a174cb4.html)  
- [SAP Help Portal — Dangerous Goods Checks in Shipping Documents](https://help.sap.com/docs/SAP_S4HANA_ON-PREMISE/2fd59f821b7d4ecd9b93fc0441b8c95e/4d909b2a9ec314b0e10000000a42189c.html)  
- [SAP Help Portal — Dangerous Goods Checks in Shipment Documents](https://help.sap.com/docs/SAP_ERP/47b7039ad8c44808aafbedb12029db94/8027bf53d25ab64ce10000000a174cb4.html)  
- [SAP Community — Dangerous Goods Management: Intro](https://blogs.sap.com/2014/01/27/dangerous-goods-management-intro/)  
- [SI-PRO — SAP EHS Dangerous Goods Management](https://www.si-pro.com/en/sap-ehs/sap-dangerous-goods-management/)  
- [SAP Help Portal — Product Safety (EHS-SAF)](https://help.sap.com/docs/SAP_ERP/e3e7c06560a54d14ba7f4c52a86635da/e624ba53422bb54ce10000000a174cb4.html)  
- [SAP Help Portal — Hazardous Substance Master](https://help.sap.com/docs/SAP_S4HANA_ON-PREMISE/ff6d35a34fbe4bea99e4ac41b0d5db03/ea66bd534f22b44ce10000000a174cb4.html)  
- [SI-PRO — SAP EHS Product Safety & Product Stewardship](https://www.si-pro.com/en/sap-ehs/sap-ehs-product-safety-product-stewardship/)  
- [SAP Community — Phrase creation with example](https://blogs.sap.com/2013/07/23/phrase-creation-with-example/)  
- [Sphera — Safety Data Sheet (SDS) Management Software](https://sphera.com/solutions/product-stewardship/chemical-management-software/sds-management-software/)  
- [Trace One — SDS Authoring Management Software](https://www.traceone.com/ehs-software/safety-data-sheets-software)  
- [Trace One — SDS Distribution & Printing](https://www.traceone.com/ehs-software/sds-distribution)  
- [UL Solutions — ULTRUS SDS Authoring and Labeling Software](https://www.ul.com/software/ultrus/sds-authoring-and-labeling-software)  
- [UL Solutions — ChemAdvisor for SAP Product Compliance](https://www.ul.com/software/ultrus/ChemAdvisor-for-SAP-Product-Compliance)  
- [Chemwatch — GoSDS](https://chemwatch.net/products/gosds/)  
- [Chemwatch — Chemeritus (Advanced Chemicals Management / Approvals)](https://chemwatch.net/products/chemeritus/)  
- [SIAM — Software Chemeter](https://www.siam-it.com/it/soluzioni/il-vostro-software-di-schede-di-sicurezza-chemeter)  
- [Softsystem — ADR e SDS in Produco](https://softsystem.it/adr-e-sds-in-produco/)  
- [Team Duemila — TeamChemical](https://www.teamduemila.it/team-chemical)  
- [Epy — SDS redazione automatica e gestione](https://www.epy.it/products/soluzioni-per-etichette-e-sds/?lang=en)  
- [eQgest — Documentazione ADR](https://www.eqgest.com/en/module/documentation-adr)  
- [eQgest — Gestione delle Schede Di Sicurezza](https://www.eqgest.com/en/module/safety-data-sheets)  
- [Descartes Visual Compliance — SPL Screening for SAP](https://www.visualcompliance.com/compliance-solutions/denied-party-screening/sap/)  
- [SAP — Global Trade Services: Sanctioned party list screenings](https://www.sap.com/assetdetail/2020/11/4cce690e-bd7d-0010-87a3-c30de2ffd8ff.html)  
- [Surety Systems — Effective SAP GTS SPL Screening Process](https://www.suretysystems.com/insights/effective-sap-gts-spl-screening-process-a-practical-guide/)  
- [EUR-Lex — Regolamento (UE) 2019/1148 (sintesi)](https://eur-lex.europa.eu/IT/legal-content/summary/marketing-and-use-of-explosives-precursors.html)  
- [FECC — European Commission Guidelines on Explosives Precursors (PDF)](https://www.fecc.org/wp-content/uploads/2020/06/European-Commission-Guidelines-Explosives-Precursors-Final-Version.pdf)  
- [The Compliance People — Regulation (EU) 2019/1148](https://thecompliancepeople.co.uk/updates/legal/regulation-eu-2019-1148/)  
- [Yara — Explosives Precursors Regulation (PDF pubblico)](https://www.yara.com/siteassets/this-is-yara/protecting-people-and-the-environment/explosive-precursors-regulation-eu-public-distribution.pdf)

