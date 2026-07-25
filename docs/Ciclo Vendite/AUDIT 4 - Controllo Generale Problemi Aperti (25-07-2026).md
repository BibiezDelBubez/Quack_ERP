# AUDIT 4 — Controllo generale, cosa resta davvero aperto nel Ciclo Vendite (25/07/2026)

Rilettura integrale di tutti i 16 documenti in `docs/Ciclo Vendite/` (`REVISIONE - Analisi Documentazione`, `AUDIT`, `AUDIT 2`, `AUDIT 3`, tutti i `PIANO -`, `Manuale_Definitivo_Ciclo_Vendite.md`, `Revisione_ciclo_vendite.md`), con verifica a campione sul codice reale (non sui documenti) per ogni punto riportato qui come ancora aperto — citazioni file:riga lette in questa sessione, non copiate da un audit precedente. Escluso tutto ciò che i documenti stessi dichiarano già risolto e verificato con esecuzione reale (è la stragrande maggioranza: negli ultimi giorni sono stati chiusi P4, P4b, la FK di `contatto_id`, la revisione ordini clienti di base, il genere degli stati preventivo, il destinatario DDT, le soglie di margine, la vista scadenza preventivi, quasi tutto l'Audit 2 e l'Audit 3). Qui restano solo gli 8 punti per cui non esiste ancora né codice né una decisione chiusa.

---

> **Nota 25/07/2026 (sera) — correzione dopo verifica di comportamento, non solo di esistenza.** I punti 1, 2, 3 e 4 sotto sono stati riverificati leggendo il comportamento reale del codice (non solo che i file/funzioni citati esistessero ancora) e contengono correzioni sostanziali rispetto alla stesura precedente. Il dettaglio della correzione è riportato in corsivo dentro ciascun punto.

## 1. La tabella `fatture`/`fatture_righe` esiste a schema ma è codice morto — la decisione di lungo termine esiste già, resta scoperto solo il coordinamento

*Corretto 25/07/2026: non è vero che "nessuna decisione è stata presa". `docs/Backlog Sviluppi Futuri.md`, voce 12 ("Modulo di fatturazione elettronica con distinzione IT/estero"), contiene un piano già scritto — nuove colonne SDI su `fatture`, generazione XML FatturaPA con ramo IT/estero fin dalla v1, `FatturaController` dedicato, decisione su provider di invio — con motivazione esplicita del rimando: "è un modulo intero non ancora iniziato, non un fix su qualcosa di esistente [...] da programmare come progetto a sé quando si deciderà di implementare la fatturazione elettronica reale". La decisione ("costruirlo davvero, ma non ora, quando emergerà il bisogno reale") è già presa. Quello che resta scoperto è più stretto di come descritto sotto: nessun documento marca esplicitamente lo schema attuale come "placeholder in attesa della voce 12" (rischio di confusione per chi legge lo schema oggi), e il design del punto 5 (vista `v_ordini_stato_fiscale`) assume una colonna `fatture.ordine_id` che il piano della voce 12 non elenca tra le colonne da aggiungere — i due documenti non sono mai stati confrontati tra loro.*

**Di che parla, con un esempio.** Nel database esiste una tabella `fatture` completa di `fatture_righe`, stati (`bozza/emessa/pagata/annullata`), tipi (`fattura/nota_credito/proforma`). Verificato ora: nessun file PHP del progetto la scrive o la legge (`grep` su `INSERT INTO fatture`, `UPDATE fatture`, `FROM fatture`, `fatture_righe` in tutto `*.php` del progetto — zero risultati). Immagina di essere un nuovo sviluppatore che apre lo schema per capire come funziona la fatturazione: vede una tabella `fatture` ben strutturata, con tanto di CHECK sugli stati, e conclude ragionevolmente che l'app la usi. Non è così: la fatturazione reale in Quack è solo un flag booleano su `ordini_clienti.stato_fatturazione` (`da_fatturare`/`fatturato`), la fattura vera si emette fuori da Quack (SGA/Doc Finance).

