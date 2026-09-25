<?php

/**
 * PHPUnit bootstrap: boots the real GLPI 11 kernel (DB + full class map,
 * including this plugin's own PSR-4 autoloading, wired automatically by
 * GLPI for any active plugin) and opens a session as the standard `glpi`
 * super-admin account, the same way front controllers do.
 *
 * No GLPI-provided PHPUnit scaffolding is used here (composer is not
 * available on this stand -- see README.md "Development"), so every test
 * class talks to the real DB directly and is responsible for cleaning up
 * whatever fixtures it creates.
 */

$glpi_root = '/var/www/html/glpi';

require_once $glpi_root . '/vendor/autoload.php';

$kernel = new Glpi\Kernel\Kernel();
$kernel->boot();

$user = new User();
$user->getFromDBbyName('glpi');

$auth = new Auth();
$auth->user          = $user;
$auth->auth_succeded = true;
$auth->extauth       = 0;
Session::init($auth);

if ((int) ($_SESSION['glpiID'] ?? 0) !== (int) $user->getID()) {
    fwrite(STDERR, "Could not open a session as the 'glpi' user -- aborting test run.\n");
    exit(1);
}
