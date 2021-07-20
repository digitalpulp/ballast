// Do not remove the following line.
/* Settings added by robo setup:drupal */

$config['system.performance']['fast_404']['exclude_paths'] = '/\/(?:styles)|(?:system\/files)\//';
$config['system.performance']['fast_404']['paths'] = '/\.(?:txt|png|gif|jpe?g|css|js|ico|swf|flv|cgi|bat|pl|dll|exe|asp)$/i';
$config['system.performance']['fast_404']['html'] = '<!DOCTYPE html><html><head><title>404 Not Found</title></head><body><h1>Not Found</h1><p>The requested URL "@path" was not found on this server.</p></body></html>';

// Trusted host patterns are set in dynamically included files below.
$settings['trusted_host_patterns'] = [];

/**
 * By checking $_ENV['AH_SITE_ENVIRONMENT'] we know whether we are on Acquia or
 * not and which Acquia environment.
 */
// Load Acquia specific settings files.
if (isset($_ENV['AH_SITE_ENVIRONMENT'])) {
  // Load shared Acquia settings.
  require __DIR__ . '/settings.acquia.php';
}
// Load settings for local development.
else {
  // Load ddev settings if it exists.
  $ddev_file_path = __DIR__ . '/settings.ddev.php';
  if (file_exists($ddev_file_path)) {
    require $ddev_file_path;
  }

  // Load local settings file if it exists.
  $local_conf_file_path = __DIR__ . '/settings.local.php';
  if (file_exists($local_conf_file_path)) {
    require $local_conf_file_path;
  }
}
