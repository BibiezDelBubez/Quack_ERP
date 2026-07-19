<!-- ── TAB: CODICI GALLERIA ────────────────────────────────────────── -->
  <div class="tab-pane fade <?= $tab === 'gallerie' ? 'show active' : '' ?>"
       id="tab-gallerie" role="tabpanel">

    <?php if (!empty($errors['galleria'])): ?>
    <div class="qk-alert qk-alert-danger mb-3">
      <i class="fa-solid fa-circle-xmark"></i>
      <span><?= e($errors['galleria']) ?></span>
    </div>
    <?php endif; ?>

    <div class="qk-card">
      <div class="qk-table-toolbar">
        <div class="qk-table-toolbar-left">
          <span class="qk-text-muted small">Restrizioni di transito in galleria (capitolo ADR 8.6)</span>
        </div>
        <div class="qk-table-toolbar-right">
          <button class="btn-qk btn-qk-primary btn-qk-sm"
                  data-bs-toggle="modal" data-bs-target="#modal-galleria"
                  data-mode="create">
            <i class="fa-solid fa-plus"></i> Aggiungi
          </button>
        </div>
      </div>
      <div class="qk-table-wrap">
        <?php if (empty($gallerie)): ?>
        <div class="qk-empty">
          <i class="fa-regular fa-road-tunnel"></i>
          <p>Nessun codice galleria registrato.</p>
        </div>
        <?php else: ?>
        <table class="qk-table">
          <thead>
            <tr>
              <th style="width:90px">Codice</th>
              <th>Descrizione</th>
              <th style="width:130px">Cisterna</th>
              <th style="width:160px">Categorie vietate</th>
              <th class="col-actions"></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($gallerie as $g): ?>
            <tr class="qk-tr-clickable"
                data-bs-toggle="modal" data-bs-target="#modal-galleria"
                data-mode="edit"
                data-codice="<?= e($g['codice']) ?>"
                data-descrizione="<?= e($g['descrizione'] ?? '') ?>"
                data-cisterna="<?= e($g['si_applica_a_cisterna'] ?? '') ?>">
              <td><code class="fw-semibold qk-text-accent"><?= e($g['codice']) ?></code></td>
              <td><?= e($g['descrizione'] ?? '') ?></td>
              <td>
                <?= $g['si_applica_a_cisterna']
                    ? '<code>' . e($g['si_applica_a_cisterna']) . '</code>'
                    : '<span class="qk-text-muted">—</span>' ?>
              </td>
              <td>
                <?php if ($g['categorie']): ?>
                  <?php foreach (explode(', ', $g['categorie']) as $cat): ?>
                    <span class="qk-badge draft me-1"><?= e(trim($cat)) ?></span>
                  <?php endforeach; ?>
                <?php else: ?>
                  <span class="qk-text-muted">—</span>
                <?php endif; ?>
              </td>
              <td class="col-actions" onclick="event.stopPropagation()">
                <div class="d-flex gap-1 justify-content-end">
                  <form method="post" action="<?= url('adr') ?>" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="tab"    value="gallerie">
                    <input type="hidden" name="action" value="delete_galleria">
                    <input type="hidden" name="codice" value="<?= e($g['codice']) ?>">
                    <button type="submit" class="btn-qk btn-qk-danger btn-qk-sm"
                            onclick="return confirm('Eliminare il codice <?= e($g['codice']) ?>?')">
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
  </div><!-- /tab-gallerie -->
