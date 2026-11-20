<?php

namespace App\Ingest;

use App\Analytics\Stack\ServerSoftware;
use App\Enums\IngestRefusalReason;
use Illuminate\Support\Facades\Validator;

/**
 * One minute of the site's server, as edgelog-ship --metrics reads it from
 * /proc and df: the processors' busy share and the bytes a second since the
 * sample before (null for the first one), load, memory and swap in bytes,
 * and each disk's space in bytes, at most 32 of them. The machine (a hash of
 * its /etc/machine-id) and its host name tell which server it is; a shipper
 * older than them sends neither. Once an hour, a sample also says what runs
 * on the machine (null in the others, and from a shipper older than 1.4.0).
 */
final readonly class ServerSample
{
    /**
     * The most disks one sample may hold.
     */
    public const int MAX_DISKS = 32;

    /**
     * The most programs one sample may name.
     */
    public const int MAX_PROGRAMS = 64;

    /**
     * @param  array<string, int|float|null>  $figures
     * @param  list<array{mount: string, size: int, used: int, free: int}>  $disks
     * @param  ?array<string, mixed>  $software  what runs on the machine, as ServerSoftware::reported() keeps it
     */
    private function __construct(
        public array $figures,
        public array $disks,
        public ?string $machineId,
        public ?string $hostname,
        public ?array $software = null,
    ) {}

    /**
     * Read a sample from its JSON body.
     *
     * @throws IngestRefusal
     */
    public static function fromJson(string $json): self
    {
        $sample = json_decode($json, true);

        if (! is_array($sample)) {
            throw new IngestRefusal(IngestRefusalReason::BadSample);
        }

        $count = ['required', 'integer', 'min:0'];
        $rate = ['present', 'nullable', 'integer', 'min:0'];
        $share = ['present', 'nullable', 'numeric', 'between:0,100'];
        $load = ['required', 'numeric', 'min:0', 'max:100000'];

        $validator = Validator::make($sample, [
            'uptime' => [...$count, 'max:4294967295'],
            'cores' => ['required', 'integer', 'between:1,65535'],
            'cpu' => $share,
            'iowait' => $share,
            'load1' => $load,
            'load5' => $load,
            'load15' => $load,
            'memory_total' => [...$count, 'gt:0'],
            'memory_available' => $count,
            'swap_total' => $count,
            'swap_free' => $count,
            'disk_read' => $rate,
            'disk_write' => $rate,
            'network_in' => $rate,
            'network_out' => $rate,
            'disks' => ['present', 'array', 'list', 'max:'.self::MAX_DISKS],
            'disks.*.mount' => ['required', 'string', 'max:255'],
            'disks.*.size' => $count,
            'disks.*.used' => $count,
            'disks.*.free' => $count,
            'machine_id' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9a-f]{32,64}$/'],
            'hostname' => ['sometimes', 'nullable', 'string', 'max:255'],
            'software' => ['sometimes', 'nullable', 'array'],
            'software.os' => ['nullable', 'string', 'max:64'],
            'software.os_version' => ['nullable', 'string', 'max:64'],
            'software.programs' => ['sometimes', 'array', 'list', 'max:'.self::MAX_PROGRAMS],
            'software.programs.*.product' => ['required', 'string', 'max:32'],
            'software.programs.*.version' => ['required', 'string', 'max:128'],
            'software.programs.*.package' => ['nullable', 'string', 'max:128'],
            'software.programs.*.package_version' => ['nullable', 'string', 'max:128'],
            'software.programs.*.settings' => ['nullable', 'array', 'max:8'],
            'software.system' => ['nullable', 'array'],
            'software.system.reboot_required' => ['nullable', 'boolean'],
            'software.system.reboot_packages' => ['nullable', 'array', 'max:20'],
            'software.system.updates' => ['nullable', 'integer', 'min:0'],
            'software.system.security_updates' => ['nullable', 'integer', 'min:0'],
            'software.system.unattended_upgrades' => ['nullable', 'boolean'],
            'software.system.listening' => ['nullable', 'array', 'max:64'],
            'software.system.ssh' => ['nullable', 'array', 'max:4'],
            'software.system.failed_units' => ['nullable', 'array', 'max:20'],
        ]);

        if ($validator->fails()) {
            throw new IngestRefusal(IngestRefusalReason::BadSample);
        }

        $valid = $validator->validated();
        $disks = array_map(fn (array $disk) => [
            'mount' => $disk['mount'],
            'size' => (int) $disk['size'],
            'used' => (int) $disk['used'],
            'free' => (int) $disk['free'],
        ], $valid['disks']);
        $machineId = $valid['machine_id'] ?? null;
        $hostname = isset($valid['hostname']) && trim($valid['hostname']) !== '' ? trim($valid['hostname']) : null;
        $software = isset($valid['software']) ? ServerSoftware::reported($valid['software']) : null;
        unset($valid['disks'], $valid['machine_id'], $valid['hostname'], $valid['software']);

        return new self($valid, $disks, $machineId, $hostname, $software);
    }

    /**
     * The sample as a row of server_metric_minutes.
     *
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [...$this->figures, 'disks' => $this->disks];
    }
}
