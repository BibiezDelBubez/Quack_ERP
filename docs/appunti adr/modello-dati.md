# Modello dati ADR — guida pratica per Quack

## 1. Perché questa guida

Quack gestisce prodotti chimici pericolosi. Ogni spedizione potrebbe richiedere la classificazione ADR (Accordo europeo merci pericolose su strada). Prima di costruire le schermate mancanti, è utile capire **come lo fanno i sistemi professionali** e cosa ci insegnano per le scelte di schema.

---

## 2. Lo stato dell'arte: il modello SAP EHS/DGP

Il riferimento di settore è **SAP EH&S Dangerous Goods Management** (componente EHS-DGP), adottato dai grandi distributori chimici europei (BASF, Brenntag, Univar, ecc.).

Il modello separa **tre livelli distinti**:

```
┌─────────────────────────────────────────────────────────┐
│  LIVELLO 1 — Tabella UN (normativa, immutabile)         │
│  adr_codici_onu                                         │
│  Un record per riga della Tabella A ADR.                │
│  Dati: UN 1789, classe 8, PG II, cat. trasporto 2...   │
└─────────────────────┬───────────────────────────────────┘
                      │ 1:1
┌─────────────────────▼───────────────────────────────────┐
│  LIVELLO 2 — Sostanza reale (aziendale)                 │
│  sostanze_adr   ← questa è l'entità mancante in Quack  │
│  Esempio: "Acido Cloridrico 30% in acqua"              │
│  Dati aziendali: nome commerciale, SDS per lingua,     │
│  nome tecnico, concentrazione tipica, note...           │
└─────────────────────┬───────────────────────────────────┘
                      │ 1:MOLTI
┌─────────────────────▼───────────────────────────────────┐
│  LIVELLO 3 — Prodotto / SKU                             │
│  prodotti                                               │
│  Esempio: SKU "HCL-30-25KG", "HCL-30-200KG"           │
│  Override specifici per SKU: packing group diverso,    │
│  concentrazione dichiarata, etc.                        │
└─────────────────────────────────────────────────────────┘
```

### Perché tre livelli e non due?

Il problema pratico che questo risolve: un distributore come Brenntag vende **Acido Cloridrico 30%** in fusti da 25 kg, 200 kg e autobotti da 25.000 kg. Sono **tre SKU diversi** (tre record in `prodotti`) ma hanno la **stessa scheda di sicurezza**, lo stesso codice UN 1789, le stesse istruzioni di trasporto. Senza una "sostanza" condivisa, ogni modifica alla SDS va fatta tre volte. Con la sostanza condivisa, la modifichi una volta sola.

### Cosa fa SAP che possiamo replicare

In SAP:
- La **UN Listed Substance** (= `adr_codici_onu`) è la voce normativa, copiata dalla Tabella A ADR
- La **Real Substance / DG Master** (= quello che chiameremo `sostanze_adr`) è l'entità aziendale che lega la voce UN ai vostri prodotti e ospita SDS, nome tecnico, proprietà fisiche misurate
- Il **Material** (= `prodotti`) è l'SKU commerciale, che punta alla DG Master e può avere override (es. packing group diverso per una formulazione più diluita)

Il software CHEMDOX (specializzato SDS per distributori chimici europei) usa la stessa logica: **product database** separata dalla **substance/regulatory database**, con le schede di sicurezza ancorate alla sostanza ma rese disponibili per prodotto.

---

## 3. Cosa esiste già in Quack e cosa manca

### Già presente ✓

| Tabella | Cosa contiene |
|---|---|
| `adr_codici_onu` | Tabella A ADR — classe, PG, cat. trasporto, QL, QE, restrizioni gallerie, merce alto rischio |
| `adr_classi_pericolo` | Classi 1–9 |
| `adr_gruppi_imballaggio` | I, II, III |
| `adr_categorie_trasporto` | 0–4 con moltiplicatori punti |
| `adr_codici_classificazione` | Codici classificazione (es. F1, D/E, ...) |
| `adr_quantita_esenti` | Codici E0–E5 con soglie |
| `prodotto_classificazioni_adr` | Override per prodotto (concentrazione, PG override, nome tecnico, validità) |

### Mancante ✗

| Cosa | Perché serve |
|---|---|
| `sostanze_adr` — entità sostanza condivisa | Aggregare più SKU sotto la stessa sostanza, ospitare SDS |
| `sostanze_adr_schede` — file SDS per lingua | Una SDS per lingua, aggiornabile una volta per tutti i prodotti |
| `prodotti.is_adr` | Flag per attivare la tab ADR nel prodotto |
| `prodotti.sostanza_adr_id` | FK verso la sostanza condivisa |

