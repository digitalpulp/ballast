<?php

/**
 * @file
 * This file holds common settings for all environments on Acquia and is only included on Acquia servers.
 */

use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\DrupalKernel;

// Acquia configuration.
if (file_exists('/var/www/site-php')) {
  require '/var/www/site-php/' . $_ENV['AH_SITE_GROUP'] . '/' . $_ENV['AH_SITE_GROUP'] . '-settings.inc';
}
// Support for private files on Acquia.
// See for details: https://docs.acquia.com/articles/setting-private-file-directory-acquia-cloud
$request = Request::createFromGlobals();
$site_path = DrupalKernel::findSitePath($request);
$private_path = '/mnt/files/' . $_ENV['AH_SITE_GROUP'] . '.' . $_ENV['AH_SITE_ENVIRONMENT'] . '/' . $site_path . '/files-private';
$settings['file_private_path'] = $private_path;
$settings['fast404_exts'] = '/^(?!robots)^(?!' . preg_quote($private_path, '/') . ').*\.(txt|png|gif|jpe?g|css|js|ico|swf|flv|cgi|bat|pl|dll|exe|asp)$/i';
$settings['trusted_host_patterns'][] = '^.+\.prod.acquia-sites.com$';

// Get the path to the parent of docroot.
$dir = dirname(DRUPAL_ROOT);
$config_directories[CONFIG_SYNC_DIRECTORY] = $dir . '/config';
if (isset($config_directories['vcs'])) {
  // Acquia creates an extra config storage directory within the file system.
  // We do not use this directory for reasons of security.
  unset($config_directories['vcs']);
}

// Environment specific settings.
switch ($_ENV['AH_SITE_ENVIRONMENT']) {
  case 'dev':
    $config['config_split.config_split.shield']['status'] = TRUE;
    break;

  case 'test':
    $config['config_split.config_split.dev']['status'] = FALSE;
    $config['config_split.config_split.shield']['status'] = TRUE;
    $config['config_split.config_split.stage']['status'] = TRUE;
    break;

  case 'prod':
//    $config['swiftmailer.transport']['transport'] = 'sendmail';
//    $config['swiftmailer.transport']['sendmail_path'] = '/usr/sbin/sendmail';
//    $config['swiftmailer.transport']['sendmail_mode'] = 'bs';
    $config['config_split.config_split.dev']['status'] = FALSE;
    $config['config_split.config_split.prod']['status'] = TRUE;
    if (PHP_SAPI !== 'cli') {
      $settings['config_readonly'] = TRUE;
    }
    ini_set('display_errors', '0');
    break;

}
