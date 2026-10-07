<?php
// Feature remains disabled until explicit migration and release validation.
$coverageEnabled=false;
try {
    $coverageRole=(string)(current_user()['role_key']??'');
    if(in_array($coverageRole,['owner_admin','front_desk_admin','marketing_sales'],true)) {
        $coverageQuery=db()->query("SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key='epi_v2_front_coverage_enabled'");
        $coverageEnabled=$coverageQuery->fetchColumn()==='1';$coverageQuery->closeCursor();
    }
} catch(Throwable $ignored) {}
if($coverageEnabled): ?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/front-coverage.css">
<script defer src="<?= BASE_URL ?>/assets/js/front-coverage.js" data-endpoint="<?= BASE_URL ?>/apps/operations/front-coverage.php"></script>
<?php endif; ?>