**Perché è un problema.** Non è un bug — nulla si rompe — ma è un'insidia silenziosa per chiunque legga lo schema in futuro (incluso un altro Claude in un'altra sessione, per la regola del punto 11 di `CLAUDE.md`: leggere questa tabella senza sapere che è morta porterebbe a un'affermazione di stato falsa). È anche un blocco concreto per un problema collegato (punto 5 sotto): il meccanismo di correzione di un ordine già fatturato, progettato ma non implementato, è stato disegnato apposta per funzionare "oggi col booleano, domani con questa tabella" — ma nessuno ha ancora deciso se questa tabella debba davvero diventare reale un giorno o vada rimossa.

**Cosa fa il codice attualmente.** `stato_fatturazione` è un `TEXT NOT NULL DEFAULT 'da_fatturare'` senza alcun vincolo `CHECK` (`db/init_sqlite.sql:1318`), aggiornato solo da `qk_ordine_cliente_segna_fatturato()`/`qk_ordine_cliente_segna_da_fatturare()` (`includes/sales_orders.php`). `fatture`/`fatture_righe` restano a schema, popolate da zero righe in ogni installazione.

**Cosa fanno gli ERP in casi simili.** Nessun grande ERP tiene a schema una tabella fiscale strutturata (stati, tipi documento, righe) senza usarla — o la fatturazione è un modulo attivo con un flusso reale (SAP SD, Odoo Invoicing), o semplicemente non esiste quella tabella finché il modulo non viene attivato. Tenere lo scheletro morto è una scelta legittima solo se dichiarata esplicitamente come "predisposizione per un modulo futuro", mai lasciata ambigua.

**Soluzione possibile, e cosa lascia scoperto.** Due strade, entrambe minime: (a) dichiarare esplicitamente nello schema e nella documentazione che `fatture`/`fatture_righe` sono una predisposizione per un futuro modulo fatturazione/note di credito, non usata oggi — zero rischio, zero lavoro tecnico, ma il rischio "qualcuno la crede attiva" resta finché non lo si scrive chiaramente ovunque; (b) rimuoverla dallo schema finché non servirà davvero, e ricrearla quando (e se) si deciderà di costruire il modulo — più pulito, ma butta via un lavoro di design già fatto e rende più costoso il giorno in cui servirà davvero (bisognerebbe riprogettarla, magari diversamente da come useranno le nuove decisioni). Nessuna delle due soluzioni tocca il problema del punto 5 (la correzione di un ordine fatturato) — quella resta comunque un buco a parte, indipendentemente da cosa si decide qui.

**Dubbi/domande aperte.** La tabella va tenuta come "predisposizione dichiarata" o rimossa? Se tenuta, chi la aggiornerà quando (e se) si deciderà di costruire un modulo fatture vero — è collegata al punto 5 sotto, e la risposta lì influenza anche questa?

---

## 2. Creare un ordine cliente si fa in due punti indipendenti, entrambi INSERT scritti a mano — non "uno strutturato e uno grezzo"

*Corretto 25/07/2026, dopo aver letto il corpo di `insertOrderHeader()` e non solo la sua firma: non sono "due filosofie diverse" nel senso di una più sicura dell'altra. Sono due INSERT manuali indipendenti con lo stesso identico rischio, solo organizzati in due file diversi.*

**Di che parla, con un esempio.** Un ordine cliente nasce da due percorsi: diretto, o da un preventivo accettato. Verificato leggendo il corpo intero, non solo la firma: il percorso diretto passa da `SalesOrderRepository::insertOrderHeader()` (`app/Repositories/SalesOrderRepository.php:22-58`), chiamata da `OrderCalculatorService::insertDirectOrder()` — ma il metodo, aperto, è anch'esso un `INSERT INTO ordini_clienti (numero_ordine, cliente_id, preventivo_id, ...)` scritto a mano con l'elenco esplicito di ~48 colonne e altrettanti `?` posizionali, incapsulato in una classe ma non più "sicuro" di una funzione qualsiasi. Il percorso da preventivo scrive lo stesso tipo di `INSERT INTO ordini_clienti (...)` a mano, con l'elenco di ~44 colonne scritto per esteso nel codice (`includes/sales_quotes.php:1157`), dentro `qk_preventivo_conferma_ordine()`. Sono due copie indipendenti dello stesso pattern (SQL manuale con colonne esplicite), non una via strutturata e una grezza.

