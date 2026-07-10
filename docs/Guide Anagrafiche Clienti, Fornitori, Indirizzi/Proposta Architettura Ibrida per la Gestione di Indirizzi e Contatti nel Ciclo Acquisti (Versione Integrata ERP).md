# Specifica Tecnica: Architettura Ibrida per la Gestione di Indirizzi e Contatti nel Ciclo Acquisti (Versione Integrata ERP)

Questa guida descrive l'architettura effettiva e definitiva per la gestione di indirizzi di consegna e contatti referenti all'interno del ciclo acquisti di Quack ERP.

L'obiettivo è unire la **pulizia strutturale di SAP** (evitando di intasare le tabelle dei documenti con decine di colonne di testo duplicato) e la **tracciabilità semantica di Dynamics 365** (sapere sempre se un indirizzo è originale o modificato, permettendo azioni intelligenti come il ripristino o il salvataggio in anagrafica).

Questa specifica costituisce la versione allineata e integrata con lo stato del database e del backend dell'applicazione, mantenendo inalterati i principi logici della proposta originaria ma traducendoli nelle reali convenzioni e strutture implementate.

---

## 1. Lo Strato Database (DB Schema)

Per far funzionare questo modello, dividiamo nettamente il database in tre aree logiche: **Anagrafiche (Master Data)**, **Documenti (Transactional Data)** e **Copie Storiche (Snapshot Data)**.

```
                  ┌──────────────────────────────────────────┐
                  │          TABELLE ANAGRAFICHE             │
                  │   (clienti_fornitori, indirizzi)         │
                  └────────────────────┬─────────────────────┘
                                       │
                                       ▼ (Copia / Snapshot)
                  ┌──────────────────────────────────────────┐
                  │           TABELLE DI SNAPSHOT            │
                  │   (indirizzi_documenti,                  │
                  │    contatti_documenti)                   │
                  └────────────────────┬─────────────────────┘
                                       │
             ┌──────────────────────────┼──────────────────────────┐
             │ (FK)                     │ (FK)                     │ (FK)
             ▼                          ▼                          ▼
 ┌──────────────────────┐   ┌──────────────────────┐   ┌──────────────────────┐
 │     rfq_testata      │   │   offerte_testata    │   │    ordini_testata    │
 └──────────────────────┘   └──────────────────────┘   └──────────────────────┘
```

### A. Tabelle di Snapshot (Il "Cuscino" tra Anagrafica e Documento)

Queste tabelle memorizzano i dati fisici di consegna e contatto per *ogni singolo documento*. Nessun documento punterà direttamente alle tabelle anagrafiche master una volta finalizzato.

#### Tabella `indirizzi_documenti`

Memorizza l'indirizzo esatto di consegna associato a un documento.

| Campo                 | Tipo           | Descrizione                                                                                                             |
| --------------------- | -------------- | ----------------------------------------------------------------------------------------------------------------------- |
| `id`                  | INT (PK)       | Chiave primaria autoincrementale (SQLite: `INTEGER PRIMARY KEY AUTOINCREMENT`).                                         |
| `indirizzo_master_id` | INT (FK) NULL  | Punta a `indirizzi.id`. Diventa `NULL` se l'indirizzo è scritto interamente a mano libera.                               |
| `nome_sede`           | VARCHAR(100)   | Nome identificativo della sede o magazzino.                                                                             |
| `presso`              | VARCHAR(100)   | Indicazione c/o o presso.                                                                                               |
| `via`                 | VARCHAR(150)   | Via e indirizzo stradale.                                                                                               |
| `civico`              | VARCHAR(10)    | Numero civico.                                                                                                          |
| `cap`                 | VARCHAR(10)    | Codice di Avviamento Postale.                                                                                           |
| `citta`               | VARCHAR(100)   | Comune / Città.                                                                                                         |
| `provincia`           | CHAR(2)        | Sigla della provincia (es. MI, RM).                                                                                     |
| `nazione`             | CHAR(2)        | Codice nazione ISO 3166-1 alpha-2 (es. IT, DE). Default: "IT".                                                          |
| `referente_consegna`  | VARCHAR(80)    | Persona di riferimento presso il luogo di consegna.                                                                     |
| `telefono_consegna`   | VARCHAR(20)    | Recapito telefonico associato alla consegna.                                                                            |
| `note_consegna`       | VARCHAR(255)   | Eventuali note per il corriere.                                                                                         |
| `personalizzato`      | BOOLEAN        | Valore `1` (TRUE) se l'utente ha modificato manualmente anche solo un carattere rispetto al master originale.           |
| `hash_originale`      | VARCHAR(64)    | Hash SHA-256 dei campi master al momento della copia. Serve per capire se l'anagrafica di origine è cambiata nel tempo.  |

