<?php
/**
 * Migration: rimuove la tabella legacy `sostanze_chimiche_schede` e i file
 * fisici SDS orfani in `UPLOAD_DIR/sds/*`.
 *
 * Contesto (PIANO - Refactoring Schede di Sicurezza (SDS) su Prodotto-Fornitore.md,
 * M4): la gestione delle SDS è stata spostata su `prodotti_fornitori_schede_sicurezza`
 * (§3.7, migrazione 2026_07_22_schede_sicurezza_prodotto_fornitore.php). Questa
 * migrazione fa la parte "distruttiva" descritta in §3.6-bis del piano — va
 * eseguita SOLO dopo che il codice legacy che leggeva/scriveva questa tabella
 * (SostanzeChimicheController::uploadScheda/deleteScheda/downloadScheda/loadSchede,
 * le relative route in app/routes.php, e le viste app/views/adr/sostanze/_form.php
 * e _list.php) è già stato rimosso — altrimenti la pagina /adr/sostanze/edit
 * romperebbe con un errore fatale ogni volta che tocca le SDS, per tutto il
 * tempo che intercorre tra le due modifiche. Verificato in questa stessa
 * sessione (22/07/2026) che il codice legacy è già stato rimosso prima di
 * eseguire questa migrazione.
 *
 * Conferma utente (§3.1 del piano, 22/07/2026): i dati in questa tabella sono
 * dati di test, non dati reali — nessuna migrazione dati necessaria prima del
 * DROP.
 *
 * Idempotente: verifica l'esistenza della tabella prima di droppare; la
 * pulizia file è sicura da rieseguire (unlink su file già assenti è un no-op).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

function qk_mig_drop_sds_table_exists(PDO $pdo, string $table): bool
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = ?");
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    }
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

if (qk_mig_drop_sds_table_exists($pdo, 'sostanze_chimiche_schede')) {
    // Pulizia fisica dei file PRIMA del drop (mentre possiamo ancora leggere i file_path)
    $righe = $pdo->query('SELECT file_path FROM sostanze_chimiche_schede')->fetchAll(PDO::FETCH_ASSOC);
    $rimossi = 0;
    foreach ($righe as $r) {
        $rel = ltrim(str_replace('\\', '/', (string) $r['file_path']), '/');
        if ($rel === '') {
            continue;
        }
        $abs = rtrim(UPLOAD_DIR, '/\\') . '/' . $rel;
        if (is_file($abs) && @unlink($abs)) {
            $rimossi++;
        }
    }
    echo "  [OK] {$rimossi} file fisici SDS legacy rimossi da uploads/sds/*\n";

    $pdo->exec('DROP TABLE sostanze_chimiche_schede');
    echo "  [OK] tabella sostanze_chimiche_schede rimossa\n";

    // La cartella uploads/sds/ può restare (potrebbero esserci sottocartelle vuote
    // residue) — non è pericoloso lasciarla, 'sds' resta comunque nella whitelist
    // di FileController::ALLOWED_DIRS per compatibilità con eventuali link salvati
    // altrove in passato, ma nessun codice attuale ci scrive più.
    echo "\nMigrazione completata.\n";
} else {
    echo "  [--] tabella sostanze_chimiche_schede già assente, skip\n";
    echo "\nNessuna modifica necessaria. Schema già aggiornato.\n";
}
