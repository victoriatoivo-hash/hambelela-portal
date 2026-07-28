<?php if (empty($portalViewBarCssLoadedInHead)): ?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/portal-view-bar.css?v=<?= is_file(BASE_PATH . '/assets/css/portal-view-bar.css') ? (string) filemtime(BASE_PATH . '/assets/css/portal-view-bar.css') : (string) time() ?>-interactive8">
<?php endif; ?>
<!-- Keep responsive rules after every page-level and feature stylesheet. -->
<link rel="stylesheet" data-portal-responsive-final href="<?= BASE_URL ?>/assets/css/portal-responsive.css?v=<?= is_file(BASE_PATH . '/assets/css/portal-responsive.css') ? (string) filemtime(BASE_PATH . '/assets/css/portal-responsive.css') : (string) time() ?>-mobile-final3">
<script defer src="<?= BASE_URL ?>/assets/js/portal-view-bar.js?v=<?= is_file(BASE_PATH . '/assets/js/portal-view-bar.js') ? (string) filemtime(BASE_PATH . '/assets/js/portal-view-bar.js') : (string) time() ?>-interactive7"></script>
</div>
</body>
</html>
