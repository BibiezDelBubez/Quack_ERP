<?php
/**
 * @var array $categorie   adr_categorie_trasporto [{categoria, moltiplicatore_punti, soglia_esenzione_kg_l, descrizione}]
 * @var array $sostanze    sostanze_chimiche con join codici_onu e categorie_trasporto
 */
?>

<div class="qk-guidance-box mb-4">
  <div class="fs-4"><i class="fa-solid fa-circle-info"></i></div>
  <div>
    <h6 class="mb-1 fw-bold">Regola dei 1000 punti (ADR 1.1.3.6)</h6>
    <p class="mb-1 text-muted" style="font-size:0.875rem">
      Una spedizione è <strong>esente dal regime ADR completo</strong> se la somma dei punti di tutte le merci pericolose a bordo è inferiore a 1000.
      I punti di ogni sostanza si calcolano come: <strong>quantità (kg o L) × moltiplicatore della categoria di trasporto</strong>.
    </p>
    <p class="mb-0 text-muted" style="font-size:.85rem">
      Categoria 1 → ×50 &nbsp;|&nbsp; Categoria 2 → ×3 &nbsp;|&nbsp; Categoria 3 → ×1 &nbsp;|&nbsp; Categoria 4 → ×0 (non conta)
      &nbsp;|&nbsp; <span style="color:var(--qk-danger)">Categoria 0 → esenzione <em>mai</em> applicabile</span>
    </p>
  </div>
</div>

<div class="row g-4">

  <!-- ══ COLONNA PRINCIPALE: tabella righe ════════════════════════════════ -->
  <div class="col-lg-8">
    <div class="qk-card">
      <div class="qk-card-header d-flex justify-content-between align-items-center">
        <span class="qk-card-title"><i class="fa-solid fa-list-check me-2"></i>Merci pericolose nel carico</span>
        <div class="d-flex gap-2">
          <button type="button" id="btn-add-row" class="btn-qk btn-qk-secondary btn-qk-sm">
            <i class="fa-solid fa-flask-vial me-1"></i>Sostanza ADR
          </button>
          <button type="button" id="btn-add-prodotto" class="btn-qk btn-qk-primary btn-qk-sm">
            <i class="fa-solid fa-box me-1"></i>Prodotto
          </button>
        </div>
      </div>

      <div class="qk-table-wrap">
        <table class="qk-table" id="tbl-esenzione" style="font-size:.875rem">
          <thead>
            <tr>
              <th style="min-width:220px">Sostanza</th>
              <th style="width:90px">UN</th>
              <th style="width:80px">Cat.</th>
              <th style="width:60px" class="text-center">×</th>
              <th style="width:120px">Quantità (kg/L)</th>
              <th style="width:90px" class="text-end">Punti</th>
              <th style="width:36px"></th>
            </tr>
          </thead>
          <tbody id="tbody-esenzione">
            <!-- righe dinamiche -->
          </tbody>
          <tfoot>
            <tr class="qk-table-total-row">
              <td colspan="5" class="text-end pe-3 fw-semibold">Totale punti</td>
              <td class="text-end fw-bold" id="cell-totale" style="font-size:1.05rem">0</td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>

      <div class="qk-card-body pt-2 pb-3">
        <button type="button" id="btn-reset" class="btn-qk btn-qk-secondary btn-qk-sm">
          <i class="fa-solid fa-rotate-left me-1"></i>Azzera tutto
        </button>
      </div>
    </div>
  </div>

  <!-- ══ PANNELLO LATERALE: risultato ════════════════════════════════════ -->
  <div class="col-lg-4">

    <!-- Risultato esenzione -->
    <div class="qk-card mb-3" id="card-risultato">
      <div class="qk-card-header">
        <span class="qk-card-title">Risultato</span>
      </div>
      <div class="qk-card-body text-center py-4">
        <!-- Stato: vuoto -->
        <div id="stato-vuoto">
          <i class="fa-regular fa-calculator fa-2x mb-2" style="color:var(--qk-text-muted)"></i>
          <p class="small mb-0" style="color:var(--qk-text-muted)">Aggiungi le sostanze per calcolare.</p>
        </div>
        <!-- Stato: esente -->
        <div id="stato-esente" style="display:none">
          <div style="font-size:2.5rem;font-weight:700;color:var(--qk-success)" id="num-punti-esente">0</div>
          <div class="small mb-2" style="color:var(--qk-text-muted)">punti su 1000</div>
          <span class="qk-badge confirmed px-3 py-2" style="font-size:.9rem">
            <i class="fa-solid fa-circle-check me-1"></i>ESENTE
          </span>
          <p class="small mt-3 mb-0" style="color:var(--qk-text-secondary)">
            La spedizione rientra nell'esenzione piccolo carico (ADR 1.1.3.6).
            I documenti ADR completi non sono obbligatori.
          </p>
        </div>
        <!-- Stato: non esente -->
        <div id="stato-non-esente" style="display:none">
          <div style="font-size:2.5rem;font-weight:700;color:var(--qk-danger)" id="num-punti-non-esente">0</div>
          <div class="small mb-2" style="color:var(--qk-text-muted)">punti su 1000</div>
          <span class="qk-badge cancelled px-3 py-2" style="font-size:.9rem">
            <i class="fa-solid fa-circle-xmark me-1"></i>NON ESENTE
          </span>
          <p class="small mt-3 mb-0" style="color:var(--qk-text-secondary)">
            La spedizione supera il limite. Si applicano tutti gli obblighi ADR
            (formazione autista, documentazione, segnaletica).
          </p>
        </div>
        <!-- Stato: bloccato (categoria 0) -->
        <div id="stato-bloccato" style="display:none">
          <i class="fa-solid fa-ban fa-2x mb-2" style="color:var(--qk-danger)"></i>
          <div class="fw-bold mb-1" style="color:var(--qk-danger)">ESENZIONE NON APPLICABILE</div>
          <p class="small mb-0" style="color:var(--qk-text-secondary)">
            Il carico contiene una o più sostanze di Categoria 0 (es. esplosivi ad alto rischio)
            per le quali l'esenzione piccolo carico non è mai consentita.
          </p>
        </div>
      </div>

      <!-- Barra progresso punti -->
      <div id="wrap-progress" style="display:none;padding:0 1rem 1rem">
        <div style="background:var(--qk-border);border-radius:99px;height:8px;overflow:hidden">
          <div id="bar-progress"
               style="height:100%;border-radius:99px;transition:width .3s ease,background .3s ease;width:0%">
          </div>
        </div>
        <div class="d-flex justify-content-between mt-1" style="font-size:.75rem;color:var(--qk-text-muted)">
          <span>0</span><span>500</span><span>1000</span>
        </div>
      </div>
    </div>

    <!-- Legenda categorie -->
    <div class="qk-card">
      <div class="qk-card-header">
        <span class="qk-card-title" style="font-size:.8rem">Categorie di trasporto</span>
      </div>
      <div class="qk-card-body p-0">
        <table class="qk-table" style="font-size:.8rem">
          <thead>
            <tr><th>Cat.</th><th>Molt.</th><th>Soglia/UT</th></tr>
          </thead>
          <tbody>
            <?php foreach ($categorie as $cat): ?>
            <?php
              $catLabel = match((int)$cat['categoria']) {
                0 => ['label' => 'Mai esente', 'style' => 'color:var(--qk-danger);font-weight:600'],
                4 => ['label' => '—', 'style' => 'color:var(--qk-text-muted)'],
                default => ['label' => '', 'style' => ''],
              };
            ?>
            <tr>
              <td class="fw-semibold"><?= (int)$cat['categoria'] ?></td>
              <td>×<?= (int)$cat['moltiplicatore_punti'] ?>
                <?php if ($catLabel['label']): ?>
                <span style="<?= $catLabel['style'] ?>;font-size:.75rem"> <?= $catLabel['label'] ?></span>
                <?php endif; ?>
              </td>
              <td style="color:var(--qk-text-muted)">
                <?= $cat['soglia_esenzione_kg_l'] ? number_format((int)$cat['soglia_esenzione_kg_l']) . ' kg/L' : '—' ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>

