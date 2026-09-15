<?php

declare(strict_types=1);

namespace GermanPath\Commerce;

use GermanPath\Content\ContentLoader;

final class CourseOfferService
{
    public function __construct(private readonly ContentLoader $content)
    {
    }

    /** @return array<string, mixed> */
    public function course(string $courseId): array
    {
        $course = $this->content->findById('courses', $courseId);
        if ($course === null || $course['published'] !== true) {
            throw new PaymentException('This course is not available.');
        }
        return $course;
    }

    /** @return array{course: array<string, mixed>, offer: array<string, mixed>, option: array<string, mixed>} */
    public function resolve(string $courseId, string $offerId, string $duration): array
    {
        $course = $this->course($courseId);
        if ($course['is_free'] === true) {
            throw new PaymentException('This course does not require payment.');
        }

        foreach ($course['offers'] as $offer) {
            if (($offer['id'] ?? null) !== $offerId) {
                continue;
            }
            foreach ($offer['access_options'] as $option) {
                if (($option['duration'] ?? null) === $duration) {
                    return ['course' => $course, 'offer' => $offer, 'option' => $option];
                }
            }
        }

        throw new PaymentException('The selected course offer or duration is not available.');
    }
}
