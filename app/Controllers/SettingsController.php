<?php

declare(strict_types=1);

final class SettingsController extends BaseController
{
    public function __construct(PDO $pdo)
    {
        parent::__construct($pdo);
        require_once INCLUDES_DIR . '/impostazioni.php';
    }
    public function index(): void
    {
        $page_title    = 'Impostazioni';
        $page_subtitle = 'Configurazioni e parametri globali del sistema';
        $breadcrumbs   = [['label' => 'Sistema'], ['label' => 'Impostazioni']];

        $this->page(APP_ROOT_DIR . '/app/views/settings/index.php', compact(
            'page_title', 'page_subtitle', 'breadcrumbs'
        ));
    }

    /**
     * Gestione delle impostazioni generali del sistema (es. ricarico di fallback globale).
     */
    public function generali(): void
    {
        $errors  = [];
        $success = false;

        // Recupera i valori esistenti o imposta i default
        $valoreDb = get_impostazione($this->pdo, 'markup_fallback_default', '20.00');
        // PIANO - Soglie Margine Configurabili (§1.4): stesso pattern di
        // markup_fallback_default, due nuove chiavi per il semaforo margine %
        // usato nei form preventivo/ordine cliente (vedi assets/js/margin-semaforo.js).
        $valoreSogliaVerde  = get_impostazione($this->pdo, 'margine_soglia_verde', '20.00');
        $valoreSogliaGiallo = get_impostazione($this->pdo, 'margine_soglia_giallo', '10.00');
        $values = [
            'markup_fallback_default' => $valoreDb,
            'margine_soglia_verde'    => $valoreSogliaVerde,
            'margine_soglia_giallo'   => $valoreSogliaGiallo,
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Token di sicurezza non valido.';
            } else {
                $markup = trim(post('markup_fallback_default') ?? '');

                if ($markup === '') {
                    $errors['markup_fallback_default'] = 'Ricarico di default obbligatorio.';
                } elseif (!is_numeric(str_replace(',', '.', $markup))) {
                    $errors['markup_fallback_default'] = 'Inserire un valore numerico valido (es. 20.00).';
                } else {
                    $markupFloat = (float)str_replace(',', '.', $markup);
                    if ($markupFloat < 0 || $markupFloat > 1000) {
                        $errors['markup_fallback_default'] = 'Il ricarico deve essere compreso tra 0% e 1000%.';
                    }
                }

                // PIANO - Soglie Margine Configurabili (§1.4): validazione
                // indipendente da quella del ricarico sopra — range 0-100 (un
                // margine sul prezzo di vendita non può raggiungere il 100%,
                // a differenza di un ricarico sul costo, vedi §4.3 del piano),
                // più il vincolo di coerenza soglia_verde > soglia_giallo.
                $sogliaVerdeRaw  = trim(post('margine_soglia_verde') ?? '');
                $sogliaGialloRaw = trim(post('margine_soglia_giallo') ?? '');
                $sogliaVerdeFloat = null;
                $sogliaGialloFloat = null;

                if ($sogliaVerdeRaw === '') {
                    $errors['margine_soglia_verde'] = 'Soglia verde obbligatoria.';
                } elseif (!is_numeric(str_replace(',', '.', $sogliaVerdeRaw))) {
                    $errors['margine_soglia_verde'] = 'Inserire un valore numerico valido (es. 20.00).';
                } else {
                    $sogliaVerdeFloat = (float)str_replace(',', '.', $sogliaVerdeRaw);
                    if ($sogliaVerdeFloat < 0 || $sogliaVerdeFloat > 100) {
                        $errors['margine_soglia_verde'] = 'La soglia deve essere compresa tra 0% e 100%.';
                    }
                }

                if ($sogliaGialloRaw === '') {
                    $errors['margine_soglia_giallo'] = 'Soglia gialla obbligatoria.';
                } elseif (!is_numeric(str_replace(',', '.', $sogliaGialloRaw))) {
                    $errors['margine_soglia_giallo'] = 'Inserire un valore numerico valido (es. 10.00).';
                } else {
                    $sogliaGialloFloat = (float)str_replace(',', '.', $sogliaGialloRaw);
                    if ($sogliaGialloFloat < 0 || $sogliaGialloFloat > 100) {
                        $errors['margine_soglia_giallo'] = 'La soglia deve essere compresa tra 0% e 100%.';
                    }
                }

                if (!isset($errors['margine_soglia_verde']) && !isset($errors['margine_soglia_giallo'])
                    && $sogliaVerdeFloat !== null && $sogliaGialloFloat !== null
                    && $sogliaVerdeFloat <= $sogliaGialloFloat) {
                    $errors['margine_soglia_verde'] = 'La soglia verde deve essere maggiore della soglia gialla.';
                }

                if (empty($errors)) {
                    $normalizedMarkup = number_format((float)str_replace(',', '.', $markup), 2, '.', '');
                    $normalizedSogliaVerde  = number_format($sogliaVerdeFloat, 2, '.', '');
                    $normalizedSogliaGiallo = number_format($sogliaGialloFloat, 2, '.', '');

                    // Upsert per salvataggio sicuro della configurazione in Impostazioni
                    set_impostazione($this->pdo, 'markup_fallback_default', $normalizedMarkup);
                    set_impostazione($this->pdo, 'margine_soglia_verde', $normalizedSogliaVerde);
                    set_impostazione($this->pdo, 'margine_soglia_giallo', $normalizedSogliaGiallo);

                    $values['markup_fallback_default'] = $normalizedMarkup;
                    $values['margine_soglia_verde']    = $normalizedSogliaVerde;
                    $values['margine_soglia_giallo']   = $normalizedSogliaGiallo;
                    $success = true;
                }
            }
        }

