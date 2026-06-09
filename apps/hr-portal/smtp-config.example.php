<?php
// Copy this file to smtp-config.php and fill in the same SMTP details used by WordPress.
// Keep smtp-config.php private and do not share it publicly.

return [
    'host' => 'mail.hambelelaorganic.com',
    'port' => 587,
    'encryption' => 'tls', // tls, ssl, or none
    'username' => 'hr@hambelelaorganic.com',
    'password' => 'PASTE_SMTP_PASSWORD_HERE',
    'from_email' => 'hr@hambelelaorganic.com',
    'from_name' => 'Hambelela Organic HR',
    'reply_to' => 'victoriatoivo@gmail.com',
];
