<?php

declare(strict_types=1);

namespace App\Services\SuperPdp\Experimental;

use RuntimeException;
use Symfony\Component\Process\Process;

/** A single authoritative slot, outside the repository and synchronized folders. */
class TrialStorage
{
    public function root(): string
    {
        $result = $this->verify(false);

        return TrialDiagnostic::step('storage.directory', fn () => TrialLedger::directory($result['root']));
    }

    public function diagnose(): array
    {
        $result = $this->verify(true);
        if ($result['exists'] === false) {
            return ['status' => 'inconclusive', 'stage' => 'storage.path', 'error_type' => null, 'reason' => 'storage_missing',
                'explanation' => 'Le répertoire privé n’existe pas dans cette session. Sa création n’est pas testée en lecture seule.',
                'corrective_action' => 'Vérifier l’identité PHP et les droits sur LOCALAPPDATA. Le message historique seul ne permet pas d’affirmer la cause du refus.'];
        }
        $root = $result['root'];
        $artifacts = [];
        foreach (['preparation.started', 'prepared.json', 'document.cii.xml', 'validation-report.json',
            'burger-queen-3-tricatel-first-deposit.jsonl', 'requests.jsonl'] as $name) {
            $artifacts[$name] = is_file($root.'/'.$name);
        }
        if ($artifacts['preparation.started'] && ! $artifacts['prepared.json']) {
            return TrialDiagnostic::describe(new TrialFailure('preparation.marker', 'preparation_incomplete', 'ExistingMarker'))
                + ['artifacts' => $artifacts];
        }

        return ['status' => 'inconclusive', 'stage' => 'storage.read_only', 'error_type' => null, 'reason' => 'local_checks_only',
            'explanation' => 'Chemin et ACL actuels conformes ; aucun test d’écriture, SQL ou OAuth n’a été effectué.',
            'corrective_action' => 'Les causes SQL, OAuth ou documentaires historiques ne peuvent pas être établies sans leur exception initiale. Ne pas relancer prepare pour obtenir ce diagnostic.',
            'artifacts' => $artifacts];
    }

    protected function verify(bool $readOnly): array
    {
        if (PHP_OS_FAMILY !== 'Windows' || ! ($local = getenv('LOCALAPPDATA'))
            || ! preg_match('~\A[A-Za-z]:/~', str_replace(chr(92), '/', $local))
            || preg_match('~(?:onedrive|/www/|/public/)~i', str_replace(chr(92), '/', $local))) {
            throw new TrialFailure('storage.platform', 'storage_platform', 'RuntimeException');
        }
        $root = $local.'/SimpleDevis/sandbox-emission-bq3-tricatel-first';
        // No user-supplied path or command interpolation. Existing ACLs are verified;
        // only a newly created final directory receives a new protected DACL.
        $script = <<<'PS'
$ErrorActionPreference='Stop'
function Get-TrialStorageItem {
 param([string]$LiteralPath)
 try { return Get-Item -LiteralPath $LiteralPath -Force -ErrorAction Stop }
 catch [System.Management.Automation.ItemNotFoundException] { return $null }
}
function Test-TrialReparseAttributes {
 param([long]$Attributes)
 return (($Attributes -band [long][IO.FileAttributes]::ReparsePoint) -ne 0)
}
$stage='storage.path'
$reason='storage_platform'
try {
$p=Join-Path $env:LOCALAPPDATA 'SimpleDevis\sandbox-emission-bq3-tricatel-first'
if ([IO.DriveInfo]::new([IO.Path]::GetPathRoot($p)).DriveType -ne [IO.DriveType]::Fixed) { throw 'nonlocal' }
$stage='storage.reparse'
$reason='storage_inspection'
$parent=[IO.DirectoryInfo]::new($p)
while ($null -ne $parent) {
 # Get-Item -Force inspects hidden ancestors and dangling links themselves.
 # Only genuinely missing items are skipped; access/provider errors fail closed.
 $item=Get-TrialStorageItem -LiteralPath $parent.FullName
 if ($null -ne $item -and (Test-TrialReparseAttributes -Attributes ([long]$item.Attributes))) {
  $reason='storage_reparse'
  throw 'reparse'
 }
 $parent=$parent.Parent
}
$new=-not (Test-Path -LiteralPath $p)
if ($new -and $readOnly) { [Console]::Out.Write('MISSING'); exit 0 }
$stage='storage.create'
$reason='storage_access'
if ($new) { New-Item -ItemType Directory -Path $p -Force | Out-Null }
$sid=[Security.Principal.WindowsIdentity]::GetCurrent().User
$stage='storage.acl'
$reason='storage_acl'
if ($new) {
 $acl=[IO.Directory]::GetAccessControl($p,[Security.AccessControl.AccessControlSections]::Access)
 $acl.SetAccessRuleProtection($true,$false)
 foreach ($rule in @($acl.Access)) { $acl.PurgeAccessRules($rule.IdentityReference) }
 foreach ($s in @($sid,[Security.Principal.SecurityIdentifier]::new('S-1-5-18'),[Security.Principal.SecurityIdentifier]::new('S-1-5-32-544'))) {
  $acl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new($s,'FullControl','ContainerInherit,ObjectInherit','None','Allow'))
 }
 [IO.Directory]::SetAccessControl($p,$acl)
}
foreach ($item in @((Get-Item -LiteralPath $p -Force)) + @(Get-ChildItem -LiteralPath $p -Force -Recurse)) {
 if (Test-TrialReparseAttributes -Attributes ([long]$item.Attributes)) {
  $stage='storage.reparse'
  $reason='storage_reparse'
  throw 'reparse'
 }
 $acl=Get-Acl -LiteralPath $item.FullName
 if ($item.FullName -eq $p -and -not $acl.AreAccessRulesProtected) { throw 'acl' }
 foreach ($rule in $acl.Access) {
  $id=$rule.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value
  if ($id -notin @($sid.Value,'S-1-5-18','S-1-5-32-544') -or $rule.AccessControlType -ne 'Allow') { throw 'acl' }
 }
 if ($acl.Access.Count -eq 0) { throw 'acl' }
}
[Console]::Out.Write('OK')
} catch {
 $kind=$_.Exception.GetType().Name
 if ($kind -notin @('UnauthorizedAccessException','MethodInvocationException','ItemNotFoundException','CommandNotFoundException','RuntimeException')) { $kind='PowerShellError' }
 if ($_.CategoryInfo.Category -eq 'PermissionDenied' -or $_.Exception -is [UnauthorizedAccessException]) { $reason='storage_access' }
 [Console]::Out.Write('ERROR|'+$stage+'|'+$reason+'|'+$kind)
 exit 1
}
PS;
        $script = '$readOnly='.($readOnly ? '$true' : '$false')."\n".$script;
        try {
            [$successful, $output] = $this->probe($script);
        } catch (\Throwable) {
            throw new TrialFailure('storage.powershell', 'storage_process', 'ProcessException');
        }
        $output = trim($output);
        if (! $successful || ! in_array($output, ['OK', 'MISSING'], true)) {
            // Strict protocol only; raw stdout/stderr and exception messages never escape.
            if (preg_match('/\AERROR\|(storage\.(?:path|reparse|create|acl))\|(storage_(?:platform|reparse|inspection|access|acl))\|(UnauthorizedAccessException|MethodInvocationException|ItemNotFoundException|CommandNotFoundException|RuntimeException|PowerShellError)\z/D', $output, $match)) {
                throw new TrialFailure($match[1], $match[2], $match[3]);
            }
            throw new TrialFailure('storage.powershell', 'storage_process', 'ProcessException');
        }

