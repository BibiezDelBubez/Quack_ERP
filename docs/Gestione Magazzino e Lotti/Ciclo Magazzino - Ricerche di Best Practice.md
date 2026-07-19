# Ciclo Magazzino — Ricerche di Best Practice

Confronto unificato SAP S/4HANA, Oracle NetSuite, Microsoft Dynamics 365, Odoo su quattro temi: modello dati multi-sede, UX di visualizzazione/cambio sede, trasferimenti fisici vs virtuali, soglie di riordino. Un solo confronto, non tre — questo documento consolida `Best Practice UX-UI Multi-Magazzino nei Grandi ERP.md`, la Parte 1 di `Piano di Implementazione Multi-Magazzino.md`, e i §6/§9 di `Trasferimenti tra Magazzini come Documento.md`, che coprivano lo stesso confronto con angolazioni diverse e sostanziale sovrapposizione.

Per come queste scelte si traducono in comportamento reale in Quack, vedi il documento A (*Flusso Operativo*), che rimanda qui ogni volta che serve il confronto di mercato invece di ripeterlo.

Sistemi analizzati: **SAP S/4HANA** ed **Oracle NetSuite** (i due riferimenti enterprise più diffusi al mondo), **Microsoft Dynamics 365** (enterprise/mid-market, ecosistema Microsoft), **Odoo** (non "enterprise" in senso stretto, ma probabilmente il sistema con la UX più moderna e curata su questi problemi specifici — un ottimo termine di paragone per capire "come si fa bene" senza la complessità di SAP). Esclusi Zoho Inventory/Katana MRP: buoni prodotti, ma non paragonabili per profondità e scala.

---

## 1. Modello dati multi-sede

### 1.1 Il concetto cardine: la giacenza non è per prodotto, ma per (prodotto × sede)

Tutti e quattro i sistemi di riferimento modellano la giacenza come combinazione di più dimensioni, mai come un singolo numero per prodotto:

| Sistema | Terminologia | Granularità |
|---|---|---|
| SAP S/4HANA | Plant × Storage Location | sede legale-operativa × sotto-zona interna |
| Oracle NetSuite | Location [× Bin] | sede × ubicazione fisica opzionale |
| Microsoft Dynamics 365 | Site × Warehouse × Location/Bin | gerarchia a 3 livelli |
| Odoo | Location (gerarchia ad albero unificata) | fisiche e virtuali nello stesso albero |

### 1.2 Tre opzioni di modellazione, e la scelta fatta per Quack

**Opzione A — `magazzino_id` sulle tabelle esistenti** (giacenze, lotti-giacenza, movimenti, inventari). Pro: cambiamento incrementale, adatto a poche sedi. Contro: non risolve la tracciabilità a livello di ubicazione fisica interna (scaffale/bin).

**Opzione B — Tabella `ubicazioni` gerarchica (bin-level)**, giacenze/lotti legati a `ubicazione_id`. Pro: modello più potente e a prova di futuro (picking, zone ADR dedicate, quarantena come ubicazioni virtuali). Contro: sforzo realizzativo molto più alto; rischio di una struttura che nessuno mantiene se il magazzino fisico non è già organizzato per zone.

**Opzione C — Ibrido progressivo**: si parte dall'Opzione A, disegnando lo schema in modo che aggiungere in futuro `ubicazione_id` sia un'estensione, non una riscrittura.

**Quack ha adottato l'Opzione C**: magazzino come dimensione semplice (Opzione A), con giacenza-lotto già scomposta per sede fin dall'inizio (`giacenze`, `lotti_giacenze` — vedi documento B §1.2/§1.4), lasciando le ubicazioni bin-level (Opzione B) come estensione futura se servirà picking a scaffale. Conseguenza diretta di questa scelta: la segregazione fisica tra sostanze incompatibili nello stesso magazzino resta un limite noto (documento A §10.11) — quella granularità richiederebbe l'Opzione B.

### 1.3 Il costo di una separazione realmente enterprise (Opzione B "alla SAP")

