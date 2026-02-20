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

    public static function get (string $sKey = ''): mixed
    {
        if (!self::$data) {
            self::load();
        }
        if (!$sKey) {
            return self::$data;
        } elseif (isset(self::$data[$sKey])) {
            return self::$data[$sKey];
        } elseif (str_contains($sKey, '|')) {
            // We're trying to set a nested key.
            $aKeys = explode('|', $sKey);
            $aData = self::$data;
            while ($aKeys) {
                $sKey = array_shift($aKeys);
                if (!$aKeys) {
                    // This was the last key.
                    return ($aData[$sKey] ?? null);
                } elseif (isset($aData[$sKey])) {
                    $aData = $aData[$sKey];
                } else {
                    // Nope, we don't have that key.
                    break;
                }
            }
        }
        return null;
    }



    public static function init (bool $bForce = false): bool
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



    public static function set (string $sKey, mixed $Value): mixed
    {
        if (!self::$data) {
            self::load();
        }
        if (!str_contains($sKey, '|')) {
            self::$data[$sKey] = $Value;
        } else {
            // We're trying to set a nested key.
            $aKeys = explode('|', $sKey);
            $aData =& self::$data;
            while ($aKeys) {
                $sKey = array_shift($aKeys);
                if (!$aKeys) {
                    // This was the last key.
                    $aData[$sKey] = $Value;
                } else {
                    // Dive deeper.
                    $aData =& $aData[$sKey];
                }
            }
        }
        return self::save();
    }
}
