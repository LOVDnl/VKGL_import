<?php
/*******************************************************************************
 *
 * VKGL-LOVD data pipeline.
 *
 * Created     : 2026-02-24
 * Modified    : 2026-07-15
 *
 * Copyright   : 2004-2026 Leiden University Medical Center; http://www.LUMC.nl/
 * Programmer  : Ivo F.A.C. Fokkema <I.F.A.C.Fokkema@LUMC.nl>
 *
 *************/

namespace LOVD;

if (!function_exists('ssh2_connect')) {
    throw new \Exception('SSH extension not installed — please install the PHP-SSH extension');
}

class SSH
{
    // Class abstracting the use of SSH/SCP to a remote server.
    private string $host;
    private string $fingerprint;
    private string $username;
    private int $port;
    private mixed $connection = null;

    public function __construct (string $sHost, string $sFingerprint)
    {
        $aHost = parse_url($sHost);
        if (!is_array($aHost) || empty($aHost['host']) || empty($aHost['user']) || empty($aHost['port'])) {
            throw new \Exception("Host string malformed ({$sHost}); use the format 'user@host:port'");
        }
        $this->host = $aHost['host'];
        $this->username = $aHost['user'];
        $this->port = $aHost['port'];
        $this->fingerprint = $sFingerprint;

        // Connect to the server, check the fingerprint, and authenticate using the keys.
        $this->connect();
    }





    private function connect (): void
    {
        // Connect to the server, check the fingerprint, and authenticate using the keys.
        $this->connection = ssh2_connect($this->host, $this->port);
        if (!$this->connection) {
            throw new \Exception("Unable to connect to {$this->host}");
        }

        // To obtain the fingerprint from the server, first check what key is being used:
        // var_dump(ssh2_methods_negotiated($this->connection)['hostkey']);
        // Then, on the server:
        // ssh-keygen -E md5 -lf /etc/ssh/ssh_host_ecdsa_key.pub
        // Remove the colons and change to uppercase.
        $sFingerprint = ssh2_fingerprint($this->connection);
        if ($sFingerprint != $this->fingerprint) {
            throw new \Exception("Finger print mismatch for host {$this->host} (received: {$sFingerprint})");
        }

        if (!ssh2_auth_agent($this->connection, $this->username)) {
            throw new \Exception("SSH authentication failed — check your SSH agent");
        }
    }





    public function disconnect (): void
    {
        // Disconnect and destroy the resource.
        if ($this->connection) {
            ssh2_disconnect($this->connection);
            $this->connection = null;
        }
    }





    public function download (string $sRemoteFile, string $sLocalFile): void
    {
        // Download a file over SCP, or throw an exception.
        if (!ssh2_scp_recv($this->connection, $sRemoteFile, $sLocalFile)) {
            throw new \Exception("SCP download failed: {$sRemoteFile}");
        }
    }





    public function upload (string $sLocalFile, string $sRemoteFile): void
    {
        // Upload a file over SCP, or throw an exception.
        if (!ssh2_scp_send($this->connection, $sLocalFile, $sRemoteFile)) {
            throw new \Exception("SCP upload failed: {$sRemoteFile}");
        }
    }
}
