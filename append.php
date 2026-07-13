<?php
$content = <<<EOF

> **Referto Audit: `includes/purchases_receipts.php`**
> **Stato (13/07/2026): BUG MATEMATICO E VIOLAZIONE ARCHITETTURALE.**

> **Segnalazione #72: Deriva della Precisione sui Carichi di Magazzino (Multi-UoM)**
> * **Spiega in modo chiaro e semplice quale è il problema e perché lo è:**
>   Quando il magazziniere conferma l arrivo della merce, il gestionale deve convertire l unita di misura. Nel file c e un grosso avviso in maiuscolo scritto dallo sviluppatore senior: "ATTENZIONE: non usare MAI divisioni decimali per la conversione, usare SEMPRE la frazione numeratore/denominatore per evitare errori di arrotondamento". Incredibilmente, poche righe piu in basso, il codice ignora il suo stesso avviso e usa una divisione decimale (bcdiv). Questo significa che se un fornitore ci vende un prodotto a frazioni (es. "0.333 litri"), il gestionale calcolera 1 / 0.333 = 3.003 litri. Quei "0.003" litri di scarto sono inventario fantasma che si accumulera nel tempo, mandando fuori quadratura i bilanci.
> * **Cosa prevede in questo momento il codice app:**
>   La funzione qk_ricezione_conferma alla riga 360 esegue la conversione con quantita_stock = (float)bcdiv((string)qtaAccettata, (string)quantita_contenuto_snapshot, 3);. Questa riga viola la direttiva architetturale dettata alle righe 191-203.
> * **Cosa fanno i grandi ERP in proposito:**
>   Gli ERP garantiscono la coerenza algebrica dei magazzini (mass balance) gestendo ogni fattore di conversione come una Frazione Intera. Per passare dall unita commerciale a quella di stock si fa sempre: Quantita * (Denominatore / Numeratore). Nessuna divisione su numeri con virgola mobile (Float) e tollerata.
> * **Quale sarebbe una soluzione professionale:**
>   Eliminare la funzione bcdiv e riscrivere la conversione applicando la frazione esatta, come imposto dalle specifiche:
>   quantita_stock = round(qtaAccettata * den_factor_snapshot / num_factor_snapshot, 3);

> **Referto Audit: `includes/purchases_rfq.php`**
> **Stato (13/07/2026): ROTTURA DELLA CONTINUITA DOCUMENTALE.**

> **Segnalazione #73: Perdita del Riferimento Numerico in Revisione RFQ**
> * **Spiega in modo chiaro e semplice quale è il problema e perché lo è:**
>   Quando una Richiesta di Offerta (RFQ) viene inviata a un fornitore e poi si rende necessaria una modifica (es. vogliamo cambiare le quantita), usiamo la funzione "Crea nuova versione". Il problema e che il sistema "brucia" un nuovo numero di sequenza (es. genera la "RFQ/002") invece di mantenere lo stesso numero con il suffisso della versione (es. "RFQ/001 v2"). Questo crea un enorme confusione: il fornitore ricevera un documento con un numero completamente diverso, pensera che sia una richiesta separata e perdera il filo della negoziazione.
> * **Cosa prevede in questo momento il codice app:**
>   Nel metodo qk_rfq_nuova_versione alla riga 448, il codice chiama nuovoNumero = qk_next_numero_acquisto(pdo, "rfq");, staccando un nuovo ID progressivo globale, ignorando il baseNumero del documento originale.
> * **Cosa fanno i grandi ERP in proposito:**
>   Nelle procedure di contrattazione (Tender/RFQ), il numero di protocollo e sacro. I software come SAP Ariba o Coupa mantengono il protocollo radice identico per tutta la vita della negoziazione, incrementando solo un suffisso (v1, v2, v3 o -A, -B, -C). Questo garantisce la tracciabilita univoca della pratica.
> * **Quale sarebbe una soluzione professionale:**
>   Allineare la logica a quella gia parzialmente corretta in purchases_orders.php. Eliminare la chiamata a qk_next_numero_acquisto e calcolare il numero dinamicamente rimuovendo l eventuale suffisso precedente e accodando il nuovo:
>   baseNumero = preg_replace("/ v\d+$/", "", rfq["numero"]);
>   nuovoNumero = baseNumero . " v" . nuovaVersione;
EOF;

file_put_contents("C:/Users/michel.papetti/Documents/xampp/htdocs/Quack/audit/audit-journal.md", $content, FILE_APPEND);
$text = file_get_contents("C:/Users/michel.papetti/Documents/xampp/htdocs/Quack/audit/audit-journal.md");
$text = str_replace("- [ ] ``includes/purchases_receipts.php``", "- [x] ``includes/purchases_receipts.php``", $text);
$text = str_replace("- [ ] ``includes/purchases_rfq.php``", "- [x] ``includes/purchases_rfq.php``", $text);
file_put_contents("C:/Users/michel.papetti/Documents/xampp/htdocs/Quack/audit/audit-journal.md", $text);

