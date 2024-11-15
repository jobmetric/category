<?php

namespace JobMetric\Taxonomy;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use JobMetric\Taxonomy\Events\TaxonomyDeleteEvent;
use JobMetric\Taxonomy\Events\TaxonomyStoreEvent;
use JobMetric\Taxonomy\Events\TaxonomyUpdateEvent;
use JobMetric\Taxonomy\Exceptions\CannotMakeParentSubsetOwnChild;
use JobMetric\Taxonomy\Exceptions\TaxonomyNotFoundException;
use JobMetric\Taxonomy\Exceptions\TaxonomyUsedException;
use JobMetric\Taxonomy\Http\Requests\SetTranslationRequest;
use JobMetric\Taxonomy\Http\Requests\StoreTaxonomyRequest;
use JobMetric\Taxonomy\Http\Requests\UpdateTaxonomyRequest;
use JobMetric\Taxonomy\Http\Resources\TaxonomyRelationResource;
use JobMetric\Taxonomy\Http\Resources\TaxonomyResource;
use JobMetric\Taxonomy\Models\Taxonomy as TaxonomyModel;
use JobMetric\Taxonomy\Models\TaxonomyPath;
use JobMetric\Taxonomy\Models\TaxonomyRelation;
use JobMetric\Translation\Models\Translation;
use Spatie\QueryBuilder\QueryBuilder;
use Throwable;

class Taxonomy
{
    /**
     * The application instance.
     *
     * @var Application
     */
    protected Application $app;

