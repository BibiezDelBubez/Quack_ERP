<!-- ── TAB: CATEGORIE DI TRASPORTO ─────────────────────────────────────── -->
  <div class="tab-pane fade <?= $tab === 'categorie' ? 'show active' : '' ?>"
       id="tab-categorie" role="tabpanel">

    <?php if (!empty($errors['categoria'])): ?>
    <div class="qk-alert qk-alert-danger mb-3">
      <i class="fa-solid fa-circle-xmark"></i>
      <span><?= e($errors['categoria']) ?></span>
    </div>
    <?php endif; ?>

    <div class="qk-card">
      <div class="qk-table-toolbar">
        <div class="qk-table-toolbar-left">
          <span class="qk-text-muted small">Categorie di trasporto ADR (col. 15 Tabella A) — determinano il calcolo dei punti per i piccoli carichi</span>
        </div>
        <div class="qk-table-toolbar-right">
          <button class="btn-qk btn-qk-primary btn-qk-sm"
                  data-bs-toggle="modal" data-bs-target="#modal-categoria"
                  data-mode="create">
            <i class="fa-solid fa-plus"></i> Aggiungi
          </button>
        </div>
      </div>
      <div class="qk-table-wrap">
        <?php if (empty($categorie_trasporto)): ?>
        <div class="qk-empty">
          <i class="fa-regular fa-truck"></i>
          <p>Nessuna categoria registrata.</p>
        </div>
        <?php else: ?>
        <table class="qk-table">
          <thead>
            <tr>
              <th style="width:90px">Categoria</th>
              <th style="width:140px">Moltiplicatore punti</th>
              <th style="width:160px">Soglia esenzione (kg/l)</th>
              <th>Descrizione</th>
              <th class="col-actions"></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($categorie_trasporto as $ct): ?>
            <tr class="qk-tr-clickable"
                data-bs-toggle="modal" data-bs-target="#modal-categoria"
                data-mode="edit"
                data-categoria="<?= e((string)$ct['categoria']) ?>"
                data-moltiplicatore="<?= e((string)$ct['moltiplicatore_punti']) ?>"
                data-soglia="<?= e((string)($ct['soglia_esenzione_kg_l'] ?? '')) ?>"
                data-descrizione="<?= e($ct['descrizione'] ?? '') ?>">
              <td><span class="fw-semibold font-monospace qk-text-accent"><?= e((string)$ct['categoria']) ?></span></td>
              <td class="text-center"><?= e((string)$ct['moltiplicatore_punti']) ?></td>
              <td class="text-center">
                <?= $ct['soglia_esenzione_kg_l'] !== null
                    ? e((string)$ct['soglia_esenzione_kg_l'])
                    : '<span class="qk-text-muted">—</span>' ?>
              </td>
              <td class="small"><?= $ct['descrizione'] ? e($ct['descrizione']) : '<span class="qk-text-muted">—</span>' ?></td>
              <td class="col-actions" onclick="event.stopPropagation()">
                <div class="d-flex gap-1 justify-content-end">
                  <form method="post" action="<?= url('adr') ?>" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="tab"       value="categorie">
                    <input type="hidden" name="action"    value="delete_categoria">
                    <input type="hidden" name="categoria" value="<?= e((string)$ct['categoria']) ?>">
                    <button type="submit" class="btn-qk btn-qk-danger btn-qk-sm"
                            onclick="return confirm('Eliminare la categoria <?= (int)$ct['categoria'] ?>?')">
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
  </div><!-- /tab-categorie -->
