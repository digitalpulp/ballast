<?php

namespace Ballast\CommandSupport;

use Robo\Tasks;
use Robo\Result;
use Robo\Contract\VerbosityThresholdInterface;
use Ballast\Utilities\Config;
use Symfony\Component\Finder\Finder;


class Deploy extends Tasks {

  /**
   * Config Utility (setter injected).
   *
   * @var \Ballast\Utilities\Config
   */
  protected $config;

  /**
   * Startup Command support object (setter injected).
   *
   * @var \Ballast\CommandSupport\StartUp
   */
  protected $startUp;

  /**
   * Set the config utility object.
   *
   * @param \Ballast\Utilities\Config $config
   */
  public function setConfig(Config $config) {
    $this->config = $config;
  }

  /**
   * Set the StartUp command support object
   *
   * @param \Ballast\CommandSupport\StartUp $startUp
   */
  public function setStartUp($startUp) {
    $this->startUp = $startUp;
  }

  /**
   * Insures that appropriate options are present.
   *
   * @param array $options
   *   Options passed from the command.
   */
  public function setDeploymentOptions(array &$options) {
    $options['remote'] = isset($options['remote']) ? $options['remote'] :getenv('DEPLOY_TARGET');
    $options['branch'] = isset($options['branch']) ? $options['branch'] : getenv('CI_BRANCH');
    $options['remote-branch'] = isset($options['remote-branch']) ? $options['remote-branch'] : $options['branch'];
    $options['deploy-branch'] = $options['remote-branch'] . '-deploy';
    $options['build_id'] = isset($options['build_id']) ? $options['build_id'] : substr(getenv('CI_COMMIT_ID'), 0, 7);
    // A target is required.
    if (empty($options['remote'])) {
      // We need a repository target.
      throw new \UnexpectedValueException('Environment variable $DEPLOY_TARGET must be set before deployment.');
    }
    // As is a branch.
    if (empty($options['branch'])) {
      throw new \UnexpectedValueException('A branch must be specified.');
    }
    // There should also be a commit-message.
    if (empty($options['commit-msg'])) {
      $options['commit-msg'] = 'Deployment built for ' . $this->config->get('site_shortname');
      if (!empty($options['build_id'])) {
        $options['commit-msg'] .= ' Build ID: ' . $options['build_id'];
      }
    }
    $remote_name = $this->config->get('site_alias_name');
    // Store for later use.
    $options['remote_name'] = $remote_name;
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
  public function setDeploymentVersionControl(array $options) {
    // Get from value set via codeship env.encrypted or other means.
    /* https://documentation.codeship.com/pro/builds-and-configuration/environment-variables/#encrypted-environment-variables */
    $git_name = getenv('GIT_NAME') ? getenv('GIT_NAME') : 'Deployment';
    $git_mail = getenv('GIT_EMAIL') ? getenv('GIT_EMAIL') : 'deploy@example.com';
    $remote_url = $options['remote'];
    $remote_name = $options['remote_name'];
    $this->say("Will push to git remote $remote_name at $remote_url");
    $result = $this->taskExecStack()
      ->dir($this->config->getProjectRoot())
      ->stopOnFail()
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_DEBUG)
      ->exec("git remote add $remote_name $remote_url")
      ->exec("git config --global user.email $git_mail")
      ->exec("git config --global user.name $git_name")
      ->run();
    if ($result instanceof Result && !$result->wasSuccessful()) {
      throw new \Exception('Git config failed to set.');
    }
    // We also need to get the keys provided via encrypted environment and add
    // them into the appropriate files with the appropriate permissions.
    $git_host = getenv('GIT_KNOWN_HOST');
    $ssh_key = getenv('SSH_PRIVATE_KEY');
    if (empty($git_host)) {
      throw new \UnexpectedValueException('A git host server key must be configured in the encrypted environment variables.');
    }
    if (empty($ssh_key)) {
      throw new \UnexpectedValueException('An ssh key must be configured in the encrypted environment variables.');
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
      throw new \Exception('Unable to set git host key.');
    }

  }

  /**
   * Function waits until front-end signals init is complete.
   */
  public function getDeploymentDependencies() {
    $ready = FALSE;
    $building = FALSE;
    $compiling = FALSE;
    $flag_npm_install = sprintf('%s/themes/custom/%s/BUILDING.txt', $this->config->getDrupalRoot(), $this->config->get('site_theme_name'));
    $flag_gulp_build = sprintf('%s/themes/custom/%s/COMPILING.TXT', $this->config->getDrupalRoot(), $this->config->get('site_theme_name'));
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
          throw new \UnexpectedValueException('After a full round of monitoring, node modules never began to compile.');
        }
        if (!$compiling && file_exists($flag_gulp_build)) {
          $compiling = TRUE;
          $this->say('Node modules have finished compiling. Gulp is compiling the theme.');
        }
      }

      $ready = $this->startUp->getFrontEndStatus(TRUE);
    }
    $this->startUp->setClearFrontEndFlags();
  }

  /**
   * Removes files and directories that should not be deployed.
   *
   * Also replaces .gitignore with deployment specific versions.
   *
   * @throws \Exception
   *   Throws an exception if the files are unable to be modified.
   */
  public function getSanitizedBuild() {
    $this->say('Sanitizing artifact...');
    $this->say('Finding .git subdirectories...');
    $git = new Finder();
    $git
      ->in([$this->config->getProjectRoot() . '/vendor', $this->config->getProjectRoot() . '/docroot'])
      ->directories()
      ->ignoreDotFiles(FALSE)
      ->ignoreVCS(FALSE)
      ->name('/^\.git$/');
    $this->say($git->count() . ' .git directories found');

    $this->say('Finding any CHANGELOG.txt');
    $changelog = new Finder();
    $changelog
      ->in($this->config->getProjectRoot())
      ->files()
      ->name('CHANGELOG.txt');
    $this->say($changelog->count() . ' CHANGELOG.txt files found');

    $this->say('Finding .gitignore in themes');
    $theme_ignores = new Finder();
    $theme_ignores
      ->in($this->config->getDrupalRoot() . '/themes/custom')
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
        ->copy($this->config->getProjectRoot() . '/setup/deploy-gitignore',
          $this->config->getProjectRoot() . '/.gitignore', TRUE)
    );
    $result = $collection->run();
    if ($result instanceof Result && !$result->wasSuccessful()) {
      throw new \Exception('Unable to sanizize the build.');
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
  public function setDeploymentCommit(array $options) {
    $gitJobs = $this->taskGitStack()
      ->stopOnFail()
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_DEBUG)
      ->checkout($options['branch'])
      ->add('-A')
      ->commit($options['commit-msg']);
    $result = $gitJobs->run();
    if ($result instanceof Result && !$result->wasSuccessful()) {
      throw new \Exception('Git commit failed.');
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
  public function setCleanMerge(array $options) {
    // We need some simple variables for expansion in a string
    $remote = $options['remote_name'];
    $target = $remote . '/' . $options['remote-branch'];
    $message = 'Merge to remote: ' . $options['commit-msg'];
    $local = $options['deploy-branch'];
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
        throw new \Exception('Unable to checkout or create a deployment branch.');
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
      throw new \Exception('Failed to merge deployment into the remote branch.');
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
  public function getPushResult(array $options) {
    $gitJobs = $this->taskGitStack()
      ->stopOnFail()
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_DEBUG)
      ->push($options['remote_name'], $options['deploy-branch'] . ':' . $options['remote-branch']);
    return $gitJobs->run();
  }

}
