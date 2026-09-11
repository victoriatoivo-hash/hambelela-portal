<?php
declare(strict_types=1);

// Owner-dashboard navigation only. Destinations mirror the existing module launchers.
function ess_navigation_children(string $name): array
{
    $groups = [
        'Cost Workbook' => ['cost-manager', [
            'size-conversions.php'=>'Size Conversions', 'supplier-invoices.php'=>'Supplier Invoices',
            'transport-costs.php'=>'Transport Costs', 'packaging-costs.php'=>'Packaging Costs',
            'landed-product-costs.php'=>'Landed Product Costs', 'product-pricing.php'=>'Product Pricing',
            'formulations.php'=>'Formulation Costing', 'wholesale-pricing.php'=>'Wholesale Pricing',
            'profitability-report.php'=>'Profitability Report',
        ]],
        'Accounts' => ['accounts', [
            'input-vat.php'=>'Input VAT', 'output-vat.php'=>'Output VAT', 'import-vat.php'=>'Import VAT',
            'paye.php'=>'PAYE', 'vat-reconciliation.php'=>'VAT Reconciliation', 'amendments.php'=>'Amendments',
            'sage-reconciliation.php'=>'Sage Posting & Reconciliation', 'asset-register.php'=>'Asset Register',
        ]],
        'Operations' => ['operations', [
            'orders-board.php'=>'Orders Board', 'orders.php'=>'Orders / POS Reports',
            'orders.php?tab=inventory'=>'Inventory', 'bookkeeping.php'=>'Bookkeeping',
            'budget-planning.php'=>'Budgeting', 'bank-statement-processor.php'=>'Bank Statement Processor',
            'consignments.php'=>'Packing List', 'courier.php'=>'Courier Waybills',
            'checklists.php'=>'Task Management', 'errors.php'=>'Error Log', 'barcode.php'=>'Barcode',
            'whatsapp.php'=>'Meta Comms', 'my-account.php'=>'Settings',
        ]],
        'Employee Performance' => ['operations', [
            'reports.php?tab=business-health'=>'Business Health', 'reports.php?tab=employees'=>'Employees',
            'reports.php?tab=business-activity'=>'Business Activity Timeline', 'reports.php?tab=settings'=>'Performance Settings',
        ]],
        'Marketing' => ['marketing', [
            'index.php?view=tasks'=>'Content Tasks', 'index.php?view=calendar'=>'Calendar',
            'index.php?view=social'=>'Social Media', 'index.php?view=reels'=>'Reels & Video',
            'index.php?view=whatsapp'=>'WhatsApp', 'index.php?view=blog'=>'Blog & SEO',
            'index.php?view=newsletter'=>'Newsletter', 'index.php?view=website'=>'Website & Products',
            'index.php?view=campaigns'=>'Campaigns & Ads', 'index.php?view=analytics'=>'Analytics',
            'index.php?view=library'=>'Content Library', 'index.php?view=ideas'=>'Ideas',
            'index.php?view=performance'=>'Performance',
        ]],
    ];
    if (!isset($groups[$name])) return [];
    [$directory, $routes] = $groups[$name];
    $links = [];
    foreach ($routes as $route => $label) $links[] = ['label'=>$label, 'href'=>BASE_URL.'/apps/'.$directory.'/'.$route];
    return $links;
}

function ess_render_navigation(array $apps): void
{
    static $groupId = 0;
    $escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    foreach ($apps as $app) {
        $children = ess_navigation_children($app['name']);
        if ($children) {
            $id = 'ess-subnav-'.(++$groupId);
            echo '<div class="ess-nav-group"><div class="ess-nav-row"><a class="ess-nav-item" href="'.$escape($app['href']).'" aria-label="'.$escape($app['name']).'" title="'.$escape($app['name']).'"><i data-lucide="'.$escape($app['icon']).'" aria-hidden="true"></i><span>'.$escape($app['name']).'</span></a><button type="button" class="ess-subnav-toggle" data-ess-subnav-toggle aria-expanded="false" aria-controls="'.$id.'" aria-label="Toggle '.$escape($app['name']).' submenu" title="Toggle '.$escape($app['name']).' submenu"><i class="ess-nav-chevron" data-lucide="chevron-down" aria-hidden="true"></i></button></div><div class="ess-subnav" id="'.$id.'" hidden>';
            echo '<a href="'.$escape($app['href']).'">'.$escape($app['name']).' overview</a>';
            foreach ($children as $child) echo '<a href="'.$escape($child['href']).'">'.$escape($child['label']).'</a>';
            echo '</div></div>';
        } else {
            echo '<a class="ess-nav-item" href="'.$escape($app['href']).'" aria-label="'.$escape($app['name']).'" title="'.$escape($app['name']).'"><i data-lucide="'.$escape($app['icon']).'" aria-hidden="true"></i><span>'.$escape($app['name']).'</span></a>';
        }
    }
}
