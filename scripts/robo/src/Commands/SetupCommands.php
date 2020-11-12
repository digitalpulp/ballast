<?php

namespace Ballast\Commands;

use Robo\Tasks;
use Robo\Result;
use Robo\Contract\VerbosityThresholdInterface;
use Ballast\Utilities\Config;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Robo commands that manage setup.
 *
 * phpcs:disable Drupal.Commenting.FunctionComment.ParamMissingDefinition
 *
 * @package Ballast\Commands
 */
class SetupCommands extends Tasks {

  use ProxyTrait;

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
    $io->text('If you wish to use Ballast as your local dev setup, run the following commands:');
    $io->listing([
      'composer robo setup:drupal',
      'composer robo setup:prerequisites',
    ]);
    $io->newLine();
    $io->text('If you have previously installed Ballast on this machine you are now ready to cast-off and launch.');
    $io->text('To launch this Drupal site, use the following commands:');
    $io->listing([
      'ahoy cast-off',
      'or: ahoy launch  (if you have already called `ahoy cast-off` in another project.)',
      'ahoy rebuild (if you have Drush aliases ready in `drush/sites`)',
    ]);
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
        $this->setPrecommitHooks($io);
        $this->setAhoyCommands($io, 'mac');
        $io->note('All the docker projects need to be in the same parent folder.  Because of the nature of NFS, this folder cannot contain any older vagrant based projects. If needed, create a directory and move this project before continuing.');

        $io->title("Next Steps");
        $io->text('We will be using Ahoy to interact with our toolset from here.  Enter `ahoy -h` to see the full list or check the README.');
        $io->text('To finish setting up Ballast for the first time and launch this Drupal site, use the following commands:');
        $io->definitionList([
          'ahoy harbor' => 'Creates and configures docker containers that are part of our infrastructure. Only needs to be run once.',
          'ahoy cast-off' => 'Starts the Ballast system. Only needs to be run once after you start up your Mac.',
          'ahoy rebuild' => 'Pulls a database copy from a remote server.  Uses the aliases set in `drush/sites`',
        ]);
        break;

      case 'Linux':
        if (!$this->getLinuxReadiness($io)) {
          return;
        }
        $this->setPrecommitHooks($io);
        $this->setAhoyCommands($io, 'linux');
        $boot_task = $this->setProxyContainer($io);
        $result = $boot_task->run();
        if ($result instanceof Result && $result->wasSuccessful()) {
          $io->success('Proxy container is setup.');
          return TRUE;
        }
        else {
          $io->error('Could not setup your http-proxy container.');
          $io->note([
            'Check that the docker system is set to run as service',
            'You may find information on the man page, `man dockerd`',
            'or use a search engine to search for your',
            'linux distro name + `dockerd`',
          ]);
        }
        $io->title("Next Steps");
        $io->text('We will be using Ahoy to interact with our toolset from here.  Enter `ahoy -h` to see the full list or check the README.');
        $io->text('For example, `ahoy launch` will bring up the site for this project.');
        break;

      default:
        $io->error("Unable to determine your operating system.  Manual installation will be required.");
        return;
    }
  }

  /**
   * Copy values from the config.yml and move drupal settings files into place.
   */
  public function setupDrupal(SymfonyStyle $io) {
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
        '|/\*\sSettings added by robo setup:drupal|',
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
      $this->taskFilesystemStack()->chmod("$drupal/sites/default", 0755)
        ->run();
      $this->taskFilesystemStack()
        ->chmod("$drupal/sites/default/settings.php", 0755)
        ->run();
      $io->success('Drupal settings are configured.');
    }
  }

  /**
   * Prep a key to be one line using the php container.
   *
   * @param string $key
   *   The key.
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
    $required = $this->getRequirements($io, 'mac');
    if (count($required) > 0) {
      $io->warning('Your Mac is missing required software to use Ballast');
      $io->text('The following packages need to be installed:');
      $io->listing(array_column($required, 'name'));
      $io->note('You can use homebrew to install each of these:');
      $commands = [];
      foreach ($required as $package => $settings) {
        $command = '';
        if (!empty($settings['tap'])) {
          $command = 'brew tap ' . $settings['tap'] . '&& ';
        }
        $command .= 'brew ' . ($settings['cask'] ? 'cask ' : '') . 'install ' . $package;
        $commands[] = $command;
      }
      $io->listing($commands);
    }
    else {
      $this->io->success("Your Mac has the required software to use Ballast.");
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
   * @param string $system
   *   Designates the system.
   */
  protected function setAhoyCommands(SymfonyStyle $io, $system) {
    // Ahoy is installed.
    $source_path = $this->config->getProjectRoot() . "/setup/ahoy/$system.ahoy.yml";
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
    }
    else {
      $io->error("Something went wrong.  Changes have been rolled back.");
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
    $result = $this->taskExec('which -s ' . $package)
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
        break;

      case 'linux':
        $required = [
          'ahoy' => [
            'name' => 'Ahoy',
            'url' => 'https://github.com/ahoy-cli/ahoy',
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
