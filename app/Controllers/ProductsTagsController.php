<?php

declare(strict_types=1);

require_once INCLUDES_DIR . '/prodotti.php';
require_once INCLUDES_DIR . '/magazzino.php';
require_once INCLUDES_DIR . '/anagrafiche.php';
require_once INCLUDES_DIR . '/tabelle_base.php';

final class ProductsTagsController extends BaseController
{


    public function crearTag(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Non autorizzato.']); exit;
        }
        $nome = trim(post('nome') ?? '');
        if ($nome === '') {
            echo json_encode(['ok' => false, 'error' => 'Il nome è obbligatorio.']); exit;
        }
        try {
            $id = qk_crea_tag($this->pdo, $nome);
            echo json_encode(['ok' => true, 'id' => $id, 'nome' => $nome]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'Errore nel salvataggio.']);
        }
        exit;
    }



    public function tags(): void
    {
        $errors = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Token di sicurezza non valido. Ricarica la pagina.';
            } else {
                $id = post_int('id');
                $nome = trim(post('nome') ?? '');

                if ($nome === '') {
                    $errors['nome'] = 'Il nome del tag è obbligatorio.';
                } elseif (mb_strlen($nome) > 50) {
                    $errors['nome'] = 'Nome troppo lungo (max 50 caratteri).';
                }

                // Check uniqueness
                if (empty($errors)) {
                    if (qk_tag_exists($this->pdo, $nome, $id)) {
                        $errors['nome'] = 'Questo tag esiste già.';
                    }
                }

                if (empty($errors)) {
                    try {
                        qk_save_tag($this->pdo, $id, $nome);
                        $msg = $id ? 'Tag aggiornato con successo.' : 'Tag creato con successo.';
                        redirect_with('products/tags', 'success', $msg);
                    } catch (Throwable $e) {
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
                        $errors['_global'] = 'Errore nel salvataggio: ' . $e->getMessage();
                    }
                }
            }
        }

        // Fetch tags with product count
        $tags = qk_get_tags_with_count($this->pdo);

        $this->page(APP_ROOT_DIR . '/app/views/products/_tags.php', [
            'page_title'    => 'Tag Prodotti',
            'page_subtitle' => 'Gestione delle etichette e dei tag associati ai prodotti',
            'breadcrumbs'   => [
                ['label' => 'Prodotti', 'url' => url('products')],
                ['label' => 'Tag'],
            ],
            'tags'          => $tags,
            'errors'        => $errors,
        ]);
    }



    public function deleteTag(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect_with('products/tags', 'danger', 'Metodo non consentito.');
        }

        if (!csrf_verify()) {
            redirect_with('products/tags', 'danger', 'Token di sicurezza non valido.');
        }

        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            redirect_with('products/tags', 'danger', 'Tag non specificato.');
        }

        try {
            qk_delete_tag($this->pdo, $id);
            redirect_with('products/tags', 'success', 'Tag eliminato correttamente.');
        } catch (Throwable $e) {
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
            redirect_with('products/tags', 'danger', 'Errore durante l\'eliminazione del tag.');
        }
    }

}
