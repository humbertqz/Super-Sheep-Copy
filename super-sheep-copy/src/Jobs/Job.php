<?php

declare(strict_types=1);

namespace SuperSheepCopy\Jobs;

final class Job
{
    public const CREATED = 'created';
    public const PREFLIGHT = 'preflight';
    public const SCANNING_FILES = 'scanning_files';
    public const EXPORTING_DATABASE = 'exporting_database';
    public const ARCHIVING_FILES = 'archiving_files';
    public const PACKAGING_ARCHIVE = 'packaging_archive';
    public const FINALIZING_BACKUP = 'finalizing_backup';
    public const VALIDATING_RESTORE = 'validating_restore';
    public const EXTRACTING_RESTORE = 'extracting_restore';
    public const IMPORTING_DATABASE = 'importing_database';
    public const RESTORING_FILES = 'restoring_files';
    public const REPLACING_URLS = 'replacing_urls';
    public const CLEARING_CACHE = 'clearing_cache';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';
    public const ROLLED_BACK = 'rolled_back';

    private string $id;
    private string $type;
    private string $state;
    /** @var array<string, mixed> */
    private array $payload;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(string $id, string $type, string $state = self::CREATED, array $payload = array())
    {
        $this->id = $id;
        $this->type = $type;
        $this->state = $state;
        $this->payload = $payload;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function state(): string
    {
        return $this->state;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    /**
     * @return array{id:string,type:string,state:string,payload:array<string,mixed>}
     */
    public function toArray(): array
    {
        return array(
            'id' => $this->id,
            'type' => $this->type,
            'state' => $this->state,
            'payload' => $this->payload,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            isset($data['id']) && is_string($data['id']) ? $data['id'] : '',
            isset($data['type']) && is_string($data['type']) ? $data['type'] : '',
            isset($data['state']) && is_string($data['state']) ? $data['state'] : self::CREATED,
            isset($data['payload']) && is_array($data['payload']) ? $data['payload'] : array()
        );
    }
}
