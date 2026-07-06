#!/bin/bash
# 构建 libevent 静态库
# 用法: bash ext/libevent/build.sh [gcc路径]
set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
GCC="${1:-gcc}"
BUILD_DIR="$SCRIPT_DIR/build"
LIB_DIR="$SCRIPT_DIR/lib"
INC_DIR="$SCRIPT_DIR/include"

rm -rf "$BUILD_DIR" "$LIB_DIR" "$INC_DIR"

echo "[1/3] cmake configure..."
cmake -G "MinGW Makefiles" \
    -DCMAKE_C_COMPILER="$GCC" \
    -S "$SCRIPT_DIR" \
    -B "$BUILD_DIR"

echo "[2/3] build..."
cmake --build "$BUILD_DIR" -j "$(nproc 2>/dev/null || echo 4)"

echo "[3/3] copy headers & library..."
mkdir -p "$LIB_DIR" "$INC_DIR"
cp -r "$SCRIPT_DIR/package/include/"* "$INC_DIR/"
cp "$BUILD_DIR/build/lib/libevent.a" "$LIB_DIR/libevent_core.a"
cp "$BUILD_DIR/build/include/event2/event-config.h" "$INC_DIR/event2/event-config.h"

echo "Done: $LIB_DIR/libevent_core.a"
ls -lh "$LIB_DIR/libevent_core.a"
