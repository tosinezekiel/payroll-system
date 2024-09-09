<?php

namespace App\Jobs;

use App\Models\Business;
use App\Services\PayItemService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Response;

class SyncPayItems implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const API_BASE_URL = 'https://some-partner-website.com/';

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected Business $business,
        private PayItemService $payItemService,
        private int $page = 1,
        private bool $end = false
    )
    {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $clairApiKey = config('services.clair.api_key');
        $path = 'clair-pay-item-sync/';

        do {
            $response = Http::withHeaders([
                'x-api-key' => $clairApiKey
            ])->get(self::API_BASE_URL . "{$path}{$this->business->external_id}?page={$this->page}");

            match ($response->status()) {
                Response::HTTP_UNAUTHORIZED => Log::alert('Unauthorized access to external API'),
                Response::HTTP_NOT_FOUND => Log::critical('No business found for provided external ID'),
                Response::HTTP_OK => $this->payItemService->processPayItems($response->json(), $this->business),
                default => Log::error('Something went wrong: ' . $response->status())
            };

            $data = $response->json();

            if(!$data['isLastPage']){
                $this->page++;
            }

            $this->end = true;

            
        } while (!$this->end);

        if (isset($this->end)) {
            $this->payItemService->removeStaleEntries($data['payItems'], $this->business);
        }
    }
}
