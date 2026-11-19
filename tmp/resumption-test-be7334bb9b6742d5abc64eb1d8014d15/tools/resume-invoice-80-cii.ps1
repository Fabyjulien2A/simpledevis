param([switch]$Execute)

# One-off supervised runbook, bound to the reviewed first attempt of invoice 80.
# No change to the application registry or its automatic-retry policy.
$ErrorActionPreference = 'Stop'
if (-not $Execute) {
    Write-Output 'Prepared only. -Execute authorizes up to 2 OAuth renewals, 4 evidence GETs, preservation of the reviewed journal, then at most 2 CII preparation POSTs. No invoice sending.'
    exit 0
}

$workspace = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$privateRoot = Join-Path $workspace 'storage/app/invoice-cii'
$journalName = 'f1d94e99082d0d2a76934648014b6c0003b6f58e066012c9f9bb7c2de9eff9c2.jsonl'
$expectedJournalHash = 'ce565a3abc1e8e50ebed03f2190985be8a9858795bff75a13bbf152be3b198d3'
$sourceHash = '94b7bb7277990d8ac8e325a8755078d1cee54bddedf4e2769fc9f29cb6930e89'
$journal = Join-Path $privateRoot "preparation-reservations/$journalName"
$history = Join-Path $privateRoot 'preparation-reservations/supervised-history'
$archive = Join-Path $history $journalName
$decision = Join-Path $history 'invoice-80-first-supervised-resumption.json'
$stage = 'reviewed_journal'

function Assert-ReviewedJournal {
    if ((Get-Item -LiteralPath $journal).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Linked journal refused' }
    if ((Get-FileHash -LiteralPath $journal -Algorithm SHA256).Hash.ToLowerInvariant() -ne $expectedJournalHash) { throw 'Journal changed' }
    $last = (Get-Content -LiteralPath $journal | Select-Object -Last 1) | ConvertFrom-Json
    if ($last.state -ne 'review_required' -or $last.reason -ne 'preparation_uncertain' -or $null -ne $last.result -or
        $last.binding.invoice_id -ne 80 -or $last.binding.user_id -ne 3 -or
        $last.binding.source_sha256 -ne $sourceHash) { throw 'Reviewed attempt mismatch' }
}

function Invoke-JsonPhp([string[]]$Arguments, [switch]$AllowFailure) {
    $output = & php @Arguments | Out-String
    $code = $LASTEXITCODE
    $data = $output | ConvertFrom-Json
    if (-not $AllowFailure -and $code -ne 0) { throw 'Operation refused; inspect its private diagnostic' }
    return [pscustomobject]@{ Data = $data; Code = $code }
}

Push-Location -LiteralPath $workspace
try {
    Assert-ReviewedJournal
    if ((Test-Path -LiteralPath $archive) -or (Test-Path -LiteralPath $decision)) { throw 'Resumption already recorded; no automatic repeat' }
    foreach ($kind in @('validated', 'pending')) {
        if (Test-Path -LiteralPath (Join-Path $privateRoot "v1/$kind/3/80")) { throw 'Existing CII requires review' }
    }
    $stage = 'local_prerequisites'
    $plan = (Invoke-JsonPhp -Arguments @('artisan', 'superpdp:collect-invoice-evidence', '--env=sandbox', '--diagnose') -AllowFailure).Data
    if ($plan.invoice_id -ne 80 -or $plan.fingerprints.source_sha256 -ne $sourceHash) { throw 'Frozen source mismatch' }
    $stage = 'explicit_oauth_renewals'
    foreach ($target in @(@{ Key = '3'; Connection = 1 }, @{ Key = '4'; Connection = 2 })) {
        $prerequisite = $plan.oauth_prerequisites.($target.Key)
        if ($null -eq $prerequisite -or $prerequisite.connection_id -ne $target.Connection) { throw 'OAuth scope mismatch' }
        if ($prerequisite.expired_or_expiring) {
            $null = Invoke-JsonPhp -Arguments @('artisan', 'superpdp:sandbox-refresh-oauth', '--env=sandbox', "--connection=$($target.Connection)", '--execute')
        }
    }
    $stage = 'fresh_evidence'
    $collection = (Invoke-JsonPhp -Arguments @('artisan', 'superpdp:collect-invoice-evidence', '--env=sandbox', '--execute', "--source-sha256=$sourceHash")).Data
    if ($collection.status -ne 'passed' -or $collection.audit_reference -notmatch '^invoice-party-checks/v1/3/80/[a-f0-9]{64}\.json$') { throw 'Fresh evidence refused' }
    $evidenceArgument = "--evidence=$($collection.audit_reference)"
    $stage = 'cii_local_preflight'
    $preflight = (Invoke-JsonPhp -Arguments @('tools/prepare-invoice-80-cii.php', '--env=sandbox', '--diagnose', $evidenceArgument)).Data
    if ($preflight.status -ne 'local_prerequisites_passed') { throw 'CII prerequisites refused' }
    $stage = 'preserve_reviewed_history'
    Assert-ReviewedJournal
    # Both absolute paths must stay inside the explicitly named private directory.
    $privatePrefix = [IO.Path]::GetFullPath($privateRoot).TrimEnd([char]92, [char]47) + [IO.Path]::DirectorySeparatorChar
    foreach ($path in @($journal, $archive, $decision)) {
        if (-not [IO.Path]::GetFullPath($path).StartsWith($privatePrefix, [StringComparison]::OrdinalIgnoreCase)) { throw 'Private path mismatch' }
    }
    if (-not (Test-Path -LiteralPath $history)) { $null = New-Item -ItemType Directory -Path $history }
    if ((Get-Item -LiteralPath $history).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Linked history refused' }
    $record = [ordered]@{ invoice_id = 80; source_sha256 = $sourceHash; previous_journal = $journalName
        previous_journal_sha256 = $expectedJournalHash; previous_state = 'review_required'
        reason = 'Explicit supervised retry after conversion_failed with missing response diagnostic; conversion-only scope, no invoice deposit'
        approved_by = 'explicit_user_invocation_of_supervised_runbook'; recorded_at = [DateTime]::UtcNow.ToString('o')
        verification_reference = $collection.audit_reference; collection_reference = $collection.collection_reference
        authorization_expires_at = $preflight.authorization_proposal.expires_at; emission_authorized = $false }
    $stream = [IO.File]::Open($decision, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
    try {
        $bytes = [Text.Encoding]::UTF8.GetBytes(($record | ConvertTo-Json -Depth 5))
        $stream.Write($bytes, 0, $bytes.Length)
        $stream.Flush($true)
    } finally { $stream.Dispose() }
    # The old journal is preserved byte-for-byte. Core registry code is unchanged.
    Move-Item -LiteralPath $journal -Destination $archive
    if ((Get-FileHash -LiteralPath $archive -Algorithm SHA256).Hash.ToLowerInvariant() -ne $expectedJournalHash) { throw 'Archived journal mismatch' }
    $stage = 'single_instrumented_preparation'
    $attempt = Invoke-JsonPhp -Arguments @('tools/prepare-invoice-80-cii.php', '--env=sandbox', '--execute', $evidenceArgument) -AllowFailure
    $attempt.Data | ConvertTo-Json -Depth 10
    exit $attempt.Code
} catch {
    # Neither raw PHP output nor exception data is copied to this operational log.
    Write-Output "Supervised resumption stopped at $stage. No automatic retry. Preserve the decision and both journals for review."
    exit 1
} finally { Pop-Location }