    /**
     * Create a new Setting instance.
     *
     * @param Application $app
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * Get the specified taxonomy.
     *
     * @param string $type
     * @param array $filter
     * @param array $with
     *
     * @return QueryBuilder
     * @throws Throwable
     */
    public function query(string $type, array $filter = [], array $with = []): QueryBuilder
    {
        checkTypeInTaxonomyTypes($type);

        $fields = [
            'id',
            'name',
            'ordering',
            'status',
            'created_at',
            'updated_at'
        ];

        $metadata_table = config('metadata.tables.meta');

        if (getTaxonomyTypeArg($type, 'hierarchical')) {
            $fields[] = 'parent_id';

            $taxonomy_table = config('taxonomy.tables.taxonomy');
            $taxonomy_path_table = config('taxonomy.tables.taxonomy_path');
            $translation_table = config('translation.tables.translation');

            // Get the path of the taxonomy
            $query = TaxonomyPath::query()
                ->from($taxonomy_path_table . ' as cp')
                ->select(['c.*']);

            // Get the name of the taxonomy
            $taxonomy_name = Translation::query()
                ->select('value')
                ->whereColumn('translatable_id', 'c.id')
                ->where([
                    'translatable_type' => TaxonomyModel::class,
                    'locale' => app()->getLocale(),
                    'key' => 'name'
                ])
                ->getQuery();
            $query->selectSub($taxonomy_name, 'name');

            // Get the full name with parent taxonomy
            $char = config('taxonomy.arrow_icon.' . trans('domi::base.direction'));
            $query->selectSub("CASE WHEN COUNT(t.value) = MAX(cp.level) + 1 THEN GROUP_CONCAT(t.value ORDER BY cp.level SEPARATOR '" . $char . "') ELSE NULL END","name_multiple");

            // Join the taxonomy table for select all fields
            $query->join($taxonomy_table . ' as c', 'cp.taxonomy_id', '=', 'c.id');

            // Join the translation table for select the name of the taxonomy
            $query->leftJoin($translation_table . ' as t', function ($join) use ($taxonomy_table) {
                $join->on('t.translatable_id', '=', 'cp.path_id')
                    ->where('t.translatable_type', '=', TaxonomyModel::class)
                    ->where('t.locale', '=', app()->getLocale())
                    ->where('t.key', '=', 'name');
            });

            // filter metadata
            if (request()->has('metadata')) {
                $metadata = request()->get('metadata', []);
                if (!empty($metadata)) {
                    $flagMeta = false;
                    foreach ($metadata as $meta) {
                        if (!is_null($meta) && $meta != '') {
                            $flagMeta = true;
                            break;
                        }
                    }

                    if ($flagMeta) {
                        $query->join($metadata_table . ' as m', 'm.metaable_id', '=', 'c.id')
                            ->where('m.metaable_type', '=', TaxonomyModel::class);

                        $query->where(function (Builder $q) use ($metadata) {
                            foreach ($metadata as $meta_key => $meta_value) {
                                if (!is_null($meta_value) && $meta_value != '') {
                                    $q->where(function () use ($q, $meta_key, $meta_value) {
                                        $q->where('m.key', $meta_key);
                                        $q->where('m.value', $meta_value);
                                    });
                                }
                            }
                        });
                    }
                }
            }

            // Where the type of the taxonomy is equal to the specified type
            $query->where([
                'cp.type' => $type,
                'c.type' => $type,
            ]);

            // Group by the taxonomy id for get the unique taxonomy
            $query->groupBy('cp.taxonomy_id');

            $queryBuilder = QueryBuilder::for(TaxonomyModel::class)
                ->fromSub($query, config('taxonomy.tables.taxonomy'))
                ->allowedFields($fields)
                ->allowedSorts($fields)
                ->allowedFilters($fields)
                ->defaultSort([
                    'name'
                ])
                ->where($filter);
        } else {
            // Get the path of the taxonomy
            $queryBuilder = QueryBuilder::for(TaxonomyModel::class)
                ->select(['*']);

            // Get the name of the taxonomy
            $taxonomy_name = Translation::query()
                ->select('value')
                ->whereColumn('translatable_id', config('taxonomy.tables.taxonomy') . '.id')
                ->where([
                    'translatable_type' => TaxonomyModel::class,
                    'locale' => app()->getLocale(),
                    'key' => 'name'
                ])
                ->getQuery();
            $queryBuilder->selectSub($taxonomy_name, 'name');

            // filter metadata
            if (request()->has('metadata')) {
                $metadata = request()->get('metadata', []);
                if (!empty($metadata)) {
                    $flagMeta = false;
                    foreach ($metadata as $meta) {
                        if (!is_null($meta) && $meta != '') {
                            $flagMeta = true;
                            break;
                        }
                    }

                    if ($flagMeta) {
                        $queryBuilder->join($metadata_table . ' as m', 'm.metaable_id', '=', 'c.id')
                            ->where('m.metaable_type', '=', TaxonomyModel::class);

                        $queryBuilder->where(function (Builder $q) use ($metadata) {
                            foreach ($metadata as $meta_key => $meta_value) {
                                if (!is_null($meta_value) && $meta_value != '') {
                                    $q->where(function () use ($q, $meta_key, $meta_value) {
                                        $q->where('m.key', $meta_key);
                                        $q->where('m.value', $meta_value);
                                    });
                                }
                            }
                        });
                    }
                }
            }

            // Where the type of the taxonomy is equal to the specified type
            $queryBuilder->where('type', $type);

            $queryBuilder->allowedFields($fields)
                ->allowedSorts($fields)
                ->allowedFilters($fields)
                ->defaultSort([
                    'name'
                ])
                ->where($filter);

        }

        $queryBuilder->with('translations');

        if (!empty($with)) {
            $queryBuilder->with($with);
        }

        return $queryBuilder;
    }

    /**
     * Paginate the specified taxonomies.
     *
     * @param string $type
     * @param array $filter
     * @param int $page_limit
     * @param array $with
     *
     * @return AnonymousResourceCollection
     * @throws Throwable
     */
    public function paginate(string $type, array $filter = [], int $page_limit = 15, array $with = []): AnonymousResourceCollection
    {
        return TaxonomyResource::collection(
            $this->query($type, $filter, $with)->paginate($page_limit)
        );
    }

    /**
     * Get all taxonomies.
     *
     * @param string $type
     * @param array $filter
     * @param array $with
     *
     * @return AnonymousResourceCollection
     * @throws Throwable
     */
    public function all(string $type, array $filter = [], array $with = []): AnonymousResourceCollection
    {
        return TaxonomyResource::collection(
            $this->query($type, $filter, $with)->get()
        );
    }

