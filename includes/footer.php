<?php
/**
 * footer.php — chiude il <main>, carica JS, chiude <body> e <html>
 * Includi sempre come ultimo include di ogni pagina.
 */
?>
</main><!-- /qk-main -->

<?php require_once INCLUDES_DIR . '/entity_picker_modal.php'; ?>
<?php require_once INCLUDES_DIR . '/modals/info.php'; ?>

<!-- ── JS ────────────────────────────────────────────────────────────────── -->
<!-- Bootstrap 5 bundle (include Popper) -->
<script src="<?= asset('js/bootstrap.bundle.js') ?>"></script>
<!-- App logic -->
<script src="<?= asset('js/app.js') ?>"></script>

<?php
/**
 * JS aggiuntivi per-pagina.
 * Impostare $extra_js come array di path relativi alla cartella assets/js/
 * prima di includere header.php. Es: $extra_js = ['sales-orders-shipment.js'];
 */
if (!empty($extra_js) && is_array($extra_js)):
  foreach ($extra_js as $_ejs): ?>
<script src="<?= asset('js/' . e($_ejs)) ?>"></script>
<?php endforeach; endif; ?>

</body>
</html>
