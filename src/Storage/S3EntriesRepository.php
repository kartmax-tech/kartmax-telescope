<?php

namespace Laravel\Telescope\Storage;

use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Laravel\Telescope\Contracts\ClearableRepository;
use Laravel\Telescope\Contracts\EntriesRepository as Contract;
use Laravel\Telescope\Contracts\PrunableRepository;
use Laravel\Telescope\Contracts\TerminableRepository;
use Laravel\Telescope\EntryResult;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\EntryUpdate;
use Laravel\Telescope\Storage\EntryQueryOptions;
use Carbon\Carbon;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Log;
use Laravel\Telescope\Storage\S3DailyStatsService;

class S3EntriesRepository implements Contract, ClearableRepository, PrunableRepository, TerminableRepository
{
    protected $disk;
    protected $directory;
    protected $monitoredTags;
    protected $monitoredTagsFile = 'monitored-tags.json';
    protected $s3Client;
    protected $statsService;

    public function __construct(string $disk, string $directory, ?S3DailyStatsService $statsService = null)
    {
        $this->disk = $disk;
        $this->directory = trim($directory, '/');
        $this->monitoredTagsFile = $this->directory . '/' . $this->monitoredTagsFile;
        $this->statsService = $statsService ?? app(S3DailyStatsService::class);
        
        // Initialize standard AWS S3 client
        $this->s3Client = new S3Client([
            'version' => 'latest',
            'region'  => config('filesystems.disks.' . $disk . '.region'),
            'credentials' => [
                'key'    => config('filesystems.disks.' . $disk . '.key'),
                'secret' => config('filesystems.disks.' . $disk . '.secret'),
            ]
        ]);
    }

    protected function getServiceTag($entry = null)
    {
        // Get from entry tags if available
        if ($entry && isset($entry->tags)) {
            $staticTag = config('telescope.custom_static_tag');
            foreach ($entry->tags as $tag) {
                if (strpos($tag, $staticTag . ':') === 0) {
                    return str_replace($staticTag . ':', '', $tag);
                }
            }
        }
        
        // Fallback to config or default
        return config('telescope.custom_static_tag', 'default-service');
    }

    protected function entryPath($type, $batchId, $uuid, $entry = null)
    {
        $now = now();
        $serviceTag = $this->getServiceTag($entry);
        $date = $now->format('Y-m-d');
        $hour = $now->format('H');
        $minute = $now->format('i');
        
        return "{$this->directory}/{$type}/{$serviceTag}/{$date}/{$hour}/{$minute}/{$uuid}.json";
    }

    public function store(Collection $entries)
    {
        if ($entries->isEmpty()) {
            return;
        }

        foreach ($entries as $entry) {
            // Pass the entry to entryPath to extract service tag
            $filePath = $this->entryPath($entry->type, $entry->batchId, $entry->uuid, $entry);
            
            // Add service tag to entry data
            $serviceTag = $this->getServiceTag($entry);
            
            // Build complete entry data
            $entryData = [
                'uuid' => $entry->uuid,
                'batch_id' => $entry->batchId,
                'type' => $entry->type,
                'service_tag' => $serviceTag,
                'family_hash' => $entry->familyHash,
                'content' => $entry->content,
                'created_at' => $entry->recordedAt->toISOString(),
                'tags' => $entry->tags ?: [],
            ];
            
            try {
                // Use standard S3 putObject
                $this->s3Client->putObject([
                    'Bucket' => config('filesystems.disks.' . $this->disk . '.bucket'),
                    'Key' => $filePath,
                    'Body' => json_encode($entryData, JSON_PRETTY_PRINT),
                    'ContentType' => 'application/json',
                ]);

                // Increment stats for this entry type
                if ($this->statsService) {
                    $this->statsService->increment($entry->type);
                }
            } catch (\Exception $e) {
                // Log error but don't fail the application
                Log::warning('Failed to store Telescope entry', [
                    'error' => $e->getMessage(),
                    'entry_type' => $entry->type,
                    'service_tag' => $serviceTag,
                    'uuid' => $entry->uuid
                ]);
            }
        }
    }

    public function get($type, EntryQueryOptions $options)
    {
        // Require service to be specified - throw exception if missing
        if (!isset($options->serviceTag) || empty($options->serviceTag)) {
            throw new \InvalidArgumentException(
                'Service selection required. Please select a service to view logs. ' .
                'Available services can be configured via TELESCOPE_CUSTOM_STATIC_TAG environment variable.'
            );
        }

        $results = collect();
        $serviceTag = $options->serviceTag;
        
        // Determine time range to scan (default: last 30 minutes for real-time viewing)
        $minutesToScan = $options->timeRange ?? 30;
        $timeSlots = collect();
        
        for ($i = 0; $i < $minutesToScan; $i++) {
            $time = now()->subMinutes($i);
            $timeSlots->push([
                'date' => $time->format('Y-m-d'),
                'hour' => $time->format('H'),
                'minute' => $time->format('i')
            ]);
        }

        // Build the base path with service tag
        $basePath = "{$this->directory}/{$type}/{$serviceTag}";

        foreach ($timeSlots as $timeSlot) {
            // If we have enough results, break early
            if ($results->count() >= $options->limit) {
                break;
            }

            $timePath = "{$basePath}/{$timeSlot['date']}/{$timeSlot['hour']}/{$timeSlot['minute']}";

            try {
                if (!Storage::disk($this->disk)->exists($timePath)) {
                    continue;
                }

                $files = Storage::disk($this->disk)->allFiles($timePath);

                // Process files for this time slot
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
            } catch (\Exception $e) {
                Log::warning('Failed to retrieve Telescope entries', [
                    'time_path' => $timePath,
                    'type' => $type,
                    'service_tag' => $serviceTag,
                    'error' => $e->getMessage()
                ]);
                continue;
            }
        }

        return $results->sortByDesc(function($entry) {
            return $entry->sequence ?? ($entry->createdAt ? $entry->createdAt->timestamp : 0);
        })->take($options->limit)->values();
    }

    public function find($id): EntryResult
    {
        // If a file path is provided via query parameter, use direct access
        $filePath = request()->query('file_path');
        
        if ($filePath && Storage::disk($this->disk)->exists($filePath)) {
            try {
                $data = json_decode(Storage::disk($this->disk)->get($filePath), true);
                return $this->toEntryResult($data, $filePath);
            } catch (\Exception $e) {
                Log::warning("Failed to read Telescope entry via direct path: {$id}", [
                    'file_path' => $filePath,
                    'error' => $e->getMessage()
                ]);
                // Fall through to traditional search if direct access fails
            }
        }

        // Traditional search method (fallback)
        // Look in the last 5 days
        $datesToCheck = collect(range(0, 4))
            ->map(fn($daysAgo) => now()->subDays($daysAgo)->format('Y-m-d'));

        foreach ($datesToCheck as $date) {
            $files = Storage::disk($this->disk)->allFiles($this->directory);
            foreach ($files as $file) {
                if (str_ends_with($file, "/{$id}.json")) {
                    try {
                        $data = json_decode(Storage::disk($this->disk)->get($file), true);
                        return $this->toEntryResult($data, $file);
                    } catch (\Exception $e) {
                        Log::warning("Failed to read Telescope entry: {$id}", [
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }
        }
        
        abort(404, 'Entry not found');
    }

    public function update(Collection $updates)
    {
        return null; // S3 implementation doesn't support updates
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
        $datesToCheck = collect(range(0, 30)) // Check up to 30 days back
            ->map(fn($daysAgo) => now()->subDays($daysAgo)->format('Y-m-d'));

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
            $file // Pass the full file path for direct access
        );
    }
} 