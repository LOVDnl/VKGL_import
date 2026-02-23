#!/usr/bin/php
<?php
/*******************************************************************************
 *
 * VKGL-LOVD data pipeline.
 *
 * Created     : 2026-02-23
 * Modified    : 2026-02-23
 *
 * Copyright   : 2004-2026 Leiden University Medical Center; http://www.LUMC.nl/
 * Programmer  : Ivo F.A.C. Fokkema <I.F.A.C.Fokkema@LUMC.nl>
 *
 *************/

// New pipeline, running everything fully automated and reducing the time spent on manual verification even more.
define('ROOT_PATH', __DIR__);
require_once(ROOT_PATH . '/settings.php');
use LOVD\Settings;
$Settings = new Settings();

// All PHP scripts use these error codes; store them in the settings if they are missing.
// See http://tldp.org/LDP/abs/html/exitcodes.html for recommendations, in particular:
// "[I propose] restricting user-defined exit codes to the range 64 - 113 (...), to conform with the C/C++ standard."
foreach([
    'EXIT_OK' => 0,
    'EXIT_WARNINGS_OCCURRED' => 64,
    'EXIT_ERROR_ARGS_INSUFFICIENT' => 65,
    'EXIT_ERROR_ARGS_NOT_UNDERSTOOD' => 66,
    'EXIT_ERROR_INPUT_NOT_A_FILE' => 67,
    'EXIT_ERROR_INPUT_UNREADABLE' => 68,
    'EXIT_ERROR_INPUT_CANT_OPEN' => 69,
    'EXIT_ERROR_HEADER_FIELDS_NOT_FOUND' => 70,
    'EXIT_ERROR_HEADER_FIELDS_INCORRECT' => 71,
    'EXIT_ERROR_DATA_FIELD_COUNT_INCORRECT' => 72,
    'EXIT_ERROR_DATA_CONTENT_ERROR' => 73,
    'EXIT_ERROR_CACHE_CANT_CREATE' => 74,
    'EXIT_ERROR_CACHE_UNREADABLE' => 75,
    'EXIT_ERROR_CACHE_CANT_WRITE' => 76,
    'EXIT_ERROR_OUTPUT_CANT_CREATE' => 77,
    'EXIT_ERROR_CONNECTION_PROBLEM' => 78,
    'EXIT_ERROR_SETTINGS_PROBLEM' => 79,
] as $sErrorCode => $nErrorCode) {
    if ($Settings->get("error_codes|$sErrorCode") === null) {
        $Settings->set("error_codes|$sErrorCode", $nErrorCode);
    }
}



// First, determine which release we're supposed to be working on.
$aMonths = $Settings->get('release_months');
if ($aMonths === null) {
    print("Can't find information in the settings about the release months. Please configure them first.\n\n");
    die($Settings->get('error_codes|EXIT_ERROR_SETTINGS_PROBLEM'));
}

rsort($aMonths);
$nThisYear = date('Y');
$nThisMonth = date('m');
$nReleaseMonth = null;
foreach ($aMonths as $nMonth) {
    if ($nMonth <= $nThisMonth) {
        $nReleaseMonth = $nMonth;
        break;
    }
}
if ($nReleaseMonth === null) {
    $nReleaseMonth = max($aMonths);
    $nThisYear --;
}
$sRelease = $nThisYear . '-' . str_pad($nReleaseMonth, 2, '0', STR_PAD_LEFT);
