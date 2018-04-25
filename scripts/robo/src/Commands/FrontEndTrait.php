<?php

namespace Ballast\Commands;

/**
 * Reusable methods for checking front end build status flags.
 *
 * @package Ballast\Commands
 */
trait FrontEndTrait {

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