    /**
     * Store the specified taxonomy.
     *
     * @param array $data
     * @return array
     * @throws Throwable
     */
    public function store(array $data): array
    {
        $validator = Validator::make($data, (new StoreTaxonomyRequest)->setType($data['type'] ?? null)->setParentId($data['parent_id'] ?? null)->rules());
        if ($validator->fails()) {
            $errors = $validator->errors()->all();

            return [
                'ok' => false,
                'message' => trans('taxonomy::base.validation.errors'),
                'errors' => $errors,
                'status' => 422
            ];
        } else {
            $data = $validator->validated();
        }

        return DB::transaction(function () use ($data) {
            $hierarchical = getTaxonomyTypeArg($data['type'], 'hierarchical');

            $taxonomy = new TaxonomyModel;
            $taxonomy->type = $data['type'];
            $taxonomy->parent_id = $data['parent_id'] ?? null;
            $taxonomy->ordering = $data['ordering'] ?? 0;
            $taxonomy->status = $data['status'] ?? true;
            $taxonomy->save();

            if (isset($data['slug'])) {
                $taxonomy->dispatchUrl($data['slug'], $data['type']);
            }

            foreach ($data['translation'] as $translation_key => $translation_value) {
                $taxonomy->translate(app()->getLocale(), [
                    $translation_key => $translation_value
                ]);
            }

            foreach ($data['metadata'] ?? [] as $metadata_key => $metadata_value) {
                $taxonomy->storeMetadata($metadata_key, $metadata_value);
            }

            $mediaAllowCollections = $taxonomy->mediaAllowCollections();
            foreach ($data['media'] ?? [] as $media_key => $media_value) {
                if ($mediaAllowCollections[$media_key]['multiple'] ?? false) {
                    foreach ($media_value as $media_item) {
                        $taxonomy->attachMedia($media_item, $media_key);
                    }
                } else {
                    if ($media_value) {
                        $taxonomy->attachMedia($media_value, $media_key);
                    }
                }
            }

            if ($hierarchical) {
                $level = 0;

                $paths = TaxonomyPath::query()->select('path_id')->where([
                    'taxonomy_id' => $taxonomy->parent_id
                ])->orderBy('level')->get()->toArray();

                $paths[] = [
                    'path_id' => $taxonomy->id
                ];

                foreach ($paths as $path) {
                    $taxonomyPath = new TaxonomyPath;
                    $taxonomyPath->type = $taxonomy->type;
                    $taxonomyPath->taxonomy_id = $taxonomy->id;
                    $taxonomyPath->path_id = $path['path_id'];
                    $taxonomyPath->level = $level++;
                    $taxonomyPath->save();

                    unset($taxonomyPath);
                }
            }

            event(new TaxonomyStoreEvent($taxonomy, $data, $hierarchical));

            return [
                'ok' => true,
                'message' => trans('taxonomy::base.messages.created'),
                'data' => TaxonomyResource::make($taxonomy),
                'status' => 201
            ];
        });
    }

