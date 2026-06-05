# Indirizzi e contatti nel ciclo vendite

> **Scope:** dal preventivo al DDT. Come un ERP moderno gestisce la selezione, il salvataggio e la propagazione degli indirizzi e dei contatti del cliente lungo tutto il flusso documentale.

---

## Il problema concreto

Quando un operatore crea un preventivo, ha bisogno di rispondere a due domande pratiche:

1. **Dove va la merce?** Un cliente può avere la sede legale a Milano, il magazzino a Sesto San Giovanni e un cantiere temporaneo a Bergamo. L'operatore deve poter scegliere il posto giusto senza cercare l'indirizzo a memoria o chiamare il cliente.

2. **A chi è intestato il documento?** La stessa azienda può avere un ufficio acquisti, un responsabile tecnico e un responsabile logistica. Il preventivo deve riportare il nome del referente corretto, con email e telefono.

Il problema si complica perché:
- I dati esistono già in anagrafica, ma possono essere tanti (e possono cambiare nel tempo)
- L'operatore deve poter correggere o integrare al volo, senza uscire dal form
- L'indirizzo stampato sul documento deve rimanere quello scelto al momento della creazione, anche se in futuro il cliente cambia sede

---

## Come lo risolvono i principali ERP

Tutti i sistemi maturi convergono sullo stesso schema concettuale, con piccole differenze di interfaccia.

### Odoo 17

Odoo modella indirizzi e contatti in modo unificato: ogni indirizzo di consegna o referente è un record nella tabella `res.partner` con un campo `type` (`delivery`, `invoice`, `contact`, `other`) e un `parent_id` che punta al cliente principale. Quando si crea un ordine di vendita e si seleziona il cliente, Odoo cerca automaticamente l'indirizzo con `type = 'delivery'` e lo preseleziona nel campo "Indirizzo di consegna". Se ne esistono più di uno, l'utente sceglie da un dropdown. Se non ne esiste nessuno, il campo rimane vuoto e l'utente può cliccare "Crea e modifica" per aprire un modale che crea un nuovo indirizzo figlio direttamente associato al cliente — senza uscire dall'ordine.

Il punto di forza di Odoo è che il concetto di "contatto" e "indirizzo" è lo stesso oggetto (partner figlio): il referente commerciale, il responsabile acquisti e il magazzino di consegna sono tutti partner con `parent_id`. Questo semplifica la struttura dati ma richiede attenzione in fase di data entry.

Al momento della conferma dell'ordine, Odoo copia i dati dell'indirizzo nei campi testuali della riga di picking e della fattura. Il documento stampato usa quei campi, non la FK.

### SAP Business One

In SAP B1 ogni cliente (Business Partner, BP) ha un elenco di indirizzi codificati nella tabella `CRD1`. Gli indirizzi di tipo `S` (Ship-to) sono quelli di spedizione, quelli di tipo `B` (Bill-to) sono per la fatturazione.

Nell'ordine di vendita c'è un campo "Codice indirizzo di consegna" (Ship-to Address Code): un dropdown che mostra tutti gli indirizzi `S` del cliente. Quando l'utente seleziona un codice, i campi testuali dell'indirizzo (via, città, CAP ecc.) si copiano nella testata dell'ordine. Da quel momento in poi i campi sono modificabili direttamente sull'ordine, ma le modifiche non tornano indietro all'anagrafica. La FK (il codice indirizzo) resta memorizzata per sapere "da quale indirizzo è partito", ma ciò che conta per la stampa sono i campi testuali.

SAP ha anche il concetto di "Contact Person": una lista di persone di contatto associate al BP, gestibile nella scheda cliente. Nel documento di vendita il campo "Referente" è un dropdown su questa lista.

### Microsoft Dynamics 365 Business Central

D365 BC ha la UI più chiara e pedagogica dei tre. Nel documento di vendita (ordine, preventivo) c'è un campo "Ship-to" con tre opzioni esplicite, come un piccolo radio button:

- **Customer Address** — usa l'indirizzo principale del cliente (quello di fatturazione come fallback di consegna)
- **Alternate Shipping Address** — apre un lookup sugli indirizzi alternativi registrati nella scheda cliente (tabella `Ship-to Address`)
- **Custom Address** — sblocca i campi per inserire un indirizzo libero che non viene salvato in anagrafica

Questa distinzione esplicita è importante perché elimina ambiguità: l'utente sa sempre in quale "modalità" sta lavorando e capisce che "Custom" non salva nulla in anagrafica. Se usa "Alternate" e l'indirizzo non c'è, può aprire la scheda cliente in un altro tab, aggiungere l'indirizzo e tornare.

