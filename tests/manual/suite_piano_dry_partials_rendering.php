<?php
declare(strict_types=1);

/**
 * tests/manual/suite_piano_dry_partials_rendering.php
 *
 * PIANO - Correzione DRY-MVC Viste Documenti Vendita e Acquisto.md, §5.1
 * (Livello 1 — rendering dei partial in isolamento, nessun HTTP).
 *
 * Verifica che components/modal_quick_cliente.php e
 * components/modal_quick_prodotto.php, dati gli stessi parametri passati da
 * sales/orders/_create.php e sales/quotes/_form.php, riproducano esattamente
 * l'HTML che quei due file emettevano PRIMA dell'estrazione (Fase 1,
 * 12/08/2026) — non un confronto "sembra giusto", un assert puntuale per
 * ogni id/attributo/opzione che i due chiamanti referenziano davvero nel
 * loro JS (verificato con grep incrociato su assets/js in questa sessione:
 * solo sales-orders-create.js e sales-quotes.js).
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php'; // e() usata da modal_quick_prodotto.php

$pass = 0;
$fail = 0;

function chk(string $label, bool $condition): void
{
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "  OK  - $label\n";
    } else {
        $fail++;
        echo "FAIL  - $label\n";
    }
}

function renderModal(string $partial, array $vars): string
{
    extract($vars);
    ob_start();
    require $partial;
    return (string) ob_get_clean();
}

$COMPONENTS = APP_ROOT_DIR . '/app/views/components';

echo "=== 1. modal_quick_cliente.php — variante 'ord-' (sales/orders/_create.php) ===\n";
$htmlOrd = renderModal($COMPONENTS . '/modal_quick_cliente.php', [
    'qkMncIdPrefix' => 'ord-',
    'qkMncTitleId'  => null,
]);
chk("id modale = ord-modal-nuovo-cliente", str_contains($htmlOrd, 'id="ord-modal-nuovo-cliente"'));
chk("nessun modal-nuovo-cliente senza prefisso (nessun leak dalla variante quotes)", !str_contains($htmlOrd, 'id="modal-nuovo-cliente"'));
chk("campo ragione sociale = ord-mnc-ragione-sociale", str_contains($htmlOrd, 'id="ord-mnc-ragione-sociale"'));
chk("radio azienda = ord-rc-azienda", str_contains($htmlOrd, 'id="ord-rc-azienda"'));
chk("radio privato = ord-rc-privato", str_contains($htmlOrd, 'id="ord-rc-privato"'));
chk("form = ord-form-nuovo-cliente", str_contains($htmlOrd, 'id="ord-form-nuovo-cliente"'));
chk("bottone salva = ord-btn-salva-cliente", str_contains($htmlOrd, 'id="ord-btn-salva-cliente"'));
chk("qkMncTitleId=null -> nessun aria-labelledby (comportamento originale invariato)", !str_contains($htmlOrd, 'aria-labelledby'));
chk("id univoco (nessuna duplicazione accidentale del partial)", substr_count($htmlOrd, 'id="ord-mnc-ragione-sociale"') === 1);

echo "\n=== 2. modal_quick_cliente.php — variante '' (sales/quotes/_form.php) ===\n";
$htmlQuo = renderModal($COMPONENTS . '/modal_quick_cliente.php', [
    'qkMncIdPrefix' => '',
    'qkMncTitleId'  => 'mnc-title',
]);
chk("id modale = modal-nuovo-cliente (senza prefisso)", str_contains($htmlQuo, 'id="modal-nuovo-cliente"'));
chk("nessun ord- residuo nella variante senza prefisso", !str_contains($htmlQuo, 'ord-'));
chk("aria-labelledby=mnc-title presente (comportamento originale invariato)", str_contains($htmlQuo, 'aria-labelledby="mnc-title"'));
chk("h5 con id=mnc-title presente", str_contains($htmlQuo, '<h5 class="modal-title" id="mnc-title">'));
chk("campo ragione sociale = mnc-ragione-sociale (non ord-mnc-...)", str_contains($htmlQuo, 'id="mnc-ragione-sociale"'));
chk("bottone salva = btn-salva-cliente", str_contains($htmlQuo, 'id="btn-salva-cliente"'));

echo "\n=== 3. modal_quick_prodotto.php — variante 'ord-' / contesto ordine (3 UdM) ===\n";
$htmlProdOrd = renderModal($COMPONENTS . '/modal_quick_prodotto.php', [
    'qkMnpIdPrefix'      => 'ord-',
    'qkMnpTitleId'       => null,
    'qkMnpUnitaMisura'   => ['pz' => 'pz', 'kg' => 'kg', 'l' => 'l'],
    'qkMnpContestoLabel' => 'ordine',
]);
chk("id modale = ord-modal-nuovo-prodotto", str_contains($htmlProdOrd, 'id="ord-modal-nuovo-prodotto"'));
chk("select con esattamente 3 <option> (pz/kg/l)", substr_count($htmlProdOrd, '<option value=') === 3);
chk("opzione 'g' ASSENTE (comportamento originale di _create.php, non un'unificazione a 5)", !str_contains($htmlProdOrd, 'value="g"'));
chk("opzione 'ml' ASSENTE", !str_contains($htmlProdOrd, 'value="ml"'));
chk("testo cita \"dell'ordine\"", str_contains($htmlProdOrd, "dell'ordine"));
chk("testo NON cita \"dell'offerta\"", !str_contains($htmlProdOrd, "dell'offerta"));
chk("qkMnpTitleId=null -> nessun aria-labelledby", !str_contains($htmlProdOrd, 'aria-labelledby'));

echo "\n=== 4. modal_quick_prodotto.php — variante '' / contesto offerta (5 UdM) ===\n";
$htmlProdQuo = renderModal($COMPONENTS . '/modal_quick_prodotto.php', [
    'qkMnpIdPrefix'      => '',
    'qkMnpTitleId'       => 'mnp-title',
    'qkMnpUnitaMisura'   => ['pz' => 'pz', 'kg' => 'kg', 'l' => 'l', 'g' => 'g', 'ml' => 'ml'],
    'qkMnpContestoLabel' => 'offerta',
]);
chk("id modale = modal-nuovo-prodotto (senza prefisso)", str_contains($htmlProdQuo, 'id="modal-nuovo-prodotto"'));
chk("select con esattamente 5 <option> (pz/kg/l/g/ml)", substr_count($htmlProdQuo, '<option value=') === 5);
chk("opzione 'g' presente (comportamento originale di quotes/_form.php)", str_contains($htmlProdQuo, 'value="g"'));
chk("opzione 'ml' presente", str_contains($htmlProdQuo, 'value="ml"'));
chk("testo cita \"dell'offerta\"", str_contains($htmlProdQuo, "dell'offerta"));
chk("aria-labelledby=mnp-title presente", str_contains($htmlProdQuo, 'aria-labelledby="mnp-title"'));

echo "\n=== RISULTATO: $pass PASS, $fail FAIL ===\n";
exit($fail > 0 ? 1 : 0);
