# Ciclo Vendite — Flusso Operativo

Percorso utente end-to-end **Preventivo → Ordine → Spedizione → DDT → Fatturazione**, stile "cosa vede l'utente / cosa fa il backend / cosa cambia nel DB" per ogni passo — stesso schema già usato per Ciclo Acquisti e Gestione Magazzino e Lotti. Questo è il documento principale del ciclo vendite: descrive il comportamento reale del codice a oggi (26/07/2026), verificato riga per riga contro `db/init_sqlite.sql`, `includes/sales_quotes.php`, `includes/sales_orders.php`, `includes/correzioni_ordini.php`, `includes/document_common.php`, `app/Controllers/SalesQuotesController.php`, `SalesOrdersController.php`, `SpedizioniController.php`, `app/Services/SpedizioneCalculatorService.php` — non le versioni proposte o mai implementate che comparivano nei documenti sorgente superati (`Manuale_Definitivo_Ciclo_Vendite.md`, `Revisione_ciclo_vendite.md`).

Per lo schema dati completo (colonne, tabelle, funzioni) vedi il documento B (*Schema Dati e API*). Per indirizzi/contatti (fotografia anagrafica sui documenti) vedi `Guide Anagrafiche Clienti, Fornitori, Indirizzi/00 - Anagrafica Unica...md` §4/§3.5 — non ripetuto qui. Per prezzi/UoM/listini vedi `Unita di misura e prezzi/01 - Ciclo Multi-UoM e Prezzi - Guida Completa.md` — non ripetuto qui. Per le lacune note e il backlog vedi la sezione finale di questo documento (§6).

**Architettura di base**: il ciclo ruota attorno a **4 moduli/tabelle separate** — `preventivi/righe`, `ordini_clienti/righe`, `spedizioni/righe` (+ `documenti_trasporto/righe`) — non un singolo record con campo stato. Stesso modello a entità distinte già usato lato Acquisti (RFQ/Offerta/Ordine/Ricezione). La fatturazione **non** è un'entità a sé in uso reale: è solo un flag su `ordini_clienti.stato_fatturazione` (vedi §5).

---

## 1. Fase A — Preventivo

### 1.1 Creazione (stato `bozza`)

**Cosa vede l'utente**: form di nuovo preventivo — cliente, azienda interna emittente, indirizzo/contatto di consegna, condizioni di pagamento, valuta, righe (prodotto, quantità, UoM, prezzo).

**Cosa fa il backend**: `qk_insert_offerta()` (`includes/sales_quotes.php:274`, il nome interno della funzione resta legato al vecchio termine "offerta" usato anche per il ciclo acquisti — vedi nota terminologica sotto) inserisce `preventivi` + `preventivi_righe` in un'unica transazione, ricalcola i totali lato server (`qk_calcola_riga_offerta()`, `:183`) e fotografa indirizzo/contatto scelti in `indirizzi_documenti`/`contatti_documenti`. `data_scadenza` viene impostata di default a +6 mesi dalla creazione.

**Cosa cambia nel DB**: `preventivi.stato = 'bozza'`. Nessun impatto su giacenze.

