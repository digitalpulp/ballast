<?php

namespace Ballast\Commands;

use Robo\Collection\Collection;
use Robo\Tasks;
use Robo\Result;
use Robo\Contract\VerbosityThresholdInterface;
use Ballast\Utilities\Config;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * Robo commands that manage setup.
 *
 * phpcs:disable Drupal.Commenting.FunctionComment.ParamMissingDefinition
 *
 * @package Ballast\Commands
 */
class SetupCommands extends Tasks {

  /**
   * Config Utility (singleton).
   *
   * @var \Ballast\Utilities\Config
   */
  protected $config;

  /**
   * Output next steps for Ballast.
   */
  public function setupInstructions(SymfonyStyle $io) {
    $io->title('Getting Started');
    $io->text('If you wish to use Ballast as your local dev setup, run:');
    $io->listing([
      'composer robo setup:prerequisites',
    ]);
    $io->text('If you are part of a team, and this project has been intialized by your lead, run:');
    $io->listing([
      'composer robo setup:project',
      'ahoy launch',
    ]);
    $io->newLine();
    $io->text('Ballast wraps utilities and automation around the excellent ddev-local toolset.');
    $io->text('If you choose not to use `ahoy` you can use ddev directly. See the README.md');
    $io->text('If you are running on macOS see the README.md about performance improvements.');
    $io->text('To launch this site, use `ahoy launch`.  The full list is available by using `ahoy --help`.');
  }

  /**
   * Dispatches prerequisite setup tasks by OS.
   */
  public function setupPrerequisites(SymfonyStyle $io) {
    $this->setConfig();
    $os = php_uname('s');
    switch ($os) {
      case 'Darwin':
        if (!$this->getMacReadiness($io)) {
          return;
        }
        break;

      case 'Linux':
        if (!$this->getLinuxReadiness($io)) {
          return;
        }
        break;

      default:
        $io->error("Unable to determine your operating system.  Manual installation will be required.");
    }
  }

  /**
   * Use this command to setup a newly cloned Ballast project.
   */
  public function setupCloned(SymfonyStyle $io) {
    $this->setConfig();
    if (
      file_exists($this->config->getProjectRoot() . Config::PATH)
      && !$io->confirm('This project has been setup already.  Setup again?')
    ) {
      return;
    }
    $config = [
      'jira_project_key' => NULL,
    ];
    $type = $io->choice(
      'What type of project will this be?',
      ['drupal8', 'drupal9', 'wordpress'],
      'drupal9'
    );
    $docroot = $io->ask('What is the name of your docroot directory?', 'docroot');
    $domain = $io->ask('What local domain would you like to use?');
    if ($type !== 'wordpress') {
      $this->setDrupalSettings($io);
      $config['site_alias_name'] = $io->ask('What is the root name for your drush aliases?');
      $config['site_theme_name'] = $io->ask('What is the directory name for your custom theme?');
      if ($io->confirm("Does your custom theme live in $docroot/themes/custom?")) {
        $config['site_theme_path'] = "/var/www/$docroot/themes/custom";
      }
      else {
        $path = $io->ask('What is the path from the project root to the folder that contains your theme?');
        $config['site_theme_path'] = "/var/www/$path";
      }
      if ($io->confirm('Does your project use Stage File Proxy?')) {
        $config['site_proxy_origin_url'] = $io->ask('What is the url to the file origin site?');
      }
    }
    if ($io->confirm('Does your project have a JIRA issue key?')) {
      $config['jira_project_key'] = $io->ask('What is the JIRA issue key?');
    }
    if ($this->taskWriteToFile($this->config->getProjectRoot() . Config::PATH)
      ->text(Yaml::dump($config))
      ->run()->wasSuccessful()) {
      $io->success('Ballast config initialized.');
    }
    else {
      $io->error('Ballast config failed to initialize.');
    }
    // Now run the setup.
    $ddev_config = "ddev config --project-type=$type --docroot=$docroot --project-name=$domain";
    if ($this->taskExec($ddev_config)->dir($this->config->getProjectRoot())->run()->wasSuccessful()) {
      // Add the front-end container as an additional service.
      $this->setFrontEnd($io);
      if ($io->confirm('Also run setup:project for your own local workflow?')) {
        $this->setupProject($io);
      }
      $io->success('This Ballast project is initialized.');
      $io->note('If you are using Codeship, check the README.md for additional setup. Otherwise you can commit and share the project.');
      return;
    }
    $io->error('This Ballast project failed to initialize.');
  }

