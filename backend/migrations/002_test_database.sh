#!/bin/bash
# Creates a separate database for PHPUnit so tests never touch app data.
set -e
TEST_DB="${MYSQL_DATABASE}_test"
mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" <<SQL
CREATE DATABASE IF NOT EXISTS \`${TEST_DB}\`;
GRANT ALL ON \`${TEST_DB}\`.* TO '${MYSQL_USER}'@'%';
SQL
mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" "${TEST_DB}" < /docker-entrypoint-initdb.d/001_init.sql
