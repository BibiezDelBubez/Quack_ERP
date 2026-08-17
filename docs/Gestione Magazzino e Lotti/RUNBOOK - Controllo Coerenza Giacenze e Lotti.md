# RUNBOOK — Controllo Coerenza Giacenze e Lotti

Tappa 5 del piano: `docs/Gestione Magazzino e Lotti/PIANO - Coerenza Strutturale Giacenze e Lotti.md`

Questo documento spiega come e quando usare `tools/check-giacenze-lotti.php`,
lo script di controllo introdotto da questa Tappa. Non è un documento di
progettazione tecnica (quella è nel piano e nei commenti del codice stesso) —
è pensato per essere consultato al momento di eseguire il controllo, anche da
chi non ha seguito lo sviluppo di questa Tappa nel dettaglio.

## Cosa fa lo script

`tools/check-giacenze-lotti.php` confronta, per ogni prodotto a lotti
(`traccia_lotti = 1`), l'aggregato `giacenze.quantita_disponibile` con la
somma di `lotti_giacenze.quantita_disponibile` dei suoi lotti, per ogni
magazzino. Le due fonti dovrebbero SEMPRE coincidere — da quando
`App\Services\GiacenzaLottoService` (Tappa 3 di questo piano) è diventato
l'unico punto che scrive entrambe insieme, e da quando l'Inventario Fisico
(Tappa 4) impedisce di gonfiare l'aggregato senza creare un lotto vero.

Segnala anche giacenze negative (aggregate o per singolo lotto), come
controllo di sanità generale.

**È di SOLA LETTURA: non scrive, non corregge, non modifica mai nulla nel
database.** Si limita a stampare le anomalie trovate (se ce ne sono) ed
esce con un codice diverso da zero in quel caso — così può essere usato
anche come controllo automatico "va tutto bene sì/no" da un altro script,
se in futuro si deciderà di integrarlo in una pipeline.

## Come si esegue

Da riga di comando, dalla cartella del progetto:

```
php tools/check-giacenze-lotti.php
```

Non servono parametri. Lo script si connette al database reale
(`C:\Quack_ERP_data\db\database.sqlite`, tramite lo stesso bootstrap di
`includes/config.php`/`includes/db.php` usato da tutta l'applicazione — non
serve nessuna configurazione aggiuntiva).

**Uscita "tutto a posto":**
```
=== Controllo coerenza giacenze/lotti — 2026-08-14 16:00:00 ===
(sola lettura — nessuna correzione automatica; vedi il runbook per come intervenire)

Nessuna anomalia trovata.
```
(exit code 0)

**Uscita con anomalie**, una riga di intestazione (tipo, SKU, nome prodotto,
magazzino) più una riga di dettaglio per ciascuna:
```
[aggregato_senza_lotti] SKU ACD-001 — Acido Cloridrico 33% (prodotto #42, magazzino "Milano" #1)
    giacenza aggregata 15.000, somma lotti 0.000, differenza +15.000

Totale anomalie: 1
```
(exit code 1)

## Chi lo esegue, e quando

In questa fase **non è schedulato per l'esecuzione automatica** (nessun cron,
nessun trigger) — è uno strumento diagnostico da lanciare a mano. Chi
amministra l'applicazione (oggi: Michel/chi ha accesso diretto al server
XAMPP) dovrebbe eseguirlo:

- **Dopo qualunque intervento manuale sul database** (query dirette,
  correzioni a mano, import/export, ripristino da backup) — è esattamente il
  tipo di intervento che può rompere la simmetria aggregato/lotti senza che
  l'applicazione se ne accorga.
