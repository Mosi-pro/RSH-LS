<?php
/**
 * RSH-LS – Einstiegspunkt im Projekt-Root.
 * Für Hosting-Umgebungen, deren Document Root direkt auf dieses
 * Verzeichnis zeigt (statt auf /public).
 */
require_once __DIR__ . '/includes/bootstrap.php';

if (is_logged_in()) {
    redirect(home_path());
}
redirect('public/login.php');
