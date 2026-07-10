# Specifica Tecnica: Architettura Ibrida per la Gestione di Indirizzi e Contatti nel Ciclo Acquisti

Questa guida descrive l'architettura d'eccellenza per la gestione di indirizzi di consegna e contatti referenti all'interno del ciclo acquisti del nostro ERP.

L'obiettivo è unire la **pulizia strutturale di SAP** (evitando di intasare le tabelle dei documenti con decine di colonne di testo duplicato) e la **tracciabilità semantica di Dynamics 365** (sapere sempre se un indirizzo è originale o modificato, permettendo azioni intelligenti come il ripristino o il salvataggio in anagrafica).

## 1. Lo Strato Database (DB Schema)

Per far funzionare questo modello, dividiamo nettamente il database in tre aree logiche: **Anagrafiche (Master Data)**, **Documenti (Transactional Data)** e **Copie Storiche (Snapshot Data)**.

```
                  ┌──────────────────────────────────────────┐
                  │          TABELLE ANAGRAFICHE             │
                  │  (clientefornitore, indirizzi_master...) │
                  └────────────────────┬─────────────────────┘
                                       │
                                       ▼ (Copia / Snapshot)
                  ┌──────────────────────────────────────────┐
                  │           TABELLE DI SNAPSHOT            │
                  │   (document_addresses, document_contacts)│
                  └────────────────────┬─────────────────────┘
                                       │
            ┌──────────────────────────┼──────────────────────────┐
            │ (FK)                     │ (FK)                     │ (FK)
            ▼                          ▼                          ▼
┌──────────────────────┐   ┌──────────────────────┐   ┌──────────────────────┐
│     rfq_headers      │   │vendor_quotes_headers │   │purchase_orders_header│
└──────────────────────┘   └──────────────────────┘   └──────────────────────┘
```

### A. Tabelle di Snapshot (Il "Cuscino" tra Anagrafica e Documento)

Queste tabelle memorizzano i dati fisici di consegna e contatto per *ogni singolo documento*. Nessun documento punterà mai direttamente alle tabelle anagrafiche master una volta finalizzato.

#### Tabella `document_addresses`

Memorizza l'indirizzo esatto di consegna associato a un documento.

| Campo               | Tipo          | Descrizione                                                                                                             |
| ------------------- | ------------- | ----------------------------------------------------------------------------------------------------------------------- |
| `id`                | INT (PK)      | Chiave primaria autoincrementale.                                                                                       |
| `company_name`      | VARCHAR(255)  | Ragione sociale della destinazione (es. nostro magazzino o cliente finale).                                             |
| `street`            | VARCHAR(255)  | Via e numero civico.                                                                                                    |
| `city`              | VARCHAR(100)  | Comune / Città.                                                                                                         |
| `zip_code`          | VARCHAR(20)   | CAP.                                                                                                                    |
| `country_code`      | VARCHAR(3)    | Codice nazione ISO 3166-1 alpha-3 (es. ITA, DEU).                                                                       |
| `master_address_id` | INT (FK) NULL | Punta a `indirizzi_master.id`. Diventa `NULL` se l'indirizzo è scritto interamente a mano libera.                       |
| `is_customized`     | BOOLEAN       | `TRUE` se l'utente ha modificato manualmente anche solo un carattere rispetto al master originale.                      |
| `original_hash`     | VARCHAR(64)   | Hash SHA-256 dei campi master al momento della copia. Serve per capire se l'anagrafica di origine è cambiata nel tempo. |

#### Tabella `document_contacts`

Memorizza il referente del fornitore associato al documento.

| Campo               | Tipo          | Descrizione                                                   |
| ------------------- | ------------- | ------------------------------------------------------------- |
| `id`                | INT (PK)      | Chiave primaria autoincrementale.                             |
| `first_name`        | VARCHAR(100)  | Nome del referente.                                           |
| `last_name`         | VARCHAR(100)  | Cognome del referente.                                        |
| `email`             | VARCHAR(255)  | Email diretta del referente.                                  |
| `phone`             | VARCHAR(50)   | Telefono/Cellulare diretto.                                   |
| `master_contact_id` | INT (FK) NULL | Punta a `contatti_master.id`. `NULL` se creato a mano libera. |
| `is_customized`     | BOOLEAN       | `TRUE` se modificato rispetto al contatto master.             |
| `original_hash`     | VARCHAR(64)   | Hash SHA-256 dei dati del contatto master originale.          |

