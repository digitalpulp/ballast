<?php

namespace Ballast\Commands;

use Robo\Tasks;
use Robo\Result;
use Robo\Contract\VerbosityThresholdInterface;
use Ballast\Utilities\Config;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Robo command that manages rebuilding from a remote environment.
 *
 * phpcs:disable Drupal.Commenting.FunctionComment.ParamMissingDefinition
 *
 * @package Ballast\Commands
 */
class RemoteRebuildCommands extends Tasks {

  use DockerMachineTrait;

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
   * @param array $options
   *   An array of command line options.
   *
   * @aliases rebuild
   *
   * @option no-update Skip database updates and configuration import
   * @option no-compile Skip theme compilation
   */
  public function rebuildSite(SymfonyStyle $io, $environment = 'dev', array $options = [
    'no-update' => FALSE,
    'no-compile' => FALSE,
  ]) {
    $this->setInitialConditions($io);
    $target = $this->config->get('site_alias_name') . '.' . $environment;
    if ($this->getRemoteDump($io, $target)) {
      $io->text('Remote database dumped.');
      // The following methods throw a \RuntimeException on failure.
      $this->getImport($io);
      if (!$options['no-update']) {
        $this->getUpdate($io);
      }
      if (!$options['no-compile']) {
        $this->getRebuiltTheme($io);
      }
      $io->success("Local site rebuilt from $environment");
    }
    else {
      $io->error('The db dump from the remote system failed to complete');
    }
  }

  /**
   * Rebuilds the local site from a remote Pantheon server.
   *
   * @param string $environment
   *   The environment suffix for the drush alias.
   */
  public function rebuildPantheon(SymfonyStyle $io, $environment = 'dev') {
    $this->setInitialConditions($io);
    $target = $this->config->get('site_alias_name') . '.' . $environment;
    if ($this->getRemotePantheonDump($io, $target)) {
      $io->text('Remote database dumped.');
      // The following methods throw a \RuntimeException on failure.
      $this->getImport($io);
      $this->getUpdate($io);
      $this->getRebuiltTheme($io);
      $io->success("Local site rebuilt from $environment");
    }
    else {
      $io->error('The db dump from the remote system failed to complete');
    }
  }

  /**
   * Initialize parameters and services.
   *
   * @param \Symfony\Component\Console\Style\SymfonyStyle $io
   *   Injected IO object for setInitialConditions.
   */
  protected function setInitialConditions(SymfonyStyle $io) {
    $this->setConfig();
    $this->dockerFlags = '';
    switch (php_uname('s')) {
      case 'Darwin':
        $this->dockerFlags = $this->getDockerMachineConfig($io);
        break;

      default:
        $io->error("Unable to determine your operating system.");
    }
  }

