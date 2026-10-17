param(
    [string]$ZigPath = '',
    [string]$CmakePath = '',
    [string]$NinjaPath = '',
    [int]$Jobs = 4
)
$ErrorActionPreference = 'Stop'
$codecRoot = Split-Path -Parent $PSScriptRoot
if (!$ZigPath) { $ZigPath = Join-Path $codecRoot '.dev/native/zig-x86_64-windows-0.14.1/zig.exe' }
if (!$CmakePath) { $CmakePath = Join-Path $codecRoot '.dev/native/build-tools/cmake/data/bin/cmake.exe' }
if (!$NinjaPath) { $NinjaPath = Join-Path $codecRoot '.dev/native/build-tools/bin/ninja.exe' }
foreach ($codecTool in @($ZigPath, $CmakePath, $NinjaPath)) { if (!(Test-Path -LiteralPath $codecTool)) { throw "Missing codec build tool: $codecTool" } }
if ($Jobs -lt 1 -or $Jobs -gt 32) { throw 'Jobs must be 1..32' }
$codecCache = Join-Path $codecRoot '.cache/native'
$codecArchive = Join-Path $codecCache 'opus-mlow-1.0.1.tar.gz'
$codecSource = Join-Path $codecCache 'opus-mlow-1.0.1'
$codecBuild = Join-Path $codecCache 'mlow-build'
$codecTools = Join-Path $codecCache 'mlow-build-tools'
$codecOutput = Join-Path $codecRoot '.dev/native/codecs'
New-Item -ItemType Directory -Force -Path $codecCache, $codecBuild, $codecTools, $codecOutput | Out-Null
$opusArchive = Join-Path $codecCache 'mingw-w64-ucrt-x86_64-opus-1.6.1-1-any.pkg.tar.zst'
$opusExpected = '7b8f176f9cc0431b52006861c0a2e083d73d4b47a73ab749755b5379ff673252'
if (!(Test-Path -LiteralPath $opusArchive)) {
    Invoke-WebRequest -Uri 'https://repo.msys2.org/mingw/ucrt64/mingw-w64-ucrt-x86_64-opus-1.6.1-1-any.pkg.tar.zst' -OutFile $opusArchive
}
if ((Get-FileHash -LiteralPath $opusArchive -Algorithm SHA256).Hash.ToLowerInvariant() -ne $opusExpected) { throw 'Opus package SHA-256 mismatch' }
& tar -xf $opusArchive -C (Join-Path $codecRoot '.dev/native')
if ($LASTEXITCODE -ne 0) { throw 'Could not extract native Opus package' }
if (!(Test-Path -LiteralPath (Join-Path $codecRoot '.dev/native/ucrt64/bin/libopus-0.dll'))) { throw 'Opus package produced no DLL' }
# The complete package, including ucrt64/share/licenses/opus/COPYING, stays installed.
[ordered]@{ version = '1.6.1'; archive = 'https://repo.msys2.org/mingw/ucrt64/mingw-w64-ucrt-x86_64-opus-1.6.1-1-any.pkg.tar.zst'; sha256 = $opusExpected; license = '../ucrt64/share/licenses/opus/COPYING' } | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $codecOutput 'opus-install.json') -Encoding utf8
if (!(Test-Path -LiteralPath $codecArchive)) {
    Invoke-WebRequest -Uri 'https://github.com/edgardmessias/opus_mlow/releases/download/v1.0.1/opus-mlow-1.0.1.tar.gz' -OutFile $codecArchive
}
$codecHash = (Get-FileHash -LiteralPath $codecArchive -Algorithm SHA256).Hash.ToLowerInvariant()
if ($codecHash -ne '2b730f9ccbc13b02b9360e889079ef2ca831cbf181fa8cb2dae634f532f76ca0') { throw 'MLow source SHA-256 mismatch' }
if (!(Test-Path -LiteralPath (Join-Path $codecSource 'CMakeLists.txt'))) {
    & tar -xzf $codecArchive -C $codecCache
    if ($LASTEXITCODE -ne 0) { throw 'Could not extract MLow source' }
}
$codecCompiler = Join-Path $codecTools 'zig-cc.cmd'
Set-Content -LiteralPath $codecCompiler -Encoding ascii -Value ('@"' + $ZigPath + '" cc -target x86_64-windows-gnu %*')
& $CmakePath -S $codecSource -B $codecBuild -G Ninja "-DCMAKE_MAKE_PROGRAM=$NinjaPath" "-DCMAKE_C_COMPILER=$codecCompiler" '-DCMAKE_BUILD_TYPE=Release' '-DCMAKE_POLICY_VERSION_MINIMUM=3.5' '-DOPUS_BUILD_SHARED_LIBRARY=ON' '-DOPUS_BUILD_TESTING=OFF' '-DOPUS_BUILD_PROGRAMS=OFF' '-DOPUS_DISABLE_INTRINSICS=ON' '-DOPUS_INSTALL_PKG_CONFIG_MODULE=OFF' '-DOPUS_INSTALL_CMAKE_CONFIG_MODULE=OFF'
if ($LASTEXITCODE -ne 0) { throw 'Could not configure MLow' }
& $CmakePath --build $codecBuild --target opus -j $Jobs
if ($LASTEXITCODE -ne 0) { throw 'Could not build native MLow' }
$codecDll = Get-ChildItem -LiteralPath $codecBuild -Filter '*opus*.dll' -File -Recurse | Select-Object -First 1
if (!$codecDll) { throw 'MLow build produced no DLL' }
Copy-Item -LiteralPath $codecDll.FullName -Destination (Join-Path $codecOutput 'libopus-mlow.dll') -Force
Copy-Item -LiteralPath (Join-Path $codecSource 'COPYING') -Destination (Join-Path $codecOutput 'MLow-COPYING.txt') -Force
$codecManifest = [ordered]@{ source = 'https://github.com/edgardmessias/opus_mlow/releases/tag/v1.0.1'; sourceSha256 = $codecHash; binarySha256 = (Get-FileHash -LiteralPath (Join-Path $codecOutput 'libopus-mlow.dll') -Algorithm SHA256).Hash.ToLowerInvariant(); arch = 'x86_64-windows-gnu'; compiler = 'Zig 0.14.1'; smpl = $true }
$codecManifest | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $codecOutput 'mlow-build.json') -Encoding utf8
Write-Output "Native MLow installed at $codecOutput"