---

## 4. `prodotto_classificazioni_adr` è ridondante: si elimina

Il ragionamento originale per tenerla era: "HCl 30% ha PG II, HCl 10% ha PG III — stesso UN, packing group diverso". Ma questo argomento cade subito: se le due formulazioni hanno classificazione diversa, hanno anche **SDS diverse**. Sono quindi **due sostanze ADR distinte**, non la stessa sostanza con un override.

```
sostanze_adr #1: "Acido Cloridrico 30%"  → UN 1789, PG II, SDS_IT_v3.1.pdf
sostanze_adr #2: "Acido Cloridrico 10%"  → UN 1789, PG III, SDS_IT_v2.0.pdf
       ↑                                          ↑
  prodotti: HCL-30-25KG                    prodotti: HCL-10-25KG
            HCL-30-200KG
```

Entrambe puntano allo stesso `adr_codici_onu` (UN 1789), ma sono entità separate con il proprio PG, la propria concentrazione e le proprie schede.

**Conclusione:** `prodotto_classificazioni_adr` viene **eliminata**. I suoi campi vengono assorbiti da `sostanze_adr`:
- `gruppo_imballaggio` (non più "override" — è il PG di quella specifica sostanza)
- `concentrazione_min_pct`, `concentrazione_max_pct`
- `punto_infiammabilita_c`
- `e_rifiuto`, `e_stabilizzato`, `e_soluzione`
- `nome_tecnico`

Il flusso di lettura dati ADR per una riga di spedizione diventa lineare:

```
prodotti.sostanza_adr_id
    → sostanze_adr (PG, concentrazione, nome tecnico, flash point)
    → sostanze_adr.codice_onu_id → adr_codici_onu (classe, cat. trasporto, QL/QE)
```

---

## 5. Le schede di sicurezza (SDS / schede di sicurezza)

### Standard di riferimento: REACH Regolamento 1907/2006 + GHS/CLP

Una SDS ha 16 sezioni. La sezione 14 ("Informazioni sul trasporto") contiene:
- Numero ONU / ID
- Nome spedizione ufficiale ADR
- Classe/e di pericolo
- Gruppo di imballaggio
- Pericoli per l'ambiente (inquinante marino, etc.)
- Precauzioni speciali per l'utente
- Trasporto alla rinfusa (se applicabile)

### Come si gestiscono in modo professionale

I sistemi best-in-class (CHEMDOX, SAP Product Safety) applicano queste regole:

1. **SDS ancorata alla sostanza, non al prodotto singolo** — La SDS descrive la sostanza chimica. Se vendo la stessa sostanza in 3 packaging, la SDS è una sola (per lingua).

2. **Versioning** — Ogni SDS ha una versione e una data di emissione. Quando l'ADR cambia biennalmente o quando ECHA aggiorna le classificazioni, si carica una nuova versione senza cancellare la vecchia (obbligatorio per tracciabilità: REACH art. 31 richiede di poter dimostrare quale SDS era valida alla data di una spedizione).

3. **Multilingua** — La SDS va fornita nella lingua del paese di destinazione (art. 31(5) REACH). Minimo: italiano + inglese per un distributore nazionale che esporta.

4. **Distribuzione automatica** — Sistemi avanzati allegano la SDS automaticamente alle DDT per le righe con `is_adr = 1`.

### Schema per Quack

```sql
CREATE TABLE sostanze_adr_schede (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    sostanza_id     INTEGER NOT NULL REFERENCES sostanze_adr(id) ON DELETE CASCADE,
    lingua          CHAR(5) NOT NULL,          -- 'IT', 'EN', 'DE', 'FR', 'ES'
    versione        VARCHAR(20),               -- es. '3.1', '2024-01'
    data_emissione  DATE,
    file_path       TEXT NOT NULL,             -- path relativo, es. 'sds/IT/hcl30-v3.1.pdf'
    file_nome_orig  VARCHAR(255),
    e_corrente      BOOLEAN DEFAULT 1,         -- 1 = versione attiva per questa lingua
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(sostanza_id, lingua, versione)
);
```

---

## 6. Il calcolo ADR: spedizione in esenzione o dichiarata?

Questo è il punto più importante dal punto di vista operativo. L'ADR prevede due regimi principali:

