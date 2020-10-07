<?php

namespace Ballast\Commands;

use Ballast\Utilities\Config;
use Robo\Tasks;
use Robo\Result;
use Robo\Contract\VerbosityThresholdInterface;
use UnexpectedValueException;

/**
 * Robo commands that manage docker interactions.
 *
 * @package Ballast\Commands
 */
class DockerCommands extends Tasks {

  use FrontEndTrait, DockerMachineTrait;

  /**
   * Config Utility (setter injected).
   *
   * @var \Ballast\Utilities\Config
   */
  protected $config;

  /**
   * Robo Command that dispatches docker setup tasks by OS.
   *
   * @ingroup setup
   */
  public function dockerInitialize() {
    $this->setConfig();
    switch (php_uname('s')) {
      case 'Darwin':
        $home = getenv('HOME');
        if (!file_exists("$home/.docker/machine/machines/dp-docker")) {
          $this->setDockerMac();
        }
        else {
          $this->io()
            ->success("All set! Ballast Docker Machine config detected at $home/.docker/machine/machines/dp-docker");
        }
        break;

      default:
        $this->io()
          ->error("Unable to determine your operating system.  Manual installation will be required.");
    }
  }

  /**
   * Routes to a machine specific http proxy function.
   */
  public function dockerProxyCreate() {
    $this->setConfig();
    switch (php_uname('s')) {
      case 'Darwin':
        $home = getenv('HOME');
        if (!file_exists("$home/.docker/machine/machines/dp-docker")) {
          $this->io()
            ->error('You must run `composer install` followed by `ahoy harbor` before you run this Drupal site.');
        }
        $this->setDnsProxyMac();
        break;

      default:
        $this->io()
          ->error("Unable to determine your operating system.  Manual dns boot will be required.");
    }
  }

  /**
   * Entry point command to launch the docker-compose process.
   *
   * Routes to a machine specific compose function.
   */
  public function dockerCompose() {
    $this->setConfig();
    $launched = FALSE;
    switch (php_uname('s')) {
      case 'Darwin':
        $home = getenv('HOME');
        $drupalRoot = $this->config->getDrupalRoot();
        if (!file_exists("$home/.docker/machine/machines/dp-docker") || !file_exists("$drupalRoot/core")) {
          $this->io()
            ->error('You must run `composer install` followed by `ahoy harbor` before you run this Drupal site.');
        }
        elseif ($this->getDockerMachineUrl()) {
          // The docker machine is installed and running.
          $launched = $this->setDockerComposeMac();
        }
        else {
          // The docker machine is installed but not running.
          $this->io()
            ->error('You must start the docker service using `ahoy cast-off`');
        }
        break;

      default:
        $this->io()
          ->error("Unable to determine your operating system.  Manual docker startup will be required.");
    }
    if ($launched) {
      $this->io()
        ->text('Please stand by while the front end tools initialize.');
      if ($this->getFrontEndStatus(TRUE)) {
        $this->io()->text('Front end tools are ready.');
        $this->setClearFrontEndFlags();
      }
      else {
        $this->io()
          ->caution('The wait timer expired waiting for front end tools to report readiness.');
      }
      $this->io()
        ->success('The site can now be reached at ' . $this->config->get('site_shortname') . '.dpulp/');
    }
  }

  /**
   * Entry point command for the boot process.
   *
   * Routes to a machine specific boot function.
   *
   * @aliases boot
   */
  public function bootDocker() {
    $this->setConfig();
    switch (php_uname('s')) {
      case 'Darwin':
        $home = getenv('HOME');
        if (!file_exists("$home/.docker/machine/machines/dp-docker") || !file_exists($this->config->getDrupalRoot() . '/core')) {
          $this->io()
            ->error('You must run `composer install` followed by `ahoy harbor` before you run this Drupal site.');
        }
        else {
          $this->setMacBoot();
        }
        break;

      default:
        $this->io()
          ->error("Unable to determine your operating system.  Manual boot will be required.");
    }
  }

  /**
   * Start DNS service to resolve containers.
   */
  public function bootDns() {
    $this->setConfig();
    switch (php_uname('s')) {
      case 'Darwin':
        if ($this->setMacDnsmasq()) {
          /* dnsmasq running - put the mac resolver file in place. */
          $this->setResolverFile();
          $this->io()->success('Ballast DNS service started.');
          if ($this->confirm('Would you also like to launch the site created by this project?')) {
            $this->dockerCompose();
          }
          return;
        }
        $this->io()->error('Unable to create dns container.');
        return;

      default:
        $this->io()
          ->error("Unable to determine your operating system.  DNS not initiated.");
    }
  }

