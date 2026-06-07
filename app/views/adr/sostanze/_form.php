<?php
/**
 * @var string $mode              'create' | 'edit'
 * @var array  $v                 valori del form
 * @var array  $errors            errori di validazione
 * @var array  $codici_onu        lista adr_codici_onu
 * @var array  $schede            lista sostanze_adr_schede (solo in edit)
 * @var array  $prodotti_associati prodotti collegati (solo in edit)
 */
$isEdit    = $mode === 'edit';
$sostanzaId = $isEdit ? (int)($v['id'] ?? 0) : 0;
$lingue    = SostanzeAdrController::lingue();
$csrf      = csrf_field();
?>

<?php if (!empty($errors['_global'])): ?>
<div class="qk-alert qk-alert-error mb-3"><?= e($errors['_global']) ?></div>
<?php endif; ?>

<!-- Tabs nav -->
<ul class="nav nav-tabs mb-3" role="tablist">
  <li class="nav-item">
    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#stab-dati" role="tab">
      <i class="fa-solid fa-flask-vial me-1"></i> Dati sostanza
    </button>
  </li>
  <?php if ($isEdit): ?>
  <li class="nav-item">
    <button class="nav-link <?= !empty($errors) ? '' : '' ?>" data-bs-toggle="tab" data-bs-target="#stab-schede" role="tab">
      <i class="fa-solid fa-file-shield me-1"></i> Schede SDS
      <?php $nCorr = count(array_filter($schede, fn($s) => $s['e_corrente'])); ?>
      <?php if ($nCorr): ?><span class="badge bg-secondary ms-1"><?= $nCorr ?></span><?php endif; ?>
    </button>
  </li>
  <li class="nav-item">
    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#stab-prodotti" role="tab">
      <i class="fa-solid fa-boxes-stacked me-1"></i> Prodotti associati
      <?php if (!empty($prodotti_associati)): ?>
      <span class="badge bg-secondary ms-1"><?= count($prodotti_associati) ?></span>
      <?php endif; ?>
    </button>
  </li>
  <?php endif; ?>
</ul>