### 6a. Quantità limitate (QL) — cap. 3.4

Se ogni collo contiene meno di una certa quantità (il codice QL su `adr_codici_onu.quantita_limitata`), la spedizione è **esente da quasi tutte le obbligazioni ADR** (no patente ADR, no segnaletica, no documento di trasporto ADR, solo etichetta "quadrato nero in bianco").

Soglie QL: 100 ml / 500 g per la maggior parte dei liquidi infiammabili/corrosivi. Il limite per collo e per carico totale sono indicati nei capitoli 3.4.

### 6b. Quantità esenti (QE) — cap. 3.5

Ancora più permissivo: codici E0–E5 su `adr_quantita_esenti`. Se la quantità per imballaggio interno è ≤ alla soglia E, il trasporto è completamente esente.

### 6c. Il calcolo dei punti ADR — cap. 1.1.3.6

Per le spedizioni che NON rientrano nelle esenzioni, si calcola un punteggio:

```
punti_riga = quantita_netta_kg_o_litri × moltiplicatore_categoria_trasporto
```

I moltiplicatori per categoria:
- Cat. 0 (vietate): ∞
- Cat. 1: × 50
- Cat. 2: × 3  
- Cat. 3: × 1
- Cat. 4: × 0 (no limite)

Se la somma dei punti di tutte le righe della spedizione è **≤ 1000**, la spedizione è **in esenzione** (cap. 1.1.3.6): non serve patente ADR del conducente, non serve segnaletica laterale del veicolo, basta documento di accompagnamento con indicazione "QUANTITÀ ESENTATE".

Se supera 1000 punti → spedizione **dichiarata ADR** completa: documento di trasporto ADR, istruzioni scritte, patente, segnaletica, equipaggiamento di sicurezza, etc.

### Come implementare il calcolo in Quack

Il dato necessario per ogni riga di spedizione:
```
adr_codici_onu.categoria_trasporto → adr_categorie_trasporto.moltiplicatore_punti
adr_codici_onu.codice_quantita_esente → adr_quantita_esenti (verifica esenzione QE)
adr_codici_onu.quantita_limitata (verifica esenzione QL)
```

Tutti questi dati esistono già in Quack. Manca solo il layer che li collega ai prodotti delle spedizioni tramite la sostanza.

---

## 7. Schema proposto completo

```sql
-- Tabella sostanza condivisa (livello 2) — assorbe prodotto_classificazioni_adr
CREATE TABLE sostanze_adr (
    id                      INTEGER PRIMARY KEY AUTOINCREMENT,
    nome                    VARCHAR(300) NOT NULL,      -- "Acido Cloridrico 30%"
    codice_onu_id           INTEGER NOT NULL REFERENCES adr_codici_onu(id),
    nome_tecnico            VARCHAR(200),               -- Nome spedizione ADR ufficiale
    gruppo_imballaggio      CHAR(3),                    -- I / II / III (se diverso dal default ONU)
    concentrazione_min_pct  DECIMAL(5,2),
    concentrazione_max_pct  DECIMAL(5,2),
    punto_infiammabilita_c  DECIMAL(6,2),               -- °C (classe 3)
    e_soluzione             BOOLEAN DEFAULT 0,
    e_rifiuto               BOOLEAN DEFAULT 0,
    e_stabilizzato          BOOLEAN DEFAULT 0,
    note_interne            TEXT,
    creato_da               INTEGER REFERENCES utenti(id),
    created_at              DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- SDS per sostanza (versionato, multilingua)
CREATE TABLE sostanze_adr_schede (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    sostanza_id     INTEGER NOT NULL REFERENCES sostanze_adr(id) ON DELETE CASCADE,
    lingua          CHAR(5) NOT NULL,                   -- IT, EN, DE, FR, ES
    versione        VARCHAR(20),                        -- es. "3.1" o "2024-06"
    data_emissione  DATE,
    file_path       TEXT NOT NULL,                      -- path relativo a UPLOAD_DIR
    file_nome_orig  VARCHAR(255),
    e_corrente      BOOLEAN DEFAULT 1,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(sostanza_id, lingua, versione)
);

-- Colonne da aggiungere a prodotti
ALTER TABLE prodotti ADD COLUMN is_adr          BOOLEAN DEFAULT 0;
ALTER TABLE prodotti ADD COLUMN sostanza_adr_id INTEGER REFERENCES sostanze_adr(id);

-- Eliminare la tabella ora ridondante
DROP TABLE IF EXISTS prodotto_classificazioni_adr;
```

