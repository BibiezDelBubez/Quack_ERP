<?php
$pdo = new PDO('sqlite:C:/Users/michel.papetti/Documents/xampp/htdocs/Quack/db/database.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $pdo->exec('DROP TABLE IF EXISTS ricevimento_merce_righe');
    $pdo->exec('DROP TABLE IF EXISTS ricevimento_merce');
    $pdo->exec('DROP TABLE IF EXISTS schede_sicurezza');
    
    $pdo->exec('
CREATE TABLE IF NOT EXISTS prodotto_classificazioni_adr (
    id                          INTEGER      PRIMARY KEY AUTOINCREMENT,
    prodotto_id                 INTEGER      NOT NULL REFERENCES prodotti(id) ON DELETE CASCADE,
    codice_onu_id               INTEGER      NOT NULL REFERENCES adr_codici_onu(id),
    gruppo_imballaggio_override CHAR(3)      REFERENCES adr_gruppi_imballaggio(codice),
    nome_tecnico_obbligatorio   BOOLEAN      NOT NULL DEFAULT 0,
    nome_tecnico                VARCHAR(200),
    esenzione_quantita_limitata BOOLEAN      NOT NULL DEFAULT 0,
    esenzione_quantita_esente   BOOLEAN      NOT NULL DEFAULT 0,
    disposizioni_speciali       TEXT,
    e_corrente                  BOOLEAN      NOT NULL DEFAULT 1,
    data_classificazione        DATE         NOT NULL,
    classificatore_id           INTEGER      REFERENCES utenti(id),
    note                        TEXT
)
    ');
    
    echo 'Real DB fixed successfully!';
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}

