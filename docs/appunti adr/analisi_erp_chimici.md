# Analisi ERP Chimici — Stato dell'Arte vs. Quack ERP
**Data:** Giugno 2026 | **Versione DB:** Schema v4.2 | **Autore:** Analisi automatica Quack

---

## Indice

1. [Stato dell'arte — ERP chimici best-in-class](#1-stato-dellarte--erp-chimici-best-in-class)
2. [Analisi del progetto Quack](#2-analisi-del-progetto-quack)
3. [Gap analysis per area](#3-gap-analysis-per-area)
4. [Roadmap raccomandata](#4-roadmap-raccomandata)

---

## 1. Stato dell'arte — ERP chimici best-in-class

### 1.1 I player di riferimento

I tre punti di riferimento assoluti per la gestione ERP di prodotti chimici sono **SAP S/4HANA con modulo EH&S** (Environment, Health & Safety), **Oracle ERP Cloud** con il modulo Product Hub e **Infor M3 Chemical Edition**. Per le PMI specializzate esistono soluzioni come **Sage X3 Chemical**, **Microsoft Dynamics 365 + SpecRight** e soluzioni verticali come **Datacor ERP** (specifico chimico). La piattaforma open-source più avanzata è **Odoo** con le estensioni di compliance.

### 1.2 Gestione Master Data Prodotto

I sistemi best-in-class distinguono nettamente tra:

- **Prodotto master** (formula chimica, CAS, proprietà fisico-chimiche intrinseche)
- **Prodotto variante / SKU** (concentrazione specifica, formato di vendita, packaging)
- **Sostanza pericolosa** (entità ONU/CLP, indipendente dal prodotto commerciale)

La separazione a tre livelli è considerata fondamentale perché una stessa sostanza (es. Acido Cloridrico, CAS 7647-01-0) può comparire in decine di SKU a concentrazioni diverse, ognuno con le proprie soglie ADR e classificazioni CLP. SAP gestisce questo attraverso la specifica `LS_UN_SUB` (UN listed substance), distinta dal materiale commerciale.

**Campi ritenuti essenziali a livello prodotto nei sistemi top:**

- Codice CAS, EINECS/ELINCS, REACH Registration Number
- Codice UFI (Unique Formula Identifier, obbligatorio per PCN dal 2025)
- Classificazione CLP/GHS completa (categorie di pericolo + indicazioni H, consigli P)
- Classificazione ADR (UN, classe, gruppo imballaggio, categoria trasporto, etichette)
- Classificazione IATA/IMDG (per spedizioni aeree/marittime, spesso i chimici esportano)
- Punto di infiammabilità, densità, viscosità, pH (rilevanti per verifica imballaggio)
- Stato fisico (solido/liquido/gas) — influenza regole ADR
- Concentrazione percentuale (range o valore fisso)
- Flag mercé ad alto rischio (MARD / High Consequence Dangerous Goods)
- Flag inquinante marino (MP)
- Quantità limitata (QL) e quantità esente (QE) per regime esenzione ADR 3.4/3.5
- Istruzioni di imballaggio ADR (P-instruction, IBC-instruction, T-code per cisterne)

### 1.3 Gestione ADR nei sistemi best-in-class

SAP EH&S organizza i dati ADR in un **property tree** a più livelli:

```
Regolamento (ADR/RID/IMDG/IATA)
  └── Sostanza UN (LS_UN_SUB)
        ├── Numero ONU
        ├── Nome di spedizione appropriato (PSN)
        ├── Classe + divisione
        ├── Gruppo di imballaggio
        ├── Categorie di pericolo secondarie
        ├── Codice di classificazione
        ├── Etichette (primaria + sussidiarie)
        ├── Categoria di trasporto + Quantità limitata
        ├── Codice quantità esente (E0–E5)
        ├── Codice restrizione gallerie
        ├── MARD flag
        ├── Marine Pollutant flag
        └── Istruzioni imballaggio (P001, P650, etc.)
```

Il punto critico nei sistemi maturi è la **gestione multi-regolamento simultanea**: lo stesso prodotto può viaggiare su strada (ADR), per ferrovia (RID), per mare (IMDG) o per aereo (IATA). Ogni modalità ha regole diverse, anche per lo stesso numero ONU. SAP distingue esplicitamente i quattro regolamenti e calcola automaticamente quale si applica in base alla modalità di trasporto della spedizione.

Un altro elemento fondamentale nei sistemi avanzati è il **calcolo automatico del regime esenzione 1.1.3.6 ADR** (regola dei 1000 punti), che determina se una spedizione mista può essere esentata dalla documentazione ADR completa. Il calcolo richiede: quantità di ogni prodotto × moltiplicatore categoria trasporto, sommato, confrontato con 1000.

La **gestione delle istruzioni scritte al conducente** (ADR 5.4.3) nei sistemi enterprise è automatizzata: il sistema genera automaticamente il foglio istruzioni in base alle classi di pericolo dei prodotti sulla spedizione.

### 1.4 Schede di Sicurezza (SDS/SDH) — gestione avanzata

Nei sistemi top la SDS non è un semplice allegato PDF ma un **documento strutturato** con 16 sezioni codificate (GHS/REACH). Le best practice prevedono:

- **Generazione automatica** della SDS dai dati di classificazione CLP/GHS inseriti nel sistema
- **Versioning** con major/minor version, data di emissione, data di scadenza
- **Multi-lingua** con workflow di traduzione e approvazione
- **Notifica automatica ai clienti** quando esce una nuova versione della SDS di un prodotto che hanno acquistato nei 12 mesi precedenti (obbligo REACH Art. 31)
- **Distribuzione elettronica tracciata** (sapere chi ha ricevuto quale versione)
- **PCN (Poison Centre Notification)**: la SDS è strettamente collegata alla notifica ai centri antiveleni, obbligatoria dal 2025 per miscele con UFI
- Collegamento con **eEPC (extended Safety Data Sheet)** per sostanze SVHC REACH

Soluzioni specializzate come Chemius, Lisam EHS, Sphera offrono la generazione automatica SDS a 16 sezioni come prodotto standalone che si integra nell'ERP.

### 1.5 Compliance e restrizioni — gestione normativa

I sistemi maturi gestiscono una matrice di conformità che copre:

**Precursori di esplosivi** (Reg. UE 2019/1148, ex 98/2013):
- Verifica P.IVA acquirente professionale
- Dichiarazione d'uso firmata con identificazione
- Log delle transazioni con reporting alle autorità competenti entro 5 giorni per operazioni sospette
- Concentrazioni soglia che modificano l'obbligo (es. H₂O₂ > 12%, KNO₃ > 16%)

**Precursori di stupefacenti** (Reg. UE 2019/515, ex D.Lgs 50/1993 per l'Italia):
- Categorie 1, 2, 3 (diversi livelli di restrizione)
- Licenze operatore per categoria 1
- Autorizzazioni per categoria 2
- Registrazione movimenti per tutte le categorie
- Reporting trimestrale all'UICPGD

**Biocidi** (Reg. UE 528/2012):
- Verifica che il prodotto biocida sia autorizzato per il tipo di uso dichiarato
- Verifiche sull'acquirente (autorizzazione per biocidi professionali cat. 1 e 2)

**REACH/CLP**:
- SVHC (Substances of Very High Concern) — obbligo comunicazione >0,1%
- Restrizioni Allegato XVII REACH (es. benzene, CrVI)
- Autorizzazioni Allegato XIV (sostanze soggette ad autorizzazione)

I sistemi enterprise gestiscono queste restrizioni con un **motore regole** che a ogni ordine esegue check automatici e blocca o avverte l'operatore. Il log delle transazioni è persistente e esportabile per audit normativi.

### 1.6 Documentazione di trasporto ADR — standard di mercato

Un ERP chimico maturo genera automaticamente:

1. **DDT con sezione merci pericolose**: descrizione in linguaggio ADR standard ("UN XXXX, Nome di spedizione appropriato, classe X.X, gruppo imballaggio II, (x), quantità")
2. **Documento di trasporto merci pericolose** (distinto dal DDT): il documento formale ADR 5.4.1 con tutte le informazioni richieste dall'accordo
3. **Istruzioni scritte al conducente** (ADR 5.4.3): foglio A4 con istruzioni per ogni classe di pericolo presente
4. **Etichette e pannelli arancio**: stampa delle etichette di pericolo e numero Kemler/ONU per pannelli arancio
5. **Calcolo carico massimo**: verifica che il totale per categoria non superi le soglie

Per spedizioni internazionali i sistemi avanzati generano anche la **CMR (lettera di vettura internazionale)** con le sezioni ADR precompilate.

### 1.7 Magazzino chimico — requisiti specifici

I sistemi specializzati gestiscono:
- **Segregazione merci incompatibili**: prevenire lo stoccaggio di sostanze che non devono stare insieme (es. acidi + basi, ossidanti + infiammabili). Matrici di compatibilità codificate per classe ADR
- **Location Management**: ubicazioni fisiche con classe ADR ammessa, temperatura massima, requisiti ventilazione
- **FEFO** (First Expired First Out) obbligatorio per prodotti con scadenza
- **Alerting automatico**: scadenze, SDS da aggiornare, scorte di sicurezza
- **Gestione rifiuti pericolosi**: codici CER, formulario FIR/MUD

---

## 2. Analisi del progetto Quack

### 2.1 Panoramica dell'architettura

**Stack tecnologico:**
- Backend: PHP 8 con pattern MVC (Controller → PDO → View)
- Database: SQLite 3 (WAL mode, FK ON) — migrazione MySQL pianificata
- Frontend: PHP server-side rendering + JavaScript per modali/AJAX
- Generazione PDF: mPDF
- Integrazione: WooCommerce REST API
- Schema: v4.2, **76 tabelle**

**Struttura macro:**
```
Acquisto: rfq_testata → offerte_testata → ordini_testata → ricezioni_testata
Magazzino: giacenze + movimenti_magazzino + lotti + inventari
Vendita: preventivi → ordini_clienti → spedizioni → documenti_trasporto → fatture
ADR: adr_codici_onu + adr_classi_pericolo + adr_gruppi_imballaggio + [6 altre lookup]
Prodotto: prodotti + sostanze_adr + prodotto_classificazioni_adr + schede_sicurezza
Compliance: compliance_restriction_types + compliance_autorizzazioni + compliance_log_transazioni
```

### 2.2 Punti di forza evidenti

**Modello ADR solido:**
Il progetto ha implementato l'intera Tabella A ADR con struttura relazionale corretta: `adr_codici_onu` con FK verso classi, gruppi imballaggio, categorie trasporto, restrizioni gallerie, quantità esenti. C'è il calcolatore 1.1.3.6 (regola 1000 punti). Le etichette ADR sono collegate sia alle classi che ai singoli codici ONU (primaria + sussidiaria).

**Tier a 3 livelli per ADR** (introdotto con migrate_adr_sostanze.php):
La scelta di separare `adr_codici_onu` → `sostanze_adr` → `prodotti` è esattamente allineata con il modello SAP/Oracle. La sostanza ADR tiene il CAS, il codice ONU e le proprietà fisico-chimiche; il prodotto è il livello commerciale/SKU. Questo è il pattern corretto.

**Compliance con workflow autorizzativo:**
`compliance_restriction_types` + `compliance_autorizzazioni` + `compliance_documenti_caricati` + `compliance_log_transazioni` è un sistema completo e corretto. Il check al momento della vendita (`qk_compliance_check_sale`) è integrato nel flusso dei preventivi. Il seed per precursori esplosivi (Reg. UE 2019/1148) è corretto.

**DDT con info ADR denormalizzate:**
La scelta di denormalizzare `numero_onu`, `classe_adr`, `packing_group` nelle righe DDT è corretta per l'integrità storica del documento (anche se il prodotto cambia classificazione, il DDT emesso rimane valido).

**Ciclo acquisti a 3 fasi** (RFQ → Offerta → Ordine): allineato con SAP/Oracle. Gestione versioning RFQ (rfq_padre_id), rifiuto riga, confronto offerte multiple. È un'implementazione matura.

**Lotti con doppio codice** (fornitore + interno): corretto per la tracciabilità. La UNIQUE su (codice_lotto_fornitore, prodotto_id, fornitore_id) gestisce il caso in cui due fornitori usino lo stesso numero lotto.

**SDS per sostanza ADR multi-lingua** (sostanze_adr_schede): gestione per lingua con versioning e flag e_corrente. L'upload/download è funzionale.

**Incoterms e metodi pagamento**: lookup tabelle pulite, allineate con standard internazionali.

### 2.3 Gap rispetto allo stato dell'arte

Vedi sezione 3 per analisi dettagliata. In sintesi i gap principali sono:

1. SDS strutturata a 16 sezioni (ora solo PDF uploadato)
2. Classificazione CLP/GHS mancante (H/P statements, pittogrammi GHS)
3. Codice UFI mancante (obbligatorio PCN 2025)
4. Documento di trasporto ADR formale (5.4.1) separato dal DDT
5. Istruzioni scritte al conducente (ADR 5.4.3) non generate
6. Multi-regolamento (ADR/IMDG/IATA) non gestito
7. Precursori di stupefacenti non implementati
8. Biocidi non implementati
9. Segregazione merci incompatibili in magazzino
10. Location management magazzino

---

## 3. Gap analysis per area

### 3.1 Master Data Prodotto — GAP MEDIO

| Feature | Best-in-class | Quack attuale | Gap |
|---------|--------------|---------------|-----|
| Codice CAS | Presente | Presente (su sostanze_adr) | ✅ OK |
| Codice ONU | Presente | Presente (adr_codici_onu) | ✅ OK |
| Gruppo imballaggio | Presente + override per prodotto | Presente + override via prodotto_classificazioni_adr | ✅ OK |
| Punto infiammabilità | Presente | Presente (su prodotto_classificazioni_adr) | ✅ OK |
| Stato fisico | Campo distinto | Ricavabile da nome_descrizione | ⚠️ MIGLIORABILE |
| Classificazione CLP/GHS | Completa (H statements, P statements, pittogrammi) | **Assente** | ❌ GAP CRITICO |
| Codice UFI | Obbligatorio da 2025 | **Assente** | ❌ GAP CRITICO |
| REACH Reg. number | Presente | **Assente** | ⚠️ GAP MEDIO |
| EINECS/ELINCS | Presente | **Assente** | ⚠️ GAP MEDIO |
| Istruzioni imballaggio ADR (P-code) | Gestite | **Assente** | ⚠️ GAP MEDIO |
| Codice cisterna ADR (T-code) | Gestito | **Assente** | ⚠️ GAP MINORE |
| Multi-regolamento (ADR/RID/IMDG/IATA) | Gestito | Solo ADR | ⚠️ GAP MEDIO |
| Flag MARD | Presente | Presente (e_merce_alto_rischio) | ✅ OK |
| Flag inquinante marino | Presente | Presente (e_inquinante_marino) | ✅ OK |
| Numero Kemler | Gestito | **Assente** | ⚠️ GAP MEDIO |
| Concentrazione soglia per restrizione | Gestita (compliance_codice_onu_restrizioni) | Presente | ✅ OK |
| Prodotto master vs variante | Architettura a 3 livelli | Architettura a 3 livelli (prodotti = variant) | ✅ OK come design attuale |

**Azione raccomandata:**
Aggiungere alla tabella `sostanze_adr` (o creare `sostanze_clp`):
```sql
-- Aggiunta alla sostanze_adr o nuova tabella collegata
codice_ufi          VARCHAR(20),     -- Unique Formula Identifier
codice_reach        VARCHAR(30),     -- REACH Registration Number  
codice_einecs       VARCHAR(10),     -- EINECS/ELINCS
numero_kemler       VARCHAR(4),      -- Numero di pericolo (pannello arancio)
stato_fisico        TEXT CHECK(stato_fisico IN ('solido','liquido','gas','aerosol')),
-- Classificazione CLP
clp_classi_pericolo TEXT,            -- JSON array: ["Flam. Liq. 2","Acute Tox. 4"]
clp_h_statements    TEXT,            -- JSON array: ["H225","H302","H319"]
clp_p_statements    TEXT,            -- JSON array: ["P210","P264","P270"]
clp_pittogrammi     TEXT,            -- JSON array: ["GHS02","GHS07"]
clp_avvertenza      TEXT CHECK(... IN ('Pericolo','Attenzione')),
-- ADR istruzioni
p_instruction       VARCHAR(20),     -- Istruzione imballaggio (es. P001)
ibc_instruction     VARCHAR(20),     -- Istruzione IBC
t_code              VARCHAR(5),      -- Codice cisterna
tp_code             VARCHAR(10)      -- Codice disposizione speciale cisterna
```

### 3.2 Schede di Sicurezza (SDS) — GAP CRITICO

**Situazione attuale:**
Quack gestisce le SDS come **file PDF allegati** a una sostanza ADR, con gestione lingua e versione. È funzionale ma non strutturata. La tabella `schede_sicurezza` collegata ai prodotti (non alle sostanze) è presente ma meno usata.

**Cosa manca rispetto allo stato dell'arte:**

**a) Struttura a 16 sezioni (REACH Allegato II / GHS):**
I sistemi enterprise mantengono le SDS come documento strutturato in database (16 sezioni codificate), permettendo di generare PDF automaticamente dai dati di classificazione. Questo è un progetto complesso (da 3 mesi a un anno di sviluppo), ma offre vantaggi enormi:
- Aggiornamento automatico quando cambia una classificazione
- Confronto tra versioni
- Estrazione dati per reporting

**b) Notifica cliente SDS aggiornata:**
REACH Art. 31(9) obbliga il fornitore a inviare la nuova SDS a tutti i clienti che hanno acquistato il prodotto nei 12 mesi precedenti. Manca un sistema di tracciamento "quali clienti hanno ricevuto quale versione" e trigger automatici.

**c) PCN (Poison Centre Notification):**
Dal 1° gennaio 2025 le miscele pericolose vendute in EU richiedono UFI e notifica al centro antiveleni (in Italia: Ospedali Riuniti di Roma). L'UFI deve apparire sulla SDS sezione 1.1 e sull'etichetta. Quack non gestisce UFI né il flusso PCN.

**d) SDS collegata al prodotto vs. alla sostanza:**
In Quack esistono due tabelle: `schede_sicurezza` (collegata a `prodotti`) e `sostanze_adr_schede` (collegata a `sostanze_adr`). È una duplicazione da risolvere. La best practice è SDS collegata alla **formula/sostanza**, non al singolo SKU, con possibilità di overrride per SKU specifici.

**Azione raccomandata a breve termine** (senza ristrutturare l'intera SDS):
```sql
-- Aggiungere alla tabella schede_sicurezza o sostanze_adr_schede
numero_versione     VARCHAR(10),     -- es. "4.0", "3.1"
data_revisione      DATE,
data_scadenza_revisione DATE,        -- tipicamente 3 anni dalla revisione
ufi                 VARCHAR(20),     -- UFI collegato
notifica_pcn_inviata BOOLEAN DEFAULT 0,
notifica_pcn_data   DATE,
inviata_a_clienti_il DATE           -- data ultima notifica clienti REACH 31(9)
```
E aggiungere un sistema di tracking clienti notificati (tabella `sds_notifiche_clienti`).

### 3.3 Trasporto ADR — GAP CRITICO

**Situazione attuale:**
Il DDT include le colonne ADR (numero ONU, classe, packing group) e mostra un avviso generico "merci pericolose ADR". C'è il calcolatore 1000 punti nell'AdrController. La logica è corretta a livello concettuale.

**Cosa manca:**

**a) Documento di trasporto ADR formale (ADR 5.4.1) separato dal DDT:**
Il DDT italiano è un documento fiscale/commerciale. Il documento di trasporto ADR richiesto dall'accordo europeo deve contenere informazioni specifiche nel formato preciso:
```
"UN 1203, BENZINA, 3, II, (D/E), 200 kg"
```
ovvero: `UN [numero], [Nome di spedizione appropriato ADR], [classe], [gruppo imballaggio], ([codice galleria]), [quantità]`

Nei sistemi maturi questo documento è generato separatamente con template specifico, non mescolato con il DDT commerciale.

**b) Istruzioni scritte al conducente (ADR 5.4.3):**
Obbligo ADR: il conducente deve avere a bordo istruzioni scritte in lingua comprensibile. Devono coprire tutte le classi di pericolo presenti. Nei sistemi avanzati è un documento generato automaticamente in base alle classi ADR della spedizione. In Quack manca completamente.

