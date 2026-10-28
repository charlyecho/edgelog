<?php

namespace App\Ingest;

use App\Enums\IngestRefusalReason;
use Illuminate\Support\Facades\Validator;

/**
 * One minute of the site's server, as edgelog-ship --metrics reads it from
 * /proc and df: the processors' busy share and the bytes a second since the
 * sample before (null for the first one), load, memory and swap in bytes,
 * and each disk's space in bytes, at most 32 of them.
 */
final readonly class ServerSample
{
    /**
     * The most disks one sample may hold.
     */
    public const int MAX_DISKS = 32;

    /**
     * @param  array<string, int|float|null>  $figures
     * @param  list<array{mount: string, size: int, used: int, free: int}>  $disks
     */
    private function __construct(public array $figures, public array $disks) {}

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
        unset($valid['disks']);

        return new self($valid, $disks);
    }

    /**
     * The sample as a row of site_metric_minutes.
     *
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [...$this->figures, 'disks' => $this->disks];
    }
}
