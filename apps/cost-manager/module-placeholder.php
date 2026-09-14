<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';

require_role('owner_admin');

$activeApp = 'cost-manager';
$module = trim((string) ($_GET['module'] ?? 'Module'));
$pageTitle = $module . ' | ' . APP_NAME;

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module">
    <a class="button back-link" href="workbook.php"><i data-lucide="arrow-left"></i> Back to system</a>
    <section class="module-header">
        <div>
            <p class="eyebrow">Blueprint Section</p>
            <h1><?= htmlspecialchars($module, ENT_QUOTES, 'UTF-8') ?></h1>
            <p>This section is part of the rebuilt Hambelela Cost Management & Inventory Intelligence blueprint. It should be built as a connected workflow section, not as a disconnected calculator.</p>
        </div>
    </section>
    <section class="panel">
        <p>Next build step: define this section's table, filters, inline editing rules, audit history, and how it connects to the Cost Workbook source of truth.</p>
    </section>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