#### Tabella `contatti_documenti`

Memorizza il referente del fornitore associato al documento.

| Campo                 | Tipo           | Descrizione                                                                                                             |
| --------------------- | -------------- | ----------------------------------------------------------------------------------------------------------------------- |
| `id`                  | INT (PK)       | Chiave primaria autoincrementale.                                                                                       |
| `contatto_master_id`  | INT (FK) NULL  | Punta a `clienti_fornitori.id` del sottocontatto (con `parent_id` non nullo). `NULL` se creato a mano libera.           |
| `nome`                | VARCHAR(80)    | Nome del referente.                                                                                                     |
| `cognome`             | VARCHAR(80)    | Cognome del referente.                                                                                                  |
| `email`               | VARCHAR(150)   | Email del referente.                                                                                                    |
| `telefono`            | VARCHAR(20)    | Numero di telefono.                                                                                                     |
| `cellulare`           | VARCHAR(20)    | Telefono cellulare.                                                                                                     |
| `personalizzato`      | BOOLEAN        | Valore `1` (TRUE) se modificato rispetto al contatto master.                                                            |
| `hash_originale`      | VARCHAR(64)    | Hash SHA-256 dei dati del contatto master originale.                                                                    |

### B. Le Tabelle dei Documenti (Le Testate del Ciclo Acquisti)

Abbiamo tre tabelle separate. Ognuna di esse ha una relazione con le tabelle di snapshot tramite Foreign Key. Per motivi di retrocompatibilità con stampe PDF storiche e reportistica preesistente, le tabelle di testata mantengono le vecchie colonne testuali denormalizzate (`consegna_*` e `contatto_*`). Tuttavia, la sorgente di verità strutturata e modificabile risiede nelle tabelle di snapshot tramite le seguenti relazioni di Foreign Key:

- **`rfq_testata`**: Collega `indirizzo_consegna_id` (FK a `indirizzi_documenti`) e `contatto_id` (FK a `contatti_documenti`).
- **`offerte_testata`**: Collega `indirizzo_consegna_id` (FK a `indirizzi_documenti`) e `contatto_id` (FK a `contatti_documenti`).
- **`ordini_testata`**: Collega `indirizzo_consegna_id` (FK a `indirizzi_documenti`) e `contatto_id` (FK a `contatti_documenti`).

---

## 2. Lo Strato Backend (La Logica Operativa)

Il backend ha tre compiti fondamentali: calcolare l'integrità dei dati (generazione hash), clonare i record quando necessario e bloccare le modifiche sui documenti confermati.

### A. L'Algoritmo di Rilevamento delle Modifiche (Hashing)

Quando l'utente modifica un indirizzo o contatto, come fa il backend a capire se è diverso dal master? Usa una funzione di hashing deterministica che normalizza le stringhe.

```php
function qk_generate_address_hash(array $addr): string {
    $parts = [
        trim((string)($addr['nome_sede'] ?? '')),
        trim((string)($addr['via'] ?? '')),
        trim((string)($addr['civico'] ?? '')),
        trim((string)($addr['cap'] ?? '')),
        trim((string)($addr['citta'] ?? '')),
        trim((string)($addr['provincia'] ?? '')),
        trim((string)($addr['nazione'] ?? 'IT'))
    ];
    $raw = strtolower(implode('|', $parts));
    return hash('sha256', $raw);
}

function qk_generate_contact_hash(array $cont): string {
    $parts = [
        trim((string)($cont['nome'] ?? '')),
        trim((string)($cont['cognome'] ?? '')),
        trim((string)($cont['email'] ?? '')),
        trim((string)($cont['telefono'] ?? ''))
    ];
    $raw = strtolower(implode('|', $parts));
    return hash('sha256', $raw);
}
```

### B. La Funzione di "Upsert" dello Snapshot

Ogni volta che salviamo una bozza o confermiamo un documento, il backend esegue questa logica per decidere se creare o aggiornare una riga in `indirizzi_documenti`:

