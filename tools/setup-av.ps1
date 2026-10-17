param()
$ErrorActionPreference = 'Stop'
$avRoot = Split-Path -Parent $PSScriptRoot
$avCache = Join-Path $avRoot '.cache/native'
$avInstall = Join-Path $avRoot '.dev/native/ffmpeg'
$avArchive = Join-Path $avCache 'ffmpeg-9.0.2-essentials_build.zip'
$avSource = 'https://www.gyan.dev/ffmpeg/builds/packages/ffmpeg-9.0.2-essentials_build.zip'
$avExpected = '60f467265b1e312373dbcd92200c2618a74850f98d3d078e94296bb3fa2047ba'
New-Item -ItemType Directory -Force -Path $avCache, $avInstall | Out-Null
if (!(Test-Path -LiteralPath $avArchive)) { Invoke-WebRequest -Uri $avSource -OutFile $avArchive }
$avActual = (Get-FileHash -LiteralPath $avArchive -Algorithm SHA256).Hash.ToLowerInvariant()
if ($avActual -ne $avExpected) { throw 'FFmpeg archive SHA-256 mismatch' }
Expand-Archive -LiteralPath $avArchive -DestinationPath $avInstall -Force
$avPackage = Join-Path $avInstall 'ffmpeg-9.0.2-essentials_build'
foreach ($avName in @('ffmpeg', 'ffplay', 'ffprobe')) {
    if (!(Test-Path -LiteralPath (Join-Path $avPackage "bin/$avName.exe"))) { throw "FFmpeg package missing $avName" }
}
$avManifest = [ordered]@{
    officialDistributorListing = 'https://ffmpeg.org/download.html'
    distributor = 'https://www.gyan.dev/ffmpeg/builds/'
    archive = $avSource
    sha256 = $avExpected
    version = '9.0.2'
    license = 'GPLv3; license and notices retained inside the extracted package'
    executableDirectory = 'ffmpeg-9.0.2-essentials_build/bin'
}
$avManifest | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $avInstall 'av-install.json') -Encoding utf8
$avVersion = & (Join-Path $avPackage 'bin/ffmpeg.exe') -version
if ($LASTEXITCODE -ne 0) { throw 'Installed FFmpeg did not start' }
$avVersion | Select-Object -First 1
Write-Output "FFmpeg, ffplay and ffprobe installed at $avPackage"
