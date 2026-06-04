<?php

declare(strict_types=1);

final class WoocommerceSettingsController extends BaseController
{
    /** Chiavi gestite da questo controller */
    private const KEYS = [
        'wc_store_url',
        'wc_consumer_key',
        'wc_consumer_secret',
        'wc_sync_enabled',
        'wc_sync_products',
        'wc_sync_orders',
        'wc_sync_direction',
        'wc_webhook_secret',
    ];

    /** Legge tutte le chiavi WC dalla tabella Impostazioni */
    private function loadSettings(): array
    {
        $placeholders = implode(',', array_map(fn($k) => '?', self::KEYS));
        $stmt = $this->pdo->prepare(
            "SELECT chiave, valore FROM Impostazioni WHERE chiave IN ($placeholders)"
        );
        $stmt->execute(self::KEYS);

        $defaults = array_fill_keys(self::KEYS, '');
        $defaults['wc_sync_direction'] = 'both';

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $defaults[$row['chiave']] = $row['valore'];
        }
        return $defaults;
    }

    /** Upsert di una chiave in Impostazioni */
    private function saveSetting(string $key, string $value): void
    {
        $this->pdo->prepare(
            "INSERT INTO Impostazioni (chiave, valore) VALUES (?, ?)
             ON CONFLICT(chiave) DO UPDATE SET valore = excluded.valore"
        )->execute([$key, $value]);
    }

    /* ── GET / POST /settings/woocommerce ─────────────────────────────────── */

    public function index(): void
    {
        $errors  = [];
        $success = false;
        $values  = $this->loadSettings();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Token di sicurezza non valido.';
            } else {
                $store_url = trim(post('wc_store_url') ?? '');
                $ck        = trim(post('wc_consumer_key') ?? '');
                $cs        = trim(post('wc_consumer_secret') ?? '');
                $direction = post('wc_sync_direction') ?: 'both';
                $webhook   = trim(post('wc_webhook_secret') ?? '');

                if ($store_url && !filter_var($store_url, FILTER_VALIDATE_URL)) {
                    $errors['wc_store_url'] = 'URL non valido.';
                }
                if (!in_array($direction, ['push', 'pull', 'both'], true)) {
                    $direction = 'both';
                }

                if (empty($errors)) {
                    // Normalizza URL: rimuovi trailing slash
                    $store_url = rtrim($store_url, '/');

                    $this->saveSetting('wc_store_url',       $store_url);
                    $this->saveSetting('wc_consumer_key',    $ck);
                    // Non sovrascrivere il secret se il campo è rimasto vuoto (mascherato)
                    if ($cs !== '' && $cs !== '••••••••') {
                        $this->saveSetting('wc_consumer_secret', $cs);
                    }
                    $this->saveSetting('wc_sync_enabled',  post('wc_sync_enabled')  ? '1' : '0');
                    $this->saveSetting('wc_sync_products', post('wc_sync_products') ? '1' : '0');
                    $this->saveSetting('wc_sync_orders',   post('wc_sync_orders')   ? '1' : '0');
                    $this->saveSetting('wc_sync_direction', $direction);
                    if ($webhook !== '') {
                        $this->saveSetting('wc_webhook_secret', $webhook);
                    }

                    $values  = $this->loadSettings();
                    $success = true;
                }
            }
        }

        // Maschera il secret in output
        $display = $values;
        if (!empty($display['wc_consumer_secret'])) {
            $display['wc_consumer_secret'] = '••••••••';
        }

        $this->page(APP_ROOT_DIR . '/app/views/settings/woocommerce.php', [
            'page_title'    => 'Integrazioni & WooCommerce',
            'page_subtitle' => 'Configura le chiavi API e i parametri di sincronizzazione',
            'breadcrumbs'   => [
                ['label' => 'Impostazioni', 'url' => url('settings')],
                ['label' => 'WooCommerce'],
            ],
            'values'  => $display,
            'errors'  => $errors,
            'success' => $success,
        ]);
    }

    /* ── POST /settings/woocommerce/test-connection ────────────────────────── */

    public function testConnection(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!csrf_verify()) {
            echo json_encode(['ok' => false, 'message' => 'Token CSRF non valido.']);
            exit;
        }

        $settings = $this->loadSettings();
        $url      = rtrim($settings['wc_store_url'], '/');
        $ck       = $settings['wc_consumer_key'];
        $cs       = $settings['wc_consumer_secret'];

        if (!$url || !$ck || !$cs) {
            echo json_encode(['ok' => false, 'message' => 'Compila URL, Consumer Key e Consumer Secret prima di testare.']);
            exit;
        }

        $endpoint = $url . '/wp-json/wc/v3/system_status';

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERPWD        => $ck . ':' . $cs,
            CURLOPT_SSL_VERIFYPEER => false, // tollerante per ambienti locali
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);

        $body    = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            echo json_encode(['ok' => false, 'message' => 'Errore di connessione: ' . $curlErr]);
            exit;
        }

        if ($httpCode === 200) {
            $data = json_decode($body, true);
            $wcVersion = $data['environment']['wc_version'] ?? '?';
            $wpVersion = $data['environment']['wp_version'] ?? '?';
            echo json_encode([
                'ok'      => true,
                'message' => "Connessione riuscita! WooCommerce $wcVersion su WordPress $wpVersion.",
            ]);
        } elseif ($httpCode === 401) {
            echo json_encode(['ok' => false, 'message' => 'Credenziali non valide (401). Verifica Consumer Key e Secret.']);
        } elseif ($httpCode === 404) {
            echo json_encode(['ok' => false, 'message' => 'Endpoint non trovato (404). Verifica che WooCommerce sia attivo e l\'URL sia corretto.']);
        } else {
            echo json_encode(['ok' => false, 'message' => "Risposta inattesa dal server (HTTP $httpCode)."]);
        }
        exit;
    }
}