<!-- ══ MOTORE + WIRING ══════════════════════════════════════════════════ -->
<script src="<?= url('assets/js/adr-calcolatore.js') ?>"></script>
<script>
(function () {

  const cellTotale        = document.getElementById('cell-totale');
  const barProgress       = document.getElementById('bar-progress');
  const wrapProgress      = document.getElementById('wrap-progress');
  const statoVuoto        = document.getElementById('stato-vuoto');
  const statoEsente       = document.getElementById('stato-esente');
  const statoNonEsente    = document.getElementById('stato-non-esente');
  const statoBloccato     = document.getElementById('stato-bloccato');
  const numPuntiEsente    = document.getElementById('num-punti-esente');
  const numPuntiNonEsente = document.getElementById('num-punti-non-esente');

  function showStato(stato) {
    statoVuoto.style.display     = stato === 'vuoto'      ? '' : 'none';
    statoEsente.style.display    = stato === 'esente'     ? '' : 'none';
    statoNonEsente.style.display = stato === 'non-esente' ? '' : 'none';
    statoBloccato.style.display  = stato === 'bloccato'   ? '' : 'none';
  }

  const calc = new QkAdrCalcolatore({
    tbody:          document.getElementById('tbody-esenzione'),
    categorie:      <?= json_encode(array_values($categorie), JSON_HEX_TAG) ?>,
    sostanze:       <?= json_encode(array_values($sostanze),  JSON_HEX_TAG) ?>,
    apiProdottiAdr: <?= json_encode(url('adr/api/prodotti-adr'), JSON_HEX_TAG) ?>,

    onUpdate({ totale, stato }) {
      if (stato === 'vuoto') {
        cellTotale.textContent     = '0';
        wrapProgress.style.display = 'none';
      } else if (stato === 'bloccato') {
        cellTotale.textContent     = '∞';
        wrapProgress.style.display = 'none';
      } else {
        const fmt = totale % 1 === 0 ? String(totale) : totale.toFixed(1);
        cellTotale.textContent     = fmt;
        wrapProgress.style.display = 'block';
        const pct = Math.min((totale / 1000) * 100, 100);
        barProgress.style.width      = pct + '%';
        barProgress.style.background = totale >= 1000
          ? 'var(--qk-danger)'
          : totale >= 700 ? 'var(--qk-warning)' : 'var(--qk-success)';
        if (stato === 'esente')     numPuntiEsente.textContent    = fmt;
        if (stato === 'non-esente') numPuntiNonEsente.textContent = fmt;
      }
      showStato(stato);
    },
  });

  document.getElementById('btn-add-row').addEventListener('click',     () => calc.addRowSostanza());
  document.getElementById('btn-add-prodotto').addEventListener('click', () => calc.addRowProdotto());
  document.getElementById('btn-reset').addEventListener('click',        () => calc.reset());

})();
</script>
