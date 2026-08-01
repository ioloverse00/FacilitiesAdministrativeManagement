param(
    [string] $BaseUrl = "http://localhost/uiv2-components",
    [string] $Username = "admin"
)

$ErrorActionPreference = "Stop"

function Read-ResponseBody {
    param([object] $Response)

    if ($null -eq $Response) { return "" }

    if ($Response.PSObject.Properties.Name -contains "Content") {
        return [string] $Response.Content
    }

    if ($Response.Exception -and $Response.Exception.Response) {
        $stream = $Response.Exception.Response.GetResponseStream()
        if ($stream) {
            $reader = New-Object System.IO.StreamReader($stream)
            try { return $reader.ReadToEnd() } finally { $reader.Dispose() }
        }
    }

    return ""
}

function Get-StatusCode {
    param([object] $Response)

    if ($Response.PSObject.Properties.Name -contains "StatusCode") {
        return [int] $Response.StatusCode
    }

    if ($Response.Exception -and $Response.Exception.Response) {
        return [int] $Response.Exception.Response.StatusCode
    }

    return 0
}

function Invoke-JsonRequest {
    param(
        [Parameter(Mandatory = $true)][string] $Uri,
        [Parameter(Mandatory = $true)][string] $Method,
        [Parameter(Mandatory = $true)][Microsoft.PowerShell.Commands.WebRequestSession] $Session,
        [string] $Body,
        [hashtable] $Headers = @{}
    )

    $parameters = @{
        Uri = $Uri
        Method = $Method
        WebSession = $Session
        Headers = $Headers
        ErrorAction = "Stop"
        UseBasicParsing = $true
    }

    if ($Body) {
        $parameters.ContentType = "application/json"
        $parameters.Body = $Body
    }

    if ($PSVersionTable.PSVersion.Major -ge 7) {
        $parameters.SkipHttpErrorCheck = $true
    }

    try {
        $response = Invoke-WebRequest @parameters
        $content = Read-ResponseBody $response
        $json = $null
        if ($content) {
            try { $json = $content | ConvertFrom-Json } catch { $json = $null }
        }

        return [pscustomobject]@{
            StatusCode = Get-StatusCode $response
            Body = $content
            Json = $json
        }
    } catch {
        $content = Read-ResponseBody $_
        $json = $null
        if ($content) {
            try { $json = $content | ConvertFrom-Json } catch { $json = $null }
        }

        return [pscustomobject]@{
            StatusCode = Get-StatusCode $_
            Body = $content
            Json = $json
        }
    }
}

function Write-CheckResult {
    param(
        [string] $Label,
        [int] $Actual,
        [int] $Expected,
        [string] $Message = ""
    )

    $status = if ($Actual -eq $Expected) { "PASS" } else { "FAIL" }
    $line = "{0}: {1} (HTTP {2}, expected {3})" -f $Label, $status, $Actual, $Expected
    if ($Message) { $line = "$line - $Message" }
    Write-Host $line
}

$securePassword = Read-Host -Prompt "Demo password" -AsSecureString
$passwordPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
try {
    $Password = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($passwordPointer)
} finally {
    if ($passwordPointer -ne [IntPtr]::Zero) {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($passwordPointer)
    }
}

$BaseUrl = $BaseUrl.TrimEnd("/")
$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession

$payload = @{
    username = $Username
    password = $Password
} | ConvertTo-Json -Compress

$loginResponse = Invoke-JsonRequest `
    -Uri "$BaseUrl/api/auth/login.php" `
    -Method POST `
    -Session $session `
    -Body $payload

$loginMessage = if ($loginResponse.Json -and $loginResponse.Json.message) { $loginResponse.Json.message } else { $loginResponse.Body }
Write-CheckResult -Label "Login" -Actual $loginResponse.StatusCode -Expected 200 -Message $loginMessage

$csrf = $null
if ($loginResponse.Json -and $loginResponse.Json.data) {
    if ($loginResponse.Json.data.csrf_token) { $csrf = $loginResponse.Json.data.csrf_token }
    elseif ($loginResponse.Json.data.csrfToken) { $csrf = $loginResponse.Json.data.csrfToken }
}

$currentUserResponse = Invoke-JsonRequest `
    -Uri "$BaseUrl/api/auth/me.php" `
    -Method GET `
    -Session $session

$currentUserMessage = if ($currentUserResponse.Json -and $currentUserResponse.Json.message) { $currentUserResponse.Json.message } else { $currentUserResponse.Body }
Write-CheckResult -Label "Current user" -Actual $currentUserResponse.StatusCode -Expected 200 -Message $currentUserMessage

if (-not $csrf -and $currentUserResponse.Json -and $currentUserResponse.Json.data) {
    if ($currentUserResponse.Json.data.csrf_token) { $csrf = $currentUserResponse.Json.data.csrf_token }
    elseif ($currentUserResponse.Json.data.csrfToken) { $csrf = $currentUserResponse.Json.data.csrfToken }
}

$logoutHeaders = @{}
if ($csrf) { $logoutHeaders["X-CSRF-Token"] = $csrf }

$logoutResponse = Invoke-JsonRequest `
    -Uri "$BaseUrl/api/auth/logout.php" `
    -Method POST `
    -Session $session `
    -Headers $logoutHeaders

$logoutMessage = if ($logoutResponse.Json -and $logoutResponse.Json.message) { $logoutResponse.Json.message } else { $logoutResponse.Body }
Write-CheckResult -Label "Logout" -Actual $logoutResponse.StatusCode -Expected 200 -Message $logoutMessage

$afterLogoutResponse = Invoke-JsonRequest `
    -Uri "$BaseUrl/api/auth/me.php" `
    -Method GET `
    -Session $session

$afterLogoutMessage = if ($afterLogoutResponse.Json -and $afterLogoutResponse.Json.message) { $afterLogoutResponse.Json.message } else { $afterLogoutResponse.Body }
Write-CheckResult -Label "Current user after logout" -Actual $afterLogoutResponse.StatusCode -Expected 401 -Message $afterLogoutMessage

if ($loginResponse.StatusCode -ne 200 -or $currentUserResponse.StatusCode -ne 200 -or $logoutResponse.StatusCode -ne 200 -or $afterLogoutResponse.StatusCode -ne 401) {
    exit 1
}

