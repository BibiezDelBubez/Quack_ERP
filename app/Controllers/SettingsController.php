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
        $values = [
            'markup_fallback_default' => $valoreDb
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

                if (empty($errors)) {
                    $normalizedMarkup = number_format((float)str_replace(',', '.', $markup), 2, '.', '');
                    
                    // Upsert per salvataggio sicuro della configurazione in Impostazioni
                    set_impostazione($this->pdo, 'markup_fallback_default', $normalizedMarkup);

                    $values['markup_fallback_default'] = $normalizedMarkup;
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