Il campo "Sell-to Contact" è un testo libero precompilato con il contatto principale, modificabile a piacere.

Quando il documento viene registrato (posted), tutti i campi testuali vengono congelati nella versione storica. Nessun aggiornamento successivo all'anagrafica può toccarli.

### Microsoft Dynamics 365 Finance & Operations (enterprise)

La versione enterprise usa il Global Address Book (GAB): ogni entità (cliente, fornitore, dipendente, sede) è una "party" con un ID univoco nel GAB. Gli indirizzi sono "postal addresses" collegate alle party. Un cliente può avere N "party roles" e ogni role può avere N indirizzi.

Questo è più potente ma molto più complesso. La versione BC è quella da cui prendere ispirazione per un ERP di medie dimensioni.

### Oracle NetSuite

NetSuite usa il concetto di "Customer Address Book": ogni cliente ha una lista di indirizzi (sia di fatturazione che di consegna) gestita in una subtable nella scheda cliente. In ogni transazione (ordine, fattura) c'è un campo "Ship To" con dropdown su questa lista.

La cosa più interessante di NetSuite è la checkbox **"Override"**: quando l'utente la spunta, i campi dell'indirizzo si sbloccano e diventano modificabili direttamente sulla transazione, senza modificare l'anagrafica. L'interfaccia mostra chiaramente che l'indirizzo è stato sovrascritto rispetto al default. Quando si spunta Override e si modificano i campi, la FK all'indirizzo del cliente viene mantenuta (così si sa da dove si è partiti) ma i campi testuali divergono. Alla stampa vengono usati i campi testuali.

NetSuite ha anche il campo "Attention" (c.a.) come testo libero, separato dal campo "Contact" che è un dropdown sui contatti del cliente.

### Oracle ERP Cloud (Fusion)

Fusion usa il concetto di "Customer Site": ogni cliente ha dei "site" associati, ognuno con un tipo (Bill-to, Ship-to, ecc.) e un indirizzo. Le transazioni puntano a un site specifico. I site sono più pesanti da gestire degli indirizzi semplici ma permettono di associare anche condizioni commerciali, contatti e regole di consegna specifiche per ogni location del cliente.

---

## Il pattern comune

Messi a confronto, tutti questi sistemi fanno la stessa cosa in tre strati sovrapposti:

| Strato | Cos'è | A cosa serve |
|---|---|---|
| **FK live** | L'ID dell'indirizzo master in anagrafica | Navigazione, filtri, reportistica, "da dove siamo partiti" |
| **Snapshot denormalizzato** | Le colonne dell'indirizzo copiate sul documento | Stampa, integrità storica, indipendenza dall'anagrafica |
| **Override inline** | Modifiche ai campi snapshot senza toccare la FK | Eccezioni una tantum (cantiere temporaneo, piano diverso, referente sostituto) |

La regola che non cambia mai: **la stampa (PDF, DDT, fattura) legge sempre i campi snapshot, mai la FK.** La FK è lì solo per l'interfaccia e per sapere l'origine.

---

## Database

### Anagrafica indirizzi

La tabella `indirizzi` contiene tutti gli indirizzi di tutti i soggetti (clienti e fornitori). Ogni indirizzo ha un `tipo` che ne definisce la funzione e un flag `predefinito` che indica quale usare per default quando ne esistono più dello stesso tipo.

```sql
CREATE TABLE indirizzi (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    soggetto_id         INTEGER NOT NULL REFERENCES clienti_fornitori(id) ON DELETE CASCADE,

    tipo                TEXT NOT NULL CHECK (tipo IN (
                            'sede_legale',    -- indirizzo giuridico/fiscale
                            'sede_operativa', -- uffici operativi
                            'spedizione',     -- dove mandare la merce
                            'fatturazione',   -- dove mandare le fatture (se diverso)
                            'magazzino'       -- magazzino fisico del cliente
                        )),

    nome_sede           VARCHAR(100),  -- etichetta leggibile: "Magazzino Nord", "Cantiere via Roma 15"
    predefinito         BOOLEAN NOT NULL DEFAULT 0,
                                       -- per ogni coppia (soggetto_id, tipo), al massimo un TRUE

    referente_consegna  VARCHAR(80),   -- persona da chiamare all'arrivo del corriere
    telefono_consegna   VARCHAR(20),   -- numero diretto del magazziniere/portineria

    presso              VARCHAR(100),  -- c/o: "Presso Condominio ABC"
    via                 VARCHAR(150) NOT NULL,
    civico              VARCHAR(10),
    cap                 VARCHAR(10) NOT NULL,
    citta               VARCHAR(100) NOT NULL,
    provincia           CHAR(2),
    nazione             CHAR(2) NOT NULL DEFAULT 'IT',

    note_consegna       VARCHAR(255),  -- "Accesso da via laterale", "Citofono 3"
    attivo              BOOLEAN NOT NULL DEFAULT 1
);
```

