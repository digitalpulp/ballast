<?php

namespace Ballast\Commands;

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
trait ProxyTrait {

  /**
   * Helper method for setting up proxy building tasks: allows code reuse.
   *
   * @param \Symfony\Component\Console\Style\SymfonyStyle $io
   *   Injected IO object.
   *
   * @see https://hub.docker.com/r/jwilder/nginx-proxy/
   *
   * @return \Robo\Collection\CollectionBuilder
   *   The basic task collection for launching the http proxy.
   */
  protected function setProxyContainer(SymfonyStyle $io) {
    $flags = $this->dockerFlags ?? '';
    $io->title('Setup HTTP Proxy');
    $boot_task = $this->collectionBuilder();
    $boot_task->addTask(
      $this->taskExec("docker network create proxynet")
    )->rollback(
      $this->taskExec("docker $flags network prune")
    );
    $command = "docker $flags run";
    $command .= ' -d -v /var/run/docker.sock:/tmp/docker.sock:ro';
    $command .= ' -p 80:80 --restart always --network proxynet';
    $command .= ' --name http-proxy digitalpulp/nginx-proxy';
    $boot_task->addTask(
      $this->taskExec($command)
    )->rollback(
      $this->taskExec("docker $flags rm http-proxy")
    );
    return $boot_task;
  }

}
