<?php

namespace Laravel\Telescope\Http\Controllers;

use Illuminate\Bus\BatchRepository;
use Illuminate\Http\Request;
use Laravel\Telescope\Contracts\EntriesRepository;
use Laravel\Telescope\EntryType;
use Laravel\Telescope\EntryUpdate;
use Laravel\Telescope\Storage\EntryQueryOptions;
use Laravel\Telescope\Watchers\BatchWatcher;

class QueueBatchesController extends EntryController
{
    /**
     * The entry type for the controller.
     *
     * @return string
     */
    protected function entryType()
    {
        return EntryType::BATCH;
    }

    /**
     * The watcher class for the controller.
     *
     * @return string
     */
    protected function watcher()
    {
        return BatchWatcher::class;
    }

    /**
     * Get an entry with the given ID.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Laravel\Telescope\Contracts\EntriesRepository  $storage
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Request $request, EntriesRepository $storage, $id)
    {
        $service = $request->get('service');
        $batch = app(BatchRepository::class)->find($id);

        $storage->update(collect([
            new EntryUpdate($id, EntryType::BATCH,
                $batch->toArray()
            ),
        ]));

        $entry = $storage->find($id, $service)->generateAvatar();

        $batchOptions = EntryQueryOptions::forBatchId($entry->batchId)->limit(-1);
        if ($service) {
            $batchOptions->serviceTag($service);
        }

        return response()->json([
            'entry' => $entry,
            'batch' => $storage->get(null, $batchOptions),
        ]);
    }
}