<div class="tab-content">

  <!-- ─── TAB 1: DATI SOSTANZA ─────────────────────────────────────────── -->
  <div class="tab-pane fade show active" id="stab-dati" role="tabpanel">
    <form method="post" action="<?= url($isEdit ? 'adr/sostanze/edit?id=' . $sostanzaId : 'adr/sostanze/create') ?>">
      <?= $csrf ?>

      <div class="qk-card mb-3">
        <div class="qk-card-header">
          <span class="qk-card-title">Identificazione</span>
        </div>
        <div class="qk-card-body">
          <div class="qk-form-row">
            <div class="qk-form-group" style="flex:2">
              <label class="qk-form-label required">Nome sostanza</label>
              <input type="text" name="nome" class="qk-form-control <?= isset($errors['nome']) ? 'is-invalid' : '' ?>"
                     value="<?= e($v['nome']) ?>" placeholder="Es. IPOCLORITO DI SODIO 14% — includi sempre nome e concentrazione">
              <?php if (isset($errors['nome'])): ?><div class="invalid-feedback"><?= e($errors['nome']) ?></div><?php endif; ?>
            </div>
            <div class="qk-form-group" style="flex:2">
              <label class="qk-form-label">Nome tecnico IUPAC</label>
              <input type="text" name="nome_tecnico" class="qk-form-control"
                     value="<?= e($v['nome_tecnico'] ?? '') ?>" placeholder="Es. Hydrogen chloride solution">
            </div>
          </div>

          <div class="qk-form-row">
            <div class="qk-form-group" style="flex:1">
              <label class="qk-form-label">
                Codice CAS
                <a href="https://www.cas.org/cas-data/cas-registry" target="_blank" tabindex="-1"
                   class="small qk-text-muted text-decoration-none ms-1" title="Cos'è il CAS?">
                  <i class="fa-solid fa-circle-question"></i>
                </a>
              </label>
              <input type="text" name="codice_cas"
                     class="qk-form-control <?= isset($errors['codice_cas']) ? 'is-invalid' : '' ?>"
                     value="<?= e($v['codice_cas'] ?? '') ?>"
                     placeholder="Es. 7647-01-0"
                     pattern="^\d{2,7}-\d{2}-\d$"
                     title="Formato CAS: XXXXXXX-XX-X">
              <?php if (isset($errors['codice_cas'])): ?>
              <div class="invalid-feedback"><?= e($errors['codice_cas']) ?></div>
              <?php else: ?>
              <div class="form-text small qk-text-muted">Identificatore univoco globale (es. 7647-01-0). Opzionale ma raccomandato.</div>
              <?php endif; ?>
            </div>
            <div class="qk-form-group" style="flex:3">
              <label class="qk-form-label">Codice ONU (N° UN)</label>
              <select name="codice_onu_id" id="codice-onu-sel" class="qk-form-control"
                      data-check-url="<?= url('adr/sostanze/api/check-onu') ?>"
                      data-exclude-id="<?= $sostanzaId ?>">
                <option value="">— Nessuno —</option>
                <?php foreach ($codici_onu as $c): ?>
                <option value="<?= (int)$c['id'] ?>"
                  <?= (string)($v['codice_onu_id'] ?? '') === (string)$c['id'] ? 'selected' : '' ?>>
                  UN<?= e($c['codice_onu']) ?> — <?= e(mb_strimwidth($c['nome_descrizione'], 0, 70, '…')) ?>
                  <?php if ($c['classe_pericolo']): ?>(cl. <?= e($c['classe_pericolo']) ?>)<?php endif; ?>
                </option>
                <?php endforeach; ?>
              </select>
              <!-- Banner dedup: iniettato via JS -->
              <div id="onu-dedup-banner" style="display:none" class="mt-2 p-2 rounded small"
                   style="background:var(--qk-warning-bg,#fffbeb);border:1px solid var(--qk-warning,#f59e0b)"></div>
            </div>
            <div class="qk-form-group" style="flex:1">
              <label class="qk-form-label">Gruppo imballaggio</label>
              <select name="gruppo_imballaggio" class="qk-form-control">
                <option value="">— —</option>
                <option value="I"   <?= ($v['gruppo_imballaggio'] ?? '') === 'I'   ? 'selected' : '' ?>>I — Alta pericolosità</option>
                <option value="II"  <?= ($v['gruppo_imballaggio'] ?? '') === 'II'  ? 'selected' : '' ?>>II — Media pericolosità</option>
                <option value="III" <?= ($v['gruppo_imballaggio'] ?? '') === 'III' ? 'selected' : '' ?>>III — Bassa pericolosità</option>
              </select>
            </div>
          </div>
        </div>
      </div>

      <div class="qk-card mb-3">
        <div class="qk-card-header">
          <span class="qk-card-title">Caratteristiche fisico-chimiche</span>
        </div>
        <div class="qk-card-body">
          <div class="qk-form-row">
            <div class="qk-form-group">
              <label class="qk-form-label">Conc. minima (%)</label>
              <input type="text" name="concentrazione_min_pct" class="qk-form-control"
                     value="<?= e($v['concentrazione_min_pct'] ?? '') ?>" placeholder="Es. 10">
            </div>
            <div class="qk-form-group">
              <label class="qk-form-label">Conc. massima (%)</label>
              <input type="text" name="concentrazione_max_pct" class="qk-form-control"
                     value="<?= e($v['concentrazione_max_pct'] ?? '') ?>" placeholder="Es. 37">
            </div>
            <div class="qk-form-group">
              <label class="qk-form-label">Punto infiammabilità (°C)</label>
              <input type="text" name="punto_infiammabilita_c" class="qk-form-control"
                     value="<?= e($v['punto_infiammabilita_c'] ?? '') ?>" placeholder="Es. 21">
            </div>
          </div>

          <div class="qk-form-row mt-2">
            <div class="qk-form-group">
              <div class="form-check">
                <input type="checkbox" class="form-check-input" name="e_soluzione" id="e_soluzione" value="1"
                       <?= ($v['e_soluzione'] ?? '0') === '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="e_soluzione">Soluzione acquosa</label>
              </div>
            </div>
            <div class="qk-form-group">
              <div class="form-check">
                <input type="checkbox" class="form-check-input" name="e_rifiuto" id="e_rifiuto" value="1"
                       <?= ($v['e_rifiuto'] ?? '0') === '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="e_rifiuto">Rifiuto pericoloso</label>
              </div>
            </div>
            <div class="qk-form-group">
              <div class="form-check">
                <input type="checkbox" class="form-check-input" name="e_stabilizzato" id="e_stabilizzato" value="1"
                       <?= ($v['e_stabilizzato'] ?? '0') === '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="e_stabilizzato">Stabilizzato</label>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="qk-card mb-3">
        <div class="qk-card-header"><span class="qk-card-title">Note interne</span></div>
        <div class="qk-card-body">
          <textarea name="note_interne" class="qk-form-control" rows="3"
                    placeholder="Note di uso interno (non visibili sui documenti)"><?= e($v['note_interne'] ?? '') ?></textarea>
        </div>
      </div>

      <div class="d-flex gap-2 mt-3">
        <button type="submit" class="btn-qk btn-qk-primary">
          <i class="fa-solid fa-floppy-disk me-1"></i>
          <?= $isEdit ? 'Salva modifiche' : 'Crea sostanza' ?>
        </button>
        <a href="<?= url('adr/sostanze') ?>" class="btn-qk btn-qk-secondary">Annulla</a>

        <?php if ($isEdit): ?>
        <div class="ms-auto">
          <form method="post" action="<?= url('adr/sostanze/delete') ?>"
                onsubmit="return confirm('Eliminare questa sostanza ADR? L\'operazione è irreversibile.')">
            <?= $csrf ?>
            <input type="hidden" name="id" value="<?= $sostanzaId ?>">
            <button type="submit" class="btn-qk btn-qk-danger btn-qk-sm">
              <i class="fa-solid fa-trash"></i> Elimina
            </button>
          </form>
        </div>
        <?php endif; ?>
      </div>
    </form>
  </div><!-- /stab-dati -->

  <?php if ($isEdit): ?>

  <!-- ─── TAB 2: SCHEDE SDS ──────────────────────────────────────────────── -->
  <div class="tab-pane fade" id="stab-schede" role="tabpanel">

    <!-- Upload nuova scheda -->
    <div class="qk-card mb-3">
      <div class="qk-card-header">
        <span class="qk-card-title"><i class="fa-solid fa-upload me-1"></i> Carica nuova SDS</span>
        <span class="qk-card-subtitle">Formati accettati: PDF, DOC, DOCX</span>
      </div>
      <div class="qk-card-body">
        <form method="post" action="<?= url('adr/sostanze/scheda/upload') ?>" enctype="multipart/form-data">
          <?= $csrf ?>
          <input type="hidden" name="sostanza_id" value="<?= $sostanzaId ?>">
          <div class="qk-form-row">
            <div class="qk-form-group">
              <label class="qk-form-label required">Lingua</label>
              <select name="lingua" class="qk-form-control" required>
                <option value="">— Seleziona —</option>
                <?php foreach ($lingue as $code => $label): ?>
                <option value="<?= $code ?>"><?= $code ?> — <?= $label ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="qk-form-group">
              <label class="qk-form-label">Versione</label>
              <input type="text" name="versione" class="qk-form-control" placeholder="Es. 3.0 o 2024-01">
            </div>
            <div class="qk-form-group">
              <label class="qk-form-label">Data emissione</label>
              <input type="date" name="data_emissione" class="qk-form-control">
            </div>
            <div class="qk-form-group" style="flex:2">
              <label class="qk-form-label required">File</label>
              <input type="file" name="file_sds" class="qk-form-control" accept=".pdf,.doc,.docx" required>
            </div>
          </div>
          <button type="submit" class="btn-qk btn-qk-primary btn-qk-sm mt-1">
            <i class="fa-solid fa-upload me-1"></i> Carica
          </button>
        </form>
      </div>
    </div>

    <!-- Lista schede esistenti -->
    <?php if (empty($schede)): ?>
    <div class="qk-empty">
      <i class="fa-solid fa-file-shield"></i>
      <p>Nessuna scheda SDS caricata per questa sostanza.</p>
    </div>
    <?php else:
      // Raggruppa per lingua
      $schedePerLingua = [];
      foreach ($schede as $sc) { $schedePerLingua[$sc['lingua']][] = $sc; }
    ?>
    <div class="row g-3">
      <?php foreach ($schedePerLingua as $lingua => $versioni): ?>
      <div class="col-md-6">
        <div class="qk-card h-100">
          <div class="qk-card-header">
            <span class="qk-card-title">
              <i class="fa-solid fa-language me-1"></i>
              <?= $lingua ?> — <?= e($lingue[$lingua] ?? $lingua) ?>
            </span>
          </div>
          <div class="qk-card-body p-0">
            <table class="qk-table" style="font-size:0.85rem">
              <thead>
                <tr><th>Versione</th><th>Data</th><th>File</th><th>Stato</th><th></th></tr>
              </thead>
              <tbody>
                <?php foreach ($versioni as $sc): ?>
                <tr>
                  <td><?= e($sc['versione'] ?: '—') ?></td>
                  <td><?= $sc['data_emissione'] ? fmt_date($sc['data_emissione']) : '—' ?></td>
                  <td>
                    <a href="<?= url('adr/sostanze/scheda/download?id=' . $sc['id']) ?>"
                       class="qk-text-accent text-decoration-none small" target="_blank">
                      <i class="fa-solid fa-file-arrow-down me-1"></i>
                      <?= e(mb_strimwidth($sc['file_nome_orig'] ?: basename($sc['file_path']), 0, 30, '…')) ?>
                    </a>
                  </td>
                  <td>
                    <?php if ($sc['e_corrente']): ?>
                    <span class="qk-badge confirmed">Corrente</span>
                    <?php else: ?>
                    <span class="qk-badge draft">Archiviata</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <form method="post" action="<?= url('adr/sostanze/scheda/delete') ?>"
                          onsubmit="return confirm('Eliminare questa scheda?')" class="d-inline">
                      <?= $csrf ?>
                      <input type="hidden" name="scheda_id" value="<?= (int)$sc['id'] ?>">
                      <input type="hidden" name="sostanza_id" value="<?= $sostanzaId ?>">
                      <button type="submit" class="btn-qk btn-qk-danger btn-qk-sm" title="Elimina">
                        <i class="fa-solid fa-trash"></i>
                      </button>
                    </form>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  </div><!-- /stab-schede -->

  <!-- ─── TAB 3: PRODOTTI ASSOCIATI ────────────────────────────────────── -->
  <div class="tab-pane fade" id="stab-prodotti" role="tabpanel">
    <?php if (empty($prodotti_associati)): ?>
    <div class="qk-empty">
      <i class="fa-solid fa-boxes-stacked"></i>
      <p>Nessun prodotto usa ancora questa sostanza ADR.</p>
      <p class="small qk-text-muted">Puoi associare questa sostanza a un prodotto dalla tab ADR nell'anagrafica prodotto.</p>
    </div>
    <?php else: ?>
    <div class="qk-card">
      <div class="qk-table-wrap">
        <table class="qk-table" style="font-size:0.875rem">
          <thead>
            <tr><th>SKU</th><th>Nome</th><th>Stato</th><th></th></tr>
          </thead>
          <tbody>
            <?php foreach ($prodotti_associati as $p): ?>
            <tr>
              <td class="fw-semibold"><?= e($p['sku_interno']) ?></td>
              <td><?= e($p['nome']) ?></td>
              <td>
                <?php if ($p['attivo']): ?>
                <span class="qk-badge confirmed">Attivo</span>
                <?php else: ?>
                <span class="qk-badge cancelled">Disattivato</span>
                <?php endif; ?>
              </td>
              <td class="text-end">
                <a href="<?= url('products/edit?id=' . $p['id']) ?>" class="btn-qk btn-qk-secondary btn-qk-sm">
                  <i class="fa-solid fa-arrow-up-right-from-square"></i>
                </a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
  </div><!-- /stab-prodotti -->

  <?php endif; // isEdit ?>

