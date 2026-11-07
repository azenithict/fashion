<?php
declare(strict_types=1);
// Copy outside the public web root to /home/isp85/fashion-private/config.php.
// Set ZENITH_CONFIG to its absolute path, or use backend/bin tools --config path.
return [
    'environment' => 'production',
    'public_origin' => 'https://fashion.azenith.ca',
    'db' => ['dsn' => 'mysql:host=localhost;dbname=isp85_fashion;charset=utf8mb4', 'user' => '', 'password' => ''],
    'storage_path' => '/home/isp85/fashion-private/storage',
    'session_path' => '/home/isp85/fashion-private/sessions',
    'session_name' => '__Host-zenith',
    'session_secure' => true,
    'session_idle_seconds' => 1800,
    'session_absolute_seconds' => 43200,
    'manual_payments_enabled' => false,
    'manual_payment_approval_reference' => '',
    'stripe_secret_key' => '',
    'stripe_webhook_secret' => '',
    'merchant' => ['legal_name'=>'', 'address'=>'', 'tax_registration'=>'', 'invoice_approval_reference'=>''],
    'pricing_min_margin_bps' => null,
    'pricing_approval_reference' => '',
    // Authenticated cPanel SMTP, or owner-approved local authenticated mail handoff.
    'mail' => ['transport' => 'smtp', 'host' => '', 'port' => 587, 'encryption' => 'tls', 'username' => '', 'password' => '', 'from' => '', 'from_name' => 'A Zenith Fashion'],
];
