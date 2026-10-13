<?php
declare(strict_types=1);
if(!defined('BASE_PATH')||!function_exists('user_has_role')||!user_has_role('owner_admin'))return;
?>
<div class="settings-card" id="driver-gps-download">
    <h2>Hambelela Driver GPS</h2>
    <p class="card-sub">Download the Android company-phone app. Driver pairing and tracking permissions remain unchanged.</p>
    <a class="btn" style="display:inline-flex;align-items:center;min-height:40px;padding:0 16px;border-radius:8px;background:#59694A;color:#fff;font:500 12px Jost,sans-serif;text-decoration:none" href="<?=htmlspecialchars(BASE_URL,ENT_QUOTES)?>/apps/delivery/driver/hambelela-driver-gps-1.0.0.apk?v=1.0.2" download>Download Android GPS app</a>
</div>
