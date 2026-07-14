

Quack ERP – Magazzino e Lotti

Cosa succede quando si
attiva o disattiva la tracciabilità lotti su un prodotto esistente

**Best
practice (Odoo e standard ERP):**

Ci sono tre situazioni possibili quando si tenta di
disabilitare traccia_lotti:

| **Stato lotti**                    | **Comportamento corretto**                                      |
| ---------------------------------- | --------------------------------------------------------------- |
| Lotti con quantita_disponibile > 0 | **Blocca** — dati inconsistenti inevitabili                     |
| Lotti tutti a 0                    | **Consenti** — storico preservato, futuro senza<br> tracciatura |
| Nessun lotto mai creato            | **Consenti** sempre                                             |

Il motivo del blocco: se disabiliti con lotti ancora aperti,
giacenze continua ad essere aggiornata dai movimenti ma
lotti.quantita_disponibile resta congelato. Il registro lotti diventa
spazzatura e la tracciabilità è persa. In settori regolamentati (alimentare,
chimico, farmaceutico) è un problema legale.

**Nei movimenti dopo la disabilitazione:** nessun campo
lotto richiesto, giacenza aggiornata direttamente. I record lotti storici
rimangono read-only per l'audit trail — non vengono mai cancellati.

Implemento il blocco nel controller prodotti:

File modificato

File modificato

Ora aggiungo il rendering dell'errore nel form prodotto,
vicino al checkbox:

File letto, file modificato

File letto, file modificato

Fatto. Il comportamento ora è:

- **Lotti
   attivi (qty > 0)** → salvataggio bloccato con messaggio esplicito: *"Impossibile
   disabilitare la tracciatura lotti: 3 lotti hanno ancora giacenza
   disponibile (totale: 150,000). Azzera le giacenze di tutti i lotti prima
   di disabilitare la tracciatura."*
- **Tutti
   i lotti a zero** → la disabilitazione è consentita; i record storici in
   lotti restano intatti per l'audit trail ma non vengono più aggiornati dai
   movimenti futuri.
- **Nessun
   lotto mai creato** → nessun controllo necessario, cambio libero in
   entrambe le direzioni.
