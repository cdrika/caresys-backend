param([int]$ApiPort=8443,[int]$WebPort=8444)
$ErrorActionPreference='Stop'
$projectRoot=(Resolve-Path (Join-Path $PSScriptRoot '../..')).Path.Replace('\','/')
$runtime=Join-Path $PSScriptRoot 'runtime'
$apache='C:/xampp/apache/bin/httpd.exe'
$openssl='C:/xampp/apache/bin/openssl.exe'
if (!(Test-Path -LiteralPath $apache)) { throw 'XAMPP Apache is required at C:/xampp/apache.' }
New-Item -ItemType Directory -Force -Path $runtime | Out-Null
$runtime=(Resolve-Path $runtime).Path.Replace('\','/')
foreach($folder in @('api-env','api-writable/cache','api-writable/logs','api-writable/session','api-writable/debugbar','api-writable/uploads')) {
    New-Item -ItemType Directory -Force -Path "$runtime/$folder" | Out-Null
}
# Only runtime DML credentials go into the API process. Never copy migration/test credentials.
$environmentLines=Get-Content -LiteralPath "$projectRoot/.env"
if (!($environmentLines -match '^\s*mobile\.webBaseURL\s*=')) {
    Add-Content -LiteralPath "$projectRoot/.env" -Value "`nmobile.webBaseURL = 'https://localhost:$WebPort/'"
}
if (!($environmentLines -match '^\s*mobile\.apiBaseURL\s*=')) {
    Add-Content -LiteralPath "$projectRoot/.env" -Value "`nmobile.apiBaseURL = 'https://127.0.0.1:$ApiPort/'"
}
$apiLines=@("CI_ENVIRONMENT = development")
$apiLines+=@($environmentLines | Where-Object { $_ -match '^\s*database\.default\.' })
$apiLines+=@('session.driver = CodeIgniter\Session\Handlers\FileHandler',"session.savePath = '$runtime/api-writable/session'",'app.indexPage = ')
Set-Content -LiteralPath "$runtime/api-env/.env" -Value $apiLines -Encoding utf8NoBOM
if (!(Test-Path -LiteralPath "$runtime/localhost.crt")) {
    & $openssl req -x509 -newkey rsa:2048 -nodes -keyout "$runtime/localhost.key" -out "$runtime/localhost.crt" -days 30 -subj '/CN=localhost' -addext 'subjectAltName=DNS:localhost,IP:127.0.0.1' -addext 'basicConstraints=critical,CA:FALSE' -config 'C:/xampp/apache/conf/openssl.cnf' 2> "$runtime/certificate-generation.log"
    if ($LASTEXITCODE -ne 0) { throw 'Local TLS certificate generation failed. See runtime/certificate-generation.log.' }
}
$base=@"
ServerRoot "C:/xampp/apache"
ServerTokens Prod
ServerSignature Off
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule authz_host_module modules/mod_authz_host.so
LoadModule dir_module modules/mod_dir.so
LoadModule mime_module modules/mod_mime.so
LoadModule log_config_module modules/mod_log_config.so
LoadModule env_module modules/mod_env.so
LoadModule rewrite_module modules/mod_rewrite.so
LoadModule headers_module modules/mod_headers.so
LoadModule setenvif_module modules/mod_setenvif.so
LoadModule socache_shmcb_module modules/mod_socache_shmcb.so
LoadModule ssl_module modules/mod_ssl.so
LoadFile "C:/xampp/php/php8ts.dll"
LoadFile "C:/xampp/php/libpq.dll"
LoadFile "C:/xampp/php/libsqlite3.dll"
LoadModule php_module "C:/xampp/php/php8apache2_4.dll"
PHPIniDir "C:/xampp/php"
TypesConfig conf/mime.types
DocumentRoot "$projectRoot/public"
DirectoryIndex index.php
SSLProtocol -all +TLSv1.2 +TLSv1.3
SSLCertificateFile "$runtime/localhost.crt"
SSLCertificateKeyFile "$runtime/localhost.key"
SSLEngine on
LimitRequestBody 65536
Timeout 30
KeepAlive On
MaxKeepAliveRequests 100
SetEnvIf Authorization "(.+)" HTTP_AUTHORIZATION=`$1
<Directory />
    AllowOverride None
    Require all denied
</Directory>
<Directory "$projectRoot/public">
    Options -Indexes +FollowSymLinks
    AllowOverride None
    Require local
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [L]
</Directory>
<FilesMatch "\.php$">
    SetHandler application/x-httpd-php
</FilesMatch>
<FilesMatch "^\.">
    Require all denied
</FilesMatch>
Header always set X-Content-Type-Options "nosniff"
Header always set Referrer-Policy "no-referrer"
"@
foreach($surface in @('api','web')) {
    $port=if($surface -eq 'api'){$ApiPort}else{$WebPort}
    $hostName=if($surface -eq 'api'){'127.0.0.1'}else{'localhost'}
    $config=$base+@"

Listen 127.0.0.1:$port
ServerName ${hostName}:$port
PidFile "$runtime/$surface.pid"
ErrorLog "$runtime/$surface-error.log"
LogFormat "%h %t %m %U %>s" private
CustomLog "$runtime/$surface-access.log" private
SetEnv CARESYS_SURFACE $surface
SetEnv CARESYS_BASE_URL https://${hostName}:$port/
SetEnv CARESYS_WEB_URL https://localhost:$WebPort
"@
    if($surface -eq 'api') {
        $config+="`nSetEnv CARESYS_ENV_DIR $runtime/api-env/`nSetEnv CARESYS_WRITABLE $runtime/api-writable/`n"
        # Route every API-host request through the pre-boot gate, including static assets.
        $config+="`n<Directory `"$projectRoot/public`">`n    RewriteEngine On`n    RewriteRule ^ index.php [END]`n</Directory>`n"
    }
    $configPath="$runtime/$surface.conf"
    Set-Content -LiteralPath $configPath -Value $config -Encoding utf8NoBOM
    & $apache -t -f $configPath
    if ($LASTEXITCODE -ne 0) { throw "Invalid $surface Apache configuration." }
    $listener=Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue
    if ($listener) {
        $existing=Get-CimInstance Win32_Process -Filter "ProcessId=$($listener[0].OwningProcess)"
        if (!$existing.CommandLine.Contains($configPath)) { throw "Port $port is already used by another server." }
        Write-Output "$surface is already running on port $port."
    } else {
        Start-Process -FilePath $apache -ArgumentList @('-f',"`"$configPath`"") -WorkingDirectory 'C:/xampp/apache' -WindowStyle Hidden
    }
}
Write-Output "Pairing UI: https://localhost:$WebPort/mobile-enrollment"
Write-Output "Mobile API: https://127.0.0.1:$ApiPort/mobile/v1"
Write-Output 'Local certificate: deploy/local/runtime/localhost.crt (self-signed; trust only for local development).'