  /**
   * Prints the database connection info for use in SQL clients.
   */
  public function connectSql() {
    $ip = $this->getDockerMachineIp();
    $port = $this->getSqlPort();
    $this->io()->title('Database Info');
    $this->io()->text("The Docker Machine host is: $ip");
    $this->io()->text("Connect to port: $port");
    $this->io()->text("Username, password, and database are all 'drupal'");
    $this->io()->note("Both the ip and port can vary between re-boots");
  }

  /**
   * All the methods that follow are protected helper methods.
   */

  /**
   * Singleton manager for Ballast\Utilities\Config.
   */
  protected function setConfig() {
    if (!$this->config instanceof Config) {
      $this->config = new Config();
    }
  }

  /**
   * Mac specific docker boot process.
   */
  protected function setMacBoot() {
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
        $this->taskExec("docker-machine-nfs dp-docker --mount-opts=\"rw,udp,noacl,async,nolock,vers=3,noatime,actimeo=2\" --shared-folder=$folder")
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
  protected function setResolverFile() {
    $this->setConfig();
    $root = $this->config->getProjectRoot();
    if ($ip = $this->getDockerMachineIp()) {
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
          $this->taskExec('cp ' . "$root/setup/dns/dpulp-template $root/setup/dns/dpulp")
        )->rollback(
          $this->taskExec('rm -f' . "$root/setup/dns/dpulp")
        );
        $collection->addTask(
          $this->taskReplaceInFile("$root/setup/dns/dpulp")
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
            ->exec("sudo mv $root/setup/dns/dpulp /etc/resolver")
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
   * Download Linux kernel: create the virtualbox based VM for Mac containers.
   */
  protected function setDockerMac() {
    $this->setConfig();
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
  protected function getHostIp($dockerIp) {
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
   * Setup the http-proxy service with dns for macOS.
   *
   * @see https://hub.docker.com/r/jwilder/nginx-proxy/
   */
  protected function setDnsProxyMac() {
    $this->setConfig();
    $dockerConfig = $this->getDockerMachineConfig();
    if ($this->getDockerMachineIp()) {
      $this->io()->title('Setup HTTP Proxy and .dp domain resolution.');
      // Boot the DNS service.
      $boot_task = $this->collectionBuilder();
      $boot_task->addTask(
        $this->taskExec("docker $dockerConfig network create proxynet")
      )->rollback(
        $this->taskExec("docker $dockerConfig network prune")
      );
      $command = "docker $dockerConfig run";
      $command .= ' -d -v /var/run/docker.sock:/tmp/docker.sock:ro';
      $command .= ' -p 80:80 --restart always --network proxynet';
      $command .= ' --name http-proxy digitalpulp/nginx-proxy';
      $boot_task->addTask(
        $this->taskExec($command)
      )->rollback(
        $this->taskExec("docker $dockerConfig rm http-proxy")
      );
      $boot_task->addTask(
        $this->taskExec('docker-machine stop dp-docker')
      );
      $result = $boot_task->run();
      if ($result instanceof Result && $result->wasSuccessful()) {
        $this->io()->success('Proxy container is setup.');
      }
    }
    else {
      $this->io()->error('Unable to get an IP address for dp-docker machine.');
    }
  }

  /**
   * Launches the dnsmasq container if it is not running.
   */
  protected function setMacDnsmasq() {
    $this->setConfig();
    $dockerConfig = $this->getDockerMachineConfig();
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
            return TRUE;
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
  protected function setDockerComposeMac() {
    $this->setConfig();
    if (!($ip = $this->getDockerMachineIp())) {
      // The docker machine is not running.
      $this->io()
        ->error('You must start the docker service using `ahoy cast-off`');
      return;
    }
    $dockerConfig = $this->getDockerMachineConfig();
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
  protected function getDockerMachineIp() {
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
  protected function getSqlPort() {
    $this->setConfig();
    // Get the port string.
    $port = NULL;
    $dockerConfig = $this->getDockerMachineConfig();
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