```php
function qk_upsert_document_address(PDO $db, ?int $existingDocAddrId, array $payload, ?int $masterAddrId): int {
    $masterHash = null;
    $personalizzato = 0;

    if ($masterAddrId > 0) {
        $stmt = $db->prepare("SELECT * FROM indirizzi WHERE id = ?");
        $stmt->execute([$masterAddrId]);
        $master = $stmt->fetch();
        if ($master) {
            $masterHash = qk_generate_address_hash($master);
            $payloadHash = qk_generate_address_hash($payload);
            if ($masterHash !== $payloadHash) {
                $personalizzato = 1;
            }
        }
    } else {
        $personalizzato = 1;
    }

    if ($existingDocAddrId > 0) {
        $stmt = $db->prepare("UPDATE indirizzi_documenti SET 
            indirizzo_master_id = ?, nome_sede = ?, presso = ?, via = ?, civico = ?, 
            cap = ?, citta = ?, provincia = ?, nazione = ?, referente_consegna = ?, 
            telefono_consegna = ?, note_consegna = ?, personalizzato = ?, hash_originale = ? 
            WHERE id = ?");
        $stmt->execute([
            $masterAddrId > 0 ? $masterAddrId : null,
            $payload['nome_sede'] ?? null,
            $payload['presso'] ?? null,
            $payload['via'] ?? '',
            $payload['civico'] ?? null,
            $payload['cap'] ?? '',
            $payload['citta'] ?? '',
            $payload['provincia'] ?? null,
            $payload['nazione'] ?? 'IT',
            $payload['referente_consegna'] ?? null,
            $payload['telefono_consegna'] ?? null,
            $payload['note_consegna'] ?? null,
            $personalizzato,
            $masterHash,
            $existingDocAddrId
        ]);
        return $existingDocAddrId;
    } else {
        $stmt = $db->prepare("INSERT INTO indirizzi_documenti (
            indirizzo_master_id, nome_sede, presso, via, civico, cap, citta, 
            provincia, nazione, referente_consegna, telefono_consegna, note_consegna, 
            personalizzato, hash_originale
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $masterAddrId > 0 ? $masterAddrId : null,
            $payload['nome_sede'] ?? null,
            $payload['presso'] ?? null,
            $payload['via'] ?? '',
            $payload['civico'] ?? null,
            $payload['cap'] ?? '',
            $payload['citta'] ?? '',
            $payload['provincia'] ?? null,
            $payload['nazione'] ?? 'IT',
            $payload['referente_consegna'] ?? null,
            $payload['telefono_consegna'] ?? null,
            $payload['note_consegna'] ?? null,
            $personalizzato,
            $masterHash
        ]);
        return (int)$db->lastInsertId();
    }
}
```

---

## 3. Gestione Fase per Fase del Ciclo Acquisti

Vediamo ora esattamente cosa succede lato **DB, Backend, Frontend e UI/UX** in ognuno dei 5 step del ciclo.

> ✅ **CORREZIONE (verificato nel codice reale, 10/07/2026):** il diagramma qui sotto, nella versione originale di questo documento, saltava la Fase 4 (Preventivi Spontanei) per un errore di copia-incolla — passava direttamente da "Fase 3: Registra Preventivo" a "Fase 5: Ordine (PO)". La Fase 4 esiste davvero, è pienamente implementata (`app/Controllers/PurchasesQuotesController.php` ha un pulsante con testo letterale "Registra Preventivo Spontaneo") ed è descritta poco sotto in questo stesso documento. Il diagramma corretto è:

```
 [Fase 1: Bozza RFQ] --> [Fase 2: RFQ Inviata] --> [Fase 3: Registra Preventivo] --> [Fase 5: Ordine (PO)]
 (Modificabile)          (Congelato)               (Eredita e Modifica)              (Blindato)
                                                     [Fase 4: Preventivo Spontaneo] --^
                                                     (nessuna RFQ a monte, stesso punto di ingresso della Fase 5)
```

### Fase 1: Creazione e Modifica Bozze di RFQ

Qui l'utente sta preparando la richiesta di offerta. È la fase di massima flessibilità.

#### Lato Database:
- Viene creato un record in `rfq_testata` con stato `bozza`.
- All'inizio, `indirizzo_consegna_id` e `contatto_id` possono essere `NULL` o puntare ai record di snapshot generati automaticamente dai default dell'anagrafica.

#### Lato Backend:
- Quando l'utente seleziona il fornitore, il backend suggerisce i contatti della tabella `clienti_fornitori` (dove `parent_id` del contatto è uguale all'ID del fornitore).
- Suggerisce l'indirizzo di consegna proponendo di default l'indirizzo della sede/magazzino aziendale di spedizione della merce.

