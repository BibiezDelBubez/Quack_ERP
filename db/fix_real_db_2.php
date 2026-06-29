<?php
$pdo = new PDO('sqlite:C:/Users/michel.papetti/Documents/xampp/htdocs/Quack/db/database.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $pdo->exec('DROP TABLE IF EXISTS prodotto_classificazioni_adr');
    
    $pdo->exec('
CREATE TABLE IF NOT EXISTS prodotto_classificazioni_adr (
      id                          INTEGER      PRIMARY KEY AUTOINCREMENT,
      prodotto_id                 INTEGER      NOT NULL REFERENCES prodotti(id) ON DELETE CASCADE,
      codice_onu_id               INTEGER      NOT NULL REFERENCES adr_codici_onu(id),
      concentrazione_min_pct      DECIMAL(5,2),
      concentrazione_max_pct      DECIMAL(5,2),
      punto_infiammabilita_c      DECIMAL(6,2),
      e_rifiuto                   BOOLEAN,
      e_stabilizzato              BOOLEAN,
      e_soluzione                 BOOLEAN,
      nome_tecnico                VARCHAR(200),
      gruppo_imballaggio_override CHAR(3)      REFERENCES adr_gruppi_imballaggio(codice),
      valido_dal                  DATE,
      valido_al                   DATE,
      e_corrente                  BOOLEAN,
      creato_da                   INTEGER      NOT NULL REFERENCES utenti(id),
      created_at                  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
)
    ');
    
    echo 'Real DB fixed successfully!';
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}