  /**
   * Use this command to setup this project before first use.
   */
  public function setupProject(SymfonyStyle $io) {
    $this->setConfig();
    // Set some simple variables for string expansion.
    $drupal = $this->config->getDrupalRoot();
    $project = $this->config->getProjectRoot();
    $this->setPrecommitHooks($io);
    if ($io->confirm('Create a settings.local.php file?')) {
      $overwrite = TRUE;
      if (file_exists("$drupal/sites/default/settings.local.php")) {
        $overwrite = $io->confirm('Overwrite the existing settings.local.php?', FALSE);
      }
      $this->taskFilesystemStack()
        ->copy("$project/setup/drupal/settings.local.php",
          "$drupal/sites/default/settings.local.php", $overwrite)->run();
      if ($this->config->get('site_proxy_origin_url')) {
        $this->taskReplaceInFile("$drupal/sites/default/settings.local.php")
          ->from('{site_proxy_origin_url}')
          ->to($this->config->get('site_proxy_origin_url'))->run();
      }
    }
    $os = php_uname('s');
    switch ($os) {
      case 'Linux':
        $ahoyType = 'linux';
        break;

      case 'Darwin':
        $ahoyType = 'mac';
        break;

    }
    if ($this->getIsInstalled($io, 'ahoy') && isset($ahoyType)) {
      $this->ahoyCommands($io, $ahoyType);
      $io->title("Next Steps");
      $io->text('We recommend using Ahoy to interact with our toolset from here.  Enter `ahoy -h` to see the full list or check the README.');
    }
    else {
      $io->note("Ahoy not found. If you decide later to use our ahoy commands, install ahoy from https://github.com/ahoy-cli/ahoy and the run `composer robo setup:ahoy-commands $ahoyType`");
    }
  }

  /**
   * Use this command to setup NFS on a Mac.
   */
  public function setupNfs(SymfonyStyle $io) {
    $this->setConfig();
    $os = php_uname('s');
    switch ($os) {
      case 'Linux':
        $io->note('NFS is not needed on Linux.');
        break;

      case 'Darwin':
        $dir = dirname($this->config->getProjectRoot());
        $folder = $io->ask('What is the path to your docker sites folder?', $dir);
        $io->text('Running NFS configuration script.');
        $script = $this->config->getProjectRoot() . "/setup/docker/macos_ddev_nfs_setup.sh $folder";
        if (!$this->taskExec($script)->run()->wasSuccessful()) {
          $io->error(
            'The NFS setup script reported an error.  Run this command again after addressing the errors.'
          );
          break;
        }
        $io->text('Verify the NFS mount');
        $this->taskExecStack()
          ->dir($this->config->getProjectRoot())
          ->stopOnFail()
          ->exec('ddev restart')
          ->exec('ddev debug nfsmount')
          ->run();
        if ($io->confirm('Are you satisfied with the verification and ready to enable NFS?')) {
          $this->taskExecStack()
            ->dir($this->config->getProjectRoot())
            ->stopOnFail()
            ->exec('ddev config global --nfs-mount-enabled')
            ->exec('ddev restart')
            ->run();
        }
        break;
    }
  }

