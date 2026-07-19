<!-- ── TAB: CODICI ONU ─────────────────────────────────────────────── -->
  <div class="tab-pane fade <?= $tab === 'codici' ? 'show active' : '' ?>"
       id="tab-codici" role="tabpanel">

    <div class="qk-card">
      <!-- Toolbar -->
      <div class="qk-table-toolbar">
        <div class="qk-table-toolbar-left">
          <form method="get" class="d-flex gap-2 align-items-center">
            <input type="hidden" name="tab" value="codici">
            <div class="qk-filter-wrap">
              <i class="fa-solid fa-magnifying-glass"></i>
              <input type="text" name="q" class="qk-filter-input"
                     placeholder="Numero ONU o classe…" value="<?= e($search) ?>">
            </div>
            <button type="submit" class="btn-qk btn-qk-secondary btn-qk-sm">
              <i class="fa-solid fa-filter"></i> Filtra
            </button>
            <?php if ($search): ?>
            <a href="<?= url('adr?tab=codici') ?>" class="btn-qk btn-qk-secondary btn-qk-sm">
              <i class="fa-solid fa-xmark"></i> Pulisci
            </a>
            <?php endif; ?>
          </form>
        </div>
        <div class="qk-table-toolbar-right d-flex gap-2">
          <button type="button" id="btn-esporta"
                  class="btn-qk btn-qk-secondary btn-qk-sm">
            <i class="fa-solid fa-file-excel me-1"></i>Esporta Lista
          </button>
          <button type="button"
                  class="btn-qk btn-qk-secondary btn-qk-sm"
                  data-bs-toggle="modal" data-bs-target="#modal-import">
            <i class="fa-solid fa-file-import me-1"></i>Importa
          </button>
          <a href="<?= url('adr/codici/create') ?>" class="btn-qk btn-qk-primary btn-qk-sm">
            <i class="fa-solid fa-plus"></i> Nuovo
          </a>
        </div>
      </div>

      <!-- Tabella -->
      <div class="qk-table-wrap">
        <?php if (empty($codici)): ?>
        <div class="qk-empty">
          <i class="fa-regular fa-triangle-exclamation"></i>
          <p><?= $search ? 'Nessun codice ONU trovato.' : 'Nessun codice ONU registrato. Usa "Nuovo" per aggiungerne uno.' ?></p>
        </div>
        <?php else: ?>
        <table class="qk-table">
          <thead>
            <tr>
              <th style="width:90px">UN #</th>
              <th>Nome / Descrizione</th>
              <th style="width:80px">Classe</th>
              <th style="width:100px">Etichette</th>
              <th style="width:75px">Gr. Imb.</th>
              <th style="width:80px">Cat. Trasp.</th>
              <th style="width:80px">Galleria</th>
              <th style="width:70px">Flag</th>
              <th class="col-actions"></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($codici as $c): ?>
            <tr>
              <td>
                <a href="<?= url('adr/codici/edit?id=' . (int)$c['id']) ?>"
                   class="fw-semibold font-monospace qk-text-accent text-decoration-none">
                  UN <?= str_pad((string)$c['codice_onu'], 4, '0', STR_PAD_LEFT) ?>
                </a>
              </td>
              <td>
                <?php if (!empty($c['nome_descrizione'])): ?>
                  <span class="small"><?= e(mb_strimwidth($c['nome_descrizione'], 0, 80, '…')) ?></span>
                <?php else: ?>
                  <span class="qk-text-muted small">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($c['classe_pericolo']): ?>
                  <span class="qk-badge draft" title="<?= e($c['classe_label'] ?? '') ?>">
                    <?= e($c['classe_pericolo']) ?>
                  </span>
                <?php else: ?>
                  <span class="qk-text-muted">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($c['etichette_str']): ?>
                  <span class="small fw-semibold"><?= e(str_replace(',', ', ', $c['etichette_str'])) ?></span>
                <?php else: ?>
                  <span class="qk-text-muted">—</span>
                <?php endif; ?>
              </td>
              <td><?= $c['gruppo_imballaggio'] ? e($c['gruppo_imballaggio']) : '<span class="qk-text-muted">—</span>' ?></td>
              <td><?= $c['categoria_trasporto'] !== null ? e((string)$c['categoria_trasporto']) : '<span class="qk-text-muted">—</span>' ?></td>
              <td>
                <?php if ($c['codice_restrizione_gallerie']): ?>
                  <code><?= e($c['codice_restrizione_gallerie']) ?></code>
                <?php else: ?>
                  <span class="qk-text-muted">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($c['e_merce_alto_rischio']): ?><span title="Merce alto rischio" class="me-1"><i class="fa-solid fa-skull-crossbones text-danger"></i></span><?php endif; ?>
                <?php if ($c['e_inquinante_marino']): ?><span title="Inquinante marino"><i class="fa-solid fa-fish-fins" style="color:var(--qk-info)"></i></span><?php endif; ?>
              </td>
              <td class="col-actions">
                <div class="d-flex gap-1 justify-content-end">
                  <a href="<?= url('adr/codici/edit?id=' . (int)$c['id']) ?>"
                     class="btn-qk btn-qk-secondary btn-qk-sm" title="Modifica">
                    <i class="fa-solid fa-pen"></i>
                  </a>
                  <form method="post" action="<?= url('adr/codici/delete') ?>" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                    <button type="submit" class="btn-qk btn-qk-danger btn-qk-sm" onclick="return confirm('Eliminare UN <?= str_pad((string)$c['codice_onu'], 4, '0', STR_PAD_LEFT) ?>?')">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                  </form>
                </div>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div><!-- /qk-table-wrap -->

      <?php if ($codici_pages > 1): ?>
      <div class="d-flex align-items-center justify-content-between px-3 py-2 border-top" style="font-size:.875rem">
        <span class="qk-text-muted">
          <?= number_format($codici_total) ?> codici ·
          pagina <?= $page ?> di <?= $codici_pages ?>
        </span>
        <div class="d-flex gap-1">
          <?php
          $qBase = http_build_query(array_filter(['tab' => 'codici', 'q' => $search]));
          $prevPage = $page > 1 ? $page - 1 : null;
          $nextPage = $page < $codici_pages ? $page + 1 : null;
          ?>
          <a href="?<?= $qBase ?>&page=1"
             class="btn-qk btn-qk-secondary btn-qk-sm <?= $page <= 1 ? 'disabled' : '' ?>">
            <i class="fa-solid fa-angles-left"></i>
          </a>
          <a href="?<?= $qBase ?>&page=<?= $prevPage ?? 1 ?>"
             class="btn-qk btn-qk-secondary btn-qk-sm <?= !$prevPage ? 'disabled' : '' ?>">
            <i class="fa-solid fa-angle-left"></i>
          </a>
          <?php
          // Mostra al massimo 5 pagine intorno alla corrente
          $from = max(1, $page - 2);
          $to   = min($codici_pages, $page + 2);
          for ($p = $from; $p <= $to; $p++):
          ?>
          <a href="?<?= $qBase ?>&page=<?= $p ?>"
             class="btn-qk btn-qk-secondary btn-qk-sm <?= $p === $page ? 'active' : '' ?>">
            <?= $p ?>
          </a>
          <?php endfor; ?>
          <a href="?<?= $qBase ?>&page=<?= $nextPage ?? $codici_pages ?>"
             class="btn-qk btn-qk-secondary btn-qk-sm <?= !$nextPage ? 'disabled' : '' ?>">
            <i class="fa-solid fa-angle-right"></i>
          </a>
          <a href="?<?= $qBase ?>&page=<?= $codici_pages ?>"
             class="btn-qk btn-qk-secondary btn-qk-sm <?= $page >= $codici_pages ? 'disabled' : '' ?>">
            <i class="fa-solid fa-angles-right"></i>
          </a>
        </div>
      </div>
      <?php endif; ?>

    </div><!-- /qk-card -->
  </div><!-- /tab-codici -->
