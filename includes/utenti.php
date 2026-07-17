<?php
declare(strict_types=1);

/**
 * Funzioni Helper per la tabella utenti
 */

/**
 * Recupera i dati di un utente tramite ID.
 *
 * @param PDO $pdo
 * @param int $id
 * @return array|null
 */
function get_utente_by_id(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT u.id, u.username, u.nome, u.cognome, u.email, u.password_hash, r.codice AS ruolo, u.attivo, u.created_at
           FROM utenti u
           JOIN ruoli r ON r.id = u.ruolo_id
          WHERE u.id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    return $res ?: null;
}

/**
 * Recupera tutti gli utenti filtrati.
 *
 * @param PDO $pdo
 * @param string $search
 * @param string $ruolo
 * @return array
 */
function get_all_utenti(PDO $pdo, string $search = '', string $ruolo = ''): array
{
    $where  = 'WHERE 1=1';
    $params = [];
    if ($search !== '') {
        $where .= ' AND (nome||" "||cognome LIKE :q OR email LIKE :q)';
        $params[':q'] = '%' . $search . '%';
    }
    if ($ruolo !== '') {
        $where .= ' AND r.codice = :ruolo';
        $params[':ruolo'] = $ruolo;
    }

    $stmt = $pdo->prepare(
        "SELECT u.id, u.username, u.nome, u.cognome, u.email, r.codice AS ruolo, u.attivo, u.created_at
           FROM utenti u
           JOIN ruoli r ON r.id = u.ruolo_id
         $where
         ORDER BY u.cognome, u.nome"
    );
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Inserisce un nuovo utente (lato admin).
 *
 * @param PDO $pdo
 * @param array $dati
 * @param string $hash
 * @return bool
 */
function insert_utente(PDO $pdo, array $dati, string $hash): bool
{
    $stmt = $pdo->prepare(
        'INSERT INTO utenti (username,nome,cognome,email,password_hash,ruolo_id,attivo)
         VALUES (:u,:n,:c,:e,:h,(SELECT id FROM ruoli WHERE codice=:r),:a)'
    );
    return $stmt->execute([
        ':u' => $dati['username'],
        ':n' => $dati['nome'],
        ':c' => $dati['cognome'],
        ':e' => $dati['email'],
        ':h' => $hash,
        ':r' => $dati['ruolo'],
        ':a' => (int) $dati['attivo'],
    ]);
}

/**
 * Aggiorna un utente esistente (lato admin).
 *
 * @param PDO $pdo
 * @param int $id
 * @param array $dati
 * @param string|null $hash Se non null, aggiorna anche la password
 * @return bool
 */
function update_utente_admin(PDO $pdo, int $id, array $dati, ?string $hash = null): bool
{
    $base_params = [
        ':u'  => $dati['username'],
        ':n'  => $dati['nome'],
        ':c'  => $dati['cognome'],
        ':e'  => $dati['email'],
        ':r'  => $dati['ruolo'],
        ':a'  => (int) $dati['attivo'],
        ':id' => $id,
    ];

    if ($hash !== null && $hash !== '') {
        $stmt = $pdo->prepare(
            'UPDATE utenti
                SET username=:u, nome=:n, cognome=:c, email=:e,
                    ruolo_id=(SELECT id FROM ruoli WHERE codice=:r),
                    attivo=:a, password_hash=:h
              WHERE id=:id'
        );
        return $stmt->execute($base_params + [':h' => $hash]);
    } else {
        $stmt = $pdo->prepare(
            'UPDATE utenti
                SET username=:u, nome=:n, cognome=:c, email=:e,
                    ruolo_id=(SELECT id FROM ruoli WHERE codice=:r),
                    attivo=:a
              WHERE id=:id'
        );
        return $stmt->execute($base_params);
    }
}

/**
 * Abilita o disabilita un utente.
 *
 * @param PDO $pdo
 * @param int $id
 * @param int $attivo 1 o 0
 * @return bool
 */
function set_stato_utente(PDO $pdo, int $id, int $attivo): bool
{
    $stmt = $pdo->prepare('UPDATE utenti SET attivo=:a WHERE id=:id');
    return $stmt->execute([':a' => $attivo, ':id' => $id]);
}

/**
 * Elimina definitivamente un utente dal sistema.
 *
 * @param PDO $pdo
 * @param int $id
 * @return bool
 */
function delete_utente(PDO $pdo, int $id): bool
{
    $stmt = $pdo->prepare('DELETE FROM utenti WHERE id=:id');
    return $stmt->execute([':id' => $id]);
}

/**
 * Aggiorna anagrafica utente (nome, cognome, email) [Profilo].
 */
function update_dati_utente(PDO $pdo, int $id, array $dati): bool
{
    $stmt = $pdo->prepare('UPDATE utenti SET nome=:n, cognome=:c, email=:e WHERE id=:id');
    return $stmt->execute([
        ':n'  => $dati['nome'],
        ':c'  => $dati['cognome'],
        ':e'  => $dati['email'],
        ':id' => $id,
    ]);
}

/**
 * Aggiorna l'hash della password di un utente [Profilo].
 */
function update_password_utente(PDO $pdo, int $id, string $hash): bool
{
    $stmt = $pdo->prepare('UPDATE utenti SET password_hash=:h WHERE id=:id');
    return $stmt->execute([
        ':h'  => $hash,
        ':id' => $id,
    ]);
}
