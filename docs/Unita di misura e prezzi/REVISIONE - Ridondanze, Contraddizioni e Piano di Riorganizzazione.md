# Revisione della cartella "Unità di misura e prezzi"

Analisi riga per riga dei 4 file esistenti, con verifica di ogni punto controverso contro lo stato reale del codice sorgente (schema `db/init_mysql.sql`, `includes/prodotti.php`, `includes/sales_quotes.php`, `app/Controllers/ProductsController.php`, `app/Controllers/SalesPricelistsController.php`, `app/Controllers/PurchasesController.php`, `assets/js/app.js`, `assets/js/sales-quotes.js`, `assets/js/rfq-form.js`). Per ogni punto controverso viene espresso un giudizio esplicito se il comportamento del codice attuale è una scelta professionale/best practice **e perché**, non solo quale testo gli sia più vicino.

File analizzati:
1. `Architettura, Analisi e Best Practice Multi-UoM e Listini Prezzi - Versione Definitiva.md` (719 righe — in realtà 3 documenti incollati + un log decisioni)
2. `Listini nella formulazione offerte.md` (159 righe)
3. `listini_architettura.md` (64 righe)
4. `UI_UX_PREZZI.md` (103 righe)

---

## 0. Premessa: il file "Versione Definitiva" non è un documento unico

Il file 1 dichiara nel proprio titolo di essere la "versione definitiva", ma al suo interno è la concatenazione letterale di **tre documenti di natura diversa** (righe 15, 452, 560) più un **log decisioni** (riga 399):

- **PARTE 1** (righe 15–397): ricerca comparativa generica su SAP/Oracle/Dynamics, con schema SQL e codice PHP **di ispirazione**, non allineati alla nomenclatura reale di Quack (tabelle in inglese: `uom_registry`, `products`, `price_lists`; classe `OrderProcessor`).
- **PARTE 2** (righe 452–558): gap-analysis Quack-specifica con piano di correzione (nomi tabella reali italiani).
- **PARTE 3** (righe 560–720): architettura di riferimento Quack-specifica, che in parte ridice PARTE 2 con formule leggermente diverse.
- **Sezione 8** (righe 399–449), incollata *dentro* PARTE 1 ma cronologicamente più recente: log decisioni che corregge/precisa alcuni punti di PARTE 1.

Questo è il problema strutturale principale da cui derivano quasi tutte le contraddizioni: **la "verità finale" (Sezione 8, Giugno 2026) è annegata in mezzo a materiale di ricerca generico e a due successive riscritture (PARTE 2 e PARTE 3) che a loro volta non sempre concordano tra loro.** Un lettore che procede in ordine lineare incontra 3-4 versioni progressivamente più precise dello stesso concetto prima di arrivare a quella corretta.

---

## 1. Stato reale del codice (fonte di verità usata per risolvere le contraddizioni)

| Elemento | Stato reale | Evidenza |
|---|---|---|
| `listini_prezzi.unita_misura_id` | **Esiste già** | `db/init_mysql.sql:662-672` |
| `prodotti_fornitori_prezzi.unita_misura_id` | **Esiste già** | `db/init_mysql.sql:599-610` |
| `prodotti_fornitori.rrp_min/rrp_max/rrp_unita_misura_id` | **Esistono già** | `db/init_mysql.sql:568-586` |
| `prodotti.unita_misura_stock_id / unita_contenuto_id / quantita_contenuto / num_factor / den_factor / markup_fallback_perc` | **Esistono già** | `db/init_mysql.sql:473-511` |
| Tabella N-a-N `product_uom_conversions` (Approccio 2, multi-UoM commerciale) | **Non esiste**, non implementata | assente da tutti gli schema; unico riferimento è nei documenti stessi |
| Tabella `giacenze` (saldo corrente) | **Distinta** da `movimenti_magazzino` (storico): `quantita_disponibile`, `quantita_riservata`, `quantita_in_arrivo`, `scorta_minima` | `db/init_mysql.sql:705-713` |
| Colonna "Catch Weight" / doppia misura (pezzi + peso reale) su `movimenti_magazzino` | **Non esiste**, nessuna colonna `peso_reale`/`peso_effettivo` | assente da `db/init_mysql.sql` |
| Righe documento (`preventivi_righe`, `ordini_clienti_righe`, `rfq_righe`, `ordini_righe`) — snapshot `quantita_stock`, `system_unit_price_stock`, `unita_misura_stock_id`, `quantita_contenuto_snapshot`, `num_factor_snapshot`, `den_factor_snapshot` | **Esistono già, su tutte e 4 le tabelle, simmetricamente** | `db/init_mysql.sql:850-876, 896-914, 1403-1427, 1547-1573` |
| Blocco cambio Stock UoM dopo movimenti | **Implementato a due livelli** (form + update), basato **solo** su `COUNT(*) movimenti_magazzino > 0`, **senza mai leggere `giacenze`** | `includes/prodotti.php:687-709`, `app/Controllers/ProductsController.php:1024-1050` |
| Formula di conversione reale | `quantita_contenuto=25` → `num_factor=1, den_factor=25`; `Q_stock = Q_comm / quantita_contenuto`; `P_stock = P_comm × (den_factor/num_factor)` | `includes/prodotti.php:574-598` (calcolo num/den), `includes/prodotti.php:984-992` (funzione `qk_uom_converti_riga`) |
| Validazione max 3 decimali su `quantita_contenuto` | **Implementata** | `app/Controllers/ProductsController.php:1053-1057` |
| Markup fallback pricing (vendite) | **Risoluzione dinamica a runtime** (non copiata alla creazione prodotto), 2 livelli reali: `prodotti.markup_fallback_perc` → `Impostazioni.markup_fallback_default` → solo se entrambi assenti, `20.0` hardcoded. **Nessun livello "categoria"** | `SalesPricelistsController.php:752, 760-778`; grep `categoria` nel controller: 0 risultati |
| Conversione UoM del prezzo nel motore di pricing vendite | **Il backend NON converte**: restituisce prezzo + `unita_misura_id` nativi del listino, la conversione "di anteprima" è delegata al frontend; la conversione **autoritativa** avviene comunque server-side al salvataggio (`qk_uom_converti_riga`) | `SalesPricelistsController.php:851-852` (commento esplicito), `assets/js/sales-quotes.js:1328-1335` (`fetchQuotePrice`), `includes/prodotti.php:956-997` (ricalcolo al salvataggio) |
| Pricing engine Acquisti/RFQ | **Esiste**, motore a scaglioni reale su `prodotti_fornitori_prezzi` (`quantita_minima`, `ORDER BY ... DESC LIMIT 1`) | `app/Controllers/PurchasesController.php:335-444` (`getPurchasePrice`), endpoint `PurchasesController::apiOttieniPrezzo:449-498` |
| Conversione UoM nello scaglione d'acquisto | Lato JS (`qkConvertUom`), non in PHP | `assets/js/rfq-form.js:10,43,232-238` |
| `qkConvertUom` | Esiste, gestisce `qFactor<=0` o `=1` come "nessuna conversione", arrotonda quantità a 3 decimali e prezzo a 4 | `assets/js/app.js:368-397` |
| Badge origine prezzo (`dedicato/standard/costo/manuale`) | Implementato backend (campo `origine`) + frontend (icone) | `SalesPricelistsController.php:613,665,718,790`; `assets/js/sales-quotes.js:1361-1375` |
| Badge in RFQ | Elemento `.price-origin-badge` **presente** nella vista | `app/views/purchases/rfq/_form.php:296` — stato di popolamento effettivo da riverificare a parte |
| `rfq_righe.sconto_perc` | **Non esiste** (confermato, coerente con i documenti) | assente da `db/init_mysql.sql:1403-1427`; presente invece in `offerte_righe` e `ordini_righe` |
| Validità per singola riga su `listini_prezzi` | **Non esiste** (né MySQL né SQLite) — per decisione di progetto, la validità vive solo sulla testata `listini`. Diverso dal lato Acquisti, dove `prodotti_fornitori_prezzi` **ha** `valido_dal`/`valido_al` correttamente | `db/init_mysql.sql:662-673`, `db/init_sqlite.sql:805-816` vs `PurchasesController.php:384-385` |
| Bug (✅ **corretto** il 10/07/2026): query in `SalesPricelistsController::getSalePrice()` filtravano `listini_prezzi` per `valido_dal`/`valido_al` inesistenti, causando un errore SQL che saltava l'intera cascata di fallback pricing | Corretto: condizioni rimosse dalle 3 query interessate | `SalesPricelistsController.php`, ex-righe ~580, ~629, ~682 — dettaglio in §6.10 |
| Vincolo `UNIQUE (listino_id, prodotto_id, quantita_minima, unita_misura_id)` su `listini_prezzi` | Presente in SQLite, **mancante in MySQL** — disallineamento tra schemi gemelli. ✅ **Corretto**: aggiunto a `db/init_mysql.sql` (richiede migrazione separata su DB già esistenti) | `db/init_sqlite.sql:815` vs `db/init_mysql.sql:662-673` (dopo correzione) |

---

## 2. Concetti e testi ripetuti

| # | Contenuto ripetuto | Dove | Nota |
|---|---|---|---|
| R1 | I "pilastri architetturali" (immutabilità transazionale, Base UoM, prezzi in UoM nativa) | File1 PARTE1 §1 (righe 21-39) **e** PARTE3 §1 (righe 564-596) | Stesso concetto, due formulazioni quasi identiche a 500 righe di distanza |
| R2 | Esempio numerico "Solvente Alpha / Fusto 25kg / 100kg ordinati / 10€ kg → 250€ fusto" | File1 PARTE1 §7 (righe 325-386) **e** PARTE3 §2 (righe 616-622) | Stessi numeri, notazione matematica diversa (num/den vs F_conv) — vedi §3 C1 |
| R3 | Matrice dei casi limite (cambio Base UoM, UoM commerciale mancante, eliminazione UoM, cambio fattore) | File1 PARTE1 §3 (tabella, righe 63-70), PARTE2 §2 (tabella, righe 469-476), PARTE3 §3 (tabella, righe 626-632) | **Tre matrici distinte** che coprono in gran parte lo stesso terreno con dettaglio e fraseggio diversi — la ridondanza più pesante del corpus |
| R4 | "Il backend ricalcola sempre con BCMath, il frontend è solo visivo" | File1: PARTE1 §6B (codice), edge case F di PARTE2 (riga 476), PARTE3 §4B punto 2 (righe 693-706) | Stesso principio ripetuto 3 volte in un solo file |
| R5 | Schema SQL "generico" (`uom_registry`, `products`, `price_lists`, inglese) | File1 PARTE1 §4 (righe 72-148) | Ridondante e disallineato rispetto allo schema reale Quack presentato poco dopo in PARTE2 §3A e PARTE3 §4A (nomi italiani) — rischia di essere scambiato per la specifica reale (vedi §3 C8) |
| R6 | Codice JS `UomConverter` + PHP `OrderProcessor.php` generici | File1 PARTE1 §6A-B (righe 194-323) | ✅ **Risolto**: non solo non corrispondeva al codice reale nei nomi, ma divergeva su 3 comportamenti concreti (arrotondamento, gestione errori, architettura) — vedi §3 C6. Cancellato, non spostato, e sostituito con un rimando a `qk_uom_converti_riga()` / `qkConvertUom()` |
| R7 | Tutela dell'RRP per-fornitore (non sul solo fornitore preferito) | File1 Sezione 8C/PARTE3 edge case 5 (riga 632) + File4 §3 (righe 73-75) | Concetto coerente ma esposto due volte senza rimando incrociato |
| R8 | Descrizione delle "due viste" (globale/listino vs scheda prodotto) che scrivono le stesse tabelle | File3 `listini_architettura.md` §2-3 (righe 17-47) **e** apertura di File4 `UI_UX_PREZZI.md` (righe 15-17, 38-40) | File4 ri-argomenta da zero perché "non esiste un listino d'acquisto globale predefinito", concetto già coperto da File3 §3 Acquisti |