> ✅ **STATO ATTUALE VERIFICATO (10/07/2026)** — La riga `master_contact_id → contatti_master.id` qui sopra descrive una tabella (`contatti_master`) che **non è mai stata creata**. **In parole semplici, per chi non conosce il database:** l'idea originaria era di avere una tabella dedicata solo ai "referenti/contatti" delle aziende, separata dalla tabella principale di clienti e fornitori. Nella realtà si è scelto un approccio più semplice: un referente aziendale (es. "Mario Rossi, ufficio acquisti di Contoso") **non è un tipo di dato diverso da un cliente o un fornitore** — è semplicemente un'altra riga della stessa tabella `clienti_fornitori`, con un campo `parent_id` che dice "questo record appartiene a Contoso Inc." Verificato nel codice reale (`includes/document_common.php`): la colonna equivalente si chiama `contatto_master_id` e punta a `clienti_fornitori.id`, non a una tabella `contatti_master` mai esistita. Questo è anche più coerente con il resto del sistema, dove lo stesso meccanismo (`parent_id`) è già usato per rappresentare filiali e sotto-contatti in generale (vedi la guida sulla Gestione Indirizzi, sezione "Gerarchia Sub-Contatti").

### B. Le Tabelle dei Documenti (Le Testate del Ciclo Acquisti)

Come da decisione architetturale, abbiamo tre tabelle separate. Ognuna di esse ha una relazione `1:1` o `N:1` (opzionale) con le tabelle di snapshot tramite Foreign Key.

- **`rfq_headers`**: Ha i campi `delivery_address_id` (FK a `document_addresses`) e `vendor_contact_id` (FK a `document_contacts`).

- **`vendor_quotes_headers`**: Ha i campi `delivery_address_id` e `vendor_contact_id`.

- **`purchase_orders_headers`**: Ha i campi `delivery_address_id` e `vendor_contact_id`.

## 2. Lo Strato Backend (La Logica Operativa)

Il backend ha tre compiti fondamentali: calcolare l'integrità dei dati (generazione hash), clonare i record quando necessario e bloccare le modifiche sui documenti confermati.

### A. L'Algoritmo di Rilevamento delle Modifiche (Hashing)

Quando l'utente modifica un indirizzo, come fa il backend a capire se è diverso dal master? Usa una funzione di hashing deterministica.

