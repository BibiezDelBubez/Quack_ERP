<?php

declare(strict_types=1);

final class SettingsController extends BaseController
{
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
        $stmt = $this->pdo->query("SELECT chiave, valore FROM Impostazioni WHERE chiave = 'markup_fallback_default'");
        $valoreDb = $stmt->fetchColumn();
        $values = [
            'markup_fallback_default' => $valoreDb !== false ? $valoreDb : '20.00'
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
                    $stSave = $this->pdo->prepare("
                        INSERT INTO Impostazioni (chiave, valore) VALUES ('markup_fallback_default', ?)
                        ON CONFLICT(chiave) DO UPDATE SET valore = excluded.valore
                    ");
                    $stSave->execute([$normalizedMarkup]);

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
}

