<?php
/**
 * app/views/errors/500.php — pagina di errore generica per la produzione.
 *
 * Eseguita via include() da _qk_render_500_response() (includes/error_reporter.php),
 * chiamata sia dall'exception handler sia dallo shutdown handler per gli
 * errori fatali (PIANO Punti 1-2-3 Basso Rischio, Punto 1, 27/07/2026).
 *
 * Unica variabile locale garantita: $hash (string, può essere '' se
 * QK_ERROR_REPORT_ENABLED è disattivato). Deve restare massimamente
 * difensiva — è già in corso una situazione anomala. Nessun dettaglio
 * tecnico mostrato qui: va solo nel log/mail, già scritto prima di arrivare
 * a questo file.
 */

$qk_is_json_response = false;
foreach (headers_list() as $qk_h) {
    if (stripos($qk_h, 'Content-Type:') === 0 && stripos($qk_h, 'application/json') !== false) {
        $qk_is_json_response = true;
        break;
    }
}

if ($qk_is_json_response) {
    echo json_encode(['ok' => false, 'error' => 'Errore interno del server. Riprova più tardi.', 'ref' => $hash], JSON_UNESCAPED_UNICODE);
    return;
}

$qk_app_name = defined('APP_NAME') ? APP_NAME : 'Quack ERP';
$qk_theme    = defined('ACTIVE_THEME') ? ACTIVE_THEME : 'svedese-blu';
$qk_base     = defined('BASE_URL') ? BASE_URL : '';

// Scegliamo un messaggio casuale stile 404
$copy_options = [
    [
        'title' => 'Il motore fa acqua.',
        'desc'  => 'Qualcosa si è rotto sotto la superficie. Non sei stato tu: il problema è già stato segnalato ai nostri tecnici.'
    ],
    [
        'title' => 'Houston, abbiamo un papero.',
        'desc'  => 'I nostri sistemi hanno rilevato un\'anomalia imprevista. Mantieni la calma: stiamo già indagando.'
    ],
    [
        'title' => 'Qualcosa si è inceppato.',
        'desc'  => 'Le rotelle del server si sono bloccate per un momento. L\'errore è stato registrato nei log di sistema.'
    ]
];
$random_copy = $copy_options[array_rand($copy_options)];

?>
<!DOCTYPE html>
<html lang="it" data-theme="<?= htmlspecialchars($qk_theme, ENT_QUOTES) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Errore 500 · <?= htmlspecialchars($qk_app_name, ENT_QUOTES) ?></title>
  <style>
    :root {
      /* Palette in linea con la 404 */
      --duck-water: #7ba6d5;      
      --duck-white: #ffffff;      
      --duck-dark-blue: #4a6fa5;  
    }

    html, body { height: 100%; margin: 0; font-family: 'Segoe UI', Roboto, sans-serif; }
    
    .qk-500-wrap {
      min-height: 100vh;
      display: flex; 
      align-items: center; 
      justify-content: center;
      background-color: var(--duck-water);
      padding: 2rem;
    }

    .qk-500-container {
      display: flex;
      flex-direction: row;
      align-items: center;
      gap: 4rem;
      max-width: 1000px;
      width: 100%;
    }

    .qk-500-content {
      flex: 1;
      text-align: left;
      color: white;
    }

    .qk-500-visual {
      flex: 1;
      display: flex;
      justify-content: center;
      align-items: center;
    }

    .qk-500-img {
      max-width: 450px;
      width: 100%;
      height: auto;
      filter: drop-shadow(0 20px 30px rgba(0,0,0,0.1));
    }

    .qk-500-code {
      font-size: 8rem;
      font-weight: 900;
      line-height: 1;
      margin-bottom: 1rem;
      color: rgba(255, 255, 255, 0.4);
    }

    .qk-500-title {
      font-size: 2.5rem;
      font-weight: 800;
      margin-bottom: 1rem;
      color: white;
      line-height: 1.2;
    }

    .qk-500-desc {
      font-size: 1.1rem;
      margin-bottom: 1.5rem;
      line-height: 1.6;
      color: rgba(255, 255, 255, 0.9);
    }

    .qk-500-ref {
      display: inline-block;
      background: rgba(255,255,255,0.15);
      padding: 0.5rem 1rem;
      border-radius: 8px;
      font-family: monospace;
      font-size: 0.95rem;
      margin-bottom: 2.5rem;
      border: 1px dashed rgba(255,255,255,0.3);
    }

    .qk-500-actions {
      display: flex; 
      gap: 1rem;
    }

    .btn-duck-primary {
      background: white;
      color: var(--duck-water);
      border: none;
      padding: 0.8rem 1.5rem;
      border-radius: 50px;
      font-weight: 700;
      text-decoration: none;
      transition: transform 0.2s;
    }

    .btn-duck-secondary {
      background: rgba(255,255,255,0.2);
      color: white;
      border: 1px solid rgba(255,255,255,0.4);
      padding: 0.8rem 1.5rem;
      border-radius: 50px;
      font-weight: 600;
      text-decoration: none;
      backdrop-filter: blur(5px);
      cursor: pointer;
    }

    .btn-duck-primary:hover, .btn-duck-secondary:hover {
      transform: translateY(-3px);
      color: var(--duck-dark-blue);
      background: white;
    }

    @media (max-width: 850px) {
      .qk-500-container {
        flex-direction: column-reverse;
        text-align: center;
        gap: 2rem;
      }
      .qk-500-content {
        text-align: center;
      }
      .qk-500-actions {
        justify-content: center;
      }
      .qk-500-code { font-size: 5rem; }
      .qk-500-title { font-size: 2rem; }
    }
  </style>
</head>
<body>

<div class="qk-500-wrap">
  <div class="qk-500-container">
    
    <div class="qk-500-content">
      <div class="qk-500-code">500</div>
      
      <h1 class="qk-500-title"><?= $random_copy['title'] ?></h1>
      
      <p class="qk-500-desc">
        <?= $random_copy['desc'] ?>
      </p>

      <?php if ($hash !== ''): ?>
      <div class="qk-500-ref">
        Rif: <strong><?= htmlspecialchars($hash, ENT_QUOTES) ?></strong>
      </div>
      <?php endif; ?>

      <div class="qk-500-actions">
        <a href="<?= htmlspecialchars($qk_base, ENT_QUOTES) ?>/dashboard" class="btn-duck-primary">
          Home Quack
        </a>
        <button onclick="history.back()" class="btn-duck-secondary">
          Torna indietro
        </button>
      </div>
    </div>

    <!-- Immagine a destra fallback self-contained (senza usare la helper function asset() che potrebbe fallire in 500) -->
    <div class="qk-500-visual">
      <img src="<?= htmlspecialchars($qk_base, ENT_QUOTES) ?>/assets/img/dabbling_duck.svg" 
           alt="Dabbling Duck 500" 
           class="qk-500-img"
           onerror="this.style.display='none'">
    </div>

  </div>
</div>

</body>
</html>