> ✅ **STATO ATTUALE VERIFICATO (10/07/2026)** — Questo pseudocodice (in Python, con nomi di campo generici) era la bozza di partenza. Il codice realmente scritto è in PHP, in `includes/document_common.php::qk_generate_address_hash()`, e **calcola l'hash su un elenco di campi diverso**: non 5 ma **7 campi**. In parole semplici: l'idea di fondo (trasformare i dati dell'indirizzo in un'"impronta digitale" numerica, e considerare l'indirizzo "cambiato" se l'impronta cambia) è identica; sono cambiati solo *quali pezzi dell'indirizzo* entrano nel calcolo dell'impronta. La versione qui sotto usa: ragione sociale, via, città, CAP, nazione (5 campi) — **manca civico e provincia**. La versione reale aggiunge anche il **nome della sede** (l'etichetta tipo "Magazzino Nord"), il **numero civico** e la **provincia**. Questo non è un dettaglio cosmetico: con la versione a 5 campi qui sotto, due indirizzi identici salvo il civico ("Via Roma 1" e "Via Roma 15") risulterebbero con lo stesso hash, cioè l'ERP li considererebbe "lo stesso indirizzo, non modificato" — un errore. La versione reale a 7 campi evita questo problema. Il codice PHP reale, tradotto in pseudocodice equivalente:
>
> ```
> function genera_hash_indirizzo(indirizzo):
>     campi = [
>         indirizzo.nome_sede,     # es. "Magazzino Nord" — ASSENTE nella versione sotto
>         indirizzo.via,
>         indirizzo.civico,        # ASSENTE nella versione sotto
>         indirizzo.cap,
>         indirizzo.citta,
>         indirizzo.provincia,     # ASSENTE nella versione sotto
>         indirizzo.nazione (default 'IT')
>     ]
>     testo = unisci_con_separatore(campi, "|"), tutto minuscolo, spazi iniziali/finali rimossi
>     ritorna SHA-256(testo)
> ```

```
import hashlib

def generate_address_hash(address_record):
    """
    Genera un hash SHA-256 pulito concatenando i campi essenziali dell'indirizzo.
    Rende la stringa minuscola e rimuove gli spazi extra per evitare falsi positivi.
    """
    components = [
        str(address_record.get('company_name', '')).strip().lower(),
        str(address_record.get('street', '')).strip().lower(),
        str(address_record.get('city', '')).strip().lower(),
        str(address_record.get('zip_code', '')).strip().lower(),
        str(address_record.get('country_code', '')).strip().lower()
    ]
    raw_string = "|".join(components)
    return hashlib.sha256(raw_string.encode('utf-8')).hexdigest()
```

### B. La Funzione di "Upsert" dello Snapshot

Ogni volta che salviamo una bozza o confermiamo un documento, il backend esegue questa logica per decidere se creare una nuova riga in `document_addresses`:

```
def process_document_address(payload_address):
    master_id = payload_address.get('master_address_id')

    # Caso 1: Indirizzo scritto totalmente a mano da zero
    if not master_id:
        return DB.insert("document_addresses", {
            **payload_address,
            "master_address_id": None,
            "is_customized": True,
            "original_hash": None
        })

    # Caso 2: C'è un'anagrafica di riferimento
    master_addr = DB.select_by_id("master_addresses", master_id)
    master_hash = generate_address_hash(master_addr)
    payload_hash = generate_address_hash(payload_address)

    # Se l'hash del payload inviato è diverso dall'hash del master in DB
    is_customized = (master_hash != payload_hash)

    return DB.insert("document_addresses", {
        "company_name": payload_address['company_name'],
        "street": payload_address['street'],
        "city": payload_address['city'],
        "zip_code": payload_address['zip_code'],
        "country_code": payload_address['country_code'],
        "master_address_id": master_id,
        "is_customized": is_customized,
        "original_hash": master_hash
    })
```

## 3. Gestione Fase per Fase del Ciclo Acquisti

Vediamo ora esattamente cosa succede lato **DB, Backend, Frontend e UI/UX** in ognuno dei 5 step del ciclo.

> ✅ **CORREZIONE (verificato nel codice reale, 10/07/2026):** il diagramma qui sotto salta la Fase 4 (Preventivi Spontanei) per un errore di copia-incolla — passa direttamente da "Fase 3: Registra Preventivo" a "Fase 5: Ordine (PO)", anche se la Fase 4 è descritta poco più avanti in questo stesso documento. **In parole semplici:** la Fase 4 rappresenta il caso in cui un fornitore invia un'offerta di sua iniziativa, senza che gli sia mai stata chiesta (nessuna RFQ a monte) — è un percorso reale e pienamente funzionante nell'ERP (esiste un pulsante "Registra Preventivo Spontaneo"), semplicemente dimenticato nel disegno del flusso. Il diagramma corretto è:

```
 [Fase 1: Bozza RFQ] --> [Fase 2: RFQ Inviata] --> [Fase 3: Registra Preventivo] --> [Fase 5: Ordine (PO)]
 (Modificabile)          (Congelato)               (Eredita e Modifica)              (Blindato)
                                                     [Fase 4: Preventivo Spontaneo] --^
                                                     (nessuna RFQ a monte, stesso punto di ingresso della Fase 5)
```

### Fase 1: Creazione e Modifica Bozze di RFQ

Qui l'utente sta preparando la richiesta di offerta. È la fase di massima flessibilità.

#### Lato Database:

- Viene creato un record in `rfq_headers` con stato `DRAFT`.

- All'inizio, `delivery_address_id` e `vendor_contact_id` sono `NULL` o puntano ai record di snapshot generati automaticamente dai default dell'anagrafica.

#### Lato Backend:

- Quando l'utente seleziona il fornitore, il backend suggerisce i contatti della tabella `clientefornitore` (dove `parent_id` del contatto è uguale all'ID del fornitore).

- Suggerisce l'indirizzo di consegna proponendo di default l'indirizzo del magazzino aziendale selezionato come destinazione per la merce.

