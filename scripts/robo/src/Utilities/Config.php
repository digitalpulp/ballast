<?php
/**
 * Created by PhpStorm.
 * User: shawnduncan
 * Date: 4/17/18
 * Time: 1:40 PM
 */

namespace Ballast\Utilities;

use Symfony\Component\Yaml\Yaml;
use DrupalFinder\DrupalFinder;


class Config {

  /**
   * Array of config values loaded from /setup/config.yml.
   *
   * @var array
   */
  protected $configuration;

  /**
   * Drupal root path.
   *
   * @var string
   */
  protected $drupalRoot;

  /**
   * Project root path.
   *
   * @var string
   */
  protected $projectRoot;

  /**
   * Docker Machine generated config string.
   *
   * @var string
   */
  protected $dockerConfig;

  /**
   * Get the path to the Drupal root directory.
   *
   * @return string
   */
  public function getDrupalRoot() {
    if (empty($this->drupalRoot)) {
      $this->setRoots();
    }
    return $this->drupalRoot;
  }

  /**
   * Get the path to the project root directory.
   *
   * @return string
   */
  public function getProjectRoot() {
    if (empty($this->projectRoot)) {
      $this->setRoots();
    }
    return $this->projectRoot;
  }

  /**
   * Return a config item if set.
   *
   * @return string
   */
  public function get($key) {
    if (empty($this->configuration)) {
      $this->setConfig();
    }
    if (isset($this->configuration[$key])) {
      return $this->configuration[$key];
    }
    return NULL;
  }

  /**
   * Construct Mac Docker exec command flags.
   */
  public function getDockerMachineConfig() {
    if (!isset($this->dockerConfig)) {
      $result = $this->taskExec('docker-machine config dp-docker')
        ->printOutput(FALSE)
        ->printMetadata(FALSE)
        ->run();
      $this->io()->newLine();
      if ($result instanceof Result && $result->wasSuccessful()) {
        $this->dockerConfig = str_replace(["\r", "\n"], ' ',
          $result->getMessage());
        // Workaround for docker-compose:1.20:
        $this->dockerConfig = str_replace('-H=', '--host ',
          $this->dockerConfig);
      }
    }
    return $this->dockerConfig;
  }

  /**
   * Loads project configuration from yaml.
   */
  protected function setConfig() {
    if (empty($this->configuration)) {
      $this->setRoots();
      try {
        $this->configuration = Yaml::parse(file_get_contents("$this->projectRoot/setup/config.yml"));
      }
      catch (ParseException $e) {
        $this->io()
          ->error(sprintf("Unable to parse the YAML string: %s",
            $e->getMessage()));
      }
    }
  }

  /**
   * Utility function to insure root paths are set.
   */
  protected function setRoots() {
    // Values are set here so one empty is a suffient flag.
    if (empty($this->drupalRoot)) {
      $drupalFinder = new DrupalFinder();
      $drupalFinder->locateRoot(getcwd());
      $this->drupalRoot = $drupalFinder->getDrupalRoot();
      $this->projectRoot = $drupalFinder->getComposerRoot();
    }
  }

}
