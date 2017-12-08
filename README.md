# Ballast
A local development toolset developed with the support of [Digital Pulp](https://www.digitalpulp.com).

Key contributors:
  - [Shawn Duncan](https://github.com/FatherShawn)
  - [Nick Maine](https://github.com/nickmaine)

## A Composer template for Drupal projects with Docker

This project template automates [Docker](https://www.docker.com/) based local development with
with [Drupal Composer](https://github.com/drupal-composer/drupal-project) workflows. The local development automation is
currently only optimized for macOS but Linux and possibly Windows
may follow.

- Site dependencies are managed with [Composer](https://getcomposer.org/).
- Setup and management of Docker is automated.

## Getting Started

1. First you need to [install composer](https://getcomposer.org/doc/00-intro.md#installation-linux-unix-osx).
   _Note: The instructions below refer to the [global composer installation](https://getcomposer.org/doc/00-intro.md#globally).
     You might need to replace `composer` with `php composer.phar`
     (or similar) for your setup._

2. MacOS users also need to have [Homebrew](https://brew.sh/).

3. Your Docker Sites need a home.
    * Choose or create a file folder to hold all the site folders for projects
managed with this approach.
    * If you have any existing files exported via
NFS they must not be in the chosen folder.
    * The easiest way forward is
to create a new folder such as `~/DockerSites`.

4. In the folder just chosen or create, `composer create-project digitalpulp/ballast-drupal  your_project`.

## Initial Setup
_After the initial setup, you should delete the Initial Setup section of
this README._

### Edit `setup/config.yml`
There are some project specific values that should be set in this file.

### Edit `composer.json`
There is also a project specific value to set here, which is the path to
bower components in the custom theme file. Edit the *extra* section to
add a path to the theme `bower_components` directory.  An example entry
is shown as the last line in the snippet below:

```json
{
"extra": {
        "installer-types": ["bower-asset"],
        "installer-paths": {
            "docroot/core": ["type:drupal-core"],
            "docroot/libraries/{$name}": ["type:drupal-library"],
            "docroot/modules/contrib/{$name}": ["type:drupal-module"],
            "docroot/profiles/contrib/{$name}": ["type:drupal-profile"],
            "docroot/themes/contrib/{$name}": ["type:drupal-theme"],
            "drush/contrib/{$name}": ["type:drupal-drush"],
            "docroot/themes/custom/theme_name/bower_components/{$name}": ["type:bower-asset"]
        }
    }
}
```
### Initial Composer Install

You may wish to require an initial line up of contributed modules. (See
Updates and Maintenance below). If you are not adding modules at first,
you may run `composer update nothing` to generate an initial
`composer.lock` file.  Either way, committing the result will speed
setup for other members of your team.

### Prepare for Codeship Pro

We use [Codeship Pro](https://codeship.com/pricing/pro) to deploy
projects built on this template.  A free tier is available. All commits
on the `develop` branch will be built and deployed.

#### Docker Environment Variables
There are two environment variables and one path which need to be set in
`codeship-services.yml` found in the root of the project repo:

-  front-end : environment : THEME_NAME - The folder/machine name of
   your custom theme. This folder should be present in
   `docroot/themes/custom`.
-  front-end : working_dir - set this value to the full path in the container to
   the theme folder.  Usually `/var/www/docroot/themes/custom/yourtheme`
-  deploy : environment : DEPLOY_TARGET - The url of the git remote to
   which Codeship will push the build artifact.

#### Encrypted Environment Variables
Deployment credentials should not be stored in the repo in the clear, but Codeship will decrypt these environment variables on each build. You will need to install the Codeship CLI tool [Jet](https://documentation.codeship.com/pro/builds-and-configuration/cli/#installing-jet) to accomplish these steps.

1. Create a new Codeship Pro project.
2. Open Project Settings and browse to the _General_ tab.
3. Scroll down and download the _AES Key_.
4. Move this key into the project root and rename it `codeship.aes`
5. Create a new file named `env` (Both this file and `codeship.aes` are
   set to be ignored by git).
6. Copy or create a private key that matches the public key installed in
   your target git remote to the project root.
7. Use the ahoy commands to bring up the local project.
8. Use advanced command `ahoy key-prep private_key` to get your private
   key in a one-line format
9. Execute `ssh user@git_remote_url` if you have not accessed this git
   remote before to add the remote to your `~/.ssh/known_hosts`.
10. Define the environment variables in `env` copying the one line
    private key, the appropriate line from `~/.ssh/known_hosts`, and the
    name and email to use when commiting the build like this:
    ```
    SSH_PRIVATE_KEY=one-line-key copied from the terminal
    GIT_KNOWN_HOST=entire line matching the git remote copied from `~/.ssh/known_hosts`
    GIT_NAME=Codeship-Deploy
    GIT_EMAIL=name@example.com
    ```
11. [Encrypt](https://documentation.codeship.com/pro/builds-and-configuration/environment-variables/#encrypted-environment-variables) the file using Jet: `jet encrypt env env.encrypted`
12. Remove or move the key created in step 6 - **do not commit** the private key!
13. Commit `env.encrypted` to the repo.

## Install the Project
```
composer install
```
All docker dependencies and Drupal core dependencies along with Drupal
core will be installed.

You should commit all files not excluded by the .gitignore file.

## What does the template do?

When installing the given `composer.json` some tasks are taken care of:

* Drupal will be installed in the `docroot`-directory.
* Autoloader is implemented to use the generated composer autoloader in
  `vendor/autoload.php`, instead of the one provided by Drupal
  (`docroot/vendor/autoload.php`).
* Modules (packages of type `drupal-module`) will be placed
  in `docroot/modules/contrib/`
* Theme (packages of type `drupal-theme`) will be placed
  in `docroot/themes/contrib/`
* Profiles (packages of type `drupal-profile`) will be placed
  in `docroot/profiles/contrib/`
* Creates default writable versions of `settings.php`
  and `services.yml`.
* Creates `docroot/sites/default/files`-directory.
* Latest version of drush is installed locally for use
  at `vendor/bin/drush`.
* Latest version of DrupalConsole is installed locally for use
  at `vendor/bin/drupal`.
* The local machine is checked for dependencies to run the docker
  development setup.  Any missing dependencies are installed
  via homebrew. The following are required for Mac:
    * Ahoy
    * xhyve
    * xhyve Driver for Docker Machine
    * Docker
    * Docker Compose
    * pre-commit by Yelp
    * Docker Machine NFS
* A docker based http-proxy & DNS service is created such that any
  docker container with host name ending in `.dpulp` has traffic routed
  from the host to the proxy.  No editing of /etc/hosts required for
  new projects.

## Updates and Maintenance

### Updating Drupal Core

This project will attempt to keep all of your Drupal Core files
up-to-date; the project [drupal-composer/drupal-scaffold](https://github.com/drupal-composer/drupal-scaffold) is used
to ensure that your scaffold files are updated every time drupal/core
is updated. If you customize any of the "scaffolding" files (commonly
`.htaccess`), you may need to merge conflicts if any of your modified
files are updated in a new release of Drupal core.

Follow the steps below to update your core files.

1. Run `composer update drupal/core --with-dependencies` to update
   Drupal Core and its dependencies.
2. Run `git diff` to determine if any of the scaffolding files have
   changed. Review the files for any changes and restore any
   customizations to `.htaccess` or `robots.txt`.
3. Commit everything all together in a single commit, so `docroot` will
   remain in sync with the `core` when checking out branches or running
   `git bisect`.
4. In the event that there are non-trivial conflicts in step 2, you may
   wish to perform these steps on a branch, and use `git merge`
   to combine the updated core files with your customized files. This
   facilitates the use of a [three-way merge tool such as kdiff3](http://www.gitshah.com/2010/12/how-to-setup-kdiff-as-diff-tool-for-git.html). This
   setup is not necessary if your changes are simple;
   keeping all of your modifications at the beginning or end of the file
   is a good strategy to keep merges easy.

### Updating and maintaining `composer.json`

At the _Managing Drupal Projects with Composer_ BOF at DrupalCon
Baltimore, one of the common pain points was merge conflicts in
`composer.json` and `composer.lock`.  It was the strong consensus of
those gathered that for development teams, there should be one
designated maintainer on the team for these files.  New modules, updates
and so forth should be requested from the maintainer, who is generally
the project lead.

With `composer require ...` you can download new dependencies, including
Drupal contributed modules to your installation. To install the latest
versions of multiple modules:

```
composer require drupal/block_visibility_groups drupal/config_split drupal/easy_breadcrumb drupal/focal_point drupal/media_entity_image drupal/media_entity_browser drupal/field_formatter drupal/paragraphs drupal/inline_entity_form drupal/pathauto drupal/page_manager drupal/viewsreference
```

You also can require bower components:

```
composer require bower-asset/formstone
```
### Local Developement Commands
The docker best practice is to work in the host and send commands to a
container when needed.  This project uses [Ahoy](https://github.com/ahoy-cli/ahoy) as an abstraction tool to
further simplify this flow for developers. Ahoy commands work anywhere
at or below the root directory of the project.

- `ahoy harbor` -  Build the harbor for your docks.  Run this command
  _once_ after the _first time_ you `composer install` a dp-docker project.
- `ahoy cast-off` - Launch the global tools needed for local
  development. Run this command once after you boot your computer.
- `ahoy launch` - Launch this project site.
- `ahoy dock` - Stops this project site and 'returns to port.'
- `ahoy drush command` - Executes _command_ via drush in the site.
- `ahoy drupal command` - Executes _command_ via drupal console
  in the site.
- `ahoy gulp command` - Executes _command_ via gulp in the site theme
  folder.
- `ahoy npm command` - Executes _command_ via npm in the site theme
  folder.
- `ahoy npm-update` - Runs 'npm install' and 'npm-shrinkwrap' in the
  site theme folder.
- `ahoy compile` - Compile the site theme assets.
- `ahoy rebuild env` - Sync with a server database and compile front
  end. Pass an environment argument to use with the drush alias
  (@shortname.env)



### Generate `composer.json` from existing project

With using [the "Composer Generate" drush extension](https://www.drupal.org/project/composer_generate)
you can now generate a basic `composer.json` file from an existing
project. Note that the generated `composer.json` will differ from this
project's file. We recommend comparing the resulting output with this
project's and editing the composer.json to merge them by hand.


## FAQ

### Should I commit the contrib modules I download?

Composer recommends **no**. They provide [argumentation against but also
workrounds if a project decides to do it anyway](https://getcomposer.org/doc/faqs/should-i-commit-the-dependencies-in-my-vendor-directory.md).

### Should I commit the scaffolding files?

The [drupal-scaffold](https://github.com/drupal-composer/drupal-scaffold) plugin can download the scaffold files (like
index.php, update.php, …) to the docroot/ directory of your project. We
generally commit these. If you have not customized those files you could
choose to not check them into your version control system (e.g. git). If
that is the case for your project it might be convenient to
automatically run the drupal-scaffold plugin after every install or
update of your project. You can achieve that by registering
`@drupal-scaffold` as post-install and post-update command in your
composer.json:

```json
{
"scripts": {
    "drupal-scaffold": "DrupalComposer\\DrupalScaffold\\Plugin::scaffold",
    "post-install-cmd": [
        "@drupal-scaffold",
        "..."
    ],
    "post-update-cmd": [
        "@drupal-scaffold",
        "..."
    ]
  }
}
```
### How can I apply patches to downloaded modules?

If you need to apply patches (depending on the project being modified, a
pull request is often a better solution), you can do so with the
[composer-patches](https://github.com/cweagans/composer-patches) plugin.

To add a patch to drupal module foobar insert the patches section in the
extra section of composer.json:
```json
{
"extra": {
    "patches": {
        "drupal/foobar": {
            "Patch description": "URL to patch"
        }
    }
  }
}
```

## Appreciation
We are grateful for the following open source projects that made this project possible!

- [Drupal](https://www.drupal.org/)
- [Drupal Composer Project](https://github.com/drupal-composer/drupal-project)
- [Drupal Docker](http://www.drupaldocker.org/)
- [Composer](https://getcomposer.org)
- [Robo](http://robo.li/)
- [Ahoy](http://www.ahoycli.com/en/latest/)
