<!-- ── TAB: GRUPPI IMBALLAGGIO ─────────────────────────────────────── -->
  <div class="tab-pane fade <?= $tab === 'imballaggio' ? 'show active' : '' ?>"
       id="tab-imballaggio" role="tabpanel">

    <?php if (!empty($errors['imb'])): ?>
    <div class="qk-alert qk-alert-danger mb-3">
      <i class="fa-solid fa-circle-xmark"></i>
      <span><?= e($errors['imb']) ?></span>
    </div>
    <?php endif; ?>

    <div class="qk-card">
      <div class="qk-table-toolbar">
        <div class="qk-table-toolbar-left">
          <span class="qk-text-muted small">Gruppi di imballaggio ADR: I (alta), II (media), III (bassa pericolosità)</span>
        </div>
        <div class="qk-table-toolbar-right">
          <button class="btn-qk btn-qk-primary btn-qk-sm"
                  data-bs-toggle="modal" data-bs-target="#modal-imb"
                  data-mode="create">
            <i class="fa-solid fa-plus"></i> Aggiungi
          </button>
        </div>
      </div>
      <div class="qk-table-wrap">
        <?php if (empty($imballaggi)): ?>
        <div class="qk-empty">
          <i class="fa-solid fa-box-taped"></i>
          <p>Nessun gruppo registrato.</p>
        </div>
        <?php else: ?>
        <table class="qk-table">
          <thead>
            <tr>
              <th style="width:120px">Codice</th>
              <th>Descrizione</th>
              <th class="col-actions"></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($imballaggi as $imb): ?>
            <tr class="qk-tr-clickable"
                data-bs-toggle="modal" data-bs-target="#modal-imb"
                data-mode="edit"
                data-codice="<?= e($imb['codice']) ?>"
                data-descrizione="<?= e($imb['descrizione']) ?>">
              <td><span class="fw-semibold font-monospace qk-text-accent"><?= e($imb['codice']) ?></span></td>
              <td><?= e($imb['descrizione']) ?></td>
              <td class="col-actions" onclick="event.stopPropagation()">
                <div class="d-flex gap-1 justify-content-end">
                  <form method="post" action="<?= url('adr') ?>" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="tab"    value="imballaggio">
                    <input type="hidden" name="action" value="delete_imb">
                    <input type="hidden" name="codice" value="<?= e($imb['codice']) ?>">
                    <button type="submit" class="btn-qk btn-qk-danger btn-qk-sm"
                            onclick="return confirm('Eliminare il gruppo <?= e($imb['codice']) ?>?')">
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
  </div><!-- /tab-imballaggio -->
