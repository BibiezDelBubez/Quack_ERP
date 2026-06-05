<?php
/**
 * includes/pagination.php — Helper paginazione condiviso
 *
 * Funzioni:
 *   qk_pagination_params()  — legge page/sort/dir dall'URL, restituisce array normalizzato
 *   qk_paginate()           — renderizza il componente UI (prev/numeri/next + info righe)
 *   qk_sort_url()           — costruisce URL per intestazione colonna ordinabile
 *   qk_sort_icon()          — icona ↑↓ per intestazione attiva
 *   qk_list_url()           — costruisce URL lista preservando tutti i parametri attivi
 */

/**
 * Legge e normalizza i parametri di paginazione e ordinamento dall'URL.
 *
 * @param array  $sortWhitelist   Colonne sort ammesse, es. ['data','stato','fornitore']
 * @param string $defaultSort     Colonna di default
 * @param string $defaultDir      Direzione di default ('asc'|'desc')
 * @param int    $defaultPerPage  Righe per pagina
 * @return array{page:int, offset:int, limit:int, sort:string, dir:string}
 */
function qk_pagination_params(
    array  $sortWhitelist  = [],
    string $defaultSort    = 'data',
    string $defaultDir     = 'desc',
    int    $defaultPerPage = 50
): array {
    $page = max(1, (int) get_param('page', '1'));
    $limit = $defaultPerPage;
    $offset = ($page - 1) * $limit;

    $sort = get_param('sort', $defaultSort);
    if (!empty($sortWhitelist) && !in_array($sort, $sortWhitelist, true)) {
        $sort = $defaultSort;
    }

    $dir = strtolower(get_param('dir', $defaultDir));
    if (!in_array($dir, ['asc', 'desc'], true)) {
        $dir = $defaultDir;
    }

    return compact('page', 'offset', 'limit', 'sort', 'dir');
}

/**
 * Costruisce un URL per la lista preservando tutti i parametri GET correnti,
 * sostituendo/aggiungendo quelli specificati in $overrides.
 * Rimuove automaticamente 'page' se non specificata (reset a pagina 1).
 *
 * @param string $baseUrl   URL base (es. url('purchases/rfq'))
 * @param array  $overrides Parametri da sovrascrivere
 * @param bool   $keepPage  Se true, preserva il parametro page corrente
 */
function qk_list_url(string $baseUrl, array $overrides = [], bool $keepPage = false): string
{
    $params = $_GET;
    if (!$keepPage) {
        unset($params['page']);
    }
    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }
    $qs = http_build_query($params);
    return $baseUrl . ($qs !== '' ? '?' . $qs : '');
}

/**
 * Costruisce l'URL per un'intestazione colonna ordinabile.
 * Se la colonna è già attiva inverte la direzione, altrimenti usa 'asc'.
 */
function qk_sort_url(string $baseUrl, string $column, string $currentSort, string $currentDir): string
{
    if ($currentSort === $column) {
        $newDir = $currentDir === 'asc' ? 'desc' : 'asc';
    } else {
        $newDir = 'asc';
    }
    return qk_list_url($baseUrl, ['sort' => $column, 'dir' => $newDir]);
}

/**
 * Icona FontAwesome per intestazione ordinabile.
 * Colonna attiva → freccia piena nella direzione corrente.
 * Colonna inattiva → doppia freccia grigia.
 */
function qk_sort_icon(string $column, string $currentSort, string $currentDir): string
{
    if ($currentSort !== $column) {
        return ' <i class="fa-solid fa-sort" style="opacity:.35;font-size:.75em"></i>';
    }
    $icon = $currentDir === 'asc' ? 'fa-sort-up' : 'fa-sort-down';
    return ' <i class="fa-solid ' . $icon . '" style="color:var(--qk-accent);font-size:.75em"></i>';
}

/**
 * Renderizza il componente paginazione.
 *
 * @param int    $total     Numero totale di righe
 * @param int    $page      Pagina corrente (1-based)
 * @param int    $limit     Righe per pagina
 * @param string $baseUrl   URL base lista
 * @param int    $maxLinks  Numero massimo di link pagina mostrati
 */
function qk_paginate(int $total, int $page, int $limit, string $baseUrl, int $maxLinks = 7): void
{
    if ($total <= 0) return;

    $pages = (int) ceil($total / $limit);
    $from  = ($page - 1) * $limit + 1;
    $to    = min($page * $limit, $total);

    // Non mostrare se c'è solo una pagina — mostriamo solo l'info righe
    if ($pages <= 1) {
        echo '<div class="qk-pagination">';
        echo '<span class="qk-pagination-info">' . number_format($total, 0, ',', '.') . ' ' . ($total === 1 ? 'risultato' : 'risultati') . '</span>';
        echo '</div>';
        return;
    }

    // Calcola range di pagine da mostrare
    $half  = (int) floor($maxLinks / 2);
    $start = max(1, $page - $half);
    $end   = min($pages, $start + $maxLinks - 1);
    if ($end - $start < $maxLinks - 1) {
        $start = max(1, $end - $maxLinks + 1);
    }

    echo '<div class="qk-pagination">';

    // Info righe
    echo '<span class="qk-pagination-info">'
        . number_format($from, 0, ',', '.') . '–' . number_format($to, 0, ',', '.')
        . ' di ' . number_format($total, 0, ',', '.') . '</span>';

    echo '<nav class="qk-pagination-nav" aria-label="Paginazione">';

    // Prev
    $prevDisabled = $page <= 1;
    $prevUrl = qk_list_url($baseUrl, ['page' => $page - 1], true);
    echo '<a href="' . ($prevDisabled ? '#' : e($prevUrl)) . '" class="qk-page-btn' . ($prevDisabled ? ' disabled' : '') . '" ' . ($prevDisabled ? 'aria-disabled="true"' : '') . '>'
        . '<i class="fa-solid fa-chevron-left"></i></a>';

    // Prima pagina + ellissi sinistra
    if ($start > 1) {
        echo '<a href="' . e(qk_list_url($baseUrl, ['page' => 1], true)) . '" class="qk-page-btn">1</a>';
        if ($start > 2) echo '<span class="qk-page-ellipsis">…</span>';
    }

    // Pagine centrali
    for ($i = $start; $i <= $end; $i++) {
        $url = qk_list_url($baseUrl, ['page' => $i], true);
        $active = $i === $page ? ' active' : '';
        echo '<a href="' . e($url) . '" class="qk-page-btn' . $active . '">' . $i . '</a>';
    }

    // Ellissi destra + ultima pagina
    if ($end < $pages) {
        if ($end < $pages - 1) echo '<span class="qk-page-ellipsis">…</span>';
        echo '<a href="' . e(qk_list_url($baseUrl, ['page' => $pages], true)) . '" class="qk-page-btn">' . $pages . '</a>';
    }

    // Next
    $nextDisabled = $page >= $pages;
    $nextUrl = qk_list_url($baseUrl, ['page' => $page + 1], true);
    echo '<a href="' . ($nextDisabled ? '#' : e($nextUrl)) . '" class="qk-page-btn' . ($nextDisabled ? ' disabled' : '') . '" ' . ($nextDisabled ? 'aria-disabled="true"' : '') . '>'
        . '<i class="fa-solid fa-chevron-right"></i></a>';

    echo '</nav>';
    echo '</div>';
}
