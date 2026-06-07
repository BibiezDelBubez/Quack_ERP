<?php
/**
 * @var array $categorie   adr_categorie_trasporto [{categoria, moltiplicatore_punti, soglia_esenzione_kg_l, descrizione}]
 * @var array $sostanze    sostanze_adr con join codici_onu e categorie_trasporto
 */
?>

<!-- Guidance box -->
<div class="qk-guidance-box mb-4 p-3 border-start border-4 rounded d-flex align-items-start gap-3"
     style="background:var(--qk-bg-subtle);border-color:var(--qk-accent)!important">
  <div class="fs-4" style="color:var(--qk-accent);flex-shrink:0"><i class="fa-solid fa-circle-info"></i></div>
  <div style="font-size:.875rem">
    <p class="fw-semibold mb-1" style="color:var(--qk-text-primary)">Regola dei 1000 punti (ADR 1.1.3.6)</p>
    <p class="mb-1" style="color:var(--qk-text-secondary)">
      Una spedizione è <strong>esente dal regime ADR completo</strong> se la somma dei punti di tutte le merci pericolose a bordo è inferiore a 1000.
      I punti di ogni sostanza si calcolano come: <strong>quantità (kg o L) × moltiplicatore della categoria di trasporto</strong>.
    </p>
    <p class="mb-0" style="color:var(--qk-text-muted)">
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
        <button type="button" id="btn-add-row" class="btn-qk btn-qk-primary btn-qk-sm">
          <i class="fa-solid fa-plus me-1"></i>Aggiungi sostanza
        </button>
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

<!-- ══ DATI PHP → JS ════════════════════════════════════════════════════ -->
<script>
const QkAdrEsenzione = {
  sostanze: <?= json_encode(array_values($sostanze), JSON_HEX_TAG) ?>,
  categorie: <?= json_encode(array_values($categorie), JSON_HEX_TAG) ?>,
};
</script>

