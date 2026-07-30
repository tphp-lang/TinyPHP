# 运行所有 pgsql 测试 × 3 编译器，结果写入 test/pgsql/results.log
$ErrorActionPreference = "Continue"
Set-Location "C:\project\php\TinyPHP"

$logFile = "test/pgsql/results.log"
"" | Out-File -FilePath $logFile -Encoding utf8

$tests = Get-ChildItem test/pgsql/*.php -Name | Where-Object { $_ -notlike "run_all*" -and $_ -notlike "debug_*" }
$compilers = @(
    @{name="tcc"; cc=""},
    @{name="gcc"; cc="C:\env\msys2\mingw64\bin\gcc.exe"},
    @{name="clang"; cc="C:\env\msys2\clang64\bin\clang.exe"}
)

foreach ($test in $tests) {
    $name = [System.IO.Path]::GetFileNameWithoutExtension($test)
    foreach ($c in $compilers) {
        $out = "test/pgsql/${name}_$($c.name).exe"
        $header = "=== $test [$($c.name)] ==="
        Write-Host $header
        Add-Content -Path $logFile -Value $header

        # 编译
        if ($c.cc -eq "") {
            $compileOut = php tphp.php "test/pgsql/$test" -o $out 2>&1
        } else {
            $compileOut = php tphp.php "test/pgsql/$test" -cc $c.cc -o $out 2>&1
        }
        $compileExit = $LASTEXITCODE

        if ($compileExit -ne 0) {
            $msg = "COMPILE FAIL (exit=$compileExit)"
            Write-Host $msg
            Add-Content -Path $logFile -Value $msg
            $compileOut | ForEach-Object { Add-Content -Path $logFile -Value $_ }
            Add-Content -Path $logFile -Value ""
            continue
        }

        Add-Content -Path $logFile -Value "COMPILE OK"

        # 运行
        $runOut = & $out 2>&1
        $runExit = $LASTEXITCODE
        $runOut | ForEach-Object {
            Write-Host $_
            Add-Content -Path $logFile -Value $_
        }
        $exitMsg = "EXIT CODE: $runExit"
        Write-Host $exitMsg
        Add-Content -Path $logFile -Value $exitMsg
        Add-Content -Path $logFile -Value ""
    }
}

Write-Host "DONE - results in $logFile"
