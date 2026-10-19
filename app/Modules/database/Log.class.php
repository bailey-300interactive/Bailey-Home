<?php
/**
 * Small error log writer. Writes to storage/logs/YYYY-MM-DD.txt
 */
class Log
{
    private $path;

    public function __construct($dir = null)
    {
        $this->path = $dir ?: ROOT . 'storage/logs/';
    }

    public function write($message)
    {
        if (!is_dir($this->path)) {
            @mkdir($this->path, 0775, true);
        }
        $file = $this->path . gmdate('Y-m-d') . '.txt';
        $line = '[' . gmdate('H:i:s') . ' UTC] ' . (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] . ' | ' : '') . $message . PHP_EOL;
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }
}