**Raccomandazione**: le ripetizioni R1, R2, R4 vanno eliminate tenendo una sola formulazione (quella di Sezione 8/PARTE3, la più aggiornata). R3 va fusa in un'unica matrice (vedi §5). R5 (schema SQL generico) va spostato in un contenitore "materiale di ispirazione" chiaramente etichettato come non normativo (vedi §5) — **a differenza di R6, per lo schema SQL generico non è stata trovata alcuna divergenza comportamentale grave, solo disallineamento di nomi, quindi lì lo spostamento resta la soluzione corretta invece della cancellazione**. R6 è **già risolto** (cancellato, vedi sopra). R7/R8 vanno collegate con un rimando invece di essere riscritte.

---

## 3. Contraddizioni verificate contro il codice, con giudizio di best practice

Per ciascun punto: cosa dicono i testi discordanti → cosa fa davvero il codice → **il codice è una scelta professionale/best practice? Perché?** → a quale testo si avvicina di più.

**Nota sullo stato**: C1, C2, C3, C4, C5/C9, C6, C8 e C10 sono contrassegnati **✅ RISOLTO** perché la correzione è già stata applicata direttamente nei 4 file originali (non solo qui). Il testo di analisi sotto ciascuno di essi resta comunque per intero, perché documenta *cosa* era sbagliato e *perché* — utile se in futuro serve capire la motivazione della correzione. **C7 è l'unico punto ancora aperto tra le 10 contraddizioni**: richiede una verifica end-to-end nel browser (aprire una RFQ reale) prima di poter essere corretto con cognizione di causa, cosa che non ho ancora fatto.

### C1 — Formula di conversione num/den: un refuso realmente presente

> ✅ **RISOLTO** — corretto direttamente nel file `Architettura, Analisi e Best Practice... Versione Definitiva.md`, Sezione 8D. Il testo sotto resta come traccia di cosa era sbagliato e perché.

- **PARTE1 §5** (righe 158-172) e **Sezione 8A** (righe 405-410, esempio esplicito `25 → num=1, den=25`): usano `num_factor=1, den_factor=25` per un contenuto di 25.
- **Sezione 8D** (riga 433): riporta l'esempio **invertito** — *"1 fusto = 25 kg → num=25, den=1"* — ripetuto anche nell'esempio Approccio 2 (riga 443).
- **PARTE3 §2** (righe 598-622): abbandona la coppia num/den e usa un fattore singolo `F_conv=25`, con `Q_stock = Q_comm / F_conv`, `P_stock = P_comm × F_conv`.

**Codice reale** (`includes/prodotti.php:574-598, 984-992`): `num_factor=1, den_factor=25` per contenuto 25; `Q_stock = Q_comm / quantita_contenuto`; `P_stock = P_comm × (den_factor/num_factor)`. Algebricamente `den/num = 25/1 = quantita_contenuto`, quindi PARTE1§5/Sezione 8A e PARTE3§2 sono **matematicamente equivalenti fra loro** (solo notazione diversa), mentre Sezione 8D è l'unica realmente **errata**.

**Il codice è best practice? Sì, e per un motivo preciso**: memorizzare una frazione intera (numeratore/denominatore) invece di un solo float replica esattamente la tecnica della tabella `MARM` di SAP citata dallo stesso documento (PARTE1 §2) — evita che una conversione come `1/3` degeneri in `0.333333...` con conseguente deriva cumulativa sugli arrotondamenti quando si eseguono migliaia di movimenti. È lo standard enterprise riconosciuto, non un'invenzione locale.

**Testo più vicino al codice**: PARTE1 §5 e Sezione 8A. **Verdetto**: correggere il refuso di Sezione 8D (righe 433, 443) e aggiungere una riga che spieghi l'equivalenza numerica con la notazione F_conv di PARTE3, per evitare che sembrino due sistemi diversi.

### C2 — `unita_misura_id` su `listini_prezzi`: la migrazione è già stata fatta

> ✅ **RISOLTO** — corretto direttamente in `Versione Definitiva.md` (PARTE2 §3A e PARTE3 §4A ora marcate come già eseguite) e in `UI_UX_PREZZI.md` (schema SQL obsoleto corretto). Il testo sotto resta come traccia di cosa era sbagliato e perché.

- **File2** (riga 12): descrive correttamente lo stato attuale — la colonna esiste.
- **File1 PARTE2 §3A** (riga 484) e **PARTE3 §4A** (righe 638-646, con script `ALTER TABLE` e migrazione dati): la trattano come da aggiungere.
- **File4** (righe 91-100): schema di `listini_prezzi` dichiarato "basato sul database reale", ma incompleto/obsoleto (manca `unita_misura_id`, `sconto_perc`, `note`, `created_at`; nome colonna sbagliato `prezzo` invece di `prezzo_unitario`).

**Codice reale**: `db/init_mysql.sql:662-672` conferma la colonna già presente.

**Il codice è best practice? Sì**: salvare la UoM nativa accanto al prezzo è esattamente il principio "Native UoM Pricing" che gli stessi documenti raccomandano in teoria (PARTE1 §1C) — qui la teoria è già realtà nel DB, non solo sulla carta.

**Testo più vicino al codice**: File2. **Verdetto**: PARTE2/PARTE3 di File1 descrivono un piano già eseguito, vanno segnati come completati; lo snippet SQL di File4 va corretto o rimosso perché sembra autorevole ma non lo è più.

### C3 — Chi converte il prezzo tra UoM nel motore di pricing vendite?

> ✅ **RISOLTO** — corretto direttamente in `Versione Definitiva.md`, PARTE2 §3B punto 3 ("Determinazione del Prezzo da Listino"), riscritto per descrivere l'architettura reale a due fasi invece della conversione lato server mai adottata. Il testo sotto resta come traccia di cosa era sbagliato e perché.

**Risposta netta: SÌ, il comportamento del codice reale è best practice.** Non "sì con riserve" — è la scelta corretta, per il motivo tecnico spiegato sotto.

- **File2 §1B** (riga 30): il backend **non** converte, restituisce il record di listino così com'è e delega al frontend.
- **File1 PARTE2 §3B punto 3** (righe 509-515): descrive un algoritmo dove è il **backend** a convertire matematicamente il prezzo.

**Cosa fa davvero il codice** (per chi non è tecnico: due momenti diversi, due responsabilità diverse):

1. **Momento 1 — "suggerimento" mentre l'utente sta ancora compilando la riga d'ordine**: quando l'utente sceglie un prodotto e una quantità, il sistema chiama un endpoint (`SalesPricelistsController.php`) che cerca il prezzo di listino e lo restituisce così com'è, insieme alla UoM a cui quel prezzo si riferisce nativamente — senza convertirlo nella UoM che l'utente ha scelto in quel momento sulla riga. È il browser (JavaScript, file `assets/js/sales-quotes.js`) a fare i conti per mostrare a schermo il prezzo convertito nell'unità giusta. Il commento nel codice sorgente lo dice esplicitamente: *"Restituiamo la UoM nativa... così il frontend sa se convertire o meno"* (`SalesPricelistsController.php:851-852`).
2. **Momento 2 — salvataggio effettivo del documento**: quando l'utente conferma e il preventivo/ordine viene davvero scritto nel database, il server rifà da capo tutti i calcoli in PHP, usando la funzione `qk_uom_converti_riga()`, e ignora completamente qualsiasi numero che il browser aveva mostrato a video. Il valore che finisce nel database è quindi sempre calcolato e verificato dal server, mai preso per buono da ciò che ha inviato il browser.

**Il codice è best practice? Perché?** Sì. Il "suggerimento prezzo" del Momento 1 serve solo a mostrare subito qualcosa a video mentre l'utente lavora — è un'anteprima, non un dato che verrà mai salvato così com'è. Farlo calcolare al server invece che al browser non aggiungerebbe alcuna sicurezza in più (tanto verrà ricalcolato comunque al salvataggio), aggiungerebbe solo un giro di rete inutile e renderebbe l'interfaccia meno reattiva. Il principio di sicurezza da rispettare in un ERP — "non fidarsi mai di un calcolo fatto solo nel browser quando si scrive nel database" — è già rispettato, perché il Momento 2 (quello che conta davvero, ai fini contabili) è interamente responsabilità del server e ignora il browser. Sarebbe stato un problema solo se il salvataggio si fosse fidato ciecamente del numero mostrato a video — e non è questo il caso.

**Testo più vicino al codice**: File2. **Verdetto/azione**: il punto 3 di PARTE2 §3B descrive una proposta (conversione lato server anche nel Momento 1) mai adottata e non necessaria — va riscritta per riflettere l'architettura reale in due fasi appena descritta.

### C4 — L'asimmetria Vendite/Acquisti descritta in File2 è superata

> ✅ **RISOLTO** — corretto direttamente in `Listini nella formulazione offerte.md`, paragrafo "Flusso Acquisti". Il testo sotto resta come traccia di cosa era sbagliato e perché.

- **File2 §1B "Flusso Acquisti"** (righe 32-34): *"Assenza di Logica di Pricing... il backend degli acquisti non ha alcun Pricing Engine"*.

**Codice reale**: `PurchasesController::getPurchasePrice` (righe 335-444) implementa un motore a scaglioni reale su `prodotti_fornitori_prezzi`. Le tabelle `rfq_righe` e `ordini_righe` hanno lo stesso identico set di colonne snapshot delle tabelle vendita.

**Il codice è best practice? Sì**: mantenere gli stessi campi di snapshot e la stessa logica di ricerca a scaglioni su entrambi i cicli (vendite/acquisti) riduce il rischio classico di "logica duplicata che diverge nel tempo" tra due processi concettualmente speculari — è più facile mantenere un solo pattern che due.

**Testo più vicino al codice**: nessuno dei due — File2 è obsoleto e va aggiornato; File1 non discute mai esplicitamente questa asimmetria (la ignora, trattando i due cicli come sempre simmetrici, il che si è rivelato corretto solo *a consuntivo*). **Verdetto**: riscrivere §1B di File2. Uniche differenze reali rimaste: RFQ senza `sconto_perc` (corretto, vedi C-RFQ sotto), conversione UoM dello scaglione lato JS anche in acquisti.

### C5 / C9 — Markup fallback: doppia contraddizione, una interna allo stesso file

> ✅ **RISOLTO** — corretto direttamente in `Listini nella formulazione offerte.md`, sia il §1B (riga 31, "+20% fisso") sia il §4B (righe 128-138, contraddizione statico/dinamico e livello "categoria" inesistente). Il testo sotto resta come traccia di cosa era sbagliato e perché.

- **File2 §1B** (riga 31): stato AS-IS = "ricarico fisso +20%".
- **File2 §4B riga 132**: descrive il livello globale come *"ereditato automaticamente all'atto della creazione di ogni nuovo prodotto"* — implica una **copia statica** del valore al momento della creazione prodotto.
- **File2 §4B riga 138 ("Logica del Pricing Engine")**: *"Cerca la percentuale... Se assente, cerca quella della categoria prodotto o in estrema ratio quella... globale"* — implica invece una **risoluzione dinamica** a runtime, con un livello intermedio "categoria prodotto" mai menzionato altrove.

Queste due frasi (riga 132 vs riga 138), a sei righe di distanza nello stesso paragrafo, descrivono **due architetture incompatibili**: copia-alla-creazione (statica, richiederebbe un job di bulk-update ogni volta che cambia il default globale) contro lettura-a-runtime (dinamica, sempre coerente col valore corrente).

