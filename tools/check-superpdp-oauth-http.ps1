# Real OAuth-mode HTTP regression. Fixed loopback paths only; redirects are
# NEVER followed, and no callback or provider endpoint is requested.
param([Parameter(Mandatory = $true)][string]$SessionDirectory, [switch]$Capture)
$ErrorActionPreference = 'Stop'

function Assert-HttpDiagnostic([bool]$Condition, [string]$Check) {
    if (-not $Condition) { throw $Check }
}

function Invoke-HttpDiagnostic(
    [ValidateSet('GET', 'POST')][string]$Method,
    [ValidateSet('/', '/favicon.ico', '/oauth/start')][string]$Path,
    [Net.CookieContainer]$Cookies,
    [string]$Form = '',
    [string]$Origin = 'http://127.0.0.1:8765'
) {
    $request = [Net.HttpWebRequest]::Create('http://127.0.0.1:8765' + $Path)
    $request.Proxy = $null
    $request.AllowAutoRedirect = $false
    $request.CookieContainer = $Cookies
    $request.Method = $Method
    $request.Timeout = 5000
    if ($Method -eq 'POST') {
        $request.Headers['Origin'] = $Origin
        $request.ContentType = 'application/x-www-form-urlencoded'
        $bytes = [Text.Encoding]::UTF8.GetBytes($Form)
        $request.ContentLength = $bytes.Length
        $stream = $request.GetRequestStream()
        try { $stream.Write($bytes, 0, $bytes.Length) } finally { $stream.Dispose() }
    }
    try { $response = $request.GetResponse() }
    catch [Net.WebException] {
        $response = $_.Exception.Response
        if ($null -eq $response) { throw 'http_unavailable' }
    }
    try {
        Assert-HttpDiagnostic ($response.Headers['X-SimpleDevis-Diagnostic-Mode'] -ceq 'oauth') 'oauth_mode'
        if ($Capture) {
            Assert-HttpDiagnostic ($response.Headers['X-SimpleDevis-Diagnostic-Capture'] -ceq 'local-only') 'capture_enabled'
            Assert-HttpDiagnostic (-not $response.Headers['Location']) 'no_external_navigation'
        }
        $reader = New-Object IO.StreamReader($response.GetResponseStream())
        try { $body = $reader.ReadToEnd() } finally { $reader.Dispose() }
        return [pscustomobject]@{
            Status = [int]$response.StatusCode; Body = $body
            Location = $response.Headers['Location']; CookieHeader = $response.Headers['Set-Cookie']
            Revision = $response.Headers['X-SimpleDevis-Diagnostic-Revision']
        }
    } finally { $response.Dispose() }
}

function Get-HttpForm([Net.CookieContainer]$Cookies) {
    $page = Invoke-HttpDiagnostic 'GET' '/' $Cookies
    Assert-HttpDiagnostic ($page.Status -eq 200) 'get_status'
    $match = [regex]::Match($page.Body, 'name="csrf" value="([a-f0-9]{64})"')
    Assert-HttpDiagnostic $match.Success 'html_nonce'
    $cookie = $Cookies.GetCookies('http://127.0.0.1:8765/')['simpledevis_oauth_diagnostic']
    Assert-HttpDiagnostic ($null -ne $cookie -and $cookie.Value -match '^[a-zA-Z0-9,-]+$') 'session_cookie'
    $file = Join-Path $SessionDirectory ('sess_' + $cookie.Value)
    Assert-HttpDiagnostic (Test-Path -LiteralPath $file -PathType Leaf) 'session_file'
    $nonce = $match.Groups[1].Value
    Assert-HttpDiagnostic ([IO.File]::ReadAllText($file).Contains('csrf|s:64:"' + $nonce + '";')) 'nonce_saved'
    $payload = 'csrf=' + $nonce
    $diagnosticId = $null
    if ($Capture) {
        $metadata = [regex]::Match($page.Body, 'name="diagnostic_id" value="([a-f0-9]{32})"')
        Assert-HttpDiagnostic $metadata.Success 'diagnostic_id_in_html'
        $diagnosticId = $metadata.Groups[1].Value
        Assert-HttpDiagnostic ($page.Body.Contains($page.Revision)) 'html_revision'
        $payload += '&diagnostic_id=' + $diagnosticId
    }
    return [pscustomobject]@{ Form = $payload; Nonce = $nonce; Id = $cookie.Value; File = $file; CookieHeader = $page.CookieHeader; DiagnosticId = $diagnosticId }
}

