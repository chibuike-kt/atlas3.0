<?php

namespace App\Services\Advisory;

use App\Models\User;
use App\Services\Financial\FinancialProfileService;
use Illuminate\Support\Facades\Log;

class AdvisoryService
{
    public function __construct(
        private readonly InsightGeneratorService  $insightGenerator,
        private readonly RuleSuggestionService    $suggestionService,
        private readonly FinancialProfileService  $profileService
    ) {}

    /**
     * Full advisory run for a user:
     * 1. Refresh financial profile if stale
     * 2. Generate insights
     * 3. Generate rule suggestions
     */
    public function runForUser(User $user): array
    {
        $profile = $user->getOrCreateFinancialProfile();

        // Refresh profile if stale
        if ($profile->is_stale && $user->connectedAccounts()->active()->exists()) {
            $profile = $this->profileService->analyse($user);
        }

        $insightsGenerated    = $this->insightGenerator->generateForUser($user);
        $suggestionsGenerated = $this->suggestionService->generateSuggestions($user);

        Log::info('Advisory run complete', [
            'user_id'     => $user->id,
            'insights'    => $insightsGenerated,
            'suggestions' => $suggestionsGenerated,
        ]);

        return [
            'insights_generated'    => $insightsGenerated,
            'suggestions_generated' => $suggestionsGenerated,
        ];
    }
}
