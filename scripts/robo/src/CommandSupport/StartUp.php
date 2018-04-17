<?php

namespace Ballast\CommandSupport;


use Robo\Tasks;
use Robo\Result;
use Ballast\Utilities\Config;


class StartUp extends Tasks {

  /**
   * Config Utility (setter injected).
   *
   * @var \Ballast\Utilities\Config
   */
  protected $config;

  /**
   * Docker support (setter injected).
   *
   * @var \Ballast\CommandSupport\Docker;
   */
  protected $docker;

  /**
   * The config utility object.
   *
   * @param \Ballast\Utilities\Config $config
   */
  public function setConfig(Config $config) {
    $this->config = $config;
  }

  /**
   * The docker support object.
   *
   * @param \Ballast\CommandSupport\Docker $docker
   */
  public function setDocker($docker) {
    $this->docker = $docker;
  }

  /**
   * Mac specific docker boot process.
   */
  public function bootMac() {
    // Boot the Docker Machine.
    $this->io()->title('Start the Ballast Docker Machine.');
    if (!($ip = $this->getDockerMachineIp())) {
      // Set the default to the parent of the project folder.
      $dir = dirname($this->config->getProjectRoot());
      $folder = $this->io()
        ->ask('What is the path to your docker sites folder?',
          $dir);
      $collection = $this->collectionBuilder();
      $collection->addTask(
        $this->taskExec('docker-machine start dp-docker')
          ->printOutput(FALSE)
      );
      $collection->addTask(
        $this->taskExec("docker-machine-nfs dp-docker --shared-folder=$folder")
          ->printOutput(FALSE)
      );
      $result = $collection->run();
    }
    if ($ip || (isset($result) && $result instanceof Result && $result->wasSuccessful())) {
      $this->io()->success('Ballast Docker Machine is ready to host projects.');
    }
  }

  /**
   * Place or update the dns resolver file.
   */
  public function setResolverFile() {
    if ($ip = $this->getDockerMachineIp()) {
      $this->setRoots();
      if (!file_exists('/etc/resolver/dpulp') ||
        strpos(file_get_contents('/etc/resolver/dpulp'), $ip) === FALSE
      ) {
        $collection = $this->collectionBuilder();
        if (file_exists('/etc/resolver/dpulp')) {
          // Clean out the file for a clean start.
          $collection->addTask(
            $this->taskExec('sudo rm /etc/resolver/dpulp')
          );
        }
        $collection->addTask(
          $this->taskExec('cp ' . "$this->projectRoot/setup/dns/dpulp-template $this->projectRoot/setup/dns/dpulp")
        )->rollback(
          $this->taskExec('rm -f' . "$this->projectRoot/setup/dns/dpulp")
        );
        $collection->addTask(
          $this->taskReplaceInFile("$this->projectRoot/setup/dns/dpulp")
            ->from('{docker-dp}')
            ->to($ip)
        );
        if (!file_exists('/etc/resolver')) {
          $collection->addTask(
            $this->taskExec('sudo mkdir /etc/resolver')
          );
        }
        $collection->addTask(
          $this->taskExecStack()
            ->exec("sudo mv $this->projectRoot/setup/dns/dpulp /etc/resolver")
            ->exec('sudo chown root:wheel /etc/resolver/dpulp')
        );
        $collection->run();
      }
    }
    else {
      $this->io()->error('Unable to get an IP address for dp-docker machine.');
    }
  }

  /**
   * Spends max 5 minutes checking if the front-end tools are initialized.
   *
   * @param bool $progress
   *   Should a progress bar display?
   *
   * @return bool
   *   TRUE if the flag file created by the front-end container is found.
   */
  public function getFrontEndStatus($progress = FALSE) {
    // Initialize variables.
    $ready = FALSE;
    $max_rounds = 150;
    $rounds = 0;
    if (isset($this->configuration['site_theme_path'])) {
      $flag_complete = sprintf('%s/%s/%s/INITIALIZED.txt', $this->projectRoot, $this->configuration['site_theme_path'], $this->configuration['site_theme_name']);
    }
    else {
      $flag_complete = sprintf('%s/themes/custom/%s/INITIALIZED.txt', $this->drupalRoot, $this->configuration['site_theme_name']);
    }
    // Sleep until the initialize file is detected or 30 rounds (5 min.) have
    // passed.
    if ($progress) {
      $this->io()->progressStart($max_rounds);
    }
    while (!$ready && $rounds < $max_rounds) {
      sleep(2);
      $ready = file_exists($flag_complete);
      $rounds++;
      if ($progress) {
        $this->io()->progressAdvance();
      }
    }
    if ($progress) {
      $this->io()->progressFinish();
    }
    return $ready;
  }

  /**
   * Utility function to remove flag files placed by entrypoint.sh.
   *
   * These are placed when the front-end container starts up and does its
   * initial work.
   */
  public function setClearFrontEndFlags() {
    $this->setRoots();
    $this->getConfig();
    if (isset($this->configuration['site_theme_path'])) {
      $flag_file = sprintf('%s/%s/%s/INITIALIZED.txt', $this->projectRoot, $this->configuration['site_theme_path'], $this->configuration['site_theme_name']);
      $flag_npm_install = sprintf('%s/%s/%s/BUILDING.txt', $this->projectRoot, $this->configuration['site_theme_path'], $this->configuration['site_theme_name']);
      $flag_gulp_build = sprintf('%s/%s/%s/COMPILING.TXT', $this->projectRoot, $this->configuration['site_theme_path'], $this->configuration['site_theme_name']);
    }
    else {
      $flag_file = sprintf('%s/themes/custom/%s/INITIALIZED.txt', $this->drupalRoot, $this->configuration['site_theme_name']);
      $flag_npm_install = sprintf('%s/themes/custom/%s/BUILDING.txt', $this->drupalRoot, $this->configuration['site_theme_name']);
      $flag_gulp_build = sprintf('%s/themes/custom/%s/COMPILING.TXT', $this->drupalRoot, $this->configuration['site_theme_name']);
    }
    // Remove flags.
    $this->taskFilesystemStack()
      ->remove($flag_file)
      ->remove($flag_npm_install)
      ->remove($flag_gulp_build)
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_DEBUG)
      ->run();
  }

}
