# Run hidden beside the isolated PHP server. Enforce expiry even with no HTTP traffic.
param([Parameter(Mandatory = $true)][string]$TraceDirectory)
$ErrorActionPreference = 'Stop'
$traceFile = Join-Path (Get-Item -LiteralPath $TraceDirectory).FullName 'trace-state.json'
while ($true) {
    try {
        if (Test-Path -LiteralPath $traceFile -PathType Leaf) {
            $item = Get-Item -LiteralPath $traceFile
            if ($item.LastWriteTimeUtc -lt [DateTime]::UtcNow.AddMinutes(-30)) {
                # A locked file is skipped; the next pass retries. Never recurse.
                Remove-Item -LiteralPath $traceFile -Force -ErrorAction Stop
            }
        }
    } catch {
        # Never log file contents, exceptions or request data.
    }
    Start-Sleep -Seconds 30
}
