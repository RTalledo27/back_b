#!/bin/sh
set -e

TEST_DB="${POSTGRES_TEST_DB:-backend_rifas_app_test}"

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
    SELECT 'CREATE DATABASE "${TEST_DB}"'
    WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '${TEST_DB}')\gexec

    GRANT ALL PRIVILEGES ON DATABASE "${TEST_DB}" TO "$POSTGRES_USER";
EOSQL