> ✅ **STATO ATTUALE VERIFICATO — nessun suggerimento automatico dell'indirizzo di consegna nella RFQ (10/07/2026):**
>
> **Cosa fa oggi l'ERP.** Il punto qui sopra descrive una funzionalità che, verificato nel codice (`includes/purchases_rfq.php` e `app/Controllers/RfqController.php`), **non è implementata**. L'utente sceglie l'indirizzo di consegna manualmente da un menu a tendina che elenca gli indirizzi disponibili in anagrafica per la nostra azienda, senza che nessuno venga preselezionato o evidenziato in base al proprio `tipo`. Non c'è alcuna logica che guardi la colonna `indirizzi.tipo` (i cui 5 valori ammessi — `sede_legale`, `sede_operativa`, `spedizione`, `fatturazione`, `magazzino` — sono definiti dal vincolo CHECK descritto in F2) per proporre in automatico l'indirizzo di tipo `magazzino` o `sede_operativa`. L'utente deve individuarlo da solo ogni volta.
>
> **📌 Nota per il futuro:** l'idea descritta sopra resta valida: si potrebbe far sì che l'ERP proponga automaticamente all'utente l'indirizzo giusto, scegliendolo tra quelli con `tipo = 'magazzino'` o `tipo = 'sede_operativa'` — un default intelligente, senza che l'utente debba cercarlo a mano. Non è ancora stato fatto; è un miglioramento da pianificare, non un comportamento già presente nell'ERP.

#### Lato Frontend:

- Mostra i selettori a discesa (dropdown) per l'indirizzo e per il contatto.

- Se l'utente preme "Salva Bozza", il frontend invia i dati correnti al backend.

#### Lato UI/UX:

- I campi dell'indirizzo e del contatto sono racchiusi in due box chiamati **"Destinazione Consegna"** e **"Referente Fornitore"**.

- Ogni box ha due pulsanti:
  
  1. **"Cambia"** (apre una lista di record anagrafici compatibili).
  
  2. **"Modifica"** (un'icona a matita che rende i campi di testo editabili direttamente dentro il box).

### Fase 2: Invio di Bozze RFQ (Passaggio a "Inviata")

L'utente decide di spedire la RFQ. Il documento viene inviato via email al fornitore ed è ora legalmente rilevante.

#### Lato Database:

- Lo stato di `rfq_headers` passa da `DRAFT` a `SENT`.

#### Lato Backend:

- **Il Trigger di Consolidamento (Snapshot):** Il backend prende l'indirizzo corrente associato alla RFQ. Se non era ancora stato isolato (era ancora una FK pura all'anagrafica o una bozza temporanea), esegue lo "snapshotting" definitivo.

- Crea un record permanente in `document_addresses` e uno in `document_contacts`.

- Aggiorna `rfq_headers` collegando le FK a questi nuovi record storici.

- **Lock di Sicurezza:** Qualsiasi richiesta di `PUT` o `PATCH` successiva su questa RFQ viene bloccata dal backend con un errore `403 Operation Not Allowed on Sent Documents`.

#### Lato Frontend:

- Disabilita tutti i controlli di editing dell'indirizzo e del contatto. Non ci sono più pulsanti "Cambia" o icone a matita.

#### Lato UI/UX:

- I box di indirizzo e contatto mostrano un'etichetta di testo statico pulita ed elegante.

- In alto a destra del box compare un badge verde scuro o blu con l'icona di un lucchetto chiuso: **"Dati Cristallizzati (Inviato)"**.

- Se l'utente prova a cliccare sulla card, compare un tooltip: *"Questo documento è stato inviato. Per modificare l'indirizzo, crea una nuova revisione del documento."*

