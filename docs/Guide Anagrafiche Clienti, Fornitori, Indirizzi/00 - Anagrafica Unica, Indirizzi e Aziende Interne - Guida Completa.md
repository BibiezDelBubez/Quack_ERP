# 00 — Anagrafica Unica, Indirizzi e Aziende Interne — Guida Completa

> Questo documento sostituisce e unisce i vecchi file `Come salvare dati Gemina su ERP.docx` (F1) e `Gestione e Associazione Indirizzi con Clienti e Fornitori su Quack erp.docx` (F2). Rispetto agli originali, ogni affermazione qui è stata verificata riga per riga contro il codice reale (`includes/anagrafiche.php`, `db/init_sqlite.sql`, `db/init_mysql.sql`, i controller in `app/Controllers/`) al 14/07/2026. Dove il comportamento reale differisce da quanto originariamente descritto, è segnalato esplicitamente con **⚠️ Nota di verifica**. I file originali restano disponibili, non eliminati, nella sottocartella `_originali_pre_riorganizzazione/` per confronto.

---

## 1. Anagrafica Unica

Quack non ha tabelle separate per clienti e fornitori. Esiste un'unica tabella, `clienti_fornitori`, con una colonna `tipo` che vale `'cliente'`, `'fornitore'` o `'entrambi'`. La stessa riga rappresenta quindi qualunque soggetto con cui l'azienda ha una relazione commerciale, indipendentemente dal verso della relazione.

### 1.1 Perché un'anagrafica unica

Il vantaggio pratico è duplice: un soggetto che compra e vende (tipico nel commercio di prodotti chimici, dove un distributore può essere sia fornitore di materia prima sia cliente per prodotti finiti) ha una sola scheda, un solo storico, un solo indirizzo censito una volta sola. Non serve mantenere sincronizzate due anagrafiche gemelle per la stessa entità legale.

### 1.2 Gerarchia dei sotto-contatti

Un soggetto può avere sotto-contatti: referenti umani (nome, email, telefono, ruolo) legati a lui tramite la colonna `parent_id` su `clienti_fornitori`. Esempio: "Fornitore Rossi Srl" è il contatto principale (`parent_id IS NULL`); "Mario Rossi", referente acquisti, è un sotto-contatto con `parent_id` = id di Rossi Srl.

**⚠️ Decisione di design (14/07/2026), non ancora imposta nel codice.** Sono state formalizzate tre regole per questa gerarchia:

1. Un contatto principale può avere più sotto-contatti (1:N) — già vero oggi, nessuna modifica necessaria.
2. Non possono esistere sotto-contatti di sotto-contatti: la profondità è limitata a un solo livello.
3. Gli indirizzi possono essere associati solo al contatto principale, mai a un sotto-contatto.

Oggi (14/07/2026) **nessuna delle due regole 2 e 3 è verificata dal codice**: `qk_insert_contatto_cliente()`/`qk_insert_contatto_fornitore()` controllano solo che il genitore esista e abbia il `tipo` giusto, non che sia a sua volta di primo livello; `qk_insert_indirizzo()`/`qk_update_indirizzo()` accettano qualunque `soggetto_id`, sotto-contatto compreso. Nessuna violazione è stata creata finora nell'uso reale (il flusso UI normale non ci porta), quindi non è un bug urgente — è una regola di integrità da formalizzare con un audit preliminare sui dati esistenti prima di attivare qualunque vincolo. Dettaglio completo dell'implementazione prevista (query di audit, validazione applicativa, trigger DB opzionali, redirect nei controller) nel documento di revisione tecnica interno; da programmare in una sessione dedicata.

Il motivo per cui questa limitazione non è restrittiva rispetto a SAP/Dynamics: quei sistemi modellano "contatto/referente" sempre come relazione persona↔organizzazione, mai persona↔persona, quindi la profondità-1 non toglie nulla che diano per scontato. E il caso "un referente riceve sempre la merce altrove" non richiede un indirizzo sul sotto-contatto: se è occasionale, esiste già il meccanismo di indirizzo "una tantum" in fase di preventivo/ordine; se è ricorrente, quel referente va censito come contatto principale a sé stante, con il proprio indirizzo.

### 1.3 Tabelle di dettaglio per ruolo

Ogni ruolo ha una propria tabella di dettaglio con i dati specifici di quel ruolo: `dettagli_clienti` (condizioni di pagamento, listino assegnato, sconto extra) e `dettagli_fornitori` (dati specifici lato acquisti). Un soggetto `tipo = 'entrambi'` può avere righe in entrambe le tabelle.