try {
    $cookies = New-Object Net.CookieContainer
    $form = Get-HttpForm $cookies
    Assert-HttpDiagnostic ($form.CookieHeader -match '(?i);\s*HttpOnly' -and $form.CookieHeader -match '(?i);\s*SameSite=Lax') 'cookie_attributes'
    $favicon = Invoke-HttpDiagnostic 'GET' '/favicon.ico' $cookies
    Assert-HttpDiagnostic ($favicon.Status -eq 404 -and [IO.File]::ReadAllText($form.File).Contains($form.Nonce)) 'unrelated_get_preserves_nonce'

    foreach ($origin in @('null', 'https://foreign.invalid')) {
        $refused = Invoke-HttpDiagnostic 'POST' '/oauth/start' $cookies $form.Form $origin
        Assert-HttpDiagnostic ($refused.Status -eq 403 -and ($refused.Body | ConvertFrom-Json).reason -ceq 'origin_invalid' -and -not $refused.Location) 'origin_refused'
        Assert-HttpDiagnostic ([IO.File]::ReadAllText($form.File).Contains($form.Nonce)) 'refusal_preserves_nonce'
    }
    $tampered = Invoke-HttpDiagnostic 'POST' '/oauth/start' $cookies 'csrf=wrong'
    Assert-HttpDiagnostic ($tampered.Status -eq 403 -and -not $tampered.Location) 'nonce_mismatch_refused'
    Assert-HttpDiagnostic ([IO.File]::ReadAllText($form.File).Contains($form.Nonce)) 'mismatch_preserves_nonce'

    $accepted = Invoke-HttpDiagnostic 'POST' '/oauth/start' $cookies $form.Form
    $acceptedStatus = if ($Capture) { 200 } else { 303 }
    if ($Capture) {
        $captureReport = $accepted.Body | ConvertFrom-Json
        Assert-HttpDiagnostic ($accepted.Status -eq 200 -and $captureReport.result -ceq 'local_capture_complete') 'capture_complete'
        Assert-HttpDiagnostic ($captureReport.diagnostic.diagnostic_id -ceq $form.DiagnosticId -and $captureReport.diagnostic.session_continuity -ceq 'same' -and $captureReport.diagnostic.form_revision_matches -and $captureReport.diagnostic.csrf_matches) 'capture_correlation'
    } else {
        Assert-HttpDiagnostic ($accepted.Status -eq 303 -and $accepted.Location.StartsWith('https://api.superpdp.tech/oauth2/authorize?')) 'valid_post_redirect'
    }
    Assert-HttpDiagnostic ($cookies.GetCookies('http://127.0.0.1:8765/')['simpledevis_oauth_diagnostic'].Value -ceq $form.Id) 'session_id_stable'
    $saved = [IO.File]::ReadAllText($form.File)
    Assert-HttpDiagnostic (-not $saved.Contains('csrf|') -and $saved.Contains('pending|') -and $saved.Contains('verifier')) 'accepted_form_consumed_pkce_saved'
    $replay = Invoke-HttpDiagnostic 'POST' '/oauth/start' $cookies $form.Form
    Assert-HttpDiagnostic ($replay.Status -eq 403 -and ($replay.Body | ConvertFrom-Json).reason -ceq 'form_missing_or_already_consumed' -and -not $replay.Location) 'replay_refused'

    $fresh = Get-HttpForm $cookies
    $emptyCookies = New-Object Net.CookieContainer
    $lost = Invoke-HttpDiagnostic 'POST' '/oauth/start' $emptyCookies $fresh.Form
    Assert-HttpDiagnostic ($lost.Status -eq 403 -and -not $lost.Location) 'missing_cookie_refused'
    if ($Capture) {
        $lostReport = $lost.Body | ConvertFrom-Json
        Assert-HttpDiagnostic (-not $lostReport.diagnostic.cookie_present -and $lostReport.diagnostic.session_continuity -ceq 'different' -and -not $lostReport.diagnostic.nonce_server_present) 'lost_session_classified'
        $replayReport = $replay.Body | ConvertFrom-Json
        Assert-HttpDiagnostic ($replayReport.diagnostic.session_continuity -ceq 'same' -and -not $replayReport.diagnostic.nonce_server_present) 'consumed_form_classified'
        $invalidCookies = New-Object Net.CookieContainer
        $invalidCookie = New-Object Net.Cookie('simpledevis_oauth_diagnostic', ([Guid]::NewGuid().ToString('N')), '/', '127.0.0.1')
        $invalidCookies.Add($invalidCookie)
        $invalid = Invoke-HttpDiagnostic 'POST' '/oauth/start' $invalidCookies $fresh.Form
        $invalidReport = $invalid.Body | ConvertFrom-Json
        Assert-HttpDiagnostic ($invalid.Status -eq 403 -and $invalidReport.diagnostic.cookie_present -and -not $invalidReport.diagnostic.incoming_cookie_retained -and $invalidReport.diagnostic.session_continuity -ceq 'different') 'strict_mode_cookie_rejected_classified'
        $rawTrace = [IO.File]::ReadAllText((Join-Path $SessionDirectory 'http-trace\trace-state.json'))
        Assert-HttpDiagnostic (-not $rawTrace.Contains($form.Id) -and -not $rawTrace.Contains($form.Nonce)) 'trace_contains_no_raw_session_or_csrf'
    }
    $recovered = Invoke-HttpDiagnostic 'POST' '/oauth/start' $cookies $fresh.Form
    Assert-HttpDiagnostic ($recovered.Status -eq $acceptedStatus) 'fresh_form_accepted'
    if ($Capture) {
        $olderTab = Get-HttpForm $cookies
        $newerTab = Get-HttpForm $cookies
        $stale = Invoke-HttpDiagnostic 'POST' '/oauth/start' $cookies $olderTab.Form
        $staleReport = $stale.Body | ConvertFrom-Json
        Assert-HttpDiagnostic ($stale.Status -eq 403 -and $staleReport.reason -ceq 'form_stale_or_changed' -and $staleReport.diagnostic.previous_form_id -ceq $newerTab.DiagnosticId -and $staleReport.diagnostic.session_continuity -ceq 'same') 'intervening_get_identified'
        $newerAccepted = Invoke-HttpDiagnostic 'POST' '/oauth/start' $cookies $newerTab.Form
        Assert-HttpDiagnostic ($newerAccepted.Status -eq 200) 'newest_tab_form_still_valid'
    }
    [pscustomobject]@{
        mode = 'oauth'; capture = [bool]$Capture; get_status = 200; valid_post_status = $acceptedStatus
        nonce_saved = $true; session_id_stable = $true; refused_post_preserves_nonce = $true
        replay_refused = $true; missing_cookie_refused = $true; pkce_saved = $true
        redirects_followed = 0; provider_requests = 0; result = 'passed'
    } | ConvertTo-Json -Compress
} catch {
    # Do not print exception details, session IDs, nonces, Location or response bodies.
    Write-Output '{"mode":"oauth","result":"failed","error":"local_http_check_failed"}'
    exit 1
}
