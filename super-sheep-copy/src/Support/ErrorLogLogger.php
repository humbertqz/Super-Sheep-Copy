<?php
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log -- This adapter intentionally writes plugin failures to the configured PHP/WordPress error log.

declare(strict_types=1);

namespace SuperSheepCopy\Support;

final class ErrorLogLogger implements LoggerInterface
{
    private bool $debug_enabled;
    /** @var callable(string):void */
    private $writer;

    /**
     * @param callable(string):void|null $writer
     */
    public function __construct(bool $debug_enabled = false, $writer = null)
    {
        $this->debug_enabled = $debug_enabled;
        $this->writer = $writer !== null ? $writer : static function (string $entry): void {
            error_log($entry);
        };
    }

    public function info(string $message, array $context = array()): void
    {
        if ($this->debug_enabled) {
            $this->write('INFO', $message, $context);
        }
    }

    public function warning(string $message, array $context = array()): void
    {
        $this->write('WARNING', $message, $context);
    }

    public function error(string $message, array $context = array()): void
    {
        $this->write('ERROR', $message, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    private function write(string $level, string $message, array $context): void
    {
        $entry = '[Super Sheep Copy] ' . $level . ': ' . $message;
        if ($context !== array()) {
            $encoded = function_exists('wp_json_encode') ? wp_json_encode($context) : json_encode($context);
            if (is_string($encoded)) {
                $entry .= ' ' . $encoded;
            }
        }

        $writer = $this->writer;
        $writer($entry);
    }
}