> ✅ **STATO ATTUALE VERIFICATO — la parola "Preventivo" indica due documenti diversi a seconda del contesto (10/07/2026):**
>
> **Cosa fa oggi l'ERP.** Nel database esistono due tabelle completamente distinte: `preventivi` (usata lato **vendite**, per l'offerta che facciamo noi a un cliente) e `offerte_testata` (usata lato **acquisti**, per l'offerta che un fornitore fa a noi — quella descritta in questa Fase 3 e nella Fase 4). In italiano, la parola "preventivo" viene però usata per entrambe: sia nella documentazione di questa cartella, sia — verificato leggendo il codice reale — nell'interfaccia stessa dell'applicazione. Ad esempio, in `app/Controllers/PurchasesQuotesController.php` (che lavora sulla tabella `offerte_testata`, cioè le offerte dei fornitori) compare un pulsante con la scritta letterale **"Registra Preventivo Spontaneo"**. Chi legge solo il nome della tabella (`offerte_testata`) e chi legge solo l'interfaccia (che dice "Preventivo") può quindi pensare, a torto, di parlare di due cose diverse o della stessa cosa a seconda del punto di vista.
>
> **È un problema?** Non è un bug del software: è una scelta terminologica consapevole e usata in modo coerente in tutta l'applicazione (in italiano corrente, "preventivo" è il termine naturale sia per un'offerta ricevuta da un fornitore sia per una inviata a un cliente). Resta però un rischio concreto di confusione per chi legge la documentazione tecnica o il database senza conoscere già lo schema.
>
> **📌 Miglioramento consigliato per il futuro:** rinominare le tabelle del ciclo commerciale con nomi che specificano sempre il verso (vendita/acquisto), ad esempio:
> - `preventivi` → **`preventivi_vendita`**
> - `offerte_testata` → **`preventivi_acquisto`**
> - `ordini_clienti` → **`ordini_vendita`**
> - `ordini_testata` → **`ordini_acquisto`**
>
> Non ancora fatto (i nomi reali nel database restano quelli storici sopra elencati) — è una modifica di schema non banale, da pianificare come intervento a sé.

### Fase 3: Registrazione Preventivi che Rispondono a una RFQ

Il fornitore risponde alla nostra RFQ inviandoci la sua offerta. Dobbiamo registrarla a sistema.

#### Lato Database:

- Creiamo un record in `vendor_quotes_headers` (Stato iniziale: `DRAFT`).

#### Lato Backend:

- **Ereditarietà Rigida:** Il backend crea il preventivo ereditando (clonando) esattamente i dati di `delivery_address_id` e `vendor_contact_id` dalla RFQ di origine.

- Tuttavia, poiché il preventivo è in stato `DRAFT`, il backend consente la modifica di queste FK se l'utente desidera aggiornarle prima della conferma.

#### Lato Frontend:

- Mostra i dati ereditati dalla RFQ nei box di consegna e contatto.

- Lascia i campi aperti alla modifica. Se il fornitore specifica un referente diverso nell'offerta scritta, l'utente può cambiarlo.

#### Lato UI/UX:

- I box mostrano un badge informativo: **"Ereditato da RFQ #1024"**.

- Se l'utente clicca sulla matita per modificare ad esempio il contatto (inserendo il cellulare diretto scritto sul PDF del preventivo), il badge cambia istantaneamente in giallo: **"Contatto Personalizzato per questo Preventivo"**.

- Compare il pulsante **"Mostra Differenze"** che, se cliccato, mostra un confronto affiancato tra il contatto originale della RFQ e quello modificato nel preventivo.

### Fase 4: Registrazione Preventivi Spontanei

Un fornitore ci invia un'offerta cartacea o via mail senza che noi abbiamo mai emesso una RFQ a monte.

#### Lato Database:

- Viene creato un record in `vendor_quotes_headers` (Stato: `DRAFT`).

- `delivery_address_id` e `vendor_contact_id` sono inizialmente `NULL`.

#### Lato Backend:

- Poiché non c'è una RFQ a monte, la logica si comporta esattamente come la **Fase 1 (Bozza RFQ)**.

- Il backend cerca in anagrafica l'indirizzo di default della nostra sede/magazzino e il contatto predefinito del fornitore inserito, proponendoli come punto di partenza.

#### Lato Frontend & UI/UX:

- L'utente ha pieno accesso di selezione e scrittura a mano libera. I box si comportano in modo identico a quelli della Fase 1.

- Nel momento in cui il preventivo viene salvato definitivamente (es. Stato passa a `ACTIVE` o `CONFIRMED`), il backend cristallizza l'indirizzo e il contatto nel DB esattamente come descritto nella Fase 2.

### Fase 5: Accettazione del Preventivo e Conversione in Ordine (PO)

