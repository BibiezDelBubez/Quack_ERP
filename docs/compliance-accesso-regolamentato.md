# Compliance – Accesso Regolamentato alla Vendita
> Modulo Quack ERP · Versione 1.0 · Giugno 2026

---

## 1. Scopo del modulo

Alcuni prodotti chimici che trattiamo non possono essere venduti liberamente a chiunque. La legge impone che il cliente soddisfi determinati requisiti prima di poter acquistare: avere una partita IVA, fornire documenti specifici, ottenere un'autorizzazione interna da parte nostra.

Questo modulo gestisce in modo centralizzato tutte queste situazioni, indipendentemente dal tipo di vincolo normativo. Quando viene creato un ordine o un preventivo, il sistema verifica automaticamente se il cliente è autorizzato a ricevere i prodotti richiesti. Se non lo è, la vendita viene bloccata con un messaggio chiaro.

---

## 2. Casi d'uso attuali e futuri

### 2.1 Precursori di esplosivi — Reg. UE 2019/1148
È il caso principale che ha dato origine a questo modulo.

Certi prodotti chimici, se usati in determinate concentrazioni, possono essere impiegati nella fabbricazione artigianale di esplosivi. Il Regolamento UE 2019/1148 (in vigore dal 1° febbraio 2021) obbliga i rivenditori a verificare l'identità e lo scopo d'uso prima di ogni vendita.

**Prodotti tipicamente coinvolti (esempi):**
- Acido cloridrico (HCl) >10% → UN 1789
- Acido solforico (H₂SO₄) >15% → UN 1830
- Perossido di idrogeno (H₂O₂) >12% → UN 2014/2015
- Nitrato di potassio (KNO₃) >45% → UN 1486

**Obblighi per noi (operatore economico):**
- Vendita solo a soggetti con partita IVA
- Raccolta e conservazione della **dichiarazione d'uso professionale** (modulo firmato, Allegato IV del Regolamento)
- Verifica documento d'identità del referente
- **Registrazione di ogni transazione**, conservata per almeno 18 mesi
- Segnalazione di transazioni sospette all'autorità competente entro 5 giorni

**Validità dell'autorizzazione:** 1 anno dalla data di approvazione. Alla scadenza il cliente deve rinnovare i documenti.

### 2.2 Precursori di stupefacenti — Reg. CE 273/2004 e 111/2005
Alcune sostanze chimiche (es. acido acetico, acetone, permanganato di potassio KMnO₄) possono essere usate nella produzione illecita di droghe. Il regime richiede registrazione degli operatori e documentazione delle forniture.

### 2.3 Biocidi ad uso professionale — Reg. UE 528/2012
Alcuni biocidi (disinfettanti, rodenticidi, ecc.) sono classificati per uso esclusivamente professionale. La vendita è consentita solo a operatori con qualifica o licenza adeguata.

### 2.4 Fitofarmaci — D.Lgs. 150/2012
Certi prodotti fitosanitari richiedono che l'acquirente sia in possesso del "patentino" (abilitazione all'acquisto e all'uso).

### 2.5 Beni dual-use — Reg. UE 2021/821
Alcune sostanze chimiche sono soggette a controlli sull'esportazione per ragioni di sicurezza internazionale. Richiedono autorizzazioni specifiche per la fornitura a certi paesi o categorie di clienti.

---

## 3. Architettura del sistema

### 3.1 Principio fondamentale

Il modulo Compliance è **separato** dalla logica di vendita e magazzino. I moduli di vendita (preventivi, ordini) non conoscono le regole normative: chiedono semplicemente al modulo Compliance *"posso vendere questi prodotti a questo cliente?"* e ricevono sì oppure no.

Questo approccio permette di aggiungere nuovi regimi normativi senza toccare il codice di vendita.

```
Ordine/Preventivo
       │
       ▼
checkSaleAllowed(cliente, prodotti)   ← unica funzione pubblica del modulo
       │
       ├── Esito: OK → la vendita procede normalmente
       │
       └── Esito: BLOCCATA → elenco delle autorizzazioni mancanti o scadute
```

### 3.2 Come si determinano le restrizioni di un prodotto

Le restrizioni **non** sono collegate direttamente ai prodotti, ma ai **codici ONU** (`adr_codici_onu`), con eventuale soglia di concentrazione. I prodotti ereditano le restrizioni tramite la classificazione ADR già esistente in `prodotto_classificazioni_adr`.

