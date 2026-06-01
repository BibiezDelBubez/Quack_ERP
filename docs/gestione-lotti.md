# Gestione Lotti — Come funziona

> Versione: maggio 2026 — Quack ERP

---

## In breve: cos'è un lotto

Un lotto è un'etichetta che incollate su un gruppo di prodotti arrivati insieme con la stessa bolla.

**Esempio concreto:**  
Oggi, 19 maggio 2026 (giorno 139 dell'anno), arriva un camion con 50 kg di cloro granulare.  
Assegnate a quella partita il numero **139/26**.  
Da quel momento, ogni singolo kg di quel cloro è "il cloro del lotto 139/26".

Quando tra sei mesi qualcuno chiama e dice "il vostro cloro ha corroso la pompa" — voi guardate il lotto sulla bolla che avete consegnato, tracciate da dove è arrivato, chi era il fornitore, quando è entrato in magazzino, e potete verificare se ci sono altri clienti con lo stesso lotto ancora in uso.

---

## Il flusso dalla A alla Z

```
FORNITORE          VOI (magazzino)           CLIENTE
─────────          ───────────────           ───────
                                          
Bolla arrivo  →  Crei il lotto 139/26    
              →  Carichi 50 kg in magazz.  
              →  Il lotto tiene il conto  
                 (50 kg disponibili)     

                 Ordine cliente           →  Cliente ordina 10 kg
              →  Scarichi 10 kg          →  DDT di uscita:
                 dal lotto 139/26            "Cloro 10 kg — lotto 139/26"
                 (rimangono 40 kg)       

                 Altro ordine            →  Cliente 2 ordina 15 kg
              →  Scarichi 15 kg          →  DDT di uscita:
                 dal lotto 139/26            "Cloro 15 kg — lotto 139/26"
                 (rimangono 25 kg)       
```

Ogni movimento (carico e scarico) è registrato con il numero lotto. La catena è sempre ricostruibile.

---

## Come si forma il codice lotto

Il formato usato da voi è `GGG/AA`:

- `GGG` = numero del giorno nell'anno (da 001 a 365/366)
- `AA` = anno a 2 cifre

| Data arrivo   | Giorno | Codice lotto |
|---------------|--------|--------------|
| 19/05/2026    | 139    | 139/26       |
| 01/01/2026    | 1      | 1/26         |
| 31/12/2026    | 365    | 365/26       |

**Attenzione:** se in uno stesso giorno arrivano due bolle diverse dello stesso prodotto, avete due lotti con lo stesso codice ma da fornitori/bolle diverse. Nel sistema possono convivere, ma meglio aggiungere un suffisso (`139/26-A`, `139/26-B`) o usare il numero della bolla per disambiguare. Il campo `numero_ddt_arrivo` serve proprio a questo.

---

## Cosa succede quando arriva la merce

Quando ricevete una bolla:

1. **Create il lotto** nel sistema:
   - Prodotto
   - Codice lotto (es. 139/26)
   - Fornitore
   - Numero DDT del fornitore (la bolla di accompagnamento)
   - Data arrivo
   - Quantità ricevuta
   - Data scadenza (se il prodotto ha una scadenza — importante per i chimici)

2. Il sistema genera automaticamente un **movimento di carico** in magazzino, collegato a quel lotto.

3. La giacenza del prodotto aumenta. Ma ora è tracciata **per lotto**: sapete quante unità ci sono per ogni lotto in magazzino.

---

## Cosa succede quando vendete

Quando create una spedizione o un DDT di uscita:

1. Per ogni riga del documento scegliete da quale lotto prendere la merce.
   - Di solito si usa il **FIFO** (First In First Out): il lotto più vecchio viene consumato per primo.
   - In alternativa lo scegliete a mano (utile se il cliente ha richiesto una specifica partita).

2. Il sistema scala la quantità dal lotto scelto.

3. Sul DDT stampato compare il numero lotto accanto al prodotto:
   ```
   Cloro granulare 70% — 10,000 kg — Lotto: 139/26
   ```

4. Se una riga richiede più quantità di quanto disponibile in un singolo lotto, si può **splittare** su più lotti:
   ```
   Cloro granulare 70% — 25,000 kg
     → 20 kg dal lotto 139/26
     →  5 kg dal lotto 141/26
   ```

---

## Rintracciabilità: come si usa nella pratica

### Caso 1 — Reclamo cliente
> "Il cloro che ci avete consegnato il 3 giugno era di scarsa qualità"

1. Cercate l'ordine/DDT di quella consegna
2. Il sistema mostra il lotto usato (es. 139/26)
3. Vedete: quel lotto era della bolla X del fornitore Y, arrivata il 19 maggio
4. Verificate se altri clienti hanno ricevuto merce dello stesso lotto
5. Contattate il fornitore con numero di bolla esatto

### Caso 2 — Richiamo fornitore
> "Il fornitore avvisa che la partita arrivata a maggio potrebbe avere problemi"

1. Cercate tutti i lotti del quel fornitore nel periodo indicato
2. Per ogni lotto: vedete a chi è stata consegnata quella merce, quando, e quanta ne rimane in magazzino
3. Potete avvisare i clienti coinvolti

### Caso 3 — Prodotto in scadenza
> Volete sapere se avete merce vicina alla scadenza

1. Filtrate i lotti per `data_scadenza` entro X giorni
2. Vedete quali prodotti/lotti stanno per scadere e quanta quantità c'è ancora in giacenza

---

## Come funziona nello schema dati

In parole semplici, ci sono queste tabelle nuove/modificate:

### `lotti` (tabella nuova)
Contiene un record per ogni lotto. I campi principali:

| Campo | Cosa contiene |
|-------|---------------|
| `codice_lotto` | Es. "139/26" |
| `prodotto_id` | A quale prodotto si riferisce |
| `fornitore_id` | Chi ha consegnato la merce |
| `numero_ddt_arrivo` | Il numero della bolla del fornitore |
| `data_arrivo` | Quando è arrivata |
| `quantita_ricevuta` | Quante unità sono entrate |
| `quantita_disponibile` | Quante sono ancora in magazzino (scala ad ogni vendita) |
| `data_scadenza` | Opzionale — per prodotti con scadenza |

### `movimenti_magazzino` (aggiunta colonna)
Ogni carico e scarico ora ha anche `lotto_id` → sapete sempre quale lotto ha mosso ogni unità.

### `spedizioni_righe` + `documenti_trasporto_righe` (aggiunta colonna)
Ogni riga di spedizione e DDT ha `lotto_id` e `codice_lotto` → il numero lotto appare sul documento.

### `ordini_clienti_righe_lotti` (tabella nuova)
Collega le righe degli ordini ai lotti. Una riga ordine può attingere da più lotti (split).

---

## Domande pratiche

**Devo assegnare un lotto a tutti i prodotti?**  
No. La tracciatura lotti è per prodotto. Se un prodotto è "carta per stampante" e non vi interessa tracciarlo, non create lotti. Se invece è un chimico o un prodotto soggetto a reclami, attivate i lotti.

**Cosa succede se non so il numero lotto al momento dell'ordine?**  
Potete assegnare il lotto al momento della preparazione della spedizione, non al momento dell'ordine. È il momento del DDT che conta per la rintracciabilità.

**Il lotto appare sempre sul DDT?**  
Solo se la riga ha un lotto associato. Se non avete impostato lotti per quel prodotto, il campo rimane vuoto.

**Posso avere lo stesso lotto per due prodotti diversi?**  
Sì, ma tecnicamente sono due lotti separati nel sistema (stessa bolla, prodotti diversi). Il codice lotto può essere lo stesso (139/26), ma le giacenze vengono tracciate separatamente per prodotto.

**Cosa succede se restituisco merce al fornitore?**  
Il sistema crea un movimento di scarico (reso fornitore) sul lotto corrispondente, riducendone la quantità disponibile.

---

## In sintesi: le regole d'oro

1. **Ogni bolla in arrivo = un lotto per prodotto**
2. **Il lotto nasce al carico, muore quando è esaurito**
3. **FIFO di default** — usate prima il lotto più vecchio
4. **Il numero lotto va sempre sul DDT di uscita**
5. **Mai modificare un lotto chiuso** — se c'è un errore, fate una rettifica separata
