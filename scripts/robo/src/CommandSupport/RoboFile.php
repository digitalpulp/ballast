<?php

/**
 * This is DP-Docker configuration for Robo task runner.
 *
 * It is designed to manage tasks in two contexts:
 * - local development.
 * - CI/CD on the Codeship Pro platform.
 *
 * The commands are grouped in the class by context in that order.  All public
 * methods are available as commands.
 *
 * The third group of methods are protected utility methods used
 * by the commands. It seems to be a convention in Robo to begin such methods
 * with get or set.
 *
 * @see http://robo.li/
 */

use Ballast\Utilities\Config;
use Ballast\Utilities\RemoteRebuild;
use Robo\Result;
use Robo\Tasks;
use Robo\Contract\VerbosityThresholdInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Class RoboFile for Ballast.
 */
class RoboFile extends Tasks {

  /**
   * Config Utility (setter injected).
   *
   * @var \Ballast\Utilities\Config
   */
  protected $config;

  /**
   * Setup Command support object (setter injected).
   *
   * @var \Ballast\CommandSupport\Setup
   */
  protected $setup;

  /**
   * Docker Command support object (setter injected).
   *
   * @var \Ballast\CommandSupport\Docker
   */
  protected $docker;

  /**
   * Startup Command support object (setter injected).
   *
   * @var \Ballast\CommandSupport\StartUp
   */
  protected $startUp;

  /**
   * Deploy Command support object (setter injected).
   *
   * @var \Ballast\CommandSupport\Deploy
   */
  protected $deploy;

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
   * @defgroup setup Initial Setup.
   */

  /**
   * Robo Command that dispatches prerequisite setup tasks by OS.
   *
   * @ingroup setup
   */
  public function setupPrerequisites() {
    $isDev = getenv('COMPOSER_DEV_MODE');
    if (!$isDev) {
      // This is a production build - do not build the local dev environment.
      return;
    }
    $this->setSetup();
    switch (php_uname('s')) {
      case 'Darwin':
        $this->setup->setupMac();
        $this->setup->setupPrecommit();
        $this->io()->title("Next Steps");
        $this->io()
          ->text('We will be using Ahoy to interact with our toolset from here.  Enter `ahoy -h` to see the full list or check the README.');
        $this->io()
          ->note('All the docker projects need to be in the same parent folder.  Because of the nature of NFS, this folder cannot contain any older vagrant based projects. If needed, create a directory and move this project before continuing.');
        $this->io()
          ->text('To finish setting up Ballast for the first time and launch this Drupal site, use the following commands:');
        $this->io()->listing([
          'ahoy harbor',
          'ahoy cast-off',
          'ahoy rebuild',
        ]);
        $this->io()->newLine();
        $this->io()
          ->text('If you have previously installed Ballast on this machine you are now ready to cast-off and launch.');
        $this->io()
          ->text('To launch this Drupal site, use the following commands:');
        $this->io()->listing([
          'ahoy cast-off (Only needed once after starting up your Mac.)',
          'ahoy launch (`ahoy cast-off` will call this for you)',
          'ahoy rebuild',
        ]);
        break;

      default:
        $this->io()
          ->error("Unable to determine your operating system.  Manual installation will be required.");
    }
  }

