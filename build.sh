#!/bin/bash
# =============================================================
# 构建独立 TCC（Linux / macOS 通用，参考 Vlang)
# 用法: bash build.sh（在项目根目录执行）
# =============================================================
set -e

OS="$(uname -s)"

echo "=== 1. 克隆 TCC 源码 ==="
rm -rf tcc-src tcc
git clone --depth 1 --branch mob https://repo.or.cz/tinycc.git tcc-src
cd tcc-src

echo "=== 2. 配置 TCC ==="
if [ "$OS" = "Darwin" ]; then
    SDK=$(xcrun --show-sdk-path)
    ./configure \
        --prefix=../tcc \
        --bindir=../tcc \
        --crtprefix="../tcc/lib/tcc:$SDK/usr/lib" \
        --libpaths="../tcc/lib/tcc:$SDK/usr/lib:/usr/lib:/usr/local/lib" \
        --sysincludepaths="../tcc/lib/tcc/include:$SDK/usr/include:/usr/local/include" \
        --extra-cflags="-I$SDK/usr/include -O3" \
        --cc=cc \
        --config-new_macho=yes \
        --config-codesign=yes \
        --config-bcheck=yes \
        --config-backtrace=yes \
        --enable-static
else
    ARCH=$(gcc -dumpmachine)
    ./configure \
        --prefix=../tcc \
        --bindir=../tcc \
        --crtprefix="tcc/lib/tcc:/usr/lib/$ARCH:/usr/lib64:/usr/lib:/lib/$ARCH:/lib" \
        --libpaths="tcc/lib/tcc:/usr/lib/$ARCH:/usr/lib64:/usr/lib:/lib/$ARCH:/lib:/usr/local/lib/$ARCH:/usr/local/lib" \
        --extra-cflags=-O3 \
        --config-bcheck=yes \
        --config-backtrace=yes \
        --debug
fi

echo "=== 3. 编译 & 安装 ==="
make
make install

echo "=== 4. 整理 TCC 目录结构 ==="
# -B 指向 tcc/lib/tcc/，libtcc1.a 已在该位置，无需额外复制
mkdir -p ../tcc/include
cp -r ../tcc/lib/tcc/include/* ../tcc/include/ 2>/dev/null || true

if [ "$OS" = "Darwin" ]; then
    # macOS: link libc for Big Sur+
    ln -sf /usr/lib/libSystem.B.dylib ../tcc/lib/tcc/libc.dylib 2>/dev/null || true
else
    echo "=== 5. 补充系统 CRT 文件（Linux） ==="
    cp /usr/lib/$ARCH/crt1.o ../tcc/lib/ 2>/dev/null || true
    cp /usr/lib/$ARCH/crti.o ../tcc/lib/ 2>/dev/null || true
    cp /usr/lib/$ARCH/crtn.o ../tcc/lib/ 2>/dev/null || true
fi

echo "=== 6. 验证 ==="
cd ..
echo 'int main(){return 0;}' > _test_tcc.c
if ./tcc/tcc -B"$PWD/tcc/lib/tcc" -o _test_tcc _test_tcc.c 2>/dev/null; then
    echo "TCC standalone OK"
else
    echo "TCC probe failed (may still work with full flags)"
fi
rm -f _test_tcc.c _test_tcc

echo "=== 7. 清理 ==="
rm -rf tcc-src

echo ""
echo "✓ 独立 TCC 构建完成"
echo "  二进制: $PWD/tcc/tcc"
echo "  使用: php tphp.php test.php"