In SAP, Plant e Storage Location non sono la stessa tabella con un flag: sono due entità distinte, con autorizzazioni separate e un proprio set di viste anagrafiche per ogni combinazione. Portare Quack a questo livello richiederebbe: spezzare `magazzini` in due tabelle reali (sedi + sotto-ubicazioni, senza self-join); trasformare ogni colonna `magazzino_id` oggi esistente in una coppia di colonne (sede + ubicazione); rifare ogni selettore in ogni vista da singolo dropdown a selezione a cascata a due livelli; introdurre un'autorizzazione separata per chi può creare/modificare una sede rispetto a chi può creare/modificare una sotto-ubicazione; migrare i dati già seminati sulla nuova struttura.

Stima realistica: alcune settimane di lavoro concentrato, sproporzionata rispetto alla scala reale di un distributore chimico con poche sedi fisiche, non una multinazionale con decine di stabilimenti. **Non consigliata** per Quack allo stato attuale: il costo non è giustificato dal beneficio.

### 1.4 Governance: come i best-in-class evitano l'errore di configurazione, senza una separazione strutturale

Domanda di fondo: come evitano i sistemi enterprise che qualcuno configuri per errore due sedi realmente distanti come se fossero la stessa, ottenendo un trasferimento istantaneo che nella realtà richiederebbe un camion? Risposta verificata su tutti e quattro, sorprendentemente uniforme: **nessuno lo deduce automaticamente da una struttura dati — è sempre una decisione esplicita di una persona autorizzata**, non un'inferenza silenziosa dalla gerarchia.