**Perché è un problema.** Se domani serve aggiungere una colonna obbligatoria a `ordini_clienti` (è già successo più volte in questo progetto: `magazzino_id`, `versione`, `contatto_id` con FK), bisogna ricordarsi di aggiornare **due** posti scritti in stili diversi — uno strutturato, uno con un elenco manuale di colonne — invece di uno solo. È esattamente il tipo di violazione DRY che in questo stesso progetto ha già causato un bug reale altrove (`RfqController::apiCreaProdotto()` vs `qk_crea_prodotto_rapido()`, citato in `CLAUDE.md` punto 1): non è un rischio teorico, è un pattern che qui ha già fatto danni.

**Cosa fa il codice in casi analoghi.** Il Ciclo Acquisti, per confronto, è internamente coerente: un'unica filosofia a funzioni per creare un ordine fornitore, non due. Il problema è isolato al Ciclo Vendite, e dentro lo stesso ciclo — non è nemmeno un confronto tra cicli diversi.

**Cosa fanno gli ERP in casi simili.** Il principio comune (Odoo, SAP, Dynamics) è un solo punto di scrittura per ogni entità di dominio, indipendentemente da quale flusso UI l'ha originata — la creazione di un ordine di vendita passa sempre dallo stesso livello di persistenza, che sia stato generato da un preventivo o inserito a mano.

**Soluzione possibile, e cosa lascia scoperto.** Far scrivere anche `qk_preventivo_conferma_ordine()` tramite `SalesOrderRepository::insertOrderHeader()` invece del suo INSERT manuale — stessa colonna, stesso vincolo, un solo posto da mantenere. Il lavoro non è banale: le ~44 colonne vanno mappate una per una controllando che nessuna si perda nel passaggio (stesso rischio già descritto al punto 2 di `CLAUDE.md`, colonne `NOT NULL` dimenticate), e serve un test di non regressione sull'intero flusso preventivo→ordine, non solo sintattico. Non risolve da sé altri problemi collegati (es. il punto 3 sotto, PDF/DOCX) — è un intervento isolato.

**Dubbi/domande aperte.** Vale la pena unificare ora, o è un rischio non giustificato rispetto al beneficio (il codice duplicato oggi funziona, nessun bug noto derivato da questo)? Nessuna decisione è mai stata presa in nessun documento — è segnalato e riconfermato tre volte (Audit 2, Audit 3 il 23/07, Audit 3 riverificato il 24/07) ma mai messo in agenda.

---

## 3. PDF e DOCX del preventivo: dati già unificati, solo il layout resta scritto due volte in tecnologie incompatibili

*Corretto 25/07/2026: la stesura precedente lasciava intendere che l'intera generazione fosse duplicata. Non è così: i dati sono già uniti in un solo punto, resta duplicato solo il layout — un problema più piccolo, ma comunque reale e irrisolto.*

