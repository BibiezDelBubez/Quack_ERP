<?php /** @var array $ordine */ /** @var array $righe */ ?>

<div class="qk-page-header">
  <h2>Richiedi Correzione — Ordine <?= e($ordine['numero_ordine']) ?></h2>
  <p class="text-muted">
    Questo ordine è già segnato come <strong>Fatturato</strong>. Non può essere modificato direttamente:
    seleziona qui sotto le righe da correggere, indica i valori corretti, e la richiesta dovrà essere
    approvata prima di avere effetto. Nessun dato dell'ordine viene cambiato finché la richiesta non è approvata.
  </p>
</div>

<form action="<?= url('sales/orders/correzioni/action') ?>" method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="azione" value="richiedi">
  <input type="hidden" name="ordine_id" value="<?= (int)$ordine['id'] ?>">

  <div class="qk-form-group">
    <label class="qk-form-label">Motivo (facoltativo, solo nota discorsiva)</label>
    <textarea name="motivo" class="qk-form-control" rows="2" placeholder="Es. Errore di battitura confermato al telefono con il cliente"></textarea>
  </div>

  <table class="qk-table">
    <thead>
      <tr>
        <th></th>
        <th>Prodotto</th>
        <th>Quantità attuale</th>
        <th>Quantità corretta</th>
        <th>Prezzo attuale</th>
        <th>Prezzo corretto</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($righe as $r): ?>
        <tr>
          <td><input type="checkbox" name="righe[<?= (int)$r['id'] ?>][seleziona]" value="1"></td>
          <td><?= e($r['descrizione']) ?></td>
          <td><?= e((string)$r['quantita_ordinata']) ?></td>
          <td><input type="number" step="0.001" name="righe[<?= (int)$r['id'] ?>][quantita_corretta]" class="qk-form-control" value="<?= e((string)$r['quantita_ordinata']) ?>"></td>
          <td><?= e((string)$r['prezzo_unitario']) ?></td>
          <td><input type="number" step="0.0001" name="righe[<?= (int)$r['id'] ?>][prezzo_unitario_corretta]" class="qk-form-control" value="<?= e((string)$r['prezzo_unitario']) ?>"></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <button type="submit" class="btn-qk btn-qk-primary">Invia Richiesta di Correzione</button>
  <a href="<?= url('sales/orders/edit') ?>?id=<?= (int)$ordine['id'] ?>" class="btn-qk btn-qk-secondary">Annulla</a>
</form>
