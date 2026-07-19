window.QK_RFQ_CONFIG = {
  apiFornitori: <?= json_encode(url('purchases/rfq/api/fornitori')) ?>,
  apiProdotti: <?= json_encode(url('purchases/rfq/api/prodotti')) ?>,
  apiPrezzo: <?= json_encode(url('purchases/rfq/api/ottieni-prezzo')) ?>,
  apiFornitoreCrea: <?= json_encode(url('purchases/rfq/api/crea-fornitore')) ?>,
  apiAziendaIndirizzi: <?= json_encode(url('purchases/rfq/api/azienda-indirizzi')) ?>,
  apiFornitoreDettagli: <?= json_encode(url('purchases/rfq/api/fornitore-dettagli')) ?>,
  canEdit: <?= $canEdit ? 'true' : 'false' ?>,
  fornitoreId: <?= json_encode($values['fornitore_id']) ?>,
  rfqNumero: <?= json_encode($rfq['numero'] ?? '') ?>,
  fornitoreNome: <?= json_encode($rfq['fornitore_nome'] ?? '') ?>,
  rfqStato: <?= json_encode($values['stato'] ?? 'bozza') ?>
};