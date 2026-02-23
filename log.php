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

namespace LOVD;

class Log
{
    // Class abstracting the use of a status.log file for pipelines.
    // The actual filename can be set using the constructor.
    private bool $printToScreen = false;
    private string $file = __DIR__ . '/status.log';
    private string $lastLine = '';



    public function __construct (string $sFile = null)
    {
        // If the file does not exist, create it.
        if ($sFile) {
            $this->file = $sFile;
        }

        if (file_exists($this->file)) {
            if (!is_readable($this->file) || !is_writable($this->file) || is_dir($this->file)) {
                throw new \Exception("Unable to load {$this->file}");
            }

            // Get the last line out. Strip the timestamp, if present.
            $this->lastLine = ltrim(strrchr(rtrim(file_get_contents($this->file)), "\n"));
            if (preg_match('/^[0-9: -]{19} .. /', $this->lastLine)) {
                $this->lastLine = substr($this->lastLine, 23);
            }

        } elseif (!touch($this->file)) {
            throw new \Exception("Unable to create {$this->file}");
        }
    }



    public function add (string $sLog, string $sCode = ''): bool
    {
        $this->lastLine = $sLog;
        $sLog = date('Y-m-d H:i:s ') . str_pad($sCode, 3) . str_replace("\n", str_repeat(' ', 23) . "\n", rtrim($sLog)) . "\n";
        if ($this->printToScreen) {
            echo $sLog;
        }
        return (bool) file_put_contents(
            $this->file,
            $sLog,
            FILE_APPEND
        );
    }



    public function addBreak (): bool
    {
        return (bool) file_put_contents($this->file, "\n", FILE_APPEND);
    }



    public function addBreakIfNotEmpty (): bool
    {
        if ($this->lastLine) {
            return $this->addBreak();
        }
        return true;
    }



    public function getLastLine (): string
    {
        return $this->lastLine;
    }



    public function printToScreen (bool $bPrint): bool
    {
        $this->printToScreen = $bPrint;
        return $this->printToScreen;
    }
}
