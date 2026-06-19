#!/usr/bin/env bash
#
# Build a minimal TrueAsync PHP and install it into $TRUEASYNC_PREFIX.
#
# TrueAsync is not (yet) published as a Linux binary, so CI builds the engine
# from source: php-src (true-async-stable) with the async extension cloned
# into ext/async. Only the extensions php-claw actually uses are enabled, so
# this stays far smaller than the full upstream ext/async test matrix.
#
# Ubuntu 24.04 already ships libuv >= 1.45 and libcurl >= 7.87 (TrueAsync's
# minimums), so — unlike the upstream ext/async CI — we do not compile those
# from source. The workflow caches the result, keyed on the upstream commit
# SHAs, so this script only runs when php-src or the extension actually move.
#
set -euo pipefail

PREFIX="${TRUEASYNC_PREFIX:?TRUEASYNC_PREFIX must be set}"
BUILD_DIR="${RUNNER_TEMP:-/tmp}/php-build"

PHP_SRC_REPO="https://github.com/true-async/php-src"
PHP_SRC_BRANCH="true-async-stable"
ASYNC_REPO="https://github.com/true-async/php-async"
ASYNC_BRANCH="main"

echo "::group::Install build dependencies"
sudo apt-get update -y
sudo apt-get install -y --no-install-recommends \
    autoconf bison build-essential re2c pkg-config \
    libxml2-dev libssl-dev libcurl4-openssl-dev \
    libsqlite3-dev libonig-dev zlib1g-dev libuv1-dev
echo "::endgroup::"

echo "::group::Clone php-src + async extension"
rm -rf "$BUILD_DIR"
git clone --depth=1 --branch="$PHP_SRC_BRANCH" "$PHP_SRC_REPO" "$BUILD_DIR"
git clone --depth=1 --branch="$ASYNC_BRANCH" "$ASYNC_REPO" "$BUILD_DIR/ext/async"
echo "::endgroup::"

cd "$BUILD_DIR"

echo "::group::Configure"
./buildconf --force
# ZTS is required by TrueAsync; --enable-async pulls in the extension.
./configure \
    --prefix="$PREFIX" \
    --enable-zts \
    --enable-async \
    --with-curl \
    --with-openssl \
    --with-zlib \
    --enable-mbstring \
    --enable-pdo \
    --with-pdo-sqlite \
    --with-sqlite3 \
    --enable-sockets \
    --without-pear \
    --disable-cgi \
    --disable-phpdbg
echo "::endgroup::"

echo "::group::Compile"
make -j"$(nproc)"
make install
echo "::endgroup::"

"$PREFIX/bin/php" -v
