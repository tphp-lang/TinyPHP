#!/bin/bash
# =============================================================
# 构建独立 TCC（参考 Vlang 方式：git clone tinycc.git + configure + make install）
# =============================================================
set -e

ARCH=$(gcc -dumpmachine)
TCC_HOME="$(pwd)/tcc-dist"
TCC_SRC="$(pwd)/tinycc-src"

echo "=== 1. 克隆 TCC 源码 ==="
rm -rf "$TCC_SRC"
git clone --depth 1 --branch mob https://repo.or.cz/tinycc.git "$TCC_SRC"
cd "$TCC_SRC"

echo "=== 2. 配置 TCC ==="
./configure \
    --prefix="$TCC_HOME" \
    --bindir="$TCC_HOME" \
    --crtprefix="$TCC_HOME/lib:/usr/lib/$ARCH:/usr/lib64:/usr/lib:/lib/$ARCH:/lib" \
    --libpaths="$TCC_HOME/lib/tcc:$TCC_HOME/lib:/usr/lib/$ARCH:/usr/lib64:/usr/lib:/lib/$ARCH:/lib:/usr/local/lib/$ARCH:/usr/local/lib" \
    --extra-cflags=-O3 \
    --config-bcheck=yes \
    --config-backtrace=yes \
    --debug

echo "=== 3. 编译 & 安装 ==="
make
make install

echo "=== 4. 复制 CRT 文件 ==="
cp /usr/lib/$ARCH/crt1.o "$TCC_HOME/lib/" 2>/dev/null || true
cp /usr/lib/$ARCH/crti.o "$TCC_HOME/lib/" 2>/dev/null || true
cp /usr/lib/$ARCH/crtn.o "$TCC_HOME/lib/" 2>/dev/null || true

echo "=== 5. 复制 tcc 到项目位置 ==="
cd "$(dirname "$TCC_HOME")"
cp "$TCC_HOME/tcc" ./tcc

echo ""
echo "✓ TCC 构建完成"
"$TCC_HOME/tcc" -v
