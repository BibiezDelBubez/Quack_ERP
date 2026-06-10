<?php
declare(strict_types=1);
/**
 * includes/purchases_common.php
 * Utility condivise dal ciclo acquisti: generazione numeri documento.
 * Incluso da purchases_rfq.php, purchases_quotes.php, ecc.
 */

/**
 * Genera e salva il prossimo numero documento per il tipo dato.
 * Usa la tabella `sequenze`; gestisce il reset annuale automaticamente.
 *
 * @param string $tipo  'rfq' | 'offerta' | 'ordine' | 'ricezione'
 * @return string  es. "RFQ/2026/0001"
 * @throws RuntimeException se la sequenza non esiste
 */
function qk_next_numero_acquisto(PDO $pdo, string $tipo): string
{
    return qk_next_sequence_number($pdo, $tipo);
}

/**
 * Anteprima del prossimo numero senza incrementare il contatore.
 * Usato solo per mostrare l'anteprima nel form prima del salvataggio.
 */
function qk_peek_numero_acquisto(PDO $pdo, string $tipo): string
{
    $anno = (int) date('Y');
    $st   = $pdo->prepare('SELECT prefisso, anno, numero_corrente, padding FROM sequenze WHERE tipo = ?');
    $st->execute([$tipo]);
    $seq  = $st->fetch(PDO::FETCH_ASSOC);
    if (!$seq) return '';

    $currentAnno = (int) $seq['anno'];
    $next        = ($currentAnno !== $anno) ? 1 : (int) $seq['numero_corrente'] + 1;
    $pad         = (int) $seq['padding'];

    return $seq['prefisso'] . $anno . '/' . str_pad((string) $next, $pad, '0', STR_PAD_LEFT);
}
