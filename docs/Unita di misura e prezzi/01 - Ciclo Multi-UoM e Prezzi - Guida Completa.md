# 01 — Ciclo Multi-UoM e Prezzi: Guida Completa

Guida operativa unica e aggiornata (UX + comportamento backend/DB insieme) del motore di gestione unità di misura multiple e prezzi in Quack ERP. Sostituisce e assorbe `Architettura, Analisi e Best Practice... Versione Definitiva.md`, `Listini nella formulazione offerte.md`, `listini_architettura.md` e `UI_UX_PREZZI.md`. Per il materiale comparativo di mercato (SAP/Oracle/Dynamics, non normativo), vedi [00 - Best Practice di Mercato](00%20-%20Best%20Practice%20di%20Mercato%20(Multi-UoM%20e%20Pricing).md). Per lo schema tecnico e i riferimenti puntuali a file/riga di codice, vedi [02 - Dettaglio Tecnico](02%20-%20Dettaglio%20Tecnico%20-%20Schema%20DB%20e%20Codice.md). Per il registro storico completo delle decisioni e la loro motivazione, vedi [REVISIONE - Ridondanze, Contraddizioni e Piano di Riorganizzazione.md](REVISIONE%20-%20Ridondanze%2C%20Contraddizioni%20e%20Piano%20di%20Riorganizzazione.md) e [PIANO - Motore di Pricing Multi-UoM.md](PIANO%20-%20Motore%20di%20Pricing%20Multi-UoM.md).

---

## 1. Decisione architetturale di partenza: Approccio 1, non Approccio 2

Quack usa **l'Approccio 1**: ogni prodotto ha al massimo **una sola** UoM commerciale oltre alla Stock UoM (obbligatoria). Non esiste una tabella di conversioni N-a-N (un prodotto con più unità alternative contemporanee) — quello è l'"Approccio 2", roadmap futura non implementata, da costruire solo se emergerà il bisogno reale di vendere lo stesso prodotto in più unità alternative sullo stesso documento.

Perché questa scelta è corretta oggi: l'Approccio 1 copre il 100% dei casi reali attuali. Costruire subito una tabella N-a-N sarebbe complessità architetturale anticipata senza un requisito reale — coerente con l'approccio "non anticipare architettura" seguito nel resto del progetto.

---

## 2. Anagrafica prodotto: le due unità e il fattore di conversione

Ogni prodotto ha:
- **`unita_misura_stock_id`** — obbligatoria. L'unità in cui il magazzino conta il prodotto.
- **`unita_contenuto_id`** — opzionale. L'unità "commerciale" con cui il prodotto viene venduto/comprato in colli (es. FUSTO invece che a peso).
- **`quantita_contenuto`** — quanta UoM di contenuto è contenuta in 1 unità di Stock UoM. Esempio: Stock = FUSTO, Contenuto = KG, `quantita_contenuto = 25` significa "1 fusto contiene 25 kg".
- **`num_factor` / `den_factor`** — lo stesso fattore come frazione intera esatta, usata per tutti i calcoli (mai un float diretto, per evitare derive di arrotondamento su frazioni periodiche).

**Convenzione importante**: `num_factor`/`den_factor` rappresentano **1/quantita_contenuto**, non `quantita_contenuto` stesso. Per `quantita_contenuto=25` → `num_factor=1, den_factor=25`. Questo è stato oggetto di un bug reale corretto il 14/07/2026 (vedi 02, "Convenzione num_factor/den_factor" per la storia completa e perché è facile sbagliarlo).

### Blocco cambio Stock UoM dopo movimenti

Se un prodotto ha almeno un movimento in `movimenti_magazzino` (anche uno solo, indipendentemente dalla giacenza attuale — che può essere zero pur avendo storico), il cambio di `unita_misura_stock_id` è **vietato in modo permanente**. Motivazione: un solo movimento storico rende ambiguo reinterpretare tutto lo storico (costo medio ponderato, quantità aggregate nei report) se la Stock UoM cambiasse — coerente con SAP (basta un movimento per bloccare per sempre).

