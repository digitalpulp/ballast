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

use Robo\Result;
use Robo\Tasks;
use Robo\Contract\VerbosityThresholdInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use DrupalFinder\DrupalFinder;

/**
 * Class RoboFile for Ballast.
 */
class RoboFile extends Tasks {

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
    switch (php_uname('s')) {
      case 'Darwin':
        $this->setupMac();
        $this->setupPrecommit();
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
   * Robo Command for MacOS Initial setup.
   *
   * @ingroup setup
   */
  public function setupMac() {
    $this->io()->title('Mac Setup for Ballast');
    $this->taskExec('brew update')
      ->printOutput(FALSE)
      ->printMetadata(FALSE);
    $prereqs = $this->getPrerequisites('mac');
    $this->io()->section('Check for Prerequisites');
    $ready = TRUE;
    foreach ($prereqs as $short_name => $full_name) {
      if (!$this->getIsInstalled($short_name)) {
        $this->io()
          ->error($full_name . " is not available.  Check the project README for preconditions and instructions.");
        $ready = FALSE;
      }
    }
    if (!$ready) {
      // Stop setup so the user can prepare the Mac.
      return;
    }
    $required = $this->getRequirements('mac');
    if (count($required) > 0) {
      $this->io()->text('The following packages need to be installed:');
      $this->io()->listing(array_column($required, 'name'));
      $collection = $this->collectionBuilder();
      foreach ($required as $package => $settings) {
        $collection->progressMessage('Installing ' . $settings['name']);
        if (!empty($settings['tap'])) {
          $collection->addTask(
            $this->taskExec('brew tap ' . $settings['tap'])
          )->rollback(
            $this->taskExec('brew untap ' . $settings['tap'])
          );
        }
        $collection->addTask(
          $this->taskExec('brew '. ($settings['cask'] ? 'cask ' : '') . 'install ' . $package)
        )->rollback(
          $this->taskExec('brew '. ($settings['cask'] ? 'cask ' : '') . "uninstall $package")
        );
      }

      $result = $collection->run();
    }
    if ((isset($result) && $result instanceof Result && $result->wasSuccessful())) {
      $this->io->success("Prerequisites prepared for Ballast.");
    }
    elseif (count($required) == 0) {
      $this->io->success("Your Mac was already prepared for Ballast.");
    }
    else {
      $this->io()
        ->error("Something went wrong.  Changes have been rolled back.");
    }
  }

  /**
   * Install pre-commit hooks.
   */
  public function setupPrecommit() {
    $this->io()->section('Configuring pre-commit linting tool.');
    $this->setRoots();
    $this->getConfig();
    $collection = $this->collectionBuilder();
    $collection->addTask(
      $this->taskExec('pre-commit install')->dir($this->projectRoot)
    )->rollback(
      $this->taskExec('pre-commit uninstall')
        ->dir($this->projectRoot)
    );
    if (!file_exists("$this->projectRoot/.git/hooks/commit-msg")) {
      $collection->addTask(
        $this->taskFilesystemStack()
          ->copy("$this->projectRoot/scripts/git/commit-msg-template",
            "$this->projectRoot/scripts/git/commit-msg")
      )->rollback(
        $this->taskFilesystemStack()
          ->remove("$this->projectRoot/scripts/git/commit-msg")
      );
      $collection->addTask(
        $this->taskReplaceInFile("$this->projectRoot/scripts/git/commit-msg")
          ->from('{key}')
          ->to($this->configuration['jira_project_key'])
      );
      $collection->addTask(
        $this->taskFilesystemStack()
          ->rename("$this->projectRoot/scripts/git/commit-msg",
            "$this->projectRoot/.git/hooks/commit-msg")
      );
    }
    $result = $collection->run();
    if ($result instanceof Result && $result->wasSuccessful()) {
      $this->io->success("Hooks for commit-msg and pre-commit linting have been installed.");
    }
    else {
      $this->io()
        ->error("Something went wrong.  Changes have been rolled back.");
    }
  }

  /**
   * Robo Command that dispatches docker setup tasks by OS.
   *
   * @ingroup setup
   */
  public function setupDocker() {
    switch (php_uname('s')) {
      case 'Darwin':
        $home = getenv('HOME');
        if (!file_exists("$home/.docker/machine/machines/dp-docker")) {
          $this->setupDockerMac();
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
   * Download Linux kernel and create the virtualbox based VM for Mac containers.
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
   * Routes to a machine specific http proxy function.
   */
  public function setupDns() {
    switch (php_uname('s')) {
      case 'Darwin':
        $home = getenv('HOME');
        if (!file_exists("$home/.docker/machine/machines/dp-docker")) {
          $this->io()
            ->error('You must run `composer install` followed by `ahoy harbor` before you run this Drupal site.');
        }
        $this->setupDnsMac();
        break;

      default:
        $this->io()
          ->error("Unable to determine your operating system.  Manual dns boot will be required.");
    }
  }

  /**
   * Setup the http-proxy service with dns for macOS.
   */
  public function setupDnsMac() {
    if ($this->getDockerMachineIp()) {
      $this->io()->title('Setup HTTP Proxy and .dp domain resolution.');
      // Boot the DNS service.
      $this->setMacDockerEnv();
      // @see https://hub.docker.com/r/jwilder/nginx-proxy/
      $boot_task = $this->collectionBuilder();
      $boot_task->addTask(
        $this->taskExec('docker network create proxynet')
      )->rollback(
        $this->taskExec('docker network prune')
      );
      $boot_task->addTask(
        $this->taskDockerRun('digitalpulp/nginx-proxy')
          ->volume('/var/run/docker.sock', '/tmp/docker.sock:ro')
          ->name('http-proxy')
          ->detached()
          ->publish(80, 80)
          ->option('restart', 'always')
          ->option('network', 'proxynet')
      )->rollback(
        $this->taskDockerRemove('http-proxy')
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
   * Copy values from the config.yml and move drupal settings files into place.
   */
  public function setupDrupal() {
    $isDev = getenv('COMPOSER_DEV_MODE');
    if (!$isDev) {
      // This is a prod build, this all should be in the repo.
      return;
    }
    $this->setRoots();
    $this->getConfig();
    $this->taskFilesystemStack()->chmod("$this->drupalRoot/sites/default", 0755)
      ->run();
    $this->taskFilesystemStack()
      ->chmod("$this->drupalRoot/sites/default/settings.php", 0755)
      ->run();
    $collection = $this->collectionBuilder();
    $collection->addTask(
      $this->taskFilesystemStack()
        ->copy("$this->projectRoot/setup/drupal/settings.acquia.php",
          "$this->drupalRoot/sites/default/settings.acquia.php", TRUE)
    )->rollback(
      $this->taskFilesystemStack()
        ->remove("$this->drupalRoot/sites/default/settings.acquia.php")
    );
    $collection->addTask(
      $this->taskFilesystemStack()
        ->copy("$this->projectRoot/setup/drupal/settings.non-acquia.php",
          "$this->drupalRoot/sites/default/settings.non-acquia.php", TRUE)
    )->rollback(
      $this->taskFilesystemStack()
        ->remove("$this->drupalRoot/sites/default/settings.non-acquia.php")
    );
    $collection->addTask(
      $this->taskFilesystemStack()
        ->copy("$this->projectRoot/setup/drupal/settings.local.php",
          "$this->drupalRoot/sites/default/settings.local.php", TRUE)
    )->rollback(
      $this->taskFilesystemStack()
        ->remove("$this->drupalRoot/sites/default/settings.local.php")
    );
    $collection->addTask(
      $this->taskFilesystemStack()
        ->copy("$this->projectRoot/setup/drupal/services.dev.yml",
          "$this->drupalRoot/sites/default/services.dev.yml", TRUE)
    )->rollback(
      $this->taskFilesystemStack()
        ->remove("$this->drupalRoot/sites/default/services.dev.yml")
    );
    $collection->addTask(
      $this->taskReplaceInFile("$this->drupalRoot/sites/default/settings.local.php")
        ->from('{site_shortname}')
        ->to($this->configuration['site_shortname'])
    );
    $collection->addTask(
      $this->taskReplaceInFile("$this->drupalRoot/sites/default/settings.local.php")
        ->from('{site_proxy_origin_url}')
        ->to($this->configuration['site_proxy_origin_url'])
    );
    if (file_exists("$this->drupalRoot/sites/default/settings.php") &&
      !preg_match(
        '|\/\*\sSettings added by robo setup:drupal|',
        file_get_contents("$this->drupalRoot/sites/default/settings.php")
      )
    ) {
      $collection->addTask(
        $this->taskConcat(
          [
            "$this->drupalRoot/sites/default/settings.php",
            "$this->projectRoot/setup/drupal/settings.append.php",
          ]
        )->to("$this->drupalRoot/sites/default/settings.php")
      );
    }
    $result = $collection->run();
    if ($result instanceof Result && $result->wasSuccessful()) {
      $this->io()->success('Drupal settings are configured.');
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
    $this->setRoots();
    switch (php_uname('s')) {
      case 'Darwin':
        $home = getenv('HOME');
        if (!file_exists("$home/.docker/machine/machines/dp-docker") || !file_exists("$this->drupalRoot/core")) {
          $this->io()
            ->error('You must run `composer install` followed by `ahoy harbor` before you run this Drupal site.');
        }
        else {
          $this->bootMac();
        }
        break;

      default:
        $this->io()
          ->error("Unable to determine your operating system.  Manual boot will be required.");
    }
  }

  /**
   * Mac specific docker boot process.
   */
  public function bootMac() {
    // Boot the Docker Machine.
    $this->io()->title('Start the Ballast Docker Machine.');
    if (!($ip = $this->getDockerMachineIp())) {
      $this->setRoots();
      // Set the default to the parent of the project folder.
      $dir = dirname($this->projectRoot);
      $folder = $this->io()
        ->ask('What is the path to your docker sites folder?',
          $dir);
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
    if ($ip || (isset($result) && $result->wasSuccessful())) {
      $this->io()->success('Ballast Docker Machine is ready to host projects.');
    }
  }

  /**
   * Start DNS service to resolve containers.
   */
  public function bootDns() {
    switch (php_uname('s')) {
      case 'Darwin':
        $this->setMacDnsmasq();
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
    $this->setRoots();
    switch (php_uname('s')) {
      case 'Darwin':
        $home = getenv('HOME');
        if (!file_exists("$home/.docker/machine/machines/dp-docker") || !file_exists("$this->drupalRoot/core")) {
          $this->io()
            ->error('You must run `composer install` followed by `ahoy harbor` before you run this Drupal site.');
        }
        elseif ($this->getDockerMachineUrl()) {
          // The docker machine is installed and running.
          $this->dockerComposeMac();
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
    $this->setRoots();
    $this->setMacDockerEnv();
    $this->getConfig();
    $collection = $this->collectionBuilder();
    $collection->addTask(
      $this->taskFilesystemStack()
        ->copy("$this->projectRoot/setup/docker/docker-compose-template",
          "$this->projectRoot/setup/docker/docker-compose.yml")
    )->rollback(
      $this->taskFilesystemStack()
        ->remove("$this->projectRoot/setup/docker/docker-compose.yml")
    );
    $collection->addTask(
      $this->taskReplaceInFile("$this->projectRoot/setup/docker/docker-compose.yml")
        ->from('{site_shortname}')
        ->to($this->configuration['site_shortname'])
    );
    $collection->addTask(
      $this->taskReplaceInFile("$this->projectRoot/setup/docker/docker-compose.yml")
        ->from('{site_theme_name}')
        ->to($this->configuration['site_theme_name'])
    );
    $collection->addTask(
      $this->taskReplaceInFile("$this->projectRoot/setup/docker/docker-compose.yml")
        ->from('{host_ip}')
        ->to($this->getHostIp($ip))
    );
    // Move into place or overwrite the docker-compose.yml.
    $collection->addTask(
      $this->taskFilesystemStack()
        ->rename("$this->projectRoot/setup/docker/docker-compose.yml",
          "$this->projectRoot/docker-compose.yml", TRUE)
    );
    $collection->run();
    $command = 'docker-compose up -d';
    $result = $this->taskExec($command)->run();
    if (isset($result) && $result->wasSuccessful()) {
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
        ->success('The site can now be reached at ' . $this->configuration['site_shortname'] . '.dpulp/');
    }
  }

  /**
   * Sync the db from a remote server to the docker environment.
   *
   * @param string $environment
   *   The environment key.
   */
  public function rebuild($environment = 'dev') {
    $this->setRoots();
    $this->getConfig();
    $target = $this->configuration['site_acquia_name'] . '.' . $environment;
    switch (php_uname('s')) {
      case 'Darwin':
        $home = getenv('HOME');
        if (!file_exists("$home/.docker/machine/machines/dp-docker") || !file_exists("$this->drupalRoot/core")) {
          $this->io()
            ->error('You must run `composer install` followed by `ahoy harbor` before you can rebuild.');
        }
        else {
          $this->setMacDockerEnv();
        }
        break;

      default:
        $this->io()
          ->error("Unable to determine your operating system.");
    }
    $this->taskExecStack()->stopOnFail()
      ->exec("$this->projectRoot/vendor/bin/drush --alias-path='$this->projectRoot/drush/aliases' @$target sql-dump --result-file= > $this->projectRoot/target.sql")
      ->exec('docker-compose exec -T cli drush -y sql-drop || true')
      ->exec("docker-compose exec -T cli drush sql-sync -y @self @self --no-dump --source-dump=/var/www/target.sql")
      ->exec('docker-compose exec -T cli drush sqlsan -y --sanitize-password=dp --sanitize-email=user-%uid@example.com')
      ->exec('docker-compose exec -T cli drush cim sync -y')
      ->exec('docker-compose exec -T cli drush cr')
      ->exec('docker-compose exec -T cli drush -y updb')
      ->exec('docker-compose exec -T front-end node_modules/.bin/gulp build')
      ->run();
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
  ]) {
    $this->getConfig();
    $this->setDeploymentOptions($options);
    // One could come in from the command but that is not the expected pattern.
    if (empty($options['tag'])) {
      // Set a default.
      $options['tag'] = $options['branch'] . '-' . time();
      if (!empty($options['build_id'])) {
        // If we have a build ID make a better tag.
        $options['tag'] = $options['branch'] . '-' . $options['build_id'];
      }
    }
    $this->say('Deploying to tag ' . $options['tag']);
    $this->setDeploymentVersionControl($options);
    $this->getDeploymentDependencies();
    $this->getSanitizedBuild();
    $this->setDeploymentCommit($options);
    $this->setCleanMerge($options);
    return $this->getPushResult($options);
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
  ]) {
    $this->setDeploymentOptions($options);
    $this->say('Deploying to branch ' . $options['branch']);
    $this->setDeploymentVersionControl($options);
    $this->getDeploymentDependencies();
    $this->getSanitizedBuild();
    $this->setDeploymentCommit($options);
    $this->setCleanMerge($options);
    return $this->getPushResult($options);
  }

  /*
   * Helper functions.
   */

  /**
   * Loads project configuration from yaml.
   */
  protected function getConfig() {
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
   * Checks for installed packages use `which`.
   *
   * @param string $package
   *   The package to check.
   *
   * @return bool
   *   True if found.
   */
  protected function getIsInstalled($package) {
    $this->io()->comment("Checking installation of " . $package . "...");
    $result = $this->taskExec('which -s ' . $package)
      ->printOutput(FALSE)
      ->printMetadata(FALSE)
      ->run();
    $this->io()->newLine();
    if ($result instanceof Result) {
      return $result->wasSuccessful();
    }
    throw new UnexpectedValueException("taskExec() failed to return a valid Result object in getIsInstalled()");
  }

  /**
   * Gets the packages installed using Homebrew.
   *
   * @return array
   *   Associative array keyed by package short name.
   */
  protected function getBrewedComponents() {
    $this->io()->comment('Getting the packages installed with Homebrew');
    $result = $this->taskExec('brew info --json=v1 --installed')
      ->printOutput(FALSE)
      ->printMetadata(FALSE)
      ->run();
    $this->io()->newLine();
    if ($result instanceof Result) {
      $rawJson = json_decode($result->getMessage(), 'assoc');
      $parsed = [];
      foreach ($rawJson as $package) {
        $parsed[$package['name']] = $package;
        unset($parsed[$package['name']]['name']);
      }
      return $parsed;
    }
    throw new UnexpectedValueException("taskExec() failed to return a valid Result object in getBrewedComponents()");
  }

  /**
   * Get the prerequisites by machine type.
   *
   * @param string $machine
   *   The machine key.
   *
   * @return mixed
   *   Associative array of prerequisites keyed by short name.
   */
  protected function getPrerequisites($machine) {
    $prerequisites = [
      'mac' => [
        'brew' => 'Homebrew',
      ],
    ];
    return $prerequisites[$machine];
  }

  /**
   * Get the required packages by machine name.
   *
   * @param string $machine
   *   The machine key.
   *
   * @return array
   *   Associative array of requirements keyed by short name.
   */
  protected function getRequirements($machine) {
    // State the requirements in an array.
    // Unset requirements already met to create a installation manifest.
    switch ($machine) {
      case 'mac':
        $required = [
          'ahoy' => [
            'name' => 'Ahoy',
            'tap' => 'ahoy-cli/tap',
            'cask' => FALSE,
          ],
          'virtualbox' => [
            'name' => 'virtualbox',
            'tap' => '',
            'cask' => TRUE,
          ],
          'docker' => [
            'name' => 'Docker',
            'tap' => '',
            'cask' => FALSE,
          ],
          'docker-compose' => [
            'name' => 'Docker Compose',
            'tap' => '',
            'cask' => FALSE,
          ],
          'pre-commit' => [
            'name' => 'pre-commit by Yelp',
            'tap' => '',
            'cask' => FALSE,
          ],
          'docker-machine-nfs' => [
            'name' => 'Docker Machine NFS',
            'tap' => '',
            'cask' => FALSE,
          ],
        ];
        $brewed = $this->getBrewedComponents();
        $this->io()->section('Checking for Installed Requirements');
        foreach ($required as $package => $full_name) {
          if (isset($brewed[$package]) || ($this->getIsInstalled($package))) {
            // Installed.  Unset.
            unset($required[$package]);
          }
        }
        return $required;

      default:
        return [];
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
  protected function getDockerMachineUrl() {
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
    // Get the port string.
    $port = NULL;
    $this->setMacDockerEnv();
    $result = $this->taskExec('docker-compose port database 3306')
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
   * Launches the dnsmasq container if it is not running.
   */
  protected function setMacDnsmasq() {
    $this->setMacDockerEnv();
    $result = $this->taskExec('docker inspect dnsmasq')
      ->printOutput(FALSE)
      ->printMetadata(FALSE)
      ->run();
    if ($result->wasSuccessful()) {
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
          $this->taskDockerRemove('dnsmasq')->run();
        }
      }
    }
    // Either there is no dns container or it has been removed.
    $ip = $this->getDockerMachineIp();
    $result = $this->taskDockerRun('andyshinn/dnsmasq:2.76')
      ->name('dnsmasq')
      ->detached()
      ->optionList('publish', ['53535:53/tcp', '53535:53/udp'])
      ->option('cap-add', 'NET_ADMIN')
      ->exec("--address=/dpulp/$ip")
      ->printOutput(FALSE)
      ->run();
    if ($result instanceof Result && $result->wasSuccessful()) {
      $this->setResolverFile();
      $this->io()->success('Ballast DNS service started.');
      if ($this->confirm('Would you also like to launch the site created by this project?')) {
        $this->dockerComposeMac();
      }
    }
    else {
      $this->io()->error('Unable to connect to docker daemon.');
      return;
    }
  }

  /**
   * Place or update the dns resolver file.
   */
  protected function setResolverFile() {
    if ($ip = $this->getDockerMachineIp()) {
      $this->setRoots();
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
          $this->taskExec('cp ' . "$this->projectRoot/setup/dns/dpulp-template $this->projectRoot/setup/dns/dpulp")
        )->rollback(
          $this->taskExec('rm -f' . "$this->projectRoot/setup/dns/dpulp")
        );
        $collection->addTask(
          $this->taskReplaceInFile("$this->projectRoot/setup/dns/dpulp")
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
            ->exec("sudo mv $this->projectRoot/setup/dns/dpulp /etc/resolver")
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
   * Utility function to set Docker environment variables.
   */
  protected function setMacDockerEnv() {
    $url = $this->getDockerMachineUrl();
    $home = getenv('HOME');
    putenv('DOCKER_HOST=' . $url);
    putenv('DOCKER_TLS_VERIFY="1"');
    putenv("DOCKER_CERT_PATH=$home/.docker/machine/machines/dp-docker");
    putenv('DOCKER_MACHINE_NAME=dp-docker');
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

  /**
   * Spends max 5 minutes checking if the front-end tools are initialized.
   *
   * @param bool $progress
   *   Should a progress bar display?
   *
   * @return bool
   *   TRUE if the flag file created by the front-end container is found.
   */
  protected function getFrontEndStatus($progress = FALSE) {
    // Initialize variables.
    $ready = FALSE;
    $max_rounds = 150;
    $rounds = 0;
    $this->setRoots();
    $this->getConfig();
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
  protected function setClearFrontEndFlags() {
    $this->setRoots();
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

  /**
   * Insures that appropriate options are present.
   *
   * @param array $options
   *   Options passed from the command.
   */
  protected function setDeploymentOptions(array &$options) {
    $this->getConfig();
    $options['remote'] = getenv('DEPLOY_TARGET');
    $options['branch'] = isset($options['branch']) ? $options['branch'] : getenv('CI_BRANCH');
    $options['remote-branch'] = isset($options['remote-branch']) ? $options['remote-branch'] : $options['branch'];
    $options['build_id'] = substr(getenv('CI_COMMIT_ID'), 0, 7);
    // A target is required.
    if (empty($options['remote'])) {
      // We need a repository target.
      throw new UnexpectedValueException('Environment variable $DEPLOY_TARGET must be set before deployment.');
    }
    // As is a branch.
    if (empty($options['branch'])) {
      throw new UnexpectedValueException('A branch must be specified.');
    }
    // There should also be a commit-message.
    if (empty($options['commit-msg'])) {
      $options['commit-msg'] = 'Deployment built for ' . $this->configuration['site_shortname'];
      if (!empty($options['build_id'])) {
        $options['commit-msg'] .= ' Build ID: ' . $options['build_id'];
      }
    }
  }

  /**
   * Adds the deployment target as a remote.
   *
   * @param array $options
   *   Options passed from the command.
   *
   * @throws \Exception
   *   Throws an exception if the git tasks fail so that deployment will abort.
   */
  protected function setDeploymentVersionControl(array &$options) {
    $this->setRoots();
    $this->getConfig();
    // Get from value set via codeship env.encrypted.
    /* https://documentation.codeship.com/pro/builds-and-configuration/environment-variables/#encrypted-environment-variables */
    $git_name = getenv('GIT_NAME') ? getenv('GIT_NAME') : 'Deployment';
    $git_mail = getenv('GIT_EMAIL') ? getenv('GIT_EMAIL') : 'deploy@example.com';
    $remote_url = $options['remote'];
    $remote_name = $this->configuration['site_acquia_name'];
    // Store for later use.
    $options['remote'] = $remote_name;
    $this->say("Will push to git remote $remote_name at $remote_url");
    $result = $this->taskExecStack()
      ->dir($this->projectRoot)
      ->stopOnFail()
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_DEBUG)
      ->exec("git remote add $remote_name $remote_url")
      ->exec("git config --global user.email $git_mail")
      ->exec("git config --global user.name $git_name")
      ->run();
    if ($result instanceof Result && !$result->wasSuccessful()) {
      throw new Exception('Git config failed to set.');
    }
    // We also need to get the keys provided via encrypted environment and add
    // them into the appropriate files with the appropriate permissions.
    $git_host = getenv('GIT_KNOWN_HOST');
    $ssh_key = getenv('SSH_PRIVATE_KEY');
    if (empty($git_host)) {
      throw new UnexpectedValueException('A git host server key must be configured in the encrypted environment variables.');
    }
    if (empty($ssh_key)) {
      throw new UnexpectedValueException('An ssh key must be configured in the encrypted environment variables.');
    }
    // Collect all the needed tasks and run them.
    $collection = $this->collectionBuilder();
    $collection->addTask(
      $this->taskFilesystemStack()
        ->mkdir('/root/.ssh')
        ->touch('/root/.ssh/known_hosts')
    );
    $collection->addTask(
      $this->taskWriteToFile('/root/.ssh/known_hosts')
        ->line($git_host)
    );
    $collection->addTask(
      $this->taskExec('echo -e ${SSH_PRIVATE_KEY} >> "${HOME}/.ssh/id_rsa"')
    );
    $collection->addTask(
      $this->taskFilesystemStack()
        ->chmod('/root/.ssh/id_rsa', 0600)
        ->chmod('/root/.ssh', 0600)
    );
    $result = $collection->run();
    if ($result instanceof Result && !$result->wasSuccessful()) {
      throw new Exception('Unable to set git host key.');
    }

  }

  /**
   * Function waits until front-end signals init is complete.
   */
  protected function getDeploymentDependencies() {
    $this->setRoots();
    $this->getConfig();
    $ready = FALSE;
    $building = FALSE;
    $compiling = FALSE;
    $flag_npm_install = sprintf('%s/themes/custom/%s/BUILDING.txt', $this->drupalRoot, $this->configuration['site_theme_name']);
    $flag_gulp_build = sprintf('%s/themes/custom/%s/COMPILING.TXT', $this->drupalRoot, $this->configuration['site_theme_name']);
    $this->say('Waiting for front-end tools to initialize.');
    $iterations = 0;
    while (!$ready) {
      $iterations++;
      if ($iterations > 1) {
        // If node modules began to compile, give an update, otherwise
        // throw an exception - something is wrong.
        if (!$building && file_exists($flag_npm_install)) {
          $building = TRUE;
          $this->say('Node modules began compiling.');
        }
        else {
          throw new UnexpectedValueException('After a full round of monitoring, node modules never began to compile.');
        }
        if (!$compiling && file_exists($flag_gulp_build)) {
          $compiling = TRUE;
          $this->say('Node modules have finished compiling. Gulp is compiling the theme.');
        }
      }

      $ready = $this->getFrontEndStatus(TRUE);
    }
    $this->setClearFrontEndFlags();
  }

  /**
   * Removes files and directories that should not be deployed.
   *
   * Also replaces .gitignore with deployment specific versions.
   *
   * @throws \Exception
   *   Throws an exception if the files are unable to be modified.
   */
  protected function getSanitizedBuild() {
    $this->setRoots();
    $this->getConfig();
    $this->say('Sanitizing artifact...');
    $this->say('Finding .git subdirectories...');
    $git = new Finder();
    $git
      ->in([$this->projectRoot . '/vendor', $this->projectRoot . '/docroot'])
      ->directories()
      ->ignoreDotFiles(FALSE)
      ->ignoreVCS(FALSE)
      ->name('/^\.git$/');
    $this->say($git->count() . ' .git directories found');

    $this->say('Finding any CHANGELOG.txt');
    $changelog = new Finder();
    $changelog
      ->in($this->projectRoot)
      ->files()
      ->name('CHANGELOG.txt');
    $this->say($changelog->count() . ' CHANGELOG.txt files found');

    $this->say('Finding .gitignore in themes');
    $theme_ignores = new Finder();
    $theme_ignores
      ->in($this->drupalRoot . '/themes/custom')
      ->ignoreDotFiles(FALSE)
      ->depth('< 3')
      ->files()
      ->name('/\.gitignore$/');
    $this->say($theme_ignores->count() . ' .gitignore files found in themes');

    $files = $git
      ->append($changelog)
      ->append($theme_ignores);

    $taskFilesystemStack = $this->taskFilesystemStack();
    foreach ($files->getIterator() as $item) {
      $taskFilesystemStack
        ->remove($item->getRealPath());
    }
    $collection = $this->collectionBuilder();
    $collection->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_DEBUG);
    $taskFilesystemStack->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_DEBUG);
    $collection->addTask($taskFilesystemStack);
    $collection->addTask(
      $this->taskFilesystemStack()
        ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_DEBUG)
        ->copy("$this->projectRoot/setup/deploy-gitignore",
          "$this->projectRoot/.gitignore", TRUE)
    );
    $result = $collection->run();
    if ($result instanceof Result && !$result->wasSuccessful()) {
      throw new Exception('Unable to sanizize the build.');
    }
  }

  /**
   * Commits the result of the deployment build.
   *
   * @param array $options
   *   Options passed from the command.
   *
   * @throws \Exception
   *   Throws an exception if the git tasks fail so that deployment will abort.
   */
  protected function setDeploymentCommit(array $options) {
    $gitJobs = $this->taskGitStack()
      ->stopOnFail()
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_DEBUG)
      ->checkout($options['branch'])
      ->add('-A')
      ->commit($options['commit-msg']);
    $result = $gitJobs->run();
    if ($result instanceof Result && !$result->wasSuccessful()) {
      throw new Exception('Git commit failed.');
    }
  }

  /**
   * Cleanly merges the current branch into the remote branch of the same name.
   *
   * Uses a simulated "theirs" strategy to prefer any changes or updates in
   * the current branch over that in the remote.
   *
   * @param array $options
   *   Options passed from the command.
   *
   * @throws \Exception
   *   Throws an exception if the branch checkout or merge fails.
   *
   * @see https://stackoverflow.com/questions/173919/is-there-a-theirs-version-of-git-merge-s-ours/4969679#4969679
   */
  protected function setCleanMerge(array &$options) {
    $this->getConfig();
    // We need some simple variables for expansion in a string.
    $local = $options['remote-branch'] . '-deploy';
    // Store for later use.
    $options['deploy-branch'] = $local;
    $remote = $options['remote'];
    $target = $remote . '/' . $options['remote-branch'];
    $message = 'Merge to remote: ' . $options['commit-msg'];
    $this->say('Move to a new branch that tracks the target repo.');
    $gitJobs = $this->taskGitStack()
      ->stopOnFail()
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_DEBUG)
      ->exec("fetch $remote")
      ->checkout("-b $local $target");
    $result = $gitJobs->run();
    if ($result instanceof Result && !$result->wasSuccessful()) {
      // The remote branch may not exist.  Just make the deploy branch.
      $gitJobs = $this->taskGitStack()
        ->stopOnFail()
        ->checkout("-b $local");
      $result = $gitJobs->run();
      if ($result instanceof Result && !$result->wasSuccessful()) {
        throw new Exception('Unable to checkout or create a deployment branch.');
      }
    }
    // Now cleanly merge $options['branch'] into $options['deploy-branch']
    // with preference to $options['branch'] for any conflicts.
    $this->say('Merging changes into remote tracking branch');
    $merge = $this->taskGitStack()
      ->stopOnFail()
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_DEBUG)
      ->exec('merge -s ours ' . $options['branch'] . " -m \"$message\"")
      ->exec('branch branch-temp')
      ->exec('reset --hard ' . $options['branch'])
      ->exec('reset --soft branch-temp')
      ->exec('commit --amend -C HEAD')
      ->exec('branch -D branch-temp');
    if ($options['tag']) {
      $merge->tag($options['tag']);
    }
    $result = $merge->run();
    if ($result instanceof Result && !$result->wasSuccessful()) {
      throw new Exception('Failed to merge deployment into the remote branch.');
    }
    $this->say('Code is ready to push');
  }

  /**
   * Attempts to push the build and returns the final result.
   *
   * @param array $options
   *   Options passed from the command.
   *
   * @return \Robo\Result
   *   The final task result.
   */
  protected function getPushResult(array $options) {
    $gitJobs = $this->taskGitStack()
      ->stopOnFail()
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_DEBUG)
      ->push($options['remote'], $options['deploy-branch'] . ':' . $options['remote-branch']);
    return $gitJobs->run();
  }

}