Il vincolo di unicità su `predefinito` è logico, non fisico: va garantito dall'applicazione (o da un trigger) che per ogni `(soggetto_id, tipo)` al massimo un record abbia `predefinito = 1`. Quando si imposta un indirizzo come predefinito, gli altri dello stesso tipo vanno portati a `0`.

### I contatti/referenti

I referenti sono gestiti come sub-record in `clienti_fornitori` tramite il campo `parent_id`. Un referente commerciale di un'azienda è a tutti gli effetti un record nella stessa tabella con:
- `parent_id` = ID dell'azienda cliente
- `tipo` = `'cliente'` (o `'entrambi'`)
- `tipo_soggetto` = `'privato'`
- `nome` e `cognome` valorizzati
- `email` e `telefono` valorizzati

Questo permette di cercare referenti con lo stesso sistema di autocomplete usato per i clienti, e di associarli ai preventivi come FK.

### Sul documento (preventivo, ordine, DDT)

Ogni documento del ciclo vendite ha due livelli affiancati per ogni informazione di consegna:

```sql
CREATE TABLE preventivi (
    id  INTEGER PRIMARY KEY,
    -- ... altri campi ...

    -- ─────────────────────────────────────────
    -- LIVELLO 1: FK live (per UI e analytics)
    -- ─────────────────────────────────────────
    indirizzo_spedizione_id INTEGER REFERENCES indirizzi(id),
    -- Punta all'indirizzo selezionato in anagrafica.
    -- NULL se l'utente ha usato un indirizzo una-tantum (override puro).

    contatto_id             INTEGER REFERENCES clienti_fornitori(id),
    -- Punta al referente selezionato, se esiste in anagrafica.
    -- NULL se l'utente ha scritto il nome a mano.

    -- ─────────────────────────────────────────
    -- LIVELLO 2: Snapshot denormalizzato (per stampa e integrità storica)
    -- ─────────────────────────────────────────
    -- Indirizzo di spedizione, copiato dall'anagrafica o inserito manualmente
    consegna_via            VARCHAR(150),
    consegna_civico         VARCHAR(10),
    consegna_cap            VARCHAR(10),
    consegna_citta          VARCHAR(100),
    consegna_provincia      CHAR(2),
    consegna_nazione        CHAR(2),
    consegna_presso         VARCHAR(100),
    consegna_referente      VARCHAR(80),   -- chi riceve fisicamente
    consegna_telefono       VARCHAR(20),
    consegna_note           VARCHAR(255),

    -- Dati del cliente, cristallizzati al momento della creazione
    cliente_ragione_sociale VARCHAR(255),
    cliente_partita_iva     VARCHAR(30),
    cliente_codice_fiscale  VARCHAR(30),
    cliente_indirizzo       VARCHAR(255),  -- indirizzo di fatturazione per esteso

    -- Referente commerciale, cristallizzato
    alla_ca                 VARCHAR(150),  -- testo libero: "c.a. Dott. Rossi"
    contatto_nome           VARCHAR(150),
    contatto_email          VARCHAR(150),
    contatto_telefono       VARCHAR(50),
    -- ...
);
```

La stessa struttura a due livelli si replica su `ordini_clienti`, `spedizioni` e `documenti_trasporto`. Ogni documento è autosufficiente per la stampa.

**Quando popolare lo snapshot?** Idealmente subito, al primo salvataggio — anche se il documento è ancora in bozza. Questo semplifica il backend (non serve una logica speciale alla transizione di stato) e permette di vedere subito la preview del documento. La regola è che lo snapshot è **bloccato** una volta che il documento viene inviato o confermato: da quel momento non si modifica più, neanche se l'operatore cambia l'anagrafica.

---

## Backend

### API: indirizzi del cliente

Questo endpoint viene chiamato dal JavaScript non appena l'utente seleziona un cliente nel form. Restituisce solo gli indirizzi rilevanti per la consegna (tipo `spedizione` o `magazzino`), ordinati con i predefiniti per primi.

```
GET /api/clienti/{id}/indirizzi
```

