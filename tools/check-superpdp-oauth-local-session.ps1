# HTTP regression against the already running local OBSERVATION server only.
# No browser, server start, OAuth redirect, provider request or SQL operation.
$ErrorActionPreference = 'Stop'

function Assert-LocalDiagnostic([bool]$Condition) {
    if (-not $Condition) { throw 'local_diagnostic_check_failed' }
}

function Invoke-LocalDiagnostic(
    [ValidateSet('GET', 'POST')][string]$Method,
    [ValidateSet('/', '/oauth/start')][string]$Path,
    [System.Net.CookieContainer]$Cookies,
    [string]$Form = ''
) {
    $request = [System.Net.HttpWebRequest]::Create('http://127.0.0.1:8765' + $Path)
    $request.Proxy = $null
    $request.AllowAutoRedirect = $false
    $request.CookieContainer = $Cookies
    $request.Method = $Method
    $request.Timeout = 5000
    if ($Method -eq 'POST') {
        $request.Headers['Origin'] = 'http://127.0.0.1:8765'
        $request.ContentType = 'application/x-www-form-urlencoded'
        $payload = [System.Text.Encoding]::UTF8.GetBytes($Form)
        $request.ContentLength = $payload.Length
        $stream = $request.GetRequestStream()
        try { $stream.Write($payload, 0, $payload.Length) } finally { $stream.Dispose() }
    }
    try { $response = $request.GetResponse() }
    catch [System.Net.WebException] {
        if ($null -eq $_.Exception.Response) { throw 'local_diagnostic_unavailable' }
        $response = $_.Exception.Response
    }
    try {
        # Refuse to test a server whose real OAuth mode is enabled.
        Assert-LocalDiagnostic ($response.Headers['X-SimpleDevis-Diagnostic-Mode'] -ceq 'observe')
        $reader = New-Object System.IO.StreamReader($response.GetResponseStream())
        try { $body = $reader.ReadToEnd() } finally { $reader.Dispose() }
        return [pscustomobject]@{ Status = [int]$response.StatusCode; Body = $body; CookieHeader = $response.Headers['Set-Cookie'] }
    } finally { $response.Dispose() }
}

function Get-LocalForm([System.Net.CookieContainer]$Cookies) {
    $page = Invoke-LocalDiagnostic 'GET' '/' $Cookies
    Assert-LocalDiagnostic ($page.Status -eq 200)
    $nonce = [regex]::Match($page.Body, 'name="csrf" value="([a-f0-9]+)"')
    Assert-LocalDiagnostic $nonce.Success
    return [pscustomobject]@{ Form = 'csrf=' + $nonce.Groups[1].Value; CookieHeader = $page.CookieHeader }
}

try {
    $diagnosticCookies = New-Object System.Net.CookieContainer
    $firstForm = Get-LocalForm $diagnosticCookies
    $attributes = @($firstForm.CookieHeader -split ';' | Select-Object -Skip 1 | ForEach-Object { $_.Trim() })
    Assert-LocalDiagnostic ($attributes -contains 'path=/')
    Assert-LocalDiagnostic ($attributes -contains 'HttpOnly')
    Assert-LocalDiagnostic ($attributes -contains 'SameSite=Lax')
    Assert-LocalDiagnostic (-not ($attributes -contains 'Secure'))
    Assert-LocalDiagnostic (@($attributes | Where-Object { $_ -like 'Domain=*' }).Count -eq 0)

    $firstPost = Invoke-LocalDiagnostic 'POST' '/oauth/start' $diagnosticCookies $firstForm.Form
    $first = $firstPost.Body | ConvertFrom-Json
    Assert-LocalDiagnostic ($firstPost.Status -eq 200 -and $first.accepted -and $first.session_cookie_present -and $first.checks.csrf_matches -and $first.provider_requests -eq 0)

    $repeatPost = Invoke-LocalDiagnostic 'POST' '/oauth/start' $diagnosticCookies $firstForm.Form
    $repeat = $repeatPost.Body | ConvertFrom-Json
    Assert-LocalDiagnostic ($repeatPost.Status -eq 403 -and -not $repeat.accepted -and $repeat.session_cookie_present -and -not $repeat.checks.session_csrf_present -and $repeat.reason -ceq 'form_missing_or_already_consumed')

    $freshForm = Get-LocalForm $diagnosticCookies
    $freshPost = Invoke-LocalDiagnostic 'POST' '/oauth/start' $diagnosticCookies $freshForm.Form
    $fresh = $freshPost.Body | ConvertFrom-Json
    Assert-LocalDiagnostic ($freshPost.Status -eq 200 -and $fresh.accepted -and $fresh.checks.csrf_matches)

    $lostCookieForm = Get-LocalForm $diagnosticCookies
    $emptyCookies = New-Object System.Net.CookieContainer
    $lostPost = Invoke-LocalDiagnostic 'POST' '/oauth/start' $emptyCookies $lostCookieForm.Form
    $lost = $lostPost.Body | ConvertFrom-Json
    Assert-LocalDiagnostic ($lostPost.Status -eq 403 -and -not $lost.accepted -and -not $lost.session_cookie_present -and $lost.reason -ceq 'session_cookie_missing' -and -not $lost.checks.csrf_matches)

    [pscustomobject]@{ mode = 'local_http_observation'; cookie_attributes = 'passed'; get_post_continuity = 'passed'; replay_refused = 'passed'; fresh_form_accepted = 'passed'; missing_cookie_refused = 'passed'; provider_requests = 0 } | ConvertTo-Json -Compress
} catch {
    # Never print exceptions, raw responses, Cookie/Set-Cookie, form values or headers.
    Write-Output '{"mode":"local_http_observation","result":"failed","error":"local_check_failed_no_details_disclosed"}'
    exit 1
}
