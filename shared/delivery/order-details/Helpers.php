<?php
if(!defined('BASE_PATH'))exit;
$h=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$asset=static fn($path)=>$base.'/'.$path.'?v='.filemtime(BASE_PATH.'/'.$path);
$paths=[
 'back'=>'<path d="m15 18-6-6 6-6"/>',
 'bell'=>'<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>',
 'truck'=>'<path d="M2 6h12v11H2zM14 10h4l4 4v3h-8z"/><circle cx="6.5" cy="18" r="2"/><circle cx="18" cy="18" r="2"/>',
 'call'=>'<path d="M22 16.9v3a2 2 0 0 1-2.2 2A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7l.5 3a2 2 0 0 1-.6 2L7.2 10a16 16 0 0 0 6.8 6.8l1.3-1.8a2 2 0 0 1 2-.6l3 .5a2 2 0 0 1 1.7 2Z"/>',
 'message'=>'<path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 9 9 0 0 1-3.8-.9L3 21l1.9-5.7a9 9 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9H13a8.5 8.5 0 0 1 8 8v.5Z"/>',
 'navigate'=>'<path d="m3 11 18-8-8 18-2.5-7.5L3 11Z"/>',
 'home'=>'<path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/>',
 'box'=>'<path d="m12 2 9 5-9 5-9-5 9-5Z"/><path d="M3 7v10l9 5 9-5V7M12 12v10"/>',
 'map'=>'<polygon points="3 6 9 3 15 6 21 3 21 18 15 21 9 18 3 21 3 6"/><path d="M9 3v15M15 6v15"/>',
 'bag'=>'<rect x="3" y="7" width="18" height="14" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 12h18"/>',
 'more'=>'<circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/>',
 'close'=>'<path d="m6 6 12 12M6 18 18 6"/>'
];
$icon=static fn($key)=>'<svg class="ho-driver-dashboard__icon" viewBox="0 0 24 24" aria-hidden="true">'.$paths[$key].'</svg>';
$activeDriverTab='box';