**c) Numero Kemler (numero di pericolo):**
Il pannello arancio ADR richiede numero Kemler (2 o 3 cifre che indicano il tipo di pericolo) sopra il numero ONU. Quack non lo gestisce. Va aggiunto a `adr_codici_onu` (colonna `numero_kemler VARCHAR(4)`).

**d) Nome di spedizione appropriato (PSN - Proper Shipping Name):**
Nei sistemi maturi il PSN viene generato dinamicamente in base al prodotto specifico (es. "ACIDO CLORIDRICO IN SOLUZIONE" con concentrazione se richiesta). Quack usa `nome_tecnico` sulla classificazione, ma manca la logica per costruire il PSN completo con eventuali qualificatori.

**e) Verifica incompatibilità di carico:**
ADR impone restrizioni su cosa può essere trasportato insieme (es. materie della classe 1 con infiammabili, perossidi con materie riducenti). I sistemi enterprise hanno una matrice di incompatibilità e avvertono se si crea una spedizione con prodotti incompatibili.

**f) Multi-modale:**
Se i prodotti vengono spediti anche per corriere espresso (IATA) o via mare (IMDG), ci sono regole diverse, a volte più restrittive. Quack non modella questo.

**Azioni raccomandate prioritarie:**

1. Aggiungere `numero_kemler VARCHAR(4)` a `adr_codici_onu`
2. Creare un template PDF separato per il **documento di trasporto ADR 5.4.1** 
3. Creare un template PDF per le **istruzioni scritte al conducente ADR 5.4.3** generato per classi di pericolo

