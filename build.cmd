@echo off
chcp 65001 >nul
REM =============================================================
REM Windows TCC 构建（需要 MSYS2 MinGW64 gcc 环境）
REM 确保 C:\msys64\mingw64\bin 在 PATH 中
REM =============================================================

echo === 1. 克隆 TCC 源码 ===
rmdir /s /q tcc 2>nul
git clone --depth 1 --branch mob https://repo.or.cz/tinycc.git tcc

echo === 2. 编译 TCC ===
cd tcc\win32
call build-tcc.bat
cd ..\..

echo === 3. 清理无关文件（仅保留 win32\） ===
for /d %%d in (tcc\*) do if not "%%~nxd"=="win32" rmdir /s /q "%%d" 2>nul
for %%f in (tcc\*) do if not exist "%%f\" del /q "%%f" 2>nul

echo === 4. 清理 win32\ 中无用文件 ===
cd tcc\win32
del /q build-tcc.bat *.def Makefile configure VERSION 2>nul
for /d %%d in (*) do if not "%%~nxd"=="include" if not "%%~nxd"=="lib" if not "%%~nxd"=="doc" rmdir /s /q "%%d" 2>nul
cd ..\..

echo.
echo TCC 构建完成
echo   二进制: tcc\win32\tcc.exe
