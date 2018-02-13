#!/bin/sh
#
# Cloud Hook: deploy
#
# Runs common deployment tasks.

site="$1"
target_env="$2"

# Maintenance on.
/var/www/html/${site}.${target_env}/vendor/bin/drush @$site.$target_env sset system.maintenance_mode 1 -y


# Update database.
/var/www/html/${site}.${target_env}/vendor/bin/drush @$site.$target_env updb -y

# Config Import
/var/www/html/${site}.${target_env}/vendor/bin/drush @$site.$target_env cim sync -y

# Clear cache
/var/www/html/${site}.${target_env}/vendor/bin/drush @$site.$target_env cr

# Maintenance off.
/var/www/html/${site}.${target_env}/vendor/bin/drush @$site.$target_env sset system.maintenance_mode 0 -y
