<?php

declare(strict_types=1);

namespace GermanPath\Content;

final class ContentIntegrityValidator
{
    /**
     * Validate relationships after each JSON object has passed its own schema.
     *
     * @param array<string, list<array<string, mixed>>> $collections
     * @return list<string>
     */
    public function validate(array $collections): array
    {
        $errors = [];
        $ids = [];
        foreach ($collections as $collection => $items) {
            foreach ($items as $item) {
                $id = $item['id'] ?? null;
                if (!is_string($id) || $id === '') {
                    continue;
                }
                if (isset($ids[$collection][$id])) {
                    $errors[] = "{$collection}: duplicate id '{$id}'.";
                }
                $ids[$collection][$id] = true;
            }
        }

        foreach ($collections['courses'] ?? [] as $course) {
            foreach ($course['teacher_ids'] ?? [] as $teacherId) {
                $this->requireReference(
                    $errors,
                    $ids['teachers'] ?? [],
                    $teacherId,
                    "course '{$course['id']}' teacher_ids"
                );
            }
            $offerIds = [];
            foreach ($course['offers'] ?? [] as $offer) {
                $offerId = (string) ($offer['id'] ?? '');
                if ($offerId !== '' && isset($offerIds[$offerId])) {
                    $errors[] = "course '{$course['id']}' contains duplicate offer id '{$offerId}'.";
                }
                if ($offerId !== '') {
                    $offerIds[$offerId] = true;
                }
                $this->requireReference(
                    $errors,
                    $ids['teachers'] ?? [],
                    $offer['teacher_id'] ?? null,
                    "course '{$course['id']}' offer '{$offerId}' teacher_id"
                );
            }
        }

        foreach ($collections['playlists'] ?? [] as $playlist) {
            foreach ($playlist['video_ids'] ?? [] as $videoId) {
                $this->requireReference(
                    $errors,
                    $ids['videos'] ?? [],
                    $videoId,
                    "playlist '{$playlist['id']}' video_ids"
                );
            }
        }

        foreach ($collections['videos'] ?? [] as $video) {
            $videoId = (string) ($video['id'] ?? '[unknown]');
            $this->requireReference($errors, $ids['teachers'] ?? [], $video['teacher_id'] ?? null, "video '{$videoId}' teacher_id");
            $this->requireReference($errors, $ids['courses'] ?? [], $video['course_id'] ?? null, "video '{$videoId}' course_id");
            $this->requireReference($errors, $ids['playlists'] ?? [], $video['playlist_id'] ?? null, "video '{$videoId}' playlist_id");
        }

        foreach ($collections['shorts'] ?? [] as $short) {
            $shortId = (string) ($short['id'] ?? '[unknown]');
            $this->requireReference($errors, $ids['teachers'] ?? [], $short['teacher_id'] ?? null, "short '{$shortId}' teacher_id");
        }

        return $errors;
    }

    /** @param array<string, bool> $knownIds @param list<string> $errors */
    private function requireReference(array &$errors, array $knownIds, mixed $reference, string $field): void
    {
        if (!is_string($reference) || !isset($knownIds[$reference])) {
            $display = is_scalar($reference) ? (string) $reference : '[missing]';
            $errors[] = "{$field} references missing id '{$display}'.";
        }
    }
}
