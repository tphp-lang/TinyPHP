#!/bin/bash
# =============================================================
# 构建独立 TCC（参考 Vlang：源码与安装目录分离，路径相对项目根）
# 用法: bash build.sh（在项目根目录执行）
# =============================================================
set -e

ARCH=$(gcc -dumpmachine)

echo "=== 1. 克隆 TCC 源码 ==="
rm -rf tcc-src tcc
git clone --depth 1 --branch mob https://repo.or.cz/tinycc.git tcc-src
cd tcc-src

echo "=== 2. 配置 TCC（路径相对于项目根目录，和 Vlang 一致） ==="
./configure \
    --prefix=../tcc \
    --bindir=../tcc \
    --crtprefix="tcc/lib:/usr/lib/$ARCH:/usr/lib64:/usr/lib:/lib/$ARCH:/lib" \
    --libpaths="tcc/lib/tcc:tcc/lib:/usr/lib/$ARCH:/usr/lib64:/usr/lib:/lib/$ARCH:/lib:/usr/local/lib/$ARCH:/usr/local/lib" \
    --extra-cflags=-O3 \
    --config-bcheck=yes \
    --config-backtrace=yes \
    --debug

echo "=== 3. 编译 & 安装 ==="
make
make install

echo "=== 4. 整理 TCC 头文件 ==="
# TCC 内部头文件 (stddef.h, stdarg.h 等) 在 lib/tcc/include/
# 复制到 include/ 使 -B 参数和默认搜索能找到
mkdir -p ../tcc/include
cp -r ../tcc/lib/tcc/include/* ../tcc/include/ 2>/dev/null || true

echo "=== 5. 补充系统 CRT 文件 ==="
cp /usr/lib/$ARCH/crt1.o ../tcc/lib/ 2>/dev/null || true
cp /usr/lib/$ARCH/crti.o ../tcc/lib/ 2>/dev/null || true
cp /usr/lib/$ARCH/crtn.o ../tcc/lib/ 2>/dev/null || true

echo "=== 6. 验证搜索路径 ==="
cd ..
./tcc/tcc -v -v 2>&1 | head -5

echo "=== 7. 清理 ==="
rm -rf tcc-src

echo ""
echo "✓ 独立 TCC 构建完成"
echo "  二进制: $PWD/tcc/tcc"
echo "  运行时: $PWD/tcc/lib/ (libtcc1.a at tcc/lib/tcc/)"
echo "  使用: php tphp.php test.php"
