@echo off
@REM 获取当前目录
set current_dir=%~dp0
set current_dir=%current_dir:~0,-1%

%current_dir%/php.exe %current_dir%/tphp.php %*