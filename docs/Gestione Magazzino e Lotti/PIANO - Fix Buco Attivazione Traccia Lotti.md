# Piano: chiudere il buco nel codice sulla tracciatura lotti

## Il problema in una frase

Il prodotto TEST-BROWSER-A ha giacenza (100 kg) senza nessun lotto dietro, e questo blocca la spedizione di un ordine reale. Il dato specifico di questo prodotto **non è più oggetto di questo piano** (non interessa sistemarlo): qui ci si concentra solo su **come è potuto succedere nel codice** e su **come chiuderlo**, così non ricapita su un prodotto vero con un cliente vero in attesa.

Il piano è organizzato per priorità. Nessun punto viene eseguito senza un tuo ok esplicito, punto per punto.

---

## Cosa succede oggi quando si spunta/despunta "Traccia lotti" su un prodotto

Prima di tutto, una buona notizia: l'app **ha già pensato** a entrambe le direzioni del cambio. Il problema non è "nessuno ci ha pensato", è che una delle due protezioni ha una falla verificabile nel codice.

### Direzione 1 — un prodotto CON lotti diventa SENZA lotti (traccia_lotti: 1 → 0)

**Bloccato correttamente.** In `app/Controllers/ProductsController.php`, righe 142-171, prima di salvare il cambio il sistema controlla se esiste anche un solo lotto di quel prodotto — in **qualsiasi** magazzino, non solo quello attivo — con giacenza ancora disponibile maggiore di zero. Se sì, rifiuta il salvataggio con un messaggio esplicito:

> "Impossibile disabilitare la tracciatura lotti: N lotti hanno ancora giacenza disponibile (totale: X). Azzera le giacenze di tutti i lotti prima di disabilitare la tracciatura."

Esempio concreto: hai un prodotto "Colla X" con 2 lotti, uno da 10 kg e uno da 0 kg (già esaurito). Provi a disattivare i lotti: il sistema vede il lotto da 10 kg ancora pieno e blocca. Spedisci/consumi quei 10 kg, il lotto arriva a 0, riprovi: ora nessun lotto ha più stock, il sistema permette il cambio.

**Cosa succede a un ordine già aperto su quel prodotto, se lo disattivi nel frattempo?** Niente di rotto. La schermata "Nuova Spedizione" legge il flag "traccia lotti" **dal vivo**, non un valore congelato al momento in cui l'ordine è stato creato (verificato in `includes/sales_orders.php`, riga 968). Quindi la riga d'ordine passerà semplicemente a "senza lotti" e userà la giacenza aggregata, che nel frattempo resta comunque corretta. Sicuro.

### Direzione 2 — un prodotto SENZA lotti diventa CON lotti (traccia_lotti: 0 → 1)

**Qui c'è un meccanismo automatico dedicato — ma con un buco reale, non ipotetico.**

Quando attivi il flag su un prodotto che ha già giacenza (es. un prodotto che vendevi "a peso" senza lotti, e da oggi vuoi tracciarlo a lotti), l'app non lascia quella giacenza orfana: esiste già una funzione, `qk_crea_lotti_conversione_iniziale()` (`includes/magazzino.php`, righe 1218-1264), che crea **in automatico** un lotto "di conversione" per ogni magazzino dove il prodotto ha stock, con nome tipo `INIZIALE-1-20260814`, che assorbe tutta la quantità esistente. Esempio: prodotto con 50 kg nel Magazzino Principale e 20 kg nel Magazzino Secondario, zero lotti. Attivi la tracciatura → il sistema crea da solo 2 lotti "INIZIALE-...", uno da 50 nel primo magazzino e uno da 20 nel secondo. Da quel momento il prodotto è utilizzabile normalmente nelle spedizioni a lotti, senza che nessuno abbia dovuto inserire nulla a mano.

Fin qui, sembra la soluzione perfetta — è letteralmente il meccanismo che serve per evitare il caso TEST-BROWSER-A. Il problema è **come è collegato** al salvataggio del prodotto: ho verificato che sono **due operazioni sul database separate**, non una sola operazione indivisibile:

