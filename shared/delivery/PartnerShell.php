<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
final class PartnerShell
{
    public static function begin(array $actor):void
    {
        ob_start();register_shutdown_function(static function()use($actor):void{
            $html=(string)ob_get_clean();$base=htmlspecialchars(BASE_URL,ENT_QUOTES);$links='';
            foreach(['delivery-core','delivery-controls','delivery-workspace'] as $css)$links.='<link rel="stylesheet" href="'.$base.'/assets/css/'.$css.'.css?v='.filemtime(BASE_PATH.'/assets/css/'.$css.'.css').'">';
            $html=str_replace('</head>',$links.'</head>',$html);
            // Local SVG equivalents keep the shared card icons under the partner's self-only CSP.
            $icons=[
                'bike'=>'<circle cx="5.5" cy="17.5" r="3.5"/><circle cx="18.5" cy="17.5" r="3.5"/><path d="m15 6 2 3h3M12 17.5l-3-5 4-4 3 4h3M10 5h1"/><circle cx="16" cy="3" r="1"/>',
                'boxes'=>'<path d="m12 3 7 4-7 4-7-4 7-4Zm-7 4v8l7 4 7-4V7M12 11v8M3 11l2-1M19 10l2 1v8l-7 4-2-1M3 11v8l7 4 2-1"/>',
                'wallet'=>'<path d="M20 8V5a2 2 0 0 0-2-2H5a3 3 0 0 0 0 6h15v11H5a3 3 0 0 1-3-3V6M20 12h-5v5h5"/><path d="M16 14.5h.01"/>',
            ];
            foreach($icons as $name=>$paths)$html=str_replace('<i data-lucide="'.$name.'"></i>','<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$paths.'</svg>',$html);
            $nav='<header class="delivery-main"><nav class="actions" aria-label="Partner workspace">';
            if(($actor['role']??'')==='partner_admin')$nav.='<a class="secondary" href="workspace.php">Delivery workspace</a>';
            $nav.='<a class="secondary" href="index.php">Tedlaser deliveries</a><a class="secondary" href="password.php">My account</a><form method="post" action="logout.php"><input type="hidden" name="csrf" value="'.htmlspecialchars($_SESSION['csrf'],ENT_QUOTES).'"><button class="secondary">Sign out</button></form></nav></header>';
            echo preg_replace_callback('~<body([^>]*)>~',static function($m)use($nav){return '<body'.$m[1].(strpos($m[1],'data-delivery-ui')===false?' data-delivery-ui':'').'>'.$nav;},$html,1);
        });
    }
}
