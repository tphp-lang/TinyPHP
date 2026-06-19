#!/bin/bash
# =============================================================
# 构建独立 TCC（参考 Vlang：相对路径 + -B 实现可重定位）
# =============================================================
set -e

ARCH=$(gcc -dumpmachine)

echo "=== 1. 克隆 TCC 源码 ==="
rm -rf tinycc-src
git clone --depth 1 --branch mob https://repo.or.cz/tinycc.git tinycc-src
cd tinycc-src

echo "=== 2. 配置 TCC（相对路径，可重定位） ==="
./configure \
    --prefix=. \
    --bindir=. \
    --crtprefix="./lib:/usr/lib/$ARCH:/usr/lib64:/usr/lib:/lib/$ARCH:/lib" \
    --libpaths="./lib:/usr/lib/$ARCH:/usr/lib64:/usr/lib:/lib/$ARCH:/lib:/usr/local/lib/$ARCH:/usr/local/lib" \
    --extra-cflags=-O3 \
    --config-bcheck=yes \
    --config-backtrace=yes \
    --debug

echo "=== 3. 编译 ==="
make

echo "=== 4. 组装独立 TCC 目录 ==="
cd ..
rm -rf tcc-standalone
mkdir -p tcc-standalone/lib tcc-standalone/include

# 二进制
cp tinycc-src/tcc tcc-standalone/

# CRT 文件
cp /usr/lib/$ARCH/crt1.o tcc-standalone/lib/ 2>/dev/null || true
cp /usr/lib/$ARCH/crti.o tcc-standalone/lib/ 2>/dev/null || true
cp /usr/lib/$ARCH/crtn.o tcc-standalone/lib/ 2>/dev/null || true

# TCC 自带的头文件和库
cp -r tinycc-src/include/*   tcc-standalone/include/ 2>/dev/null || true
cp -r tinycc-src/lib/*.a     tcc-standalone/lib/     2>/dev/null || true
cp    tinycc-src/libtcc1.a   tcc-standalone/lib/     2>/dev/null || true

# glibc 多架构头文件
cp -r /usr/include/$ARCH/bits tcc-standalone/include/ 2>/dev/null || true
cp -r /usr/include/$ARCH/sys  tcc-standalone/include/ 2>/dev/null || true
cp -r /usr/include/$ARCH/gnu  tcc-standalone/include/ 2>/dev/null || true

# 复制到项目所需的 tcc/tcc 位置
cp tcc-standalone/tcc ./tcc

echo ""
echo "✓ 独立 TCC 构建完成"
echo "  二进制: $PWD/tcc"
echo "  使用: php tphp.php test.php （tphp.php 会自动加 -B 指向 tcc-standalone/）"
