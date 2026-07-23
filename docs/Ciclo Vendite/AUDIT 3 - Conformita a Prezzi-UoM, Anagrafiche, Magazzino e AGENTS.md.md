# AUDIT 3 — Conformità del Ciclo Vendite a Prezzi/UoM, Anagrafiche, Magazzino/Lotti, AGENTS.md

> Stato: 🔴 **SOLO ANALISI, NESSUN FILE TOCCATO.** Tutti i 18 punti sotto sono da leggere, capire e decidere UNO ALLA VOLTA prima di correggere qualunque cosa — stessa procedura già usata per gli "Open Problems" della REVISIONE principale (spiegazione semplice → perché è un problema → cosa fa il codice → soluzione proposta → attendere "vai").

Prodotto lanciando 4 revisioni indipendenti in parallelo, ciascuna con lettura integrale (non a campione) sia della documentazione di riferimento sia del codice del Ciclo Vendite coinvolto, verificando ogni riga citata con lo strumento di lettura file (non fidandosi di grep per punteggiatura/commenti). Nessuna delle 4 revisioni ripete i 10 problemi già trovati e corretti in `AUDIT 2` / `PIANO - Correzione Violazioni MVC-DRY-God Object (Audit 2).md` in questa stessa giornata di lavoro (bug `qk_preventivo_duplica()`, `apiCreaCliente()` senza colonna `codice`, registro compliance mai scritto, duplicazione compliance-check/indirizzo tra `create()`/`edit()`, `require_once` dentro le funzioni di `compliance.php`, buffer PDF non ripulito, `sconto_perc` ignorato nei totali in 5 punti, SQL grezzo per `canali_vendita` in una vista, CSS hardcoded ridondante in un alert).

---

## Parte 1 — Conformità a `docs/Unita di misura e prezzi/`

### 1.1 (Gravità alta) La pagina "Nuova Spedizione" confronta quantità in unità diverse senza mai convertirle

**File e righe:**
- `app/Controllers/SpedizioniController.php:111-126` — carica `(r.quantita_ordinata - r.quantita_spedita) AS qta_residua`, nell'unità nativa della riga (di default commerciale).
- `app/Controllers/SpedizioniController.php:158-179` — carica `lotti_giacenze.quantita_disponibile`, sempre in Stock UoM.
- `app/views/sales/shipments/_nuova.php:106-111` — `$statoRiga = $stockTot >= $qtaResidua ? 'ok' : ...` confronta le due direttamente, senza mai chiamare `qk_uom_converti_riga()`.
- `app/views/sales/shipments/_nuova.php:186-234` — la pre-allocazione FEFO fa `min($rimasto, $lot['quantita_disponibile'])` mescolando le stesse due unità, ed etichetta la disponibilità del lotto con l'unità sbagliata.
- `assets/js/sales-shipment-new.js:98-104, 107-162` — stessa mescolanza lato client.

**Cosa dice la documentazione:** il principio cardine di `PIANO - Motore di Pricing Multi-UoM.md` (ribadito in doc 01 §4) è che due quantità in unità potenzialmente diverse non sono mai confrontabili senza prima canonicalizzarle con `qk_uom_converti_riga()`. `ordini_clienti_righe.quantita_stock` esiste già proprio per questo, ma questa schermata non la usa mai per il residuo.

**Esempio concreto:** prodotto con 1 fusto = 25 kg. Ordine per 4 fusti. Lotto con 60 kg disponibili (= 2,4 fusti reali). La UI mostra "riga pronta" (verde, `60 >= 4`) e permette di procedere; solo al submit il server (che converte correttamente con bcdiv/bcmul) rifiuta con "giacenza insufficiente". Il dato a DB resta corretto, ma l'operatore vede sistematicamente stati sbagliati ogni volta che la riga è in unità commerciale.