```json
[
  {
    "id": 12,
    "tipo": "spedizione",
    "predefinito": true,
    "nome_sede": "Magazzino principale",
    "label_breve": "Magazzino principale — Milano",
    "via": "Via Roma", "civico": "1", "cap": "20100",
    "citta": "Milano", "provincia": "MI", "nazione": "IT",
    "presso": null,
    "referente_consegna": "Mario Bianchi",
    "telefono_consegna": "02 1234567",
    "note_consegna": "Consegnare solo al mattino, citofono 3"
  },
  {
    "id": 17,
    "tipo": "spedizione",
    "predefinito": false,
    "nome_sede": "Filiale Torino",
    "label_breve": "Filiale Torino — Torino",
    "via": "Corso Francia", "civico": "45", "cap": "10138",
    "citta": "Torino", "provincia": "TO", "nazione": "IT",
    "presso": null,
    "referente_consegna": null,
    "telefono_consegna": null,
    "note_consegna": null
  }
]
```

Se il cliente non ha indirizzi di tipo `spedizione` o `magazzino`, l'API restituisce un array vuoto e il frontend mostra il form di inserimento manuale.

### API: contatti del cliente

```
GET /api/clienti/{id}/contatti
```

Restituisce il cliente stesso (spesso è la voce "nessun referente specifico") e tutti i sub-contatti con `parent_id = {id}`.

```json
[
  {
    "id": 45,
    "nome_completo": "Acme S.r.l.",
    "email": "info@acme.it",
    "telefono": "02 9876543",
    "ruolo": null
  },
  {
    "id": 88,
    "nome_completo": "Carla Verdi",
    "email": "c.verdi@acme.it",
    "telefono": "02 9876544",
    "ruolo": "Ufficio acquisti"
  }
]
```

### Salvataggio del preventivo

Il controller deve gestire due percorsi:

**Percorso A — l'utente ha scelto un indirizzo dall'anagrafica:**
```php
if (!empty($values['indirizzo_spedizione_id'])) {
    $addr = fetchIndirizzo($values['indirizzo_spedizione_id']);
    // Popola i campi snapshot dall'anagrafica
    $values['consegna_via']       = $addr['via'];
    $values['consegna_civico']    = $addr['civico'];
    $values['consegna_cap']       = $addr['cap'];
    $values['consegna_citta']     = $addr['citta'];
    $values['consegna_provincia'] = $addr['provincia'];
    $values['consegna_nazione']   = $addr['nazione'];
    $values['consegna_presso']    = $addr['presso'];
    $values['consegna_referente'] = $addr['referente_consegna'];
    $values['consegna_telefono']  = $addr['telefono_consegna'];
    $values['consegna_note']      = $addr['note_consegna'];
}
```

Poi, se l'utente ha modificato qualche campo inline (override), i valori POST sovrascrivono quelli appena letti dall'anagrafica — perché i campi del form vengono inviati comunque.

**Percorso B — l'utente ha usato l'indirizzo libero (una tantum):**
```php
// indirizzo_spedizione_id è NULL
// I campi consegna_* arrivano direttamente dal POST
// Non c'è nulla da copiare dall'anagrafica
```

In entrambi i casi i campi del cliente (ragione sociale, P.IVA ecc.) vengono copiati dall'anagrafica ad ogni salvataggio mentre il documento è in bozza, e bloccati una volta confermato.

### Propagazione nel ciclo

Quando si crea un ordine da un preventivo confermato, il controller copia FK e snapshot:

```php
$ordine = [
    // FK
    'indirizzo_spedizione_id' => $preventivo['indirizzo_spedizione_id'],
    'contatto_id'             => $preventivo['contatto_id'],
    // Snapshot
    'consegna_via'            => $preventivo['consegna_via'],
    'consegna_cap'            => $preventivo['consegna_cap'],
    'consegna_citta'          => $preventivo['consegna_citta'],
    // ... tutti gli altri campi snapshot
];
```

L'operatore può cambiare l'indirizzo sull'ordine prima della conferma. Il preventivo originale resta intatto. Stessa logica dall'ordine alla spedizione, dalla spedizione al DDT.

---

## Frontend

### Cosa succede quando si seleziona il cliente

Il JavaScript esegue due chiamate API in parallelo (indirizzi + contatti) non appena l'utente seleziona un cliente dall'autocomplete. Il form non si blocca: le chiamate sono asincrone e i campi si aggiornano quando le risposte arrivano.

```
Utente digita "Acme" → dropdown autocomplete → seleziona cliente
    │
    ├── POST nascosto: cliente_id = 45
    ├── Mostra preview cliente (ragione sociale, P.IVA)
    │
    ├── GET /api/clienti/45/indirizzi  ──→  popola select "Indirizzo di consegna"
    │                                        preseleziona predefinito
    │                                        mostra preview indirizzo
    │
    └── GET /api/clienti/45/contatti   ──→  popola select "Referente"
                                            preseleziona contatto principale
```

