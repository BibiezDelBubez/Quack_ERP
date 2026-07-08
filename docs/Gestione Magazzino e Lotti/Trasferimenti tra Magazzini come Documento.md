# Trasferimenti tra Magazzini come Documento

Nota di studio — spiega il *perché* e il *come* della progettazione dei trasferimenti interni in Quack (Piano di Implementazione Multi-Magazzino, §3.4 e §4.2). Non è un changelog: è pensata per essere letta da sola, capendo prima il problema e poi la soluzione.

---

## 1. Il problema: uno spostamento merci non è un evento istantaneo

Con un solo magazzino, "spostare" merce non esiste come concetto: la giacenza è un unico numero per prodotto, e ogni operazione (vendita, ricezione, rettifica) la tocca direttamente.

Appena si introduce un secondo magazzino, nasce un'operazione nuova: spostare quantità da una sede all'altra. La tentazione naturale è modellarla come un movimento singolo, sullo stesso pattern già usato per carico/scarico:

```
UPDATE giacenze SET quantita_disponibile = quantita_disponibile - 100 WHERE magazzino_id = ORIGINE;
UPDATE giacenze SET quantita_disponibile = quantita_disponibile + 100 WHERE magazzino_id = DESTINAZIONE;
```

Due UPDATE nella stessa transazione, fine. Sembra corretto — ed è esattamente il tipo di scelta che sembra ragionevole finché non si pensa a cosa succede nel mondo fisico *tra* le due righe di codice.

Nella realtà, uno spostamento tra due magazzini fisici richiede un vettore: un camion, un corriere, un operatore che porta un pallet da un capannone all'altro. In quell'intervallo di tempo — che può essere minuti o giorni — la merce:

- **non è più disponibile nell'origine** (è già sul camion, non puoi venderla da lì),
- **non è ancora disponibile nella destinazione** (non è ancora arrivata, non puoi promettere di spedirla da lì).

Se il codice esegue i due UPDATE atomicamente in un'unica transazione, questo stato intermedio — "merce in transito, non disponibile da nessuna parte" — semplicemente non esiste mai nel sistema. Il database passa istantaneamente da "tutto in A" a "tutto in B", ma il camion nella realtà ci mette ore. Il software mente sulla realtà fisica.

Questo non è un dettaglio accademico. Ha conseguenze operative dirette:

- Un operatore in destinazione controlla il sistema e vede la merce già disponibile, ma il camion non è ancora arrivato: la promette a un cliente, poi non riesce a evaderla.
- Un operatore in origine, se il trasferimento fosse invece modellato *dopo* l'evento (scritto nel sistema solo a cose fatte), potrebbe nel frattempo vendere merce che aveva già fisicamente caricato su un altro furgone.
- Se il camion si rompe, torna indietro, o consegna solo una parte del carico (una cassa danneggiata), non c'è alcun posto nel modello dati per registrare questa eccezione: il modello a due UPDATE assume implicitamente che il trasferimento vada sempre a buon fine, per intero, all'istante.

## 2. La soluzione: un documento con stato, non un movimento

La soluzione è smettere di trattare il trasferimento come un evento e iniziare a trattarlo come un **processo con una durata**, esattamente come già si fa per un ordine di vendita o un ordine d'acquisto — che infatti in Quack non sono "un movimento", ma documenti con una testata, delle righe, e uno stato che avanza nel tempo.

Un trasferimento diventa quindi un documento a sé stante:

- una **testata** (`trasferimenti_magazzino`): chi è l'origine, chi è la destinazione, un numero progressivo (`TRF/2026/0001`), date di richiesta/partenza/arrivo, note;
- delle **righe** (`trasferimenti_magazzino_righe`): quali prodotti, quali lotti, quanto si intendeva spostare (`quantita_richiesta`) e quanto è effettivamente arrivato (`quantita_arrivata`, inizialmente vuoto).

E soprattutto, uno **stato** che rappresenta dove si trova il processo nel tempo reale:

```
   bozza  ──────────────►  in_transito  ──────────────►  completato
     │                          │
     │                          │
     └──────────► annullato ◄───┘
```

| Stato | Cosa significa fisicamente | Cosa succede alla giacenza |
|---|---|---|
| `bozza` | Il documento è in preparazione. Nessuno ha ancora toccato un pallet. | Niente: nessuna giacenza è ancora stata modificata. |
| `in_transito` | Il camion è partito. La merce è scaricata dall'origine ma non ancora caricata a destinazione. | L'origine ha già scaricato (movimento di `scarico`). La destinazione non ha ancora ricevuto nulla. |
| `completato` | Il camion è arrivato, l'operatore ha controllato e confermato quanto ricevuto. | La destinazione carica (movimento di `carico`) per la quantità *effettivamente confermata* — non necessariamente uguale a quella richiesta. |
| `annullato` | Il trasferimento non avverrà (o non è avvenuto come previsto). | Dipende da quando viene annullato — vedi §4. |