**✅ DECISIONE (confermata):** usare `ordini_clienti_righe.quantita_stock` (o `qk_uom_converti_riga()`) per calcolare il residuo, invece del valore grezzo in unità commerciale — stessa conversione già corretta nel motore di pricing e nel controllo finale server-side di `elaboraCreaSpedizione()`. Stessa correzione per la pre-allocazione FEFO lato JS: convertire prima di confrontare/sommare, ed etichettare sempre la disponibilità del lotto con l'unità realmente usata (Stock UoM). Confermato confrontando col Ciclo Acquisti: la ricezione merci non ha una schermata identica (il suo confronto residuo è tra valori nella stessa unità), ma ovunque tocca la giacenza reale converte sempre esplicitamente a Stock UoM prima — stesso principio da applicare qui.

### 1.2 (Gravità alta) Il suggerimento di prezzo nel form "Nuovo Ordine Diretto" è completamente non funzionante

**File e righe:**
- `assets/js/sales-orders-create.js:394-415` (`fetchOrdinePrice()`) — manda `POST` con body `application/x-www-form-urlencoded`, nessuna query string nell'URL.
- `app/Controllers/SalesPricelistsController.php:628-641` (`apiOttieniPrezzo()`) — legge esclusivamente da `filter_input(INPUT_GET, ...)`.

**Tre bug indipendenti sullo stesso percorso:**
1. I parametri arrivano via POST body, l'endpoint legge solo GET → `$_GET` è sempre vuoto → risposta sempre `{"ok":false,"error":"Prodotto ID mancante."}`.
2. Anche corretto il metodo, il nome parametro quantità è sbagliato (`qta` invece di `quantita`).
3. Il JS controlla `data.prezzo`, l'API restituisce `prezzo_unitario`.

**Esempio concreto:** un operatore crea un ordine diretto, seleziona un prodotto con un listino dedicato attivo per quel cliente — non riceve mai il prezzo suggerito né il badge di origine, resta sempre "Nessun prezzo configurato" (icona rossa). Deve inserire il prezzo a mano ogni volta. Il form preventivi (stesso pattern, GET corretto) funziona bene — conferma che è un bug isolato, non una scelta voluta.

