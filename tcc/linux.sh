#!/bin/bash
# =============================================================
# 构建独立 TCC（参考 Vlang 的 TCC 打包方式）
# 核心：用 --crtprefix --libpaths 把路径编译进 TCC 二进制
# =============================================================
set -e

ARCH=$(gcc -dumpmachine)
TCC_HOME="$(pwd)/tcc-dist"   # 独立 TCC 安装目录

echo "=== 1. 复制 glibc 多架构头文件到 TCC include/（自举编译用） ==="
cp -r /usr/include/$ARCH/bits include/ 2>/dev/null || true
cp -r /usr/include/$ARCH/sys  include/ 2>/dev/null || true
cp -r /usr/include/$ARCH/gnu  include/ 2>/dev/null || true

echo "=== 2. 兼容 glibc 2.34+（__malloc_hook 等已移除） ==="
(echo '#include <stddef.h>'; \
 echo 'void *(*volatile __malloc_hook)(size_t, const void *);'; \
 echo 'void *(*volatile __realloc_hook)(void *, size_t, const void *);'; \
 echo 'void (*volatile __free_hook)(void *, const void *);'; \
 echo 'void *(*volatile __memalign_hook)(size_t, size_t, const void *);'; \
 echo ''; \
 cat lib/bcheck.c) > lib/bcheck.c.tmp && mv lib/bcheck.c.tmp lib/bcheck.c

echo "=== 3. 配置 TCC（关键：--crtprefix 和 --libpaths 烧入二进制） ==="
rm -f config.mak config.h
sh configure \
    --prefix="$TCC_HOME" \
    --bindir="$TCC_HOME" \
    --tccdir="$TCC_HOME/lib/tcc" \
    --crtprefix="$TCC_HOME/lib:/usr/lib/$ARCH:/usr/lib64:/usr/lib:/lib/$ARCH:/lib" \
    --libpaths="$TCC_HOME/lib:/usr/lib/$ARCH:/usr/lib64:/usr/lib:/lib/$ARCH:/lib:/usr/local/lib/$ARCH:/usr/local/lib" \
    --extra-cflags="-O3 -I/usr/include/$ARCH" \
    --extra-ldflags="-L/usr/lib/$ARCH"

echo "=== 4. 编译 & 安装到独立目录 ==="
make
make install

echo "=== 5. 补充 CRT 文件 ==="
cp /usr/lib/$ARCH/crt1.o  "$TCC_HOME/lib/" 2>/dev/null || true
cp /usr/lib/$ARCH/crti.o  "$TCC_HOME/lib/" 2>/dev/null || true
cp /usr/lib/$ARCH/crtn.o  "$TCC_HOME/lib/" 2>/dev/null || true

echo "=== 6. 复制 tcc（tphp.php Linux 路径: tcc/tcc） ==="
cp "$TCC_HOME/tcc" ./tcc

echo ""
echo "✓ 独立 TCC 构建完成！"
echo "  路径: $TCC_HOME/tcc"
echo "  现在可直接使用: php tphp.php test.php"