**Di che parla, con un esempio.** Il preventivo si può esportare in PDF (motore mPDF, che vuole una stringa HTML) e in DOCX (motore PhpWord, che vuole un albero di oggetti — `addTable()`/`addRow()`/`addCell()`, un'API completamente diversa, non testuale). Verificato leggendo il corpo dei metodi: `SalesQuotesController::pdf()` (riga 820) chiama `preparaDatiStampaOfferta($id)` **una sola volta** e passa lo stesso array `$dati` sia a `SalesQuoteDocumentService::generatePdf($dati)` sia, nel metodo gemello `docx()`, a `::generateDocx($dati)` (`app/Services/SalesQuoteDocumentService.php:13` e `:51`) — quindi numero preventivo, righe, sconti, totali, contatto e indirizzo sono già letti e calcolati una volta sola, non due. Quello che resta genuinamente duplicato è solo la parte di **layout**: come tradurre quei dati in una tabella PDF vs in una tabella Word, scritto due volte perché le due librerie non condividono API. Se domani cambia il layout (es. una nuova colonna nella tabella righe), va comunque cambiato in due punti — ma è un problema di presentazione, non di dati.

**Perché è un problema.** È un output visibile al cliente — il rischio più alto identificato in tutto l'Audit 2 proprio per questo motivo, e per questo deliberatamente non affrontato di fretta finora.

**Cosa fa il codice in casi analoghi.** `RfqController` lato Acquisti ha lo stesso bisogno (PDF/DOCX di una richiesta d'offerta) e delega già a un Service + template esterno — ma anche lì restano due motori diversi per i due formati, non è un problema che il progetto abbia già risolto altrove da copiare.

**Cosa fanno gli ERP in casi simili.** La pratica comune è un solo motore di rendering (tipicamente HTML→PDF anche per il DOCX, o un motore che genera entrambi da un unico template dichiarativo) invece di due tecnologie separate — ma è un cambiamento architetturale non banale, non un fix locale, e non tutti gli ERP mid-market lo fanno (dipende dal budget dedicato ai documenti stampati).

**Soluzione possibile, e cosa lascia scoperto.** Il data-provider unico (proposto in Audit 2 §5 V7) esiste già (`preparaDatiStampaOfferta()` + `SalesQuoteDocumentService`) — quella parte del lavoro è fatta. La vera unificazione del layout avrebbe una sola strada tecnica plausibile: usare il convertitore HTML→Word di PhpWord (`\PhpOffice\PhpWord\Shared\Html::addHtml()`), scrivendo un solo template HTML riusato sia per mPDF sia, tramite quel convertitore, per il DOCX. Verificato con grep dedicato: **non è mai stato usato in nessun punto del progetto**. Ha limiti reali di fedeltà (CSS complesso e tabelle articolate spesso non traducono 1:1 tra i due formati) — richiederebbe la stessa verifica byte-per-byte/visiva dedicata già ritenuta necessaria per qualunque intervento su quest'area. L'alternativa più economica è accettare esplicitamente un DOCX "più semplice", non un clone pixel-perfect del PDF — scelta di prodotto, non solo tecnica, mai posta all'utente.

**Dubbi/domande aperte.** Va fatto ora (rischio più alto, ma anche l'intervento con la superficie di test più delicata) o resta rimandato? Nessuna risposta mai data: l'Audit 2 lo aveva esplicitamente lasciato in sospeso "in attesa di conferma" e non è mai arrivata.

---

## 4. Spedire lo stesso ordine su indirizzi diversi non è solo scomodo — è strutturalmente impossibile oggi

*Corretto 25/07/2026, il problema è più serio di come descritto in origine: non "manca una UI comoda", ma "il campo non esiste nel form e il valore è sempre ereditato dall'ordine, mai scelto per la singola spedizione".*

**Di che parla, con un esempio.** Un cliente ordina merce che deve arrivare in due sedi diverse (es. 60 unità alla sede di Milano, 40 a quella di Bari). Il Manuale la descrive solo in una riga concettuale ("un ordine può generare N spedizioni, ognuna col proprio indirizzo"), mai come schermata o flusso concreto. Verificato ora, andando oltre lo schema fino al comportamento reale: `app/views/sales/shipments/_nuova.php` (il form "Nuova Spedizione") **non ha alcun campo indirizzo** — zero occorrenze di `indirizzo_spedizione_id` in tutta la vista. `SpedizioneCalculatorService.php:91` conferma perché: `$indirizzoSpedizioneId = ... (int) $order['indirizzo_spedizione_id']` — l'indirizzo di ogni spedizione viene **sempre ereditato automaticamente dall'ordine**, mai scelto dall'operatore al momento della spedizione.

**Perché è un problema.** Non è che manchi una guida nella UI per fare bene una cosa possibile — è che la cosa stessa non è possibile: **oggi non si può creare, con lo strumento attuale, una spedizione dello stesso ordine con un indirizzo diverso da quello congelato sull'ordine**. Il caso Milano/Bari descritto nel Manuale non è realizzabile, non solo scomodo da realizzare.

**Cosa fa il codice per casi simili.** Lo split multi-lotto (assegnare più lotti alla stessa riga per soddisfare la quantità) è invece un flusso guidato reale, con pre-allocazione FEFO automatica in UI (`app/views/sales/shipments/_nuova.php:186-234`). Lo split multi-indirizzo non ha l'equivalente.

**Cosa fanno i grandi ERP.** SAP e Dynamics trattano la "consegna parziale su indirizzo diverso" come un caso esplicito del processo di evasione ordine (partial delivery con indirizzo di destinazione per riga/spedizione), con un'interfaccia dedicata che aiuta a bilanciare le quantità tra le spedizioni. Odoo permette di creare consegne parziali (backorder) ciascuna con la propria destinazione, ma anche lì senza un aiuto automatico nella suddivisione.

**Soluzione possibile, e cosa lascia scoperto.** Costruire una UI dedicata (scegliere l'indirizzo per ogni sotto-gruppo di righe/quantità prima di generare le spedizioni) richiederebbe innanzitutto affrontare il gap collegato del punto §1.5/§1.7 già chiuso solo in parte: `spedizioni` non ha nessuno snapshot indirizzo congelato (solo FK), quindi anche costruendo la UI, il problema "se l'indirizzo cambia dopo, la spedizione già emessa lo racconta male" (già corretto per il DDT) resterebbe aperto per la spedizione in sé. Non risolve nulla del problema di stampa se non si affronta anche quello.

**Dubbi/domande aperte.** È un caso d'uso reale e frequente per Quack (multi-sede per lo stesso cliente), o marginale? Nessuna domanda è mai stata posta esplicitamente all'utente su questo punto — è rimasto solo segnalato, mai discusso.

---

## 5. Correzione di un ordine già fatturato: progettata nel dettaglio, ma zero righe di codice scritte, e dipende da un piano gemello non ancora deciso

**Di che parla, con un esempio.** Un ordine di 500 taniche viene confermato per errore invece di 50. Se l'errore emerge prima della spedizione, oggi si corregge con "Crea Nuova Versione" (già implementato e funzionante, vedi `PIANO - Revisione Ordini Clienti`). Ma se l'errore emerge **dopo** che l'ordine è stato marcato `stato_fatturazione='fatturato'`, oggi non esiste alcun percorso: la revisione è bloccata sempre (decisione presa deliberatamente, §4.3 dello stesso piano, corretta e da mantenere), e non c'è nemmeno un ripiego informale, perché il problema è un errore sul documento stesso, non una consegna mancante.

**Perché è un problema.** L'operatore che scopre l'errore non ha letteralmente nessuno strumento in Quack per segnalarlo — deve avvisare l'amministrazione fuori sistema (telefonata, email), e Quack non tiene traccia da nessuna parte che quella correzione sia dovuta.

**Cosa fa il codice attualmente.** Nulla: è stato progettato un intero schema (tabelle `richieste_correzione_ordine`/`richieste_correzione_righe`, vista adattiva `v_ordini_stato_fiscale`, permessi maker-checker `can_approvare_correzioni`/`can_bypass_maker_checker`) in `PIANO - Revisione Ordini Clienti (Nuova Versione).md` §7, con tanto di audit che ha corretto 5 errori fattuali della prima stesura (una tabella di transizioni mai esistita, cartelle di codice mai esistite, un controllo `can('admin')` che non avrebbe mai funzionato, una tabella di audit reinventata quando ne esiste già una dormiente `log_stati_ordini`) — ma lo stato finale dichiarato esplicitamente nel piano stesso (§7.7.8) è: **"resta non autorizzata l'implementazione di qualunque tappa"**.

**Trovata anche una falla di sicurezza collaterale, non ancora corretta**: `qk_ordine_cliente_segna_da_fatturare()` (`includes/sales_orders.php:358-362`, verificato) è un `UPDATE` grezzo senza alcun controllo di permesso — chiunque arrivi al bottone già esistente può togliere il flag `fatturato` e poi cliccare "Crea Nuova Versione", aggirando completamente il blocco del §4.3. Il blocco quindi oggi si fida di un campo che chiunque può disattivare con un click, senza permesso speciale e senza lasciare traccia.

**Cosa fanno gli ERP in casi simili.** SAP SD, Odoo, NetSuite e Dynamics 365 non modificano mai un documento dopo che esiste un documento fiscale a valle: la correzione passa sempre da un documento nuovo collegato esplicitamente all'originale (Credit Memo Request in SAP, Credit Note in Odoo/NetSuite), mai una riscrittura del passato — principio già recepito nel design (§7.4), non nell'implementazione.

**Soluzione possibile, e cosa lascia scoperto.** Il design esistente (§7.7) è stato validato come "base ragionevole e proporzionata per le dimensioni di Quack", non "livello enterprise" — l'autovalutazione nello stesso documento (§7.5) elenca i limiti ammessi: un `motivo` testo libero anche nella prima proposta, poi corretto con tabella dati strutturati riga per riga nella versione finale; nessun riferimento al documento esterno reale prima della correzione, poi aggiunto (`riferimento_doc_esterno`) nella versione finale. Quello che resta comunque scoperto anche nella versione finale del design: dipende per intero da un piano gemello sui permessi (`docs/plans/PIANO - Revisione Sistema Ruoli e Permessi.md`) mai citato come concluso — le Tappe 8 e 10 non possono partire finché quel piano non decide come rappresentare i ruoli.

**Dubbi/domande aperte.** Si vuole procedere con l'implementazione (Tappe 8-10, già disegnate nel dettaglio) o resta congelata in attesa del piano permessi? La falla di sicurezza su `segna_da_fatturare` (bypass senza controllo) è abbastanza seria da meritare un fix isolato subito, indipendentemente dal resto del piano — che è bloccato da una dipendenza esterna — o si preferisce aspettare comunque perché il fix richiede lo stesso permesso nuovo (`can_approvare_correzioni`) che nasce solo con la Tappa 8?

---

## 6. `stato_fatturazione` è un interruttore libero, senza vincoli, anche su ordini annullati

**Di che parla, con un esempio.** Un operatore può marcare qualunque ordine `fatturato`/`da_fatturare` in qualsiasi momento, indipendentemente dallo stato dell'ordine. Verificato ora: `db/init_sqlite.sql:1318`, `stato_fatturazione TEXT NOT NULL DEFAULT 'da_fatturare'` — nessun `CHECK`, nessun trigger. Un ordine `annullato` può restare marcato `da_fatturare` per sempre, e continuerà a comparire nei filtri/contatori "da fatturare" anche se non verrà mai davvero fatturato.

**Perché è un problema.** È coerente con la filosofia dichiarata ("marcatura visiva semplice", non un vero stato macchina), quindi non è un bug in senso stretto — ma nessun documento lo dichiara esplicitamente, e il caso "ordine annullato ancora da fatturare" resta silenzioso nei report finché qualcuno non lo nota manualmente confrontando le liste.

**Cosa fa il codice per casi simili.** Gli altri stati "di controllo" del progetto (es. `ordini_clienti.stato` stesso) hanno un `CHECK` esplicito e un `TransitionGuard` dietro. `stato_fatturazione` è l'unico campo di stato del ciclo vendite senza nessuna delle due protezioni.

**Cosa fanno gli ERP in casi simili.** I sistemi seri trattano lo stato di fatturazione come derivato dal documento fiscale reale (se esiste una fattura collegata, l'ordine è fatturato — punto 1 sopra, la vista `v_ordini_stato_fiscale` mai costruita), non come un flag manuale libero.

**Soluzione possibile, e cosa lascia scoperto.** Minimo: escludere esplicitamente gli ordini `annullato`/`superato` dai filtri "da fatturare" (intervento piccolo, isolato). Non risolve il problema di fondo — che lo stato resta comunque un flag manuale, non derivato — che dipende dalla stessa decisione del punto 1 (tabella `fatture` viva o morta).

**Dubbi/domande aperte.** Vale la pena un intervento minimo isolato (escludere annullati dai filtri) anche senza affrontare il punto 1 per intero, o si aspetta una decisione complessiva sulla fatturazione?

---

## 7. Riga esclusa silenziosamente dalla spedizione se il prodotto tracciato a lotti non ha lotti disponibili

**Di che parla, con un esempio.** Un prodotto tracciato a lotti (es. un biocida con scadenza) viene ordinato, ma al momento della spedizione non ha lotti disponibili (magari sono tutti scaduti o esauriti). Verificato il comportamento descritto: la riga viene esclusa dalla proposta di spedizione senza alcun avviso — il form semplicemente non la propone, il backend la ignora. L'unico errore esplicito scatta solo se **nessuna** riga produce quantità spedibile ("Nessuna quantità valida").

**Perché è un problema.** Non è sbagliato (non si può spedire un prodotto tracciato senza lotto, giustamente), ma l'operatore che spedisce un ordine a più righe può ritrovarsi con una spedizione parziale non voluta senza che nessuno gliel'abbia segnalato esplicitamente — se non controlla riga per riga cosa manca rispetto all'ordine originale, potrebbe non accorgersi che una riga è stata saltata.

**Cosa fa il codice per casi simili.** Il superamento quantità (overshipment, S1) e la vendita a scoperto (P4) sono stati corretti proprio per evitare "eventi silenziosi non segnalati" — qui lo stesso principio non è stato ancora applicato.

**Cosa fanno gli ERP in casi simili.** I sistemi seri segnalano sempre esplicitamente una riga esclusa da un documento di evasione (icona di avviso, riga evidenziata), anche quando l'esclusione è tecnicamente corretta — per lo stesso principio già citato altrove in questa cartella: un evento che richiede attenzione non deve mai essere silenzioso.

**Soluzione possibile, e cosa lascia scoperto.** Aggiungere un avviso visivo esplicito per ogni riga esclusa per mancanza di lotti (badge o messaggio dedicato, distinto dal generico "nessuna quantità valida") — intervento contenuto, solo UI, nessun rischio sui dati. Non risolve il problema di fondo (nessun lotto disponibile per un prodotto ordinato) — resta comunque un problema di approvvigionamento, non risolvibile lato spedizione.

**Dubbi/domande aperte.** Nessuna finora — è solo segnalato come "da documentare, ed eventualmente da segnalare in UI", mai discusso esplicitamente con l'utente se vale la pena l'intervento.

---

## 8. Il "contenitore best practice unico" tra le cartelle di documentazione — domanda organizzativa, non di codice

Non è una criticità di prodotto ma una domanda di processo mai risposta: ogni cartella (Acquisti, Magazzino, Vendite) tiene il proprio file locale di confronto con i grandi ERP, invece di un unico contenitore cross-cartella. È fattibile unificarli, ma tocca anche le cartelle già dichiarate chiuse — nessuna decisione presa, la domanda (posta nella `REVISIONE - Analisi Documentazione Ciclo Vendite.md`, §5 punto 5) non ha mai ricevuto risposta.

---

## Cose intenzionalmente NON incluse sopra, perché già decise (non "aperte" nel senso della domanda)

Per completezza, e per non farle sembrare dimenticate: il flusso di reso cliente (nessun automatismo, uso del movimento di magazzino manuale — deciso 22/07/2026, tracciato in Backlog voce 34); l'annullamento/eliminazione di una spedizione salvata (stesso trattamento, stessa voce di backlog); i gap puramente documentali dove il codice si comporta correttamente e in modo intenzionale ma nessun testo lo spiega ancora (prodotto/cliente disattivato con documenti aperti già esistenti, `completato` solo manuale, kit mai nominato nel testo narrativo del ciclo vendite) — questi ultimi non sono criticità di comportamento, solo materiale ancora da scrivere quando si procederà alla riscrittura definitiva della guida.
