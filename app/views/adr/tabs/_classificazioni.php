<!-- ── TAB: CODICI CLASSIFICAZIONE ─────────────────────────────────────── -->
  <div class="tab-pane fade <?= $tab === 'classificazioni' ? 'show active' : '' ?>"
       id="tab-classificazioni" role="tabpanel">

    <?php if (!empty($errors['classificazione'])): ?>
    <div class="qk-alert qk-alert-danger mb-3">
      <i class="fa-solid fa-circle-xmark"></i>
      <span><?= e($errors['classificazione']) ?></span>
    </div>
    <?php endif; ?>

    <div class="qk-card">
      <div class="qk-table-toolbar">
        <div class="qk-table-toolbar-left">
          <span class="qk-text-muted small">Codici di classificazione ADR (col. 3b Tabella A) — indicano il tipo di pericolo primario e secondario</span>
        </div>
        <div class="qk-table-toolbar-right d-flex gap-2">
          <button type="button" id="btn-esporta-classificazioni"
                  class="btn-qk btn-qk-secondary btn-qk-sm">
            <i class="fa-solid fa-file-excel me-1"></i>Esporta Lista
          </button>
          <button type="button"
                  class="btn-qk btn-qk-secondary btn-qk-sm"
                  data-bs-toggle="modal" data-bs-target="#modal-import-classificazioni">
            <i class="fa-solid fa-file-import me-1"></i>Importa
          </button>
          <button class="btn-qk btn-qk-primary btn-qk-sm"
                  data-bs-toggle="modal" data-bs-target="#modal-classificazione"
                  data-mode="create">
            <i class="fa-solid fa-plus"></i> Aggiungi
          </button>
        </div>
      </div>
      <div class="qk-table-wrap">
        <?php if (empty($codici_classificazione)): ?>
        <div class="qk-empty">
          <i class="fa-regular fa-barcode"></i>
          <p>Nessun codice di classificazione registrato.</p>
        </div>
        <?php else: ?>
        <table class="qk-table">
          <thead>
            <tr>
              <th style="width:100px">Codice</th>
              <th style="width:120px">Classe ADR</th>
              <th style="width:140px">Pericolo primario</th>
              <th style="width:140px">Pericolo secondario</th>
              <th>Descrizione</th>
              <th class="col-actions"></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($codici_classificazione as $cf): ?>
            <tr class="qk-tr-clickable"
                data-bs-toggle="modal" data-bs-target="#modal-classificazione"
                data-mode="edit"
                data-codice="<?= e($cf['codice']) ?>"
                data-classe="<?= e($cf['codice_classe']) ?>"
                data-descrizione="<?= e($cf['descrizione'] ?? '') ?>"
                data-primario="<?= e($cf['pericolo_primario'] ?? '') ?>"
                data-secondario="<?= e($cf['pericolo_secondario'] ?? '') ?>">
              <td><span class="fw-semibold font-monospace qk-text-accent"><?= e($cf['codice']) ?></span></td>
              <td>
                <?php if ($cf['codice_classe']): ?>
                  <span class="qk-badge draft" title="<?= e($cf['classe_label'] ?? '') ?>">
                    <?= e($cf['codice_classe']) ?>
                  </span>
                <?php else: ?>
                  <span class="qk-text-muted">—</span>
                <?php endif; ?>
              </td>
              <td><?= $cf['pericolo_primario'] ? e($cf['pericolo_primario']) : '<span class="qk-text-muted">—</span>' ?></td>
              <td><?= $cf['pericolo_secondario'] ? e($cf['pericolo_secondario']) : '<span class="qk-text-muted">—</span>' ?></td>
              <td class="small"><?= $cf['descrizione'] ? e($cf['descrizione']) : '<span class="qk-text-muted">—</span>' ?></td>
              <td class="col-actions" onclick="event.stopPropagation()">
                <div class="d-flex gap-1 justify-content-end">
                  <form method="post" action="<?= url('adr') ?>" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="tab"    value="classificazioni">
                    <input type="hidden" name="action" value="delete_classificazione">
                    <input type="hidden" name="codice" value="<?= e($cf['codice']) ?>">
                    <input type="hidden" name="codice_classe" value="<?= e($cf['codice_classe']) ?>">
                    <button type="submit" class="btn-qk btn-qk-danger btn-qk-sm"
                            onclick="return confirm('Eliminare il codice <?= e($cf['codice']) ?>?')">
                      <i class="fa-solid fa-trash"></i>
                    </button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
  </div><!-- /tab-classificazioni -->



