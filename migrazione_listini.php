<?php

require_once __DIR__ . '/app/bootstrap.php';

try {
    // Apriamo la transazione per la migrazione massiva
    $pdo->beginTransaction();

    echo "Inizio migrazione listini acquisto...\n";

    // 1. Troviamo tutti i prezzi orfani (senza listino_acquisto_id)
    $stmt = $pdo->query('
        SELECT 
            pfp.id AS prezzo_id,
            pf.fornitore_id,
            COALESCE(cf.ragione_sociale, TRIM(COALESCE(cf.nome, \'\') || \' \' || COALESCE(cf.cognome, \'\'))) AS fornitore_nome
        FROM prodotti_fornitori_prezzi pfp
        JOIN prodotti_fornitori pf ON pf.id = pfp.prodotto_fornitore_id
        JOIN clienti_fornitori cf ON cf.id = pf.fornitore_id
        WHERE pfp.listino_acquisto_id IS NULL
    ');
    
    $prezziOrfani = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($prezziOrfani)) {
        echo "Nessun prezzo orfano trovato. Il database è già normalizzato!\n";
        $pdo->commit();
        exit;
    }

    echo "Trovati " . count($prezziOrfani) . " prezzi orfani. Procedo con la normalizzazione...\n";

    $cacheListini = []; // cache per non interrogare il DB ad ogni riga

    $stmtCreateListino = $pdo->prepare("
        INSERT INTO listini_acquisto (fornitore_id, nome_listino, note) 
        VALUES (:fid, :nome, 'Listino generato automaticamente dalla migrazione per raccogliere i prezzi base.')
    ");

    $stmtUpdatePrezzo = $pdo->prepare("
        UPDATE prodotti_fornitori_prezzi 
        SET listino_acquisto_id = :listino_id 
        WHERE id = :prezzo_id
    ");

    $listiniCreati = 0;
    $prezziAggiornati = 0;

    foreach ($prezziOrfani as $riga) {
        $fornId = $riga['fornitore_id'];
        $fornNome = $riga['fornitore_nome'] ?: 'Sconosciuto';
        $nomeListino = "Condizioni Base - " . $fornNome;

        // Se non abbiamo ancora in cache l'ID del listino base per questo fornitore, lo cerchiamo o creiamo
        if (!isset($cacheListini[$fornId])) {
            $stmtCerca = $pdo->prepare("SELECT id FROM listini_acquisto WHERE fornitore_id = :fid AND nome_listino = :nome");
            $stmtCerca->execute([':fid' => $fornId, ':nome' => $nomeListino]);
            $listinoId = $stmtCerca->fetchColumn();

            if (!$listinoId) {
                // Creiamo il listino
                $stmtCreateListino->execute([':fid' => $fornId, ':nome' => $nomeListino]);
                $listinoId = (int)$pdo->lastInsertId();
                $listiniCreati++;
            }
            $cacheListini[$fornId] = $listinoId;
        }

        // Aggiorniamo il prezzo con il listino_id
        $stmtUpdatePrezzo->execute([
            ':listino_id' => $cacheListini[$fornId],
            ':prezzo_id' => $riga['prezzo_id']
        ]);
        $prezziAggiornati++;
    }

    $pdo->commit();
    echo "Migrazione completata con successo!\n";
    echo "- Listini base creati: {$listiniCreati}\n";
    echo "- Prezzi normalizzati: {$prezziAggiornati}\n";

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "ERRORE durante la migrazione: " . $e->getMessage() . "\n";
}
