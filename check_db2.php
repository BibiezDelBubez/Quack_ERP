<?php
// Script di debug: legge le colonne di "prodotti" dal DB attivo.
// Usa lo stesso bootstrap dell'app (config.php + db.php) invece di un path
// hardcoded, così segue automaticamente DB_DIR / DB_DRIVER.
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

print_r(db_table_columns($pdo, 'prodotti'));
