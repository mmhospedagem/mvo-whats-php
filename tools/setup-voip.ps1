param([int]$Jobs=3,[string]$ZigPath='',[string]$CmakePath='',[string]$NinjaPath='')
$ErrorActionPreference='Stop'
$voipRoot=Split-Path -Parent $PSScriptRoot
if($Jobs -lt 1 -or $Jobs -gt 32){throw 'Jobs must be 1..32'}
$voipCache=Join-Path $voipRoot '.cache/native'
$voipNative=Join-Path $voipRoot '.dev/native'
New-Item -ItemType Directory -Force -Path $voipCache,$voipNative | Out-Null
if(!$ZigPath){
    $ZigPath=Join-Path $voipNative 'zig-x86_64-windows-0.14.1/zig.exe'
    if(!(Test-Path -LiteralPath $ZigPath)){
        $voipZigZip=Join-Path $voipCache 'zig.zip'
        if(!(Test-Path -LiteralPath $voipZigZip)){Invoke-WebRequest 'https://ziglang.org/download/0.14.1/zig-x86_64-windows-0.14.1.zip' -OutFile $voipZigZip}
        if((Get-FileHash -LiteralPath $voipZigZip -Algorithm SHA256).Hash -ne '554f5378228923ffd558eac35e21af020c73789d87afeabf4bfd16f2e6feed2c'){throw 'Zig SHA256 mismatch'}
        Expand-Archive -LiteralPath $voipZigZip -DestinationPath $voipNative
    }
}
if(!$CmakePath){$CmakePath=Join-Path $voipNative 'build-tools/cmake/data/bin/cmake.exe'}
if(!$NinjaPath){$NinjaPath=Join-Path $voipNative 'build-tools/bin/ninja.exe'}
if(!(Test-Path -LiteralPath $CmakePath) -or !(Test-Path -LiteralPath $NinjaPath)){
    # Python only installs the build tools; no Python is used by the PHP runtime.
    & python -m pip install --target (Join-Path $voipNative 'build-tools') 'cmake==4.4.4' 'ninja==1.13.2'
    if($LASTEXITCODE -ne 0){throw 'Could not install native build tools'}
}
foreach($voipTool in @($ZigPath,$CmakePath,$NinjaPath)){if(!(Test-Path -LiteralPath $voipTool)){throw "Native build tool missing: $voipTool"}}
$voipSources=@(
    @{Directory='libdatachannel';Url='https://github.com/paullouisageneau/libdatachannel.git';Tag='v0.24.6';Commit='6b1e2e620f1e37f0eafeee702eaea0043cb305fd'},
    @{Directory='mbedtls';Url='https://github.com/Mbed-TLS/mbedtls.git';Tag='mbedtls-3.6.7';Commit='068ff080b369adfac81509f9b57b2afabaf82dc5'}
)
foreach($voipSource in $voipSources){
    $voipCheckout=Join-Path $voipCache $voipSource.Directory
    if(!(Test-Path -LiteralPath (Join-Path $voipCheckout '.git'))){
        & git clone --branch $voipSource.Tag --depth 1 --recurse-submodules --shallow-submodules $voipSource.Url $voipCheckout
        if($LASTEXITCODE -ne 0){throw 'Native dependency clone failed'}
    }
    $voipHead=& git -C $voipCheckout rev-parse HEAD
    if($LASTEXITCODE -ne 0 -or $voipHead -ne $voipSource.Commit){throw "Unexpected native source revision: $voipCheckout"}
}
$voipBuild=Join-Path $voipCache 'rtc-build'
$voipOutput=Join-Path $voipNative 'bin'
New-Item -ItemType Directory -Force -Path $voipOutput | Out-Null
& $CmakePath -S (Join-Path $voipRoot 'native') -B $voipBuild -G Ninja "-DCMAKE_MAKE_PROGRAM=$NinjaPath" "-DCMAKE_C_COMPILER=$ZigPath" '-DCMAKE_C_COMPILER_ARG1=cc' "-DCMAKE_CXX_COMPILER=$ZigPath" '-DCMAKE_CXX_COMPILER_ARG1=c++' "-DMVO_ZIG_EXECUTABLE=$ZigPath" "-DMBEDTLS_SOURCE=$(Join-Path $voipCache 'mbedtls')" "-DDATACHANNEL_SOURCE=$(Join-Path $voipCache 'libdatachannel')" '-DCMAKE_BUILD_TYPE=Release' '-DCMAKE_POLICY_VERSION_MINIMUM=3.10'
if($LASTEXITCODE -ne 0){throw 'Native WebRTC configure failed'}
& $CmakePath --build $voipBuild --target mvo_rtc --parallel $Jobs
if($LASTEXITCODE -ne 0){throw 'Native WebRTC build failed'}
Copy-Item -LiteralPath (Join-Path $voipBuild 'datachannel/libdatachannel.dll') -Destination (Join-Path $voipOutput 'libdatachannel.dll') -Force
Copy-Item -LiteralPath (Join-Path $voipBuild 'mvo_rtc.dll') -Destination (Join-Path $voipOutput 'mvo_rtc.dll') -Force
$voipSymbols=Join-Path $voipBuild 'mvo_rtc.pdb'
if(Test-Path -LiteralPath $voipSymbols){Copy-Item -LiteralPath $voipSymbols -Destination (Join-Path $voipOutput 'mvo_rtc.pdb') -Force}
$voipLicenses=Join-Path $voipOutput 'licenses'
New-Item -ItemType Directory -Force -Path $voipLicenses | Out-Null
foreach($voipLicense in @('libdatachannel/LICENSE','libdatachannel/deps/libjuice/LICENSE','libdatachannel/deps/plog/LICENSE','libdatachannel/deps/usrsctp/LICENSE.md','libdatachannel/deps/json/LICENSE.MIT','mbedtls/LICENSE','mbedtls/LICENSES/Apache-2.0.txt')){
    $voipLicensePath=Join-Path $voipCache $voipLicense
    if(Test-Path -LiteralPath $voipLicensePath){Copy-Item -LiteralPath $voipLicensePath -Destination (Join-Path $voipLicenses ($voipLicense.Replace('/','-'))) -Force}
}
$voipManifest=[ordered]@{bridgeAbi=1;bridgeLibrary='mvo_rtc.dll';bridgeApiPrefix='mvo_rtc_';compiler='Zig 0.14.1';sources=$voipSources;bridgeSha256=(Get-FileHash (Join-Path $voipOutput 'mvo_rtc.dll')).Hash.ToLowerInvariant();datachannelSha256=(Get-FileHash (Join-Path $voipOutput 'libdatachannel.dll')).Hash.ToLowerInvariant()}
$voipManifest | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath (Join-Path $voipOutput 'build.json') -Encoding utf8
Write-Output "Native ICE/DTLS/SCTP installed: $voipOutput. Enable ext-ffi in the project PHP configuration."
