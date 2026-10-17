param(
    [string]$ZigPath = '',
    [string]$CmakePath = '',
    [string]$NinjaPath = '',
    [int]$Jobs = 4
)
$ErrorActionPreference = 'Stop'
$companionRoot = Split-Path -Parent $PSScriptRoot
$companionWork = Join-Path $companionRoot '.dev/native/companion'
$companionOutput = Join-Path $companionRoot '.dev/native/codecs'
$companionRevision = '0f50232f5b3667da3c3d8208cdd5ef4c8d48f390'
$companionArchiveHash = '3668aa86290e74e47d4384f79c829b0d4f033f346551c8c24dcc2b257e0c59a3'
$companionArchiveUrl = "https://codeload.github.com/vinikjkkj/libmlow-wasm/zip/$companionRevision"
$companionArchive = Join-Path $companionWork "libmlow-wasm-$companionRevision.zip"
$companionSource = Join-Path $companionWork "libmlow-wasm-$companionRevision"
$companionOpus = Join-Path $companionWork "opus-mlow-1.0.1-$companionRevision"
$companionOpusArchive = Join-Path $companionWork 'opus-mlow-1.0.1.tar.gz'
$companionOpusHash = '2b730f9ccbc13b02b9360e889079ef2ca831cbf181fa8cb2dae634f532f76ca0'
$companionOpusUrl = 'https://github.com/edgardmessias/opus_mlow/releases/download/v1.0.1/opus-mlow-1.0.1.tar.gz'
$companionProject = Join-Path $companionWork 'project'
$companionBuild = Join-Path $companionWork 'build'
$companionLicenseDir = Join-Path $companionOutput 'licenses'
if (!$ZigPath) { $ZigPath = Join-Path $companionRoot '.dev/native/zig-x86_64-windows-0.14.1/zig.exe' }
if (!$CmakePath) { $CmakePath = Join-Path $companionRoot '.dev/native/build-tools/cmake/data/bin/cmake.exe' }
if (!$NinjaPath) { $NinjaPath = Join-Path $companionRoot '.dev/native/build-tools/bin/ninja.exe' }
foreach ($companionTool in @($ZigPath,$CmakePath,$NinjaPath)) { if (!(Test-Path -LiteralPath $companionTool -PathType Leaf)) { throw "Missing native Companion build tool: $companionTool" } }
if ($Jobs -lt 1 -or $Jobs -gt 32) { throw 'Jobs must be 1..32' }
New-Item -ItemType Directory -Force -Path $companionWork,$companionOutput,$companionProject,$companionBuild,$companionLicenseDir,$companionOpus | Out-Null