### 3.4 Compliance e Restrizioni — GAP MEDIO

**Situazione attuale:**
L'infrastruttura è buona: `compliance_restriction_types`, `compliance_autorizzazioni` (con workflow stati), `compliance_documenti_caricati` (con verifica), `compliance_log_transazioni` (con flag reporting), `prodotti_restrizioni`. Il check al preventivo è integrato.

**Cosa manca:**

**a) Precursori di stupefacenti (D.Lgs 50/1993 / Reg. UE 2019/515):**
Solo i precursori esplosivi sono stati implementati nel seed. I precursori di stupefacenti richiedono gestione più complessa con 3 categorie (Cat. 1: licenza, Cat. 2: autorizzazione, Cat. 3: registrazione), soglie per concentrazione, obbligo di reporting trimestrale all'UICPGD. Prodotti comuni coinvolti: acido solforico, permanganato di potassio, acetica anidride, efedrina, ecc.

**b) Biocidi (Reg. UE 528/2012):**
I biocidi richiedono autorizzazione per tipo di uso (PT1-PT22). Un ERP chimico deve verificare che il cliente usi il biocida per un uso autorizzato e che per usi professionali/industriali (PT13-PT22) il cliente abbia le competenze richieste.

**c) Reporting automatico:**
La colonna `reported_to_authority` esiste ma manca il meccanismo che genera i report nel formato richiesto (es. XML per sistema SIAC in Italia per precursori stupefacenti). I sistemi maturi hanno esportazioni preconfigurate.

