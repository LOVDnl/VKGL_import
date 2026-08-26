<?php
/*******************************************************************************
 *
 * VKGL-LOVD data pipeline.
 *
 * Created     : 2026-07-01
 * Modified    : 2026-08-12
 *
 * Copyright   : 2004-2026 Leiden University Medical Center; http://www.LUMC.nl/
 * Programmer  : Ivo F.A.C. Fokkema <I.F.A.C.Fokkema@LUMC.nl>
 *
 *************/

namespace LOVD;

require_once(__DIR__ . '/log.php');

class LOVD
{
    // Class that abstracts interacting with an LOVD database. Should be used statically.
    private static bool $bConnecting = false;
    private static bool $bConnected = false;
    private static $Log; // Holding the Log object so we can log our progress.

    public static function connect (string $sPath, Log $Log): bool
    {
        // Connect to LOVD, if not done already.
        // Set global LOVD variables as global, so we'll push them into the global space when inc-init.php defines them.
        global $_AUTH, $_CONF, $_DB, $_INI, $_PE, $_SETT, $_STAT, $_T, $_TABLES;

        // Did we receive a path at all?
        if (!$sPath) {
            $Log->add('LOVD path not provided - cannot connect.', '!!');
            return false;
        }

        // We can't connect to multiple LOVDs, so when we're already connected, just return true.
        if (self::$bConnected) {
            // Already connected.
            return true;
        }

        // Log that we're connecting, so we can handle fatal errors properly in the shutdown function.
        self::$bConnecting = true;
        self::$Log = $Log; // To make sure the shutdown method can also log things.

        // We'll log connecting and being connected, mostly for debugging purposes.
        $Log->add('Connecting to LOVD at ' . $sPath . ' ...');

        if (!file_exists($sPath) || !is_readable($sPath) || !is_dir($sPath)) {
            $Log->add('LOVD path does not exist or is not readable.', '!!');
            return false;
        }

        if (!file_exists($sPath . '/inc-init.php')) {
            $Log->add('LOVD path does not contain an LOVD init file.', '!!');
            return false;
        }

        // Find LOVD installation, run its inc-init.php to get DB connection, initiate $_SETT, etc.
        define('ROOT_PATH', rtrim($sPath, '/') . '/');
        define('FORMAT_ALLOW_TEXTPLAIN', true);
        $_GET['format'] = 'text/plain';
        // To prevent notices when running inc-init.php.
        $_SERVER = array_merge($_SERVER, array(
            'HTTP_HOST' => 'localhost',
            'REQUEST_URI' => '/' . basename(__FILE__),
            'QUERY_STRING' => '',
            'REQUEST_METHOD' => 'GET',
        ));
        // I need to get rid of the "headers already sent" warnings from inc-init.php, so I have to turn errors off.
        // Because of our shutdown function, we'll still have output if there is a problem connecting to LOVD.
        ini_set('display_errors', '0');
        ini_set('log_errors', '0'); // CLI logs errors to the screen, apparently.
        // Let the LOVD believe we're accessing it through SSL. LOVDs that demand this, will otherwise block us.
        // We have error messages suppressed anyway, as the LOVD in question will complain when it tries to define "SSL" as well.
        define('SSL', true);
        require ROOT_PATH . 'inc-init.php';
        require ROOT_PATH . 'inc-lib-form.php'; // For lovd_fetchDBID().
        ini_set('display_errors', '1'); // We do want to see errors from here on.

        self::$bConnected = true;
        self::$bConnecting = false;
        $Log->add('Connected!', 'OK');

        return true;
    }





    public static function deleteFromDatabase($nCenterId)
    {
        // Get the LOVD users with the given IDs.
        global $_DB;

        // We can't auto-connect because we don't have the path.
        if (!LOVD::isConnected()) {
            throw new \Exception('LOVD is not connected; cannot query the database.');
        }

        // Cast ids to an UNSIGNED to make sure ints match.
        return $_DB->q('
            DELETE FROM lovd_variants WHERE created_by = '. $nCenterId);
    }





    public static function getAllTranscripts (): array
    {
        // Get the LOVD's configured genome build.
        global $_DB;

        // We can't auto-connect because we don't have the path.
        if (!LOVD::isConnected()) {
            throw new \Exception('LOVD is not connected; cannot query the database.');
        }

        return $_DB->q('
            SELECT id_ncbi, id
            FROM ' . TABLE_TRANSCRIPTS . '
            ORDER BY id_ncbi')->fetchAllCombine();
    }





    public static function getGenomeBuild (): string
    {
        // Get the LOVD's configured genome build.
        global $_DB;

        // We can't auto-connect because we don't have the path.
        if (!LOVD::isConnected()) {
            throw new \Exception('LOVD is not connected; cannot query the database.');
        }

        return $_DB->q('SELECT refseq_build FROM ' . TABLE_CONFIG)->fetchColumn();
    }





    public static function getUsers (array $aIDs): array
    {
        // Get the LOVD users with the given IDs.
        global $_DB;

        if (!$aIDs) {
            return [];
        }

        // We can't auto-connect because we don't have the path.
        if (!LOVD::isConnected()) {
            throw new \Exception('LOVD is not connected; cannot query the database.');
        }

        // Cast ids to an UNSIGNED to make sure ints match.
        return $_DB->q('
            SELECT CAST(id AS UNSIGNED) AS id, name
            FROM ' . TABLE_USERS . '
            WHERE id IN (?' . str_repeat(', ?', count($aIDs) - 1) . ')
            ORDER BY id',
            array_values($aIDs))->fetchAllCombine();
    }





    public static function isConnected ()
    {
        return self::$bConnected;
    }





    public static function shutdown (): void
    {
        // Make sure we log things if there is a problem with connecting to LOVD.
        // If we're not connecting or if we connected successfully, we're not dying because of LOVD.
        if (!self::$bConnecting || self::$bConnected) {
            return;
        }

        $aError = error_get_last();
        // Check if we had a fatal error. If so, this is likely due to failures connecting to LOVD.
        if (($aError['type'] ?? -1) === E_ERROR) {
            self::$Log->add('Error occurred within LOVD: ' . $aError['message'], '!!');
        }
    }
}

register_shutdown_function(['LOVD\LOVD', 'shutdown']);
