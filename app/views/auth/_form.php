<?php
/* ─────────────────────────────────────────────────────────────────────────────
   Partial — form di accesso condiviso da tutte le skin.
   Variabili attese dal chiamante: $skin (string), $error (string|null).
   ───────────────────────────────────────────────────────────────────────────── */
?>
<!--
<div class="qk-logo-wrap">
  <svg class="qk-logo-mark" viewBox="0 0 48 48"
       xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    <rect x="5" y="5" width="38" height="38" rx="11"
          fill="none" stroke="currentColor" stroke-width="2.2"/>
    <text x="24" y="32.5" text-anchor="middle"
          font-family="system-ui,-apple-system,'Segoe UI',sans-serif"
          font-size="22" font-weight="700"
          fill="currentColor">Q</text>
  </svg>
  <div class="qk-logo-shadow" aria-hidden="true"></div>
</div>
-->

<div class="qk-logo-wrap">
  <div class="qk-logo-duck">
   <img class="qk-logo-mark"
     src="<?= asset('img/quack.svg') ?>"
     alt="Q"
     title="Wszyscy na jednej fali!">
    <div class="qk-logo-shadow" aria-hidden="true"></div>
  </div>
  <!--<span class="qk-logo-text" aria-label="Quack">UACK</span> -->
</div>

<h1 class="qk-heading">Login</h1>
<p class="qk-sub"><?= e(APP_NAME) ?> · Quoting Utility and ADR Chemical Keeper</p>

<?php if ($error): ?>
<div class="qk-alert" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<form method="post" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="_skin" value="<?= e($skin) ?>">
  <div class="qk-field">
    <label for="username">Username</label>
    <input id="username" name="username" placeholder="il tuo username"
           required autofocus autocomplete="username">
  </div>
  <div class="qk-field">
    <label for="password">Password</label>
    <input type="password" id="password" name="password"
           placeholder="••••••••" required autocomplete="current-password">
  </div>
  <button type="submit" class="qk-submit">
    <span aria-hidden="true">→</span> Entra
  </button>
</form>

<p class="qk-hint">Scritto da Yorick con rancore</p>
