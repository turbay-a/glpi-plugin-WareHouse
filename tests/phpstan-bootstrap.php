<?php

/**
 * Bootstrap used only by phpstan (see phpstan.neon) so that GLPI core
 * classes/constants/functions are known when analysing the plugin.
 * Does not boot a full GLPI kernel (no DB/session), so anything relying on
 * runtime state (e.g. $CFG_GLPI content) is out of phpstan's reach anyway.
 */

$glpi_root = '/var/www/html/glpi';

require_once $glpi_root . '/vendor/autoload.php';
require_once $glpi_root . '/src/autoload/constants.php';
require_once $glpi_root . '/src/autoload/misc-functions.php';
require_once $glpi_root . '/src/autoload/dbutils-aliases.php';
require_once $glpi_root . '/src/autoload/i18n.php';