<script>
(function () {
  /* ── Stato ──────────────────────────────────────────────────────────── */
  let rows = [];   // [{id, nome, unCode, categoria, moltiplicatore, quantita}]
  let nextId = 1;

  const tbody        = document.getElementById('tbody-esenzione');
  const cellTotale   = document.getElementById('cell-totale');
  const barProgress  = document.getElementById('bar-progress');
  const wrapProgress = document.getElementById('wrap-progress');

  const statoVuoto      = document.getElementById('stato-vuoto');
  const statoEsente     = document.getElementById('stato-esente');
  const statoNonEsente  = document.getElementById('stato-non-esente');
  const statoBloccato   = document.getElementById('stato-bloccato');
  const numPuntiEsente      = document.getElementById('num-punti-esente');
  const numPuntiNonEsente   = document.getElementById('num-punti-non-esente');

  /* Mappa sostanza_id → dati */
  const sostanzeMap = {};
  QkAdrEsenzione.sostanze.forEach(s => { sostanzeMap[s.id] = s; });

  /* ── Costruisce la <option> list del select sostanze ────────────────── */
  function buildSelectOptions(currentId) {
    let html = '<option value="">— Seleziona sostanza —</option>';
    const withCat = QkAdrEsenzione.sostanze.filter(s => s.categoria_trasporto !== null && s.categoria_trasporto !== undefined);
    const withoutCat = QkAdrEsenzione.sostanze.filter(s => s.categoria_trasporto === null || s.categoria_trasporto === undefined);

    if (withCat.length) {
      html += '<optgroup label="Sostanze con categoria nota">';
      withCat.forEach(s => {
        const label = s.nome + (s.codice_onu ? ' — UN' + s.codice_onu : '') + ' (Cat.' + s.categoria_trasporto + ')';
        html += `<option value="${s.id}" ${String(s.id) === String(currentId) ? 'selected' : ''}>${escHtml(label)}</option>`;
      });
      html += '</optgroup>';
    }
    if (withoutCat.length) {
      html += '<optgroup label="Sostanze senza categoria (inserisci manualmente)">';
      withoutCat.forEach(s => {
        const label = s.nome + (s.codice_onu ? ' — UN' + s.codice_onu : '') + ' [cat. mancante]';
        html += `<option value="${s.id}" ${String(s.id) === String(currentId) ? 'selected' : ''}>${escHtml(label)}</option>`;
      });
      html += '</optgroup>';
    }
    return html;
  }

  /* ── Costruisce la <option> list categorie (per override manuale) ───── */
  function buildCatOptions(currentCat) {
    let html = '<option value="">—</option>';
    QkAdrEsenzione.categorie.forEach(c => {
      const label = 'Cat. ' + c.categoria + ' (×' + c.moltiplicatore_punti + ')';
      html += `<option value="${c.categoria}" ${String(c.categoria) === String(currentCat) ? 'selected' : ''}>${label}</option>`;
    });
    return html;
  }

  /* ── Aggiunge una riga ──────────────────────────────────────────────── */
  function addRow() {
    const id = nextId++;
    rows.push({ id, sostanzaId: '', nome: '', unCode: '', categoria: '', moltiplicatore: null, quantita: 0 });
    renderRows();
    // Focus sul select della nuova riga
    const sel = tbody.querySelector(`[data-row-id="${id}"] .sel-sostanza`);
    if (sel) sel.focus();
  }

  /* ── Rimuove una riga ───────────────────────────────────────────────── */
  function removeRow(id) {
    rows = rows.filter(r => r.id !== id);
    renderRows();
    updateTotale();
  }

  /* ── Quando cambia il select sostanza ──────────────────────────────── */
  function onSostanzaChange(rowId, sostanzaId) {
    const row = rows.find(r => r.id === rowId);
    if (!row) return;
    row.sostanzaId = sostanzaId;
    if (sostanzaId && sostanzeMap[sostanzaId]) {
      const s = sostanzeMap[sostanzaId];
      row.unCode       = s.codice_onu   || '';
      row.categoria    = s.categoria_trasporto !== null ? String(s.categoria_trasporto) : '';
      row.moltiplicatore = s.moltiplicatore_punti !== null ? parseInt(s.moltiplicatore_punti) : null;
    } else {
      row.unCode = ''; row.categoria = ''; row.moltiplicatore = null;
    }
    renderRows();
    updateTotale();
  }

  /* ── Quando cambia la categoria (override manuale) ──────────────────── */
  function onCatChange(rowId, catVal) {
    const row = rows.find(r => r.id === rowId);
    if (!row) return;
    row.categoria = catVal;
    const catObj = QkAdrEsenzione.categorie.find(c => String(c.categoria) === String(catVal));
    row.moltiplicatore = catObj ? parseInt(catObj.moltiplicatore_punti) : null;
    updateTotale();
    // Aggiorna solo la cella punti senza re-render completo
    const tr = tbody.querySelector(`[data-row-id="${rowId}"]`);
    if (tr) tr.querySelector('.cell-punti').textContent = formatPunti(row);
  }

  /* ── Quando cambia la quantità ──────────────────────────────────────── */
  function onQtyChange(rowId, val) {
    const row = rows.find(r => r.id === rowId);
    if (!row) return;
    row.quantita = parseFloat(val.replace(',', '.')) || 0;
    updateTotale();
    const tr = tbody.querySelector(`[data-row-id="${rowId}"]`);
    if (tr) tr.querySelector('.cell-punti').textContent = formatPunti(row);
  }

  /* ── Calcola punti di una riga ──────────────────────────────────────── */
  function puntiRiga(row) {
    if (row.moltiplicatore === null || row.moltiplicatore === undefined) return null;
    return row.quantita * row.moltiplicatore;
  }

  function formatPunti(row) {
    const p = puntiRiga(row);
    if (p === null) return '—';
    return p % 1 === 0 ? String(p) : p.toFixed(1);
  }

  /* ── Render completo della tabella ─────────────────────────────────── */
  function renderRows() {
    if (rows.length === 0) {
      tbody.innerHTML = `
        <tr><td colspan="7" class="text-center py-4" style="color:var(--qk-text-muted)">
          <i class="fa-regular fa-circle-plus me-1"></i>
          Nessuna sostanza aggiunta — clicca "Aggiungi sostanza" per iniziare.
        </td></tr>`;
      return;
    }

    tbody.innerHTML = rows.map(row => {
      const hasCat = row.categoria !== '';
      const catEditable = !row.sostanzaId || sostanzeMap[row.sostanzaId]?.categoria_trasporto === null;

      return `
      <tr data-row-id="${row.id}">
        <td>
          <select class="qk-form-control qk-form-control-sm sel-sostanza">
            ${buildSelectOptions(row.sostanzaId)}
          </select>
        </td>
        <td>
          <span class="small" style="color:var(--qk-text-secondary)">
            ${row.unCode ? '<span class="qk-badge adr">UN' + escHtml(String(row.unCode)) + '</span>' : '<span style="color:var(--qk-text-muted)">—</span>'}
          </span>
        </td>
        <td>
          ${catEditable
            ? `<select class="qk-form-control qk-form-control-sm sel-categoria" style="min-width:90px">${buildCatOptions(row.categoria)}</select>`
            : `<span class="fw-semibold">${escHtml(String(row.categoria))}</span>`
          }
        </td>
        <td class="text-center" style="color:var(--qk-text-muted)">
          ${row.moltiplicatore !== null ? '×' + row.moltiplicatore : '<span style="color:var(--qk-text-muted)">—</span>'}
        </td>
        <td>
          <input type="number" class="qk-form-control qk-form-control-sm inp-qty text-end"
                 value="${row.quantita || ''}" min="0" step="0.1" placeholder="0">
        </td>
        <td class="text-end fw-semibold cell-punti" style="color:${puntiRiga(row) === null ? 'var(--qk-text-muted)' : 'inherit'}">
          ${formatPunti(row)}
        </td>
        <td>
          <button type="button" class="btn-qk btn-qk-secondary btn-qk-sm btn-remove-row" title="Rimuovi">
            <i class="fa-solid fa-xmark"></i>
          </button>
        </td>
      </tr>`;
    }).join('');

    // Event listeners sulle nuove righe
    tbody.querySelectorAll('[data-row-id]').forEach(tr => {
      const rowId = parseInt(tr.dataset.rowId);
      const selSostanza = tr.querySelector('.sel-sostanza');
      const selCat      = tr.querySelector('.sel-categoria');
      const inpQty      = tr.querySelector('.inp-qty');
      const btnRem      = tr.querySelector('.btn-remove-row');

      if (selSostanza) selSostanza.addEventListener('change', () => onSostanzaChange(rowId, selSostanza.value));
      if (selCat)      selCat.addEventListener('change',      () => onCatChange(rowId, selCat.value));
      if (inpQty)      inpQty.addEventListener('input',       () => onQtyChange(rowId, inpQty.value));
      if (btnRem)      btnRem.addEventListener('click',       () => removeRow(rowId));
    });

    updateTotale();
  }

  /* ── Aggiorna totale e pannello risultato ───────────────────────────── */
  function updateTotale() {
    if (rows.length === 0) {
      cellTotale.textContent = '0';
      wrapProgress.style.display = 'none';
      showStato('vuoto');
      return;
    }

    // Controlla categoria 0
    const hasCategoria0 = rows.some(r => r.categoria === '0' || r.categoria === 0);
    if (hasCategoria0) {
      cellTotale.textContent = '∞';
      wrapProgress.style.display = 'none';
      showStato('bloccato');
      return;
    }

    // Somma punti (ignora righe senza categoria)
    let totale = 0;
    rows.forEach(r => {
      const p = puntiRiga(r);
      if (p !== null) totale += p;
    });

    cellTotale.textContent = totale % 1 === 0 ? String(totale) : totale.toFixed(1);

    // Barra progresso
    wrapProgress.style.display = 'block';
    const pct = Math.min((totale / 1000) * 100, 100);
    barProgress.style.width = pct + '%';
    barProgress.style.background = totale >= 1000
      ? 'var(--qk-danger)'
      : totale >= 700
        ? 'var(--qk-warning)'
        : 'var(--qk-success)';

    if (totale < 1000) {
      numPuntiEsente.textContent = cellTotale.textContent;
      showStato('esente');
    } else {
      numPuntiNonEsente.textContent = cellTotale.textContent;
      showStato('non-esente');
    }
  }

  function showStato(stato) {
    statoVuoto.style.display     = stato === 'vuoto'      ? '' : 'none';
    statoEsente.style.display    = stato === 'esente'     ? '' : 'none';
    statoNonEsente.style.display = stato === 'non-esente' ? '' : 'none';
    statoBloccato.style.display  = stato === 'bloccato'   ? '' : 'none';
  }

  function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  /* ── Boot ───────────────────────────────────────────────────────────── */
  document.getElementById('btn-add-row').addEventListener('click', addRow);
  document.getElementById('btn-reset').addEventListener('click', () => {
    rows = [];
    nextId = 1;
    renderRows();
    updateTotale();
  });

  renderRows(); // stato iniziale vuoto
})();
</script>
