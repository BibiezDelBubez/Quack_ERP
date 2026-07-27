<?php

declare(strict_types=1);

require_once INCLUDES_DIR . '/prodotti.php';
require_once INCLUDES_DIR . '/magazzino.php';
require_once INCLUDES_DIR . '/anagrafiche.php';
require_once INCLUDES_DIR . '/tabelle_base.php';

final class ProductsCategoriesController extends BaseController
{


    public function crearCategoria(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['ok' => false, 'error' => 'Metodo non consentito.']); exit;
        }
        if (!csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Token non valido.']); exit;
        }

        $nome = trim(post('nome') ?? '');
        if ($nome === '') {
            echo json_encode(['ok' => false, 'error' => 'Il nome è obbligatorio.']); exit;
        }
        if (mb_strlen($nome) > 100) {
            echo json_encode(['ok' => false, 'error' => 'Nome troppo lungo (max 100 caratteri).']); exit;
        }

        try {
            $id = qk_crea_categoria($this->pdo, $nome);
            echo json_encode(['ok' => true, 'id' => $id, 'nome' => $nome, 'label' => $nome]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'Errore nel salvataggio.']);
        }
        exit;
    }



    public function searchCategorie(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $q         = trim(get_param('q'));
        $limit     = min(100, max(1, (int) get_param('limit', '50')));
        // D4 (PIANO - Unificazione Autocomplete, 27/07/2026): esclude la categoria
        // in modifica dai risultati, per evitare che compaia come genitore di se
        // stessa nel campo di ricerca. get_int() (non filter_input diretto) per
        // evitare lo stesso bug ?int/false già corretto altrove nel progetto.
        $excludeId = get_int('exclude_id');
        echo json_encode(qk_search_categorie($this->pdo, $q, $limit, $excludeId));
        exit;
    }



    public function categories(): void
    {
        $errors = [];
        $ok = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Token di sicurezza non valido. Ricarica la pagina.';
            } else {
                $id = post_int('id');
                $nome = trim(post('nome') ?? '');
                $parent_id = post_int('parent_id');
                $posizione_categoria = (int) post('posizione_categoria', '0');
                $immagine_path = trim(post('immagine_path') ?? '') ?: null;

                if ($nome === '') {
                    $errors['nome'] = 'Il nome della categoria è obbligatorio.';
                } elseif (mb_strlen($nome) > 100) {
                    $errors['nome'] = 'Nome troppo lungo (max 100 caratteri).';
                }

                if ($id && $parent_id && $id === $parent_id) {
                    $errors['parent_id'] = 'Una categoria non può essere genitore di se stessa.';
                }

                // Handle image upload
                if (empty($errors) && isset($_FILES['immagine_file']) && $_FILES['immagine_file']['error'] === UPLOAD_ERR_OK) {
                    $tmp = $_FILES['immagine_file']['tmp_name'];
                    $name = $_FILES['immagine_file']['name'];

                    // Verifica tipo reale del file (MIME sniffato dal contenuto, non
                    // dall'estensione dichiarata dall'utente). SVG escluso: può contenere
                    // script embedded (rischio XSS), coerente con AziendeController.
                    $allowedMimeToExt = [
                        'image/jpeg' => 'jpg',
                        'image/png'  => 'png',
                        'image/gif'  => 'gif',
                        'image/webp' => 'webp',
                    ];
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $realMime = $finfo ? finfo_file($finfo, $tmp) : false;
                    if ($finfo) {
                        finfo_close($finfo);
                    }
                    $ext = $allowedMimeToExt[$realMime] ?? null;
                    if ($ext === null || getimagesize($tmp) === false) {
                        $errors['immagine_file'] = 'Formato immagine non valido. Consentiti: ' . implode(', ', $allowedMimeToExt);
                    } else {
                        $subDir = 'categorie';
                        $absDir = upload_ensure_dir($subDir);
                        $safeName = preg_replace('/[^a-zA-Z0-9_.-]/', '_', pathinfo($name, PATHINFO_FILENAME));
                        $finalName = $safeName . '_' . time() . '.' . $ext;
                        $destAbs = $absDir . DIRECTORY_SEPARATOR . $finalName;
                        if (move_uploaded_file($tmp, $destAbs)) {
                            $immagine_path = $subDir . '/' . $finalName;
                        } else {
                            $errors['immagine_file'] = 'Impossibile salvare l\'immagine caricata.';
                        }
                    }
                }

                if (empty($errors)) {
                    try {
                        $oldImg = qk_save_category($this->pdo, $id, $nome, $parent_id, $posizione_categoria, $immagine_path);

                        // Se avevamo un'immagine vecchia ed è diversa dalla nuova (cioè abbiamo fatto upload), la eliminiamo
                        if ($oldImg && $oldImg !== $immagine_path) {
                            $absPath = APP_ROOT_DIR . '/public/' . ltrim($oldImg, '/\\');
                            if (is_file($absPath)) {
                                if (!unlink($absPath)) {
                                    error_log("ProductsController: Impossibile eliminare la vecchia immagine categoria: " . $absPath);
                                }
                            }
                        }

                        $msg = $id ? 'Categoria aggiornata con successo.' : 'Categoria creata con successo.';
                        redirect_with('products/categories', 'success', $msg);
                    } catch (Throwable $e) {
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
                        $errors['_global'] = 'Errore nel salvataggio: ' . $e->getMessage();
                    }
                }
            }
        }

        // Fetch categories list
        $categories = qk_get_categories_list($this->pdo);

        // Fetch flat list for dropdown selection (parent choice)
        $dropdown_categories = qk_load_categorie($this->pdo);

        $this->page(APP_ROOT_DIR . '/app/views/products/_categories.php', [
            'page_title'          => 'Categorie Prodotti',
            'page_subtitle'       => 'Gestione delle categorie merceologiche del catalogo',
            'breadcrumbs'         => [
                ['label' => 'Prodotti', 'url' => url('products')],
                ['label' => 'Categorie'],
            ],
            'categories'          => $categories,
            'dropdown_categories' => $dropdown_categories,
            'errors'              => $errors,
        ]);
    }



    public function deleteCategory(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect_with('products/categories', 'danger', 'Metodo non consentito.');
        }

        if (!csrf_verify()) {
            redirect_with('products/categories', 'danger', 'Token di sicurezza non valido.');
        }

        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            redirect_with('products/categories', 'danger', 'Categoria non specificata.');
        }

        $res = qk_delete_category($this->pdo, $id);
        
        if (!$res['ok']) {
            redirect_with('products/categories', 'danger', $res['error']);
        }

        $oldImg = $res['immagine_path'] ?? null;
        if ($oldImg) {
            $absPath = APP_ROOT_DIR . '/public/' . ltrim($oldImg, '/\\');
            if (is_file($absPath)) {
                if (!unlink($absPath)) {
                    error_log("ProductsController: Impossibile eliminare l'immagine categoria in fase di cancellazione: " . $absPath);
                }
            }
        }

        redirect_with('products/categories', 'success', 'Categoria eliminata correttamente.');
    }



    public function importCategorie(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['ok' => false, 'error' => 'Metodo non consentito.']); exit;
        }
        if (!csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Token di sicurezza non valido. Ricarica la pagina.']); exit;
        }

        $rows = json_decode(post('rows') ?? '[]', true);
        if (!is_array($rows) || empty($rows)) {
            echo json_encode(['ok' => false, 'error' => 'Nessun dato ricevuto.']); exit;
        }

        try {
            $result = qk_import_categories_massively($this->pdo, $rows);
            echo json_encode($result);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $this->dbMessage($e)]);
        }
        exit;
    }



    public function exportCategorie(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $rows = qk_get_categories_for_export($this->pdo);

        echo json_encode([
            'ok'         => true,
            'exported_at' => date('Y-m-d H:i:s'),
            'rows'       => $rows,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

}