**d) Soglie di concentrazione più granulari:**
I limiti ADR per i precursori cambiano in base alla concentrazione (es. H₂O₂: non ADR se ≤ 60%, ADR classe 5.1 se > 60%). La tabella `compliance_codice_onu_restrizioni` gestisce min/max concentration ma la logica di verifica in `compliance.php` usa le concentrazioni della sostanza (min/max pct sulla sostanza_adr), non quella del prodotto specifico. Questo va verificato e potenzialmente rafforzato.

**e) Log vendite per precursori — verifica quantità cumulative:**
Il Reg. UE 2019/1148 richiede di segnalare transazioni "sospette". Una transazione può diventare sospetta non per la singola vendita ma per il pattern di acquisto (stessa persona che compra grandi quantità in breve tempo). I sistemi avanzati hanno regole di alerting su questo. Manca completamente in Quack.

### 3.5 Magazzino — GAP MEDIO

**Situazione attuale:**
Giacenze, movimenti, lotti (con FEFO per data_scadenza), inventari. Correto e funzionale. Manca `ubicazione_stoccaggio` strutturata (c'è solo un campo varchar in `ricezioni_righe`).

**Cosa manca:**

**a) Location Management strutturato:**
In Quack `ubicazione_stoccaggio` è un campo testo libero. Un magazzino chimico ha requisiti stringenti: zone ADR separate (zona infiammabili, zona corrosivi, zona tossici), requisiti di temperatura, ventilazione. I sistemi maturi hanno tabella `magazzino_locazioni` con:
- Codice ubicazione (es. A01-B03)
- Classe ADR ammessa
- Temperatura massima
- Capacità (peso, volume)
- Tipo contenitore