1. `qk_update_prodotto()` (`includes/prodotti.php`) salva il prodotto — apre la propria transazione a riga 1037 e la **chiude definitivamente (commit)** a riga 1134. A questo punto `traccia_lotti = 1` è già scritto per sempre nel database.
2. **Solo dopo**, il controller chiama `qk_crea_lotti_conversione_iniziale()`, che apre e chiude **una seconda transazione, indipendente dalla prima** (`magazzino.php`, riga 1229).

Se il passo 2 fallisce per qualunque motivo, il passo 1 **non torna indietro**. Risultato: prodotto con `traccia_lotti = 1`, giacenza aggregata presente, zero lotti — esattamente lo stato di TEST-BROWSER-A. Raggiunto però cliccando normalmente nell'interfaccia, non manomettendo il database.

**E il passo 2 può davvero fallire, non solo in teoria.** Il nome del lotto generato è `INIZIALE-{magazzino}-{data di oggi}` (riga 1246 di `magazzino.php`), e nel database esiste un indice che vieta due lotti con lo stesso codice per lo stesso prodotto (`db/init_sqlite.sql`, riga 1660: `UNIQUE INDEX ... ON lotti(prodotto_id, codice_lotto_interno)`). Se lo **stesso prodotto** passa da questa conversione **due volte nello stesso giorno**, la seconda volta il nome del lotto è identico al primo (`INIZIALE-1-20260814` due volte) e l'inserimento viene respinto dal database.

Esempio concreto di come ci si arriva, in una giornata di lavoro normale:

1. Mattina: attivi "traccia lotti" su un prodotto con 50 kg in giacenza → si crea il lotto `INIZIALE-1-20260814` da 50 kg. Tutto bene.
2. Nel pomeriggio spedisci tutto: quel lotto arriva a 0 kg.
3. Disattivi "traccia lotti" (permesso: il lotto è a zero, vedi Direzione 1 sopra).
4. Arriva nuova merce nel pomeriggio, la carichi normalmente (senza lotti, visto che ora il prodotto non li traccia): giacenza aggregata torna a, es., 30 kg.
5. Fine giornata: qualcuno riattiva "traccia lotti" sullo stesso prodotto (magari per errore, o perché nel frattempo è arrivata una richiesta di tracciabilità). Il sistema prova a creare di nuovo `INIZIALE-1-20260814` — stesso codice di stamattina, stesso prodotto → il database rifiuta l'inserimento, la conversione fallisce, ma il flag è già salvato da un pezzo. Da qui in poi quel prodotto è "a lotti" con 30 kg fantasma, zero lotti veri.

Questa sequenza (attiva/disattiva/riattiva più volte nello stesso giorno sullo stesso prodotto) è esattamente il tipo di comportamento tipico di un test automatizzato via browser che prova ripetutamente lo stesso scenario — coerente con l'ipotesi che il nome "TEST-BROWSER-A" non sia un caso.

**Perché conta anche per un prodotto vero, non solo per i test:** dato che la spedizione legge il flag "dal vivo" (vedi Direzione 1), se qualcuno attiva la tracciatura lotti su un prodotto che ha **in questo momento un ordine cliente reale già aperto e non ancora spedito**, e la conversione fallisce per il motivo sopra, quell'ordine reale si blocca con lo stesso identico errore visto su TEST-BROWSER-A — nel momento peggiore possibile, cioè mentre l'operatore sta cercando di evaderlo.

---

## Cosa fanno gli ERP "seri" in questo punto esatto

In SAP, Dynamics 365 e NetSuite, l'attivazione della gestione lotti su un materiale con giacenza esistente e la conversione di quella giacenza in lotti sono **la stessa identica operazione, indivisibile**: o va a buon fine tutta, o non cambia nulla. Non esiste uno stato intermedio in cui il materiale "è già a lotti" ma la sua giacenza storica non lo è ancora. È lo stesso principio — tutto o niente — che manca qui tra il salvataggio del prodotto e la creazione dei lotti di conversione.

---

## Interventi proposti, in ordine di priorità

