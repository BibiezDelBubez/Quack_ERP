<?php
$content = <<<EOF

> **Referto Audit: `assets/js/app.js`**
> **Stato (13/07/2026): BUG NELLA FORMATTAZIONE VALUTA E GESTIONE ERRORI SILENTE.**

> **Segnalazione #75: parseFloat su Valute e Catch Silenti**
> * **Spiega in modo chiaro e semplice quale è il problema e perché lo è:**
>   Il file Javascript globale dell app contiene una funzione critica, qkFormatEuro, usata per mostrare a video i prezzi. Questa funzione usa parseFloat(n) per convertire il valore in numero. In Italia i decimali si scrivono con la virgola (es. "1.234,56"). Se l input e gia una stringa formattata all italiana, parseFloat si ferma al punto o lo interpreta male e distrugge il prezzo reale a video. Inoltre, il salvataggio del tema UI usa un .catch(function () {}); totalmente silente: se l utente perde la connessione mentre cambia tema, l app fallisce senza dire nulla.
> * **Cosa prevede in questo momento il codice app:**
>   La funzione qkFormatEuro (riga 471) usa parseFloat(n) senza normalizzare virgole o punti delle migliaia. Alle righe 215 e 245 ci sono .catch con funzione vuota per le chiamate fetch.
> * **Cosa fanno i grandi ERP in proposito:**
>   Per la formattazione della valuta, si passano sempre numeri puri dal backend all interfaccia (es. attributo data-price="1234.56") e solo in quel momento si convertono, oppure si normalizzano le stringhe prima del casting.
> * **Quale sarebbe una soluzione professionale:**
>   Riscrivere qkFormatEuro affinche normalizzi prima le stringhe italiane (sostituisca il punto delle migliaia con vuoto e la virgola decimale con il punto standard), oppure pretendere nativamente un tipo Number. Eliminare i catch silenti per la fetch.
EOF;

file_put_contents("C:/Users/michel.papetti/Documents/xampp/htdocs/Quack/audit/audit-journal.md", $content, FILE_APPEND);
$text = file_get_contents("C:/Users/michel.papetti/Documents/xampp/htdocs/Quack/audit/audit-journal.md");
$text = str_replace("- [ ] \`assets/js/app.js\`: DRY (Logiche duplicate?)", "- [x] \`assets/js/app.js\`: DRY (Logiche duplicate?)", $text);
$text = str_replace("- [ ] \`assets/js/app.js\`: Try..catch vuoti o silenti?", "- [x] \`assets/js/app.js\`: Try..catch vuoti o silenti?", $text);
$text = str_replace("- [ ] \`assets/js/app.js\`: Tipo Dati (Float per valuta trattati male, rischio \r\narrotondamenti?)", "- [x] \`assets/js/app.js\`: Tipo Dati (Float per valuta trattati male, rischio \r\narrotondamenti?)", $text);
$text = str_replace("- [ ] \`assets/js/app.js\`: Tipo Dati (Float per valuta trattati male, rischio \narrotondamenti?)", "- [x] \`assets/js/app.js\`: Tipo Dati (Float per valuta trattati male, rischio \narrotondamenti?)", $text);
file_put_contents("C:/Users/michel.papetti/Documents/xampp/htdocs/Quack/audit/audit-journal.md", $text);

