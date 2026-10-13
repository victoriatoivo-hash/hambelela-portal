<?php
declare(strict_types=1);

namespace Hambelela\Delivery;

final class PortalShell
{
    public static function begin(bool $accounting = false, bool $operations = false): void
    {
        ob_start();
        register_shutdown_function(static function () use ($accounting, $operations): void {
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
            if ($accounting) {
                $extraStylesheets = [$extraStylesheets[0], ['path' => 'assets/css/delivery-accounting.css', 'version' => (string) filemtime(BASE_PATH.'/assets/css/delivery-accounting.css')]];
            }
            if ($operations) $extraStylesheets[] = ['path'=>'assets/css/delivery-operations.css','version'=>(string)filemtime(BASE_PATH.'/assets/css/delivery-operations.css')];
            $extraStylesheets[] = ['path'=>'assets/css/delivery-controls.css','version'=>(string)filemtime(BASE_PATH.'/assets/css/delivery-controls.css')];
            if(strpos($body[2],'delivery-workspace')!==false)$extraStylesheets[]=['path'=>'assets/css/delivery-workspace.css','version'=>(string)filemtime(BASE_PATH.'/assets/css/delivery-workspace.css')];
            ob_start();
            require BASE_PATH.'/shared/header.php';
            require BASE_PATH.'/shared/ess-sidebar.php';
            $shell = (string) ob_get_clean();
            // Keep the calendar behaviour, but load Delivery's shared calendar theme in the head.
            $shell = preg_replace('~<link[^>]+href="[^"]*/assets/css/portal-date-picker\.css[^"]*"[^>]*>~', '', $shell);
            // Driver install convenience still opens the shared role-aware portal module.
            if((\current_user()['role_key']??'')==='delivery_driver'){
                $shell=preg_replace('~<link rel="manifest"[^>]*>~','<link rel="manifest" href="'.htmlspecialchars(BASE_URL,ENT_QUOTES).'/apps/delivery/driver/manifest.webmanifest">',$shell,1);
            }
            $shell = preg_replace('~<body([^>]*)>~', '<body$1 data-delivery-ui'.$body[1].'>', $shell, 1);
            ob_start();
            require BASE_PATH.'/shared/ess-topbar.php';
            $topbar = (string) ob_get_clean();
            ob_start();
            require BASE_PATH.'/shared/ess-mobile-navigation.php';
            echo '<script defer src="'.htmlspecialchars(BASE_URL, ENT_QUOTES).'/assets/js/ess-dashboard.js?v='.filemtime(BASE_PATH.'/assets/js/ess-dashboard.js').'"></script>';
            require BASE_PATH.'/shared/footer.php';
            $footer = (string) ob_get_clean();
            $content = str_replace(['<main class="delivery-main">', '</main>'], ['<section class="delivery-main">', '</section>'], $body[2]);
            $app=basename((string)($_SERVER['SCRIPT_NAME']??''));
            $names=['workspace.php'=>'Front Operations','accounting.php'=>'Accounting','pricing.php'=>'Delivery Pricing','tedlaser.php'=>'Tedlaser','overview.php'=>'Driver oversight','driver-view.php'=>'Driver'];
            if(isset($names[$app]))$content='<nav aria-label="Breadcrumb" style="display:flex;align-items:center;gap:8px;font:400 11px Jost,sans-serif;margin-bottom:12px"><a style="color:#59694A;min-height:42px;display:inline-flex;align-items:center" href="'.htmlspecialchars(BASE_URL,ENT_QUOTES).'/apps/delivery/index.php">Back to Delivery</a><span aria-hidden="true">›</span><span>'.$names[$app].'</span></nav>'.$content;
            if((\current_user()['role_key']??'')==='delivery_driver')$content.='<script defer src="'.htmlspecialchars(BASE_URL,ENT_QUOTES).'/apps/delivery/driver/pwa.js?v='.filemtime(BASE_PATH.'/apps/delivery/driver/pwa.js').'"></script>';
            echo $shell.'<main id="ess-main" class="workspace ess-dashboard-main">'.$topbar.'<div class="delivery-content'.($accounting?' delivery-accounting':'').'">'.$content.'</div></main>'.$footer;
        });
    }
}
