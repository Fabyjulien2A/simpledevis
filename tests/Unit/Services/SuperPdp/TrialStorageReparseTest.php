<?php

use App\Services\SuperPdp\Experimental\TrialStorage;
use Symfony\Component\Process\Process;

// Native PowerShell is exercised with in-memory attribute values and mocked
// filesystem cmdlets. No directory/link creation, ACL mutation, Laravel or SQL.
function trialReparseScript(): string
{
    $storage = new class extends TrialStorage
    {
        public string $script = '';

        protected function probe(string $script): array
        {
            $this->script = $script;

            return [true, 'MISSING'];
        }
    };
    $storage->diagnose();

    return $storage->script;
}

function trialReparsePowerShell(string $script): Process
{
    $bootstrap = '& ([scriptblock]::Create([Console]::In.ReadToEnd()))';
    $process = new Process(['powershell.exe', '-NoProfile', '-NonInteractive', '-EncodedCommand',
        base64_encode(mb_convert_encoding($bootstrap, 'UTF-16LE', 'UTF-8'))]);
    $process->setInput($script);
    $process->setTimeout(15);
    $process->run();

    return $process;
}

function trialReparseFunction(string $name): string
{
    preg_match('/function '.preg_quote($name, '/').' \{.*?^\}/ms', trialReparseScript(), $match);
    expect($match)->toHaveKey(0);

    return $match[0];
}

beforeEach(function () {
    if (PHP_OS_FAMILY !== 'Windows') {
        test()->markTestSkipped('Windows attribute and PowerShell regression.');
    }
});

it('tests the reparse bit numerically without confusing hidden or directory attributes', function (int $attributes, bool $expected) {
    $script = trialReparseFunction('Test-TrialReparseAttributes')."\n".
        'Test-TrialReparseAttributes -Attributes '.$attributes.' | ConvertTo-Json -Compress';
    $process = trialReparsePowerShell($script);
    expect($process->getExitCode())->toBe(0)
        ->and(json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR))->toBe($expected);
})->with([
    'none' => [0, false], 'directory' => [16, false], 'hidden AppData' => [18, false],
    'readonly directory' => [17, false], 'system drive root' => [54, false],
    'other flag alone' => [524288, false], 'symlink' => [1024, true],
    'junction' => [1040, true], 'hidden reparse' => [1042, true],
    'cloud reparse' => [525312, true],
]);

it('inspects the existing hidden AppData with Force without changing it', function () {
    $script = trialReparseFunction('Get-TrialStorageItem')."\n".
        trialReparseFunction('Test-TrialReparseAttributes')."\n".<<<'PS'
$item=Get-TrialStorageItem -LiteralPath (Join-Path $env:USERPROFILE 'AppData')
if ($null -eq $item) { throw 'Existing AppData was not inspected' }
@{
 found=$true
 hidden=(([long]$item.Attributes -band [long][IO.FileAttributes]::Hidden) -ne 0)
 mask_matches=((Test-TrialReparseAttributes -Attributes ([long]$item.Attributes)) -eq (([long]$item.Attributes -band 1024) -ne 0))
} | ConvertTo-Json -Compress
PS;
    $process = trialReparsePowerShell($script);
    expect($process->getExitCode())->toBe(0);
    $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['found'])->toBeTrue()->and($report['hidden'])->toBeTrue()->and($report['mask_matches'])->toBeTrue();
});