Accettiamo il preventivo del fornitore. Il sistema genera automaticamente l'Ordine d'Acquisto (Purchase Order) che è un contratto vincolante a tutti gli effetti.

#### Lato Database:

- Lo stato di `vendor_quotes_headers` passa a `ACCEPTED` (totalmente blindato).

- Viene generato un record in `purchase_orders_headers` (Stato: `DRAFT` prima dell'approvazione, poi `CONFIRMED` per l'invio).

#### Lato Backend:

- **Integrità Assoluta:** Il backend copia le FK `delivery_address_id` e `vendor_contact_id` direttamente dal preventivo accettato all'ordine. Non è consentito caricare dati diversi in questa fase di transizione automatica.

- **Meccanismo di Variazione (Order Amendment):** Se l'ordine è in stato `CONFIRMED` (inviato) e il fornitore ci comunica un cambio di indirizzo di consegna dell'ultimo minuto, il backend **non** permette la modifica diretta dei campi. L'utente deve attivare l'azione "Crea Revisione":

> ✅ **STATO ATTUALE VERIFICATO (10/07/2026)** — Questo meccanismo esiste davvero ed è realmente usato (`includes/purchases_orders.php`, funzione di clonazione ordine), ma con due differenze concrete rispetto a come è descritto qui sotto. **In parole semplici, per chi non conosce il database:** quando un ordine già inviato al fornitore deve cambiare (es. un nuovo indirizzo di consegna dell'ultimo minuto), l'ERP non modifica l'ordine originale — ne crea una copia nuova, "collegata" alla vecchia, che rappresenta la versione aggiornata. Le due differenze reali sono:
> 1. **Il numero di partenza non è 0, è 1.** Il campo reale si chiama `versione` (non `revision_number`) e ogni ordine nuovo parte già da `versione = 1`, non da `0`. Una prima revisione porta quindi l'ordine da versione **1** a versione **2** (non da 0 a 1). Questo è più intuitivo per l'utente: un ordine mostrato per la prima volta come "v1" è più naturale di uno mostrato come "v0".
> 2. **Non esiste una parola-stato tipo `SUPERSEDED` scritta in inglese**: l'ordine precedente viene semplicemente marcato con lo stato `superato` (in italiano, coerente con tutti gli altri stati del sistema, che sono in italiano: `bozza`, `inviato`, `annullato`, ecc.).
>
> Il meccanismo di fondo — clonare l'ordine, bloccare l'originale, permettere la modifica solo sulla nuova versione, poi re-inviarla — funziona esattamente come descritto nei punti 1-4 sotto, solo con questi due dettagli numerici/lessicali diversi.

  1. Il backend clona l'ordine incrementando il campo `revision_number` (es. da `0` a `1`).
  
  2. L'ordine originale passa allo stato `SUPERSEDED` (Sostituito).
  
  3. Sulla revisione `1` (che torna temporaneamente in stato modificabile), il backend permette di inserire il nuovo indirizzo (generando una nuova riga in `document_addresses`).
  
  4. La revisione `1` viene approvata e inviata nuovamente.

#### Lato Frontend & UI/UX:

- Sull'ordine d'acquisto confermato, i box degli indirizzi mostrano un lucchetto d'acciaio con un badge rosso: **"Contratto Confermato (Immodificabile)"**.

- Se l'utente ha i permessi di acquisto, in alto compare il pulsante di azione primaria: **"Richiedi Variazione d'Ordine (Crea Revisione)"**.

- Se cliccato, lo schermo effettua una transizione visiva, mostra la nuova bozza dell'ordine `v2` e sblocca i campi di indirizzo e contatto per consentire l'inserimento delle nuove informazioni.

## 4. UI/UX: Dettaglio dei Componenti "Smart"

La vera forza di questa architettura si esprime nell'interfaccia grafica. Ecco come devono essere realizzati i componenti frontend per garantire la massima semplicità d'uso.

> ✅ **STATO ATTUALE VERIFICATO (10/07/2026)** — L'interfaccia reale (verificata in `assets/js/rfq-form.js` e `assets/js/sales-quotes.js`) usa testi diversi da quelli scritti in questa sezione, e il pulsante "Salva in Anagrafica" esiste solo lato vendite, non lato acquisti. Vedi la nota dettagliata identica nel documento "Versione Integrata ERP" (§4, stessa cartella), sezioni A e B, per la spiegazione punto per punto comprensibile anche a chi non conosce il database — qui di seguito solo la tabella di corrispondenza rapida: badge verde reale = **"✓ Sincronizzato con anagrafica"** (non "Anagrafica Allineata"); badge giallo reale = **"Modificato localmente (override)"** (non "Indirizzo Modificato"); badge blu/grigio reale = **"Recapito manuale / una tantum"** (non "Solo per questo Documento"). Il pulsante "Ripristina" esiste ed è funzionante su entrambi i cicli; il pulsante "Salva in Anagrafica" esiste solo per i preventivi di vendita, come casella di spunta al salvataggio invece che come pulsante con dialog separato, ed è del tutto assente sul ciclo acquisti (RFQ).

### A. La Card di Visualizzazione dell'Indirizzo

Questo componente React (o Angular) deve mostrare chiaramente lo stato dell'indirizzo in base ai tre metadati (`master_address_id`, `is_customized` e l'allineamento dell'hash).

```
+-------------------------------------------------------------+
|  CONSEGNA A: MAGAZZINO CENTRALE      [ Indirizzo Modificato ]| <--- Badge Giallo
|                                                             |
|  ACME Logistics S.r.l.                                      |
|  Via delle Industrie 15/A, Interno 2                        |
|  20100 - Milano (MI)                                        |
|                                                             |
|  [ Ripristina Originale ]          [ Salva in Anagrafica ]  | <--- Azioni intelligenti
+-------------------------------------------------------------+
```

- **Stato 1: Standard (Nessuna modifica)**
  
  - `master_address_id` != NULL, `is_customized` == FALSE.
  
  - *UI:* Mostra un badge verde chiaro **"Anagrafica Allineata"**. Nessun pulsante speciale.

- **Stato 2: Modificato (Customizzato)**
  
  - `master_address_id` != NULL, `is_customized` == TRUE.
  
  - *UI:* Mostra un badge giallo **"Indirizzo Modificato"**. Mostra i pulsanti **"Ripristina Originale"** e **"Salva in Anagrafica"**.

- **Stato 3: Scritto a mano (Estemporaneo Puro)**
  
  - `master_address_id` == NULL.
  
  - *UI:* Mostra un badge grigio **"Solo per questo Documento"**. Mostra il pulsante **"Salva in Anagrafica"** per permettere di censirlo come nuovo indirizzo permanente se necessario.

### B. Il Funzionamento delle Azioni Smart

#### 1. "Ripristina Originale" (UX Flawless)

Quando l'utente clicca su questo pulsante:

1. Il frontend effettua una richiesta API: `GET /api/v1/master-addresses/{master_address_id}`.

2. Il frontend sovrascrive i campi a schermo con i valori originali dell'anagrafica.

3. Imposta il flag `is_customized` a `FALSE` e nasconde il badge giallo.

4. Al successivo salvataggio della bozza, il backend eliminerà la riga di snapshot personalizzata e collegherà il documento a una copia pulita del master.

#### 2. "Salva in Anagrafica" (La promozione del dato)

Se l'utente ha inserito una modifica utile (es. ha corretto il numero di telefono del referente o ha aggiunto un interno corretto all'indirizzo), può promuovere il dato senza dover andare nel modulo anagrafiche del fornitore:

1. L'utente clicca su **"Salva in Anagrafica"**.

2. Il frontend apre un dialog di conferma: *"Vuoi salvare questo indirizzo modificato come nuovo indirizzo permanente nell'anagrafica del fornitore?"*.

3. Alla conferma, il backend effettua due operazioni in un'unica transazione atomica:
   
   - Crea un nuovo record nella tabella `master_addresses` associato al fornitore corrente.
   
   - Aggiorna il record `document_addresses` del documento, impostando `master_address_id` = ID del nuovo record master appena creato, e imposta `is_customized` a `FALSE` (poiché ora l'indirizzo del documento coincide perfettamente con il nuovo record master).

4. La UI si aggiorna mostrando il badge verde **"Anagrafica Allineata"**.

## 5. Gestione dei Casi Limite (Edge Cases)

Un ERP solido si riconosce da come gestisce i problemi di sincronizzazione dei dati. Ecco i tre casi più frequenti e le relative soluzioni adottate in questo modello.

> ✅ **STATO ATTUALE VERIFICATO (10/07/2026)** — Di questi tre casi limite, il **Caso 1 e il Caso 3 non sono implementati** (cercati a tappeto nel codice: nessuna traccia, né del banner del Caso 1 né del cron/pulizia del Caso 3). Il **Caso 2 è implementato**, ma solo a metà rispetto a come è descritto qui sotto, e la metà mancante è corretta: lato acquisti (RFQ) il cambio fornitore resetta solo il contatto referente, non l'indirizzo di consegna — perché l'indirizzo di consegna in una RFQ è il *nostro* magazzino, non un dato del fornitore, quindi non ha senso cambiarlo quando si cambia fornitore. Lato vendite (preventivo al cliente) invece il cambio cliente resetta correttamente sia l'indirizzo sia il contatto, come descritto sotto. Dettaglio completo, comprensibile anche a chi non conosce il database, nel documento "Versione Integrata ERP" (stessa cartella), sezione 5.

### Caso Limite 1: L'anagrafica Master viene modificata *dopo* che la bozza del documento è stata creata

- **Il problema:** L'utente ha creato una bozza di RFQ usando l'indirizzo master del magazzino "Milano" (ID 45). Il giorno dopo, l'amministratore dell'ERP corregge il CAP del magazzino "Milano" in anagrafica globale. Cosa deve succedere alla bozza della RFQ che non è ancora stata inviata?

- **La soluzione (L'allineamento silente delle bozze):**
  
  - Finché un documento è in stato `DRAFT`, i dati devono essere dinamici e allineati all'anagrafica corrente.
  
  - Quando l'utente apre la bozza della RFQ sul frontend, il frontend calcola l'hash dei dati memorizzati nel record di snapshot della bozza e lo confronta con l'hash calcolato al volo sui dati correnti della tabella master.
  
  - Se gli hash differiscono, il frontend mostra un banner discreto in cima alla pagina:
    
    - ⚠️ *"I dati anagrafici di questo indirizzo sono stati aggiornati di recente. [Clicca qui per aggiornare la bozza all'ultimo indirizzo ufficiale]"*.
  
  - Se l'utente clicca, la bozza viene aggiornata. Altrimenti, mantiene i dati con cui era stata creata. In ogni caso, al momento dell'invio definitivo (Fase 2), lo snapshot congelerà i dati correnti, risolvendo qualsiasi ambiguità.

### Caso Limite 2: Il fornitore del documento viene modificato in corso d'opera

- **Il problema:** L'utente crea una RFQ per il fornitore "ACME", seleziona un contatto di ACME (ID 12) e apporta una modifica manuale al cellulare del contatto (generando uno snapshot con `master_contact_id = 12` e `is_customized = true`). Poi si rende conto di aver sbagliato fornitore e seleziona "XYZ Corp".

- **La soluzione (Il Reset Intelligente):**
  
  - Se l'utente cambia il fornitore di testata del documento, il frontend deve effettuare un **reset completo** della sezione contatti ed indirizzi specifici del fornitore.
  
  - Il frontend cancella le FK `delivery_address_id` e `vendor_contact_id` correnti.
  
  - Propone automaticamente i nuovi default del nuovo fornitore "XYZ Corp".
  
  - Il backend, al salvataggio della bozza, marcherà come orfani i vecchi record di snapshot del vecchio fornitore (che verranno puliti dalle routine di manutenzione periodica del DB).

### Caso Limite 3: Pulizia periodica degli Snapshot "Orfani"

- **Il problema:** Durante le modifiche in stato di `DRAFT`, gli utenti potrebbero creare molti record di snapshot in `document_addresses` e `document_contacts` che poi non vengono realmente utilizzati perché i documenti vengono cancellati o modificati più volte.

- **La soluzione:**
  
  - Il database prevede un processo batch notturno (un cron job di backend) che effettua una semplice query di pulizia (Garbage Collection):
  
  - Elimina tutti i record di `document_addresses` e `document_contacts` che **non** sono referenziati da nessuna riga delle tabelle `rfq_headers`, `vendor_quotes_headers` o `purchase_orders_headers`. Questo mantiene il database sempre leggero, reattivo e pulito.