- **SAP**: la scelta tra il movimento immediato a un passaggio (tipo 301) e lo Stock Transport Order non è mai automatica in base a quali plant sono coinvolti — è l'utente che sceglie quale transazione lanciare in base alle esigenze di business (documentazione, tracciamento costi). Lo stesso plant può usare entrambe le modalità verso lo stesso plant di destinazione, a seconda del caso concreto. ([fonte](https://www.saplogisticsexpert.com/stock-transfer-in-sap-the-best-guide-with-all-scenarios-explained/))
- **Microsoft Dynamics 365**: stessa logica esplicita — i Transfer Journal sono adatti a trasferimenti nello stesso sito o vicini, i Transfer Order per spostamenti a lunga distanza; è una linea guida per l'utente su quale documento aprire, non un automatismo che legge la gerarchia dei siti. ([fonte](https://www.encorebusiness.com/blog/transfer-order-transfer-journal-differences-d365fo/))
- **NetSuite**: distinzione ancora più netta — i movimenti tra sotto-sedi ("sub-location") richiedono *sempre* un Inventory Transfer formale con impatto contabile/tracciamento, indipendentemente dalla gerarchia; la documentazione ufficiale avverte esplicitamente di non usare le Location per modellare zone fisiche interne (scaffali/banchine), perché causa problemi con evasione ordini, costing LIFO/FIFO e reportistica — le vere zone interne vanno modellate coi Bin, un concetto tecnico diverso. ([fonte](https://8quanta.com/study/when-to-use-sub-locations-in-netsuite/))
- **Odoo**: le regole di instradamento (routes, push/pull, numero di step per ricevimento/spedizione) sono impostazioni esplicite configurate una volta dall'amministratore per ciascun magazzino/percorso — non qualcosa che il sistema deduce guardando quali magazzini esistono. ([fonte](https://www.odoo.com/documentation/19.0/applications/inventory_and_mrp/inventory/shipping_receiving/daily_operations/use_routes.html))

**Filo conduttore**: la correttezza non viene garantita dalla struttura dei dati, viene garantita tenendo la decisione nelle mani di una persona autorizzata, in un momento esplicito. **Applicato in Quack**: il permesso per modificare `parent_id`/`tipo` di un magazzino resta `manage_users` (già usato per tutta l'anagrafica) — coerente con la separazione di ruoli che SAP applica tra Plant e Storage Location, in versione proporzionata alla scala di Quack. Nessuna inferenza automatica aggiuntiva: la regola "stessa radice fisica ⇒ trasferimento diretto ammesso" (documento A §5.1) resta com'è, perché il controllo di correttezza è già a monte, nel momento in cui l'anagrafica del magazzino viene creata con `parent_id` esplicito.

### 1.5 ATP (Available to Promise) — non implementato, deliberatamente

Quando un ordine cliente viene confermato, un motore ATP deciderebbe da quale magazzino prelevare, con regole di instradamento (magazzino più vicino, scorta sufficiente, priorità canale — SAP/Dynamics: "sourcing rules"; Odoo: "routes" e regole push/pull). Automatizza decisioni logistiche complesse e riduce split-shipment non necessari, ma è la parte più sofisticata da progettare bene — con poche sedi (2-3, il caso Quack) spesso conviene la scelta manuale dell'operatore piuttosto che un motore di regole. **Deliberatamente non implementato**: la riserva stock resta aggregata per prodotto (documento A §10.3), lo split shipment resta una decisione manuale (documento A §10.2).

### 1.6 Permessi per magazzino — non implementati

Gli ERP enterprise associano gli utenti ai magazzini a cui hanno accesso (tabella ponte utente↔magazzino con ruolo: operatore, responsabile, sola lettura) — rilevante anche per la segregazione ADR (un sito può avere un'autorizzazione che un altro non ha). Overhead di configurazione da mantenere; se i magazzini sono pochi e il team piccolo, spesso si parte "tutti vedono tutto" e si stringe dopo. **In Quack: non implementato** (documento A §10.18) — tutti gli utenti con permesso di gestire il magazzino vedono tutte le sedi attive.

---

## 2. UX: visualizzazione giacenze e cambio sede

### 2.1 SAP S/4HANA

**Visualizzare le giacenze.** L'app "Stock Overview" mostra, per un dato materiale, tutte le quantità disponibili incrociando plant e storage location in un'unica tabella — una matrice, non solo un totale aggregato né solo una sede singola. Potente, ma richiede di capire prima la differenza tra plant e storage location.

**Cambiare vista.** Non esiste un "magazzino attivo di sessione" universale: ogni transazione/app chiede esplicitamente plant e storage location come parametri di ricerca, ogni volta. Elimina il rischio di operare per errore sul posto sbagliato (nessun default nascosto), al prezzo di dover reinserire il contesto quasi a ogni schermata — mitigato da valori di default per utente/ruolo configurabili dall'amministratore.

**Pro**: massima precisione, nessuna ambiguità, ottima tracciabilità storica. **Contro**: verbosità enorme, curva di apprendimento ripida, concetti sovrapposti che confondono chi non viene da un contesto SAP.

### 2.2 Oracle NetSuite

**Visualizzare le giacenze.** Concetto di "location" (sede) e, dentro ciascuna, opzionalmente "bin" (ubicazione fisica specifica). Le liste articoli mostrano tipicamente il totale, i report di inventario dedicati permettono il dettaglio per location/bin.

**Cambiare vista.** Filtri espliciti sulle liste (colonna "Location" filtrabile) più che un selettore di sessione persistente. La sede diventa "attiva" davvero solo nel contesto di un documento specifico (ordine, ricezione), non come stato ambientale generale.

**Pro**: buon compromesso flessibilità/semplicità. **Contro**: la configurazione di bin/location ha molte opzioni da capire prima di usarla bene.

### 2.3 Microsoft Dynamics 365

**Visualizzare le giacenze.** L'elenco "on-hand" è una vista aggregata configurabile: la stessa tabella di dettaglio (ogni combinazione articolo/sede/lotto) può essere mostrata sia come somma totale che come dettaglio per sede, in base a quali colonne/raggruppamenti l'utente applica — stesso dato sorgente, due lenti diverse, non due funzionalità separate.

**Cambiare vista.** Ogni documento ha un campo "Location" esplicito da indicare sempre, non un contesto di sessione globale. Le sedi hanno una "Location Card" che ne definisce anche le rotte di trasferimento predefinite verso altre sedi.

**Pro**: pattern pulito (stesso dato, lenti diverse); rotte predefinite riducono l'attrito per spostamenti ricorrenti. **Contro**: nessuno strutturale su questo fronte specifico.

### 2.4 Odoo

**Visualizzare le giacenze.** Sulla scheda di ogni prodotto, un contatore "In Stock" cliccabile apre subito il dettaglio riga per riga per magazzino — nessun report separato da cercare. Le viste elenco permettono di raggruppare per magazzino con un filtro standard, o lasciare il totale aggregato di default.

**Cambiare vista.** Nessun "magazzino attivo" di sessione universale: ogni operazione di magazzino mostra i propri elenchi filtrabili per sede tramite filtri standard, con la possibilità di salvarne uno come preferito. Il concetto più vicino al "magazzino attivo" è legato al contesto del *documento* (un ordine di vendita ha una sede di spedizione di default, configurabile per azienda/utente), non un interruttore globale sempre visibile.

**Pro**: la UX più semplice e diretta dei quattro esaminati. **Contro**: meno controllo puntuale rispetto a SAP/NetSuite (niente approvazioni multi-step); il "magazzino attivo" implicito nei documenti può comunque essere fonte di errore se l'utente non guarda con attenzione il campo sede.

### 2.5 Il filo conduttore comune, e cosa ha adottato Quack

**Primo punto comune**: nessuno dei quattro sistemi usa un "magazzino attivo" ambientale e nascosto come unico meccanismo. Tutti rendono la sede un dato esplicito legato al *documento* o alla *vista* in uso in quel momento, non uno stato di sessione invisibile che filtra tutto silenziosamente. Dove esiste un default di comodo (Odoo per i documenti, SAP per utente/ruolo), è un default pre-compilato e sempre visibile/modificabile sul documento stesso, mai un filtro invisibile su una schermata di sola lettura.

**Secondo punto comune**: la vista di giacenza aggregata ("quanto ne ho in totale, ovunque") e la vista per singola sede sono la *stessa informazione* guardata con lenti diverse, non due funzionalità separate da mantenere — il dato di partenza è sempre quello granulare per sede, l'aggregato è una somma calcolata al volo, mai una colonna scritta a parte.

**Adottato in Quack**: il selettore magazzino permanente in topbar è stato rimosso (documento A §3.2) — resta solo come default pre-compilato e sempre modificabile sui documenti che lo richiedono (movimento manuale, nuova spedizione, nuovo trasferimento, inventario), esattamente il pattern Odoo/SAP-per-ruolo. Le viste di elenco/report (Giacenze, Riordino, Lotti in scadenza) hanno un filtro esplicito e visibile sulla pagina stessa, con un'opzione reale "tutti i magazzini" che è una somma corretta (`SUM` con `GROUP BY`), mai un valore preso a caso da una riga qualunque — coerente col secondo punto comune sopra.

---

## 3. Trasferimenti: fisici vs virtuali

### 3.1 Il dualismo fisico/logico nei quattro sistemi

Spesso in azienda serve isolare una parte della merce (es. "Merce in quarantena", "Reparto Resi") senza spostarla fisicamente. Costringere l'operatore a un documento con tempi di spedizione/arrivo per un passaggio logico istantaneo è una forzatura. Ecco come i 4 ERP gestiscono il dualismo tra spostamento fisico (lento, richiede camion) e virtuale (istantaneo, logico):

| Sistema | Movimento virtuale/logico | Movimento fisico tracciato |
|---|---|---|
| SAP | **Transfer Posting** — istantaneo | **Stock Transport Order (STO)** — merce "viaggiante"; ammesse scorciatoie (movimento 301) se non serve tracciare il transito |
| Odoo | Movimento diretto tra Location (es. Disponibile → Quarantena) — istantaneo | *Internal Transfer* — passa per una "Transit Location" virtuale, imitando il viaggio |
| Dynamics 365 | **Transfer Journal** — registrazione diretta, senza attese | **Transfer Order** — parcheggia le quantità in un "Transit Warehouse" invisibile, blocca la merce fino a conferma arrivo |
| NetSuite | **Bin Transfer** / Inventory Transfer — effetto immediato | **Transfer Order** — richiede un "Fulfillment" e, ore/giorni dopo, un "Receipt" |

**Regola aurea comune a tutti e quattro**: il modello informatico deve riflettere la realtà logistica dell'operazione. Spostamento virtuale/logico → registrazione istantanea. Spostamento fisico tracciato → documento formale che lascia la merce "sospesa" durante il transito.

### 3.2 La scelta Quack: Trasferimento Diretto vs con Transito

Implementato coerentemente con la regola aurea sopra: due magazzini che condividono la stessa **radice fisica** (`COALESCE(parent_id, id)` coincide — es. una sede e una sua ubicazione virtuale interna) possono usare il **Trasferimento Diretto**, istantaneo (documento A §5.1); due magazzini con radici fisiche diverse richiedono il **Trasferimento con Transito**, a stati (documento A §5.2). Nome allineato al lessico di mercato: SAP chiama "trasferimento diretto" il movimento con uscita/entrata simultanea (tipo 301/311); Dynamics 365 lo chiama Giornale di Trasferimento, contrapposto all'Ordine di Trasferimento con transito.

Anche il Trasferimento Diretto genera comunque un record in `trasferimenti_magazzino` (tracciabilità, numero progressivo), ma lo porta istantaneamente allo stato `completato`, bypassando `in_transito` in un'unica transazione — riusando le stesse funzioni scritte per il documento pieno (`qk_insert_trasferimento` → `qk_trasferimento_registra_partenza` → `qk_conferma_arrivo_trasferimento`), zero duplicazione di logica.

### 3.3 Perché il vincolo di gerarchia a due soli livelli

Odoo modella tutto lo spazio (fisico e virtuale) con un concetto unificato di "Location", ad albero di profondità arbitraria — estremamente flessibile, ma con un rischio esplicito riconosciuto dagli stessi materiali Odoo: se le ubicazioni virtuali non vengono gestite con rigore, si generano "scorte fantasma" disperse in reparti fittizi, sballando il reale valore del magazzino. SAP, NetSuite e Dynamics evitano questo rischio con una gerarchia **fissa** a due livelli (Plant→Storage Location, Location→Bin, Site→Warehouse). Quack ha scelto lo stesso vincolo a due livelli (`magazzini.parent_id` può puntare solo a un magazzino fisico radice, mai a un altro non-fisico né a un fisico che ha già un genitore) — evitando il punto debole individuato nel modello più libero di Odoo.

---

## 4. Soglie di riordino

| Sistema | Granularità | Interfaccia |
|---|---|---|
| SAP S/4HANA | materiale + plant (+ storage location per configurazioni avanzate) | anagrafica dedicata ("Material Master", vista MRP) |
| Oracle NetSuite | articolo + location | funzione "Multi-Location Inventory" |
| Microsoft Dynamics 365 | articolo + sede, tramite record separato "Stockkeeping Unit" (SKU) | scheda SKU distinta dalla scheda articolo |
| Odoo | prodotto + magazzino, con quantità minima **e** massima | "Reordering Rules", tabella a singola schermata (Prodotto, Magazzino, Min, Max) |

**Terzo punto in comune tra tutti e quattro**: la scorta minima vive sempre alla granularità prodotto+sede, mai solo prodotto. Quack è allineato: `giacenze.scorta_minima` è per la coppia (prodotto, magazzino) — unica fonte di verità, non un override facoltativo di un valore a livello prodotto (che è stato rimosso dallo schema).

**Non adottato da Quack**: la separazione concettuale "SKU distinta dall'articolo" di Dynamics 365 (pulita, ma un livello di indirezione in più da spiegare) e la generazione *automatica* di un trasferimento quando la scorta di un magazzino satellite scende sotto soglia (Odoo "Reordering Rules" con collegamento diretto a un trasferimento) — in Quack la proposta di riordino resta sempre una decisione manuale dell'operatore (documento A §7), coerente con la scelta di non avere un motore ATP (§1.5).
