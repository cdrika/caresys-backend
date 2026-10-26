$ErrorActionPreference='Stop'
$runtime=(Resolve-Path (Join-Path $PSScriptRoot 'runtime')).Path.Replace('\','/')
foreach($surface in @('api','web')) {
    $configPath="$runtime/$surface.conf"
    $owned=@(Get-CimInstance Win32_Process -Filter "Name='httpd.exe'" | Where-Object { $_.CommandLine -and $_.CommandLine.Contains($configPath) })
    if($owned.Count) { Stop-Process -Id @($owned.ProcessId) -Force -ErrorAction SilentlyContinue }
}