### Il select "Indirizzo di consegna"

Non è un `<select>` standard. Le opzioni devono essere leggibili a colpo d'occhio, senza mostrare l'indirizzo completo (troppo lungo). Il formato consigliato: **nome sede** + **città** + eventuale indicazione del tipo.

```
▼ Magazzino principale ★  ·  Milano
  Filiale Torino           ·  Torino
  ──────────────────────────────────
  + Aggiungi indirizzo una tantum...
  + Salva nuovo indirizzo in anagrafica...
```

La stella (★) indica il predefinito. Le ultime due voci non sono indirizzi ma azioni che aprono rispettivamente un form inline e un form che salva in DB.

Dopo la selezione, sotto il select appare un **box preview** con tutti i dettagli dell'indirizzo. Questo box è pensato per due scopi: permettere all'utente di verificare che sia quello giusto, e permettere piccoli override.

```
┌────────────────────────────────────────────────────────┐
│ Magazzino principale                                   │
│ Via Roma 1 · 20100 Milano MI · Italia                  │
│ Referente: Mario Bianchi · 02 1234567                  │
│ Note: Consegnare solo al mattino, citofono 3           │
│                                         [✎] [✕]        │
└────────────────────────────────────────────────────────┘
```

Cliccando **[✎]** il box si trasforma in campi editabili. L'utente può modificare singoli valori (es. cambiare il referente perché Mario è in ferie) senza cambiare l'anagrafica. I campi modificati rispetto all'originale vengono evidenziati con un bordo colorato, così è chiaro che c'è un override.

Cliccando **[✕]** la selezione viene rimossa e il select torna allo stato vuoto.

### Il form "una tantum"

Quando l'utente seleziona "+ Aggiungi indirizzo una tantum", il box preview si trasforma in un form vuoto con i campi dell'indirizzo. In fondo al form c'è un piccolo checkbox: **"Salva anche in anagrafica per usi futuri"**. Se spuntato, al salvataggio del preventivo viene anche creato un record in `indirizzi` e la FK viene valorizzata. Se non spuntato, la FK rimane NULL e i dati vanno solo sullo snapshot.

Questo evita di dover scegliere tra due pulsanti diversi prima di compilare i campi — l'utente compila e poi decide, una volta visto il risultato.

### Il campo referente

Il referente ha una struttura doppia:

1. **Select** con i contatti censiti in anagrafica (dropdown)
2. **Campo testo libero** "Alla c.a. di" — precompilato dal select ma modificabile

Questa separazione è importante: il select setta la FK (`contatto_id`) e precompila il testo, ma il testo è quello che finisce sul documento stampato. Se il referente non è in anagrafica, l'operatore salta il select e scrive direttamente nel testo. La FK rimane NULL, il documento stampato è corretto.

Se l'operatore seleziona un contatto dal select e poi corregge il testo a mano, entrambi i valori vengono salvati: la FK indica chi era il referente previsto, il testo indica quello che è scritto sul documento.

### Blocco al cambio di stato

Quando il preventivo viene inviato (stato `bozza → inviata`), l'interfaccia deve segnalare che lo snapshot è ora bloccato. L'approccio migliore è mostrare i campi come read-only con un'icona lucchetto, e aggiungere un avviso esplicito:

> "Questo preventivo è stato inviato al cliente. I dati di consegna non possono più essere modificati. Per correggere l'indirizzo, crea una nuova revisione del preventivo."

Qualsiasi tentativo di forzare il salvataggio deve essere bloccato lato backend, non solo frontend.

---

## Simulazione flusso utente: tutti i casi

Questa simulazione segue un operatore che crea un preventivo. Ogni ramo descrive un caso reale.

---

### FASE 1 — Selezione del cliente

```
Operatore apre "Nuovo preventivo"
    │
    └── Digita "Ferretti" nel campo cliente
            │
            ├── [CASO 1.A] Trovato un solo cliente "Ferretti S.r.l."
            │       → Il sistema lo seleziona direttamente (o con un click)
            │       → Si va alla FASE 2
            │
            ├── [CASO 1.B] Trovati più clienti con "Ferretti" nel nome
            │       → Dropdown con l'elenco
            │       → Operatore sceglie quello giusto
            │       → Si va alla FASE 2
            │
            └── [CASO 1.C] Nessun risultato
                    → Il pulsante "Nuovo cliente" si illumina o compare un link
                    → Operatore apre il form di creazione cliente (modale o nuova pagina)
                    → Crea il cliente, torna al preventivo con il cliente già selezionato
                    → Si va alla FASE 2 (ma probabilmente senza indirizzi: CASO 2.C)
```

