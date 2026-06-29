#!/bin/bash
# =============================================================
# 构建独立 TCC（Linux / macOS 通用，参考 Vlang)
# 用法: bash build.sh（在项目根目录执行）
# =============================================================
set -e
set -o pipefail

OS="$(uname -s)"

echo "=== 1. 克隆 TCC 源码 ==="
# 记下项目根目录绝对路径 — TCC 的 --prefix 必须用绝对路径
# 否则 TCC 初始化时从 CWD 解析相对路径，会找错 libtcc1.a 的位置
PROJECT_ROOT="$(pwd)"
rm -rf tcc-src tcc
# 最多重试 3 次（repo.or.cz 网络不稳定）
for i in 1 2 3; do
  echo "[*] 第 $i 次尝试克隆 TCC..."
  if git clone --depth 1 --branch mob https://repo.or.cz/tinycc.git tcc-src 2>/dev/null; then
    break
  fi
  [ "$i" -lt 3 ] && sleep 10
done
if [ ! -d tcc-src ]; then
  echo "[ERROR] 无法从 repo.or.cz 克隆 TCC 源码（重试 3 次均失败）"
  exit 1
fi
cd tcc-src

echo "=== 2. 配置 TCC ==="
echo "       prefix = $PROJECT_ROOT/tcc"
if [ "$OS" = "Darwin" ]; then
    SDK=$(xcrun --show-sdk-path)
    ./configure \
        --prefix="$PROJECT_ROOT/tcc" \
        --bindir="$PROJECT_ROOT/tcc" \
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
        --prefix="$PROJECT_ROOT/tcc" \
        --bindir="$PROJECT_ROOT/tcc" \
        --crtprefix="../lib/tcc:/usr/lib/$ARCH:/usr/lib64:/usr/lib:/lib/$ARCH:/lib" \
        --libpaths="../lib/tcc:/usr/lib/$ARCH:/usr/lib64:/usr/lib:/lib/$ARCH:/lib:/usr/local/lib/$ARCH:/usr/local/lib" \
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
    CRT_TARGET=../tcc/lib/tcc
    # 来自多个可能的 CRT 路径
    for crtdir in /usr/lib/$ARCH /usr/lib64 /usr/lib /lib/$ARCH /lib; do
        [ -f "$crtdir/crt1.o" ] || continue
        cp -v "$crtdir/crt1.o" "$crtdir/crti.o" "$crtdir/crtn.o" "$CRT_TARGET/" 2>/dev/null || true
        # 也复制 libc.so（用于动态链接）
        cp -v "$crtdir/libc.so"* "$CRT_TARGET/" 2>/dev/null || true
        cp -v "$crtdir/libc.a"   "$CRT_TARGET/" 2>/dev/null || true
        break
    done
fi

echo "=== 6. 验证 ==="
cd ..
echo 'int main(){return 0;}' > _test_tcc.c
echo "=== TCC search paths ==="
./tcc/tcc -v -v 2>&1 || true
echo "=== strace: TCC's open() calls for libtcc1 ==="
strace -f -e openat -o /tmp/tcc_strace.log ./tcc/tcc -B"$PROJECT_ROOT/tcc/lib/tcc" -o _test_tcc _test_tcc.c 2>&1; true
grep -i 'libtcc1' /tmp/tcc_strace.log || echo "(no libtcc1 in strace)"
echo "=== verify with symlink workaround ==="
ln -sf "$PROJECT_ROOT/tcc/lib/tcc/libtcc1.a" ./libtcc1.a
if ./tcc/tcc -B"$PROJECT_ROOT/tcc/lib/tcc" -o _test_tcc _test_tcc.c 2>&1; then
    echo "TCC OK"
    rm -f _test_tcc
else
    echo "TCC FAILED"
    exit 1
fi
rm -f _test_tcc.c _test_tcc libtcc1.a

echo "=== 7. 清理 ==="
rm -rf tcc-src

echo ""
echo "✓ 独立 TCC 构建完成"
echo "  二进制: $PWD/tcc/tcc"
echo "  使用: php tphp.php test.php"