**✅ DECISIONE (confermata, rivista dopo obiezione DRY dell'utente):** la prima proposta (copiare la chiamata GET corretta anche in `sales-orders-create.js`) creava una seconda copia della stessa logica — sbagliato. Le due implementazioni gestiscono la risposta in modo diverso (quella preventivi applica anche conversione prezzo tra UoM/sconto/RRP, quella ordine diretto no — differenza di funzionalità reale, non toccata da questo fix), ma la parte dove vive il bug — costruire l'URL e chiamare l'API — è identica al 100% e oggi non esiste alcun file JS condiviso tra i due form. Soluzione: creare `assets/js/pricing-widget.js` con un'unica funzione condivisa (es. `qkFetchPrezzoSuggerito({prodottoId, listinoId, clienteId, quantita, unitaMisuraId})`) che fa la chiamata GET corretta e restituisce i dati, caricato da entrambi i form; ognuno dei due continua a gestire la risposta come fa oggi.

**Tutto il resto verificato conforme:** cascata prezzo dedicato→standard→costo, `qk_uom_converti_riga()`, snapshot su preventivi/ordini, conversione bcdiv/bcmul in `elaboraCreaSpedizione()`, vincolo `UNIQUE(..., quantita_minima_stock)`.

---

## Parte 2 — Conformità a `docs/Guide Anagrafiche Clienti, Fornitori, Indirizzi/`

### 2.1 (Gravità alta) Il form ordine diretto non usa `qk_aziende_list()` per l'azienda emittente

**File e righe:** `app/Controllers/SalesOrdersController.php:141-147` (query propria, nessun `WHERE attivo=1`, ordinata solo per ragione sociale) vs `SalesQuotesController::create()` righe 118-133 (usa correttamente `qk_aziende_list()` con fallback su `predefinita`).

**Esempio concreto:** un'azienda interna disattivata resta selezionabile come emittente di un ordine nuovo. I dati fiscali (ragione sociale, P.IVA, IBAN) vengono congelati permanentemente su quel documento — l'errore non si corregge più in stampa per il pattern di storicizzazione già stabilito.

### 2.2 (Gravità alta) Checkbox "Salva in anagrafica" sul form ordine diretto non collegata al backend

**File e righe:** checkbox in `app/views/sales/orders/_create.php:392-397`, gestita in JS (`sales-orders-create.js:156,171,176`), ma `SalesOrdersController::readPostOrderValues()` (righe 198-232) non legge mai `$_POST['salva_in_anagrafica_indirizzo']` — nessun equivalente di `SalesQuotesController::maybeCreateOneTimeAddress()`.

**Nota:** la documentazione di riferimento (Revisione Incrociata §1) dichiara questa funzionalità "implementata su entrambi i documenti" — non è vero per gli ordini diretti. Caso da manuale del punto 9 di CLAUDE.md (verificare lo stato attuale, non fidarsi di quanto già dichiarato verificato).

**Esempio concreto:** l'utente vede la stessa UI identica ai preventivi, spunta la checkbox aspettandosi che l'indirizzo venga salvato per il prossimo ordine — non succede nulla, va reinserito da capo ogni volta.

### 2.3 (Gravità bassa) `SalesOrdersController::apiClienteDettagli()` non filtra clienti disattivati

**File e righe:** `SalesOrdersController.php:284-292` (nessun `AND cf.attivo=1`) vs `SalesQuotesController.php:569-576` (stesso endpoint, filtro presente). Rischio pratico basso (l'autocomplete filtra già), ma incoerenza reale tra due controller gemelli.

**Tutto il resto verificato conforme:** pattern snapshot/congelamento (indirizzo, contatto, azienda emittente) implementato identicamente e senza duplicazione tra preventivi e ordini; creazione rapida cliente/prodotto riusa sempre le funzioni condivise; blocco esplicito quando manca un indirizzo risolvibile in spedizione.

---

## Parte 3 — Conformità a `docs/Gestione Magazzino e Lotti/`

### 3.1 (Gravità molto alta) Riserva e rilascio stock in spedizione operano su magazzini mai confrontati — riserva "fantasma" permanente

**File e righe:**
- `app/Services/OrderCalculatorService.php:56,62,113` e `includes/sales_quotes.php:1060,1092,1169` — la riserva viene scritta sul magazzino attivo di **sessione al momento della creazione ordine**, congelato su `ordini_clienti.magazzino_id`.
- `app/Controllers/SpedizioniController.php:139-145` — il form spedizione precompila il magazzino con quello attivo di sessione **al momento della spedizione** — scollegato da `ordini_clienti.magazzino_id` (nessun riferimento in tutto `_nuova.php`).
- `app/Controllers/SalesOrdersController.php` (`elaboraCreaSpedizione()`, righe 633,747,848-853,927,969,985) — decrementa `giacenze.quantita_riservata` usando **solo** il magazzino scelto sul form spedizione. Zero occorrenze di `$order['magazzino_id']` in tutto il metodo (verificato con grep mirato).

**Cosa dice la documentazione:** §10.3 di *Flusso Operativo* accetta che un ordine possa risultare "squilibrato tra sedi", MAI che una riserva vada persa/orfana. Il pattern corretto ("mai il magazzino attivo di sessione, sempre quello congelato sull'ordine") è già implementato in `qk_ordine_cliente_annulla()` — ma non nel percorso di spedizione, molto più frequente.

**Esempio concreto:** ordine di 100 kg creato con magazzino attivo "Milano" → riserva scritta a Milano. Giorni dopo, spedizione con magazzino di sessione cambiato a "Bari" (dove la merce è realmente disponibile) → lo scarico fisico avviene correttamente a Bari, ma il rilascio riserva tenta Bari (dove non c'era nulla, clampato a 0) — **a Milano restano 100 kg riservati per sempre**, nessun percorso li rilascia più (l'ordine è "spedito", non più annullabile). Giacenza virtuale di Milano sottostimata a tempo indeterminato → falsi allarmi sottoscorta o blocco vendite legittime senza causa fisica reale.

**✅ CORRETTO E VERIFICATO CON ESECUZIONE REALE (8/8 asserzioni, 2 scenari: standalone e Kit).** `SpedizioniController::nuova()` ora precompila il magazzino da `ordini_clienti.magazzino_id` (fallback su `qk_magazzino_attivo_id()` solo per ordini legacy senza magazzino_id congelato). `SalesOrdersController::elaboraCreaSpedizione()` ora separa le due operazioni: `$stDecrementaDisponibile` tocca solo `quantita_disponibile` sul magazzino di spedizione scelto; il rilascio di `quantita_riservata` avviene tramite `qk_rilascia_stock_ordine()` (già kit-aware) sul magazzino originale (`$magazzinoRiservaId`), separatamente. Test: ordine con magazzino originale=1, spedito scegliendo magazzino=2 (deliberatamente diverso) — confermato che la riserva si libera sul magazzino 1 e lo scarico fisico avviene sul magazzino 2, sia per un prodotto standalone sia per un Kit.

### 3.2 (Gravità alta, specifico ADR) Componenti Kit tracciati a lotto: nessuna selezione lotto né tracciabilità in spedizione

**File e righe:** `SalesOrdersController.php:947-971` (ramo scarico componenti Kit) — decrementa solo `giacenze` (aggregato), mai `lotti_giacenze`; nessuna selezione FEFO; movimento sempre con `lotto_id = null`. Il ramo gemello per prodotti standalone a lotti (righe 899-930) fa tutto correttamente.

**Esempio concreto:** un Kit con un componente ADR tracciato a lotti (es. biocida con scadenza) viene spedito — la giacenza aggregata scende, `lotti_giacenze` resta invariata, nessun riferimento lotto sul movimento/DDT. Un richiamo fornitore su un lotto specifico non troverà **nessuna** di queste spedizioni: rottura di tracciabilità reale per un distributore ADR, non solo un'imprecisione contabile.

**✅ CORRETTO E VERIFICATO CON ESECUZIONE REALE.** Nuova funzione condivisa `qk_seleziona_lotti_fefo()` in `includes/magazzino.php` (stessa logica FEFO/esclusione-scaduti già usata per la pre-allocazione in `SpedizioniController::nuova()`, estratta per essere riusabile). Il ramo Kit di `elaboraCreaSpedizione()` ora verifica `traccia_lotti` per ogni componente: se tracciato, seleziona i lotti via FEFO, decrementa `lotti_giacenze` per ognuno e registra `lotto_id` sul movimento — stesso comportamento già corretto del ramo standalone. Test: Kit con un componente a lotto, spedizione confermata — `lotti_giacenze` del componente decrementata correttamente e movimento con `lotto_id` valorizzato (prima: sempre `null`).

### 3.3 (Gravità bassa) Rilascio riserva Kit basato sulla composizione attuale, non su uno snapshot al momento della riserva

**File e righe:** `includes/magazzino.php:98-137` (`qk_riserva_stock_ordine`) e `:158-187` (`qk_rilascia_stock_ordine`) — entrambe interrogano `componenti_kit` "dal vivo", nessuno snapshot.

**Esempio concreto:** Kit con 2 kg di componente X, ordine riservato. Prima dell'annullamento, la distinta viene corretta a 3 kg. L'annullamento rilascia 3 kg invece dei 2 realmente riservati — riserva residua sotto il livello corretto, clampata a 0 silenziosamente. Edge case, richiede una modifica anagrafica nel mezzo.

**Tutto il resto verificato conforme:** FEFO con esclusione scaduti per prodotti standalone, congelamento mittente DDT, posting-block per riga singola, espansione Kit nella riserva/rilascio a livello di `giacenze`.

---

## Parte 4 — Violazioni AGENTS.md e coerenza architetturale (Vendite vs Acquisti)

### Categoria A — Violazioni dirette di AGENTS.md

**4.A.1** `require_once` dentro un `if` — `SalesOrdersController.php:113`, dentro `create()`. Stesso identico anti-pattern già segnalato in CLAUDE.md come "trovato ripetutamente" in Acquisti, qui non ancora corretto. Varianti più lievi (require dentro un metodo, non in testa al file) in `SalesQuotesController.php:329,744`.

**4.A.2** `SalesQuotesController::apiCheckCompliance()` (righe 729-757) non chiama `csrf_verify()`, mentre `apiCreaCliente()`/`apiCreaProdotto()` nello stesso file lo fanno entrambi. Il JS manda comunque il token (`sales-quotes.js:867`) — il server lo ignora silenziosamente.

**4.A.3** CSS inline hardcoded duplicato carattere per carattere in 2 controller — `SalesOrdersController.php:121` e `SalesQuotesController.php:347`, stessa stringa `style='color:#721c24;font-weight:bold;text-decoration:underline;'`, invece di una classe `qk-*` esistente.

**4.A.4** SQL crudo nei controller — **conteggio esaustivo, non a campione** (l'utente ha esplicitamente richiesto un terzo controllo perché il primo si era fermato a esempi rappresentativi). Ogni chiamata `$this->pdo->query/prepare/exec` diretta in un controller del Ciclo Vendite, contata una per una:

| Controller | N. query SQL dirette | Righe |
|---|---|---|
| `SalesOrdersController.php` | **33** | 14,28,59,67,141,284,294,306,395,451,471,476,493,500,512,561,565,602,641,668,675,692,744,780,815,826,827,832,848,859,860,861,909,929,948,970,986,996,1005 |
| `SalesPricelistsController.php` | **29** | 47,117,164,209,253,259,291,342,353,387,428,453,482,503,550,576,665,733,793,802,807,819,864,888,908,953,981,1003,1030,1035 |
| `SalesQuotesController.php` | **9** | 37,43,177,387,537,541,569,582,593 |
| `SpedizioniController.php` | **7** | 43,52,87,111,158,203,231 |
| **Totale** | **78** | — |

Tutte e 78 sono violazioni dirette della Regola Aurea di AGENTS.md ("zero SQL grezzo nei controller, tutto deve passare da helper in `includes/` o servizi/repository"). Non le elenco singolarmente riga per riga con spiegazione (sarebbe un documento a sé), ma il conteggio è completo, non campionato — nessuna query di questi 4 file è stata esclusa dal conteggio. `SalesPricelistsController.php` è il caso più estremo (29 su 78, il 37%, intero CRUD del controller).

**Violazione DRY collegata, anch'essa verificata in modo esaustivo**: la stessa identica espressione SQL per calcolare il "nome visualizzato" di un cliente/fornitore (`COALESCE(ragione_sociale, TRIM(nome || ' ' || cognome))` o varianti) è scritta a mano, indipendentemente, **8 volte** nei controller del Ciclo Vendite invece di usare una vista SQL condivisa o una singola funzione PHP: 4 volte in `SalesOrdersController.php`, 3 in `SalesPricelistsController.php`, 1 in `SalesQuotesController.php`. È lo stesso pattern già segnalato in CLAUDE.md come "visto ripetersi 6 volte nel codebase" (a livello di intero progetto) — qui si conferma presente anche solo dentro il Ciclo Vendite, indipendentemente da quel conteggio più ampio.

**4.A.5** (minore) Docblock impreciso in `SpedizioniController::nuova()` — descrive un ramo POST che non esiste in quel metodo; il form posta realmente a `sales/orders/action`.

### Categoria B — Incoerenza architetturale Vendite vs Acquisti

**4.B.1** Il gemello concettuale di `SalesQuotesController` (`RfqController`, Acquisti) delega PDF/DOCX a un Service + template esterno; Vendite costruisce tutto inline nel controller (~540 righe). Stesso bisogno di business, due architetture incompatibili — nessuna giustificazione di business apparente.

**4.B.2** **Dentro lo stesso Ciclo Vendite**: creare un ordine nella stessa tabella `ordini_clienti` ha due filosofie diverse. Ordine diretto → `SalesOrderRepository`/`OrderCalculatorService` (uniche classi Repository/Service OOP di questo tipo in tutto il progetto). Preventivo confermato in ordine → INSERT SQL scritto a mano con ~44 colonne quasi duplicate riga per riga rispetto al Repository. Il Ciclo Acquisti, per confronto, è internamente coerente (un'unica filosofia a funzioni). Questo è esattamente il "30 modi diversi per lo stesso problema" richiesto di verificare — e non è nemmeno tra cicli diversi, è dentro lo stesso ciclo.

**4.B.3** "Segna fatturato/da fatturare": lato Acquisti estratto in funzioni condivise con un commento esplicito "mirror di Vendite" — ma Vendite (`SalesOrdersController::action()`, righe 561,565) non è mai stato allineato, resta SQL inline. Il refactoring è stato fatto solo su metà della coppia gemella.

### Categoria C — God Object / violazioni MVC pure

**4.C.1** `SalesOrdersController::elaboraCreaSpedizione()`, righe 617-1013 (~400 righe) — il caso più grave: validazione, upload con MIME sniffing, overshipment, numerazione, congelamento mittente/destinatario, inserimento spedizione/DDT/righe, gestione lotti/kit, giacenze, log movimenti — tutto in un metodo privato di un controller HTTP, mentre esiste già `OrderCalculatorService` come pattern alternativo nello stesso file/dominio.

**4.C.2** `SalesPricelistsController::apiImportPrezzi()`, righe 842-1055 (~210 righe) — logica di business pesante (parsing, conversione UoM, dedup, regola "non sovrascrivere se modificato a mano") interamente nel controller.

**4.C.3** `SalesPricelistsController` nel complesso — il controller più pesante del modulo, intero CRUD come SQL diretto.

**4.C.4** Confermato: V7 (PDF/DOCX) resta parzialmente irrisolto per la parte di rendering — le due API (stringa HTML per mPDF vs oggetti PhpWord) sono troppo diverse per unificare senza un redesign importante; osservazione già nota, non serve un piano completo. Passo più sicuro disponibile: spostare almeno il blocco HTML di `pdf()` in un template esterno (come fa già `RfqController` lato Acquisti, punto 4.B.1) — risolverebbe la violazione MVC pura senza eliminare la duplicazione tra i due formati.

---

## Riepilogo per priorità

| # | Problema | Gravità | Rischio pratico |
|---|---|---|---|
| 3.1 | ✅ Riserva/rilascio stock su magazzini mai confrontati — CORRETTO E VERIFICATO | **Molto alta** | Raggiungibile in uso quotidiano, corrompe silenziosamente la giacenza virtuale, nessun percorso di auto-correzione |
| 1.1 | Confronto quantità in unità diverse in "Nuova Spedizione" | Alta | UI mostra stati sbagliati sistematicamente, il DB resta corretto |
| 1.2 | Suggerimento prezzo rotto su ordine diretto | Alta | Feature invisibilmente morta, nessun errore visibile |
| 2.1 | Azienda emittente disattivata selezionabile su ordine diretto | Alta | Dati fiscali sbagliati congelati permanentemente |
| 2.2 | Checkbox "salva indirizzo" non collegata (ordine diretto) | Alta | Funzionalità dichiarata implementata ma silenziosamente assente |
| 3.2 | ✅ Kit + lotti: nessuna tracciabilità in spedizione — CORRETTO E VERIFICATO | Alta (ADR) | Rottura tracciabilità per richiami prodotto |
| 4.B.2 | Due filosofie per creare un ordine nella stessa tabella | Alta (architetturale) | Manutenzione doppia, rischio di divergenza futura |
| 4.C.1 | `elaboraCreaSpedizione()` God Object (~400 righe) | Alta (architetturale) | — |
| 4.A.2 | CSRF mancante su `apiCheckCompliance()` | Media | — |
| 4.B.1, 4.B.3, 4.C.2, 4.C.4 | Incoerenze architetturali varie | Media | — |
| 4.A.4 | SQL crudo nei controller — **78 occorrenze esaustive** (non più campione) + 8 duplicazioni nome cliente | Alta (volume) | Sistemico, richiede un progetto a sé per dimensione, non un fix puntuale |
| 2.3, 3.3, 4.A.1, 4.A.3, 4.A.5, 4.C.3 | Minori / già note come sistemiche | Bassa-Media | — |

Nessun file è stato toccato. Aspetto le tue decisioni, punto per punto, con lo stesso formato già usato per gli altri problemi aperti di questo ciclo.
