<?php

namespace Ballast\Commands;

use Robo\Exception\TaskExitException;
use Robo\Result;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reusable methods for interacting with Docker Machine flags.
 *
 * Only usable within classes extending \Robo\Tasks.
 *
 * phpcs:disable Drupal.Commenting.FunctionComment.ParamMissingDefinition
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
   *
   * @param \Symfony\Component\Console\Style\SymfonyStyle $io
   *   Injected IO object.
   *
   * @return string
   *   The correct docker-machine config string.
   */
  public function getDockerMachineConfig(SymfonyStyle $io) {
    if (!isset($this->dockerConfig)) {
      $result = $this->taskExec('docker-machine config dp-docker')
        ->printOutput(FALSE)
        ->printMetadata(FALSE)
        ->run();
      $io->newLine();
      if ($result instanceof Result && $result->wasSuccessful()) {
        $this->dockerConfig = str_replace(["\r", "\n"], ' ',
          $result->getMessage());
        // Workaround for docker-compose:1.20:
        $this->dockerConfig = str_replace('-H=', '--host ',
          $this->dockerConfig);
      }
      else {
        $io->error('Unable to connect to docker machine.');
        throw new TaskExitException('Unable to connect to docker machine.');
      }
    }
    return $this->dockerConfig;
  }

}