**⚠️ Nota di verifica — creazione "pigra" della riga di dettaglio.** Quando un soggetto cambia `tipo` verso un ruolo nuovo (es. da `fornitore` a `entrambi`), la riga di dettaglio corrispondente **non** si crea automaticamente in quell'istante. `qk_update_cliente()`/`qk_update_fornitore()` fanno un upsert pigro: verificano con un `SELECT` se la riga esiste, e la creano al volo solo alla prima volta che qualcuno apre e salva il tab specifico di quel ruolo. Se nessuno lo fa, il soggetto resta con un ruolo assegnato ma senza dati di dettaglio, e una query che fa `INNER JOIN` su quella tabella di dettaglio semplicemente non lo troverà, senza errore visibile. Soluzione consigliata quando si interviene su questa parte: mostrare subito, al cambio di `tipo`, il blocco dati del ruolo nuovo da compilare nella stessa schermata, invece di rimandarlo; aggiungere una query di audit periodica per i soggetti con ruolo assegnato ma dettaglio mancante.

### 1.4 Duplicati anagrafici

Il vincolo `partita_iva VARCHAR(20) UNIQUE` è presente su `clienti_fornitori` in entrambi gli schemi (SQLite e MySQL) — un duplicato per stessa Partita IVA è già impedito a livello di database. Il messaggio d'errore che l'utente vede in questo caso passa da `qk_db_user_message()`, che intercetta l'eccezione PDO e restituisce un testo in italiano ("Esiste già un soggetto con lo stesso codice anagrafica o partita IVA."), usato nei form principali di clienti e fornitori.

**⚠️ Limiti reali di questo controllo:** il vincolo UNIQUE non blocca i valori `NULL` — due persone fisiche senza Partita IVA possono duplicarsi liberamente; il percorso AJAX per i sotto-contatti non passa da `qk_db_user_message()`, quindi un eventuale errore SQL grezzo arriverebbe non tradotto; il codice fiscale non ha alcun vincolo di unicità, né a database né applicativo. I grandi ERP (SAP Business Partner, Dynamics, Odoo) non si fermano al vincolo di unicità: fanno un duplicate-check attivo mentre l'utente digita (fuzzy match su nome, match esatto su P.IVA/CF), mostrando possibili corrispondenze prima del salvataggio — il vincolo DB resta solo la rete di sicurezza finale. Estendere questo comportamento a Quack (in particolare ai privati senza P.IVA/CF, dove un vincolo rigido non è praticabile) è un miglioramento futuro, non un bug da correggere subito.

### 1.5 Cancellazione di un soggetto e dei suoi sotto-contatti

`qk_delete_soggetto()` tenta prima una `DELETE` fisica; se un vincolo di chiave esterna la blocca (tipicamente perché il soggetto ha documenti storici collegati), fa fallback a un soft-delete (`attivo = 0`) e restituisce quale dei due esiti si è verificato — questo valore alimenta il messaggio corretto mostrato all'utente ("eliminato definitivamente" vs "disattivato perché ha documenti storici collegati"), tramite la funzione condivisa `qk_esito_cancellazione_messaggio()`.

