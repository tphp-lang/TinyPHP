#!/bin/bash
# TinyPHP CLI 入口（Linux / macOS）
CURRENT_DIR=$(cd "$(dirname "$0")" && pwd)

exec "$CURRENT_DIR/php" "$CURRENT_DIR/tphp.php" "$@"
