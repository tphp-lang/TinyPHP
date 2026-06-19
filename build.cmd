#!/bin/bash
# =============================================================
# Windows TCC 构建（MSYS2 MinGW64）
# 用法: bash build.cmd（在项目根目录执行）
# =============================================================
set -e

echo "=== 1. 克隆 TCC 源码 ==="
rm -rf tcc
git clone --depth 1 --branch mob https://repo.or.cz/tinycc.git tcc

echo "=== 2. 编译 TCC ==="
cd tcc/win32
cmd //c build-tcc.bat

echo "=== 3. 清理无关文件（仅保留 win32/） ==="
cd ..
shopt -s extglob
rm -rf !(win32) 2>/dev/null || true
rm -f win32/build-tcc.bat 2>/dev/null || true

echo ""
echo "TCC 构建完成"
echo "  二进制: tcc/win32/tcc.exe"
