# MANUALE COMPLETO E DEFINITIVO DEL CICLO VENDITE ERP

Questo documento raccoglie tutto il materiale tecnico, architetturale e procedurale relativo al ciclo vendite, organizzato in parti tematiche, mantenendo l'integrità e la profondità dei documenti originali.

=================================================================================

# PARTE: CICLO VENDITE DA PREVENTIVO A SPEDIZIONE E FATTURAZIONE

=================================================================================

# Ciclo Vendite: da Preventivo a Spedizione e Fatturazione

Il ciclo vendite gestisce il flusso commerciale attivo dell'azienda: dalla quotazione al cliente (preventivo/offerta) all'acquisizione dell'ordine, alla preparazione della merce (con relativo scarico lotti), alla redazione del Documento di Trasporto (DDT) ed infine alla marcatura dello stato di fatturazione.

Si ispira alle best practice dei principali ERP internazionali (SAP, Odoo, Dynamics) adattate al commercio chimico e alla semplicità operativa.

---

## 1. Moduli Logici e Nomenclatura Database

Nel database di Quack c'è una distinzione netta tra preventivi attivi (inviati ai clienti) e offerte passive (ricevute dai fornitori):

* **Ciclo Vendite (Clienti)**:
  * **preventivi** (Testata del preventivo inviato al cliente, gestito tramite l'indirizzo `/offers` nel browser)
  * **preventivi_righe** (Righe del preventivo cliente)
  * **ordini_clienti** / **ordini_clienti_righe** (Gli ordini confermati dai clienti)
* **Ciclo Acquisti (Fornitori)**:
  * **offerte_testata** / **offerte_righe** (Le quotazioni ricevute dai fornitori in risposta alle RFQ)

---

## 2. Gestione Versioni e Revisioni Preventivi Cliente

Per tenere traccia dello storico delle trattative con il cliente senza violare il vincolo `UNIQUE` sul campo `numero_preventivo`, si adotta la strategia del **codice con suffisso di revisione**:

1. **Prima emissione (v1)**: Il preventivo viene registrato con il numero progressivo standard:
   * Esempio: `PREV/2026/0004`
2. **Creazione nuova versione**: Quando il preventivo è in stato "Inviato" e il cliente richiede modifiche:
   * L'utente clicca su **"Crea Nuova Versione"**.
   * Il preventivo originario passa allo stato **annullato** (con `storico = 1`). (Nota tecnica: gli stati reali gestiti dal database per il preventivo sono `bozza`, `inviato`, `accettato`, `rifiutato`, `scaduto`, `annullato`).
   * Il nuovo preventivo viene creato in stato **bozza**, con `storico = 0`.
   * Il numero del nuovo preventivo viene calcolato aggiungendo un suffisso incrementale:
     * Versione 2: `PREV/2026/0004-02`
     * Versione 3: `PREV/2026/0004-03`
3. **Vantaggio**: Questo metodo permette di confrontare le diverse versioni inviate allo stesso cliente mantendole tutte nel database ed è pienamente compatibile con il vincolo `UNIQUE` di SQLite.

---

## 3. Da Preventivo a Ordine Cliente (Sales Order)

Quando il cliente accetta un'offerta:

* L'utente clicca su **"Accetta e Crea Ordine"** o **"Conferma Ordine"** (termini commerciali adatti alle vendite, a differenza di "Aggiudica" usato per gli acquisti).
* Il sistema:
  1. Cambia lo stato del preventivo in **accettato** (il database supporta l'intero ciclo di vita: `bozza`, `inviato`, `accettato`, `rifiutato`, `scaduto`, `annullato`).
  2. Crea un record in `ordini_clienti` (es. `ODC/2026/0001`).
  3. Copia le righe in `ordini_clienti_righe` mantenendo il riferimento `preventivo_riga_id` per fini statistici.
  4. Riserva le quantità a magazzino (incrementa `quantita_riservata` in `giacenze` per gli articoli coinvolti).

---

## 4. Esecuzione dell'Ordine: Spedizioni e Assegnazione Lotti (Pick & Pack)

Nel settore chimico, la spedizione della merce richiede la tracciabilità assoluta del lotto.

### **Assegnazione dei Lotti (`spedizioni_righe.lotto_id`)**

Al momento della preparazione della spedizione:

* L'operatore seleziona quali lotti fisici prelevare per coprire la quantità ordinata.
* Il sistema supporta lo **split multi-lotto**: se servono 100 kg, l'operatore può prelevare 60 kg dal *Lotto A* e 40 kg dal *Lotto B*.
* Il database memorizza questa associazione sulla riga di spedizione, nel campo `spedizioni_righe.lotto_id` — non esiste una tabella dedicata sull'ordine: la scelta del lotto avviene solo alla spedizione, non alla conferma ordine. Vedi il capitolo "Spedizioni e Assegnazione Lotti" più avanti in questo Manuale per il dettaglio (FEFO, split multi-lotto).

### **Spedizione e DDT**

1. **Bolla di Spedizione (`spedizioni`)**: Registra l'avvenuto prelievo e la data di spedizione effettiva.
2. **Generazione DDT (`documenti_trasporto`)**:
   * Viene generato il DDT agganciato alla spedizione.
   * Le informazioni ADR (numero ONU, classe di pericolo, gruppo di imballaggio) vengono denormalizzate sulle righe del DDT per consentire stampe conformi alle normative sui trasporti di merci pericolose.
3. **Aggiornamento Giacenze**:
   * La merce esce dal magazzino:
     * `quantita_disponibile` dell'articolo si riduce.
     * `quantita_riservata` si riduce.
     * La giacenza specifica del `lotto` prelevato viene scaricata.

---

## 5. Stato di Fatturazione Semplificato

Poiché la fatturazione reale può essere gestita esternamente tramite software dedicati (SGA, Doc Finance), Quack non obbliga l'utente ad emettere fatture elettroniche o compilare dettagli amministrativi complessi. 

Al contrario, implementa una marcatura di stato semplice e visiva:

* **Stato dell'Ordine / DDT**: Un campo booleano o uno stato semplice (es. `Fatturato: Sì / No`, o `stato_fatturazione` tra `da_fatturare` e `fatturato`).
* Questo permette all'utente di contrassegnare l'ordine come "Fatturato" con un solo clic, garantendo la riconciliazione visiva senza costringere a fare data entry ridondante.

=================================================================================

# PARTE: INDIRIZZI-CONTATTI-NEL-CICLO-VENDITE

=================================================================================

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

| Strato                      | Cos'è                                           | A cosa serve                                                                   |
| --------------------------- | ----------------------------------------------- | ------------------------------------------------------------------------------ |
| **FK live**                 | L'ID dell'indirizzo master in anagrafica        | Navigazione, filtri, reportistica, "da dove siamo partiti"                     |
| **Snapshot denormalizzato** | Le colonne dell'indirizzo copiate sul documento | Stampa, integrità storica, indipendenza dall'anagrafica                        |
| **Override inline**         | Modifiche ai campi snapshot senza toccare la FK | Eccezioni una tantum (cantiere temporaneo, piano diverso, referente sostituto) |

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

Quando il preventivo viene inviato (stato `bozza → inviato`), l'interfaccia deve segnalare che lo snapshot è ora bloccato. (Nota tecnica: gli stati reali previsti nel database sono `bozza`, `inviato`, `accettato`, `rifiutato`, `scaduto`, `annullato`). L'approccio migliore è mostrare i campi come read-only con un'icona lucchetto, e aggiungere un avviso esplicito:

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
Operatore clicca "Invia preventivo" (stato bozza → inviato)
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
            → Lo stato passa a 'scaduto' (automaticamente o manualmente)
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

**Quando bloccare lo snapshot?** Alla transizione `bozza → inviato` o `bozza → accettato`. Il blocco va verificato lato backend ad ogni salvataggio — non fidarsi solo del frontend.

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

=================================================================================

# PARTE: GUIDA ALLA GESTIONE DEI LISTINI VENDITA ERP

=================================================================================

# Guida Tecnica e Funzionale: Gestione Listini Vendita in un ERP Best-in-Class

## 1. Come si comportano i Listini nei Preventivi? (La Logica Funzionale)

Nei migliori ERP, il listino **non è un blocco rigido**, ma una **guida intelligente**. Ecco come si comporta quando crei un preventivo o un ordine di vendita:

### Suggerimento vs Obbligo

- **Comportamento Standard (Suggerimento):** Quando selezioni un cliente nel preventivo, l'ERP carica il suo listino associato. Quando aggiungi una riga prodotto, il sistema calcola il prezzo in base al listino e lo inserisce automaticamente nel campo `Prezzo Unitario`.

- **Flessibilità di Modifica:** L'operatore commerciale può quasi sempre sovrascrivere manualmente il prezzo suggerito (es. per fare una trattativa spot).

- **Controllo (Permissions & Approvals):** Se l'operatore abbassa il prezzo sotto una determinata soglia stabilita dal listino (o sotto il costo del prodotto), l'ERP non blocca l'inserimento, ma:
  
  1. Evidenzia il campo. Con simboli o con colore rosso/arancione.

### Tracciabilità del Prezzo Originale

Un ERP best-in-class si focalizza sulla redditività reale piuttosto che sul prezzo di listino originario, che ha natura "statistica". L'applicazione memorizza informazioni vitali direttamente sulla riga transazionale (`preventivi_righe`), senza intasarla con il listino o l'RRP. Salva:

1. `Costo Unitario` (il costo di acquisto congelato al momento dell'offerta)
2. `Prezzo Netto` (prezzo finale di vendita applicato al cliente)
3. `Margine Riga` (differenza tra imponibile e costo totale della riga)

Questo approccio è lo standard (es. SAP tramite le condition `Statistical`): le tabelle dell'ordine non vengono riempite con i prezzi teorici del fornitore, i quali restano visibili solo in fase di compilazione, ma storicizzano il costo effettivo e il margine reale generato dalla trattativa.

## 2. Architettura dei Dati: Listini Generali vs Listini Dedicati

> **La regola d'oro dei Best-in-Class:** Listini generali (per tutti) e listini dedicati (per un solo cliente) **NON sono oggetti diversi a livello di database**. Sono lo stesso identico oggetto, ma con condizioni di applicabilità diverse.

Se crei tabelle separate per "listini generici" e "listini dedicati", il tuo backend diventerà un incubo di `IF/ELSE`.

### Il Modello a "Regole di Pricing" e il "Listino Base" (Best Practice)

Un listino è semplicemente un **contenitore di regole**.

In un sistema enterprise, **l'anagrafica del prodotto non contiene il prezzo di vendita base**. Memorizzare il prezzo di vendita nella tabella prodotti è un errore bloccante per la gestione multivaluta, la storicità e il multi-società.

Invece, esiste un **Listino Standard / Pubblico** (es. ID 1) che contiene il prezzo base "di listino puro" per tutti gli articoli. Tutti gli altri listini speciali (dedicati ai clienti o ai gruppi) lavorano in percentuale di sconto o maggiorazione rispetto a questo Listino Standard, oppure definiscono prezzi fissi sostitutivi.

Le regole vengono valutate in base a una priorità. L'ERP cerca la regola più specifica applicabile a quella vendita. Se non la trova, sale di livello fino al listino generale.

L'assegnazione funziona così:

1. **Listino Standard / Pubblico (Default):** Contiene i prezzi base ufficiali dell'azienda. Si applica a tutti i clienti che non hanno un listino specifico.

2. **Listino di Gruppo (es. "Grossisti", "Distributori"):** Associato a una categoria di clienti.

3. **Listino Specifico (es. "Listino Speciale Cliente ACME"):** Associato a un singolo cliente.

## 3. Schema del Database (SQL)

Ecco un design di database relazionale pulito, scalabile e ottimizzato che disaccoppia i prodotti dai loro prezzi di vendita.

```
-- 1. Tabella Prodotti (SENZA prezzo_base, contiene solo costi e metadati)
CREATE TABLE prodotti (
    id SERIAL PRIMARY KEY,
    codice VARCHAR(50) UNIQUE NOT NULL,
    nome VARCHAR(100) NOT NULL,
    categoria_id INT, -- FK a categorie_prodotti
    costo_medio_ponderato DECIMAL(12,4) DEFAULT 0.0000,
    ultimo_costo_acquisto DECIMAL(12,4) DEFAULT 0.0000,
    attivo BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. Tabella dei Listini (La testata del listino)
CREATE TABLE listini (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(100) NOT NULL, -- es. "Listino Base Standard", "Listino Grossisti 2026", "Listino ACME S.p.A."
    valuta VARCHAR(3) DEFAULT 'EUR', -- Gestione multivaluta nativa
    attivo BOOLEAN DEFAULT TRUE,
    data_inizio DATE,
    data_fine DATE,
    is_default BOOLEAN DEFAULT 0, -- Identifica il listino base ufficiale senza cablare ID (best practice)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 3. Tabella di associazione Cliente -> Listino
CREATE TABLE clienti_listini (
    id SERIAL PRIMARY KEY,
    cliente_id INT NOT NULL, -- FK a clienti_fornitori
    listino_id INT NOT NULL, -- FK a listini
    priorita INT DEFAULT 1,  -- Più è alto, più comanda (es. listino promo temporaneo batte quello fisso)
    FOREIGN KEY (listino_id) REFERENCES listini(id)
);

-- 4. Le Regole del Listino (Il motore dei prezzi)
CREATE TABLE listini_regole (
    id SERIAL PRIMARY KEY,
    listino_id INT NOT NULL REFERENCES listini(id) ON DELETE CASCADE,

    -- Ambito di applicazione (Almeno uno deve essere valorizzato, l'ERP valuta dal più specifico al più generico)
    prodotto_id INT REFERENCES prodotti(id) ON DELETE CASCADE, 
    categoria_prodotto_id INT, -- Per applicare sconti/maggiorazioni a intere famiglie chimiche

    -- Condizioni
    quantita_minima DECIMAL(12,4) DEFAULT 0.0000, -- Per scaglioni di quantità (es. prezzo per fusto vs prezzo per cisterna)
    data_inizio DATE,
    data_fine DATE,

    -- Come viene calcolato il prezzo?
    -- Tipi di calcolo: 
    -- 'fixed' (Prezzo fisso, es. 12.50 €/kg. Obbligatorio nel "Listino Standard" per definire il prezzo base)
    -- 'discount' (Sconto % da applicare al prezzo trovato nel "Listino Standard")
    -- 'formula' (Costo del prodotto + % ricarico - utilissimo nel chimico per volatilità materie prime)
    tipo_calcolo VARCHAR(30) NOT NULL CHECK (tipo_calcolo IN ('fixed', 'discount', 'formula')),

    valore DECIMAL(12,4) NOT NULL, -- Può essere il prezzo fisso (es. 15.50), la % di sconto (es. 10.00 per 10%), o la % di ricarico sul costo (es. 20.00 per +20%)

    -- Se tipo_calcolo è 'formula', specifichiamo la base di partenza
    formula_base VARCHAR(30) CHECK (formula_base IN ('costo_medio_ponderato', 'ultimo_costo_acquisto')), 

    priorita INT DEFAULT 10, -- Usato per risolvere i conflitti (es. regola prodotto singolo batte regola categoria)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

## 4. Backend & Algoritmo di Calcolo (Il "Pricing Engine")

Il backend deve esporre un servizio (o API) chiamato `PricingEngine`. Tutto il calcolo del prezzo deve passare da qui.

### L'Algoritmo di calcolo (Pseudocode / Logic)

Quando l'utente seleziona un prodotto o cambia la quantità in un preventivo, il frontend chiama il backend passando `cliente_id`, `prodotto_id`, `quantita`, `data_documento`.

```
def calcola_prezzo_vendita(cliente_id, prodotto_id, quantita, data_documento):
    # Recupera l'ID del listino standard globale (quello con is_default = 1)
    # L'uso di un flag a database permette di cambiare il listino base senza toccare il codice
    listino_base = db.query("SELECT id FROM listini WHERE is_default = 1 LIMIT 1")
    ID_LISTINO_STANDARD = listino_base.id if listino_base else 1 

    # 1. Recupera i dati del prodotto (costi e categoria)
    prodotto = db.query("SELECT costo_medio_ponderato, ultimo_costo_acquisto, categoria_id FROM prodotti WHERE id = ?", prodotto_id)
    if not prodotto:
        raise Exception("Prodotto non trovato")

    # 2. Trova il listino attivo personalizzato per questo cliente
    listino_cliente_id = db.query("""
        SELECT listino_id FROM clienti_listini 
        JOIN listini ON listini.id = clienti_listini.listino_id
        WHERE cliente_id = ? AND listini.attivo = TRUE 
        AND (listini.data_inizio IS NULL OR listini.data_inizio <= ?)
        AND (listini.data_fine IS NULL OR listini.data_fine >= ?)
        ORDER BY priorita DESC LIMIT 1
    """, cliente_id, data_documento, data_documento)

    ha_listino_dedicato = True
    if not listino_cliente_id:
        listino_cliente_id = ID_LISTINO_STANDARD
        ha_listino_dedicato = False

    # 3. Cerca la regola più specifica nel listino assegnato al cliente
    regola = cerca_regola_in_listino(listino_cliente_id, prodotto_id, prodotto.categoria_id, quantita, data_documento)

    # 4. CASO CRITICO: Il prodotto non ha una regola nel listino dedicato del cliente!
    if not regola and ha_listino_dedicato:
        # Fallback 1: Cerca nel Listino Standard/Pubblico di base
        regola_generale = cerca_regola_in_listino(ID_LISTINO_STANDARD, prodotto_id, prodotto.categoria_id, quantita, data_documento)
        if regola_generale:
            prezzo = esegui_calcolo_regola(regola_generale, prodotto, ID_LISTINO_STANDARD)
            return {
                "prezzo": prezzo,
                "origine": "listino_generale",
                "dettaglio": "Prodotto assente nel listino dedicato. Applicata regola da Listino Standard."
            }
        else:
            # Fallback 2: Nessuna regola definita neanche nel listino standard (Manca il prezzo base!)
            # Applichiamo una regola di emergenza: Costo Medio Ponderato + 20% di margine minimo
            prezzo_emergenza = prodotto.costo_medio_ponderato * 1.20
            return {
                "prezzo": prezzo_emergenza,
                "origine": "calcolo_emergenza_costo",
                "dettaglio": "Nessun prezzo di listino trovato. Applicato ricarico di emergenza su costo (+20%)."
            }

    # 5. Se invece abbiamo trovato la regola nel listino principale
    if not regola:
        # Nessuna regola trovata nel listino standard/generico
        prezzo_emergenza = prodotto.costo_medio_ponderato * 1.20
        return {
            "prezzo": prezzo_emergenza,
            "origine": "calcolo_emergenza_costo",
            "dettaglio": "Nessuna regola applicabile nel listino standard. Applicato ricarico di emergenza."
        }

    prezzo_calcolato = esegui_calcolo_regola(regola, prodotto, ID_LISTINO_STANDARD)
    origine_prezzo = "listino_dedicato" if ha_listino_dedicato else "listino_generale"

    return {
        "prezzo": prezzo_calcolato,
        "origine": origine_prezzo,
        "dettaglio": f"Applicata regola ID {regola.id} del listino {listino_cliente_id}."
    }

def cerca_regola_in_listino(listino_id, prodotto_id, categoria_id, quantita, data_documento):
    return db.query("""
        SELECT * FROM listini_regole
        WHERE listino_id = ?
          AND (prodotto_id = ? OR prodotto_id IS NULL)
          AND (categoria_prodotto_id = ? OR categoria_prodotto_id IS NULL)
          AND quantita_minima <= ?
          AND (data_inizio IS NULL OR data_inizio <= ?)
          AND (data_fine IS NULL OR data_fine >= ?)
        ORDER BY 
          (prodotto_id IS NOT NULL) DESC,          -- Priorità 1: Prodotto specifico
          (categoria_prodotto_id IS NOT NULL) DESC, -- Priorità 2: Categoria specifica
          quantita_minima DESC,                    -- Priorità 3: Scaglione di quantità più alto applicabile
          priorita DESC                            -- Priorità 4: Priorità manuale impostata a DB
        LIMIT 1
    """, listino_id, prodotto_id, categoria_id, quantita, data_documento)

def esegui_calcolo_regola(regola, prodotto, id_listino_standard):
    if regola.tipo_calcolo == 'fixed':
        return regola.valore

    elif regola.tipo_calcolo == 'discount':
        # Per calcolare lo sconto percentuale, dobbiamo prima recuperare il prezzo base "fixed" dal Listino Standard
        regola_base = db.query("""
            SELECT valore FROM listini_regole 
            WHERE listino_id = ? AND prodotto_id = ? AND tipo_calcolo = 'fixed' 
            LIMIT 1
        """, id_listino_standard, prodotto.id)

        prezzo_base = regola_base.valore if regola_base else (prodotto.costo_medio_ponderato * 1.20)
        sconto = prezzo_base * (regola.valore / 100)
        return prezzo_base - sconto

    elif regola.tipo_calcolo == 'formula':
        base_costo = prodotto.costo_medio_ponderato if regola.formula_base == 'costo_medio_ponderato' else prodotto.ultimo_costo_acquisto
        return base_costo * (1 + (regola.valore / 100))
```

## 5. Frontend & UX (Interfaccia Utente)

Per rendere l'ERP piacevole e intuitivo, l'interfaccia deve comunicare chiaramente cosa sta succedendo dietro le quinte.

### Nella scheda Cliente

- Deve esserci un campo a discesa chiamato **"Listino Vendite di Default"**.

- Di fianco, un pulsante rapido "Vedi regole listino" per vedere cosa c'è dentro senza dover navigare altrove.

### Nella scheda Listini (Pannello Amministrativo)

Non separare le schermate. Crea un'unica interfaccia di gestione listini dove l'utente può:

1. Creare un listino (es. "Listino Clienti VIP").

2. Associare i clienti a quel listino direttamente da una lista multi-selezione o drag-and-drop nella stessa schermata.

3. Definire le regole tramite una tabella editabile (un "DataGrid") dove ogni riga è una regola (Prodotto / Categoria | Quantità Minima | Tipo (Fisso/%/Formula) | Valore).

### Nella schermata del Preventivo (UX Dinamica)

Quando inserisci le righe d'ordine:

- Se modifichi la quantità da 1 a 1000, l'interfaccia deve fare una chiamata asincrona (debounced) al `PricingEngine` e aggiornare istantaneamente il prezzo unitario se scatta uno scaglione.

- **Visualizzazione in Tempo Reale dei Costi e del Margine (Stato Attuale Implementato)**:
  Anziché un complesso sistema di icone a tag per l'origine del listino, l'applicazione attualmente implementa una visualizzazione molto più pragmatica e fondamentale per il settore distributivo.
  Sulla riga del preventivo sono presenti:
  
  - La colonna **Costo Acquisto** (prelevato direttamente dal costo del prodotto in quel momento).
  - Il **Range Consigliato (RRP)** del fornitore (prelevato dall'anagrafica prodotto-fornitore).
  - Il calcolo in real-time del **Delta/Margine**.
    *Nota Best Practice:* Questa interfaccia è ottimizzata per massimizzare la profittabilità durante la trattativa: il commerciale basa il suo ricarico sul costo reale della merce e sui suggerimenti del fornitore, rendendo l'origine esatta del "listino" un dato secondario rispetto al margine effettivo calcolato a riga.

## 6. Il Caso Speciale del Settore Chimico (Cruciale per il tuo ERP!)

Visto che stai sviluppando un ERP per **prodotti chimici**, i listini standard degli ERP generici non ti basteranno. Devi implementare queste tre logiche fin da subito per essere "best-in-class":

### A. Gestione Doppia Unità di Misura (UoM)

I prodotti chimici si acquistano e vendono spesso con unità di misura diverse (es. compri in Kg, vendi in fusti da 25 litri o in cisterna).

- Il listino deve supportare la conversione automatica.

- Esempio: Il listino base definisce $1.50\text{ €/Kg}$. Se il commerciale seleziona nel preventivo "Fusto da 200 Litri" (e la densità del prodotto rende un fusto pari a $180\text{ Kg}$), il sistema deve saper calcolare:
  
  $$\text{Prezzo Fusto} = 1.80\text{ €/Kg} \times 180\text{ Kg} = 324.00\text{ €}$$

### B. Aggiornamento Massivo per Volatilità (Costo Chimico + Margine)

I prezzi del settore chimico fluttuano violentemente in base al costo del petrolio e delle materie prime. I tuoi clienti non aggiorneranno mai i listini fissi riga per riga a mano.

- Implementa la formula **"Costo + Margine"** come mostrato nello schema DB.

- Esempio: "Il prezzo del solvente è pari al *Costo Medio Ponderato di questa settimana* + $15\%$". In questo modo, il listino si auto-aggiorna in base alle oscillazioni d'acquisto senza che l'utente debba ricalcolare nulla a mano.

## 7. Cosa succede se un Prodotto NON è nel Listino del Cliente? (La "Cascata di Fallback")

Questo è uno scenario quotidiano: crei un preventivo per il Cliente ACME (che ha il suo listino personalizzato), ma inserisci un prodotto nuovo o non abituale che non ha una regola specifica in quel listino.

Un ERP allo stato dell'arte non restituisce mai un errore bloccante e non lascia il prezzo a zero. Applica invece una **Cascata di Fallback** (Ereditarietà) strutturata in 4 livelli.

```
[Listino Dedicato Cliente] ──(No)──> [Listino Standard / Pubblico] ──(No)──> [Ricarico di Emergenza su Costo] ──(No)──> [Blocco / Richiesta Prezzo]
```

### I 4 Livelli di Fallback

1. **Livello 1: Listino Dedicato al Cliente**
   
   Il sistema cerca nel listino associato al cliente una regola specifica per il Prodotto (o per la Categoria di quel prodotto) valida per la quantità inserita.

2. **Livello 2: Listino Standard / Pubblico (Primo Fallback)**
   
   Se il punto 1 fallisce, il sistema scala sul **Listino Standard** (ID 1). Cerca se esiste una regola di prezzo fissa (`fixed`) per quel prodotto specifico. Trattandosi del listino ufficiale di base, tutti i prodotti attivi dovrebbero teoricamente avere un prezzo definito qui.

3. **Livello 3: Ricarico di Emergenza su Costo (Secondo Fallback)**
   
   Se il prodotto non è stato configurato nemmeno nel Listino Standard per dimenticanza dell'ufficio acquisti/vendite, l'ERP estrae il `Costo Medio Ponderato` (o l'ultimo costo d'acquisto) dalla tabella prodotti e applica una percentuale di margine minimo di sicurezza aziendale pre-configurata (es. `Costo + 20%`).

4. **Livello 4: Blocco / Richiesta Prezzo (Ultima Spiaggia)**
   
   Se manca anche il dato del costo, il prezzo viene impostato a `0.00`, la riga viene evidenziata in rosso bloccando la conferma del preventivo e viene richiesta la valorizzazione manuale da parte di un utente con privilegi di amministratore commerciale.

### UX & Feedback Visivo: Come presentarlo al commerciale?

Se un prodotto non è nel listino dedicato, l'operatore deve saperlo istantaneamente per evitare di fare preventivi con margini sbagliati o prezzi standard a clienti che si aspettano trattamenti di favore.

- **Icona di Warning Soft:** Vicino al prezzo unitario della riga del preventivo, l'ERP mostra un badge giallo o grigio (es. un punto esclamativo o un'icona "link interrotto").

- **Tooltip Esplicativo:** Passando il mouse sull'icona, il sistema mostra:
  
  > *"Attenzione: Questo prodotto non fa parte del 'Listino Personalizzato ACME'. È stato applicato il prezzo di fallback dal 'Listino Standard' (€15.00/Kg)."*

- **Il tasto magico: "Aggiungi al listino del cliente" (Inline Upsert)**
  
  Se l'utente ha i permessi commerciali appropriati, l'ERP mostra un pulsante rapido sulla riga del preventivo: **"Salva questo prezzo nel listino di ACME"**.
  
  Cliccandolo, il commerciale può digitare un prezzo concordato al volo (es. €14.00) e il sistema, in background, crea automaticamente una nuova riga nella tabella `listini_regole` legata al listino di quel cliente. Al prossimo preventivo, il prodotto sarà già scontato senza dover uscire dalla schermata di vendita.

## 8. UX & Data Entry: Come gestire l' "Illusione del Prezzo Base" nel Frontend

Disaccoppiare i prezzi dal prodotto a livello di database è una best practice ingegneristica, ma **può distruggere l'esperienza utente** se costringe a navigare in tre schede diverse solo per impostare il prezzo di un nuovo articolo.

Gli ERP best-in-class superano questo limite strutturando l'interfaccia frontend tramite tre pattern di design:

### A. L' "Illusione Ottica" nella scheda Prodotto (Prezzo Base Diretto)

Quando l'utente si trova sulla scheda di creazione del prodotto (`/prodotti/nuovo` o `/prodotti/modifica`), **deve vedere** un campo di input chiamato **"Prezzo di Vendita Base (€)"**.

- **Come funziona lato client:** L'utente inserisce semplicemente `15.50` nel campo. Per lui, quel prezzo è una proprietà fisica del prodotto (proprio come il nome o il codice).

- **Come funziona lato API/Backend:** Quando l'utente preme "Salva", il frontend invia un payload unificato:
  
  ```
  {
    "codice": "SOLV-01",
    "nome": "Solvente Acetone Puro",
    "prezzo_vendita_base": 15.50,
    "costo_medio_ponderato": 8.00
  }
  ```
  
  Il backend intercetta la richiesta ed esegue una **transazione atomica SQL**:
  
  1. Fa l'INSERT/UPDATE del record nella tabella `prodotti`.
  
  2. Fa un **UPSERT** (Inserimento o Aggiornamento) automatico nella tabella `listini_regole` forzando il `listino_id = 1` (Listino Standard), `tipo_calcolo = 'fixed'`, `valore = 15.50` e associando il `prodotto_id` appena creato.

*L'utente ha l'illusione di salvare il prezzo sul prodotto, ma a DB i dati rimangono normalizzati, puliti e scalabili.*

### B. Il Tab "Prezzi & Listini" (Matrice locale sul Prodotto)

Sempre all'interno della scheda prodotto, di fianco alle informazioni di magazzino o tecniche, i grandi ERP inseriscono un pannello (Tab) denominato **"Vendite & Pricing"**.

In questa sezione viene visualizzata una tabella dinamica (DataGrid) che mostra tutte le regole di prezzo esistenti per *quel* prodotto in tutti i listini del sistema:

| Listino                     | Target                  | Quantità Min. | Tipo Calcolo        | Valore        | Validità      | Azioni     |
| --------------------------- | ----------------------- | ------------- | ------------------- | ------------- | ------------- | ---------- |
| **Listino Standard (Base)** | Tutti i Clienti         | 0 Kg          | Prezzo Fisso        | **15.50 €**   | *Sempre*      | [Modifica] |
| **Listino Grossisti**       | Categoria: Distributori | 1000 Kg       | Sconto %            | **10.00 %**   | *Sempre*      | [Elimina]  |
| **Special ACME**            | Cliente: ACME S.p.A.    | 5000 Kg       | Formula (Costo + %) | **+ 12.00 %** | Fino al 31/12 | [Elimina]  |

- **Vantaggio:** Il product manager non deve andare nella sezione "Configurazione Listini" per decidere quanto costa un prodotto per i vari clienti. Lo fa direttamente mentre compila la scheda tecnica dell'articolo.

### C. La Tabella Excel-Like (Massive Editing)

Se l'azienda commercializza $10.000$ articoli chimici, non è pensabile che l'ufficio prezzi apra ogni singola scheda prodotto per aggiornare i listini di base o speciali.

I migliori ERP espongono una vista tabellare nativa (basata su librerie ad alte prestazioni come *ag-Grid* o *Handsontable*) che replica il comportamento di Microsoft Excel:

- L'utente seleziona un listino (es. "Listino Standard").

- L'ERP mostra una griglia editabile con due colonne principali: **Prodotto** e **Prezzo Fisso**.

- L'utente può usare le frecce della tastiera per muoversi tra le celle, sovrascrivere i valori alla velocità della luce, trascinare i dati per copiare i prezzi su righe adiacenti e salvare $500$ modifiche in un colpo solo tramite una sola chiamata batch alle API.

=================================================================================

# PARTE: RRP_ANALISI_BEST_PRACTICE

=================================================================================

# RRP (Recommended Retail Price) — Analisi Best Practice e Piano di Implementazione Quack ERP

> Documento generato il 05/06/2026  
> Contesto: Quack ERP v4.2 — JS + PHP + SQLite (→ MySQL)  
> Scopo: ricerca ERP enterprise, valutazione DB esistente, piano modifiche frontend/backend

---

## 1. Come gestiscono il RRP i principali ERP enterprise

### 1.1 SAP S/4HANA — SD Condition Technique

SAP non tratta il RRP come un campo fisso sulla tabella prodotto. Lo modella come un **Condition Type dedicato** all'interno di una Pricing Procedure configurabile. Il modello dati ruota su tre entità:

- **`KONH`** (Condition Header): chiave della condizione (es. fornitore + materiale + sales area), date di validità `ValidFrom`/`ValidTo`, valuta.
- **`KONP`** (Condition Item): valore, scala quantitativa, unità di misura, percentuale vs importo assoluto.
- **`PRCD_ELEMENTS`** (S/4HANA, sostituisce il vecchio `KONV`): snapshot di tutte le condizioni attivate su ogni riga documento al momento della creazione. I campi `KZWI1–KZWI6` in `VBAP`/`VBRP` memorizzano subtotali intermedi (uno viene tipicamente usato per il valore RRP o il costo, rendendolo disponibile per CO-PA e BW/BI).

**Historicity e versioning:** tramite validity period (ValidFrom/ValidTo). Quando il prezzo cambia si crea un nuovo record con nuova data; il vecchio viene chiuso. Non esiste un version number esplicito, ma la storia è ricostruibile via change documents.

**Multi-currency:** sì, ogni condition record ha la propria valuta. Esiste anche un Currency Conversion List per convertire automaticamente.

**Multi-fornitore:** sì, tramite *access sequence* configurabile. Si può includere il Vendor nella key combination, ottenendo RRP diversi per fornitore.

**Comportamento nel preventivo/ordine:** un condition type marcato come `Statistical` viene calcolato ma non contribuisce al net value — perfetto per il RRP "solo informativo". Il valore viene scritto in `PRCD_ELEMENTS` e opzionalmente in un subtotale `KZWI`, disponibile per report ma invisibile al cliente sul documento.

**API:** OData `/A_PricingCondition` in S/4HANA Cloud; BAPI `BAPI_PRICES_CONDITIONS` e RFC per on-premise.

---

### 1.2 Oracle Fusion Cloud Pricing

Oracle separa fisicamente il concetto di "costo" dal "prezzo di listino/RRP":

- **Price List** (header + righe item): ogni item ha un `Base List Price` (= MSRP), scale break per quantità, attributi di pricing, secondary price list come fallback. Una Price List ha date di validità sull'header.
- **Cost List** (separato): contiene i costi per item; viene usato esclusivamente per il calcolo del margine. Questa separazione fisica è la più pulita tra i tre ERP.
- **Pricing Algorithm** (Groovy scripts): legge Price List + Cost List + Discount List, produce `Price Elements` (oggetti che catturano prezzo, costo, sconto, margine, tasse). Il `Base List Price` è il primo elemento calcolato e funge da riferimento RRP.

**Multi-currency:** Price List in valuta base + Currency Conversion List per conversione a runtime.

**API REST:** `/fscmRestApi/resources/latest/priceListItems` (CRUD su price list items) e `/fscmRestApi/priceExecution/documentPrices/priceSalesTransaction` (pricing real-time su transazione, risposta include `BasePrice`, `AdjustedPrice`, `NetPrice`, `Margin`).

---

### 1.3 Microsoft Dynamics 365 Finance & Operations / Commerce

Il RRP si mappa sul campo `Base Price` (campo `Price` nella scheda Sell della Released Product, tabella `InventTable` / `EcoResProduct`). La documentazione Microsoft afferma esplicitamente: *"Most customers put a high default price here, such as MSRP"*.

- **Trade Agreement Price** (tabella `PriceDiscTable`): righe con scope (Table/Group/All), date validità, valuta, quantità minima.
- **Margin alert nativo con semaforo a tre colori** sulle righe ordine/preventivo: verde (margine OK), giallo (sotto soglia "acceptable"), rosso (sotto soglia "questionable"). Le soglie sono configurabili. **Questo è il sistema più pronto all'uso dei tre per il nostro caso d'uso.**
- **API `GetActivePrices`:** restituisce `BasePrice`, `TradeAgreementPrice`, `AdjustedPrice` in una singola chiamata REST.

---

### 1.4 Sintesi comparativa

| Aspetto                   | SAP S/4HANA                               | Oracle Fusion                     | Dynamics 365                                   |
| ------------------------- | ----------------------------------------- | --------------------------------- | ---------------------------------------------- |
| Struttura DB RRP          | Tabella condizioni (KONH/KONP)            | Price List item separato          | Campo base price su prodotto + trade agreement |
| Storicità / Versioning    | ValidFrom/ValidTo + change docs           | Validity dates su price list      | Validity dates su trade agreement              |
| Multi-currency            | Sì, per condition record                  | Sì, con Currency Conversion List  | Sì, per trade agreement/price list             |
| Multi-fornitore           | Sì, tramite access sequence               | Parziale                          | No nativo                                      |
| Calcolo margine real-time | Sì, VPRS+PR00 in pricing procedure        | Sì, via Cost List + Price Element | Sì, con margin alert                           |
| Semaforo UI               | No nativo (serve customization)           | No nativo                         | **Sì nativo (verde/giallo/rosso)**             |
| ADR/chimici               | Modulo DGM integrato, surcharge condition | No nativo                         | No nativo                                      |

**Lezione chiave per noi:** tutti e tre gli ERP separano il RRP dal prezzo di vendita applicato; lo trattano come dato informativo/statistico che non influenza il totale; e lo collegano alla coppia prodotto-fornitore (non al solo prodotto).

---

## 2. Analisi approfondita del DB Quack ERP

### 2.1 Dove sono i dati RRP adesso

La tabella `prodotti_fornitori` (sezione 14 di `init_sqlite.sql`) contiene già:

```sql
rrp_min  DECIMAL(10,4),  -- prezzo di vendita MINIMO consigliato dal fornitore (in unita_misura_acquisto_id)
rrp_max  DECIMAL(10,4),  -- prezzo di vendita MASSIMO consigliato dal fornitore (in unita_misura_acquisto_id)
```

Questo è **già corretto** rispetto alle best practice enterprise: il RRP è legato alla **coppia prodotto-fornitore**, non al solo prodotto. SAP usa la stessa logica (key combination include il vendor). Benissimo.

### 2.2 Cosa funziona bene

| Elemento                                                                            | Giudizio        | Note                                                                     |
| ----------------------------------------------------------------------------------- | --------------- | ------------------------------------------------------------------------ |
| RRP in `prodotti_fornitori` (non in `prodotti`)                                     | ✅ Corretto      | Stesso approccio di SAP/Oracle: RRP per coppia prodotto-fornitore        |
| Multi-fornitore nativo                                                              | ✅ Corretto      | Un prodotto può avere RRP diversi da fornitori diversi                   |
| `ordine_preferenza_fornitore` in `prodotti_fornitori`                               | ✅ Utile         | Permette di sapere quale fornitore è il preferred per il default display |
| Separazione costo (`prodotti_fornitori_prezzi`) da RRP (`prodotti_fornitori.rrp_*`) | ✅ Corretto      | Oracle fa la stessa cosa con Price List vs Cost List                     |
| Margine calcolato e salvato in `preventivi` e `preventivi_righe`                    | ✅ Buono         | `margine_riga`, `margine_euro`, `margine_perc` già presenti              |
| `costo_unitario` salvato per riga preventivo                                        | ✅ Best practice | Snapshot del costo al momento dell'offerta, integrità storica            |
| `ruoli.can_view_costs` per controllo accesso ai costi                               | ✅ Ottimo        | Permette di nascondere costi/margini agli utenti non autorizzati         |

### 2.3 Lacune rispetto alle best practice enterprise

#### Lacuna 1 — Nessuna storicità sul RRP (CRITICA per ADR)

`rrp_min` e `rrp_max` sono campi semplici su `prodotti_fornitori`. Non hanno `valido_dal`/`valido_al`. Quando il fornitore aggiorna il suo RRP, il vecchio valore viene sovrascritto. Problema:

- I prodotti ADR hanno RRP che variano con le revisioni biennali ADR e con i listini annuali dei fornitori.
- Se un preventivo emesso 6 mesi fa viene ristampato o riaperto, non è possibile sapere qual era il RRP consigliato al momento dell'offerta.

SAP risolve con validity period sulla condition record. Oracle con validity dates sulla Price List.

**Soluzione raccomandata:** due opzioni, dalla più semplice alla più robusta.

*Opzione A (semplice, raccomandato per ora):* aggiungere `rrp_valido_dal DATE` e `rrp_valido_al DATE` direttamente in `prodotti_fornitori`. Semplice, nessuna migrazione di tabelle. Sufficiente per un'azienda con pochi aggiornamenti all'anno.

*Opzione B (robusta, come SAP/Oracle):* creare una tabella storica separata `prodotti_fornitori_rrp` con chiave `(prodotto_fornitore_id, valido_dal)`. Permette storico completo e query "qual era il RRP al 15/03/2025?". Richiede più lavoro ma è la scelta giusta se si prevede di auditare o comparare RRP nel tempo.

Per ora, l'Opzione A è sufficiente e non rompe nulla.

#### Lacuna 2 — UoM del RRP non esplicitata

`rrp_min`/`rrp_max` sono commentati come "in `unita_misura_acquisto_id`". Questa è l'unità di misura di acquisto del fornitore. Problema: nel preventivo di vendita si lavora con `unita_misura_commerciale` (es. €/kg o €/l), che può differire dall'unità d'acquisto (es. il fornitore quota in fusti da 25 kg ma noi vendiamo in €/kg).

La conversione esiste già nell'app (tramite `quantita_contenuto` e `fattore_base` in `unita_misura`), ma deve essere applicata anche al RRP quando viene esposto nell'API di pricing.

**Soluzione:** nessuna modifica al DB necessaria (l'UoM è già quella di acquisto per convenzione). La conversione va gestita nel backend al momento dell'esposizione, esattamente come già avviene per `costo_unitario` nel `SalesQuotesController`.

#### Lacuna 3 — Nessuna valuta esplicita sul RRP

Il RRP eredita implicitamente la valuta dalla colonna `valuta` in `dettagli_fornitori`. Per la maggior parte dei casi (fornitori italiani in EUR) va bene. Problema futuro: se si aggiunge un fornitore che quota in USD o GBP, il RRP non ha la valuta esplicita.

**Soluzione:** aggiungere `rrp_valuta CHAR(3) DEFAULT 'EUR'` a `prodotti_fornitori`. Piccola modifica, zero impatto sulle query esistenti.

#### Lacuna 4 — L'API di ricerca prodotti non espone il RRP

`qk_search_prodotti_sales()` in `includes/sales_quotes.php` (riga 100) esegue:

```sql
SELECT p.id, p.nome, p.sku_interno, um.codice, p.unita_misura_stock_id,
       p.descrizione_confezione, p.unita_contenuto_id, um_comm.codice, p.quantita_contenuto
FROM prodotti p ...
```

Non joinano `prodotti_fornitori`. Quindi il catalogo prodotti nella form preventivi **non ha accesso ai valori RRP** al momento della ricerca. Il catalogo mostrato nel mockup (con Costo, Min, Max su ogni card) non è ancora implementato.

#### Lacuna 5 — `apiOttieniPrezzo` non restituisce RRP né costo

Il metodo `SalesPricelistsController::apiOttieniPrezzo()` restituisce:

```json
{
  "ok": true,
  "prezzo_unitario": 0.89,
  "sconto_perc": 0,
  "valuta": "EUR",
  "listino_id": 1,
  "prezzo_sorgente": "Listino Standard: Base",
  "origine": "dedicato"
}
```

Non restituisce né `rrp_min`, né `rrp_max`, né `costo_unitario`. Il JS del preventivo (`fetchQuotePrice()`) non mostra quindi range consigliato né calcola il delta/margine visivo per riga. Il margine viene calcolato solo al salvataggio (backend PHP), non in real-time nel frontend.

#### Lacuna 6 — La form preventivi non mostra RRP né margine per riga

L'header della tabella righe preventivo è attualmente:

```
Cerca prodotto | Descrizione | Confezione | Qtà | U.M. | Prezzo unit. | Sc. % | IVA % | Totale | [X]
```

Mancano: Costo €/kg, Range consigliato (rrp_min → rrp_max), Margine/Delta per riga. Il mockup richiede esattamente queste colonne.

#### Lacuna 7 — Il modal riga listino vendita non mostra il RRP

`_modal_riga.php` per i listini di vendita mostra solo: prodotto, quantità minima, prezzo unitario, sconto, note. Non mostra il range RRP del fornitore come riferimento per chi sta impostando il prezzo nel listino. Questo è il punto più diretto di utilizzo del RRP: il pricing manager deve vedere il consigliato mentre imposta il prezzo.

### 2.4 Cosa NON serve modificare

- **La tabella `preventivi_righe` non deve salvare `rrp_min`/`rrp_max`.** Questi valori sono informativi e cambiano nel tempo: salvarli nella riga preventivo creerebbe ridondanza senza utilità, esattamente come SAP usa il flag `Statistical` per non far contribuire il RRP al valore del documento. Il margine (che invece deve essere cristallizzato) è già salvato in `margine_riga`.
- **La tabella `listini_prezzi` non deve includere RRP.** Il RRP appartiene alla relazione prodotto-fornitore, non al listino di vendita.

---

## 3. Piano modifiche frontend e backend

### 3.1 Modifiche al DB (opzionali ma raccomandate)

#### Migration A — Storicità RRP su `prodotti_fornitori`

```sql
-- Aggiunge periodo di validità al RRP (Opzione A, semplice)
ALTER TABLE prodotti_fornitori ADD COLUMN rrp_valido_dal DATE;
ALTER TABLE prodotti_fornitori ADD COLUMN rrp_valido_al  DATE;
ALTER TABLE prodotti_fornitori ADD COLUMN rrp_valuta CHAR(3) DEFAULT 'EUR';

-- Aggiunge indice per query "RRP attivo alla data X"
CREATE INDEX idx_prodotti_fornitori_rrp_validita 
    ON prodotti_fornitori(prodotto_id, fornitore_id, rrp_valido_dal, rrp_valido_al);
```

Queste tre colonne sono completamente additive: non rompono nessuna query o logica esistente. Il valore DEFAULT lascia NULL le righe esistenti, che continuano a funzionare esattamente come prima (= sempre valide).

Nota: per la migrazione MySQL, `ALTER TABLE ... ADD COLUMN` funziona identicamente. Nessun problema di compatibilità.

---

### 3.2 Modifiche backend

#### B1 — Aggiornare `qk_search_prodotti_sales()` in `includes/sales_quotes.php`

La funzione deve joinare `prodotti_fornitori` (fornitore preferito, `ordine_preferenza_fornitore = 1`) per restituire:

- `costo_unitario`: prezzo minimo dal fornitore preferito (`prodotti_fornitori_prezzi`)
- `rrp_min`: dal fornitore preferito
- `rrp_max`: dal fornitore preferito
- `rrp_fornitore_id`: per sapere a quale fornitore si riferisce il range

```php
// Query da aggiornare (in qk_search_prodotti_sales):
SELECT p.id, 
       p.nome AS label, 
       p.sku_interno AS sku, 
       um.codice AS unita_misura,
       p.unita_misura_stock_id,
       p.descrizione_confezione AS quantita_confezione,
       p.unita_contenuto_id,
       um_comm.codice AS unita_misura_commerciale,
       p.quantita_contenuto,
       -- RRP dal fornitore preferito
       pf.rrp_min,
       pf.rrp_max,
       pf.fornitore_id AS rrp_fornitore_id,
       -- Costo dal fornitore preferito (prezzo base, quantita_minima = 0)
       (SELECT MIN(pfp2.prezzo) 
        FROM prodotti_fornitori_prezzi pfp2 
        WHERE pfp2.prodotto_fornitore_id = pf.id 
          AND pfp2.quantita_minima = 0) AS costo_base
FROM prodotti p
LEFT JOIN unita_misura um ON um.id = p.unita_misura_stock_id
LEFT JOIN unita_misura um_comm ON um_comm.id = p.unita_contenuto_id
-- Join sul fornitore preferito (ordine 1); se non c'è, LEFT JOIN lascia NULL
LEFT JOIN prodotti_fornitori pf ON pf.prodotto_id = p.id 
    AND pf.attivo = 1 
    AND pf.ordine_preferenza_fornitore = (
        SELECT MIN(pf2.ordine_preferenza_fornitore) 
        FROM prodotti_fornitori pf2 
        WHERE pf2.prodotto_id = p.id AND pf2.attivo = 1
    )
WHERE p.attivo = 1
  AND (p.nome LIKE :q OR p.sku_interno LIKE :q)
ORDER BY p.nome
LIMIT 50
```

#### B2 — Aggiornare `apiOttieniPrezzo()` in `SalesPricelistsController`

Il metodo deve restituire anche `rrp_min`, `rrp_max` e `costo_unitario`, con conversione UoM se il cliente ha selezionato "unità di stock" vs "unità commerciale":

```php
// Aggiungere alla query di getSalePrice() o direttamente in apiOttieniPrezzo():
$stRrp = $this->pdo->prepare('
    SELECT pf.rrp_min, pf.rrp_max, pf.unita_misura_acquisto_id,
           MIN(pfp.prezzo) AS costo_unitario
    FROM prodotti_fornitori pf
    LEFT JOIN prodotti_fornitori_prezzi pfp ON pfp.prodotto_fornitore_id = pf.id 
        AND pfp.quantita_minima = 0
    WHERE pf.prodotto_id = ? AND pf.attivo = 1
    ORDER BY pf.ordine_preferenza_fornitore ASC
    LIMIT 1
');
$stRrp->execute([$prodottoId]);
$rrpData = $stRrp->fetch(PDO::FETCH_ASSOC);

// Nel JSON di risposta aggiungere:
'rrp_min'        => $rrpData ? (float)($rrpData['rrp_min'] ?? 0) : null,
'rrp_max'        => $rrpData ? (float)($rrpData['rrp_max'] ?? 0) : null,
'costo_unitario' => $rrpData ? (float)($rrpData['costo_unitario'] ?? 0) : null,
```

**Nota sulla conversione UoM:** se il preventivo è in modalità "unità commerciale" e il RRP è in unità d'acquisto che corrisponde a quella commerciale, nessuna conversione. Se il cliente ha scelto "unità di stock", applicare lo stesso fattore `quantita_contenuto` già usato per `prezzo_unitario`.

#### B3 — Rispettare `can_view_costs` nel JSON

L'API non deve restituire `rrp_min`, `rrp_max`, `costo_unitario` se l'utente loggato ha `can_view_costs = 0`:

```php
$mostraCosti = (bool)($_SESSION['user']['can_view_costs'] ?? false);
if (!$mostraCosti) {
    unset($response['rrp_min'], $response['rrp_max'], $response['costo_unitario']);
}
```

#### B4 — Aggiornare `SalesPricelistsController::apiSaveRiga()` e `apiProdotti()`

La funzione `apiProdotti()` della form listini vendita deve anch'essa restituire `rrp_min` e `rrp_max` per mostrare il range nel modal di inserimento prezzo.

---

### 3.3 Modifiche frontend

#### F1 — Catalogo prodotti nella form preventivi

Il widget "Catalogo Prodotti" (grid di card cliccabili, vedere mockup) deve mostrare per ogni card:

- Costo: valore grezzo da `costo_base` (già nella risposta search)
- Min/Max badge (verde/arancione) da `rrp_min`/`rrp_max`
- Nascondere questi dati se l'utente non ha `can_view_costs`

```js
// Esempio rendering card (aggiunta ai badge esistenti):
if (data.can_view_costs && prod.rrp_min != null) {
  cardHtml += `<span class="badge-rrp-min">Min: €${fmt(prod.rrp_min)}</span>`;
  cardHtml += `<span class="badge-rrp-max">Max: €${fmt(prod.rrp_max)}</span>`;
}
if (data.can_view_costs && prod.costo_base != null) {
  cardHtml += `<span class="badge-costo">Costo: €${fmt(prod.costo_base)}</span>`;
}
```

#### F2 — Colonne aggiuntive nella tabella righe preventivo

Aggiungere le colonne nascoste/mostrabili con il toggle esistente:

```html
<!-- Nuovi th da aggiungere (visibili di default solo se can_view_costs) -->
<th class="col-costo col-extra" data-col="costo">Costo €/<span class="um-label">kg</span></th>
<th class="col-range col-extra" data-col="range">Range consigliato</th>
<th class="col-delta col-extra" data-col="delta">Delta</th>
```

In ogni riga generata via JS aggiungere le corrispondenti `<td>`:

```js
// Nella funzione buildRow() (o equivalente):
const costoTd  = `<td class="col-costo col-extra" data-col="costo">${canViewCosts ? fmtEur(costo) : '—'}</td>`;
const rangeTd  = `<td class="col-range col-extra" data-col="range">
    ${canViewCosts && rrpMin ? 
      `<span class="rrp-range">€${fmt(rrpMin)} → €${fmt(rrpMax)}</span>` : '—'}
</td>`;
const deltaTd  = `<td class="col-delta col-extra" data-col="delta">
    <span class="delta-badge ${deltaClass}">${canViewCosts ? fmtEur(delta) : '—'}</span>
</td>`;
```

Il **delta** per riga è: `imponibile_riga - totale_costo_riga` — già calcolato al salvataggio, deve essere calcolato anche in real-time nel JS per ogni variazione di prezzo/quantità.

#### F3 — Totali con margine in fondo al preventivo

Aggiungere ai box totali (già presenti: Imponibile, IVA, Totale) due nuovi box:

- **Margine €** (verde se > soglia, rosso se < soglia)
- **Margine %** (con semaforo colore: verde > 20%, giallo 10–20%, rosso < 10%)

Queste soglie possono essere inserite in `Impostazioni` (chiave `margine_soglia_verde`, `margine_soglia_giallo`) per renderle configurabili senza toccare il codice.

```js
// In calcTotali():
const margineEuro = imponibile - totaleCosto;
const marginePerc = imponibile > 0 ? (margineEuro / imponibile) * 100 : 0;
const marginClass = marginePerc >= sogliVerde ? 'margine-ok' 
                  : marginePerc >= sogliaGialla ? 'margine-warn' 
                  : 'margine-danger';
```

#### F4 — Toggle visibilità colonne (bottone esistente)

Il meccanismo di toggle colonne già presente nell'app va esteso per includere le nuove colonne `col-costo`, `col-range`, `col-delta`. Lo stato deve essere salvato in `localStorage` per persistere tra sessioni. La preferenza utente su quali colonne mostrare non deve essere salvata nel DB (è preferenza UI, non dato aziendale).

```js
const COL_PREFS_KEY = 'quack_quote_cols_v1';
// Salvo/leggo da localStorage
const savedCols = JSON.parse(localStorage.getItem(COL_PREFS_KEY) || '{"costo":true,"range":true,"delta":true}');
```

#### F5 — Modal riga listino vendita (`_modal_riga.php`)

Aggiungere un box informativo (non editabile) sotto il campo prezzo:

```html
<!-- Da aggiungere in _modal_riga.php dopo il campo prezzo -->
<div id="mr-rrp-hint" class="qk-alert qk-alert-info mt-2 d-none">
  <i class="fa-solid fa-circle-info me-1"></i>
  Range consigliato fornitore: 
  <strong id="mr-rrp-min">—</strong> → <strong id="mr-rrp-max">—</strong>
  <span class="text-muted ms-1" id="mr-rrp-fornitore" style="font-size:.8rem"></span>
</div>
```

Il JS del modal popola questo box quando viene selezionato un prodotto, usando `apiProdotti()` arricchita con i dati RRP.

---

### 3.4 Cosa NON modificare e perché

| Elemento               | Decisione                          | Motivazione                                                                                                                                          |
| ---------------------- | ---------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
| `preventivi_righe`     | NON aggiungere `rrp_min`/`rrp_max` | Il RRP è informativo, non parte del contratto. Il snapshot necessario è `costo_unitario` (già salvato). Stessa logica del flag `Statistical` di SAP. |
| Stampa PDF/DOCX        | NON includere RRP                  | Corretto per design: è dato interno di pricing, non va sul documento cliente.                                                                        |
| `listini_prezzi`       | NON aggiungere campi RRP           | RRP appartiene alla relazione prodotto-fornitore, non al listino di vendita.                                                                         |
| `ordini_clienti_righe` | NON aggiungere RRP                 | L'ordine è generato dal preventivo; il margine è già storicizzato nel preventivo.                                                                    |

---

## 4. Ciclo d'uso previsto per Quack ERP

1. **Inserimento RRP** (Acquisti / Scheda prodotto-fornitore): il buyer o il responsabile acquisti apre la scheda fornitore di un prodotto e inserisce/aggiorna `rrp_min` e `rrp_max` (con le nuove colonne `rrp_valido_dal`/`rrp_valido_al` opzionali). Questo tipicamente avviene quando si riceve il nuovo listino dal fornitore (cadenza mensile o trimestrale per prodotti chimici).

2. **Compilazione preventivo** (Vendite / Nuovo preventivo): l'agente commerciale cerca un prodotto nel catalogo. Il widget catalogo mostra già Costo, Min, Max (visibili solo a chi ha `can_view_costs`). Aggiunge la riga. Nelle colonne della tabella vede il range consigliato e il delta (margine per riga) in real-time mentre modifica il prezzo. Se il delta è rosso, sa che sta vendendo sotto costo o sotto il margine minimo.

3. **Gestione listini vendita** (Vendite / Listini): il pricing manager apre il modal di inserimento prezzo per un prodotto. Vede il range consigliato dal fornitore come riferimento. Imposta il prezzo nel listino di vendita coerentemente.

4. **Audit margini** (Report): i totali Margine € e Margine % sulla testata preventivo permettono una verifica immediata prima dell'invio al cliente.

5. **Aggiornamento stagionale RRP** (Acquisti): quando il fornitore aggiorna il suo listino, il buyer aggiorna `rrp_min`/`rrp_max` con le nuove date di validità. I preventivi precedenti non sono impattati (il RRP non è salvato sulla riga preventivo).

---

## 5. Riepilogo priorità di implementazione

| Priorità       | Intervento                                                                                 | Impatto                                  | Difficoltà |
| -------------- | ------------------------------------------------------------------------------------------ | ---------------------------------------- | ---------- |
| 🔴 Alta        | B1 — Aggiornare `qk_search_prodotti_sales()` con JOIN su `prodotti_fornitori`              | Senza questo nulla funziona nel frontend | Media      |
| 🔴 Alta        | B2 — Aggiornare `apiOttieniPrezzo()` per restituire `rrp_min`, `rrp_max`, `costo_unitario` | Necessario per F2 e F3                   | Bassa      |
| 🔴 Alta        | F1 — Widget catalogo con Costo/Min/Max badge                                               | Visibile immediatamente all'utente       | Bassa      |
| 🟠 Media       | F2 — Colonne Costo/Range/Delta nella tabella righe preventivo                              | Core della feature richiesta             | Media      |
| 🟠 Media       | F3 — Box Margine € e Margine % nei totali preventivo                                       | Molto utile per il commerciale           | Bassa      |
| 🟡 Bassa       | F4 — Toggle persistente colonne in localStorage                                            | UX migliorativa                          | Bassa      |
| 🟡 Bassa       | F5 — Hint RRP nel modal listino vendita                                                    | Utile al pricing manager                 | Bassa      |
| 🟢 Facoltativo | DB Migration A — `rrp_valido_dal`, `rrp_valido_al`, `rrp_valuta`                           | Robustezza a lungo termine               | Bassa      |
| 🟢 Facoltativo | B3 — Guard `can_view_costs` nell'API JSON                                                  | Sicurezza dati commerciali               | Bassa      |

---

## 6. Note specifiche per prodotti ADR/chimici

- Il RRP di un prodotto chimico può variare per fornitore anche a parità di denominazione commerciale (diversi produttori, diverse concentrazioni, diverso gruppo imballaggio ADR → diverso costo di trasporto → diverso RRP consigliato). Il modello attuale (RRP per coppia prodotto-fornitore) gestisce già questo correttamente.
- Per prodotti con `is_adr = true` nelle righe preventivo, sarebbe utile in futuro mostrare un'indicazione che il prezzo include o esclude il supplemento ADR. Questo non è RRP in senso stretto ma è un'informazione correlata che i fornitori chimici spesso specificano nel listino.
- Il campo `rrp_valido_al` diventa particolarmente rilevante per prodotti ADR: le revisioni biennali ADR (es. ADR 2025, ADR 2027) cambiano classificazioni e possono impattare i costi di trasporto e quindi il RRP consigliato dal fornitore.

---

*Fine documento — Quack ERP RRP Analysis v1.0*

=================================================================================

# PARTE: COMPLIANCE-RESTRIZIONI-VENDITA

=================================================================================

# Compliance — Restrizioni di vendita

## Concetto

Alcuni prodotti chimici non possono essere venduti liberamente a chiunque.
Il motivo dipende dalla normativa: precursori di esplosivi (Reg. UE 2019/1148),
precursori di stupefacenti, biocidi con uso regolamentato, ecc.

In Quack ogni **SKU è già un prodotto distinto** (acido solforico 37% e acido
solforico 96% sono due articoli separati). Questo semplifica la gestione: non
serve risalire alla sostanza o al CAS — la restrizione si assegna direttamente
al prodotto.

---

## Perché non usiamo ADR come fonte di verità

ADR classifica i prodotti per il **trasporto** (classe di pericolo, codice ONU,
gruppo imballaggio). Non è pensato per regolare *a chi* si può vendere.

Esempi di disallineamento:

| Prodotto             | ADR        | Compliance vendita          |
| -------------------- | ---------- | --------------------------- |
| H₂O₂ 3%              | nessuno    | nessuna                     |
| H₂O₂ 35%             | classe 5.1 | precursore esplosivi (>12%) |
| Vernice infiammabile | classe 3   | nessuna                     |

ADR e compliance vivono in parallelo sullo stesso prodotto. Si sfiorano nella
tabella `compliance_codice_onu_restrizioni`, che serve solo come **suggerimento**
automatico (vedi sotto), non come fonte di verità.

---

## Struttura DB

### Tabelle principali

```
compliance_restriction_types       — catalogo dei regimi normativi
compliance_documenti_richiesti     — documenti che il cliente deve presentare per ogni regime
compliance_autorizzazioni          — autorizzazione del cliente (cliente × regime × scadenza)
compliance_documenti_caricati      — file allegati all'autorizzazione
compliance_log_transazioni         — ogni vendita di prodotto soggetto a restrizione
prodotti_restrizioni               — FONTE DI VERITÀ: prodotto → regime
```

### Tabella di supporto

```
compliance_codice_onu_restrizioni  — suggerimento: codice ONU → regime (usato solo come hint)
```

### Schema `prodotti_restrizioni`

```sql
CREATE TABLE prodotti_restrizioni (
    prodotto_id         INTEGER NOT NULL REFERENCES prodotti(id) ON DELETE CASCADE,
    restriction_type_id INTEGER NOT NULL REFERENCES compliance_restriction_types(id),
    note                TEXT,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (prodotto_id, restriction_type_id)
);
```

Un prodotto può avere zero o più restrizioni. La relazione è molti-a-molti
(un prodotto può essere sia precursore di esplosivi sia biocida).

---

## Flusso operativo

### 1. Configurazione (una tantum)

In `compliance_restriction_types` sono già presenti i regimi normativi:

| code                  | name                    | regulatory_ref    |
| --------------------- | ----------------------- | ----------------- |
| `EXPLOSIVE_PRECURSOR` | Precursori di esplosivi | Reg. UE 2019/1148 |

Nuovi regimi si aggiungono direttamente in DB o tramite una futura UI di
amministrazione.

Per ogni regime, `compliance_documenti_richiesti` elenca i documenti che
il cliente deve fornire (dichiarazione d'uso, documento d'identità, ecc.).

### 2. Marcatura prodotto (scheda prodotto → tab Compliance)

L'operatore apre un prodotto, va nella tab **Compliance** e aggiunge le
restrizioni applicabili.

Se il prodotto ha una classificazione ADR con un codice ONU mappato in
`compliance_codice_onu_restrizioni`, il sistema mostra un **banner di
suggerimento** ("Dal codice ONU risulta: Precursori di esplosivi — vuoi
aggiungerla?"). L'operatore può applicare il suggerimento con un clic o
ignorarlo.

### 3. Gestione cliente (anagrafica cliente → tab Compliance)

Per ogni cliente che vuole acquistare un prodotto soggetto a restrizione,
si crea una `compliance_autorizzazioni`:

- `restriction_type_id` → quale regime
- `status` → `in_attesa` | `approvata` | `rifiutata` | `scaduta` | `sospesa`
- `issued_date` / `expiry_date` → validità
- File allegati tramite `compliance_documenti_caricati`

### 4. Check al momento della vendita

Quando si inserisce una riga in un preventivo o ordine:

```
Per ogni prodotto nella riga:
    1. Cerca in prodotti_restrizioni se il prodotto ha restrizioni
    2. Se sì → verifica compliance_autorizzazioni del cliente
       - status = 'approvata'
       - expiry_date IS NULL OR expiry_date >= oggi
    3. Se il cliente non ha un'autorizzazione valida → blocco/warning
```

> Il check è ancora da implementare nel controller degli ordini/preventivi.

### 5. Log transazione

Quando la vendita va a buon fine, si inserisce una riga in
`compliance_log_transazioni` con:

- riferimento all'ordine
- prodotto e quantità
- autorizzazione usata
- `reported_to_authority` (da spuntare entro `report_within_days` giorni)

---

## API endpoints (prodotti)

| Metodo | URL                                       | Descrizione                                             |
| ------ | ----------------------------------------- | ------------------------------------------------------- |
| GET    | `/products/compliance/list?prodotto_id=X` | Restrizioni assegnate + tutti i tipi + suggerimenti ADR |
| POST   | `/products/compliance/add`                | Aggiunge una restrizione al prodotto                    |
| POST   | `/products/compliance/remove`             | Rimuove una restrizione dal prodotto                    |

---

## Cosa manca (roadmap)

- [ ] Check automatico al salvataggio di preventivo/ordine
- [ ] Notifica/blocco UI quando il cliente non ha autorizzazione valida
- [ ] Scrittura automatica di `compliance_log_transazioni` alla conferma ordine
- [ ] Alert per autorizzazioni in scadenza (entro N giorni)
- [ ] UI per gestire `compliance_restriction_types` da settings
- [ ] Segnalazione alle autorità (flag `reported_to_authority`)

=================================================================================

# PARTE: SPEDIZIONI E ASSEGNAZIONE LOTTI

=================================================================================

# Gestione Spedizioni e Assegnazione Lotti nel Ciclo Vendite

Questa guida descrive l'architettura tecnica, i casi d'uso e le linee guida di design per l'implementazione del flusso di spedizione merce e generazione dei Documenti di Trasporto (DDT) nel ciclo vendite attivo di Quack ERP.

---

## 1. Casi d'Uso Chiave (Use Cases)

Nel commercio chimico e distributivo, l'evasione di un ordine di vendita può assumere diverse forme operative. Il sistema deve supportare i seguenti scenari senza irrigidire il flusso di lavoro:

### A. Spedizione Completa Lineare

L'ordine viene evaso interamente in un'unica spedizione. 

* Ciascuna riga dell'ordine viene coperta per l'intera quantità ordinata.
* I lotti vengono assegnati automaticamente dal sistema basandosi sulla disponibilità di magazzino.

### B. Spedizione Parziale (Tranche Multiple)

Per motivi di disponibilità scorte o accordi con il cliente, l'ordine viene evaso in tempi diversi.

* L'operatore può indicare, per ogni riga, la quantità effettivamente pronta per la partenza.
* Lo stato dell'ordine transita automaticamente a `parzialmente_spedito`.
* Le spedizioni successive scaleranno le quantità residue attese, fino al completamento totale (stato `spedito`).

### C. Ripartizione Multi-Lotto (Split Lotto)

Se la quantità ordinata per una singola riga supera la giacenza di un singolo lotto fisico, l'operatore deve poter prelevare la merce da lotti differenti.

* Esempio: Ordinati 100 kg. Giacenza Lotto A = 60 kg, Lotto B = 40 kg.
* L'interfaccia deve consentire di inserire due o più righe di assegnazione lotto per la stessa riga d'ordine (es. `Lotto A: 60 kg` e `Lotto B: 40 kg`).

---

## 2. Modello Dati e Flusso Transazionale (Database)

Al momento del salvataggio di una spedizione, il database deve essere aggiornato garantendo l'integrità referenziale sotto un'unica transazione database (`BEGIN TRANSACTION`).

### A. Creazione della Spedizione (`spedizioni` e `spedizioni_righe`)

Viene generato un record nella tabella `spedizioni` (associato all'ID dell'ordine) e le relative righe dettagliate in `spedizioni_righe`. 
Ogni riga di spedizione memorizza obbligatoriamente:

* Il riferimento alla riga dell'ordine (`ordine_riga_id`).
* Il lotto prelevato (`lotto_id`).
* La quantità effettiva spedita.

### B. Generazione del DDT (`documenti_trasporto` e `documenti_trasporto_righe`)

In contemporanea alla spedizione, viene creato il Documento di Trasporto (DDT) per accompagnare fisicamente la merce:

* La testata del DDT denormalizza i dati di spedizione, causale, aspetto dei beni, porto e vettore.
* Le righe del DDT copiano le informazioni ADR denormalizzate relative ai lotti di prodotti chimici pericolosi (ONU, classe, gruppo di imballaggio) per consentire la stampa cartacea conforme alla normativa sui trasporti.

### C. Aggiornamento delle Giacenze a Magazzino

La movimentazione fisica comporta lo scarico immediato delle scorte:

1. **Lotti**: La quantità disponibile del lotto (`quantita_disponibile` in `lotti`) viene decrementata del valore spedito.
2. **Giacenze Generali**: 
   * `quantita_disponibile` del prodotto in `giacenze` viene decrementata.
   * `quantita_riservata` (precedentemente accantonata al momento dell'accettazione dell'ordine) viene decrementata in proporzione alla quantità spedita.
3. **Righe Ordine**: La colonna `quantita_spedita` di `ordini_clienti_righe` viene incrementata della quantità evasa.
4. **Stato dell'Ordine**: Se la quantità totale spedita per tutte le righe eguaglia o supera la quantità ordinata, lo stato dell'ordine diventa `spedito`.

---

## 3. Linee Guida UX (Stile Apple)

Per rendere l'operazione naturale e priva di attrito per l'utente, l'interfaccia deve seguire regole di design precise:

1. **Suggerimento Automatico Smart (Auto-allocation)**:
   
   * All'apertura della schermata di spedizione, il sistema pre-calcola l'assegnazione ottimale dei lotti applicando la logica **FEFO** (First Expired, First Out) per i prodotti che tracciano i lotti.
   * Se i lotti non hanno scadenza, si applica la logica **FIFO** (First In, First Out) basandosi sulla data di creazione del lotto.
   * L'utente deve solo premere "Conferma" se l'assegnazione automatica è corretta.

2. **Interfaccia Visiva Denza e Chiara**:
   
   * Utilizzo di un pannello laterale a scorrimento (Drawer) o di un modal a schermo intero con sfumature morbide e transazioni fluide (senza ricaricare la pagina).
   * Indicatori grafici di progresso per mostrare immediatamente quanta parte di ciascuna riga è già stata spedita in passato e quanta rimane da spedire.

3. **Gestione Errori Preventiva**:
   
   * Il sistema blocca il salvataggio se l'utente tenta di spedire una quantità superiore a quella disponibile nel lotto selezionato.
   * Evidenziazione visiva immediata (soft-red border e testo informativo) in caso di inserimento di quantità non congruenti.
