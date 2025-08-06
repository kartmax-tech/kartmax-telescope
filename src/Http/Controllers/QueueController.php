<?php

namespace Laravel\Telescope\Http\Controllers;

use Illuminate\Http\Request;
use Laravel\Telescope\Contracts\EntriesRepository;
use Laravel\Telescope\EntryType;
use Laravel\Telescope\Storage\EntryQueryOptions;
use Laravel\Telescope\Watchers\JobWatcher;

class QueueController extends EntryController
{
    /**
     * The entry type for the controller.
     *
     * @return string
     */
    protected function entryType()
    {
        return EntryType::JOB;
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
        $entry = $storage->find($id, $service);

        $batchOptions = null;
        if (isset($entry->content['updated_batch_id'])) {
            $batchOptions = EntryQueryOptions::forBatchId($entry->content['updated_batch_id']);
            if ($service) {
                $batchOptions->serviceTag($service);
            }
        }

        return response()->json([
            'entry' => $entry,
            'batch' => $batchOptions ? $storage->get(null, $batchOptions) : null,
        ]);
    }

    /**
     * The watcher class for the controller.
     *
     * @return string
     */
    protected function watcher()
    {
        return JobWatcher::class;
    }
}
