<!-- ── TAB: CLASSI DI PERICOLO ─────────────────────────────────────── -->
  <div class="tab-pane fade <?= $tab === 'classi' ? 'show active' : '' ?>"
       id="tab-classi" role="tabpanel">

    <?php if (!empty($errors['classe'])): ?>
    <div class="qk-alert qk-alert-danger mb-3">
      <i class="fa-solid fa-circle-xmark"></i>
      <span><?= e($errors['classe']) ?></span>
    </div>
    <?php endif; ?>

    <div class="qk-card">
      <div class="qk-table-toolbar">
        <div class="qk-table-toolbar-left">
          <span class="qk-text-muted small">Classi di pericolo standard ADR (Tabella A)</span>
        </div>
        <div class="qk-table-toolbar-right">
          <button class="btn-qk btn-qk-primary btn-qk-sm"
                  data-bs-toggle="modal" data-bs-target="#modal-classe"
                  data-mode="create">
            <i class="fa-solid fa-plus"></i> Aggiungi
          </button>
        </div>
      </div>
      <div class="qk-table-wrap">
        <?php if (empty($classi)): ?>
        <div class="qk-empty">
          <i class="fa-regular fa-triangle-exclamation"></i>
          <p>Nessuna classe registrata.</p>
        </div>
        <?php else: ?>
        <table class="qk-table">
          <thead>
            <tr>
              <th style="width:90px">Codice</th>
              <th>Denominazione</th>
              <th style="width:120px">Divisioni</th>
              <th class="col-actions"></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($classi as $cl): ?>
            <tr class="qk-tr-clickable"
                data-bs-toggle="modal" data-bs-target="#modal-classe"
                data-mode="edit"
                data-codice="<?= e($cl['codice']) ?>"
                data-denominazione="<?= e($cl['denominazione']) ?>"
                data-divisioni="<?= $cl['ha_divisioni'] ? '1' : '0' ?>">
              <td><span class="fw-semibold font-monospace qk-text-accent"><?= e($cl['codice']) ?></span></td>
              <td><?= e($cl['denominazione']) ?></td>
              <td class="text-center">
                <?= $cl['ha_divisioni']
                    ? '<i class="fa-solid fa-check text-success"></i>'
                    : '<span class="qk-text-muted">No</span>' ?>
              </td>
              <td class="col-actions" onclick="event.stopPropagation()">
                <div class="d-flex gap-1 justify-content-end">
                  <form method="post" action="<?= url('adr') ?>" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="tab"    value="classi">
                    <input type="hidden" name="action" value="delete_classe">
                    <input type="hidden" name="codice" value="<?= e($cl['codice']) ?>">
                    <button type="submit" class="btn-qk btn-qk-danger btn-qk-sm"
                            onclick="return confirm('Eliminare la classe <?= e($cl['codice']) ?>?')">
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
  </div><!-- /tab-classi -->
