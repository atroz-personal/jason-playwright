#!/usr/bin/env bash
set -euo pipefail

original_entrypoint='docker-entrypoint.sh'
wp_path='/var/www/html'
woocommerce_flag_file="${wp_path}/wp-content/.woocommerce-installed"

render_php_limits() {
  cat > /usr/local/etc/php/conf.d/zz-runtime-limits.ini <<EOF
upload_max_filesize=${WORDPRESS_UPLOAD_MAX_FILESIZE:-64M}
post_max_size=${WORDPRESS_POST_MAX_SIZE:-64M}
memory_limit=${WORDPRESS_MEMORY_LIMIT:-256M}
max_execution_time=300
max_input_time=300
EOF
}

prepare_wordpress_files() {
  (
    cd "${wp_path}"
    "${original_entrypoint}" php-fpm -t >/dev/null
  )
}

ensure_valid_wp_config() {
  local config_file docker_config_file
  config_file="${wp_path}/wp-config.php"
  docker_config_file="/usr/src/wordpress/wp-config-docker.php"

  if [[ ! -s "${config_file}" ]]; then
    return
  fi

  if grep -q "require_once ABSPATH . 'wp-settings.php';" "${config_file}"; then
    return
  fi

  cp "${docker_config_file}" "${config_file}"
  chown www-data:www-data "${config_file}" 2>/dev/null || true
}

fix_wordpress_permissions() {
  mkdir -p \
    "${wp_path}/wp-content/uploads" \
    "${wp_path}/wp-content/upgrade" \
    "${wp_path}/wp-content/cache"

  chown -R www-data:www-data "${wp_path}/wp-content"
  find "${wp_path}/wp-content" -type d -exec chmod 775 {} +
  find "${wp_path}/wp-content" -type f -exec chmod 664 {} +
}

wait_for_db() {
  local db_host db_port
  db_host="${WORDPRESS_DB_HOST%%:*}"
  db_port="${WORDPRESS_DB_HOST##*:}"

  if [[ "${db_host}" == "${db_port}" ]]; then
    db_port="3306"
  fi

  until mariadb-admin ping \
    --host="${db_host}" \
    --port="${db_port}" \
    --user="${WORDPRESS_DB_USER}" \
    --password="${WORDPRESS_DB_PASSWORD}" \
    --silent; do
    sleep 3
  done
}

install_wordpress_if_needed() {
  if wp core is-installed --allow-root --path="${wp_path}" >/dev/null 2>&1; then
    return
  fi

  wp core install \
    --allow-root \
    --path="${wp_path}" \
    --url="${WORDPRESS_SITE_URL}" \
    --title="${WORDPRESS_SITE_TITLE}" \
    --admin_user="${WORDPRESS_ADMIN_USER}" \
    --admin_password="${WORDPRESS_ADMIN_PASSWORD}" \
    --admin_email="${WORDPRESS_ADMIN_EMAIL}" \
    --skip-email
}

install_woocommerce() {
  if [[ -f "${woocommerce_flag_file}" ]]; then
    return
  fi

  if ! wp core is-installed --allow-root --path="${wp_path}" >/dev/null 2>&1; then
    return
  fi

  if wp plugin is-installed woocommerce --allow-root --path="${wp_path}" >/dev/null 2>&1; then
    wp plugin activate woocommerce --allow-root --path="${wp_path}" >/dev/null 2>&1 || true
    touch "${woocommerce_flag_file}"
    return
  fi

  wp plugin install woocommerce --activate --allow-root --path="${wp_path}"
  touch "${woocommerce_flag_file}"
}

main() {
  render_php_limits
  prepare_wordpress_files
  ensure_valid_wp_config

  wait_for_db

  # The official image's generated wp-config.php is not always friendly to
  # early wp-cli calls during first boot, so we keep PHP-FPM alive and only
  # attempt WooCommerce installation once WordPress is already installed.
  if ! install_wordpress_if_needed; then
    echo "Skipping automatic WordPress installation; complete it from the browser and WooCommerce will install on a later restart."
  fi

  if ! install_woocommerce; then
    echo "Skipping automatic WooCommerce installation for now."
  fi

  fix_wordpress_permissions

  exec php-fpm
}

main "$@"
