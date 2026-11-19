$ErrorActionPreference='Stop'
$global:Invoice81Calls=@()
$global:Invoice81Mode='ok'
function global:php {
 $a=@($args);$global:Invoice81Calls+=($a -join ' ');$global:LASTEXITCODE=0
 if($a[0] -eq 'artisan' -and $a[1] -eq 'superpdp:sandbox-refresh-oauth'){'{"status":"refreshed"}';return}
 if($a[0] -eq 'artisan' -and $a -contains '--diagnose'){
  $global:LASTEXITCODE=1
  $source='43b543f9a1a05a3153bafacade9a677dca9bc39f706470d9ffe7cab8ab2954c2'
  if($global:Invoice81Mode -eq 'wrongSource'){$source='incorrect'}
  @{invoice_id=81;invoice_reference='SBX-01M7G5J2H7KJQG4DWKE0QK5CTD';fingerprints=@{source_sha256=$source};oauth_prerequisites=@{'3'=@{connection_id=1;expired_or_expiring=$true};'4'=@{connection_id=2;expired_or_expiring=$true}}}|ConvertTo-Json -Depth 5 -Compress;return
 }
 if($a[0] -eq 'artisan'){
  $id=81;if($global:Invoice81Mode -eq 'wrongEvidence'){$id=80}
  @{status='passed';audit_reference=('invoice-party-checks/v1/3/'+$id+'/'+('a'*64)+'.json')}|ConvertTo-Json -Compress;return
 }
 if($a -contains '--diagnose'){'{"status":"local_prerequisites_passed"}';return}
 $global:LASTEXITCODE=1
 '{"status":"blocked","failure_stage":"conversion.response","diagnostic_reference":"invoice-party-checks/v1/3/81/synthetic.json"}'
}
$runner=Join-Path (Get-Location) 'tools/prepare-invoice-81-cii.ps1'
$result=& $runner -Execute
if($global:Invoice81Calls.Count -ne 6){throw 'Unexpected orchestration sequence'}
foreach($call in $global:Invoice81Calls){if($call -notmatch 'sandbox-refresh-oauth' -and $call -notmatch '--invoice=81'){throw 'Wrong invoice target'}}
if(($result|Out-String) -notmatch 'invoice-party-checks/v1/3/81/'){throw 'Diagnostic reference not relayed'}
$global:Invoice81Calls=@();$global:Invoice81Mode='wrongEvidence'
$null=& $runner -Execute
if($global:Invoice81Calls.Count -ne 4){throw 'Evidence of invoice 80 accepted'}
$global:Invoice81Calls=@();$global:Invoice81Mode='wrongSource'
$null=& $runner -Execute
if($global:Invoice81Calls.Count -ne 1){throw 'Changed source accepted'}
$tokens=$null;$errors=$null
$ast=[System.Management.Automation.Language.Parser]::ParseFile($runner,[ref]$tokens,[ref]$errors)
if($errors.Count -ne 0){throw 'Syntax invalid'}
$mutations=$ast.FindAll({param($node) $node -is [System.Management.Automation.Language.CommandAst] -and $node.GetCommandName() -in @('Move-Item','Remove-Item','Set-Content','New-Item')},$true)
if($mutations.Count -ne 0){throw 'Unexpected registry/file manipulation'}
'PASS: invoice 81 only; renewal/evidence/producer sequence; invoice 80 evidence refused; changed source refused; diagnostic reference relayed; no journal operations. All PHP calls simulated.'