  /**
   * Dump the remote database from the host, avoids messing with ssh keys.
   *
   * @param \Symfony\Component\Console\Style\SymfonyStyle $io
   *   Injected IO object for getRemoteDump.
   * @param string $target
   *   The full drush alias of the target remote.
   *
   * @return bool
   *   Success of the dump.
   */
  protected function getRemoteDump(SymfonyStyle $io, $target) {
    $root = $this->config->getProjectRoot();
    $io->text('Dumping remote database');
    $dumpRemote = $this->collectionBuilder();
    $dumpRemote->addTask(
      $this->taskExec("$root/vendor/bin/drush --alias-path='$root/drush/sites' @$target sql-dump --gzip --result-file=/tmp/target.sql")
        ->printMetadata(FALSE)
        ->printOutput(TRUE)
    );
    $dumpRemote->addTask(
      $this->taskExec("$root/vendor/bin/drush --alias-path='$root/drush/sites' rsync -y @$target:/tmp/target.sql.gz $root/tmp/")
        ->printMetadata(FALSE)
        ->printOutput(TRUE)
    );
    $dumpRemote->addTask(
      $this->taskExec("gunzip $root/tmp/target.sql.gz -c > $root/target.sql")
        ->printMetadata(FALSE)
        ->printOutput(TRUE)
    );
    $dumpRemote->addTask(
      $this->taskDeleteDir("$root/tmp/")
    );
    $result = $dumpRemote->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_DEBUG)
      ->run();
    return $result instanceof Result && $result->wasSuccessful();
  }

  /**
   * Dump the remote database from the host, avoids messing with ssh keys.
   *
   * @param \Symfony\Component\Console\Style\SymfonyStyle $io
   *   Injected IO object for getRemotePantheonDump.
   * @param string $target
   *   The full drush alias of the target remote.
   *
   * @return bool
   *   Success of the dump.
   */
  protected function getRemotePantheonDump(SymfonyStyle $io, $target) {
    $root = $this->config->getProjectRoot();
    $io->text('Dumping remote database');
    $dumpRemote = $this->collectionBuilder();
    $dumpRemote->addTask(
      $this->taskExec("terminus backup:create $target --keep-for=1  --element=db")
        ->printMetadata(FALSE)
        ->printOutput(FALSE)
    );
    $dumpRemote->addTask(
      $this->taskExec("terminus backup:get $target --element=db --to=$root/database.sql.gz")
        ->printMetadata(FALSE)
        ->printOutput(FALSE)
    );
    $dumpRemote->addTask(
      $this->taskExec("gunzip -c $root/database.sql.gz > $root/target.sql")
        ->printMetadata(FALSE)
        ->printOutput(FALSE)
    );
    $dumpRemote->addTask(
      $this->taskFilesystemStack()->remove("$root/database.sql.gz")
    );
    $result = $dumpRemote->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_DEBUG)
      ->run();
    return $result instanceof Result && $result->wasSuccessful();
  }

  /**
   * Import the dump into the docker managed database.
   *
   * @param \Symfony\Component\Console\Style\SymfonyStyle $io
   *   Injected IO object getImport.
   */
  protected function getImport(SymfonyStyle $io) {
    $io->newLine();
    $io->text('Loading remote dump to local database.');
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
      $io->text('Remote database loaded.');
    }
    else {
      $io->error('The db dump from the remote system failed to load.');
      $io->newLine();
      $io->text($loadResult->getMessage());
      throw new \RuntimeException();
    }
  }

  /**
   * Run update hooks and import local config.
   *
   * @param \Symfony\Component\Console\Style\SymfonyStyle $io
   *   Injected IO object getUpdate.
   */
  protected function getUpdate(SymfonyStyle $io) {
    $io->newLine();
    $io->text('Running database updates and importing config.');
    $updateResult = $this->taskExec("docker-compose $this->dockerFlags exec cli drush -y updb")
      ->printMetadata(FALSE)
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_NORMAL)
      ->run();
    $io->newLine();
    $io->text('Rebuilding cache before importing config to enable any overrides.');
    $this->taskExec("docker-compose $this->dockerFlags exec -T cli drush -y cr")
      ->printMetadata(FALSE)
      ->printOutput(FALSE)
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_DEBUG)
      ->run();
    $updateResult->merge($this->taskExec("docker-compose $this->dockerFlags exec cli drush -y cim")
      ->printMetadata(FALSE)
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_NORMAL)
      ->run());
    $io->newLine();
    $io->text('Rebuilding cache after importing config.');
    $this->taskExec("docker-compose $this->dockerFlags exec -T cli drush -y cr")
      ->printMetadata(FALSE)
      ->printOutput(FALSE)
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_DEBUG)
      ->run();
    if (!($updateResult instanceof Result && $updateResult->wasSuccessful())) {
      $io->error('Database updates and/or config imports failed to load.');
      $io->newLine();
      throw new \RuntimeException();
    }
  }

  /**
   * Rebuild the theme.
   *
   * @param \Symfony\Component\Console\Style\SymfonyStyle $io
   *   Injected IO object for getRebuiltTheme.
   */
  protected function getRebuiltTheme(SymfonyStyle $io) {
    $io->newLine();
    $io->text('Building the theme.');
    $gulpResult = $this->taskExec("docker-compose $this->dockerFlags exec -T front-end node_modules/.bin/gulp build")
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_DEBUG)
      ->run();
    if ($gulpResult instanceof Result && $gulpResult->wasSuccessful()) {
      $io->text('Theme compiled');
    }
    else {
      $io->error('The theme failed to compile.');
      $io->newLine();
      $io->text($gulpResult->getMessage());
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
