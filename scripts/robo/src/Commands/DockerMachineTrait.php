<?php

namespace Ballast\Commands;

use Robo\Result;

/**
 * Reusable methods for interacting with Docker Machine flags.
 *
 * Only usable within classes extending \Robo\Tasks.
 *
 * @package Ballast\Commands
 */
trait DockerMachineTrait {

  /**
   * Docker Machine generated config string.
   *
   * @var string
   */
  protected $dockerConfig;

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

}