  /**
   * Robo Command that dispatches docker setup tasks by OS.
   *
   * @ingroup setup
   */
  public function setupDocker() {
    $this->setDocker();
    switch (php_uname('s')) {
      case 'Darwin':
        $home = getenv('HOME');
        if (!file_exists("$home/.docker/machine/machines/dp-docker")) {
          $this->docker->setupDockerMac();
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
  public function setupDns() {
    $this->setSetup();
    switch (php_uname('s')) {
      case 'Darwin':
        $home = getenv('HOME');
        if (!file_exists("$home/.docker/machine/machines/dp-docker")) {
          $this->io()
            ->error('You must run `composer install` followed by `ahoy harbor` before you run this Drupal site.');
        }
        $this->setup->setupDnsMac();
        break;

      default:
        $this->io()
          ->error("Unable to determine your operating system.  Manual dns boot will be required.");
    }
  }

  /**
   * @defgroup setup Startup Commands - used once per login.
   */

  /**
   * Entry point command for the boot process.
   *
   * Routes to a machine specific boot function.
   */
  public function boot() {
    $this->setStartUp();
    switch (php_uname('s')) {
      case 'Darwin':
        $home = getenv('HOME');
        if (!file_exists("$home/.docker/machine/machines/dp-docker") || !file_exists($this->config->getDrupalRoot() . '/core')) {
          $this->io()
            ->error('You must run `composer install` followed by `ahoy harbor` before you run this Drupal site.');
        }
        else {
          $this->startUp->bootMac();
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
    $this->setDocker();
    switch (php_uname('s')) {
      case 'Darwin':
        if ($this->docker->setMacDnsmasq()) {
          // dnsmasq running - put the mac resolver file in place.
          $this->startUp->setResolverFile();
          $this->io()->success('Ballast DNS service started.');
          if ($this->confirm('Would you also like to launch the site created by this project?')) {
            $this->docker->dockerCompose();
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
   * @defgroup workflow Workflow Commands - used to interact with the site.
   */

  /**
   * Entry point command to launch the docker-compose process.
   *
   * Routes to a machine specific compose function.
   */
  public function dockerCompose() {
    $this->setDocker();
    $this->setStartUp();
    $launched = FALSE;
    switch (php_uname('s')) {
      case 'Darwin':
        $home = getenv('HOME');
        $drupalRoot = $this->config->getDrupalRoot();
        if (!file_exists("$home/.docker/machine/machines/dp-docker") || !file_exists("$drupalRoot/core")) {
          $this->io()
            ->error('You must run `composer install` followed by `ahoy harbor` before you run this Drupal site.');
        }
        elseif ($this->docker->getDockerMachineUrl()) {
          // The docker machine is installed and running.
          $launched = $this->docker->dockerComposeMac();
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
      if ($this->startUp->getFrontEndStatus(TRUE)) {
        $this->io()->text('Front end tools are ready.');
        $this->startUp->setClearFrontEndFlags();
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
   * @defgroup ahoy Developer Commands
   */

  /**
   * Prep a key to be one line using the php container.
   *
   * @param string $key
   *   The key.
   */
  public function keyPrep($key) {
    $this->setConfig();
    $root = $this->config->getProjectRoot();
    $key_contents = file_get_contents("$root/$key");
    $one_line = str_replace(["\r", "\n"], '\\n',
      $key_contents);
    $result = $this->taskWriteToFile("$this->projectRoot/env")
      ->append(TRUE)
      ->line("SSH_PRIVATE_KEY=$one_line")
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_DEBUG)
      ->run();
    if ($result instanceof Result && $result->wasSuccessful()) {
      $this->io()
        ->success("The key has been processed and appended to the env file.");
    }
    else {
      $this->io()->error('Error message: ' . $result->getMessage());
    }
  }

  /**
   * Sync the db from a remote server to the docker environment.
   *
   * @param string $environment
   *   The environment key.
   */
  public function rebuild($environment = 'dev') {
    $rebuild = new RemoteRebuild();
    $this->setConfig();
    $rebuild->setConfig($this->config);
    $rebuild->execute($environment);
  }

  /**
   * Prints the database connection info for use in SQL clients.
   */
  public function connectSql() {
    $this->setDocker();
    $ip = $this->docker->getDockerMachineIp();
    $port = $this->docker->getSqlPort();
    $this->io()->title('Database Info');
    $this->io()->text("The Docker Machine host is: $ip");
    $this->io()->text("Connect to port: $port");
    $this->io()->text("Username, password, and database are all 'drupal'");
    $this->io()->note("Both the ip and port can vary between re-boots");
  }

  /**
   * @defgroup deploy Deployment Commands
   */

  /**
   * Builds separate artifact and pushes to remote defined in $DEPLOY_TARGET.
   *
   * @param array $options
   *   Options from the command line.
   *
   * @throws \Exception
   *   Throws an exception if the deployment fails.
   */
  public function deploy(array $options = [
    'branch' => NULL,
    'tag' => NULL,
    'commit-msg' => NULL,
    'remote-branch' => NULL,
    'remote' => NULL,
    'build_id' => NULL,
  ]) {
    if (!empty($options['tag'])) {
      $result = $this->deployTag($options);
    }
    else {
      $result = $this->deployBranch($options);
    }
    if ($result instanceof Result && $result->wasSuccessful()) {
      $this->io()
        ->success('Deployment succeeded.');
    }
    else {
      throw new Exception('Deployment failed.');
    }
  }

  /**
   * Builds separate artifact and pushes as a tag.
   *
   * @param array $options
   *   Options from the command line or internal call.
   *
   * @return \Robo\Result
   *   The result of the final push.
   */
  public function deployTag(array $options = [
    'branch' => InputOption::VALUE_REQUIRED,
    'tag' => InputOption::VALUE_REQUIRED,
    'commit-msg' => InputOption::VALUE_REQUIRED,
    'remote-branch' => NULL,
    'remote' => NULL,
    'build_id' => NULL,
  ]) {
    $this->setDeploy();
    $this->deploy->setDeploymentOptions($options);
    if (empty($options['tag'])) {
      // Excess of caution to be sure we have a tag - should never be here.
      $options['tag'] = $options['branch'] . '-' . time();
      if (!empty($options['build_id'])) {
        // If we have a build ID make a better tag.
        $options['tag'] = $options['branch'] . '-' . $options['build_id'];
      }
    }
    $this->say('Deploying to tag ' . $options['tag']);
    $this->deploy->setDeploymentVersionControl($options);
    $this->deploy->getDeploymentDependencies();
    $this->deploy->getSanitizedBuild();
    $this->deploy->setDeploymentCommit($options);
    $this->deploy->setCleanMerge($options);
    return $this->deploy->getPushResult($options);
  }

  /**
   * Builds separate artifact and pushes to remote as a branch.
   *
   * @param array $options
   *   Options from the command line or internal call.
   *
   * @return \Robo\Result
   *   The result of the final push.
   */
  public function deployBranch(array $options = [
    'branch' => InputOption::VALUE_REQUIRED,
    'commit-msg' => InputOption::VALUE_REQUIRED,
    'remote-branch' => NULL,
    'remote' => NULL,
    'build_id' => NULL,
  ]) {
    $this->setDeploy();
    $this->deploy->setDeploymentOptions($options);
    $this->say('Deploying to branch ' . $options['branch']);
    $this->deploy->setDeploymentVersionControl($options);
    $this->deploy->getDeploymentDependencies();
    $this->deploy->getSanitizedBuild();
    $this->deploy->setDeploymentCommit($options);
    $this->deploy->setCleanMerge($options);
    return $this->deploy->getPushResult($options);
  }

  /*
   * Dependency injection setters.
   */

  /**
   * Setter injection for Ballast\Utilities\Config.
   */
  protected function setConfig() {
    if (!$this->config instanceof \Ballast\Utilities\Config) {
      $this->config = new Config();
    }
  }

  /**
   * Setter injection for Ballast\Commands\Setup.
   */
  protected function setSetup() {
    if (!$this->setup instanceof \Ballast\CommandSupport\Setup) {
      $this->setup = new \Ballast\CommandSupport\Setup();
      $this->setConfig();
      $this->setup->setConfig($this->config);
    }
  }

  /**
   * Setter injection for Ballast\Commands\Docker.
   */
  public function setDocker() {
    if (!$this->docker instanceof \Ballast\CommandSupport\Docker) {
      $this->docker = new \Ballast\CommandSupport\Docker();
      $this->setConfig();
      $this->docker->setConfig($this->config);
    }
  }

  /**
   * Setter injection for Ballast\Commands\StartUp.
   */
  public function setStartUp() {
    if (!$this->startUp instanceof \Ballast\CommandSupport\StartUp) {
      $this->startUp = new \Ballast\CommandSupport\StartUp();
      $this->setDocker();
      $this->startUp->setConfig($this->config);
      $this->startUp->setDocker($this->docker);
    }
  }

  /**
   * Setter injection for Ballast\Commands\StartUp.
   */
  public function setDeploy() {
    if (!$this->deploy instanceof \Ballast\CommandSupport\Deploy) {
      $this->deploy = new \Ballast\CommandSupport\Deploy();
      $this->setConfig();
      $this->setStartUp();
      $this->deploy->setConfig($this->config);
      $this->deploy->setStartUp($this->startUp);
    }
  }

}