**B.0 — (Nuovo, priorità più alta) Rendere indivisibili salvataggio del prodotto e conversione dei lotti.**
Le due operazioni in `ProductsController::edit()` (il salvataggio via `qk_update_prodotto()` e la chiamata a `qk_crea_lotti_conversione_iniziale()` subito dopo) devono girare dentro un'unica transazione: se la conversione fallisce, anche il cambio del flag `traccia_lotti` deve tornare indietro, non restare a metà. In più, il nome del lotto generato in automatico (`INIZIALE-{magazzino}-{data}`) va reso univoco anche in caso di attivazioni ripetute lo stesso giorno (basta aggiungere un contatore o l'ora esatta al nome, es. `INIZIALE-1-20260814-153042`), così la collisione che ho descritto sopra non può più verificarsi.

**B.1 — Inventario Fisico: permettere di creare un lotto nuovo durante il conteggio.**
Verificato separatamente (vedi conversazione precedente): oggi, se un prodotto a lotti ha zero lotti e un operatore prova a "sistemarlo" tramite un conteggio fisico, il sistema raddoppia l'errore invece di correggerlo, perché non sa creare un lotto al volo durante la convalida. Va aggiunta questa possibilità, riusando la stessa logica già scritta per "Nuovo movimento → Carico → nuovo lotto".

**B.2 — Script di controllo coerenza (rete di sicurezza, rischio basso, economico da fare subito).**
Uno script — sul modello di `tools/check-utf8.php` già presente nel progetto — che confronta, per ogni prodotto a lotti e ogni magazzino, la giacenza aggregata con la somma delle giacenze dei lotti, e segnala le differenze:

```sql
SELECT p.sku_interno, p.nome, g.magazzino_id,
       g.quantita_disponibile AS aggregato,
       COALESCE(SUM(lg.quantita_disponibile), 0) AS somma_lotti
FROM prodotti p
JOIN giacenze g ON g.prodotto_id = p.id
LEFT JOIN lotti l ON l.prodotto_id = p.id
LEFT JOIN lotti_giacenze lg ON lg.lotto_id = l.id AND lg.magazzino_id = g.magazzino_id
WHERE p.traccia_lotti = 1
GROUP BY p.id, g.magazzino_id
HAVING ABS(aggregato - somma_lotti) > 0.0005;
```

Anche con B.0 e B.1 applicati, questo resta utile: è la rete che intercetta qualunque altra causa di disallineamento non ancora identificata, subito invece che mesi dopo davanti a un cliente in attesa.

**B.3 — (Più ampio, da pianificare a parte, non urgente) Rendere l'aggregato "di sola lettura" per i prodotti a lotti.**
B.0, B.1 e B.2 chiudono i buchi conosciuti oggi. Per essere sicuri al 100% che nessun futuro pezzo di codice (uno script, un'importazione, una funzione scritta senza conoscere queste regole) possa ricreare lo stesso problema per una via non ancora vista, servirebbe che il numero mostrato in "Giacenze" per un prodotto a lotti non sia più un valore salvato a sé, ma sempre calcolato dalla somma dei lotti — come fa Dynamics 365. Cambiamento architetturale più ampio, da valutare con calma a parte.

---

## Riepilogo — cosa serve da te

| # | Cosa | Tocca | Urgenza | Perché |
|---|---|---|---|---|
| B.0 | Unire in una transazione sola salvataggio prodotto + conversione lotti, e rendere univoco il nome del lotto generato | Codice (`ProductsController.php`, `prodotti.php`, `magazzino.php`) | **Alta** — può bloccare un ordine reale, non solo un test | Chiude il meccanismo esatto che ha (probabilmente) creato il caso TEST-BROWSER-A |
| B.1 | Permettere di creare un lotto durante l'Inventario Fisico | Codice (`inventario.php`, form) | Media | Oggi lo strumento pensato per correggere questi casi li aggrava |
| B.2 | Script di controllo coerenza aggregato/lotti | Codice nuovo, nessun impatto sull'esistente | Bassa, ma economico da fare | Rete di sicurezza per cause non ancora note |
| B.3 | Rendere l'aggregato "sola lettura" per i prodotti a lotti | Architettura, più ampio | Bassa, da pianificare a parte | Protezione definitiva, non urgente |

Dimmi quali punti approvi e su quali iniziare.
