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
            $extraStylesheets = [['path' => 'assets/css/delivery-portal.css', 'version' => (string) filemtime(BASE_PATH.'/assets/css/delivery-portal.css')]];
            ob_start();
            require BASE_PATH.'/shared/header.php';
            require BASE_PATH.'/shared/sidebar.php';
            $shell = (string) ob_get_clean();
            $shell = preg_replace('~<body([^>]*)>~', '<body$1'.$body[1].'>', $shell, 1);
            ob_start();
            require BASE_PATH.'/shared/footer.php';
            $footer = (string) ob_get_clean();
            echo $shell.'<div class="workspace delivery-content">'.$body[2].'</div>'.$footer;
        });
    }
}
