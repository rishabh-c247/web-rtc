<?php
declare(strict_types=1);

namespace VoiceLink\WebSocket;

/**
 * Simple file-based logger for the WebSocket server process.
 * Writes timestamped lines to a configurable log file and optionally echoes to stdout.
 */
class Logger
{
    public function __construct(
        private readonly string $logFile,
        private readonly bool   $verbose = false
    ) {}

    public function info(string $message): void
    {
        $this->write('INFO', $message);
    }

    public function warn(string $message): void
    {
        $this->write('WARN', $message);
    }

    public function error(string $message): void
    {
        $this->write('ERROR', $message);
    }

    public function debug(string $message): void
    {
        if ($this->verbose) {
            $this->write('DEBUG', $message);
        }
    }

    private function write(string $level, string $message): void
    {
        $line = sprintf("[%s] [%-5s] %s\n", date('Y-m-d H:i:s'), $level, $message);
        file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
        if ($this->verbose) {
            echo $line;
        }
    }
}
