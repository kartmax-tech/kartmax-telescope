<?php

namespace Laravel\Telescope\Storage;

use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Laravel\Telescope\Contracts\ClearableRepository;
use Laravel\Telescope\Contracts\EntriesRepository as Contract;
use Laravel\Telescope\Contracts\PrunableRepository;
use Laravel\Telescope\Contracts\TerminableRepository;
use Laravel\Telescope\EntryResult;
use Laravel\Telescope\Storage\EntryQueryOptions;
use Carbon\Carbon;

/**
 * Example Telescope repository that emits entries to stdout as JSON lines.
 * Your Lambda Logs API extension will receive these and upload to S3 using the provided s3_key.
 */
class S3EntriesRepository implements Contract, ClearableRepository, PrunableRepository, TerminableRepository
{
    protected $disk;
    protected $directory;
    protected $monitoredTags;
    protected $monitoredTagsFile = 'monitored-tags.json';

    public function __construct(string $disk, string $directory)
    {
        $this->disk = $disk;
        $this->directory = trim($directory, '/');
        $this->monitoredTagsFile = $this->directory . '/' . $this->monitoredTagsFile;
    }

    protected function getServiceTag($entry = null)
    {
        $staticTag = config('telescope.custom_static_tag');
        if ($entry && isset($entry->tags) && is_array($entry->tags)) {
            foreach ($entry->tags as $tag) {
                if ($staticTag && strpos($tag, $staticTag . ':') === 0) {
                    return str_replace($staticTag . ':', '', $tag);
                }
            }
        }
        return config('telescope.custom_static_tag', config('app.name', 'laravel'));
    }

    protected function entryPath($type, $batchId, $uuid, $entry = null)
    {
        $now = now()->setTimezone('Asia/Kolkata');
        $serviceTag = $this->getServiceTag($entry);
        $date = $now->format('Y-m-d');
        $hour = $now->format('H');

        $currentMinute = intval($now->format('i'));
        $roundedMinute = intval($currentMinute / 5) * 5;
        $minute = sprintf('%02d', $roundedMinute);

        return "{$this->directory}/{$type}/{$serviceTag}/{$date}/{$hour}/{$minute}/{$uuid}.json";
    }