**b) Matrice di compatibilità/segregazione:**
Non esiste logica che impedisce di stoccare nella stessa ubicazione prodotti incompatibili. ADR e NFPA 30 definiscono regole precise (es. ossidanti non con infiammabili, acidi forti non con basi forti, tossici separati da alimentari). Un sistema maturo verifica questo all'atto del ricevimento merce.

**c) Gestione rifiuti pericolosi:**
I prodotti chimici scaduti o i residui diventano rifiuti pericolosi con codici CER specifici. Manca una gestione dei rifiuti (formulario FIR/MUD, registro carico/scarico rifiuti pericolosi, obbligatorio per D.Lgs 152/2006).

**d) Giacenze multi-ubicazione:**
La tabella `giacenze` ha una riga per prodotto (UNIQUE su prodotto_id). Con location management, la giacenza dovrebbe essere per prodotto × ubicazione.

### 3.6 Documentazione e PDF — GAP MEDIO

**Situazione attuale:**
mPDF è integrato, preventivi in PDF funzionano, DDT stampabile.

**Cosa manca:**
- Documento ADR 5.4.1 separato (vedi 3.3)
- Istruzioni scritte conducente (vedi 3.3)
- Etichette ADR stampabili (per collo/imballo)
- Etichette CLP/GHS stampabili
- Bolla ADR (pannello arancio con numero Kemler/ONU) — in realtà è del vettore, ma alcuni ERP la gestiscono

