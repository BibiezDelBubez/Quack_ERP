# AUDIT — Storico completo di tutto ciò che risulta scritto/modificato nel Ciclo Vendite

**Metodo**: nessun git (per istruzione esplicita). Ricostruito da tre fonti verificabili: (1) la mia conoscenza diretta di questa sessione, (2) i 3 documenti PIANO che descrivono interventi completati con checklist di verifica, (3) `audit/criticita_risolte.md` e `audit/scoperte_audit.md`, che tracciano un audit più ampio (non solo Vendite) condotto in sessioni precedenti.

**Limite onesto**: non tutto quello che è successo in questo progetto è stato fatto da "Claude" nel senso stretto. La checklist Fase 7 è stata eseguita da un secondo strumento IA ("Antigravity", nome usato esplicitamente in questa conversazione). Le sessioni precedenti a questa non sono nella mia memoria diretta: quanto segue è ricostruito da tracce scritte (commenti nel codice, PIANO, audit), non da ricordo. Dove la fonte non specifica quale sessione/strumento ha agito, lo segnalo come "non attribuibile con certezza".

---

## PARTE I — Questa sessione (conoscenza diretta, nessuna ricostruzione)

### File creati
1. `docs/Ciclo Vendite/REVISIONE - Analisi Documentazione Ciclo Vendite.md` — analisi completa della cartella Ciclo Vendite (contraddizioni, ripetizioni, lacune, §3bis eccezioni per fase).
2. `db/migrations/2026_07_22_aziende_interne_attivo.php`
3. `db/migrations/2026_07_22_ordini_clienti_magazzino_id.php`
4. `db/migrations/2026_07_22c_mittente_ddt_e_magazzino_spedizione.php`
5. `db/migrations/2026_07_22d_ordini_clienti_versione_e_superato.php`
6. `docs/Ciclo Vendite/PIANO - Revisione Ordini Clienti (Nuova Versione).md` — piano, **implementazione non completata** (interrotta per il problema di design su righe non modificabili, poi per questo audit).
7. `docs/Ciclo Vendite/AUDIT - Storico Completo Modifiche Ciclo Vendite.md` — questo file.