> ✅ **STATO ATTUALE VERIFICATO — nessun suggerimento automatico dell'indirizzo di consegna nella RFQ (10/07/2026):**
>
> **Cosa fa oggi l'ERP.** Il punto qui sopra descrive una funzionalità che, verificato nel codice (`includes/purchases_rfq.php` e `app/Controllers/RfqController.php`), **non è implementata**. Nella pratica l'utente crea la RFQ e sceglie l'indirizzo di consegna manualmente da un menu a tendina che elenca gli indirizzi disponibili in anagrafica per la nostra azienda, senza che nessuno di questi venga preselezionato o evidenziato in base al proprio `tipo`. Non c'è quindi alcuna logica che guardi la colonna `indirizzi.tipo` (i cui 5 valori ammessi — `sede_legale`, `sede_operativa`, `spedizione`, `fatturazione`, `magazzino` — sono definiti dal vincolo CHECK descritto in F2) per proporre in automatico l'indirizzo di tipo `magazzino` o `sede_operativa` come punto di partenza. L'utente deve individuarlo da solo ogni volta, tra tutti gli indirizzi della propria azienda presenti in anagrafica.
>
> **📌 Nota per il futuro:** l'idea descritta sopra resta valida e vale la pena implementarla: si potrebbe far sì che l'ERP proponga automaticamente all'utente l'indirizzo giusto, scegliendolo tra quelli con `tipo = 'magazzino'` o `tipo = 'sede_operativa'` — cioè un default intelligente, senza che l'utente debba cercarlo a mano. Non è ancora stato fatto; è un miglioramento da pianificare, non un comportamento già presente nell'ERP.

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
- Lo stato di `rfq_testata` passa da `bozza` a `inviata`.

#### Lato Backend:
- **Il Trigger di Consolidamento (Snapshot):** Il backend prende l'indirizzo corrente associato alla RFQ. Se non era ancora stato isolato, esegue lo "snapshotting" definitivo.
- Crea un record permanente in `indirizzi_documenti` e uno in `contatti_documenti`.
- Aggiorna `rfq_testata` collegando le FK a questi nuovi record storici. Copia i valori testuali piatti nelle colonne denormalizzate `consegna_*` e `contatto_*` per consistenza e velocità di lettura (PDF).
- **Lock di Sicurezza:** Qualsiasi richiesta di modifica successiva su questa RFQ viene bloccata dal backend con un errore `403 Operation Not Allowed on Sent Documents`.

#### Lato Frontend:
- Disabilita tutti i controlli di editing dell'indirizzo e del contatto. Non ci sono più pulsanti "Cambia" o icone a matita.

#### Lato UI/UX:
- I box di indirizzo e contatto mostrano un'etichetta di testo statico pulita ed elegante.
- In alto a destra del box compare un badge verde scuro o blu con l'icona di un lucchetto chiuso: **"Dati Cristallizzati (Inviato)"**.

> ✅ **STATO ATTUALE VERIFICATO — la parola "Preventivo" indica due documenti diversi a seconda del contesto (10/07/2026):**
>
> **Cosa fa oggi l'ERP.** Nel database esistono due tabelle completamente distinte: `preventivi` (usata lato **vendite**, per l'offerta che facciamo noi a un cliente) e `offerte_testata` (usata lato **acquisti**, per l'offerta che un fornitore fa a noi — quella descritta in questa Fase 3 e nella Fase 4). In italiano, la parola "preventivo" viene però usata per entrambe: sia nella documentazione di questa cartella, sia — è stato verificato leggendo il codice reale — nell'interfaccia stessa dell'applicazione. Ad esempio, in `app/Controllers/PurchasesQuotesController.php` (che lavora sulla tabella `offerte_testata`, cioè le offerte dei fornitori) compare un pulsante con la scritta letterale **"Registra Preventivo Spontaneo"**. Chi legge solo il nome della tabella (`offerte_testata`) e chi legge solo l'interfaccia (che dice "Preventivo") può quindi pensare, a torto, di parlare di due cose diverse o della stessa cosa a seconda del punto di vista.
>
> **È un problema?** Non è un bug del software: è una scelta terminologica consapevole e usata in modo coerente in tutta l'applicazione (in italiano corrente, "preventivo" è il termine naturale sia per un'offerta ricevuta da un fornitore sia per una inviata a un cliente). Resta però un rischio concreto di confusione per chi legge la documentazione tecnica o il database senza conoscere già lo schema: una persona che cerca la tabella del "preventivo" partendo dal nome ovvio (`preventivi`) troverebbe la tabella sbagliata se in realtà cercava le offerte dei fornitori.
>
> **📌 Miglioramento consigliato per il futuro:** per eliminare l'ambiguità alla radice, converrebbe rinominare le tabelle del ciclo commerciale con nomi che specificano sempre il verso (vendita/acquisto), ad esempio:
> - `preventivi` → **`preventivi_vendita`** (offerta nostra verso il cliente)
> - `offerte_testata` → **`preventivi_acquisto`** (offerta del fornitore verso di noi)
> - `ordini_clienti` → **`ordini_vendita`**
> - `ordini_testata` → **`ordini_acquisto`**
>
> Questo non è ancora stato fatto (i nomi reali nel database, ad oggi, restano quelli storici sopra elencati) — è una modifica di schema non banale (richiede aggiornare ogni riferimento nel codice PHP/JS e nelle query), quindi va pianificata come intervento a sé, non improvvisata.

