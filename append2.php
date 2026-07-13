<?php
$content = <<<EOF

> **Referto Audit: `assets/js/adr-calcolatore.js`**
> **Stato (13/07/2026): GESTIONE ERRORI SILENTE E ARITMETICA IMPRECISA.**

> **Segnalazione #74: Dropdown Muto e Aritmetica Floating Point**
> * **Spiega in modo chiaro e semplice quale è il problema e perché lo è:**
>   Ci sono due problemi in questo file Javascript usato per calcolare i punti ADR (merci pericolose). Primo: quando l utente cerca un prodotto digitando il nome, il sistema interroga il server. Se il server da errore o cade la connessione, lo script nasconde la tendina dei risultati in totale silenzio (catch silente). L utente digita e non succede nulla, credendo che il prodotto non esista, invece il sistema e in errore. Secondo: il Javascript moltiplica i chili per i punti usando la matematica base (quantita * moltiplicatore). In JS, operazioni come 0.1 * 3 possono risultare 0.30000000000000004, sballando il conteggio esatto dei 1000 punti legali.
> * **Cosa prevede in questo momento il codice app:**
>   Alla riga 373 c e un .catch(() => { dd.style.display = "none"; }); che ingoia l errore dell API. Alle righe 106 e 87 si sommano e moltiplicano numeri a virgola mobile senza arrotondamenti preventivi.
> * **Cosa fanno i grandi ERP in proposito:**
>   Nei sistemi industriali, ogni chiamata AJAX/Fetch interrotta o fallita produce un "Toast" (messaggio a scomparsa) o un alert discreto ("Errore di connessione"). Inoltre, per i calcoli legali (come l ADR), i JS moderni usano librerie come big.js o perlomeno moltiplicano tutto per 1000, lavorano con interi e poi dividono alla fine, per eliminare il problema del Floating Point.
> * **Quale sarebbe una soluzione professionale:**
>   Sostituire il catch silente con una notifica UI (es. qk_notify("Errore", "error")). Rifattorizzare la funzione _puntiRiga per eseguire i calcoli con arrotondamento controllato.
EOF;

file_put_contents("C:/Users/michel.papetti/Documents/xampp/htdocs/Quack/audit/audit-journal.md", $content, FILE_APPEND);
$text = file_get_contents("C:/Users/michel.papetti/Documents/xampp/htdocs/Quack/audit/audit-journal.md");
$text = str_replace("- [ ] \`assets/js/adr-calcolatore.js\`: DRY (Logiche duplicate?)", "- [x] \`assets/js/adr-calcolatore.js\`: DRY (Logiche duplicate?)", $text);
$text = str_replace("- [ ] \`assets/js/adr-calcolatore.js\`: Try..catch vuoti o silenti?", "- [x] \`assets/js/adr-calcolatore.js\`: Try..catch vuoti o silenti?", $text);
$text = str_replace("- [ ] \`assets/js/adr-calcolatore.js\`: Tipo Dati (Float per valuta trattati male, rischio \r\narrotondamenti?)", "- [x] \`assets/js/adr-calcolatore.js\`: Tipo Dati (Float per valuta trattati male, rischio \r\narrotondamenti?)", $text);
$text = str_replace("- [ ] \`assets/js/adr-calcolatore.js\`: Tipo Dati (Float per valuta trattati male, rischio \narrotondamenti?)", "- [x] \`assets/js/adr-calcolatore.js\`: Tipo Dati (Float per valuta trattati male, rischio \narrotondamenti?)", $text);
file_put_contents("C:/Users/michel.papetti/Documents/xampp/htdocs/Quack/audit/audit-journal.md", $text);