---

### FASE 2 — Selezione indirizzo di consegna

Una volta selezionato il cliente, il sistema carica gli indirizzi via API.

```
Sistema carica indirizzi del cliente
    │
    ├── [CASO 2.A] Cliente ha UN solo indirizzo di spedizione
    │       → Preselezionato automaticamente
    │       → Operatore vede il preview e può scegliere:
    │           ├── [2.A.i] Va bene → lascia così, passa al referente
    │           ├── [2.A.ii] Vuole modificare un dettaglio (piano, referente diverso)
    │           │       → Clicca [✎] nel preview
    │           │       → Modifica il campo che non va (es. referente consegna)
    │           │       → L'override è solo sul documento
    │           └── [2.A.iii] L'indirizzo è completamente sbagliato (cliente si è trasferito)
    │                   → Clicca "+" per inserire nuovo indirizzo
    │                   → Può scegliere: solo sul documento (una tantum)
    │                                     oppure salva anche in anagrafica
    │                   → Se salva in anagrafica, l'operatore può anche segnalare
    │                     all'amministratore di aggiornare/disattivare il vecchio
    │
    ├── [CASO 2.B] Cliente ha PIÙ indirizzi di spedizione
    │       → Il predefinito viene preselezionato
    │       → Se il predefinito non è quello giusto:
    │           → Operatore apre il select e sceglie l'altro
    │           → Il preview si aggiorna con i dettagli del nuovo indirizzo
    │           → Eventuale override inline (come in 2.A.ii)
    │
    ├── [CASO 2.C] Cliente NON ha indirizzi di spedizione
    │       → Il select appare vuoto con messaggio "Nessun indirizzo di consegna"
    │       → Il form di inserimento manuale si apre automaticamente (con checkbox "salva in anagrafica" suggerito = spuntato)
    │       → Operatore inserisce l'indirizzo
    │
    ├── [CASO 2.D] Cliente ha solo sede legale (nessun indirizzo tipo 'spedizione')
    │       → Come 2.C, ma il sistema può suggerire di usare la sede legale
    │         mostrando un link "Usa sede legale come indirizzo di consegna"
    │       → Se l'operatore accetta, la sede legale viene precaricata nei campi
    │         (snapshot), la FK punta all'indirizzo di tipo 'sede_legale'
    │
    └── [CASO 2.E] La merce va a un TERZO (non al cliente)
            → Caso meno comune ma reale: il cliente ordina ma la merce va
              a un suo cantiere gestito da un'altra impresa
            → L'operatore svuota la selezione e usa "+ Inserisci indirizzo una tantum"
            → Nei campi scrive l'indirizzo del terzo destinatario
            → La FK rimane NULL, lo snapshot contiene i dati del terzo
            → Sul PDF compare l'indirizzo del terzo ma il cliente rimane il committente
```

---

### FASE 3 — Selezione del referente

```
Sistema carica contatti del cliente
    │
    ├── [CASO 3.A] Cliente ha un contatto principale
    │       → Preselezionato automaticamente
    │       → Il campo "alla c.a. di" viene precompilato con nome + cognome
    │       → Se va bene → lascia così
    │       → Se vuole cambiare email/telefono per questo documento:
    │           → Modifica direttamente il campo testo (la FK al contatto resta)
    │
    ├── [CASO 3.B] Cliente ha PIÙ contatti
    │       → Preselezionato il principale
    │       → Se non è quello giusto: operatore cambia il select
    │       → Il campo testo si aggiorna
    │
    ├── [CASO 3.C] Cliente NON ha contatti censiti
    │       → Il select è vuoto
    │       → Il campo "alla c.a. di" è libero: operatore scrive direttamente
    │       → Nessuna FK, solo testo sullo snapshot
    │
    ├── [CASO 3.D] Il referente non è nell'elenco (nuova persona in azienda)
    │       → Operatore scrive il nome nel campo testo (override immediato)
    │       → Opzionalmente: clicca "Aggiungi ai contatti del cliente"
    │         che apre un mini-form per creare il sub-contatto in anagrafica
    │         senza uscire dal preventivo
    │
    └── [CASO 3.E] Nessun referente: il documento va all'azienda genericamente
            → Operatore lascia il campo vuoto o scrive "Ufficio acquisti"
            → Sul PDF comparirà "Egr. Sig./Sig.ra" o l'azienda direttamente
```

