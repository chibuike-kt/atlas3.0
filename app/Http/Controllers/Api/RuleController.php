<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Rules\CreateRuleRequest;
use App\Http\Requests\Rules\ParseRuleRequest;
use App\Models\Rule;
use App\Services\Rules\RuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RuleController extends BaseApiController
{
    public function __construct(private readonly RuleService $ruleService)
    {
    }

    /**
     * GET /api/rules
     */
    public function index(Request $request): JsonResponse
    {
        $query = $request->user()
            ->rules()
            ->with(['connectedAccount', 'latestExecution'])
            ->withTrashed(false)
            ->orderByRaw("FIELD(status, 'active', 'paused', 'draft', 'archived')")
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('trigger_type')) {
            $query->where('trigger_type', $request->trigger_type);
        }

        $rules = $query->paginate($request->input('per_page', 20));

        return $this->paginated(
            $rules->through(fn($r) => $this->formatRule($r)),
            'Rules retrieved.'
        );
    }

    /**
     * GET /api/rules/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $rule = $this->findRule($request, $id);

        if (! $rule) {
            return $this->notFound('Rule not found.');
        }

        return $this->success($this->formatRule($rule, true));
    }

    /**
     * POST /api/rules
     * Create a rule from structured data.
     */
    public function store(CreateRuleRequest $request): JsonResponse
    {
        // Verify the account belongs to this user
        $account = $request->user()
            ->connectedAccounts()
            ->active()
            ->find($request->connected_account_id);

        if (! $account) {
            return $this->error('The selected account was not found or is not active.');
        }

        try {
            $rule = $this->ruleService->create($request->user(), $request->validated());

            return $this->created($this->formatRule($rule, true), "Rule \"{$rule->name}\" created successfully.");
        } catch (\Throwable $e) {
            return $this->serverError('Failed to create rule. Please try again.');
        }
    }

    /**
     * POST /api/rules/parse
     * Parse plain English into a structured rule preview — does NOT save.
     * The frontend shows the preview for user confirmation, then calls POST /api/rules.
     */
    public function parse(ParseRuleRequest $request): JsonResponse
    {
        $user    = $request->user();
        $account = $user->primaryAccount;

        if (! $account) {
            return $this->error('Connect a bank account before creating rules.');
        }

        if (! config('atlas.anthropic.api_key')) {
            return $this->error('AI rule parsing is not configured on this server.');
        }

        try {
            $result = $this->ruleService->createFromText(
                $user,
                $request->rule_text,
                $account->id
            );

            return $this->success([
                'preview'      => $result['parsed'],
                'confidence'   => $result['confidence'],
                'ambiguities'  => $result['ambiguities'],
                'ready_to_save'=> $result['confidence'] >= 0.70 && empty($result['ambiguities']),
            ], 'Rule parsed successfully. Review the preview before saving.');

        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            return $this->serverError('Rule parsing failed. Please try again or build the rule manually.');
        }
    }

    /**
     * PUT /api/rules/{id}
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $rule = $this->findRule($request, $id);

        if (! $rule) {
            return $this->notFound('Rule not found.');
        }

        if (! $rule->status->isExecutable() && $rule->status->value !== 'paused') {
            return $this->error('Archived rules cannot be edited.');
        }

        $validated = $request->validate([
            'name'              => ['sometimes', 'string', 'min:3', 'max:120'],
            'trigger_config'    => ['sometimes', 'array'],
            'total_amount_type' => ['sometimes', 'string'],
            'total_amount'      => ['sometimes', 'nullable', 'integer'],
            'actions'           => ['sometimes', 'array', 'min:1', 'max:15'],
        ]);

        $rule = $this->ruleService->update($rule, $validated);

        return $this->success($this->formatRule($rule, true), 'Rule updated successfully.');
    }

    /**
     * POST /api/rules/{id}/pause
     */
    public function pause(Request $request, string $id): JsonResponse
    {
        $rule = $this->findRule($request, $id);

        if (! $rule) {
            return $this->notFound('Rule not found.');
        }

        if (! $rule->status->isExecutable()) {
            return $this->error('Only active rules can be paused.');
        }

        $rule = $this->ruleService->pause($rule);

        return $this->success($this->formatRule($rule), "\"{$rule->name}\" paused.');");
    }

    /**
     * POST /api/rules/{id}/resume
     */
    public function resume(Request $request, string $id): JsonResponse
    {
        $rule = $this->findRule($request, $id);

        if (! $rule) {
            return $this->notFound('Rule not found.');
        }

        if ($rule->status->value !== 'paused') {
            return $this->error('Only paused rules can be resumed.');
        }

        $rule = $this->ruleService->resume($rule);

        return $this->success($this->formatRule($rule), "\"{$rule->name}\" is now active.");
    }

    /**
     * DELETE /api/rules/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $rule = $this->findRule($request, $id);

        if (! $rule) {
            return $this->notFound('Rule not found.');
        }

        $name = $rule->name;
        $this->ruleService->archive($rule);

        return $this->noContent("\"{$name}\" deleted.");
    }

    /**
     * GET /api/rules/{id}/executions
     * Lists execution history for a specific rule.
     */
    public function executions(Request $request, string $id): JsonResponse
    {
        $rule = $this->findRule($request, $id);

        if (! $rule) {
            return $this->notFound('Rule not found.');
        }

        $executions = $rule->executions()
            ->orderByDesc('created_at')
            ->paginate($request->input('per_page', 10));

        return $this->paginated($executions->through(fn($e) => [
            'id'              => $e->id,
            'status'          => $e->status,
            'trigger_type'    => $e->trigger_type,
            'total_amount'    => $e->total_amount,
            'total_amount_formatted' => $e->total_amount_formatted,
            'total_fee'       => $e->total_fee,
            'steps_total'     => $e->steps_total,
            'steps_completed' => $e->steps_completed,
            'failure_reason'  => $e->failure_reason,
            'started_at'      => $e->started_at,
            'completed_at'    => $e->completed_at,
            'duration_seconds'=> $e->duration_seconds,
        ]), 'Execution history retrieved.');
    }

    // ── Private helpers ───────────────────────────────────────────────────

    private function findRule(Request $request, string $id): ?Rule
    {
        return $request->user()->rules()->find($id);
    }

    private function formatRule(Rule $rule, bool $detailed = false): array
    {
        $base = [
            'id'                  => $rule->id,
            'name'                => $rule->name,
            'rule_text'           => $rule->rule_text,
            'status'              => $rule->status,
            'trigger_type'        => $rule->trigger_type,
            'total_amount_type'   => $rule->total_amount_type,
            'total_amount'        => $rule->total_amount,
            'total_amount_formatted' => $rule->total_amount_formatted,
            'step_count'          => $rule->step_count,
            'is_ai_suggested'     => $rule->is_ai_suggested,
            'execution_count'     => $rule->execution_count,
            'success_count'       => $rule->success_count,
            'success_rate'        => $rule->success_rate,
            'total_amount_moved'  => $rule->total_amount_moved,
            'total_amount_moved_formatted' => $rule->total_amount_moved_formatted,
            'last_triggered_at'   => $rule->last_triggered_at,
            'next_trigger_at'     => $rule->next_trigger_at,
            'account'             => $rule->connectedAccount ? [
                'id'          => $rule->connectedAccount->id,
                'institution' => $rule->connectedAccount->institution,
                'number'      => $rule->connectedAccount->masked_account_number,
            ] : null,
        ];

        if ($detailed) {
            $base['description']    = $rule->description;
            $base['trigger_config'] = $rule->trigger_config;
            $base['actions']        = $rule->actions;
            $base['created_at']     = $rule->created_at;
            $base['latest_execution'] = $rule->latestExecution ? [
                'id'         => $rule->latestExecution->id,
                'status'     => $rule->latestExecution->status,
                'completed_at' => $rule->latestExecution->completed_at,
            ] : null;
        }

        return $base;
    }
}