function Get-CompanionArchive([string]$Url,[string]$Path,[string]$Hash) {
    if (!(Test-Path -LiteralPath $Path)) { Invoke-WebRequest -Uri $Url -OutFile $Path }
    if ((Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant() -ne $Hash) { throw "Companion archive SHA-256 mismatch: $Path" }
}
Get-CompanionArchive $companionArchiveUrl $companionArchive $companionArchiveHash
if (!(Test-Path -LiteralPath (Join-Path $companionSource 'native/companion.c'))) { Expand-Archive -LiteralPath $companionArchive -DestinationPath $companionWork }
if (!(Test-Path -LiteralPath $companionOpusArchive)) {
    $existingCompanionOpus = Join-Path $companionRoot '.cache/native/opus-mlow-1.0.1.tar.gz'
    if (Test-Path -LiteralPath $existingCompanionOpus) { Copy-Item -LiteralPath $existingCompanionOpus -Destination $companionOpusArchive }
}
Get-CompanionArchive $companionOpusUrl $companionOpusArchive $companionOpusHash
if (!(Test-Path -LiteralPath (Join-Path $companionOpus 'CMakeLists.txt'))) {
    & tar -xzf $companionOpusArchive -C $companionOpus --strip-components=1
    if ($LASTEXITCODE -ne 0) { throw 'Could not extract native opus_mlow source' }
}

# Read a strictly limited data subset of the pinned script. No JavaScript is executed.
# Only backtick string literals, two known constant interpolations and literal newline escapes
# are accepted. There is no eval, import, subprocess or general expression interpretation.
$companionPatchFile = Join-Path $companionSource 'scripts/opus-mlow-patches.mjs'
$companionPatchText = [IO.File]::ReadAllText($companionPatchFile).Replace("`r`n","`n")
$companionPatchHash = (Get-FileHash -LiteralPath $companionPatchFile -Algorithm SHA256).Hash.ToLowerInvariant()
$companionStamp = Join-Path $companionOpus '.mvo-companion-patch-sha256'
$companionUtf8 = New-Object Text.UTF8Encoding($false)
function Read-CompanionLiteral([string]$Value) {
    $decoded = $Value.Replace('${MARKER}','libmlow-wasm:').Replace('${SMPL_FRAME_HOOK_REQUEST}','4880').Replace('\`','`').Replace('\n',"`n")
    if ($decoded.Contains('${') -or $decoded.Contains('\')) { throw 'Unsupported expression/escape in pinned Companion patch literal' }
    return $decoded
}
if (Test-Path -LiteralPath $companionStamp) {
    if ([IO.File]::ReadAllText($companionStamp).Trim() -ne $companionPatchHash) { throw 'Companion patch source changed; use a fresh separately named build directory' }
} else {
    $header = [regex]::Match($companionPatchText,'(?s)const hookHeader = `((?:\\.|[^`])*)`;')
    if (!$header.Success) { throw 'Missing pinned Companion hook header' }
    $fileMatches = [regex]::Matches($companionPatchText,'file: "([^"]+)",')
    if ($fileMatches.Count -ne 3) { throw 'Unexpected pinned Companion patch file count' }
    $companionEdits = @()
    for ($fileIndex=0;$fileIndex -lt $fileMatches.Count;$fileIndex++) {
        $fileMatch = $fileMatches[$fileIndex]
        $sectionEnd = if ($fileIndex+1 -lt $fileMatches.Count) { $fileMatches[$fileIndex+1].Index } else { $companionPatchText.IndexOf('const STAMP_FILE') }
        $section = $companionPatchText.Substring($fileMatch.Index,$sectionEnd-$fileMatch.Index)
        $pairs = [regex]::Matches($section,'(?s)anchor: `((?:\\.|[^`])*)`,\s*replace: `((?:\\.|[^`])*)`')
        $relative = $fileMatch.Groups[1].Value
        if ($relative -notin @('smpl/smpl_structs.h','src/opus_decoder.c','smpl/smpl_core_decoder.c')) { throw 'Unexpected Companion patch destination' }
        $target = Join-Path $companionOpus $relative
        $contents = [IO.File]::ReadAllText($target).Replace("`r`n","`n")
        foreach ($pair in $pairs) {
            $anchor = Read-CompanionLiteral $pair.Groups[1].Value
            $replacement = Read-CompanionLiteral $pair.Groups[2].Value
            $position = $contents.IndexOf($anchor,[StringComparison]::Ordinal)
            if ($position -lt 0 -or $contents.IndexOf($anchor,$position+1,[StringComparison]::Ordinal) -ge 0) { throw "Companion patch anchor must occur exactly once: $relative" }
            $contents = $contents.Replace($anchor,$replacement)
            $companionEdits += $relative
        }
        [IO.File]::WriteAllText($target,$contents,$companionUtf8)
    }
    if ($companionEdits.Count -ne 9) { throw 'Unexpected Companion patch edit count' }
    [IO.File]::WriteAllText((Join-Path $companionOpus 'include/opus_smpl_hook.h'),(Read-CompanionLiteral $header.Groups[1].Value),$companionUtf8)
    [IO.File]::WriteAllText($companionStamp,$companionPatchHash+"`n",$companionUtf8)
}

$companionCc = Join-Path $companionProject 'zig-cc.cmd'
$companionAr = Join-Path $companionProject 'zig-ar.cmd'
$companionRanlib = Join-Path $companionProject 'zig-ranlib.cmd'
Set-Content -LiteralPath $companionCc -Encoding ascii -Value ('@"'+$ZigPath+'" cc -target x86_64-windows-gnu %*')
Set-Content -LiteralPath $companionAr -Encoding ascii -Value ('@"'+$ZigPath+'" ar %*')
Set-Content -LiteralPath $companionRanlib -Encoding ascii -Value ('@"'+$ZigPath+'" ranlib %*')
$companionCmake = @'
cmake_minimum_required(VERSION 3.21)
project(mvo_mlow_companion LANGUAGES C)
set(CMAKE_C_STANDARD 99)
set(OPUS_BUILD_SHARED_LIBRARY OFF CACHE BOOL "" FORCE)
set(OPUS_BUILD_TESTING OFF CACHE BOOL "" FORCE)
set(OPUS_BUILD_PROGRAMS OFF CACHE BOOL "" FORCE)
set(OPUS_DISABLE_INTRINSICS ON CACHE BOOL "" FORCE)
set(OPUS_INSTALL_PKG_CONFIG_MODULE OFF CACHE BOOL "" FORCE)
set(OPUS_INSTALL_CMAKE_CONFIG_MODULE OFF CACHE BOOL "" FORCE)
add_subdirectory("${MVO_OPUS_SOURCE}" opus)
target_compile_definitions(opus PRIVATE DLL_EXPORT)
add_library(mvo_mlow_companion SHARED
  "${MVO_PROJECT_ROOT}/native/companion_bridge.c"
  "${MVO_COMPANION_SOURCE}/native/companion.c"
  "${MVO_COMPANION_SOURCE}/native/companion_decoder.c"
  "${MVO_COMPANION_SOURCE}/native/companion_features.c"
  "${MVO_COMPANION_SOURCE}/native/companion_net.c")
target_include_directories(mvo_mlow_companion PRIVATE "${MVO_OPUS_SOURCE}/include" "${MVO_COMPANION_SOURCE}/native")
target_link_libraries(mvo_mlow_companion PRIVATE "-Wl,--whole-archive" opus "-Wl,--no-whole-archive")
target_link_options(mvo_mlow_companion PRIVATE "-Wl,--export-all-symbols")
set_target_properties(mvo_mlow_companion PROPERTIES PREFIX "" OUTPUT_NAME "libmvo-mlow-companion")
add_executable(mvo_companion_test
  "${MVO_COMPANION_SOURCE}/native/companion_test.c"
  "${MVO_COMPANION_SOURCE}/native/companion.c"
  "${MVO_COMPANION_SOURCE}/native/companion_features.c"
  "${MVO_COMPANION_SOURCE}/native/companion_net.c")
target_include_directories(mvo_companion_test PRIVATE "${MVO_COMPANION_SOURCE}/native")
'@
[IO.File]::WriteAllText((Join-Path $companionProject 'CMakeLists.txt'),$companionCmake,$companionUtf8)
& $CmakePath -S $companionProject -B $companionBuild -G Ninja "-DCMAKE_MAKE_PROGRAM=$NinjaPath" "-DCMAKE_C_COMPILER=$companionCc" "-DCMAKE_AR=$companionAr" "-DCMAKE_RANLIB=$companionRanlib" '-DCMAKE_BUILD_TYPE=Release' '-DCMAKE_POLICY_VERSION_MINIMUM=3.5' "-DMVO_OPUS_SOURCE=$companionOpus" "-DMVO_COMPANION_SOURCE=$companionSource" "-DMVO_PROJECT_ROOT=$companionRoot"
if ($LASTEXITCODE -ne 0) { throw 'Could not configure native Companion' }
& $CmakePath --build $companionBuild --target mvo_mlow_companion mvo_companion_test -j $Jobs
if ($LASTEXITCODE -ne 0) { throw 'Could not build native Companion' }
$companionDll = Join-Path $companionBuild 'libmvo-mlow-companion.dll'
$companionTest = Join-Path $companionBuild 'mvo_companion_test.exe'
if (!(Test-Path -LiteralPath $companionDll) -or !(Test-Path -LiteralPath $companionTest)) { throw 'Native Companion build produced no DLL/test' }
$companionPriorModel = $env:COMPANION_MODEL
try {
    Remove-Item -LiteralPath 'Env:COMPANION_MODEL' -ErrorAction SilentlyContinue
    & $companionTest
    if ($LASTEXITCODE -ne 0) { throw 'Native Companion synthetic property checks failed' }
} finally { if ($null -ne $companionPriorModel) { $env:COMPANION_MODEL=$companionPriorModel } }
Copy-Item -LiteralPath $companionDll -Destination (Join-Path $companionOutput 'libmvo-mlow-companion.dll') -Force
foreach ($notice in @('LICENSE','THIRD_PARTY_NOTICES.md')) { Copy-Item -LiteralPath (Join-Path $companionSource $notice) -Destination (Join-Path $companionLicenseDir ('companion-'+$notice)) -Force }
Copy-Item -LiteralPath (Join-Path $companionOpus 'COPYING') -Destination (Join-Path $companionLicenseDir 'companion-opus-COPYING.txt') -Force
[ordered]@{
    source=$companionArchiveUrl; revision=$companionRevision; sourceSha256=$companionArchiveHash
    opusSource=$companionOpusUrl; opusSourceSha256=$companionOpusHash; patchSourceSha256=$companionPatchHash
    bridgeSha256=(Get-FileHash -LiteralPath (Join-Path $companionRoot 'native/companion_bridge.c') -Algorithm SHA256).Hash.ToLowerInvariant()
    binary='libmvo-mlow-companion.dll'; binarySha256=(Get-FileHash -LiteralPath $companionDll -Algorithm SHA256).Hash.ToLowerInvariant()
    nativeAbi=@('oc_companion_create','oc_companion_destroy','oc_companion_reset','oc_decoder_set_companion'); hookRequest=4880
    compiler='Zig 0.14.1'; architecture='x86_64-windows-gnu'; syntheticPropertyTests='passed'
    modelBundled=$false; modelRequired='DNNw weights supplied explicitly by the application'
} | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath (Join-Path $companionOutput 'companion-build.json') -Encoding utf8
Write-Output "Optional native Companion installed at $companionOutput/libmvo-mlow-companion.dll (model not bundled)"