### 3.7 Frontend e UX — GAP MINORE/MEDIO

**Situazione attuale:**
PHP server-side rendering classico. Efficace ma non real-time.

**Cosa manca rispetto a sistemi moderni:**
- Dashboard in tempo reale con KPI (prodotti ADR in scadenza, autorizzazioni compliance in scadenza, SDS da aggiornare)
- Notifiche in-app per alerting normativo (SDS scaduta, autorizzazione cliente scaduta, scheda ONU aggiornata dopo revisione biennale ADR)
- Suggerimento automatico classificazione ADR quando si inserisce un codice CAS (API lookup da PubChem/ECHA)
- Accesso clienti per download SDS (portale self-service)

### 3.8 Aspetti non ancora implementati ma rilevanti

**a) Fattura elettronica XML (SDI):**
In Italia la fattura elettronica al SDI è obbligatoria per i B2B. Lo schema SDI ha campi specifici. Quack ha la struttura delle fatture ma non la generazione del file XML FatturaPA. Questo è un gap urgente per operatività.

**b) e-CMR (lettera di vettura elettronica):**
Per spedizioni internazionali ADR la CMR digitale è sempre più richiesta. Non è urgente per un'azienda con vendite prevalentemente nazionali.

**c) IMDG/IATA:**
Se l'azienda spedisce anche per corriere aereo o container navale, servono classificazioni IMDG e IATA. Quack gestisce solo ADR.