### Fase 3: Registrazione Preventivi che Rispondono a una RFQ

Il fornitore risponde alla nostra RFQ inviandoci la sua offerta. Dobbiamo registrarla a sistema.

#### Lato Database:
- Creiamo un record in `offerte_testata` (Stato iniziale: `bozza`).

#### Lato Backend:
- **Ereditarietà Rigida:** Il backend crea il preventivo ereditando (clonando) esattamente i dati di `indirizzo_consegna_id` e `contatto_id` dalla RFQ di origine.
- Tuttavia, poiché il preventivo è in stato `bozza`, il backend consente la modifica di queste FK se l'utente desidera aggiornarle prima della conferma.

#### Lato Frontend:
- Mostra i dati ereditati dalla RFQ nei box di consegna e contatto.
- Lascia i campi aperti alla modifica. Se il fornitore specifica un referente diverso nell'offerta scritta, l'utente può cambiarlo.

#### Lato UI/UX:
- I box mostrano un badge informativo: **"Ereditato da RFQ"**.
- Se l'utente clicca sulla matita per modificare ad esempio il contatto (inserendo il cellulare diretto scritto sul PDF del preventivo), il badge cambia istantaneamente in giallo: **"Contatto Personalizzato per questo Preventivo"**.
- Compare il pulsante **"Mostra Differenze"** che, se cliccato, mostra un confronto affiancato tra il contatto originale della RFQ e quello modificato nel preventivo.

### Fase 4: Registrazione Preventivi Spontanei

Un fornitore ci invia un'offerta cartacea o via mail senza che noi abbiamo mai emesso una RFQ a monte.

#### Lato Database:
- Viene creato un record in `offerte_testata` (Stato: `bozza`).
- `indirizzo_consegna_id` e `contatto_id` sono inizialmente `NULL`.

#### Lato Backend:
- Poiché non c'è una RFQ a monte, la logica si comporta esattamente come la **Fase 1 (Bozza RFQ)**.
- Il backend cerca in anagrafica l'indirizzo di default della nostra sede/magazzino e il contatto predefinito del fornitore inserito, proponendoli come punto di partenza.

#### Lato Frontend & UI/UX:
- L'utente ha pieno accesso di selezione e scrittura a mano libera. I box si comportano in modo identico a quelli della Fase 1.
- Nel momento in cui il preventivo viene salvato definitivamente (es. Stato passa ad `accettata`), il backend cristallizza l'indirizzo e il contatto nel DB esattamente come descritto nella Fase 2.

### Fase 5: Accettazione del Preventivo e Conversione in Ordine (PO)

Accettiamo il preventivo del fornitore. Il sistema genera automaticamente l'Ordine d'Acquisto (Purchase Order) che è un contratto vincolante a tutti gli effetti.

#### Lato Database:
- Lo stato di `offerte_testata` passa a `accettata` (totalmente blindato).
- Viene generato un record in `ordini_testata` (Stato: `bozza` prima dell'approvazione, poi `confermato` per l'invio).