---

### FASE 4 — Invio del preventivo (cambio di stato)

```
Operatore clicca "Invia preventivo" (stato bozza → inviata)
    │
    ├── [CASO 4.A] Tutti i dati sono presenti e corretti
    │       → Il backend cristallizza lo snapshot (se non già fatto)
    │       → I campi diventano read-only nel frontend
    │       → Il preventivo è pronto per essere stampato/inviato via email
    │
    ├── [CASO 4.B] Indirizzo di consegna mancante
    │       → Il backend blocca la transizione e restituisce un errore di validazione
    │       → L'interfaccia mostra il messaggio e porta il focus alla tab "Altre Info"
    │       → L'operatore inserisce l'indirizzo e riprova
    │
    ├── [CASO 4.C] Dopo l'invio, il cliente chiede di cambiare l'indirizzo
    │       → Il sistema NON permette di modificare il preventivo inviato
    │       → L'operatore ha due opzioni:
    │           1. Creare una revisione del preventivo (nuovo numero con suffisso "rev.1")
    │              Il vecchio viene annullato, il nuovo eredita i dati e aggiorna l'indirizzo
    │           2. Creare un nuovo preventivo da zero
    │
    └── [CASO 4.D] Il preventivo scade senza risposta
            → Lo stato passa a 'scaduta' (automaticamente o manualmente)
            → Lo snapshot resta immutato per eventuali recuperi storici
```

---

### FASE 5 — Dal preventivo all'ordine

```
Cliente accetta il preventivo → operatore crea l'ordine
    │
    ├── [CASO 5.A] Consegna alle stesse condizioni del preventivo
    │       → L'ordine eredita FK e snapshot senza chiedere nulla
    │       → Flusso normale
    │
    ├── [CASO 5.B] Indirizzo di consegna diverso da quello del preventivo
    │       → L'ordine viene creato con i dati del preventivo
    │       → L'operatore può modificare l'indirizzo sull'ordine (prima della conferma)
    │       → Il preventivo resta intatto
    │       → Caso tipico: "mandateci la merce al cantiere, non al magazzino"
    │
    ├── [CASO 5.C] Consegna parziale su più indirizzi
    │       → Questo esce dallo scope del preventivo e si gestisce a livello di spedizioni:
    │         un ordine può generare N spedizioni, ognuna con il proprio indirizzo
    │       → La spedizione eredita l'indirizzo dall'ordine ma l'operatore può cambiarlo
    │         prima di generare il DDT
    │
    └── [CASO 5.D] L'ordine genera più DDT nel tempo (consegne scaglionate)
            → Ogni DDT ha il proprio snapshot dell'indirizzo
            → Se nel frattempo il cantiere si è spostato, il nuovo DDT può avere
              un indirizzo diverso dai precedenti — e va bene così
```

---

## Regole di implementazione

Queste sono le decisioni da prendere in modo esplicito, perché cambiano il comportamento dell'applicazione in modo non ovvio.

**Quando copiare lo snapshot?** Alla prima scrittura (anche da bozza). Non aspettare la conferma: semplifica il codice e permette preview accurate.

**Quando bloccare lo snapshot?** Alla transizione `bozza → inviata` (o `bozza → confermata` se non c'è la fase "inviata"). Il blocco va verificato lato backend ad ogni salvataggio — non fidarsi solo del frontend.

**La FK è obbligatoria?** No. Può essere NULL se l'utente usa l'indirizzo una tantum. I campi snapshot invece devono essere valorizzati se il documento deve essere stampato.

**Cosa fare se l'indirizzo in anagrafica viene eliminato?** La FK diventa orfana (o si rompe se c'è un FK constraint). La soluzione è non cancellare mai gli indirizzi ma solo disattivarli (`attivo = 0`). Il constraint di FK non va messo come `ON DELETE CASCADE` ma come `ON DELETE SET NULL` o `RESTRICT`.

**L'override invalida la FK?** No. Se l'utente seleziona l'indirizzo "Magazzino principale" ma poi cambia il referente nel preview, la FK punta ancora a "Magazzino principale". I campi snapshot divergono dall'anagrafica, ma è quello che vogliamo: sappiamo da dove siamo partiti (FK) e cosa c'è scritto sul documento (snapshot).

**La stampa usa sempre e solo lo snapshot.** Mai fare una JOIN all'indirizzo master per generare il PDF. Il giorno in cui il cliente cambia sede, tutti i vecchi documenti stamperebbero l'indirizzo nuovo — un disastro contabile e legale.

