param([switch]$Execute)

# Existing evidence collector and CII producer only. No journal move, reset or resumption.
$ErrorActionPreference = 'Stop'
if (-not $Execute) {
    Write-Output 'Prepared only: invoice 81. -Execute authorizes up to 2 OAuth renewals, 4 evidence GETs, then at most 2 conversion/validation POSTs. No invoice sending.'
    exit 0
}
$workspace = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$sourceHash = '43b543f9a1a05a3153bafacade9a677dca9bc39f706470d9ffe7cab8ab2954c2'
$stage = 'local_prerequisites'
function Invoke-JsonPhp([string[]]$Arguments, [switch]$AllowFailure) {
    $output = & php @Arguments | Out-String
    $code = $LASTEXITCODE
    $data = $output | ConvertFrom-Json
    if (-not $AllowFailure -and $code -ne 0) { throw 'Operation refused' }
    return [pscustomobject]@{ Data = $data; Code = $code }
}
Push-Location -LiteralPath $workspace
try {
    $plan = (Invoke-JsonPhp -Arguments @('artisan', 'superpdp:collect-invoice-evidence', '--env=sandbox', '--invoice=81', '--diagnose') -AllowFailure).Data
    if ($plan.invoice_id -ne 81 -or $plan.invoice_reference -ne 'SBX-01M7G5J2H7KJQG4DWKE0QK5CTD' -or
        $plan.fingerprints.source_sha256 -ne $sourceHash) { throw 'Frozen invoice mismatch' }
    $stage = 'explicit_oauth_renewals'
    foreach ($target in @(@{ Key = '3'; Connection = 1 }, @{ Key = '4'; Connection = 2 })) {
        $prerequisite = $plan.oauth_prerequisites.($target.Key)
        if ($null -eq $prerequisite -or $prerequisite.connection_id -ne $target.Connection) { throw 'OAuth scope mismatch' }
        if ($prerequisite.expired_or_expiring) {
            $null = Invoke-JsonPhp -Arguments @('artisan', 'superpdp:sandbox-refresh-oauth', '--env=sandbox', "--connection=$($target.Connection)", '--execute')
        }
    }
    $stage = 'fresh_invoice_81_evidence'
    $collection = (Invoke-JsonPhp -Arguments @('artisan', 'superpdp:collect-invoice-evidence', '--env=sandbox', '--invoice=81', '--execute', "--source-sha256=$sourceHash")).Data
    if ($collection.status -ne 'passed' -or $collection.audit_reference -notmatch '^invoice-party-checks/v1/3/81/[a-f0-9]{64}\.json$') { throw 'Invoice 81 evidence required' }
    $evidenceArgument = "--evidence=$($collection.audit_reference)"
    $stage = 'cii_preflight'
    $preflight = (Invoke-JsonPhp -Arguments @('tools/prepare-invoice-80-cii.php', '--env=sandbox', '--invoice=81', '--diagnose', $evidenceArgument)).Data
    if ($preflight.status -ne 'local_prerequisites_passed') { throw 'CII prerequisites refused' }
    $stage = 'existing_invoice_81_cii_producer'
    $attempt = Invoke-JsonPhp -Arguments @('tools/prepare-invoice-80-cii.php', '--env=sandbox', '--invoice=81', '--execute', $evidenceArgument) -AllowFailure
    $attempt.Data | ConvertTo-Json -Depth 10
    exit $attempt.Code
} catch {
    Write-Output "Invoice 81 preparation stopped at $stage. No automatic retry, journal reset or invoice sending."
    exit 1
} finally { Pop-Location }
