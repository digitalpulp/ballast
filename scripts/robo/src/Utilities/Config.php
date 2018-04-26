<?php

namespace Ballast\Utilities;

use Symfony\Component\Yaml\Yaml;
use DrupalFinder\DrupalFinder;

/**
 * Configuration utility singleton.
 *
 * @package Ballast\Utilities
 */
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
   * Get the path to the Drupal root directory.
   *
   * @return string
   *   The drupal root path.
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
   *   The project root path.
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
   *   The value.
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
