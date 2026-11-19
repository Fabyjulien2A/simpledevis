$ErrorActionPreference = 'Stop'
$repo = (Get-Location).Path
$global:ResumeTestCalls = @()
$global:ResumeTestFailPreflight = $false
function global:php {
    $a = @($args)
    $global:ResumeTestCalls += ($a -join ' ')
    $global:LASTEXITCODE = 0
    if ($a[0] -eq 'artisan' -and $a[1] -eq 'superpdp:sandbox-refresh-oauth') {
        '{"status":"refreshed"}'; return
    }
    if ($a[0] -eq 'artisan' -and $a -contains '--diagnose') {
        $global:LASTEXITCODE = 1
        @{invoice_id=80;fingerprints=@{source_sha256='94b7bb7277990d8ac8e325a8755078d1cee54bddedf4e2769fc9f29cb6930e89'};oauth_prerequisites=@{'3'=@{connection_id=1;expired_or_expiring=$true};'4'=@{connection_id=2;expired_or_expiring=$true}}} | ConvertTo-Json -Depth 5 -Compress
        return
    }
    if ($a[0] -eq 'artisan') {
        @{status='passed';audit_reference=('invoice-party-checks/v1/3/80/'+('a'*64)+'.json');collection_reference='synthetic-collection'} | ConvertTo-Json -Compress
        return
    }
    if ($a -contains '--diagnose') {
        if ($global:ResumeTestFailPreflight) {$global:LASTEXITCODE=1;'{"status":"blocked"}';return}
        '{"status":"local_prerequisites_passed","authorization_proposal":{"expires_at":1999999999}}';return
    }
    [IO.File]::WriteAllText($global:ResumeTestJournal, 'synthetic-new-attempt-kept')
    $global:LASTEXITCODE=1
    '{"status":"blocked","reason":"conversion_failed","diagnostic_status":"stored"}'
}
function New-TestWorkspace {
    $root=Join-Path $repo ('tmp/resumption-test-'+[guid]::NewGuid().ToString('N'))
    $journalDir=Join-Path $root 'storage/app/invoice-cii/preparation-reservations'
    $null=New-Item -ItemType Directory -Path $journalDir -Force
    $null=New-Item -ItemType Directory -Path (Join-Path $root 'tools')
    $name='f1d94e99082d0d2a76934648014b6c0003b6f58e066012c9f9bb7c2de9eff9c2.jsonl'
    $global:ResumeTestJournal=Join-Path $journalDir $name
    $row=@{state='review_required';reason='preparation_uncertain';result=$null;binding=@{invoice_id=80;user_id=3;source_sha256='94b7bb7277990d8ac8e325a8755078d1cee54bddedf4e2769fc9f29cb6930e89'}}
    [IO.File]::WriteAllText($global:ResumeTestJournal, ($row|ConvertTo-Json -Compress)+[Environment]::NewLine)
    $hash=(Get-FileHash -LiteralPath $global:ResumeTestJournal).Hash.ToLowerInvariant()
    $source=[IO.File]::ReadAllText((Join-Path $repo 'tools/resume-invoice-80-cii.ps1'))
    $source=$source.Replace('8ffdf53ec0c31fe8e1daa48f3ae2202b10e8da5c65d399aec22a2e753d42fb13',$hash)
    $runner=Join-Path $root 'tools/resume-invoice-80-cii.ps1'
    [IO.File]::WriteAllText($runner,$source)
    return @{Runner=$runner;Hash=$hash;Archive=(Join-Path $journalDir ('supervised-history/'+$hash+'.jsonl'));Decision=(Join-Path $journalDir 'supervised-history/invoice-80-http400-filter-supervised-resumption.json')}
}
$fixture=New-TestWorkspace
$output=& $fixture.Runner -Execute
if ($global:ResumeTestCalls.Count -ne 6) {throw ('Expected six mocked PHP invocations, got '+$global:ResumeTestCalls.Count+'; '+($output|Out-String))}
if ((Get-FileHash -LiteralPath $fixture.Archive).Hash.ToLowerInvariant() -ne $fixture.Hash) {throw 'History was not preserved'}
if ([IO.File]::ReadAllText($global:ResumeTestJournal) -ne 'synthetic-new-attempt-kept') {throw 'New attempt was lost'}
$decision=Get-Content -LiteralPath $fixture.Decision -Raw|ConvertFrom-Json
if ($decision.previous_state -ne 'review_required' -or $decision.reason -notmatch 'Explicit supervised retry') {throw 'Decision missing'}
$null=& $fixture.Runner -Execute
if ($global:ResumeTestCalls.Count -ne 6) {throw 'Automatic repetition allowed'}
$global:ResumeTestCalls=@()
$global:ResumeTestFailPreflight=$true
$fixture=New-TestWorkspace
$null=& $fixture.Runner -Execute
if ((Test-Path -LiteralPath $fixture.Archive) -or (Test-Path -LiteralPath $fixture.Decision)) {throw 'Failed preflight changed history'}
if ((Get-FileHash -LiteralPath $global:ResumeTestJournal).Hash.ToLowerInvariant() -ne $fixture.Hash) {throw 'Failed preflight changed journal'}
'PASS: mocked sequence; original history retained; resumption reason recorded; new failed attempt retained; no repeat; failed preflight leaves registry untouched. No real PHP or API invocation.'