**Nota terminologica** (già segnalata in `Guide Anagrafiche/00`, §5): nel codice la tabella si chiama `preventivi`, ma alcune funzioni storiche portano ancora il nome `offerta` (`qk_insert_offerta()`, `qk_update_offerta()`) — stesso concetto, nome legacy mai rinominato. Da non confondere con `offerte_testata`, tabella del tutto diversa lato Acquisti (l'offerta che un *fornitore* fa a noi).

**Suggerimento prezzo**: stesso algoritmo a cascata già descritto in `Unita di misura e prezzi/01`, non ripetuto qui — sempre un suggerimento precompilato, mai un valore imposto.

**RRP e margine in tempo reale sulla riga** (concetto RRP/sottocosto già descritto in `Unita di misura e prezzi/01` §10 — qui solo la meccanica UI specifica di preventivo/ordine, mai documentata come stato di fatto prima d'ora): la tabella righe preventivo mostra, oltre ai campi standard, le colonne Costo/Range consigliato/Delta (`app/views/sales/quotes/_form.php`), popolate dal catalogo prodotti con badge Min/Max RRP e Costo. Il box "Margine %" in fondo al preventivo usa un semaforo a tre fasce (verde/giallo/rosso) calcolato da `qkMarginSemaforo()` (`assets/js/margin-semaforo.js`), funzione condivisa estratta il 24/07/2026 (`PIANO - Soglie Margine Configurabili (§1.4).md`) per unificare due implementazioni prima divergenti: il preventivo aveva già tre fasce ma con soglie hardcoded (20/10), l'ordine diretto (`assets/js/sales-orders-create.js`) aveva solo un binario verde/rosso. Oggi entrambi i form usano la stessa funzione e le stesse soglie, lette a runtime da `Impostazioni` (chiavi `margine_soglia_verde`/`margine_soglia_giallo`, default `20.00`/`10.00`, configurabili da `SettingsController::generali()` — `db/init_sqlite.sql:2054-2055`), non più scritte nel codice sorgente. Il box "Margine €" (solo segno positivo/negativo) resta invece volutamente binario in entrambi i form — è una metrica di segno, non di fascia di qualità.

### 1.2 Invio al cliente (`bozza` → `inviato`)

**Cosa vede l'utente**: pulsante "Invia" sul preventivo in bozza.

**Cosa fa il backend**: `qk_preventivo_invia()` (`includes/sales_quotes.php:805`) — verifica lo stato di partenza tramite `App\Services\TransitionGuard` + `qk_transizioni_preventivi()` (`includes/transizioni_stati.php:29-53`) prima di scrivere, poi `UPDATE preventivi SET stato = 'inviato'`.

**Cosa cambia nel DB**: `stato = 'inviato'`. Nessun impatto su giacenze — è ancora solo carta.

**Guard server-side reale, non solo un filtro nel form**: la mappa di transizione ammette solo `bozza → {bozza, inviato}` — un POST diretto costruito a mano non può più forzare uno stato arbitrario (fix del 22/07/2026, vedi §3 sotto per la storia del bug che questo ha chiuso). Il form (`app/views/sales/quotes/_form.php`) non mostra più nemmeno una select libera dello stato: lo stato è sola lettura, si cambia solo dai bottoni azione guardati.

### 1.3 Preventivo inviato: cosa è ancora modificabile

Una volta `inviato`, il preventivo **non è più modificabile** — righe e prezzi inclusi: `qk_update_offerta()` (`includes/sales_quotes.php:543`) verifica lo stato di partenza e rifiuta qualunque `UPDATE` se diverso da `bozza`, indipendentemente da cosa fa o non fa il controller a monte (`SalesQuotesController::edit()`, guard doppio controller+funzione). Se serve cambiare qualcosa prima ancora di una risposta del cliente, l'unica via è "Crea Nuova Versione" (§1.7).

### 1.4 Accettazione e creazione Ordine (`inviato` → `accettato`)

**Cosa vede l'utente**: pulsante "Accetta e Crea Ordine" sul preventivo `inviato`. Se il magazzino di evasione non è stato ancora scelto esplicitamente, un campo obbligatorio lo richiede accanto al bottone (§1.5). Se la disponibilità a magazzino risulta insufficiente per una o più righe, compare una modale di conferma esplicita (§1.6) — mai un blocco.

**Cosa fa il backend**: `SalesQuotesController::action()`, caso `conferma_ordine` (`:289-330`) — valida il magazzino esplicito (`qk_valida_magazzino_ordine_esplicito()`), ricontrola la disponibilità server-side (`qk_preventivo_verifica_disponibilita()`, `includes/sales_quotes.php:1040`, mai fidandosi solo del pre-check AJAX lato client), poi chiama `qk_preventivo_conferma_ordine()` (`:1084`) che nella stessa transazione: marca il preventivo `accettato`, riserva lo stock (`qk_riserva_stock_ordine()`), e genera `ordini_clienti`/`ordini_clienti_righe` tramite l'unico punto di scrittura condiviso `qk_ordine_cliente_salva()` (vedi §2.1 — stesso percorso usato anche dall'ordine diretto, unificato il 26/07/2026).

**Cosa cambia nel DB**: `preventivi.stato = 'accettato'` (stato terminale: la mappa di transizione non ammette alcuna uscita da `accettato`, §1.3/§3), nuova riga `ordini_clienti` con `preventivo_id` valorizzato, `giacenze.quantita_riservata` incrementata.

### 1.5 Magazzino di evasione: sempre esplicito, mai un default silenzioso

Il campo magazzino accanto al bottone "Accetta e Crea Ordine" è **obbligatorio** — non c'è un fallback silenzioso sul magazzino attivo di sessione. Prima di questo fix (25/07/2026), la riserva usava il magazzino attivo in sessione al momento della conferma, che poteva essere diverso da quello con cui l'operatore stava davvero lavorando la pratica se nel frattempo aveva cambiato sede in un'altra scheda. Il valore scelto qui viene congelato su `ordini_clienti.magazzino_id` — mai più ricalcolato, nemmeno al momento di un eventuale annullamento (§2.3).

### 1.6 Vendita allo scoperto: mai un blocco, sempre una conferma esplicita e tracciata

Se una o più righe del preventivo superano la disponibilità reale a magazzino, il sistema **non blocca mai** la vendita — coerente con la scelta commerciale che un ordine può sempre essere preso anche senza scorta immediata (l'azienda può riapprovvigionarsi). Compare invece una richiesta di conferma esplicita (bottone dedicato nella modale, non una checkbox pre-spuntata), e solo dopo quella conferma l'ordine viene creato. Chi ha confermato, quando, e il dettaglio delle righe scoperte al momento vengono registrati su `ordini_clienti.vendita_scoperto_confermata_da`/`_at`/`_dettaglio` (JSON snapshot) — un audit trail vero, non solo un'eccezione silenziosa passata.

### 1.7 Nuova Versione (correzione di un preventivo `inviato`/`rifiutato`/`scaduto`/`annullato`)

**Cosa vede l'utente**: bottone "Crea Nuova Versione", mostrato solo per preventivi in questi quattro stati — mai per `bozza` (già modificabile direttamente) o `accettato` (terminale, ha già generato un ordine).

**Cosa fa il backend**: `qk_preventivo_nuova_versione()` (`includes/sales_quotes.php:858`) — marca l'originale `annullato` e crea una copia con `versione+1`, `preventivo_padre_id` collegato, snapshot di indirizzo/contatto clonati (non condivisi) con l'originale.

**Bug noto, non ancora corretto**: la funzione copia anche `numero_preventivo` (colonna `UNIQUE`) nel nuovo INSERT prima di rinominarlo — fallisce sempre con `UNIQUE constraint failed`. Il bottone "Crea Nuova Versione" è oggi **non funzionante**. Segnalato, in attesa di decisione separata (non affrontato in questa riscrittura documentale, che descrive lo stato del codice così com'è, bug incluso).

### 1.8 Rifiuto (`inviato` → `rifiutato`)

**Cosa fa il backend**: `qk_preventivo_rifiuta()` (`includes/sales_quotes.php:827`), stesso guard di stato di `qk_preventivo_invia()`.

### 1.9 Scadenza: calcolata a runtime, mai scritta su `preventivi.stato`

`preventivi.data_scadenza` esiste, ma **nessun automatismo esterno** (cron/scheduled task) la confronta con la data odierna — scelta deliberata (26/07/2026: un job esterno alla webapp è invisibile a chi subentra sul progetto, e se smette di girare nessuno se ne accorge). Il confronto è centralizzato in una vista SQL, `v_preventivi_stato_effettivo`, consultata ovunque serva mostrare/filtrare lo stato "vero" (liste, dashboard, blocco delle azioni su un preventivo scaduto) — `preventivi.stato` in colonna grezza non diventa mai `'scaduto'` da nessun percorso di scrittura (coerente con la mappa di transizione, dove `scaduto` compare come stato raggiungibile solo in teoria).

### 1.10 Compliance vendita (restrizioni cliente/prodotto)

Ogni salvataggio (creazione e modifica in bozza) passa da `qk_compliance_check_sale()` (`includes/compliance.php:25`, chiamata da `SalesQuotesController::checkComplianceOrWarn()`, `:373-378`, condiviso tra `create()` e `edit()`): se il cliente non ha un'autorizzazione valida per un prodotto con restrizione, il preventivo in bozza mostra solo un avviso (non blocca — può essere ancora sistemato), ma un documento non più in bozza blocca il salvataggio con un errore `_global`. Stesso controllo, reso bloccante senza eccezioni, sull'ordine diretto (§2.2) e sull'ordine da preventivo (che eredita compliance già verificata sul preventivo di origine).

### 1.11 Prodotto o cliente disattivato con un documento in corso

L'autocomplete di ricerca prodotti/clienti filtra sempre `attivo = 1` — non si possono *aggiungere* prodotti o clienti disattivati a un nuovo preventivo. Ma una bozza che già contiene un prodotto (o un cliente) disattivato successivamente **si salva e si invia senza alcun avviso**: comportamento voluto ("fallisci tardi", stesso principio già adottato per fornitori disattivati lato Acquisti/Prezzi), non un bug — ma da tenere presente.

---

## 2. Fase B — Ordine Cliente

### 2.1 Due percorsi di creazione, un solo scrittore

Un ordine cliente nasce in due modi — diretto, o da un preventivo accettato (§1.4) — ma da fine luglio 2026 entrambi passano dalla stessa identica funzione di persistenza, `qk_ordine_cliente_salva()` (unificazione completata il 26/07/2026, vedi `PIANO - Unificazione Creazione Ordine Cliente.md` per il dettaglio): prima erano due `INSERT` manuali indipendenti (uno in `SalesOrderRepository::insertOrderHeader()`, uno in `qk_preventivo_conferma_ordine()`), stesso rischio di entrambi dimenticare una colonna se lo schema cambia, ora un solo punto da mantenere.

**Cosa vede l'utente (percorso diretto)**: form "Nuovo Ordine" — stessa struttura del preventivo, ma senza passare da uno stadio di trattativa.

**Cosa fa il backend**: `OrderCalculatorService::insertDirectOrder()` → `qk_ordine_cliente_salva()`, con `preventivo_id` nullo. Compliance (§1.10), vendita allo scoperto (§1.6) e magazzino esplicito (§1.5) si applicano identici anche qui — nessuna scorciatoia per il percorso diretto.

### 2.2 Stati e transizioni

`ordini_clienti.stato` (CHECK: `in_lavorazione`, `parzialmente_spedito`, `spedito`, `completato`, `annullato`, `superato` — `db/init_sqlite.sql:1309-1311`) segue transizioni guardate da `App\Services\TransitionGuard` + `qk_transizioni_ordini_clienti()`, stesso motore già visto per i preventivi. `completa`/`riapri` verificano esplicitamente lo stato di partenza (`completa` ammessa da `in_lavorazione`/`parzialmente_spedito`/`spedito`; `riapri` solo da `completato`) — prima di un fix del 22/07/2026 potevano essere richiamate su qualunque stato, incluso un ordine già `annullato`.

### 2.3 Annullamento: rilascio corretto della sola quota residua

**Cosa fa il backend**: `qk_ordine_cliente_annulla()` (`includes/sales_orders.php`) rilascia, nella stessa transazione dell'annullamento, solo il **residuo non spedito** di ogni riga (`quantita_stock * (quantita_ordinata - quantita_spedita) / quantita_ordinata`) — mai il totale originario, mai la parte già uscita per davvero. Il rilascio usa sempre `ordini_clienti.magazzino_id` congelato alla creazione (§1.5), mai il magazzino attivo di sessione al momento dell'annullamento. Ordini storici con `magazzino_id` NULL (creati prima di questa colonna): rilascio saltato riga per riga con un log esplicito, annullamento comunque permesso.

### 2.4 Revisione di un ordine non ancora fatturato ("Crea Nuova Versione")

**Cosa vede l'utente**: bottone "Crea Nuova Versione" su un ordine `in_lavorazione`, mirror esatto del pattern già visto per i preventivi (§1.7) e per gli ordini d'acquisto.

**Cosa fa il backend**: `qk_ordine_cliente_crea_revisione()` (`includes/sales_orders.php:362-506`) — permessa solo da `in_lavorazione`, **bloccata** se esiste già una spedizione collegata o se l'ordine è marcato `stato_fatturazione = 'fatturato'` (blocco assoluto, deciso deliberatamente — vedi §2.5 per cosa succede in quel secondo caso). L'originale passa a `superato` (mai più modificabile), la nuova versione (`versione+1`, `ordine_padre_id` collegato) riparte da `in_lavorazione` con ri-esecuzione del controllo compliance.

### 2.5 Correzione di un ordine già fatturato: richiesta con approvazione (maker-checker)

Se l'errore su un ordine emerge **dopo** che è stato marcato `stato_fatturazione = 'fatturato'`, "Crea Nuova Versione" resta bloccato per sempre (§2.4) — ma dal 26/07/2026 esiste un percorso dedicato, distinto e tracciato, invece del vuoto totale di prima.

**Cosa vede l'utente**: bottone "Richiedi Correzione" sulla scheda ordine (visibile solo se `stato_fatturazione = 'fatturato'`) → form dedicato (righe dell'ordine con checkbox di selezione, quantità/prezzo corretti, motivo libero facoltativo). Una volta inviata, la richiesta compare in un riepilogo sulla stessa scheda ordine, con stato e, se già gestita, l'esito.

**Cosa fa il backend**: `qk_correzione_ordine_richiedi()` (`includes/correzioni_ordini.php`) crea una riga in `richieste_correzione_ordine` + una per riga in `richieste_correzione_righe`, stato iniziale `in_approvazione`. **Nessuna modifica ai dati dell'ordine avviene a questo punto** — è solo una richiesta.

**Approvazione o rifiuto**: `qk_correzione_ordine_approva()` richiede sempre un `riferimento_doc_esterno` (es. il numero della nota di credito emessa dal commercialista fuori da Quack — Quack non emette mai fatture elettroniche, vedi §5) prima di accettare; `qk_correzione_ordine_rifiuta()` richiede sempre un motivo. Entrambe scrivono una riga di log in `log_stati_ordini` (tabella preesistente, usata per la prima volta proprio da questo flusso).

**Maker-checker**: se l'impostazione `maker_checker_correzioni_attivo` è attiva (default: disattiva — un solo utente basta), chi ha creato la richiesta non può approvarla lui stesso, a meno di avere il permesso `bypass_maker_checker` (oggi solo il ruolo `admin`). Con l'impostazione disattiva, qualunque utente con permesso `approvare_correzioni` (oggi solo `admin`) può gestire qualunque richiesta, incluse le proprie.

**Cosa NON fa questo flusso, per scelta esplicita (26/07/2026)**: non impedisce a nessuno di togliere il flag `stato_fatturazione = 'fatturato'` con un click (`qk_ordine_cliente_segna_da_fatturare()` resta un `UPDATE` senza alcun controllo di permesso) e poi usare "Crea Nuova Versione" aggirando questo intero flusso — falla nota, lasciata così per decisione esplicita dell'utente, non per dimenticanza.

### 2.6 Modifica di un ordine confermato: solo note e data di consegna prevista

Al di fuori dei percorsi guardati sopra (annullamento §2.3, revisione §2.4, correzione post-fatturazione §2.5), un ordine cliente non è editabile in senso pieno in nessuno stato: `qk_ordine_cliente_aggiorna_note()` (`includes/sales_orders.php:482`) permette di cambiare solo `note` e `data_consegna_prevista` — bloccato con `qk_ordine_cliente_assicura_modificabile()` se l'ordine è già `completato` o `annullato` (guard riusato anche da altre azioni, verifica che l'ordine possa transire verso `'annullato'` come proxy di "non è già in uno stato terminale"). Le righe (`ordini_clienti_righe`: prodotto, quantità, prezzo) **non sono modificabili in nessuno stato** — non per un controllo esplicito pensato per quel caso, ma per assenza totale di un percorso di editing sulle righe. In pratica, l'unico modo di correggere un errore su una riga (prima della fatturazione) è annullare l'ordine (§2.3) e ricrearlo, oppure usare "Crea Nuova Versione" (§2.4) se ancora `in_lavorazione`.

---

## 3. Nota storica — bug di transizione di stato, chiusi il 22/07/2026

Prima dell'introduzione del motore di transizioni comune (`App\Services\TransitionGuard`), le azioni `invia`/`rifiuta` sui preventivi non controllavano affatto lo stato di partenza: un POST diretto poteva portare qualunque stato a `inviato`/`rifiutato`, incluso un `accettato` che aveva già generato un ordine. Un secondo percorso, più subdolo, permetteva la stessa cosa dalla UI: il form di modifica aveva una `<select>` libera con tutti gli stati selezionabili a mano, scritta da `qk_update_offerta()` senza validare il valore di *arrivo* (solo quello di partenza). Entrambi i percorsi sono stati chiusi nello stesso intervento: la mappa dichiarativa `qk_transizioni_preventivi()` (§1.2) più la rimozione della select libera dal form (stato oggi mostrato in sola lettura). Riportato qui solo come nota storica — il comportamento descritto nei paragrafi precedenti è già quello corretto.

---

## 4. Fase C — Spedizione

### 4.1 Casi supportati

Un ordine può essere evaso in un'unica spedizione completa, oppure in più spedizioni parziali nel tempo (l'operatore indica, per ogni riga, la quantità pronta per quella partenza — lo stato dell'ordine transita a `parzialmente_spedito`, poi a `spedito` quando ogni riga ha spedito ≥ ordinato, ricalcolato dopo ogni spedizione). Per i prodotti tracciati a lotti, la stessa riga può essere coperta da più lotti diversi (split multi-lotto: es. 100 kg ordinati, 60 dal Lotto A e 40 dal Lotto B).

### 4.2 Creazione (`GET /sales/shipments/new?order_id=X`)

**Cosa vede l'utente**: pagina "Nuova Spedizione" — per ogni riga con residuo da spedire, un campo quantità semplice (prodotto senza tracciamento lotti) oppure un blocco lotti pre-compilato (prodotto tracciato). Il magazzino di spedizione è un campo esplicito e obbligatorio (§4.3), mai un default silenzioso.

**Cosa fa il backend**: `SpedizioniController::nuova()` (`app/Controllers/SpedizioniController.php:62-132`) carica le righe con residuo (`qk_get_righe_ordine_da_spedire()`, `includes/sales_orders.php:973`, residuo espresso in Stock UoM per un confronto corretto anche per prodotti in unità commerciale diversa da quella di stock) e, per ogni prodotto tracciato a lotti, i lotti disponibili nel magazzino scelto (`qk_get_lotti_disponibili_per_prodotti()`, `includes/magazzino.php:1667`), pre-allocati FEFO (First Expired, First Out) lato server — l'operatore vede già una proposta compilata, non parte da zero.

**Lotti scaduti**: esclusi di default dalla pre-allocazione automatica; un toggle esplicito "Includi lotti scaduti" (`?includi_scaduti=1`) li rende disponibili per i soli casi eccezionali (smaltimento, reso) — mai un default silenzioso.

**Prodotto tracciato senza nessun lotto disponibile**: la riga non sparisce dalla proposta — resta visibile con un avviso esplicito ("Nessun lotto disponibile in magazzino per questo prodotto", `app/views/sales/shipments/_nuova.php:179-183`), verificato con un test HTTP reale il 26/07/2026 (login vero, pagina caricata davvero, avviso presente nell'HTML restituito). Non è possibile spedire senza lotto un prodotto tracciato — corretto — ma l'operatore viene sempre avvisato, non lasciato a scoprirlo da solo confrontando le quantità.

### 4.3 Magazzino di spedizione: campo esplicito

`magazzino_id` è obbligatorio sul form — un `throw` blocca il salvataggio se manca (`app/Services/SpedizioneCalculatorService.php:606-608`). Il default proposto è il magazzino congelato sull'ordine (`ordini_clienti.magazzino_id`, §2 sopra), non il magazzino attivo di sessione — che potrebbe essere cambiato tra la creazione dell'ordine e la spedizione, lasciando altrimenti la riserva "fantasma" nel magazzino originale. L'operatore può comunque cambiarlo esplicitamente se la merce non è più lì; in quel caso il rilascio della riserva sul magazzino originale e lo scarico fisico sul magazzino scelto vengono gestiti separatamente.

### 4.4 Blocchi e conferme al salvataggio

- **Superamento quantità (overshipment)**: un controllo server-side reale confronta ogni riga contro il residuo (`quantita_ordinata - quantita_spedita`) e blocca con un'eccezione esplicita se non arriva una conferma (`conferma_superamento=1` nel POST) — stesso principio "mai un blocco secco, sempre una conferma esplicita" già visto per la vendita allo scoperto (§1.6). Verificato in `SpedizioneCalculatorService.php:111-134`.
- **Giacenza insufficiente**: controllo completo e bloccante (nessuna conferma possibile, a differenza del superamento quantità) — lotto per lotto per i prodotti tracciati (`:820`), su `giacenze` per i non tracciati (`:869`), sui componenti per i kit (`:853`).
- **Componenti Kit tracciati a lotto**: selezionati via FEFO (`qk_seleziona_lotti_fefo()`, `includes/magazzino.php`, stessa logica di esclusione-scaduti della pre-allocazione §4.2, estratta per essere condivisa) e decrementati su `lotti_giacenze` con `lotto_id` registrato sul movimento/DDT — stesso trattamento dei prodotti standalone tracciati. Prima di un fix (AUDIT 3 §3.2, corretto e verificato con esecuzione reale), i componenti Kit lot-tracked scaricavano solo `giacenze` in aggregato, senza mai toccare `lotti_giacenze` né registrare un `lotto_id`: un richiamo fornitore su un lotto specifico non avrebbe trovato quelle spedizioni — rottura di tracciabilità rilevante per un distributore ADR, non solo un'imprecisione contabile.
- **Posting block inventario**: verificato presente per righe semplici (`:772`) e per componenti kit (`:845`).

### 4.5 Aggiornamento giacenze e stato ordine

Alla conferma: `lotti_giacenze.quantita_disponibile` decrementata per il lotto prelevato, `giacenze.quantita_disponibile`/`quantita_riservata` decrementate per il prodotto, `ordini_clienti_righe.quantita_spedita` incrementata. Lo stato dell'ordine viene ricalcolato su tutte le righe dopo ogni spedizione (§2.2): `spedito` se ogni riga ha spedito ≥ ordinato, `parzialmente_spedito` se almeno una riga ha spedito > 0, altrimenti resta `in_lavorazione`.

### 4.6 Spedizione su un indirizzo diverso da quello dell'ordine: non ancora possibile

Il campo per l'indirizzo della singola spedizione esiste già a schema (`spedizioni.indirizzo_spedizione_id`), ma oggi è sempre popolato con lo stesso valore congelato sull'ordine, mai lasciato scegliere — il form "Nuova Spedizione" non ha alcun campo indirizzo. Un ordine che deve arrivare in due sedi diverse (es. 60 unità a Milano, 40 a Bari) non è oggi realizzabile con un solo ordine. Rimandato al backlog (voce 35) il 26/07/2026 — piano dettagliato lì, non ripetuto qui.

### 4.7 Correzione o annullamento di una spedizione già salvata: non esiste

Non c'è nessuna azione per annullare o eliminare una spedizione già confermata: giacenze scaricate, `quantita_spedita` incrementata, DDT emesso, tutto definitivo. Un errore di spedizione (lotto sbagliato, quantità sbagliata) si corregge solo manualmente con un movimento di magazzino generico — che non riallinea da solo `quantita_spedita` né lo stato dell'ordine. Deciso il 22/07/2026 di non costruire un flusso guidato ora (stesso trattamento già scelto per il reso a fornitore lato Acquisti) — dettaglio in [Backlog Sviluppi Futuri.md, voce 34](../Backlog%20Sviluppi%20Futuri.md#34-reso-cliente-e-correzione-di-una-spedizione-gi%C3%A0-confermata). Gli stati `consegnata`/`reso` esistono nel CHECK di `spedizioni.stato` ma sono oggi irraggiungibili: nessuna azione di codice li imposta mai — resteranno morti finché non si costruirà quel flusso.

---

## 5. Fase D — DDT (Documento di Trasporto)

### 5.1 Generazione, in contemporanea alla spedizione

Il DDT non è un passo separato che l'operatore avvia a parte: viene generato nella stessa transazione della spedizione (§4), con `numero_documento_trasporto` assegnato da `qk_next_sequence_number($pdo, 'documento_trasporto')` (`includes/functions.php:526`, condivisa con gli altri contatori del progetto) — lock esclusivo reale (`BEGIN IMMEDIATE`, zero race condition) e azzeramento del progressivo a ogni cambio anno.

### 5.2 Mittente e destinatario: congelati alla creazione, mai ricalcolati in stampa

`documenti_trasporto` porta 6 colonne `mittente_*` + 10 colonne `destinatario_*`, popolate una sola volta al momento della creazione e mai più ricalcolate — stesso principio già visto per `ordini_clienti.azienda_*`/`cliente_*` (§1-2). Dettaglio completo (da dove vengono copiati i valori, le funzioni di lettura con fallback per i DDT storici) in [`Guide Anagrafiche Clienti, Fornitori, Indirizzi/00 - Anagrafica Unica...md`](../Guide%20Anagrafiche%20Clienti%2C%20Fornitori%2C%20Indirizzi/00%20-%20Anagrafica%20Unica%2C%20Indirizzi%20e%20Aziende%20Interne%20-%20Guida%20Completa.md) §3.5 — non ripetuto qui. In breve: un cambio di ragione sociale o indirizzo del cliente **dopo** l'emissione di un DDT non altera in alcun modo un documento già stampato/consegnato (fix del 20/07/2026 — prima il destinatario era letto dal vivo a ogni stampa, mentre il mittente era già corretto dal 19/07/2026).

### 5.3 Righe: snapshot ADR

Le righe del DDT portano una copia congelata della classificazione ADR del prodotto al momento della spedizione (numero ONU, classe di pericolo, gruppo di imballaggio), per consentire una stampa conforme alla normativa trasporti anche se la classificazione del prodotto cambia in anagrafica in seguito. Sia il ramo a lotto sia quello senza lotto leggono la classificazione reale del prodotto (`getClassificazioneAdr()`) al momento della creazione — nessuno dei due rami scrive più un valore ADR fittizio a prescindere dalla classificazione vera, come invece accadeva prima di un fix del 24/07/2026.

### 5.4 Stati e immutabilità

`documenti_trasporto.stato` (CHECK: `bozza`, `emesso`, `consegnato`) — nella pratica il DDT nasce ed emesso, la merce accompagnata fisicamente non prevede un flusso di conferma ricezione da parte del cliente all'interno di Quack.

### 5.5 Indirizzo di spedizione: stessa limitazione della fase precedente

Come per la spedizione (§4.6), il DDT non ha oggi modo di riflettere un indirizzo di consegna diverso da quello congelato sull'ordine — stesso rimando alla voce 35 del Backlog.

---

## 6. Fase E — Fatturazione (semplificata, per scelta deliberata)

### 6.1 Cosa fa oggi Quack: un solo flag

La fatturazione reale (fattura elettronica, invio SDI) **non avviene mai dentro Quack** — viene gestita esternamente tramite software dedicati (SGA, Doc Finance) o dal commercialista. Quack non obbliga alcun data-entry amministrativo: implementa solo una marcatura di stato semplice e visiva, `ordini_clienti.stato_fatturazione` (`TEXT NOT NULL DEFAULT 'da_fatturare'`, valori `da_fatturare`/`fatturato`), cambiabile con un click da `qk_ordine_cliente_segna_fatturato()`/`qk_ordine_cliente_segna_da_fatturare()` (`includes/sales_orders.php`).

**Decisione permanente (26/07/2026)**: questo non cambierà mai nel senso dell'emissione elettronica — Quack non produrrà mai fatture elettroniche. Non è escluso che in futuro serva registrare dati più ricchi (numero fattura, data, importo) su una fattura emessa altrove e solo *annotata* in Quack: per questo motivo esiste già a schema, integralmente e senza alcuna modifica, una coppia di tabelle `fatture`/`fatture_righe` — oggi **non scritte né lette da nessun punto del codice** (verificato: zero occorrenze di `INSERT`/`UPDATE`/`SELECT` su di esse in tutto il progetto). Sono una predisposizione dichiarata per un eventuale uso futuro di registrazione manuale, non una funzionalità attiva, e restano intatte per scelta esplicita ("il futuro è sempre incerto, meglio non buttare via un design già fatto").

### 6.2 `stato_fatturazione`: un interruttore libero, senza vincoli

Nessun `CHECK`, nessun trigger, nessun controllo di permesso su chi può cambiarlo o quando: coerente con la filosofia dichiarata ("marcatura visiva semplice", non un vero stato macchina), ma con due conseguenze accettate esplicitamente il 26/07/2026, non corrette:

- un ordine `annullato` può restare marcato `da_fatturare` per sempre, e continuerà a comparire nei filtri/contatori "da fatturare" anche se non verrà mai davvero fatturato;
- chiunque può togliere il flag `fatturato` con un click e poi usare "Crea Nuova Versione" (§2.4) per aggirare il blocco assoluto sulla correzione di un ordine fatturato (§2.5) — nessun permesso speciale richiesto, nessuna traccia lasciata.

Una proposta di soluzione completa (un "registro di fatturazione" che deriva lo stato automaticamente da una fattura/nota di credito registrata, invece di un flag manuale) è scritta per intero in [`Backlog Sviluppi Futuri.md`, voce 36](../Backlog%20Sviluppi%20Futuri.md) — non in corso di realizzazione, resta lì in attesa di una decisione futura.

### 6.3 Correzione di un ordine già fatturato

Vedi §2.5 sopra — richiesta con approvazione (maker-checker), non una riscrittura diretta dell'ordine.

---

## 7. Casi Limite e Lacune Note

Raccolta delle lacune/limitazioni note del ciclo vendite, per non farle sembrare dimenticate — nessuna delle voci sotto è un bug silenzioso: ognuna è stata verificata e, dove è stata presa una decisione, è riportata.

- **Reso cliente**: nessun flusso guidato. Gli stati `consegnata`/`reso` di `spedizioni.stato` esistono a schema ma sono irraggiungibili — nessuna azione di codice li imposta mai. Un reso o una correzione di spedizione già confermata si gestiscono con un movimento di magazzino manuale generico (deciso il 22/07/2026, stesso trattamento del reso a fornitore lato Acquisti). Dettaglio: [Backlog, voce 34](../Backlog%20Sviluppi%20Futuri.md#34-reso-cliente-e-correzione-di-una-spedizione-gi%C3%A0-confermata).
- **Spedizione su indirizzi diversi per lo stesso ordine**: non realizzabile oggi (§4.6/§5.5). Rimandato al backlog, voce 35, piano dettagliato scritto.
- **`fatture`/`fatture_righe` morte**: predisposizione dichiarata, non un bug (§6.1).
- **`stato_fatturazione` libero senza vincoli**: per scelta esplicita, non corretto (§6.2). Proposta di soluzione in backlog, voce 36.
- **Prodotto/cliente disattivato con documenti aperti**: "fallisci tardi" per scelta (§1.11) — comportamento voluto, non un bug.
- **`completato` solo manuale**: nessun automatismo passa un ordine `spedito` a `completato` — azione esplicita dell'operatore (§2.2), mirror del "chiudi" lato Acquisti.
- **Kit nel ciclo vendite**: la logica kit (riserva componenti alla conferma, scarico componenti in spedizione con controllo giacenza e posting-block, costo base da `componenti_kit`) è reale e funzionante ma non è mai stata nominata esplicitamente nel testo narrativo di questo documento fino a questa riscrittura — ora coperta indirettamente ovunque si parla di "riserva"/"scarico" (§1.4, §4.4), senza una sezione dedicata a sé.
- **Nessun cruscotto di confronto preventivi multipli sullo stesso cliente**: a differenza del confronto offerte multi-fornitore lato Acquisti, non esiste un analogo lato vendite — caso d'uso meno frequente in questo verso, non richiesto.

Per la riscrittura definitiva della documentazione (questo stesso documento) come processo, vedi la nota in `AUDIT 4 - Controllo Generale Problemi Aperti (25-07-2026).md` punto 8.

---

*(documento completo — Preventivo, Ordine, Spedizione, DDT, Fatturazione, Casi Limite. Vedi anche il documento B, `Ciclo Vendite - Schema Dati e API.md` — DDL, funzioni `qk_*` ed endpoint — scritto il 26/07/2026.)*
