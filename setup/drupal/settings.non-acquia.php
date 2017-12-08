<?php

/**
 * @file
 * This file holds common settings for all non-Acquia servers.
 *
 * It is only included outside Acquia servers.
 */

// Staging key is deprecated.  See: https://www.drupal.org/node/2574957
// This is the default - same as settings.local.php
// Get the path to the parent of docroot.
$dir = dirname(DRUPAL_ROOT);
$config_directories[CONFIG_SYNC_DIRECTORY] = $dir . '/config';


if (isset($_ENV['PROBO_ENVIRONMENT'])) {
  $settings['trusted_host_patterns'] = array(
    '^localhost$',
    '^.+\.probo\.build$',
  );
  if (isset($_ENV['SRC_DIR'])) {
    $private_path = $_ENV['SRC_DIR'] . '/files-private';
    $settings['file_private_path'] = $private_path;
    $settings['fast404_exts'] = '/^(?!robots)^(?!' . preg_quote($private_path, '/') . ').*\.(txt|png|gif|jpe?g|css|js|ico|swf|flv|cgi|bat|pl|dll|exe|asp)$/i';
  }
}
