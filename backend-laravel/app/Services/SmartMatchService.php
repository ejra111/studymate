<?php

namespace App\Services;

use App\Models\StudyGroup;
use App\Models\User;
use Illuminate\Support\Collection;

class SmartMatchService
{
    public function getMatchesForUser(User $user, int $limit = 6, ?string $search = null): array
    {
        $user->loadMissing(['program', 'courses', 'studyGroups.location', 'studyGroups.members', 'friends', 'notificationsSent', 'notificationsReceived']);

        // IDs to exclude: friends and users with pending/accepted study_invite
        $friendIds = $user->friends->pluck('id')->all();
        $notifSentIds = $user->notificationsSent()
            ->where('type', 'study_invite')
            ->get()
            ->pluck('receiver_id')
            ->all();
        $notifReceivedIds = $user->notificationsReceived()
            ->where('type', 'study_invite')
            ->get()
            ->pluck('sender_id')
            ->all();

        $excludeIds = array_unique(array_merge([$user->id], $friendIds, $notifSentIds, $notifReceivedIds));

        $partnerQuery = User::query()
            ->whereNotIn('id', $excludeIds)
            ->where('role', 'student');

        if ($search) {
            $partnerQuery->where('name', 'like', "%{$search}%");
        }

        $partnerMatches = $partnerQuery->with(['program', 'courses', 'studyGroups.members', 'studyGroups.location'])
            ->get()
            ->map(fn (User $candidate) => $this->scorePartner($user, $candidate))
            ->filter(fn (array $item) => $item['score'] >= 10)
            ->sortByDesc('score')
            ->take($limit)
            ->values();

        $groupQuery = StudyGroup::query()
            ->where('status', 'active');

        if ($search) {
            $groupQuery->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('topic', 'like', "%{$search}%");
            });
        }

        $groupMatches = $groupQuery->with(['owner', 'course', 'location', 'members'])
            ->get()
            ->map(fn (StudyGroup $group) => $this->scoreGroup($user, $group))
            ->filter(fn (array $item) => $item['score'] >= 10)
            ->sortByDesc('score')
            ->take($limit)
            ->values();

        return [
            'partnerMatches' => $partnerMatches,
            'groupMatches' => $groupMatches,
            'smartMatchMeta' => [
                'strategy' => 'Skor dihitung dari kecocokan mata kuliah, minat akademik, availability, kedekatan semester, grafik sosial, dan konteks grup.',
                'weights' => [
                    'mataKuliah' => 30,
                    'availability' => 20,
                    'minat' => 15,
                    'akademik' => 15, // Program, Semester, Univ
                    'grafikSosial' => 10,
                    'bioDanTopik' => 10,
                ],
                'aiMode' => 'AI-lite heuristic recommendation',
            ],
        ];
    }

    public function buildGroupCompatibility(User $user, StudyGroup $group): array
    {
        $group->loadMissing(['owner', 'course', 'location', 'members', 'members.studyGroups']);
        $user->loadMissing(['courses', 'studyGroups.location', 'studyGroups.members']);

        $match = $this->scoreGroup($user, $group);

        return [
            'score' => $match['score'],
            'confidence' => $match['confidence'],
            'reasons' => $match['reasons'],
            'matchTags' => $match['matchTags'],
            'sharedFriendsCount' => $match['sharedFriendsCount'],
            'seatsLeft' => $match['seatsLeft'],
            'narrative' => $match['compatibilityNarrative'],
            'group' => $group,
        ];
    }

    private function scorePartner(User $user, User $candidate): array
    {
        $score = 0;
        $reasons = [];
        $breakdown = [];

        $sharedCourses = $candidate->courses->intersect($user->courses);
        $courseScore = min($sharedCourses->count() * 18, 36);
        $score += $courseScore;
        if ($courseScore > 0) {
            $courseNames = $sharedCourses->pluck('name')->take(3)->values()->all();
            $reasons[] = 'Mata kuliah sama: ' . implode(', ', $courseNames);
            $breakdown[] = ['label' => 'Mata kuliah', 'score' => $courseScore];
        }

        $sharedInterests = array_values(array_intersect($candidate->interests ?? [], $user->interests ?? []));
        $interestScore = min(count($sharedInterests) * 8, 16);
        $score += $interestScore;
        if ($interestScore > 0) {
            $reasons[] = 'Minat akademik serupa: ' . implode(', ', array_slice($sharedInterests, 0, 3));
            $breakdown[] = ['label' => 'Minat', 'score' => $interestScore];
        }

        $sharedAvailability = array_values(array_intersect($candidate->availability ?? [], $user->availability ?? []));
        $availabilityScore = min(count($sharedAvailability) * 7, 21);
        $score += $availabilityScore;
        if ($availabilityScore > 0) {
            $reasons[] = 'Ada slot waktu belajar yang sama.';
            $breakdown[] = ['label' => 'Availability', 'score' => $availabilityScore];
        }

        $sameProgram = ($candidate->program_id && $candidate->program_id === $user->program_id) ? 5 : 0;
        $score += $sameProgram;
        if ($sameProgram > 0) {
            $reasons[] = 'Program studi sama.';
            $breakdown[] = ['label' => 'Program studi', 'score' => $sameProgram];
        }

        $sameFaculty = ($candidate->program?->faculty && $candidate->program?->faculty === $user->program?->faculty) ? 5 : 0;
        $score += $sameFaculty;
        if ($sameFaculty > 0 && $sameProgram === 0) {
            $reasons[] = 'Satu fakultas (' . $candidate->program->faculty . ').';
            $breakdown[] = ['label' => 'Fakultas', 'score' => $sameFaculty];
        }

        $sameUniv = ($candidate->university && $candidate->university === $user->university) ? 5 : 0;
        $score += $sameUniv;
        if ($sameUniv > 0) {
            $reasons[] = 'Satu universitas.';
            $breakdown[] = ['label' => 'Universitas', 'score' => $sameUniv];
        }

        $semesterScore = $this->semesterClosenessScore($user->semester, $candidate->semester);
        $score += $semesterScore;
        if ($semesterScore > 0) {
            $reasons[] = 'Semester relatif berdekatan.';
            $breakdown[] = ['label' => 'Semester', 'score' => $semesterScore];
        }

        $commonGroups = $candidate->studyGroups->intersect($user->studyGroups);
        $socialScore = min($commonGroups->count() * 9, 18);
        $score += $socialScore;
        if ($socialScore > 0) {
            $reasons[] = 'Pernah terhubung di grup belajar yang sama.';
            $breakdown[] = ['label' => 'Grafik sosial', 'score' => $socialScore];
        }

        $bioScore = $this->keywordOverlapScore($user->bio, $candidate->bio, 10);
        $score += $bioScore;
        if ($bioScore > 0) {
            $reasons[] = 'Tujuan belajar di bio terlihat searah.';
            $breakdown[] = ['label' => 'Bio', 'score' => $bioScore];
        }

        $score = min($score, 100);

        return [
            'user' => $candidate,
            'score' => $score,
            'confidence' => $this->confidenceFromScore($score),
            'reasons' => $reasons,
            'breakdown' => $breakdown,
            'sharedCourses' => $sharedCourses->map(fn ($course) => [
                'id' => $course->id,
                'code' => $course->code,
                'name' => $course->name,
            ])->values(),
            'sharedInterests' => $sharedInterests,
            'sharedAvailability' => $sharedAvailability,
            'commonGroupsCount' => $commonGroups->count(),
            'matchNarrative' => $this->buildPartnerNarrative($candidate->name, $reasons, $score),
        ];
    }

    private function scoreGroup(User $user, StudyGroup $group): array
    {
        $score = 0;
        $reasons = [];
        $matchTags = [];
        $seatsLeft = max(((int) $group->capacity) - $group->members->count(), 0);

        if ($user->courses->contains('id', $group->course_id)) {
            $score += 38;
            $reasons[] = 'Grup ini memakai mata kuliah yang sedang kamu ambil.';
            $matchTags[] = 'Course Fit';
        }

        if (in_array($group->schedule, $user->availability ?? [], true)) {
            $score += 22;
            $reasons[] = 'Jadwal grup cocok dengan availability-mu.';
            $matchTags[] = 'Schedule Fit';
        }

        $interestHit = false;
        $topicCorpus = strtolower(trim(($group->topic ?? '') . ' ' . ($group->description ?? '')));
        foreach ($user->interests ?? [] as $interest) {
            if ($interest && str_contains($topicCorpus, strtolower($interest))) {
                $interestHit = true;
                break;
            }
        }
        if ($interestHit) {
            $score += 14;
            $reasons[] = 'Topik grup selaras dengan minat akademikmu.';
            $matchTags[] = 'Interest Fit';
        }

        $sharedFriendsCount = $group->members
            ->pluck('id')
            ->intersect($user->studyGroups->flatMap(fn ($item) => $item->members->pluck('id'))->unique())
            ->count();
        if ($sharedFriendsCount > 0) {
            $socialScore = min($sharedFriendsCount * 4, 12);
            $score += $socialScore;
            $reasons[] = 'Ada koneksi sosial dari grup yang pernah kamu ikuti.';
            $matchTags[] = 'Social Signal';
        }

        $locationNames = $user->studyGroups
            ->pluck('location.name')
            ->filter()
            ->map(fn ($name) => strtolower((string) $name))
            ->unique();
        if ($group->location?->name && $locationNames->contains(strtolower($group->location->name))) {
            $score += 6;
            $reasons[] = 'Lokasi belajar terasa familiar.';
            $matchTags[] = 'Location Fit';
        }

        if ($seatsLeft > 0) {
            $score += 8;
            $reasons[] = 'Masih tersedia kursi untuk bergabung.';
            $matchTags[] = 'Open Seat';
        }

        $score += $this->keywordOverlapScore($user->bio, $group->topic . ' ' . $group->description, 10);
        $score = min($score, 100);

        return [
            'group' => $group,
            'score' => $score,
            'confidence' => $this->confidenceFromScore($score),
            'reasons' => $reasons,
            'matchTags' => array_values(array_unique($matchTags)),
            'sharedFriendsCount' => $sharedFriendsCount,
            'seatsLeft' => $seatsLeft,
            'compatibilityNarrative' => $this->buildGroupNarrative($group->title, $reasons, $score, $seatsLeft),
        ];
    }

    private function semesterClosenessScore(?int $source, ?int $target): int
    {
        if (!$source || !$target) {
            return 0;
        }

        $gap = abs($source - $target);

        return match (true) {
            $gap === 0 => 10,
            $gap === 1 => 8,
            $gap === 2 => 5,
            $gap === 3 => 2,
            default => 0,
        };
    }

    private function keywordOverlapScore(?string $left, ?string $right, int $cap = 10): int
    {
        $leftTokens = $this->tokenize($left);
        $rightTokens = $this->tokenize($right);

        if (empty($leftTokens) || empty($rightTokens)) {
            return 0;
        }

        $hits = array_intersect($leftTokens, $rightTokens);

        return min(count($hits) * 2, $cap);
    }

    private function tokenize(?string $text): array
    {
        $text = strtolower(trim((string) $text));
        if ($text === '') {
            return [];
        }

        $text = preg_replace('/[^a-z0-9\s]/', ' ', $text) ?? '';
        $raw = preg_split('/\s+/', $text) ?: [];
        $stopwords = [
            'dan', 'yang', 'untuk', 'dengan', 'atau', 'saya', 'kami', 'kamu', 'the', 'of',
            'di', 'ke', 'dari', 'ini', 'itu', 'pada', 'agar', 'lebih', 'active', 'aktif',
        ];

        return array_values(array_unique(array_filter($raw, function ($word) use ($stopwords) {
            return strlen($word) >= 3 && !in_array($word, $stopwords, true);
        })));
    }

    private function confidenceFromScore(int $score): string
    {
        return match (true) {
            $score >= 75 => 'Tinggi',
            $score >= 50 => 'Sedang',
            $score >= 30 => 'Cukup',
            default => 'Rendah',
        };
    }

    private function buildPartnerNarrative(string $candidateName, array $reasons, int $score): string
    {
        if (empty($reasons)) {
            return "{$candidateName} memiliki profil yang menarik, namun kami butuh lebih banyak data untuk memberikan rekomendasi yang spesifik.";
        }

        if ($score >= 80) {
            $intro = "Luar biasa! {$candidateName} adalah partner belajar yang sangat potensial.";
        } elseif ($score >= 50) {
            $intro = "{$candidateName} memiliki banyak kesamaan denganmu.";
        } else {
            $intro = "{$candidateName} bisa menjadi teman diskusi yang baru.";
        }

        $focus = implode(', ', array_slice($reasons, 0, 2));
        return "{$intro} Kalian cocok karena {$focus}.";
    }

    private function buildGroupNarrative(string $groupTitle, array $reasons, int $score, int $seatsLeft): string
    {
        if (empty($reasons)) {
            return "{$groupTitle} masih bisa dipertimbangkan, tetapi sinyal kecocokannya belum kuat.";
        }

        $focus = implode('; ', array_slice($reasons, 0, 3));
        return "{$groupTitle} cocok untukmu karena {$focus}. Skor kecocokan {$score}/100 dengan sisa kursi {$seatsLeft}.";
    }
}
