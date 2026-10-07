<?php
declare(strict_types=1);

namespace Hambelela\Delivery;

final class PortalShell
{
    public static function begin(): void
    {
        ob_start();
        register_shutdown_function(static function (): void {
            $html = (string) ob_get_clean();
            if (!preg_match('~<body([^>]*)>(.*)</body>~s', $html, $body)) { echo $html; return; }
            preg_match('~<title>(.*?)</title>~s', $html, $title);
            $pageTitle = html_entity_decode($title[1] ?? 'Delivery · Hambelela', ENT_QUOTES, 'UTF-8');
            $activeApp = 'delivery';
            $isEssDashboard = true;
            $pageUsesPortalSidebar = false;
            require_once BASE_PATH.'/shared/ess-navigation.php';
            $essShellApps = ess_shell_apps();
            $essActiveModule = 'Delivery';
            $essHeadingPartial = BASE_PATH.'/shared/delivery/PortalHeading.php';
            $extraStylesheets = [
                ['path' => 'assets/css/ess-dashboard.css', 'version' => (string) filemtime(BASE_PATH.'/assets/css/ess-dashboard.css')],
                ['path' => 'assets/css/delivery-portal.css', 'version' => (string) filemtime(BASE_PATH.'/assets/css/delivery-portal.css')],
                ['path' => 'assets/css/delivery-layout.css', 'version' => (string) filemtime(BASE_PATH.'/assets/css/delivery-layout.css')],
            ];
            ob_start();
            require BASE_PATH.'/shared/header.php';
            require BASE_PATH.'/shared/ess-sidebar.php';
            $shell = (string) ob_get_clean();
            $shell = preg_replace('~<body([^>]*)>~', '<body$1'.$body[1].'>', $shell, 1);
            ob_start();
            require BASE_PATH.'/shared/ess-topbar.php';
            $topbar = (string) ob_get_clean();
            ob_start();
            require BASE_PATH.'/shared/ess-mobile-navigation.php';
            echo '<script defer src="'.htmlspecialchars(BASE_URL, ENT_QUOTES).'/assets/js/ess-dashboard.js?v='.filemtime(BASE_PATH.'/assets/js/ess-dashboard.js').'"></script>';
            require BASE_PATH.'/shared/footer.php';
            $footer = (string) ob_get_clean();
            $content = str_replace(['<main class="delivery-main">', '</main>'], ['<section class="delivery-main">', '</section>'], $body[2]);
            echo $shell.'<main id="ess-main" class="workspace ess-dashboard-main">'.$topbar.'<div class="delivery-content">'.$content.'</div></main>'.$footer;
        });
    }
}
