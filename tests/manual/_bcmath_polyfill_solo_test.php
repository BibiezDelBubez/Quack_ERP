<?php
declare(strict_types=1);

/**
 * tests/manual/_bcmath_polyfill_solo_test.php
 *
 * SOLO PER I TEST: l'estensione bcmath (bccomp/bcadd/bcsub/bcmul/bcdiv) non è
 * disponibile nel sandbox Linux in cui gira questa sessione (verificato:
 * assente da /usr/bin/php, non installabile via apt — i pacchetti php8.4-*
 * di questo ambiente vengono da un repository locale senza il modulo
 * bcmath disponibile per quella ABI, e non c'è accesso di rete a php.net
 * per compilarlo da sorgente — vedi CLAUDE.md punto 13, che prevede
 * esplicitamente questo caso: "se fallisce, tornare a una verifica solo
 * statica"). Il PC reale dell'utente (XAMPP) ha bcmath regolarmente
 * disponibile: questo file NON fa parte dell'applicazione, non va incluso
 * da nessun file sotto includes/ o app/, e serve solo a permettere
 * l'ESECUZIONE REALE (non solo la lettura statica) delle suite di test in
 * questo ambiente specifico.
 *
 * Implementazione: aritmetica decimale a virgola fissa via interi PHP
 * (scala per 10^precisione, opera su interi, riformatta) — non tramite
 * float grezzi, per evitare errori di arrotondamento anche se le grandezze
 * usate nei test restano comunque ben dentro il range sicuro di un float a
 * doppia precisione.
 *
 * Uso: require_once __DIR__ . '/_bcmath_polyfill_solo_test.php'; PRIMA di
 * richiedere qualunque file dell'applicazione che chiami funzioni bc*.
 */

if (!function_exists('bccomp')) {
    function _bcps_a_intero(string $numero, int $scala): int
    {
        $numero = trim($numero);
        $negativo = false;
        if ($numero !== '' && ($numero[0] === '-' || $numero[0] === '+')) {
            $negativo = $numero[0] === '-';
            $numero = substr($numero, 1);
        }
        if ($numero === '') {
            $numero = '0';
        }
        if (!str_contains($numero, '.')) {
            $numero .= '.';
        }
        [$parteIntera, $parteDecimale] = explode('.', $numero, 2);
        $parteIntera = $parteIntera === '' ? '0' : $parteIntera;
        $parteDecimale = str_pad(substr($parteDecimale, 0, $scala), $scala, '0');
        $valore = (int) ($parteIntera . $parteDecimale);
        return $negativo ? -$valore : $valore;
    }

    function _bcps_da_intero(int $valore, int $scala): string
    {
        $negativo = $valore < 0;
        $valore = abs($valore);
        $s = (string) $valore;
        if ($scala === 0) {
            return ($negativo ? '-' : '') . $s;
        }
        $s = str_pad($s, $scala + 1, '0', STR_PAD_LEFT);
        $parteIntera = substr($s, 0, strlen($s) - $scala);
        $parteDecimale = substr($s, strlen($s) - $scala);
        $risultato = $parteIntera . '.' . $parteDecimale;
        return ($negativo && $valore !== 0 ? '-' : '') . $risultato;
    }

    function bccomp(string $a, string $b, int $scale = 0): int
    {
        $ia = _bcps_a_intero($a, $scale);
        $ib = _bcps_a_intero($b, $scale);
        return $ia <=> $ib;
    }

    function bcadd(string $a, string $b, int $scale = 0): string
    {
        $scalaCalcolo = $scale + 4;
        $r = _bcps_a_intero($a, $scalaCalcolo) + _bcps_a_intero($b, $scalaCalcolo);
        return _bcps_da_intero(intdiv($r, (int) (10 ** 4)), $scale);
    }

    function bcsub(string $a, string $b, int $scale = 0): string
    {
        $scalaCalcolo = $scale + 4;
        $r = _bcps_a_intero($a, $scalaCalcolo) - _bcps_a_intero($b, $scalaCalcolo);
        return _bcps_da_intero(intdiv($r, (int) (10 ** 4)), $scale);
    }

    function bcmul(string $a, string $b, int $scale = 0): string
    {
        $scalaCalcolo = 6;
        $ia = _bcps_a_intero($a, $scalaCalcolo);
        $ib = _bcps_a_intero($b, $scalaCalcolo);
        $prodotto = $ia * $ib; // scala risultante: 2*$scalaCalcolo
        $fattoreRiduzione = (int) (10 ** (2 * $scalaCalcolo - $scale));
        return _bcps_da_intero(intdiv($prodotto, $fattoreRiduzione), $scale);
    }

    function bcdiv(string $a, string $b, int $scale = 0): string
    {
        $scalaCalcolo = $scale + 6;
        $ia = _bcps_a_intero($a, $scalaCalcolo);
        $ib = _bcps_a_intero($b, 0);
        if ($ib === 0) {
            throw new \DivisionByZeroError('bcdiv(): Argument #2 ($num2) is 0');
        }
        $q = intdiv($ia, $ib);
        return _bcps_da_intero(intdiv($q, (int) (10 ** ($scalaCalcolo - $scale))), $scale);
    }
}