        return ['root' => $root, 'exists' => $output === 'OK'];
    }

    protected function probe(string $script): array
    {
        // Keep the Windows command line short; the fixed script travels over stdin,
        // without a temporary file or shell interpolation of its contents.
        $bootstrap = '& ([scriptblock]::Create([Console]::In.ReadToEnd()))';
        $process = new Process(['powershell.exe', '-NoProfile', '-NonInteractive', '-EncodedCommand',
            base64_encode(mb_convert_encoding($bootstrap, 'UTF-16LE', 'UTF-8'))]);
        $process->setInput($script);
        $process->setTimeout(20);
        $process->run();

        return [$process->isSuccessful(), $process->getOutput()];
    }

    public function exclusively(callable $operation): mixed
    {
        $root = $this->root();
        $lock = TrialDiagnostic::step('storage.lock_open', fn () => fopen($root.'/operation.lock', 'c+b'));
        if ($lock === false) {
            throw new TrialFailure('storage.lock_open', 'storage_access', 'RuntimeException');
        }
        try {
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new TrialFailure('storage.lock', 'operation_busy', 'RuntimeException');
            }

            return $operation($root);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function create(string $root, string $name, string $bytes): void
    {
        if (! preg_match('/\A[a-zA-Z0-9.-]+\z/D', $name)) {
            throw new RuntimeException('Invalid private artifact name.');
        }
        $handle = @fopen($root.'/'.$name, 'x+b');
        if ($handle === false) {
            if (file_exists($root.'/'.$name) || is_link($root.'/'.$name)) {
                throw new RuntimeException('Artifact already exists; no overwrite or automatic retry.');
            }
            throw new RuntimeException('Artifact creation failed.');
        }
        try {
            TrialLedger::writeDurably($handle, $bytes);
        } finally {
            fclose($handle);
        }
    }

    /** Durable local intent before EVERY SDK request, including preparation and reads. */
    public function intent(string $root, string $method, string $endpoint): void
    {
        $handle = fopen($root.'/requests.jsonl', 'ab');
        if ($handle === false) {
            throw new RuntimeException('Cannot journal network intent.');
        }
        try {
            TrialLedger::writeDurably($handle, json_encode(['method' => $method, 'endpoint' => $endpoint,
                'at' => now()->toIso8601String()], JSON_THROW_ON_ERROR)."\n");
        } finally {
            fclose($handle);
        }
    }
}
