<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Rules\ApplySelectionRules;
use App\Enums\AuditAction;
use App\Enums\RuleType;
use App\Http\Requests\Rules\SaveSelectionRuleRequest;
use App\Models\SelectionRule;
use App\Models\ServerConnection;
use App\Services\Audit\AuditLogger;
use App\Services\Discovery\RuleMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SelectionRuleController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): Response
    {
        $this->authorize('viewAny', SelectionRule::class);

        $connections = ServerConnection::query()->with(['selectionRules', 'databases' => fn ($q) => $q->whereNull('missing_since')->select('id', 'connection_id', 'name')])
            ->orderBy('name')->get();

        $groups = $connections->map(function (ServerConnection $c): array {
            $names = $c->databases->pluck('name')->all();
            $sorted = RuleMatcher::sorted($c->selectionRules);

            // Which rule decides each database (first match wins).
            $decidedBy = [];
            foreach ($names as $name) {
                $rule = RuleMatcher::firstMatch($name, $sorted);
                if ($rule !== null) {
                    $decidedBy[$rule->id][] = $name;
                }
            }

            return [
                'connection' => ['id' => $c->id, 'name' => $c->name, 'policy' => $c->new_database_policy->value],
                'unmatched' => count($names) - array_sum(array_map('count', $decidedBy)),
                'rules' => array_map(function (SelectionRule $r) use ($names, $decidedBy): array {
                    $matches = array_values(array_filter($names, fn (string $n): bool => RuleMatcher::globMatches($r->pattern, $n)));

                    return [
                        'id' => $r->id,
                        'connection_id' => $r->connection_id,
                        'type' => $r->type->value,
                        'pattern' => $r->pattern,
                        'priority' => $r->priority,
                        'is_active' => $r->is_active,
                        'match_count' => count($matches),
                        'decides_count' => count($decidedBy[$r->id] ?? []),
                        'sample' => array_slice($decidedBy[$r->id] ?? [], 0, 8),
                    ];
                }, $sorted),
            ];
        });

        return Inertia::render('rules/index', [
            'groups' => $groups,
            'types' => RuleType::options(),
        ]);
    }

    public function store(SaveSelectionRuleRequest $request): RedirectResponse
    {
        $rule = SelectionRule::query()->create($request->validated());
        $this->audit->log(AuditAction::RuleCreated, $rule, $request->validated());

        return back()->with('success', 'Rule created. Use "Apply to existing" to update databases that are already known.');
    }

    public function update(SaveSelectionRuleRequest $request, SelectionRule $rule): RedirectResponse
    {
        $rule->update($request->validated());
        $this->audit->log(AuditAction::RuleUpdated, $rule, $request->validated());

        return back()->with('success', 'Rule updated.');
    }

    public function destroy(SelectionRule $rule): RedirectResponse
    {
        $this->authorize('delete', $rule);

        $this->audit->log(AuditAction::RuleDeleted, $rule, ['pattern' => $rule->pattern, 'type' => $rule->type->value]);
        $rule->delete();

        return back()->with('success', 'Rule deleted.');
    }

    /**
     * Live preview while typing a pattern.
     */
    public function preview(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SelectionRule::class);

        $data = $request->validate([
            'connection_id' => ['required', 'integer'],
            'pattern' => ['required', 'string', 'regex:'.RuleMatcher::PATTERN_RULE],
        ]);

        $names = ServerConnection::query()->findOrFail($data['connection_id'])
            ->databases()->whereNull('missing_since')->orderBy('name')->pluck('name')
            ->filter(fn (string $n): bool => RuleMatcher::globMatches($data['pattern'], $n))
            ->values();

        return response()->json(['total' => $names->count(), 'matches' => $names->take(50)->all()]);
    }

    public function apply(ServerConnection $connection, ApplySelectionRules $action): RedirectResponse
    {
        $this->authorize('create', SelectionRule::class);

        $changed = $action->handle($connection);
        $this->audit->log(AuditAction::DatabaseStateChanged, $connection, ['mode' => 'apply_rules', 'count' => $changed]);

        return back()->with('success', "Rules applied. {$changed} database(s) changed state (manual choices were kept).");
    }
}