        $page_title    = 'Impostazioni Generali';
        $page_subtitle = 'Parametri e ricarichi di default dell\'ERP';
        $breadcrumbs   = [
            ['label' => 'Sistema', 'url' => url('settings')],
            ['label' => 'Impostazioni Generali']
        ];

        $this->page(APP_ROOT_DIR . '/app/views/settings/generali.php', compact(
            'page_title', 'page_subtitle', 'breadcrumbs', 'values', 'errors', 'success'
        ));
    }

    /**
     * Pagina admin "Sequenze Documenti" (REVISIONE Ciclo Acquisti, 17/07/2026):
     * lista/modifica delle righe di `sequenze` e azione "Ricrea sequenza
     * mancante" per un tipo scelto da un elenco chiuso. Aggancia la card
     * placeholder "Progressivi e Canali" già esistente in settings/index.php,
     * invece di inventare una nuova sezione (decisione consolidata nel
     * documento, che in precedenza aveva due formulazioni non allineate).
     * Non implementa auto-repair silenzioso: qk_next_sequence_number() resta
     * invariata (continua a lanciare eccezione se la riga manca), qui c'è
     * solo un percorso guidato per risolverlo da interfaccia.
     */
    public function sequenze(): void
    {
        $errors  = [];
        $success = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Token di sicurezza non valido.';
            } else {
                $azione = post('azione', '');
                try {
                    if ($azione === 'aggiorna') {
                        qk_sequenza_aggiorna($this->pdo, (int) post('id', '0'), [
                            'prefisso'        => post('prefisso', ''),
                            'anno'            => post('anno', ''),
                            'numero_corrente' => post('numero_corrente', ''),
                            'padding'         => post('padding', ''),
                        ]);
                        $success = 'Sequenza aggiornata.';
                    } elseif ($azione === 'crea_mancante') {
                        qk_sequenza_crea_mancante(
                            $this->pdo,
                            post('tipo', ''),
                            post('nuovo_prefisso', ''),
                            (int) post('nuovo_padding', '4')
                        );
                        $success = 'Sequenza ricreata.';
                    } else {
                        $errors['_global'] = "Azione '$azione' non riconosciuta.";
                    }
                } catch (\Throwable $e) {
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
                    $errors['_global'] = $e->getMessage();
                }
            }
        }

        $sequenze = qk_sequenze_list($this->pdo);
        $tipiEsistenti = array_column($sequenze, 'tipo');
        $tipiMancanti = array_values(array_diff(QK_SEQUENZE_TIPI_NOTI, $tipiEsistenti));

        $page_title    = 'Sequenze Documenti';
        $page_subtitle = 'Prefissi e contatori delle numerazioni progressive di ogni documento';
        $breadcrumbs   = [
            ['label' => 'Sistema', 'url' => url('settings')],
            ['label' => 'Sequenze Documenti']
        ];

        $this->page(APP_ROOT_DIR . '/app/views/settings/sequenze.php', compact(
            'page_title', 'page_subtitle', 'breadcrumbs', 'sequenze', 'tipiMancanti', 'errors', 'success'
        ));
    }
}

