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

    /**
     * Rilegge dal form SOLO i campi elencati in $whitelist, usando il valore
     * già presente in $current come fallback per ogni campo non inviato.
     * Qualunque altra chiave presente in $current (es. un campo di sola
     * lettura finito lì per un merge con una riga DB, o colonne extra
     * restituite da una query più ampia del necessario) non viene mai letta
     * da $_POST: è ignorata per costruzione, non solo per disciplina.
     *
     * @param array<string,mixed> $current    valori attuali (default form + riga DB caricata)
     * @param string[]            $whitelist  chiavi scrivibili dal form (tipicamente array_keys(defaultValues()))
     * @param string[]            $checkboxes chiavi checkbox HTML: assenti in $_POST => '0'
     * @param string[]            $skip       chiavi con logica di lettura dedicata, gestite dal chiamante
     */
    protected function readFormValues(array $current, array $whitelist, array $checkboxes = [], array $skip = []): array
    {
        foreach ($whitelist as $k) {
            if (in_array($k, $checkboxes, true) || in_array($k, $skip, true)) {
                continue;
            }
            $current[$k] = post($k, (string) ($current[$k] ?? ''));
        }
        foreach ($checkboxes as $k) {
            $current[$k] = isset($_POST[$k]) ? '1' : '0';
        }
        return $current;
    }

    protected function dbMessage(PDOException $e): string
    {
        $msg = $e->getMessage();
        if (str_contains($msg, 'FOREIGN KEY')) {
            return 'Impossibile completare l\'operazione: vincolo di chiave esterna (Foreign Key) violato. Verificare che tutti i codici/relazioni inseriti esistano già a sistema, oppure che il record non sia referenziato da altri dati.';
        }
        if (str_contains($msg, 'UNIQUE')) {
            return 'Esiste già un record con questo codice/chiave.';
        }
        return APP_ENV === 'development' ? 'Errore DB: ' . $msg : 'Errore nel salvataggio. Riprova.';
    }
}
