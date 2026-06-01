<?php

declare(strict_types=1);

/**
 * BaseController — classe base per tutti i controller dell'app.
 *
 * Un controller gestisce l'HTTP (legge $_GET/$_POST, valida, redirect)
 * e alla fine include un file di vista (HTML puro).
 * La logica di accesso al DB vive in includes/anagrafiche.php o file analoghi.
 *
 * Uso nei file di vista:
 *   Il controller imposta variabili locali ($page_title, $customers, …)
 *   e poi include l'header, il partial HTML e il footer.
 *   Le variabili del metodo sono visibili nel file incluso (scoping PHP standard).
 */
abstract class BaseController
{
    protected PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Renderizza un file di vista passandogli un array di variabili.
     * Equivale a extract($data) + require $file.
     *
     * @param string $file  percorso assoluto al file di vista
     * @param array  $data  variabili da iniettare nello scope della vista
     */
    protected function render(string $file, array $data = []): void
    {
        extract($data, EXTR_SKIP);
        require $file;
    }

    /**
     * Shortcut per includere header + vista + footer in un colpo solo.
     *
     * @param string $viewFile  percorso assoluto al file HTML della vista
     * @param array  $data      variabili aggiuntive (si sommano alle già definite nel metodo)
     */
    protected function page(string $viewFile, array $data = []): void
    {
        extract($data, EXTR_SKIP);
        require INCLUDES_DIR . '/header.php';
        require $viewFile;
        require INCLUDES_DIR . '/footer.php';
    }
}