La differenza fondamentale rispetto al modello a due UPDATE: lo stato `in_transito` **esiste come dato**, non solo come momento fugace tra due righe di codice nella stessa transazione. Un report sulla giacenza può interrogare "cosa c'è in transito adesso" come una query, non deve indovinarlo.

## 3. Perché non un solo movimento con tipo "trasferimento"

Una via di mezzo sarebbe usare un solo movimento di magazzino con un tipo dedicato (`tipo_movimento = 'trasferimento'`), invece di un intero documento. In Quack questo tipo esiste già nell'enum di `movimenti_magazzino` (ereditato da un tentativo di design precedente), ma **non viene usato per rappresentare l'intero trasferimento** — viene usato per marcare a quale documento appartiene ciascun movimento generato (vedi §5), non per sostituire il documento.

Il motivo è che un singolo movimento — anche con un tipo suo — non ha uno stato che può cambiare nel tempo. Un movimento in Quack è un fatto accaduto, immutabile, con una data e un verso (carico o scarico): è pensato per lo storico, non per un processo in corso. Non c'è un posto dove scrivere "questo trasferimento è ancora in viaggio" se il trasferimento *è* un singolo movimento già accaduto.

Un documento con stato, invece, può restare fermo in `in_transito` per giorni, essere interrogato, modificato nelle sue attese (quanto arriverà), e alla fine confermato con un valore diverso da quello iniziale. È lo stesso motivo per cui un ordine cliente non è "un movimento di vendita": è un documento che nasce in bozza, passa per stati intermedi, e può discostarsi dal previsto lungo il percorso.

## 4. I casi limite che il modello a stato risolve

Un modello di dati si giudica da come si comporta nei casi limite, non nel percorso felice. Tre casi concreti, e come lo stato li rende gestibili invece che problematici:

### 4.1 Arrivo con quantità diversa da quella partita

Un trasferimento di 100 unità arriva con solo 98 (una cassa rotta in viaggio, un errore di conta all'imbarco). Con il modello a documento, questo non è un'eccezione da gestire fuori standard: è semplicemente il valore che l'operatore inserisce in `quantita_arrivata` al momento della conferma, diverso da `quantita_richiesta`. La differenza (2 unità) genera una nota di discrepanza sulla riga — non un blocco dell'operazione, non un dato perso.

Notare la scelta di design: la giacenza di **destinazione** viene caricata per la quantità realmente arrivata (98), non per quella richiesta (100). L'origine ha già scaricato 100 alla partenza e quel numero non si tocca più: quella era la quantità realmente partita, un fatto storico immutabile. Le 2 unità mancanti restano "perse" nel sistema (né in origine né in destinazione) fino a un'eventuale verifica fisica — non vengono automaticamente "ritrovate" da nessuna parte, perché non è compito del software inventare dati che nessuno ha verificato.

### 4.2 Annullamento a metà strada

Il trasferimento è già `in_transito` — la merce è già scaricata dall'origine — ma va annullato (il camion torna indietro, l'ordine cliente che lo motivava è stato cancellato). Qui il modello a documento espone un dettaglio che il modello a due UPDATE avrebbe nascosto: la merce è *già stata tolta* dall'origine. Annullare non può essere "non fare nulla" — deve generare un movimento di carico che la **restituisce all'origine**, altrimenti quella quantità sparirebbe nel nulla (scaricata ma mai più ricaricata da nessuna parte).

Annullare un trasferimento già `completato`, invece, è vietato per principio: a quel punto la merce è arrivata e la giacenza di destinazione riflette la realtà. Se serve tornare indietro, si crea un nuovo trasferimento in senso opposto — mai riscrivere lo storico di un'operazione già conclusa, stesso principio già seguito altrove in Quack per ordini e fatture.

### 4.3 Consegna in più tranche

