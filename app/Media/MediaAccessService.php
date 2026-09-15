<?php

declare(strict_types=1);

namespace GermanPath\Media;

use GermanPath\Commerce\AccessService;
use GermanPath\Content\ContentLoader;

final class MediaAccessService
{
    public function __construct(
        private readonly ContentLoader $content,
        private readonly AccessService $access,
        private readonly WorkerMediaService $worker
    ) {
    }

    /** @return array<string, mixed> */
    public function media(string $mediaId): array
    {
        foreach (['videos', 'shorts'] as $collection) {
            $media = $this->content->findById($collection, $mediaId);
            if ($media !== null) {
                return $media;
            }
        }
        throw new MediaException('Media not found.', 404);
    }

    /** @return array<string, mixed> */
    public function signForUser(?int $userId, string $mediaId): array
    {
        $media = $this->media($mediaId);
        if (($media['published'] ?? true) !== true) {
            throw new MediaException('Media not found.', 404);
        }
        $isFree = ($media['is_free'] ?? false) === true;
        if (!$isFree && $userId === null) {
            throw new MediaException('Login is required for this lesson.', 401);
        }
        $courseId = $media['course_id'] ?? null;
        if (!$isFree && (!is_string($courseId) || !$this->access->hasAccess($userId ?? 0, $courseId))) {
            throw new MediaException('An active course access grant is required.', 403);
        }

        $signed = $this->worker->sign($media, $userId ?? 0);
        return [
            'id' => $media['id'],
            'title' => $media['title'],
            'video_url' => $signed['video_url'],
            'subtitle_url' => $signed['subtitle_url'],
            'expires_at' => $signed['expires_at'],
        ];
    }
}