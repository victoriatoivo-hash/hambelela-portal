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
                'bike'=>'<circle cx="18.5" cy="17.5" r="3.5"/><circle cx="5.5" cy="17.5" r="3.5"/><circle cx="15" cy="5" r="1"/><path d="M12 17.5V14l-3-3 4-3 2 3h2"/>',
                'boxes'=>'<path d="M2.97 12.92A2 2 0 0 0 2 14.63v3.24a2 2 0 0 0 .97 1.71l3 1.8a2 2 0 0 0 2.06 0L12 19v-5.5l-5-3-4.03 2.42Z"/><path d="m7 16.5-4.74-2.85m4.74 2.85 5-3M7 16.5v5.17M12 13.5V19l3.97 2.38a2 2 0 0 0 2.06 0l3-1.8a2 2 0 0 0 .97-1.71v-3.24a2 2 0 0 0-.97-1.71L17 10.5l-5 3Zm5 3-5-3m5 3 4.74-2.85M17 16.5v5.17M7.97 4.42A2 2 0 0 0 7 6.13v4.37l5 3 5-3V6.13a2 2 0 0 0-.97-1.71l-3-1.8a2 2 0 0 0-2.06 0l-3 1.8ZM12 8 7.26 5.15M12 8l4.74-2.85M12 13.5V8"/>',
                'wallet'=>'<path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/>',
            ];
            foreach($icons as $name=>$paths)$html=str_replace('<i data-lucide="'.$name.'"></i>','<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$paths.'</svg>',$html);
            $nav='<header class="delivery-main"><nav class="actions" aria-label="Partner workspace">';
            if(($actor['role']??'')==='partner_admin')$nav.='<a class="secondary" href="workspace.php">Delivery workspace</a>';
            $nav.='<a class="secondary" href="index.php">Tedlaser deliveries</a><a class="secondary" href="password.php">My account</a><form method="post" action="logout.php"><input type="hidden" name="csrf" value="'.htmlspecialchars($_SESSION['csrf'],ENT_QUOTES).'"><button class="secondary">Sign out</button></form></nav></header>';
            echo preg_replace_callback('~<body([^>]*)>~',static function($m)use($nav){return '<body'.$m[1].(strpos($m[1],'data-delivery-ui')===false?' data-delivery-ui':'').'>'.$nav;},$html,1);
        });
    }
}
