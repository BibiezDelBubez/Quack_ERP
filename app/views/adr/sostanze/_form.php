<?php
/**
 * @var string $mode                 'create' | 'edit'
 * @var array  $v                    valori del form (campi sostanza)
 * @var array  $errors               errori di validazione
 * @var array  $codici_onu           lista adr_codici_onu
 * @var array  $clp_data             ['h_statements'=>[], 'p_statements'=>[], 'pittogrammi'=>[]]
 * @var array  $schede               lista sostanze_chimiche_schede (solo in edit)
 * @var array  $prodotti_associati   prodotti SKU collegati (solo in edit)
 * @var array  $h_selezionati        codici H già assegnati (solo in edit)
 * @var array  $p_selezionati        codici P già assegnati (solo in edit)
 * @var array  $pitt_selezionati     codici pittogramma già assegnati (solo in edit)
 */
$isEdit     = $mode === 'edit';
$sostanzaId = $isEdit ? (int)($v['id'] ?? 0) : 0;
$lingue     = SostanzeChimicheController::lingue();
$csrf       = csrf_field();
$h_selezionati    = $h_selezionati    ?? [];
$p_selezionati    = $p_selezionati    ?? [];
$pitt_selezionati = $pitt_selezionati ?? [];
?>

<?php if (!empty($errors['_global'])): ?>
<div class="qk-alert qk-alert-error mb-3"><?= e($errors['_global']) ?></div>
<?php endif; ?>

<ul class="nav nav-tabs mb-3" role="tablist">
  <li class="nav-item">
    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#stab-dati" role="tab">
      <i class="fa-solid fa-flask-vial me-1"></i> Dati sostanza
    </button>
  </li>
  <li class="nav-item">
    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#stab-clp" role="tab">
      <i class="fa-solid fa-biohazard me-1"></i> CLP / GHS
      <?php $nH = $isEdit ? count($h_selezionati) : 0; $nPitt = $isEdit ? count($pitt_selezionati) : 0;
        if ($nH || $nPitt): ?><span class="badge bg-secondary ms-1"><?= $nH ?>H</span><?php endif; ?>
    </button>
  </li>
  <?php if ($isEdit): ?>
  <li class="nav-item">
    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#stab-schede" role="tab">
      <i class="fa-solid fa-file-shield me-1"></i> Schede SDS
      <?php $nCorr = count(array_filter($schede, fn($s) => $s['e_corrente']));
        if ($nCorr): ?><span class="badge bg-secondary ms-1"><?= $nCorr ?></span><?php endif; ?>
    </button>
  </li>
  <li class="nav-item">
    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#stab-restrizioni" role="tab" id="btn-tab-restrizioni">
      <i class="fa-solid fa-ban me-1"></i> Restrizioni
      <span class="badge bg-secondary ms-1 d-none" id="badge-restrizioni">0</span>
    </button>
  </li>
  <li class="nav-item">
    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#stab-prodotti" role="tab">
      <i class="fa-solid fa-boxes-stacked me-1"></i> Prodotti Associati
      <span class="badge bg-secondary ms-1 <?= empty($prodotti_associati) ? 'd-none' : '' ?>" id="badge-prodotti-count"><?= count($prodotti_associati) ?></span>
    </button>
  </li>
  <?php endif; ?>
</ul>