  /**
   * Prep a key to be one line using the php container.
   *
   * @param string $key
   *   The path to a key.
   */
  public function keyPrep(SymfonyStyle $io, $key) {
    $this->setConfig();
    $root = $this->config->getProjectRoot();
    $key_contents = file_get_contents("$root/$key");
    $one_line = str_replace(["\r", "\n"], '\\n',
      $key_contents);
    $result = $this->taskWriteToFile("$root/env")
      ->append(TRUE)
      ->line("SSH_PRIVATE_KEY=$one_line")
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_DEBUG)
      ->run();
    if ($result instanceof Result && $result->wasSuccessful()) {
      $io->success("The key has been processed and appended to the env file.");
    }
    else {
      $io->error('Error message: ' . $result->getMessage());
    }
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
   * MacOS Readiness check.
   *
   * @param \Symfony\Component\Console\Style\SymfonyStyle $io
   *   Injected IO object.
   *
   * @return bool
   *   Mac is ready for Ballast.
   */
  protected function getMacReadiness(SymfonyStyle $io) {
    $ready = FALSE;
    $this->setConfig();
    $io->title('Mac Setup for Ballast');
    if ($this->getIsInstalled($io, 'docker-machine')) {
      $io->note('Your system has docker-machine installed.  If you are upgrading from Ballast 2.x or below this check may falsely report readiness. Ballast 3.x uses Docker Desktop which you can install along side docker-machine and run with docker-machine stopped.');
    }
    $required = $this->getRequirements($io, 'mac');
    if (count($required) > 0) {
      $io->warning('Your Mac is missing required software to use Ballast');
      $io->text('The following packages need to be installed:');
      $io->listing(array_column($required, 'name'));
      $io->note('You can use homebrew to install each of these:');
      $commands = [];
      foreach ($required as $settings) {
        $command = '';
        if (!empty($settings['tap'])) {
          $command = 'brew tap ' . $settings['tap'] . '&& ';
        }
        $command .= 'brew install ' . $settings['pkg'];
        $commands[] = $command;
      }
      $io->listing($commands);
    }
    else {
      $io->success("Your Mac has the required software to use Ballast.");
      $ready = TRUE;
    }
    return $ready;
  }

  /**
   * MacOS Initial setup.
   *
   * @param \Symfony\Component\Console\Style\SymfonyStyle $io
   *   Injected IO object.
   *
   * @return bool
   *   Mac is ready for Ballast.
   */
  protected function getLinuxReadiness(SymfonyStyle $io) {
    $ready = FALSE;
    $this->setConfig();
    $io->title('Linux Setup for Ballast');
    $required = $this->getRequirements($io, 'linux');
    if (count($required) > 0) {
      $io->warning('Your system is missing required software to use Ballast');
      $io->text('The following packages need to be installed:');
      $io->listing(array_column($required, 'name'));
      $io->note('Here are urls where you can find more info:');
      $urls = [];
      foreach ($required as $settings) {
        $urls[] = $settings['url'];
      }
      $io->listing($urls);
    }
    else {
      $io->success("Your system has the required software to use Ballast.");
      $ready = TRUE;
    }
    return $ready;
  }

