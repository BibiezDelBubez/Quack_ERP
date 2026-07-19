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
            <input type="text" name="codice" id="mc-codice" class="qk-form-control qk-font-mono"
                   placeholder="es. 3, 4.1, 6.1" maxlength="5" required>
            <div class="qk-form-hint">Per classi con divisioni, usa il codice padre (es. <code>1</code> non <code>1.1</code>).</div>
          </div>
          <div class="mb-3">
            <label class="qk-form-label">Denominazione <span class="text-danger">*</span></label>
            <input type="text" name="denominazione" id="mc-denom" class="qk-form-control" maxlength="100" required>
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
            <input type="text" name="codice" id="mi-codice" class="qk-form-control qk-font-mono text-uppercase"
                   placeholder="I, II, III" maxlength="3" required>
          </div>
          <div class="mb-3">
            <label class="qk-form-label">Descrizione <span class="text-danger">*</span></label>
            <input type="text" name="descrizione" id="mi-desc" class="qk-form-control" maxlength="100" required>
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
              <input type="text" name="codice" id="mg-codice" class="qk-form-control qk-font-mono"
                     placeholder="es. B, C/D" maxlength="10" required>
            </div>
            <div class="col-sm-8">
              <label class="qk-form-label">Codice cisterna</label>
              <input type="text" name="si_applica_a_cisterna" id="mg-cisterna" class="qk-form-control qk-font-mono"
                     placeholder="Codice diverso per cisterne">
              <div class="qk-form-hint">Lascia vuoto se il codice è lo stesso per colli e cisterne.</div>
            </div>
          </div>
          <div class="mb-3">
            <label class="qk-form-label">Descrizione</label>
            <input type="text" name="descrizione" id="mg-desc" class="qk-form-control" maxlength="255">
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
              <input type="number" name="categoria" id="mcat-cat" class="qk-form-control qk-font-mono"
                     placeholder="0–4" min="0" max="4" required>
            </div>
            <div class="col-sm-4">
              <label class="qk-form-label">Moltiplicatore punti <span class="text-danger">*</span></label>
              <input type="number" name="moltiplicatore_punti" id="mcat-molt" class="qk-form-control"
                     placeholder="es. 50" min="0">
            </div>
            <div class="col-sm-4">
              <label class="qk-form-label">Soglia esenzione (kg/l)</label>
              <input type="number" name="soglia_esenzione_kg_l" id="mcat-soglia" class="qk-form-control"
                     placeholder="Lascia vuoto se nessuna" min="0">
            </div>
          </div>
          <div class="mb-3">
            <label class="qk-form-label">Descrizione</label>
            <input type="text" name="descrizione" id="mcat-desc" class="qk-form-control" maxlength="255">
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
              <input type="text" name="codice" id="mcl-codice" class="qk-form-control qk-font-mono text-uppercase"
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
              <input type="text" name="pericolo_primario" id="mcl-prim" class="qk-form-control"
                     placeholder="es. Infiammabile" maxlength="50">
            </div>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-sm-4">
              <label class="qk-form-label">Pericolo secondario</label>
              <input type="text" name="pericolo_secondario" id="mcl-sec" class="qk-form-control"
                     placeholder="es. Tossico" maxlength="50">
            </div>
            <div class="col-sm-8">
              <label class="qk-form-label">Descrizione</label>
              <input type="text" name="descrizione" id="mcl-desc" class="qk-form-control" maxlength="255">
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
