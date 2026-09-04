<?php
/**
 * RSH-LS – Bootstrap
 * Wird von jeder aufrufbaren Seite als erstes eingebunden.
 */
define('RSH_APP', true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';

start_session();
