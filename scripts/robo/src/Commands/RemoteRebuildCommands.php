<?php

namespace Ballast\Commands;

use Robo\Tasks;
use Robo\Result;
use Robo\Contract\VerbosityThresholdInterface;
use Ballast\Utilities\Config;

/**
 * Robo command that manages rebuilding from a remote environment.
 *
 * @package Ballast\Commands
 */
class RemoteRebuildCommands extends Tasks {

  /**
   * Config Utility (singleton).
   *
   * @var \Ballast\Utilities\Config
   */
  protected $config;


  /**
   * Contains docker config flags.
   *
   * @var string
   */
  protected $dockerFlags;

  /**
   * Rebuilds the local site from a remote server.
   *
   * @param string $environment
   *   The environment suffix for the drush alias.
   *
   * @aliases rebuild
   */
  public function rebuildSite($environment = 'dev') {
    $this->setInitialConditions();
    $target = $this->config->get('site_alias_name') . '.' . $environment;
    if ($this->getRemoteDump($target)) {
      $this->io()->text('Remote database dumped.');
      // The following methods throw a \RuntimeException on failure.
      $this->getImport();
      $this->getUpdate();
      $this->getRebuiltTheme();
      $this->io()->success("Local site rebuilt from $environment");
    }
    else {
      $this->io()
        ->error('The db dump from the remote system failed to complete');
    }
  }

  /**
   * Initialize parameters and services.
   */
  protected function setInitialConditions() {
    $this->setConfig();
    $this->dockerFlags = '';
    switch (php_uname('s')) {
      case 'Darwin':
        $this->dockerFlags = $this->config->getDockerMachineConfig();
        break;

      default:
        $this->io()
          ->error("Unable to determine your operating system.");
    }
  }

  /**
   * Dump the remote database from the host, avoids messing with ssh keys.
   *
   * @param string $target
   *   The full drush alias of the target remote.
   *
   * @return bool
   *   Success of the dump.
   */
  protected function getRemoteDump($target) {
    $root = $this->config->getProjectRoot();
    $this->io()->text('Dumping remote database');
    $dumpRemote = $this->collectionBuilder();
    $dumpRemote->addTask(
      $this->taskExec("$root/vendor/bin/drush --alias-path='$root/drush/sites' @$target sql-dump --result-file= > $this->projectRoot/target.sql")
        ->printMetadata(FALSE)
        ->printOutput(TRUE)
    );
    $dumpRemote->addTask(
      $this->taskReplaceInFile("$root/target.sql")
        ->regex('~Connection to(.*)closed.~')
        ->to('--')
        ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_DEBUG)
    );
    $result = $dumpRemote->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_DEBUG)
      ->run();
    return $result instanceof Result && $result->wasSuccessful();
  }

  /**
   * Import the dump into the docker managed database.
   */
  protected function getImport() {
    $this->io()->newLine();
    $this->io()->text('Loading remote dump to local database.');
    $loadRemote = $this->collectionBuilder();
    $loadRemote->addTask(
      $this->taskExec("docker-compose $this->dockerFlags exec -T cli drush -y sql-drop || true")
        ->printMetadata(FALSE)
        ->printOutput(FALSE)
    );
    $loadRemote->addTask(
      $this->taskExec("docker-compose $this->dockerFlags exec -T cli drush sql-sync -y @self @self --no-dump --source-dump=/var/www/target.sql")
        ->printMetadata(FALSE)
        ->printOutput(FALSE)
    );
    $loadRemote->addTask(
      $this->taskExec("docker-compose $this->dockerFlags exec -T cli drush sqlsan -y --sanitize-password=dp --sanitize-email=user-%uid@example.com")
        ->printMetadata(FALSE)
        ->printOutput(FALSE)
    );
    $loadResult = $loadRemote->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_DEBUG)
      ->run();
    if ($loadResult instanceof Result && $loadResult->wasSuccessful()) {
      $this->io()->text('Remote database loaded.');
    }
    else {
      $this->io()
        ->error('The db dump from the remote system failed to load.');
      $this->io()->newLine();
      $this->io()->text($loadResult->getMessage());
      throw new \RuntimeException();
    }
  }

  /**
   * Run update hooks and import local config.
   */
  protected function getUpdate() {
    $this->io()->newLine();
    $this->io()->text('Running database updates and importing config.');
    $updateResult = $this->taskExec("docker-compose $this->dockerFlags exec cli drush -y updb")
      ->printMetadata(FALSE)
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_NORMAL)
      ->run();
    $this->io()->newLine();
    $this->io()
      ->text('Rebuilding cache before importing config to enable any overrides.');
    $this->taskExec("docker-compose $this->dockerFlags exec -T cli drush -y cr")
      ->printMetadata(FALSE)
      ->printOutput(FALSE)
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_DEBUG)
      ->run();
    $updateResult->merge($this->taskExec("docker-compose $this->dockerFlags exec cli drush -y cim")
      ->printMetadata(FALSE)
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_NORMAL)
      ->run());
    $this->io()->newLine();
    $this->io()->text('Rebuilding cache after importing config.');
    $this->taskExec("docker-compose $this->dockerFlags exec -T cli drush -y cr")
      ->printMetadata(FALSE)
      ->printOutput(FALSE)
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_DEBUG)
      ->run();
    if (!($updateResult instanceof Result && $updateResult->wasSuccessful())) {
      $this->io()
        ->error('Database updates and/or config imports failed to load.');
      $this->io()->newLine();
      throw new \RuntimeException();
    }
  }

  /**
   * Rebuild the theme.
   */
  protected function getRebuiltTheme() {
    $this->io()->newLine();
    $this->io()->text('Building the theme.');
    $gulpResult = $this->taskExec("docker-compose $this->dockerFlags exec -T front-end node_modules/.bin/gulp build")
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_DEBUG)
      ->run();
    if ($gulpResult instanceof Result && $gulpResult->wasSuccessful()) {
      $this->io()->text('Theme compiled');
    }
    else {
      $this->io()->error('The theme failed to compile.');
      $this->io()->newLine();
      $this->io()->text($gulpResult->getMessage());
      throw new \RuntimeException();
    }
  }

  /**
   * Singleton manager for Ballast\Utilities\Config.
   */
  protected function setConfig() {
    if (!$this->config instanceof Config) {
      $this->config = new Config();
    }
  }

}
