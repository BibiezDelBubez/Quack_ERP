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

| Prodotto | ADR | Compliance vendita |
|---|---|---|
| H₂O₂ 3% | nessuno | nessuna |
| H₂O₂ 35% | classe 5.1 | precursore esplosivi (>12%) |
| Vernice infiammabile | classe 3 | nessuna |

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

| code | name | regulatory_ref |
|---|---|---|
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

| Metodo | URL | Descrizione |
|---|---|---|
| GET | `/products/compliance/list?prodotto_id=X` | Restrizioni assegnate + tutti i tipi + suggerimenti ADR |
| POST | `/products/compliance/add` | Aggiunge una restrizione al prodotto |
| POST | `/products/compliance/remove` | Rimuove una restrizione dal prodotto |

---

## Cosa manca (roadmap)

- [ ] Check automatico al salvataggio di preventivo/ordine
- [ ] Notifica/blocco UI quando il cliente non ha autorizzazione valida
- [ ] Scrittura automatica di `compliance_log_transazioni` alla conferma ordine
- [ ] Alert per autorizzazioni in scadenza (entro N giorni)
- [ ] UI per gestire `compliance_restriction_types` da settings
- [ ] Segnalazione alle autorità (flag `reported_to_authority`)
