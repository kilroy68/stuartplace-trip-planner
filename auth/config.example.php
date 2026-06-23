<?php
/**
 * Copy this file to auth/config.php on Hostinger and fill in real values.
 * Do NOT commit auth/config.php; it is ignored by git.
 */
return [
    // Public site origin, no trailing slash.
    'site_url' => 'https://www.stuartplace.net',

    // Create these in Google Cloud Console > APIs & Services > Credentials.
    // Authorized redirect URI must be: https://www.stuartplace.net/auth/callback.php
    'google_client_id' => 'YOUR_GOOGLE_CLIENT_ID.apps.googleusercontent.com',
    'google_client_secret' => 'YOUR_GOOGLE_CLIENT_SECRET',

    // Hostinger MySQL database settings.
    'db_host' => 'localhost',
    'db_name' => 'YOUR_DATABASE_NAME',
    'db_user' => 'YOUR_DATABASE_USER',
    'db_pass' => 'YOUR_DATABASE_PASSWORD',
    'db_charset' => 'utf8mb4',

    // These accounts are always allowed and are seeded/kept active.
    'initial_users' => [
        'david.c.stuart@gmail.com' => 'admin',
        'angelarx@gmail.com' => 'admin',
    ],

    // Change this to a long random string before deploying.
    'session_secret' => 'CHANGE_ME_TO_A_LONG_RANDOM_SECRET',

    // Keep users logged in for 30 days. Sessions are stored outside public_html by default
    // in ../stuartplace-sessions so shared-host PHP garbage collection does not log users out early.
    // A separate secure remember-me token is also stored in MySQL so login survives PHP session cleanup.
    'session_lifetime_days' => 30,
    'session_save_path' => '',

    // Optional: SmugMug API settings for the trip photo sync.
    // Leave blank until you are ready to connect a SmugMug trip gallery.
    'smugmug_api_key' => '',
    'smugmug_api_secret' => '',
    // Paste the API URI or web URL for the trip gallery in the admin photo settings.
];
