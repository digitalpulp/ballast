#!/bin/sh
#
# Cloud Hook: deploy
#
# Runs common deployment tasks.

site="$1"
target_env="$2"

# Maintenance on.
drush @$site.$target_env sset system.maintenance_mode 1 -y


# Update database.
drush @$site.$target_env updb -y

# Config Import
drush @$site.$target_env cim sync -y

# Clear cache
drush @$site.$target_env cr

# Maintenance off.
drush @$site.$target_env sset system.maintenance_mode 0 -y
