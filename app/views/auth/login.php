<?php
declare(strict_types=1);
if (is_authenticated()) { redirect('dashboard'); }

/* Impedisce la cache del browser: ogni ricarica pesca una skin diversa */
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /*
     * Sul POST manteniamo la skin del caricamento iniziale
     * (il campo nascosto _skin la riporta indietro).
     */
    $skin = match(post('_skin')) { 'B' => 'B', 'C' => 'C', 'D' => 'D', 'E' => 'E', default => 'A' };
    if (!csrf_verify()) {
        $error = 'Token CSRF non valido. Riprova.';
    } else {
        global $pdo;
        if (login_attempt($pdo, post('username'), post('password'))) { redirect('dashboard'); }
        $error = 'Credenziali non valide.';
    }
} else {
    /*
     * GET — scelta casuale:
     *   A = "Increspature"              — cerchi Perlin noise dentro la vasca
     *   B = "Onde orizzontali"          — linee noise full-screen su sfondo scuro
     *   C = "Onde piatte piene"         — fasce di profondità dentro la vasca
     *   D = "Mattonelle fondale distorte" — griglia di piastrelle deformate da Perlin noise
     *   E = "Caustiche"                 — shader WebGL luce rifratta sull'acqua
     */
    $skin = ['A', 'B', 'C', 'D', 'E'][rand(0, 4)];
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login — <?= e(APP_NAME) ?></title>
  <link rel="icon" href="<?= asset('img/logo_quack.png') ?>" type="image/png">
  <link rel="stylesheet" href="<?= asset('css/bootstrap.min.css') ?>">
  <link rel="stylesheet" href="<?= asset('css/login.css') ?>">
</head>
<body class="qk-login-body qk-skin-<?= strtolower($skin) ?>">

  <main class="qk-pool" id="qk-pool" role="main">
    <div class="qk-content">
      <?php include __DIR__ . '/_form.php'; ?>
    </div>
  </main>

  <script defer src="<?= asset('js/p5.min.js') ?>"></script>
  <script defer src="<?= asset('js/login-skin-' . strtolower($skin) . '.js') ?>"></script>
  <script defer src="<?= asset('js/login-interactions.js') ?>"></script>

</body>
</html>
