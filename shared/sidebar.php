<?php

declare(strict_types=1);

$roleKey = current_role_key();
$activeApp = $activeApp ?? 'dashboard';
$navItems = [];

if ($activeApp === 'kpi') {
    $kpiTab = preg_replace('/[^a-z0-9_-]/', '', (string) ($_GET['tab'] ?? 'overview')) ?: 'overview';
    $kpiRangeParams = [];
    $kpiStartDate = (string) ($filterStartDate ?? ($_GET['start_date'] ?? ''));
    $kpiEndDate = (string) ($filterEndDate ?? ($_GET['end_date'] ?? ''));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $kpiStartDate)) {
        $kpiRangeParams['start_date'] = $kpiStartDate;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $kpiEndDate)) {
        $kpiRangeParams['end_date'] = $kpiEndDate;
    }
    $kpiRangeSuffix = $kpiRangeParams ? '&' . http_build_query($kpiRangeParams) : '';
    $kpiFrontDeskLabel = 'Front Desk Performance';
    $kpiPackerOneLabel = 'Packer 1 -- Not assigned';
    $kpiPackerTwoLabel = 'Packer 2 -- Not assigned';

    if (!empty($employeeScores) && is_array($employeeScores)) {
        $frontDeskRows = array_values(array_filter($employeeScores, static function (array $row): bool {
            return ($row['role_group'] ?? '') === 'front_desk';
        }));
        if (!$frontDeskRows) {
            $frontDeskRows = array_values(array_filter($employeeScores, static function (array $row): bool {
                $name = (string) ($row['name'] ?? '');
                $email = (string) ($row['email'] ?? '');
                return stripos($name, 'cecil') !== false
                    || stripos($name, 'secil') !== false
                    || strtolower($email) === 'shiwedasecilia3@gmail.com';
            }));
        }
        if ($frontDeskRows) {
            $frontDeskPreferred = $frontDeskRows[0];
            foreach ($frontDeskRows as $row) {
                $name = strtolower((string) ($row['name'] ?? ''));
                $email = strtolower((string) ($row['email'] ?? ''));
                $isFrontDeskPerson = stripos($name, 'cecil') !== false
                    || stripos($name, 'secil') !== false
                    || $email === 'shiwedasecilia3@gmail.com';
                if ($isFrontDeskPerson || !empty($row['hr_linked'])) {
                    $frontDeskPreferred = $row;
                    if ($isFrontDeskPerson) {
                        break;
                    }
                }
            }
            $kpiFrontDeskLabel = 'Front Desk -- ' . (string) $frontDeskPreferred['name'];
        }
    }

    if (!empty($scoresById) && is_array($scoresById)) {
        if (!empty($pickerOneId) && isset($scoresById[(int) $pickerOneId])) {
            $kpiPackerOneLabel = 'Packer 1 -- ' . (string) $scoresById[(int) $pickerOneId]['name'];
        }
        if (!empty($pickerTwoId) && isset($scoresById[(int) $pickerTwoId])) {
            $kpiPackerTwoLabel = 'Packer 2 -- ' . (string) $scoresById[(int) $pickerTwoId]['name'];
        }
    }

    $navItems = [
        ['id' => 'kpi-overview', 'tab' => 'overview', 'label' => 'Dashboard', 'icon' => 'layout-dashboard', 'href' => BASE_URL . '/apps/operations/reports.php?tab=overview' . $kpiRangeSuffix],
        ['id' => 'kpi-front-desk', 'tab' => 'front-desk', 'label' => $kpiFrontDeskLabel, 'icon' => 'headset', 'href' => BASE_URL . '/apps/operations/reports.php?tab=front-desk' . $kpiRangeSuffix],
        ['id' => 'kpi-picker-1', 'tab' => 'picker-1', 'label' => $kpiPackerOneLabel, 'icon' => 'user-check', 'href' => BASE_URL . '/apps/operations/reports.php?tab=picker-1' . $kpiRangeSuffix],
        ['id' => 'kpi-picker-2', 'tab' => 'picker-2', 'label' => $kpiPackerTwoLabel, 'icon' => 'user-round-check', 'href' => BASE_URL . '/apps/operations/reports.php?tab=picker-2' . $kpiRangeSuffix],
        ['id' => 'kpi-bonus', 'tab' => 'bonus', 'label' => 'Bonus Incentive Score', 'icon' => 'badge-dollar-sign', 'href' => BASE_URL . '/apps/operations/reports.php?tab=bonus' . $kpiRangeSuffix],
        ['id' => 'operations', 'tab' => '', 'label' => 'Back to Operations', 'icon' => 'arrow-left', 'href' => BASE_URL . '/apps/operations/index.php'],
    ];
} else {
    $navItems = [
        ['id' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'layout-dashboard', 'href' => BASE_URL . '/index.php'],
    ];

    if ($roleKey === 'owner_admin') {
        $navItems[] = ['id' => 'cost-manager', 'label' => 'Cost Workbook', 'icon' => 'table-2', 'href' => BASE_URL . '/apps/cost-manager/workbook.php'];
        $navItems[] = ['id' => 'operations', 'label' => 'Operations', 'icon' => 'clipboard-check', 'href' => BASE_URL . '/apps/operations/index.php'];
        $navItems[] = ['id' => 'operations-whatsapp', 'label' => 'Meta Comms', 'icon' => 'messages-square', 'href' => BASE_URL . '/apps/operations/whatsapp.php'];
        $navItems[] = ['id' => 'hr-portal', 'label' => 'HR Portal', 'icon' => 'shield-check', 'href' => BASE_URL . '/apps/hr-portal/index.php'];
        $navItems[] = ['id' => 'kpi', 'label' => 'KPI Reports', 'icon' => 'chart-no-axes-combined', 'href' => BASE_URL . '/apps/operations/reports.php'];
        $navItems[] = ['id' => 'operations-bookkeeping', 'label' => 'Bookkeeping', 'icon' => 'wallet-cards', 'href' => BASE_URL . '/apps/operations/bookkeeping.php'];
        $navItems[] = ['id' => 'operations-bank-processor', 'label' => 'Bank Processor', 'icon' => 'file-spreadsheet', 'href' => BASE_URL . '/apps/operations/bank-statement-processor.php'];
        $navItems[] = ['id' => 'operations-courier', 'label' => 'Courier', 'icon' => 'truck', 'href' => BASE_URL . '/apps/operations/courier.php'];
        $navItems[] = ['id' => 'operations-consignments', 'label' => 'Packing List', 'icon' => 'package-open', 'href' => BASE_URL . '/apps/operations/consignments.php'];
    } elseif (in_array($roleKey, ['front_desk_admin', 'supervisor_manager'], true)) {
        $navItems[] = ['id' => 'operations', 'label' => 'Live Orders', 'icon' => 'table-2', 'href' => BASE_URL . '/apps/operations/orders-board.php'];
        if ($roleKey === 'front_desk_admin') {
            $navItems[] = ['id' => 'operations-whatsapp', 'label' => 'Meta Comms', 'icon' => 'messages-square', 'href' => BASE_URL . '/apps/operations/whatsapp.php'];
            $navItems[] = ['id' => 'operations-bookkeeping', 'label' => 'Bookkeeping', 'icon' => 'wallet-cards', 'href' => BASE_URL . '/apps/operations/bookkeeping.php'];
            $navItems[] = ['id' => 'operations-bank-processor', 'label' => 'Bank Processor', 'icon' => 'file-spreadsheet', 'href' => BASE_URL . '/apps/operations/bank-statement-processor.php'];
        }
        $navItems[] = ['id' => 'operations-courier', 'label' => 'Courier', 'icon' => 'truck', 'href' => BASE_URL . '/apps/operations/courier.php'];
        $navItems[] = ['id' => 'operations-consignments', 'label' => 'Packing List', 'icon' => 'package-open', 'href' => BASE_URL . '/apps/operations/consignments.php'];
        $navItems[] = ['id' => 'operations-checklists', 'label' => 'Tasks', 'icon' => 'list-checks', 'href' => BASE_URL . '/apps/operations/checklists.php'];
        if ($roleKey === 'front_desk_admin') {
            $navItems[] = ['id' => 'operations-errors', 'label' => 'Error Log', 'icon' => 'triangle-alert', 'href' => BASE_URL . '/apps/operations/errors.php'];
        }
    } elseif ($roleKey === 'packer') {
        $navItems[] = ['id' => 'operations', 'label' => 'Live Orders', 'icon' => 'table-2', 'href' => BASE_URL . '/apps/operations/orders-board.php'];
        $navItems[] = ['id' => 'operations-consignments', 'label' => 'Packing List', 'icon' => 'package-open', 'href' => BASE_URL . '/apps/operations/consignments.php'];
        $navItems[] = ['id' => 'operations-courier', 'label' => 'Courier', 'icon' => 'truck', 'href' => BASE_URL . '/apps/operations/courier.php'];
        $navItems[] = ['id' => 'operations-checklists', 'label' => 'Tasks', 'icon' => 'list-checks', 'href' => BASE_URL . '/apps/operations/checklists.php'];
        $navItems[] = ['id' => 'operations-barcode', 'label' => 'Barcode Soon', 'icon' => 'scan-barcode', 'href' => BASE_URL . '/apps/operations/barcode.php'];
    }

    if ($roleKey !== 'owner_admin') {
        $navItems[] = ['id' => 'hr-portal', 'label' => 'HR Portal', 'icon' => 'shield-check', 'href' => BASE_URL . '/apps/hr-portal/index.php'];
    }
}
?>
<aside class="sidebar" id="portal-sidebar" aria-label="Portal navigation">
    <button class="sidebar-collapse-toggle" type="button" aria-label="Collapse sidebar" aria-pressed="false" data-sidebar-collapse>
        <i data-lucide="panel-left-close"></i>
        <span>Collapse</span>
    </button>
    <nav>
        <?php foreach ($navItems as $item): ?>
            <?php $isActive = $activeApp === 'kpi' ? (($item['tab'] ?? null) === $kpiTab) : ($activeApp === $item['id']); ?>
            <a class="<?= $isActive ? 'active' : '' ?>" href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>">
                <i data-lucide="<?= htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
                <span><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
</aside>
