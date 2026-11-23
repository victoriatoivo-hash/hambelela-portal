<?php
/**
 * Performance Command Centre compact navigation.
 *
 * The owner sees all performance areas. Staff members viewing their own profile
 * retain access to the profile and the normal workspace without owner-only links.
 */
declare(strict_types=1);
$pcIsOwner = current_role_key() === 'owner_admin';
$pcSection = (string)($_GET['tab'] ?? 'business-health');
$pcOnProfile = basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'kpi-employee.php';
$pcIcons = [
    'home' => 'house', 'overview' => 'layout-dashboard', 'people' => 'users',
    'work' => 'clipboard-check', 'activity' => 'activity', 'marketing' => 'megaphone',
    'delivery' => 'truck', 'settings' => 'settings-2', 'back' => 'arrow-left', 'recognition'=>'award'
];
$pcLinks = [
    ['overview','Business health',BASE_URL.'/apps/operations/reports.php?tab=business-health','business-health'],
    ['people','Employees',BASE_URL.'/apps/operations/reports.php?tab=employees','employees'],
    ['activity','Activity timeline',BASE_URL.'/apps/operations/reports.php?tab=business-activity','business-activity'],
    ['work','Work & quality',BASE_URL.'/apps/operations/reports.php?tab=work-quality','work-quality'],
    ['marketing','Marketing performance',BASE_URL.'/apps/operations/reports.php?tab=marketing','marketing'],
    ['delivery','Driver performance',BASE_URL.'/apps/operations/reports.php?tab=drivers','drivers'],
    ['recognition','Recognition',BASE_URL.'/apps/operations/reports.php?tab=recognition','recognition'],
    ['settings','Performance settings',BASE_URL.'/apps/operations/reports.php?tab=settings','settings'],
];
?>
<nav class="pc-rail" aria-label="Performance Command Centre navigation">
    <a class="pc-rail-brand" href="<?= htmlspecialchars(BASE_URL.'/index.php', ENT_QUOTES, 'UTF-8') ?>" aria-label="Hambelela home"><i data-lucide="leaf" aria-hidden="true"></i><span>Hambelela Organic</span></a>
    <div class="pc-rail-items">
        <a class="pc-rail-link" href="<?= htmlspecialchars(BASE_URL.'/index.php',ENT_QUOTES,'UTF-8') ?>" aria-label="Business dashboard" title="Business dashboard"><i data-lucide="house" aria-hidden="true"></i><span>Business dashboard</span></a>
        <?php if ($pcIsOwner): foreach ($pcLinks as [$icon,$title,$href,$tab]): ?>
        <a class="pc-rail-link<?= !$pcOnProfile && $pcSection === $tab ? ' is-current' : '' ?>" href="<?= htmlspecialchars($href,ENT_QUOTES,'UTF-8') ?>" aria-label="<?= htmlspecialchars($title,ENT_QUOTES,'UTF-8') ?>" title="<?= htmlspecialchars($title,ENT_QUOTES,'UTF-8') ?>"<?= !$pcOnProfile && $pcSection === $tab ? ' aria-current="page"' : '' ?>><i data-lucide="<?= htmlspecialchars($pcIcons[$icon] ?? 'circle', ENT_QUOTES,'UTF-8') ?>" aria-hidden="true"></i><span><?= htmlspecialchars($title,ENT_QUOTES,'UTF-8') ?></span></a>
        <?php endforeach; else: ?>
        <a class="pc-rail-link is-current" href="<?= htmlspecialchars(BASE_URL.'/apps/operations/kpi-employee.php',ENT_QUOTES,'UTF-8') ?>" title="My performance" aria-current="page"><i data-lucide="user-round" aria-hidden="true"></i><span>My performance</span></a>
        <a class="pc-rail-link" href="<?= htmlspecialchars(BASE_URL.'/apps/operations/checklists.php',ENT_QUOTES,'UTF-8') ?>" title="My tasks"><i data-lucide="clipboard-check" aria-hidden="true"></i><span>My tasks</span></a>
        <?php endif; ?>
    </div>
    <a class="pc-rail-return" href="<?= htmlspecialchars(BASE_URL.'/index.php',ENT_QUOTES,'UTF-8') ?>" title="Back to portal"><i data-lucide="arrow-left" aria-hidden="true"></i><span>Portal</span></a>
</nav>
<script src="<?= BASE_URL ?>/assets/js/performance-command-people.js?v=<?= (int)filemtime(BASE_PATH.'/assets/js/performance-command-people.js') ?>"></script>