it('keeps reparse and unreadable ancestor checks closed using the actual storage script', function (string $mode, string $expected, int $exitCode) {
    // Only a hardcoded test mode is interpolated. All Get-Item/Test-Path calls
    // are synthetic, including a dangling link for which Test-Path is false.
    $mocks = '$fixtureMode="'.$mode.'"'."\n".<<<'PS'
function Get-Item {
 param([string]$LiteralPath,[switch]$Force,$ErrorAction)
 if (-not $Force) { throw [IO.IOException]::new('Hidden item requires Force') }
 if ($LiteralPath.EndsWith('sandbox-emission-bq3-tricatel-first')) {
  if ($fixtureMode -eq 'dangling') { return [pscustomobject]@{Attributes=[IO.FileAttributes]1040} }
  if ($fixtureMode -eq 'childlink') { return [pscustomobject]@{Attributes=[IO.FileAttributes]16;FullName=$LiteralPath} }
  throw [System.Management.Automation.ItemNotFoundException]::new('Missing fixture leaf')
 }
 if ($LiteralPath.EndsWith('\AppData')) {
  if ($fixtureMode -eq 'unreadable') { throw [IO.IOException]::new('OFFLINE_SENSITIVE_DETAIL') }
  if ($fixtureMode -eq 'denied') { throw [UnauthorizedAccessException]::new('OFFLINE_SENSITIVE_DETAIL') }
  if ($fixtureMode -eq 'junction') { return [pscustomobject]@{Attributes=[IO.FileAttributes]1040} }
  return [pscustomobject]@{Attributes=[IO.FileAttributes]18}
 }
 return [pscustomobject]@{Attributes=[IO.FileAttributes]16}
}
function Test-Path { param($LiteralPath) return ($fixtureMode -eq 'childlink') }
function New-Item { throw 'Filesystem mutation forbidden' }
function Get-ChildItem { param($LiteralPath,[switch]$Force,[switch]$Recurse) return [pscustomobject]@{Attributes=[IO.FileAttributes]1040} }
function Get-Acl {
 param($LiteralPath)
 if ($fixtureMode -ne 'childlink') { throw 'ACL access not expected for missing fixture storage' }
 $acl=[Security.AccessControl.DirectorySecurity]::new()
 $acl.SetAccessRuleProtection($true,$false)
 $acl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new([Security.Principal.WindowsIdentity]::GetCurrent().User,'FullControl','ContainerInherit,ObjectInherit','None','Allow'))
 return $acl
}
PS;
    $captured = trialReparseScript();
    expect($captured)->toStartWith('$readOnly=$true');
    $process = trialReparsePowerShell($mocks."\n".$captured);
    expect(trim($process->getOutput()))->toBe($expected)
        ->and($process->getExitCode())->toBe($exitCode)
        ->and($process->getOutput().$process->getErrorOutput())->not->toContain('OFFLINE_SENSITIVE_DETAIL');
})->with([
    'hidden ancestor is readable' => ['hidden', 'MISSING', 0],
    'junction ancestor stays refused' => ['junction', 'ERROR|storage.reparse|storage_reparse|RuntimeException', 1],
    'dangling link stays refused' => ['dangling', 'ERROR|storage.reparse|storage_reparse|RuntimeException', 1],
    'descendant link stays refused' => ['childlink', 'ERROR|storage.reparse|storage_reparse|RuntimeException', 1],
    'IO failure is not a reparse proof' => ['unreadable', 'ERROR|storage.reparse|storage_inspection|PowerShellError', 1],
    'access failure stays refused' => ['denied', 'ERROR|storage.reparse|storage_access|UnauthorizedAccessException', 1],
]);