### Cambio Stock UoM permesso (zero movimenti) e ricalcolo bozze — IMPLEMENTATO

Se il cambio è permesso (zero movimenti), e il prodotto ha già preventivi/RFQ/ordini d'acquisto **in bozza** che lo referenziano, quei documenti userebbero ancora il vecchio fattore se non venissero aggiornati. Regole implementate (14/07/2026):

1. **Guardia**: se il prodotto ha già una UoM commerciale configurata, il nuovo fattore di conversione è **obbligatorio** nello stesso salvataggio che cambia la Stock UoM — altrimenti il salvataggio viene rifiutato con un errore esplicito. Senza un fattore valido non si potrebbe ricalcolare correttamente nulla.
2. **Ricalcolo, non rietichettatura**: "aggiornare le bozze" non significa mai cambiare solo il nome dell'unità sul numero già salvato — significa ripassare il valore che l'utente aveva realmente inserito nella stessa identica funzione di calcolo usata per una riga nuova (`qk_uom_converti_riga()`/`qk_calcola_riga_offerta()`).
3. **Frontend**: quando l'utente cambia la Stock UoM e il prodotto ha già una UoM commerciale, il campo "quantità per confezione" si azzera con un messaggio inline che obbliga a re-inserirlo consapevolmente, per non sottomettere per errore un vecchio numero.
4. **Cosa viene ricalcolato**: preventivi in bozza, RFQ in bozza, ordini d'acquisto in bozza (mai gli ordini cliente, che non hanno un editor di riga in bozza; mai i documenti già confermati/inviati, per il principio di immutabilità — vedi §7), **più** il valore canonico `quantita_minima_stock` su `listini_prezzi` e `prodotti_fornitori_prezzi` per quel prodotto (vedi §4). Tutto nella stessa transazione del salvataggio prodotto, con un messaggio di conferma che riporta i conteggi ("3 preventivi in bozza ricalcolati...").

Funzione: `qk_ricalcola_bozze_per_cambio_stock_uom()` in `includes/prodotti.php`, verificata con esecuzione reale (SQL contro schema reale + calcolo numerico) il 14/07/2026.

### Disattivazione di una UoM — non ancora implementata (backlog)

Oggi non esiste alcun modo di disattivare una unità di misura dai menu di scelta per prodotti nuovi pur lasciandola funzionante per chi la usa già (nessuna colonna `attivo` su `unita_misura`, nessuna UI di gestione). Specifica pronta ma rimandata deliberatamente, perché nessun caso d'uso reale la richiede ancora — vedi `docs/Backlog Sviluppi Futuri.md`, voce 6bis.

### UoM commerciale rimossa/cambiata con prezzi già esistenti in quella unità

