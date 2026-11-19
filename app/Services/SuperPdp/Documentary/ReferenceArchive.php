<?php

namespace App\Services\SuperPdp\Documentary;

use App\Services\SuperPdp\Experimental\TrialLedger;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Independent immutable artifacts. No invoice, emission package or SQL dependency. */
class ReferenceArchive
{
    private string $root;

    public function __construct(string $directory)
    {
        $real = realpath($directory);
        if ($real === false || ! is_dir($real) || ! is_writable($real) || is_link($directory)
            || ! preg_match('~\A(?:[A-Za-z]:[\\\\/]|/)~', $directory)
            || preg_match('~(?:onedrive|[\\/]public(?:[\\/]|$)|^[a-z]+://)~i', $directory)
            || preg_match('~(?:onedrive|[\\/]public(?:[\\/]|$))~i', $real)) {
            throw new RuntimeException('Répertoire documentaire privé local existant requis.');
        }
        // Inspect every ancestor, including junctions on Windows.
        for ($path = $directory; ; $path = dirname($path)) {
            if (is_link($path) || (PHP_OS_FAMILY !== 'Windows' && realpath($path) === false)) {
                throw new RuntimeException('Lien ou chemin privé invalide.');
            }
            if (dirname($path) === $path || preg_match('~\A[A-Za-z]:[\\\\/]\z~', $path)) {
                break;
            }
        }
        foreach ([public_path(), base_path(), config('filesystems.disks.public.root')] as $exposed) {
            $location = is_string($exposed) ? realpath($exposed) : false;
            if ($location !== false && (strtolower($real) === strtolower($location)
                || str_starts_with(strtolower($real), strtolower($location).DIRECTORY_SEPARATOR))) {
                throw new RuntimeException('Stockage hors dépôt et hors racine publique requis.');
            }
        }
        $this->root = $real;
        $this->assertPermissions($directory);
    }

    protected function assertPermissions(string $real): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // Fixed script; path passed as data, never interpolated into executable code.
            $script = <<<'PS'
$ErrorActionPreference='Stop'
$p=$env:SIMPLEDEVIS_DOCUMENTARY_ROOT
$sid=[Security.Principal.WindowsIdentity]::GetCurrent().User.Value
if ([IO.DriveInfo]::new([IO.Path]::GetPathRoot($p)).DriveType -ne 'Fixed') { exit 1 }
$ancestor=[IO.DirectoryInfo]::new($p)
while ($null -ne $ancestor) {
 if (((Get-Item -LiteralPath $ancestor.FullName -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { exit 1 }
 $ancestor=$ancestor.Parent
}
foreach ($item in @((Get-Item -LiteralPath $p -Force)) + @(Get-ChildItem -LiteralPath $p -Recurse -Force)) {
 if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { exit 1 }
 $acl=Get-Acl -LiteralPath $item.FullName
 if (-not $acl.AreAccessRulesProtected -and $item.FullName -eq $p) { exit 1 }
 if ($acl.Access.Count -eq 0) { exit 1 }
 foreach ($rule in $acl.Access) {
  if ($rule.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value -notin @($sid,'S-1-5-18','S-1-5-32-544') -or $rule.AccessControlType -ne 'Allow') { exit 1 }
 }
}
PS;
            $process = new Process(['powershell.exe', '-NoProfile', '-NonInteractive', '-EncodedCommand',
                base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'))], env: ['SIMPLEDEVIS_DOCUMENTARY_ROOT' => $real]);
            $process->setTimeout(20);
            $process->run();
            if (! $process->isSuccessful()) {
                throw new RuntimeException('ACL ou chemin privé Windows refusé.');
            }
        } elseif ((fileperms($real) & 0077) !== 0) {
            throw new RuntimeException('Permissions privées 0700 requises.');
        }
    }

    public function locked(string $run, callable $operation): mixed
    {
        if (! preg_match('/\A[a-z0-9][a-z0-9-]{0,63}\z/D', $run)) {
            throw new RuntimeException('Référence invalide.');
        }
        $directory = $this->root.'/'.$run;
        if (! file_exists($directory) && ! mkdir($directory, 0700)) {
            throw new RuntimeException('Stockage indisponible.');
        }
        if (is_link($directory) || ! is_dir($directory)) {
            throw new RuntimeException('Archive invalide.');
        }
        $lock = $directory.'/operation.lock';
        if (is_link($lock)) {
            throw new RuntimeException('Verrou invalide.');
        }
        $handle = fopen($lock, 'c+b');
        if ($handle === false) {
            throw new RuntimeException('Verrou indisponible.');
        }
        try {
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Test documentaire déjà en cours.');
            }

            return $operation($directory);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function write(string $directory, string $name, string $bytes): void
    {
        $path = $directory.'/'.$name;
        $handle = @fopen($path, 'x+b');
        if ($handle === false) {
            throw new RuntimeException('Archive existante : aucun écrasement ni reprise automatique.');
        }
        try {
            TrialLedger::writeDurably($handle, $bytes);
        } finally {
            fclose($handle);
        }
        if (! hash_equals(hash('sha256', $bytes), (string) hash_file('sha256', $path))) {
            throw new RuntimeException('Archive altérée.');
        }
    }

    public function read(string $directory, string $name): string
    {
        $path = $directory.'/'.$name;
        if (is_link($path) || ! is_file($path) || filesize($path) > 8 * 1024 * 1024) {
            throw new RuntimeException('Archive absente ou invalide.');
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException('Archive illisible.');
        }

        return $bytes;
    }
}
