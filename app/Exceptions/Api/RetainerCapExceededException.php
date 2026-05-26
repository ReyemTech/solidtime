<?php

declare(strict_types=1);

namespace App\Exceptions\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RetainerCapExceededException extends ApiException
{
    public const string KEY = 'retainer_cap_exceeded';

    public function __construct(
        public readonly string $retainerId,
        public readonly int $capSeconds,
        public readonly int $currentTrackedSeconds,
        public readonly int $attemptedDeltaSeconds,
    ) {
        parent::__construct();
    }

    #[\Override]
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'error' => true,
            'key' => $this->getKey(),
            'message' => $this->getTranslatedMessage(),
            'retainer_id' => $this->retainerId,
            'cap_seconds' => $this->capSeconds,
            'tracked_seconds' => $this->currentTrackedSeconds,
            'attempted_delta_seconds' => $this->attemptedDeltaSeconds,
        ], 422);
    }

    #[\Override]
    public function getTranslatedMessage(): string
    {
        return 'Saving this time entry would exceed the retainer cap.';
    }
}
