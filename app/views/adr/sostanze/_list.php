<?php /** @var array $sostanze @var string|null $search */ ?>

<div class="qk-guidance-box mb-4 p-3 border-start border-4 rounded d-flex align-items-start gap-3"
     style="background:var(--qk-bg-subtle);border-color:var(--qk-accent)!important">
  <div class="fs-4" style="color:var(--qk-accent);flex-shrink:0"><i class="fa-solid fa-circle-info"></i></div>
  <div style="font-size:.875rem">
    <p class="fw-semibold mb-1" style="color:var(--qk-text-primary)">A cosa serve questa sezione?</p>
    <p class="mb-0" style="color:var(--qk-text-secondary)">
      Qui raggruppiamo i prodotti composti dalla stessa identica sostanza. <strong>Serve per unificare l'assegnazione
      del codice ONU, delle schede di sicurezza (SDS) e delle restrizioni di vendita — così non devi ripetere
      le stesse informazioni su ogni singolo prodotto.</strong><br>
      <span class="qk-text-muted" style="font-size:.85rem">
        Esempio: il Codice ONU 1791 è una categoria generica che copre <em>qualsiasi</em> soluzione di ipoclorito.
        Qui invece si registra la formulazione specifica — <strong>Ipoclorito di sodio 14%</strong> — con il suo CAS,
        la sua scheda di sicurezza, la classificazione CLP/GHS (pittogrammi, frasi H e P) e i dati ADR.
        Tutti i prodotti che vendono quella stessa formulazione (es. tanica 5L, fusto 25kg, ecc.) vengono poi collegati a questa scheda.
      </span>
    </p>
  </div>
</div>

<div class="qk-card">
  <div class="qk-table-toolbar">
    <div class="qk-table-toolbar-left">
      <form method="get" class="d-flex gap-2 align-items-center flex-wrap">
        <div class="qk-filter-wrap">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input type="text" name="q" class="qk-filter-input"
                 placeholder="Nome, nome tecnico, CAS, N° ONU…"
                 value="<?= e($search ?? '') ?>">
        </div>
        <button type="submit" class="btn-qk btn-qk-secondary btn-qk-sm">
          <i class="fa-solid fa-filter"></i> Filtra
        </button>
      </form>
    </div>
    <div class="qk-table-toolbar-right">
      <small class="qk-text-muted me-3"><?= count($sostanze) ?> sostan<?= count($sostanze) === 1 ? 'za' : 'ze' ?></small>
      <a href="<?= url('adr/sostanze/create') ?>" class="btn-qk btn-qk-primary btn-qk-sm">
        <i class="fa-solid fa-plus"></i> Nuova sostanza
      </a>
    </div>
  </div>

  <div class="qk-table-wrap">
    <?php if (empty($sostanze)): ?>
      <div class="qk-empty">
        <i class="fa-solid fa-flask-vial"></i>
        <p>Nessuna sostanza chimica registrata.<?= $search ? ' Prova a cambiare i filtri.' : '' ?></p>
        <?php if (!$search): ?>
        <a href="<?= url('adr/sostanze/create') ?>" class="btn-qk btn-qk-primary btn-qk-sm mt-2">
          <i class="fa-solid fa-plus"></i> Aggiungi sostanza
        </a>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <table class="qk-table" style="font-size:0.875rem">
        <thead>
          <tr>
            <th>Nome sostanza</th>
            <th>CAS</th>
            <th>N° ONU</th>
            <th>Classe</th>
            <th>G.I.</th>
            <th>Concentrazione</th>
            <th>CLP</th>
            <th>Flag</th>
            <th>SDS</th>
            <th>SKU</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($sostanze as $s): ?>
          <tr>
            <td>
              <a href="<?= url('adr/sostanze/edit?id=' . $s['id']) ?>" class="qk-text-accent fw-semibold text-decoration-none">
                <?= e($s['nome']) ?>
              </a>
              <?php if ($s['nome_tecnico']): ?>
              <div class="small qk-text-muted"><?= e($s['nome_tecnico']) ?></div>
              <?php endif; ?>
            </td>
            <td class="small">
              <?php if ($s['codice_cas']): ?>
              <code style="font-size:.8rem"><?= e($s['codice_cas']) ?></code>
              <?php else: ?><span class="qk-text-muted">—</span><?php endif; ?>
            </td>
            <td>
              <?php if ($s['codice_onu']): ?>
              <span class="qk-badge adr">UN<?= e($s['codice_onu']) ?></span>
              <?php else: ?>
              <span class="qk-text-muted">—</span>
              <?php endif; ?>
            </td>
            <td><?= $s['classe_pericolo'] ? e($s['classe_pericolo']) : '—' ?></td>
            <td>
              <?php if ($s['gruppo_imballaggio']): ?>
              <span class="fw-semibold"><?= e($s['gruppo_imballaggio']) ?></span>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td class="small">
              <?php if ($s['concentrazione_min_pct'] !== null || $s['concentrazione_max_pct'] !== null): ?>
                <?= $s['concentrazione_min_pct'] !== null ? number_format((float)$s['concentrazione_min_pct'], 1) . '%' : '' ?>
                <?php if ($s['concentrazione_min_pct'] !== null && $s['concentrazione_max_pct'] !== null): ?> – <?php endif; ?>
                <?= $s['concentrazione_max_pct'] !== null ? number_format((float)$s['concentrazione_max_pct'], 1) . '%' : '' ?>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td style="min-width:120px">
              <?php if ($s['clp_avvertenza']): ?>
                <span class="qk-badge <?= $s['clp_avvertenza'] === 'Pericolo' ? 'cancelled' : 'pending' ?> me-1">
                  <?= e($s['clp_avvertenza']) ?>
                </span>
              <?php endif; ?>
              <?php if (!empty($s['h_codes'])): ?>
                <span class="small qk-text-muted" title="<?= e($s['h_codes']) ?>">
                  <?= count(explode(' ', trim($s['h_codes']))) ?> frasi H
                </span>
              <?php endif; ?>
              <?php if (!empty($s['pittogrammi'])): ?>
                <div class="small qk-text-muted mt-1">
                  <?php foreach (explode(',', $s['pittogrammi']) as $pitt): ?>
                    <span class="badge bg-secondary me-1" style="font-size:.7rem"><?= e(trim($pitt)) ?></span>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </td>
            <td class="small">
              <?php if ($s['e_soluzione']): ?><span class="qk-badge draft me-1">Soluz.</span><?php endif; ?>
              <?php if ($s['e_rifiuto']): ?><span class="qk-badge cancelled me-1">Rifiuto</span><?php endif; ?>
            </td>
            <td class="text-center">
              <?php if ((int)$s['n_schede'] > 0): ?>
              <span class="qk-badge confirmed"><?= (int)$s['n_schede'] ?></span>
              <?php else: ?>
              <span class="qk-text-muted small">—</span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <?php if ((int)$s['n_prodotti'] > 0): ?>
              <span class="qk-badge pending"><?= (int)$s['n_prodotti'] ?></span>
              <?php else: ?>
              <span class="qk-text-muted small">—</span>
              <?php endif; ?>
            </td>
            <td class="text-end">
              <a href="<?= url('adr/sostanze/edit?id=' . $s['id']) ?>" class="btn-qk btn-qk-secondary btn-qk-sm">
                <i class="fa-solid fa-pen-to-square"></i>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
