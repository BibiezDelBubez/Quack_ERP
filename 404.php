<?php
/* ═══════════════════════════ LOGICA ═══════════════════════════════════════ */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

http_response_code(404);

// Percorso specifico per la paperella
$img_url = asset('img/dabbling_duck.svg');

$copy_options = [
    [
        'title' => 'Dove vanno le anatre di Central Park?',
        'desc'  => 'Holden non l\'ha mai scoperto, e noi non sappiamo dove sia finita questa pagina. Il lago è ghiacciato, meglio tornare a riva.'
    ],
    [
        'title' => 'Cercavi qualcosa laggiù?',
        'desc'  => 'Stavi cercando qualcosa che non doveva essere qui, o una pagina che è già volata altrove? Comunque sia, hai trovato solo una coda piumata.'
    ],
    [
        'title' => 'Hai toccato il fondo.',
        'desc'  => 'Letteralmente. Questa pagina è affondata, migrata a sud, o forse non è mai esistita. Niente panico: riprendi fiato e torna a galla.'
    ],
    [
        'title' => 'Un\'anatra nel Sahara?',
        'desc'  => '"Se allevassi un\'anatra nel cuore del Sahara, senza dubbio nuoterebbe se la portassi al Nilo." Twain aveva ragione, ma qui siamo nel deserto delle pagine non trovate.'
    ],
    [
        'title' => 'Pagina svanita nella nebbia',
        'desc'  => '"Il mare s\'oscura<br>le voci delle anatre selvatiche<br>sono vagamente bianche."<br><br>Come nel celebre haiku di Bashō, la pagina che cercavi è svanita nel nulla: l\'indirizzo potrebbe essere errato o rimosso.'
    ]
];

// Estrazione casuale di un copy
$random_copy = $copy_options[array_rand($copy_options)];
?>
<!DOCTYPE html>
<html lang="it" data-theme="<?= e(ACTIVE_THEME) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>404 – Pagina non trovata · <?= e(APP_NAME) ?></title>
  <link rel="icon" href="<?= asset('img/logo_quack.png') ?>" type="image/png">
  <link rel="stylesheet" href="<?= asset('css/bootstrap.min.css') ?>">
  <link rel="stylesheet" href="<?= asset('fontawesome/css/all.min.css') ?>">
  <link rel="stylesheet" href="<?= asset('css/theme.css') ?>">
  <link rel="stylesheet" href="<?= asset('css/app.css') ?>">
  <style>
    :root {
      /* Palette ispirata all'immagine */
      --duck-water: #7ba6d5;      /* Azzurro sfondo */
      --duck-white: #ffffff;      /* Bianco piume */
      --duck-orange: #f4a261;     /* Arancio zampe */
      --duck-dark-blue: #4a6fa5;  /* Testo contrasto */
    }

    html, body { height: 100%; margin: 0; }
    
    .qk-404-wrap {
      min-height: 100vh;
      display: flex; 
      align-items: center; 
      justify-content: center;
      background-color: var(--duck-water);
      padding: 2rem;
      font-family: 'Segoe UI', Roboto, sans-serif;
    }

    .qk-404-container {
      display: flex;
      flex-direction: row;
      align-items: center;
      gap: 4rem;
      max-width: 1000px;
      width: 100%;
    }

    /* Colonna Testo (Sinistra) */
    .qk-404-content {
      flex: 1;
      text-align: left;
      color: white;
    }

    /* Colonna Immagine (Destra) */
    .qk-404-visual {
      flex: 1;
      display: flex;
      justify-content: center;
    }

    .qk-404-img {
      max-width: 450px;
      width: 100%;
      height: auto;
      filter: drop-shadow(0 20px 30px rgba(0,0,0,0.1));
    }

    .qk-404-code {
      font-size: 8rem;
      font-weight: 900;
      line-height: 1;
      margin-bottom: 1rem;
      color: rgba(255, 255, 255, 0.4);
    }

    .qk-404-title {
      font-size: 2.5rem;
      font-weight: 800;
      margin-bottom: 1rem;
      color: white;
      line-height: 1.2;
    }

    .qk-404-desc {
      font-size: 1.1rem;
      margin-bottom: 2.5rem;
      line-height: 1.6;
      color: rgba(255, 255, 255, 0.9);
    }

    .qk-404-actions {
      display: flex; 
      gap: 1rem;
    }

    /* Pulsanti personalizzati per la palette */
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
    }

    .btn-duck-primary:hover, .btn-duck-secondary:hover {
      transform: translateY(-3px);
      color: var(--duck-dark-blue);
      background: white;
    }

    /* Responsive per mobile */
    @media (max-width: 850px) {
      .qk-404-container {
        flex-direction: column-reverse;
        text-align: center;
        gap: 2rem;
      }
      .qk-404-content {
        text-align: center;
      }
      .qk-404-actions {
        justify-content: center;
      }
      .qk-404-code { font-size: 5rem; }
      .qk-404-title { font-size: 2rem; }
    }
  </style>
  <script>const QK_BASE = '<?= BASE_URL ?>';</script>
</head>
<body>

<div class="qk-404-wrap">
  <div class="qk-404-container">
    
    <!-- Testo a sinistra -->
    <div class="qk-404-content">
      <div class="qk-404-code">404</div>
      <!-- Stampa dinamica del titolo e della descrizione -->
      <h1 class="qk-404-title"><?= $random_copy['title'] ?></h1>
      <p class="qk-404-desc">
        <?= $random_copy['desc'] ?>
      </p>

      <div class="qk-404-actions">
        <a href="<?= url('dashboard') ?>" class="btn-duck-primary">
          <i class="fa-solid fa-house"></i> Home Quack
        </a>
        <button onclick="history.back()" class="btn-duck-secondary">
          <i class="fa-solid fa-arrow-left"></i> Torna indietro
        </button>
      </div>
    </div>

    <!-- Immagine a destra -->
    <div class="qk-404-visual">
      <img src="<?= e($img_url) ?>" 
           alt="Dabbling Duck 404" 
           class="qk-404-img">
    </div>

  </div>
</div>

<script src="<?= asset('js/bootstrap.bundle.js') ?>"></script>
<script src="<?= asset('js/app.js') ?>"></script>
</body>
</html>