</div><!-- /tab-content -->

<script>
(function () {
  const sel    = document.getElementById('codice-onu-sel');
  const selGI  = document.querySelector('[name="gruppo_imballaggio"]');
  const banner = document.getElementById('onu-dedup-banner');
  if (!sel || !banner) return;

  const baseUrl   = sel.dataset.checkUrl;
  const excludeId = sel.dataset.excludeId || '0';

  function check() {
    const codiceOnuId = sel.value;
    if (!codiceOnuId) { banner.style.display = 'none'; return; }

    const gi  = selGI ? selGI.value : '';
    const qs  = '?codice_onu_id=' + encodeURIComponent(codiceOnuId)
              + '&exclude_id='    + encodeURIComponent(excludeId)
              + (gi ? '&gruppo_imballaggio=' + encodeURIComponent(gi) : '');

    fetch(baseUrl + qs)
      .then(r => r.json())
      .then(data => {
        if (!data.sostanze || data.sostanze.length === 0) {
          banner.style.display = 'none';
          return;
        }

        const lista = data.sostanze.map(s => {
          const giLabel = s.gruppo_imballaggio ? ' · GI ' + s.gruppo_imballaggio : '';
          const cas     = s.codice_cas ? ' · CAS ' + s.codice_cas : '';
          const href    = '<?= url('adr/sostanze/edit?id=') ?>' + s.id;
          return `<a href="${href}" target="_blank" class="fw-semibold text-decoration-none" style="color:inherit">${s.nome}${giLabel}${cas}</a>`;
        }).join('<br>');

        // Messaggio diverso se il match è su UN+GI o solo UN
        const isExact = data.match_type === 'onu+gi';
        const intro   = isExact
          ? '<strong>Attenzione:</strong> esiste già ' +
            (data.sostanze.length === 1 ? 'una sostanza' : data.sostanze.length + ' sostanze') +
            ' con lo stesso N° ONU <em>e</em> lo stesso gruppo di imballaggio:'
          : '<strong>Attenzione:</strong> questo codice ONU è già usato da ' +
            (data.sostanze.length === 1 ? 'un\'altra sostanza' : data.sostanze.length + ' altre sostanze') + ':';

        banner.style.cssText = 'display:block;background:#fffbeb;border:1px solid #f59e0b;border-radius:var(--qk-radius,4px);padding:.5rem .75rem';
        banner.innerHTML =
          '<i class="fa-solid fa-triangle-exclamation me-1" style="color:#f59e0b"></i>' +
          intro + '<br>' + lista + '<br>' +
          '<span class="qk-text-muted">' +
          (isExact
            ? 'Probabilmente è la stessa sostanza — verifica prima di crearne una nuova.'
            : 'Verifica che non si tratti della stessa sostanza prima di continuare.') +
          '</span>';
      })
      .catch(() => { banner.style.display = 'none'; });
  }

  sel.addEventListener('change', check);
  if (selGI) selGI.addEventListener('change', check);

  // Check on page load if editing with values already set
  if (sel.value) check();
})();
</script>