**d) Integrazione con registri nazionali:**
ECHA (dati REACH/CLP), PubChem (dati CAS), ADR official database — un'integrazione API permetterebbe di auto-compilare i dati chimici quando si inserisce un CAS.

---

## 4. Roadmap raccomandata

### Priorità ALTA (impatto legale/operativo immediato)

**Sprint 1 — Completamento documenti ADR obbligatori**
1. Aggiungere `numero_kemler` a `adr_codici_onu`
2. Creare template PDF "Documento di Trasporto Merci Pericolose" (ADR 5.4.1) separato dal DDT con formato stringa ADR corretto
3. Creare template PDF "Istruzioni Scritte al Conducente" (ADR 5.4.3) generato per classi di pericolo
4. Aggiungere logica di costruzione PSN (Proper Shipping Name) con qualificatori

**Sprint 2 — UFI e PCN**
1. Aggiungere `codice_ufi` a `sostanze_adr`
2. Aggiungere `numero_versione` e `inviata_a_clienti_il` a `sostanze_adr_schede`
3. Creare tabella `sds_notifiche_clienti` per tracking REACH Art. 31(9)
4. Aggiungere UI per gestire e inviare notifiche SDS ai clienti

**Sprint 3 — Precursori stupefacenti**
1. Aggiungere seed `compliance_restriction_types` per DRUG_PRECURSOR_CAT1/CAT2/CAT3
2. Implementare logica di verifica per categorie 1/2/3 con diversi livelli di restrizione
3. Aggiungere reporting strutturato per UICPGD