### File modificati (codice)
| File | Cosa ho cambiato | Verificato con esecuzione reale? |
|---|---|---|
| `includes/sales_quotes.php` | `require_once document_common.php` mancante; fallback `listino_id` 0→null in `qk_preventivo_conferma_ordine()`; aggiunto `magazzino_id` all'INSERT testata ordine, rimossa una chiamata duplicata a `qk_magazzino_attivo_id()` | Sì (script PHP diretti sul DB live, poi rimossi) |
| `includes/magazzino.php` | Nuova funzione `qk_rilascia_stock_ordine()` | Sì |
| `includes/sales_orders.php` | Riscritta `qk_ordine_cliente_annulla()` per rilasciare la riserva residua | Sì (controprova con due ordini paralleli) |
| `app/Controllers/SalesOrdersController.php` | Compliance check su `create()` (ordine diretto); guard overshipment server-side in `elaboraCreaSpedizione()`; numerazione spedizione/DDT da `COUNT(*)` a `qk_next_sequence_number()`; fix ADR nel ramo no-lotto (era sempre `is_adr=0`) | Sì, tutti e 4 |
| `app/Services/OrderCalculatorService.php` | Aggiunto `magazzino_id` a `$orderData`, rimossa chiamata duplicata a `qk_magazzino_attivo_id()` nel loop | Parziale — non ho testato esplicitamente il percorso ordine diretto con questa modifica |
| `app/Repositories/SalesOrderRepository.php` | Aggiunta colonna `magazzino_id` all'INSERT; fallback `listino_id` `?? 1` → `?? null` | **No, mai testato direttamente** — verificato solo per lettura di codice |
| `app/views/sales/shipments/_nuova.php` | Aggiunto campo nascosto `conferma_superamento` | Indirettamente (via test end-to-end sul controller) |
| `assets/js/sales-shipment-new.js` | Riscritta la validazione submit per il caso overshipment (conferma invece di blocco secco) | Sì (Node, 5 scenari) |
| `db/init_sqlite.sql` | Aggiunto commento+colonna `ordini_clienti.magazzino_id`; aggiunte `ordini_clienti.versione`/`ordine_padre_id`; esteso CHECK di `ordini_clienti.stato` con `'superato'` | Sì, sul DB live (dopo 2 tentativi falliti, vedi Audit 2 per l'analisi critica) |
| `docs/Backlog Sviluppi Futuri.md` | Aggiunta voce 34 (Reso Cliente e Correzione Spedizione) | N/A (documentazione) |

### Migrazioni **eseguite da me sul database live** in questa sessione
- Le 4 nuove elencate sopra.
- **`db/migrations/2026_07_20_destinatario_ddt_congelato.php`** — file preesistente (da un'altra sessione), **mai eseguito sul DB live prima d'ora**. L'ho eseguito io oggi.
- **`db/migrations/2026_07_14_aggiungi_azienda_denormalizzata_ordini_clienti.php`** — eseguita, riscontrato che era già applicata (no-op).

### Cosa NON ho completato
La funzione `qk_ordine_cliente_crea_revisione()` (Tappa 3 del piano "Nuova Versione") — **mai scritta**, mi sono fermato dopo aver scoperto che le righe ordine non sono editabili in nessuno stato, rendendo la funzione come progettata inutile allo scopo.

---

## PARTE II — Ricostruito da `docs/plans/PIANO - Motore Unificato di Transizioni di Stato.md` (22/07/2026, stato: eseguito per intero secondo il documento)

Piano nato da un punto della mia stessa REVISIONE (P3), esteso su richiesta esplicita a tutta l'app. Tocca **8 entità**, di cui 2 nel Ciclo Vendite (Preventivi, Ordini cliente). File toccati per la parte Vendite, secondo il documento:

- **Nuovi**: `app/Services/TransitionGuard.php`, `includes/transizioni_stati.php` (con `qk_transizioni_preventivi()`, `qk_transizioni_ordini_clienti()`).
- **`app/Controllers/SalesQuotesController.php`**: azioni `invia`/`rifiuta` ora guardate.
- **`includes/sales_quotes.php`**: `qk_preventivo_conferma_ordine()` ora richiede davvero `inviato` (prima accettava da qualunque stato — dichiarata "la vulnerabilità più grave trovata in tutto il piano"); `qk_update_offerta()`/`qk_insert_offerta()` ora validano anche il valore di arrivo dello stato; `qk_preventivo_nuova_versione()` corretta (bug UNIQUE che la rendeva sempre fallita, vedi sotto).
- **`app/views/sales/quotes/_form.php`**: rimosso un `<select name="stato">` libero con tutti e 6 gli stati selezionabili (bypassava per intero il motore di transizioni); riga 69 disaccoppiata per usare `puoTransire()` invece di un `in_array` duplicato.
- **`app/Controllers/SalesOrdersController.php`**: `edit()` e `action()` (completa/riapri/annulla) ora guardati; **`elaboraCreaSpedizione()` non controllava affatto lo stato dell'ordine** (si poteva creare una spedizione, e quindi "far ripartire" lo stato, su un ordine già completato/annullato) — dichiarata "la tappa più delicata" del piano.
- **`includes/sales_orders.php`**: nuove `qk_ordine_cliente_completa()`, `qk_ordine_cliente_riapri()`, `qk_ordine_cliente_annulla()` (versione PRIMA del mio fix odierno — senza rilascio riserva), `qk_ordine_cliente_assicura_modificabile()`, `qk_ordine_cliente_aggiorna_note()` — il controllo di stato spostato dal controller (violazione MVC) al model.

**Bug indipendenti dal motore di transizioni, scoperti e corretti durante l'esecuzione di questo piano (dichiarato "su richiesta esplicita dell'utente" per ciascuno)**:
- `qk_preventivo_nuova_versione()`: copiava anche `numero_preventivo` (UNIQUE) prima di correggerlo — falliva sempre. Corretto.
- (Lato Acquisti, non Vendite, ma stesso piano: `qk_ordine_da_offerta()`, `qk_rfq_nuova_versione()`, `_rfq_insert_righe()` — bug di conteggio placeholder SQL/colonne mancanti su `rfq_righe`.)

**Segnalato ma esplicitamente NON corretto, dichiarato fuori perimetro**: un **secondo** `<select name="stato">` libero in `_form.php` (righe 294-297 secondo il documento) — il documento stesso dice: *"Verificato che il campo esiste ancora così com'è, non è stato toccato... bypassando per intero TransitionGuard"*. **Non ho verificato se questo sia ancora vero oggi** — lo tratto in Audit 2.

---

## PARTE III — Ricostruito da `docs/Ciclo Vendite/PIANO - Uniformare Genere Grammaticale Stati Preventivo.md` (20/07/2026, stato: eseguito)

Corregge la discrepanza tra CHECK dello schema (maschile) e scritture nel codice (femminile). File toccati secondo il documento:
- `app/Controllers/SalesQuotesController.php` — azioni `invia`/`rifiuta`, tutte le letture `=== 'accettata'` ecc.
- `includes/sales_quotes.php` — `qk_preventivo_nuova_versione()`, `qk_preventivo_conferma_ordine()`.
- `app/views/sales/quotes/_form.php` — pipeline stati, bottone "Salva e invia" (scriveva `stato='inviata'` **bypassando l'azione `invia` del controller** — scoperta fatta durante questo stesso piano, non nell'audit iniziale).
- `app/views/sales/quotes/_list.php` — `$statiLabels`.
- `includes/sales_dashboard.php` + `app/views/sales/sales_dashboard.php` — KPI e badge.
- `app/Controllers/SalesPricelistsController.php:343` — bug funzionale indipendente dal genere, corretto nello stesso intervento: la query `NOT IN ('annullato','rifiutato')` non escludeva mai nulla perché il valore reale era femminile — **il conteggio "quanti preventivi usano questo listino" era gonfiato**.
- `includes/functions.php` — `prev_stato_badge()`, funzione condivisa scoperta solo in esecuzione (non censita nell'audit iniziale).

---

## PARTE IV — Ricostruito da `docs/Ciclo Vendite/PIANO - Congelare Destinatario sul DDT.md` (20/07/2026, stato: eseguito)

- `db/init_sqlite.sql` + `db/init_mysql.sql` — 10 colonne `destinatario_*` su `documenti_trasporto`.
- `includes/document_common.php` — nuove `qk_calcola_destinatario_ddt()`, `qk_resolve_destinatario_ddt_stampa()`.
- `app/Controllers/SalesOrdersController.php` — INSERT `documenti_trasporto` esteso con i 10 campi, copiati da `$order` (già in memoria, nessuna nuova query).
- `app/Controllers/SpedizioniController.php` (`ddtView()`) — rimosso il JOIN live per il destinatario.
- `app/views/sales/shipments/_ddt_view.php` — legge `$destinatario` invece che `$d`.
- `db/migrations/2026_07_20_destinatario_ddt_congelato.php` (nuova) — **mai eseguita sul DB live fino a questa sessione** (l'ho eseguita io oggi, vedi Parte I).

---

## PARTE V — Voci attribuibili al Ciclo Vendite trovate in `audit/criticita_risolte.md` / `audit/scoperte_audit.md`, non coperte dai PIANO sopra

Attribuzione a sessione specifica non sempre chiara dal testo — riporto quello che il file dichiara, senza inventare certezza sulla data/autore quando non esplicita.

- **Bug "Listino Fantasma" (10/07)**: prezzi da listini disattivati continuavano a essere usati come costo base — duplicato in 6 file, tra cui `includes/sales_quotes.php`, `includes/sales_orders.php`, `SalesPricelistsController.php`. Corretto centralizzando in 2 viste SQL (`v_prezzo_acquisto_base_fornitore`, `v_costo_acquisto_prodotti`).
- **Bug #16 Asimmetria Kit (10/07)**: la riserva per un Kit scriveva sulla riga `giacenze` del Kit stesso, lo scarico sui componenti — mai allineati. Corretto con `qk_riserva_stock_ordine()` kit-aware, sostituendo la vecchia logica in `includes/sales_orders.php` e `includes/sales_quotes.php`.
- **Lotti scaduti selezionabili (10/07)**: `SpedizioniController::nuova()` e `SalesOrdersController::apiLottiDisponibili()` ora escludono di default i lotti scaduti, con toggle esplicito.
- **Kit: nessun controllo disponibilità in spedizione per prodotti non a lotto (10/07, "fuori scope")**: `SalesOrdersController::elaboraCreaSpedizione()`, ramo non-lot-tracked, non controllava `giacenze.quantita_disponibile` prima di scaricare. Corretto.
- **`quantita_riservata` poteva andare sotto zero allo scarico (10/07)**: stesso metodo, clamp con `CASE WHEN`.
- **Rettifica manuale su prodotto multi-lotto (10/07)**: non è Vendite in senso stretto ma tocca `SalesOrdersController.php` nello stesso commit, per lo storico movimenti.
- **`lotti.codice_lotto` — crash certo (10/07)**: 2 occorrenze in `SalesOrdersController.php`, 1 in `SpedizioniController.php`, corrette insieme al resto del codebase.
- **`qk_next_sequence_number()` — transazione grezza chiusa con metodo PDO (22/07)**: bug scoperto **tramite la checklist Fase 7 eseguita da Antigravity**, bloccava la creazione di ogni documento — non specifico di Vendite (14 file la usano) ma scoperto e corretto nel contesto di questa cartella.
- **Tappa 3.2 (data imprecisata, prima di questa sessione) — `listino_id` fallback hardcoded a `1`**: il file dichiara questo bug **già corretto una volta** con "query dinamica `SELECT id FROM listini WHERE is_default=1 AND attivo=1`". **Nota mia**: ho trovato e corretto io stesso, in questa sessione, un fallback `0`/`1` hardcoded praticamente identico in due punti (`sales_quotes.php`, `SalesOrderRepository.php`) — il bug sembra essere stato reintrodotto (o non tutti i punti erano stati coperti la prima volta). Non ho verificato quale delle due letture sia esatta.
- **Compliance check assente su ordine diretto**: segnalato in `scoperte_audit.md` Tappa 3.2 come ancora aperto al 09/07. **L'ho corretto io oggi** (Parte I) — quindi tra il 09/07 e oggi nessuno lo aveva chiuso, nonostante fosse già in un elenco di criticità aperte con priorità 🔴 ALTO.
- **Snapshot indirizzo mancante su preventivi/ordini (data imprecisata)**: dichiarato corretto tramite `qk_upsert_document_address()`/`qk_upsert_document_contact()`.
- **Asimmetria colonne `azienda_*` tra `preventivi` e `ordini_clienti` (14/07)**: `ordini_clienti` non le aveva, `preventivi` sì — corretto aggiungendole a `ordini_clienti`.
- **`ordini_clienti_righe_lotti` — tabella mai esistita**: un audit precedente (09/07) l'aveva erroneamente segnata come "presente e confermata" — errore di verifica riconosciuto e corretto il 10/07. Rilevante perché **anche il Manuale_Definitivo_Ciclo_Vendite.md descrive questa tabella come esistente** — la mia REVISIONE (§1.1) l'ha segnalato come contraddizione, senza sapere che l'errore era già stato tracciato altrove.
- **`ProductsController.php` riga ~1975, uso di `prodotto_classificazioni_adr`**: non è Vendite ma è il dato che il fix ADR di oggi (Parte I) legge in lettura.

---

## Riepilogo numerico

- File di codice del dominio Vendite risultano modificati da **almeno 4 iniziative distinte** oltre a questa sessione (Motore Transizioni, Genere Grammaticale, Destinatario DDT, più singole voci sparse nell'audit generale) — coprendo un arco temporale dal 29/06 al 22/07/2026.
- Migrazioni sul DB live relative a Vendite trovate **scritte ma mai eseguite** prima di questa sessione: almeno 2 (`2026_07_20_destinatario_ddt_congelato.php`, e implicitamente tutte quelle collegate a colonne aggiunte a `init_sqlite.sql` senza una migrazione dedicata, come `aziende_interne.attivo` e i campi mittente/magazzino spedizione).
- Almeno **2 bug dichiarati "corretti" in un audit precedente sono stati ritrovati non-corretti** da me o da un piano successivo: `listino_id` hardcoded, compliance check su ordine diretto.
- Un punto è dichiarato esplicitamente **scoperto e mai corretto per scelta**: il secondo `<select name="stato">` libero in `_form.php` — da verificare nell'Audit 2 se esiste ancora.
