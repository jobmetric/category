<?php

namespace JobMetric\Taxonomy\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use JobMetric\Media\Enums\MediaImageResponsiveModeEnum;
use JobMetric\Media\Models\Media;
use JobMetric\Metadata\Http\Resources\MetadataResource;
use JobMetric\Metadata\Models\Meta;
use JobMetric\Taxonomy\Models\Taxonomy;
use JobMetric\Taxonomy\Models\TaxonomyPath;
use JobMetric\Taxonomy\Models\TaxonomyRelation;
use JobMetric\Translation\Models\Translation;

/**
 * @property mixed id
 * @property mixed type
 * @property mixed name
 * @property mixed parent_id
 * @property mixed ordering
 * @property mixed status
 * @property mixed created_at
 * @property mixed updated_at
 *
 * @property Translation[] translations
 * @property TaxonomyRelation[] taxonomyRelations
 * @property Meta[] metas
 * @property TaxonomyPath[] paths
 * @property Taxonomy[] children
 * @property Media[] files
 */
class TaxonomyResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        global $translationLocale;

        $taxonomyTypes = getTaxonomyType();
        $hierarchical = $taxonomyTypes[$this->type]['hierarchical'];

        return [
            'id' => $this->id,
            'type' => $this->type,
            'hierarchical' => $hierarchical,
            'name' => $this->whenHas('name', $this->name),
            'name_multiple' => $this->whenHas('name_multiple', $this->name_multiple),
            'parent_id' => $this->when($hierarchical, $this->parent_id),
            'ordering' => $this->ordering,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            'translations' => translationResourceData($this->translations, $translationLocale),

            'taxonomyRelations' => $this->whenLoaded('taxonomyRelations', function () {
                return TaxonomyRelationResource::collection($this->taxonomyRelations);
            }),

            'metas' => $this->whenLoaded('metas', function () {
                return MetadataResource::collection($this->metas);
            }),

            'paths' => $this->whenLoaded('paths', function () {
                return TaxonomyPathResource::collection($this->paths);
            }),

            'children_count' => $this->whenLoaded('children', function () {
                return count($this->children);
            }),

            'files' => $this->whenLoaded('files', function () {
                $config = [];

                $has_base_media = getTaxonomyTypeArg($this->type, 'has_base_media');
                $media_config = getTaxonomyTypeArg($this->type, 'media');

                if ($has_base_media) {
                    $config['base'] = [
                        'default' => [
                            'w' => config('media.default_image_size.width'),
                            'h' => config('media.default_image_size.height'),
                        ],
                        'thumb' => [
                            'w' => config('media.thumb_image_size.width'),
                            'h' => config('media.thumb_image_size.height'),
                        ],
                    ];
                }

                foreach ($media_config as $collection => $media) {
                    $config[$collection] = $media['size'];
                }

                $files = [];
                foreach ($this->files as $file) {
                    if (getMimeGroup($file->mime_type) == 'image') {
                        foreach ($config[$file->pivot->collection] as $config_key => $config_item) {
                            $files[$file->pivot->collection][$config_key] = route('media.image.responsive', [
                                'uuid' => $file->uuid,
                                'w' => $config_item['w'],
                                'h' => $config_item['h'],
                                'm' => MediaImageResponsiveModeEnum::COVER()
                            ]);
                        }
                    } else {
                        $files[$file->pivot->collection] = route('media.download', [
                            'media' => $file->id,
                        ]);
                    }
                }

                return $files;
            }),
        ];
    }
}
