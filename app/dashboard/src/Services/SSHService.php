<?php

namespace WpOps\Dashboard\Services;

use phpseclib3\Net\SSH2;
use phpseclib3\Net\SFTP;
use Exception;

/**
 * SSH Service
 * Handles SSH connections to remote servers
 */
class SSHService
{
    private ?SSH2 $ssh = null;
    private ?SFTP $sftp = null;
    private array $serverConfig;

    public function __construct(array $serverConfig)
    {
        $this->serverConfig = $serverConfig;
    }

    /**
     * Connect to the remote server via SSH
     */
    public function connect(): bool
    {
        try {
            $host = $this->serverConfig['hostname'] ?: $this->serverConfig['ip_address'];
            $port = $this->serverConfig['ssh_port'] ?: 22;
            
            $this->ssh = new SSH2($host, $port);
            
            // Authenticate
            if (!empty($this->serverConfig['ssh_private_key_path']) && file_exists($this->serverConfig['ssh_private_key_path'])) {
                // Key-based authentication from file
                $keyContent = file_get_contents($this->serverConfig['ssh_private_key_path']);
                $key = \phpseclib3\Crypt\PublicKeyLoader::load($keyContent);
                if (!$this->ssh->login($this->serverConfig['ssh_user'], $key)) {
                    throw new Exception('SSH key authentication failed');
                }
            } elseif (!empty($this->serverConfig['ssh_key'])) {
                // Key-based authentication from config
                $key = \phpseclib3\Crypt\PublicKeyLoader::load($this->serverConfig['ssh_key']);
                if (!$this->ssh->login($this->serverConfig['ssh_user'], $key)) {
                    throw new Exception('SSH key authentication failed');
                }
            } elseif (!empty($this->serverConfig['ssh_password'])) {
                // Password authentication
                if (!$this->ssh->login($this->serverConfig['ssh_user'], $this->serverConfig['ssh_password'])) {
                    throw new Exception('SSH password authentication failed');
                }
            } else {
                throw new Exception('No SSH authentication method provided');
            }
            
            // Initialize SFTP for file operations
            $this->sftp = new SFTP($host, $port);
            
            // Use the same authentication method for SFTP
            $authSuccess = false;
            if (!empty($this->serverConfig['ssh_private_key_path']) && file_exists($this->serverConfig['ssh_private_key_path'])) {
                $keyContent = file_get_contents($this->serverConfig['ssh_private_key_path']);
                $key = \phpseclib3\Crypt\PublicKeyLoader::load($keyContent);
                $authSuccess = $this->sftp->login($this->serverConfig['ssh_user'], $key);
            } elseif (!empty($this->serverConfig['ssh_key'])) {
                $key = \phpseclib3\Crypt\PublicKeyLoader::load($this->serverConfig['ssh_key']);
                $authSuccess = $this->sftp->login($this->serverConfig['ssh_user'], $key);
            } elseif (!empty($this->serverConfig['ssh_password'])) {
                $authSuccess = $this->sftp->login($this->serverConfig['ssh_user'], $this->serverConfig['ssh_password']);
            }
            
            if (!$authSuccess) {
                throw new Exception('SFTP authentication failed');
            }
            
            return true;
        } catch (Exception $e) {
            error_log("SSH connection failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Execute a command on the remote server
     */
    public function executeCommand(string $command): array
    {
        if (!$this->ssh) {
            throw new Exception('SSH connection not established');
        }

        try {
            $output = $this->ssh->exec($command);
            $exitCode = $this->ssh->getExitStatus();
            
            return [
                'success' => $exitCode === 0,
                'output' => $output,
                'exit_code' => $exitCode
            ];
        } catch (Exception $e) {
            error_log("SSH command execution failed: " . $e->getMessage());
            return [
                'success' => false,
                'output' => '',
                'exit_code' => -1,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Check if a directory exists on the remote server
     */
    public function directoryExists(string $path): bool
    {
        if (!$this->sftp) {
            return false;
        }

        try {
            return $this->sftp->is_dir($path);
        } catch (Exception $e) {
            error_log("SFTP directory check failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if a file exists on the remote server
     */
    public function fileExists(string $path): bool
    {
        if (!$this->sftp) {
            return false;
        }

        try {
            return $this->sftp->file_exists($path);
        } catch (Exception $e) {
            error_log("SFTP file check failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * List directories matching a pattern (for wildcard expansion)
     */
    public function expandWildcardPath(string $pattern): array
    {
        if (!$this->ssh) {
            return [];
        }

        try {
            // Use shell glob to expand wildcards
            $command = "find " . escapeshellarg(dirname($pattern)) . " -maxdepth 1 -type d -name " . escapeshellarg(basename($pattern)) . " 2>/dev/null";
            $result = $this->executeCommand($command);
            
            if (!$result['success']) {
                return [];
            }
            
            $paths = array_filter(explode("\n", trim($result['output'])));
            return $paths;
        } catch (Exception $e) {
            error_log("Wildcard expansion failed: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Expand wildcard paths on remote server via SSH
     */
    public function expandWildcardPathRemote(string $path): array
    {
        // If no wildcard, return the path as-is
        if (strpos($path, '*') === false) {
            return [$path];
        }

        try {
            // Use bash glob expansion instead of find
            $command = "bash -c 'for dir in $path; do [ -d \"\$dir\" ] && echo \"\$dir\"; done' 2>/dev/null";
            
            $result = $this->executeCommand($command);
            
            if (!$result['success'] || empty($result['output'])) {
                error_log("No matches found for wildcard path: {$path}");
                return [];
            }
            
            $expandedPaths = array_filter(explode("\n", trim($result['output'])));
            
            error_log("Expanded wildcard path '{$path}' to: " . implode(', ', $expandedPaths));
            return $expandedPaths;
            
        } catch (\Exception $e) {
            error_log("Error expanding wildcard path '{$path}': " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get file contents from remote server
     */
    public function getFileContents(string $path): ?string
    {
        if (!$this->sftp) {
            return null;
        }

        try {
            return $this->sftp->get($path);
        } catch (Exception $e) {
            error_log("SFTP file read failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Disconnect from the server
     */
    public function disconnect(): void
    {
        if ($this->ssh) {
            $this->ssh->disconnect();
            $this->ssh = null;
        }
        
        if ($this->sftp) {
            $this->sftp->disconnect();
            $this->sftp = null;
        }
    }

    /**
     * Destructor to ensure connections are closed
     */
    public function __destruct()
    {
        $this->disconnect();
    }
}