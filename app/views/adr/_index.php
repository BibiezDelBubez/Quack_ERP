<?php
/**
 * adr/_index.php — Vista principale ADR con 6 tab
 *
 * Variabili attese:
 *   $tab                     string  tab attiva: codici|classi|imballaggio|gallerie|categorie|classificazioni
 *   $ok                      ?string messaggio successo inline
 *   $errors                  array   errori inline
 *   $search                  string  testo ricerca codici ONU
 *   $codici                  array   righe adr_codici_onu
 *   $classi                  array   righe adr_classi_pericolo
 *   $imballaggi              array   righe adr_gruppi_imballaggio
 *   $gallerie                array   righe adr_codici_restrizione_gallerie
 *   $categorie_trasporto     array   righe adr_categorie_trasporto
 *   $codici_classificazione  array   righe adr_codici_classificazione
 */

$page_actions = '<a href="' . url('adr/codici/create') . '" class="btn-qk btn-qk-primary">
    <i class="fa-solid fa-plus"></i> Nuovo codice ONU
</a>';

/* JSON per il JS (export / import) */
$codici_json = json_encode($codici, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
$import_url  = url('adr/codici/import');
$classificazioni_json = json_encode($codici_classificazione, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
$import_classificazioni_url  = url('adr/classificazioni/import');
?>

<?php if (!empty($errors['_global'])): ?>
<div class="qk-alert qk-alert-danger mb-3">
  <i class="fa-solid fa-circle-xmark"></i>
  <span><?= e($errors['_global']) ?></span>
</div>
<?php endif; ?>

<!-- ═══════════════════════ TAB NAV ═══════════════════════════════════════ -->
<ul class="nav nav-tabs qk-tabs mb-0" id="adrTabs" role="tablist">
  <li class="nav-item" role="presentation">
    <button class="nav-link <?= $tab === 'codici'      ? 'active' : '' ?>"
            id="tab-btn-codici" data-bs-toggle="tab" data-bs-target="#tab-codici"
            data-tab-key="codici" type="button" role="tab">
      <i class="fa-solid fa-list-ol fa-fw me-1"></i>Codici ONU
      <span class="badge bg-secondary ms-1"><?= count($codici) ?></span>
    </button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link <?= $tab === 'classi'      ? 'active' : '' ?>"
            id="tab-btn-classi" data-bs-toggle="tab" data-bs-target="#tab-classi"
            data-tab-key="classi" type="button" role="tab">
      <i class="fa-solid fa-fire-flame-curved fa-fw me-1"></i>Classi pericolo
      <span class="badge bg-secondary ms-1"><?= count($classi) ?></span>
    </button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link <?= $tab === 'imballaggio' ? 'active' : '' ?>"
            id="tab-btn-imballaggio" data-bs-toggle="tab" data-bs-target="#tab-imballaggio"
            data-tab-key="imballaggio" type="button" role="tab">
      <i class="fa-solid fa-box-taped fa-fw me-1"></i>Gruppi imballaggio
      <span class="badge bg-secondary ms-1"><?= count($imballaggi) ?></span>
    </button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link <?= $tab === 'gallerie'   ? 'active' : '' ?>"
            id="tab-btn-gallerie" data-bs-toggle="tab" data-bs-target="#tab-gallerie"
            data-tab-key="gallerie" type="button" role="tab">
      <i class="fa-solid fa-car-tunnel fa-fw me-1"></i>Codici galleria
      <span class="badge bg-secondary ms-1"><?= count($gallerie) ?></span>
    </button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link <?= $tab === 'categorie' ? 'active' : '' ?>"
            id="tab-btn-categorie" data-bs-toggle="tab" data-bs-target="#tab-categorie"
            data-tab-key="categorie" type="button" role="tab">
      <i class="fa-solid fa-truck fa-fw me-1"></i>Categorie trasporto
      <span class="badge bg-secondary ms-1"><?= count($categorie_trasporto) ?></span>
    </button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link <?= $tab === 'classificazioni' ? 'active' : '' ?>"
            id="tab-btn-classificazioni" data-bs-toggle="tab" data-bs-target="#tab-classificazioni"
            data-tab-key="classificazioni" type="button" role="tab">
      <i class="fa-solid fa-barcode fa-fw me-1"></i>Codici classificazione
      <span class="badge bg-secondary ms-1"><?= count($codici_classificazione) ?></span>
    </button>
  </li>
</ul>

<!-- ═══════════════════════ TAB CONTENT ══════════════════════════════════ -->
<div class="tab-content mt-0">

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
                <span class="fw-semibold font-monospace">
                  UN <?= e((string)$c['codice_onu']) ?>
                </span>
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
                <div class="dropdown">
                  <button class="btn-qk btn-qk-secondary btn-qk-sm px-2"
                          data-bs-toggle="dropdown">
                    <i class="fa-solid fa-ellipsis-vertical"></i>
                  </button>
                  <ul class="dropdown-menu dropdown-menu-end">
                    <li>
                      <a class="dropdown-item"
                         href="<?= url('adr/codici/edit?id=' . (int)$c['id']) ?>">
                        <i class="fa-solid fa-pen fa-fw me-2"></i>Modifica
                      </a>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                      <form method="post" action="<?= url('adr/codici/delete') ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                        <button type="submit" class="dropdown-item text-danger"
                                onclick="return confirm('Eliminare UN <?= (int)$c['codice_onu'] ?>?')">
                          <i class="fa-solid fa-trash fa-fw me-2"></i>Elimina
                        </button>
                      </form>
                    </li>
                  </ul>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div><!-- /qk-table-wrap -->
    </div><!-- /qk-card -->
  </div><!-- /tab-codici -->


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
            <tr>
              <td><span class="fw-semibold font-monospace"><?= e($cl['codice']) ?></span></td>
              <td><?= e($cl['denominazione']) ?></td>
              <td class="text-center">
                <?= $cl['ha_divisioni']
                    ? '<i class="fa-solid fa-check text-success"></i>'
                    : '<span class="qk-text-muted">No</span>' ?>
              </td>
              <td class="col-actions">
                <div class="d-flex gap-1 justify-content-end">
                  <button class="btn-qk btn-qk-secondary btn-qk-sm"
                          data-bs-toggle="modal" data-bs-target="#modal-classe"
                          data-mode="edit"
                          data-codice="<?= e($cl['codice']) ?>"
                          data-denominazione="<?= e($cl['denominazione']) ?>"
                          data-divisioni="<?= $cl['ha_divisioni'] ? '1' : '0' ?>">
                    <i class="fa-solid fa-pen"></i>
                  </button>
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
            <tr>
              <td><span class="fw-semibold font-monospace"><?= e($imb['codice']) ?></span></td>
              <td><?= e($imb['descrizione']) ?></td>
              <td class="col-actions">
                <div class="d-flex gap-1 justify-content-end">
                  <button class="btn-qk btn-qk-secondary btn-qk-sm"
                          data-bs-toggle="modal" data-bs-target="#modal-imb"
                          data-mode="edit"
                          data-codice="<?= e($imb['codice']) ?>"
                          data-descrizione="<?= e($imb['descrizione']) ?>">
                    <i class="fa-solid fa-pen"></i>
                  </button>
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
            <tr>
              <td><code class="fw-semibold"><?= e($g['codice']) ?></code></td>
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
              <td class="col-actions">
                <div class="d-flex gap-1 justify-content-end">
                  <button class="btn-qk btn-qk-secondary btn-qk-sm"
                          data-bs-toggle="modal" data-bs-target="#modal-galleria"
                          data-mode="edit"
                          data-codice="<?= e($g['codice']) ?>"
                          data-descrizione="<?= e($g['descrizione'] ?? '') ?>"
                          data-cisterna="<?= e($g['si_applica_a_cisterna'] ?? '') ?>">
                    <i class="fa-solid fa-pen"></i>
                  </button>
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
            <tr>
              <td><span class="fw-semibold font-monospace"><?= e((string)$ct['categoria']) ?></span></td>
              <td class="text-center"><?= e((string)$ct['moltiplicatore_punti']) ?></td>
              <td class="text-center">
                <?= $ct['soglia_esenzione_kg_l'] !== null
                    ? e((string)$ct['soglia_esenzione_kg_l'])
                    : '<span class="qk-text-muted">—</span>' ?>
              </td>
              <td class="small"><?= $ct['descrizione'] ? e($ct['descrizione']) : '<span class="qk-text-muted">—</span>' ?></td>
              <td class="col-actions">
                <div class="d-flex gap-1 justify-content-end">
                  <button class="btn-qk btn-qk-secondary btn-qk-sm"
                          data-bs-toggle="modal" data-bs-target="#modal-categoria"
                          data-mode="edit"
                          data-categoria="<?= e((string)$ct['categoria']) ?>"
                          data-moltiplicatore="<?= e((string)$ct['moltiplicatore_punti']) ?>"
                          data-soglia="<?= e((string)($ct['soglia_esenzione_kg_l'] ?? '')) ?>"
                          data-descrizione="<?= e($ct['descrizione'] ?? '') ?>">
                    <i class="fa-solid fa-pen"></i>
                  </button>
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
            <tr>
              <td><span class="fw-semibold font-monospace"><?= e($cf['codice']) ?></span></td>
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
              <td class="col-actions">
                <div class="d-flex gap-1 justify-content-end">
                  <button class="btn-qk btn-qk-secondary btn-qk-sm"
                          data-bs-toggle="modal" data-bs-target="#modal-classificazione"
                          data-mode="edit"
                          data-codice="<?= e($cf['codice']) ?>"
                          data-classe="<?= e($cf['codice_classe']) ?>"
                          data-descrizione="<?= e($cf['descrizione'] ?? '') ?>"
                          data-primario="<?= e($cf['pericolo_primario'] ?? '') ?>"
                          data-secondario="<?= e($cf['pericolo_secondario'] ?? '') ?>">
                    <i class="fa-solid fa-pen"></i>
                  </button>
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


</div><!-- /tab-content -->


<!-- ═══════════════════════ MODALI LOOKUP ════════════════════════════════ -->

<!-- Modal: Classe di pericolo -->
<div class="modal fade" id="modal-classe" tabindex="-1" aria-labelledby="modal-classe-label" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" action="<?= url('adr') ?>" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="tab"    value="classi">
      <input type="hidden" name="action" value="save_classe">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="modal-classe-label">Classe di pericolo</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="qk-form-label">Codice <span class="text-danger">*</span></label>
            <input type="text" name="codice" id="mc-codice" class="form-control font-monospace"
                   placeholder="es. 3, 4.1, 6.1" maxlength="5" required>
            <div class="qk-form-hint">Per classi con divisioni, usa il codice padre (es. <code>1</code> non <code>1.1</code>).</div>
          </div>
          <div class="mb-3">
            <label class="qk-form-label">Denominazione <span class="text-danger">*</span></label>
            <input type="text" name="denominazione" id="mc-denom" class="form-control" maxlength="100" required>
          </div>
          <div class="mb-3">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="ha_divisioni" id="mc-div" value="1">
              <label class="form-check-label" for="mc-div">Ha divisioni</label>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-qk btn-qk-secondary" data-bs-dismiss="modal">Annulla</button>
          <button type="submit" class="btn-qk btn-qk-primary">
            <i class="fa-solid fa-floppy-disk me-1"></i>Salva
          </button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Gruppo imballaggio -->
<div class="modal fade" id="modal-imb" tabindex="-1" aria-labelledby="modal-imb-label" aria-hidden="true">
  <div class="modal-dialog modal-sm">
    <form method="post" action="<?= url('adr') ?>" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="tab"    value="imballaggio">
      <input type="hidden" name="action" value="save_imb">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="modal-imb-label">Gruppo imballaggio</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="qk-form-label">Codice <span class="text-danger">*</span></label>
            <input type="text" name="codice" id="mi-codice" class="form-control font-monospace text-uppercase"
                   placeholder="I, II, III" maxlength="3" required>
          </div>
          <div class="mb-3">
            <label class="qk-form-label">Descrizione <span class="text-danger">*</span></label>
            <input type="text" name="descrizione" id="mi-desc" class="form-control" maxlength="100" required>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-qk btn-qk-secondary" data-bs-dismiss="modal">Annulla</button>
          <button type="submit" class="btn-qk btn-qk-primary">
            <i class="fa-solid fa-floppy-disk me-1"></i>Salva
          </button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Codice galleria -->
<div class="modal fade" id="modal-galleria" tabindex="-1" aria-labelledby="modal-galleria-label" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" action="<?= url('adr') ?>" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="tab"    value="gallerie">
      <input type="hidden" name="action" value="save_galleria">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="modal-galleria-label">Codice galleria</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3 mb-3">
            <div class="col-sm-4">
              <label class="qk-form-label">Codice <span class="text-danger">*</span></label>
              <input type="text" name="codice" id="mg-codice" class="form-control font-monospace"
                     placeholder="es. B, C/D" maxlength="10" required>
            </div>
            <div class="col-sm-8">
              <label class="qk-form-label">Codice cisterna</label>
              <input type="text" name="si_applica_a_cisterna" id="mg-cisterna" class="form-control font-monospace"
                     placeholder="Codice diverso per cisterne">
              <div class="qk-form-hint">Lascia vuoto se il codice è lo stesso per colli e cisterne.</div>
            </div>
          </div>
          <div class="mb-3">
            <label class="qk-form-label">Descrizione</label>
            <input type="text" name="descrizione" id="mg-desc" class="form-control" maxlength="255">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-qk btn-qk-secondary" data-bs-dismiss="modal">Annulla</button>
          <button type="submit" class="btn-qk btn-qk-primary">
            <i class="fa-solid fa-floppy-disk me-1"></i>Salva
          </button>
        </div>
      </div>
    </form>
  </div>
</div>


<!-- Modal: Categoria di trasporto -->
<div class="modal fade" id="modal-categoria" tabindex="-1" aria-labelledby="modal-categoria-label" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" action="<?= url('adr') ?>" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="tab"    value="categorie">
      <input type="hidden" name="action" value="save_categoria">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="modal-categoria-label">Categoria di trasporto</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3 mb-3">
            <div class="col-sm-4">
              <label class="qk-form-label">Categoria <span class="text-danger">*</span></label>
              <input type="number" name="categoria" id="mcat-cat" class="form-control font-monospace"
                     placeholder="0–4" min="0" max="4" required>
            </div>
            <div class="col-sm-4">
              <label class="qk-form-label">Moltiplicatore punti <span class="text-danger">*</span></label>
              <input type="number" name="moltiplicatore_punti" id="mcat-molt" class="form-control"
                     placeholder="es. 50" min="0">
            </div>
            <div class="col-sm-4">
              <label class="qk-form-label">Soglia esenzione (kg/l)</label>
              <input type="number" name="soglia_esenzione_kg_l" id="mcat-soglia" class="form-control"
                     placeholder="Lascia vuoto se nessuna" min="0">
            </div>
          </div>
          <div class="mb-3">
            <label class="qk-form-label">Descrizione</label>
            <input type="text" name="descrizione" id="mcat-desc" class="form-control" maxlength="255">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-qk btn-qk-secondary" data-bs-dismiss="modal">Annulla</button>
          <button type="submit" class="btn-qk btn-qk-primary">
            <i class="fa-solid fa-floppy-disk me-1"></i>Salva
          </button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Codice classificazione -->
<div class="modal fade" id="modal-classificazione" tabindex="-1" aria-labelledby="modal-classif-label" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form method="post" action="<?= url('adr') ?>" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="tab"    value="classificazioni">
      <input type="hidden" name="action" value="save_classificazione">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="modal-classif-label">Codice classificazione</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3 mb-3">
            <div class="col-sm-4">
              <label class="qk-form-label">Codice <span class="text-danger">*</span></label>
              <input type="text" name="codice" id="mcl-codice" class="form-control font-monospace text-uppercase"
                     placeholder="es. F1, TF1, 2A" maxlength="20" required>
            </div>
            <div class="col-sm-4">
              <label class="qk-form-label">Classe ADR <span class="text-danger">*</span></label>
              <select name="codice_classe" id="mcl-classe" class="form-select" required>
                <option value="">— Seleziona —</option>
                <?php foreach ($classi as $cl): ?>
                <option value="<?= e($cl['codice']) ?>"><?= e($cl['codice']) ?> — <?= e(mb_strimwidth($cl['denominazione'], 0, 40, '…')) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-4">
              <label class="qk-form-label">Pericolo primario</label>
              <input type="text" name="pericolo_primario" id="mcl-prim" class="form-control"
                     placeholder="es. Infiammabile" maxlength="50">
            </div>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-sm-4">
              <label class="qk-form-label">Pericolo secondario</label>
              <input type="text" name="pericolo_secondario" id="mcl-sec" class="form-control"
                     placeholder="es. Tossico" maxlength="50">
            </div>
            <div class="col-sm-8">
              <label class="qk-form-label">Descrizione</label>
              <input type="text" name="descrizione" id="mcl-desc" class="form-control" maxlength="255">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-qk btn-qk-secondary" data-bs-dismiss="modal">Annulla</button>
          <button type="submit" class="btn-qk btn-qk-primary">
            <i class="fa-solid fa-floppy-disk me-1"></i>Salva
          </button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ═══════════════════════ MODALE IMPORT ════════════════════════════════ -->
<div class="modal fade" id="modal-import" tabindex="-1"
     aria-labelledby="modal-import-label" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title" id="modal-import-label">
          <i class="fa-solid fa-file-import me-2"></i>Importazione Massiva Codici ONU
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">

        <!-- Step: istruzioni (default) -->
        <div id="import-step-instructions">
          <p class="mb-3">Per caricare più codici ONU in una sola operazione:</p>
          <ol class="mb-4">
            <li class="mb-2">
              Scarica il <strong>tracciato Excel</strong>: un modello precompilato
              con istruzioni e valori ammessi.
            </li>
            <li class="mb-2">
              Compila il foglio <code class="font-monospace">2. IMPORTA_DATI</code>
              con i tuoi dati (rimuovi le righe di esempio prima di caricare).
            </li>
            <li class="mb-2">
              Clicca <strong>Carica file Excel</strong> e seleziona il file compilato.
            </li>
          </ol>
          <div class="qk-alert qk-alert-warning" style="font-size:.85rem">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <span>I nuovi record vengono <strong>aggiunti</strong> alla lista esistente.
            Lo stesso codice ONU può comparire più volte (stati fisici diversi).</span>
          </div>
        </div>

        <!-- Step: elaborazione -->
        <div id="import-step-processing" class="d-none text-center py-5">
          <div class="spinner-border text-primary mb-3" role="status">
            <span class="visually-hidden">Caricamento…</span>
          </div>
          <p class="text-muted mb-0">Elaborazione del file in corso…</p>
        </div>

        <!-- Step: risultato -->
        <div id="import-step-result" class="d-none"></div>

      </div><!-- /modal-body -->

      <div class="modal-footer" id="import-modal-footer">
        <!-- Sempre visibile -->
        <button type="button" class="btn-qk btn-qk-secondary"
                id="import-btn-close" data-bs-dismiss="modal">Annulla</button>

        <!-- Pulsanti modalità istruzioni -->
        <div id="import-footer-normal" class="d-flex gap-2">
          <button type="button" class="btn-qk btn-qk-secondary"
                  id="btn-scarica-tracciato">
            <i class="fa-solid fa-file-arrow-down me-1"></i>Scarica Tracciato
          </button>
          <label class="btn-qk btn-qk-primary mb-0" style="cursor:pointer">
            <i class="fa-solid fa-file-arrow-up me-1"></i>Carica file Excel
            <input type="file" id="import-file-input" accept=".xlsx" class="d-none">
          </label>
        </div>

        <!-- Dopo import OK -->
        <button type="button" class="btn-qk btn-qk-primary d-none"
                id="import-btn-reload" onclick="location.reload()">
          <i class="fa-solid fa-rotate-right me-1"></i>Ricarica pagina
        </button>

        <!-- Dopo import KO -->
        <button type="button" class="btn-qk btn-qk-secondary d-none"
                id="import-btn-retry">Riprova</button>
      </div>

    </div><!-- /modal-content -->
  </div>
</div>

<!-- ═══════════════════════ MODALE IMPORT CLASSIFICAZIONI ════════════════════════ -->
<div class="modal fade" id="modal-import-classificazioni" tabindex="-1"
     aria-labelledby="modal-import-classif-label" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title" id="modal-import-classif-label">
          <i class="fa-solid fa-file-import me-2"></i>Importazione Massiva Codici Classificazione
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">

        <!-- Step: istruzioni (default) -->
        <div id="import-classif-step-instructions">
          <p class="mb-3">Per caricare più codici di classificazione in una sola operazione:</p>
          <ol class="mb-4">
            <li class="mb-2">
              Scarica il <strong>tracciato Excel</strong>: un modello precompilato
              con istruzioni e valori ammessi.
            </li>
            <li class="mb-2">
              Compila il foglio <code class="font-monospace">2. IMPORTA_DATI</code>
              con i tuoi dati (rimuovi le righe di esempio prima di caricare).
            </li>
            <li class="mb-2">
              Clicca <strong>Carica file Excel</strong> e seleziona il file compilato.
            </li>
          </ol>
          <div class="qk-alert qk-alert-warning" style="font-size:.85rem">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <span>I nuovi record vengono <strong>aggiunti</strong> alla lista esistente.
            Il codice di classificazione deve essere univoco.</span>
          </div>
        </div>

        <!-- Step: elaborazione -->
        <div id="import-classif-step-processing" class="d-none text-center py-5">
          <div class="spinner-border text-primary mb-3" role="status">
            <span class="visually-hidden">Caricamento…</span>
          </div>
          <p class="text-muted mb-0">Elaborazione del file in corso…</p>
        </div>

        <!-- Step: risultato -->
        <div id="import-classif-step-result" class="d-none"></div>

      </div><!-- /modal-body -->

      <div class="modal-footer" id="import-classif-modal-footer">
        <!-- Sempre visibile -->
        <button type="button" class="btn-qk btn-qk-secondary"
                id="import-classif-btn-close" data-bs-dismiss="modal">Annulla</button>

        <!-- Pulsanti modalità istruzioni -->
        <div id="import-classif-footer-normal" class="d-flex gap-2">
          <button type="button" class="btn-qk btn-qk-secondary"
                  id="btn-scarica-tracciato-classificazioni">
            <i class="fa-solid fa-file-arrow-down me-1"></i>Scarica Tracciato
          </button>
          <label class="btn-qk btn-qk-primary mb-0" style="cursor:pointer">
            <i class="fa-solid fa-file-arrow-up me-1"></i>Carica file Excel
            <input type="file" id="import-classif-file-input" accept=".xlsx" class="d-none">
          </label>
        </div>

        <!-- Dopo import OK -->
        <button type="button" class="btn-qk btn-qk-primary d-none"
                id="import-classif-btn-reload" onclick="location.reload()">
          <i class="fa-solid fa-rotate-right me-1"></i>Ricarica pagina
        </button>

        <!-- Dopo import KO -->
        <button type="button" class="btn-qk btn-qk-secondary d-none"
                id="import-classif-btn-retry">Riprova</button>
      </div>

    </div><!-- /modal-content -->
  </div>
</div>


<!-- ═══════════════════════ JS ════════════════════════════════════════════ -->
<script src="<?= asset('js/exceljs.min.js') ?>"></script>
<script>
(function () {
  // ── Aggiorna URL quando si cambia tab (bookmark-friendly) ─────────────
  document.querySelectorAll('#adrTabs [data-tab-key]').forEach(function (btn) {
    btn.addEventListener('shown.bs.tab', function () {
      var key = btn.dataset.tabKey;
      var url = new URL(window.location.href);
      url.searchParams.set('tab', key);
      history.replaceState(null, '', url.toString());
    });
  });

  // ── Popola modal classi ────────────────────────────────────────────────
  var modalClasse = document.getElementById('modal-classe');
  if (modalClasse) {
    modalClasse.addEventListener('show.bs.modal', function (e) {
      var btn    = e.relatedTarget;
      var mode   = btn ? btn.dataset.mode : 'create';
      var label  = modalClasse.querySelector('.modal-title');
      var fCodice = document.getElementById('mc-codice');
      var fDenom  = document.getElementById('mc-denom');
      var fDiv    = document.getElementById('mc-div');

      if (mode === 'edit') {
        label.textContent    = 'Modifica classe ' + btn.dataset.codice;
        fCodice.value        = btn.dataset.codice;
        fCodice.readOnly     = true;
        fDenom.value         = btn.dataset.denominazione;
        fDiv.checked         = btn.dataset.divisioni === '1';
      } else {
        label.textContent    = 'Nuova classe di pericolo';
        fCodice.value        = '';
        fCodice.readOnly     = false;
        fDenom.value         = '';
        fDiv.checked         = false;
      }
    });
  }

  // ── Popola modal imballaggio ───────────────────────────────────────────
  var modalImb = document.getElementById('modal-imb');
  if (modalImb) {
    modalImb.addEventListener('show.bs.modal', function (e) {
      var btn    = e.relatedTarget;
      var mode   = btn ? btn.dataset.mode : 'create';
      var label  = modalImb.querySelector('.modal-title');
      var fCodice = document.getElementById('mi-codice');
      var fDesc   = document.getElementById('mi-desc');

      if (mode === 'edit') {
        label.textContent = 'Modifica gruppo ' + btn.dataset.codice;
        fCodice.value     = btn.dataset.codice;
        fCodice.readOnly  = true;
        fDesc.value       = btn.dataset.descrizione;
      } else {
        label.textContent = 'Nuovo gruppo imballaggio';
        fCodice.value     = '';
        fCodice.readOnly  = false;
        fDesc.value       = '';
      }
    });
  }

  // ── Popola modal galleria ──────────────────────────────────────────────
  var modalGalleria = document.getElementById('modal-galleria');
  if (modalGalleria) {
    modalGalleria.addEventListener('show.bs.modal', function (e) {
      var btn    = e.relatedTarget;
      var mode   = btn ? btn.dataset.mode : 'create';
      var label  = modalGalleria.querySelector('.modal-title');
      var fCodice   = document.getElementById('mg-codice');
      var fCisterna = document.getElementById('mg-cisterna');
      var fDesc     = document.getElementById('mg-desc');

      if (mode === 'edit') {
        label.textContent    = 'Modifica codice ' + btn.dataset.codice;
        fCodice.value        = btn.dataset.codice;
        fCodice.readOnly     = true;
        fCisterna.value      = btn.dataset.cisterna;
        fDesc.value          = btn.dataset.descrizione;
      } else {
        label.textContent    = 'Nuovo codice galleria';
        fCodice.value        = '';
        fCodice.readOnly     = false;
        fCisterna.value      = '';
        fDesc.value          = '';
      }
    });
  }

  // ── Popola modal categoria trasporto ──────────────────────────────────
  var modalCat = document.getElementById('modal-categoria');
  if (modalCat) {
    modalCat.addEventListener('show.bs.modal', function (e) {
      var btn   = e.relatedTarget;
      var mode  = btn ? btn.dataset.mode : 'create';
      var label = modalCat.querySelector('.modal-title');
      var fCat  = document.getElementById('mcat-cat');
      var fMolt = document.getElementById('mcat-molt');
      var fSogl = document.getElementById('mcat-soglia');
      var fDesc = document.getElementById('mcat-desc');
      if (mode === 'edit') {
        label.textContent = 'Modifica categoria ' + btn.dataset.categoria;
        fCat.value        = btn.dataset.categoria;
        fCat.readOnly     = true;
        fMolt.value       = btn.dataset.moltiplicatore;
        fSogl.value       = btn.dataset.soglia;
        fDesc.value       = btn.dataset.descrizione;
      } else {
        label.textContent = 'Nuova categoria di trasporto';
        fCat.value        = '';
        fCat.readOnly     = false;
        fMolt.value       = '';
        fSogl.value       = '';
        fDesc.value       = '';
      }
    });
  }

  // ── Popola modal codice classificazione ───────────────────────────────
  var modalClassif = document.getElementById('modal-classificazione');
  if (modalClassif) {
    modalClassif.addEventListener('show.bs.modal', function (e) {
      var btn    = e.relatedTarget;
      var mode   = btn ? btn.dataset.mode : 'create';
      var label  = modalClassif.querySelector('.modal-title');
      var fCodice = document.getElementById('mcl-codice');
      var fClasse = document.getElementById('mcl-classe');
      var fPrim   = document.getElementById('mcl-prim');
      var fSec    = document.getElementById('mcl-sec');
      var fDesc   = document.getElementById('mcl-desc');
      if (mode === 'edit') {
        label.textContent  = 'Modifica classificazione ' + btn.dataset.codice;
        fCodice.value      = btn.dataset.codice;
        fCodice.readOnly   = true;
        fClasse.value      = btn.dataset.classe;
        fPrim.value        = btn.dataset.primario;
        fSec.value         = btn.dataset.secondario;
        fDesc.value        = btn.dataset.descrizione;
      } else {
        label.textContent  = 'Nuovo codice classificazione';
        fCodice.value      = '';
        fCodice.readOnly   = false;
        fClasse.value      = '';
        fPrim.value        = '';
        fSec.value         = '';
        fDesc.value        = '';
      }
    });
  }

  // ══════════════════════════════════════════════════════════════════════
  //  EXPORT / IMPORT — ExcelJS
  // ══════════════════════════════════════════════════════════════════════

  /* Dati correnti (PHP → JS) */
  var CODICI_DATA  = <?= $codici_json ?>;
  var IMPORT_URL   = <?= json_encode($import_url) ?>;

  /* Definizione colonne — uguale per export, tracciato e import */
  var COL_DEFS = [
    { key: 'codice_onu',                      width: 13, note: 'Numero ONU (intero positivo, es. 1203) — OBBLIGATORIO' },
    { key: 'nome_descrizione',                width: 60, note: 'Denominazione ufficiale di trasporto (colonna 2 Tabella A ADR)' },
    { key: 'classe_pericolo',                 width: 16, note: 'Classe di pericolo ADR (es. 2, 3, 4.1, 6.1)' },
    { key: 'codice_classificazione',          width: 22, note: 'Codice classificazione (es. F1, TF1, 2A, 2F)' },
    { key: 'gruppo_imballaggio',              width: 18, note: 'Gruppo imballaggio: I, II o III' },
    { key: 'categoria_trasporto',             width: 18, note: 'Categoria di trasporto: intero 0–4' },
    { key: 'codice_restrizione_gallerie',     width: 24, note: 'Codice restrizione gallerie (es. A, B, C/D, D/E, E)' },
    { key: 'quantita_limitata',               width: 20, note: 'Quantità limitata in ml o g (numero intero)' },
    { key: 'codice_quantita_esente',          width: 22, note: 'Codice quantità esente (es. E0, E1, E2, E3, E4, E5)' },
    { key: 'e_merce_alto_rischio',            width: 20, note: '1 = merce ad alto rischio, 0 = no' },
    { key: 'e_inquinante_marino',             width: 20, note: '1 = inquinante marino, 0 = no' },
    { key: 'inizio_validita',                 width: 15, note: 'Data inizio validità (YYYY-MM-DD), es. 2024-01-01' },
    { key: 'fine_validita',                   width: 15, note: 'Data fine validità (YYYY-MM-DD) — vuoto = indeterminata' },
  ];

  var HEADER_COLOR = 'FF1E3A5F'; /* blu scuro Quack */
  var EXAMPLE_COLOR = 'FFDDE3E9';

  /* Utility: scarica un buffer come file */
  function downloadBuffer(buffer, filename) {
    var blob = new Blob([buffer], {
      type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    });
    var url = URL.createObjectURL(blob);
    var a   = document.createElement('a');
    a.href     = url;
    a.download = filename;
    a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  }

  /* Utility: stile cella intestazione */
  function styleHeader(cell) {
    cell.font      = { bold: true, color: { argb: 'FFFFFFFF' } };
    cell.fill      = { type: 'pattern', pattern: 'solid', fgColor: { argb: HEADER_COLOR } };
    cell.alignment = { vertical: 'middle', horizontal: 'center', wrapText: true };
  }

  /* ── ESPORTA LISTA ─────────────────────────────────────────────────── */
  document.getElementById('btn-esporta').addEventListener('click', async function () {
    var btn = this;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Esportazione…';

    try {
      var wb = new ExcelJS.Workbook();
      wb.creator = 'Quack ERP';
      wb.created = new Date();

      var ws = wb.addWorksheet('Codici ONU');
      ws.columns = COL_DEFS.map(function (c) { return { key: c.key, width: c.width }; });

      /* Intestazione */
      var hRow = ws.addRow(COL_DEFS.map(function (c) { return c.key; }));
      hRow.height = 28;
      hRow.eachCell(styleHeader);

      /* Dati */
      CODICI_DATA.forEach(function (r) {
        var vals = COL_DEFS.map(function (c) {
          var v = r[c.key];
          if (v === null || v === undefined || v === '') return '';
          if (c.key === 'e_merce_alto_rischio' ||
              c.key === 'e_inquinante_marino') {
            return v ? 1 : 0;
          }
          return v;
        });
        ws.addRow(vals).eachCell(function (cell) {
          cell.alignment = { vertical: 'middle' };
        });
      });

      /* Filtri automatici */
      ws.autoFilter = {
        from: { row: 1, column: 1 },
        to:   { row: 1, column: COL_DEFS.length }
      };
      ws.views = [{ state: 'frozen', xSplit: 0, ySplit: 1 }];

      downloadBuffer(
        await wb.xlsx.writeBuffer(),
        'ADR_Codici_ONU_' + new Date().toISOString().slice(0, 10) + '.xlsx'
      );
    } catch (err) {
      alert('Errore durante l\'esportazione: ' + err.message);
    } finally {
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-file-excel me-1"></i>Esporta Lista';
    }
  });

  /* ── SCARICA TRACCIATO ─────────────────────────────────────────────── */
  document.getElementById('btn-scarica-tracciato').addEventListener('click', async function () {
    var btn = this;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Generazione…';

    try {
      var wb = new ExcelJS.Workbook();
      wb.creator = 'Quack ERP';
      wb.created = new Date();

      /* ── Foglio 1: ISTRUZIONI ──────────────────────────────────────── */
      var wsI = wb.addWorksheet('1. ISTRUZIONI');
      wsI.getColumn(1).width = 32;
      wsI.getColumn(2).width = 68;

      var rTitle = wsI.addRow(['TRACCIATO IMPORTAZIONE — ADR Codici ONU', '']);
      rTitle.getCell(1).font = { bold: true, size: 14, color: { argb: 'FF' + HEADER_COLOR.slice(2) } };
      wsI.mergeCells('A1:B1');
      wsI.getRow(1).height = 28;

      wsI.addRow(['Quack ERP — generato il ' + new Date().toLocaleDateString('it-IT'), '']);
      wsI.addRow([]);

      var rIstr = wsI.addRow(['ISTRUZIONI', '']);
      rIstr.getCell(1).font = { bold: true, size: 11 };

      [
        ['1.', 'Passa al foglio "2. IMPORTA_DATI"'],
        ['2.', 'Elimina le 3 righe di esempio (sfondo grigio chiaro) prima di caricare il file'],
        ['3.', 'Inserisci i tuoi dati dalla riga 2 (la riga 1 è l\'intestazione — non modificarla)'],
        ['4.', 'Salva il file e caricalo col pulsante "Carica file Excel" nel modale di importazione'],
        ['5.', 'I record con numero ONU mancante o non valido vengono ignorati automaticamente'],
        ['6.', 'I nuovi record vengono aggiunti alla lista esistente (lo stesso UN# può comparire più volte)'],
      ].forEach(function (row) { wsI.addRow(row); });

      wsI.addRow([]);

      var rCol = wsI.addRow(['COLONNA / CAMPO DB', 'DESCRIZIONE E VALORI AMMESSI']);
      rCol.eachCell(styleHeader);

      COL_DEFS.forEach(function (c) {
        wsI.addRow([c.key, c.note]);
      });

      /* ── Foglio 2: IMPORTA_DATI ────────────────────────────────────── */
      var wsD = wb.addWorksheet('2. IMPORTA_DATI');
      wsD.columns = COL_DEFS.map(function (c) { return { key: c.key, width: c.width }; });

      var hRow = wsD.addRow(COL_DEFS.map(function (c) { return c.key; }));
      hRow.height = 28;
      hRow.eachCell(styleHeader);

      /* Righe di esempio (dati reali ADR, sfondo grigio) */
      var examples = [
        [1009, '2', '2A', '',    '', '',    0, 'E1', '', 0, 0, 0, '', ''],
        [1010, '2', '2F', '',    '', '',    0, 'E0', '', 0, 0, 0, '', ''],
        [1011, '2', '2F', '',    '', '',    0, 'E0', '', 0, 0, 0, '', ''],
      ];
      examples.forEach(function (ex) {
        var exRow = wsD.addRow(ex);
        exRow.eachCell(function (cell) {
          cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: EXAMPLE_COLOR } };
          cell.font = { italic: true, color: { argb: 'FF777777' } };
        });
      });

      wsD.autoFilter = {
        from: { row: 1, column: 1 },
        to:   { row: 1, column: COL_DEFS.length }
      };
      wsD.views = [{ state: 'frozen', xSplit: 0, ySplit: 1 }];

      downloadBuffer(await wb.xlsx.writeBuffer(), 'ADR_Codici_ONU_Tracciato.xlsx');
    } catch (err) {
      alert('Errore durante la generazione del tracciato: ' + err.message);
    } finally {
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-file-arrow-down me-1"></i>Scarica Tracciato';
    }
  });

  /* ── IMPORTAZIONE FILE ─────────────────────────────────────────────── */

  /* Reset modale ogni volta che si apre */
  var modalImport = document.getElementById('modal-import');
  if (modalImport) {
    modalImport.addEventListener('show.bs.modal', function () {
      setImportStep('instructions');
      document.getElementById('import-file-input').value = '';
    });
  }

  /* Pulsante Riprova: riporta alle istruzioni */
  document.getElementById('import-btn-retry').addEventListener('click', function () {
    setImportStep('instructions');
    document.getElementById('import-file-input').value = '';
  });

  document.getElementById('import-file-input').addEventListener('change', async function () {
    var file = this.files[0];
    if (!file) return;

    setImportStep('processing');

    try {
      var buf = await file.arrayBuffer();
      var wb  = new ExcelJS.Workbook();
      await wb.xlsx.load(buf);

      /* Cerca il foglio "2. IMPORTA_DATI", fallback al primo */
      var ws = wb.getWorksheet('2. IMPORTA_DATI') || wb.worksheets[0];
      if (!ws) {
        throw new Error('Impossibile trovare il foglio dati nel file Excel.');
      }

      /* Leggi l'intestazione dalla riga 1 */
      var headers = [];
      ws.getRow(1).eachCell({ includeEmpty: true }, function (cell) {
        headers.push(((cell.value !== null && cell.value !== undefined) ? String(cell.value) : '').trim());
      });

      /* Mappa chiave → indice colonna (0-based) */
      var keyMap = {};
      COL_DEFS.forEach(function (c) {
        var idx = headers.indexOf(c.key);
        if (idx >= 0) keyMap[c.key] = idx;
      });

      if (keyMap['codice_onu'] === undefined) {
        throw new Error(
          'Intestazione "codice_onu" non trovata (riga 1). ' +
          'Assicurati di usare il tracciato originale senza modificare la riga 1.'
        );
      }

      /* Leggi le righe dati (salta riga 1 = intestazione) */
      var rows = [];
      ws.eachRow(function (row, rowNum) {
        if (rowNum === 1) return;
        var obj = {};
        var hasData = false;

        COL_DEFS.forEach(function (c) {
          var cellVal = '';
          if (keyMap[c.key] !== undefined) {
            var cell = row.getCell(keyMap[c.key] + 1);
            var v = cell.value;
            if (v === null || v === undefined) {
              cellVal = '';
            } else if (typeof v === 'object' && v !== null) {
              /* Rich text o formula */
              cellVal = (v.text || v.result || '').toString().trim();
            } else {
              cellVal = v.toString().trim();
            }
          }
          obj[c.key] = cellVal;
          if (c.key === 'codice_onu' && cellVal !== '') hasData = true;
        });

        if (hasData) rows.push(obj);
      });

      if (rows.length === 0) {
        throw new Error(
          'Il file non contiene righe di dati valide. ' +
          'Ricorda di eliminare le righe di esempio prima del caricamento.'
        );
      }

      /* Invia al PHP via FormData (CSRF incluso) */
      var tokenEl = document.querySelector('input[name="csrf_token"]');
      var fd = new FormData();
      fd.append('csrf_token', tokenEl ? tokenEl.value : '');
      fd.append('rows', JSON.stringify(rows));

      var resp   = await fetch(IMPORT_URL, { method: 'POST', body: fd });
      var result = await resp.json();
      showImportResult(result);

    } catch (err) {
      showImportResult({ ok: false, error: err.message });
    }
  });

  function setImportStep(step) {
    /* 'success' e 'error' mostrano entrambi il div risultato */
    var isResult = (step === 'result' || step === 'success' || step === 'error');
    document.getElementById('import-step-instructions').classList.toggle('d-none', step !== 'instructions');
    document.getElementById('import-step-processing').classList.toggle('d-none',   step !== 'processing');
    document.getElementById('import-step-result').classList.toggle('d-none',       !isResult);

    /* Footer: nascondi tutto durante elaborazione, poi mostra i pulsanti giusti */
    var footer = document.getElementById('import-modal-footer');
    footer.style.display = (step === 'processing') ? 'none' : '';

    /* Testo bottone close */
    var closeBtn = document.getElementById('import-btn-close');
    if (closeBtn) closeBtn.textContent = (step === 'instructions') ? 'Annulla' : 'Chiudi';

    /* Visibilità pulsanti footer */
    var elNormal = document.getElementById('import-footer-normal');
    var elReload = document.getElementById('import-btn-reload');
    var elRetry  = document.getElementById('import-btn-retry');
    if (elNormal) elNormal.classList.toggle('d-none', step !== 'instructions');
    if (elReload) elReload.classList.toggle('d-none',  step !== 'success');
    if (elRetry)  elRetry.classList.toggle('d-none',   step !== 'error');
  }

  function showImportResult(result) {
    var el = document.getElementById('import-step-result');

    if (result.ok) {
      var html = '<div class="qk-alert qk-alert-success mb-3">'
        + '<i class="fa-solid fa-circle-check"></i>'
        + '<div><strong>' + result.inserted + '</strong> record importati correttamente'
        + (result.skipped > 0
            ? '; <strong>' + result.skipped + '</strong> saltati per dati non validi'
            : '')
        + '.</div></div>';

      if (result.errors && result.errors.length > 0) {
        html += '<div class="qk-alert qk-alert-warning mb-3">'
          + '<i class="fa-solid fa-triangle-exclamation"></i>'
          + '<div>' + result.errors.map(function (e) {
              return '<div class="small">' + e + '</div>';
            }).join('') + '</div></div>';
      }
      html += '<p class="text-muted small">Ricarica la pagina per visualizzare i nuovi record nella lista.</p>';
      el.innerHTML = html;
      setImportStep('success');

    } else {
      el.innerHTML =
        '<div class="qk-alert qk-alert-danger mb-3">'
        + '<i class="fa-solid fa-circle-xmark"></i>'
        + '<span>' + (result.error || 'Errore sconosciuto.') + '</span></div>'
        + '<p class="text-muted small">Controlla il file e riprova.</p>';
      setImportStep('error');
    }
  }

  // ══════════════════════════════════════════════════════════════════════
  //  CLASSIFICAZIONI EXPORT / IMPORT — ExcelJS
  // ══════════════════════════════════════════════════════════════════════

  var CLASSIFICAZIONI_DATA = <?= $classificazioni_json ?>;
  var IMPORT_CLASSIFICAZIONI_URL = <?= json_encode($import_classificazioni_url) ?>;

  var CLASSIF_COL_DEFS = [
    { key: 'codice',              width: 15, note: 'Codice di classificazione (es. F1, TF1, 2A) — OBBLIGATORIO' },
    { key: 'codice_classe',       width: 15, note: 'Classe ADR di appartenenza (es. 1, 2, 3, 4.1) — OBBLIGATORIO' },
    { key: 'pericolo_primario',   width: 25, note: 'Descrizione del pericolo primario (es. Infiammabile)' },
    { key: 'pericolo_secondario', width: 25, note: 'Descrizione del pericolo secondario (es. Tossico)' },
    { key: 'descrizione',         width: 50, note: 'Descrizione estesa o note del codice' },
  ];

  /* ── ESPORTA CLASSIFICAZIONI ───────────────────────────────────────── */
  var btnEsportaClassif = document.getElementById('btn-esporta-classificazioni');
  if (btnEsportaClassif) {
    btnEsportaClassif.addEventListener('click', async function () {
      var btn = this;
      btn.disabled = true;
      btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Esportazione…';

      try {
        var wb = new ExcelJS.Workbook();
        wb.creator = 'Quack ERP';
        wb.created = new Date();

        var ws = wb.addWorksheet('Codici Classificazione');
        ws.columns = CLASSIF_COL_DEFS.map(function (c) { return { key: c.key, width: c.width }; });

        /* Intestazione */
        var hRow = ws.addRow(CLASSIF_COL_DEFS.map(function (c) { return c.key; }));
        hRow.height = 28;
        hRow.eachCell(styleHeader);

        /* Dati */
        CLASSIFICAZIONI_DATA.forEach(function (r) {
          var vals = CLASSIF_COL_DEFS.map(function (c) {
            var v = r[c.key];
            return (v === null || v === undefined || v === '') ? '' : v;
          });
          ws.addRow(vals).eachCell(function (cell) {
            cell.alignment = { vertical: 'middle' };
          });
        });

        /* Filtri automatici */
        ws.autoFilter = {
          from: { row: 1, column: 1 },
          to:   { row: 1, column: CLASSIF_COL_DEFS.length }
        };
        ws.views = [{ state: 'frozen', xSplit: 0, ySplit: 1 }];

        downloadBuffer(
          await wb.xlsx.writeBuffer(),
          'ADR_Classificazioni_' + new Date().toISOString().slice(0, 10) + '.xlsx'
        );
      } catch (err) {
        alert('Errore durante l\'esportazione: ' + err.message);
      } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-file-excel me-1"></i>Esporta Lista';
      }
    });
  }

  /* ── SCARICA TRACCIATO CLASSIFICAZIONI ──────────────────────────────── */
  var btnScaricaTracciatoClassif = document.getElementById('btn-scarica-tracciato-classificazioni');
  if (btnScaricaTracciatoClassif) {
    btnScaricaTracciatoClassif.addEventListener('click', async function () {
      var btn = this;
      btn.disabled = true;
      btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Generazione…';

      try {
        var wb = new ExcelJS.Workbook();
        wb.creator = 'Quack ERP';
        wb.created = new Date();

        /* Foglio 1: ISTRUZIONI */
        var wsI = wb.addWorksheet('1. ISTRUZIONI');
        wsI.getColumn(1).width = 32;
        wsI.getColumn(2).width = 68;

        var rTitle = wsI.addRow(['TRACCIATO IMPORTAZIONE — ADR Classificazioni', '']);
        rTitle.getCell(1).font = { bold: true, size: 14, color: { argb: 'FF' + HEADER_COLOR.slice(2) } };
        wsI.mergeCells('A1:B1');
        wsI.getRow(1).height = 28;

        wsI.addRow(['Quack ERP — generato il ' + new Date().toLocaleDateString('it-IT'), '']);
        wsI.addRow([]);

        var rIstr = wsI.addRow(['ISTRUZIONI', '']);
        rIstr.getCell(1).font = { bold: true, size: 11 };

        [
          ['1.', 'Passa al foglio "2. IMPORTA_DATI"'],
          ['2.', 'Elimina le 3 righe di esempio (sfondo grigio chiaro) prima di caricare il file'],
          ['3.', 'Inserisci i tuoi dati dalla riga 2 (la riga 1 è l\'intestazione — non modificarla)'],
          ['4.', 'Salva il file e caricalo col pulsante "Carica file Excel" nel modale di importazione'],
          ['5.', 'I record con codice o classe mancante o non valida vengono ignorati automaticamente'],
          ['6.', 'I nuovi record vengono aggiunti alla lista esistente (il codice deve essere univoco)'],
        ].forEach(function (row) { wsI.addRow(row); });

        wsI.addRow([]);

        var rCol = wsI.addRow(['COLONNA / CAMPO DB', 'DESCRIZIONE E VALORI AMMESSI']);
        rCol.eachCell(styleHeader);

        CLASSIF_COL_DEFS.forEach(function (c) {
          wsI.addRow([c.key, c.note]);
        });

        /* Foglio 2: IMPORTA_DATI */
        var wsD = wb.addWorksheet('2. IMPORTA_DATI');
        wsD.columns = CLASSIF_COL_DEFS.map(function (c) { return { key: c.key, width: c.width }; });

        var hRow = wsD.addRow(CLASSIF_COL_DEFS.map(function (c) { return c.key; }));
        hRow.height = 28;
        hRow.eachCell(styleHeader);

        /* Righe di esempio (dati reali ADR, sfondo grigio) */
        var examples = [
          ['F1', '3', 'Infiammabile', '', 'Materie liquide infiammabili con punto d\'infiammabilità <= 60°C'],
          ['TF1', '3', 'Tossico', 'Infiammabile', 'Materie liquide infiammabili tossiche'],
          ['2A', '2', 'Asfissiante', '', 'Gas asfissiante'],
        ];
        examples.forEach(function (ex) {
          var exRow = wsD.addRow(ex);
          exRow.eachCell(function (cell) {
            cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: EXAMPLE_COLOR } };
            cell.font = { italic: true, color: { argb: 'FF777777' } };
          });
        });

        wsD.autoFilter = {
          from: { row: 1, column: 1 },
          to:   { row: 1, column: CLASSIF_COL_DEFS.length }
        };
        wsD.views = [{ state: 'frozen', xSplit: 0, ySplit: 1 }];

        downloadBuffer(await wb.xlsx.writeBuffer(), 'ADR_Classificazioni_Tracciato.xlsx');
      } catch (err) {
        alert('Errore durante la generazione del tracciato: ' + err.message);
      } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-file-arrow-down me-1"></i>Scarica Tracciato';
      }
    });
  }

  /* ── IMPORTAZIONE FILE CLASSIFICAZIONI ──────────────────────────────── */
  var modalImportClassif = document.getElementById('modal-import-classificazioni');
  if (modalImportClassif) {
    modalImportClassif.addEventListener('show.bs.modal', function () {
      setImportClassifStep('instructions');
      document.getElementById('import-classif-file-input').value = '';
    });
  }

  var btnRetryImportClassif = document.getElementById('import-classif-btn-retry');
  if (btnRetryImportClassif) {
    btnRetryImportClassif.addEventListener('click', function () {
      setImportClassifStep('instructions');
      document.getElementById('import-classif-file-input').value = '';
    });
  }

  var fileInputClassif = document.getElementById('import-classif-file-input');
  if (fileInputClassif) {
    fileInputClassif.addEventListener('change', async function () {
      var file = this.files[0];
      if (!file) return;

      setImportClassifStep('processing');

      try {
        var buf = await file.arrayBuffer();
        var wb  = new ExcelJS.Workbook();
        await wb.xlsx.load(buf);

        var ws = wb.getWorksheet('2. IMPORTA_DATI') || wb.worksheets[0];
        if (!ws) {
          throw new Error('Impossibile trovare il foglio dati nel file Excel.');
        }

        /* Leggi l'intestazione dalla riga 1 */
        var headers = [];
        ws.getRow(1).eachCell({ includeEmpty: true }, function (cell) {
          headers.push(((cell.value !== null && cell.value !== undefined) ? String(cell.value) : '').trim());
        });

        /* Mappa chiave → indice colonna (0-based) */
        var keyMap = {};
        CLASSIF_COL_DEFS.forEach(function (c) {
          var idx = headers.indexOf(c.key);
          if (idx >= 0) keyMap[c.key] = idx;
        });

        if (keyMap['codice'] === undefined) {
          throw new Error(
            'Intestazione "codice" non trovata (riga 1). ' +
            'Assicurati di usare il tracciato originale senza modificare la riga 1.'
          );
        }

        /* Leggi le righe dati (salta riga 1 = intestazione) */
        var rows = [];
        ws.eachRow(function (row, rowNum) {
          if (rowNum === 1) return;
          var obj = {};
          var hasData = false;

          CLASSIF_COL_DEFS.forEach(function (c) {
            var cellVal = '';
            if (keyMap[c.key] !== undefined) {
              var cell = row.getCell(keyMap[c.key] + 1);
              var v = cell.value;
              if (v === null || v === undefined) {
                cellVal = '';
              } else if (typeof v === 'object' && v !== null) {
                cellVal = (v.text || v.result || '').toString().trim();
              } else {
                cellVal = v.toString().trim();
              }
            }
            obj[c.key] = cellVal;
            if (c.key === 'codice' && cellVal !== '') hasData = true;
          });

          if (hasData) rows.push(obj);
        });

        if (rows.length === 0) {
          throw new Error(
            'Il file non contiene righe di dati valide. ' +
            'Ricorda di eliminare le righe di esempio prima del caricamento.'
          );
        }

        /* Invia al PHP via FormData (CSRF incluso) */
        var tokenEl = document.querySelector('input[name="csrf_token"]');
        var fd = new FormData();
        fd.append('csrf_token', tokenEl ? tokenEl.value : '');
        fd.append('rows', JSON.stringify(rows));

        var resp   = await fetch(IMPORT_CLASSIFICAZIONI_URL, { method: 'POST', body: fd });
        var result = await resp.json();
        showImportClassifResult(result);

      } catch (err) {
        showImportClassifResult({ ok: false, error: err.message });
      }
    });
  }

  function setImportClassifStep(step) {
    var isResult = (step === 'result' || step === 'success' || step === 'error');
    document.getElementById('import-classif-step-instructions').classList.toggle('d-none', step !== 'instructions');
    document.getElementById('import-classif-step-processing').classList.toggle('d-none',   step !== 'processing');
    document.getElementById('import-classif-step-result').classList.toggle('d-none',       !isResult);

    var footer = document.getElementById('import-classif-modal-footer');
    footer.style.display = (step === 'processing') ? 'none' : '';

    var closeBtn = document.getElementById('import-classif-btn-close');
    if (closeBtn) closeBtn.textContent = (step === 'instructions') ? 'Annulla' : 'Chiudi';

    var elNormal = document.getElementById('import-classif-footer-normal');
    var elReload = document.getElementById('import-classif-btn-reload');
    var elRetry  = document.getElementById('import-classif-btn-retry');
    if (elNormal) elNormal.classList.toggle('d-none', step !== 'instructions');
    if (elReload) elReload.classList.toggle('d-none',  step !== 'success');
    if (elRetry)  elRetry.classList.toggle('d-none',   step !== 'error');
  }

  function showImportClassifResult(result) {
    var el = document.getElementById('import-classif-step-result');

    if (result.ok) {
      var html = '<div class="qk-alert qk-alert-success mb-3">'
        + '<i class="fa-solid fa-circle-check"></i>'
        + '<div><strong>' + result.inserted + '</strong> record importati correttamente'
        + (result.skipped > 0
            ? '; <strong>' + result.skipped + '</strong> saltati per dati non validi'
            : '')
        + '.</div></div>';

      if (result.errors && result.errors.length > 0) {
        html += '<div class="qk-alert qk-alert-warning mb-3">'
          + '<i class="fa-solid fa-triangle-exclamation"></i>'
          + '<div>' + result.errors.map(function (e) {
              return '<div class="small">' + e + '</div>';
            }).join('') + '</div></div>';
      }
      html += '<p class="text-muted small">Ricarica la pagina per visualizzare i nuovi record nella lista.</p>';
      el.innerHTML = html;
      setImportClassifStep('success');

    } else {
      el.innerHTML =
        '<div class="qk-alert qk-alert-danger mb-3">'
        + '<i class="fa-solid fa-circle-xmark"></i>'
        + '<span>' + (result.error || 'Errore sconosciuto.') + '</span></div>'
        + '<p class="text-muted small">Controlla il file e riprova.</p>';
      setImportClassifStep('error');
    }
  }

})();
</script>