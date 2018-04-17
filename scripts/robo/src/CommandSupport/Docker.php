<?php

namespace Ballast\CommandSupport;

use Ballast\Utilities\Config;
use Robo\Tasks;
use Robo\Result;
use Robo\Contract\VerbosityThresholdInterface;
use UnexpectedValueException;

class Docker extends Tasks {

  /**
   * Config Utility (setter injected).
   *
   * @var \Ballast\Utilities\Config
   */
  protected $config;

  /**
   * The config utility object.
   *
   * @param \Ballast\Utilities\Config $config
   */
  public function setConfig(Config $config) {
    $this->config = $config;
  }

  /**
   * Download Linux kernel: create the virtualbox based VM for Mac containers.
   */
  public function setupDockerMac() {
    $this->io()->title('Build the dp-docker machine');
    $collection = $this->collectionBuilder();
    $collection->addTask(
      $this->taskExec('docker-machine create -d virtualbox --virtualbox-memory 2048 -virtualbox-no-share dp-docker')
    )->rollback(
      $this->taskExec('docker-machine rm dp-docker')
    );
    $result = $collection->run();
    if ($result instanceof Result && $result->wasSuccessful()) {
      $this->io()->success("Docker machine created.");
    }
    else {
      $this->io()
        ->error("Something went wrong.  Changes have been rolled back.");
    }
  }

  /**
   * Utility function to get the ip of the host machine on the docker net.
   *
   * @param string $dockerIp
   *   The ip address of the docker machine.
   *
   * @return string
   *   The ip address of the host machine.
   */
  public function getHostIp($dockerIp) {
    $ip = '';
    $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    socket_connect($sock, $dockerIp, 53);
    if (!socket_getsockname($sock, $ip)) {
      throw new UnexpectedValueException('Unable to get an ip via socket.');
    }
    socket_close($sock);
    return $ip;
  }

  /**
   * Launches the dnsmasq container if it is not running.
   */
  public function setMacDnsmasq() {
    $dockerConfig = $this->config->getDockerMachineConfig();
    if (!isset($dockerConfig)) {
      $this->io()->error('Unable to connect to docker machine.');
      return;
    }
    $result = $this->taskExec("docker $dockerConfig inspect dnsmasq")
      ->printOutput(FALSE)
      ->printMetadata(FALSE)
      ->run();
    if ($result instanceof Result && $result->wasSuccessful()) {
      $inspection = json_decode($result->getMessage(), 'assoc');
      if (!empty($inspection) && is_array($inspection)) {
        // There should be only one.
        $dnsmasq = reset($inspection);
        if (isset($dnsmasq['State']['Running'])) {
          // The container exists.
          if ($dnsmasq['State']['Running']) {
            // And the container is already running.
            $this->io()->note('DNS service is already running.');
            return;
          }
          // The container is stopped - remove so it is recreated to
          // renew the ip of the docker machine.
          $this->say('Container exists but is stopped.');
          $this->taskExec("docker $dockerConfig rm dnsmasq")
            ->printOutput(FALSE)
            ->printMetadata(FALSE)
            ->run();
        }
      }
    }
    // Either there is no dns container or it has been removed.
    $ip = $this->getDockerMachineIp();
    $command = "docker $dockerConfig run";
    $command .= ' -d --name dnsmasq';
    $command .= " --publish '53535:53/tcp' --publish '53535:53/udp'";
    $command .= ' --cap-add NET_ADMIN  andyshinn/dnsmasq:2.76';
    $command .= " --address=/dpulp/$ip";
    $result = $this->taskExec($command)
      ->printOutput(FALSE)
      ->printMetadata(FALSE)
      ->run();
    return ($result instanceof Result && $result->wasSuccessful());
  }

  /**
   * Mac specific command to start docker-compose services.
   */
  public function dockerComposeMac() {
    if (!($ip = $this->getDockerMachineIp())) {
      // The docker machine is not running.
      $this->io()
        ->error('You must start the docker service using `ahoy cast-off`');
      return;
    }
    $dockerConfig = $this->config->getDockerMachineConfig();
    $root = $this->config->getProjectRoot();
    $collection = $this->collectionBuilder();
    $collection->addTask(
      $this->taskFilesystemStack()
        ->copy("$root/setup/docker/docker-compose-template",
          "$root/setup/docker/docker-compose.yml")
    )->rollback(
      $this->taskFilesystemStack()
        ->remove("$root/setup/docker/docker-compose.yml")
    );
    $collection->addTask(
      $this->taskReplaceInFile("$root/setup/docker/docker-compose.yml")
        ->from('{site_shortname}')
        ->to($this->config->get('site_shortname'))
    );
    $collection->addTask(
      $this->taskReplaceInFile("$root/setup/docker/docker-compose.yml")
        ->from('{site_theme_name}')
        ->to($this->config->get('site_theme_name'))
    );
    $collection->addTask(
      $this->taskReplaceInFile("$root/setup/docker/docker-compose.yml")
        ->from('{host_ip}')
        ->to($this->getHostIp($ip))
    );
    // Move into place or overwrite the docker-compose.yml.
    $collection->addTask(
      $this->taskFilesystemStack()
        ->rename("$root/setup/docker/docker-compose.yml",
          "$root/docker-compose.yml", TRUE)
    );
    $collection->run();
    $command = "docker-compose $dockerConfig up -d ";
    $result = $this->taskExec($command)->run();
    return (isset($result) && $result->wasSuccessful());
  }

  /**
   * Get the ip of the dp-docker machine.
   *
   * @return mixed
   *   The ip address or null if the machine is not running.
   */
  public function getDockerMachineIp() {
    // Get the ip address of the Docker Machine.
    $ip = NULL;
    $result = $this->taskExec('docker-machine ip dp-docker')
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_DEBUG)
      ->printOutput(FALSE)
      ->printMetadata(FALSE)
      ->run();
    $this->io()->newLine();
    if ($result instanceof Result && $result->wasSuccessful()) {
      $ip = $result->getMessage();
    }
    return trim($ip);
  }

  /**
   * Get the url of the dp-docker machine docker daemon.
   *
   * @return mixed
   *   The url or null if the machine is not running.
   */
  public function getDockerMachineUrl() {
    // Get the url of the Docker Machine.
    $url = '';
    $result = $this->taskExec('docker-machine url dp-docker')
      ->printOutput(FALSE)
      ->printMetadata(FALSE)
      ->run();
    $this->io()->newLine();
    if ($result instanceof Result && $result->wasSuccessful()) {
      $url = $result->getMessage();
    }
    return trim($url);
  }

  /**
   * Get the port exposed for sqlServer from the database service.
   *
   * @return mixed
   *   The port or null if it is not running.
   */
  public function getSqlPort() {
    // Get the port string.
    $port = NULL;
    $dockerConfig = $this->config->getDockerMachineConfig();
    $result = $this->taskExec("docker-compose $dockerConfig port database 3306")
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_DEBUG)
      ->printOutput(FALSE)
      ->printMetadata(FALSE)
      ->run();
    if ($result instanceof Result && $result->wasSuccessful()) {
      $raw = $result->getMessage();
      $port = trim(substr($raw, strpos($raw, ':') + 1));
    }
    return $port;
  }

}
