<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if (is_logged_in()) {
    redirect('public/dashboard.php');
}
redirect('public/login.php');
