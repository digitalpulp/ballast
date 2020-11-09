<?php

namespace Ballast\Commands;

use Ballast\Utilities\Config;
use Robo\Tasks;
use Robo\Result;
use Robo\Contract\VerbosityThresholdInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Robo commands that manage docker interactions.
 *
 * phpcs:disable Drupal.Commenting.FunctionComment.ParamMissingDefinition
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
   * Contains docker config flags.
   *
   * @var string
   */
  protected $dockerFlags;

  /**
   * Prepare a docker-machine VM.
   *
   * @ingroup setup
   */
  public function dockerInitialize(SymfonyStyle $io) {
    $this->setConfig();
    switch (php_uname('s')) {
      case 'Darwin':
        $home = getenv('HOME');
        if (!file_exists("$home/.docker/machine/machines/dp-docker")) {
          $this->setDockerMac($io);
        }
        else {
          $io->success("All set! Ballast Docker Machine config detected at $home/.docker/machine/machines/dp-docker");
        }
        break;

      case 'Linux':
        $io->note('This command is not needed for Linux users.');
        break;

      default:
        $io->error("Unable to determine your operating system.  Manual installation will be required.");
    }
  }

  /**
   * Routes to a machine specific http proxy function.
   */
  public function dockerProxyCreate(SymfonyStyle $io) {
    $this->setConfig();
    switch (php_uname('s')) {
      case 'Darwin':
        $home = getenv('HOME');
        if (!file_exists("$home/.docker/machine/machines/dp-docker")) {
          $io->error('You must run `composer install` followed by `ahoy harbor` before you run this Drupal site.');
        }
        $this->setHttpProxyMac($io);
        break;

      case 'Linux':
        $io->note('This command is not needed for Linux users.');
        break;

      default:
        $io->error("Unable to determine your operating system.  Manual dns boot will be required.");
    }
  }

  /**
   * Entry point command to launch the docker-compose process.
   *
   * Routes to a machine specific compose function.
   */
  public function dockerCompose(SymfonyStyle $io) {
    $this->setConfig();
    $launched = FALSE;
    switch (php_uname('s')) {
      case 'Darwin':
        $home = getenv('HOME');
        $drupalRoot = $this->config->getDrupalRoot();
        if (!file_exists("$home/.docker/machine/machines/dp-docker") || !file_exists("$drupalRoot/core")) {
          $io->error('You must run `composer install` followed by `ahoy harbor` before you run this Drupal site.');
        }
        elseif ($this->getDockerMachineUrl($io)) {
          // The docker machine is installed and running.
          $launched = $this->setDockerComposeMac($io);
        }
        else {
          // The docker machine is installed but not running.
          $io->error('You must start the docker service using `ahoy cast-off`');
        }
        break;

      default:
        $io->error("Unable to determine your operating system.  Manual docker startup will be required.");
    }
    if ($launched) {
      $io->text('Please stand by while the front end tools initialize.');
      if ($this->getFrontEndStatus(TRUE)) {
        $io->text('Front end tools are ready.');
        $this->setClearFrontEndFlags();
      }
      else {
        $io->caution('The wait timer expired waiting for front end tools to report readiness.');
      }
      $io->success('The site can now be reached at ' . $this->config->get('site_shortname') . '.test/');
    }
  }

  /**
   * Entry point command for the boot process.
   *
   * Routes to a machine specific boot function.
   *
   * @aliases boot
   */
  public function bootDocker(SymfonyStyle $io) {
    $this->setInitialConditions($io);
    switch (php_uname('s')) {
      case 'Darwin':
        $home = getenv('HOME');
        if (!file_exists("$home/.docker/machine/machines/dp-docker") || !file_exists($this->config->getDrupalRoot() . '/core')) {
          $io->error('You must run `composer install` followed by `ahoy harbor` before you run this Drupal site.');
        }
        else {
          $this->setMacBoot($io);
        }
        break;

      case 'Linux':
        $io->text('Linux runs Docker natively.');
        break;

      default:
        $io->error("Unable to determine your operating system.  Manual boot will be required.");
    }
  }

  /**
   * Start DNS service to resolve containers.
   */
  public function bootDns(SymfonyStyle $io) {
    $this->setInitialConditions($io);
    switch (php_uname('s')) {
      case 'Darwin':
        if ($this->setMacDnsmasq($io)) {
          /* dnsmasq running - put the mac resolver file in place. */
          $this->setResolverFile($io);
          $io->success('Ballast DNS service started.');
          if ($this->confirm('Would you also like to launch the site created by this project?')) {
            $this->dockerCompose($io);
          }
          return;
        }
        $io->error('Unable to create dns container.');
        return;

      default:
        $io->error("Unable to determine your operating system.  DNS not initiated.");
    }
  }

  /**
   * Prints the database connection info for use in SQL clients.
   */
  public function connectSql(SymfonyStyle $io) {
    $this->setInitialConditions($io);
    switch (php_uname('s')) {
      case 'Darwin':
        $this->dockerFlags = $this->getDockerMachineConfig($io);
        $ip = $this->getDockerMachineIp($io);
        break;

      case 'Linux':
        $this->dockerFlags = '';
        $ip = '127.0.0.1';
        break;

      default:
        $io->error("Unable to determine your operating system.");
    }
    $port = $this->getSqlPort();
    $io->title('Database Info');
    $io->text("The database may be reached at: $ip:$port");
    $io->text("Username, password, and database are all 'drupal'");
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

      case 'Linux':
        $this->dockerFlags = '';
        break;

      default:
        $io->error("Unable to determine your operating system.");
    }
  }

  /**
   * Mac specific docker boot process.
   *
   * @param \Symfony\Component\Console\Style\SymfonyStyle $io
   *   Injected IO object.
   */
  protected function setMacBoot(SymfonyStyle $io) {
    // Boot the Docker Machine.
    $io->title('Start the Ballast Docker Machine.');
    if (!($ip = $this->getDockerMachineIp($io))) {
      // Set the default to the parent of the project folder.
      $dir = dirname($this->config->getProjectRoot());
      $folder = $io->ask('What is the path to your docker sites folder?', $dir);
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
      $io->success('Ballast Docker Machine is ready to host projects.');
    }
  }

  /**
   * Place or update the dns resolver file.
   *
   * @param \Symfony\Component\Console\Style\SymfonyStyle $io
   *   Injected IO object.
   */
  protected function setResolverFile(SymfonyStyle $io) {
    $this->setConfig();
    $root = $this->config->getProjectRoot();
    if ($ip = $this->getDockerMachineIp($io)) {
      if (!file_exists('/etc/resolver/test') ||
        strpos(file_get_contents('/etc/resolver/test'), $ip) === FALSE
      ) {
        $collection = $this->collectionBuilder();
        if (file_exists('/etc/resolver/test')) {
          // Clean out the file for a clean start.
          $collection->addTask(
            $this->taskExec('sudo rm /etc/resolver/test')
          );
        }
        $collection->addTask(
          $this->taskExec('cp ' . "$root/setup/dns/test-template $root/setup/dns/test")
        )->rollback(
          $this->taskExec('rm -f' . "$root/setup/dns/test")
        );
        $collection->addTask(
          $this->taskReplaceInFile("$root/setup/dns/test")
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
            ->exec("sudo mv $root/setup/dns/test /etc/resolver")
            ->exec('sudo chown root:wheel /etc/resolver/test')
        );
        $collection->run();
      }
    }
    else {
      $io->error('Unable to get an IP address for dp-docker machine.');
    }
  }

  /**
   * Download Linux kernel: create the virtualbox based VM for Mac containers.
   *
   * @param \Symfony\Component\Console\Style\SymfonyStyle $io
   *   Injected IO object.
   */
  protected function setDockerMac(SymfonyStyle $io) {
    $this->setConfig();
    $io->title('Build the dp-docker machine');
    $collection = $this->collectionBuilder();
    $collection->addTask(
      $this->taskExec('docker-machine create -d virtualbox --virtualbox-memory 2048 -virtualbox-no-share dp-docker')
    )->rollback(
      $this->taskExec('docker-machine rm dp-docker')
    );
    $result = $collection->run();
    if ($result instanceof Result && $result->wasSuccessful()) {
      $io->success("Docker machine created.");
    }
    else {
      $io->error("Something went wrong.  Changes have been rolled back.");
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
      throw new \UnexpectedValueException('Unable to get an ip via socket.');
    }
    socket_close($sock);
    return $ip;
  }

  /**
   * Setup the http-proxy service with dns for macOS.
   *
   * @param \Symfony\Component\Console\Style\SymfonyStyle $io
   *   Injected IO object.
   */
  protected function setHttpProxyMac(SymfonyStyle $io) {
    if ($this->getDockerMachineIp($io)) {
      $boot_task = $this->setProxyContainer($io);
      $boot_task->addTask(
        $this->taskExec('docker-machine stop dp-docker')
      );
      $result = $boot_task->run();
      if ($result instanceof Result && $result->wasSuccessful()) {
        $io->success('Proxy container is setup.');
      }
    }
    else {
      $io->error('Unable to get an IP address for dp-docker machine.');
    }
  }

  /**
   * Setup the http-proxy service with dns for macOS.
   *
   * @param \Symfony\Component\Console\Style\SymfonyStyle $io
   *   Injected IO object.
   *
   * @see https://hub.docker.com/r/jwilder/nginx-proxy/
   */
  protected function setDnsProxyLinux(SymfonyStyle $io) {
    $boot_task = $this->setProxyContainer($io);
    $result = $boot_task->run();
    if ($result instanceof Result && $result->wasSuccessful()) {
      $io->success('Proxy container is setup.');
    }
  }

  /**
   * Launches the dnsmasq container if it is not running.
   *
   * @param \Symfony\Component\Console\Style\SymfonyStyle $io
   *   Injected IO object.
   *
   * @return bool
   *   Indicates success.
   */
  protected function setMacDnsmasq(SymfonyStyle $io) {
    $this->setInitialConditions($io);
    $result = $this->taskExec("docker $this->dockerFlags inspect dnsmasq")
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
            $io->note('DNS service is already running.');
            return TRUE;
          }
          // The container is stopped - remove so it is recreated to
          // renew the ip of the docker machine.
          $this->say('Container exists but is stopped.');
          $this->taskExec("docker $this->dockerFlags rm dnsmasq")
            ->printOutput(FALSE)
            ->printMetadata(FALSE)
            ->run();
        }
      }
    }
    // Either there is no dns container or it has been removed.
    $ip = $this->getDockerMachineIp($io);
    $command = "docker $this->dockerFlags run";
    $command .= ' -d --name dnsmasq';
    $command .= " --publish '53535:53/tcp' --publish '53535:53/udp'";
    $command .= ' --cap-add NET_ADMIN  andyshinn/dnsmasq:2.81';
    $command .= " --address=/test/$ip";
    $result = $this->taskExec($command)
      ->printOutput(FALSE)
      ->printMetadata(FALSE)
      ->run();
    return ($result instanceof Result && $result->wasSuccessful());
  }

  /**
   * Helpful dns instructions for Linux.
   *
   * @param \Symfony\Component\Console\Style\SymfonyStyle $io
   *   Injected IO object.
   */
  protected function setLinuxDnsInstructions(SymfonyStyle $io) {
    $io->note([
      'Since Docker containers run natively in Linux, while Ballast is running',
      'all the hosted sites are served by a proxy to port 80.  For easy',
      'resolution on our *.test subdomain, Linux users should setup a local',
      'resolver that sends all *.test requests to the loopback address.',
      'Further instructions with helpful urls are in the README.md file.',
    ]);
  }

  /**
   * Mac specific command to start docker-compose services.
   *
   * @param \Symfony\Component\Console\Style\SymfonyStyle $io
   *   Injected IO object.
   *
   * @return bool
   *   Indicates success.
   */
  protected function setDockerComposeMac(SymfonyStyle $io) {
    $this->setConfig();
    if (!($ip = $this->getDockerMachineIp($io))) {
      // The docker machine is not running.
      $io->error('You must start the docker service using `ahoy cast-off`');
      return FALSE;
    }
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
    $command = "docker-compose $this->dockerFlags up -d ";
    $result = $this->taskExec($command)->run();
    return (isset($result) && $result->wasSuccessful());
  }

  /**
   * Get the ip of the dp-docker machine.
   *
   * @param \Symfony\Component\Console\Style\SymfonyStyle $io
   *   Injected IO object.
   *
   * @return mixed
   *   The ip address or null if the machine is not running.
   */
  protected function getDockerMachineIp(SymfonyStyle $io) {
    // Get the ip address of the Docker Machine.
    $ip = NULL;
    $result = $this->taskExec('docker-machine ip dp-docker')
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_DEBUG)
      ->printOutput(FALSE)
      ->printMetadata(FALSE)
      ->run();
    $io->newLine();
    if ($result instanceof Result && $result->wasSuccessful()) {
      $ip = $result->getMessage();
    }
    return trim($ip);
  }

  /**
   * Get the url of the dp-docker machine docker daemon.
   *
   * @param \Symfony\Component\Console\Style\SymfonyStyle $io
   *   Injected IO object.
   *
   * @return mixed
   *   The url or null if the machine is not running.
   */
  public function getDockerMachineUrl(SymfonyStyle $io) {
    // Get the url of the Docker Machine.
    $url = '';
    $result = $this->taskExec('docker-machine url dp-docker')
      ->printOutput(FALSE)
      ->printMetadata(FALSE)
      ->run();
    $io->newLine();
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
    $result = $this->taskExec("docker-compose $this->dockerFlags port database 3306")
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
    $this->setInitialConditions($io);
    $io->title('Setup HTTP Proxy');
    $boot_task = $this->collectionBuilder();
    $boot_task->addTask(
      $this->taskExec("docker $this->dockerFlags network create proxynet")
    )->rollback(
      $this->taskExec("docker $this->dockerFlags network prune")
    );
    $command = "docker $this->dockerFlags run";
    $command .= ' -d -v /var/run/docker.sock:/tmp/docker.sock:ro';
    $command .= ' -p 80:80 --restart always --network proxynet';
    $command .= ' --name http-proxy digitalpulp/nginx-proxy';
    $boot_task->addTask(
      $this->taskExec($command)
    )->rollback(
      $this->taskExec("docker $this->dockerFlags rm http-proxy")
    );
    return $boot_task;
  }

}
