param(
    [string]$PhpExecutable = 'php',
    [switch]$SkipComposer,
    [switch]$InstallEditorExtensions
)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
Push-Location -LiteralPath $projectRoot
try {
    $phpCommand = Get-Command $PhpExecutable -ErrorAction Stop
    $phpPath = $phpCommand.Source
    $metadata = & $phpPath 'tools/runtime-info.php'
    if ($LASTEXITCODE -ne 0) { throw 'Nao foi possivel consultar o PHP.' }
    $info = $metadata | ConvertFrom-Json
    $runtime = Get-Content -LiteralPath 'dev/runtime.lock.json' -Raw | ConvertFrom-Json
    if ($info.minor -ne $runtime.php.minor -or $info.bits -ne $runtime.php.bits -or $info.ts -ne $runtime.php.threadSafe) {
        throw 'O bootstrap Windows esta fixado em PHP 8.4 TS x64 VS17. Consulte docs/DESENVOLVIMENTO.md para outros runtimes.'
    }
    foreach ($path in @('.dev/ext','.dev/logs','.cache','build')) {
        New-Item -ItemType Directory -Force -Path $path | Out-Null
    }
    $dll = Join-Path $projectRoot '.dev/ext/php_xdebug.dll'
    if (-not (Test-Path -LiteralPath $dll) -or (Get-FileHash -LiteralPath $dll -Algorithm SHA256).Hash -ne $runtime.xdebug.sha256) {
        Write-Host 'Baixando Xdebug do site oficial...'
        Invoke-WebRequest -Uri $runtime.xdebug.url -OutFile $dll
    }
    if ((Get-FileHash -LiteralPath $dll -Algorithm SHA256).Hash -ne $runtime.xdebug.sha256) {
        throw 'SHA256 do Xdebug diverge do runtime.lock.json. Instalacao interrompida.'
    }
    $extensionDir = $info.ext
    if (-not [IO.Path]::IsPathRooted($extensionDir)) { $extensionDir = Join-Path (Split-Path -Parent $phpPath) $extensionDir }
    foreach ($name in @('openssl','sodium','pdo_sqlite','mbstring','curl','fileinfo','zip','sockets','gmp','gd','ffi')) {
        if (-not (Test-Path -LiteralPath (Join-Path $extensionDir "php_$name.dll"))) { throw "Extensao PHP ausente: $name" }
    }
    $ini = Get-Content -LiteralPath 'dev/php.ini.template' -Raw
    $ini = $ini.Replace('@EXTENSION_DIR@', $extensionDir.Replace('\','/'))
    $ini = $ini.Replace('@XDEBUG_DLL@', $dll.Replace('\','/'))
    $ini = $ini.Replace('@LOG_DIR@', (Join-Path $projectRoot '.dev/logs').Replace('\','/'))
    $ini = $ini.Replace('@CA_FILE@', ([string]$info.ca).Replace('\','/'))
    [IO.File]::WriteAllText((Join-Path $projectRoot '.dev/php.ini'), $ini, [Text.UTF8Encoding]::new($false))
    $localConfig = @{php=$phpPath; ini=(Join-Path $projectRoot '.dev/php.ini'); version=$info.version; xdebug=$runtime.xdebug.version} | ConvertTo-Json
    [IO.File]::WriteAllText((Join-Path $projectRoot 'dev.local.json'), $localConfig, [Text.UTF8Encoding]::new($false))
    $settingsPath = Join-Path $projectRoot '.vscode/settings.json'
    if (Test-Path -LiteralPath $settingsPath) {
        $settings = Get-Content -LiteralPath $settingsPath -Raw | ConvertFrom-Json
        $settings.'php.validate.executablePath' = $phpPath.Replace('\','/')
        $settings.'php.debug.executablePath' = $phpPath.Replace('\','/')
        [IO.File]::WriteAllText($settingsPath, ($settings | ConvertTo-Json -Depth 20), [Text.UTF8Encoding]::new($false))
    }
    if (-not $SkipComposer) {
        # Run Composer with the project ini, including project-required GMP.
        $previousPhprc = $env:PHPRC
        $env:PHPRC = Join-Path $projectRoot '.dev/php.ini'
        try { & composer install --no-interaction } finally { $env:PHPRC = $previousPhprc }
        if ($LASTEXITCODE -ne 0) { throw 'Composer install falhou.' }
    }
    if ($InstallEditorExtensions) {
        & code --install-extension xdebug.php-debug
        if ($LASTEXITCODE -ne 0) { throw 'Instalacao da extensao PHP Debug falhou.' }
        & code --install-extension bmewburn.vscode-intelephense-client
        if ($LASTEXITCODE -ne 0) { throw 'Instalacao do Intelephense falhou.' }
    }
    & $phpPath -c '.dev/php.ini' 'tools/doctor.php'
    if ($LASTEXITCODE -ne 0) { throw 'Diagnostico do ambiente falhou.' }
    Write-Host 'Ambiente pronto. Execute composer check ou abra mvo-php.code-workspace no VS Code.'
} finally { Pop-Location }
