$ErrorActionPreference='Stop'
$global:Emit81Calls=@();$global:Emit81Mode='ok'
function global:php {
 $a=@($args);$global:Emit81Calls+=($a -join ' ');$global:LASTEXITCODE=0
 if($a[0] -eq 'tools/revalidate-invoice-81-cii.php' -and $a -contains '--diagnose'){
  if($global:Emit81Mode -eq 'previous'){$global:LASTEXITCODE=1;'{"status":"blocked"}';return}
  '{"status":"archived_cii_checked","invoice_id":81,"source_sha256":"43b543f9a1a05a3153bafacade9a677dca9bc39f706470d9ffe7cab8ab2954c2","cii_sha256":"65449421a5536621c584d425041695af2ef00136d8d07dfa34f0cb4ab6d1e8ab","previous_emission":false}';return
 }
 if($a[0] -eq 'artisan' -and $a -contains '--diagnose'){
  $global:LASTEXITCODE=1
  @{invoice_id=81;fingerprints=@{source_sha256='43b543f9a1a05a3153bafacade9a677dca9bc39f706470d9ffe7cab8ab2954c2'};oauth_prerequisites=@{'3'=@{connection_id=1;expired_or_expiring=$true};'4'=@{connection_id=2;expired_or_expiring=$true}}}|ConvertTo-Json -Depth 5 -Compress;return
 }
 if($a[0] -eq 'artisan' -and $a[1] -eq 'superpdp:sandbox-refresh-oauth'){'{"status":"refreshed"}';return}
 if($a[0] -eq 'artisan'){@{status='passed';audit_reference=('invoice-party-checks/v1/3/81/'+('a'*64)+'.json')}|ConvertTo-Json -Compress;return}
 if($a[0] -eq 'tools/revalidate-invoice-81-cii.php'){
  if($global:Emit81Mode -eq 'validation-failed'){$global:LASTEXITCODE=1;'{"status":"blocked","diagnostic_reference":"synthetic-private-diagnostic"}';return}
  @{status='revalidated';artifact=@{validation_status='passed';cii_sha256='65449421a5536621c584d425041695af2ef00136d8d07dfa34f0cb4ab6d1e8ab';reference=('invoice-cii/v1/validated/3/81/'+('b'*64))}}|ConvertTo-Json -Depth 4 -Compress;return
 }
 if($a[0] -eq 'tools/send-invoice-81.php'){
  if($global:Emit81Mode -eq 'uncertain'){$global:LASTEXITCODE=1;'{"status":"unknown"}';return}
  '{"status":"accepted","provider_invoice_id":900}';return
 }
 throw 'Unexpected PHP operation'
}
$runner=Join-Path (Get-Location) 'tools/emit-invoice-81.ps1'
$output=& $runner -Execute
if($global:Emit81Calls.Count -ne 7){throw 'Unexpected sequence'}
if(@($global:Emit81Calls|Where-Object {$_ -match '^tools/send-invoice-81.php'}).Count -ne 1){throw 'Not exactly one send'}
if(@($global:Emit81Calls|Where-Object {$_ -match 'prepare-invoice|invoices/convert'}).Count -ne 0){throw 'Conversion attempted'}
if(($output|Out-String) -notmatch 'accepted'){throw 'Result missing'}
$global:Emit81Calls=@();$global:Emit81Mode='validation-failed'
$output=& $runner -Execute
if(@($global:Emit81Calls|Where-Object {$_ -match '^tools/send-invoice-81.php'}).Count -ne 0){throw 'Send after validation failure'}
if(($output|Out-String) -notmatch 'synthetic-private-diagnostic'){throw 'Failure diagnostic lost'}
$global:Emit81Calls=@();$global:Emit81Mode='previous'
$null=& $runner -Execute
if($global:Emit81Calls.Count -ne 1){throw 'Previous emission did not stop before network phases'}
$global:Emit81Calls=@();$global:Emit81Mode='uncertain'
$null=& $runner -Execute
if(@($global:Emit81Calls|Where-Object {$_ -match '^tools/send-invoice-81.php'}).Count -ne 1){throw 'Uncertain send retried'}
'PASS: complete mocked sequence; no conversion; fresh archive binding; exactly one send; validation failure blocks send and preserves diagnostic; prior emission blocks all network phases; uncertain send not retried. No actual PHP/API calls.'