```
prodotti
   └──> prodotto_classificazioni_adr  (ha concentrazione_min/max_pct e codice_onu_id)
                └──> adr_codici_onu
                          └──> compliance_codice_onu_restrizioni  (ha soglia concentrazione)
                                    └──> compliance_restriction_types
```

**Esempio pratico:**
Il prodotto "pH Minus Liquido 50%" è classificato ADR con codice ONU UN 1789 (HCl) e concentrazione 50%. La tabella `compliance_codice_onu_restrizioni` dice che UN 1789 con concentrazione ≥10% è un precursore di esplosivo. Il sistema rileva automaticamente la restrizione senza configurazione aggiuntiva sul prodotto.

**Vantaggio:** Aggiungendo una nuova taglia del prodotto (es. "pH Minus 50% – Fusto 200L") e classificandola normalmente come ADR, la restrizione viene ereditata in automatico. Zero configurazione manuale.

---

## 4. Schema del database

### 4.1 Tipi di restrizione
**Tabella: `compliance_restriction_types`**

| Campo (IT) | Campo (EN) | Tipo | Note |
|---|---|---|---|
| id | id | INTEGER PK | |
| codice | code | VARCHAR(30) UNIQUE | Es. `EXPLOSIVE_PRECURSOR`, `DRUG_PRECURSOR` |
| nome | name | TEXT | Nome leggibile per l'interfaccia |
| riferimento_normativo | regulatory_ref | TEXT | Es. "Reg. UE 2019/1148" |
| richiede_piva | requires_vat | BOOLEAN | Se true, il cliente deve avere P.IVA |
| validita_giorni | validity_days | INTEGER | Durata autorizzazione in giorni (es. 365) |
| log_transazioni | log_transactions | BOOLEAN | Se true, ogni vendita viene registrata |
| segnalazione_entro_giorni | report_within_days | INTEGER | Giorni entro cui segnalare all'autorità; NULL = non richiesto |
| attivo | active | BOOLEAN | Permette di disattivare un regime senza cancellarlo |

### 4.2 Restrizioni per codice ONU
**Tabella: `compliance_codice_onu_restrizioni`**

Collega un codice ONU a un tipo di restrizione, specificando la soglia di concentrazione che attiva la restrizione.

| Campo (IT) | Campo (EN) | Tipo | Note |
|---|---|---|---|
| codice_onu_id | un_code_id | INTEGER FK → adr_codici_onu | |
| restriction_type_id | restriction_type_id | INTEGER FK → compliance_restriction_types | |
| concentrazione_min | min_concentration | DECIMAL(5,2) | % minima che attiva la restrizione; NULL = nessun minimo |
| concentrazione_max | max_concentration | DECIMAL(5,2) | % massima; NULL = nessun massimo |
| note | notes | TEXT | |

*Chiave primaria composta: `(codice_onu_id, restriction_type_id)`*

### 4.3 Documenti richiesti per tipo di restrizione
**Tabella: `compliance_documenti_richiesti`**

Definisce quali documenti devono essere caricati per completare una certa autorizzazione.

| Campo (IT) | Campo (EN) | Tipo | Note |
|---|---|---|---|
| id | id | INTEGER PK | |
| restriction_type_id | restriction_type_id | INTEGER FK | |
| codice_documento | document_code | VARCHAR(50) | Es. `MODULO_DICHIARAZIONE`, `DOCUMENTO_IDENTITA` |
| nome_documento | document_name | TEXT | Es. "Dichiarazione d'uso professionale (Allegato IV)" |
| obbligatorio | mandatory | BOOLEAN | Se false, è facoltativo |
| istruzioni | instructions | TEXT | Testo di aiuto mostrato all'utente durante il caricamento |

### 4.4 Autorizzazioni cliente
**Tabella: `compliance_autorizzazioni`**

Registra il fatto che un cliente è stato autorizzato (o è in attesa, o è stato rifiutato) per un certo tipo di restrizione.

| Campo (IT) | Campo (EN) | Tipo | Note |
|---|---|---|---|
| id | id | INTEGER PK | |
| cliente_id | customer_id | INTEGER FK → clienti_fornitori | |
| restriction_type_id | restriction_type_id | INTEGER FK | |
| stato | status | TEXT | `in_attesa`, `approvata`, `rifiutata`, `scaduta`, `sospesa` |
| data_emissione | issued_date | DATE | Data di approvazione |
| data_scadenza | expiry_date | DATE | Calcolata: data_emissione + validita_giorni |
| approvata_da | approved_by | INTEGER FK → utenti | Utente interno che ha approvato |
| note_interne | internal_notes | TEXT | Visibili solo all'operatore |
| created_at | created_at | DATETIME | |
| updated_at | updated_at | DATETIME | |