**⚠️ Nota di verifica — sotto-contatti orfani.** La colonna `parent_id` su `clienti_fornitori` **non ha alcuna foreign key** — niente `REFERENCES`, niente `ON DELETE`. Se un genitore con sotto-contatti viene eliminato fisicamente (perché non referenziato da nient'altro), i sotto-contatti restano righe orfane con `parent_id` che punta a un id inesistente, silenziosamente. Diverso — e già corretto — il caso di un sotto-contatto referenziato da uno snapshot di documento: `contatto_master_id` ha `ON DELETE SET NULL`, quindi l'eliminazione del master non blocca né rompe nulla, ma il pulsante "Ripristina" di quel documento, non trovando più il contatto tra quelli attivi, fa fallback silenzioso svuotando i campi invece di avvisare l'utente. Entrambi i punti sono aree di miglioramento note, non urgenti nell'uso attuale.

---

## 2. Indirizzi

Ogni soggetto (`clienti_fornitori`) può avere più indirizzi, in relazione 1:N tramite `indirizzi.soggetto_id`, con cancellazione a cascata (`ON DELETE CASCADE`): se il soggetto viene eliminato fisicamente, i suoi indirizzi lo seguono automaticamente.

### 2.1 Tipi di indirizzo

Un vincolo CHECK sulla colonna `tipo` limita i valori ammessi (tipicamente: `sede_legale`, `fatturazione`, `spedizione`/`sede_operativa`, `magazzino` — la lista esatta va verificata sullo schema attivo).

### 2.2 La regola del "predefinito"

Per ogni combinazione soggetto+tipo, un solo indirizzo dovrebbe essere marcato `predefinito = 1` — è quello che l'algoritmo di fallback (§4) usa per precompilare fatturazione/spedizione quando il documento non ne specifica uno esplicito.

**✅ Corretto il 14/07/2026.** A livello di schema, `predefinito` resta un semplice booleano — nessun indice univoco parziale, nessun trigger — ma a livello applicativo `qk_insert_indirizzo()`/`qk_update_indirizzo()` ora avvolgono il reset degli altri predefiniti + la scrittura del nuovo in una transazione esplicita (`beginTransaction()`/`commit()`/`rollBack()`), attivata solo quando serve (`predefinito=1`) e solo se non esiste già una transazione esterna in corso (per evitare l'errore "transazione annidata" quando un chiamante ha già aperto la propria). Verificato con test reale: 3 inserimenti consecutivi con `predefinito=1` sullo stesso soggetto+tipo lasciano sempre e solo un predefinito. Stessa protezione già presente da prima per `aziende_interne.predefinita` in `qk_insert_azienda()`/`qk_update_azienda()`. In caso di migrazione stabile a MySQL, resta valido rinforzare con un trigger `BEFORE INSERT/UPDATE` come rete di sicurezza indipendente aggiuntiva.

### 2.3 Campi logistici speciali e loro scopo

Oltre ai campi indirizzo standard, la tabella `indirizzi` ha alcune colonne pensate specificamente per la logistica (proposte in F2, oggi già implementate nello schema):

- `nome_sede`: un alias leggibile per distinguere indirizzi simili in un menu a tendina (es. "[Magazzino Nord] Via Torino 12" invece di un elenco di vie tutte uguali).
- `referente_consegna` / `telefono_consegna`: permettono di indicare al corriere chi cercare fisicamente sul posto (es. il magazziniere) senza dover passare dal centralino dell'ufficio acquisti del cliente.
- `presso` (c/o): utile quando la consegna avviene presso terzi (un trasportatore intermedio, un deposito esterno).

### 2.4 Idee di UX discusse e non (ancora) implementate

Due proposte di F2 restano solo idee, verificate come non presenti nel codice reale (`app/views/customers/_form.php` e equivalenti):

- **"Disvelamento progressivo"**: alla creazione di una nuova anagrafica, mostrare un solo modulo indirizzo (sede legale) con due checkbox preselezionate ("la spedizione coincide con la sede legale", "la fatturazione coincide con la sede legale"), rivelando moduli aggiuntivi solo se l'utente le deseleziona. Riduce l'attrito per il caso comune (un solo indirizzo) senza perdere flessibilità per i casi con più sedi. Non implementata.
- **Autocompletamento indirizzo via API di geolocalizzazione** (Google Places, TomTom, OpenStreetMap): valutata e scartata deliberatamente in F2 stesso — dipendenza da servizi esterni potenzialmente a pagamento o soggetti a modifiche, difficili da sostituire per l'accoppiamento stretto col fornitore scelto. Non riproporre senza una motivazione nuova.

### 2.5 Nazioni estere: controlli non ancora adattati

F2 prometteva che "i controlli applicativi si adattano o disattivano" per CAP e Provincia quando la nazione non è l'Italia. **Questa promessa non è mai stata tradotta in codice.** Il CAP è oggi sempre obbligatorio, qualunque sia la nazione; il campo `provincia` ha `maxlength="2"` fisso nel form HTML anche per l'estero; il campo `nazione` è testo libero, non un menu ISO. Nessuna logica JS adatta il comportamento in base alla nazione scelta. Decisione di progetto (14/07/2026): funzionalità futura, non implementata ora — nessun cliente/fornitore estero reale ha ancora incontrato il problema in produzione. Specifica di implementazione completa tracciata in [Backlog Sviluppi Futuri.md](../Backlog%20Sviluppi%20Futuri.md), voce 11.

---

## 3. Azienda Interna / Multi-Company

`aziende_interne` rappresenta le entità legali interne all'azienda (es. "Gemina S.r.l.", ed eventuali società collegate in futuro). Condivide la stessa tabella `indirizzi` usata da clienti e fornitori (via `clienti_fornitori`, di cui ogni azienda interna è anche una riga), per riuso di codice e per rendere la fatturazione elettronica e l'espansione multi-company più semplici da gestire in futuro.

### 3.1 Azienda predefinita e suo utilizzo

Un flag `predefinita` su `aziende_interne` indica quale azienda emittente usare di default su RFQ/preventivi/ordini quando il documento non ne specifica una esplicitamente.

**⚠️ Nota di verifica — comportamento reale in assenza di azienda predefinita valida.** Nessun vincolo DB impedisce che `predefinita = 1` venga tolta da tutte le aziende interne, o che l'unica azienda con quel flag venga disattivata. Le quattro occorrenze verificate del fallback (`RfqController.php`, `PurchaseOrdersController.php`) **non filtrano mai `attivo = 1`** nella query `WHERE predefinita = 1`. Se nessuna riga soddisfa la condizione, il risultato è un array vuoto — non un errore, non un blocco — e in fase di generazione PDF l'header ricade su un nome azienda **hardcoded** (`'Gemina S.r.l.'`) con Partita IVA, email e telefono vuoti: un documento commerciale incompleto che potrebbe uscire realmente senza che nessuno se ne accorga. Inoltre `qk_delete_azienda()` fa sempre un soft-delete incondizionato e non controlla né azzera il flag `predefinita` dell'azienda disattivata: se l'utente disattiva l'unica azienda predefinita, quella stessa azienda, ormai disattivata, continua a essere scelta come emittente di default sui nuovi documenti, perché il fallback non guarda `attivo`.

**✅ Corretto il 14/07/2026.** `qk_delete_azienda()` ora azzera esplicitamente `predefinita` sull'azienda che disattiva e, se era lei la predefinita, promuove automaticamente un'altra azienda attiva al suo posto (la prima per id) — verificato con test reale, incluso il caso limite "nessuna azienda attiva rimasta" (nessun crash, nessuna promozione impossibile). La funzione ora riusa anche `qk_delete_soggetto()` per la parte `clienti_fornitori`, eliminando una duplicazione. Il fallback di stampa sui preventivi (`SalesQuotesController.php`) ora ha il filtro `AND attivo = 1` (vedi §3.3 più sotto).

Anche il fallback di `qk_resolve_azienda_emittente()` (usato da RFQ/Ordini d'acquisto) è stato corretto, in un intervento separato dal refactor MVC/DRY originale: `qk_azienda_predefinita()` ora filtra `AND az.attivo = 1`. La correzione è mirata: riguarda solo il ramo "nessuna azienda ancora assegnata al documento" (fallback). Il ramo con `azienda_interna_id` esplicito — cioè quando il documento (RFQ/ordine già esistente) ha già un'azienda collegata — **resta intenzionalmente senza filtro `attivo`**: se quell'azienda viene disattivata in seguito, il documento deve continuare a mostrarla, per la stessa ragione di integrità storica di `qk_azienda_interna_by_id()` (vedi `02`, §2.2). Confondere questi due casi sarebbe stato un errore: la prima proposta di fix ipotizzava di filtrare `attivo` anche sul ramo esplicito, poi corretta prima di implementare, verificando che entrambi i punti di chiamata reali (`RfqController::pdf()`, `PurchaseOrdersController::pdf()`) passano sempre l'id già salvato sul documento esistente, mai un id "candidato" per un documento nuovo. Verificato con 6 assert dedicati + 7 della suite di stampa preesistente, nessuna regressione sulle altre 12+3 asserzioni delle sessioni precedenti.

### 3.2 Disattivazione di un'azienda interna con documenti storici

`qk_delete_azienda()` non tenta mai una cancellazione fisica — a differenza di `qk_delete_soggetto()` per clienti/fornitori — fa sempre e soltanto soft-delete (`attivo = 0`), indipendentemente dall'esistenza di documenti storici collegati. Il menu di scelta azienda per i nuovi documenti (`qk_aziende_list()`) filtra correttamente `attivo = 1`, quindi un'azienda disattivata sparisce subito da lì; i documenti storici che referenziano esplicitamente l'azienda continuano a leggerla correttamente, preservando lo storico.

**✅ Corretto.** Il messaggio "Azienda rimossa con successo" mostrato in origine era sempre fuorviante, dato che la cancellazione fisica non viene mai nemmeno tentata. `AziendeController::delete()` ora usa `qk_esito_cancellazione_messaggio($esito, 'azienda', 'la')` (la stessa funzione condivisa introdotta per `qk_delete_soggetto()`, vedi §1.5): per l'esito `'disattivato'` mostra "Azienda non eliminata (è referenziata da altri documenti/dati): è stata disattivata invece." — un messaggio che non lascia più credere all'utente che il record sia sparito.

### 3.3 Colonne denormalizzate per integrità storica: scritte ma non lette

Ogni documento (preventivo/ordine) salva, alla creazione, una copia denormalizzata dei dati dell'azienda emittente (`azienda_ragione_sociale`, `azienda_partita_iva`, ecc.) — il commento nello schema dichiara esplicitamente "per integrità storica". Il pattern di scrittura è corretto.

**✅ Corretto il 14/07/2026, ed esteso anche a `ordini_clienti`.** Prima, la generazione PDF/Word rifaceva sempre una query dal vivo su `aziende_interne` (con lo stesso fallback su `predefinita = 1` del punto 3.1), ignorando le colonne denormalizzate già presenti sulla riga. Ora entrambi i punti di generazione (`SalesQuotesController.php`, PDF e Word) chiamano la funzione condivisa `qk_resolve_azienda_emittente_stampa()` (`includes/document_common.php`), che legge prima le colonne congelate e ricorre alla query dal vivo — con filtro `attivo = 1` incluso — solo per i documenti legacy che ne sono privi. Verificato con test reale: un documento con snapshot congelato continua a mostrare i dati originali anche dopo che l'anagrafica dell'azienda viene rinominata.

**Estensione (richiesta esplicitamente per dare pari robustezza a vendite e acquisti):** `ordini_clienti`, che prima non aveva affatto queste colonne (asimmetria rispetto a `preventivi`), le ha ora ricevute con la stessa struttura — schema aggiornato in `init_sqlite.sql`/`init_mysql.sql`, migrazione dedicata in `db/migrations/2026_07_14_aggiungi_azienda_denormalizzata_ordini_clienti.php` per i database già esistenti, e le due funzioni che creano ordini (`qk_insert_ordine_diretto()`, `qk_preventivo_conferma_ordine()`) ora le popolano. **Nota**: lo schema MySQL di `ordini_clienti` era già significativamente disallineato da quello SQLite prima di questo intervento (mancano `consegna_*`, `contatto_*`, `cliente_*` e altre colonne) — un problema preesistente, segnalato ma non risolto qui perché fuori scope (lo sviluppo attivo è su SQLite).

### 3.4 Proposta UI di F1: pagina unica "La mia Azienda" — già realizzata, in forma leggermente diversa

F1 proponeva una schermata dedicata che presenti all'utente un'unica pagina di data-entry senza fargli percepire che sotto il cofano scrive su due tabelle diverse (`clienti_fornitori` + `aziende_interne`), organizzata in 4 tab: Dati Generali, Registro Imprese & Legali, Sedi e Magazzini, Conti Correnti.

**✅ Verificato — esiste già**, in `app/views/settings/aziende/_form.php`: 3 tab ("Dati", "Indirizzi", "Contatti") invece di 4, con il campo Registro Imprese/REA incorporato nel tab "Dati" invece che in un tab legale separato. La sostanza della proposta (unica pagina, niente esposizione delle due tabelle sottostanti) è rispettata; la suddivisione esatta in tab è diversa da quella immaginata in F1 ma equivalente nella pratica.

---

## 4. L'algoritmo di risoluzione indirizzo fatturazione/spedizione

F2 §3 descriveva un algoritmo a cascata a 4 livelli per la fatturazione (predefinito di tipo fatturazione → fatturazione qualsiasi → sede legale → primo indirizzo disponibile) e uno analogo, con fallback finale sulla fatturazione, per la spedizione.

**⚠️ Nota di verifica — questo algoritmo non è mai stato costruito, il comportamento reale è diverso e più semplice.**

- **Lato fatturazione**: `document_common.php` fa un semplice `LEFT JOIN` sull'indirizzo di tipo `sede_legale` — nessuna cascata su altri tipi. Se il cliente non ha nessun indirizzo `sede_legale`, tutti i campi risultano `NULL` e la concatenazione produceva, prima della correzione applicata l'11/07/2026, un residuo di punteggiatura (`",  ()"`) invece di una stringa vuota; oggi la concatenazione usa `array_filter` sui componenti non vuoti e restituisce `null` se non c'è nulla da mostrare.
- **Lato spedizione, in fase di preventivo**: la selezione è sempre manuale, da un dropdown con opzione "Usa sede legale" e "+ Inserisci indirizzo personalizzato (una tantum)" — non è un automatismo che possa "non trovare nulla": se il cliente non ha indirizzi salvati, il preventivo si salva comunque senza indirizzo di consegna esplicito, senza errori.
- **Lato spedizione, in fase di creazione della spedizione fisica**: qui un indirizzo risolto serve per forza. Il codice tenta tre livelli (indirizzo esplicito → master dello snapshot → predefinito del cliente) e, se nessuno è risolvibile, **blocca esplicitamente** con un messaggio chiaro — comportamento già corretto, introdotto in passato proprio per evitare un crash SQL da `NULL` forzato su una colonna `NOT NULL`.
- **Gerarchia e sotto-contatti**: F2 §3.C descriveva una risalita al genitore per un sotto-contatto senza indirizzi propri. Verificato: questa risalita **non esiste per nessun livello**. La ricerca cliente dell'autocomplete preventivo non esclude i sotto-contatti dalla selezione diretta come intestatario, e la risoluzione indirizzo usa esclusivamente l'id passato, senza mai controllare `parent_id`. Un sotto-contatto senza indirizzo proprio produce lo stesso comportamento del punto sopra (indirizzo vuoto), non un errore diverso.

**⚠️ Nota aggiuntiva, assente in F2: questo algoritmo (in qualunque delle sue forme reali sopra descritte) non ha alcun equivalente lato acquisti.** Il ciclo RFQ→Offerta→Ordine d'acquisto usa un meccanismo completamente diverso, basato su snapshot con hash — descritto nel dettaglio nel documento [`01 - Ciclo Acquisti`](01%20-%20Ciclo%20Acquisti%20-%20Indirizzi%20e%20Contatti%20da%20RFQ%20a%20Ordine.md). Le due logiche (vendite: risoluzione live con fallback manuale/bloccante; acquisti: fotografia immutabile al momento della creazione del documento) non vanno confuse tra loro.

---

## 5. Nota terminologica: "Preventivo" indica due tabelle diverse

**⚠️ Verificato nel codice reale, non un bug ma un rischio concreto di confusione per chi non conosce già lo schema.** Nel database esistono due tabelle completamente distinte per il concetto di "preventivo": `preventivi` (lato vendite, l'offerta che facciamo noi a un cliente) e `offerte_testata` (lato acquisti, l'offerta che un fornitore fa a noi — descritta in [`01 - Ciclo Acquisti`](01%20-%20Ciclo%20Acquisti%20-%20Indirizzi%20e%20Contatti%20da%20RFQ%20a%20Ordine.md)). In italiano la parola "preventivo" viene usata per entrambe, anche nell'interfaccia stessa: il modulo acquisti ha un pulsante con la scritta letterale "Registra Preventivo Spontaneo", pur scrivendo sulla tabella `offerte_testata`. Non è un errore di programmazione — è una scelta terminologica consapevole, usata in modo coerente in tutta l'applicazione — ma chi cerca "la tabella del preventivo" partendo dal nome ovvio (`preventivi`) trova la tabella giusta solo per il lato vendite.

Nota minore, solo terminologica: F2 titolava la propria sezione sul preventivo "Il Preventivo (Sales Order)" — etichetta imprecisa anche in inglese, perché "Sales Order" indica un ordine di vendita già confermato, non un preventivo; il termine corretto sarebbe "Sales Quote"/"Quotation". Non ha alcun impatto sul codice, riportato solo per completezza rispetto al documento originale.

**Miglioramento consigliato per il futuro, non ancora fatto**: rinominare le tabelle del ciclo commerciale con nomi che specificano sempre il verso — `preventivi` → `preventivi_vendita`, `offerte_testata` → `preventivi_acquisto`, `ordini_clienti` → `ordini_vendita`, `ordini_testata` → `ordini_acquisto`. È una modifica di schema non banale (tocca ogni query, ogni FK, ogni vista che le referenzia), da pianificare come intervento a sé, non improvvisata.

---

Per il dettaglio tecnico di funzioni, firme e schema DB citati in questo documento, vedi [`02 - Dettaglio Tecnico`](02%20-%20Dettaglio%20Tecnico%20-%20Schema%20DB%20e%20Codice%20(Anagrafiche).md).
