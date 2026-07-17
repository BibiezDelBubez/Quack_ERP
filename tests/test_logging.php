<?php

/**
 * Test di Simulazione Logica per Logging Try-Catch
 */

// Simuliamo l'ambiente
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['csrf_token'] = 'fake_token';

$logFile = __DIR__ . '/test_error.log';
ini_set('error_log', $logFile);
ini_set('log_errors', 1);

if (file_exists($logFile)) {
    unlink($logFile);
}

try {
    // Creiamo una classe fittizia che usa la stessa sintassi iniettata dallo script
    $code = '
    class FakeAdrController {
        public function insertCodice() {
            throw new Exception("Errore Intenzionale di Test!");
        }
        
        public function trigger() {
            try {
                $this->insertCodice();
            } catch (\Throwable $e) {
                error_log(\'[\' . basename(__FILE__) . \':\' . __LINE__ . \'] Exception: \' . $e->getMessage());
                return false;
            }
        }
    }
    $c = new FakeAdrController();
    $res = $c->trigger();
    return $res;
    ';
    
    $res = eval($code);

    if ($res === false) {
        echo "LOGIC_RESULT: RETURN_FALSE_PRESERVED\n";
    }

    if (file_exists($logFile)) {
        $logContent = file_get_contents($logFile);
        if (strpos($logContent, 'Exception: Errore Intenzionale di Test!') !== false) {
            echo "LOGIC_RESULT: LOG_WRITTEN_CORRECTLY\n";
        }
    }
} catch (Throwable $e) {
    echo "FATAL: " . $e->getMessage();
}
