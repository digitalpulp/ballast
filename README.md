# Ballast
A local development toolset developed with the support of [Digital Pulp](https://www.digitalpulp.com).

Key contributors:
  - [Shawn Duncan](https://github.com/FatherShawn)
  - [Nick Maine](https://github.com/nickmaine)

## A Composer template for Drupal projects with Docker

This project template automates [Docker](https://www.docker.com/) based local development with [Drupal Composer](https://github.com/drupal-composer/drupal-project) workflows. The local development automation is
currently only optimized for macOS but Linux and possibly Windows
may follow.

- Site dependencies are managed with [Composer](https://getcomposer.org/).
- Setup and management of Docker is automated.

## Getting Started

1. First you need to [install composer](https://getcomposer.org/doc/00-intro.md#installation-linux-unix-osx).
   _Note: The instructions below refer to the [global composer installation](https://getcomposer.org/doc/00-intro.md#globally).
     You might need to replace `composer` with `php composer.phar`
     (or similar) for your setup._

2. Ballast will check your system for needed software.  If anything is missing, a list of missing packages will be
provided.

2. OS Specific Notes:

- **macOS**: Your Docker Sites need a home.
    * Choose or create a file folder to hold all the site folders for projects
managed with this approach.
    * If you have any existing files exported via
NFS they must not be in the chosen folder.
    * You must also not choose a folder that is a child of an existing
    NFS export.
    * The easiest way forward is
to create a new folder such as `~/DockerSites`.
- **Linux**: File permissions are simplest if your user belongs to the same _group_ as the webserver.
Nginx runs as group id 82.  If this group id does not exist, you should create it and add your user to it.
Setting any files that need to be writeable can then be set to 775 (group writeable) so they are writeable by
Drupal. You will need to configure your system to forward all requests for `*.test` to the loop back address
(localhost). We recommend using [dnsmasq](http://www.thekelleys.org.uk/dnsmasq/doc.html), which is well known
and should be available via your package manager.  The key task is to set `address=/test/127.0.0.1` in the
dnsmasq configuration. Here are some links to helpful blog posts for some common
flavors of Linux:
    * [Ubuntu](https://askubuntu.com/a/1031896)
    * [Fedora](https://brunopaz.dev/blog/setup-a-local-dns-server-for-your-projects-on-linux-with-dnsmasq)
    * [Arch](https://coderwall.com/p/rwpesa/set-up-wildcard-subdomains-with-dnsmasq-on-archlinux)

- **Windows Linux Subsystem**: Build and manage your Ballast sites within Linux.  A Windows equivalent to dnsmasq
appears to be [Acrylic DNS Proxy](http://mayakron.altervista.org/support/acrylic/Home.htm) as described in
[Setting Up A Windows 10 Development Environment with WSL, PHP & Laravel](https://jackwhiting.co.uk/posts/setting-up-a-windows-10-development-environment-with-wsl-php-laravel/)
or if the number of sites are limited, the local FQDN, `our-site.test`, could be pointed to the loopback address in your
[hosts file](https://www.onmsft.com/how-to/how-to-modify-your-hosts-file-in-windows-10-and-why-you-might-want-to):
```
127.0.0.1       our-site.test
```

## Managing Theme Tasks
There are ahoy commands for running theme tasks in the front-end container, so you can choose to not install node on
your host. When you first setup a site with an established theme, you will probably need to run
`ahoy npm install --no-save` to install the node based theme tools.  Additional ahoy commands for front-end task can be
found via `ahoy --help`.

## Initial Setup
_After the initial setup, you should delete the Initial Setup section of
this README._

In the folder chosen or created under _Getting Started_, `composer create-project digitalpulp/ballast  your_project`.

### Edit `setup/config.yml`
There are some project specific values that should be set in this file. The
default location for custom themes is `docroot/themes/custom`. This can be
altered by adding a `site_theme_path` key to this file and setting it to the
relative path from the project root to parent directory for custom themes.

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

In addition, an additional property is available for the `drupal-scaffold` key in the `extra` property:
```json
{
"file-mapping": {
        "[web-root]/.htaccess": false,
        "[web-root]/.eslintrc.json": false,
        "[web-root]/.ht.router.php": false,
        "[web-root]/INSTALL.txt": false,
        "[web-root]/README.txt": false,
        "[web-root]/autoload.php": false,
        "[web-root]/example.gitignore": false,
        "[web-root]/index.php": false,
        "[web-root]/robots.txt": false,
        "[web-root]/update.php": false,
        "[web-root]/web.config": false,
        "[web-root]/sites/default/settings.php": false
      }
}
```
These will block that file from being changed when core is updated or added to the project.

### Initial Composer Install

You may wish to require an initial line up of contributed modules. (See
Updates and Maintenance below). If you are not adding modules at first,
you may run `composer update nothing` to generate an initial
`composer.lock` file.  Either way, committing the result will speed
setup for other members of your team.

### Set a node version in your custom theme.
The front-end container expects a `.node-version` file in the theme directory. See the [nodenv documentation](https://github.com/nodenv/nodenv#nodenv-local).

### Prepare for Codeship Pro

We use [Codeship Pro](https://codeship.com/pricing/pro) to deploy
projects built on this template.  A free tier is available. All commits
on the `develop` branch will be built and deployed.

#### Docker Environment Variables
There are two environment variables and one path which need to be set in
`codeship-services.yml` found in the root of the project repo:

-  front-end : environment : THEME_NAME - The folder/machine name of your custom
   theme. This folder should be present in `docroot/themes/custom`. If you set
   `site_theme_path` in `setup/config.yml` then also set THEME_PATH here to the
   same value.
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
   your target git remote to the project root or to a `/keys` directory in the project.
7. Use the ahoy commands to bring up the local project.
8. Use advanced command `ahoy key-prep path/to/private_key` to get your private
   key in a one-line format and appended to the `env` file.
9. Execute `ssh user@git_remote_url` if you have not accessed this git
   remote before to add the remote to your `~/.ssh/known_hosts`.
10. Define the environment variables in `env` copying the appropriate line from `~/.ssh/known_hosts`, and the
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

### Advanced `ahoy` commands for Tech Leads
There are some additional commands in the `ahoy.yml` file marked "Advanced" which do
not appear in response to `ahoy --help`  These are intended for tech leads that may need
to shell in the docker container for some purpose.

### Using a Passphrase protected key in the CI Service

Some projects, such as ecommerce projects, should have all ssh keys
protected with a passphrase.  This is not particularly an issue for keys
used by humans, but the key used by a continuous integration service
requires automation.  The approach used here assumes that the CI
environment is using our containers and is therefore created for each
build. This example expects the CI service to be Codeship, which you
can adapt if you use a different service.

#### Additional Environment Variables
1. Add the passphrase into the `env` file described above as
   `SSHPASS=key` and re-encrypt the file.
1. Add an environment variable to `environment` section of the `deploy`
   service in the `codeship-services.yml` file:
```
GIT_SSH_COMMAND: SSH_AUTH_SOCK=/root/.ssh/ssh_auth_sock ssh
```

#### Manually Connect Once
If the key in question has never been used to authenticate to the remote
git service, ssh-agent will still prompt for the passphrase as discussed
in this [StackExchange comment](https://superuser.com/a/1309412/206941).
Use `ssh -i` to connect once to the git service from the command line on
your local using the CI key.  Supply the passphrase at the prompt and
the git service will be primed for use by your CI user.

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
    * VirtualBox
    * Docker
    * Docker Compose
    * pre-commit by Yelp
    * Docker Machine NFS
* A docker based http-proxy & DNS service is created such that any
  docker container with host name ending in `.test` has traffic routed
  from the host to the proxy.  No editing of /etc/hosts required for
  new projects.

## Updates and Maintenance

### Updating Drupal Core

This project will attempt to keep all of your Drupal Core files
up-to-date; the project [drupal/core-composer-scaffold](https://github.com/drupal/core-composer-scaffold) is used
to ensure that your scaffold files are updated every time drupal/core
is updated. If you customize any of the "scaffolding" files (commonly
`.htaccess`), you may need to merge conflicts if any of your modified
files are updated in a new release of Drupal core. See the `drupal-scaffold` section above
to block updates to any of these files.

Follow the steps below to update your core files.

1.  Run `composer update drupal/core-recommended drupal/core-composer-scaffold drupal/core-dev --with-all-dependencies` to update
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

The stability minimum is set to stable.  You will need to flag specific packages if you need a lower stability.

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
