<?php
/*******************************************************************************
 *
 * VKGL-LOVD data pipeline.
 *
 * Created     : 2026-02-20
 * Modified    : 2026-02-20
 *
 * Copyright   : 2004-2026 Leiden University Medical Center; http://www.LUMC.nl/
 * Programmer  : Ivo F.A.C. Fokkema <I.F.A.C.Fokkema@LUMC.nl>
 *
 *************/

namespace LOVD\Settings;

class Settings
{
    // Class abstracting the use of a settings.json file for pipelines.
    // The actual filename can not be configured, and is hard-coded.
    private static array $data = [];
    private static string $file = __DIR__ . '/settings.json';

    public static function get ($sKey = ''): mixed
    {
        if (!self::$data) {
            self::load();
        }
        if (!$sKey) {
            return self::$data;
        } elseif (isset(self::$data[$sKey])) {
            return self::$data[$sKey];
        } else {
            return null;
        }
    }



    public static function init ($bForce = false): bool
    {
        // If the file does not exist, create it.
        if (!$bForce && self::load()) {
            return true;
        } else {
            return (bool) file_put_contents(self::$file, '{}');
        }
    }



    public static function load (): bool
    {
        if (file_exists(self::$file)) {
            $aData = json_decode(file_get_contents(self::$file), true);
            if ($aData && is_array($aData)) {
                self::$data = $aData;
                return true;
            }
            return false;

        } else {
            // Force to init it, instead.
            return self::init(true);
        }
    }



    public static function save (): bool
    {
        if (!self::$data) {
            $b = self::load();
            if (!$b) {
                // Don't try to save the file when it can't be loaded.
                return false;
            }
        }

        return (bool) file_put_contents(self::$file, json_encode(self::$data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