    public function store(Collection $entries)
    {
        if ($entries->isEmpty()) {
            return;
        }

        foreach ($entries as $entry) {
            $filePath = $this->entryPath($entry->type, $entry->batchId, $entry->uuid, $entry);
            $serviceTag = $this->getServiceTag($entry);

            $payload = [
                'uuid' => $entry->uuid,
                'batch_id' => $entry->batchId,
                'type' => $entry->type,
                'service_tag' => $serviceTag,
                'family_hash' => $entry->familyHash,
                'content' => $entry->content,
                'created_at' => $entry->recordedAt->toISOString(),
                'tags' => $entry->tags ?: [],
                's3_key' => $filePath,
            ];

            try {
                // Emit JSON object to stdout for Lumigo APM extension
                $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
                if ($json !== false) {
                    file_put_contents('php://stdout', $json . PHP_EOL);
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to emit Telescope entry to stdout', [
                    'error' => $e->getMessage(),
                    'entry_type' => $entry->type,
                    'service_tag' => $serviceTag,
                    'uuid' => $entry->uuid,
                ]);
            }
        }
    }

    public function get($type, EntryQueryOptions $options)
    {
        if (!isset($options->serviceTag) || empty($options->serviceTag)) {
            throw new \InvalidArgumentException('Service selection required. Please select a service to view logs.');
        }

        $results = collect();
        $serviceTag = $options->serviceTag;

        $timeSlots = collect();
        if ($options->fromDateTime && $options->toDateTime) {
            $fromDate = Carbon::parse($options->fromDateTime)->setTimezone('Asia/Kolkata');
            $toDate = Carbon::parse($options->toDateTime)->setTimezone('Asia/Kolkata');

            $current = $fromDate->copy();
            $currentMinute = intval($current->format('i'));
            $roundedMinute = intval($currentMinute / 5) * 5;
            $current->minute($roundedMinute)->second(0);

            while ($current->lessThanOrEqualTo($toDate)) {
                $timeSlots->push([
                    'date' => $current->format('Y-m-d'),
                    'hour' => $current->format('H'),
                    'minute' => sprintf('%02d', $current->minute)
                ]);
                $current->addMinutes(5);
            }
        } else {
            $minutesToScan = $options->timeRange ?? 30;
            $fiveMinuteSlots = ceil($minutesToScan / 5);
            for ($i = 0; $i < $fiveMinuteSlots; $i++) {
                $time = now()->setTimezone('Asia/Kolkata')->subMinutes($i * 5);
                $minute = intval($time->format('i'));
                $roundedMinute = intval($minute / 5) * 5;
                $time->minute($roundedMinute);
                $timeSlots->push([
                    'date' => $time->format('Y-m-d'),
                    'hour' => $time->format('H'),
                    'minute' => sprintf('%02d', $time->minute)
                ]);
            }
        }

        $basePath = "{$this->directory}/{$type}/{$serviceTag}";

        foreach ($timeSlots as $timeSlot) {
            if ($results->count() >= $options->limit) {
                break;
            }

            $timePath = "{$basePath}/{$timeSlot['date']}/{$timeSlot['hour']}/{$timeSlot['minute']}";

            try {
                if (!Storage::disk($this->disk)->exists($timePath)) {
                    continue;
                }

                $files = Storage::disk($this->disk)->allFiles($timePath);
                foreach ($files as $file) {
                    if (!str_ends_with($file, '.json')) {
                        continue;
                    }
                    if ($results->count() >= $options->limit) {
                        break;
                    }
                    $data = json_decode(Storage::disk($this->disk)->get($file), true);
                    if ($this->matchesOptions($data, $options)) {
                        $results->push($this->toEntryResult($data, $file));
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to retrieve Telescope entries', [
                    'time_path' => $timePath,
                    'type' => $type,
                    'service_tag' => $serviceTag,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }

        return $results->sortByDesc(function ($entry) {
            return $entry->sequence ?? ($entry->createdAt ? $entry->createdAt->timestamp : 0);
        })->take($options->limit)->values();
    }

    public function find($id): EntryResult
    {
        $filePath = request()->query('file_path');
        if ($filePath && Storage::disk($this->disk)->exists($filePath)) {
            try {
                $data = json_decode(Storage::disk($this->disk)->get($filePath), true);
                return $this->toEntryResult($data, $filePath);
            } catch (\Throwable $e) {
                Log::warning("Failed to read Telescope entry via direct path: {$id}", [
                    'file_path' => $filePath,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $datesToCheck = collect(range(0, 4))
            ->map(fn ($daysAgo) => now()->subDays($daysAgo)->format('Y-m-d'));

        foreach ($datesToCheck as $date) {
            $files = Storage::disk($this->disk)->allFiles($this->directory);
            foreach ($files as $file) {
                if (str_ends_with($file, "/{$id}.json")) {
                    try {
                        $data = json_decode(Storage::disk($this->disk)->get($file), true);
                        return $this->toEntryResult($data, $file);
                    } catch (\Throwable $e) {
                        Log::warning("Failed to read Telescope entry: {$id}", [
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        abort(404, 'Entry not found');
    }

    public function update(Collection $updates)
    {
        return null;
    }

    public function loadMonitoredTags()
    {
        if (Storage::disk($this->disk)->exists($this->monitoredTagsFile)) {
            $tags = json_decode(Storage::disk($this->disk)->get($this->monitoredTagsFile), true);
            $this->monitoredTags = is_array($tags) ? $tags : [];
        } else {
            $this->monitoredTags = [];
        }
    }

    public function monitoring()
    {
        if ($this->monitoredTags === null) {
            $this->loadMonitoredTags();
        }
        return $this->monitoredTags;
    }

    public function monitor(array $tags)
    {
        if ($this->monitoredTags === null) {
            $this->loadMonitoredTags();
        }
        $this->monitoredTags = array_unique(array_merge($this->monitoredTags, $tags));
        Storage::disk($this->disk)->put($this->monitoredTagsFile, json_encode(array_values($this->monitoredTags)));
    }

    public function stopMonitoring(array $tags)
    {
        if ($this->monitoredTags === null) {
            $this->loadMonitoredTags();
        }
        $this->monitoredTags = array_values(array_diff($this->monitoredTags, $tags));
        Storage::disk($this->disk)->put($this->monitoredTagsFile, json_encode($this->monitoredTags));
    }

    public function isMonitoring(array $tags)
    {
        if ($this->monitoredTags === null) {
            $this->loadMonitoredTags();
        }
        return !empty(array_intersect($tags, $this->monitoredTags));
    }

    public function prune(DateTimeInterface $before)
    {
        $deleted = 0;
        $datesToCheck = collect(range(0, 30))
            ->map(fn ($daysAgo) => now()->subDays($daysAgo)->format('Y-m-d'));

        foreach ($datesToCheck as $date) {
            $dateTimestamp = Carbon::parse($date)->timestamp;
            if ($dateTimestamp < $before->getTimestamp()) {
                $path = "{$this->directory}/{$date}";
                if (Storage::disk($this->disk)->exists($path)) {
                    Storage::disk($this->disk)->deleteDirectory($path);
                    $deleted++;
                }
            }
        }
        return $deleted;
    }

    public function clear()
    {
        Storage::disk($this->disk)->deleteDirectory($this->directory);
    }

    public function terminate()
    {
        $this->monitoredTags = null;
    }

    protected function matchesOptions($data, EntryQueryOptions $options)
    {
        if ($options->batchId && ($data['batch_id'] ?? null) !== $options->batchId) return false;
        if ($options->tag && (!isset($data['tags']) || !in_array($options->tag, $data['tags']))) return false;
        if ($options->familyHash && ($data['family_hash'] ?? null) !== $options->familyHash) return false;
        if ($options->beforeSequence && ($data['sequence'] ?? null) >= $options->beforeSequence) return false;
        if ($options->uuids && !in_array($data['uuid'] ?? null, $options->uuids)) return false;
        return true;
    }

    protected function toEntryResult($data, $file)
    {
        return new EntryResult(
            $data['uuid'] ?? null,
            $data['sequence'] ?? null,
            $data['batch_id'] ?? null,
            $data['type'] ?? null,
            $data['family_hash'] ?? null,
            $data['content'] ?? [],
            Carbon::parse($data['created_at'] ?? now()),
            $data['tags'] ?? [],
            $file
        );
    }
}