<?php
/**
 * settings/woocommerce.php
 * Configurazione integrazione WooCommerce.
 * Variabili: $values (array), $errors (array), $success (bool)
 */
?>

<?php if ($success): ?>
<div class="qk-alert qk-alert-success mb-3" data-auto-dismiss="4000">
  <i class="fa-solid fa-circle-check"></i>
  <span>Impostazioni WooCommerce salvate correttamente.</span>
  <button class="qk-alert-close"><i class="fa-solid fa-xmark"></i></button>
</div>
<?php endif; ?>

<?php if (!empty($errors['_global'])): ?>
<div class="qk-alert qk-alert-danger mb-3">
  <i class="fa-solid fa-circle-exclamation"></i>
  <span><?= e($errors['_global']) ?></span>
  <button class="qk-alert-close"><i class="fa-solid fa-xmark"></i></button>
</div>
<?php endif; ?>

<form method="post" id="formWoocommerce" action="<?= url('settings/woocommerce') ?>">
  <?= csrf_field() ?>

  <div class="qk-form-grid">

    <!-- Colonna principale -->
    <div>

      <!-- Credenziali API -->
      <div class="qk-card mb-3">
        <div class="qk-card-header">
          <span class="qk-card-title">
            <i class="fa-brands fa-wordpress me-2" style="color:var(--qk-accent)"></i>Credenziali API WooCommerce
          </span>
        </div>
        <div class="qk-card-body">

          <div class="qk-form-group">
            <label class="qk-form-label">URL Negozio <span class="required">*</span></label>
            <input type="url" name="wc_store_url"
                   class="qk-form-control <?= isset($errors['wc_store_url']) ? 'is-invalid' : '' ?>"
                   value="<?= e($values['wc_store_url']) ?>"
                   placeholder="https://mionegozio.it">
            <?php if (isset($errors['wc_store_url'])): ?>
              <div class="qk-form-error"><i class="fa-solid fa-circle-exclamation"></i> <?= e($errors['wc_store_url']) ?></div>
            <?php endif; ?>
            <span class="qk-form-hint">URL base del sito WordPress (senza slash finale).</span>
          </div>

          <div class="qk-form-group">
            <label class="qk-form-label">Consumer Key <span class="required">*</span></label>
            <input type="text" name="wc_consumer_key"
                   class="qk-form-control font-monospace"
                   value="<?= e($values['wc_consumer_key']) ?>"
                   placeholder="ck_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                   autocomplete="off">
            <span class="qk-form-hint">Generabile in WooCommerce → Impostazioni → Avanzate → REST API.</span>
          </div>

          <div class="qk-form-group mb-0">
            <label class="qk-form-label">Consumer Secret <span class="required">*</span></label>
            <div style="position:relative">
              <input type="password" name="wc_consumer_secret" id="wc-secret-input"
                     class="qk-form-control font-monospace"
                     value="<?= e($values['wc_consumer_secret']) ?>"
                     placeholder="cs_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                     autocomplete="new-password">
              <button type="button" id="wc-secret-toggle"
                      style="position:absolute;right:.6rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--qk-text-muted);padding:0"
                      title="Mostra/nascondi">
                <i class="fa-solid fa-eye" id="wc-secret-icon"></i>
              </button>
            </div>
            <?php if (!empty($values['wc_consumer_secret'])): ?>
              <span class="qk-form-hint"><i class="fa-solid fa-lock me-1" style="color:var(--qk-success)"></i>Secret salvato. Lascia vuoto per non modificarlo.</span>
            <?php else: ?>
              <span class="qk-form-hint">Verrà cifrato in DB. Non viene mai mostrato dopo il salvataggio.</span>
            <?php endif; ?>
          </div>

        </div>
        <div class="qk-card-footer qk-flex-row qk-gap-md">
          <button type="button" id="btn-test-connection" class="btn-qk btn-qk-secondary">
            <i class="fa-solid fa-plug me-1"></i>Testa connessione
          </button>
          <span id="test-result" style="font-size:.85rem"></span>
        </div>
      </div>

      <!-- Sincronizzazione -->
      <div class="qk-card mb-3">
        <div class="qk-card-header">
          <span class="qk-card-title">
            <i class="fa-solid fa-rotate me-2"></i>Sincronizzazione
          </span>
        </div>
        <div class="qk-card-body">

          <div class="qk-form-group">
            <label class="qk-flex-row qk-gap-sm" style="font-size:.875rem;cursor:pointer">
              <input type="hidden" name="wc_sync_enabled" value="0">
              <input type="checkbox" name="wc_sync_enabled" value="1"
                     id="wc-sync-enabled" <?= qk_is_true($values['wc_sync_enabled'] ?? '0') ? 'checked' : '' ?>
                     style="width:1.1rem;height:1.1rem;cursor:pointer">
              <span>Abilita sincronizzazione automatica</span>
            </label>
            <span class="qk-form-hint">Se disabilitata, la sync può essere avviata manualmente.</span>
          </div>

          <div id="sync-options" style="<?= !qk_is_true($values['wc_sync_enabled'] ?? '0') ? 'opacity:.45;pointer-events:none' : '' ?>">

            <div class="qk-form-group">
              <label class="qk-form-label">Cosa sincronizzare</label>
              <div class="qk-flex-col qk-gap-sm">
                <label class="qk-flex-row qk-gap-sm" style="font-size:.875rem;cursor:pointer">
                  <input type="hidden" name="wc_sync_products" value="0">
                  <input type="checkbox" name="wc_sync_products" value="1"
                         <?= qk_is_true($values['wc_sync_products'] ?? '0') ? 'checked' : '' ?>
                         style="width:1.1rem;height:1.1rem;cursor:pointer">
                  <span>Prodotti (nome, prezzo, stock, immagine)</span>
                </label>
                <label class="qk-flex-row qk-gap-sm" style="font-size:.875rem;cursor:pointer">
                  <input type="hidden" name="wc_sync_orders" value="0">
                  <input type="checkbox" name="wc_sync_orders" value="1"
                         <?= qk_is_true($values['wc_sync_orders'] ?? '0') ? 'checked' : '' ?>
                         style="width:1.1rem;height:1.1rem;cursor:pointer">
                  <span>Ordini (importa da WooCommerce come ordini di vendita)</span>
                </label>
              </div>
            </div>

            <div class="qk-form-group mb-0">
              <label class="qk-form-label">Direzione sincronizzazione prodotti</label>
              <div class="qk-flex-col qk-gap-xs">
                <?php foreach ([
                  'push' => ['Quack → WooCommerce', 'I prodotti vengono gestiti in Quack e pubblicati su WooCommerce'],
                  'pull' => ['WooCommerce → Quack', 'I prodotti vengono importati da WooCommerce in Quack'],
                  'both' => ['Bidirezionale', 'Sync in entrambe le direzioni (Quack è la fonte di verità)'],
                ] as $val => [$label, $hint]): ?>
                <label class="qk-flex-row qk-gap-sm" style="font-size:.875rem;cursor:pointer">
                  <input type="radio" name="wc_sync_direction" value="<?= $val ?>"
                         <?= ($values['wc_sync_direction'] ?? 'both') === $val ? 'checked' : '' ?>
                         style="margin-top:.15rem;flex-shrink:0">
                  <span>
                    <strong><?= $label ?></strong>
                    <span class="qk-form-hint d-block" style="margin:0"><?= $hint ?></span>
                  </span>
                </label>
                <?php endforeach; ?>
              </div>
            </div>

          </div>

        </div>
      </div>

      <!-- Webhook -->
      <div class="qk-card mb-3">
        <div class="qk-card-header">
          <span class="qk-card-title">
            <i class="fa-solid fa-webhook me-2"></i>Webhook
          </span>
          <span class="qk-badge qk-badge-muted" style="font-size:.7rem">Opzionale</span>
        </div>
        <div class="qk-card-body">
          <div class="qk-form-group mb-0">
            <label class="qk-form-label">Webhook Secret</label>
            <input type="password" name="wc_webhook_secret"
                   class="qk-form-control font-monospace"
                   value=""
                   placeholder="Lascia vuoto per non modificare"
                   autocomplete="new-password">
            <span class="qk-form-hint">
              Segreto condiviso con WooCommerce per validare i webhook in entrata.
              URL webhook da configurare in WooCommerce:
              <code style="font-size:.8rem"><?= rtrim(url('ecommerce/sync'), '/') ?>/webhook</code>
            </span>
          </div>
        </div>
      </div>

    </div><!-- /colonna principale -->

    <!-- Pannello stato (destro) -->
    <div class="qk-status-panel">
      <div class="qk-status-panel-hd">Stato integrazione</div>
      <div class="qk-status-panel-body">

        <div class="qk-flex-col qk-gap-sm mb-3">

          <?php
          $hasUrl    = !empty($values['wc_store_url']);
          $hasCk     = !empty($values['wc_consumer_key']);
          $hasCs     = !empty($values['wc_consumer_secret']);
          $syncOn    = qk_is_true($values['wc_sync_enabled'] ?? '0');
          ?>

          <div class="qk-flex-row qk-gap-sm" style="font-size:.82rem">
            <i class="fa-solid fa-circle <?= $hasUrl ? 'text-success' : '' ?>" style="font-size:.55rem;color:<?= $hasUrl ? 'var(--qk-success)' : 'var(--qk-text-muted)' ?>"></i>
            <span style="color:var(--qk-text-secondary)">URL negozio <?= $hasUrl ? 'configurato' : 'non configurato' ?></span>
          </div>
          <div class="qk-flex-row qk-gap-sm" style="font-size:.82rem">
            <i class="fa-solid fa-circle" style="font-size:.55rem;color:<?= ($hasCk && $hasCs) ? 'var(--qk-success)' : 'var(--qk-text-muted)' ?>"></i>
            <span style="color:var(--qk-text-secondary)">Chiavi API <?= ($hasCk && $hasCs) ? 'configurate' : 'non configurate' ?></span>
          </div>
          <div class="qk-flex-row qk-gap-sm" style="font-size:.82rem">
            <i class="fa-solid fa-circle" style="font-size:.55rem;color:<?= $syncOn ? 'var(--qk-success)' : 'var(--qk-text-muted)' ?>"></i>
            <span style="color:var(--qk-text-secondary)">Sync automatica <?= $syncOn ? 'attiva' : 'disattivata' ?></span>
          </div>

        </div>

        <?php if ($hasUrl): ?>
        <div style="margin-bottom:1rem;padding:.6rem .75rem;background:var(--qk-table-head-bg);border-radius:var(--qk-radius);font-size:.78rem;word-break:break-all;color:var(--qk-text-secondary)">
          <i class="fa-solid fa-link me-1"></i><?= e($values['wc_store_url']) ?>
        </div>
        <?php endif; ?>

        <div class="qk-status-panel-actions">
          <button type="submit" class="btn-qk btn-qk-primary">
            <i class="fa-solid fa-floppy-disk"></i> Salva impostazioni
          </button>
          <a href="<?= url('settings') ?>" class="btn-qk btn-qk-secondary">Annulla</a>
        </div>

      </div>
    </div>

  </div><!-- /qk-form-grid -->
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {

  // Toggle visibilità secret
  const secretInput = document.getElementById('wc-secret-input');
  const secretIcon  = document.getElementById('wc-secret-icon');
  document.getElementById('wc-secret-toggle').addEventListener('click', function () {
    const show = secretInput.type === 'password';
    secretInput.type = show ? 'text' : 'password';
    secretIcon.className = show ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
  });

  // Abilita/disabilita opzioni sync
  const syncEnabledCb = document.getElementById('wc-sync-enabled');
  const syncOptions   = document.getElementById('sync-options');
  syncEnabledCb.addEventListener('change', function () {
    syncOptions.style.opacity       = this.checked ? '1'    : '.45';
    syncOptions.style.pointerEvents = this.checked ? 'auto' : 'none';
  });

  // Test connessione
  document.getElementById('btn-test-connection').addEventListener('click', function () {
    const btn    = this;
    const result = document.getElementById('test-result');
    const form   = document.getElementById('formWoocommerce');

    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Test in corso…';
    result.innerHTML = '';

    const fd = new FormData(form);
    // Usa il valore attuale del campo secret (non mascherato se appena digitato)
    fetch('<?= url('settings/woocommerce/test-connection') ?>', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: fd,
    })
    .then(r => r.json())
    .then(data => {
      const color = data.ok ? 'var(--qk-success)' : 'var(--qk-danger)';
      const icon  = data.ok ? 'fa-circle-check'   : 'fa-circle-xmark';
      result.innerHTML = `<i class="fa-solid ${icon} me-1" style="color:${color}"></i><span style="color:${color}">${data.message}</span>`;
    })
    .catch(() => {
      result.innerHTML = '<span style="color:var(--qk-danger)">Errore di rete.</span>';
    })
    .finally(() => {
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-plug me-1"></i>Testa connessione';
    });
  });

});
</script>
