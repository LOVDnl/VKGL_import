<?php
/*******************************************************************************
 *
 * VKGL-LOVD data pipeline.
 *
 * Created     : 2026-02-24
 * Modified    : 2026-06-25
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
    private string $private_key;
    private string $public_key;
    private string $passphrase;
    private int $port;
    private mixed $connection = null;

    public function __construct (string $sHost, string $sFingerprint, string $sPrivateKey, string $sPassphrase)
    {
        $aHost = parse_url($sHost);
        if (!is_array($aHost) || empty($aHost['host']) || empty($aHost['user']) || empty($aHost['port'])) {
            throw new \Exception("Host string malformed ({$sHost}); use the format 'user@host:port'");
        }
        $this->host = $aHost['host'];
        $this->username = $aHost['user'];
        $this->port = $aHost['port'];
        $this->fingerprint = $sFingerprint;

        if (!file_exists($sPrivateKey) || !is_readable($sPrivateKey)) {
            throw new \Exception("Private key not found or not readable: {$sPrivateKey}");
        }
        $this->private_key = $sPrivateKey;

        $sPublicKey = $sPrivateKey . '.pub';
        if (!file_exists($sPublicKey) || !is_readable($sPublicKey)) {
            throw new \Exception("Public key not found or not readable: {$sPublicKey}");
        }
        $this->public_key = $sPublicKey;
        $this->passphrase = $sPassphrase;
    }
}