<div class="tab-content">

  <!-- TAB 1: DATI SOSTANZA -->
  <div class="tab-pane fade show active" id="stab-dati" role="tabpanel">
    <form method="post" id="form-sostanza"
          action="<?= url($isEdit ? 'adr/sostanze/edit?id=' . $sostanzaId : 'adr/sostanze/create') ?>">
      <?= $csrf ?>

      <div class="qk-card mb-3">
        <div class="qk-card-header"><span class="qk-card-title">Identificazione</span></div>
        <div class="qk-card-body">
          <div class="qk-form-row">
            <div class="qk-form-group" style="flex:2">
              <label class="qk-form-label required">Nome sostanza</label>
              <input type="text" name="nome"
                     class="qk-form-control <?= isset($errors['nome']) ? 'is-invalid' : '' ?>"
                     value="<?= e($v['nome']) ?>" placeholder="Es. IPOCLORITO DI SODIO 14%">
              <?php if (isset($errors['nome'])): ?><div class="invalid-feedback"><?= e($errors['nome']) ?></div><?php endif; ?>
            </div>
            <div class="qk-form-group" style="flex:2">
              <label class="qk-form-label">Nome tecnico IUPAC</label>
              <input type="text" name="nome_tecnico" class="qk-form-control"
                     value="<?= e($v['nome_tecnico'] ?? '') ?>" placeholder="Es. Sodium hypochlorite">
            </div>
          </div>
          <div class="qk-form-row">
            <div class="qk-form-group">
              <label class="qk-form-label">Codice CAS</label>
              <input type="text" name="codice_cas"
                     class="qk-form-control <?= isset($errors['codice_cas']) ? 'is-invalid' : '' ?>"
                     value="<?= e($v['codice_cas'] ?? '') ?>" placeholder="Es. 7647-01-0" pattern="^\d{2,7}-\d{2}-\d$">
              <?php if (isset($errors['codice_cas'])): ?><div class="invalid-feedback"><?= e($errors['codice_cas']) ?></div><?php endif; ?>
            </div>
            <div class="qk-form-group">
              <label class="qk-form-label">EINECS / ELINCS</label>
              <input type="text" name="codice_einecs" class="qk-form-control"
                     value="<?= e($v['codice_einecs'] ?? '') ?>" placeholder="Es. 231-668-3">
            </div>
            <div class="qk-form-group">
              <label class="qk-form-label">REACH Reg. Number</label>
              <input type="text" name="codice_reach" class="qk-form-control"
                     value="<?= e($v['codice_reach'] ?? '') ?>" placeholder="Es. 01-2119458197-...">
            </div>
            <div class="qk-form-group">
              <label class="qk-form-label">UFI <a href="https://echa.europa.eu/ufi" target="_blank" tabindex="-1" class="small qk-text-muted ms-1" title="Unique Formula Identifier"><i class="fa-solid fa-circle-question"></i></a></label>
              <input type="text" name="codice_ufi" class="qk-form-control"
                     value="<?= e($v['codice_ufi'] ?? '') ?>" placeholder="Es. UFI: AB12-CD34-EF56-GH78">
            </div>
          </div>
        </div>
      </div>

      <div class="qk-card mb-3">
        <div class="qk-card-header"><span class="qk-card-title">Classificazione ADR</span></div>
        <div class="qk-card-body">
          <div class="qk-form-row">
            <div class="qk-form-group" style="flex:3">
              <label class="qk-form-label">Codice ONU (N° UN)</label>
              <input type="hidden" name="codice_onu_id" id="codice-onu-id" value="<?= e($v['codice_onu_id'] ?? '') ?>"
                     data-check-url="<?= url('adr/sostanze/api/check-onu') ?>"
                     data-exclude-id="<?= $sostanzaId ?>">
              <div class="qk-m2o-wrap" style="position:relative;">
                <?php
                $onuLabel = '';
                if (!empty($v['codice_onu'])) {
                    $onuLabel = 'UN' . $v['codice_onu'] . ' — ' . ($v['codice_onu_desc'] ?? '');
                    if (!empty($v['codice_onu_classe'])) {
                        $onuLabel .= ' (cl. ' . $v['codice_onu_classe'] . ')';
                    }
                }
                ?>
                <input type="text"
                       class="qk-form-control qk-m2o-input"
                       id="codice-onu-label"
                       placeholder="Cerca per codice ONU (es. 1203) o descrizione…"
                       value="<?= e($onuLabel) ?>"
                       autocomplete="off">
                <button type="button" class="qk-m2o-clear <?= empty($v['codice_onu_id']) ? 'd-none' : '' ?>" id="codice-onu-clear">&times;</button>
                <div class="qk-m2o-dropdown shadow-sm" id="codice-onu-dropdown" style="display:none; position:absolute; z-index:1050; background:var(--qk-card-bg); border:1px solid var(--qk-border); width:100%; max-height:200px; overflow-y:auto;"></div>
              </div>
              <div id="onu-dedup-banner" style="display:none" class="mt-2 p-2 rounded small"></div>
            </div>
            <div class="qk-form-group" style="flex:1">
              <label class="qk-form-label">Gruppo imballaggio</label>
              <select name="gruppo_imballaggio" class="qk-form-control">
                <option value="">— —</option>
                <option value="I"   <?= ($v['gruppo_imballaggio'] ?? '') === 'I'   ? 'selected' : '' ?>>I — Alta pericolosità</option>
                <option value="II"  <?= ($v['gruppo_imballaggio'] ?? '') === 'II'  ? 'selected' : '' ?>>II — Media</option>
                <option value="III" <?= ($v['gruppo_imballaggio'] ?? '') === 'III' ? 'selected' : '' ?>>III — Bassa</option>
              </select>
            </div>
            <div class="qk-form-group" style="flex:1">
              <label class="qk-form-label">N° Kemler <span class="small qk-text-muted">(pannello arancio)</span></label>
              <input type="text" name="numero_kemler" class="qk-form-control"
                     value="<?= e($v['numero_kemler'] ?? '') ?>" placeholder="Es. 80, 33, 268" maxlength="4">
            </div>
          </div>
        </div>
      </div>

      <div class="qk-card mb-3">
        <div class="qk-card-header"><span class="qk-card-title">Caratteristiche fisico-chimiche</span></div>
        <div class="qk-card-body">
          <div class="qk-form-row">
            <div class="qk-form-group">
              <label class="qk-form-label">Stato fisico</label>
              <select name="stato_fisico" class="qk-form-control">
                <option value="">— —</option>
                <?php foreach (['solido','liquido','gas','aerosol','pasta'] as $sf): ?>
                <option value="<?= $sf ?>" <?= ($v['stato_fisico'] ?? '') === $sf ? 'selected' : '' ?>><?= ucfirst($sf) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="qk-form-group">
              <label class="qk-form-label">Conc. minima (%) <i class="fa-solid fa-circle-info ms-1 text-muted" data-bs-toggle="tooltip" title="Concentrazione fisica o purezza reale MINIMA del prodotto commerciale (es. 37 per acido cloridrico)." style="cursor:help;"></i></label>
              <input type="text" name="concentrazione_min_pct" class="qk-form-control"
                     value="<?= e($v['concentrazione_min_pct'] ?? '') ?>" placeholder="Es. 10">
            </div>
            <div class="qk-form-group">
              <label class="qk-form-label">Conc. massima (%) <i class="fa-solid fa-circle-info ms-1 text-muted" data-bs-toggle="tooltip" title="Concentrazione fisica o purezza reale MASSIMA del prodotto commerciale." style="cursor:help;"></i></label>
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
                    placeholder="Note di uso interno"><?= e($v['note_interne'] ?? '') ?></textarea>
        </div>
      </div>

      <div class="d-flex gap-2 mt-3">
        <button type="submit" class="btn-qk btn-qk-primary">
          <i class="fa-solid fa-floppy-disk me-1"></i> <?= $isEdit ? 'Salva modifiche' : 'Crea sostanza' ?>
        </button>
        <a href="<?= url('adr/sostanze') ?>" class="btn-qk btn-qk-secondary">Annulla</a>
        <?php if ($isEdit): ?>
        <div class="ms-auto">
          <form method="post" action="<?= url('adr/sostanze/delete') ?>"
                onsubmit="return confirm('Eliminare questa sostanza? L\'operazione è irreversibile.')">
            <?= $csrf ?>
            <input type="hidden" name="id" value="<?= $sostanzaId ?>">
            <button type="submit" class="btn-qk btn-qk-danger btn-qk-sm"><i class="fa-solid fa-trash"></i> Elimina</button>
          </form>
        </div>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <!-- TAB 2: CLP / GHS -->
  <div class="tab-pane fade" id="stab-clp" role="tabpanel">
    <form method="post" id="form-clp"
          action="<?= url($isEdit ? 'adr/sostanze/edit?id=' . $sostanzaId : 'adr/sostanze/create') ?>">
      <?= $csrf ?>

      <div class="qk-card mb-3">
        <div class="qk-card-header"><span class="qk-card-title">Avvertenza CLP</span></div>
        <div class="qk-card-body">
          <div class="d-flex gap-3">
            <?php foreach (['Pericolo', 'Attenzione'] as $av): ?>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="clp_avvertenza"
                     id="clp_av_<?= $av ?>" value="<?= $av ?>"
                     <?= ($v['clp_avvertenza'] ?? '') === $av ? 'checked' : '' ?>>
              <label class="form-check-label fw-semibold <?= $av === 'Pericolo' ? 'text-danger' : 'text-warning' ?>"
                     for="clp_av_<?= $av ?>"><?= $av ?></label>
            </div>
            <?php endforeach; ?>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="clp_avvertenza" id="clp_av_nessuna" value=""
                     <?= ($v['clp_avvertenza'] ?? '') === '' ? 'checked' : '' ?>>
              <label class="form-check-label qk-text-muted" for="clp_av_nessuna">Nessuna</label>
            </div>
          </div>
        </div>
      </div>

      <div class="qk-card mb-3">
        <div class="qk-card-header"><span class="qk-card-title">Pittogrammi GHS</span></div>
        <div class="qk-card-body">
          <div class="d-flex flex-wrap gap-3">
            <?php foreach ($clp_data['pittogrammi'] as $pitt):
              $pittSel = in_array($pitt['codice'], $pitt_selezionati); ?>
            <label class="d-flex flex-column align-items-center gap-1 pitt-label"
                   id="pitt-lbl-<?= $pitt['codice'] ?>"
                   style="width:90px;padding:8px;border:2px solid <?= $pittSel ? 'var(--qk-accent)' : 'var(--qk-border)' ?>;border-radius:var(--qk-radius);cursor:pointer"
                   title="<?= e($pitt['descrizione']) ?>">
              <input type="checkbox" name="pittogrammi[]" value="<?= e($pitt['codice']) ?>"
                     class="pitt-check d-none" data-label="pitt-lbl-<?= $pitt['codice'] ?>"
                     <?= $pittSel ? 'checked' : '' ?>>
              <span class="fw-bold" style="font-size:0.75rem;color:var(--qk-accent)"><?= e($pitt['codice']) ?></span>
              <span class="text-center" style="font-size:0.7rem;line-height:1.2"><?= e($pitt['nome']) ?></span>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="qk-card mb-3">
        <div class="qk-card-header">
          <span class="qk-card-title">Frasi H — Indicazioni di pericolo</span>
          <span class="qk-card-subtitle">Clicca per selezionare; passa il mouse per leggere il testo</span>
        </div>
        <div class="qk-card-body">
          <?php
          $hPerTipo = [];
          foreach ($clp_data['h_statements'] as $h) { $hPerTipo[$h['tipo']][] = $h; }
          $tipoLabel = ['fisico'=>'Pericoli fisici','salute'=>'Pericoli per la salute','ambiente'=>'Pericoli per l\'ambiente','supplementare'=>'Frasi EUH supplementari'];
          foreach ($hPerTipo as $tipo => $frasi): ?>
          <div class="mb-3">
            <div class="fw-semibold small qk-text-muted mb-2 text-uppercase" style="letter-spacing:.05em"><?= e($tipoLabel[$tipo] ?? $tipo) ?></div>
            <div class="d-flex flex-wrap gap-1">
              <?php foreach ($frasi as $h):
                $sel = in_array($h['codice'], $h_selezionati); ?>
              <label class="h-chip <?= $sel ? 'h-chip-active' : '' ?>" id="hlbl-<?= e($h['codice']) ?>" title="<?= e($h['testo']) ?>">
                <input type="checkbox" name="h_statements[]" value="<?= e($h['codice']) ?>"
                       class="h-check d-none" data-label="hlbl-<?= e($h['codice']) ?>"
                       <?= $sel ? 'checked' : '' ?>>
                <?= e($h['codice']) ?>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="qk-card mb-3">
        <div class="qk-card-header">
          <span class="qk-card-title">Frasi P — Consigli di prudenza</span>
        </div>
        <div class="qk-card-body">
          <?php
          $pPerTipo = [];
          foreach ($clp_data['p_statements'] as $p) { $pPerTipo[$p['tipo']][] = $p; }
          $pTipoLabel = ['generale'=>'Generali','prevenzione'=>'Prevenzione','risposta'=>'Risposta','conservazione'=>'Conservazione','smaltimento'=>'Smaltimento'];
          foreach ($pPerTipo as $tipo => $frasi): ?>
          <div class="mb-3">
            <div class="fw-semibold small qk-text-muted mb-2 text-uppercase" style="letter-spacing:.05em"><?= e($pTipoLabel[$tipo] ?? $tipo) ?></div>
            <div class="d-flex flex-wrap gap-1">
              <?php foreach ($frasi as $p):
                $sel = in_array($p['codice'], $p_selezionati); ?>
              <label class="h-chip <?= $sel ? 'h-chip-active' : '' ?>" id="plbl-<?= e($p['codice']) ?>" title="<?= e($p['testo']) ?>">
                <input type="checkbox" name="p_statements[]" value="<?= e($p['codice']) ?>"
                       class="p-check d-none" data-label="plbl-<?= e($p['codice']) ?>"
                       <?= $sel ? 'checked' : '' ?>>
                <?= e($p['codice']) ?>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="d-flex gap-2 mt-3">
        <button type="submit" class="btn-qk btn-qk-primary">
          <i class="fa-solid fa-floppy-disk me-1"></i> Salva classificazione CLP
        </button>
      </div>
    </form>
  </div>

  <?php if ($isEdit): ?>

  <!-- TAB 3: SCHEDE SDS -->
  <div class="tab-pane fade" id="stab-schede" role="tabpanel">
    <div class="qk-card mb-3">
      <div class="qk-card-header">
        <span class="qk-card-title"><i class="fa-solid fa-upload me-1"></i> Carica nuova SDS</span>
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
              <input type="text" name="versione" class="qk-form-control" placeholder="Es. 3.0">
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
          <button type="submit" class="btn-qk btn-qk-primary btn-qk-sm mt-1"><i class="fa-solid fa-upload me-1"></i> Carica</button>
        </form>
      </div>
    </div>

    <?php if (empty($schede)): ?>
    <div class="qk-empty"><i class="fa-solid fa-file-shield"></i><p>Nessuna SDS caricata.</p></div>
    <?php else:
      $schedePerLingua = [];
      foreach ($schede as $sc) { $schedePerLingua[$sc['lingua']][] = $sc; }
    ?>
    <div class="row g-3">
      <?php foreach ($schedePerLingua as $lingua => $versioni): ?>
      <div class="col-md-6">
        <div class="qk-card h-100">
          <div class="qk-card-header">
            <span class="qk-card-title"><i class="fa-solid fa-language me-1"></i><?= $lingua ?> — <?= e($lingue[$lingua] ?? $lingua) ?></span>
          </div>
          <div class="qk-card-body p-0">
            <table class="qk-table" style="font-size:0.85rem">
              <thead><tr><th>Versione</th><th>Data</th><th>File</th><th>Stato</th><th></th></tr></thead>
              <tbody>
                <?php foreach ($versioni as $sc): ?>
                <tr>
                  <td><?= e($sc['versione'] ?: '—') ?></td>
                  <td><?= $sc['data_emissione'] ? fmt_date($sc['data_emissione']) : '—' ?></td>
                  <td><a href="<?= url('adr/sostanze/scheda/download?id=' . $sc['id']) ?>" class="qk-text-accent text-decoration-none small" target="_blank"><i class="fa-solid fa-file-arrow-down me-1"></i><?= e(mb_strimwidth($sc['file_nome_orig'] ?: basename($sc['file_path']), 0, 30, '…')) ?></a></td>
                  <td><?php if ($sc['e_corrente']): ?><span class="qk-badge confirmed">Corrente</span><?php else: ?><span class="qk-badge draft">Archiviata</span><?php endif; ?></td>
                  <td>
                    <form method="post" action="<?= url('adr/sostanze/scheda/delete') ?>" onsubmit="return confirm('Eliminare?')" class="d-inline">
                      <?= $csrf ?>
                      <input type="hidden" name="scheda_id" value="<?= (int)$sc['id'] ?>">
                      <input type="hidden" name="sostanza_id" value="<?= $sostanzaId ?>">
                      <button type="submit" class="btn-qk btn-qk-danger btn-qk-sm"><i class="fa-solid fa-trash"></i></button>
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
  </div>

  <!-- TAB 4: PRODOTTI ASSOCIATI -->
  <div class="tab-pane fade" id="stab-prodotti" role="tabpanel">
    <div class="row g-3">
      <!-- Sinistra: Catalogo Prodotti Disponibili -->
      <div class="col-md-6">
        <div class="qk-card d-flex flex-column" style="min-height: 480px;">
          <div class="qk-p-3 border-bottom">
            <div class="fw-semibold mb-2 text-primary d-flex align-items-center justify-content-between">
              <span><i class="fa-solid fa-store me-1"></i> Catalogo Prodotti (Disponibili)</span>
              <span class="badge bg-secondary" id="shuttle-available-badge-total" style="font-size: 0.75rem;">0</span>
            </div>
            <div class="row g-2">
              <div class="col-sm-7">
                <input type="text" id="shuttle-search" class="qk-form-control py-1" placeholder="Digita min. 2 caratteri per cercare..." autocomplete="off" style="font-size:0.85rem; height: 34px;">
              </div>
              <div class="col-sm-5">
                <select id="shuttle-category" class="qk-form-select py-1" style="font-size:0.85rem; height: 34px;">
                  <option value="">Tutte le categorie</option>
                  <?php foreach ($categories as $cat): ?>
                    <option value="<?= (int)$cat['id'] ?>"><?= e($cat['nome']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>
          
          <!-- Contenitore lista disponibili -->
          <div class="flex-grow-1 overflow-auto qk-p-2" style="max-height: 380px; min-height: 300px;" id="shuttle-available-container">
            <div class="text-muted text-center py-5 shuttle-empty-state">
              <i class="fa-solid fa-magnifying-glass fa-2xl mb-3 text-muted" style="opacity: 0.5;"></i>
              <div class="small fw-semibold">Digita almeno 2 caratteri per cercare i prodotti nel catalogo.</div>
            </div>
          </div>
          
          <div class="qk-p-2 border-top d-flex justify-content-between align-items-center bg-light-subtle">
            <span class="text-muted small" id="shuttle-available-count">0 selezionati</span>
            <button type="button" class="btn-qk btn-qk-primary btn-qk-sm" id="btn-shuttle-associate" disabled>
              Associa Selezionati <i class="fa-solid fa-chevron-right ms-1"></i>
            </button>
          </div>
        </div>
      </div>

      <!-- Destra: Prodotti Associati alla Sostanza -->
      <div class="col-md-6">
        <div class="qk-card d-flex flex-column" style="min-height: 480px;">
          <div class="qk-p-3 border-bottom">
            <div class="fw-semibold mb-2 text-success d-flex align-items-center justify-content-between">
              <span><i class="fa-solid fa-link me-1"></i> Prodotti Associati</span>
              <span class="badge bg-success" id="shuttle-associated-badge-total" style="font-size: 0.75rem;">0</span>
            </div>
            <div>
              <input type="text" id="shuttle-associated-search" class="qk-form-control py-1" placeholder="Filtra tra i collegati per nome o SKU..." autocomplete="off" style="font-size:0.85rem; height: 34px;">
            </div>
          </div>
          
          <!-- Contenitore lista associati -->
          <div class="flex-grow-1 overflow-auto qk-p-2" style="max-height: 380px; min-height: 300px;" id="shuttle-associated-container">
            <!-- Rendered dynamically via JS -->
          </div>
          
          <div class="qk-p-2 border-top d-flex justify-content-between align-items-center bg-light-subtle">
            <span class="text-muted small" id="shuttle-associated-count">0 selezionati</span>
            <button type="button" class="btn-qk btn-qk-secondary text-danger btn-qk-sm" id="btn-shuttle-disassociate" disabled>
              <i class="fa-solid fa-link-slash me-1"></i> Scollega Selezionati
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- TAB 5: RESTRIZIONI COMPLIANCE -->
  <div class="tab-pane fade" id="stab-restrizioni" role="tabpanel">
    <div id="restrizioni-container">
      <div class="qk-empty py-4">
        <i class="fa-solid fa-spinner fa-spin fa-lg mb-2"></i><br>Caricamento…
      </div>
    </div>
  </div>

  <?php endif; ?>
</div>

<style>
.h-chip { display:inline-block; padding:3px 8px; border:1px solid var(--qk-border); border-radius:4px; font-size:0.75rem; font-weight:600; cursor:pointer; transition:all .15s; user-select:none; }
.h-chip:hover { border-color:var(--qk-accent); color:var(--qk-accent); }
.h-chip-active { background:var(--qk-accent); border-color:var(--qk-accent); color:#fff; }

/* ── Restrizioni compliance ───────────────────────────────────── */
.qkr-card {
  background: var(--qk-card-bg);
  border: 1px solid var(--qk-card-border);
  border-radius: var(--qk-radius, 6px);
  padding: 1rem 1.25rem;
  margin-bottom: .75rem;
  display: flex;
  align-items: flex-start;
  gap: 1rem;
  transition: border-color .15s;
}
.qkr-card:hover { border-color: var(--qk-accent); }
.qkr-icon {
  width: 36px; height: 36px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
  font-size: .9rem;
}
.qkr-icon--direct    { background: rgba(239,68,68,.12); color: #ef4444; }
.qkr-icon--inherited { background: var(--qk-accent-light, rgba(0,120,255,.1)); color: var(--qk-accent); }
.qkr-body { flex: 1; min-width: 0; }
.qkr-name { font-weight: 600; font-size: .9rem; color: var(--qk-text-primary); }
.qkr-ref  { font-size: .78rem; color: var(--qk-text-muted); margin-top: 1px; }
.qkr-meta { display: flex; flex-wrap: wrap; gap: .4rem; margin-top: .4rem; }
.qkr-pill {
  display: inline-flex; align-items: center; gap: .25rem;
  font-size: .72rem; font-weight: 500;
  padding: 2px 8px;
  border-radius: 99px;
  background: var(--qk-bg-subtle);
  border: 1px solid var(--qk-border);
  color: var(--qk-text-secondary);
}
.qkr-conc { font-size: .78rem; color: var(--qk-text-secondary); margin-top: .25rem; }
.qkr-note { font-size: .78rem; color: var(--qk-text-muted); font-style: italic; margin-top: .2rem; }
.qkr-source-badge {
  font-size: .7rem; font-weight: 600; padding: 2px 7px;
  border-radius: 4px; letter-spacing: .03em;
}
.qkr-source-badge--direct    { background: color-mix(in srgb,#ef4444 12%,transparent); color:#b91c1c; }
.qkr-source-badge--inherited { background: color-mix(in srgb,var(--qk-accent) 10%,transparent); color:var(--qk-accent); }

/* Pannello aggiungi inline */
.qkr-add-panel {
  background: var(--qk-bg-subtle);
  border: 1px dashed var(--qk-border);
  border-radius: var(--qk-radius, 6px);
  padding: 1rem 1.25rem;
  margin-bottom: .75rem;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.h-check, .p-check').forEach(function(cb) {
    cb.addEventListener('change', function() {
      const lbl = document.getElementById(this.dataset.label);
      if (lbl) lbl.classList.toggle('h-chip-active', this.checked);
    });
  });
  document.querySelectorAll('.pitt-check').forEach(function(cb) {
    cb.addEventListener('change', function() {
      const lbl = document.getElementById(this.dataset.label);
      if (lbl) lbl.style.borderColor = this.checked ? 'var(--qk-accent)' : 'var(--qk-border)';
    });
  });
  const idInput = document.getElementById('codice-onu-id');
  const labelInput = document.getElementById('codice-onu-label');
  const clearBtn = document.getElementById('codice-onu-clear');
  const dropdownEl = document.getElementById('codice-onu-dropdown');
  const selGI  = document.querySelector('[name="gruppo_imballaggio"]');
  const banner = document.getElementById('onu-dedup-banner');

  if (labelInput && idInput) {
    window.qkAutocomplete({
      input: labelInput,
      dropdown: dropdownEl,
      fetchFn: function(term, callback) {
        fetch('<?= url("adr/api/lookup") ?>?type=codici_onu&q=' + encodeURIComponent(term))
          .then(res => res.json())
          .then(data => {
            const results = data.map(item => {
              let label = 'UN' + item.label;
              if (item.sub) {
                label += ' — ' + item.sub;
              }
              return { id: item.id, label: label };
            });
            callback(results);
          })
          .catch(err => {
            console.error('Error lookup ONU:', err);
            callback([]);
          });
      },
      onSelect: function(selItem) {
        if (selItem) {
          idInput.value = selItem.id;
          labelInput.value = selItem.label;
          if (clearBtn) clearBtn.classList.remove('d-none');
          checkOnu();
        } else {
          idInput.value = '';
          labelInput.value = '';
          if (clearBtn) clearBtn.classList.add('d-none');
          checkOnu();
        }
      }
    });

    if (clearBtn) {
      clearBtn.addEventListener('click', function() {
        idInput.value = '';
        labelInput.value = '';
        clearBtn.classList.add('d-none');
        dropdownEl.style.display = 'none';
        checkOnu();
      });
    }
  }

  const baseUrl   = idInput ? idInput.dataset.checkUrl : '';
  const excludeId = idInput ? idInput.dataset.excludeId || '0' : '0';

  function checkOnu() {
    const v = idInput ? idInput.value : '';
    if (!v || !banner) { if (banner) banner.style.display='none'; return; }
    const gi = selGI ? selGI.value : '';
    const qs = '?codice_onu_id=' + encodeURIComponent(v)
             + '&exclude_id='    + encodeURIComponent(excludeId)
             + (gi ? '&gruppo_imballaggio=' + encodeURIComponent(gi) : '');
    fetch(baseUrl + qs).then(r=>r.json()).then(data => {
      if (!data.sostanze || !data.sostanze.length) { banner.style.display='none'; return; }
      const lista = data.sostanze.map(s => {
        const href = '<?= url('adr/sostanze/edit?id=') ?>' + s.id;
        return '<a href="'+href+'" target="_blank" class="fw-semibold text-decoration-none" style="color:inherit">'+s.nome+(s.gruppo_imballaggio?' · GI '+s.gruppo_imballaggio:'')+(s.codice_cas?' · '+s.codice_cas:'')+'</a>';
      }).join('<br>');
      banner.style.cssText='display:block;background:#fffbeb;border:1px solid #f59e0b;border-radius:var(--qk-radius,4px);padding:.5rem .75rem';
      banner.innerHTML='<i class="fa-solid fa-triangle-exclamation me-1" style="color:#f59e0b"></i><strong>Attenzione:</strong> questo ONU è già usato da:<br>'+lista+'<br><span class="qk-text-muted">Verifica che non sia la stessa sostanza.</span>';
    }).catch(()=>{if (banner) banner.style.display='none';});
  }

  if (idInput) idInput.addEventListener('change', checkOnu);
  if (selGI) selGI.addEventListener('change', checkOnu);
  if (idInput && idInput.value) checkOnu();
});
</script>

<?php if ($isEdit): ?>
<script>
/* ══════════════════════════════════════════════════════════════════════════
 * Tab Restrizioni Compliance — AJAX
 * ══════════════════════════════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', function () {
  'use strict';

  const SOSTANZA_ID  = <?= $sostanzaId ?>;
  const API_LIST     = <?= json_encode(url('adr/sostanze/api/restrizioni')) ?>;
  const API_ADD      = <?= json_encode(url('adr/sostanze/api/restrizioni/add')) ?>;
  const API_REMOVE   = <?= json_encode(url('adr/sostanze/api/restrizioni/remove')) ?>;
  const CSRF         = <?= json_encode(csrf_token()) ?>;

  const container    = document.getElementById('restrizioni-container');
  const badge        = document.getElementById('badge-restrizioni');
  const tabBtn       = document.getElementById('btn-tab-restrizioni');

  if (!container) return;

  // Carica al click sul tab (lazy load)
  if (tabBtn) {
    tabBtn.addEventListener('shown.bs.tab', load);
    tabBtn.addEventListener('click', function() {
      // Se il container ha ancora lo spinner (non caricato o fallito), forza caricamento
      if (container.querySelector('.fa-spinner')) {
        load();
      }
    });
  }

  // ── helpers ───────────────────────────────────────────────────────────

  function esc(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function pill(icon, text) {
    return `<span class="qkr-pill"><i class="fa-solid ${icon} fa-xs"></i> ${esc(text)}</span>`;
  }

  // ── render principale ─────────────────────────────────────────────────

  async function load() {
    container.innerHTML = `<div class="qk-empty py-4"><i class="fa-solid fa-spinner fa-spin fa-lg mb-2"></i><br>Caricamento…</div>`;
    try {
      const res  = await fetch(`${API_LIST}?sostanza_id=${SOSTANZA_ID}`);
      const data = await res.json();
      if (!data.ok) throw new Error(data.error ?? 'Errore sconosciuto');
      render(data);
    } catch(e) {
      container.innerHTML = `<div class="alert alert-danger m-3">${esc(e.message)}</div>`;
    }
  }

  function render(data) {
    const dirette   = data.dirette   ?? [];
    const ereditate = data.ereditate ?? [];
    const tutti     = data.tutti     ?? [];

    // Badge tab
    const nDirette = dirette.length;
    if (badge) {
      badge.textContent = nDirette;
      badge.classList.toggle('d-none', nDirette === 0);
    }

    let html = '';

    // ── Toolbar: titolo + pulsante aggiungi ──────────────────────────────
    html += `
      <div class="qk-table-toolbar mb-3">
        <div class="qk-table-toolbar-left">
          <span class="fw-semibold">Restrizioni di vendita</span>
          <span class="qk-text-muted small ms-2">
            Documenti o autorizzazioni richiesti per poter vendere questa sostanza.
          </span>
        </div>
        <div class="qk-table-toolbar-right">
          <button class="btn-qk btn-qk-primary btn-qk-sm" id="btn-aggiungi-restrizione">
            <i class="fa-solid fa-plus me-1"></i> Aggiungi restrizione
          </button>
        </div>
      </div>`;

    // Pannello aggiungi (nascosto di default)
    html += buildAddPanel(tutti);

    // ── Lista restrizioni dirette ─────────────────────────────────────────
    if (dirette.length === 0) {
      // Testo diverso se ci sono restrizioni ereditate dal codice ONU o no.
      // (internamente: se non ci sono restrizioni dirette, il sistema usa quelle collegate
      //  al codice ONU come fallback — ma all'utente basta sapere cosa fare)
      const emptyMsg = ereditate.length > 0
        ? `Nessuna restrizione configurata manualmente.<br>
           <span class="qk-text-muted">Il sistema rileva già una restrizione in automatico (vedi sotto) — se è corretta non devi fare nulla.</span>`
        : `Nessuna restrizione di vendita.<br>
           <span class="qk-text-muted">Se questa sostanza è soggetta a normative particolari (es. precursori di esplosivi, stupefacenti, biocidi), aggiungila con il pulsante qui sopra.</span>`;

      html += `<div class="qk-empty"><i class="fa-solid fa-shield-halved"></i><p>${emptyMsg}</p></div>`;
    } else {
      dirette.forEach(r => { html += buildCard(r, 'direct'); });
    }

    // ── Sezione rilevata automaticamente dal codice ONU (read-only) ───────
    // Internamente è il "fallback via ONU" — mostrato come info, non come azione
    if (ereditate.length > 0) {
      const infoMsg = dirette.length > 0
        ? 'Rilevata dal codice ONU, ma <strong>non attiva</strong> perché è già presente una restrizione configurata manualmente sopra.'
        : 'Rilevata in automatico dal codice ONU. Non devi fare nulla.';

      html += `
        <div class="qk-card mt-3" style="border-style:dashed">
          <div class="qk-card-header">
            <span class="qk-card-title" style="font-size:.85rem">
              <i class="fa-solid fa-wand-magic-sparkles me-1" style="color:var(--qk-accent)"></i>
              Rilevata automaticamente
            </span>
            <span class="qk-card-subtitle">${infoMsg}</span>
          </div>
          <div class="qk-card-body pt-2 pb-2">`;
      ereditate.forEach(r => { html += buildCard(r, 'inherited'); });
      html += `</div></div>`;
    }

    container.innerHTML = html;
    attachEvents(tutti);
  }

  // ── Card singola restrizione ─────────────────────────────────────────

  function buildCard(r, source) {
    const isDirect = source === 'direct';
    const iconClass = isDirect ? 'qkr-icon--direct' : 'qkr-icon--inherited';
    const sourceBadge = isDirect
      ? `<span class="qkr-source-badge qkr-source-badge--direct">Diretta</span>`
      : `<span class="qkr-source-badge qkr-source-badge--inherited">Da ONU ${esc(r.codice_onu ?? '')}</span>`;

    const pills = [];
    if (parseInt(r.requires_vat)) pills.push(pill('fa-address-card', 'P.IVA richiesta'));
    if (r.validity_days)          pills.push(pill('fa-calendar-days', `Validità ${r.validity_days} gg`));

    const concMin = r.concentrazione_min ?? r.min_concentration;
    const concMax = r.concentrazione_max ?? r.max_concentration;
    let concHtml = '';
    if (concMin !== null && concMin !== undefined) {
      concHtml = `<div class="qkr-conc"><i class="fa-solid fa-flask fa-xs me-1"></i>Attiva da conc. ≥ ${concMin}%${concMax ? ` e ≤ ${concMax}%` : ''}</div>`;
    }

    const noteHtml = r.note ? `<div class="qkr-note">${esc(r.note)}</div>` : '';

    const removeBtn = isDirect
      ? `<button class="btn-qk btn-qk-secondary btn-qk-sm text-danger btn-remove-restrizione"
                 data-type-id="${r.restriction_type_id}"
                 data-name="${esc(r.name)}"
                 title="Rimuovi restrizione">
           <i class="fa-solid fa-xmark"></i>
         </button>`
      : '';

    return `
      <div class="qkr-card" data-type-id="${r.restriction_type_id ?? ''}">
        <div class="qkr-icon ${iconClass}">
          <i class="fa-solid fa-shield-halved"></i>
        </div>
        <div class="qkr-body">
          <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="qkr-name">${esc(r.name)}</span>
            ${sourceBadge}
          </div>
          <div class="qkr-ref">${esc(r.regulatory_ref ?? '')}</div>
          ${pills.length ? `<div class="qkr-meta">${pills.join('')}</div>` : ''}
          ${concHtml}
          ${noteHtml}
        </div>
        <div class="flex-shrink-0">${removeBtn}</div>
      </div>`;
  }

  // ── Pannello aggiungi inline ─────────────────────────────────────────

  function buildAddPanel(tutti) {
    const options = tutti.map(t =>
      `<option value="${t.id}">${esc(t.name)} — ${esc(t.regulatory_ref ?? '')}</option>`
    ).join('');

    return `
      <div class="qkr-add-panel d-none" id="qkr-add-panel">
        <div class="fw-semibold mb-2 small"><i class="fa-solid fa-plus-circle me-1" style="color:var(--qk-accent)"></i>Aggiungi restrizione compliance</div>
        <div class="qk-form-row">
          <div class="qk-form-group" style="flex:3">
            <label class="qk-form-label required">Tipo di restrizione</label>
            <select id="qkr-select-tipo" class="qk-form-control">
              <option value="">— Seleziona —</option>
              ${options}
            </select>
          </div>
          <div class="qk-form-group" style="flex:1">
            <label class="qk-form-label">Conc. min (%) <i class="fa-solid fa-circle-info ms-1 text-muted" title="Soglia legale minima di concentrazione oltre la quale la restrizione si attiva." style="cursor:help;"></i></label>
            <input type="text" id="qkr-conc-min" class="qk-form-control" placeholder="Es. 10">
          </div>
          <div class="qk-form-group" style="flex:1">
            <label class="qk-form-label">Conc. max (%) <i class="fa-solid fa-circle-info ms-1 text-muted" title="Soglia legale massima di concentrazione entro cui si applica la restrizione." style="cursor:help;"></i></label>
            <input type="text" id="qkr-conc-max" class="qk-form-control" placeholder="Es. 50">
          </div>
        </div>
        <div class="qk-form-row">
          <div class="qk-form-group" style="flex:1">
            <label class="qk-form-label">Note</label>
            <input type="text" id="qkr-note" class="qk-form-control" placeholder="Riferimento interno, motivazione…">
          </div>
        </div>
        <div class="d-flex gap-2 mt-1">
          <button class="btn-qk btn-qk-primary btn-qk-sm" id="qkr-btn-salva">
            <i class="fa-solid fa-floppy-disk me-1"></i> Salva
          </button>
          <button class="btn-qk btn-qk-secondary btn-qk-sm" id="qkr-btn-annulla">Annulla</button>
          <span id="qkr-save-error" class="small text-danger align-self-center d-none"></span>
        </div>
      </div>`;
  }

  // ── eventi ────────────────────────────────────────────────────────────

  function attachEvents(tutti) {
    // Apri pannello aggiungi
    const btnAdd = document.getElementById('btn-aggiungi-restrizione');
    const panel  = document.getElementById('qkr-add-panel');
    if (btnAdd && panel) {
      btnAdd.addEventListener('click', () => {
        panel.classList.remove('d-none');
        btnAdd.classList.add('d-none');
        document.getElementById('qkr-select-tipo')?.focus();
      });
    }

    // Annulla
    document.getElementById('qkr-btn-annulla')?.addEventListener('click', () => {
      panel?.classList.add('d-none');
      btnAdd?.classList.remove('d-none');
    });

    // Salva
    document.getElementById('qkr-btn-salva')?.addEventListener('click', async () => {
      const typeId  = document.getElementById('qkr-select-tipo')?.value;
      const concMin = document.getElementById('qkr-conc-min')?.value.trim();
      const concMax = document.getElementById('qkr-conc-max')?.value.trim();
      const note    = document.getElementById('qkr-note')?.value.trim();
      const errEl   = document.getElementById('qkr-save-error');

      if (!typeId) {
        if (errEl) { errEl.textContent = 'Seleziona un tipo di restrizione.'; errEl.classList.remove('d-none'); }
        return;
      }
      if (errEl) errEl.classList.add('d-none');

      const body = new FormData();
      body.append('sostanza_id',        SOSTANZA_ID);
      body.append('restriction_type_id', typeId);
      body.append('concentrazione_min',  concMin);
      body.append('concentrazione_max',  concMax);
      body.append('note',                note);
      body.append('csrf_token',          CSRF);

      try {
        const res  = await fetch(API_ADD, { method: 'POST', body });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error ?? 'Errore');
        await load(); // ricarica tutto
      } catch(e) {
        if (errEl) { errEl.textContent = e.message; errEl.classList.remove('d-none'); }
      }
    });

    // Rimuovi
    document.querySelectorAll('.btn-remove-restrizione').forEach(btn => {
      btn.addEventListener('click', async () => {
        const typeId = btn.dataset.typeId;
        const name   = btn.dataset.name;
        if (!confirm(`Rimuovere la restrizione "${name}" da questa sostanza?`)) return;

        const body = new FormData();
        body.append('sostanza_id',         SOSTANZA_ID);
        body.append('restriction_type_id', typeId);
        body.append('csrf_token',          CSRF);

        try {
          const res  = await fetch(API_REMOVE, { method: 'POST', body });
          const data = await res.json();
          if (!data.ok) throw new Error(data.error ?? 'Errore');
          await load();
        } catch(e) {
          alert('Errore: ' + e.message);
        }
      });
    });
  }

  // ==========================================
  // DUAL-LIST SHUTTLE PICKER (PRODOTTI ASSOCIATI)
  // ==========================================
  
  // Iniettiamo gli stili CSS personalizzati per lo shuttle picker in modo da rimanere conformi e non inquinare i file globali
  (function injectShuttleStyles() {
    const styleEl = document.createElement('style');
    styleEl.textContent = `
      .shuttle-item {
        padding: 8px 12px;
        margin-bottom: 6px;
        border: 1px solid var(--qk-border);
        border-radius: 6px;
        cursor: pointer;
        transition: background-color 0.15s, border-color 0.15s, transform 0.15s;
        background-color: var(--qk-card-bg);
        user-select: none;
        display: flex;
        align-items: center;
        gap: 10px;
      }
      .shuttle-item:hover {
        background-color: var(--qk-bg-subtle);
        border-color: var(--qk-accent);
      }
      .shuttle-item.selected {
        background-color: var(--qk-accent-light, rgba(0, 102, 204, 0.04));
        border-color: var(--qk-accent);
      }
      .shuttle-item input[type="checkbox"] {
        cursor: pointer;
        width: 16px;
        height: 16px;
        accent-color: var(--qk-accent);
      }
    `;
    document.head.appendChild(styleEl);
  })();

  // Stato dell'interfaccia
  let associatedList = <?= json_encode(array_map(function($p) {
      return [
          'id' => (int)$p['id'],
          'nome' => $p['nome'],
          'sku' => $p['sku_interno'],
          'attivo' => (bool)$p['attivo'],
          'categoria_id' => $p['categoria_id'] ? (int)$p['categoria_id'] : null
      ];
  }, $prodotti_associati)) ?>;

  let availableList = []; // Prodotti trovati dalla ricerca

  // Elementi DOM dello Shuttle Picker
  const elSearchAvailable = document.getElementById('shuttle-search');
  const elCategoryFilter = document.getElementById('shuttle-category');
  const elContainerAvailable = document.getElementById('shuttle-available-container');
  const elCountAvailableSelected = document.getElementById('shuttle-available-count');
  const elBtnAssociate = document.getElementById('btn-shuttle-associate');
  const elBadgeAvailableTotal = document.getElementById('shuttle-available-badge-total');

  const elSearchAssociated = document.getElementById('shuttle-associated-search');
  const elContainerAssociated = document.getElementById('shuttle-associated-container');
  const elCountAssociatedSelected = document.getElementById('shuttle-associated-count');
  const elBtnDisassociate = document.getElementById('btn-shuttle-disassociate');
  const elBadgeAssociatedTotal = document.getElementById('shuttle-associated-badge-total');
  const elBadgeTabCount = document.getElementById('badge-prodotti-count');

  // Funzione helper per l'escaping HTML
  function escapeHTML(str) {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  // Rende la lista dei prodotti disponibili
  function renderAvailable() {
    if (availableList.length === 0) {
      elBadgeAvailableTotal.textContent = '0';
      const term = elSearchAvailable.value.trim();
      if (term.length < 2) {
        elContainerAvailable.innerHTML = `
          <div class="text-muted text-center py-5 shuttle-empty-state">
            <i class="fa-solid fa-magnifying-glass fa-2xl mb-3 text-muted" style="opacity: 0.5;"></i>
            <div class="small fw-semibold">Digita almeno 2 caratteri per cercare i prodotti nel catalogo.</div>
          </div>
        `;
      } else {
        elContainerAvailable.innerHTML = `
          <div class="text-muted text-center py-5 shuttle-empty-state">
            <i class="fa-solid fa-boxes-stacked fa-2xl mb-3 text-muted" style="opacity: 0.5;"></i>
            <div class="small fw-semibold">Nessun prodotto disponibile trovato con questi filtri.</div>
          </div>
        `;
      }
      updateAvailableCount();
      return;
    }

    // Escludiamo quelli già associati
    const associatedIds = new Set(associatedList.map(p => p.id));
    const filtered = availableList.filter(p => !associatedIds.has(p.id));

    elBadgeAvailableTotal.textContent = filtered.length;

    if (filtered.length === 0) {
      elContainerAvailable.innerHTML = `
        <div class="text-muted text-center py-5 shuttle-empty-state">
          <i class="fa-solid fa-check-double fa-2xl mb-3 text-success" style="opacity: 0.7;"></i>
          <div class="small fw-semibold text-success">Tutti i prodotti cercati sono già associati!</div>
        </div>
      `;
      updateAvailableCount();
      return;
    }

    let html = '';
    filtered.forEach(p => {
      const isSelected = p._selected ? 'selected' : '';
      const isChecked = p._selected ? 'checked' : '';
      html += `
        <div class="shuttle-item ${isSelected}" data-id="${p.id}">
          <input type="checkbox" class="shuttle-cb" data-id="${p.id}" ${isChecked}>
          <div class="flex-grow-1">
            <div class="fw-semibold text-primary" style="font-size: 0.85rem;">${escapeHTML(p.nome)}</div>
            <div class="text-muted font-monospace small d-flex align-items-center gap-2" style="font-size: 0.72rem;">
              <span>SKU: ${escapeHTML(p.sku)}</span>
              ${p.attivo ? '<span class="text-success">● Attivo</span>' : '<span class="text-danger">● Inattivo</span>'}
            </div>
          </div>
        </div>
      `;
    });

    elContainerAvailable.innerHTML = html;

    // Aggiungi event listeners per la selezione al click sull'intero elemento
    elContainerAvailable.querySelectorAll('.shuttle-item').forEach(item => {
      item.addEventListener('click', function(e) {
        // Se il click non è direttamente sul checkbox, cambialo manualmente
        if (e.target.type !== 'checkbox') {
          const cb = this.querySelector('.shuttle-cb');
          cb.checked = !cb.checked;
          cb.dispatchEvent(new Event('change'));
        }
      });
    });

    elContainerAvailable.querySelectorAll('.shuttle-cb').forEach(cb => {
      cb.addEventListener('change', function() {
        const id = parseInt(this.dataset.id);
        const item = availableList.find(p => p.id === id);
        if (item) {
          item._selected = this.checked;
          const parent = this.closest('.shuttle-item');
          if (parent) parent.classList.toggle('selected', this.checked);
        }
        updateAvailableCount();
      });
    });

    updateAvailableCount();
  }

  // Rende la lista dei prodotti associati
  function renderAssociated() {
    let list = [...associatedList];

    // Filtro locale su associati per nome o SKU
    const localQ = elSearchAssociated.value.trim().toLowerCase();
    if (localQ.length > 0) {
      list = list.filter(p => 
        p.nome.toLowerCase().includes(localQ) || 
        p.sku.toLowerCase().includes(localQ)
      );
    }

    elBadgeAssociatedTotal.textContent = associatedList.length;
    if (elBadgeTabCount) {
      elBadgeTabCount.textContent = associatedList.length;
      elBadgeTabCount.classList.toggle('d-none', associatedList.length === 0);
    }

    if (list.length === 0) {
      elContainerAssociated.innerHTML = `
        <div class="text-muted text-center py-5 shuttle-empty-state">
          <i class="fa-solid fa-link-slash fa-2xl mb-3 text-muted" style="opacity: 0.5;"></i>
          <div class="small fw-semibold">Nessun prodotto associato trovato.</div>
        </div>
      `;
      updateAssociatedCount();
      return;
    }

    let html = '';
    list.forEach(p => {
      const isSelected = p._selected ? 'selected' : '';
      const isChecked = p._selected ? 'checked' : '';
      html += `
        <div class="shuttle-item ${isSelected}" data-id="${p.id}">
          <input type="checkbox" class="shuttle-cb" data-id="${p.id}" ${isChecked}>
          <div class="flex-grow-1">
            <div class="fw-semibold text-success" style="font-size: 0.85rem;">${escapeHTML(p.nome)}</div>
            <div class="text-muted font-monospace small d-flex align-items-center justify-content-between" style="font-size: 0.72rem;">
              <span>SKU: ${escapeHTML(p.sku)}</span>
              <a href="<?= url('products/edit?id=') ?>${p.id}" class="text-decoration-none text-muted" target="_blank" title="Apri scheda prodotto" style="cursor: pointer;">
                Apri scheda <i class="fa-solid fa-arrow-up-right-from-square ms-1" style="font-size:0.65rem;"></i>
              </a>
            </div>
          </div>
        </div>
      `;
    });

    elContainerAssociated.innerHTML = html;

    // Aggiungi event listeners per la selezione al click sull'intero elemento
    elContainerAssociated.querySelectorAll('.shuttle-item').forEach(item => {
      item.addEventListener('click', function(e) {
        // Se il click è su "Apri scheda", non fare il toggle
        if (e.target.closest('a')) return;
        if (e.target.type !== 'checkbox') {
          const cb = this.querySelector('.shuttle-cb');
          cb.checked = !cb.checked;
          cb.dispatchEvent(new Event('change'));
        }
      });
    });

    elContainerAssociated.querySelectorAll('.shuttle-cb').forEach(cb => {
      cb.addEventListener('change', function() {
        const id = parseInt(this.dataset.id);
        const item = associatedList.find(p => p.id === id);
        if (item) {
          item._selected = this.checked;
          const parent = this.closest('.shuttle-item');
          if (parent) parent.classList.toggle('selected', this.checked);
        }
        updateAssociatedCount();
      });
    });

    updateAssociatedCount();
  }

  // Aggiorna contatore e pulsante dei prodotti disponibili
  function updateAvailableCount() {
    const selected = availableList.filter(p => p._selected && !associatedList.some(assoc => assoc.id === p.id));
    const count = selected.length;
    elCountAvailableSelected.textContent = `${count} selezionati`;
    elBtnAssociate.disabled = count === 0;
  }

  // Aggiorna contatore e pulsante dei prodotti associati
  function updateAssociatedCount() {
    const selected = associatedList.filter(p => p._selected);
    const count = selected.length;
    elCountAssociatedSelected.textContent = `${count} selezionati`;
    elBtnDisassociate.disabled = count === 0;
  }

  // Cerca prodotti sul server tramite API
  let searchTimer = null;
  async function searchProducts() {
    const query = elSearchAvailable.value.trim();
    const catId = elCategoryFilter.value;

    if (query.length < 2) {
      availableList = [];
      renderAvailable();
      return;
    }

    // Mostriamo uno spinner di caricamento nella lista
    elContainerAvailable.innerHTML = `
      <div class="text-center py-5 text-muted">
        <i class="fa-solid fa-spinner fa-spin fa-2xl mb-3 text-accent"></i>
        <div class="small fw-semibold">Ricerca in corso...</div>
      </div>
    `;

    try {
      const response = await fetch('<?= url("sales/quotes/api/prodotti") ?>?q=' + encodeURIComponent(query));
      const data = await response.json();

      let results = data.map(p => ({
        id: parseInt(p.id),
        nome: p.nome || p.label || '',
        sku: p.sku || p.sku_interno || '',
        attivo: true,
        categoria_id: p.categoria_id ? parseInt(p.categoria_id) : null,
        _selected: false
      }));

      // Se c'è un filtro categoria, filtriamo i risultati
      if (catId) {
        results = results.filter(p => p.categoria_id === parseInt(catId));
      }

      availableList = results;
      renderAvailable();
    } catch (err) {
      console.error(err);
      window.qkToast('Errore nel caricamento del catalogo.', 'danger');
      elContainerAvailable.innerHTML = `
        <div class="text-danger text-center py-5">
          <i class="fa-solid fa-triangle-exclamation fa-2xl mb-3"></i>
          <div class="small fw-semibold">Errore nel recupero dei prodotti.</div>
        </div>
      `;
    }
  }

  // Event listener per la ricerca
  if (elSearchAvailable) {
    elSearchAvailable.addEventListener('input', () => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(searchProducts, 250);
    });
  }

  if (elCategoryFilter) {
    elCategoryFilter.addEventListener('change', searchProducts);
  }

  if (elSearchAssociated) {
    elSearchAssociated.addEventListener('input', renderAssociated);
  }

  // Associazione massiva di prodotti selezionati
  if (elBtnAssociate) {
    elBtnAssociate.addEventListener('click', async function() {
      const selected = availableList.filter(p => p._selected && !associatedList.some(assoc => assoc.id === p.id));
      if (selected.length === 0) return;

      elBtnAssociate.disabled = true;
      const originalHTML = elBtnAssociate.innerHTML;
      elBtnAssociate.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Associazione...';

      const fd = new FormData();
      fd.append('csrf_token', CSRF);
      fd.append('sostanza_id', SOSTANZA_ID);
      selected.forEach(p => {
        fd.append('prodotto_ids[]', p.id);
      });

      try {
        const response = await fetch('<?= url("adr/sostanze/api/associa-prodotti") ?>', {
          method: 'POST',
          body: fd
        });
        const resData = await response.json();

        if (resData.ok) {
          window.qkToast(`Associati con successo ${selected.length} prodotti!`, 'success');
          
          // Spostiamo i prodotti dallo stato disponibili ad associati
          selected.forEach(p => {
            p._selected = false;
            associatedList.push({
              id: p.id,
              nome: p.nome,
              sku: p.sku,
              attivo: p.attivo,
              categoria_id: p.categoria_id
            });
          });

          // Pulisci selezione e renderizza
          renderAvailable();
          renderAssociated();
        } else {
          window.qkToast(resData.error || 'Errore durante l\'associazione.', 'danger');
        }
      } catch (err) {
        console.error(err);
        window.qkToast('Errore di rete.', 'danger');
      } finally {
        elBtnAssociate.disabled = false;
        elBtnAssociate.innerHTML = originalHTML;
      }
    });
  }

  // Disassociazione massiva di prodotti selezionati
  if (elBtnDisassociate) {
    elBtnDisassociate.addEventListener('click', async function() {
      const selected = associatedList.filter(p => p._selected);
      if (selected.length === 0) return;

      if (!confirm(`Vuoi davvero scollegare ${selected.length} prodotti selezionati da questa sostanza?`)) {
        return;
      }

      elBtnDisassociate.disabled = true;
      const originalHTML = elBtnDisassociate.innerHTML;
      elBtnDisassociate.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Rimozione...';

      const fd = new FormData();
      fd.append('csrf_token', CSRF);
      selected.forEach(p => {
        fd.append('prodotto_ids[]', p.id);
      });

      try {
        const response = await fetch('<?= url("adr/sostanze/api/disassocia-prodotto") ?>', {
          method: 'POST',
          body: fd
        });
        const resData = await response.json();

        if (resData.ok) {
          window.qkToast(`Scollegati con successo ${selected.length} prodotti!`, 'success');

          // Rimuoviamo dalla lista locale dei prodotti associati
          const selectedIds = new Set(selected.map(p => p.id));
          associatedList = associatedList.filter(p => !selectedIds.has(p.id));

          // Aggiorna renderizzazione
          renderAvailable();
          renderAssociated();
        } else {
          window.qkToast(resData.error || 'Errore durante lo scollegamento.', 'danger');
        }
      } catch (err) {
        console.error(err);
        window.qkToast('Errore di rete.', 'danger');
      } finally {
        elBtnDisassociate.disabled = false;
        elBtnDisassociate.innerHTML = originalHTML;
      }
    });
  }

  // Primo caricamento
  renderAssociated();
  renderAvailable();

});
</script>

<?php endif; ?>