---

## 8. Decisioni da prendere prima di costruire le schermate

Dopo aver letto questa guida, occorre rispondere a:

### D1 — Dove vivono i file SDS sul server?

Due opzioni:
- **`/uploads/sds/`** nella cartella pubblica di Quack (semplicissimo, ma i PDF sono accessibili a chiunque conosca l'URL)
- **`/storage/sds/`** fuori dalla web root, serviti da un controller PHP con verifica auth (più sicuro, consigliato per dati regolatori)

### D2 — ~~`prodotto_classificazioni_adr`~~ ✓ Eliminata

`sostanze_adr` assorbe tutti i suoi campi. Non servono override per-prodotto: se un prodotto ha una classificazione diversa (diverso PG, diversa concentrazione, diversa SDS), è per definizione una sostanza ADR diversa.

### D3 — Calcolo ADR automatico sulle spedizioni?

Quando una spedizione viene confermata, Quack può calcolare automaticamente i punti ADR e decidere se mostrare un warning "Spedizione dichiarata ADR" nel DDT. Richiede:
- `prodotti.sostanza_adr_id` collegato
- `prodotto_classificazioni_adr` con PG corretto (o default da ONU)
- Logica di calcolo nella `elaboraCreaSpedizione()`

Implementarlo ora o dopo la sezione sostanze è costruita? **Consiglio: dopo.**

### D4 — Lingue SDS da supportare?

Minimo pratico per un distributore italiano con export:
- `IT` — Italia (obbligatoria)
- `EN` — UK, export generico
- `DE` — Germania, Austria, Svizzera tedesca
- `FR` — Francia, Svizzera francese, Belgio

---

## 9. Roadmap suggerita

```
FASE 1 — Migrazione schema (15 min)
  ├── Aggiungere sostanze_adr e sostanze_adr_schede al DB
  ├── Aggiungere prodotti.is_adr e prodotti.sostanza_adr_id
  └── Creare cartella storage SDS

FASE 2 — Sezione "Sostanze ADR" nell'area ADR
  ├── Lista sostanze (con badge UN, classe, quanti prodotti associati)
  ├── Pagina dettaglio sostanza:
  │   ├── Tab Info: nome, codice UN, proprietà fisiche
  │   ├── Tab Schede sicurezza: upload/download SDS per lingua (versionato)
  │   └── Tab Prodotti associati: lista + aggiungi/rimuovi SKU
  └── Voce nel menu sidebar "Sostanze ADR"

FASE 3 — Tab ADR nel prodotto
  ├── Flag is_adr che mostra/nasconde la card
  ├── Selector per associare la sostanza_adr
  ├── Override packing group (se diverso dal default ONU)
  └── Preview: "Questo prodotto è UN XXXX, Classe X, PG Y"

FASE 4 — Calcolo esenzione ADR nelle spedizioni
  ├── Per ogni riga con is_adr=1: calcola punti
  ├── Se somma ≤ 1000: badge "In esenzione 1.1.3.6" sulla DDT
  └── Se somma > 1000: warning + istruzioni scritte obbligatorie
```

---

## 10. Fonti e riferimenti

- **SAP EHS-DGP documentation**: [Dangerous Goods Management intro](https://blogs.sap.com/2014/01/27/dangerous-goods-management-intro/) — conferma il modello a tre livelli (UN substance → Real substance → Material)
- **SAP Help Portal**: [DGP Help](https://help.sap.com/docs/SAP_ERP/47b7039ad8c44808aafbedb12029db94/b424ba53422bb54ce10000000a174cb4.html) — documentazione tecnica del data model
- **CHEMDOX**: [Legal Content & Substance Database](https://www.chemdox.com/en_US/chemdox-software-features/legal-content-substance-database.html) — software SDS per distributori chimici europei, stesso pattern product/substance
- **ADR 2025 Tabella A**: [adrbook.com](https://adrbook.com/en/2017/ADR/3.2) — struttura dati ufficiale della Tabella A
- **ECHA REACH art. 31**: SDS obbligatoria per sostanze/miscele pericolose, versionamento e lingua del paese destinatario
- **ADR cap. 1.1.3.6**: calcolo punti per esenzione — fonte primaria normativa
- **ADR cap. 3.4**: quantità limitate (QL)
- **ADR cap. 3.5**: quantità esenti (QE)
