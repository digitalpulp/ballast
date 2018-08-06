<?php

/**
 * @file
 * This functions as a front controller for the Robo commands.
 */

// @codingStandardsIgnoreFile

$root_directory = dirname(__FILE__, 3);
require_once $root_directory . '/vendor/autoload.php';
$discovery = new Consolidation\AnnotatedCommand\CommandFileDiscovery();
$commandClasses = $discovery->discover('scripts/robo/src/Commands', '\Ballast\Commands');
$statusCode = \Robo\Robo::run(
  $_SERVER['argv'],
  $commandClasses,
  'Ballast',
  '1.0.9'
);
exit($statusCode);