#### Lato Backend:
- **Integrità Assoluta:** Il backend copia le FK `indirizzo_consegna_id` e `contatto_id` direttamente dal preventivo accettato all'ordine. Non è consentito caricare dati diversi in questa fase di transizione automatica.
- **Meccanismo di Variazione (Order Amendment):** Se l'ordine è in stato `confermato` (inviato) e il fornitore ci comunica un cambio di indirizzo di consegna dell'ultimo minuto, il backend **non** permette la modifica diretta dei campi. L'utente deve attivare l'azione "Crea Revisione":
  1. Il backend clona l'ordine incrementando il campo `versione` (es. da `1` a `2`).
  2. L'ordine precedente viene marcato come superato/annullato.
  3. Sulla nuova revisione (che torna temporaneamente in stato modificabile), il backend permette di inserire il nuovo indirizzo (generando una nuova riga in `indirizzi_documenti`).
  4. La nuova revisione viene approvata e inviata nuovamente.

#### Lato Frontend & UI/UX:
- Sull'ordine d'acquisto confermato, i box degli indirizzi mostrano un lucchetto d'acciaio con un badge rosso: **"Contratto Confermato (Immodificabile)"**.
- Se l'utente ha i permessi di acquisto, in alto compare il pulsante di azione primaria: **"Richiedi Variazione d'Ordine (Crea Revisione)"**.
- Se cliccato, lo schermo effettua una transizione visiva, mostra la nuova bozza dell'ordine e sblocca i campi di indirizzo e contatto per consentire l'inserimento delle nuove informazioni.

---

## 4. UI/UX: Dettaglio dei Componenti "Smart"

La vera forza di questa architettura si esprime nell'interfaccia grafica. Ecco come devono essere realizzati i componenti frontend per garantire la massima semplicità d'uso.

### A. La Card di Visualizzazione dell'Indirizzo

Questo componente deve mostrare chiaramente lo stato dell'indirizzo in base ai tre metadati (`indirizzo_master_id`, `personalizzato` e l'allineamento dell'hash).

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