### Priorità MEDIA (buona pratica, non urgente)

**Sprint 4 — CLP/GHS nel master data**
1. Aggiungere H statements, P statements, pittogrammi GHS a `sostanze_adr`
2. Aggiungere `codice_reach`, `codice_einecs`, `numero_kemler`, `stato_fisico` a `sostanze_adr`
3. Generazione automatica sezione 2 della SDS dai dati CLP inseriti

**Sprint 5 — Location Management magazzino**
1. Creare tabella `magazzino_ubicazioni` con classe ADR ammessa
2. Modificare `giacenze` per supportare multi-ubicazione
3. Matrice di compatibilità ADR di base (almeno warning)

**Sprint 6 — Dashboard normativa**
1. Widget "SDS in scadenza" (>3 anni da data revisione)
2. Widget "Autorizzazioni compliance in scadenza" (filtro su compliance_autorizzazioni)
3. Widget "Prodotti ADR senza classificazione corrente"
4. Alerting per revisione biennale ADR (ADR si aggiorna ogni 2 anni, prossima 2027)

### Priorità BASSA (evoluzione a lungo termine)

**Sprint 7 — Fattura elettronica XML SDI**
Dipende dalle priorità di business, ma tecnicamente è necessario per operatività completa.

**Sprint 8 — SDS strutturata a 16 sezioni**
Progetto più impegnativo. Valutare integrazione con Chemius API o Sphera invece di sviluppare in-house.

**Sprint 9 — Multi-regolamento trasporto (IMDG/IATA)**
Solo se l'azienda spedisce per nave/aereo.

---

## 5. Valutazione complessiva

| Area | Completezza | Allineamento best-in-class | Priorità miglioramento |
|------|-------------|---------------------------|----------------------|
| Master Data ADR | 75% | Buono | MEDIA |
| Master Data CLP/GHS | 10% | Basso | ALTA |
| SDS Management | 40% | Sufficiente | ALTA |
| Trasporto ADR | 60% | Discreto | ALTA |
| Compliance restrizioni | 65% | Buono (per precurs. esplosivi) | MEDIA |
| Ciclo acquisti | 85% | Molto buono | BASSA |
| Magazzino | 70% | Buono | MEDIA |
| Ciclo vendita | 80% | Buono | BASSA |
| Documentazione PDF | 55% | Discreto | ALTA |
| Frontend/UX | 60% | Sufficiente | MEDIA |

**Giudizio generale:** Il progetto Quack è a un livello di maturità sorprendentemente alto per un ERP custom, in particolare per la gestione ADR (che spesso è la parte più trascurata nei sistemi generici). L'architettura del database è solida, il modello a tre livelli ONU → Sostanza → Prodotto è corretto, il ciclo acquisti è professionale. I gap principali sono su aspetti normativi specifici (UFI/PCN, documenti ADR obbligatori come istruzioni conducente, precursori stupefacenti) e sulla classificazione CLP/GHS che nei sistemi best-in-class è il cuore dell'intero modulo chimico. Con gli sprint suggeriti, Quack può raggiungere un livello comparabile a soluzioni verticali di mercato per PMI.

---

*Documento generato in base all'analisi dello schema SQLite v4.2, dei controller PHP e della ricerca sullo stato dell'arte ERP chimici (SAP EH&S, Oracle, Infor, Chemius, Odoo Chemical) — Giugno 2026.*