    /**
     * Update the specified taxonomy.
     *
     * @param int $taxonomy_id
     * @param array $data
     *
     * @return array
     * @throws Throwable
     */
    public function update(int $taxonomy_id, array $data): array
    {
        /**
         * @var TaxonomyModel $taxonomy
         */
        $taxonomy = TaxonomyModel::find($taxonomy_id);

        if (!$taxonomy) {
            throw new TaxonomyNotFoundException($taxonomy_id);
        }

        $validator = Validator::make($data, (new UpdateTaxonomyRequest)->setType($taxonomy->type)->setTaxonomyId($taxonomy_id)->setData($data)->rules());
        if ($validator->fails()) {
            $errors = $validator->errors()->all();

            return [
                'ok' => false,
                'message' => trans('taxonomy::base.validation.errors'),
                'errors' => $errors,
                'status' => 422
            ];
        } else {
            $data = $validator->validated();
        }

        return DB::transaction(function () use ($taxonomy_id, $data, $taxonomy) {
            $hierarchical = getTaxonomyTypeArg($taxonomy->type, 'hierarchical');

            $change_parent_id = false;
            if (array_key_exists('parent_id', $data) && $taxonomy->parent_id != $data['parent_id'] && $hierarchical) {
                // If the parent_id is changed, the path of the taxonomy must be updated.

                // You cannot make a parent a subset of its own child.
                if (TaxonomyPath::query()->where([
                    'type' => $taxonomy->type,
                    'taxonomy_id' => $data['parent_id'],
                    'path_id' => $taxonomy_id
                ])->exists()) {
                    throw new CannotMakeParentSubsetOwnChild;
                }

                $taxonomy->parent_id = $data['parent_id'];

                $change_parent_id = true;
            }

            if (array_key_exists('ordering', $data)) {
                $taxonomy->ordering = $data['ordering'];
            }

            if (array_key_exists('status', $data)) {
                $taxonomy->status = $data['status'];
            }

            $taxonomy->save();

            if (array_key_exists('translation', $data)) {
                foreach ($data['translation'] ?? [] as $locale => $translation_data) {
                    foreach ($translation_data as $translation_key => $translation_value) {
                        $taxonomy->translate($locale, [
                            $translation_key => $translation_value
                        ]);
                    }
                }
            }

            if (array_key_exists('metadata', $data)) {
                foreach ($data['metadata'] ?? [] as $metadata_key => $metadata_value) {
                    $taxonomy->storeMetadata($metadata_key, $metadata_value);
                }
            }

            // @todo: detach all media relations for update
            $mediaAllowCollections = $taxonomy->mediaAllowCollections();
            foreach ($data['media'] ?? [] as $media_key => $media_value) {
                if ($mediaAllowCollections[$media_key]['multiple'] ?? false) {
                    foreach ($media_value as $media_item) {
                        $taxonomy->attachMedia($media_item, $media_key);
                    }
                } else {
                    if ($media_value) {
                        $taxonomy->attachMedia($media_value, $media_key);
                    }
                }
            }

            if ($change_parent_id) {
                $paths = TaxonomyPath::query()->where([
                    'type' => $taxonomy->type,
                    'path_id' => $taxonomy_id,
                ])->get()->toArray();

                foreach ($paths as $path) {
                    // Delete the path below the current one
                    TaxonomyPath::query()->where([
                        'type' => $taxonomy->type,
                        'taxonomy_id' => $path['taxonomy_id']
                    ])->where('level', '<', $path['level'])->delete();

                    $item_paths = [];

                    // Get the nodes new parents
                    $nodes = TaxonomyPath::query()->where([
                        'type' => $taxonomy->type,
                        'taxonomy_id' => $taxonomy->parent_id
                    ])->orderBy('level')->get()->toArray();

                    foreach ($nodes as $node) {
                        $item_paths[] = $node['path_id'];
                    }

                    // Get what's left of the nodes current path
                    $left_nodes = TaxonomyPath::query()->where([
                        'type' => $taxonomy->type,
                        'taxonomy_id' => $path['taxonomy_id']
                    ])->orderBy('level')->get()->toArray();

                    foreach ($left_nodes as $left_node) {
                        $item_paths[] = $left_node['path_id'];
                    }

                    // Combine the paths with a new level
                    $level = 0;
                    foreach ($item_paths as $item_path) {
                        TaxonomyPath::query()->updateOrInsert([
                            'type' => $taxonomy->type,
                            'taxonomy_id' => $path['taxonomy_id'],
                            'path_id' => $item_path,
                        ], [
                            'level' => $level++
                        ]);
                    }
                }
            }

            event(new TaxonomyUpdateEvent($taxonomy, $data, $change_parent_id));

            return [
                'ok' => true,
                'message' => trans('taxonomy::base.messages.updated'),
                'data' => TaxonomyResource::make($taxonomy),
                'status' => 200
            ];
        });
    }

    /**
     * Delete the specified taxonomy.
     *
     * @param int $taxonomy_id
     *
     * @return array
     * @throws Throwable
     */
    public function delete(int $taxonomy_id): array
    {
        /**
         * @var TaxonomyModel $taxonomy
         */
        $taxonomy = TaxonomyModel::find($taxonomy_id);

        if (!$taxonomy) {
            throw new TaxonomyNotFoundException($taxonomy_id);
        }

        $data = TaxonomyResource::make($taxonomy);

        return DB::transaction(function () use ($taxonomy_id, $taxonomy, $data) {
            if (getTaxonomyTypeArg($taxonomy->type, 'hierarchical')) {
                $taxonomy_ids = TaxonomyPath::query()->where([
                    'type' => $taxonomy->type,
                    'path_id' => $taxonomy_id
                ])->pluck('taxonomy_id')->toArray();

                $flag_name = false;
                foreach ($taxonomy_ids as $item) {
                    if ($this->hasUsed($item)) {
                        $flag_name = $this->getName($item);
                        break;
                    }
                }

                if ($flag_name) {
                    throw new TaxonomyUsedException($flag_name);
                }

                TaxonomyPath::query()->where('type', $taxonomy->type)->whereIn('taxonomy_id', $taxonomy_ids)->delete();

                TaxonomyModel::query()->whereIn('id', $taxonomy_ids)->get()->each(function ($item) {
                    /**
                     * @var TaxonomyModel $item
                     */
                    $item->forgetTranslations();
                    $item->delete();
                });
            } else {
                if ($this->hasUsed($taxonomy_id)) {
                    throw new TaxonomyUsedException($this->getName($taxonomy_id));
                }

                $taxonomy->forgetTranslations();
                $taxonomy->delete();
            }

            event(new TaxonomyDeleteEvent($taxonomy));

            return [
                'ok' => true,
                'message' => trans('taxonomy::base.messages.deleted'),
                'data' => $data,
                'status' => 200
            ];
        });
    }

