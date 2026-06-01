<?php
/**
 * settings/index.php
 * Vista dashboard delle impostazioni di sistema
 */
?>

<div class="qk-settings-grid">

  <!-- Configurazione Aziendale -->
  <div class="qk-card qk-settings-card">
    <div class="qk-settings-card-icon"><i class="fa-solid fa-building-user"></i></div>
    <div class="qk-settings-card-body">
      <h3>Aziende Interne</h3>
      <p>Configura le anagrafiche, i loghi, le intestazioni e le coordinate bancarie delle aziende che emettono i documenti nell'ERP.</p>
      <a href="<?= url('settings/aziende') ?>" class="btn-qk btn-qk-secondary btn-qk-sm mt-auto align-self-start">
        Gestisci aziende <i class="fa-solid fa-arrow-right ms-1"></i>
      </a>
    </div>
  </div>

  <!-- Aliquote IVA (Placeholder) -->
  <div class="qk-card qk-settings-card qk-settings-card-muted">
    <div class="qk-settings-card-icon"><i class="fa-solid fa-percent"></i></div>
    <div class="qk-settings-card-body">
      <h3>Aliquote IVA</h3>
      <p>Gestisci le aliquote di vendita ed acquisto, i codici di esenzione e le impostazioni per la fatturazione elettronica.</p>
      <button class="btn-qk btn-qk-secondary btn-qk-sm mt-auto align-self-start" disabled>
        In arrivo...
      </button>
    </div>
  </div>

  <!-- Canali di Vendita & Numerazioni (Placeholder) -->
  <div class="qk-card qk-settings-card qk-settings-card-muted">
    <div class="qk-settings-card-icon"><i class="fa-solid fa-arrow-down-1-9"></i></div>
    <div class="qk-settings-card-body">
      <h3>Progressivi e Canali</h3>
      <p>Configura i prefissi e i contatori delle sequenze numeriche per preventivi, ordini e canali di vendita.</p>
      <button class="btn-qk btn-qk-secondary btn-qk-sm mt-auto align-self-start" disabled>
        In arrivo...
      </button>
    </div>
  </div>

  <!-- Integrazioni API (Placeholder) -->
  <div class="qk-card qk-settings-card qk-settings-card-muted">
    <div class="qk-settings-card-icon"><i class="fa-solid fa-network-wired"></i></div>
    <div class="qk-settings-card-body">
      <h3>Integrazioni & WooCommerce</h3>
      <p>Configura le chiavi API di WooCommerce e i parametri di sincronizzazione automatica dei prodotti e ordini.</p>
      <button class="btn-qk btn-qk-secondary btn-qk-sm mt-auto align-self-start" disabled>
        In arrivo...
      </button>
    </div>
  </div>

</div>

<style>
.qk-settings-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
  gap: 1.25rem;
  padding: 0.25rem 0;
}

.qk-settings-card {
  display: flex;
  flex-direction: row;
  gap: 1.25rem;
  padding: 1.5rem;
  transition: box-shadow var(--qk-transition), border-color var(--qk-transition), transform var(--qk-transition);
  min-height: 160px;
}

.qk-settings-card:hover:not(.qk-settings-card-muted) {
  border-color: var(--qk-accent);
  box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
  transform: translateY(-2px);
}

.qk-settings-card-icon {
  font-size: 2rem;
  color: var(--qk-accent-text);
  background: color-mix(in srgb, var(--qk-accent) 8%, transparent);
  width: 56px;
  height: 56px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: var(--qk-radius);
  flex-shrink: 0;
}

.qk-settings-card-muted .qk-settings-card-icon {
  color: var(--qk-text-muted);
  background: var(--qk-table-head-bg);
}

.qk-settings-card-body {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  flex: 1;
}

.qk-settings-card-body h3 {
  font-size: 1rem;
  font-weight: 600;
  margin: 0;
  color: var(--qk-text-primary);
}

.qk-settings-card-body p {
  font-size: 0.8rem;
  color: var(--qk-text-secondary);
  margin: 0;
  line-height: 1.4;
}

.qk-settings-card-muted {
  opacity: 0.7;
}
</style>