**Nessuna guardia bloccante**: il cambio/rimozione di `unita_contenuto_id` procede sempre, coerente con SAP (non blocca mai un'anagrafica per via di riferimenti che diventerebbero "non più applicabili" — li lascia semplicemente irraggiungibili). Il motore di ricerca prezzo (§5), non trovando corrispondenza per la combinazione prodotto/UoM, scende semplicemente al livello di fallback successivo. Uno strumento di reportistica opzionale (non un blocco) per trovare prezzi diventati orfani è specificato ma non ancora costruito — vedi `Backlog Sviluppi Futuri.md`.

---

## 3. Le due viste sui listini (globale vs scheda prodotto)

Quack offre due modi per interagire con gli stessi dati sottostanti — **entrambe le viste scrivono e leggono esattamente dalle stesse tabelle**, nessuna duplicazione:

### A. Le "vere sezioni" (viste top-down)

- URL: `/sales/pricelists`, `/purchases/pricelists`.
- Scopo: gestione massiva, creazione di campagne, import Excel.
- Chi la usa: Direttore Commerciale / Responsabile Acquisti — entra nel listino e da lì gestisce i prezzi di centinaia di prodotti.

### B. Le "sotto-sezioni" (viste bottom-up, scheda prodotto)

- URL: scheda prodotto → tab "Prezzi di Vendita" / "Costi d'Acquisto".
- Scopo: inserimento rapido "al volo", confronto diretto durante l'operatività quotidiana.
- Pattern UX **Master List + Drawer** (acquisti): la tabella principale mostra SKU fornitore, lead time e tariffa base; un drawer laterale nasconde la complessità (scaglioni volume, vincoli RRP, selettore listino d'acquisto) mantenendo la griglia principale pulita.
- Pattern UX **Matrice Prodotto-Centrica (Cascading NetSuite)** (vendite): righe = listini (il **Listino 1** funge da Listino Base e non è eliminabile), colonne = scaglioni di quantità.
- **Ereditarietà visiva e logica (Cascading Verticale)**: a differenza dei vecchi comportamenti, l'applicazione sposa il modello NetSuite. Una cella vuota per un dato scaglione in un listino non-base (es. Listino Rivenditori) **eredita matematicamente il prezzo dal Listino Base (Listino 1) per quello stesso scaglione**, a cui viene poi sottratto in automatico lo `sconto_default_perc` configurato per quel listino. I placeholder JS informano l'utente sull'origine del calcolo (es. `(Ered.)` o `(Calcolato)`). Questa logica sostituisce i precedenti concetti di ereditarietà orizzontale o "fallback" su listini standard. 
- La riga di un listino mostra un indicatore `readonly` della percentuale di sconto applicata, permettendo all'utente di avere chiara la derivazione matematica senza impazzire. L'inserimento manuale in una cella sovrascrive (override) la formula.

### Acquisti: nessun "listino d'acquisto globale predefinito"

A differenza delle vendite, non esiste un listino d'acquisto globale di default generato automaticamente in background per i prezzi presi "al volo" senza listino esplicito — un prezzo d'acquisto senza listino resta con `listino_acquisto_id = NULL`, trattato come "prezzo base". Un blocco di codice residuo che tentava di auto-creare un simile listino in `ProductsController::saveForn()` è stato **rimosso** (14/07/2026, era codice morto/vestigiale mai raggiunto da alcun percorso utente reale).

---

## 4. Il motore di pricing multi-UoM: perché serve e come funziona

### Il problema che ha reso necessario questo motore

Un prezzo a scaglioni (es. "da 100 kg in su costa 10€/kg") è salvato con una soglia di quantità (`quantita_minima`) e un'unità di prezzo. Se due scaglioni sullo stesso prodotto/listino sono scritti in unità diverse (es. uno scaglione "100 KG" e un altro "5 FUSTI"), confrontare i numeri grezzi (`100` contro `5`) senza convertirli prima nella stessa unità produce un ordinamento privo di senso: `100 > 5` numericamente, ma se 1 fusto = 25 kg, 5 fusti = 125 kg, che è realmente la soglia più alta.

**Esempio numerico completo**: prodotto con Stock UoM = FUSTO, UoM di contenuto = KG, `quantita_contenuto = 25`. Scaglione A: `quantita_minima=100` in KG. Scaglione B: `quantita_minima=5` in FUSTO. Un ordine di 150 kg (= 6 fusti):
- **Prima della correzione**: confronto grezzo `100` vs `5` → sceglie sempre A, indipendentemente dal fatto che B (5 fusti = 125 kg) sia la soglia fisicamente più alta superata da un ordine di 150 kg.
- **Dopo la correzione**: entrambi gli scaglioni vengono canonicalizzati in Stock UoM: A → `100 / 25 = 4` fusti; B è già in stock → `5` fusti. La richiesta viene anch'essa convertita: `150 / 25 = 6` fusti. Query: `quantita_minima_stock <= 6 ORDER BY quantita_minima_stock DESC` → candidati 4 e 5, vince **5 (Scaglione B)** — il risultato fisicamente corretto.

### La soluzione: soglia canonica sempre in Stock UoM

La colonna `unita_misura_id` di una riga di prezzo serve a due scopi che vanno tenuti distinti:

| Scopo | Colonna | Corretto così? |
|---|---|---|
| In che unità è **quotato** il prezzo (es. "10€/kg" vs "250€/fusto") | `unita_misura_id` | Sì — pilastro "prezzo in UoM nativa", non va toccato |
| A partire da quale **quantità** si applica (la soglia) | `quantita_minima`, confrontata grezza | No — qui nasceva il bug |

Soluzione implementata: tre colonne aggiunte a `listini_prezzi` e `prodotti_fornitori_prezzi`:
- **`quantita_minima_intento`** + **`unita_misura_soglia_id`** — il valore e l'unità con cui l'utente ha *davvero* scritto la soglia (il suo intento originale, conservato per poterlo ricalcolare in futuro).
- **`quantita_minima_stock`** — il valore **canonico**, sempre in Stock UoM, calcolato con la stessa funzione autorevole `qk_uom_converti_riga()`. È questo il valore che il motore di ricerca prezzo confronta — mai più il numero grezzo.

Il vincolo `UNIQUE` è stato spostato dal valore grezzo al valore canonico (`UNIQUE(listino_id, prodotto_id, quantita_minima_stock)` e equivalente lato acquisti), intercettando anche i "doppioni mascherati" (es. "100 KG" e "4 FUSTI" con fattore 25 sono la stessa identica soglia fisica scritta due volte).

### Chi converte, e quando (regola di sicurezza)

La conversione **avviene sempre lato server (PHP)**, mai fidandosi di un numero calcolato solo nel browser. Il frontend continua a offrire un'anteprima immediata (per reattività UI), ma non è mai l'origine del dato salvato o usato per decidere un prezzo reale — stesso principio di sicurezza già adottato per il resto del ciclo documentale (vedi §6).

### Cosa succede quando cambia il fattore di conversione o la Stock UoM

Vedi §2, "Cambio Stock UoM permesso e ricalcolo bozze" — la stessa funzione che ricalcola le righe documento in bozza (`qk_ricalcola_bozze_per_cambio_stock_uom()`) ricalcola **anche** `quantita_minima_stock` su tutte le righe prezzo del prodotto interessato, dall'intento originale + fattore corrente (mai un aggiustamento diretto del numero canonico già salvato).

---

## 5. Cascata di ricerca del prezzo (Fallback Pricing Hierarchy)

### Ciclo Vendite

Il motore (`getSalePrice()` in `includes/pricing.php`, chiamata dal controller) valuta, dal più specifico al più generico:

1. **Copia transazione**: se un ordine nasce da un preventivo, il prezzo è ereditato e congelato — nessun ricalcolo.
2. **Listino/i assegnati al cliente** (`clienti_listini`): un cliente può avere più listini assegnati contemporaneamente, ciascuno con priorità (`priorita`, default 10) e finestra di validità propria. Il sistema sceglie sempre quello con priorità più alta tra i validi in quel momento (nome del listino come spareggio) — un "listino promozionale temporaneo" non è un meccanismo diverso, è solo un'assegnazione con priorità più alta e/o date più strette.
3. **Listino standard** (`is_default = 1`): se nessun listino assegnato produce un prezzo per quel prodotto.
4. **Ricarico su costo (Cost-Plus)**: markup fallback a due livelli, vedi sotto.
5. **Manuale**: nessun prezzo trovato, l'utente inserisce a mano.

### Ciclo Acquisti

Il motore (`getPurchasePrice()` in `includes/pricing.php`) è simmetrico: motore a scaglioni reale su `prodotti_fornitori_prezzi`, stessa logica di ricerca "scaglione più alto ≤ quantità richiesta", stesse colonne canoniche. Un'RFQ con offerta accettata eredita il prezzo (l'accettazione di un'offerta rifiuta automaticamente e nello stesso istante tutte le altre sulla stessa RFQ — non può mai accadere che l'ordine finisca a un fornitore diverso da quello selezionato come vincitore).

### Markup fallback (livello 4 vendite): due livelli, non tre

Quando nessun listino produce un prezzo, il sistema calcola `Prezzo = Costo d'acquisto × (1 + Markup/100)`. Il markup è risolto **dinamicamente a runtime** (mai copiato/congelato nell'anagrafica prodotto alla sua creazione), in ordine:

1. Percentuale impostata sulla scheda del prodotto specifico, se presente (anche `0`, che significa esplicitamente "vendi a prezzo di costo" — distinto da "non impostato": il codice controlla `!== null && !== ''`, mai un controllo di verità generico che tratterebbe `0` come assente).
2. Percentuale di default globale, dalle Impostazioni generali.
3. Solo se nessuna delle due è mai stata configurata: `+20%` come valore di emergenza.

**Non esiste** un livello intermedio "markup di categoria prodotto" — la risoluzione dinamica (non una copia statica al momento della creazione) è la scelta corretta: se il default globale cambia, tutti i prodotti senza override lo applicano subito, senza bisogno di un job di bulk-update.

---

## 6. Preventivo/ordine vendita: due momenti, due responsabilità

1. **Momento 1 — suggerimento a video**: mentre l'utente compila la riga, il backend restituisce il prezzo di listino così com'è, insieme alla UoM nativa del prezzo — **senza convertirlo** nella UoM scelta sulla riga. È il browser (JavaScript) a convertire per l'anteprima a schermo.
2. **Momento 2 — salvataggio**: quando il documento viene confermato, il server rifà da capo tutti i calcoli in PHP (`qk_uom_converti_riga()`), ignorando completamente qualunque numero mostrato dal browser.

Questa architettura a due fasi è la scelta corretta, non un compromesso: il "suggerimento" del Momento 1 è solo un'anteprima mai salvata così com'è; farlo calcolare al server non aggiungerebbe sicurezza (verrà ricalcolato comunque al Momento 2), solo un giro di rete inutile. Il principio "non fidarsi mai di un calcolo fatto solo nel browser quando si scrive nel database" resta rispettato, perché il Momento 2 (l'unico che conta ai fini contabili) è interamente responsabilità del server.

### Badge origine prezzo e override

Ogni riga mostra un'icona che indica la provenienza del prezzo (`origine`): `dedicato` (listino cliente), `standard` (listino generale), `costo` (ricarico su costo), `manuale`. Se l'utente digita manualmente un prezzo, il badge cambia in una penna gialla ("Modificato manualmente"), e quel prezzo non viene più sovrascritto automaticamente dal server — ma **si converte comunque proporzionalmente** se l'utente cambia il toggle di visualizzazione stock/commerciale: cambiare l'unità mostrata non è una nuova proposta di prezzo, è lo stesso numero espresso diversamente.

**Lato RFQ acquisti**: l'elemento HTML del badge (`.price-origin-badge`) esiste già nella vista, ma **non è ancora stato verificato end-to-end** se sia effettivamente popolato con dati reali o resti markup vuoto — punto aperto, richiede apertura di una RFQ reale nel browser per la verifica (vedi REVISIONE §C7).

---

## 7. RFQ e ordini d'acquisto: stesso pattern, con differenze reali e intenzionali

### Cosa succede quando un documento genera una "nuova versione" di sé stesso

| Documento | Meccanismo di riapertura | Comportamento snapshot |
|---|---|---|
| Preventivi vendita | Nessuno — bloccato in modo definitivo | N/A |
| Ordini cliente | "Riapri" cambia solo lo stato di testata | N/A, nessun editor di riga |
| RFQ acquisto | "Nuova versione" (`qk_rfq_nuova_versione()`) | **Ricalcolato** dai dati prodotto correnti |
| Ordini d'acquisto | "Nuova revisione" (`qk_ordine_crea_revisione()`) | **Preservato esattamente**, mai ricalcolato |

**Perché questa differenza è corretta**: una RFQ non è vincolante (nessun impegno preso col fornitore), quindi una nuova versione riflette correttamente i dati di oggi. Un ordine d'acquisto inviato è un impegno contrattuale già preso: una sua revisione deve partire dalle stesse condizioni concordate — coerente con SAP (RFQ liberamente rinegoziabile, Purchase Order inviato richiede *Change Management* che preserva le condizioni originarie).

### Numerazione RFQ in nuova versione — corretto il 14/07/2026

`qk_rfq_nuova_versione()` ora preserva il numero radice della RFQ, incrementando solo il suffisso di versione (`RFQ/2026/0001` → `RFQ/2026/0001 v2` → `v3`, ecc.), invece di "bruciare" un nuovo numero sequenziale globale come faceva prima — allineato al pattern già corretto usato dagli ordini d'acquisto (`qk_ordine_crea_revisione()`).

### Resi e note di credito

Un reso/nota di credito **eredita sempre lo snapshot della riga d'ordine originale referenziata** (fattore di conversione, prezzo unitario in stock), mai ricalcolato sui dati prodotto correnti — coerenza con l'immutabilità, con la fisica (il fattore descrive un fatto vero al momento della vendita), e con la contabilità (la nota di credito deve accreditare l'importo realmente fatturato).

---

## 8. Matrice dei casi limite (stato reale, verificato)

| # | Caso | Comportamento reale | Stato |
|---|---|---|---|
| 1 | Cambio Stock UoM dopo movimenti | Vietato in modo permanente (basta 1 movimento, indipendente dalla giacenza) | ✅ Implementato |
| 2 | Cambio Stock UoM senza movimenti | Permesso; ricalcola bozze + soglie canoniche (§2) | ✅ Implementato |
| 3 | UoM commerciale mai definita | UI blocca su Stock UoM soltanto | ✅ Implementato |
| 4 | UoM commerciale rimossa con prezzi esistenti | Nessun blocco; prezzi diventano irraggiungibili, non danneggiati | ✅ Implementato (report di pulizia opzionale non ancora costruito) |
| 5 | Variazione del solo fattore di conversione | Documenti storici intatti (snapshot); soglie canoniche ricalcolate (§2) | ✅ Implementato |
| 6 | Disattivazione di una UoM | Funzionalità non ancora costruita | ⏸️ Backlog (voce 6bis) |
| 7 | Scaglioni misti in unità diverse sullo stesso listino | Confronto sul valore canonico, mai sul numero grezzo | ✅ Implementato (§4) |
| 8 | Doppioni mascherati (stessa soglia fisica, unità diverse) | Bloccati dal vincolo `UNIQUE` su `quantita_minima_stock` | ✅ Implementato |
| 9 | "Terza unità" di quotazione fornitore | Validazione server-side: deve essere Stock o Commerciale del prodotto, mai una terza unità sconosciuta | ✅ Implementato |
| 10 | Reso/nota di credito su riga con conversione UoM | Eredita sempre lo snapshot originale, mai ricalcolo | ✅ Deciso; flusso applicativo guidato non ancora costruito |
| 11 | RFQ vs Ordine d'acquisto: nuova versione/revisione | RFQ ricalcola, Ordine preserva (§7) | ✅ Implementato, dichiarato normativo |
| 12 | Preventivo scaduto convertito in ordine | Avviso non bloccante se `data_scadenza` è nel passato, mai blocco | ⏸️ Specificato, non ancora applicato |
| 13 | Prezzo "equivalente in stock" su listino chiuso | Va mostrato solo per listini ancora validi, mai per listini con `valido_al` nel passato | ⏸️ Specificato, non ancora applicato |
| 14 | Priorità tra più listini assegnati allo stesso cliente | `clienti_listini.priorita`, già implementato | ✅ Implementato |
| 15 | Import massivo: riga modificata a mano dopo l'ultimo import | Non sovrascritta silenziosamente; segnalata nel riepilogo import | ✅ Implementato |
| 16 | Fornitore disattivato nei calcoli di costo di riferimento | Escluso dalle viste di costo; RFQ nuove correttamente filtrate (verificato) | ✅ Implementato |
| 17 | Catch Weight (doppia misura pezzi/peso) | Fuori scope — Quack assume prodotti a peso stabile | ⏸️ Backlog (voce 7), non pianificato |
| 18 | Badge origine prezzo in RFQ popolato con dati reali | Elemento UI presente, dato reale non ancora verificato end-to-end | ⏸️ Da verificare nel browser |

---

## 9. Selettore listino di testata e tracciabilità riga

- Il selettore del listino in testata è **sempre** un campo autocomplete (mai un dropdown a soglia numerica) — scelta più semplice, evita l'ambiguità "sopra/sotto N listini".
- *Vendite*: preseleziona il listino di default del cliente, o il listino pubblico se assente.
- *Acquisti*: filtra per fornitore selezionato, preseleziona il listino preferenziale.
- Ogni riga traccia un badge di origine (§6) e uno stato override; un cambio di UoM di visualizzazione converte sempre proporzionalmente, indipendentemente dallo stato manuale/automatico della riga.

---

## 10. RRP e sottocosto

- I vincoli RRP (prezzo minimo/massimo consigliato) si leggono da **tutti** i fornitori del prodotto, non solo dal fornitore preferito ("la Stella"): la Stella si usa solo per il costo d'acquisto reale ai fini del blocco sottocosto, non per i vincoli RRP.
- **Soft-warning** su violazione RRP: icona gialla, non bloccante, con tooltip esplicativo.
- **Hard-warning** su sottocosto reale (prezzo sotto il costo d'acquisto del fornitore preferito): blocco.
- Multi-valuta per l'RRP (es. fornitore che quota in USD contro vendita in EUR): fuori scope oggi — Quack lavora solo in EUR (confermato col proprietario del progetto). Tracciato in `Backlog Sviluppi Futuri.md`, voce 4.

---

## 11. Riferimenti

- Schema tecnico, funzioni reali, file e righe: [02 - Dettaglio Tecnico](02%20-%20Dettaglio%20Tecnico%20-%20Schema%20DB%20e%20Codice.md).
- Materiale comparativo di mercato: [00 - Best Practice di Mercato](00%20-%20Best%20Practice%20di%20Mercato%20(Multi-UoM%20e%20Pricing).md).
- Registro storico delle decisioni (perché, non solo cosa): [REVISIONE - Ridondanze, Contraddizioni e Piano di Riorganizzazione.md](REVISIONE%20-%20Ridondanze%2C%20Contraddizioni%20e%20Piano%20di%20Riorganizzazione.md), [PIANO - Motore di Pricing Multi-UoM.md](PIANO%20-%20Motore%20di%20Pricing%20Multi-UoM.md).
- Sviluppi rimandati deliberatamente: `docs/Backlog Sviluppi Futuri.md`.

## Gestione Listini dalla Scheda Prodotto

**IMPORTANTE (Best Practice ERP)**: Dalla scheda prodotto (Tab 'Prezzi di Vendita & Listini') NON è possibile creare nuovi listini commerciali (es. 'Listino B2B', 'Listino Rivenditori'). Questa è una scelta architetturale voluta e allineata agli standard ERP di fascia alta (es. NetSuite).
I listini sono entità globali del sistema e vanno creati nell'apposita sezione di configurazione. Una volta creati, appariranno automaticamente come righe all'interno della matrice prezzi di ogni singolo prodotto, dove l'utente potrà inserire il prezzo, gli sconti o gli scaglioni di quantità per quello specifico articolo. Il 'Prezzo Base' (Listino di sistema ID 1) funge da default per i clienti privi di listino specifico.