  /**
   * Helper method to move an OS specific ahoy command file into place.
   *
   * @param \Symfony\Component\Console\Style\SymfonyStyle $io
   *   Injected IO object.
   * @param string $os
   *   Designates the operating system. Valid values are: mac, linux.
   */
  public function ahoyCommands(SymfonyStyle $io, $os) {
    // Ahoy is installed.
    $source_path = $this->config->getProjectRoot() . "/setup/ahoy/$os.ahoy.yml";
    $destination_path = $this->config->getProjectRoot() . '/.ahoy.yml';
    $result = $this->taskFilesystemStack()
      ->copy($source_path, $destination_path)
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_DEBUG)
      ->run();
    if ($result instanceof Result && $result->wasSuccessful()) {
      $io->text('Ahoy commands prepared.');
    }
    else {
      $io->error('Unable to move ahoy.yml file into place.');
      $io->text('Error is:');
      $io->text($result->getMessage());
      $io->warning("You will need to copy and move this file yourself from $source_path to $destination_path");
    }
  }

  /**
   * Helper method to add the compose file for front-end to ddev.
   *
   * @param \Symfony\Component\Console\Style\SymfonyStyle $io
   *   Injected IO object.
   */
  protected function setFrontEnd(SymfonyStyle $io) {
    $this->setConfig();
    // Ahoy is installed.
    $source_path = $this->config->getProjectRoot() . '/setup/docker/docker-compose.front-end-yaml-template';
    $destination_path = $this->config->getProjectRoot() . '/.ddev/docker-compose.front-end.yaml';
    $move = $this->taskFilesystemStack()
      ->copy($source_path, $destination_path)
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_DEBUG)
      ->run()->wasSuccessful();
    $placeholders = new Collection();
    $placeholders->add(
      $this->taskReplaceInFile($destination_path)
        ->from('{site_theme_name}')
        ->to($this->config->get('site_theme_name'))
    );
    $placeholders->add($this->taskReplaceInFile($destination_path)
      ->from('{site_theme_path}')
      ->to($this->config->get('site_theme_path'))
    );
    $replace = $placeholders->run()->wasSuccessful();
    if ($move && $replace) {
      $io->text('Front end service added to ddev.');
    }
    else {
      $io->error('Unable to move docker-compose.front-end.yaml file into place.');
      $io->text('Error is:');
      $io->text($move->getMessage());
      $io->warning("You will need to copy and move this file yourself from $source_path to $destination_path");
    }
  }

  /**
   * Install pre-commit hooks.
   *
   * @param \Symfony\Component\Console\Style\SymfonyStyle $io
   *   Injected IO object.
   */
  protected function setPrecommitHooks(SymfonyStyle $io) {
    $this->setConfig();
    $root = $this->config->getProjectRoot();
    if (!file_exists("$root/.git")) {
      $io->error('Git repository not found.  Pre-commit cannot be setup until this project is under version control.');
      return;
    }
    $io->section('Configuring pre-commit linting tool.');
    $collection = $this->collectionBuilder();
    $collection->addTask(
      $this->taskExec('pre-commit install')->dir($this->config->getProjectRoot())
    )->rollback(
      $this->taskExec('pre-commit uninstall')
        ->dir($root)
    );
    // If the JIRA key property is populated, install the commit hook.
    $key = $this->config->get('jira_project_key');
    if (!file_exists("$root/.git/hooks/commit-msg") && !empty($key)) {
      $collection->addTask(
        $this->taskFilesystemStack()
          ->copy("$root/scripts/git/commit-msg-template",
            "$root/scripts/git/commit-msg")
      )->rollback(
        $this->taskFilesystemStack()
          ->remove("$root/scripts/git/commit-msg")
      );
      $collection->addTask(
        $this->taskReplaceInFile("$root/scripts/git/commit-msg")
          ->from('{key}')
          ->to($this->config->get('jira_project_key'))
      );
      $collection->addTask(
        $this->taskFilesystemStack()
          ->rename("$root/scripts/git/commit-msg",
            "$root/.git/hooks/commit-msg")
      );
    }
    $result = $collection->run();
    if ($result instanceof Result && $result->wasSuccessful()) {
      $io->success("Hooks for commit-msg and pre-commit linting have been installed.");
      return TRUE;
    }
    else {
      $io->error("Something went wrong.  Changes have been rolled back.");
      return FALSE;
    }
  }

  /**
   * Copy our additional drupal settings files into place.
   */
  protected function setDrupalSettings(SymfonyStyle $io) {
    $this->setConfig();
    // Set some simple variables for string expansion.
    $drupal = $this->config->getDrupalRoot();
    $project = $this->config->getProjectRoot();
    $collection = $this->collectionBuilder();
    $collection->addTask(
      $this->taskFilesystemStack()
        ->copy("$drupal/sites/default/default.settings.php",
          "$drupal/sites/default/settings.php")
    )->rollback(
      $this->taskFilesystemStack()
        ->remove("$drupal/sites/default/settings.acquia.php")
    );
    $collection->addTask(
      $this->taskFilesystemStack()
        ->copy("$project/setup/drupal/settings.acquia.php",
          "$drupal/sites/default/settings.acquia.php", TRUE)
    )->rollback(
      $this->taskFilesystemStack()
        ->remove("$drupal/sites/default/settings.acquia.php")
    );
    $collection->addTask(
      $this->taskFilesystemStack()
        ->copy("$project/setup/drupal/settings.non-acquia.php",
          "$drupal/sites/default/settings.non-acquia.php", TRUE)
    )->rollback(
      $this->taskFilesystemStack()
        ->remove("$drupal/sites/default/settings.non-acquia.php")
    );
    $collection->addTask(
      $this->taskFilesystemStack()
        ->copy("$project/setup/drupal/services.dev.yml",
          "$drupal/sites/default/services.dev.yml", TRUE)
    )->rollback(
      $this->taskFilesystemStack()
        ->remove("$drupal/sites/default/services.dev.yml")
    );
    if (file_exists("$drupal/sites/default/settings.php") &&
      preg_match(
        '|/\*\sSettings added by robo setup:project|',
        file_get_contents("$drupal/sites/default/settings.php")
      )
    ) {
      $io->text('Robo settings previously appended to existing settings.php');
    }
    else {
      $collection->addTask(
        $this->taskConcat(
          [
            "$drupal/sites/default/settings.php",
            "$project/setup/drupal/settings.append.php",
          ]
        )->to("$drupal/sites/default/settings.php")
      );
    }
    $result = $collection->run();
    if ($result instanceof Result && $result->wasSuccessful()) {
      $this->taskFilesystemStack()->chmod("$drupal/sites/default", 0755)
        ->run();
      $this->taskFilesystemStack()
        ->chmod("$drupal/sites/default/settings.php", 0755)
        ->run();
      $io->success('Drupal settings are configured.');
    }
  }

  /**
   * Checks for installed packages use `which`.
   *
   * @param \Symfony\Component\Console\Style\SymfonyStyle $io
   *   Injected IO object.
   * @param string $package
   *   The package to check.
   *
   * @return bool
   *   True if found.
   */
  protected function getIsInstalled(SymfonyStyle $io, $package) {
    $io->comment("Checking installation of " . $package . "...");
    $result = $this->taskExec('which ' . $package)
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_DEBUG)
      ->printOutput(FALSE)
      ->printMetadata(FALSE)
      ->run();
    $io->newLine();
    if ($result instanceof Result) {
      return $result->wasSuccessful();
    }
    throw new \UnexpectedValueException("taskExec() failed to return a valid Result object in getIsInstalled()");
  }

  /**
   * Get the required packages by machine name.
   *
   * @param \Symfony\Component\Console\Style\SymfonyStyle $io
   *   Injected IO object.
   * @param string $machine
   *   The machine key.
   *
   * @return array
   *   Associative array of requirements keyed by short name.
   */
  protected function getRequirements(SymfonyStyle $io, $machine) {
    // State the requirements in an array.
    // Unset requirements already met to create a installation manifest.
    switch ($machine) {
      case 'mac':
        $required = [
          'ddev' => [
            'name' => 'DDEV Local',
            'tap' => '',
            'pkg' => 'drud/ddev/ddev',
          ],
          'docker' => [
            'name' => 'Docker',
            'tap' => '',
            'pkg' => 'homebrew/cask/docker',
          ],
          'docker-compose' => [
            'name' => 'Docker Compose',
            'tap' => '',
            'pkg' => 'homebrew/cask/docker',
          ],
          'pre-commit' => [
            'name' => 'pre-commit by Yelp',
            'tap' => '',
            'pkg' => FALSE,
          ],
        ];
        break;

      case 'linux':
        $required = [
          'ddev' => [
            'name' => 'DDEV Local',
            'url' => 'https://github.com/drud/ddev',
          ],
          'docker' => [
            'name' => 'Docker',
            'url' => 'https://docs.docker.com/engine/install/',
          ],
          'docker-compose' => [
            'name' => 'Docker Compose',
            'url' => 'https://docs.docker.com/compose/install/',
          ],
          'pre-commit' => [
            'name' => 'pre-commit by Yelp',
            'url' => 'https://pre-commit.com/#install',
          ],
        ];
        break;

      default:
        return [];
    }
    $io->section('Checking for Installed Requirements');
    foreach ($required as $package => $details) {
      if ($this->getIsInstalled($io, $package)) {
        // Installed.  Unset.
        unset($required[$package]);
      }
    }
    return $required;
  }

}