    /**
     * Get Name the specified taxonomy.
     *
     * @param int $taxonomy_id
     * @param bool $concat
     * @param string|null $locale
     *
     * @return string
     * @throws Throwable
     */
    public function getName(int $taxonomy_id, bool $concat = true, string $locale = null): string
    {
        /**
         * @var TaxonomyModel $taxonomy
         */
        $taxonomy = TaxonomyModel::find($taxonomy_id);

        if (!$taxonomy) {
            throw new TaxonomyNotFoundException($taxonomy_id);
        }

        $locale = $locale ?? app()->getLocale();

        if (getTaxonomyTypeArg($taxonomy->type, 'hierarchical') && $concat) {
            $names = [];
            $paths = TaxonomyPath::query()->select('path_id')->where([
                'taxonomy_id' => $taxonomy_id
            ])->orderBy('level')->get()->toArray();

            foreach ($paths as $path) {
                $names[] = Translation::query()->where([
                    'translatable_id' => $path['path_id'],
                    'translatable_type' => TaxonomyModel::class,
                    'locale' => $locale,
                    'key' => 'name'
                ])->value('value');
            }

            $char = config('taxonomy.arrow_icon.' . trans('domi::base.direction'));

            return implode($char, $names);
        } else {
            return Translation::query()->where([
                'translatable_id' => $taxonomy_id,
                'translatable_type' => TaxonomyModel::class,
                'locale' => $locale,
                'key' => 'name'
            ])->value('value');
        }
    }

    /**
     * Used In taxonomy
     *
     * @param int $taxonomy_id
     *
     * @return array
     * @throws Throwable
     */
    public function usedIn(int $taxonomy_id): array
    {
        /**
         * @var TaxonomyModel $taxonomy
         */
        $taxonomy = TaxonomyModel::find($taxonomy_id);

        if (!$taxonomy) {
            throw new TaxonomyNotFoundException($taxonomy_id);
        }

        $taxonomy_relations = TaxonomyRelation::query()->where([
            'taxonomy_id' => $taxonomy_id
        ])->get();

        return [
            'ok' => true,
            'message' => trans('taxonomy::base.messages.used_in', [
                'count' => $taxonomy_relations->count()
            ]),
            'data' => TaxonomyRelationResource::collection($taxonomy_relations),
            'status' => 200
        ];
    }

    /**
     * Has Used taxonomy
     *
     * @param int $taxonomy_id
     *
     * @return bool
     * @throws Throwable
     */
    public function hasUsed(int $taxonomy_id): bool
    {
        /**
         * @var TaxonomyModel $taxonomy
         */
        $taxonomy = TaxonomyModel::find($taxonomy_id);

        if (!$taxonomy) {
            throw new TaxonomyNotFoundException($taxonomy_id);
        }

        return TaxonomyRelation::query()->where([
            'taxonomy_id' => $taxonomy_id
        ])->exists();
    }

    /**
     * Set Translation in list
     *
     * @param array $data
     *
     * @return array
     * @throws Throwable
     */
    public function setTranslation(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $taxonomy = TaxonomyModel::find($data['translatable_id'] ?? null);

            foreach ($data['translation'] as $translation_key => $translation_value) {
                $taxonomy->translate($data['locale'] ?? null, [
                    $translation_key => $translation_value
                ]);
            }

            return [
                'ok' => true,
                'message' => trans('taxonomy::base.messages.set_translation'),
                'status' => 200
            ];
        });
    }
}