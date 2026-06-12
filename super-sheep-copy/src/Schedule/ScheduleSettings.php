<?php

declare(strict_types=1);

namespace SuperSheepCopy\Schedule;

final class ScheduleSettings
{
    private bool $enabled;
    private string $frequency;
    private string $time_of_day;
    private string $last_status;
    private string $last_message;
    private string $last_run_at;

    public function __construct(
        bool $enabled,
        string $frequency,
        string $time_of_day,
        string $last_status = '',
        string $last_message = '',
        string $last_run_at = ''
    ) {
        $this->enabled = $enabled;
        $this->frequency = in_array($frequency, array('daily', 'weekly', 'monthly'), true) ? $frequency : 'daily';
        $this->time_of_day = preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time_of_day) === 1 ? $time_of_day : '02:00';
        $this->last_status = $this->sanitizeStatus($last_status);
        $this->last_message = trim(strip_tags($last_message));
        $this->last_run_at = trim($last_run_at);
    }

    public static function defaults(): self
    {
        return new self(false, 'daily', '02:00');
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $defaults = self::defaults();

        return new self(
            self::boolValue($data, 'enabled', $defaults->enabled()),
            isset($data['frequency']) && is_scalar($data['frequency']) ? (string) $data['frequency'] : $defaults->frequency(),
            isset($data['time_of_day']) && is_scalar($data['time_of_day']) ? (string) $data['time_of_day'] : $defaults->timeOfDay(),
            isset($data['last_status']) && is_scalar($data['last_status']) ? (string) $data['last_status'] : '',
            isset($data['last_message']) && is_scalar($data['last_message']) ? (string) $data['last_message'] : '',
            isset($data['last_run_at']) && is_scalar($data['last_run_at']) ? (string) $data['last_run_at'] : ''
        );
    }

    public function withLastRun(string $status, string $message, string $run_at): self
    {
        return new self($this->enabled, $this->frequency, $this->time_of_day, $status, $message, $run_at);
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function frequency(): string
    {
        return $this->frequency;
    }

    public function timeOfDay(): string
    {
        return $this->time_of_day;
    }

    public function lastStatus(): string
    {
        return $this->last_status;
    }

    public function lastMessage(): string
    {
        return $this->last_message;
    }

    public function lastRunAt(): string
    {
        return $this->last_run_at;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return array(
            'enabled' => $this->enabled,
            'frequency' => $this->frequency,
            'time_of_day' => $this->time_of_day,
            'last_status' => $this->last_status,
            'last_message' => $this->last_message,
            'last_run_at' => $this->last_run_at,
        );
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function boolValue(array $data, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $data)) {
            return $default;
        }

        $value = $data[$key];
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        return $value === 'on' || $value === 'yes' || $value === 'true';
    }

    private function sanitizeStatus(string $status): string
    {
        return in_array($status, array('queued', 'completed', 'failed', 'skipped'), true) ? $status : '';
    }
}