**Codice reale**: `SalesPricelistsController.php:770-780` legge `Impostazioni.markup_fallback_default` **al momento del calcolo**, non da un valore copiato in anagrafica — è risoluzione dinamica. Nessun livello "categoria" esiste (verificato via grep, 0 occorrenze di `categoria` nel controller): i livelli reali sono solo 2 (prodotto → globale), non 3.

**Il codice è best practice? Sì, ed è la scelta tecnicamente superiore tra le due descritte in File2**: la risoluzione dinamica evita il classico bug da "default copiato": se domani il markup globale passa dal 20% al 25%, con un sistema a copia-statica tutti i prodotti creati prima del cambio resterebbero fermi al vecchio valore finché qualcuno non lancia un aggiornamento massivo; con la lettura dinamica, il nuovo default si applica immediatamente a tutti i prodotti che non hanno un override esplicito, senza alcuna manutenzione.

**Testo più vicino al codice**: riga 138 di File2 (dinamico), non riga 132 (statico). **Verdetto**: File2 va corretto rimuovendo sia il riferimento "ereditato alla creazione" sia il livello "categoria prodotto" inesistente; la sezione AS-IS (§1B, "+20% fisso") va sostituita perché oggi è raramente il valore realmente applicato, avendo due livelli di override sopra.

### C6 — Il codice di esempio di PARTE1 §6 non è normativo

> ✅ **RISOLTO — verdetto rivisto da "spostare" a "cancellare" dopo confronto più approfondito.** Corretto direttamente in `Versione Definitiva.md`, §6: gli snippet sono stati rimossi (non solo rietichettati) e sostituiti con un paragrafo di rimando al codice reale. Il testo sotto resta come traccia del confronto che ha portato a questa decisione.

- **PARTE1 §6A** (righe 194-233) e **§6B** (righe 235-323): presentano una classe JS `UomConverter` e una classe PHP `OrderProcessor` come se fossero rappresentative di come Quack implementa la conversione UoM.

**Codice reale**: lato PHP, `qk_uom_converti_riga()` in `includes/prodotti.php:956-997`; lato JS, `qkConvertUom()` in `assets/js/app.js:368-397`. Nomi di classi/funzioni/tabelle tutti diversi (inglese generico contro italiano reale) — questo da solo era già il problema originariamente segnalato. Ma un confronto **comportamentale**, non solo nominale, ha rivelato tre divergenze concrete, non semplice differenza di stile:

1. **Arrotondamento diverso**: lo snippet JS arrotonda sia quantità che prezzo a 4 decimali (`toFixed(4)` per entrambi); il codice reale arrotonda la quantità a **3** decimali e il prezzo a 4 — una differenza che altera il risultato numerico, non solo la forma del codice.
2. **Gestione dell'errore diversa**: lo snippet PHP lancia un'`Exception` e blocca tutto se manca il fattore di conversione; il codice reale, nello stesso caso, restituisce silenziosamente i valori non convertiti invece di bloccare l'operazione — è una scelta di robustezza diversa, non un dettaglio.
3. **Architettura diversa**: lo snippet PHP fonde calcolo e scrittura a database nella stessa funzione; il codice reale li tiene separati (la funzione restituisce solo numeri, senza toccare il database), il che è anche il motivo per cui la stessa funzione reale viene riusata da 4 cicli documentali diversi (preventivi, ordini vendita, RFQ, ordini d'acquisto) invece di richiedere una copia per ciascuno.

**Il codice è best practice? Perché?** La domanda va riformulata: qui non c'è un "comportamento del sistema" da giudicare (lo snippet non è mai stato eseguito in Quack), quindi la domanda utile è *"questo testo, tenuto così com'è, è utile ai nostri scopi?"* — che è esattamente la domanda posta. Risposta: **no**. Non è utile perché (a) il principio generale che vorrebbe insegnare — validare e ricalcolare sempre lato server — è già scritto in prosa altrove nello stesso documento senza bisogno di codice (vedi R4 in §2 di questa revisione, ripetuto 3 volte), quindi non aggiunge un'informazione nuova; (b) le tre divergenze comportamentali sopra non sono un dettaglio stilistico ma insegnerebbero un comportamento realmente diverso da quello di Quack a chiunque lo prendesse come riferimento per scrivere nuovo codice.

**Testo più vicino al codice**: nessuno dei due — non è una via di mezzo imprecisa, è proprio un'implementazione diversa su tre punti comportamentali concreti.

**Verdetto esplicito**: **cancellare gli snippet di codice**, non limitarsi a rietichettarli. Il principio generale che volevano illustrare resta, in una riga di prosa, senza codice. Chi ha bisogno del codice reale viene indirizzato per nome ai due file/funzioni reali (`qk_uom_converti_riga()`, `qkConvertUom()`).

### C7 — Badge origine prezzo in RFQ: l'affermazione di assenza è probabilmente obsoleta, da riverificare

- **File2** (riga 55): *"RFQ d'acquisto: la tabella non ha badge visivi. Non c'è alcun indicatore di provenienza del costo"*.

**Codice reale**: `app/views/purchases/rfq/_form.php:296` contiene già un elemento `.price-origin-badge` nel markup HTML della vista.

**Il codice è best practice? Perché?** **Non ancora accertabile con i dati raccolti finora** — e non voglio scrivere un "sì" o un "no" senza averlo verificato. So che l'elemento HTML del badge esiste nella vista, ma non ho verificato se sia effettivamente collegato a dati reali (cioè se il backend della RFQ popola davvero quel badge con l'origine del prezzo, come fa il ciclo Vendite) oppure se sia solo markup vuoto/di scaffolding non ancora cablato alla logica. Finché questo non è verificato end-to-end (aprendo una RFQ vera e controllando se il badge mostra un'origine reale), non è corretto affermare né che "funziona come in Vendite" né che "è solo un guscio vuoto".

**Testo più vicino al codice**: nessuno dei due è pienamente verificato — File2 afferma un'assenza totale che i fatti (l'elemento HTML esiste) già smentiscono in parte, ma non è nemmeno dimostrato che sia pienamente equivalente al badge di Vendite.

**Verdetto**: prima verificare end-to-end (aprire una RFQ nel browser, controllare se il badge mostra dati reali o resta vuoto/statico), poi correggere il tono di File2 di conseguenza — con l'esito della verifica, non con una supposizione.

### C8 — La tabella `product_uom_conversions` (Approccio 2): non implementata, ma l'ordine espositivo genera confusione

> ✅ **RISOLTO** — corretto direttamente in `Versione Definitiva.md`: aggiunto un avviso in apertura della Sezione 4 (prima dello schema SQL con `product_uom_conversions`) che anticipa la decisione Approccio1/2, con rimando alla Sezione 8D per il dettaglio completo; aggiunto anche un rimando incrociato nella Sezione 8D stessa. Non è stata necessaria la riorganizzazione strutturale completa in 3 file (§5) per risolvere questo punto specifico — è bastata una nota di anticipazione nel punto giusto. La riorganizzazione in 3 file resta comunque consigliata per le altre ragioni esposte in §5 (ridondanza, materiale di ricerca generico mescolato a specifica reale), ma non è più un prerequisito per questo punto. Il testo sotto resta come traccia di cosa era confuso e perché.

- **PARTE1 §4** (righe 72-148): presenta uno schema SQL con `product_uom_conversions` (N-a-N) come se fosse l'architettura proposta.
- Solo **350 righe più avanti**, **Sezione 8D** (riga 448) chiarisce che è "scenario futuro, non l'implementazione attuale".

**Codice reale**: confermato che la tabella non esiste — `prodotti` ha una singola FK `unita_contenuto_id` (Approccio 1).

**Il codice è best practice? Sì, per la fase attuale**: l'Approccio 1 (una sola UoM commerciale per prodotto) copre il 100% dei casi reali finché non emerge la necessità concreta di vendere lo stesso prodotto in più unità alternative sullo stesso documento (es. sia a KG che a Litri). Costruire subito la tabella N-a-N sarebbe complessità architetturale anticipata senza un requisito reale a supportarla — è corretto rimandarla a "roadmap futura" come fa Sezione 8D.