it('runs the storage probe over stdin without command line length limits or temporary files', function () {
    $storage = new class extends TrialStorage
    {
        public function runFixture(string $script): array
        {
            return $this->probe($script);
        }
    };
    $script = str_repeat("# Offline inert fixture padding\n", 500)."\n".'[Console]::Out.Write("OK")';
    expect(strlen(base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'))))->toBeGreaterThan(8191);
    [$successful, $output] = $storage->runFixture($script);
    expect($successful)->toBeTrue()->and($output)->toBe('OK');
});

it('automatically creates missing storage and protects its ACL before returning success using only in-memory filesystem mocks', function () {
    $storage = new class extends TrialStorage
    {
        private string $script = '';

        public function creationScript(): string
        {
            $this->verify(false);

            return $this->script;
        }

        protected function probe(string $script): array
        {
            $this->script = $script;

            return [true, 'OK'];
        }
    };
    $script = $storage->creationScript();
    expect($script)->toStartWith('$readOnly=$false');

    // Intercept the two static .NET persistence calls in memory. Assert the
    // replacements before running anything: native filesystem writes are forbidden.
    $script = str_replace('[IO.Directory]::GetAccessControl($p,[Security.AccessControl.AccessControlSections]::Access)',
        '(Get-FixtureDirectoryAcl)', $script, $reads);
    $script = str_replace('[IO.Directory]::SetAccessControl($p,$acl)',
        'Set-FixtureDirectoryAcl -Acl $acl', $script, $writes);
    expect($reads)->toBe(1)->and($writes)->toBe(1);
    $mocks = <<<'PS'
$script:fixtureCreated=$false
$script:fixtureCreates=0
$script:fixtureAclWrites=0
$script:fixtureAcl=[Security.AccessControl.DirectorySecurity]::new()
# Deliberately unsafe initial inherited-style permissions must be removed.
$fixtureAcl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new([Security.Principal.SecurityIdentifier]::new('S-1-1-0'),'Modify','ContainerInherit,ObjectInherit','None','Allow'))
function Test-Path { param($LiteralPath) return $script:fixtureCreated }
function Get-Item {
 param([string]$LiteralPath,[switch]$Force,$ErrorAction)
 if ($LiteralPath.EndsWith('sandbox-emission-bq3-tricatel-first') -and -not $script:fixtureCreated) {
  throw [System.Management.Automation.ItemNotFoundException]::new('Missing fixture directory')
 }
 return [pscustomobject]@{Attributes=[IO.FileAttributes]16;FullName=$LiteralPath}
}
function New-Item {
 param($ItemType,$Path,[switch]$Force)
 if ($ItemType -ne 'Directory' -or -not $Force -or -not $Path.EndsWith('SimpleDevis\sandbox-emission-bq3-tricatel-first')) { throw 'Unexpected creation' }
 $script:fixtureCreates++
 $script:fixtureCreated=$true
}
function Get-FixtureDirectoryAcl { return $script:fixtureAcl }
function Set-FixtureDirectoryAcl { param($Acl) $script:fixtureAclWrites++; $script:fixtureAcl=$Acl }
function Get-Acl { param($LiteralPath) return $script:fixtureAcl }
function Get-ChildItem { param($LiteralPath,[switch]$Force,[switch]$Recurse) return @() }
PS;
    $assertions = <<<'PS'
$expected=@([Security.Principal.WindowsIdentity]::GetCurrent().User.Value,'S-1-5-18','S-1-5-32-544') | Sort-Object -Unique
$actual=@($fixtureAcl.Access | ForEach-Object { $_.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value }) | Sort-Object -Unique
$rulesCorrect=$true
foreach ($rule in $fixtureAcl.Access) {
 if ($rule.AccessControlType -ne 'Allow' -or $rule.FileSystemRights -ne 'FullControl' -or $rule.IsInherited -or
     $rule.InheritanceFlags -ne 'ContainerInherit,ObjectInherit' -or $rule.PropagationFlags -ne 'None') { $rulesCorrect=$false }
}
[Console]::Out.Write('|')
[Console]::Out.Write((@{
 creates=$fixtureCreates
 acl_writes=$fixtureAclWrites
 protected=$fixtureAcl.AreAccessRulesProtected
 exact_principals=(@(Compare-Object $expected $actual).Count -eq 0)
 rules_correct=$rulesCorrect
} | ConvertTo-Json -Compress))
PS;
    $process = trialReparsePowerShell($mocks."\n".$script."\n".$assertions);
    expect($process->getOutput())->toStartWith('OK|')->and($process->getExitCode())->toBe(0);
    $report = json_decode(substr($process->getOutput(), 3), true, flags: JSON_THROW_ON_ERROR);
    expect($report['creates'])->toBe(1)->and($report['acl_writes'])->toBe(1)
        ->and($report['protected'])->toBeTrue()->and($report['exact_principals'])->toBeTrue()
        ->and($report['rules_correct'])->toBeTrue();
});
