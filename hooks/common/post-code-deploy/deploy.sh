#!/bin/sh
#
# Cloud Hook: deploy
#
# Runs common deployment tasks.

site="$1"
target_env="$2"

# Change working directory.
cd /var/www/html/${site}.${target_env}/docroot/sites/default
# Maintenance on.
/var/www/html/${site}.${target_env}/vendor/bin/drush sset system.maintenance_mode 1 -y

# Update database.
/var/www/html/${site}.${target_env}/vendor/bin/drush updb -y

# Config Import
/var/www/html/${site}.${target_env}/vendor/bin/drush cim sync -y

# Clear cache
/var/www/html/${site}.${target_env}/vendor/bin/drush cr

# Maintenance off.
/var/www/html/${site}.${target_env}/vendor/bin/drush sset system.maintenance_mode 0 -y
