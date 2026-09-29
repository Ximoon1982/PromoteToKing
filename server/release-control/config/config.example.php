<?php
declare(strict_types=1);

return [
    // Recovery-plane access is deliberately narrower than ordinary club-admin access.
    // Host-specific overrides belong in config.local.php and are never release-managed.
    'super_admin_usernames' => ['ximoon'],
];