**Regola:** un cliente può avere una sola autorizzazione attiva per tipo di restrizione. I rinnovi creano un nuovo record e il precedente viene marcato `scaduta`.

### 4.5 Documenti caricati
**Tabella: `compliance_documenti_caricati`**

Registra i singoli file caricati dal cliente (o inseriti manualmente dall'operatore) a supporto di un'autorizzazione.

| Campo (IT) | Campo (EN) | Tipo | Note |
|---|---|---|---|
| id | id | INTEGER PK | |
| autorizzazione_id | authorization_id | INTEGER FK → compliance_autorizzazioni | |
| documento_richiesto_id | required_doc_id | INTEGER FK → compliance_documenti_richiesti | |
| percorso_file | file_path | TEXT | Percorso fisico del file caricato |
| nome_originale | original_filename | TEXT | Nome del file come caricato dall'utente |
| caricato_il | uploaded_at | DATETIME | |
| verificato | verified | BOOLEAN | L'operatore ha confermato che il documento è valido |
| verificato_da | verified_by | INTEGER FK → utenti | |
| verificato_il | verified_at | DATETIME | |

### 4.6 Log transazioni
**Tabella: `compliance_log_transazioni`**

Registro obbligatorio di ogni vendita di prodotti soggetti a restrizione. Per i precursori di esplosivi, la legge impone la conservazione per almeno 18 mesi.

| Campo (IT) | Campo (EN) | Tipo | Note |
|---|---|---|---|
| id | id | INTEGER PK | |
| vendita_id | sale_id | INTEGER FK → ordini_clienti | |
| autorizzazione_id | authorization_id | INTEGER FK → compliance_autorizzazioni | |
| restriction_type_id | restriction_type_id | INTEGER FK | Denormalizzato per comodità di query |
| prodotto_id | product_id | INTEGER FK → prodotti | |
| quantita | quantity | DECIMAL(10,3) | |
| unita_misura_id | unit_id | INTEGER FK → unita_misura | |
| data_transazione | transaction_date | DATE | |
| segnalata_autorita | reported_to_authority | BOOLEAN | Se richiesta segnalazione: è stata inviata? |
| segnalata_il | reported_at | DATETIME | |
| riferimento_segnalazione | report_reference | TEXT | Numero protocollo o riferimento segnalazione |
| created_at | created_at | DATETIME | |

---

## 5. Logica applicativa

### 5.1 Verifica vendita

Prima di confermare un preventivo o un ordine, il backend esegue questo controllo:

```
Per ogni prodotto nell'ordine:
  1. Cerca le classificazioni ADR attive del prodotto (e_corrente = 1)
  2. Per ogni classificazione, cerca restrizioni applicate al codice ONU
     dove la concentrazione del prodotto rientra nel range della restrizione
  3. Per ogni restrizione trovata:
     a. Verifica che il cliente abbia P.IVA (se richiesta_piva = true)
     b. Cerca un'autorizzazione con stato = 'approvata' e data_scadenza >= oggi
     c. Se non trovata → aggiunge la violazione all'elenco

Se l'elenco violazioni è vuoto → vendita consentita
Se non è vuoto → vendita bloccata, elenco violazioni restituito al frontend
```

### 5.2 Registrazione transazione

Dopo la conferma dell'ordine, per ogni riga che riguarda un prodotto con restrizione attiva, viene scritto automaticamente un record in `compliance_log_transazioni`.

### 5.3 Scadenze e alert

Un job giornaliero controlla:
- Autorizzazioni con `data_scadenza` nei prossimi 30 giorni → notifica interna all'operatore, avviso visualizzato nell'anagrafica cliente
- Autorizzazioni già scadute con ordini attivi associati → alert urgente
- Transazioni registrate per regimi con `segnalazione_entro_giorni` valorizzato, dove `segnalata_autorita = false` e sono passati più giorni del limite → alert per operatore

---

## 6. Interfaccia utente

### 6.1 Anagrafica cliente
Nella scheda del cliente è presente una tab **"Compliance"** che mostra:
- Elenco di tutti i tipi di restrizione rilevanti
- Per ognuno: stato dell'autorizzazione con badge colorato (🟢 Attiva / 🟡 In scadenza / 🔴 Scaduta / 🔵 In attesa / ⚪ Non presente)
- Data di scadenza
- Elenco dei documenti caricati con stato di verifica
- Bottone "Nuova autorizzazione" per avviare il processo
- Bottone "Approva" visibile solo agli utenti con ruolo admin o commerciale

### 6.2 Flusso di autorizzazione
1. L'operatore apre la scheda cliente → tab Compliance
2. Clicca "Nuova autorizzazione" per il tipo di restrizione desiderato
3. Il sistema mostra i documenti richiesti e il form di upload
4. L'operatore carica i documenti ricevuti dal cliente
5. Verifica i documenti (spunta "documento valido" su ognuno)
6. Clicca "Approva" → il sistema calcola la data di scadenza e salva
7. Da questo momento il cliente può acquistare i prodotti soggetti a quella restrizione

### 6.3 Flusso di vendita (preventivo / ordine)
- Quando si aggiunge un prodotto con restrizioni a un preventivo/ordine, appare immediatamente un indicatore visivo
- Se il cliente non è autorizzato: **warning in arancione** con descrizione del problema
- Il preventivo può essere salvato in bozza anche senza autorizzazione
- La **conferma dell'ordine è bloccata** se esistono restrizioni non soddisfatte
- Il messaggio di blocco indica: quale prodotto, quale restrizione, e un link diretto alla gestione autorizzazioni del cliente

### 6.4 Dashboard operativa
Nella home o in un pannello dedicato:
- **Widget "Autorizzazioni in scadenza"**: clienti la cui autorizzazione scade nei prossimi 30 giorni, ordinati per urgenza
- **Widget "In attesa di approvazione"**: autorizzazioni nello stato `in_attesa` da approvare
- **Widget "Transazioni da segnalare"**: transazioni che richiedono notifica all'autorità entro il termine di legge

---

## 7. Flusso operativo — guida pratica per l'operatore

### Nuovo cliente vuole acquistare un precursore di esplosivo

1. Verificare che il cliente abbia P.IVA in anagrafica
2. Richiedere al cliente:
   - Modulo di dichiarazione d'uso professionale firmato (Allegato IV Reg. UE 2019/1148)
   - Copia documento d'identità del responsabile firmatario
3. Aprire la scheda cliente → tab Compliance
4. Cliccare "Nuova autorizzazione" → selezionare "Precursori di esplosivi"
5. Caricare i documenti ricevuti
6. Verificare ogni documento e approvare
7. L'autorizzazione è valida per 12 mesi

### Rinnovo autorizzazione scaduta o in scadenza

1. Il sistema notifica con anticipo (30 giorni prima della scadenza)
2. Contattare il cliente per richiedere nuovo modulo firmato e documento d'identità aggiornato
3. Aprire la scheda cliente → tab Compliance → cliccare "Rinnova"
4. Procedere come per una nuova autorizzazione
5. Il vecchio record viene marcato come `scaduta`, il nuovo come `approvata`

### Ordine bloccato per mancanza di autorizzazione

1. Il sistema mostra il motivo del blocco nell'ordine/preventivo
2. Aprire la scheda cliente e risolvere la situazione (nuova autorizzazione o rinnovo)
3. Una volta approvata l'autorizzazione, tornare all'ordine e confermarlo

---

## 8. Query di riferimento

### Trovare le restrizioni attive per un prodotto
```sql
SELECT r.codice, r.nome, r.richiede_piva, r.validita_giorni,
       cr.concentrazione_min, cr.concentrazione_max
FROM compliance_restriction_types r
JOIN compliance_codice_onu_restrizioni cr ON cr.restriction_type_id = r.id
JOIN prodotto_classificazioni_adr pca ON pca.codice_onu_id = cr.codice_onu_id
WHERE pca.prodotto_id = :prodotto_id
  AND pca.e_corrente = 1
  AND r.attivo = 1
  AND (cr.concentrazione_min IS NULL OR pca.concentrazione_max_pct >= cr.concentrazione_min)
  AND (cr.concentrazione_max IS NULL OR pca.concentrazione_min_pct <= cr.concentrazione_max);
```

### Verificare se un cliente è autorizzato per una certa restrizione
```sql
SELECT * FROM compliance_autorizzazioni
WHERE cliente_id = :cliente_id
  AND restriction_type_id = :restriction_type_id
  AND stato = 'approvata'
  AND date(data_scadenza) >= date('now');
```

### Autorizzazioni in scadenza nei prossimi 30 giorni
```sql
SELECT cf.ragione_sociale, cf.partita_iva,
       r.nome AS restrizione,
       a.data_scadenza,
       julianday(a.data_scadenza) - julianday('now') AS giorni_alla_scadenza
FROM compliance_autorizzazioni a
JOIN compliance_restriction_types r ON r.id = a.restriction_type_id
JOIN clienti_fornitori cf ON cf.id = a.cliente_id
WHERE a.stato = 'approvata'
  AND date(a.data_scadenza) BETWEEN date('now') AND date('now', '+30 days')
ORDER BY a.data_scadenza ASC;
```

### Transazioni da segnalare all'autorità
```sql
SELECT lt.*, cf.ragione_sociale, p.nome AS prodotto, r.nome AS restrizione,
       r.segnalazione_entro_giorni,
       julianday('now') - julianday(lt.data_transazione) AS giorni_trascorsi
FROM compliance_log_transazioni lt
JOIN compliance_autorizzazioni a ON a.id = lt.autorizzazione_id
JOIN compliance_restriction_types r ON r.id = lt.restriction_type_id
JOIN clienti_fornitori cf ON cf.id = a.cliente_id
JOIN prodotti p ON p.id = lt.prodotto_id
WHERE lt.segnalata_autorita = 0
  AND r.segnalazione_entro_giorni IS NOT NULL
  AND julianday('now') - julianday(lt.data_transazione) >= r.segnalazione_entro_giorni;
```

---

## 9. Dati di configurazione iniziale (seed)

```sql
-- Tipo restrizione: Precursori di esplosivi
INSERT INTO compliance_restriction_types
  (codice, nome, riferimento_normativo, richiede_piva, validita_giorni, log_transazioni, segnalazione_entro_giorni)
VALUES
  ('EXPLOSIVE_PRECURSOR', 'Precursori di esplosivi', 'Reg. UE 2019/1148', 1, 365, 1, 5);

-- Documenti richiesti
INSERT INTO compliance_documenti_richiesti (restriction_type_id, codice_documento, nome_documento, obbligatorio, istruzioni)
SELECT id, 'DICHIARAZIONE_USO', 'Dichiarazione d''uso professionale (Allegato IV)', 1,
       'Modulo firmato dal responsabile aziendale. Scaricare il modello dalla cartella documenti.'
FROM compliance_restriction_types WHERE codice = 'EXPLOSIVE_PRECURSOR';

INSERT INTO compliance_documenti_richiesti (restriction_type_id, codice_documento, nome_documento, obbligatorio, istruzioni)
SELECT id, 'DOCUMENTO_IDENTITA', 'Documento d''identità del firmatario', 1,
       'Copia fronte/retro di un documento d''identità in corso di validità del responsabile che ha firmato la dichiarazione.'
FROM compliance_restriction_types WHERE codice = 'EXPLOSIVE_PRECURSOR';

-- Codici ONU soggetti a restrizione esplosivi (esempi principali)
-- UN 1789 – Acido cloridrico, soluzione (HCl >10%)
INSERT INTO compliance_codice_onu_restrizioni (codice_onu_id, restriction_type_id, concentrazione_min, note)
SELECT onu.id, r.id, 10.0, 'HCl >10% – Reg. UE 2019/1148 Allegato I'
FROM adr_codici_onu onu, compliance_restriction_types r
WHERE onu.codice_onu = 1789 AND r.codice = 'EXPLOSIVE_PRECURSOR';
```

---

## 10. Note di sviluppo

- Le tabelle compliance seguono le convenzioni di naming del resto del DB (snake_case, FK con suffisso `_id`)
- I percorsi dei file caricati (`percorso_file`) seguono la struttura `/uploads/compliance/{autorizzazione_id}/{nome_file}`
- La verifica `checkSaleAllowed` deve essere eseguita lato backend, non solo frontend, per prevenire aggiramenti
- Quando `segnalazione_entro_giorni` è valorizzato, il job giornaliero genera un alert nel pannello operativo; la segnalazione effettiva all'autorità è operazione manuale (l'ERP non invia automaticamente alle autorità)
- La conservazione del log transazioni per 18 mesi è un obbligo di legge: non prevedere cancellazione automatica dei record in `compliance_log_transazioni`