- **Periodicamente, come controllo di routine** (es. una volta a settimana,
  o dopo una giornata di inventario fisico intenso) — anche senza un motivo
  specifico per sospettare un problema, è un controllo economico (sola
  lettura, nessun impatto sulle prestazioni oltre la durata dell'esecuzione)
  da poter fare con regolarità.
- **Se si nota un comportamento anomalo in produzione** riconducibile a
  giacenze — es. un caso simile a TEST-BROWSER-A (aggregato che sembra
  "sballato" rispetto ai lotti visibili nella UI) — come primo passo
  diagnostico, prima di intervenire manualmente sul dato.
- **Dopo un aggiornamento del codice** che tocca il dominio giacenze/lotti
  (nuove Tappe di questo stesso piano, o modifiche future non pianificate
  qui), come controllo di non-regressione sui dati reali — complementare
  alle suite automatiche in `tests/manual/`, che verificano il CODICE ma non
  possono vedere lo stato reale del database di produzione.

## Cosa fare se trova anomalie

Lo script **non corregge nulla di proposito** — la scelta di come intervenire
va sempre fatta da una persona, guardando il caso specifico, non da uno
script automatico che potrebbe applicare la correzione sbagliata (es.
"fidarsi" dell'aggregato quando in realtà è il dato dei lotti quello
corrotto, o viceversa).

Per ogni anomalia segnalata:

1. **Non modificare subito il database.** Prima annotare (screenshot o copia
   dell'output dello script) lo stato trovato — utile sia per capire la
   causa sia come riferimento se la correzione dovesse rivelarsi sbagliata.
2. **Capire la causa prima di correggere**, se possibile:
   - `aggregato_senza_lotti` — l'aggregato ha un valore ma il prodotto non
     ha nessun lotto (in quel magazzino) a supporto. Prima della Tappa 4 di
     questo piano, era il sintomo tipico di un caso come TEST-BROWSER-A
     (rettifica da inventario su un prodotto a lotti senza ancora nessun
     lotto). Se il caso è successivo alla consegna della Tappa 4, indica
     probabilmente un intervento manuale sul DB, o un percorso di scrittura
     non passato da `GiacenzaLottoService` — da investigare nel codice
     prima di correggere il dato, altrimenti l'anomalia si ripresenterà.
   - `disallineamento_aggregato_lotti` — entrambi i lati hanno un valore, ma
     diverso. Controllare lo storico movimenti (`movimenti_magazzino`) per
     quel prodotto/magazzino per capire quale dei due lati riflette
     l'ultima operazione reale.
   - `lotti_senza_giacenza_aggregata` — il caso opposto: i lotti hanno
     giacenza ma manca la riga aggregata. Verificare se la riga `giacenze`
     sia stata cancellata per errore, o se il prodotto sia stato
     disattivato/riattivato senza passare dal percorso applicativo corretto.
   - `giacenza_aggregata_negativa` / `giacenza_lotto_negativa` — non
     dovrebbe mai accadere; se capita, prima di correggere il segno cercare
     nello storico movimenti quale operazione ha portato il valore sotto
     zero, perché è probabile che indichi un buco di validazione da
     sistemare nel codice, non solo un dato da aggiustare una tantum.
3. **Correggere il dato manualmente**, solo dopo aver capito la causa,
   allineando il lato sbagliato all'altro (o ricostruendo entrambi da
   `movimenti_magazzino` se nessuno dei due è affidabile) — con
   l'applicazione chiusa o comunque senza operazioni concorrenti su quel
   prodotto/magazzino nel frattempo.
4. **Rilanciare lo script** dopo la correzione, per confermare che
   l'anomalia sia sparita e che non ne siano comparse altre.
5. Se la causa individuata al punto 2 è un bug applicativo (non un semplice
   incidente una tantum), segnalarlo perché venga corretto nel codice — un
   dato corretto a mano senza risolvere la causa si ripresenterà.

## Copertura di test

`tests/manual/suite_check_giacenze_lotti_tappa5.php` verifica la logica
dello script (5 scenari: stato coerente, i tre tipi di disallineamento,
giacenza negativa aggregata e di lotto) contro un database SQLite in-memory
con dati di prova — non contro il database reale. Vedi i commenti in cima a
quella suite per una nota sul perché il bootstrap CLI vero e proprio
(connessione al database reale) non è verificabile automaticamente
dall'ambiente di sviluppo di Claude, e va quindi testato una prima volta a
mano sulla macchina reale dopo ogni consegna che tocca questo file.
