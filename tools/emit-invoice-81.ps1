param([switch]$Execute)

$ErrorActionPreference = 'Stop'
if (-not $Execute) {
    Write-Output 'Prepared only. Invoice 81: up to 2 OAuth renewals, 4 evidence GETs, 1 archived-CII validation POST, then at most 1 invoice POST. No conversion or automatic retry.'
    exit 0
}
$workspace = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$sourceHash = '43b543f9a1a05a3153bafacade9a677dca9bc39f706470d9ffe7cab8ab2954c2'
$ciiHash = '65449421a5536621c584d425041695af2ef00136d8d07dfa34f0cb4ab6d1e8ab'
$stage = 'original_archive_and_no_previous_emission'
$failureData = $null
function Invoke-JsonPhp([string[]]$Arguments, [switch]$AllowFailure) {
    $output = & php @Arguments | Out-String
    $code = $LASTEXITCODE
    $data = $output | ConvertFrom-Json
    if ($code -ne 0 -and -not $AllowFailure) {
        $script:failureData = $data
        throw 'Operation refused; no subsequent operation allowed'
    }
    return [pscustomobject]@{ Data = $data; Code = $code }
}
Push-Location -LiteralPath $workspace
try {
    $local = (Invoke-JsonPhp -Arguments @('tools/revalidate-invoice-81-cii.php', '--env=sandbox', '--diagnose')).Data
    if ($local.status -ne 'archived_cii_checked' -or $local.invoice_id -ne 81 -or
        $local.source_sha256 -ne $sourceHash -or $local.cii_sha256 -ne $ciiHash -or $local.previous_emission) { throw 'Original archive scope refused' }
    $stage = 'local_oauth_prerequisites'
    $plan = (Invoke-JsonPhp -Arguments @('artisan', 'superpdp:collect-invoice-evidence', '--env=sandbox', '--invoice=81', '--diagnose') -AllowFailure).Data
    if ($plan.invoice_id -ne 81 -or $plan.fingerprints.source_sha256 -ne $sourceHash) { throw 'Frozen source mismatch' }
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
    if ($collection.status -ne 'passed' -or $collection.audit_reference -notmatch '^invoice-party-checks/v1/3/81/[a-f0-9]{64}\.json$') { throw 'Fresh invoice 81 evidence required' }
    $stage = 'revalidate_exact_archived_cii'
    $renewal = (Invoke-JsonPhp -Arguments @('tools/revalidate-invoice-81-cii.php', '--env=sandbox', '--execute', "--evidence=$($collection.audit_reference)")).Data
    if ($renewal.status -ne 'revalidated' -or $renewal.artifact.validation_status -ne 'passed' -or
        $renewal.artifact.cii_sha256 -ne $ciiHash -or $renewal.artifact.reference -notmatch '^invoice-cii/v1/validated/3/81/[a-f0-9]{64}$') { throw 'Fresh archived-byte validation required' }
    $stage = 'single_uuid_emission'
    $attempt = Invoke-JsonPhp -Arguments @('tools/send-invoice-81.php', '--env=sandbox', '--execute', "--archive=$($renewal.artifact.reference)") -AllowFailure
    $attempt.Data | ConvertTo-Json -Depth 10
    exit $attempt.Code
} catch {
    if ($null -ne $failureData) { $failureData | ConvertTo-Json -Depth 10 }
    Write-Output "Invoice 81 procedure stopped at $stage. No retry, journal reset, reconversion or additional invoice POST. Inspect any diagnostic_reference and the existing emission registry."
    exit 1
} finally { Pop-Location }
