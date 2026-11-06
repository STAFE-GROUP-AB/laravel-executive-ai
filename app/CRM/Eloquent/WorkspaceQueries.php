<?php

declare(strict_types=1);

namespace App\CRM\Eloquent;

use App\CRM\Exceptions\WorkspaceAccessDeniedException;
use App\CRM\Queries\QueryPage;
use App\CRM\Search\FullTextSearch;
use App\CRM\WorkspaceScope;
use App\Models\CRM\Communication;
use App\Models\CRM\CrmModel;
use Illuminate\Database\Eloquent\Builder;

/**
 * The only way a CRM query is built in this application.
 *
 * `of()` does two things that cannot be separated: it asks
 * `WorkspaceBoundary` whether the scope may read the organization at all, and
 * it applies the `organization_id` predicate to the builder it returns. A
 * caller therefore cannot obtain an unscoped CRM query — not by forgetting a
 * `where`, not by writing one with the wrong column, and not by passing an
 * organization id it read out of a request. The tenant boundary is enforced
 * where the query is made, which is what `docs/architecture/crm.md` §3 means
 * by "at the repository boundary".
 *
 * `search()` is the second rule in one place: a term a person typed is
 * resolved through the existing full-text corpora (ADR-002 D4, FTS5 on the
 * `local` profile, InnoDB FULLTEXT on `server`) and never through `LIKE`. The
 * corpus already refuses rows outside the organization and soft-deleted rows,
 * so the hit set is narrowed twice — once by the index and once by the
 * predicate above — and an empty hit set is an empty page rather than an
 * unfiltered one.
 */
final class WorkspaceQueries
{
    /**
     * How many hit ids a term may contribute. The corpus returns ids ascending,
     * so this is the first `MAX_LIMIT` matches in id order and the keyset page
     * walks that window; a term matching more is narrowed by the person typing
     * a second one (every term must match — `FullTextSearch`).
     */
    public const int SEARCH_HITS = QueryPage::MAX_LIMIT;

    public function __construct(
        private readonly WorkspaceBoundary $boundary,
        private readonly FullTextSearch $fullText,
    ) {}

    /**
     * A query over `$entity`, scoped to the organization `$scope` may read.
     *
     * @return Builder<CrmModel>
     *
     * @throws WorkspaceAccessDeniedException
     */
    public function of(WorkspaceScope $scope, CrmEntity $entity): Builder
    {
        $organizationId = $this->boundary->organizationFor($scope);

        return $entity->newModel()->newQuery()
            ->where($entity->table().'.organization_id', $organizationId);
    }

    /**
     * The organization a scope may read, for the few lookups that need the id
     * rather than a builder — the full-text corpus and the per-workspace
     * address lookup. It is the same check `of()` runs, so a caller cannot get
     * the id without passing it either.
     *
     * @throws WorkspaceAccessDeniedException
     */
    public function organizationFor(WorkspaceScope $scope): string
    {
        return $this->boundary->organizationFor($scope);
    }

    /**
     * The timeline projection (ADR-002 D9). `communications` is not one of the
     * four §3.2 record types the screens edit, but it is read under the same
     * rule, so it is built the same way rather than by a caller writing its own
     * `where` — the point of this class is that there is no second way.
     *
     * @return Builder<Communication>
     *
     * @throws WorkspaceAccessDeniedException
     */
    public function ofCommunications(WorkspaceScope $scope): Builder
    {
        $organizationId = $this->boundary->organizationFor($scope);

        return Communication::query()->where('communications.organization_id', $organizationId);
    }

    /**
     * Narrow `$builder` by a free-text term through the entity's corpus.
     *
     * A blank term changes nothing. A term with no match narrows to **nothing**
     * — `whereIn` on an empty list — because the alternative, leaving the
     * builder untouched, would answer a failed search with the whole table.
     *
     * @param  Builder<CrmModel>  $builder
     *
     * @throws WorkspaceAccessDeniedException
     */
    public function search(Builder $builder, WorkspaceScope $scope, CrmEntity $entity, ?string $term): void
    {
        if ($term === null || trim($term) === '') {
            return;
        }

        $hits = $this->fullText->search(
            $entity->corpus(),
            $this->boundary->organizationFor($scope),
            $term,
            self::SEARCH_HITS,
        );

        $builder->whereIn($entity->table().'.'.$entity->corpusColumn(), $hits);
    }

    /**
     * The keyset page the §3 contract defines: `id` ascending, bounded by
     * `limit`, starting after the previous page's last id.
     *
     * @param  Builder<CrmModel>  $builder
     */
    public function paginate(Builder $builder, CrmEntity $entity, QueryPage $page): void
    {
        $key = $entity->table().'.id';

        if ($page->after !== null) {
            $builder->where($key, '>', $page->after);
        }

        $builder->orderBy($key)->limit($page->limit);
    }
}
