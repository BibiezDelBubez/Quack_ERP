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

</body>
</html>
