<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\AdvisoryInsight;
use App\Services\Advisory\AdvisoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InsightController extends BaseApiController
{
    public function __construct(private readonly AdvisoryService $advisoryService)
    {
    }

    /**
     * GET /api/insights
     * Returns the user's visible insight feed, sorted by priority.
     */
    public function index(Request $request): JsonResponse
    {
        $query = $request->user()
            ->insights()
            ->visible()
            ->byPriority();

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->boolean('unread_only')) {
            $query->unread();
        }

        if ($request->boolean('urgent_only')) {
            $query->urgent();
        }

        $insights = $query->paginate($request->input('per_page', 15));

        return $this->paginated(
            $insights->through(fn($i) => $this->formatInsight($i)),
            'Insights retrieved.'
        );
    }

    /**
     * GET /api/insights/summary
     * Returns unread count and urgent count — for notification badges.
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();

        $unreadCount = $user->insights()->visible()->unread()->count();
        $urgentCount = $user->insights()->visible()->urgent()->unread()->count();
        $totalCount  = $user->insights()->visible()->count();

        return $this->success([
            'unread_count' => $unreadCount,
            'urgent_count' => $urgentCount,
            'total_count'  => $totalCount,
        ], 'Insight summary retrieved.');
    }

    /**
     * POST /api/insights/{id}/read
     */
    public function markRead(Request $request, string $id): JsonResponse
    {
        $insight = $this->findInsight($request, $id);

        if (! $insight) {
            return $this->notFound('Insight not found.');
        }

        $insight->markRead();

        return $this->success($this->formatInsight($insight->fresh()), 'Insight marked as read.');
    }

    /**
     * POST /api/insights/read-all
     * Mark all visible insights as read.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $count = $request->user()
            ->insights()
            ->visible()
            ->unread()
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        return $this->success(['marked_read' => $count], "{$count} insight(s) marked as read.");
    }

    /**
     * POST /api/insights/{id}/action
     * Records that the user actioned an insight (e.g. tapped the CTA).
     */
    public function action(Request $request, string $id): JsonResponse
    {
        $insight = $this->findInsight($request, $id);

        if (! $insight) {
            return $this->notFound('Insight not found.');
        }

        $insight->markActioned();

        return $this->success([
            'insight'        => $this->formatInsight($insight->fresh()),
            'action_payload' => $insight->action_payload,
        ], 'Insight actioned.');
    }

    /**
     * DELETE /api/insights/{id}
     * Dismiss (soft-delete equivalent) an insight.
     */
    public function dismiss(Request $request, string $id): JsonResponse
    {
        $insight = $this->findInsight($request, $id);

        if (! $insight) {
            return $this->notFound('Insight not found.');
        }

        $insight->dismiss();

        return $this->noContent('Insight dismissed.');
    }

    /**
     * POST /api/insights/refresh
     * Trigger a new advisory analysis run for the user.
     */
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->connectedAccounts()->active()->exists()) {
            return $this->error('Connect a bank account before Atlas can generate insights.');
        }

        $result = $this->advisoryService->runForUser($user);

        return $this->success($result,
            $result['insights_generated'] > 0
                ? "Atlas generated {$result['insights_generated']} new insight(s) for you."
                : 'Your insights are up to date.'
        );
    }

    // ── Private helpers ───────────────────────────────────────────────────

    private function findInsight(Request $request, string $id): ?AdvisoryInsight
    {
        return $request->user()
            ->insights()
            ->find($id);
    }

    private function formatInsight(AdvisoryInsight $insight): array
    {
        return [
            'id'             => $insight->id,
            'type'           => $insight->type,
            'title'          => $insight->title,
            'body'           => $insight->body,
            'priority'       => $insight->priority,
            'is_urgent'      => $insight->is_urgent,
            'is_read'        => $insight->is_read,
            'is_actioned'    => $insight->is_actioned,
            'cta_label'      => $insight->cta_label,
            'cta_action'     => $insight->cta_action,
            'action_payload' => $insight->action_payload,
            'data'           => $insight->data,
            'expires_at'     => $insight->expires_at,
            'read_at'        => $insight->read_at,
            'created_at'     => $insight->created_at,
        ];
    }
}
