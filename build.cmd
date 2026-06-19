#!/bin/bash
# =============================================================
# Windows TCC 构建（MSYS2 MinGW64）
# 用法: bash build.cmd（在项目根目录执行）
# =============================================================
set -e

echo "=== 1. 克隆 TCC 源码 ==="
rm -rf tcc
git clone --depth 1 --branch mob https://repo.or.cz/tinycc.git tcc
cd tcc

echo "=== 2. 配置 TCC（安装到 win32/ 子目录） ==="
./configure \
    --prefix=win32 \
    --bindir=win32 \
    --crtprefix="tcc/win32/lib" \
    --libpaths="tcc/win32/lib/tcc;tcc/win32/lib" \
    --cc=gcc \
    --extra-cflags=-O3 \
    --config-bcheck=yes \
    --config-backtrace=yes \
    --debug

echo "=== 3. 编译 & 安装 ==="
mingw32-make
mingw32-make install

echo "=== 4. 整理 TCC 头文件 ==="
mkdir -p win32/include
cp -r win32/lib/tcc/include/* win32/include/ 2>/dev/null || true

echo ""
echo "TCC 构建完成"
echo "  二进制: tcc/win32/tcc.exe"
