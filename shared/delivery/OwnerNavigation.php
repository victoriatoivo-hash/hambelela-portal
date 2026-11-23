<?php
declare(strict_types=1);
namespace Hambelela\Delivery;

/** Presentation/routing only. Each controller retains its own owner authorization. */
final class OwnerNavigation
{
    public static function redirectLegacy(string $page): bool
    {
        if (strpos((string)($_SERVER['SCRIPT_NAME']??''), '/apps/operations/')===false) return false;
        $query=http_build_query($_GET, '', '&', PHP_QUERY_RFC3986);
        header('Location: '.rtrim(BASE_URL,'/').'/apps/delivery/'.$page.($query!==''?'?'.$query:''), true, 302);
        return true;
    }

    public static function styles(string $page): array
    {
        $styles=[];
        foreach(['ess-dashboard','driver-admin-'.$page,'delivery-admin'] as $name){
            $path='assets/css/'.$name.'.css';
            $styles[]=['path'=>$path,'version'=>(string)filemtime(BASE_PATH.'/'.$path)];
        }
        return $styles;
    }

    public static function render(string $current): void
    {
        $base=htmlspecialchars(rtrim(BASE_URL,'/'),ENT_QUOTES,'UTF-8');
        echo '<nav class="delivery-admin-nav" aria-label="Delivery workspace">';
        foreach(['driver-operations.php'=>'Driver Operations','workspace.php'=>'Front Operations','tedlaser.php'=>'Partner Deliveries','accounting.php'=>'Delivery Accounting'] as $page=>$label){
            echo '<a href="'.$base.'/apps/delivery/'.$page.'"'.($page===$current?' aria-current="page"':'').'>'.$label.'</a>';
        }
        echo '</nav>';
        echo '<script defer src="'.$base.'/assets/js/delivery-admin.js?v='.filemtime(BASE_PATH.'/assets/js/delivery-admin.js').'"></script>';
    }
}