---

## Cosa non fare

**Non obbligare l'utente a uscire dal form** per aggiungere un indirizzo o un contatto. Ogni azione "crea nuovo" deve essere accessibile inline o in modale, con ritorno immediato al documento in lavorazione.

**Non mostrare l'indirizzo completo nelle opzioni del select.** "Via Roma 1, 20100 Milano (MI) — Magazzino principale" è illeggibile. Meglio "Magazzino principale · Milano". Il dettaglio si vede nel preview dopo la selezione.

**Non aggiornare silenziosamente lo snapshot** quando l'anagrafica cambia. Se il cliente cambia indirizzo e il sistema aggiorna automaticamente i preventivi in bozza, potrebbe mandare merce al posto sbagliato. Lasciare che l'utente aggiorni manualmente, o al massimo mostrare un avviso "l'indirizzo in anagrafica è cambiato — vuoi aggiornare questo documento?".

**Non rimuovere indirizzi dall'anagrafica.** Usare `attivo = 0`. Se si elimina, i documenti storici perdono la FK e il sistema non sa più a quale indirizzo ci si riferiva.

**Non duplicare il campo "c.a." con il campo "contatto".** Sono due cose diverse: il contatto è una FK all'anagrafica (per tracciabilità e analytics), il "c.a." è il testo che appare sul documento stampato (personalizzabile). Devono vivere separati.

---

## Checklist implementazione

### Database
- [ ] `indirizzi.predefinito`: garantire via applicazione (o trigger) che per ogni `(soggetto_id, tipo)` al massimo un record abbia `predefinito = 1`
- [ ] FK su `indirizzi`: `ON DELETE RESTRICT` o `ON DELETE SET NULL`, mai `CASCADE`
- [ ] Ogni tabella documento (`preventivi`, `ordini_clienti`, `spedizioni`, `documenti_trasporto`) ha sia la FK che i campi snapshot

### Backend
- [ ] `GET /api/clienti/{id}/indirizzi`: restituisce solo `tipo IN ('spedizione','magazzino')` e `attivo = 1`, ordinati `predefinito DESC, nome_sede ASC`
- [ ] `GET /api/clienti/{id}/contatti`: restituisce cliente + sub-contatti (`parent_id = {id}`), ordinati per nome
- [ ] `POST /api/clienti/{id}/indirizzi`: crea un nuovo indirizzo in anagrafica (usato dal "salva in anagrafica")
- [ ] Al salvataggio del documento: se `indirizzo_spedizione_id` è valorizzato, leggere i dati dall'anagrafica e popolare i campi snapshot; i valori POST possono sovrascrivere (override)
- [ ] Al salvataggio del documento: se `indirizzo_spedizione_id` è NULL, i campi snapshot vengono dall'input dell'utente (una tantum)
- [ ] Alla transizione `→ inviata/confermata`: verificare lato backend che lo snapshot sia valorizzato; rifiutare il cambio di stato se snapshot vuoto
- [ ] Alla transizione `→ inviata/confermata`: bloccare qualsiasi modifica successiva ai campi snapshot
- [ ] Creazione ordine da preventivo: copiare FK + tutti i campi snapshot
- [ ] Creazione spedizione da ordine: stessa logica
- [ ] Creazione DDT da spedizione: stessa logica

### Frontend
- [ ] Al cambio cliente: chiamate API in parallelo per indirizzi e contatti; svuotare i select precedenti prima di popolare
- [ ] Se indirizzo predefinito trovato: preselezionarlo e mostrare subito il preview
- [ ] Se nessun indirizzo trovato: aprire automaticamente il form di inserimento manuale
- [ ] Select indirizzi: label breve (nome sede + città), non indirizzo completo
- [ ] Preview indirizzo: editabile inline con evidenziazione dei campi modificati
- [ ] Preview: tasto "✕" per deselezionare, tasto "✎" per modificare
- [ ] Form una tantum: checkbox "Salva anche in anagrafica" (default spuntato se il cliente non ha indirizzi)
- [ ] Select referenti: precompila il campo testo "alla c.a. di" al cambio selezione
- [ ] Campo "alla c.a. di": sempre editabile anche dopo selezione dal select
- [ ] Dopo conferma/invio: campi indirizzo read-only con icona lucchetto, senza possibilità di salvataggio

### Stampa
- [ ] PDF legge **solo** i campi snapshot, mai JOIN a `indirizzi` o `clienti_fornitori`
- [ ] Testare la stampa con un documento il cui cliente ha cambiato indirizzo in anagrafica: il PDF deve mostrare ancora quello vecchio
