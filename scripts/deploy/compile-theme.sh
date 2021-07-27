
WORKING_DIR=${THEME_PATH:-"/var/www/docroot/themes/custom"}

[ "${THEME_NAME}" = "" ] && echo "Error, THEME_NAME variable not specified. Exiting front-end." && exit 1

THEME_DIR="${WORKING_DIR}/${THEME_NAME}"

[ ! -f "${THEME_DIR}/gulpfile.js" ] && echo "Error, gulpfile.js file not present in theme directory ${THEME_DIR}. Exiting gulp-build.sh" && exit 1

cd ${THEME_DIR}
echo "Changed working directory to ${THEME_DIR}"

nodenv install -s
eval "$(nodenv init -)"
if npm_config_user=root npm ci; then
  echo "Node modules installed"
  node_modules/.bin/gulp build
else
  echo "npm ci failed"
fi
