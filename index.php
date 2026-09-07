<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
redirect(current_user() ? 'pages/dashboard.php' : 'login.php');
