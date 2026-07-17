<?php
/**
 * settings/sequenze.php
 * Pagina admin "Sequenze Documenti" (REVISIONE Ciclo Acquisti, 17/07/2026).
 * Variabili: $sequenze (array), $tipiMancanti (array<string>), $errors (array), $success (string)
 */
?>

<?php if (!empty($success)): ?>
<div class="qk-alert qk-alert-success mb-3" data-auto-dismiss="4000">
  <i class="fa-solid fa-circle-check"></i>
  <span><?= e($success) ?></span>
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

<div class="qk-card mb-4">
  <div class="qk-card-header">
    <span class="qk-card-title">
      <i class="fa-solid fa-arrow-down-1-9 me-2" style="color:var(--qk-accent)"></i>Numerazioni Progressive
    </span>
  </div>
  <div class="qk-card-body">
    <p class="qk-form-hint mb-3">
      Ogni riga genera il numero dei documenti di quel tipo (es. RFQ/2026/0001). "Numero corrente" è l'ultimo
      numero già assegnato: il prossimo documento userà numero corrente + 1. Modificare questi valori a mano
      può creare duplicati o buchi nella numerazione — farlo solo per correggere un errore constatato.
    </p>

    <div class="qk-table-wrap">
      <table class="qk-table" style="font-size:0.875rem">
        <thead>
          <tr>
            <th>Tipo</th>
            <th>Prefisso</th>
            <th class="qk-text-center">Anno</th>
            <th class="qk-text-right">Numero Corrente</th>
            <th class="qk-text-center">Padding</th>
            <th>Prossimo Numero</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($sequenze as $s):
            // Un <form> non può avere <tr>/<td> come figli diretti (nesting HTML
            // non valido) — ogni riga usa un <form> "invisibile" fuori dalla
            // tabella (id univoco), collegato agli input via attributo form="...".
            $formId = 'seq-form-' . (int)$s['id'];
          ?>
            <tr>
                <td><span class="qk-badge draft"><?= e($s['tipo']) ?></span></td>
                <td>
                  <input type="text" name="prefisso" form="<?= $formId ?>" class="qk-form-control" style="max-width:120px"
                         value="<?= e($s['prefisso']) ?>" required maxlength="20">
                </td>
                <td class="qk-text-center">
                  <input type="number" name="anno" form="<?= $formId ?>" class="qk-form-control qk-text-center" style="max-width:90px"
                         value="<?= (int)$s['anno'] ?>" min="2000" max="2100" required>
                </td>
                <td class="qk-text-right">
                  <input type="number" name="numero_corrente" form="<?= $formId ?>" class="qk-form-control qk-text-right" style="max-width:110px"
                         value="<?= (int)$s['numero_corrente'] ?>" min="0" required>
                </td>
                <td class="qk-text-center">
                  <input type="number" name="padding" form="<?= $formId ?>" class="qk-form-control qk-text-center" style="max-width:80px"
                         value="<?= (int)$s['padding'] ?>" min="1" max="8" required>
                </td>
                <td class="text-muted">
                  <?= e($s['prefisso'] . (int)$s['anno'] . '/' . str_pad((string)((int)$s['numero_corrente'] + 1), (int)$s['padding'], '0', STR_PAD_LEFT)) ?>
                </td>
                <td class="qk-text-right">
                  <form id="<?= $formId ?>" method="post" action="<?= url('settings/sequenze') ?>" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="azione" value="aggiorna">
                    <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                  </form>
                  <button type="submit" form="<?= $formId ?>" class="btn-qk btn-qk-secondary btn-qk-xs">
                    <i class="fa-solid fa-floppy-disk"></i> Salva
                  </button>
                </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($sequenze)): ?>
            <tr><td colspan="7" class="text-center text-muted py-4">Nessuna sequenza configurata.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php if (!empty($tipiMancanti)): ?>
<div class="qk-card mb-4">
  <div class="qk-card-header">
    <span class="qk-card-title">
      <i class="fa-solid fa-triangle-exclamation me-2 text-danger"></i>Ricrea Sequenza Mancante
    </span>
  </div>
  <div class="qk-card-body">
    <p class="qk-form-hint mb-3">
      I tipi elencati qui non hanno una riga in <code>sequenze</code>: il primo tentativo di creare un
      documento di quel tipo fallirebbe con un errore. Scegli il tipo, imposta prefisso e numero di cifre,
      e la sequenza partirà da 0 (il primo documento sarà il numero 1).
    </p>
    <form method="post" action="<?= url('settings/sequenze') ?>" class="qk-flex-row qk-gap-sm" style="align-items:flex-end; flex-wrap:wrap">
      <?= csrf_field() ?>
      <input type="hidden" name="azione" value="crea_mancante">

      <div class="qk-form-group mb-0">
        <label class="qk-form-label">Tipo</label>
        <select name="tipo" class="qk-form-control" required>
          <option value="">— Seleziona —</option>
          <?php foreach ($tipiMancanti as $t): ?>
            <option value="<?= e($t) ?>"><?= e($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="qk-form-group mb-0">
        <label class="qk-form-label">Prefisso</label>
        <input type="text" name="nuovo_prefisso" class="qk-form-control" style="max-width:120px" placeholder="es. RIC/" required maxlength="20">
      </div>

      <div class="qk-form-group mb-0">
        <label class="qk-form-label">Padding (cifre)</label>
        <input type="number" name="nuovo_padding" class="qk-form-control" style="max-width:90px" value="4" min="1" max="8" required>
      </div>

      <button type="submit" class="btn-qk btn-qk-warning">
        <i class="fa-solid fa-plus me-1"></i> Ricrea Sequenza
      </button>
    </form>
  </div>
</div>
<?php endif; ?>

<a href="<?= url('settings') ?>" class="btn-qk btn-qk-secondary">
  <i class="fa-solid fa-arrow-left me-1"></i> Torna a Impostazioni
</a>