> ✅ **STATO ATTUALE VERIFICATO (10/07/2026)** — Tutta la sezione 4 qui sotto (badge e azioni "smart") descriveva il comportamento *previsto*, non ancora confrontato con l'interfaccia realmente costruita. Controllato ora file per file (`assets/js/rfq-form.js`, `assets/js/sales-quotes.js`, `app/views/purchases/rfq/_form.php`, `app/views/sales/quotes/_form.php`, `app/Controllers/SalesQuotesController.php`). Spiegazione pensata anche per chi non è uno sviluppatore:
>
> **Cos'è, in parole semplici, questo meccanismo?** Quando l'utente compila un documento (una richiesta d'offerta, un preventivo, un ordine) e sceglie un indirizzo o un contatto già presenti in anagrafica, l'ERP mostra un'etichetta colorata ("badge") che dice se quell'indirizzo/contatto è ancora identico a come è scritto in anagrafica, oppure se l'utente lo ha modificato a mano solo per questo documento specifico (es. ha aggiunto "consegnare solo di mattina"), oppure se non è affatto collegato a un'anagrafica (scritto a mano libera per un caso una tantum).
>
> **1. I tre badge esistono davvero, ma con un testo diverso da quello scritto qui sotto.** Il testo realmente mostrato all'utente (identico su acquisti e vendite) è:
> - 🟢 verde: **"✓ Sincronizzato con anagrafica"** (non "Anagrafica Allineata" come scritto sotto)
> - 🟡 giallo: **"Modificato localmente (override)"** (non "Indirizzo Modificato")
> - 🔵 blu: **"Recapito manuale / una tantum"** (non "Solo per questo Documento")
>
> Il meccanismo che decide quale badge mostrare (confronto dell'hash SHA-256 tra il dato scritto sul documento e il dato master in anagrafica) funziona esattamente come descritto in questo documento — è cambiato solo il testo scritto sul badge, non la logica sottostante.
>
> **2. Il pulsante "Ripristina Originale" esiste e funziona come descritto**, con il nome abbreviato in "Ripristina" nell'interfaccia (`app/views/purchases/rfq/_form.php`). Riporta i campi ai valori dell'anagrafica e fa ripartire il badge verde.
>
> **3. Il pulsante "Salva in Anagrafica" esiste, ma è più semplice di come descritto qui, e — importante — esiste SOLO per i documenti di vendita (preventivi al cliente), non per quelli di acquisto (RFQ al fornitore).** Non è un pulsante separato con una finestra di conferma dedicata: è una casella di spunta ("Salva in anagrafica per usi futuri") accanto al form dell'indirizzo, che l'utente spunta prima di salvare il documento. Se spuntata, il salvataggio crea comunque un nuovo indirizzo permanente in anagrafica e ricollega il documento a quello, ottenendo lo stesso risultato finale descritto sotto — solo con un clic in meno e senza popup di conferma. Sul lato acquisti (RFQ verso un fornitore) questa funzione oggi **non esiste**: se l'utente corregge un indirizzo o un contatto del fornitore direttamente su una RFQ, quella correzione resta solo su quel documento e non può essere "promossa" ad anagrafica senza aprire separatamente la scheda del fornitore.

### A. La Card di Visualizzazione dell'Indirizzo

Questo componente deve mostrare chiaramente lo stato dell'indirizzo in base ai tre metadati (`indirizzo_master_id`, `personalizzato` e l'allineamento dell'hash).

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
  - `indirizzo_master_id` != NULL, `personalizzato` == 0.
  - *UI (progetto originale):* badge verde chiaro "Anagrafica Allineata". *UI reale:* badge verde **"✓ Sincronizzato con anagrafica"**. Nessun pulsante speciale in entrambi i casi.

- **Stato 2: Modificato (Customizzato)**
  - `indirizzo_master_id` != NULL, `personalizzato` == 1.
  - *UI (progetto originale):* badge giallo "Indirizzo Modificato". *UI reale:* badge giallo **"Modificato localmente (override)"**. Mostra i pulsanti "Ripristina" (presente) e "Salva in Anagrafica" (presente solo lato vendite).

- **Stato 3: Scritto a mano (Estemporaneo Puro)**
  - `indirizzo_master_id` == NULL.
  - *UI (progetto originale):* badge grigio "Solo per questo Documento". *UI reale:* badge blu **"Recapito manuale / una tantum"**. Il pulsante "Salva in Anagrafica" per censirlo come indirizzo permanente è presente solo lato vendite.

### B. Il Funzionamento delle Azioni Smart

#### 1. "Ripristina Originale" (UX Flawless)
Quando l'utente clicca su questo pulsante:
1. Il frontend effettua una richiesta API: `GET /api/v1/indirizzi/{indirizzo_master_id}`.
2. Il frontend sovrascrive i campi a schermo con i valori originali dell'anagrafica.
3. Imposta il flag `personalizzato` a `0` e nasconde il badge giallo.
4. Al successivo salvataggio della bozza, il backend allineerà lo snapshot della bozza.

#### 2. "Salva in Anagrafica" (La promozione del dato) — presente solo lato vendite, vedi nota sopra
Se l'utente ha inserito una modifica utile (es. ha aggiunto un interno o corretto l'indirizzo), può promuovere il dato senza dover andare nel modulo anagrafiche del cliente:
1. L'utente spunta la casella "Salva in anagrafica per usi futuri" (non un pulsante/dialog separato).
2. Al salvataggio del documento, il backend (`SalesQuotesController.php`) effettua due operazioni:
   - Crea un nuovo record nella tabella `indirizzi` associato al cliente corrente.
   - Ricollega il documento al nuovo indirizzo appena creato (`indirizzo_spedizione_id` = nuovo ID), da cui poi lo snapshot viene rigenerato con `personalizzato = 0` (poiché ora coincide col nuovo master).
3. La UI, al ricaricamento, mostra il badge verde "✓ Sincronizzato con anagrafica".

---

## 5. Gestione dei Casi Limite (Edge Cases)

> ✅ **STATO ATTUALE VERIFICATO (10/07/2026)** — Dei tre casi limite descritti sotto, uno **non è implementato**, uno è implementato ma in modo diverso (e più corretto) di come descritto, e uno **non è implementato**. Dettaglio caso per caso:

### Caso Limite 1: L'anagrafica Master viene modificata *dopo* che la bozza del documento è stata creata

> ❌ **NON implementato.** Ho cercato in tutto il codice (testo del banner, confronto d'hash lato client su una bozza già aperta) e non esiste nulla del genere. **In parole semplici:** oggi, se un utente crea la bozza di una RFQ usando l'indirizzo del "Magazzino Milano", e il giorno dopo un collega corregge il CAP di quel magazzino in anagrafica, la bozza aperta **non riceve alcun avviso**. Se l'utente la invia così com'è, verrà congelato (cristallizzato) il CAP vecchio, senza che nessuno se ne accorga finché non arriva un reclamo dal fornitore o dal corriere. Non è un bug che rompe qualcosa — l'ERP non fa nulla di sbagliato — ma è una funzionalità di sicurezza descritta in questo documento che manca ancora.

La soluzione descritta di seguito (banner "I dati anagrafici di questo indirizzo sono stati aggiornati di recente") resta quindi solo una proposta, non uno stato del software:
- **La soluzione (L'allineamento silente delle bozze):**
  - Finché un documento è in stato `bozza`, i dati devono essere dinamici e allineati all'anagrafica corrente.
  - Quando l'utente apre la bozza sul frontend, il frontend calcola l'hash dei dati memorizzati nel record di snapshot della bozza e lo confronta con l'hash calcolato al volo sui dati correnti della tabella master.
  - Se gli hash differiscono, il frontend mostra un banner discreto in cima alla pagina:
    - ⚠️ *"I dati anagrafici di questo indirizzo sono stati aggiornati di recente. [Clicca qui per aggiornare la bozza all'ultimo indirizzo ufficiale]"*.
  - Se l'utente clicca, la bozza viene aggiornata. Altrimenti, mantiene i dati con cui era stata creata. In ogni caso, al momento dell'invio definitivo (Fase 2), lo snapshot congelerà i dati correnti.

### Caso Limite 2: Il fornitore del documento viene modificato in corso d'opera

> ✅ **Implementato, ma in modo più preciso di come descritto qui sotto.** Verificato in `assets/js/rfq-form.js` (lato acquisti) e in `assets/js/sales-quotes.js` (lato vendite). **In parole semplici:** quando l'utente cambia il cliente su un preventivo di vendita, l'ERP azzera e ricarica sia il menu dell'indirizzo sia quello del contatto, proponendo i default del nuovo cliente — esattamente come descritto sotto. Quando invece l'utente cambia il fornitore su una RFQ di acquisto, l'ERP ricarica **solo** il menu del contatto referente, e lascia **invariato** l'indirizzo di consegna. Questa non è una funzionalità mancante: è corretta, perché in una RFQ l'indirizzo di consegna è **il nostro magazzino** (dove vogliamo che il fornitore ci spedisca la merce), non un dato del fornitore — cambiare fornitore non ha alcun motivo di cambiare dove vogliamo ricevere la merce. Questa distinzione (l'indirizzo, lato acquisti, non appartiene al soggetto scelto in testata) non è mai esplicitata in questo documento, che parla genericamente di "resettare la sezione contatti ed indirizzi specifici del fornitore" per ogni caso: il codice reale, su questo punto, è più corretto della sua stessa documentazione di riferimento.

- **La soluzione (Il Reset Intelligente), come originariamente proposta:**
  - Se l'utente cambia il fornitore di testata del documento, il frontend deve effettuare un **reset completo** della sezione contatti ed indirizzi specifici del fornitore.
  - Il frontend cancella le FK `indirizzo_consegna_id` e `contatto_id` correnti.
  - Propone automaticamente i nuovi default del nuovo fornitore.
  - Il backend, al salvataggio della bozza, marcherà come orfani i vecchi record di snapshot del vecchio fornitore (che verranno puliti dalle routine di manutenzione periodica del DB — vedi però Caso Limite 3, mai implementato).

### Caso Limite 3: Pulizia periodica degli Snapshot "Orfani"

> ❌ **NON implementato.** Cercato un cron job, un task pianificato, o anche solo una query manuale di pulizia (`DELETE FROM indirizzi_documenti`/`contatti_documenti` per righe non più referenziate da nessun documento): non esiste nulla. **In parole semplici:** ogni volta che un utente modifica una bozza più volte, o la cancella prima di inviarla, l'ERP crea nuove righe nelle tabelle di "fotografia" (`indirizzi_documenti`, `contatti_documenti`) senza mai eliminare quelle diventate inutili. Nel tempo queste due tabelle crescono senza limiti, anche se questo non causa (almeno per ora) errori visibili all'utente — è un problema di "pulizia della casa" più che un bug immediato, ma vale la pena tenerlo presente se il database dovesse crescere molto nel tempo.

- **La soluzione, come originariamente proposta:**
  - Il database prevede un processo batch notturno (un cron job di backend) che effettua una semplice query di pulizia (Garbage Collection):
  - Elimina tutti i record di `indirizzi_documenti` e `contatti_documenti` che **non** sono referenziati da nessuna riga delle tabelle `rfq_testata`, `offerte_testata` o `ordini_testata`. Questo manterrebbe il database sempre leggero, reattivo e pulito.
