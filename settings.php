<?php
/*******************************************************************************
 *
 * VKGL-LOVD data pipeline.
 *
 * Created     : 2026-02-20
 * Modified    : 2026-02-27
 *
 * Copyright   : 2004-2026 Leiden University Medical Center; http://www.LUMC.nl/
 * Programmer  : Ivo F.A.C. Fokkema <I.F.A.C.Fokkema@LUMC.nl>
 *
 *************/

namespace LOVD;

class Settings
{
    // Class abstracting the use of a settings.json file for pipelines.
    // The actual filename can be set using the constructor.
    private array $data = [];
    private string $file = __DIR__ . '/settings.json';



    public function __construct (string $sFile = null)
    {
        // If the file exists, load it. Otherwise, create it.
        if ($sFile) {
            $this->file = $sFile;
        }

        if (file_exists($this->file)) {
            $aData = json_decode(file_get_contents($this->file), true);
            if ($aData === false || !is_array($aData)) {
                throw new \Exception("Unable to load {$this->file}");
            }
            $this->data = $aData;

        } elseif (!file_put_contents($this->file, '{}')) {
            throw new \Exception("Unable to create {$this->file}");
        }
    }



    public function delete (string $sKey): bool
    {
        if (!str_contains($sKey, '|')) {
            unset($this->data[$sKey]);
        } else {
            // We're trying to set a nested key.
            $aKeys = explode('|', $sKey);
            $aData =& $this->data;
            while ($aKeys) {
                $sKey = array_shift($aKeys);
                if (!$aKeys) {
                    // This was the last key.
                    unset($aData[$sKey]);
                } else {
                    // Dive deeper.
                    $aData =& $aData[$sKey];
                }
            }
        }
        return $this->save();
    }



    public function get (string $sKey = ''): mixed
    {
        if (!$sKey) {
            return $this->data;
        } elseif (isset($this->data[$sKey])) {
            return $this->data[$sKey];
        } elseif (str_contains($sKey, '|')) {
            // We're trying to set a nested key.
            $aKeys = explode('|', $sKey);
            $aData = $this->data;
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



    public function save (): bool
    {
        return (bool) file_put_contents($this->file, json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }



    public function set (string $sKey, mixed $Value): bool
    {
        if (!str_contains($sKey, '|')) {
            $this->data[$sKey] = $Value;
        } else {
            // We're trying to set a nested key.
            $aKeys = explode('|', $sKey);
            $aData =& $this->data;
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
        return $this->save();
    }
}
