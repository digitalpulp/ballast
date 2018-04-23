<?php
$root_directory = dirname(__FILE__, 3);
require_once $root_directory . '/vendor/autoload.php';

use Consolidation\AnnotatedCommand\CommandFileDiscovery;

$discovery = new CommandFileDiscovery();
$discovery->setSearchPattern('*.php');
$commandClasses = $discovery->discover('CommandSupport', '\Ballast');
$statusCode = \Robo\Robo::run(
  $_SERVER['argv'],
  $commandClasses,
  'Ballast',
  '1.3.0'
);
exit($statusCode);
