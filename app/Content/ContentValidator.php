<?php

declare(strict_types=1);

namespace GermanPath\Content;

final class ContentValidator
{
    private const CURRENT_SCHEMA_VERSION = 1;

    /** @var array<string, array{type: string, required: list<string>}> */
    private const COLLECTIONS = [
        'teachers' => [
            'type' => 'teacher',
            'required' => ['schema_version', 'type', 'id', 'name', 'display_name', 'bio', 'languages'],
        ],
        'courses' => [
            'type' => 'course',
            'required' => [
                'schema_version', 'type', 'id', 'slug', 'title', 'short_description',
                'description', 'teacher_ids', 'level', 'language', 'category',
                'tags', 'requirements', 'learning_outcomes', 'target_audience',
                'duration', 'total_lessons', 'total_videos', 'featured', 'is_free',
                'pricing', 'access_options', 'offers', 'published',
            ],
        ],
        'playlists' => [
            'type' => 'playlist',
            'required' => ['schema_version', 'type', 'id', 'slug', 'title', 'description', 'video_ids', 'published'],
        ],
        'videos' => [
            'type' => 'video',
            'required' => [
                'schema_version', 'type', 'id', 'slug', 'title', 'description',
                'thumbnail', 'duration', 'teacher_id', 'course_id', 'playlist_id',
                'position', 'published', 'is_free', 'storage', 'video', 'subtitle',
                'subtitle_tracks',
            ],
        ],
        'shorts' => [
            'type' => 'short',
            'required' => [
                'schema_version', 'type', 'id', 'slug', 'title', 'description',
                'thumbnail', 'duration', 'teacher_id', 'published', 'is_free',
                'storage', 'video', 'subtitle',
            ],
        ],
        'pages' => [
            'type' => 'page',
            'required' => ['schema_version', 'type', 'id', 'slug', 'title', 'body', 'published'],
        ],
        'site' => [
            'type' => 'site_settings',
            'required' => ['schema_version', 'type', 'id', 'site_name', 'tagline', 'default_language'],
        ],
    ];

    /** @return list<string> */
    public function validate(string $collection, mixed $data, string $source): array
    {
        if (!isset(self::COLLECTIONS[$collection])) {
            return ["Unknown content collection '{$collection}'."];
        }
        if (!is_array($data) || array_is_list($data)) {
            return ["{$source}: top-level JSON value must be an object."];
        }

        $definition = self::COLLECTIONS[$collection];
        $errors = [];
        foreach ($definition['required'] as $field) {
            if (!array_key_exists($field, $data)) {
                $errors[] = "{$source}: missing required field '{$field}'.";
            }
        }

        if (isset($data['schema_version']) && (
            !is_int($data['schema_version'])
            || $data['schema_version'] < 1
            || $data['schema_version'] > self::CURRENT_SCHEMA_VERSION
        )) {
            $errors[] = "{$source}: unsupported schema_version.";
        }
        if (isset($data['type']) && $data['type'] !== $definition['type']) {
            $errors[] = "{$source}: type must be '{$definition['type']}'.";
        }
        if (isset($data['id']) && !$this->isIdentifier($data['id'])) {
            $errors[] = "{$source}: id must contain only lowercase letters, numbers, dots, underscores, or hyphens.";
        }

        foreach (['name', 'display_name', 'bio', 'slug', 'title', 'short_description', 'description', 'level', 'language', 'category', 'target_audience', 'duration', 'thumbnail', 'teacher_id', 'course_id', 'playlist_id', 'storage', 'video', 'subtitle', 'body', 'site_name', 'tagline', 'default_language'] as $field) {
            if (array_key_exists($field, $data) && !is_string($data[$field])) {
                $errors[] = "{$source}: '{$field}' must be a string.";
            }
        }

        foreach (['languages', 'teacher_ids', 'tags', 'requirements', 'learning_outcomes', 'access_options', 'video_ids', 'subtitle_tracks'] as $field) {
            if (array_key_exists($field, $data) && !$this->isListOfStrings($data[$field])) {
                $errors[] = "{$source}: '{$field}' must be an array of strings.";
            }
        }

        foreach (['featured', 'is_free', 'published'] as $field) {
            if (array_key_exists($field, $data) && !is_bool($data[$field])) {
                $errors[] = "{$source}: '{$field}' must be boolean.";
            }
        }
        foreach (['total_lessons', 'total_videos', 'position'] as $field) {
            if (array_key_exists($field, $data) && (!is_int($data[$field]) || $data[$field] < 0)) {
                $errors[] = "{$source}: '{$field}' must be a non-negative integer.";
            }
        }
        if (array_key_exists('pricing', $data) && (!is_array($data['pricing']) || array_is_list($data['pricing']))) {
            $errors[] = "{$source}: 'pricing' must be an object.";
        }
        if (array_key_exists('offers', $data)) {
            if (!is_array($data['offers']) || !array_is_list($data['offers']) || $data['offers'] === []) {
                $errors[] = "{$source}: 'offers' must be a non-empty array of objects.";
            } else {
                foreach ($data['offers'] as $offerIndex => $offer) {
                    if (!is_array($offer) || array_is_list($offer)) {
                        $errors[] = "{$source}: offer {$offerIndex} must be an object.";
                        continue;
                    }
                    foreach (['id', 'teacher_id', 'title'] as $field) {
                        if (!isset($offer[$field]) || !is_string($offer[$field]) || trim($offer[$field]) === '') {
                            $errors[] = "{$source}: offer {$offerIndex} requires a non-empty '{$field}'.";
                        }
                    }
                    if (isset($offer['access_options']) && (!is_array($offer['access_options']) || !array_is_list($offer['access_options']) || $offer['access_options'] === [])) {
                        $errors[] = "{$source}: offer {$offerIndex} access_options must be a non-empty array.";
                    }
                    foreach ($offer['access_options'] ?? [] as $optionIndex => $option) {
                        if (!is_array($option) || array_is_list($option)
                            || !is_string($option['duration'] ?? null)
                            || !is_int($option['amount_cents'] ?? null)
                            || ($option['amount_cents'] ?? -1) < 0
                        ) {
                            $errors[] = "{$source}: offer {$offerIndex} option {$optionIndex} requires duration and non-negative integer amount_cents.";
                        }
                    }
                }
            }
        }

        return $errors;
    }

    /** @return array<string, array{type: string, required: list<string>}> */
    public function schemaDefinitions(): array
    {
        return self::COLLECTIONS;
    }

    private function isIdentifier(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-z0-9][a-z0-9._-]*$/', $value) === 1;
    }

    private function isListOfStrings(mixed $value): bool
    {
        if (!is_array($value) || !array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '') {
                return false;
            }
        }

        return true;
    }
}