Un trasferimento potrebbe arrivare in due camion separati in momenti diversi. Lo schema (quantità richiesta/arrivata *per riga*, non per l'intera testata) lo rende rappresentabile senza modifiche strutturali, anche se la UI attuale copre solo la conferma in un'unica soluzione — un'estensione naturale, non un redesign.

## 5. Come si collega al resto del sistema di magazzino

I trasferimenti non introducono un sistema di giacenza parallelo: usano esattamente le stesse tabelle (`giacenze`, `lotti_giacenze`) e lo stesso meccanismo di storico (`movimenti_magazzino`) già usati da ricezioni, vendite e rettifiche manuali. Concretamente:

- **Alla partenza** (`bozza` → `in_transito`): per ogni riga, si scarica `giacenze`/`lotti_giacenze` dell'origine (stesso pattern usato altrove per uno scarico), e si registra un `movimenti_magazzino` con `tipo_movimento = 'scarico'` e `documento_tipo = 'trasferimento'` + `documento_id` puntato alla testata — così lo storico movimenti resta consultabile con lo stesso strumento usato per ogni altra operazione, ma resta rintracciabile a quale trasferimento appartiene.
- **Alla conferma arrivo** (`in_transito` → `completato`): stesso meccanismo, ma in carico sulla destinazione, per la quantità confermata (non quella richiesta).
- **All'annullamento da `in_transito`**: un carico di ripristino sull'origine, sempre con lo stesso collegamento documentale.

La scelta di riusare `carico`/`scarico` invece del tipo enum `trasferimento` (pur essendo un valore valido nello schema) è deliberata: la lista movimenti calcola il segno (+/-) da mostrare in base al tipo, e quella logica già distingue correttamente carico da scarico. Introdurre un terzo tipo con verso ambiguo avrebbe richiesto duplicare quella logica altrove; riusare i tipi esistenti mantiene un solo punto di verità per "cosa aumenta e cosa diminuisce la giacenza".

## 6. Analisi di Mercato: Come gestiscono i trasferimenti i Top ERP

Per capire se il modello a documento (con stato e tempi di transito) sia la scelta corretta per i magazzini fisici, e come differisca dalla gestione di magazzini "virtuali", è utile guardare ai leader di mercato. 

**Cosa si intende per movimento "virtuale" o "logico"?**
Spesso in azienda c'è bisogno di isolare una parte della merce (es. "Merce in quarantena", "Reparto Resi", "Assegnata all'e-commerce") senza spostarla fisicamente da un'altra parte del capannone. In questi casi, creare un vero e proprio magazzino informatico — o una "Ubicazione Virtuale" — serve solo a compartimentare il dato. In questi scenari, costringere l'operatore a usare un documento con tempi di spedizione e di arrivo (che richiederebbe ore o giorni) è una forzatura, perché il passaggio logico da "Vendibile" a "Quarantena" è istantaneo. Ecco come i 4 ERP principali gestiscono questo dualismo tra spostamento fisico (lento, richiede camion) e virtuale (istantaneo, logico).

### 6.1 SAP (ECC e S/4HANA)
In SAP, la distinzione tra movimento logico e fisico è netta. Per gli spostamenti puramente logici o virtuali, SAP utilizza il **Transfer Posting**, un'operazione generata istantaneamente. Quando la merce deve viaggiare fisicamente tra due sedi, SAP solitamente impone l'uso di uno **Stock Transport Order (STO)**, un documento che prevede merce "viaggiante". Tuttavia, se l'azienda non ha bisogno di tracciare il tempo di transito (es. magazzini molto vicini), SAP ammette scorciatoie (come il movimento 301) per spostamenti fisici istantanei, dimostrando che l'STO è legato alla *necessità logistica di tracciare il viaggio e gestire il transito*, non esclusivamente al mero spostamento fisico.
**PRO:** Estrema precisione e robustezza. L'STO copre ogni anomalia fisica, mentre il Transfer Posting è rapido. 
**CONTRO:** La configurazione iniziale è molto complessa. Gli operatori devono imparare Transaction Codes (T-Codes) diversi a seconda dello scenario.

### 6.2 Odoo
Odoo modella tutto lo spazio, sia fisico che logico, attraverso il concetto unificato di **Locations** (Ubicazioni). Le ubicazioni possono essere interne (fisiche, come uno scaffale) oppure virtuali (come ubicazioni di Scarto, Perdite di inventario, o ubicazioni di Transito). Per spostare la merce fisicamente, Odoo crea un *Internal Transfer* che sposta prima la merce in una "Transit Location" virtuale, e da lì alla destinazione (imitando il viaggio del camion). Gli spostamenti virtuali (es. da "Disponibile" a "Quarantena"), invece, si risolvono con un movimento diretto da una Location all'altra, che ha effetto immediato.
**PRO:** Il concetto unificato di "Location" è estremamente flessibile e facile da capire. Puoi inventare ubicazioni virtuali per qualsiasi necessità (es. "Fiera di Milano") in pochi click.
**CONTRO:** Proprio per la sua eccessiva libertà, se le ubicazioni virtuali non vengono gestite con rigore, si rischia di creare "scorte fantasma" disperse in decine di reparti fittizi, sballando il reale valore del magazzino.

### 6.3 Microsoft Dynamics 365 Supply Chain
L'ERP di Microsoft utilizza una gerarchia fissa: Siti (Sites), Magazzini (Warehouses) e Ubicazioni (Locations/Bins). Se devi fare uno spostamento logico o virtuale (es. passare merce a un reparto "Resi"), Dynamics usa i **Transfer Journals** (Giornali di Trasferimento): una registrazione diretta che aggiorna le giacenze senza attese. Se invece devi inviare merci da un Sito a un altro fisicamente distante, sei obbligato a usare i **Transfer Orders** (Ordini di Trasferimento), che parcheggiano le quantità in un "Transit Warehouse" invisibile e bloccano la merce finché non si conferma l'arrivo.
**PRO:** La gerarchia rigida garantisce una struttura dei dati solida, permettendo di ottenere report aziendali perfetti e inequivocabili (sapendo sempre cosa è in viaggio e cosa è fermo).
**CONTRO:** È poco flessibile se l'azienda cambia spesso struttura operativa. Spostare merce tra due "Siti" richiede la trafila complessa del Transfer Order anche se i due siti, nella realtà, si trovano nello stesso blocco di edifici.

### 6.4 Oracle NetSuite
NetSuite adotta una logica molto simile. Le sedi aziendali fisiche sono chiamate *Locations*, mentre le partizioni logiche al loro interno sono *Bins*. Uno spostamento virtuale (es. cambio di stato merce) avviene tramite un **Bin Transfer** o **Inventory Transfer** con effetto immediato. Quando invece l'inventario si muove tra due *Locations* separate fisicamente, NetSuite usa i **Transfer Orders**, che richiedono all'operatore di fare prima un "Fulfillment" (sottrazione) e, ore/giorni dopo, un "Receipt" (acquisizione).
**PRO:** La distinzione nativa tra Location (fisica/fiscale) e Bin (scaffale/virtuale) è molto intuitiva anche per utenti alle prime armi, evitando confusione tra cosa è in viaggio e cosa no.
**CONTRO:** Senza acquistare moduli aggiuntivi avanzati (WMS), il monitoraggio dei passaggi intermedi su percorsi logistici complessi può risultare basilare e meno granulare rispetto a SAP.

### 6.5 Confronto e Riepilogo
Tutti i principali ERP concordano su una regola aurea: **il modello informatico deve riflettere la realtà logistica dell'operazione**. 
Se lo spostamento è solo "virtuale" o logico, tutti i top ERP offrono registrazioni istantanee per non intralciare l'utente (Transfer Posting, Bin Transfer, Journals). 
Quando si tratta di un vero spostamento **fisico** tra due sedi per cui l'azienda *deve* tracciare i tempi di viaggio, i leader di mercato impongono l'uso di un documento di transito formale (Transfer Order o Stock Transport Order) che lascia la merce "sospesa". 
Questa logica (documento formale per il transito fisico tracciato, movimento immediato per la compartimentazione logica) dimostra come la scelta di progettare i trasferimenti di Quack ERP con uno stato dedicato (`in_transito`) sia solida. Il documento dotato di stato — in grado di rappresentare merce in viaggio, imprevisti e differenze di quantità — è lo strumento per garantire che l'azienda sappia sempre dove si trovi il suo patrimonio logistico.

*(Nota: per un'analisi comparata approfondita dell'UX/UI su questi ERP, si rimanda al documento "Best Practice UX-UI Multi-Magazzino nei Grandi ERP.md").*

## 7. Proposta Evolutiva: Magazzini Virtuali e Trasferimenti Rapidi

Sulla base del confronto con i leader di mercato, l'architettura attuale (che impone sempre un documento con stato di transito) è perfetta per gli spostamenti *fisici*. Tuttavia, per supportare la compartimentazione logica all'interno della stessa sede (es. Reparto Resi, Quarantena), proponiamo un'evoluzione dell'architettura di Quack ERP basata sul concetto di **Gerarchia Spaziale**.

### 7.1 Modifiche al Modello Dati (Gerarchia)
Invece di creare una tabella separata per le ubicazioni virtuali, estendiamo la colonna `tipo` già esistente nella tabella `magazzini` (attualmente `fisico`, `virtuale`, `conto_terzi`) aggiungendo un quarto valore per il processing esterno, e introduciamo la struttura ad albero:
- `tipo`: enum allargato (`fisico`, `virtuale`, `conto_terzi`, `conto_lavoro`).
- `parent_id`: foreign key verso la stessa tabella `magazzini`, con un vincolo aggiuntivo: **un magazzino può fare da genitore solo se è a sua volta `fisico` e senza genitore** (`parent_id IS NULL`). Non sono ammessi virtuali dentro virtuali.
- `soggetto_esterno_id`: foreign key opzionale verso `clienti_fornitori(id)`, valorizzata quando `tipo IN ('conto_terzi','conto_lavoro')`.

Questa struttura permette di mappare la realtà in questo modo:
* **Magazzino Roma** (`tipo: fisico`, `parent_id: null`)
  * **Area Quarantena Roma** (`tipo: virtuale`, `parent_id: Roma`)
  * **Reparto E-commerce Roma** (`tipo: virtuale`, `parent_id: Roma`)
* **Magazzino Milano** (`tipo: fisico`, `parent_id: null`)

*(Nota terminologica: `conto_terzi` rappresenta merce nostra depositata in un polo logistico esterno; `conto_lavoro` rappresenta merce nostra inviata a un terzista per subire lavorazioni. Entrambi fisicamente esterni all'azienda).*

**Perché `soggetto_esterno_id` e non solo l'enum.** L'enum dice *che tipo* di relazione esterna è (conto terzi vs conto lavoro), ma non dice *con chi* — se domani ci sono più terzisti, quell'informazione finirebbe solo come testo libero nel campo `nome`/`note`, senza un vero collegamento all'anagrafica. In tutto il resto di Quack ogni relazione con un soggetto esterno passa da `clienti_fornitori` (fornitori, clienti, aziende interne), riusando l'anagrafica invece di duplicarla in testo libero — lo stesso principio già seguito per il modulo indirizzi (§3.2 del Piano). Un magazzino `conto_lavoro` con `soggetto_esterno_id` valorizzato può quindi riusare direttamente l'indirizzo e i dati del terzista già censito come fornitore, invece di doverli reinserire a mano nella scheda del magazzino.

**Perché il vincolo a due livelli.** È la stessa preoccupazione già sollevata in §6.2 per Odoo: un albero di profondità arbitraria, se non gestito con rigore, genera "scorte fantasma" disperse in reparti virtuali annidati senza controllo. Vincolare i genitori ai soli magazzini fisici radice mantiene la gerarchia a due livelli fissi (come fanno SAP, NetSuite e Dynamics con Plant→Storage Location / Location→Bin), evitando esattamente il punto debole che il confronto di mercato ha individuato nel modello più libero di Odoo.

### 7.2 Il Motore di Routing dei Trasferimenti e la Topbar
L'intelligenza del sistema risiederà nel `WarehouseController`, che applicherà regole rigide per determinare **automaticamente** l'iter corretto.

**Attenzione all'implementazione — verificato sul codice reale, non ipotizzato.** `qk_magazzini_list($pdo, true)` (in `includes/magazzino.php`) non è usata solo dalla topbar: è la stessa funzione chiamata anche in `app/Controllers/PurchaseOrdersController.php:118` (dropdown "magazzino destinazione" sugli ordini d'acquisto) e in `app/Controllers/WarehouseController.php:769`, dentro `createTransfer()`, per popolare **sia il menu origine che quello destinazione del form di creazione trasferimento**. Filtrare questa funzione a `tipo='fisico' AND parent_id IS NULL` come unica modifica romperebbe queste due funzionalità — in particolare la seconda, che è la funzionalità stessa che questo piano vuole abilitare: un magazzino virtuale deve poter comparire come origine/destinazione di un trasferimento, solo non come sede attiva di sessione. La correzione corretta è aggiungere un nuovo parametro opzionale alla funzione esistente (es. `qk_magazzini_list(PDO $pdo, bool $soloAttivi = false, bool $soloRadiciFisiche = false)`), usato con `true` **solo** nella chiamata di `includes/header.php:53`; le chiamate in `PurchaseOrdersController.php` e in `WarehouseController::createTransfer()` restano invariate, senza questo filtro.

Lo stesso principio — nessuna sede virtuale come predefinita di sistema — va applicato al fallback automatico di riassegnazione del `predefinito`, che **non vive in un solo posto**: è duplicato, scritto a mano, in quattro funzioni distinte di `includes/magazzino.php` — `qk_insert_magazzino()` (quando è il primo magazzino mai creato), `qk_update_magazzino()` (quando l'utente toglie la spunta "predefinito" senza assegnarla altrove), `qk_imposta_magazzino_predefinito()` (azione esplicita dalla lista) e `qk_disattiva_magazzino()` (quando si disattiva il magazzino che era predefinito). Tutte e quattro le query di fallback (`SELECT id FROM magazzini WHERE attivo = 1 ORDER BY id LIMIT 1` o equivalente) vanno corrette allo stesso modo, aggiungendo `AND tipo = 'fisico' AND parent_id IS NULL` — sistemarne solo una lascerebbe le altre tre come porte aperte per promuovere silenziosamente una sede virtuale a predefinita.

Va anche esteso `MagazziniController::validate()` (riga 251), che oggi ha l'enum `tipo` scritto a mano nel codice — `in_array($values['tipo'], ['fisico', 'virtuale', 'conto_terzi'], true)` — senza `conto_lavoro` e senza alcuna validazione su `parent_id` (deve rifiutare un genitore che non sia a sua volta `fisico` e senza genitore, per far rispettare il vincolo a due livelli del §7.1) o su `soggetto_esterno_id`.

**Regola A: Spostamento Intra-Sede (Trasferimento Rapido / Giroconto)**
Se l'origine e la destinazione condividono la stessa "radice fisica" (es. da *Roma* a *Quarantena Roma*), il sistema nasconde la complessità del transito.
*   **UX:** L'utente usa una maschera semplificata "Giroconto". Inserisce Origine, Destinazione, Articolo, Lotto e Quantità.
*   **Database:** Al click su Conferma, il Giroconto genera comunque un record in `trasferimenti_magazzino` (garantendo tracciabilità, un numero progressivo `TRF/…`), ma lo porta **istantaneamente allo stato `completato`**, bypassando lo stato `in_transito` in una singola transazione. Riusa in sequenza le funzioni già scritte in `includes/trasferimenti.php` (`qk_insert_trasferimento` → `qk_trasferimento_registra_partenza` → `qk_conferma_arrivo_trasferimento`, con `quantita_arrivata = quantita_richiesta`) invece di scrivere un secondo percorso di scarico/carico: zero duplicazione di logica.

**Regola B: Spostamento Inter-Sede (Trasferimento con Transito)**
Se l'origine e la destinazione hanno radici fisiche diverse (es. da *Quarantena Roma* a *Milano*), significa che la merce deve viaggiare.
*   **UX:** L'interfaccia "Giroconto" viene bloccata. L'utente è obbligato a creare un documento di *Trasferimento Magazzino*.
*   **Database:** Si segue il flusso rigoroso già implementato: Creazione (Bozza) -> Partenza (Scarico e stato `in_transito`) -> Arrivo (Carico a destinazione).

### 7.3 Eccezioni di Settore e Casi Limite (Edge Cases)

1. **Magazzini Virtuali Esterni (Conto Lavoro / Conto Terzi):**
   Spesso un'azienda crea un magazzino virtuale per tracciare la merce inviata a un terzista (es. "Verniciatore Rossi"). Pur essendo un magazzino *virtuale* a livello contabile aziendale, fisicamente si trova a 50 km di distanza.
   *   **Soluzione:** Questi magazzini avranno `tipo: conto_lavoro` (o `conto_terzi`), `parent_id: null` e `soggetto_esterno_id` puntato al record di "Verniciatore Rossi" già censito in `clienti_fornitori`. Il sistema capirà automaticamente che hanno una radice fisica diversa dalla sede centrale e imporrà il documento di transito per inviargli la merce, modellando perfettamente il viaggio del furgone — e potrà stampare il documento di trasporto con i dati del destinatario presi direttamente dall'anagrafica, senza reinserirli.

2. **Trasferimenti Parziali da Ubicazioni Logiche:**
   Cosa succede se decido di trasferire a Milano solo 10 dei 50 pezzi che si trovano nella *Quarantena Roma*? 
   *   **Soluzione:** Il sistema lo permette senza modifiche. Il documento di transito preleverà la merce direttamente dal `magazzino_id` *Quarantena Roma* e lo invierà a *Milano*. Il modello dati lo supporta nativamente.

### 7.4 Impatto e Vantaggi
Questa evoluzione fornisce una "scatola guida" per l'utente. Invece di dover spiegare a un magazziniere quando usare una funzione o un'altra, è la topologia stessa del magazzino (il `parent_id`, vincolato a due soli livelli) a determinare i binari corretti. I dati rimangono intonsi, la tracciabilità documentale è garantita sempre (persino nel Giroconto istantaneo, che riusa il codice esistente invece di duplicarlo) e il transito viene gestito solo quando è fisicamente reale.

## 8. Riepilogo: La realtà negata

Se si scegliesse di NON usare il modello a documento (o di forzare i giri rapidi ovunque, perdendo la traccia dei viaggi), si andrebbe contro la realtà fisica. Come promemoria finale:

| Se il trasferimento fosse... | La realtà che verrebbe negata |
|---|---|
| un solo aggiornamento atomico di due righe | il tempo che il camion impiega a viaggiare |
| un singolo movimento con un tipo dedicato | la possibilità che lo stato cambi nel tempo, che qualcuno lo interroghi mentre è ancora aperto |
| senza gestione esplicita della quantità arrivata | il fatto che in magazzino le cose si rompono, si perdono, si contano male |
| annullabile a cancellazione diretta anche da "in transito" | il fatto che la merce scaricata dall'origine non sparisce nel nulla se il viaggio si interrompe |

---

## 9. Quanto costerebbe farlo "alla SAP", e come i best-in-class risolvono davvero le criticità

### 9.1 Il costo di una separazione realmente enterprise

In SAP, Plant e Storage Location non sono la stessa tabella con un flag: sono due entità distinte, con autorizzazioni separate (in SAP servono ruoli/oggetti di autorizzazione diversi per Plant e per Storage Location, gestiti a livello di configurazione BASIS/PFCG — fonte: [SAP Community, Storage Location Authorization](https://community.sap.com/t5/enterprise-resource-planning-q-a/storage-location-authorization/qaq-p/7901287)) e con un proprio set di viste anagrafiche (MRP, Acquisti, Qualità...) per ogni combinazione. Portare Quack a questo livello vorrebbe dire:

- Spezzare `magazzini` in due tabelle reali: una per le sedi (oggi radici fisiche) e una per le sotto-ubicazioni, senza self-join.
- Trasformare ogni singola colonna `magazzino_id` oggi esistente — `giacenze`, `lotti_giacenze`, `movimenti_magazzino`, `trasferimenti_magazzino` (origine/destinazione), `ordini_testata.magazzino_destinazione_id`, `ricezioni_testata.magazzino_id` — in una coppia di colonne (sede + ubicazione), sul modello della chiave composta Plant+Storage Location.
- Rifare ogni selettore in ogni vista (topbar, ordini, ricezioni, trasferimenti, elenco prodotti) da singolo dropdown a selezione a cascata a due livelli.
- Introdurre un'autorizzazione separata per chi può creare/modificare una sede rispetto a chi può creare/modificare una sotto-ubicazione, come fa SAP.
- Migrare i dati già seminati (`MAG-01`) sulla nuova struttura.

Sono, di fatto, tutti i punti di contatto già toccati per l'intero progetto multi-magazzino (15 task tracciati su più sessioni). Una stima realistica è di alcune settimane di lavoro concentrato, non giorni — ed è sproporzionata rispetto alla scala reale di Quack (poche sedi fisiche di un distributore chimico, non una multinazionale con decine di stabilimenti). **Non lo consiglio**: il costo non è giustificato dal beneficio, che si può ottenere in modo molto più economico (vedi §9.3).

### 9.2 Come i best-in-class risolvono davvero la criticità di governance

La domanda di fondo era: come evitano, i sistemi enterprise, che qualcuno configuri per errore due sedi realmente distanti come se fossero la stessa, ottenendo un trasferimento istantaneo che nella realtà richiederebbe un camion? La risposta, verificata su tutti e quattro, è sorprendentemente uniforme: **nessuno lo deduce automaticamente da una struttura dati. È sempre una decisione esplicita di una persona autorizzata**, non un'inferenza silenziosa dalla gerarchia.

- **SAP**: la scelta tra il movimento immediato a un passaggio (tipo 301) e lo Stock Transport Order non è mai automatica in base a quali plant sono coinvolti — è l'utente che sceglie quale transazione lanciare, "in base alle specifiche esigenze di business in termini di documentazione, visibilità di pianificazione e tracciamento costi" (fonte: [SAP Logistics Expert, STO Guide](https://www.saplogisticsexpert.com/stock-transfer-in-sap-the-best-guide-with-all-scenarios-explained/)). Lo stesso plant può tranquillamente usare entrambe le modalità verso lo stesso plant di destinazione, a seconda del caso concreto.
- **Microsoft Dynamics 365**: stessa logica esplicita — "i Transfer Journal sono adatti a trasferimenti nello stesso sito o vicini, i Transfer Order per spostamenti a lunga distanza... se sposti merce tra location fisiche, va usato un Transfer Order" (fonte: [Encore Business Solutions](https://www.encorebusiness.com/blog/transfer-order-transfer-journal-differences-d365fo/)) — è una linea guida per l'utente su quale documento aprire, non un automatismo che legge la gerarchia dei siti.
- **NetSuite**: qui la distinzione è ancora più netta e istruttiva — i movimenti tra sotto-sedi ("sub-location") richiedono *sempre* un Inventory Transfer formale con impatto contabile/tracciamento, indipendentemente dalla gerarchia; e la documentazione ufficiale avverte esplicitamente di **non** usare le Location per modellare zone fisiche interne come scaffali o banchine, perché "causa problemi con gli evasi ordine, il costing LIFO/FIFO e la reportistica" (fonte: [8Quanta, Sub-Locations in NetSuite](https://8quanta.com/study/when-to-use-sub-locations-in-netsuite/)) — le vere zone interne vanno modellate con i Bin, un concetto tecnico diverso, non riusando lo stesso oggetto "location" a grana più fine.
- **Odoo**: le regole di instradamento (routes, push/pull, numero di step per ricevimento/spedizione) sono impostazioni esplicite configurate una volta dall'amministratore per ciascun magazzino/percorso (fonte: [Odoo 19 — Routes and push/pull rules](https://www.odoo.com/documentation/19.0/applications/inventory_and_mrp/inventory/shipping_receiving/daily_operations/use_routes.html)), non qualcosa che il sistema deduce guardando quali magazzini esistono.

Il filo conduttore: **la correttezza non viene garantita dalla struttura dei dati, viene garantita tenendo la decisione nelle mani di una persona autorizzata**, in un momento esplicito (o quando crea/configura l'anagrafica, o quando sceglie il tipo di documento operazione per operazione).

### 9.3 Cosa significa in pratica per Quack

Non serve la separazione strutturale del §9.1, e non serve nemmeno una nuova tabella di regole di transito. Serve spostare la protezione dal "il sistema deduce dalla gerarchia" al "la persona che configura la gerarchia lo fa consapevolmente, con un permesso dedicato":

- Il permesso per modificare `parent_id`/`tipo` di un magazzino andrebbe trattato come un'azione strutturale, non una modifica anagrafica qualunque — coerente con la separazione di ruoli che SAP applica tra Plant e Storage Location. Nella pratica di Quack: si può restare su `manage_users` (già usato per tutta l'anagrafica magazzini), ma l'interfaccia di modifica deve mostrare un avviso esplicito quando si imposta un `parent_id` ("stai dichiarando che questi due magazzini sono la stessa sede fisica: gli spostamenti tra loro diventeranno istantanei, senza documento di trasporto") — è la versione leggera, proporzionata alla scala di Quack, della stessa garanzia che SAP ottiene con ruoli di autorizzazione separati.
- Nessuna inferenza "furba" aggiuntiva da costruire: la regola "stessa radice ⇒ Giroconto ammesso" resta com'è nella proposta del §7, perché il controllo di correttezza è già stato spostato a monte, nel momento in cui l'anagrafica viene creata con consapevolezza.

**Stima per la versione corretta (schema unificato + le correzioni del §7 + questo avviso di conferma):** un'implementazione contenuta, dell'ordine di 1-3 giorni di lavoro — modifica schema (`tipo` esteso, `parent_id`, `soggetto_esterno_id`); `qk_magazzini_list()` con il nuovo parametro `$soloRadiciFisiche`, usato solo nella chiamata di `header.php` e non in `PurchaseOrdersController.php`/`WarehouseController::createTransfer()` (verificato: sono la stessa funzione condivisa, vedi §7.2); lo stesso filtro `tipo='fisico' AND parent_id IS NULL` applicato a tutte e quattro le funzioni di fallback del predefinito (`qk_insert_magazzino`, `qk_update_magazzino`, `qk_imposta_magazzino_predefinito`, `qk_disattiva_magazzino`), non solo l'ultima; l'estensione di `MagazziniController::validate()` (enum `tipo`, vincolo su `parent_id`, `soggetto_esterno_id`); riuso delle funzioni esistenti di `trasferimenti.php` per il Giroconto; un filtro/tab nella vista trasferimenti per separare i giroconti istantanei dai trasferimenti fisici; e l'avviso di conferma alla creazione/modifica della gerarchia. Nessuna necessità di toccare le tabelle di giacenza né la logica interna di `PurchaseOrdersController`/`WarehouseController::createTransfer` (le loro chiamate a `qk_magazzini_list` restano invariate, senza il nuovo filtro).

---

*Riferimento implementativo: `includes/trasferimenti.php`, `app/Controllers/WarehouseController.php` (azioni `transfers*`), tabelle `trasferimenti_magazzino` / `trasferimenti_magazzino_righe` in `db/init_sqlite.sql`. Vedi anche Piano di Implementazione Multi-Magazzino, Parte 4 §4.2.*
