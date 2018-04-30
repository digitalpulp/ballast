<?php

namespace Ballast\Commands;

use Robo\Tasks;
use Robo\Result;
use Robo\Contract\VerbosityThresholdInterface;
use Ballast\Utilities\Config;
use UnexpectedValueException;

/**
 * Robo commands that manage setup.
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
   * Dispatches prerequisite setup tasks by OS.
   */
  public function setupPrerequisites() {
    $isDev = getenv('COMPOSER_DEV_MODE');
    if (!$isDev) {
      // This is a production build - do not build the local dev environment.
      return;
    }
    $this->setConfig();
    switch (php_uname('s')) {
      case 'Darwin':
        $this->setMacRequirements();
        $this->setPrecommitHooks();
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
   * Copy values from the config.yml and move drupal settings files into place.
   */
  public function setupDrupal() {
    $isDev = getenv('COMPOSER_DEV_MODE');
    if (!$isDev) {
      // This is a prod build, this all should be in the repo.
      return;
    }
    $this->setConfig();
    // Set some simple variables for string expansion.
    $drupal = $this->config->getDrupalRoot();
    $project = $this->config->getProjectRoot();
    $this->taskFilesystemStack()->chmod("$drupal/sites/default", 0755)
      ->run();
    $this->taskFilesystemStack()
      ->chmod("$drupal/sites/default/settings.php", 0755)
      ->run();
    $collection = $this->collectionBuilder();
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
        ->copy("$project/setup/drupal/settings.local.php",
          "$drupal/sites/default/settings.local.php", TRUE)
    )->rollback(
      $this->taskFilesystemStack()
        ->remove("$drupal/sites/default/settings.local.php")
    );
    $collection->addTask(
      $this->taskFilesystemStack()
        ->copy("$project/setup/drupal/services.dev.yml",
          "$drupal/sites/default/services.dev.yml", TRUE)
    )->rollback(
      $this->taskFilesystemStack()
        ->remove("$drupal/sites/default/services.dev.yml")
    );
    $collection->addTask(
      $this->taskReplaceInFile("$drupal/sites/default/settings.local.php")
        ->from('{site_shortname}')
        ->to($this->config->get('site_shortname'))
    );
    $collection->addTask(
      $this->taskReplaceInFile("$drupal/sites/default/settings.local.php")
        ->from('{site_proxy_origin_url}')
        ->to($this->config->get('site_proxy_origin_url'))
    );
    if (file_exists("$drupal/sites/default/settings.php") &&
      !preg_match(
        '|\/\*\sSettings added by robo setup:drupal|',
        file_get_contents("$drupal/sites/default/settings.php")
      )
    ) {
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
      $this->io()->success('Drupal settings are configured.');
    }
  }

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
    $result = $this->taskWriteToFile("$root/env")
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
   * MacOS Initial setup.
   */
  protected function setMacRequirements() {
    $this->setConfig();
    $this->io()->title('Mac Setup for Ballast');
    $result = $this->taskFilesystemStack()
      ->copy($this->config->getProjectRoot() . '/setup/ahoy/mac.ahoy.yml', $this->config->getProjectRoot() . '/.ahoy.yml')
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_DEBUG)
      ->run();
    if ($result instanceof Result && $result->wasSuccessful()) {
      $this->io()->text('Ahoy commands prepared.');
    }
    else {
      $this->io()->error('Unable to move ahoy.yml file into place.');
      $this->io()->text('Error is:');
      $this->io()->text($result->getMessage());
    }
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
          $this->taskExec('brew ' . ($settings['cask'] ? 'cask ' : '') . 'install ' . $package)
        )->rollback(
          $this->taskExec('brew ' . ($settings['cask'] ? 'cask ' : '') . "uninstall $package")
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
  protected function setPrecommitHooks() {
    $this->setConfig();
    $root = $this->config->getProjectRoot();
    $this->io()->section('Configuring pre-commit linting tool.');
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
      $this->io->success("Hooks for commit-msg and pre-commit linting have been installed.");
    }
    else {
      $this->io()
        ->error("Something went wrong.  Changes have been rolled back.");
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

}