**Testo più vicino al codice**: Sezione 8D (l'unica che dichiara esplicitamente "Approccio 1 = implementazione attuale, Approccio 2 = roadmap futura non implementata"). PARTE1 §4, preso da solo e senza aver ancora letto Sezione 8D, non è "sbagliato" ma è **fuorviante per l'ordine in cui compare**: presenta lo schema N-a-N prima di dire che non è quello reale.

**Verdetto**: il contenuto è corretto, ma l'ordine di esposizione è invertito e genera una falsa impressione di contraddizione. Va anticipata la decisione Approccio1/2 **prima** di mostrare qualunque schema SQL.

### C10 — "Giacenze" e "movimenti di magazzino" trattati come sinonimi in un punto, ma sono due condizioni tecnicamente diverse

> ✅ **RISOLTO** — corretto direttamente in `Versione Definitiva.md`, PARTE2, edge case A della matrice. Il testo sotto resta come traccia di cosa era sbagliato e perché.

- **File1 PARTE2, edge case A** (riga 471): *"Consentito se `giacenze` e `movimenti_magazzino` sono a 0"* — richiede **entrambe** le condizioni.
- **File1 PARTE1 §3, Caso 1** (riga 65) e **PARTE3, edge case 1** (riga 628): parlano solo di "movimenti (anche solo 1)" come condizione di blocco, senza mai menzionare le giacenze.

Nello schema reale, `giacenze` (`db/init_mysql.sql:705-713`, saldo corrente: `quantita_disponibile`) e `movimenti_magazzino` (storico dei movimenti) sono **tabelle concettualmente distinte**: un prodotto può avere `giacenze.quantita_disponibile = 0` (perché tutto ciò che è stato caricato è stato anche scaricato) pur avendo **decine di righe storiche** in `movimenti_magazzino`.

**Codice reale**: sia `includes/prodotti.php:699-708` sia `app/Controllers/ProductsController.php:1043-1048` bloccano il cambio di `unita_misura_stock_id` **solo** sul `COUNT(*)` di `movimenti_magazzino > 0` — non leggono mai `giacenze`. Un prodotto con saldo a 0 ma con storico movimenti resta comunque bloccato.

**Il codice è best practice? Sì, ed è più corretto della formulazione di PARTE2 edge case A**: se si permettesse il cambio di Base UoM quando le sole giacenze sono a 0 (ignorando lo storico), tutte le registrazioni passate — costo medio ponderato, quantità aggregate nei report storici, eventuali lotti già chiusi — resterebbero espresse nella vecchia UoM, rendendo lo storico ambiguo da reinterpretare. Bloccare in modo permanente non appena esiste **un solo movimento passato**, indipendentemente dal saldo attuale, è esattamente il comportamento che SAP adotta secondo la stessa ricerca citata nel documento (PARTE1 §3, Caso 1: *"una volta che un prodotto ha movimenti di magazzino (anche solo 1), la Base UoM viene bloccata"*) — quindi il codice è coerente con la best practice che il documento stesso descrive altrove, mentre è **PARTE2 edge case A** ad introdurre una condizione ulteriore e più permissiva (giacenze=0) che il codice non applica.

**Testo più vicino al codice**: PARTE1 §3 e PARTE3 edge case 1 (solo movimenti). **Verdetto**: correggere PARTE2 edge case A rimuovendo il riferimento a `giacenze`, per non lasciar credere che un prodotto "svuotato" possa tornare modificabile — non è così, né dovrebbe esserlo.

---

## 4. Sintesi valutativa

| Punto | I documenti sono... | Il codice reale è... | È best practice? Risposta netta | Stato correzione |
|---|---|---|---|---|
| C1 (formula num/den) | Corretti tranne un refuso in Sez. 8D | `num=1,den=25`, frazioni intere | **SÌ** — evita deriva float sui periodici, tecnica SAP (MARM) | ✅ Applicata nei file sorgente |
| C2 (`unita_misura_id`) | File2 aggiornato; File1/File4 obsoleti | Colonna già presente | **SÌ** — realizza il principio "Native UoM Pricing" che i testi stessi raccomandano | ✅ Applicata nei file sorgente |
| C3 (conversione prezzo backend/frontend) | File2 corretto; File1 PARTE2 descrive proposta scartata | Anteprima client-side (Momento 1), autorità server-side al salvataggio (Momento 2) | **SÌ, netto** — separazione corretta tra UX reattiva (non salvata) e calcolo contabile validato (l'unico che conta, e non si fida mai del browser) | ✅ Applicata nei file sorgente |
| C4 (pricing acquisti) | File2 obsoleto | Motore a scaglioni già implementato, simmetrico alle vendite | **SÌ** — evita duplicazione di logica che diverge nel tempo | ✅ Applicata nei file sorgente |
| C5/C9 (markup fallback) | File2 si contraddice internamente (riga 132 statico vs riga 138 dinamico) + livello "categoria" inesistente | Dinamico a 2 livelli (prodotto→globale), nessuna categoria | **SÌ** — evita il bug classico del "default copiato" che va disallineato quando cambia il valore globale | ✅ Applicata nei file sorgente |
| C6 (codice esempio generico) | Non errato ma non etichettato — verdetto rivisto dopo confronto comportamentale | Diverge da 3 comportamenti reali (arrotondamento, gestione errore, architettura calcolo/persistenza) | **NO, non è utile ai nostri scopi** — il principio che insegna è già scritto altrove in prosa; il codice stesso rischia di essere copiato con comportamenti sbagliati | ✅ Applicata nei file sorgente (cancellato, non spostato) |
| C7 (badge RFQ) | Probabilmente obsoleto | Elemento UI presente, funzionalità end-to-end da confermare | **Da verificare** (non ancora accertato se il badge sia solo markup vuoto o davvero popolato) | Da verificare, poi correggere il tono in File2 |
| C8 (product_uom_conversions) | Corretti nel contenuto, mal ordinati | Non implementata (Approccio 1) | **SÌ** — evita complessità anticipata senza requisito reale | ✅ Applicata nei file sorgente (nota di anticipazione, non serviva la riorganizzazione completa) |
| C10 (giacenze vs movimenti) | PARTE2 introduce una condizione (giacenze=0) che PARTE1/PARTE3 non hanno | Blocca solo su `movimenti_magazzino>0`, ignora giacenze | **SÌ** — più conservativo, coerente con SAP: lo storico resta ambiguo anche a saldo zero | ✅ Applicata nei file sorgente |

**In sintesi**: in ogni singolo punto dove la domanda "è best practice?" riguarda un comportamento del sistema (C1, C2, C3, C4, C5/C9, C8, C10), la risposta verificata è **sempre sì**: il codice reale è una scelta tecnicamente valida e allineata alle best practice enterprise che gli stessi documenti descrivono in astratto — non è mai stato trovato un bug o una scorciatoia impropria nel codice stesso. C6 è un caso diverso: lì la domanda pertinente non è "il codice si comporta bene?" (non è mai stato codice Quack) ma "questo testo è utile a chi legge la documentazione?", e la risposta, dopo un confronto comportamentale approfondito, è no — per questo è stato cancellato invece che solo rietichettato. C7 resta genuinamente aperto (elemento UI presente, funzionalità da confermare). Le contraddizioni nascono quasi sempre dal fatto che la documentazione non è stata aggiornata di pari passo con lo sviluppo (parti "da fare" già fatte, parti "stato attuale" ormai superate), non da un codice scritto male.

**Correzioni già applicate ai 4 file originali in questa sessione**: C1, C2 (incluso lo schema stale di File4), C3, C4, C5/C9, C6 (cancellazione, non spostamento), C8 (nota di anticipazione, non l'intera riorganizzazione), C10. **Resta aperto solo C7**, che richiede una verifica end-to-end nel browser prima di poter essere corretto.

---

## 5. Proposta di riorganizzazione dei file

Applicando il criterio indicato — *un ciclo si documenta come un'unica guida omnicomprensiva (UX/UI utente + comportamento backend/DB), con i dettagli tecnici puri (SQL/PHP) in capitoli successivi, e le ricerche di best practice raccolte in un contenitore separato richiamabile con un rimando* — la cartella va riorganizzata così:

### `00 - Best Practice di Mercato (Multi-UoM e Pricing).md` — nuovo file, contenitore unico
Contiene **solo** materiale comparativo/ispirativo, esplicitamente marcato come non normativo per Quack:
- PARTE1 §1-3 di File1 (filosofia SAP/Oracle/Dynamics, edge case generici, incluso il concetto di Catch Weight — vedi §6.1 sotto, oggi non implementato)
- Gli Approcci A/B/C (Odoo/SAP/pattern UX) di File2 §2 (righe 59-102)
- Lo schema SQL generico di PARTE1 §4 (con avviso esplicito in testa: "esempio illustrativo, i nomi di tabella NON corrispondono al codice Quack reale — vedi capitolo 02 per lo schema reale"). Il codice di esempio che era in PARTE1 §6 (classe JS `UomConverter`, classe PHP `OrderProcessor`) **non va spostato qui**: è già stato cancellato direttamente da `Versione Definitiva.md` (vedi C6 in §3), perché divergeva da comportamenti reali del sistema e non solo dai nomi — a differenza dello schema SQL, per cui non è stata trovata la stessa gravità di divergenza.

### `01 - Ciclo Multi-UoM e Prezzi - Guida Completa.md` — nuovo file, cuore del sistema
Un'unica guida fase-per-fase (UX + comportamento backend/DB insieme, senza SQL/PHP crudo), che:
1. **Dichiara subito in apertura** la decisione Approccio 1 vs Approccio 2 (risolve C8).
2. Attraversa il flusso reale: anagrafica prodotto (Stock UoM, UoM commerciale, fattore di conversione, blocco post-movimenti basato solo su `movimenti_magazzino`, mai su `giacenze` — risolve C10) → configurazione listini vendita/acquisto (con UoM nativa) → RRP multi-fornitore → preventivo/ordine vendita (toggle stock/commerciale, badge origine prezzo, override manuale) → RFQ/ordine acquisto (stesso pattern, differenze reali evidenziate) → ricevimento merce.
3. Include **una sola** matrice di edge case (fusione di R3), verificata contro il codice, con una colonna "Stato" (Implementato / Da fare / Verificare) — e integra gli 8+ casi mancanti del §6.
4. Assorbe `listini_architettura.md` e `UI_UX_PREZZI.md` come sotto-sezioni del flusso.
5. Include la sezione UX di File2 §4 (fallback hierarchy corretta a 2 livelli, selettore listino, tracciabilità/override) nel punto del flusso a cui appartiene.

### `02 - Dettaglio Tecnico - Schema DB e Codice.md` — nuovo file, capitolo di dettaglio
Schema reale verificato, funzione `qk_uom_converti_riga()` come riferimento autoritativo, riferimenti puntuali ai controller/JS reali (vedi §1).

### File da eliminare dopo il travaso
- `Architettura, Analisi e Best Practice... Versione Definitiva.md`, `Listini nella formulazione offerte.md` → smistati ed eliminati.
- `listini_architettura.md`, `UI_UX_PREZZI.md` → assorbiti come sotto-sezioni di `01` (già ben scritti, solo da incorporare).

---

## 6. Analisi sistematica delle eccezioni, paragrafo per paragrafo

Per ogni sotto-argomento trattato nei 4 file, verifica se la copertura delle eccezioni/casi limite è completa. Elenco organizzato per file e paragrafo di origine.

### 6.1 File1 PARTE1 §2 "Catch Weight (Doppia Misura)" (riga 57) — eccezione strutturale non risolta altrove

Il documento stesso segnala che nel settore chimico (il business reale di Quack, prodotti ADR) è "estremamente comune" tracciare **contemporaneamente** una quantità a pezzi (es. 5 fusti) e il peso reale effettivo rilevato al carico/scarico (es. 1023 kg, che può differire dal teorico 5×25=125 kg per variazioni di densità/riempimento lotto per lotto). Questo è citato come pratica enterprise ma **non viene mai ripreso** né in PARTE2, né in PARTE3, né nella Sezione 8 delle decisioni Quack: l'Approccio 1 deciso (un solo fattore fisso `quantita_contenuto` per prodotto) **non supporta** una doppia misura variabile per singolo movimento/lotto. Verificato anche a livello DB: nessuna colonna `peso_reale`/`peso_effettivo` su `movimenti_magazzino`. **Questa è l'eccezione più rilevante lasciata completamente aperta**, perché riguarda proprio il caso d'uso citato esplicitamente come tipico del dominio applicativo di Quack (chimica/ADR) — va quantomeno annotata come limitazione nota, o va deciso esplicitamente se e quando affrontarla.

### 6.2 File1 PARTE1 §1A "Immutabilità Transazionale" (righe 27-31) — eccezioni mancanti

> ✅ **RISOLTO** — verificato il comportamento reale su tutti e 4 i tipi di documento (preventivi, ordini cliente, RFQ, ordini d'acquisto) e aggiunta la decisione risultante direttamente in `Versione Definitiva.md`, nuova Sezione 8F. Il testo sotto resta come traccia della domanda originale.

- Non è mai chiarito cosa succede a un documento **annullato prima della conferma** (bozza cancellata): non ha ancora eseguito lo snapshot, quindi non c'è nulla da congelare — ovvio ma mai dichiarato esplicitamente, lasciando ambiguo se l'eliminazione di una bozza richieda una gestione particolare.
- Non è mai trattato il caso di un documento **riaperto dopo la conferma** (es. ordine cliente confermato riportato in bozza per correggere un errore): lo snapshot va ricalcolato con i valori correnti o resta congelato al momento della prima conferma? Nessuno dei 3 sotto-documenti di File1 lo dice.

**Risultati della verifica (10/07/2026)**:

| Documento | Meccanismo di riapertura | Comportamento snapshot |
|---|---|---|
| Preventivi vendita | Nessuno — bloccato in modo definitivo (`SalesQuotesController.php:217-221`) | N/A, non editabile |
| Ordini cliente | "Riapri" cambia solo lo stato di testata (`SalesOrdersController.php:503-506`) | N/A, nessun editor di riga esiste |
| RFQ acquisto | "Nuova versione" (`qk_rfq_nuova_versione()`, `includes/purchases_rfq.php:442`) | **Ricalcolato** dai dati prodotto correnti — `_rfq_insert_righe()` chiama sempre `qk_uom_converti_riga()`, anche in copia versione |
| Ordini d'acquisto | "Nuova revisione" / Order Amendment (`qk_ordine_crea_revisione()`, `includes/purchases_orders.php:544`) | **Preservato esattamente** — righe copiate senza mai richiamare `qk_uom_converti_riga()` |

**Perché la differenza RFQ/Ordine d'acquisto è corretta, non un bug**: una RFQ non è vincolante (nessun impegno preso col fornitore), quindi ha senso che rifletta i dati correnti a ogni nuova versione. Un ordine d'acquisto inviato è un impegno contrattuale già preso: una sua revisione deve partire dalle stesse condizioni concordate, non da una loro reinterpretazione — stessa logica della Sezione 8E (resi), e coerente con la prassi SAP (RFQ liberamente rinegoziabile; Purchase Order inviato richiede *Change Management* che preserva di default le condizioni originarie).

**Divergenza tra codice, best practice e grandi ERP**: nessuna — il comportamento del codice è corretto e allineato su tutti i fronti. La vera lacuna era solo nella documentazione, che non menzionava affatto questi due meccanismi di versionamento.

### 6.3 Base UoM — due eccezioni mancanti, con soluzione proposta

*(Un solo file coinvolto: `Versione Definitiva.md`. Da qui in poi "il documento" significa sempre e solo quello.)*

**Problema 1: si disattiva una UoM mentre un prodotto la sta ancora usando.**

Esempio: il prodotto "Solvente Alpha" si vende in FUSTI. Un giorno qualcuno va nell'elenco generale delle unità di misura e disattiva "FUSTO" — magari perché pensa che ormai serva solo TANICA per i prodotti nuovi. Il documento dice che disattivare una UoM va bene: non rompe nulla per chi la usa già, semplicemente non è più scegliibile per cose nuove.

Il problema: nessuno ha pensato a cosa succede se, dopo aver disattivato FUSTO, qualcuno prova a modificare la scheda di "Solvente Alpha" — che usa ancora FUSTO oggi, non solo in passato. Il menu a tendina per scegliere la Stock UoM probabilmente mostra solo le unità attive: FUSTO sparisce dalla lista, pur essendo il valore attuale del prodotto. E il prodotto non si può nemmeno spostare su un'altra UoM, perché (altra regola, sempre nello stesso documento) se ha già movimenti di magazzino il cambio di Stock UoM è vietato per sempre. Il prodotto rischia di restare bloccato, e il documento non ne parla.

**Soluzione professionale (cosa fanno i best-in-class come SAP)**: separare due regole che oggi il documento confonde in una sola.
1. *"Selezionabile per cose nuove"* — questo il documento lo dice già bene: una UoM disattivata sparisce dai menu quando si crea un prodotto o una riga nuova.
2. *"Continua a funzionare per cose che la usano già"* — questo manca, e va scritto esplicitamente: salvare una scheda prodotto **senza cambiare** il campo Stock UoM deve funzionare sempre, anche se quella UoM è stata nel frattempo disattivata (mostrandola con un'etichetta tipo "FUSTO (disattivato)" per avvisare, non nascondendola). Il filtro alle sole UoM attive si applica solo quando l'utente sceglie attivamente un valore diverso da quello attuale.

> ⚠️ **Correzione (10/07/2026, dopo verifica più approfondita su cosa fanno davvero i grandi ERP)**: una versione precedente di questo paragrafo proponeva anche di **bloccare** la disattivazione di una UoM se è ancora la Stock UoM di un prodotto attivo. Verificato che **non è quello che fa SAP**: SAP non impedisce mai un cambiamento di anagrafica per via di riferimenti che diventerebbero "non più applicabili" altrove — lascia che accada, e il riferimento diventato incongruente smette semplicemente di essere raggiungibile dai flussi normali (nessun dato è "rotto": FUSTO continua a esistere come riga valida, semplicemente Solvente Alpha non lo userebbe più attivamente). La correzione giusta resta **solo** il punto 2 sopra (il dropdown continua a mostrare/accettare il valore corrente anche se disattivato) — **nessuna guardia bloccante va aggiunta**. Al suo posto, i grandi ERP offrono uno strumento di reportistica separato per trovare (non impedire) le UoM disattivate ancora referenziate, così un responsabile può fare pulizia quando vuole — vedi punto 4 della specifica sotto.

#### Specifica di implementazione — Problema 1 (verificata contro il codice reale il 10/07/2026, pronta per essere eseguita)

**Premessa importante, diversa da quanto ipotizzato all'inizio**: la funzionalità di disattivazione di una UoM **non esiste ancora in nessuna forma** in Quack. Verificato che:
- La tabella `unita_misura` (`db/init_mysql.sql:99-106`) ha solo le colonne `id, codice, descrizione, categoria_id, fattore_base, e_riferimento` — **nessuna colonna `attivo`/`is_active`**.
- Non esiste alcuna vista o controller per gestire (creare/modificare/disattivare) le righe di `unita_misura` — nessun file `app/views/*unita*` o `*misura*` trovato, nessuna azione di disattivazione in nessun controller.

Quindi questa non è "aggiungere una guardia a una funzione esistente": è costruire la funzionalità di disattivazione UoM da zero, con la guardia già incorporata fin dall'inizio. Passi necessari, in ordine:

**1. Migrazione schema** (su entrambi gli schemi gemelli, `db/init_mysql.sql` e `db/init_sqlite.sql`):
```sql
ALTER TABLE unita_misura ADD COLUMN attivo TINYINT(1) NOT NULL DEFAULT 1;
```

**2. Query di lettura per i due dropdown della scheda prodotto** (`app/Controllers/ProductsController.php`, righe 74 e 184 — attualmente identiche: `SELECT id, codice, descrizione FROM unita_misura ORDER BY descrizione`):
- Nel metodo di **creazione** prodotto (riga 74, nessun prodotto esistente da preservare): cambiare in `SELECT id, codice, descrizione, attivo FROM unita_misura WHERE attivo = 1 ORDER BY descrizione`.
- Nel metodo di **modifica** prodotto (riga 184, va preservato il valore corrente anche se disattivato): la query deve includere sia le UoM attive sia — se diverse — la UoM di stock corrente e la UoM commerciale correnti del prodotto in modifica:
```sql
SELECT id, codice, descrizione, attivo FROM unita_misura
WHERE attivo = 1
   OR id = :stock_uom_id_corrente
   OR id = :contenuto_uom_id_corrente
ORDER BY descrizione
```
  (i due parametri vanno presi dal record prodotto già caricato in quel metodo, prima di eseguire questa query; se `unita_contenuto_id` è NULL, passare un valore che non può mai fare match, es. `0`).

**3. Vista** (`app/views/products/_form.php`):
- Riga ~477-481 (dropdown Stock UoM, `name="unita_misura"`) e riga ~510-514 (dropdown UoM commerciale, `name="unita_contenuto_id"`): in entrambi i cicli `foreach ($unita_misura_list as $umRow)`, cambiare la label da
  ```php
  <?= e($umRow['codice']) ?> — <?= e($umRow['descrizione']) ?>
  ```
  a
  ```php
  <?= e($umRow['codice']) ?> — <?= e($umRow['descrizione']) ?><?= empty($umRow['attivo']) ? ' (disattivata)' : '' ?>
  ```

**4. Nessuna guardia bloccante alla disattivazione.** La disattivazione (`UPDATE unita_misura SET attivo = 0 WHERE id = ?`) procede sempre, senza controlli preventivi — coerente con come si comportano i grandi ERP (vedi correzione sopra).

**5. Report di pulizia (facoltativo, in stile SAP — strumento amministrativo, non un blocco)**: una query/vista che elenca le UoM disattivate ancora referenziate come Stock UoM o UoM commerciale da un prodotto attivo, da mostrare in una pagina Impostazioni per pulizia manuale periodica:
```sql
SELECT p.id, p.nome, um.codice AS uom_disattivata, 
       CASE WHEN p.unita_misura_stock_id = um.id THEN 'stock' ELSE 'commerciale' END AS ruolo
FROM prodotti p
JOIN unita_misura um ON um.id IN (p.unita_misura_stock_id, p.unita_contenuto_id)
WHERE um.attivo = 0 AND p.attivo = 1
```

**6. UI per l'azione di disattivazione**: dato che oggi non esiste alcuna vista per gestire l'anagrafica UoM, va creata (o aggiunta a una vista Impostazioni esistente, es. `app/Controllers/SettingsController.php`, già usato per altre configurazioni globali come il markup di fallback) una tabella con elenco UoM, un pulsante "Disattiva" (nessuna guardia, punto 4) e la sezione di report del punto 5.

**Problema 2: si cambia la Stock UoM di un prodotto, ma esistono già preventivi in bozza scritti nella sua unità commerciale.**

Esempio: "Acido X" si vende in FUSTI (unità di stock) ma i clienti lo comprano a KG (unità commerciale), con la regola "1 fusto = 25 kg". Non ci sono ancora movimenti di magazzino per questo prodotto. Un commerciale ha già scritto un preventivo in bozza: "100 KG di Acido X", e il sistema ha calcolato che equivalgono a 4 fusti.

Prima che il preventivo venga confermato, qualcuno cambia la Stock UoM di Acido X da FUSTO a TANICA (permesso, perché non ci sono ancora movimenti). Il documento dice solo: *"il sistema aggiorna le bozze"* — e basta, nessun altro dettaglio.

Il problema: e se 1 tanica pesa 20 kg, non 25? La riga di preventivo dice ancora "100 KG", ma il numero nascosto dietro (quante unità di stock corrispondono) era stato calcolato per i FUSTI, non per le TANICHE. Se il sistema si limita a cambiare il nome dell'unità ("ora è tanica invece di fusto") senza ricalcolare il numero, il preventivo finirebbe per dire che 100 kg equivalgono a 4 taniche — falso, perché 4 taniche da 20 kg fanno 80 kg, non 100. Il documento non dice se "aggiorna le bozze" significa solo cambiare il nome dell'unità, o ricalcolare davvero la quantità.

**Soluzione professionale**: "aggiornare le bozze" non deve mai voler dire "cambia solo l'etichetta" — deve voler dire *ricalcola la riga esattamente come se l'operatore la stesse inserendo oggi da zero, usando lo stesso motore di calcolo delle righe nuove*. Concretamente: prendere il dato che l'utente ha davvero inserito (100 KG) e ripassarlo nella stessa funzione di calcolo usata per una riga nuova, con la nuova Stock UoM — non toccare a mano il numero "4" già salvato. In Quack esiste già una funzione unica che fa questo calcolo per ogni riga nuova (`qk_uom_converti_riga()`, verificata nei punti precedenti di questa revisione): la soluzione naturale è richiamare quella stessa funzione per ogni bozza collegata, non inventarne una diversa "di aggiornamento".

C'è anche una seconda regola che i best-in-class applicano qui, e che manca del tutto: **non permettere il cambio di Stock UoM se il nuovo fattore di conversione verso la UoM commerciale non è ancora stato definito**. Nell'esempio, se nessuno ha ancora detto "1 tanica = quanti kg", cambiare la Stock UoM a TANICA lascerebbe il prodotto in un buco: non si saprebbe come ricalcolare le bozze esistenti. La soluzione è bloccare il cambio finché il nuovo fattore non viene inserito nella stessa operazione — non è un principio nuovo, è applicare coerentemente una regola che il documento usa già altrove (blocco di un listino in una UoM commerciale non configurata).

#### Specifica di implementazione — Problema 2 (verificata contro il codice reale il 10/07/2026, pronta per essere eseguita)

**Punto di partenza già esistente da riusare**: il blocco cambio Stock UoM su prodotti con movimenti è già implementato in due punti gemelli — `includes/prodotti.php`, funzione `qk_update_prodotto()` (righe 687-709) e `app/Controllers/ProductsController.php` (righe 1024-1050, validazione lato form). La logica sotto va aggiunta **subito dopo** la guardia sui movimenti esistente in entrambi i punti (cioè: si applica solo quando il cambio di Stock UoM è effettivamente permesso, movimenti = 0).

**1. Guardia: fattore di conversione obbligatorio quando cambia la Stock UoM e il prodotto ha una UoM commerciale**

In `qk_update_prodotto()` (`includes/prodotti.php:687` in poi), subito dopo il blocco esistente `if ($hasMovements) { throw ... }`, aggiungere:
```php
if ($currentUmId && (int)$currentUmId !== (int)$um_id && !$hasMovements) {
    // La Stock UoM sta cambiando davvero (non solo un resubmit con lo stesso valore).
    $stContenuto = $pdo->prepare('SELECT unita_contenuto_id FROM prodotti WHERE id = ?');
    $stContenuto->execute([$id]);
    $haUnitaContenuto = (bool) $stContenuto->fetchColumn();

    if ($haUnitaContenuto) {
        $nuovaQuantitaContenuto = qk_prodotti_decimal($v['quantita_contenuto'] ?? null);
        if ($nuovaQuantitaContenuto === null || (float)$nuovaQuantitaContenuto <= 0.0) {
            throw new \RuntimeException(
                "Devi specificare il nuovo fattore di conversione (\"Quantità per unità di stock\") " .
                "perché stai cambiando l'unità di misura di stock di un prodotto che ha già " .
                "un'unità commerciale configurata. Il vecchio fattore non è valido per la nuova unità di stock."
            );
        }
    }
}
```
**Nota importante sul perché questa guardia non basta a fidarsi ciecamente del nuovo numero**: questa guardia impedisce solo il caso "campo lasciato vuoto o a zero" — non garantisce che l'utente abbia davvero ricontrollato il valore (potrebbe risottomettere lo stesso numero di prima per errore, credendo che si riferisca ancora alla vecchia unità). Non è possibile risolvere questa ambiguità solo lato backend: va accompagnata dal punto 3 (comportamento JS) che azzera visivamente il campo per costringere l'utente a re-inserirlo consapevolmente.

**2. Funzione di ricalcolo delle bozze collegate**

Nuova funzione, es. in `includes/prodotti.php` (accanto a `qk_uom_converti_riga()`, di cui riusa la logica):
```php
/**
 * Ricalcola lo snapshot UoM/prezzo di tutte le righe in stato "bozza" che
 * referenziano $prodotto_id, dopo un cambio di Stock UoM sul prodotto.
 * Da chiamare DOPO che il nuovo unita_misura_stock_id (e l'eventuale nuovo
 * quantita_contenuto/num_factor/den_factor) sono già stati scritti su `prodotti`.
 *
 * @return array{preventivi: int, rfq: int, ordini_acquisto: int} conteggio righe toccate per tipo documento
 */
function qk_ricalcola_bozze_per_cambio_stock_uom(PDO $pdo, int $prodotto_id): array
{
    $risultato = ['preventivi' => 0, 'rfq' => 0, 'ordini_acquisto' => 0];

    // --- Preventivi in bozza ---
    $st = $pdo->prepare("
        SELECT pr.* FROM preventivi_righe pr
        JOIN preventivi p ON p.id = pr.preventivo_id
        WHERE pr.prodotto_id = ? AND p.stato = 'bozza'
    ");
    $st->execute([$prodotto_id]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $riga) {
        // Risolvere il codice UoM dalla riga (serve a qk_calcola_riga_offerta come 'unita_misura')
        $umCodice = $pdo->prepare('SELECT codice FROM unita_misura WHERE id = ?');
        $umCodice->execute([$riga['unita_misura_id']]);
        $riga['unita_misura'] = $umCodice->fetchColumn() ?: 'pz';

        $ricalcolata = qk_calcola_riga_offerta($pdo, $riga, (int)$riga['posizione_riga']);

        $upd = $pdo->prepare("
            UPDATE preventivi_righe SET
                quantita_stock = ?, system_unit_price_stock = ?, unita_misura_stock_id = ?,
                quantita_contenuto_snapshot = ?, num_factor_snapshot = ?, den_factor_snapshot = ?,
                totale_costo_riga = ?, margine_riga = ?
            WHERE id = ?
        ");
        $upd->execute([
            $ricalcolata['quantita_stock'], $ricalcolata['system_unit_price_stock'], $ricalcolata['unita_misura_stock_id'],
            $ricalcolata['quantita_contenuto_snapshot'], $ricalcolata['num_factor_snapshot'], $ricalcolata['den_factor_snapshot'],
            $ricalcolata['totale_costo_riga'], $ricalcolata['margine_riga'],
            $riga['id'],
        ]);
        $risultato['preventivi']++;
    }

    // --- RFQ in bozza: stessa logica, tabella rfq_righe / rfq_testata, riusa qk_uom_converti_riga() ---
    // (struttura identica al blocco sopra: JOIN su rfq_testata.stato = 'bozza', poi UPDATE
    //  quantita_stock/system_unit_price_stock/unita_misura_stock_id/quantita_contenuto_snapshot/
    //  num_factor_snapshot/den_factor_snapshot su rfq_righe)

    // --- Ordini d'acquisto in bozza: stessa logica, tabella ordini_righe / ordini_testata,
    //     JOIN su ordini_testata.stato = 'bozza' ---

    return $risultato;
}
```
Le sezioni RFQ e ordini d'acquisto sono lasciate come "stessa struttura" nello scheletro sopra perché la logica è identica a quella dei preventivi, cambia solo il nome delle tabelle — vanno scritte per esteso al momento dell'implementazione, non è necessario progettarle di nuovo da zero.

**3. Comportamento frontend richiesto** (form prodotto, `app/views/products/_form.php`, campo `id="sel-um"` per la Stock UoM e campo "Quantità per unità stock" vicino a `id="sel-unita-contenuto"`): quando l'utente cambia la selezione di `#sel-um` **e** il prodotto ha già una `unita_contenuto_id` impostata, uno script JS deve svuotare il campo "quantità per unità stock" e mostrare un messaggio inline (es. sotto il campo): *"Hai cambiato l'unità di stock: inserisci di nuovo quanta UoM commerciale corrisponde a 1 unità della nuova UoM di stock."* — così l'utente non può sottomettere per errore un vecchio numero senza accorgersene.

**4. Punto di invocazione**: `qk_ricalcola_bozze_per_cambio_stock_uom()` va chiamata da `qk_update_prodotto()` subito dopo l'`UPDATE` su `prodotti` che scrive il nuovo `unita_misura_stock_id`, nella stessa transazione database (se il salvataggio prodotto già usa una transazione; altrimenti va aggiunta una transazione che avvolga entrambe le operazioni, per evitare che il prodotto risulti aggiornato ma le bozze no in caso di errore a metà). Il conteggio righe restituito va mostrato all'utente in un messaggio di conferma, es. *"Prodotto aggiornato. 3 preventivi in bozza sono stati ricalcolati con la nuova unità di stock."*

### 6.4 File1 PARTE2 edge case C / PARTE3 edge case 3 "UoM Commerciale non definita" — eccezione mancante

> ✅ **RISOLTO** — soluzione proposta e concordata il 10/07/2026, aggiunta direttamente in `Versione Definitiva.md`, nuova Sezione 8G.

Coperto il caso "prodotto senza `unita_contenuto_id` → UI blocca su Stock UoM". **Non coperto**: cosa succede se il prodotto **aveva** `unita_contenuto_id` impostato, sono già stati creati listini/RRP nativi in quella UoM commerciale, e successivamente l'anagrafica viene modificata azzerando (o cambiando) `unita_contenuto_id`? I listini restano "orfani" (puntano a una UoM che il prodotto non ha più come commerciale) — non trattato in nessuno dei 4 file.

**Esempio**: "Acido X" ha Stock UoM = FUSTO e UoM commerciale = KG ("1 fusto = 25 kg"). Nel tempo sono stati creati listini vendita espressi in KG (es. "Listino Grossisti: 10 €/KG"). Qualcuno azzera `unita_contenuto_id` su Acido X: quei listini restano nel database puntando a "KG" come loro UoM di prezzo, ma il prodotto non ha più alcuna relazione con i KG — non si sa se il sistema li nasconda, li blocchi, li converta, o li lasci semplicemente lì, rotti, finché qualcuno non se ne accorge.

> ⚠️ **Correzione (10/07/2026, dopo verifica su cosa fanno davvero i grandi ERP)**: una versione precedente di questo paragrafo proponeva di **bloccare** la rimozione/cambio della UoM commerciale finché non si eliminano o convertono i prezzi collegati. Verificato che **non è la best practice**: SAP non fa questo controllo. Lascia che il cambio avvenga, e il prezzo diventato incongruente (es. "10 €/KG" quando il prodotto non ha più KG come UoM commerciale) diventa semplicemente **irraggiungibile** — nessun dato è danneggiato: KG continua a esistere come unità valida, la riga di listino continua a esistere e a puntarci correttamente, semplicemente nessuno potrà più selezionare quella combinazione per Acido X. La sicurezza non viene dal bloccare il cambiamento, ma dal fatto che il motore di ricerca prezzo, non trovando corrispondenza, passa al livello successivo della sua cascata invece di fallire — comportamento che Quack **ha già** (verificato in `SalesPricelistsController.php`: se un listino dedicato non produce un match, si scende a listino standard, poi a ricarico su costo, poi a manuale — la stessa identica cascata già verificata in altri punti di questa revisione).

**Soluzione decisa**: **nessuna guardia bloccante**. Il cambio/rimozione di `unita_contenuto_id` procede sempre. In aggiunta, uno strumento di reportistica (facoltativo, in stile SAP) per trovare — non impedire — i prezzi diventati orfani, utile per una pulizia amministrativa periodica.

#### Specifica di implementazione (verificata contro il codice reale il 10/07/2026, pronta per essere eseguita)

**1. Nessuna modifica a `qk_update_prodotto()`**: il salvataggio di `unita_contenuto_id` (righe 757, 781 di `includes/prodotti.php`) resta esattamente com'è oggi, senza controlli aggiuntivi.

**2. Verifica che la cascata di fallback gestisca già il caso "nessun prezzo trovato per questa combinazione prodotto/UoM"** — già confermato in questa revisione: `SalesPricelistsController.php` implementa i livelli Dedicato → Standard → Costo (ricarico) → Manuale, e ciascun livello semplicemente non trova un risultato e passa al successivo se la query non produce righe. Nessuna modifica necessaria qui: è già il comportamento corretto.

**3. Report di pulizia** (nuova query, da esporre in una pagina Impostazioni, es. accanto al report di 6.3 Problema 1): elenca prezzi di listino/acquisto/RRP la cui UoM non è più né lo Stock UoM né la UoM commerciale del prodotto a cui appartengono:
```sql
-- Prezzi di listino vendita orfani
SELECT lp.id, p.nome AS prodotto, um.codice AS uom_prezzo, lp.prezzo_unitario
FROM listini_prezzi lp
JOIN prodotti p ON p.id = lp.prodotto_id
JOIN unita_misura um ON um.id = lp.unita_misura_id
WHERE lp.unita_misura_id NOT IN (p.unita_misura_stock_id, COALESCE(p.unita_contenuto_id, -1))

-- Prezzi fornitore orfani (stessa logica, tabella diversa)
SELECT pfp.id, p.nome AS prodotto, um.codice AS uom_prezzo, pfp.prezzo
FROM prodotti_fornitori_prezzi pfp
JOIN prodotti_fornitori pf ON pf.id = pfp.prodotto_fornitore_id
JOIN prodotti p ON p.id = pf.prodotto_id
JOIN unita_misura um ON um.id = pfp.unita_misura_id
WHERE pfp.unita_misura_id NOT IN (p.unita_misura_stock_id, COALESCE(p.unita_contenuto_id, -1))

-- RRP orfani (stessa logica, tabella prodotti_fornitori)
SELECT pf.id, p.nome AS prodotto, um.codice AS uom_rrp, pf.rrp_min, pf.rrp_max
FROM prodotti_fornitori pf
JOIN prodotti p ON p.id = pf.prodotto_id
JOIN unita_misura um ON um.id = pf.rrp_unita_misura_id
WHERE pf.rrp_unita_misura_id NOT IN (p.unita_misura_stock_id, COALESCE(p.unita_contenuto_id, -1))
```
Queste tre query coprono anche il caso simmetrico del punto 6.3 (cambio della **Stock** UoM, non solo della UoM commerciale): il criterio `NOT IN (stock, commerciale)` intercetta un prezzo orfano indipendentemente da quale dei due campi sia cambiato — è la versione generale e corretta della regola, più ampia di quanto proposto inizialmente.

**4. Nessuna azione "Converti e rimuovi" automatica**: coerente con "non bloccare", non serve nemmeno offrire una conversione automatica forzata — se un amministratore, guardando il report del punto 3, decide di sistemare un prezzo orfano, lo fa manualmente modificando quella riga di listino con i normali strumenti già esistenti (modifica prezzo, modifica UoM del prezzo). Non è necessaria una funzione dedicata.

### 6.5 File1 PARTE2 edge case E / PARTE3 edge case 4 "Variazione del fattore di conversione" — eccezione mancante

> ✅ **RISOLTO** — soluzione decisa il 10/07/2026 sulla base di come funzionano davvero le condizioni di prezzo in SAP.

Ben coperto per le righe documento (snapshot). **Non coperto**: l'impatto sui **listini_prezzi** stessi quando il fattore cambia. Il testo dice che "il valore equivalente in stock cambia, il backend applicherà il nuovo fattore per le nuove transazioni" — ma se un listino conserva solo il prezzo nativo (es. `10€/KG`) senza mai salvare uno snapshot del fattore usato al momento della sua creazione, un utente che confronta storicamente un vecchio listino "chiuso" (`valido_al` nel passato) con la sua controparte in stock potrebbe ottenere un valore diverso da quello realmente applicato all'epoca.

**Cosa fanno i grandi ERP (verificato: SAP)**: le condizioni di prezzo (l'equivalente del listino) memorizzano **solo** il prezzo nativo e la sua validità — mai un "prezzo equivalente in unità di stock" derivato. Un listino chiuso mostra solo "10 €/KG, valido fino al [data]", senza alcun valore convertito accanto. Se serve sapere quale fosse il prezzo equivalente in FUSTO in quel periodo, la risposta storicamente corretta si trova nei **documenti** effettivamente emessi in quel periodo (che congelano il fattore riga per riga, come Quack già fa) — non nel listino, che non è mai pensato come registro storico-contabile.

**Decisione**: coerente con questo, **un valore convertito (es. "= 250 €/fusto") va mostrato solo per i listini ancora validi** (`valido_al` nullo o nel futuro), calcolato col fattore di conversione attuale — corretto, perché rappresenta la realtà di oggi. **Per un listino chiuso (`valido_al` nel passato), non va mai mostrato alcun valore convertito**: solo il prezzo nativo così come fu salvato. Nessuna colonna aggiuntiva da introdurre su `listini_prezzi` — il criterio si basa unicamente sul confronto tra `valido_al` e la data odierna, già disponibile.

**Specifica di implementazione**: ovunque nel frontend (es. `assets/js/sales-quotes.js`, funzioni che mostrano un prezzo convertito accanto a quello nativo) o nel backend (`SalesPricelistsController.php`) venga calcolato un valore "equivalente in stock" per una riga di `listini_prezzi`, va aggiunta la condizione: calcolare/mostrare il valore convertito solo se `valido_al IS NULL OR valido_al >= CURRENT_DATE`; per righe con `valido_al` nel passato, mostrare unicamente `prezzo_unitario` e la sua `unita_misura_id` nativa, senza alcuna conversione.

### 6.6 File2 — Fallback Pricing Hierarchy Vendite (righe 111-118) — eccezioni mancanti

> ✅ **RISOLTO** — entrambi i sotto-punti chiariti il 10/07/2026. Il sotto-punto A ha una soluzione da implementare (dettaglio sotto, non ancora scritta nel codice); il sotto-punto B era già risolto nel codice e serviva solo correggere la documentazione (fatto in `listini_architettura.md`).

**A. "Level 1 Copia Transazione" (da preventivo a ordine) con listino scaduto nel frattempo.**

- **Il problema**: un preventivo viene creato con un prezzo da un listino promozionale (es. "-20%, valido fino al 30/6"). Il cliente risponde tardi, il listino nel frattempo è scaduto e chiuso. Il preventivo viene comunque trasformato in ordine, ereditando lo sconto -20% che l'azienda non offre più a nessuno.
- **Cosa succede oggi in Quack (verificato)**: la funzione di conversione `qk_preventivo_conferma_ordine()` (`includes/sales_quotes.php:925`) copia `prezzo_unitario`/`sconto_perc` direttamente dalle righe del preventivo, senza mai ricontrollare la validità del listino di origine né la `data_scadenza` del preventivo stesso. La conversione è sempre permessa, sempre silenziosa — nessun avviso in nessun caso.
- **Cosa prevede il documento**: solo che i prezzi vengono "ereditati e congelati" — coerente col codice, ma non chiarisce se sia voluto anche per preventivi/listini scaduti.
- **Cosa fanno i grandi ERP**: qui congelare il prezzo è **corretto**, non un problema — un preventivo accettato è un impegno commerciale formale, onorarlo è il suo scopo, indipendentemente da cosa succede al listino nel frattempo (SAP fa lo stesso). Il vero rischio non è "il listino è scaduto", è **"il preventivo stesso è scaduto"** (ha superato la sua `data_scadenza`) — su quello sì, i grandi ERP avvisano.
- **Soluzione**: nessuna modifica alla logica di ereditarietà (è già corretta). Aggiungere un controllo sulla `data_scadenza` del preventivo al momento della conversione: se scaduta, mostrare un avviso non bloccante che richiede conferma esplicita, non un blocco secco.

**Specifica di implementazione**:
1. In `SalesQuotesController.php`, azione `conferma_ordine` (righe 341-345): prima di chiamare `qk_preventivo_conferma_ordine()`, controllare se `$quota['data_scadenza']` è valorizzata e nel passato rispetto a oggi.
2. Se scaduta e la richiesta non contiene un flag esplicito di conferma (es. `post('conferma_nonostante_scadenza') === '1'`), non procedere: mostrare invece un avviso all'utente ("Questo preventivo è scaduto il [data]. Vuoi comunque generare l'ordine con questi prezzi?") con un pulsante che ripete l'azione includendo quel flag.
3. Se il flag è presente, o se `data_scadenza` è vuota/futura, procedere come oggi, chiamando `qk_preventivo_conferma_ordine()` senza modifiche alla funzione stessa (la logica di ereditarietà prezzo resta invariata, corretta così com'è).

**B. Priorità tra promozioni multiple per lo stesso cliente — non è un problema, era solo la documentazione a non saperlo.**

Verificato: esiste già la tabella `clienti_listini` (collega un cliente a più listini contemporaneamente) con una colonna `priorita` (default 10) dedicata esattamente a questo. La query di ricerca prezzo (`SalesPricelistsController::getSalePrice()`, righe 563-577) ordina già `ORDER BY cl.priorita DESC, l.nome ASC` — sceglie sempre, in modo deterministico, il listino con priorità più alta tra quelli validi, con il nome come spareggio. Corretto e coerente con come SAP risolve lo stesso conflitto (priorità numerica tra condizioni). **Correzione applicata direttamente in `listini_architettura.md`** (la frase "un listino promozionale" al singolare è stata corretta per menzionare il meccanismo di priorità reale).

### 6.7 File2 — Fallback Pricing Hierarchy Acquisti (righe 120-126) — eccezione mancante

> ✅ **RISOLTO** — verificato il 10/07/2026: lo scenario descritto è impossibile con l'architettura reale, quindi non c'è nulla da correggere nel codice. Corretta solo la documentazione (`Listini nella formulazione offerte.md`).

**Level 1 "Offerta RFQ Approvata"**: non è trattato il caso in cui una RFQ riceve offerte da **più fornitori** per lo stesso prodotto e l'ordine d'acquisto finale viene generato scegliendo un fornitore diverso da quello "vincitore" originariamente selezionato in RFQ — quale prezzo/sconto eredita il PO in quel caso.

**Verificato nel codice**: "scegliere il vincitore" e "generare l'ordine" non sono due passaggi indipendenti che potrebbero divergere — sono la stessa azione. `qk_offerta_accetta()` (`includes/purchases_quotes.php:325-356`), nello stesso momento in cui accetta un'offerta, **rifiuta automaticamente tutte le altre offerte sulla stessa RFQ** (`UPDATE offerte_testata SET stato = 'rifiutata' WHERE rfq_id = ? AND id != ?`) e chiude la RFQ. La funzione che genera l'ordine (`qk_ordine_da_offerta()`, `includes/purchases_orders.php:162`) può partire solo da un'offerta già `accettata`, e ne verifica l'unicità (un solo ordine per offerta). Non esiste alcun percorso di codice in cui l'ordine possa finire a un fornitore diverso da quello accettato — l'alternativa è già stata rifiutata irreversibilmente nello stesso istante in cui il vincitore è stato scelto.

**Cosa fanno i grandi ERP**: stessa logica — in SAP, accettare l'offerta di un fornitore chiude la competizione in un solo passo, non in due fasi disgiunte che potrebbero contraddirsi.

**Correzione applicata**: solo alla documentazione (`Listini nella formulazione offerte.md`, riga 123), che descriveva il caso come se fosse possibile.

### 6.8 File2 — Markup Fallback (righe 128-138) — vedi C5/C9

> ✅ **RISOLTO** — nessuna modifica al codice necessaria (era già corretto). Aggiunta una nota esplicita e prominente in `Listini nella formulazione offerte.md` per proteggere questo comportamento da futuri refactoring accidentali.

Oltre alla contraddizione interna già segnalata in C5/C9, manca l'eccezione: cosa succede se il markup per **prodotto** viene impostato a **0** esplicitamente (per vendere a prezzo di costo)? Il codice controlla `!== null && !== ''` (`SalesPricelistsController.php:760`) quindi `0` è un valore valido che viene applicato — comportamento corretto, coerente con come i grandi ERP trattano un override numerico esplicito a zero (distinto da "campo vuoto"). Il rischio reale non era nel comportamento attuale, ma nel fatto che un futuro refactoring potrebbe sostituire quel controllo con un `if ($markup)` generico (che tratterebbe erroneamente `0` come falso/assente) — per questo la nota aggiunta alla documentazione è esplicitamente rivolta a chi toccherà questa logica in futuro, non solo descrittiva.

### 6.9 File2 — Selettore Listino di Testata (righe 142-150) — eccezioni mancanti

> ✅ **RISOLTO** — entrambi i sotto-punti verificati il 10/07/2026: nessuno richiedeva una modifica al codice, solo alla documentazione (`Listini nella formulazione offerte.md`, corretta direttamente).

- Soglia "meno di 15 / più di 15 listini" (riga 145-146) per passare da dropdown a autocomplete: **buco a esattamente 15** (né "meno di" né "più di" lo copre esplicitamente) — micro-ambiguità off-by-one da chiudere.
  **Verificato**: la soglia non esiste nel codice. Il selettore listino in testata è sempre autocomplete (`app/views/sales/quotes/_form.php:234`, commento esplicito "Sempre autocomplete come richiesto"), indipendentemente dal numero di listini — scelta più semplice e che evita proprio l'ambiguità che la soglia numerica avrebbe creato.
- **Override manuale + cambio UoM riga**: se l'utente fa override manuale del prezzo e poi cambia il toggle stock/commerciale della riga, il prezzo forzato manualmente si converte proporzionalmente o resta "congelato" nel valore digitato (in quale UoM)? Non trattato.
  **Verificato**: si converte correttamente. `setRowUomMode()` (`assets/js/sales-quotes.js:197-234`) converte sempre quantità e prezzo con `qkConvertUom()`, senza distinguere tra righe automatiche e manuali — corretto, perché cambiare l'unità di visualizzazione è un ricalcolo matematico dello stesso numero, non una nuova proposta di prezzo (quella sì viene bloccata in modalità "manual", per non farsi sovrascrivere dal server — verificato a `sales-quotes.js:1317`).

### 6.10 File3 `listini_architettura.md` §4 "Storicizzazione" (righe 51-64) — eccezioni mancanti

> ✅ **RISOLTO** — entrambi i sotto-punti chiusi il 10/07/2026. Il sotto-punto 2 era già coperto da 6.6/8J (rimando). Il sotto-punto 1, durante la verifica, ha portato alla scoperta di un **bug reale in produzione**, non solo di un gap di documentazione — corretto direttamente nel codice.

**Sotto-punto 2 (promozioni multiple)**: stesso problema del sotto-punto B di 6.6, già risolto da `clienti_listini.priorita` — vedi Sezione 8J. Nessun lavoro aggiuntivo qui.

**Sotto-punto 1 (sovrapposizione di validità) — la domanda originale era mal posta, e la verifica ha scoperto un bug più grave.**

- **Il problema originale**: non è specificata alcuna regola per il caso in cui due righe di `listini_prezzi` per lo stesso prodotto/listino/UoM abbiano range `valido_dal`/`valido_al` sovrapposti.
- **Prima scoperta (verificando lo schema)**: `listini_prezzi` **non ha affatto** le colonne `valido_dal`/`valido_al` — né in MySQL né in SQLite. La validità vive solo sulla testata (`listini.valido_dal`/`valido_al`), non sulla singola riga di prezzo. Questo è confermato come scelta di progetto attuale (le date per singola riga sono un'eventuale estensione futura, non implementata oggi). Quindi lo scenario "sovrapposizione di validità tra righe" **non può accadere** nella forma originariamente descritta — non esistono date per riga da sovrapporre.
- **Bug reale scoperto durante la verifica**: nonostante le colonne non esistano, `SalesPricelistsController::getSalePrice()` conteneva **tre query identiche** (righe ~580, ~629, ~682 prima della correzione) che filtravano `listini_prezzi` per `valido_dal`/`valido_al` come se quelle colonne esistessero. Con `PDO::ATTR_ERRMODE = ERRMODE_EXCEPTION` attivo globalmente (`includes/db.php:29,47`), questo genera un errore SQL **ogni volta** che la funzione viene eseguita. Il `try/catch` che lo intercetta (`apiOttieniPrezzo()`, riga 883) è attorno a **tutta** la funzione, non al singolo tentativo — quindi l'eccezione salta l'intera cascata di fallback (Level 2-7) e restituisce direttamente un errore JSON grezzo all'utente, invece di un prezzo. In pratica: **la ricerca del prezzo falliva sempre** per qualunque prodotto quando il cliente aveva un listino dedicato assegnato (`clienti_listini`), o quando veniva passato un `listino_id` esplicito, o quando si arrivava al fallback sul listino standard — tutti e tre i punti avevano la stessa query rotta.
- **Causa**: codice scritto anticipando una funzionalità futura (validità per singola riga di listino) mai effettivamente aggiunta allo schema — probabilmente copiato per analogia dal lato Acquisti, dove `prodotti_fornitori_prezzi` **ha davvero** le colonne `valido_dal`/`valido_al` ed è usata correttamente (`PurchasesController.php:384-385`). Il lato Vendite ha ereditato il pattern senza avere le colonne corrispondenti.
- **Correzione applicata**: rimosse le condizioni `valido_dal`/`valido_al` dalle tre query su `listini_prezzi` in `SalesPricelistsController.php` (righe ~580, ~629, ~682) — la validità del listino resta comunque controllata correttamente a livello di testata (`listini.valido_dal`/`valido_al`, già presente e corretta nelle query precedenti a ciascuna di queste tre).
- **Correzione aggiuntiva allo schema (risolve anche la domanda originale del sotto-punto 1)**: verificato che lo schema **SQLite** ha già un vincolo `UNIQUE (listino_id, prodotto_id, quantita_minima, unita_misura_id)` su `listini_prezzi` — impedisce a livello di database due righe prezzo contraddittorie per la stessa identica combinazione. Lo schema **MySQL** non aveva questo vincolo (disallineamento tra i due schemi gemelli). Aggiunto lo stesso vincolo `UNIQUE` a `db/init_mysql.sql` per allinearlo. Con questo vincolo, il caso "due prezzi per la stessa combinazione" non può più accadere in nessuno dei due database — è un vincolo di database, più robusto di qualunque guardia applicativa.
- **Nota operativa importante**: questa modifica allo schema (`db/init_mysql.sql`) si applica automaticamente solo a **nuove installazioni**. Un database MySQL già esistente in produzione richiede una migrazione separata (`ALTER TABLE listini_prezzi ADD UNIQUE (...)`) per ricevere lo stesso vincolo — non ancora eseguita, e da eseguire con cautela: se esistono già righe duplicate nel database live, l'`ALTER TABLE` fallirebbe finché non vengono ripulite manualmente.

### 6.11 File3 §3 "Acquisti" (righe 44-48) — eccezione mancante

Il "listino di default generato in background" per i prezzi d'acquisto presi "al volo" (senza listino esplicito): cosa succede se il fornitore associato viene disattivato o eliminato dopo che gli sono stati associati prezzi in questo modo? Il listino generato in background resta orfano — non trattato.

### 6.12 File4 `UI_UX_PREZZI.md` §1 "La Regola d'Oro (Sincronizzazione)" (righe 30-31) — eccezioni mancanti

- Tratta solo il caso "riga sincronizzata da import CSV, bloccata/non modificabile". **Non trattato**: cosa succede se l'utente elimina fisicamente quella riga bloccata (è permesso?) e se un successivo import CSV che non contiene più quel prodotto lascia orfana la riga precedentemente sincronizzata (va disattivata automaticamente o resta visibile indefinitamente?).
- **Asimmetria non giustificata**: il meccanismo di sincronizzazione/blocco da import massivo è descritto **solo per gli acquisti**; il caso equivalente per import massivo di listini vendita non è mai trattato, pur essendo presumibilmente un flusso analogo nell'ERP.

### 6.13 File4 §2 "Matrice Ibrida" (righe 58-59) — ambiguità semantica non risolta

*"Le celle vuote per un listino che non prevede quello scaglione devono limitarsi a ereditare visivamente il valore dello scaglione precedente (grigio chiaro, disabilitato simulato)"*. Il testo non chiarisce se questa **ereditarietà è solo un fatto visivo di anteprima in UI**, oppure se il motore di pricing backend applica la stessa logica quando cerca un prezzo per uno scaglione non esplicitamente definito in un ordine reale. Se le due logiche (frontend "eredita visivamente" vs backend "cerca lo scaglione più vicino") non sono esplicitamente allineate, un utente potrebbe vedere in anteprima un prezzo che il motore di pricing non applica realmente all'ordine — rischio di disallineamento UI/motore non discusso.

### 6.14 File4 §3 "RRP e Sottocosto" (riga 84) — eccezione sotto-specificata

Il cambio valuta USD→EUR per il controllo di sottocosto è menzionato **una sola volta**, senza specificare: provenienza del tasso di cambio, frequenza di aggiornamento, e soprattutto **l'ordine di applicazione** rispetto alla conversione UoM (se si converte prima la valuta e poi la UoM, o viceversa, il risultato numerico può differire per via del doppio arrotondamento — non essendo le due conversioni commutative sotto arrotondamento a 2-4 decimali).

### 6.15 File4 §1 "Scaglioni Volume" (drawer fornitore) — eccezione mancante

Non è specificata alcuna regola di unicità: cosa succede se due scaglioni vengono inseriti con la **stessa `quantita_minima`** ma prezzi diversi per lo stesso fornitore/prodotto (dato ambiguo/malformato)? Nessuna validazione descritta né trovata lato codice per questo caso specifico.

### 6.16 Trasversale — Comparabilità di scaglioni in UoM diverse

Nessuno dei 4 documenti specifica come il motore di pricing normalizza per il confronto due scaglioni (`quantita_minima`) espressi in **UoM diverse** sullo stesso prodotto/listino (es. uno scaglione a "100 KG" e un altro a "5 FUSTI" sullo stesso listino) — quale criterio stabilisce quale dei due si applica a una quantità data, dato che confrontare `100` con `5` senza convertirli preventivamente nella stessa unità produrrebbe un ordinamento privo di senso.

### 6.17 Trasversale — Resi e note di credito

> ✅ **RISOLTO** — decisione presa e aggiunta direttamente in `Versione Definitiva.md`, nuova Sezione 8E ("Gestione Resi e Note di Credito: Ereditarietà dello Snapshot"). Il testo sotto resta come traccia della domanda originale.

Nessuno dei 4 file tratta quale fattore/UoM si applica a un **reso parziale** di una riga d'ordine che a suo tempo aveva applicato una conversione UoM (es. reso di 10 kg su un ordine originario di 100 kg = 4 fusti) — se il reso eredita lo snapshot della riga originale (coerente col principio di immutabilità) o ricalcola su base corrente.

**Decisione presa (10/07/2026)**: il reso/nota di credito **eredita sempre lo snapshot della riga d'ordine originale referenziata** (`quantita_contenuto_snapshot`, `num_factor_snapshot`, `den_factor_snapshot`, `system_unit_price_stock`), mai ricalcolato sui dati prodotto correnti. Motivazione completa in Sezione 8E del file sorgente: coerenza con il principio di immutabilità già adottato ovunque, coerenza fisica (il fattore descrive un fatto vero al momento della vendita, non oggi), coerenza contabile (la nota di credito deve accreditare l'importo realmente fatturato), e allineamento alla prassi SAP (reso creato "con riferimento a" il documento originale, che eredita le sue condizioni). Lo schema Quack ha già l'aggancio necessario (`fatture_righe.ordine_riga_id`, tipo movimento `reso_cliente`); manca il flusso applicativo guidato che rispetti questa decisione quando verrà implementato.

---

## 7. Prossimi passi consigliati

1. ~~Correggere subito i due refusi/contraddizioni interne più concreti: la formula invertita in Sezione 8D (C1) e la doppia descrizione statica/dinamica del markup in File2 riga 132 vs 138 (C5/C9).~~ **✅ Fatto** — applicato direttamente ai file sorgente, insieme a C2, C4 e C10 (vedi banner "RISOLTO" in §3 e colonna "Stato correzione" in §4).
2. ~~Cancellare il codice di esempio non normativo (`OrderProcessor.php`, `UomConverter`) di PARTE1 §6.~~ **✅ Fatto** (**C6**) — cancellato, non spostato, dopo aver verificato che divergeva da 3 comportamenti reali del sistema, non solo dai nomi.
3. ~~Riscrivere PARTE2 §3B punto 3 di `Versione Definitiva.md` per riflettere l'architettura reale a due fasi (anteprima client-side, autorità server-side al salvataggio).~~ **✅ Fatto** (**C3**).
4. ~~Anticipare la decisione Approccio1/2 prima dello schema SQL con `product_uom_conversions`.~~ **✅ Fatto** (**C8**) — aggiunto un avviso in apertura della Sezione 4 di `Versione Definitiva.md`, con rimando alla Sezione 8D. Non ha richiesto la riorganizzazione completa in 3 file.
5. Verificare end-to-end il badge origine prezzo in RFQ (**C7**) prima di correggerne il tono in File2. — **Unico punto ancora aperto tra le 10 contraddizioni.**
6. Annotare esplicitamente come **limitazione nota** il tema Catch Weight (§6.1): è l'unica eccezione strutturale rilevante per il dominio chimico/ADR di Quack lasciata completamente scoperta da tutti e 4 i documenti.
7. Procedere comunque con il travaso in 3 file (§5), consigliato per le altre ragioni esposte lì (ridondanza R1-R8, materiale di ricerca generico mescolato alla specifica reale) anche se non più necessario per risolvere C8.
8. Colmare i punti del §6 (17 eccezioni puntuali, organizzate per paragrafo di origine), almeno con una riga di decisione ciascuno, prima di considerare la documentazione "fissata sulla pietra".
